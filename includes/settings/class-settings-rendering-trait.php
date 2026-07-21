<?php
/**
 * Dashboard/client settings presentation and runtime mapping.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Settings_Rendering_Trait
{


    public static function get_crawl_scope_summary($settings_override = null)
    {
        $fallback = array(
            'baseUrlCount' => 0,
            'menuUrlCount' => 0,
            'seedUrlCount' => 0,
            'postUrlCount' => 0,
            'termUrlCount' => 0,
            'contentUrlCount' => 0,
            'estimatedTotal' => 0,
            'maxUrls' => 5000,
            'defaultScheduledWarmLimit' => 9,
            'suggestedScheduledWarmLimit' => 0,
            'scheduledWarmLimitDerived' => false,
            'scheduledWarmLimitSource' => 'user_cap',
            'sourceCounts' => array(),
            'sourceBreakdown' => array(),
            'sourceOrder' => self::get_full_site_warm_source_order_keys(),
            'generatedAt' => 0,
            'storedAt' => 0,
            'menuOptions' => array(),
            'menuDepthOptions' => array(
                array('value' => '', 'label' => __('Select depth', 'ultracache')),
                array('value' => '1', 'label' => __('Depth 1', 'ultracache')),
                array('value' => '2', 'label' => __('Depth 2', 'ultracache')),
                array('value' => '3', 'label' => __('Depth 3', 'ultracache')),
                array('value' => 'all', 'label' => __('All depths', 'ultracache')),
            ),
            'fullSiteSourceOptions' => array(),
            'selectedMenuLocation' => '',
            'selectedMenuDepth' => '',
            'selectedFullSiteSources' => array(),
        );

        if (!function_exists('home_url')) {
            return $fallback;
        }

        $engine = self::get_engine_instance();
        if ($engine) {
            $summary = null;
            if (is_array($settings_override) && method_exists($engine, 'get_crawl_scope_summary_for_settings')) {
                $summary = $engine->get_crawl_scope_summary_for_settings($settings_override);
            } elseif (method_exists($engine, 'get_crawl_scope_summary')) {
                $summary = $engine->get_crawl_scope_summary();
            }

            if (is_array($summary)) {
                return array_merge($fallback, $summary);
            }
        }

        return $fallback;
    }



    private static function get_redis_support_status()
    {
        $available = class_exists('Redis') || extension_loaded('redis');
        $message = '';

        if (!$available) {
            $message = self::maybe_translate('PHP Redis extension is not loaded on this server.');
        }

        return array(
            'available' => $available,
            'message'   => $message,
        );
    }



    private static function get_varnish_endpoint_diagnostics($servers, $mode = 'http')
    {
        $mode = self::sanitize_varnish_mode($mode);
        $servers = self::sanitize_varnish_servers_string($servers, $mode);
        $items = array_values(array_filter(array_map('trim', preg_split('/\s+/', (string) $servers))));
        $messages = array();
        $unsafe = false;

        if ('http' === $mode) {
            foreach ($items as $server) {
                $check = self::validate_varnish_http_endpoint($server);
                if (empty($check['valid'])) {
                    $unsafe = true;
                    $messages[] = (string) ($check['message'] ?? 'Invalid or unsafe Varnish HTTP endpoint.');
                }
            }
        }

        $messages = array_values(array_unique(array_filter($messages)));

        return array(
            'unsafe'       => $unsafe,
            'messages'     => $messages,
            'suggestedPorts' => self::get_varnish_http_allowed_ports(),
            'allowedPorts' => self::get_varnish_http_allowed_ports(), // Backward-compatible alias for suggested/common ports; custom ports are allowed.
            'customPortsAllowed' => true,
            'externalConfiguredEndpointsAllowed' => true,
        );
    }



    public static function reset_settings_cache()
    {
        self::$dashboard_settings_cache = null;
        self::$settings_cache = null;
        delete_transient('ultracache_frontend_compression_probe_v1');
        delete_transient('ultracache_object_cache_support_status_v1');
        delete_transient('ultracache_media_support_status_v4');
        delete_transient('ultracache_imagick_avif_alpha_probe_v1');
        delete_transient('ultracache_gd_avif_alpha_probe_v3');
        delete_transient('ultracache_gd_webp_encode_probe_v2');
        delete_transient('ultracache_media_queue_init_maintenance_v1');
        ultracache_reset_loopback_ssl_status();

        if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'reset_settings_cache')) {
            Ultra_Cache_Object_Cache_Manager::reset_settings_cache();
        }
    }



    public static function get_dashboard_settings()
    {
        ultracache_request_profile_checkpoint('dashboard_settings_start');
        if (null !== self::$dashboard_settings_cache) {
            ultracache_request_profile_checkpoint('dashboard_settings_cache_hit');
            return self::$dashboard_settings_cache;
        }

        ultracache_request_profile_checkpoint('dashboard_settings_before_get_option');
        $saved = get_option(ULTRACACHE_SETTINGS_KEY, array());
        ultracache_request_profile_checkpoint('dashboard_settings_after_get_option', array(
            'raw_is_array' => is_array($saved) ? 'true' : 'false',
            'raw_count' => is_array($saved) ? count($saved) : 0,
        ));
        $raw_saved = is_array($saved) ? $saved : array();
        ultracache_request_profile_checkpoint('dashboard_settings_before_sanitize');
        $sanitized = self::sanitize_dashboard_settings($raw_saved);
        // Password values are never loaded into dashboard settings. They are
        // read only from wp-config.php constants by runtime consumers.
        $sanitized['redisPassword'] = '';
        $sanitized['varnishCliKey'] = '';
        ultracache_request_profile_checkpoint('dashboard_settings_after_sanitize', array(
            'sanitized_count' => is_array($sanitized) ? count($sanitized) : 0,
        ));

        // Dashboard settings reads are intentionally side-effect free.
        // Canonical storage cleanup happens on explicit saves/migrations only,
        // so status polling cannot turn into wp_options write traffic.
        ultracache_request_profile_checkpoint('dashboard_settings_read_only_canonical_skip');

        self::$dashboard_settings_cache = $sanitized;

        ultracache_request_profile_checkpoint('dashboard_settings_end');
        return self::$dashboard_settings_cache;
    }



    private static function get_secret_setting_keys()
    {
        return array(
            'redisPassword',
            'varnishCliKey',
        );
    }



    private static function get_secret_configuration_flag_map()
    {
        return array(
            'redisPassword' => 'redisPasswordConfigured',
            'varnishCliKey' => 'varnishCliKeyConfigured',
        );
    }



    private static function merge_protected_dashboard_settings(array $incoming, array $existing)
    {
        $merged = $incoming;
        $flag_map = self::get_secret_configuration_flag_map();

        foreach (self::get_secret_setting_keys() as $key) {
            $clear_flag = 'clear' . ucfirst($key);
            $should_clear = !empty($incoming[$clear_flag]);

            if ($should_clear) {
                $merged[$key] = '';
                continue;
            }

            if (!array_key_exists($key, $incoming)) {
                if (array_key_exists($key, $existing)) {
                    $merged[$key] = $existing[$key];
                }
                continue;
            }

            if ('' === trim((string) $incoming[$key]) && array_key_exists($key, $existing) && '' !== trim((string) $existing[$key])) {
                $merged[$key] = $existing[$key];
            }
        }

        return $merged;
    }



    private static function get_runtime_dashboard_settings()
    {
        $saved = get_option(ULTRACACHE_SETTINGS_KEY, array());
        $raw_saved = is_array($saved) ? $saved : array();
        $sanitized = self::sanitize_dashboard_settings($raw_saved, false);

        // Runtime consumers read secrets from wp-config.php constants only.
        $sanitized['redisPassword'] = function_exists('ultracache_get_redis_password') ? ultracache_get_redis_password() : '';
        $sanitized['varnishCliKey'] = function_exists('ultracache_get_varnish_password') ? ultracache_get_varnish_password() : '';

        return $sanitized;
    }



    public static function get_dashboard_settings_for_client()
    {
        $settings = self::get_dashboard_settings();
        $statuses = self::get_wp_config_secret_statuses();

        $redis = isset($statuses['redis']) && is_array($statuses['redis']) ? $statuses['redis'] : array();
        $varnish = isset($statuses['varnish']) && is_array($statuses['varnish']) ? $statuses['varnish'] : array();

        $settings['redisPassword'] = '';
        $settings['redisPasswordConfigured'] = !empty($redis['configured']);
        $settings['redisPasswordManaged'] = !empty($redis['managed']);
        $settings['redisPasswordExternal'] = !empty($redis['external']);

        $settings['varnishCliKey'] = '';
        $settings['varnishCliKeyConfigured'] = !empty($varnish['configured']);
        $settings['varnishCliKeyManaged'] = !empty($varnish['managed']);
        $settings['varnishCliKeyExternal'] = !empty($varnish['external']);

        return $settings;
    }



    public static function get_dashboard_defaults_for_client()
    {
        $settings = self::sanitize_dashboard_settings(self::get_dashboard_defaults());
        $settings['redisPassword'] = '';
        $settings['redisPasswordConfigured'] = false;
        $settings['redisPasswordManaged'] = false;
        $settings['redisPasswordExternal'] = false;
        $settings['varnishCliKey'] = '';
        $settings['varnishCliKeyConfigured'] = false;
        $settings['varnishCliKeyManaged'] = false;
        $settings['varnishCliKeyExternal'] = false;

        return $settings;
    }



    public static function get_settings()
    {
        ultracache_request_profile_settings_checkpoint('wp_get_settings_start');
        if (null !== self::$settings_cache) {
            ultracache_request_profile_settings_checkpoint('wp_get_settings_cache_hit');
            return self::$settings_cache;
        }

        ultracache_request_profile_settings_checkpoint('wp_get_settings_before_runtime_dashboard_settings');
        $ui = self::get_runtime_dashboard_settings();
        ultracache_request_profile_settings_checkpoint('wp_get_settings_after_runtime_dashboard_settings', array(
            'dashboard_settings_count' => is_array($ui) ? count($ui) : 0,
        ));

        ultracache_request_profile_settings_checkpoint('wp_get_settings_before_runtime_textareas');
        $excluded_paths = self::parse_textarea_setting($ui['cacheExceptionPaths']);
        if (empty($excluded_paths)) {
            $excluded_paths = self::get_default_excluded_paths();
        }

        $excluded_query_args = self::parse_textarea_setting($ui['cacheExceptionQueryArgs']);
        if (empty($excluded_query_args)) {
            $excluded_query_args = self::get_default_excluded_query_args();
        }

        $query_allowlist = self::parse_textarea_setting(self::sanitize_setting_key_list($ui['cacheQueryStringAllowlist']));
        $safe_tracking_cookie_patterns = self::parse_textarea_setting(self::sanitize_cookie_pattern_setting($ui['safeTrackingCookieList'] ?? ''));
        $unsafe_cache_cookie_patterns = self::parse_textarea_setting(self::sanitize_cookie_pattern_setting($ui['unsafeCacheCookieList'] ?? ''));
        ultracache_request_profile_settings_checkpoint('wp_get_settings_after_runtime_textareas', array(
            'excluded_paths_count' => count($excluded_paths),
            'excluded_query_args_count' => count($excluded_query_args),
            'query_allowlist_count' => count($query_allowlist),
            'safe_tracking_cookie_count' => count($safe_tracking_cookie_patterns),
            'unsafe_cache_cookie_count' => count($unsafe_cache_cookie_patterns),
        ));
        $defer_js_enabled = !empty($ui['deferJsEnabled']);
        $defer_all_js_enabled = false;
        $delay_all_js_enabled = !empty($ui['delayAllJsEnabled']);
        $delay_safe_third_party_js_enabled = !empty($ui['delaySafeThirdPartyJsEnabled']);
        $delay_functional_third_party_js_enabled = !empty($ui['delayFunctionalThirdPartyJsEnabled']);
        $delay_all_third_party_js_enabled = !empty($ui['delayAllThirdPartyJsEnabled']);
        $delay_non_critical_js_enabled = !empty($ui['delayNonCriticalJsEnabled']);
        $defer_stage_aggressive = $delay_non_critical_js_enabled;
        $defer_stage_balanced = $delay_safe_third_party_js_enabled || $delay_functional_third_party_js_enabled || $delay_all_third_party_js_enabled || $defer_stage_aggressive;
        $defer_stage_safe = $defer_js_enabled || $defer_all_js_enabled || $delay_all_js_enabled || $defer_stage_balanced;
        $manual_lcp_selector_split = self::split_manual_lcp_selector_setting($ui['manualLcpHeroSelector'] ?? '');
        $defaults = self::get_dashboard_defaults();
        $varnish_stale_while_revalidate_seconds = self::sanitize_bounded_integer_setting(
            $ui['varnishStaleWhileRevalidateSeconds'] ?? $defaults['varnishStaleWhileRevalidateSeconds'],
            $defaults['varnishStaleWhileRevalidateSeconds'],
            0,
            86400
        );
        if ($varnish_stale_while_revalidate_seconds > 0) {
            $varnish_mode = self::sanitize_varnish_mode($ui['varnishCliMode'] ?? 'http');
            $varnish_servers_raw = self::sanitize_varnish_servers_string($ui['varnishCliServers'] ?? '', $varnish_mode);
            $varnish_capability = self::get_varnish_soft_purge_capability(array(
                'mode' => $varnish_mode,
                'servers' => array_values(array_filter(array_map('trim', preg_split('/\s+/', $varnish_servers_raw)))),
                'key' => function_exists('ultracache_get_varnish_password') ? trim((string) ultracache_get_varnish_password()) : '',
            ));
            if (empty($varnish_capability['supported'])) {
                $varnish_stale_while_revalidate_seconds = 0;
            }
        }

        self::$settings_cache = array(
            'enabled'                      => !empty($ui['pageCacheEnabled']),
            'purge_after_core_updates'     => !empty($ui['purgeAfterCoreUpdatesEnabled']),
            'purge_after_plugin_updates'   => !empty($ui['purgeAfterPluginUpdatesEnabled']),
            'purge_after_theme_updates'    => !empty($ui['purgeAfterThemeUpdatesEnabled']),
            'object_cache_enabled'         => !empty($ui['objectCacheEnabled']),
            'object_cache_backend'         => self::sanitize_object_cache_backend($ui['objectCacheBackend']),
            'object_cache_fallback_backend'=> self::sanitize_object_cache_fallback_backend($ui['objectCacheFallbackBackend'] ?? 'apcu'),
            'sqlite_database_size_mb'       => self::sanitize_sqlite_database_size_mb($ui['sqliteDatabaseSizeMb'] ?? 256, 256),
            'redis_host'                   => self::sanitize_redis_host($ui['redisHost']),
            'redis_port'                   => self::sanitize_bounded_integer_setting($ui['redisPort'], 6379, 1, 65535),
            'redis_username'               => self::sanitize_redis_username($ui['redisUsername'] ?? ''),
            'redis_password'               => trim((string) $ui['redisPassword']),
            'redis_database'               => self::sanitize_redis_database($ui['redisDatabase']),
            'redis_prefix'                 => self::sanitize_redis_prefix($ui['redisPrefix']),
            'redis_use_tls'                => !empty($ui['redisUseTls']),
            'redis_persistent'             => !empty($ui['redisPersistent']),
            'redis_connect_timeout_ms'     => self::sanitize_bounded_integer_setting($ui['redisConnectTimeoutMs'], 200, 50, 15000),
            'redis_read_timeout_ms'        => self::sanitize_bounded_integer_setting($ui['redisReadTimeoutMs'], 200, 50, 15000),
            'cache_logged_in_users'        => false,
            'cache_query_strings'          => !empty($ui['cacheQueryStringsEnabled']),
            'cache_query_allowlist'        => $query_allowlist,
            'cache_safe_tracking_cookies'  => !empty($ui['cacheSafeTrackingCookiesEnabled']),
            'safe_tracking_cookie_patterns'=> $safe_tracking_cookie_patterns,
            'unsafe_cache_cookie_patterns' => $unsafe_cache_cookie_patterns,
            'gzip_enabled'                 => !empty($ui['gzipEnabled']),
            'brotli_enabled'               => !empty($ui['brotliEnabled']),
            'cache_stats_enabled'          => !empty($ui['cacheStatsEnabled']),
            'preload_on_save'              => !empty($ui['preRenderOnSave']),
            'defer_js'                     => $defer_js_enabled,
            'defer_all_js'                 => $defer_all_js_enabled,
            'delay_all_js'                 => $delay_all_js_enabled,
            'delayed_local_js_auto_start' => in_array((string) ($ui['delayedLocalJsAutoStart'] ?? 'custom'), array('interaction', 'custom'), true) ? (string) ($ui['delayedLocalJsAutoStart'] ?? 'custom') : 'custom',
            'delayed_local_js_auto_start_seconds' => self::sanitize_bounded_number_setting($ui['delayedLocalJsAutoStartSeconds'] ?? 0.05, 0.05, 0.05, 5),
            'delayed_js_autostart_after_load' => !empty($ui['delayedJsAutostartAfterLoadEnabled']),
            'delayed_js_autostart_mousemove' => !empty($ui['delayedJsAutostartMousemoveEnabled']),
            'delayed_js_autostart_scroll' => !empty($ui['delayedJsAutostartScrollEnabled']),
            'delayed_js_autostart_click' => !empty($ui['delayedJsAutostartClickEnabled']),
            'delayed_js_autostart_touch_pointer' => !empty($ui['delayedJsAutostartTouchPointerEnabled']),
            'delayed_js_autostart_keyboard' => !empty($ui['delayedJsAutostartKeyboardEnabled']),
            'first_party_js_parallel_execution' => !empty($ui['firstPartyJsParallelExecutionEnabled']),
            'third_party_js_parallel_execution' => !empty($ui['thirdPartyJsParallelExecutionEnabled']),
            'defer_stage_safe'             => $defer_stage_safe,
            'defer_stage_balanced'         => $defer_stage_balanced,
            'defer_stage_aggressive'       => $defer_stage_aggressive,
            'defer_js_force_list'          => self::parse_textarea_setting(self::normalize_textarea_setting($ui['deferJsForceList'])),
            'defer_js_exclude_list'        => self::parse_textarea_setting(self::normalize_textarea_setting($ui['deferJsExcludeList'])),
            'delay_safe_third_party_js'         => $delay_safe_third_party_js_enabled,
            'delay_all_third_party_js'          => $delay_all_third_party_js_enabled,
            'lazy_mailerlite_nonce'        => !empty($ui['lazyMailerliteNonceEnabled']),
            'delay_safe_third_party_js_patterns' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['delaySafeThirdPartyJsPatterns'])),
            'delay_functional_third_party_js' => $delay_functional_third_party_js_enabled,
            'delay_functional_third_party_js_patterns' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['delayFunctionalThirdPartyJsPatterns'])),
            'async_external_scripts'       => !empty($ui['asyncExternalScriptsEnabled']),
            'homepage_css_bundle'         => !empty($ui['homepageCssBundleEnabled']),
            'homepage_css_bundle_inline'  => !empty($ui['homepageCssBundleInlineEnabled']),
            'leftover_css_bundle'       => !empty($ui['leftoverCssBundleEnabled']),
            'font_mix_css_bundle'       => !empty($ui['fontMixCssBundleEnabled']),
            'font_mix_css_bundle_async' => !empty($ui['fontMixCssBundleAsyncEnabled']) && !empty($ui['fontMixCssBundleEnabled']),
            'homepage_css_bundle_exclude_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['homepageCssBundleExcludeList'])),
            'delay_icon_fonts'            => !empty($ui['delayIconFontsEnabled']),
            'delay_icon_fonts_auto_detect' => false,
            'delay_icon_fonts_list'       => self::parse_textarea_setting(self::normalize_textarea_setting($ui['delayIconFontsList'])),
            'delay_icon_fonts_exclude_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['delayIconFontsExcludeList'])),
            'homepage_css_bundle_mode'    => self::sanitize_homepage_css_bundle_mode($ui['homepageCssBundleMode']),
            'css_bundle_scope'            => self::sanitize_css_bundle_scope($ui['cssBundleScope'] ?? 'homepage'),
            'page_css_bundle_on_entry'    => !empty($ui['pageCssBundleOnEntryEnabled']) && empty($ui['pageAsyncBundleOnEntryEnabled']),
            'page_css_bundle_async_on_entry' => !empty($ui['pageAsyncBundleOnEntryEnabled']),
            'slider_safe_mode'            => !empty($ui['sliderSafeModeEnabled']),
            'cls_dimensions'               => !empty($ui['clsDimensionsEnabled']),
            'async_css'                    => !empty($ui['asyncCssEnabled']),
            'async_external_css'           => !empty($ui['asyncExternalCssEnabled']),
            'async_css_exclude_list'       => self::parse_textarea_setting(self::normalize_textarea_setting($ui['asyncCssExcludeList'])),
            'async_external_css_exclude_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['asyncExternalCssExcludeList'] ?? '')),
            'aggressive_async_css'         => !empty($ui['aggressiveAsyncCssEnabled']),
            'delay_non_critical_js'        => $delay_non_critical_js_enabled,
            'delay_non_critical_js_aggressive' => $defer_stage_aggressive,
            'delay_non_critical_js_exclude_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['deferJsExcludeList'])),
            'lcp_image_priority'           => !empty($ui['lcpImagePriorityEnabled']),
            'lcp_frontend_discovery'       => !empty($ui['lcpFrontendDiscoveryEnabled']),
            'lcp_frontend_discovery_admins_only' => !empty($ui['lcpFrontendDiscoveryAdminsOnly']),
            'lcp_frontend_discovery_duration' => self::sanitize_lcp_frontend_discovery_duration($ui['lcpFrontendDiscoveryDuration'] ?? 'indefinitely'),
            'lcp_frontend_discovery_started_at' => absint($ui['lcpFrontendDiscoveryStartedAt'] ?? 0),
            'lcp_frontend_discovery_expires_at' => absint($ui['lcpFrontendDiscoveryExpiresAt'] ?? 0),
            'lazy_load_images'            => !empty($ui['lazyLoadImagesEnabled']),
            'lcp_boundary_defer'           => !empty($ui['lcpBoundaryDeferEnabled']),
            'lcp_image_priority_override_list' => $manual_lcp_selector_split['images'],
            'manual_lcp_hero_selector_list' => $manual_lcp_selector_split['selectors'],
            'main_thread_relief'          => !empty($ui['mainThreadReliefEnabled']),
            'critical_request_chain_relief' => !empty($ui['criticalRequestChainReliefEnabled']),
            'critical_resource_preload_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['criticalResourcePreloadList'])),
            'critical_request_chain_delay_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['criticalRequestChainDelayList'])),
            'asset_chain_cleanup'          => !empty($ui['assetChainCleanupEnabled']),
            'asset_cleanup_woo_product_assets' => !empty($ui['assetCleanupWooProductAssetsEnabled']),
            'asset_cleanup_product_filter_assets' => !empty($ui['assetCleanupProductFilterAssetsEnabled']),
            'asset_cleanup_woo_blocks_css' => !empty($ui['assetCleanupWooBlocksCssEnabled']),
            'woocommerce_cart_fragments_suppress_empty' => !empty($ui['woocommerceCartFragmentsSuppressEmptyEnabled']),
            'woocommerce_cart_fragments_delay' => !empty($ui['woocommerceCartFragmentsDelayEnabled']),
            'woocommerce_cart_fragments_delay_timing' => self::sanitize_woocommerce_cart_fragments_delay_timing($ui['woocommerceCartFragmentsDelayTiming'] ?? 'delayed-js'),
            'asset_cleanup_exclude_list'   => self::parse_textarea_setting(self::normalize_textarea_setting($ui['assetCleanupExcludeList'])),
            'google_fonts_swap'            => !empty($ui['googleFontsSwapEnabled']),
            'google_fonts_local_optimization' => !empty($ui['googleFontsLocalOptimizationEnabled']),
            'google_fonts_additional_scan_urls' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['googleFontsAdditionalScanUrls'] ?? '')),
            'self_hosted_font_css_optimization' => !empty($ui['selfHostedFontCssOptimizationEnabled']),
            'self_hosted_font_runtime_rewrite' => !empty($ui['selfHostedFontRuntimeRewriteEnabled']),
            'speculation_rules_enabled'    => !empty($ui['speculationRulesEnabled']),
            'browser_cache_rules'          => !empty($ui['browserCacheRulesEnabled']),
            'apache_static_html_delivery'  => !empty($ui['apacheStaticHtmlDeliveryEnabled']),
            'varnish_cli_enabled'          => !empty($ui['varnishCliEnabled']),
            'varnish_cli_mode'             => self::sanitize_varnish_mode($ui['varnishCliMode']),
            'varnish_cli_servers'          => self::sanitize_varnish_servers_string($ui['varnishCliServers'], self::sanitize_varnish_mode($ui['varnishCliMode'])),
            'varnish_cli_key'              => trim((string) $ui['varnishCliKey']),
            'varnish_cli_timeout_seconds'  => max(1, min(15, absint($ui['varnishCliTimeoutSeconds']))),
            'varnish_cli_method'           => ('PURGE' === strtoupper(trim((string) $ui['varnishCliMethod']))) ? 'PURGE' : 'BAN',
            'varnish_invalidation_strategy' => self::sanitize_varnish_invalidation_strategy($ui['varnishInvalidationStrategy'] ?? 'ban'),
            'varnish_flush_scope'          => self::sanitize_varnish_flush_scope($ui['varnishFlushScope'] ?? 'auto'),
            'varnish_html_ttl_minutes'     => self::sanitize_bounded_integer_setting($ui['varnishHtmlTtlMinutes'] ?? $defaults['varnishHtmlTtlMinutes'], $defaults['varnishHtmlTtlMinutes'], 0, 525600),
            'varnish_stale_while_revalidate_seconds' => $varnish_stale_while_revalidate_seconds,
            'varnish_refill_after_targeted_invalidation' => !empty($ui['varnishRefillAfterTargetedInvalidation']),
            'varnish_warm_during_manual_warmup' => !empty($ui['varnishWarmDuringManualWarmup']),
            'varnish_refresh_ahead_enabled'  => !empty($ui['varnishRefreshAheadEnabled']),
            'varnish_refresh_ahead_threshold_percent' => self::sanitize_bounded_integer_setting($ui['varnishRefreshAheadThresholdPercent'] ?? $defaults['varnishRefreshAheadThresholdPercent'], $defaults['varnishRefreshAheadThresholdPercent'], 50, 95),
            'varnish_refresh_ahead_max_pages' => self::sanitize_bounded_integer_setting($ui['varnishRefreshAheadMaxPages'] ?? $defaults['varnishRefreshAheadMaxPages'], $defaults['varnishRefreshAheadMaxPages'], 1, 10),
            'varnish_refresh_ahead_pinned_urls' => self::parse_textarea_setting(self::sanitize_local_url_textarea_setting($ui['varnishRefreshAheadPinnedUrls'] ?? '', 25)),
            'media_optimization_enabled'   => !empty($ui['mediaOptimizationEnabled']),
            'media_generate_on_upload'     => !empty($ui['mediaGenerateOnUploadEnabled']),
            'media_generate_on_demand'     => !empty($ui['mediaGenerateOnDemandEnabled']),
            'media_upload_conversion_enabled' => !empty($ui['mediaUploadConversionEnabled']),
            'image_upload_max_side'        => self::sanitize_bounded_integer_setting($ui['imageUploadMaxSide'] ?? $defaults['imageUploadMaxSide'], $defaults['imageUploadMaxSide'], 1, 8192),
            'media_output_mode'            => self::sanitize_media_output_mode($ui['mediaOutputMode']),
            'media_fallback_format'        => self::sanitize_media_fallback_format($ui['mediaFallbackFormat'] ?? $defaults['mediaFallbackFormat'], $ui['mediaOutputMode'] ?? $defaults['mediaOutputMode']),
            'media_quality'                => self::sanitize_media_quality($ui['mediaQuality'] ?? $defaults['mediaQuality']),
            'woo_safe_mode'                => !empty($ui['woocommerceSafeModeEnabled']),
            'cache_cleanup_enabled'        => !empty($ui['cacheCleanupEnabled']),
            'apcu_flush_on_scheduled_cleanup' => !empty($ui['apcuFlushOnScheduledCleanup']),
            'flush_all_include_opcache'   => !empty($ui['flushAllIncludeOpcache']),
            'flush_all_include_apcu'      => ('apcu' === self::sanitize_object_cache_backend($ui['objectCacheBackend'] ?? 'redis')) || !empty($ui['flushAllIncludeApcu']),
            'flush_all_include_litespeed' => !empty($ui['flushAllIncludeLiteSpeed']),
            'flush_all_include_nginx'     => !empty($ui['flushAllIncludeNginx']),
            'flush_all_include_varnish'   => !empty($ui['flushAllIncludeVarnish']),
            'cache_cleanup_interval_hours' => max(1, absint($ui['cacheCleanupIntervalHours'])),
            'css_bundle_cleanup_grace_seconds' => HOUR_IN_SECONDS * self::sanitize_bounded_integer_setting($ui['cssBundleCleanupGraceHours'] ?? 48, 48, 1, 168),
            'css_bundle_cleanup_delete_limit' => self::sanitize_bounded_integer_setting($ui['cssBundleCleanupDeleteLimit'] ?? 60, 60, 5, 500),
            'cron_warm_enabled'            => !empty($ui['cronWarmEnabled']),
            'cron_warm_start_after_cleanup'=> !empty($ui['cronWarmStartAfterCleanup']),
            'cron_warm_start_after_manual_purge'=> !empty($ui['cronWarmStartAfterManualPurge']),
            'debug_headers_enabled'        => !empty($ui['debugHeadersEnabled']),
            'cron_warm_pages_per_minute'   => max(0, absint($ui['cronWarmPagesPerMinute'])),
            'scheduled_warm_limit'         => max(1, absint($ui['scheduledWarmLimit'])),
            'warm_menu_location'           => sanitize_key((string) ($ui['warmMenuLocation'] ?? '')),
            'warm_menu_depth'              => in_array((string) ($ui['warmMenuDepth'] ?? ''), array('1', '2', '3', 'all'), true) ? (string) $ui['warmMenuDepth'] : '',
            'warm_full_site_sources'       => self::parse_textarea_setting(str_replace(',', "\n", (string) ($ui['warmFullSiteSources'] ?? ''))),
            'stale_while_revalidate_enabled' => !empty($ui['staleWhileRevalidateEnabled']),
            'cache_fresh_ttl_minutes'      => self::sanitize_positive_integer_setting($ui['cacheFreshTtlMinutes'] ?? $defaults['cacheFreshTtlMinutes'], $defaults['cacheFreshTtlMinutes'], 1),
            'cache_max_stale_minutes'      => max(self::sanitize_positive_integer_setting($ui['cacheFreshTtlMinutes'] ?? $defaults['cacheFreshTtlMinutes'], $defaults['cacheFreshTtlMinutes'], 1), self::sanitize_positive_integer_setting($ui['cacheMaxStaleMinutes'] ?? $defaults['cacheMaxStaleMinutes'], $defaults['cacheMaxStaleMinutes'], 1)),
            'excluded_paths'               => $excluded_paths,
            'excluded_query_args'          => $excluded_query_args,
        );

        ultracache_request_profile_settings_checkpoint('wp_get_settings_after_runtime_map', array(
            'settings_count' => count(self::$settings_cache),
        ));
        return self::$settings_cache;
    }

}
