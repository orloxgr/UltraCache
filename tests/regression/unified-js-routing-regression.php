<?php
/**
 * UltraCache 3.12.36 unified JavaScript routing regression.
 *
 * Run:
 *   php tests/regression/unified-js-routing-regression.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$root = dirname(__DIR__, 2);
require_once $root . '/includes/engine/js/class-js-policy-trait.php';

final class UltraCacheUnifiedPolicyHarness
{
    use Ultra_Cache_Engine_JS_Policy_Trait;

    public array $stub = array();

    public function __call($name, $arguments)
    {
        if (array_key_exists($name, $this->stub)) {
            $value = $this->stub[$name];
            return is_callable($value) ? $value(...$arguments) : $value;
        }
        if ('get_defer_stage_level' === $name) {
            $settings = isset($arguments[0]) && is_array($arguments[0]) ? $arguments[0] : array();
            if (!empty($settings['defer_stage_aggressive']) || !empty($settings['delay_non_critical_js_aggressive'])) {
                return 3;
            }
            if (!empty($settings['defer_stage_balanced'])) {
                return 2;
            }
            if (!empty($settings['defer_stage_safe']) || !empty($settings['defer_js'])) {
                return 1;
            }
            return 0;
        }
        if (in_array($name, array('get_safe_third_party_delay_patterns', 'get_functional_third_party_delay_patterns', 'get_non_critical_delay_patterns'), true)) {
            return array();
        }
        return false;
    }

    public function policy(array $settings): array
    {
        return $this->ultracache_build_unified_js_execution_policy($settings);
    }

    public function evaluate(array $policy, array $facts): array
    {
        return $this->ultracache_evaluate_unified_js_execution_policy($policy, $facts);
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

$h = new UltraCacheUnifiedPolicyHarness();
$h->stub['get_safe_third_party_delay_patterns'] = array('analytics.example');
$h->stub['get_functional_third_party_delay_patterns'] = array('chat.example');
$h->stub['get_non_critical_delay_patterns'] = array('slider');
$policy = $h->policy(array(
    'defer_js' => true,
    'defer_stage_balanced' => true,
    'delay_safe_third_party_js' => true,
    'delay_functional_third_party_js' => true,
    'delay_all_third_party_js' => true,
    'delay_non_critical_js' => true,
    'lcp_image_priority' => true,
));

$expect('3.13.10' === ($policy['version'] ?? ''), 'policy snapshot is versioned 3.13.10');
$ids = array_values(array_map(static fn($rule) => (string) ($rule['id'] ?? ''), (array) ($policy['rules'] ?? array())));
$expected_prefix = array('visible-native', 'visible-defer', 'explicit-integration', 'delay-all', 'safe-third-party', 'functional-third-party', 'all-third-party');
$expect($expected_prefix === array_slice($ids, 0, count($expected_prefix)), 'one rule table freezes visible/integration/delay precedence');
$expect(true === ($policy['flags']['delayClassificationEnabled'] ?? false), 'balanced stage enables DELAY classification in canonical policy');
$expect(array('analytics.example') === ($policy['patterns']['safe'] ?? array()), 'safe patterns live in canonical policy snapshot');
$expect(array('chat.example') === ($policy['patterns']['functional'] ?? array()), 'functional patterns live in canonical policy snapshot');

$route = $h->evaluate($policy, array(
    'visibleNativePattern' => 'native.js',
    'visibleDeferPattern' => 'native.js',
));
$expect('native' === ($route['lane'] ?? '') && 'visible-do-not-defer-or-delay' === ($route['reason'] ?? ''), 'NATIVE visible rule wins overlap through shared evaluator');

$route = $h->evaluate($policy, array(
    'visibleNativePattern' => '',
    'visibleDeferPattern' => 'defer.js',
));
$expect('defer' === ($route['lane'] ?? '') && 'visible-defer-instead-of-delay' === ($route['reason'] ?? ''), 'DEFER visible rule is second shared-policy authority');

$route = $h->evaluate($policy, array(
    'lcpProtectedPattern' => 'nectar-video-bg',
    'delayAllCandidate' => true,
    'nonCriticalPattern' => 'slider',
));
$expect('delay' === ($route['lane'] ?? '') && 'non-critical-first-party' === ($route['reason'] ?? ''), 'legacy LCP fingerprint facts cannot promote generic DELAY to DEFER');

$route = $h->evaluate($policy, array(
    'familyDeferCandidate' => true,
    'nonCriticalPattern' => 'slider',
));
$expect('delay' === ($route['lane'] ?? '') && 'non-critical-first-party' === ($route['reason'] ?? ''), 'legacy inline-family facts cannot promote generic DELAY to DEFER');

$delayAllPolicy = $h->policy(array(
    'defer_js' => true,
    'defer_stage_balanced' => true,
    'delay_all_js' => true,
    'delay_safe_third_party_js' => true,
));
$route = $h->evaluate($delayAllPolicy, array(
    'delayAllCandidate' => true,
    'safePattern' => 'analytics.example',
    'isThirdParty' => true,
));
$expect('delay' === ($route['lane'] ?? '') && 'delay-all-js' === ($route['reason'] ?? '') && false === ($route['interactionEligible'] ?? true), 'Delay All precedence is identical for third-party/runtime candidates');

$route = $h->evaluate($policy, array(
    'safePattern' => 'analytics.example',
    'isThirdParty' => true,
));
$expect('delay' === ($route['lane'] ?? '') && 'safe-third-party' === ($route['reason'] ?? '') && false === ($route['interactionEligible'] ?? true), 'safe third-party rule resolves DELAY through shared evaluator');

$route = $h->evaluate($policy, array(
    'functionalPattern' => 'chat.example',
    'isThirdParty' => true,
));
$expect('delay' === ($route['lane'] ?? '') && 'functional-third-party' === ($route['reason'] ?? '') && true === ($route['interactionEligible'] ?? false), 'functional third-party rule keeps interaction release semantics');

$route = $h->evaluate($policy, array(
    'isThirdParty' => true,
));
$expect('delay' === ($route['lane'] ?? '') && 'all-third-party' === ($route['reason'] ?? ''), 'all-third-party origin rule uses same evaluator');

$route = $h->evaluate($policy, array(
    'nonCriticalPattern' => 'slider',
    'isThirdParty' => false,
));
$expect('delay' === ($route['lane'] ?? '') && 'non-critical-first-party' === ($route['reason'] ?? ''), 'first-party non-critical rule uses same evaluator');

$safeStagePolicy = $h->policy(array(
    'defer_js' => true,
    'defer_stage_safe' => true,
    'delay_safe_third_party_js' => true,
));
$route = $h->evaluate($safeStagePolicy, array(
    'safePattern' => 'analytics.example',
    'isThirdParty' => true,
));
$expect('defer' === ($route['lane'] ?? '') && 'default-defer-strategy' === ($route['reason'] ?? ''), 'stage-one policy cannot secretly DELAY runtime-created scripts');

$disabledPolicy = $h->policy(array());
$route = $h->evaluate($disabledPolicy, array());
$expect('native' === ($route['lane'] ?? '') && 'javascript-optimization-disabled' === ($route['reason'] ?? ''), 'disabled JavaScript optimization resolves NATIVE through same table');

$router = (string) file_get_contents($root . '/includes/engine/js/class-js-router-trait.php');
$delay = (string) file_get_contents($root . '/includes/engine/js/class-js-delay-trait.php');
$loader = (string) file_get_contents($root . '/assets/js/delayed-js-loader.js');
$aggregator = (string) file_get_contents($root . '/includes/engine/class-engine-js-optimization-trait.php');

$expect(false !== strpos($aggregator, 'class-js-policy-trait.php') && false !== strpos($aggregator, 'use Ultra_Cache_Engine_JS_Policy_Trait;'), 'engine composes the canonical policy trait');
$expect(false !== strpos($router, 'ultracache_build_unified_js_execution_policy') && false !== strpos($router, 'ultracache_evaluate_unified_js_execution_policy'), 'registered scripts consume canonical policy table');
$expect(false === strpos($router, 'get_third_party_delay_match(') && false === strpos($router, 'should_delay_non_critical_script('), 'registered router has no duplicate generic third-party/non-critical decision tree');
$expect(false !== strpos($delay, '$dynamic_policy = $this->ultracache_build_unified_js_execution_policy($settings);'), 'runtime-created script policy payload comes from canonical builder');
$expect(false !== strpos($loader, 'dynamicPolicyRules') && false !== strpos($loader, 'evaluateUnifiedDynamicPolicy'), 'browser classifier interprets canonical ordered rules');
$expect(false === strpos($loader, 'if (dynamicPolicy.delaySafe)') && false === strpos($loader, 'if (dynamicPolicy.delayFunctional)'), 'browser classifier no longer owns a parallel hardcoded precedence tree');
$expect(false === strpos($router, 'update_option(') && false === strpos($loader, 'sendBeacon('), 'unified routing adds no production telemetry');

$methodBody = static function (string $source, string $method, string $nextMethod): string {
    $start = strpos($source, 'function ' . $method . '(');
    if (false === $start) {
        return '';
    }
    $end = strpos($source, 'function ' . $nextMethod . '(', $start + 1);
    return false === $end ? substr($source, $start) : substr($source, $start, $end - $start);
};
$thirdPartyAdapter = $methodBody($delay, 'get_third_party_delay_match', 'get_inline_third_party_delay_match');
$inlineAdapter = $methodBody($delay, 'get_inline_third_party_delay_match', 'should_delay_non_critical_script');
$nonCriticalAdapter = $methodBody($delay, 'should_delay_non_critical_script', '');
$expect(false !== strpos($thirdPartyAdapter, 'ultracache_evaluate_unified_js_execution_policy'), 'final HTML third-party fallback delegates to unified evaluator');
$expect(false !== strpos($inlineAdapter, 'ultracache_evaluate_unified_js_execution_policy'), 'inline third-party fallback delegates to unified evaluator');
$expect(false !== strpos($nonCriticalAdapter, 'ultracache_evaluate_unified_js_execution_policy'), 'legacy non-critical helper delegates to unified evaluator');

$contract = require $root . '/tests/architecture/js-policy-contract.php';
$expect('ultracache_build_unified_js_execution_policy' === ($contract['unified_routing']['policy_builder'] ?? ''), 'architecture contract names canonical policy builder');
$expect('declarative-rule-table' === ($contract['unified_routing']['authority'] ?? ''), 'architecture contract freezes declarative rule-table authority');
$expect(false === ($contract['unified_routing']['separate_dynamic_precedence_tree'] ?? true), 'architecture contract forbids a separate dynamic precedence tree');
$expect('unchanged' === ($contract['frozen_existing_behavior']['auto_release'] ?? ''), 'Auto Release remains frozen');

echo PHP_EOL . 'Result: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . PHP_EOL;
if (!empty($failures)) {
    exit(1);
}
