<?php
/**
 * UltraCache controlled Lighthouse A/B comparator.
 * Development/manual diagnostic only; never loaded by the plugin runtime.
 *
 * Usage:
 * php tests/manual/compare-lighthouse.php baseline.json candidate.json [more.json ...]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$files = array_slice($argv, 1);
if (count($files) < 2) {
    fwrite(STDERR, "Usage: php tests/manual/compare-lighthouse.php baseline.json candidate.json [more.json ...]\n");
    exit(1);
}

function ultracache_ab_read_report($file)
{
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('Cannot read: ' . $file);
    }
    $raw = file_get_contents($file);
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid Lighthouse JSON: ' . $file);
    }
    return $json;
}

function ultracache_ab_audit_value($report, $audit_id, $field = 'numericValue')
{
    if (!isset($report['audits'][$audit_id]) || !is_array($report['audits'][$audit_id])) {
        return null;
    }
    return array_key_exists($field, $report['audits'][$audit_id]) ? $report['audits'][$audit_id][$field] : null;
}

function ultracache_ab_score($report)
{
    if (isset($report['categories']['performance']['score']) && is_numeric($report['categories']['performance']['score'])) {
        return round((float) $report['categories']['performance']['score'] * 100, 1);
    }
    return null;
}

function ultracache_ab_dom_size($report)
{
    $items = isset($report['audits']['dom-size']['details']['items']) && is_array($report['audits']['dom-size']['details']['items'])
        ? $report['audits']['dom-size']['details']['items']
        : array();
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (isset($item['statistic']) && stripos((string) $item['statistic'], 'total') !== false && isset($item['value'])) {
            return (int) $item['value'];
        }
        if (isset($item['label']) && stripos((string) $item['label'], 'total') !== false && isset($item['value'])) {
            return (int) $item['value'];
        }
    }
    $numeric = ultracache_ab_audit_value($report, 'dom-size');
    return is_numeric($numeric) ? (int) $numeric : null;
}

function ultracache_ab_third_party_google($report)
{
    $transfer = 0;
    $main_thread = 0;
    $requests = 0;
    $details = isset($report['audits']['third-parties']['details']['items']) && is_array($report['audits']['third-parties']['details']['items'])
        ? $report['audits']['third-parties']['details']['items']
        : array();

    $walk = function ($items) use (&$walk, &$transfer, &$main_thread, &$requests) {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = '';
            foreach (array('url', 'entity', 'name') as $key) {
                if (isset($item[$key]) && is_string($item[$key])) {
                    $url .= ' ' . strtolower($item[$key]);
                }
            }
            $is_google = strpos($url, 'googletagmanager') !== false || strpos($url, 'google tag manager') !== false || strpos($url, 'google-analytics') !== false;
            if ($is_google) {
                if (isset($item['transferSize']) && is_numeric($item['transferSize'])) {
                    $transfer += (float) $item['transferSize'];
                }
                if (isset($item['mainThreadTime']) && is_numeric($item['mainThreadTime'])) {
                    $main_thread += (float) $item['mainThreadTime'];
                }
                $requests++;
            }
            if (isset($item['subItems']['items']) && is_array($item['subItems']['items'])) {
                $walk($item['subItems']['items']);
            }
        }
    };
    $walk($details);

    return array(
        'transferKiB' => round($transfer / 1024, 1),
        'mainThreadMs' => round($main_thread, 1),
        'matchedItems' => $requests,
    );
}

function ultracache_ab_ms($value)
{
    return is_numeric($value) ? round((float) $value, 1) : null;
}

function ultracache_ab_metric_row($file, $report)
{
    $google = ultracache_ab_third_party_google($report);
    return array(
        'run' => basename($file),
        'score' => ultracache_ab_score($report),
        'FCPms' => ultracache_ab_ms(ultracache_ab_audit_value($report, 'first-contentful-paint')),
        'LCPms' => ultracache_ab_ms(ultracache_ab_audit_value($report, 'largest-contentful-paint')),
        'TBTms' => ultracache_ab_ms(ultracache_ab_audit_value($report, 'total-blocking-time')),
        'CLS' => ultracache_ab_audit_value($report, 'cumulative-layout-shift'),
        'SIms' => ultracache_ab_ms(ultracache_ab_audit_value($report, 'speed-index')),
        'DOM' => ultracache_ab_dom_size($report),
        'googleKiB' => $google['transferKiB'],
        'googleMainMs' => $google['mainThreadMs'],
    );
}

try {
    $rows = array();
    foreach ($files as $file) {
        $rows[] = ultracache_ab_metric_row($file, ultracache_ab_read_report($file));
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$headers = array('run', 'score', 'FCPms', 'LCPms', 'TBTms', 'CLS', 'SIms', 'DOM', 'googleKiB', 'googleMainMs');
$widths = array();
foreach ($headers as $header) {
    $widths[$header] = strlen($header);
}
foreach ($rows as $row) {
    foreach ($headers as $header) {
        $value = $row[$header] === null ? '-' : (string) $row[$header];
        $widths[$header] = max($widths[$header], strlen($value));
    }
}

$print_row = function ($row) use ($headers, $widths) {
    $parts = array();
    foreach ($headers as $header) {
        $value = array_key_exists($header, $row) && $row[$header] !== null ? (string) $row[$header] : '-';
        $parts[] = str_pad($value, $widths[$header]);
    }
    echo implode('  ', $parts) . "\n";
};

$header_row = array();
foreach ($headers as $header) {
    $header_row[$header] = $header;
}
$print_row($header_row);
echo str_repeat('-', array_sum($widths) + (count($headers) - 1) * 2) . "\n";
foreach ($rows as $row) {
    $print_row($row);
}

$baseline = $rows[0];
echo "\nDeltas vs baseline: " . $baseline['run'] . "\n";
foreach (array_slice($rows, 1) as $row) {
    $parts = array();
    foreach (array('score', 'FCPms', 'LCPms', 'TBTms', 'CLS', 'SIms', 'DOM', 'googleKiB', 'googleMainMs') as $key) {
        if (is_numeric($baseline[$key]) && is_numeric($row[$key])) {
            $delta = round((float) $row[$key] - (float) $baseline[$key], 2);
            $parts[] = $key . '=' . ($delta >= 0 ? '+' : '') . $delta;
        }
    }
    echo $row['run'] . ': ' . implode('  ', $parts) . "\n";
}
