<?php
/**
 * Lazy minimal Varnish test runner for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-behavior-test-trait.php');

class Ultra_Cache_WP_Varnish_Test_Runner extends Ultra_Cache_WP
{
    use Ultra_Cache_WP_Varnish_Behavior_Test_Trait;

    /**
     * Run the minimal connection, exact invalidation, and public refill test.
     *
     * @return array
     */
    public static function run()
    {
        return self::varnish_test_behavior();
    }
}
