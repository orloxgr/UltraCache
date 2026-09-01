<?php
/**
 * UltraCache 3.12.14 classification audit-mode regression.
 *
 * Run:
 *   php tests/regression/classification-audit-mode-regression.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/engine/js/class-js-runtime-registry-trait.php';
require_once $root . '/includes/engine/js/class-js-policy-trait.php';
require_once $root . '/includes/engine/js/class-js-router-trait.php';

final class UltraCacheClassificationAuditHarness
{
    use Ultra_Cache_Engine_JS_Runtime_Registry_Trait;
    use Ultra_Cache_Engine_JS_Policy_Trait;
    use Ultra_Cache_Engine_JS_Router_Trait;

    public bool $scan = false;

    public function is_runtime_js_scan_request(): bool { return $this->scan; }
    public function apply(array $route, string $tag, string $handle = 'example', string $src = 'https://site.example/app.js'): string
    {
        return $this->ultracache_apply_registered_script_route($route, $tag, $handle, $src, array());
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

$router = file_get_contents($root . '/includes/engine/js/class-js-router-trait.php');
$delay = file_get_contents($root . '/includes/engine/js/class-js-delay-trait.php');
$collector = file_get_contents($root . '/assets/js/runtime-js-scan-collector.js');
$finder = file_get_contents($root . '/assets/js/dynamic-script-finder-bootstrap.js');
$loader = file_get_contents($root . '/assets/js/delayed-js-loader.js');

$expect(is_string($router) && false !== strpos($router, 'ultracache_classification_audit_enabled'), 'A1: registered Router owns a bounded scan-only audit gate');
$expect(is_string($router) && false !== strpos($router, "method_exists(\$this, 'is_runtime_js_scan_request')"), 'A2: audit activation is tied to verified Runtime Scan request state');
$expect(is_string($router) && false !== strpos($router, "data-ultracache-audit-lane") && false !== strpos($router, "data-ultracache-audit-caught-by"), 'A3: registered decisions expose lane/reason/source/catch metadata only through audit attributes');
$expect(is_string($delay) && false === strpos($delay, 'auditEnabled') && is_string($finder) && false !== strpos($finder, 'setAuditRecorder'), 'A4: Dynamic Finder exposes only a bounded callback hook; no audit mode is transported in production Finder config');
$expect(is_string($collector) && false !== strpos($collector, "classificationAudit: buildClassificationAuditPayload()"), 'A5: classification audit piggybacks the existing Runtime Scan report payload');
$expect(is_string($collector) && false !== strpos($collector, "performance.getEntriesByType('resource')") && false !== strpos($collector, "initiatorType") && false !== strpos($collector, "'script'"), 'A6: audit compares decisions with actual browser script resource requests');
$expect(is_string($collector) && false !== strpos($collector, 'unclassifiedRequests') && false !== strpos($collector, 'invariantPassed'), 'A7: audit exposes unclassified escape requests and invariant status');
$expect(is_string($finder) && false !== strpos($finder, 'setAuditRecorder') && false === strpos($finder, '__ultracacheJsClassificationAudit') && is_string($collector) && false !== strpos($collector, 'installDynamicFinderClassificationAuditBridge') && false !== strpos($collector, 'setAuditRecorder(recordDynamicClassification)'), 'A8: scan-only collector owns dynamic recording while production Finder exposes only a callback hook');
$expect(is_string($loader) && false !== strpos($loader, "data-ultracache-audit-") && false !== strpos($loader, "'caught-by'"), 'A9: delayed executable replacements preserve classification provenance');
$expect(is_string($router) && false === strpos($router, 'update_option(') && false === strpos($router, 'wp_remote_'), 'A10: Router audit adds no persistence or network telemetry');

$h = new UltraCacheClassificationAuditHarness();
$h->scan = false;
$plain = $h->apply(array('lane' => 'native', 'reason' => 'default-native', 'action' => 'unchanged', 'rule_id' => 'default-native'), '<script src="https://site.example/app.js"></script>');
$expect(false === strpos($plain, 'data-ultracache-audit-'), 'B1: normal production output contains no classification-audit attributes');

// Static-local cache inside the trait is request-scoped by design, so use a fresh
// PHP process path for scan=true behavior through a dedicated harness subclass.
final class UltraCacheClassificationAuditEnabledHarness
{
    use Ultra_Cache_Engine_JS_Runtime_Registry_Trait;
    use Ultra_Cache_Engine_JS_Policy_Trait;
    use Ultra_Cache_Engine_JS_Router_Trait;
    public function is_runtime_js_scan_request(): bool { return true; }
    public function apply(array $route, string $tag): string
    {
        return $this->ultracache_apply_registered_script_route($route, $tag, 'example', 'https://site.example/app.js', array());
    }
}
$enabled = new UltraCacheClassificationAuditEnabledHarness();
$tag = $enabled->apply(
    array(
        'lane' => 'defer',
        'reason' => 'visible-defer-instead-of-delay',
        'action' => 'unchanged',
        'rule_id' => 'visible-defer',
        'matched_pattern' => 'example/app.js',
    ),
    '<script src="https://site.example/app.js"></script>'
);
$expect(false !== strpos($tag, 'data-ultracache-audit-lane="defer"'), 'B2: verified Runtime Scan emits the DEFER classification lane');
$expect(false !== strpos($tag, 'data-ultracache-audit-source="visible-list"'), 'B3: verified Runtime Scan records visible-list decision source');
$expect(false !== strpos($tag, 'data-ultracache-audit-caught-by="registered-router"'), 'B4: verified Runtime Scan records registered Router catch path');
$expect(false !== strpos($tag, 'data-ultracache-audit-rule="visible-defer"'), 'B5: verified Runtime Scan records the matched policy rule id');

$optout = $enabled->apply(
    array(
        'lane' => 'native',
        'reason' => 'explicit-author-optimizer-opt-out',
        'action' => 'unchanged',
        'rule_id' => 'explicit-author-opt-out',
    ),
    '<script data-no-defer src="https://site.example/optout.js"></script>'
);
$expect(false !== strpos($optout, 'data-ultracache-audit-source="explicit-author-opt-out"'), 'B6: explicit author opt-out is not mislabeled as an integration switch');

$contract = require $root . '/tests/architecture/js-policy-contract.php';
$audit = isset($contract['classification_audit_mode']) && is_array($contract['classification_audit_mode']) ? $contract['classification_audit_mode'] : array();
$expect(true === ($audit['verified_runtime_scan_only'] ?? false), 'C1: architecture contract limits detailed classification recording to verified Runtime Scan');
$expect(false === ($audit['production_telemetry'] ?? true), 'C2: architecture contract forbids production classification telemetry');
$expect(0 === ($audit['frontend_requests_added'] ?? -1), 'C3: classification audit adds zero frontend requests');
$expect(true === ($audit['unclassified_script_request_is_engine_bug'] ?? false), 'C4: unclassified script request is explicitly an engine/Finder bug');

$total = $passes + count($failures);
echo PHP_EOL . 'Result: ' . $passes . '/' . $total . ' PASS' . PHP_EOL;
if (!empty($failures)) {
    exit(1);
}
