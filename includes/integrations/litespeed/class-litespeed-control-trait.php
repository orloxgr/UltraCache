<?php
/**
 * Signed native LiteSpeed purge control and invalidation helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_LiteSpeed_Control_Trait
{
    /**
     * Return whether LiteSpeed exact invalidation should preserve stale HTML while
     * the shared Automation pipeline regenerates the page.
     *
     * LiteSpeed does not own a separate stale policy. The central Automation
     * Stale While Revalidate switch and Fresh/Max Stale lifetime define it.
     *
     * @param array $settings Optional dashboard settings.
     * @return bool
     */
    public static function is_litespeed_stale_invalidation_enabled(array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_dashboard_settings();
        }

        $fresh_minutes = max(1, min(525600, absint($settings['cacheFreshTtlMinutes'] ?? 1440)));
        $max_stale_minutes = max(
            $fresh_minutes,
            min(525600, absint($settings['cacheMaxStaleMinutes'] ?? $fresh_minutes))
        );

        return !empty($settings['pageCacheEnabled'])
            && !empty($settings['liteSpeedCacheEnabled'])
            && !empty($settings['staleWhileRevalidateEnabled'])
            && $max_stale_minutes > $fresh_minutes;
    }

    /**
     * Maximum exact URLs carried by one signed control request.
     *
     * @return int
     */
    private static function get_litespeed_control_url_limit()
    {
        return 20;
    }

    /**
     * Whether UltraCache owns the native LiteSpeed HTML cache contract.
     *
     * @return bool
     */
    private static function is_native_litespeed_html_cache_enabled()
    {
        $settings = self::get_dashboard_settings();

        return !empty($settings['pageCacheEnabled'])
            && !empty($settings['liteSpeedCacheEnabled']);
    }

    /**
     * Return the canonical query-cache projection used by LiteSpeed rules,
     * diagnostics, and invalidation reporting.
     *
     * Safe-query LiteSpeed retrieval remains disabled until cache-key parity is
     * proven by the dedicated roadmap stage.
     *
     * @return array<string,mixed>
     */
    private static function get_litespeed_query_cache_policy()
    {
        $settings = method_exists(static::class, 'get_settings') ? self::get_settings() : array();
        if (function_exists('ultracache_get_query_cache_policy')) {
            return ultracache_get_query_cache_policy(is_array($settings) ? $settings : array());
        }

        return array(
            'version' => 1,
            'enabled' => false,
            'configured_allowlist' => array(),
            'allowlist' => array(),
            'hard_blocked_keys' => array(),
            'fingerprint' => '',
        );
    }

    /**
     * Return the current LiteSpeed query cache-key proof.
     *
     * @return array<string,mixed>
     */
    private static function get_litespeed_query_cache_key_proof()
    {
        $policy = self::get_litespeed_query_cache_policy();
        if (function_exists('ultracache_build_litespeed_query_cache_key_proof')) {
            return ultracache_build_litespeed_query_cache_key_proof($policy);
        }

        return array(
            'version' => 2,
            'status' => 'blocked',
            'verified' => false,
            'safe_query_retrieval_enabled' => false,
            'policy_fingerprint' => (string) ($policy['fingerprint'] ?? ''),
            'fingerprint' => '',
            'reason' => 'proof-helper-unavailable',
            'operation_contract' => array(
                'retrieval' => 'bypass',
                'storage' => 'no-cache',
                'exact_purge' => 'skip',
                'public_refill' => 'skip',
                'base_url_aliasing' => false,
            ),
        );
    }

    /**
     * Whether one URL carries a non-empty query string.
     *
     * Native LiteSpeed lookup bypasses every non-empty query string, so query
     * URLs must never be mapped to their base URL for purge or refill.
     *
     * @param string $url URL candidate.
     * @return bool
     */
    private static function litespeed_url_has_nonempty_query($url)
    {
        $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
        if ('' === $url) {
            return false;
        }

        $parts = wp_parse_url($url);
        return is_array($parts)
            && array_key_exists('query', $parts)
            && '' !== (string) $parts['query'];
    }

    /**
     * Return the stable configured WordPress public origin for LiteSpeed control.
     *
     * LiteSpeed keeps HTTP and HTTPS cache contexts separate. Internal control
     * requests therefore use the configured WordPress home scheme instead of a
     * request-filtered home_url() value that multilingual/proxy code can change.
     * The path is intentionally excluded because REST routing may legitimately be
     * language-aware while the transport scheme must remain stable.
     *
     * @return string
     */
    private static function get_litespeed_stable_site_origin()
    {
        if (function_exists('ultracache_get_configured_site_origin')) {
            return (string) ultracache_get_configured_site_origin();
        }

        $home = function_exists('home_url') ? (string) home_url('/') : '';
        $parts = wp_parse_url($home);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $origin = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
        if (!empty($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }

        return $origin;
    }

    /**
     * Return the local public hosts accepted by LiteSpeed exact invalidation.
     *
     * @return array<int,string>
     */
    private static function get_litespeed_local_public_hosts()
    {
        if (function_exists('ultracache_get_trusted_hosts')) {
            return array_values(array_unique(array_filter(array_map('strval', ultracache_get_trusted_hosts()))));
        }

        $hosts = array();
        foreach (array(self::get_litespeed_stable_site_origin(), home_url('/'), site_url('/')) as $candidate) {
            $host = strtolower((string) wp_parse_url(trim((string) $candidate), PHP_URL_HOST));
            if ('' !== $host) {
                $hosts[$host] = $host;
            }
        }

        return array_values($hosts);
    }

    /**
     * Resolve the exact public origin that owns one LiteSpeed cache target.
     *
     * The target must already belong to UltraCache's trusted frontend topology.
     * Keeping the target origin intact is required for domain-per-language sites,
     * because LiteSpeed cache objects are scoped by the responding virtual host.
     *
     * @param string $url Public target URL.
     * @return string
     */
    private static function get_litespeed_control_origin_for_url($url)
    {
        $url = trim((string) $url);
        if ('' === $url) {
            return '';
        }

        if (function_exists('ultracache_is_strict_frontend_loopback_url')
            && !ultracache_is_strict_frontend_loopback_url($url)) {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (!in_array($scheme, array('http', 'https'), true) || '' === $host) {
            return '';
        }

        $origin = $scheme . '://' . $host;
        if (!empty($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }

        return $origin;
    }

    /**
     * Return every unique public origin that can own a LiteSpeed site cache.
     *
     * Directory-mode languages collapse to one origin. Provider domain-per-language
     * installations yield one origin per language virtual host. Capability proof
     * remains global; this list only scopes runtime control requests.
     *
     * @return array<int,string>
     */
    private static function get_litespeed_control_origins()
    {
        $origins = array();
        $candidates = array();
        if (function_exists('ultracache_get_public_site_topology')) {
            $topology = ultracache_get_public_site_topology();
            if (!empty($topology['configuredBase'])) {
                $candidates[] = (string) $topology['configuredBase'];
            }
            foreach ((array) ($topology['multilingualLanguageHomeUrls'] ?? array()) as $language_url) {
                $candidates[] = (string) $language_url;
            }
        }
        if (empty($candidates)) {
            $candidates[] = self::get_litespeed_stable_site_origin();
        }

        foreach ($candidates as $candidate) {
            $origin = self::get_litespeed_control_origin_for_url($candidate);
            if ('' !== $origin) {
                $origins[strtolower($origin)] = $origin;
            }
        }

        if (empty($origins)) {
            $origin = self::get_litespeed_stable_site_origin();
            if ('' !== $origin) {
                $origins[strtolower($origin)] = $origin;
            }
        }

        return array_values($origins);
    }

    /**
     * Return the signed LiteSpeed control endpoint for one public cache origin.
     *
     * The REST path remains the WordPress/WPML-provided route. Only the authority
     * is rebound to the trusted target origin so the purge response is emitted by
     * the same LiteSpeed virtual host that owns the cached object.
     *
     * @param string $target_url Optional target URL/origin.
     * @return string
     */
    private static function get_litespeed_control_transport_url($target_url = '')
    {
        $url = (string) rest_url('ultracache/v1/litespeed/control');
        $origin = '' !== trim((string) $target_url)
            ? self::get_litespeed_control_origin_for_url($target_url)
            : self::get_litespeed_stable_site_origin();
        if ('' === $origin) {
            return '';
        }

        $rest_parts = wp_parse_url($url);
        $origin_parts = wp_parse_url($origin);
        if (!is_array($rest_parts) || !is_array($origin_parts) || empty($origin_parts['scheme']) || empty($origin_parts['host'])) {
            return '';
        }

        $transport = strtolower((string) $origin_parts['scheme']) . '://' . strtolower((string) $origin_parts['host']);
        if (!empty($origin_parts['port'])) {
            $transport .= ':' . (int) $origin_parts['port'];
        }
        $transport .= !empty($rest_parts['path']) ? (string) $rest_parts['path'] : '/wp-json/ultracache/v1/litespeed/control';
        if (isset($rest_parts['query']) && '' !== (string) $rest_parts['query']) {
            $transport .= '?' . (string) $rest_parts['query'];
        }

        $transport = esc_url_raw($transport, array('http', 'https'));
        if ('' === $transport) {
            return '';
        }

        return function_exists('ultracache_is_strict_frontend_loopback_url')
            && !ultracache_is_strict_frontend_loopback_url($transport)
                ? ''
                : $transport;
    }

    /**
     * Normalize one local public URL for exact LiteSpeed invalidation.
     *
     * Query strings are excluded because the managed LiteSpeed lookup contract
     * bypasses every query-string request.
     *
     * @param string $url URL candidate.
     * @return string
     */
    private static function normalize_litespeed_purge_url($url)
    {
        $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
        if ('' === $url || self::litespeed_url_has_nonempty_query($url)) {
            return '';
        }

        $stable_origin = self::get_litespeed_stable_site_origin();
        $stable_scheme = strtolower((string) wp_parse_url($stable_origin, PHP_URL_SCHEME));
        if (0 === strpos($url, '//')) {
            if (!in_array($stable_scheme, array('http', 'https'), true)) {
                return '';
            }
            $url = $stable_scheme . ':' . $url;
        } elseif (0 === strpos($url, '/') && 0 !== strpos($url, '//')) {
            if ('' === $stable_origin) {
                return '';
            }
            $url = rtrim($stable_origin, '/') . '/' . ltrim($url, '/');
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, array('http', 'https'), true) || '' === $host) {
            return '';
        }

        $allowed_hosts = self::get_litespeed_local_public_hosts();
        if (empty($allowed_hosts) || !in_array($host, $allowed_hosts, true)) {
            return '';
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : 0;
        $candidate_origin = $scheme . '://' . $host . ($port > 0 ? ':' . $port : '');
        if (function_exists('ultracache_is_strict_frontend_loopback_url')
            && !ultracache_is_strict_frontend_loopback_url($candidate_origin . '/')) {
            return '';
        }

        $path = isset($parts['path']) ? rawurldecode((string) $parts['path']) : '/';
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        if ('' === $path) {
            $path = '/';
        }

        $normalized = $scheme . '://' . $host;
        if ($port > 0) {
            $normalized .= ':' . $port;
        }
        $normalized .= $path;

        return esc_url_raw($normalized);
    }

    /**
     * Partition LiteSpeed URL candidates under the query-bypass contract.
     *
     * @param array $urls  URL candidates.
     * @param int   $limit Optional maximum eligible URLs.
     * @return array<string,mixed>
     */
    private static function partition_litespeed_purge_urls(array $urls, $limit = 0)
    {
        $limit = $limit > 0 ? max(1, min(500, (int) $limit)) : 500;
        $prepared = array();
        $input_count = 0;
        $skipped_query_count = 0;
        $skipped_invalid_count = 0;
        $skipped_limit_count = 0;

        foreach ($urls as $url) {
            ++$input_count;
            if (!is_scalar($url)) {
                ++$skipped_invalid_count;
                continue;
            }

            $url = trim((string) $url);
            if ('' === $url) {
                ++$skipped_invalid_count;
                continue;
            }
            if (self::litespeed_url_has_nonempty_query($url)) {
                ++$skipped_query_count;
                continue;
            }

            $normalized = self::normalize_litespeed_purge_url($url);
            if ('' === $normalized) {
                ++$skipped_invalid_count;
                continue;
            }
            if (isset($prepared[$normalized])) {
                continue;
            }
            if (count($prepared) >= $limit) {
                ++$skipped_limit_count;
                continue;
            }

            $prepared[$normalized] = $normalized;
        }

        return array(
            'urls' => array_values($prepared),
            'inputUrlCount' => $input_count,
            'eligibleUrlCount' => count($prepared),
            'skippedQueryUrlCount' => $skipped_query_count,
            'skippedInvalidUrlCount' => $skipped_invalid_count,
            'skippedLimitUrlCount' => $skipped_limit_count,
            'queryUrlHandling' => 'bypass-skip',
        );
    }

    /**
     * Normalize and deduplicate exact LiteSpeed invalidation URLs.
     *
     * @param array $urls URL candidates.
     * @param int   $limit Optional maximum.
     * @return array<int,string>
     */
    private static function prepare_litespeed_purge_urls(array $urls, $limit = 0)
    {
        $partition = self::partition_litespeed_purge_urls($urls, $limit);
        return array_values((array) ($partition['urls'] ?? array()));
    }

    /** Normalize and deduplicate native LiteSpeed semantic purge tags. */
    private static function prepare_litespeed_purge_tags(array $tags, $limit = 0)
    {
        $limit = $limit > 0 ? max(1, min(100, (int) $limit)) : 100;
        return function_exists('ultracache_normalize_litespeed_cache_tags')
            ? ultracache_normalize_litespeed_cache_tags($tags, $limit)
            : array_slice(array_values(array_unique(array_filter(array_map('strval', $tags)))), 0, $limit);
    }

    /**
     * Return the canonical HMAC payload for one internal purge request.
     *
     * @param string $operation  Purge operation.
     * @param array  $urls       Normalized URLs.
     * @param string $request_id Unique request ID.
     * @param int    $expires    Expiry timestamp.
     * @return string
     */
    private static function get_litespeed_control_signature($operation, array $urls, $request_id, $expires)
    {
        $encoded_urls = wp_json_encode(array_values($urls), JSON_UNESCAPED_SLASHES);
        $encoded_urls = is_string($encoded_urls) ? $encoded_urls : '[]';
        $message = implode('|', array(
            sanitize_key((string) $operation),
            strtolower((string) $request_id),
            (string) ((int) $expires),
            hash('sha256', $encoded_urls),
        ));

        return ultracache_internal_sign('litespeed-control', $message);
    }

    /**
     * Atomically claim one signed control request to prevent replay.
     *
     * @param string $request_id Request ID.
     * @param int    $expires    Expiry timestamp.
     * @return bool
     */
    private static function claim_litespeed_control_request($request_id, $expires)
    {
        $hash = substr(hash('sha256', strtolower((string) $request_id)), 0, 40);
        $ttl = max(30, min(300, ((int) $expires - time()) + 30));

        if (!function_exists('ultracache_acquire_lock')) {
            return false;
        }

        return ultracache_acquire_lock(
            'ultracache_litespeed_control_' . $hash,
            (string) $request_id,
            $ttl,
            array('type' => 'litespeed_control', 'request_id' => (string) $request_id)
        );
    }

    /**
     * Validate and claim a signed internal LiteSpeed purge request.
     *
     * @param string $operation  Purge operation.
     * @param array  $urls       URL payload.
     * @param string $request_id Request ID.
     * @param int    $expires    Expiry timestamp.
     * @param string $signature  HMAC signature.
     * @return bool
     */
    public static function verify_litespeed_control_request($operation, array $urls, $request_id, $expires, $signature)
    {
        if (!self::is_native_litespeed_html_cache_enabled()) {
            return false;
        }

        $operation = sanitize_key((string) $operation);
        $request_id = strtolower(trim((string) $request_id));
        $expires = (int) $expires;
        $signature = strtolower(trim((string) $signature));
        $now = time();

        if (
            !in_array($operation, array('site', 'urls', 'stale-urls', 'tags', 'stale-tags'), true)
            || 1 !== preg_match('/^[a-f0-9-]{32,36}$/', $request_id)
            || $expires < ($now - 5)
            || $expires > ($now + 180)
            || 1 !== preg_match('/^[a-f0-9]{64}$/', $signature)
        ) {
            return false;
        }

        $is_tag_operation = in_array($operation, array('tags', 'stale-tags'), true);
        $prepared_targets = $is_tag_operation
            ? self::prepare_litespeed_purge_tags($urls, self::get_litespeed_control_url_limit())
            : self::prepare_litespeed_purge_urls($urls, self::get_litespeed_control_url_limit());
        if ('site' === $operation && !empty($prepared_targets)) {
            return false;
        }
        if (in_array($operation, array('urls', 'stale-urls', 'tags', 'stale-tags'), true) && empty($prepared_targets)) {
            return false;
        }

        $expected = self::get_litespeed_control_signature($operation, $prepared_targets, $request_id, $expires);
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        return self::claim_litespeed_control_request($request_id, $expires);
    }

    /**
     * Convert one normalized local URL into LiteSpeed's native exact-URL purge token.
     *
     * LiteSpeed requires the url= prefix for URI purges. Query-string URLs are
     * already excluded by the UltraCache native-cache contract before this point.
     *
     * @param string $url Normalized same-site URL.
     * @return string
     */
    private static function get_litespeed_exact_url_purge_token($url)
    {
        $url = self::normalize_litespeed_purge_url($url);
        if ('' === $url) {
            return '';
        }

        $path = wp_parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && '' !== $path ? $path : '/';
        if ('/' !== substr($path, 0, 1)) {
            $path = '/' . ltrim($path, '/');
        }

        // Response headers must stay single-line and comma/semicolon delimiters
        // belong to the LiteSpeed purge grammar, not to an individual URI token.
        if (
            strlen($path) > 2048
            || preg_match('/[\\x00-\\x20\\x7f,;]/', $path)
        ) {
            return '';
        }

        return 'url=' . $path;
    }

    /**
     * Build the native LiteSpeed purge response contract for a verified request.
     *
     * @param string $operation Purge operation.
     * @param array  $urls      URL payload.
     * @return array<string,mixed>
     */
    public static function get_litespeed_control_response($operation, array $urls = array())
    {
        $operation = sanitize_key((string) $operation);
        $is_tag_operation = in_array($operation, array('tags', 'stale-tags'), true);
        $is_url_operation = in_array($operation, array('urls', 'stale-urls'), true);
        $prepared_urls = $is_tag_operation ? array() : self::prepare_litespeed_purge_urls($urls, self::get_litespeed_control_url_limit());
        $prepared_semantic_tags = $is_tag_operation ? self::prepare_litespeed_purge_tags($urls, self::get_litespeed_control_url_limit()) : array();
        $tags = array();
        $url_tokens = array();

        if ('site' === $operation) {
            // A site purge is deliberately independent of UltraCache tag identity.
            // This also invalidates objects created before tag-normalization changes.
            return array(
                'success' => true,
                'message' => __('LiteSpeed public site-cache purge response prepared.', 'ultracache'),
                'purgeHeader' => '*',
                'operation' => $operation,
                'targetCount' => 1,
                'directUrlTargetCount' => 0,
                'urls' => array(),
                'tags' => array(),
            );
        } elseif ($is_tag_operation) {
            $tags = $prepared_semantic_tags;
        } elseif ($is_url_operation) {
            foreach ($prepared_urls as $url) {
                $tag = function_exists('ultracache_get_litespeed_url_tag')
                    ? ultracache_get_litespeed_url_tag($url)
                    : '';
                if ('' !== $tag) {
                    $tags[$tag] = $tag;
                }

                $url_token = self::get_litespeed_exact_url_purge_token($url);
                if ('' !== $url_token) {
                    $url_tokens[$url_token] = $url_token;
                }
            }
            $tags = array_values($tags);
            $url_tokens = array_values($url_tokens);
        }

        if (empty($tags) && empty($url_tokens)) {
            return array(
                'success' => false,
                'message' => __('No valid LiteSpeed purge targets were resolved.', 'ultracache'),
                'purgeHeader' => '',
                'operation' => $operation,
                'targetCount' => 0,
            );
        }

        $parts = array('public');
        if (in_array($operation, array('stale-urls', 'stale-tags'), true)) {
            $parts[] = 'stale';
        }
        foreach ($tags as $tag) {
            if (1 === preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', (string) $tag)) {
                // Explicit tag= keeps the response unambiguous when URL purge
                // tokens are carried in the same native LiteSpeed header.
                $parts[] = 'tag=' . (string) $tag;
            }
        }
        foreach ($url_tokens as $url_token) {
            $parts[] = $url_token;
        }

        if (count($parts) < 2) {
            return array(
                'success' => false,
                'message' => __('No valid LiteSpeed purge targets were resolved.', 'ultracache'),
                'purgeHeader' => '',
                'operation' => $operation,
                'targetCount' => 0,
            );
        }

        return array(
            'success' => true,
            'message' => 'site' === $operation
                ? __('LiteSpeed public site-cache purge response prepared.', 'ultracache')
                : ($is_tag_operation
                    ? ('stale-tags' === $operation
                        ? __('LiteSpeed stale semantic-tag purge response prepared.', 'ultracache')
                        : __('LiteSpeed semantic-tag purge response prepared.', 'ultracache'))
                    : ('stale-urls' === $operation
                        ? __('LiteSpeed stale exact URL purge response prepared.', 'ultracache')
                        : __('LiteSpeed exact URL purge response prepared.', 'ultracache'))),
            'purgeHeader' => implode(',', $parts),
            'operation' => $operation,
            // A native URL purge target is one prepared URL even though the
            // response can carry both its URL token and UltraCache tag.
            'targetCount' => $is_url_operation ? count($prepared_urls) : count($tags),
            'directUrlTargetCount' => $is_url_operation ? count($url_tokens) : 0,
            'urls' => $is_url_operation ? $prepared_urls : array(),
            'tags' => $is_tag_operation ? $prepared_semantic_tags : array(),
        );
    }

    /**
     * Dispatch one signed, blocking same-site purge request.
     *
     * @param string $operation Purge operation.
     * @param array  $urls      Normalized URLs.
     * @return array<string,mixed>
     */
    private static function dispatch_litespeed_control_request($operation, array $urls = array(), $control_target = '')
    {
        $operation = sanitize_key((string) $operation);
        $is_tag_operation = in_array($operation, array('tags', 'stale-tags'), true);
        $targets = $is_tag_operation
            ? self::prepare_litespeed_purge_tags($urls, self::get_litespeed_control_url_limit())
            : self::prepare_litespeed_purge_urls($urls, self::get_litespeed_control_url_limit());
        if ('site' === $operation) {
            $targets = array();
        } elseif (!in_array($operation, array('urls', 'stale-urls', 'tags', 'stale-tags'), true) || empty($targets)) {
            return array(
                'success' => false,
                'message' => __('No valid LiteSpeed purge targets were supplied.', 'ultracache'),
                'transport' => 'signed_internal_control',
                'operation' => $operation,
            );
        }

        $request_id = strtolower(wp_generate_uuid4());
        $expires = time() + 120;
        $signature = self::get_litespeed_control_signature($operation, $targets, $request_id, $expires);
        $url = self::get_litespeed_control_transport_url($control_target);
        if ('' === $url) {
            return array(
                'success' => false,
                'message' => __('No trusted LiteSpeed control transport URL could be resolved for the requested cache origin.', 'ultracache'),
                'transport' => 'signed_internal_control',
                'operation' => $operation,
                'targetCount' => count($targets),
                'httpStatus' => 0,
                'acknowledged' => false,
                'controlOrigin' => '',
            );
        }
        $control_origin = self::get_litespeed_control_origin_for_url($url);
        $request_args = array(
            'method' => 'POST',
            'timeout' => method_exists(static::class, 'get_litespeed_http_timeout')
                ? self::get_litespeed_http_timeout()
                : max(0, (int) ini_get('max_execution_time')),
            'blocking' => true,
            'redirection' => 0,
            'headers' => array(
                'Cache-Control' => 'no-cache',
                'X-UltraCache-Internal' => 'litespeed-control',
            ),
            'body' => array(
                'operation' => $operation,
                'urls' => $is_tag_operation ? array() : $targets,
                'tags' => $is_tag_operation ? $targets : array(),
                'requestId' => $request_id,
                'expires' => $expires,
                'signature' => $signature,
            ),
        );
        $response = function_exists('ultracache_safe_loopback_remote_request')
            ? ultracache_safe_loopback_remote_request($url, $request_args, 'litespeed-control')
            : wp_remote_post($url, $request_args);

        if (method_exists(static::class, 'observe_litespeed_http_response')) {
            self::observe_litespeed_http_response($response, 'signed-control-response');
        }

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: loopback request error. */
                    __('LiteSpeed signed control request failed: %s', 'ultracache'),
                    $response->get_error_message()
                ),
                'transport' => 'signed_internal_control',
                'operation' => $operation,
                'targetCount' => count($targets),
                'httpStatus' => 0,
                'errorCode' => sanitize_key((string) $response->get_error_code()),
                'controlOrigin' => $control_origin,
                'controlUrl' => $url,
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $body = is_array($body) ? $body : array();
        $acknowledged = '1' === (string) wp_remote_retrieve_header($response, 'x-ultracache-litespeed-purge');
        $success = $status_code >= 200 && $status_code < 300 && !empty($body['success']) && $acknowledged;

        return array(
            'success' => $success,
            'message' => $success
                ? __('LiteSpeed purge header dispatched through the signed internal control response.', 'ultracache')
                : (string) ($body['message'] ?? __('LiteSpeed signed control response did not acknowledge the purge.', 'ultracache')),
            'transport' => 'signed_internal_control',
            'operation' => $operation,
            'targetCount' => (int) ($body['targetCount'] ?? count($targets)),
            'httpStatus' => $status_code,
            'acknowledged' => $acknowledged,
            'controlOrigin' => $control_origin,
            'controlUrl' => $url,
        );
    }

    /**
     * Dispatch an UltraCache-owned public site-cache purge.
     *
     * @param array $status Optional current transport status.
     * @return array<string,mixed>
     */
    private static function dispatch_litespeed_site_purge(array $status = array())
    {
        if (empty($status['serverDetected']) && empty($status['nativeHeaderAvailable'])
            && method_exists(static::class, 'get_confirmed_litespeed_transport_status')) {
            $status = self::get_confirmed_litespeed_transport_status(true);
        }

        if (empty($status['serverDetected']) && empty($status['nativeHeaderAvailable'])) {
            return array(
                'success' => false,
                'message' => __('A LiteSpeed origin or active LiteSpeed cache response has not been confirmed for native public site-cache purge.', 'ultracache'),
                'transport' => 'signed_internal_control',
                'operation' => 'site',
                'controlScopeCount' => 0,
                'processedControlScopeCount' => 0,
            );
        }

        $origins = self::get_litespeed_control_origins();
        if (empty($origins)) {
            return array(
                'success' => false,
                'message' => __('No trusted public LiteSpeed cache origin could be resolved for site purge.', 'ultracache'),
                'transport' => 'signed_internal_control',
                'operation' => 'site',
                'controlScopeCount' => 0,
                'processedControlScopeCount' => 0,
            );
        }

        $results = array();
        $processed = 0;
        foreach ($origins as $origin) {
            $result = self::dispatch_litespeed_control_request('site', array(), $origin . '/');
            $results[] = $result;
            if (!empty($result['success'])) {
                ++$processed;
            }
        }

        $success = $processed === count($origins);
        return array(
            'success' => $success,
            'partial' => $processed > 0 && !$success,
            'message' => $success
                ? __('LiteSpeed public site-cache purge was dispatched through every trusted public cache origin.', 'ultracache')
                : __('One or more LiteSpeed public cache origins failed site-cache purge.', 'ultracache'),
            'transport' => 'signed_internal_control',
            'operation' => 'site',
            'targetCount' => count($origins),
            'processedCount' => $processed,
            'controlScopeCount' => count($origins),
            'processedControlScopeCount' => $processed,
            'controlOrigins' => $origins,
            'batches' => $results,
        );
    }

    /**
     * Dispatch exact LiteSpeed invalidation for local URLs.
     *
     * @param array $urls URL candidates.
     * @return array<string,mixed>
     */
    public static function purge_litespeed_urls(array $urls, $stale = null, $context = 'exact-invalidation')
    {
        $query_policy = self::get_litespeed_query_cache_policy();
        $query_policy_fingerprint = (string) ($query_policy['fingerprint'] ?? '');
        $query_key_proof = self::get_litespeed_query_cache_key_proof();
        $query_key_proof_fingerprint = (string) ($query_key_proof['fingerprint'] ?? '');
        $partition = self::partition_litespeed_purge_urls($urls);
        $urls = array_values((array) ($partition['urls'] ?? array()));
        $operation_metadata = array(
            'inputUrlCount' => max(0, (int) ($partition['inputUrlCount'] ?? 0)),
            'eligibleUrlCount' => count($urls),
            'skippedQueryUrlCount' => max(0, (int) ($partition['skippedQueryUrlCount'] ?? 0)),
            'skippedInvalidUrlCount' => max(0, (int) ($partition['skippedInvalidUrlCount'] ?? 0)),
            'skippedLimitUrlCount' => max(0, (int) ($partition['skippedLimitUrlCount'] ?? 0)),
            'queryUrlHandling' => 'bypass-skip',
            'queryPolicyFingerprint' => $query_policy_fingerprint,
            'queryKeyProofFingerprint' => $query_key_proof_fingerprint,
        );
        if (null === $stale) {
            $settings = self::get_dashboard_settings();
            $stale = self::is_litespeed_stale_invalidation_enabled($settings);
        }
        $stale = (bool) $stale;
        $context = sanitize_key((string) $context);
        if ('' === $context) {
            $context = $stale ? 'stale-exact-invalidation' : 'exact-invalidation';
        }
        $operation = $stale ? 'stale-urls' : 'urls';
        if (empty($urls)) {
            return array_merge($operation_metadata, array(
                'success' => true,
                'skipped' => true,
                'message' => !empty($operation_metadata['skippedQueryUrlCount'])
                    ? __('LiteSpeed bypasses query-string requests, so no native query URL invalidation or base-URL purge was dispatched.', 'ultracache')
                    : __('No eligible LiteSpeed URLs required invalidation.', 'ultracache'),
                'targetCount' => 0,
                'processedCount' => 0,
            ));
        }

        $status = method_exists(static::class, 'get_confirmed_litespeed_transport_status')
            ? self::get_confirmed_litespeed_transport_status(true)
            : self::get_litespeed_transport_status(
                isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '',
                method_exists(__CLASS__, 'get_reverse_proxy_status') ? self::get_reverse_proxy_status() : array()
            );

        if (empty($status['serverDetected']) && empty($status['nativeHeaderAvailable'])) {
            $result = array_merge($operation_metadata, array(
                'success' => false,
                'message' => __('A LiteSpeed origin or active LiteSpeed cache response has not been confirmed for exact URL invalidation.', 'ultracache'),
                'transport' => 'signed_internal_control',
                'operation' => $operation,
                'stale' => $stale,
                'targetCount' => count($urls),
                'processedCount' => 0,
            ));
            if (method_exists(static::class, 'record_litespeed_purge_result')) {
                self::record_litespeed_purge_result('urls', $result, count($urls), $context);
            }
            return $result;
        }

        $grouped_urls = array();
        foreach ($urls as $url) {
            $origin = self::get_litespeed_control_origin_for_url($url);
            if ('' === $origin) {
                continue;
            }
            if (!isset($grouped_urls[$origin])) {
                $grouped_urls[$origin] = array();
            }
            $grouped_urls[$origin][] = $url;
        }

        $results = array();
        $success = !empty($grouped_urls);
        $processed = 0;
        foreach ($grouped_urls as $origin => $origin_urls) {
            foreach (array_chunk($origin_urls, self::get_litespeed_control_url_limit()) as $chunk) {
                $result = self::dispatch_litespeed_control_request($operation, $chunk, (string) reset($chunk));
                $results[] = $result;
                $success = $success && !empty($result['success']);
                if (!empty($result['success'])) {
                    $processed += (int) ($result['targetCount'] ?? count($chunk));
                }
            }
        }

        $result = array_merge($operation_metadata, array(
            'success' => $success,
            'partial' => $processed > 0 && $processed < count($urls),
            'message' => $success
                ? ($stale
                    ? __('LiteSpeed stale exact URL invalidation headers were dispatched through signed internal control responses.', 'ultracache')
                    : __('LiteSpeed exact URL invalidation headers were dispatched through signed internal control responses.', 'ultracache'))
                : __('One or more LiteSpeed exact URL invalidation requests failed.', 'ultracache'),
            'transport' => 'signed_internal_control',
            'operation' => $operation,
            'stale' => $stale,
            'targetCount' => count($urls),
            'processedCount' => $processed,
            'controlScopeCount' => count($grouped_urls),
            'controlOrigins' => array_values(array_keys($grouped_urls)),
            'batches' => $results,
        ));
        if (method_exists(static::class, 'record_litespeed_purge_result')) {
            self::record_litespeed_purge_result('urls', $result, count($urls), $context);
        }
        return $result;
    }

    /**
     * Invalidate semantic LiteSpeed tags through the signed native transport.
     *
     * @param array  $tags    Semantic tag candidates.
     * @param bool   $stale   Whether stale invalidation is requested.
     * @param string $context Diagnostic source.
     * @return array<string,mixed>
     */
    public static function purge_litespeed_tags(array $tags, $stale = false, $context = 'semantic-invalidation')
    {
        $tags = self::prepare_litespeed_purge_tags($tags);
        $stale = (bool) $stale;
        $context = sanitize_key((string) $context);
        if (empty($tags)) {
            return array('success' => true, 'skipped' => true, 'targetCount' => 0, 'processedCount' => 0, 'message' => __('No LiteSpeed semantic tags required invalidation.', 'ultracache'));
        }

        $status = method_exists(static::class, 'get_confirmed_litespeed_transport_status')
            ? self::get_confirmed_litespeed_transport_status(true)
            : array();
        if (empty($status['serverDetected']) && empty($status['nativeHeaderAvailable'])) {
            $result = array(
                'success' => false,
                'message' => __('A LiteSpeed origin or active LiteSpeed cache response has not been confirmed for semantic tag invalidation.', 'ultracache'),
                'transport' => 'signed_internal_control',
                'operation' => $stale ? 'stale-tags' : 'tags',
                'stale' => $stale,
                'targetCount' => count($tags),
                'processedCount' => 0,
            );
            if (method_exists(static::class, 'record_litespeed_purge_result')) {
                self::record_litespeed_purge_result('tags', $result, count($tags), $context);
            }
            return $result;
        }

        $operation = $stale ? 'stale-tags' : 'tags';
        $origins = self::get_litespeed_control_origins();
        $results = array();
        $successful_scopes = 0;
        foreach ($origins as $origin) {
            $scope_success = true;
            foreach (array_chunk($tags, self::get_litespeed_control_url_limit()) as $chunk) {
                $result = self::dispatch_litespeed_control_request($operation, $chunk, $origin . '/');
                $results[] = $result;
                $scope_success = $scope_success && !empty($result['success']);
            }
            if ($scope_success) {
                ++$successful_scopes;
            }
        }
        $success = !empty($origins) && $successful_scopes === count($origins);
        $processed = $success ? count($tags) : 0;

        $result = array(
            'success' => $success,
            'partial' => $successful_scopes > 0 && !$success,
            'message' => $success
                ? ($stale ? __('LiteSpeed stale semantic tag invalidation completed.', 'ultracache') : __('LiteSpeed semantic tag invalidation completed.', 'ultracache'))
                : __('One or more LiteSpeed semantic tag invalidation requests failed.', 'ultracache'),
            'transport' => 'signed_internal_control',
            'operation' => $operation,
            'stale' => $stale,
            'targetCount' => count($tags),
            'processedCount' => $processed,
            'controlScopeCount' => count($origins),
            'processedControlScopeCount' => $successful_scopes,
            'controlOrigins' => array_values($origins),
            'batches' => $results,
            'context' => $context,
        );
        if (method_exists(static::class, 'record_litespeed_purge_result')) {
            self::record_litespeed_purge_result('tags', $result, count($tags), $context);
        }
        return $result;
    }

    /**
     * Invalidate the native LiteSpeed site cache after an UltraCache full purge.
     *
     * @param array $payload Purge context.
     * @return array<string,mixed>
     */
    public function handle_litespeed_after_purge_all($payload = array())
    {
        unset($payload);

        if (!self::is_native_litespeed_html_cache_enabled()) {
            return array('success' => true, 'skipped' => true, 'message' => __('Native LiteSpeed HTML Cache is disabled.', 'ultracache'));
        }

        $server_software = isset($_SERVER['SERVER_SOFTWARE'])
            ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))
            : '';
        $reverse_proxy = method_exists(__CLASS__, 'get_reverse_proxy_status') ? self::get_reverse_proxy_status() : array();
        $status = self::get_litespeed_transport_status($server_software, $reverse_proxy);

        $result = self::dispatch_litespeed_site_purge($status);
        if (method_exists(static::class, 'record_litespeed_purge_result')) {
            self::record_litespeed_purge_result('site', $result, max(1, (int) ($result['controlScopeCount'] ?? 1)), 'ultracache-purge-all');
        }
        return $result;
    }

    /**
     * Invalidate exact native LiteSpeed URLs after UltraCache URL purges.
     *
     * @param array  $urls    Purged URLs.
     * @param string $scope   Purge scope.
     * @param array  $payload Purge context.
     * @return array<string,mixed>
     */
    public function handle_litespeed_after_purge_urls($urls, $scope = 'batch', $payload = array())
    {
        $payload = is_array($payload) ? $payload : array();

        if (!self::is_native_litespeed_html_cache_enabled()) {
            return array('success' => true, 'skipped' => true, 'message' => __('Native LiteSpeed HTML Cache is disabled.', 'ultracache'));
        }

        $urls = is_array($urls) ? $urls : array();
        $settings = self::get_dashboard_settings();
        $stale = self::is_litespeed_stale_invalidation_enabled($settings);
        $scope = sanitize_key((string) $scope);
        if ('' === $scope) {
            $scope = 'batch';
        }
        $shared_warm_owns_refill = !empty($payload['litespeed_refill_managed_by_shared_warm']);
        $refill = 'lcp-refresh' !== $scope
            && !$shared_warm_owns_refill
            && method_exists(static::class, 'should_refill_after_targeted_litespeed_invalidation')
            && self::should_refill_after_targeted_litespeed_invalidation();

        if (method_exists(static::class, 'enqueue_litespeed_invalidation_urls')) {
            $result = self::enqueue_litespeed_invalidation_urls(
                $urls,
                $stale,
                $scope,
                $refill
            );
        } else {
            $result = array(
                'success' => false,
                'queued' => false,
                'failedUrlCount' => count($urls),
                'failedUrls' => $urls,
                'message' => __('Persistent LiteSpeed invalidation queue integration is unavailable.', 'ultracache'),
            );
        }

        // Queue storage is the normal targeted path. If persistence itself is
        // unavailable, fall back synchronously for the unpersisted subset so a
        // storage failure cannot leave known stale LiteSpeed objects until TTL.
        $failed_urls = array_values((array) ($result['failedUrls'] ?? array()));
        if (!empty($failed_urls)) {
            $fallback = self::purge_litespeed_urls(
                $failed_urls,
                $stale,
                $stale ? 'queue-storage-fallback-stale' : 'queue-storage-fallback-hard'
            );
            $result['fallback'] = $fallback;
            $fallback_processed = max(0, (int) ($fallback['processedCount'] ?? 0));
            if (!empty($fallback['success'])) {
                $result['fallbackProcessedUrlCount'] = $fallback_processed;
                $result['failedUrlCount'] = max(0, (int) ($result['failedUrlCount'] ?? 0) - $fallback_processed);
                $result['success'] = 0 === (int) $result['failedUrlCount'];
            }
        }

        $result['refillRequested'] = $refill;
        $result['refillManagedBySharedWarm'] = $shared_warm_owns_refill;
        $result['refillMode'] = $refill
            ? 'after-durable-invalidation'
            : ($shared_warm_owns_refill ? 'shared-warm-pipeline' : 'none');
        return $result;
    }
}
