<?php
/**
 * UltraCache 3.12.24 Runtime Scan integrity regression.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
$root = dirname(__DIR__, 2);
require_once $root . '/includes/profiler/class-runtime-js-scanner-trait.php';
require_once $root . '/includes/profiler/class-runtime-js-rules-trait.php';

final class UltraCacheRuntimeScanIntegrityHarness
{
    use Ultra_Cache_Runtime_JS_Scanner_Trait;
    use Ultra_Cache_Runtime_JS_Rules_Trait;

    public function isInternalRuntimeSource(string $source): bool
    {
        return $this->runtime_js_scan_is_ultracache_runtime_helper_source($source);
    }
}

$passes = 0;
$failures = array();
$expect = static function (bool $condition, string $label) use (&$passes, &$failures): void {
    if ($condition) {
        $passes++;
        echo '[PASS] ' . $label . PHP_EOL;
        return;
    }
    $failures[] = $label;
    echo '[FAIL] ' . $label . PHP_EOL;
};

$harness = new UltraCacheRuntimeScanIntegrityHarness();
$dashboard = file_get_contents($root . '/includes/admin/js/dashboard-application.js');
$diagnostics = file_get_contents($root . '/includes/admin/js/diagnostics.js');
$cache = file_get_contents($root . '/includes/rest/class-rest-cache-trait.php');
$pipeline = file_get_contents($root . '/includes/warmup/class-warm-page-pipeline-trait.php');
$routes = file_get_contents($root . '/includes/rest/class-rest-routes-trait.php');

$expect($harness->isInternalRuntimeSource('https://example.com/wp-content/uploads/ultracache/js-bundles/runtime-defer-fcfd3e688cda6845274dd231.js'), 'generated UltraCache runtime-defer bundle is internal diagnostic infrastructure');
$expect($harness->isInternalRuntimeSource('/wp-content/uploads/ultracache/js-bundles/runtime-native-abc123.js'), 'generated UltraCache runtime-native bundle is internal diagnostic infrastructure');
$expect(!$harness->isInternalRuntimeSource('/wp-content/plugins/woocommerce-products-filter/js/front.js'), 'real plugin script remains eligible for diagnostic attribution');

$refreshStart = strpos((string) $dashboard, 'async function refreshRuntimeScanTargetUrl');
$refreshEnd = strpos((string) $dashboard, 'async function prepareBrowserRuntimeScanStrategySafeguards', $refreshStart === false ? 0 : $refreshStart);
$refreshMethod = ($refreshStart !== false && $refreshEnd !== false) ? substr((string) $dashboard, $refreshStart, $refreshEnd - $refreshStart) : '';
$expect($refreshMethod !== '', 'Runtime Scan target refresh method exists');
$expect(strpos($refreshMethod, "queueDashboardAction('purge_all'") === false, 'Runtime Scan never performs global Purge All');
$expect(strpos($refreshMethod, 'purgeTargetFirst: true') !== false, 'Runtime Scan asks the ordinary crawl-page path to invalidate only its target');
$expect(strpos((string) $cache, "'purge_target_first'    => \$purge_target_first") !== false, 'crawl-page forwards exact-target invalidation into the shared warm pipeline');
$lockPos = strpos((string) $pipeline, '$lock = $this->acquire_warm_pipeline_url_lock');
$purgePos = strpos((string) $pipeline, "if (!empty(\$args['purge_target_first']))");
$expect(false !== $lockPos && false !== $purgePos && $lockPos < $purgePos && strpos((string) $pipeline, '$this->purge_url($url)', $purgePos) !== false, 'crawl-page exact-target invalidation uses purge_url only after the shared URL lock is acquired');
$expect(strpos((string) $routes, "'purgeTargetFirst'") !== false, 'crawl-page REST contract exposes exact-target invalidation flag');

$expect(strpos((string) $diagnostics, 'Pre-existing Baseline Errors') !== false, 'baseline console errors remain visible in Runtime Scan results');
$expect(strpos((string) $diagnostics, 'baselineErrors: baselineErrors') !== false, 'automatic site scan returns aggregated baseline errors');
$expect(strpos((string) $diagnostics, 'Runtime Site Scan Findings') === false, 'duplicated Runtime Site Scan Findings panel is removed');
$expect(strpos((string) $diagnostics, 'Runtime Scan Results') === false, 'duplicate aggregate Runtime Scan Results finding cards are removed');
$expect(strpos((string) $diagnostics, "jsDiagnosticQueue ? h('div'") !== false, 'DB-backed JS Diagnostic Queue panel remains visible after Runtime Site Scan');
$expect(strpos((string) $diagnostics, '(jsDiagnosticQueue && !runtimeScanAggregateScan)') === false, 'Runtime Site Scan no longer suppresses the JS Diagnostic Queue panel');
$expect(strpos((string) $diagnostics, 'preferDeferForAmbiguous: true') !== false, 'Runtime Site Scan restores speed-first automatic repair by trying Defer Instead first for ambiguous recommended fixes');

$total = $passes + count($failures);
echo PHP_EOL . 'Result: ' . $passes . '/' . $total . ' PASS' . PHP_EOL;
if ($failures) {
    exit(1);
}
