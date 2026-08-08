<?php
/**
 * Canonical query-string cache policy helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalize one query-policy key using the same contract in WordPress and the
 * early advanced-cache drop-in.
 *
 * @param mixed $key Query key candidate.
 * @return string
 */
function ultracache_normalize_query_policy_key($key)
{
    if (is_array($key) || is_object($key)) {
        return '';
    }

    $key = strtolower((string) $key);
    $key = preg_replace('/[^a-z0-9_-]/', '', $key);

    return is_string($key) ? $key : '';
}

/**
 * Normalize, deduplicate, and sort query-policy keys.
 *
 * @param mixed $keys Query key candidates.
 * @return array<int,string>
 */
function ultracache_normalize_query_policy_keys($keys)
{
    if (!is_array($keys)) {
        $keys = preg_split('/\r?\n/', (string) $keys);
    }

    $normalized = array();
    foreach ((array) $keys as $key) {
        $key = ultracache_normalize_query_policy_key($key);
        if ('' !== $key) {
            $normalized[$key] = true;
        }
    }

    $normalized = array_keys($normalized);
    sort($normalized, SORT_STRING);

    return $normalized;
}

/**
 * Return the immutable security floor for query-string cache variants.
 *
 * @return array<int,string>
 */
function ultracache_get_query_cache_hard_blocked_defaults()
{
    return array(
        '_ajax_nonce',
        '_wpnonce',
        'access_token',
        'auth',
        'auth_token',
        'cancel_order',
        'customer-logout',
        'download_file',
        'key',
        'logout',
        'nonce',
        'order_key',
        'pass',
        'password',
        'pay_for_order',
        'pwd',
        'redirect_to',
        'rest_route',
        'security',
        'token',
        'ultracache_bucket',
        'ultracache_callback_profile',
        'ultracache_probe_compression',
        'ultracache_profile_bypass',
        'ultracache_profile_run',
        'ultracache_revalidate',
        'ultracache_rt',
        'ultracache_runtime_js_scan',
        'ultracache_runtime_js_scan_id',
        'ultracache_runtime_js_scan_nonce',
        'ultracache_rv',
        'ultracache_sqlite_exposure_probe',
        'ultracache_store_profile',
        'ultracache_store_profile_verbose',
        'ultracache_store_profile_verbose_settings',
        'ultracache_varnish_canary',
    );
}

/**
 * Build one normalized query-string cache policy projection.
 *
 * The fingerprint represents effective cache behavior. Configured allowlist
 * entries that are also hard-blocked remain visible for diagnostics but do not
 * enter the effective allowlist or change the fingerprint.
 *
 * @param bool  $enabled             Query-string page-cache switch.
 * @param mixed $allowlist           Configured allowlist.
 * @param mixed $excluded_query_args Configured/runtime exclusions.
 * @return array<string,mixed>
 */
function ultracache_build_query_cache_policy($enabled, $allowlist = array(), $excluded_query_args = array())
{
    $configured_allowlist = ultracache_normalize_query_policy_keys($allowlist);
    $hard_blocked_keys = ultracache_normalize_query_policy_keys(array_merge(
        ultracache_get_query_cache_hard_blocked_defaults(),
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

/**
 * Return the canonical query-string cache policy for one settings array.
 *
 * @param array $settings Internal UltraCache settings.
 * @return array<string,mixed>
 */
function ultracache_get_query_cache_policy(array $settings = array())
{
    return ultracache_build_query_cache_policy(
        !empty($settings['cache_query_strings']),
        $settings['cache_query_allowlist'] ?? array(),
        $settings['excluded_query_args'] ?? array()
    );
}

/**
 * Normalize an embedded policy with legacy runtime fields as fallback.
 *
 * @param mixed $policy   Embedded policy candidate.
 * @param array $fallback Legacy runtime fields.
 * @return array<string,mixed>
 */
function ultracache_normalize_query_cache_policy($policy, array $fallback = array())
{
    $policy = is_array($policy) ? $policy : array();
    $enabled = array_key_exists('enabled', $policy)
        ? !empty($policy['enabled'])
        : !empty($fallback['cache_query_strings']);
    $allowlist = array_key_exists('configured_allowlist', $policy)
        ? $policy['configured_allowlist']
        : (array_key_exists('allowlist', $policy) ? $policy['allowlist'] : ($fallback['cache_query_allowlist'] ?? array()));
    $excluded = array_merge(
        (array) ($policy['hard_blocked_keys'] ?? array()),
        (array) ($fallback['excluded_query_args'] ?? array())
    );

    return ultracache_build_query_cache_policy($enabled, $allowlist, $excluded);
}

/**
 * Build the LiteSpeed query cache-key proof for one canonical policy.
 *
 * LiteSpeed's documented query-key modifiers can remove exact or prefix-matched
 * query keys. UltraCache's canonical query contract additionally preserves the
 * allowed keys while sorting key order, recursively sorting structured values,
 * and emitting RFC3986 encoding. Those requirements cannot be represented by
 * drop-only key modifiers without either fragmenting equivalent URLs or aliasing
 * distinct allowed values.
 *
 * @param array $policy Canonical query-cache policy.
 * @return array<string,mixed>
 */
function ultracache_build_litespeed_query_cache_key_proof(array $policy = array())
{
    $policy = ultracache_normalize_query_cache_policy($policy);
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
