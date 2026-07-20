<?php
/**
 * Exact-map loader for the primary UltraCache classes.
 *
 * Procedural helpers and trait aggregators keep their explicit load order.
 * This loader defers only standalone plugin-owned classes until first use.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Ultra_Cache_Class_Loader
{
    /** @var bool */
    private static $registered = false;

    /**
     * Register the UltraCache class loader once.
     *
     * @return bool
     */
    public static function register()
    {
        if (self::$registered) {
            return true;
        }

        self::$registered = spl_autoload_register(array(__CLASS__, 'autoload'), true, true);

        return self::$registered;
    }

    /**
     * Load an exact plugin-owned class mapping.
     *
     * @param string $class Class name requested by PHP.
     * @return void
     */
    public static function autoload($class)
    {
        if (!is_string($class) || '' === $class) {
            return;
        }

        $map = self::get_class_map();
        if (!isset($map[$class])) {
            return;
        }

        $file = ULTRACACHE_PATH . $map[$class];
        if (!is_readable($file)) {
            return;
        }

        ultracache_request_profile_checkpoint('dependency_load_start', array('file' => basename($file)));
        require_once $file;
        ultracache_request_profile_checkpoint('dependency_load_end', array('file' => basename($file)));
    }

    /**
     * Return the exact primary-class map.
     *
     * @return array<string,string>
     */
    private static function get_class_map()
    {
        return array(
            'Ultra_Cache_Engine'               => 'includes/class-ultra-cache-engine.php',
            'Ultra_Cache_Media_Converter'      => 'includes/class-media-converter.php',
            'Ultra_Cache_Object_Cache_Manager' => 'includes/class-object-cache-manager.php',
            'Ultra_Cache_Rest_API'             => 'includes/class-rest-api.php',
            'Ultra_Cache_WP_CLI'               => 'includes/class-wp-cli.php',
        );
    }
}
