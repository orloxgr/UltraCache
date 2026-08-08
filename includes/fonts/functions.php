<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Conservative CSS minifier for generated UltraCache CSS assets.
 * Keeps CSS semantics intact; intended for generated/cache copies, not source files.
 */
function ultracache_css_minify_preserve_strings($css)
{
    $css = (string) $css;
    if ('' === $css) {
        return '';
    }

    $css = str_replace(array("\r\n", "\r"), "\n", $css);
    $css = preg_replace('#/\*(?!\!)[\s\S]*?\*/#', '', $css);
    if (!is_string($css)) {
        return '';
    }

    $tokens = array();
    $css = preg_replace_callback(
        '/("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')/s',
        static function ($matches) use (&$tokens) {
            $key = '___ULTRACACHE_CSS_STRING_' . count($tokens) . '___';
            $tokens[$key] = $matches[0];
            return $key;
        },
        $css
    );
    if (!is_string($css)) {
        return '';
    }

    $css = preg_replace('/\s+/', ' ', $css);
    $css = preg_replace('/\s*([{}:;,>~+])\s*/', '$1', (string) $css);
    $css = preg_replace('/;}/', '}', (string) $css);
    $css = trim((string) $css);

    foreach ($tokens as $key => $value) {
        $css = str_replace($key, $value, $css);
    }

    return $css;
}

/**
 * Scan CSS for complete @font-face blocks while respecting comments, strings,
 * escapes, and balanced braces. Malformed structural input is reported so
 * callers can leave third-party CSS unchanged.
 *
 * @return array{blocks:array<int,string>,ranges:array<int,array<string,int>>,malformed:bool,errorOffset:int}
 */
function ultracache_css_scan_font_face_blocks($css)
{
    $css = (string) $css;
    $result = array(
        'blocks' => array(),
        'ranges' => array(),
        'malformed' => false,
        'errorOffset' => -1,
    );

    if ('' === $css || false === stripos($css, '@font-face')) {
        return $result;
    }

    $len = strlen($css);
    $keyword = '@font-face';
    $keyword_len = strlen($keyword);
    $i = 0;

    while ($i < $len) {
        $ch = $css[$i];

        if ('/' === $ch && $i + 1 < $len && '*' === $css[$i + 1]) {
            $comment_end = strpos($css, '*/', $i + 2);
            if (false === $comment_end) {
                $result['malformed'] = true;
                $result['errorOffset'] = $i;
                return $result;
            }
            $i = $comment_end + 2;
            continue;
        }

        if ('"' === $ch || "'" === $ch) {
            $quote = $ch;
            $quote_start = $i;
            $i++;
            $closed = false;
            while ($i < $len) {
                if ('\\' === $css[$i] && $i + 1 < $len) {
                    $i += 2;
                    continue;
                }
                if ($quote === $css[$i]) {
                    $i++;
                    $closed = true;
                    break;
                }
                $i++;
            }
            if (!$closed) {
                $result['malformed'] = true;
                $result['errorOffset'] = $quote_start;
                return $result;
            }
            continue;
        }

        if ('\\' === $ch && $i + 1 < $len) {
            $i += 2;
            continue;
        }

        if ('@' !== $ch || 0 !== strncasecmp(substr($css, $i, $keyword_len), $keyword, $keyword_len)) {
            $i++;
            continue;
        }

        $previous = $i > 0 ? $css[$i - 1] : '';
        $after_index = $i + $keyword_len;
        $after = $after_index < $len ? $css[$after_index] : '';
        if (('' !== $previous && preg_match('/[A-Za-z0-9_\\-]/', $previous))
            || ('' !== $after && preg_match('/[A-Za-z0-9_\\-]/', $after))) {
            $i++;
            continue;
        }

        $cursor = $after_index;
        while ($cursor < $len) {
            if (preg_match('/\\s/', $css[$cursor])) {
                $cursor++;
                continue;
            }
            if ('/' === $css[$cursor] && $cursor + 1 < $len && '*' === $css[$cursor + 1]) {
                $comment_end = strpos($css, '*/', $cursor + 2);
                if (false === $comment_end) {
                    $result['malformed'] = true;
                    $result['errorOffset'] = $cursor;
                    return $result;
                }
                $cursor = $comment_end + 2;
                continue;
            }
            break;
        }

        if ($cursor >= $len || '{' !== $css[$cursor]) {
            $i++;
            continue;
        }

        $start = $i;
        $open_brace = $cursor;
        $depth = 0;
        $j = $open_brace;
        $end = null;
        while ($j < $len) {
            $block_ch = $css[$j];

            if ('/' === $block_ch && $j + 1 < $len && '*' === $css[$j + 1]) {
                $comment_end = strpos($css, '*/', $j + 2);
                if (false === $comment_end) {
                    $result['malformed'] = true;
                    $result['errorOffset'] = $j;
                    return $result;
                }
                $j = $comment_end + 2;
                continue;
            }

            if ('"' === $block_ch || "'" === $block_ch) {
                $quote = $block_ch;
                $quote_start = $j;
                $j++;
                $closed = false;
                while ($j < $len) {
                    if ('\\' === $css[$j] && $j + 1 < $len) {
                        $j += 2;
                        continue;
                    }
                    if ($quote === $css[$j]) {
                        $j++;
                        $closed = true;
                        break;
                    }
                    $j++;
                }
                if (!$closed) {
                    $result['malformed'] = true;
                    $result['errorOffset'] = $quote_start;
                    return $result;
                }
                continue;
            }

            if ('\\' === $block_ch && $j + 1 < $len) {
                $j += 2;
                continue;
            }

            if ('{' === $block_ch) {
                $depth++;
                $j++;
                continue;
            }

            if ('}' === $block_ch) {
                $depth--;
                $j++;
                if (0 === $depth) {
                    $end = $j;
                    break;
                }
                if ($depth < 0) {
                    $result['malformed'] = true;
                    $result['errorOffset'] = $j - 1;
                    return $result;
                }
                continue;
            }

            $j++;
        }

        if (null === $end) {
            $result['malformed'] = true;
            $result['errorOffset'] = $start;
            return $result;
        }

        $block = substr($css, $start, $end - $start);
        $result['blocks'][] = $block;
        $result['ranges'][] = array(
            'start' => $start,
            'length' => $end - $start,
            'openBrace' => $open_brace,
            'closeBrace' => $end - 1,
        );
        $i = $end;
    }

    return $result;
}

/**
 * Rewrite complete @font-face blocks without reconstructing untouched CSS.
 * Malformed CSS is returned byte-for-byte unchanged.
 */
function ultracache_css_rewrite_font_face_blocks($css, callable $callback)
{
    $css = (string) $css;
    $scan = ultracache_css_scan_font_face_blocks($css);
    if (!empty($scan['malformed']) || empty($scan['ranges'])) {
        return $css;
    }

    $output = '';
    $cursor = 0;
    $changed = false;
    foreach ($scan['ranges'] as $index => $range) {
        $start = (int) $range['start'];
        $length = (int) $range['length'];
        $block = substr($css, $start, $length);
        $replacement = $callback($block, (int) $index, $range);
        if (!is_string($replacement)) {
            $replacement = $block;
        }
        $output .= substr($css, $cursor, $start - $cursor) . $replacement;
        $cursor = $start + $length;
        if ($replacement !== $block) {
            $changed = true;
        }
    }
    $output .= substr($css, $cursor);

    return $changed ? $output : $css;
}

/**
 * Remove CSS comments while preserving quoted strings and escapes.
 */
function ultracache_css_strip_comments_preserve_strings($css)
{
    $css = (string) $css;
    $len = strlen($css);
    $out = '';
    $i = 0;
    while ($i < $len) {
        $ch = $css[$i];
        if ('/' === $ch && $i + 1 < $len && '*' === $css[$i + 1]) {
            $end = strpos($css, '*/', $i + 2);
            if (false === $end) {
                return $css;
            }
            $i = $end + 2;
            continue;
        }
        if ('"' === $ch || "'" === $ch) {
            $quote = $ch;
            $out .= $ch;
            $i++;
            while ($i < $len) {
                $out .= $css[$i];
                if ('\\' === $css[$i] && $i + 1 < $len) {
                    $i++;
                    $out .= $css[$i];
                    $i++;
                    continue;
                }
                if ($quote === $css[$i]) {
                    $i++;
                    break;
                }
                $i++;
            }
            continue;
        }
        $out .= $ch;
        $i++;
    }
    return $out;
}

/**
 * Parse top-level declarations inside one complete @font-face block.
 *
 * @return array{declarations:array<int,array<string,mixed>>,malformed:bool,closeBrace:int}
 */
function ultracache_font_css_scan_declarations($block)
{
    $block = (string) $block;
    $scan = ultracache_css_scan_font_face_blocks($block);
    if (!empty($scan['malformed']) || 1 !== count($scan['ranges']) || 0 !== (int) $scan['ranges'][0]['start'] || strlen($block) !== (int) $scan['ranges'][0]['length']) {
        return array('declarations' => array(), 'malformed' => true, 'closeBrace' => -1);
    }

    $open = (int) $scan['ranges'][0]['openBrace'];
    $close = (int) $scan['ranges'][0]['closeBrace'];
    $declarations = array();
    $segment_start = $open + 1;
    $colon = null;
    $paren_depth = 0;
    $bracket_depth = 0;
    $i = $segment_start;

    $consume_segment = static function ($segment_end, $has_semicolon) use (&$declarations, &$colon, &$segment_start, $block) {
        $raw = substr($block, $segment_start, $segment_end - $segment_start);
        $plain = trim(ultracache_css_strip_comments_preserve_strings($raw));
        if ('' === $plain) {
            $segment_start = $segment_end + ($has_semicolon ? 1 : 0);
            $colon = null;
            return true;
        }
        if (null === $colon || $colon < $segment_start || $colon >= $segment_end) {
            return false;
        }
        $property_raw = substr($block, $segment_start, $colon - $segment_start);
        $property = strtolower(trim(ultracache_css_strip_comments_preserve_strings($property_raw)));
        if (!preg_match('/^-?[A-Za-z_][A-Za-z0-9_\\-]*$/', $property)) {
            return false;
        }
        $value_start = $colon + 1;
        $value_end = $segment_end;
        while ($value_start < $value_end && preg_match('/\\s/', $block[$value_start])) {
            $value_start++;
        }
        while ($value_end > $value_start && preg_match('/\\s/', $block[$value_end - 1])) {
            $value_end--;
        }
        $declarations[] = array(
            'property' => $property,
            'start' => $segment_start,
            'end' => $segment_end + ($has_semicolon ? 1 : 0),
            'valueStart' => $value_start,
            'valueEnd' => $value_end,
            'hasSemicolon' => (bool) $has_semicolon,
        );
        $segment_start = $segment_end + ($has_semicolon ? 1 : 0);
        $colon = null;
        return true;
    };

    while ($i < $close) {
        $ch = $block[$i];
        if ('/' === $ch && $i + 1 < $close && '*' === $block[$i + 1]) {
            $end = strpos($block, '*/', $i + 2);
            if (false === $end || $end >= $close) {
                return array('declarations' => array(), 'malformed' => true, 'closeBrace' => $close);
            }
            $i = $end + 2;
            continue;
        }
        if ('"' === $ch || "'" === $ch) {
            $quote = $ch;
            $i++;
            $closed = false;
            while ($i < $close) {
                if ('\\' === $block[$i] && $i + 1 < $close) {
                    $i += 2;
                    continue;
                }
                if ($quote === $block[$i]) {
                    $i++;
                    $closed = true;
                    break;
                }
                $i++;
            }
            if (!$closed) {
                return array('declarations' => array(), 'malformed' => true, 'closeBrace' => $close);
            }
            continue;
        }
        if ('\\' === $ch && $i + 1 < $close) {
            $i += 2;
            continue;
        }
        if ('(' === $ch) {
            $paren_depth++;
            $i++;
            continue;
        }
        if (')' === $ch) {
            if (0 === $paren_depth) {
                return array('declarations' => array(), 'malformed' => true, 'closeBrace' => $close);
            }
            $paren_depth--;
            $i++;
            continue;
        }
        if ('[' === $ch) {
            $bracket_depth++;
            $i++;
            continue;
        }
        if (']' === $ch) {
            if (0 === $bracket_depth) {
                return array('declarations' => array(), 'malformed' => true, 'closeBrace' => $close);
            }
            $bracket_depth--;
            $i++;
            continue;
        }
        if (0 === $paren_depth && 0 === $bracket_depth) {
            if (':' === $ch) {
                if (null !== $colon) {
                    return array('declarations' => array(), 'malformed' => true, 'closeBrace' => $close);
                }
                $colon = $i;
                $i++;
                continue;
            }
            if (';' === $ch) {
                if (!$consume_segment($i, true)) {
                    return array('declarations' => array(), 'malformed' => true, 'closeBrace' => $close);
                }
                $i++;
                continue;
            }
            if ('{' === $ch || '}' === $ch) {
                return array('declarations' => array(), 'malformed' => true, 'closeBrace' => $close);
            }
        }
        $i++;
    }

    if (0 !== $paren_depth || 0 !== $bracket_depth || !$consume_segment($close, false)) {
        return array('declarations' => array(), 'malformed' => true, 'closeBrace' => $close);
    }

    return array('declarations' => $declarations, 'malformed' => false, 'closeBrace' => $close);
}

function ultracache_font_css_find_declaration($block, $property)
{
    $property = strtolower(trim((string) $property));
    $scan = ultracache_font_css_scan_declarations($block);
    if (!empty($scan['malformed'])) {
        return array();
    }
    foreach ($scan['declarations'] as $declaration) {
        if ($property === (string) $declaration['property']) {
            return $declaration;
        }
    }
    return array();
}

function ultracache_font_css_extract_declaration($block, $property)
{
    $declaration = ultracache_font_css_find_declaration((string) $block, (string) $property);
    if (empty($declaration)) {
        return '';
    }
    $value = substr((string) $block, (int) $declaration['valueStart'], (int) $declaration['valueEnd'] - (int) $declaration['valueStart']);
    return trim(ultracache_css_strip_comments_preserve_strings($value));
}

function ultracache_font_css_replace_declaration_value($block, $property, $value)
{
    $block = (string) $block;
    $declaration = ultracache_font_css_find_declaration($block, $property);
    if (empty($declaration)) {
        return $block;
    }
    $start = (int) $declaration['valueStart'];
    $length = (int) $declaration['valueEnd'] - $start;
    return substr_replace($block, (string) $value, $start, $length);
}

function ultracache_font_css_append_declaration($block, $declaration)
{
    $block = (string) $block;
    $scan = ultracache_font_css_scan_declarations($block);
    if (!empty($scan['malformed']) || (int) $scan['closeBrace'] < 0) {
        return $block;
    }

    $close = (int) $scan['closeBrace'];
    $before = substr($block, 0, $close);
    $trimmed = rtrim($before);
    $trailing = substr($before, strlen($trimmed));
    $declarations = isset($scan['declarations']) && is_array($scan['declarations']) ? $scan['declarations'] : array();
    $separator = '';
    if (!empty($declarations)) {
        $last_declaration = $declarations[count($declarations) - 1];
        $separator = !empty($last_declaration['hasSemicolon']) ? '' : ';';
    }
    $prefix = false !== strpos($block, "\n") ? "\n  " : '';

    return $trimmed . $separator . $prefix . trim((string) $declaration) . $trailing . substr($block, $close);
}

function ultracache_font_css_split_src_items($src)
{
    $items = array();
    $current = '';
    $depth = 0;
    $quote = '';
    $len = strlen((string) $src);
    for ($i = 0; $i < $len; $i++) {
        $ch = $src[$i];
        if ('' !== $quote) {
            $current .= $ch;
            if ('\\' === $ch && $i + 1 < $len) {
                $i++;
                $current .= $src[$i];
                continue;
            }
            if ($ch === $quote) {
                $quote = '';
            }
            continue;
        }
        if ('"' === $ch || "'" === $ch) {
            $quote = $ch;
            $current .= $ch;
            continue;
        }
        if ('(' === $ch) {
            $depth++;
            $current .= $ch;
            continue;
        }
        if (')' === $ch) {
            $depth = max(0, $depth - 1);
            $current .= $ch;
            continue;
        }
        if (',' === $ch && 0 === $depth) {
            $trimmed = trim($current);
            if ('' !== $trimmed) {
                $items[] = $trimmed;
            }
            $current = '';
            continue;
        }
        $current .= $ch;
    }
    $trimmed = trim($current);
    if ('' !== $trimmed) {
        $items[] = $trimmed;
    }
    return $items;
}

function ultracache_optimize_font_face_block($block, array &$stats)
{
    foreach (array('fontDisplayAdded', 'duplicateSrcRemoved', 'ttfSourcesRemoved', 'fontFaceBlocksChanged') as $key) {
        if (!isset($stats[$key])) {
            $stats[$key] = 0;
        }
    }

    $block = (string) $block;
    $original = $block;
    $declaration_scan = ultracache_font_css_scan_declarations($block);
    if (!empty($declaration_scan['malformed'])) {
        return $block;
    }

    $display = ultracache_font_css_find_declaration($block, 'font-display');
    if (!empty($display)) {
        $block = ultracache_font_css_replace_declaration_value($block, 'font-display', 'swap');
    } else {
        $updated = ultracache_font_css_append_declaration($block, 'font-display:swap;');
        if ($updated !== $block) {
            $block = $updated;
            $stats['fontDisplayAdded']++;
        }
    }

    $src = ultracache_font_css_extract_declaration($block, 'src');
    if ('' !== $src) {
        $items = ultracache_font_css_split_src_items($src);
        if (!empty($items)) {
            $seen = array();
            $kept = array();
            foreach ($items as $item) {
                $item = trim((string) $item);
                if ('' === $item) {
                    continue;
                }

                $item_key = strtolower((string) preg_replace('/\s+/', '', $item));
                if (isset($seen[$item_key])) {
                    $stats['duplicateSrcRemoved']++;
                    continue;
                }

                $seen[$item_key] = true;
                $kept[] = $item;
            }

            if (!empty($kept)) {
                $block = ultracache_font_css_replace_declaration_value($block, 'src', implode(',', $kept));
            }
        }
    }

    $minified = ultracache_css_minify_preserve_strings($block);
    if ('' !== $minified) {
        $block = $minified;
    }

    if ($block !== $original) {
        $stats['fontFaceBlocksChanged']++;
    }

    return $block;
}

/**
 * Extract top-level @font-face blocks and report whether meaningful non-font CSS remains.
 * Generated /font-css/ assets must remain font-only; mixed layout CSS is skipped by caller.
 */
function ultracache_extract_font_face_blocks_from_css($css)
{
    $css = (string) $css;
    $scan = ultracache_css_scan_font_face_blocks($css);
    if (!empty($scan['malformed'])) {
        $trimmed = trim($css);
        return array(
            'blocks' => array(),
            'remaining' => $css,
            'nonFontBytes' => strlen($trimmed),
            'hasNonFontCss' => '' !== $trimmed,
            'malformed' => true,
        );
    }

    $blocks = isset($scan['blocks']) && is_array($scan['blocks']) ? $scan['blocks'] : array();
    $remaining = $css;
    foreach (array_reverse((array) $scan['ranges']) as $range) {
        $start = (int) $range['start'];
        $length = (int) $range['length'];
        $remaining = substr($remaining, 0, $start) . str_repeat(' ', $length) . substr($remaining, $start + $length);
    }

    $non_font = preg_replace('#/\*(?!\!)[\s\S]*?\*/#', '', $remaining);
    if (!is_string($non_font)) {
        $non_font = $remaining;
    }
    $non_font = preg_replace('/@charset\s+[^;]+;/i', '', $non_font);
    $non_font = preg_replace('/@import\s+[^;]+;/i', '', (string) $non_font);
    if (!is_string($non_font)) {
        $non_font = $remaining;
    }
    $non_font_trimmed = trim($non_font);

    return array(
        'blocks' => $blocks,
        'remaining' => $remaining,
        'nonFontBytes' => strlen($non_font_trimmed),
        'hasNonFontCss' => '' !== $non_font_trimmed,
        'malformed' => false,
    );
}

/**
 * Optimize generated local font CSS copies.
 *
 * Generated /font-css/ assets should contain font CSS only. If the source stylesheet
 * is mixed layout/theme CSS, the caller should keep the original stylesheet instead
 * of copying that whole stylesheet into /font-css/.
 *
 * @return array{css:string,stats:array}
 */
function ultracache_optimize_generated_font_css($css, $source_url = '')
{
    $css = (string) $css;
    $stats = array(
        'sourceUrl' => (string) $source_url,
        'beforeBytes' => strlen($css),
        'afterBytes' => strlen($css),
        'fontFaceBlocksBefore' => 0,
        'fontFaceBlocksAfter' => 0,
        'fontFaceBlocksRemoved' => 0,
        'fontFaceBlocksChanged' => 0,
        'duplicateSrcRemoved' => 0,
        'ttfSourcesRemoved' => 0,
        'fontDisplayAdded' => 0,
        'minifyBytesSaved' => 0,
        'fontFaceOnlyExtraction' => true,
        'nonFontCssDetected' => false,
        'nonFontCssBytes' => 0,
        'skippedNonFontSource' => false,
    );

    if ('' === $css) {
        $stats['afterBytes'] = 0;
        return array('css' => '', 'stats' => $stats);
    }

    $extracted = ultracache_extract_font_face_blocks_from_css($css);
    $blocks = isset($extracted['blocks']) && is_array($extracted['blocks']) ? $extracted['blocks'] : array();
    $stats['fontFaceBlocksBefore'] = count($blocks);
    $stats['nonFontCssBytes'] = max(0, (int) ($extracted['nonFontBytes'] ?? 0));
    $stats['nonFontCssDetected'] = !empty($extracted['hasNonFontCss']);

    if (empty($blocks)) {
        $stats['afterBytes'] = 0;
        if (!empty($stats['nonFontCssDetected'])) {
            $stats['skippedNonFontSource'] = true;
        }
        return array('css' => '', 'stats' => $stats);
    }

    $seen_blocks = array();
    $optimized_blocks = array();
    foreach ($blocks as $block) {
        $block = ultracache_optimize_font_face_block((string) $block, $stats);
        $key = strtolower(preg_replace('/\s+/', '', $block));
        if (isset($seen_blocks[$key])) {
            $stats['fontFaceBlocksRemoved']++;
            continue;
        }
        $seen_blocks[$key] = true;
        $optimized_blocks[] = $block;
    }

    $optimized = implode("\n", $optimized_blocks);
    $optimized = ultracache_css_minify_preserve_strings($optimized);
    if ('' === $optimized && !empty($optimized_blocks)) {
        $optimized = trim(implode("\n", $optimized_blocks));
    }

    $stats['fontFaceBlocksAfter'] = count($optimized_blocks);
    $stats['afterBytes'] = strlen((string) $optimized);
    $stats['minifyBytesSaved'] = max(0, (int) $stats['beforeBytes'] - (int) $stats['afterBytes']);

    return array('css' => (string) $optimized . ('' !== $optimized ? "\n" : ''), 'stats' => $stats);
}



