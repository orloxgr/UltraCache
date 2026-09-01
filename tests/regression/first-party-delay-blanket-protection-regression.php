<?php
/**
 * UltraCache 3.11.29 first-party Delay blanket-protection regression.
 *
 * This source-contract test intentionally runs without WordPress. It guards
 * the architecture of should_delay_non_critical_script(): adaptive Elementor
 * and explicit integration switches must remain authoritative, and
 * stale blanket Elementor/WooCommerce exclusions must not be reintroduced.
 *
 * Run:
 *   php tests/regression/first-party-delay-blanket-protection-regression.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/includes/engine/js/class-js-delay-trait.php';
$source = file_get_contents($path);
$router = file_get_contents($root . '/includes/engine/js/class-js-router-trait.php');
if (!is_string($source) || '' === $source) {
    fwrite(STDERR, "[FAIL] Unable to read class-js-delay-trait.php\n");
    exit(1);
}

$start = strpos($source, 'private function should_delay_non_critical_script');
$end = strpos($source, 'private function should_delay_script', $start === false ? 0 : $start + 1);
if (false === $start || false === $end || $end <= $start) {
    fwrite(STDERR, "[FAIL] Unable to isolate should_delay_non_critical_script()\n");
    exit(1);
}

$body = substr($source, $start, $end - $start);
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

$expect(is_string($router) && false !== strpos($router, 'should_protect_elementor_compatibility_script('), 'adaptive Elementor compatibility resolver remains active as a unified-router fact');
$expect(is_string($router) && false !== strpos($router, 'should_protect_woocommerce_variable_product_interaction_script('), 'targeted WooCommerce variable-product resolver remains available behind its explicit integration switch');
$expect(false === strpos($body, "'elementor-webpack-runtime'"), 'Elementor webpack runtime is not blanket force-blocked');
$expect(false === strpos($body, "'elementor-pro-webpack-runtime'"), 'Elementor Pro webpack runtime is not blanket force-blocked');
$expect(false === strpos($body, "'elementor-frontend-js'"), 'Elementor frontend is not blanket force-blocked');
$expect(false === strpos($body, "'elementor-pro-frontend-js'"), 'Elementor Pro frontend is not blanket force-blocked');
$expect(false === strpos($body, "'/woocommerce/assets/'"), 'WooCommerce asset path is not blanket force-blocked');
$expect(false === strpos($body, 'ultracache_plugins_public_path(\'woocommerce\')'), 'WooCommerce plugin root is not blanket force-blocked');
$expect(false === strpos($body, "'contact-form-7-js'"), 'Contact Form 7 is not hardcoded in the generic delay classifier');
$expect(false === strpos($body, "'author-arc-handler-js'"), 'author arc is not hardcoded in the generic delay classifier');

$expect(false === strpos($body, '$forced_blocking_handles'), 'generic first-party Delay classifier has no residual hardcoded vendor fallback table');
$expect(false !== strpos($body, "empty(\$settings['protect_woocommerce_variable_product_compatibility'])") || false !== strpos($source, "empty(\$settings['protect_woocommerce_variable_product_compatibility'])"), 'WooCommerce variable-product resolver is gated by explicit runtime policy');

echo PHP_EOL . 'Result: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . PHP_EOL;
if (!empty($failures)) {
    exit(1);
}
