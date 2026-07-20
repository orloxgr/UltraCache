<?php
/**
 * LCP and slider optimization compatibility aggregator.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/lcp/class-lcp-detection-trait.php';
require_once __DIR__ . '/lcp/class-lcp-preload-trait.php';
require_once __DIR__ . '/lcp/class-lcp-observation-storage-trait.php';
require_once __DIR__ . '/lcp/class-lcp-observation-trait.php';
require_once __DIR__ . '/lcp/class-slider-revolution-trait.php';
require_once __DIR__ . '/lcp/class-lcp-html-rewrite-trait.php';

trait Ultra_Cache_Engine_LCP_Slider_Trait
{
    use Ultra_Cache_Engine_LCP_Detection_Trait;
    use Ultra_Cache_Engine_LCP_Preload_Trait;
    use Ultra_Cache_Engine_LCP_Observation_Storage_Trait;
    use Ultra_Cache_Engine_LCP_Observation_Trait;
    use Ultra_Cache_Engine_Slider_Revolution_Trait;
    use Ultra_Cache_Engine_LCP_HTML_Rewrite_Trait;
}
