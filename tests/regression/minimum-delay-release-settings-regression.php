<?php
/**
 * UltraCache 3.12.12 Minimum Delay Release settings/contract regression.
 *
 * Run:
 *   php tests/regression/minimum-delay-release-settings-regression.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
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

$registration = file_get_contents($root . '/includes/settings/class-settings-registration-trait.php');
$validation = file_get_contents($root . '/includes/settings/class-settings-validation-trait.php');
$rendering = file_get_contents($root . '/includes/settings/class-settings-rendering-trait.php');
$schema = file_get_contents($root . '/includes/rest/class-rest-schemas-trait.php');
$cli = file_get_contents($root . '/includes/cli/class-wp-cli-helpers-trait.php');
$settings_js = file_get_contents($root . '/includes/admin/js/settings.js');
$dashboard = file_get_contents($root . '/includes/admin/js/dashboard-application.js');
$delay = file_get_contents($root . '/includes/engine/js/class-js-delay-trait.php');
$loader = file_get_contents($root . '/assets/js/delayed-js-loader.js');
$contract = require $root . '/tests/architecture/js-policy-contract.php';

$expect(is_string($registration) && false !== strpos($registration, "'delayedJsMinimumReleaseSeconds' => 0"), 'A1: setting defaults to Disabled/0 seconds');
$expect(is_string($validation) && false !== strpos($validation, "sanitize_bounded_integer_setting(\$settings['delayedJsMinimumReleaseSeconds']") && false !== strpos($validation, ', 0, 4);'), 'A2: setting is bounded to 0..4 seconds');
$expect(is_string($rendering) && false !== strpos($rendering, "'delayed_js_minimum_release_seconds'"), 'A3: runtime snake_case setting is rendered');
$expect(is_string($schema) && false !== strpos($schema, "'delayedJsMinimumReleaseSeconds'") && false !== strpos($schema, "'type' => 'integer'") && false !== strpos($schema, "'minimum' => 0") && false !== strpos($schema, "'maximum' => 4"), 'A4: REST schema exposes bounded integer setting');
$expect(is_string($cli) && false !== strpos($cli, "'delayedJsMinimumReleaseSeconds'"), 'A5: CLI setting import accepts minimum release value');
$expect(is_string($settings_js) && false !== strpos($settings_js, "'delayedJsMinimumReleaseSeconds'") && false !== strpos($settings_js, '"delayedJsMinimumReleaseSeconds": 0'), 'A6: import/export and Wizard recipe preserve explicit Disabled default');

$expect(is_string($dashboard) && false !== strpos($dashboard, "label: __('Minimum Delay Release'"), 'B1: JavaScript UI exposes Minimum Delay Release');
foreach (array("{ value: '0', label: __('Disabled'", "{ value: '1', label: __('1 second'", "{ value: '2', label: __('2 seconds'", "{ value: '3', label: __('3 seconds'", "{ value: '4', label: __('4 seconds'") as $needle) {
    $expect(false !== strpos((string) $dashboard, $needle), 'B2: UI option exists: ' . $needle);
}
$expect(false !== strpos((string) $dashboard, 'It does not trigger release by itself'), 'B3: UI explains gate-not-trigger semantics');

$expect(is_string($delay) && false !== strpos($delay, "\$minimum_release_seconds = isset(\$settings['delayed_js_minimum_release_seconds'])"), 'C1: frontend loader config reads only the new runtime gate setting');
$expect(is_string($delay) && false !== strpos($delay, "'minimumReleaseMs'  => (int) round(1000 * \$minimum_release_seconds)"), 'C2: gate is transported in existing delayed-js-loader config');
$expect(is_string($loader) && false !== strpos($loader, "holdForMinimumRelease('full')") && false !== strpos($loader, "holdForMinimumRelease('interaction')"), 'C3: both full and interaction release requests use one minimum gate');
$expect(is_string($loader) && false !== strpos($loader, 'minimumReleaseAtMs = pageNavigationStartMs() + minimumReleaseMs'), 'C4: gate uses absolute page-navigation threshold');

$minimum = isset($contract['minimum_delay_release']) && is_array($contract['minimum_delay_release']) ? $contract['minimum_delay_release'] : array();
$expect('3.13.10' === ($contract['contract_version'] ?? ''), 'D1: current architecture contract version is 3.13.10');
$expect(true === ($minimum['is_gate_not_trigger'] ?? false), 'D2: contract freezes gate-not-trigger semantics');
$expect(array(0, 1, 2, 3, 4) === ($minimum['allowed_seconds'] ?? array()), 'D3: contract freezes Disabled/1/2/3/4 options');
$expect('page_navigation_start' === ($minimum['clock_origin'] ?? ''), 'D4: contract freezes page-navigation clock origin');
$expect(true === ($minimum['interaction_precedes_pending_full_request'] ?? false), 'D5: interaction-first pending ordering is frozen');
$expect('unchanged' === ($contract['frozen_existing_behavior']['auto_release'] ?? ''), 'D6: existing Auto Release remains frozen unchanged');
$expect(false === ($minimum['adds_frontend_request'] ?? true), 'D7: feature adds no frontend request');

// Existing Auto Release option set must remain intact.
foreach (array('infinite', '0.05', '0.1', '0.5', '1', '2', '3', '4', '5') as $value) {
    $expect(false !== strpos((string) $dashboard, "{ value: '" . $value . "'"), 'E: existing Auto Release option remains: ' . $value);
}


echo PHP_EOL . 'Result: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . PHP_EOL;
if (!empty($failures)) {
    exit(1);
}
