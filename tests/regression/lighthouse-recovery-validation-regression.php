<?php
/**
 * UltraCache 3.12.17 Lighthouse recovery validation contracts.
 * Development-only; never loaded by the plugin runtime.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/manual/js-architecture-validation.php';

$passes = 0;
$failures = array();
function uc317_expect(bool $ok, string $label): void {
    global $passes, $failures;
    if ($ok) { $passes++; echo "[PASS] $label\n"; return; }
    $failures[] = $label; echo "[FAIL] $label\n";
}

$lh = array(
    'categories' => array('performance' => array('score' => 0.97)),
    'audits' => array(
        'first-contentful-paint' => array('numericValue' => 700),
        'largest-contentful-paint' => array('numericValue' => 900),
        'total-blocking-time' => array('numericValue' => 0),
        'cumulative-layout-shift' => array('numericValue' => 0.007),
        'speed-index' => array('numericValue' => 1400),
        'dom-size' => array('details' => array('items' => array(array('statistic' => 'Total elements', 'value' => 2100)))),
        'render-blocking-resources' => array('details' => array('items' => array(
            array('url' => 'https://example.test/wp-content/plugins/ultracache/assets/js/a.js', 'transferSize' => 2048, 'wastedMs' => 120),
            array('url' => 'https://example.test/wp-content/themes/t/a.js', 'transferSize' => 4096, 'wastedMs' => 80),
        ))),
        'forced-reflow-insight' => array('details' => array('items' => array(array('duration' => 17.5))))
    )
);
$scan = array('payload' => array('classificationAudit' => array(
    'records' => array(
        array('url' => 'https://example.test/a.js', 'handle' => 'a', 'lane' => 'native'),
        array('url' => 'https://example.test/b.js', 'handle' => 'b', 'lane' => 'defer'),
        array('url' => 'https://example.test/c.js', 'handle' => 'c', 'lane' => 'delay'),
        array('url' => 'https://example.test/c.js', 'handle' => 'c', 'lane' => 'delay'),
    ),
    'unclassifiedRequests' => array(array('url' => 'https://example.test/escape.js')),
    'invariantPassed' => false,
)));

$report = ultracache_validation_report($lh, $scan);
uc317_expect(97.0 === $report['score'], 'performance score is normalized to 0-100');
uc317_expect(700.0 === $report['FCPms'] && 900.0 === $report['LCPms'] && 0.0 === $report['TBTms'], 'core Lighthouse timing metrics are preserved');
uc317_expect(2100 === $report['DOM'], 'DOM size is extracted');
uc317_expect(17.5 === $report['forcedReflowMs'], 'forced reflow duration is surfaced');
uc317_expect(2 === $report['renderBlockingAll'], 'all render-blocking resources are counted');
uc317_expect(1 === $report['renderBlockingUC'], 'UltraCache render-blocking resources are isolated');
uc317_expect(2.0 === $report['renderBlockingUCKiB'] && 120.0 === $report['renderBlockingUCMs'], 'UltraCache render-blocking footprint is summarized');
uc317_expect(1 === $report['native'] && 1 === $report['defer'] && 1 === $report['delay'], 'classification lane distribution deduplicates equivalent records');
uc317_expect(1 === $report['unclassified'] && false === $report['classificationInvariantPassed'], 'classification escapes fail the invariant');
uc317_expect(null === ultracache_validation_lane_summary(null)['invariantPassed'], 'Lighthouse-only reports do not invent Runtime Scan evidence');
uc317_expect(true === ultracache_validation_is_uc_url('https://x.test/uploads/ultracache/js-bundles/native.js'), 'generated UltraCache bundle URLs are recognized');
uc317_expect(false === ultracache_validation_is_uc_url('https://x.test/wp-content/themes/theme/app.js'), 'unrelated resources are not attributed to UltraCache');

$manual = file_get_contents(dirname(__DIR__) . '/manual/js-architecture-validation.php');
uc317_expect(false === strpos($manual, 'wp_enqueue_script'), 'validation tool cannot enqueue production scripts');
uc317_expect(false === strpos($manual, 'add_action('), 'validation tool cannot register WordPress runtime hooks');

if ($failures) {
    fwrite(STDERR, count($failures) . " failure(s).\n");
    exit(1);
}
echo $passes . "/" . $passes . " PASS\n";
