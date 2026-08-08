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
        $url = trim((string) $url);
        if ('' === $url || self::litespeed_url_has_nonempty_query($url)) {
            return '';
        }

        if (0 === strpos($url, '/') && 0 !== strpos($url, '//')) {
            $home_origin = wp_parse_url((string) home_url('/'));
            if (!is_array($home_origin) || empty($home_origin['scheme']) || empty($home_origin['host'])) {
                return '';
            }

            $origin = strtolower((string) $home_origin['scheme']) . '://' . strtolower((string) $home_origin['host']);
            if (!empty($home_origin['port'])) {
                $origin .= ':' . (int) $home_origin['port'];
            }
            $url = $origin . '/' . ltrim($url, '/');
        }

        $normalized = function_exists('ultracache_normalize_public_url')
            ? ultracache_normalize_public_url($url, array('strip_query' => false, 'strip_fragment' => true))
            : esc_url_raw(preg_replace('/#.*$/', '', $url));
        $normalized = is_string($normalized) ? $normalized : '';
        if ('' === $normalized || self::litespeed_url_has_nonempty_query($normalized)) {
            return '';
        }

        $home = wp_parse_url((string) home_url('/'));
        $target = wp_parse_url($normalized);
        if (!is_array($home) || !is_array($target)) {
            return '';
        }

        $home_host = strtolower((string) ($home['host'] ?? ''));
        $target_host = strtolower((string) ($target['host'] ?? ''));
        $home_port = isset($home['port']) ? (int) $home['port'] : 0;
        $target_port = isset($target['port']) ? (int) $target['port'] : 0;
        if ('' === $home_host || !hash_equals($home_host, $target_host) || $home_port !== $target_port) {
            return '';
        }

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
            'ultracache-litespeed-control-v1',
            sanitize_key((string) $operation),
            strtolower((string) $request_id),
            (string) ((int) $expires),
            hash('sha256', $encoded_urls),
            (string) home_url('/'),
            (string) get_current_blog_id(),
        ));

        return hash_hmac('sha256', $message, wp_salt('auth'));
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
            !in_array($operation, array('site', 'urls', 'stale-urls'), true)
            || 1 !== preg_match('/^[a-f0-9-]{32,36}$/', $request_id)
            || $expires < ($now - 5)
            || $expires > ($now + 180)
            || 1 !== preg_match('/^[a-f0-9]{64}$/', $signature)
        ) {
            return false;
        }

        $prepared_urls = self::prepare_litespeed_purge_urls($urls, self::get_litespeed_control_url_limit());
        if ('site' === $operation && !empty($prepared_urls)) {
            return false;
        }
        if (in_array($operation, array('urls', 'stale-urls'), true) && empty($prepared_urls)) {
            return false;
        }

        $expected = self::get_litespeed_control_signature($operation, $prepared_urls, $request_id, $expires);
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        return self::claim_litespeed_control_request($request_id, $expires);
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
        $prepared_urls = self::prepare_litespeed_purge_urls($urls, self::get_litespeed_control_url_limit());
        $tags = array();

        if ('site' === $operation) {
            $site_tag = function_exists('ultracache_get_litespeed_site_tag')
                ? ultracache_get_litespeed_site_tag()
                : '';
            if ('' !== $site_tag) {
                $tags[] = $site_tag;
            }
        } elseif (in_array($operation, array('urls', 'stale-urls'), true)) {
            foreach ($prepared_urls as $url) {
                $tag = function_exists('ultracache_get_litespeed_url_tag')
                    ? ultracache_get_litespeed_url_tag($url)
                    : '';
                if ('' !== $tag) {
                    $tags[$tag] = $tag;
                }
            }
            $tags = array_values($tags);
        }

        if (empty($tags)) {
            return array(
                'success' => false,
                'message' => __('No valid LiteSpeed purge targets were resolved.', 'ultracache'),
                'purgeHeader' => '',
                'operation' => $operation,
                'targetCount' => 0,
            );
        }

        $parts = array('public');
        if ('stale-urls' === $operation) {
            $parts[] = 'stale';
        }
        foreach ($tags as $tag) {
            if (1 === preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', (string) $tag)) {
                $parts[] = 'tag=' . (string) $tag;
            }
        }

        if (count($parts) < 2) {
            return array(
                'success' => false,
                'message' => __('No valid LiteSpeed purge tags were resolved.', 'ultracache'),
                'purgeHeader' => '',
                'operation' => $operation,
                'targetCount' => 0,
            );
        }

        return array(
            'success' => true,
            'message' => 'site' === $operation
                ? __('LiteSpeed site-tag purge response prepared.', 'ultracache')
                : ('stale-urls' === $operation
                    ? __('LiteSpeed stale exact URL-tag purge response prepared.', 'ultracache')
                    : __('LiteSpeed exact URL-tag purge response prepared.', 'ultracache')),
            'purgeHeader' => implode(',', $parts),
            'operation' => $operation,
            'targetCount' => count($tags),
            'urls' => in_array($operation, array('urls', 'stale-urls'), true) ? $prepared_urls : array(),
        );
    }

    /**
     * Dispatch one signed, blocking same-site purge request.
     *
     * @param string $operation Purge operation.
     * @param array  $urls      Normalized URLs.
     * @return array<string,mixed>
     */
    private static function dispatch_litespeed_control_request($operation, array $urls = array())
    {
        $operation = sanitize_key((string) $operation);
        $urls = self::prepare_litespeed_purge_urls($urls, self::get_litespeed_control_url_limit());
        if ('site' === $operation) {
            $urls = array();
        } elseif (!in_array($operation, array('urls', 'stale-urls'), true) || empty($urls)) {
            return array(
                'success' => false,
                'message' => __('No valid LiteSpeed purge targets were supplied.', 'ultracache'),
                'transport' => 'signed_internal_control',
                'operation' => $operation,
            );
        }

        $request_id = strtolower(wp_generate_uuid4());
        $expires = time() + 120;
        $signature = self::get_litespeed_control_signature($operation, $urls, $request_id, $expires);
        $url = rest_url('ultracache/v1/litespeed/control');
        $request_args = array(
            'method' => 'POST',
            'timeout' => 10,
            'blocking' => true,
            'redirection' => 0,
            'headers' => array(
                'Cache-Control' => 'no-cache',
                'X-UltraCache-Internal' => 'litespeed-control',
            ),
            'body' => array(
                'operation' => $operation,
                'urls' => $urls,
                'requestId' => $request_id,
                'expires' => $expires,
                'signature' => $signature,
            ),
        );
        $response = function_exists('ultracache_safe_loopback_remote_request')
            ? ultracache_safe_loopback_remote_request($url, $request_args, 'litespeed-control')
            : wp_remote_post($url, $request_args);

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
                'targetCount' => count($urls),
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
            'targetCount' => (int) ($body['targetCount'] ?? count($urls)),
            'httpStatus' => $status_code,
            'acknowledged' => $acknowledged,
        );
    }

    /**
     * Dispatch an UltraCache-owned site-tag purge.
     *
     * @param array $status Optional current transport status.
     * @return array<string,mixed>
     */
    private static function dispatch_litespeed_site_purge(array $status = array())
    {
        if (empty($status['serverDetected']) && empty($status['nativeHeaderAvailable'])) {
            return array(
                'success' => false,
                'message' => __('A LiteSpeed origin or active LiteSpeed cache response has not been confirmed for native site-tag purge.', 'ultracache'),
                'transport' => 'signed_internal_control',
                'operation' => 'site',
            );
        }

        return self::dispatch_litespeed_control_request('site');
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
            $stale = !empty($settings['liteSpeedStalePurgeEnabled']);
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

        $server_software = isset($_SERVER['SERVER_SOFTWARE'])
            ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))
            : '';
        $reverse_proxy = method_exists(__CLASS__, 'get_reverse_proxy_status') ? self::get_reverse_proxy_status() : array();
        $status = self::get_litespeed_transport_status($server_software, $reverse_proxy);

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

        $results = array();
        $success = true;
        $processed = 0;
        foreach (array_chunk($urls, self::get_litespeed_control_url_limit()) as $chunk) {
            $result = self::dispatch_litespeed_control_request($operation, $chunk);
            $results[] = $result;
            $success = $success && !empty($result['success']);
            if (!empty($result['success'])) {
                $processed += (int) ($result['targetCount'] ?? count($chunk));
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
            'batches' => $results,
        ));
        if (method_exists(static::class, 'record_litespeed_purge_result')) {
            self::record_litespeed_purge_result('urls', $result, count($urls), $context);
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
            self::record_litespeed_purge_result('site', $result, 1, 'ultracache-purge-all');
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
        unset($payload);

        if (!self::is_native_litespeed_html_cache_enabled()) {
            return array('success' => true, 'skipped' => true, 'message' => __('Native LiteSpeed HTML Cache is disabled.', 'ultracache'));
        }

        $urls = is_array($urls) ? $urls : array();
        $partition = self::partition_litespeed_purge_urls($urls);
        $litespeed_urls = array_values((array) ($partition['urls'] ?? array()));
        $settings = self::get_dashboard_settings();
        $result = self::purge_litespeed_urls(
            $urls,
            !empty($settings['liteSpeedStalePurgeEnabled']),
            !empty($settings['liteSpeedStalePurgeEnabled']) ? 'targeted-stale-invalidation' : 'exact-invalidation'
        );
        $scope = sanitize_key((string) $scope);
        if (!empty($result['success']) && 'lcp-refresh' !== $scope) {
            $pipeline_queue = !empty($litespeed_urls)
                && method_exists(static::class, 'should_refill_after_targeted_litespeed_invalidation')
                && self::should_refill_after_targeted_litespeed_invalidation()
                && method_exists(static::class, 'enqueue_targeted_warm_pipeline_urls')
                ? self::enqueue_targeted_warm_pipeline_urls($litespeed_urls, false, '' !== $scope ? $scope : 'litespeed-targeted')
                : array('success' => true, 'queued' => false, 'queuedUrlCount' => 0);
            $result['refillQueued'] = !empty($pipeline_queue['queued']);
            $result['refillQueuedUrlCount'] = max(0, (int) ($pipeline_queue['queuedUrlCount'] ?? 0));
            $result['refillSkippedQueryUrlCount'] = max(0, (int) ($partition['skippedQueryUrlCount'] ?? 0));
            $result['refillMode'] = !empty($pipeline_queue['queued']) ? 'shared-page-warm-pipeline' : 'none';
            if (!empty($pipeline_queue['message'])) {
                $result['message'] .= ' ' . (string) $pipeline_queue['message'];
            }
        }

        return $result;
    }
}
