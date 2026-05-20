<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!trait_exists('Ultra_Cache_WP_Settings_Trait')) {
    trait Ultra_Cache_WP_Settings_Trait
    {
        public static function get_dashboard_defaults()
        {
            // Defaults must stay cheap and side-effect free. Capability probes are
            // dashboard diagnostics, not runtime default construction.
            return array(
                'pageCacheEnabled'           => false,
                'objectCacheEnabled'         => false,
                'objectCacheBackend'         => 'redis',
                'objectCacheFallbackBackend' => 'apcu',
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
                'mediaOutputMode'            => 'auto',
                'deferJsEnabled'             => false,
                'deferAllJsEnabled'          => false,
                'jsFullSiteStrategy'         => 'off',
                'delayedLocalJsAutoStart'  => 'custom',
                'delayedLocalJsAutoStartSeconds' => 1,
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
                'asyncCssExcludeList'        => '',
                'aggressiveAsyncCssEnabled'  => false,
                'delayNonCriticalJsEnabled'  => false,
                'delayNonCriticalJsExcludeList' => '',
                'lcpImagePriorityEnabled'    => false,
                'lazyLoadImagesEnabled'      => false,
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
                'assetCleanupExcludeList'     => "elementor\nbricks\noxygen\nwpbakery\nvc_\nrevslider\nsr7\najaxsearch\nfibosearch\n.dgwt-wcas\naws-container\ncart\ncheckout\naccount",
                'googleFontsSwapEnabled'     => false,
                'googleFontsLocalOptimizationEnabled' => false,
                'googleFontsAdditionalScanUrls' => '',
                'selfHostedFontCssOptimizationEnabled' => false,
                'selfHostedFontRuntimeRewriteEnabled' => false,
                'speculationRulesEnabled'    => false,
                'browserCacheRulesEnabled'   => false,
                'varnishCliEnabled'          => false,
                'varnishCliMode'             => 'http',
                'varnishCliServers'          => self::get_default_varnish_http_endpoint(),
                'varnishCliKey'              => '',
                'varnishCliTimeoutSeconds'   => 2,
                'varnishCliMethod'           => 'BAN',
                'preRenderOnSave'            => false,
                'woocommerceSafeModeEnabled' => false,
                'cacheCleanupEnabled'        => false,
                'apcuFlushOnScheduledCleanup'=> false,
                'flushAllIncludeOpcache'     => false,
                'flushAllIncludeApcu'        => false,
                'flushAllIncludeLiteSpeed'   => false,
                'flushAllIncludeNginx'       => false,
                'flushAllIncludeVarnish'     => false,
                'cacheCleanupIntervalHours'  => 24,
                'cssBundleCleanupGraceHours' => 48,
                'cssBundleCleanupDeleteLimit' => 60,
                'cronWarmEnabled'            => false,
                'cronWarmStartAfterCleanup'  => false,
                'cronWarmStartAfterManualPurge' => false,
                'cronWarmPagesPerMinute'     => 2,
                'scheduledWarmLimit'         => 9,
                'warmMenuLocation'           => '',
                'warmMenuDepth'              => '',
                'warmFullSiteSources'        => '',
                'staleWhileRevalidateEnabled'=> false,
                'debugHeadersEnabled'        => false,
                'cacheFreshTtlMinutes'       => 15,
                'cacheMaxStaleMinutes'       => 720,
                'cacheExceptionPaths'        => implode("\n", self::get_default_excluded_paths()),
                'cacheExceptionQueryArgs'    => implode("\n", self::get_default_excluded_query_args()),
                'cacheQueryStringsEnabled'   => false,
                'cacheQueryStringAllowlist'  => '',
                'cacheSafeTrackingCookiesEnabled' => false,
                'safeTrackingCookieList'     => implode("\n", self::get_default_safe_tracking_cookie_patterns()),
                'unsafeCacheCookieList'      => implode("\n", self::get_default_unsafe_cache_cookie_patterns()),
                'uninstallCleanupPolicy'    => 'delete_everything',
            );
        }

        public static function sanitize_uninstall_cleanup_policy($policy)
        {
            $policy = strtolower(trim((string) $policy));
            $allowed = array(
                'plugin_only',
                'keep_settings',
                'keep_settings_tables',
                'delete_everything',
            );

            return in_array($policy, $allowed, true) ? $policy : 'plugin_only';
        }

        private static function get_full_site_warm_source_order_keys()
        {
            return array('homepage', 'menus', 'pages', 'posts', 'categories', 'tags', 'woocommerce_products', 'woocommerce_product_taxonomies', 'custom_post_types', 'custom_taxonomies');
        }

        private static function get_default_excluded_paths()
        {
            return array(
                '/cart/',
                '/checkout/',
                '/my-account/',
                '/wp-admin/',
                '/wp-login.php',
                '/wc-api/',
                '/wp-json/',
            );
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
                'ucwp_runtime_js_scan',
                'ucwp_runtime_js_scan_id',
                'ucwp_runtime_js_scan_nonce',
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

        private static function sanitize_cookie_pattern_line($line)
        {
            $line = trim((string) $line);
            if ('' === $line) {
                return '';
            }

            $line = preg_replace('/[\x00-\x1F\x7F]/', '', $line);
            $line = is_string($line) ? trim($line) : '';
            if ('' === $line) {
                return '';
            }

            // Cookie names are case-sensitive in the browser, but matching here
            // is intentionally case-insensitive and pattern-like. Keep only
            // characters valid for cookie names plus '*' as a visible wildcard.
            $line = preg_replace('/[^A-Za-z0-9_\-.\*]/', '', $line);
            $line = is_string($line) ? trim($line) : '';
            if ('' === $line || '*' === $line) {
                return '';
            }

            return $line;
        }

        private static function sanitize_cookie_pattern_setting($value, $limit = 200)
        {
            return self::normalize_multiline_setting_with_callback($value, array(__CLASS__, 'sanitize_cookie_pattern_line'), $limit);
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
             * Append Tested Dependency Defaults must expose only the tested
             * WordPress foundation paths. Broad tokens such as jquery,
             * jquery.min.js, wp-util, api-fetch, or /wp-includes/js/jquery/
             * are intentionally excluded because they can catch unrelated
             * plugin/theme scripts and weaken the JS optimization strategy.
             */
            return array(
                '/wp-includes/js/jquery/jquery.min.js',
                '/wp-includes/js/jquery/jquery-migrate.min.js',
                '/wp-includes/js/underscore.min.js',
                '/wp-includes/js/wp-util.min.js',
                '/wp-includes/js/dist/i18n.min.js',
                '/wp-includes/js/dist/hooks.min.js',
                '/wp-includes/js/dist/api-fetch.min.js',
                '/wp-includes/js/api-request.min.js',
                '/wp-includes/js/dist/dom-ready.min.js',
            );
        }

        private static function get_default_safe_third_party_delay_patterns()
        {
            return array(
                'googletagmanager.com',
                'google-analytics.com',
                'gtag/js',
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
            return array(
                'recaptcha',
                'hcaptcha',
                'google.com/recaptcha',
                'gstatic.com/recaptcha',
                'maps.googleapis.com',
                'maps.gstatic.com',
                'complianz',
                'cmplz',

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

        private static function normalize_textarea_setting($value)
        {
            if (is_array($value)) {
                $value = implode("\n", array_map('strval', $value));
            }

            $value = str_replace(array("\r\n", "\r"), "\n", (string) $value);
            $lines = array_filter(array_map('trim', explode("\n", $value)), static function ($line) {
                return '' !== $line;
            });

            return implode("\n", array_values(array_unique($lines)));
        }

        private static function merge_textarea_settings($first, $second)
        {
            $lines = array_merge(self::parse_textarea_setting($first), self::parse_textarea_setting($second));
            return self::normalize_textarea_setting($lines);
        }

        private static function normalize_multiline_setting_with_callback($value, callable $callback, $limit = 200)
        {
            $lines = self::parse_textarea_setting($value);
            $normalized = array();

            foreach ($lines as $line) {
                $sanitized = call_user_func($callback, $line);
                if (!is_string($sanitized) || '' === $sanitized) {
                    continue;
                }

                $normalized[$sanitized] = $sanitized;
                if (count($normalized) >= max(1, absint($limit))) {
                    break;
                }
            }

            return implode("\n", array_values($normalized));
        }

        private static function get_reserved_setting_keys()
        {
            return array(
                '__proto__',
                'constructor',
                'prototype',
            );
        }

        private static function sanitize_setting_key_line($value)
        {
            $value = strtolower(trim((string) $value));
            if ('' === $value) {
                return '';
            }

            if (!preg_match('/^[a-z0-9_-]{1,64}$/', $value)) {
                return '';
            }

            if (in_array($value, self::get_reserved_setting_keys(), true)) {
                return '';
            }

            return $value;
        }

        private static function sanitize_setting_key_list($value, $limit = 200)
        {
            return self::normalize_multiline_setting_with_callback($value, array(__CLASS__, 'sanitize_setting_key_line'), $limit);
        }

        private static function sanitize_excluded_path_line($value)
        {
            $rule = html_entity_decode(trim((string) $value), ENT_QUOTES, 'UTF-8');
            if ('' === $rule) {
                return '';
            }

            $rule = str_replace('\\', '/', $rule);
            if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $rule)) {
                return '';
            }

            if (false !== strpos($rule, '?') || false !== strpos($rule, '#')) {
                return '';
            }

            if (preg_match('/[[:cntrl:]\s]/u', $rule)) {
                return '';
            }

            if ('/' !== substr($rule, 0, 1)) {
                return '';
            }

            if ('/' === $rule) {
                return '';
            }

            if (false !== strpos($rule, '//')) {
                return '';
            }

            $wildcard = false;
            if (substr($rule, -2) === '/*') {
                $wildcard = true;
                $rule = substr($rule, 0, -2);
            } elseif (false !== strpos($rule, '*')) {
                return '';
            }

            if ('' === $rule || '/' === $rule) {
                return '';
            }

            foreach (explode('/', trim($rule, '/')) as $segment) {
                if ('' === $segment || '.' === $segment || '..' === $segment) {
                    return '';
                }
            }

            if ($wildcard) {
                $rule = rtrim($rule, '/') . '/*';
            }

            return $rule;
        }

        private static function sanitize_excluded_paths_setting($value, $limit = 200)
        {
            return self::normalize_multiline_setting_with_callback($value, array(__CLASS__, 'sanitize_excluded_path_line'), $limit);
        }

        private static function sanitize_positive_integer_setting($value, $default, $min = 1)
        {
            $default = max((int) $min, (int) $default);
            $min = max(0, (int) $min);

            if (is_string($value)) {
                $value = trim($value);
                if ('' === $value || !preg_match('/^\d+$/', $value)) {
                    return $default;
                }
                $value = (int) $value;
            } elseif (is_int($value)) {
                $value = (int) $value;
            } elseif (is_float($value) && floor($value) === $value) {
                $value = (int) $value;
            } else {
                return $default;
            }

            return max($min, $value);
        }

        private static function sanitize_bounded_integer_setting($value, $default, $min, $max)
        {
            $default = (int) $default;
            $min = (int) $min;
            $max = (int) $max;

            if ($min > $max) {
                $swap = $min;
                $min = $max;
                $max = $swap;
            }

            if ($default < $min || $default > $max) {
                $default = max($min, min($max, $default));
            }

            if (is_string($value)) {
                $value = trim($value);
                if ('' === $value || !preg_match('/^\d+$/', $value)) {
                    return $default;
                }
                $value = (int) $value;
            } elseif (is_int($value)) {
                $value = (int) $value;
            } else {
                return $default;
            }

            if ($value < $min || $value > $max) {
                return $default;
            }

            return $value;
        }

        private static function sanitize_bounded_number_setting($value, $default, $min, $max)
        {
            $default = (float) $default;
            $min = (float) $min;
            $max = (float) $max;

            if ($min > $max) {
                $swap = $min;
                $min = $max;
                $max = $swap;
            }

            if ($default < $min || $default > $max) {
                $default = max($min, min($max, $default));
            }

            if (is_string($value)) {
                $value = trim($value);
                if ('' === $value || !preg_match('/^\d+(?:\.\d+)?$/', $value)) {
                    return $default;
                }
                $value = (float) $value;
            } elseif (is_int($value) || is_float($value)) {
                $value = (float) $value;
            } else {
                return $default;
            }

            if ($value < $min || $value > $max) {
                return $default;
            }

            return (float) rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
        }

        private static function parse_textarea_setting($value)
        {
            $normalized = self::normalize_textarea_setting($value);
            if ('' === $normalized) {
                return array();
            }

            return array_values(array_unique(array_filter(array_map('trim', explode("\n", $normalized)))));
        }

        private static function manual_lcp_selector_line_is_image($line)
        {
            $line = trim((string) $line);
            if ('' === $line) {
                return false;
            }

            if (preg_match('/^image\s+\S+/i', $line)) {
                return true;
            }

            if (preg_match('#^(?:https?:)?//#i', $line) || preg_match('#^/#', $line)) {
                return true;
            }

            return (bool) preg_match('/\.(?:avif|webp|png|jpe?g|gif|svg)(?:[?#].*)?$/i', $line);
        }

        private static function normalize_manual_lcp_image_entry($line)
        {
            $line = trim((string) $line);
            if (preg_match('/^image\s+(.+)$/i', $line, $matches)) {
                $line = trim((string) $matches[1]);
            }

            return $line;
        }

        private static function split_manual_lcp_selector_setting($value)
        {
            $lines = self::parse_textarea_setting($value);
            $selectors = array();
            $images = array();

            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ('' === $line) {
                    continue;
                }

                if (self::manual_lcp_selector_line_is_image($line)) {
                    $image = self::normalize_manual_lcp_image_entry($line);
                    if ('' !== $image) {
                        $images[$image] = $image;
                    }
                    continue;
                }

                $selectors[$line] = $line;
            }

            return array(
                'selectors' => array_values($selectors),
                'images'    => array_values($images),
            );
        }


        private static function sanitize_object_cache_backend($value)
        {
            $value = strtolower(trim((string) $value));
            return in_array($value, array('redis', 'apcu', 'disk'), true) ? $value : 'redis';
        }

        private static function sanitize_object_cache_fallback_backend($value)
        {
            $value = strtolower(trim((string) $value));
            if ('none' === $value || 'runtime' === $value || '' === $value) {
                return 'none';
            }
            return in_array($value, array('apcu', 'disk'), true) ? $value : 'apcu';
        }

        private static function sanitize_homepage_css_bundle_mode($value)
        {
            $value = strtolower(trim((string) $value));
            return in_array($value, array('safe', 'aggressive', 'full'), true) ? $value : 'safe';
        }

        private static function sanitize_css_bundle_scope($value)
        {
            $value = strtolower(trim((string) $value));
            return in_array($value, array('homepage', 'shared', 'per-page'), true) ? $value : 'homepage';
        }

        private static function sanitize_redis_host($value)
        {
            $value = trim((string) $value);
            if ('' === $value) {
                return '127.0.0.1';
            }

            $value = preg_replace('/[\r\n\t\0\x0B]+/', '', $value);
            $value = trim((string) $value);
            if ('' === $value) {
                return '127.0.0.1';
            }

            if (strlen($value) > 255) {
                $value = substr($value, 0, 255);
            }

            return $value;
        }

        private static function sanitize_redis_username($value)
        {
            $value = trim((string) $value);
            if ('' === $value) {
                return '';
            }

            $value = preg_replace('/[\r\n\t\0\x0B]+/', '', $value);
            $value = trim((string) $value);
            if (strlen($value) > 128) {
                $value = substr($value, 0, 128);
            }

            return sanitize_text_field($value);
        }

        private static function sanitize_redis_database($value)
        {
            return self::sanitize_bounded_integer_setting($value, 0, 0, 15);
        }

        private static function sanitize_redis_prefix($value)
        {
            $value = trim((string) $value);
            if ('' === $value) {
                return '';
            }

            $value = preg_replace('/[^A-Za-z0-9:_\-]/', '', $value);
            $value = trim((string) $value, ':');

            return '' === $value ? '' : $value . ':';
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

        private static function sanitize_varnish_mode($value)
        {
            return ('admin' === strtolower(trim((string) $value))) ? 'admin' : 'http';
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
            $ports = apply_filters('ucwp_varnish_http_endpoint_ports', array(82, 6081));
            if (!is_array($ports)) {
                $ports = array(82, 6081);
            }

            $ports = array_values(array_unique(array_filter(array_map('intval', $ports))));
            return !empty($ports) ? $ports : array(82, 6081);
        }

        private static function normalize_varnish_compare_host($host)
        {
            $host = strtolower(trim((string) $host));
            $host = trim($host, "[] \t\n\r\0\x0B.");
            if (0 === strpos($host, 'www.')) {
                $host = substr($host, 4);
            }
            return $host;
        }

        private static function get_varnish_site_host_candidates()
        {
            $hosts = array();
            $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
            if ($home_host) {
                $hosts[] = self::normalize_varnish_compare_host($home_host);
            }

            foreach (array('HTTP_HOST', 'SERVER_NAME', 'SERVER_ADDR', 'LOCAL_ADDR') as $key) {
                $server_value = function_exists('ucwp_server_value') ? ucwp_server_value($key) : '';
                if ('' !== $server_value) {
                    $hosts[] = self::normalize_varnish_compare_host(sanitize_text_field($server_value));
                }
            }

            $expanded = array();
            foreach ($hosts as $host) {
                if ('' === $host) {
                    continue;
                }
                $expanded[] = $host;
                $expanded[] = 'www.' . $host;
            }

            return array_values(array_unique(array_filter($expanded)));
        }

        private static function get_varnish_http_endpoint_block_message($host, $port)
        {
            $host = self::normalize_varnish_compare_host($host);
            $port = (int) $port;

            if ('' === $host || $port <= 0) {
                return self::maybe_translate('Invalid Varnish HTTP endpoint. Use host:port, for example 127.0.0.1:82.');
            }

            if (in_array($host, self::get_varnish_site_host_candidates(), true) && in_array($port, array(80, 443, 8443), true)) {
                return self::maybe_translate_sprintf('Blocked unsafe Varnish endpoint %1$s:%2$d because it points to the public WordPress frontend. Use a configured Varnish listener such as 127.0.0.1:82, varnish.internal:82, or Admin mode such as 127.0.0.1:6082.', $host, $port);
            }

            return '';
        }

        private static function validate_varnish_http_endpoint($terminal)
        {
            $terminal = trim((string) $terminal);
            if ('' === $terminal) {
                return array('valid' => false, 'message' => self::maybe_translate('Empty Varnish HTTP endpoint. Use host:port, for example 127.0.0.1:82 or varnish.example.com:8080.'));
            }

            list($host, $port) = self::parse_varnish_terminal($terminal);
            $message = self::get_varnish_http_endpoint_block_message($host, $port);
            if ('' !== $message) {
                return array('valid' => false, 'message' => $message, 'host' => $host, 'port' => $port);
            }

            return array('valid' => true, 'message' => '', 'host' => $host, 'port' => $port);
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

        private static function validate_varnish_settings(array $settings)
        {
            if (empty($settings['varnishCliEnabled'])) {
                return true;
            }

            $mode = self::sanitize_varnish_mode($settings['varnishCliMode'] ?? 'http');
            if ('http' !== $mode) {
                return true;
            }

            $diagnostics = self::get_varnish_endpoint_diagnostics($settings['varnishCliServers'] ?? '', $mode);
            if (!empty($diagnostics['unsafe'])) {
                $message = !empty($diagnostics['messages'][0]) ? (string) $diagnostics['messages'][0] : self::maybe_translate('Unsafe Varnish HTTP endpoint blocked.');
                return new WP_Error('ucwp_unsafe_varnish_http_endpoint', $message);
            }

            return true;
        }

        private static function get_known_cache_plugin_signatures()
        {
            return array(
                'w3-total-cache' => array('label' => __('W3 Total Cache', 'ultracache'), 'markers' => array('W3 Total Cache', 'W3TC', 'w3-total-cache', 'w3tc_')),
                'wp-rocket' => array('label' => __('WP Rocket', 'ultracache'), 'markers' => array('WP Rocket', 'WP_ROCKET', 'rocket_clean_domain', 'wp-rocket')),
                'wp-super-cache' => array('label' => __('WP Super Cache', 'ultracache'), 'markers' => array('WP Super Cache', 'WPCACHEHOME', 'wp-cache-phase1', 'wp-super-cache')),
                'litespeed-cache' => array('label' => __('LiteSpeed Cache', 'ultracache'), 'markers' => array('LiteSpeed Cache', 'LSCWP', 'litespeed-cache', 'LiteSpeed_Cache')),
                'sg-cachepress' => array('label' => __('SiteGround Optimizer', 'ultracache'), 'markers' => array('SiteGround Optimizer', 'SG Optimizer', 'sg-cachepress', 'SiteGround_Optimizer')),
                'wp-fastest-cache' => array('label' => __('WP Fastest Cache', 'ultracache'), 'markers' => array('WP Fastest Cache', 'WpFastestCache', 'wp-fastest-cache')),
                'breeze' => array('label' => __('Breeze', 'ultracache'), 'markers' => array('Breeze', 'BREEZE', 'breeze-cache')),
                'redis-cache' => array('label' => __('Redis Object Cache', 'ultracache'), 'markers' => array('Redis Object Cache', 'Redis_Object_Cache', 'redis-cache', 'Rhubarb\\RedisCache')),
                'docket-cache' => array('label' => __('Docket Cache', 'ultracache'), 'markers' => array('Docket Cache', 'DocketCache', 'docket-cache')),
                'object-cache-pro' => array('label' => __('Object Cache Pro', 'ultracache'), 'markers' => array('Object Cache Pro', 'objectcache.pro', 'ObjectCachePro')),
                'memcached' => array('label' => __('Memcached Object Cache', 'ultracache'), 'markers' => array('Memcached', 'Memcache', 'memcached', 'memcache')),
                'powered-cache' => array('label' => __('Powered Cache', 'ultracache'), 'markers' => array('Powered Cache', 'powered-cache')),
                'cache-enabler' => array('label' => __('Cache Enabler', 'ultracache'), 'markers' => array('Cache Enabler', 'cache-enabler')),
                'autoptimize' => array('label' => __('Autoptimize', 'ultracache'), 'markers' => array('Autoptimize', 'autoptimize')),
            );
        }

        private static function detect_cache_dropin_owner($contents)
        {
            $contents = (string) $contents;
            if ('' === $contents) {
                return 'Unknown';
            }

            foreach (self::get_known_cache_plugin_signatures() as $signature) {
                $label = (string) ($signature['label'] ?? 'Unknown');
                $markers = isset($signature['markers']) && is_array($signature['markers']) ? $signature['markers'] : array();
                foreach ($markers as $marker) {
                    if ('' !== (string) $marker && false !== stripos($contents, (string) $marker)) {
                        return $label;
                    }
                }
            }

            return 'Unknown';
        }

        private static function get_cache_dropin_conflict_status()
        {
            $dropins = array();
            $detected = false;

            if (!defined('WP_CONTENT_DIR')) {
                return array(
                    'detected' => false,
                    'dropins' => array(),
                    'message' => '',
                );
            }

            $targets = array(
                'advanced-cache.php' => self::maybe_translate('Page cache drop-in'),
                'object-cache.php' => self::maybe_translate('Object cache drop-in'),
            );

            foreach ($targets as $basename => $label) {
                $path = trailingslashit(WP_CONTENT_DIR) . $basename;
                $exists = file_exists($path);
                $contents = $exists ? (string) ucwp_safe_file_get_contents($path, 'cache drop-in conflict detection', true) : '';
                $managed = $exists && false !== strpos($contents, 'UltraCache');
                $owner = $exists ? self::detect_cache_dropin_owner($contents) : '';
                $is_conflict = $exists && !$managed;

                if ($is_conflict) {
                    $detected = true;
                }

                $dropins[] = array(
                    'file' => $basename,
                    'label' => (string) $label,
                    'path' => $path,
                    'exists' => (bool) $exists,
                    'managed' => (bool) $managed,
                    'owner' => $exists ? $owner : '',
                    'removable' => (bool) $is_conflict,
                    'size' => $exists ? (int) max(0, (int) ucwp_safe_filesize($path, 'cache drop-in conflict size')) : 0,
                    'modified' => $exists ? (int) max(0, (int) ucwp_safe_filemtime($path, 'cache drop-in conflict mtime')) : 0,
                );
            }

            return array(
                'detected' => (bool) $detected,
                'dropins' => $dropins,
                'message' => $detected ? self::maybe_translate('Conflicting WordPress cache drop-ins detected. UltraCache can back them up and remove them if you choose.') : '',
            );
        }

        private static function get_active_cache_plugin_conflict_status()
        {
            $known = array(
                'w3-total-cache' => 'W3 Total Cache',
                'wp-rocket' => 'WP Rocket',
                'wp-super-cache' => 'WP Super Cache',
                'litespeed-cache' => 'LiteSpeed Cache',
                'sg-cachepress' => 'SiteGround Optimizer',
                'wp-fastest-cache' => 'WP Fastest Cache',
                'breeze' => 'Breeze',
                'redis-cache' => 'Redis Object Cache',
                'docket-cache' => 'Docket Cache',
                'object-cache-pro' => 'Object Cache Pro',
                'memcached' => 'Memcached Object Cache',
                'powered-cache' => 'Powered Cache',
                'cache-enabler' => 'Cache Enabler',
                'comet-cache' => 'Comet Cache',
                'hummingbird-performance' => 'Hummingbird',
                'nitropack' => 'NitroPack',
                'autoptimize' => 'Autoptimize',
                'wp-optimize' => 'WP-Optimize',
            );

            $active = array();
            $site_plugins = get_option('active_plugins', array());
            if (is_array($site_plugins)) {
                $active = array_merge($active, $site_plugins);
            }

            if (is_multisite()) {
                $network_plugins = get_site_option('active_sitewide_plugins', array());
                if (is_array($network_plugins)) {
                    $active = array_merge($active, array_keys($network_plugins));
                }
            }

            $items = array();
            foreach (array_unique(array_filter(array_map('strval', $active))) as $plugin_file) {
                $slug = strtolower(trim(strtok($plugin_file, '/')));
                if ('' === $slug || 'ultracache' === $slug || !isset($known[$slug])) {
                    continue;
                }

                $items[] = array(
                    'slug' => $slug,
                    'name' => $known[$slug],
                    'pluginFile' => $plugin_file,
                );
            }

            return array(
                'detected' => !empty($items),
                'items' => array_values($items),
                'message' => !empty($items) ? self::maybe_translate('Potential cache plugin conflict detected. Running multiple cache/performance plugins together can cause stale pages, purge loops, or object cache conflicts.') : '',
            );
        }

        private static function get_legacy_cache_conflict_status()
        {
            $option_names = array(
                'purge_varnish_action',
                'purge_varnish_expire',
                'varnish_bantype',
                'varnish_control_key',
                'varnish_control_terminal',
                'varnish_socket_timeout',
                'varnish_version',
                'vhp_varnish_debug',
                'w3x_varnish_cli_secret',
                'w3x_varnish_cli_timeout_ms',
                'w3x_varnish_http_servers',
                'w3tc_state',
            );

            $found_options = array();
            foreach ($option_names as $option_name) {
                if (false !== get_option($option_name, false)) {
                    $found_options[] = $option_name;
                }
            }

            $found_plugins = array();
            if (defined('WP_PLUGIN_DIR')) {
                foreach (array('w3-total-cache', 'w3tc-varnish-cli-helper') as $plugin_dir) {
                    if (file_exists(trailingslashit(WP_PLUGIN_DIR) . $plugin_dir)) {
                        $found_plugins[] = $plugin_dir;
                    }
                }
            }

            $dropin_conflicts = self::get_cache_dropin_conflict_status();
            $active_cache_plugins = self::get_active_cache_plugin_conflict_status();

            /*
             * Disabled/installed cache plugins and legacy options are advisory diagnostics only.
             * They must not create dashboard warnings unless an active cache plugin is detected
             * or a non-UltraCache WordPress drop-in is actually present/removable.
             */
            $detected = !empty($dropin_conflicts['detected']) || !empty($active_cache_plugins['detected']);

            return array(
                'detected' => (bool) $detected,
                'options'  => $found_options,
                'plugins'  => $found_plugins,
                'dropins'  => isset($dropin_conflicts['dropins']) && is_array($dropin_conflicts['dropins']) ? $dropin_conflicts['dropins'] : array(),
                'dropinConflictsDetected' => !empty($dropin_conflicts['detected']),
                'activeCachePlugins' => isset($active_cache_plugins['items']) && is_array($active_cache_plugins['items']) ? $active_cache_plugins['items'] : array(),
                'activeCachePluginsDetected' => !empty($active_cache_plugins['detected']),
                'message'  => $detected ? self::maybe_translate('Cache helper or active cache plugin conflicts detected. Review the details before enabling UltraCache Varnish or Object Cache.') : '',
            );
        }

        public static function remove_conflicting_cache_dropins()
        {
            if (!current_user_can('manage_options') || !current_user_can('activate_plugins')) {
                return array(
                    'success' => false,
                    'message' => self::maybe_translate('Removing conflicting cache drop-ins requires manage_options and activate_plugins permissions.'),
                    'removed' => array(),
                    'failed' => array(),
                    'backups' => array(),
                );
            }

            if (!defined('WP_CONTENT_DIR')) {
                return array(
                    'success' => false,
                    'message' => self::maybe_translate('WP_CONTENT_DIR is unavailable.'),
                    'removed' => array(),
                    'failed' => array(),
                    'backups' => array(),
                );
            }

            $status = self::get_cache_dropin_conflict_status();
            $dropins = isset($status['dropins']) && is_array($status['dropins']) ? $status['dropins'] : array();
            $timestamp = gmdate('Ymd-His');
            $backup_dir = trailingslashit(UCWP_CACHE_DIR) . 'backups/dropins/';
            $removed = array();
            $failed = array();
            $backups = array();

            foreach ($dropins as $dropin) {
                if (empty($dropin['removable']) || empty($dropin['file'])) {
                    continue;
                }

                $basename = basename((string) $dropin['file']);
                if (!in_array($basename, array('advanced-cache.php', 'object-cache.php'), true)) {
                    continue;
                }

                $path = trailingslashit(WP_CONTENT_DIR) . $basename;
                if (!file_exists($path)) {
                    continue;
                }

                $contents = (string) ucwp_safe_file_get_contents($path, 'remove conflicting cache drop-in verify', true);
                if (false !== strpos($contents, 'UltraCache')) {
                    $failed[] = array(
                        'file' => $basename,
                        'owner' => 'UltraCache',
                        'message' => self::maybe_translate('Skipped UltraCache-managed drop-in.'),
                    );
                    continue;
                }

                if (!ucwp_safe_mkdir($backup_dir, 0755, true, 'cache drop-in conflict backup mkdir')) {
                    $failed[] = array(
                        'file' => $basename,
                        'owner' => self::detect_cache_dropin_owner($contents),
                        'message' => self::maybe_translate('Could not create backup directory.'),
                    );
                    continue;
                }

                $backup_file = $backup_dir . $timestamp . '-' . $basename;
                if (!ucwp_safe_copy($path, $backup_file, 'cache drop-in conflict backup copy')) {
                    $failed[] = array(
                        'file' => $basename,
                        'owner' => self::detect_cache_dropin_owner($contents),
                        'message' => self::maybe_translate('Could not back up drop-in.'),
                    );
                    continue;
                }

                if (!ucwp_safe_unlink($path, 'cache drop-in conflict remove')) {
                    $failed[] = array(
                        'file' => $basename,
                        'owner' => self::detect_cache_dropin_owner($contents),
                        'backup' => $backup_file,
                        'message' => self::maybe_translate('Backup created, but removal failed.'),
                    );
                    continue;
                }

                $removed[] = array(
                    'file' => $basename,
                    'owner' => self::detect_cache_dropin_owner($contents),
                    'backup' => $backup_file,
                );
                $backups[] = $backup_file;
            }

            $success = empty($failed);
            if (empty($removed) && empty($failed)) {
                $message = self::maybe_translate('No conflicting cache helpers were found.');
            } elseif ($success) {
                $message = self::maybe_translate_sprintf('Removed %d conflicting cache helper(s).', count($removed));
            } else {
                $message = self::maybe_translate_sprintf('Removed %d cache helper(s); %d failed.', count($removed), count($failed));
            }

            return array(
                'success' => (bool) $success,
                'message' => $message,
                'removed' => $removed,
                'failed' => $failed,
                'backups' => $backups,
                'diagnostics' => self::get_dashboard_diagnostics(),
                'stats' => self::get_engine_stats(),
            );
        }

        private static function sanitize_varnish_servers_string($value, $mode = 'http')
        {
            $mode = self::sanitize_varnish_mode($mode);

            if (is_array($value)) {
                $value = implode("
", array_map('strval', $value));
            }

            $value = str_replace(array("
", "
", ",", ";", "	"), array("
", "
", "
", "
", "
"), (string) $value);
            $servers = preg_split('/\s+/', $value);
            if (!is_array($servers)) {
                return '';
            }

            $normalized = array();
            foreach ($servers as $server) {
                $server = trim((string) $server);
                if ('' === $server) {
                    continue;
                }

                $server = preg_replace('#^[a-z]+://#i', '', $server);
                $server = preg_replace('#/.*$#', '', $server);
                $server = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $server);
                if ('' === $server) {
                    continue;
                }

                $normalized[] = $server;
            }

            if (empty($normalized)) {
                $normalized[] = ('admin' === $mode) ? self::get_default_varnish_admin_endpoint() : self::get_default_varnish_http_endpoint();
            }

            return implode("
", array_values(array_unique($normalized)));
        }


        private static function sanitize_media_output_mode($value)
        {
            $value = strtolower(trim((string) $value));
            return in_array($value, array('auto', 'avif', 'webp'), true) ? $value : 'auto';
        }

        private static function normalize_boolean_setting_value($value, $default = false)
        {
            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return 0 !== (int) $value;
            }

            if (is_string($value)) {
                $normalized = strtolower(trim($value));
                if ('' === $normalized) {
                    return (bool) $default;
                }

                if (in_array($normalized, array('1', 'true', 'yes', 'on', 'enabled'), true)) {
                    return true;
                }

                if (in_array($normalized, array('0', 'false', 'no', 'off', 'disabled'), true)) {
                    return false;
                }
            }

            if (null === $value) {
                return (bool) $default;
            }

            return !empty($value);
        }

        public static function sanitize_dashboard_settings(array $settings, $validate_support = true)
        {
            $raw_settings = $settings;
            $defaults = self::get_dashboard_defaults();
            $settings = wp_parse_args($settings, $defaults);

            // Canonicalize every boolean dashboard setting before any runtime
            // mapping or !empty() checks. This prevents imported/CLI/direct
            // option values such as "false", "0", "off", or null from
            // being treated as enabled.
            foreach ($defaults as $setting_key => $default_value) {
                if (is_bool($default_value)) {
                    $settings[$setting_key] = self::normalize_boolean_setting_value(
                        $settings[$setting_key] ?? $default_value,
                        $default_value
                    );
                }
            }

            $settings['cronWarmPagesPerMinute']    = max(0, min(600, absint($settings['cronWarmPagesPerMinute'])));
            $settings['warmMenuLocation']          = sanitize_key((string) $settings['warmMenuLocation']);
            $settings['warmMenuDepth']             = in_array((string) $settings['warmMenuDepth'], array('1', '2', '3', 'all'), true) ? (string) $settings['warmMenuDepth'] : '';
            $warm_full_site_sources = preg_split('/[\r\n,]+/', (string) $settings['warmFullSiteSources']);
            $warm_full_site_allowed = self::get_full_site_warm_source_order_keys();
            $warm_full_site_requested = array();
            foreach ((array) $warm_full_site_sources as $warm_full_site_source) {
                $warm_full_site_source = sanitize_key((string) $warm_full_site_source);
                if ('' !== $warm_full_site_source && in_array($warm_full_site_source, $warm_full_site_allowed, true)) {
                    $warm_full_site_requested[$warm_full_site_source] = true;
                }
            }
            $warm_full_site_clean = array();
            foreach ($warm_full_site_allowed as $warm_full_site_source) {
                if (isset($warm_full_site_requested[$warm_full_site_source])) {
                    $warm_full_site_clean[$warm_full_site_source] = true;
                }
            }
            $settings['warmFullSiteSources']       = implode(',', array_keys($warm_full_site_clean));
            $settings['scheduledWarmLimit']        = max(1, min(5000, absint($settings['scheduledWarmLimit'])));
            $settings['varnishCliTimeoutSeconds']  = max(1, min(15, absint($settings['varnishCliTimeoutSeconds'])));
            $settings['cacheFreshTtlMinutes']      = self::sanitize_positive_integer_setting($settings['cacheFreshTtlMinutes'], $defaults['cacheFreshTtlMinutes'], 1);
            $settings['cacheMaxStaleMinutes']      = max((int) $settings['cacheFreshTtlMinutes'], self::sanitize_positive_integer_setting($settings['cacheMaxStaleMinutes'], $defaults['cacheMaxStaleMinutes'], 1));
            $settings['cacheExceptionPaths']       = self::sanitize_excluded_paths_setting($settings['cacheExceptionPaths']);
            $settings['cacheExceptionQueryArgs']   = self::sanitize_setting_key_list($settings['cacheExceptionQueryArgs']);
            $settings['cacheQueryStringAllowlist'] = self::sanitize_setting_key_list($settings['cacheQueryStringAllowlist']);
            $settings['cacheSafeTrackingCookiesEnabled'] = self::normalize_boolean_setting_value($settings['cacheSafeTrackingCookiesEnabled'] ?? false, false);
            $settings['safeTrackingCookieList']    = self::sanitize_cookie_pattern_setting($settings['safeTrackingCookieList']);
            $settings['unsafeCacheCookieList']     = self::sanitize_cookie_pattern_setting($settings['unsafeCacheCookieList']);
            $settings['jsFullSiteStrategy'] = in_array((string) ($settings['jsFullSiteStrategy'] ?? ''), array('off', 'delay_all'), true) ? (string) $settings['jsFullSiteStrategy'] : $defaults['jsFullSiteStrategy'];
            $settings['delayedLocalJsAutoStart'] = in_array((string) ($settings['delayedLocalJsAutoStart'] ?? $defaults['delayedLocalJsAutoStart']), array('interaction', 'custom'), true) ? (string) $settings['delayedLocalJsAutoStart'] : $defaults['delayedLocalJsAutoStart'];
            $settings['delayedLocalJsAutoStartSeconds'] = self::sanitize_bounded_number_setting($settings['delayedLocalJsAutoStartSeconds'] ?? $defaults['delayedLocalJsAutoStartSeconds'], $defaults['delayedLocalJsAutoStartSeconds'], 0.1, 9);
            $settings['deferJsForceList']         = self::normalize_textarea_setting($settings['deferJsForceList']);
            $settings['deferJsExcludeList']       = self::merge_textarea_settings($settings['deferJsExcludeList'], $settings['delayNonCriticalJsExcludeList']);
            // Existing installs keep their saved visible JS Delay / Defer Exclusions.
            // Fresh installs receive the safe dependency-floor defaults in the
            // visible textarea; there are still no hidden safe-stage exclusions.
            $settings['deferJsExcludeList'] = self::normalize_textarea_setting($settings['deferJsExcludeList']);
            $settings['delayNonCriticalJsExcludeList'] = '';
            $settings['delaySafeThirdPartyJsPatterns'] = self::normalize_textarea_setting($settings['delaySafeThirdPartyJsPatterns']);
            $settings['delayFunctionalThirdPartyJsPatterns'] = self::normalize_textarea_setting($settings['delayFunctionalThirdPartyJsPatterns']);
            $settings['homepageCssBundleExcludeList'] = self::normalize_textarea_setting($settings['homepageCssBundleExcludeList']);
            $settings['delayIconFontsList'] = self::normalize_textarea_setting($settings['delayIconFontsList']);
            $settings['delayIconFontsExcludeList'] = self::normalize_textarea_setting($settings['delayIconFontsExcludeList']);
            $settings['homepageCssBundleMode'] = self::sanitize_homepage_css_bundle_mode($settings['homepageCssBundleMode']);
            $settings['cssBundleScope'] = self::sanitize_css_bundle_scope($settings['cssBundleScope'] ?? 'homepage');
            if (!empty($settings['pageAsyncBundleOnEntryEnabled'])) {
                $settings['pageCssBundleOnEntryEnabled'] = false;
            }
            $settings['asyncCssExcludeList']       = self::normalize_textarea_setting($settings['asyncCssExcludeList']);
            $settings['delayNonCriticalJsExcludeList'] = self::normalize_textarea_setting($settings['delayNonCriticalJsExcludeList']);
            $settings['assetCleanupExcludeList'] = self::normalize_textarea_setting($settings['assetCleanupExcludeList']);
            $settings['googleFontsAdditionalScanUrls'] = self::normalize_textarea_setting($settings['googleFontsAdditionalScanUrls']);
            $settings['manualLcpHeroSelector'] = self::normalize_textarea_setting($settings['manualLcpHeroSelector']);
            unset($settings['lcpImagePriorityOverride']);
            $settings['criticalResourcePreloadList'] = self::normalize_textarea_setting($settings['criticalResourcePreloadList']);
            $settings['criticalRequestChainDelayList'] = self::normalize_textarea_setting($settings['criticalRequestChainDelayList']);
            $settings['mediaOutputMode']           = self::sanitize_media_output_mode($settings['mediaOutputMode']);
            $settings['objectCacheBackend']        = self::sanitize_object_cache_backend($settings['objectCacheBackend']);
            if ('apcu' === $settings['objectCacheBackend']) {
                $settings['flushAllIncludeApcu'] = true;
            }
            $settings['objectCacheFallbackBackend'] = self::sanitize_object_cache_fallback_backend($settings['objectCacheFallbackBackend'] ?? 'apcu');
            $settings['redisHost']                 = self::sanitize_redis_host($settings['redisHost']);
            $settings['redisPort']                 = self::sanitize_bounded_integer_setting($settings['redisPort'], $defaults['redisPort'], 1, 65535);
            $settings['redisUsername']             = self::sanitize_redis_username($settings['redisUsername'] ?? '');
            $settings['redisPassword']             = trim((string) $settings['redisPassword']);
            $settings['redisDatabase']             = self::sanitize_redis_database($settings['redisDatabase']);
            $settings['redisPrefix']               = self::sanitize_redis_prefix($settings['redisPrefix']);
            $settings['redisUseTls']               = !empty($settings['redisUseTls']);
            $settings['redisPersistent']           = !empty($settings['redisPersistent']);
            $settings['redisConnectTimeoutMs']     = self::sanitize_bounded_integer_setting($settings['redisConnectTimeoutMs'], $defaults['redisConnectTimeoutMs'], 50, 15000);
            $settings['redisReadTimeoutMs']        = self::sanitize_bounded_integer_setting($settings['redisReadTimeoutMs'], $defaults['redisReadTimeoutMs'], 50, 15000);
            $settings['varnishCliMode']            = self::sanitize_varnish_mode($settings['varnishCliMode']);
            $settings['varnishCliServers']         = self::sanitize_varnish_servers_string($settings['varnishCliServers'], $settings['varnishCliMode']);
            $settings['varnishCliKey']             = trim((string) $settings['varnishCliKey']);
            $settings['varnishCliMethod']          = ('PURGE' === strtoupper(trim((string) $settings['varnishCliMethod']))) ? 'PURGE' : 'BAN';

            unset($settings['frontendSafeModeEnabled']);

            if ($validate_support) {
                ucwp_request_profile_checkpoint('sanitize_settings_support_checks_not_mutating_values');
            } else {
                ucwp_request_profile_checkpoint('sanitize_settings_support_checks_skipped_runtime');
            }

            $settings['cronWarmStartAfterCleanup'] = !empty($settings['cronWarmStartAfterCleanup']);
            $settings['cronWarmStartAfterManualPurge'] = !empty($settings['cronWarmStartAfterManualPurge']);
            $settings['uninstallCleanupPolicy'] = self::sanitize_uninstall_cleanup_policy($settings['uninstallCleanupPolicy'] ?? $defaults['uninstallCleanupPolicy']);

            // Keep the public settings payload canonical. Stored options may still contain
            // obsolete keys, but they must not leak back to CLI, REST, exports, or
            // runtime settings after sanitization.
            $settings = array_intersect_key($settings, $defaults);

            return $settings;
        }

        private static function setting_was_enabled_by_patch(array $current, array $previous, $key)
        {
            return !empty($current[$key]) && empty($previous[$key]);
        }

        private static function validate_critical_settings_support_before_persist(array $current, array $previous)
        {
            if (self::setting_was_enabled_by_patch($current, $previous, 'brotliEnabled')) {
                $compression_support = self::get_compression_support_status();
                if (empty($compression_support['brotli'])) {
                    return new WP_Error('ucwp_brotli_unavailable', self::maybe_translate('Brotli compression is not available on this server, so Brotli Cache Compression was not enabled.'));
                }

                $frontend_compression = self::get_frontend_compression_probe_status(false);
                if (!empty($frontend_compression['brotli']) || !empty($frontend_compression['brokenBrotli'])) {
                    return new WP_Error('ucwp_brotli_frontend_conflict', self::maybe_translate('Brotli compression appears to be handled or conflicted before WordPress, so Brotli Cache Compression was not enabled.'));
                }
            }

            if (self::setting_was_enabled_by_patch($current, $previous, 'gzipEnabled')) {
                $compression_support = self::get_compression_support_status();
                if (empty($compression_support['gzip'])) {
                    return new WP_Error('ucwp_gzip_unavailable', self::maybe_translate('Gzip compression is not available on this server, so Gzip Cache Compression was not enabled.'));
                }

                $frontend_compression = self::get_frontend_compression_probe_status(false);
                if (!empty($frontend_compression['gzip']) || !empty($frontend_compression['brokenGzip'])) {
                    return new WP_Error('ucwp_gzip_frontend_conflict', self::maybe_translate('Gzip compression appears to be handled or conflicted before WordPress, so Gzip Cache Compression was not enabled.'));
                }
            }

            if (self::setting_was_enabled_by_patch($current, $previous, 'objectCacheEnabled')) {
                $object_cache_support = self::get_object_cache_support_status(true);
                if (empty($object_cache_support['available'])) {
                    $message = !empty($object_cache_support['message']) ? (string) $object_cache_support['message'] : self::maybe_translate('Object Cache cannot be enabled because the UltraCache object-cache drop-in helper is unavailable.');
                    return new WP_Error('ucwp_object_cache_unavailable', $message);
                }
            }

            if (
                self::setting_was_enabled_by_patch($current, $previous, 'mediaOptimizationEnabled')
                || self::setting_was_enabled_by_patch($current, $previous, 'mediaGenerateOnUploadEnabled')
                || self::setting_was_enabled_by_patch($current, $previous, 'mediaGenerateOnDemandEnabled')
            ) {
                $media_support = self::get_media_support_status();
                if (empty($media_support['supported'])) {
                    return new WP_Error('ucwp_media_optimization_unavailable', self::maybe_translate('Media optimization is not available on this server, so the media optimization setting was not enabled.'));
                }
            }

            if (self::setting_was_enabled_by_patch($current, $previous, 'varnishCliEnabled')) {
                $varnish_support = self::get_varnish_support_status();
                if (empty($varnish_support['available'])) {
                    $message = !empty($varnish_support['message']) ? (string) $varnish_support['message'] : self::maybe_translate('Varnish integration is not available on this server, so Varnish was not enabled.');
                    return new WP_Error('ucwp_varnish_unavailable', $message);
                }
            }

            return true;
        }

        public static function reset_settings_cache()
        {
            self::$dashboard_settings_cache = null;
            self::$settings_cache = null;
            delete_transient('ultracache_frontend_compression_probe_v1');
            delete_transient('ultracache_object_cache_support_status_v1');
            delete_transient('ultracache_media_support_status_v3');
            delete_transient('ultracache_gd_avif_encode_probe_v2');
            delete_transient('ultracache_gd_webp_encode_probe_v2');
            delete_transient('ultracache_media_queue_init_maintenance_v1');
            ucwp_reset_loopback_ssl_status();

            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'reset_settings_cache')) {
                Ultra_Cache_Object_Cache_Manager::reset_settings_cache();
            }
        }

        public static function get_dashboard_settings()
        {
            ucwp_request_profile_checkpoint('dashboard_settings_start');
            if (null !== self::$dashboard_settings_cache) {
                ucwp_request_profile_checkpoint('dashboard_settings_cache_hit');
                return self::$dashboard_settings_cache;
            }

            ucwp_request_profile_checkpoint('dashboard_settings_before_get_option');
            $saved = get_option(UCWP_SETTINGS_KEY, array());
            ucwp_request_profile_checkpoint('dashboard_settings_after_get_option', array(
                'raw_is_array' => is_array($saved) ? 'true' : 'false',
                'raw_count' => is_array($saved) ? count($saved) : 0,
            ));
            $raw_saved = is_array($saved) ? $saved : array();
            ucwp_request_profile_checkpoint('dashboard_settings_before_sanitize');
            $sanitized = self::sanitize_dashboard_settings($raw_saved);
            // Dashboard reads must never create or migrate runtime secret files.
            // Secrets are written only by explicit non-empty admin save inputs.
            $runtime_redis_password = self::get_runtime_redis_password();
            if ('' !== $runtime_redis_password) {
                $sanitized['redisPassword'] = $runtime_redis_password;
            }
            $runtime_varnish_secret = self::get_runtime_varnish_admin_secret();
            if ('' !== $runtime_varnish_secret) {
                $sanitized['varnishCliKey'] = $runtime_varnish_secret;
            }
            ucwp_request_profile_checkpoint('dashboard_settings_after_sanitize', array(
                'sanitized_count' => is_array($sanitized) ? count($sanitized) : 0,
            ));

            // Dashboard settings reads are intentionally side-effect free.
            // Canonical storage cleanup happens on explicit saves/migrations only,
            // so status polling cannot turn into wp_options write traffic.
            ucwp_request_profile_checkpoint('dashboard_settings_read_only_canonical_skip');

            self::$dashboard_settings_cache = $sanitized;

            ucwp_request_profile_checkpoint('dashboard_settings_end');
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
            $saved = get_option(UCWP_SETTINGS_KEY, array());
            $raw_saved = is_array($saved) ? $saved : array();
            $sanitized = self::sanitize_dashboard_settings($raw_saved, false);

            // Runtime reads must hydrate secret values, but they must not run
            // dashboard support probes or write the canonical option back.
            $runtime_redis_password = self::get_runtime_redis_password();
            if ('' !== $runtime_redis_password) {
                $sanitized['redisPassword'] = $runtime_redis_password;
            }

            $runtime_varnish_secret = self::get_runtime_varnish_admin_secret();
            if ('' !== $runtime_varnish_secret) {
                $sanitized['varnishCliKey'] = $runtime_varnish_secret;
            }

            return $sanitized;
        }

        public static function get_dashboard_settings_for_client()
        {
            $settings = self::get_dashboard_settings();
            $flag_map = self::get_secret_configuration_flag_map();

            foreach (self::get_secret_setting_keys() as $key) {
                $flag = isset($flag_map[$key]) ? $flag_map[$key] : '';
                if ('' !== $flag) {
                    $settings[$flag] = ('' !== trim((string) ($settings[$key] ?? '')));
                }
                $settings[$key] = '';
            }

            return $settings;
        }

        public static function get_dashboard_defaults_for_client()
        {
            $settings = self::sanitize_dashboard_settings(self::get_dashboard_defaults());
            $flag_map = self::get_secret_configuration_flag_map();

            foreach (self::get_secret_setting_keys() as $key) {
                $flag = isset($flag_map[$key]) ? $flag_map[$key] : '';
                if ('' !== $flag) {
                    $settings[$flag] = false;
                }
                $settings[$key] = '';
            }

            return $settings;
        }

        public static function get_settings()
        {
            ucwp_request_profile_settings_checkpoint('wp_get_settings_start');
            if (null !== self::$settings_cache) {
                ucwp_request_profile_settings_checkpoint('wp_get_settings_cache_hit');
                return self::$settings_cache;
            }

            ucwp_request_profile_settings_checkpoint('wp_get_settings_before_runtime_dashboard_settings');
            $ui = self::get_runtime_dashboard_settings();
            ucwp_request_profile_settings_checkpoint('wp_get_settings_after_runtime_dashboard_settings', array(
                'dashboard_settings_count' => is_array($ui) ? count($ui) : 0,
            ));

            ucwp_request_profile_settings_checkpoint('wp_get_settings_before_runtime_textareas');
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
            ucwp_request_profile_settings_checkpoint('wp_get_settings_after_runtime_textareas', array(
                'excluded_paths_count' => count($excluded_paths),
                'excluded_query_args_count' => count($excluded_query_args),
                'query_allowlist_count' => count($query_allowlist),
                'safe_tracking_cookie_count' => count($safe_tracking_cookie_patterns),
                'unsafe_cache_cookie_count' => count($unsafe_cache_cookie_patterns),
            ));
            $defer_js_enabled = !empty($ui['deferJsEnabled']);
            $js_full_site_strategy = isset($ui['jsFullSiteStrategy']) && in_array((string) $ui['jsFullSiteStrategy'], array('off', 'delay_all'), true) ? (string) $ui['jsFullSiteStrategy'] : 'off';
            $defer_all_js_enabled = false;
            $delay_all_js_enabled = ('delay_all' === $js_full_site_strategy);
            $delay_safe_third_party_js_enabled = !empty($ui['delaySafeThirdPartyJsEnabled']);
            $delay_functional_third_party_js_enabled = !empty($ui['delayFunctionalThirdPartyJsEnabled']);
            $delay_all_third_party_js_enabled = !empty($ui['delayAllThirdPartyJsEnabled']);
            $delay_non_critical_js_enabled = !empty($ui['delayNonCriticalJsEnabled']);
            $defer_stage_aggressive = $delay_non_critical_js_enabled;
            $defer_stage_balanced = $delay_safe_third_party_js_enabled || $delay_functional_third_party_js_enabled || $delay_all_third_party_js_enabled || $defer_stage_aggressive;
            $defer_stage_safe = $defer_js_enabled || $defer_all_js_enabled || $delay_all_js_enabled || $defer_stage_balanced;
            $manual_lcp_selector_split = self::split_manual_lcp_selector_setting($ui['manualLcpHeroSelector'] ?? '');
            $defaults = self::get_dashboard_defaults();

            self::$settings_cache = array(
                'enabled'                      => !empty($ui['pageCacheEnabled']),
                'object_cache_enabled'         => !empty($ui['objectCacheEnabled']),
                'object_cache_backend'         => self::sanitize_object_cache_backend($ui['objectCacheBackend']),
                'object_cache_fallback_backend'=> self::sanitize_object_cache_fallback_backend($ui['objectCacheFallbackBackend'] ?? 'apcu'),
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
                'js_full_site_strategy'       => $js_full_site_strategy,
                'defer_all_js'                 => $defer_all_js_enabled,
                'delay_all_js'                 => $delay_all_js_enabled,
                'delayed_local_js_auto_start' => in_array((string) ($ui['delayedLocalJsAutoStart'] ?? 'custom'), array('interaction', 'custom'), true) ? (string) ($ui['delayedLocalJsAutoStart'] ?? 'custom') : 'custom',
                'delayed_local_js_auto_start_seconds' => self::sanitize_bounded_number_setting($ui['delayedLocalJsAutoStartSeconds'] ?? 1, 1, 0.1, 9),
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
                'homepage_css_bundle_exclude_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['homepageCssBundleExcludeList'])),
                'delay_icon_fonts'            => !empty($ui['delayIconFontsEnabled']),
                'delay_icon_fonts_auto_detect' => !empty($ui['delayIconFontsAutoDetectEnabled']),
                'delay_icon_fonts_list'       => self::parse_textarea_setting(self::normalize_textarea_setting($ui['delayIconFontsList'])),
                'delay_icon_fonts_exclude_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['delayIconFontsExcludeList'])),
                'homepage_css_bundle_mode'    => self::sanitize_homepage_css_bundle_mode($ui['homepageCssBundleMode']),
                'css_bundle_scope'            => self::sanitize_css_bundle_scope($ui['cssBundleScope'] ?? 'homepage'),
                'page_css_bundle_on_entry'    => !empty($ui['pageCssBundleOnEntryEnabled']) && empty($ui['pageAsyncBundleOnEntryEnabled']),
                'page_css_bundle_async_on_entry' => !empty($ui['pageAsyncBundleOnEntryEnabled']),
                'slider_safe_mode'            => !empty($ui['sliderSafeModeEnabled']),
                'cls_dimensions'               => !empty($ui['clsDimensionsEnabled']),
                'async_css'                    => !empty($ui['asyncCssEnabled']),
                'async_css_exclude_list'       => self::parse_textarea_setting(self::normalize_textarea_setting($ui['asyncCssExcludeList'])),
                'aggressive_async_css'         => !empty($ui['aggressiveAsyncCssEnabled']),
                'delay_non_critical_js'        => $delay_non_critical_js_enabled,
                'delay_non_critical_js_aggressive' => $defer_stage_aggressive,
                'delay_non_critical_js_exclude_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['deferJsExcludeList'])),
                'lcp_image_priority'           => !empty($ui['lcpImagePriorityEnabled']),
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
                'asset_cleanup_exclude_list'   => self::parse_textarea_setting(self::normalize_textarea_setting($ui['assetCleanupExcludeList'])),
                'google_fonts_swap'            => !empty($ui['googleFontsSwapEnabled']),
                'google_fonts_local_optimization' => !empty($ui['googleFontsLocalOptimizationEnabled']),
                'google_fonts_additional_scan_urls' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['googleFontsAdditionalScanUrls'] ?? '')),
                'self_hosted_font_css_optimization' => !empty($ui['selfHostedFontCssOptimizationEnabled']),
                'self_hosted_font_runtime_rewrite' => !empty($ui['selfHostedFontRuntimeRewriteEnabled']),
                'speculation_rules_enabled'    => !empty($ui['speculationRulesEnabled']),
                'browser_cache_rules'          => !empty($ui['browserCacheRulesEnabled']),
                'varnish_cli_enabled'          => !empty($ui['varnishCliEnabled']),
                'varnish_cli_mode'             => self::sanitize_varnish_mode($ui['varnishCliMode']),
                'varnish_cli_servers'          => self::sanitize_varnish_servers_string($ui['varnishCliServers'], self::sanitize_varnish_mode($ui['varnishCliMode'])),
                'varnish_cli_key'              => trim((string) $ui['varnishCliKey']),
                'varnish_cli_timeout_seconds'  => max(1, min(15, absint($ui['varnishCliTimeoutSeconds']))),
                'varnish_cli_method'           => ('PURGE' === strtoupper(trim((string) $ui['varnishCliMethod']))) ? 'PURGE' : 'BAN',
                'media_optimization_enabled'   => !empty($ui['mediaOptimizationEnabled']),
                'media_generate_on_upload'     => !empty($ui['mediaGenerateOnUploadEnabled']),
                'media_generate_on_demand'     => !empty($ui['mediaGenerateOnDemandEnabled']),
                'media_output_mode'            => self::sanitize_media_output_mode($ui['mediaOutputMode']),
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

            ucwp_request_profile_settings_checkpoint('wp_get_settings_after_runtime_map', array(
                'settings_count' => count(self::$settings_cache),
            ));
            return self::$settings_cache;
        }


    }
}
