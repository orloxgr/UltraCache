<?php
/**
 * UltraCache 3.12.09 explicit JavaScript integrations regression.
 *
 * Run:
 *   php tests/regression/explicit-js-integrations-regression.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = array(
    'delay' => file_get_contents($root . '/includes/engine/js/class-js-delay-trait.php'),
    'exclude' => file_get_contents($root . '/includes/engine/js/class-js-exclusions-trait.php'),
    'registration' => file_get_contents($root . '/includes/settings/class-settings-registration-trait.php'),
    'validation' => file_get_contents($root . '/includes/settings/class-settings-validation-trait.php'),
    'rendering' => file_get_contents($root . '/includes/settings/class-settings-rendering-trait.php'),
    'admin' => file_get_contents($root . '/includes/admin/js/dashboard-application.js'),
    'rest' => file_get_contents($root . '/includes/rest/class-rest-schemas-trait.php'),
);

$failures = array();
$passes = 0;
$expect = static function (bool $ok, string $label) use (&$failures, &$passes): void {
    if ($ok) {
        $passes++;
        echo '[PASS] ' . $label . PHP_EOL;
        return;
    }
    $failures[] = $label;
    echo '[FAIL] ' . $label . PHP_EOL;
};

$expect(false !== strpos($files['registration'], "'woocommerceVariableProductCompatibilityEnabled' => false"), 'WooCommerce JS Compatibility canonical fresh-install default is OFF');
$expect(false !== strpos($files['rendering'], "'protect_woocommerce_variable_product_compatibility'"), 'WooCommerce JS Compatibility is mapped into runtime settings');
$expect(false !== strpos($files['rest'], "'woocommerceVariableProductCompatibilityEnabled'"), 'WooCommerce JS Compatibility is writable through the normal settings REST schema');
$expect(false !== strpos($files['admin'], 'WooCommerce JS Compatibility'), 'WooCommerce JS Compatibility switch is visible in the WooCommerce JavaScript card');
$expect(false !== strpos($files['delay'], "empty(\$settings['protect_woocommerce_variable_product_compatibility'])"), 'variable-product protection explicitly checks the integration switch');
$expect(false === strpos($files['exclude'], 'ultracache-woocommerce-variable-product-guard'), 'generic force-blocking contains no WooCommerce helper identity');
$expect(false === strpos($files['delay'], "'contact-form-7-js'"), 'Contact Form 7 handle is absent from the generic delay classifier');
$expect(false === strpos($files['delay'], "'author-arc-handler-js'"), 'author-arc handle is absent from the generic delay classifier');
$expect(false === strpos($files['registration'], "'contact-form-7-js'"), 'Contact Form 7 is not forced into canonical Do Not Defer or Delay defaults');
$expect(false === strpos($files['registration'], "'author-arc-handler-js'"), 'author-arc is not forced into canonical Do Not Defer or Delay defaults');
$expect(false === strpos($files['validation'], 'migrate_js_visible_vendor_fallbacks_31209'), 'no 3.12.09 JavaScript vendor-list migration remains');
$expect(false === strpos($files['validation'], 'migrate_js_visible_policy_defaults_31208'), 'no 3.12.08 JavaScript visible-list migration remains');

$debt = require $root . '/tests/architecture/js-policy-debt.php';
$expect(array() === $debt, 'hidden vendor-specific JavaScript policy debt is zero');

$contract = require $root . '/tests/architecture/js-policy-contract.php';
$expect('woocommerceVariableProductCompatibilityEnabled' === ($contract['explicit_integrations']['woocommerce_variable_products'] ?? ''), 'policy contract records the explicit WooCommerce integration switch');

$autoRegistration = $files['registration'];
$autoValidation = $files['validation'];
$expect(false !== strpos($autoRegistration, "'delayedLocalJsAutoStartSeconds' => 0.05"), 'existing Auto Release default remains present');
$expect(false !== strpos($autoValidation, "sanitize_bounded_number_setting(\$settings['delayedLocalJsAutoStartSeconds']"), 'existing Auto Release sanitizer remains present');

echo PHP_EOL . 'Result: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . PHP_EOL;
if ($failures) {
    exit(1);
}
