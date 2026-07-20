<?php
/**
 * Varnish public cache-behavior diagnostics for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Behavior_Test_Trait
{
    private static function get_varnish_behavior_response_header($response, $name)
    {
        if (is_wp_error($response)) {
            return '';
        }

        $value = wp_remote_retrieve_header($response, (string) $name);
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }

        $value = trim((string) $value);
        if ('' === $value) {
            return '';
        }

        $value = preg_replace('/[\r\n\t]+/', ' ', $value);
        $value = is_string($value) ? preg_replace('/\s+/', ' ', $value) : '';

        return self::sanitize_varnish_string(substr((string) $value, 0, 500));
    }

    private static function classify_varnish_behavior_response(array $headers, $response_code)
    {
        $response_code = (int) $response_code;
        if ($response_code < 200 || $response_code >= 400) {
            return array(
                'status'          => 'ERROR',
                'varnishDetected' => false,
                'confidence'      => 'high',
                'evidence'        => 'http-status',
            );
        }

        $via = strtolower((string) ($headers['via'] ?? ''));
        $server = strtolower((string) ($headers['server'] ?? ''));
        $x_varnish = trim((string) ($headers['xVarnish'] ?? ''));
        $x_varnish_cache = strtolower((string) ($headers['xVarnishCache'] ?? ''));
        $varnish_detected = '' !== $x_varnish
            || false !== strpos($via, 'varnish')
            || false !== strpos($server, 'varnish')
            || '' !== $x_varnish_cache;

        $status_headers = strtolower(implode(' ', array_filter(array(
            (string) ($headers['xCache'] ?? ''),
            (string) ($headers['xCacheStatus'] ?? ''),
            (string) ($headers['xProxyCache'] ?? ''),
            (string) ($headers['xVarnishCache'] ?? ''),
        ))));

        $has_stale = 1 === preg_match('/\b(stale|grace|updating|revalidated)\b/i', $status_headers);
        $has_bypass = 1 === preg_match('/\b(pass|bypass|uncacheable)\b/i', $status_headers);
        $has_miss = 1 === preg_match('/\bmiss\b/i', $status_headers);
        $has_hit = 1 === preg_match('/\b(hit|cached)\b/i', $status_headers);

        if ($varnish_detected && $has_stale) {
            return array(
                'status'          => 'STALE',
                'varnishDetected' => true,
                'confidence'      => 'high',
                'evidence'        => 'cache-status-header',
            );
        }

        if ($varnish_detected && (int) $has_bypass + (int) $has_miss + (int) $has_hit > 1) {
            return array(
                'status'          => 'INCONCLUSIVE',
                'varnishDetected' => true,
                'confidence'      => 'low',
                'evidence'        => 'ambiguous-cache-status-header',
            );
        }

        if ($varnish_detected && $has_bypass) {
            return array(
                'status'          => 'BYPASS',
                'varnishDetected' => true,
                'confidence'      => 'high',
                'evidence'        => 'cache-status-header',
            );
        }

        if ($varnish_detected && $has_miss) {
            return array(
                'status'          => 'MISS',
                'varnishDetected' => true,
                'confidence'      => 'high',
                'evidence'        => 'cache-status-header',
            );
        }

        if ($varnish_detected && $has_hit) {
            return array(
                'status'          => 'HIT',
                'varnishDetected' => true,
                'confidence'      => 'high',
                'evidence'        => 'cache-status-header',
            );
        }

        $age_raw = trim((string) ($headers['age'] ?? ''));
        $age = ctype_digit($age_raw) ? (int) $age_raw : null;
        if ($varnish_detected && null !== $age && $age > 0) {
            return array(
                'status'          => 'HIT',
                'varnishDetected' => true,
                'confidence'      => 'medium',
                'evidence'        => 'positive-age',
            );
        }

        $varnish_ids = array();
        if ('' !== $x_varnish && preg_match_all('/\b\d+\b/', $x_varnish, $matches)) {
            $varnish_ids = array_values(array_unique($matches[0]));
        }

        if ($varnish_detected && count($varnish_ids) >= 2) {
            return array(
                'status'          => 'HIT',
                'varnishDetected' => true,
                'confidence'      => 'medium',
                'evidence'        => 'multiple-x-varnish-ids',
            );
        }

        if ($varnish_detected && 1 === count($varnish_ids) && 0 === $age) {
            return array(
                'status'          => 'MISS',
                'varnishDetected' => true,
                'confidence'      => 'medium',
                'evidence'        => 'single-x-varnish-id-age-zero',
            );
        }

        return array(
            'status'          => 'INCONCLUSIVE',
            'varnishDetected' => $varnish_detected,
            'confidence'      => 'low',
            'evidence'        => $varnish_detected ? 'varnish-headers-without-cache-status' : 'no-varnish-headers',
        );
    }

    private static function run_varnish_behavior_request($url, $step, $timeout, $accept = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', array $request_headers = array())
    {
        $headers = array(
            'Accept'          => sanitize_text_field((string) $accept),
            'Accept-Encoding' => 'identity',
        );
        foreach (array('If-None-Match', 'If-Modified-Since') as $allowed_header) {
            if (!isset($request_headers[$allowed_header]) || !is_scalar($request_headers[$allowed_header])) {
                continue;
            }
            $value = trim(preg_replace('/[\r\n\t]+/', ' ', (string) $request_headers[$allowed_header]));
            if ('' !== $value) {
                $headers[$allowed_header] = substr($value, 0, 4096);
            }
        }

        $started = microtime(true);
        $response = ultracache_safe_loopback_remote_request($url, array(
            'method'      => 'GET',
            'timeout'     => max(2, min(10, (int) $timeout)),
            'redirection' => 2,
            'headers'     => $headers,
            'cookies'     => array(),
        ), 'varnish_behavior_' . sanitize_key((string) $step));
        $duration_ms = (int) round((microtime(true) - $started) * 1000);

        if (is_wp_error($response)) {
            return array(
                'success'         => false,
                'step'            => sanitize_key((string) $step),
                'status'          => 'ERROR',
                'httpCode'        => 0,
                'durationMs'      => $duration_ms,
                'varnishDetected' => false,
                'confidence'      => 'high',
                'evidence'        => 'request-error',
                'message'         => self::sanitize_varnish_string($response->get_error_message()),
                'headers'         => array(),
            );
        }

        $response_code = (int) wp_remote_retrieve_response_code($response);
        $response_message = trim((string) wp_remote_retrieve_response_message($response));
        $headers = array(
            'age'              => self::get_varnish_behavior_response_header($response, 'age'),
            'via'              => self::get_varnish_behavior_response_header($response, 'via'),
            'server'           => self::get_varnish_behavior_response_header($response, 'server'),
            'xVarnish'         => self::get_varnish_behavior_response_header($response, 'x-varnish'),
            'xVarnishCache'    => self::get_varnish_behavior_response_header($response, 'x-varnish-cache'),
            'xCache'           => self::get_varnish_behavior_response_header($response, 'x-cache'),
            'xCacheStatus'     => self::get_varnish_behavior_response_header($response, 'x-cache-status'),
            'xProxyCache'      => self::get_varnish_behavior_response_header($response, 'x-proxy-cache'),
            'cacheControl'       => self::get_varnish_behavior_response_header($response, 'cache-control'),
            'vary'               => self::get_varnish_behavior_response_header($response, 'vary'),
            'cfCacheStatus'      => self::get_varnish_behavior_response_header($response, 'cf-cache-status'),
            'ultraCache'         => self::get_varnish_behavior_response_header($response, 'x-ultra-cache'),
            'ultraCacheSource'   => self::get_varnish_behavior_response_header($response, 'x-ultra-cache-source'),
            'ultraCacheAge'      => self::get_varnish_behavior_response_header($response, 'x-ultra-cache-age'),
            'ultraCacheVariant'  => self::get_varnish_behavior_response_header($response, 'x-ultracache-variant'),
            'ultraCacheCacheable' => self::get_varnish_behavior_response_header($response, 'x-ultracache-cacheable'),
            'ultraCacheSurrogateTtl' => self::get_varnish_behavior_response_header($response, 'x-ultracache-surrogate-ttl'),
            'ultraCacheStaleWhileRevalidate' => self::get_varnish_behavior_response_header($response, 'x-ultracache-stale-while-revalidate'),
            'etag'              => self::get_varnish_behavior_response_header($response, 'etag'),
            'lastModified'      => self::get_varnish_behavior_response_header($response, 'last-modified'),
            'contentLength'     => self::get_varnish_behavior_response_header($response, 'content-length'),
        );
        $classification = self::classify_varnish_behavior_response($headers, $response_code);
        $http_ok = $response_code >= 200 && $response_code < 400;

        return array(
            'success'         => $http_ok,
            'step'            => sanitize_key((string) $step),
            'status'          => (string) $classification['status'],
            'httpCode'        => $response_code,
            'durationMs'      => $duration_ms,
            'varnishDetected' => !empty($classification['varnishDetected']),
            'confidence'      => (string) $classification['confidence'],
            'evidence'        => (string) $classification['evidence'],
            'message'         => self::sanitize_varnish_string('HTTP ' . $response_code . ('' !== $response_message ? ' ' . $response_message : '')),
            'bodyBytes'       => strlen((string) wp_remote_retrieve_body($response)),
            'headers'         => $headers,
        );
    }

    private static function varnish_behavior_steps_completed(array $steps)
    {
        foreach ($steps as $step) {
            if (!is_array($step) || empty($step['success'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate visible evidence for the configured shared HTML TTL.
     *
     * @param array $steps              Behavior-test request steps.
     * @param int   $configured_minutes Configured TTL in minutes.
     * @return array
     */
    private static function evaluate_varnish_shared_html_ttl(array $steps, $configured_minutes)
    {
        $configured_minutes = max(0, min(525600, absint($configured_minutes)));
        $configured_seconds = $configured_minutes * MINUTE_IN_SECONDS;
        if ($configured_seconds <= 0) {
            return array(
                'status' => 'disabled',
                'observed' => false,
                'configuredMinutes' => 0,
                'configuredSeconds' => 0,
                'message' => __('UltraCache is not sending a Varnish shared HTML TTL because the setting is 0.', 'ultracache'),
            );
        }

        $origin = is_array($steps['afterInvalidation'] ?? null) ? $steps['afterInvalidation'] : array();
        $hit = is_array($steps['verification'] ?? null) ? $steps['verification'] : array();
        if (empty($origin['success']) || empty($hit['success'])) {
            return array(
                'status' => 'inconclusive',
                'observed' => false,
                'configuredMinutes' => $configured_minutes,
                'configuredSeconds' => $configured_seconds,
                'message' => __('The shared HTML TTL could not be checked because the post-invalidation refill sequence did not complete.', 'ultracache'),
            );
        }

        $origin_cache_control = strtolower((string) ($origin['headers']['cacheControl'] ?? ''));
        $hit_cache_control = strtolower((string) ($hit['headers']['cacheControl'] ?? ''));
        $expected_pattern = '/(?:^|[,\s])s-maxage\s*=\s*' . preg_quote((string) $configured_seconds, '/') . '(?:$|[,\s])/';
        $origin_header = 1 === preg_match($expected_pattern, $origin_cache_control);
        $hit_header = 1 === preg_match($expected_pattern, $hit_cache_control);
        $surrogate_ttl = trim((string) ($hit['headers']['ultraCacheSurrogateTtl'] ?? ''));
        $surrogate_header = ctype_digit($surrogate_ttl) && (int) $surrogate_ttl === $configured_seconds;
        $hit_verified = 'HIT' === strtoupper((string) ($hit['status'] ?? ''));
        $age_raw = trim((string) ($hit['headers']['age'] ?? ''));
        $age = ctype_digit($age_raw) ? (int) $age_raw : null;
        $observed = $origin_header && $hit_verified && ($hit_header || $surrogate_header);

        if ($observed) {
            $status = 'observed';
            $message = __('The configured shared HTML TTL was visible on the refilled response and the following request was a verified Varnish HIT.', 'ultracache');
        } elseif (!$origin_header) {
            $status = 'header-missing';
            $message = __('The configured shared HTML TTL was not visible on the post-invalidation origin response. Existing server policy may be replacing or hiding Cache-Control.', 'ultracache');
        } else {
            $status = 'inconclusive';
            $message = __('The configured shared HTML TTL was emitted, but the test could not verify the corresponding Varnish HIT behavior.', 'ultracache');
        }

        return array(
            'status' => $status,
            'observed' => $observed,
            'configuredMinutes' => $configured_minutes,
            'configuredSeconds' => $configured_seconds,
            'originHeaderObserved' => $origin_header,
            'hitHeaderObserved' => $hit_header || $surrogate_header,
            'hitVerified' => $hit_verified,
            'age' => $age,
            'message' => $message,
        );
    }

    public static function varnish_test_behavior()
    {
        self::reset_settings_cache();
        $settings = self::get_varnish_cli_settings();
        $support = is_array($settings['support'] ?? null) ? $settings['support'] : array();
        self::set_varnish_refresh_ahead_capability(array(
            'supported' => false,
            'status' => 'not-verified',
            'message' => self::maybe_translate('Refresh-ahead capability has not been verified by the current behavior test.'),
            'ageObserved' => false,
            'observedAge' => null,
            'testedAt' => time(),
        ), $settings);

        if (empty($support['available'])) {
            $result = array(
                'success'  => false,
                'verified' => false,
                'testType' => 'behavior',
                'status'   => 'unavailable',
                'message'  => self::sanitize_varnish_string((string) ($support['message'] ?? 'Varnish integration is unavailable.')),
                'time'     => time(),
            );
            self::set_varnish_last_result($result);
            return $result;
        }

        if (empty($settings['enabled'])) {
            $result = array(
                'success'  => false,
                'verified' => false,
                'testType' => 'behavior',
                'status'   => 'disabled',
                'message'  => __('Varnish integration is disabled.', 'ultracache'),
                'time'     => time(),
            );
            self::set_varnish_last_result($result);
            return $result;
        }

        if (empty($settings['servers'])) {
            $result = array(
                'success'  => false,
                'verified' => false,
                'testType' => 'behavior',
                'status'   => 'not-configured',
                'message'  => __('No Varnish endpoints are configured.', 'ultracache'),
                'time'     => time(),
            );
            self::set_varnish_last_result($result);
            return $result;
        }

        $url = home_url('/');
        if (!ultracache_is_trusted_loopback_url($url)) {
            $result = array(
                'success'  => false,
                'verified' => false,
                'testType' => 'behavior',
                'status'   => 'blocked-url',
                'message'  => __('The site front-page URL is not allowed by the UltraCache loopback policy.', 'ultracache'),
                'time'     => time(),
            );
            self::set_varnish_last_result($result);
            return $result;
        }

        $timeout = max(2, min(5, (int) $settings['timeout']));
        $steps = array();
        $steps['first'] = self::run_varnish_behavior_request($url, 'first', $timeout);
        if (!empty($steps['first']['success'])) {
            $steps['second'] = self::run_varnish_behavior_request($url, 'second', $timeout);
        }

        $baseline_completed = isset($steps['first'], $steps['second'])
            && self::varnish_behavior_steps_completed(array(
                $steps['first'],
                $steps['second'],
            ));
        $invalidation = array(
            'success' => false,
            'details' => array(),
        );
        if ($baseline_completed) {
            $invalidation = self::varnish_flush_url_hard($url);
        }

        $invalidation_success = !empty($invalidation['success']);
        if ($invalidation_success) {
            $steps['afterInvalidation'] = self::run_varnish_behavior_request($url, 'after_invalidation', $timeout);
            if (!empty($steps['afterInvalidation']['success'])) {
                $steps['verification'] = self::run_varnish_behavior_request($url, 'verification', $timeout);
            }
        }

        $requests_completed = $baseline_completed
            && isset($steps['afterInvalidation'], $steps['verification'])
            && self::varnish_behavior_steps_completed(array(
                $steps['afterInvalidation'],
                $steps['verification'],
            ));
        $varnish_detected = false;
        foreach ($steps as $step) {
            if (!empty($step['varnishDetected'])) {
                $varnish_detected = true;
                break;
            }
        }

        $after_status = strtoupper((string) ($steps['afterInvalidation']['status'] ?? ''));
        $verification_status = strtoupper((string) ($steps['verification']['status'] ?? ''));
        $invalidation_verified = in_array($after_status, array('MISS', 'STALE'), true);
        $hit_verified = 'HIT' === $verification_status;
        $verified = $requests_completed && $invalidation_success && $varnish_detected && $invalidation_verified && $hit_verified;
        $variant_test = array(
            'status' => 'not-run',
            'compatible' => false,
            'message' => __('Varnish HTML variant compatibility was not tested because the base cache behavior was not verified.', 'ultracache'),
            'profiles' => array(),
        );
        $scope_test = array(
            'capability' => self::get_varnish_html_flush_capability(),
            'steps' => array(),
            'invalidation' => array('success' => false, 'details' => array()),
        );
        $soft_purge_test = array(
            'capability' => self::get_varnish_soft_purge_capability($settings),
            'steps' => array(),
            'invalidation' => array('success' => false, 'details' => array()),
        );
        $conditional_revalidation_test = array(
            'status' => 'not-run',
            'observed' => false,
            'etagAvailable' => false,
            'lastModifiedAvailable' => false,
            'etagObserved' => false,
            'lastModifiedObserved' => false,
            'source' => '',
            'etagStep' => array(),
            'lastModifiedStep' => array(),
            'message' => __('Conditional HTML validators were not tested because the base cache behavior was not verified.', 'ultracache'),
        );
        if ($verified) {
            $conditional_revalidation_test = self::run_varnish_conditional_revalidation_test(
                $url,
                $timeout,
                is_array($steps['verification'] ?? null) ? $steps['verification'] : array()
            );
            $variant_test = self::run_varnish_html_variant_test($url, $timeout, $steps);
            $scope_test = self::run_varnish_html_flush_scope_test($url, $timeout);
            $soft_purge_test = self::run_varnish_soft_purge_capability_test($url, $timeout);
        }

        $shared_ttl_test = self::evaluate_varnish_shared_html_ttl($steps, $settings['htmlTtlMinutes'] ?? 0);
        $stale_while_revalidate_test = self::evaluate_varnish_stale_while_revalidate(
            $steps,
            $settings['staleWhileRevalidateSeconds'] ?? 0,
            is_array($soft_purge_test['capability'] ?? null) ? $soft_purge_test['capability'] : array()
        );
        $refresh_ahead_capability = self::evaluate_varnish_refresh_ahead_capability(
            $steps,
            $settings,
            is_array($soft_purge_test['capability'] ?? null) ? $soft_purge_test['capability'] : array(),
            is_array($shared_ttl_test) ? $shared_ttl_test : array(),
            $verified
        );
        if (!empty($refresh_ahead_capability['supported']) && !empty(self::get_dashboard_settings()['varnishRefreshAheadEnabled'])) {
            self::ensure_cron_warm_events_scheduled(5, true);
        }

        if (!$baseline_completed) {
            $status = 'request-failed';
            $message = __('Varnish cache behavior test could not complete the initial public front-page requests.', 'ultracache');
        } elseif (!$invalidation_success) {
            $status = 'invalidation-failed';
            $message = __('Varnish cache behavior test stopped because front-page invalidation failed.', 'ultracache');
        } elseif (!$requests_completed) {
            $status = 'request-failed';
            $message = __('Varnish cache behavior test could not complete the post-invalidation public front-page requests.', 'ultracache');
        } elseif ($verified) {
            $status = 'verified';
            $message = __('Varnish cache behavior verified: the front page was invalidated, refilled, and returned as a cache HIT.', 'ultracache');
        } elseif (!$varnish_detected) {
            $status = 'inconclusive';
            $message = __('The test completed, but Varnish-specific response headers were not visible, so cache behavior could not be verified.', 'ultracache');
        } elseif ('BYPASS' === $verification_status) {
            $status = 'bypassed';
            $message = __('Varnish was detected, but the public front page remained bypassed instead of becoming a cache HIT.', 'ultracache');
        } elseif (!$invalidation_verified) {
            $status = 'invalidation-inconclusive';
            $message = __('Varnish was detected, but the post-invalidation request did not provide enough evidence of a MISS or stale response.', 'ultracache');
        } else {
            $status = 'hit-inconclusive';
            $message = __('Varnish was detected and invalidation completed, but the final cache HIT could not be verified.', 'ultracache');
        }

        $ban_pressure = array(
            'status' => 'not-applicable',
            'available' => false,
            'message' => __('Ban-list diagnostics apply only to authenticated Varnish admin mode.', 'ultracache'),
        );
        if ('admin' === (string) ($settings['mode'] ?? 'http')) {
            $ban_pressure = self::collect_varnish_ban_pressure();
        }

        $result = array(
            'success'              => $requests_completed && $invalidation_success,
            'verified'             => $verified,
            'testType'             => 'behavior',
            'status'               => $status,
            'message'              => $message,
            'time'                 => time(),
            'url'                  => esc_url_raw($url),
            'mode'                 => (string) ($settings['mode'] ?? 'http'),
            'method'               => (string) ($settings['method'] ?? 'BAN'),
            'effectiveMethod'      => (string) ($settings['effectiveMethod'] ?? ''),
            'endpointCount'        => (int) ($settings['endpointCount'] ?? 0),
            'varnishDetected'      => $varnish_detected,
            'invalidationAttempted' => $baseline_completed,
            'invalidationAccepted' => $invalidation_success,
            'invalidationVerified' => $invalidation_verified,
            'hitVerified'          => $hit_verified,
            'steps'                => $steps,
            'variantTest'          => is_array($variant_test) ? $variant_test : array(),
            'sharedTtlTest'        => is_array($shared_ttl_test) ? $shared_ttl_test : array(),
            'staleWhileRevalidateTest' => is_array($stale_while_revalidate_test) ? $stale_while_revalidate_test : array(),
            'refreshAheadCapability' => is_array($refresh_ahead_capability) ? $refresh_ahead_capability : array(),
            'conditionalRevalidationTest' => is_array($conditional_revalidation_test) ? $conditional_revalidation_test : array(),
            'htmlFlushCapability'  => is_array($scope_test['capability'] ?? null) ? $scope_test['capability'] : array(),
            'htmlFlushSteps'       => is_array($scope_test['steps'] ?? null) ? $scope_test['steps'] : array(),
            'htmlFlushInvalidation' => is_array($scope_test['invalidation'] ?? null) ? $scope_test['invalidation'] : array(),
            'softPurgeCapability' => is_array($soft_purge_test['capability'] ?? null) ? $soft_purge_test['capability'] : array(),
            'softPurgeSteps' => is_array($soft_purge_test['steps'] ?? null) ? $soft_purge_test['steps'] : array(),
            'softPurgeInvalidation' => is_array($soft_purge_test['invalidation'] ?? null) ? $soft_purge_test['invalidation'] : array(),
            'details'              => is_array($invalidation['details'] ?? null) ? $invalidation['details'] : array(),
            'banPressure'          => is_array($ban_pressure) ? $ban_pressure : array(),
        );

        self::set_varnish_last_result($result);
        return $result;
    }
}
