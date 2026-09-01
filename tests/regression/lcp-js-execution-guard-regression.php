<?php
/** UltraCache 3.13.05 LCP resource / JS policy separation regression. */
$root = dirname(__DIR__, 2);
$files = array(
    'policy' => file_get_contents($root . '/includes/engine/js/class-js-policy-trait.php'),
    'router' => file_get_contents($root . '/includes/engine/js/class-js-router-trait.php'),
    'lcp' => file_get_contents($root . '/includes/engine/lcp/class-lcp-observation-trait.php'),
    'rewrite' => file_get_contents($root . '/includes/engine/lcp/class-lcp-html-rewrite-trait.php'),
    'output' => file_get_contents($root . '/includes/engine/class-engine-html-output-trait.php'),
    'loader' => file_get_contents($root . '/assets/js/delayed-js-loader.js'),
);
$failures = array();
$expect = static function ($condition, $message) use (&$failures) { if (!$condition) $failures[] = $message; };
foreach ($files as $name => $contents) $expect(is_string($contents) && '' !== $contents, 'missing ' . $name);

$expect(false !== strpos($files['lcp'], 'ultracache_get_lcp_protection_context'), 'LCP observation/resource context remains available');
$expect(false !== strpos($files['lcp'], "'browser-locked'"), 'browser locked LCP authority remains available');
$expect(false !== strpos($files['lcp'], "'server-heuristic'"), 'server heuristic LCP fallback remains available');
$expect(false !== strpos($files['rewrite'], 'ultracache_find_observed_lcp_boundary_offset($html)'), 'LCP Boundary Delay still uses observed boundary authority');

$expect(false === strpos($files['policy'], "'lcpProtectionEnabled'"), 'unified JS policy has no LCP-to-DEFER promotion flag');
$expect(false === strpos($files['policy'], "'id' => 'lcp-protected'"), 'unified JS policy has no LCP-to-DEFER promotion rule');
$expect(false === strpos($files['router'], "'lcpProtectedPattern' =>"), 'registered router supplies no LCP promotion fact');
$expect(false === strpos($files['loader'], 'dynamicLcpProtectedPatterns') && false === strpos($files['loader'], 'lcpProtectedPattern: dynamicFirstPattern'), 'runtime classifier has no LCP promotion path');
$expect(false === strpos($files['output'], "'restore-lcp-protected-delayed-js'"), 'final HTML no longer vetoes a selected DELAY lane from LCP fingerprints');

$expect(false !== strpos($files['rewrite'], 'should_delay_lcp_boundary_script'), 'explicit LCP Boundary Delay mechanism remains available');
$expect(false !== strpos($files['rewrite'], 'optimize_lcp_image_markup'), 'LCP image resource prioritization remains available');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: LCP resource / JS policy separation regression\n";
