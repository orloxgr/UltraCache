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

    /**
     * Build bounded request headers for one public or endpoint-isolated probe.
     *
     * @param string $accept          Accept header value.
     * @param array  $request_headers Optional conditional/session headers.
     * @param string $host_header     Optional virtual-host header for a direct endpoint request.
     * @return array<string,string>
     */
    private static function build_varnish_behavior_request_headers($accept, array $request_headers = array(), $host_header = '')
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

        $host_header = strtolower(trim(preg_replace('/[\r\n\t]+/', '', (string) $host_header)));
        if ('' !== $host_header && strlen($host_header) <= 255) {
            $headers['Host'] = $host_header;
        }

        return $headers;
    }

    /**
     * Normalize one HTTP API response into the bounded behavior-test shape.
     *
     * @param array|WP_Error $response    HTTP response.
     * @param string         $step        Stable step label.
     * @param int            $duration_ms Request duration.
     * @param string         $endpoint    Optional configured endpoint label.
     * @return array<string,mixed>
     */
    private static function normalize_varnish_behavior_response($response, $step, $duration_ms, $endpoint = '')
    {
        $step = sanitize_key((string) $step);
        $endpoint = self::normalize_varnish_registry_endpoint($endpoint);
        if (is_wp_error($response)) {
            return array(
                'success'         => false,
                'step'            => $step,
                'status'          => 'ERROR',
                'httpCode'        => 0,
                'durationMs'      => (int) $duration_ms,
                'varnishDetected' => false,
                'confidence'      => 'high',
                'evidence'        => 'request-error',
                'message'         => self::sanitize_varnish_string($response->get_error_message()),
                'endpoint'        => $endpoint,
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
        $canary_identifier = '';
        $canary_generation = 0;
        if (preg_match('/ULTRACACHE-VARNISH-CANARY:([a-f0-9]{32}):GENERATION-([1-9])/', $response_body, $canary_matches)) {
            $canary_identifier = strtolower((string) $canary_matches[1]);
            $canary_generation = (int) $canary_matches[2];
        }

        return array(
            'success'         => $http_ok,
            'step'            => $step,
            'status'          => (string) $classification['status'],
            'httpCode'        => $response_code,
            'durationMs'      => (int) $duration_ms,
            'varnishDetected' => !empty($classification['varnishDetected']),
            'confidence'      => (string) $classification['confidence'],
            'evidence'        => (string) $classification['evidence'],
            'message'         => self::sanitize_varnish_string('HTTP ' . $response_code . ('' !== $response_message ? ' ' . $response_message : '')),
            'bodyBytes'       => strlen($response_body),
            'bodySha256'      => '' !== $response_body ? hash('sha256', $response_body) : '',
            'canaryIdentifier' => $canary_identifier,
            'canaryGeneration' => $canary_generation,
            'endpoint'        => $endpoint,
            'headers'         => $headers,
        );
    }

    protected static function run_varnish_behavior_request(
        $url,
        $step,
        $timeout,
        $accept = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        array $request_headers = array()
    )
    {
        $headers = self::build_varnish_behavior_request_headers($accept, $request_headers);
        $started = microtime(true);
        $response = ultracache_safe_loopback_remote_request($url, array(
            'method'      => 'GET',
            'timeout'     => max(2, min(10, (int) $timeout)),
            'redirection' => 0,
            'headers'     => $headers,
            'cookies'     => array(),
        ), 'varnish_behavior_' . sanitize_key((string) $step));

        return self::normalize_varnish_behavior_response(
            $response,
            $step,
            (int) round((microtime(true) - $started) * 1000)
        );
    }

    /**
     * Request one same-origin route through one configured HTTP data-plane endpoint.
     *
     * @param string $endpoint_label Configured Varnish endpoint.
     * @param string $url            Same-origin public canary URL.
     * @param string $step           Stable step label.
     * @param int    $timeout        Request timeout.
     * @param string $accept         Accept header value.
     * @param array  $request_headers Optional conditional/session headers.
     * @return array<string,mixed>
     */
    private static function run_varnish_endpoint_behavior_request(
        $endpoint_label,
        $url,
        $step,
        $timeout,
        $accept = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        array $request_headers = array()
    )
    {
        $endpoint_label = self::normalize_varnish_registry_endpoint($endpoint_label);
        $url = esc_url_raw((string) $url);
        if ('' === $endpoint_label || '' === $url || !ultracache_is_trusted_loopback_url($url)) {
            return self::normalize_varnish_behavior_response(
                new WP_Error('ultracache_varnish_endpoint_canary_url_invalid', __('The endpoint canary URL is not a trusted local frontend URL.', 'ultracache')),
                $step,
                0,
                $endpoint_label
            );
        }

        $endpoint_check = self::validate_varnish_http_endpoint($endpoint_label);
        $endpoint = self::normalize_varnish_endpoint($endpoint_label);
        $public = wp_parse_url($url);
        if (empty($endpoint_check['valid']) || empty($endpoint) || !is_array($public) || empty($public['host'])) {
            $message = !empty($endpoint_check['message'])
                ? (string) $endpoint_check['message']
                : __('The configured Varnish endpoint could not be used for an isolated canary request.', 'ultracache');
            return self::normalize_varnish_behavior_response(
                new WP_Error('ultracache_varnish_endpoint_canary_unavailable', $message),
                $step,
                0,
                $endpoint_label
            );
        }

        $path = isset($public['path']) ? (string) $public['path'] : '/';
        if ('' === $path) {
            $path = '/';
        }
        $query = isset($public['query']) ? (string) $public['query'] : '';
        $target = $path . ('' !== $query ? '?' . $query : '');
        if (strlen($target) > 8192 || preg_match('/[\x00\r\n]/', $target)) {
            return self::normalize_varnish_behavior_response(
                new WP_Error('ultracache_varnish_endpoint_canary_target_invalid', __('The endpoint canary request target is invalid.', 'ultracache')),
                $step,
                0,
                $endpoint_label
            );
        }

        $host_header = strtolower(rtrim((string) $public['host'], '.'));
        if (false !== strpos($host_header, ':') && '[' !== substr($host_header, 0, 1)) {
            $host_header = '[' . $host_header . ']';
        }
        $public_scheme = strtolower((string) ($public['scheme'] ?? 'http'));
        $public_port = absint($public['port'] ?? 0);
        $default_port = 'https' === $public_scheme ? 443 : 80;
        if ($public_port > 0 && $public_port !== $default_port) {
            $host_header .= ':' . $public_port;
        }
        $headers = self::build_varnish_behavior_request_headers($accept, $request_headers, $host_header);
        $target_url = self::build_varnish_target_url($endpoint, $target);
        $started = microtime(true);
        $response = ultracache_safe_configured_infrastructure_remote_request($target_url, array(
            'method'      => 'GET',
            'timeout'     => max(2, min(10, (int) $timeout)),
            'redirection' => 0,
            'headers'     => $headers,
            'cookies'     => array(),
        ), 'varnish_endpoint_behavior_' . sanitize_key((string) $step));

        return self::normalize_varnish_behavior_response(
            $response,
            $step,
            (int) round((microtime(true) - $started) * 1000),
            $endpoint_label
        );
    }

    /**
     * Observe one canary through the canonical public WordPress frontend.
     *
     * Configured HTTP endpoints are control-plane targets for PURGE/BAN. They
     * are not required to serve ordinary GET requests. Capability behavior is
     * therefore observed through the public site while the control operation
     * is sent only to the configured endpoint under test.
     *
     * @param string $url             Public canary URL.
     * @param string $step            Stable diagnostic step.
     * @param int    $timeout         Request timeout.
     * @param string $accept          Accept header value.
     * @param array  $request_headers Optional conditional/session headers.
     * @return array<string,mixed>
     */
    private static function run_varnish_public_capability_observation_request(
        $url,
        $step,
        $timeout,
        $accept = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        array $request_headers = array()
    ) {
        return self::run_varnish_behavior_request(
            $url,
            $step,
            $timeout,
            $accept,
            $request_headers
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


    /**
     * Return whether one response contains the expected canary generation.
     *
     * @param array  $step       Response step.
     * @param string $identifier Canary identifier.
     * @param int    $generation Expected generation.
     * @return bool
     */
    private static function varnish_canary_step_matches(array $step, $identifier, $generation)
    {
        return !empty($step['success'])
            && strtolower((string) ($step['canaryIdentifier'] ?? '')) === strtolower((string) $identifier)
            && (int) ($step['canaryGeneration'] ?? 0) === (int) $generation;
    }

    /**
     * Return whether one response matches the exact artifact written for a
     * canary generation. The body marker remains the primary proof, while the
     * deterministic response body hash and ETag provide equivalent identity
     * evidence when an intermediary changes body parsing without changing the
     * cached object.
     *
     * @param array  $step               Response step.
     * @param string $identifier         Canary identifier.
     * @param int    $generation         Expected generation.
     * @param array  $written_generation Result returned by write_varnish_canary_generation().
     * @return bool
     */
    private static function varnish_canary_step_matches_written_generation(
        array $step,
        $identifier,
        $generation,
        array $written_generation
    ) {
        if (self::varnish_canary_step_matches($step, $identifier, $generation)) {
            return true;
        }

        if (empty($step['success'])) {
            return false;
        }

        $expected_sha256 = strtolower(trim((string) ($written_generation['bodySha256'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $expected_sha256)) {
            return false;
        }

        $response_sha256 = strtolower(trim((string) ($step['bodySha256'] ?? '')));
        if (hash_equals($expected_sha256, $response_sha256)) {
            return true;
        }

        $response_etag = self::normalize_varnish_conditional_etag(
            (string) ($step['headers']['etag'] ?? '')
        );
        $expected_etag = '"uc-varnish-' . $expected_sha256 . '"';

        return '' !== $response_etag && hash_equals($expected_etag, $response_etag);
    }

    /**
     * Observe a post-operation canary until two matching public responses are
     * collected, a successful mismatch proves that the operation did not expose
     * the expected generation, or the bounded attempt budget is exhausted.
     *
     * Failed HTTP/DNS observations are transport noise on the public data path;
     * they do not negate an accepted control operation. Two successful matching
     * observations are still required so the capability proof covers both the
     * first renewed object and its stable refill.
     *
     * @param callable $request            Receives the zero-based attempt index and returns one normalized response step.
     * @param string   $identifier         Canary identifier.
     * @param int      $generation         Expected generation.
     * @param array    $written_generation Optional exact written artifact identity.
     * @param int      $required_matches   Required matching successful observations.
     * @param int      $max_attempts       Maximum total observations.
     * @return array<string,mixed>
     */
    private static function run_varnish_canary_post_operation_proof(
        callable $request,
        $identifier,
        $generation,
        array $written_generation = array(),
        $required_matches = 2,
        $max_attempts = 4
    ) {
        $required_matches = max(1, min(2, absint($required_matches)));
        $max_attempts = max($required_matches, min(4, absint($max_attempts)));
        $attempts = array();
        $matching_count = 0;
        $mismatching_count = 0;
        $successful_count = 0;
        $failed_count = 0;

        for ($attempt_index = 0; $attempt_index < $max_attempts; $attempt_index++) {
            $step = call_user_func($request, $attempt_index);
            if (!is_array($step)) {
                $step = array();
            }
            $attempts[] = $step;

            if (empty($step['success'])) {
                $failed_count++;
            } else {
                $successful_count++;
                $matches = !empty($written_generation)
                    ? self::varnish_canary_step_matches_written_generation(
                        $step,
                        $identifier,
                        $generation,
                        $written_generation
                    )
                    : self::varnish_canary_step_matches($step, $identifier, $generation);
                if ($matches) {
                    $matching_count++;
                } else {
                    $mismatching_count++;
                }
            }

            if ($mismatching_count > 0 || $matching_count >= $required_matches) {
                break;
            }
        }

        $verified = $matching_count >= $required_matches && 0 === $mismatching_count;
        $conclusive = $verified || $mismatching_count > 0;

        return array(
            'verified' => $verified,
            'conclusive' => $conclusive,
            'behaviorMismatchObserved' => $mismatching_count > 0,
            'observationIncomplete' => !$conclusive,
            'requiredMatchingObservationCount' => $required_matches,
            'matchingObservationCount' => $matching_count,
            'mismatchingObservationCount' => $mismatching_count,
            'successfulObservationCount' => $successful_count,
            'failedObservationCount' => $failed_count,
            'attemptCount' => count($attempts),
            'attempts' => $attempts,
        );
    }

    /**
     * Probe one public canary route for stable shared-cache storage.
     *
     * The origin file is changed from generation 1 to generation 2 without
     * changing the URL. A route is cacheable only when the public response still
     * returns generation 1 after the local file already contains generation 2.
     *
     * @param string $identifier Canary identifier.
     * @param string $route      Route label.
     * @param string $url        Public canary URL.
     * @param int    $timeout    Request timeout.
     * @param string $endpoint   Optional configured endpoint for direct node isolation.
     * @return array
     */
    private static function probe_varnish_canary_route($identifier, $route, $url, $timeout, $endpoint = '')
    {
        $route = sanitize_key((string) $route);
        $url = esc_url_raw((string) $url);
        $endpoint = self::normalize_varnish_registry_endpoint($endpoint);
        $result = array(
            'route' => $route,
            'url' => $url,
            'available' => false,
            'cacheable' => false,
            'status' => 'unavailable',
            'message' => '',
            'steps' => array(),
        );

        if ('' === $url || !ultracache_is_trusted_loopback_url($url)) {
            $result['status'] = 'untrusted-url';
            $result['message'] = __('The canary route is not a trusted local frontend URL.', 'ultracache');
            return $result;
        }

        $generation_one = self::write_varnish_canary_generation($identifier, 1);
        if (is_wp_error($generation_one)) {
            $result['status'] = 'write-failed';
            $result['message'] = self::sanitize_varnish_string($generation_one->get_error_message());
            return $result;
        }

        $request = static function ($request_url, $request_step) use ($endpoint, $timeout) {
            if ('' !== $endpoint) {
                return self::run_varnish_endpoint_behavior_request($endpoint, $request_url, $request_step, $timeout);
            }

            return self::run_varnish_behavior_request($request_url, $request_step, $timeout);
        };

        $result['steps']['warm'] = $request($url, 'canary_' . $route . '_warm');
        $result['steps']['warmVerification'] = $request($url, 'canary_' . $route . '_warm_verification');
        if (!self::varnish_canary_step_matches($result['steps']['warm'], $identifier, 1)
            || !self::varnish_canary_step_matches($result['steps']['warmVerification'], $identifier, 1)) {
            $result['status'] = 'route-not-served';
            $result['message'] = __('The public route did not return the expected Varnish canary response.', 'ultracache');
            return $result;
        }

        $result['available'] = true;
        $generation_two = self::write_varnish_canary_generation($identifier, 2);
        if (is_wp_error($generation_two)) {
            $result['status'] = 'write-failed';
            $result['message'] = self::sanitize_varnish_string($generation_two->get_error_message());
            return $result;
        }

        $result['steps']['beforeInvalidation'] = $request($url, 'canary_' . $route . '_before_invalidation');
        if (self::varnish_canary_step_matches($result['steps']['beforeInvalidation'], $identifier, 1)) {
            $result['cacheable'] = true;
            $result['status'] = 'cached';
            $result['message'] = __('The public route retained generation 1 after the origin canary changed to generation 2.', 'ultracache');
        } elseif (self::varnish_canary_step_matches($result['steps']['beforeInvalidation'], $identifier, 2)) {
            $result['status'] = 'not-cached';
            $result['message'] = __('The public route immediately exposed generation 2, so no stable shared-cache object was available for capability testing.', 'ultracache');
        } else {
            $result['status'] = 'unexpected-response';
            $result['message'] = __('The public route returned an unexpected response after the canary generation changed.', 'ultracache');
        }

        return $result;
    }

    /**
     * Select the first public route that demonstrably stores the canary object.
     *
     * @param string $identifier Canary identifier.
     * @param array  $canary     Canary storage details.
     * @param int    $timeout    Request timeout.
     * @param string $endpoint   Optional configured endpoint for direct node isolation.
     * @return array
     */
    private static function select_varnish_canary_route($identifier, array $canary, $timeout, $endpoint = '')
    {
        $candidates = array(
            'application-path' => array(
                'url' => (string) ($canary['applicationUrl'] ?? ''),
                'runtimeEligible' => true,
            ),
            'application-query' => array(
                'url' => (string) ($canary['queryUrl'] ?? ''),
                'runtimeEligible' => true,
            ),
            'direct-upload' => array(
                'url' => (string) ($canary['directUrl'] ?? ''),
                'runtimeEligible' => false,
            ),
        );
        $attempts = array();

        foreach ($candidates as $route => $candidate) {
            $url = (string) ($candidate['url'] ?? '');
            $runtime_eligible = !empty($candidate['runtimeEligible']);
            $attempt = self::probe_varnish_canary_route($identifier, $route, $url, $timeout, $endpoint);
            $attempt['runtimeEligible'] = $runtime_eligible;
            $attempts[] = $attempt;
            if (!empty($attempt['cacheable'])) {
                return array(
                    'success' => true,
                    'runtimeEligible' => $runtime_eligible,
                    'route' => $route,
                    'url' => $url,
                    'attempt' => $attempt,
                    'attempts' => $attempts,
                );
            }
        }

        return array(
            'success' => false,
            'runtimeEligible' => false,
            'route' => '',
            'url' => '',
            'attempt' => array(),
            'attempts' => $attempts,
        );
    }

    /**
     * Select a cacheable WordPress-served canary route that is also a valid,
     * identity-preserving production invalidation target.
     *
     * @param string $identifier          Canary identifier.
     * @param array  $canary              Canary storage details.
     * @param int    $timeout             Request timeout.
     * @param string $endpoint            Optional HTTP data-plane endpoint.
     * @param array  $reserved_dedupe_keys Canonical targets already selected.
     * @return array<string,mixed>
     */
    private static function select_varnish_production_canary_route(
        $identifier,
        array $canary,
        $timeout,
        $endpoint = '',
        array $reserved_dedupe_keys = array()
    ) {
        $candidates = array(
            'application-path' => (string) ($canary['applicationUrl'] ?? ''),
            'application-query' => (string) ($canary['queryUrl'] ?? ''),
        );
        $reserved = array_fill_keys(array_values(array_filter(array_map('strval', $reserved_dedupe_keys))), true);
        $attempts = array();

        foreach ($candidates as $route => $url) {
            $attempt = self::probe_varnish_canary_route($identifier, $route, $url, $timeout, $endpoint);
            $attempt['runtimeEligible'] = true;
            $attempt['runtimeNormalization'] = array();
            if (!empty($attempt['cacheable'])) {
                $normalized = self::normalize_varnish_invalidation_url($url);
                $attempt['runtimeNormalization'] = $normalized;
                $input = ultracache_safe_wp_parse_url($url, -1, 'select_varnish_production_canary_route input');
                $canonical = ultracache_safe_wp_parse_url((string) ($normalized['url'] ?? ''), -1, 'select_varnish_production_canary_route normalized');
                $input_target = is_array($input)
                    ? ((string) ($input['path'] ?? '/') . (!empty($input['query']) ? '?' . (string) $input['query'] : ''))
                    : '';
                $canonical_target = is_array($canonical)
                    ? ((string) ($canonical['path'] ?? '/') . (!empty($canonical['query']) ? '?' . (string) $canonical['query'] : ''))
                    : '';
                $identity_preserved = !empty($normalized['valid'])
                    && is_array($input)
                    && is_array($canonical)
                    && strtolower(rtrim((string) ($input['host'] ?? ''), '.')) === strtolower(rtrim((string) ($canonical['host'] ?? ''), '.'))
                    && $input_target === $canonical_target;
                $dedupe_key = (string) ($normalized['dedupeKey'] ?? '');

                if ($identity_preserved && '' !== $dedupe_key && !isset($reserved[$dedupe_key])) {
                    $attempts[] = $attempt;
                    return array(
                        'success' => true,
                        'runtimeEligible' => true,
                        'route' => $route,
                        'url' => esc_url_raw($url),
                        'normalizedUrl' => esc_url_raw((string) ($normalized['url'] ?? $url)),
                        'dedupeKey' => $dedupe_key,
                        'attempt' => $attempt,
                        'attempts' => $attempts,
                    );
                }

                $attempt['status'] = !$identity_preserved
                    ? 'production-normalization-changed-target'
                    : 'production-target-duplicate';
                $attempt['message'] = !$identity_preserved
                    ? self::maybe_translate('The cacheable canary route is not an identity-preserving production invalidation target.')
                    : self::maybe_translate('The cacheable canary route normalized to a target already selected for this batch proof.');
            }
            $attempts[] = $attempt;
        }

        return array(
            'success' => false,
            'runtimeEligible' => false,
            'route' => '',
            'url' => '',
            'normalizedUrl' => '',
            'dedupeKey' => '',
            'attempt' => array(),
            'attempts' => $attempts,
        );
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


    /**
     * Determine whether the configured control endpoint authenticated even when
     * the exact invalidation command itself was rejected by the active VCL.
     *
     * @param array $invalidation Bounded invalidation result.
     * @return bool
     */
    private static function varnish_basic_invalidation_control_connection_accepted(array $invalidation)
    {
        if (!empty($invalidation['controlConnectionAccepted'])) {
            return true;
        }

        foreach ((array) ($invalidation['details'] ?? array()) as $detail) {
            if (is_array($detail) && !empty($detail['connectionAccepted'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize an internal exact-operation capability identifier.
     *
     * @param mixed $capability Candidate identifier.
     * @return string
     */
    private static function normalize_varnish_behavior_exact_capability($capability)
    {
        $capability = preg_replace('/[^a-z]/', '', strtolower((string) $capability));
        if ('exactpurge' === $capability) {
            return 'exactPurge';
        }
        if ('exactban' === $capability) {
            return 'exactBan';
        }

        return '';
    }

    /**
     * Normalize and deduplicate exact-operation identifiers.
     *
     * @param array $capabilities Candidate identifiers.
     * @return array<int,string>
     */
    private static function normalize_varnish_behavior_exact_capabilities(array $capabilities)
    {
        $normalized = array();
        foreach ($capabilities as $capability) {
            $capability = self::normalize_varnish_behavior_exact_capability($capability);
            if ('' !== $capability) {
                $normalized[$capability] = true;
            }
        }

        return array_keys($normalized);
    }

    private static function run_varnish_basic_test_invalidation($url, $endpoint = '', array $contract = array(), $strategy_override = '')
    {
        $endpoint = self::normalize_varnish_registry_endpoint($endpoint);
        $settings = self::get_varnish_cli_settings();
        $probe_endpoints = '' !== $endpoint
            ? array($endpoint)
            : (array) ($settings['servers'] ?? array());
        $strategy_override = sanitize_key((string) $strategy_override);
        if (!in_array($strategy_override, array('purge', 'ban'), true)) {
            $strategy_override = '';
        }
        $probe_strategy = 'admin' === self::sanitize_varnish_mode($settings['mode'] ?? 'http')
            ? 'exact-ban'
            : ('ban' === $strategy_override
                ? 'exact-ban'
                : ('purge' === $strategy_override
                    ? 'exact-purge'
                    : ('BAN' === strtoupper((string) ($settings['method'] ?? 'BAN')) ? 'exact-ban' : 'exact-purge')));
        $effective_override = 'exact-ban' === $probe_strategy ? 'ban' : 'purge';
        $probe_token = self::begin_varnish_capability_probe(array(
            'operation' => 'targeted',
            'strategy' => $probe_strategy,
            'requestedScope' => 'exact-url',
            'endpoints' => $probe_endpoints,
            'urls' => array($url),
        ));
        self::begin_varnish_test_run();
        try {
            if (
                '' !== $endpoint
                && 'exact-purge' === $probe_strategy
                && !empty($contract['ok'])
                && self::varnish_http_contract_has_capability($contract, 'exact-purge')
            ) {
                $settings = self::get_varnish_cli_settings();
                $contract_probe_token = self::begin_varnish_capability_probe(array(
                    'operation' => 'targeted',
                    'strategy' => 'exact-purge',
                    'requestedScope' => 'exact-url',
                    'endpoints' => array($endpoint),
                    'urls' => array($url),
                ));
                try {
                    $response = self::send_varnish_http_contract_exact_purge(
                        $endpoint,
                        $url,
                        (int) ($settings['timeout'] ?? 5),
                        $settings,
                        true
                    );
                } finally {
                    self::end_varnish_capability_probe($contract_probe_token);
                }
                if (!empty($response['ok'])) {
                    return array(
                        'success' => true,
                        'partial' => false,
                        'message' => self::sanitize_varnish_string((string) ($response['detail'] ?? '')),
                        'details' => array(array(
                            'server' => $endpoint,
                            'success' => true,
                            'detail' => self::sanitize_varnish_string((string) ($response['detail'] ?? '')),
                            'code' => absint($response['code'] ?? 0),
                        )),
                        'contract' => $response,
                        'contractFallbackUsed' => false,
                        'testedExactCapabilities' => array('exactPurge'),
                        'verifiedExactCapability' => 'exactPurge',
                    );
                }

                self::maybe_downgrade_varnish_http_contract_profile($endpoint, $response, $settings);
                $fallback = self::varnish_flush_url_batch(
                    array($url),
                    'behavior-endpoint-contract-fallback',
                    $effective_override,
                    array($endpoint)
                );
                $fallback['contract'] = $response;
                $fallback['contractFallbackUsed'] = true;
                $fallback_capability = 'exact-purge' === $probe_strategy ? 'exactPurge' : 'exactBan';
                $fallback['testedExactCapabilities'] = array_values(array_unique(array('exactPurge', $fallback_capability)));
                if (!empty($fallback['success'])) {
                    $fallback['verifiedExactCapability'] = $fallback_capability;
                }
                return $fallback;
            }
            if ('' !== $endpoint) {
                $settings = self::get_varnish_cli_settings();
                $result = self::varnish_flush_url_batch(
                    array($url),
                    'behavior-endpoint-' . $effective_override,
                    $effective_override,
                    array($endpoint),
                    $settings
                );
                $tested_capability = 'exact-purge' === $probe_strategy ? 'exactPurge' : 'exactBan';
                $result['testedExactCapabilities'] = array($tested_capability);
                if (!empty($result['success'])) {
                    $result['verifiedExactCapability'] = $tested_capability;
                }
                return $result;
            }

            $settings = self::get_varnish_cli_settings();
            $result = self::varnish_flush_url_hard($url);
            $tested_capability = 'exact-purge' === $probe_strategy ? 'exactPurge' : 'exactBan';
            $result['testedExactCapabilities'] = array($tested_capability);
            if (!empty($result['success']) && empty($result['verifiedExactCapability'])) {
                $result['verifiedExactCapability'] = $tested_capability;
            }
            return $result;
        } finally {
            self::end_varnish_capability_probe($probe_token);
            self::end_varnish_test_run();
        }
    }


    /**
     * Run one exact-invalidation canary through one configured HTTP endpoint.
     *
     * The cached object is created and observed through the canonical public
     * WordPress route. Only the PURGE/BAN control request is sent to the
     * configured endpoint, because a valid control listener is not required to
     * accept normal frontend GET requests.
     *
     * @param string $endpoint Configured HTTP endpoint label.
     * @param array  $settings Normalized Varnish settings.
     * @param int    $timeout  Request timeout.
     * @return array<string,mixed>
     */
    private static function run_varnish_endpoint_exact_canary($endpoint, array $settings, $timeout, $strategy_override = '')
    {
        $endpoint = self::normalize_varnish_registry_endpoint($endpoint);
        $strategy_override = sanitize_key((string) $strategy_override);
        if (!in_array($strategy_override, array('purge', 'ban'), true)) {
            $strategy_override = 'PURGE' === strtoupper((string) ($settings['method'] ?? 'BAN')) ? 'purge' : 'ban';
        }
        $tested_exact_capability = 'purge' === $strategy_override ? 'exactPurge' : 'exactBan';
        $tested_method = 'purge' === $strategy_override ? 'PURGE' : 'BAN';
        $tested_at = time();
        $identifier = self::generate_varnish_canary_identifier();
        $canary = self::write_varnish_canary_generation($identifier, 1);
        if (is_wp_error($canary)) {
            return array(
                'endpoint' => $endpoint,
                'success' => false,
                'verified' => false,
                'exactInvalidationVerified' => false,
                'reachable' => false,
                'status' => 'canary-create-failed',
                'message' => self::sanitize_varnish_string($canary->get_error_message()),
                'time' => $tested_at,
                'steps' => array(),
                'details' => array(),
                'cleanup' => array(
                    'localFileRemoved' => false,
                    'cachedObjectPurgeAttempted' => false,
                    'cachedObjectPurgeAccepted' => false,
                ),
            );
        }

        $route_selection = array('attempts' => array());
        $steps = array();
        $invalidation = array();
        $cleanup_results = array();
        $contract = array();
        $observation_proof = array();
        $url = '';
        $route = '';
        $local_cleanup = false;

        self::begin_varnish_test_run();
        try {
            $route_selection = self::select_varnish_canary_route($identifier, $canary, $timeout, '');
            $contract = self::probe_varnish_http_contract($endpoint, $settings, $timeout);
            if (!empty($route_selection['success'])) {
                $url = esc_url_raw((string) ($route_selection['url'] ?? ''));
                $route = sanitize_key((string) ($route_selection['route'] ?? ''));
                $selected_attempt = is_array($route_selection['attempt'] ?? null) ? $route_selection['attempt'] : array();
                $steps = is_array($selected_attempt['steps'] ?? null) ? $selected_attempt['steps'] : array();

                $invalidation = self::run_varnish_basic_test_invalidation($url, $endpoint, $contract, $strategy_override);
                if (!empty($invalidation['contractFallbackUsed']) && is_array($invalidation['contract'] ?? null)) {
                    $contract = $invalidation['contract'];
                }
                if (!empty($invalidation['success'])) {
                    $observation_proof = self::run_varnish_canary_post_operation_proof(
                        static function ($attempt_index) use ($endpoint, $url, $timeout) {
                            $step = 0 === $attempt_index
                                ? 'endpoint_canary_after_invalidation'
                                : (1 === $attempt_index
                                    ? 'endpoint_canary_refill_verification'
                                    : 'endpoint_canary_observation_retry_' . ($attempt_index - 1));

                            return self::run_varnish_public_capability_observation_request(
                                $url,
                                $step,
                                $timeout
                            );
                        },
                        $identifier,
                        2
                    );
                    foreach ((array) ($observation_proof['attempts'] ?? array()) as $attempt_index => $observation_step) {
                        if (0 === $attempt_index) {
                            $steps['afterInvalidation'] = $observation_step;
                        } elseif (1 === $attempt_index) {
                            $steps['verification'] = $observation_step;
                        } else {
                            $steps['observationRetry' . ($attempt_index - 1)] = $observation_step;
                        }
                    }
                }
            }

            $cleanup_urls = array();
            foreach ((array) ($route_selection['attempts'] ?? array()) as $cleanup_attempt) {
                if (!is_array($cleanup_attempt)) {
                    continue;
                }
                $cleanup_url = esc_url_raw((string) ($cleanup_attempt['url'] ?? ''));
                if ('' !== $cleanup_url) {
                    $cleanup_urls[$cleanup_url] = true;
                }
            }
            if ('' !== $url) {
                $cleanup_urls[$url] = true;
            }
            foreach (array_keys($cleanup_urls) as $cleanup_url) {
                $cleanup_results[] = self::run_varnish_basic_test_invalidation($cleanup_url, $endpoint, $contract, $strategy_override);
            }
        } finally {
            $local_cleanup = self::delete_varnish_canary($identifier);
            self::end_varnish_test_run();
        }

        $attempt_summaries = array();
        $any_route_available = false;
        foreach ((array) ($route_selection['attempts'] ?? array()) as $attempt) {
            if (!is_array($attempt)) {
                continue;
            }
            $available = !empty($attempt['available']);
            $any_route_available = $any_route_available || $available;
            $attempt_summaries[] = array(
                'route' => sanitize_key((string) ($attempt['route'] ?? '')),
                'available' => $available,
                'cacheable' => !empty($attempt['cacheable']),
                'runtimeEligible' => !empty($attempt['runtimeEligible']),
                'status' => sanitize_key((string) ($attempt['status'] ?? 'unavailable')),
                'message' => self::sanitize_varnish_string((string) ($attempt['message'] ?? '')),
            );
        }

        $network_reachable = false;
        foreach ((array) ($route_selection['attempts'] ?? array()) as $reachability_attempt) {
            foreach ((array) ($reachability_attempt['steps'] ?? array()) as $reachability_step) {
                if (is_array($reachability_step) && absint($reachability_step['httpCode'] ?? 0) > 0) {
                    $network_reachable = true;
                    break 2;
                }
            }
        }

        $route_verified = !empty($route_selection['success']);
        $runtime_route_eligible = $route_verified && !empty($route_selection['runtimeEligible']);
        $invalidation_success = !empty($invalidation['success']);
        $control_connection_accepted = self::varnish_basic_invalidation_control_connection_accepted($invalidation);
        $after_matches = absint($observation_proof['matchingObservationCount'] ?? 0) > 0;
        $verification_matches = !empty($observation_proof['verified']);
        $behavior_verified = $route_verified
            && $invalidation_success
            && $verification_matches;
        $exact_verified = $behavior_verified && $runtime_route_eligible;
        $verified_exact_capability = $exact_verified
            ? self::normalize_varnish_behavior_exact_capability($invalidation['verifiedExactCapability'] ?? '')
            : '';
        if ($exact_verified && !in_array($verified_exact_capability, array('exactPurge', 'exactBan'), true)) {
            $verified_exact_capability = $tested_exact_capability;
        }
        $static_verified = $behavior_verified && !$runtime_route_eligible;
        $hit_verified = false;
        foreach ((array) ($observation_proof['attempts'] ?? array()) as $observation_step) {
            if (is_array($observation_step)
                && self::varnish_canary_step_matches($observation_step, $identifier, 2)
                && 'HIT' === strtoupper((string) ($observation_step['status'] ?? ''))) {
                $hit_verified = true;
                break;
            }
        }
        $observation_summary = $observation_proof;
        unset($observation_summary['attempts']);
        $observation_mismatch = !empty($observation_proof['behaviorMismatchObserved']);
        $transport_accepted = !empty($invalidation['success']);
        $reachable = $network_reachable || $any_route_available || $route_verified || $control_connection_accepted || $transport_accepted;

        if (!$route_verified) {
            if ($any_route_available) {
                $status = 'canary-not-cacheable';
                $message = __('This Varnish endpoint served the canary but did not retain a stable shared-cache object.', 'ultracache');
            } else {
                $status = 'endpoint-route-unavailable';
                $message = __('No tested canonical public WordPress route could serve a stable canary object for this control-endpoint probe.', 'ultracache');
            }
            $success = false;
        } elseif (!$invalidation_success) {
            $status = self::classify_varnish_basic_invalidation_failure($invalidation);
            $message = 'authentication-failed' === $status
                ? __('This endpoint rejected or could not authenticate the exact canary invalidation.', 'ultracache')
                : __('This endpoint accepted no successful exact invalidation request for its cached canary.', 'ultracache');
            $success = false;
        } elseif (!$after_matches) {
            $status = $observation_mismatch ? 'invalidation-not-observed' : 'observation-incomplete';
            $message = $observation_mismatch
                ? __('The endpoint accepted the invalidation request but a successful public observation returned an unexpected canary generation.', 'ultracache')
                : __('The endpoint accepted the invalidation request, but the bounded public observations did not produce a successful canary response.', 'ultracache');
            $success = false;
        } elseif (!$verification_matches) {
            $status = $observation_mismatch ? 'refill-failed' : 'observation-incomplete';
            $message = $observation_mismatch
                ? __('The endpoint exposed generation 2 after invalidation, but a later successful refill observation returned an unexpected canary generation.', 'ultracache')
                : __('The endpoint exposed generation 2 after invalidation, but bounded public transport failures prevented a second successful refill observation.', 'ultracache');
            $success = false;
        } elseif (!$runtime_route_eligible) {
            $status = 'static-route-only';
            $message = __('This endpoint proved static-file invalidation only; WordPress page invalidation remains unverified.', 'ultracache');
            $success = false;
        } else {
            $status = $hit_verified ? 'working' : 'working-signals-hidden';
            $message = $hit_verified
                ? __('This endpoint independently proved exact WordPress-page invalidation and a stable HIT refill.', 'ultracache')
                : __('This endpoint independently proved exact WordPress-page invalidation; public HIT/MISS signals are hidden or incomplete.', 'ultracache');
            $success = true;
        }

        return array(
            'endpoint' => $endpoint,
            'success' => $success,
            'verified' => $exact_verified,
            'exactInvalidationVerified' => $exact_verified,
            'testedExactCapabilities' => self::normalize_varnish_behavior_exact_capabilities((array) ($invalidation['testedExactCapabilities'] ?? array($tested_exact_capability))),
            'verifiedExactCapability' => $verified_exact_capability,
            'sharedCacheVerified' => $route_verified,
            'runtimeRouteEligible' => $runtime_route_eligible,
            'staticExactInvalidationVerified' => $static_verified,
            'reachable' => $reachable,
            'controlConnectionAccepted' => $control_connection_accepted,
            'transportAccepted' => $transport_accepted,
            'invalidationVerified' => $after_matches,
            'refillVerified' => $verification_matches,
            'hitVerified' => $hit_verified,
            'cacheSignalsHidden' => $exact_verified && !$hit_verified,
            'status' => $status,
            'message' => $message,
            'time' => $tested_at,
            'method' => $tested_method,
            'contract' => $contract,
            'canaryRoute' => $route,
            'canaryAttempts' => $attempt_summaries,
            'steps' => $steps,
            'observationProof' => $observation_summary,
            'details' => is_array($invalidation['details'] ?? null) ? $invalidation['details'] : array(),
            'cleanup' => array(
                'localFileRemoved' => $local_cleanup,
                'cachedObjectPurgeAttempted' => !empty($cleanup_results),
                'cachedObjectPurgeAttemptCount' => count($cleanup_results),
                'cachedObjectPurgeAcceptedCount' => count(array_filter($cleanup_results, static function ($cleanup_result) {
                    return is_array($cleanup_result) && !empty($cleanup_result['success']);
                })),
            ),
        );
    }

    /**
     * Run isolated exact-canary proofs for every configured HTTP endpoint.
     *
     * @param array $settings Normalized Varnish settings.
     * @return array<string,mixed>
     */
    private static function run_varnish_multi_endpoint_exact_canaries(array $settings)
    {
        $tested_at = time();
        $timeout = max(2, min(10, (int) ($settings['timeout'] ?? 5)));
        $endpoint_results = array();
        $verified_count = 0;
        $exact_purge_verified_count = 0;
        $exact_ban_verified_count = 0;
        $reachable_count = 0;
        $transport_accepted_count = 0;
        $shared_cache_count = 0;
        $details = array();
        $endpoint_summaries = array();
        $site_flush_method_overrides = array();

        foreach ((array) ($settings['servers'] ?? array()) as $endpoint) {
            $endpoint = self::normalize_varnish_registry_endpoint($endpoint);
            if ('' === $endpoint) {
                continue;
            }
            $configured_strategy = 'PURGE' === strtoupper((string) ($settings['method'] ?? 'BAN')) ? 'purge' : 'ban';
            $secondary_strategy = 'purge' === $configured_strategy ? 'ban' : 'purge';
            $endpoint_result = self::run_varnish_endpoint_exact_canary($endpoint, $settings, $timeout, $configured_strategy);
            $secondary_result = self::run_varnish_endpoint_exact_canary($endpoint, $settings, $timeout, $secondary_strategy);
            $endpoint_result['exactCapabilityResults'] = array(
                'exactPurge' => 'purge' === $configured_strategy ? $endpoint_result : $secondary_result,
                'exactBan' => 'ban' === $configured_strategy ? $endpoint_result : $secondary_result,
            );
            $purge_verified = !empty($endpoint_result['exactCapabilityResults']['exactPurge']['exactInvalidationVerified']);
            $ban_verified = !empty($endpoint_result['exactCapabilityResults']['exactBan']['exactInvalidationVerified']);
            $endpoint_any_exact_verified = $purge_verified || $ban_verified;
            if ($purge_verified || $ban_verified) {
                $site_flush_method_overrides[$endpoint] = $purge_verified && !$ban_verified
                    ? 'PURGE'
                    : ($ban_verified && !$purge_verified
                        ? 'BAN'
                        : ('purge' === $configured_strategy ? 'PURGE' : 'BAN'));
            }
            $endpoint_reachable = !empty($endpoint_result['reachable']) || !empty($secondary_result['reachable']);
            $endpoint_transport_accepted = !empty($endpoint_result['transportAccepted']) || !empty($secondary_result['transportAccepted']);
            $endpoint_shared_cache_verified = !empty($endpoint_result['sharedCacheVerified']) || !empty($secondary_result['sharedCacheVerified']);
            $verified_exact_capabilities = array_values(array_filter(array(
                $purge_verified ? 'exactPurge' : '',
                $ban_verified ? 'exactBan' : '',
            )));
            $endpoint_result['testedExactCapabilities'] = array('exactPurge', 'exactBan');
            $endpoint_result['verifiedExactCapabilities'] = $verified_exact_capabilities;
            $endpoint_result['verifiedExactCapability'] = !empty($verified_exact_capabilities) ? (string) reset($verified_exact_capabilities) : '';
            $endpoint_result['success'] = $endpoint_any_exact_verified;
            $endpoint_result['verified'] = $endpoint_any_exact_verified;
            $endpoint_result['exactInvalidationVerified'] = $endpoint_any_exact_verified;
            $endpoint_result['reachable'] = $endpoint_reachable;
            $endpoint_result['transportAccepted'] = $endpoint_transport_accepted;
            $endpoint_result['sharedCacheVerified'] = $endpoint_shared_cache_verified;
            if ($endpoint_any_exact_verified) {
                $verified_method_label = $purge_verified && $ban_verified
                    ? 'PURGE and BAN'
                    : ($purge_verified ? 'PURGE' : 'BAN');
                $endpoint_result['status'] = 'working';
                $endpoint_result['message'] = self::maybe_translate_sprintf(
                    'The endpoint independently verified exact %s behavior with an isolated WordPress-served canary.',
                    $verified_method_label
                );
            }
            $endpoint_results[] = $endpoint_result;
            if ($endpoint_any_exact_verified) {
                $verified_count++;
            }
            if ($purge_verified) {
                $exact_purge_verified_count++;
            }
            if ($ban_verified) {
                $exact_ban_verified_count++;
            }
            if ($endpoint_reachable) {
                $reachable_count++;
            }
            if ($endpoint_transport_accepted) {
                $transport_accepted_count++;
            }
            if ($endpoint_shared_cache_verified) {
                $shared_cache_count++;
            }
            $endpoint_summaries[] = array(
                'endpoint' => (string) ($endpoint_result['endpoint'] ?? $endpoint),
                'success' => $endpoint_any_exact_verified,
                'reachable' => $endpoint_reachable,
                'transportAccepted' => $endpoint_transport_accepted,
                'sharedCacheVerified' => $endpoint_shared_cache_verified,
                'verifiedExactCapabilities' => $verified_exact_capabilities,
                'status' => sanitize_key((string) ($endpoint_result['status'] ?? 'not-verified')),
                'message' => self::sanitize_varnish_string((string) ($endpoint_result['message'] ?? '')),
            );
            $details[] = array(
                'server' => (string) ($endpoint_result['endpoint'] ?? $endpoint),
                'success' => $endpoint_any_exact_verified,
                'detail' => self::sanitize_varnish_string(
                    strtoupper((string) ($endpoint_result['status'] ?? 'not-verified')) . ' · ' . (string) ($endpoint_result['message'] ?? '')
                ),
            );
        }

        $configured_endpoint_count = count((array) ($settings['servers'] ?? array()));
        $tested_endpoint_count = count($endpoint_results);
        $all_exact_purge_verified = $configured_endpoint_count > 0
            && $tested_endpoint_count === $configured_endpoint_count
            && $exact_purge_verified_count === $configured_endpoint_count;
        $all_exact_ban_verified = $configured_endpoint_count > 0
            && $tested_endpoint_count === $configured_endpoint_count
            && $exact_ban_verified_count === $configured_endpoint_count;
        $all_verified = $all_exact_purge_verified || $all_exact_ban_verified;
        $any_exact_verified = $all_verified;
        $common_verified_method = $all_exact_purge_verified && $all_exact_ban_verified
            ? 'PURGE and BAN'
            : ($all_exact_purge_verified ? 'PURGE' : ($all_exact_ban_verified ? 'BAN' : ''));
        $partial = $verified_count > 0 && !$all_verified;
        if ($all_verified) {
            $status = 'working';
            $message = self::maybe_translate_sprintf(
                'Every configured Varnish endpoint independently proved exact %1$s behavior (%2$d/%3$d).',
                $common_verified_method,
                $configured_endpoint_count,
                $configured_endpoint_count
            );
        } elseif ($partial) {
            if ($verified_count === $configured_endpoint_count) {
                $status = 'mixed-exact-methods';
                $message = self::maybe_translate('Every configured endpoint verified an exact invalidation method, but no single PURGE or BAN method is verified across the complete endpoint set. Runtime invalidation remains disabled.');
            } else {
                $status = 'partial-topology';
                $message = self::maybe_translate_sprintf(
                    'Exact invalidation is independently verified on %1$d of %2$d configured Varnish endpoints. Runtime invalidation remains disabled.',
                    $verified_count,
                    $configured_endpoint_count
                );
            }
        } else {
            $status = 'endpoint-proofs-failed';
            $message = __('No configured Varnish endpoint independently proved exact WordPress-page invalidation.', 'ultracache');
        }

        if ($reachable_count > 0) {
            $esi_probe = self::run_varnish_esi_capability_probe($timeout);
            self::set_varnish_esi_capability($esi_probe);
            $esi_capability = self::get_varnish_esi_capability_status();
        } else {
            $esi_capability = self::mark_varnish_esi_capability_unverified(
                'probe-skipped',
                __('The public-path ESI probe was skipped because no configured HTTP endpoint was reachable.', 'ultracache')
            );
        }
        $html_variant_capability = $all_verified
            ? self::run_varnish_html_variant_capability_probe($timeout)
            : array(
                'supported' => false,
                'applicable' => true,
                'tested' => false,
                'status' => 'not-tested',
                'message' => __('HTML variants were not tested because exact invalidation was not verified on every configured HTTP endpoint.', 'ultracache'),
                'time' => $tested_at,
                'url' => home_url('/'),
                'activeBuckets' => array(),
                'verifiedBucketCount' => 0,
                'details' => array(),
            );
        $batch_ban_capability = self::run_varnish_batch_ban_capability_probe($settings, $timeout, $all_exact_ban_verified);
        $flush_topology_capability = self::run_varnish_flush_topology_capability_probe($settings, $timeout, $site_flush_method_overrides);
        $flush_topology_capability['exactInvalidationVerified'] = $any_exact_verified;
        $soft_purge_capability = self::run_varnish_soft_purge_capability_probe($settings, $timeout);

        return array(
            'success' => $all_verified,
            'verified' => $all_verified,
            'testType' => 'per-endpoint',
            'capabilityTest' => 'exact-url-canary-per-endpoint',
            'operationType' => 'diagnostic-exact-url-endpoint-test',
            'status' => $status,
            'message' => $message,
            'time' => $tested_at,
            'mode' => 'http',
            'method' => '' !== $common_verified_method && false === strpos($common_verified_method, ' and ')
                ? $common_verified_method
                : (string) ($settings['method'] ?? 'BAN'),
            'effectiveMethod' => '' !== $common_verified_method ? $common_verified_method : (string) ($settings['effectiveMethod'] ?? ''),
            'endpointCount' => $configured_endpoint_count,
            'testedEndpointCount' => $tested_endpoint_count,
            'verifiedEndpointCount' => $verified_count,
            'reachableEndpointCount' => $reachable_count,
            'varnishDetected' => $all_verified || $reachable_count > 0,
            'controlTransportTested' => $tested_endpoint_count > 0,
            'controlTransportAccepted' => $transport_accepted_count > 0,
            'publicBehaviorVerified' => $all_verified,
            'perEndpointBehaviorVerified' => $all_verified,
            'mixedEndpointTopologyUnverified' => !$all_verified,
            'exactInvalidationVerified' => $all_verified,
            'exactPurgeVerifiedAllEndpoints' => $all_exact_purge_verified,
            'exactBanVerifiedAllEndpoints' => $all_exact_ban_verified,
            'anyExactInvalidationVerified' => $any_exact_verified,
            'sharedCacheVerified' => $shared_cache_count > 0,
            'endpointSummaries' => $endpoint_summaries,
            'endpointResults' => $endpoint_results,
            'connectionDetails' => $details,
            'details' => $details,
            'esiTested' => !empty($esi_capability['tested']),
            'esiSupported' => !empty($esi_capability['supported']),
            'esiVerified' => !empty($esi_capability['verified']),
            'esiEffective' => !empty($esi_capability['effective']),
            'esiStatus' => (string) ($esi_capability['status'] ?? 'not-tested'),
            'esiMessage' => (string) ($esi_capability['message'] ?? ''),
            'esiCapability' => $esi_capability,
            'htmlVariantsSupported' => !empty($html_variant_capability['supported']),
            'htmlVariantCapability' => $html_variant_capability,
            'batchBanSupported' => !empty($batch_ban_capability['supported']),
            'batchBanCapability' => $batch_ban_capability,
            'htmlFlushSupported' => !empty($flush_topology_capability['htmlInvalidationVerified']),
            'hostFlushSupported' => !empty($flush_topology_capability['entireHostVerified']),
            'flushTopologyCapability' => $flush_topology_capability,
            'softPurgeSupported' => !empty($soft_purge_capability['supported']),
            'softPurgeCapability' => $soft_purge_capability,
            'originRevalidationVerified' => !empty($soft_purge_capability['originRevalidationVerified']),
            'staleWhileRevalidateVerified' => !empty($soft_purge_capability['staleVerified']) && !empty($soft_purge_capability['freshHitVerified']),
        );
    }

    /**
     * Verify active Varnish HTML variants through the canonical page warm pipeline.
     *
     * The diagnostic reuses UltraCache's existing HTML warm, invalidation, and
     * public refill stages. It only adds bounded public verification requests
     * after the canonical pipeline has completed.
     *
     * @param int $timeout Request timeout.
     * @return array<string,mixed>
     */
    private static function run_varnish_html_variant_capability_probe($timeout)
    {
        $tested_at = time();
        $dashboard_settings = self::get_dashboard_settings();
        $policy = function_exists('ultracache_get_html_variant_policy')
            ? ultracache_get_html_variant_policy($dashboard_settings)
            : array('buckets' => array('orig'), 'vary_accept' => false);
        $buckets = array_values(array_intersect(
            array('orig', 'webp', 'avif'),
            (array) ($policy['buckets'] ?? array('orig'))
        ));
        if (empty($buckets)) {
            $buckets = array('orig');
        }

        if (count($buckets) < 2 || empty($policy['vary_accept'])) {
            return array(
                'tested' => false,
                'supported' => false,
                'applicable' => false,
                'status' => 'unavailable-current-html-mode',
                'message' => __('HTML variant separation is unavailable because the current media configuration uses one HTML bucket.', 'ultracache'),
                'time' => $tested_at,
                'url' => home_url('/'),
                'activeBuckets' => $buckets,
                'verifiedBucketCount' => 0,
                'details' => array(),
            );
        }

        $url = esc_url_raw((string) home_url('/'));
        if ('' === $url
            || !function_exists('ultracache_is_strict_frontend_loopback_url')
            || !ultracache_is_strict_frontend_loopback_url($url)
            || !class_exists('Ultra_Cache_Engine')
            || !method_exists('Ultra_Cache_Engine', 'get_instance')) {
            return array(
                'tested' => false,
                'supported' => false,
                'applicable' => true,
                'status' => 'precondition-unavailable',
                'message' => __('The canonical front-page warm pipeline is unavailable for the HTML variant test.', 'ultracache'),
                'time' => $tested_at,
                'url' => $url,
                'activeBuckets' => $buckets,
                'verifiedBucketCount' => 0,
                'details' => array(),
            );
        }

        $timeout = max(2, min(10, (int) $timeout));
        $warm_result = array();
        $details = array();
        $verified_count = 0;
        $refill_settings = self::get_varnish_cli_settings();
        $refill_probe_token = self::begin_varnish_capability_probe(array(
            'operation' => 'refill',
            'strategy' => 'refill',
            'requestedScope' => 'html-variants',
            'endpoints' => (array) ($refill_settings['servers'] ?? array()),
            'urls' => array($url),
        ));

        self::begin_varnish_test_run();
        try {
            $engine = Ultra_Cache_Engine::get_instance();
            if (!$engine || !method_exists($engine, 'warm_page_pipeline')) {
                return array(
                    'tested' => false,
                    'supported' => false,
                    'applicable' => true,
                    'status' => 'precondition-unavailable',
                    'message' => __('The canonical front-page warm pipeline is unavailable for the HTML variant test.', 'ultracache'),
                    'time' => $tested_at,
                    'url' => $url,
                    'activeBuckets' => $buckets,
                    'verifiedBucketCount' => 0,
                    'details' => array(),
                );
            }

            $warm_result = $engine->warm_page_pipeline($url, array(
                'force_refresh' => true,
                'ignore_runtime_bypass' => true,
                'buckets' => $buckets,
                'build_css_bundle' => false,
                'include_varnish' => true,
                'include_litespeed' => false,
                'warm_context' => 'diagnostic',
                'required_stages' => array('html', 'varnish'),
                'time_budget' => 60,
            ));

            if (empty($warm_result['success'])) {
                $failed_stage = sanitize_key((string) ($warm_result['failedStage'] ?? ''));
                $failure_class = sanitize_key((string) ($warm_result['failureClass'] ?? ''));
                $failure_message = self::sanitize_varnish_string((string) ($warm_result['failureMessage'] ?? ($warm_result['message'] ?? '')));
                $failure_details = is_array($warm_result['failureDetails'] ?? null) ? $warm_result['failureDetails'] : array();
                $pipeline_message = __('The canonical warm/refill pipeline did not reach HTML variant observation.', 'ultracache');
                if ('' !== $failed_stage) {
                    $pipeline_message .= ' ' . self::maybe_translate_sprintf('Failed stage: %s.', $failed_stage);
                }
                if ('' !== $failure_message) {
                    $pipeline_message .= ' ' . $failure_message;
                }

                return array(
                    'tested' => true,
                    'supported' => false,
                    'applicable' => true,
                    'conclusive' => false,
                    'status' => 'observation-incomplete',
                    'message' => self::sanitize_varnish_string($pipeline_message),
                    'time' => $tested_at,
                    'url' => $url,
                    'activeBuckets' => $buckets,
                    'verifiedBucketCount' => 0,
                    'warmPipeline' => array(
                        'success' => false,
                        'failedStage' => $failed_stage,
                        'failureClass' => $failure_class,
                        'message' => $failure_message,
                        'details' => $failure_details,
                    ),
                    'details' => array(),
                );
            }

            foreach ($buckets as $bucket) {
                $accept = function_exists('ultracache_get_accept_header_for_html_bucket')
                    ? ultracache_get_accept_header_for_html_bucket($bucket)
                    : 'text/html,application/xhtml+xml';
                $first = self::run_varnish_behavior_request(
                    $url,
                    'variant_' . $bucket . '_first',
                    $timeout,
                    $accept
                );
                $second = self::run_varnish_behavior_request(
                    $url,
                    'variant_' . $bucket . '_second',
                    $timeout,
                    $accept
                );
                $first_variant = sanitize_key((string) ($first['headers']['ultraCacheVariant'] ?? ''));
                $second_variant = sanitize_key((string) ($second['headers']['ultraCacheVariant'] ?? ''));
                $second_cache_status = strtoupper((string) ($second['status'] ?? ''));
                $bucket_verified = !empty($first['success'])
                    && !empty($second['success'])
                    && $bucket === $first_variant
                    && $bucket === $second_variant
                    && !empty($second['varnishDetected'])
                    && in_array($second_cache_status, array('HIT', 'STALE'), true);
                if ($bucket_verified) {
                    ++$verified_count;
                }
                $details[] = array(
                    'bucket' => $bucket,
                    'supported' => $bucket_verified,
                    'firstStatus' => sanitize_key((string) ($first['status'] ?? '')),
                    'secondStatus' => sanitize_key((string) ($second['status'] ?? '')),
                    'secondCacheVerified' => in_array($second_cache_status, array('HIT', 'STALE'), true),
                    'firstVariant' => $first_variant,
                    'secondVariant' => $second_variant,
                    'varnishDetected' => !empty($first['varnishDetected']) || !empty($second['varnishDetected']),
                    'firstHttpCode' => absint($first['httpCode'] ?? 0),
                    'secondHttpCode' => absint($second['httpCode'] ?? 0),
                );
            }
        } finally {
            self::end_varnish_capability_probe($refill_probe_token);
            self::end_varnish_test_run();
        }

        $supported = count($buckets) === $verified_count;
        return array(
            'tested' => true,
            'supported' => $supported,
            'applicable' => true,
            'status' => $supported ? 'supported' : 'not-supported',
            'message' => $supported
                ? __('Varnish served the correct cached HTML object for every active original, WebP, and AVIF bucket after the canonical warm pipeline completed.', 'ultracache')
                : __('Varnish did not preserve the correct cached HTML object for every active variant bucket.', 'ultracache'),
            'time' => $tested_at,
            'url' => $url,
            'activeBuckets' => $buckets,
            'verifiedBucketCount' => $verified_count,
            'proofExpiresAt' => $supported ? $tested_at + WEEK_IN_SECONDS : 0,
            'warmPipeline' => array(
                'success' => !empty($warm_result['success']),
                'message' => self::sanitize_varnish_string((string) ($warm_result['message'] ?? '')),
            ),
            'details' => $details,
        );
    }

    /**
     * Observe one public canary for a configured control endpoint.
     *
     * Both HTTP listeners and Admin sockets are treated as control-plane targets.
     * The resulting behavior is always observed through the canonical public
     * WordPress route, because a valid PURGE/BAN endpoint is not required to
     * accept normal frontend GET requests.
     *
     * @param string $endpoint Configured control endpoint.
     * @param string $url      Public canary URL.
     * @param string $step     Stable diagnostic step.
     * @param int    $timeout  Request timeout.
     * @param array  $settings Normalized Varnish settings.
     * @return array<string,mixed>
     */
    private static function run_varnish_control_scoped_behavior_request($endpoint, $url, $step, $timeout, array $settings)
    {
        unset($endpoint, $settings);

        return self::run_varnish_public_capability_observation_request($url, $step, $timeout);
    }

    /**
     * Prove that one control endpoint can invalidate two distinct URLs with one BAN.
     *
     * @param string $endpoint            Configured control-endpoint label.
     * @param array  $settings            Normalized Varnish settings.
     * @param int    $timeout             Request timeout.
     * @param array  $transport_endpoints Configured control-endpoint set receiving the production operation.
     * @return array<string,mixed>
     */
    private static function run_varnish_endpoint_batch_ban_canary($endpoint, array $settings, $timeout, array $transport_endpoints = array())
    {
        $transport_endpoints = array_values(array_unique(array_filter(array_map(static function ($candidate) {
            return self::normalize_varnish_registry_endpoint($candidate);
        }, !empty($transport_endpoints) ? $transport_endpoints : array($endpoint)))));
        $tested_at = time();
        $identifiers = array(self::generate_varnish_canary_identifier(), self::generate_varnish_canary_identifier());
        $canaries = array();
        foreach ($identifiers as $identifier) {
            $canary = self::write_varnish_canary_generation($identifier, 1);
            if (is_wp_error($canary)) {
                foreach ($canaries as $created_canary) {
                    self::delete_varnish_canary((string) ($created_canary['identifier'] ?? ''));
                }
                return array(
                    'endpoint' => $endpoint,
                    'batchBan' => false,
                    'tested' => false,
                    'reachable' => false,
                    'status' => 'canary-create-failed',
                    'message' => self::sanitize_varnish_string($canary->get_error_message()),
                    'testedAt' => $tested_at,
                    'steps' => array(),
                );
            }
            $canaries[] = $canary;
        }

        $steps = array();
        $urls = array();
        $selected_dedupe_keys = array();
        $generation_two_artifacts = array();
        $transport = array();
        try {
            $route_endpoint = '';
            foreach ($canaries as $index => $canary) {
                $identifier = (string) $canary['identifier'];
                $route_selection = self::select_varnish_production_canary_route(
                    $identifier,
                    $canary,
                    $timeout,
                    $route_endpoint,
                    $selected_dedupe_keys
                );
                $steps['route' . $index] = $route_selection;
                if (empty($route_selection['success']) || empty($route_selection['runtimeEligible'])) {
                    return array(
                        'endpoint' => $endpoint,
                        'batchBan' => false,
                        'tested' => false,
                        'reachable' => !empty($route_selection['success']),
                        'status' => 'production-canary-unavailable',
                        'message' => self::maybe_translate('Batch BAN requires two distinct cacheable WordPress-served URLs that remain valid, identity-preserving production invalidation targets.'),
                        'testedAt' => $tested_at,
                        'steps' => $steps,
                    );
                }
                $url = esc_url_raw((string) ($route_selection['url'] ?? ''));
                $dedupe_key = (string) ($route_selection['dedupeKey'] ?? '');
                if ('' === $url || '' === $dedupe_key) {
                    return array(
                        'endpoint' => $endpoint,
                        'batchBan' => false,
                        'tested' => false,
                        'reachable' => true,
                        'status' => 'production-target-unavailable',
                        'message' => self::maybe_translate('A cacheable canary route did not produce a stable production invalidation target.'),
                        'testedAt' => $tested_at,
                        'steps' => $steps,
                    );
                }
                $urls[] = $url;
                $selected_dedupe_keys[] = $dedupe_key;
                $steps['before' . $index] = self::run_varnish_control_scoped_behavior_request($endpoint, $url, 'batch_before_' . $index, $timeout, $settings);
                if (!self::varnish_canary_step_matches($steps['before' . $index], $identifier, 1)) {
                    return array(
                        'endpoint' => $endpoint,
                        'batchBan' => false,
                        'tested' => false,
                        'reachable' => true,
                        'status' => 'canary-not-retained',
                        'message' => self::maybe_translate('A batch BAN canary changed before the batch invalidation command was sent.'),
                        'testedAt' => $tested_at,
                        'steps' => $steps,
                    );
                }
            }

            foreach ($identifiers as $identifier) {
                $generation_two = self::write_varnish_canary_generation($identifier, 2);
                if (is_wp_error($generation_two)) {
                    return array(
                        'endpoint' => $endpoint,
                        'batchBan' => false,
                        'tested' => false,
                        'reachable' => true,
                        'status' => 'canary-write-failed',
                        'message' => self::sanitize_varnish_string($generation_two->get_error_message()),
                        'testedAt' => $tested_at,
                        'steps' => $steps,
                    );
                }
                $generation_two_artifacts[$identifier] = $generation_two;
            }

            $probe_token = self::begin_varnish_capability_probe(array(
                'operation' => 'targeted',
                'strategy' => 'batch-ban',
                'requestedScope' => 'batch',
                'endpoints' => $transport_endpoints,
                'urls' => $urls,
            ));
            self::begin_varnish_test_run();
            try {
                $transport = self::varnish_flush_url_batch(
                    $urls,
                    'behavior-batch-ban',
                    'ban',
                    $transport_endpoints,
                    $settings
                );
            } finally {
                self::end_varnish_capability_probe($probe_token);
                self::end_varnish_test_run();
            }

            $transport_attempted = absint($transport['requestCount'] ?? 0) > 0;
            $transport_reachable = false;
            foreach ((array) ($transport['details'] ?? array()) as $transport_detail) {
                if (is_array($transport_detail)
                    && (!empty($transport_detail['connectionAccepted']) || !empty($transport_detail['code']))) {
                    $transport_reachable = true;
                    break;
                }
            }
            if (empty($transport['success'])) {
                return array(
                    'endpoint' => $endpoint,
                    'batchBan' => false,
                    'tested' => $transport_attempted,
                    'reachable' => $transport_attempted && $transport_reachable,
                    'status' => $transport_attempted
                        ? sanitize_key((string) ($transport['status'] ?? 'transport-failed'))
                        : 'production-path-not-executable',
                    'message' => self::sanitize_varnish_string((string) ($transport['message'] ?? 'The production batch BAN path could not be executed.')),
                    'testedAt' => $tested_at,
                    'steps' => $steps,
                    'transport' => $transport,
                );
            }

            if (empty($transport['batchBanUsed'])) {
                return array(
                    'endpoint' => $endpoint,
                    'batchBan' => false,
                    'tested' => false,
                    'reachable' => true,
                    'status' => 'production-batch-not-selected',
                    'message' => self::maybe_translate('The production targeted invalidator did not select one shared batch BAN expression for both canary URLs.'),
                    'testedAt' => $tested_at,
                    'steps' => $steps,
                    'transport' => $transport,
                );
            }

            $verified = true;
            $conclusive = true;
            $behavior_mismatch = false;
            $verification = array();
            foreach ($canaries as $index => $canary) {
                $identifier = (string) $canary['identifier'];
                $url = (string) $urls[$index];
                $artifact = is_array($generation_two_artifacts[$identifier] ?? null)
                    ? $generation_two_artifacts[$identifier]
                    : array();
                $proof = self::run_varnish_canary_post_operation_proof(
                    static function ($attempt_index) use ($endpoint, $url, $index, $timeout, $settings) {
                        $step = 0 === $attempt_index
                            ? 'batch_after_' . $index
                            : (1 === $attempt_index
                                ? 'batch_verify_' . $index
                                : 'batch_observation_retry_' . $index . '_' . ($attempt_index - 1));

                        return self::run_varnish_control_scoped_behavior_request(
                            $endpoint,
                            $url,
                            $step,
                            $timeout,
                            $settings
                        );
                    },
                    $identifier,
                    2,
                    $artifact
                );
                foreach ((array) ($proof['attempts'] ?? array()) as $attempt_index => $observation_step) {
                    if (0 === $attempt_index) {
                        $steps['after' . $index] = $observation_step;
                    } elseif (1 === $attempt_index) {
                        $steps['verify' . $index] = $observation_step;
                    } else {
                        $steps['retry' . $index . '_' . ($attempt_index - 1)] = $observation_step;
                    }
                }
                $after_matches = isset($steps['after' . $index])
                    && self::varnish_canary_step_matches_written_generation(
                        (array) $steps['after' . $index],
                        $identifier,
                        2,
                        $artifact
                    );
                $verify_matches = isset($steps['verify' . $index])
                    && self::varnish_canary_step_matches_written_generation(
                        (array) $steps['verify' . $index],
                        $identifier,
                        2,
                        $artifact
                    );
                $verification[$index] = array(
                    'verified' => !empty($proof['verified']),
                    'afterMatchesGenerationTwo' => $after_matches,
                    'verifyMatchesGenerationTwo' => $verify_matches,
                    'matchingObservationCount' => absint($proof['matchingObservationCount'] ?? 0),
                    'mismatchingObservationCount' => absint($proof['mismatchingObservationCount'] ?? 0),
                    'failedObservationCount' => absint($proof['failedObservationCount'] ?? 0),
                    'attemptCount' => absint($proof['attemptCount'] ?? 0),
                    'afterStatus' => sanitize_key((string) ($steps['after' . $index]['status'] ?? '')),
                    'verifyStatus' => sanitize_key((string) ($steps['verify' . $index]['status'] ?? '')),
                );
                if (empty($proof['verified'])) {
                    $verified = false;
                }
                if (empty($proof['conclusive'])) {
                    $conclusive = false;
                }
                if (!empty($proof['behaviorMismatchObserved'])) {
                    $behavior_mismatch = true;
                }
            }

            return array(
                'endpoint' => $endpoint,
                'batchBan' => $verified,
                'tested' => true,
                'conclusive' => $verified || $conclusive,
                'reachable' => true,
                'status' => $verified
                    ? 'verified'
                    : ($conclusive ? 'invalidation-not-observed' : 'observation-incomplete'),
                'message' => $verified
                    ? self::maybe_translate('One batch BAN expression invalidated both isolated canary URLs and both refills matched the exact written generation 2 artifacts.')
                    : ($conclusive && $behavior_mismatch
                        ? self::maybe_translate('The batch BAN command was accepted, but a successful public observation returned a stale or mismatched canary artifact.')
                        : self::maybe_translate('The batch BAN command was accepted, but bounded public transport failures prevented two successful matching observations for every canary.')),
                'testedAt' => $tested_at,
                'steps' => $steps,
                'verification' => $verification,
                'transport' => $transport,
            );
        } finally {
            foreach ($identifiers as $identifier) {
                self::delete_varnish_canary($identifier);
            }
        }
    }

    /**
     * Run the real batch BAN proof for every configured control endpoint.
     *
     * @param array $settings                    Normalized Varnish settings.
     * @param int   $timeout                     Request timeout.
     * @param bool  $exact_invalidation_verified Whether the same production endpoint set already passed exact invalidation.
     * @return array<string,mixed>
     */
    private static function run_varnish_batch_ban_capability_probe(array $settings, $timeout, $exact_invalidation_verified)
    {
        $tested_at = time();
        $mode = self::sanitize_varnish_mode($settings['mode'] ?? 'http');
        $configured_endpoints = array_values(array_unique(array_filter(array_map(static function ($endpoint) {
            return self::normalize_varnish_registry_endpoint($endpoint);
        }, (array) ($settings['servers'] ?? array())))));
        $skip_result = static function ($status, $message) use ($configured_endpoints, $tested_at) {
            return array(
                'supported' => false,
                'verified' => false,
                'tested' => false,
                'testedAllEndpoints' => false,
                'status' => sanitize_key((string) $status),
                'message' => $message,
                'testedAt' => $tested_at,
                'configuredEndpointCount' => count($configured_endpoints),
                'verifiedEndpointCount' => 0,
                'testedEndpointCount' => 0,
                'controlEndpointSetTested' => false,
                'endpointCapabilities' => array_map(static function ($endpoint) use ($status, $message, $tested_at) {
                    return array(
                        'endpoint' => $endpoint,
                        'batchBan' => false,
                        'tested' => false,
                        'reachable' => false,
                        'status' => sanitize_key((string) $status),
                        'message' => $message,
                        'testedAt' => $tested_at,
                    );
                }, $configured_endpoints),
            );
        };
        if (!$exact_invalidation_verified) {
            return $skip_result(
                'exact-prerequisite-unverified',
                self::maybe_translate('Batch BAN was not tested because the same production endpoint set did not first verify Exact BAN behavior.')
            );
        }
        $endpoint_capabilities = array();
        $verified = 0;
        $tested = 0;
        $conclusive = 0;
        if ('admin' === $mode && !empty($configured_endpoints)) {
            $aggregate = self::run_varnish_endpoint_batch_ban_canary(
                (string) reset($configured_endpoints),
                $settings,
                $timeout,
                $configured_endpoints
            );
            foreach ($configured_endpoints as $endpoint) {
                $result = $aggregate;
                $result['endpoint'] = $endpoint;
                $result['controlEndpointSet'] = $configured_endpoints;
                $endpoint_capabilities[] = $result;
                if (!empty($result['batchBan'])) {
                    $verified++;
                }
                if (!empty($result['tested'])) {
                    $tested++;
                }
                if (!empty($result['conclusive'])) {
                    $conclusive++;
                }
            }
        } else {
            foreach ($configured_endpoints as $endpoint) {
                $result = self::run_varnish_endpoint_batch_ban_canary(
                    $endpoint,
                    $settings,
                    $timeout,
                    array($endpoint)
                );
                $endpoint_capabilities[] = $result;
                if (!empty($result['batchBan'])) {
                    $verified++;
                }
                if (!empty($result['tested'])) {
                    $tested++;
                }
                if (!empty($result['conclusive'])) {
                    $conclusive++;
                }
            }
        }

        $count = count($endpoint_capabilities);
        $all_verified = $count > 0 && $verified === $count;
        $all_tested = $count > 0 && $tested === $count;
        $all_conclusive = $count > 0 && $conclusive === $count;
        $status = $all_verified
            ? 'verified'
            : ($verified > 0
                ? 'partial-topology'
                : (0 === $tested
                    ? 'not-tested'
                    : (!$all_conclusive ? 'observation-incomplete' : ($all_tested ? 'not-supported' : 'partially-tested'))));
        return array(
            'supported' => $all_verified,
            'verified' => $all_verified,
            'tested' => $tested > 0,
            'testedAllEndpoints' => $all_tested,
            'conclusiveAllEndpoints' => $all_conclusive,
            'status' => $status,
            'message' => $all_verified
                ? self::maybe_translate('Every configured Varnish control endpoint passed the two-URL batch BAN proof.')
                : ($verified > 0
                    ? self::maybe_translate('Batch BAN was behavior-verified on only part of the configured control-endpoint topology.')
                    : (0 === $tested
                    ? self::maybe_translate('The two-URL batch BAN transport was not tested because the required cacheable canaries were unavailable.')
                    : (!$all_conclusive
                        ? self::maybe_translate('Batch BAN transport executed, but bounded public transport failures left at least one endpoint capability observation incomplete.')
                        : ($all_tested
                            ? self::maybe_translate('At least one configured Varnish control endpoint failed the two-URL batch BAN proof.')
                            : self::maybe_translate('The two-URL batch BAN transport was tested on only part of the configured endpoint topology.'))))),
            'testedAt' => $tested_at,
            'configuredEndpointCount' => $count,
            'verifiedEndpointCount' => $verified,
            'testedEndpointCount' => $tested,
            'controlEndpointSetTested' => 'admin' === $mode && count($configured_endpoints) > 1,
            'endpointCapabilities' => $endpoint_capabilities,
        );
    }

    /**
     * Classify the public static-canary delivery route.
     *
     * A stable generation-1 response after the origin changes to generation 2
     * proves that the static object travels through Varnish. An immediate
     * generation-2 response without Varnish evidence proves that the public
     * static route bypasses Varnish and therefore does not need preservation
     * proof during an HTML-only BAN.
     *
     * @param array  $warm       First static request.
     * @param array  $verify     Second static request.
     * @param array  $before     Request after the origin changed to generation 2.
     * @param string $identifier Static canary identifier.
     * @return array<string,mixed>
     */
    private static function classify_varnish_static_canary_route(
        array $warm,
        array $verify,
        array $before,
        $identifier
    ) {
        $served_generation_one = self::varnish_canary_step_matches($warm, $identifier, 1)
            || self::varnish_canary_step_matches($verify, $identifier, 1);
        $stable_generation_one = self::varnish_canary_step_matches($warm, $identifier, 1)
            && self::varnish_canary_step_matches($verify, $identifier, 1)
            && self::varnish_canary_step_matches($before, $identifier, 1);
        $exposed_generation_two = self::varnish_canary_step_matches($before, $identifier, 2);
        $varnish_visible = !empty($warm['varnishDetected'])
            || !empty($verify['varnishDetected'])
            || !empty($before['varnishDetected']);
        $reachable = absint($warm['httpCode'] ?? 0) > 0
            || absint($verify['httpCode'] ?? 0) > 0
            || absint($before['httpCode'] ?? 0) > 0;

        if ($stable_generation_one) {
            return array(
                'route' => 'through-varnish',
                'cacheable' => true,
                'bypass' => false,
                'reachable' => true,
                'message' => self::maybe_translate('The opaque static canary remained on generation 1 after its origin file changed, proving a stable Varnish static object.'),
            );
        }

        if ($served_generation_one && $exposed_generation_two && !$varnish_visible) {
            return array(
                'route' => 'varnish-bypass',
                'cacheable' => false,
                'bypass' => true,
                'reachable' => true,
                'message' => self::maybe_translate('The public static route exposed generation 2 immediately without Varnish response evidence, proving that static assets bypass Varnish.'),
            );
        }

        if ($varnish_visible) {
            return array(
                'route' => 'varnish-unverified',
                'cacheable' => false,
                'bypass' => false,
                'reachable' => $reachable,
                'message' => self::maybe_translate('Varnish was visible on the static route, but the static canary did not produce a stable cache object.'),
            );
        }

        return array(
            'route' => 'outside-or-unobservable',
            'cacheable' => false,
            'bypass' => false,
            'reachable' => $reachable,
            'message' => self::maybe_translate('The static delivery route was outside Varnish or did not expose enough evidence for classification.'),
        );
    }

    /**
     * Prove one site-flush scope through the production runtime planner.
     *
     * Two independent WordPress-served HTML canaries prove that the operation
     * is host-wide rather than an accidental exact-URL invalidation. The static
     * canary is used when static objects travel through Varnish. When the public
     * static route demonstrably bypasses Varnish, HTML-only proof proceeds and
     * static preservation is recorded as not required.
     *
     * @param string $scope                 html or host.
     * @param string $observation_endpoint HTTP data-plane endpoint, or empty for the public Admin path.
     * @param array  $settings              Normalized Varnish settings.
     * @param int    $timeout               Request timeout.
     * @param array  $transport_endpoints   Configured control-endpoint set receiving the production operation.
     * @param string $method_override       HTTP PURGE or BAN method selected by the current exact-capability probe.
     * @return array<string,mixed>
     */
    private static function run_varnish_flush_scope_canary(
        $scope,
        $observation_endpoint,
        array $settings,
        $timeout,
        array $transport_endpoints,
        $method_override = ''
    ) {
        $scope = 'host' === sanitize_key((string) $scope) ? 'host' : 'html';
        $observation_endpoint = self::normalize_varnish_registry_endpoint($observation_endpoint);
        $transport_endpoints = array_values(array_unique(array_filter(array_map(static function ($endpoint) {
            return self::normalize_varnish_registry_endpoint($endpoint);
        }, $transport_endpoints))));
        $tested_at = time();
        if (empty($transport_endpoints)) {
            return array(
                'endpoint' => $observation_endpoint,
                'controlEndpointSet' => array(),
                'scope' => $scope,
                'verified' => false,
                'tested' => false,
                'reachable' => false,
                'status' => 'configuration-incomplete',
                'message' => self::maybe_translate('The production site-flush proof requires at least one configured Varnish endpoint.'),
                'testedAt' => $tested_at,
                'steps' => array(),
                'transport' => array(),
            );
        }

        $html_ids = array(
            self::generate_varnish_canary_identifier(),
            self::generate_varnish_canary_identifier(),
        );
        $static_id = self::generate_varnish_canary_identifier();
        $html_canaries = array();
        foreach ($html_ids as $html_id) {
            $html_canary = self::write_varnish_canary_generation($html_id, 1);
            if (is_wp_error($html_canary)) {
                foreach ($html_ids as $cleanup_id) {
                    self::delete_varnish_canary($cleanup_id);
                }
                return array(
                    'endpoint' => $observation_endpoint,
                    'controlEndpointSet' => $transport_endpoints,
                    'scope' => $scope,
                    'verified' => false,
                    'tested' => false,
                    'reachable' => false,
                    'status' => 'canary-create-failed',
                    'message' => self::sanitize_varnish_string($html_canary->get_error_message()),
                    'testedAt' => $tested_at,
                    'steps' => array(),
                    'transport' => array(),
                );
            }
            $html_canaries[] = $html_canary;
        }
        $static = self::write_varnish_static_canary_generation($static_id, 1);
        if (is_wp_error($static)) {
            foreach ($html_ids as $cleanup_id) {
                self::delete_varnish_canary($cleanup_id);
            }
            return array(
                'endpoint' => $observation_endpoint,
                'controlEndpointSet' => $transport_endpoints,
                'scope' => $scope,
                'verified' => false,
                'tested' => false,
                'reachable' => false,
                'status' => 'canary-create-failed',
                'message' => self::sanitize_varnish_string($static->get_error_message()),
                'testedAt' => $tested_at,
                'steps' => array(),
                'transport' => array(),
            );
        }

        $static_url = esc_url_raw((string) ($static['directUrl'] ?? ''));
        $steps = array();
        $transport = array();
        self::begin_varnish_test_run();
        try {
            $route_endpoint = '';
            $html_routes = array();
            foreach ($html_ids as $index => $html_id) {
                $html_route = self::select_varnish_canary_route(
                    $html_id,
                    (array) ($html_canaries[$index] ?? array()),
                    $timeout,
                    $route_endpoint
                );
                $steps['htmlRoute' . ($index + 1)] = $html_route;
                if (empty($html_route['success']) || empty($html_route['runtimeEligible'])) {
                    return array(
                        'endpoint' => $observation_endpoint,
                        'controlEndpointSet' => $transport_endpoints,
                        'scope' => $scope,
                        'verified' => false,
                        'tested' => false,
                        'reachable' => !empty($html_route['success']),
                        'status' => 'html-canary-not-cacheable',
                        'message' => self::maybe_translate('The production site-flush proof requires two independent cacheable WordPress-served HTML canary routes.'),
                        'testedAt' => $tested_at,
                        'steps' => $steps,
                        'transport' => array(),
                    );
                }
                $html_routes[] = $html_route;
            }

            $steps['staticWarm'] = self::run_varnish_control_scoped_behavior_request(
                $observation_endpoint,
                $static_url,
                $scope . '_static_warm',
                $timeout,
                $settings
            );
            $steps['staticWarmVerify'] = self::run_varnish_control_scoped_behavior_request(
                $observation_endpoint,
                $static_url,
                $scope . '_static_warm_verify',
                $timeout,
                $settings
            );
            $static_generation_two = self::write_varnish_static_canary_generation($static_id, 2);
            if (is_wp_error($static_generation_two)) {
                return array(
                    'endpoint' => $observation_endpoint,
                    'controlEndpointSet' => $transport_endpoints,
                    'scope' => $scope,
                    'verified' => false,
                    'tested' => false,
                    'reachable' => true,
                    'status' => 'canary-write-failed',
                    'message' => self::sanitize_varnish_string($static_generation_two->get_error_message()),
                    'testedAt' => $tested_at,
                    'steps' => $steps,
                    'transport' => array(),
                );
            }
            $steps['staticBefore'] = self::run_varnish_control_scoped_behavior_request(
                $observation_endpoint,
                $static_url,
                $scope . '_static_before',
                $timeout,
                $settings
            );
            $static_route = self::classify_varnish_static_canary_route(
                $steps['staticWarm'],
                $steps['staticWarmVerify'],
                $steps['staticBefore'],
                $static_id
            );

            if ('host' === $scope && !empty($static_route['bypass'])) {
                return array(
                    'endpoint' => $observation_endpoint,
                    'controlEndpointSet' => $transport_endpoints,
                    'scope' => $scope,
                    'verified' => false,
                    'tested' => true,
                    'conclusive' => true,
                    'applicable' => false,
                    'reachable' => true,
                    'staticRoute' => 'varnish-bypass',
                    'staticPreservation' => 'not-required',
                    'entireHostStatus' => 'not-applicable-static-bypass',
                    'entireHostStaticInvalidation' => 'not-required',
                    'status' => 'not-applicable-static-bypass',
                    'message' => self::maybe_translate('Entire-host Varnish invalidation is not applicable because the public static route bypasses Varnish. HTML-only invalidation remains independently testable.'),
                    'staticRouteMessage' => self::sanitize_varnish_string((string) ($static_route['message'] ?? '')),
                    'testedAt' => $tested_at,
                    'steps' => $steps,
                    'transport' => array(),
                );
            }

            if (empty($static_route['cacheable']) && empty($static_route['bypass'])) {
                return array(
                    'endpoint' => $observation_endpoint,
                    'controlEndpointSet' => $transport_endpoints,
                    'scope' => $scope,
                    'verified' => false,
                    'tested' => false,
                    'reachable' => !empty($static_route['reachable']),
                    'staticRoute' => sanitize_key((string) ($static_route['route'] ?? 'outside-or-unobservable')),
                    'staticPreservation' => 'unobservable',
                    'entireHostStatus' => 'not-tested',
                    'entireHostStaticInvalidation' => 'unobservable',
                    'status' => 'static-canary-unobservable',
                    'message' => self::maybe_translate('The production site-flush proof could not classify a cacheable or bypassed static route.'),
                    'staticRouteMessage' => self::sanitize_varnish_string((string) ($static_route['message'] ?? '')),
                    'testedAt' => $tested_at,
                    'steps' => $steps,
                    'transport' => array(),
                );
            }

            $html_before_verified = true;
            foreach ($html_routes as $index => $html_route) {
                $html_id = (string) ($html_ids[$index] ?? '');
                $html_url = esc_url_raw((string) ($html_route['url'] ?? ''));
                $step_key = 'htmlBefore' . ($index + 1);
                $steps[$step_key] = self::run_varnish_control_scoped_behavior_request(
                    $observation_endpoint,
                    $html_url,
                    $scope . '_html_before_' . ($index + 1),
                    $timeout,
                    $settings
                );
                if (!self::varnish_canary_step_matches($steps[$step_key], $html_id, 1)) {
                    $html_before_verified = false;
                }
            }
            if (!$html_before_verified) {
                return array(
                    'endpoint' => $observation_endpoint,
                    'controlEndpointSet' => $transport_endpoints,
                    'scope' => $scope,
                    'verified' => false,
                    'tested' => false,
                    'reachable' => true,
                    'staticRoute' => sanitize_key((string) ($static_route['route'] ?? 'outside-or-unobservable')),
                    'staticPreservation' => !empty($static_route['bypass']) ? 'not-required' : 'not-tested',
                    'entireHostStatus' => !empty($static_route['bypass']) ? 'not-applicable-static-bypass' : 'not-tested',
                    'entireHostStaticInvalidation' => !empty($static_route['bypass']) ? 'not-required' : 'not-tested',
                    'status' => 'html-canary-not-retained',
                    'message' => self::maybe_translate('At least one HTML canary changed before the production site-flush operation was executed.'),
                    'staticRouteMessage' => self::sanitize_varnish_string((string) ($static_route['message'] ?? '')),
                    'testedAt' => $tested_at,
                    'steps' => $steps,
                    'transport' => array(),
                );
            }

            $strategy = 'html' === $scope ? 'html-flush' : 'host-flush';
            $probe_token = self::begin_varnish_capability_probe(array(
                'operation' => 'site-flush',
                'strategy' => $strategy,
                'requestedScope' => $scope,
                'endpoints' => $transport_endpoints,
                'urls' => array(),
            ));
            try {
                $transport = self::execute_varnish_site_runtime_plan($scope, array(
                    'probeStrategy' => $strategy,
                    'probeScope' => $scope,
                    'probeEndpoints' => $transport_endpoints,
                    'probeUrls' => array(),
                    'probeMethod' => $method_override,
                ));
            } finally {
                self::end_varnish_capability_probe($probe_token);
            }

            $tested = !empty($transport['runtimeExecutionAttempted'])
                && (absint($transport['requestCount'] ?? 0) > 0
                    || absint($transport['successfulEndpointRequestCount'] ?? 0) > 0
                    || absint($transport['failedEndpointRequestCount'] ?? 0) > 0);
            $reachable = !empty($transport['success'])
                || absint($transport['successfulEndpointRequestCount'] ?? 0) > 0
                || absint($transport['failedEndpointRequestCount'] ?? 0) > 0;
            if (!$tested) {
                return array(
                    'endpoint' => $observation_endpoint,
                    'controlEndpointSet' => $transport_endpoints,
                    'scope' => $scope,
                    'verified' => false,
                    'tested' => false,
                    'reachable' => $reachable,
                    'staticRoute' => sanitize_key((string) ($static_route['route'] ?? 'outside-or-unobservable')),
                    'staticPreservation' => !empty($static_route['bypass']) ? 'not-required' : 'not-tested',
                    'entireHostStatus' => !empty($static_route['bypass']) ? 'not-applicable-static-bypass' : 'not-tested',
                    'entireHostStaticInvalidation' => !empty($static_route['bypass']) ? 'not-required' : 'not-tested',
                    'status' => 'runtime-operation-not-executed',
                    'message' => self::sanitize_varnish_string((string) ($transport['message'] ?? 'The production site-flush runtime did not execute a transport operation.')),
                    'staticRouteMessage' => self::sanitize_varnish_string((string) ($static_route['message'] ?? '')),
                    'testedAt' => $tested_at,
                    'steps' => $steps,
                    'transport' => $transport,
                );
            }
            if (empty($transport['success'])) {
                return array(
                    'endpoint' => $observation_endpoint,
                    'controlEndpointSet' => $transport_endpoints,
                    'scope' => $scope,
                    'verified' => false,
                    'tested' => true,
                    'reachable' => $reachable,
                    'staticRoute' => sanitize_key((string) ($static_route['route'] ?? 'outside-or-unobservable')),
                    'staticPreservation' => !empty($static_route['bypass']) ? 'not-required' : 'not-tested',
                    'entireHostStatus' => !empty($static_route['bypass']) ? 'not-applicable-static-bypass' : 'not-tested',
                    'entireHostStaticInvalidation' => !empty($static_route['bypass']) ? 'not-required' : 'not-tested',
                    'status' => 'transport-failed',
                    'message' => self::sanitize_varnish_string((string) ($transport['message'] ?? 'The production site-flush operation failed.')),
                    'staticRouteMessage' => self::sanitize_varnish_string((string) ($static_route['message'] ?? '')),
                    'testedAt' => $tested_at,
                    'steps' => $steps,
                    'transport' => $transport,
                );
            }

            $html_verified = true;
            $html_conclusive = true;
            $html_observation_proofs = array();
            foreach ($html_routes as $index => $html_route) {
                $html_id = (string) ($html_ids[$index] ?? '');
                $html_url = esc_url_raw((string) ($html_route['url'] ?? ''));
                $proof = self::run_varnish_canary_post_operation_proof(
                    static function ($attempt_index) use ($observation_endpoint, $html_url, $scope, $index, $timeout, $settings) {
                        $step = 0 === $attempt_index
                            ? $scope . '_html_after_' . ($index + 1)
                            : (1 === $attempt_index
                                ? $scope . '_html_verify_' . ($index + 1)
                                : $scope . '_html_observation_retry_' . ($index + 1) . '_' . ($attempt_index - 1));

                        return self::run_varnish_control_scoped_behavior_request(
                            $observation_endpoint,
                            $html_url,
                            $step,
                            $timeout,
                            $settings
                        );
                    },
                    $html_id,
                    2
                );
                foreach ((array) ($proof['attempts'] ?? array()) as $attempt_index => $observation_step) {
                    if (0 === $attempt_index) {
                        $steps['htmlAfter' . ($index + 1)] = $observation_step;
                    } elseif (1 === $attempt_index) {
                        $steps['htmlVerify' . ($index + 1)] = $observation_step;
                    } else {
                        $steps['htmlRetry' . ($index + 1) . '_' . ($attempt_index - 1)] = $observation_step;
                    }
                }
                $html_observation_proofs[$index] = array(
                    'identifier' => $html_id,
                    'verified' => !empty($proof['verified']),
                    'matchingObservationCount' => absint($proof['matchingObservationCount'] ?? 0),
                    'mismatchingObservationCount' => absint($proof['mismatchingObservationCount'] ?? 0),
                    'failedObservationCount' => absint($proof['failedObservationCount'] ?? 0),
                    'attemptCount' => absint($proof['attemptCount'] ?? 0),
                );
                if (empty($proof['verified'])) {
                    $html_verified = false;
                }
                if (empty($proof['conclusive'])) {
                    $html_conclusive = false;
                }
            }

            $static_verified = true;
            $static_conclusive = true;
            $static_observation_proof = array();
            if (!empty($static_route['cacheable'])) {
                $static_expected_generation = 'host' === $scope ? 2 : 1;
                $static_expected_artifact = 'host' === $scope
                    ? (is_array($static_generation_two) ? $static_generation_two : array())
                    : (is_array($static) ? $static : array());
                $static_observation_proof = self::run_varnish_canary_post_operation_proof(
                    static function ($attempt_index) use ($observation_endpoint, $static_url, $scope, $timeout, $settings) {
                        $step = 0 === $attempt_index
                            ? $scope . '_static_after'
                            : (1 === $attempt_index
                                ? $scope . '_static_verify'
                                : $scope . '_static_observation_retry_' . ($attempt_index - 1));

                        return self::run_varnish_control_scoped_behavior_request(
                            $observation_endpoint,
                            $static_url,
                            $step,
                            $timeout,
                            $settings
                        );
                    },
                    $static_id,
                    $static_expected_generation,
                    $static_expected_artifact
                );
                foreach ((array) ($static_observation_proof['attempts'] ?? array()) as $attempt_index => $observation_step) {
                    if (0 === $attempt_index) {
                        $steps['staticAfter'] = $observation_step;
                    } elseif (1 === $attempt_index) {
                        $steps['staticVerify'] = $observation_step;
                    } else {
                        $steps['staticRetry' . ($attempt_index - 1)] = $observation_step;
                    }
                }
                $static_verified = !empty($static_observation_proof['verified']);
                $static_conclusive = !empty($static_observation_proof['conclusive']);
            }
            $verified = $html_verified && $static_verified;
            $conclusive = $html_conclusive && $static_conclusive;
            $static_route_status = sanitize_key((string) ($static_route['route'] ?? 'outside-or-unobservable'));
            $static_preservation = !empty($static_route['bypass'])
                ? 'not-required'
                : ('html' === $scope && $static_verified ? 'verified' : 'not-tested');
            $entire_host_status = !empty($static_route['bypass'])
                ? 'not-applicable-static-bypass'
                : ('host' === $scope && $verified ? 'verified' : 'not-tested');
            $entire_host_static = !empty($static_route['bypass'])
                ? 'not-required'
                : ('host' === $scope && $static_verified ? 'verified' : 'not-tested');

            return array(
                'endpoint' => $observation_endpoint,
                'controlEndpointSet' => $transport_endpoints,
                'scope' => $scope,
                'verified' => $verified,
                'tested' => true,
                'conclusive' => $verified || $conclusive,
                'applicable' => true,
                'reachable' => true,
                'htmlInvalidated' => $html_verified,
                'staticPreserved' => 'html' === $scope && $static_verified,
                'staticInvalidated' => 'host' === $scope && $static_verified,
                'staticRoute' => $static_route_status,
                'staticPreservation' => $static_preservation,
                'entireHostStatus' => $entire_host_status,
                'entireHostStaticInvalidation' => $entire_host_static,
                'status' => $verified
                    ? (!empty($static_route['bypass']) ? 'verified-static-bypass' : 'verified')
                    : ($conclusive ? 'behavior-mismatch' : 'observation-incomplete'),
                'message' => $verified
                    ? (!empty($static_route['bypass'])
                        ? self::maybe_translate('The production HTML-only site-flush runtime renewed two independent HTML canaries; static preservation was not required because the public static route bypasses Varnish.')
                        : ('html' === $scope
                            ? self::maybe_translate('The production HTML-only site-flush runtime renewed two independent HTML canaries while the opaque static canary remained cached.')
                            : self::maybe_translate('The production entire-host site-flush runtime renewed two independent HTML canaries and the opaque static canary.')))
                    : ($conclusive
                        ? self::maybe_translate('The accepted production site-flush operation produced a successful stale or mismatched HTML/static observation.')
                        : self::maybe_translate('The production site-flush operation executed, but bounded public transport failures left the HTML/static behavior observation incomplete.')),
                'staticRouteMessage' => self::sanitize_varnish_string((string) ($static_route['message'] ?? '')),
                'testedAt' => $tested_at,
                'steps' => $steps,
                'htmlObservationProofs' => $html_observation_proofs,
                'staticObservationProof' => $static_observation_proof,
                'transport' => $transport,
            );
        } finally {
            foreach ($html_ids as $cleanup_id) {
                self::delete_varnish_canary($cleanup_id);
            }
            self::delete_varnish_static_canary($static_id);
            self::end_varnish_test_run();
        }
    }

    /**
     * Prove HTML-only and entire-host capability through the production site-flush runtime.
     *
     * HTTP listeners are tested independently as control-plane targets while all
     * resulting cache behavior is observed through the canonical public WordPress
     * route. Admin sockets are tested as one configured control set because the
     * production runtime broadcasts the same operation to every socket.
     *
     * @param array $settings Normalized Varnish settings.
     * @param int   $timeout          Request timeout.
     * @param array $method_overrides Per-endpoint HTTP methods proven during the current exact-capability probe.
     * @return array<string,mixed>
     */
    private static function run_varnish_flush_topology_capability_probe(array $settings, $timeout, array $method_overrides = array())
    {
        $tested_at = time();
        $mode = self::sanitize_varnish_mode($settings['mode'] ?? 'http');
        $configured_endpoints = array_values(array_unique(array_filter(array_map(static function ($endpoint) {
            return self::normalize_varnish_registry_endpoint($endpoint);
        }, (array) ($settings['servers'] ?? array())))));
        $normalized_method_overrides = array();
        foreach ($method_overrides as $method_endpoint => $method_override) {
            $method_endpoint = self::normalize_varnish_registry_endpoint($method_endpoint);
            $method_override = strtoupper(sanitize_key((string) $method_override));
            if ('' !== $method_endpoint && in_array($method_override, array('PURGE', 'BAN'), true)) {
                $normalized_method_overrides[$method_endpoint] = $method_override;
            }
        }
        if (empty($configured_endpoints)) {
            return array(
                'supported' => false,
                'manualSupported' => false,
                'topologyVerified' => false,
                'tested' => false,
                'htmlTestedAllEndpoints' => false,
                'hostTestedAllEndpoints' => false,
                'transportVerified' => false,
                'partialEndpointOutage' => false,
                'transportFailure' => false,
                'endpointBehaviorVerified' => false,
                'exactInvalidationVerified' => false,
                'htmlInvalidationVerified' => false,
                'entireHostVerified' => false,
                'staticOpaqueStable' => false,
                'controlDataPathIsolated' => false,
                'controlEndpointSetTested' => false,
                'transportMode' => $mode,
                'transportMethod' => (string) ($settings['method'] ?? 'BAN'),
                'configuredEndpointCount' => 0,
                'successfulEndpointCount' => 0,
                'failedEndpointCount' => 0,
                'staticRoute' => 'not-tested',
                'staticPreservation' => 'not-tested',
                'entireHostStatus' => 'not-tested',
                'entireHostStaticInvalidation' => 'not-tested',
                'status' => 'configuration-incomplete',
                'message' => self::maybe_translate('HTML-only and entire-host capability were not tested because no Varnish endpoints are configured.'),
                'testedAt' => $tested_at,
                'htmlTestedEndpointCount' => 0,
                'hostTestedEndpointCount' => 0,
                'endpointCapabilities' => array(),
            );
        }

        $groups = array();
        if ('admin' === $mode) {
            $groups[] = array(
                'observationEndpoint' => '',
                'transportEndpoints' => $configured_endpoints,
                'registryEndpoints' => $configured_endpoints,
                'transportMethod' => 'BAN',
            );
        } else {
            foreach ($configured_endpoints as $endpoint) {
                $groups[] = array(
                    'observationEndpoint' => $endpoint,
                    'transportEndpoints' => array($endpoint),
                    'registryEndpoints' => array($endpoint),
                    'transportMethod' => (string) ($normalized_method_overrides[$endpoint] ?? ''),
                );
            }
        }

        $endpoint_capabilities = array();
        foreach ($groups as $group) {
            $observation_endpoint = (string) ($group['observationEndpoint'] ?? '');
            $transport_endpoints = (array) ($group['transportEndpoints'] ?? array());
            $transport_method = strtoupper(sanitize_key((string) ($group['transportMethod'] ?? '')));
            if ('http' === $mode && !in_array($transport_method, array('PURGE', 'BAN'), true)) {
                $prerequisite_message = self::maybe_translate('HTML-only and entire-host flush were not tested because this endpoint did not first verify an exact PURGE or BAN control method.');
                $html = array(
                    'endpoint' => $observation_endpoint,
                    'controlEndpointSet' => $transport_endpoints,
                    'scope' => 'html',
                    'verified' => false,
                    'tested' => false,
                    'conclusive' => false,
                    'applicable' => true,
                    'reachable' => false,
                    'status' => 'exact-method-prerequisite-unverified',
                    'message' => $prerequisite_message,
                    'testedAt' => 0,
                    'steps' => array(),
                    'transport' => array(),
                );
                $host = array_merge($html, array('scope' => 'host'));
            } else {
                $html = self::run_varnish_flush_scope_canary(
                    'html',
                    $observation_endpoint,
                    $settings,
                    $timeout,
                    $transport_endpoints,
                    $transport_method
                );
                $host = self::run_varnish_flush_scope_canary(
                    'host',
                    $observation_endpoint,
                    $settings,
                    $timeout,
                    $transport_endpoints,
                    $transport_method
                );
            }
            $html_verified = !empty($html['verified']);
            $host_verified = !empty($host['verified']);
            $html_tested = !empty($html['tested']);
            $host_tested = !empty($host['tested']);
            $html_conclusive = $html_verified || !empty($html['conclusive']);
            $host_conclusive = $host_verified || !empty($host['conclusive']);
            $endpoint_observation_incomplete = ($html_tested && !$html_conclusive)
                || ($host_tested && !$host_conclusive);
            $endpoint_status = $html_verified && $host_verified
                ? 'verified'
                : (($html_verified || $host_verified)
                    ? 'partially-supported'
                    : ((!$html_tested && !$host_tested)
                        ? 'not-tested'
                        : ($endpoint_observation_incomplete
                            ? 'observation-incomplete'
                            : (($html_tested && $host_tested) ? 'not-supported' : 'partially-tested'))));

            foreach ((array) ($group['registryEndpoints'] ?? array()) as $registry_endpoint) {
                $registry_endpoint = self::normalize_varnish_registry_endpoint($registry_endpoint);
                if ('' === $registry_endpoint) {
                    continue;
                }
                $endpoint_capabilities[] = array(
                    'endpoint' => $registry_endpoint,
                    'controlEndpointSet' => $transport_endpoints,
                    'transportMethod' => $transport_method,
                    'reachable' => !empty($html['reachable']) || !empty($host['reachable']),
                    'htmlFlush' => $html_verified,
                    'hostFlush' => $host_verified,
                    'htmlTested' => $html_tested,
                    'hostTested' => $host_tested,
                    'htmlConclusive' => $html_conclusive,
                    'hostConclusive' => $host_conclusive,
                    'observationIncomplete' => $endpoint_observation_incomplete,
                    'status' => $endpoint_status,
                    'message' => self::sanitize_varnish_string((string) ($html['message'] ?? '') . ' ' . (string) ($host['message'] ?? '')),
                    'htmlTest' => $html,
                    'hostTest' => $host,
                    'staticRoute' => sanitize_key((string) ($html['staticRoute'] ?? $host['staticRoute'] ?? 'not-tested')),
                    'staticPreservation' => sanitize_key((string) ($html['staticPreservation'] ?? 'not-tested')),
                    'entireHostStatus' => sanitize_key((string) ($host['entireHostStatus'] ?? $html['entireHostStatus'] ?? 'not-tested')),
                    'entireHostStaticInvalidation' => sanitize_key((string) ($host['entireHostStaticInvalidation'] ?? $html['entireHostStaticInvalidation'] ?? 'not-tested')),
                    'staticRouteMessage' => self::sanitize_varnish_string((string) ($html['staticRouteMessage'] ?? $host['staticRouteMessage'] ?? '')),
                );
            }
        }

        $count = count($endpoint_capabilities);
        $html_verified_count = count(array_filter($endpoint_capabilities, static function ($item) {
            return !empty($item['htmlFlush']);
        }));
        $host_verified_count = count(array_filter($endpoint_capabilities, static function ($item) {
            return !empty($item['hostFlush']);
        }));
        $html_tested_count = count(array_filter($endpoint_capabilities, static function ($item) {
            return !empty($item['htmlTested']);
        }));
        $host_tested_count = count(array_filter($endpoint_capabilities, static function ($item) {
            return !empty($item['hostTested']);
        }));
        $html_conclusive_count = count(array_filter($endpoint_capabilities, static function ($item) {
            return !empty($item['htmlConclusive']);
        }));
        $host_conclusive_count = count(array_filter($endpoint_capabilities, static function ($item) {
            return !empty($item['hostConclusive']);
        }));
        $observation_incomplete_count = count(array_filter($endpoint_capabilities, static function ($item) {
            return !empty($item['observationIncomplete']);
        }));
        $endpoint_verified_count = count(array_filter($endpoint_capabilities, static function ($item) {
            return !empty($item['htmlFlush']) || !empty($item['hostFlush']);
        }));
        $static_routes = array_values(array_unique(array_filter(array_map(static function ($item) {
            return sanitize_key((string) ($item['staticRoute'] ?? ''));
        }, $endpoint_capabilities))));
        $static_preservations = array_values(array_unique(array_filter(array_map(static function ($item) {
            return sanitize_key((string) ($item['staticPreservation'] ?? ''));
        }, $endpoint_capabilities))));
        $entire_host_statuses = array_values(array_unique(array_filter(array_map(static function ($item) {
            return sanitize_key((string) ($item['entireHostStatus'] ?? ''));
        }, $endpoint_capabilities))));
        $entire_host_static_statuses = array_values(array_unique(array_filter(array_map(static function ($item) {
            return sanitize_key((string) ($item['entireHostStaticInvalidation'] ?? ''));
        }, $endpoint_capabilities))));
        $static_route_messages = array_values(array_unique(array_filter(array_map(static function ($item) {
            return self::sanitize_varnish_string((string) ($item['staticRouteMessage'] ?? ''));
        }, $endpoint_capabilities))));
        $html_all = $count > 0 && $html_verified_count === $count;
        $host_all = $count > 0 && $host_verified_count === $count;
        $html_tested_all = $count > 0 && $html_tested_count === $count;
        $host_tested_all = $count > 0 && $host_tested_count === $count;
        $html_conclusive_all = $count > 0 && $html_conclusive_count === $count;
        $host_conclusive_all = $count > 0 && $host_conclusive_count === $count;
        $any_tested = $html_tested_count > 0 || $host_tested_count > 0;
        $observation_incomplete = $observation_incomplete_count > 0;
        $supported = $html_all || $host_all;
        $static_route = 1 === count($static_routes) ? (string) $static_routes[0] : (!empty($static_routes) ? 'mixed-static-route' : 'not-tested');
        $static_preservation = 1 === count($static_preservations)
            ? (string) $static_preservations[0]
            : ($html_all ? 'unobservable' : 'not-tested');
        $entire_host_status = 1 === count($entire_host_statuses)
            ? (string) $entire_host_statuses[0]
            : ($host_all ? 'verified' : 'not-tested');
        $entire_host_static = 1 === count($entire_host_static_statuses)
            ? (string) $entire_host_static_statuses[0]
            : ($host_all ? 'verified' : 'not-tested');
        $static_bypass = 'varnish-bypass' === $static_route;
        $status = $html_all && $host_all
            ? 'verified'
            : ($html_all && $static_bypass
                ? 'html-verified-static-bypass'
                : ($supported
                    ? 'partially-supported'
                    : (!$any_tested
                        ? 'not-tested'
                        : ($observation_incomplete
                            ? 'observation-incomplete'
                            : (($html_tested_all && $host_tested_all) ? 'not-supported' : 'partially-tested')))));

        return array(
            'supported' => $supported,
            'manualSupported' => $supported,
            'topologyVerified' => $supported,
            'tested' => $any_tested,
            'htmlTestedAllEndpoints' => $html_tested_all,
            'hostTestedAllEndpoints' => $host_tested_all,
            'htmlConclusiveAllEndpoints' => $html_conclusive_all,
            'hostConclusiveAllEndpoints' => $host_conclusive_all,
            'observationIncomplete' => $observation_incomplete,
            'transportVerified' => $count > 0 && 0 === count(array_filter($endpoint_capabilities, static function ($item) {
                return empty($item['reachable']);
            })),
            'partialEndpointOutage' => ($html_verified_count > 0 && !$html_all) || ($host_verified_count > 0 && !$host_all),
            'transportFailure' => $count > 0 && 0 === count(array_filter($endpoint_capabilities, static function ($item) {
                return !empty($item['reachable']);
            })),
            'endpointBehaviorVerified' => $supported,
            'exactInvalidationVerified' => false,
            'htmlInvalidationVerified' => $html_all,
            'entireHostVerified' => $host_all,
            'staticOpaqueStable' => $html_all && 'through-varnish' === $static_route,
            'controlDataPathIsolated' => 'http' === $mode,
            'controlEndpointSetTested' => 'admin' === $mode,
            'transportMode' => $mode,
            'transportMethod' => (string) ($settings['method'] ?? 'BAN'),
            'configuredEndpointCount' => $count,
            'successfulEndpointCount' => $endpoint_verified_count,
            'failedEndpointCount' => max(0, $count - $endpoint_verified_count),
            'staticRoute' => $static_route,
            'staticPreservation' => $static_preservation,
            'entireHostStatus' => $entire_host_status,
            'entireHostStaticInvalidation' => $entire_host_static,
            'staticRouteMessage' => implode(' ', $static_route_messages),
            'status' => $status,
            'message' => $html_all && $host_all
                ? ('admin' === $mode
                    ? self::maybe_translate('The configured Admin endpoint set passed both production HTML-only and entire-host site-flush behavior proofs on the public path.')
                    : self::maybe_translate('Every configured HTTP endpoint passed both production HTML-only and entire-host site-flush behavior proofs.'))
                : ($html_all && $static_bypass
                    ? self::maybe_translate('Every configured endpoint passed the production HTML-only site-flush proof. Entire-host proof is not applicable because public static assets bypass Varnish.')
                    : ($supported
                        ? self::maybe_translate('Only part of the production Varnish site-flush capability was behavior-verified.')
                        : (!$any_tested
                            ? self::maybe_translate('The production HTML-only and entire-host site-flush operations were not tested because the required cacheable canaries were unavailable.')
                            : ($observation_incomplete
                                ? self::maybe_translate('The production site-flush transport executed, but bounded public transport failures left at least one capability observation incomplete.')
                                : (($html_tested_all && $host_tested_all)
                                    ? self::maybe_translate('The configured production Varnish site-flush runtime did not pass HTML-only or entire-host behavior proof.')
                                    : self::maybe_translate('The production HTML-only or entire-host site-flush operation was tested on only part of the configured endpoint topology.')))))),
            'testedAt' => $tested_at,
            'htmlTestedAt' => $html_tested_count > 0 ? $tested_at : 0,
            'hostTestedAt' => $host_tested_count > 0 ? $tested_at : 0,
            'htmlTestedEndpointCount' => $html_tested_count,
            'hostTestedEndpointCount' => $host_tested_count,
            'endpointCapabilities' => $endpoint_capabilities,
        );
    }

    /**
     * Prove soft expiry and stale-to-fresh refill on one HTTP endpoint.
     *
     * @param string $endpoint Configured HTTP endpoint.
     * @param array  $settings Normalized Varnish settings.
     * @param int    $timeout  Request timeout.
     * @return array<string,mixed>
     */
    private static function run_varnish_endpoint_soft_purge_canary($endpoint, array $settings, $timeout)
    {
        $endpoint = self::normalize_varnish_registry_endpoint($endpoint);
        $tested_at = time();
        $identifier = self::generate_varnish_canary_identifier();
        $canary = self::write_varnish_canary_generation($identifier, 1);
        if (is_wp_error($canary)) {
            return array(
                'endpoint' => $endpoint,
                'tested' => false,
                'reachable' => false,
                'softPurge' => false,
                'staleVerified' => false,
                'freshHitVerified' => false,
                'verificationAttemptCount' => 0,
                'status' => 'canary-create-failed',
                'message' => self::sanitize_varnish_string($canary->get_error_message()),
                'testedAt' => $tested_at,
                'steps' => array(),
                'transport' => array(),
            );
        }

        $selection = array('attempts' => array());
        $steps = array();
        $transport = array();
        $cleanup = array();
        $url = '';
        $tested = false;
        $reachable = false;
        $stale_verified = false;
        $fresh_hit_verified = false;
        $verification_attempt_count = 0;

        self::begin_varnish_test_run();
        try {
            $selection = self::select_varnish_production_canary_route($identifier, $canary, $timeout, '');
            if (!empty($selection['success']) && !empty($selection['runtimeEligible'])) {
                $url = esc_url_raw((string) ($selection['normalizedUrl'] ?? $selection['url'] ?? ''));
                $selected_attempt = is_array($selection['attempt'] ?? null) ? $selection['attempt'] : array();
                $steps = is_array($selected_attempt['steps'] ?? null) ? $selected_attempt['steps'] : array();
                $reachable = true;
                $generation_two = self::write_varnish_canary_generation($identifier, 2);
                if (is_wp_error($generation_two)) {
                    $transport = array(
                        'attempted' => false,
                        'ok' => false,
                        'code' => 0,
                        'detail' => self::sanitize_varnish_string($generation_two->get_error_message()),
                    );
                } else {
                    $soft_probe_token = self::begin_varnish_capability_probe(array(
                        'operation' => 'targeted',
                        'strategy' => 'soft-purge',
                        'requestedScope' => 'exact-url',
                        'endpoints' => array($endpoint),
                        'urls' => array($url),
                    ));
                    if ('' === $soft_probe_token) {
                        $transport = array(
                            'attempted' => false,
                            'operationAttempted' => false,
                            'ok' => false,
                            'status' => 'probe-context-unavailable',
                            'detail' => self::maybe_translate('The production soft-purge capability probe could not be bound to this endpoint and URL.'),
                            'code' => 0,
                        );
                    } else {
                        try {
                            $runtime_result = self::varnish_flush_url_batch(
                                array($url),
                                'diagnostic-soft-purge',
                                'soft',
                                array($endpoint),
                                $settings
                            );
                        } finally {
                            self::end_varnish_capability_probe($soft_probe_token);
                        }
                        $request_count = absint($runtime_result['requestCount'] ?? 0);
                        $selected_strategy = sanitize_key((string) ($runtime_result['runtimePlan']['selectedStrategy'] ?? ''));
                        $runtime_strategy = sanitize_key((string) ($runtime_result['invalidationStrategy'] ?? ''));
                        $soft_operation = 'soft-purge' === $selected_strategy && 'soft' === $runtime_strategy;
                        $transport = array(
                            'attempted' => $soft_operation && $request_count > 0,
                            'operationAttempted' => $request_count > 0,
                            'ok' => $soft_operation && !empty($runtime_result['success']),
                            'status' => $soft_operation
                                ? sanitize_key((string) ($runtime_result['runtimeOutcome'] ?? ($runtime_result['status'] ?? 'inconclusive')))
                                : 'runtime-strategy-mismatch',
                            'detail' => self::sanitize_varnish_string((string) ($runtime_result['message'] ?? '')),
                            'code' => 0,
                            'requestCount' => $request_count,
                            'successfulEndpointRequestCount' => absint($runtime_result['successfulEndpointRequestCount'] ?? 0),
                            'failedEndpointRequestCount' => absint($runtime_result['failedEndpointRequestCount'] ?? 0),
                            'fullyInvalidatedUrlCount' => absint($runtime_result['fullyInvalidatedUrlCount'] ?? 0),
                            'selectedStrategy' => $selected_strategy,
                            'invalidationStrategy' => $runtime_strategy,
                            'attemptedEndpointTargets' => array_values((array) ($runtime_result['attemptedEndpointTargets'] ?? array())),
                        );
                    }
                }
                $tested = !empty($transport['attempted']);
                $reachable = $reachable || absint($transport['code'] ?? 0) > 0 || !empty($transport['ok']);
                if (!empty($transport['ok'])) {
                    $steps['stale'] = self::run_varnish_public_capability_observation_request(
                        $url,
                        'soft_purge_stale',
                        $timeout
                    );
                    $stale_verified = self::varnish_canary_step_matches((array) $steps['stale'], $identifier, 1)
                        && 'STALE' === strtoupper((string) ($steps['stale']['status'] ?? ''))
                        && 'high' === strtolower((string) ($steps['stale']['confidence'] ?? ''));

                    if ($stale_verified) {
                        $deadline = microtime(true) + max(2, min(8, max(2, (int) $timeout) * 2));
                        $max_attempts = 8;
                        while ($verification_attempt_count < $max_attempts && microtime(true) < $deadline) {
                            $verification_attempt_count++;
                            usleep(250000);
                            $poll = self::run_varnish_public_capability_observation_request(
                                $url,
                                'soft_purge_revalidation_' . $verification_attempt_count,
                                $timeout
                            );
                            $steps['revalidation' . $verification_attempt_count] = $poll;
                            $fresh_candidate = self::varnish_canary_step_matches($poll, $identifier, 2)
                                && 'HIT' === strtoupper((string) ($poll['status'] ?? ''))
                                && 'high' === strtolower((string) ($poll['confidence'] ?? ''));
                            if (!$fresh_candidate) {
                                continue;
                            }
                            $steps['freshVerification'] = self::run_varnish_public_capability_observation_request(
                                $url,
                                'soft_purge_fresh_verification',
                                $timeout
                            );
                            $fresh_hit_verified = self::varnish_canary_step_matches((array) $steps['freshVerification'], $identifier, 2)
                                && 'HIT' === strtoupper((string) ($steps['freshVerification']['status'] ?? ''))
                                && 'high' === strtolower((string) ($steps['freshVerification']['confidence'] ?? ''));
                            break;
                        }
                    }
                }
            }

            if ('' !== $url) {
                $cleanup = self::run_varnish_basic_test_invalidation($url, $endpoint);
            }
        } finally {
            self::delete_varnish_canary($identifier);
            self::end_varnish_test_run();
        }

        if (!$tested) {
            $status = 'not-tested';
            $message = !empty($transport['detail'])
                ? self::sanitize_varnish_string((string) $transport['detail'])
                : self::maybe_translate('Soft purge was not tested because no stable production-eligible cacheable canary route was available for this endpoint.');
        } elseif (empty($transport['ok'])) {
            $status = 'transport-rejected';
            $message = self::maybe_translate('The production targeted runtime did not complete the soft PURGE request on this endpoint.');
        } elseif (!$stale_verified) {
            $status = 'stale-not-observed';
            $message = self::maybe_translate('The production soft PURGE was accepted, but the next response did not expose the cached first generation as a high-confidence STALE/grace response.');
        } elseif (!$fresh_hit_verified) {
            $status = 'fresh-refill-not-observed';
            $message = self::maybe_translate('The endpoint exposed a stale response after soft PURGE, but bounded polling did not reach a stable high-confidence HIT for the new origin generation.');
        } else {
            $status = 'verified';
            $message = self::maybe_translate('The production targeted runtime passed the soft-purge sequence: cached generation 1 became STALE, revalidated at origin, and returned generation 2 as a stable HIT.');
        }

        return array(
            'endpoint' => $endpoint,
            'tested' => $tested,
            'reachable' => $reachable,
            'transportAccepted' => !empty($transport['ok']),
            'softPurge' => $stale_verified && $fresh_hit_verified,
            'staleVerified' => $stale_verified,
            'freshHitVerified' => $fresh_hit_verified,
            'verificationAttemptCount' => $verification_attempt_count,
            'status' => $status,
            'message' => $message,
            'testedAt' => $tested_at,
            'steps' => $steps,
            'transport' => $transport,
            'cleanup' => array(
                'attempted' => '' !== $url,
                'accepted' => !empty($cleanup['success']),
            ),
        );
    }

    /**
     * Run the full HTTP soft-purge, origin-revalidation, and SWR proof.
     *
     * @param array $settings Normalized Varnish settings.
     * @param int   $timeout  Request timeout.
     * @return array<string,mixed>
     */
    private static function run_varnish_soft_purge_capability_probe(array $settings, $timeout)
    {
        $tested_at = time();
        $mode = self::sanitize_varnish_mode($settings['mode'] ?? 'http');
        $stale_seconds = max(0, min(86400, absint($settings['staleWhileRevalidateSeconds'] ?? 0)));
        $endpoints = array_values(array_unique(array_filter(array_map(static function ($endpoint) {
            return self::normalize_varnish_registry_endpoint($endpoint);
        }, (array) ($settings['servers'] ?? array())))));

        if ('http' !== $mode) {
            $soft_message = self::maybe_translate('Soft PURGE is not applicable through the configured Varnish admin BAN interface.');
            $origin = self::get_varnish_origin_revalidation_not_applicable_status();
            $endpoint_capabilities = array();
            foreach ($endpoints as $endpoint) {
                $endpoint_capabilities[] = array(
                    'endpoint' => $endpoint,
                    'reachable' => false,
                    'softPurge' => false,
                    'originRevalidation' => false,
                    'swr' => false,
                    'softPurgeTested' => false,
                    'originRevalidationTested' => false,
                    'swrTested' => false,
                    'softPurgeApplicable' => false,
                    'originRevalidationApplicable' => false,
                    'swrApplicable' => false,
                    'softPurgeStatus' => 'not-applicable',
                    'originRevalidationStatus' => 'not-applicable',
                    'swrStatus' => 'not-applicable',
                    'softPurgeMessage' => $soft_message,
                    'originRevalidationMessage' => (string) ($origin['message'] ?? ''),
                    'swrMessage' => $soft_message,
                    'testedAt' => 0,
                    'status' => 'not-applicable',
                    'message' => $soft_message,
                );
            }
            return array(
                'applicable' => false,
                'supported' => false,
                'tested' => false,
                'status' => 'not-applicable',
                'message' => $soft_message,
                'testedAt' => 0,
                'staleVerified' => false,
                'freshHitVerified' => false,
                'originRevalidationVerified' => false,
                'verificationAttemptCount' => 0,
                'endpointCapabilities' => $endpoint_capabilities,
                'originRevalidation' => $origin,
            );
        }
        if ($stale_seconds <= 0) {
            $soft_message = self::maybe_translate('Soft-purge and stale-refresh behavior are not applicable because the configured stale-while-revalidate window is zero.');
            $origin = empty($endpoints)
                ? array(
                    'applicable' => true,
                    'verified' => false,
                    'status' => 'not-tested',
                    'testedAt' => 0,
                    'message' => self::maybe_translate('Origin revalidation was not tested because no HTTP Varnish endpoint is configured.'),
                )
                : self::run_varnish_origin_revalidation_contract_test(home_url('/'), true);
            $origin_tested = absint($origin['testedAt'] ?? 0) > 0;
            $endpoint_capabilities = array();
            foreach ($endpoints as $endpoint) {
                $endpoint_capabilities[] = array(
                    'endpoint' => $endpoint,
                    'reachable' => $origin_tested,
                    'softPurge' => false,
                    'originRevalidation' => !empty($origin['verified']),
                    'swr' => false,
                    'softPurgeTested' => false,
                    'originRevalidationTested' => $origin_tested,
                    'swrTested' => false,
                    'softPurgeApplicable' => false,
                    'originRevalidationApplicable' => !array_key_exists('applicable', $origin) || !empty($origin['applicable']),
                    'swrApplicable' => false,
                    'softPurgeStatus' => 'not-applicable',
                    'originRevalidationStatus' => sanitize_key((string) ($origin['status'] ?? 'not-tested')),
                    'swrStatus' => 'not-applicable',
                    'softPurgeMessage' => $soft_message,
                    'originRevalidationMessage' => self::sanitize_varnish_string((string) ($origin['message'] ?? '')),
                    'swrMessage' => $soft_message,
                    'testedAt' => absint($origin['testedAt'] ?? 0),
                    'status' => 'not-applicable',
                    'message' => $soft_message,
                );
            }
            return array(
                'applicable' => false,
                'supported' => false,
                'tested' => $origin_tested,
                'status' => 'not-applicable',
                'message' => $soft_message,
                'testedAt' => absint($origin['testedAt'] ?? 0),
                'staleVerified' => false,
                'freshHitVerified' => false,
                'originRevalidationVerified' => !empty($origin['verified']),
                'verificationAttemptCount' => 0,
                'endpointCapabilities' => $endpoint_capabilities,
                'originRevalidation' => $origin,
            );
        }
        if (empty($endpoints)) {
            return array(
                'applicable' => true,
                'supported' => false,
                'tested' => false,
                'status' => 'not-tested',
                'message' => self::maybe_translate('Soft-purge behavior was not tested because no HTTP Varnish endpoint is configured.'),
                'testedAt' => 0,
                'staleVerified' => false,
                'freshHitVerified' => false,
                'originRevalidationVerified' => false,
                'verificationAttemptCount' => 0,
                'endpointCapabilities' => array(),
                'originRevalidation' => array(
                    'applicable' => true,
                    'verified' => false,
                    'status' => 'not-tested',
                    'testedAt' => 0,
                    'message' => self::maybe_translate('Origin revalidation was not tested because no HTTP Varnish endpoint is configured.'),
                ),
            );
        }

        $endpoint_results = array();
        $any_tested = false;
        $all_stale = !empty($endpoints);
        $all_fresh = !empty($endpoints);
        $attempt_count = 0;
        foreach ($endpoints as $endpoint) {
            $endpoint_result = self::run_varnish_endpoint_soft_purge_canary($endpoint, $settings, $timeout);
            $endpoint_results[] = $endpoint_result;
            $any_tested = $any_tested || !empty($endpoint_result['tested']);
            $all_stale = $all_stale && !empty($endpoint_result['staleVerified']);
            $all_fresh = $all_fresh && !empty($endpoint_result['freshHitVerified']);
            $attempt_count += absint($endpoint_result['verificationAttemptCount'] ?? 0);
        }

        $origin = self::run_varnish_origin_revalidation_contract_test(home_url('/'), true);
        $origin_verified = !empty($origin['verified']);
        $origin_tested = absint($origin['testedAt'] ?? 0) > 0;
        $endpoint_capabilities = array();
        $verified_count = 0;
        $tested_count = 0;
        foreach ($endpoint_results as $endpoint_result) {
            $endpoint_tested = !empty($endpoint_result['tested']);
            $endpoint_transport_accepted = !empty($endpoint_result['transportAccepted']);
            $swr_verified = !empty($endpoint_result['staleVerified']) && !empty($endpoint_result['freshHitVerified']);
            $verified = $endpoint_transport_accepted && $swr_verified && $origin_verified;
            if ($endpoint_tested) {
                $tested_count++;
            }
            if ($verified) {
                $verified_count++;
            }
            $endpoint_status = $verified
                ? 'verified'
                : (!$endpoint_tested
                    ? 'not-tested'
                    : (!$swr_verified
                        ? sanitize_key((string) ($endpoint_result['status'] ?? 'not-supported'))
                        : 'origin-revalidation-unverified'));
            $endpoint_message = $verified
                ? self::maybe_translate('Soft expiry, stale-to-fresh refill, and authenticated origin revalidation were verified.')
                : self::sanitize_varnish_string(trim(
                    (string) ($endpoint_result['message'] ?? '')
                    . ' '
                    . ($endpoint_transport_accepted && !$origin_verified ? (string) ($origin['message'] ?? '') : '')
                ));
            $endpoint_capabilities[] = array(
                'endpoint' => (string) ($endpoint_result['endpoint'] ?? ''),
                'reachable' => !empty($endpoint_result['reachable']),
                'softPurge' => $endpoint_transport_accepted && $swr_verified,
                'originRevalidation' => $origin_verified,
                'swr' => $endpoint_transport_accepted && $swr_verified,
                'softPurgeTested' => $endpoint_tested,
                'originRevalidationTested' => $origin_tested,
                'swrTested' => $endpoint_tested,
                'softPurgeApplicable' => true,
                'originRevalidationApplicable' => !array_key_exists('applicable', $origin) || !empty($origin['applicable']),
                'swrApplicable' => true,
                'softPurgeStatus' => sanitize_key((string) ($endpoint_result['status'] ?? ($endpoint_tested ? 'not-supported' : 'not-tested'))),
                'originRevalidationStatus' => sanitize_key((string) ($origin['status'] ?? ($origin_tested ? 'not-supported' : 'not-tested'))),
                'swrStatus' => sanitize_key((string) ($endpoint_result['status'] ?? ($endpoint_tested ? 'not-supported' : 'not-tested'))),
                'softPurgeMessage' => self::sanitize_varnish_string((string) ($endpoint_result['message'] ?? '')),
                'originRevalidationMessage' => self::sanitize_varnish_string((string) ($origin['message'] ?? '')),
                'swrMessage' => self::sanitize_varnish_string((string) ($endpoint_result['message'] ?? '')),
                'staleVerified' => !empty($endpoint_result['staleVerified']),
                'freshHitVerified' => !empty($endpoint_result['freshHitVerified']),
                'verificationAttemptCount' => absint($endpoint_result['verificationAttemptCount'] ?? 0),
                'testedAt' => max(
                    absint($endpoint_result['testedAt'] ?? 0),
                    absint($origin['testedAt'] ?? 0)
                ),
                'status' => $endpoint_status,
                'message' => $endpoint_message,
                'test' => $endpoint_result,
            );
        }

        $endpoint_count = count($endpoint_capabilities);
        $supported = $endpoint_count > 0 && $verified_count === $endpoint_count;
        if ($supported) {
            $status = 'verified';
            $message = self::maybe_translate('Every configured HTTP endpoint passed soft expiry, stale-to-fresh refill, and authenticated origin-revalidation proof.');
        } elseif (0 === $tested_count) {
            $status = 'not-tested';
            $message = self::maybe_translate('Soft purge was not tested because no configured endpoint exposed a stable cacheable canary route.');
        } elseif ($verified_count > 0) {
            $status = 'partial';
            $message = self::maybe_translate('Soft purge is verified on only part of the configured HTTP endpoint topology. Runtime soft purge remains disabled.');
        } elseif ($all_stale && $all_fresh && !$origin_verified) {
            $status = 'origin-revalidation-unverified';
            $message = self::sanitize_varnish_string((string) ($origin['message'] ?? self::maybe_translate('Authenticated origin revalidation was not verified.')));
        } else {
            $status = 'not-supported';
            $message = self::maybe_translate('The configured HTTP endpoint topology did not pass the complete soft-purge stale-to-fresh behavior proof.');
        }

        return array(
            'applicable' => true,
            'supported' => $supported,
            'tested' => $tested_count > 0 || $origin_tested,
            'status' => $status,
            'message' => $message,
            'testedAt' => ($tested_count > 0 || $origin_tested)
                ? max($tested_at, absint($origin['testedAt'] ?? 0))
                : 0,
            'configuredEndpointCount' => $endpoint_count,
            'testedEndpointCount' => $tested_count,
            'verifiedEndpointCount' => $verified_count,
            'staleVerified' => $all_stale,
            'freshHitVerified' => $all_fresh,
            'originRevalidationVerified' => $origin_verified,
            'verificationAttemptCount' => $attempt_count,
            'endpointCapabilities' => $endpoint_capabilities,
            'originRevalidation' => $origin,
        );
    }

    public static function varnish_test_behavior(array $settings = array())
    {
        if (empty($settings)) {
            self::reset_settings_cache();
            $settings = self::get_varnish_cli_settings();
        }
        $support = is_array($settings['support'] ?? null) ? $settings['support'] : array();
        $tested_at = time();

        if (empty($support['available'])) {
            $result = array(
                'success' => false,
                'verified' => false,
                'testType' => 'basic',
                'capabilityTest' => 'exact-url-canary',
                'operationType' => 'diagnostic-exact-url-test',
                'status' => 'configuration-incomplete',
                'message' => self::sanitize_varnish_string((string) ($support['message'] ?? 'Varnish integration is unavailable.')),
                'time' => $tested_at,
            );
            $result['esiCapability'] = self::mark_varnish_esi_capability_unverified(
                'configuration-incomplete',
                (string) $result['message']
            );
            return $result;
        }

        if (empty($settings['enabled'])) {
            $result = array(
                'success' => false,
                'verified' => false,
                'testType' => 'basic',
                'capabilityTest' => 'exact-url-canary',
                'operationType' => 'diagnostic-exact-url-test',
                'status' => 'configuration-incomplete',
                'message' => __('Varnish integration is disabled.', 'ultracache'),
                'time' => $tested_at,
            );
            $result['esiCapability'] = self::mark_varnish_esi_capability_unverified(
                'configuration-incomplete',
                (string) $result['message']
            );
            return $result;
        }

        if (empty($settings['servers'])) {
            $result = array(
                'success' => false,
                'verified' => false,
                'testType' => 'basic',
                'capabilityTest' => 'exact-url-canary',
                'operationType' => 'diagnostic-exact-url-test',
                'status' => 'configuration-incomplete',
                'message' => __('No Varnish endpoints are configured.', 'ultracache'),
                'time' => $tested_at,
            );
            $result['esiCapability'] = self::mark_varnish_esi_capability_unverified(
                'configuration-incomplete',
                (string) $result['message']
            );
            return $result;
        }

        if ('http' === self::sanitize_varnish_mode($settings['mode'] ?? 'http')) {
            return self::run_varnish_multi_endpoint_exact_canaries($settings);
        }

        $timeout = max(2, min(5, (int) ($settings['timeout'] ?? 5)));
        $identifier = self::generate_varnish_canary_identifier();
        $canary = self::write_varnish_canary_generation($identifier, 1);
        if (is_wp_error($canary)) {
            $result = array(
                'success' => false,
                'verified' => false,
                'testType' => 'basic',
                'capabilityTest' => 'exact-url-canary',
                'operationType' => 'diagnostic-exact-url-test',
                'status' => 'canary-write-failed',
                'message' => self::sanitize_varnish_string($canary->get_error_message()),
                'time' => $tested_at,
            );
            $result['esiCapability'] = self::mark_varnish_esi_capability_unverified(
                'probe-skipped',
                __('The ESI probe was skipped because the Varnish canary could not be created.', 'ultracache')
            );
            return $result;
        }

        $route_selection = array('success' => false, 'attempts' => array());
        $invalidation = array('success' => false, 'details' => array());
        $cleanup_invalidation = array();
        $steps = array();
        $observation_proof = array();
        $url = '';
        $route = '';
        $contract = array();
        $local_cleanup = false;

        self::begin_varnish_test_run();
        try {
            $route_selection = self::select_varnish_canary_route($identifier, $canary, $timeout);
            if (!empty($route_selection['success'])) {
                $url = esc_url_raw((string) ($route_selection['url'] ?? ''));
                $route = sanitize_key((string) ($route_selection['route'] ?? ''));
                $selected_attempt = is_array($route_selection['attempt'] ?? null) ? $route_selection['attempt'] : array();
                $steps = is_array($selected_attempt['steps'] ?? null) ? $selected_attempt['steps'] : array();

                $invalidation = self::run_varnish_basic_test_invalidation($url);
                $contract = is_array($invalidation['contract'] ?? null) ? $invalidation['contract'] : array();
                if (!empty($invalidation['success'])) {
                    $observation_proof = self::run_varnish_canary_post_operation_proof(
                        static function ($attempt_index) use ($url, $timeout) {
                            $step = 0 === $attempt_index
                                ? 'canary_after_invalidation'
                                : (1 === $attempt_index
                                    ? 'canary_refill_verification'
                                    : 'canary_observation_retry_' . ($attempt_index - 1));

                            return self::run_varnish_behavior_request($url, $step, $timeout);
                        },
                        $identifier,
                        2
                    );
                    foreach ((array) ($observation_proof['attempts'] ?? array()) as $attempt_index => $observation_step) {
                        if (0 === $attempt_index) {
                            $steps['afterInvalidation'] = $observation_step;
                        } elseif (1 === $attempt_index) {
                            $steps['verification'] = $observation_step;
                        } else {
                            $steps['observationRetry' . ($attempt_index - 1)] = $observation_step;
                        }
                    }
                }
            }

            if ('' !== $url) {
                $cleanup_invalidation = self::run_varnish_basic_test_invalidation($url);
            }
        } finally {
            $local_cleanup = self::delete_varnish_canary($identifier);
            self::end_varnish_test_run();
        }

        $attempt_summaries = array();
        $any_route_available = false;
        foreach ((array) ($route_selection['attempts'] ?? array()) as $attempt) {
            if (!is_array($attempt)) {
                continue;
            }
            $available = !empty($attempt['available']);
            $any_route_available = $any_route_available || $available;
            $attempt_summaries[] = array(
                'route' => sanitize_key((string) ($attempt['route'] ?? '')),
                'available' => $available,
                'cacheable' => !empty($attempt['cacheable']),
                'runtimeEligible' => !empty($attempt['runtimeEligible']),
                'status' => sanitize_key((string) ($attempt['status'] ?? 'unavailable')),
                'message' => self::sanitize_varnish_string((string) ($attempt['message'] ?? '')),
            );
        }

        $route_verified = !empty($route_selection['success']);
        $runtime_route_eligible = $route_verified && !empty($route_selection['runtimeEligible']);
        $invalidation_success = !empty($invalidation['success']);
        $control_connection_accepted = self::varnish_basic_invalidation_control_connection_accepted($invalidation);
        $after_matches = absint($observation_proof['matchingObservationCount'] ?? 0) > 0;
        $verification_matches = !empty($observation_proof['verified']);
        $behavior_verified = $route_verified
            && $invalidation_success
            && $verification_matches;
        $exact_invalidation_verified = $behavior_verified && $runtime_route_eligible;
        $static_exact_invalidation_verified = $behavior_verified && !$runtime_route_eligible;
        $hit_verified = false;
        foreach ((array) ($observation_proof['attempts'] ?? array()) as $observation_step) {
            if (is_array($observation_step)
                && self::varnish_canary_step_matches($observation_step, $identifier, 2)
                && 'HIT' === strtoupper((string) ($observation_step['status'] ?? ''))) {
                $hit_verified = true;
                break;
            }
        }
        $observation_summary = $observation_proof;
        unset($observation_summary['attempts']);
        $observation_mismatch = !empty($observation_proof['behaviorMismatchObserved']);
        $varnish_detected = false;
        foreach ($steps as $step) {
            if (is_array($step) && !empty($step['varnishDetected'])) {
                $varnish_detected = true;
                break;
            }
        }
        if ($exact_invalidation_verified) {
            $varnish_detected = true;
        }
        $cache_signals_hidden = $exact_invalidation_verified && !$hit_verified;
        $endpoint_count = max(0, (int) ($settings['endpointCount'] ?? count((array) ($settings['servers'] ?? array()))));
        $admin_control_set_verified = $exact_invalidation_verified
            && 'admin' === self::sanitize_varnish_mode($settings['mode'] ?? 'http')
            && $endpoint_count > 0;
        $per_endpoint_behavior_verified = $exact_invalidation_verified
            && (1 === $endpoint_count || $admin_control_set_verified);
        $mixed_endpoint_topology_unverified = $exact_invalidation_verified
            && $endpoint_count > 1
            && !$admin_control_set_verified;

        if (!$route_verified) {
            if ($any_route_available) {
                $status = 'canary-not-cacheable';
                $message = __('The Varnish canary was reachable, but none of the tested public routes retained the first generation after the origin file changed. Exact invalidation was not claimed.', 'ultracache');
            } else {
                $status = 'canary-route-unavailable';
                $message = __('No tested public route could serve the Varnish canary. Exact invalidation was not claimed.', 'ultracache');
            }
            $success = false;
        } elseif (!$invalidation_success) {
            $status = self::classify_varnish_basic_invalidation_failure($invalidation);
            $message = 'authentication-failed' === $status
                ? __('Varnish transport or authentication failed during the exact canary invalidation.', 'ultracache')
                : __('Varnish accepted no successful exact invalidation request for the cached canary.', 'ultracache');
            $success = false;
        } elseif (!$after_matches) {
            $status = $observation_mismatch ? 'invalidation-not-observed' : 'observation-incomplete';
            $message = $observation_mismatch
                ? __('The invalidation request was accepted, but a successful public observation returned an unexpected canary generation.', 'ultracache')
                : __('The invalidation request was accepted, but the bounded public observations did not produce a successful canary response.', 'ultracache');
            $success = false;
        } elseif (!$verification_matches) {
            $status = $observation_mismatch ? 'refill-failed' : 'observation-incomplete';
            $message = $observation_mismatch
                ? __('Exact invalidation exposed the new canary generation, but a later successful refill observation returned an unexpected generation.', 'ultracache')
                : __('Exact invalidation exposed the new canary generation, but bounded public transport failures prevented a second successful refill observation.', 'ultracache');
            $success = false;
        } elseif (!$runtime_route_eligible) {
            $status = 'static-route-only';
            $message = __('The direct uploads canary proved shared-cache invalidation for a static file, but no WordPress-served canary route was cacheable. Managed page invalidation remains unverified.', 'ultracache');
            $success = false;
        } else {
            $status = $hit_verified ? 'working' : 'working-signals-hidden';
            $message = $hit_verified
                ? __('Varnish exact URL invalidation is verified with an isolated WordPress-served canary: cached generation 1 was replaced by generation 2 and the refilled object returned as a HIT.', 'ultracache')
                : __('Varnish exact URL invalidation is verified with an isolated WordPress-served canary. Cached generation 1 was replaced by generation 2; public HIT/MISS headers are hidden or incomplete.', 'ultracache');
            $success = true;
        }

        $esi_probe_allowed = $route_verified || $any_route_available;
        if ($esi_probe_allowed) {
            $esi_probe = self::run_varnish_esi_capability_probe($timeout);
            self::set_varnish_esi_capability($esi_probe);
            $esi_capability = self::get_varnish_esi_capability_status();
        } else {
            $esi_capability = self::mark_varnish_esi_capability_unverified(
                'probe-skipped',
                __('The ESI probe was skipped because no public canary route reached the frontend application.', 'ultracache')
            );
        }

        $html_variant_capability = $exact_invalidation_verified
            ? self::run_varnish_html_variant_capability_probe($timeout)
            : array(
                'supported' => false,
                'applicable' => true,
                'tested' => false,
                'status' => 'not-tested',
                'message' => __('HTML variants were not tested because the exact Varnish invalidation and refill test did not complete.', 'ultracache'),
                'time' => $tested_at,
                'url' => home_url('/'),
                'activeBuckets' => array(),
                'verifiedBucketCount' => 0,
                'details' => array(),
            );

        $batch_ban_capability = self::run_varnish_batch_ban_capability_probe($settings, $timeout, $exact_invalidation_verified);
        $flush_topology_capability = self::run_varnish_flush_topology_capability_probe($settings, $timeout);
        $flush_topology_capability['exactInvalidationVerified'] = $exact_invalidation_verified;
        $soft_purge_capability = self::run_varnish_soft_purge_capability_probe($settings, $timeout);

        $endpoint_results = array();
        $configured_endpoints = array();
        foreach ((array) ($settings['servers'] ?? array()) as $configured_endpoint) {
            $configured_endpoint = self::normalize_varnish_registry_endpoint($configured_endpoint);
            if ('' !== $configured_endpoint) {
                $configured_endpoints[] = $configured_endpoint;
            }
        }
        $configured_endpoints = array_values(array_unique($configured_endpoints));
        $tested_exact_capabilities = self::normalize_varnish_behavior_exact_capabilities(
            (array) ($invalidation['testedExactCapabilities'] ?? array())
        );
        $verified_exact_capability = $exact_invalidation_verified
            ? (in_array(self::normalize_varnish_behavior_exact_capability($invalidation['verifiedExactCapability'] ?? ''), array('exactPurge', 'exactBan'), true)
                ? self::normalize_varnish_behavior_exact_capability($invalidation['verifiedExactCapability'])
                : ('PURGE' === strtoupper((string) ($settings['method'] ?? 'BAN')) ? 'exactPurge' : 'exactBan'))
            : '';
        $invalidation_detail_map = array();
        foreach ((array) ($invalidation['details'] ?? array()) as $invalidation_detail) {
            if (!is_array($invalidation_detail)) {
                continue;
            }
            $detail_endpoint = self::normalize_varnish_registry_endpoint($invalidation_detail['server'] ?? '');
            if ('' !== $detail_endpoint) {
                $invalidation_detail_map[$detail_endpoint] = $invalidation_detail;
            }
        }
        $admin_mode = 'admin' === self::sanitize_varnish_mode($settings['mode'] ?? 'http');
        if ($admin_mode && !empty($configured_endpoints)) {
            $admin_set_verified = $exact_invalidation_verified;
            foreach ($configured_endpoints as $configured_endpoint) {
                $endpoint_detail = is_array($invalidation_detail_map[$configured_endpoint] ?? null)
                    ? $invalidation_detail_map[$configured_endpoint]
                    : array();
                if (empty($endpoint_detail) || empty($endpoint_detail['success'])) {
                    $admin_set_verified = false;
                    break;
                }
            }
            $per_endpoint_behavior_verified = $admin_set_verified;
            $mixed_endpoint_topology_unverified = $exact_invalidation_verified
                && count($configured_endpoints) > 1
                && !$admin_set_verified;
            foreach ($configured_endpoints as $configured_endpoint) {
                $endpoint_detail = is_array($invalidation_detail_map[$configured_endpoint] ?? null)
                    ? $invalidation_detail_map[$configured_endpoint]
                    : array();
                $endpoint_transport_accepted = !empty($endpoint_detail['success']);
                $endpoint_connection_accepted = !empty($endpoint_detail['connectionAccepted'])
                    || $endpoint_transport_accepted;
                $endpoint_results[] = array(
                    'endpoint' => $configured_endpoint,
                    'success' => $admin_set_verified,
                    'verified' => $admin_set_verified,
                    'exactInvalidationVerified' => $admin_set_verified,
                    'testedExactCapabilities' => $tested_exact_capabilities,
                    'verifiedExactCapability' => $admin_set_verified ? 'exactBan' : '',
                    'reachable' => $endpoint_connection_accepted,
                    'controlConnectionAccepted' => $endpoint_connection_accepted,
                    'transportAccepted' => $endpoint_transport_accepted,
                    'sharedCacheVerified' => $route_verified,
                    'runtimeRouteEligible' => $runtime_route_eligible,
                    'invalidationVerified' => $admin_set_verified && $after_matches,
                    'refillVerified' => $admin_set_verified && $verification_matches,
                    'hitVerified' => $admin_set_verified && $hit_verified,
                    'status' => $admin_set_verified ? $status : 'admin-endpoint-set-unverified',
                    'message' => $admin_set_verified
                        ? $message
                        : self::maybe_translate('The complete configured Admin endpoint set did not accept the exact BAN and expose the verified public refill behavior.'),
                    'time' => $tested_at,
                    'method' => 'BAN',
                    'contract' => array(),
                    'controlEndpointSet' => $configured_endpoints,
                );
            }
        } elseif (1 === count($configured_endpoints)) {
            $endpoint_results[] = array(
                'endpoint' => (string) $configured_endpoints[0],
                'success' => $success,
                'verified' => $exact_invalidation_verified,
                'exactInvalidationVerified' => $exact_invalidation_verified,
                'testedExactCapabilities' => $tested_exact_capabilities,
                'verifiedExactCapability' => $verified_exact_capability,
                'reachable' => $varnish_detected || $route_verified || $control_connection_accepted || $invalidation_success,
                'controlConnectionAccepted' => $control_connection_accepted,
                'transportAccepted' => $invalidation_success,
                'sharedCacheVerified' => $route_verified,
                'runtimeRouteEligible' => $runtime_route_eligible,
                'invalidationVerified' => $after_matches,
                'refillVerified' => $verification_matches,
                'hitVerified' => $hit_verified,
                'status' => $status,
                'message' => $message,
                'time' => $tested_at,
                'method' => (string) ($settings['method'] ?? 'BAN'),
                'contract' => $contract,
            );
        }

        return array(
            'success' => $success,
            'verified' => $exact_invalidation_verified,
            'testType' => 'basic',
            'capabilityTest' => 'exact-url-canary',
            'operationType' => 'diagnostic-exact-url-test',
            'status' => $status,
            'message' => $message,
            'time' => $tested_at,
            'mode' => (string) ($settings['mode'] ?? 'http'),
            'method' => (string) ($settings['method'] ?? 'BAN'),
            'effectiveMethod' => (string) ($settings['effectiveMethod'] ?? ''),
            'endpointCount' => $endpoint_count,
            'endpointResults' => $endpoint_results,
            'varnishDetected' => $varnish_detected,
            'controlTransportTested' => $route_verified,
            'controlConnectionAccepted' => $control_connection_accepted,
            'controlTransportAccepted' => $invalidation_success,
            'publicBehaviorVerified' => $behavior_verified,
            'runtimeRouteEligible' => $runtime_route_eligible,
            'staticExactInvalidationVerified' => $static_exact_invalidation_verified,
            'perEndpointBehaviorVerified' => $per_endpoint_behavior_verified,
            'mixedEndpointTopologyUnverified' => $mixed_endpoint_topology_unverified,
            'cacheSignalsHidden' => $cache_signals_hidden,
            'connectionTested' => $route_verified,
            'connectionVerified' => $exact_invalidation_verified,
            'transportAccepted' => $invalidation_success,
            'invalidationAttempted' => $route_verified,
            'invalidationAccepted' => $invalidation_success,
            'invalidationVerified' => $after_matches,
            'exactInvalidationVerified' => $exact_invalidation_verified,
            'sharedCacheVerified' => $route_verified,
            'canaryCacheable' => $route_verified,
            'canaryRoute' => $route,
            'canaryAttempts' => $attempt_summaries,
            'refillVerified' => $verification_matches,
            'hitVerified' => $hit_verified,
            'esiTested' => !empty($esi_capability['tested']),
            'esiSupported' => !empty($esi_capability['supported']),
            'esiVerified' => !empty($esi_capability['verified']),
            'esiEffective' => !empty($esi_capability['effective']),
            'esiStatus' => (string) ($esi_capability['status'] ?? 'not-tested'),
            'esiMessage' => (string) ($esi_capability['message'] ?? ''),
            'esiCapability' => $esi_capability,
            'htmlVariantsSupported' => !empty($html_variant_capability['supported']),
            'htmlVariantCapability' => $html_variant_capability,
            'batchBanSupported' => !empty($batch_ban_capability['supported']),
            'batchBanCapability' => $batch_ban_capability,
            'htmlFlushSupported' => !empty($flush_topology_capability['htmlInvalidationVerified']),
            'hostFlushSupported' => !empty($flush_topology_capability['entireHostVerified']),
            'flushTopologyCapability' => $flush_topology_capability,
            'softPurgeSupported' => !empty($soft_purge_capability['supported']),
            'softPurgeCapability' => $soft_purge_capability,
            'originRevalidationVerified' => !empty($soft_purge_capability['originRevalidationVerified']),
            'staleWhileRevalidateVerified' => !empty($soft_purge_capability['staleVerified']) && !empty($soft_purge_capability['freshHitVerified']),
            'steps' => $steps,
            'observationProof' => $observation_summary,
            'connectionDetails' => is_array($invalidation['details'] ?? null) ? $invalidation['details'] : array(),
            'details' => is_array($invalidation['details'] ?? null) ? $invalidation['details'] : array(),
            'cleanup' => array(
                'localFileRemoved' => $local_cleanup,
                'cachedObjectPurgeAttempted' => '' !== $url,
                'cachedObjectPurgeAccepted' => !empty($cleanup_invalidation['success']),
            ),
        );
    }

}
