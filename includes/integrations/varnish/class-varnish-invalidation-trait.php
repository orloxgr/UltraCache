<?php
/**
 * Varnish invalidation orchestration for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Invalidation_Trait
{
        public function handle_varnish_after_purge_all($payload = array())
        {
            $settings = self::get_dashboard_settings();
            if (!self::is_varnish_runtime_enabled($settings)) {
                return;
            }
            if (empty($settings['flushAllIncludeVarnish'])) {
                self::set_varnish_last_result(array(
                    'success' => false,
                    'skipped' => true,
                    'message' => self::maybe_translate('Varnish integration is enabled, but Flush All Include Varnish is OFF. Reverse-proxy cache was not purged.'),
                    'time'    => time(),
                    'scope'   => 'all',
                ));
                return;
            }
            $result = self::varnish_flush_all_current_host();
            if (empty($result['success'])) {
                return;
            }

            $site_warm_requested = method_exists(static::class, 'should_include_varnish_in_site_warmup')
                && self::should_include_varnish_in_site_warmup();
            $warm_after_flush_enabled = !empty($settings['cronWarmStartAfterManualPurge'])
                && absint($settings['cronWarmPagesPerMinute'] ?? 0) > 0;

            $result['operationType'] = 'site-wide-flush';
            $result['refillQueued'] = false;
            $result['refillQueuedUrlCount'] = 0;
            $result['refillMode'] = $site_warm_requested && $warm_after_flush_enabled
                ? 'site-warm-pipeline'
                : 'none';
            $result['queue'] = self::get_varnish_queue_stats();
            if ('site-warm-pipeline' === $result['refillMode']) {
                $result['message'] .= ' ' . self::maybe_translate('Varnish will be warmed per page by the configured warm-after-flush site pipeline.');
            }
            self::set_varnish_last_result($result, false);
        }

        public function handle_varnish_after_purge_urls($urls, $scope = 'batch', $payload = array())
        {
            unset($payload);
            if (!is_array($urls)) {
                return;
            }

            $queue_decision = self::maybe_queue_varnish_invalidation($urls, (string) $scope);
            $queue_mode = sanitize_key((string) ($queue_decision['mode'] ?? 'failed'));
            if ('queued' === $queue_mode) {
                return;
            }

            $direct_urls = 'direct' === $queue_mode
                ? (array) ($queue_decision['directUrls'] ?? array())
                : (array) ($queue_decision['fallbackDirectUrls'] ?? array());
            if (empty($direct_urls)) {
                $terminal_result = (array) ($queue_decision['result'] ?? array());
                if (!empty($terminal_result)) {
                    self::set_varnish_last_result($terminal_result);
                }
                return;
            }

            $result = self::varnish_flush_url_batch($direct_urls, (string) $scope);
            $direct_invalidation_succeeded = !empty($result['success']);
            $result = self::apply_varnish_queue_decision_to_direct_result($result, $queue_decision);
            if ($direct_invalidation_succeeded) {
                $requires_verified_origin = 'soft' === sanitize_key((string) ($result['invalidationStrategy'] ?? ''));
                $pipeline_queue = 'lcp-refresh' !== sanitize_key((string) $scope)
                    && self::should_refill_after_targeted_varnish_invalidation()
                    && method_exists(static::class, 'enqueue_targeted_warm_pipeline_urls')
                    ? self::enqueue_targeted_warm_pipeline_urls($direct_urls, $requires_verified_origin, (string) $scope)
                    : array('success' => true, 'queued' => false, 'queuedUrlCount' => 0);
                $result['refillQueued'] = !empty($pipeline_queue['queued']);
                $result['refillQueuedUrlCount'] = max(0, (int) ($pipeline_queue['queuedUrlCount'] ?? 0));
                $result['refillMode'] = !empty($pipeline_queue['queued']) ? 'shared-page-warm-pipeline' : 'none';
                $result['strictOriginRequired'] = $requires_verified_origin;
                $result['queue'] = self::get_varnish_queue_stats();
                if (!empty($pipeline_queue['message'])) {
                    $result['message'] .= ' ' . (string) $pipeline_queue['message'];
                }
            }

            self::set_varnish_last_result($result, false);
        }

        private static function escape_varnish_vcl_string($value)
        {
            $value = (string) $value;
            $value = str_replace(array("\\", '"', "\r", "\n"), array('\\\\', '\"', '', ''), $value);

            return $value;
        }

        private static function build_varnish_ban_expression($host, $path = '', $all = false)
        {
            $host = self::escape_varnish_vcl_string($host);
            if ('' === $host) {
                return '';
            }

            if ($all) {
                return 'req.http.host == "' . $host . '" && req.url ~ ".*"';
            }

            $path = (string) $path;
            if ('' === $path) {
                $path = '/';
            }
            if ('/' !== $path[0]) {
                $path = '/' . $path;
            }

            $quoted = preg_quote($path, '/');
            $quoted = self::escape_varnish_vcl_string($quoted);

            return 'req.http.host == "' . $host . '" && req.url ~ "^' . $quoted . '$"';
        }

        private static function varnish_send_expr_to_all($expr, $label = '', array $endpoint_targets = array(), $diagnostic_probe = false, $method_override = '')
        {
            $settings = self::get_varnish_cli_settings();
            $support = $settings['support'];

            if (empty($support['available'])) {
                $result = array(
                    'success' => false,
                    'partial' => false,
                    'message' => (string) $support['message'],
                    'time'    => time(),
                    'label'   => $label,
                    'operationType' => 'direct-invalidation',
                    'configuredEndpointCount' => count((array) ($settings['servers'] ?? array())),
                    'endpointCount' => 0,
                    'successfulEndpointRequestCount' => 0,
                    'failedEndpointRequestCount' => 0,
                    'successfulEndpointTargets' => array(),
                    'failedEndpointTargets' => array(),
                    'attemptedEndpointTargets' => array(),
                    'details' => array(),
                );
                self::set_varnish_last_result($result);
                return $result;
            }

            if (empty($settings['enabled'])) {
                $result = array(
                    'success' => false,
                    'partial' => false,
                    'message' => self::maybe_translate('Varnish integration is disabled.'),
                    'time'    => time(),
                    'label'   => $label,
                    'operationType' => 'direct-invalidation',
                    'configuredEndpointCount' => count((array) ($settings['servers'] ?? array())),
                    'endpointCount' => 0,
                    'successfulEndpointRequestCount' => 0,
                    'failedEndpointRequestCount' => 0,
                    'successfulEndpointTargets' => array(),
                    'failedEndpointTargets' => array(),
                    'attemptedEndpointTargets' => array(),
                    'details' => array(),
                );
                self::set_varnish_last_result($result);
                return $result;
            }

            if (empty($settings['servers'])) {
                $result = array(
                    'success' => false,
                    'partial' => false,
                    'message' => self::maybe_translate('No Varnish endpoints are configured.'),
                    'time'    => time(),
                    'label'   => $label,
                    'operationType' => 'direct-invalidation',
                    'configuredEndpointCount' => 0,
                    'endpointCount' => 0,
                    'successfulEndpointRequestCount' => 0,
                    'failedEndpointRequestCount' => 0,
                    'successfulEndpointTargets' => array(),
                    'failedEndpointTargets' => array(),
                    'attemptedEndpointTargets' => array(),
                    'details' => array(),
                );
                self::set_varnish_last_result($result);
                return $result;
            }

            $configured_targets = array_values(array_filter(array_map('strval', (array) $settings['servers'])));
            $targets = empty($endpoint_targets)
                ? $configured_targets
                : self::resolve_varnish_invalidation_targets($configured_targets, $endpoint_targets);
            $requested_targets = array_values(array_unique(array_filter(array_map('strval', $endpoint_targets))));
            $missing_targets = empty($endpoint_targets)
                ? array()
                : array_values(array_diff($requested_targets, $targets));
            if (empty($targets) || !empty($missing_targets)) {
                $result = array(
                    'success' => false,
                    'partial' => false,
                    'skipped' => true,
                    'message' => self::maybe_translate('The requested Varnish endpoint set is not available in the current configuration.'),
                    'time' => time(),
                    'label' => $label,
                    'operationType' => 'direct-invalidation',
                    'configuredEndpointCount' => count($configured_targets),
                    'endpointCount' => 0,
                    'successfulEndpointRequestCount' => 0,
                    'failedEndpointRequestCount' => 0,
                    'successfulEndpointTargets' => array(),
                    'failedEndpointTargets' => !empty($missing_targets) ? $missing_targets : $requested_targets,
                    'attemptedEndpointTargets' => array(),
                    'details' => array(),
                );
                self::set_varnish_last_result($result);
                return $result;
            }

            $mode = self::sanitize_varnish_mode($settings['mode'] ?? 'http');
            $method_override = strtoupper(sanitize_key((string) $method_override));
            $effective_method = 'admin' === $mode
                ? 'BAN'
                : (in_array($method_override, array('PURGE', 'BAN'), true)
                    ? $method_override
                    : (('PURGE' === strtoupper((string) ($settings['method'] ?? 'BAN'))) ? 'PURGE' : 'BAN'));

            $details = array();
            $successful_targets = array();
            $failed_targets = array();
            foreach ($targets as $server) {
                $res = self::varnish_command_for_expr(
                    $server,
                    $settings['key'],
                    $settings['timeout'],
                    $expr,
                    $effective_method,
                    (bool) $diagnostic_probe && 'http' === $mode
                );
                $success = !empty($res['ok']);
                if ($success) {
                    $successful_targets[] = (string) $server;
                } else {
                    $failed_targets[] = (string) $server;
                }
                $details[] = array(
                    'server'  => $server,
                    'success' => $success,
                    'detail'  => self::sanitize_varnish_string((string) ($res['detail'] ?? '')),
                    'code'    => absint($res['code'] ?? 0),
                );
            }

            $endpoint_count = count($details);
            $successful_count = count($successful_targets);
            $failed_count = count($failed_targets);
            $all_ok = $endpoint_count > 0 && 0 === $failed_count;
            $partial = $successful_count > 0 && $failed_count > 0;
            $action_label = 'admin' === $mode ? 'admin BAN' : $effective_method;

            if ($all_ok) {
                $message = self::maybe_translate_sprintf('Varnish %1$s succeeded on %2$d endpoint(s).', $action_label, $endpoint_count);
            } elseif ($partial) {
                $message = self::maybe_translate_sprintf(
                    'Varnish %1$s succeeded on %2$d endpoint(s) and failed on %3$d endpoint(s).',
                    $action_label,
                    $successful_count,
                    $failed_count
                );
            } else {
                $message = self::maybe_translate_sprintf('Varnish %s failed on every configured endpoint.', $action_label);
            }

            $result = array(
                'success' => $all_ok,
                'partial' => $partial,
                'message' => $message,
                'time'    => time(),
                'mode'    => (string) ($settings['mode'] ?? 'http'),
                'method'  => $effective_method,
                'effectiveMethod' => $action_label,
                'endpointCount' => $endpoint_count,
                'configuredEndpointCount' => count((array) $settings['servers']),
                'successfulEndpointRequestCount' => $successful_count,
                'failedEndpointRequestCount' => $failed_count,
                'successfulEndpointTargets' => array_values($successful_targets),
                'failedEndpointTargets' => array_values($failed_targets),
                'attemptedEndpointTargets' => array_values($targets),
                'adminModeUsed' => ('admin' === ($settings['mode'] ?? 'http')),
                'httpEndpointModeUsed' => ('http' === ($settings['mode'] ?? 'http')),
                'secretConfigured' => !empty($settings['key']),
                'label'   => $label,
                'operationType' => 'direct-invalidation',
                'requestCount' => $endpoint_count,
                'details' => $details,
            );

            self::set_varnish_last_result($result);
            return $result;
        }

        public static function varnish_flush_url($url)
        {
            return self::varnish_flush_url_batch(array($url), 'url');
        }

        protected static function varnish_flush_url_hard($url)
        {
            return self::varnish_flush_url_batch(array($url), 'behavior-hard', 'hard');
        }

        /**
         * Invalidate one canonical page after its disk HTML/CSS warm stages and
         * immediately before Varnish refill. The hard strategy is intentional:
         * a warm must not reuse a still-fresh object that predates the new disk HTML.
         *
         * @param string $url     Canonical local page URL.
         * @param string $context Warm source label.
         * @return array
         */
        protected static function varnish_invalidate_url_before_warm_refill($url, $context = 'site-warm')
        {
            $context = sanitize_key((string) $context);
            if ('' === $context) {
                $context = 'site-warm';
            }

            return self::varnish_flush_url_batch(
                array($url),
                substr($context . '-warm-refill', 0, 64),
                'hard'
            );
        }
}
