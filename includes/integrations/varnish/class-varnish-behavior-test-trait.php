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
    /**
     * Normalize a weak or strong ETag for diagnostic comparisons.
     *
     * Used by the compact Varnish connection, invalidation, and refill test.
     *
     * @param string $etag Raw ETag header value.
     * @return string
     */
    protected static function normalize_varnish_conditional_etag($etag)
    {
        $etag = trim(substr((string) $etag, 0, 500));
        if (0 === stripos($etag, 'W/')) {
            $etag = trim(substr($etag, 2));
        }

        return $etag;
    }

    protected static function run_varnish_behavior_request(
        $url,
        $step,
        $timeout,
        $accept = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        array $request_headers = array()
    )
    {
        $headers = array(
            'Accept'          => sanitize_text_field((string) $accept),
            'Accept-Encoding' => 'identity',
        );
        $allowed_headers = array(
            'If-None-Match',
            'If-Modified-Since',
            'Cookie',
            'Authorization',
            'Cache-Control',
            'Pragma',
        );
        foreach ($allowed_headers as $allowed_header) {
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
            'redirection' => 0,
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
            'age'              => self::get_varnish_response_header($response, 'age'),
            'via'              => self::get_varnish_response_header($response, 'via'),
            'server'           => self::get_varnish_response_header($response, 'server'),
            'xVarnish'         => self::get_varnish_response_header($response, 'x-varnish'),
            'xVarnishCache'    => self::get_varnish_response_header($response, 'x-varnish-cache'),
            'xCache'           => self::get_varnish_response_header($response, 'x-cache'),
            'xCacheStatus'     => self::get_varnish_response_header($response, 'x-cache-status'),
            'xProxyCache'      => self::get_varnish_response_header($response, 'x-proxy-cache'),
            'cacheControl'       => self::get_varnish_response_header($response, 'cache-control'),
            'surrogateControl'   => self::get_varnish_response_header($response, 'surrogate-control'),
            'pragma'             => self::get_varnish_response_header($response, 'pragma'),
            'vary'               => self::get_varnish_response_header($response, 'vary'),
            'cfCacheStatus'      => self::get_varnish_response_header($response, 'cf-cache-status'),
            'ultraCache'         => self::get_varnish_response_header($response, 'x-ultra-cache'),
            'ultraCacheSource'   => self::get_varnish_response_header($response, 'x-ultra-cache-source'),
            'ultraCacheAge'      => self::get_varnish_response_header($response, 'x-ultra-cache-age'),
            'ultraCacheVariant'  => self::get_varnish_response_header($response, 'x-ultracache-variant'),
            'ultraCacheCacheable' => self::get_varnish_response_header($response, 'x-ultracache-cacheable'),
            'ultraCacheSurrogateTtl' => self::get_varnish_response_header($response, 'x-ultracache-surrogate-ttl'),
            'ultraCacheStaleWhileRevalidate' => self::get_varnish_response_header($response, 'x-ultracache-stale-while-revalidate'),
            'etag'              => self::get_varnish_response_header($response, 'etag'),
            'lastModified'      => self::get_varnish_response_header($response, 'last-modified'),
            'contentLength'     => self::get_varnish_response_header($response, 'content-length'),
            'contentEncoding'   => self::get_varnish_response_header($response, 'content-encoding'),
            'contentType'       => self::get_varnish_response_header($response, 'content-type'),
            'warning'           => self::get_varnish_response_header($response, 'warning'),
        );
        $headers['setCookiePresent'] = '' !== trim((string) wp_remote_retrieve_header($response, 'set-cookie'));
        $classification = self::classify_varnish_response($headers, $response_code);
        $http_ok = ($response_code >= 200 && $response_code < 300) || 304 === $response_code;
        $response_body = (string) wp_remote_retrieve_body($response);

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
            'bodyBytes'       => strlen($response_body),
            'bodySha256'      => '' !== $response_body ? hash('sha256', $response_body) : '',
            'headers'         => $headers,
        );
    }

    protected static function varnish_behavior_steps_completed(array $steps)
    {
        foreach ($steps as $step) {
            if (!is_array($step) || empty($step['success'])) {
                return false;
            }
        }

        return true;
    }

    private static function classify_varnish_basic_invalidation_failure(array $invalidation)
    {
        $parts = array((string) ($invalidation['message'] ?? ''));
        foreach ((array) ($invalidation['details'] ?? array()) as $detail) {
            if (is_array($detail)) {
                $parts[] = (string) ($detail['detail'] ?? '');
            }
        }
        $evidence = strtolower(implode(' ', $parts));

        return preg_match('/\b(auth(?:entication)?|secret|challenge|permission denied|access denied|unauthorized|forbidden|401|403)\b/i', $evidence)
            ? 'authentication-failed'
            : 'invalidation-failed';
    }

    private static function run_varnish_basic_test_invalidation($url)
    {
        self::begin_varnish_test_run();
        try {
            return self::varnish_flush_url_hard($url);
        } finally {
            self::end_varnish_test_run();
        }
    }

    public static function varnish_test_behavior()
    {
        self::reset_settings_cache();
        $settings = self::get_varnish_cli_settings();
        $support = is_array($settings['support'] ?? null) ? $settings['support'] : array();
        $tested_at = time();

        if (empty($support['available'])) {
            $result = array(
                'success' => false,
                'verified' => false,
                'testType' => 'basic',
                'operationType' => 'diagnostic-basic-test',
                'status' => 'configuration-incomplete',
                'message' => self::sanitize_varnish_string((string) ($support['message'] ?? 'Varnish integration is unavailable.')),
                'time' => $tested_at,
            );
            return $result;
        }

        if (empty($settings['enabled'])) {
            $result = array(
                'success' => false,
                'verified' => false,
                'testType' => 'basic',
                'operationType' => 'diagnostic-basic-test',
                'status' => 'configuration-incomplete',
                'message' => __('Varnish integration is disabled.', 'ultracache'),
                'time' => $tested_at,
            );
            return $result;
        }

        if (empty($settings['servers'])) {
            $result = array(
                'success' => false,
                'verified' => false,
                'testType' => 'basic',
                'operationType' => 'diagnostic-basic-test',
                'status' => 'configuration-incomplete',
                'message' => __('No Varnish endpoints are configured.', 'ultracache'),
                'time' => $tested_at,
            );
            return $result;
        }

        $normalized = self::normalize_varnish_invalidation_url(home_url('/'));
        $url = !empty($normalized['valid']) ? esc_url_raw((string) ($normalized['url'] ?? '')) : '';
        if ('' === $url || !ultracache_is_trusted_loopback_url($url)) {
            $result = array(
                'success' => false,
                'verified' => false,
                'testType' => 'basic',
                'operationType' => 'diagnostic-basic-test',
                'status' => 'configuration-incomplete',
                'message' => __('The canonical front-page URL is not eligible for the Varnish test.', 'ultracache'),
                'time' => $tested_at,
            );
            return $result;
        }

        $timeout = max(2, min(5, (int) ($settings['timeout'] ?? 5)));
        $steps = array();
        $steps['beforeInvalidation'] = self::run_varnish_behavior_request($url, 'basic_before_invalidation', $timeout);

        $invalidation = array(
            'success' => false,
            'details' => array(),
        );
        if (!empty($steps['beforeInvalidation']['success'])) {
            $invalidation = self::run_varnish_basic_test_invalidation($url);
        }

        $invalidation_success = !empty($invalidation['success']);
        if ($invalidation_success) {
            $steps['afterInvalidation'] = self::run_varnish_behavior_request($url, 'basic_after_invalidation', $timeout);
            if (!empty($steps['afterInvalidation']['success'])) {
                // Give the newly refilled object time to become observable as a public Varnish HIT.
                sleep(2);
                $steps['verification'] = self::run_varnish_behavior_request($url, 'basic_refill_verification', $timeout);
            }
        }

        $before_completed = !empty($steps['beforeInvalidation']['success']);
        $refill_completed = isset($steps['afterInvalidation'], $steps['verification'])
            && self::varnish_behavior_steps_completed(array(
                $steps['afterInvalidation'],
                $steps['verification'],
            ));
        $after_status = strtoupper((string) ($steps['afterInvalidation']['status'] ?? ''));
        $verification_status = strtoupper((string) ($steps['verification']['status'] ?? ''));
        $invalidation_verified = in_array($after_status, array('MISS', 'STALE'), true);
        $hit_verified = 'HIT' === $verification_status;
        $varnish_detected = false;
        foreach ($steps as $step) {
            if (!empty($step['varnishDetected'])) {
                $varnish_detected = true;
                break;
            }
        }

        $visible_sequence_verified = $invalidation_success
            && $refill_completed
            && $varnish_detected
            && $invalidation_verified
            && $hit_verified;
        $cache_signals_hidden = $invalidation_success
            && $refill_completed
            && (!$varnish_detected || ('INCONCLUSIVE' === $after_status && 'INCONCLUSIVE' === $verification_status));

        if (!$before_completed) {
            $status = 'refill-failed';
            $message = __('The public canonical page could not be requested before invalidation.', 'ultracache');
            $success = false;
        } elseif (!$invalidation_success) {
            $status = self::classify_varnish_basic_invalidation_failure($invalidation);
            $message = 'authentication-failed' === $status
                ? __('Varnish connection or authentication failed.', 'ultracache')
                : __('Varnish exact URL invalidation failed.', 'ultracache');
            $success = false;
        } elseif (!$refill_completed) {
            $status = 'refill-failed';
            $message = __('Varnish invalidation succeeded, but the public refill sequence did not complete.', 'ultracache');
            $success = false;
        } elseif ($visible_sequence_verified) {
            $status = 'working';
            $message = __('Varnish is working: the canonical page was invalidated, refilled, and returned as a cache HIT.', 'ultracache');
            $success = true;
        } elseif ($cache_signals_hidden || ('INCONCLUSIVE' === $after_status && $hit_verified)) {
            $status = 'working-signals-hidden';
            $message = __('Varnish invalidation and public refill completed, but cache HIT/MISS signals are hidden or incomplete.', 'ultracache');
            $success = true;
        } elseif ('HIT' === $after_status) {
            $status = 'invalidation-failed';
            $message = __('Varnish accepted the invalidation request, but the visible public object remained a cache HIT.', 'ultracache');
            $success = false;
        } else {
            $status = 'refill-failed';
            $message = __('Varnish invalidation completed, but the public page did not return as a cache HIT.', 'ultracache');
            $success = false;
        }

        $result = array(
            'success' => $success,
            'verified' => $visible_sequence_verified,
            'testType' => 'basic',
            'operationType' => 'diagnostic-basic-test',
            'status' => $status,
            'message' => $message,
            'time' => $tested_at,
            'url' => $url,
            'mode' => (string) ($settings['mode'] ?? 'http'),
            'method' => (string) ($settings['method'] ?? 'BAN'),
            'effectiveMethod' => (string) ($settings['effectiveMethod'] ?? ''),
            'endpointCount' => (int) ($settings['endpointCount'] ?? count((array) ($settings['servers'] ?? array()))),
            'varnishDetected' => $varnish_detected,
            'cacheSignalsHidden' => $cache_signals_hidden,
            'connectionTested' => $before_completed,
            'connectionVerified' => $invalidation_success,
            'invalidationAttempted' => $before_completed,
            'invalidationAccepted' => $invalidation_success,
            'invalidationVerified' => $invalidation_verified,
            'hitVerified' => $hit_verified,
            'steps' => $steps,
            'connectionDetails' => is_array($invalidation['details'] ?? null) ? $invalidation['details'] : array(),
            'details' => is_array($invalidation['details'] ?? null) ? $invalidation['details'] : array(),
        );

        return $result;
    }
}
