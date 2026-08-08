<?php
/**
 * Atomic Varnish invalidation operation-rate coordination.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Invalidation_Rate_Trait
{
    /**
     * Return the authoritative Varnish invalidation-rate state name.
     *
     * @return string
     */
    private static function get_varnish_invalidation_rate_state_name()
    {
        return 'ultracache_state:varnish_invalidation_rate';
    }

    /**
     * Return the configured Varnish invalidation operation limit.
     *
     * @param array<string,mixed> $settings Optional settings snapshot.
     * @return int
     */
    private static function get_configured_varnish_invalidation_rate_limit(array $settings = array())
    {
        if (empty($settings) && method_exists(static::class, 'get_settings')) {
            $settings = self::get_settings();
        }

        $configured = $settings['varnish_invalidations_per_minute']
            ?? $settings['varnishInvalidationsPerMinute']
            ?? 10;

        return max(1, min(600, absint($configured)));
    }

    /**
     * Return the default Varnish invalidation-rate payload.
     *
     * @param int $configured_limit Optional configured limit.
     * @return array<string,int>
     */
    private static function get_default_varnish_invalidation_rate_state($configured_limit = 10)
    {
        $configured_limit = max(1, min(600, absint($configured_limit)));

        return array(
            'windowMinute' => 0,
            'claimedOperations' => 0,
            'configuredLimit' => $configured_limit,
            'effectiveLimit' => $configured_limit,
            'updatedAt' => 0,
        );
    }

    /**
     * Normalize one Varnish invalidation-rate payload.
     *
     * @param array<string,mixed> $state            State payload.
     * @param int                 $configured_limit Optional configured fallback.
     * @return array<string,int>
     */
    private static function normalize_varnish_invalidation_rate_state(array $state, $configured_limit = 10)
    {
        $state = array_merge(
            self::get_default_varnish_invalidation_rate_state($configured_limit),
            $state
        );
        $state['windowMinute'] = max(0, (int) $state['windowMinute']);
        $state['claimedOperations'] = max(0, min(600, (int) $state['claimedOperations']));
        $state['configuredLimit'] = max(1, min(600, (int) $state['configuredLimit']));
        $state['effectiveLimit'] = max(1, min(600, (int) $state['effectiveLimit']));
        $state['updatedAt'] = max(0, (int) $state['updatedAt']);
        return $state;
    }

    /**
     * Read the authoritative Varnish invalidation-rate payload.
     *
     * @param bool $read_only Whether storage must be read without schema ensure.
     * @return array<string,int>
     */
    private static function get_varnish_invalidation_rate_state($read_only = false)
    {
        $configured_limit = self::get_configured_varnish_invalidation_rate_limit();
        if (!function_exists('ultracache_get_state_record')) {
            return self::normalize_varnish_invalidation_rate_state(
                array(),
                $configured_limit
            );
        }

        $record = ($read_only && function_exists('ultracache_get_state_record_read_only'))
            ? ultracache_get_state_record_read_only(self::get_varnish_invalidation_rate_state_name())
            : ultracache_get_state_record(self::get_varnish_invalidation_rate_state_name());
        if (empty($record)) {
            return self::normalize_varnish_invalidation_rate_state(
                array(),
                $configured_limit
            );
        }

        return self::normalize_varnish_invalidation_rate_state(
            (array) ($record['payload'] ?? array()),
            $configured_limit
        );
    }

    /**
     * Synchronize the configured limit without increasing the allowance already
     * established for the current real-minute window.
     *
     * @param int $configured_limit Configured operation limit.
     * @param int $now              Optional current timestamp.
     * @return array<string,mixed>
     */
    private static function sync_varnish_invalidation_rate_limit($configured_limit, $now = 0)
    {
        $configured_limit = max(1, min(600, absint($configured_limit)));
        $now = $now > 0 ? (int) $now : time();
        $window = (int) floor($now / MINUTE_IN_SECONDS);
        if (!function_exists('ultracache_mutate_state_record')) {
            return array(
                'success' => false,
                'reason' => 'storage-unavailable',
                'state' => array(),
            );
        }

        return ultracache_mutate_state_record(
            self::get_varnish_invalidation_rate_state_name(),
            static function ($payload) use ($configured_limit, $now, $window) {
                $state = self::normalize_varnish_invalidation_rate_state(
                    (array) $payload,
                    $configured_limit
                );
                if ($window !== $state['windowMinute']) {
                    $state['windowMinute'] = $window;
                    $state['claimedOperations'] = 0;
                    $state['effectiveLimit'] = $configured_limit;
                } else {
                    $state['effectiveLimit'] = min($state['effectiveLimit'], $configured_limit);
                }
                $state['configuredLimit'] = $configured_limit;
                $state['updatedAt'] = $now;
                return self::normalize_varnish_invalidation_rate_state($state, $configured_limit);
            },
            5,
            self::normalize_varnish_invalidation_rate_state(
                array(
                    'windowMinute' => $window,
                    'configuredLimit' => $configured_limit,
                    'effectiveLimit' => $configured_limit,
                    'updatedAt' => $now,
                ),
                $configured_limit
            )
        );
    }

    /**
     * Atomically claim operations from the real-minute Varnish invalidation budget.
     *
     * Claims are conservative and are never released inside the same minute.
     * An interrupted worker may under-use one window, but overlapping workers
     * cannot exceed its committed effective limit.
     *
     * @param int    $requested_operations Maximum operations requested.
     * @param int    $now                  Optional current timestamp.
     * @param string $context              Diagnostic claim context.
     * @return array<string,mixed>
     */
    private static function claim_varnish_invalidation_rate_operations($requested_operations, $now = 0, $context = '')
    {
        $requested_operations = max(0, min(600, absint($requested_operations)));
        $configured_limit = self::get_configured_varnish_invalidation_rate_limit();
        $now = $now > 0 ? (int) $now : time();
        $window = (int) floor($now / MINUTE_IN_SECONDS);
        $next_at = ($window + 1) * MINUTE_IN_SECONDS;
        $context = sanitize_key((string) $context);

        if ($requested_operations < 1) {
            $snapshot = self::get_varnish_invalidation_rate_snapshot(false, $now);
            return array(
                'claimed' => false,
                'granted' => 0,
                'remaining' => (int) ($snapshot['remainingOperations'] ?? 0),
                'reason' => 'no-work-requested',
                'window' => $window,
                'nextAt' => $next_at,
                'context' => $context,
                'state' => (array) ($snapshot['state'] ?? array()),
            );
        }
        if (
            !function_exists('ultracache_get_state_record')
            || !function_exists('ultracache_create_state_record')
            || !function_exists('ultracache_compare_and_swap_state_record')
        ) {
            return array(
                'claimed' => false,
                'granted' => 0,
                'remaining' => 0,
                'reason' => 'storage-unavailable',
                'window' => $window,
                'nextAt' => $next_at,
                'context' => $context,
                'state' => array(),
            );
        }

        $state_name = self::get_varnish_invalidation_rate_state_name();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $record = ultracache_get_state_record($state_name);
            $state = empty($record)
                ? self::get_default_varnish_invalidation_rate_state($configured_limit)
                : self::normalize_varnish_invalidation_rate_state(
                    (array) ($record['payload'] ?? array()),
                    $configured_limit
                );

            if ($window !== $state['windowMinute']) {
                $state['windowMinute'] = $window;
                $state['claimedOperations'] = 0;
                $state['effectiveLimit'] = $configured_limit;
            } else {
                $state['effectiveLimit'] = min($state['effectiveLimit'], $configured_limit);
            }
            $state['configuredLimit'] = $configured_limit;

            $available = max(0, $state['effectiveLimit'] - $state['claimedOperations']);
            $granted = min($requested_operations, $available);
            if ($granted < 1) {
                return array(
                    'claimed' => false,
                    'granted' => 0,
                    'remaining' => 0,
                    'reason' => 'minute-budget-exhausted',
                    'window' => $window,
                    'nextAt' => $next_at,
                    'attempts' => $attempt,
                    'context' => $context,
                    'state' => $state,
                );
            }

            $state['claimedOperations'] += $granted;
            $state['updatedAt'] = $now;
            $state = self::normalize_varnish_invalidation_rate_state($state, $configured_limit);
            $result = empty($record)
                ? ultracache_create_state_record($state_name, $state)
                : ultracache_compare_and_swap_state_record(
                    $state_name,
                    (int) ($record['revision'] ?? 0),
                    $state
                );

            if (!empty($result['success'])) {
                return array(
                    'claimed' => true,
                    'granted' => $granted,
                    'remaining' => max(0, $state['effectiveLimit'] - $state['claimedOperations']),
                    'reason' => '',
                    'window' => $window,
                    'nextAt' => $next_at,
                    'attempts' => $attempt,
                    'context' => $context,
                    'revision' => max(0, (int) ($result['state']['revision'] ?? 0)),
                    'state' => $state,
                );
            }
            if (empty($result['conflict'])) {
                return array(
                    'claimed' => false,
                    'granted' => 0,
                    'remaining' => 0,
                    'reason' => sanitize_key((string) ($result['reason'] ?? 'write-failed')),
                    'window' => $window,
                    'nextAt' => $next_at,
                    'attempts' => $attempt,
                    'context' => $context,
                    'state' => isset($result['state']) && is_array($result['state'])
                        ? $result['state']
                        : array(),
                );
            }
        }

        return array(
            'claimed' => false,
            'granted' => 0,
            'remaining' => 0,
            'reason' => 'conflict-exhausted',
            'window' => $window,
            'nextAt' => $next_at,
            'attempts' => 5,
            'context' => $context,
            'state' => self::get_varnish_invalidation_rate_state(),
        );
    }

    /**
     * Return a read-model snapshot for diagnostics and future worker dispatch.
     *
     * @param bool $read_only Whether storage must be read without schema ensure.
     * @param int  $now       Optional current timestamp.
     * @return array<string,mixed>
     */
    private static function get_varnish_invalidation_rate_snapshot($read_only = true, $now = 0)
    {
        $now = $now > 0 ? (int) $now : time();
        $window = (int) floor($now / MINUTE_IN_SECONDS);
        $configured_limit = self::get_configured_varnish_invalidation_rate_limit();
        $state = self::get_varnish_invalidation_rate_state($read_only);

        if ($window !== $state['windowMinute']) {
            $state['windowMinute'] = $window;
            $state['claimedOperations'] = 0;
            $state['configuredLimit'] = $configured_limit;
            $state['effectiveLimit'] = $configured_limit;
        } else {
            $state['configuredLimit'] = $configured_limit;
            $state['effectiveLimit'] = min($state['effectiveLimit'], $configured_limit);
        }
        $state = self::normalize_varnish_invalidation_rate_state($state, $configured_limit);
        $remaining = max(0, $state['effectiveLimit'] - $state['claimedOperations']);

        return array(
            'state' => $state,
            'window' => $window,
            'nextAt' => ($window + 1) * MINUTE_IN_SECONDS,
            'remainingOperations' => $remaining,
            'exhausted' => $remaining < 1,
        );
    }
}
