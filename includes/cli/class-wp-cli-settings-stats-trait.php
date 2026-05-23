<?php
/**
 * WP-CLI command group for UltraCache.
 */

defined('ABSPATH') || exit;

if (!trait_exists('UCWP_CLI_Settings_Stats_Trait')) {
    trait UCWP_CLI_Settings_Stats_Trait
    {
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

            $stored = get_option(defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY : 'ultracache_settings', array());
            $stored = is_array($stored) ? $stored : array();
            $deprecated_keys = array_values(array_intersect(array_keys($stored), array_merge($this->get_deprecated_setting_keys(), array('criticalCssEnabled', 'criticalCssInlineEnabled', 'criticalCssExcludeList'))));
            $this->self_test_add_check(
                $checks,
                'Stored settings canonical',
                empty($deprecated_keys),
                empty($deprecated_keys) ? 'No deprecated setting keys remain in stored settings.' : ('Deprecated keys: ' . implode(', ', $deprecated_keys)),
                'warning'
            );

            $cache_dir = ucwp_content_cache_storage_dir();
            $this->self_test_add_check(
                $checks,
                'Cache directory writable',
                is_dir($cache_dir) ? wp_is_writable($cache_dir) : wp_is_writable(dirname($cache_dir)),
                'cacheDir=' . $cache_dir,
                'error',
                $this->get_file_owner_summary($cache_dir)
            );

            $advanced_cache = ucwp_dropin_path('advanced-cache.php');
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

            $object_cache = ucwp_dropin_path('object-cache.php');
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
            $manifest_raw = ($css_bundle_expected && file_exists($manifest_path) && is_readable($manifest_path)) ? ucwp_safe_file_get_contents($manifest_path, 'wp_cli_css_bundle_manifest_read', true) : '';
            $manifest_ok = !$css_bundle_expected || (is_string($manifest_raw) && is_array(json_decode((string) $manifest_raw, true)));
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
         * Rebuild the local Google Fonts cache from the homepage and configured extra scan URLs.
         *
         * ## OPTIONS
         *
         * [--clear]
         * : Clear existing local Google Fonts CSS/WOFF files before rebuilding.
         */

    }
}
