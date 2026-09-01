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
    /**
     * Read and sanitize one bounded WordPress HTTP response header.
     *
     * Shared by the production refill pipeline and the compact Varnish test.
     *
     * @param array|WP_Error $response WordPress HTTP API response.
     * @param string         $name     Header name.
     * @return string
     */
    protected static function get_varnish_response_header($response, $name)
    {
        if (is_wp_error($response)) {
            return '';
        }

        $value = wp_remote_retrieve_header($response, (string) $name);
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }

        $value = trim((string) $value);
        if ('' === $value) {
            return '';
        }

        $value = preg_replace('/[\r\n\t]+/', ' ', $value);
        $value = is_string($value) ? preg_replace('/\s+/', ' ', $value) : '';

        return self::sanitize_varnish_string(substr((string) $value, 0, 500));
    }

    /**
     * Resolve the stable public origin for one trusted Varnish cache host.
     *
     * Language-domain control must preserve the language host while avoiding a
     * request-filtered home_url() scheme. Configured home and provider language-home
     * URLs are the authoritative runtime control topology.
     *
     * @param string $host Public host.
     * @return string
     */
    private static function get_varnish_public_origin_for_host($host)
    {
        $host = strtolower(rtrim(trim((string) $host), '.'));
        if ('' === $host) {
            return '';
        }

        $candidates = array();
        if (function_exists('ultracache_get_public_site_topology')) {
            $topology = ultracache_get_public_site_topology();
            if (!empty($topology['configuredBase'])) {
                $candidates[] = (string) $topology['configuredBase'];
            }
            foreach ((array) ($topology['multilingualLanguageHomeUrls'] ?? array()) as $language_url) {
                $candidates[] = (string) $language_url;
            }
        }
        if (empty($candidates) && function_exists('ultracache_get_configured_site_origin')) {
            $candidates[] = (string) ultracache_get_configured_site_origin();
        }

        foreach ($candidates as $candidate) {
            $parts = wp_parse_url($candidate);
            if (!is_array($parts)) {
                continue;
            }
            $candidate_host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if ($candidate_host !== $host || !in_array($scheme, array('http', 'https'), true)) {
                continue;
            }
            $origin = $scheme . '://' . $candidate_host;
            if (!empty($parts['port'])) {
                $origin .= ':' . (int) $parts['port'];
            }
            return $origin;
        }

        $stable_origin = function_exists('ultracache_get_configured_site_origin')
            ? (string) ultracache_get_configured_site_origin()
            : '';
        $scheme = strtolower((string) wp_parse_url($stable_origin, PHP_URL_SCHEME));
        if (!in_array($scheme, array('http', 'https'), true)) {
            return '';
        }
        return $scheme . '://' . $host;
    }


    /**
     * Classify one public Varnish response from portable HTTP evidence.
     *
     * This parser is runtime transport code. It is used to summarize actual
     * refill responses and by the compact connection/invalidation test.
     *
     * @param array $headers       Normalized response headers.
     * @param int   $response_code HTTP response status.
     * @return array
     */
    protected static function classify_varnish_response(array $headers, $response_code)
    {
        $response_code = (int) $response_code;
        if (($response_code >= 300 && $response_code < 400 && 304 !== $response_code) || $response_code < 200 || $response_code >= 400) {
            return array(
                'status'          => 'ERROR',
                'varnishDetected' => false,
                'confidence'      => 'high',
                'evidence'        => ($response_code >= 300 && $response_code < 400) ? 'canonical-redirect' : 'http-status',
            );
        }

        $via = strtolower((string) ($headers['via'] ?? ''));
        $server = strtolower((string) ($headers['server'] ?? ''));
        $x_varnish = trim((string) ($headers['xVarnish'] ?? ''));
        $x_varnish_cache = strtolower((string) ($headers['xVarnishCache'] ?? ''));
        $varnish_detected = '' !== $x_varnish
            || false !== strpos($via, 'varnish')
            || false !== strpos($server, 'varnish')
            || '' !== $x_varnish_cache;

        $status_headers = strtolower(implode(' ', array_filter(array(
            (string) ($headers['xCache'] ?? ''),
            (string) ($headers['xCacheStatus'] ?? ''),
            (string) ($headers['xProxyCache'] ?? ''),
            (string) ($headers['xVarnishCache'] ?? ''),
        ))));

        $warning_header = strtolower((string) ($headers['warning'] ?? ''));
        $has_stale_warning = 1 === preg_match('/(?:^|[,\s])11[01](?:$|[,\s-])/', $warning_header)
            || false !== strpos($warning_header, 'response is stale')
            || false !== strpos($warning_header, 'revalidation failed');
        $has_stale = $has_stale_warning || 1 === preg_match('/\b(stale|grace|updating)\b/i', $status_headers);
        $has_bypass = 1 === preg_match('/\b(pass|bypass|uncacheable)\b/i', $status_headers);
        $has_miss = 1 === preg_match('/\bmiss\b/i', $status_headers);
        $has_hit = 1 === preg_match('/\b(hit|cached)\b/i', $status_headers);

        if ($varnish_detected && $has_stale) {
            return array(
                'status'          => 'STALE',
                'varnishDetected' => true,
                'confidence'      => 'high',
                'evidence'        => $has_stale_warning ? 'warning-header' : 'cache-status-header',
            );
        }

        if ($varnish_detected && (int) $has_bypass + (int) $has_miss + (int) $has_hit > 1) {
            return array(
                'status'          => 'INCONCLUSIVE',
                'varnishDetected' => true,
                'confidence'      => 'low',
                'evidence'        => 'ambiguous-cache-status-header',
            );
        }

        if ($varnish_detected && $has_bypass) {
            return array(
                'status'          => 'BYPASS',
                'varnishDetected' => true,
                'confidence'      => 'high',
                'evidence'        => 'cache-status-header',
            );
        }

        if ($varnish_detected && $has_miss) {
            return array(
                'status'          => 'MISS',
                'varnishDetected' => true,
                'confidence'      => 'high',
                'evidence'        => 'cache-status-header',
            );
        }

        if ($varnish_detected && $has_hit) {
            return array(
                'status'          => 'HIT',
                'varnishDetected' => true,
                'confidence'      => 'high',
                'evidence'        => 'cache-status-header',
            );
        }

        $age_raw = trim((string) ($headers['age'] ?? ''));
        $age = ctype_digit($age_raw) ? (int) $age_raw : null;
        if ($varnish_detected && null !== $age && $age > 0) {
            return array(
                'status'          => 'HIT',
                'varnishDetected' => true,
                'confidence'      => 'medium',
                'evidence'        => 'positive-age',
            );
        }

        $varnish_ids = array();
        if ('' !== $x_varnish && preg_match_all('/\b\d+\b/', $x_varnish, $matches)) {
            $varnish_ids = array_values(array_unique($matches[0]));
        }

        if ($varnish_detected && count($varnish_ids) >= 2) {
            return array(
                'status'          => 'HIT',
                'varnishDetected' => true,
                'confidence'      => 'medium',
                'evidence'        => 'multiple-x-varnish-ids',
            );
        }

        if ($varnish_detected && 1 === count($varnish_ids) && 0 === $age) {
            return array(
                'status'          => 'MISS',
                'varnishDetected' => true,
                'confidence'      => 'medium',
                'evidence'        => 'single-x-varnish-id-age-zero',
            );
        }

        return array(
            'status'          => 'INCONCLUSIVE',
            'varnishDetected' => $varnish_detected,
            'confidence'      => 'low',
            'evidence'        => $varnish_detected ? 'varnish-headers-without-cache-status' : 'no-varnish-headers',
        );
    }
        private static function send_varnish_http_request(array $endpoint, $target_url, $host_header, $timeout_s, $expr, $method, array $extra_headers = array())
        {
            $settings = self::get_varnish_cli_settings();
            $endpoint_label = self::normalize_varnish_metrics_endpoint_label($endpoint);
            $contract_profile = self::get_varnish_http_contract_runtime_profile($endpoint_label, $settings);
            $soft_requested = !empty($extra_headers['X-UltraCache-Soft-Purge'])
                || 'soft' === strtolower((string) ($extra_headers['X-Purge'] ?? ''));
            if (
                !empty($contract_profile)
                && !$soft_requested
                && 'PURGE' === strtoupper((string) $method)
                && self::is_varnish_endpoint_capability_current($contract_profile, 'exactPurge')
            ) {
                $parts = wp_parse_url((string) $target_url);
                $path = is_array($parts) && !empty($parts['path']) ? (string) $parts['path'] : '/';
                if (is_array($parts) && !empty($parts['query'])) {
                    $path .= '?' . (string) $parts['query'];
                }
                $public_origin = self::get_varnish_public_origin_for_host((string) $host_header);
                if ('' === $public_origin) {
                    $public_origin = function_exists('ultracache_get_configured_site_origin')
                        ? (string) ultracache_get_configured_site_origin()
                        : '';
                }
                $public_url = rtrim($public_origin, '/') . $path;
                $contract_result = self::send_varnish_http_contract_exact_purge(
                    $endpoint_label,
                    $public_url,
                    $timeout_s,
                    $settings
                );
                if (!empty($contract_result['ok'])) {
                    return $contract_result;
                }

                self::maybe_downgrade_varnish_http_contract_profile($endpoint_label, $contract_result, $settings);
                // The standalone CWP template and generic hosts may still expose
                // exact PURGE even when the optional advanced contract is stale.
                // Continue into the portable request path for this operation.
            }

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
            $x_varnish = self::get_varnish_response_header($response, 'x-varnish');
            $via = self::get_varnish_response_header($response, 'via');
            $server = self::get_varnish_response_header($response, 'server');
            $varnish_evidence = '' !== $x_varnish
                || false !== stripos($via, 'varnish')
                || false !== stripos($server, 'varnish');
            $invalidation_text = strtolower($message . ' ' . $summary);
            $invalidation_confirmed = 1 === preg_match('/\b(purged?|ban(?:ned)?|ban added|invalidated?|cache cleared|cache flushed|flush(?:ed)? successfully)\b/i', $invalidation_text);
            $verified_html_invalidation = $looks_like_html
                && $varnish_evidence
                && $invalidation_confirmed
                && strlen($body) <= 16384;

            if ($code < 200 || $code >= 300) {
                $detail = 'HTTP ' . $code . ($message !== '' ? ' ' . $message : '');
                if ($summary !== '') {
                    $detail .= ' · ' . $summary;
                }
                return $finalize(array('ok' => false, 'detail' => self::sanitize_varnish_string($detail), 'code' => $code));
            }

            if ($looks_like_html && !$verified_html_invalidation) {
                return $finalize(array(
                    'ok' => false,
                    'detail' => self::sanitize_varnish_string('HTTP ' . $code . ' returned an unverified HTML page instead of a confirmed Varnish invalidation response. Check that this endpoint points to a Varnish frontend/listener that accepts ' . strtoupper((string) $method) . '.'),
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

        // Varnish CLI is a bounded socket protocol with challenge-response authentication; WordPress has no API for this transport.
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Scoped to the authenticated Varnish admin socket helpers below.

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

        private static function get_varnish_admin_secret_materials($secret)
        {
            $secret = (string) $secret;
            if ('' === $secret) {
                return array();
            }

            $secret_without_line_break = rtrim($secret, "\r\n");
            $materials = array(
                $secret,
                $secret_without_line_break . "\n",
                $secret_without_line_break,
            );

            return array_values(array_filter(array_unique($materials), static function ($material) {
                return '' !== (string) $material;
            }));
        }

        private static function build_varnish_admin_auth_token($challenge, $secret_material)
        {
            $challenge = trim((string) $challenge);
            $secret_material = (string) $secret_material;
            if ('' === $challenge || '' === $secret_material || !function_exists('hash')) {
                return '';
            }

            return hash('sha256', $challenge . "\n" . $secret_material . $challenge . "\n");
        }

        private static function write_varnish_admin_command($fp, $command)
        {
            if (!is_resource($fp)) {
                return false;
            }

            $command = (string) $command;
            $length = strlen($command);
            if (0 === $length || $length > 8192 || "\n" !== substr($command, -1)) {
                return false;
            }

            $written = 0;
            while ($written < $length) {
                $bytes = fwrite($fp, substr($command, $written));
                if (false === $bytes || 0 === $bytes) {
                    return false;
                }
                $written += $bytes;
            }

            return true;
        }

        private static function open_authenticated_varnish_admin_connection($host, $port, $secret, $timeout_s)
        {
            $host = trim((string) $host);
            $port = (int) $port;
            $secret = (string) $secret;

            if ('' === $host || $port <= 0 || !ultracache_is_allowed_socket_target($host, $port, 'configured_varnish_admin_endpoint')) {
                return array('ok' => false, 'detail' => self::sanitize_varnish_string('Invalid or blocked Varnish admin endpoint.'));
            }

            if (strlen($secret) > 4096) {
                return array('ok' => false, 'detail' => self::sanitize_varnish_string('Varnish admin secret exceeds the supported length.'));
            }

            $secret_materials = self::get_varnish_admin_secret_materials($secret);
            if (empty($secret_materials)) {
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

            $last_auth_code = 0;
            foreach ($secret_materials as $secret_material) {
                list($fp, $connect_error) = $connect();
                if (!is_resource($fp)) {
                    return array('ok' => false, 'detail' => self::sanitize_varnish_string($connect_error));
                }

                $hello = self::read_varnish_admin_response($fp);
                if (empty($hello['ok'])) {
                    fclose($fp);
                    return array('ok' => false, 'detail' => self::sanitize_varnish_string((string) ($hello['body'] ?? 'Invalid admin banner.')));
                }

                $hello_code = (int) ($hello['code'] ?? 0);
                if (200 === $hello_code) {
                    return array('ok' => true, 'fp' => $fp);
                }

                if (107 !== $hello_code) {
                    fclose($fp);
                    return array('ok' => false, 'detail' => self::sanitize_varnish_string('Unexpected admin banner · Admin ' . $hello_code));
                }

                $challenge = self::extract_varnish_admin_challenge((string) ($hello['body'] ?? ''));
                if ('' === $challenge) {
                    fclose($fp);
                    return array('ok' => false, 'detail' => self::sanitize_varnish_string('Admin auth failed · Missing challenge from Varnish banner.'));
                }

                $token = self::build_varnish_admin_auth_token($challenge, $secret_material);
                if ('' === $token) {
                    fclose($fp);
                    return array('ok' => false, 'detail' => self::sanitize_varnish_string('Admin auth failed · Could not build auth token.'));
                }

                if (!self::write_varnish_admin_command($fp, 'auth ' . $token . "\n")) {
                    fclose($fp);
                    return array('ok' => false, 'detail' => self::sanitize_varnish_string('Admin auth failed · Could not write authentication command.'));
                }

                $auth = self::read_varnish_admin_response($fp);
                $last_auth_code = (int) ($auth['code'] ?? 0);
                if (!empty($auth['ok']) && 200 === $last_auth_code) {
                    return array('ok' => true, 'fp' => $fp);
                }

                fclose($fp);
            }

            $detail = 'Admin auth failed';
            if ($last_auth_code > 0) {
                $detail .= ' · Admin ' . $last_auth_code;
            }

            return array('ok' => false, 'detail' => self::sanitize_varnish_string($detail));
        }

        private static function send_varnish_admin_ban($host, $port, $secret, $timeout_s, $expr, $max_attempts = 1)
        {
            $expr = self::build_varnish_object_metadata_expression($expr);
            if ('' === $expr) {
                return array('ok' => false, 'connectionAccepted' => false, 'commandAccepted' => false, 'detail' => self::sanitize_varnish_string('Invalid or unsupported Varnish BAN expression.'));
            }

            $max_attempts = max(1, min(3, absint($max_attempts)));
            $last_response = array('ok' => false, 'connectionAccepted' => false, 'commandAccepted' => false, 'detail' => self::sanitize_varnish_string('Admin connection failed.'), 'code' => 0);

            for ($attempt = 1; $attempt <= $max_attempts; ++$attempt) {
                $connection = self::open_authenticated_varnish_admin_connection($host, $port, $secret, $timeout_s);
                if (empty($connection['ok']) || !is_resource($connection['fp'] ?? null)) {
                    $last_response = array(
                        'ok' => false,
                        'connectionAccepted' => false,
                        'commandAccepted' => false,
                        'detail' => self::sanitize_varnish_string((string) ($connection['detail'] ?? 'Admin connection failed.')),
                        'code' => 0,
                    );
                } else {
                    $fp = $connection['fp'];
                    if (!self::write_varnish_admin_command($fp, 'ban ' . $expr . "\n")) {
                        fclose($fp);
                        $last_response = array(
                            'ok' => false,
                            'connectionAccepted' => true,
                            'commandAccepted' => false,
                            'detail' => self::sanitize_varnish_string('Could not write Varnish BAN command.'),
                            'code' => 0,
                        );
                    } else {
                        $resp = self::read_varnish_admin_response($fp);
                        fclose($fp);

                        if (empty($resp['ok'])) {
                            $last_response = array(
                                'ok' => false,
                                'connectionAccepted' => true,
                                'commandAccepted' => false,
                                'detail' => self::sanitize_varnish_string((string) ($resp['body'] ?? 'No admin response.')),
                                'code' => 0,
                            );
                        } else {
                            $code = (int) $resp['code'];
                            $accepted = in_array($code, array(200, 201), true);
                            $detail = 'Admin ' . $code;
                            if (!empty($resp['body'])) {
                                $detail .= ' · ' . self::summarize_varnish_http_body($resp['body']);
                            }

                            $last_response = array(
                                'ok' => $accepted,
                                'connectionAccepted' => true,
                                'commandAccepted' => $accepted,
                                'detail' => self::sanitize_varnish_string($detail),
                                'code' => $code,
                            );
                        }
                    }
                }

                $last_response['attemptCount'] = $attempt;
                if (!empty($last_response['ok']) || !self::is_varnish_admin_ban_retryable($last_response) || $attempt >= $max_attempts) {
                    if ($attempt > 1) {
                        $last_response['detail'] = self::sanitize_varnish_string(
                            (string) ($last_response['detail'] ?? '') . ' · attempt ' . $attempt . '/' . $max_attempts
                        );
                    }
                    return $last_response;
                }

                usleep(200000);
            }

            return $last_response;
        }

        private static function is_varnish_admin_ban_retryable(array $response)
        {
            if (!empty($response['ok']) || absint($response['code'] ?? 0) > 0) {
                return false;
            }

            $detail = strtolower((string) ($response['detail'] ?? ''));
            if (preg_match('/\b(?:auth(?:entication)?|secret|challenge|permission denied|access denied|unauthorized|forbidden|invalid|unsupported|blocked)\b/i', $detail)
                || preg_match('/\bAdmin\s+[1-9]\d{2}\b/i', $detail)) {
                return false;
            }

            return true;
        }

        private static function send_varnish_admin_ban_list($host, $port, $secret, $timeout_s)
        {
            $connection = self::open_authenticated_varnish_admin_connection($host, $port, $secret, $timeout_s);
            if (empty($connection['ok']) || !is_resource($connection['fp'] ?? null)) {
                return array('ok' => false, 'detail' => self::sanitize_varnish_string((string) ($connection['detail'] ?? 'Admin connection failed.')));
            }

            $fp = $connection['fp'];
            if (!self::write_varnish_admin_command($fp, "ban.list\n")) {
                fclose($fp);
                return array('ok' => false, 'detail' => self::sanitize_varnish_string('Could not write Varnish ban.list command.'));
            }
            $resp = self::read_varnish_admin_response($fp, 262144);
            fclose($fp);

            if (empty($resp['ok'])) {
                return array('ok' => false, 'detail' => self::sanitize_varnish_string((string) ($resp['body'] ?? 'No admin response.')));
            }

            return array(
                'ok' => in_array((int) $resp['code'], array(200, 201), true),
                'partial' => (201 === (int) $resp['code']),
                'detail' => self::sanitize_varnish_string('Admin ' . (int) $resp['code'] . ' ban.list' . (201 === (int) $resp['code'] ? ' · response truncated by Varnish CLI limit' : '')),
                'code' => (int) $resp['code'],
                'body' => (string) ($resp['body'] ?? ''),
            );
        }

        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

        private static function varnish_command_for_expr($terminal, $secret, $timeout_s, $expr, $method, $diagnostic_probe = false, $admin_max_attempts = 1, $public_host = '')
        {
            $settings = self::get_varnish_cli_settings();
            if ($diagnostic_probe) {
                $object_expression = method_exists(static::class, 'build_varnish_object_metadata_expression')
                    ? self::build_varnish_object_metadata_expression($expr)
                    : '';
                $classified = '' !== $object_expression && method_exists(static::class, 'classify_varnish_contract_ban_operation')
                    ? self::classify_varnish_contract_ban_operation($object_expression)
                    : '';
                $probe_map = array(
                    'exact-ban' => array(
                        'operation' => 'targeted',
                        'strategy' => 'PURGE' === strtoupper((string) $method) ? 'exact-purge' : 'exact-ban',
                        'scope' => 'exact-url',
                    ),
                    'batch-ban' => array(
                        'operation' => 'targeted',
                        'strategy' => 'batch-ban',
                        'scope' => 'batch',
                    ),
                    'html-ban' => array(
                        'operation' => 'site-flush',
                        'strategy' => 'html-flush',
                        'scope' => 'html',
                    ),
                    'host-ban' => array(
                        'operation' => 'site-flush',
                        'strategy' => 'host-flush',
                        'scope' => 'host',
                    ),
                );
                $probe = is_array($probe_map[$classified] ?? null) ? $probe_map[$classified] : array();
                $authorized = !empty($probe)
                    && method_exists(static::class, 'is_varnish_capability_probe_transport_authorized')
                    && self::is_varnish_capability_probe_transport_authorized(
                        (string) $probe['operation'],
                        (string) $probe['strategy'],
                        (string) $probe['scope'],
                        array($terminal)
                    );
                if (!$authorized) {
                    return array(
                        'ok' => false,
                        'status' => 'diagnostic-probe-not-authorized',
                        'detail' => self::sanitize_varnish_string('The diagnostic Varnish command is outside the active scoped capability probe.'),
                        'code' => 0,
                    );
                }
            }
            if ('admin' === ($settings['mode'] ?? 'http')) {
                list($host, $port) = self::parse_varnish_terminal($terminal);
                $started_at = microtime(true);
                $response = self::send_varnish_admin_ban($host, $port, $secret, $timeout_s, $expr, $admin_max_attempts);
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

            $contract_profile = self::get_varnish_http_contract_runtime_profile($terminal, $settings);
            if (!empty($contract_profile) || $diagnostic_probe) {
                $response = self::send_varnish_http_contract_ban($terminal, $timeout_s, $expr, $settings, (bool) $diagnostic_probe, $public_host);
                if (!empty($response['ok'])) {
                    return $response;
                }
                if (!empty($contract_profile) || !empty($response['contractAvailable'])) {
                    if (!$diagnostic_probe) {
                        self::maybe_downgrade_varnish_http_contract_profile($terminal, $response, $settings);
                    }
                    return $response;
                }
            }

            $site_host = trim((string) $public_host);
            if ('' === $site_host) {
                $stable_origin = function_exists('ultracache_get_configured_site_origin') ? ultracache_get_configured_site_origin() : home_url('/');
                $site_host = (string) wp_parse_url($stable_origin, PHP_URL_HOST);
            }
            if ('' === $site_host) {
                $site_host = (string) $endpoint['host'];
            }
            $target_url = self::build_varnish_target_url($endpoint, '/');

            $response = self::send_varnish_http_request($endpoint, $target_url, $site_host, $timeout_s, $expr, $method);

            return $response;
        }
}
