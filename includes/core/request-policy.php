<?php
/**
 * Canonical frontend request policy helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logged-in frontend requests must receive the normal WordPress output without
 * UltraCache frontend performance transformations.
 *
 * Browser Runtime Scan anonymous passes run in a credentialless browser frame,
 * so WordPress resolves them as anonymous from the beginning of the request.
 * No user impersonation is performed here or by the scanner.
 *
 * @return bool
 */
function ultracache_should_bypass_logged_in_frontend_optimizations()
{
    // Warm finalization may run inside an authenticated admin/REST request while
    // transforming HTML that was rendered by a separate anonymous frontend
    // source loopback. In that narrowly scoped context the source document is
    // public visitor HTML and must receive the normal frontend transformations.
    if (!empty($GLOBALS['ultracache_anonymous_public_cache_transform'])) {
        return false;
    }

    return function_exists('is_user_logged_in') && is_user_logged_in();
}

/**
 * Query arguments used only by Browser Runtime Scan control/diagnostic requests.
 *
 * @return array<int,string>
 */
function ultracache_runtime_js_scan_control_query_args()
{
    return array(
        'ultracache_runtime_js_scan',
        'ultracache_runtime_js_scan_id',
        'ultracache_runtime_js_scan_token',
        'ultracache_runtime_js_scan_nonce',
        'ultracache_runtime_js_scan_mode',
        'ultracache_runtime_js_scan_context',
        'ultracache_rt',
    );
}

/**
 * Normalize a same-site scan target while removing scanner-only query arguments.
 *
 * @param string $url Target URL.
 * @return string
 */
function ultracache_runtime_js_scan_normalize_target_url($url)
{
    $url = trim((string) $url);
    if ('' === $url) {
        return '';
    }

    if (0 === strpos($url, '/')) {
        $url = home_url($url);
    } elseif (!preg_match('#^https?://#i', $url)) {
        $url = home_url('/' . ltrim($url, '/'));
    }

    $url = esc_url_raw($url);
    if ('' === $url) {
        return '';
    }

    $home = wp_parse_url(home_url('/'));
    $parts = wp_parse_url($url);
    if (!is_array($home) || !is_array($parts) || empty($parts['host'])) {
        return '';
    }

    $home_scheme = strtolower((string) ($home['scheme'] ?? 'http'));
    $scheme = strtolower((string) ($parts['scheme'] ?? $home_scheme));
    $host = strtolower((string) $parts['host']);
    $port = isset($parts['port']) ? (int) $parts['port'] : 0;
    if (!in_array($scheme, array('http', 'https'), true)) {
        return '';
    }

    if (function_exists('ultracache_is_strict_frontend_loopback_url')) {
        if (!ultracache_is_strict_frontend_loopback_url($url)) {
            return '';
        }
    } else {
        $home_host = strtolower((string) ($home['host'] ?? ''));
        $home_port = isset($home['port']) ? (int) $home['port'] : 0;
        if ('' === $home_host || $host !== $home_host || $port !== $home_port) {
            return '';
        }
    }

    $clean = remove_query_arg(ultracache_runtime_js_scan_control_query_args(), $url);
    $parts = wp_parse_url($clean);
    if (!is_array($parts)) {
        return '';
    }

    $path = isset($parts['path']) && '' !== (string) $parts['path'] ? (string) $parts['path'] : '/';
    $query = array();
    if (!empty($parts['query'])) {
        parse_str((string) $parts['query'], $query);
        if (is_array($query)) {
            ksort($query);
        } else {
            $query = array();
        }
    }

    $clean_scheme = strtolower((string) ($parts['scheme'] ?? $scheme));
    $clean_host = strtolower((string) ($parts['host'] ?? $host));
    $clean_port = isset($parts['port']) ? (int) $parts['port'] : $port;
    if (!in_array($clean_scheme, array('http', 'https'), true) || '' === $clean_host) {
        return '';
    }

    $authority = $clean_scheme . '://' . $clean_host;
    if ($clean_port > 0) {
        $authority .= ':' . $clean_port;
    }
    $normalized = $authority . $path;
    if (!empty($query)) {
        $normalized .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return esc_url_raw($normalized);
}

/**
 * Build the current frontend request URL against the canonical home origin.
 *
 * @return string
 */
function ultracache_runtime_js_scan_current_request_url()
{
    $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_url(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '/';
    if ('' === $request_uri || '/' !== $request_uri[0]) {
        $request_uri = '/' . ltrim($request_uri, '/');
    }

    $home = wp_parse_url(home_url('/'));
    if (!is_array($home) || empty($home['host'])) {
        return '';
    }

    $url = strtolower((string) ($home['scheme'] ?? 'http')) . '://' . strtolower((string) $home['host']);
    if (!empty($home['port'])) {
        $url .= ':' . (int) $home['port'];
    }
    $url .= $request_uri;

    return ultracache_runtime_js_scan_normalize_target_url($url);
}

/**
 * Detect WordPress authentication cookies on a scanner request.
 *
 * @return bool
 */
function ultracache_runtime_js_scan_has_auth_cookie()
{
    foreach (array_keys((array) $_COOKIE) as $cookie_name) {
        $cookie_name = (string) $cookie_name;
        if (0 === strpos($cookie_name, 'wordpress_logged_in_') || 0 === strpos($cookie_name, 'wordpress_sec_')) {
            return true;
        }
        if (preg_match('/^wordpress_[a-f0-9]{32}$/i', $cookie_name)) {
            return true;
        }
    }

    return false;
}

/**
 * Return a stable token identity for a normalized frontend scan URL.
 *
 * WordPress canonical redirects commonly normalize a pretty-permalink path from
 * `/example` to `/example/`. The Browser Runtime Scan token must survive that
 * same-site canonical redirect, otherwise the redirected request cannot enable
 * the collector and the admin-side iframe waits forever for a report.
 *
 * Only the trailing slash of a non-root path is ignored. Scheme, host, port,
 * path content, and normalized query arguments remain part of the identity.
 *
 * @param string $url Target URL.
 * @return string
 */
function ultracache_runtime_js_scan_target_identity_url($url)
{
    $normalized = ultracache_runtime_js_scan_normalize_target_url($url);
    if ('' === $normalized) {
        return '';
    }

    $parts = wp_parse_url($normalized);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $path = isset($parts['path']) && '' !== (string) $parts['path'] ? (string) $parts['path'] : '/';
    if ('/' !== $path) {
        $path = untrailingslashit($path);
        if ('' === $path) {
            $path = '/';
        }
    }

    $identity = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
    if (!empty($parts['port'])) {
        $identity .= ':' . (int) $parts['port'];
    }
    $identity .= $path;
    if (!empty($parts['query'])) {
        $identity .= '?' . (string) $parts['query'];
    }

    return esc_url_raw($identity);
}

/**
 * Mint a short-lived, capability-free token that can only enable one anonymous
 * Browser Runtime Scan collector for one scan id and one same-site target URL.
 *
 * @param string $scan_id Scan id.
 * @param string $target_url Target URL.
 * @return string
 */
function ultracache_runtime_js_scan_mint_token($scan_id, $target_url)
{
    $scan_id = sanitize_key((string) $scan_id);
    $target_url = ultracache_runtime_js_scan_normalize_target_url($target_url);
    $target_identity = ultracache_runtime_js_scan_target_identity_url($target_url);
    if ('' === $scan_id || strlen($scan_id) > 64 || '' === $target_url || '' === $target_identity) {
        return '';
    }

    $token = wp_generate_password(48, false, false);
    if ('' === $token) {
        return '';
    }

    $ttl = 300;
    $record = array(
        'scan_id'        => $scan_id,
        'target_hash'    => hash('sha256', $target_identity),
        'expires_at'     => time() + $ttl,
        'remaining_uses' => 3,
    );
    $key = 'ultracache_rtscan_' . hash('sha256', $token);

    if (!set_transient($key, $record, $ttl)) {
        return '';
    }

    return $token;
}

/**
 * Verify and consume one use of an anonymous Browser Runtime Scan token.
 *
 * @param string $token Token.
 * @param string $scan_id Scan id.
 * @param string $request_url Current request URL.
 * @return bool
 */
function ultracache_runtime_js_scan_verify_token($token, $scan_id, $request_url)
{
    $token = sanitize_text_field((string) $token);
    $scan_id = sanitize_key((string) $scan_id);
    $request_url = ultracache_runtime_js_scan_normalize_target_url($request_url);
    if ('' === $token || '' === $scan_id || '' === $request_url) {
        return false;
    }

    $key = 'ultracache_rtscan_' . hash('sha256', $token);
    $record = get_transient($key);
    if (!is_array($record)) {
        return false;
    }

    $stored_scan_id = sanitize_key((string) ($record['scan_id'] ?? ''));
    $stored_target_hash = (string) ($record['target_hash'] ?? '');
    $request_identity = ultracache_runtime_js_scan_target_identity_url($request_url);
    $expires_at = (int) ($record['expires_at'] ?? 0);
    $remaining_uses = (int) ($record['remaining_uses'] ?? 0);

    if ('' === $stored_scan_id || !hash_equals($stored_scan_id, $scan_id)) {
        return false;
    }
    if ('' === $stored_target_hash || '' === $request_identity || !hash_equals($stored_target_hash, hash('sha256', $request_identity))) {
        return false;
    }
    if ($expires_at < time() || $remaining_uses < 1) {
        delete_transient($key);
        return false;
    }

    $remaining_uses--;
    if ($remaining_uses < 1) {
        delete_transient($key);
    } else {
        $record['remaining_uses'] = $remaining_uses;
        set_transient($key, $record, max(1, $expires_at - time()));
    }

    return true;
}


/**
 * Normalize one cookie-pattern list shared by request and response policy.
 *
 * @param array|string $patterns Cookie-name patterns.
 * @return array<int,string>
 */
function ultracache_normalize_cookie_pattern_list($patterns)
{
    $normalized = array();
    foreach ((array) $patterns as $pattern) {
        if (is_array($pattern) || is_object($pattern)) {
            continue;
        }

        $pattern = trim((string) $pattern);
        if ('' === $pattern) {
            continue;
        }

        $pattern = preg_replace('/[\x00-\x1F\x7F]/', '', $pattern);
        $pattern = is_string($pattern) ? preg_replace('/[^A-Za-z0-9_\-.\*]/', '', $pattern) : '';
        $pattern = trim((string) $pattern);
        if ('' === $pattern || '*' === $pattern) {
            continue;
        }

        $normalized[strtolower($pattern)] = $pattern;
    }

    return array_values($normalized);
}

/**
 * Match one cookie name against one UltraCache cookie pattern.
 *
 * @param string $cookie_name Cookie name.
 * @param string $pattern     Pattern.
 * @return bool
 */
function ultracache_cookie_name_matches_pattern($cookie_name, $pattern)
{
    $cookie_name = strtolower(trim((string) $cookie_name));
    $pattern = strtolower(trim((string) $pattern));
    if ('' === $cookie_name || '' === $pattern || '*' === $pattern) {
        return false;
    }

    if (false !== strpos($pattern, '*')) {
        $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/i';
        return 1 === preg_match($regex, $cookie_name);
    }

    return false !== strpos($cookie_name, $pattern);
}

/**
 * Match one cookie name against any UltraCache cookie pattern.
 *
 * @param string $cookie_name Cookie name.
 * @param array  $patterns    Patterns.
 * @return bool
 */
function ultracache_cookie_name_matches_any_pattern($cookie_name, array $patterns)
{
    foreach ($patterns as $pattern) {
        if (ultracache_cookie_name_matches_pattern($cookie_name, $pattern)) {
            return true;
        }
    }

    return false;
}

/**
 * Whether every cookie name matches the supplied pattern set.
 *
 * @param array $cookie_names Cookie names.
 * @param array $patterns     Patterns.
 * @return bool
 */
function ultracache_all_cookie_names_match_patterns(array $cookie_names, array $patterns)
{
    if (empty($cookie_names) || empty($patterns)) {
        return false;
    }

    foreach ($cookie_names as $cookie_name) {
        if (!ultracache_cookie_name_matches_any_pattern($cookie_name, $patterns)) {
            return false;
        }
    }

    return true;
}

/**
 * Return the canonical private/auth/cart response-cookie patterns.
 *
 * This is the single Policy v2 response-cookie source used by page storage and
 * outer-cache clean handoff. Extensions may add patterns through the existing
 * WordPress filter without teaching Varnish or LiteSpeed a second list.
 *
 * @param array $settings Runtime settings.
 * @return array<int,string>
 */
function ultracache_get_response_cookie_reject_patterns(array $settings)
{
    $patterns = array(
        'wordpress_logged_in_',
        'wordpress_sec_',
        'wp-postpass_',
        'woocommerce_items_in_cart',
        'woocommerce_cart_hash',
        'wp_woocommerce_session_',
    );

    $patterns = apply_filters('ultracache_response_cookie_reject_patterns', $patterns, $settings);
    return ultracache_normalize_cookie_pattern_list($patterns);
}

/**
 * Classify response cookie names using Cacheability Policy v2.
 *
 * @param array $cookie_names Cookie names only; values are never inspected or persisted.
 * @param array $settings     Runtime settings.
 * @return array<string,mixed>
 */
function ultracache_response_cookie_cache_policy(array $cookie_names, array $settings)
{
    $cookie_names = array_values(array_unique(array_filter(array_map('strval', $cookie_names))));
    if (empty($cookie_names)) {
        return array(
            'decision' => 'none',
            'reason' => 'no-response-cookies',
            'matchedCookies' => array(),
            'knownSafe' => false,
        );
    }

    if (empty($settings['cache_safe_tracking_cookies'])) {
        return array(
            'decision' => 'reject',
            'reason' => 'strict-response-cookie-policy',
            'matchedCookies' => $cookie_names,
            'knownSafe' => false,
        );
    }

    $reject_patterns = ultracache_get_response_cookie_reject_patterns($settings);
    $matched = array();
    foreach ($cookie_names as $cookie_name) {
        if (ultracache_cookie_name_matches_any_pattern($cookie_name, $reject_patterns)) {
            $matched[] = (string) $cookie_name;
        }
    }

    if (!empty($matched)) {
        return array(
            'decision' => 'reject',
            'reason' => 'private-response-cookie',
            'matchedCookies' => array_values(array_unique($matched)),
            'knownSafe' => false,
        );
    }

    $safe_patterns = ultracache_normalize_cookie_pattern_list(
        !empty($settings['safe_tracking_cookie_patterns']) && is_array($settings['safe_tracking_cookie_patterns'])
            ? $settings['safe_tracking_cookie_patterns']
            : array()
    );
    $known_safe = !empty($safe_patterns) && ultracache_all_cookie_names_match_patterns($cookie_names, $safe_patterns);

    return array(
        'decision' => 'allow',
        'reason' => $known_safe ? 'known-safe-response-cookie' : 'public-response-cookie',
        'matchedCookies' => array(),
        'knownSafe' => $known_safe,
    );
}

/**
 * Extract cookie names from one or more Set-Cookie header values.
 *
 * @param string|array $header_values Set-Cookie values.
 * @return array<int,string>
 */
function ultracache_extract_set_cookie_names($header_values)
{
    $names = array();
    foreach ((array) $header_values as $header_value) {
        $header_value = trim((string) $header_value);
        if ('' === $header_value) {
            continue;
        }

        // Requests normally preserves Set-Cookie as separate values. The regex
        // also tolerates a proxy/client that joined multiple cookie values.
        if (preg_match_all('/(?:^|,\s*)([!#$%&\'*+\-.^_`|~0-9A-Za-z]+)=/', $header_value, $matches)) {
            foreach ((array) ($matches[1] ?? array()) as $name) {
                $name = preg_replace('/[^A-Za-z0-9_\-.]/', '', (string) $name);
                if ('' !== $name) {
                    $names[$name] = $name;
                }
            }
        }
    }

    return array_values($names);
}

/**
 * Extract Set-Cookie names from one WordPress HTTP API response.
 *
 * @param array|WP_Error $response WordPress HTTP response.
 * @return array<int,string>
 */
function ultracache_get_http_response_set_cookie_names($response)
{
    if (is_wp_error($response)) {
        return array();
    }

    return ultracache_extract_set_cookie_names(wp_remote_retrieve_header($response, 'set-cookie'));
}
