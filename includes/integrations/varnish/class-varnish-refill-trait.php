<?php
/**
 * Affected-page Varnish refill, verification, and manual prewarm helpers.
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
     * Whether dashboard manual warm-up should also populate Varnish.
     *
     * @return bool
     */
    private static function should_warm_varnish_during_manual_warmup()
    {
        $settings = self::get_dashboard_settings();
        return !empty($settings['varnishCliEnabled'])
            && !empty($settings['varnishWarmDuringManualWarmup']);
    }

    /**
     * Whether one additional public request should verify each refilled variant.
     *
     * @return bool
     */
    private static function should_verify_varnish_refill_hit()
    {
        $settings = self::get_dashboard_settings();
        return !empty($settings['varnishCliEnabled'])
            && !empty($settings['varnishVerifyRefillHit']);
    }

    /**
     * Queue eligible URLs for a persistent UltraCache rebuild and Varnish refill.
     *
     * @param array  $urls   Candidate local URLs.
     * @param string $reason Refill source.
     * @return array
     */
    private static function queue_varnish_refill_urls(array $urls, $reason = 'targeted-invalidation')
    {
        $result = array(
            'success' => false,
            'queued' => false,
            'queuedUrlCount' => 0,
            'reason' => sanitize_key((string) $reason),
        );
        if (!self::should_refill_after_targeted_varnish_invalidation()) {
            $result['message'] = 'Affected-page Varnish refill is disabled.';
            return $result;
        }

        $prepared = self::prepare_varnish_invalidation_urls($urls);
        $canonical_urls = array();
        foreach ((array) ($prepared['urls'] ?? array()) as $item) {
            $url = isset($item['url']) ? esc_url_raw((string) $item['url']) : '';
            if ('' !== $url) {
                $canonical_urls[$url] = $url;
            }
        }

        if (empty($canonical_urls) || !method_exists(static::class, 'insert_cron_warm_queue_urls') || !self::ensure_cron_warm_queue_table()) {
            $result['message'] = 'No eligible URLs were available for Varnish refill.';
            return $result;
        }

        $queued = self::insert_cron_warm_queue_urls(array_values($canonical_urls), 0, 'varnish_refill');
        if ($queued < 1) {
            $result['message'] = 'Varnish refill rows could not be queued.';
            return $result;
        }

        self::ensure_cron_warm_events_scheduled(1, true);
        $result['success'] = true;
        $result['queued'] = true;
        $result['queuedUrlCount'] = $queued;
        $result['message'] = self::maybe_translate_sprintf(
            'Queued %d affected URL(s) for UltraCache rebuild and Varnish refill.',
            $queued
        );
        return $result;
    }

    /**
     * Return request arguments for one public Varnish refill or verification request.
     *
     * @param string $bucket       UltraCache HTML variant bucket.
     * @param bool   $verification Whether this is the follow-up verification request.
     * @return array
     */
    private static function get_varnish_refill_request_args($bucket, $verification = false)
    {
        $bucket = in_array((string) $bucket, array('orig', 'webp', 'avif'), true) ? (string) $bucket : 'orig';
        $accept = function_exists('ultracache_get_accept_header_for_html_bucket')
            ? ultracache_get_accept_header_for_html_bucket($bucket)
            : 'text/html,application/xhtml+xml';

        return array(
            'method' => 'GET',
            'timeout' => 10,
            'redirection' => 3,
            'user-agent' => 'Mozilla/5.0 (compatible; UltraCache-Varnish-' . ($verification ? 'Verify' : 'Refill') . '/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : 'unknown') . '; +https://wordpress.org)',
            'headers' => array(
                'Accept' => $accept,
                'PageSpeed' => 'off',
                'ModPagespeed' => 'off',
            ),
        );
    }

    /**
     * Send one bounded public loopback request for a Varnish HTML variant.
     *
     * @param string $url          Public local URL.
     * @param string $bucket       UltraCache HTML variant bucket.
     * @param bool   $verification Whether this is the follow-up verification request.
     * @return array|WP_Error
     */
    private static function send_single_varnish_refill_request($url, $bucket, $verification = false)
    {
        $args = self::get_varnish_refill_request_args($bucket, $verification);
        $args['sslverify'] = !function_exists('ultracache_is_local_https_url') || !ultracache_is_local_https_url($url);

        return function_exists('ultracache_safe_loopback_remote_request')
            ? ultracache_safe_loopback_remote_request($url, $args, $verification ? 'varnish_refill_verify' : 'varnish_refill')
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
                'detail' => sanitize_text_field($response->get_error_message()),
                'headers' => array(),
            );
        }

        $response_code = (int) wp_remote_retrieve_response_code($response);
        $headers = array(
            'via' => self::get_varnish_behavior_response_header($response, 'via'),
            'server' => self::get_varnish_behavior_response_header($response, 'server'),
            'xVarnish' => self::get_varnish_behavior_response_header($response, 'x-varnish'),
            'xVarnishCache' => self::get_varnish_behavior_response_header($response, 'x-varnish-cache'),
            'xCache' => self::get_varnish_behavior_response_header($response, 'x-cache'),
            'xCacheStatus' => self::get_varnish_behavior_response_header($response, 'x-cache-status'),
            'xProxyCache' => self::get_varnish_behavior_response_header($response, 'x-proxy-cache'),
            'age' => self::get_varnish_behavior_response_header($response, 'age'),
            'ultraCache' => self::get_varnish_behavior_response_header($response, 'x-ultra-cache'),
            'ultraCacheSource' => self::get_varnish_behavior_response_header($response, 'x-ultra-cache-source'),
            'ultraCacheVariant' => self::get_varnish_behavior_response_header($response, 'x-ultracache-variant'),
        );
        $classification = self::classify_varnish_behavior_response($headers, $response_code);

        return array(
            'success' => 200 === $response_code,
            'httpCode' => $response_code,
            'status' => (string) ($classification['status'] ?? 'INCONCLUSIVE'),
            'varnishDetected' => !empty($classification['varnishDetected']),
            'confidence' => (string) ($classification['confidence'] ?? 'low'),
            'evidence' => (string) ($classification['evidence'] ?? 'none'),
            'detail' => 'HTTP ' . $response_code,
            'headers' => $headers,
        );
    }

    /**
     * Send normal public requests for every active UltraCache HTML variant.
     *
     * @param string    $url        Local public URL.
     * @param bool|null $verify_hit Optional explicit verification policy.
     * @return array
     */
    private static function send_public_varnish_refill_requests($url, $verify_hit = null)
    {
        $url = esc_url_raw((string) $url);
        if ('' === $url) {
            return array('success' => false, 'message' => 'Invalid Varnish refill URL.', 'details' => array());
        }

        $verify_hit = null === $verify_hit ? self::should_verify_varnish_refill_hit() : (bool) $verify_hit;
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
        $verified_hit_count = 0;
        $bypassed_count = 0;
        $inconclusive_count = 0;
        $not_hit_count = 0;
        $verification_error_count = 0;

        foreach ($buckets as $bucket) {
            $refill_response = self::send_single_varnish_refill_request($url, $bucket, false);
            $refill = self::summarize_varnish_refill_response($refill_response);
            $success = !empty($refill['success']);
            $all_ok = $all_ok && $success;
            if ($success) {
                $refilled_count++;
            }

            $detail = array(
                'bucket' => $bucket,
                'success' => $success,
                'httpCode' => (int) ($refill['httpCode'] ?? 0),
                'refillStatus' => (string) ($refill['status'] ?? 'INCONCLUSIVE'),
                'refillEvidence' => (string) ($refill['evidence'] ?? ''),
                'detail' => (string) ($refill['detail'] ?? ''),
                'verificationEnabled' => $verify_hit,
            );

            if ($verify_hit && $success) {
                $verification_response = self::send_single_varnish_refill_request($url, $bucket, true);
                $verification = self::summarize_varnish_refill_response($verification_response);
                $verification_status = strtoupper((string) ($verification['status'] ?? 'INCONCLUSIVE'));
                $detail['verificationSuccess'] = !empty($verification['success']);
                $detail['verificationHttpCode'] = (int) ($verification['httpCode'] ?? 0);
                $detail['verificationStatus'] = $verification_status;
                $detail['verificationEvidence'] = (string) ($verification['evidence'] ?? '');
                $detail['verificationDetail'] = (string) ($verification['detail'] ?? '');
                $detail['verificationAge'] = (string) (($verification['headers']['age'] ?? ''));

                if (empty($verification['success']) || 'ERROR' === $verification_status) {
                    $verification_error_count++;
                } elseif ('HIT' === $verification_status) {
                    $verified_hit_count++;
                } elseif ('BYPASS' === $verification_status) {
                    $bypassed_count++;
                } elseif (in_array($verification_status, array('MISS', 'STALE'), true)) {
                    $not_hit_count++;
                } else {
                    $inconclusive_count++;
                }
            } elseif ($verify_hit) {
                $detail['verificationSuccess'] = false;
                $detail['verificationStatus'] = 'SKIPPED';
                $detail['verificationEvidence'] = 'refill-failed';
                $detail['verificationDetail'] = 'Verification skipped because the refill request failed.';
                $verification_error_count++;
            }

            $details[] = $detail;
        }

        $verification_status = 'disabled';
        $verified = false;
        if ($verify_hit) {
            if ($verification_error_count > 0) {
                $verification_status = 'error';
            } elseif ($bypassed_count > 0) {
                $verification_status = 'bypassed';
            } elseif ($not_hit_count > 0) {
                $verification_status = 'not-hit';
            } elseif ($inconclusive_count > 0) {
                $verification_status = 'inconclusive';
            } elseif ($verified_hit_count === count($buckets) && $verified_hit_count > 0) {
                $verification_status = 'verified';
                $verified = true;
            }
        }

        if (!$all_ok) {
            $message = self::maybe_translate('Varnish public refill failed for one or more HTML variants.');
        } elseif (!$verify_hit) {
            $message = self::maybe_translate_sprintf('Varnish public refill completed for %d HTML variant(s).', count($details));
        } elseif ($verified) {
            $message = self::maybe_translate_sprintf('Varnish public refill completed and %d HTML variant(s) were verified as HIT.', $verified_hit_count);
        } else {
            $message = self::maybe_translate_sprintf(
                'Varnish public refill completed, but HIT verification was %s.',
                str_replace('-', ' ', $verification_status)
            );
        }

        return array(
            'success' => $all_ok,
            'message' => $message,
            'variantCount' => count($details),
            'refilledCount' => $refilled_count,
            'verificationEnabled' => $verify_hit,
            'verificationStatus' => $verification_status,
            'verified' => $verified,
            'verifiedHitCount' => $verified_hit_count,
            'bypassedCount' => $bypassed_count,
            'inconclusiveCount' => $inconclusive_count,
            'notHitCount' => $not_hit_count,
            'verificationErrorCount' => $verification_error_count,
            'details' => $details,
        );
    }

    /**
     * Populate Varnish immediately after one successful dashboard manual warm request.
     *
     * @param string $url         Local public URL.
     * @param array  $warm_result Existing dashboard force-refresh result.
     * @return array
     */
    public static function refill_varnish_after_manual_warm($url, array $warm_result = array())
    {
        if (!self::should_warm_varnish_during_manual_warmup()) {
            return array(
                'success' => true,
                'skipped' => true,
                'message' => self::maybe_translate('Manual Varnish warm-up is disabled.'),
            );
        }

        $settings = self::get_varnish_cli_settings();
        if (empty($settings['support']['available']) || empty($settings['servers'])) {
            return array(
                'success' => false,
                'message' => self::maybe_translate('Varnish is enabled but no usable endpoint is available for manual warm-up.'),
            );
        }

        $two_stage = !empty($warm_result)
            ? self::record_manual_varnish_origin_refresh_result($warm_result)
            : array(
                'available' => false,
                'status' => 'inconclusive',
                'fallbackUsed' => true,
                'message' => self::maybe_translate('The manual warm result was not available for two-stage refill verification.'),
            );
        $public_result = self::send_public_varnish_refill_requests($url);
        $public_result['twoStageRefill'] = $two_stage;
        return $public_result;
    }

    /**
     * Rebuild the inner cache and then refill the public Varnish variants.
     *
     * @param string $url Public local URL.
     * @return array
     */
    private static function send_varnish_refill_request($url)
    {
        $url = esc_url_raw((string) $url);
        if ('' === $url) {
            return array('success' => false, 'message' => 'Invalid Varnish refill URL.');
        }

        $inner_preparation = self::prepare_varnish_inner_cache_for_refill($url);
        $inner_result = is_array($inner_preparation['innerCache'] ?? null) ? $inner_preparation['innerCache'] : array();
        $origin_refresh = is_array($inner_preparation['originRefresh'] ?? null) ? $inner_preparation['originRefresh'] : array();
        $two_stage = is_array($inner_preparation['twoStageRefill'] ?? null) ? $inner_preparation['twoStageRefill'] : array();

        if (empty($inner_preparation['success'])) {
            return array(
                'success' => false,
                'message' => !empty($inner_result['message']) ? (string) $inner_result['message'] : 'UltraCache rebuild failed before Varnish refill.',
                'innerCache' => $inner_result,
                'originRefresh' => $origin_refresh,
                'twoStageRefill' => $two_stage,
            );
        }

        $public_result = self::send_public_varnish_refill_requests($url);
        return array(
            'success' => !empty($public_result['success']),
            'message' => (string) ($public_result['message'] ?? 'Varnish refill failed.'),
            'innerCache' => $inner_result,
            'originRefresh' => $origin_refresh,
            'twoStageRefill' => $two_stage,
            'publicRefill' => $public_result,
            'verificationEnabled' => !empty($public_result['verificationEnabled']),
            'verificationStatus' => (string) ($public_result['verificationStatus'] ?? 'disabled'),
            'verified' => !empty($public_result['verified']),
            'verifiedHitCount' => absint($public_result['verifiedHitCount'] ?? 0),
        );
    }
}
