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

function ultracache_advanced_cache_normalize_cache_host_segment($host) {
    $host = strtolower(rtrim(trim((string) $host), '.'));
    if ('' === $host) {
        return 'site';
    }

    if ('[' === substr($host, 0, 1) && ']' === substr($host, -1)) {
        return 'ipv6-' . substr(hash('sha256', $host), 0, 32);
    }

    $host = preg_replace('/[^a-z0-9._-]+/', '-', $host);
    $host = is_string($host) ? trim($host, '-') : '';
    if ('' === $host || '.' === $host || '..' === $host) {
        return 'site';
    }

    return $host;
}

function ultracache_advanced_cache_normalize_query_policy_key($key) {
    if (is_array($key) || is_object($key)) {
        return '';
    }
    $key = strtolower((string) $key);
    $key = preg_replace('/[^a-z0-9_-]/', '', $key);
    return is_string($key) ? $key : '';
}

function ultracache_advanced_cache_normalize_query_policy_keys($keys) {
    if (!is_array($keys)) {
        $keys = preg_split('/\r?\n/', (string) $keys);
    }
    $normalized = array();
    foreach ((array) $keys as $key) {
        $key = ultracache_advanced_cache_normalize_query_policy_key($key);
        if ('' !== $key) {
            $normalized[$key] = true;
        }
    }
    $normalized = array_keys($normalized);
    sort($normalized, SORT_STRING);
    return $normalized;
}

function ultracache_advanced_cache_query_policy_hard_blocked_defaults() {
    return array(
        '_ajax_nonce', '_wpnonce', 'access_token', 'auth', 'auth_token', 'cancel_order',
        'customer-logout', 'download_file', 'key', 'logout', 'nonce', 'order_key',
        'pass', 'password', 'pay_for_order', 'pwd', 'redirect_to', 'rest_route',
        'security', 'token', 'ultracache_bucket', 'ultracache_callback_profile',
        'ultracache_probe_compression', 'ultracache_profile_bypass', 'ultracache_profile_run',
        'ultracache_revalidate', 'ultracache_rt', 'ultracache_runtime_js_scan',
        'ultracache_runtime_js_scan_id', 'ultracache_runtime_js_scan_token', 'ultracache_runtime_js_scan_nonce',
        'ultracache_runtime_js_scan_mode', 'ultracache_runtime_js_scan_context', 'ultracache_rv',
        'ultracache_sqlite_exposure_probe', 'ultracache_store_profile',
        'ultracache_store_profile_verbose', 'ultracache_store_profile_verbose_settings',
        'ultracache_varnish_canary',
    );
}

function ultracache_advanced_cache_build_query_cache_policy($enabled, $allowlist = array(), $excluded_query_args = array()) {
    $configured_allowlist = ultracache_advanced_cache_normalize_query_policy_keys($allowlist);
    $hard_blocked_keys = ultracache_advanced_cache_normalize_query_policy_keys(array_merge(
        ultracache_advanced_cache_query_policy_hard_blocked_defaults(),
        is_array($excluded_query_args) ? $excluded_query_args : preg_split('/\r?\n/', (string) $excluded_query_args)
    ));
    $hard_lookup = array_fill_keys($hard_blocked_keys, true);
    $effective_allowlist = array_values(array_filter($configured_allowlist, static function ($key) use ($hard_lookup) {
        return !isset($hard_lookup[$key]);
    }));
    $enabled = (bool) $enabled;
    $fingerprint_material = implode("\n", array(
        'ultracache-query-cache-policy-v1',
        $enabled ? '1' : '0',
        implode(',', $effective_allowlist),
        implode(',', $hard_blocked_keys),
    ));
    return array(
        'version' => 1,
        'enabled' => $enabled,
        'configured_allowlist' => $configured_allowlist,
        'allowlist' => $effective_allowlist,
        'hard_blocked_keys' => $hard_blocked_keys,
        'fingerprint' => hash('sha256', $fingerprint_material),
    );
}

function ultracache_advanced_cache_normalize_query_cache_policy($policy, $fallback = array()) {
    $policy = is_array($policy) ? $policy : array();
    $fallback = is_array($fallback) ? $fallback : array();
    $enabled = array_key_exists('enabled', $policy) ? !empty($policy['enabled']) : !empty($fallback['cache_query_strings']);
    $allowlist = array_key_exists('configured_allowlist', $policy)
        ? $policy['configured_allowlist']
        : (array_key_exists('allowlist', $policy) ? $policy['allowlist'] : ($fallback['cache_query_allowlist'] ?? array()));
    $excluded = array_merge(
        (array) ($policy['hard_blocked_keys'] ?? array()),
        (array) ($fallback['excluded_query_args'] ?? array())
    );
    return ultracache_advanced_cache_build_query_cache_policy($enabled, $allowlist, $excluded);
}

function ultracache_advanced_cache_normalize_wpml_parameter_cache_contract($contract) {
    $contract = is_array($contract) ? $contract : array();
    $languages = array();
    foreach ((array) ($contract['languages'] ?? array()) as $language_code) {
        if (is_array($language_code) || is_object($language_code)) {
            continue;
        }
        $language_code = strtolower(trim((string) $language_code));
        if ('' !== $language_code && 1 === preg_match('/^[a-z0-9_-]+$/', $language_code)) {
            $languages[$language_code] = true;
        }
    }
    $languages = array_keys($languages);
    sort($languages, SORT_STRING);

    $default_language = strtolower(trim((string) ($contract['default_language'] ?? '')));
    if ('' === $default_language || 1 !== preg_match('/^[a-z0-9_-]+$/', $default_language) || !in_array($default_language, $languages, true)) {
        $default_language = '';
    }

    $query_key = strtolower(trim((string) ($contract['query_key'] ?? 'lang')));
    if ('lang' !== $query_key) {
        $query_key = 'lang';
    }

    $enabled = !empty($contract['enabled']) && !empty($languages);
    $fingerprint_material = implode("\n", array(
        'ultracache-wpml-parameter-cache-v1',
        $enabled ? '1' : '0',
        $query_key,
        implode(',', $languages),
        $default_language,
    ));

    return array(
        'version' => 1,
        'enabled' => $enabled,
        'query_key' => $query_key,
        'languages' => $languages,
        'default_language' => $default_language,
        'fingerprint' => hash('sha256', $fingerprint_material),
    );
}

function ultracache_advanced_cache_build_litespeed_query_cache_key_proof($policy = array()) {
    $policy = ultracache_advanced_cache_normalize_query_cache_policy($policy);
    $enabled = !empty($policy['enabled']);
    $allowlist = (array) ($policy['allowlist'] ?? array());
    $applicable = $enabled && !empty($allowlist);
    $requirements = array(
        'preserve_allowed_keys' => true,
        'reject_extra_keys' => true,
        'canonical_key_order' => true,
        'canonical_structured_value_order' => true,
        'rfc3986_encoding' => true,
        'distinct_allowed_values' => true,
    );
    $native_capabilities = array(
        'drop_exact_query_key' => true,
        'drop_prefix_query_key' => true,
        'canonical_key_order' => false,
        'canonical_structured_value_order' => false,
        'rfc3986_encoding' => false,
    );
    $missing_capabilities = array(
        'canonical_key_order',
        'canonical_structured_value_order',
        'rfc3986_encoding',
    );
    $operation_contract = array(
        'retrieval' => 'bypass',
        'storage' => 'no-cache',
        'exact_purge' => 'skip',
        'public_refill' => 'skip',
        'base_url_aliasing' => false,
    );
    $status = $applicable ? 'blocked' : 'not-applicable';
    $reason = $applicable
        ? 'native-query-key-modifiers-cannot-represent-ultracache-canonical-query'
        : 'query-cache-policy-disabled-or-empty';
    $fingerprint_material = implode("\n", array(
        'ultracache-litespeed-query-key-proof-v2',
        (string) ($policy['fingerprint'] ?? ''),
        $status,
        $reason,
        implode(',', array_keys(array_filter($requirements))),
        implode(',', array_keys(array_filter($native_capabilities))),
        implode(',', $missing_capabilities),
        implode(',', array(
            $operation_contract['retrieval'],
            $operation_contract['storage'],
            $operation_contract['exact_purge'],
            $operation_contract['public_refill'],
            $operation_contract['base_url_aliasing'] ? '1' : '0',
        )),
    ));
    return array(
        'version' => 2,
        'status' => $status,
        'verified' => false,
        'strategy' => 'native-query-key-modifiers',
        'safe_query_retrieval_enabled' => false,
        'policy_fingerprint' => (string) ($policy['fingerprint'] ?? ''),
        'fingerprint' => hash('sha256', $fingerprint_material),
        'reason' => $reason,
        'requirements' => $requirements,
        'native_capabilities' => $native_capabilities,
        'missing_capabilities' => $missing_capabilities,
        'operation_contract' => $operation_contract,
    );
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
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- The path is restricted by ultracache_advanced_cache_is_allowed_file_path(); early drop-in writeability must be checked before WP_Filesystem is initialized.
    if ('' !== $dir && '.' !== $dir && (!is_dir($dir) || !is_writable($dir))) {
        if (is_dir($dir)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Repairs permissions only on an UltraCache-allowed cache directory before WP_Filesystem is initialized.
            @chmod($dir, 0755);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Rechecks the same UltraCache-allowed cache directory after the bounded permission repair.
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
    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Deletes only a path accepted by ultracache_advanced_cache_is_allowed_file_path() before WP_Filesystem is initialized.
    return !file_exists($file) ? true : @unlink($file);
}

function ultracache_advanced_cache_safe_rename($from, $to) {
    if (!ultracache_advanced_cache_is_allowed_file_path($from, true) || !ultracache_advanced_cache_is_allowed_file_path($to, false)) {
        return false;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic rename is restricted to two UltraCache-allowed cache paths before WP_Filesystem is initialized.
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
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates only an UltraCache-allowed cache directory before WP_Filesystem is initialized.
    return @mkdir($dir, $mode, $recursive) || is_dir($dir);
}


function ultracache_advanced_cache_safe_filemtime($file) {
    return ultracache_advanced_cache_is_allowed_file_path($file, true) && is_file($file) ? filemtime($file) : false;
}

function ultracache_advanced_cache_get_validator_metadata($file, $encoding_bucket = 'identity') {
    if (!ultracache_advanced_cache_is_allowed_file_path($file, true) || !is_readable($file)) {
        return array();
    }

    clearstatcache(true, $file);
    $stat = stat($file);
    if (!is_array($stat)) {
        return array();
    }

    $mtime = max(0, (int) ($stat['mtime'] ?? 0));
    $size = max(0, (int) ($stat['size'] ?? 0));
    $inode = max(0, (int) ($stat['ino'] ?? 0));
    if ($mtime <= 0 || $size <= 0) {
        return array();
    }

    $encoding_bucket = strtolower(trim((string) $encoding_bucket));
    if (!in_array($encoding_bucket, array('identity', 'gzip', 'brotli'), true)) {
        $encoding_bucket = 'identity';
    }

    $signature = hash('sha256', implode('|', array(
        basename($file),
        (string) $mtime,
        (string) $size,
        (string) $inode,
        $encoding_bucket,
    )));

    return array(
        'etag' => 'W/"uc-' . substr($signature, 0, 32) . '"',
        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- HTTP Last-Modified requires a non-localized IMF-fixdate in GMT.
        'last_modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
        'mtime' => $mtime,
    );
}

function ultracache_advanced_cache_send_validator_headers(array $metadata) {
    if (headers_sent()) {
        return;
    }
    if (!empty($metadata['etag'])) {
        header('ETag: ' . (string) $metadata['etag'], true);
    }
    if (!empty($metadata['last_modified'])) {
        header('Last-Modified: ' . (string) $metadata['last_modified'], true);
    }
}

function ultracache_advanced_cache_normalize_etag_for_comparison($etag) {
    $etag = trim((string) $etag);
    if (0 === stripos($etag, 'W/')) {
        $etag = trim(substr($etag, 2));
    }
    return $etag;
}

function ultracache_advanced_cache_if_none_match_matches($request_value, $current_etag) {
    $request_value = trim(substr((string) $request_value, 0, 4096));
    $current_etag = ultracache_advanced_cache_normalize_etag_for_comparison($current_etag);
    if ('' === $request_value || '' === $current_etag) {
        return false;
    }
    if ('*' === $request_value) {
        return true;
    }
    foreach (explode(',', $request_value) as $candidate) {
        $candidate = ultracache_advanced_cache_normalize_etag_for_comparison($candidate);
        if ('' !== $candidate && hash_equals($current_etag, $candidate)) {
            return true;
        }
    }
    return false;
}

function ultracache_advanced_cache_request_is_not_modified(array $metadata, $method) {
    $method = strtoupper((string) $method);
    if (!in_array($method, array('GET', 'HEAD'), true)) {
        return false;
    }

    $if_none_match = ultracache_advanced_cache_server_var('HTTP_IF_NONE_MATCH');
    if ('' !== trim($if_none_match)) {
        return ultracache_advanced_cache_if_none_match_matches($if_none_match, (string) ($metadata['etag'] ?? ''));
    }

    $if_modified_since = trim(substr(ultracache_advanced_cache_server_var('HTTP_IF_MODIFIED_SINCE'), 0, 255));
    $mtime = max(0, (int) ($metadata['mtime'] ?? 0));
    if ('' === $if_modified_since || $mtime <= 0) {
        return false;
    }

    $request_time = strtotime($if_modified_since);
    return false !== $request_time && $mtime <= (int) $request_time;
}

function ultracache_advanced_cache_send_not_modified_status() {
    if (headers_sent()) {
        return;
    }
    if (function_exists('header_remove')) {
        header_remove('Content-Type');
        header_remove('Content-Encoding');
        header_remove('Content-Length');
    }
    $protocol = ultracache_advanced_cache_server_var('SERVER_PROTOCOL', 'HTTP/1.1');
    header($protocol . ' 304 Not Modified', true, 304);
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

function ultracache_advanced_cache_write_all($stream, $payload) {
    if (!is_resource($stream) || !is_string($payload) || '' === $payload || strlen($payload) > 16384) {
        return false;
    }

    $length = strlen($payload);
    $written = 0;
    while ($written < $length) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The early drop-in sends one bounded authenticated loopback request before WordPress APIs are available.
        $chunk = @fwrite($stream, substr($payload, $written));
        if (!is_int($chunk) || $chunk <= 0) {
            return false;
        }
        $written += $chunk;
    }

    return true;
}


function ultracache_advanced_cache_debug_headers_enabled() {
    global $runtime_config;
    if (empty($runtime_config['debug_headers_enabled'])) {
        return false;
    }
    $flag = strtolower(ultracache_advanced_cache_server_var('HTTP_X_ULTRACACHE_DEBUG', ''));
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

function ultracache_advanced_cache_get_esi_metadata($cache_file, $base_dir) {
    $cache_file = is_string($cache_file) ? trim($cache_file) : '';
    $base_dir = is_string($base_dir) ? trim($base_dir) : '';
    if (
        '' === $cache_file ||
        '' === $base_dir ||
        !ultracache_advanced_cache_is_valid_cache_payload_file($cache_file, $base_dir)
    ) {
        return array();
    }

    $marker = $cache_file . '.esi';
    if (
        !ultracache_advanced_cache_is_cache_path($marker, $base_dir, true) ||
        1 !== preg_match('/\Aindex-(?:orig|avif|webp)-[a-f0-9]{32}\.html\.esi\z/', basename($marker)) ||
        !is_readable($marker)
    ) {
        return array();
    }

    $size = filesize($marker);
    if (!is_int($size) || $size <= 0 || $size > 2048) {
        return array();
    }

    $raw = ultracache_advanced_cache_safe_file_get_contents($marker);
    $metadata = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($metadata)) {
        return array();
    }

    $version = (int) ($metadata['version'] ?? 0);
    if (!in_array($version, array(1, 2, 3, 4), true)) {
        return array();
    }

    $fragment_count = max(0, min(64, (int) ($metadata['fragmentCount'] ?? 0)));
    $public_count = max(0, min($fragment_count, (int) ($metadata['publicCount'] ?? $fragment_count)));
    $private_count = max(0, min($fragment_count - $public_count, (int) ($metadata['privateCount'] ?? 0)));
    if ($fragment_count <= 0 || ($public_count + $private_count) <= 0) {
        return array();
    }

    $unique_fragment_count = max(0, min($fragment_count, (int) ($metadata['uniqueFragmentCount'] ?? $fragment_count)));
    $min_ttl = max(0, min(604800, (int) ($metadata['minTtl'] ?? 0)));
    $max_ttl = max($min_ttl, min(604800, (int) ($metadata['maxTtl'] ?? $min_ttl)));

    return array(
        'version' => 4,
        'fragmentCount' => $fragment_count,
        'publicCount' => $public_count,
        'privateCount' => $private_count,
        'uniqueFragmentCount' => $unique_fragment_count,
        'woocommerceMiniCart' => 4 === $version && !empty($metadata['woocommerceMiniCart']) && $private_count > 0,
        'minTtl' => $min_ttl,
        'maxTtl' => $max_ttl,
    );
}

function ultracache_advanced_cache_get_litespeed_esi_metadata($cache_file, $base_dir) {
    $cache_file = is_string($cache_file) ? trim($cache_file) : '';
    $base_dir = is_string($base_dir) ? trim($base_dir) : '';
    if (
        '' === $cache_file ||
        '' === $base_dir ||
        !ultracache_advanced_cache_is_valid_cache_payload_file($cache_file, $base_dir)
    ) {
        return array();
    }

    $marker = $cache_file . '.lsesi';
    if (
        !ultracache_advanced_cache_is_cache_path($marker, $base_dir, true) ||
        1 !== preg_match('/\Aindex-(?:orig|avif|webp)-[a-f0-9]{32}\.html\.lsesi\z/', basename($marker)) ||
        !is_readable($marker)
    ) {
        return array();
    }

    $size = filesize($marker);
    if (!is_int($size) || $size <= 0 || $size > 1024) {
        return array();
    }

    $raw = ultracache_advanced_cache_safe_file_get_contents($marker);
    $metadata = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($metadata) || 1 !== (int) ($metadata['version'] ?? 0)) {
        return array();
    }

    $fragment_count = max(0, min(64, (int) ($metadata['fragmentCount'] ?? 0)));
    if ($fragment_count <= 0) {
        return array();
    }

    return array(
        'version' => 1,
        'fragmentCount' => $fragment_count,
        'woocommerceMiniCart' => !empty($metadata['woocommerceMiniCart']),
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
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Early drop-in fallback is normalized immediately by ultracache_advanced_cache_clean_server_text(); the server key is internal and fixed by callers.
    $value = ultracache_advanced_cache_clean_server_text(function_exists('wp_unslash') ? wp_unslash($_SERVER[$key]) : $_SERVER[$key]);
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
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The advanced-cache drop-in loads before wp_parse_url() is available.
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

$authorization = trim((string) ultracache_advanced_cache_server_var('HTTP_AUTHORIZATION', ''));
$redirect_authorization = trim((string) ultracache_advanced_cache_server_var('REDIRECT_HTTP_AUTHORIZATION', ''));
$php_auth_user = trim((string) ultracache_advanced_cache_server_var('PHP_AUTH_USER', ''));
if ('' !== $authorization || '' !== $redirect_authorization || '' !== $php_auth_user) {
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
    ) as $needle) {
        if (false !== strpos((string) $cookie_name, $needle)) {
            return;
        }
    }
}

$runtime_config = array(
    'excluded_paths' => array(
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
        'ultracache_rv',
        'ultracache_bucket',
        'ultracache_store_profile',
        'ultracache_callback_profile',
        'ultracache_store_profile_verbose',
        'ultracache_store_profile_verbose_settings',
        'ultracache_profile_bypass',
        'ultracache_profile_run',
        'ultracache_runtime_js_scan',
        'ultracache_runtime_js_scan_id',
        'ultracache_runtime_js_scan_token',
        'ultracache_runtime_js_scan_nonce',
        'ultracache_runtime_js_scan_mode',
        'ultracache_runtime_js_scan_context',
        'ultracache_probe_compression',
        'ultracache_sqlite_exposure_probe',
    ),
    'cache_query_strings'            => false,
    'cache_query_allowlist'          => array(),
    'query_cache_policy'             => ultracache_advanced_cache_build_query_cache_policy(false, array(), array()),
    'wpml_parameter_cache'            => ultracache_advanced_cache_normalize_wpml_parameter_cache_contract(array()),
    'litespeed_query_cache_key_proof' => ultracache_advanced_cache_build_litespeed_query_cache_key_proof(ultracache_advanced_cache_build_query_cache_policy(false, array(), array())),
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
    'woocommerce_contract_active'    => false,
    'woocommerce_contract_available' => false,
    'woocommerce_contract_fingerprint' => '',
    'woocommerce_dynamic_paths'      => array(),
    'woocommerce_dynamic_query_rules' => array(),
    'woocommerce_dynamic_query_keys' => array(),
    'cache_stats_enabled'            => false,
    'debug_headers_enabled'          => false,
    'shared_cache_delivery_enabled'  => false,
    'shared_cache_control_verified'  => false,
    'shared_cache_control_proof_expires_at' => 0,
    'shared_cache_delivery_mode'     => 'disabled',
    'shared_cache_ttl_minutes'       => 0,
    'shared_cache_managed_ttl_minutes' => 1440,
    'shared_cache_ttl_only_minutes'  => 10,
    'varnish_enabled'                => false,
    'varnish_stale_while_revalidate_seconds' => 0,
    'litespeed_enabled'               => false,
    'litespeed_site_tag'              => '',
    'configured_site_base'           => '',
    'home_url'                       => '',
    'html_vary_accept'               => false,
    'html_variant_buckets'           => array('orig'),
    'stale_while_revalidate_enabled' => false,
    'cache_fresh_ttl_minutes'        => 1440,
    'cache_max_stale_minutes'        => 2880,
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
        $paths[$path_rule . ($wildcard && '/' !== $path_rule ? '*' : '')] = true;
    }

    $paths = array_keys($paths);
    sort($paths);
    return $paths;
};
$ultracache_normalize_woocommerce_query_rules = static function ($rules) use ($ultracache_normalize_runtime_path) {
    $normalized = array();
    foreach ((array) $rules as $rule) {
        if (!is_array($rule) || empty($rule['query']) || !is_array($rule['query'])) {
            continue;
        }

        $path = $ultracache_normalize_runtime_path($rule['path'] ?? '/');
        $query = array();
        $valid = true;
        foreach ($rule['query'] as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $valid = false;
                break;
            }
            $key = strtolower((string) $key);
            $key = preg_replace('/[^a-z0-9_-]/', '', $key);
            if (!is_string($key) || '' === $key) {
                $valid = false;
                break;
            }
            $query[$key] = (string) $value;
        }
        if (!$valid || empty($query)) {
            continue;
        }
        ksort($query);
        $encoded = json_encode(array('path' => $path, 'query' => $query));
        $fingerprint = hash('sha256', is_string($encoded) ? $encoded : serialize($query));
        $normalized[$fingerprint] = array('path' => $path, 'query' => $query);
    }

    $normalized = array_values($normalized);
    usort($normalized, static function ($a, $b) {
        return strcmp((string) json_encode($a), (string) json_encode($b));
    });
    return $normalized;
};
$ultracache_normalize_runtime_config = static function ($config) use ($runtime_config, $ultracache_normalize_runtime_string_list, $ultracache_normalize_runtime_path_list, $ultracache_normalize_woocommerce_query_rules) {
    $config = is_array($config) ? $config : array();

    $excluded_query_args = $ultracache_normalize_runtime_string_list($config['excluded_query_args'] ?? $runtime_config['excluded_query_args'], '/[^a-z0-9_-]/');
    $query_cache_policy = ultracache_advanced_cache_normalize_query_cache_policy(
        $config['query_cache_policy'] ?? ($runtime_config['query_cache_policy'] ?? array()),
        array(
            'cache_query_strings' => !empty($config['cache_query_strings']),
            'cache_query_allowlist' => $config['cache_query_allowlist'] ?? $runtime_config['cache_query_allowlist'],
            'excluded_query_args' => $excluded_query_args,
        )
    );
    $cache_query_allowlist = (array) ($query_cache_policy['allowlist'] ?? array());
    $wpml_parameter_cache = ultracache_advanced_cache_normalize_wpml_parameter_cache_contract(
        $config['wpml_parameter_cache'] ?? ($runtime_config['wpml_parameter_cache'] ?? array())
    );
    $litespeed_query_cache_key_proof = ultracache_advanced_cache_build_litespeed_query_cache_key_proof($query_cache_policy);
    $fresh_ttl_minutes = isset($config['cache_fresh_ttl_minutes']) ? (int) $config['cache_fresh_ttl_minutes'] : (int) $runtime_config['cache_fresh_ttl_minutes'];
    $fresh_ttl_minutes = max(1, min(525600, $fresh_ttl_minutes));
    $max_stale_minutes = isset($config['cache_max_stale_minutes']) ? (int) $config['cache_max_stale_minutes'] : (int) $runtime_config['cache_max_stale_minutes'];
    $max_stale_minutes = max($fresh_ttl_minutes, min(525600, $max_stale_minutes));
    $html_variant_buckets = array('orig');
    foreach ((array) ($config['html_variant_buckets'] ?? $runtime_config['html_variant_buckets']) as $html_variant_bucket) {
        $html_variant_bucket = strtolower(preg_replace('/[^a-z0-9_-]/', '', (string) $html_variant_bucket));
        if (in_array($html_variant_bucket, array('webp', 'avif'), true)) {
            $html_variant_buckets[] = $html_variant_bucket;
        }
    }
    $html_variant_buckets = array_values(array_unique($html_variant_buckets));

    return array(
        'excluded_paths'                 => $ultracache_normalize_runtime_path_list($config['excluded_paths'] ?? $runtime_config['excluded_paths']),
        'excluded_query_args'            => $excluded_query_args,
        'cache_query_strings'            => !empty($query_cache_policy['enabled']),
        'cache_query_allowlist'          => $cache_query_allowlist,
        'query_cache_policy'             => $query_cache_policy,
        'wpml_parameter_cache'            => $wpml_parameter_cache,
        'litespeed_query_cache_key_proof' => $litespeed_query_cache_key_proof,
        'cache_safe_tracking_cookies'    => array_key_exists('cache_safe_tracking_cookies', (array) $config) ? !empty($config['cache_safe_tracking_cookies']) : !empty($runtime_config['cache_safe_tracking_cookies']),
        'safe_tracking_cookie_patterns'  => $ultracache_normalize_runtime_string_list($config['safe_tracking_cookie_patterns'] ?? $runtime_config['safe_tracking_cookie_patterns'], '/[^a-z0-9_\-.\*]/'),
        'unsafe_cache_cookie_patterns'   => $ultracache_normalize_runtime_string_list($config['unsafe_cache_cookie_patterns'] ?? $runtime_config['unsafe_cache_cookie_patterns'], '/[^a-z0-9_\-.\*]/'),
        'woo_safe_mode'                  => !empty($config['woo_safe_mode']),
        'woocommerce_contract_active'    => !empty($config['woocommerce_contract_active']),
        'woocommerce_contract_available' => !empty($config['woocommerce_contract_available']),
        'woocommerce_contract_fingerprint' => isset($config['woocommerce_contract_fingerprint']) && 1 === preg_match('/\A[a-f0-9]{64}\z/', (string) $config['woocommerce_contract_fingerprint']) ? (string) $config['woocommerce_contract_fingerprint'] : '',
        'woocommerce_dynamic_paths'      => $ultracache_normalize_runtime_path_list($config['woocommerce_dynamic_paths'] ?? $runtime_config['woocommerce_dynamic_paths']),
        'woocommerce_dynamic_query_rules' => $ultracache_normalize_woocommerce_query_rules($config['woocommerce_dynamic_query_rules'] ?? $runtime_config['woocommerce_dynamic_query_rules']),
        'woocommerce_dynamic_query_keys' => $ultracache_normalize_runtime_string_list($config['woocommerce_dynamic_query_keys'] ?? $runtime_config['woocommerce_dynamic_query_keys'], '/[^a-z0-9_-]/'),
        'cache_stats_enabled'            => !empty($config['cache_stats_enabled']),
        'debug_headers_enabled'          => !empty($config['debug_headers_enabled']),
        'shared_cache_delivery_enabled'  => !empty($config['shared_cache_delivery_enabled']),
        'shared_cache_control_verified'  => !empty($config['shared_cache_control_verified']),
        'shared_cache_control_proof_expires_at' => max(0, isset($config['shared_cache_control_proof_expires_at']) ? (int) $config['shared_cache_control_proof_expires_at'] : 0),
        'shared_cache_delivery_mode'     => in_array(strtolower(trim((string) ($config['shared_cache_delivery_mode'] ?? 'disabled'))), array('managed', 'ttl-only', 'disabled'), true) ? strtolower(trim((string) $config['shared_cache_delivery_mode'])) : 'disabled',
        'shared_cache_ttl_minutes'       => max(0, min(525600, isset($config['shared_cache_ttl_minutes']) ? (int) $config['shared_cache_ttl_minutes'] : 0)),
        'shared_cache_managed_ttl_minutes' => max(1, min(525600, isset($config['shared_cache_managed_ttl_minutes']) ? (int) $config['shared_cache_managed_ttl_minutes'] : 1440)),
        'shared_cache_ttl_only_minutes'  => max(1, min(1440, isset($config['shared_cache_ttl_only_minutes']) ? (int) $config['shared_cache_ttl_only_minutes'] : 10)),
        'varnish_enabled'                => !empty($config['varnish_enabled']),
        'varnish_stale_while_revalidate_seconds' => max(0, min(86400, isset($config['varnish_stale_while_revalidate_seconds']) ? (int) $config['varnish_stale_while_revalidate_seconds'] : 0)),
        'litespeed_enabled'               => !empty($config['litespeed_enabled']),
        'litespeed_site_tag'              => isset($config['litespeed_site_tag']) && 1 === preg_match('/\Auc_s_[a-f0-9]{20}\z/', (string) $config['litespeed_site_tag']) ? (string) $config['litespeed_site_tag'] : '',
        'configured_site_base'           => isset($config['configured_site_base']) && is_scalar($config['configured_site_base']) ? trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $config['configured_site_base'])) : '',
        'home_url'                       => isset($config['home_url']) && is_scalar($config['home_url']) ? trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $config['home_url'])) : '',
        'html_vary_accept'               => !empty($config['html_vary_accept']) && count($html_variant_buckets) > 1,
        'html_variant_buckets'           => $html_variant_buckets,
        'stale_while_revalidate_enabled' => !empty($config['stale_while_revalidate_enabled']),
        'cache_fresh_ttl_minutes'        => $fresh_ttl_minutes,
        'cache_max_stale_minutes'        => $max_stale_minutes,
        'trusted_hosts'                  => $ultracache_normalize_runtime_string_list($config['trusted_hosts'] ?? $runtime_config['trusted_hosts']),
        'object_cache_enabled'           => !empty($config['object_cache_enabled']),
        'object_cache_backend'           => in_array(strtolower(trim((string) ($config['object_cache_backend'] ?? 'redis'))), array('redis', 'apcu', 'sqlite', 'disk'), true) ? strtolower(trim((string) ($config['object_cache_backend'] ?? 'redis'))) : 'redis',
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
if (!empty($runtime_config['woocommerce_contract_active']) && empty($runtime_config['woocommerce_contract_available'])) {
    return;
}

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

$ultracache_is_woocommerce_session_cookie_name = static function ($cookie_name) {
    $cookie_name = strtolower(trim((string) $cookie_name));
    if ('' === $cookie_name) {
        return false;
    }

    return in_array($cookie_name, array('woocommerce_items_in_cart', 'woocommerce_cart_hash'), true)
        || 0 === strpos($cookie_name, 'wp_woocommerce_session_');
};

$ultracache_has_woocommerce_session_cookie = false;
foreach (array_keys((array) ($_COOKIE ?? array())) as $cookie_name) {
    $cookie_name = ultracache_advanced_cache_clean_server_text($cookie_name);
    if ($ultracache_is_woocommerce_session_cookie_name($cookie_name)) {
        $ultracache_has_woocommerce_session_cookie = true;
        continue;
    }

    if ($ultracache_cookie_name_matches_any_pattern($cookie_name, $runtime_config['unsafe_cache_cookie_patterns'] ?? array())) {
        return;
    }
}

$ultracache_cache_stats_enabled = !empty($runtime_config['cache_stats_enabled']);

$ultracache_initial_query_vars = array();
$ultracache_initial_query_string = ultracache_advanced_cache_server_var('QUERY_STRING', '');
if ('' !== $ultracache_initial_query_string) {
    parse_str($ultracache_initial_query_string, $ultracache_initial_query_vars);
}
$revalidate_flag = isset($ultracache_initial_query_vars['ultracache_revalidate']) && is_scalar($ultracache_initial_query_vars['ultracache_revalidate'])
    ? ultracache_advanced_cache_clean_server_text($ultracache_initial_query_vars['ultracache_revalidate'])
    : '';
$revalidate_header = ultracache_advanced_cache_server_var('HTTP_X_ULTRACACHE_REVALIDATE', '');
$revalidate_token = isset($ultracache_initial_query_vars['ultracache_rt']) && is_scalar($ultracache_initial_query_vars['ultracache_rt'])
    ? ultracache_advanced_cache_clean_server_text($ultracache_initial_query_vars['ultracache_rt'])
    : ultracache_advanced_cache_server_var('HTTP_X_ULTRACACHE_TOKEN', '');
$is_revalidate_request = (
    ('1' === $revalidate_flag || '1' === $revalidate_header)
    && '1' === ultracache_advanced_cache_server_var('HTTP_X_ULTRACACHE_INTERNAL_REQUEST', '')
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

$ultracache_initial_internal_control = false;
if (!empty($ultracache_initial_query_vars) && is_array($ultracache_initial_query_vars)) {
    $ultracache_initial_internal_keys = array(
        'ultracache_revalidate' => true,
        'ultracache_rt' => true,
        'ultracache_rv' => true,
        'ultracache_bucket' => true,
        'ultracache_store_profile' => true,
        'ultracache_callback_profile' => true,
        'ultracache_store_profile_verbose' => true,
        'ultracache_store_profile_verbose_settings' => true,
        'ultracache_profile_bypass' => true,
        'ultracache_profile_run' => true,
        'ultracache_runtime_js_scan' => true,
        'ultracache_runtime_js_scan_id' => true,
        'ultracache_runtime_js_scan_token' => true,
        'ultracache_runtime_js_scan_nonce' => true,
        'ultracache_runtime_js_scan_mode' => true,
        'ultracache_runtime_js_scan_context' => true,
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
$force_refresh_token = (string) ultracache_advanced_cache_server_var('HTTP_X_ULTRACACHE_TOKEN', '');
$force_refresh_valid = ('1' === $force_refresh_header || 'true' === $force_refresh_header)
    && ('1' === $internal_header || '1' === $warm_header)
    && '' !== $ultracache_runtime_control_secret
    && ultracache_advanced_cache_validate_runtime_control_token($force_refresh_token, $ultracache_runtime_control_secret);
if ($force_refresh_valid) {
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
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A native handle is required for flock()/truncate; the analytics buffer path is validated as an UltraCache cache path immediately above.
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
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Releases the validated analytics buffer lock handle.
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
$ultracache_try_acquire_revalidate_lock = static function ($lock_file, $max_stale_seconds) use ($ultracache_get_filemtime, $ultracache_make_dir, $ultracache_delete_file, $ultracache_advanced_cache_is_cache_path) {
    if (!$ultracache_advanced_cache_is_cache_path($lock_file)) {
        return false;
    }

    $dir = dirname($lock_file);
    if (!file_exists($dir) && !$ultracache_make_dir($dir, 0755, true)) {
        return false;
    }

    $lock_ttl = max(30, min(300, (int) $max_stale_seconds));
    for ($attempt = 0; $attempt < 2; $attempt++) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive create is required for an atomic pre-WordPress stale-revalidation lease.
        $handle = @fopen($lock_file, 'x');
        if (is_resource($handle)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bounded timestamp written to an exclusively created cache-root lock.
            $written = @fwrite($handle, (string) time());
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Releases the validated stale-revalidation lease handle.
            fclose($handle);
            if (false !== $written) {
                return true;
            }
            $ultracache_delete_file($lock_file);
            return false;
        }

        $mtime = $ultracache_get_filemtime($lock_file);
        if (!$mtime || (time() - (int) $mtime) <= $lock_ttl) {
            return false;
        }
        $ultracache_delete_file($lock_file);
    }

    return false;
};
$ultracache_queue_revalidate = static function ($target_url, $secret, $configured_site_base) {
    $secret = (string) $secret;
    if ('' === $target_url || '' === $secret) {
        return false;
    }
    $token = ultracache_advanced_cache_create_runtime_control_token($secret);
    if ('' === $token) {
        return false;
    }
    $separator = false !== strpos($target_url, '?') ? '&' : '?';
    try {
        $request_nonce = bin2hex(random_bytes(12));
    } catch (Throwable) {
        $request_nonce = substr(hash_hmac('sha256', $target_url . '|' . sprintf('%.6F', microtime(true)) . '|' . getmypid() . '|' . memory_get_usage(), $secret), 0, 24);
    }
    $request_url = $target_url . $separator . 'ultracache_revalidate=1&ultracache_rt=' . rawurlencode($token) . '&ultracache_rv=' . rawurlencode($request_nonce);

    // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The advanced-cache drop-in loads before wp_parse_url() is available.
    $parts = parse_url($request_url);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The advanced-cache drop-in loads before wp_parse_url() is available.
    $home_parts = parse_url((string) $configured_site_base);
    if (empty($parts['host']) || empty($home_parts['host'])) {
        return false;
    }

    $scheme = !empty($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'http';
    $host = strtolower((string) $parts['host']);
    $home_host = strtolower((string) $home_parts['host']);
    if (!in_array($scheme, array('http', 'https'), true) || $host !== $home_host) {
        return false;
    }

    $signature = hash_hmac('sha256', 'ultracache-varnish-origin-revalidation|' . (string) $configured_site_base, $secret);
    if ('' === $signature) {
        return false;
    }

    $port = isset($parts['port']) ? (int) $parts['port'] : ('https' === $scheme ? 443 : 80);
    $path = (!empty($parts['path']) ? (string) $parts['path'] : '/') . (!empty($parts['query']) ? '?' . (string) $parts['query'] : '');
    $remote = ('https' === $scheme ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $host_header = $host;
    if (!(('http' === $scheme && 80 === $port) || ('https' === $scheme && 443 === $port))) {
        $host_header .= ':' . (string) $port;
    }

    $errno = 0;
    $errstr = '';
    $fp = ultracache_advanced_cache_safe_stream_socket_client($remote, 0.5, $errno, $errstr, STREAM_CLIENT_CONNECT);
    if (!is_resource($fp)) {
        return false;
    }

    stream_set_blocking($fp, true);
    stream_set_timeout($fp, 0, 500000);
    $out = "GET {$path} HTTP/1.1\r\n";
    $out .= "Host: {$host_header}\r\n";
    $out .= "Connection: Close\r\n";
    $out .= "X-UltraCache-Warm: 1\r\n";
    $out .= "X-UltraCache-Internal-Request: 1\r\n";
    $out .= "X-UltraCache-Force-Refresh: 1\r\n";
    $out .= "X-UltraCache-Revalidate: 1\r\n";
    $out .= "X-UltraCache-Token: {$token}\r\n";
    $out .= "X-UltraCache-VCL-Signature: {$signature}\r\n";
    $out .= "Cache-Control: no-cache, no-store, must-revalidate, max-age=0\r\n";
    $out .= "Pragma: no-cache\r\n\r\n";
    $written = ultracache_advanced_cache_write_all($fp, $out);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Releases the bounded authenticated early-bootstrap loopback stream.
    fclose($fp);

    return $written;
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

$ultracache_evaluate_query_for_cache = static function ($query_vars, $runtime_config) use ($ultracache_sort_query_value, $ultracache_normalize_query_key) {
    if (!is_array($query_vars) || empty($query_vars)) {
        return array(
            'cacheable' => true,
            'reason' => '',
            'rejected_key' => '',
            'normalized_vars' => array(),
            'normalized_query' => '',
            'wpml_parameter_candidate' => false,
            'wpml_parameter_language' => '',
        );
    }

    $query_policy = (array) ($runtime_config['query_cache_policy'] ?? array());
    $generic_enabled = !empty($query_policy['enabled']);
    $generic_allowlist = (array) ($query_policy['allowlist'] ?? ($runtime_config['cache_query_allowlist'] ?? array()));
    $generic_lookup = array();
    foreach ($generic_allowlist as $allowed_key) {
        $allowed_key = $ultracache_normalize_query_key($allowed_key);
        if ('' !== $allowed_key) {
            $generic_lookup[$allowed_key] = true;
        }
    }

    $wpml_contract = ultracache_advanced_cache_normalize_wpml_parameter_cache_contract(
        $runtime_config['wpml_parameter_cache'] ?? array()
    );
    $wpml_enabled = !empty($wpml_contract['enabled']);
    $wpml_key = $wpml_enabled ? (string) ($wpml_contract['query_key'] ?? 'lang') : '';
    $wpml_languages = $wpml_enabled ? (array) ($wpml_contract['languages'] ?? array()) : array();

    $normalized = array();
    $wpml_candidate = false;
    $wpml_language = '';

    foreach ($query_vars as $query_key => $query_value) {
        $raw_key = (string) $query_key;
        $normalized_key = $ultracache_normalize_query_key($raw_key);
        if ('' === $normalized_key) {
            return array(
                'cacheable' => false,
                'reason' => 'query-arg-not-allowlisted',
                'rejected_key' => $raw_key,
                'normalized_vars' => array(),
                'normalized_query' => '',
                'wpml_parameter_candidate' => $wpml_candidate,
                'wpml_parameter_language' => $wpml_language,
            );
        }

        if ($wpml_enabled && $raw_key === $wpml_key) {
            $wpml_candidate = true;
            if (!is_string($query_value) || !in_array($query_value, $wpml_languages, true)) {
                return array(
                    'cacheable' => false,
                    'reason' => 'wpml-language-invalid',
                    'rejected_key' => $raw_key,
                    'normalized_vars' => array(),
                    'normalized_query' => '',
                    'wpml_parameter_candidate' => true,
                    'wpml_parameter_language' => '',
                );
            }

            $wpml_language = $query_value;
            $normalized[$wpml_key] = $query_value;
            continue;
        }

        if (!$generic_enabled) {
            return array(
                'cacheable' => false,
                'reason' => 'query-strings-disabled',
                'rejected_key' => $raw_key,
                'normalized_vars' => array(),
                'normalized_query' => '',
                'wpml_parameter_candidate' => $wpml_candidate,
                'wpml_parameter_language' => $wpml_language,
            );
        }

        if (empty($generic_lookup)) {
            return array(
                'cacheable' => false,
                'reason' => 'query-allowlist-empty',
                'rejected_key' => $raw_key,
                'normalized_vars' => array(),
                'normalized_query' => '',
                'wpml_parameter_candidate' => $wpml_candidate,
                'wpml_parameter_language' => $wpml_language,
            );
        }

        if (!isset($generic_lookup[$normalized_key])) {
            return array(
                'cacheable' => false,
                'reason' => 'query-arg-not-allowlisted',
                'rejected_key' => $raw_key,
                'normalized_vars' => array(),
                'normalized_query' => '',
                'wpml_parameter_candidate' => $wpml_candidate,
                'wpml_parameter_language' => $wpml_language,
            );
        }

        $normalized[$normalized_key] = $ultracache_sort_query_value($query_value);
    }

    if (empty($normalized)) {
        return array(
            'cacheable' => false,
            'reason' => 'invalid-query',
            'rejected_key' => '',
            'normalized_vars' => array(),
            'normalized_query' => '',
            'wpml_parameter_candidate' => $wpml_candidate,
            'wpml_parameter_language' => $wpml_language,
        );
    }

    ksort($normalized);
    return array(
        'cacheable' => true,
        'reason' => '',
        'rejected_key' => '',
        'normalized_vars' => $normalized,
        'normalized_query' => http_build_query($normalized, '', '&', PHP_QUERY_RFC3986),
        'wpml_parameter_candidate' => $wpml_candidate,
        'wpml_parameter_language' => $wpml_language,
    );
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

$ultracache_hard_security_paths = array_merge(
    array(
        '/wp-admin/',
        '/wp-login.php',
        '/wp-json/',
        '/xmlrpc.php',
        '/wp-cron.php',
        '/wp-comments-post.php',
        '/wc-api/',
        '/ultracache-varnishtest/',
    ),
    (array) ($runtime_config['woocommerce_dynamic_paths'] ?? array())
);
$ultracache_hard_security_paths = array_values(array_unique($ultracache_hard_security_paths));

$ultracache_internal_control_query_args = array(
    'ultracache_revalidate',
    'ultracache_rt',
    'ultracache_rv',
    'ultracache_bucket',
    'ultracache_store_profile',
    'ultracache_callback_profile',
    'ultracache_store_profile_verbose',
    'ultracache_store_profile_verbose_settings',
    'ultracache_profile_bypass',
    'ultracache_profile_run',
    'ultracache_runtime_js_scan',
    'ultracache_runtime_js_scan_id',
    'ultracache_runtime_js_scan_token',
    'ultracache_runtime_js_scan_nonce',
    'ultracache_runtime_js_scan_mode',
    'ultracache_runtime_js_scan_context',
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

$ultracache_parse_host_authority = static function ($authority) {
    $authority = trim((string) $authority);
    if ('' === $authority) {
        return array('host' => '', 'port' => 0);
    }

    if (false !== strpos($authority, ',')) {
        $parts = explode(',', $authority);
        $authority = (string) reset($parts);
    }

    $authority = preg_replace('/\s+/', '', $authority);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The advanced-cache drop-in loads before wp_parse_url() is available.
    $parsed = parse_url('http://' . ltrim((string) $authority, '/'));
    $host = is_array($parsed) && !empty($parsed['host']) ? (string) $parsed['host'] : '';
    $port = is_array($parsed) && isset($parsed['port']) ? (int) $parsed['port'] : 0;

    $host = strtolower(rtrim(trim($host), '.'));
    if ('' === $host || !preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:.]+\])$/i', $host)) {
        return array('host' => '', 'port' => 0);
    }

    if ($port < 1 || $port > 65535) {
        $port = 0;
    }

    return array('host' => $host, 'port' => $port);
};

$ultracache_normalize_host = static function ($host) use ($ultracache_parse_host_authority) {
    $authority = $ultracache_parse_host_authority($host);
    return (string) ($authority['host'] ?? '');
};
$ultracache_trusted_hosts = array();
foreach ((array) ($runtime_config['trusted_hosts'] ?? array()) as $trusted_host) {
    $trusted_host = $ultracache_normalize_host($trusted_host);
    if ('' !== $trusted_host) {
        $ultracache_trusted_hosts[$trusted_host] = true;
    }
}
$incoming_authority = $ultracache_parse_host_authority($raw_http_host);
$incoming_host = (string) ($incoming_authority['host'] ?? '');
$incoming_port = max(0, (int) ($incoming_authority['port'] ?? 0));
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
$url = $scheme . '://' . $incoming_host . ($incoming_port > 0 ? ':' . $incoming_port : '') . $request_uri;
// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The advanced-cache drop-in loads before wp_parse_url() is available.
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

$ultracache_normalize_woo_query_key = static function ($key) {
    $key = strtolower((string) $key);
    $key = preg_replace('/[^a-z0-9_-]/', '', $key);
    return is_string($key) ? $key : '';
};
$normalized_woo_query_vars = array();
foreach ((array) $query_vars as $query_key => $query_value) {
    if (is_array($query_value) || is_object($query_value)) {
        continue;
    }
    $query_key = $ultracache_normalize_woo_query_key($query_key);
    if ('' !== $query_key) {
        $normalized_woo_query_vars[$query_key] = (string) $query_value;
    }
}

foreach ((array) ($runtime_config['woocommerce_dynamic_query_rules'] ?? array()) as $woo_query_rule) {
    if (!is_array($woo_query_rule) || $path !== (string) ($woo_query_rule['path'] ?? '/')) {
        continue;
    }
    $required = (array) ($woo_query_rule['query'] ?? array());
    if (empty($required)) {
        continue;
    }
    $matched = true;
    foreach ($required as $query_key => $query_value) {
        if (!array_key_exists($query_key, $normalized_woo_query_vars) || (string) $normalized_woo_query_vars[$query_key] !== (string) $query_value) {
            $matched = false;
            break;
        }
    }
    if ($matched) {
        return;
    }
}

$woo_dynamic_query_keys = array_fill_keys((array) ($runtime_config['woocommerce_dynamic_query_keys'] ?? array()), true);
foreach (array_keys($normalized_woo_query_vars) as $query_key) {
    if (isset($woo_dynamic_query_keys[$query_key])) {
        return;
    }
}

$excluded_query_args_lookup = array_fill_keys(
    (array) (($runtime_config['query_cache_policy']['hard_blocked_keys'] ?? array())),
    true
);
foreach (array_keys($query_vars) as $query_key) {
    $query_key = $ultracache_normalize_query_key($query_key);
    if ('' !== $query_key && isset($excluded_query_args_lookup[$query_key])) {
        return;
    }
}

$normalized_query_vars = array();
if (!empty($query_vars)) {
    $query_evaluation = $ultracache_evaluate_query_for_cache($query_vars, $runtime_config);
    if (empty($query_evaluation['cacheable'])) {
        return;
    }

    $normalized_query_vars = (array) ($query_evaluation['normalized_vars'] ?? array());
    if (empty($normalized_query_vars)) {
        return;
    }
}

$host = ultracache_advanced_cache_normalize_cache_host_segment((string) $parts['host']);
$cache_key_path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';
$cache_key_path = preg_replace('#[^A-Za-z0-9/_-]#', '-', $cache_key_path);
$cache_key_path = trim((string) $cache_key_path, '/');
if ('' === $cache_key_path) {
    $cache_key_path = 'index';
}

$accept = (string) ultracache_advanced_cache_server_var('HTTP_ACCEPT', '');
$active_html_buckets = array_fill_keys((array) ($runtime_config['html_variant_buckets'] ?? array('orig')), true);
$ultracache_accept_allows_exact_media_type = static function ($header_value, $media_type) {
    $media_type = strtolower(trim((string) $media_type));
    if (1 !== preg_match('/\A[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+\z/', $media_type)) {
        return false;
    }

    $header_value = substr(strtolower((string) $header_value), 0, 8192);
    $allowed = false;
    foreach (array_slice(explode(',', $header_value), 0, 64) as $range) {
        $parts = array_map('trim', explode(';', (string) $range));
        $token = (string) array_shift($parts);
        if ($token !== $media_type) {
            continue;
        }

        $quality = 1.0;
        foreach ($parts as $parameter) {
            $parameter = trim((string) $parameter);
            if (1 !== preg_match('/\Aq\s*=/i', $parameter)) {
                continue;
            }
            if (1 === preg_match('/\Aq\s*=\s*(0(?:\.\d+)?|1(?:\.0+)?)\z/i', $parameter, $matches)) {
                $quality = max(0.0, min(1.0, (float) $matches[1]));
            }
            break;
        }

        if ($quality <= 0.0) {
            return false;
        }
        $allowed = true;
    }

    return $allowed;
};
$bucket = 'orig';
if (isset($active_html_buckets['avif']) && $ultracache_accept_allows_exact_media_type($accept, 'image/avif')) {
    $bucket = 'avif';
} elseif (isset($active_html_buckets['webp']) && $ultracache_accept_allows_exact_media_type($accept, 'image/webp')) {
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

$esi_metadata = ultracache_advanced_cache_get_esi_metadata($cache_file, $ultracache_cache_base_dir);
$is_esi_parent = !empty($esi_metadata);
$litespeed_esi_metadata = !empty($runtime_config['litespeed_enabled'])
    ? ultracache_advanced_cache_get_litespeed_esi_metadata($cache_file, $ultracache_cache_base_dir)
    : array();
$is_litespeed_esi_parent = !empty($litespeed_esi_metadata);

/*
 * WooCommerce session cookies remain a normal full-page cache bypass unless
 * this exact cache object is a verified native LiteSpeed Woo mini-cart ESI
 * parent. The exception is intentionally evaluated only after the canonical
 * cache file and its `.lsesi` sidecar have both been validated, so it cannot
 * affect Varnish, non-ESI LiteSpeed pages, dynamic WooCommerce paths, query
 * requests, logged-in sessions, or any other unsafe cookie.
 */
$ultracache_litespeed_woocommerce_shared_parent = false;
if ($ultracache_has_woocommerce_session_cookie) {
    $ultracache_litespeed_woocommerce_shared_parent = !empty($runtime_config['litespeed_enabled'])
        && empty($runtime_config['varnish_enabled'])
        && $is_litespeed_esi_parent
        && !empty($litespeed_esi_metadata['woocommerceMiniCart']);

    if (!$ultracache_litespeed_woocommerce_shared_parent) {
        return;
    }
}

$ultracache_get_accept_encoding_quality = static function ($header_value, $encoding_name) {
    $header_value = strtolower((string) $header_value);
    $encoding_name = strtolower(trim((string) $encoding_name));
    if ('' === $header_value || '' === $encoding_name) {
        return 0.0;
    }

    $wildcard_quality = null;
    foreach (explode(',', $header_value) as $item) {
        $parts = array_map('trim', explode(';', (string) $item));
        $token = strtolower((string) array_shift($parts));
        if ('' === $token) {
            continue;
        }

        $quality = 1.0;
        foreach ($parts as $parameter) {
            if (1 === preg_match('/\Aq\s*=\s*(0(?:\.\d+)?|1(?:\.0+)?)\z/i', (string) $parameter, $matches)) {
                $quality = max(0.0, min(1.0, (float) $matches[1]));
                break;
            }
        }

        if ($token === $encoding_name) {
            return $quality;
        }

        if ('*' === $token) {
            $wildcard_quality = $quality;
        }
    }

    return null === $wildcard_quality ? 0.0 : (float) $wildcard_quality;
};

$encoding = (string) ultracache_advanced_cache_server_var('HTTP_ACCEPT_ENCODING', '');
$serve_file = $cache_file;
$encoding_bucket = 'identity';
$content_encoding = '';
$encoding_candidates = array();
$brotli_quality = $ultracache_get_accept_encoding_quality($encoding, 'br');
$gzip_quality = $ultracache_get_accept_encoding_quality($encoding, 'gzip');

if (!$is_esi_parent && !$is_litespeed_esi_parent && $brotli_quality > 0.0) {
    $encoding_candidates[] = array(
        'file'     => $cache_file . '.br',
        'bucket'   => 'brotli',
        'header'   => 'br',
        'quality'  => $brotli_quality,
        'priority' => 2,
    );
}
if (!$is_litespeed_esi_parent && $gzip_quality > 0.0) {
    $encoding_candidates[] = array(
        'file'     => $cache_file . '.gz',
        'bucket'   => 'gzip',
        'header'   => 'gzip',
        'quality'  => $gzip_quality,
        'priority' => 1,
    );
}

usort(
    $encoding_candidates,
    static function ($left, $right) {
        if ((float) $left['quality'] === (float) $right['quality']) {
            return (int) $right['priority'] <=> (int) $left['priority'];
        }
        return (float) $right['quality'] <=> (float) $left['quality'];
    }
);

foreach ($encoding_candidates as $candidate) {
    $candidate_file = (string) $candidate['file'];
    if (!$ultracache_advanced_cache_is_cache_path($candidate_file) || !file_exists($candidate_file) || !is_readable($candidate_file)) {
        continue;
    }

    $serve_file = $candidate_file;
    $encoding_bucket = (string) $candidate['bucket'];
    $content_encoding = (string) $candidate['header'];
    break;
}

if (!ultracache_advanced_cache_is_valid_cache_payload_file($serve_file, $ultracache_cache_base_dir) || !is_readable($serve_file)) {
    return;
}

/**
 * Normalize an exact public URL for the LiteSpeed URL-tag contract.
 *
 * @param string $url      Public URL.
 * @param string $home_url Canonical WordPress home URL.
 * @return string
 */
function ultracache_advanced_cache_normalize_litespeed_tag_url($url, $home_url = '')
{
    $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
    if ('' === $url || false !== strpos($url, "\0")) {
        return '';
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The advanced-cache drop-in loads before wp_parse_url() is available.
    $home_parts = parse_url((string) $home_url);
    $preferred_scheme = is_array($home_parts) && !empty($home_parts['scheme'])
        ? strtolower((string) $home_parts['scheme'])
        : 'http';
    $home_host = is_array($home_parts) && !empty($home_parts['host'])
        ? strtolower((string) $home_parts['host'])
        : '';

    if (0 === strpos($url, '//')) {
        $url = $preferred_scheme . ':' . $url;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The advanced-cache drop-in loads before wp_parse_url() is available.
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return '';
    }

    $path = isset($parts['path']) ? rawurldecode((string) $parts['path']) : '';
    if ('' !== $path) {
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    $normalized = '';
    if (!empty($parts['scheme'])) {
        $scheme = strtolower((string) $parts['scheme']);
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        if ('' !== $home_host && $home_host === $host && '' !== $preferred_scheme) {
            $scheme = $preferred_scheme;
        }
        $normalized .= $scheme . '://';
    }
    if (!empty($parts['user'])) {
        $normalized .= (string) $parts['user'];
        if (isset($parts['pass'])) {
            $normalized .= ':' . (string) $parts['pass'];
        }
        $normalized .= '@';
    }
    if (!empty($parts['host'])) {
        $normalized .= strtolower((string) $parts['host']);
    }
    if (!empty($parts['port'])) {
        $normalized .= ':' . (int) $parts['port'];
    }
    $normalized .= $path;

    return $normalized;
}

/**
 * Return the exact LiteSpeed URL tag used by the WordPress runtime.
 *
 * @param string $url      Public URL.
 * @param string $home_url Canonical WordPress home URL.
 * @return string
 */
function ultracache_advanced_cache_get_litespeed_url_tag($url, $home_url = '')
{
    $url = ultracache_advanced_cache_normalize_litespeed_tag_url($url, $home_url);
    return '' !== $url ? 'uc_u_' . substr(hash('sha256', $url), 0, 24) : '';
}


/** Read bounded semantic LiteSpeed tags stored beside one cached HTML object. */
function ultracache_advanced_cache_get_litespeed_semantic_tags($cache_file, $base_dir)
{
    $cache_file = (string) $cache_file;
    $marker = $cache_file . '.lstags';
    if (!ultracache_advanced_cache_is_valid_cache_payload_file($cache_file, $base_dir)
        || !ultracache_advanced_cache_is_allowed_file_path($marker, true)
        || !is_readable($marker)) {
        return array();
    }

    $raw = ultracache_advanced_cache_safe_file_get_contents($marker);
    if (!is_string($raw) || '' === trim($raw)) {
        return array();
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || 1 !== (int) ($decoded['version'] ?? 0) || !is_array($decoded['tags'] ?? null)) {
        return array();
    }

    $tags = array();
    foreach ($decoded['tags'] as $tag) {
        $tag = is_scalar($tag) ? trim((string) $tag) : '';
        if ('' === $tag || 1 !== preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $tag)) {
            continue;
        }
        if (0 === strpos($tag, 'uc_s_') || 0 === strpos($tag, 'uc_u_')) {
            continue;
        }
        $tags[$tag] = $tag;
        if (count($tags) >= 48) {
            break;
        }
    }
    return array_values($tags);
}

/**
 * Send the public shared-cache contract for an UltraCache HTML object.
 *
 * @param array  $config    Runtime configuration.
 * @param bool   $cacheable Whether this response should receive a positive shared TTL.
 * @param string $bucket    Active UltraCache HTML bucket.
 * @param string    $url                 Exact public request URL.
 * @param bool|null $litespeed_cacheable Optional LSCache-specific override.
 * @param array     $esi_metadata        Optional normalized Varnish ESI parent metadata.
 * @param array     $litespeed_semantic_tags Optional LiteSpeed semantic cache tags.
 * @param array     $litespeed_esi_metadata Optional native LiteSpeed ESI parent metadata.
 * @param bool      $litespeed_woocommerce_shared_parent Whether this request uses the Woo ESI shared-parent vary bucket.
 * @return void
 */
function ultracache_advanced_cache_send_shared_html_headers(array $config, $cacheable = true, $bucket = 'orig', $url = '', $litespeed_cacheable = null, array $esi_metadata = array(), array $litespeed_semantic_tags = array(), array $litespeed_esi_metadata = array(), $litespeed_woocommerce_shared_parent = false)
{
    $shared_cache_enabled = !empty($config['shared_cache_delivery_enabled']);
    $shared_cache_proof_expires_at = max(0, (int) ($config['shared_cache_control_proof_expires_at'] ?? 0));
    $shared_cache_control_verified = !empty($config['shared_cache_control_verified'])
        && (0 === $shared_cache_proof_expires_at || $shared_cache_proof_expires_at > time());
    $litespeed_enabled = !empty($config['litespeed_enabled']);
    $litespeed_esi_parent = $litespeed_enabled
        && !empty($litespeed_esi_metadata)
        && 1 === (int) ($litespeed_esi_metadata['version'] ?? 0)
        && (int) ($litespeed_esi_metadata['fragmentCount'] ?? 0) > 0;
    if (headers_sent() || (!$shared_cache_enabled && !$litespeed_enabled)) {
        return;
    }

    $shared_cache_minutes = $shared_cache_control_verified
        ? max(1, min(525600, (int) ($config['shared_cache_managed_ttl_minutes'] ?? 1440)))
        : max(1, min(1440, (int) ($config['shared_cache_ttl_only_minutes'] ?? 10)));
    $shared_cache_seconds = $shared_cache_minutes * 60;
    $litespeed_minutes = max(1, min(525600, (int) ($config['cache_fresh_ttl_minutes'] ?? 1440)));
    $litespeed_seconds = $litespeed_minutes * 60;

    if ($shared_cache_enabled) {
        $is_esi_parent = !empty($esi_metadata) && in_array((int) ($esi_metadata['version'] ?? 0), array(1, 2, 3, 4), true);
        if ($is_esi_parent && function_exists('header_remove')) {
            header_remove('ETag');
            header_remove('Last-Modified');
        }
        $esi_count = $is_esi_parent ? max(1, min(64, (int) ($esi_metadata['fragmentCount'] ?? 1))) : 0;
        $esi_public_count = $is_esi_parent ? max(0, min($esi_count, (int) ($esi_metadata['publicCount'] ?? $esi_count))) : 0;
        $esi_private_count = $is_esi_parent ? max(0, min($esi_count - $esi_public_count, (int) ($esi_metadata['privateCount'] ?? 0))) : 0;
        $esi_unique_count = $is_esi_parent ? max(1, min($esi_count, (int) ($esi_metadata['uniqueFragmentCount'] ?? $esi_count))) : 0;
        $esi_min_ttl = $is_esi_parent ? max(0, min(604800, (int) ($esi_metadata['minTtl'] ?? 0))) : 0;
        $esi_max_ttl = $is_esi_parent ? max($esi_min_ttl, min(604800, (int) ($esi_metadata['maxTtl'] ?? $esi_min_ttl))) : 0;

        if (!$cacheable) {
            header('Cache-Control: private, no-store, max-age=0, must-revalidate', true);
            header('Surrogate-Control: ' . ($is_esi_parent ? 'content="ESI/1.0", no-store' : 'no-store'), true);
            header('X-UltraCache-Cacheable: 0');
            header('X-UltraCache-Surrogate-TTL: 0');
            header('X-UltraCache-Stale-While-Revalidate: 0');
            if ($is_esi_parent) {
                header('X-UltraCache-ESI: 1');
                header('X-UltraCache-ESI-Count: ' . (string) $esi_count);
                header('X-UltraCache-ESI-Public-Count: ' . (string) $esi_public_count);
                header('X-UltraCache-ESI-Private-Count: ' . (string) $esi_private_count);
                header('X-UltraCache-ESI-Unique-Count: ' . (string) $esi_unique_count);
                header('X-UltraCache-ESI-TTL-Min: ' . (string) $esi_min_ttl);
                header('X-UltraCache-ESI-TTL-Max: ' . (string) $esi_max_ttl);
            }
        } else {
            $stale_seconds = $shared_cache_control_verified
                ? max(0, min(86400, (int) ($config['varnish_stale_while_revalidate_seconds'] ?? 0)))
                : 0;
            $cache_control = 'public, max-age=0, s-maxage=' . (string) $shared_cache_seconds;
            if ($stale_seconds > 0) {
                $cache_control .= ', stale-while-revalidate=' . (string) $stale_seconds;
            }

            header('Cache-Control: ' . $cache_control, true);
            if ($is_esi_parent) {
                header('Surrogate-Control: content="ESI/1.0"', true);
                header('X-UltraCache-ESI: 1');
                header('X-UltraCache-ESI-Count: ' . (string) $esi_count);
                header('X-UltraCache-ESI-Public-Count: ' . (string) $esi_public_count);
                header('X-UltraCache-ESI-Private-Count: ' . (string) $esi_private_count);
                header('X-UltraCache-ESI-Unique-Count: ' . (string) $esi_unique_count);
                header('X-UltraCache-ESI-TTL-Min: ' . (string) $esi_min_ttl);
                header('X-UltraCache-ESI-TTL-Max: ' . (string) $esi_max_ttl);
                $esi_candidate = ultracache_advanced_cache_server_var('HTTP_X_ULTRACACHE_ESI_CANDIDATE', '');
                $esi_woocommerce_mini_cart = !empty($esi_metadata['woocommerceMiniCart']);
                if ($esi_private_count > 0 && $esi_woocommerce_mini_cart && '1' !== $esi_candidate) {
                    header('X-UltraCache-ESI-Shared-Parent: 1');
                }
            }
            header('X-UltraCache-Cacheable: 1');
            header('X-UltraCache-Surrogate-TTL: ' . (string) $shared_cache_seconds);
            header('X-UltraCache-Stale-While-Revalidate: ' . (string) $stale_seconds);
            header('X-UltraCache-Shared-Cache-Mode: ' . ($shared_cache_control_verified ? 'managed' : 'ttl-only'));
        }
    }

    if (!$litespeed_enabled) {
        return;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The advanced-cache drop-in loads before wp_parse_url() is available.
    $url_parts = parse_url((string) $url);
    $litespeed_cacheable = null === $litespeed_cacheable ? $cacheable : (bool) $litespeed_cacheable;
    $litespeed_cacheable = $litespeed_cacheable && (!is_array($url_parts) || empty($url_parts['query']));
    if (!$litespeed_cacheable) {
        header('X-LiteSpeed-Cache-Control: no-cache' . ($litespeed_esi_parent ? ',esi=on' : ''), true);
        if ($litespeed_esi_parent) {
            header('X-UltraCache-LiteSpeed-ESI: 1', true);
        }
        return;
    }

    $tags = array();
    $site_tag = isset($config['litespeed_site_tag']) ? (string) $config['litespeed_site_tag'] : '';
    if (1 === preg_match('/\Auc_s_[a-f0-9]{20}\z/', $site_tag)) {
        $tags[] = $site_tag;
    }

    $url_tag = ultracache_advanced_cache_get_litespeed_url_tag($url, (string) ($config['home_url'] ?? ''));
    if ('' !== $url_tag) {
        $tags[] = $url_tag;
    }
    foreach ($litespeed_semantic_tags as $semantic_tag) {
        $semantic_tag = is_scalar($semantic_tag) ? trim((string) $semantic_tag) : '';
        if ('' !== $semantic_tag && 1 === preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $semantic_tag)) {
            $tags[] = $semantic_tag;
        }
    }

    header('X-LiteSpeed-Cache-Control: public,max-age=' . (string) $litespeed_seconds . ($litespeed_esi_parent ? ',esi=on' : ''), true);
    if ($litespeed_esi_parent) {
        header('X-UltraCache-LiteSpeed-ESI: 1', true);
    }
    if (!empty($tags)) {
        header('X-LiteSpeed-Tag: ' . implode(',', array_values(array_unique($tags))), true);
    }

    if (!empty($config['html_vary_accept'])) {
        $bucket = in_array((string) $bucket, array('orig', 'webp', 'avif'), true) ? (string) $bucket : 'orig';
        $vary_value = $litespeed_woocommerce_shared_parent ? 'uc_woo_esi_' . $bucket : 'uc_' . $bucket;
        header('X-LiteSpeed-Vary: value=' . $vary_value, true);
    } elseif ($litespeed_woocommerce_shared_parent) {
        header('X-LiteSpeed-Vary: value=uc_woo_esi', true);
    }
}

$litespeed_semantic_tags = ultracache_advanced_cache_get_litespeed_semantic_tags($cache_file, $ultracache_cache_base_dir);

$fresh_ttl = max(60, (int) ($runtime_config['cache_fresh_ttl_minutes'] ?? 1440) * 60);
$max_stale = max($fresh_ttl, (int) ($runtime_config['cache_max_stale_minutes'] ?? 2880) * 60);
$freshness_mtime = $ultracache_get_filemtime($cache_file . '.fresh');
if (!$freshness_mtime) {
    $freshness_mtime = $ultracache_get_filemtime($cache_file);
}
$age = $freshness_mtime ? max(0, time() - (int) $freshness_mtime) : 0;
$serve_until = !empty($runtime_config['stale_while_revalidate_enabled']) ? $max_stale : $fresh_ttl;
if ($age > $serve_until) {
    return;
}

if (!empty($runtime_config['stale_while_revalidate_enabled']) && $age > $fresh_ttl && $age <= $max_stale) {
    $lock_file = $ultracache_get_revalidate_lock_path($cache_file);
    $should_revalidate = $ultracache_try_acquire_revalidate_lock($lock_file, $max_stale);

    $ultracache_record_hit($bucket, $encoding_bucket, true);
    $validator_metadata = (empty($esi_metadata) && !$is_litespeed_esi_parent)
        ? ultracache_advanced_cache_get_validator_metadata($serve_file, $encoding_bucket)
        : array();
    header('Content-Type: text/html; charset=UTF-8');
    header('Vary: ' . (!empty($runtime_config['html_vary_accept']) ? 'Accept, Accept-Encoding' : 'Accept-Encoding'), false);
    ultracache_advanced_cache_send_shared_html_headers($runtime_config, false, $bucket, $normalized, null, $esi_metadata, $litespeed_semantic_tags, $litespeed_esi_metadata, $ultracache_litespeed_woocommerce_shared_parent);
    ultracache_advanced_cache_send_validator_headers($validator_metadata);
    if (!empty($esi_metadata) && ultracache_advanced_cache_debug_headers_enabled()) {
        header('X-UltraCache-ESI-Validators: disabled');
    }
    if ($is_litespeed_esi_parent && ultracache_advanced_cache_debug_headers_enabled()) {
        header('X-UltraCache-LiteSpeed-ESI-Validators: disabled');
    }
    if (!empty($runtime_config['shared_cache_delivery_enabled']) || !empty($runtime_config['varnish_enabled']) || ultracache_advanced_cache_debug_headers_enabled()) {
        header('X-UltraCache-Variant: ' . $bucket);
    }
    if ('' !== $content_encoding) {
        header('Content-Encoding: ' . $content_encoding);
        header('X-UltraCache-Encoding: ' . $encoding_bucket);
    }
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

        $queued = $ultracache_queue_revalidate($normalized, $ultracache_runtime_control_secret, (string) ($runtime_config['configured_site_base'] ?? ''));
        if ($queued) {
            $ultracache_record_background_revalidation();
        } else {
            $ultracache_delete_file($lock_file);
        }
    }
    exit;
}

$ultracache_record_hit($bucket, $encoding_bucket, false);
$is_esi_parent = !empty($esi_metadata);
$is_dynamic_esi_parent = $is_esi_parent || $is_litespeed_esi_parent;
$validator_metadata = $is_dynamic_esi_parent
    ? array()
    : ultracache_advanced_cache_get_validator_metadata($serve_file, $encoding_bucket);
$not_modified = !$is_dynamic_esi_parent
    && !headers_sent()
    && !empty($validator_metadata)
    && ultracache_advanced_cache_request_is_not_modified($validator_metadata, $method);

if (!$not_modified) {
    header('Content-Type: text/html; charset=UTF-8');
}
header('Vary: ' . (!empty($runtime_config['html_vary_accept']) ? 'Accept, Accept-Encoding' : 'Accept-Encoding'), false);
ultracache_advanced_cache_send_shared_html_headers($runtime_config, true, $bucket, $normalized, !$not_modified, $esi_metadata, $litespeed_semantic_tags, $litespeed_esi_metadata, $ultracache_litespeed_woocommerce_shared_parent);
ultracache_advanced_cache_send_validator_headers($validator_metadata);
if ($is_esi_parent && ultracache_advanced_cache_debug_headers_enabled()) {
    header('X-UltraCache-ESI-Validators: disabled');
}
if ($is_litespeed_esi_parent && ultracache_advanced_cache_debug_headers_enabled()) {
    header('X-UltraCache-LiteSpeed-ESI-Validators: disabled');
}
if (!empty($runtime_config['shared_cache_delivery_enabled']) || !empty($runtime_config['varnish_enabled']) || ultracache_advanced_cache_debug_headers_enabled()) {
    header('X-UltraCache-Variant: ' . $bucket);
}
if (!$not_modified && '' !== $content_encoding) {
    header('Content-Encoding: ' . $content_encoding);
    header('X-UltraCache-Encoding: ' . $encoding_bucket);
} elseif (ultracache_advanced_cache_debug_headers_enabled()) {
    header('X-UltraCache-Encoding: ' . $encoding_bucket);
}
header('X-Ultra-Cache: HIT');
if (ultracache_advanced_cache_debug_headers_enabled()) {
    header('X-Ultra-Cache-Source: advanced-cache');
}
header('X-Ultra-Cache-Age: ' . (string) $age);
if ($not_modified) {
    ultracache_advanced_cache_send_not_modified_status();
    exit;
}
if ('HEAD' !== $method) {
    $ultracache_advanced_cache_safe_readfile($serve_file);
}
exit;
