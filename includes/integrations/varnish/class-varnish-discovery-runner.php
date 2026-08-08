<?php
/**
 * Lazy Varnish configuration discovery runner for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-discovery-candidates-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-discovery-probes-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-discovery-trait.php');

class Ultra_Cache_WP_Varnish_Discovery_Runner extends Ultra_Cache_WP
{
    use Ultra_Cache_WP_Varnish_Discovery_Candidates_Trait;
    use Ultra_Cache_WP_Varnish_Discovery_Probes_Trait;
    use Ultra_Cache_WP_Varnish_Discovery_Trait;

    /**
     * Run bounded Varnish configuration discovery.
     *
     * @return array
     */
    public static function run()
    {
        return self::discover_varnish_configuration();
    }
}
