<?php
/**
 * UltraCache advanced-cache drop-in.
 * Marker: UltraCache advanced-cache drop-in
 * Drop-in Build: __UCWP_DROPIN_BUILD__
 */
if (!defined('ABSPATH')) {
    return;
}

if (!function_exists('ucwp_safe_file_get_contents')) {
    function ucwp_safe_file_get_contents($file) {
        return is_readable($file) ? file_get_contents($file) : false;
    }
}

if (!function_exists('ucwp_safe_file_put_contents')) {
    function ucwp_safe_file_put_contents($file, $data, $flags = 0) {
        return file_put_contents($file, $data, $flags);
    }
}

if (!function_exists('ucwp_safe_unlink')) {
    function ucwp_safe_unlink($file) {
        return !file_exists($file) ? true : unlink($file);
    }
}

if (!function_exists('ucwp_safe_rename')) {
    function ucwp_safe_rename($from, $to) {
        return rename($from, $to);
    }
}

if (!function_exists('ucwp_safe_mkdir')) {
    function ucwp_safe_mkdir($dir, $mode = 0755, $recursive = true) {
        if (is_dir($dir)) {
            return true;
        }
        return mkdir($dir, $mode, $recursive) || is_dir($dir);
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
    return mkdir($dir, $mode, $recursive) || is_dir($dir);
};

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
if (!in_array($method, array('GET', 'HEAD'), true)) {
    return;
}

if (empty($_SERVER['HTTP_HOST']) || empty($_SERVER['REQUEST_URI'])) {
    return;
}

$request_uri = (string) $_SERVER['REQUEST_URI'];
if (strpos($request_uri, '/wp-admin/') === 0 || strpos($request_uri, '/wp-login.php') === 0) {
    return;
}

foreach ((array) ($_COOKIE ?? array()) as $cookie_name => $cookie_value) {
    foreach (array(
        'wordpress_logged_in_',
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
    ),
    'cache_query_strings'            => false,
    'cache_query_allowlist'          => array(),
    'woo_safe_mode'                  => true,
    'stale_while_revalidate_enabled' => true,
    'cache_fresh_ttl_minutes'        => 1440,
    'cache_max_stale_minutes'        => 10080,
    'revalidate_secret'              => '',
    'trusted_hosts'                  => array(),
);

$runtime_config_file = rtrim(WP_CONTENT_DIR, '/\\') . '/cache/ultracache/runtime-config.json';
if (file_exists($runtime_config_file) && is_readable($runtime_config_file)) {
    $loaded_runtime_raw = @file_get_contents($runtime_config_file);
    if (is_string($loaded_runtime_raw) && '' !== $loaded_runtime_raw) {
        $loaded_runtime = json_decode($loaded_runtime_raw, true);
        if (is_array($loaded_runtime)) {
            $runtime_config = array_merge($runtime_config, $loaded_runtime);
        }
    }
}

$is_revalidate_request = (
    ('1' === (string) ($_GET['ucwp_revalidate'] ?? '') || '1' === (string) ($_SERVER['HTTP_X_ULTRACACHE_REVALIDATE'] ?? ''))
    && !empty($runtime_config['revalidate_secret'])
    && hash_equals((string) $runtime_config['revalidate_secret'], (string) ($_GET['ucwp_rt'] ?? ($_SERVER['HTTP_X_ULTRACACHE_TOKEN'] ?? '')))
);
if ($is_revalidate_request) {
    return;
}

$ucwp_analytics_file = rtrim(WP_CONTENT_DIR, '/\\') . '/cache/ultracache/analytics.json';
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
$ucwp_read_analytics = static function () use ($ucwp_analytics_file, $ucwp_default_analytics, $ucwp_read_file) {
    $data = $ucwp_default_analytics();
    if (!file_exists($ucwp_analytics_file) || !is_readable($ucwp_analytics_file)) {
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
$ucwp_write_analytics = static function ($data) use ($ucwp_analytics_file, $ucwp_make_dir, $ucwp_write_file) {
    $dir = dirname($ucwp_analytics_file);
    if (!file_exists($dir)) {
        $ucwp_make_dir($dir, 0755, true);
    }
    $ucwp_write_file($ucwp_analytics_file, json_encode($data), LOCK_EX);
};
$ucwp_record_hit = static function ($bucket, $encoding_bucket, $stale = false) use ($ucwp_read_analytics, $ucwp_write_analytics) {
    $data = $ucwp_read_analytics();
    if ($stale) {
        $data['pageStaleHits'] = (int) ($data['pageStaleHits'] ?? 0) + 1;
    } else {
        $data['pageHits'] = (int) ($data['pageHits'] ?? 0) + 1;
    }
    if (!isset($data['bucketHits']) || !is_array($data['bucketHits'])) {
        $data['bucketHits'] = array('orig' => 0, 'webp' => 0, 'avif' => 0);
    }
    if (!isset($data['bucketHits'][$bucket])) {
        $data['bucketHits'][$bucket] = 0;
    }
    $data['bucketHits'][$bucket] = (int) $data['bucketHits'][$bucket] + 1;
    if (!isset($data['encodingHits']) || !is_array($data['encodingHits'])) {
        $data['encodingHits'] = array('identity' => 0, 'gzip' => 0, 'brotli' => 0);
    }
    if (!isset($data['encodingHits'][$encoding_bucket])) {
        $data['encodingHits'][$encoding_bucket] = 0;
    }
    $data['encodingHits'][$encoding_bucket] = (int) $data['encodingHits'][$encoding_bucket] + 1;
    $ucwp_write_analytics($data);
};
$ucwp_record_background_revalidation = static function () use ($ucwp_read_analytics, $ucwp_write_analytics) {
    $data = $ucwp_read_analytics();
    $data['pageBackgroundRevalidations'] = (int) ($data['pageBackgroundRevalidations'] ?? 0) + 1;
    $ucwp_write_analytics($data);
};
$ucwp_get_revalidate_lock_path = static function ($cache_file) {
    return (string) $cache_file . '.revalidate.lock';
};
$ucwp_can_queue_revalidate = static function ($lock_file, $max_stale_seconds) {
    if (!file_exists($lock_file)) {
        return true;
    }
    $mtime = filemtime($lock_file);
    if (!$mtime) {
        return true;
    }
    $lock_ttl = max(30, min(300, (int) $max_stale_seconds));
    return (time() - (int) $mtime) > $lock_ttl;
};
$ucwp_write_revalidate_lock = static function ($lock_file) use ($ucwp_make_dir, $ucwp_write_file) {
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
            curl_exec($ch);
            curl_close($ch);
            return true;
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
    $remote = 'https' === $scheme ? 'ssl://' . $host : $host;

    $fp = fsockopen($remote, $port, $errno, $errstr, 0.5);
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
    fwrite($fp, $out);
    @fclose($fp);
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
    if (!is_array($query_vars) || empty($query_vars)) {
        return array();
    }

    $lookup = array();
    foreach ((array) $allowlist as $allowed_key) {
        $allowed_key = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $allowed_key));
        if ('' !== $allowed_key) {
            $lookup[$allowed_key] = true;
        }
    }

    if (empty($lookup)) {
        return array();
    }

    $filtered = array();
    foreach ($query_vars as $query_key => $query_value) {
        $normalized_key = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $query_key));
        if ('' === $normalized_key || !isset($lookup[$normalized_key])) {
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
$incoming_host = $ucwp_normalize_host((string) $_SERVER['HTTP_HOST']);
if ('' === $incoming_host || empty($ucwp_trusted_hosts) || !isset($ucwp_trusted_hosts[$incoming_host])) {
    return;
}

$is_ssl = (!empty($_SERVER['HTTPS']) && 'off' !== strtolower((string) $_SERVER['HTTPS']))
    || (isset($_SERVER['SERVER_PORT']) && '443' === (string) $_SERVER['SERVER_PORT']);
$scheme = $is_ssl ? 'https' : 'http';
$url = $scheme . '://' . $incoming_host . (string) $_SERVER['REQUEST_URI'];
$parts = parse_url($url);
if (empty($parts['host'])) {
    return;
}

$path = isset($parts['path']) ? $normalize_path((string) $parts['path']) : '/';
foreach ((array) ($runtime_config['excluded_paths'] ?? array()) as $excluded_path) {
    if ($path_matches_rule($path, $excluded_path)) {
        return;
    }
}

$query_vars = array();
if (!empty($parts['query'])) {
    parse_str((string) $parts['query'], $query_vars);
}

foreach (array_keys($query_vars) as $query_key) {
    if (in_array((string) $query_key, (array) ($runtime_config['excluded_query_args'] ?? array()), true)) {
        return;
    }
}

$normalized_query_vars = array();
if (!empty($query_vars)) {
    if (empty($runtime_config['cache_query_strings'])) {
        return;
    }

    $normalized_query_vars = $ucwp_normalize_query_vars($query_vars, (array) ($runtime_config['cache_query_allowlist'] ?? array()));
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

$accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
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
if (!file_exists($cache_file)) {
    return;
}

$encoding = strtolower((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
$serve_file = $cache_file;
$encoding_bucket = 'identity';
if (false !== strpos($encoding, 'br') && file_exists($cache_file . '.br')) {
    $serve_file = $cache_file . '.br';
    $encoding_bucket = 'brotli';
    header('Content-Encoding: br');
} elseif (false !== strpos($encoding, 'gzip') && file_exists($cache_file . '.gz')) {
    $serve_file = $cache_file . '.gz';
    $encoding_bucket = 'gzip';
    header('Content-Encoding: gzip');
}

$fresh_ttl = max(60, (int) ($runtime_config['cache_fresh_ttl_minutes'] ?? 15) * 60);
$max_stale = max($fresh_ttl, (int) ($runtime_config['cache_max_stale_minutes'] ?? 60) * 60);
$mtime = @filemtime($cache_file);
$age = $mtime ? max(0, time() - (int) $mtime) : 0;

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
    header('X-Ultra-Cache-Age: ' . (string) $age);
    header('X-Ultra-Cache-Revalidate: ' . ($queued ? 'queued' : 'pending'));
    readfile($serve_file);
    exit;
}

$ucwp_record_hit($bucket, $encoding_bucket, false);

header('Content-Type: text/html; charset=UTF-8');
header('Vary: Accept, Accept-Encoding', false);
header('X-Ultra-Cache: HIT');
header('X-Ultra-Cache-Age: ' . (string) $age);
readfile($serve_file);
exit;
