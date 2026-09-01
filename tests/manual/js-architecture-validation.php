<?php
/**
 * UltraCache JavaScript architecture validation report.
 * Development/manual diagnostic only; never loaded by the plugin runtime.
 *
 * Usage:
 *   php tests/manual/js-architecture-validation.php lighthouse.json [runtime-scan.json]
 */

declare(strict_types=1);

function ultracache_validation_read_json(string $file): array
{
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('Cannot read: ' . $file);
    }
    $json = json_decode((string) file_get_contents($file), true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid JSON: ' . $file);
    }
    return $json;
}

function ultracache_validation_audit_numeric(array $report, string $audit_id, string $field = 'numericValue'): ?float
{
    $value = $report['audits'][$audit_id][$field] ?? null;
    return is_numeric($value) ? (float) $value : null;
}

function ultracache_validation_performance_score(array $report): ?float
{
    $value = $report['categories']['performance']['score'] ?? null;
    return is_numeric($value) ? round((float) $value * 100, 1) : null;
}

function ultracache_validation_walk(array $value, callable $callback): void
{
    $callback($value);
    foreach ($value as $child) {
        if (is_array($child)) {
            ultracache_validation_walk($child, $callback);
        }
    }
}

function ultracache_validation_is_uc_url(string $url): bool
{
    $url = strtolower($url);
    return str_contains($url, '/wp-content/plugins/ultracache/')
        || str_contains($url, '/uploads/ultracache/')
        || str_contains($url, '/ultracache/js-bundles/')
        || str_contains($url, 'ultracache-native')
        || str_contains($url, 'ultracache-defer')
        || str_contains($url, 'ultracache-delay');
}

function ultracache_validation_render_blocking(array $report): array
{
    $seen = array();
    $items = array();
    foreach (($report['audits'] ?? array()) as $audit_id => $audit) {
        if (!is_array($audit) || false === stripos((string) $audit_id, 'render-block')) {
            continue;
        }
        ultracache_validation_walk($audit, static function (array $node) use (&$seen, &$items): void {
            $url = isset($node['url']) && is_string($node['url']) ? trim($node['url']) : '';
            if ('' === $url || isset($seen[$url])) {
                return;
            }
            $seen[$url] = true;
            $bytes = 0.0;
            foreach (array('transferSize', 'totalBytes', 'resourceSize', 'wastedBytes') as $key) {
                if (isset($node[$key]) && is_numeric($node[$key])) {
                    $bytes = max($bytes, (float) $node[$key]);
                }
            }
            $ms = 0.0;
            foreach (array('wastedMs', 'duration', 'delay', 'savings') as $key) {
                if (isset($node[$key]) && is_numeric($node[$key])) {
                    $ms = max($ms, (float) $node[$key]);
                }
            }
            $items[] = array('url' => $url, 'bytes' => $bytes, 'ms' => $ms, 'ultracache' => ultracache_validation_is_uc_url($url));
        });
    }
    $uc = array_values(array_filter($items, static fn(array $item): bool => !empty($item['ultracache'])));
    return array(
        'allCount' => count($items),
        'ucCount' => count($uc),
        'ucKiB' => round(array_sum(array_column($uc, 'bytes')) / 1024, 1),
        'ucMs' => round(array_sum(array_column($uc, 'ms')), 1),
        'ucItems' => $uc,
    );
}

function ultracache_validation_forced_reflow_ms(array $report): float
{
    $total = 0.0;
    foreach (($report['audits'] ?? array()) as $audit_id => $audit) {
        if (!is_array($audit) || false === stripos((string) $audit_id, 'forced-reflow')) {
            continue;
        }
        ultracache_validation_walk($audit, static function (array $node) use (&$total): void {
            if (isset($node['duration']) && is_numeric($node['duration'])) {
                $total += (float) $node['duration'];
            }
        });
    }
    return round($total, 1);
}

function ultracache_validation_dom_size(array $report): ?int
{
    $items = $report['audits']['dom-size']['details']['items'] ?? array();
    if (is_array($items)) {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = strtolower((string) ($item['statistic'] ?? $item['label'] ?? ''));
            if (str_contains($label, 'total') && isset($item['value']) && is_numeric($item['value'])) {
                return (int) $item['value'];
            }
        }
    }
    $numeric = ultracache_validation_audit_numeric($report, 'dom-size');
    return null === $numeric ? null : (int) $numeric;
}

function ultracache_validation_find_classification_audit(array $payload): ?array
{
    if (isset($payload['classificationAudit']) && is_array($payload['classificationAudit'])) {
        return $payload['classificationAudit'];
    }
    foreach ($payload as $value) {
        if (is_array($value)) {
            $found = ultracache_validation_find_classification_audit($value);
            if (null !== $found) {
                return $found;
            }
        }
    }
    return null;
}

function ultracache_validation_lane_summary(?array $audit): array
{
    $counts = array('native' => 0, 'defer' => 0, 'delay' => 0);
    $seen = array();
    if (is_array($audit['records'] ?? null)) {
        foreach ($audit['records'] as $record) {
            if (!is_array($record)) {
                continue;
            }
            $lane = strtolower((string) ($record['lane'] ?? ''));
            if (!array_key_exists($lane, $counts)) {
                continue;
            }
            $key = implode('|', array(
                (string) ($record['url'] ?? ''),
                (string) ($record['handle'] ?? ''),
                $lane,
            ));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $counts[$lane]++;
        }
    }
    $unclassified = is_array($audit['unclassifiedRequests'] ?? null)
        ? count($audit['unclassifiedRequests'])
        : (int) ($audit['unclassifiedCount'] ?? 0);
    return array(
        'native' => $counts['native'],
        'defer' => $counts['defer'],
        'delay' => $counts['delay'],
        'unclassified' => $unclassified,
        'invariantPassed' => is_array($audit) ? (bool) ($audit['invariantPassed'] ?? (0 === $unclassified)) : null,
    );
}

function ultracache_validation_report(array $lighthouse, ?array $runtime_scan = null): array
{
    $blocking = ultracache_validation_render_blocking($lighthouse);
    $audit = null === $runtime_scan ? null : ultracache_validation_find_classification_audit($runtime_scan);
    $lanes = ultracache_validation_lane_summary($audit);
    return array(
        'score' => ultracache_validation_performance_score($lighthouse),
        'FCPms' => ultracache_validation_audit_numeric($lighthouse, 'first-contentful-paint'),
        'LCPms' => ultracache_validation_audit_numeric($lighthouse, 'largest-contentful-paint'),
        'TBTms' => ultracache_validation_audit_numeric($lighthouse, 'total-blocking-time'),
        'CLS' => ultracache_validation_audit_numeric($lighthouse, 'cumulative-layout-shift'),
        'SIms' => ultracache_validation_audit_numeric($lighthouse, 'speed-index'),
        'DOM' => ultracache_validation_dom_size($lighthouse),
        'forcedReflowMs' => ultracache_validation_forced_reflow_ms($lighthouse),
        'renderBlockingAll' => $blocking['allCount'],
        'renderBlockingUC' => $blocking['ucCount'],
        'renderBlockingUCKiB' => $blocking['ucKiB'],
        'renderBlockingUCMs' => $blocking['ucMs'],
        'native' => $lanes['native'],
        'defer' => $lanes['defer'],
        'delay' => $lanes['delay'],
        'unclassified' => $lanes['unclassified'],
        'classificationInvariantPassed' => $lanes['invariantPassed'],
        'ucRenderBlockingItems' => $blocking['ucItems'],
    );
}

function ultracache_validation_print(array $report): void
{
    $keys = array('score','FCPms','LCPms','TBTms','CLS','SIms','DOM','forcedReflowMs','renderBlockingAll','renderBlockingUC','renderBlockingUCKiB','renderBlockingUCMs','native','defer','delay','unclassified');
    foreach ($keys as $key) {
        $value = $report[$key] ?? null;
        echo str_pad($key, 24) . ': ' . (null === $value ? '-' : (string) $value) . PHP_EOL;
    }
    echo str_pad('classificationInvariant', 24) . ': ';
    echo null === ($report['classificationInvariantPassed'] ?? null) ? '-' : (!empty($report['classificationInvariantPassed']) ? 'PASS' : 'FAIL');
    echo PHP_EOL;
    if (!empty($report['ucRenderBlockingItems'])) {
        echo PHP_EOL . "UltraCache render-blocking resources:" . PHP_EOL;
        foreach ($report['ucRenderBlockingItems'] as $item) {
            echo '- ' . $item['url'] . '  ' . round((float) $item['bytes'] / 1024, 1) . ' KiB  ' . round((float) $item['ms'], 1) . " ms" . PHP_EOL;
        }
    }
}

function ultracache_validation_main(array $argv): int
{
    if (count($argv) < 2 || count($argv) > 3) {
        fwrite(STDERR, "Usage: php tests/manual/js-architecture-validation.php lighthouse.json [runtime-scan.json]\n");
        return 1;
    }
    try {
        $lighthouse = ultracache_validation_read_json($argv[1]);
        $scan = isset($argv[2]) ? ultracache_validation_read_json($argv[2]) : null;
        ultracache_validation_print(ultracache_validation_report($lighthouse, $scan));
        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        return 1;
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(ultracache_validation_main($argv));
}
