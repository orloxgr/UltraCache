<?php
/**
 * LiteSpeed targeted refill and site warm-up helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_LiteSpeed_Refill_Trait
{
    /**
     * Whether successful targeted LiteSpeed invalidations should enter the
     * existing shared page-warm pipeline.
     *
     * @return bool
     */
    private static function should_refill_after_targeted_litespeed_invalidation()
    {
        return self::is_native_litespeed_html_cache_enabled();
    }

    /**
     * Whether normal site warm-up paths should populate LiteSpeed.
     *
     * @return bool
     */
    private static function should_warm_litespeed_during_site_warmup()
    {
        return self::is_native_litespeed_html_cache_enabled();
    }

    /**
     * Expose the site warm-up decision to the shared orchestrator.
     *
     * @return bool
     */
    public static function should_include_litespeed_in_site_warmup()
    {
        return self::should_warm_litespeed_during_site_warmup();
    }

    /**
     * Return active UltraCache HTML buckets for a LiteSpeed refill.
     *
     * @return array<int,string>
     */
    private static function get_litespeed_refill_buckets()
    {
        $settings = self::get_dashboard_settings();
        $policy = function_exists('ultracache_get_html_variant_policy')
            ? ultracache_get_html_variant_policy($settings)
            : array('buckets' => array('orig'));
        $buckets = array_values(array_intersect(
            array('orig', 'webp', 'avif'),
            (array) ($policy['buckets'] ?? array('orig'))
        ));

        return empty($buckets) ? array('orig') : $buckets;
    }

    /**
     * Return the LiteSpeed warm plan shared by manual and queued site warm-up.
     *
     * @return array<string,mixed>
     */
    public static function get_site_warm_litespeed_plan()
    {
        if (!self::should_warm_litespeed_during_site_warmup()) {
            return array(
                'enabled' => false,
                'buckets' => array(),
                'message' => self::maybe_translate('Native LiteSpeed HTML Cache is not enabled for site warm-up.'),
            );
        }

        $buckets = self::get_litespeed_refill_buckets();

        return array(
            'enabled' => true,
            'buckets' => $buckets,
            'message' => self::maybe_translate_sprintf(
                'Site warm-up will populate %d LiteSpeed HTML variant(s).',
                count($buckets)
            ),
        );
    }

    /**
     * Normalize one LiteSpeed refill result to the common high-level state.
     *
     * @param array $result Result payload.
     * @return array<string,mixed>
     */
    private static function normalize_litespeed_refill_result_state(array $result)
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
     * Renew the owning warm pipeline around a LiteSpeed request.
     *
     * @param callable|null $heartbeat Internal ownership callback.
     * @param string        $stage     Current stage.
     * @param string        $bucket    Optional HTML bucket.
     * @return bool
     */
    private static function invoke_litespeed_refill_heartbeat($heartbeat, $stage, $bucket = '')
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
     * Determine whether a failed signed purge request is retryable.
     *
     * @param array $result Purge result.
     * @return bool
     */
    private static function is_litespeed_purge_failure_retryable(array $result)
    {
        $batches = isset($result['batches']) && is_array($result['batches'])
            ? $result['batches']
            : array($result);

        foreach ($batches as $batch) {
            if (!is_array($batch) || !empty($batch['success'])) {
                continue;
            }
            $http_code = (int) ($batch['httpStatus'] ?? 0);
            if (0 === $http_code || 408 === $http_code || 425 === $http_code || 429 === $http_code || $http_code >= 500) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove the exact LiteSpeed object immediately before public refill.
     *
     * @param string $url Local public URL.
     * @return array<string,mixed>
     */
    private static function invalidate_litespeed_before_warm_refill($url)
    {
        $url = esc_url_raw((string) $url);
        if (method_exists(static::class, 'litespeed_url_has_nonempty_query')
            && self::litespeed_url_has_nonempty_query($url)) {
            return array(
                'success' => true,
                'skipped' => true,
                'retryable' => false,
                'terminal' => false,
                'failureClass' => '',
                'queryUrlHandling' => 'bypass-skip',
                'message' => self::maybe_translate('LiteSpeed bypasses query-string requests, so exact native invalidation was skipped without purging the base URL.'),
                'requestCount' => 0,
            );
        }
        if ('' === $url || !method_exists(static::class, 'purge_litespeed_urls')) {
            return array(
                'success' => false,
                'retryable' => false,
                'terminal' => true,
                'failureClass' => 'litespeed-invalidation-unavailable',
                'message' => self::maybe_translate('Exact LiteSpeed invalidation is unavailable before refill.'),
            );
        }

        $result = self::purge_litespeed_urls(array($url), false, 'pre-refill-hard-invalidation');
        $success = !empty($result['success']);
        $retryable = !$success && self::is_litespeed_purge_failure_retryable($result);

        return array(
            'success' => $success,
            'retryable' => $retryable,
            'terminal' => !$success && !$retryable,
            'failureClass' => $success ? '' : 'litespeed-invalidation-failed',
            'message' => $success
                ? self::maybe_translate('Exact LiteSpeed URL invalidation completed before refill.')
                : self::maybe_translate('LiteSpeed refill stopped because exact URL invalidation failed.'),
            'requestCount' => count((array) ($result['batches'] ?? array())),
            'details' => $result,
        );
    }

    /**
     * Return request arguments for one anonymous public LiteSpeed refill.
     *
     * @param string $bucket UltraCache HTML bucket.
     * @return array<string,mixed>
     */
    private static function get_litespeed_refill_request_args($bucket)
    {
        $bucket = in_array((string) $bucket, array('orig', 'webp', 'avif'), true)
            ? (string) $bucket
            : 'orig';
        $accept = function_exists('ultracache_get_accept_header_for_html_bucket')
            ? ultracache_get_accept_header_for_html_bucket($bucket)
            : 'text/html,application/xhtml+xml';

        return array(
            'method' => 'GET',
            'timeout' => method_exists(static::class, 'get_litespeed_http_timeout')
                ? self::get_litespeed_http_timeout()
                : max(0, (int) ini_get('max_execution_time')),
            'redirection' => 0,
            'user-agent' => 'Mozilla/5.0 (compatible; UltraCache-LiteSpeed-Refill/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : 'unknown') . '; +https://wordpress.org)',
            'headers' => array(
                'Accept' => $accept,
                'PageSpeed' => 'off',
                'ModPagespeed' => 'off',
            ),
        );
    }

    /**
     * Send one exact anonymous public GET that may populate LSCache.
     *
     * @param string $url    Local public URL.
     * @param string $bucket UltraCache HTML bucket.
     * @return array|WP_Error
     */
    private static function send_single_litespeed_refill_request($url, $bucket)
    {
        $url = esc_url_raw((string) $url);
        if ('' === $url
            || !function_exists('ultracache_is_strict_frontend_loopback_url')
            || !ultracache_is_strict_frontend_loopback_url($url)) {
            return new WP_Error(
                'ultracache_invalid_litespeed_refill_url',
                self::maybe_translate('The LiteSpeed refill URL is not an exact trusted frontend URL for this site.')
            );
        }

        $args = self::get_litespeed_refill_request_args($bucket);
        $args['sslverify'] = !function_exists('ultracache_is_local_https_url')
            || !ultracache_is_local_https_url($url);

        return function_exists('ultracache_safe_loopback_remote_request')
            ? ultracache_safe_loopback_remote_request($url, $args, 'litespeed_refill')
            : wp_safe_remote_get($url, $args);
    }

    /**
     * Read one response header conservatively.
     *
     * @param array|WP_Error $response WordPress HTTP response.
     * @param string         $name     Header name.
     * @return string
     */
    private static function get_litespeed_refill_response_header($response, $name)
    {
        if (is_wp_error($response)) {
            return '';
        }

        return sanitize_text_field((string) wp_remote_retrieve_header($response, $name));
    }

    /**
     * Summarize one public LiteSpeed refill response.
     *
     * @param array|WP_Error $response WordPress HTTP response.
     * @return array<string,mixed>
     */
    private static function summarize_litespeed_refill_response($response)
    {
        if (method_exists(static::class, 'observe_litespeed_http_response')) {
            self::observe_litespeed_http_response($response, 'refill-response');
        }

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'httpCode' => 0,
                'cacheStatus' => 'ERROR',
                'errorCode' => sanitize_key((string) $response->get_error_code()),
                'detail' => sanitize_text_field($response->get_error_message()),
                'headers' => array(),
            );
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $headers = array(
            'server' => self::get_litespeed_refill_response_header($response, 'server'),
            'cacheControl' => self::get_litespeed_refill_response_header($response, 'cache-control'),
            'xLiteSpeedCache' => self::get_litespeed_refill_response_header($response, 'x-litespeed-cache'),
            'xLiteSpeedCacheControl' => self::get_litespeed_refill_response_header($response, 'x-litespeed-cache-control'),
            'xQcCache' => self::get_litespeed_refill_response_header($response, 'x-qc-cache'),
            'xLiteSpeedVary' => self::get_litespeed_refill_response_header($response, 'x-litespeed-vary'),
            'ultraCache' => self::get_litespeed_refill_response_header($response, 'x-ultra-cache'),
            'ultraCacheReason' => self::get_litespeed_refill_response_header($response, 'x-ultra-cache-reason'),
            'ultraCacheSource' => self::get_litespeed_refill_response_header($response, 'x-ultra-cache-source'),
            'ultraCacheVariant' => self::get_litespeed_refill_response_header($response, 'x-ultracache-variant'),
            'ultraCacheCacheable' => self::get_litespeed_refill_response_header($response, 'x-ultracache-cacheable'),
            'ultraCachePageCacheable' => self::get_litespeed_refill_response_header($response, 'x-ultracache-page-cacheable'),
            'sharedCacheState' => self::get_litespeed_refill_response_header($response, 'x-ultracache-shared-cache-state'),
            'sharedCacheReason' => self::get_litespeed_refill_response_header($response, 'x-ultracache-shared-cache-reason'),
        );
        $status_header = strtolower(trim((string) ($headers['xLiteSpeedCache'] ?: $headers['xQcCache'])));
        $litespeed_control = strtolower(trim((string) $headers['xLiteSpeedCacheControl']));
        $cache_status = 'INCONCLUSIVE';
        if (false !== strpos($status_header, 'bypass')
            || false !== strpos($status_header, 'no-cache')
            || false !== strpos($litespeed_control, 'no-cache')) {
            // An explicit no-cache contract overrides a simultaneous MISS
            // status: LiteSpeed reached the origin but was instructed not to
            // store this representation.
            $cache_status = 'BYPASS';
        } elseif (false !== strpos($status_header, 'hit')) {
            $cache_status = 'HIT';
        } elseif (false !== strpos($status_header, 'miss')) {
            $cache_status = 'MISS';
        }
        $is_redirect = in_array($http_code, array(301, 302, 303, 307, 308), true);
        $explicit_bypass = 200 === $http_code && 'BYPASS' === $cache_status;
        $cookie_names = function_exists('ultracache_get_http_response_set_cookie_names')
            ? ultracache_get_http_response_set_cookie_names($response)
            : array();
        $cookie_policy = function_exists('ultracache_response_cookie_cache_policy')
            ? ultracache_response_cookie_cache_policy($cookie_names, self::get_settings())
            : array('decision' => empty($cookie_names) ? 'none' : 'reject', 'reason' => 'response-cookie-policy-unavailable');
        $ultra_status = strtoupper(trim((string) $headers['ultraCache']));
        $cookie_handoff_deferred = 200 === $http_code
            && !empty($cookie_names)
            && 'allow' === (string) ($cookie_policy['decision'] ?? '')
            && in_array($ultra_status, array('STORE', 'HIT'), true)
            && ('deferred-response-cookie' === sanitize_key((string) $headers['sharedCacheState']) || $explicit_bypass);

        return array(
            'success' => 200 === $http_code && (!$explicit_bypass || $cookie_handoff_deferred),
            'warning' => 200 === $http_code && 'INCONCLUSIVE' === $cache_status,
            'httpCode' => $http_code,
            'cacheStatus' => $is_redirect ? 'REDIRECT' : $cache_status,
            'errorCode' => $explicit_bypass && !$cookie_handoff_deferred ? 'litespeed_cache_bypass' : '',
            'detail' => $is_redirect
                ? self::maybe_translate_sprintf('HTTP %d redirect refused; the exact queued URL was not warmed.', $http_code)
                : ($cookie_handoff_deferred
                    ? self::maybe_translate('The public UltraCache response carried an allowed response cookie; LiteSpeed handoff is deferred to one clean follow-up request.')
                    : ($explicit_bypass
                        ? self::maybe_translate('LiteSpeed explicitly bypassed this refill request.')
                        : 'HTTP ' . $http_code)),
            'headers' => $headers,
            'setCookieNames' => $cookie_names,
            'responseCookiePolicy' => $cookie_policy,
            'cookieHandoffDeferred' => $cookie_handoff_deferred,
            'sharedCacheState' => sanitize_key((string) $headers['sharedCacheState']),
        );
    }

    /**
     * Whether a failed public refill request is retryable.
     *
     * @param array $summary Response summary.
     * @return bool
     */
    private static function is_litespeed_refill_failure_retryable(array $summary)
    {
        $error_code = sanitize_key((string) ($summary['errorCode'] ?? ''));
        if (in_array($error_code, array('ultracache_invalid_litespeed_refill_url', 'ultracache_untrusted_loopback_url'), true)) {
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
     * Send one public refill request for every active UltraCache HTML bucket.
     *
     * @param string        $url       Local public URL.
     * @param callable|null $heartbeat Internal ownership callback.
     * @param string        $context   Warm source.
     * @return array<string,mixed>
     */
    private static function send_public_litespeed_refill_requests($url, $heartbeat = null, $context = 'pipeline')
    {
        $url = esc_url_raw((string) $url);
        if ('' === $url) {
            return self::normalize_litespeed_refill_result_state(array(
                'success' => false,
                'retryable' => false,
                'terminal' => true,
                'failureClass' => 'litespeed-invalid-url',
                'message' => self::maybe_translate('Invalid LiteSpeed refill URL.'),
                'details' => array(),
            ));
        }

        $buckets = self::get_litespeed_refill_buckets();
        $details = array();
        $all_ok = true;
        $refilled_count = 0;
        $retryable_failures = 0;
        $terminal_failures = 0;
        $inconclusive_successes = 0;
        $verified_count = 0;

        foreach ($buckets as $bucket) {
            if (!self::invoke_litespeed_refill_heartbeat($heartbeat, 'litespeed-refill-before', $bucket)) {
                return self::normalize_litespeed_refill_result_state(array(
                    'success' => false,
                    'retryable' => true,
                    'terminal' => false,
                    'ownershipLost' => true,
                    'failureClass' => 'ownership-lost',
                    'message' => self::maybe_translate('Warm-up ownership expired before the LiteSpeed refill request completed.'),
                    'variantCount' => count($details),
                    'refilledCount' => $refilled_count,
                    'details' => $details,
                ));
            }

            $started = microtime(true);
            $first_summary = self::summarize_litespeed_refill_response(
                self::send_single_litespeed_refill_request($url, $bucket)
            );
            $summary = $first_summary;
            $initial_cache_status = sanitize_key(strtolower((string) ($first_summary['cacheStatus'] ?? 'inconclusive')));
            $verification_cache_status = '';
            $request_count = 1;
            $verified = 'hit' === $initial_cache_status;
            $cookie_handoff_required = !empty($first_summary['cookieHandoffDeferred']);
            $cookie_handoff_completed = false;

            if (!empty($first_summary['success']) && $cookie_handoff_required) {
                if (!self::invoke_litespeed_refill_heartbeat($heartbeat, 'litespeed-cookie-handoff-before', $bucket)) {
                    return self::normalize_litespeed_refill_result_state(array(
                        'success' => false,
                        'retryable' => true,
                        'terminal' => false,
                        'ownershipLost' => true,
                        'failureClass' => 'ownership-lost',
                        'message' => self::maybe_translate('Warm-up ownership expired before the clean LiteSpeed cookie handoff completed.'),
                        'variantCount' => count($details),
                        'refilledCount' => $refilled_count,
                        'verifiedCount' => $verified_count,
                        'details' => $details,
                    ));
                }

                $clean_summary = self::summarize_litespeed_refill_response(
                    self::send_single_litespeed_refill_request($url, $bucket)
                );
                ++$request_count;
                $clean_status = sanitize_key(strtolower((string) ($clean_summary['cacheStatus'] ?? 'inconclusive')));
                $verification_cache_status = $clean_status;

                if (!empty($clean_summary['success']) && empty($clean_summary['cookieHandoffDeferred'])) {
                    $summary = $clean_summary;
                    $cookie_handoff_completed = true;
                    if ('hit' === $clean_status) {
                        $verified = true;
                        $summary['warning'] = false;
                    } elseif ('miss' === $clean_status) {
                        if (!self::invoke_litespeed_refill_heartbeat($heartbeat, 'litespeed-cookie-handoff-verify-before', $bucket)) {
                            return self::normalize_litespeed_refill_result_state(array(
                                'success' => false,
                                'retryable' => true,
                                'terminal' => false,
                                'ownershipLost' => true,
                                'failureClass' => 'ownership-lost',
                                'message' => self::maybe_translate('Warm-up ownership expired before LiteSpeed cookie-handoff verification.'),
                                'variantCount' => count($details),
                                'refilledCount' => $refilled_count,
                                'verifiedCount' => $verified_count,
                                'details' => $details,
                            ));
                        }
                        $verify_summary = self::summarize_litespeed_refill_response(
                            self::send_single_litespeed_refill_request($url, $bucket)
                        );
                        ++$request_count;
                        $verify_status = sanitize_key(strtolower((string) ($verify_summary['cacheStatus'] ?? 'inconclusive')));
                        $verification_cache_status = $verify_status;
                        if (!empty($verify_summary['success']) && 'hit' === $verify_status) {
                            $summary = $verify_summary;
                            $verified = true;
                            $summary['warning'] = false;
                        } elseif (!empty($verify_summary['success'])) {
                            $summary = $verify_summary;
                            $summary['success'] = true;
                            $summary['warning'] = true;
                            $summary['detail'] = self::maybe_translate('LiteSpeed completed a clean handoff after an incidental response cookie, but the final request did not verify a stored HIT.');
                        } else {
                            $summary = $verify_summary;
                        }
                    } else {
                        $summary['warning'] = true;
                        $summary['detail'] = self::maybe_translate('LiteSpeed completed a clean handoff after an incidental response cookie, but storage could not be verified from the returned cache status.');
                    }
                } else {
                    $summary = $clean_summary;
                    if (!empty($summary['success'])) {
                        $summary['warning'] = true;
                        $summary['detail'] = self::maybe_translate('The public page remained cacheable in UltraCache, but the clean LiteSpeed handoff still carried a response cookie and could not populate LSCache in this pass.');
                    }
                }
            } elseif (!empty($first_summary['success']) && 'miss' === $initial_cache_status) {
                if (!self::invoke_litespeed_refill_heartbeat($heartbeat, 'litespeed-refill-verify-before', $bucket)) {
                    return self::normalize_litespeed_refill_result_state(array(
                        'success' => false,
                        'retryable' => true,
                        'terminal' => false,
                        'ownershipLost' => true,
                        'failureClass' => 'ownership-lost',
                        'message' => self::maybe_translate('Warm-up ownership expired before LiteSpeed storage verification.'),
                        'variantCount' => count($details),
                        'refilledCount' => $refilled_count,
                        'verifiedCount' => $verified_count,
                        'details' => $details,
                    ));
                }
                $verification_summary = self::summarize_litespeed_refill_response(
                    self::send_single_litespeed_refill_request($url, $bucket)
                );
                ++$request_count;
                $verification_cache_status = sanitize_key(strtolower((string) ($verification_summary['cacheStatus'] ?? 'inconclusive')));
                if (!empty($verification_summary['success']) && 'hit' === $verification_cache_status) {
                    $summary = $verification_summary;
                    $verified = true;
                    $summary['warning'] = false;
                } elseif (!empty($verification_summary['success'])) {
                    $summary = $verification_summary;
                    $summary['success'] = true;
                    $summary['warning'] = true;
                    $summary['detail'] = self::maybe_translate('LiteSpeed accepted the refill request, but a follow-up request did not verify a stored HIT.');
                } else {
                    $summary = $verification_summary;
                }
            }

            $summary['durationMs'] = max(0, (int) round((microtime(true) - $started) * 1000));
            $summary['verified'] = $verified;
            $summary['requestCount'] = $request_count;
            $summary['initialCacheStatus'] = strtoupper($initial_cache_status);
            $summary['verificationCacheStatus'] = '' !== $verification_cache_status ? strtoupper($verification_cache_status) : '';
            $success = !empty($summary['success']);
            $all_ok = $all_ok && $success;
            if ($success) {
                ++$refilled_count;
                if ($verified) {
                    ++$verified_count;
                }
                if (!empty($summary['warning']) || !$verified) {
                    ++$inconclusive_successes;
                }
            } elseif (self::is_litespeed_refill_failure_retryable($summary)) {
                ++$retryable_failures;
            } else {
                ++$terminal_failures;
            }

            $details[] = array(
                'bucket' => $bucket,
                'success' => $success,
                'verified' => $verified,
                'requestCount' => $request_count,
                'initialCacheStatus' => strtoupper($initial_cache_status),
                'verificationCacheStatus' => '' !== $verification_cache_status ? strtoupper($verification_cache_status) : '',
                'cookieHandoffRequired' => $cookie_handoff_required,
                'cookieHandoffCompleted' => $cookie_handoff_completed,
                'setCookieNames' => array_values((array) ($first_summary['setCookieNames'] ?? array())),
                'responseCookiePolicy' => sanitize_key((string) (($first_summary['responseCookiePolicy']['reason'] ?? ''))),
                'httpCode' => (int) ($summary['httpCode'] ?? 0),
                'cacheStatus' => (string) ($summary['cacheStatus'] ?? 'INCONCLUSIVE'),
                'detail' => (string) ($summary['detail'] ?? ''),
                'headers' => (array) ($summary['headers'] ?? array()),
            );

            if (!self::invoke_litespeed_refill_heartbeat($heartbeat, 'litespeed-refill-after', $bucket)) {
                return self::normalize_litespeed_refill_result_state(array(
                    'success' => false,
                    'retryable' => true,
                    'terminal' => false,
                    'ownershipLost' => true,
                    'failureClass' => 'ownership-lost',
                    'message' => self::maybe_translate('Warm-up ownership expired after the LiteSpeed refill request completed.'),
                    'variantCount' => count($details),
                    'refilledCount' => $refilled_count,
                    'details' => $details,
                ));
            }
            if (method_exists(static::class, 'record_litespeed_refill_result')) {
                self::record_litespeed_refill_result($url, $bucket, $summary, $context);
            }
        }

        $retryable = !$all_ok && $retryable_failures > 0 && 0 === $terminal_failures;
        $all_verified = $all_ok && count($details) > 0 && $verified_count === count($details);

        return self::normalize_litespeed_refill_result_state(array(
            'success' => $all_ok,
            'verified' => $all_verified,
            'retryable' => $retryable,
            'terminal' => !$all_ok && !$retryable,
            'warning' => $all_ok && (!$all_verified || $inconclusive_successes > 0),
            'failureClass' => $all_ok ? '' : ($retryable ? 'litespeed-http-transient' : 'litespeed-http-terminal'),
            'message' => $all_verified
                ? self::maybe_translate_sprintf('LiteSpeed public refill verified a stored HIT for %d HTML variant(s).', count($details))
                : ($all_ok
                    ? self::maybe_translate_sprintf('LiteSpeed public refill completed for %d HTML variant(s), but one or more stored HITs could not be verified.', count($details))
                    : self::maybe_translate('LiteSpeed public refill failed for one or more HTML variants.')),
            'variantCount' => count($details),
            'refilledCount' => $refilled_count,
            'verifiedCount' => $verified_count,
            'details' => $details,
        ));
    }

    /**
     * Populate LiteSpeed after one successful page warm.
     *
     * @param string        $url                      Local public URL.
     * @param array         $warm_result              Existing page warm result.
     * @param string        $context                  Warm source.
     * @param bool          $requires_verified_origin Reserved parity argument.
     * @param callable|null $heartbeat                Internal ownership callback.
     * @return array<string,mixed>
     */
    public static function refill_litespeed_after_site_warm($url, array $warm_result = array(), $context = 'manual', $requires_verified_origin = false, $heartbeat = null)
    {
        unset($warm_result, $requires_verified_origin);
        $context = sanitize_key((string) $context);
        if (!in_array($context, array('manual', 'cron', 'warm-after-flush', 'scheduled-cleanup', 'targeted-purge', 'queued-invalidation', 'affected-save', 'cli'), true)) {
            $context = 'manual';
        }

        $targeted_context = in_array($context, array('targeted-purge', 'queued-invalidation', 'affected-save'), true);
        $enabled = $targeted_context
            ? self::should_refill_after_targeted_litespeed_invalidation()
            : self::should_warm_litespeed_during_site_warmup();
        if (!$enabled) {
            return self::normalize_litespeed_refill_result_state(array(
                'success' => true,
                'skipped' => true,
                'message' => $targeted_context
                    ? self::maybe_translate('Native LiteSpeed HTML Cache is not enabled for affected-page refill.')
                    : self::maybe_translate('Native LiteSpeed HTML Cache is not enabled for site warm-up.'),
            ));
        }

        if (!self::is_native_litespeed_html_cache_enabled()) {
            return self::normalize_litespeed_refill_result_state(array(
                'success' => false,
                'retryable' => false,
                'terminal' => true,
                'failureClass' => 'litespeed-configuration',
                'message' => self::maybe_translate('Native LiteSpeed HTML Cache is not enabled for refill.'),
            ));
        }

        if (method_exists(static::class, 'litespeed_url_has_nonempty_query')
            && self::litespeed_url_has_nonempty_query($url)) {
            return self::normalize_litespeed_refill_result_state(array(
                'success' => true,
                'skipped' => true,
                'retryable' => false,
                'terminal' => false,
                'failureClass' => '',
                'queryUrlHandling' => 'bypass-skip',
                'message' => self::maybe_translate('LiteSpeed bypasses query-string requests, so native purge and public refill were skipped without mapping the request to the base URL.'),
                'variantCount' => 0,
                'refilledCount' => 0,
            ));
        }

        if (!self::invoke_litespeed_refill_heartbeat($heartbeat, 'litespeed-invalidation-before')) {
            return self::normalize_litespeed_refill_result_state(array(
                'success' => false,
                'retryable' => true,
                'terminal' => false,
                'ownershipLost' => true,
                'failureClass' => 'ownership-lost',
                'message' => self::maybe_translate('Warm-up ownership expired before exact LiteSpeed invalidation.'),
                'variantCount' => 0,
                'refilledCount' => 0,
            ));
        }

        $invalidation = ('queued-invalidation' === $context)
            ? array(
                'success' => true,
                'skipped' => true,
                'alreadyCompleted' => true,
                'retryable' => false,
                'terminal' => false,
                'failureClass' => '',
                'message' => self::maybe_translate('Durable LiteSpeed invalidation already completed before this refill entered the shared page-warm pipeline.'),
            )
            : self::invalidate_litespeed_before_warm_refill($url);
        if (empty($invalidation['success'])) {
            return self::normalize_litespeed_refill_result_state(array(
                'success' => false,
                'retryable' => !empty($invalidation['retryable']),
                'terminal' => !empty($invalidation['terminal']),
                'failureClass' => (string) ($invalidation['failureClass'] ?? 'litespeed-invalidation-failed'),
                'message' => (string) ($invalidation['message'] ?? self::maybe_translate('Exact LiteSpeed invalidation failed before refill.')),
                'variantCount' => 0,
                'refilledCount' => 0,
                'invalidationCompleted' => false,
                'invalidation' => $invalidation,
            ));
        }

        if (!self::invoke_litespeed_refill_heartbeat($heartbeat, 'litespeed-invalidation-after')) {
            return self::normalize_litespeed_refill_result_state(array(
                'success' => false,
                'retryable' => true,
                'terminal' => false,
                'ownershipLost' => true,
                'failureClass' => 'ownership-lost',
                'message' => self::maybe_translate('Warm-up ownership expired after exact LiteSpeed invalidation.'),
                'variantCount' => 0,
                'refilledCount' => 0,
                'invalidationCompleted' => true,
                'invalidation' => $invalidation,
            ));
        }

        $public_result = self::send_public_litespeed_refill_requests($url, $heartbeat, $context);
        $public_result['invalidationCompleted'] = true;
        $public_result['invalidation'] = $invalidation;
        $public_result['context'] = $context;

        return self::normalize_litespeed_refill_result_state($public_result);
    }

    /**
     * Backward-compatible dashboard wrapper.
     *
     * @param string $url         Local public URL.
     * @param array  $warm_result Existing warm result.
     * @return array<string,mixed>
     */
    public static function refill_litespeed_after_manual_warm($url, array $warm_result = array())
    {
        return self::refill_litespeed_after_site_warm($url, $warm_result, 'manual');
    }
}
