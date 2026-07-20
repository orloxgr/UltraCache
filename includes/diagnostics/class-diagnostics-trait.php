<?php
/**
 * Dashboard diagnostics compatibility aggregator.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-diagnostics-collection-trait.php';
require_once __DIR__ . '/class-diagnostics-export-trait.php';
require_once __DIR__ . '/class-diagnostics-runtime-trait.php';
require_once __DIR__ . '/class-lcp-diagnostics-trait.php';
require_once __DIR__ . '/class-diagnostics-rest-trait.php';

trait Ultra_Cache_WP_Diagnostics_Trait
{
    use Ultra_Cache_WP_Diagnostics_Collection_Trait;
    use Ultra_Cache_WP_Diagnostics_Export_Trait;
    use Ultra_Cache_WP_Diagnostics_Runtime_Trait;
    use Ultra_Cache_WP_LCP_Diagnostics_Trait;
    use Ultra_Cache_WP_Diagnostics_REST_Trait;
}
