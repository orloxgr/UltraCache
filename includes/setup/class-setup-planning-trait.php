<?php
/**
 * Read-only setup detection and planning helpers.
 *
 * This layer deliberately does not persist UltraCache settings and does not
 * execute live capability probes. It describes the current environment and
 * produces a deterministic plan that later setup workflows can verify/apply.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Setup_Planning_Trait
{
    /**
     * Build the current read-only setup plan.
     *
     * @return array<string,mixed>
     */
    public static function get_setup_plan()
    {
        $settings = self::get_dashboard_settings();
        $server = self::get_setup_server_capability();
        $object_cache = self::get_setup_object_cache_capability($settings);
        $compression = self::get_setup_compression_capability();
        $media = self::get_setup_media_capability();
        $integrations = self::get_setup_integration_capability();
        $site = self::get_setup_site_capability($settings);
        $external_cache = self::get_setup_external_cache_capability($settings, $server);

        return array(
            'success' => true,
            'schemaVersion' => 2,
            'readOnly' => true,
            'generatedAt' => time(),
            'version' => defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : '',
            'capabilities' => array(
                'server' => $server,
                'objectCache' => $object_cache,
                'compression' => $compression,
                'media' => $media,
                'integrations' => $integrations,
                'externalCache' => $external_cache,
                'site' => $site,
            ),
            'recommendations' => array(
                'pageCache' => array(
                    'status' => 'recommended',
                    'enabled' => true,
                ),
                'objectCache' => array(
                    'status' => !empty($object_cache['configured']) ? 'preserve' : (!empty($object_cache['recommendedBackend']) ? 'recommended' : 'probe_required'),
                    'enabled' => !empty($object_cache['configured']) || !empty($object_cache['recommendedBackend']),
                    'backend' => !empty($object_cache['configured']) ? (string) ($object_cache['currentBackend'] ?? 'external') : (string) ($object_cache['recommendedBackend'] ?? ''),
                    'fallbackBackend' => !empty($object_cache['configured']) ? (string) ($object_cache['currentFallbackBackend'] ?? 'none') : (string) ($object_cache['recommendedFallbackBackend'] ?? 'none'),
                    'verification' => !empty($object_cache['configured'])
                        ? (!empty($object_cache['managedByUltraCache']) ? 'runtime_verify_only' : 'external_preserve')
                        : 'live_probe_required',
                    'preserveExisting' => !empty($object_cache['configured']),
                    'managedByUltraCache' => !empty($object_cache['managedByUltraCache']),
                ),
                'compression' => array(
                    'status' => !empty($compression['currentProbe']['decisionReady']) ? 'known' : 'probe_required',
                    'mode' => (string) ($compression['recommendedMode'] ?? 'probe'),
                    'verification' => !empty($compression['currentProbe']['decisionReady']) ? 'current_probe_available' : 'live_probe_required',
                ),
                'media' => array(
                    'status' => 'probe_required',
                    'formatCandidate' => (string) ($media['recommendedFormatCandidate'] ?? 'none'),
                    'fallbackFormatCandidate' => (string) ($media['recommendedFallbackFormatCandidate'] ?? 'none'),
                    'uploadConversion' => 'enable_after_conversion_test',
                    'maxUploadSide' => 1920,
                    'quality' => 'compact',
                    'verification' => 'conversion_test_required',
                ),
                'fonts' => array(
                    'status' => 'recommended',
                    'localGoogleFontsOptimization' => true,
                    'fontMixCssBundle' => true,
                    'delayIconFonts' => true,
                ),
                'integrations' => array(
                    'woocommerceEmptyCartSuppression' => !empty($integrations['woocommerce']['active']),
                    'lazyMailerliteNonce' => !empty($integrations['mailerlite']['active']),
                    'protectWpBakeryAnimations' => !empty($integrations['wpbakery']['active']),
                    'protectElementorCompatibility' => !empty($integrations['elementor']['active']),
                    'realCookieBannerCompatibility' => !empty($integrations['realCookieBanner']['active']),
                    'complianzCompatibility' => !empty($integrations['complianz']['active']),
                ),
                'warmup' => array(
                    'menuLocation' => (string) ($site['recommendedMenu']['value'] ?? ''),
                    'menuDepth' => '1',
                    'fullSiteSources' => array_values((array) ($site['recommendedFullSiteSources'] ?? array())),
                ),
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_setup_server_capability()
    {
        $software = isset($_SERVER['SERVER_SOFTWARE'])
            ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))
            : '';
        $lower = strtolower($software);
        $type = 'unknown';
        $detection_source = '' !== $software ? 'current-request' : '';
        $persistent_litespeed = array();

        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_litespeed_detection_evidence_read_only')) {
            $persistent_litespeed = Ultra_Cache_WP::get_litespeed_detection_evidence_read_only();
        }

        if (false !== strpos($lower, 'litespeed')) {
            $type = 'litespeed';
        } elseif (!empty($persistent_litespeed['detected'])) {
            $type = 'litespeed';
            $detection_source = 'persistent-litespeed-evidence';
            if ('' === $software) {
                $software = sanitize_text_field((string) ($persistent_litespeed['server'] ?? 'LiteSpeed'));
            }
        } elseif (false !== strpos($lower, 'nginx')) {
            $type = 'nginx';
        } elseif (false !== strpos($lower, 'apache')) {
            $type = 'apache';
        }

        return array(
            'status' => 'unknown' !== $type || '' !== $software ? 'detected' : 'unknown',
            'type' => $type,
            'software' => $software,
            'detectionSource' => $detection_source,
            'phpVersion' => PHP_VERSION,
            'phpSapi' => PHP_SAPI,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_setup_object_cache_capability(array $settings)
    {
        $redis_available = class_exists('Redis') || extension_loaded('redis');
        $apcu_available = function_exists('apcu_fetch')
            && function_exists('apcu_store')
            && (!function_exists('apcu_enabled') || apcu_enabled());
        $sqlite_available = class_exists('SQLite3') && extension_loaded('sqlite3');

        $disk_path = function_exists('ultracache_object_cache_storage_dir')
            ? ultracache_object_cache_storage_dir()
            : (defined('ULTRACACHE_OBJECT_CACHE_DIR') ? ULTRACACHE_OBJECT_CACHE_DIR : '');
        $disk_probe_path = $disk_path;
        while ('' !== $disk_probe_path && !file_exists($disk_probe_path)) {
            $parent = dirname($disk_probe_path);
            if ($parent === $disk_probe_path) {
                break;
            }
            $disk_probe_path = $parent;
        }
        $disk_available = '' !== $disk_probe_path
            && is_dir($disk_probe_path)
            && wp_is_writable($disk_probe_path);

        $backends = array(
            'redis' => array(
                'status' => $redis_available ? 'available' : 'unavailable',
                'available' => (bool) $redis_available,
                'verification' => 'live_probe_required',
            ),
            'apcu' => array(
                'status' => $apcu_available ? 'available' : 'unavailable',
                'available' => (bool) $apcu_available,
                'verification' => 'live_probe_required',
            ),
            'sqlite' => array(
                'status' => $sqlite_available ? 'available' : 'unavailable',
                'available' => (bool) $sqlite_available,
                'version' => $sqlite_available ? self::get_setup_sqlite_version() : '',
                'verification' => 'live_probe_required',
            ),
            'disk' => array(
                'status' => $disk_available ? 'available' : 'unavailable',
                'available' => (bool) $disk_available,
                'path' => $disk_path,
                'verification' => 'live_probe_required',
            ),
        );

        $available_order = array();
        foreach (array('redis', 'apcu', 'sqlite', 'disk') as $backend) {
            if (!empty($backends[$backend]['available'])) {
                $available_order[] = $backend;
            }
        }

        $managed_by_ultracache = !empty($settings['objectCacheEnabled']);
        $external_object_cache_active = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        $configured = $managed_by_ultracache || $external_object_cache_active;
        $current_backend = $managed_by_ultracache
            ? self::sanitize_object_cache_backend($settings['objectCacheBackend'] ?? 'redis')
            : ($external_object_cache_active ? 'external' : '');
        $current_fallback = $managed_by_ultracache
            ? self::sanitize_object_cache_fallback_backend($settings['objectCacheFallbackBackend'] ?? 'apcu')
            : 'none';

        return array(
            'status' => $configured ? 'configured' : (!empty($available_order) ? 'available' : 'unavailable'),
            'configured' => (bool) $configured,
            'managedByUltraCache' => (bool) $managed_by_ultracache,
            'externalObjectCacheActive' => (bool) $external_object_cache_active,
            'currentBackend' => (string) $current_backend,
            'currentFallbackBackend' => (string) $current_fallback,
            'selectionOrder' => array('redis', 'apcu', 'sqlite', 'disk'),
            'backends' => $backends,
            'recommendedBackend' => (string) ($available_order[0] ?? ''),
            'recommendedFallbackBackend' => (string) ($available_order[1] ?? 'none'),
            'verification' => $configured
                ? ($managed_by_ultracache ? 'runtime_verify_only' : 'external_preserve')
                : 'live_probe_required',
        );
    }

    private static function get_setup_sqlite_version()
    {
        if (!class_exists('SQLite3')) {
            return '';
        }
        try {
            $version = SQLite3::version();
            return is_array($version) && isset($version['versionString']) ? (string) $version['versionString'] : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_setup_compression_capability()
    {
        $support = method_exists(__CLASS__, 'get_compression_support_status')
            ? self::get_compression_support_status()
            : array('brotli' => false, 'gzip' => false, 'preferred' => 'none');
        $stored = method_exists(__CLASS__, 'read_frontend_compression_probe_status')
            ? self::read_frontend_compression_probe_status()
            : array();
        $probe_current = 'current' === (string) ($stored['diagnosticStatus'] ?? '');
        $server_managed = $probe_current && (!empty($stored['gzip']) || !empty($stored['brotli']));
        $probe_complete = $probe_current && (
            !empty($stored['probeComplete'])
            || (!empty($stored['gzipProbeCompleted']) && !empty($stored['brotliProbeCompleted']))
        );
        $probe_blocked = $probe_current && (!empty($stored['brokenGzip']) || !empty($stored['brokenBrotli']));
        $decision_ready = $probe_current && ($server_managed || $probe_blocked || $probe_complete);

        $recommended_mode = 'probe';
        if ($decision_ready) {
            if ($server_managed) {
                $recommended_mode = 'server-managed';
            } elseif ($probe_blocked) {
                $recommended_mode = 'off';
            } elseif (!empty($support['brotli'])) {
                $recommended_mode = 'brotli';
            } elseif (!empty($support['gzip'])) {
                $recommended_mode = 'gzip';
            } else {
                $recommended_mode = 'off';
            }
        }

        return array(
            'status' => $decision_ready ? 'known' : 'probe_required',
            'phpGzipAvailable' => !empty($support['gzip']),
            'phpBrotliAvailable' => !empty($support['brotli']),
            'currentProbe' => array(
                'current' => $probe_current,
                'decisionReady' => $decision_ready,
                'complete' => $probe_complete,
                'testedAt' => max(0, (int) ($stored['testedAt'] ?? 0)),
                'serverGzip' => $probe_current && !empty($stored['gzip']),
                'serverBrotli' => $probe_current && !empty($stored['brotli']),
                'brokenGzip' => $probe_current && !empty($stored['brokenGzip']),
                'brokenBrotli' => $probe_current && !empty($stored['brokenBrotli']),
            ),
            'serverManaged' => (bool) $server_managed,
            'recommendedMode' => $recommended_mode,
            'verification' => $decision_ready ? 'current_probe_available' : 'live_probe_required',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_setup_media_capability()
    {
        $imagick = extension_loaded('imagick') && class_exists('Imagick');
        $imagick_avif = false;
        $imagick_webp = false;
        if ($imagick) {
            try {
                $imagick_avif = !empty(Imagick::queryFormats('AVIF'));
                $imagick_webp = !empty(Imagick::queryFormats('WEBP'));
            } catch (Throwable $e) {
                $imagick_avif = false;
                $imagick_webp = false;
            }
        }

        $gd = extension_loaded('gd');
        $gd_avif = $gd && function_exists('imageavif');
        $gd_webp = $gd && function_exists('imagewebp');
        $avif_candidate = $imagick_avif || $gd_avif;
        $webp_candidate = $imagick_webp || $gd_webp;

        return array(
            'status' => ($avif_candidate || $webp_candidate) ? 'candidate_available' : 'unavailable',
            'imagick' => array(
                'available' => (bool) $imagick,
                'avifCandidate' => (bool) $imagick_avif,
                'webpCandidate' => (bool) $imagick_webp,
            ),
            'gd' => array(
                'available' => (bool) $gd,
                'avifCandidate' => (bool) $gd_avif,
                'webpCandidate' => (bool) $gd_webp,
            ),
            'recommendedFormatCandidate' => $avif_candidate ? 'avif' : ($webp_candidate ? 'webp' : 'none'),
            'recommendedFallbackFormatCandidate' => ($avif_candidate && $webp_candidate) ? 'webp' : 'none',
            'verification' => 'conversion_test_required',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_setup_integration_capability()
    {
        $active_plugins = (array) get_option('active_plugins', array());
        if (is_multisite()) {
            $active_plugins = array_merge($active_plugins, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }
        $active_plugins = array_map('strtolower', array_map('strval', $active_plugins));

        $woocommerce = class_exists('WooCommerce') || self::setup_active_plugin_matches($active_plugins, 'woocommerce/');
        $mailerlite = self::setup_active_plugin_matches($active_plugins, 'mailerlite');
        $elementor = defined('ELEMENTOR_VERSION')
            || defined('ELEMENTOR_PRO_VERSION')
            || class_exists('Elementor\\Plugin')
            || class_exists('ElementorPro\\Plugin')
            || self::setup_active_plugin_matches($active_plugins, 'elementor/')
            || self::setup_active_plugin_matches($active_plugins, 'elementor-pro/');
        $wpbakery = defined('WPB_VC_VERSION') || class_exists('Vc_Manager') || self::setup_active_plugin_matches($active_plugins, 'js_composer/') || self::setup_active_plugin_matches($active_plugins, 'wpbakery');
        $real_cookie_banner = defined('RCB_PATH')
            || defined('RCB_VERSION')
            || self::setup_active_plugin_matches($active_plugins, 'real-cookie-banner-pro/')
            || self::setup_active_plugin_matches($active_plugins, 'real-cookie-banner/');
        $complianz = defined('CMPLZ_VERSION')
            || class_exists('COMPLIANZ')
            || self::setup_active_plugin_matches($active_plugins, 'complianz-gdpr-premium/')
            || self::setup_active_plugin_matches($active_plugins, 'complianz-gdpr/');

        return array(
            'woocommerce' => array('active' => (bool) $woocommerce, 'status' => $woocommerce ? 'detected' : 'not_detected'),
            'mailerlite' => array('active' => (bool) $mailerlite, 'status' => $mailerlite ? 'detected' : 'not_detected'),
            'elementor' => array('active' => (bool) $elementor, 'status' => $elementor ? 'detected' : 'not_detected'),
            'wpbakery' => array('active' => (bool) $wpbakery, 'status' => $wpbakery ? 'detected' : 'not_detected'),
            'realCookieBanner' => array('active' => (bool) $real_cookie_banner, 'status' => $real_cookie_banner ? 'detected' : 'not_detected'),
            'complianz' => array('active' => (bool) $complianz, 'status' => $complianz ? 'detected' : 'not_detected'),
        );
    }

    private static function setup_active_plugin_matches(array $active_plugins, $needle)
    {
        $needle = strtolower((string) $needle);
        foreach ($active_plugins as $plugin) {
            if (false !== strpos((string) $plugin, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $settings Current dashboard settings.
     * @return array<string,mixed>
     */
    private static function get_setup_site_capability(array $settings)
    {
        $scope = method_exists(__CLASS__, 'get_crawl_scope_summary')
            ? self::get_crawl_scope_summary($settings)
            : array();
        $menu_options = isset($scope['menuOptions']) && is_array($scope['menuOptions'])
            ? array_values($scope['menuOptions'])
            : array();
        $recommended_menu = self::select_setup_primary_menu($menu_options);

        $available_sources = array();
        foreach ((array) ($scope['fullSiteSourceOptions'] ?? array()) as $option) {
            if (is_array($option) && !empty($option['value'])) {
                $available_sources[] = sanitize_key((string) $option['value']);
            }
        }
        $available_sources = array_values(array_unique(array_filter($available_sources)));

        $recommended_sources = array();
        foreach (array('homepage', 'menus', 'pages', 'posts', 'categories') as $source) {
            if ('menus' === $source && empty($recommended_menu['value'])) {
                continue;
            }
            if (in_array($source, $available_sources, true)) {
                $recommended_sources[] = $source;
            }
        }

        return array(
            'status' => 'detected',
            'homeUrl' => esc_url_raw(home_url('/')),
            'menuOptions' => $menu_options,
            'recommendedMenu' => $recommended_menu,
            'recommendedMenuDepth' => '1',
            'availableFullSiteSources' => $available_sources,
            'recommendedFullSiteSources' => $recommended_sources,
            'estimatedWarmUrls' => max(0, (int) ($scope['estimatedTotal'] ?? 0)),
        );
    }

    /**
     * @param array<int,array<string,mixed>> $menu_options Menu options from the warm discovery engine.
     * @return array<string,mixed>
     */
    private static function select_setup_primary_menu(array $menu_options)
    {
        $assigned = array_values(array_filter($menu_options, static function ($option) {
            return is_array($option) && 'assigned' === (string) ($option['source'] ?? '');
        }));
        $preferred_exact = array('primary', 'main', 'header', 'top', 'navigation', 'main-menu', 'primary-menu');

        foreach ($preferred_exact as $preferred) {
            foreach ($assigned as $option) {
                if ($preferred === sanitize_key((string) ($option['value'] ?? ''))) {
                    return self::format_setup_menu_recommendation($option, 'preferred_location');
                }
            }
        }

        foreach ($assigned as $option) {
            $value = sanitize_key((string) ($option['value'] ?? ''));
            $label = strtolower((string) ($option['label'] ?? ''));
            foreach (array('primary', 'main', 'header', 'top', 'navigation') as $marker) {
                if (false !== strpos($value, $marker) || false !== strpos($label, $marker)) {
                    return self::format_setup_menu_recommendation($option, 'preferred_location_match');
                }
            }
        }

        if (1 === count($assigned)) {
            return self::format_setup_menu_recommendation($assigned[0], 'single_assigned_menu');
        }

        return array(
            'status' => empty($assigned) ? 'not_available' : 'ambiguous',
            'value' => '',
            'label' => '',
            'menuId' => 0,
            'reason' => empty($assigned) ? 'no_assigned_frontend_menu' : 'multiple_assigned_menus_no_primary_signal',
        );
    }

    private static function format_setup_menu_recommendation(array $option, $reason)
    {
        return array(
            'status' => 'recommended',
            'value' => sanitize_key((string) ($option['value'] ?? '')),
            'label' => sanitize_text_field((string) ($option['label'] ?? '')),
            'menuId' => max(0, (int) ($option['menuId'] ?? 0)),
            'reason' => sanitize_key((string) $reason),
        );
    }

    /**
     * @param array<string,mixed> $settings Current dashboard settings.
     * @param array<string,mixed> $server   Server capability payload.
     * @return array<string,mixed>
     */
    private static function get_setup_external_cache_capability(array $settings, array $server)
    {
        $server_type = (string) ($server['type'] ?? 'unknown');
        $stored_detection = get_option('ultracache_external_cache_detection', array());
        $stored_layers = is_array($stored_detection) && isset($stored_detection['layers']) && is_array($stored_detection['layers'])
            ? $stored_detection['layers']
            : array();
        $stored_litespeed = isset($stored_layers['litespeed']) && is_array($stored_layers['litespeed'])
            ? $stored_layers['litespeed']
            : array();
        $stored_nginx = isset($stored_layers['nginx']) && is_array($stored_layers['nginx'])
            ? $stored_layers['nginx']
            : array();
        $stored_varnish = isset($stored_layers['varnish']) && is_array($stored_layers['varnish'])
            ? $stored_layers['varnish']
            : array();

        $litespeed_detected = 'litespeed' === $server_type || !empty($stored_litespeed['detected']);
        $nginx_server_detected = 'nginx' === $server_type;
        $nginx_integration_detected = !empty($stored_nginx['detected'])
            || class_exists('Nginx_Helper')
            || class_exists('Nginx_Helper_Admin')
            || has_action('rt_nginx_helper_purge_all');
        $nginx_flush_hook_available = !empty($stored_nginx['flushable']) || has_action('rt_nginx_helper_purge_all') > 0;
        $varnish_configured = !empty($settings['varnishConnectionConfigured'])
            || (
                !empty($settings['varnishCliEnabled'])
                && '' !== trim((string) ($settings['varnishCliServers'] ?? ''))
            );
        $varnish_detected = $varnish_configured || !empty($stored_varnish['detected']);

        return array(
            'detectionSnapshot' => array(
                'available' => !empty($stored_detection),
                'detectedAt' => absint($stored_detection['detectedAt'] ?? 0),
                'serverSoftware' => sanitize_text_field((string) ($stored_detection['serverSoftware'] ?? '')),
            ),
            'litespeed' => array(
                'status' => $litespeed_detected ? 'detected' : 'not_detected',
                'detected' => (bool) $litespeed_detected,
                'configured' => !empty($settings['liteSpeedCacheEnabled']),
                'flushable' => !empty($stored_litespeed['flushable']),
                'source' => 'litespeed' === $server_type ? 'server' : (!empty($stored_litespeed['detected']) ? 'external-cache-detection' : ''),
            ),
            'nginx' => array(
                'status' => ($nginx_server_detected || $nginx_integration_detected) ? 'detected' : 'not_detected',
                'detected' => (bool) ($nginx_server_detected || $nginx_integration_detected),
                'serverDetected' => (bool) $nginx_server_detected,
                'integrationDetected' => (bool) $nginx_integration_detected,
                'flushHookAvailable' => (bool) $nginx_flush_hook_available,
                'configured' => !empty($settings['flushAllIncludeNginx']),
            ),
            'varnish' => array(
                'status' => $varnish_configured ? 'configured' : ($varnish_detected ? 'detected' : 'not_configured'),
                'detected' => (bool) $varnish_detected,
                'configured' => (bool) $varnish_configured,
                'flushable' => !empty($stored_varnish['flushable']),
                'verification' => $varnish_configured ? 'live_test_required' : ($varnish_detected ? 'discovery_required' : 'not_configured'),
            ),
        );
    }
}
