<?php
/**
 * Shared responsive-image srcset parsing and rewriting helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether one byte is HTML ASCII whitespace.
 *
 * @param string $character Single-byte character.
 * @return bool
 */
function ultracache_srcset_is_ascii_whitespace($character)
{
    return '' !== $character && false !== strpos("\x09\x0A\x0C\x0D\x20", $character);
}

/**
 * Parse a srcset string without treating commas inside URL tokens as separators.
 *
 * The returned offsets address the original byte string so callers can replace
 * only URL tokens and preserve all descriptors, commas, and whitespace exactly.
 * This follows the responsive-image candidate parsing shape: a URL token runs
 * until ASCII whitespace; descriptor text then runs until a top-level comma.
 * Trailing commas on a URL-only token terminate that candidate.
 *
 * @param string $srcset Raw srcset value.
 * @return array<int,array{url:string,descriptor:string,url_start:int,url_length:int,candidate_start:int,candidate_end:int}>
 */
function ultracache_parse_srcset_candidates($srcset)
{
    $srcset = (string) $srcset;
    $length = strlen($srcset);
    if (0 === $length) {
        return array();
    }

    $position = 0;
    $candidates = array();

    while ($position < $length) {
        while ($position < $length) {
            $character = $srcset[$position];
            if (',' !== $character && !ultracache_srcset_is_ascii_whitespace($character)) {
                break;
            }
            $position++;
        }

        if ($position >= $length) {
            break;
        }

        $candidate_start = $position;
        $url_start = $position;
        while ($position < $length && !ultracache_srcset_is_ascii_whitespace($srcset[$position])) {
            $position++;
        }

        $url_token_end = $position;
        $url_end = $url_token_end;
        while ($url_end > $url_start && ',' === $srcset[$url_end - 1]) {
            $url_end--;
        }

        if ($url_end <= $url_start) {
            continue;
        }

        $descriptor = '';
        $candidate_end = $position;

        if ($url_end === $url_token_end) {
            while ($position < $length && ultracache_srcset_is_ascii_whitespace($srcset[$position])) {
                $position++;
            }

            $descriptor_start = $position;
            $parentheses = 0;
            while ($position < $length) {
                $character = $srcset[$position];
                if ('(' === $character) {
                    $parentheses++;
                } elseif (')' === $character && $parentheses > 0) {
                    $parentheses--;
                } elseif (',' === $character && 0 === $parentheses) {
                    break;
                }
                $position++;
            }

            $descriptor_end = $position;
            while ($descriptor_end > $descriptor_start && ultracache_srcset_is_ascii_whitespace($srcset[$descriptor_end - 1])) {
                $descriptor_end--;
            }
            if ($descriptor_end > $descriptor_start) {
                $descriptor = substr($srcset, $descriptor_start, $descriptor_end - $descriptor_start);
            }
            $candidate_end = $position;

            if ($position < $length && ',' === $srcset[$position]) {
                $position++;
            }
        }

        $candidates[] = array(
            'url'             => substr($srcset, $url_start, $url_end - $url_start),
            'descriptor'      => $descriptor,
            'url_start'       => $url_start,
            'url_length'      => $url_end - $url_start,
            'candidate_start' => $candidate_start,
            'candidate_end'   => $candidate_end,
        );
    }

    return $candidates;
}

/**
 * Return unique candidate URLs from a srcset value in source order.
 *
 * @param string $srcset Raw srcset value.
 * @return array<int,string>
 */
function ultracache_extract_srcset_urls($srcset)
{
    $urls = array();
    foreach (ultracache_parse_srcset_candidates($srcset) as $candidate) {
        $url = (string) ($candidate['url'] ?? '');
        if ('' !== $url && !isset($urls[$url])) {
            $urls[$url] = true;
        }
    }

    return array_keys($urls);
}

/**
 * Rewrite srcset URL tokens while preserving all untouched bytes.
 *
 * The callback receives the URL, descriptor, and parsed candidate. Returning a
 * non-empty URL replaces only that URL token. Returning false, null, an empty
 * string, or the original URL leaves the candidate untouched. When no token is
 * changed the exact original srcset string is returned byte-for-byte.
 *
 * @param string   $srcset   Raw srcset value.
 * @param callable $callback URL replacement callback.
 * @return string
 */
function ultracache_rewrite_srcset_urls($srcset, $callback)
{
    $srcset = (string) $srcset;
    if ('' === $srcset || !is_callable($callback)) {
        return $srcset;
    }

    $replacements = array();
    foreach (ultracache_parse_srcset_candidates($srcset) as $candidate) {
        $url = (string) ($candidate['url'] ?? '');
        if ('' === $url) {
            continue;
        }

        $replacement = call_user_func(
            $callback,
            $url,
            (string) ($candidate['descriptor'] ?? ''),
            $candidate
        );
        if (!is_string($replacement)) {
            continue;
        }

        $replacement = trim($replacement);
        if ('' === $replacement || $replacement === $url || preg_match('/[\x00-\x20\x7F]/', $replacement)) {
            continue;
        }

        $replacements[] = array(
            'start'  => (int) $candidate['url_start'],
            'length' => (int) $candidate['url_length'],
            'value'  => $replacement,
        );
    }

    if (empty($replacements)) {
        return $srcset;
    }

    usort(
        $replacements,
        static function ($left, $right) {
            return ((int) $right['start']) <=> ((int) $left['start']);
        }
    );

    $rewritten = $srcset;
    foreach ($replacements as $replacement) {
        $rewritten = substr_replace(
            $rewritten,
            (string) $replacement['value'],
            (int) $replacement['start'],
            (int) $replacement['length']
        );
    }

    return $rewritten;
}
