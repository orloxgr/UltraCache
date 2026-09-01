<?php
/**
 * UltraCache 3.12.07 Delay classification contract regression.
 *
 * The Delay classifiers must not recognize consent/CMP identity as a hidden
 * protection. Consent-management scripts are ordinary candidates governed by
 * the selected strategy, visible lists and generic script semantics.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$delay = (string) file_get_contents($root . '/includes/engine/js/class-js-delay-trait.php');
$html = (string) file_get_contents($root . '/includes/engine/js/class-js-html-rewrite-trait.php');
$router = (string) file_get_contents($root . '/includes/engine/js/class-js-router-trait.php');
$policy = (string) file_get_contents($root . '/includes/engine/js/class-js-policy-trait.php');

$passes = 0;
$failures = array();
$expect = static function (bool $condition, string $label) use (&$passes, &$failures): void {
    if ($condition) { $passes++; echo '[PASS] ' . $label . PHP_EOL; return; }
    $failures[] = $label; echo '[FAIL] ' . $label . PHP_EOL;
};

$extract = static function (string $source, string $function): string {
    $start = strpos($source, 'function ' . $function . '(');
    if (false === $start) { return ''; }
    $next = strpos($source, "\n    private function ", $start + 10);
    if (false === $next) { $next = strlen($source); }
    return substr($source, $start, $next - $start);
};

foreach (array('should_delay_non_critical_script', 'get_third_party_delay_match', 'get_inline_third_party_delay_match') as $function) {
    $body = $extract($delay, $function);
    $expect('' !== $body, 'classifier exists: ' . $function);
    $expect(false === stripos($body, 'consent') && false === stripos($body, 'complianz') && false === stripos($body, 'cookiebot') && false === stripos($body, 'onetrust'), 'classifier has no hidden consent/CMP fingerprint: ' . $function);
}

$expect(false === strpos($router, 'legacy-consent-control-plane') && false === strpos($router, 'should_protect_consent'), 'central router has no consent-specific branch');
$expect(false === strpos($html, 'should_native_defer_consent') && false === strpos($html, 'should_protect_consent'), 'HTML rewrite has no consent-specific lane override');
$expect(false !== strpos($policy, 'get_safe_third_party_delay_patterns($settings)') && false !== strpos($delay, 'ultracache_build_unified_js_execution_policy($settings)'), 'normal user-editable safe third-party matching remains available through unified policy');
$expect(false !== strpos($delay, 'is_js_excluded_by_user_patterns'), 'visible exclusion policy remains in Delay classifiers');
$expect(false !== strpos($delay, 'is_script_user_force_deferred'), 'visible Defer Instead policy remains in Delay classifiers');

echo PHP_EOL . 'Result: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . PHP_EOL;
if ($failures) { exit(1); }
