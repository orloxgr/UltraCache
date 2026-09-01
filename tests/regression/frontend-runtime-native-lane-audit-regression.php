<?php
/**
 * UltraCache 3.12.06 strict frontend native-lane audit regression.
 *
 * Run:
 *   php tests/regression/frontend-runtime-native-lane-audit-regression.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', (string) $key)); }
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/engine/js/class-js-runtime-registry-trait.php';

final class UltraCacheNativeLaneAuditHarness
{
    use Ultra_Cache_Engine_JS_Runtime_Registry_Trait;
    public function definitions(): array { return $this->ultracache_frontend_runtime_module_definitions(); }
}

$passes = 0;
$failures = array();
$expect = static function (bool $condition, string $label) use (&$passes, &$failures): void {
    if ($condition) { $passes++; echo '[PASS] ' . $label . PHP_EOL; return; }
    $failures[] = $label; echo '[FAIL] ' . $label . PHP_EOL;
};

$definitions = (new UltraCacheNativeLaneAuditHarness())->definitions();
$native = array();
$defer = array();
foreach ($definitions as $id => $module) {
    $lane = (string) ($module['lane'] ?? '');
    if ('native' === $lane) { $native[$id] = $module; }
    if ('defer' === $lane) { $defer[$id] = $module; }
}

$expect(array_keys($native) === array(
    'dynamic-script-finder-bootstrap',
    'delayed-js-interaction-bootstrap',
    'lcp-request-credentials-bootstrap',
    'runtime-js-scan-collector',
), 'NATIVE lane contains exactly the four audited parser-early modules');

$normalNative = array_keys(array_filter($native, static function (array $module): bool {
    return empty($module['diagnostic_only']);
}));
$expect($normalNative === array('dynamic-script-finder-bootstrap', 'delayed-js-interaction-bootstrap', 'lcp-request-credentials-bootstrap'), 'normal production NATIVE lane contains only three modules');

foreach ($native as $id => $module) {
    $expect(true === ($module['parser_early_required'] ?? false), 'every NATIVE module has explicit parser-early requirement: ' . $id);
    $expect('' !== (string) ($module['reason'] ?? ''), 'every NATIVE module has an explicit evidence reason: ' . $id);
}
$expect(true === ($native['runtime-js-scan-collector']['diagnostic_only'] ?? false), 'runtime scanner NATIVE cost exists only on explicit diagnostic requests');
$expect(empty($native['dynamic-script-finder-bootstrap']['diagnostic_only']), 'dynamic script finder is normal runtime only when a Delay pipeline is active');
$expect(empty($native['delayed-js-interaction-bootstrap']['diagnostic_only']), 'interaction bootstrap is normal runtime, not diagnostic-only');
$expect(empty($native['lcp-request-credentials-bootstrap']['diagnostic_only']), 'LCP credentials bootstrap is normal runtime while learning is enabled');

$moved = array(
    'async-css-runtime',
    'dynamic-icon-font-delay',
    'font-display-cssom-patch',
    'elementor-compatibility-runtime',
    'mailerlite-lazy-nonce',
    'runtime-font-css-map',
    'woocommerce-cart-fragments-delay',
    'woocommerce-esi-optin',
    'woocommerce-variable-product-guard',
);
foreach ($moved as $id) {
    $expect(isset($defer[$id]), 'non-critical helper is moved to DEFER: ' . $id);
    $expect('defer-native' === ($defer[$id]['route_action'] ?? ''), 'moved helper uses native DEFER transport: ' . $id);
}

$registrySource = file_get_contents($root . '/includes/engine/js/class-js-runtime-registry-trait.php');
$expect(is_string($registrySource) && false === strpos($registrySource, "'policy_debt'  => 'native-lane-audit'"), 'blanket UltraCache helper native-lane debt marker is fully removed');

$asyncCss = file_get_contents($root . '/assets/js/async-css-runtime.js');
$fontCssom = file_get_contents($root . '/assets/js/font-display-cssom-patch.js');
$wcFragments = file_get_contents($root . '/assets/js/woocommerce-cart-fragments-delay.js');
$wcEsi = file_get_contents($root . '/assets/js/woocommerce-esi-optin.js');
$wcVariable = file_get_contents($root . '/assets/js/woocommerce-variable-product-guard.js');
$mailerlite = file_get_contents($root . '/assets/js/mailerlite-lazy-nonce.js');
$expect(is_string($asyncCss) && false !== strpos($asyncCss, 'scan(document);') && false !== strpos($asyncCss, 'if (link.sheet)'), 'async CSS runtime can recover already-loaded managed stylesheets after deferred execution');
$expect(is_string($fontCssom) && false !== strpos($fontCssom, 'patchStyleNodes(document);') && false !== strpos($fontCssom, 'scheduleSheets();'), 'font-display CSSOM runtime rescans existing document state after deferred execution');
$expect(is_string($wcFragments) && false !== strpos($wcFragments, 'installWhenReady(0);') && false !== strpos($wcFragments, 'afterDomReady(function ()'), 'cart-fragments control is designed to install before DOM-ready work rather than parser-time work');
$expect(is_string($wcEsi) && false !== strpos($wcEsi, "document.addEventListener('DOMContentLoaded', optIn"), 'WooCommerce ESI opt-in is DOM-ready work and does not require parser blocking');
$expect(is_string($wcVariable) && false !== strpos($wcVariable, "$(document).on('submit.ultracacheVariationGuard'"), 'variable-product guard uses delegated interaction listeners and can defer');
$expect(is_string($mailerlite) && false !== strpos($mailerlite, "document.addEventListener('submit'") && false !== strpos($mailerlite, "document.addEventListener('pointerdown'"), 'MailerLite nonce runtime is interaction-driven and can defer without blocking the parser');

$debt = require $root . '/tests/architecture/js-policy-debt.php';
$ids = array_column($debt, 'id');
$expect(!in_array('ultracache-helper-blanket-policy', $ids, true), 'completed helper blanket-native debt is removed from architecture debt manifest');

$contract = require $root . '/tests/architecture/js-policy-contract.php';
$expect(true === ($contract['native_lane']['audit_complete'] ?? false), 'architecture contract records completed native-lane audit');
$expect(true === ($contract['native_lane']['optimization_opportunity_alone_is_not_enough'] ?? false), 'parser blocking cannot be justified only by an optimization opportunity');
$expect('unchanged' === ($contract['frozen_existing_behavior']['auto_release'] ?? ''), 'Auto Release remains frozen and untouched');

echo PHP_EOL . 'Result: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . PHP_EOL;
if (!empty($failures)) { exit(1); }
