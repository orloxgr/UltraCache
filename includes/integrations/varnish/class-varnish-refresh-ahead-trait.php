<?php
/**
 * Bounded Varnish hot-page refresh-ahead orchestration.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Refresh_Ahead_Trait
{
    private static function get_varnish_refresh_ahead_state_option_key()
    {
        return 'ultracache_varnish_refresh_ahead_state_v1';
    }

    private static function get_varnish_refresh_ahead_capability_transient_key()
    {
        return 'ultracache_varnish_refresh_ahead_capability_v1';
    }

    private static function get_varnish_refresh_ahead_scan_interval()
    {
        $interval = (int) apply_filters('ultracache_varnish_refresh_ahead_scan_interval_seconds', 5 * MINUTE_IN_SECONDS);
        return max(MINUTE_IN_SECONDS, min(HOUR_IN_SECONDS, $interval));
    }

    private static function get_varnish_refresh_ahead_configuration_signature(array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }

        $parts = array(
            (string) ($settings['mode'] ?? ''),
            implode(' ', array_map('strval', (array) ($settings['servers'] ?? array()))),
            (string) ($settings['method'] ?? ''),
            (string) ($settings['htmlTtlMinutes'] ?? ''),
            !empty($settings['key']) ? 'token-configured' : 'token-empty',
            home_url('/'),
        );
        return hash('sha256', implode('|', $parts));
    }

    private static function set_varnish_refresh_ahead_capability(array $capability, array $settings = array())
    {
        $capability['configurationSignature'] = self::get_varnish_refresh_ahead_configuration_signature($settings);
        $capability['testedAt'] = max(0, (int) ($capability['testedAt'] ?? time()));
        set_transient(
            self::get_varnish_refresh_ahead_capability_transient_key(),
            self::sanitize_varnish_result($capability),
            WEEK_IN_SECONDS
        );
    }

    private static function evaluate_varnish_refresh_ahead_capability(array $steps, array $settings, array $soft_capability, array $shared_ttl_test, $verified)
    {
        $age = null;
        foreach (array('verification', 'second') as $step_key) {
            $candidate_age = $steps[$step_key]['headers']['age'] ?? null;
            if (null !== $candidate_age && '' !== (string) $candidate_age && is_numeric($candidate_age)) {
                $age = max(0, (int) $candidate_age);
                break;
            }
        }

        $supported = !empty($verified)
            && !empty($soft_capability['supported'])
            && !empty($shared_ttl_test['observed'])
            && null !== $age
            && max(0, (int) ($settings['htmlTtlMinutes'] ?? 0)) > 0;

        if ($supported) {
            $status = 'verified';
            $message = self::maybe_translate('Refresh ahead is available: Varnish HIT, Age, shared TTL, and soft purge/grace behavior were verified.');
        } elseif (empty($verified)) {
            $status = 'cache-unverified';
            $message = self::maybe_translate('Refresh ahead is unavailable because the public Varnish HIT behavior was not verified.');
        } elseif (empty($soft_capability['supported'])) {
            $status = 'soft-purge-unverified';
            $message = self::maybe_translate('Refresh ahead requires verified soft purge with stale/grace delivery.');
        } elseif (empty($shared_ttl_test['observed'])) {
            $status = 'ttl-unverified';
            $message = self::maybe_translate('Refresh ahead requires a positive shared HTML TTL observed on a verified Varnish HIT.');
        } elseif (null === $age) {
            $status = 'age-hidden';
            $message = self::maybe_translate('Refresh ahead requires a numeric Age header, but the active Varnish configuration did not expose one.');
        } else {
            $status = 'unavailable';
            $message = self::maybe_translate('Refresh ahead prerequisites were not satisfied.');
        }

        $capability = array(
            'supported' => $supported,
            'status' => $status,
            'message' => $message,
            'ageObserved' => null !== $age,
            'observedAge' => $age,
            'testedAt' => time(),
        );
        self::set_varnish_refresh_ahead_capability($capability, $settings);
        return $capability;
    }

    private static function get_varnish_refresh_ahead_capability(array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }

        $value = get_transient(self::get_varnish_refresh_ahead_capability_transient_key());
        if (!is_array($value)) {
            return array(
                'supported' => false,
                'status' => 'untested',
                'message' => self::maybe_translate('Run Test Varnish to verify the HIT, Age, shared TTL, and soft purge prerequisites for refresh ahead.'),
                'testedAt' => 0,
                'ageObserved' => false,
                'observedAge' => null,
            );
        }

        $signature = self::get_varnish_refresh_ahead_configuration_signature($settings);
        if (!hash_equals((string) ($value['configurationSignature'] ?? ''), $signature)) {
            return array(
                'supported' => false,
                'status' => 'configuration-changed',
                'message' => self::maybe_translate('The Varnish endpoint or shared TTL configuration changed. Run Test Varnish again before using refresh ahead.'),
                'testedAt' => max(0, (int) ($value['testedAt'] ?? 0)),
                'ageObserved' => false,
                'observedAge' => null,
            );
        }

        return array(
            'supported' => !empty($value['supported']),
            'status' => sanitize_key((string) ($value['status'] ?? 'inconclusive')),
            'message' => self::sanitize_varnish_string((string) ($value['message'] ?? '')),
            'testedAt' => max(0, (int) ($value['testedAt'] ?? 0)),
            'ageObserved' => !empty($value['ageObserved']),
            'observedAge' => isset($value['observedAge']) && null !== $value['observedAge'] ? max(0, (int) $value['observedAge']) : null,
        );
    }

    private static function get_varnish_refresh_ahead_state()
    {
        $state = get_option(self::get_varnish_refresh_ahead_state_option_key(), array());
        if (!is_array($state)) {
            $state = array();
        }

        return array_merge(array(
            'lastScanAt' => 0,
            'candidateCount' => 0,
            'probedCount' => 0,
            'eligibleCount' => 0,
            'queuedCount' => 0,
            'skippedPendingCount' => 0,
            'errorCount' => 0,
            'lastMessage' => '',
            'details' => array(),
        ), $state);
    }

    private static function save_varnish_refresh_ahead_state(array $state)
    {
        $state['lastScanAt'] = max(0, (int) ($state['lastScanAt'] ?? time()));
        $state['candidateCount'] = max(0, (int) ($state['candidateCount'] ?? 0));
        $state['probedCount'] = max(0, (int) ($state['probedCount'] ?? 0));
        $state['eligibleCount'] = max(0, (int) ($state['eligibleCount'] ?? 0));
        $state['queuedCount'] = max(0, (int) ($state['queuedCount'] ?? 0));
        $state['skippedPendingCount'] = max(0, (int) ($state['skippedPendingCount'] ?? 0));
        $state['errorCount'] = max(0, (int) ($state['errorCount'] ?? 0));
        $state['lastMessage'] = sanitize_text_field((string) ($state['lastMessage'] ?? ''));
        $state['details'] = array_slice(is_array($state['details'] ?? null) ? $state['details'] : array(), 0, 10);
        update_option(self::get_varnish_refresh_ahead_state_option_key(), self::sanitize_varnish_result($state), false);
    }

    private static function get_varnish_refresh_ahead_candidate_count()
    {
        if (!class_exists('Ultra_Cache_Engine') || !method_exists('Ultra_Cache_Engine', 'get_hot_page_candidates')) {
            return 0;
        }
        return count(Ultra_Cache_Engine::get_hot_page_candidates(50));
    }

    private static function get_varnish_refresh_ahead_status(array $settings = array())
    {
        $dashboard_settings = empty($settings) ? self::get_dashboard_settings() : $settings;
        $varnish_settings = self::get_varnish_cli_settings();
        $capability = self::get_varnish_refresh_ahead_capability($varnish_settings);
        $analytics_enabled = !empty($dashboard_settings['cacheStatsEnabled']);
        $configured = !empty($dashboard_settings['varnishRefreshAheadEnabled']);
        $strategy = self::get_varnish_invalidation_strategy_status($varnish_settings);
        $soft_effective = 'soft' === (string) ($strategy['effective'] ?? '');
        $available = !empty($dashboard_settings['varnishCliEnabled'])
            && $analytics_enabled
            && !empty($capability['supported'])
            && $soft_effective
            && max(0, (int) ($dashboard_settings['varnishHtmlTtlMinutes'] ?? 0)) > 0;
        $state = self::get_varnish_refresh_ahead_state();

        if (!$analytics_enabled) {
            $message = self::maybe_translate('Refresh ahead requires Cache Statistics so UltraCache can maintain a bounded list of recently observed cacheable pages.');
        } elseif (empty($dashboard_settings['varnishCliEnabled'])) {
            $message = self::maybe_translate('Refresh ahead is inactive because Varnish integration is disabled.');
        } elseif (empty($capability['supported'])) {
            $message = (string) ($capability['message'] ?? '');
        } elseif (!$soft_effective) {
            $message = self::maybe_translate('Refresh ahead requires Automatic or Soft purge + refill as the effective invalidation strategy.');
        } elseif (!$configured) {
            $message = self::maybe_translate('Refresh ahead is available but disabled. It uses bounded UltraCache-observed page activity and never adds a frontend analytics beacon.');
        } else {
            $message = self::maybe_translate('Refresh ahead probes a bounded set of UltraCache-observed pages and soft-purges only verified Varnish HITs whose Age reached the configured percentage of the shared TTL.');
        }

        if (!$analytics_enabled) {
            $status_key = 'analytics-disabled';
        } elseif (empty($dashboard_settings['varnishCliEnabled'])) {
            $status_key = 'varnish-disabled';
        } elseif (empty($capability['supported'])) {
            $status_key = sanitize_key((string) ($capability['status'] ?? 'unavailable'));
        } elseif (!$soft_effective) {
            $status_key = 'soft-strategy-inactive';
        } else {
            $status_key = $configured ? 'active' : 'available';
        }

        return array(
            'configured' => $configured,
            'available' => $available,
            'active' => $configured && $available,
            'status' => $status_key,
            'message' => $message,
            'thresholdPercent' => max(50, min(95, absint($dashboard_settings['varnishRefreshAheadThresholdPercent'] ?? 85))),
            'maxPagesPerRun' => max(1, min(10, absint($dashboard_settings['varnishRefreshAheadMaxPages'] ?? 5))),
            'candidateCount' => $analytics_enabled ? self::get_varnish_refresh_ahead_candidate_count() : 0,
            'capability' => $capability,
            'state' => $state,
            'analyticsEnabled' => $analytics_enabled,
            'softStrategyEffective' => $soft_effective,
        );
    }

    private static function should_keep_varnish_refresh_ahead_cron()
    {
        $settings = self::get_dashboard_settings();
        if (
            empty($settings['varnishRefreshAheadEnabled'])
            || empty($settings['varnishCliEnabled'])
            || empty($settings['cacheStatsEnabled'])
            || max(0, (int) ($settings['varnishHtmlTtlMinutes'] ?? 0)) < 1
        ) {
            return false;
        }

        $varnish_settings = self::get_varnish_cli_settings();
        $capability = self::get_varnish_refresh_ahead_capability($varnish_settings);
        $strategy = self::get_varnish_invalidation_strategy_status($varnish_settings);
        return !empty($capability['supported']) && 'soft' === (string) ($strategy['effective'] ?? '');
    }

    private static function has_pending_varnish_refill_for_url($url)
    {
        global $wpdb;
        $url = esc_url_raw((string) $url);
        if ('' === $url || !($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return false;
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Checks one UltraCache-owned persistent refill row.
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE job_type = %s AND url_hash = %s AND status = %s',
                $table,
                'varnish_refill',
                sha1($url),
                'pending'
            )
        );
        return (int) $count > 0;
    }

    private static function get_varnish_refresh_ahead_probe_bucket()
    {
        $settings = self::get_dashboard_settings();
        $policy = function_exists('ultracache_get_html_variant_policy')
            ? ultracache_get_html_variant_policy($settings)
            : array('buckets' => array('orig'));
        $buckets = array_values(array_intersect(array('avif', 'webp', 'orig'), (array) ($policy['buckets'] ?? array('orig'))));
        return !empty($buckets) ? (string) $buckets[0] : 'orig';
    }

    private static function acquire_varnish_refresh_ahead_lock()
    {
        if (!function_exists('ultracache_acquire_lock')) {
            return '';
        }

        $token = 'refresh-ahead-' . wp_generate_password(20, false, false);
        $payload = array('startedAt' => time());
        return ultracache_acquire_lock('ultracache_varnish_refresh_ahead_v1', $token, 90, $payload) ? $token : '';
    }

    private static function release_varnish_refresh_ahead_lock($token)
    {
        $token = (string) $token;
        if ('' !== $token && function_exists('ultracache_release_lock')) {
            ultracache_release_lock('ultracache_varnish_refresh_ahead_v1', $token);
        }
    }

    private static function maybe_run_varnish_refresh_ahead()
    {
        $status = self::get_varnish_refresh_ahead_status();
        if (empty($status['active'])) {
            return array('ran' => false, 'reason' => (string) ($status['status'] ?? 'inactive'));
        }

        $state = self::get_varnish_refresh_ahead_state();
        $now = time();
        if (($now - max(0, (int) ($state['lastScanAt'] ?? 0))) < self::get_varnish_refresh_ahead_scan_interval()) {
            return array('ran' => false, 'reason' => 'not-due');
        }

        $lock_token = self::acquire_varnish_refresh_ahead_lock();
        if ('' === $lock_token) {
            return array('ran' => false, 'reason' => 'locked');
        }

        try {
            $state = self::get_varnish_refresh_ahead_state();
            $now = time();
            if (($now - max(0, (int) ($state['lastScanAt'] ?? 0))) < self::get_varnish_refresh_ahead_scan_interval()) {
                return array('ran' => false, 'reason' => 'not-due');
            }

            if (self::is_manual_warmup_blocking_cron() || self::has_pending_varnish_queue_rows()) {
                $state['lastScanAt'] = $now;
                $state['lastMessage'] = self::maybe_translate('Refresh-ahead scan skipped because another UltraCache warm or Varnish queue operation has priority.');
                self::save_varnish_refresh_ahead_state($state);
                return array('ran' => false, 'reason' => 'queue-busy');
            }

            $max_pages = max(1, min(10, (int) ($status['maxPagesPerRun'] ?? 5)));
            $candidates = class_exists('Ultra_Cache_Engine') && method_exists('Ultra_Cache_Engine', 'get_hot_page_candidates')
                ? Ultra_Cache_Engine::get_hot_page_candidates($max_pages)
                : array();
            $varnish_settings = self::get_varnish_cli_settings();
            $ttl_seconds = max(1, (int) ($varnish_settings['htmlTtlMinutes'] ?? 0) * MINUTE_IN_SECONDS);
            $threshold_percent = max(50, min(95, (int) ($status['thresholdPercent'] ?? 85)));
            $threshold_seconds = (int) floor($ttl_seconds * ($threshold_percent / 100));
            $bucket = self::get_varnish_refresh_ahead_probe_bucket();
            $engine = method_exists(static::class, 'get_engine_instance') ? self::get_engine_instance() : null;

            $details = array();
            $probed = 0;
            $eligible = 0;
            $queued = 0;
            $skipped_pending = 0;
            $errors = 0;

            foreach ($candidates as $candidate) {
                $url = esc_url_raw((string) ($candidate['url'] ?? ''));
                if ('' === $url || !$engine || !method_exists($engine, 'is_cacheable_local_url') || !$engine->is_cacheable_local_url($url)) {
                    continue;
                }
                if (self::has_pending_varnish_refill_for_url($url)) {
                    ++$skipped_pending;
                    continue;
                }

                ++$probed;
                $probe = self::summarize_varnish_refill_response(self::send_single_varnish_refill_request($url, $bucket, true));
                $age_value = $probe['headers']['age'] ?? null;
                $age = is_numeric($age_value) ? max(0, (int) $age_value) : null;
                $detail = array(
                    'url' => $url,
                    'status' => sanitize_key(strtolower((string) ($probe['status'] ?? 'inconclusive'))),
                    'age' => $age,
                    'thresholdSeconds' => $threshold_seconds,
                    'queued' => false,
                );

                if (empty($probe['success']) || 'HIT' !== strtoupper((string) ($probe['status'] ?? '')) || null === $age) {
                    if (empty($probe['success'])) {
                        ++$errors;
                    }
                    $details[] = $detail;
                    continue;
                }
                if ($age < $threshold_seconds) {
                    $details[] = $detail;
                    continue;
                }

                ++$eligible;
                $prepared = self::prepare_varnish_invalidation_urls(array($url));
                $purge = self::send_varnish_soft_purge_prepared_urls($prepared, 'refresh-ahead');
                if (empty($purge['success'])) {
                    ++$errors;
                    $detail['status'] = 'soft-purge-failed';
                    $details[] = $detail;
                    continue;
                }

                $refill = self::queue_varnish_refill_urls(array($url), 'refresh-ahead');
                if (!empty($refill['queued'])) {
                    ++$queued;
                    $detail['queued'] = true;
                    $detail['status'] = 'queued';
                } else {
                    ++$errors;
                    $detail['status'] = 'refill-queue-failed';
                }
                $details[] = $detail;
            }

            $state = array(
                'lastScanAt' => $now,
                'candidateCount' => count($candidates),
                'probedCount' => $probed,
                'eligibleCount' => $eligible,
                'queuedCount' => $queued,
                'skippedPendingCount' => $skipped_pending,
                'errorCount' => $errors,
                'lastMessage' => self::maybe_translate_sprintf('Refresh ahead probed %1$d candidate(s), found %2$d near expiry, and queued %3$d refill(s).', $probed, $eligible, $queued),
                'details' => $details,
            );
            self::save_varnish_refresh_ahead_state($state);

            if ($queued > 0) {
                self::ensure_cron_warm_events_scheduled(1, true);
            }

            return array_merge(array('ran' => true), $state);
        } finally {
            self::release_varnish_refresh_ahead_lock($lock_token);
        }
    }
}
