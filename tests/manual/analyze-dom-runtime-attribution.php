<?php
/**
 * UltraCache DOM/runtime attribution analyzer.
 * Development/manual diagnostic only; never loaded by the plugin runtime.
 *
 * Usage:
 *   php tests/manual/analyze-dom-runtime-attribution.php dom-runtime-audit.json
 */

declare(strict_types=1);

function ultracache_visual_audit_is_visual_identity(string $value): bool
{
    return (bool) preg_match('/swiper|slider|carousel|elementor|sr7|revslider|revolution|tptools|tp-tools|slick|splide|owl(?:\.|-|_)?carousel|flickity|keen-slider|bxslider|masterslider|layerslider|metaslider|smartslider|n2-ss|soliloquy|royalslider/i', $value);
}

function ultracache_visual_audit_read(string $file): array
{
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('Cannot read audit JSON: ' . $file);
    }

    $raw = file_get_contents($file);
    if (!is_string($raw) || '' === trim($raw)) {
        throw new RuntimeException('Audit JSON is empty: ' . $file);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid audit JSON: ' . $file);
    }

    return $data;
}

function ultracache_visual_audit_execution_rows(array $audit): array
{
    $rows = array();
    foreach ((array) ($audit['scriptExecutions'] ?? array()) as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        $descriptor = isset($item['descriptor']) && is_array($item['descriptor']) ? $item['descriptor'] : array();
        $handle = (string) ($descriptor['handle'] ?? '');
        $src = (string) ($descriptor['src'] ?? '');
        $reason = (string) ($descriptor['delayReason'] ?? '');
        $lane = (string) ($item['inferredLane'] ?? 'unknown');
        $dom_delta = is_numeric($item['domDelta'] ?? null) ? (int) $item['domDelta'] : 0;
        $start = is_numeric($item['startTimeMs'] ?? null) ? (float) $item['startTimeMs'] : null;
        $end = is_numeric($item['endTimeMs'] ?? null) ? (float) $item['endTimeMs'] : null;
        $duration = (null !== $start && null !== $end) ? max(0.0, $end - $start) : null;
        $identity = trim($handle . ' ' . $src . ' ' . $reason);
        $visual = ultracache_visual_audit_is_visual_identity($identity);

        $score = 0;
        if ($dom_delta > 0) {
            $score += min(60, (int) round($dom_delta / 5));
        }
        if ($visual) {
            $score += 30;
        }
        if ('interaction' === $lane) {
            $score += 8;
        }
        if (null !== $duration && $duration >= 16.0) {
            $score += min(20, (int) round($duration / 8));
        }

        $rows[] = array(
            'index' => (int) $index,
            'handle' => $handle,
            'src' => $src,
            'reason' => $reason,
            'lane' => $lane,
            'startMs' => $start,
            'endMs' => $end,
            'durationMs' => null === $duration ? null : round($duration, 2),
            'domDelta' => $dom_delta,
            'visualIdentity' => $visual,
            'score' => $score,
            'completion' => (string) ($item['completion'] ?? ''),
        );
    }

    usort($rows, static function (array $a, array $b): int {
        if ($b['score'] !== $a['score']) {
            return $b['score'] <=> $a['score'];
        }
        if ($b['domDelta'] !== $a['domDelta']) {
            return $b['domDelta'] <=> $a['domDelta'];
        }
        return ($a['startMs'] ?? PHP_FLOAT_MAX) <=> ($b['startMs'] ?? PHP_FLOAT_MAX);
    });

    return $rows;
}

function ultracache_visual_audit_operation_rows(array $audit): array
{
    $rows = array();
    foreach ((array) ($audit['operationSummary'] ?? array()) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $source = (string) ($item['source'] ?? 'unknown');
        $positive = is_numeric($item['positiveElementDelta'] ?? null) ? (int) $item['positiveElementDelta'] : 0;
        $interesting = is_numeric($item['interestingCalls'] ?? null) ? (int) $item['interestingCalls'] : 0;
        $visual = ultracache_visual_audit_is_visual_identity($source) || $interesting > 0;
        if ($positive <= 0 && !$visual) {
            continue;
        }
        $rows[] = array(
            'source' => $source,
            'calls' => (int) ($item['calls'] ?? 0),
            'positiveElementDelta' => $positive,
            'interestingCalls' => $interesting,
            'visualIdentity' => $visual,
        );
    }

    usort($rows, static function (array $a, array $b): int {
        if ($b['positiveElementDelta'] !== $a['positiveElementDelta']) {
            return $b['positiveElementDelta'] <=> $a['positiveElementDelta'];
        }
        return $b['interestingCalls'] <=> $a['interestingCalls'];
    });

    return $rows;
}

function ultracache_visual_audit_decision(array $audit): array
{
    $executions = ultracache_visual_audit_execution_rows($audit);
    $operations = ultracache_visual_audit_operation_rows($audit);
    $culprits = array_values(array_filter($executions, static function (array $row): bool {
        return $row['visualIdentity'] && ($row['domDelta'] >= 20 || $row['score'] >= 40);
    }));

    $largest_dom_delta = 0;
    foreach ($executions as $row) {
        $largest_dom_delta = max($largest_dom_delta, (int) $row['domDelta']);
    }

    return array(
        'hasEvidenceForProductionVisualFix' => !empty($culprits),
        'culprits' => array_slice($culprits, 0, 10),
        'executions' => $executions,
        'operationSources' => $operations,
        'largestSingleScriptDomDelta' => $largest_dom_delta,
        'droppedOperations' => (int) ($audit['meta']['droppedOperations'] ?? 0),
    );
}

function ultracache_visual_audit_print(array $decision): void
{
    echo "UltraCache visual-init attribution decision\n";
    echo "=========================================\n";
    echo 'Production visual fix evidence: ' . ($decision['hasEvidenceForProductionVisualFix'] ? 'YES' : 'NO') . "\n";
    echo 'Largest single-script DOM delta: ' . (int) $decision['largestSingleScriptDomDelta'] . "\n";
    echo 'Dropped instrumented operations: ' . (int) $decision['droppedOperations'] . "\n\n";

    echo "Top delayed-script executions\n";
    echo "-----------------------------\n";
    foreach (array_slice($decision['executions'], 0, 20) as $row) {
        printf(
            "score=%d dom=%+d lane=%s visual=%s duration=%s handle=%s src=%s\n",
            (int) $row['score'],
            (int) $row['domDelta'],
            (string) $row['lane'],
            $row['visualIdentity'] ? 'yes' : 'no',
            null === $row['durationMs'] ? 'n/a' : number_format((float) $row['durationMs'], 2, '.', '') . 'ms',
            '' !== $row['handle'] ? $row['handle'] : '-',
            '' !== $row['src'] ? $row['src'] : '-'
        );
    }

    echo "\nTop synchronous DOM sources\n";
    echo "---------------------------\n";
    foreach (array_slice($decision['operationSources'], 0, 20) as $row) {
        printf(
            "dom=%+d calls=%d interesting=%d visual=%s source=%s\n",
            (int) $row['positiveElementDelta'],
            (int) $row['calls'],
            (int) $row['interestingCalls'],
            $row['visualIdentity'] ? 'yes' : 'no',
            (string) $row['source']
        );
    }

    echo "\nDecision\n";
    echo "--------\n";
    if (!$decision['hasEvidenceForProductionVisualFix']) {
        echo "No source-proven visual-init culprit crossed the evidence threshold. Do not add a generic Swiper/Slider/Elementor scheduler or CSS workaround.\n";
        return;
    }

    echo "Source-proven visual-init candidates exist. Patch only the demonstrated handle/src/lane behavior and re-run the controlled A/B before broadening classification.\n";
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        if (empty($argv[1])) {
            throw new InvalidArgumentException('Usage: php tests/manual/analyze-dom-runtime-attribution.php dom-runtime-audit.json');
        }
        $audit = ultracache_visual_audit_read((string) $argv[1]);
        ultracache_visual_audit_print(ultracache_visual_audit_decision($audit));
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
