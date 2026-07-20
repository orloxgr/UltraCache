<?php
/**
 * JavaScript optimization compatibility aggregator.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/js/class-js-classification-trait.php';
require_once __DIR__ . '/js/class-js-defer-trait.php';
require_once __DIR__ . '/js/class-js-delay-trait.php';
require_once __DIR__ . '/js/class-js-exclusions-trait.php';
require_once __DIR__ . '/js/class-js-html-rewrite-trait.php';

trait Ultra_Cache_Engine_JS_Optimization_Trait
{
    use Ultra_Cache_Engine_JS_Classification_Trait;
    use Ultra_Cache_Engine_JS_Defer_Trait;
    use Ultra_Cache_Engine_JS_Delay_Trait;
    use Ultra_Cache_Engine_JS_Exclusions_Trait;
    use Ultra_Cache_Engine_JS_HTML_Rewrite_Trait;
}
