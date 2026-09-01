<?php
/**
 * UltraCache 3.11.23 visual-init attribution analyzer regression contracts.
 *
 * Run:
 *   wp eval-file tests/regression/visual-init-attribution-analyzer-regression.php
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/manual/analyze-dom-runtime-attribution.php';

$passes = 0;
$failures = array();

function ultracache_visual_attribution_expect(bool $condition, string $label): void
{
    global $passes, $failures;
    if ($condition) {
        $passes++;
        echo esc_html('[PASS] ' . $label) . PHP_EOL;
        return;
    }
    $failures[] = $label;
    echo esc_html('[FAIL] ' . $label) . PHP_EOL;
}

$audit = array(
    'meta' => array('droppedOperations' => 0),
    'scriptExecutions' => array(
        array(
            'startTimeMs' => 1400,
            'endTimeMs' => 1444,
            'inferredLane' => 'firstparty',
            'domDelta' => 238,
            'completion' => 'loaded',
            'descriptor' => array(
                'handle' => 'swiper',
                'src' => '/wp-content/plugins/example/swiper.js',
                'delayReason' => '',
            ),
        ),
        array(
            'startTimeMs' => 1200,
            'endTimeMs' => 1204,
            'inferredLane' => 'firstparty',
            'domDelta' => 2,
            'completion' => 'loaded',
            'descriptor' => array(
                'handle' => 'wc-add-to-cart',
                'src' => '/wp-content/plugins/woocommerce/assets/js/frontend/add-to-cart.min.js',
                'delayReason' => '',
            ),
        ),
    ),
    'operationSummary' => array(
        array(
            'source' => 'https://example.test/swiper.js:667:12',
            'calls' => 35,
            'positiveElementDelta' => 240,
            'negativeElementDelta' => 0,
            'interestingCalls' => 30,
            'methods' => array('appendChild' => 35),
        ),
    ),
);

$decision = ultracache_visual_audit_decision($audit);
$rows = $decision['executions'];

ultracache_visual_attribution_expect(true === $decision['hasEvidenceForProductionVisualFix'], 'A: large source-proven Swiper DOM growth crosses the production-fix evidence gate');
ultracache_visual_attribution_expect(238 === $decision['largestSingleScriptDomDelta'], 'B: largest single-script DOM delta is retained exactly');
ultracache_visual_attribution_expect('swiper' === $rows[0]['handle'], 'C: visual DOM-growth culprit ranks ahead of small functional execution');
ultracache_visual_attribution_expect(true === $rows[0]['visualIdentity'], 'D: Swiper identity is classified as visual initialization');
ultracache_visual_attribution_expect(false === $rows[1]['visualIdentity'], 'E: WooCommerce add-to-cart is not falsely classified as visual initialization');
ultracache_visual_attribution_expect(1 === count($decision['operationSources']), 'F: synchronous visual DOM source is retained for stack attribution');

$clean = ultracache_visual_audit_decision(array(
    'scriptExecutions' => array(
        array(
            'startTimeMs' => 100,
            'endTimeMs' => 104,
            'inferredLane' => 'firstparty',
            'domDelta' => 3,
            'descriptor' => array('handle' => 'site-runtime', 'src' => '/theme/runtime.js', 'delayReason' => ''),
        ),
    ),
));
ultracache_visual_attribution_expect(false === $clean['hasEvidenceForProductionVisualFix'], 'G: small non-visual DOM activity does not authorize a speculative production fix');

echo esc_html(sprintf('Result: %d PASS, %d FAIL', $passes, count($failures))) . PHP_EOL;
if ($failures) {
    exit(1);
}
