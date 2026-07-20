<?php
/**
 * Varnish HTML-only flush capability test for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Flush_Scope_Test_Trait
{
    /**
     * Return a small local static asset used to classify the public static route.
     *
     * @return string
     */
    private static function get_varnish_behavior_static_probe_url()
    {
        return ultracache_plugin_url('assets/diagnostics/avif-self-test-16x16.png');
    }

    /**
     * Classify the visible public route used by the static probe.
     *
     * This intentionally describes only observable evidence. A frontend proxy
     * may bypass Varnish for static files, or it may hide Varnish headers.
     *
     * @param array $first  First static response.
     * @param array $second Second static response.
     * @return array
     */
    private static function classify_varnish_static_probe_route(array $first, array $second)
    {
        if (empty($first['success']) || empty($second['success'])) {
            return array(
                'status' => 'request-error',
                'varnishObserved' => false,
                'cacheHitVerified' => false,
                'preservationRequired' => false,
                'message' => self::maybe_translate('The public static probe requests did not complete.'),
            );
        }

        $first_detected = !empty($first['varnishDetected']);
        $second_detected = !empty($second['varnishDetected']);
        $first_status = strtoupper((string) ($first['status'] ?? 'INCONCLUSIVE'));
        $second_status = strtoupper((string) ($second['status'] ?? 'INCONCLUSIVE'));

        if ($first_detected || $second_detected) {
            if ('HIT' === $second_status) {
                return array(
                    'status' => 'through-varnish',
                    'varnishObserved' => true,
                    'cacheHitVerified' => true,
                    'preservationRequired' => true,
                    'message' => self::maybe_translate('The public static probe is cached through Varnish.'),
                );
            }

            if ('BYPASS' === $first_status || 'BYPASS' === $second_status) {
                return array(
                    'status' => 'varnish-bypass',
                    'varnishObserved' => true,
                    'cacheHitVerified' => false,
                    'preservationRequired' => false,
                    'message' => self::maybe_translate('Varnish is visible on the static route, but the static probe is explicitly bypassed.'),
                );
            }

            return array(
                'status' => 'varnish-unverified',
                'varnishObserved' => true,
                'cacheHitVerified' => false,
                'preservationRequired' => false,
                'message' => self::maybe_translate('Varnish is visible on the static route, but a static cache HIT could not be verified.'),
            );
        }

        return array(
            'status' => 'outside-or-unobservable',
            'varnishObserved' => false,
            'cacheHitVerified' => false,
            'preservationRequired' => false,
            'message' => self::maybe_translate('The public static route did not expose Varnish signals. Static files may bypass Varnish or an upstream layer may hide those headers.'),
        );
    }

    /**
     * Verify HTML-only host flushing while treating static delivery as a
     * separate topology capability rather than assuming every server sends
     * public static files through Varnish.
     *
     * @param string $page_url Front-page URL.
     * @param int    $timeout  Request timeout.
     * @return array
     */
    private static function run_varnish_html_flush_scope_test($page_url, $timeout)
    {
        $asset_url = self::get_varnish_behavior_static_probe_url();
        $steps = array();
        $invalidation = array('success' => false, 'details' => array());
        $settings = self::get_varnish_cli_settings();
        $mode = sanitize_key((string) ($settings['mode'] ?? 'http'));

        if (!ultracache_is_trusted_loopback_url($asset_url)) {
            $capability = array(
                'supported' => false,
                'manualSupported' => false,
                'htmlInvalidationVerified' => false,
                'staticRoute' => 'blocked',
                'staticPreservation' => 'not-tested',
                'status' => 'blocked-static-probe',
                'message' => self::maybe_translate('The local static probe URL was blocked by the UltraCache loopback policy.'),
                'testedAt' => time(),
            );
            self::set_varnish_html_flush_capability($capability);
            return array('capability' => $capability, 'steps' => $steps, 'invalidation' => $invalidation);
        }

        $asset_accept = 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8';
        $steps['assetFirst'] = self::run_varnish_behavior_request($asset_url, 'html_scope_asset_first', $timeout, $asset_accept);
        if (!empty($steps['assetFirst']['success'])) {
            $steps['assetSecond'] = self::run_varnish_behavior_request($asset_url, 'html_scope_asset_second', $timeout, $asset_accept);
        }

        $static_route = self::classify_varnish_static_probe_route(
            is_array($steps['assetFirst'] ?? null) ? $steps['assetFirst'] : array(),
            is_array($steps['assetSecond'] ?? null) ? $steps['assetSecond'] : array()
        );

        $invalidation = self::varnish_flush_html_current_host_for_test();
        if (empty($invalidation['success'])) {
            $capability = array(
                'supported' => false,
                'manualSupported' => false,
                'htmlInvalidationVerified' => false,
                'staticRoute' => sanitize_key((string) ($static_route['status'] ?? 'inconclusive')),
                'staticPreservation' => 'not-tested',
                'status' => 'html-invalidation-failed',
                'message' => self::maybe_translate('The configured Varnish endpoint rejected or failed the HTML-only flush expression.'),
                'staticRouteMessage' => (string) ($static_route['message'] ?? ''),
                'testedAt' => time(),
            );
            self::set_varnish_html_flush_capability($capability);
            return array('capability' => $capability, 'steps' => $steps, 'invalidation' => $invalidation);
        }

        $steps['pageAfterHtmlFlush'] = self::run_varnish_behavior_request($page_url, 'page_after_html_scope', $timeout);
        $steps['assetAfterHtmlFlush'] = self::run_varnish_behavior_request($asset_url, 'asset_after_html_scope', $timeout, $asset_accept);
        if (!empty($steps['pageAfterHtmlFlush']['success'])) {
            $steps['pageHtmlFlushVerification'] = self::run_varnish_behavior_request($page_url, 'page_html_scope_verification', $timeout);
        }

        $page_after_status = strtoupper((string) ($steps['pageAfterHtmlFlush']['status'] ?? ''));
        $asset_after_status = strtoupper((string) ($steps['assetAfterHtmlFlush']['status'] ?? ''));
        $page_verification_status = strtoupper((string) ($steps['pageHtmlFlushVerification']['status'] ?? ''));
        $page_invalidated = in_array($page_after_status, array('MISS', 'STALE'), true);
        $page_refilled = 'HIT' === $page_verification_status;
        $html_verified = $page_invalidated && $page_refilled;
        $static_route_status = sanitize_key((string) ($static_route['status'] ?? 'inconclusive'));
        $static_hit_required = !empty($static_route['preservationRequired']);
        $asset_preserved = 'HIT' === $asset_after_status;
        $static_preservation = 'not-required';

        if ($static_hit_required) {
            $static_preservation = $asset_preserved ? 'verified' : 'failed';
        } elseif ('request-error' === $static_route_status) {
            $static_preservation = 'not-tested';
        }

        $manual_supported = $html_verified && (!$static_hit_required || $asset_preserved);
        $automatic_supported = false;
        if ($manual_supported) {
            if ('through-varnish' === $static_route_status && $asset_preserved) {
                $automatic_supported = true;
            } elseif ('varnish-bypass' === $static_route_status) {
                $automatic_supported = true;
            } elseif ('outside-or-unobservable' === $static_route_status && 'admin' === $mode) {
                /*
                 * Admin mode sends the exact obj.http.Content-Type BAN expression
                 * directly to Varnish. The HTML sequence proves that expression
                 * works; a public static route without Varnish signals does not
                 * need a Varnish preservation HIT before Automatic can use it.
                 */
                $automatic_supported = true;
            }
        }

        if (!$page_invalidated) {
            $status = 'html-not-invalidated';
            $message = self::maybe_translate('The HTML-only expression was accepted, but the cached front page was not observably invalidated.');
        } elseif (!$page_refilled) {
            $status = 'refill-inconclusive';
            $message = self::maybe_translate('The HTML object appeared invalidated, but the final HTML HIT could not be verified.');
        } elseif ($static_hit_required && !$asset_preserved) {
            $status = 'broader-than-html';
            $message = self::maybe_translate('The HTML-only operation also invalidated the verified Varnish static object, so UltraCache will not use this scope.');
        } elseif ($automatic_supported && 'through-varnish' === $static_route_status) {
            $status = 'verified-static-preserved';
            $message = self::maybe_translate('HTML-only Varnish flushing was verified and the cached static probe remained a HIT.');
        } elseif ($automatic_supported && 'varnish-bypass' === $static_route_status) {
            $status = 'verified-static-bypass';
            $message = self::maybe_translate('HTML-only Varnish flushing was verified. The public static probe is explicitly bypassed by Varnish, so static preservation testing is not required.');
        } elseif ($automatic_supported && 'outside-or-unobservable' === $static_route_status) {
            $status = 'verified-static-route-unobserved';
            $message = self::maybe_translate('HTML-only Varnish flushing was verified through the admin BAN interface. The public static route does not expose Varnish signals, so static preservation testing is not required for Automatic mode.');
        } elseif ($manual_supported) {
            $status = 'html-verified-static-unobservable';
            $message = self::maybe_translate('HTML-only invalidation was verified, but the public static route could not be verified. Manual HTML-only flushing is available; Automatic continues to use the entire-host scope.');
        } else {
            $status = 'inconclusive';
            $message = self::maybe_translate('The HTML-only flush test completed without enough evidence to enable it.');
        }

        $capability = array(
            'supported' => $automatic_supported,
            'manualSupported' => $manual_supported,
            'htmlInvalidationVerified' => $html_verified,
            'staticRoute' => $static_route_status,
            'staticPreservation' => $static_preservation,
            'status' => $status,
            'message' => $message,
            'staticRouteMessage' => self::sanitize_varnish_string((string) ($static_route['message'] ?? '')),
            'testedAt' => time(),
        );
        self::set_varnish_html_flush_capability($capability);

        return array(
            'capability' => $capability,
            'steps' => $steps,
            'invalidation' => $invalidation,
        );
    }
}
