<?php
/**
 * WP-CLI command group for UltraCache.
 */

defined('ABSPATH') || exit;

trait ULTRACACHE_CLI_Cache_Trait
{
    private $ultracache_cli_warm_token = '';
    private $ultracache_cli_warm_generation = 0;
    private $ultracache_cli_warm_shutdown_registered = false;
    private $ultracache_cli_warm_preempted = false;

    private function ensure_cli_warm_session()
    {
        if ('' !== (string) $this->ultracache_cli_warm_token) {
            return (string) $this->ultracache_cli_warm_token;
        }
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'begin_foreground_warmup_session')) {
            return '';
        }

        $result = Ultra_Cache_WP::begin_foreground_warmup_session('cli', 'cli_warm');
        if (empty($result['success']) || empty($result['token'])) {
            WP_CLI::error(!empty($result['message']) ? (string) $result['message'] : 'WP-CLI warm-up ownership could not be acquired.');
        }

        $this->ultracache_cli_warm_token = (string) $result['token'];
        $this->ultracache_cli_warm_generation = max(0, (int) ($result['generation'] ?? 0));
        if (!$this->ultracache_cli_warm_shutdown_registered) {
            $this->ultracache_cli_warm_shutdown_registered = true;
            register_shutdown_function(array($this, 'release_cli_warm_session'));
        }
        return $this->ultracache_cli_warm_token;
    }

    public function release_cli_warm_session()
    {
        $token = (string) $this->ultracache_cli_warm_token;
        if ('' === $token) {
            return;
        }
        $this->ultracache_cli_warm_token = '';
        $this->ultracache_cli_warm_generation = 0;
        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'end_foreground_warmup_session')) {
            Ultra_Cache_WP::end_foreground_warmup_session($token, 'cli', 'completed');
        }
    }

    public function purge($args, $assoc_args)
    {
        $engine = $this->get_engine();
        if (!$engine) {
            WP_CLI::error('Cache engine not available.');
        }

        $target_url = !empty($assoc_args['cache-url']) ? $assoc_args['cache-url'] : '';
        if (!empty($target_url) && !empty($assoc_args['all'])) {
            WP_CLI::error('Use either --all or --cache-url, not both.');
        }

        if (!empty($target_url)) {
            if (!method_exists($engine, 'purge_url')) {
                WP_CLI::error('Single-URL purge is not available.');
            }

            $url = $this->require_local_site_url($target_url, $engine, 'Please provide a valid local site URL for --cache-url.');
            $purged = (bool) $engine->purge_url($url);
            if (!$purged) {
                WP_CLI::warning('No cache files matched that URL.');
                return;
            }

            WP_CLI::success('Purged cache for ' . $url);
            return;
        }

        if (!method_exists($engine, 'purge_all')) {
            WP_CLI::error('Full purge is not available.');
        }

        $purge_started_at = time();
        $purged = (bool) $engine->purge_all();
        if (!$purged) {
            WP_CLI::error('Full cache purge is already running or the purge lock could not be acquired.');
        }

        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_settings')) {
            $settings = Ultra_Cache_WP::get_dashboard_settings();
            $varnish_configured = method_exists('Ultra_Cache_WP', 'is_varnish_runtime_enabled')
                && Ultra_Cache_WP::is_varnish_runtime_enabled($settings);
            if ($varnish_configured && empty($settings['flushAllIncludeVarnish'])) {
                WP_CLI::warning('Varnish integration is enabled, but Flush All Include Varnish is OFF. The local UltraCache cache was purged, but reverse-proxy/Varnish cache was not purged.');
            }
            if ($varnish_configured && !empty($settings['flushAllIncludeVarnish'])) {
                $varnish_result = method_exists('Ultra_Cache_WP', 'get_varnish_last_operation_result')
                    ? Ultra_Cache_WP::get_varnish_last_operation_result()
                    : array();
                $varnish_result_time = max(0, (int) ($varnish_result['time'] ?? 0));
                if ($varnish_result_time < ($purge_started_at - 1)) {
                    WP_CLI::warning('Varnish purge did not publish a current persistent operation result.');
                } elseif (empty($varnish_result['success'])) {
                    WP_CLI::warning(!empty($varnish_result['message']) ? (string) $varnish_result['message'] : 'Varnish purge did not report success.');
                }
            }
        }

        WP_CLI::success('Purged the full cache.');
    }

    /**
     * Return external-cache stages selected for direct WP-CLI warm commands.
     *
     * @return array<string,mixed>
     */
    private function get_cli_warm_pipeline_args()
    {
        $token = $this->ensure_cli_warm_session();
        $include_varnish = class_exists('Ultra_Cache_WP')
            && method_exists('Ultra_Cache_WP', 'should_include_varnish_in_site_warmup')
            && Ultra_Cache_WP::should_include_varnish_in_site_warmup();
        $include_litespeed = class_exists('Ultra_Cache_WP')
            && method_exists('Ultra_Cache_WP', 'should_include_litespeed_in_site_warmup')
            && Ultra_Cache_WP::should_include_litespeed_in_site_warmup();

        $heartbeat = null;
        if ('' !== $token && method_exists('Ultra_Cache_WP', 'renew_foreground_warmup_session')) {
            $generation = max(0, (int) $this->ultracache_cli_warm_generation);
            $heartbeat = function ($stage = '') use ($token, $generation) {
                $renewed = Ultra_Cache_WP::renew_foreground_warmup_session($token, 'cli', $stage, '', $generation);
                if (empty($renewed['success'])) {
                    $this->ultracache_cli_warm_preempted = true;
                    $this->ultracache_cli_warm_token = '';
                    $this->ultracache_cli_warm_generation = 0;
                    return false;
                }
                return true;
            };
        }

        return array(
            'include_varnish' => $include_varnish,
            'include_litespeed' => $include_litespeed,
            'warm_context' => 'cli',
            '_queue_lease_heartbeat' => $heartbeat,
        );
    }

    private function get_warm_buckets_from_assoc_args($assoc_args)
    {
        if (empty($assoc_args['buckets'])) {
            return null;
        }

        $buckets = array_values(array_unique(array_intersect(
            array('orig', 'webp', 'avif'),
            array_map('trim', explode(',', (string) $assoc_args['buckets']))
        )));
        if (empty($buckets)) {
            WP_CLI::error('Invalid bucket list. Use orig,webp,avif.');
        }

        return $buckets;
    }

    private function run_cli_warm_pipeline_with_lock_retry($engine, $url, array $warm_args)
    {
        $max_retries = max(0, min(10, (int) apply_filters('ultracache_cli_warm_lock_retries', 5)));
        $attempt = 0;

        do {
            $result = method_exists($engine, 'warm_page_pipeline')
                ? $engine->warm_page_pipeline($url, $warm_args)
                : $engine->warm_url($url, $warm_args);
            if (empty($result['coalesced']) || $attempt >= $max_retries) {
                return is_array($result) ? $result : array(
                    'success' => false,
                    'message' => 'Warm pipeline returned an invalid result.',
                );
            }

            sleep(min(3, $attempt + 1));
            ++$attempt;
        } while (true);
    }

    private function get_cli_css_asset_inventory()
    {
        $inventory = array(
            'available' => false,
            'error' => '',
            'manifestEntries' => 0,
            'manifestValidEntries' => 0,
            'mainBundles' => array(),
            'leftoverBundles' => array(),
            'fontMixBundles' => array(),
            'delayedFontBundles' => array(),
            'fontCssFiles' => array(),
            'optimizedCssFiles' => array(),
            'googleFontsCssFiles' => array(),
            'googleFontsWoff2Files' => array(),
        );

        if (!function_exists('ultracache_generated_asset_dir')) {
            $inventory['error'] = 'Generated asset storage is not available.';
            return $inventory;
        }

        try {
            $css_bundle_dir = ultracache_generated_asset_dir('css-bundles');
            $manifest_file = '' !== (string) $css_bundle_dir ? trailingslashit($css_bundle_dir) . 'manifest.json' : '';
            if ('' !== $manifest_file && is_readable($manifest_file) && function_exists('ultracache_safe_file_get_contents')) {
                $raw = ultracache_safe_file_get_contents($manifest_file);
                $manifest = is_string($raw) && '' !== $raw ? json_decode($raw, true) : array();
                if (is_array($manifest) && !empty($manifest['entries']) && is_array($manifest['entries'])) {
                    $inventory['manifestEntries'] = count($manifest['entries']);
                    foreach ($manifest['entries'] as $entry) {
                        if (!is_array($entry) || empty($entry['bundleFile'])) {
                            continue;
                        }
                        $bundle_file = wp_normalize_path((string) $entry['bundleFile']);
                        $bundle_size = is_readable($bundle_file) ? @filesize($bundle_file) : false;
                        if (false !== $bundle_size && $bundle_size > 0) {
                            ++$inventory['manifestValidEntries'];
                        }
                    }
                }
            }

            $bucket_extensions = array(
                'css-bundles' => array('css'),
                'font-css' => array('css'),
                'optimized-css' => array('css'),
                'google-fonts' => array('css', 'woff2'),
            );

            foreach ($bucket_extensions as $bucket => $extensions) {
                $dir = ultracache_generated_asset_dir($bucket);
                if (!is_dir($dir)) {
                    continue;
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY,
                    RecursiveIteratorIterator::CATCH_GET_CHILD
                );

                foreach ($iterator as $file_info) {
                    if (!$file_info instanceof SplFileInfo || !$file_info->isFile()) {
                        continue;
                    }
                    $extension = strtolower((string) $file_info->getExtension());
                    if (!in_array($extension, $extensions, true)) {
                        continue;
                    }

                    $file = wp_normalize_path((string) $file_info->getPathname());
                    $name = strtolower((string) $file_info->getFilename());
                    if ('css-bundles' === $bucket) {
                        if (0 === strpos($name, 'bundle-leftover-')) {
                            $inventory['leftoverBundles'][$file] = true;
                        } elseif (0 === strpos($name, 'bundle-font-mix-')) {
                            $inventory['fontMixBundles'][$file] = true;
                        } elseif (false !== strpos($name, '-delayed-fonts.css')) {
                            $inventory['delayedFontBundles'][$file] = true;
                        } elseif (0 === strpos($name, 'bundle-')) {
                            $inventory['mainBundles'][$file] = true;
                        }
                    } elseif ('font-css' === $bucket) {
                        $inventory['fontCssFiles'][$file] = true;
                    } elseif ('optimized-css' === $bucket) {
                        $inventory['optimizedCssFiles'][$file] = true;
                    } elseif ('google-fonts' === $bucket) {
                        if ('css' === $extension) {
                            $inventory['googleFontsCssFiles'][$file] = true;
                        } elseif ('woff2' === $extension) {
                            $inventory['googleFontsWoff2Files'][$file] = true;
                        }
                    }
                }
            }

            $inventory['available'] = true;
        } catch (Throwable $error) {
            $inventory['error'] = sanitize_text_field((string) $error->getMessage());
        }

        return $inventory;
    }

    private function inspect_cli_cached_css_files(array $files)
    {
        $result = array(
            'variantCount' => 0,
            'mainBundleVariants' => 0,
            'leftoverBundleVariants' => 0,
            'fontMixBundleVariants' => 0,
            'fontCssVariants' => 0,
            'optimizedCssVariants' => 0,
            'googleFontsVariants' => 0,
            'stylesheetLinks' => 0,
        );

        if (!function_exists('ultracache_safe_file_get_contents')) {
            return $result;
        }

        foreach (array_values(array_unique(array_filter(array_map('strval', $files)))) as $file) {
            $html = ultracache_safe_file_get_contents($file);
            if (!is_string($html) || '' === $html) {
                continue;
            }

            ++$result['variantCount'];
            $lower = strtolower($html);
            if (
                false !== strpos($lower, 'data-ultracache-page-css-bundle=')
                || false !== strpos($lower, 'data-ultracache-frontpage-css=')
                || false !== strpos($lower, 'id="ultracache-page-css-bundle"')
                || false !== strpos($lower, "id='ultracache-page-css-bundle'")
            ) {
                ++$result['mainBundleVariants'];
            }
            if (false !== strpos($lower, 'data-ultracache-leftover-css-bundle=')) {
                ++$result['leftoverBundleVariants'];
            }
            if (false !== strpos($lower, 'data-ultracache-font-mix-css-bundle=')) {
                ++$result['fontMixBundleVariants'];
            }
            if (false !== strpos($lower, '/ultracache/font-css/')) {
                ++$result['fontCssVariants'];
            }
            if (false !== strpos($lower, '/ultracache/optimized-css/')) {
                ++$result['optimizedCssVariants'];
            }
            if (false !== strpos($lower, '/ultracache/google-fonts/')) {
                ++$result['googleFontsVariants'];
            }

            $link_count = preg_match_all('/<link\b[^>]*\brel\s*=\s*(["\'])[^"\']*stylesheet[^"\']*\1[^>]*>/i', $html, $matches);
            if (false !== $link_count) {
                $result['stylesheetLinks'] += (int) $link_count;
            }
        }

        return $result;
    }

    private function print_cli_css_warm_summary(array $summary, array $inventory_after)
    {
        $css = isset($summary['css']) && is_array($summary['css']) ? $summary['css'] : array();
        $html = isset($summary['cachedHtml']) && is_array($summary['cachedHtml']) ? $summary['cachedHtml'] : array();

        WP_CLI::log('CSS warm-up summary:');
        WP_CLI::log(sprintf(
            '  Per-page CSS: %d built, %d reused, %d skipped, %d failed.',
            (int) ($css['built'] ?? 0),
            (int) ($css['reused'] ?? 0),
            (int) ($css['skipped'] ?? 0),
            (int) ($css['failed'] ?? 0)
        ));
        WP_CLI::log(sprintf(
            '  Built bundle input: %d stylesheet(s), %d byte(s) across this run.',
            (int) ($css['sourceStylesheets'] ?? 0),
            (int) ($css['bundleBytes'] ?? 0)
        ));
        WP_CLI::log(sprintf(
            '  Cached HTML checked: %d URL(s), %d variant file(s). Main bundle on all variants: %d URL(s); partial: %d; missing: %d.',
            (int) ($html['urlsInspected'] ?? 0),
            (int) ($html['variantsInspected'] ?? 0),
            (int) ($html['urlsMainAll'] ?? 0),
            (int) ($html['urlsMainPartial'] ?? 0),
            (int) ($html['urlsMainNone'] ?? 0)
        ));
        WP_CLI::log(sprintf(
            '  Additional CSS in cached HTML: leftover %d URL(s), font mix %d, font-css %d, optimized-css %d, local Google Fonts %d.',
            (int) ($html['urlsLeftover'] ?? 0),
            (int) ($html['urlsFontMix'] ?? 0),
            (int) ($html['urlsFontCss'] ?? 0),
            (int) ($html['urlsOptimizedCss'] ?? 0),
            (int) ($html['urlsGoogleFonts'] ?? 0)
        ));

        $homepage = isset($summary['homepage']) && is_array($summary['homepage']) ? $summary['homepage'] : array();
        if (!empty($homepage['included']) && !empty($homepage['variantCount'])) {
            WP_CLI::log(sprintf(
                '  Homepage verification: %d/%d cached variant(s) contain the main CSS bundle; leftover %d, font mix %d, font-css %d, optimized-css %d, local Google Fonts %d.',
                (int) ($homepage['mainBundleVariants'] ?? 0),
                (int) ($homepage['variantCount'] ?? 0),
                (int) ($homepage['leftoverBundleVariants'] ?? 0),
                (int) ($homepage['fontMixBundleVariants'] ?? 0),
                (int) ($homepage['fontCssVariants'] ?? 0),
                (int) ($homepage['optimizedCssVariants'] ?? 0),
                (int) ($homepage['googleFontsVariants'] ?? 0)
            ));
        } elseif (!empty($homepage['included'])) {
            WP_CLI::log('  Homepage verification: homepage was processed, but no readable cached variant file was available for CSS inspection.');
        } else {
            WP_CLI::log('  Homepage verification: homepage was not included in the selected crawl set.');
        }

        WP_CLI::log('CSS asset inventory after warm-up:');
        if (empty($inventory_after['available'])) {
            $message = !empty($inventory_after['error']) ? ' ' . (string) $inventory_after['error'] : '';
            WP_CLI::warning('Final CSS asset inventory is unavailable.' . $message);
            return;
        }

        WP_CLI::log(sprintf(
            '  Manifest entries: %d total, %d valid.',
            (int) ($inventory_after['manifestEntries'] ?? 0),
            (int) ($inventory_after['manifestValidEntries'] ?? 0)
        ));
        WP_CLI::log(sprintf(
            '  css-bundles: %d main, %d leftover, %d font-mix, %d delayed-font companion.',
            count((array) ($inventory_after['mainBundles'] ?? array())),
            count((array) ($inventory_after['leftoverBundles'] ?? array())),
            count((array) ($inventory_after['fontMixBundles'] ?? array())),
            count((array) ($inventory_after['delayedFontBundles'] ?? array()))
        ));
        WP_CLI::log(sprintf(
            '  Font assets: %d font-css CSS, %d optimized-css CSS, %d Google Fonts CSS, %d Google Fonts WOFF2.',
            count((array) ($inventory_after['fontCssFiles'] ?? array())),
            count((array) ($inventory_after['optimizedCssFiles'] ?? array())),
            count((array) ($inventory_after['googleFontsCssFiles'] ?? array())),
            count((array) ($inventory_after['googleFontsWoff2Files'] ?? array()))
        ));
    }

    private function warm_url_list($engine, array $urls, $buckets = null, $purge_first = false, $build_css_bundle = false)
    {
        $urls = array_values(array_filter($urls));
        if (empty($urls)) {
            WP_CLI::warning('No URLs to warm.');
            return array();
        }

        WP_CLI::log(sprintf('Preparing warm-up for %d URL(s).', count($urls)));

        $summary = array(
            'requested' => count($urls),
            'processed' => 0,
            'completed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'preempted' => false,
            'css' => array(
                'built' => 0,
                'reused' => 0,
                'skipped' => 0,
                'failed' => 0,
                'sourceStylesheets' => 0,
                'bundleBytes' => 0,
            ),
            'cachedHtml' => array(
                'urlsInspected' => 0,
                'variantsInspected' => 0,
                'urlsMainAll' => 0,
                'urlsMainPartial' => 0,
                'urlsMainNone' => 0,
                'urlsLeftover' => 0,
                'urlsFontMix' => 0,
                'urlsFontCss' => 0,
                'urlsOptimizedCss' => 0,
                'urlsGoogleFonts' => 0,
            ),
            'homepage' => array('included' => false),
        );

        $progress = \WP_CLI\Utils\make_progress_bar('Warming cache', count($urls));
        $homepage_url = untrailingslashit((string) home_url('/'));

        foreach ($urls as $url) {
            if ($purge_first && method_exists($engine, 'purge_url')) {
                $engine->purge_url($url);
            }

            $warm_args = array_merge(
                array('build_css_bundle' => (bool) $build_css_bundle),
                $this->get_cli_warm_pipeline_args()
            );
            if (is_array($buckets)) {
                $warm_args['buckets'] = $buckets;
            }
            $result = $this->run_cli_warm_pipeline_with_lock_retry($engine, $url, $warm_args);
            ++$summary['processed'];

            if (!empty($result['success'])) {
                ++$summary['completed'];
            } elseif (!empty($result['skipped'])) {
                ++$summary['skipped'];
                WP_CLI::warning($url . ' -> ' . (!empty($result['message']) ? $result['message'] : 'Warm skipped.'));
            } else {
                ++$summary['failed'];
                WP_CLI::warning($url . ' -> ' . (!empty($result['message']) ? $result['message'] : 'Warm failed.'));
            }

            if ($build_css_bundle) {
                $css_result = isset($result['cssBundle']) && is_array($result['cssBundle']) ? $result['cssBundle'] : array();
                $css_outcome = sanitize_key((string) ($css_result['outcome'] ?? ''));
                if ('built' === $css_outcome || (!empty($css_result['success']) && empty($css_result['skipped']))) {
                    ++$summary['css']['built'];
                    $summary['css']['sourceStylesheets'] += max(0, (int) ($css_result['stats']['bundled'] ?? 0));
                    $summary['css']['bundleBytes'] += max(0, (int) ($css_result['bundleBytes'] ?? 0));
                } elseif ('reused' === $css_outcome) {
                    ++$summary['css']['reused'];
                } elseif (!empty($css_result['skipped'])) {
                    ++$summary['css']['skipped'];
                } elseif (empty($css_result) && empty($result['success'])) {
                    ++$summary['css']['skipped'];
                } else {
                    ++$summary['css']['failed'];
                }

                $cache_scan = $this->inspect_cli_cached_css_files((array) ($result['files'] ?? array()));
                if ($cache_scan['variantCount'] > 0) {
                    ++$summary['cachedHtml']['urlsInspected'];
                    $summary['cachedHtml']['variantsInspected'] += (int) $cache_scan['variantCount'];
                    if ($cache_scan['mainBundleVariants'] === $cache_scan['variantCount']) {
                        ++$summary['cachedHtml']['urlsMainAll'];
                    } elseif ($cache_scan['mainBundleVariants'] > 0) {
                        ++$summary['cachedHtml']['urlsMainPartial'];
                    } else {
                        ++$summary['cachedHtml']['urlsMainNone'];
                    }
                    if ($cache_scan['leftoverBundleVariants'] > 0) {
                        ++$summary['cachedHtml']['urlsLeftover'];
                    }
                    if ($cache_scan['fontMixBundleVariants'] > 0) {
                        ++$summary['cachedHtml']['urlsFontMix'];
                    }
                    if ($cache_scan['fontCssVariants'] > 0) {
                        ++$summary['cachedHtml']['urlsFontCss'];
                    }
                    if ($cache_scan['optimizedCssVariants'] > 0) {
                        ++$summary['cachedHtml']['urlsOptimizedCss'];
                    }
                    if ($cache_scan['googleFontsVariants'] > 0) {
                        ++$summary['cachedHtml']['urlsGoogleFonts'];
                    }
                }

                if ($homepage_url === untrailingslashit((string) $url)) {
                    $summary['homepage'] = array_merge(
                        array(
                            'included' => true,
                            'completed' => !empty($result['success']),
                            'message' => (string) ($result['message'] ?? ''),
                        ),
                        $cache_scan
                    );
                }
            }

            $progress->tick();
            if (!empty($result['ownershipLost']) || $this->ultracache_cli_warm_preempted) {
                $summary['preempted'] = true;
                WP_CLI::warning('WP-CLI warm-up yielded to a newer dashboard or WP-CLI foreground owner.');
                break;
            }
        }

        $progress->finish();
        WP_CLI::success(sprintf(
            'Warm finished. Completed: %d, skipped: %d, failed: %d, processed: %d of %d.',
            (int) $summary['completed'],
            (int) $summary['skipped'],
            (int) $summary['failed'],
            (int) $summary['processed'],
            (int) $summary['requested']
        ));

        if ($build_css_bundle) {
            $inventory_after = $this->get_cli_css_asset_inventory();
            $this->print_cli_css_warm_summary($summary, $inventory_after);
        }

        return $summary;
    }

    /**
     * Warm cache files.
     *
     * ## OPTIONS
     *
     * [--cache-url=<url>]
     * : Warm a single local URL.
     *
     * Note: `--url` is reserved by WP-CLI as a global parameter. Use `--cache-url` here.
     *
     * [--limit=<number>]
     * : Limit how many crawl URLs will be warmed.
     *
     * [--buckets=<list>]
     * : Comma-separated buckets: orig,webp,avif.
     *
     * [--purge-first]
     * : Purge each URL before warming it.
     */

    public function warm($args, $assoc_args)
    {
        $engine = $this->get_engine();
        if (!$engine || !method_exists($engine, 'warm_url')) {
            WP_CLI::error('Cache warming is not available.');
        }

        $buckets = $this->get_warm_buckets_from_assoc_args($assoc_args);

        $target_url = !empty($assoc_args['cache-url']) ? $assoc_args['cache-url'] : '';
        if (!empty($target_url) && !empty($assoc_args['all'])) {
            WP_CLI::error('Use either --all or --cache-url, not both.');
        }

        if (!empty($target_url)) {
            $urls = array($this->require_local_site_url($target_url, $engine, 'Please provide a valid local site URL for --cache-url.'));
        } else {
            if (!method_exists($engine, 'get_crawl_urls')) {
                WP_CLI::error('URL discovery is not available.');
            }
            $urls = (array) $engine->get_crawl_urls();
        }

        $limit = isset($assoc_args['limit']) ? max(0, absint($assoc_args['limit'])) : 0;
        if ($limit > 0) {
            $urls = array_slice($urls, 0, $limit);
        }

        $this->warm_url_list($engine, $urls, $buckets, isset($assoc_args['purge-first']));
    }

    /**
     * Warm up HTML cache for all crawlable public URLs.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Limit how many crawl URLs will be warmed.
     *
     * [--buckets=<list>]
     * : Comma-separated buckets: orig,webp,avif.
     *
     * [--purge-first]
     * : Purge each URL before warming it.
     */

    public function warm_html_all($args, $assoc_args)
    {
        $engine = $this->get_engine();
        if (!$engine || !method_exists($engine, 'get_crawl_urls') || !method_exists($engine, 'warm_url')) {
            WP_CLI::error('Cache warming is not available.');
        }

        $urls = (array) $engine->get_crawl_urls();
        $limit = isset($assoc_args['limit']) ? max(0, absint($assoc_args['limit'])) : 0;
        if ($limit > 0) {
            $urls = array_slice($urls, 0, $limit);
        }

        $this->warm_url_list($engine, $urls, $this->get_warm_buckets_from_assoc_args($assoc_args), isset($assoc_args['purge-first']));
    }

    /**
     * Warm up HTML cache for the front page only.
     *
     * ## OPTIONS
     *
     * [--buckets=<list>]
     * : Comma-separated buckets: orig,webp,avif.
     *
     * [--purge-first]
     * : Purge the front page before warming it.
     */

    public function warm_frontpage_html($args, $assoc_args)
    {
        $engine = $this->get_engine();
        if (!$engine || !method_exists($engine, 'warm_frontpage_html')) {
            WP_CLI::error('Front page HTML warming is not available.');
        }

        $frontpage_url = home_url('/');
        if (isset($assoc_args['purge-first']) && method_exists($engine, 'purge_url')) {
            $engine->purge_url($frontpage_url);
        }

        $warm_args = array();
        $buckets = $this->get_warm_buckets_from_assoc_args($assoc_args);
        if (is_array($buckets)) {
            $warm_args['buckets'] = $buckets;
        }

        if (method_exists($engine, 'warm_page_pipeline')) {
            $result = $this->run_cli_warm_pipeline_with_lock_retry(
                $engine,
                $frontpage_url,
                array_merge(
                    $warm_args,
                    array('skip_css_bundle' => true),
                    $this->get_cli_warm_pipeline_args()
                )
            );
        } else {
            $result = $engine->warm_frontpage_html($warm_args);
        }
        if (!empty($result['success'])) {
            WP_CLI::success(!empty($result['message']) ? $result['message'] : 'Front page HTML cache warmed.');
            return;
        }

        WP_CLI::error(!empty($result['message']) ? $result['message'] : 'Front page HTML warm failed.');
    }

    /**
     * Warm up front page HTML cache and rebuild the front page CSS bundle.
     *
     * ## OPTIONS
     *
     * [--purge-first]
     * : Purge the front page before warming it.
     */

    public function warm_frontpage_html_css($args, $assoc_args)
    {
        $engine = $this->get_engine();
        if (!$engine || !method_exists($engine, 'warm_frontpage_html_with_css')) {
            WP_CLI::error('Front page HTML + CSS warming is not available.');
        }

        $frontpage_url = home_url('/');
        if (isset($assoc_args['purge-first']) && method_exists($engine, 'purge_url')) {
            $engine->purge_url($frontpage_url);
        }

        $settings = (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_settings')) ? Ultra_Cache_WP::get_dashboard_settings() : array();
        if (empty($settings['homepageCssBundleEnabled'])) {
            if (!method_exists($engine, 'warm_frontpage_html')) {
                WP_CLI::error('Front page HTML warming is not available while CSS Bundling is disabled.');
            }

            $fallback_args = array_merge(
                array('force_refresh' => true, 'skip_css_bundle' => true),
                $this->get_cli_warm_pipeline_args()
            );
            $result = method_exists($engine, 'warm_page_pipeline')
                ? $this->run_cli_warm_pipeline_with_lock_retry($engine, $frontpage_url, $fallback_args)
                : $engine->warm_frontpage_html(array('force_refresh' => true));
            if (!empty($result['success']) || !empty($result['skipped'])) {
                $message = !empty($result['message']) ? (string) $result['message'] : 'Front page HTML cache warmed.';
                WP_CLI::success('CSS Bundling is disabled; warmed front page HTML cache only. ' . $message);
                return;
            }

            WP_CLI::error(!empty($result['message']) ? $result['message'] : 'Front page HTML warm failed while CSS Bundling is disabled.');
        }

        $result = method_exists($engine, 'warm_page_pipeline')
            ? $this->run_cli_warm_pipeline_with_lock_retry(
                $engine,
                $frontpage_url,
                array_merge(
                    array('build_css_bundle' => true),
                    $this->get_cli_warm_pipeline_args()
                )
            )
            : $engine->warm_frontpage_html_with_css();
        if (!empty($result['success']) || !empty($result['skipped'])) {
            WP_CLI::success(!empty($result['message']) ? $result['message'] : 'Front page HTML + CSS warm completed.');
            return;
        }

        WP_CLI::error(!empty($result['message']) ? $result['message'] : 'Front page HTML + CSS warm failed.');
    }

    /**
     * Warm up HTML cache for all crawlable public URLs, then rebuild the front page CSS bundle.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Limit how many crawl URLs will be warmed.
     *
     * [--buckets=<list>]
     * : Comma-separated buckets: orig,webp,avif.
     *
     * [--purge-first]
     * : Purge each URL before warming it.
     */

    public function warm_html_all_css($args, $assoc_args)
    {
        $engine = $this->get_engine();
        if (!$engine || !method_exists($engine, 'get_crawl_urls') || !method_exists($engine, 'warm_url')) {
            WP_CLI::error('Site-wide HTML + CSS warming is not available.');
        }

        $urls = (array) $engine->get_crawl_urls();
        $limit = isset($assoc_args['limit']) ? max(0, absint($assoc_args['limit'])) : 0;
        if ($limit > 0) {
            $urls = array_slice($urls, 0, $limit);
        }

        $this->warm_url_list(
            $engine,
            $urls,
            $this->get_warm_buckets_from_assoc_args($assoc_args),
            isset($assoc_args['purge-first']),
            true
        );
    }

    /**
     * Show UltraCache status, diagnostics, storage, settings, or analytics data.
     *
     * ## OPTIONS
     *
     * [--section=<section>]
     * : Section to show. One of summary, settings, diagnostics, storage, stats, analytics, all. Default: summary.
     *
     * [--format=<format>]
     * : Output format. One of table, json, yaml. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp ultracache status
     *     wp ultracache status --section=storage --format=json
     *     wp ultracache status --section=settings
     */

    public function status($args, $assoc_args)
    {
        $format = !empty($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
        $section = !empty($assoc_args['section']) ? strtolower((string) $assoc_args['section']) : 'summary';
        if (!in_array($section, array('summary', 'settings', 'diagnostics', 'storage', 'stats', 'analytics', 'all'), true)) {
            WP_CLI::error('Invalid section. Use summary, settings, diagnostics, storage, stats, analytics, or all.');
        }

        $settings = $this->get_dashboard_settings();
        $stats = $this->get_dashboard_stats();
        $diagnostics = $this->get_dashboard_diagnostics();
        unset($stats['diagnostics']);

        $media_file_counts = array('total' => 0, 'avif' => 0, 'webp' => 0);
        $media = $this->get_media();
        if ($media && method_exists($media, 'get_media_file_counts')) {
            $current_counts = $media->get_media_file_counts();
            if (is_array($current_counts)) {
                $media_file_counts = array_merge($media_file_counts, $current_counts);
            }
        }

        $payload = array();
        switch ($section) {
            case 'settings':
                $payload = $this->redact_dashboard_settings_for_output($settings);
                break;
            case 'diagnostics':
                $payload = $diagnostics;
                break;
            case 'storage':
                $payload = !empty($diagnostics['cacheStorage']) && is_array($diagnostics['cacheStorage']) ? $diagnostics['cacheStorage'] : array();
                break;
            case 'stats':
            case 'analytics':
                $payload = $stats;
                break;
            case 'all':
                $payload = array(
                    'settings' => $this->redact_dashboard_settings_for_output($settings),
                    'diagnostics' => $diagnostics,
                    'stats' => $stats,
                    'mediaFileCounts' => $media_file_counts,
                );
                break;
            case 'summary':
            default:
                $last = !empty($diagnostics['lastEvent']) && is_array($diagnostics['lastEvent']) ? $diagnostics['lastEvent'] : array();
                $payload = array(
                    'pageCacheEnabled' => !empty($settings['pageCacheEnabled']),
                    'pageCacheActive' => !empty($diagnostics['pageCache']['active']),
                    'objectCacheEnabled' => !empty($settings['objectCacheEnabled']),
                    'objectCacheActive' => !empty($diagnostics['objectCache']['active']),
                    'objectCacheAvailable' => !empty($diagnostics['objectCache']['available']),
                    'gzipEnabled' => !empty($settings['gzipEnabled']),
                    'brotliEnabled' => !empty($settings['brotliEnabled']),
                    'mediaOptimizationEnabled' => !empty($settings['mediaOptimizationEnabled']),
                    'cacheSizeHuman' => (string) ($stats['cacheSizeHuman'] ?? ''),
                    'pagesCached' => (int) ($stats['pagesCached'] ?? ($stats['pageCacheFiles'] ?? 0)),
                    'pageCacheHits' => (int) ($stats['pageCacheHits'] ?? 0),
                    'pageCacheMisses' => (int) ($stats['pageCacheMisses'] ?? 0),
                    'pageCacheBypasses' => (int) ($stats['pageCacheBypasses'] ?? 0),
                    'pageCacheHitRatio' => (float) ($stats['pageCacheHitRatio'] ?? 0),
                    'pageCacheStaleHits' => (int) ($stats['pageCacheStaleHits'] ?? 0),
                    'pageCacheBackgroundRevalidations' => (int) ($stats['pageCacheBackgroundRevalidations'] ?? 0),
                    'objectCacheEntries' => (int) ($stats['objectCacheEntries'] ?? 0),
                    'objectCacheHits' => (int) ($stats['objectCacheHits'] ?? 0),
                    'objectCacheMisses' => (int) ($stats['objectCacheMisses'] ?? 0),
                    'objectCacheHitRatio' => (float) ($stats['objectCacheHitRatio'] ?? 0),
                    'optimizedImages' => (int) ($media_file_counts['total'] ?? 0),
                    'avifImages' => (int) ($media_file_counts['avif'] ?? 0),
                    'webpImages' => (int) ($media_file_counts['webp'] ?? 0),
                    'lastEventStatus' => (string) ($last['status'] ?? ''),
                    'lastEventReason' => (string) ($last['reason'] ?? ''),
                    'lastEventBucket' => (string) ($last['bucket'] ?? ''),
                    'lastEventTime' => (string) ($last['time_mysql'] ?? ($last['time'] ?? '')),
                    'lastPurgeTime' => (string) (($stats['lastPurge']['time_mysql'] ?? ($stats['lastPurge']['time'] ?? ''))),
                    'lastWarmTime' => (string) (($stats['lastWarm']['time_mysql'] ?? ($stats['lastWarm']['time'] ?? ''))),
                );
                break;
        }

        $this->output_assoc($payload, $format);
    }

    /**
     * Inspect cacheability for a URL.
     *
     * ## OPTIONS
     *
     * <url>
     * : URL to inspect.
     *
     * [--format=<format>]
     * : Output format: table or json. Default: table.
     */

    public function inspect($args, $assoc_args)
    {
        if (empty($args[0])) {
            WP_CLI::error('Please provide a URL to inspect.');
        }

        $engine = $this->get_engine();
        if (!$engine || !method_exists($engine, 'inspect_url')) {
            WP_CLI::error('URL inspection is not available.');
        }

        $format = !empty($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
        $result = $engine->inspect_url((string) $args[0]);
        if (!is_array($result)) {
            WP_CLI::error('Unexpected inspect response.');
        }

        $this->output_assoc($result, $format);
    }

    /**
     * Run the scheduled UltraCache cleanup routine once.
     *
     * ## EXAMPLES
     *
     *     wp ultracache cleanup
     */

    public function cleanup($args, $assoc_args)
    {
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'run_scheduled_cache_cleanup')) {
            WP_CLI::error('Scheduled cleanup is not available.');
        }

        $result = Ultra_Cache_WP::run_scheduled_cache_cleanup();
        if (empty($result['success'])) {
            WP_CLI::error(!empty($result['message']) ? $result['message'] : 'Cleanup failed.');
        }

        WP_CLI::success(sprintf(
            'Scheduled cleanup finished. CSS bundles deleted: %d. Recognized CSS bundle files before/after: %d/%d. Old orphan-like eligible before: %d. Recent orphan-like protected by grace before: %d. Protected by cached HTML before: %d. Cached HTML CSS refs before: %d. Cleanup limit: %d/run. Grace: %d seconds. Runtime artifacts deleted: %d. Warmed %d URL(s).',
            (int) ($result['cssBundleFilesDeleted'] ?? 0),
            (int) ($result['cssBundleFilesBefore'] ?? 0),
            (int) ($result['cssBundleFilesAfter'] ?? 0),
            (int) ($result['cssBundleOldOrphanLikeBefore'] ?? 0),
            (int) ($result['cssBundleRecentOrphanLikeBefore'] ?? 0),
            (int) ($result['cssBundleProtectedByCachedHtmlBefore'] ?? 0),
            (int) ($result['cssBundleCachedHtmlRefsBefore'] ?? 0),
            (int) ($result['cssBundleCleanupLimit'] ?? 0),
            (int) ($result['cssBundleGraceSeconds'] ?? 0),
            (int) ($result['runtimeArtifactsDeleted'] ?? 0),
            (int) ($result['warmed'] ?? 0)
        ));
    }

    /**
     * Clean safe old runtime lock/test artifacts.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview matching artifacts without deleting them.
     *
     * [--max-age-minutes=<number>]
     * : Minimum age for regular runtime lock markers. Default: 10. Test dummy markers are eligible immediately if not actively locked.
     *
     * [--format=<format>]
     * : Output format: table, json, or yaml. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp ultracache cleanup_artifacts --dry-run
     *     wp ultracache cleanup_artifacts --max-age-minutes=10
     */

    public function cleanup_artifacts($args, $assoc_args)
    {
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'cleanup_runtime_artifacts')) {
            WP_CLI::error('Runtime artifact cleanup is not available.');
        }

        $format = !empty($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
        $max_age_minutes = isset($assoc_args['max-age-minutes']) ? max(1, absint($assoc_args['max-age-minutes'])) : 10;
        $result = Ultra_Cache_WP::cleanup_runtime_artifacts(array(
            'dry_run' => !empty($assoc_args['dry-run']),
            'max_age_seconds' => $max_age_minutes * MINUTE_IN_SECONDS,
        ));

        if (!empty($result['items']) && 'table' === $format) {
            $items = array();
            foreach ((array) $result['items'] as $item) {
                $items[] = array(
                    'file' => (string) ($item['file'] ?? ''),
                    'action' => (string) ($item['action'] ?? ''),
                    'reason' => (string) ($item['reason'] ?? ''),
                    'ageSeconds' => (int) ($item['ageSeconds'] ?? 0),
                );
            }
            if (!empty($items)) {
                WP_CLI::line('Runtime artifact cleanup candidates:');
                \WP_CLI\Utils\format_items('table', $items, array('file', 'action', 'reason', 'ageSeconds'));
            }
        }

        $summary = array(
            'success' => !empty($result['success']) ? 'yes' : 'no',
            'dryRun' => !empty($result['dryRun']) ? 'yes' : 'no',
            'maxAgeSeconds' => (int) ($result['maxAgeSeconds'] ?? 0),
            'scanned' => (int) ($result['scanned'] ?? 0),
            'matched' => (int) ($result['matched'] ?? 0),
            'deleted' => (int) ($result['deleted'] ?? 0),
            'wouldDelete' => (int) ($result['wouldDelete'] ?? 0),
            'skippedActive' => (int) ($result['skippedActive'] ?? 0),
            'skippedYoung' => (int) ($result['skippedYoung'] ?? 0),
            'skippedUnknown' => (int) ($result['skippedUnknown'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'message' => (string) ($result['message'] ?? ''),
        );

        if ('table' === $format) {
            $this->output_assoc($summary, 'table');
            if (empty($result['success'])) {
                WP_CLI::error('Runtime artifact cleanup completed with failures.');
            }
            return;
        }

        $this->output_assoc($result, $format);
        if (empty($result['success'])) {
            WP_CLI::halt(1);
        }
    }

}
