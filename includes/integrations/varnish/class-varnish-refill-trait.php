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
        if (empty($settings['varnishCliEnabled'])) {
            return false;
        }

        $varnish_settings = self::get_varnish_cli_settings();
        $strategy = self::get_varnish_invalidation_strategy_status($varnish_settings);
        return !empty($settings['varnishRefillAfterTargetedInvalidation'])
            || 'soft' === (string) ($strategy['effective'] ?? '');
    }

    /**
     * Whether any site warm-up path should populate Varnish for the same page.
     *
     * The existing setting key is retained for backward compatibility, while
     * dashboard manual jobs, cron jobs, and warm-after-flush jobs all consume
     * the same decision.
     *
     * @return bool
     */
    private static function should_warm_varnish_during_site_warmup()
    {
        $settings = self::get_dashboard_settings();
        return !empty($settings['varnishCliEnabled'])
            && !empty($settings['varnishWarmDuringManualWarmup']);
    }

    /**
     * Backward-compatible manual warm-up decision wrapper.
     *
     * @return bool
     */
    private static function should_warm_varnish_during_manual_warmup()
    {
        return self::should_warm_varnish_during_site_warmup();
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
            return array(
                'enabled' => false,
                'buckets' => array(),
                'message' => self::maybe_translate('Varnish warm-up with site warm-up is disabled.'),
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
     * Backward-compatible plan used by the dashboard staged pipeline.
     *
     * @return array
     */
    public static function get_manual_varnish_warm_plan()
    {
        return self::get_site_warm_varnish_plan();
    }

    /**
     * Refill one Varnish HTML bucket immediately for the manual page pipeline.
     *
     * @param string $url    Local public URL.
     * @param string $bucket HTML variant bucket.
     * @return array
     */
    public static function refill_varnish_manual_bucket($url, $bucket)
    {
        $url = esc_url_raw((string) $url);
        $bucket = sanitize_key((string) $bucket);
        $plan = self::get_manual_varnish_warm_plan();
        if (empty($plan['enabled'])) {
            return array(
                'success' => true,
                'skipped' => true,
                'bucket' => $bucket,
                'message' => (string) ($plan['message'] ?? self::maybe_translate('Varnish warm-up with site warm-up is disabled.')),
            );
        }
        if ('' === $url || !in_array($bucket, (array) ($plan['buckets'] ?? array()), true)) {
            return array(
                'success' => false,
                'skipped' => false,
                'bucket' => $bucket,
                'message' => self::maybe_translate('Invalid manual Varnish refill bucket.'),
            );
        }

        $summary = self::summarize_varnish_refill_response(self::send_single_varnish_refill_request($url, $bucket));
        $success = !empty($summary['success']);
        if (method_exists(static::class, 'record_varnish_operation_result')) {
            self::record_varnish_operation_result(array(
                'success' => $success,
                'message' => (string) ($summary['detail'] ?? ''),
                'time' => time(),
                'operationType' => 'manual-refill-bucket',
                'label' => 'manual-' . $bucket . '-' . substr(sha1($url), 0, 12),
                'requestCount' => 1,
                'refillSuccessCount' => $success ? 1 : 0,
            ));
        }

        return array(
            'success' => $success,
            'skipped' => false,
            'bucket' => $bucket,
            'httpCode' => (int) ($summary['httpCode'] ?? 0),
            'status' => (string) ($summary['status'] ?? 'INCONCLUSIVE'),
            'evidence' => (string) ($summary['evidence'] ?? ''),
            'message' => $success
                ? self::maybe_translate_sprintf('Varnish %s bucket refilled.', strtoupper($bucket))
                : self::maybe_translate_sprintf('Varnish %s bucket refill failed.', strtoupper($bucket)),
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
            $result['message'] = self::maybe_translate('Affected-page Varnish refill is disabled.');
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
     * @param string $bucket UltraCache HTML variant bucket.
     * @return array
     */
    private static function get_varnish_refill_request_args($bucket)
    {
        $bucket = in_array((string) $bucket, array('orig', 'webp', 'avif'), true) ? (string) $bucket : 'orig';
        $accept = function_exists('ultracache_get_accept_header_for_html_bucket')
            ? ultracache_get_accept_header_for_html_bucket($bucket)
            : 'text/html,application/xhtml+xml';

        $runtime_token = function_exists('ultracache_create_runtime_control_token')
            ? ultracache_create_runtime_control_token()
            : '';

        return array(
            'method' => 'GET',
            'timeout' => 10,
            'redirection' => 0,
            'user-agent' => 'Mozilla/5.0 (compatible; UltraCache-Varnish-Refill/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : 'unknown') . '; +https://wordpress.org)',
            'headers' => array_filter(array(
                'Accept' => $accept,
                'PageSpeed' => 'off',
                'ModPagespeed' => 'off',
                'X-UltraCache-Warm' => '1',
                'X-UltraCache-Internal-Request' => '1',
                'X-UltraCache-Token' => $runtime_token,
            )),
        );
    }

    /**
     * Send one bounded public loopback request for a Varnish HTML variant.
     *
     * @param string $url    Public local URL.
     * @param string $bucket UltraCache HTML variant bucket.
     * @return array|WP_Error
     */
    private static function send_single_varnish_refill_request($url, $bucket)
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

        $args = self::get_varnish_refill_request_args($bucket);
        $runtime_token = (string) ($args['headers']['X-UltraCache-Token'] ?? '');
        if ('' === $runtime_token) {
            return new WP_Error(
                'ultracache_internal_auth_unavailable',
                self::maybe_translate('Could not authenticate the internal Varnish refill request.')
            );
        }

        $args['sslverify'] = !function_exists('ultracache_is_local_https_url') || !ultracache_is_local_https_url($url);

        return function_exists('ultracache_safe_loopback_remote_request')
            ? ultracache_safe_loopback_remote_request($url, $args, 'varnish_refill')
            : wp_safe_remote_get($url, $args);
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
            'ultraCache' => self::get_varnish_response_header($response, 'x-ultra-cache'),
            'ultraCacheSource' => self::get_varnish_response_header($response, 'x-ultra-cache-source'),
            'ultraCacheVariant' => self::get_varnish_response_header($response, 'x-ultracache-variant'),
        );
        $classification = self::classify_varnish_response($headers, $response_code);
        $is_redirect = in_array($response_code, array(301, 302, 303, 307, 308), true);

        return array(
            'success' => 200 === $response_code,
            'httpCode' => $response_code,
            'status' => $is_redirect ? 'REDIRECT' : (string) ($classification['status'] ?? 'INCONCLUSIVE'),
            'varnishDetected' => !empty($classification['varnishDetected']),
            'confidence' => $is_redirect ? 'high' : (string) ($classification['confidence'] ?? 'low'),
            'evidence' => $is_redirect ? 'redirect-refused' : (string) ($classification['evidence'] ?? 'none'),
            'errorCode' => '',
            'detail' => $is_redirect
                ? self::maybe_translate_sprintf('HTTP %d redirect refused; the exact queued URL was not warmed.', $response_code)
                : 'HTTP ' . $response_code,
            'headers' => $headers,
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

            $refill = self::summarize_varnish_refill_response(self::send_single_varnish_refill_request($url, $bucket));
            $success = !empty($refill['success']);
            $all_ok = $all_ok && $success;
            if ($success) {
                ++$refilled_count;
            } elseif (self::is_varnish_refill_failure_retryable($refill)) {
                ++$retryable_failure_count;
            } else {
                ++$terminal_failure_count;
            }

            $details[] = array(
                'bucket' => $bucket,
                'success' => $success,
                'httpCode' => (int) ($refill['httpCode'] ?? 0),
                'refillStatus' => (string) ($refill['status'] ?? 'INCONCLUSIVE'),
                'refillEvidence' => (string) ($refill['evidence'] ?? ''),
                'detail' => (string) ($refill['detail'] ?? ''),
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
        return self::normalize_varnish_refill_result_state(array(
            'success' => $all_ok,
            'retryable' => $retryable,
            'terminal' => !$all_ok && !$retryable,
            'warning' => false,
            'failureClass' => $all_ok ? '' : ($retryable ? 'varnish-http-transient' : 'varnish-http-terminal'),
            'message' => $all_ok
                ? self::maybe_translate_sprintf('Varnish public refill completed for %d HTML variant(s).', count($details))
                : self::maybe_translate('Varnish public refill failed for one or more HTML variants.'),
            'variantCount' => count($details),
            'refilledCount' => $refilled_count,
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
        if (!in_array($context, array('manual', 'cron', 'warm-after-flush', 'scheduled-cleanup', 'targeted-purge', 'refresh-ahead', 'cli'), true)) {
            $context = 'manual';
        }

        $varnish_warm_enabled = in_array($context, array('targeted-purge', 'refresh-ahead'), true)
            ? self::should_refill_after_targeted_varnish_invalidation()
            : self::should_warm_varnish_during_site_warmup();
        if (!$varnish_warm_enabled) {
            return self::normalize_varnish_refill_result_state(array(
                'success' => true,
                'skipped' => true,
                'message' => in_array($context, array('targeted-purge', 'refresh-ahead'), true)
                    ? self::maybe_translate('Affected-page Varnish refill is disabled.')
                    : self::maybe_translate('Varnish warm-up with site warm-up is disabled.'),
            ));
        }

        $settings = self::get_varnish_cli_settings();
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

        $public_result = self::send_public_varnish_refill_requests($url, $heartbeat);
        $public_result['originRevalidationRequired'] = $requires_verified_origin;
        $public_result['fallbackBlocked'] = false;
        $public_result['twoStageRefill'] = $two_stage;

        if (method_exists(static::class, 'record_varnish_operation_result')) {
            self::record_varnish_operation_result(array(
                'success' => !empty($public_result['success']),
                'message' => (string) ($public_result['message'] ?? ''),
                'time' => time(),
                'operationType' => $context . '-refill',
                'label' => $context . '-' . substr(sha1(esc_url_raw((string) $url)), 0, 12),
                'requestCount' => max(0, (int) ($public_result['variantCount'] ?? 0)),
                'refillSuccessCount' => max(0, (int) ($public_result['refilledCount'] ?? 0)),
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
