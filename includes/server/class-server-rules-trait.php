<?php
/**
 * UltraCache server-rule and page-cache bootstrap methods.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Server_Rules_Trait
{
    private static function get_browser_cache_htaccess_path()
    {
        return trailingslashit(ultracache_get_wordpress_home_path()) . '.htaccess';
    }

    private static function get_browser_cache_htaccess_block()
    {
        return implode("\n", array(
            '<IfModule mod_mime.c>',
            'AddType application/manifest+json .webmanifest',
            'AddType application/wasm .wasm',
            'AddType image/avif .avif',
            'AddType image/avif-sequence .avifs',
            '</IfModule>',
            '<IfModule mod_expires.c>',
            'ExpiresActive On',
            'ExpiresByType text/html "access plus 0 seconds"',
            'ExpiresByType text/xml "access plus 0 seconds"',
            'ExpiresByType application/xml "access plus 0 seconds"',
            'ExpiresByType application/json "access plus 0 seconds"',
            'ExpiresByType application/manifest+json "access plus 1 year"',
            'ExpiresByType text/css "access plus 1 year"',
            'ExpiresByType text/javascript "access plus 1 year"',
            'ExpiresByType application/javascript "access plus 1 year"',
            'ExpiresByType application/x-javascript "access plus 1 year"',
            'ExpiresByType image/jpeg "access plus 1 year"',
            'ExpiresByType image/png "access plus 1 year"',
            'ExpiresByType image/gif "access plus 1 year"',
            'ExpiresByType image/webp "access plus 1 year"',
            'ExpiresByType image/avif "access plus 1 year"',
            'ExpiresByType image/avif-sequence "access plus 1 year"',
            'ExpiresByType image/svg+xml "access plus 1 year"',
            'ExpiresByType image/x-icon "access plus 1 year"',
            'ExpiresByType font/ttf "access plus 1 year"',
            'ExpiresByType font/otf "access plus 1 year"',
            'ExpiresByType font/woff "access plus 1 year"',
            'ExpiresByType font/woff2 "access plus 1 year"',
            'ExpiresByType application/font-woff "access plus 1 year"',
            'ExpiresByType application/font-woff2 "access plus 1 year"',
            'ExpiresByType application/vnd.ms-fontobject "access plus 1 year"',
            'ExpiresByType application/wasm "access plus 1 year"',
            'ExpiresByType video/mp4 "access plus 1 year"',
            'ExpiresByType video/webm "access plus 1 year"',
            'ExpiresByType video/ogg "access plus 1 year"',
            'ExpiresByType audio/mpeg "access plus 1 year"',
            'ExpiresByType audio/mp4 "access plus 1 year"',
            'ExpiresByType audio/ogg "access plus 1 year"',
            'ExpiresByType audio/wav "access plus 1 year"',
            '</IfModule>',
            '<IfModule mod_headers.c>',
            '<FilesMatch "\\.(css|js|mjs|gif|png|jpe?g|webp|avif|avifs|svg|ico|woff2?|ttf|otf|eot|webmanifest|wasm|mp4|m4v|webm|ogv|ogg|mp3|m4a|wav)$">',
            'Header set Cache-Control "public, max-age=31536000, immutable"',
            '</FilesMatch>',
            '<FilesMatch "\\.(html?|json|xml)$">',
            'Header set Cache-Control "public, max-age=0, must-revalidate"',
            '</FilesMatch>',
            '</IfModule>',
        ));
    }

    private static function write_htaccess_rules_with_verification($path, $contents, $original_contents, $original_exists, $context)
    {
        $context = (string) $context;
        if (!ultracache_is_allowed_writable_path($path, $context)) {
            return false;
        }

        $filesystem = ultracache_get_wp_filesystem();
        if (
            !$filesystem
            || !method_exists($filesystem, 'put_contents')
            || !method_exists($filesystem, 'get_contents')
            || !method_exists($filesystem, 'exists')
            || !method_exists($filesystem, 'delete')
        ) {
            return false;
        }

        $dir = dirname($path);
        if (method_exists($filesystem, 'is_writable')) {
            $writable_path = $original_exists ? $path : $dir;
            if (!$filesystem->is_writable($writable_path)) {
                return false;
            }
        }

        $mode = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644;
        $written = $filesystem->put_contents($path, (string) $contents, $mode);
        $verified = false;

        if (false !== $written) {
            $read_back = $filesystem->get_contents($path);
            $verified = is_string($read_back)
                && hash_equals(hash('sha256', (string) $contents), hash('sha256', $read_back));
        }

        if ($verified) {
            return true;
        }

        if ($original_exists) {
            $restored = $filesystem->put_contents($path, (string) $original_contents, $mode);
            $restored_contents = false !== $restored ? $filesystem->get_contents($path) : false;
            $rollback_verified = is_string($restored_contents)
                && hash_equals(hash('sha256', (string) $original_contents), hash('sha256', $restored_contents));

            if (!$rollback_verified) {
                ultracache_debug_log('htaccess rules write and rollback failed', array('path' => $path, 'context' => $context));
            }

            return false;
        }

        if ($filesystem->exists($path)) {
            $filesystem->delete($path, false, 'f');
        }

        if ($filesystem->exists($path)) {
            ultracache_debug_log('htaccess rules write cleanup failed', array('path' => $path, 'context' => $context));
        }

        return false;
    }

    private static function write_browser_cache_rules_with_verification($path, $contents, $original_contents, $original_exists)
    {
        return self::write_htaccess_rules_with_verification($path, $contents, $original_contents, $original_exists, 'sync_browser_cache_rules');
    }

    public static function sync_browser_cache_rules($enabled = null)
    {
        $begin = '# BEGIN UltraCache Browser Cache';
        $end   = '# END UltraCache Browser Cache';

        if (null === $enabled) {
            $settings = self::get_settings();
            $enabled = !empty($settings['browser_cache_rules']);
        }

        $path = self::get_browser_cache_htaccess_path();
        $original_exists = file_exists($path);
        $contents = $original_exists ? (string) ultracache_safe_file_get_contents($path, 'sync_browser_cache_rules') : '';
        $has_block = (false !== strpos($contents, $begin) && false !== strpos($contents, $end));

        if ($original_exists && !ultracache_path_is_writable($path)) {
            return !$enabled && !$has_block;
        }
        $pattern  = '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\R*/s';
        $updated  = (string) preg_replace($pattern, '', $contents);
        $updated  = rtrim($updated);

        if ($enabled) {
            $block = $begin . "\n" . self::get_browser_cache_htaccess_block() . "\n" . $end;
            $updated = '' === $updated ? $block : ($updated . "\n\n" . $block);
        }

        $updated = '' === trim($updated) ? '' : (rtrim($updated) . "\n");

        if ($updated === $contents) {
            return true;
        }

        $dir = dirname($path);
        if (!file_exists($dir) && !ultracache_safe_mkdir($dir, 0755, true, 'sync_browser_cache_rules') && !file_exists($dir)) {
            return false;
        }

        return self::write_browser_cache_rules_with_verification($path, $updated, $contents, $original_exists);
    }

    /** Return the current UltraCache-managed LiteSpeed rules schema version. */
    private static function get_litespeed_cache_rules_schema_version()
    {
        return 3;
    }

    /** Return the persistent managed LiteSpeed rules state option name. */
    private static function get_litespeed_rules_state_option_name()
    {
        return 'ultracache_litespeed_rules_state_v1';
    }

    /** Return the current persisted managed LiteSpeed rules state. */
    public static function get_litespeed_rules_state_read_only()
    {
        $default = array(
            'schemaVersion' => 1,
            'rulesSchema' => 0,
            'status' => 'unknown',
            'contextHash' => '',
            'desiredRulesHash' => '',
            'appliedRulesHash' => '',
            'reason' => '',
            'updatedAt' => 0,
            'updatedAtHuman' => '',
        );
        $stored = get_option(self::get_litespeed_rules_state_option_name(), array());
        if (!is_array($stored) || empty($stored)) {
            return $default;
        }

        $stored = array_merge($default, $stored);
        $stored['schemaVersion'] = 1;
        $stored['rulesSchema'] = max(0, (int) ($stored['rulesSchema'] ?? 0));
        $stored['status'] = sanitize_key((string) ($stored['status'] ?? 'unknown'));
        $stored['contextHash'] = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($stored['contextHash'] ?? '')));
        $stored['desiredRulesHash'] = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($stored['desiredRulesHash'] ?? '')));
        $stored['appliedRulesHash'] = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($stored['appliedRulesHash'] ?? '')));
        $stored['reason'] = sanitize_key((string) ($stored['reason'] ?? ''));
        $stored['updatedAt'] = max(0, (int) ($stored['updatedAt'] ?? 0));
        $stored['updatedAtHuman'] = $stored['updatedAt'] > 0
            ? gmdate('Y-m-d H:i:s', $stored['updatedAt']) . ' UTC'
            : '';
        return $stored;
    }

    /** Persist the managed LiteSpeed rules state. */
    private static function persist_litespeed_rules_state($status, $context_hash, $desired_rules_hash, $applied_rules_hash, $reason)
    {
        $core = array(
            'schemaVersion' => 1,
            'rulesSchema' => self::get_litespeed_cache_rules_schema_version(),
            'status' => sanitize_key((string) $status),
            'contextHash' => strtolower((string) $context_hash),
            'desiredRulesHash' => strtolower((string) $desired_rules_hash),
            'appliedRulesHash' => strtolower((string) $applied_rules_hash),
            'reason' => sanitize_key((string) $reason),
        );
        $current = self::get_litespeed_rules_state_read_only();
        $current_core = array_intersect_key($current, $core);
        if ($current_core === $core) {
            return $current;
        }

        $next = $core;
        $next['updatedAt'] = time();
        $next['updatedAtHuman'] = gmdate('Y-m-d H:i:s') . ' UTC';
        update_option(self::get_litespeed_rules_state_option_name(), $next, false);
        return $next;
    }

    /** Whether the official WooCommerce plugin is currently active for this site/network. */
    private static function is_woocommerce_active_for_litespeed_rules_context()
    {
        if (!function_exists('is_plugin_active') || (is_multisite() && !function_exists('is_plugin_active_for_network'))) {
            ultracache_require_wordpress_admin_include('plugin.php', 'is_plugin_active');
        }

        $plugin = 'woocommerce/woocommerce.php';
        $site_active = function_exists('is_plugin_active') && is_plugin_active($plugin);
        $network_active = is_multisite()
            && function_exists('is_plugin_active_for_network')
            && is_plugin_active_for_network($plugin);

        return $site_active || $network_active;
    }

    /** Build the persistent/lifecycle-only context used to generate LiteSpeed server rules. */
    private static function get_litespeed_rules_context(array $settings)
    {
        $native_enabled = (!empty($settings['litespeed_cache_enabled']) || !empty($settings['liteSpeedCacheEnabled']))
            && (!empty($settings['enabled']) || !empty($settings['pageCacheEnabled']));
        $varnish_enabled = method_exists(__CLASS__, 'is_varnish_runtime_enabled')
            ? self::is_varnish_runtime_enabled($settings)
            : false;
        $capability = method_exists(__CLASS__, 'get_litespeed_esi_capability_read_only')
            ? self::get_litespeed_esi_capability_read_only()
            : array('status' => 'not_tested', 'serverType' => 'unknown');
        $woocommerce_active = self::is_woocommerce_active_for_litespeed_rules_context();
        $variant_policy = ultracache_get_html_variant_policy($settings);
        $query_policy = method_exists(static::class, 'get_litespeed_query_cache_policy')
            ? self::get_litespeed_query_cache_policy()
            : array();

        $woo_esi_shared_vary = $native_enabled
            && !$varnish_enabled
            && 'supported' === (string) ($capability['status'] ?? 'not_tested')
            && 'enterprise' === (string) ($capability['serverType'] ?? 'unknown')
            && $woocommerce_active;

        $context = array(
            'rulesSchema' => self::get_litespeed_cache_rules_schema_version(),
            'nativeEnabled' => $native_enabled,
            'varnishEnabled' => $varnish_enabled,
            'esiCapabilityStatus' => (string) ($capability['status'] ?? 'not_tested'),
            'esiServerType' => (string) ($capability['serverType'] ?? 'unknown'),
            'woocommerceActive' => $woocommerce_active,
            'woocommerceEsiSharedVary' => $woo_esi_shared_vary,
            'variantBuckets' => array_values((array) ($variant_policy['buckets'] ?? array('orig'))),
            'variantVaryAccept' => !empty($variant_policy['vary_accept']),
            'queryPolicyFingerprint' => (string) ($query_policy['fingerprint'] ?? ''),
            'unsafeCookiePatterns' => array_values((array) ($settings['unsafe_cache_cookie_patterns'] ?? array())),
            'excludedPaths' => array_values((array) ($settings['excluded_paths'] ?? array())),
        );
        $context['hash'] = hash('sha256', (string) wp_json_encode($context));
        return $context;
    }

    /**
     * Read the schema version from an existing managed LiteSpeed block.
     *
     * Blocks written before schema comments existed are schema 1. A missing
     * block is schema 0.
     *
     * @param string $contents Root .htaccess contents.
     * @return int
     */
    private static function get_litespeed_cache_rules_schema_from_contents($contents)
    {
        $contents = (string) $contents;
        $begin = '# BEGIN UltraCache LiteSpeed Cache';
        $end = '# END UltraCache LiteSpeed Cache';
        $begin_pos = strpos($contents, $begin);
        $end_pos = false !== $begin_pos ? strpos($contents, $end, $begin_pos + strlen($begin)) : false;

        if (false === $begin_pos || false === $end_pos) {
            return 0;
        }

        $block = substr($contents, $begin_pos, ($end_pos + strlen($end)) - $begin_pos);
        if (1 === preg_match('/^[ \t]*# UltraCache LiteSpeed Rules Schema:[ \t]*(\d+)[ \t]*$/mi', (string) $block, $matches)) {
            return max(1, (int) $matches[1]);
        }

        return 1;
    }

    /** Return the WooCommerce session cookie patterns eligible for LiteSpeed ESI shared-parent vary. */
    private static function get_litespeed_woocommerce_esi_cookie_patterns()
    {
        return array(
            'woocommerce_items_in_cart',
            'woocommerce_cart_hash',
            'wp_woocommerce_session_',
        );
    }

    /** Return the exact Apache-compatible WooCommerce session cookie matcher used by the ESI vary contract. */
    private static function get_litespeed_woocommerce_esi_cookie_rewrite_pattern()
    {
        return '(?:^|;[[:space:]]*)(?:woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_[^;=]*)=';
    }

    /** Whether native LiteSpeed WooCommerce ESI shared-parent vary rules may be emitted. */
    private static function is_litespeed_woocommerce_esi_shared_vary_enabled(array $settings)
    {
        $context = self::get_litespeed_rules_context($settings);
        return !empty($context['woocommerceEsiSharedVary']);
    }

    /**
     * Build an Apache-compatible unsafe-cookie bypass pattern.
     *
     * @param array $settings Normalized runtime settings.
     * @param array $excluded_patterns Exact normalized patterns omitted from this bypass matcher.
     * @return string
     */
    private static function get_litespeed_cache_cookie_bypass_pattern(array $settings, array $excluded_patterns = array())
    {
        $patterns = !empty($settings['unsafe_cache_cookie_patterns']) && is_array($settings['unsafe_cache_cookie_patterns'])
            ? $settings['unsafe_cache_cookie_patterns']
            : array();
        $patterns = array_merge($patterns, self::get_default_unsafe_cache_cookie_patterns());
        $patterns = apply_filters('ultracache_cache_bypass_cookie_patterns', $patterns, $settings);

        $excluded = array();
        foreach ($excluded_patterns as $excluded_pattern) {
            $excluded_pattern = strtolower(trim((string) $excluded_pattern));
            $excluded_pattern = preg_replace('/[^a-z0-9_.*\-]/', '', $excluded_pattern);
            if (is_string($excluded_pattern) && '' !== $excluded_pattern) {
                $excluded[$excluded_pattern] = true;
            }
        }

        $parts = array();
        foreach (array_slice(array_values(array_unique(array_filter(array_map('strval', $patterns)))), 0, 64) as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            $pattern = preg_replace('/[^a-z0-9_.*\-]/', '', $pattern);
            if ('' === $pattern || '*' === $pattern || isset($excluded[$pattern])) {
                continue;
            }

            $quoted = preg_quote($pattern, '#');
            if (false !== strpos($pattern, '*')) {
                $name_pattern = str_replace('\\*', '[^;=]*', $quoted);
            } else {
                $name_pattern = '[^;=]*' . $quoted . '[^;=]*';
            }
            $parts[$name_pattern] = $name_pattern;
        }

        if (empty($parts)) {
            return '';
        }

        return '(?:^|;[[:space:]]*)(?:' . implode('|', array_values($parts)) . ')=';
    }

    /**
     * Build an Apache-compatible dynamic-path bypass pattern.
     *
     * @param array $settings Normalized runtime settings.
     * @return string
     */
    private static function get_litespeed_cache_path_bypass_pattern(array $settings)
    {
        if (function_exists('ultracache_woocommerce_endpoint_contract_requires_fail_closed')
            && ultracache_woocommerce_endpoint_contract_requires_fail_closed()
        ) {
            return '^/';
        }

        $paths = !empty($settings['excluded_paths']) && is_array($settings['excluded_paths'])
            ? $settings['excluded_paths']
            : array();
        $paths = array_merge($paths, array(
            function_exists('ultracache_wordpress_admin_public_path') ? ultracache_wordpress_admin_public_path() : '/wp-admin/',
            '/wp-login.php',
            '/wp-json/',
            '/xmlrpc.php',
            '/wp-cron.php',
            '/wp-comments-post.php',
            '/admin-ajax.php',
            '/wc-api/',
        ));
        if (function_exists('ultracache_get_woocommerce_dynamic_paths')) {
            $paths = array_merge($paths, ultracache_get_woocommerce_dynamic_paths());
        }

        $parts = array();
        foreach (array_slice(array_values(array_unique(array_filter(array_map('strval', $paths)))), 0, 96) as $path) {
            $path = (string) wp_parse_url(trim((string) $path), PHP_URL_PATH);
            $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
            if ('' === $path) {
                continue;
            }
            if ('/' === $path) {
                $parts['root'] = '/$';
                continue;
            }
            $path = rtrim($path, '/');
            $quoted = preg_quote($path, '#');
            $parts[$quoted] = $quoted . '(?:/|$)';
        }

        if (empty($parts)) {
            return '';
        }

        return '^(?:' . implode('|', array_values($parts)) . ')';
    }

    /**
     * Return the shared Apache/LiteSpeed Accept conditions for one image bucket.
     *
     * These rules mirror ultracache_get_html_variant_bucket_for_accept(), including
     * the explicit q=0 refusal rule and AVIF-before-WebP server preference.
     *
     * @param string $bucket HTML image bucket.
     * @return array<int,string>
     */
    private static function get_html_variant_accept_rewrite_conditions($bucket)
    {
        $bucket = in_array((string) $bucket, array('avif', 'webp'), true) ? (string) $bucket : '';
        if ('' === $bucket) {
            return array();
        }

        return array(
            'RewriteCond %{HTTP:Accept} "(^|,)[[:space:]]*image/' . $bucket . '([[:space:]]*;[^,]*)?([[:space:]]*,|$)" [NC]',
            'RewriteCond %{HTTP:Accept} "!(^|,)[[:space:]]*image/' . $bucket . '[[:space:]]*;[^,]*q[[:space:]]*=[[:space:]]*0(\.0+)?([[:space:]]*(;|,|$))" [NC]',
        );
    }

    /**
     * Build the managed native LiteSpeed lookup, bypass, and vary block.
     *
     * @return string
     */
    private static function get_litespeed_cache_htaccess_block()
    {
        $settings = self::get_settings();
        $variant_policy = ultracache_get_html_variant_policy($settings);
        $active_html_buckets = (array) ($variant_policy['buckets'] ?? array('orig'));
        $woocommerce_esi_shared_vary = self::is_litespeed_woocommerce_esi_shared_vary_enabled($settings);
        $woocommerce_esi_cookie_patterns = $woocommerce_esi_shared_vary
            ? self::get_litespeed_woocommerce_esi_cookie_patterns()
            : array();
        $cookie_pattern = self::get_litespeed_cache_cookie_bypass_pattern($settings, $woocommerce_esi_cookie_patterns);
        $woocommerce_esi_cookie_pattern = $woocommerce_esi_shared_vary
            ? self::get_litespeed_woocommerce_esi_cookie_rewrite_pattern()
            : '';
        $path_pattern = self::get_litespeed_cache_path_bypass_pattern($settings);
        $query_policy = method_exists(static::class, 'get_litespeed_query_cache_policy')
            ? self::get_litespeed_query_cache_policy()
            : array();
        $query_policy_fingerprint = (string) ($query_policy['fingerprint'] ?? '');
        $query_key_proof = function_exists('ultracache_build_litespeed_query_cache_key_proof')
            ? ultracache_build_litespeed_query_cache_key_proof($query_policy)
            : array();
        $query_key_proof_fingerprint = (string) ($query_key_proof['fingerprint'] ?? '');
        $query_key_proof_status = sanitize_key((string) ($query_key_proof['status'] ?? 'blocked'));

        $lines = array(
            '# UltraCache LiteSpeed Rules Schema: ' . (string) self::get_litespeed_cache_rules_schema_version(),
            '# Generated by UltraCache',
            '<IfModule LiteSpeed>',
            'CacheLookup public on',
            'RewriteEngine On',
            '# UltraCache native LSCache retrieval contract. Response headers decide storage and TTL.',
            '# UltraCache query policy fingerprint: ' . ('' !== $query_policy_fingerprint ? $query_policy_fingerprint : 'unavailable'),
            '# UltraCache query key proof fingerprint: ' . ('' !== $query_key_proof_fingerprint ? $query_key_proof_fingerprint : 'unavailable'),
            '# UltraCache query key proof status: ' . ('' !== $query_key_proof_status ? $query_key_proof_status : 'blocked'),
            '# Native LSCache query-key modifiers cannot represent UltraCache canonical key/value ordering.',
            '# Safe-query LSCache retrieval remains disabled.',
            '# Query URLs are bypassed and are never mapped to the base URL for native purge or refill.',
            'RewriteCond %{REQUEST_METHOD} !^(?:GET|HEAD)$ [NC]',
            'RewriteRule .* - [E=Cache-Control:no-cache,E=ULTRACACHE_LSCACHE_BYPASS:1]',
            'RewriteCond %{QUERY_STRING} !^$',
            'RewriteRule .* - [E=Cache-Control:no-cache,E=ULTRACACHE_LSCACHE_BYPASS:1]',
            'RewriteCond %{HTTP:Authorization} !^$',
            'RewriteRule .* - [E=Cache-Control:no-cache,E=ULTRACACHE_LSCACHE_BYPASS:1]',
        );

        if ($woocommerce_esi_shared_vary && '' !== $woocommerce_esi_cookie_pattern) {
            $lines[] = '# WooCommerce cart/session cookies use a shared LiteSpeed ESI vary bucket instead of blanket no-cache.';
            $lines[] = '# Other unsafe cookies and all dynamic WooCommerce URLs remain hard bypasses.';
            $lines[] = 'RewriteCond %{HTTP:Cookie} "' . $woocommerce_esi_cookie_pattern . '" [NC]';
            $lines[] = 'RewriteRule .* - [E=ULTRACACHE_LSCACHE_WOO_ESI:1]';
        }

        if ('' !== $cookie_pattern) {
            $lines[] = 'RewriteCond %{HTTP:Cookie} "' . $cookie_pattern . '" [NC]';
            $lines[] = 'RewriteRule .* - [E=Cache-Control:no-cache,E=ULTRACACHE_LSCACHE_BYPASS:1]';
        }
        if ('' !== $path_pattern) {
            $lines[] = 'RewriteCond %{REQUEST_URI} "' . $path_pattern . '" [NC]';
            $lines[] = 'RewriteRule .* - [E=Cache-Control:no-cache,E=ULTRACACHE_LSCACHE_BYPASS:1]';
        }

        $lines = array_merge($lines, array(
            'RewriteCond %{REQUEST_URI} \.[A-Za-z0-9]{2,8}$ [NC]',
            'RewriteRule .* - [E=Cache-Control:no-cache,E=ULTRACACHE_LSCACHE_BYPASS:1]',
        ));

        if (!empty($variant_policy['vary_accept'])) {
            $avif_accept_conditions = self::get_html_variant_accept_rewrite_conditions('avif');
            $webp_accept_conditions = self::get_html_variant_accept_rewrite_conditions('webp');

            $lines[] = 'RewriteCond %{ENV:ULTRACACHE_LSCACHE_BYPASS} !^1$';
            if ($woocommerce_esi_shared_vary) {
                $lines[] = 'RewriteCond %{ENV:ULTRACACHE_LSCACHE_WOO_ESI} !^1$';
            }
            $lines[] = 'RewriteRule .* - [E=Cache-Control:vary=uc_orig]';

            if ($woocommerce_esi_shared_vary) {
                $lines[] = 'RewriteCond %{ENV:ULTRACACHE_LSCACHE_BYPASS} !^1$';
                $lines[] = 'RewriteCond %{ENV:ULTRACACHE_LSCACHE_WOO_ESI} ^1$';
                $lines[] = 'RewriteRule .* - [E=Cache-Control:vary=uc_woo_esi_orig]';
            }

            if (in_array('webp', $active_html_buckets, true)) {
                $lines[] = 'RewriteCond %{ENV:ULTRACACHE_LSCACHE_BYPASS} !^1$';
                if ($woocommerce_esi_shared_vary) {
                    $lines[] = 'RewriteCond %{ENV:ULTRACACHE_LSCACHE_WOO_ESI} !^1$';
                }
                foreach ($webp_accept_conditions as $condition) {
                    $lines[] = $condition;
                }
                $lines[] = 'RewriteRule .* - [E=Cache-Control:vary=uc_webp]';

                if ($woocommerce_esi_shared_vary) {
                    $lines[] = 'RewriteCond %{ENV:ULTRACACHE_LSCACHE_BYPASS} !^1$';
                    $lines[] = 'RewriteCond %{ENV:ULTRACACHE_LSCACHE_WOO_ESI} ^1$';
                    foreach ($webp_accept_conditions as $condition) {
                        $lines[] = $condition;
                    }
                    $lines[] = 'RewriteRule .* - [E=Cache-Control:vary=uc_woo_esi_webp]';
                }
            }
            if (in_array('avif', $active_html_buckets, true)) {
                $lines[] = 'RewriteCond %{ENV:ULTRACACHE_LSCACHE_BYPASS} !^1$';
                if ($woocommerce_esi_shared_vary) {
                    $lines[] = 'RewriteCond %{ENV:ULTRACACHE_LSCACHE_WOO_ESI} !^1$';
                }
                foreach ($avif_accept_conditions as $condition) {
                    $lines[] = $condition;
                }
                $lines[] = 'RewriteRule .* - [E=Cache-Control:vary=uc_avif]';

                if ($woocommerce_esi_shared_vary) {
                    $lines[] = 'RewriteCond %{ENV:ULTRACACHE_LSCACHE_BYPASS} !^1$';
                    $lines[] = 'RewriteCond %{ENV:ULTRACACHE_LSCACHE_WOO_ESI} ^1$';
                    foreach ($avif_accept_conditions as $condition) {
                        $lines[] = $condition;
                    }
                    $lines[] = 'RewriteRule .* - [E=Cache-Control:vary=uc_woo_esi_avif]';
                }
            }
        } elseif ($woocommerce_esi_shared_vary) {
            $lines[] = 'RewriteCond %{ENV:ULTRACACHE_LSCACHE_BYPASS} !^1$';
            $lines[] = 'RewriteCond %{ENV:ULTRACACHE_LSCACHE_WOO_ESI} ^1$';
            $lines[] = 'RewriteRule .* - [E=Cache-Control:vary=uc_woo_esi]';
        }

        $lines[] = '</IfModule>';

        return implode("\n", $lines) . "\n";
    }


    /** Return the exact managed LiteSpeed block, including BEGIN/END markers. */
    private static function get_litespeed_managed_block_from_contents($contents)
    {
        $contents = (string) $contents;
        $begin = '# BEGIN UltraCache LiteSpeed Cache';
        $end = '# END UltraCache LiteSpeed Cache';
        $begin_pos = strpos($contents, $begin);
        $end_pos = false !== $begin_pos ? strpos($contents, $end, $begin_pos + strlen($begin)) : false;
        if (false === $begin_pos || false === $end_pos) {
            return '';
        }
        return substr($contents, $begin_pos, ($end_pos + strlen($end)) - $begin_pos);
    }

    /** Return a stable hash for a managed LiteSpeed block. */
    private static function get_litespeed_rules_hash($block)
    {
        $block = trim((string) $block);
        return '' === $block ? '' : hash('sha256', $block);
    }

    /**
     * Upgrade an existing managed LiteSpeed rules block to the current schema.
     *
     * Legacy blocks without a schema comment are treated as schema 1. Newer
     * schemas are left untouched so a rolled-back plugin cannot overwrite rules
     * written by a later UltraCache release.
     *
     * @return array<string,mixed>
     */
    public static function maybe_sync_litespeed_cache_rules_schema()
    {
        $settings = self::get_settings();
        $enabled = (!empty($settings['litespeed_cache_enabled']) || !empty($settings['liteSpeedCacheEnabled']))
            && (!empty($settings['enabled']) || !empty($settings['pageCacheEnabled']));
        if (!$enabled) {
            return array('success' => true, 'skipped' => true, 'reason' => 'litespeed_disabled');
        }

        $path = self::get_browser_cache_htaccess_path();
        $contents = file_exists($path)
            ? (string) ultracache_safe_file_get_contents($path, 'sync_litespeed_cache_rules')
            : '';
        $current_schema = self::get_litespeed_cache_rules_schema_version();
        $existing_schema = self::get_litespeed_cache_rules_schema_from_contents($contents);

        if ($existing_schema > $current_schema) {
            self::persist_litespeed_rules_state(
                'newer_schema',
                '',
                '',
                self::get_litespeed_rules_hash(self::get_litespeed_managed_block_from_contents($contents)),
                'newer_schema_present'
            );
            return array(
                'success' => true,
                'skipped' => true,
                'reason' => 'newer_schema_present',
                'existingSchema' => $existing_schema,
                'currentSchema' => $current_schema,
            );
        }

        if ($existing_schema === $current_schema) {
            return array(
                'success' => true,
                'skipped' => true,
                'reason' => 'already_current',
                'existingSchema' => $existing_schema,
                'currentSchema' => $current_schema,
            );
        }

        if (method_exists(__CLASS__, 'refresh_litespeed_esi_capability_from_persisted_detection')) {
            self::refresh_litespeed_esi_capability_from_persisted_detection('rules-schema-upgrade');
        }
        $synced = self::sync_litespeed_cache_rules(true);
        return array(
            'success' => (bool) $synced,
            'skipped' => false,
            'reason' => $synced ? 'schema_upgraded' : 'server_rules_sync_failed',
            'existingSchema' => $existing_schema,
            'currentSchema' => $current_schema,
        );
    }

    /**
     * Synchronize the managed LiteSpeed cache block in the root .htaccess.
     *
     * This method is invoked only by lifecycle/admin events. Frontend visitors
     * never trigger LiteSpeed capability detection or .htaccess regeneration.
     *
     * @param bool|null $enabled Optional explicit desired state.
     * @return bool
     */
    public static function sync_litespeed_cache_rules($enabled = null)
    {
        $begin = '# BEGIN UltraCache LiteSpeed Cache';
        $end = '# END UltraCache LiteSpeed Cache';
        $settings = self::get_settings();

        if (null === $enabled) {
            $enabled = (!empty($settings['litespeed_cache_enabled']) || !empty($settings['liteSpeedCacheEnabled']))
                && (!empty($settings['enabled']) || !empty($settings['pageCacheEnabled']));
        }
        $enabled = (bool) $enabled;

        $context = self::get_litespeed_rules_context($settings);
        $context_hash = (string) ($context['hash'] ?? '');
        $block_body = $enabled ? self::get_litespeed_cache_htaccess_block() : '';
        if ($enabled && '' === $block_body) {
            self::persist_litespeed_rules_state('pending', $context_hash, '', '', 'empty_rules_block');
            return false;
        }
        $desired_block = $enabled ? ($begin . "\n" . $block_body . $end) : '';
        $desired_rules_hash = self::get_litespeed_rules_hash($desired_block);

        $path = self::get_browser_cache_htaccess_path();
        $original_exists = file_exists($path);
        $contents = $original_exists ? (string) ultracache_safe_file_get_contents($path, 'sync_litespeed_cache_rules') : '';
        $existing_block = self::get_litespeed_managed_block_from_contents($contents);
        $has_block = '' !== $existing_block;
        $existing_rules_hash = self::get_litespeed_rules_hash($existing_block);
        $existing_schema = self::get_litespeed_cache_rules_schema_from_contents($contents);
        $current_schema = self::get_litespeed_cache_rules_schema_version();

        if ($has_block && $existing_schema > $current_schema) {
            self::persist_litespeed_rules_state('newer_schema', $context_hash, $desired_rules_hash, $existing_rules_hash, 'newer_schema_present');
            return true;
        }

        if (($enabled && $has_block && $existing_schema === $current_schema && hash_equals($desired_rules_hash, $existing_rules_hash))
            || (!$enabled && !$has_block)
        ) {
            self::persist_litespeed_rules_state(
                $enabled ? 'applied' : 'disabled',
                $context_hash,
                $desired_rules_hash,
                $existing_rules_hash,
                $enabled ? 'already_applied' : 'already_disabled'
            );
            return true;
        }

        if ($original_exists && !ultracache_path_is_writable($path)) {
            self::persist_litespeed_rules_state('pending', $context_hash, $desired_rules_hash, $existing_rules_hash, 'htaccess_not_writable');
            return false;
        }

        $pattern = '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\\R*/s';
        $updated = (string) preg_replace($pattern, '', $contents);
        $updated = ltrim($updated);

        if ($enabled) {
            $updated = '' === trim($updated) ? $desired_block : ($desired_block . "\n\n" . rtrim($updated));
        }

        $updated = '' === trim($updated) ? '' : (rtrim($updated) . "\n");
        if ($updated === $contents) {
            self::persist_litespeed_rules_state(
                $enabled ? 'applied' : 'disabled',
                $context_hash,
                $desired_rules_hash,
                $enabled ? $desired_rules_hash : '',
                'contents_unchanged'
            );
            return true;
        }

        $dir = dirname($path);
        if (!file_exists($dir) && !ultracache_safe_mkdir($dir, 0755, true, 'sync_litespeed_cache_rules') && !file_exists($dir)) {
            self::persist_litespeed_rules_state('pending', $context_hash, $desired_rules_hash, $existing_rules_hash, 'rules_directory_unavailable');
            return false;
        }

        $written = self::write_htaccess_rules_with_verification($path, $updated, $contents, $original_exists, 'sync_litespeed_cache_rules');
        if (!$written) {
            self::persist_litespeed_rules_state('pending', $context_hash, $desired_rules_hash, $existing_rules_hash, 'write_verification_failed');
            return false;
        }

        self::persist_litespeed_rules_state(
            $enabled ? 'applied' : 'disabled',
            $context_hash,
            $desired_rules_hash,
            $enabled ? $desired_rules_hash : '',
            $enabled ? 'rules_written' : 'rules_removed'
        );
        return true;
    }

    /** Rebuild LiteSpeed managed rules when WooCommerce activation state changes. */
    public static function maybe_sync_litespeed_rules_for_plugin_state_change($plugin, $network_wide = false)
    {
        unset($network_wide);
        $plugin = plugin_basename((string) $plugin);
        if ('woocommerce/woocommerce.php' !== $plugin) {
            return;
        }

        self::sync_litespeed_cache_rules();
    }

    private static function get_apache_static_html_delivery_cache_public_path()
    {
        $url = function_exists('ultracache_content_cache_storage_url') ? ultracache_content_cache_storage_url('') : '';
        $path = is_string($url) ? (string) wp_parse_url($url, PHP_URL_PATH) : '';
        if ('' === $path) {
            return '';
        }

        return trailingslashit('/' . ltrim(str_replace('\\', '/', rawurldecode($path)), '/'));
    }

    private static function get_apache_static_html_delivery_htaccess_block()
    {
        $cache_path = self::get_apache_static_html_delivery_cache_public_path();
        if ('' === $cache_path) {
            return '';
        }

        $settings = self::get_settings();
        if (!empty($settings['litespeed_cache_enabled']) || !empty($settings['liteSpeedCacheEnabled'])) {
            // LiteSpeed semantic tags are per cached URL and are emitted by the
            // advanced-cache drop-in from the page's semantic sidecar. A direct
            // static alias cannot read that WordPress metadata, so LSCache MISS
            // requests intentionally use the early PHP cache hit path instead.
            return '';
        }

        $trusted_hosts = array_values(array_unique(array_filter(array_map('ultracache_normalize_host', ultracache_get_trusted_hosts()))));
        $host_targets = array();
        foreach ($trusted_hosts as $trusted_host) {
            $host_folder = preg_replace('#[^a-z0-9._-]#', '-', strtolower((string) $trusted_host));
            if ('' === $trusted_host || '' === $host_folder) {
                continue;
            }
            $host_targets[] = array(
                'condition' => 'RewriteCond %{HTTP_HOST} ^' . preg_quote($trusted_host, '#') . '(?::[0-9]+)?$ [NC]',
                'folder'    => $host_folder,
            );
        }
        if (empty($host_targets)) {
            return '';
        }

        $gzip_enabled = !empty($settings['gzip_enabled']);
        $brotli_enabled = !empty($settings['brotli_enabled']);
        $variant_policy = ultracache_get_html_variant_policy($settings);
        $active_html_buckets = (array) $variant_policy['buckets'];
        $vary_accept = !empty($variant_policy['vary_accept']);
        $send_variant_header = !empty($settings['debug_headers_enabled']) || !empty($settings['shared_cache_delivery_enabled']) || !empty($settings['varnish_cli_enabled']);
        $litespeed_cache_enabled = !empty($settings['litespeed_cache_enabled']) && !empty($settings['enabled']);
        $litespeed_html_ttl_seconds = $litespeed_cache_enabled
            ? max(1, min(525600, absint($settings['cache_fresh_ttl_minutes'] ?? 1440))) * MINUTE_IN_SECONDS
            : 0;
        $litespeed_site_tag = $litespeed_cache_enabled && function_exists('ultracache_get_litespeed_site_tag')
            ? ultracache_get_litespeed_site_tag()
            : '';
        $shared_cache_proof_expires_at = absint($settings['shared_cache_control_proof_expires_at'] ?? 0);
        $static_managed_delivery = !empty($settings['shared_cache_control_verified'])
            && 0 === $shared_cache_proof_expires_at;
        $shared_html_ttl_minutes = !empty($settings['shared_cache_delivery_enabled'])
            ? ($static_managed_delivery
                ? max(1, min(525600, absint($settings['shared_cache_managed_ttl_minutes'] ?? 1440)))
                : max(1, min(1440, absint($settings['shared_cache_ttl_only_minutes'] ?? 10))))
            : 0;
        $shared_html_ttl_seconds = $shared_html_ttl_minutes * MINUTE_IN_SECONDS;
        $varnish_stale_while_revalidate_seconds = $shared_html_ttl_seconds > 0 && $static_managed_delivery
            ? max(0, min(86400, absint($settings['varnish_stale_while_revalidate_seconds'] ?? 0)))
            : 0;
        $static_path_bypass_pattern = self::get_litespeed_cache_path_bypass_pattern($settings);
        $html_cache_control = $shared_html_ttl_seconds > 0
            ? 'public, max-age=0, s-maxage=' . (string) $shared_html_ttl_seconds
                . ', stale-while-revalidate=' . (string) $varnish_stale_while_revalidate_seconds
            : 'public, max-age=0, must-revalidate';

        $common_conditions = array(
            'RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$ [NC]',
            'RewriteCond %{HTTP:X-UltraCache-Force-Refresh} !^(?:1|true)$ [NC]',
            'RewriteCond %{QUERY_STRING} ^$',
            'RewriteCond %{HTTP:Cookie} !(wordpress_logged_in_|wordpress_sec_|wp-postpass_|comment_author_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_) [NC]',
            '' !== $static_path_bypass_pattern ? 'RewriteCond %{REQUEST_URI} !' . $static_path_bypass_pattern . ' [NC]' : '',
            'RewriteCond %{REQUEST_URI} !\.[A-Za-z0-9]{2,8}$ [NC]',
            'RewriteCond %{HTTP:Accept} (text/html|\*/\*) [NC]',
        );

        $lines = array(
            '<FilesMatch "^index-(orig|webp|avif)\.html(?:\.(?:gz|br))?$">',
            'FileETag MTime Size',
            '</FilesMatch>',
            '<IfModule mod_headers.c>',
            '<FilesMatch "^index-(orig|webp|avif)\.html$">',
            'Header set Cache-Control "' . $html_cache_control . '"',
            'Header set X-Ultra-Cache-Source "apache-static"',
            'Header set X-UltraCache-Encoding "identity"',
            $litespeed_html_ttl_seconds > 0 ? 'Header set X-LiteSpeed-Cache-Control "public,max-age=' . (string) $litespeed_html_ttl_seconds . '"' : '',
            '' !== $litespeed_site_tag ? 'Header set X-LiteSpeed-Tag "' . $litespeed_site_tag . '"' : '',
            $shared_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Cacheable "1"' : '',
            $shared_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Surrogate-TTL "' . (string) $shared_html_ttl_seconds . '"' : '',
            $shared_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Stale-While-Revalidate "' . (string) $varnish_stale_while_revalidate_seconds . '"' : '',
                $shared_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Shared-Cache-Mode "' . ($static_managed_delivery ? 'managed' : 'ttl-only') . '"' : '',
        );
        if ($vary_accept) {
            $lines[] = 'Header merge Vary Accept';
        }
        $lines[] = 'Header merge Vary Accept-Encoding';
        $lines[] = '</FilesMatch>';

        if ($gzip_enabled) {
            $gzip_lines = array(
                '<FilesMatch "^index-(orig|webp|avif)\.html\.gz$">',
                'Header set Content-Type "text/html; charset=UTF-8"',
                'Header set Content-Encoding "gzip"',
                'Header set Cache-Control "' . $html_cache_control . '"',
                'Header set X-Ultra-Cache-Source "apache-static"',
                'Header set X-UltraCache-Encoding "gzip"',
                $litespeed_html_ttl_seconds > 0 ? 'Header set X-LiteSpeed-Cache-Control "public,max-age=' . (string) $litespeed_html_ttl_seconds . '"' : '',
                '' !== $litespeed_site_tag ? 'Header set X-LiteSpeed-Tag "' . $litespeed_site_tag . '"' : '',
                $shared_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Cacheable "1"' : '',
                $shared_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Surrogate-TTL "' . (string) $shared_html_ttl_seconds . '"' : '',
                $shared_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Stale-While-Revalidate "' . (string) $varnish_stale_while_revalidate_seconds . '"' : '',
                $shared_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Shared-Cache-Mode "' . ($static_managed_delivery ? 'managed' : 'ttl-only') . '"' : '',
            );
            if ($vary_accept) {
                $gzip_lines[] = 'Header merge Vary Accept';
            }
            $gzip_lines[] = 'Header merge Vary Accept-Encoding';
            $gzip_lines[] = '</FilesMatch>';
            $lines = array_merge($lines, $gzip_lines);
        }

        if ($brotli_enabled) {
            $brotli_lines = array(
                '<FilesMatch "^index-(orig|webp|avif)\.html\.br$">',
                'Header set Content-Type "text/html; charset=UTF-8"',
                'Header set Content-Encoding "br"',
                'Header set Cache-Control "' . $html_cache_control . '"',
                'Header set X-Ultra-Cache-Source "apache-static"',
                'Header set X-UltraCache-Encoding "brotli"',
                $litespeed_html_ttl_seconds > 0 ? 'Header set X-LiteSpeed-Cache-Control "public,max-age=' . (string) $litespeed_html_ttl_seconds . '"' : '',
                '' !== $litespeed_site_tag ? 'Header set X-LiteSpeed-Tag "' . $litespeed_site_tag . '"' : '',
                $shared_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Cacheable "1"' : '',
                $shared_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Surrogate-TTL "' . (string) $shared_html_ttl_seconds . '"' : '',
                $shared_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Stale-While-Revalidate "' . (string) $varnish_stale_while_revalidate_seconds . '"' : '',
                $shared_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Shared-Cache-Mode "' . ($static_managed_delivery ? 'managed' : 'ttl-only') . '"' : '',
            );
            if ($vary_accept) {
                $brotli_lines[] = 'Header merge Vary Accept';
            }
            $brotli_lines[] = 'Header merge Vary Accept-Encoding';
            $brotli_lines[] = '</FilesMatch>';
            $lines = array_merge($lines, $brotli_lines);
        }

        $lines = array_merge($lines, array(
            '<FilesMatch "^index-(orig|webp|avif)\.html(?:\.(?:gz|br))?$">',
            'Header always unset Content-Type "expr=%{REQUEST_STATUS} == 304"',
            'Header always unset Content-Encoding "expr=%{REQUEST_STATUS} == 304"',
            'Header always unset Content-Length "expr=%{REQUEST_STATUS} == 304"',
            '</FilesMatch>',
        ));

        $lines = array_values(array_filter($lines, static function ($line) {
            return '' !== (string) $line;
        }));

        if ($litespeed_html_ttl_seconds > 0 && $vary_accept) {
            foreach ($active_html_buckets as $active_html_bucket) {
                if (!in_array($active_html_bucket, array('orig', 'webp', 'avif'), true)) {
                    continue;
                }
                $vary_value = function_exists('ultracache_get_litespeed_vary_value_for_bucket')
                    ? ultracache_get_litespeed_vary_value_for_bucket($active_html_bucket)
                    : 'uc_orig';
                $lines[] = '<FilesMatch "^index-' . $active_html_bucket . '\.html(?:\.(?:gz|br))?$">';
                $lines[] = 'Header set X-LiteSpeed-Vary "value=' . $vary_value . '"';
                $lines[] = '</FilesMatch>';
            }
        }

        if ($send_variant_header) {
            foreach ($active_html_buckets as $active_html_bucket) {
                if (!in_array($active_html_bucket, array('orig', 'webp', 'avif'), true)) {
                    continue;
                }
                $lines[] = '<FilesMatch "^index-' . $active_html_bucket . '\.html(?:\.(?:gz|br))?$">';
                $lines[] = 'Header set X-UltraCache-Variant "' . $active_html_bucket . '"';
                $lines[] = '</FilesMatch>';
            }
        }

        $lines = array_merge($lines, array(
            '</IfModule>',
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
            '# UltraCache direct static HTML delivery: conservative queryless anonymous page-cache aliases.',
        ));

        $append_rule = static function (array $host_target, array $extra_conditions, $file, $rule_pattern) use (&$lines, $common_conditions, $cache_path) {
            foreach (array_merge($common_conditions, array($host_target['condition']), $extra_conditions) as $condition) {
                $lines[] = $condition;
            }
            $target = $cache_path . $host_target['folder'] . '/' . $file;
            $lines[] = 'RewriteCond %{DOCUMENT_ROOT}' . $target . ' -f';
            $lines[] = 'RewriteRule ' . $rule_pattern . ' ' . $target . ' [L]';
        };

        $bucket_rules = array();
        $avif_accept_conditions = self::get_html_variant_accept_rewrite_conditions('avif');
        $webp_accept_conditions = self::get_html_variant_accept_rewrite_conditions('webp');
        if (in_array('avif', $active_html_buckets, true)) {
            $bucket_rules[] = array($avif_accept_conditions, 'index/index-avif.html', '^$');
        }
        if (in_array('webp', $active_html_buckets, true)) {
            $bucket_rules[] = array($webp_accept_conditions, 'index/index-webp.html', '^$');
        }
        $bucket_rules[] = array(array(), 'index/index-orig.html', '^$');
        if (in_array('avif', $active_html_buckets, true)) {
            $bucket_rules[] = array($avif_accept_conditions, '$1/index-avif.html', '^(.+?)/?$');
        }
        if (in_array('webp', $active_html_buckets, true)) {
            $bucket_rules[] = array($webp_accept_conditions, '$1/index-webp.html', '^(.+?)/?$');
        }
        $bucket_rules[] = array(array(), '$1/index-orig.html', '^(.+?)/?$');

        if ($brotli_enabled || $gzip_enabled) {
            $lines[] = '<IfModule mod_headers.c>';
            $explicit_encoding_quality_condition = 'RewriteCond %{HTTP:Accept-Encoding} "!(^|,)[[:space:]]*(br|gzip)[[:space:]]*;[^,]*q[[:space:]]*=" [NC]';
            $brotli_accept_condition = 'RewriteCond %{HTTP:Accept-Encoding} "(^|,)[[:space:]]*br([[:space:]]*;[^,]*)?([[:space:]]*,|$)" [NC]';
            $gzip_accept_condition = 'RewriteCond %{HTTP:Accept-Encoding} "(^|,)[[:space:]]*gzip([[:space:]]*;[^,]*)?([[:space:]]*,|$)" [NC]';
            foreach ($host_targets as $host_target) {
                foreach ($bucket_rules as $bucket_rule) {
                    list($bucket_conditions, $file, $rule_pattern) = $bucket_rule;

                    if ($brotli_enabled) {
                        $append_rule(
                            $host_target,
                            array_merge(
                                $bucket_conditions,
                                array(
                                    $brotli_accept_condition,
                                    $explicit_encoding_quality_condition,
                                )
                            ),
                            $file . '.br',
                            $rule_pattern
                        );
                    }

                    if ($gzip_enabled) {
                        $append_rule(
                            $host_target,
                            array_merge(
                                $bucket_conditions,
                                array(
                                    $gzip_accept_condition,
                                    $explicit_encoding_quality_condition,
                                )
                            ),
                            $file . '.gz',
                            $rule_pattern
                        );
                    }
                }
            }
            $lines[] = '</IfModule>';
        }

        foreach ($host_targets as $host_target) {
            foreach ($bucket_rules as $bucket_rule) {
                list($bucket_conditions, $file, $rule_pattern) = $bucket_rule;
                $append_rule($host_target, $bucket_conditions, $file, $rule_pattern);
            }
        }

        $lines[] = '</IfModule>';

        return implode("\n", $lines) . "\n";
    }

    public static function sync_apache_static_html_delivery_rules($enabled = null)
    {
        $begin = '# BEGIN UltraCache Apache Static HTML';
        $end   = '# END UltraCache Apache Static HTML';

        $settings = self::get_settings();
        if (null === $enabled) {
            $enabled = !empty($settings['apache_static_html_delivery']) && !empty($settings['enabled']);
        }

        // Native LiteSpeed HTML Cache must own the outer static response path
        // while semantic tags are active. The advanced-cache drop-in needs to
        // read the per-page .lstags sidecar on an LSCache MISS, so an existing
        // Apache Static HTML block is intentionally removed rather than treated
        // as a rule-generation failure.
        if (!empty($settings['litespeed_cache_enabled']) || !empty($settings['liteSpeedCacheEnabled'])) {
            $enabled = false;
        }

        $path = self::get_browser_cache_htaccess_path();
        $original_exists = file_exists($path);
        $contents = $original_exists ? (string) ultracache_safe_file_get_contents($path, 'sync_apache_static_html_delivery_rules') : '';
        $has_block = (false !== strpos($contents, $begin) && false !== strpos($contents, $end));

        if ($original_exists && !ultracache_path_is_writable($path)) {
            return !$enabled && !$has_block;
        }
        $pattern = '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\R*/s';
        $updated = (string) preg_replace($pattern, '', $contents);
        $updated = rtrim($updated);

        if ($enabled) {
            $block_body = self::get_apache_static_html_delivery_htaccess_block();
            if ('' === $block_body) {
                return false;
            }
            $block = $begin . "\n" . $block_body . "\n" . $end;
            $updated = '' === $updated ? $block : ($updated . "\n\n" . $block);
        }

        $updated = '' === trim($updated) ? '' : (rtrim($updated) . "\n");

        if ($updated === $contents) {
            return true;
        }

        $dir = dirname($path);
        if (!file_exists($dir) && !ultracache_safe_mkdir($dir, 0755, true, 'sync_apache_static_html_delivery_rules') && !file_exists($dir)) {
            return false;
        }

        return self::write_htaccess_rules_with_verification($path, $updated, $contents, $original_exists, 'sync_apache_static_html_delivery_rules');
    }

    private static function get_engine_class()
    {
        $candidates = array('Ultra_Cache_Engine');
        foreach ($candidates as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    public static function sync_page_cache_bootstrap($enabled = null, $update_wp_config = true)
    {
        if (null === $enabled) {
            $settings = self::get_dashboard_settings();
            $enabled = !empty($settings['pageCacheEnabled']);
        }

        $enabled = (bool) $enabled;

        if ($update_wp_config) {
            $result = self::set_wp_cache_flag($enabled);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        $engine_class = self::get_engine_class();
        if (!$engine_class) {
            return true;
        }

        if ($enabled) {
            if (method_exists($engine_class, 'setup_advanced_cache')) {
                $setup_result = $engine_class::setup_advanced_cache();
                if (is_wp_error($setup_result)) {
                    return $setup_result;
                }
            }
            if (method_exists($engine_class, 'get_advanced_cache_dropin_status')) {
                $dropin_status = $engine_class::get_advanced_cache_dropin_status();
                if (empty($dropin_status['healthy'])) {
                    $reason = isset($dropin_status['reason']) ? sanitize_key((string) $dropin_status['reason']) : 'verification_failed';
                    return new WP_Error(
                        'ultracache_advanced_cache_dropin_sync_failed',
                        sprintf(
                            /* translators: %s: advanced-cache.php verification reason. */
                            self::maybe_translate('Page Cache could not be enabled because UltraCache could not install and verify wp-content/advanced-cache.php (%s). Check wp-content permissions and retry.'),
                            $reason
                        )
                    );
                }
            }
        } elseif (method_exists($engine_class, 'maybe_remove_advanced_cache')) {
            $engine_class::maybe_remove_advanced_cache();
        }

        return true;
    }
}
