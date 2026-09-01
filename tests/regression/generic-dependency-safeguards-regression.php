<?php
/**
 * UltraCache 3.12.16 generic dependency safeguards regression.
 *
 * Run:
 *   php tests/regression/generic-dependency-safeguards-regression.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', (string) $key));
    }
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/engine/js/class-js-html-rewrite-trait.php';

final class UltraCacheDependencyLaneHarness
{
    use Ultra_Cache_Engine_JS_HTML_Rewrite_Trait;

    public function resolve(array $ranks, array $deps): array
    {
        return $this->ultracache_resolve_dependency_lane_ranks($ranks, $deps);
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

$h = new UltraCacheDependencyLaneHarness();

$result = $h->resolve(
    array('dependency' => 2, 'dependent' => 1),
    array('dependent' => array('dependency'))
);
$expect(1 === ($result['dependency'] ?? -1) && 1 === ($result['dependent'] ?? -1), 'DELAY dependency is promoted only to DEFER when dependent is DEFER');

$result = $h->resolve(
    array('dependency' => 2, 'dependent' => 2),
    array('dependent' => array('dependency'))
);
$expect(2 === ($result['dependency'] ?? -1), 'dependency remains DELAY when dependent is also DELAY');

$result = $h->resolve(
    array('a' => 2, 'b' => 2, 'c' => 0, 'independent' => 2),
    array('b' => array('a'), 'c' => array('b'))
);
$expect(0 === ($result['a'] ?? -1) && 0 === ($result['b'] ?? -1) && 0 === ($result['c'] ?? -1), 'earlier lane propagates transitively through dependency chain');
$expect(2 === ($result['independent'] ?? -1), 'unrelated script lane is unchanged');

$result = $h->resolve(
    array('a' => 2, 'b' => 1),
    array('a' => array('b'), 'b' => array('a'))
);
$expect(1 === ($result['a'] ?? -1) && 1 === ($result['b'] ?? -1), 'dependency cycle converges to earliest lane without infinite recursion');

$router = (string) file_get_contents($root . '/includes/engine/js/class-js-router-trait.php');
$policy = (string) file_get_contents($root . '/includes/engine/js/class-js-policy-trait.php');
$classifier = (string) file_get_contents($root . '/includes/engine/js/class-js-classification-trait.php');
$html = (string) file_get_contents($root . '/includes/engine/js/class-js-html-rewrite-trait.php');
$lcp = (string) file_get_contents($root . '/includes/engine/lcp/class-lcp-html-rewrite-trait.php');
$loader = (string) file_get_contents($root . '/assets/js/delayed-js-loader.js');
$output = (string) file_get_contents($root . '/includes/engine/class-engine-html-output-trait.php');

$expect(false === strpos($policy, "'id' => 'registered-group-guard'"), 'inline companions are no longer a hidden NATIVE policy rule');
$expect(false === strpos($router, 'legacy-inline-companion-group-handoff'), 'legacy inline-companion NATIVE handoff is removed');
$expect(false === strpos($router, '&& !$this->script_handle_has_enqueued_dependents($handle)'), 'non-critical classifier no longer blanket-rejects every dependency with dependents');
$expect(false === strpos($lcp, 'script_handle_has_enqueued_dependents($handle)'), 'LCP boundary no longer blanket-rejects scripts solely because they have dependents');
$expect(false === strpos($lcp, 'script_handle_has_wp_inline_companion_segments($handle)'), 'LCP boundary no longer blanket-rejects inline-companion handles');

$expect(false !== strpos($html, 'ultracache_normalize_inline_companion_group_lanes_in_html'), 'final HTML pass normalizes inline companions to their selected external lane');
$expect(false !== strpos($html, 'ultracache_normalize_registered_dependency_lanes_in_html'), 'final HTML pass enforces dependency lane ordering');
$expect(false !== strpos($html, 'a registered dependency must never execute later than its dependent'), 'dependency invariant is documented in production source');
$expect(false !== strpos($output, "'normalize-js-dependency-lanes'"), 'dependency lane normalization is part of final HTML output pipeline');

$expect(false !== strpos($router, 'script_handle_has_active_dependency_edges($handle) || $this->script_handle_has_wp_inline_companion_segments($handle)'), 'default DEFER forces ordered transport only for dependency-connected/companion scripts');
$expect(substr_count($router, 'script_handle_has_active_dependency_edges($handle) || $this->script_handle_has_wp_inline_companion_segments($handle)') >= 2, 'explicit Async transport also falls back to ordered DEFER for dependency-connected/companion scripts');
$forceOrderedPos = strpos($router, "case 'defer-force-ordered':");
$forceOrderedBody = false === $forceOrderedPos ? '' : substr($router, $forceOrderedPos, 260);
$expect(false !== strpos($forceOrderedBody, 'add_defer_attribute_to_script_tag($tag, true)') && false === strpos($forceOrderedBody, 'add_defer_or_parallel_attribute_to_script_tag'), 'defer-force-ordered is truly ordered and cannot become async');

$expect(false !== strpos($html, "data-ultracache-ordered'] = '1'"), 'registered delayed scripts with dependency edges carry ordered execution metadata');
$expect(false !== strpos($loader, "ultracacheData(node, 'ordered')") && false !== strpos($loader, 'laneUsesParallelExecution(mode) && !dependencyOrdered'), 'Delay parallel execution is disabled for dependency-connected delayed groups');
$expect(false !== strpos($classifier, 'script_handle_has_active_dependency_edges'), 'dependency-edge detection is generic and centralized');

$contract = require $root . '/tests/architecture/js-policy-contract.php';
$expect('lane-coherent-graph' === ($contract['dependency_semantics']['model'] ?? ''), 'architecture contract declares lane-coherent dependency graph model');
$expect(false === ($contract['dependency_semantics']['inline_companion_forces_native'] ?? true), 'contract forbids blanket NATIVE for inline companions');
$expect(false === ($contract['dependency_semantics']['has_dependent_forbids_delay'] ?? true), 'contract forbids blanket DELAY rejection merely because a dependent exists');
$expect(true === ($contract['dependency_semantics']['dependency_never_executes_later_than_dependent'] ?? false), 'contract freezes dependency execution invariant');
$expect('unchanged' === ($contract['frozen_existing_behavior']['auto_release'] ?? ''), 'Auto Release remains frozen');

$total = $passes + count($failures);
echo PHP_EOL . 'Result: ' . $passes . '/' . $total . ' PASS' . PHP_EOL;
if (!empty($failures)) {
    exit(1);
}
