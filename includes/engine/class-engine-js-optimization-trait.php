<?php
/**
 * JavaScript optimization compatibility aggregator.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/js/class-js-runtime-registry-trait.php';
require_once __DIR__ . '/js/class-js-classification-trait.php';
require_once __DIR__ . '/js/class-js-policy-trait.php';
require_once __DIR__ . '/js/class-js-real-cookie-banner-compatibility-trait.php';
require_once __DIR__ . '/js/class-js-complianz-compatibility-trait.php';
require_once __DIR__ . '/js/class-js-router-trait.php';
require_once __DIR__ . '/js/class-js-defer-trait.php';
require_once __DIR__ . '/js/class-js-delay-trait.php';
require_once __DIR__ . '/js/class-js-exclusions-trait.php';
require_once __DIR__ . '/js/class-js-html-rewrite-trait.php';

trait Ultra_Cache_Engine_JS_Optimization_Trait
{
    use Ultra_Cache_Engine_JS_Runtime_Registry_Trait;
    use Ultra_Cache_Engine_JS_Classification_Trait;
    use Ultra_Cache_Engine_JS_Policy_Trait;
    use Ultra_Cache_Engine_JS_Real_Cookie_Banner_Compatibility_Trait;
    use Ultra_Cache_Engine_JS_Complianz_Compatibility_Trait;
    use Ultra_Cache_Engine_JS_Router_Trait;
    use Ultra_Cache_Engine_JS_Defer_Trait;
    use Ultra_Cache_Engine_JS_Delay_Trait;
    use Ultra_Cache_Engine_JS_Exclusions_Trait;
    use Ultra_Cache_Engine_JS_HTML_Rewrite_Trait;
}
