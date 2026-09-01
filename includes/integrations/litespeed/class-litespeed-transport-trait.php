<?php
/**
 * LiteSpeed cache detection and purge transport helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_LiteSpeed_Transport_Trait
{
    /** @var bool Prevent recursive same-site LiteSpeed origin probes. */
    private static $litespeed_origin_probe_active = false;

    private static function get_litespeed_detection_option_name()
    {
        return 'ultracache_litespeed_detection_v1';
    }

    private static function get_litespeed_detection_fingerprint()
    {
        $origin = function_exists('ultracache_get_configured_site_origin')
            ? (string) ultracache_get_configured_site_origin()
            : '';

        return hash('sha256', wp_json_encode(array(
            'origin' => $origin,
        )));
    }

    private static function get_default_litespeed_detection_evidence()
    {
        return array(
            'schemaVersion' => 1,
            'fingerprint' => self::get_litespeed_detection_fingerprint(),
            'detected' => false,
            'serverDetected' => false,
            'cacheHeaderDetected' => false,
            'liteSpeedCacheHeaderDetected' => false,
            'quicCloudCacheHeaderDetected' => false,
            'server' => '',
            'xLiteSpeedCache' => '',
            'xQcCache' => '',
            'source' => '',
            'confirmedAt' => 0,
            'confirmedAtHuman' => '',
            'lastProbe' => array(),
            'configurationChanged' => false,
        );
    }

    private static function get_persistent_litespeed_detection_evidence()
    {
        $default = self::get_default_litespeed_detection_evidence();
        $stored = get_option(self::get_litespeed_detection_option_name(), array());
        if (!is_array($stored) || empty($stored)) {
            return $default;
        }

        $stored = array_merge($default, $stored);
        $fingerprint = (string) ($stored['fingerprint'] ?? '');
        $current_fingerprint = self::get_litespeed_detection_fingerprint();
        if ('' === $fingerprint || !hash_equals($fingerprint, $current_fingerprint)) {
            $default['configurationChanged'] = true;
            return $default;
        }

        $stored['schemaVersion'] = 1;
        $stored['fingerprint'] = $current_fingerprint;
        $stored['serverDetected'] = !empty($stored['serverDetected']);
        $stored['cacheHeaderDetected'] = !empty($stored['cacheHeaderDetected']);
        $stored['liteSpeedCacheHeaderDetected'] = !empty($stored['liteSpeedCacheHeaderDetected']);
        $stored['quicCloudCacheHeaderDetected'] = !empty($stored['quicCloudCacheHeaderDetected']);
        $stored['detected'] = $stored['serverDetected'] || $stored['liteSpeedCacheHeaderDetected'];
        $stored['server'] = sanitize_text_field((string) ($stored['server'] ?? ''));
        $stored['xLiteSpeedCache'] = sanitize_text_field((string) ($stored['xLiteSpeedCache'] ?? ''));
        $stored['xQcCache'] = sanitize_text_field((string) ($stored['xQcCache'] ?? ''));
        $stored['source'] = sanitize_key((string) ($stored['source'] ?? ''));
        $stored['confirmedAt'] = max(0, (int) ($stored['confirmedAt'] ?? 0));
        $stored['confirmedAtHuman'] = $stored['confirmedAt'] > 0
            ? gmdate('Y-m-d H:i:s', $stored['confirmedAt']) . ' UTC'
            : '';
        $stored['lastProbe'] = is_array($stored['lastProbe'] ?? null) ? $stored['lastProbe'] : array();
        $stored['configurationChanged'] = false;

        return $stored;
    }

    /**
     * Return the current persistent LiteSpeed detection evidence without probing or mutating it.
     *
     * @return array<string,mixed>
     */
    public static function get_litespeed_detection_evidence_read_only()
    {
        return self::get_persistent_litespeed_detection_evidence();
    }

    /** Return the persistent LiteSpeed ESI capability option name. */
    private static function get_litespeed_esi_capability_option_name()
    {
        return 'ultracache_litespeed_esi_capability_v1';
    }

    /** Return the default persisted LiteSpeed ESI capability record. */
    private static function get_default_litespeed_esi_capability()
    {
        return array(
            'schemaVersion' => 1,
            'status' => 'not_tested',
            'serverType' => 'unknown',
            'reason' => 'not_tested',
            'source' => '',
            'checkedAt' => 0,
            'checkedAtHuman' => '',
        );
    }

    /** Return the persisted LiteSpeed ESI capability without probing or mutating it. */
    public static function get_litespeed_esi_capability_read_only()
    {
        $default = self::get_default_litespeed_esi_capability();
        $stored = get_option(self::get_litespeed_esi_capability_option_name(), array());
        if (!is_array($stored) || empty($stored)) {
            return $default;
        }

        $stored = array_merge($default, $stored);
        $status = sanitize_key((string) ($stored['status'] ?? 'not_tested'));
        if (!in_array($status, array('supported', 'not_supported', 'not_tested'), true)) {
            $status = 'not_tested';
        }
        $server_type = sanitize_key((string) ($stored['serverType'] ?? 'unknown'));
        if (!in_array($server_type, array('enterprise', 'openlitespeed', 'unknown'), true)) {
            $server_type = 'unknown';
        }

        $stored['schemaVersion'] = 1;
        $stored['status'] = $status;
        $stored['serverType'] = $server_type;
        $stored['reason'] = sanitize_key((string) ($stored['reason'] ?? 'not_tested'));
        $stored['source'] = sanitize_key((string) ($stored['source'] ?? ''));
        $stored['checkedAt'] = max(0, (int) ($stored['checkedAt'] ?? 0));
        $stored['checkedAtHuman'] = $stored['checkedAt'] > 0
            ? gmdate('Y-m-d H:i:s', $stored['checkedAt']) . ' UTC'
            : '';

        return $stored;
    }

    /**
     * Recalculate and persist LiteSpeed ESI capability from already-known detection evidence.
     *
     * This method never performs an HTTP probe. It is intended for explicit detection,
     * activation/update, and settings lifecycle events only.
     *
     * @param string $source Capability refresh source.
     * @return array<string,mixed>
     */
    public static function refresh_litespeed_esi_capability_from_persisted_detection($source = 'persisted-detection')
    {
        $source = sanitize_key((string) $source);
        if ('' === $source) {
            $source = 'persisted-detection';
        }

        $evidence = self::get_persistent_litespeed_detection_evidence();
        $server_type_constant = defined('LITESPEED_SERVER_TYPE')
            ? strtoupper((string) constant('LITESPEED_SERVER_TYPE'))
            : '';
        $esi_support_constant = defined('LSWCP_ESI_SUPPORT')
            ? (bool) constant('LSWCP_ESI_SUPPORT')
            : null;
        $lsws_edition = isset($_SERVER['LSWS_EDITION'])
            ? strtolower(trim(sanitize_text_field(wp_unslash($_SERVER['LSWS_EDITION']))))
            : '';
        $runtime_server = isset($_SERVER['SERVER_SOFTWARE'])
            ? strtolower(trim(sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))))
            : '';
        $evidence_server = strtolower(trim((string) ($evidence['server'] ?? '')));

        $openlitespeed = 'LITESPEED_SERVER_OLS' === $server_type_constant
            || (null !== $esi_support_constant && !$esi_support_constant)
            || 0 === strpos($lsws_edition, 'openlitespeed')
            || false !== strpos($runtime_server, 'openlitespeed')
            || false !== strpos($evidence_server, 'openlitespeed');

        $enterprise = in_array($server_type_constant, array('LITESPEED_SERVER_ENT', 'LITESPEED_SERVER_ADC'), true)
            || (null !== $esi_support_constant && $esi_support_constant && '' !== $server_type_constant)
            || ('' !== $runtime_server && false !== strpos($runtime_server, 'litespeed') && false === strpos($runtime_server, 'openlitespeed'))
            || (!empty($evidence['serverDetected'])
                && '' !== $evidence_server
                && false !== strpos($evidence_server, 'litespeed')
                && false === strpos($evidence_server, 'openlitespeed'));

        if ($openlitespeed) {
            $status = 'not_supported';
            $server_type = 'openlitespeed';
            $reason = 'openlitespeed_no_esi';
        } elseif ($enterprise) {
            $status = 'supported';
            $server_type = 'enterprise';
            $reason = 'litespeed_esi_supported';
        } else {
            $status = 'not_tested';
            $server_type = 'unknown';
            $reason = !empty($evidence['detected']) ? 'edition_not_confirmed' : 'litespeed_not_confirmed';
        }

        $next = array(
            'schemaVersion' => 1,
            'status' => $status,
            'serverType' => $server_type,
            'reason' => $reason,
            'source' => $source,
            'checkedAt' => time(),
            'checkedAtHuman' => gmdate('Y-m-d H:i:s') . ' UTC',
        );

        $current = self::get_litespeed_esi_capability_read_only();
        if ($next !== $current) {
            update_option(self::get_litespeed_esi_capability_option_name(), $next, false);
        }

        return $next;
    }

    private static function persist_litespeed_detection_evidence($server, $x_litespeed_cache = '', $x_qc_cache = '', $source = 'runtime', array $probe = array(), $force = false)
    {
        $server = sanitize_text_field((string) $server);
        $x_litespeed_cache = sanitize_text_field((string) $x_litespeed_cache);
        $x_qc_cache = sanitize_text_field((string) $x_qc_cache);
        $source = sanitize_key((string) $source);
        if ('' === $source) {
            $source = 'runtime';
        }

        $server_detected = false !== stripos($server, 'LiteSpeed') || false !== stripos($server, 'OpenLiteSpeed');
        $litespeed_cache_header_detected = '' !== trim($x_litespeed_cache);
        $quic_cloud_cache_header_detected = '' !== trim($x_qc_cache);
        $cache_header_detected = $litespeed_cache_header_detected || $quic_cloud_cache_header_detected;
        $detected = $server_detected || $litespeed_cache_header_detected;

        $current = self::get_persistent_litespeed_detection_evidence();
        $configuration_changed = !empty($current['configurationChanged']);
        if ($configuration_changed) {
            $current = self::get_default_litespeed_detection_evidence();
        }

        $next = $current;
        $next['schemaVersion'] = 1;
        $next['fingerprint'] = self::get_litespeed_detection_fingerprint();
        $next['configurationChanged'] = false;

        if (!empty($probe)) {
            $next['lastProbe'] = array(
                'success' => !empty($probe['success']),
                'httpCode' => max(0, min(599, (int) ($probe['httpCode'] ?? 0))),
                'errorCode' => sanitize_key((string) ($probe['errorCode'] ?? '')),
                'message' => substr(sanitize_text_field((string) ($probe['message'] ?? '')), 0, 300),
                'time' => max(0, (int) ($probe['time'] ?? time())),
                'source' => $source,
            );
        }

        if ($detected) {
            $positive_change = empty($current['detected'])
                || ($server_detected && empty($current['serverDetected']))
                || ($cache_header_detected && empty($current['cacheHeaderDetected']))
                || ($litespeed_cache_header_detected && empty($current['liteSpeedCacheHeaderDetected']))
                || ($quic_cloud_cache_header_detected && empty($current['quicCloudCacheHeaderDetected']))
                || ('' !== $server && $server !== (string) ($current['server'] ?? ''))
                || ('' !== $x_litespeed_cache && $x_litespeed_cache !== (string) ($current['xLiteSpeedCache'] ?? ''))
                || ('' !== $x_qc_cache && $x_qc_cache !== (string) ($current['xQcCache'] ?? ''));

            $next['detected'] = true;
            $next['serverDetected'] = !empty($next['serverDetected']) || $server_detected;
            $next['cacheHeaderDetected'] = !empty($next['cacheHeaderDetected']) || $cache_header_detected;
            $next['liteSpeedCacheHeaderDetected'] = !empty($next['liteSpeedCacheHeaderDetected']) || $litespeed_cache_header_detected;
            $next['quicCloudCacheHeaderDetected'] = !empty($next['quicCloudCacheHeaderDetected']) || $quic_cloud_cache_header_detected;
            if ('' !== $server) {
                $next['server'] = $server;
            }
            if ('' !== $x_litespeed_cache) {
                $next['xLiteSpeedCache'] = $x_litespeed_cache;
            }
            if ('' !== $x_qc_cache) {
                $next['xQcCache'] = $x_qc_cache;
            }
            if ($force || $configuration_changed || $positive_change || empty($current['confirmedAt'])) {
                $next['source'] = $source;
                $next['confirmedAt'] = time();
                $next['confirmedAtHuman'] = gmdate('Y-m-d H:i:s', (int) $next['confirmedAt']) . ' UTC';
            }
        }

        $meaningful_change = $force || $configuration_changed || $next !== $current;
        if ($meaningful_change) {
            update_option(self::get_litespeed_detection_option_name(), $next, false);
        }

        return $next;
    }

    private static function get_litespeed_http_timeout()
    {
        return function_exists('ultracache_get_php_max_execution_time_seconds')
            ? ultracache_get_php_max_execution_time_seconds()
            : max(0, (int) ini_get('max_execution_time'));
    }

    private static function observe_litespeed_http_response($response, $source = 'http-response', $force = false)
    {
        $probe = $force ? array(
            'success' => !is_wp_error($response),
            'httpCode' => is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response),
            'errorCode' => is_wp_error($response) ? (string) $response->get_error_code() : '',
            'message' => is_wp_error($response) ? (string) $response->get_error_message() : '',
            'time' => time(),
        ) : array();

        if (is_wp_error($response)) {
            return self::persist_litespeed_detection_evidence('', '', '', $source, $probe, $force);
        }

        return self::persist_litespeed_detection_evidence(
            (string) wp_remote_retrieve_header($response, 'server'),
            (string) wp_remote_retrieve_header($response, 'x-litespeed-cache'),
            (string) wp_remote_retrieve_header($response, 'x-qc-cache'),
            $source,
            $probe,
            $force
        );
    }

    private static function probe_litespeed_origin()
    {
        if (self::$litespeed_origin_probe_active) {
            return array(
                'success' => false,
                'detected' => false,
                'httpCode' => 0,
                'errorCode' => 'recursive_probe',
                'message' => 'LiteSpeed origin probe recursion was prevented.',
            );
        }

        $requested_url = esc_url_raw(home_url('/'));
        $resolution = function_exists('ultracache_resolve_anonymous_frontend_url')
            ? ultracache_resolve_anonymous_frontend_url($requested_url, 'litespeed_origin')
            : array(
                'success' => '' !== $requested_url,
                'requestedUrl' => $requested_url,
                'resolvedUrl' => $requested_url,
                'httpCode' => 200,
            );
        if (is_wp_error($resolution)) {
            return array(
                'success' => false,
                'detected' => false,
                'httpCode' => 0,
                'errorCode' => sanitize_key((string) $resolution->get_error_code()),
                'message' => sanitize_text_field((string) $resolution->get_error_message()),
                'requestedUrl' => $requested_url,
                'resolvedUrl' => '',
                'requestedLanguage' => function_exists('ultracache_multilingual_get_public_url_language') ? ultracache_multilingual_get_public_url_language($requested_url) : '',
                'resolvedLanguage' => '',
            );
        }

        $url = esc_url_raw((string) ($resolution['resolvedUrl'] ?? ''));
        if (empty($resolution['success']) || '' === $url || !function_exists('ultracache_is_strict_frontend_loopback_url') || !ultracache_is_strict_frontend_loopback_url($url)) {
            return array(
                'success' => false,
                'detected' => false,
                'httpCode' => max(0, (int) ($resolution['httpCode'] ?? 0)),
                'errorCode' => 'invalid_probe_url',
                'message' => 'The resolved anonymous front-page URL is not eligible for the LiteSpeed origin probe.',
                'requestedUrl' => $requested_url,
                'resolvedUrl' => $url,
                'requestedLanguage' => function_exists('ultracache_multilingual_get_public_url_language') ? ultracache_multilingual_get_public_url_language($requested_url) : '',
                'resolvedLanguage' => function_exists('ultracache_multilingual_get_public_url_language') ? ultracache_multilingual_get_public_url_language($url) : '',
            );
        }

        self::$litespeed_origin_probe_active = true;
        try {
            $args = array(
                'timeout' => self::get_litespeed_http_timeout(),
                'redirection' => 0,
                'user-agent' => 'Mozilla/5.0 (compatible; UltraCache-LiteSpeed-Origin-Probe/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : 'unknown') . '; +https://wordpress.org)',
                'headers' => array(
                    'Accept' => 'text/html,application/xhtml+xml,image/avif,image/webp,*/*;q=0.8',
                    'PageSpeed' => 'off',
                    'ModPagespeed' => 'off',
                ),
            );
            $started_at = microtime(true);
            $response = function_exists('ultracache_safe_loopback_remote_request')
                ? ultracache_safe_loopback_remote_request($url, $args, 'litespeed_origin_probe')
                : wp_safe_remote_get($url, $args);
            $duration_ms = max(0, (int) round((microtime(true) - $started_at) * 1000));
            $evidence = self::observe_litespeed_http_response($response, 'origin-probe', true);
            self::refresh_litespeed_esi_capability_from_persisted_detection('origin-probe');
            if (method_exists(__CLASS__, 'sync_litespeed_cache_rules')) {
                self::sync_litespeed_cache_rules();
            }
            $probe_time = time();

            $response_headers = array(
                'server' => is_wp_error($response) ? '' : sanitize_text_field((string) wp_remote_retrieve_header($response, 'server')),
                'cacheControl' => is_wp_error($response) ? '' : sanitize_text_field((string) wp_remote_retrieve_header($response, 'cache-control')),
                'xLiteSpeedCache' => is_wp_error($response) ? '' : sanitize_text_field((string) wp_remote_retrieve_header($response, 'x-litespeed-cache')),
                'xLiteSpeedCacheControl' => is_wp_error($response) ? '' : sanitize_text_field((string) wp_remote_retrieve_header($response, 'x-litespeed-cache-control')),
                'xLiteSpeedVary' => is_wp_error($response) ? '' : sanitize_text_field((string) wp_remote_retrieve_header($response, 'x-litespeed-vary')),
                'xQcCache' => is_wp_error($response) ? '' : sanitize_text_field((string) wp_remote_retrieve_header($response, 'x-qc-cache')),
                'ultraCache' => is_wp_error($response) ? '' : sanitize_text_field((string) wp_remote_retrieve_header($response, 'x-ultra-cache')),
                'ultraCacheReason' => is_wp_error($response) ? '' : sanitize_text_field((string) wp_remote_retrieve_header($response, 'x-ultra-cache-reason')),
                'ultraCacheSource' => is_wp_error($response) ? '' : sanitize_text_field((string) wp_remote_retrieve_header($response, 'x-ultra-cache-source')),
                'ultraCacheVariant' => is_wp_error($response) ? '' : sanitize_text_field((string) wp_remote_retrieve_header($response, 'x-ultra-cache-variant')),
            );

            return array(
                'success' => !is_wp_error($response),
                'detected' => !empty($evidence['serverDetected']) || !empty($evidence['liteSpeedCacheHeaderDetected']),
                'httpCode' => is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response),
                'errorCode' => is_wp_error($response) ? sanitize_key((string) $response->get_error_code()) : '',
                'message' => is_wp_error($response) ? sanitize_text_field((string) $response->get_error_message()) : '',
                'durationMs' => $duration_ms,
                'time' => $probe_time,
                'timeHuman' => gmdate('Y-m-d H:i:s', $probe_time) . ' UTC',
                'server' => $response_headers['server'],
                'xLiteSpeedCache' => $response_headers['xLiteSpeedCache'],
                'xQcCache' => $response_headers['xQcCache'],
                'requestedUrl' => $requested_url,
                'resolvedUrl' => $url,
                'requestedLanguage' => function_exists('ultracache_multilingual_get_public_url_language') ? ultracache_multilingual_get_public_url_language($requested_url) : '',
                'resolvedLanguage' => function_exists('ultracache_multilingual_get_public_url_language') ? ultracache_multilingual_get_public_url_language($url) : '',
                'redirected' => !empty($resolution['redirected']),
                'redirectCount' => max(0, (int) ($resolution['redirectCount'] ?? 0)),
                'request' => array(
                    'method' => 'GET',
                    'url' => $url,
                    'userAgent' => sanitize_text_field((string) $args['user-agent']),
                    'headers' => array(
                        'accept' => sanitize_text_field((string) $args['headers']['Accept']),
                        'pageSpeed' => sanitize_text_field((string) $args['headers']['PageSpeed']),
                        'modPagespeed' => sanitize_text_field((string) $args['headers']['ModPagespeed']),
                    ),
                ),
                'responseHeaders' => $response_headers,
            );
        } finally {
            self::$litespeed_origin_probe_active = false;
        }
    }

    private static function get_confirmed_litespeed_transport_status($allow_probe = false)
    {
        $server_software = isset($_SERVER['SERVER_SOFTWARE'])
            ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))
            : '';
        $reverse_proxy = method_exists(__CLASS__, 'get_reverse_proxy_status') ? self::get_reverse_proxy_status() : array();
        $status = self::get_litespeed_transport_status($server_software, $reverse_proxy);

        if ($allow_probe
            && empty($status['serverDetected'])
            && empty($status['liteSpeedCacheHeaderDetected'])) {
            $status['originProbe'] = self::probe_litespeed_origin();
            $status = array_merge($status, self::get_litespeed_transport_status($server_software, $reverse_proxy));
        }

        return $status;
    }
    private static function get_litespeed_transport_status($server_software, $reverse_proxy)
    {
        $server_software = is_string($server_software) ? $server_software : '';
        $reverse_proxy = is_array($reverse_proxy) ? $reverse_proxy : array();

        $reverse_server = isset($reverse_proxy['server']) ? (string) $reverse_proxy['server'] : '';
        $x_litespeed_cache = isset($reverse_proxy['x_litespeed_cache']) ? trim((string) $reverse_proxy['x_litespeed_cache']) : '';
        $x_qc_cache = isset($reverse_proxy['x_qc_cache']) ? trim((string) $reverse_proxy['x_qc_cache']) : '';

        $current_server_detected = false !== stripos($server_software, 'LiteSpeed')
            || false !== stripos($server_software, 'OpenLiteSpeed')
            || false !== stripos($reverse_server, 'LiteSpeed')
            || false !== stripos($reverse_server, 'OpenLiteSpeed');
        $current_litespeed_cache_header_detected = '' !== $x_litespeed_cache;
        $current_quic_cloud_cache_header_detected = '' !== $x_qc_cache;
        $current_cache_header_detected = $current_litespeed_cache_header_detected || $current_quic_cloud_cache_header_detected;

        if ($current_server_detected || $current_cache_header_detected) {
            $observed_server = '' !== trim($reverse_server) ? $reverse_server : $server_software;
            self::persist_litespeed_detection_evidence(
                $observed_server,
                $x_litespeed_cache,
                $x_qc_cache,
                'runtime-detection'
            );
        }

        $persistent = self::get_persistent_litespeed_detection_evidence();
        $persistent_server_detected = !empty($persistent['serverDetected']);
        $persistent_litespeed_cache_header_detected = !empty($persistent['liteSpeedCacheHeaderDetected']);
        $persistent_quic_cloud_cache_header_detected = !empty($persistent['quicCloudCacheHeaderDetected']);
        $persistent_cache_header_detected = !empty($persistent['cacheHeaderDetected']);

        $server_detected = $current_server_detected || $persistent_server_detected;
        $litespeed_cache_header_detected = $current_litespeed_cache_header_detected || $persistent_litespeed_cache_header_detected;
        $quic_cloud_cache_header_detected = $current_quic_cloud_cache_header_detected || $persistent_quic_cloud_cache_header_detected;
        $cache_header_detected = $current_cache_header_detected || $persistent_cache_header_detected;
        if ('' === $x_litespeed_cache && !empty($persistent['xLiteSpeedCache'])) {
            $x_litespeed_cache = (string) $persistent['xLiteSpeedCache'];
        }
        if ('' === $x_qc_cache && !empty($persistent['xQcCache'])) {
            $x_qc_cache = (string) $persistent['xQcCache'];
        }
        $observed_server = '' !== trim($reverse_server) ? $reverse_server : $server_software;
        if ('' === trim($observed_server) && !empty($persistent['server'])) {
            $observed_server = (string) $persistent['server'];
        }

        $purge_hook_registered = function_exists('has_action') && false !== has_action('litespeed_purge_all');
        $purge_tag_hook_registered = function_exists('has_action') && false !== has_action('litespeed_purge');
        $purge_url_hook_registered = function_exists('has_action') && false !== has_action('litespeed_purge_url');
        $plugin_marker_detected = defined('LSCWP_V') || defined('LITESPEED_STATIC_DIR');
        $legacy_class_detected = class_exists('LiteSpeed_Cache_API');
        $legacy_class_purge = $legacy_class_detected && method_exists('LiteSpeed_Cache_API', 'purge_all');
        $legacy_namespaced_purge = class_exists('\LiteSpeed\Purge') && method_exists('\LiteSpeed\Purge', 'purge_all');
        $legacy_function_purge = function_exists('litespeed_purge_all');

        $official_hook_available = $purge_hook_registered;
        $signed_control_configured = method_exists(__CLASS__, 'is_native_litespeed_html_cache_enabled')
            && self::is_native_litespeed_html_cache_enabled();
        $native_header_available = $litespeed_cache_header_detected
            || ($quic_cloud_cache_header_detected && $server_detected);
        $signed_control_available = $signed_control_configured
            && ($server_detected || $native_header_available);
        $plugin_detected = $plugin_marker_detected
            || $official_hook_available
            || $purge_tag_hook_registered
            || $purge_url_hook_registered
            || $legacy_class_detected
            || $legacy_namespaced_purge
            || $legacy_function_purge;

        // `detected` means a LiteSpeed/OpenLiteSpeed origin was observed. A
        // WordPress plugin marker or QUIC.cloud header alone is integration
        // metadata, not proof that the origin web server is LiteSpeed.
        $detected = $server_detected || $litespeed_cache_header_detected;
        $enabled = $signed_control_available
            || $cache_header_detected
            || $official_hook_available
            || $legacy_class_purge
            || $legacy_namespaced_purge
            || $legacy_function_purge;
        $flushable = $signed_control_available
            || $official_hook_available
            || $native_header_available
            || $legacy_class_purge
            || $legacy_namespaced_purge
            || $legacy_function_purge;

        if ($signed_control_available) {
            $transport = 'signed_internal_control';
            $method = 'signed REST control response';
        } elseif ($official_hook_available) {
            $transport = 'official_wordpress_hook';
            $method = 'do_action(litespeed_purge_all)';
        } elseif ($native_header_available) {
            $transport = 'native_response_header';
            $method = 'X-LiteSpeed-Purge response header';
        } elseif ($legacy_class_purge) {
            $transport = 'legacy_class_api';
            $method = 'LiteSpeed_Cache_API::purge_all';
        } elseif ($legacy_namespaced_purge) {
            $transport = 'legacy_namespaced_api';
            $method = '\LiteSpeed\Purge::purge_all';
        } elseif ($legacy_function_purge) {
            $transport = 'legacy_function_api';
            $method = 'litespeed_purge_all';
        } else {
            $transport = 'unavailable';
            $method = 'not_available';
        }

        if ($signed_control_available) {
            $message = __('Native LiteSpeed HTML Cache is enabled on a confirmed LiteSpeed origin. The signed internal purge control is available.', 'ultracache');
        } elseif ($official_hook_available) {
            $message = __('LiteSpeed Cache WordPress purge integration detected.', 'ultracache');
        } elseif ($native_header_available) {
            $message = __('An active LiteSpeed cache response header was observed. Native LiteSpeed purge headers are available.', 'ultracache');
        } elseif ($quic_cloud_cache_header_detected) {
            $message = __('A QUIC.cloud cache response header was observed, but a LiteSpeed origin purge transport or WordPress purge hook has not been confirmed.', 'ultracache');
        } elseif ($legacy_class_purge || $legacy_namespaced_purge || $legacy_function_purge) {
            $message = __('A legacy LiteSpeed purge API is available as a compatibility fallback.', 'ultracache');
        } elseif ($plugin_marker_detected) {
            $message = __('LiteSpeed Cache plugin markers were detected, but no registered purge hook or compatibility transport is available.', 'ultracache');
        } elseif ($server_detected) {
            $message = __('LiteSpeed/OpenLiteSpeed server detected, but active LSCache or a WordPress purge integration has not been confirmed.', 'ultracache');
        } elseif ($detected) {
            $message = __('LiteSpeed/OpenLiteSpeed origin detected. No supported purge transport is currently available.', 'ultracache');
        } elseif ($plugin_detected || $quic_cloud_cache_header_detected) {
            $message = __('LiteSpeed-related WordPress/CDN integration metadata was detected, but the origin web server has not been confirmed as LiteSpeed/OpenLiteSpeed.', 'ultracache');
        } else {
            $message = __('LiteSpeed/OpenLiteSpeed origin was not detected.', 'ultracache');
        }

        return array(
            'detected' => (bool) $detected,
            'enabled' => (bool) $enabled,
            'flushable' => (bool) $flushable,
            'method' => $method,
            'transport' => $transport,
            'server' => sanitize_text_field((string) $observed_server),
            'serverDetected' => (bool) $server_detected,
            'cacheHeaderDetected' => (bool) $cache_header_detected,
            'liteSpeedCacheHeaderDetected' => (bool) $litespeed_cache_header_detected,
            'quicCloudCacheHeaderDetected' => (bool) $quic_cloud_cache_header_detected,
            'pluginDetected' => (bool) $plugin_detected,
            'officialHookAvailable' => (bool) $official_hook_available,
            'officialTagHookAvailable' => (bool) $purge_tag_hook_registered,
            'officialUrlHookAvailable' => (bool) $purge_url_hook_registered,
            'nativeHeaderAvailable' => (bool) $native_header_available,
            'signedControlConfigured' => (bool) $signed_control_configured,
            'signedControlAvailable' => (bool) $signed_control_available,
            'legacyFallbackAvailable' => (bool) ($legacy_class_purge || $legacy_namespaced_purge || $legacy_function_purge),
            'persistentEvidence' => array(
                'available' => !empty($persistent['detected']),
                'serverDetected' => $persistent_server_detected,
                'cacheHeaderDetected' => $persistent_cache_header_detected,
                'source' => sanitize_key((string) ($persistent['source'] ?? '')),
                'confirmedAt' => max(0, (int) ($persistent['confirmedAt'] ?? 0)),
                'confirmedAtHuman' => (string) ($persistent['confirmedAtHuman'] ?? ''),
                'fingerprint' => (string) ($persistent['fingerprint'] ?? ''),
                'lastProbe' => is_array($persistent['lastProbe'] ?? null) ? $persistent['lastProbe'] : array(),
            ),
            'xLiteSpeedCache' => $x_litespeed_cache,
            'xQcCache' => $x_qc_cache,
            'message' => $message,
        );
    }

    private static function send_litespeed_purge_header($value = '*')
    {
        $value = is_string($value) ? trim($value) : '*';
        if ('' === $value) {
            $value = '*';
        }

        if ('*' !== $value && !preg_match('/^(?:url|tag|private|public)=[A-Za-z0-9_:\/.,?&=%+~#@!$;*()\[\]\-]+$/', $value)) {
            return array(
                'success' => false,
                'message' => __('Invalid LiteSpeed purge header value.', 'ultracache'),
                'method' => 'X-LiteSpeed-Purge response header',
                'transport' => 'native_response_header',
            );
        }

        if (PHP_SAPI === 'cli') {
            return array(
                'success' => false,
                'message' => __('LiteSpeed native purge headers require an HTTP response and cannot be sent from WP-CLI.', 'ultracache'),
                'method' => 'X-LiteSpeed-Purge response header',
                'transport' => 'native_response_header',
            );
        }

        if (headers_sent($file, $line)) {
            return array(
                'success' => false,
                'message' => sprintf(
                    /* translators: 1: PHP file path where headers were sent, 2: line number. */
                    __('LiteSpeed purge header could not be sent because headers were already sent at %1$s:%2$s.', 'ultracache'),
                    (string) $file,
                    (string) $line
                ),
                'method' => 'X-LiteSpeed-Purge response header',
                'transport' => 'native_response_header',
            );
        }

        header('X-LiteSpeed-Purge: ' . $value, false);
        header('X-UltraCache-LiteSpeed-Purge: requested', false);

        return array(
            'success' => true,
            'message' => __('LiteSpeed native purge header queued on this HTTP response.', 'ultracache'),
            'method' => 'X-LiteSpeed-Purge response header',
            'transport' => 'native_response_header',
        );
    }

    private static function dispatch_litespeed_purge_all($status)
    {
        $status = is_array($status) ? $status : array();

        if (!empty($status['officialHookAvailable'])) {
            try {
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Official LiteSpeed Cache interoperability hook.
                do_action('litespeed_purge_all');

                return array(
                    'success' => true,
                    'message' => __('LiteSpeed Cache purge request dispatched through the official WordPress hook.', 'ultracache'),
                    'method' => 'do_action(litespeed_purge_all)',
                    'transport' => 'official_wordpress_hook',
                );
            } catch (Throwable $throwable) {
                return array(
                    'success' => false,
                    'message' => sprintf(
                        /* translators: %s: purge error message. */
                        __('LiteSpeed Cache purge hook failed: %s', 'ultracache'),
                        $throwable->getMessage()
                    ),
                    'method' => 'do_action(litespeed_purge_all)',
                    'transport' => 'official_wordpress_hook',
                );
            }
        }

        if (!empty($status['nativeHeaderAvailable'])) {
            return self::send_litespeed_purge_header('*');
        }

        if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) {
            try {
                $result = @LiteSpeed_Cache_API::purge_all();
                $success = false !== $result;

                return array(
                    'success' => $success,
                    'message' => $success
                        ? __('LiteSpeed Cache purge request dispatched through the legacy class API.', 'ultracache')
                        : __('The legacy LiteSpeed class API rejected the purge request.', 'ultracache'),
                    'method' => 'LiteSpeed_Cache_API::purge_all',
                    'transport' => 'legacy_class_api',
                );
            } catch (Throwable $throwable) {
                return array(
                    'success' => false,
                    'message' => $throwable->getMessage(),
                    'method' => 'LiteSpeed_Cache_API::purge_all',
                    'transport' => 'legacy_class_api',
                );
            }
        }

        if (class_exists('\LiteSpeed\Purge') && method_exists('\LiteSpeed\Purge', 'purge_all')) {
            try {
                $result = @call_user_func(array('\LiteSpeed\Purge', 'purge_all'));
                $success = false !== $result;

                return array(
                    'success' => $success,
                    'message' => $success
                        ? __('LiteSpeed Cache purge request dispatched through the legacy namespaced API.', 'ultracache')
                        : __('The legacy LiteSpeed namespaced API rejected the purge request.', 'ultracache'),
                    'method' => '\LiteSpeed\Purge::purge_all',
                    'transport' => 'legacy_namespaced_api',
                );
            } catch (Throwable $throwable) {
                return array(
                    'success' => false,
                    'message' => $throwable->getMessage(),
                    'method' => '\LiteSpeed\Purge::purge_all',
                    'transport' => 'legacy_namespaced_api',
                );
            }
        }

        if (function_exists('litespeed_purge_all')) {
            try {
                $result = @litespeed_purge_all();
                $success = false !== $result;

                return array(
                    'success' => $success,
                    'message' => $success
                        ? __('LiteSpeed Cache purge request dispatched through the legacy function API.', 'ultracache')
                        : __('The legacy LiteSpeed function API rejected the purge request.', 'ultracache'),
                    'method' => 'litespeed_purge_all',
                    'transport' => 'legacy_function_api',
                );
            } catch (Throwable $throwable) {
                return array(
                    'success' => false,
                    'message' => $throwable->getMessage(),
                    'method' => 'litespeed_purge_all',
                    'transport' => 'legacy_function_api',
                );
            }
        }

        return array(
            'success' => false,
            'message' => __('LiteSpeed Cache purge is not available.', 'ultracache'),
            'method' => 'not_available',
            'transport' => 'unavailable',
        );
    }
}
