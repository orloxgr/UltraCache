<?php
/**
 * UltraCache 3.12.16 final hidden vendor-rules audit regression.
 *
 * Run:
 *   php tests/regression/final-hidden-vendor-rules-regression.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
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

$methodBody = static function (string $source, string $method, string $nextMethod = ''): string {
    $start = strpos($source, 'function ' . $method . '(');
    if (false === $start) {
        return '';
    }
    if ('' === $nextMethod) {
        return substr($source, $start);
    }
    $end = strpos($source, 'function ' . $nextMethod . '(', $start + 1);
    return false === $end ? substr($source, $start) : substr($source, $start, $end - $start);
};

$delay = (string) file_get_contents($root . '/includes/engine/js/class-js-delay-trait.php');
$defer = (string) file_get_contents($root . '/includes/engine/js/class-js-defer-trait.php');
$exclusions = (string) file_get_contents($root . '/includes/engine/js/class-js-exclusions-trait.php');
$loader = (string) file_get_contents($root . '/assets/js/delayed-js-loader.js');
$elementorRuntime = (string) file_get_contents($root . '/assets/js/elementor-compatibility-runtime.js');
$elementorIntegration = (string) file_get_contents($root . '/includes/integrations/elementor/class-elementor-js-compatibility-trait.php');
$engine = (string) file_get_contents($root . '/includes/class-ultra-cache-engine.php');
$registry = (string) file_get_contents($root . '/includes/engine/js/class-js-runtime-registry-trait.php');

$nonCritical = $methodBody($delay, 'get_non_critical_delay_patterns', 'matches_non_critical_delay_patterns');
$forbiddenNonCritical = array(
    'sitekit', 'mailerlite', 'mc4wp', 'tooltipster', 'magnific-popup', 'perfect-scrollbar',
    'plainoverlay', 'ion.range', 'icheck', 'easy-autocomplete', 'jarallax', 'tweenmax',
    'gsap', 'sticky-kit', 'slick', 'swiper', 'elementor', 'woocommerce', 'complianz',
);
foreach ($forbiddenNonCritical as $token) {
    $expect(false === stripos($nonCritical, $token), 'non-critical generic classifier contains no hidden vendor/library identity: ' . $token);
}
foreach (array('carousel', 'slider', 'popup', 'modal', 'lightbox', 'offcanvas', 'animation') as $token) {
    $expect(false !== strpos($nonCritical, "'" . $token . "'"), 'non-critical classifier retains generic semantic candidate: ' . $token);
}

$async = $methodBody($defer, 'should_async_external_script', 'is_defer_all_js_candidate');
$expect(false !== strpos($async, 'get_safe_third_party_delay_patterns($settings)'), 'Async external transport consumes the visible Delay third-party JS Patterns list');
$expect(false !== strpos($async, 'get_matching_third_party_delay_pattern'), 'Async external transport uses the normal visible-pattern matcher');
foreach (array('googletagmanager.com', 'google-analytics.com', 'facebook', 'hotjar', 'clarity.ms', 'tiktok', 'linkedin', 'yandex') as $token) {
    $expect(false === stripos($async, $token), 'Async external transport has no private vendor allowlist token: ' . $token);
}

$rootCheck = $methodBody($exclusions, 'is_generic_root_js_exclusion_fragment', 'generic_root_js_exclusion_matches_haystacks');
$rootMatch = $methodBody($exclusions, 'generic_root_js_exclusion_matches_haystacks', 'normalize_js_fragment_match_text');
$legacyCluster = $methodBody($exclusions, 'matched_exclusions_need_jquery_legacy_cluster', 'get_defer_stage_user_exclude_fragments');
$expect(false === stripos($rootCheck, 'woocommerce') && false === stripos($rootMatch, 'woocommerce'), 'generic visible-exclusion matcher has no WooCommerce special case');
$expect(false === stripos($legacyCluster, 'elementor') && false === stripos($legacyCluster, 'woocommerce-coupon-box') && false === stripos($legacyCluster, 'wcb_params'), 'legacy jQuery cluster heuristic has no Elementor/Woo vendor triggers');

$expect(false === stripos($loader, 'elementor'), 'generic delayed-js-loader contains no Elementor-specific runtime');
$expect(false !== stripos($elementorRuntime, '.e-con.e-parent') && false !== stripos($elementorRuntime, 'e-lazyloaded'), 'Elementor lazy-background behavior lives in dedicated runtime asset');
$expect(false !== strpos($elementorIntegration, "empty(\$settings['protect_elementor_compatibility'])"), 'Elementor runtime enqueue path is guarded by explicit compatibility switch');
$expect(false !== strpos($elementorIntegration, "ultracache_is_frontend_runtime_module_requested('ultracache-delayed-js-loader')"), 'Elementor runtime is requested only when Delay runtime is active');
$expect(false !== strpos($elementorIntegration, "'ultracache-elementor-compatibility-runtime'"), 'Elementor integration requests dedicated runtime module');
$expect(false !== strpos($engine, "'enqueue_elementor_compatibility_runtime_helper'"), 'frontend hook registers explicit Elementor runtime enqueue path');
$expect(false !== strpos($registry, "'elementor-compatibility-runtime' => array(") && false !== strpos($registry, "'lane'         => 'defer'"), 'runtime registry owns dedicated Elementor compatibility module in DEFER lane');

$debt = require $root . '/tests/architecture/js-policy-debt.php';
$expect(array() === $debt, 'hidden vendor scheduling debt manifest remains empty after final audit');
$contract = require $root . '/tests/architecture/js-policy-contract.php';
$expect('3.13.10' === ($contract['contract_version'] ?? ''), 'architecture contract is versioned 3.13.10');
$expect(true === ($contract['final_vendor_policy_audit']['complete'] ?? false), 'architecture contract records completed final vendor-policy audit');
$expect(0 === ($contract['final_vendor_policy_audit']['hidden_vendor_scheduling_debt'] ?? -1), 'architecture contract freezes hidden vendor scheduling debt at zero');
$expect('generic-semantic-only' === ($contract['final_vendor_policy_audit']['non_critical_classifier'] ?? ''), 'architecture contract freezes vendor-neutral non-critical classifier');
$expect('Delay third-party JS Patterns' === ($contract['final_vendor_policy_audit']['async_external_pattern_authority'] ?? ''), 'architecture contract freezes visible Async external pattern authority');
$expect(false === ($contract['final_vendor_policy_audit']['generic_delay_loader_vendor_runtime'] ?? true), 'architecture contract forbids vendor runtime inside generic delayed loader');
$expect('unchanged' === ($contract['frozen_existing_behavior']['auto_release'] ?? ''), 'Auto Release remains frozen');

$policy = (string) file_get_contents($root . '/includes/engine/js/class-js-policy-trait.php');
$finder = (string) file_get_contents($root . '/assets/js/dynamic-script-finder-bootstrap.js');
foreach (array('elementor', 'woocommerce', 'sitekit', 'wpbakery', 'mailerlite') as $token) {
    $expect(false === stripos($policy, $token), 'canonical generic policy table contains no vendor identity: ' . $token);
    $expect(false === stripos($finder, $token), 'parser-early dynamic finder contains no vendor identity: ' . $token);
}
$expect(false !== strpos($policy, "'complianzCompatibility' => !empty(\$settings['complianz_compatibility'])")
    && false !== strpos($policy, "'complianzInfrastructure' =>"),
    'Complianz identity exists only behind the explicit compatibility-switch infrastructure policy');
$expect(false !== strpos($finder, 'var complianzCompatibility = !!bootstrapPolicy.complianzCompatibility;')
    && false !== strpos($finder, 'complianzInfrastructurePatterns'),
    'parser-early Complianz identity exists only as explicit consent-controller infrastructure provenance');

echo PHP_EOL . 'Result: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . PHP_EOL;
if (!empty($failures)) {
    exit(1);
}
