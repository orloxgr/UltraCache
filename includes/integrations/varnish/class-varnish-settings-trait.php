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

        /**
         * Request-local stack of narrowly authorized Varnish capability probes.
         *
         * The general test-run flag suppresses production metrics only. It must
         * never authorize a transport operation. Every behavior probe that needs
         * to exercise an unverified runtime path must push an explicit context
         * describing the operation, strategy, endpoint set, scope and target URLs.
         *
         * @var array<int,array<string,mixed>>
         */
        private static $varnish_capability_probe_stack = array();

        /**
         * Request-local normalized Varnish settings used by isolated diagnostics.
         *
         * This never persists configuration. It lets the existing capability
         * machinery verify a discovered candidate before the normal settings
         * flow saves or enables that candidate.
         *
         * @var array<string,mixed>
         */
        private static $varnish_cli_settings_diagnostic_override = array();

        /**
         * Set one request-local normalized Varnish settings override.
         *
         * @param array<string,mixed> $settings Normalized CLI settings.
         * @return void
         */
        private static function set_varnish_cli_settings_diagnostic_override(array $settings)
        {
            self::$varnish_cli_settings_diagnostic_override = $settings;
        }

        /**
         * Clear the request-local Varnish settings override.
         *
         * @return void
         */
        private static function clear_varnish_cli_settings_diagnostic_override()
        {
            self::$varnish_cli_settings_diagnostic_override = array();
        }

        /**
         * Normalize one capability-probe endpoint set.
         *
         * @param array $endpoints Candidate endpoint labels.
         * @return array<int,string>
         */
        private static function normalize_varnish_capability_probe_endpoints(array $endpoints)
        {
            $normalized = array();
            foreach ($endpoints as $endpoint) {
                $endpoint = method_exists(static::class, 'normalize_varnish_registry_endpoint')
                    ? self::normalize_varnish_registry_endpoint($endpoint)
                    : strtolower(trim((string) $endpoint));
                if ('' !== $endpoint) {
                    $normalized[$endpoint] = true;
                }
            }
            $normalized = array_keys($normalized);
            sort($normalized, SORT_STRING);
            return $normalized;
        }

        /**
         * Normalize one public URL for request-local capability-probe matching.
         *
         * @param mixed $url Candidate URL.
         * @return string
         */
        private static function normalize_varnish_capability_probe_url($url)
        {
            $url = esc_url_raw((string) $url);
            $parts = wp_parse_url($url);
            if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
                return '';
            }

            $scheme = strtolower((string) $parts['scheme']);
            if (!in_array($scheme, array('http', 'https'), true)) {
                return '';
            }
            $host = strtolower(rtrim((string) $parts['host'], '.'));
            if ('' === $host) {
                return '';
            }
            $port = absint($parts['port'] ?? 0);
            $default_port = 'https' === $scheme ? 443 : 80;
            $authority = $host;
            if ($port > 0 && $port !== $default_port) {
                $authority .= ':' . $port;
            }
            $path = isset($parts['path']) && '' !== (string) $parts['path']
                ? (string) $parts['path']
                : '/';
            $query = isset($parts['query']) && '' !== (string) $parts['query']
                ? '?' . (string) $parts['query']
                : '';

            return $scheme . '://' . $authority . $path . $query;
        }

        /**
         * Normalize one capability-probe URL set.
         *
         * @param array $urls Candidate URLs.
         * @return array<int,string>
         */
        private static function normalize_varnish_capability_probe_urls(array $urls)
        {
            $normalized = array();
            foreach ($urls as $url) {
                $url = self::normalize_varnish_capability_probe_url($url);
                if ('' !== $url) {
                    $normalized[$url] = true;
                }
            }
            $normalized = array_keys($normalized);
            sort($normalized, SORT_STRING);
            return $normalized;
        }

        /**
         * Normalize one bounded capability-probe authorization context.
         *
         * @param array $context Candidate context.
         * @return array<string,mixed>
         */
        private static function normalize_varnish_capability_probe_context(array $context)
        {
            $operation = sanitize_key((string) ($context['operation'] ?? ''));
            $strategy = sanitize_key((string) ($context['strategy'] ?? ''));
            $requested_scope = sanitize_key((string) ($context['requestedScope'] ?? ''));
            $allowed_operations = array('targeted', 'site-flush', 'refill', 'connection');
            $allowed_strategies = array(
                'exact-ban',
                'exact-purge',
                'batch-ban',
                'soft-purge',
                'html-flush',
                'host-flush',
                'refill',
                'connection',
            );
            $allowed_scopes = array('exact-url', 'batch', 'html', 'host', 'html-variants', 'connection');
            if (!in_array($operation, $allowed_operations, true)
                || !in_array($strategy, $allowed_strategies, true)
                || !in_array($requested_scope, $allowed_scopes, true)) {
                return array();
            }
            $allowed_contracts = array(
                'targeted' => array(
                    'exact-ban:exact-url',
                    'exact-purge:exact-url',
                    'soft-purge:exact-url',
                    'batch-ban:batch',
                ),
                'site-flush' => array(
                    'html-flush:html',
                    'host-flush:host',
                ),
                'refill' => array('refill:html-variants'),
                'connection' => array('connection:connection'),
            );
            if (!in_array($strategy . ':' . $requested_scope, (array) ($allowed_contracts[$operation] ?? array()), true)) {
                return array();
            }

            $endpoints = self::normalize_varnish_capability_probe_endpoints((array) ($context['endpoints'] ?? array()));
            $urls = self::normalize_varnish_capability_probe_urls((array) ($context['urls'] ?? array()));
            if (empty($endpoints)) {
                return array();
            }
            if (in_array($requested_scope, array('exact-url', 'batch', 'html-variants'), true) && empty($urls)) {
                return array();
            }
            if ('exact-url' === $requested_scope && 1 !== count($urls)) {
                return array();
            }
            if ('batch' === $requested_scope && count($urls) < 2) {
                return array();
            }

            return array(
                'operation' => $operation,
                'strategy' => $strategy,
                'requestedScope' => $requested_scope,
                'endpoints' => $endpoints,
                'endpointFingerprint' => hash('sha256', implode("\n", $endpoints)),
                'urls' => $urls,
                'urlFingerprint' => empty($urls) ? '' : hash('sha256', implode("\n", $urls)),
                'urlCount' => count($urls),
            );
        }

        /**
         * Begin one narrowly scoped capability probe.
         *
         * @param array $context Probe authorization context.
         * @return string Opaque request-local token, or an empty string on failure.
         */
        protected static function begin_varnish_capability_probe(array $context)
        {
            $context = self::normalize_varnish_capability_probe_context($context);
            if (empty($context)) {
                return '';
            }

            $token = hash('sha256', uniqid('ultracache-varnish-probe-', true) . '|' . microtime(true));
            $context['token'] = $token;
            self::$varnish_capability_probe_stack[] = $context;
            return $token;
        }

        /**
         * End the most recently opened capability probe.
         *
         * @param string $token Token returned by begin_varnish_capability_probe().
         * @return bool
         */
        protected static function end_varnish_capability_probe($token)
        {
            $token = (string) $token;
            if ('' === $token || empty(self::$varnish_capability_probe_stack)) {
                return false;
            }

            $index = count(self::$varnish_capability_probe_stack) - 1;
            $active = self::$varnish_capability_probe_stack[$index];
            if (!is_array($active) || !hash_equals((string) ($active['token'] ?? ''), $token)) {
                return false;
            }

            array_pop(self::$varnish_capability_probe_stack);
            return true;
        }

        /**
         * Return the active request-local capability-probe context.
         *
         * @return array<string,mixed>
         */
        private static function get_active_varnish_capability_probe()
        {
            if (empty(self::$varnish_capability_probe_stack)) {
                return array();
            }
            $active = self::$varnish_capability_probe_stack[count(self::$varnish_capability_probe_stack) - 1];
            return is_array($active) ? $active : array();
        }

        /**
         * Whether the active probe authorizes exactly one requested operation.
         *
         * @param string $operation Requested operation.
         * @param array  $context   Requested strategy, scope, endpoints and URLs.
         * @return bool
         */
        private static function is_varnish_capability_probe_authorized($operation, array $context = array())
        {
            $active = self::get_active_varnish_capability_probe();
            if (empty($active)) {
                return false;
            }

            $request = self::normalize_varnish_capability_probe_context(array(
                'operation' => $operation,
                'strategy' => $context['strategy'] ?? '',
                'requestedScope' => $context['requestedScope'] ?? '',
                'endpoints' => (array) ($context['endpoints'] ?? array()),
                'urls' => (array) ($context['urls'] ?? array()),
            ));
            if (empty($request)) {
                return false;
            }

            return hash_equals((string) ($active['operation'] ?? ''), (string) $request['operation'])
                && hash_equals((string) ($active['strategy'] ?? ''), (string) $request['strategy'])
                && hash_equals((string) ($active['requestedScope'] ?? ''), (string) $request['requestedScope'])
                && hash_equals((string) ($active['endpointFingerprint'] ?? ''), (string) $request['endpointFingerprint'])
                && hash_equals((string) ($active['urlFingerprint'] ?? ''), (string) $request['urlFingerprint']);
        }

        /**
         * Whether a low-level diagnostic transport matches the active probe.
         *
         * URL-bearing probes are authorized earlier by the production planner or
         * diagnostic helper. The transport layer rechecks operation, strategy,
         * scope and endpoint identity and also requires the active context to own
         * the expected number of bounded target URLs.
         *
         * @param string $operation       Requested operation.
         * @param string $strategy        Requested transport strategy.
         * @param string $requested_scope Requested capability scope.
         * @param array  $endpoints       Endpoint labels used by the transport.
         * @return bool
         */
        private static function is_varnish_capability_probe_transport_authorized($operation, $strategy, $requested_scope, array $endpoints)
        {
            $active = self::get_active_varnish_capability_probe();
            $operation = sanitize_key((string) $operation);
            $strategy = sanitize_key((string) $strategy);
            $requested_scope = sanitize_key((string) $requested_scope);
            $endpoints = self::normalize_varnish_capability_probe_endpoints($endpoints);
            if (empty($active) || empty($endpoints)) {
                return false;
            }

            $minimum_url_count = 'exact-url' === $requested_scope ? 1 : ('batch' === $requested_scope ? 2 : 0);
            return hash_equals((string) ($active['operation'] ?? ''), $operation)
                && hash_equals((string) ($active['strategy'] ?? ''), $strategy)
                && hash_equals((string) ($active['requestedScope'] ?? ''), $requested_scope)
                && hash_equals(
                    (string) ($active['endpointFingerprint'] ?? ''),
                    hash('sha256', implode("\n", $endpoints))
                )
                && absint($active['urlCount'] ?? 0) >= $minimum_url_count;
        }

        /**
         * Whether an active probe may temporarily mark its exact endpoint set as
         * configured while the isolated operation is being assembled.
         *
         * @param array $endpoints Configured endpoint labels.
         * @return bool
         */
        private static function is_varnish_capability_probe_connection_authorized(array $endpoints)
        {
            $active = self::get_active_varnish_capability_probe();
            $endpoints = self::normalize_varnish_capability_probe_endpoints($endpoints);
            if (empty($active) || empty($endpoints)) {
                return false;
            }

            return hash_equals(
                (string) ($active['endpointFingerprint'] ?? ''),
                hash('sha256', implode("\n", $endpoints))
            );
        }

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

        /**
         * Return the automatic Varnish connection state.
         *
         * Legacy enable values are retained only as migration markers.
         * Runtime behavior is derived from the saved connection marker, explicit
         * connection fields, or an active isolated capability probe.
         *
         * @param array $settings Optional dashboard settings.
         * @return array<string,mixed>
         */
        private static function get_varnish_connection_configuration_status(array $settings = array())
        {
            if (empty($settings)) {
                $settings = self::get_dashboard_settings();
            }

            $mode = self::sanitize_varnish_mode($settings['varnishCliMode'] ?? 'http');
            $servers_raw = self::sanitize_varnish_servers_string($settings['varnishCliServers'] ?? '', $mode);
            $servers = array_values(array_filter(array_map('trim', preg_split('/\s+/', $servers_raw))));
            $default_endpoint = 'admin' === $mode
                ? self::get_default_varnish_admin_endpoint()
                : self::get_default_varnish_http_endpoint();
            $normalized_servers = implode(' ', $servers);
            $normalize_boolean = static function ($value) {
                if (is_bool($value)) {
                    return $value;
                }
                if (is_int($value) || is_float($value)) {
                    return 1 === (int) $value;
                }
                return in_array(strtolower(trim((string) $value)), array('1', 'true', 'yes', 'on'), true);
            };
            $saved_marker = $normalize_boolean($settings['varnishConnectionConfigured'] ?? false);
            $legacy_marker = $normalize_boolean($settings['sharedCacheDeliveryEnabled'] ?? false);
            $custom_connection = '' !== $normalized_servers
                && $normalized_servers !== $default_endpoint;
            $test_probe = !empty($servers)
                && self::is_varnish_capability_probe_connection_authorized($servers);
            $configured = $saved_marker || $legacy_marker || $custom_connection || $test_probe;

            if ($saved_marker) {
                $source = 'saved-connection';
            } elseif ($legacy_marker) {
                $source = 'legacy-connection';
            } elseif ($custom_connection) {
                $source = 'connection-fields';
            } elseif ($test_probe) {
                $source = 'capability-probe';
            } else {
                $source = 'not-configured';
            }

            return array(
                'configured' => $configured,
                'source' => $source,
                'mode' => $mode,
                'serversRaw' => $servers_raw,
                'servers' => $servers,
                'endpointCount' => count($servers),
                'savedMarker' => $saved_marker,
                'legacyMarker' => $legacy_marker,
                'customConnection' => $custom_connection,
                'testProbe' => $test_probe,
            );
        }

        /**
         * Whether the Varnish connection is automatically active.
         *
         * @param array $settings Optional dashboard settings.
         * @return bool
         */
        public static function is_varnish_connection_configured(array $settings = array())
        {
            $status = self::get_varnish_connection_configuration_status($settings);
            return !empty($status['configured']);
        }

        /**
         * Whether the administrator has enabled the Varnish integration.
         *
         * The enable switch is intentionally independent from connection
         * completeness so the accordion can be opened and configured before
         * a working endpoint has been saved.
         *
         * @param array $settings Optional dashboard settings.
         * @return bool
         */
        public static function is_varnish_enabled(array $settings = array())
        {
            if (empty($settings)) {
                $settings = self::get_dashboard_settings();
            }

            return !empty($settings['varnishCliEnabled']);
        }

        /**
         * Whether Varnish runtime actions may execute.
         *
         * @param array $settings Optional dashboard settings.
         * @return bool
         */
        public static function is_varnish_runtime_enabled(array $settings = array())
        {
            if (empty($settings)) {
                $settings = self::get_dashboard_settings();
            }

            return self::is_varnish_enabled($settings)
                && self::is_varnish_connection_configured($settings);
        }
        protected static function sanitize_varnish_string($value)
        {
            $value = (string) $value;
            if (function_exists('wp_check_invalid_utf8')) {
                $value = wp_check_invalid_utf8($value, true);
            }
            if (function_exists('ultracache_redact_sensitive_string')) {
                $value = ultracache_redact_sensitive_string($value);
            }
            if (function_exists('wp_check_invalid_utf8')) {
                $value = wp_check_invalid_utf8($value, true);
            }

            return $value;
        }

        private static function sanitize_varnish_result_value($value)
        {
            if (is_array($value)) {
                $clean = array();
                foreach ($value as $key => $child) {
                    $clean_key = is_string($key) ? self::sanitize_varnish_string($key) : $key;
                    $clean[$clean_key] = self::sanitize_varnish_result_value($child);
                }
                return $clean;
            }

            if (is_string($value)) {
                return self::sanitize_varnish_string($value);
            }

            if (is_wp_error($value)) {
                return array(
                    'error' => true,
                    'message' => self::sanitize_varnish_string($value->get_error_message()),
                );
            }

            if (is_object($value)) {
                return array(
                    'object' => self::sanitize_varnish_string(get_class($value)),
                );
            }

            if (is_resource($value)) {
                return null;
            }

            if (is_float($value) && !is_finite($value)) {
                return 0.0;
            }

            return $value;
        }

        private static function sanitize_varnish_result(array $result)
        {
            return self::sanitize_varnish_result_value($result);
        }

        /**
         * Return the authoritative persistent state name for the latest Varnish
         * production operation result.
         *
         * @return string
         */
        private static function get_varnish_last_result_state_name()
        {
            return 'ultracache_state:varnish.last_operation';
        }


        /**
         * Commit one sanitized Varnish operation result to persistent state.
         *
         * @param array $result Sanitized operation result.
         * @return bool
         */
        private static function persist_varnish_last_result_state(array $result)
        {
            if (!function_exists('ultracache_mutate_state_record')) {
                return false;
            }

            $recorded_at = max(0, (int) ($result['time'] ?? 0));
            if ($recorded_at <= 0) {
                $recorded_at = time();
            }

            if (empty($result['time'])) {
                $result['time'] = $recorded_at;
            }

            $state_payload = array(
                'schemaVersion' => 1,
                'recordedAt' => $recorded_at,
                'result' => $result,
            );
            $mutation = ultracache_mutate_state_record(
                self::get_varnish_last_result_state_name(),
                static function () use ($state_payload) {
                    return $state_payload;
                },
                5,
                array()
            );

            if (empty($mutation['success']) && function_exists('ultracache_debug_log')) {
                ultracache_debug_log(
                    'Unable to persist latest Varnish operation state',
                    array('reason' => sanitize_key((string) ($mutation['reason'] ?? 'unknown')))
                );
            }

            return !empty($mutation['success']);
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
            self::persist_varnish_last_result_state($result);
            if ($record_metrics && method_exists(__CLASS__, 'record_varnish_operation_result')) {
                self::record_varnish_operation_result($result);
            }
            if (method_exists(__CLASS__, 'flush_varnish_metrics_state')) {
                self::flush_varnish_metrics_state();
            }
        }

        /**
         * Attach the completed dashboard action identity to the same persistent
         * Varnish operation result used by diagnostics and WP-CLI.
         *
         * @param array  $result Operation result.
         * @param string $job_id Durable dashboard action-job identifier.
         * @param array  $job    Completed action-job metadata.
         * @return bool
         */
        public static function persist_varnish_action_job_result(array $result, $job_id, array $job = array())
        {
            $job_id = sanitize_text_field((string) $job_id);
            if ('' === $job_id) {
                return false;
            }

            $result['actionJobId'] = $job_id;
            $result['actionJobStatus'] = sanitize_key((string) ($job['status'] ?? ''));
            $result['actionJobCreatedAt'] = max(0, (int) ($job['createdAt'] ?? 0));
            $result['actionJobStartedAt'] = max(0, (int) ($job['startedAt'] ?? 0));
            $result['actionJobFinishedAt'] = max(0, (int) ($job['finishedAt'] ?? 0));
            $result = self::sanitize_varnish_result($result);

            return self::persist_varnish_last_result_state($result);
        }

        private static function get_varnish_last_result()
        {
            if (!function_exists('ultracache_get_state_record_read_only')) {
                return array();
            }

            $record = ultracache_get_state_record_read_only(self::get_varnish_last_result_state_name());
            $payload = is_array($record['payload'] ?? null) ? $record['payload'] : array();
            $value = is_array($payload['result'] ?? null) ? $payload['result'] : array();
            if (empty($value)) {
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

        /**
         * Return the latest persistent Varnish production operation result.
         *
         * @return array<string,mixed>
         */
        public static function get_varnish_last_operation_result()
        {
            return self::get_varnish_last_result();
        }

        /**
         * Build the Varnish runtime policy from the central Automation & Scheduling settings.
         *
         * Varnish-specific UI settings do not own TTL, refill, warm-up, or refresh cadence.
         * Those decisions follow the existing page-cache lifetime and warm-up pipeline.
         *
         * @param array $settings Optional dashboard settings.
         * @return array<string,mixed>
         */
        private static function get_varnish_automation_policy(array $settings = array())
        {
            if (empty($settings)) {
                $settings = self::get_dashboard_settings();
            }

            $fresh_ttl_minutes = max(1, min(525600, absint($settings['cacheFreshTtlMinutes'] ?? 1440)));
            $max_stale_minutes = max(
                $fresh_ttl_minutes,
                min(525600, absint($settings['cacheMaxStaleMinutes'] ?? $fresh_ttl_minutes))
            );
            $ttl_only_cap_minutes = max(
                1,
                min(1440, (int) apply_filters('ultracache_varnish_ttl_only_cap_minutes', 10, $settings))
            );
            $ttl_only_minutes = min($fresh_ttl_minutes, $ttl_only_cap_minutes);
            $stale_seconds = !empty($settings['staleWhileRevalidateEnabled'])
                ? min(86400, max(0, ($max_stale_minutes - $fresh_ttl_minutes) * MINUTE_IN_SECONDS))
                : 0;
            $pages_per_minute = max(0, min(600, absint($settings['cronWarmPagesPerMinute'] ?? 0)));
            $scheduled_warm_enabled = $pages_per_minute > 0;
            $refresh_threshold = max(50, min(95, (int) apply_filters(
                'ultracache_varnish_refresh_ahead_threshold_percent',
                85,
                $settings
            )));
            $refresh_max_pages = max(1, min(10, (int) apply_filters(
                'ultracache_varnish_refresh_ahead_max_pages',
                $pages_per_minute > 0 ? $pages_per_minute : 5,
                $settings
            )));

            return array(
                'freshTtlMinutes' => $fresh_ttl_minutes,
                'maxStaleMinutes' => $max_stale_minutes,
                'ttlOnlyMinutes' => $ttl_only_minutes,
                'staleWhileRevalidateSeconds' => $stale_seconds,
                'refillAfterTargetedInvalidation' => true,
                'warmWithSiteWarmup' => true,
                'scheduledWarmEnabled' => $scheduled_warm_enabled,
                'refreshAheadEnabled' => $scheduled_warm_enabled && $stale_seconds > 0,
                'refreshAheadThresholdPercent' => $refresh_threshold,
                'refreshAheadMaxPages' => $refresh_max_pages,
                'refreshAheadPinnedUrls' => '',
                'pagesPerMinute' => $pages_per_minute,
                'scheduledWarmLimit' => max(1, min(5000, absint($settings['scheduledWarmLimit'] ?? 9))),
            );
        }

        /**
         * Return the effective standard shared-cache delivery mode.
         *
         * Shared-cache response headers are independent from Varnish control.
         * The normal page-cache TTL requires verified exact URL and site-wide
         * invalidation coverage; otherwise UltraCache uses the shorter
         * expiry-only TTL.
         *
         * @param array $settings Optional dashboard settings.
         * @return array
         */
        public static function get_shared_cache_delivery_status(array $settings = array())
        {
            if (empty($settings)) {
                $settings = self::get_dashboard_settings();
            }

            $connection = self::get_varnish_connection_configuration_status($settings);
            $enabled = self::is_varnish_enabled($settings) && !empty($connection['configured']);
            $automation = self::get_varnish_automation_policy($settings);
            $managed_ttl_minutes = (int) $automation['freshTtlMinutes'];
            $ttl_only_minutes = (int) $automation['ttlOnlyMinutes'];
            $mode = self::sanitize_varnish_mode($settings['varnishCliMode'] ?? 'http');
            $servers_raw = self::sanitize_varnish_servers_string($settings['varnishCliServers'] ?? '', $mode);
            $servers = array_values(array_filter(array_map('trim', preg_split('/\s+/', $servers_raw))));
            $runtime_key = function_exists('ultracache_get_varnish_password')
                ? trim((string) ultracache_get_varnish_password())
                : '';
            $support = self::get_varnish_support_status();
            $control_settings = array(
                'enabled' => $enabled,
                'mode' => $mode,
                'method' => ('PURGE' === strtoupper((string) ($settings['varnishCliMethod'] ?? 'BAN'))) ? 'PURGE' : 'BAN',
                'servers' => $servers,
                'secretConfigured' => '' !== $runtime_key,
                'support' => $support,
            );
            $exact_capability = method_exists(static::class, 'get_varnish_exact_invalidation_capability')
                ? self::get_varnish_exact_invalidation_capability($control_settings)
                : array('verified' => false, 'status' => 'unavailable', 'message' => '');
            $exact_control_verified = !empty($exact_capability['verified']);
            $method_capability = method_exists(static::class, 'get_varnish_flush_method_capabilities')
                ? self::get_varnish_flush_method_capabilities($control_settings)
                : array();
            $topology = method_exists(static::class, 'get_varnish_html_flush_capability')
                ? self::get_varnish_html_flush_capability()
                : array();
            $site_wide_control_verified = !empty($method_capability['htmlInvalidationSupported'])
                || !empty($method_capability['hostInvalidationSupported']);
            $control_proof_expires_at = 0;
            $managed_control_verified = $exact_control_verified
                && $site_wide_control_verified;
            $delivery_mode = !$enabled ? 'disabled' : ($managed_control_verified ? 'managed' : 'ttl-only');
            $ttl_minutes = !$enabled ? 0 : ($managed_control_verified ? $managed_ttl_minutes : $ttl_only_minutes);
            $control_status = $managed_control_verified
                ? 'managed-verified'
                : ($exact_control_verified ? 'exact-only' : sanitize_key((string) ($exact_capability['status'] ?? 'not-verified')));
            $control_message = $managed_control_verified
                ? self::maybe_translate('Exact URL and site-wide invalidation are available for the configured Varnish control transport.')
                : ($exact_control_verified
                    ? self::maybe_translate('Exact URL invalidation is verified, but no site-wide invalidation scope is verified. Shared-cache delivery therefore remains on the shorter TTL.')
                    : self::sanitize_varnish_string((string) ($exact_capability['message'] ?? '')));

            return array(
                'enabled' => $enabled,
                'mode' => $delivery_mode,
                'controlConfigured' => !empty($connection['configured']),
                'connectionSource' => sanitize_key((string) ($connection['source'] ?? 'not-configured')),
                'controlVerified' => $managed_control_verified,
                'exactControlVerified' => $exact_control_verified,
                'siteWideControlVerified' => $site_wide_control_verified,
                'controlProofExpiresAt' => $managed_control_verified ? $control_proof_expires_at : 0,
                'controlStatus' => $control_status,
                'controlMessage' => self::sanitize_varnish_string($control_message),
                'ttlMinutes' => $ttl_minutes,
                'ttlSeconds' => $ttl_minutes * MINUTE_IN_SECONDS,
                'managedTtlMinutes' => $managed_ttl_minutes,
                'ttlOnlyMinutes' => $ttl_only_minutes,
                'message' => !$enabled
                    ? self::maybe_translate('Standard shared-cache delivery is disabled.')
                    : ($managed_control_verified
                        ? self::maybe_translate_sprintf('Managed shared-cache delivery is active with a %d minute TTL because exact URL and site-wide invalidation are available.', $managed_ttl_minutes)
                        : self::maybe_translate_sprintf('Shared-cache delivery is active in TTL-expiry-only mode with a %d minute TTL.', $ttl_only_minutes)),
            );
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
            if (!empty(self::$varnish_cli_settings_diagnostic_override)) {
                return self::$varnish_cli_settings_diagnostic_override;
            }

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

            $automation = self::get_varnish_automation_policy($settings);
            $connection = self::get_varnish_connection_configuration_status($settings);

            return array(
                'enabled'      => self::is_varnish_enabled($settings) && !empty($connection['configured']),
                'connectionConfigured' => !empty($connection['configured']),
                'connectionSource' => sanitize_key((string) ($connection['source'] ?? 'not-configured')),
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
                'htmlTtlMinutes' => (int) $automation['freshTtlMinutes'],
                'staleWhileRevalidateSeconds' => (int) $automation['staleWhileRevalidateSeconds'],
                'refillAfterTargetedInvalidation' => !empty($automation['refillAfterTargetedInvalidation']),
                'warmDuringManualWarmup' => !empty($automation['warmWithSiteWarmup']),
                'warmWithSiteWarmup' => !empty($automation['warmWithSiteWarmup']),
                'refreshAheadEnabled' => !empty($automation['refreshAheadEnabled']),
                'refreshAheadThresholdPercent' => (int) $automation['refreshAheadThresholdPercent'],
                'refreshAheadMaxPages' => (int) $automation['refreshAheadMaxPages'],
                'refreshAheadPinnedUrls' => '',
                'automationPolicy' => $automation,
                'effectiveMethod' => $effective_method,
                'adminModeUsed' => ('admin' === $mode),
                'httpEndpointModeUsed' => ('http' === $mode),
                'support'      => self::get_varnish_support_status(),
                'last'         => self::get_varnish_last_result(),
                'endpointDiagnostics' => self::get_varnish_endpoint_diagnostics($servers_raw, $mode),
            );
        }
}
