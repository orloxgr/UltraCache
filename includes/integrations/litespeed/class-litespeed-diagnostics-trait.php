<?php
/**
 * Native LiteSpeed diagnostics.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_LiteSpeed_Diagnostics_Trait
{
    /**
     * Return the current LiteSpeed diagnostic state.
     *
     * Detection is intentionally separate from cache-behaviour verification.
     * When explicitly requested, UltraCache performs one anonymous origin probe
     * and records server/cache headers as detection evidence. No HIT/MISS proof
     * is required for LiteSpeed to be considered present.
     *
     * @param bool $allow_probe Whether a live origin-detection probe may run.
     * @return array<string,mixed>
     */
    public static function get_litespeed_diagnostics_status($allow_probe = false)
    {
        $transport = method_exists(static::class, 'get_confirmed_litespeed_transport_status')
            ? self::get_confirmed_litespeed_transport_status((bool) $allow_probe)
            : self::get_litespeed_transport_status(
                isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '',
                method_exists(__CLASS__, 'get_reverse_proxy_status') ? self::get_reverse_proxy_status() : array()
            );
        $settings = self::get_dashboard_settings();
        $rules_active = false;
        $rules_path = '';
        if (method_exists(__CLASS__, 'get_browser_cache_htaccess_path')) {
            $rules_path = self::get_browser_cache_htaccess_path();
            $contents = file_exists($rules_path)
                ? (string) ultracache_safe_file_get_contents($rules_path, 'litespeed_cache diagnostics')
                : '';
            $rules_active = false !== strpos($contents, '# BEGIN UltraCache LiteSpeed Cache')
                && false !== strpos($contents, '# END UltraCache LiteSpeed Cache');
        }

        $query_policy = method_exists(static::class, 'get_litespeed_query_cache_policy')
            ? self::get_litespeed_query_cache_policy()
            : array();
        $query_key_proof = method_exists(static::class, 'get_litespeed_query_cache_key_proof')
            ? self::get_litespeed_query_cache_key_proof()
            : array();

        return array_merge($transport, array(
            'nativeEnabled' => self::is_native_litespeed_html_cache_enabled(),
            'esi' => array(
                'enabled' => function_exists('ultracache_litespeed_esi_is_enabled') && ultracache_litespeed_esi_is_enabled(),
                'server' => sanitize_text_field((string) ($transport['server'] ?? '')),
                'capability' => method_exists(__CLASS__, 'get_litespeed_esi_capability_read_only')
                    ? self::get_litespeed_esi_capability_read_only()
                    : array('status' => 'not_tested', 'serverType' => 'unknown'),
                'woocommerceMiniCart' => function_exists('ultracache_get_woocommerce_litespeed_esi_mini_cart_markup'),
            ),
            'queryPolicy' => array(
                'version' => (int) ($query_policy['version'] ?? 1),
                'enabled' => !empty($query_policy['enabled']),
                'allowlist' => (array) ($query_policy['allowlist'] ?? array()),
                'hardBlockedKeys' => (array) ($query_policy['hard_blocked_keys'] ?? array()),
                'fingerprint' => (string) ($query_policy['fingerprint'] ?? ''),
                'safeQueryRetrievalEnabled' => !empty($query_key_proof['safe_query_retrieval_enabled']),
            ),
            'queryKeyProof' => array(
                'version' => (int) ($query_key_proof['version'] ?? 2),
                'status' => sanitize_key((string) ($query_key_proof['status'] ?? 'blocked')),
                'verified' => !empty($query_key_proof['verified']),
                'strategy' => sanitize_key((string) ($query_key_proof['strategy'] ?? 'none')),
                'fingerprint' => (string) ($query_key_proof['fingerprint'] ?? ''),
                'policyFingerprint' => (string) ($query_key_proof['policy_fingerprint'] ?? ''),
                'reason' => sanitize_key((string) ($query_key_proof['reason'] ?? '')),
                'missingCapabilities' => array_values((array) ($query_key_proof['missing_capabilities'] ?? array())),
                'safeQueryRetrievalEnabled' => !empty($query_key_proof['safe_query_retrieval_enabled']),
                'operationContract' => array(
                    'retrieval' => sanitize_key((string) ($query_key_proof['operation_contract']['retrieval'] ?? 'bypass')),
                    'storage' => sanitize_key((string) ($query_key_proof['operation_contract']['storage'] ?? 'no-cache')),
                    'exactPurge' => sanitize_key((string) ($query_key_proof['operation_contract']['exact_purge'] ?? 'skip')),
                    'publicRefill' => sanitize_key((string) ($query_key_proof['operation_contract']['public_refill'] ?? 'skip')),
                    'baseUrlAliasing' => !empty($query_key_proof['operation_contract']['base_url_aliasing']),
                ),
            ),
            'rulesActive' => $rules_active,
            'rulesPath' => $rules_path,
            'rulesState' => method_exists(__CLASS__, 'get_litespeed_rules_state_read_only')
                ? self::get_litespeed_rules_state_read_only()
                : array(),
            'activeBuckets' => self::get_litespeed_refill_buckets(),
            'refillAfterTargetedInvalidation' => self::is_native_litespeed_html_cache_enabled(),
            'warmWithSiteWarmup' => self::is_native_litespeed_html_cache_enabled(),
            'stalePurgeEnabled' => self::is_litespeed_stale_invalidation_enabled($settings),
            'invalidationQueue' => method_exists(static::class, 'get_litespeed_invalidation_queue_stats')
                ? self::get_litespeed_invalidation_queue_stats()
                : array(),
            'semanticTags' => array(
                'enabled' => self::is_native_litespeed_html_cache_enabled(),
                'pageMetadata' => 'page-cache .lstags sidecar',
                'deliveryPath' => self::is_native_litespeed_html_cache_enabled() ? 'advanced-cache' : 'standard',
                'exactUrlTagFallback' => true,
                'serverRuleContract' => (string) get_option('ultracache_litespeed_semantic_rules_contract', ''),
            ),
            'metrics' => self::get_litespeed_metrics_status(),
        ));
    }
}
