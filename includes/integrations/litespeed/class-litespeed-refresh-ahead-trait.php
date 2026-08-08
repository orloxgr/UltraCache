<?php
/**
 * LiteSpeed stale purge and bounded hot-page refresh-ahead orchestration.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_LiteSpeed_Refresh_Ahead_Trait
{
    private static function get_litespeed_refresh_ahead_state_option_key()
    {
        return 'ultracache_litespeed_refresh_ahead_state_v1';
    }

    private static function get_litespeed_refresh_ahead_scan_interval()
    {
        $interval = (int) apply_filters('ultracache_litespeed_refresh_ahead_scan_interval_seconds', 5 * MINUTE_IN_SECONDS);
        return max(MINUTE_IN_SECONDS, min(HOUR_IN_SECONDS, $interval));
    }

    private static function get_default_litespeed_refresh_ahead_state()
    {
        return array(
            'lastScanAt' => 0,
            'candidateCount' => 0,
            'candidateSources' => array(),
            'checkedCount' => 0,
            'eligibleCount' => 0,
            'queuedCount' => 0,
            'skippedPendingCount' => 0,
            'errorCount' => 0,
            'lastMessage' => '',
            'details' => array(),
        );
    }

    private static function get_litespeed_refresh_ahead_activation_contract(array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_dashboard_settings();
        }

        $configured = !empty($settings['liteSpeedRefreshAheadEnabled']);
        $page_cache_enabled = !empty($settings['pageCacheEnabled']);
        $native_enabled = !empty($settings['liteSpeedCacheEnabled']);
        $stale_purge_enabled = !empty($settings['liteSpeedStalePurgeEnabled']);
        $available = $page_cache_enabled && $native_enabled && $stale_purge_enabled;

        if (!$page_cache_enabled) {
            $status = 'page-cache-disabled';
            $message = self::maybe_translate('LiteSpeed refresh ahead requires the UltraCache page cache.');
        } elseif (!$native_enabled) {
            $status = 'litespeed-disabled';
            $message = self::maybe_translate('LiteSpeed refresh ahead requires native LiteSpeed HTML Cache.');
        } elseif (!$stale_purge_enabled) {
            $status = 'stale-purge-disabled';
            $message = self::maybe_translate('LiteSpeed refresh ahead requires stale exact-URL purge so the old object can remain available during regeneration.');
        } elseif (!$configured) {
            $status = 'available';
            $message = self::maybe_translate('LiteSpeed refresh ahead is available but disabled.');
        } else {
            $status = 'active';
            $message = self::maybe_translate('LiteSpeed refresh ahead is active. It uses UltraCache freshness markers and the existing persistent page-warm queue.');
        }

        return array(
            'configured' => $configured,
            'available' => $available,
            'active' => $configured && $available,
            'status' => $status,
            'message' => $message,
            'stalePurgeEnabled' => $stale_purge_enabled,
        );
    }

    private static function is_litespeed_refresh_ahead_runtime_active()
    {
        $contract = self::get_litespeed_refresh_ahead_activation_contract();
        return !empty($contract['active']);
    }

    private static function get_litespeed_refresh_ahead_state()
    {
        $state = get_option(self::get_litespeed_refresh_ahead_state_option_key(), array());
        return array_merge(
            self::get_default_litespeed_refresh_ahead_state(),
            is_array($state) ? $state : array()
        );
    }

    private static function save_litespeed_refresh_ahead_state(array $state)
    {
        $state['lastScanAt'] = max(0, (int) ($state['lastScanAt'] ?? time()));
        $state['candidateCount'] = max(0, (int) ($state['candidateCount'] ?? 0));
        $state['candidateSources'] = array_slice(is_array($state['candidateSources'] ?? null) ? $state['candidateSources'] : array(), 0, 12, true);
        $clean_sources = array();
        foreach ($state['candidateSources'] as $source => $count) {
            $source = sanitize_key((string) $source);
            if ('' !== $source) {
                $clean_sources[$source] = max(0, (int) $count);
            }
        }
        $state['candidateSources'] = $clean_sources;
        foreach (array('checkedCount', 'eligibleCount', 'queuedCount', 'skippedPendingCount', 'errorCount') as $key) {
            $state[$key] = max(0, (int) ($state[$key] ?? 0));
        }
        $state['lastMessage'] = sanitize_text_field((string) ($state['lastMessage'] ?? ''));
        $state['details'] = array_slice(is_array($state['details'] ?? null) ? $state['details'] : array(), 0, 10);
        update_option(self::get_litespeed_refresh_ahead_state_option_key(), $state, false);
    }

    private static function get_litespeed_refresh_ahead_thresholds(array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_dashboard_settings();
        }
        $ttl_seconds = max(MINUTE_IN_SECONDS, absint($settings['cacheFreshTtlMinutes'] ?? 1440) * MINUTE_IN_SECONDS);
        $threshold_percent = max(50, min(95, absint($settings['liteSpeedRefreshAheadThresholdPercent'] ?? 85)));
        return array(
            'ttlSeconds' => $ttl_seconds,
            'thresholdPercent' => $threshold_percent,
            'thresholdSeconds' => max(1, (int) floor($ttl_seconds * ($threshold_percent / 100))),
        );
    }

    private static function get_litespeed_refresh_ahead_status(array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_dashboard_settings();
        }
        $contract = self::get_litespeed_refresh_ahead_activation_contract($settings);
        $active = !empty($contract['active']);
        $summary = $active && method_exists(static::class, 'get_litespeed_refresh_candidate_summary')
            ? self::get_litespeed_refresh_candidate_summary()
            : array('count' => 0, 'sources' => array());
        $thresholds = self::get_litespeed_refresh_ahead_thresholds($settings);

        return array(
            'configured' => !empty($contract['configured']),
            'available' => !empty($contract['available']),
            'active' => $active,
            'status' => sanitize_key((string) ($contract['status'] ?? 'unavailable')),
            'message' => (string) ($contract['message'] ?? ''),
            'stalePurgeEnabled' => !empty($contract['stalePurgeEnabled']),
            'thresholdPercent' => (int) $thresholds['thresholdPercent'],
            'thresholdSeconds' => (int) $thresholds['thresholdSeconds'],
            'maxPagesPerRun' => max(1, min(10, absint($settings['liteSpeedRefreshAheadMaxPages'] ?? 5))),
            'candidateCount' => max(0, (int) ($summary['count'] ?? 0)),
            'candidateSources' => is_array($summary['sources'] ?? null) ? $summary['sources'] : array(),
            'analyticsEnabled' => $active && !empty($settings['cacheStatsEnabled']),
            'state' => $active ? self::get_litespeed_refresh_ahead_state() : self::get_default_litespeed_refresh_ahead_state(),
        );
    }

    private static function should_keep_litespeed_refresh_ahead_cron()
    {
        return self::is_litespeed_refresh_ahead_runtime_active();
    }

    private static function acquire_litespeed_refresh_ahead_lock()
    {
        if (!function_exists('ultracache_acquire_lock')) {
            return '';
        }
        $token = 'litespeed-refresh-ahead-' . wp_generate_password(20, false, false);
        return ultracache_acquire_lock(
            'ultracache_litespeed_refresh_ahead_v1',
            $token,
            90,
            array('startedAt' => time())
        ) ? $token : '';
    }

    private static function release_litespeed_refresh_ahead_lock($token)
    {
        $token = (string) $token;
        if ('' !== $token && function_exists('ultracache_release_lock')) {
            ultracache_release_lock('ultracache_litespeed_refresh_ahead_v1', $token);
        }
    }

    private static function has_pending_litespeed_refresh_for_url($url)
    {
        global $wpdb;
        $url = esc_url_raw((string) $url);
        if ('' === $url || !($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return false;
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Checks one UltraCache-owned persistent page-warm row.
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE job_type = %s AND url_hash = %s AND status IN (%s, %s)',
                $table,
                'page_warm',
                sha1($url),
                'pending',
                'processing'
            )
        );
        return (int) $count > 0;
    }

    /**
     * Read the age of the UltraCache HTML files that LSCache publishes.
     *
     * @param string $url Exact local public URL.
     * @return array<string,mixed>
     */
    private static function get_litespeed_local_freshness_status($url)
    {
        $url = self::normalize_litespeed_refresh_candidate_url($url);
        $engine = method_exists(static::class, 'get_engine_instance') ? self::get_engine_instance() : null;
        if ('' === $url || !$engine || !method_exists($engine, 'get_cache_path') || !method_exists($engine, 'is_cacheable_local_url') || !$engine->is_cacheable_local_url($url)) {
            return array(
                'success' => false,
                'eligible' => false,
                'message' => self::maybe_translate('The LiteSpeed refresh-ahead candidate is not an eligible local page-cache URL.'),
                'url' => $url,
                'buckets' => array(),
            );
        }

        $thresholds = self::get_litespeed_refresh_ahead_thresholds();
        $buckets = method_exists(static::class, 'get_litespeed_refill_buckets')
            ? self::get_litespeed_refill_buckets()
            : array('orig');
        $now = time();
        $rows = array();
        $oldest_age = 0;
        $missing = array();

        foreach ($buckets as $bucket) {
            $bucket = in_array((string) $bucket, array('orig', 'webp', 'avif'), true) ? (string) $bucket : 'orig';
            $path = (string) $engine->get_cache_path($url, $bucket);
            $cache_root = defined('ULTRACACHE_CACHE_DIR') ? ultracache_normalize_filesystem_path_for_guard(ULTRACACHE_CACHE_DIR) : '';
            $guarded_path = function_exists('ultracache_normalize_filesystem_path_for_guard')
                ? ultracache_normalize_filesystem_path_for_guard($path)
                : $path;
            $valid_path = '' !== $cache_root
                && '' !== $guarded_path
                && function_exists('ultracache_path_is_within_root')
                && ultracache_path_is_within_root($guarded_path, $cache_root);
            $mtime = false;
            if ($valid_path && is_readable($guarded_path . '.fresh')) {
                $mtime = ultracache_safe_filemtime($guarded_path . '.fresh', 'litespeed_refresh_ahead_freshness_marker');
            }
            if (false === $mtime && $valid_path && is_readable($guarded_path)) {
                $mtime = ultracache_safe_filemtime($guarded_path, 'litespeed_refresh_ahead_cache_file');
            }
            $age = false !== $mtime && (int) $mtime > 0 ? max(0, $now - (int) $mtime) : null;
            if (null === $age) {
                $missing[] = $bucket;
            } else {
                $oldest_age = max($oldest_age, $age);
            }
            $rows[] = array(
                'bucket' => $bucket,
                'exists' => null !== $age,
                'age' => $age,
            );
        }

        $eligible = !empty($missing) || $oldest_age >= (int) $thresholds['thresholdSeconds'];
        return array(
            'success' => true,
            'eligible' => $eligible,
            'url' => $url,
            'oldestAge' => $oldest_age,
            'missingBuckets' => $missing,
            'thresholdSeconds' => (int) $thresholds['thresholdSeconds'],
            'ttlSeconds' => (int) $thresholds['ttlSeconds'],
            'buckets' => $rows,
            'message' => $eligible
                ? self::maybe_translate('The local UltraCache HTML is missing or has reached the LiteSpeed refresh-ahead threshold.')
                : self::maybe_translate('The local UltraCache HTML remains below the LiteSpeed refresh-ahead threshold.'),
        );
    }

    private static function calculate_litespeed_refresh_ahead_next_check(array $freshness, $queued, $check_count = 0)
    {
        $now = time();
        $scan_interval = self::get_litespeed_refresh_ahead_scan_interval();
        $threshold = max(1, (int) ($freshness['thresholdSeconds'] ?? $scan_interval));
        if ($queued) {
            return $now + max($scan_interval, $threshold);
        }
        if (!empty($freshness['success'])) {
            if (!empty($freshness['eligible'])) {
                return $now + $scan_interval;
            }
            $age = max(0, (int) ($freshness['oldestAge'] ?? 0));
            return $now + max($scan_interval, $threshold - min($threshold, $age));
        }
        $multiplier = max(1, min(6, (int) $check_count + 1));
        return $now + ($scan_interval * $multiplier);
    }

    /**
     * Recheck and stale-purge a claimed refresh-ahead row before regeneration.
     *
     * @param string        $url       Exact local page URL.
     * @param array         $row       Claimed queue row.
     * @param callable|null $heartbeat Queue lease heartbeat.
     * @return array<string,mixed>
     */
    private static function prepare_litespeed_refresh_ahead_page_warm($url, array $row, $heartbeat = null)
    {
        unset($row);
        if (!self::is_litespeed_refresh_ahead_runtime_active()) {
            return array(
                'proceed' => false,
                'skipped' => true,
                'retryable' => false,
                'message' => self::maybe_translate('LiteSpeed refresh ahead is no longer active.'),
            );
        }

        $freshness = self::get_litespeed_local_freshness_status($url);
        if (empty($freshness['success'])) {
            return array(
                'proceed' => false,
                'skipped' => false,
                'retryable' => true,
                'message' => (string) ($freshness['message'] ?? self::maybe_translate('LiteSpeed refresh-ahead freshness recheck failed.')),
                'freshness' => $freshness,
            );
        }
        if (empty($freshness['eligible'])) {
            return array(
                'proceed' => false,
                'skipped' => true,
                'retryable' => false,
                'message' => self::maybe_translate('The page is no longer near the UltraCache freshness threshold.'),
                'freshness' => $freshness,
            );
        }

        if (is_callable($heartbeat) && false === call_user_func($heartbeat, 'litespeed-refresh-ahead-stale-purge-before')) {
            return array(
                'proceed' => false,
                'skipped' => false,
                'retryable' => true,
                'ownershipLost' => true,
                'message' => self::maybe_translate('The queue lease was lost before LiteSpeed stale purge.'),
                'freshness' => $freshness,
            );
        }

        $purge = self::purge_litespeed_urls(array($url), true, 'refresh-ahead-stale-purge');
        if (is_callable($heartbeat) && false === call_user_func($heartbeat, 'litespeed-refresh-ahead-stale-purge-after')) {
            return array(
                'proceed' => false,
                'skipped' => false,
                'retryable' => true,
                'ownershipLost' => true,
                'message' => self::maybe_translate('The queue lease was lost after LiteSpeed stale purge.'),
                'freshness' => $freshness,
                'purge' => $purge,
            );
        }

        if (empty($purge['success'])) {
            return array(
                'proceed' => false,
                'skipped' => false,
                'retryable' => method_exists(static::class, 'is_litespeed_purge_failure_retryable')
                    ? self::is_litespeed_purge_failure_retryable($purge)
                    : true,
                'message' => (string) ($purge['message'] ?? self::maybe_translate('LiteSpeed refresh-ahead stale purge failed.')),
                'freshness' => $freshness,
                'purge' => $purge,
            );
        }

        return array(
            'proceed' => true,
            'skipped' => false,
            'retryable' => false,
            'message' => self::maybe_translate('LiteSpeed stale purge completed; the shared page pipeline can rebuild and refill the page.'),
            'freshness' => $freshness,
            'purge' => $purge,
        );
    }

    private static function maybe_run_litespeed_refresh_ahead()
    {
        $status = self::get_litespeed_refresh_ahead_status();
        if (empty($status['active'])) {
            return array('ran' => false, 'reason' => (string) ($status['status'] ?? 'inactive'));
        }

        $state = self::get_litespeed_refresh_ahead_state();
        $now = time();
        if (($now - max(0, (int) ($state['lastScanAt'] ?? 0))) < self::get_litespeed_refresh_ahead_scan_interval()) {
            return array('ran' => false, 'reason' => 'not-due');
        }

        $lock_token = self::acquire_litespeed_refresh_ahead_lock();
        if ('' === $lock_token) {
            return array('ran' => false, 'reason' => 'locked');
        }

        try {
            $state = self::get_litespeed_refresh_ahead_state();
            $now = time();
            if (($now - max(0, (int) ($state['lastScanAt'] ?? 0))) < self::get_litespeed_refresh_ahead_scan_interval()) {
                return array('ran' => false, 'reason' => 'not-due');
            }

            $pending_targeted = method_exists(static::class, 'count_pending_targeted_page_warm_queue_rows')
                ? self::count_pending_targeted_page_warm_queue_rows()
                : 0;
            if (self::is_manual_warmup_blocking_cron() || $pending_targeted > 0) {
                $state['lastScanAt'] = $now;
                $state['lastMessage'] = self::maybe_translate('LiteSpeed refresh-ahead scan skipped because another UltraCache warm operation has priority.');
                self::save_litespeed_refresh_ahead_state($state);
                return array('ran' => false, 'reason' => 'queue-busy');
            }

            $max_pages = max(1, min(10, (int) ($status['maxPagesPerRun'] ?? 5)));
            $selection_limit = max($max_pages, min(50, $max_pages * 4));
            $candidates = self::get_litespeed_refresh_ahead_candidates($selection_limit, true);
            $details = array();
            $updates = array();
            $eligible_urls = array();
            $checked = 0;
            $eligible = 0;
            $queued = 0;
            $skipped_pending = 0;
            $errors = 0;

            foreach ($candidates as $candidate) {
                if ($checked >= $max_pages) {
                    break;
                }
                $url = self::normalize_litespeed_refresh_candidate_url($candidate['url'] ?? '');
                if ('' === $url) {
                    continue;
                }
                if (self::has_pending_litespeed_refresh_for_url($url)) {
                    ++$skipped_pending;
                    $updates[] = array(
                        'url' => $url,
                        'incrementProbe' => false,
                        'lastResult' => 'pending',
                        'nextProbeAt' => $now + self::get_litespeed_refresh_ahead_scan_interval(),
                    );
                    continue;
                }

                ++$checked;
                $freshness = self::get_litespeed_local_freshness_status($url);
                $is_eligible = !empty($freshness['success']) && !empty($freshness['eligible']);
                if ($is_eligible) {
                    ++$eligible;
                    $eligible_urls[$url] = $url;
                } elseif (empty($freshness['success'])) {
                    ++$errors;
                }

                $updates[] = array(
                    'url' => $url,
                    'incrementProbe' => true,
                    'lastProbedAt' => $now,
                    'lastProbeAge' => isset($freshness['oldestAge']) ? max(0, (int) $freshness['oldestAge']) : null,
                    'lastResult' => $is_eligible ? 'eligible' : (!empty($freshness['success']) ? 'fresh' : 'error'),
                    'nextProbeAt' => self::calculate_litespeed_refresh_ahead_next_check(
                        $freshness,
                        false,
                        max(0, (int) ($candidate['probeCount'] ?? 0))
                    ),
                );
                $details[] = array(
                    'url' => $url,
                    'status' => $is_eligible ? 'eligible' : (!empty($freshness['success']) ? 'fresh' : 'error'),
                    'age' => isset($freshness['oldestAge']) ? max(0, (int) $freshness['oldestAge']) : null,
                    'thresholdSeconds' => max(0, (int) ($freshness['thresholdSeconds'] ?? 0)),
                    'missingBuckets' => array_values((array) ($freshness['missingBuckets'] ?? array())),
                    'sources' => array_slice(array_values(array_map('sanitize_key', (array) ($candidate['sources'] ?? array()))), 0, 8),
                    'queued' => false,
                );
            }

            self::record_litespeed_refresh_candidate_probe_updates($updates);

            if (!empty($eligible_urls)) {
                $queue_result = self::enqueue_targeted_warm_pipeline_urls(
                    array_values($eligible_urls),
                    true,
                    'litespeed-refresh-ahead'
                );
                $queued_map = array();
                foreach ((array) ($queue_result['queuedUrls'] ?? array()) as $queued_url) {
                    $queued_url = self::normalize_litespeed_refresh_candidate_url($queued_url);
                    if (isset($eligible_urls[$queued_url])) {
                        $queued_map[$queued_url] = true;
                    }
                }
                $queued = count($queued_map);
                $errors += max(0, count($eligible_urls) - $queued);
                $queue_updates = array();
                foreach ($details as &$detail) {
                    $detail_url = (string) ($detail['url'] ?? '');
                    if (!isset($eligible_urls[$detail_url])) {
                        continue;
                    }
                    $was_queued = isset($queued_map[$detail_url]);
                    $detail['queued'] = $was_queued;
                    $detail['status'] = $was_queued ? 'queued' : 'queue-failed';
                    $queue_updates[] = array(
                        'url' => $detail_url,
                        'incrementProbe' => false,
                        'lastResult' => $was_queued ? 'queued' : 'queue-failed',
                        'nextProbeAt' => $was_queued
                            ? $now + max(self::get_litespeed_refresh_ahead_scan_interval(), (int) ($status['thresholdSeconds'] ?? 0))
                            : $now + self::get_litespeed_refresh_ahead_scan_interval(),
                    );
                }
                unset($detail);
                self::record_litespeed_refresh_candidate_probe_updates($queue_updates);
            }

            $summary = self::get_litespeed_refresh_candidate_summary();
            $state = array(
                'lastScanAt' => $now,
                'candidateCount' => max(count($candidates), (int) ($summary['count'] ?? 0)),
                'candidateSources' => is_array($summary['sources'] ?? null) ? $summary['sources'] : array(),
                'checkedCount' => $checked,
                'eligibleCount' => $eligible,
                'queuedCount' => $queued,
                'skippedPendingCount' => $skipped_pending,
                'errorCount' => $errors,
                'lastMessage' => self::maybe_translate_sprintf(
                    'LiteSpeed refresh ahead checked %1$d due candidate(s), found %2$d at the UltraCache freshness threshold, and queued %3$d shared page job(s).',
                    $checked,
                    $eligible,
                    $queued
                ),
                'details' => $details,
            );
            self::save_litespeed_refresh_ahead_state($state);
            if (
                $queued > 0
                && !self::is_manual_warmup_blocking_cron()
                && self::get_shared_automation_pages_per_minute() > 0
            ) {
                self::ensure_cron_warm_events_scheduled(1);
            }
            return array_merge(array('ran' => true), $state);
        } finally {
            self::release_litespeed_refresh_ahead_lock($lock_token);
        }
    }
}
