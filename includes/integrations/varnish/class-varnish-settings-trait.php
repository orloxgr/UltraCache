<?php
/**
 * Varnish settings and result-state helpers for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Settings_Trait
{
        /**
         * Depth counter for explicitly initiated Varnish test operations.
         * Test requests must not update production endpoint or operation metrics.
         *
         * @var int
         */
        private static $varnish_test_run_depth = 0;

        protected static function begin_varnish_test_run()
        {
            self::$varnish_test_run_depth = min(10, max(0, (int) self::$varnish_test_run_depth) + 1);
        }

        protected static function end_varnish_test_run()
        {
            self::$varnish_test_run_depth = max(0, (int) self::$varnish_test_run_depth - 1);
        }

        private static function is_varnish_test_run_active()
        {
            return (int) self::$varnish_test_run_depth > 0;
        }
        protected static function sanitize_varnish_string($value)
        {
            $value = (string) $value;
            if (function_exists('ultracache_redact_sensitive_string')) {
                $value = ultracache_redact_sensitive_string($value);
            }

            return $value;
        }

        private static function sanitize_varnish_result_value($value)
        {
            if (is_array($value)) {
                $clean = array();
                foreach ($value as $key => $child) {
                    $clean[$key] = self::sanitize_varnish_result_value($child);
                }
                return $clean;
            }

            if (is_string($value)) {
                return self::sanitize_varnish_string($value);
            }

            return $value;
        }

        private static function sanitize_varnish_result(array $result)
        {
            return self::sanitize_varnish_result_value($result);
        }

        protected static function set_varnish_last_result(array $result, $record_metrics = true)
        {
            if (self::is_varnish_test_run_active()) {
                return;
            }

            if (in_array(sanitize_key((string) ($result['testType'] ?? '')), array('basic', 'behavior'), true)
                && method_exists(static::class, 'bind_varnish_capability_contracts')) {
                $result = self::bind_varnish_capability_contracts(
                    $result,
                    array('transport', 'html-invalidation', 'refill')
                );
            }

            $result = self::sanitize_varnish_result($result);
            set_transient('ultracache_varnish_last_result', $result, DAY_IN_SECONDS);
            if ($record_metrics && method_exists(__CLASS__, 'record_varnish_operation_result')) {
                self::record_varnish_operation_result($result);
            }
            if (method_exists(__CLASS__, 'flush_varnish_metrics_state')) {
                self::flush_varnish_metrics_state();
            }
        }

        private static function get_varnish_last_result()
        {
            $value = get_transient('ultracache_varnish_last_result');
            if (!is_array($value)) {
                return array();
            }

            if (in_array(sanitize_key((string) ($value['testType'] ?? '')), array('basic', 'behavior'), true)
                && method_exists(static::class, 'varnish_capability_contracts_match')
                && !self::varnish_capability_contracts_match(
                    $value,
                    array('transport', 'html-invalidation', 'refill')
                )) {
                return self::mark_varnish_behavior_result_configuration_changed($value);
            }

            return $value;
        }

        private static function get_varnish_support_status()
        {
            $http_available = function_exists('wp_safe_remote_request');
            $admin_available = function_exists('fsockopen');
            $available = $http_available || $admin_available;
            if ($http_available && $admin_available) {
                $message = 'Varnish integration supports both HTTP host:port purge endpoints and admin-secret mode.';
            } elseif ($http_available) {
                $message = 'Varnish integration supports HTTP host:port purge endpoints on this server.';
            } elseif ($admin_available) {
                $message = 'Varnish integration supports admin-secret mode on this server.';
            } else {
                $message = 'Neither the WordPress HTTP API nor socket support is available, so Varnish integration is unavailable.';
            }

            return array(
                'available' => $available,
                'message'   => $message,
            );
        }

        public static function get_varnish_cli_settings()
        {
            $settings = self::get_dashboard_settings();
            $mode = self::sanitize_varnish_mode($settings['varnishCliMode']);
            $servers_raw = self::sanitize_varnish_servers_string($settings['varnishCliServers'], $mode);
            $servers = array_values(array_filter(array_map('trim', preg_split('/\s+/', $servers_raw))));
            $method = ('PURGE' === strtoupper(trim((string) $settings['varnishCliMethod']))) ? 'PURGE' : 'BAN';
            $effective_method = ('admin' === $mode) ? 'admin BAN' : $method;
            $runtime_key = function_exists('ultracache_get_varnish_password')
                ? (string) ultracache_get_varnish_password()
                : '';
            $key = 'admin' === $mode ? $runtime_key : trim($runtime_key);

            return array(
                'enabled'      => !empty($settings['varnishCliEnabled']),
                'mode'         => $mode,
                'configuredMode' => $mode,
                'servers_raw'  => $servers_raw,
                'servers'      => $servers,
                'endpointCount' => count($servers),
                'key'          => $key,
                'secretConfigured' => ('' !== $key),
                'timeout'      => max(1, min(15, absint($settings['varnishCliTimeoutSeconds']))),
                'method'       => $method,
                'invalidationStrategy' => self::sanitize_varnish_invalidation_strategy($settings['varnishInvalidationStrategy'] ?? strtolower($method)),
                'flushScope'   => self::sanitize_varnish_flush_scope($settings['varnishFlushScope'] ?? 'auto'),
                'htmlTtlMinutes' => max(0, min(525600, absint($settings['varnishHtmlTtlMinutes'] ?? 0))),
                'staleWhileRevalidateSeconds' => max(0, min(86400, absint($settings['varnishStaleWhileRevalidateSeconds'] ?? 0))),
                'refillAfterTargetedInvalidation' => !empty($settings['varnishRefillAfterTargetedInvalidation']),
                'warmDuringManualWarmup' => !empty($settings['varnishWarmDuringManualWarmup']),
                'warmWithSiteWarmup' => !empty($settings['varnishWarmDuringManualWarmup']),
                'refreshAheadEnabled' => !empty($settings['varnishRefreshAheadEnabled']),
                'refreshAheadThresholdPercent' => max(50, min(95, absint($settings['varnishRefreshAheadThresholdPercent'] ?? 85))),
                'refreshAheadMaxPages' => max(1, min(10, absint($settings['varnishRefreshAheadMaxPages'] ?? 5))),
                'refreshAheadPinnedUrls' => self::sanitize_varnish_string((string) ($settings['varnishRefreshAheadPinnedUrls'] ?? '')),
                'effectiveMethod' => $effective_method,
                'adminModeUsed' => ('admin' === $mode),
                'httpEndpointModeUsed' => ('http' === $mode),
                'support'      => self::get_varnish_support_status(),
                'last'         => self::get_varnish_last_result(),
                'endpointDiagnostics' => self::get_varnish_endpoint_diagnostics($servers_raw, $mode),
            );
        }
}
