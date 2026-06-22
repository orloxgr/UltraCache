<?php
/**
 * UltraCache advanced-cache drop-in.
 * Marker: UltraCache advanced-cache drop-in
 * Drop-in Build: __ULTRACACHE_DROPIN_BUILD__
 * Embedded Runtime Config Hash: __ULTRACACHE_RUNTIME_CONFIG_HASH__
 */
if (!defined('ABSPATH')) {
    return;
}

function ultracache_advanced_cache_guard_normalize_path($path) {
    $path = is_string($path) ? trim($path) : '';
    if ('' === $path || false !== strpos($path, "\0")) {
        return '';
    }
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path);
    return is_string($path) ? rtrim($path, '/') : '';
}

function ultracache_advanced_cache_guard_resolve_path($path, $must_exist = false) {
    $path = is_string($path) ? trim($path) : '';
    if ('' === $path || false !== strpos($path, "\0")) {
        return '';
    }
    $real = function_exists('realpath') ? realpath($path) : false;
    if (is_string($real) && '' !== $real) {
        return ultracache_advanced_cache_guard_normalize_path($real);
    }
    if ($must_exist) {
        return '';
    }
    $parent = dirname($path);
    $leaf = basename($path);
    if ('' === $leaf || '.' === $leaf || '..' === $leaf) {
        return '';
    }
    $real_parent = function_exists('realpath') ? realpath($parent) : false;
    if (is_string($real_parent) && '' !== $real_parent) {
        return ultracache_advanced_cache_guard_normalize_path(rtrim($real_parent, '/\\') . DIRECTORY_SEPARATOR . $leaf);
    }
    return ultracache_advanced_cache_guard_normalize_path($path);
}

function ultracache_advanced_cache_allowed_file_roots() {
    return array(__ULTRACACHE_CACHE_DIR__);
}

function ultracache_advanced_cache_is_allowed_file_path($path, $must_exist = false) {
    $resolved = ultracache_advanced_cache_guard_resolve_path($path, (bool) $must_exist);
    if ('' === $resolved) {
        return false;
    }
    foreach (ultracache_advanced_cache_allowed_file_roots() as $root) {
        $root = ultracache_advanced_cache_guard_resolve_path($root, false);
        if ('' === $root) {
            continue;
        }
        if ($resolved === $root || 0 === strpos($resolved, $root . '/')) {
            return true;
        }
    }
    return false;
}

function ultracache_advanced_cache_safe_file_get_contents($file) {
    return ultracache_advanced_cache_is_allowed_file_path($file, true) && is_readable($file) ? file_get_contents($file) : false;
}

function ultracache_advanced_cache_safe_file_put_contents($file, $data, $flags = 0, $context = '') {
    $file = is_string($file) ? trim($file) : '';
    if ('' === $file || !ultracache_advanced_cache_is_allowed_file_path($file, false)) {
        return false;
    }
    $dir = dirname($file);
    if ('' !== $dir && '.' !== $dir && !is_dir($dir)) {
        ultracache_advanced_cache_safe_mkdir($dir, 0755, true);
    }
    if ('' !== $dir && '.' !== $dir && (!is_dir($dir) || !is_writable($dir))) {
        if (is_dir($dir)) {
            @chmod($dir, 0755);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }
    }
    return @file_put_contents($file, $data, $flags);
}
function ultracache_advanced_cache_safe_unlink($file) {
    if (!ultracache_advanced_cache_is_allowed_file_path($file, false)) {
        return false;
    }
    return !file_exists($file) ? true : @unlink($file);
}

function ultracache_advanced_cache_safe_rename($from, $to) {
    if (!ultracache_advanced_cache_is_allowed_file_path($from, true) || !ultracache_advanced_cache_is_allowed_file_path($to, false)) {
        return false;
    }
    return @rename($from, $to);
}

function ultracache_advanced_cache_safe_mkdir($dir, $mode = 0755, $recursive = true) {
    $dir = is_string($dir) ? trim($dir) : '';
    if ('' === $dir || !ultracache_advanced_cache_is_allowed_file_path($dir, false)) {
        return false;
    }
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, $mode, $recursive) || is_dir($dir);
}


function ultracache_advanced_cache_safe_filemtime($file) {
    return ultracache_advanced_cache_is_allowed_file_path($file, true) && is_file($file) ? filemtime($file) : false;
}

function ultracache_advanced_cache_safe_readfile($file, $cache_base_dir) {
    if (!ultracache_advanced_cache_is_valid_cache_payload_file($file, $cache_base_dir) || !is_readable($file)) {
        return false;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile, WordPress.Security.EscapeOutput.OutputNotEscaped -- Early advanced-cache drop-in streams a validated full-page cache payload before WordPress is loaded.
    return readfile($file);
}

function ultracache_advanced_cache_safe_stream_socket_client($remote_socket, $timeout, &$errno = null, &$errstr = null, $flags = STREAM_CLIENT_CONNECT) {
    $timeout = max(0.05, (float) $timeout);
    return @stream_socket_client($remote_socket, $errno, $errstr, $timeout, $flags);
}


function ultracache_advanced_cache_debug_headers_enabled() {
    global $runtime_config;
    if (empty($runtime_config['debug_headers_enabled'])) {
        return false;
    }
    $flag = isset($_SERVER['HTTP_X_ULTRACACHE_DEBUG']) ? strtolower(trim((string) $_SERVER['HTTP_X_ULTRACACHE_DEBUG'])) : '';
    return in_array($flag, array('1', 'true', 'yes', 'on'), true);
}

function ultracache_advanced_cache_normalize_cache_path($path) {
    $path = is_string($path) ? trim($path) : '';
    if ('' === $path) {
        return '';
    }
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path);
    return is_string($path) ? rtrim($path, '/') : '';
}

function ultracache_advanced_cache_resolve_cache_path_for_compare($path, $must_exist = false) {
    $path = is_string($path) ? trim($path) : '';
    if ('' === $path) {
        return '';
    }

    if (function_exists('realpath')) {
        $real = realpath($path);
        if (is_string($real) && '' !== $real) {
            return ultracache_advanced_cache_normalize_cache_path($real);
        }

        if (!$must_exist) {
            $parent = dirname($path);
            $leaf = basename($path);
            $real_parent = realpath($parent);
            if (is_string($real_parent) && '' !== $real_parent && '' !== $leaf && '.' !== $leaf && '..' !== $leaf) {
                return ultracache_advanced_cache_normalize_cache_path(rtrim($real_parent, '/\\') . DIRECTORY_SEPARATOR . $leaf);
            }
        }

        if ($must_exist) {
            return '';
        }
    }

    return ultracache_advanced_cache_normalize_cache_path($path);
}

function ultracache_advanced_cache_is_path_within_base($path, $base_dir, $must_exist = false) {
    $resolved_path = ultracache_advanced_cache_resolve_cache_path_for_compare($path, (bool) $must_exist);
    $resolved_base = ultracache_advanced_cache_resolve_cache_path_for_compare($base_dir, true);
    if ('' === $resolved_path || '' === $resolved_base) {
        return false;
    }
    return $resolved_path === $resolved_base || 0 === strpos($resolved_path, $resolved_base . '/');
}

function ultracache_advanced_cache_is_cache_path($path, $base_dir, $must_exist = false) {
    $path = is_string($path) ? trim($path) : '';
    $base_dir = is_string($base_dir) ? trim($base_dir) : '';
    if ('' === $path || '' === $base_dir) {
        return false;
    }
    return ultracache_advanced_cache_is_path_within_base($path, $base_dir, (bool) $must_exist);
}

function ultracache_advanced_cache_is_valid_cache_payload_file($path, $base_dir) {
    if (!ultracache_advanced_cache_is_cache_path($path, $base_dir, true)) {
        return false;
    }

    $resolved = ultracache_advanced_cache_resolve_cache_path_for_compare($path, true);
    if ('' === $resolved) {
        return false;
    }

    return 1 === preg_match(
        '/\Aindex-(?:orig|avif|webp)-[a-f0-9]{32}\.html(?:\.(?:gz|br))?\z/',
        basename($resolved)
    );
}

function ultracache_advanced_cache_create_runtime_control_token($secret, $issued_at = null) {
    $secret = is_string($secret) ? trim($secret) : '';
    if ('' === $secret) {
        return '';
    }
    $issued_at = null === $issued_at ? time() : (int) $issued_at;
    if ($issued_at <= 0) {
        return '';
    }
    $payload = 'v2|' . (string) $issued_at . '|ultracache-runtime-control';
    $mac = hash_hmac('sha256', $payload, $secret);
    return 'v2:' . (string) $issued_at . ':' . $mac;
}

function ultracache_advanced_cache_validate_runtime_control_token($token, $secret, $ttl = 900) {
    $token = is_scalar($token) ? trim((string) $token) : '';
    $secret = is_string($secret) ? trim($secret) : '';
    if ('' === $token || '' === $secret || strlen($token) > 160) {
        return false;
    }
    $parts = explode(':', $token);
    if (3 !== count($parts) || 'v2' !== $parts[0]) {
        return false;
    }
    $issued_at = (int) $parts[1];
    $mac = (string) $parts[2];
    $ttl = max(60, min(3600, (int) $ttl));
    $now = time();
    if ($issued_at <= 0 || $issued_at > ($now + 60) || ($now - $issued_at) > $ttl) {
        return false;
    }
    if (1 !== preg_match('/^[a-f0-9]{64}$/', $mac)) {
        return false;
    }
    $expected = hash_hmac('sha256', 'v2|' . (string) $issued_at . '|ultracache-runtime-control', $secret);
    return function_exists('hash_equals') ? hash_equals($expected, $mac) : $expected === $mac;
}

function ultracache_advanced_cache_runtime_control_secret() {
    $constant_names = array(
        'AUTH_KEY',
        'AUTH_SALT',
        'SECURE_AUTH_KEY',
        'SECURE_AUTH_SALT',
        'LOGGED_IN_KEY',
        'LOGGED_IN_SALT',
        'NONCE_KEY',
        'NONCE_SALT',
    );
    $material = array();
    foreach ($constant_names as $constant_name) {
        if (!defined($constant_name)) {
            continue;
        }
        $value = constant($constant_name);
        if (!is_scalar($value)) {
            continue;
        }
        $value = (string) $value;
        if ('' === $value || false !== stripos($value, 'put your unique phrase here')) {
            continue;
        }
        $material[] = $constant_name . '=' . $value;
    }
    return empty($material)
        ? ''
        : hash_hmac('sha256', 'ultracache-revalidate-v1', implode('|', $material));
}

function ultracache_advanced_cache_redis_credentials() {
    $username = '';
    $password = '';
    if (defined('WP_REDIS_PASSWORD')) {
        $value = constant('WP_REDIS_PASSWORD');
        if (is_array($value)) {
            if (array_key_exists(0, $value) && is_scalar($value[0])) {
                $username = trim((string) $value[0]);
            } elseif (isset($value['username']) && is_scalar($value['username'])) {
                $username = trim((string) $value['username']);
            } elseif (isset($value['user']) && is_scalar($value['user'])) {
                $username = trim((string) $value['user']);
            }
            if (array_key_exists(1, $value) && is_scalar($value[1])) {
                $password = (string) $value[1];
            } elseif (isset($value['password']) && is_scalar($value['password'])) {
                $password = (string) $value['password'];
            }
        } elseif (is_scalar($value)) {
            $password = (string) $value;
        }
    }
    if ('' === $username && defined('WP_REDIS_USERNAME')) {
        $value = constant('WP_REDIS_USERNAME');
        $username = is_scalar($value) ? trim((string) $value) : '';
    }
    return array('username' => $username, 'password' => $password);
}

function ultracache_advanced_cache_clean_server_text($value) {
    if (is_array($value) || is_object($value)) {
        return '';
    }
    $value = (string) $value;
    $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
    return is_string($value) ? trim($value) : '';
}

function ultracache_advanced_cache_server_var($key, $default = '') {
    if (!isset($_SERVER[$key])) {
        return $default;
    }
    $value = ultracache_advanced_cache_clean_server_text($_SERVER[$key]);
    return '' === $value ? $default : $value;
}

function ultracache_advanced_cache_normalize_request_uri($uri) {
    $uri = ultracache_advanced_cache_clean_server_text($uri);
    if ('' === $uri) {
        return '';
    }
    if (strlen($uri) > 8192) {
        $uri = substr($uri, 0, 8192);
    }
    if (false !== strpos($uri, '://')) {
        $parsed = parse_url($uri);
        if (!is_array($parsed)) {
            return '';
        }
        $uri = (!empty($parsed['path']) ? (string) $parsed['path'] : '/');
        if (!empty($parsed['query'])) {
            $uri .= '?' . (string) $parsed['query'];
        }
    }
    if ('/' !== substr($uri, 0, 1)) {
        $uri = '/' . ltrim($uri, '/');
    }
    return $uri;
}

$ultracache_read_file = static function ($file) {
    return ultracache_advanced_cache_safe_file_get_contents($file);
};
$ultracache_write_file = static function ($file, $data, $flags = 0) {
    return ultracache_advanced_cache_safe_file_put_contents($file, $data, $flags);
};
$ultracache_delete_file = static function ($file) {
    return ultracache_advanced_cache_safe_unlink($file);
};
$ultracache_move_file = static function ($from, $to) {
    return ultracache_advanced_cache_safe_rename($from, $to);
};
$ultracache_make_dir = static function ($dir, $mode = 0755, $recursive = true) {
    return ultracache_advanced_cache_safe_mkdir($dir, $mode, $recursive);
};
$ultracache_get_filemtime = static function ($file) {
    return ultracache_advanced_cache_safe_filemtime($file);
};
$ultracache_cache_base_dir = __ULTRACACHE_CACHE_DIR__;
$ultracache_advanced_cache_safe_readfile = static function ($file) use ($ultracache_cache_base_dir) {
    return ultracache_advanced_cache_safe_readfile($file, $ultracache_cache_base_dir);
};
$ultracache_advanced_cache_is_cache_path = static function ($path, $base_dir = null, $must_exist = false) use ($ultracache_cache_base_dir) {
    $base_dir = is_string($base_dir) && '' !== trim($base_dir) ? $base_dir : $ultracache_cache_base_dir;
    return ultracache_advanced_cache_is_cache_path($path, $base_dir, (bool) $must_exist);
};

$method = strtoupper((string) ultracache_advanced_cache_server_var('REQUEST_METHOD', 'GET'));
if (!in_array($method, array('GET', 'HEAD'), true)) {
    return;
}

$raw_http_host = ultracache_advanced_cache_server_var('HTTP_HOST', '');
$request_uri = ultracache_advanced_cache_normalize_request_uri(ultracache_advanced_cache_server_var('REQUEST_URI', ''));
if ('' === $raw_http_host || '' === $request_uri) {
    return;
}
if (strpos($request_uri, '/wp-admin/') === 0 || strpos($request_uri, '/wp-login.php') === 0) {
    return;
}

foreach (array_keys((array) ($_COOKIE ?? array())) as $cookie_name) {
    $cookie_name = ultracache_advanced_cache_clean_server_text($cookie_name);
    foreach (array(
        'wordpress_logged_in_',
        'wordpress_sec_',
        'comment_author_',
        'wp-postpass_',
        'woocommerce_items_in_cart',
        'woocommerce_cart_hash',
        'wp_woocommerce_session_',
    ) as $needle) {
        if (false !== strpos((string) $cookie_name, $needle)) {
            return;
        }
    }
}

$runtime_config = array(
    'excluded_paths' => array(
        '/cart/',
        '/checkout/',
        '/my-account/',
        '/wp-admin/',
        '/wp-login.php',
        '/wc-api/',
        '/wp-json/',
    ),
    'excluded_query_args' => array(
        'preview',
        'customize_changeset_uuid',
        'customize_autosaved',
        'elementor-preview',
        'vc_editable',
        'et_fb',
        'add-to-cart',
        'wc-ajax',
        'remove_item',
        'undo_item',
        'apply_coupon',
        'remove_coupon',
        'order_again',
        'rest_route',
        'ultracache_revalidate',
        'ultracache_rt',
        'ultracache_store_profile',
        'ultracache_callback_profile',
        'ultracache_store_profile_verbose',
        'ultracache_store_profile_verbose_settings',
        'ultracache_profile_bypass',
        'ultracache_profile_run',
        'ultracache_runtime_js_scan',
        'ultracache_runtime_js_scan_id',
        'ultracache_runtime_js_scan_nonce',
    ),
    'cache_query_strings'            => false,
    'cache_query_allowlist'          => array(),
    'cache_safe_tracking_cookies'    => true,
    'safe_tracking_cookie_patterns'  => array(),
    'unsafe_cache_cookie_patterns'   => array(
        'wordpress_logged_in_',
        'wordpress_sec_',
        'comment_author_',
        'wp-postpass_',
        'woocommerce_items_in_cart',
        'woocommerce_cart_hash',
        'wp_woocommerce_session_',
    ),
    'woo_safe_mode'                  => false,
    'cache_stats_enabled'            => false,
    'stale_while_revalidate_enabled' => false,
    'cache_fresh_ttl_minutes'        => 15,
    'cache_max_stale_minutes'        => 720,
    'trusted_hosts'                  => array(),
    'object_cache_enabled'           => false,
    'object_cache_backend'           => 'redis',
    'redis_host'                     => '127.0.0.1',
    'redis_port'                     => 6379,
    'redis_username'                 => '',
    'redis_database'                 => 0,
    'redis_prefix'                   => '',
    'redis_use_tls'                  => false,
    'redis_persistent'               => false,
    'redis_connect_timeout_ms'       => 200,
    'redis_read_timeout_ms'          => 200,
);
$ultracache_normalize_runtime_string_list = static function ($value, $pattern = null) {
    $items = is_array($value) ? $value : preg_split('/\r?\n/', (string) $value);
    if (!is_array($items)) {
        return array();
    }

    $normalized = array();
    foreach ($items as $item) {
        if (is_array($item) || is_object($item)) {
            continue;
        }
        $item = trim((string) $item);
        if ('' === $item) {
            continue;
        }
        $item = preg_replace('/[\x00-\x1F\x7F]/', '', $item);
        $item = is_string($item) ? trim($item) : '';
        if ('' === $item) {
            continue;
        }
        if (is_string($pattern) && '' !== $pattern) {
            $item = preg_replace($pattern, '', strtolower($item));
            $item = is_string($item) ? trim($item) : '';
            if ('' === $item) {
                continue;
            }
        }
        $normalized[$item] = true;
    }

    $normalized = array_keys($normalized);
    sort($normalized);
    return $normalized;
};
$ultracache_normalize_runtime_path = static function ($path) {
    $path = '/' . ltrim((string) $path, '/');
    return '/' === $path ? '/' : rtrim($path, '/') . '/';
};
$ultracache_normalize_runtime_path_list = static function ($value) use ($ultracache_normalize_runtime_string_list, $ultracache_normalize_runtime_path) {
    $paths = array();
    foreach ($ultracache_normalize_runtime_string_list($value) as $path_rule) {
        $wildcard = '*' === substr($path_rule, -1);
        if ($wildcard) {
            $path_rule = substr($path_rule, 0, -1);
        }
        $path_rule = $ultracache_normalize_runtime_path($path_rule);
        if ('/' === $path_rule) {
            continue;
        }
        $paths[$path_rule . ($wildcard ? '*' : '')] = true;
    }

    $paths = array_keys($paths);
    sort($paths);
    return $paths;
};
$ultracache_normalize_runtime_config = static function ($config) use ($runtime_config, $ultracache_normalize_runtime_string_list, $ultracache_normalize_runtime_path_list) {
    $config = is_array($config) ? $config : array();

    $cache_query_allowlist = $ultracache_normalize_runtime_string_list($config['cache_query_allowlist'] ?? $runtime_config['cache_query_allowlist'], '/[^a-z0-9_-]/');
    $fresh_ttl_minutes = isset($config['cache_fresh_ttl_minutes']) ? (int) $config['cache_fresh_ttl_minutes'] : (int) $runtime_config['cache_fresh_ttl_minutes'];
    $fresh_ttl_minutes = max(1, min(1440, $fresh_ttl_minutes));
    $max_stale_minutes = isset($config['cache_max_stale_minutes']) ? (int) $config['cache_max_stale_minutes'] : (int) $runtime_config['cache_max_stale_minutes'];
    $max_stale_minutes = max($fresh_ttl_minutes, min(10080, $max_stale_minutes));

    return array(
        'excluded_paths'                 => $ultracache_normalize_runtime_path_list($config['excluded_paths'] ?? $runtime_config['excluded_paths']),
        'excluded_query_args'            => $ultracache_normalize_runtime_string_list($config['excluded_query_args'] ?? $runtime_config['excluded_query_args'], '/[^a-z0-9_-]/'),
        'cache_query_strings'            => !empty($config['cache_query_strings']),
        'cache_query_allowlist'          => $cache_query_allowlist,
        'cache_safe_tracking_cookies'    => array_key_exists('cache_safe_tracking_cookies', (array) $config) ? !empty($config['cache_safe_tracking_cookies']) : !empty($runtime_config['cache_safe_tracking_cookies']),
        'safe_tracking_cookie_patterns'  => $ultracache_normalize_runtime_string_list($config['safe_tracking_cookie_patterns'] ?? $runtime_config['safe_tracking_cookie_patterns'], '/[^a-z0-9_\-.\*]/'),
        'unsafe_cache_cookie_patterns'   => $ultracache_normalize_runtime_string_list($config['unsafe_cache_cookie_patterns'] ?? $runtime_config['unsafe_cache_cookie_patterns'], '/[^a-z0-9_\-.\*]/'),
        'woo_safe_mode'                  => !empty($config['woo_safe_mode']),
        'cache_stats_enabled'            => !empty($config['cache_stats_enabled']),
        'stale_while_revalidate_enabled' => !empty($config['stale_while_revalidate_enabled']),
        'cache_fresh_ttl_minutes'        => $fresh_ttl_minutes,
        'cache_max_stale_minutes'        => $max_stale_minutes,
        'trusted_hosts'                  => $ultracache_normalize_runtime_string_list($config['trusted_hosts'] ?? $runtime_config['trusted_hosts']),
        'object_cache_enabled'           => !empty($config['object_cache_enabled']),
        'object_cache_backend'           => in_array(strtolower(trim((string) ($config['object_cache_backend'] ?? 'redis'))), array('redis', 'apcu', 'disk'), true) ? strtolower(trim((string) ($config['object_cache_backend'] ?? 'redis'))) : 'redis',
        'redis_host'                     => isset($config['redis_host']) && is_scalar($config['redis_host']) ? trim((string) $config['redis_host']) : '127.0.0.1',
        'redis_port'                     => max(1, min(65535, (int) ($config['redis_port'] ?? 6379))),
        'redis_username'                 => isset($config['redis_username']) && is_scalar($config['redis_username']) ? trim((string) $config['redis_username']) : '',
        'redis_database'                 => max(0, (int) ($config['redis_database'] ?? 0)),
        'redis_prefix'                   => isset($config['redis_prefix']) && is_scalar($config['redis_prefix']) ? preg_replace('/[^A-Za-z0-9:_\-]/', '', (string) $config['redis_prefix']) : '',
        'redis_use_tls'                  => !empty($config['redis_use_tls']),
        'redis_persistent'               => !empty($config['redis_persistent']),
        'redis_connect_timeout_ms'       => max(50, min(15000, (int) ($config['redis_connect_timeout_ms'] ?? 200))),
        'redis_read_timeout_ms'          => max(50, min(15000, (int) ($config['redis_read_timeout_ms'] ?? 200))),
    );
};
$embedded_runtime_config = json_decode(__ULTRACACHE_RUNTIME_CONFIG_JSON__, true);
if (is_array($embedded_runtime_config)) {
    $runtime_config = array_merge($runtime_config, $embedded_runtime_config);
}
$runtime_config = $ultracache_normalize_runtime_config($runtime_config);

$ultracache_runtime_control_secret = ultracache_advanced_cache_runtime_control_secret();
$ultracache_redis_credentials = ultracache_advanced_cache_redis_credentials();

$ultracache_cookie_name_matches_pattern = static function ($cookie_name, $pattern) {
    $cookie_name = strtolower(trim((string) $cookie_name));
    $pattern = strtolower(trim((string) $pattern));
    if ('' === $cookie_name || '' === $pattern || '*' === $pattern) {
        return false;
    }

    if (false !== strpos($pattern, '*')) {
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
        return 1 === preg_match($regex, $cookie_name);
    }

    return false !== strpos($cookie_name, $pattern);
};

$ultracache_cookie_name_matches_any_pattern = static function ($cookie_name, $patterns) use ($ultracache_cookie_name_matches_pattern) {
    foreach ((array) $patterns as $pattern) {
        if ($ultracache_cookie_name_matches_pattern($cookie_name, $pattern)) {
            return true;
        }
    }

    return false;
};

foreach (array_keys((array) ($_COOKIE ?? array())) as $cookie_name) {
    $cookie_name = ultracache_advanced_cache_clean_server_text($cookie_name);
    if ($ultracache_cookie_name_matches_any_pattern($cookie_name, $runtime_config['unsafe_cache_cookie_patterns'] ?? array())) {
        return;
    }
}

$ultracache_cache_stats_enabled = !empty($runtime_config['cache_stats_enabled']);

$revalidate_flag = isset($_GET['ultracache_revalidate']) ? (string) $_GET['ultracache_revalidate'] : '';
$revalidate_header = ultracache_advanced_cache_server_var('HTTP_X_ULTRACACHE_REVALIDATE', '');
$revalidate_token = isset($_GET['ultracache_rt']) ? (string) $_GET['ultracache_rt'] : ultracache_advanced_cache_server_var('HTTP_X_ULTRACACHE_TOKEN', '');
$is_revalidate_request = (
    ('1' === $revalidate_flag || '1' === $revalidate_header)
    && '' !== $ultracache_runtime_control_secret
    && ultracache_advanced_cache_validate_runtime_control_token($revalidate_token, $ultracache_runtime_control_secret)
);
if ($is_revalidate_request) {
    return;
}

$profile_bypass_header = ultracache_advanced_cache_server_var('HTTP_X_ULTRACACHE_PROFILE_BYPASS', '');
$profile_bypass_token = ultracache_advanced_cache_server_var('HTTP_X_ULTRACACHE_TOKEN', '');
$is_profile_bypass_request = (
    ('1' === $profile_bypass_header || 'true' === strtolower((string) $profile_bypass_header))
    && '' !== $ultracache_runtime_control_secret
    && ultracache_advanced_cache_validate_runtime_control_token((string) $profile_bypass_token, $ultracache_runtime_control_secret)
);
if ($is_profile_bypass_request) {
    if (!headers_sent()) {
        header('X-Ultra-Cache-Profile-Bypass: advanced-cache');
    }
    return;
}

$ultracache_initial_query_vars = array();
if (!empty($_SERVER['QUERY_STRING'])) {
    parse_str((string) $_SERVER['QUERY_STRING'], $ultracache_initial_query_vars);
}
$ultracache_initial_internal_control = false;
if (!empty($ultracache_initial_query_vars) && is_array($ultracache_initial_query_vars)) {
    $ultracache_initial_internal_keys = array(
        'ultracache_revalidate' => true,
        'ultracache_rt' => true,
        'ultracache_store_profile' => true,
        'ultracache_callback_profile' => true,
        'ultracache_store_profile_verbose' => true,
        'ultracache_store_profile_verbose_settings' => true,
        'ultracache_profile_bypass' => true,
        'ultracache_profile_run' => true,
        'ultracache_runtime_js_scan' => true,
        'ultracache_runtime_js_scan_id' => true,
        'ultracache_runtime_js_scan_nonce' => true,
    );
    foreach (array_keys($ultracache_initial_query_vars) as $ultracache_initial_query_key) {
        $ultracache_initial_query_key = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $ultracache_initial_query_key));
        if (isset($ultracache_initial_internal_keys[$ultracache_initial_query_key])) {
            $ultracache_initial_internal_control = true;
            break;
        }
    }
}
if (!$ultracache_initial_internal_control) {
    foreach (array(
        'HTTP_X_ULTRACACHE_REVALIDATE',
        'HTTP_X_ULTRACACHE_TOKEN',
        'HTTP_X_ULTRACACHE_PROFILE_BYPASS',
        'HTTP_X_ULTRACACHE_STORE_PROFILE',
        'HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE',
        'HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE_SETTINGS',
        'HTTP_X_ULTRACACHE_CALLBACK_PROFILE',
    ) as $ultracache_initial_header) {
        if ('' !== trim((string) ultracache_advanced_cache_server_var($ultracache_initial_header, ''))) {
            $ultracache_initial_internal_control = true;
            break;
        }
    }
}
if ($ultracache_initial_internal_control) {
    return;
}

$force_refresh_header = strtolower((string) ultracache_advanced_cache_server_var('HTTP_X_ULTRACACHE_FORCE_REFRESH', ''));
$internal_header = (string) ultracache_advanced_cache_server_var('HTTP_X_ULTRACACHE_INTERNAL_REQUEST', '');
$warm_header = (string) ultracache_advanced_cache_server_var('HTTP_X_ULTRACACHE_WARM', '');
if (('1' === $force_refresh_header || 'true' === $force_refresh_header) && ('1' === $internal_header || '1' === $warm_header)) {
    if (!headers_sent()) {
        header('X-Ultra-Cache-Force-Refresh: advanced-cache');
    }
    return;
}

$ultracache_analytics_file = $ultracache_cache_base_dir . '/analytics.json';
$ultracache_analytics_hit_buffer_file = $ultracache_cache_base_dir . '/analytics-hit-buffer.log';
$ultracache_analytics_apcu_prefix = 'ultracache_analytics_hit_buffer_' . md5(__ULTRACACHE_SITE_NAMESPACE_SEED__) . '_';
$ultracache_analytics_buffer_flush_threshold = 50;
$ultracache_analytics_buffer_flush_interval = 30;
$ultracache_analytics_file_flush_threshold = 65536;
$ultracache_default_analytics = static function () {
    return array(
        'version' => 1,
        'pageHits' => 0,
        'pageMisses' => 0,
        'pageBypasses' => 0,
        'pageStores' => 0,
        'pageStoreSkips' => 0,
        'pageStaleHits' => 0,
        'pageBackgroundRevalidations' => 0,
        'bucketHits' => array('orig' => 0, 'webp' => 0, 'avif' => 0),
        'encodingHits' => array('identity' => 0, 'gzip' => 0, 'brotli' => 0),
        'bypassReasons' => array(),
        'lastPurge' => array(),
        'lastWarm' => array(),
        'warmSuccess' => 0,
        'warmFailed' => 0,
    );
};
$ultracache_read_analytics = static function () use ($ultracache_default_analytics) {
    // Aggregate analytics storage moved to DB in 2.57.147. The drop-in runs before
    // WordPress/$wpdb, so it only buffers hits in APCu/Redis and lets WordPress drain them.
    return $ultracache_default_analytics();
};
$ultracache_write_analytics = static function ($data) {
    // DB aggregate writes require WordPress runtime; never write analytics.json from the drop-in.
    return false;
};
$ultracache_analytics_apcu_available = static function () {
    if (!function_exists('apcu_fetch') || !function_exists('apcu_add') || !function_exists('apcu_inc') || !function_exists('apcu_dec') || !function_exists('apcu_delete') || !function_exists('apcu_store')) {
        return false;
    }
    if (function_exists('apcu_enabled') && !apcu_enabled()) {
        return false;
    }
    return true;
};
$ultracache_analytics_counters = array('pageHits', 'pageStaleHits', 'bucket_orig', 'bucket_webp', 'bucket_avif', 'encoding_identity', 'encoding_gzip', 'encoding_brotli');
$ultracache_analytics_redis = null;
$ultracache_analytics_redis_attempted = false;
$ultracache_analytics_redis_prefix = static function () use (&$runtime_config) {
    $prefix = isset($runtime_config['redis_prefix']) ? preg_replace('/[^A-Za-z0-9:_\-]/', '', (string) $runtime_config['redis_prefix']) : '';
    $prefix = trim((string) $prefix, ':');
    if ('' !== $prefix) {
        $prefix .= ':';
    } else {
        $seed = __ULTRACACHE_SITE_NAMESPACE_SEED__;
        $prefix = 'ultracache:' . substr((string) (function_exists('hash') ? hash('sha256', 'ultracache-redis|' . $seed) : md5('ultracache-redis|' . $seed)), 0, 12) . ':';
    }
    return $prefix . 'analytics-hit-buffer:';
};
$ultracache_get_analytics_redis = static function () use (&$ultracache_analytics_redis, &$ultracache_analytics_redis_attempted, &$runtime_config, &$ultracache_redis_credentials) {
    if ($ultracache_analytics_redis_attempted) {
        return $ultracache_analytics_redis;
    }
    $ultracache_analytics_redis_attempted = true;
    if (empty($runtime_config['object_cache_enabled']) || 'redis' !== (string) ($runtime_config['object_cache_backend'] ?? '') || !class_exists('Redis')) {
        return null;
    }
    try {
        $redis = new Redis();
        $host = trim((string) ($runtime_config['redis_host'] ?? '127.0.0.1'));
        if ('' === $host) {
            $host = '127.0.0.1';
        }
        if (!empty($runtime_config['redis_use_tls']) && 0 !== strpos($host, 'tls://')) {
            $host = 'tls://' . ltrim($host, '/');
        }
        $port = max(1, min(65535, (int) ($runtime_config['redis_port'] ?? 6379)));
        $connect_timeout = max(0.05, ((int) ($runtime_config['redis_connect_timeout_ms'] ?? 200)) / 1000);
        $read_timeout = max(0.05, ((int) ($runtime_config['redis_read_timeout_ms'] ?? 200)) / 1000);
        $database = max(0, (int) ($runtime_config['redis_database'] ?? 0));
        $persistent = !empty($runtime_config['redis_persistent']);
        if ($persistent) {
            $connected = @$redis->pconnect($host, $port, $connect_timeout, 'ultracache-analytics-' . md5($host . '|' . $port . '|' . $database));
        } else {
            $connected = @$redis->connect($host, $port, $connect_timeout);
        }
        if (!$connected) {
            return null;
        }
        if (defined('Redis::OPT_SERIALIZER') && defined('Redis::SERIALIZER_NONE')) {
            @$redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
        }
        if (defined('Redis::OPT_READ_TIMEOUT')) {
            @$redis->setOption(Redis::OPT_READ_TIMEOUT, $read_timeout);
        }
        $username = isset($ultracache_redis_credentials['username']) ? trim((string) $ultracache_redis_credentials['username']) : trim((string) ($runtime_config['redis_username'] ?? ''));
        $password = isset($ultracache_redis_credentials['password']) ? (string) $ultracache_redis_credentials['password'] : '';
        if ('' !== $password) {
            $authenticated = '' !== $username ? @$redis->auth(array($username, $password)) : @$redis->auth($password);
            if (!$authenticated) {
                return null;
            }
        } elseif ('' !== $username) {
            return null;
        }
        if ($database > 0 && !@$redis->select($database)) {
            return null;
        }
        $ultracache_analytics_redis = $redis;
        return $ultracache_analytics_redis;
    } catch (Throwable $e) {
        return null;
    }
};
$ultracache_collect_redis_analytics_hit_buffer = static function () use ($ultracache_get_analytics_redis, $ultracache_analytics_redis_prefix, $ultracache_analytics_counters) {
    $redis = $ultracache_get_analytics_redis();
    if (!$redis instanceof Redis) {
        return array();
    }
    $prefix = $ultracache_analytics_redis_prefix();
    $deltas = array();
    foreach ($ultracache_analytics_counters as $counter) {
        try {
            $value = $redis->get($prefix . $counter);
        } catch (Throwable $e) {
            $value = false;
        }
        $value = is_numeric($value) ? max(0, (int) $value) : 0;
        if ($value > 0) {
            $deltas[$counter] = $value;
        }
    }
    return $deltas;
};
$ultracache_decrement_redis_analytics_hit_buffer = static function ($deltas) use ($ultracache_get_analytics_redis, $ultracache_analytics_redis_prefix) {
    $redis = $ultracache_get_analytics_redis();
    if (!$redis instanceof Redis || empty($deltas)) {
        return;
    }
    $prefix = $ultracache_analytics_redis_prefix();
    foreach ($deltas as $counter => $amount) {
        $amount = max(0, (int) $amount);
        if ($amount <= 0) {
            continue;
        }
        try {
            $redis->decrBy($prefix . (string) $counter, $amount);
        } catch (Throwable $e) {
        }
    }
    try {
        $redis->del($prefix . 'total');
        $redis->setEx($prefix . 'last_flush', 3600, (string) time());
    } catch (Throwable $e) {
    }
};
$ultracache_acquire_redis_analytics_flush_lock = static function () use ($ultracache_get_analytics_redis, $ultracache_analytics_redis_prefix) {
    $redis = $ultracache_get_analytics_redis();
    if (!$redis instanceof Redis) {
        return false;
    }
    try {
        return (bool) $redis->set($ultracache_analytics_redis_prefix() . 'flush_lock', '1', array('nx', 'ex' => 10));
    } catch (Throwable $e) {
        return false;
    }
};
$ultracache_release_redis_analytics_flush_lock = static function () use ($ultracache_get_analytics_redis, $ultracache_analytics_redis_prefix) {
    $redis = $ultracache_get_analytics_redis();
    if ($redis instanceof Redis) {
        try {
            $redis->del($ultracache_analytics_redis_prefix() . 'flush_lock');
        } catch (Throwable $e) {
        }
    }
};
$ultracache_apply_analytics_hit_delta = static function (&$data, $counter, $amount) {
    $amount = max(0, (int) $amount);
    if ($amount <= 0) {
        return;
    }
    $counter = (string) $counter;
    if ('pageHits' === $counter || 'pageStaleHits' === $counter) {
        $data[$counter] = (int) ($data[$counter] ?? 0) + $amount;
        return;
    }
    if (0 === strpos($counter, 'bucket_')) {
        $bucket = substr($counter, 7);
        if (!in_array($bucket, array('orig', 'webp', 'avif'), true)) {
            return;
        }
        if (!isset($data['bucketHits']) || !is_array($data['bucketHits'])) {
            $data['bucketHits'] = array('orig' => 0, 'webp' => 0, 'avif' => 0);
        }
        $data['bucketHits'][$bucket] = (int) ($data['bucketHits'][$bucket] ?? 0) + $amount;
        return;
    }
    if (0 === strpos($counter, 'encoding_')) {
        $encoding = substr($counter, 9);
        if (!in_array($encoding, array('identity', 'gzip', 'brotli'), true)) {
            return;
        }
        if (!isset($data['encodingHits']) || !is_array($data['encodingHits'])) {
            $data['encodingHits'] = array('identity' => 0, 'gzip' => 0, 'brotli' => 0);
        }
        $data['encodingHits'][$encoding] = (int) ($data['encodingHits'][$encoding] ?? 0) + $amount;
    }
};
$ultracache_collect_apcu_analytics_hit_buffer = static function () use ($ultracache_analytics_apcu_available, $ultracache_analytics_apcu_prefix) {
    if (!$ultracache_analytics_apcu_available()) {
        return array();
    }
    $deltas = array();
    foreach (array('pageHits', 'pageStaleHits', 'bucket_orig', 'bucket_webp', 'bucket_avif', 'encoding_identity', 'encoding_gzip', 'encoding_brotli') as $counter) {
        $ok = false;
        $value = apcu_fetch($ultracache_analytics_apcu_prefix . $counter, $ok);
        $value = $ok ? max(0, (int) $value) : 0;
        if ($value > 0) {
            $deltas[$counter] = $value;
        }
    }
    return $deltas;
};
$ultracache_consume_file_analytics_hit_buffer = static function () use ($ultracache_analytics_hit_buffer_file, $ultracache_advanced_cache_is_cache_path) {
    if (!$ultracache_advanced_cache_is_cache_path($ultracache_analytics_hit_buffer_file) || !file_exists($ultracache_analytics_hit_buffer_file) || !is_readable($ultracache_analytics_hit_buffer_file)) {
        return array();
    }
    $handle = @fopen($ultracache_analytics_hit_buffer_file, 'c+');
    if (!$handle) {
        return array();
    }
    $raw = '';
    if (@flock($handle, LOCK_EX)) {
        rewind($handle);
        $raw = stream_get_contents($handle);
        ftruncate($handle, 0);
        rewind($handle);
        @flock($handle, LOCK_UN);
    }
    fclose($handle);
    if (!is_string($raw) || '' === trim($raw)) {
        return array();
    }
    $deltas = array();
    foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
        if ('' === $line) {
            continue;
        }
        $parts = explode("\t", $line);
        if (count($parts) < 3) {
            continue;
        }
        $state = strtoupper((string) $parts[0]);
        $bucket = in_array($parts[1], array('orig', 'webp', 'avif'), true) ? $parts[1] : 'orig';
        $encoding = in_array($parts[2], array('identity', 'gzip', 'brotli'), true) ? $parts[2] : 'identity';
        $hit_counter = ('S' === $state) ? 'pageStaleHits' : 'pageHits';
        foreach (array($hit_counter, 'bucket_' . $bucket, 'encoding_' . $encoding) as $counter) {
            if (!isset($deltas[$counter])) {
                $deltas[$counter] = 0;
            }
            $deltas[$counter]++;
        }
    }
    return $deltas;
};
$ultracache_flush_analytics_hit_buffer = static function () {
    // The advanced-cache drop-in cannot use $wpdb safely. Keep hit counters in
    // APCu/Redis until a normal WordPress request/dashboard read drains them into DB.
    return false;
};
$ultracache_record_hit = static function ($bucket, $encoding_bucket, $stale = false) use ($ultracache_cache_stats_enabled, $ultracache_analytics_apcu_available, $ultracache_analytics_apcu_prefix, $ultracache_analytics_buffer_flush_threshold, $ultracache_analytics_buffer_flush_interval, $ultracache_flush_analytics_hit_buffer, $ultracache_get_analytics_redis, $ultracache_analytics_redis_prefix) {
    if (!$ultracache_cache_stats_enabled) {
        return false;
    }
    $bucket = in_array($bucket, array('orig', 'webp', 'avif'), true) ? $bucket : 'orig';
    $encoding_bucket = in_array($encoding_bucket, array('identity', 'gzip', 'brotli'), true) ? $encoding_bucket : 'identity';
    $counters = array($stale ? 'pageStaleHits' : 'pageHits', 'bucket_' . $bucket, 'encoding_' . $encoding_bucket);

    if ($ultracache_analytics_apcu_available()) {
        foreach ($counters as $counter) {
            @apcu_add($ultracache_analytics_apcu_prefix . $counter, 0, 3600);
            @apcu_inc($ultracache_analytics_apcu_prefix . $counter, 1);
        }
        @apcu_add($ultracache_analytics_apcu_prefix . 'total', 0, 3600);
        $total = (int) @apcu_inc($ultracache_analytics_apcu_prefix . 'total', 1);
        $last_flush = (int) @apcu_fetch($ultracache_analytics_apcu_prefix . 'last_flush');
        // Do not flush aggregate analytics from the drop-in; WordPress drains APCu into DB.
        return true;
    }

    $redis = $ultracache_get_analytics_redis();
    if ($redis instanceof Redis) {
        $prefix = $ultracache_analytics_redis_prefix();
        try {
            foreach ($counters as $counter) {
                $redis->incr($prefix . $counter);
                $redis->expire($prefix . $counter, 3600);
            }
            $total = (int) $redis->incr($prefix . 'total');
            $redis->expire($prefix . 'total', 3600);
            $last_flush = (int) $redis->get($prefix . 'last_flush');
            // Do not flush aggregate analytics from the drop-in; WordPress drains Redis into DB.
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // No APCu and no usable Redis: disable per-hit analytics entirely.
    return false;
};
$ultracache_record_background_revalidation = static function () {
    // Aggregate DB analytics are recorded by the WordPress runtime, not the drop-in.
    return false;
};
$ultracache_get_revalidate_lock_path = static function ($cache_file) {
    return (string) $cache_file . '.revalidate.lock';
};
$ultracache_can_queue_revalidate = static function ($lock_file, $max_stale_seconds) use ($ultracache_get_filemtime, $ultracache_advanced_cache_is_cache_path) {
    if (!$ultracache_advanced_cache_is_cache_path($lock_file) || !file_exists($lock_file)) {
        return true;
    }
    $mtime = $ultracache_get_filemtime($lock_file);
    if (!$mtime) {
        return true;
    }
    $lock_ttl = max(30, min(300, (int) $max_stale_seconds));
    return (time() - (int) $mtime) > $lock_ttl;
};
$ultracache_write_revalidate_lock = static function ($lock_file) use ($ultracache_make_dir, $ultracache_write_file, $ultracache_advanced_cache_is_cache_path) {
    if (!$ultracache_advanced_cache_is_cache_path($lock_file)) {
        return;
    }
    $dir = dirname($lock_file);
    if (!file_exists($dir)) {
        $ultracache_make_dir($dir, 0755, true);
    }
    $ultracache_write_file($lock_file, (string) time(), LOCK_EX);
};
$ultracache_queue_revalidate = static function ($target_url, $secret) {
    $secret = (string) $secret;
    if ('' === $target_url || '' === $secret) {
        return false;
    }
    $token = ultracache_advanced_cache_create_runtime_control_token($secret);
    if ('' === $token) {
        return false;
    }
    $separator = false !== strpos($target_url, '?') ? '&' : '?';
    $request_url = $target_url . $separator . 'ultracache_revalidate=1&ultracache_rt=' . rawurlencode($token);

    $parts = parse_url($request_url);
    if (empty($parts['host'])) {
        return false;
    }

    $scheme = !empty($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'http';
    $host = (string) $parts['host'];
    $port = isset($parts['port']) ? (int) $parts['port'] : ('https' === $scheme ? 443 : 80);
    $path = (!empty($parts['path']) ? (string) $parts['path'] : '/') . (!empty($parts['query']) ? '?' . (string) $parts['query'] : '');
    $remote = ('https' === $scheme ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $host_header = $host;
    if (!(('http' === $scheme && 80 === $port) || ('https' === $scheme && 443 === $port))) {
        $host_header .= ':' . (string) $port;
    }

    // Prefer fire-and-forget sockets. This keeps stale HIT serving out of the 1s cURL wait path.
    $errno = 0;
    $errstr = '';
    $flags = STREAM_CLIENT_CONNECT;
    if (defined('STREAM_CLIENT_ASYNC_CONNECT')) {
        $flags |= STREAM_CLIENT_ASYNC_CONNECT;
    }
    $fp = ultracache_advanced_cache_safe_stream_socket_client($remote, 0.15, $errno, $errstr, $flags);
    if ($fp) {
        stream_set_blocking($fp, false);
        stream_set_timeout($fp, 0, 150000);
        $out = "GET {$path} HTTP/1.1
";
        $out .= "Host: {$host_header}
";
        $out .= "Connection: Close
";
        $out .= "X-UltraCache-Revalidate: 1
";
        $out .= "X-UltraCache-Token: {$token}

";
        $written = @fwrite($fp, $out);
        fclose($fp);
        if (false !== $written) {
            return true;
        }
    }

    // No cURL fallback here: stale revalidation must never run a blocking HTTP client in the visitor path.
    return false;
};

$normalize_path = static function ($path) {
    $path = '/' . ltrim((string) $path, '/');
    return '/' === $path ? '/' : rtrim($path, '/') . '/';
};

$path_matches_rule = static function ($path, $rule) use ($normalize_path) {
    $path = $normalize_path($path);
    $rule = trim((string) $rule);
    if ('' === $rule) {
        return false;
    }

    $wildcard = false;
    if ('*' === substr($rule, -1)) {
        $wildcard = true;
        $rule = substr($rule, 0, -1);
    }

    $rule = $normalize_path($rule);
    if ('/' === $rule) {
        return '/' === $path;
    }

    if ($path === $rule) {
        return true;
    }

    return $wildcard || 0 === strpos($path, $rule);
};

$ultracache_sort_query_value = static function ($value) use (&$ultracache_sort_query_value) {
    if (!is_array($value)) {
        return $value;
    }

    foreach ($value as $key => $child) {
        $value[$key] = $ultracache_sort_query_value($child);
    }

    if (array_keys($value) === range(0, count($value) - 1)) {
        usort($value, static function ($a, $b) {
            return strcmp((string) json_encode($a), (string) json_encode($b));
        });
        return $value;
    }

    ksort($value);
    return $value;
};

$ultracache_normalize_query_vars = static function ($query_vars, $allowlist) use ($ultracache_sort_query_value) {
    if (!is_array($query_vars) || empty($query_vars) || empty($allowlist)) {
        return array();
    }

    $lookup = array();
    foreach ((array) $allowlist as $allowed_key) {
        $allowed_key = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $allowed_key));
        if ('' !== $allowed_key) {
            $lookup[$allowed_key] = true;
        }
    }

    $filtered = array();
    foreach ($query_vars as $query_key => $query_value) {
        $normalized_key = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $query_key));
        if ('' === $normalized_key) {
            continue;
        }
        if (!empty($lookup) && !isset($lookup[$normalized_key])) {
            continue;
        }

        $filtered[$normalized_key] = $ultracache_sort_query_value($query_value);
    }

    if (empty($filtered)) {
        return array();
    }

    ksort($filtered);
    return $filtered;
};

$ultracache_normalize_query_key = static function ($query_key) {
    $query_key = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $query_key));
    return is_string($query_key) ? $query_key : '';
};

$ultracache_get_first_non_allowlisted_query_key = static function ($query_vars, $allowlist) use ($ultracache_normalize_query_key) {
    if (!is_array($query_vars) || empty($query_vars)) {
        return '';
    }

    $lookup = array();
    foreach ((array) $allowlist as $allowed_key) {
        $allowed_key = $ultracache_normalize_query_key($allowed_key);
        if ('' !== $allowed_key) {
            $lookup[$allowed_key] = true;
        }
    }

    if (empty($lookup)) {
        return '';
    }

    foreach (array_keys($query_vars) as $query_key) {
        $normalized_key = $ultracache_normalize_query_key($query_key);
        if ('' === $normalized_key || !isset($lookup[$normalized_key])) {
            return '' !== $normalized_key ? $normalized_key : (string) $query_key;
        }
    }

    return '';
};

$ultracache_hard_security_query_args = array(
    '_wpnonce',
    '_ajax_nonce',
    'nonce',
    'security',
    'token',
    'auth',
    'auth_token',
    'access_token',
    'key',
    'order_key',
    'password',
    'pass',
    'pwd',
    'redirect_to',
    'rest_route',
    'customer-logout',
    'logout',
    'pay_for_order',
    'cancel_order',
    'download_file',
    'ultracache_revalidate',
    'ultracache_rt',
    'ultracache_store_profile',
    'ultracache_callback_profile',
    'ultracache_store_profile_verbose',
    'ultracache_store_profile_verbose_settings',
    'ultracache_profile_bypass',
    'ultracache_profile_run',
    'ultracache_runtime_js_scan',
    'ultracache_runtime_js_scan_id',
    'ultracache_runtime_js_scan_nonce',
);

$ultracache_hard_security_paths = array(
    '/wp-admin/',
    '/wp-login.php',
    '/wp-json/',
    '/xmlrpc.php',
    '/wp-cron.php',
    '/wp-comments-post.php',
    '/wc-api/',
    '/cart/',
    '/checkout/',
    '/my-account/',
    '/order-pay/',
    '/order-received/',
    '/add-payment-method/',
    '/lost-password/',
);

$ultracache_internal_control_query_args = array(
    'ultracache_revalidate',
    'ultracache_rt',
    'ultracache_store_profile',
    'ultracache_callback_profile',
    'ultracache_store_profile_verbose',
    'ultracache_store_profile_verbose_settings',
    'ultracache_profile_bypass',
    'ultracache_profile_run',
    'ultracache_runtime_js_scan',
    'ultracache_runtime_js_scan_id',
    'ultracache_runtime_js_scan_nonce',
);

$ultracache_has_internal_control_query_marker = static function ($query_vars) use ($ultracache_normalize_query_key, $ultracache_internal_control_query_args) {
    if (!is_array($query_vars) || empty($query_vars)) {
        return false;
    }

    $lookup = array_fill_keys($ultracache_internal_control_query_args, true);
    foreach (array_keys($query_vars) as $query_key) {
        $query_key = $ultracache_normalize_query_key($query_key);
        if (isset($lookup[$query_key])) {
            return true;
        }
    }

    return false;
};

$ultracache_has_internal_control_header_marker = static function () {
    foreach (array(
        'HTTP_X_ULTRACACHE_REVALIDATE',
        'HTTP_X_ULTRACACHE_TOKEN',
        'HTTP_X_ULTRACACHE_PROFILE_BYPASS',
        'HTTP_X_ULTRACACHE_STORE_PROFILE',
        'HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE',
        'HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE_SETTINGS',
        'HTTP_X_ULTRACACHE_CALLBACK_PROFILE',
    ) as $header) {
        if ('' !== trim((string) ultracache_advanced_cache_server_var($header, ''))) {
            return true;
        }
    }

    return false;
};

$ultracache_normalize_host = static function ($host) {

    $host = trim((string) $host);
    if ('' === $host) {
        return '';
    }

    if (false !== strpos($host, ',')) {
        $parts = explode(',', $host);
        $host = (string) reset($parts);
    }

    $host = preg_replace('/\s+/', '', $host);
    $parsed = parse_url('http://' . ltrim($host, '/'));
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
};
$ultracache_trusted_hosts = array();
foreach ((array) ($runtime_config['trusted_hosts'] ?? array()) as $trusted_host) {
    $trusted_host = $ultracache_normalize_host($trusted_host);
    if ('' !== $trusted_host) {
        $ultracache_trusted_hosts[$trusted_host] = true;
    }
}
$incoming_host = $ultracache_normalize_host($raw_http_host);
if ('' === $incoming_host || empty($ultracache_trusted_hosts) || !isset($ultracache_trusted_hosts[$incoming_host])) {
    return;
}

$ultracache_detect_request_scheme = static function () {
    $https_value = strtolower((string) ultracache_advanced_cache_server_var('HTTPS', ''));
    $server_port = ultracache_advanced_cache_server_var('SERVER_PORT', '');
    $is_ssl = ('' !== $https_value && 'off' !== $https_value)
        || ('443' === $server_port);

    if ($is_ssl) {
        return 'https';
    }

    $forwarded_proto_parts = explode(',', ultracache_advanced_cache_server_var('HTTP_X_FORWARDED_PROTO', ''));
    $forwarded_proto = strtolower(trim((string) reset($forwarded_proto_parts)));
    if ('https' === $forwarded_proto) {
        return 'https';
    }

    $forwarded_scheme = strtolower(trim((string) ultracache_advanced_cache_server_var('HTTP_X_FORWARDED_SCHEME', '')));
    if ('https' === $forwarded_scheme) {
        return 'https';
    }

    $forwarded_ssl = strtolower(trim((string) ultracache_advanced_cache_server_var('HTTP_X_FORWARDED_SSL', '')));
    if (in_array($forwarded_ssl, array('on', '1', 'true', 'https'), true)) {
        return 'https';
    }

    $frontend_https = strtolower(trim((string) ultracache_advanced_cache_server_var('HTTP_FRONT_END_HTTPS', '')));
    if (in_array($frontend_https, array('on', '1', 'true'), true)) {
        return 'https';
    }

    $cloudfront_proto = strtolower(trim((string) ultracache_advanced_cache_server_var('HTTP_CLOUDFRONT_FORWARDED_PROTO', '')));
    if ('https' === $cloudfront_proto) {
        return 'https';
    }

    $cf_visitor = (string) ultracache_advanced_cache_server_var('HTTP_CF_VISITOR', '');
    if (false !== stripos($cf_visitor, '"scheme":"https"')) {
        return 'https';
    }

    return 'http';
};
$scheme = $ultracache_detect_request_scheme();
$url = $scheme . '://' . $incoming_host . $request_uri;
$parts = parse_url($url);
if (empty($parts['host'])) {
    return;
}

$path = isset($parts['path']) ? $normalize_path((string) $parts['path']) : '/';
foreach ($ultracache_hard_security_paths as $excluded_path) {
    if ($path_matches_rule($path, $excluded_path)) {
        return;
    }
}
foreach ((array) ($runtime_config['excluded_paths'] ?? array()) as $excluded_path) {
    if ($path_matches_rule($path, $excluded_path)) {
        return;
    }
}

$query_vars = array();
if (!empty($parts['query'])) {
    parse_str((string) $parts['query'], $query_vars);
}

$excluded_query_args_lookup = array();
foreach ($ultracache_hard_security_query_args as $excluded_query_arg) {
    $excluded_query_arg = $ultracache_normalize_query_key($excluded_query_arg);
    if ('' !== $excluded_query_arg) {
        $excluded_query_args_lookup[$excluded_query_arg] = true;
    }
}
foreach ((array) ($runtime_config['excluded_query_args'] ?? array()) as $excluded_query_arg) {
    $excluded_query_arg = $ultracache_normalize_query_key($excluded_query_arg);
    if ('' !== $excluded_query_arg) {
        $excluded_query_args_lookup[$excluded_query_arg] = true;
    }
}
foreach (array_keys($query_vars) as $query_key) {
    $query_key = $ultracache_normalize_query_key($query_key);
    if ('' !== $query_key && isset($excluded_query_args_lookup[$query_key])) {
        return;
    }
}

$normalized_query_vars = array();
if (!empty($query_vars)) {
    $query_allowlist = (array) ($runtime_config['cache_query_allowlist'] ?? array());
    if (empty($runtime_config['cache_query_strings']) || empty($query_allowlist)) {
        return;
    }

    if ('' !== $ultracache_get_first_non_allowlisted_query_key($query_vars, $query_allowlist)) {
        return;
    }

    $normalized_query_vars = $ultracache_normalize_query_vars($query_vars, $query_allowlist);
    if (empty($normalized_query_vars)) {
        return;
    }
}

if (!empty($runtime_config['woo_safe_mode'])) {
    foreach (array('/cart/', '/checkout/', '/my-account/', '/order-pay/', '/order-received/', '/add-payment-method/', '/lost-password/') as $dynamic_path) {
        if ($path_matches_rule($path, $dynamic_path)) {
            return;
        }
    }
}

$host = preg_replace('#[^a-z0-9._-]#', '-', strtolower((string) $parts['host']));
$cache_key_path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';
$cache_key_path = preg_replace('#[^A-Za-z0-9/_-]#', '-', $cache_key_path);
$cache_key_path = trim((string) $cache_key_path, '/');
if ('' === $cache_key_path) {
    $cache_key_path = 'index';
}

$accept = strtolower((string) ultracache_advanced_cache_server_var('HTTP_ACCEPT', ''));
$bucket = 'orig';
if (false !== strpos($accept, 'image/avif')) {
    $bucket = 'avif';
} elseif (false !== strpos($accept, 'image/webp')) {
    $bucket = 'webp';
}

$normalized = $scheme . '://' . strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . '/' . ltrim((string) ($parts['path'] ?? '/'), '/');
if (!empty($normalized_query_vars)) {
    $normalized .= '?' . http_build_query($normalized_query_vars, '', '&', PHP_QUERY_RFC3986);
}
$hash = md5($normalized);
$cache_file = $ultracache_cache_base_dir . '/' . $host . '/' . $cache_key_path . '/index-' . $bucket . '-' . $hash . '.html';
if (!ultracache_advanced_cache_is_valid_cache_payload_file($cache_file, $ultracache_cache_base_dir) || !file_exists($cache_file) || !is_readable($cache_file)) {
    return;
}


$encoding = strtolower((string) ultracache_advanced_cache_server_var('HTTP_ACCEPT_ENCODING', ''));
$serve_file = $cache_file;
$encoding_bucket = 'identity';
if (false !== strpos($encoding, 'br') && $ultracache_advanced_cache_is_cache_path($cache_file . '.br') && file_exists($cache_file . '.br') && is_readable($cache_file . '.br')) {
    $serve_file = $cache_file . '.br';
    $encoding_bucket = 'brotli';
    header('X-UltraCache-Encoding: brotli');
    header('Content-Encoding: br');
} elseif (false !== strpos($encoding, 'gzip') && $ultracache_advanced_cache_is_cache_path($cache_file . '.gz') && file_exists($cache_file . '.gz') && is_readable($cache_file . '.gz')) {
    $serve_file = $cache_file . '.gz';
    $encoding_bucket = 'gzip';
    header('X-UltraCache-Encoding: gzip');
    header('Content-Encoding: gzip');
}

if (!ultracache_advanced_cache_is_valid_cache_payload_file($serve_file, $ultracache_cache_base_dir) || !is_readable($serve_file)) {
    return;
}

$fresh_ttl = max(60, (int) ($runtime_config['cache_fresh_ttl_minutes'] ?? 15) * 60);
$max_stale = max($fresh_ttl, (int) ($runtime_config['cache_max_stale_minutes'] ?? 60) * 60);
$mtime = $ultracache_get_filemtime($cache_file);
$age = $mtime ? max(0, time() - (int) $mtime) : 0;
$serve_until = !empty($runtime_config['stale_while_revalidate_enabled']) ? $max_stale : $fresh_ttl;
if ($age > $serve_until) {
    return;
}

if (!empty($runtime_config['stale_while_revalidate_enabled']) && $age > $fresh_ttl && $age <= $max_stale) {
    $lock_file = $ultracache_get_revalidate_lock_path($cache_file);
    $should_revalidate = false;
    if ($ultracache_can_queue_revalidate($lock_file, $max_stale)) {
        $ultracache_write_revalidate_lock($lock_file);
        $should_revalidate = true;
    }

    $ultracache_record_hit($bucket, $encoding_bucket, true);
    header('Content-Type: text/html; charset=UTF-8');
    header('Vary: Accept, Accept-Encoding', false);
    header('X-Ultra-Cache: STALE');
    if (ultracache_advanced_cache_debug_headers_enabled()) {
        header('X-Ultra-Cache-Source: advanced-cache');
    }
    header('X-Ultra-Cache-Age: ' . (string) $age);
    header('X-Ultra-Cache-Revalidate: ' . ($should_revalidate ? 'queued' : 'pending'));
    if ('HEAD' !== $method) {
        $ultracache_advanced_cache_safe_readfile($serve_file);
    }

    if ($should_revalidate) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            if (function_exists('ob_get_level')) {
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
            }
            @flush();
        }

        $queued = $ultracache_queue_revalidate($normalized, $ultracache_runtime_control_secret);
        if ($queued) {
            $ultracache_record_background_revalidation();
        } else {
            $ultracache_delete_file($lock_file);
        }
    }
    exit;
}

$ultracache_record_hit($bucket, $encoding_bucket, false);

header('Content-Type: text/html; charset=UTF-8');
header('Vary: Accept, Accept-Encoding', false);
header('X-Ultra-Cache: HIT');
if (ultracache_advanced_cache_debug_headers_enabled()) {
    header('X-Ultra-Cache-Source: advanced-cache');
}
header('X-Ultra-Cache-Age: ' . (string) $age);
if ('HEAD' !== $method) {
    $ultracache_advanced_cache_safe_readfile($serve_file);
}
exit;
