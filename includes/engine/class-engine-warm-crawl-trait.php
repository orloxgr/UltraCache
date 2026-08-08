<?php
/**
 * Warm crawl compatibility aggregator.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once ultracache_plugin_dir('includes/warmup/class-warm-url-discovery-trait.php');
require_once ultracache_plugin_dir('includes/warmup/class-affected-url-coalescing-trait.php');
require_once ultracache_plugin_dir('includes/warmup/class-warm-page-pipeline-trait.php');
require_once ultracache_plugin_dir('includes/warmup/class-warm-queue-trait.php');
require_once ultracache_plugin_dir('includes/warmup/class-warm-runner-trait.php');
require_once ultracache_plugin_dir('includes/warmup/class-warm-css-bundle-trait.php');

trait Ultra_Cache_Engine_Warm_Crawl_Trait
{
    use Ultra_Cache_Engine_Warm_URL_Discovery_Trait;
    use Ultra_Cache_Engine_Affected_URL_Coalescing_Trait;
    use Ultra_Cache_Engine_Warm_Page_Pipeline_Trait;
    use Ultra_Cache_Engine_Warm_Queue_Trait;
    use Ultra_Cache_Engine_Warm_Runner_Trait;
    use Ultra_Cache_Engine_Warm_CSS_Bundle_Trait;
}
