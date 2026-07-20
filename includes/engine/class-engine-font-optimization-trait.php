<?php
/**
 * Font optimization compatibility aggregator.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/fonts/class-font-discovery-trait.php';
require_once __DIR__ . '/fonts/class-font-css-trait.php';
require_once __DIR__ . '/fonts/class-font-preload-trait.php';
require_once __DIR__ . '/fonts/class-font-storage-trait.php';

trait Ultra_Cache_Engine_Font_Optimization_Trait
{
    use Ultra_Cache_Engine_Font_Discovery_Trait;
    use Ultra_Cache_Engine_Font_CSS_Trait;
    use Ultra_Cache_Engine_Font_Preload_Trait;
    use Ultra_Cache_Engine_Font_Storage_Trait;
}
