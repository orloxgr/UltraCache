<?php
/**
 * Runtime directory and embedded configuration handling.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Runtime_Config_Trait
{
    private static function ensure_directories()
    {
        $dirs = array(
            ULTRACACHE_CACHE_DIR,
            ULTRACACHE_AVIF_DIR,
            ULTRACACHE_WEBP_DIR,
            ULTRACACHE_OBJECT_CACHE_DIR,
            ultracache_generated_asset_dir('google-fonts'),
        );

        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }

            $index = trailingslashit($dir) . 'index.php';
            if (!file_exists($index)) {
                ultracache_safe_file_put_contents($index, "<?php\n// Silence is golden.\n", 0, 'ensure_directories index');
            }
        }

    }

    private static function build_runtime_config()
    {
        $settings = self::get_settings();
        $variant_policy = ultracache_get_html_variant_policy($settings);
        $query_policy = ultracache_get_query_cache_policy($settings);
        $wpml_parameter_cache = function_exists('ultracache_wpml_get_parameter_cache_contract')
            ? ultracache_wpml_get_parameter_cache_contract()
            : array('version' => 1, 'enabled' => false, 'query_key' => 'lang', 'languages' => array(), 'default_language' => '', 'fingerprint' => '');
        $litespeed_query_cache_key_proof = ultracache_build_litespeed_query_cache_key_proof($query_policy);
        $woocommerce_endpoint_contract = function_exists('ultracache_get_woocommerce_endpoint_contract')
            ? ultracache_get_woocommerce_endpoint_contract()
            : array('active' => false, 'available' => false, 'dynamicPaths' => array(), 'dynamicQueryRules' => array(), 'dynamicQueryKeys' => array(), 'fingerprint' => '');

        return self::normalize_runtime_config(array(
            'excluded_paths'                  => $settings['excluded_paths'],
            'excluded_query_args'             => array_values(array_unique(array_merge((array) $settings['excluded_query_args'], array('ultracache_runtime_js_scan', 'ultracache_runtime_js_scan_id', 'ultracache_runtime_js_scan_token', 'ultracache_runtime_js_scan_nonce', 'ultracache_runtime_js_scan_mode', 'ultracache_runtime_js_scan_context')))),
            'cache_query_strings'             => !empty($query_policy['enabled']),
            'cache_query_allowlist'           => (array) ($query_policy['allowlist'] ?? array()),
            'query_cache_policy'              => $query_policy,
            'wpml_parameter_cache'             => $wpml_parameter_cache,
            'litespeed_query_cache_key_proof'  => $litespeed_query_cache_key_proof,
            'cache_safe_tracking_cookies'      => !empty($settings['cache_safe_tracking_cookies']),
            'safe_tracking_cookie_patterns'   => !empty($settings['safe_tracking_cookie_patterns']) ? self::parse_textarea_setting(self::sanitize_cookie_pattern_setting((array) $settings['safe_tracking_cookie_patterns'])) : array(),
            'unsafe_cache_cookie_patterns'    => !empty($settings['unsafe_cache_cookie_patterns']) ? self::parse_textarea_setting(self::sanitize_cookie_pattern_setting((array) $settings['unsafe_cache_cookie_patterns'])) : array(),
            'woo_safe_mode'                   => !empty($settings['woo_safe_mode']),
            'woocommerce_contract_active'     => !empty($woocommerce_endpoint_contract['active']),
            'woocommerce_contract_available'  => !empty($woocommerce_endpoint_contract['available']),
            'woocommerce_contract_fingerprint' => sanitize_text_field((string) ($woocommerce_endpoint_contract['fingerprint'] ?? '')),
            'woocommerce_dynamic_paths'       => (array) ($woocommerce_endpoint_contract['dynamicPaths'] ?? array()),
            'woocommerce_dynamic_query_rules' => (array) ($woocommerce_endpoint_contract['dynamicQueryRules'] ?? array()),
            'woocommerce_dynamic_query_keys'  => (array) ($woocommerce_endpoint_contract['dynamicQueryKeys'] ?? array()),
            'cache_stats_enabled'             => !empty($settings['cache_stats_enabled']),
            'debug_headers_enabled'           => !empty($settings['debug_headers_enabled']),
            'shared_cache_delivery_enabled'  => !empty($settings['shared_cache_delivery_enabled']),
            'shared_cache_control_verified'  => !empty($settings['shared_cache_control_verified']),
            'shared_cache_control_proof_expires_at' => absint($settings['shared_cache_control_proof_expires_at'] ?? 0),
            'shared_cache_delivery_mode'     => sanitize_key((string) ($settings['shared_cache_delivery_mode'] ?? 'disabled')),
            'shared_cache_ttl_minutes'       => max(0, min(525600, absint($settings['shared_cache_ttl_minutes'] ?? 0))),
            'shared_cache_managed_ttl_minutes' => max(1, min(525600, absint($settings['shared_cache_managed_ttl_minutes'] ?? 1440))),
            'shared_cache_ttl_only_minutes'  => max(1, min(1440, absint($settings['shared_cache_ttl_only_minutes'] ?? 10))),
            'varnish_enabled'                 => !empty($settings['varnish_cli_enabled']),
            'varnish_stale_while_revalidate_seconds' => max(0, min(86400, absint($settings['varnish_stale_while_revalidate_seconds'] ?? 0))),
            'litespeed_enabled'                => !empty($settings['litespeed_cache_enabled']),
            'litespeed_site_tag'               => function_exists('ultracache_get_litespeed_site_tag') ? ultracache_get_litespeed_site_tag() : '',
            'configured_site_base'            => function_exists('ultracache_get_configured_site_base') ? ultracache_get_configured_site_base() : '',
            'home_url'                        => esc_url_raw(home_url('/')),
            'html_vary_accept'                => !empty($variant_policy['vary_accept']),
            'html_variant_buckets'            => (array) $variant_policy['buckets'],
            'object_cache_enabled'            => !empty($settings['object_cache_enabled']),
            'object_cache_backend'            => self::sanitize_object_cache_backend($settings['object_cache_backend'] ?? 'redis'),
            'object_cache_fallback_backend'   => self::sanitize_object_cache_fallback_backend($settings['object_cache_fallback_backend'] ?? 'apcu'),
            'redis_host'                      => (string) ($settings['redis_host'] ?? '127.0.0.1'),
            'redis_port'                      => max(1, absint($settings['redis_port'] ?? 6379)),
            'redis_username'                  => sanitize_text_field((string) ($settings['redis_username'] ?? '')),
            'redis_database'                  => max(0, absint($settings['redis_database'] ?? 0)),
            'redis_prefix'                    => preg_replace('/[^A-Za-z0-9:_\\-]/', '', (string) ($settings['redis_prefix'] ?? '')),
            'redis_use_tls'                   => !empty($settings['redis_use_tls']),
            'redis_persistent'                => !empty($settings['redis_persistent']),
            'redis_connect_timeout_ms'        => max(50, absint($settings['redis_connect_timeout_ms'] ?? 200)),
            'redis_read_timeout_ms'           => max(50, absint($settings['redis_read_timeout_ms'] ?? 200)),
            'stale_while_revalidate_enabled'  => !empty($settings['stale_while_revalidate_enabled']),
            'cache_fresh_ttl_minutes'         => max(1, absint($settings['cache_fresh_ttl_minutes'])),
            'cache_max_stale_minutes'         => max(absint($settings['cache_fresh_ttl_minutes']), absint($settings['cache_max_stale_minutes'])),
            'trusted_hosts'                   => ultracache_get_trusted_hosts(),
        ));
    }

    /**
     * Return the normalized secret-free configuration embedded in advanced-cache.php.
     *
     * @return array
     */

    public static function get_embedded_runtime_config()
    {
        return self::build_runtime_config();
    }

    /**
     * Normalize the active HTML image buckets embedded in advanced-cache.php.
     *
     * @param mixed $buckets Candidate buckets.
     * @return array
     */
    private static function normalize_html_variant_buckets($buckets)
    {
        $normalized = array('orig');
        foreach ((array) $buckets as $bucket) {
            $bucket = sanitize_key((string) $bucket);
            if (in_array($bucket, array('webp', 'avif'), true)) {
                $normalized[] = $bucket;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Normalize exact WooCommerce query-route rules for the early cache layer.
     *
     * @param mixed $rules Candidate route rules.
     * @return array<int,array<string,mixed>>
     */
    private static function normalize_woocommerce_dynamic_query_rules($rules)
    {
        $normalized = array();
        foreach ((array) $rules as $rule) {
            if (!is_array($rule) || empty($rule['query']) || !is_array($rule['query'])) {
                continue;
            }

            $path = '/' . ltrim((string) ($rule['path'] ?? '/'), '/');
            $path = '/' === $path ? '/' : trailingslashit(rtrim($path, '/'));
            $query = array();
            foreach ($rule['query'] as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    continue 2;
                }
                $key = function_exists('ultracache_normalize_query_policy_key')
                    ? ultracache_normalize_query_policy_key($key)
                    : sanitize_key((string) $key);
                if ('' === $key) {
                    continue 2;
                }
                $query[$key] = (string) $value;
            }
            if (empty($query)) {
                continue;
            }
            ksort($query, SORT_STRING);
            $fingerprint = hash('sha256', (string) wp_json_encode(array('path' => $path, 'query' => $query)));
            $normalized[$fingerprint] = array('path' => $path, 'query' => $query);
        }

        $normalized = array_values($normalized);
        usort($normalized, static function ($a, $b) {
            return strcmp((string) wp_json_encode($a), (string) wp_json_encode($b));
        });

        return $normalized;
    }

    private static function normalize_runtime_config(array $runtime)
    {
        $defaults = self::get_dashboard_defaults();
        $fresh_minutes = max(1, min(525600, absint($runtime['cache_fresh_ttl_minutes'] ?? $defaults['cacheFreshTtlMinutes'])));
        $max_stale_minutes = max($fresh_minutes, min(525600, absint($runtime['cache_max_stale_minutes'] ?? $defaults['cacheMaxStaleMinutes'])));
        $html_variant_buckets = self::normalize_html_variant_buckets($runtime['html_variant_buckets'] ?? array('orig'));
        $excluded_query_args = self::parse_textarea_setting(self::sanitize_setting_key_list((array) ($runtime['excluded_query_args'] ?? array())));
        $query_policy = ultracache_normalize_query_cache_policy(
            $runtime['query_cache_policy'] ?? array(),
            array(
                'cache_query_strings' => !empty($runtime['cache_query_strings']),
                'cache_query_allowlist' => $runtime['cache_query_allowlist'] ?? array(),
                'excluded_query_args' => $excluded_query_args,
            )
        );
        $wpml_parameter_cache = function_exists('ultracache_wpml_normalize_parameter_cache_contract')
            ? ultracache_wpml_normalize_parameter_cache_contract($runtime['wpml_parameter_cache'] ?? array())
            : array('version' => 1, 'enabled' => false, 'query_key' => 'lang', 'languages' => array(), 'default_language' => '', 'fingerprint' => '');
        $litespeed_query_cache_key_proof = ultracache_build_litespeed_query_cache_key_proof($query_policy);

        $normalized = array(
            'excluded_paths'                 => self::parse_textarea_setting(self::sanitize_excluded_paths_setting((array) ($runtime['excluded_paths'] ?? array()))),
            'excluded_query_args'            => $excluded_query_args,
            'cache_query_strings'            => !empty($query_policy['enabled']),
            'cache_query_allowlist'          => (array) ($query_policy['allowlist'] ?? array()),
            'query_cache_policy'             => $query_policy,
            'wpml_parameter_cache'            => $wpml_parameter_cache,
            'litespeed_query_cache_key_proof' => $litespeed_query_cache_key_proof,
            'safe_tracking_cookie_patterns' => self::parse_textarea_setting(self::sanitize_cookie_pattern_setting((array) ($runtime['safe_tracking_cookie_patterns'] ?? array()))),
            'unsafe_cache_cookie_patterns'  => self::parse_textarea_setting(self::sanitize_cookie_pattern_setting((array) ($runtime['unsafe_cache_cookie_patterns'] ?? array()))),
            'woo_safe_mode'                  => !empty($runtime['woo_safe_mode']),
            'woocommerce_contract_active'    => !empty($runtime['woocommerce_contract_active']),
            'woocommerce_contract_available' => !empty($runtime['woocommerce_contract_available']),
            'woocommerce_contract_fingerprint' => preg_match('/\A[a-f0-9]{64}\z/', (string) ($runtime['woocommerce_contract_fingerprint'] ?? '')) ? (string) $runtime['woocommerce_contract_fingerprint'] : '',
            'woocommerce_dynamic_paths'      => self::parse_textarea_setting(self::sanitize_excluded_paths_setting((array) ($runtime['woocommerce_dynamic_paths'] ?? array()))),
            'woocommerce_dynamic_query_rules' => self::normalize_woocommerce_dynamic_query_rules($runtime['woocommerce_dynamic_query_rules'] ?? array()),
            'woocommerce_dynamic_query_keys' => self::parse_textarea_setting(self::sanitize_setting_key_list((array) ($runtime['woocommerce_dynamic_query_keys'] ?? array()))),
            'cache_stats_enabled'            => !empty($runtime['cache_stats_enabled']),
            'debug_headers_enabled'          => !empty($runtime['debug_headers_enabled']),
            'shared_cache_delivery_enabled' => !empty($runtime['shared_cache_delivery_enabled']),
            'shared_cache_control_verified' => !empty($runtime['shared_cache_control_verified']),
            'shared_cache_control_proof_expires_at' => absint($runtime['shared_cache_control_proof_expires_at'] ?? 0),
            'shared_cache_delivery_mode'    => in_array(sanitize_key((string) ($runtime['shared_cache_delivery_mode'] ?? 'disabled')), array('managed', 'ttl-only', 'disabled'), true) ? sanitize_key((string) $runtime['shared_cache_delivery_mode']) : 'disabled',
            'shared_cache_ttl_minutes'      => max(0, min(525600, absint($runtime['shared_cache_ttl_minutes'] ?? 0))),
            'shared_cache_managed_ttl_minutes' => max(1, min(525600, absint($runtime['shared_cache_managed_ttl_minutes'] ?? 1440))),
            'shared_cache_ttl_only_minutes' => max(1, min(1440, absint($runtime['shared_cache_ttl_only_minutes'] ?? 10))),
            'varnish_enabled'                => !empty($runtime['varnish_enabled']),
            'varnish_stale_while_revalidate_seconds' => max(0, min(86400, absint($runtime['varnish_stale_while_revalidate_seconds'] ?? 0))),
            'litespeed_enabled'               => !empty($runtime['litespeed_enabled']),
            'litespeed_site_tag'              => 1 === preg_match('/\Auc_s_[a-f0-9]{20}\z/', (string) ($runtime['litespeed_site_tag'] ?? '')) ? (string) $runtime['litespeed_site_tag'] : '',
            'configured_site_base'           => function_exists('ultracache_normalize_configured_site_base') ? ultracache_normalize_configured_site_base((string) ($runtime['configured_site_base'] ?? '')) : '',
            'home_url'                       => esc_url_raw((string) ($runtime['home_url'] ?? home_url('/'))),
            'html_vary_accept'               => !empty($runtime['html_vary_accept']) && count($html_variant_buckets) > 1,
            'html_variant_buckets'           => $html_variant_buckets,
            'object_cache_enabled'           => !empty($runtime['object_cache_enabled']),
            'object_cache_backend'           => self::sanitize_object_cache_backend($runtime['object_cache_backend'] ?? 'redis'),
            'object_cache_fallback_backend'  => self::sanitize_object_cache_fallback_backend($runtime['object_cache_fallback_backend'] ?? 'apcu'),
            'redis_host'                     => trim((string) ($runtime['redis_host'] ?? '127.0.0.1')) ?: '127.0.0.1',
            'redis_port'                     => max(1, min(65535, absint($runtime['redis_port'] ?? 6379))),
            'redis_username'                 => sanitize_text_field((string) ($runtime['redis_username'] ?? '')),
            'redis_database'                 => max(0, absint($runtime['redis_database'] ?? 0)),
            'redis_prefix'                   => preg_replace('/[^A-Za-z0-9:_\\-]/', '', (string) ($runtime['redis_prefix'] ?? '')),
            'redis_use_tls'                  => !empty($runtime['redis_use_tls']),
            'redis_persistent'               => !empty($runtime['redis_persistent']),
            'redis_connect_timeout_ms'       => max(50, min(15000, absint($runtime['redis_connect_timeout_ms'] ?? 200))),
            'redis_read_timeout_ms'          => max(50, min(15000, absint($runtime['redis_read_timeout_ms'] ?? 200))),
            'stale_while_revalidate_enabled' => !empty($runtime['stale_while_revalidate_enabled']),
            'cache_fresh_ttl_minutes'        => $fresh_minutes,
            'cache_max_stale_minutes'        => $max_stale_minutes,
            'trusted_hosts'                  => array_values(array_filter(array_map('ultracache_normalize_host', (array) ($runtime['trusted_hosts'] ?? ultracache_get_trusted_hosts())))),
        );

        sort($normalized['excluded_paths']);
        sort($normalized['excluded_query_args']);
        sort($normalized['cache_query_allowlist']);
        sort($normalized['safe_tracking_cookie_patterns']);
        sort($normalized['unsafe_cache_cookie_patterns']);

        return $normalized;
    }

    /**
     * Mark the WooCommerce endpoint contract for one coalesced shutdown sync.
     *
     * @return void
     */
    public static function schedule_woocommerce_endpoint_contract_sync()
    {
        self::$woocommerce_endpoint_contract_sync_pending = true;
    }

    /**
     * Watch current WooCommerce routing options without maintaining legacy
     * endpoint defaults inside UltraCache.
     *
     * @param string $option    Option name.
     * @param mixed  $old_value Previous value.
     * @param mixed  $value     New value.
     * @return void
     */
    public static function maybe_schedule_woocommerce_endpoint_contract_sync_from_option($option, $old_value = null, $value = null)
    {
        $option = (string) $option;
        $page_options = array(
            'woocommerce_cart_page_id',
            'woocommerce_checkout_page_id',
            'woocommerce_myaccount_page_id',
            'permalink_structure',
        );
        $is_endpoint_option = 0 === strpos($option, 'woocommerce_') && '_endpoint' === substr($option, -9);
        if (in_array($option, $page_options, true) || $is_endpoint_option) {
            self::schedule_woocommerce_endpoint_contract_sync();
        }
    }

    /**
     * Watch permalink/slug changes to the three WooCommerce dynamic base pages.
     *
     * @param int $post_id Updated page ID.
     * @return void
     */
    public static function maybe_schedule_woocommerce_endpoint_contract_sync_from_page($post_id)
    {
        if (!function_exists('wc_get_page_id')) {
            return;
        }

        $post_id = absint($post_id);
        if ($post_id < 1) {
            return;
        }

        foreach (array('cart', 'checkout', 'myaccount') as $page_key) {
            if ($post_id === absint(wc_get_page_id($page_key))) {
                self::schedule_woocommerce_endpoint_contract_sync();
                return;
            }
        }
    }

    /**
     * Synchronize the dynamic WooCommerce routing contract into every early
     * cache layer. Routing changes are a clean-slate cache boundary because a
     * previously public URL may have become a cart/checkout/account page.
     *
     * @return bool
     */
    public static function maybe_sync_woocommerce_endpoint_contract()
    {
        if (!function_exists('ultracache_build_woocommerce_endpoint_contract')) {
            return false;
        }

        // WooCommerce permalink helpers require WordPress's rewrite runtime.
        // Never persist an early-bootstrap "unavailable" observation as the
        // routing source of truth; wait for normal bootstrap instead.
        $woocommerce_active = class_exists('WooCommerce') || defined('WC_VERSION') || function_exists('WC');
        if (
            $woocommerce_active
            && function_exists('ultracache_woocommerce_routing_runtime_ready')
            && !ultracache_woocommerce_routing_runtime_ready()
        ) {
            return false;
        }

        $current = ultracache_build_woocommerce_endpoint_contract();
        if (!is_array($current) || empty($current['fingerprint'])) {
            return false;
        }

        $option_key = function_exists('ultracache_woocommerce_endpoint_contract_option_key')
            ? ultracache_woocommerce_endpoint_contract_option_key()
            : 'ultracache_woocommerce_endpoint_contract_v1';
        $stored = get_option($option_key, array());
        $stored = is_array($stored) ? $stored : array();

        $current_fingerprint = (string) ($current['fingerprint'] ?? '');
        $current_routing = (string) ($current['routingFingerprint'] ?? '');
        $stored_fingerprint = (string) ($stored['fingerprint'] ?? '');
        $stored_applied_fingerprint = (string) ($stored['appliedFingerprint'] ?? $stored_fingerprint);
        $stored_applied_routing = (string) ($stored['appliedRoutingFingerprint'] ?? ($stored['routingFingerprint'] ?? ''));

        if (
            '' !== $stored_fingerprint
            && hash_equals($stored_fingerprint, $current_fingerprint)
            && '' !== $stored_applied_fingerprint
            && hash_equals($stored_applied_fingerprint, $current_fingerprint)
        ) {
            self::$woocommerce_endpoint_contract_sync_pending = false;
            return true;
        }

        $routing_changed = '' !== $stored_applied_routing
            ? !hash_equals($stored_applied_routing, $current_routing)
            : !empty($current['active']);

        if (!$routing_changed) {
            $current['appliedFingerprint'] = $current_fingerprint;
            $current['appliedRoutingFingerprint'] = $current_routing;
            update_option($option_key, $current, false);
            self::$woocommerce_endpoint_contract_sync_pending = false;
            return true;
        }

        // Persist the observed routing contract before rebuilding early layers.
        // The separate applied fingerprints stay on the last successfully
        // installed contract, so a failed filesystem/server-rule sync retries
        // on the next admin request instead of becoming a false success. This
        // also lets deactivation rebuild advanced-cache/.htaccess from the new
        // inactive contract rather than re-reading the previous active snapshot.
        $staged = $current;
        $staged['appliedFingerprint'] = $stored_applied_fingerprint;
        $staged['appliedRoutingFingerprint'] = $stored_applied_routing;
        update_option($option_key, $staged, false);

        $engine = method_exists(static::class, 'get_engine_instance') ? self::get_engine_instance() : null;
        if ($engine && method_exists($engine, 'purge_all')) {
            $purged = $engine->purge_all(array(
                'reason' => 'woocommerce_endpoint_contract_change',
                'source' => 'woocommerce-routing-sync',
            ));
            if (!$purged) {
                return false;
            }
        }

        $settings = self::get_dashboard_settings();
        $page_cache_enabled = !empty($settings['pageCacheEnabled']);
        $page_cache_sync = self::sync_page_cache_bootstrap($page_cache_enabled, false);
        if (is_wp_error($page_cache_sync)) {
            return false;
        }
        if (false === self::sync_apache_static_html_delivery_rules()) {
            return false;
        }
        if (false === self::sync_litespeed_cache_rules()) {
            return false;
        }

        $current['appliedFingerprint'] = $current_fingerprint;
        $current['appliedRoutingFingerprint'] = $current_routing;
        update_option($option_key, $current, false);
        self::$woocommerce_endpoint_contract_sync_pending = false;
        return true;
    }

    /**
     * Execute one deferred contract sync after WooCommerce settings/page writes.
     *
     * @return void
     */
    public static function maybe_run_scheduled_woocommerce_endpoint_contract_sync()
    {
        if (!self::$woocommerce_endpoint_contract_sync_pending) {
            return;
        }

        self::maybe_sync_woocommerce_endpoint_contract();
    }

}
