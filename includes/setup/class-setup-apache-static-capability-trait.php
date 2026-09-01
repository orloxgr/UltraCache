<?php
/**
 * Setup-time Apache Static HTML Delivery capability verification.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Setup_Apache_Static_Capability_Trait
{
    /**
     * Return the latest persisted Apache Static capability proof.
     *
     * @return array<string,mixed>
     */
    public static function get_apache_static_html_delivery_capability_probe()
    {
        $stored = get_option('ultracache_apache_static_capability_v1', array());
        return is_array($stored) ? $stored : array();
    }

    /**
     * Verify whether the current public request path can serve an UltraCache
     * page-cache file through the existing Apache Static .htaccess rules.
     *
     * The probe never changes the persisted Apache Static setting. It installs
     * the existing rules temporarily, warms one canonical local URL through the
     * existing warm engine, performs an anonymous loopback request, records the
     * X-Ultra-Cache-Source proof, and restores the rule state that existed before
     * the probe.
     *
     * @return array<string,mixed>
     */
    public static function run_apache_static_html_delivery_capability_probe()
    {
        $settings = self::get_dashboard_settings();
        $tested_at = time();
        $server_software = isset($_SERVER['SERVER_SOFTWARE'])
            ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))
            : '';
        $previous_enabled = !empty($settings['apacheStaticHtmlDeliveryEnabled']);
        $page_cache_enabled = !empty($settings['pageCacheEnabled']);
        $stored_detection = get_option('ultracache_external_cache_detection', array());
        $stored_layers = is_array($stored_detection) && isset($stored_detection['layers']) && is_array($stored_detection['layers'])
            ? $stored_detection['layers']
            : array();
        $stored_litespeed = isset($stored_layers['litespeed']) && is_array($stored_layers['litespeed'])
            ? $stored_layers['litespeed']
            : array();
        $litespeed_detected = !empty($settings['liteSpeedCacheEnabled'])
            || !empty($stored_litespeed['detected'])
            || false !== strpos(strtolower($server_software), 'litespeed');

        if ($litespeed_detected) {
            return self::persist_apache_static_html_delivery_capability_probe(array(
                'status' => 'not_applicable',
                'verified' => false,
                'reason' => 'litespeed_detected',
                'message' => self::maybe_translate('Apache Static HTML Delivery capability testing is not applicable while LiteSpeed is detected.'),
                'testedAt' => $tested_at,
                'serverSoftware' => $server_software,
                'previouslyEnabled' => $previous_enabled,
                'pageCacheEnabled' => $page_cache_enabled,
            ));
        }

        if (!$page_cache_enabled) {
            return self::persist_apache_static_html_delivery_capability_probe(array(
                'status' => 'inconclusive',
                'verified' => false,
                'reason' => 'page_cache_disabled',
                'message' => self::maybe_translate('Apache Static HTML Delivery could not be verified because UltraCache Page Cache is disabled.'),
                'testedAt' => $tested_at,
                'serverSoftware' => $server_software,
                'previouslyEnabled' => $previous_enabled,
                'pageCacheEnabled' => false,
            ));
        }

        $engine = self::get_engine_instance();
        if (!$engine || !method_exists($engine, 'warm_url')) {
            return self::persist_apache_static_html_delivery_capability_probe(array(
                'status' => 'inconclusive',
                'verified' => false,
                'reason' => 'warm_engine_unavailable',
                'message' => self::maybe_translate('Apache Static HTML Delivery could not be verified because the cache warm engine is unavailable.'),
                'testedAt' => $tested_at,
                'serverSoftware' => $server_software,
                'previouslyEnabled' => $previous_enabled,
                'pageCacheEnabled' => true,
            ));
        }

        $requested_url = esc_url_raw(home_url('/'));
        $requested_language = function_exists('ultracache_multilingual_get_public_url_language')
            ? ultracache_multilingual_get_public_url_language($requested_url)
            : '';
        $resolution = function_exists('ultracache_resolve_anonymous_frontend_url')
            ? ultracache_resolve_anonymous_frontend_url($requested_url, 'apache_static')
            : array(
                'success' => '' !== $requested_url,
                'requestedUrl' => $requested_url,
                'resolvedUrl' => $requested_url,
                'httpCode' => 200,
                'redirected' => false,
                'redirectCount' => 0,
            );
        if (is_wp_error($resolution)) {
            return self::persist_apache_static_html_delivery_capability_probe(array(
                'status' => 'inconclusive',
                'verified' => false,
                'reason' => 'frontend_target_resolution_failed',
                'message' => self::maybe_translate('Apache Static HTML Delivery could not be verified because the anonymous frontend URL could not be resolved.') . ' ' . sanitize_text_field((string) $resolution->get_error_message()),
                'testedAt' => $tested_at,
                'serverSoftware' => $server_software,
                'requestedUrl' => $requested_url,
                'requestedLanguage' => $requested_language,
                'resolvedUrl' => '',
                'resolvedLanguage' => '',
                'resolutionError' => sanitize_key((string) $resolution->get_error_code()),
                'previouslyEnabled' => $previous_enabled,
                'pageCacheEnabled' => true,
            ));
        }

        $url = esc_url_raw((string) ($resolution['resolvedUrl'] ?? ''));
        if (empty($resolution['success']) || '' === $url || !function_exists('ultracache_is_trusted_loopback_url') || !ultracache_is_trusted_loopback_url($url)) {
            return self::persist_apache_static_html_delivery_capability_probe(array(
                'status' => 'inconclusive',
                'verified' => false,
                'reason' => 'loopback_url_unavailable',
                'message' => self::maybe_translate('Apache Static HTML Delivery could not be verified because the resolved anonymous frontend URL was unavailable.'),
                'testedAt' => $tested_at,
                'serverSoftware' => $server_software,
                'url' => $url,
                'resolvedUrl' => $url,
                'requestedUrl' => $requested_url,
                'requestedLanguage' => $requested_language,
                'resolvedLanguage' => function_exists('ultracache_multilingual_get_public_url_language') ? ultracache_multilingual_get_public_url_language($url) : '',
                'redirected' => !empty($resolution['redirected']),
                'redirectCount' => max(0, (int) ($resolution['redirectCount'] ?? 0)),
                'resolutionError' => empty($resolution['success']) ? 'frontend_target_http_status' : '',
                'previouslyEnabled' => $previous_enabled,
                'pageCacheEnabled' => true,
            ));
        }

        $rules_installed = false;
        $result = array();
        try {
            $rules_installed = true === self::sync_apache_static_html_delivery_rules(true);
            if (!$rules_installed) {
                $result = array(
                    'status' => 'unsupported',
                    'verified' => false,
                    'reason' => 'rules_not_writable',
                    'message' => self::maybe_translate('Apache Static HTML Delivery could not be enabled because its .htaccess rules could not be written and verified.'),
                );
            } else {
                $warm_result = $engine->warm_url($url, array(
                    'force_refresh' => true,
                    'skip_css_bundle' => true,
                    'buckets' => array('orig'),
                    'execution_profile' => 'ui',
                    'force_apache_static_alias' => true,
                ));
                $warm_success = is_array($warm_result) && !empty($warm_result['success']) && !empty($warm_result['cached']);

                if (!$warm_success) {
                    $result = array(
                        'status' => 'inconclusive',
                        'verified' => false,
                        'reason' => 'warm_failed',
                        'message' => is_array($warm_result) && !empty($warm_result['message'])
                            ? sanitize_text_field((string) $warm_result['message'])
                            : self::maybe_translate('Apache Static HTML Delivery could not be verified because the probe page could not be warmed.'),
                        'warm' => array(
                            'success' => false,
                            'failureClass' => is_array($warm_result) ? sanitize_key((string) ($warm_result['failureClass'] ?? '')) : '',
                        ),
                    );
                } else {
                    $varnish_invalidation = self::prepare_apache_static_probe_public_path($url);
                    if (!empty($varnish_invalidation['attempted']) && empty($varnish_invalidation['success'])) {
                        $result = array(
                            'status' => 'inconclusive',
                            'verified' => false,
                            'reason' => 'varnish_invalidation_failed',
                            'message' => !empty($varnish_invalidation['message'])
                                ? sanitize_text_field((string) $varnish_invalidation['message'])
                                : self::maybe_translate('Apache Static HTML Delivery could not be verified because the configured Varnish layer could not be invalidated before the public-path probe.'),
                            'warm' => array(
                                'success' => true,
                                'cached' => true,
                            ),
                            'varnishInvalidation' => $varnish_invalidation,
                        );
                    } else {
                        $php_max_execution = function_exists('ultracache_get_php_max_execution_time_seconds')
                            ? ultracache_get_php_max_execution_time_seconds()
                            : max(0, (int) ini_get('max_execution_time'));
                        $response = ultracache_safe_loopback_remote_request(
                            $url,
                            array(
                                'method' => 'GET',
                                'timeout' => $php_max_execution,
                                'redirection' => 0,
                                'user-agent' => 'Mozilla/5.0 (compatible; UltraCache-Apache-Static-Probe/' . ULTRACACHE_VERSION . '; +https://wordpress.org)',
                                'headers' => array(
                                    'Accept' => 'text/html,application/xhtml+xml',
                                    'Accept-Encoding' => 'identity',
                                    'Cache-Control' => 'no-cache, max-age=0',
                                    'Pragma' => 'no-cache',
                                    'PageSpeed' => 'off',
                                    'ModPagespeed' => 'off',
                                    'X-UltraCache-Apache-Static-Probe' => '1',
                                ),
                            ),
                            'setup_apache_static_capability_probe'
                        );

                        if (is_wp_error($response)) {
                            $result = array(
                                'status' => 'inconclusive',
                                'verified' => false,
                                'reason' => 'verification_request_failed',
                                'message' => sanitize_text_field($response->get_error_message()),
                                'varnishInvalidation' => $varnish_invalidation,
                            );
                        } else {
                            $http_code = (int) wp_remote_retrieve_response_code($response);
                            $source = strtolower(trim((string) wp_remote_retrieve_header($response, 'x-ultra-cache-source')));
                            $verified = 200 === $http_code && 'apache-static' === $source;
                            $varnish_observed = !$verified && self::apache_static_probe_response_looks_like_varnish($response);
                            $status = $verified
                                ? 'verified'
                                : ($varnish_observed ? 'inconclusive' : (200 === $http_code ? 'unsupported' : 'inconclusive'));
                            $reason = $verified
                                ? 'apache_static_header_verified'
                                : ($varnish_observed ? 'varnish_obscured_origin' : (200 === $http_code ? 'apache_static_header_not_observed' : 'verification_http_status'));
                            $message = $verified
                                ? self::maybe_translate('Apache Static HTML Delivery was verified through the public request path.')
                                : ($varnish_observed
                                    ? self::maybe_translate('Apache Static HTML Delivery verification was inconclusive because the public probe was answered by a Varnish layer before the Apache origin could be observed.')
                                    : (200 === $http_code
                                        ? self::maybe_translate('The public request path did not serve the warmed page through Apache Static HTML Delivery.')
                                        : self::maybe_translate('Apache Static HTML Delivery verification did not return HTTP 200.')));
                            $result = array(
                                'status' => $status,
                                'verified' => $verified,
                                'reason' => $reason,
                                'message' => $message,
                                'httpCode' => $http_code,
                                'source' => sanitize_text_field($source),
                                'warm' => array(
                                    'success' => true,
                                    'cached' => true,
                                ),
                                'varnishObserved' => $varnish_observed,
                                'varnishInvalidation' => $varnish_invalidation,
                            );
                        }
                    }
                }
            }
        } catch (Throwable $error) {
            $result = array(
                'status' => 'inconclusive',
                'verified' => false,
                'reason' => 'probe_exception',
                'message' => sanitize_text_field($error->getMessage()),
            );
        } finally {
            if ($rules_installed || !$previous_enabled) {
                self::sync_apache_static_html_delivery_rules($previous_enabled);
            }
        }

        $result['testedAt'] = $tested_at;
        $result['serverSoftware'] = $server_software;
        $result['url'] = $url;
        $result['resolvedUrl'] = $url;
        $result['requestedUrl'] = $requested_url;
        $result['requestedLanguage'] = $requested_language;
        $result['resolvedLanguage'] = function_exists('ultracache_multilingual_get_public_url_language')
            ? ultracache_multilingual_get_public_url_language($url)
            : '';
        $result['redirected'] = !empty($resolution['redirected']);
        $result['redirectCount'] = max(0, (int) ($resolution['redirectCount'] ?? 0));
        $result['previouslyEnabled'] = $previous_enabled;
        $result['pageCacheEnabled'] = true;
        $result['rulesInstalled'] = $rules_installed;

        return self::persist_apache_static_html_delivery_capability_probe($result);
    }

    /**
     * Invalidate an already-configured Varnish object before the Apache public
     * path probe. Client no-cache headers are not a reliable bypass contract:
     * Varnish configurations may legitimately continue to return a fresh HIT.
     *
     * @param string $url Canonical local probe URL.
     * @return array<string,mixed>
     */
    private static function prepare_apache_static_probe_public_path($url)
    {
        $dashboard_settings = self::get_dashboard_settings();
        $runtime_enabled = method_exists(static::class, 'is_varnish_runtime_enabled')
            && self::is_varnish_runtime_enabled($dashboard_settings);

        if (!$runtime_enabled) {
            return array(
                'attempted' => false,
                'success' => true,
                'message' => '',
            );
        }

        if (!method_exists(static::class, 'varnish_flush_url')) {
            return array(
                'attempted' => true,
                'success' => false,
                'message' => self::maybe_translate('The configured Varnish layer could not be invalidated before Apache Static HTML Delivery verification.'),
            );
        }

        $flush = self::varnish_flush_url($url);
        $success = is_array($flush) && !empty($flush['success']);

        return array(
            'attempted' => true,
            'success' => $success,
            'message' => $success
                ? self::maybe_translate('The configured Varnish object was invalidated before Apache Static HTML Delivery verification.')
                : sanitize_text_field((string) (is_array($flush) ? ($flush['message'] ?? '') : '')),
            'requestCount' => is_array($flush) ? max(0, (int) ($flush['requestCount'] ?? 0)) : 0,
        );
    }

    /**
     * Detect whether an unsuccessful Apache-origin proof was answered by a
     * Varnish layer. Such a response is inconclusive, never proof that Apache
     * Static HTML Delivery is unsupported.
     *
     * @param mixed $response WordPress HTTP API response.
     * @return bool
     */
    private static function apache_static_probe_response_looks_like_varnish($response)
    {
        if (!is_array($response)) {
            return false;
        }

        $via = strtolower(trim((string) wp_remote_retrieve_header($response, 'via')));
        $x_varnish = trim((string) wp_remote_retrieve_header($response, 'x-varnish'));

        return '' !== $x_varnish || false !== strpos($via, 'varnish');
    }

    /**
     * @param array<string,mixed> $result Probe result.
     * @return array<string,mixed>
     */
    private static function persist_apache_static_html_delivery_capability_probe(array $result)
    {
        $status = sanitize_key((string) ($result['status'] ?? 'inconclusive'));
        if (!in_array($status, array('verified', 'unsupported', 'inconclusive', 'not_applicable'), true)) {
            $status = 'inconclusive';
        }
        $result['status'] = $status;
        $result['verified'] = 'verified' === $status && !empty($result['verified']);
        $result['reason'] = sanitize_key((string) ($result['reason'] ?? ''));
        $result['message'] = sanitize_text_field((string) ($result['message'] ?? ''));
        $result['testedAt'] = absint($result['testedAt'] ?? time());
        $result['serverSoftware'] = sanitize_text_field((string) ($result['serverSoftware'] ?? ''));
        $result['url'] = isset($result['url']) ? esc_url_raw((string) $result['url']) : '';
        $result['resolvedUrl'] = isset($result['resolvedUrl']) ? esc_url_raw((string) $result['resolvedUrl']) : $result['url'];
        $result['requestedUrl'] = isset($result['requestedUrl']) ? esc_url_raw((string) $result['requestedUrl']) : '';
        $result['requestedLanguage'] = function_exists('ultracache_multilingual_normalize_language_code')
            ? ultracache_multilingual_normalize_language_code($result['requestedLanguage'] ?? '')
            : sanitize_key((string) ($result['requestedLanguage'] ?? ''));
        $result['resolvedLanguage'] = function_exists('ultracache_multilingual_normalize_language_code')
            ? ultracache_multilingual_normalize_language_code($result['resolvedLanguage'] ?? '')
            : sanitize_key((string) ($result['resolvedLanguage'] ?? ''));
        $result['redirected'] = !empty($result['redirected']);
        $result['redirectCount'] = max(0, (int) ($result['redirectCount'] ?? 0));
        $result['resolutionError'] = sanitize_key((string) ($result['resolutionError'] ?? ''));
        $result['previouslyEnabled'] = !empty($result['previouslyEnabled']);
        $result['pageCacheEnabled'] = !empty($result['pageCacheEnabled']);
        $result['rulesInstalled'] = !empty($result['rulesInstalled']);
        if (isset($result['httpCode'])) {
            $result['httpCode'] = absint($result['httpCode']);
        }
        if (isset($result['source'])) {
            $result['source'] = sanitize_text_field((string) $result['source']);
        }
        $result['varnishObserved'] = !empty($result['varnishObserved']);
        if (isset($result['varnishInvalidation']) && is_array($result['varnishInvalidation'])) {
            $result['varnishInvalidation'] = array(
                'attempted' => !empty($result['varnishInvalidation']['attempted']),
                'success' => !empty($result['varnishInvalidation']['success']),
                'message' => sanitize_text_field((string) ($result['varnishInvalidation']['message'] ?? '')),
                'requestCount' => max(0, (int) ($result['varnishInvalidation']['requestCount'] ?? 0)),
            );
        }
        if (isset($result['warm']) && is_array($result['warm'])) {
            $result['warm'] = array(
                'success' => !empty($result['warm']['success']),
                'cached' => !empty($result['warm']['cached']),
                'failureClass' => sanitize_key((string) ($result['warm']['failureClass'] ?? '')),
            );
        }

        update_option('ultracache_apache_static_capability_v1', $result, false);
        return $result;
    }
}
