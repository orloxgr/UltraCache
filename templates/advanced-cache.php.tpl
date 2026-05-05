<?php
/**
 * UltraCache advanced-cache drop-in.
 * Marker: UltraCache advanced-cache drop-in
 * Drop-in Build: __UCWP_DROPIN_BUILD__
 */
if (!defined('ABSPATH')) {
    return;
}

if (!function_exists('ucwp_dropin_safe_file_get_contents')) {
    function ucwp_dropin_safe_file_get_contents($file) {
        return is_readable($file) ? file_get_contents($file) : false;
    }
}

if (!function_exists('ucwp_dropin_safe_file_put_contents')) {
    function ucwp_dropin_safe_file_put_contents($file, $data, $flags = 0, $context = '') {
        $file = (string) $file;
        $dir = dirname($file);
        if ('' === $file) {
            return false;
        }
        if ('' !== $dir && '.' !== $dir && !is_dir($dir)) {
            if (function_exists('ucwp_dropin_safe_mkdir')) {
                ucwp_dropin_safe_mkdir($dir, 0755, true);
            } else {
                @mkdir($dir, 0755, true);
            }
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
}
if (!function_exists('ucwp_dropin_safe_unlink')) {
    function ucwp_dropin_safe_unlink($file) {
        return !file_exists($file) ? true : unlink($file);
    }
}

if (!function_exists('ucwp_dropin_safe_rename')) {
    function ucwp_dropin_safe_rename($from, $to) {
        return rename($from, $to);
    }
}

if (!function_exists('ucwp_dropin_safe_mkdir')) {
    function ucwp_dropin_safe_mkdir($dir, $mode = 0755, $recursive = true) {
        $dir = is_string($dir) ? trim($dir) : '';
        if ('' === $dir) {
            return false;
        }
        if (is_dir($dir)) {
            return true;
        }
        return @mkdir($dir, $mode, $recursive) || is_dir($dir);
    }
}


if (!function_exists('ucwp_dropin_safe_filemtime')) {
    function ucwp_dropin_safe_filemtime($file) {
        return is_file($file) ? filemtime($file) : false;
    }
}

if (!function_exists('ucwp_dropin_safe_readfile')) {
    function ucwp_dropin_safe_readfile($file) {
        return is_readable($file) ? readfile($file) : false;
    }
}

if (!function_exists('ucwp_dropin_safe_stream_socket_client')) {
    function ucwp_dropin_safe_stream_socket_client($remote_socket, $timeout, &$errno = null, &$errstr = null) {
        $timeout = max(0.1, (float) $timeout);
        return stream_socket_client($remote_socket, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    }
}


if (!function_exists('ucwp_advanced_cache_debug_headers_enabled')) {
    function ucwp_advanced_cache_debug_headers_enabled() {
        $flag = isset($_SERVER['HTTP_X_ULTRACACHE_DEBUG']) ? strtolower(trim((string) $_SERVER['HTTP_X_ULTRACACHE_DEBUG'])) : '';
        return in_array($flag, array('1', 'true', 'yes', 'on'), true);
    }
}

if (!function_exists('ucwp_normalize_cache_path')) {
    function ucwp_normalize_cache_path($path) {
        $path = is_string($path) ? trim($path) : '';
        if ('' === $path) {
            return '';
        }
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);
        return is_string($path) ? rtrim($path, '/') : '';
    }
}

if (!function_exists('ucwp_resolve_cache_path_for_compare')) {
    function ucwp_resolve_cache_path_for_compare($path, $must_exist = false) {
        $path = is_string($path) ? trim($path) : '';
        if ('' === $path) {
            return '';
        }

        if (function_exists('realpath')) {
            $real = realpath($path);
            if (is_string($real) && '' !== $real) {
                return ucwp_normalize_cache_path($real);
            }

            if (!$must_exist) {
                $parent = dirname($path);
                $leaf = basename($path);
                $real_parent = realpath($parent);
                if (is_string($real_parent) && '' !== $real_parent && '' !== $leaf && '.' !== $leaf && '..' !== $leaf) {
                    return ucwp_normalize_cache_path(rtrim($real_parent, '/\\') . DIRECTORY_SEPARATOR . $leaf);
                }
            }

            if ($must_exist) {
                return '';
            }
        }

        return ucwp_normalize_cache_path($path);
    }
}

if (!function_exists('ucwp_is_path_within_base')) {
    function ucwp_is_path_within_base($path, $base_dir, $must_exist = false) {
        $resolved_path = ucwp_resolve_cache_path_for_compare($path, (bool) $must_exist);
        $resolved_base = ucwp_resolve_cache_path_for_compare($base_dir, true);
        if ('' === $resolved_path || '' === $resolved_base) {
            return false;
        }
        return $resolved_path === $resolved_base || 0 === strpos($resolved_path, $resolved_base . '/');
    }
}

if (!function_exists('ucwp_is_cache_path')) {
    function ucwp_is_cache_path($path, $base_dir, $must_exist = false) {
        $path = is_string($path) ? trim($path) : '';
        $base_dir = is_string($base_dir) ? trim($base_dir) : '';
        if ('' === $path || '' === $base_dir) {
            return false;
        }
        return ucwp_is_path_within_base($path, $base_dir, (bool) $must_exist);
    }
}

if (!function_exists('ucwp_clean_server_text')) {
    function ucwp_clean_server_text($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        $value = (string) $value;
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
        return is_string($value) ? trim($value) : '';
    }
}

if (!function_exists('ucwp_server_var')) {
    function ucwp_server_var($key, $default = '') {
        if (!isset($_SERVER[$key])) {
            return $default;
        }
        $value = ucwp_clean_server_text($_SERVER[$key]);
        return '' === $value ? $default : $value;
    }
}

if (!function_exists('ucwp_normalize_request_uri')) {
    function ucwp_normalize_request_uri($uri) {
        $uri = ucwp_clean_server_text($uri);
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
}

$ucwp_read_file = static function ($file) {
    return is_readable($file) ? file_get_contents($file) : false;
};
$ucwp_write_file = static function ($file, $data, $flags = 0) {
    return file_put_contents($file, $data, $flags);
};
$ucwp_delete_file = static function ($file) {
    return !file_exists($file) ? true : unlink($file);
};
$ucwp_move_file = static function ($from, $to) {
    return rename($from, $to);
};
$ucwp_make_dir = static function ($dir, $mode = 0755, $recursive = true) {
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, $mode, $recursive) || is_dir($dir);
};
$ucwp_get_filemtime = static function ($file) {
    return ucwp_dropin_safe_filemtime($file);
};
$ucwp_dropin_safe_readfile = static function ($file) {
    return ucwp_dropin_safe_readfile($file);
};
$ucwp_cache_base_dir = rtrim(WP_CONTENT_DIR, '/\\') . '/cache/ultracache';
$ucwp_is_cache_path = static function ($path, $base_dir = null, $must_exist = false) use ($ucwp_cache_base_dir) {
    $base_dir = is_string($base_dir) && '' !== trim($base_dir) ? $base_dir : $ucwp_cache_base_dir;
    return ucwp_is_cache_path($path, $base_dir, (bool) $must_exist);
};

$ucwp_validate_generated_css_refs = static function ($cache_file) use ($ucwp_read_file, $ucwp_delete_file, $ucwp_is_cache_path, $ucwp_cache_base_dir) {
    $cache_file = is_string($cache_file) ? trim($cache_file) : '';
    if ('' === $cache_file || !$ucwp_is_cache_path($cache_file, null, true) || !is_readable($cache_file)) {
        return false;
    }

    $html = $ucwp_read_file($cache_file);
    if (!is_string($html) || '' === $html) {
        return false;
    }

    if (false === stripos($html, '/cache/ultracache/')) {
        return true;
    }

    $has_generated_css = (false !== stripos($html, '/cache/ultracache/css-bundles/'))
        || (false !== stripos($html, '/cache/ultracache/font-css/'))
        || (false !== stripos($html, '/cache/ultracache/optimized-css/'));
    if (!$has_generated_css) {
        return true;
    }

    $patterns = array(
        "~(?:https?:)?//[^\\s\"'<>]+/wp-content/cache/ultracache/(?:css-bundles|font-css|optimized-css)/[^\\s\"'<>?#)]+\\.css~i",
        "~/wp-content/cache/ultracache/(?:css-bundles|font-css|optimized-css)/[^\\s\"'<>?#)]+\\.css~i",
    );

    $refs = array();
    foreach ($patterns as $pattern) {
        $matches = array();
        $matched = preg_match_all($pattern, $html, $matches);
        if (false === $matched || empty($matches[0]) || !is_array($matches[0])) {
            continue;
        }
        $refs = array_merge($refs, $matches[0]);
    }

    if (empty($refs)) {
        return true;
    }

    $allowed_dirs = array(
        'css-bundles' => rtrim($ucwp_cache_base_dir, '/\\') . '/css-bundles/',
        'font-css' => rtrim($ucwp_cache_base_dir, '/\\') . '/font-css/',
        'optimized-css' => rtrim($ucwp_cache_base_dir, '/\\') . '/optimized-css/',
    );

    $missing = array();
    foreach (array_values(array_unique(array_map('strval', $refs))) as $ref) {
        $path = parse_url($ref, PHP_URL_PATH);
        if (!is_string($path) || '' === $path) {
            $path = $ref;
        }
        $path = rawurldecode((string) $path);

        if (!preg_match('#/wp-content/cache/ultracache/(css-bundles|font-css|optimized-css)/([^/]+\.css)$#i', $path, $match)) {
            continue;
        }

        $bucket = strtolower((string) $match[1]);
        $basename = basename((string) $match[2]);
        if ('' === $basename || empty($allowed_dirs[$bucket]) || !preg_match('/^[A-Za-z0-9_.-]+\.css$/', $basename)) {
            continue;
        }

        $file = $allowed_dirs[$bucket] . $basename;
        clearstatcache(true, $file);
        if (!$ucwp_is_cache_path($file, null, false) || !is_readable($file) || filesize($file) <= 0) {
            $missing[$bucket . '/' . $basename] = true;
        }
    }

    if (empty($missing)) {
        return true;
    }

    $ucwp_delete_file($cache_file);
    $ucwp_delete_file($cache_file . '.gz');
    $ucwp_delete_file($cache_file . '.br');
    if (!headers_sent()) {
        header('X-Ultra-Cache-Stale-Generated-CSS: invalidated');
    }

    return false;
};

$method = strtoupper((string) ucwp_server_var('REQUEST_METHOD', 'GET'));
if (!in_array($method, array('GET', 'HEAD'), true)) {
    return;
}

$raw_http_host = ucwp_server_var('HTTP_HOST', '');
$request_uri = ucwp_normalize_request_uri(ucwp_server_var('REQUEST_URI', ''));
if ('' === $raw_http_host || '' === $request_uri) {
    return;
}
if (strpos($request_uri, '/wp-admin/') === 0 || strpos($request_uri, '/wp-login.php') === 0) {
    return;
}

foreach (array_keys((array) ($_COOKIE ?? array())) as $cookie_name) {
    $cookie_name = ucwp_clean_server_text($cookie_name);
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
        'ucwp_revalidate',
        'ucwp_rt',
        'ucwp_store_profile',
        'ucwp_callback_profile',
        'ucwp_store_profile_verbose',
        'ucwp_store_profile_verbose_settings',
        'ucwp_profile_bypass',
        'ucwp_profile_run',
        'ucwp_runtime_js_scan',
        'ucwp_runtime_js_scan_id',
        'ucwp_runtime_js_scan_nonce',
    ),
    'cache_query_strings'            => false,
    'cache_query_allowlist'          => array(),
    'woo_safe_mode'                  => false,
    'cache_stats_enabled'            => false,
    'stale_while_revalidate_enabled' => false,
    'cache_fresh_ttl_minutes'        => 15,
    'cache_max_stale_minutes'        => 720,
    'revalidate_secret'              => '',
    'trusted_hosts'                  => array(),
    'object_cache_enabled'           => false,
    'object_cache_backend'           => 'redis',
    'redis_host'                     => '127.0.0.1',
    'redis_port'                     => 6379,
    'redis_password'                 => '',
    'redis_database'                 => 0,
    'redis_prefix'                   => '',
    'redis_use_tls'                  => false,
    'redis_persistent'               => false,
    'redis_connect_timeout_ms'       => 200,
    'redis_read_timeout_ms'          => 200,
);
$ucwp_normalize_runtime_string_list = static function ($value, $pattern = null) {
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
$ucwp_normalize_runtime_path = static function ($path) {
    $path = '/' . ltrim((string) $path, '/');
    return '/' === $path ? '/' : rtrim($path, '/') . '/';
};
$ucwp_normalize_runtime_path_list = static function ($value) use ($ucwp_normalize_runtime_string_list, $ucwp_normalize_runtime_path) {
    $paths = array();
    foreach ($ucwp_normalize_runtime_string_list($value) as $path_rule) {
        $wildcard = '*' === substr($path_rule, -1);
        if ($wildcard) {
            $path_rule = substr($path_rule, 0, -1);
        }
        $path_rule = $ucwp_normalize_runtime_path($path_rule);
        if ('/' === $path_rule) {
            continue;
        }
        $paths[$path_rule . ($wildcard ? '*' : '')] = true;
    }

    $paths = array_keys($paths);
    sort($paths);
    return $paths;
};
$ucwp_normalize_runtime_config = static function ($config) use ($runtime_config, $ucwp_normalize_runtime_string_list, $ucwp_normalize_runtime_path_list) {
    $config = is_array($config) ? $config : array();

    $cache_query_allowlist = $ucwp_normalize_runtime_string_list($config['cache_query_allowlist'] ?? $runtime_config['cache_query_allowlist'], '/[^a-z0-9_-]/');
    $fresh_ttl_minutes = isset($config['cache_fresh_ttl_minutes']) ? (int) $config['cache_fresh_ttl_minutes'] : (int) $runtime_config['cache_fresh_ttl_minutes'];
    $fresh_ttl_minutes = max(1, min(1440, $fresh_ttl_minutes));
    $max_stale_minutes = isset($config['cache_max_stale_minutes']) ? (int) $config['cache_max_stale_minutes'] : (int) $runtime_config['cache_max_stale_minutes'];
    $max_stale_minutes = max($fresh_ttl_minutes, min(10080, $max_stale_minutes));

    return array(
        'excluded_paths'                 => $ucwp_normalize_runtime_path_list($config['excluded_paths'] ?? $runtime_config['excluded_paths']),
        'excluded_query_args'            => $ucwp_normalize_runtime_string_list($config['excluded_query_args'] ?? $runtime_config['excluded_query_args'], '/[^a-z0-9_-]/'),
        'cache_query_strings'            => !empty($config['cache_query_strings']),
        'cache_query_allowlist'          => $cache_query_allowlist,
        'woo_safe_mode'                  => !empty($config['woo_safe_mode']),
        'cache_stats_enabled'            => !empty($config['cache_stats_enabled']),
        'stale_while_revalidate_enabled' => !empty($config['stale_while_revalidate_enabled']),
        'cache_fresh_ttl_minutes'        => $fresh_ttl_minutes,
        'cache_max_stale_minutes'        => $max_stale_minutes,
        'revalidate_secret'              => isset($config['revalidate_secret']) && is_scalar($config['revalidate_secret']) ? (string) $config['revalidate_secret'] : '',
        'trusted_hosts'                  => $ucwp_normalize_runtime_string_list($config['trusted_hosts'] ?? $runtime_config['trusted_hosts']),
        'object_cache_enabled'           => !empty($config['object_cache_enabled']),
        'object_cache_backend'           => in_array(strtolower(trim((string) ($config['object_cache_backend'] ?? 'redis'))), array('redis', 'apcu', 'disk'), true) ? strtolower(trim((string) ($config['object_cache_backend'] ?? 'redis'))) : 'redis',
        'redis_host'                     => isset($config['redis_host']) && is_scalar($config['redis_host']) ? trim((string) $config['redis_host']) : '127.0.0.1',
        'redis_port'                     => max(1, min(65535, (int) ($config['redis_port'] ?? 6379))),
        'redis_password'                 => isset($config['redis_password']) && is_scalar($config['redis_password']) ? (string) $config['redis_password'] : '',
        'redis_database'                 => max(0, (int) ($config['redis_database'] ?? 0)),
        'redis_prefix'                   => isset($config['redis_prefix']) && is_scalar($config['redis_prefix']) ? preg_replace('/[^A-Za-z0-9:_\-]/', '', (string) $config['redis_prefix']) : '',
        'redis_use_tls'                  => !empty($config['redis_use_tls']),
        'redis_persistent'               => !empty($config['redis_persistent']),
        'redis_connect_timeout_ms'       => max(50, min(5000, (int) ($config['redis_connect_timeout_ms'] ?? 200))),
        'redis_read_timeout_ms'          => max(50, min(5000, (int) ($config['redis_read_timeout_ms'] ?? 200))),
    );
};
$runtime_config = $ucwp_normalize_runtime_config($runtime_config);

$runtime_config_file = rtrim(WP_CONTENT_DIR, '/\\') . '/cache/ultracache/runtime-config.php';
if ($ucwp_is_cache_path($runtime_config_file, null, true) && is_file($runtime_config_file) && is_readable($runtime_config_file)) {
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($runtime_config_file, true);
    }
    $loaded_runtime = require $runtime_config_file;
    if (is_array($loaded_runtime)) {
        $runtime_config = $ucwp_normalize_runtime_config(array_merge($runtime_config, $loaded_runtime));
    }
} else {
    $legacy_runtime_config_file = rtrim(WP_CONTENT_DIR, '/\\') . '/cache/ultracache/runtime-config.json';
    if ($ucwp_is_cache_path($legacy_runtime_config_file, null, true) && is_file($legacy_runtime_config_file) && is_readable($legacy_runtime_config_file)) {
        $loaded_runtime_raw = $ucwp_read_file($legacy_runtime_config_file);
        if (is_string($loaded_runtime_raw) && '' !== $loaded_runtime_raw) {
            $loaded_runtime = json_decode($loaded_runtime_raw, true);
            if (is_array($loaded_runtime)) {
                $runtime_config = $ucwp_normalize_runtime_config(array_merge($runtime_config, $loaded_runtime));
            }
        }
    }
}


$runtime_secret_candidates = array();
$runtime_secret_base = dirname(rtrim(ABSPATH, '/\\'));
$runtime_secret_site_token = basename(rtrim(ABSPATH, '/\\'));
$runtime_secret_site_token = is_string($runtime_secret_site_token) ? strtolower($runtime_secret_site_token) : '';
$runtime_secret_site_token = preg_replace('/[^a-z0-9._-]+/', '-', $runtime_secret_site_token);
$runtime_secret_site_token = trim((string) $runtime_secret_site_token, '.-_');
if ('' === $runtime_secret_site_token) {
    $runtime_secret_site_token = 'site';
}
if (is_string($runtime_secret_base) && '' !== trim($runtime_secret_base) && '.' !== $runtime_secret_base && '/' !== $runtime_secret_base) {
    $runtime_secret_candidates[] = rtrim($runtime_secret_base, '/\\') . '/.' . $runtime_secret_site_token . '-ultracache-runtime-secrets.php';
}
$runtime_secret_candidates = array_values(array_unique($runtime_secret_candidates));
$ucwp_is_allowed_runtime_secret = static function ($path) use ($runtime_secret_base) {
    $path = is_string($path) ? trim($path) : '';
    if ('' === $path) {
        return false;
    }

    return is_string($runtime_secret_base)
        && '' !== trim($runtime_secret_base)
        && '.' !== $runtime_secret_base
        && '/' !== $runtime_secret_base
        && ucwp_is_path_within_base($path, $runtime_secret_base, true);
};
foreach ($runtime_secret_candidates as $runtime_secret_file) {
    if (!is_string($runtime_secret_file) || '' === trim($runtime_secret_file) || !file_exists($runtime_secret_file) || !is_readable($runtime_secret_file) || !$ucwp_is_allowed_runtime_secret($runtime_secret_file)) {
        continue;
    }

    $loaded_runtime_secret = require $runtime_secret_file;
    if (is_array($loaded_runtime_secret)) {
        if (isset($loaded_runtime_secret['revalidate_secret']) && is_scalar($loaded_runtime_secret['revalidate_secret'])) {
            $runtime_config['revalidate_secret'] = (string) $loaded_runtime_secret['revalidate_secret'];
        }
        if (isset($loaded_runtime_secret['redis_password']) && is_scalar($loaded_runtime_secret['redis_password'])) {
            $runtime_config['redis_password'] = (string) $loaded_runtime_secret['redis_password'];
        }
        break;
    }
}
$runtime_config = $ucwp_normalize_runtime_config($runtime_config);
$ucwp_cache_stats_enabled = !empty($runtime_config['cache_stats_enabled']);

$revalidate_flag = isset($_GET['ucwp_revalidate']) ? (string) $_GET['ucwp_revalidate'] : '';
$revalidate_header = ucwp_server_var('HTTP_X_ULTRACACHE_REVALIDATE', '');
$revalidate_token = isset($_GET['ucwp_rt']) ? (string) $_GET['ucwp_rt'] : ucwp_server_var('HTTP_X_ULTRACACHE_TOKEN', '');
$is_revalidate_request = (
    ('1' === $revalidate_flag || '1' === $revalidate_header)
    && !empty($runtime_config['revalidate_secret'])
    && hash_equals((string) $runtime_config['revalidate_secret'], $revalidate_token)
);
if ($is_revalidate_request) {
    return;
}

$profile_bypass_header = ucwp_server_var('HTTP_X_ULTRACACHE_PROFILE_BYPASS', '');
$profile_bypass_token = ucwp_server_var('HTTP_X_ULTRACACHE_TOKEN', '');
$is_profile_bypass_request = (
    ('1' === $profile_bypass_header || 'true' === strtolower((string) $profile_bypass_header))
    && !empty($runtime_config['revalidate_secret'])
    && hash_equals((string) $runtime_config['revalidate_secret'], (string) $profile_bypass_token)
);
if ($is_profile_bypass_request) {
    if (!headers_sent()) {
        header('X-Ultra-Cache-Profile-Bypass: advanced-cache');
    }
    return;
}

$ucwp_initial_query_vars = array();
if (!empty($_SERVER['QUERY_STRING'])) {
    parse_str((string) $_SERVER['QUERY_STRING'], $ucwp_initial_query_vars);
}
$ucwp_initial_internal_control = false;
if (!empty($ucwp_initial_query_vars) && is_array($ucwp_initial_query_vars)) {
    $ucwp_initial_internal_keys = array(
        'ucwp_revalidate' => true,
        'ucwp_rt' => true,
        'ucwp_store_profile' => true,
        'ucwp_callback_profile' => true,
        'ucwp_store_profile_verbose' => true,
        'ucwp_store_profile_verbose_settings' => true,
        'ucwp_profile_bypass' => true,
        'ucwp_profile_run' => true,
        'ucwp_runtime_js_scan' => true,
        'ucwp_runtime_js_scan_id' => true,
        'ucwp_runtime_js_scan_nonce' => true,
    );
    foreach (array_keys($ucwp_initial_query_vars) as $ucwp_initial_query_key) {
        $ucwp_initial_query_key = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $ucwp_initial_query_key));
        if (isset($ucwp_initial_internal_keys[$ucwp_initial_query_key])) {
            $ucwp_initial_internal_control = true;
            break;
        }
    }
}
if (!$ucwp_initial_internal_control) {
    foreach (array(
        'HTTP_X_ULTRACACHE_REVALIDATE',
        'HTTP_X_ULTRACACHE_TOKEN',
        'HTTP_X_ULTRACACHE_PROFILE_BYPASS',
        'HTTP_X_ULTRACACHE_STORE_PROFILE',
        'HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE',
        'HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE_SETTINGS',
        'HTTP_X_ULTRACACHE_CALLBACK_PROFILE',
    ) as $ucwp_initial_header) {
        if ('' !== trim((string) ucwp_server_var($ucwp_initial_header, ''))) {
            $ucwp_initial_internal_control = true;
            break;
        }
    }
}
if ($ucwp_initial_internal_control) {
    return;
}

$force_refresh_header = strtolower((string) ucwp_server_var('HTTP_X_ULTRACACHE_FORCE_REFRESH', ''));
$internal_header = (string) ucwp_server_var('HTTP_X_ULTRACACHE_INTERNAL_REQUEST', '');
$warm_header = (string) ucwp_server_var('HTTP_X_ULTRACACHE_WARM', '');
if (('1' === $force_refresh_header || 'true' === $force_refresh_header) && ('1' === $internal_header || '1' === $warm_header)) {
    if (!headers_sent()) {
        header('X-Ultra-Cache-Force-Refresh: advanced-cache');
    }
    return;
}

$ucwp_analytics_file = rtrim(WP_CONTENT_DIR, '/\\') . '/cache/ultracache/analytics.json';
$ucwp_analytics_hit_buffer_file = rtrim(WP_CONTENT_DIR, '/\\') . '/cache/ultracache/analytics-hit-buffer.log';
$ucwp_analytics_apcu_prefix = 'ucwp_analytics_hit_buffer_' . md5((string) WP_CONTENT_DIR) . '_';
$ucwp_analytics_buffer_flush_threshold = 50;
$ucwp_analytics_buffer_flush_interval = 30;
$ucwp_analytics_file_flush_threshold = 65536;
$ucwp_default_analytics = static function () {
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
$ucwp_read_analytics = static function () use ($ucwp_analytics_file, $ucwp_default_analytics, $ucwp_read_file, $ucwp_is_cache_path) {
    $data = $ucwp_default_analytics();
    if (!$ucwp_is_cache_path($ucwp_analytics_file) || !file_exists($ucwp_analytics_file) || !is_readable($ucwp_analytics_file)) {
        return $data;
    }
    $raw = $ucwp_read_file($ucwp_analytics_file);
    if (false === $raw || '' === $raw) {
        return $data;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $data;
    }
    return array_replace_recursive($data, $decoded);
};
$ucwp_write_analytics = static function ($data) use ($ucwp_analytics_file, $ucwp_make_dir, $ucwp_write_file, $ucwp_is_cache_path) {
    if (!$ucwp_is_cache_path($ucwp_analytics_file)) {
        return false;
    }
    $dir = dirname($ucwp_analytics_file);
    if (!file_exists($dir)) {
        $ucwp_make_dir($dir, 0755, true);
    }
    return false !== $ucwp_write_file($ucwp_analytics_file, json_encode($data), LOCK_EX);
};
$ucwp_analytics_apcu_available = static function () {
    if (!function_exists('apcu_fetch') || !function_exists('apcu_add') || !function_exists('apcu_inc') || !function_exists('apcu_dec') || !function_exists('apcu_delete') || !function_exists('apcu_store')) {
        return false;
    }
    if (function_exists('apcu_enabled') && !apcu_enabled()) {
        return false;
    }
    return true;
};
$ucwp_analytics_counters = array('pageHits', 'pageStaleHits', 'bucket_orig', 'bucket_webp', 'bucket_avif', 'encoding_identity', 'encoding_gzip', 'encoding_brotli');
$ucwp_analytics_redis = null;
$ucwp_analytics_redis_attempted = false;
$ucwp_analytics_redis_prefix = static function () use (&$runtime_config) {
    $prefix = isset($runtime_config['redis_prefix']) ? preg_replace('/[^A-Za-z0-9:_\-]/', '', (string) $runtime_config['redis_prefix']) : '';
    $prefix = trim((string) $prefix, ':');
    if ('' !== $prefix) {
        $prefix .= ':';
    } else {
        $seed = (defined('DB_NAME') ? DB_NAME : '') . '|' . (defined('ABSPATH') ? ABSPATH : '') . '|' . (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '');
        $prefix = 'ucwp:' . substr((string) (function_exists('hash') ? hash('sha256', 'ucwp-redis|' . $seed) : md5('ucwp-redis|' . $seed)), 0, 12) . ':';
    }
    return $prefix . 'analytics-hit-buffer:';
};
$ucwp_get_analytics_redis = static function () use (&$ucwp_analytics_redis, &$ucwp_analytics_redis_attempted, &$runtime_config) {
    if ($ucwp_analytics_redis_attempted) {
        return $ucwp_analytics_redis;
    }
    $ucwp_analytics_redis_attempted = true;
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
            $connected = @$redis->pconnect($host, $port, $connect_timeout, 'ucwp-analytics-' . md5($host . '|' . $port . '|' . $database));
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
        $password = isset($runtime_config['redis_password']) ? (string) $runtime_config['redis_password'] : '';
        if ('' !== $password && !@$redis->auth($password)) {
            return null;
        }
        if ($database > 0 && !@$redis->select($database)) {
            return null;
        }
        $ucwp_analytics_redis = $redis;
        return $ucwp_analytics_redis;
    } catch (Throwable $e) {
        return null;
    }
};
$ucwp_collect_redis_analytics_hit_buffer = static function () use ($ucwp_get_analytics_redis, $ucwp_analytics_redis_prefix, $ucwp_analytics_counters) {
    $redis = $ucwp_get_analytics_redis();
    if (!$redis instanceof Redis) {
        return array();
    }
    $prefix = $ucwp_analytics_redis_prefix();
    $deltas = array();
    foreach ($ucwp_analytics_counters as $counter) {
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
$ucwp_decrement_redis_analytics_hit_buffer = static function ($deltas) use ($ucwp_get_analytics_redis, $ucwp_analytics_redis_prefix) {
    $redis = $ucwp_get_analytics_redis();
    if (!$redis instanceof Redis || empty($deltas)) {
        return;
    }
    $prefix = $ucwp_analytics_redis_prefix();
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
$ucwp_acquire_redis_analytics_flush_lock = static function () use ($ucwp_get_analytics_redis, $ucwp_analytics_redis_prefix) {
    $redis = $ucwp_get_analytics_redis();
    if (!$redis instanceof Redis) {
        return false;
    }
    try {
        return (bool) $redis->set($ucwp_analytics_redis_prefix() . 'flush_lock', '1', array('nx', 'ex' => 10));
    } catch (Throwable $e) {
        return false;
    }
};
$ucwp_release_redis_analytics_flush_lock = static function () use ($ucwp_get_analytics_redis, $ucwp_analytics_redis_prefix) {
    $redis = $ucwp_get_analytics_redis();
    if ($redis instanceof Redis) {
        try {
            $redis->del($ucwp_analytics_redis_prefix() . 'flush_lock');
        } catch (Throwable $e) {
        }
    }
};
$ucwp_apply_analytics_hit_delta = static function (&$data, $counter, $amount) {
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
$ucwp_collect_apcu_analytics_hit_buffer = static function () use ($ucwp_analytics_apcu_available, $ucwp_analytics_apcu_prefix) {
    if (!$ucwp_analytics_apcu_available()) {
        return array();
    }
    $deltas = array();
    foreach (array('pageHits', 'pageStaleHits', 'bucket_orig', 'bucket_webp', 'bucket_avif', 'encoding_identity', 'encoding_gzip', 'encoding_brotli') as $counter) {
        $ok = false;
        $value = apcu_fetch($ucwp_analytics_apcu_prefix . $counter, $ok);
        $value = $ok ? max(0, (int) $value) : 0;
        if ($value > 0) {
            $deltas[$counter] = $value;
        }
    }
    return $deltas;
};
$ucwp_consume_file_analytics_hit_buffer = static function () use ($ucwp_analytics_hit_buffer_file, $ucwp_is_cache_path) {
    if (!$ucwp_is_cache_path($ucwp_analytics_hit_buffer_file) || !file_exists($ucwp_analytics_hit_buffer_file) || !is_readable($ucwp_analytics_hit_buffer_file)) {
        return array();
    }
    $handle = @fopen($ucwp_analytics_hit_buffer_file, 'c+');
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
$ucwp_flush_analytics_hit_buffer = static function () use ($ucwp_cache_stats_enabled, $ucwp_read_analytics, $ucwp_write_analytics, $ucwp_apply_analytics_hit_delta, $ucwp_collect_apcu_analytics_hit_buffer, $ucwp_consume_file_analytics_hit_buffer, $ucwp_analytics_apcu_available, $ucwp_analytics_apcu_prefix, $ucwp_collect_redis_analytics_hit_buffer, $ucwp_decrement_redis_analytics_hit_buffer, $ucwp_acquire_redis_analytics_flush_lock, $ucwp_release_redis_analytics_flush_lock) {
    if (!$ucwp_cache_stats_enabled) {
        return false;
    }
    $apcu_lock_acquired = false;
    if ($ucwp_analytics_apcu_available()) {
        if (@apcu_add($ucwp_analytics_apcu_prefix . 'flush_lock', 1, 10)) {
            $apcu_lock_acquired = true;
        }
    }

    $redis_lock_acquired = $ucwp_acquire_redis_analytics_flush_lock();
    if (!$apcu_lock_acquired && !$redis_lock_acquired) {
        return false;
    }

    $apcu_deltas = $apcu_lock_acquired ? $ucwp_collect_apcu_analytics_hit_buffer() : array();
    $redis_deltas = $redis_lock_acquired ? $ucwp_collect_redis_analytics_hit_buffer() : array();

    // Legacy drain only: old builds may have left this buffer behind. New hits
    // never write to disk when APCu/Redis is unavailable.
    $file_deltas = $ucwp_consume_file_analytics_hit_buffer();

    if (empty($apcu_deltas) && empty($redis_deltas) && empty($file_deltas)) {
        if ($apcu_lock_acquired) {
            @apcu_delete($ucwp_analytics_apcu_prefix . 'flush_lock');
        }
        if ($redis_lock_acquired) {
            $ucwp_release_redis_analytics_flush_lock();
        }
        return false;
    }

    $data = $ucwp_read_analytics();
    foreach (array($apcu_deltas, $redis_deltas, $file_deltas) as $deltas) {
        foreach ($deltas as $counter => $amount) {
            $ucwp_apply_analytics_hit_delta($data, $counter, $amount);
        }
    }

    if (!$ucwp_write_analytics($data)) {
        if ($apcu_lock_acquired) {
            @apcu_delete($ucwp_analytics_apcu_prefix . 'flush_lock');
        }
        if ($redis_lock_acquired) {
            $ucwp_release_redis_analytics_flush_lock();
        }
        return false;
    }

    if ($apcu_lock_acquired) {
        foreach ($apcu_deltas as $counter => $amount) {
            $amount = max(0, (int) $amount);
            if ($amount > 0) {
                @apcu_dec($ucwp_analytics_apcu_prefix . (string) $counter, $amount);
            }
        }
        @apcu_delete($ucwp_analytics_apcu_prefix . 'total');
        @apcu_store($ucwp_analytics_apcu_prefix . 'last_flush', time(), 3600);
        @apcu_delete($ucwp_analytics_apcu_prefix . 'flush_lock');
    }
    if ($redis_lock_acquired) {
        $ucwp_decrement_redis_analytics_hit_buffer($redis_deltas);
        $ucwp_release_redis_analytics_flush_lock();
    }

    return true;
};
$ucwp_record_hit = static function ($bucket, $encoding_bucket, $stale = false) use ($ucwp_cache_stats_enabled, $ucwp_analytics_apcu_available, $ucwp_analytics_apcu_prefix, $ucwp_analytics_buffer_flush_threshold, $ucwp_analytics_buffer_flush_interval, $ucwp_flush_analytics_hit_buffer, $ucwp_get_analytics_redis, $ucwp_analytics_redis_prefix) {
    if (!$ucwp_cache_stats_enabled) {
        return false;
    }
    $bucket = in_array($bucket, array('orig', 'webp', 'avif'), true) ? $bucket : 'orig';
    $encoding_bucket = in_array($encoding_bucket, array('identity', 'gzip', 'brotli'), true) ? $encoding_bucket : 'identity';
    $counters = array($stale ? 'pageStaleHits' : 'pageHits', 'bucket_' . $bucket, 'encoding_' . $encoding_bucket);

    if ($ucwp_analytics_apcu_available()) {
        foreach ($counters as $counter) {
            @apcu_add($ucwp_analytics_apcu_prefix . $counter, 0, 3600);
            @apcu_inc($ucwp_analytics_apcu_prefix . $counter, 1);
        }
        @apcu_add($ucwp_analytics_apcu_prefix . 'total', 0, 3600);
        $total = (int) @apcu_inc($ucwp_analytics_apcu_prefix . 'total', 1);
        $last_flush = (int) @apcu_fetch($ucwp_analytics_apcu_prefix . 'last_flush');
        if ($total >= $ucwp_analytics_buffer_flush_threshold || (time() - $last_flush) >= $ucwp_analytics_buffer_flush_interval) {
            $ucwp_flush_analytics_hit_buffer();
        }
        return true;
    }

    $redis = $ucwp_get_analytics_redis();
    if ($redis instanceof Redis) {
        $prefix = $ucwp_analytics_redis_prefix();
        try {
            foreach ($counters as $counter) {
                $redis->incr($prefix . $counter);
                $redis->expire($prefix . $counter, 3600);
            }
            $total = (int) $redis->incr($prefix . 'total');
            $redis->expire($prefix . 'total', 3600);
            $last_flush = (int) $redis->get($prefix . 'last_flush');
            if ($total >= $ucwp_analytics_buffer_flush_threshold || (time() - $last_flush) >= $ucwp_analytics_buffer_flush_interval) {
                $ucwp_flush_analytics_hit_buffer();
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // No APCu and no usable Redis: disable per-hit analytics entirely.
    return false;
};
$ucwp_record_background_revalidation = static function () use ($ucwp_cache_stats_enabled, $ucwp_read_analytics, $ucwp_write_analytics, $ucwp_flush_analytics_hit_buffer) {
    if (!$ucwp_cache_stats_enabled) {
        return false;
    }
    $ucwp_flush_analytics_hit_buffer();
    $data = $ucwp_read_analytics();
    $data['pageBackgroundRevalidations'] = (int) ($data['pageBackgroundRevalidations'] ?? 0) + 1;
    $ucwp_write_analytics($data);
};
$ucwp_get_revalidate_lock_path = static function ($cache_file) {
    return (string) $cache_file . '.revalidate.lock';
};
$ucwp_can_queue_revalidate = static function ($lock_file, $max_stale_seconds) use ($ucwp_get_filemtime, $ucwp_is_cache_path) {
    if (!$ucwp_is_cache_path($lock_file) || !file_exists($lock_file)) {
        return true;
    }
    $mtime = $ucwp_get_filemtime($lock_file);
    if (!$mtime) {
        return true;
    }
    $lock_ttl = max(30, min(300, (int) $max_stale_seconds));
    return (time() - (int) $mtime) > $lock_ttl;
};
$ucwp_write_revalidate_lock = static function ($lock_file) use ($ucwp_make_dir, $ucwp_write_file, $ucwp_is_cache_path) {
    if (!$ucwp_is_cache_path($lock_file)) {
        return;
    }
    $dir = dirname($lock_file);
    if (!file_exists($dir)) {
        $ucwp_make_dir($dir, 0755, true);
    }
    $ucwp_write_file($lock_file, (string) time(), LOCK_EX);
};
$ucwp_queue_revalidate = static function ($target_url, $secret) {
    $secret = (string) $secret;
    if ('' === $target_url || '' === $secret) {
        return false;
    }
    $separator = false !== strpos($target_url, '?') ? '&' : '?';
    $request_url = $target_url . $separator . 'ucwp_revalidate=1&ucwp_rt=' . rawurlencode($secret);

    if (function_exists('curl_init')) {
        $ch = curl_init();
        if ($ch) {
            curl_setopt($ch, CURLOPT_URL, $request_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1000);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 500);
            curl_setopt($ch, CURLOPT_NOSIGNAL, true);
            curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
            curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-UltraCache-Revalidate: 1', 'Connection: close'));
            $result = curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            return false !== $result && 0 === (int) $errno;
        }
    }

    $parts = parse_url($request_url);
    if (empty($parts['host'])) {
        return false;
    }

    $scheme = !empty($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'http';
    $host = (string) $parts['host'];
    $port = isset($parts['port']) ? (int) $parts['port'] : ('https' === $scheme ? 443 : 80);
    $path = (!empty($parts['path']) ? (string) $parts['path'] : '/') . (!empty($parts['query']) ? '?' . (string) $parts['query'] : '');
    $remote = ('https' === $scheme ? 'ssl://' : 'tcp://') . $host . ':' . $port;

    $errno = 0;
    $errstr = '';
    $fp = ucwp_dropin_safe_stream_socket_client($remote, 0.5, $errno, $errstr);
    if (!$fp) {
        return false;
    }

    stream_set_blocking($fp, false);
    $out = "GET {$path} HTTP/1.1
";
    $out .= "Host: {$host}
";
    $out .= "Connection: Close
";
    $out .= "X-UltraCache-Revalidate: 1

";
    if (false === fwrite($fp, $out)) {
        fclose($fp);
        return false;
    }
    fclose($fp);
    return true;
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

$ucwp_sort_query_value = static function ($value) use (&$ucwp_sort_query_value) {
    if (!is_array($value)) {
        return $value;
    }

    foreach ($value as $key => $child) {
        $value[$key] = $ucwp_sort_query_value($child);
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

$ucwp_normalize_query_vars = static function ($query_vars, $allowlist) use ($ucwp_sort_query_value) {
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

        $filtered[$normalized_key] = $ucwp_sort_query_value($query_value);
    }

    if (empty($filtered)) {
        return array();
    }

    ksort($filtered);
    return $filtered;
};

$ucwp_normalize_query_key = static function ($query_key) {
    $query_key = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $query_key));
    return is_string($query_key) ? $query_key : '';
};

$ucwp_get_first_non_allowlisted_query_key = static function ($query_vars, $allowlist) use ($ucwp_normalize_query_key) {
    if (!is_array($query_vars) || empty($query_vars)) {
        return '';
    }

    $lookup = array();
    foreach ((array) $allowlist as $allowed_key) {
        $allowed_key = $ucwp_normalize_query_key($allowed_key);
        if ('' !== $allowed_key) {
            $lookup[$allowed_key] = true;
        }
    }

    if (empty($lookup)) {
        return '';
    }

    foreach (array_keys($query_vars) as $query_key) {
        $normalized_key = $ucwp_normalize_query_key($query_key);
        if ('' === $normalized_key || !isset($lookup[$normalized_key])) {
            return '' !== $normalized_key ? $normalized_key : (string) $query_key;
        }
    }

    return '';
};

$ucwp_hard_security_query_args = array(
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
    'ucwp_revalidate',
    'ucwp_rt',
    'ucwp_store_profile',
    'ucwp_callback_profile',
    'ucwp_store_profile_verbose',
    'ucwp_store_profile_verbose_settings',
    'ucwp_profile_bypass',
    'ucwp_profile_run',
    'ucwp_runtime_js_scan',
    'ucwp_runtime_js_scan_id',
    'ucwp_runtime_js_scan_nonce',
);

$ucwp_hard_security_paths = array(
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

$ucwp_internal_control_query_args = array(
    'ucwp_revalidate',
    'ucwp_rt',
    'ucwp_store_profile',
    'ucwp_callback_profile',
    'ucwp_store_profile_verbose',
    'ucwp_store_profile_verbose_settings',
    'ucwp_profile_bypass',
    'ucwp_profile_run',
    'ucwp_runtime_js_scan',
    'ucwp_runtime_js_scan_id',
    'ucwp_runtime_js_scan_nonce',
);

$ucwp_has_internal_control_query_marker = static function ($query_vars) use ($ucwp_normalize_query_key, $ucwp_internal_control_query_args) {
    if (!is_array($query_vars) || empty($query_vars)) {
        return false;
    }

    $lookup = array_fill_keys($ucwp_internal_control_query_args, true);
    foreach (array_keys($query_vars) as $query_key) {
        $query_key = $ucwp_normalize_query_key($query_key);
        if (isset($lookup[$query_key])) {
            return true;
        }
    }

    return false;
};

$ucwp_has_internal_control_header_marker = static function () {
    foreach (array(
        'HTTP_X_ULTRACACHE_REVALIDATE',
        'HTTP_X_ULTRACACHE_TOKEN',
        'HTTP_X_ULTRACACHE_PROFILE_BYPASS',
        'HTTP_X_ULTRACACHE_STORE_PROFILE',
        'HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE',
        'HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE_SETTINGS',
        'HTTP_X_ULTRACACHE_CALLBACK_PROFILE',
    ) as $header) {
        if ('' !== trim((string) ucwp_server_var($header, ''))) {
            return true;
        }
    }

    return false;
};

$ucwp_normalize_host = static function ($host) {

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
$ucwp_trusted_hosts = array();
foreach ((array) ($runtime_config['trusted_hosts'] ?? array()) as $trusted_host) {
    $trusted_host = $ucwp_normalize_host($trusted_host);
    if ('' !== $trusted_host) {
        $ucwp_trusted_hosts[$trusted_host] = true;
    }
}
$incoming_host = $ucwp_normalize_host($raw_http_host);
if ('' === $incoming_host || empty($ucwp_trusted_hosts) || !isset($ucwp_trusted_hosts[$incoming_host])) {
    return;
}

$ucwp_detect_request_scheme = static function () {
    $https_value = strtolower((string) ucwp_server_var('HTTPS', ''));
    $server_port = ucwp_server_var('SERVER_PORT', '');
    $is_ssl = ('' !== $https_value && 'off' !== $https_value)
        || ('443' === $server_port);

    if ($is_ssl) {
        return 'https';
    }

    $forwarded_proto_parts = explode(',', ucwp_server_var('HTTP_X_FORWARDED_PROTO', ''));
    $forwarded_proto = strtolower(trim((string) reset($forwarded_proto_parts)));
    if ('https' === $forwarded_proto) {
        return 'https';
    }

    $forwarded_scheme = strtolower(trim((string) ucwp_server_var('HTTP_X_FORWARDED_SCHEME', '')));
    if ('https' === $forwarded_scheme) {
        return 'https';
    }

    $forwarded_ssl = strtolower(trim((string) ucwp_server_var('HTTP_X_FORWARDED_SSL', '')));
    if (in_array($forwarded_ssl, array('on', '1', 'true', 'https'), true)) {
        return 'https';
    }

    $frontend_https = strtolower(trim((string) ucwp_server_var('HTTP_FRONT_END_HTTPS', '')));
    if (in_array($frontend_https, array('on', '1', 'true'), true)) {
        return 'https';
    }

    $cloudfront_proto = strtolower(trim((string) ucwp_server_var('HTTP_CLOUDFRONT_FORWARDED_PROTO', '')));
    if ('https' === $cloudfront_proto) {
        return 'https';
    }

    $cf_visitor = (string) ucwp_server_var('HTTP_CF_VISITOR', '');
    if (false !== stripos($cf_visitor, '"scheme":"https"')) {
        return 'https';
    }

    return 'http';
};
$scheme = $ucwp_detect_request_scheme();
$url = $scheme . '://' . $incoming_host . $request_uri;
$parts = parse_url($url);
if (empty($parts['host'])) {
    return;
}

$path = isset($parts['path']) ? $normalize_path((string) $parts['path']) : '/';
foreach ($ucwp_hard_security_paths as $excluded_path) {
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
foreach ($ucwp_hard_security_query_args as $excluded_query_arg) {
    $excluded_query_arg = $ucwp_normalize_query_key($excluded_query_arg);
    if ('' !== $excluded_query_arg) {
        $excluded_query_args_lookup[$excluded_query_arg] = true;
    }
}
foreach ((array) ($runtime_config['excluded_query_args'] ?? array()) as $excluded_query_arg) {
    $excluded_query_arg = $ucwp_normalize_query_key($excluded_query_arg);
    if ('' !== $excluded_query_arg) {
        $excluded_query_args_lookup[$excluded_query_arg] = true;
    }
}
foreach (array_keys($query_vars) as $query_key) {
    $query_key = $ucwp_normalize_query_key($query_key);
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

    if ('' !== $ucwp_get_first_non_allowlisted_query_key($query_vars, $query_allowlist)) {
        return;
    }

    $normalized_query_vars = $ucwp_normalize_query_vars($query_vars, $query_allowlist);
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

$accept = strtolower((string) ucwp_server_var('HTTP_ACCEPT', ''));
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
$cache_file = rtrim(WP_CONTENT_DIR, '/\\') . '/cache/ultracache/' . $host . '/' . $cache_key_path . '/index-' . $bucket . '-' . $hash . '.html';
if (!$ucwp_is_cache_path($cache_file) || !file_exists($cache_file) || !is_readable($cache_file)) {
    return;
}

if (!$ucwp_validate_generated_css_refs($cache_file)) {
    return;
}

$encoding = strtolower((string) ucwp_server_var('HTTP_ACCEPT_ENCODING', ''));
$serve_file = $cache_file;
$encoding_bucket = 'identity';
if (false !== strpos($encoding, 'br') && $ucwp_is_cache_path($cache_file . '.br') && file_exists($cache_file . '.br') && is_readable($cache_file . '.br')) {
    $serve_file = $cache_file . '.br';
    $encoding_bucket = 'brotli';
    header('X-UltraCache-Encoding: brotli');
    header('Content-Encoding: br');
} elseif (false !== strpos($encoding, 'gzip') && $ucwp_is_cache_path($cache_file . '.gz') && file_exists($cache_file . '.gz') && is_readable($cache_file . '.gz')) {
    $serve_file = $cache_file . '.gz';
    $encoding_bucket = 'gzip';
    header('X-UltraCache-Encoding: gzip');
    header('Content-Encoding: gzip');
}

if (!$ucwp_is_cache_path($serve_file) || !is_readable($serve_file)) {
    return;
}

$fresh_ttl = max(60, (int) ($runtime_config['cache_fresh_ttl_minutes'] ?? 15) * 60);
$max_stale = max($fresh_ttl, (int) ($runtime_config['cache_max_stale_minutes'] ?? 60) * 60);
$mtime = $ucwp_get_filemtime($cache_file);
$age = $mtime ? max(0, time() - (int) $mtime) : 0;
$serve_until = !empty($runtime_config['stale_while_revalidate_enabled']) ? $max_stale : $fresh_ttl;
if ($age > $serve_until) {
    return;
}

if (!empty($runtime_config['stale_while_revalidate_enabled']) && $age > $fresh_ttl && $age <= $max_stale) {
    $lock_file = $ucwp_get_revalidate_lock_path($cache_file);
    $queued = false;
    if ($ucwp_can_queue_revalidate($lock_file, $max_stale)) {
        $ucwp_write_revalidate_lock($lock_file);
        $queued = $ucwp_queue_revalidate($normalized, (string) ($runtime_config['revalidate_secret'] ?? ''));
        if ($queued) {
            $ucwp_record_background_revalidation();
        } else {
            $ucwp_delete_file($lock_file);
        }
    }

    $ucwp_record_hit($bucket, $encoding_bucket, true);
    header('Content-Type: text/html; charset=UTF-8');
    header('Vary: Accept, Accept-Encoding', false);
    header('X-Ultra-Cache: STALE');
    if (ucwp_advanced_cache_debug_headers_enabled()) {
        header('X-Ultra-Cache-Source: advanced-cache');
    }
    header('X-Ultra-Cache-Age: ' . (string) $age);
    header('X-Ultra-Cache-Revalidate: ' . ($queued ? 'queued' : 'pending'));
    $ucwp_dropin_safe_readfile($serve_file);
    exit;
}

$ucwp_record_hit($bucket, $encoding_bucket, false);

header('Content-Type: text/html; charset=UTF-8');
header('Vary: Accept, Accept-Encoding', false);
header('X-Ultra-Cache: HIT');
if (ucwp_advanced_cache_debug_headers_enabled()) {
    header('X-Ultra-Cache-Source: advanced-cache');
}
header('X-Ultra-Cache-Age: ' . (string) $age);
$ucwp_dropin_safe_readfile($serve_file);
exit;
