<?php
/**
 * Lightweight Varnish discovery HTTP probes.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Discovery_Probes_Trait
{
    /**
     * Read portable Varnish evidence from one HTTP response.
     *
     * @param array|WP_Error $response HTTP response.
     * @return array
     */
    private static function get_varnish_discovery_http_evidence($response)
    {
        if (is_wp_error($response)) {
            return array(
                'detected' => false,
                'xVarnish' => '',
                'via' => '',
                'server' => '',
                'age' => '',
            );
        }

        $x_varnish = self::get_varnish_response_header($response, 'x-varnish');
        $via = self::get_varnish_response_header($response, 'via');
        $server = self::get_varnish_response_header($response, 'server');
        $x_cache = self::get_varnish_response_header($response, 'x-cache');
        $x_cache_status = self::get_varnish_response_header($response, 'x-cache-status');
        $x_varnish_cache = self::get_varnish_response_header($response, 'x-varnish-cache');
        $combined = strtolower($via . ' ' . $server . ' ' . $x_cache . ' ' . $x_cache_status . ' ' . $x_varnish_cache);

        return array(
            'detected' => '' !== $x_varnish || false !== strpos($combined, 'varnish'),
            'xVarnish' => $x_varnish,
            'via' => $via,
            'server' => $server,
            'age' => self::get_varnish_response_header($response, 'age'),
        );
    }

    /**
     * Probe the canonical homepage once and confirm that the public response
     * passes through Varnish.
     *
     * @param string $url     Canonical homepage URL.
     * @param int    $timeout Timeout seconds.
     * @return array
     */
    private static function probe_varnish_discovery_homepage($url, $timeout)
    {
        $response = ultracache_safe_loopback_remote_request($url, array(
            'method' => 'GET',
            'timeout' => max(2, min(10, (int) $timeout)),
            'redirection' => 0,
            'headers' => array(
                'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
                'Accept-Encoding' => 'identity',
            ),
            'cookies' => array(),
        ), 'varnish_discovery_homepage');

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'varnishDetected' => false,
                'httpCode' => 0,
                'message' => self::sanitize_varnish_string($response->get_error_message()),
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $message = trim((string) wp_remote_retrieve_response_message($response));
        $evidence = self::get_varnish_discovery_http_evidence($response);

        return array(
            'success' => $code >= 200 && $code < 400,
            'varnishDetected' => !empty($evidence['detected']),
            'httpCode' => $code,
            'message' => self::sanitize_varnish_string('HTTP ' . $code . ('' !== $message ? ' ' . $message : '')),
        );
    }

    /**
     * Classify one invalidation response using portable Varnish evidence.
     *
     * @param array|WP_Error $response HTTP response.
     * @param string         $method   Attempted method.
     * @return array
     */
    private static function classify_varnish_discovery_invalidation_response($response, $method)
    {
        if (is_wp_error($response)) {
            return array(
                'accepted' => false,
                'httpCode' => 0,
                'detail' => self::sanitize_varnish_string($response->get_error_message()),
                'varnishEvidence' => false,
                'authenticationRequired' => false,
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $message = trim((string) wp_remote_retrieve_response_message($response));
        $body = trim((string) wp_remote_retrieve_body($response));
        $summary = trim(wp_strip_all_tags($body));
        $summary = preg_replace('/\s+/', ' ', (string) $summary);
        $summary = self::sanitize_varnish_string($summary);
        if (function_exists('mb_substr')) {
            $summary = mb_substr($summary, 0, 240, 'UTF-8');
        } else {
            $summary = self::sanitize_varnish_string(substr($summary, 0, 240));
        }

        $evidence = self::get_varnish_discovery_http_evidence($response);
        $text = strtolower($message . ' ' . $summary);
        $action_confirmed = 1 === preg_match('/\b(purged?|ban(?:ned)?|ban added|invalidated?|cache cleared|cache flushed|flush(?:ed)? successfully)\b/i', $text);
        $authentication_required = in_array($code, array(401, 403, 407), true)
            || 1 === preg_match('/\b(auth(?:entication|orization)?|unauthorized|forbidden|secret|token|control key|permission denied)\b/i', $text);
        $accepted = $code >= 200 && $code < 300
            && (!empty($evidence['detected']) || $action_confirmed || '' === $body);

        $detail = 'HTTP ' . $code . ('' !== $message ? ' ' . $message : '');
        if ('' !== $summary) {
            $detail .= ' · ' . $summary;
        } elseif ('' === $message) {
            $detail .= ' ' . strtoupper((string) $method) . ' OK';
        }

        return array(
            'accepted' => $accepted,
            'httpCode' => $code,
            'detail' => self::sanitize_varnish_string($detail),
            'varnishEvidence' => !empty($evidence['detected']),
            'actionConfirmed' => $action_confirmed,
            'authenticationRequired' => $authentication_required,
        );
    }

    /**
     * Send one homepage invalidation request to a candidate listener.
     *
     * @param array  $candidate      Candidate endpoint.
     * @param string $site_host      Canonical host.
     * @param string $site_scheme    Canonical public scheme.
     * @param string $request_target Canonical homepage request target.
     * @param string $method         PURGE or BAN.
     * @param string $token          Optional configured token.
     * @param int    $timeout        Timeout seconds.
     * @return array
     */
    private static function send_varnish_discovery_invalidation(array $candidate, $site_host, $site_scheme, $request_target, $method, $token, $timeout)
    {
        $endpoint = self::build_varnish_discovery_endpoint_label(
            (string) ($candidate['scheme'] ?? 'http'),
            (string) ($candidate['host'] ?? ''),
            (int) ($candidate['port'] ?? 0)
        );
        if ('' === $endpoint) {
            return array('accepted' => false, 'httpCode' => 0, 'detail' => 'Invalid candidate endpoint.');
        }

        $request_target = '/' . ltrim((string) $request_target, '/');
        $escaped_host = str_replace(array('\\', '"', "\r", "\n"), array('\\\\', '\\"', '', ''), (string) $site_host);
        $escaped_target = str_replace(array('\\', '"', "\r", "\n"), array('\\\\', '\\"', '', ''), preg_quote($request_target, '/'));
        $expression = 'req.http.host == "' . $escaped_host . '" && req.url ~ "^' . $escaped_target . '$"';
        $headers = array(
            'Host' => (string) $site_host,
            'X-Forwarded-Proto' => (string) $site_scheme,
            'X-Ban-Expression' => $expression,
            'X-UltraCache-Purge' => '1',
        );
        if ('' !== trim((string) $token)) {
            $headers['X-UltraCache-Token'] = trim((string) $token);
        }

        $response = ultracache_safe_configured_infrastructure_remote_request($endpoint . $request_target, array(
            'method' => strtoupper((string) $method),
            'timeout' => max(1, min(5, (int) $timeout)),
            'redirection' => 0,
            'headers' => $headers,
            'body' => '',
        ), 'configured_varnish_discovery_invalidation');

        $result = self::classify_varnish_discovery_invalidation_response($response, $method);
        $result['endpoint'] = $endpoint;
        $result['method'] = strtoupper((string) $method);
        return $result;
    }
}
