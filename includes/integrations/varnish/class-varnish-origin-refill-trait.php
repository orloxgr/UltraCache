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
     * Return a configuration signature without storing endpoint secrets.
     *
     * @return string
     */
    private static function get_varnish_two_stage_refill_configuration_signature()
    {
        if (method_exists(static::class, 'get_varnish_flush_scope_configuration_signature')) {
            return (string) self::get_varnish_flush_scope_configuration_signature();
        }

        $settings = self::get_varnish_cli_settings();
        $payload = array(
            'mode' => (string) ($settings['mode'] ?? 'http'),
            'method' => (string) ($settings['method'] ?? 'BAN'),
            'servers' => array_values(array_map('strval', is_array($settings['servers'] ?? null) ? $settings['servers'] : array())),
            'siteHost' => wp_parse_url(home_url('/'), PHP_URL_HOST),
        );

        return hash('sha256', (string) wp_json_encode($payload));
    }

    /**
     * Persist the latest empirical two-stage refill capability.
     *
     * @param array $status Capability result.
     * @return void
     */
    private static function set_varnish_two_stage_refill_status(array $status)
    {
        $status['configurationSignature'] = self::get_varnish_two_stage_refill_configuration_signature();
        $status['testedAt'] = absint($status['testedAt'] ?? time());
        $status['available'] = !empty($status['available']);
        $status['status'] = sanitize_key((string) ($status['status'] ?? 'inconclusive'));
        $status['message'] = self::sanitize_varnish_string((string) ($status['message'] ?? ''));

        set_transient(
            'ultracache_varnish_two_stage_refill_v1',
            self::sanitize_varnish_result($status),
            WEEK_IN_SECONDS
        );
    }

    /**
     * Expose the latest two-stage refill capability for dashboard diagnostics.
     *
     * @return array
     */
    public static function get_varnish_two_stage_refill_status()
    {
        $value = get_transient('ultracache_varnish_two_stage_refill_v1');
        if (!is_array($value)) {
            return array(
                'available' => false,
                'status' => 'untested',
                'testedAt' => 0,
                'message' => self::maybe_translate('Run a manual warm-up or allow an affected-page refill to test whether authenticated force-refresh requests reach the WordPress origin before Varnish is populated.'),
            );
        }

        $current_signature = self::get_varnish_two_stage_refill_configuration_signature();
        if (empty($value['configurationSignature']) || !hash_equals((string) $value['configurationSignature'], $current_signature)) {
            return array(
                'available' => false,
                'status' => 'configuration-changed',
                'testedAt' => 0,
                'message' => self::maybe_translate('The Varnish endpoint configuration changed. Two-stage refill will be tested again during the next warm or refill operation.'),
            );
        }

        return array(
            'available' => !empty($value['available']),
            'status' => sanitize_key((string) ($value['status'] ?? 'inconclusive')),
            'testedAt' => absint($value['testedAt'] ?? 0),
            'reachedBucketCount' => absint($value['reachedBucketCount'] ?? 0),
            'expectedBucketCount' => absint($value['expectedBucketCount'] ?? 0),
            'fallbackUsed' => !empty($value['fallbackUsed']),
            'message' => self::sanitize_varnish_string((string) ($value['message'] ?? '')),
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
            $classification = self::classify_varnish_behavior_response(
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
                'fallbackUsed' => true,
                'message' => self::sanitize_varnish_string((string) ($result['message'] ?? self::maybe_translate('Authenticated origin refresh failed; UltraCache will use the ordinary one-stage warm fallback.'))),
            );
        }

        if ($visible_proxy_hit) {
            return array(
                'available' => false,
                'status' => 'unavailable',
                'testedAt' => time(),
                'reachedBucketCount' => $reached,
                'expectedBucketCount' => $expected,
                'fallbackUsed' => true,
                'message' => self::maybe_translate_sprintf(
                    'The authenticated force-refresh request was served as a visible Varnish %s before reaching WordPress, so UltraCache used the one-stage fallback.',
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
            'fallbackUsed' => true,
            'message' => self::maybe_translate('The force-refresh response did not expose the WordPress origin marker. UltraCache used the one-stage fallback rather than claiming two-stage refill support.'),
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
                'time_budget' => 20,
                'source' => 'varnish-origin-refill',
            )
        );
    }

    /**
     * Prepare the inner cache before sending the normal public Varnish refill.
     *
     * @param string $url Local public URL.
     * @return array
     */
    private static function prepare_varnish_inner_cache_for_refill($url)
    {
        $origin_result = self::perform_varnish_origin_refresh($url);
        $two_stage = self::assess_varnish_origin_refresh_result(is_array($origin_result) ? $origin_result : array());
        self::set_varnish_two_stage_refill_status($two_stage);

        if (!empty($two_stage['available'])) {
            return array(
                'success' => !empty($origin_result['success']),
                'innerCache' => $origin_result,
                'originRefresh' => $origin_result,
                'twoStageRefill' => $two_stage,
            );
        }

        $engine = method_exists(static::class, 'get_engine_instance') ? self::get_engine_instance() : null;
        $fallback_result = array(
            'success' => false,
            'message' => self::maybe_translate('UltraCache engine unavailable for one-stage warm fallback.'),
        );
        if ($engine && method_exists($engine, 'warm_url')) {
            $fallback_result = $engine->warm_url(
                $url,
                array(
                    'ignore_runtime_bypass' => true,
                    'skip_css_bundle' => true,
                    'time_budget' => 20,
                    'source' => 'varnish-refill-fallback',
                )
            );
        }

        $two_stage['fallbackUsed'] = true;
        self::set_varnish_two_stage_refill_status($two_stage);

        return array(
            'success' => !empty($fallback_result['success']),
            'innerCache' => $fallback_result,
            'originRefresh' => $origin_result,
            'twoStageRefill' => $two_stage,
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
        $status = self::assess_varnish_origin_refresh_result($warm_result);
        if (empty($status['available'])) {
            $status['fallbackUsed'] = true;
        }
        self::set_varnish_two_stage_refill_status($status);
        return $status;
    }
}
