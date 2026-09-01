<?php
/**
 * UltraCache 3.13.05 observed-execution / DOM-order provider selection regression.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$root = dirname(__DIR__, 2);
require_once $root . '/includes/profiler/class-runtime-js-scanner-trait.php';
require_once $root . '/includes/profiler/class-runtime-js-rules-trait.php';

final class UltraCacheRuntimeProviderOrderHarness
{
    use Ultra_Cache_Runtime_JS_Scanner_Trait;
    use Ultra_Cache_Runtime_JS_Rules_Trait;

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

$h = new UltraCacheRuntimeProviderOrderHarness();
$providers = array(
    array('id' => 'root-before-early', 'order' => 2),
    array('id' => 'root-before-nearest', 'order' => 5),
    array('id' => 'root-after', 'order' => 9),
);
$selected = $h->choose($providers, array('id' => 'consumer', 'order' => 7));
$expect('root-before-nearest' === ($selected['id'] ?? ''), 'nearest preceding receiver writer wins over earlier and later candidates');

$selected = $h->choose(array(array('id' => 'only', 'order' => 9)), array('id' => 'consumer', 'order' => 7));
$expect('only' === ($selected['id'] ?? ''), 'single provider remains usable as legacy fallback when no preceding writer exists');

$selected = $h->choose(array(array('id' => 'one'), array('id' => 'two')), array('id' => 'consumer'));
$expect(empty($selected), 'ambiguous providers without DOM order remain unresolved');

$selected = $h->choose(array(array('id' => 'only')), array('id' => 'consumer'));
$expect('only' === ($selected['id'] ?? ''), 'single provider without order remains deterministic');

$providers = array(
    array('id' => 'dom-nearest-but-executed-late', 'order' => 5, 'executionSequence' => 12),
    array('id' => 'observed-provider', 'order' => 2, 'executionSequence' => 7),
);
$selected = $h->choose($providers, array('id' => 'consumer', 'order' => 7, 'executionSequence' => 10));
$expect('observed-provider' === ($selected['id'] ?? ''), 'observed delayed execution sequence outranks DOM proximity when both sides expose runtime sequence');

$providers = array(
    array('id' => 'future-provider', 'order' => 2, 'executionSequence' => 11),
    array('id' => 'preceding-provider', 'order' => 1, 'executionSequence' => 4),
);
$selected = $h->choose($providers, array('id' => 'consumer', 'order' => 7, 'executionSequence' => 8));
$expect('preceding-provider' === ($selected['id'] ?? ''), 'provider that executed after the consumer is rejected even when its DOM position is earlier');

$total = $passes + count($failures);
echo PHP_EOL . 'Result: ' . $passes . '/' . $total . ' PASS' . PHP_EOL;
if ($failures) { exit(1); }
