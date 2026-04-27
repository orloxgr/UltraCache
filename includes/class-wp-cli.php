<?php
/**
 * WP-CLI integration for UltraCache.
 */

defined('ABSPATH') || exit;

if (!class_exists('UCWP_CLI_Command') && defined('WP_CLI') && WP_CLI && class_exists('WP_CLI_Command')) {
    class UCWP_CLI_Command extends WP_CLI_Command
    {
        private function get_engine()
        {
            foreach (array('Ultra_Cache_Engine') as $class) {
                if (class_exists($class) && method_exists($class, 'get_instance')) {
                    return call_user_func(array($class, 'get_instance'));
                }
            }

            return null;
        }

        private function get_media()
        {
            foreach (array('Ultra_Cache_Media_Converter') as $class) {
                if (class_exists($class) && method_exists($class, 'get_instance')) {
                    return $class::get_instance();
                }
            }

            return null;
        }

        private function get_dashboard_settings()
        {
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_settings')) {
                return Ultra_Cache_WP::get_dashboard_settings();
            }

            $settings = get_option(defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY : 'ucwp_settings', array());
            return is_array($settings) ? $settings : array();
        }

        private function get_secret_setting_keys()
        {
            return array(
                'redisPassword',
                'varnishCliKey',
            );
        }

        private function get_secret_configuration_flag_map()
        {
            return array(
                'redisPassword' => 'redisPasswordConfigured',
                'varnishCliKey' => 'varnishCliKeyConfigured',
            );
        }

        private function get_deprecated_setting_keys()
        {
            return array(
                'cronWarmStartAfterFlush',
                'warmAfterScheduledCleanup',
                'avifConversionEnabled',
            );
        }

        private function remove_deprecated_setting_keys(array $settings)
        {
            foreach ($this->get_deprecated_setting_keys() as $key) {
                unset($settings[$key]);
            }

            return $settings;
        }

        private function redact_dashboard_settings_for_output(array $settings)
        {
            $settings = $this->remove_deprecated_setting_keys($settings);
            $flag_map = $this->get_secret_configuration_flag_map();

            foreach ($this->get_secret_setting_keys() as $key) {
                $flag = isset($flag_map[$key]) ? $flag_map[$key] : '';
                if ('' !== $flag) {
                    $settings[$flag] = ('' !== trim((string) ($settings[$key] ?? '')));
                }

                if (array_key_exists($key, $settings)) {
                    $settings[$key] = '[redacted]';
                }
            }

            return $settings;
        }

        private function redact_single_setting_for_output($key, array $settings)
        {
            $key = (string) $key;
            if (in_array($key, $this->get_deprecated_setting_keys(), true)) {
                return array();
            }

            $value = array_key_exists($key, $settings) ? $settings[$key] : null;

            if (in_array($key, $this->get_secret_setting_keys(), true)) {
                $flag_map = $this->get_secret_configuration_flag_map();
                $payload = array(
                    $key => '[redacted]',
                );

                if (!empty($flag_map[$key])) {
                    $payload[$flag_map[$key]] = ('' !== trim((string) $value));
                }

                return $payload;
            }

            return array($key => $value);
        }

        private function get_dashboard_stats()
        {
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_engine_stats')) {
                $stats = Ultra_Cache_WP::get_engine_stats();
                return is_array($stats) ? $stats : array();
            }

            return array();
        }

        private function get_dashboard_diagnostics()
        {
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics')) {
                $diagnostics = Ultra_Cache_WP::get_dashboard_diagnostics();
                return is_array($diagnostics) ? $diagnostics : array();
            }

            return array();
        }

        private function is_local_site_url($url, $engine = null)
        {
            $url = esc_url_raw((string) $url);
            if ('' === $url) {
                return false;
            }

            if ($engine && method_exists($engine, 'is_cacheable_local_url')) {
                return (bool) $engine->is_cacheable_local_url($url);
            }

            $parts = wp_parse_url($url);
            $home_parts = wp_parse_url(home_url('/'));
            if (empty($parts['scheme']) || empty($parts['host']) || empty($home_parts['host'])) {
                return false;
            }

            if (!in_array(strtolower((string) $parts['scheme']), array('http', 'https'), true)) {
                return false;
            }

            return strtolower((string) $parts['host']) === strtolower((string) $home_parts['host']);
        }

        private function require_local_site_url($url, $engine = null, $error_message = 'Please provide a valid local site URL.')
        {
            $url = esc_url_raw((string) $url);
            if (!$this->is_local_site_url($url, $engine)) {
                WP_CLI::error($error_message);
            }

            return $url;
        }

        private function is_assoc_array($value)
        {
            if (!is_array($value)) {
                return false;
            }

            return array_keys($value) !== range(0, count($value) - 1);
        }

        private function scalarize_value($value)
        {
            if (is_bool($value)) {
                return $value ? 'yes' : 'no';
            }

            if (null === $value) {
                return '';
            }

            if (is_scalar($value)) {
                return (string) $value;
            }

            return wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        private function flatten_assoc(array $data, $prefix = '')
        {
            $flat = array();

            foreach ($data as $key => $value) {
                $composed = '' !== $prefix ? $prefix . '.' . $key : (string) $key;

                if (is_array($value) && $this->is_assoc_array($value)) {
                    $flat = array_merge($flat, $this->flatten_assoc($value, $composed));
                    continue;
                }

                if (is_array($value)) {
                    $flat[$composed] = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    continue;
                }

                $flat[$composed] = $this->scalarize_value($value);
            }

            return $flat;
        }

        private function output_assoc($payload, $format = 'table')
        {
            $format = strtolower((string) $format);
            if (!in_array($format, array('table', 'json'), true)) {
                WP_CLI::error('Invalid format. Use table or json.');
            }

            if ('json' === $format) {
                WP_CLI::line(wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return;
            }

            $rows = array();
            foreach ($this->flatten_assoc((array) $payload) as $key => $value) {
                $rows[] = array(
                    'key'   => (string) $key,
                    'value' => (string) $value,
                );
            }

            if (empty($rows)) {
                WP_CLI::warning('No data available.');
                return;
            }

            \WP_CLI\Utils\format_items('table', $rows, array('key', 'value'));
        }

        private function parse_bool_cli_value($value)
        {
            $value = strtolower(trim((string) $value));

            if (in_array($value, array('1', 'true', 'yes', 'on', 'enabled'), true)) {
                return true;
            }

            if (in_array($value, array('0', 'false', 'no', 'off', 'disabled'), true)) {
                return false;
            }

            WP_CLI::error('Invalid boolean value. Use 1/0, true/false, yes/no, on/off.');
        }

        private function coerce_setting_value($key, $value)
        {
            $boolean_keys = array(
                'pageCacheEnabled',
                'objectCacheEnabled',
                'brotliEnabled',
                'gzipEnabled',
                'cacheStatsEnabled',
                'mediaOptimizationEnabled',
                'deferJsEnabled',
                'delayThirdPartyJsEnabled',
                'asyncExternalScriptsEnabled',
                'homepageCssBundleEnabled',
                'homepageCssBundleInlineEnabled',
                'pageCssBundleOnEntryEnabled',
                'frontendSafeModeEnabled',
                'sliderSafeModeEnabled',
                'clsDimensionsEnabled',
                'asyncCssEnabled',
                'aggressiveAsyncCssEnabled',
                'delayNonCriticalJsEnabled',
                'lcpImagePriorityEnabled',
                'lcpBoundaryDeferEnabled',
                'mainThreadReliefEnabled',
                'googleFontsSwapEnabled',
                'googleFontsLocalOptimizationEnabled',
                'selfHostedFontCssOptimizationEnabled',
                'selfHostedFontRuntimeRewriteEnabled',
                'speculationRulesEnabled',
                'browserCacheRulesEnabled',
                'varnishCliEnabled',
                'varnishCliDebug',
                'preRenderOnSave',
                'woocommerceSafeModeEnabled',
                'cacheCleanupEnabled',
                'cronWarmEnabled',
                'cronWarmStartAfterCleanup',
                'cronWarmStartAfterManualPurge',
                'staleWhileRevalidateEnabled',
                'cacheQueryStringsEnabled',
                'redisUseTls',
                'redisPersistent',
            );

            $integer_keys = array(
                'cacheCleanupIntervalHours',
                'redisPort',
                'redisDatabase',
                'redisConnectTimeoutMs',
                'redisReadTimeoutMs',
                'varnishCliTimeoutSeconds',
                'cronWarmPagesPerMinute',
                'scheduledWarmLimit',
                'cacheFreshTtlMinutes',
                'cacheMaxStaleMinutes',
            );

            $textarea_keys = array(
                'cacheExceptionPaths',
                'cacheExceptionQueryArgs',
                'deferJsForceList',
                'deferJsExcludeList',
                'homepageCssBundleExcludeList',
                'asyncCssExcludeList',
                'aggressiveAsyncCssExcludeList',
                'delayNonCriticalJsExcludeList',
                'lcpImagePriorityOverride',
                'varnishCliServers',
                'varnishCliKey',
                'varnishCliMethod',
                'objectCacheBackend',
                'cssBundleScope',
                'cacheQueryStringAllowlist',
                'redisHost',
                'redisPassword',
                'redisPrefix',
            );

            if (in_array($key, $boolean_keys, true)) {
                return $this->parse_bool_cli_value($value);
            }

            if (in_array($key, $integer_keys, true)) {
                return absint($value);
            }

            if (in_array($key, $textarea_keys, true)) {
                return str_replace(array('\\r\\n', '\\n', '\\r'), array("\n", "\n", "\n"), (string) $value);
            }

            return (string) $value;
        }

        /**
         * Purge the cache.
         *
         * ## OPTIONS
         *
         * [--cache-url=<url>]
         * : Purge a single local URL instead of the entire cache.
         *
         * [--all]
         * : Explicitly purge the entire cache. Equivalent to running `wp ultracache purge`.
         *
         * Note: `--url` is reserved by WP-CLI as a global parameter. Use `--cache-url` here.
         */
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

            $engine->purge_all();
            WP_CLI::success('Purged the full cache.');
        }

        private function get_warm_buckets_from_assoc_args($assoc_args)
        {
            $buckets = array('orig', 'webp', 'avif');
            if (!empty($assoc_args['buckets'])) {
                $buckets = array_values(array_unique(array_intersect(
                    array('orig', 'webp', 'avif'),
                    array_map('trim', explode(',', (string) $assoc_args['buckets']))
                )));
                if (empty($buckets)) {
                    WP_CLI::error('Invalid bucket list. Use orig,webp,avif.');
                }
            }

            return $buckets;
        }

        private function warm_url_list($engine, array $urls, array $buckets, $purge_first = false)
        {
            $urls = array_values(array_filter($urls));
            if (empty($urls)) {
                WP_CLI::warning('No URLs to warm.');
                return;
            }

            $progress = \WP_CLI\Utils\make_progress_bar('Warming cache', count($urls));
            $warmed = 0;
            $failed = 0;

            foreach ($urls as $url) {
                if ($purge_first && method_exists($engine, 'purge_url')) {
                    $engine->purge_url($url);
                }

                $result = $engine->warm_url($url, array('buckets' => $buckets));
                if (!empty($result['success'])) {
                    $warmed++;
                } else {
                    $failed++;
                    WP_CLI::warning($url . ' -> ' . (!empty($result['message']) ? $result['message'] : 'Warm failed.'));
                }
                $progress->tick();
            }

            $progress->finish();
            WP_CLI::success(sprintf('Warm finished. Success: %d, failed: %d.', $warmed, $failed));
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

            $result = $engine->warm_frontpage_html(array('buckets' => $this->get_warm_buckets_from_assoc_args($assoc_args)));
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

            $result = $engine->warm_frontpage_html_with_css();
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
            if (!$engine || !method_exists($engine, 'get_crawl_urls') || !method_exists($engine, 'warm_url') || !method_exists($engine, 'build_frontpage_css_bundle')) {
                WP_CLI::error('Site-wide HTML + CSS warming is not available.');
            }

            $urls = (array) $engine->get_crawl_urls();
            $limit = isset($assoc_args['limit']) ? max(0, absint($assoc_args['limit'])) : 0;
            if ($limit > 0) {
                $urls = array_slice($urls, 0, $limit);
            }

            $this->warm_url_list($engine, $urls, $this->get_warm_buckets_from_assoc_args($assoc_args), isset($assoc_args['purge-first']));

            $result = $engine->build_frontpage_css_bundle();
            if (!empty($result['success']) || !empty($result['skipped'])) {
                WP_CLI::success(!empty($result['message']) ? $result['message'] : 'All URLs warmed and front page CSS bundle rebuilt.');
                return;
            }

            WP_CLI::error(!empty($result['message']) ? $result['message'] : 'All URLs were warmed, but front page CSS rebuild failed.');
        }

        /**
         * Generate AVIF/WebP files.
         *
         * ## OPTIONS
         *
         * [--ids=<ids>]
         * : Comma-separated attachment IDs.
         *
         * [--limit=<number>]
         * : Limit how many discovered media IDs are processed.
         *
         * [--format=<format>]
         * : One of best, avif, webp, both. Default: both.
         *
         * [--only-missing]
         * : Skip variants that already exist.
         */
        public function media($args, $assoc_args)
        {
            $media = $this->get_media();
            if (!$media || !method_exists($media, 'generate_attachment_formats')) {
                WP_CLI::error('Media converter is not available.');
            }

            $format = !empty($assoc_args['format']) ? strtolower((string) $assoc_args['format']) : 'both';
            if (!in_array($format, array('best', 'avif', 'webp', 'both'), true)) {
                WP_CLI::error('Invalid format. Use best, avif, webp, or both.');
            }

            $only_missing = isset($assoc_args['only-missing']);
            $limit = isset($assoc_args['limit']) ? max(0, absint($assoc_args['limit'])) : 0;

            if (!empty($assoc_args['ids'])) {
                $ids = array_values(array_filter(array_map('absint', explode(',', (string) $assoc_args['ids']))));
                if ($limit > 0) {
                    $ids = array_slice($ids, 0, $limit);
                }

                if (empty($ids)) {
                    WP_CLI::warning('No attachments to process.');
                    return;
                }

                $progress = \WP_CLI\Utils\make_progress_bar('Generating media variants', count($ids));
                $attachments = 0;
                $avif = 0;
                $webp = 0;

                foreach ($ids as $attachment_id) {
                    $result = $media->generate_attachment_formats((int) $attachment_id, $format, $only_missing);
                    if (!empty($result['success'])) {
                        $attachments++;
                        $avif += (int) $result['avif'];
                        $webp += (int) $result['webp'];
                    }
                    $progress->tick();
                }

                $progress->finish();
                WP_CLI::success(sprintf('Processed %d attachments. Generated %d AVIF and %d WebP files.', $attachments, $avif, $webp));
                return;
            }

            if (!method_exists($media, 'get_media_ids_batch')) {
                $ids = method_exists($media, 'get_all_media_ids') ? (array) $media->get_all_media_ids() : array();
                if ($limit > 0) {
                    $ids = array_slice($ids, 0, $limit);
                }

                if (empty($ids)) {
                    WP_CLI::warning('No attachments to process.');
                    return;
                }

                $progress = \WP_CLI\Utils\make_progress_bar('Generating media variants', count($ids));
                $attachments = 0;
                $avif = 0;
                $webp = 0;

                foreach ($ids as $attachment_id) {
                    $result = $media->generate_attachment_formats((int) $attachment_id, $format, $only_missing);
                    if (!empty($result['success'])) {
                        $attachments++;
                        $avif += (int) $result['avif'];
                        $webp += (int) $result['webp'];
                    }
                    $progress->tick();
                }

                $progress->finish();
                WP_CLI::success(sprintf('Processed %d attachments. Generated %d AVIF and %d WebP files.', $attachments, $avif, $webp));
                return;
            }

            $first_batch = $media->get_media_ids_batch(0, 1);
            $total = (int) ($first_batch['total'] ?? 0);
            if ($limit > 0 && ($total <= 0 || $limit < $total)) {
                $total = $limit;
            }

            if ($total <= 0) {
                WP_CLI::warning('No attachments to process.');
                return;
            }

            $progress = \WP_CLI\Utils\make_progress_bar('Generating media variants', $total);
            $attachments = 0;
            $avif = 0;
            $webp = 0;
            $processed = 0;
            $offset = 0;
            $batch_size = 250;

            do {
                $remaining = $limit > 0 ? max(0, $limit - $processed) : $batch_size;
                if ($limit > 0 && 0 === $remaining) {
                    break;
                }

                $request_size = $limit > 0 ? min($batch_size, $remaining) : $batch_size;
                $batch = $media->get_media_ids_batch($offset, $request_size);
                $items = array_map('intval', (array) ($batch['items'] ?? array()));
                if (empty($items)) {
                    break;
                }

                foreach ($items as $attachment_id) {
                    $result = $media->generate_attachment_formats((int) $attachment_id, $format, $only_missing);
                    if (!empty($result['success'])) {
                        $attachments++;
                        $avif += (int) $result['avif'];
                        $webp += (int) $result['webp'];
                    }
                    $processed++;
                    $progress->tick();
                }

                $offset = (int) ($batch['nextOffset'] ?? ($offset + count($items)));
            } while (!empty($batch['hasMore']) && ($limit <= 0 || $processed < $limit));

            $progress->finish();
            WP_CLI::success(sprintf('Processed %d attachments. Generated %d AVIF and %d WebP files.', $attachments, $avif, $webp));
        }

        /**
         * Show UltraCache status.
         *
         * ## OPTIONS
         *
         * [--format=<format>]
         * : Output format: table or json. Default: table.
         *
         * [--section=<section>]
         * : One of summary, settings, diagnostics, stats, all. Default: summary.
         */
        public function status($args, $assoc_args)
        {
            $format = !empty($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
            $section = !empty($assoc_args['section']) ? strtolower((string) $assoc_args['section']) : 'summary';
            if (!in_array($section, array('summary', 'settings', 'diagnostics', 'stats', 'analytics', 'all'), true)) {
                WP_CLI::error('Invalid section. Use summary, settings, diagnostics, stats, analytics, or all.');
            }

            $settings = $this->get_dashboard_settings();
            $stats = $this->get_dashboard_stats();
            $diagnostics = $this->get_dashboard_diagnostics();
            unset($stats['diagnostics']);

            $payload = array();
            switch ($section) {
                case 'settings':
                    $payload = $this->redact_dashboard_settings_for_output($settings);
                    break;
                case 'diagnostics':
                    $payload = $diagnostics;
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
                        'optimizedImages' => (int) ($stats['imagesOptimized'] ?? ($stats['optimizedImages'] ?? 0)),
                        'avifImages' => (int) ($stats['avifImagesOptimized'] ?? ($stats['avifFiles'] ?? 0)),
                        'webpImages' => (int) ($stats['webpImagesOptimized'] ?? ($stats['webpFiles'] ?? 0)),
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
         * Read or update dashboard settings.
         *
         * ## OPTIONS
         *
         * <action>
         * : One of list, get, set.
         *
         * [<key>]
         * : Setting key for get/set.
         *
         * [<value>]
         * : New value for set.
         *
         * [--format=<format>]
         * : Output format for list/get: table or json. Default: table.
         *
         * ## EXAMPLES
         *
         *     wp ultracache settings list
         *     wp ultracache settings get pageCacheEnabled
         *     wp ultracache settings set gzipEnabled 1
         *     wp ultracache settings set cacheCleanupIntervalHours 24
         *     wp ultracache settings set cacheExceptionPaths "/cart/\n/checkout/"
         */
        public function settings($args, $assoc_args)
        {
            $action = !empty($args[0]) ? strtolower((string) $args[0]) : 'list';
            $format = !empty($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
            $current = $this->get_dashboard_settings();

            if ('list' === $action) {
                $this->output_assoc($this->redact_dashboard_settings_for_output($current), $format);
                return;
            }

            if ('get' === $action) {
                if (empty($args[1])) {
                    WP_CLI::error('Please provide a setting key.');
                }

                $key = (string) $args[1];
                if (in_array($key, $this->get_deprecated_setting_keys(), true)) {
                    WP_CLI::error('Deprecated setting key: ' . $key);
                }
                if (!array_key_exists($key, $current)) {
                    WP_CLI::error('Unknown setting key: ' . $key);
                }

                $this->output_assoc($this->redact_single_setting_for_output($key, $current), $format);
                return;
            }

            if ('set' === $action) {
                if (!empty($args[1]) && in_array((string) $args[1], $this->get_deprecated_setting_keys(), true)) {
                    WP_CLI::error('Deprecated setting key: ' . (string) $args[1]);
                }
                if (empty($args[1]) || !array_key_exists((string) $args[1], $current)) {
                    WP_CLI::error('Please provide a valid setting key.');
                }
                if (!array_key_exists(2, $args)) {
                    WP_CLI::error('Please provide a value.');
                }
                if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'persist_dashboard_settings')) {
                    WP_CLI::error('Settings persistence is not available.');
                }

                $key = (string) $args[1];
                $value = $this->coerce_setting_value($key, $args[2]);
                $next = $current;
                $next[$key] = $value;
                $response = Ultra_Cache_WP::persist_dashboard_settings($next);

                if (is_wp_error($response)) {
                    WP_CLI::error($response->get_error_message());
                }

                $updated = $this->get_dashboard_settings();
                WP_CLI::success(sprintf('Updated %s.', $key));
                $this->output_assoc($this->redact_single_setting_for_output($key, $updated), $format);
                return;
            }

            WP_CLI::error('Invalid action. Use list, get, or set.');
        }

        /**
         * Read or reset persistent cache analytics counters.
         *
         * ## OPTIONS
         *
         * [<action>]
         * : One of show or reset. Default: show.
         *
         * [--format=<format>]
         * : Output format for show: table or json. Default: table.
         *
         * ## EXAMPLES
         *
         *     wp ultracache stats
         *     wp ultracache stats --format=json
         *     wp ultracache stats reset
         */
        public function stats($args, $assoc_args)
        {
            $action = !empty($args[0]) ? strtolower((string) $args[0]) : 'show';
            $format = !empty($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';

            if ('reset' === $action) {
                $reset = false;

                foreach (array('Ultra_Cache_Engine') as $class) {
                    if (class_exists($class) && method_exists($class, 'reset_analytics')) {
                        call_user_func(array($class, 'reset_analytics'));
                        $reset = true;
                        break;
                    }
                }

                if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'reset_metrics')) {
                    Ultra_Cache_Object_Cache_Manager::reset_metrics();
                    $reset = true;
                }

                if (!$reset) {
                    WP_CLI::error('Analytics reset is not available.');
                }

                WP_CLI::success('UltraCache analytics counters were reset.');
                return;
            }

            if ('show' !== $action) {
                WP_CLI::error('Invalid action. Use show or reset.');
            }

            $payload = array(
                'pageCacheFiles' => 0,
                'pageCacheHits' => 0,
                'pageCacheMisses' => 0,
                'pageCacheBypasses' => 0,
                'pageCacheStores' => 0,
                'pageCacheStoreSkips' => 0,
                'pageCacheHitRatio' => 0.0,
                'pageCacheStaleHits' => 0,
                'pageCacheBackgroundRevalidations' => 0,
                'pageCacheBucketHits' => array('orig' => 0, 'webp' => 0, 'avif' => 0),
                'pageCacheEncodingHits' => array('identity' => 0, 'gzip' => 0, 'brotli' => 0),
                'topBypassReasons' => array(),
                'lastPurge' => array(),
                'lastWarm' => array(),
                'warmSuccessCount' => 0,
                'warmFailureCount' => 0,
                'objectCacheEntries' => 0,
                'objectCacheSizeBytes' => 0,
                'objectCacheSizeHuman' => '',
                'objectCacheSelectedBackend' => '',
                'objectCacheActiveBackend' => '',
                'objectCacheFallbackActive' => false,
                'objectCacheStatsSource' => '',
                'objectCacheRedisEntries' => 0,
                'objectCacheApcuEntries' => 0,
                'objectCacheDiskEntries' => 0,
                'objectCacheHits' => 0,
                'objectCacheMisses' => 0,
                'objectCacheHitRatio' => 0.0,
            );

            foreach (array('Ultra_Cache_Engine') as $class) {
                if (!class_exists($class)) {
                    continue;
                }

                if (method_exists($class, 'get_stats')) {
                    $engine_stats = call_user_func(array($class, 'get_stats'));
                    if (is_array($engine_stats)) {
                        $payload['pageCacheFiles'] = (int) ($engine_stats['pageCacheFiles'] ?? $payload['pageCacheFiles']);
                    }
                }

                if (method_exists($class, 'get_analytics_stats')) {
                    $analytics = call_user_func(array($class, 'get_analytics_stats'));
                    if (is_array($analytics)) {
                        $payload['pageCacheHits'] = (int) ($analytics['pageCacheHits'] ?? 0);
                        $payload['pageCacheMisses'] = (int) ($analytics['pageCacheMisses'] ?? 0);
                        $payload['pageCacheBypasses'] = (int) ($analytics['pageCacheBypasses'] ?? 0);
                        $payload['pageCacheStores'] = (int) ($analytics['pageCacheStores'] ?? 0);
                        $payload['pageCacheStoreSkips'] = (int) ($analytics['pageCacheStoreSkips'] ?? 0);
                        $payload['pageCacheHitRatio'] = (float) ($analytics['pageCacheHitRatio'] ?? 0);
                        $payload['pageCacheStaleHits'] = (int) ($analytics['pageCacheStaleHits'] ?? 0);
                        $payload['pageCacheBackgroundRevalidations'] = (int) ($analytics['pageCacheBackgroundRevalidations'] ?? 0);
                        $payload['pageCacheBucketHits'] = (array) ($analytics['pageCacheBucketHits'] ?? $payload['pageCacheBucketHits']);
                        $payload['pageCacheEncodingHits'] = (array) ($analytics['pageCacheEncodingHits'] ?? $payload['pageCacheEncodingHits']);
                        $payload['topBypassReasons'] = (array) ($analytics['topBypassReasons'] ?? array());
                        $payload['lastPurge'] = (array) ($analytics['lastPurge'] ?? array());
                        $payload['lastWarm'] = (array) ($analytics['lastWarm'] ?? array());
                        $payload['warmSuccessCount'] = (int) ($analytics['warmSuccessCount'] ?? 0);
                        $payload['warmFailureCount'] = (int) ($analytics['warmFailureCount'] ?? 0);
                    }
                    break;
                }
            }

            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'get_stats')) {
                $object_stats = Ultra_Cache_Object_Cache_Manager::get_stats();
                if (is_array($object_stats)) {
                    $payload['objectCacheEntries'] = (int) ($object_stats['objectCacheEntries'] ?? 0);
                    $payload['objectCacheSizeBytes'] = (int) ($object_stats['objectCacheSizeBytes'] ?? 0);
                    $payload['objectCacheSizeHuman'] = (string) ($object_stats['objectCacheSizeHuman'] ?? '');
                    $payload['objectCacheSelectedBackend'] = (string) ($object_stats['objectCacheSelectedBackend'] ?? '');
                    $payload['objectCacheActiveBackend'] = (string) ($object_stats['objectCacheActiveBackend'] ?? ($object_stats['objectCacheBackend'] ?? ''));
                    $payload['objectCacheFallbackActive'] = !empty($object_stats['objectCacheFallbackActive']);
                    $payload['objectCacheStatsSource'] = (string) ($object_stats['objectCacheStatsSource'] ?? '');
                    $payload['objectCacheRedisEntries'] = (int) ($object_stats['objectCacheRedisEntries'] ?? 0);
                    $payload['objectCacheApcuEntries'] = (int) ($object_stats['objectCacheApcuEntries'] ?? 0);
                    $payload['objectCacheDiskEntries'] = (int) ($object_stats['objectCacheDiskEntries'] ?? 0);
                    $payload['objectCacheHits'] = (int) ($object_stats['objectCacheHits'] ?? 0);
                    $payload['objectCacheMisses'] = (int) ($object_stats['objectCacheMisses'] ?? 0);
                    $payload['objectCacheHitRatio'] = (float) ($object_stats['objectCacheHitRatio'] ?? 0);
                }
            }

            $this->output_assoc($payload, $format);
        }


        /**
         * Test or trigger Varnish CLI helpers.
         *
         * ## OPTIONS
         *
         * <action>
         * : One of test, flush-all, or flush-url.
         *
         * [--cache-url=<url>]
         * : Local URL to purge when using flush-url.
         *
         * Note: `--url` is reserved by WP-CLI as a global parameter. Use `--cache-url` here.
         */
        public function varnish($args, $assoc_args)
        {
            $action = !empty($args[0]) ? strtolower((string) $args[0]) : 'test';

            if (!class_exists('Ultra_Cache_WP')) {
                WP_CLI::error('UltraCache runtime is not available.');
            }

            if ('test' === $action) {
                if (!method_exists('Ultra_Cache_WP', 'varnish_test_connection')) {
                    WP_CLI::error('Varnish helper is not available.');
                }
                $result = Ultra_Cache_WP::varnish_test_connection();
                if (empty($result['success'])) {
                    WP_CLI::error(!empty($result['message']) ? $result['message'] : 'Varnish test failed.');
                }
                WP_CLI::success((string) ($result['message'] ?? 'Varnish test succeeded.'));
                return;
            }

            if ('flush-all' === $action) {
                if (!method_exists('Ultra_Cache_WP', 'varnish_flush_all_current_host')) {
                    WP_CLI::error('Varnish helper is not available.');
                }
                $result = Ultra_Cache_WP::varnish_flush_all_current_host();
                if (empty($result['success'])) {
                    WP_CLI::error(!empty($result['message']) ? $result['message'] : 'Varnish flush-all failed.');
                }
                WP_CLI::success((string) ($result['message'] ?? 'Varnish flush-all succeeded.'));
                return;
            }

            if ('flush-url' === $action) {
                $target_url = !empty($assoc_args['cache-url']) ? $assoc_args['cache-url'] : '';
            if (!empty($target_url) && !empty($assoc_args['all'])) {
                WP_CLI::error('Use either --all or --cache-url, not both.');
            }

                $url = '' !== $target_url ? $this->require_local_site_url($target_url, $this->get_engine(), 'Please provide a valid local site URL for --cache-url.') : '';
                if ('' === $url) {
                    WP_CLI::error('Please provide a valid local site URL for --cache-url.');
                }
                if (!method_exists('Ultra_Cache_WP', 'varnish_flush_url')) {
                    WP_CLI::error('Varnish helper is not available.');
                }
                $result = Ultra_Cache_WP::varnish_flush_url($url);
                if (empty($result['success'])) {
                    WP_CLI::error(!empty($result['message']) ? $result['message'] : 'Varnish flush-url failed.');
                }
                WP_CLI::success((string) ($result['message'] ?? 'Varnish flush-url succeeded.'));
                return;
            }

            WP_CLI::error('Invalid action. Use test, flush-all, or flush-url.');
        }

        /**
         * Manage the cron warm up queue.
         *
         * ## OPTIONS
         *
         * <action>
         * : One of start, stop, tick, status.
         *
         * [--pages-per-minute=<number>]
         * : Override the saved pages per minute for this tick.
         */
        public function cron_warm($args, $assoc_args)
        {
            $action = !empty($args[0]) ? strtolower((string) $args[0]) : 'status';
            if (!class_exists('Ultra_Cache_WP')) {
                WP_CLI::error('UltraCache core is not available.');
            }

            if ('start' === $action) {
                if (!method_exists('Ultra_Cache_WP', 'start_cron_warmup_queue')) {
                    WP_CLI::error('Cron warm helper is not available.');
                }
                $result = Ultra_Cache_WP::start_cron_warmup_queue('cli', false);
                if (empty($result['success'])) {
                    WP_CLI::error(!empty($result['message']) ? $result['message'] : 'Cron warm queue could not start.');
                }
                WP_CLI::success((string) ($result['message'] ?? 'Cron warm queue started.'));
                return;
            }

            if ('stop' === $action) {
                if (!method_exists('Ultra_Cache_WP', 'stop_cron_warmup_queue')) {
                    WP_CLI::error('Cron warm helper is not available.');
                }
                $result = Ultra_Cache_WP::stop_cron_warmup_queue('cli');
                if (empty($result['success'])) {
                    WP_CLI::error(!empty($result['message']) ? $result['message'] : 'Cron warm queue could not stop.');
                }
                WP_CLI::success((string) ($result['message'] ?? 'Cron warm queue stopped.'));
                return;
            }

            if ('tick' === $action) {
                if (!method_exists('Ultra_Cache_WP', 'run_cron_warm_tick')) {
                    WP_CLI::error('Cron warm helper is not available.');
                }
                $result = Ultra_Cache_WP::run_cron_warm_tick(array(
                    'invokedBy' => 'cli',
                    'pagesPerMinute' => isset($assoc_args['pages-per-minute']) ? absint($assoc_args['pages-per-minute']) : null,
                ));
                if (empty($result['success'])) {
                    WP_CLI::error(!empty($result['message']) ? $result['message'] : 'Cron warm tick failed.');
                }
                $state = isset($result['state']) && is_array($result['state']) ? $result['state'] : array();
                WP_CLI::success(sprintf('%s Processed %d/%d, warmed %d this run.', (string) ($result['message'] ?? 'Cron warm tick complete.'), (int) ($state['processed'] ?? 0), (int) ($state['total'] ?? 0), (int) ($result['warmedThisRun'] ?? 0)));
                return;
            }

            if ('status' === $action) {
                if (!method_exists('Ultra_Cache_WP', 'get_cron_warm_status')) {
                    WP_CLI::error('Cron warm helper is not available.');
                }
                $status = Ultra_Cache_WP::get_cron_warm_status();
                WP_CLI::line(wp_json_encode($status));
                return;
            }

            WP_CLI::error('Invalid action. Use start, stop, tick, or status.');
        }
        private function self_test_add_check(array &$checks, $name, $passed, $message = '', $severity = 'error', array $meta = array())
        {
            $status = $passed ? 'pass' : ('warning' === $severity ? 'warning' : 'fail');
            $checks[] = array(
                'name'     => (string) $name,
                'status'   => $status,
                'message'  => (string) $message,
                'severity' => $passed ? 'info' : (string) $severity,
                'meta'     => $meta,
            );
        }

        private function get_file_owner_summary($path)
        {
            $summary = array(
                'exists' => file_exists($path),
                'path' => (string) $path,
                'owner' => '',
                'group' => '',
                'mode' => '',
            );

            if (!$summary['exists']) {
                return $summary;
            }

            $perms = @fileperms($path);
            $summary['mode'] = false === $perms ? '' : substr(sprintf('%o', $perms), -4);

            $owner_id = @fileowner($path);
            if (false !== $owner_id && function_exists('posix_getpwuid')) {
                $owner = @posix_getpwuid($owner_id);
                $summary['owner'] = is_array($owner) && !empty($owner['name']) ? (string) $owner['name'] : (string) $owner_id;
            } elseif (false !== $owner_id) {
                $summary['owner'] = (string) $owner_id;
            }

            $group_id = @filegroup($path);
            if (false !== $group_id && function_exists('posix_getgrgid')) {
                $group = @posix_getgrgid($group_id);
                $summary['group'] = is_array($group) && !empty($group['name']) ? (string) $group['name'] : (string) $group_id;
            } elseif (false !== $group_id) {
                $summary['group'] = (string) $group_id;
            }

            return $summary;
        }

        /**
         * Run a compact UltraCache self-test.
         *
         * ## OPTIONS
         *
         * [--format=<format>]
         * : Output format: table or json. Default: table.
         */
        public function self_test($args, $assoc_args)
        {
            $format = !empty($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
            if (!in_array(strtolower($format), array('table', 'json'), true)) {
                WP_CLI::error('Invalid format. Use table or json.');
            }

            $checks = array();
            $settings = $this->get_dashboard_settings();
            $diagnostics = $this->get_dashboard_diagnostics();
            $stats = $this->get_dashboard_stats();

            $this->self_test_add_check(
                $checks,
                'Version constant',
                defined('UCWP_VERSION') && '' !== (string) UCWP_VERSION,
                defined('UCWP_VERSION') ? ('version=' . UCWP_VERSION) : 'Version constant unavailable.'
            );

            $stored = get_option(defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY : 'ucwp_settings', array());
            $stored = is_array($stored) ? $stored : array();
            $deprecated_keys = array_values(array_intersect(array_keys($stored), array_merge($this->get_deprecated_setting_keys(), array('criticalCssEnabled', 'criticalCssInlineEnabled', 'criticalCssExcludeList'))));
            $this->self_test_add_check(
                $checks,
                'Stored settings canonical',
                empty($deprecated_keys),
                empty($deprecated_keys) ? 'No deprecated setting keys remain in stored settings.' : ('Deprecated keys: ' . implode(', ', $deprecated_keys)),
                'warning'
            );

            $invalid_lcp_combo = (!empty($settings['frontendSafeModeEnabled']) || !empty($settings['sliderSafeModeEnabled'])) && !empty($settings['lcpBoundaryDeferEnabled']);
            $this->self_test_add_check(
                $checks,
                'LCP Boundary Defer guard',
                !$invalid_lcp_combo,
                $invalid_lcp_combo ? 'LCP Boundary Defer is enabled while a safe mode is active.' : 'LCP Boundary Defer setting is compatible with current safe-mode settings.'
            );

            $cache_dir = defined('UCWP_CACHE_DIR') ? UCWP_CACHE_DIR : WP_CONTENT_DIR . '/cache/ultracache';
            $this->self_test_add_check(
                $checks,
                'Cache directory writable',
                is_dir($cache_dir) ? wp_is_writable($cache_dir) : wp_is_writable(dirname($cache_dir)),
                'cacheDir=' . $cache_dir,
                'error',
                $this->get_file_owner_summary($cache_dir)
            );

            $advanced_cache = WP_CONTENT_DIR . '/advanced-cache.php';
            $page_cache_expected = !empty($settings['pageCacheEnabled']);
            $advanced_exists = file_exists($advanced_cache);
            $advanced_dropin_ok = $page_cache_expected ? $advanced_exists : !$advanced_exists;
            $this->self_test_add_check(
                $checks,
                'Page cache drop-in',
                $advanced_dropin_ok,
                $page_cache_expected ? ($advanced_exists ? 'advanced-cache.php is installed.' : 'Page Cache is enabled but advanced-cache.php is missing.') : ($advanced_exists ? 'advanced-cache.php exists while Page Cache setting is off.' : 'Page Cache disabled; no drop-in required.'),
                $page_cache_expected ? 'error' : 'warning',
                $this->get_file_owner_summary($advanced_cache)
            );

            $object_cache = WP_CONTENT_DIR . '/object-cache.php';
            $object_expected = !empty($settings['objectCacheEnabled']);
            $object_exists = file_exists($object_cache);
            $object_status = !empty($diagnostics['objectCache']) && is_array($diagnostics['objectCache']) ? $diagnostics['objectCache'] : array();
            $object_dropin_ok = $object_expected ? $object_exists : !$object_exists;
            $this->self_test_add_check(
                $checks,
                'Object cache drop-in',
                $object_dropin_ok,
                $object_expected ? ($object_exists ? 'object-cache.php is installed.' : 'Object Cache is enabled but object-cache.php is missing.') : ($object_exists ? 'object-cache.php exists while Object Cache setting is off.' : 'Object Cache disabled; no drop-in required.'),
                $object_expected ? 'error' : 'warning',
                $this->get_file_owner_summary($object_cache)
            );

            $selected_backend = (string) ($object_status['selectedBackend'] ?? ($stats['objectCacheSelectedBackend'] ?? ''));
            $active_backend = (string) ($object_status['activeBackend'] ?? ($stats['objectCacheActiveBackend'] ?? ''));
            $this->self_test_add_check(
                $checks,
                'Object cache backend truth',
                empty($object_expected) || ('' !== $selected_backend && '' !== $active_backend),
                empty($object_expected) ? 'Object Cache disabled.' : ('selected=' . $selected_backend . ', active=' . $active_backend . (!empty($object_status['fallbackActive']) ? ', fallback active' : '')),
                'error',
                array(
                    'selectedBackend' => $selected_backend,
                    'activeBackend' => $active_backend,
                    'fallbackBackend' => (string) ($object_status['fallbackBackend'] ?? ''),
                    'fallbackActive' => !empty($object_status['fallbackActive']),
                )
            );

            $payload_probe = array();
            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'test_runtime_object_cache_payloads')) {
                $payload_probe = Ultra_Cache_Object_Cache_Manager::test_runtime_object_cache_payloads();
            }
            $this->self_test_add_check(
                $checks,
                'Object payload probe',
                empty($object_expected) || !empty($payload_probe['success']),
                empty($object_expected) ? 'Object Cache disabled.' : (string) ($payload_probe['message'] ?? 'Payload probe unavailable.'),
                'error',
                is_array($payload_probe) ? $payload_probe : array()
            );

            $manifest_path = trailingslashit($cache_dir) . 'css-bundles/manifest.json';
            $css_bundle_expected = !empty($settings['homepageCssBundleEnabled']);
            $manifest_ok = !$css_bundle_expected || (file_exists($manifest_path) && is_readable($manifest_path) && is_array(json_decode((string) file_get_contents($manifest_path), true)));
            $this->self_test_add_check(
                $checks,
                'CSS bundle manifest',
                $manifest_ok,
                $css_bundle_expected ? ($manifest_ok ? 'CSS bundle manifest is readable.' : 'CSS bundling is enabled but manifest is missing or invalid.') : 'CSS bundling disabled; manifest not required.',
                'warning',
                array('manifestPath' => $manifest_path)
            );

            $cron_status = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_cron_warm_status') ? Ultra_Cache_WP::get_cron_warm_status() : array();
            $this->self_test_add_check(
                $checks,
                'Cron warm status payload',
                is_array($cron_status) && array_key_exists('enabled', $cron_status),
                is_array($cron_status) ? ('enabled=' . (!empty($cron_status['enabled']) ? 'yes' : 'no') . ', active=' . (!empty($cron_status['active']) ? 'yes' : 'no')) : 'Cron warm status unavailable.',
                'warning',
                is_array($cron_status) ? $cron_status : array()
            );

            $failures = 0;
            $warnings = 0;
            foreach ($checks as $check) {
                if ('fail' === $check['status']) {
                    $failures++;
                } elseif ('warning' === $check['status']) {
                    $warnings++;
                }
            }

            $payload = array(
                'summary' => array(
                    'success' => 0 === $failures,
                    'failures' => $failures,
                    'warnings' => $warnings,
                    'version' => defined('UCWP_VERSION') ? UCWP_VERSION : '',
                ),
                'checks' => $checks,
            );

            if ('json' === strtolower($format)) {
                WP_CLI::line(wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                if ($failures > 0) {
                    WP_CLI::halt(1);
                }
                return;
            }

            $rows = array();
            foreach ($checks as $check) {
                $rows[] = array(
                    'status' => strtoupper((string) $check['status']),
                    'check' => (string) $check['name'],
                    'message' => (string) $check['message'],
                );
            }
            \WP_CLI\Utils\format_items('table', $rows, array('status', 'check', 'message'));

            if ($failures > 0) {
                WP_CLI::error(sprintf('UltraCache self-test failed: %d failure(s), %d warning(s).', $failures, $warnings));
            }

            WP_CLI::success(sprintf('UltraCache self-test passed with %d warning(s).', $warnings));
        }


        /**
         * Flush the object cache.
         */
        public function flush_object_cache($args, $assoc_args)
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'flush_object_cache')) {
                WP_CLI::error('Object cache helper is not available.');
            }

            $result = Ultra_Cache_WP::flush_object_cache();
            if (empty($result['success'])) {
                WP_CLI::error(!empty($result['message']) ? $result['message'] : 'Object cache flush failed.');
            }

            WP_CLI::success((string) ($result['message'] ?? 'Object cache flushed.'));
        }


        /**
         * Run the scheduled cleanup job immediately.
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

            WP_CLI::success(sprintf('Scheduled cleanup finished. Warmed %d URL(s).', (int) ($result['warmed'] ?? 0)));
        }
    }
}

if (!class_exists('Ultra_Cache_WP_CLI')) {
    final class Ultra_Cache_WP_CLI
    {
        public static function register()
        {
            if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) {
                return;
            }

            if (!class_exists('UCWP_CLI_Command')) {
                return;
            }

            if (defined('UCWP_WP_CLI_REGISTERED')) {
                return;
            }

            define('UCWP_WP_CLI_REGISTERED', true);
            WP_CLI::add_command('ultracache', 'UCWP_CLI_Command');
        }
    }
}

