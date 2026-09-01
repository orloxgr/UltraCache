<?php
/**
 * UltraCache 3.12.13 JavaScript architecture policy-contract regression.
 *
 * This test freezes the 3.12 visible-policy authority model and the remaining
 * explicit policy debt so future releases can only reduce it, not silently
 * add more hidden scheduling rules.
 *
 * Run:
 *   php tests/regression/js-policy-contract-regression.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$contract = require $root . '/tests/architecture/js-policy-contract.php';
$debt = require $root . '/tests/architecture/js-policy-debt.php';

$failures = array();
$passes = 0;

$expect = static function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes++;
        echo '[PASS] ' . $label . PHP_EOL;
        return;
    }

    $failures[] = $label;
    echo '[FAIL] ' . $label . PHP_EOL;
};

$expect('3.13.10' === ($contract['contract_version'] ?? ''), 'contract version is 3.13.10');
$expect(array('native', 'defer', 'delay') === ($contract['lanes'] ?? array()), 'execution lanes are exactly NATIVE / DEFER / DELAY');
$expect(in_array('visible_lists_are_authoritative', $contract['principles'] ?? array(), true), 'visible lists are declared authoritative');
$expect(in_array('generic_engine_does_not_use_vendor_identity_as_hidden_policy', $contract['principles'] ?? array(), true), 'generic engine forbids hidden vendor-identity policy');
$expect(array('html_js_semantics', 'explicit_author_opt_out', 'explicit_integration_switch') === ($contract['allowed_hidden_exception_categories'] ?? array()), 'only three hidden-exception categories are allowed');
$expect('delay' === ($contract['visible_policy']['delay']['default_lane'] ?? ''), 'Delay strategy default lane is DELAY');
$expect('Defer Instead of Delay' === ($contract['visible_policy']['delay']['defer_list'] ?? ''), 'Delay strategy DEFER override is the visible Defer Instead list');
$expect('Do Not Defer or Delay' === ($contract['visible_policy']['delay']['native_list'] ?? ''), 'Delay strategy NATIVE override is the visible Do Not Defer or Delay list');
$expect('defer' === ($contract['visible_policy']['defer']['default_lane'] ?? ''), 'Defer strategy default lane is DEFER');
$expect(array('native_list', 'defer_list', 'default_lane') === ($contract['visible_policy']['precedence'] ?? array()), 'visible policy precedence is NATIVE list, then DEFER list, then strategy default');
$expect(true === ($contract['visible_policy']['native_wins_on_overlap'] ?? false), 'Do Not Defer or Delay wins when visible lists overlap');
$expect(false === ($contract['visible_policy']['post_list_hidden_lane_override'] ?? true), 'no hidden generic classifier may override a resolved visible-list lane');
$expect(true === ($contract['visible_policy']['defaults_are_user_editable'] ?? false), 'default policy patterns remain user-editable rather than hidden');
$expect('unchanged' === ($contract['frozen_existing_behavior']['auto_release'] ?? ''), 'existing Auto Release behavior is explicitly frozen');
$expect(true === ($contract['minimum_delay_release']['is_gate_not_trigger'] ?? false), 'Minimum Delay Release is a gate and never a release trigger');
$expect(array(0, 1, 2, 3, 4) === ($contract['minimum_delay_release']['allowed_seconds'] ?? array()), 'Minimum Delay Release visible options are Disabled/1/2/3/4 seconds');
$expect('page_navigation_start' === ($contract['minimum_delay_release']['clock_origin'] ?? ''), 'Minimum Delay Release uses page-navigation start as its clock origin');
$expect(true === ($contract['minimum_delay_release']['interaction_precedes_pending_full_request'] ?? false), 'pending interaction release resumes before a pending full request');
$expect('teacher' === ($contract['scanner_policy']['role'] ?? ''), 'Runtime Scanner is a teacher rather than a hidden policy authority');
$expect(false === ($contract['scanner_policy']['diagnostic_queue_is_policy_authority'] ?? true), 'diagnostic queue cannot act as execution-policy authority');
$expect(false === ($contract['scanner_policy']['stored_findings_affect_runtime'] ?? true), 'stored diagnostic findings cannot affect runtime execution');
$expect('visible-setting-only' === ($contract['scanner_policy']['automatic_write_mode'] ?? ''), 'automatic Runtime Scan fixes may write visible settings only');
$expect(true === ($contract['scanner_policy']['automatic_apply_requires_policy_descriptor'] ?? false), 'automatic Runtime Scan apply requires a visible-policy descriptor');
$expect(false === ($contract['scanner_policy']['hidden_override_storage'] ?? true), 'scanner contract forbids hidden override storage');
$expect(array('delay', 'force', 'exclusion') === array_keys($contract['scanner_policy']['allowed_targets'] ?? array()), 'scanner automatic policy targets are exactly Delay / Defer Instead / Do Not Defer');
$expect('ultracache_build_registered_script_route' === ($contract['router']['registered_script_entry_point'] ?? ''), 'central router is the registered-script classification authority');
$expect('ultracache_apply_registered_script_route' === ($contract['router']['route_application'] ?? ''), 'route application is separately declared from policy');
$expect(false === ($contract['router']['production_telemetry'] ?? true), 'central router adds no production telemetry');
$expect('native' === ($contract['router']['async_transport_lane'] ?? ''), 'async remains a transport inside NATIVE instead of creating a fourth lane');
$expect('ultracache_frontend_runtime_modules' === ($contract['runtime_registry']['entry_point'] ?? ''), 'contract defines one canonical UltraCache frontend runtime registry');
$expect(false === ($contract['runtime_registry']['production_telemetry'] ?? true), 'runtime registry adds no production telemetry');
$expect('runtime_bundle_registry' === ($contract['runtime_registry']['activation_authority'] ?? ''), 'runtime registry activation authority is the lane-bundle registry');
$expect(array('native', 'defer', 'delay') === ($contract['runtime_bundles']['lanes'] ?? array()), 'runtime bundles map exactly to the three execution lanes');
$expect(1 === ($contract['runtime_bundles']['max_normal_path_network_assets_per_lane'] ?? 0), 'normal bundled path permits at most one UltraCache network asset per active lane');
$expect(false === ($contract['runtime_bundles']['inactive_module_source_loading'] ?? true), 'inactive UltraCache module source is not included in generated bundles');
$expect('uploads/ultracache/js-bundles' === ($contract['runtime_bundles']['generated_asset_root'] ?? ''), 'generated runtime bundles use the private UltraCache uploads asset root');
$expect(true === ($contract['runtime_bundles']['content_addressed_variants'] ?? false), 'runtime bundle variants are content addressed');
$expect(true === ($contract['runtime_bundles']['standalone_fallback_on_generation_failure'] ?? false), 'standalone helper fallback remains available if bundle generation fails');
$expect(false === ($contract['runtime_bundles']['production_telemetry'] ?? true), 'runtime bundling adds no production telemetry');
$expect(true === ($contract['native_lane']['audit_complete'] ?? false), 'UltraCache native-lane self-cost audit is complete');
$expect(array('dynamic-script-finder-bootstrap', 'delayed-js-interaction-bootstrap', 'lcp-request-credentials-bootstrap') === ($contract['native_lane']['normal_production_modules'] ?? array()), 'normal production parser-early lane is restricted to dynamic discovery plus unrecoverable interaction/LCP capture');
$expect(array('runtime-js-scan-collector') === ($contract['native_lane']['diagnostic_only_modules'] ?? array()), 'runtime scanner is the only diagnostic-only parser-early module');
$expect(true === ($contract['native_lane']['optimization_opportunity_alone_is_not_enough'] ?? false), 'optimization opportunity alone is not accepted as parser-early justification');

$delay_source = file_get_contents($root . '/includes/engine/js/class-js-delay-trait.php');
$registry_source = file_get_contents($root . '/includes/engine/js/class-js-runtime-registry-trait.php');
$admin_source = file_get_contents($root . '/includes/admin/js/dashboard-application.js');
$expect(is_string($delay_source) && false !== strpos($delay_source, "empty(\$settings['protect_elementor_compatibility'])"), 'Elementor vendor-specific scheduling remains behind an explicit setting');
$expect(is_string($delay_source) && false !== strpos($delay_source, "empty(\$settings['protect_wpbakery_animations'])"), 'WPBakery vendor-specific scheduling remains behind an explicit setting');
$expect(is_string($delay_source) && false !== strpos($delay_source, "empty(\$settings['protect_woocommerce_variable_product_compatibility'])"), 'WooCommerce variable-product scheduling is behind an explicit setting');
$expect(is_string($admin_source) && false !== strpos($admin_source, 'Elementor Compatibility'), 'Elementor integration switch is visible in JavaScript settings');
$expect(is_string($admin_source) && false !== strpos($admin_source, 'Protect WPBakery animations'), 'WPBakery integration switch is visible in JavaScript settings');
$expect(is_string($admin_source) && false !== strpos($admin_source, 'WooCommerce JS Compatibility'), 'WooCommerce variable-product integration switch is visible in JavaScript settings');
$expect(is_string($registry_source) && false !== strpos($registry_source, 'ultracache_frontend_runtime_module_definitions') && false !== strpos($registry_source, "'enabled'"), 'runtime registry exposes canonical definitions and current-request enabled state');

/**
 * Count a literal needle across the manifest scope.
 *
 * @param string[] $scope Relative directories.
 */
$count_in_scope = static function (array $scope, string $needle) use ($root): int {
    $count = 0;
    foreach ($scope as $relative_path) {
        $absolute_path = $root . '/' . ltrim($relative_path, '/');
        if (is_file($absolute_path)) {
            $contents = file_get_contents($absolute_path);
            if (is_string($contents) && '' !== $contents) {
                $count += substr_count($contents, $needle);
            }
            continue;
        }
        if (!is_dir($absolute_path)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute_path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $extension = strtolower((string) $file->getExtension());
            if (!in_array($extension, array('php', 'js'), true)) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if (!is_string($contents) || '' === $contents) {
                continue;
            }
            $count += substr_count($contents, $needle);
        }
    }
    return $count;
};

$ids = array();
foreach ($debt as $entry) {
    $id = (string) ($entry['id'] ?? '');
    $needle = (string) ($entry['needle'] ?? '');
    $scope = isset($entry['scope']) && is_array($entry['scope']) ? $entry['scope'] : array();
    $expected_count = (int) ($entry['expected_count'] ?? -1);
    $target = (string) ($entry['target'] ?? '');

    $expect('' !== $id && !isset($ids[$id]), 'debt entry has unique id: ' . $id);
    $ids[$id] = true;
    $expect('' !== $needle && !empty($scope) && $expected_count >= 0 && '' !== $target, 'debt entry is fully specified: ' . $id);
    $actual_count = $count_in_scope($scope, $needle);
    $expect($expected_count === $actual_count, 'known debt count is frozen for ' . $id . ' (' . $actual_count . ')');
}

$expect(count($debt) === 0, '3.12.11 keeps hidden vendor-specific JavaScript policy debt at zero');

$expect(false === ($contract['consent_policy']['hidden_control_plane_classifier'] ?? true), 'hidden consent control-plane classifier is removed');
$expect(false === ($contract['consent_policy']['hidden_native_lifecycle_override'] ?? true), 'hidden consent native-lifecycle override is removed');
$expect(false === ($contract['consent_policy']['dynamic_tracker_gate'] ?? true), 'temporary dynamic tracker gate is removed');
$expect(false === ($contract['consent_policy']['vendor_fingerprint_changes_lane'] ?? true), 'consent/CMP vendor fingerprint cannot change a lane');
$expect(true === ($contract['consent_policy']['consent_assets_follow_normal_router_and_visible_policy'] ?? false), 'consent assets follow normal router and visible policy');

$readme = file_get_contents($root . '/tests/regression/README.md');
$expect(is_string($readme) && false !== strpos($readme, '3.12.03 Central Script Router'), 'regression README documents the central router');
$expect(is_string($readme) && false !== strpos($readme, '3.12.07 Hidden Consent Policy Removal'), 'regression README documents hidden consent-policy removal');
$expect(is_string($readme) && false !== strpos($readme, '3.12.08 Visible Lists Final Authority'), 'regression README documents visible-list final authority');
$expect(is_string($readme) && false !== strpos($readme, '3.12.09 Explicit Integrations Only'), 'regression README documents explicit-integration boundary');
$expect(is_string($readme) && false !== strpos($readme, '3.12.10 Generic Dynamic Script Finder'), 'regression README documents generic dynamic-script discovery');
$expect(is_string($readme) && false !== strpos($readme, '3.12.11 Unified JavaScript Routing'), 'regression README documents unified JavaScript routing');
$expect(is_string($readme) && false !== strpos($readme, '3.12.12 Minimum Delay Release'), 'regression README documents the additive minimum release gate');
$expect(is_string($readme) && false !== strpos($readme, '3.12.13 Scanner as Teacher'), 'regression README documents the visible-policy-only Scanner boundary');

echo PHP_EOL . 'Result: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . PHP_EOL;
if (!empty($failures)) {
    exit(1);
}
