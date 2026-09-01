<?php
/**
 * UltraCache forced-reflow source mapper.
 * Development/manual diagnostic only; never loaded by the plugin runtime.
 *
 * Usage:
 *   php tests/manual/analyze-forced-reflow-source.php lighthouse.json served.html [--document-url=https://example.test/page]
 */

declare(strict_types=1);

function ultracache_reflow_read_json(string $file): array
{
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('Cannot read Lighthouse JSON: ' . $file);
    }
    $raw = file_get_contents($file);
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid Lighthouse JSON: ' . $file);
    }
    return $data;
}

function ultracache_reflow_read_html(string $file): string
{
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('Cannot read served HTML: ' . $file);
    }
    $html = file_get_contents($file);
    if (!is_string($html) || '' === $html) {
        throw new RuntimeException('Served HTML is empty: ' . $file);
    }
    return $html;
}

function ultracache_reflow_line_from_offset(string $text, int $offset): int
{
    if ($offset <= 0) {
        return 1;
    }
    return substr_count(substr($text, 0, $offset), "\n") + 1;
}

function ultracache_reflow_parse_attrs(string $tag): array
{
    $attrs = array();
    if (!preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $tag, $matches, PREG_SET_ORDER)) {
        return $attrs;
    }
    foreach ($matches as $match) {
        $name = strtolower((string) $match[1]);
        $value = '';
        if (isset($match[2]) && '' !== $match[2]) {
            $value = $match[2];
        } elseif (isset($match[3]) && '' !== $match[3]) {
            $value = $match[3];
        } elseif (isset($match[4])) {
            $value = $match[4];
        }
        $attrs[$name] = $value;
    }
    return $attrs;
}

function ultracache_reflow_owner_from_script(array $attrs, string $body): array
{
    $id = strtolower((string) ($attrs['id'] ?? ''));
    $src = strtolower((string) ($attrs['src'] ?? ''));
    $haystack = strtolower($id . ' ' . $src . ' ' . substr($body, 0, 6000));

    $rules = array(
        'ultracache' => array('/ultracache/', 'data-ultracache', '__ultracache', 'ultracache_'),
        'complianz' => array('complianz', 'cmplz_','cmplz-'),
        'real-cookie-banner' => array('real-cookie-banner', 'devowl', 'rcb/consent', 'rcb-'),
        'google-site-kit' => array('googlesitekit', 'google-site-kit'),
        'google-tag-manager/analytics' => array('googletagmanager.com', 'google-analytics.com', "gtag('", 'gtag("', 'dataLayer'),
        'woocommerce' => array('woocommerce', 'wc-settings', 'wc_add_to_cart', 'wc-cart-fragments'),
        'elementor' => array('elementor'),
        'slider-revolution' => array('revslider', 'revolution', 'tptools', 'sr7'),
        'swiper' => array('swiper'),
    );

    foreach ($rules as $owner => $needles) {
        foreach ($needles as $needle) {
            if (false !== strpos($haystack, strtolower($needle))) {
                $confidence = ('' !== $id && false !== strpos($id, strtolower($needle))) || ('' !== $src && false !== strpos($src, strtolower($needle))) ? 'high' : 'medium';
                return array('owner' => $owner, 'confidence' => $confidence, 'evidence' => $needle);
            }
        }
    }

    if ('' !== $id) {
        return array('owner' => 'unknown', 'confidence' => 'low', 'evidence' => 'script-id:' . $id);
    }

    return array('owner' => 'unknown', 'confidence' => 'none', 'evidence' => '');
}

function ultracache_reflow_inline_scripts(string $html): array
{
    $scripts = array();
    if (!preg_match_all('/<script\b[^>]*>.*?<\/script\s*>/is', $html, $matches, PREG_OFFSET_CAPTURE)) {
        return $scripts;
    }

    foreach ($matches[0] as $index => $capture) {
        $full = (string) $capture[0];
        $offset = (int) $capture[1];
        if (!preg_match('/^<script\b([^>]*)>(.*)<\/script\s*>$/is', $full, $parts)) {
            continue;
        }
        $open_tag = '<script' . (string) $parts[1] . '>';
        $body = (string) $parts[2];
        $attrs = ultracache_reflow_parse_attrs($open_tag);
        $start_line = ultracache_reflow_line_from_offset($html, $offset);
        $end_line = $start_line + substr_count($full, "\n");
        $body_line = $start_line + substr_count($open_tag, "\n");
        $owner = ultracache_reflow_owner_from_script($attrs, $body);

        $snippet = trim(preg_replace('/\s+/', ' ', $body));
        if (strlen($snippet) > 240) {
            $snippet = substr($snippet, 0, 237) . '...';
        }

        $scripts[] = array(
            'index' => $index,
            'startLine' => $start_line,
            'bodyStartLine' => $body_line,
            'endLine' => $end_line,
            'id' => (string) ($attrs['id'] ?? ''),
            'type' => (string) ($attrs['type'] ?? ''),
            'src' => (string) ($attrs['src'] ?? ''),
            'inline' => empty($attrs['src']),
            'owner' => $owner['owner'],
            'ownerConfidence' => $owner['confidence'],
            'ownerEvidence' => $owner['evidence'],
            'snippet' => $snippet,
        );
    }

    return $scripts;
}

function ultracache_reflow_location_from_item(array $item): ?array
{
    $url = '';
    foreach (array('url', 'scriptUrl', 'scriptURL') as $key) {
        if (isset($item[$key]) && is_string($item[$key]) && '' !== trim($item[$key])) {
            $url = trim($item[$key]);
            break;
        }
    }

    $line = null;
    $column = null;
    foreach (array('lineNumber', 'line', 'lineNo') as $key) {
        if (isset($item[$key]) && is_numeric($item[$key])) {
            $line = (int) $item[$key];
            break;
        }
    }
    foreach (array('columnNumber', 'column', 'columnNo') as $key) {
        if (isset($item[$key]) && is_numeric($item[$key])) {
            $column = (int) $item[$key];
            break;
        }
    }

    foreach (array('source', 'location') as $key) {
        if (!isset($item[$key]) || !is_string($item[$key])) {
            continue;
        }
        $source = trim($item[$key]);
        if (preg_match('/^(.*?):(\d+)(?::(\d+))?$/', $source, $m)) {
            if ('' === $url) {
                $url = trim($m[1]);
            }
            if (null === $line) {
                $line = (int) $m[2];
            }
            if (null === $column && isset($m[3]) && '' !== $m[3]) {
                $column = (int) $m[3];
            }
            break;
        }
    }

    if (null === $line && '' === $url) {
        return null;
    }

    $duration = null;
    foreach (array('duration', 'wastedMs', 'total', 'timing') as $key) {
        if (isset($item[$key]) && is_numeric($item[$key])) {
            $duration = (float) $item[$key];
            break;
        }
    }

    return array('url' => $url, 'line' => $line, 'column' => $column, 'durationMs' => $duration);
}

function ultracache_reflow_collect_locations_recursive($node, string $path, array &$rows): void
{
    if (!is_array($node)) {
        return;
    }

    $location = ultracache_reflow_location_from_item($node);
    if (null !== $location) {
        $location['path'] = $path;
        $rows[] = $location;
    }

    foreach ($node as $key => $value) {
        if (is_array($value)) {
            ultracache_reflow_collect_locations_recursive($value, $path . '/' . (string) $key, $rows);
        }
    }
}

function ultracache_reflow_extract_locations(array $report): array
{
    $rows = array();
    foreach ((array) ($report['audits'] ?? array()) as $audit_id => $audit) {
        $id = strtolower((string) $audit_id);
        if (false === strpos($id, 'reflow') && false === strpos($id, 'layout')) {
            continue;
        }
        ultracache_reflow_collect_locations_recursive($audit, 'audits/' . $audit_id, $rows);
    }

    $unique = array();
    foreach ($rows as $row) {
        $key = implode('|', array((string) $row['url'], (string) $row['line'], (string) $row['column'], (string) $row['durationMs']));
        if (!isset($unique[$key])) {
            $unique[$key] = $row;
        }
    }
    return array_values($unique);
}

function ultracache_reflow_url_is_document(string $url, string $document_url = ''): bool
{
    $url_lc = strtolower(trim($url));
    if ('' === $url_lc || '(index)' === $url_lc || '(document)' === $url_lc || 'index' === $url_lc) {
        return true;
    }
    if ('' !== $document_url) {
        $left = rtrim(preg_replace('/#.*$/', '', $url_lc), '/');
        $right = rtrim(strtolower(preg_replace('/#.*$/', '', $document_url)), '/');
        if ($left === $right) {
            return true;
        }
    }
    return false;
}

function ultracache_reflow_script_for_line(array $scripts, int $reported_line): ?array
{
    // Lighthouse/trace locations can be zero-based while DevTools display is one-based.
    // Try the literal display line first, then +1 as protocol-style fallback.
    foreach (array($reported_line, $reported_line + 1) as $candidate_line) {
        if ($candidate_line < 1) {
            continue;
        }
        foreach ($scripts as $script) {
            if ($candidate_line >= $script['startLine'] && $candidate_line <= $script['endLine']) {
                $script['matchedLine'] = $candidate_line;
                $script['lineBasis'] = ($candidate_line === $reported_line) ? 'reported-as-one-based' : 'reported-as-zero-based';
                return $script;
            }
        }
    }
    return null;
}

function ultracache_reflow_analyze(array $report, string $html, string $document_url = ''): array
{
    $scripts = ultracache_reflow_inline_scripts($html);
    $locations = ultracache_reflow_extract_locations($report);
    $mapped = array();

    foreach ($locations as $location) {
        $row = $location;
        $row['documentSource'] = ultracache_reflow_url_is_document((string) $location['url'], $document_url);
        $row['script'] = null;
        if ($row['documentSource'] && is_int($location['line'])) {
            $row['script'] = ultracache_reflow_script_for_line($scripts, $location['line']);
        }
        $mapped[] = $row;
    }

    usort($mapped, static function (array $a, array $b): int {
        $ad = is_numeric($a['durationMs']) ? (float) $a['durationMs'] : -1.0;
        $bd = is_numeric($b['durationMs']) ? (float) $b['durationMs'] : -1.0;
        return $bd <=> $ad;
    });

    return array('locations' => $mapped, 'scripts' => $scripts);
}

function ultracache_reflow_print(array $analysis): void
{
    echo "UltraCache forced-reflow source map\n";
    echo "===================================\n";
    echo 'Inline scripts indexed: ' . count($analysis['scripts']) . "\n";
    echo 'Reflow/layout source locations: ' . count($analysis['locations']) . "\n\n";

    if (!$analysis['locations']) {
        echo "No source locations were exposed by the Lighthouse reflow/layout audits. Export a full Lighthouse JSON or Chrome Performance trace with source locations.\n";
        return;
    }

    foreach ($analysis['locations'] as $i => $row) {
        $duration = is_numeric($row['durationMs']) ? number_format((float) $row['durationMs'], 2, '.', '') . 'ms' : 'n/a';
        $line = null === $row['line'] ? 'n/a' : (string) $row['line'];
        $column = null === $row['column'] ? 'n/a' : (string) $row['column'];
        echo sprintf("[%d] duration=%s source=%s:%s:%s\n", $i + 1, $duration, '' !== $row['url'] ? $row['url'] : '(document)', $line, $column);

        if (!$row['documentSource']) {
            echo "    External source; no inline HTML mapping attempted.\n";
            continue;
        }

        if (!is_array($row['script'])) {
            echo "    Document source, but no <script> block contains the reported line. Do not infer ownership.\n";
            continue;
        }

        $script = $row['script'];
        echo sprintf(
            "    inline-script lines=%d-%d id=%s owner=%s confidence=%s basis=%s\n",
            $script['startLine'],
            $script['endLine'],
            '' !== $script['id'] ? $script['id'] : '-',
            $script['owner'],
            $script['ownerConfidence'],
            $script['lineBasis']
        );
        if ('' !== $script['ownerEvidence']) {
            echo '    evidence=' . $script['ownerEvidence'] . "\n";
        }
        echo '    snippet=' . ($script['snippet'] ?: '[empty]') . "\n";
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        if (empty($argv[1]) || empty($argv[2])) {
            throw new InvalidArgumentException('Usage: php tests/manual/analyze-forced-reflow-source.php lighthouse.json served.html [--document-url=https://example.test/page]');
        }
        $document_url = '';
        foreach (array_slice($argv, 3) as $arg) {
            if (0 === strpos((string) $arg, '--document-url=')) {
                $document_url = substr((string) $arg, strlen('--document-url='));
            }
        }
        $report = ultracache_reflow_read_json((string) $argv[1]);
        $html = ultracache_reflow_read_html((string) $argv[2]);
        ultracache_reflow_print(ultracache_reflow_analyze($report, $html, $document_url));
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
