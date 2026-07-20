<?php
/**
 * Varnish HTTP and admin-socket transport helpers for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Transport_Trait
{
        private static function send_varnish_http_request(array $endpoint, $target_url, $host_header, $timeout_s, $expr, $method, array $extra_headers = array())
        {
            $started_at = microtime(true);
            $endpoint_label = self::normalize_varnish_metrics_endpoint_label($endpoint);
            $finalize = static function (array $result) use ($started_at, $endpoint_label) {
                self::record_varnish_endpoint_result(
                    $endpoint_label,
                    'http',
                    !empty($result['ok']),
                    (microtime(true) - $started_at) * 1000,
                    (string) ($result['detail'] ?? '')
                );
                return $result;
            };

            $headers = array(
                'Host'               => (string) $host_header,
                'X-Ban-Expression'   => (string) $expr,
                'X-UltraCache-Purge' => '1',
            );

            foreach ($extra_headers as $header_name => $header_value) {
                $header_name = sanitize_key(str_replace('_', '-', (string) $header_name));
                if ('' !== $header_name) {
                    $headers[$header_name] = sanitize_text_field((string) $header_value);
                }
            }

            $settings = self::get_varnish_cli_settings();
            if (!empty($settings['key'])) {
                $headers['X-UltraCache-Token'] = (string) $settings['key'];
            }

            $response = ultracache_safe_configured_infrastructure_remote_request($target_url, array(
                'method'      => (string) $method,
                'timeout'     => max(1, (int) $timeout_s),
                'redirection' => 0,
                'headers'     => $headers,
                'body'        => '',
            ), 'configured_varnish_http_request');

            if (is_wp_error($response)) {
                return $finalize(array('ok' => false, 'detail' => self::sanitize_varnish_string($response->get_error_message())));
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
                return $finalize(array('ok' => false, 'detail' => self::sanitize_varnish_string($detail), 'code' => $code));
            }

            if ($looks_like_html) {
                return $finalize(array(
                    'ok' => false,
                    'detail' => self::sanitize_varnish_string('HTTP ' . $code . ' returned an HTML page instead of a Varnish purge response. Check that this endpoint points to a Varnish frontend/listener that accepts ' . strtoupper((string) $method) . '.'),
                    'code' => $code,
                ));
            }

            $detail = 'HTTP ' . $code . ($message !== '' ? ' ' . $message : '');
            if ($summary !== '') {
                $detail .= ' · ' . $summary;
            } elseif ($message === '') {
                $detail .= ' ' . strtoupper((string) $method) . ' OK';
            }

            return $finalize(array('ok' => true, 'detail' => self::sanitize_varnish_string($detail), 'code' => $code));
        }

        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

        private static function read_varnish_admin_response($fp, $max_body_bytes = 1048576)
        {
            $header = '';
            while (!feof($fp) && strlen($header) < 13) {
                $chunk = ultracache_safe_fread($fp, 13 - strlen($header), 'read_varnish_admin_response header');
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                $header .= $chunk;
            }

            if (strlen($header) < 13) {
                return array('ok' => false, 'code' => 0, 'body' => 'Failed to read Varnish admin response header.');
            }

            if (!preg_match('/^(\d{3})\s+(\d+)/', trim($header), $matches)) {
                return array('ok' => false, 'code' => 0, 'body' => 'Invalid Varnish admin response header.');
            }

            $code = (int) $matches[1];
            $length = max(0, (int) $matches[2]);
            $max_body_bytes = max(1024, min(1048576, absint($max_body_bytes)));
            if ($length > $max_body_bytes) {
                return array('ok' => false, 'code' => 0, 'body' => 'Varnish admin response exceeded the bounded response limit.');
            }
            $body = '';
            $expected_body_bytes = $length + 1;
            while (strlen($body) < $expected_body_bytes && !feof($fp)) {
                $chunk = ultracache_safe_fread($fp, min(8192, $expected_body_bytes - strlen($body)), 'read_varnish_admin_response body');
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                $body .= $chunk;
            }

            if (strlen($body) !== $expected_body_bytes || "\n" !== substr($body, -1)) {
                return array('ok' => false, 'code' => 0, 'body' => 'Invalid Varnish admin response body.');
            }

            return array('ok' => true, 'code' => $code, 'body' => trim(substr($body, 0, $length)));
        }

        private static function extract_varnish_admin_challenge($body)
        {
            $body = (string) $body;
            if (preg_match('/^([A-Za-z0-9]{32,64})/m', $body, $matches)) {
                return (string) $matches[1];
            }

            return '';
        }

        private static function build_varnish_admin_auth_tokens($challenge, $secret)
        {
            $challenge = trim((string) $challenge);
            $secret    = str_replace("\0", '', (string) $secret);
            if ('' === $challenge || '' === $secret || !function_exists('hash')) {
                return array();
            }

            $secret_without_line_break = rtrim($secret, "\r\n");
            $secret_materials = array(
                $secret,
                $secret_without_line_break . "\n",
                $secret_without_line_break,
            );

            $tokens = array();
            foreach (array_values(array_unique($secret_materials)) as $material) {
                if ('' === $material) {
                    continue;
                }
                $tokens[] = hash('sha256', $challenge . "\n" . $material . $challenge . "\n");
            }

            return array_values(array_unique($tokens));
        }

        private static function open_authenticated_varnish_admin_connection($host, $port, $secret, $timeout_s)
        {
            $host = trim((string) $host);
            $port = (int) $port;
            $secret = (string) $secret;

            if ('' === $host || $port <= 0 || !ultracache_is_allowed_socket_target($host, $port, 'configured_varnish_admin_endpoint')) {
                return array('ok' => false, 'detail' => self::sanitize_varnish_string('Invalid or blocked Varnish admin endpoint.'));
            }

            if ('' === trim($secret)) {
                return array('ok' => false, 'detail' => self::sanitize_varnish_string('Varnish admin secret is required for admin mode.'));
            }

            $connect = static function () use ($host, $port, $timeout_s) {
                $errno  = 0;
                $errstr = '';
                $fp = ultracache_safe_fsockopen($host, $port, $errno, $errstr, max(1, (int) $timeout_s), 'configured_varnish_admin_endpoint');
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

                $tokens = self::build_varnish_admin_auth_tokens($challenge, $secret);
                if (empty($tokens)) {
                    fclose($fp);
                    return array('ok' => false, 'detail' => self::sanitize_varnish_string('Admin auth failed · Could not build auth token.'));
                }

                $auth = array('ok' => false, 'code' => 0, 'body' => '');
                foreach ($tokens as $index => $token) {
                    fwrite($fp, 'auth ' . $token . "\n");
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

            return array('ok' => true, 'fp' => $fp);
        }

        private static function send_varnish_admin_ban($host, $port, $secret, $timeout_s, $expr)
        {
            $connection = self::open_authenticated_varnish_admin_connection($host, $port, $secret, $timeout_s);
            if (empty($connection['ok']) || !is_resource($connection['fp'] ?? null)) {
                return array('ok' => false, 'detail' => self::sanitize_varnish_string((string) ($connection['detail'] ?? 'Admin connection failed.')));
            }

            $fp = $connection['fp'];
            fwrite($fp, 'ban ' . $expr . "\n");
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

        private static function send_varnish_admin_ban_list($host, $port, $secret, $timeout_s)
        {
            $connection = self::open_authenticated_varnish_admin_connection($host, $port, $secret, $timeout_s);
            if (empty($connection['ok']) || !is_resource($connection['fp'] ?? null)) {
                return array('ok' => false, 'detail' => self::sanitize_varnish_string((string) ($connection['detail'] ?? 'Admin connection failed.')));
            }

            $fp = $connection['fp'];
            fwrite($fp, "ban.list\n");
            $resp = self::read_varnish_admin_response($fp, 262144);
            fclose($fp);

            if (empty($resp['ok'])) {
                return array('ok' => false, 'detail' => self::sanitize_varnish_string((string) ($resp['body'] ?? 'No admin response.')));
            }

            return array(
                'ok' => (200 === (int) $resp['code']),
                'detail' => self::sanitize_varnish_string('Admin ' . (int) $resp['code'] . ' ban.list'),
                'code' => (int) $resp['code'],
                'body' => (string) ($resp['body'] ?? ''),
            );
        }

        private static function varnish_command_for_expr($terminal, $secret, $timeout_s, $expr, $method)
        {
            $settings = self::get_varnish_cli_settings();
            if ('admin' === ($settings['mode'] ?? 'http')) {
                list($host, $port) = self::parse_varnish_terminal($terminal);
                $started_at = microtime(true);
                $response = self::send_varnish_admin_ban($host, $port, $secret, $timeout_s, $expr);
                self::record_varnish_endpoint_result(
                    $terminal,
                    'admin',
                    !empty($response['ok']),
                    (microtime(true) - $started_at) * 1000,
                    (string) ($response['detail'] ?? '')
                );
                return $response;
            }

            $endpoint_check = self::validate_varnish_http_endpoint($terminal);
            if (empty($endpoint_check['valid'])) {
                $response = array('ok' => false, 'detail' => self::sanitize_varnish_string((string) ($endpoint_check['message'] ?? 'Invalid or blocked Varnish HTTP endpoint.')));
                self::record_varnish_endpoint_result($terminal, 'http', false, 0, (string) $response['detail']);
                return $response;
            }

            $endpoint = self::normalize_varnish_endpoint($terminal);
            if (empty($endpoint)) {
                $response = array('ok' => false, 'detail' => self::sanitize_varnish_string('Invalid or blocked Varnish HTTP endpoint.'));
                self::record_varnish_endpoint_result($terminal, 'http', false, 0, (string) $response['detail']);
                return $response;
            }

            $home = wp_parse_url(home_url('/'));
            $site_host = !empty($home['host']) ? (string) $home['host'] : $endpoint['host'];
            $target_url = self::build_varnish_target_url($endpoint, '/');

            $response = self::send_varnish_http_request($endpoint, $target_url, $site_host, $timeout_s, $expr, $method);

            return $response;
        }
}
