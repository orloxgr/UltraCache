<?php
/**
 * Bounded Varnish endpoint health, operation metrics, and ban-pressure helpers.
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

    private static function get_varnish_metrics_option_name()
    {
        return 'ultracache_varnish_metrics_v1';
    }

    private static function get_default_varnish_metrics_state()
    {
        return array(
            'version' => 1,
            'updatedAt' => 0,
            'endpoints' => array(),
            'operations' => array(
                'total' => 0,
                'successful' => 0,
                'failed' => 0,
                'invalidations' => 0,
                'refills' => 0,
                'behaviorTests' => 0,
                'endpointRequests' => 0,
                'endpointFailures' => 0,
                'urlsReceived' => 0,
                'uniqueUrls' => 0,
                'duplicatesRemoved' => 0,
                'rejectedUrls' => 0,
                'banBatches' => 0,
                'requestsSavedByBatching' => 0,
                'refillsVerified' => 0,
                'refillsBypassed' => 0,
                'refillsInconclusive' => 0,
            ),
            'recent' => array(),
            'fingerprints' => array(),
            'banPressure' => array(
                'status' => 'not-tested',
                'available' => false,
                'time' => 0,
                'endpointCount' => 0,
                'listedEntries' => 0,
                'activeEntries' => 0,
                'completedEntries' => 0,
                'requestDependentEntries' => 0,
                'objectOnlyEntries' => 0,
                'failedEndpoints' => 0,
                'diagnostics' => array(),
                'message' => __('Ban pressure has not been inspected.', 'ultracache'),
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
        $state['endpoints'] = is_array($value['endpoints'] ?? null) ? array_slice($value['endpoints'], 0, 12, true) : array();
        $state['operations'] = array_merge($defaults['operations'], is_array($value['operations'] ?? null) ? $value['operations'] : array());
        foreach ($state['operations'] as $key => $count) {
            $state['operations'][$key] = max(0, (int) $count);
        }
        $state['recent'] = is_array($value['recent'] ?? null) ? array_slice(array_values($value['recent']), 0, 12) : array();
        $state['fingerprints'] = is_array($value['fingerprints'] ?? null) ? array_slice(array_values($value['fingerprints']), 0, 20) : array();
        $state['banPressure'] = array_merge($defaults['banPressure'], is_array($value['banPressure'] ?? null) ? $value['banPressure'] : array());
        $state['banPressure']['diagnostics'] = is_array($state['banPressure']['diagnostics'] ?? null) ? array_slice(array_values($state['banPressure']['diagnostics']), 0, 12) : array();

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

    private static function normalize_varnish_metrics_endpoint_label($endpoint)
    {
        if (is_array($endpoint)) {
            $host = sanitize_text_field((string) ($endpoint['host'] ?? ''));
            $port = absint($endpoint['port'] ?? 0);
            $endpoint = $host . ($port > 0 ? ':' . $port : '');
        }

        $endpoint = trim(sanitize_text_field((string) $endpoint));
        if (strlen($endpoint) > 190) {
            $endpoint = substr($endpoint, 0, 190);
        }

        return $endpoint;
    }

    private static function get_varnish_metrics_endpoint_key($endpoint, $mode)
    {
        return substr(hash('sha256', sanitize_key((string) $mode) . '|' . strtolower(self::normalize_varnish_metrics_endpoint_label($endpoint))), 0, 24);
    }

    private static function record_varnish_endpoint_result($endpoint, $mode, $success, $duration_ms, $detail = '')
    {
        $label = self::normalize_varnish_metrics_endpoint_label($endpoint);
        if ('' === $label) {
            return;
        }

        $state = self::get_varnish_metrics_state();
        $key = self::get_varnish_metrics_endpoint_key($label, $mode);
        $current = is_array($state['endpoints'][$key] ?? null) ? $state['endpoints'][$key] : array();
        $samples = min(1000, max(0, (int) ($current['latencySamples'] ?? 0)));
        $average = max(0, (int) ($current['averageLatencyMs'] ?? 0));
        $duration_ms = max(0, min(60000, (int) round($duration_ms)));
        $new_samples = min(1000, $samples + 1);
        $new_average = 1 === $new_samples
            ? $duration_ms
            : (int) round((($average * min($samples, 999)) + $duration_ms) / max(1, min($samples, 999) + 1));
        $detail = self::sanitize_varnish_string((string) $detail);
        if (strlen($detail) > 240) {
            $detail = substr($detail, 0, 240);
        }

        $current = array_merge(array(
            'label' => $label,
            'mode' => sanitize_key((string) $mode),
            'requestCount' => 0,
            'successCount' => 0,
            'failureCount' => 0,
            'consecutiveFailures' => 0,
            'averageLatencyMs' => 0,
            'latencySamples' => 0,
            'lastSuccessAt' => 0,
            'lastFailureAt' => 0,
            'lastDetail' => '',
        ), $current);
        $current['label'] = $label;
        $current['mode'] = sanitize_key((string) $mode);
        $current['requestCount'] = max(0, (int) $current['requestCount']) + 1;
        $current['averageLatencyMs'] = $new_average;
        $current['latencySamples'] = $new_samples;
        $current['lastDetail'] = $detail;
        if ($success) {
            $current['successCount'] = max(0, (int) $current['successCount']) + 1;
            $current['consecutiveFailures'] = 0;
            $current['lastSuccessAt'] = time();
        } else {
            $current['failureCount'] = max(0, (int) $current['failureCount']) + 1;
            $current['consecutiveFailures'] = min(999, max(0, (int) $current['consecutiveFailures']) + 1);
            $current['lastFailureAt'] = time();
        }

        $state['endpoints'][$key] = $current;
        if (count($state['endpoints']) > 12) {
            uasort($state['endpoints'], static function ($left, $right) {
                $left_time = max((int) ($left['lastSuccessAt'] ?? 0), (int) ($left['lastFailureAt'] ?? 0));
                $right_time = max((int) ($right['lastSuccessAt'] ?? 0), (int) ($right['lastFailureAt'] ?? 0));
                return $right_time <=> $left_time;
            });
            $state['endpoints'] = array_slice($state['endpoints'], 0, 12, true);
        }
        self::set_varnish_metrics_state($state);
    }

    private static function get_varnish_operation_fingerprint(array $result)
    {
        $parts = array(
            (string) ($result['operationType'] ?? $result['testType'] ?? $result['scope'] ?? 'operation'),
            (string) ($result['time'] ?? 0),
            (string) ($result['requestCount'] ?? 0),
            (string) ($result['uniqueUrlCount'] ?? 0),
            (string) ($result['refillSuccessCount'] ?? 0),
            (string) ($result['label'] ?? ''),
            (string) ($result['message'] ?? ''),
            !empty($result['success']) ? '1' : '0',
        );

        return substr(hash('sha256', implode('|', $parts)), 0, 24);
    }

    private static function record_varnish_operation_result(array $result)
    {
        if (empty($result) || !isset($result['time'])) {
            return;
        }

        $fingerprint = self::get_varnish_operation_fingerprint($result);
        $state = self::get_varnish_metrics_state();
        if (in_array($fingerprint, $state['fingerprints'], true)) {
            return;
        }
        array_unshift($state['fingerprints'], $fingerprint);
        $state['fingerprints'] = array_slice(array_values(array_unique($state['fingerprints'])), 0, 20);

        $operations = $state['operations'];
        ++$operations['total'];
        if (!empty($result['success'])) {
            ++$operations['successful'];
        } elseif (empty($result['skipped'])) {
            ++$operations['failed'];
        }

        $operation_type = sanitize_key((string) ($result['operationType'] ?? ''));
        $test_type = sanitize_key((string) ($result['testType'] ?? ''));
        if (false !== strpos($operation_type, 'invalidation') || isset($result['uniqueUrlCount'])) {
            ++$operations['invalidations'];
        }
        if ('queued-refill' === $operation_type) {
            ++$operations['refills'];
        }
        if ('behavior' === $test_type) {
            ++$operations['behaviorTests'];
        }

        $request_count = max(0, (int) ($result['requestCount'] ?? 0));
        $endpoint_failures = 0;
        foreach ((array) ($result['details'] ?? array()) as $detail) {
            if (is_array($detail) && empty($detail['success'])) {
                ++$endpoint_failures;
            }
        }
        $operations['endpointRequests'] += $request_count;
        $operations['endpointFailures'] += $endpoint_failures;
        $operations['urlsReceived'] += max(0, (int) ($result['receivedUrlCount'] ?? 0));
        $operations['uniqueUrls'] += max(0, (int) ($result['uniqueUrlCount'] ?? 0));
        $operations['duplicatesRemoved'] += max(0, (int) ($result['duplicateUrlCount'] ?? 0));
        $operations['rejectedUrls'] += max(0, (int) ($result['rejectedUrlCount'] ?? 0));
        $operations['banBatches'] += max(0, (int) ($result['batchCount'] ?? 0));
        $operations['refillsVerified'] += max(0, (int) ($result['refillVerifiedCount'] ?? 0));
        $operations['refillsBypassed'] += max(0, (int) ($result['refillBypassedCount'] ?? 0));
        $operations['refillsInconclusive'] += max(0, (int) ($result['refillInconclusiveCount'] ?? 0)) + max(0, (int) ($result['refillNotHitCount'] ?? 0)) + max(0, (int) ($result['refillVerificationErrorCount'] ?? 0));

        $unique = max(0, (int) ($result['uniqueUrlCount'] ?? 0));
        $endpoints = max(0, (int) ($result['endpointCount'] ?? 0));
        $method = strtoupper((string) ($result['method'] ?? ''));
        if ($unique > 0 && $endpoints > 0 && ('BAN' === $method || !empty($result['adminModeUsed']))) {
            $operations['requestsSavedByBatching'] += max(0, ($unique * $endpoints) - $request_count);
        }

        $state['operations'] = $operations;
        array_unshift($state['recent'], array(
            'time' => max(0, (int) ($result['time'] ?? time())),
            'type' => $operation_type ?: ($test_type ?: sanitize_key((string) ($result['scope'] ?? 'operation'))),
            'success' => !empty($result['success']),
            'requestCount' => $request_count,
            'uniqueUrlCount' => $unique,
            'endpointFailures' => $endpoint_failures,
        ));
        $state['recent'] = array_slice($state['recent'], 0, 12);
        self::set_varnish_metrics_state($state);
    }

    private static function classify_varnish_endpoint_health(array $endpoint)
    {
        $requests = max(0, (int) ($endpoint['requestCount'] ?? 0));
        $failures = max(0, (int) ($endpoint['consecutiveFailures'] ?? 0));
        if ($requests < 1) {
            return 'untested';
        }
        if ($failures >= 3) {
            return 'unhealthy';
        }
        if ($failures > 0) {
            return 'degraded';
        }
        return 'healthy';
    }

    private static function get_varnish_metrics_status()
    {
        $state = self::get_varnish_metrics_state();
        $settings = self::get_dashboard_settings();
        $mode = self::sanitize_varnish_mode($settings['varnishCliMode'] ?? 'http');
        $servers_raw = self::sanitize_varnish_servers_string($settings['varnishCliServers'] ?? '', $mode);
        $servers = array_values(array_filter(array_map('trim', preg_split('/\s+/', $servers_raw))));
        $configured = array();

        foreach ($servers as $server) {
            $key = self::get_varnish_metrics_endpoint_key($server, $mode);
            $endpoint = is_array($state['endpoints'][$key] ?? null) ? $state['endpoints'][$key] : array(
                'label' => self::normalize_varnish_metrics_endpoint_label($server),
                'mode' => $mode,
                'requestCount' => 0,
                'successCount' => 0,
                'failureCount' => 0,
                'consecutiveFailures' => 0,
                'averageLatencyMs' => 0,
                'lastSuccessAt' => 0,
                'lastFailureAt' => 0,
                'lastDetail' => '',
            );
            $endpoint['label'] = self::normalize_varnish_metrics_endpoint_label($endpoint['label'] ?? $server);
            $endpoint['mode'] = sanitize_key((string) ($endpoint['mode'] ?? $mode));
            foreach (array('requestCount', 'successCount', 'failureCount', 'consecutiveFailures', 'averageLatencyMs', 'lastSuccessAt', 'lastFailureAt') as $numeric_key) {
                $endpoint[$numeric_key] = max(0, (int) ($endpoint[$numeric_key] ?? 0));
            }
            $endpoint['lastDetail'] = self::sanitize_varnish_string((string) ($endpoint['lastDetail'] ?? ''));
            $endpoint['health'] = self::classify_varnish_endpoint_health($endpoint);
            $configured[] = $endpoint;
        }

        $healthy = 0;
        $degraded = 0;
        $unhealthy = 0;
        foreach ($configured as $endpoint) {
            if ('healthy' === $endpoint['health']) {
                ++$healthy;
            } elseif ('degraded' === $endpoint['health']) {
                ++$degraded;
            } elseif ('unhealthy' === $endpoint['health']) {
                ++$unhealthy;
            }
        }

        return array(
            'updatedAt' => max(0, (int) $state['updatedAt']),
            'endpoints' => $configured,
            'healthyEndpoints' => $healthy,
            'degradedEndpoints' => $degraded,
            'unhealthyEndpoints' => $unhealthy,
            'operations' => $state['operations'],
            'recent' => $state['recent'],
            'banPressure' => $state['banPressure'],
        );
    }

    private static function parse_varnish_ban_list_body($body)
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $body);
        $listed = 0;
        $completed = 0;
        $request_dependent = 0;
        $object_only = 0;
        foreach (array_slice((array) $lines, 0, 5000) as $line) {
            $line = trim((string) $line);
            if ('' === $line || 0 === stripos($line, 'Present bans')) {
                continue;
            }
            if (!preg_match('/^\d+(?:\.\d+)?\s+/', $line)) {
                continue;
            }
            ++$listed;
            if (preg_match('/\sC(?:\s|$)/', $line)) {
                ++$completed;
            }
            if (false !== strpos($line, 'req.')) {
                ++$request_dependent;
            }
            if (false !== strpos($line, 'obj.') && false === strpos($line, 'req.')) {
                ++$object_only;
            }
        }

        $active = max(0, $listed - $completed);
        $status = $listed >= 1000 || $active >= 500 ? 'high' : (($listed >= 250 || $active >= 100 || $request_dependent >= 100) ? 'elevated' : 'normal');
        return array(
            'status' => $status,
            'listedEntries' => $listed,
            'activeEntries' => $active,
            'completedEntries' => $completed,
            'requestDependentEntries' => $request_dependent,
            'objectOnlyEntries' => $object_only,
        );
    }

    private static function set_varnish_ban_pressure_status(array $pressure)
    {
        $state = self::get_varnish_metrics_state();
        $defaults = self::get_default_varnish_metrics_state();
        $state['banPressure'] = array_merge($defaults['banPressure'], $pressure);
        $state['banPressure']['time'] = time();
        self::set_varnish_metrics_state($state);
    }
    private static function collect_varnish_ban_pressure()
    {
        $settings = self::get_varnish_cli_settings();
        if ('admin' !== (string) ($settings['mode'] ?? 'http')) {
            $pressure = array(
                'status' => 'unavailable',
                'available' => false,
                'endpointCount' => count((array) ($settings['servers'] ?? array())),
                'failedEndpoints' => 0,
                'message' => __('Ban pressure inspection is available only in authenticated Varnish admin mode.', 'ultracache'),
            );
            self::set_varnish_ban_pressure_status($pressure);
            return $pressure;
        }

        $aggregate = array(
            'status' => 'normal',
            'available' => false,
            'endpointCount' => count((array) ($settings['servers'] ?? array())),
            'listedEntries' => 0,
            'activeEntries' => 0,
            'completedEntries' => 0,
            'requestDependentEntries' => 0,
            'objectOnlyEntries' => 0,
            'failedEndpoints' => 0,
            'diagnostics' => array(),
            'message' => '',
        );
        $statuses = array('normal' => 0, 'elevated' => 1, 'high' => 2);

        foreach ((array) ($settings['servers'] ?? array()) as $server) {
            list($host, $port) = self::parse_varnish_terminal($server);
            $response = self::send_varnish_admin_ban_list($host, $port, (string) ($settings['key'] ?? ''), (int) ($settings['timeout'] ?? 2));
            $aggregate['diagnostics'][] = array(
                'server' => self::sanitize_varnish_string((string) $server),
                'success' => !empty($response['ok']),
                'detail' => self::sanitize_varnish_string((string) ($response['detail'] ?? '')),
            );
            if (empty($response['ok'])) {
                ++$aggregate['failedEndpoints'];
                continue;
            }

            $parsed = self::parse_varnish_ban_list_body((string) ($response['body'] ?? ''));
            $aggregate['available'] = true;
            foreach (array('listedEntries', 'activeEntries', 'completedEntries', 'requestDependentEntries', 'objectOnlyEntries') as $key) {
                $aggregate[$key] += max(0, (int) ($parsed[$key] ?? 0));
            }
            if (($statuses[$parsed['status'] ?? 'normal'] ?? 0) > ($statuses[$aggregate['status']] ?? 0)) {
                $aggregate['status'] = (string) $parsed['status'];
            }
        }

        if (!$aggregate['available']) {
            $aggregate['status'] = 'unavailable';
            $detail_messages = array();
            foreach ((array) $aggregate['diagnostics'] as $diagnostic) {
                $detail = trim((string) ($diagnostic['detail'] ?? ''));
                if ('' !== $detail) {
                    $detail_messages[] = $detail;
                }
            }
            $aggregate['message'] = __('Ban-list diagnostics are unavailable for the configured admin endpoint.', 'ultracache');
            if (!empty($detail_messages)) {
                $aggregate['message'] .= ' ' . implode(' · ', array_slice(array_values(array_unique($detail_messages)), 0, 3));
            }
        } elseif ('high' === $aggregate['status']) {
            $aggregate['message'] = __('High ban-list pressure was observed. Review frequent invalidation sources and request-dependent bans.', 'ultracache');
        } elseif ('elevated' === $aggregate['status']) {
            $aggregate['message'] = __('Elevated ban-list pressure was observed. Continue monitoring active and request-dependent bans.', 'ultracache');
        } else {
            $aggregate['message'] = __('The bounded ban-list inspection did not show elevated pressure.', 'ultracache');
        }

        self::set_varnish_ban_pressure_status($aggregate);
        self::flush_varnish_metrics_state();
        return $aggregate;
    }

}
