<?php
/**
 * WP-CLI command group for UltraCache.
 */

defined('ABSPATH') || exit;

if (!trait_exists('UCWP_CLI_Helpers_Trait')) {
    trait UCWP_CLI_Helpers_Trait
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
                'deferAllJsEnabled',
                'delaySafeThirdPartyJsEnabled',
                'delayFunctionalThirdPartyJsEnabled',
                'asyncExternalScriptsEnabled',
                'homepageCssBundleEnabled',
                'homepageCssBundleInlineEnabled',
                'leftoverCssBundleEnabled',
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
                'delaySafeThirdPartyJsPatterns',
                'delayFunctionalThirdPartyJsPatterns',
                'delayThirdPartyJsExcludeList',
                'homepageCssBundleExcludeList',
                'asyncCssExcludeList',
                'aggressiveAsyncCssExcludeList',
                'delayNonCriticalJsExcludeList',
                'lcpImagePriorityOverride',
                'manualLcpHeroSelector',
                'varnishCliServers',
                'varnishCliKey',
                'varnishCliMethod',
                'objectCacheBackend',
                'cssBundleScope',
                'cacheQueryStringAllowlist',
                'googleFontsAdditionalScanUrls',
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

    }
}
