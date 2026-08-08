<?php
/**
 * Bounded production Varnish operation and sampled ESI metrics for UltraCache.
 *
 * Diagnostic results are stored separately by the diagnostic actions layer.
 * Current queue lifecycle counts come directly from the queue table and are
 * not duplicated in this option.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Metrics_Trait
{
    /** @var array|null */
    private static $varnish_metrics_state_cache = null;

    /** @var bool */
    private static $varnish_metrics_state_dirty = false;

    /** @var bool */
    private static $varnish_metrics_shutdown_registered = false;

    /** @var array<string,bool> */
    private static $varnish_metrics_recorded_operations = array();

    private static function get_varnish_metrics_option_name()
    {
        return 'ultracache_varnish_metrics_v1';
    }

    private static function get_default_varnish_metrics_state()
    {
        return array(
            'version' => 4,
            'updatedAt' => 0,
            'windowStartedAt' => time(),
            'operations' => array(
                'invalidationOperations' => 0,
                'invalidatedUrls' => 0,
                'endpointFailures' => 0,
                'refillAttempts' => 0,
                'refillSuccesses' => 0,
                'refillFailures' => 0,
                'esiFragmentInvalidations' => 0,
                'esiFragmentInvalidationFailures' => 0,
                'esiWarmParentVariants' => 0,
                'esiWarmFragmentReferences' => 0,
            ),
            'runtimeOutcomes' => array(
                'complete' => 0,
                'partial' => 0,
                'degraded' => 0,
                'unsupported' => 0,
                'failed' => 0,
            ),
            'runtimeStrategies' => array(
                'exact-purge' => 0,
                'exact-ban' => 0,
                'admin-ban' => 0,
                'soft-purge' => 0,
                'html-flush' => 0,
                'host-flush' => 0,
                'known-url-exact-purge' => 0,
                'known-url-exact-ban' => 0,
                'known-url-admin-ban' => 0,
                'ttl-expiry' => 0,
                'other' => 0,
            ),
            'esi' => array(
                'sampleRate' => 32,
                'sampledRequests' => 0,
                'estimatedRequests' => 0,
                'sampledPublicRequests' => 0,
                'estimatedPublicRequests' => 0,
                'sampledPrivateRequests' => 0,
                'estimatedPrivateRequests' => 0,
                'renderDurationMsTotal' => 0,
                'renderDurationMsMax' => 0,
                'outputBytesTotal' => 0,
                'containedErrors' => 0,
                'woocommerceMiniCartSampledRequests' => 0,
                'woocommerceMiniCartEstimatedRequests' => 0,
                'woocommerceMiniCartRenderDurationMsTotal' => 0,
                'woocommerceMiniCartRenderDurationMsMax' => 0,
                'woocommerceMiniCartOutputBytesTotal' => 0,
                'woocommerceMiniCartContainedErrors' => 0,
            ),
            'esiHourly' => array(),
        );
    }

    private static function sanitize_varnish_metrics_hourly(array $hourly)
    {
        $sanitized = array();
        $minimum_hour = (int) floor(time() / HOUR_IN_SECONDS) - 47;
        foreach ($hourly as $key => $bucket) {
            if (!is_array($bucket) || !preg_match('/^\d{10}$/', (string) $key)) {
                continue;
            }
            $hour_start = absint($bucket['hourStart'] ?? 0);
            if ($hour_start < $minimum_hour * HOUR_IN_SECONDS) {
                continue;
            }
            $sanitized[(string) $key] = array(
                'hourStart' => $hour_start,
                'sampledRequests' => max(0, (int) ($bucket['sampledRequests'] ?? 0)),
                'estimatedRequests' => max(0, (int) ($bucket['estimatedRequests'] ?? 0)),
                'sampledPublicRequests' => max(0, (int) ($bucket['sampledPublicRequests'] ?? 0)),
                'estimatedPublicRequests' => max(0, (int) ($bucket['estimatedPublicRequests'] ?? 0)),
                'sampledPrivateRequests' => max(0, (int) ($bucket['sampledPrivateRequests'] ?? 0)),
                'estimatedPrivateRequests' => max(0, (int) ($bucket['estimatedPrivateRequests'] ?? 0)),
                'renderDurationMsTotal' => max(0, (int) ($bucket['renderDurationMsTotal'] ?? 0)),
                'renderDurationMsMax' => max(0, (int) ($bucket['renderDurationMsMax'] ?? 0)),
                'outputBytesTotal' => max(0, (int) ($bucket['outputBytesTotal'] ?? 0)),
                'containedErrors' => max(0, (int) ($bucket['containedErrors'] ?? 0)),
                'woocommerceMiniCartSampledRequests' => max(0, (int) ($bucket['woocommerceMiniCartSampledRequests'] ?? 0)),
                'woocommerceMiniCartEstimatedRequests' => max(0, (int) ($bucket['woocommerceMiniCartEstimatedRequests'] ?? 0)),
                'woocommerceMiniCartRenderDurationMsTotal' => max(0, (int) ($bucket['woocommerceMiniCartRenderDurationMsTotal'] ?? 0)),
                'woocommerceMiniCartRenderDurationMsMax' => max(0, (int) ($bucket['woocommerceMiniCartRenderDurationMsMax'] ?? 0)),
                'woocommerceMiniCartOutputBytesTotal' => max(0, (int) ($bucket['woocommerceMiniCartOutputBytesTotal'] ?? 0)),
                'woocommerceMiniCartContainedErrors' => max(0, (int) ($bucket['woocommerceMiniCartContainedErrors'] ?? 0)),
            );
        }
        ksort($sanitized, SORT_STRING);
        if (count($sanitized) > 48) {
            $sanitized = array_slice($sanitized, -48, null, true);
        }

        return $sanitized;
    }

    private static function sanitize_varnish_metrics_state($value)
    {
        $defaults = self::get_default_varnish_metrics_state();
        if (!is_array($value)) {
            return $defaults;
        }

        $state = $defaults;
        $state['updatedAt'] = max(0, (int) ($value['updatedAt'] ?? 0));
        $state['windowStartedAt'] = max(1, (int) ($value['windowStartedAt'] ?? $defaults['windowStartedAt']));

        foreach (array('operations', 'runtimeOutcomes', 'runtimeStrategies', 'esi') as $section) {
            $stored = is_array($value[$section] ?? null) ? $value[$section] : array();
            foreach ($state[$section] as $key => $default) {
                $state[$section][$key] = max(0, (int) ($stored[$key] ?? $default));
            }
        }
        $state['esi']['sampleRate'] = max(1, min(256, (int) ($state['esi']['sampleRate'] ?? 32)));
        $state['esiHourly'] = self::sanitize_varnish_metrics_hourly(
            is_array($value['esiHourly'] ?? null) ? $value['esiHourly'] : array()
        );

        return $state;
    }

    private static function get_varnish_metrics_state()
    {
        if (is_array(self::$varnish_metrics_state_cache)) {
            return self::$varnish_metrics_state_cache;
        }

        self::$varnish_metrics_state_cache = self::sanitize_varnish_metrics_state(
            get_option(self::get_varnish_metrics_option_name(), array())
        );

        return self::$varnish_metrics_state_cache;
    }

    private static function set_varnish_metrics_state(array $state)
    {
        self::$varnish_metrics_state_cache = self::sanitize_varnish_metrics_state($state);
        self::$varnish_metrics_state_cache['updatedAt'] = time();
        self::$varnish_metrics_state_dirty = true;

        if (!self::$varnish_metrics_shutdown_registered) {
            self::$varnish_metrics_shutdown_registered = true;
            register_shutdown_function(array(__CLASS__, 'flush_varnish_metrics_state'));
        }
    }

    public static function flush_varnish_metrics_state()
    {
        if (!self::$varnish_metrics_state_dirty || !is_array(self::$varnish_metrics_state_cache)) {
            return;
        }

        update_option(
            self::get_varnish_metrics_option_name(),
            self::sanitize_varnish_metrics_state(self::$varnish_metrics_state_cache),
            false
        );
        self::$varnish_metrics_state_dirty = false;
    }

    /**
     * Normalize an endpoint label used by transport responses.
     *
     * @param mixed $endpoint Endpoint identity.
     * @return string
     */
    private static function normalize_varnish_metrics_endpoint_label($endpoint)
    {
        if (is_array($endpoint)) {
            $scheme = sanitize_key((string) ($endpoint['scheme'] ?? ''));
            $host = sanitize_text_field((string) ($endpoint['host'] ?? ''));
            $port = absint($endpoint['port'] ?? 0);
            if (false !== strpos($host, ':') && '[' !== substr($host, 0, 1)) {
                $host = '[' . $host . ']';
            }
            $endpoint = (in_array($scheme, array('http', 'https'), true) ? $scheme . '://' : '') . $host . ($port > 0 ? ':' . $port : '');
        }

        $endpoint = trim(sanitize_text_field((string) $endpoint));
        return strlen($endpoint) > 190 ? substr($endpoint, 0, 190) : $endpoint;
    }

    /**
     * Record only production endpoint failures.
     *
     * @param mixed  $endpoint    Endpoint identity. Intentionally unused.
     * @param string $mode        Endpoint mode. Intentionally unused.
     * @param bool   $success     Whether the endpoint request succeeded.
     * @param int    $duration_ms Request duration. Intentionally unused.
     * @param string $detail      Request detail. Intentionally unused.
     * @return void
     */
    private static function record_varnish_endpoint_result($endpoint, $mode, $success, $duration_ms, $detail = '')
    {
        unset($endpoint, $mode, $duration_ms, $detail);
        if ($success || (method_exists(static::class, 'is_varnish_test_run_active') && self::is_varnish_test_run_active())) {
            return;
        }

        $state = self::get_varnish_metrics_state();
        $state['operations']['endpointFailures']++;
        self::set_varnish_metrics_state($state);
    }

    private static function get_varnish_operation_fingerprint(array $result)
    {
        $parts = array(
            (string) ($result['operationType'] ?? ''),
            (string) ($result['time'] ?? 0),
            (string) ($result['uniqueUrlCount'] ?? 0),
            (string) ($result['fullyInvalidatedUrlCount'] ?? 0),
            (string) ($result['refillSuccessCount'] ?? 0),
            (string) ($result['runtimeOutcome'] ?? ''),
            (string) ($result['runtimeStrategy'] ?? ''),
            !empty($result['success']) ? '1' : '0',
            !empty($result['skipped']) ? '1' : '0',
        );

        return substr(hash('sha256', implode('|', $parts)), 0, 24);
    }

    /**
     * Record compact production invalidation and refill outcomes.
     *
     * @param array $result Operation result.
     * @return void
     */
    private static function record_varnish_operation_result(array $result)
    {
        if (empty($result) || !isset($result['time'])) {
            return;
        }
        if (method_exists(static::class, 'is_varnish_test_run_active') && self::is_varnish_test_run_active()) {
            return;
        }

        $operation_type = sanitize_key((string) ($result['operationType'] ?? ''));
        $test_type = sanitize_key((string) ($result['testType'] ?? ''));
        if (0 === strpos($operation_type, 'diagnostic-') || in_array($test_type, array('basic', 'behavior'), true)) {
            return;
        }

        $fingerprint = self::get_varnish_operation_fingerprint($result);
        if (isset(self::$varnish_metrics_recorded_operations[$fingerprint])) {
            return;
        }
        self::$varnish_metrics_recorded_operations[$fingerprint] = true;
        if (count(self::$varnish_metrics_recorded_operations) > 50) {
            self::$varnish_metrics_recorded_operations = array_slice(self::$varnish_metrics_recorded_operations, -50, null, true);
        }

        $state = self::get_varnish_metrics_state();
        $operations = $state['operations'];

        $is_invalidation = false !== strpos($operation_type, 'invalidation')
            || 'site-flush' === $operation_type
            || isset($result['fullyInvalidatedUrlCount']);
        if ($is_invalidation) {
            $operations['invalidationOperations']++;
            $fully_invalidated = max(0, (int) ($result['fullyInvalidatedUrlCount'] ?? 0));
            if ($fully_invalidated < 1 && !empty($result['success'])) {
                $fully_invalidated = max(0, (int) ($result['uniqueUrlCount'] ?? 0));
            }
            $operations['invalidatedUrls'] += $fully_invalidated;
        }

        if (false !== strpos($operation_type, 'refill') && empty($result['skipped'])) {
            $operations['refillAttempts']++;
            if (!empty($result['success'])) {
                $operations['refillSuccesses']++;
            } else {
                $operations['refillFailures']++;
            }
        }

        $operations['esiWarmParentVariants'] += max(0, (int) ($result['esiParentCount'] ?? 0));
        $operations['esiWarmFragmentReferences'] += max(0, (int) ($result['esiFragmentReferenceCount'] ?? 0));
        $state['operations'] = $operations;

        $runtime_outcome = sanitize_key((string) ($result['runtimeOutcome'] ?? ''));
        if (isset($state['runtimeOutcomes'][$runtime_outcome])) {
            $state['runtimeOutcomes'][$runtime_outcome]++;
        }
        $runtime_strategy = sanitize_key((string) ($result['runtimeStrategy'] ?? ''));
        if ('' !== $runtime_strategy) {
            if (!isset($state['runtimeStrategies'][$runtime_strategy])) {
                $runtime_strategy = 'other';
            }
            $state['runtimeStrategies'][$runtime_strategy]++;
        }

        self::set_varnish_metrics_state($state);
    }

    private static function get_varnish_esi_metrics_sample_rate()
    {
        $rate = (int) apply_filters('ultracache_varnish_esi_metrics_sample_rate', 32);
        return max(1, min(256, $rate));
    }

    private static function should_sample_varnish_esi_metrics($sample_rate)
    {
        $sample_rate = max(1, min(256, absint($sample_rate)));
        if (1 === $sample_rate) {
            return true;
        }
        return 1 === wp_rand(1, $sample_rate);
    }

    private static function get_varnish_esi_metrics_hour_key()
    {
        $hour_start = (int) floor(time() / HOUR_IN_SECONDS) * HOUR_IN_SECONDS;
        return array(gmdate('YmdH', $hour_start), $hour_start);
    }

    private static function get_default_varnish_esi_hour_bucket($hour_start)
    {
        return array(
            'hourStart' => absint($hour_start),
            'sampledRequests' => 0,
            'estimatedRequests' => 0,
            'sampledPublicRequests' => 0,
            'estimatedPublicRequests' => 0,
            'sampledPrivateRequests' => 0,
            'estimatedPrivateRequests' => 0,
            'renderDurationMsTotal' => 0,
            'renderDurationMsMax' => 0,
            'outputBytesTotal' => 0,
            'containedErrors' => 0,
            'woocommerceMiniCartSampledRequests' => 0,
            'woocommerceMiniCartEstimatedRequests' => 0,
            'woocommerceMiniCartRenderDurationMsTotal' => 0,
            'woocommerceMiniCartRenderDurationMsMax' => 0,
            'woocommerceMiniCartOutputBytesTotal' => 0,
            'woocommerceMiniCartContainedErrors' => 0,
        );
    }

    /**
     * Record a sampled ESI fragment render. The default 1:32 sample keeps the
     * diagnostics useful without adding an option write to every fragment.
     *
     * @param string $fragment_id       Fragment identifier.
     * @param string $context_hash      Context hash. Intentionally unused.
     * @param int    $output_bytes      Output bytes.
     * @param int    $render_duration_ms Render duration.
     * @param int    $ttl               Fragment TTL. Intentionally unused.
     * @param string $scope             public or private.
     * @return void
     */
    public static function record_varnish_esi_fragment_render_metrics($fragment_id, $context_hash, $output_bytes, $render_duration_ms, $ttl, $scope)
    {
        unset($context_hash, $ttl);
        if (method_exists(static::class, 'is_varnish_test_run_active') && self::is_varnish_test_run_active()) {
            return;
        }

        $sample_rate = self::get_varnish_esi_metrics_sample_rate();
        if (!self::should_sample_varnish_esi_metrics($sample_rate)) {
            return;
        }

        $fragment_id = sanitize_key((string) $fragment_id);
        $scope = 'private' === sanitize_key((string) $scope) ? 'private' : 'public';
        $output_bytes = max(0, min(10485760, (int) $output_bytes));
        $render_duration_ms = max(0, min(600000, (int) $render_duration_ms));
        $state = self::get_varnish_metrics_state();
        $state['esi']['sampleRate'] = $sample_rate;
        $state['esi']['sampledRequests']++;
        $state['esi']['estimatedRequests'] += $sample_rate;
        $state['esi']['renderDurationMsTotal'] += $render_duration_ms;
        $state['esi']['renderDurationMsMax'] = max($state['esi']['renderDurationMsMax'], $render_duration_ms);
        $state['esi']['outputBytesTotal'] += $output_bytes;
        if ('private' === $scope) {
            $state['esi']['sampledPrivateRequests']++;
            $state['esi']['estimatedPrivateRequests'] += $sample_rate;
        } else {
            $state['esi']['sampledPublicRequests']++;
            $state['esi']['estimatedPublicRequests'] += $sample_rate;
        }

        list($hour_key, $hour_start) = self::get_varnish_esi_metrics_hour_key();
        $hour = is_array($state['esiHourly'][$hour_key] ?? null)
            ? $state['esiHourly'][$hour_key]
            : self::get_default_varnish_esi_hour_bucket($hour_start);
        $hour['sampledRequests']++;
        $hour['estimatedRequests'] += $sample_rate;
        $hour['renderDurationMsTotal'] += $render_duration_ms;
        $hour['renderDurationMsMax'] = max($hour['renderDurationMsMax'], $render_duration_ms);
        $hour['outputBytesTotal'] += $output_bytes;
        if ('private' === $scope) {
            $hour['sampledPrivateRequests']++;
            $hour['estimatedPrivateRequests'] += $sample_rate;
        } else {
            $hour['sampledPublicRequests']++;
            $hour['estimatedPublicRequests'] += $sample_rate;
        }

        if ('woocommerce-mini-cart' === $fragment_id) {
            $state['esi']['woocommerceMiniCartSampledRequests']++;
            $state['esi']['woocommerceMiniCartEstimatedRequests'] += $sample_rate;
            $state['esi']['woocommerceMiniCartRenderDurationMsTotal'] += $render_duration_ms;
            $state['esi']['woocommerceMiniCartRenderDurationMsMax'] = max($state['esi']['woocommerceMiniCartRenderDurationMsMax'], $render_duration_ms);
            $state['esi']['woocommerceMiniCartOutputBytesTotal'] += $output_bytes;
            $hour['woocommerceMiniCartSampledRequests']++;
            $hour['woocommerceMiniCartEstimatedRequests'] += $sample_rate;
            $hour['woocommerceMiniCartRenderDurationMsTotal'] += $render_duration_ms;
            $hour['woocommerceMiniCartRenderDurationMsMax'] = max($hour['woocommerceMiniCartRenderDurationMsMax'], $render_duration_ms);
            $hour['woocommerceMiniCartOutputBytesTotal'] += $output_bytes;
        }

        $state['esiHourly'][$hour_key] = $hour;
        self::set_varnish_metrics_state($state);
    }

    /**
     * Record contained ESI errors without sampling because correctness failures
     * should not be hidden by the request-rate sampler.
     *
     * @param string $fragment_id Fragment identifier.
     * @param array  $context     Fragment context. Intentionally unused.
     * @param array  $definition  Fragment definition. Intentionally unused.
     * @param string $error_code  Error code. Intentionally unused.
     * @return void
     */
    public static function record_varnish_esi_fragment_contained_error($fragment_id, $context, $definition, $error_code = '')
    {
        unset($context, $definition, $error_code);
        if (method_exists(static::class, 'is_varnish_test_run_active') && self::is_varnish_test_run_active()) {
            return;
        }

        $fragment_id = sanitize_key((string) $fragment_id);
        $state = self::get_varnish_metrics_state();
        $state['esi']['containedErrors']++;
        if ('woocommerce-mini-cart' === $fragment_id) {
            $state['esi']['woocommerceMiniCartContainedErrors']++;
        }
        list($hour_key, $hour_start) = self::get_varnish_esi_metrics_hour_key();
        $hour = is_array($state['esiHourly'][$hour_key] ?? null)
            ? $state['esiHourly'][$hour_key]
            : self::get_default_varnish_esi_hour_bucket($hour_start);
        $hour['containedErrors']++;
        if ('woocommerce-mini-cart' === $fragment_id) {
            $hour['woocommerceMiniCartContainedErrors']++;
        }
        $state['esiHourly'][$hour_key] = $hour;
        self::set_varnish_metrics_state($state);
    }

    /**
     * Record one public ESI exact-context invalidation outcome without
     * duplicating the generic Varnish invalidation operation counters.
     *
     * @param bool $success Whether exact invalidation completed.
     * @return void
     */
    private static function record_varnish_esi_fragment_invalidation_result($success)
    {
        if (method_exists(static::class, 'is_varnish_test_run_active') && self::is_varnish_test_run_active()) {
            return;
        }

        $state = self::get_varnish_metrics_state();
        $state['operations']['esiFragmentInvalidations']++;
        if (!$success) {
            $state['operations']['esiFragmentInvalidationFailures']++;
        }
        self::set_varnish_metrics_state($state);
    }

    private static function get_varnish_esi_metrics_last_24_hours(array $state)
    {
        $minimum = time() - DAY_IN_SECONDS;
        $summary = self::get_default_varnish_esi_hour_bucket(0);
        foreach ((array) ($state['esiHourly'] ?? array()) as $bucket) {
            if (!is_array($bucket) || absint($bucket['hourStart'] ?? 0) < $minimum) {
                continue;
            }
            foreach ($summary as $key => $default) {
                if ('hourStart' === $key) {
                    continue;
                }
                if (false !== strpos($key, 'Max')) {
                    $summary[$key] = max($summary[$key], max(0, (int) ($bucket[$key] ?? 0)));
                } else {
                    $summary[$key] += max(0, (int) ($bucket[$key] ?? 0));
                }
            }
        }

        $sampled = max(0, (int) $summary['sampledRequests']);
        $woo_sampled = max(0, (int) $summary['woocommerceMiniCartSampledRequests']);
        return array(
            'sampledRequests' => $sampled,
            'estimatedRequests' => max(0, (int) $summary['estimatedRequests']),
            'estimatedRequestsPerHour' => round(max(0, (int) $summary['estimatedRequests']) / 24, 1),
            'sampledPublicRequests' => max(0, (int) $summary['sampledPublicRequests']),
            'estimatedPublicRequests' => max(0, (int) $summary['estimatedPublicRequests']),
            'sampledPrivateRequests' => max(0, (int) $summary['sampledPrivateRequests']),
            'estimatedPrivateRequests' => max(0, (int) $summary['estimatedPrivateRequests']),
            'averageRenderMs' => $sampled > 0 ? round($summary['renderDurationMsTotal'] / $sampled, 1) : 0.0,
            'maximumRenderMs' => max(0, (int) $summary['renderDurationMsMax']),
            'averageOutputBytes' => $sampled > 0 ? round($summary['outputBytesTotal'] / $sampled, 1) : 0.0,
            'containedErrors' => max(0, (int) $summary['containedErrors']),
            'woocommerceMiniCart' => array(
                'sampledRequests' => $woo_sampled,
                'estimatedRequests' => max(0, (int) $summary['woocommerceMiniCartEstimatedRequests']),
                'estimatedRequestsPerHour' => round(max(0, (int) $summary['woocommerceMiniCartEstimatedRequests']) / 24, 1),
                'averageRenderMs' => $woo_sampled > 0 ? round($summary['woocommerceMiniCartRenderDurationMsTotal'] / $woo_sampled, 1) : 0.0,
                'maximumRenderMs' => max(0, (int) $summary['woocommerceMiniCartRenderDurationMsMax']),
                'averageOutputBytes' => $woo_sampled > 0 ? round($summary['woocommerceMiniCartOutputBytesTotal'] / $woo_sampled, 1) : 0.0,
                'containedErrors' => max(0, (int) $summary['woocommerceMiniCartContainedErrors']),
            ),
        );
    }

    private static function get_varnish_metrics_status()
    {
        $state = self::get_varnish_metrics_state();
        $esi = $state['esi'];
        $sampled = max(0, (int) $esi['sampledRequests']);
        $woo_sampled = max(0, (int) $esi['woocommerceMiniCartSampledRequests']);

        return array(
            'version' => 4,
            'updatedAt' => max(0, (int) $state['updatedAt']),
            'windowStartedAt' => max(0, (int) $state['windowStartedAt']),
            'operations' => $state['operations'],
            'runtimeOutcomes' => $state['runtimeOutcomes'],
            'runtimeStrategies' => $state['runtimeStrategies'],
            'esi' => array(
                'sampleRate' => max(1, (int) $esi['sampleRate']),
                'sampledRequests' => $sampled,
                'estimatedRequests' => max(0, (int) $esi['estimatedRequests']),
                'sampledPublicRequests' => max(0, (int) $esi['sampledPublicRequests']),
                'estimatedPublicRequests' => max(0, (int) $esi['estimatedPublicRequests']),
                'sampledPrivateRequests' => max(0, (int) $esi['sampledPrivateRequests']),
                'estimatedPrivateRequests' => max(0, (int) $esi['estimatedPrivateRequests']),
                'averageRenderMs' => $sampled > 0 ? round($esi['renderDurationMsTotal'] / $sampled, 1) : 0.0,
                'maximumRenderMs' => max(0, (int) $esi['renderDurationMsMax']),
                'averageOutputBytes' => $sampled > 0 ? round($esi['outputBytesTotal'] / $sampled, 1) : 0.0,
                'containedErrors' => max(0, (int) $esi['containedErrors']),
                'woocommerceMiniCart' => array(
                    'sampledRequests' => $woo_sampled,
                    'estimatedRequests' => max(0, (int) $esi['woocommerceMiniCartEstimatedRequests']),
                    'averageRenderMs' => $woo_sampled > 0 ? round($esi['woocommerceMiniCartRenderDurationMsTotal'] / $woo_sampled, 1) : 0.0,
                    'maximumRenderMs' => max(0, (int) $esi['woocommerceMiniCartRenderDurationMsMax']),
                    'averageOutputBytes' => $woo_sampled > 0 ? round($esi['woocommerceMiniCartOutputBytesTotal'] / $woo_sampled, 1) : 0.0,
                    'containedErrors' => max(0, (int) $esi['woocommerceMiniCartContainedErrors']),
                ),
                'last24Hours' => self::get_varnish_esi_metrics_last_24_hours($state),
            ),
        );
    }
}
