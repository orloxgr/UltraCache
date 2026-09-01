<?php
/**
 * Explicit Complianz compatibility lifecycle integration.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Complianz_Compatibility_Trait
{
    /** Serve a same-origin, script-free document for credentialless scanner storage reset. */
    public static function ultracache_render_complianz_scanner_reset_frame()
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

    /** Whether the explicit Complianz compatibility switch is enabled. */
    private static function ultracache_complianz_compatibility_enabled()
    {
        $settings = get_option(ULTRACACHE_SETTINGS_KEY, array());
        return is_array($settings) && !empty($settings['complianzCompatibilityEnabled']);
    }

    /** Purge UltraCache once per request after a Complianz configuration change. */
    private static function ultracache_purge_after_complianz_change()
    {
        static $purged = false;
        if ($purged || !self::ultracache_complianz_compatibility_enabled()) {
            return;
        }
        $purged = true;

        if (!class_exists('Ultra_Cache_Engine') || !method_exists('Ultra_Cache_Engine', 'get_instance')) {
            return;
        }
        $engine = Ultra_Cache_Engine::get_instance();
        if ($engine && method_exists($engine, 'purge_all')) {
            $engine->purge_all(array(
                'reason' => 'complianz-change',
                'source' => 'complianz',
            ));
        }
    }

    /** Invalidate cached consent-transformed HTML when the main Complianz settings option changes. */
    public static function ultracache_handle_complianz_options_updated($old_value = null, $value = null, $option = '')
    {
        unset($old_value, $value, $option);
        self::ultracache_purge_after_complianz_change();
    }

    /** Invalidate after a Complianz settings transaction completes. */
    public static function ultracache_handle_complianz_saved_fields($fields = array())
    {
        self::ultracache_purge_after_complianz_change();
        return $fields;
    }

    /** Invalidate after a real banner CSS regeneration, but never for the admin preview file. */
    public static function ultracache_handle_complianz_css_generation($file, $css = '', $upload_dir = '', $banner_id = '', $consent_type = '')
    {
        unset($css, $upload_dir, $banner_id, $consent_type);
        $file = strtolower((string) $file);
        if ('' !== $file && false !== strpos($file, 'banner-preview-')) {
            return;
        }
        self::ultracache_purge_after_complianz_change();
    }
}
