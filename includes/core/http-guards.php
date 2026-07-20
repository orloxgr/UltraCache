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
    $args['timeout'] = max(1, min(60, (int) $args['timeout']));
    $args['reject_unsafe_urls'] = true;

    $response = wp_safe_remote_request($url, $args);
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


function ultracache_get_loopback_ssl_status()
{
    $status = get_transient('ultracache_loopback_ssl_status_v1');
    if (!is_array($status)) {
        $status = array();
    }

    return wp_parse_args($status, array(
        'strictByDefault' => true,
        'fallbackUsed'    => false,
        'lastUrl'         => '',
        'lastError'       => '',
        'context'         => '',
        'message'         => '',
        'updatedAt'       => 0,
    ));
}

function ultracache_set_loopback_ssl_status(array $status)
{
    set_transient('ultracache_loopback_ssl_status_v1', $status, DAY_IN_SECONDS);
}

function ultracache_reset_loopback_ssl_status()
{
    delete_transient('ultracache_loopback_ssl_status_v1');
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

    foreach (array(home_url('/'), site_url('/')) as $site_url) {
        $parts = wp_parse_url((string) $site_url);
        if (!is_array($parts)) {
            continue;
        }

        $site_scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $site_host = isset($parts['host']) ? ultracache_normalize_host((string) $parts['host']) : '';
        if ($site_scheme !== $scheme || $site_host !== $host) {
            continue;
        }

        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port > 0 && $port <= 65535) {
                $ports[$port] = true;
            }
        }
    }

    return array_map('intval', array_keys($ports));
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

function ultracache_get_trusted_hosts()
{
    $hosts = array();
    foreach (array(home_url('/'), site_url('/')) as $url) {
        $host = ultracache_normalize_host(wp_parse_url((string) $url, PHP_URL_HOST));
        if ('' !== $host) {
            $hosts[$host] = true;
        }
    }

    return array_values(array_keys($hosts));
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
