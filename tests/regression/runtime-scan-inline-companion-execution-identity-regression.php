<?php
/**
 * UltraCache 3.12.28 inline-companion execution identity regression.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) { return trim((string) $value); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($value) { return (string) $value; }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/profiler/class-runtime-js-scanner-trait.php';
require_once $root . '/includes/profiler/class-runtime-js-rules-trait.php';

final class UltraCacheRuntimeInlineExecutionHarness
{
    use Ultra_Cache_Runtime_JS_Scanner_Trait;
    use Ultra_Cache_Runtime_JS_Rules_Trait;

    public function context(string $source, array $scripts): array
    {
        return $this->runtime_js_scan_error_execution_consumer_context($source, '', '', $scripts);
    }

    public function executionIdentity(array $script): string
    {
        return $this->runtime_js_scan_execution_identity($script);
    }

    public function providers(string $expression, array $scripts, array $consumer): array
    {
        return $this->runtime_js_scan_find_member_expression_provider_scripts($expression, $scripts, $consumer);
    }

    public function choose(array $providers, array $consumer): array
    {
        return $this->runtime_js_scan_select_member_expression_provider_for_consumer($providers, $consumer);
    }
}

$passes = 0;
$failures = array();
$expect = static function (bool $ok, string $label) use (&$passes, &$failures): void {
    if ($ok) { $passes++; echo '[PASS] ' . $label . PHP_EOL; return; }
    $failures[] = $label; echo '[FAIL] ' . $label . PHP_EOL;
};

$h = new UltraCacheRuntimeInlineExecutionHarness();
$provider = array(
    'order' => 2,
    'id' => 'google_gtagjs-js-after',
    'handle' => 'google_gtagjs',
    'src' => '',
    'text' => 'window._googlesitekit = window._googlesitekit || {};',
    'delayed' => true,
);
$providerSibling = array(
    'order' => 1,
    'id' => 'google_gtagjs-js-before',
    'handle' => 'google_gtagjs',
    'src' => '',
    'text' => 'window.dataLayer = window.dataLayer || [];',
    'delayed' => true,
);
$owner = array(
    'order' => 4,
    'id' => 'googlesitekit-events-provider-woocommerce-js',
    'handle' => 'googlesitekit-events-provider-woocommerce',
    'src' => '',
    'text' => '',
    'delayed' => false,
);
$consumer = array(
    'order' => 3,
    'id' => 'googlesitekit-events-provider-woocommerce-js-before',
    'handle' => 'googlesitekit-events-provider-woocommerce',
    'src' => '',
    'text' => 'window._googlesitekit.wcdata = window._googlesitekit.wcdata || {};',
    'delayed' => false,
);
$scripts = array($providerSibling, $provider, $consumer, $owner);

$ctx = $h->context('googlesitekit-events-provider-woocommerce-js-before:2:54', $scripts);
$expect('googlesitekit-events-provider-woocommerce-js-before' === ($ctx['execution']['id'] ?? ''), 'browser inline frame resolves to the exact execution segment');
$expect('googlesitekit-events-provider-woocommerce-js' === ($ctx['policyOwner']['id'] ?? ''), 'inline execution segment keeps its registered parent as visible-policy owner');
$expect($h->executionIdentity($provider) !== $h->executionIdentity($providerSibling), 'before/after companions sharing one handle retain distinct execution identities');
$expect($h->executionIdentity($consumer) !== $h->executionIdentity($owner), 'inline consumer is not collapsed into its external parent identity');

$providers = $h->providers('window._googlesitekit', $scripts, $consumer);
$selected = $h->choose($providers, $consumer);
$expect('google_gtagjs-js-after' === ($selected['id'] ?? ''), 'source-level receiver scan resolves the exact inline writer before the failing consumer');

$total = $passes + count($failures);
echo PHP_EOL . 'Result: ' . $passes . '/' . $total . ' PASS' . PHP_EOL;
if ($failures) { exit(1); }
