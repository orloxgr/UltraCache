<?php
/**
 * Atomic shared background minute-rate coordination.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Warm_Rate_Trait
{
    /**
     * Return the authoritative shared background-rate state name.
     *
     * @return string
     */
    private static function get_warm_rate_state_name()
    {
        return 'ultracache_state:warm_rate';
    }

    /**
     * Return the default shared background-rate payload.
     *
     * @return array<string,int>
     */
    private static function get_default_warm_rate_state()
    {
        return array(
            'windowMinute' => 0,
            'claimedCount' => 0,
            'configuredLimit' => 0,
            'effectiveLimit' => 0,
            'updatedAt' => 0,
        );
    }

    /**
     * Normalize one shared background-rate payload.
     *
     * @param array<string,mixed> $state State payload.
     * @return array<string,int>
     */
    private static function normalize_warm_rate_state(array $state)
    {
        $state = array_merge(self::get_default_warm_rate_state(), $state);
        $state['windowMinute'] = max(0, (int) $state['windowMinute']);
        $state['claimedCount'] = max(0, min(600, (int) $state['claimedCount']));
        $state['configuredLimit'] = max(0, min(600, (int) $state['configuredLimit']));
        $state['effectiveLimit'] = max(0, min(600, (int) $state['effectiveLimit']));
        $state['updatedAt'] = max(0, (int) $state['updatedAt']);
        return $state;
    }

    /**
     * Return the configured central background URL limit.
     *
     * @param array<string,mixed> $settings Optional settings snapshot.
     * @return int
     */
    private static function get_configured_warm_rate_limit(array $settings = array())
    {
        if (empty($settings)) {
            $settings = method_exists(static::class, 'get_settings') ? self::get_settings() : array();
        }
        return max(0, min(600, absint($settings['cron_warm_pages_per_minute'] ?? 2)));
    }

    /**
     * Resolve the per-invocation effective limit without allowing an explicit
     * tick override to exceed or resume the central configured limit.
     *
     * @param int      $configured_limit Configured central limit.
     * @param int|null $requested_limit  Optional explicit invocation limit.
     * @return int
     */
    private static function resolve_effective_warm_rate_limit($configured_limit, $requested_limit = null)
    {
        $configured_limit = max(0, min(600, absint($configured_limit)));
        if ($configured_limit < 1) {
            return 0;
        }
        if (null === $requested_limit) {
            return $configured_limit;
        }
        return min($configured_limit, max(0, min(600, absint($requested_limit))));
    }

    /**
     * Read the authoritative shared background-rate payload.
     *
     * When the row does not exist yet, the current setting supplies only the
     * configuration defaults. No runtime claim is inferred or persisted.
     *
     * @param bool $read_only Whether storage must be read without schema ensure.
     * @return array<string,int>
     */
    private static function get_warm_rate_state($read_only = false)
    {
        $configured_limit = self::get_configured_warm_rate_limit();
        if (!function_exists('ultracache_get_state_record')) {
            return self::normalize_warm_rate_state(array(
                'configuredLimit' => $configured_limit,
                'effectiveLimit' => $configured_limit,
            ));
        }

        $record = ($read_only && function_exists('ultracache_get_state_record_read_only'))
            ? ultracache_get_state_record_read_only(self::get_warm_rate_state_name())
            : ultracache_get_state_record(self::get_warm_rate_state_name());
        if (empty($record)) {
            return self::normalize_warm_rate_state(array(
                'configuredLimit' => $configured_limit,
                'effectiveLimit' => $configured_limit,
            ));
        }

        return self::normalize_warm_rate_state((array) ($record['payload'] ?? array()));
    }

    /**
     * Synchronize the configured limit without resetting or increasing the
     * allowance already established for the current real-minute window.
     *
     * @param int $configured_limit Configured central limit.
     * @param int $now              Optional current timestamp.
     * @return array<string,mixed>
     */
    private static function sync_warm_rate_limit($configured_limit, $now = 0)
    {
        $configured_limit = max(0, min(600, absint($configured_limit)));
        $now = $now > 0 ? (int) $now : time();
        $window = (int) floor($now / MINUTE_IN_SECONDS);
        if (!function_exists('ultracache_mutate_state_record')) {
            return array(
                'success' => false,
                'reason' => 'storage_unavailable',
                'state' => array(),
            );
        }

        return ultracache_mutate_state_record(
            self::get_warm_rate_state_name(),
            static function ($payload) use ($configured_limit, $now, $window) {
                $state = self::normalize_warm_rate_state((array) $payload);
                if ($window !== $state['windowMinute']) {
                    $state['windowMinute'] = $window;
                    $state['claimedCount'] = 0;
                    $state['effectiveLimit'] = $configured_limit;
                } elseif ($configured_limit < 1) {
                    $state['effectiveLimit'] = 0;
                } elseif ($state['claimedCount'] < 1 && $state['effectiveLimit'] < 1) {
                    $state['effectiveLimit'] = $configured_limit;
                } elseif ($state['effectiveLimit'] > 0) {
                    $state['effectiveLimit'] = min($state['effectiveLimit'], $configured_limit);
                }
                $state['configuredLimit'] = $configured_limit;
                $state['updatedAt'] = $now;
                return self::normalize_warm_rate_state($state);
            },
            5,
            self::normalize_warm_rate_state(array(
                'windowMinute' => $window,
                'configuredLimit' => $configured_limit,
                'effectiveLimit' => $configured_limit,
                'updatedAt' => $now,
            ))
        );
    }

    /**
     * Atomically claim URL slots from the shared real-minute background budget.
     *
     * Claims are conservative and are never released inside the same minute.
     * An interrupted worker may therefore under-use a window, but concurrent
     * workers can never exceed its committed effective limit.
     *
     * @param int    $requested_slots   Maximum slots requested by this worker.
     * @param int    $configured_limit Central configured limit.
     * @param int    $effective_limit  Effective invocation limit.
     * @param int    $now              Optional current timestamp.
     * @param string $context          Diagnostic claim context.
     * @return array<string,mixed>
     */
    private static function claim_warm_rate_slots($requested_slots, $configured_limit, $effective_limit, $now = 0, $context = '')
    {
        $requested_slots = max(0, min(600, absint($requested_slots)));
        $configured_limit = max(0, min(600, absint($configured_limit)));
        $effective_limit = max(0, min($configured_limit, absint($effective_limit)));
        $now = $now > 0 ? (int) $now : time();
        $window = (int) floor($now / MINUTE_IN_SECONDS);
        $next_at = ($window + 1) * MINUTE_IN_SECONDS;
        $context = sanitize_key((string) $context);

        if ($configured_limit < 1 || $effective_limit < 1) {
            self::sync_warm_rate_limit($configured_limit, $now);
            return array(
                'claimed' => false,
                'granted' => 0,
                'remaining' => 0,
                'reason' => 'background-rate-paused',
                'window' => $window,
                'nextAt' => $next_at,
                'context' => $context,
                'state' => self::get_warm_rate_state(),
            );
        }
        if ($requested_slots < 1) {
            $state = self::get_warm_rate_state();
            $remaining = $window === (int) $state['windowMinute']
                ? max(0, (int) $state['effectiveLimit'] - (int) $state['claimedCount'])
                : $effective_limit;
            return array(
                'claimed' => false,
                'granted' => 0,
                'remaining' => $remaining,
                'reason' => 'no-work-requested',
                'window' => $window,
                'nextAt' => $next_at,
                'context' => $context,
                'state' => $state,
            );
        }
        if (!function_exists('ultracache_get_state_record') || !function_exists('ultracache_create_state_record') || !function_exists('ultracache_compare_and_swap_state_record')) {
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

        $state_name = self::get_warm_rate_state_name();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $record = ultracache_get_state_record($state_name);
            $state = empty($record)
                ? self::get_default_warm_rate_state()
                : self::normalize_warm_rate_state((array) ($record['payload'] ?? array()));

            if ($window !== $state['windowMinute']) {
                $state['windowMinute'] = $window;
                $state['claimedCount'] = 0;
                $state['effectiveLimit'] = $effective_limit;
            } elseif ($state['effectiveLimit'] < 1 && $state['claimedCount'] < 1) {
                $state['effectiveLimit'] = $effective_limit;
            } else {
                // A later invocation may lower the current window ceiling, but
                // cannot raise it and manufacture additional same-minute work.
                $state['effectiveLimit'] = min($state['effectiveLimit'], $effective_limit);
            }
            $state['configuredLimit'] = $configured_limit;

            $available = max(0, $state['effectiveLimit'] - $state['claimedCount']);
            $granted = min($requested_slots, $available);
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

            $state['claimedCount'] += $granted;
            $state['updatedAt'] = $now;
            $state = self::normalize_warm_rate_state($state);
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
                    'remaining' => max(0, $state['effectiveLimit'] - $state['claimedCount']),
                    'reason' => '',
                    'window' => $window,
                    'nextAt' => $next_at,
                    'attempts' => $attempt,
                    'context' => $context,
                    'revision' => max(0, (int) (($result['state']['revision'] ?? 0))),
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
                    'state' => isset($result['state']) && is_array($result['state']) ? $result['state'] : array(),
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
            'state' => self::get_warm_rate_state(),
        );
    }

    /**
     * Project the authoritative rate payload onto the legacy status shape.
     *
     * @param array<string,mixed> $state Legacy status payload.
     * @return array<string,mixed>
     */
    private static function overlay_warm_rate_on_cron_state(array $state)
    {
        $rate = self::get_warm_rate_state();
        $state['pagesPerMinute'] = (int) $rate['configuredLimit'];
        $state['backgroundRateWindowMinute'] = (int) $rate['windowMinute'];
        $state['backgroundRateWindowClaimedAt'] = (int) ($rate['claimedCount'] > 0 ? $rate['updatedAt'] : 0);
        $state['backgroundRateWindowLimit'] = (int) $rate['effectiveLimit'];
        $state['backgroundRateWindowClaimedCount'] = (int) $rate['claimedCount'];
        $state['warmRate'] = $rate;
        return $state;
    }
}
