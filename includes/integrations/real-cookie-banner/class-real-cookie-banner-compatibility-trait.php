<?php
/**
 * Explicit Real Cookie Banner compatibility lifecycle integration.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Real_Cookie_Banner_Compatibility_Trait
{
    /** Serve a same-origin, script-free document for credentialless scanner storage reset. */
    public static function ultracache_render_real_cookie_banner_scanner_reset_frame()
    {
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Robots-Tag: noindex, nofollow', true);
        }
        echo '<!doctype html><html><head><meta charset="utf-8"><title>UltraCache Runtime Scan Storage Reset</title></head><body></body></html>';
        exit;
    }

    /** Whether the explicit Real Cookie Banner compatibility switch is enabled. */
    private static function ultracache_real_cookie_banner_compatibility_enabled()
    {
        $settings = get_option(ULTRACACHE_SETTINGS_KEY, array());
        return is_array($settings) && !empty($settings['realCookieBannerCompatibilityEnabled']);
    }

    /** Purge UltraCache once per request after an RCB configuration/revision change. */
    private static function ultracache_purge_after_real_cookie_banner_change()
    {
        static $purged = false;
        if ($purged || !self::ultracache_real_cookie_banner_compatibility_enabled()) {
            return;
        }
        $purged = true;

        if (!class_exists('Ultra_Cache_Engine') || !method_exists('Ultra_Cache_Engine', 'get_instance')) {
            return;
        }
        $engine = Ultra_Cache_Engine::get_instance();
        if ($engine && method_exists($engine, 'purge_all')) {
            $engine->purge_all(array(
                'reason' => 'real-cookie-banner-change',
                'source' => 'real-cookie-banner',
            ));
        }
    }

    /** Preserve the RCB customize filter value while invalidating stale UltraCache output. */
    public static function ultracache_handle_real_cookie_banner_customize_updated($response)
    {
        self::ultracache_purge_after_real_cookie_banner_change();
        return $response;
    }

    /** Preserve the RCB settings filter value while invalidating stale UltraCache output. */
    public static function ultracache_handle_real_cookie_banner_settings_updated($response, $request = null)
    {
        unset($request);
        self::ultracache_purge_after_real_cookie_banner_change();
        return $response;
    }

    /** Preserve the RCB revision filter value while invalidating stale UltraCache output. */
    public static function ultracache_handle_real_cookie_banner_revision_hash($result, $hash = '')
    {
        unset($hash);
        self::ultracache_purge_after_real_cookie_banner_change();
        return $result;
    }

    /** Invalidate UltraCache after RCB Pro/Lite license state changes. */
    public static function ultracache_handle_real_cookie_banner_license_status_changed()
    {
        self::ultracache_purge_after_real_cookie_banner_change();
    }
}
