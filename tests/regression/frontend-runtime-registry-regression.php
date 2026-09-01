<?php
/**
 * UltraCache 3.12.10 frontend runtime registry regression.
 *
 * Run:
 *   php tests/regression/frontend-runtime-registry-regression.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $key));
    }
}
$GLOBALS['ultracache_registry_enqueued'] = array();
if (!function_exists('wp_script_is')) {
    function wp_script_is($handle, $status = 'enqueued') {
        return 'enqueued' === $status && !empty($GLOBALS['ultracache_registry_enqueued'][(string) $handle]);
    }
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/engine/js/class-js-runtime-registry-trait.php';

final class UltraCacheRuntimeRegistryHarness
{
    use Ultra_Cache_Engine_JS_Runtime_Registry_Trait;

    public function definitions(): array
    {
        return $this->ultracache_frontend_runtime_module_definitions();
    }

    public function modules(): array
    {
        return $this->ultracache_frontend_runtime_modules();
    }

    public function byHandle(string $handle): array
    {
        return $this->ultracache_get_frontend_runtime_module($handle);
    }

    public function bySrc(string $src): array
    {
        return $this->ultracache_get_frontend_runtime_module_by_src($src);
    }

    public function request(string $handle): array
    {
        return $this->ultracache_request_frontend_runtime_module($handle);
    }

    public function bundles(): array
    {
        return $this->ultracache_frontend_runtime_bundle_definitions();
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

$h = new UltraCacheRuntimeRegistryHarness();
$definitions = $h->definitions();
$expected = array(
    'ultracache-async-css-runtime' => array('async-css-runtime.js', 'defer', array()),
    'ultracache-delayed-js-interaction-bootstrap' => array('delayed-js-interaction-bootstrap.js', 'native', array()),
    'ultracache-dynamic-script-finder-bootstrap' => array('dynamic-script-finder-bootstrap.js', 'native', array()),
    'ultracache-delayed-js-loader' => array('delayed-js-loader.js', 'defer', array()),
    'ultracache-inline-registry-dispatcher' => array('inline-registry-dispatcher.js', 'defer', array()),
    'ultracache-elementor-compatibility-runtime' => array('elementor-compatibility-runtime.js', 'defer', array()),
    'ultracache-dynamic-icon-font-delay' => array('dynamic-icon-font-delay.js', 'defer', array()),
    'ultracache-font-display-cssom-patch' => array('font-display-cssom-patch.js', 'defer', array()),
    'ultracache-lazy-third-party-iframes' => array('lazy-third-party-iframes.js', 'defer', array()),
    'ultracache-lcp-observer' => array('lcp-observer.js', 'defer', array()),
    'ultracache-lcp-request-credentials-bootstrap' => array('lcp-request-credentials-bootstrap.js', 'native', array()),
    'ultracache-mailerlite-lazy-nonce' => array('mailerlite-lazy-nonce.js', 'defer', array()),
    'ultracache-runtime-font-css-map' => array('runtime-font-css-map.js', 'defer', array()),
    'ultracache-runtime-js-scan-collector' => array('runtime-js-scan-collector.js', 'native', array()),
    'ultracache-woocommerce-cart-fragments-delay' => array('woocommerce-cart-fragments-delay.js', 'defer', array('jquery')),
    'ultracache-woocommerce-esi-optin' => array('woocommerce-esi-optin.js', 'defer', array()),
    'ultracache-woocommerce-variable-product-guard' => array('woocommerce-variable-product-guard.js', 'defer', array('jquery')),
);

$expect(count($expected) === count($definitions), 'registry contains every current UltraCache frontend JS runtime module exactly once');
$seen_handles = array();
$seen_assets = array();
foreach ($definitions as $id => $module) {
    $handle = (string) ($module['handle'] ?? '');
    $asset = (string) ($module['asset'] ?? '');
    $lane = (string) ($module['lane'] ?? '');
    $deps = isset($module['dependencies']) && is_array($module['dependencies']) ? array_values($module['dependencies']) : array();
    $expect(in_array($lane, array('native', 'defer', 'delay'), true), 'module lane is one of NATIVE / DEFER / DELAY: ' . $id);
    $expect('' !== (string) ($module['reason'] ?? ''), 'module has an explicit lane reason: ' . $id);
    $expect(isset($expected[$handle]), 'module handle is part of the canonical inventory: ' . $handle);
    if (isset($expected[$handle])) {
        $expect($expected[$handle][0] === $asset && $expected[$handle][1] === $lane && $expected[$handle][2] === $deps, 'module asset/lane/dependencies match audited 3.12.10 behavior: ' . $handle);
    }
    $expect(!isset($seen_handles[$handle]), 'module handle is unique: ' . $handle);
    $expect(!isset($seen_assets[$asset]), 'module asset is unique: ' . $asset);
    $seen_handles[$handle] = true;
    $seen_assets[$asset] = true;
}

$loader = $h->byHandle('ultracache-delayed-js-loader-js');
$expect('defer' === ($loader['lane'] ?? '') && 'delayed-js-loader.js' === ($loader['asset'] ?? ''), 'rendered -js id resolves to canonical delayed loader module');
$bootstrap = $h->bySrc('https://site.example/wp-content/plugins/ultracache/assets/js/delayed-js-interaction-bootstrap.js?ver=3.12.10');
$expect('native' === ($bootstrap['lane'] ?? '') && 'opaque-bootstrap' === ($bootstrap['route_action'] ?? ''), 'asset URL resolves to parser-early interaction bootstrap module');
$expect(array() === $h->bySrc('https://site.example/wp-content/themes/example/app.js'), 'non-UltraCache asset is not claimed by runtime registry');

$h->request('ultracache-lcp-observer');
$modules = $h->modules();
$expect(true === ($modules['lcp-observer']['enabled'] ?? false), 'enabled reflects current-request runtime request state for an active module');
$expect(false === ($modules['mailerlite-lazy-nonce']['enabled'] ?? true), 'enabled remains false for a module not enqueued on this request');

$bundles = $h->bundles();
$expect(array_keys($bundles) === array('native', 'defer', 'delay'), 'runtime registry exposes exactly three canonical lane bundles');
$expect('ultracache-runtime-native' === ($bundles['native']['handle'] ?? ''), 'native lane has one canonical bundle handle');
$expect('ultracache-runtime-defer' === ($bundles['defer']['handle'] ?? ''), 'defer lane has one canonical bundle handle');
$expect('ultracache-runtime-delay' === ($bundles['delay']['handle'] ?? ''), 'delay lane has one canonical bundle handle');

$frontend_assets = file_get_contents($root . '/includes/engine/class-engine-frontend-assets-trait.php');
$router = file_get_contents($root . '/includes/engine/js/class-js-router-trait.php');
$classification = file_get_contents($root . '/includes/engine/js/class-js-classification-trait.php');
$expect(is_string($frontend_assets) && false !== strpos($frontend_assets, 'ultracache_get_frontend_runtime_module($handle)'), 'frontend helper registration consumes canonical registry metadata');
$expect(is_string($router) && false !== strpos($router, '$runtime_module = $this->ultracache_get_frontend_runtime_module($handle);'), 'Central Script Router consumes runtime registry before legacy page-script policy');
$expect(is_string($classification) && false === strpos($classification, "'ultracache-mailerlite-lazy-nonce',") && false === strpos($classification, "/mailerlite-lazy-nonce.js"), 'legacy duplicated helper handle/asset inventory is removed from classification trait');

$asset_files = glob($root . '/assets/js/*.js');
$asset_basenames = array_map('basename', is_array($asset_files) ? $asset_files : array());
sort($asset_basenames);
$registry_assets = array_keys($seen_assets);
sort($registry_assets);
$expect($asset_basenames === $registry_assets, 'every production assets/js JavaScript file is represented by the runtime registry');

$source = file_get_contents($root . '/includes/engine/js/class-js-runtime-registry-trait.php');
$expect(is_string($source) && false === strpos($source, 'update_option(') && false === strpos($source, 'wp_remote_') && false === strpos($source, 'fetch('), 'registry adds no production telemetry or network side effects');

echo PHP_EOL . 'Result: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . PHP_EOL;
if (!empty($failures)) {
    exit(1);
}
