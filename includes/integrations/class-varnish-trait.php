<?php
/**
 * Varnish and reverse-proxy integration helpers for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Trait
{
        public function handle_varnish_after_purge_all($payload = array())
        {
            $settings = self::get_dashboard_settings();
            if (empty($settings['flushAllIncludeVarnish'])) {
                return;
            }
            self::varnish_flush_all_current_host();
        }

        public function handle_varnish_after_purge_urls($urls, $scope = 'batch', $payload = array())
        {
            if (!is_array($urls)) {
                return;
            }

            foreach ($urls as $url) {
                self::varnish_flush_url($url);
            }
        }

        private static function sanitize_varnish_string($value)
        {
            $value = (string) $value;
            if (function_exists('ucwp_redact_sensitive_string')) {
                $value = ucwp_redact_sensitive_string($value);
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

        private static function set_varnish_last_result(array $result)
        {
            set_transient('ultracache_varnish_last_result', self::sanitize_varnish_result($result), DAY_IN_SECONDS);
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

        public static function get_varnish_cli_settings()
        {
            $settings = self::get_dashboard_settings();
            $mode = self::sanitize_varnish_mode($settings['varnishCliMode']);
            $servers_raw = self::sanitize_varnish_servers_string($settings['varnishCliServers'], $mode);
            $servers = array_values(array_filter(array_map('trim', preg_split('/\s+/', $servers_raw))));
            $method = ('PURGE' === strtoupper(trim((string) $settings['varnishCliMethod']))) ? 'PURGE' : 'BAN';
            $effective_method = ('admin' === $mode) ? 'admin BAN' : $method;
            $key = trim((string) $settings['varnishCliKey']);
            if (method_exists(__CLASS__, 'get_runtime_varnish_admin_secret')) {
                $runtime_key = trim((string) self::get_runtime_varnish_admin_secret());
                if ('' !== $runtime_key) {
                    $key = $runtime_key;
                }
            }

            return array(
                'enabled'      => !empty($settings['varnishCliEnabled']),
                'mode'         => $mode,
                'configuredMode' => $mode,
                'servers_raw'  => $servers_raw,
                'servers'      => $servers,
                'endpointCount' => count($servers),
                'key'          => $key,
                'secretConfigured' => ('' !== $key),
                'timeout'      => max(1, min(30, absint($settings['varnishCliTimeoutSeconds']))),
                'method'       => $method,
                'effectiveMethod' => $effective_method,
                'adminModeUsed' => ('admin' === $mode),
                'httpEndpointModeUsed' => ('http' === $mode),
                'support'      => self::get_varnish_support_status(),
                'last'         => self::get_varnish_last_result(),
                'endpointDiagnostics' => self::get_varnish_endpoint_diagnostics($servers_raw, $mode),
            );
        }

        private static function normalize_varnish_endpoint($terminal)
        {
            $terminal = trim((string) $terminal);
            if ('' === $terminal) {
                return array();
            }

            $check = self::validate_varnish_http_endpoint($terminal);
            if (empty($check['valid'])) {
                return array();
            }

            $host = (string) ($check['host'] ?? '');
            $port = (int) ($check['port'] ?? 0);
            if ('' === $host || $port <= 0) {
                return array();
            }

            return array(
                'scheme' => 'http',
                'host'   => $host,
                'port'   => $port,
            );
        }

        private static function build_varnish_target_url(array $endpoint, $path = '/')
        {
            $path = '/' . ltrim((string) $path, '/');
            return $endpoint['scheme'] . '://' . $endpoint['host'] . ':' . $endpoint['port'] . $path;
        }

        private static function summarize_varnish_http_body($body, $max_length = 180)
        {
            $body = trim(wp_strip_all_tags((string) $body));
            if ('' === $body) {
                return '';
            }

            $body = preg_replace('/\s+/', ' ', $body);
            if (!is_string($body) || '' === $body) {
                return '';
            }

            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($body) > $max_length) {
                    $body = mb_substr($body, 0, $max_length - 1) . '…';
                }
            } elseif (strlen($body) > $max_length) {
                $body = substr($body, 0, $max_length - 1) . '…';
            }

            return $body;
        }

        private static function send_varnish_http_request(array $endpoint, $target_url, $host_header, $timeout_s, $expr, $method)
        {
            $headers = array(
                'Host'               => (string) $host_header,
                'X-Ban-Expression'   => (string) $expr,
                'X-UltraCache-Purge' => '1',
            );

            $settings = self::get_varnish_cli_settings();
            if (!empty($settings['key'])) {
                $headers['X-UltraCache-Token'] = (string) $settings['key'];
            }

            $response = ucwp_safe_configured_infrastructure_remote_request($target_url, array(
                'method'      => (string) $method,
                'timeout'     => max(1, (int) $timeout_s),
                'redirection' => 0,
                'headers'     => $headers,
                'body'        => '',
            ), 'configured_varnish_http_request');

            if (is_wp_error($response)) {
                return array('ok' => false, 'detail' => self::sanitize_varnish_string($response->get_error_message()));
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $message = trim((string) wp_remote_retrieve_response_message($response));
            $body = trim((string) wp_remote_retrieve_body($response));
            $content_type = strtolower(trim((string) wp_remote_retrieve_header($response, 'content-type')));
            $summary = self::summarize_varnish_http_body($body);
            $looks_like_html = (false !== strpos($content_type, 'text/html')) || ('' !== $body && preg_match('/<(?:!doctype|html|head|body)\b/i', $body));

            if ($code < 200 || $code >= 300) {
                $detail = 'HTTP ' . $code . ($message !== '' ? ' ' . $message : '');
                if ($summary !== '') {
                    $detail .= ' · ' . $summary;
                }
                return array('ok' => false, 'detail' => self::sanitize_varnish_string($detail), 'code' => $code);
            }

            if ($looks_like_html) {
                return array(
                    'ok' => false,
                    'detail' => self::sanitize_varnish_string('HTTP ' . $code . ' returned an HTML page instead of a Varnish purge response. Check that this endpoint points to a Varnish frontend/listener that accepts ' . strtoupper((string) $method) . '.'),
                    'code' => $code,
                );
            }

            $detail = 'HTTP ' . $code . ($message !== '' ? ' ' . $message : '');
            if ($summary !== '') {
                $detail .= ' · ' . $summary;
            } elseif ($message === '') {
                $detail .= ' ' . strtoupper((string) $method) . ' OK';
            }

            return array('ok' => true, 'detail' => self::sanitize_varnish_string($detail), 'code' => $code);
        }

        private static function parse_varnish_terminal($terminal)
        {
            $terminal = trim((string) $terminal);
            if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $terminal, $matches)) {
                return array($matches[1], (int) $matches[2]);
            }

            $pos = strrpos($terminal, ':');
            if (false === $pos) {
                return array('', 0);
            }

            return array(substr($terminal, 0, $pos), (int) substr($terminal, $pos + 1));
        }

        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        private static function read_varnish_admin_response($fp)
        {
            $header = ucwp_safe_fread($fp, 13, 'read_varnish_admin_response header');
            if (false === $header || strlen($header) < 13) {
                return array('ok' => false, 'code' => 0, 'body' => 'Failed to read Varnish admin response header.');
            }

            $code = (int) substr($header, 0, 3);
            $length = (int) substr($header, 4, 6) + 1;
            $body = '';
            while (strlen($body) < $length && !feof($fp)) {
                $chunk = ucwp_safe_fread($fp, $length - strlen($body), 'read_varnish_admin_response body');
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                $body .= $chunk;
            }

            return array('ok' => true, 'code' => $code, 'body' => trim((string) $body));
        }

        private static function extract_varnish_admin_challenge($body)
        {
            $body = (string) $body;
            if (preg_match('/^([A-Za-z0-9]{32,64})/m', $body, $matches)) {
                return (string) $matches[1];
            }

            return '';
        }

        private static function build_varnish_admin_auth_token($challenge, $secret)
        {
            $challenge = trim((string) $challenge);
            $secret    = trim((string) $secret);
            if ('' === $challenge || '' === $secret || !function_exists('hash')) {
                return '';
            }

            return hash('sha256', $challenge . "
" . $secret . "
" . $challenge . "
");
        }

        private static function send_varnish_admin_ban($host, $port, $secret, $timeout_s, $expr)
        {
            $host = trim((string) $host);
            $port = (int) $port;
            $secret = (string) $secret;

            if ('' === $host || $port <= 0 || !ucwp_is_allowed_socket_target($host, $port, 'configured_varnish_admin_endpoint')) {
                return array('ok' => false, 'detail' => self::sanitize_varnish_string('Invalid or blocked Varnish admin endpoint.'));
            }

            if ('' === trim($secret)) {
                return array('ok' => false, 'detail' => self::sanitize_varnish_string('Varnish admin secret is required for admin mode.'));
            }

            $connect = static function () use ($host, $port, $timeout_s) {
                $errno  = 0;
                $errstr = '';
                $fp = ucwp_safe_fsockopen($host, $port, $errno, $errstr, max(1, (int) $timeout_s), 'configured_varnish_admin_endpoint');
                if (!is_resource($fp)) {
                    return array(false, self::sanitize_varnish_string('Connection failed: ' . trim($errstr !== '' ? $errstr : ('Error ' . $errno))));
                }
                stream_set_timeout($fp, max(1, (int) $timeout_s));
                return array($fp, '');
            };

            list($fp, $connect_error) = $connect();
            if (!is_resource($fp)) {
                return array('ok' => false, 'detail' => self::sanitize_varnish_string($connect_error));
            }

            $hello = self::read_varnish_admin_response($fp);
            if (empty($hello['ok'])) {
                fclose($fp);
                return array('ok' => false, 'detail' => self::sanitize_varnish_string((string) ($hello['body'] ?? 'Invalid admin banner.')));
            }

            if (107 === (int) $hello['code']) {
                $challenge = self::extract_varnish_admin_challenge((string) ($hello['body'] ?? ''));
                if ('' === $challenge) {
                    fclose($fp);
                    return array('ok' => false, 'detail' => self::sanitize_varnish_string('Admin auth failed · Missing challenge from Varnish banner.'));
                }

                $tokens = array();
                $tokens[] = self::build_varnish_admin_auth_token($challenge, $secret);
                $tokens = array_values(array_unique(array_filter($tokens)));
                if (empty($tokens)) {
                    fclose($fp);
                    return array('ok' => false, 'detail' => self::sanitize_varnish_string('Admin auth failed · Could not build auth token.'));
                }

                $auth = array('ok' => false, 'code' => 0, 'body' => '');
                foreach ($tokens as $index => $token) {
                    fwrite($fp, 'auth ' . $token . "
");
                    $auth = self::read_varnish_admin_response($fp);
                    if (!empty($auth['ok']) && 200 === (int) ($auth['code'] ?? 0)) {
                        break;
                    }
                    if ($index < count($tokens) - 1) {
                        fclose($fp);
                        list($fp, $connect_error) = $connect();
                        if (!is_resource($fp)) {
                            return array('ok' => false, 'detail' => self::sanitize_varnish_string($connect_error));
                        }
                        $hello = self::read_varnish_admin_response($fp);
                        if (empty($hello['ok']) || 107 !== (int) ($hello['code'] ?? 0)) {
                            fclose($fp);
                            return array('ok' => false, 'detail' => self::sanitize_varnish_string('Admin auth failed · Could not re-open authenticated session.'));
                        }
                    }
                }

                if (empty($auth['ok']) || 200 !== (int) ($auth['code'] ?? 0)) {
                    fclose($fp);
                    $detail = 'Admin auth failed';
                    if (!empty($auth['body'])) {
                        $detail .= ' · ' . self::summarize_varnish_http_body($auth['body']);
                    }
                    return array('ok' => false, 'detail' => self::sanitize_varnish_string($detail));
                }
            } elseif (200 !== (int) $hello['code']) {
                fclose($fp);
                return array('ok' => false, 'detail' => self::sanitize_varnish_string('Unexpected admin banner · ' . self::summarize_varnish_http_body((string) ($hello['body'] ?? ''))));
            }

            fwrite($fp, 'ban ' . $expr . "
");
            $resp = self::read_varnish_admin_response($fp);
            fclose($fp);

            if (empty($resp['ok'])) {
                return array('ok' => false, 'detail' => self::sanitize_varnish_string((string) ($resp['body'] ?? 'No admin response.')));
            }

            $detail = 'Admin ' . (int) $resp['code'];
            if (!empty($resp['body'])) {
                $detail .= ' · ' . self::summarize_varnish_http_body($resp['body']);
            }

            return array('ok' => (200 === (int) $resp['code']), 'detail' => self::sanitize_varnish_string($detail), 'code' => (int) $resp['code']);
        }

        private static function varnish_command_for_expr($terminal, $secret, $timeout_s, $expr, $method)
        {
            $settings = self::get_varnish_cli_settings();
            if ('admin' === ($settings['mode'] ?? 'http')) {
                list($host, $port) = self::parse_varnish_terminal($terminal);
                $response = self::send_varnish_admin_ban($host, $port, $secret, $timeout_s, $expr);
                return $response;
            }

            $endpoint_check = self::validate_varnish_http_endpoint($terminal);
            if (empty($endpoint_check['valid'])) {
                return array('ok' => false, 'detail' => self::sanitize_varnish_string((string) ($endpoint_check['message'] ?? 'Invalid or blocked Varnish HTTP endpoint.')));
            }

            $endpoint = self::normalize_varnish_endpoint($terminal);
            if (empty($endpoint)) {
                return array('ok' => false, 'detail' => self::sanitize_varnish_string('Invalid or blocked Varnish HTTP endpoint.'));
            }

            $home = wp_parse_url(home_url('/'));
            $site_host = !empty($home['host']) ? (string) $home['host'] : $endpoint['host'];
            $target_url = self::build_varnish_target_url($endpoint, '/');

            $response = self::send_varnish_http_request($endpoint, $target_url, $site_host, $timeout_s, $expr, $method);

            return $response;
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
                'details' => $details,
            );

            self::set_varnish_last_result($result);
            return $result;
        }

        public static function varnish_flush_all_current_host()
        {
            $home = home_url('/');
            $parsed = wp_parse_url($home);
            $host = $parsed && !empty($parsed['host']) ? $parsed['host'] : '';
            if ('' === $host) {
                $result = array('success' => false, 'message' => self::maybe_translate('Could not determine site host for Varnish.'), 'time' => time());
                self::set_varnish_last_result($result);
                return $result;
            }

            $expr = self::build_varnish_ban_expression($host, '/', true);
            return self::varnish_send_expr_to_all($expr, 'all');
        }

        public static function varnish_flush_url($url)
        {
            $parsed = ucwp_safe_wp_parse_url((string) $url, -1, 'varnish_flush_url');
            if (!$parsed || empty($parsed['host'])) {
                $result = array('success' => false, 'message' => self::maybe_translate('Invalid URL for Varnish purge.'), 'time' => time(), 'url' => (string) $url);
                self::set_varnish_last_result($result);
                return $result;
            }

            $host = (string) $parsed['host'];
            $path = ((string) ($parsed['path'] ?? '/')) . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
            $settings = self::get_varnish_cli_settings();

            if (empty($settings['enabled'])) {
                $result = array('success' => false, 'message' => self::maybe_translate('Varnish integration is disabled.'), 'time' => time(), 'url' => (string) $url);
                self::set_varnish_last_result($result);
                return $result;
            }

            if (empty($settings['servers'])) {
                $result = array('success' => false, 'message' => self::maybe_translate('No Varnish endpoints are configured.'), 'time' => time(), 'url' => (string) $url);
                self::set_varnish_last_result($result);
                return $result;
            }

            $details = array();
            $all_ok = true;
            foreach ($settings['servers'] as $terminal) {
                $expr = self::build_varnish_ban_expression($host, $path, false);
                if ('admin' === ($settings['mode'] ?? 'http')) {
                    list($admin_host, $admin_port) = self::parse_varnish_terminal($terminal);
                    $res = self::send_varnish_admin_ban($admin_host, $admin_port, $settings['key'], $settings['timeout'], $expr);
                    $details[] = array('server' => $terminal, 'success' => !empty($res['ok']), 'detail' => self::sanitize_varnish_string((string) ($res['detail'] ?? '')));
                    if (empty($res['ok'])) {
                        $all_ok = false;
                    }
                    continue;
                }

                $endpoint_check = self::validate_varnish_http_endpoint($terminal);
                if (empty($endpoint_check['valid'])) {
                    $details[] = array('server' => $terminal, 'success' => false, 'detail' => self::sanitize_varnish_string((string) ($endpoint_check['message'] ?? 'Invalid or blocked Varnish HTTP endpoint.')));
                    $all_ok = false;
                    continue;
                }

                $endpoint = self::normalize_varnish_endpoint($terminal);
                if (empty($endpoint)) {
                    $details[] = array('server' => $terminal, 'success' => false, 'detail' => self::sanitize_varnish_string('Invalid or blocked Varnish HTTP endpoint.'));
                    $all_ok = false;
                    continue;
                }

                $target_url = self::build_varnish_target_url($endpoint, $path);
                $res = self::send_varnish_http_request($endpoint, $target_url, $host, $settings['timeout'], $expr, $settings['method']);
                $details[] = array('server' => $terminal, 'success' => !empty($res['ok']), 'detail' => self::sanitize_varnish_string((string) ($res['detail'] ?? '')));
                if (empty($res['ok'])) {
                    $all_ok = false;
                }
            }

            $effective_method = ('admin' === ($settings['mode'] ?? 'http')) ? 'admin BAN' : $settings['method'];
            $result = array(
                'success' => $all_ok,
                'message' => $all_ok ? 'Varnish ' . $effective_method . ' succeeded on ' . count($details) . ' endpoint(s).' : 'Varnish ' . $effective_method . ' failed on one or more endpoints.',
                'time'    => time(),
                'mode'    => (string) ($settings['mode'] ?? 'http'),
                'method'  => $settings['method'],
                'effectiveMethod' => $effective_method,
                'endpointCount' => count($details),
                'adminModeUsed' => ('admin' === ($settings['mode'] ?? 'http')),
                'httpEndpointModeUsed' => ('http' === ($settings['mode'] ?? 'http')),
                'secretConfigured' => !empty($settings['key']),
                'label'   => $path,
                'details' => $details,
            );
            self::set_varnish_last_result($result);
            return $result;
        }

        public static function varnish_test_connection()
        {
            self::reset_settings_cache();
            $home = home_url('/');
            $parsed = wp_parse_url($home);
            $host = $parsed && !empty($parsed['host']) ? $parsed['host'] : '';
            if ('' === $host) {
                $result = array('success' => false, 'message' => self::maybe_translate('Could not determine site host for Varnish test.'), 'time' => time());
                self::set_varnish_last_result($result);
                return $result;
            }

            $expr = self::build_varnish_ban_expression($host, '/', false);
            return self::varnish_send_expr_to_all($expr, '/');
        }

        private static function get_reverse_proxy_status()
        {
            $cached = get_transient('ultracache_reverse_proxy_status_v2');
            if (is_array($cached)) {
                return $cached;
            }

            $status = array(
                'detected'           => false,
                'varnish'            => false,
                'nginx_cache'        => false,
                'litespeed_cache'    => false,
                'server_cache'       => false,
                'provider'           => '',
                'providers'          => array(),
                'via'                => '',
                'x_varnish'          => '',
                'x_cache'            => '',
                'x_cache_status'     => '',
                'x_proxy_cache'      => '',
                'x_fastcgi_cache'    => '',
                'x_litespeed_cache'  => '',
                'x_qc_cache'         => '',
                'cf_cache_status'    => '',
                'age'                => '',
                'server'             => '',
                'message'            => '',
            );

            $response = ucwp_safe_loopback_remote_request(home_url('/'), array(
                'method'      => 'HEAD',
                'timeout'     => 5,
                'redirection' => 2,
                'headers'     => array(
                    'Cache-Control' => 'no-cache',
                    'Pragma'        => 'no-cache',
                ),
            ), 'reverse_proxy_status');

            if (!is_wp_error($response)) {
                $headers = wp_remote_retrieve_headers($response);
                $status['via']               = trim((string) ($headers['via'] ?? ''));
                $status['x_varnish']         = trim((string) ($headers['x-varnish'] ?? ''));
                $status['x_cache']           = trim((string) ($headers['x-cache'] ?? ''));
                $status['x_cache_status']    = trim((string) ($headers['x-cache-status'] ?? ''));
                $status['x_proxy_cache']     = trim((string) ($headers['x-proxy-cache'] ?? ''));
                $status['x_fastcgi_cache']   = trim((string) ($headers['x-fastcgi-cache'] ?? ''));
                $status['x_litespeed_cache'] = trim((string) ($headers['x-litespeed-cache'] ?? ''));
                $status['x_qc_cache']        = trim((string) ($headers['x-qc-cache'] ?? ''));
                $status['cf_cache_status']   = trim((string) ($headers['cf-cache-status'] ?? ''));
                $status['age']               = trim((string) ($headers['age'] ?? ''));
                $status['server']            = trim((string) ($headers['server'] ?? ''));

                $via_lower             = strtolower($status['via']);
                $x_cache_lower         = strtolower($status['x_cache']);
                $x_cache_status_lower  = strtolower($status['x_cache_status']);
                $x_proxy_cache_lower   = strtolower($status['x_proxy_cache']);
                $x_fastcgi_cache_lower = strtolower($status['x_fastcgi_cache']);
                $x_litespeed_lower     = strtolower($status['x_litespeed_cache']);
                $x_qc_cache_lower      = strtolower($status['x_qc_cache']);
                $cf_cache_lower        = strtolower($status['cf_cache_status']);
                $server_lower          = strtolower($status['server']);

                $status['varnish'] = ('' !== $status['x_varnish']) || (false !== strpos($via_lower, 'varnish'));
                $status['nginx_cache'] = ('' !== $status['x_fastcgi_cache'])
                    || ('' !== $status['x_proxy_cache'])
                    || ('' !== $status['x_cache_status'])
                    || ((false !== strpos($server_lower, 'nginx')) && (preg_match('/\b(hit|miss|bypass|expired|stale|updating|revalidated)\b/i', $status['x_cache']) || preg_match('/\b(hit|miss|bypass|expired|stale|updating|revalidated)\b/i', $status['x_cache_status'])));
                $status['litespeed_cache'] = ('' !== $status['x_litespeed_cache'])
                    || ('' !== $status['x_qc_cache'])
                    || (false !== strpos($server_lower, 'litespeed'));

                $providers = array();
                if ($status['varnish']) {
                    $providers[] = 'Varnish';
                }
                if ($status['nginx_cache']) {
                    $providers[] = 'Nginx Cache';
                }
                if ($status['litespeed_cache']) {
                    $providers[] = 'LiteSpeed Cache';
                }
                if ('' !== $status['cf_cache_status']) {
                    $providers[] = 'Cloudflare Cache';
                }
                if (!$providers && ('' !== $status['via'] || '' !== $status['x_cache'] || '' !== $status['age'])) {
                    $providers[] = 'Reverse Proxy Cache';
                }

                $status['providers'] = array_values(array_unique($providers));
                $status['provider'] = !empty($status['providers']) ? implode(' + ', $status['providers']) : '';
                $status['server_cache'] = !empty($status['providers']);
                $status['detected'] = $status['server_cache'];

                if ($status['detected']) {
                    $provider_label = $status['provider'] ? $status['provider'] : self::maybe_translate('Reverse Proxy Cache');
                    $status['message'] = self::maybe_translate_sprintf(
                        '%s detected. UltraCache hit counters reflect only requests that reach PHP/advanced-cache and may under-report public hits served before WordPress.',
                        $provider_label
                    );
                }
            }

            set_transient('ultracache_reverse_proxy_status_v2', $status, MINUTE_IN_SECONDS);
            return $status;
        }


}
