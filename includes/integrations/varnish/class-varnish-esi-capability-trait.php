<?php
/**
 * End-to-end Varnish ESI capability verification for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_ESI_Capability_Trait
{
    /**
     * Return the independent frontend timeout used by end-to-end ESI probes.
     *
     * Admin socket timeouts protect CLI/BAN connections and are intentionally
     * not reused for WordPress -> Varnish -> origin -> fragment composition.
     *
     * @return int
     */
    private static function get_varnish_esi_probe_timeout_seconds()
    {
        $timeout = (int) apply_filters('ultracache_varnish_esi_probe_timeout_seconds', 20);
        return max(10, min(30, $timeout));
    }

    /**
     * Return the non-autoload option used by the latest ESI capability proof.
     *
     * @return string
     */
    private static function get_varnish_esi_capability_option_name()
    {
        return 'ultracache_varnish_esi_capability_v1';
    }

    /**
     * Return the UltraCache-owned state record for ESI capability evidence.
     *
     * @return string
     */
    private static function get_varnish_esi_capability_state_name()
    {
        return 'ultracache_state:varnish.esi_capability';
    }

    /**
     * Read the raw persisted ESI capability and migrate the legacy option once.
     *
     * @return array<string,mixed>
     */
    private static function read_persisted_varnish_esi_capability()
    {
        if (function_exists('ultracache_get_state_record_read_only')) {
            $record = ultracache_get_state_record_read_only(self::get_varnish_esi_capability_state_name());
            $payload = is_array($record['payload'] ?? null) ? $record['payload'] : array();
            $capability = is_array($payload['capability'] ?? null) ? $payload['capability'] : array();
            if (!empty($capability)) {
                return self::sanitize_varnish_result($capability);
            }
        }

        $legacy = get_option(self::get_varnish_esi_capability_option_name(), array());
        if (!is_array($legacy) || empty($legacy)) {
            return array();
        }
        $legacy = self::sanitize_varnish_result($legacy);
        if (function_exists('ultracache_mutate_state_record')) {
            $payload = array(
                'schemaVersion' => 1,
                'updatedAt' => time(),
                'capability' => $legacy,
            );
            $mutation = ultracache_mutate_state_record(
                self::get_varnish_esi_capability_state_name(),
                static function () use ($payload) {
                    return $payload;
                },
                5,
                array()
            );
            if (!empty($mutation['success'])) {
                delete_option(self::get_varnish_esi_capability_option_name());
            }
        }

        return $legacy;
    }

    /**
     * Persist the ESI capability in the existing UltraCache state table.
     *
     * @param array $capability Sanitized capability payload.
     * @return bool
     */
    private static function persist_varnish_esi_capability_state(array $capability)
    {
        if (!function_exists('ultracache_mutate_state_record')) {
            return false;
        }
        $payload = array(
            'schemaVersion' => 1,
            'updatedAt' => time(),
            'capability' => $capability,
        );
        $mutation = ultracache_mutate_state_record(
            self::get_varnish_esi_capability_state_name(),
            static function () use ($payload) {
                return $payload;
            },
            5,
            array()
        );
        if (!empty($mutation['success'])) {
            delete_option(self::get_varnish_esi_capability_option_name());
        }

        return !empty($mutation['success']);
    }

    /**
     * Return an unverified ESI capability payload.
     *
     * @param string $status  Stable status code.
     * @param string $message Human-readable message.
     * @param int    $tested_at Optional test timestamp.
     * @return array
     */
    private static function get_unverified_varnish_esi_capability($status, $message, $tested_at = 0)
    {
        $settings = self::get_dashboard_settings();
        $configured = self::is_varnish_runtime_enabled($settings);

        return array(
            'supported' => false,
            'verified' => false,
            'configured' => $configured,
            'effective' => false,
            'tested' => $tested_at > 0,
            'status' => sanitize_key((string) $status),
            'message' => self::sanitize_varnish_string((string) $message),
            'testedAt' => max(0, (int) $tested_at),
            'identityVerified' => false,
            'gzipVerified' => false,
            'brotliClientVerified' => false,
            'compositionVerified' => false,
            'hitVerified' => false,
            'fragmentCacheVerified' => false,
            'privateTransportVerified' => false,
            'privateSessionIsolationVerified' => false,
            'privateParentCacheVerified' => false,
            'privateFragmentNoStoreVerified' => false,
            'privateOnerrorVerified' => false,
            'woocommerceTransportVerified' => false,
            'woocommerceAdapterAvailable' => class_exists('WooCommerce') || defined('WC_VERSION'),
            'rawMarkupBlocked' => false,
            'fallbackRemoved' => false,
            'steps' => array(),
        );
    }

    /**
     * Persist an unavailable/unverified ESI capability state.
     *
     * @param string $status  Stable status code.
     * @param string $message Human-readable message.
     * @return array
     */
    protected static function mark_varnish_esi_capability_unverified($status, $message)
    {
        return self::set_varnish_esi_capability(
            self::get_unverified_varnish_esi_capability($status, $message, time())
        );
    }

    /**
     * Persist one complete ESI capability result.
     *
     * @param array $capability Capability result.
     * @return array
     */
    protected static function set_varnish_esi_capability(array $capability)
    {
        $capability['testedAt'] = max(0, (int) ($capability['testedAt'] ?? time()));
        $capability = self::sanitize_varnish_result($capability);
        if (!is_array($capability)) {
            $capability = array();
        }
        $capability = self::bind_varnish_capability_contracts($capability, array('esi'));

        // A transport-incomplete observation is not evidence that an existing
        // current capability disappeared. Keep the verified proof and attach
        // the incomplete attempt as diagnostic history without extending its
        // expiry or changing runtime permission.
        if ('observation-incomplete' === sanitize_key((string) ($capability['status'] ?? ''))) {
            $current = self::read_persisted_varnish_esi_capability();
            $current_tested_at = absint($current['testedAt'] ?? 0);
            $current_expires_at = absint($current['proofExpiresAt'] ?? 0);
            if ($current_expires_at <= 0 && !empty($current['supported']) && !empty($current['verified']) && $current_tested_at > 0) {
                $current_expires_at = $current_tested_at + WEEK_IN_SECONDS;
            }
            if (!empty($current['supported'])
                && !empty($current['verified'])
                && self::varnish_capability_contracts_match($current, array('esi'))
                && $current_expires_at > time()) {
                $current['proofExpiresAt'] = $current_expires_at;
                $current['lastProbeStatus'] = 'observation-incomplete';
                $current['lastProbeMessage'] = self::sanitize_varnish_string((string) ($capability['message'] ?? ''));
                $current['lastProbeTestedAt'] = absint($capability['testedAt'] ?? time());
                $current['lastProbeSteps'] = is_array($capability['steps'] ?? null) ? $capability['steps'] : array();
                $current = self::sanitize_varnish_result($current);
                self::persist_varnish_esi_capability_state($current);
                return $current;
            }
        }

        self::persist_varnish_esi_capability_state($capability);
        return $capability;
    }

    /**
     * Read the current ESI capability and bind it to the current configuration.
     *
     * @return array
     */
    public static function get_varnish_esi_capability_status()
    {
        $settings = self::get_dashboard_settings();
        $configured = self::is_varnish_runtime_enabled($settings);
        $value = self::read_persisted_varnish_esi_capability();

        if (!is_array($value) || empty($value)) {
            $capability = self::get_unverified_varnish_esi_capability(
                'not-tested',
                __('Run Test Varnish to verify end-to-end ESI processing.', 'ultracache')
            );
            $capability['configured'] = $configured;
            return $capability;
        }

        if (!self::varnish_capability_contracts_match($value, array('esi'))) {
            $capability = self::get_unverified_varnish_esi_capability(
                'configuration-changed',
                __('The ESI capability contract changed. Run Test Varnish again.', 'ultracache'),
                (int) ($value['testedAt'] ?? 0)
            );
            $capability['configurationChanged'] = true;
            $capability['previousTestedAt'] = max(0, (int) ($value['testedAt'] ?? 0));
            $capability['configured'] = $configured;
            return $capability;
        }

        $tested_at = max(0, (int) ($value['testedAt'] ?? 0));
        $proof_expires_at = max(0, (int) ($value['proofExpiresAt'] ?? 0));
        if ($proof_expires_at <= 0 && !empty($value['supported']) && !empty($value['verified']) && $tested_at > 0) {
            $proof_expires_at = $tested_at + WEEK_IN_SECONDS;
        }
        if ($proof_expires_at > 0 && $proof_expires_at <= time()) {
            $capability = self::get_unverified_varnish_esi_capability(
                'proof-expired',
                __('The stored public-path ESI behavior proof expired. Run Test Varnish again.', 'ultracache'),
                $tested_at
            );
            $capability['proofExpired'] = true;
            $capability['proofExpiresAt'] = $proof_expires_at;
            $capability['configured'] = $configured;
            return $capability;
        }

        $value['configured'] = $configured;
        $value['proofExpiresAt'] = $proof_expires_at;
        $value['woocommerceAdapterAvailable'] = class_exists('WooCommerce') || defined('WC_VERSION');
        $value['supported'] = !empty($value['supported']) && !empty($value['verified']);
        $value['effective'] = $configured && !empty($value['supported']);
        if (!$configured) {
            $value['effective'] = false;
            if (!empty($value['supported'])) {
                $value['message'] = __('ESI proof is stored, but no active Varnish connection is configured. Registered fragments render inline fallback HTML.', 'ultracache');
            }
        }

        return $value;
    }

    /**
     * Whether public ESI markup may be emitted for normal frontend pages.
     *
     * @return bool
     */
    public static function is_varnish_esi_capability_verified()
    {
        $capability = self::get_varnish_esi_capability_status();
        return !empty($capability['effective']);
    }

    /**
     * Whether verified private/session ESI transport may be emitted.
     *
     * @return bool
     */
    public static function is_varnish_private_esi_capability_verified()
    {
        $capability = self::get_varnish_esi_capability_status();
        return !empty($capability['effective'])
            && !empty($capability['privateTransportVerified'])
            && !empty($capability['privateSessionIsolationVerified'])
            && !empty($capability['privateParentCacheVerified'])
            && !empty($capability['privateFragmentNoStoreVerified'])
            && !empty($capability['privateOnerrorVerified']);
    }

    /**
     * Whether private ESI onerror=continue behavior was verified.
     *
     * @return bool
     */
    public static function is_varnish_private_esi_onerror_verified()
    {
        $capability = self::get_varnish_esi_capability_status();
        return !empty($capability['effective'])
            && !empty($capability['privateTransportVerified'])
            && !empty($capability['privateOnerrorVerified']);
    }


    /**
     * Whether the WooCommerce classic mini-cart cookie transport was verified.
     *
     * @return bool
     */
    public static function is_varnish_woocommerce_esi_capability_verified()
    {
        $capability = self::get_varnish_esi_capability_status();
        return !empty($capability['effective'])
            && !empty($capability['privateTransportVerified'])
            && !empty($capability['privateSessionIsolationVerified'])
            && !empty($capability['privateParentCacheVerified'])
            && !empty($capability['privateFragmentNoStoreVerified'])
            && !empty($capability['privateOnerrorVerified'])
            && !empty($capability['woocommerceAdapterAvailable'])
            && !empty($capability['woocommerceTransportVerified']);
    }

    /**
     * Create a short-lived signed token for an ESI capability probe endpoint.
     *
     * @param string $kind  parent or fragment.
     * @param string $nonce Probe nonce.
     * @param int    $expires Expiration timestamp.
     * @return string
     */
    protected static function create_varnish_esi_probe_token($kind, $nonce, $expires)
    {
        $kind = sanitize_key((string) $kind);
        if (!in_array($kind, array('parent', 'fragment', 'private-parent', 'private-fragment'), true)) {
            return '';
        }
        $nonce = strtolower(preg_replace('/[^a-f0-9]/', '', (string) $nonce));
        $expires = max(time() + 30, min(time() + 600, (int) $expires));
        if (32 !== strlen($nonce)) {
            return '';
        }

        $payload = array(
            'v' => 1,
            'kind' => $kind,
            'nonce' => $nonce,
            'exp' => $expires,
            'blog' => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0,
        );
        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || '' === $json) {
            return '';
        }

        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, self::get_varnish_esi_probe_signing_key());
        return $encoded . '.' . $signature;
    }

    /**
     * Decode and verify a short-lived ESI capability probe token.
     *
     * @param string $token Signed token.
     * @param string $expected_kind Expected endpoint kind.
     * @return array|WP_Error
     */
    protected static function decode_varnish_esi_probe_token($token, $expected_kind)
    {
        $token = trim((string) $token);
        $expected_kind = sanitize_key((string) $expected_kind);
        if (!in_array($expected_kind, array('parent', 'fragment', 'private-parent', 'private-fragment'), true)) {
            return new WP_Error('ultracache_esi_probe_kind_invalid', __('The ESI probe kind is invalid.', 'ultracache'));
        }
        if ('' === $token || strlen($token) > 2048 || 1 !== preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/', $token, $matches)) {
            return new WP_Error('ultracache_esi_probe_token_invalid', __('The ESI probe token is invalid.', 'ultracache'));
        }

        $encoded = (string) $matches[1];
        $expected_signature = hash_hmac('sha256', $encoded, self::get_varnish_esi_probe_signing_key());
        if (!hash_equals($expected_signature, (string) $matches[2])) {
            return new WP_Error('ultracache_esi_probe_signature_invalid', __('The ESI probe signature is invalid.', 'ultracache'));
        }

        $base64 = strtr($encoded, '-_', '+/');
        $padding = strlen($base64) % 4;
        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }
        $json = base64_decode($base64, true);
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($payload)
            || 1 !== (int) ($payload['v'] ?? 0)
            || $expected_kind !== (string) ($payload['kind'] ?? '')
            || (int) ($payload['exp'] ?? 0) < time()
            || (int) ($payload['exp'] ?? 0) > time() + 600
            || (function_exists('get_current_blog_id') && (int) ($payload['blog'] ?? -1) !== (int) get_current_blog_id())
            || 1 !== preg_match('/^[a-f0-9]{32}$/', (string) ($payload['nonce'] ?? ''))
        ) {
            return new WP_Error('ultracache_esi_probe_payload_invalid', __('The ESI probe payload is invalid or expired.', 'ultracache'));
        }

        return $payload;
    }

    /**
     * Return the site-specific ESI probe signing key.
     *
     * @return string
     */
    private static function get_varnish_esi_probe_signing_key()
    {
        $blog_id = function_exists('get_current_blog_id') ? (string) get_current_blog_id() : '0';
        return hash('sha256', wp_salt('nonce') . '|ultracache-varnish-esi-probe-v1|' . $blog_id . '|' . home_url('/'));
    }

    /**
     * Build one signed same-origin probe URL.
     *
     * @param string $kind  parent or fragment.
     * @param string $nonce Probe nonce.
     * @return string
     */
    protected static function get_varnish_esi_probe_url($kind, $nonce)
    {
        $token = self::create_varnish_esi_probe_token($kind, $nonce, time() + 300);
        if ('' === $token) {
            return '';
        }

        $query_keys = array(
            'parent' => 'ultracache_esi_probe',
            'fragment' => 'ultracache_esi_probe_fragment',
            'private-parent' => 'ultracache_esi_probe_private',
            'private-fragment' => 'ultracache_esi_probe_private_fragment',
        );
        $query_key = $query_keys[$kind] ?? '';
        if ('' === $query_key) {
            return '';
        }

        $args = array($query_key => $token);
        if (0 === strpos($kind, 'private-')) {
            $args['esi_scope'] = 'private';
        }
        return esc_url_raw(add_query_arg($args, home_url('/')));
    }

    /**
     * Decode a compressed probe response when the HTTP transport did not.
     *
     * @param string $body     Raw body.
     * @param string $encoding Content-Encoding header.
     * @return array{body:string,inspectable:bool,decodeStatus:string}
     */
    private static function decode_varnish_esi_probe_body($body, $encoding)
    {
        $body = (string) $body;
        $encoding = strtolower(trim((string) $encoding));
        if ('' === $encoding || 'identity' === $encoding) {
            return array('body' => $body, 'inspectable' => true, 'decodeStatus' => 'identity');
        }

        if ('gzip' === $encoding) {
            if (false !== strpos($body, 'ULTRACACHE_ESI_')) {
                return array('body' => $body, 'inspectable' => true, 'decodeStatus' => 'transport-decoded-gzip');
            }
            if (function_exists('gzdecode')) {
                $decoded = @gzdecode($body);
                if (is_string($decoded)) {
                    return array('body' => $decoded, 'inspectable' => true, 'decodeStatus' => 'decoded-gzip');
                }
            }
            return array('body' => $body, 'inspectable' => false, 'decodeStatus' => 'gzip-decode-failed');
        }

        if ('br' === $encoding) {
            if (false !== strpos($body, 'ULTRACACHE_ESI_')) {
                return array('body' => $body, 'inspectable' => true, 'decodeStatus' => 'transport-decoded-brotli');
            }
            if (function_exists('brotli_uncompress')) {
                $decoded = @brotli_uncompress($body);
                if (is_string($decoded)) {
                    return array('body' => $decoded, 'inspectable' => true, 'decodeStatus' => 'decoded-brotli');
                }
            }
            return array('body' => $body, 'inspectable' => false, 'decodeStatus' => 'brotli-decoder-unavailable');
        }

        return array('body' => $body, 'inspectable' => false, 'decodeStatus' => 'unsupported-content-encoding');
    }

    /**
     * Run one public ESI probe request and classify the composed response.
     *
     * @param string $url      Probe parent URL.
     * @param string $step     Diagnostic step key.
     * @param int    $timeout  Request timeout.
     * @param string $encoding Accept-Encoding value.
     * @param string $nonce    Probe nonce.
     * @return array
     */
    private static function run_varnish_esi_probe_request($url, $step, $timeout, $encoding, $nonce)
    {
        $started = microtime(true);
        $response = ultracache_safe_loopback_remote_request($url, array(
            'method' => 'GET',
            'timeout' => self::get_varnish_esi_probe_timeout_seconds(),
            'redirection' => 0,
            'headers' => array(
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Encoding' => (string) $encoding,
            ),
            'cookies' => array(),
            'decompress' => true,
        ), 'varnish_esi_' . sanitize_key((string) $step));
        $duration_ms = (int) round((microtime(true) - $started) * 1000);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'step' => sanitize_key((string) $step),
                'status' => 'ERROR',
                'httpCode' => 0,
                'durationMs' => $duration_ms,
                'message' => self::sanitize_varnish_string($response->get_error_message()),
                'compositionVerified' => false,
                'fragmentRendered' => false,
                'fallbackRemoved' => false,
                'rawMarkupBlocked' => false,
                'inspectable' => false,
                'headers' => array(),
            );
        }

        $response_code = (int) wp_remote_retrieve_response_code($response);
        $headers = array(
            'age' => self::get_varnish_response_header($response, 'age'),
            'via' => self::get_varnish_response_header($response, 'via'),
            'server' => self::get_varnish_response_header($response, 'server'),
            'xVarnish' => self::get_varnish_response_header($response, 'x-varnish'),
            'xVarnishCache' => self::get_varnish_response_header($response, 'x-varnish-cache'),
            'xCache' => self::get_varnish_response_header($response, 'x-cache'),
            'xCacheStatus' => self::get_varnish_response_header($response, 'x-cache-status'),
            'xProxyCache' => self::get_varnish_response_header($response, 'x-proxy-cache'),
            'contentEncoding' => self::get_varnish_response_header($response, 'content-encoding'),
            'contentType' => self::get_varnish_response_header($response, 'content-type'),
        );
        $classification = self::classify_varnish_response($headers, $response_code);
        $decoded = self::decode_varnish_esi_probe_body(
            (string) wp_remote_retrieve_body($response),
            (string) $headers['contentEncoding']
        );
        $body = (string) $decoded['body'];
        $parent_marker = 'ULTRACACHE_ESI_PARENT_' . $nonce;
        $fragment_marker = 'ULTRACACHE_ESI_FRAGMENT_' . $nonce;
        $fallback_marker = 'ULTRACACHE_ESI_FALLBACK_' . $nonce;
        $fragment_render_token = '';
        if (1 === preg_match('/ULTRACACHE_ESI_RENDER_([a-f0-9]{16})/', $body, $render_matches)) {
            $fragment_render_token = (string) $render_matches[1];
        }
        $fragment_rendered = false !== strpos($body, $fragment_marker);
        $parent_rendered = false !== strpos($body, $parent_marker);
        $fallback_removed = false === strpos($body, $fallback_marker);
        $raw_markup_blocked = false === stripos($body, '<esi:include') && false === stripos($body, '<esi:remove');
        $inspectable = !empty($decoded['inspectable']);
        $composition_verified = $inspectable && $parent_rendered && $fragment_rendered && $fallback_removed && $raw_markup_blocked;

        return array(
            'success' => $response_code >= 200 && $response_code < 300 && $inspectable,
            'step' => sanitize_key((string) $step),
            'status' => (string) $classification['status'],
            'httpCode' => $response_code,
            'durationMs' => $duration_ms,
            'varnishDetected' => !empty($classification['varnishDetected']),
            'confidence' => (string) $classification['confidence'],
            'evidence' => (string) $classification['evidence'],
            'message' => self::sanitize_varnish_string('HTTP ' . $response_code . ' · ' . (string) $decoded['decodeStatus']),
            'compositionVerified' => $composition_verified,
            'parentRendered' => $parent_rendered,
            'fragmentRendered' => $fragment_rendered,
            'fragmentRenderToken' => $fragment_render_token,
            'fallbackRemoved' => $fallback_removed,
            'rawMarkupBlocked' => $raw_markup_blocked,
            'inspectable' => $inspectable,
            'decodeStatus' => (string) $decoded['decodeStatus'],
            'bodyBytes' => strlen($body),
            'bodySha256' => '' !== $body ? hash('sha256', $body) : '',
            'headers' => $headers,
        );
    }

    /**
     * Run one private/session ESI probe request.
     *
     * @param string $url      Probe parent URL.
     * @param string $step     Diagnostic step key.
     * @param int    $timeout  Request timeout.
     * @param string $session  Probe session identifier.
     * @param string $nonce    Probe nonce.
     * @return array
     */
    private static function run_varnish_private_esi_probe_request($url, $step, $timeout, $session, $nonce)
    {
        $started = microtime(true);
        $woocommerce_hash = substr(hash('sha256', (string) $nonce . '|' . (string) $session . '|woocommerce'), 0, 16);
        $cookie_header = 'ultracache_esi_optin=1'
            . '; esi_session=' . sanitize_key((string) $session)
            . '; woocommerce_items_in_cart=1'
            . '; woocommerce_cart_hash=' . $woocommerce_hash
            . '; wp_woocommerce_session_probe=' . sanitize_key((string) $session);
        $response = ultracache_safe_loopback_remote_request($url, array(
            'method' => 'GET',
            'timeout' => self::get_varnish_esi_probe_timeout_seconds(),
            'redirection' => 0,
            'headers' => array(
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Encoding' => 'gzip',
                'Cookie' => $cookie_header,
            ),
            'decompress' => true,
        ), 'varnish_esi_private_' . sanitize_key((string) $step));
        $duration_ms = (int) round((microtime(true) - $started) * 1000);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'step' => sanitize_key((string) $step),
                'status' => 'ERROR',
                'httpCode' => 0,
                'durationMs' => $duration_ms,
                'message' => self::sanitize_varnish_string($response->get_error_message()),
                'compositionVerified' => false,
                'sessionVerified' => false,
                'onerrorVerified' => false,
                'inspectable' => false,
                'headers' => array(),
            );
        }

        $response_code = (int) wp_remote_retrieve_response_code($response);
        $headers = array(
            'age' => self::get_varnish_response_header($response, 'age'),
            'via' => self::get_varnish_response_header($response, 'via'),
            'server' => self::get_varnish_response_header($response, 'server'),
            'xVarnish' => self::get_varnish_response_header($response, 'x-varnish'),
            'xCache' => self::get_varnish_response_header($response, 'x-cache'),
            'contentEncoding' => self::get_varnish_response_header($response, 'content-encoding'),
            'contentType' => self::get_varnish_response_header($response, 'content-type'),
        );
        $classification = self::classify_varnish_response($headers, $response_code);
        $decoded = self::decode_varnish_esi_probe_body(
            (string) wp_remote_retrieve_body($response),
            (string) $headers['contentEncoding']
        );
        $body = (string) $decoded['body'];
        $parent_marker = 'ULTRACACHE_ESI_PRIVATE_PARENT_' . $nonce;
        $fragment_marker = 'ULTRACACHE_ESI_PRIVATE_FRAGMENT_' . $nonce;
        $session_marker = 'ULTRACACHE_ESI_PRIVATE_SESSION_' . sanitize_key((string) $session);
        $fallback_marker = 'ULTRACACHE_ESI_PRIVATE_FALLBACK_' . $nonce;
        $tail_marker = 'ULTRACACHE_ESI_PRIVATE_TAIL_' . $nonce;
        $woocommerce_items_marker = 'ULTRACACHE_ESI_WOO_ITEMS_1';
        $woocommerce_hash_marker = 'ULTRACACHE_ESI_WOO_HASH_' . $woocommerce_hash;
        $woocommerce_session_marker = 'ULTRACACHE_ESI_WOO_SESSION_' . sanitize_key((string) $session);
        $parent_render_token = '';
        $fragment_render_token = '';
        if (1 === preg_match('/ULTRACACHE_ESI_PRIVATE_PARENT_RENDER_([a-f0-9]{16})/', $body, $matches)) {
            $parent_render_token = (string) $matches[1];
        }
        if (1 === preg_match('/ULTRACACHE_ESI_PRIVATE_RENDER_([a-f0-9]{16})/', $body, $matches)) {
            $fragment_render_token = (string) $matches[1];
        }

        $inspectable = !empty($decoded['inspectable']);
        $parent_rendered = false !== strpos($body, $parent_marker);
        $fragment_rendered = false !== strpos($body, $fragment_marker);
        $session_verified = false !== strpos($body, $session_marker);
        $fallback_removed = false === strpos($body, $fallback_marker);
        $raw_markup_blocked = false === stripos($body, '<esi:include') && false === stripos($body, '<esi:remove');
        $onerror_verified = false !== strpos($body, $tail_marker);
        $woocommerce_transport_verified = false !== strpos($body, $woocommerce_items_marker)
            && false !== strpos($body, $woocommerce_hash_marker)
            && false !== strpos($body, $woocommerce_session_marker);
        $composition_verified = $inspectable
            && $parent_rendered
            && $fragment_rendered
            && $session_verified
            && $fallback_removed
            && $raw_markup_blocked
            && $onerror_verified;

        return array(
            'success' => $response_code >= 200 && $response_code < 300 && $inspectable,
            'step' => sanitize_key((string) $step),
            'status' => (string) $classification['status'],
            'httpCode' => $response_code,
            'durationMs' => $duration_ms,
            'varnishDetected' => !empty($classification['varnishDetected']),
            'message' => self::sanitize_varnish_string('HTTP ' . $response_code . ' · ' . (string) $decoded['decodeStatus']),
            'compositionVerified' => $composition_verified,
            'parentRendered' => $parent_rendered,
            'fragmentRendered' => $fragment_rendered,
            'sessionVerified' => $session_verified,
            'onerrorVerified' => $onerror_verified,
            'woocommerceTransportVerified' => $woocommerce_transport_verified,
            'parentRenderToken' => $parent_render_token,
            'fragmentRenderToken' => $fragment_render_token,
            'fallbackRemoved' => $fallback_removed,
            'rawMarkupBlocked' => $raw_markup_blocked,
            'inspectable' => $inspectable,
            'decodeStatus' => (string) $decoded['decodeStatus'],
            'bodyBytes' => strlen($body),
            'bodySha256' => '' !== $body ? hash('sha256', $body) : '',
            'headers' => $headers,
        );
    }

    /**
     * Retry one ESI observation only when the frontend request did not produce
     * an HTTP response. A successful HTTP response with incomplete composition
     * is conclusive behavior evidence and is never retried into a pass.
     *
     * @param callable $request      Zero-argument request callback.
     * @param int      $max_attempts Maximum transport attempts.
     * @return array<string,mixed>
     */
    private static function run_varnish_esi_transport_observation(callable $request, $max_attempts = 3)
    {
        $max_attempts = max(1, min(3, absint($max_attempts)));
        $attempts = array();
        $result = array();
        for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
            $result = $request();
            if (!is_array($result)) {
                $result = array();
            }
            $attempts[] = $result;
            if (!empty($result['success']) || absint($result['httpCode'] ?? 0) > 0) {
                break;
            }
            if ($attempt + 1 < $max_attempts) {
                usleep(200000);
            }
        }
        $result['transportAttempts'] = $attempts;
        $result['transportAttemptCount'] = count($attempts);
        $result['transportIncomplete'] = empty($result['success'])
            && 0 === absint($result['httpCode'] ?? 0);

        return $result;
    }

    /**
     * Run the complete identity/gzip/Brotli-client ESI capability probe.
     *
     * @param int $timeout Request timeout.
     * @return array
     */
    protected static function run_varnish_esi_capability_probe($timeout)
    {
        // The admin endpoint timeout is deliberately ignored for frontend ESI composition.
        $timeout = self::get_varnish_esi_probe_timeout_seconds();
        $tested_at = time();
        $scenarios = array(
            'identity' => 'identity',
            'gzip' => 'gzip',
            'brotliClient' => 'br, gzip',
        );
        $steps = array();
        $scenario_verified = array();
        $scenario_composition_verified = array();
        $hit_verified = false;
        $fragment_cache_verified = true;
        $varnish_detected = false;
        $public_observation_incomplete = false;

        foreach ($scenarios as $scenario => $accept_encoding) {
            try {
                $nonce = bin2hex(random_bytes(16));
            } catch (Throwable $e) {
                $nonce = md5(uniqid('ultracache-esi-', true));
            }
            $url = self::get_varnish_esi_probe_url('parent', $nonce);
            if ('' === $url || !ultracache_is_trusted_loopback_url($url)) {
                $steps[$scenario] = array(
                    'success' => false,
                    'status' => 'ERROR',
                    'message' => __('The signed ESI probe URL could not be created.', 'ultracache'),
                    'compositionVerified' => false,
                );
                $scenario_verified[$scenario] = false;
                $scenario_composition_verified[$scenario] = false;
                $fragment_cache_verified = false;
                continue;
            }

            $first = self::run_varnish_esi_transport_observation(
                static function () use ($url, $scenario, $timeout, $accept_encoding, $nonce) {
                    return self::run_varnish_esi_probe_request(
                        $url,
                        'esi_' . $scenario . '_first',
                        $timeout,
                        $accept_encoding,
                        $nonce
                    );
                }
            );
            $second = !empty($first['success'])
                ? self::run_varnish_esi_transport_observation(
                    static function () use ($url, $scenario, $timeout, $accept_encoding, $nonce) {
                        return self::run_varnish_esi_probe_request(
                            $url,
                            'esi_' . $scenario . '_second',
                            $timeout,
                            $accept_encoding,
                            $nonce
                        );
                    }
                )
                : array();

            $public_observation_incomplete = $public_observation_incomplete
                || !empty($first['transportIncomplete'])
                || (!empty($first['success']) && !empty($second['transportIncomplete']));
            $first_render_token = (string) ($first['fragmentRenderToken'] ?? '');
            $second_render_token = (string) ($second['fragmentRenderToken'] ?? '');
            $scenario_fragment_cache = 16 === strlen($first_render_token)
                && 16 === strlen($second_render_token)
                && hash_equals($first_render_token, $second_render_token);
            $scenario_composition = !empty($first['compositionVerified'])
                && !empty($second['compositionVerified']);
            $scenario_ok = $scenario_composition && $scenario_fragment_cache;
            $scenario_hit = in_array(strtoupper((string) ($second['status'] ?? '')), array('HIT', 'STALE'), true);
            $scenario_varnish = !empty($first['varnishDetected']) || !empty($second['varnishDetected']);
            $scenario_verified[$scenario] = $scenario_ok;
            $scenario_composition_verified[$scenario] = $scenario_composition;
            $hit_verified = $hit_verified || $scenario_hit;
            $fragment_cache_verified = $fragment_cache_verified && $scenario_fragment_cache;
            $varnish_detected = $varnish_detected || $scenario_varnish;
            $steps[$scenario] = array(
                'success' => $scenario_ok,
                'compositionVerified' => $scenario_composition,
                'hitVerified' => $scenario_hit,
                'fragmentCacheVerified' => $scenario_fragment_cache,
                'varnishDetected' => $scenario_varnish,
                'first' => $first,
                'second' => $second,
            );
        }

        $identity_verified = !empty($scenario_verified['identity']);
        $gzip_verified = !empty($scenario_verified['gzip']);
        $brotli_verified = !empty($scenario_verified['brotliClient']);
        $composition_verified = !empty($scenario_composition_verified['identity'])
            && !empty($scenario_composition_verified['gzip'])
            && !empty($scenario_composition_verified['brotliClient']);
        $supported = $identity_verified && $gzip_verified && $brotli_verified
            && $composition_verified
            && $fragment_cache_verified;
        $raw_markup_blocked = $composition_verified;
        $fallback_removed = $composition_verified;

        try {
            $private_nonce = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            $private_nonce = md5(uniqid('ultracache-esi-private-', true));
        }
        $private_url = self::get_varnish_esi_probe_url('private-parent', $private_nonce);
        $private_session_a = substr(hash('sha256', $private_nonce . '|a'), 0, 16);
        $private_session_b = substr(hash('sha256', $private_nonce . '|b'), 0, 16);
        $private_first = array();
        $private_repeat = array();
        $private_second = array();
        if ('' !== $private_url && ultracache_is_trusted_loopback_url($private_url)) {
            $private_first = self::run_varnish_esi_transport_observation(
                static function () use ($private_url, $timeout, $private_session_a, $private_nonce) {
                    return self::run_varnish_private_esi_probe_request(
                        $private_url,
                        'esi_private_session_a_first',
                        $timeout,
                        $private_session_a,
                        $private_nonce
                    );
                }
            );
            $private_repeat = !empty($private_first['success'])
                ? self::run_varnish_esi_transport_observation(
                    static function () use ($private_url, $timeout, $private_session_a, $private_nonce) {
                        return self::run_varnish_private_esi_probe_request(
                            $private_url,
                            'esi_private_session_a_repeat',
                            $timeout,
                            $private_session_a,
                            $private_nonce
                        );
                    }
                )
                : array();
            $private_second = !empty($private_repeat['success'])
                ? self::run_varnish_esi_transport_observation(
                    static function () use ($private_url, $timeout, $private_session_b, $private_nonce) {
                        return self::run_varnish_private_esi_probe_request(
                            $private_url,
                            'esi_private_session_b',
                            $timeout,
                            $private_session_b,
                            $private_nonce
                        );
                    }
                )
                : array();
        }

        $private_observation_incomplete = !empty($private_first['transportIncomplete'])
            || (!empty($private_first['success']) && !empty($private_repeat['transportIncomplete']))
            || (!empty($private_repeat['success']) && !empty($private_second['transportIncomplete']));
        $private_session_isolation_verified = !empty($private_first['compositionVerified'])
            && !empty($private_repeat['compositionVerified'])
            && !empty($private_second['compositionVerified'])
            && !empty($private_first['sessionVerified'])
            && !empty($private_repeat['sessionVerified'])
            && !empty($private_second['sessionVerified'])
            && (string) ($private_first['bodySha256'] ?? '') !== (string) ($private_second['bodySha256'] ?? '');
        $private_parent_cache_verified = 16 === strlen((string) ($private_first['parentRenderToken'] ?? ''))
            && 16 === strlen((string) ($private_repeat['parentRenderToken'] ?? ''))
            && 16 === strlen((string) ($private_second['parentRenderToken'] ?? ''))
            && hash_equals(
                (string) $private_first['parentRenderToken'],
                (string) $private_repeat['parentRenderToken']
            )
            && hash_equals(
                (string) $private_first['parentRenderToken'],
                (string) $private_second['parentRenderToken']
            );
        $private_fragment_no_store_verified = 16 === strlen((string) ($private_first['fragmentRenderToken'] ?? ''))
            && 16 === strlen((string) ($private_repeat['fragmentRenderToken'] ?? ''))
            && 16 === strlen((string) ($private_second['fragmentRenderToken'] ?? ''))
            && !hash_equals(
                (string) $private_first['fragmentRenderToken'],
                (string) $private_repeat['fragmentRenderToken']
            )
            && !hash_equals(
                (string) $private_repeat['fragmentRenderToken'],
                (string) $private_second['fragmentRenderToken']
            );
        $private_onerror_verified = !empty($private_first['onerrorVerified'])
            && !empty($private_repeat['onerrorVerified'])
            && !empty($private_second['onerrorVerified']);
        $woocommerce_transport_verified = !empty($private_first['woocommerceTransportVerified'])
            && !empty($private_repeat['woocommerceTransportVerified'])
            && !empty($private_second['woocommerceTransportVerified']);
        $private_transport_verified = $supported
            && $private_session_isolation_verified
            && $private_parent_cache_verified
            && $private_fragment_no_store_verified
            && $private_onerror_verified;
        $steps['privateTransport'] = array(
            'success' => $private_transport_verified,
            'sessionIsolationVerified' => $private_session_isolation_verified,
            'parentCacheVerified' => $private_parent_cache_verified,
            'fragmentNoStoreVerified' => $private_fragment_no_store_verified,
            'onerrorVerified' => $private_onerror_verified,
            'woocommerceTransportVerified' => $woocommerce_transport_verified,
            'first' => $private_first,
            'repeat' => $private_repeat,
            'second' => $private_second,
        );
        $varnish_detected = $varnish_detected
            || !empty($private_first['varnishDetected'])
            || !empty($private_repeat['varnishDetected'])
            || !empty($private_second['varnishDetected']);

        if ($supported && $private_transport_verified && $varnish_detected && $hit_verified) {
            $status = 'working-private';
            $message = __('Varnish public ESI and private/session transport are verified, including shared parent reuse, isolated session output, non-cacheable private fragments, and onerror containment.', 'ultracache');
        } elseif ($supported && $private_transport_verified) {
            $status = 'working-private-signals-hidden';
            $message = __('Public ESI and private/session transport are verified, but Varnish or cache HIT headers are hidden.', 'ultracache');
        } elseif ($supported && $varnish_detected && $hit_verified) {
            $status = 'working-public-only';
            $message = __('Public Varnish ESI is verified. Private/session transport is unavailable, so private fragments continue rendering inline fallback HTML.', 'ultracache');
        } elseif ($supported) {
            $status = 'working-public-only-signals-hidden';
            $message = __('Public ESI is verified, but private/session transport is unavailable and Varnish or cache HIT headers are hidden.', 'ultracache');
        } elseif ($public_observation_incomplete) {
            $status = 'observation-incomplete';
            $message = __('The ESI probe did not receive a complete pair of frontend HTTP observations after bounded transport retries. The previous current proof, if any, remains authoritative.', 'ultracache');
        } elseif ($composition_verified && !$fragment_cache_verified) {
            $status = 'fragment-cache-failed';
            $message = __('ESI composition succeeded, but the public fragment was not reused from shared cache across repeated parent deliveries. UltraCache will keep rendering inline fallback HTML.', 'ultracache');
        } else {
            $status = 'not-supported';
            $message = __('Varnish did not complete the end-to-end ESI composition probe. UltraCache will keep rendering inline fallback HTML.', 'ultracache');
        }

        return array(
            'supported' => $supported,
            'verified' => $supported,
            'configured' => self::is_varnish_runtime_enabled(self::get_dashboard_settings()),
            'effective' => false,
            'tested' => true,
            'status' => $status,
            'message' => $message,
            'testedAt' => $tested_at,
            'identityVerified' => $identity_verified,
            'gzipVerified' => $gzip_verified,
            'brotliClientVerified' => $brotli_verified,
            'compositionVerified' => $composition_verified,
            'hitVerified' => $hit_verified,
            'fragmentCacheVerified' => $fragment_cache_verified,
            'publicObservationIncomplete' => $public_observation_incomplete,
            'privateObservationIncomplete' => $private_observation_incomplete,
            'privateTransportVerified' => $private_transport_verified,
            'privateSessionIsolationVerified' => $private_session_isolation_verified,
            'privateParentCacheVerified' => $private_parent_cache_verified,
            'privateFragmentNoStoreVerified' => $private_fragment_no_store_verified,
            'privateOnerrorVerified' => $private_onerror_verified,
            'woocommerceTransportVerified' => $woocommerce_transport_verified,
            'woocommerceAdapterAvailable' => class_exists('WooCommerce') || defined('WC_VERSION'),
            'varnishDetected' => $varnish_detected,
            'rawMarkupBlocked' => $raw_markup_blocked,
            'fallbackRemoved' => $fallback_removed,
            'steps' => $steps,
        );
    }
}
