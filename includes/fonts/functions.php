<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('ucwp_css_minify_preserve_strings')) {
    /**
     * Conservative CSS minifier for generated UltraCache CSS assets.
     * Keeps CSS semantics intact; intended for generated/cache copies, not source files.
     */
    function ucwp_css_minify_preserve_strings($css)
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
                $key = '___UCWP_CSS_STRING_' . count($tokens) . '___';
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
}

if (!function_exists('ucwp_font_css_extract_declaration')) {
    function ucwp_font_css_extract_declaration($block, $property)
    {
        $property = preg_quote((string) $property, '/');
        if (preg_match('/' . $property . '\s*:\s*([^;}]+)\s*;?/i', (string) $block, $matches)) {
            return trim((string) $matches[1]);
        }
        return '';
    }
}

if (!function_exists('ucwp_font_css_split_src_items')) {
    function ucwp_font_css_split_src_items($src)
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
}

if (!function_exists('ucwp_optimize_font_face_block')) {
    function ucwp_optimize_font_face_block($block, array &$stats)
    {
        $block = (string) $block;
        $original = $block;

        if (false !== stripos($block, 'font-display')) {
            $updated = preg_replace('/font-display\s*:\s*[^;}{]+;?/i', 'font-display: swap;', $block, 1);
            if (is_string($updated) && '' !== $updated) {
                $block = $updated;
            }
        } else {
            $body = (string) preg_replace('/}\s*$/', '', $block, 1);
            $body = rtrim($body);
            if ('' !== $body && '{' !== substr($body, -1) && ';' !== substr($body, -1)) {
                $body .= ';';
            }
            $block = $body . 'font-display:swap;}';
            $stats['fontDisplayAdded']++;
        }

        $block = (string) preg_replace_callback('/src\s*:\s*([^;}]+)\s*;?/i', static function ($matches) use (&$stats) {
            $src = (string) $matches[1];
            $items = ucwp_font_css_split_src_items($src);
            if (empty($items)) {
                return $matches[0];
            }

            $has_woff2 = false;
            foreach ($items as $item) {
                if (preg_match('/\.woff2(?:[\?#][^\"\'\)\s]*)?/i', $item) || preg_match('/format\(\s*[\"\']?woff2[\"\']?\s*\)/i', $item)) {
                    $has_woff2 = true;
                    break;
                }
            }

            $seen = array();
            $kept = array();
            foreach ($items as $item) {
                $item = trim((string) $item);
                if ('' === $item) {
                    continue;
                }

                $item_key = strtolower(preg_replace('/\s+/', '', $item));
                if (isset($seen[$item_key])) {
                    $stats['duplicateSrcRemoved']++;
                    continue;
                }

                if ($has_woff2 && (preg_match('/\.ttf(?:[\?#][^\"\'\)\s]*)?/i', $item) || preg_match('/format\(\s*[\"\']?truetype[\"\']?\s*\)/i', $item))) {
                    $stats['ttfSourcesRemoved']++;
                    continue;
                }

                $seen[$item_key] = true;
                $kept[] = $item;
            }

            if (empty($kept)) {
                $kept = $items;
            }

            return 'src:' . implode(',', $kept) . ';';
        }, $block);

        $minified = ucwp_css_minify_preserve_strings($block);
        if ('' !== $minified) {
            $block = $minified;
        }

        if ($block !== $original) {
            $stats['fontFaceBlocksChanged']++;
        }

        return $block;
    }
}

if (!function_exists('ucwp_extract_font_face_blocks_from_css')) {
    /**
     * Extract top-level @font-face blocks and report whether meaningful non-font CSS remains.
     * Generated /font-css/ assets must remain font-only; mixed layout CSS is skipped by caller.
     */
    function ucwp_extract_font_face_blocks_from_css($css)
    {
        $css = (string) $css;
        $blocks = array();
        $remaining = $css;

        if ('' === $css || false === stripos($css, '@font-face')) {
            $trimmed = trim($css);
            return array('blocks' => array(), 'remaining' => $css, 'nonFontBytes' => strlen($trimmed), 'hasNonFontCss' => '' !== $trimmed);
        }

        $offset = 0;
        $len = strlen($css);
        while ($offset < $len && preg_match('/@font-face\s*\{/i', $css, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = (int) $m[0][1];
            $brace = strpos($css, '{', $start);
            if (false === $brace) {
                break;
            }

            $depth = 0;
            $quote = '';
            $end = null;
            for ($i = $brace; $i < $len; $i++) {
                $ch = $css[$i];
                if ('' !== $quote) {
                    if ('\\' === $ch && $i + 1 < $len) {
                        $i++;
                        continue;
                    }
                    if ($ch === $quote) {
                        $quote = '';
                    }
                    continue;
                }
                if ('"' === $ch || "'" === $ch) {
                    $quote = $ch;
                    continue;
                }
                if ('{' === $ch) {
                    $depth++;
                    continue;
                }
                if ('}' === $ch) {
                    $depth--;
                    if ($depth <= 0) {
                        $end = $i + 1;
                        break;
                    }
                }
            }

            if (null === $end) {
                break;
            }

            $blocks[] = substr($css, $start, $end - $start);
            $remaining = substr($remaining, 0, $start) . str_repeat(' ', $end - $start) . substr($remaining, $end);
            $offset = $end;
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
        );
    }
}

if (!function_exists('ucwp_optimize_generated_font_css')) {
    /**
     * Optimize generated local font CSS copies.
     *
     * Generated /font-css/ assets should contain font CSS only. If the source stylesheet
     * is mixed layout/theme CSS, the caller should keep the original stylesheet instead
     * of copying that whole stylesheet into /font-css/.
     *
     * @return array{css:string,stats:array}
     */
    function ucwp_optimize_generated_font_css($css, $source_url = '')
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

        $extracted = ucwp_extract_font_face_blocks_from_css($css);
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
            $block = ucwp_optimize_font_face_block((string) $block, $stats);
            $key = strtolower(preg_replace('/\s+/', '', $block));
            if (isset($seen_blocks[$key])) {
                $stats['fontFaceBlocksRemoved']++;
                continue;
            }
            $seen_blocks[$key] = true;
            $optimized_blocks[] = $block;
        }

        $optimized = implode("\n", $optimized_blocks);
        $optimized = ucwp_css_minify_preserve_strings($optimized);
        if ('' === $optimized && !empty($optimized_blocks)) {
            $optimized = trim(implode("\n", $optimized_blocks));
        }

        $stats['fontFaceBlocksAfter'] = count($optimized_blocks);
        $stats['afterBytes'] = strlen((string) $optimized);
        $stats['minifyBytesSaved'] = max(0, (int) $stats['beforeBytes'] - (int) $stats['afterBytes']);

        return array('css' => (string) $optimized . ('' !== $optimized ? "\n" : ''), 'stats' => $stats);
    }
}

if (!function_exists('ucwp_font_css_file_diagnostics')) {
    function ucwp_font_css_file_diagnostics($file, $max_items = 8)
    {
        $file = (string) $file;
        $css = is_readable($file) ? ucwp_safe_file_get_contents($file, 'font_css_file_diagnostics', true) : '';
        if (!is_string($css)) {
            $css = '';
        }
        return array(
            'file' => basename($file),
            'bytes' => is_file($file) ? (int) max(0, (int) ucwp_safe_filesize($file, 'font_css_file_diagnostics')) : 0,
            'fontFaceBlocks' => preg_match_all('/@font-face\s*{/i', $css),
            'woff2Sources' => preg_match_all('/\.woff2(?:[\?#][^\"\'\)\s]*)?/i', $css),
            'woffSources' => preg_match_all('/\.woff(?!2)(?:[\?#][^\"\'\)\s]*)?/i', $css),
            'ttfSources' => preg_match_all('/\.ttf(?:[\?#][^\"\'\)\s]*)?/i', $css),
        );
    }
}
