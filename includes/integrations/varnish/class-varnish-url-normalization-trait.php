<?php
/**
 * Varnish invalidation URL validation, normalization and deduplication.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_URL_Normalization_Trait
{
    /**
     * Maximum path plus query length accepted for one invalidation target.
     *
     * @return int
     */
    private static function get_varnish_invalidation_target_limit()
    {
        return 2048;
    }

    /**
     * Recursively sort query values in the same deterministic form as page cache.
     *
     * @param mixed $value Query value.
     * @return mixed
     */
    private static function sort_varnish_query_value_for_cache($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = self::sort_varnish_query_value_for_cache($child);
        }

        if (array_keys($value) === range(0, count($value) - 1)) {
            usort($value, static function ($a, $b) {
                return strcmp((string) wp_json_encode($a), (string) wp_json_encode($b));
            });
            return $value;
        }

        ksort($value);
        return $value;
    }

    /**
     * Validate and normalize a query string against UltraCache's allowlist.
     *
     * @param string $query    Raw query string.
     * @param array  $settings Runtime cache settings.
     * @param string $reason   Rejection reason passed by reference.
     * @return string|null Normalized query, empty string, or null when rejected.
     */
    private static function normalize_varnish_query_for_cache($query, array $settings, &$reason)
    {
        $query = (string) $query;
        $reason = '';
        if ('' === $query) {
            return '';
        }

        if (empty($settings['cache_query_strings'])) {
            $reason = 'query-strings-disabled';
            return null;
        }

        $allowlist = !empty($settings['cache_query_allowlist']) && is_array($settings['cache_query_allowlist'])
            ? array_values(array_unique(array_filter(array_map('sanitize_key', $settings['cache_query_allowlist']))))
            : array();
        if (empty($allowlist)) {
            $reason = 'query-allowlist-empty';
            return null;
        }

        parse_str($query, $query_vars);
        if (empty($query_vars) || !is_array($query_vars)) {
            $reason = 'invalid-query';
            return null;
        }

        $lookup = array_fill_keys($allowlist, true);
        $normalized = array();
        foreach ($query_vars as $query_key => $query_value) {
            $normalized_key = sanitize_key((string) $query_key);
            if ('' === $normalized_key || !isset($lookup[$normalized_key])) {
                $reason = 'query-arg-not-allowlisted';
                return null;
            }
            $normalized[$normalized_key] = self::sort_varnish_query_value_for_cache($query_value);
        }

        ksort($normalized);
        return http_build_query($normalized, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Create a bounded diagnostic label without retaining query values.
     *
     * @param string $url Candidate URL.
     * @return string
     */
    private static function get_varnish_rejected_url_label($url)
    {
        $url = trim((string) $url);
        $parts = ultracache_safe_wp_parse_url($url, -1, 'get_varnish_rejected_url_label');
        if (is_array($parts) && !empty($parts['host'])) {
            $scheme = !empty($parts['scheme']) ? strtolower((string) $parts['scheme']) . '://' : '';
            $host = strtolower((string) $parts['host']);
            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
            $path = isset($parts['path']) && '' !== (string) $parts['path'] ? (string) $parts['path'] : '/';
            $label = $scheme . $host . $port . $path;
            if (isset($parts['query']) && '' !== (string) $parts['query']) {
                $label .= '?[redacted]';
            }
        } else {
            $label = preg_split('/[?#]/', $url, 2)[0] ?? '';
        }

        $label = self::sanitize_varnish_string((string) $label);
        if (strlen($label) > 240) {
            $label = substr($label, 0, 239) . '…';
        }
        return $label;
    }

    /**
     * Normalize one URL through the page-cache decision engine where available.
     *
     * @param string $url Candidate URL.
     * @return array
     */
    protected static function normalize_varnish_invalidation_url($url)
    {
        $input_url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
        if ('' === $input_url) {
            return array('valid' => false, 'reason' => 'empty-url', 'url' => '');
        }

        $parsed_input = ultracache_safe_wp_parse_url($input_url, -1, 'normalize_varnish_invalidation_url');
        if (!is_array($parsed_input) || empty($parsed_input['scheme']) || empty($parsed_input['host'])) {
            return array('valid' => false, 'reason' => 'invalid-url', 'url' => $input_url);
        }

        $scheme = strtolower((string) $parsed_input['scheme']);
        if (!in_array($scheme, array('http', 'https'), true)) {
            return array('valid' => false, 'reason' => 'unsupported-scheme', 'url' => $input_url);
        }

        if (!empty($parsed_input['user']) || !empty($parsed_input['pass'])) {
            return array('valid' => false, 'reason' => 'credentials-not-allowed', 'url' => $input_url);
        }

        if (!function_exists('ultracache_is_local_site_url') || !ultracache_is_local_site_url($input_url)) {
            return array('valid' => false, 'reason' => 'non-local-url', 'url' => $input_url);
        }

        $normalized_url = '';
        $inspection_reason = '';
        $manual_normalization_required = false;
        if (class_exists('Ultra_Cache_Engine') && method_exists('Ultra_Cache_Engine', 'get_instance')) {
            try {
                $engine = Ultra_Cache_Engine::get_instance();
                if ($engine && method_exists($engine, 'inspect_url')) {
                    $inspection = $engine->inspect_url($input_url);
                    if (is_array($inspection)) {
                        $inspection_reason = sanitize_key((string) ($inspection['reason'] ?? ''));
                        if (!empty($inspection['cacheable'])) {
                            $normalized_url = trim((string) ($inspection['normalizedUrl'] ?? ''));
                        } elseif ('disabled' === $inspection_reason) {
                            $manual_normalization_required = true;
                        } else {
                            return array(
                                'valid' => false,
                                'reason' => '' !== $inspection_reason ? $inspection_reason : 'non-cacheable-url',
                                'url' => $input_url,
                            );
                        }
                    }
                }
            } catch (Throwable $throwable) {
                $manual_normalization_required = true;
            }
        } else {
            $manual_normalization_required = true;
        }

        if ($manual_normalization_required || '' === $normalized_url) {
            $settings = method_exists(static::class, 'get_settings') ? self::get_settings() : array();
            $query_reason = '';
            $normalized_query = self::normalize_varnish_query_for_cache(
                isset($parsed_input['query']) ? (string) $parsed_input['query'] : '',
                is_array($settings) ? $settings : array(),
                $query_reason
            );
            if (null === $normalized_query) {
                return array(
                    'valid' => false,
                    'reason' => '' !== $query_reason ? $query_reason : 'non-cacheable-query',
                    'url' => $input_url,
                );
            }

            $host = strtolower(rtrim((string) $parsed_input['host'], '.'));
            $port = isset($parsed_input['port']) ? ':' . (int) $parsed_input['port'] : '';
            $path = isset($parsed_input['path']) && '' !== (string) $parsed_input['path']
                ? '/' . ltrim((string) $parsed_input['path'], '/')
                : '/';
            $normalized_url = $scheme . '://' . $host . $port . $path . ('' !== $normalized_query ? '?' . $normalized_query : '');
        }

        $parsed = ultracache_safe_wp_parse_url($normalized_url, -1, 'normalize_varnish_invalidation_url normalized');
        if (!is_array($parsed) || empty($parsed['host'])) {
            return array('valid' => false, 'reason' => 'normalization-failed', 'url' => $input_url);
        }

        $host = strtolower(rtrim((string) $parsed['host'], '.'));
        if ('' === $host || preg_match('/[\x00-\x20\x7f]/', $host)) {
            return array('valid' => false, 'reason' => 'invalid-host', 'url' => $input_url);
        }

        $path = isset($parsed['path']) ? (string) $parsed['path'] : '/';
        if ('' === $path) {
            $path = '/';
        }
        if ('/' !== $path[0]) {
            $path = '/' . $path;
        }
        if (preg_match('/[\x00\r\n]/', $path)) {
            return array('valid' => false, 'reason' => 'invalid-path', 'url' => $input_url);
        }

        $query = isset($parsed['query']) ? (string) $parsed['query'] : '';
        if (preg_match('/[\x00\r\n]/', $query)) {
            return array('valid' => false, 'reason' => 'invalid-query', 'url' => $input_url);
        }

        $target = $path . ('' !== $query ? '?' . $query : '');
        if (strlen($target) > self::get_varnish_invalidation_target_limit()) {
            return array('valid' => false, 'reason' => 'target-too-long', 'url' => $input_url);
        }

        $normalized_scheme = !empty($parsed['scheme']) ? strtolower((string) $parsed['scheme']) : $scheme;
        $port = isset($parsed['port']) ? ':' . (int) $parsed['port'] : '';
        $canonical_url = $normalized_scheme . '://' . $host . $port . $target;

        return array(
            'valid' => true,
            'reason' => '',
            'url' => $canonical_url,
            'host' => $host,
            'path' => $target,
            'dedupeKey' => $host . "\n" . $target,
        );
    }

    /**
     * Normalize, validate and deduplicate an invalidation URL list.
     *
     * @param array $urls Candidate URLs.
     * @return array
     */
    protected static function prepare_varnish_invalidation_urls(array $urls)
    {
        $prepared = array();
        $seen = array();
        $rejections = array();
        $duplicates = 0;
        $valid_count = 0;

        foreach ($urls as $url) {
            $candidate = self::normalize_varnish_invalidation_url($url);
            if (empty($candidate['valid'])) {
                $rejections[] = array(
                    'url' => self::get_varnish_rejected_url_label((string) ($candidate['url'] ?? $url)),
                    'reason' => sanitize_key((string) ($candidate['reason'] ?? 'invalid-url')),
                );
                continue;
            }

            $single_expression = self::build_varnish_ban_expression(
                (string) ($candidate['host'] ?? ''),
                (string) ($candidate['path'] ?? ''),
                false
            );
            if ('' === $single_expression || strlen($single_expression) > self::get_varnish_ban_batch_expression_limit()) {
                $rejections[] = array(
                    'url' => self::get_varnish_rejected_url_label((string) ($candidate['url'] ?? $url)),
                    'reason' => 'expression-too-long',
                );
                continue;
            }

            ++$valid_count;
            $dedupe_key = (string) ($candidate['dedupeKey'] ?? '');
            if ('' === $dedupe_key || isset($seen[$dedupe_key])) {
                ++$duplicates;
                continue;
            }

            $seen[$dedupe_key] = true;
            $prepared[] = $candidate;
        }

        return array(
            'receivedCount' => count($urls),
            'validCount' => $valid_count,
            'uniqueCount' => count($prepared),
            'duplicateCount' => $duplicates,
            'rejectedCount' => count($rejections),
            'urls' => $prepared,
            'rejections' => array_slice($rejections, 0, 20),
            'rejectionsTruncated' => count($rejections) > 20,
        );
    }
}
