<?php
$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/includes/engine/class-engine-frontend-assets-trait.php');
$failures = array();
$expect = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$start = strpos($source, 'public function enqueue_runtime_js_scan_collector()');
$end = strpos($source, 'public function enqueue_mailerlite_lazy_nonce_helper()', $start);
$method = false !== $start && false !== $end ? substr($source, $start, $end - $start) : '';

$expect('' !== $method, 'Runtime Scan collector enqueue method exists');
$expect(false !== strpos($method, "ultracache_frontend_js_asset_url('runtime-js-scan-collector.js')"), 'collector uses standalone asset URL');
$expect(false !== strpos($method, 'wp_register_script('), 'collector is registered standalone through WordPress');
$expect(false !== strpos($method, 'wp_add_inline_script('), 'collector config is attached directly before standalone asset');
$expect(false !== strpos($method, 'wp_enqueue_script($handle)'), 'standalone collector is enqueued');
$expect(false === strpos($method, 'ultracache_enqueue_frontend_js_helper($handle, \'runtime-js-scan-collector.js\''), 'collector no longer depends on runtime bundle helper');
$expect(false === strpos($method, 'ultracache_add_frontend_js_helper_data($handle, \'ultracacheRuntimeJsScanConfig\''), 'collector config no longer depends on bundled-module config transport');
$expect(false !== strpos($method, "'window.ultracacheRuntimeJsScanConfig = '"), 'collector receives scan config before execution');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "PASS: Runtime Scan collector bootstrap is standalone and independent from runtime bundling.\n";
