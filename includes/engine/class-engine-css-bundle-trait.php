<?php
/**
 * CSS bundle compatibility aggregator.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/css/class-css-bundle-builder-trait.php';
require_once __DIR__ . '/css/class-css-bundle-storage-trait.php';
require_once __DIR__ . '/css/class-css-bundle-rewrite-trait.php';
require_once __DIR__ . '/css/class-css-bundle-cleanup-trait.php';

trait Ultra_Cache_Engine_CSS_Bundle_Trait
{
    use Ultra_Cache_Engine_CSS_Bundle_Builder_Trait;
    use Ultra_Cache_Engine_CSS_Bundle_Storage_Trait;
    use Ultra_Cache_Engine_CSS_Bundle_Rewrite_Trait;
    use Ultra_Cache_Engine_CSS_Bundle_Cleanup_Trait;
}
