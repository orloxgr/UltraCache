<?php
/**
 * Backward-compatible Varnish test runner facade.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ultra_Cache_WP_Varnish_Test_Runner extends Ultra_Cache_WP
{
    /**
     * Run the test in the parent class scope where all private Varnish helpers exist.
     *
     * @return array
     */
    public static function run()
    {
        return parent::varnish_test_behavior();
    }
}
