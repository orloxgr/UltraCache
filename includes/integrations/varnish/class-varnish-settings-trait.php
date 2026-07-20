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
        private static function sanitize_varnish_string($value)
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

        private static function set_varnish_last_result(array $result)
        {
            $result = self::sanitize_varnish_result($result);
            set_transient('ultracache_varnish_last_result', $result, DAY_IN_SECONDS);
            if (method_exists(__CLASS__, 'record_varnish_operation_result')) {
                self::record_varnish_operation_result($result);
            }
            if (method_exists(__CLASS__, 'flush_varnish_metrics_state')) {
                self::flush_varnish_metrics_state();
            }
        }

        private static function get_varnish_last_result()
        {
            $value = get_transient('ultracache_varnish_last_result');
            return is_array($value) ? $value : array();
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

        private static function get_varnish_html_ttl_status(array $settings = array())
        {
            if (empty($settings)) {
                $settings = self::get_dashboard_settings();
            }

            $minutes = max(0, min(525600, absint($settings['varnishHtmlTtlMinutes'] ?? 0)));
            $last = self::get_varnish_last_result();
            $test = is_array($last['sharedTtlTest'] ?? null) ? $last['sharedTtlTest'] : array();
            if ((int) ($test['configuredMinutes'] ?? -1) !== $minutes) {
                $test = array();
            }

            return array(
                'configuredMinutes' => $minutes,
                'configuredSeconds' => $minutes * MINUTE_IN_SECONDS,
                'enabled' => !empty($settings['varnishCliEnabled']) && $minutes > 0,
                'status' => $minutes <= 0 ? 'disabled' : (empty($settings['varnishCliEnabled']) ? 'inactive' : (string) ($test['status'] ?? 'not-tested')),
                'observed' => !empty($test['observed']),
                'age' => isset($test['age']) && null !== $test['age'] ? (int) $test['age'] : null,
                'message' => $minutes <= 0
                    ? __('0 leaves the shared-cache lifetime to the existing Varnish configuration.', 'ultracache')
                    : (empty($settings['varnishCliEnabled'])
                        ? __('The shared HTML TTL is configured but inactive because Varnish integration is disabled.', 'ultracache')
                        : (string) ($test['message'] ?? __('Run Test Varnish to check whether the configured shared HTML TTL is visible on a verified Varnish HIT.', 'ultracache'))),
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
            $key = function_exists('ultracache_get_varnish_password')
                ? trim((string) ultracache_get_varnish_password())
                : '';

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
                'verifyRefillHit' => !empty($settings['varnishVerifyRefillHit']),
                'refreshAheadEnabled' => !empty($settings['varnishRefreshAheadEnabled']),
                'refreshAheadThresholdPercent' => max(50, min(95, absint($settings['varnishRefreshAheadThresholdPercent'] ?? 85))),
                'refreshAheadMaxPages' => max(1, min(10, absint($settings['varnishRefreshAheadMaxPages'] ?? 5))),
                'effectiveMethod' => $effective_method,
                'adminModeUsed' => ('admin' === $mode),
                'httpEndpointModeUsed' => ('http' === $mode),
                'support'      => self::get_varnish_support_status(),
                'last'         => self::get_varnish_last_result(),
                'endpointDiagnostics' => self::get_varnish_endpoint_diagnostics($servers_raw, $mode),
            );
        }
}
