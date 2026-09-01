<?php
/**
 * Guarded socket, HTTP, loopback, URL, and trusted-host helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

function ultracache_is_allowed_socket_target($host, $port, $context = '')
{
    $host = strtolower(trim((string) $host));
    $port = (int) $port;
    $context = (string) $context;

    if ('' === $host || $port <= 0 || $port > 65535) {
        return false;
    }

    $default_allowed_ports = array(80, 82, 443, 6081, 6082);
    if (false !== stripos($context, 'varnish')) {
        // Varnish is commonly deployed on custom ports. Endpoint trust is handled by the caller/context.
        $default_allowed_ports[] = $port;
    }
    $allowed_ports = apply_filters('ultracache_allowed_socket_ports', array_values(array_unique(array_map('intval', $default_allowed_ports))), $host, $context);
    if (is_array($allowed_ports) && !in_array($port, array_map('intval', $allowed_ports), true)) {
        return false;
    }

    if (false !== stripos($context, 'configured_varnish') || false !== stripos($context, 'trusted_infrastructure')) {
        return (bool) apply_filters('ultracache_allow_configured_infrastructure_socket_target', true, $host, $port, $context);
    }

    $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    $home_host = strtolower((string) $home_host);
    $local_hosts = array_filter(array_unique(array('localhost', '127.0.0.1', '::1', '[::1]', $home_host)));

    if (in_array($host, $local_hosts, true)) {
        return true;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (0 === strpos($host, '10.') || 0 === strpos($host, '192.168.') || preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $host)) {
                return true;
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && ('fc' === substr($host, 0, 2) || 'fd' === substr($host, 0, 2))) {
            return true;
        }
        return false;
    }

    if ('' !== $home_host && ($host === $home_host || preg_match('/(?:^|\.)' . preg_quote($home_host, '/') . '$/i', $host))) {
        return true;
    }

    return (bool) apply_filters('ultracache_is_allowed_socket_target', false, $host, $port, $context);
}

function ultracache_is_allowed_redis_socket_target($host, $port, $context = 'redis_endpoint')
{
    $raw_host = trim((string) $host);
    $host = preg_replace('#^(?:tcp|tls|ssl)://#i', '', $raw_host);
    $host = trim((string) $host, " \t\n\r\0\x0B[]");
    $port = (int) $port;
    $context = (string) $context;

    if ('' === $host || $port <= 0 || $port > 65535 || false !== strpos($host, '/')) {
        return false;
    }

    $normalized = strtolower($host);
    $trusted_hosts = apply_filters('ultracache_trusted_redis_socket_hosts', array(), $host, $port, $context);
    if (is_array($trusted_hosts)) {
        $trusted_hosts = array_map(static function ($value) {
            return strtolower(trim((string) $value));
        }, $trusted_hosts);
        if (in_array($normalized, $trusted_hosts, true)) {
            return true;
        }
    }

    $allowed = apply_filters('ultracache_allow_configured_external_redis_endpoint', true, $host, $port, $context);
    if ($allowed) {
        return true;
    }

    return (bool) apply_filters('ultracache_is_allowed_redis_socket_target', false, $host, $port, $context);
}

function ultracache_safe_fsockopen($host, $port, &$errno, &$errstr, $timeout = 0, $context = '')
{
    $host = (string) $host;
    $port = (int) $port;
    $timeout = (float) $timeout;
    $context = (string) $context;
    $errno = 0;
    $errstr = '';

    if (!ultracache_is_allowed_socket_target($host, $port, $context)) {
        $errstr = 'Socket target is not allowed.';
        ultracache_debug_log('stream_socket_client blocked: unsafe socket target', array(
            'host' => $host,
            'port' => $port,
            'timeout' => $timeout,
            'context' => $context,
        ));
        return false;
    }

    $remote_socket = 'tcp://' . $host . ':' . $port;
    $stream = @stream_socket_client($remote_socket, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);

    if (false === $stream) {
        $log_context = array(
            'host' => $host,
            'port' => $port,
            'timeout' => $timeout,
            'context' => $context,
            'errno' => (int) $errno,
        );
        if ('' !== (string) $errstr) {
            $log_context['error'] = (string) $errstr;
        }
        ultracache_debug_log('stream_socket_client failed', $log_context);
    }

    return $stream;
}

/**
 * Run a WordPress HTTP API request with the execution-bound timeout contract.
 *
 * WordPress' bundled Requests cURL transport clamps a zero total timeout to one
 * second and has a separate connect timeout. For UltraCache execution-bound
 * same-site work, temporarily align cURL with the explicit request timeout just
 * before send, then remove the hook immediately after the scoped WordPress HTTP
 * request returns. A zero total timeout therefore has no UltraCache total-request
 * deadline; cURL's own native connection semantics still apply.
 *
 * @param string $url                   Request URL.
 * @param array  $args                  WordPress HTTP API arguments.
 * @param bool   $safe                  Whether to use wp_safe_remote_request().
 * @param bool   $align_connect_timeout Whether the cURL connect timeout should follow the request timeout.
 * @return array|WP_Error
 */
function ultracache_wordpress_http_request_with_execution_timeout($url, array $args, $safe = true, $align_connect_timeout = false)
{
    $timeout = array_key_exists('timeout', $args) ? (float) $args['timeout'] : null;
    $needs_zero_override = null !== $timeout && $timeout <= 0;
    $needs_connect_alignment = null !== $timeout && $align_connect_timeout;

    if ((!$needs_zero_override && !$needs_connect_alignment) || !function_exists('add_action') || !function_exists('remove_action')) {
        return $safe ? wp_safe_remote_request($url, $args) : wp_remote_request($url, $args);
    }

    $curl_callback = static function ($handle, $parsed_args, $request_url) use ($align_connect_timeout) {
        unset($request_url);
        if (!is_array($parsed_args) || !array_key_exists('timeout', $parsed_args)) {
            return;
        }

        $request_timeout = max(0.0, (float) $parsed_args['timeout']);
        if ($request_timeout <= 0) {
            if (defined('CURLOPT_TIMEOUT')) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Scoped http_api_curl transport adjustment; the request itself uses the WordPress HTTP API.
                curl_setopt($handle, CURLOPT_TIMEOUT, 0);
            }
            if (defined('CURLOPT_TIMEOUT_MS')) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Scoped http_api_curl transport adjustment; the request itself uses the WordPress HTTP API.
                curl_setopt($handle, CURLOPT_TIMEOUT_MS, 0);
            }
        }

        if (!$align_connect_timeout) {
            return;
        }

        if ($request_timeout <= 0) {
            if (defined('CURLOPT_CONNECTTIMEOUT')) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Scoped http_api_curl transport adjustment; the request itself uses the WordPress HTTP API.
                curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 0);
            }
            if (defined('CURLOPT_CONNECTTIMEOUT_MS')) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Scoped http_api_curl transport adjustment; the request itself uses the WordPress HTTP API.
                curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, 0);
            }
            return;
        }

        if (defined('CURLOPT_CONNECTTIMEOUT_MS')) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Scoped http_api_curl transport adjustment; the request itself uses the WordPress HTTP API.
            curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, (int) max(1, round($request_timeout * 1000)));
        } elseif (defined('CURLOPT_CONNECTTIMEOUT')) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Scoped http_api_curl transport adjustment; the request itself uses the WordPress HTTP API.
            curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, (int) max(1, ceil($request_timeout)));
        }
    };

    add_action('http_api_curl', $curl_callback, PHP_INT_MAX, 3);
    try {
        return $safe ? wp_safe_remote_request($url, $args) : wp_remote_request($url, $args);
    } finally {
        remove_action('http_api_curl', $curl_callback, PHP_INT_MAX);
    }
}

function ultracache_safe_remote_request($url, array $args = array(), $context = '')
{
    $url = is_string($url) ? trim($url) : '';
    if ('' === $url) {
        return new WP_Error('ultracache_empty_remote_url', __('Remote request URL is empty.', 'ultracache'));
    }


    $defaults = array(
        'timeout' => 10,
        'redirection' => 3,
        'reject_unsafe_urls' => true,
        'user-agent' => 'UltraCache/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : 'unknown') . '; ' . home_url('/'),
    );
    $args = array_merge($defaults, $args);
    $args['redirection'] = max(0, min(5, (int) $args['redirection']));
    $allow_long_loopback_timeout = 0 === strpos((string) $context, 'warm_url')
        || 0 === strpos((string) $context, 'css_bundle_scan')
        || 0 === strpos((string) $context, 'litespeed_')
        || 0 === strpos((string) $context, 'litespeed-')
        || 0 === strpos((string) $context, 'varnish_refill')
        || 0 === strpos((string) $context, 'varnish_refresh_probe')
        || 0 === strpos((string) $context, 'frontpage-font-pattern-scan')
        || 0 === strpos((string) $context, 'frontend_compression_probe')
        || 0 === strpos((string) $context, 'frontend_probe_target');
    $args['timeout'] = $allow_long_loopback_timeout
        ? max(0, (int) $args['timeout'])
        : max(1, min(60, (int) $args['timeout']));
    $args['reject_unsafe_urls'] = true;

    $response = ultracache_wordpress_http_request_with_execution_timeout($url, $args, true, $allow_long_loopback_timeout);
    if (is_wp_error($response)) {
        ultracache_debug_log('wp_safe_remote_request failed', array(
            'url' => (string) $url,
            'context' => (string) $context,
            'error' => $response->get_error_message(),
        ));
    }

    return $response;
}

function ultracache_safe_configured_infrastructure_remote_request($url, array $args = array(), $context = '')
{
    $url = is_string($url) ? trim($url) : '';
    if ('' === $url) {
        return new WP_Error('ultracache_empty_remote_url', __('Remote request URL is empty.', 'ultracache'));
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts)) {
        return new WP_Error('ultracache_invalid_infrastructure_url', __('Configured infrastructure URL is invalid.', 'ultracache'));
    }

    $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
    $host = isset($parts['host']) ? strtolower(trim((string) $parts['host'])) : '';
    $port = isset($parts['port']) ? (int) $parts['port'] : ('https' === $scheme ? 443 : 80);
    if (!in_array($scheme, array('http', 'https'), true) || '' === $host || $port <= 0 || $port > 65535) {
        return new WP_Error('ultracache_invalid_infrastructure_url', __('Configured infrastructure URL must use http(s) with a valid host and port.', 'ultracache'));
    }

    if (!ultracache_is_allowed_socket_target($host, $port, 'trusted_infrastructure_' . (string) $context)) {
        return new WP_Error('ultracache_blocked_infrastructure_url', __('Configured infrastructure target is blocked by UltraCache socket policy.', 'ultracache'));
    }

    $defaults = array(
        'timeout' => 10,
        'redirection' => 0,
        'reject_unsafe_urls' => false,
        'user-agent' => 'UltraCache/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : 'unknown') . '; ' . home_url('/'),
    );
    $args = array_merge($defaults, $args);
    $args['redirection'] = max(0, min(2, (int) $args['redirection']));
    $args['timeout'] = max(1, min(60, (int) $args['timeout']));
    $args['reject_unsafe_urls'] = false;

    // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_request_wp_remote_request -- This wrapper is only used for administrator-configured same-server infrastructure endpoints after ultracache_is_allowed_socket_target() validation; wp_safe_remote_request() would block trusted loopback targets needed for Varnish/reverse-proxy integrations.
    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) {
        ultracache_debug_log('wp_remote_request infrastructure request failed', array(
            'url' => (string) $url,
            'context' => (string) $context,
            'error' => $response->get_error_message(),
        ));
    }

    return $response;
}


function ultracache_get_loopback_ssl_status_state_name()
{
    return 'ultracache_state:runtime.loopback_ssl_status';
}

function ultracache_get_loopback_ssl_status_fingerprint()
{
    $payload = array(
        'schema' => 1,
        'siteOrigin' => function_exists('ultracache_get_configured_site_origin') ? ultracache_get_configured_site_origin() : '',
    );

    return substr(hash('sha256', (string) wp_json_encode($payload)), 0, 24);
}

function ultracache_get_loopback_ssl_status()
{
    $status = array();
    if (function_exists('ultracache_get_state_record_read_only')) {
        $record = ultracache_get_state_record_read_only(ultracache_get_loopback_ssl_status_state_name());
        $payload = is_array($record['payload'] ?? null) ? $record['payload'] : array();
        $status = is_array($payload['status'] ?? null) ? $payload['status'] : array();
    }

    $status = wp_parse_args($status, array(
        'strictByDefault' => true,
        'fallbackUsed'    => false,
        'lastUrl'         => '',
        'lastError'       => '',
        'context'         => '',
        'message'         => '',
        'updatedAt'       => 0,
        'fingerprint'     => '',
        'diagnosticStatus' => 'not-tested',
        'configurationChanged' => false,
        'ageSeconds' => 0,
    ));

    $status['updatedAt'] = max(0, (int) $status['updatedAt']);
    $status['ageSeconds'] = $status['updatedAt'] > 0 ? max(0, time() - $status['updatedAt']) : 0;
    $current_fingerprint = ultracache_get_loopback_ssl_status_fingerprint();
    $stored_fingerprint = sanitize_text_field((string) $status['fingerprint']);
    $status['configurationChanged'] = '' !== $stored_fingerprint && !hash_equals($current_fingerprint, $stored_fingerprint);
    $status['diagnosticStatus'] = $status['configurationChanged']
        ? 'configuration-changed'
        : ($status['updatedAt'] > 0 ? 'current' : 'not-tested');

    return $status;
}

function ultracache_set_loopback_ssl_status(array $status)
{
    if (!function_exists('ultracache_mutate_state_record')) {
        return false;
    }

    $status = wp_parse_args($status, array(
        'strictByDefault' => true,
        'fallbackUsed' => false,
        'lastUrl' => '',
        'lastError' => '',
        'context' => '',
        'message' => '',
        'updatedAt' => time(),
    ));
    $status['updatedAt'] = max(0, (int) $status['updatedAt']);
    $status['fingerprint'] = ultracache_get_loopback_ssl_status_fingerprint();
    $status['diagnosticStatus'] = 'current';
    $status['configurationChanged'] = false;
    $status['ageSeconds'] = 0;

    $mutation = ultracache_mutate_state_record(
        ultracache_get_loopback_ssl_status_state_name(),
        static function () use ($status) {
            return array(
                'schemaVersion' => 1,
                'recordedAt' => (int) $status['updatedAt'],
                'fingerprint' => (string) $status['fingerprint'],
                'status' => $status,
            );
        },
        5,
        array()
    );

    return !empty($mutation['success']);
}

function ultracache_reset_loopback_ssl_status()
{
    return function_exists('ultracache_delete_state_record')
        ? ultracache_delete_state_record(ultracache_get_loopback_ssl_status_state_name())
        : false;
}

function ultracache_is_local_https_url($url)
{
    $url = is_string($url) ? trim($url) : '';
    if ('' === $url) {
        return false;
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts)) {
        return false;
    }

    $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
    $host   = isset($parts['host']) ? ultracache_normalize_host((string) $parts['host']) : '';
    if ('https' !== $scheme || '' === $host) {
        return false;
    }

    $trusted_hosts = ultracache_get_trusted_hosts();
    return in_array($host, $trusted_hosts, true);
}


function ultracache_get_default_port_for_scheme($scheme)
{
    $scheme = strtolower((string) $scheme);
    if ('https' === $scheme) {
        return 443;
    }

    if ('http' === $scheme) {
        return 80;
    }

    return 0;
}

function ultracache_get_allowed_frontend_ports_for_scheme($scheme, $host)
{
    $scheme = strtolower((string) $scheme);
    $host = ultracache_normalize_host($host);
    $ports = array();

    $default = ultracache_get_default_port_for_scheme($scheme);
    if ($default > 0) {
        $ports[$default] = true;
    }

    $public_urls = array();
    if (function_exists('ultracache_get_public_site_topology')) {
        $topology = ultracache_get_public_site_topology();
        $public_urls = is_array($topology['multilingualPublicUrls'] ?? null) ? $topology['multilingualPublicUrls'] : array();
    }

    if (empty($public_urls)) {
        foreach (array(home_url('/'), site_url('/')) as $site_url) {
            $parts = wp_parse_url((string) $site_url);
            if (!is_array($parts)) {
                continue;
            }
            $public_urls[] = array(
                'scheme' => strtolower((string) ($parts['scheme'] ?? '')),
                'host'   => ultracache_normalize_host((string) ($parts['host'] ?? '')),
                'port'   => isset($parts['port']) ? (int) $parts['port'] : 0,
            );
        }
    }

    foreach ($public_urls as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $site_scheme = strtolower((string) ($entry['scheme'] ?? ''));
        $site_host = ultracache_normalize_host((string) ($entry['host'] ?? ''));
        if ($site_scheme !== $scheme || $site_host !== $host) {
            continue;
        }

        $port = (int) ($entry['port'] ?? 0);
        if ($port > 0 && $port <= 65535) {
            $ports[$port] = true;
        }
    }

    $ports = array_map('intval', array_keys($ports));
    sort($ports, SORT_NUMERIC);
    return $ports;
}

function ultracache_is_strict_frontend_loopback_url($url)
{
    $url = is_string($url) ? trim($url) : '';
    if ('' === $url) {
        return false;
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts)) {
        return false;
    }

    $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
    $host = isset($parts['host']) ? ultracache_normalize_host((string) $parts['host']) : '';
    if (!in_array($scheme, array('http', 'https'), true) || '' === $host) {
        return false;
    }

    $trusted_hosts = ultracache_get_trusted_hosts();
    if (!in_array($host, $trusted_hosts, true)) {
        return false;
    }

    if (isset($parts['port'])) {
        $port = (int) $parts['port'];
        $allowed_ports = ultracache_get_allowed_frontend_ports_for_scheme($scheme, $host);
        if ($port <= 0 || $port > 65535 || !in_array($port, $allowed_ports, true)) {
            return false;
        }
    }

    return (bool) apply_filters('ultracache_is_strict_frontend_loopback_url', true, $url, $host, $scheme, isset($parts['port']) ? (int) $parts['port'] : 0);
}

function ultracache_is_ssl_verification_wp_error($error)
{
    if (!($error instanceof WP_Error)) {
        return false;
    }

    $message = strtolower(trim((string) $error->get_error_message()));
    if ('' === $message) {
        return false;
    }

    $needles = array(
        'ssl certificate',
        'certificate verify failed',
        'peer certificate',
        'self signed certificate',
        'unable to get local issuer certificate',
        'unable to verify the first certificate',
        'tlsv1 alert',
        'certificate has expired',
        'hostname mismatch',
        'curl error 60',
        'curl error 51',
    );

    foreach ($needles as $needle) {
        if (false !== strpos($message, $needle)) {
            return true;
        }
    }

    return false;
}

function ultracache_is_trusted_loopback_url($url)
{
    // Frontend loopback requests are intentionally stricter than configured
    // infrastructure endpoints. Redis/Varnish/custom infrastructure validation
    // must continue to use the configured socket/endpoint helpers, not this one.
    return function_exists('ultracache_is_strict_frontend_loopback_url') && ultracache_is_strict_frontend_loopback_url($url);
}

function ultracache_safe_loopback_remote_request($url, array $args = array(), $context = '')
{
    if (!ultracache_is_trusted_loopback_url($url)) {
        ultracache_debug_log('loopback remote request blocked: untrusted URL', array('url' => (string) $url, 'context' => (string) $context));
        return new WP_Error('ultracache_untrusted_loopback_url', __('Loopback request URL is not local/trusted for this site.', 'ultracache'));
    }

    $is_local_https = ultracache_is_local_https_url($url);
    if (!$is_local_https) {
        return ultracache_safe_remote_request($url, $args, $context);
    }

    $strict_args = $args;
    $strict_args['sslverify'] = true;
    $response = ultracache_safe_remote_request($url, $strict_args, $context . ':strict');
    if (!is_wp_error($response)) {
        return $response;
    }

    if (!ultracache_is_ssl_verification_wp_error($response)) {
        return $response;
    }

    $fallback_args = $args;
    $fallback_args['sslverify'] = false;
    $fallback = ultracache_safe_remote_request($url, $fallback_args, $context . ':fallback');
    if (!is_wp_error($fallback)) {
        ultracache_set_loopback_ssl_status(array(
            'strictByDefault' => true,
            'fallbackUsed'    => true,
            'lastUrl'         => (string) $url,
            'lastError'       => (string) $response->get_error_message(),
            'context'         => (string) $context,
            'message'         => function_exists('__') ? __('Strict local SSL verification failed and UltraCache temporarily retried the same-host HTTPS loopback request without certificate verification.', 'ultracache') : 'Strict local SSL verification failed and UltraCache temporarily retried the same-host HTTPS loopback request without certificate verification.',
            'updatedAt'       => time(),
        ));
        return $fallback;
    }

    return $response;
}

/**
 * Normalize dot-segments in one HTTP URL path without changing its encoding.
 *
 * @param string $path URL path.
 * @return string
 */
function ultracache_normalize_frontend_probe_path($path)
{
    $path = (string) $path;
    $trailing_slash = '' !== $path && '/' === substr($path, -1);
    $segments = explode('/', $path);
    $normalized = array();

    foreach ($segments as $segment) {
        if ('' === $segment || '.' === $segment) {
            continue;
        }
        if ('..' === $segment) {
            array_pop($normalized);
            continue;
        }
        $normalized[] = $segment;
    }

    $result = '/' . implode('/', $normalized);
    if ($trailing_slash && '/' !== $result) {
        $result .= '/';
    }
    return $result;
}

/**
 * Convert one same-site Location header into an absolute HTTP(S) URL.
 *
 * @param string $location Redirect Location value.
 * @param string $base_url URL that returned the redirect.
 * @return string
 */
function ultracache_make_absolute_frontend_probe_url($location, $base_url)
{
    $location = trim((string) $location);
    $base_url = esc_url_raw((string) $base_url);
    if ('' === $location || '' === $base_url) {
        return '';
    }

    if (function_exists('wp_sanitize_redirect')) {
        $location = wp_sanitize_redirect($location);
    }
    if ('' === $location) {
        return '';
    }

    if (preg_match('#^https?://#i', $location)) {
        return esc_url_raw($location);
    }

    $base = wp_parse_url($base_url);
    if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
        return '';
    }

    $scheme = strtolower((string) $base['scheme']);
    $host = (string) $base['host'];
    $port = isset($base['port']) ? ':' . (int) $base['port'] : '';
    $origin = $scheme . '://' . $host . $port;

    if (0 === strpos($location, '//')) {
        return esc_url_raw($scheme . ':' . $location);
    }

    if (0 === strpos($location, '#')) {
        $current = preg_replace('/#.*$/', '', $base_url);
        return esc_url_raw((string) $current);
    }

    if (0 === strpos($location, '?')) {
        $base_path = isset($base['path']) && '' !== (string) $base['path'] ? (string) $base['path'] : '/';
        return esc_url_raw($origin . $base_path . $location);
    }

    $relative = wp_parse_url($location);
    if (false === $relative) {
        return '';
    }
    $relative = is_array($relative) ? $relative : array();
    $relative_path = isset($relative['path']) ? (string) $relative['path'] : '';

    if (0 === strpos($relative_path, '/')) {
        $path = $relative_path;
    } else {
        $base_path = isset($base['path']) && '' !== (string) $base['path'] ? (string) $base['path'] : '/';
        $base_dir = preg_replace('#/[^/]*$#', '/', $base_path);
        $path = (string) $base_dir . $relative_path;
    }

    $path = ultracache_normalize_frontend_probe_path($path);
    $query = isset($relative['query']) && '' !== (string) $relative['query'] ? '?' . (string) $relative['query'] : '';

    return esc_url_raw($origin . $path . $query);
}

/**
 * Resolve the final anonymous same-site frontend URL before a behavioral probe.
 *
 * This follows only explicit HTTP redirects and never infers language/plugin
 * routes. The request timeout follows PHP max_execution_time; max_execution_time
 * 0 remains unlimited under the existing UltraCache WordPress HTTP wrapper.
 * The redirect ceiling matches WordPress HTTP's normal redirect-chain guard and
 * is not an execution deadline.
 *
 * @param string $url     Initial frontend URL. Defaults to home_url('/').
 * @param string $context Diagnostic context suffix.
 * @return array|WP_Error
 */
function ultracache_resolve_anonymous_frontend_url($url = '', $context = 'frontend_probe_target')
{
    $requested_url = esc_url_raw('' !== trim((string) $url) ? (string) $url : home_url('/'));
    if ('' === $requested_url || !ultracache_is_trusted_loopback_url($requested_url)) {
        return new WP_Error(
            'ultracache_invalid_frontend_probe_target',
            __('The frontend probe target is not a trusted local URL.', 'ultracache')
        );
    }

    $current = $requested_url;
    $visited = array();
    $redirects = array();
    $php_max_execution = function_exists('ultracache_get_php_max_execution_time_seconds')
        ? ultracache_get_php_max_execution_time_seconds()
        : max(0, (int) ini_get('max_execution_time'));
    $redirect_limit = 5;

    for ($hop = 0; $hop <= $redirect_limit; $hop++) {
        $identity = (string) preg_replace('/#.*$/', '', $current);
        if (isset($visited[$identity])) {
            return new WP_Error(
                'ultracache_frontend_probe_redirect_loop',
                __('The anonymous frontend probe target entered a redirect loop.', 'ultracache'),
                array('requestedUrl' => $requested_url, 'lastUrl' => $current, 'redirects' => $redirects)
            );
        }
        $visited[$identity] = true;

        $response = ultracache_safe_loopback_remote_request(
            $current,
            array(
                'method' => 'GET',
                'timeout' => $php_max_execution,
                'redirection' => 0,
                'decompress' => false,
                'limit_response_size' => 1,
                'user-agent' => 'Mozilla/5.0 (compatible; UltraCache-Frontend-Probe-Resolver/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : 'unknown') . '; +https://wordpress.org)',
                'headers' => array(
                    'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
                    'Accept-Encoding' => 'identity',
                    'Cache-Control' => 'no-cache, max-age=0',
                    'Pragma' => 'no-cache',
                    'PageSpeed' => 'off',
                    'ModPagespeed' => 'off',
                ),
            ),
            'frontend_probe_target:' . sanitize_key((string) $context)
        );

        if (is_wp_error($response)) {
            return new WP_Error(
                'ultracache_frontend_probe_resolution_failed',
                $response->get_error_message(),
                array(
                    'requestedUrl' => $requested_url,
                    'lastUrl' => $current,
                    'errorCode' => $response->get_error_code(),
                    'redirects' => $redirects,
                )
            );
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $location = trim((string) wp_remote_retrieve_header($response, 'location'));
        if (in_array($http_code, array(301, 302, 303, 307, 308), true)) {
            if ('' === $location) {
                return new WP_Error(
                    'ultracache_frontend_probe_redirect_missing_location',
                    __('The anonymous frontend probe received a redirect without a Location header.', 'ultracache'),
                    array('requestedUrl' => $requested_url, 'lastUrl' => $current, 'httpCode' => $http_code, 'redirects' => $redirects)
                );
            }

            if ($hop >= $redirect_limit) {
                return new WP_Error(
                    'ultracache_frontend_probe_too_many_redirects',
                    __('The anonymous frontend probe exceeded WordPress\' normal redirect-chain limit.', 'ultracache'),
                    array('requestedUrl' => $requested_url, 'lastUrl' => $current, 'httpCode' => $http_code, 'redirects' => $redirects)
                );
            }

            $next = ultracache_make_absolute_frontend_probe_url($location, $current);
            if ('' === $next || !ultracache_is_trusted_loopback_url($next)) {
                return new WP_Error(
                    'ultracache_frontend_probe_redirect_untrusted',
                    __('The anonymous frontend probe redirected outside the trusted site URL set.', 'ultracache'),
                    array('requestedUrl' => $requested_url, 'lastUrl' => $current, 'location' => $location, 'redirects' => $redirects)
                );
            }

            $redirects[] = array(
                'from' => esc_url_raw($current),
                'to' => esc_url_raw($next),
                'httpCode' => $http_code,
            );
            $current = $next;
            continue;
        }

        $resolved_url = esc_url_raw($current);
        $resolved = array(
            'success' => $http_code >= 200 && $http_code < 300,
            'requestedUrl' => $requested_url,
            'resolvedUrl' => $resolved_url,
            'requestedLanguage' => function_exists('ultracache_multilingual_get_public_url_language')
                ? ultracache_multilingual_get_public_url_language($requested_url)
                : '',
            'resolvedLanguage' => function_exists('ultracache_multilingual_get_public_url_language')
                ? ultracache_multilingual_get_public_url_language($resolved_url)
                : '',
            'httpCode' => $http_code,
            'redirected' => !empty($redirects),
            'redirectCount' => count($redirects),
            'redirects' => $redirects,
        );
        return $resolved;
    }

    return new WP_Error(
        'ultracache_frontend_probe_resolution_failed',
        __('The anonymous frontend probe target could not be resolved.', 'ultracache')
    );
}

function ultracache_safe_wp_parse_url($url, $component = -1, $context = '')
{
    if (-1 === $component) {
        $result = wp_parse_url((string) $url);
    } else {
        $result = wp_parse_url((string) $url, $component);
    }

    if (false === $result) {
        ultracache_debug_log('wp_parse_url failed', array('url' => (string) $url, 'component' => $component, 'context' => (string) $context));
    }

    return $result;
}

function ultracache_normalize_host($host)
{
    $host = trim((string) $host);
    if ('' === $host) {
        return '';
    }

    if (false !== strpos($host, ',')) {
        $parts = explode(',', $host);
        $host = (string) reset($parts);
    }

    $host = preg_replace('/\s+/', '', $host);
    $parsed = wp_parse_url('http://' . ltrim($host, '/'));
    if (is_array($parsed) && !empty($parsed['host'])) {
        $host = (string) $parsed['host'];
    }

    $host = strtolower(rtrim(trim($host), '.'));
    if ('' === $host) {
        return '';
    }

    if (!preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:.]+\])$/i', $host)) {
        return '';
    }

    return $host;
}

/**
 * Return UltraCache's known configured and language-aware public site topology.
 *
 * This foundation separates stable WordPress configuration from contextual frontend
 * URLs and provider-resolved multilingual URLs. From 3.04.34 strict frontend-host,
 * native server-cache, and provider-neutral diagnostic consumers use the generic
 * multilingual collections while the legacy WPML-shaped fields remain available
 * as a compatibility projection.
 *
 * @return array<string,mixed>
 */
function ultracache_get_public_site_topology()
{
    $multilingual_provider = function_exists('ultracache_multilingual_get_provider')
        ? ultracache_multilingual_get_provider()
        : 'none';
    $multilingual_active = function_exists('ultracache_multilingual_is_active')
        && ultracache_multilingual_is_active();
    $generic_language_homes = $multilingual_active && function_exists('ultracache_multilingual_get_language_home_urls')
        ? ultracache_multilingual_get_language_home_urls()
        : array();
    $wpml_active = function_exists('ultracache_wpml_is_active') && ultracache_wpml_is_active();
    $wpml_language_homes = $wpml_active && function_exists('ultracache_wpml_get_language_home_urls')
        ? ultracache_wpml_get_language_home_urls()
        : array();

    $topology = array(
        'configuredBase'               => function_exists('ultracache_get_configured_site_base') ? ultracache_get_configured_site_base() : '',
        'configuredOrigin'             => function_exists('ultracache_get_configured_site_origin') ? ultracache_get_configured_site_origin() : '',
        'multilingualActive'           => $multilingual_active,
        'multilingualProvider'         => $multilingual_provider,
        'defaultLanguage'              => $multilingual_active && function_exists('ultracache_multilingual_get_default_language') ? ultracache_multilingual_get_default_language() : '',
        'activeLanguages'              => $multilingual_active && function_exists('ultracache_multilingual_get_active_languages') ? ultracache_multilingual_get_active_languages() : array(),
        'multilingualLanguageHomeUrls' => $generic_language_homes,
        'urlMode'                      => $multilingual_active && function_exists('ultracache_multilingual_get_url_mode') ? ultracache_multilingual_get_url_mode() : 'unknown',

        // Compatibility projection retained for code that still reads the old
        // WPML-shaped topology names. UltraCache's provider-neutral trust/native-
        // cache consumers use the generic multilingual collections from 3.04.34.
        'wpmlActive'                   => $wpml_active,
        'wpmlNegotiationType'          => function_exists('ultracache_wpml_get_negotiation_type') ? ultracache_wpml_get_negotiation_type() : 'unknown',
        'languageHomeUrls'             => $wpml_language_homes,
        'publicUrls'                   => array(),
        'hosts'                        => array(),

        // Provider-neutral collections are the authoritative strict frontend
        // trust/native-cache topology from 3.04.34 onward.
        'multilingualPublicUrls'       => array(),
        'multilingualHosts'            => array(),
    );

    $configured_siteurl = '';
    $siteurl_candidates = array();
    if (defined('WP_SITEURL')) {
        $siteurl_candidates[] = (string) WP_SITEURL;
    }
    if (function_exists('get_option')) {
        $siteurl_candidates[] = (string) get_option('siteurl');
    }
    foreach ($siteurl_candidates as $candidate) {
        $candidate = function_exists('ultracache_normalize_configured_site_base')
            ? ultracache_normalize_configured_site_base($candidate)
            : '';
        if ('' !== $candidate) {
            $configured_siteurl = $candidate;
            break;
        }
    }

    $build_collection = static function (array $language_homes, $language_source, $generic_language_codes = false) use ($topology, $configured_siteurl) {
        $urls = array();
        $add_url = static function ($url, $source = '', $language = '') use (&$urls, $generic_language_codes) {
            $url = trim((string) $url);
            if ('' === $url) {
                return;
            }

            $parts = wp_parse_url($url);
            if (!is_array($parts)) {
                return;
            }
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = ultracache_normalize_host((string) ($parts['host'] ?? ''));
            if (!in_array($scheme, array('http', 'https'), true) || '' === $host) {
                return;
            }
            if (!empty($parts['user']) || !empty($parts['pass'])) {
                return;
            }

            $normalized_url = function_exists('esc_url_raw')
                ? (string) esc_url_raw($url, array('http', 'https'))
                : $url;
            if ('' === $normalized_url) {
                return;
            }

            if ($generic_language_codes && function_exists('ultracache_multilingual_normalize_language_code')) {
                $language = ultracache_multilingual_normalize_language_code($language);
            } elseif (function_exists('ultracache_wpml_normalize_language_code')) {
                $language = ultracache_wpml_normalize_language_code($language);
            } else {
                $language = '';
            }

            $key = hash('sha256', $normalized_url . '|' . (string) $source . '|' . (string) $language);
            $urls[$key] = array(
                'url'      => $normalized_url,
                'host'     => $host,
                'scheme'   => $scheme,
                'port'     => isset($parts['port']) ? (int) $parts['port'] : 0,
                'source'   => (string) $source,
                'language' => (string) $language,
            );
        };

        $add_url($topology['configuredBase'], 'configured_home');
        $add_url($configured_siteurl, 'configured_siteurl');
        if (function_exists('home_url')) {
            $add_url(home_url('/'), 'current_home');
        }
        if (function_exists('site_url')) {
            $add_url(site_url('/'), 'current_siteurl');
        }
        foreach ($language_homes as $language => $url) {
            $add_url($url, $language_source, $language);
        }

        $hosts = array();
        foreach ($urls as $entry) {
            $host = (string) $entry['host'];
            if (!isset($hosts[$host])) {
                $hosts[$host] = array(
                    'host'      => $host,
                    'schemes'   => array(),
                    'ports'     => array(),
                    'languages' => array(),
                    'sources'   => array(),
                );
            }

            $hosts[$host]['schemes'][$entry['scheme']] = true;
            if ((int) $entry['port'] > 0) {
                $hosts[$host]['ports'][(int) $entry['port']] = true;
            }
            if ('' !== (string) $entry['language']) {
                $hosts[$host]['languages'][(string) $entry['language']] = true;
            }
            if ('' !== (string) $entry['source']) {
                $hosts[$host]['sources'][(string) $entry['source']] = true;
            }
        }

        ksort($hosts, SORT_STRING);
        foreach ($hosts as &$host_entry) {
            $host_entry['schemes'] = array_values(array_keys($host_entry['schemes']));
            $host_entry['ports'] = array_map('intval', array_keys($host_entry['ports']));
            $host_entry['languages'] = array_values(array_keys($host_entry['languages']));
            $host_entry['sources'] = array_values(array_keys($host_entry['sources']));
            sort($host_entry['schemes'], SORT_STRING);
            sort($host_entry['ports'], SORT_NUMERIC);
            sort($host_entry['languages'], SORT_STRING);
            sort($host_entry['sources'], SORT_STRING);
        }
        unset($host_entry);

        return array(
            'publicUrls' => array_values($urls),
            'hosts'      => $hosts,
        );
    };

    $legacy = $build_collection($wpml_language_homes, 'wpml_language_home', false);
    $generic = $build_collection($generic_language_homes, 'multilingual_language_home', true);
    $topology['publicUrls'] = $legacy['publicUrls'];
    $topology['hosts'] = $legacy['hosts'];
    $topology['multilingualPublicUrls'] = $generic['publicUrls'];
    $topology['multilingualHosts'] = $generic['hosts'];

    return $topology;
}

/**
 * Return whether Warm Cache should expand one warm scope across translations.
 *
 * This is a warm-policy setting only. It does not alter multilingual purge,
 * invalidation, page-cache, or provider topology semantics.
 *
 * @return bool
 */
function ultracache_should_warm_translation_pages()
{
    if (!function_exists('get_option')) {
        return true;
    }

    $key = defined('ULTRACACHE_SETTINGS_KEY') ? ULTRACACHE_SETTINGS_KEY : 'ultracache_settings';
    $settings = get_option($key, array());
    if (!is_array($settings)) {
        return true;
    }

    // Preserve the pre-setting multilingual warm behavior on upgrade.
    if (!array_key_exists('alsoWarmTranslationPagesEnabled', $settings)) {
        return true;
    }

    return !empty($settings['alsoWarmTranslationPagesEnabled']);
}

/**
 * Return canonical public homepage warm targets.
 *
 * Non-multilingual sites deliberately preserve the historical single
 * home_url('/') target. Supported multilingual providers supply their own
 * canonical language homes; UltraCache never constructs language paths or
 * follows redirects to guess them.
 *
 * @return array<int,string>
 */
function ultracache_get_frontpage_warm_targets($operation = '')
{
    if (!function_exists('ultracache_multilingual_is_active') || !ultracache_multilingual_is_active()) {
        return function_exists('home_url') ? array((string) home_url('/')) : array();
    }

    $operation = sanitize_key((string) $operation);
    $homes = function_exists('ultracache_multilingual_get_language_home_urls')
        ? ultracache_multilingual_get_language_home_urls()
        : array();
    $ordered_homes = array();

    if ('' !== $operation && function_exists('ultracache_multilingual_get_warm_languages')) {
        $language_codes = ultracache_multilingual_get_warm_languages($operation);
        foreach ((array) $language_codes as $language_code) {
            if (isset($homes[$language_code])) {
                $ordered_homes[$language_code] = $homes[$language_code];
                continue;
            }
            if (function_exists('ultracache_multilingual_translate_url')) {
                $configured_home = function_exists('get_option') ? trim((string) get_option('home')) : '';
                $translated_home = '' !== $configured_home
                    ? trim((string) ultracache_multilingual_translate_url($configured_home, $language_code))
                    : '';
                if ('' !== $translated_home) {
                    $ordered_homes[$language_code] = $translated_home;
                }
            }
        }
    } else {
        $warm_all_translations = !function_exists('ultracache_should_warm_translation_pages')
            || ultracache_should_warm_translation_pages();
        $language_codes = function_exists('ultracache_multilingual_get_active_language_codes')
            ? ultracache_multilingual_get_active_language_codes()
            : array();

        if (!$warm_all_translations) {
            $default_language = function_exists('ultracache_multilingual_get_default_language')
                ? (string) ultracache_multilingual_get_default_language()
                : '';
            if ('' !== $default_language && isset($homes[$default_language])) {
                $ordered_homes[$default_language] = $homes[$default_language];
            } elseif ('' !== $default_language && function_exists('ultracache_multilingual_translate_url')) {
                $configured_home = function_exists('get_option') ? trim((string) get_option('home')) : '';
                if ('' !== $configured_home) {
                    $translated_home = trim((string) ultracache_multilingual_translate_url($configured_home, $default_language));
                    if ('' !== $translated_home) {
                        $ordered_homes[$default_language] = $translated_home;
                    }
                }
            }
        } else {
            foreach ((array) $language_codes as $language_code) {
                if (isset($homes[$language_code])) {
                    $ordered_homes[$language_code] = $homes[$language_code];
                }
            }
            foreach ((array) $homes as $language_code => $url) {
                if (!isset($ordered_homes[$language_code])) {
                    $ordered_homes[$language_code] = $url;
                }
            }
        }
    }

    $targets = array();
    foreach ($ordered_homes as $url) {
        $url = trim((string) $url);
        if ('' === $url) {
            continue;
        }
        $parts = wp_parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? ultracache_normalize_host((string) ($parts['host'] ?? '')) : '';
        if (!in_array($scheme, array('http', 'https'), true) || '' === $host || !empty($parts['user']) || !empty($parts['pass'])) {
            continue;
        }
        $url = function_exists('esc_url_raw') ? (string) esc_url_raw($url, array('http', 'https')) : $url;
        if ('' !== $url) {
            $targets[$url] = $url;
        }
    }

    if (!empty($targets)) {
        return array_values($targets);
    }

    // An explicit operation with zero eligible languages must not silently
    // substitute another language. The dashboard returns a clear no-target result.
    if ('' !== $operation) {
        return array();
    }

    // A positively detected provider with an unavailable/empty URL contract
    // fails back to the historical target rather than inventing topology.
    return function_exists('home_url') ? array((string) home_url('/')) : array();
}

/**
 * Summarize multiple canonical homepage warm results while preserving the
 * exact historical single-target result shape on ordinary sites.
 *
 * @param array<int,array<string,mixed>> $results Per-target warm results.
 * @param string                         $label   Human-readable operation label.
 * @return array<string,mixed>
 */
function ultracache_summarize_frontpage_warm_results(array $results, $label = 'Front page warm')
{
    $results = array_values(array_filter($results, 'is_array'));
    if (1 === count($results)) {
        return $results[0];
    }
    if (empty($results)) {
        return array(
            'success' => false,
            'skipped' => false,
            'runtimeOutcome' => 'failed',
            'message' => (string) $label . ' produced no valid target result.',
            'targetCount' => 0,
            'targetResults' => array(),
        );
    }

    $success_count = 0;
    $skipped_count = 0;
    $failed_count = 0;
    $ownership_lost = false;
    $targets = array();
    foreach ($results as $result) {
        $url = trim((string) ($result['url'] ?? ''));
        if ('' !== $url) {
            $targets[] = $url;
        }
        if (!empty($result['ownershipLost'])) {
            $ownership_lost = true;
        }
        if (!empty($result['success'])) {
            ++$success_count;
        } elseif (!empty($result['skipped'])) {
            ++$skipped_count;
        } else {
            ++$failed_count;
        }
    }

    $target_count = count($results);
    $all_success = $success_count === $target_count;
    $has_acceptable = ($success_count + $skipped_count) === $target_count;
    $runtime_outcome = $failed_count > 0 ? 'failed' : ($all_success ? 'complete' : 'partial');

    return array(
        'success'         => $all_success || ($has_acceptable && $success_count > 0),
        'skipped'         => 0 === $success_count && $skipped_count === $target_count,
        'ownershipLost'   => $ownership_lost,
        'runtimeOutcome'  => $runtime_outcome,
        'message'         => sprintf(
            '%s: %d/%d completed, %d skipped, %d failed.',
            (string) $label,
            $success_count,
            $target_count,
            $skipped_count,
            $failed_count
        ),
        'url'             => isset($targets[0]) ? $targets[0] : '',
        'targets'         => array_values(array_unique($targets)),
        'targetCount'     => $target_count,
        'completedCount'  => $success_count,
        'skippedCount'    => $skipped_count,
        'failedCount'     => $failed_count,
        'targetResults'   => $results,
    );
}

/**
 * Check whether a public URL belongs to the configured/provider public site topology.
 *
 * This is a semantic membership helper, not the strict loopback security gate.
 * Strict ports and transport rules remain in ultracache_is_strict_frontend_loopback_url().
 *
 * @param string $url Public absolute, protocol-relative, or root-relative URL.
 * @return bool
 */
function ultracache_is_trusted_public_host($host)
{
    $host = ultracache_normalize_host($host);
    if ('' === $host) {
        return false;
    }

    $topology = ultracache_get_public_site_topology();
    return isset($topology['multilingualHosts'][$host]);
}

function ultracache_is_public_site_url($url)
{
    $url = trim((string) $url);
    if ('' === $url) {
        return false;
    }

    if (0 === strpos($url, '/') && 0 !== strpos($url, '//')) {
        return true;
    }

    if (0 === strpos($url, '//')) {
        $origin = function_exists('ultracache_get_configured_site_origin') ? ultracache_get_configured_site_origin() : '';
        $scheme = strtolower((string) wp_parse_url($origin, PHP_URL_SCHEME));
        if (!in_array($scheme, array('http', 'https'), true)) {
            return false;
        }
        $url = $scheme . ':' . $url;
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = ultracache_normalize_host((string) ($parts['host'] ?? ''));
    if (!in_array($scheme, array('http', 'https'), true) || '' === $host) {
        return false;
    }
    if (!empty($parts['user']) || !empty($parts['pass'])) {
        return false;
    }

    return ultracache_is_trusted_public_host($host);
}

function ultracache_get_trusted_hosts()
{
    $hosts = array();
    $topology = ultracache_get_public_site_topology();
    foreach ((array) ($topology['multilingualHosts'] ?? array()) as $host => $entry) {
        $normalized = ultracache_normalize_host($host);
        if ('' !== $normalized) {
            $hosts[$normalized] = true;
        }
    }

    if (empty($hosts)) {
        foreach (array(home_url('/'), site_url('/')) as $url) {
            $host = ultracache_normalize_host(wp_parse_url((string) $url, PHP_URL_HOST));
            if ('' !== $host) {
                $hosts[$host] = true;
            }
        }
    }

    $hosts = array_values(array_keys($hosts));
    sort($hosts, SORT_STRING);
    return $hosts;
}

function ultracache_get_validated_http_host($host, $context = '')
{
    $normalized = ultracache_normalize_host($host);
    if ('' === $normalized) {
        ultracache_debug_log('invalid host header', array('host' => (string) $host, 'context' => (string) $context));
        return '';
    }

    $trusted = array_fill_keys(ultracache_get_trusted_hosts(), true);
    if (empty($trusted) || !isset($trusted[$normalized])) {
        ultracache_debug_log('untrusted host header rejected', array('host' => $normalized, 'context' => (string) $context, 'trusted_hosts' => array_keys($trusted)));
        return '';
    }

    return $normalized;
}
