<?php
/**
 * Bounded production Varnish operation metrics for UltraCache.
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
            'version' => 2,
            'updatedAt' => 0,
            'operations' => array(
                'invalidationOperations' => 0,
                'invalidatedUrls' => 0,
                'endpointFailures' => 0,
                'refillAttempts' => 0,
                'refillSuccesses' => 0,
                'refillFailures' => 0,
            ),
        );
    }

    private static function sanitize_varnish_metrics_state($value)
    {
        $defaults = self::get_default_varnish_metrics_state();
        if (!is_array($value)) {
            return $defaults;
        }

        $state = $defaults;
        $state['updatedAt'] = max(0, (int) ($value['updatedAt'] ?? 0));
        $stored_operations = is_array($value['operations'] ?? null) ? $value['operations'] : array();
        foreach ($state['operations'] as $key => $default) {
            $state['operations'][$key] = max(0, (int) ($stored_operations[$key] ?? $default));
        }

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
     * Successful endpoint timing and health history belong to explicit
     * diagnostics rather than the production metrics option.
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

        $state['operations'] = $operations;
        self::set_varnish_metrics_state($state);
    }

    private static function get_varnish_metrics_status()
    {
        $state = self::get_varnish_metrics_state();

        return array(
            'updatedAt' => max(0, (int) $state['updatedAt']),
            'operations' => $state['operations'],
        );
    }
}
