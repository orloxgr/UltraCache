<?php
/**
 * Conditional response validators for UltraCache HTML cache delivery.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Conditional_Response_Trait
{
    /**
     * Build a bounded weak validator from the selected cached representation.
     *
     * @param string $file_path       Validated cache payload path.
     * @param string $encoding_bucket Selected representation encoding.
     * @return array
     */
    private function get_cached_html_validator_metadata($file_path, $encoding_bucket = 'identity')
    {
        $file_path = (string) $file_path;
        clearstatcache(true, $file_path);
        $stat = is_readable($file_path) ? stat($file_path) : false;
        if (!is_array($stat)) {
            return array();
        }

        $mtime = max(0, (int) ($stat['mtime'] ?? 0));
        $size = max(0, (int) ($stat['size'] ?? 0));
        $inode = max(0, (int) ($stat['ino'] ?? 0));
        if ($mtime <= 0 || $size <= 0) {
            return array();
        }

        $encoding_bucket = sanitize_key((string) $encoding_bucket);
        if (!in_array($encoding_bucket, array('identity', 'gzip', 'brotli'), true)) {
            $encoding_bucket = 'identity';
        }

        $signature = hash(
            'sha256',
            implode('|', array(
                basename($file_path),
                (string) $mtime,
                (string) $size,
                (string) $inode,
                $encoding_bucket,
            ))
        );

        return array(
            'etag'         => 'W/"uc-' . substr($signature, 0, 32) . '"',
            // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- HTTP Last-Modified requires a non-localized IMF-fixdate in GMT.
            'lastModified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
            'mtime'        => $mtime,
            'size'         => $size,
            'encoding'     => $encoding_bucket,
        );
    }

    /**
     * Send ETag and Last-Modified for a validated cached representation.
     *
     * @param array $metadata Validator metadata.
     * @return void
     */
    private function send_cached_html_validator_headers(array $metadata)
    {
        if (headers_sent()) {
            return;
        }

        if (!empty($metadata['etag'])) {
            header('ETag: ' . (string) $metadata['etag'], true);
        }
        if (!empty($metadata['lastModified'])) {
            header('Last-Modified: ' . (string) $metadata['lastModified'], true);
        }
    }

    /**
     * Normalize an entity-tag for weak comparison.
     *
     * @param string $etag Entity-tag value.
     * @return string
     */
    private function normalize_cached_html_etag_for_comparison($etag)
    {
        $etag = trim((string) $etag);
        if (0 === stripos($etag, 'W/')) {
            $etag = trim(substr($etag, 2));
        }

        return $etag;
    }

    /**
     * Whether an If-None-Match value weakly matches the selected validator.
     *
     * @param string $request_value Request header value.
     * @param string $current_etag  Current response ETag.
     * @return bool
     */
    private function cached_html_if_none_match_matches($request_value, $current_etag)
    {
        $request_value = trim(substr((string) $request_value, 0, 4096));
        $current_etag = $this->normalize_cached_html_etag_for_comparison($current_etag);
        if ('' === $request_value || '' === $current_etag) {
            return false;
        }

        if ('*' === $request_value) {
            return true;
        }

        foreach (explode(',', $request_value) as $candidate) {
            $candidate = $this->normalize_cached_html_etag_for_comparison($candidate);
            if ('' !== $candidate && hash_equals($current_etag, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an If-Modified-Since value validates the selected representation.
     *
     * @param string $request_value Request header value.
     * @param int    $mtime         Representation modification time.
     * @return bool
     */
    private function cached_html_if_modified_since_matches($request_value, $mtime)
    {
        $request_value = trim(substr((string) $request_value, 0, 255));
        $mtime = max(0, (int) $mtime);
        if ('' === $request_value || $mtime <= 0) {
            return false;
        }

        $request_time = strtotime($request_value);
        return false !== $request_time && $mtime <= (int) $request_time;
    }

    /**
     * Whether this GET/HEAD request may receive a 304 response.
     *
     * If-None-Match takes precedence over If-Modified-Since, as required by HTTP.
     *
     * @param array $metadata Validator metadata.
     * @return bool
     */
    private function cached_html_request_is_not_modified(array $metadata)
    {
        $method = strtoupper((string) ultracache_server_value('REQUEST_METHOD'));
        if ('' === $method) {
            $method = 'GET';
        }
        if (!in_array($method, array('GET', 'HEAD'), true)) {
            return false;
        }

        $if_none_match = (string) ultracache_server_value('HTTP_IF_NONE_MATCH');
        if ('' !== trim($if_none_match)) {
            return $this->cached_html_if_none_match_matches($if_none_match, (string) ($metadata['etag'] ?? ''));
        }

        return $this->cached_html_if_modified_since_matches(
            (string) ultracache_server_value('HTTP_IF_MODIFIED_SINCE'),
            (int) ($metadata['mtime'] ?? 0)
        );
    }

    /**
     * Send a bodyless 304 response after normal cache metadata headers are set.
     *
     * @return void
     */
    private function send_cached_html_not_modified_status()
    {
        if (headers_sent()) {
            return;
        }

        if (function_exists('header_remove')) {
            header_remove('Content-Type');
            header_remove('Content-Encoding');
            header_remove('Content-Length');
        }

        status_header(304);
    }
}
