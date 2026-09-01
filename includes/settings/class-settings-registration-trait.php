<?php
/**
 * Settings registration and canonical defaults.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Settings_Registration_Trait
{

    /**
     * Register the canonical UltraCache settings option with WordPress.
     *
     * The dashboard uses a custom REST interface with schema validation and
     * the same canonical sanitizer. Registration supplies WordPress Settings
     * API consumers with the canonical default and sanitization callback.
     *
     * @return void
     */
    public static function register_dashboard_setting()
    {
        register_setting(
            'ultracache',
            ULTRACACHE_SETTINGS_KEY,
            array(
                'type'              => 'array',
                'description'       => __('UltraCache plugin settings.', 'ultracache'),
                'sanitize_callback' => array(__CLASS__, 'sanitize_registered_dashboard_settings'),
                'default'           => self::get_dashboard_defaults(),
                'show_in_rest'      => false,
            )
        );
    }


    public static function get_dashboard_defaults()
    {
        // Defaults must stay cheap and side-effect free. Capability probes are
        // dashboard diagnostics, not runtime default construction.
        return array(
            'pageCacheEnabled'           => false,
            'purgeAfterCoreUpdatesEnabled'   => true,
            'purgeAfterPluginUpdatesEnabled' => true,
            'purgeAfterThemeUpdatesEnabled'  => true,
            'objectCacheEnabled'         => false,
            'objectCacheBackend'         => 'redis',
            'objectCacheFallbackBackend' => 'apcu',
            'sqliteDatabaseSizeMb'        => 256,
            'redisHost'                  => '127.0.0.1',
            'redisPort'                  => 6379,
            'redisUsername'              => '',
            'redisPassword'              => '',
            'redisDatabase'              => 0,
            'redisPrefix'                => '',
            'redisUseTls'                => false,
            'redisPersistent'            => false,
            'redisConnectTimeoutMs'      => 200,
            'redisReadTimeoutMs'         => 200,
            'brotliEnabled'              => false,
            'gzipEnabled'                => false,
            'cacheStatsEnabled'          => false,
            'mediaOptimizationEnabled'     => false,
            'mediaGenerateOnUploadEnabled' => false,
            'mediaGenerateOnDemandEnabled' => false,
            'mediaUploadConversionEnabled' => false,
            'mediaStaleWorkerThreshold'    => 3,
            'imageUploadMaxSide'           => 1920,
            'mediaIgnoreColorProfilePreservation' => false,
            'mediaUploadFormat'          => 'webp',
            'mediaOutputMode'            => 'webp',
            'mediaFallbackFormat'        => 'original',
            'mediaReplacementFormat'     => 'webp',
            'mediaQuality'               => 'balanced',
            'javascriptStrategy'         => 'off',
            'deferJsEnabled'             => false,
            'delayAllJsEnabled'          => false,
            'firstPartyJsParallelExecutionEnabled' => false,
            'thirdPartyJsParallelExecutionEnabled' => false,
            'delayedLocalJsAutoStart'  => 'custom',
            'delayedLocalJsAutoStartSeconds' => 0.05,
            'delayedJsMinimumReleaseSeconds' => 0,
            'delayedJsAutostartAfterLoadEnabled' => false,
            'delayedJsAutostartMousemoveEnabled' => false,
            'delayedJsAutostartScrollEnabled' => false,
            'delayedJsAutostartClickEnabled' => false,
            'delayedJsAutostartTouchPointerEnabled' => false,
            'delayedJsAutostartKeyboardEnabled' => false,
            'delayAllThirdPartyJsEnabled' => false,
            'deferJsForceList'           => '',
            'deferJsExcludeList'         => implode("\n", self::get_default_js_delay_defer_exclusion_patterns()),
            'delaySafeThirdPartyJsEnabled'   => false,
            'lazyMailerliteNonceEnabled' => false,
            'delaySafeThirdPartyJsPatterns' => implode("\n", self::get_default_safe_third_party_delay_patterns()),
            'delayFunctionalThirdPartyJsEnabled' => false,
            'delayFunctionalThirdPartyJsPatterns' => implode("\n", self::get_default_functional_third_party_delay_patterns()),
            'asyncExternalScriptsEnabled'=> false,
            'homepageCssBundleEnabled'   => false,
            'homepageCssBundleInlineEnabled' => false,
            'leftoverCssBundleEnabled'   => false,
            'fontMixCssBundleEnabled'    => false,
            'fontMixCssBundleAsyncEnabled' => false,
            'homepageCssBundleExcludeList' => '',
            'homepageCssBundleMode'      => 'safe',
            'delayIconFontsEnabled'      => false,
            'delayIconFontsAutoDetectEnabled' => false,
            'delayIconFontsList'         => '',
            'delayIconFontsExcludeList'  => '',
            'cssBundleScope'            => 'homepage',
            'pageCssBundleOnEntryEnabled' => false,
            'pageAsyncBundleOnEntryEnabled' => false,
            'sliderSafeModeEnabled'       => false,
            'clsDimensionsEnabled'       => false,
            'asyncCssEnabled'            => false,
            'asyncExternalCssEnabled'    => false,
            'asyncConsentCssEnabled'     => false,
            'asyncConsentCssAutoEnabled' => false,
            'asyncCssExcludeList'        => '',
            'asyncExternalCssExcludeList' => '',
            'aggressiveAsyncCssEnabled'  => false,
            'delayNonCriticalJsEnabled'  => false,
            'protectWpBakeryAnimationsEnabled' => false,
            'protectElementorCompatibilityEnabled' => false,
            'realCookieBannerCompatibilityEnabled' => false,
            'complianzCompatibilityEnabled' => false,
            'woocommerceVariableProductCompatibilityEnabled' => false,
            'delayNonCriticalJsExcludeList' => '',
            'lcpImagePriorityEnabled'    => false,
            'lcpFrontendDiscoveryEnabled' => false,
            'lcpFrontendDiscoveryAdminsOnly' => false,
            'lcpFrontendDiscoveryDuration' => 'indefinitely',
            'lcpFrontendDiscoveryStartedAt' => 0,
            'lcpFrontendDiscoveryExpiresAt' => 0,
            'lazyLoadImagesEnabled'      => false,
            'lazyLoadThirdPartyIframesEnabled' => false,
            'lcpBoundaryDeferEnabled'    => false,
            'manualLcpHeroSelector'      => '',
            'mainThreadReliefEnabled'    => false,
            'criticalRequestChainReliefEnabled' => false,
            'criticalResourcePreloadList' => '',
            'criticalRequestChainDelayList' => '',
            'assetChainCleanupEnabled'    => false,
            'assetCleanupWooProductAssetsEnabled' => false,
            'assetCleanupProductFilterAssetsEnabled' => false,
            'assetCleanupWooBlocksCssEnabled' => false,
            'woocommerceCartFragmentsSuppressEmptyEnabled' => false,
            'woocommerceCartFragmentsDelayEnabled' => false,
            'woocommerceCartFragmentsDelayTiming' => 'delayed-js',
            'assetCleanupExcludeList'     => "elementor\nbricks\noxygen\nwpbakery\nvc_\nrevslider\nsr7\najaxsearch\nfibosearch\n.dgwt-wcas\naws-container\ncart\ncheckout\naccount",
            'googleFontsSwapEnabled'     => false,
            'googleFontsLocalOptimizationEnabled' => false,
            'googleFontsAdditionalScanUrls' => '',
            'selfHostedFontCssOptimizationEnabled' => false,
            'selfHostedFontRuntimeRewriteEnabled' => false,
            'speculationRulesEnabled'    => false,
            'browserCacheRulesEnabled'   => false,
            'apacheStaticHtmlDeliveryEnabled' => false,
            'liteSpeedCacheEnabled'      => false,
            'varnishCliEnabled'          => false,
            'varnishConnectionConfigured' => false,
            'varnishCliMode'             => 'http',
            'varnishCliServers'          => self::get_default_varnish_http_endpoint(),
            'varnishCliKey'              => '',
            'varnishCliTimeoutSeconds'   => 2,
            'varnishInvalidationsPerMinute' => 10,
            'varnishCliMethod'           => 'BAN',
            'varnishInvalidationStrategy' => 'auto',
            'varnishFlushScope'          => 'auto',
            'preRenderOnSave'            => false,
            'woocommerceSafeModeEnabled' => false,
            'cacheCleanupEnabled'        => false,
            'apcuFlushOnScheduledCleanup'=> false,
            'flushAllIncludeOpcache'     => false,
            'flushAllIncludeApcu'        => false,
            'flushAllIncludeLiteSpeed'   => false,
            'flushAllIncludeNginx'       => false,
            'flushAllIncludeVarnish'     => false,
            'flushAllIncludeElementor'   => false,
            'cacheCleanupIntervalHours'  => 24,
            'cssBundleCleanupGraceHours' => 48,
            'cssBundleCleanupDeleteLimit' => 60,
            'cronWarmStartAfterCleanup'  => false,
            'cronWarmStartAfterManualPurge' => false,
            'warmUncachedUrlsOnFirstVisit' => false,
            'warmCssBundlesEnabled'       => true,
            'alsoWarmTranslationPagesEnabled' => true,
            'multilingualWarmPolicyV1'  => array('schemaVersion' => 2, 'migrationVersion' => 0, 'providerPolicies' => array(), 'providerStates' => array()),
            'cronWarmPagesPerMinute'     => 2,
            'scheduledWarmLimit'         => 9,
            'warmMenuLocation'           => '',
            'warmMenuDepth'              => '',
            'warmFullSiteSources'        => '',
            'staleWhileRevalidateEnabled'=> false,
            'debugHeadersEnabled'        => false,
            'openBrowserScannerInNewWindowEnabled' => false,
            'cacheFreshTtlMinutes'       => 1440,
            'cacheMaxStaleMinutes'       => 2880,
            'cacheExceptionPaths'        => implode("\n", self::get_default_excluded_paths()),
            'cacheExceptionQueryArgs'    => implode("\n", self::get_default_excluded_query_args()),
            'cacheQueryStringsEnabled'   => false,
            'cacheQueryStringAllowlist'  => '',
            'cacheQueryCombinationLevel' => '3',
            'cacheSafeTrackingCookiesEnabled' => true,
            'safeTrackingCookieList'     => implode("\n", self::get_default_safe_tracking_cookie_patterns()),
            'unsafeCacheCookieList'      => implode("\n", self::get_default_unsafe_cache_cookie_patterns()),
            'uninstallCleanupPolicy'    => 'delete_everything',
        );
    }



    private static function get_full_site_warm_source_order_keys()
    {
        return array('homepage', 'menus', 'pages', 'posts', 'categories', 'tags', 'woocommerce_products', 'woocommerce_product_taxonomies', 'custom_post_types', 'custom_taxonomies');
    }



    private static function get_default_excluded_paths()
    {
        return array_values(array_filter(array(
            function_exists('ultracache_wordpress_admin_public_path') ? ultracache_wordpress_admin_public_path() : '',
            '/wp-login.php',
            '/wc-api/',
            '/wp-json/',
        )));
    }



    private static function get_default_excluded_query_args()
    {
        return array(
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
            'customer-logout',
            'logout',
            'pay_for_order',
            'cancel_order',
            'download_file',
            'ultracache_runtime_js_scan',
            'ultracache_runtime_js_scan_id',
            'ultracache_runtime_js_scan_token',
            'ultracache_runtime_js_scan_nonce',
            'ultracache_runtime_js_scan_mode',
            'ultracache_runtime_js_scan_context',
        );
    }



    private static function get_default_safe_tracking_cookie_patterns()
    {
        return array(
            '_fbp',
            '_fbc',
            '_ga',
            '_gid',
            '_gat',
            '_gac_',
            '_gcl_au',
            '_gcl_aw',
            '_gcl_dc',
            '_dc_gtm_',
            '_clck',
            '_clsk',
            'CLID',
            '_uetvid',
            '_uetsid',
            '_ttp',
            '_tt_enable_cookie',
            'ttcsid',
            '_pin_unauth',
            '_pinterest_ct_ua',
        );
    }



    private static function get_default_unsafe_cache_cookie_patterns()
    {
        return array(
            'wordpress_logged_in_',
            'wordpress_sec_',
            'wp-postpass_',
            'comment_author_',
            'woocommerce_items_in_cart',
            'woocommerce_cart_hash',
            'wp_woocommerce_session_',
            'woocommerce_recently_viewed',
            'yith_wcwl_session_',
            'yith_woocompare_',
        );
    }



    private static function get_default_delay_icon_font_patterns()
    {
        return array(
            'fa-solid-900',
            'fa-regular',
            'fa-brands',
            'fontawesome',
            'font awesome',
            'eicons',
            'dashicons',
            'bokifa-icon',
            'icomoon',
            'flaticon',
            'themify',
            'simple-line-icons',
            'linearicons',
            'material-icons',
            'materialicons',
            'ionicons',
            'feather.ttf',
            'feather fonts',
            'star.ttf',
            'woocommerce star',
        );
    }



    private static function get_default_delay_icon_font_exclude_patterns()
    {
        return array(
            'manrope',
            'fraunces',
            'roboto',
            'open-sans',
            'inter',
            'lato',
            'montserrat',
            'poppins',
            'source-sans',
            'noto',
            '2920108',
            'google-fonts',
            'body',
            'heading',
        );
    }



    private static function get_default_js_delay_defer_exclusion_patterns()
    {
        /*
         * Canonical visible defaults used by fresh settings and Populate
         * Defaults. Saving user-edited JavaScript policy lists must never
         * inject additional compatibility entries.
         */
        return array_values(array_filter(array(
            function_exists('ultracache_wordpress_includes_public_path') ? ultracache_wordpress_includes_public_path('js/jquery/jquery.min.js') : '',
        )));
    }



    private static function get_broad_wp_dependency_preset_patterns()
    {
        /*
         * Manual compatibility preset only. These are not scanner results
         * and are not silently applied as defaults.
         */
        $paths = array(
            'js/jquery/jquery.min.js',
            'js/jquery/jquery-migrate.min.js',
            'js/underscore.min.js',
            'js/wp-util.min.js',
            'js/dist/i18n.min.js',
            'js/dist/hooks.min.js',
            'js/dist/api-fetch.min.js',
            'js/api-request.min.js',
            'js/dist/dom-ready.min.js',
        );

        return array_values(array_filter(array_map(static function ($relative) {
            return function_exists('ultracache_wordpress_includes_public_path')
                ? ultracache_wordpress_includes_public_path($relative)
                : '';
        }, $paths)));
    }



    private static function get_default_safe_third_party_delay_patterns()
    {
        /*
         * User-editable matching fragments for scripts already present on
         * the page. These defaults do not load, enqueue, fetch, or contact
         * the listed third-party providers by themselves.
         */
        return array(
            'googletagmanager.com',
            'google-analytics.com',
            'gtag/js',
            'gtag(',
            'dataLayer',
            'gtm.start',
            'gtm.js',
            'googlesitekit-events-provider',
            'google-site-kit/dist/assets/js',
            'connect.facebook.net',
            'fbevents.js',
            'fbq',
            'analytics.tiktok.com',
            'snap.licdn.com',
            'insight.min.js',
            'bat.bing.com',
            'clarity.ms',
            'static.hotjar.com',
            'script.hotjar.com',
            's.pinimg.com',
            'pintrk',
            'doubleclick.net',
            'googleadservices.com',
            'taboola',
            'outbrain',
            'yahoo',
            'yimg.com',
        );
    }



    private static function get_default_functional_third_party_delay_patterns()
    {
        /*
         * User-editable matching fragments for already-present functional
         * third-party scripts. These defaults are used for matching only;
         * they do not add or contact those providers.
         */
        return array(
            'recaptcha',
            'hcaptcha',
            'google.com/recaptcha',
            'maps.googleapis.com',
            'cookieyes',
            'cky-',
            'intercom',
            'crisp.chat',
            'tawk.to',
            'zendesk',
            'calendly',
            'typeform',
            'jotform',
        );
    }



    private static function get_default_varnish_http_endpoint()
    {
        return '127.0.0.1:82';
    }



    private static function get_default_varnish_admin_endpoint()
    {
        return '127.0.0.1:6082';
    }



    private static function get_varnish_http_allowed_ports()
    {
        $ports = apply_filters('ultracache_varnish_http_endpoint_ports', array(82, 6081));
        if (!is_array($ports)) {
            $ports = array(82, 6081);
        }

        $ports = array_values(array_unique(array_filter(array_map('intval', $ports))));
        return !empty($ports) ? $ports : array(82, 6081);
    }

}
