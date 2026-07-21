<?php
/**
 * WP-CLI command group for UltraCache.
 */

defined('ABSPATH') || exit;

trait ULTRACACHE_CLI_Integrations_Trait
{
    public function varnish($args, $assoc_args)
    {
        $action = !empty($args[0]) ? strtolower((string) $args[0]) : 'test';

        if (!class_exists('Ultra_Cache_WP')) {
            WP_CLI::error('UltraCache runtime is not available.');
        }

        if ('test' === $action) {
            if (!method_exists('Ultra_Cache_WP', 'run_varnish_basic_test')) {
                WP_CLI::error('Varnish helper is not available.');
            }
            $result = Ultra_Cache_WP::run_varnish_basic_test();
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

    public function google_fonts_rebuild($args, $assoc_args)
    {
        $engine = $this->get_engine();
        if (!$engine || !method_exists($engine, 'rebuild_google_fonts_cache_from_scan_urls')) {
            WP_CLI::error('Google Fonts rebuild helper is not available.');
        }

        $result = $engine->rebuild_google_fonts_cache_from_scan_urls(array(), !empty($assoc_args['clear']), 'wp-cli');
        if (empty($result['success'])) {
            WP_CLI::error(!empty($result['message']) ? (string) $result['message'] : 'Google Fonts rebuild failed.');
        }

        WP_CLI::log(sprintf(
            'Scanned URLs: %d, Google Fonts URLs: %d, built: %d, failed: %d.',
            (int) ($result['scannedUrls'] ?? 0),
            (int) ($result['fontUrls'] ?? 0),
            (int) ($result['built'] ?? 0),
            (int) ($result['failed'] ?? 0)
        ));
        WP_CLI::success(!empty($result['message']) ? (string) $result['message'] : 'Google Fonts cache rebuilt.');
    }

    /**
     * Show or clear the last STORE profiler report.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : One of show, clear. Default: show.
     *
     * [--format=<format>]
     * : Output format for show: table or json. Default: table.
     */

    public function store_profile($args, $assoc_args)
    {
        $action = !empty($args[0]) ? strtolower((string) $args[0]) : 'show';
        $format = !empty($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
        $engine = $this->get_engine();
        if (!$engine || !method_exists($engine, 'get_last_store_profile')) {
            WP_CLI::error('STORE profiler is not available.');
        }

        if ('clear' === $action) {
            if (!method_exists($engine, 'clear_last_store_profile')) {
                WP_CLI::error('STORE profiler clear helper is not available.');
            }
            $ok = (bool) $engine->clear_last_store_profile();
            if (!$ok) {
                WP_CLI::error('Could not clear last STORE profiler report.');
            }
            WP_CLI::success('Last STORE profiler report cleared.');
            return;
        }

        if ('show' !== $action) {
            WP_CLI::error('Invalid action. Use show or clear.');
        }

        $profile = $engine->get_last_store_profile();
        if (empty($profile)) {
            WP_CLI::warning('No STORE profiler report found yet. Trigger a STORE request with X-UltraCache-Store-Profile: 1 or ?ultracache_store_profile=1.');
            return;
        }

        $this->output_assoc($profile, $format);
    }

    /**
     * Run CSS critical-path diagnostics for a URL.
     *
     * ## OPTIONS
     *
     * [<url>]
     * : Local site URL to profile. Defaults to the homepage.
     *
     * [--url=<url>]
     * : Local site URL to profile. Alternative to the positional URL.
     *
     * [--last]
     * : Read the last saved STORE profile instead of running a fresh profile-bypass request.
     *
     * [--format=<format>]
     * : table or json. Default: table.
     */

    public function css_diagnostics($args, $assoc_args)
    {
        $format = !empty($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
        $engine = $this->get_engine();
        if (!$engine || !method_exists($engine, 'get_last_store_profile')) {
            WP_CLI::error('STORE profiler is not available.');
        }

        $url = !empty($assoc_args['url']) ? (string) $assoc_args['url'] : (!empty($args[0]) ? (string) $args[0] : home_url('/'));
        $url = $this->require_local_site_url($url, $engine, 'Please provide a valid local site URL for CSS diagnostics.');

        $meta = array('url' => $url, 'cacheBypassedForDiagnostic' => false);

        if (empty($assoc_args['last'])) {
            if (method_exists($engine, 'clear_last_store_profile')) {
                $engine->clear_last_store_profile();
            }

            $started = microtime(true);
            $response = ultracache_safe_loopback_remote_request($url, array(
                'timeout'     => 90,
                'redirection' => 3,
                'headers'     => array(
                    'X-UltraCache-Store-Profile'  => '1',
                    'X-UltraCache-Debug'          => '1',
                    'X-UltraCache-Profile-Bypass' => '1',
                    'X-UltraCache-Token'          => (function_exists('ultracache_create_runtime_control_token') ? ultracache_create_runtime_control_token() : ''),
                ),
                'user-agent'  => 'UltraCache CSS Diagnostics/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : 'unknown') . '; ' . home_url('/'),
            ));
            $elapsed_ms = (int) round((microtime(true) - $started) * 1000);

            if (is_wp_error($response)) {
                WP_CLI::error('CSS diagnostics request failed: ' . $response->get_error_message());
            }

            $meta['responseCode'] = (int) wp_remote_retrieve_response_code($response);
            $meta['requestMs'] = $elapsed_ms;
            $meta['cacheStatus'] = (string) wp_remote_retrieve_header($response, 'x-ultra-cache');
            $meta['cacheSource'] = (string) wp_remote_retrieve_header($response, 'x-ultra-cache-source');
            $meta['profileHeader'] = (string) wp_remote_retrieve_header($response, 'x-ultra-cache-store-profile');
            $meta['cacheBypassedForDiagnostic'] = true;
        }

        $profile = $engine->get_last_store_profile();
        if (!is_array($profile) || empty($profile)) {
            WP_CLI::error('No STORE profile report found for CSS diagnostics.');
        }

        $this->output_assoc($this->summarize_css_diagnostics_profile($profile, $meta), $format);
    }

    private function summarize_css_diagnostics_profile(array $profile, array $request_meta = array())
    {
        $css_context = isset($profile['css_bundle_context_after']) && is_array($profile['css_bundle_context_after']) ? $profile['css_bundle_context_after'] : array();
        $critical = isset($profile['critical_request_chain']) && is_array($profile['critical_request_chain']) ? $profile['critical_request_chain'] : array();
        $leftover = isset($profile['leftover_css_bundle']) && is_array($profile['leftover_css_bundle']) ? $profile['leftover_css_bundle'] : array();            $async_css = isset($profile['async_css_diagnostics']) && is_array($profile['async_css_diagnostics']) ? $profile['async_css_diagnostics'] : array();
        $stages = array('original' => array(), 'main' => array(), 'leftover' => array(), 'final' => array());

        if (isset($profile['stages']) && is_array($profile['stages'])) {
            foreach ($profile['stages'] as $stage) {
                if (!is_array($stage)) {
                    continue;
                }
                $name = isset($stage['stage']) ? (string) $stage['stage'] : '';
                if ('original_wordpress_html' === $name) {
                    $stages['original'] = $stage;
                } elseif ('replace-homepage-css-bundle' === $name || 'replace_page_css_bundle' === $name) {
                    $stages['main'] = $stage;
                } elseif ('consolidate-leftover-css-bundle' === $name) {
                    $stages['leftover'] = $stage;
                }
                $stages['final'] = $stage;
            }
        }

        $final = $stages['final'];
        $protected_css = array();
        $remaining_css = array();
        if (isset($critical['style_candidates']) && is_array($critical['style_candidates'])) {
            foreach ($critical['style_candidates'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $compact = array(
                    'url'       => isset($item['url']) ? (string) $item['url'] : (isset($item['path']) ? (string) $item['path'] : ''),
                    'status'    => isset($item['status']) ? (string) $item['status'] : '',
                    'origin'    => isset($item['origin']) ? (string) $item['origin'] : '',
                    'location'  => isset($item['location']) ? (string) $item['location'] : '',
                    'bytes'     => isset($item['bytes']) ? (int) $item['bytes'] : 0,
                    'protected' => !empty($item['protected']),
                    'reason'    => isset($item['reason']) ? (string) $item['reason'] : '',
                );
                if (!empty($item['protected']) && count($protected_css) < 12) {
                    $protected_css[] = $compact;
                }
                if (!empty($item['renderBlocking']) && count($remaining_css) < 20) {
                    $remaining_css[] = $compact;
                }
            }
        }

        $recommendations = array();
        $main_links = isset($stages['main']['stylesheet_links']) ? (int) $stages['main']['stylesheet_links'] : 0;
        $final_links = isset($final['stylesheet_links']) ? (int) $final['stylesheet_links'] : 0;
        if (!empty($leftover['enabled']) && !empty($leftover['success'])) {
            $recommendations[] = sprintf('Consolidate Remaining CSS replaced %d leftover stylesheet link(s).', (int) ($leftover['replaced_link_count'] ?? 0));
        } elseif (empty($leftover['enabled']) && $main_links > 0 && $final_links > 8) {
            $recommendations[] = 'Consider testing Consolidate Remaining CSS to reduce leftover render-blocking stylesheet calls.';
        }
        if ((int) ($css_context['bundle_file_bytes'] ?? 0) > 153600) {
            $recommendations[] = 'Main CSS bundle is large and render-blocking; next candidate is critical CSS split or async non-critical bundle mode.';
        }

        return array(
            'url' => isset($request_meta['url']) ? (string) $request_meta['url'] : (isset($profile['url']) ? (string) $profile['url'] : ''),
            'version' => isset($profile['version']) ? (string) $profile['version'] : (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : ''),
            'responseCode' => isset($request_meta['responseCode']) ? (int) $request_meta['responseCode'] : 0,
            'requestMs' => isset($request_meta['requestMs']) ? (int) $request_meta['requestMs'] : 0,
            'cacheStatus' => isset($request_meta['cacheStatus']) ? (string) $request_meta['cacheStatus'] : '',
            'cacheSource' => isset($request_meta['cacheSource']) ? (string) $request_meta['cacheSource'] : '',
            'profileHeader' => isset($request_meta['profileHeader']) ? (string) $request_meta['profileHeader'] : '',
            'cacheBypassedForDiagnostic' => !empty($request_meta['cacheBypassedForDiagnostic']),
            'originalStylesheetLinks' => isset($stages['original']['stylesheet_links']) ? (int) $stages['original']['stylesheet_links'] : 0,
            'afterMainBundleStylesheetLinks' => $main_links,
            'afterLeftoverBundleStylesheetLinks' => isset($stages['leftover']['stylesheet_links']) ? (int) $stages['leftover']['stylesheet_links'] : 0,
            'finalStylesheetLinks' => $final_links,
            'finalRenderBlockingStylesheets' => isset($final['render_blocking_stylesheet_links']) ? (int) $final['render_blocking_stylesheet_links'] : 0,
            'renderBlockingBundleLinks' => isset($final['render_blocking_css_bundle_links']) ? (int) $final['render_blocking_css_bundle_links'] : 0,
            'renderBlockingNonBundleLinks' => isset($final['render_blocking_non_bundle_stylesheet_links']) ? (int) $final['render_blocking_non_bundle_stylesheet_links'] : 0,
            'mainCssBundle' => array(
                'fileExists' => !empty($css_context['bundle_file_exists']),
                'fileBytes' => isset($css_context['bundle_file_bytes']) ? (int) $css_context['bundle_file_bytes'] : 0,
                'sourceUrlCount' => isset($css_context['source_url_count']) ? (int) $css_context['source_url_count'] : 0,
                'sourceBytesTotal' => isset($css_context['source_bytes_total']) ? (int) $css_context['source_bytes_total'] : 0,
                'largestSourceBytes' => isset($css_context['largest_source_bytes']) ? (int) $css_context['largest_source_bytes'] : 0,
                'largestSourceUrl' => isset($css_context['largest_source_url']) ? (string) $css_context['largest_source_url'] : '',
                'mode' => isset($css_context['mode']) ? (string) $css_context['mode'] : '',
                'sourceTop' => isset($css_context['source_top']) && is_array($css_context['source_top']) ? array_slice($css_context['source_top'], 0, 8) : array(),
            ),
            'asyncCssDiagnostics' => array(
                'available' => !empty($async_css['available']),
                'enabled' => !empty($async_css['enabled']),
                'aggressiveEnabled' => !empty($async_css['aggressive_enabled']),
                'safe' => !isset($async_css['safe']) || !empty($async_css['safe']),
                'scanned' => isset($async_css['scanned']) ? (int) $async_css['scanned'] : 0,
                'rewritten' => isset($async_css['rewritten']) ? (int) $async_css['rewritten'] : 0,
                'skipped' => isset($async_css['skipped']) ? (int) $async_css['skipped'] : 0,
                'unresolved' => isset($async_css['unresolved']) ? (int) $async_css['unresolved'] : 0,
                'reasonCounts' => isset($async_css['reason_counts']) && is_array($async_css['reason_counts']) ? $async_css['reason_counts'] : array(),
                'items' => isset($async_css['items']) && is_array($async_css['items']) ? array_slice($async_css['items'], 0, 80) : array(),
            ),                'leftoverCssBundle' => array(
                'enabled' => !empty($leftover['enabled']),
                'success' => !empty($leftover['success']),
                'candidateCount' => isset($leftover['candidate_count']) ? (int) $leftover['candidate_count'] : 0,
                'replacedLinkCount' => isset($leftover['replaced_link_count']) ? (int) $leftover['replaced_link_count'] : 0,
                'skippedProtectedCount' => isset($leftover['skipped_protected_count']) ? (int) $leftover['skipped_protected_count'] : 0,
                'bundleBytes' => isset($leftover['bundle_bytes']) ? (int) $leftover['bundle_bytes'] : 0,
                'sourceBytesTotal' => isset($leftover['source_bytes_total']) ? (int) $leftover['source_bytes_total'] : 0,
                'skippedReason' => isset($leftover['skipped_reason']) ? (string) $leftover['skipped_reason'] : '',
            ),
            'criticalRequestChain' => array(
                'available' => !empty($critical['available']),
                'renderBlockingStyleCount' => isset($critical['render_blocking_style_count']) ? (int) $critical['render_blocking_style_count'] : 0,
                'renderBlockingScriptCount' => isset($critical['render_blocking_script_count']) ? (int) $critical['render_blocking_script_count'] : 0,
                'delayedScriptCount' => isset($critical['delayed_script_count']) ? (int) $critical['delayed_script_count'] : 0,
                'protectedStyleCount' => isset($critical['protected_style_count']) ? (int) $critical['protected_style_count'] : 0,
                'protectedScriptCount' => isset($critical['protected_script_count']) ? (int) $critical['protected_script_count'] : 0,
            ),
            'protectedCss' => $protected_css,
            'remainingRenderBlockingCss' => $remaining_css,
            'recommendations' => $recommendations,
        );
    }

    /**
     * Run the scheduled cleanup job immediately.
     */

}
