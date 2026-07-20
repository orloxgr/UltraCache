<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/profiler/class-profiler-storage-trait.php';
require_once dirname(__DIR__) . '/profiler/class-profiler-queue-trait.php';
require_once dirname(__DIR__) . '/profiler/class-runtime-js-scanner-trait.php';
require_once dirname(__DIR__) . '/profiler/class-runtime-js-rules-trait.php';
require_once dirname(__DIR__) . '/profiler/class-profiler-rest-trait.php';
require_once dirname(__DIR__) . '/profiler/class-profiler-runner-trait.php';

trait Ultra_Cache_Rest_Profiler_Trait
{
    use Ultra_Cache_Profiler_Storage_Trait;
    use Ultra_Cache_Profiler_Queue_Trait;
    use Ultra_Cache_Runtime_JS_Scanner_Trait;
    use Ultra_Cache_Runtime_JS_Rules_Trait;
    use Ultra_Cache_Profiler_Rest_Trait;
    use Ultra_Cache_Profiler_Runner_Trait;
}
