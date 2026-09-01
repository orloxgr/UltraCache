<?php
/**
 * UltraCache 3.12.18 runtime bundle external metadata transport regression.
 *
 * Run:
 *   php tests/regression/runtime-bundle-activation-transport-regression.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('ULTRACACHE_VERSION', '3.12.18');

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', (string) $key)); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}

final class UltraCacheRuntimeBundleWpScriptsStub
{
    /** @var array<string,object> */
    public $registered = array();

    public function get_data($handle, $key)
    {
        $handle = sanitize_key((string) $handle);
        if (empty($this->registered[$handle]) || !is_object($this->registered[$handle])) {
            return false;
        }
        $extra = isset($this->registered[$handle]->extra) && is_array($this->registered[$handle]->extra)
            ? $this->registered[$handle]->extra
            : array();
        return $extra[(string) $key] ?? false;
    }
}

$GLOBALS['uc318_wp_scripts'] = new UltraCacheRuntimeBundleWpScriptsStub();
if (!function_exists('wp_scripts')) {
    function wp_scripts() { return $GLOBALS['uc318_wp_scripts']; }
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/engine/js/class-js-runtime-registry-trait.php';
require_once $root . '/includes/engine/class-engine-frontend-assets-trait.php';
require_once $root . '/includes/engine/js/class-js-router-trait.php';

final class UltraCacheRuntimeBundleActivationTransportHarness
{
    use Ultra_Cache_Engine_JS_Runtime_Registry_Trait;
    use Ultra_Cache_Engine_Frontend_Assets_Trait;
    use Ultra_Cache_Engine_JS_Router_Trait;

    public function request(string $handle): void
    {
        $this->ultracache_request_frontend_runtime_module($handle);
    }

    public function store(string $lane, string $handle): array
    {
        return $this->ultracache_store_frontend_runtime_bundle_activation_metadata($lane, $handle);
    }

    public function finalizeTag(string $tag, string $handle): string
    {
        return $this->ultracache_finalize_runtime_bundle_activation_script_tag($tag, $handle, 'https://example.test/bundle.js');
    }
}

$passes = 0;
$failures = array();
$expect = static function (bool $condition, string $label) use (&$passes, &$failures): void {
    if ($condition) { $passes++; echo '[PASS] ' . $label . PHP_EOL; return; }
    $failures[] = $label;
    echo '[FAIL] ' . $label . PHP_EOL;
};

$wpScripts = $GLOBALS['uc318_wp_scripts'];
foreach (array('ultracache-runtime-native', 'ultracache-runtime-defer') as $handle) {
    $dependency = new stdClass();
    $dependency->extra = array();
    $wpScripts->registered[$handle] = $dependency;
}

$h = new UltraCacheRuntimeBundleActivationTransportHarness();
$h->request('ultracache-delayed-js-interaction-bootstrap');
$h->request('ultracache-runtime-js-scan-collector');
$h->request('ultracache-delayed-js-loader');
$h->request('ultracache-lcp-observer');

$nativeModules = $h->store('native', 'ultracache-runtime-native');
$deferModules = $h->store('defer', 'ultracache-runtime-defer');

$nativeBefore = '<script id="ultracache-runtime-native-js-before">window.config=1;</script>';
$nativeExternal = '<script src="https://example.test/uploads/ultracache/js-bundles/runtime-native-test.js?ver=3.12.18" id="ultracache-runtime-native-js"></script>';
$nativeRendered = $h->finalizeTag($nativeExternal, 'ultracache-runtime-native');

$deferBefore = '<script id="ultracache-runtime-defer-js-before">window.config=1;</script>';
$deferExternal = '<script src="https://example.test/uploads/ultracache/js-bundles/runtime-defer-test.js?ver=3.12.18" id="ultracache-runtime-defer-js" defer></script>';
$deferRendered = $h->finalizeTag($deferExternal, 'ultracache-runtime-defer');

$expect($nativeModules === array('delayed-js-interaction-bootstrap', 'runtime-js-scan-collector'), 'native finalized activation set contains the requested dependency-free native modules');
$expect($deferModules === array('delayed-js-loader', 'lcp-observer'), 'defer finalized activation set contains the requested dependency-free defer modules');
$expect(false === strpos($nativeBefore, 'data-ultracache-modules='), 'native -js-before configuration tag is not activation authority');
$expect(false === strpos($deferBefore, 'data-ultracache-modules='), 'defer -js-before configuration tag is not activation authority');
$expect(false !== strpos($nativeRendered, 'src="https://example.test/uploads/ultracache/js-bundles/runtime-native-test.js?ver=3.12.18"')
    && false !== strpos($nativeRendered, 'data-ultracache-modules="delayed-js-interaction-bootstrap,runtime-js-scan-collector"'),
    'native emitted external script owns src and data-ultracache-modules on the same element');
$expect(false !== strpos($deferRendered, 'src="https://example.test/uploads/ultracache/js-bundles/runtime-defer-test.js?ver=3.12.18"')
    && false !== strpos($deferRendered, 'data-ultracache-modules="delayed-js-loader,lcp-observer"'),
    'defer emitted external script owns src and data-ultracache-modules on the same element');
$expect(false !== strpos($nativeRendered, 'data-ultracache-runtime-bundle="native"'), 'native external tag carries its lane marker');
$expect(false !== strpos($deferRendered, 'data-ultracache-runtime-bundle="defer"'), 'defer external tag carries its lane marker');

$nativeTwice = $h->finalizeTag($nativeRendered, 'ultracache-runtime-native');
$expect(1 === substr_count($nativeTwice, 'data-ultracache-modules='), 'late activation transport guard is idempotent for module metadata');
$expect(1 === substr_count($nativeTwice, 'data-ultracache-runtime-bundle='), 'late activation transport guard is idempotent for lane metadata');

$frontend = file_get_contents($root . '/includes/engine/class-engine-frontend-assets-trait.php');
$engine = file_get_contents($root . '/includes/class-ultra-cache-engine.php');
$expect(is_string($frontend) && false !== strpos($frontend, 'ultracache_store_frontend_runtime_bundle_activation_metadata'), 'bundle finalization persists diagnostic module metadata on the registered external bundle handle');
$expect(is_string($engine) && false !== strpos($engine, "ultracache_finalize_runtime_bundle_activation_script_tag'), PHP_INT_MAX - 1"), 'external bundle diagnostic module metadata is reapplied at the end of the script-loader chain');

if (!empty($failures)) {
    fwrite(STDERR, count($failures) . " failure(s).\n");
    exit(1);
}

echo PHP_EOL . 'Result: ' . $passes . '/' . $passes . ' PASS' . PHP_EOL;
