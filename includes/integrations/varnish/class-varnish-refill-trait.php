<?php
/**
 * Affected-page Varnish refill and manual prewarm helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Refill_Trait
{
    /**
     * Whether successful targeted invalidations should enqueue affected-page refills.
     *
     * @return bool
     */
    private static function should_refill_after_targeted_varnish_invalidation()
    {
        $settings = self::get_dashboard_settings();
        if (!self::is_varnish_runtime_enabled($settings)) {
            return false;
        }

        $varnish_settings = self::get_varnish_cli_settings();
        $strategy = self::get_varnish_invalidation_strategy_status($varnish_settings);
        return !empty($varnish_settings['refillAfterTargetedInvalidation'])
            || 'soft' === (string) ($strategy['effective'] ?? '');
    }

    /**
     * Whether any site warm-up path should populate Varnish for the same page.
     *
     * Dashboard manual jobs, cron jobs, and warm-after-flush jobs share the
     * central Automation & Scheduling pipeline. Varnish joins automatically
     * when its exact runtime path is currently verified.
     *
     * @return bool
     */
    private static function should_warm_varnish_during_site_warmup()
    {
        $settings = self::get_dashboard_settings();
        $varnish_settings = self::get_varnish_cli_settings();
        if (!self::is_varnish_runtime_enabled($settings) || empty($varnish_settings['warmWithSiteWarmup'])) {
            return false;
        }

        $runtime_plan = self::plan_varnish_runtime_operation('targeted', array(
            'strategyOverride' => 'hard',
        ));

        return !empty($runtime_plan['canExecute']);
    }

    /**
     * Expose the shared switch decision to the cron/site warm orchestrator.
     *
     * @return bool
     */
    public static function should_include_varnish_in_site_warmup()
    {
        return self::should_warm_varnish_during_site_warmup();
    }

    /**
     * Return the Varnish warm plan shared by every site warm-up path.
     *
     * @return array
     */
    public static function get_site_warm_varnish_plan()
    {
        if (!self::should_warm_varnish_during_site_warmup()) {
            $settings = self::get_dashboard_settings();
            $runtime_plan = self::plan_varnish_runtime_operation('targeted', array(
                'strategyOverride' => 'hard',
            ));
            $varnish_settings = self::get_varnish_cli_settings();
            $message = !self::is_varnish_runtime_enabled($settings) || empty($varnish_settings['warmWithSiteWarmup'])
                ? self::maybe_translate('Varnish warm-up is unavailable because the Varnish connection is not active.')
                : self::maybe_translate('Varnish site warm-up is paused because exact invalidation is not verified for every configured endpoint.');
            if (!empty($runtime_plan['reason'])) {
                $message .= ' ' . (string) $runtime_plan['reason'];
            }

            return array(
                'enabled' => false,
                'buckets' => array(),
                'runtimeOutcome' => sanitize_key((string) ($runtime_plan['plannedOutcome'] ?? 'unsupported')),
                'runtimeStrategy' => sanitize_key((string) ($runtime_plan['selectedStrategy'] ?? 'none')),
                'message' => self::sanitize_varnish_string($message),
            );
        }

        $settings = self::get_varnish_cli_settings();
        if (empty($settings['support']['available']) || empty($settings['servers'])) {
            return array(
                'enabled' => false,
                'buckets' => array(),
                'message' => self::maybe_translate('Varnish is enabled but no usable endpoint is configured.'),
            );
        }

        $dashboard_settings = self::get_dashboard_settings();
        $policy = function_exists('ultracache_get_html_variant_policy')
            ? ultracache_get_html_variant_policy($dashboard_settings)
            : array('buckets' => array('orig'));
        $buckets = array_values(array_intersect(array('orig', 'webp', 'avif'), (array) ($policy['buckets'] ?? array('orig'))));
        if (empty($buckets)) {
            $buckets = array('orig');
        }

        return array(
            'enabled' => true,
            'buckets' => $buckets,
            'message' => self::maybe_translate_sprintf('Site warm-up will populate %d Varnish HTML variant(s).', count($buckets)),
        );
    }

    /**
     * Remove the current public object before a site warm refills Varnish.
     *
     * @param string $url     Canonical local page URL.
     * @param string $context Warm source.
     * @return array
     */
    private static function invalidate_varnish_before_warm_refill($url, $context = 'manual')
    {
        $url = esc_url_raw((string) $url);
        $context = sanitize_key((string) $context);
        if ('' === $url || !method_exists(static::class, 'varnish_invalidate_url_before_warm_refill')) {
            return array(
                'success' => false,
                'retryable' => false,
                'terminal' => true,
                'failureClass' => 'varnish-invalidation-unavailable',
                'message' => self::maybe_translate('Exact Varnish invalidation is unavailable before refill.'),
            );
        }

        $settings = self::get_varnish_cli_settings();
        $diagnostic_refill = 'diagnostic' === $context
            && method_exists(static::class, 'is_varnish_capability_probe_authorized')
            && self::is_varnish_capability_probe_authorized('refill', array(
                'strategy' => 'refill',
                'requestedScope' => 'html-variants',
                'endpoints' => (array) ($settings['servers'] ?? array()),
                'urls' => array($url),
            ));
        $exact_probe_token = '';
        if ($diagnostic_refill) {
            $exact_probe_token = self::begin_varnish_capability_probe(array(
                'operation' => 'targeted',
                'strategy' => ('admin' === self::sanitize_varnish_mode($settings['mode'] ?? 'http')
                    || 'BAN' === strtoupper((string) ($settings['method'] ?? 'BAN')))
                    ? 'exact-ban'
                    : 'exact-purge',
                'requestedScope' => 'exact-url',
                'endpoints' => (array) ($settings['servers'] ?? array()),
                'urls' => array($url),
            ));
        }
        try {
            $result = self::varnish_invalidate_url_before_warm_refill($url, $context);
        } finally {
            if ('' !== $exact_probe_token) {
                self::end_varnish_capability_probe($exact_probe_token);
            }
        }
        $url_results = is_array($result['urlResults'] ?? null) ? $result['urlResults'] : array();
        $first_url_result = !empty($url_results) ? reset($url_results) : array();
        $url_result = isset($url_results[$url]) && is_array($url_results[$url])
            ? $url_results[$url]
            : (is_array($first_url_result) ? $first_url_result : array());
        $success = !empty($result['success']);
        $retryable = !$success && !empty($url_result['retryable']);

        $summary = $result;
        unset($summary['urlResults'], $summary['rejections']);

        return array(
            'success' => $success,
            'retryable' => $retryable,
            'terminal' => !$success && !$retryable,
            'failureClass' => $success ? '' : 'varnish-invalidation-failed',
            'message' => $success
                ? self::maybe_translate('Exact Varnish URL invalidation completed before refill.')
                : self::maybe_translate('Varnish refill stopped because exact URL invalidation failed.'),
            'requestCount' => max(0, (int) ($result['requestCount'] ?? 0)),
            'details' => $summary,
        );
    }

    /**
     * Queue eligible URLs for a persistent UltraCache rebuild and Varnish refill.
     *
     * @param array  $urls                     Candidate local URLs.
     * @param string $reason                   Refill source.
     * @param bool   $requires_verified_origin Whether the strict origin contract is required.
     * @param bool   $force                    Whether this bounded system refill bypasses the targeted-refill toggle.
     * @return array
     */
    private static function queue_varnish_refill_urls(array $urls, $reason = 'targeted-invalidation', $requires_verified_origin = false, $force = false)
    {
        $result = array(
            'success' => false,
            'queued' => false,
            'queuedUrlCount' => 0,
            'reason' => sanitize_key((string) $reason),
            'pipeline' => 'shared-page-warm',
        );
        if (!$force && !self::should_refill_after_targeted_varnish_invalidation()) {
            $result['message'] = self::maybe_translate('Affected-page Varnish refill is unavailable for the current connection or capability state.');
            return $result;
        }
        if (!method_exists(static::class, 'enqueue_targeted_warm_pipeline_urls')) {
            $result['message'] = self::maybe_translate('The shared page warm pipeline is unavailable.');
            return $result;
        }

        $queued = self::enqueue_targeted_warm_pipeline_urls(
            $urls,
            (bool) $requires_verified_origin,
            sanitize_key((string) $reason)
        );
        return array_merge($result, is_array($queued) ? $queued : array());
    }

    /**
     * Return request arguments for one public Varnish refill request.
     *
     * @param string $bucket             UltraCache HTML variant bucket.
     * @param bool   $authenticated_warm Whether to send the authenticated internal warm contract.
     * @return array
     */
    private static function get_varnish_refill_request_args($bucket, $authenticated_warm = true)
    {
        $bucket = in_array((string) $bucket, array('orig', 'webp', 'avif'), true) ? (string) $bucket : 'orig';
        $accept = function_exists('ultracache_get_accept_header_for_html_bucket')
            ? ultracache_get_accept_header_for_html_bucket($bucket)
            : 'text/html,application/xhtml+xml';

        $authenticated_warm = (bool) $authenticated_warm;
        $runtime_token = $authenticated_warm && function_exists('ultracache_create_runtime_control_token')
            ? ultracache_create_runtime_control_token()
            : '';
        $headers = array(
            'Accept' => $accept,
            'PageSpeed' => 'off',
            'ModPagespeed' => 'off',
        );
        if ($authenticated_warm) {
            $headers['X-UltraCache-Warm'] = '1';
            $headers['X-UltraCache-Internal-Request'] = '1';
            $headers['X-UltraCache-Token'] = $runtime_token;
        }

        return array(
            'method' => 'GET',
            'timeout' => function_exists('ultracache_get_php_max_execution_time_seconds')
                ? ultracache_get_php_max_execution_time_seconds()
                : max(0, (int) ini_get('max_execution_time')),
            'redirection' => 0,
            'user-agent' => 'Mozilla/5.0 (compatible; UltraCache-Varnish-Refill/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : 'unknown') . '; +https://wordpress.org)',
            'headers' => array_filter($headers),
        );
    }

    /**
     * Send one bounded public loopback request for a Varnish HTML variant.
     *
     * @param string $url                Public local URL.
     * @param string $bucket             UltraCache HTML variant bucket.
     * @param bool   $authenticated_warm Whether to use the authenticated warm request contract.
     * @return array|WP_Error
     */
    private static function send_single_varnish_refill_request($url, $bucket, $authenticated_warm = true)
    {
        $url = esc_url_raw((string) $url);
        if ('' === $url
            || !function_exists('ultracache_is_strict_frontend_loopback_url')
            || !ultracache_is_strict_frontend_loopback_url($url)) {
            return new WP_Error(
                'ultracache_invalid_varnish_refill_url',
                self::maybe_translate('The Varnish refill URL is not an exact trusted frontend URL for this site.')
            );
        }

        $authenticated_warm = (bool) $authenticated_warm;
        $args = self::get_varnish_refill_request_args($bucket, $authenticated_warm);
        if ($authenticated_warm) {
            $runtime_token = (string) ($args['headers']['X-UltraCache-Token'] ?? '');
            if ('' === $runtime_token) {
                return new WP_Error(
                    'ultracache_internal_auth_unavailable',
                    self::maybe_translate('Could not authenticate the internal Varnish refill request.')
                );
            }
        }

        $args['sslverify'] = !function_exists('ultracache_is_local_https_url') || !ultracache_is_local_https_url($url);

        return function_exists('ultracache_safe_loopback_remote_request')
            ? ultracache_safe_loopback_remote_request($url, $args, 'varnish_refill')
            : wp_safe_remote_get($url, $args);
    }

    /**
     * Resolve one trusted same-origin canonical redirect for a manual refill.
     *
     * @param string         $request_url Original refill URL.
     * @param array|WP_Error $response    WordPress HTTP API response.
     * @return string
     */
    private static function get_varnish_refill_canonical_redirect_url($request_url, $response)
    {
        if (is_wp_error($response)) {
            return '';
        }

        $response_code = (int) wp_remote_retrieve_response_code($response);
        if (!in_array($response_code, array(301, 302, 303, 307, 308), true)) {
            return '';
        }

        $location = trim((string) wp_remote_retrieve_header($response, 'location'));
        if ('' === $location) {
            return '';
        }
        if (class_exists('WP_Http') && method_exists('WP_Http', 'make_absolute_url')) {
            $location = WP_Http::make_absolute_url($location, (string) $request_url);
        }
        $location = esc_url_raw($location);
        if ('' === $location
            || !function_exists('ultracache_is_strict_frontend_loopback_url')
            || !ultracache_is_strict_frontend_loopback_url($location)) {
            return '';
        }

        return $location;
    }

    /**
     * Convert one public response into a conservative cache-classification summary.
     *
     * @param array|WP_Error $response WordPress HTTP API response.
     * @return array
     */
    private static function summarize_varnish_refill_response($response)
    {
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'httpCode' => 0,
                'status' => 'ERROR',
                'varnishDetected' => false,
                'confidence' => 'high',
                'evidence' => 'request-error',
                'errorCode' => sanitize_key((string) $response->get_error_code()),
                'detail' => sanitize_text_field($response->get_error_message()),
                'headers' => array(),
            );
        }

        $response_code = (int) wp_remote_retrieve_response_code($response);
        $headers = array(
            'via' => self::get_varnish_response_header($response, 'via'),
            'server' => self::get_varnish_response_header($response, 'server'),
            'xVarnish' => self::get_varnish_response_header($response, 'x-varnish'),
            'xVarnishCache' => self::get_varnish_response_header($response, 'x-varnish-cache'),
            'xCache' => self::get_varnish_response_header($response, 'x-cache'),
            'xCacheStatus' => self::get_varnish_response_header($response, 'x-cache-status'),
            'xProxyCache' => self::get_varnish_response_header($response, 'x-proxy-cache'),
            'age' => self::get_varnish_response_header($response, 'age'),
            'location' => self::get_varnish_response_header($response, 'location'),
            'ultraCache' => self::get_varnish_response_header($response, 'x-ultra-cache'),
            'ultraCacheSource' => self::get_varnish_response_header($response, 'x-ultra-cache-source'),
            'ultraCacheVariant' => self::get_varnish_response_header($response, 'x-ultracache-variant'),
            'ultraCacheCacheable' => self::get_varnish_response_header($response, 'x-ultracache-cacheable'),
            'ultraCachePageCacheable' => self::get_varnish_response_header($response, 'x-ultracache-page-cacheable'),
            'sharedCacheState' => self::get_varnish_response_header($response, 'x-ultracache-shared-cache-state'),
            'sharedCacheReason' => self::get_varnish_response_header($response, 'x-ultracache-shared-cache-reason'),
            'cacheControl' => self::get_varnish_response_header($response, 'cache-control'),
            'ultraCacheEsi' => self::get_varnish_response_header($response, 'x-ultracache-esi'),
            'ultraCacheEsiCount' => self::get_varnish_response_header($response, 'x-ultracache-esi-count'),
            'ultraCacheEsiUniqueCount' => self::get_varnish_response_header($response, 'x-ultracache-esi-unique-count'),
            'ultraCacheEsiTtlMin' => self::get_varnish_response_header($response, 'x-ultracache-esi-ttl-min'),
            'ultraCacheEsiTtlMax' => self::get_varnish_response_header($response, 'x-ultracache-esi-ttl-max'),
        );
        $classification = self::classify_varnish_response($headers, $response_code);
        $is_redirect = in_array($response_code, array(301, 302, 303, 307, 308), true);

        $esi_parent = 200 === $response_code && '1' === trim((string) $headers['ultraCacheEsi']);
        $esi_fragment_count = $esi_parent ? max(0, min(100, (int) $headers['ultraCacheEsiCount'])) : 0;
        $esi_unique_count = $esi_parent ? max(0, min($esi_fragment_count, (int) $headers['ultraCacheEsiUniqueCount'])) : 0;
        $esi_min_ttl = $esi_parent ? max(0, min(WEEK_IN_SECONDS, (int) $headers['ultraCacheEsiTtlMin'])) : 0;
        $esi_max_ttl = $esi_parent ? max($esi_min_ttl, min(WEEK_IN_SECONDS, (int) $headers['ultraCacheEsiTtlMax'])) : 0;
        $cookie_names = function_exists('ultracache_get_http_response_set_cookie_names')
            ? ultracache_get_http_response_set_cookie_names($response)
            : array();
        $cookie_policy = function_exists('ultracache_response_cookie_cache_policy')
            ? ultracache_response_cookie_cache_policy($cookie_names, self::get_settings())
            : array('decision' => empty($cookie_names) ? 'none' : 'reject', 'reason' => 'response-cookie-policy-unavailable');
        $ultra_status = strtoupper(trim((string) $headers['ultraCache']));
        $cache_control = strtolower((string) $headers['cacheControl']);
        $origin_public = !in_array($ultra_status, array('BYPASS', 'SKIP'), true)
            && 1 !== preg_match('/(?:^|[,\s])(private|no-store|no-cache)(?:$|[,=\s])/', $cache_control);
        $cookie_handoff_deferred = 200 === $response_code
            && !empty($cookie_names)
            && 'allow' === (string) ($cookie_policy['decision'] ?? '')
            && $origin_public
            && ('deferred-response-cookie' === sanitize_key((string) $headers['sharedCacheState'])
                || in_array($ultra_status, array('STORE', 'HIT'), true));
        $shared_cache_blocked = 200 === $response_code
            && !empty($cookie_names)
            && 'reject' === (string) ($cookie_policy['decision'] ?? '');

        return array(
            'success' => 200 === $response_code,
            'warning' => $shared_cache_blocked,
            'sharedCacheBlocked' => $shared_cache_blocked,
            'httpCode' => $response_code,
            'status' => $is_redirect ? 'REDIRECT' : (string) ($classification['status'] ?? 'INCONCLUSIVE'),
            'varnishDetected' => !empty($classification['varnishDetected']),
            'confidence' => $is_redirect ? 'high' : (string) ($classification['confidence'] ?? 'low'),
            'evidence' => $is_redirect ? 'redirect-refused' : (string) ($classification['evidence'] ?? 'none'),
            'errorCode' => '',
            'detail' => $is_redirect
                ? ('' !== trim((string) $headers['location'])
                    ? self::maybe_translate_sprintf('HTTP %1$d redirected to %2$s.', $response_code, trim((string) $headers['location']))
                    : self::maybe_translate_sprintf('HTTP %d redirected without a Location header.', $response_code))
                : 'HTTP ' . $response_code,
            'headers' => $headers,
            'esiParent' => $esi_parent,
            'esiFragmentCount' => $esi_fragment_count,
            'esiUniqueFragmentCount' => $esi_unique_count,
            'esiMinTtl' => $esi_min_ttl,
            'esiMaxTtl' => $esi_max_ttl,
            'setCookieNames' => $cookie_names,
            'responseCookiePolicy' => $cookie_policy,
            'cookieHandoffDeferred' => $cookie_handoff_deferred,
            'sharedCacheState' => sanitize_key((string) $headers['sharedCacheState']),
        );
    }

    /**
     * Renew the owning warm pipeline before or after a Varnish request.
     *
     * @param callable|null $heartbeat Internal ownership callback.
     * @param string        $stage     Current request stage.
     * @param string        $bucket    HTML variant bucket.
     * @return bool
     */
    private static function invoke_varnish_refill_heartbeat($heartbeat, $stage, $bucket = '')
    {
        if (!is_callable($heartbeat)) {
            return true;
        }

        $heartbeat_stage = sanitize_key((string) $stage);
        $bucket = sanitize_key((string) $bucket);
        if ('' !== $bucket) {
            $heartbeat_stage .= '-' . $bucket;
        }
        try {
            return false !== call_user_func($heartbeat, $heartbeat_stage);
        } catch (Throwable $error) {
            unset($error);
            return false;
        }
    }

    /**
     * Whether one failed WordPress HTTP response represents a transient condition.
     *
     * @param array $summary Normalized refill response.
     * @return bool
     */
    private static function is_varnish_refill_failure_retryable(array $summary)
    {
        $error_code = sanitize_key((string) ($summary['errorCode'] ?? ''));
        if (in_array($error_code, array('ultracache_invalid_varnish_refill_url', 'ultracache_internal_auth_unavailable', 'ultracache_untrusted_loopback_url'), true)) {
            return false;
        }

        $http_code = (int) ($summary['httpCode'] ?? 0);
        return 0 === $http_code
            || 408 === $http_code
            || 425 === $http_code
            || 429 === $http_code
            || $http_code >= 500;
    }

    /**
     * Send one public refill request and retry only transient transport/server
     * failures. A successful or terminal HTTP response is never repeated.
     *
     * @param string $url                Public local URL.
     * @param string $bucket             HTML variant bucket.
     * @param bool   $authenticated_warm Whether to use the authenticated warm request contract.
     * @return array
     */
    private static function send_varnish_refill_request_with_transient_retry($url, $bucket, $authenticated_warm = true)
    {
        $max_attempts = (int) apply_filters('ultracache_varnish_refill_max_attempts', 3, $url, $bucket);
        $max_attempts = max(1, min(4, $max_attempts));
        $attempts = array();
        $summary = array();

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $summary = self::summarize_varnish_refill_response(self::send_single_varnish_refill_request($url, $bucket, $authenticated_warm));
            $summary['attempt'] = $attempt;
            $summary['retryable'] = empty($summary['success']) && self::is_varnish_refill_failure_retryable($summary);
            $attempts[] = $summary;

            if (!empty($summary['success']) || empty($summary['retryable']) || $attempt >= $max_attempts) {
                break;
            }

            usleep(150000 * $attempt);
        }

        return array(
            'summary' => $summary,
            'attempts' => $attempts,
            'attemptCount' => count($attempts),
        );
    }

    /**
     * Return the actual terminal/transient class for one failed refill.
     *
     * @param array $summary Normalized refill response.
     * @return string
     */
    private static function get_varnish_refill_failure_class(array $summary)
    {
        $error_code = sanitize_key((string) ($summary['errorCode'] ?? ''));
        if ('' !== $error_code) {
            return 'varnish-' . $error_code;
        }

        $http_code = max(0, (int) ($summary['httpCode'] ?? 0));
        if ($http_code > 0) {
            return 'varnish-http-' . $http_code;
        }

        return !empty($summary['retryable']) ? 'varnish-http-transient' : 'varnish-http-terminal';
    }

    /**
     * Add one stable high-level refill result state while preserving the
     * existing boolean fields used by the queue and warm pipeline.
     *
     * @param array $result Refill result.
     * @return array
     */
    private static function normalize_varnish_refill_result_state(array $result)
    {
        if (!empty($result['skipped'])) {
            $result['resultStatus'] = 'not_applicable';
        } elseif (!empty($result['success']) && !empty($result['warning'])) {
            $result['resultStatus'] = 'warning';
        } elseif (!empty($result['success'])) {
            $result['resultStatus'] = 'success';
        } elseif (!empty($result['retryable'])) {
            $result['resultStatus'] = 'retryable_error';
        } else {
            $result['resultStatus'] = 'terminal_error';
        }

        return $result;
    }

    /**
     * Send one public refill request for every active UltraCache HTML variant.
     *
     * @param string        $url       Local public URL.
     * @param callable|null $heartbeat Internal ownership callback.
     * @return array
     */
    private static function send_public_varnish_refill_requests($url, $heartbeat = null)
    {
        $url = esc_url_raw((string) $url);
        if ('' === $url) {
            return self::normalize_varnish_refill_result_state(array(
                'success' => false,
                'retryable' => false,
                'terminal' => true,
                'message' => self::maybe_translate('Invalid Varnish refill URL.'),
                'details' => array(),
            ));
        }

        $settings = self::get_dashboard_settings();
        $policy = function_exists('ultracache_get_html_variant_policy')
            ? ultracache_get_html_variant_policy($settings)
            : array('buckets' => array('orig'));
        $buckets = array_values(array_intersect(array('orig', 'webp', 'avif'), (array) ($policy['buckets'] ?? array('orig'))));
        if (empty($buckets)) {
            $buckets = array('orig');
        }

        $details = array();
        $all_ok = true;
        $refilled_count = 0;
        $retryable_failure_count = 0;
        $terminal_failure_count = 0;
        $warning_count = 0;
        $esi_parent_variant_count = 0;
        $esi_fragment_reference_count = 0;
        $esi_unique_fragment_reference_count = 0;
        $esi_min_ttl = 0;
        $esi_max_ttl = 0;

        foreach ($buckets as $bucket) {
            if (!self::invoke_varnish_refill_heartbeat($heartbeat, 'varnish-refill-before', $bucket)) {
                return self::normalize_varnish_refill_result_state(array(
                    'success' => false,
                    'retryable' => true,
                    'terminal' => false,
                    'ownershipLost' => true,
                    'failureClass' => 'ownership-lost',
                    'message' => self::maybe_translate('Warm-up ownership expired before the Varnish refill request completed.'),
                    'variantCount' => count($details),
                    'refilledCount' => $refilled_count,
                    'details' => $details,
                ));
            }

            $request_result = self::send_varnish_refill_request_with_transient_retry($url, $bucket);
            $refill = is_array($request_result['summary'] ?? null) ? $request_result['summary'] : array();
            $refill_attempts = is_array($request_result['attempts'] ?? null) ? $request_result['attempts'] : array();
            $request_count = max(1, (int) ($request_result['attemptCount'] ?? count($refill_attempts)));
            $cookie_handoff_required = !empty($refill['cookieHandoffDeferred']);
            $cookie_handoff_cookie_names = array_values((array) ($refill['setCookieNames'] ?? array()));
            $cookie_handoff_policy_reason = sanitize_key((string) (($refill['responseCookiePolicy']['reason'] ?? '')));
            $cookie_handoff_completed = false;
            $cookie_handoff_verified = false;
            $cookie_handoff_verification_status = '';

            if (!empty($refill['success']) && $cookie_handoff_required) {
                if (!self::invoke_varnish_refill_heartbeat($heartbeat, 'varnish-cookie-handoff-before', $bucket)) {
                    return self::normalize_varnish_refill_result_state(array(
                        'success' => false,
                        'retryable' => true,
                        'terminal' => false,
                        'ownershipLost' => true,
                        'failureClass' => 'ownership-lost',
                        'message' => self::maybe_translate('Warm-up ownership expired before the clean Varnish cookie handoff completed.'),
                        'variantCount' => count($details),
                        'refilledCount' => $refilled_count,
                        'details' => $details,
                    ));
                }

                // The first authenticated warm request may legitimately boot WordPress and emit
                // an incidental Set-Cookie while storing the UltraCache object. The clean handoff
                // must be a normal anonymous public request so advanced-cache.php can satisfy it
                // before plugin code runs and Varnish can store the cookie-free representation.
                $clean_result = self::send_varnish_refill_request_with_transient_retry($url, $bucket, false);
                $clean_summary = is_array($clean_result['summary'] ?? null) ? $clean_result['summary'] : array();
                $request_count += max(1, (int) ($clean_result['attemptCount'] ?? 1));
                $refill_attempts = array_merge($refill_attempts, (array) ($clean_result['attempts'] ?? array()));

                if (!empty($clean_summary['success']) && empty($clean_summary['cookieHandoffDeferred'])) {
                    $refill = $clean_summary;
                    $cookie_handoff_completed = true;
                    $clean_status = strtoupper((string) ($clean_summary['status'] ?? 'INCONCLUSIVE'));
                    if ('HIT' === $clean_status || 'STALE' === $clean_status) {
                        $cookie_handoff_verified = true;
                        $cookie_handoff_verification_status = $clean_status;
                    } elseif (!empty($clean_summary['varnishDetected']) && 'MISS' === $clean_status) {
                        $verify_result = self::send_varnish_refill_request_with_transient_retry($url, $bucket, false);
                        $verify_summary = is_array($verify_result['summary'] ?? null) ? $verify_result['summary'] : array();
                        $request_count += max(1, (int) ($verify_result['attemptCount'] ?? 1));
                        $refill_attempts = array_merge($refill_attempts, (array) ($verify_result['attempts'] ?? array()));
                        $verify_status = strtoupper((string) ($verify_summary['status'] ?? 'INCONCLUSIVE'));
                        $cookie_handoff_verification_status = $verify_status;
                        if (!empty($verify_summary['success']) && in_array($verify_status, array('HIT', 'STALE'), true)) {
                            $refill = $verify_summary;
                            $cookie_handoff_verified = true;
                        } else {
                            $refill['warning'] = true;
                            $refill['detail'] = self::maybe_translate('A clean Varnish refill completed after an incidental response cookie, but the optional follow-up request did not verify an outer-cache HIT.');
                        }
                    }
                } else {
                    $refill = $clean_summary;
                    if (!empty($refill['success'])) {
                        $refill['warning'] = true;
                        $refill['detail'] = self::maybe_translate('The public page remained cacheable in UltraCache, but the clean shared-cache handoff still carried a response cookie and could not populate Varnish in this pass.');
                    }
                }
            }

            $success = !empty($refill['success']);
            $shared_cache_blocked = !empty($refill['sharedCacheBlocked']);
            $all_ok = $all_ok && $success;
            if ($success) {
                $shared_cache_refill_completed = !$shared_cache_blocked
                    && (!$cookie_handoff_required || $cookie_handoff_completed);
                if ($shared_cache_refill_completed) {
                    ++$refilled_count;
                }
                if (!empty($refill['warning']) || $shared_cache_blocked) {
                    ++$warning_count;
                }
                if (!empty($refill['esiParent'])) {
                    ++$esi_parent_variant_count;
                    $esi_fragment_reference_count += max(0, (int) ($refill['esiFragmentCount'] ?? 0));
                    $esi_unique_fragment_reference_count += max(0, (int) ($refill['esiUniqueFragmentCount'] ?? 0));
                    $variant_min_ttl = max(0, (int) ($refill['esiMinTtl'] ?? 0));
                    $variant_max_ttl = max(0, (int) ($refill['esiMaxTtl'] ?? 0));
                    if ($variant_min_ttl > 0 && (0 === $esi_min_ttl || $variant_min_ttl < $esi_min_ttl)) {
                        $esi_min_ttl = $variant_min_ttl;
                    }
                    if ($variant_max_ttl > $esi_max_ttl) {
                        $esi_max_ttl = $variant_max_ttl;
                    }
                }
            } elseif (self::is_varnish_refill_failure_retryable($refill)) {
                ++$retryable_failure_count;
            } else {
                ++$terminal_failure_count;
            }

            $details[] = array(
                'bucket' => $bucket,
                'success' => $success,
                'retryable' => !$success && self::is_varnish_refill_failure_retryable($refill),
                'failureClass' => $success ? '' : self::get_varnish_refill_failure_class($refill),
                'httpCode' => (int) ($refill['httpCode'] ?? 0),
                'errorCode' => sanitize_key((string) ($refill['errorCode'] ?? '')),
                'refillStatus' => (string) ($refill['status'] ?? 'INCONCLUSIVE'),
                'refillEvidence' => (string) ($refill['evidence'] ?? ''),
                'detail' => (string) ($refill['detail'] ?? ''),
                'attemptCount' => $request_count,
                'cookieHandoffRequired' => $cookie_handoff_required,
                'cookieHandoffCompleted' => $cookie_handoff_completed,
                'cookieHandoffVerified' => $cookie_handoff_verified,
                'cookieHandoffVerificationStatus' => $cookie_handoff_verification_status,
                'setCookieNames' => $cookie_handoff_cookie_names,
                'responseCookiePolicy' => $cookie_handoff_policy_reason,
                'sharedCacheBlocked' => $shared_cache_blocked,
                'attempts' => array_map(
                    static function ($attempt_summary, $attempt_index) {
                        $attempt_summary = is_array($attempt_summary) ? $attempt_summary : array();
                        return array(
                            // Individual retry helpers number their own local histories from 1.
                            // After cookie handoff merges warm, clean, and verification histories,
                            // expose one monotonic sequence for the combined diagnostic timeline.
                            'attempt' => max(1, (int) $attempt_index + 1),
                            'success' => !empty($attempt_summary['success']),
                            'retryable' => !empty($attempt_summary['retryable']),
                            'httpCode' => max(0, (int) ($attempt_summary['httpCode'] ?? 0)),
                            'status' => sanitize_key((string) ($attempt_summary['status'] ?? '')),
                            'evidence' => sanitize_key((string) ($attempt_summary['evidence'] ?? '')),
                            'errorCode' => sanitize_key((string) ($attempt_summary['errorCode'] ?? '')),
                            'detail' => sanitize_text_field((string) ($attempt_summary['detail'] ?? '')),
                        );
                    },
                    array_slice($refill_attempts, 0, 4),
                    array_keys(array_slice($refill_attempts, 0, 4))
                ),
                'esiParent' => !empty($refill['esiParent']),
                'esiFragmentCount' => max(0, (int) ($refill['esiFragmentCount'] ?? 0)),
                'esiUniqueFragmentCount' => max(0, (int) ($refill['esiUniqueFragmentCount'] ?? 0)),
                'esiMinTtl' => max(0, (int) ($refill['esiMinTtl'] ?? 0)),
                'esiMaxTtl' => max(0, (int) ($refill['esiMaxTtl'] ?? 0)),
            );

            if (!self::invoke_varnish_refill_heartbeat($heartbeat, 'varnish-refill-after', $bucket)) {
                return self::normalize_varnish_refill_result_state(array(
                    'success' => false,
                    'retryable' => true,
                    'terminal' => false,
                    'ownershipLost' => true,
                    'failureClass' => 'ownership-lost',
                    'message' => self::maybe_translate('Warm-up ownership expired after the Varnish refill request completed.'),
                    'variantCount' => count($details),
                    'refilledCount' => $refilled_count,
                    'details' => $details,
                ));
            }
        }

        $retryable = !$all_ok && $retryable_failure_count > 0 && 0 === $terminal_failure_count;
        $first_failed_detail = array();
        foreach ($details as $detail) {
            if (empty($detail['success'])) {
                $first_failed_detail = $detail;
                break;
            }
        }
        return self::normalize_varnish_refill_result_state(array(
            'success' => $all_ok,
            'retryable' => $retryable,
            'terminal' => !$all_ok && !$retryable,
            'warning' => $all_ok && $warning_count > 0,
            'failureClass' => $all_ok
                ? ''
                : sanitize_key((string) ($first_failed_detail['failureClass'] ?? ($retryable ? 'varnish-http-transient' : 'varnish-http-terminal'))),
            'message' => $all_ok
                ? ($refilled_count === count($details)
                    ? self::maybe_translate_sprintf('Varnish public refill completed for %d HTML variant(s).', count($details))
                    : self::maybe_translate_sprintf('Varnish refill requests completed, but only %d of %d HTML variant(s) were eligible for shared-cache refill.', $refilled_count, count($details)))
                : self::maybe_translate('Varnish public refill failed for one or more HTML variants.'),
            'variantCount' => count($details),
            'refilledCount' => $refilled_count,
            'esiParentVariantCount' => $esi_parent_variant_count,
            'esiFragmentReferenceCount' => $esi_fragment_reference_count,
            'esiUniqueFragmentReferenceCount' => $esi_unique_fragment_reference_count,
            'esiMinTtl' => $esi_min_ttl,
            'esiMaxTtl' => $esi_max_ttl,
            'details' => $details,
        ));
    }

    /**
     * Populate Varnish immediately after one successful site warm-up page.
     *
     * @param string $url         Local public URL.
     * @param array  $warm_result Existing page force-refresh result.
     * @param string $context                  Warm-up source used for bounded metrics labels.
     * @param bool          $requires_verified_origin Whether one-stage fallback must be blocked.
     * @param callable|null $heartbeat               Internal ownership callback.
     * @return array
     */
    public static function refill_varnish_after_site_warm($url, array $warm_result = array(), $context = 'manual', $requires_verified_origin = false, $heartbeat = null)
    {
        $context = sanitize_key((string) $context);
        $requires_verified_origin = (bool) $requires_verified_origin;
        if (!in_array($context, array('manual', 'cron', 'warm-after-flush', 'scheduled-cleanup', 'targeted-purge', 'refresh-ahead', 'affected-save', 'cli', 'diagnostic'), true)) {
            $context = 'manual';
        }

        $settings = self::get_varnish_cli_settings();
        $diagnostic_probe = 'diagnostic' === $context
            && method_exists(static::class, 'is_varnish_capability_probe_authorized')
            && self::is_varnish_capability_probe_authorized('refill', array(
                'strategy' => 'refill',
                'requestedScope' => 'html-variants',
                'endpoints' => (array) ($settings['servers'] ?? array()),
                'urls' => array($url),
            ));
        if ($diagnostic_probe) {
            $varnish_warm_enabled = true;
        } elseif ('affected-save' === $context) {
            $dashboard_settings = self::get_dashboard_settings();
            $varnish_warm_enabled = self::is_varnish_runtime_enabled($dashboard_settings);
        } else {
            $varnish_warm_enabled = in_array($context, array('targeted-purge', 'refresh-ahead'), true)
                ? self::should_refill_after_targeted_varnish_invalidation()
                : self::should_warm_varnish_during_site_warmup();
        }
        if (!$varnish_warm_enabled) {
            return self::normalize_varnish_refill_result_state(array(
                'success' => true,
                'skipped' => true,
                'message' => in_array($context, array('targeted-purge', 'refresh-ahead', 'affected-save'), true)
                    ? self::maybe_translate('Affected-page Varnish refill is unavailable for the current connection or capability state.')
                    : self::maybe_translate('Varnish warm-up is unavailable for the current connection or capability state.'),
            ));
        }

        if (empty($settings['support']['available']) || empty($settings['servers'])) {
            return self::normalize_varnish_refill_result_state(array(
                'success' => false,
                'retryable' => false,
                'terminal' => true,
                'failureClass' => 'varnish-configuration',
                'message' => self::maybe_translate('Varnish is enabled but no usable endpoint is available for site warm-up.'),
            ));
        }

        $two_stage = self::get_varnish_origin_revalidation_not_applicable_status();
        if ($requires_verified_origin) {
            if (!self::is_varnish_origin_revalidation_applicable($settings)) {
                return self::normalize_varnish_refill_result_state(array(
                    'success' => false,
                    'skipped' => false,
                    'retryable' => false,
                    'terminal' => true,
                    'failureClass' => 'origin-revalidation-not-applicable',
                    'message' => self::maybe_translate('Strict origin revalidation is unavailable because the active Varnish mode is not HTTP soft purge.'),
                    'variantCount' => 0,
                    'refilledCount' => 0,
                    'originRevalidationRequired' => true,
                    'fallbackBlocked' => true,
                    'twoStageRefill' => $two_stage,
                ));
            }

            if (!empty($warm_result)) {
                $two_stage = self::record_manual_varnish_origin_refresh_result($warm_result);
            } else {
                $two_stage = self::assess_varnish_origin_refresh_result(self::perform_varnish_origin_refresh($url));
                $two_stage['applicable'] = true;
                self::set_varnish_two_stage_refill_status($two_stage);
            }
        }

        if ($requires_verified_origin && empty($two_stage['available'])) {
            return self::normalize_varnish_refill_result_state(array(
                'success' => false,
                'skipped' => false,
                'retryable' => false,
                'terminal' => true,
                'failureClass' => 'origin-verification-blocked',
                'message' => self::maybe_translate('Strict soft-purge refill stopped because the authenticated origin refresh was not verified.'),
                'variantCount' => 0,
                'refilledCount' => 0,
                'originRevalidationRequired' => true,
                'fallbackBlocked' => true,
                'twoStageRefill' => $two_stage,
            ));
        }

        $invalidation = array(
            'success' => true,
            'skipped' => true,
            'message' => self::maybe_translate('The strict soft-purge origin flow already controls public object refresh.'),
        );
        if (!$requires_verified_origin) {
            if (!self::invoke_varnish_refill_heartbeat($heartbeat, 'varnish-invalidation-before', 'all')) {
                return self::normalize_varnish_refill_result_state(array(
                    'success' => false,
                    'retryable' => true,
                    'terminal' => false,
                    'ownershipLost' => true,
                    'failureClass' => 'ownership-lost',
                    'message' => self::maybe_translate('Warm-up ownership expired before exact Varnish invalidation.'),
                    'variantCount' => 0,
                    'refilledCount' => 0,
                ));
            }

            $invalidation = self::invalidate_varnish_before_warm_refill($url, $context);
            if (empty($invalidation['success'])) {
                return self::normalize_varnish_refill_result_state(array(
                    'success' => false,
                    'skipped' => false,
                    'retryable' => !empty($invalidation['retryable']),
                    'terminal' => !empty($invalidation['terminal']),
                    'failureClass' => (string) ($invalidation['failureClass'] ?? 'varnish-invalidation-failed'),
                    'message' => (string) ($invalidation['message'] ?? self::maybe_translate('Exact Varnish invalidation failed before refill.')),
                    'variantCount' => 0,
                    'refilledCount' => 0,
                    'originRevalidationRequired' => false,
                    'fallbackBlocked' => false,
                    'twoStageRefill' => $two_stage,
                    'invalidationCompleted' => false,
                    'invalidation' => $invalidation,
                ));
            }

            if (!self::invoke_varnish_refill_heartbeat($heartbeat, 'varnish-invalidation-after', 'all')) {
                return self::normalize_varnish_refill_result_state(array(
                    'success' => false,
                    'retryable' => true,
                    'terminal' => false,
                    'ownershipLost' => true,
                    'failureClass' => 'ownership-lost',
                    'message' => self::maybe_translate('Warm-up ownership expired after exact Varnish invalidation.'),
                    'variantCount' => 0,
                    'refilledCount' => 0,
                    'invalidationCompleted' => true,
                    'invalidation' => $invalidation,
                ));
            }
        }

        $public_result = self::send_public_varnish_refill_requests($url, $heartbeat);
        $public_result['originRevalidationRequired'] = $requires_verified_origin;
        $public_result['fallbackBlocked'] = false;
        $public_result['twoStageRefill'] = $two_stage;
        $public_result['invalidationCompleted'] = !$requires_verified_origin && !empty($invalidation['success']);
        $public_result['softPurgeRefreshFlow'] = $requires_verified_origin;
        $public_result['invalidation'] = $invalidation;

        if (empty($public_result['ownershipLost']) && method_exists(static::class, 'record_varnish_operation_result')) {
            self::record_varnish_operation_result(array(
                'success' => !empty($public_result['success']),
                'message' => (string) ($public_result['message'] ?? ''),
                'time' => time(),
                'operationType' => $context . '-refill',
                'label' => $context . '-' . substr(sha1(esc_url_raw((string) $url)), 0, 12),
                'requestCount' => max(0, (int) ($public_result['variantCount'] ?? 0)),
                'refillSuccessCount' => max(0, (int) ($public_result['refilledCount'] ?? 0)),
                'esiParentCount' => max(0, (int) ($public_result['esiParentVariantCount'] ?? 0)),
                'esiFragmentReferenceCount' => max(0, (int) ($public_result['esiFragmentReferenceCount'] ?? 0)),
            ));
        }
        return self::normalize_varnish_refill_result_state($public_result);
    }

    /**
     * Backward-compatible dashboard wrapper.
     *
     * @param string $url         Local public URL.
     * @param array  $warm_result Existing dashboard force-refresh result.
     * @return array
     */
    public static function refill_varnish_after_manual_warm($url, array $warm_result = array())
    {
        return self::refill_varnish_after_site_warm($url, $warm_result, 'manual');
    }


}
