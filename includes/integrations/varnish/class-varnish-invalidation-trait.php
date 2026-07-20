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
            if (empty($settings['varnishCliEnabled'])) {
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
            self::varnish_flush_all_current_host();
        }

        public function handle_varnish_after_purge_urls($urls, $scope = 'batch', $payload = array())
        {
            if (!is_array($urls)) {
                return;
            }

            $queued = self::maybe_queue_varnish_invalidation($urls, (string) $scope);
            if (is_array($queued)) {
                return;
            }

            $result = self::varnish_flush_url_batch($urls, (string) $scope);
            if (!empty($result['success']) && 'lcp-refresh' !== sanitize_key((string) $scope)) {
                $refill_queue = self::queue_varnish_refill_urls($urls, (string) $scope);
                if (!empty($refill_queue['queued'])) {
                    $result['refillQueued'] = true;
                    $result['refillQueuedUrlCount'] = max(0, (int) ($refill_queue['queuedUrlCount'] ?? 0));
                    $result['queue'] = self::get_varnish_queue_stats();
                    self::set_varnish_last_result($result);
                }
            }
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

        private static function varnish_send_expr_to_all($expr, $label = '')
        {
            $settings = self::get_varnish_cli_settings();
            $support = $settings['support'];

            if (empty($support['available'])) {
                $result = array(
                    'success' => false,
                    'message' => (string) $support['message'],
                    'time'    => time(),
                    'label'   => $label,
                );
                self::set_varnish_last_result($result);
                return $result;
            }

            if (empty($settings['enabled'])) {
                $result = array(
                    'success' => false,
                    'message' => self::maybe_translate('Varnish integration is disabled.'),
                    'time'    => time(),
                    'label'   => $label,
                );
                self::set_varnish_last_result($result);
                return $result;
            }

            if (empty($settings['servers'])) {
                $result = array(
                    'success' => false,
                    'message' => self::maybe_translate('No Varnish endpoints are configured.'),
                    'time'    => time(),
                    'label'   => $label,
                );
                self::set_varnish_last_result($result);
                return $result;
            }

            $details = array();
            $all_ok = true;
            foreach ($settings['servers'] as $server) {
                $res = self::varnish_command_for_expr($server, $settings['key'], $settings['timeout'], $expr, $settings['method']);
                $all_ok = $all_ok && !empty($res['ok']);
                $details[] = array(
                    'server'  => $server,
                    'success' => !empty($res['ok']),
                    'detail'  => self::sanitize_varnish_string((string) ($res['detail'] ?? '')),
                );
            }

            $action_label = ('admin' === ($settings['mode'] ?? 'http')) ? 'admin BAN' : $settings['method'];
            $message = $all_ok
                ? self::maybe_translate_sprintf('Varnish %1$s succeeded on %2$d endpoint(s).', $action_label, count($details))
                : self::maybe_translate_sprintf('Varnish %s failed on one or more endpoints.', $action_label);

            $result = array(
                'success' => $all_ok,
                'message' => $message,
                'time'    => time(),
                'mode'    => (string) ($settings['mode'] ?? 'http'),
                'method'  => $settings['method'],
                'effectiveMethod' => $action_label,
                'endpointCount' => count($details),
                'adminModeUsed' => ('admin' === ($settings['mode'] ?? 'http')),
                'httpEndpointModeUsed' => ('http' === ($settings['mode'] ?? 'http')),
                'secretConfigured' => !empty($settings['key']),
                'label'   => $label,
                'operationType' => 'direct-invalidation',
                'requestCount' => count($details),
                'details' => $details,
            );

            self::set_varnish_last_result($result);
            return $result;
        }

        public static function varnish_flush_url($url)
        {
            return self::varnish_flush_url_batch(array($url), 'url');
        }

        private static function varnish_flush_url_hard($url)
        {
            return self::varnish_flush_url_batch(array($url), 'behavior-hard', 'hard');
        }
}
