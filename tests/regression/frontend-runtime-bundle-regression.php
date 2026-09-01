<?php
/**
 * UltraCache 3.12.06 three-lane runtime bundle regression.
 *
 * Run:
 *   php tests/regression/frontend-runtime-bundle-regression.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('ULTRACACHE_VERSION', '3.12.06');
define('ULTRACACHE_PATH', dirname(__DIR__, 2) . '/');

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', (string) $key)); }
}
if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($name) { return (string) preg_replace('/[^A-Za-z0-9._-]/', '', basename((string) $name)); }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($value) { return rtrim((string) $value, '/\\') . '/'; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0777, true); }
}
if (!function_exists('ultracache_strip_source_mapping_url_comments')) {
    function ultracache_strip_source_mapping_url_comments($value) { return (string) preg_replace('/^[ \t]*\/\/[#@]\s*sourceMappingURL\s*=.*(?:\r?\n|$)/mi', '', (string) $value); }
}
if (!function_exists('ultracache_guarded_asset_file_get_contents')) {
    function ultracache_guarded_asset_file_get_contents($path, $type = '', $context = '', $suppress = false) {
        unset($type, $context, $suppress);
        return is_file($path) ? file_get_contents($path) : false;
    }
}
$GLOBALS['uc_bundle_tmp'] = sys_get_temp_dir() . '/ultracache-runtime-bundle-regression';
if (!function_exists('ultracache_uploads_storage_dir')) {
    function ultracache_uploads_storage_dir($relative = '') {
        return trailingslashit($GLOBALS['uc_bundle_tmp']) . ltrim((string) $relative, '/');
    }
}
if (!function_exists('ultracache_uploads_storage_url')) {
    function ultracache_uploads_storage_url($relative = '') {
        return 'https://example.test/uploads/' . ltrim((string) $relative, '/');
    }
}
if (!function_exists('ultracache_safe_file_put_contents')) {
    function ultracache_safe_file_put_contents($path, $contents, $flags = 0, $context = '') {
        unset($context);
        $dir = dirname((string) $path);
        if (!is_dir($dir)) { mkdir($dir, 0777, true); }
        return file_put_contents($path, $contents, $flags);
    }
}
if (!function_exists('wp_script_is')) {
    function wp_script_is($handle, $status = 'enqueued') { unset($handle, $status); return false; }
}
if (!function_exists('wp_register_script')) { function wp_register_script() { return true; } }
if (!function_exists('wp_enqueue_script')) { function wp_enqueue_script() { return true; } }

$root = dirname(__DIR__, 2);
require_once $root . '/includes/engine/js/class-js-runtime-registry-trait.php';
require_once $root . '/includes/engine/class-engine-frontend-assets-trait.php';

final class UltraCacheRuntimeBundleHarness
{
    use Ultra_Cache_Engine_JS_Runtime_Registry_Trait;
    use Ultra_Cache_Engine_Frontend_Assets_Trait;

    public function request(string $handle): array { return $this->ultracache_request_frontend_runtime_module($handle); }
    public function reserve(string $handle): bool { return $this->ultracache_reserve_frontend_runtime_module($handle); }
    public function included(string $lane): array { return $this->ultracache_get_frontend_runtime_included_module_ids($lane); }
    public function auto(string $lane): array { return $this->ultracache_get_frontend_runtime_auto_module_ids($lane); }
    public function build(string $lane): array { return $this->ultracache_build_frontend_runtime_bundle_asset($lane, $this->included($lane)); }
    public function bundles(): array { return $this->ultracache_frontend_runtime_bundle_definitions(); }
}

$passes = 0;
$failures = array();
$expect = static function (bool $condition, string $label) use (&$passes, &$failures): void {
    if ($condition) { $passes++; echo '[PASS] ' . $label . PHP_EOL; return; }
    $failures[] = $label; echo '[FAIL] ' . $label . PHP_EOL;
};

$deleteTree = static function ($dir) use (&$deleteTree): void {
    if (!is_dir($dir)) { return; }
    foreach (array_diff(scandir($dir), array('.', '..')) as $name) {
        $path = $dir . '/' . $name;
        if (is_dir($path)) { $deleteTree($path); } else { @unlink($path); }
    }
    @rmdir($dir);
};
$deleteTree($GLOBALS['uc_bundle_tmp']);

$h = new UltraCacheRuntimeBundleHarness();
$bundles = $h->bundles();
$expect(array_keys($bundles) === array('native', 'defer', 'delay'), 'exactly three canonical runtime lanes exist');
$expect(count(array_unique(array_column($bundles, 'handle'))) === 3, 'each lane has exactly one canonical network handle');

$h->request('ultracache-dynamic-script-finder-bootstrap');
$h->request('ultracache-delayed-js-interaction-bootstrap');
$h->request('ultracache-lcp-request-credentials-bootstrap');
$h->request('ultracache-async-css-runtime');
$h->reserve('ultracache-woocommerce-esi-optin');
$nativeIds = $h->included('native');
$expect($nativeIds === array('delayed-js-interaction-bootstrap', 'dynamic-script-finder-bootstrap', 'lcp-request-credentials-bootstrap'), 'normal native bundle contains only audited parser-early production modules');
$expect($h->auto('native') === array('delayed-js-interaction-bootstrap', 'dynamic-script-finder-bootstrap', 'lcp-request-credentials-bootstrap'), 'audited native production modules auto-activate when requested');

$native = $h->build('native');
$nativeSource = !empty($native['path']) && is_file($native['path']) ? file_get_contents($native['path']) : '';
$expect(is_string($nativeSource) && '' !== $nativeSource, 'native generated bundle is written');
$expect(false !== strpos($nativeSource, 'UltraCache runtime module: dynamic-script-finder-bootstrap'), 'native bundle contains active generic dynamic script finder module');
$expect(false !== strpos($nativeSource, 'UltraCache runtime module: delayed-js-interaction-bootstrap'), 'native bundle contains active interaction bootstrap module');
$expect(false !== strpos($nativeSource, 'UltraCache runtime module: lcp-request-credentials-bootstrap'), 'native bundle contains active LCP credentials bootstrap');
$expect(false === strpos($nativeSource, 'UltraCache runtime module: async-css-runtime'), 'defer-safe async CSS runtime is absent from native bundle');
$expect(false === strpos($nativeSource, 'UltraCache runtime module: woocommerce-esi-optin'), 'DOM-ready ESI opt-in runtime is absent from native bundle');
$expect(false === strpos($nativeSource, 'UltraCache runtime module: runtime-js-scan-collector'), 'diagnostic collector is absent outside scanner requests');
$expect(false !== strpos((string) $nativeSource, 'var autoModules=["delayed-js-interaction-bootstrap","dynamic-script-finder-bootstrap","lcp-request-credentials-bootstrap"]'), 'native bundle embeds its requested activation set in generated JavaScript');

$h->request('ultracache-delayed-js-loader');
$h->request('ultracache-lcp-observer');
$deferIds = $h->included('defer');
$expect($deferIds === array('async-css-runtime', 'delayed-js-loader', 'lcp-observer', 'woocommerce-esi-optin'), 'defer bundle contains moved helper plus requested/reserved defer modules');
$defer = $h->build('defer');
$deferSource = !empty($defer['path']) && is_file($defer['path']) ? file_get_contents($defer['path']) : '';
$expect(false !== strpos((string) $deferSource, 'UltraCache runtime module: delayed-js-loader'), 'defer bundle contains delayed-js executor');
$expect(false !== strpos((string) $deferSource, 'UltraCache runtime module: lcp-observer'), 'defer bundle contains LCP observer');
$expect(false !== strpos((string) $deferSource, 'UltraCache runtime module: async-css-runtime'), 'defer bundle contains moved async CSS runtime');
$expect(false !== strpos((string) $deferSource, 'UltraCache runtime module: woocommerce-esi-optin'), 'defer bundle contains reserved ESI opt-in factory');
$expect(false !== strpos((string) $deferSource, 'var autoModules=["async-css-runtime","delayed-js-loader","lcp-observer"]'), 'defer bundle embeds only requested dependency-free activations and leaves reserved factories dormant');
$expect(false === strpos((string) $deferSource, 'UltraCache runtime module: lazy-third-party-iframes'), 'inactive lazy iframe runtime is not loaded');
$expect(array() === $h->included('delay'), 'no delay bundle is generated when no UltraCache module belongs to DELAY');

$frontend = file_get_contents($root . '/includes/engine/class-engine-frontend-assets-trait.php');
$engine = file_get_contents($root . '/includes/class-ultra-cache-engine.php');
$router = file_get_contents($root . '/includes/engine/js/class-js-router-trait.php');
$expect(is_string($frontend) && false !== strpos($frontend, 'ultracache_ensure_frontend_runtime_bundle_placeholder'), 'bundle handle reserves its WordPress queue position at first module request');
$expect(is_string($frontend) && false !== strpos($frontend, 'runtime_js_bundle_asset'), 'runtime bundles are persisted as generated uploads assets');
$expect(is_string($frontend) && false !== strpos($frontend, 'ultracache_enqueue_frontend_runtime_lane_fallback'), 'bundle write failure has standalone compatibility fallback');
$expect(is_string($frontend) && false !== strpos($frontend, 'ultracache_frontend_runtime_bundle_dependencies') && false !== strpos($frontend, "'defer' === \$lane") && false !== strpos($frontend, "'delay' === \$lane"), 'runtime bundle handles enforce NATIVE -> DEFER -> DELAY predecessor ordering');
$expect(is_string($engine) && false !== strpos($engine, "ultracache_finalize_frontend_runtime_bundles'), PHP_INT_MAX - 100"), 'bundle finalization occurs after feature declarations but before script printing');
$expect(is_string($router) && false !== strpos($router, "case 'runtime-bundle-native'") && false !== strpos($router, "case 'runtime-bundle-defer'") && false !== strpos($router, "case 'runtime-bundle-delay'"), 'central router owns all three runtime bundle lanes');

if ('1' !== getenv('ULTRACACHE_KEEP_RUNTIME_BUNDLE_TEST')) {
    $deleteTree($GLOBALS['uc_bundle_tmp']);
}

echo PHP_EOL . 'Result: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . PHP_EOL;
if (!empty($failures)) { exit(1); }
