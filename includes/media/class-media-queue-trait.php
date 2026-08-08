<?php
/**
 * UltraCache media queue compatibility aggregator.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once ultracache_plugin_dir('includes/media/queue/class-media-queue-schema-trait.php');
require_once ultracache_plugin_dir('includes/media/queue/class-media-queue-units-trait.php');
require_once ultracache_plugin_dir('includes/media/queue/class-media-queue-unit-runner-trait.php');
require_once ultracache_plugin_dir('includes/media/queue/class-media-queue-rebuild-trait.php');
require_once ultracache_plugin_dir('includes/media/queue/class-media-queue-runner-trait.php');
require_once ultracache_plugin_dir('includes/media/queue/class-media-affected-pages-trait.php');

trait Ultra_Cache_Media_Queue_Trait
{
    use Ultra_Cache_Media_Queue_Schema_Trait;
    use Ultra_Cache_Media_Queue_Units_Trait;
    use Ultra_Cache_Media_Queue_Unit_Runner_Trait;
    use Ultra_Cache_Media_Queue_Rebuild_Trait;
    use Ultra_Cache_Media_Queue_Runner_Trait;
    use Ultra_Cache_Media_Affected_Pages_Trait;
}
