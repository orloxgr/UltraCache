<?php
/**
 * UltraCache 3.11.26 forced-reflow source mapping regression contracts.
 *
 * Run:
 *   wp eval-file tests/regression/forced-reflow-source-mapping-regression.php
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/manual/analyze-forced-reflow-source.php';

$passes = 0;
$failures = array();

function ultracache_reflow_expect(bool $condition, string $label): void
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

$html = <<<'HTML'
<!doctype html>
<html>
<head>
<script id="woocommerce-inline-js-after">
window.wcSettings = {cart:true};
document.body && document.body.offsetHeight;
</script>
<script id="googlesitekit-consent-mode-js-after">
window.dataLayer = window.dataLayer || [];
</script>
</head>
<body></body>
</html>
HTML;

$report = array(
    'audits' => array(
        'forced-reflow-insight' => array(
            'details' => array(
                'items' => array(
                    array(
                        'source' => '(index):6:18',
                        'duration' => 61,
                    ),
                    array(
                        'url' => 'https://example.test/wp-includes/js/jquery/jquery.min.js',
                        'lineNumber' => 2,
                        'columnNumber' => 14,
                        'duration' => 17,
                    ),
                ),
            ),
        ),
        'largest-contentful-paint' => array(
            'details' => array(
                'items' => array(
                    array('url' => 'https://example.test/image.webp', 'lineNumber' => 999),
                ),
            ),
        ),
    ),
);

$analysis = ultracache_reflow_analyze($report, $html, 'https://example.test/product');
$locations = $analysis['locations'];

ultracache_reflow_expect(2 === count($locations), 'A: only reflow/layout audit source locations are collected');
ultracache_reflow_expect(61.0 === (float) $locations[0]['durationMs'], 'B: locations are ranked by reflow duration');
ultracache_reflow_expect(true === $locations[0]['documentSource'], 'C: (index) source is classified as the document');
ultracache_reflow_expect(is_array($locations[0]['script']), 'D: document line maps to the containing inline script');
ultracache_reflow_expect('woocommerce-inline-js-after' === $locations[0]['script']['id'], 'E: inline script id is preserved for handle/source attribution');
ultracache_reflow_expect('woocommerce' === $locations[0]['script']['owner'], 'F: strong script-id/content evidence maps WooCommerce ownership');
ultracache_reflow_expect(false === $locations[1]['documentSource'], 'G: external jquery source is not falsely mapped to an inline script');
ultracache_reflow_expect(null === $locations[1]['script'], 'H: external source has no inline ownership assignment');

$unknown_html = "<html>\n<script id=\"mystery-js-after\">\nvoid document.documentElement.offsetWidth;\n</script>\n</html>";
$unknown_report = array('audits' => array('forced-reflow' => array('details' => array('items' => array(array('source' => '(index):3:1', 'duration' => 4))))));
$unknown = ultracache_reflow_analyze($unknown_report, $unknown_html);
ultracache_reflow_expect('unknown' === $unknown['locations'][0]['script']['owner'], 'I: unknown inline ownership stays unknown instead of being guessed');
ultracache_reflow_expect('low' === $unknown['locations'][0]['script']['ownerConfidence'], 'J: script-id-only unknown evidence remains low confidence');

$zero_based = ultracache_reflow_script_for_line(ultracache_reflow_inline_scripts("<html>\n<script id=\"x\">\n1;\n</script>\n</html>"), 1);
ultracache_reflow_expect(is_array($zero_based) && 'reported-as-zero-based' === $zero_based['lineBasis'], 'K: protocol-style zero-based line numbers have an explicit +1 fallback');

echo esc_html(sprintf('Result: %d PASS, %d FAIL', $passes, count($failures))) . PHP_EOL;
if ($failures) {
    exit(1);
}
