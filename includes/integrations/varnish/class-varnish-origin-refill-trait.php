<?php
/**
 * Two-stage UltraCache origin rebuild and Varnish refill helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Origin_Refill_Trait
{
    /**
     * Whether the active configuration can use strict origin revalidation.
     *
     * The contract is relevant only to HTTP soft-purge operation. Ordinary
     * BAN/PURGE refill remains a public request and does not depend on proving
     * a private origin route.
     *
     * @param array $settings Optional normalized Varnish settings.
     * @return bool
     */
    private static function is_varnish_origin_revalidation_applicable(array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }

        $mode = self::sanitize_varnish_mode((string) ($settings['mode'] ?? 'http'));
        $configured_strategy = self::sanitize_varnish_invalidation_strategy(
            (string) ($settings['invalidationStrategy'] ?? 'ban')
        );

        return 'http' === $mode && in_array($configured_strategy, array('soft', 'auto'), true);
    }

    /**
     * Return the neutral result used when strict origin proof is not part of
     * the active Varnish contract.
     *
     * @return array
     */
    private static function get_varnish_origin_revalidation_not_applicable_status()
    {
        return array(
            'applicable' => false,
            'available' => false,
            'status' => 'not-applicable',
            'testedAt' => 0,
            'reachedBucketCount' => 0,
            'expectedBucketCount' => 0,
            'fallbackUsed' => false,
            'message' => self::maybe_translate('Strict origin revalidation is used only by HTTP soft purge and stale/grace refresh. Ordinary BAN/PURGE refill uses the public cache route.'),
        );
    }

    /**
     * Persist the latest empirical two-stage refill capability.
     *
     * @param array $status Capability result.
     * @return void
     */
    private static function set_varnish_two_stage_refill_status(array $status)
    {
        $status = self::bind_varnish_capability_contracts($status, array('soft-purge', 'refill'));
        $status['testedAt'] = absint($status['testedAt'] ?? time());
        $status['available'] = !empty($status['available']);
        $status['status'] = sanitize_key((string) ($status['status'] ?? 'inconclusive'));
        $status['message'] = self::sanitize_varnish_string((string) ($status['message'] ?? ''));

        if (method_exists(static::class, 'persist_varnish_capability_diagnostic')) {
            self::persist_varnish_capability_diagnostic(
                'originrevalidation',
                $status,
                array('soft-purge', 'refill')
            );
        }
    }

    /**
     * Expose the latest two-stage refill capability for dashboard diagnostics.
     *
     * @return array
     */
    public static function get_varnish_two_stage_refill_status()
    {
        if (!self::is_varnish_origin_revalidation_applicable()) {
            return self::get_varnish_origin_revalidation_not_applicable_status();
        }

        $settings = self::get_varnish_cli_settings();
        $registry = method_exists(static::class, 'get_varnish_endpoint_capability_registry_status')
            ? self::get_varnish_endpoint_capability_registry_status($settings)
            : array();
        $effective = is_array($registry['effective'] ?? null) ? $registry['effective'] : array();
        $capability_state = is_array($registry['capabilityStates']['originRevalidation'] ?? null)
            ? $registry['capabilityStates']['originRevalidation']
            : array();
        $diagnostic = method_exists(static::class, 'get_varnish_capability_diagnostic')
            ? self::get_varnish_capability_diagnostic('originrevalidation', array('soft-purge', 'refill'))
            : array();
        $available = !empty($effective['originRevalidation']);
        $status = $available ? 'verified' : sanitize_key((string) ($capability_state['state'] ?? 'not-tested'));
        if (!empty($diagnostic['configurationChanged']) && !$available) {
            $status = 'configuration-changed';
        }

        $tested_at = max(
            absint($capability_state['testedAt'] ?? 0),
            empty($diagnostic['configurationChanged']) ? absint($diagnostic['testedAt'] ?? 0) : 0
        );
        if ($available) {
            $message = self::maybe_translate('Every configured HTTP Varnish endpoint has current authenticated origin-revalidation proof.');
        } elseif (empty($diagnostic['configurationChanged']) && '' !== (string) ($diagnostic['message'] ?? '')) {
            $message = self::sanitize_varnish_string((string) $diagnostic['message']);
        } elseif ('configuration-changed' === $status) {
            $message = self::maybe_translate('The soft-purge or public-refill contract changed. Origin revalidation must be verified again.');
        } else {
            $message = self::maybe_translate('Authenticated force-refresh has not been verified for every configured HTTP Varnish endpoint.');
        }

        return array(
            'applicable' => true,
            'available' => $available,
            'status' => $status,
            'testedAt' => $tested_at,
            'proofExpiresAt' => absint($capability_state['proofExpiresAt'] ?? 0),
            'reachedBucketCount' => empty($diagnostic['configurationChanged']) ? absint($diagnostic['reachedBucketCount'] ?? 0) : 0,
            'expectedBucketCount' => empty($diagnostic['configurationChanged']) ? absint($diagnostic['expectedBucketCount'] ?? 0) : 0,
            'fallbackUsed' => empty($diagnostic['configurationChanged']) && !empty($diagnostic['fallbackUsed']),
            'message' => $message,
            'endpointRegistry' => $registry,
        );
    }

    /**
     * Assess one force-refresh warm result without guessing when headers are hidden.
     *
     * @param array $result Force-refresh warm result.
     * @return array
     */
    private static function assess_varnish_origin_refresh_result(array $result)
    {
        $success = !empty($result['success']);
        $requested = !empty($result['forceRefreshRequested']);
        $reached = absint($result['forceRefreshReachedBucketCount'] ?? 0);
        $expected = absint($result['forceRefreshExpectedBucketCount'] ?? 0);
        $all_reached = $success && $requested && $expected > 0 && $reached === $expected;
        $visible_proxy_hit = false;
        $visible_proxy_status = '';

        foreach ((array) ($result['forceRefreshDetails'] ?? array()) as $detail) {
            $headers = is_array($detail['headers'] ?? null) ? $detail['headers'] : array();
            $classification = self::classify_varnish_response(
                $headers,
                absint($detail['httpCode'] ?? 0)
            );
            $status = strtoupper((string) ($classification['status'] ?? 'INCONCLUSIVE'));
            if (in_array($status, array('HIT', 'STALE'), true)) {
                $visible_proxy_hit = true;
                $visible_proxy_status = $status;
                break;
            }
        }

        if ($all_reached) {
            return array(
                'available' => true,
                'status' => 'available',
                'testedAt' => time(),
                'reachedBucketCount' => $reached,
                'expectedBucketCount' => $expected,
                'fallbackUsed' => false,
                'message' => self::maybe_translate_sprintf(
                    'Authenticated origin refresh reached WordPress for %d active HTML variant(s) before the public Varnish refill.',
                    $reached
                ),
            );
        }

        if (!$success) {
            return array(
                'available' => false,
                'status' => 'error',
                'testedAt' => time(),
                'reachedBucketCount' => $reached,
                'expectedBucketCount' => $expected,
                'fallbackUsed' => false,
                'message' => self::sanitize_varnish_string((string) ($result['message'] ?? self::maybe_translate('Authenticated origin refresh failed before reaching the WordPress engine.'))),
            );
        }

        if ($visible_proxy_hit) {
            return array(
                'available' => false,
                'status' => 'unavailable',
                'testedAt' => time(),
                'reachedBucketCount' => $reached,
                'expectedBucketCount' => $expected,
                'fallbackUsed' => false,
                'message' => self::maybe_translate_sprintf(
                    'The authenticated force-refresh request was served as a visible Varnish %s before reaching WordPress.',
                    $visible_proxy_status
                ),
            );
        }

        return array(
            'available' => false,
            'status' => 'inconclusive',
            'testedAt' => time(),
            'reachedBucketCount' => $reached,
            'expectedBucketCount' => $expected,
            'fallbackUsed' => false,
            'message' => self::maybe_translate('The force-refresh response did not expose the WordPress origin marker.'),
        );
    }

    /**
     * Run one authenticated origin refresh through the existing warm engine.
     *
     * @param string $url Local public URL.
     * @return array
     */
    private static function perform_varnish_origin_refresh($url)
    {
        $engine = method_exists(static::class, 'get_engine_instance') ? self::get_engine_instance() : null;
        if (!$engine || !method_exists($engine, 'warm_url')) {
            return array(
                'success' => false,
                'message' => self::maybe_translate('UltraCache engine unavailable for authenticated origin refresh.'),
            );
        }

        return $engine->warm_url(
            $url,
            array(
                'force_refresh' => true,
                'ignore_runtime_bypass' => true,
                'skip_css_bundle' => true,
                'source' => 'varnish-origin-refill',
            )
        );
    }

    /**
     * Record the two-stage result already produced by a dashboard force-refresh warm.
     *
     * @param array $warm_result Manual warm result.
     * @return array
     */
    private static function record_manual_varnish_origin_refresh_result(array $warm_result)
    {
        if (!self::is_varnish_origin_revalidation_applicable()) {
            return self::get_varnish_origin_revalidation_not_applicable_status();
        }

        $status = self::assess_varnish_origin_refresh_result($warm_result);
        $status['applicable'] = true;
        $status['fallbackUsed'] = false;
        self::set_varnish_two_stage_refill_status($status);
        return $status;
    }
}
