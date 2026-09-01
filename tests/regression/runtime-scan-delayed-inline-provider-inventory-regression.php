<?php
/**
 * UltraCache 3.12.29 delayed-inline provider inventory regression.
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
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($value) { return (string) $value; }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); }
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/profiler/class-runtime-js-scanner-trait.php';
require_once $root . '/includes/profiler/class-runtime-js-rules-trait.php';

final class UltraCacheDelayedInlineInventoryHarness
{
    use Ultra_Cache_Runtime_JS_Scanner_Trait;
    use Ultra_Cache_Runtime_JS_Rules_Trait;

    public function merge(array $fetched, array $runtime): array
    {
        return $this->runtime_js_scan_merge_script_inventories($fetched, $runtime);
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

$h = new UltraCacheDelayedInlineInventoryHarness();
$runtime = array(
    array('order' => 0, 'id' => 'google_gtagjs-js-before', 'handle' => 'google_gtagjs', 'src' => '', 'text' => '', 'delayed' => true),
    array('order' => 1, 'id' => 'google_gtagjs-js-after', 'handle' => 'google_gtagjs', 'src' => '', 'text' => '', 'delayed' => true),
    array('order' => 2, 'id' => 'googlesitekit-events-provider-woocommerce-js-before', 'handle' => 'googlesitekit-events-provider-woocommerce', 'src' => '', 'text' => 'window._googlesitekit.wcdata = window._googlesitekit.wcdata || {};', 'delayed' => false),
);
$fetched = array(
    array('order' => 0, 'id' => 'google_gtagjs-js-before', 'handle' => 'google_gtagjs', 'src' => '', 'text' => 'window.dataLayer = window.dataLayer || [];', 'delayed' => true),
    array('order' => 1, 'id' => 'google_gtagjs-js-after', 'handle' => 'google_gtagjs', 'src' => '', 'text' => 'window._googlesitekit = window._googlesitekit || {};', 'delayed' => true),
    array('order' => 2, 'id' => 'googlesitekit-events-provider-woocommerce-js-before', 'handle' => 'googlesitekit-events-provider-woocommerce', 'src' => '', 'text' => 'window._googlesitekit.wcdata = window._googlesitekit.wcdata || {};', 'delayed' => false),
);

$merged = $h->merge($fetched, $runtime);
$byId = array();
foreach ($merged as $script) {
    $byId[(string) ($script['id'] ?? '')] = $script;
}

$expect(isset($byId['google_gtagjs-js-before']), 'inline before companion survives merge as its own execution segment');
$expect(isset($byId['google_gtagjs-js-after']), 'inline after companion sharing the same handle survives merge separately');
$expect(false !== strpos((string) ($byId['google_gtagjs-js-after']['text'] ?? ''), 'window._googlesitekit'), 'server inventory enriches the exact delayed inline provider body by id');
$expect(!empty($byId['google_gtagjs-js-after']['delayed']), 'delayed execution state remains authoritative after enrichment');

$consumer = (array) ($byId['googlesitekit-events-provider-woocommerce-js-before'] ?? array());
$providers = $h->providers('window._googlesitekit', $merged, $consumer);
$selected = $h->choose($providers, $consumer);
$expect('google_gtagjs-js-after' === ($selected['id'] ?? ''), 'provider resolver can prove the delayed inline writer after inventory merge');
$expect('delay' === strtolower((string) (!empty($selected['delayed']) ? 'delay' : 'native')), 'resolved inline provider retains DELAY lane evidence');

$total = $passes + count($failures);
echo PHP_EOL . 'Result: ' . $passes . '/' . $total . ' PASS' . PHP_EOL;
if ($failures) { exit(1); }
