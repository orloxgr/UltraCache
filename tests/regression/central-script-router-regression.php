<?php
/**
 * UltraCache 3.12.03 Central Script Router regression.
 *
 * Run:
 *   php tests/regression/central-script-router-regression.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $key));
    }
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/engine/js/class-js-runtime-registry-trait.php';
require_once $root . '/includes/engine/js/class-js-policy-trait.php';
require_once $root . '/includes/engine/js/class-js-router-trait.php';

final class UltraCacheRouterHarness
{
    use Ultra_Cache_Engine_JS_Runtime_Registry_Trait;
    use Ultra_Cache_Engine_JS_Policy_Trait;
    use Ultra_Cache_Engine_JS_Router_Trait;

    public array $stub = array();

    public function __call($name, $arguments)
    {
        if (array_key_exists($name, $this->stub)) {
            $value = $this->stub[$name];
            return is_callable($value) ? $value(...$arguments) : $value;
        }

        if ('is_delayable_external_script_tag' === $name) {
            return true;
        }
        if ('get_defer_stage_level' === $name) {
            return 2;
        }
        if (in_array($name, array('get_safe_third_party_delay_patterns', 'get_functional_third_party_delay_patterns', 'get_non_critical_delay_patterns'), true)) {
            return array();
        }

        return false;
    }

    public function route(array $settings = array(), string $handle = 'example', string $src = 'https://site.example/app.js', string $tag = '<script src="https://site.example/app.js"></script>'): array
    {
        return $this->ultracache_build_registered_script_route($tag, $handle, $src, $settings);
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

$router_source = file_get_contents($root . '/includes/engine/js/class-js-router-trait.php');
$defer_source = file_get_contents($root . '/includes/engine/js/class-js-defer-trait.php');
$aggregator_source = file_get_contents($root . '/includes/engine/class-engine-js-optimization-trait.php');

$expect(is_string($aggregator_source) && false !== strpos($aggregator_source, "class-js-router-trait.php"), 'router trait is loaded by JS optimization aggregator');
$expect(is_string($aggregator_source) && false !== strpos($aggregator_source, 'use Ultra_Cache_Engine_JS_Router_Trait;'), 'engine composes the central router trait');
$expect(is_string($defer_source) && false !== strpos($defer_source, 'ultracache_build_registered_script_route('), 'defer filter delegates classification to central router');
$expect(is_string($defer_source) && false !== strpos($defer_source, 'ultracache_apply_registered_script_route('), 'defer filter delegates route application to central router');

$defer_method = '';
if (is_string($defer_source)) {
    $method_start = strpos($defer_source, 'public function defer_scripts(');
    $method_end = false !== $method_start ? strpos($defer_source, 'private function should_keep_script_blocking_for_defer_all(', $method_start) : false;
    if (false !== $method_start && false !== $method_end) {
        $defer_method = substr($defer_source, $method_start, $method_end - $method_start);
    }
}
$expect('' !== $defer_method, 'defer filter body is inspectable');
$expect(false === strpos($defer_method, 'should_protect_') && false === strpos($defer_method, 'is_script_user_') && false === strpos($defer_method, 'get_third_party_delay_match'), 'defer filter no longer owns scheduling policy decisions');

$policy_source = file_get_contents($root . '/includes/engine/js/class-js-policy-trait.php');
$expect(is_string($policy_source) && false !== strpos($policy_source, "'version' => '3.13.10'"), 'canonical policy table is versioned 3.13.10');
$expect(is_string($policy_source) && false !== strpos($policy_source, "'lane' => 'native'") && false !== strpos($policy_source, "'lane' => 'defer'") && false !== strpos($policy_source, "'lane' => 'delay'"), 'canonical policy table emits NATIVE / DEFER / DELAY lanes');
$expect(is_string($router_source) && false !== strpos($router_source, 'ultracache_build_unified_js_execution_policy') && false !== strpos($router_source, 'ultracache_evaluate_unified_js_execution_policy'), 'registered router delegates generic lane precedence to unified policy evaluator');
$expect(false === strpos((string) $router_source, 'update_option(') && false === strpos((string) $router_source, 'wp_remote_') && false === strpos((string) $router_source, 'fetch('), 'router emits no production telemetry/network side effects');

$h = new UltraCacheRouterHarness();
$route = $h->route(array('defer_js' => true));
$expect('defer' === ($route['lane'] ?? '') && 'defer-default' === ($route['action'] ?? ''), 'default active Defer strategy routes to DEFER');

$h = new UltraCacheRouterHarness();
$h->stub['is_js_excluded_by_user_patterns'] = true;
$route = $h->route(array('defer_js' => true));
$expect('native' === ($route['lane'] ?? '') && 'visible-do-not-defer-or-delay' === ($route['reason'] ?? ''), 'visible exclusion routes to NATIVE');

$h = new UltraCacheRouterHarness();
$h->stub['is_script_user_force_deferred'] = true;
$route = $h->route(array('defer_js' => true, 'delay_non_critical_js' => true));
$expect('defer' === ($route['lane'] ?? '') && 'visible-defer-instead-of-delay' === ($route['reason'] ?? ''), 'visible Defer Instead rule routes to DEFER');

$h = new UltraCacheRouterHarness();
$h->stub['is_js_excluded_by_user_patterns'] = true;
$h->stub['is_script_user_force_deferred'] = true;
$route = $h->route(array('defer_js' => true, 'delay_non_critical_js' => true));
$expect('native' === ($route['lane'] ?? '') && 'visible-do-not-defer-or-delay' === ($route['reason'] ?? ''), 'Do Not Defer or Delay wins when both visible lists match');

$h = new UltraCacheRouterHarness();
$h->stub['get_safe_third_party_delay_patterns'] = array('safe-pattern');
$h->stub['get_matching_third_party_delay_pattern'] = 'safe-pattern';
$route = $h->route(array('defer_js' => true, 'defer_stage_balanced' => true, 'delay_safe_third_party_js' => true));
$expect('delay' === ($route['lane'] ?? '') && 'delay-tag' === ($route['action'] ?? ''), 'third-party match routes to DELAY');

$h = new UltraCacheRouterHarness();
$h->stub['is_same_host_public_url'] = true;
$h->stub['matches_non_critical_delay_patterns'] = true;
$route = $h->route(array('defer_js' => true, 'defer_stage_balanced' => true, 'delay_non_critical_js' => true));
$expect('delay' === ($route['lane'] ?? '') && 'non-critical-first-party' === ($route['reason'] ?? ''), 'non-critical first-party match routes to DELAY');

$h = new UltraCacheRouterHarness();
$h->stub['is_defer_all_js_candidate'] = true;
$route = $h->route(array('delay_all_js' => true, 'defer_js' => true));
$expect('delay' === ($route['lane'] ?? '') && 'delay-html-pass' === ($route['action'] ?? ''), 'Delay All candidate is classified DELAY before ordered HTML rewrite');

$h = new UltraCacheRouterHarness();
$h->stub['is_delayable_external_script_tag'] = false;
$route = $h->route(array('defer_js' => true));
$expect('native' === ($route['lane'] ?? '') && 'html-js-semantics-non-delayable' === ($route['reason'] ?? ''), 'non-executable/non-delayable semantics stay NATIVE');

$h = new UltraCacheRouterHarness();
$h->stub['is_script_tag_optimizer_opted_out'] = true;
$route = $h->route(array('defer_js' => true));
$expect('native' === ($route['lane'] ?? '') && 'explicit-author-optimizer-opt-out' === ($route['reason'] ?? ''), 'explicit author optimizer opt-out stays NATIVE');

$h = new UltraCacheRouterHarness();
$route = $h->route(array('defer_js' => true), 'ultracache-lcp-observer');
$expect('defer' === ($route['lane'] ?? '') && 'defer-native' === ($route['action'] ?? ''), 'existing native-deferred UltraCache helper behavior is preserved through router');

$h = new UltraCacheRouterHarness();
$route = $h->route(array('defer_js' => true), 'ultracache-delayed-js-interaction-bootstrap');
$expect('native' === ($route['lane'] ?? '') && 'opaque-bootstrap' === ($route['action'] ?? ''), 'existing parser-early interaction bootstrap behavior is preserved through router');

$h = new UltraCacheRouterHarness();
$h->stub['should_protect_elementor_compatibility_script'] = true;
$route = $h->route(array('defer_js' => true, 'delay_non_critical_js' => true));
$expect('defer' === ($route['lane'] ?? '') && 'explicit-elementor-compatibility' === ($route['reason'] ?? ''), 'explicit Elementor integration still routes to DEFER');

$h = new UltraCacheRouterHarness();
$h->stub['should_async_external_script'] = true;
$route = $h->route(array('defer_js' => true, 'async_external_scripts' => true));
$expect('native' === ($route['lane'] ?? '') && 'async' === ($route['action'] ?? ''), 'existing async transport is represented inside the NATIVE lane without creating a fourth lane');

$expect(is_string($router_source) && false === strpos($router_source, 'consent-control-plane') && false === strpos($router_source, 'should_protect_consent'), 'central router contains no hidden consent/CMP scheduling branch');
$expect(is_string($router_source) && false === strpos($router_source, 'legacy-inline-companion-group-handoff'), 'legacy inline-companion NATIVE handoff is removed after dependency audit');
$expect(is_string($router_source) && false === strpos($router_source, 'get_third_party_delay_match(') && false === strpos($router_source, 'should_delay_non_critical_script('), 'registered router no longer owns duplicate third-party/non-critical classification branches');

echo PHP_EOL . 'Result: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . PHP_EOL;
if (!empty($failures)) {
    exit(1);
}
