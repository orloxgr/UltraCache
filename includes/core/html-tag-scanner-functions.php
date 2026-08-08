<?php
/**
 * Dependency-free raw HTML opening-tag scanner helpers.
 *
 * These helpers preserve original bytes and byte offsets. They intentionally
 * do not implement HTML tree construction, selector matching, text-node
 * parsing, or script/style inner-content parsing.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('ultracache_scan_raw_html_tags')) {
    /**
     * Scan raw HTML tags while respecting single- and double-quoted attributes.
     *
     * @param string        $html          Source HTML.
     * @param array<string> $allowed_names Optional case-insensitive tag allowlist.
     * @param int           $start_offset  Inclusive byte offset.
     * @param int|null      $end_offset    Exclusive byte offset.
     * @param int           $maximum_tags  Maximum returned tags, or zero for no explicit limit.
     * @return array<int,array{name:string,raw:string,offset:int,end:int,closing:bool,self_closing:bool}>
     */
    function ultracache_scan_raw_html_tags($html, array $allowed_names = array(), $start_offset = 0, $end_offset = null, $maximum_tags = 0)
    {
        if (!is_string($html) || '' === $html) {
            return array();
        }

        $length = strlen($html);
        $start_offset = max(0, min($length, (int) $start_offset));
        $end_offset = null === $end_offset ? $length : max($start_offset, min($length, (int) $end_offset));
        $maximum_tags = max(0, (int) $maximum_tags);

        $allowed = array();
        foreach ($allowed_names as $allowed_name) {
            $allowed_name = strtolower(trim((string) $allowed_name));
            if ('' !== $allowed_name && preg_match('/^[a-z][a-z0-9:_-]*$/', $allowed_name)) {
                $allowed[$allowed_name] = true;
            }
        }

        $tags = array();
        $offset = $start_offset;
        while ($offset < $end_offset) {
            $tag_start = strpos($html, '<', $offset);
            if (false === $tag_start || $tag_start >= $end_offset) {
                break;
            }

            $cursor = $tag_start + 1;
            $closing = false;
            if ($cursor < $end_offset && '/' === $html[$cursor]) {
                $closing = true;
                ++$cursor;
            }

            if ($cursor >= $end_offset || !preg_match('/[A-Za-z]/', $html[$cursor])) {
                $offset = $tag_start + 1;
                continue;
            }

            $name_start = $cursor;
            while ($cursor < $end_offset && preg_match('/[A-Za-z0-9:_-]/', $html[$cursor])) {
                ++$cursor;
            }
            $name = strtolower(substr($html, $name_start, $cursor - $name_start));
            if ('' === $name || (!empty($allowed) && !isset($allowed[$name]))) {
                $offset = $tag_start + 1;
                continue;
            }

            if ($cursor < $end_offset && false === strpos(" \t\r\n\f/>", $html[$cursor])) {
                $offset = $tag_start + 1;
                continue;
            }

            $quote = '';
            $tag_end = -1;
            for (; $cursor < $end_offset; ++$cursor) {
                $character = $html[$cursor];
                if ('' !== $quote) {
                    if ($character === $quote) {
                        $quote = '';
                    }
                    continue;
                }

                if ('"' === $character || "'" === $character) {
                    $quote = $character;
                    continue;
                }

                if ('>' === $character) {
                    $tag_end = $cursor + 1;
                    break;
                }
            }

            if ($tag_end < 0) {
                // An unterminated quoted attribute makes later boundaries
                // ambiguous, so fail closed for the remainder of this range.
                break;
            }

            $raw = substr($html, $tag_start, $tag_end - $tag_start);
            $before_close = rtrim(substr($raw, 0, -1));
            $tags[] = array(
                'name' => $name,
                'raw' => $raw,
                'offset' => $tag_start,
                'end' => $tag_end,
                'closing' => $closing,
                'self_closing' => !$closing && '' !== $before_close && '/' === substr($before_close, -1),
            );

            if ($maximum_tags > 0 && count($tags) >= $maximum_tags) {
                break;
            }
            $offset = $tag_end;
        }

        return $tags;
    }
}
