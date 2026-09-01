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

    private static function get_varnish_refresh_ahead_scan_interval()
    {
        $interval = (int) apply_filters('ultracache_varnish_refresh_ahead_scan_interval_seconds', 5 * MINUTE_IN_SECONDS);
        return max(MINUTE_IN_SECONDS, min(HOUR_IN_SECONDS, $interval));
    }

    private static function get_default_varnish_refresh_ahead_state()
    {
        return array(
            'lastScanAt' => 0,
            'candidateCount' => 0,
            'candidateSources' => array(),
            'probedCount' => 0,
            'eligibleCount' => 0,
            'queuedCount' => 0,
            'skippedPendingCount' => 0,
            'skippedExcludedCount' => 0,
            'errorCount' => 0,
            'lastMessage' => '',
            'probeBucketIndex' => 0,
            'lastProbeBucket' => '',
            'details' => array(),
        );
    }

    /**
     * Return the portable runtime contract for refresh-ahead.
     *
     * Refresh-ahead is operational only when the administrator enabled it,
     * HTTP endpoint mode is active, the current HTTP contract has verified
     * soft purge, and stale-while-revalidate is configured. No topology or
     * public-header probe participates in this runtime decision.
     *
     * @param array $dashboard_settings Optional dashboard settings.
     * @param array $varnish_settings   Optional normalized Varnish settings.
     * @return array<string,mixed>
     */
    private static function get_varnish_refresh_ahead_activation_contract(array $dashboard_settings = array(), array $varnish_settings = array())
    {
        if (empty($dashboard_settings)) {
            $dashboard_settings = self::get_dashboard_settings();
        }
        if (empty($varnish_settings)) {
            $varnish_settings = self::get_varnish_cli_settings();
        }

        $automation = self::get_varnish_automation_policy($dashboard_settings);
        $configured = !empty($automation['refreshAheadEnabled']);
        $varnish_enabled = self::is_varnish_runtime_enabled($dashboard_settings);
        $http_mode = 'http' === (string) ($varnish_settings['mode'] ?? 'http');
        $stale_seconds = max(0, min(86400, absint($automation['staleWhileRevalidateSeconds'] ?? 0)));
        $soft_capability = array(
            'supported' => false,
            'status' => $http_mode ? 'soft-purge-unverified' : 'http-only',
            'message' => '',
        );
        $soft_effective = false;
        if ($varnish_enabled && $http_mode && $stale_seconds > 0) {
            $strategy = self::get_varnish_invalidation_strategy_status($varnish_settings);
            $soft_capability = is_array($strategy['softCapability'] ?? null)
                ? $strategy['softCapability']
                : self::get_varnish_soft_purge_capability($varnish_settings);
            $soft_effective = 'soft' === (string) ($strategy['effective'] ?? '');
        }
        $available = $varnish_enabled
            && $http_mode
            && $stale_seconds > 0
            && !empty($soft_capability['supported'])
            && $soft_effective;

        if (!$varnish_enabled) {
            $status = 'varnish-disabled';
            $message = self::maybe_translate('Refresh ahead is inactive because Varnish integration is disabled.');
        } elseif (!$http_mode) {
            $status = 'unavailable-current-mode';
            $message = self::maybe_translate('Refresh ahead is unavailable in the current mode. It requires HTTP endpoint mode with verified soft purge and stale-while-revalidate.');
        } elseif ($stale_seconds < 1) {
            $status = 'swr-disabled';
            $message = self::maybe_translate('Refresh ahead requires a positive stale-while-revalidate value.');
        } elseif (empty($soft_capability['supported'])) {
            $status = sanitize_key((string) ($soft_capability['status'] ?? 'soft-purge-unverified'));
            $message = (string) ($soft_capability['message'] ?? self::maybe_translate('Refresh ahead requires verified HTTP soft purge.'));
        } elseif (!$soft_effective) {
            $status = 'soft-strategy-inactive';
            $message = self::maybe_translate('Refresh ahead requires Automatic or Soft purge + refill as the effective invalidation strategy.');
        } elseif (!$configured) {
            $status = 'automation-inactive';
            $message = self::maybe_translate('Refresh ahead follows Automation & Scheduling and becomes active when scheduled warm-up and stale refresh are both active.');
        } else {
            $status = 'active';
            $message = self::maybe_translate('Refresh ahead is active. Its scanner collects bounded candidates, probes due pages, and queues the shared page-warm pipeline.');
        }

        return array(
            'configured' => $configured,
            'available' => $available,
            'active' => $configured && $available,
            'status' => $status,
            'message' => $message,
            'httpMode' => $http_mode,
            'staleWhileRevalidateSeconds' => $stale_seconds,
            'softStrategyEffective' => $soft_effective,
            'softPurgeCapability' => $soft_capability,
        );
    }

    private static function is_varnish_refresh_ahead_runtime_active()
    {
        $contract = self::get_varnish_refresh_ahead_activation_contract();
        return !empty($contract['active']);
    }

    private static function get_varnish_refresh_ahead_state()
    {
        $state = get_option(self::get_varnish_refresh_ahead_state_option_key(), array());
        if (!is_array($state)) {
            $state = array();
        }

        return array_merge(self::get_default_varnish_refresh_ahead_state(), $state);
    }

    private static function save_varnish_refresh_ahead_state(array $state)
    {
        $state['lastScanAt'] = max(0, (int) ($state['lastScanAt'] ?? time()));
        $state['candidateCount'] = max(0, (int) ($state['candidateCount'] ?? 0));
        $state['candidateSources'] = array_slice(is_array($state['candidateSources'] ?? null) ? $state['candidateSources'] : array(), 0, 12, true);
        foreach ($state['candidateSources'] as $source => $count) {
            $clean_source = sanitize_key((string) $source);
            if ('' === $clean_source) {
                unset($state['candidateSources'][$source]);
                continue;
            }
            if ($clean_source !== $source) {
                unset($state['candidateSources'][$source]);
            }
            $state['candidateSources'][$clean_source] = max(0, (int) $count);
        }
        $state['probedCount'] = max(0, (int) ($state['probedCount'] ?? 0));
        $state['eligibleCount'] = max(0, (int) ($state['eligibleCount'] ?? 0));
        $state['queuedCount'] = max(0, (int) ($state['queuedCount'] ?? 0));
        $state['skippedPendingCount'] = max(0, (int) ($state['skippedPendingCount'] ?? 0));
        $state['skippedExcludedCount'] = max(0, (int) ($state['skippedExcludedCount'] ?? 0));
        $state['errorCount'] = max(0, (int) ($state['errorCount'] ?? 0));
        $state['lastMessage'] = sanitize_text_field((string) ($state['lastMessage'] ?? ''));
        $state['probeBucketIndex'] = max(0, (int) ($state['probeBucketIndex'] ?? 0));
        $state['lastProbeBucket'] = in_array((string) ($state['lastProbeBucket'] ?? ''), array('orig', 'webp', 'avif'), true)
            ? (string) $state['lastProbeBucket']
            : '';
        $state['details'] = array_slice(is_array($state['details'] ?? null) ? $state['details'] : array(), 0, 10);
        update_option(self::get_varnish_refresh_ahead_state_option_key(), self::sanitize_varnish_result($state), false);
    }

    private static function get_varnish_refresh_ahead_status(array $settings = array())
    {
        $dashboard_settings = empty($settings) ? self::get_dashboard_settings() : $settings;
        $varnish_settings = self::get_varnish_cli_settings();
        $automation = self::get_varnish_automation_policy($dashboard_settings);
        $contract = self::get_varnish_refresh_ahead_activation_contract($dashboard_settings, $varnish_settings);
        $active = !empty($contract['active']);
        $candidate_summary = $active && method_exists(static::class, 'get_varnish_refresh_candidate_summary')
            ? self::get_varnish_refresh_candidate_summary()
            : array('count' => 0, 'sources' => array());

        return array(
            'configured' => !empty($contract['configured']),
            'available' => !empty($contract['available']),
            'active' => $active,
            'status' => sanitize_key((string) ($contract['status'] ?? 'unavailable')),
            'message' => (string) ($contract['message'] ?? ''),
            'thresholdPercent' => max(50, min(95, absint($automation['refreshAheadThresholdPercent'] ?? 85))),
            'maxPagesPerRun' => max(1, min(10, absint($automation['refreshAheadMaxPages'] ?? 5))),
            'candidateCount' => max(0, (int) ($candidate_summary['count'] ?? 0)),
            'candidateSources' => is_array($candidate_summary['sources'] ?? null) ? $candidate_summary['sources'] : array(),
            'capability' => is_array($contract['softPurgeCapability'] ?? null) ? $contract['softPurgeCapability'] : array(),
            'state' => $active ? self::get_varnish_refresh_ahead_state() : self::get_default_varnish_refresh_ahead_state(),
            'analyticsEnabled' => $active && !empty($dashboard_settings['cacheStatsEnabled']),
            'softStrategyEffective' => !empty($contract['softStrategyEffective']),
            'httpMode' => !empty($contract['httpMode']),
            'staleWhileRevalidateSeconds' => max(0, (int) ($contract['staleWhileRevalidateSeconds'] ?? 0)),
        );
    }

    private static function should_keep_varnish_refresh_ahead_cron()
    {
        return self::is_varnish_refresh_ahead_runtime_active();
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

    private static function get_varnish_refresh_ahead_probe_buckets()
    {
        $settings = self::get_dashboard_settings();
        $policy = function_exists('ultracache_get_html_variant_policy')
            ? ultracache_get_html_variant_policy($settings)
            : array('buckets' => array('orig'));
        $buckets = array_values(array_intersect(array('orig', 'webp', 'avif'), (array) ($policy['buckets'] ?? array('orig'))));
        return !empty($buckets) ? $buckets : array('orig');
    }

    /**
     * Select one active HTML bucket per scan and advance the bounded cursor.
     *
     * @param array $state Refresh-ahead state, updated by reference.
     * @return string
     */
    private static function select_varnish_refresh_ahead_probe_bucket(array &$state)
    {
        $buckets = self::get_varnish_refresh_ahead_probe_buckets();
        $index = max(0, (int) ($state['probeBucketIndex'] ?? 0));
        $bucket = (string) $buckets[$index % count($buckets)];
        $state['probeBucketIndex'] = ($index + 1) % count($buckets);
        $state['lastProbeBucket'] = $bucket;
        return $bucket;
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

    /**
     * Return an anonymous public request contract for one refresh-ahead Age probe.
     *
     * The request intentionally carries no UltraCache internal markers or runtime
     * token, so it observes the same public Varnish path as an anonymous browser.
     *
     * @param string $bucket Active HTML variant bucket.
     * @return array
     */
    private static function get_varnish_refresh_ahead_probe_request_args($bucket)
    {
        $bucket = in_array((string) $bucket, array('orig', 'webp', 'avif'), true) ? (string) $bucket : 'orig';
        $accept = function_exists('ultracache_get_accept_header_for_html_bucket')
            ? ultracache_get_accept_header_for_html_bucket($bucket)
            : 'text/html,application/xhtml+xml';

        return array(
            'method' => 'GET',
            'timeout' => function_exists('ultracache_get_php_max_execution_time_seconds')
                ? ultracache_get_php_max_execution_time_seconds()
                : max(0, (int) ini_get('max_execution_time')),
            'redirection' => 0,
            'limit_response_size' => 1024,
            'user-agent' => 'Mozilla/5.0 (compatible; UltraCache-Refresh-Probe/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : 'unknown') . '; +https://wordpress.org)',
            'headers' => array(
                'Accept' => $accept,
            ),
            'cookies' => array(),
        );
    }

    /**
     * Send one exact anonymous public refresh-ahead probe.
     *
     * @param string $url    Exact local frontend URL.
     * @param string $bucket Active HTML variant bucket.
     * @return array|WP_Error
     */
    private static function send_varnish_refresh_ahead_probe_request($url, $bucket)
    {
        $url = esc_url_raw((string) $url);
        if ('' === $url
            || !function_exists('ultracache_is_strict_frontend_loopback_url')
            || !ultracache_is_strict_frontend_loopback_url($url)) {
            return new WP_Error(
                'ultracache_invalid_varnish_refresh_probe_url',
                self::maybe_translate('The refresh-ahead probe URL is not an exact trusted frontend URL for this site.')
            );
        }

        $args = self::get_varnish_refresh_ahead_probe_request_args($bucket);
        return function_exists('ultracache_safe_loopback_remote_request')
            ? ultracache_safe_loopback_remote_request($url, $args, 'varnish_refresh_probe')
            : wp_safe_remote_get($url, $args);
    }

    /**
     * Return shared TTL and Age-threshold values for refresh-ahead decisions.
     *
     * @param array $status Optional dashboard status.
     * @return array
     */
    private static function get_varnish_refresh_ahead_thresholds(array $status = array())
    {
        if (empty($status)) {
            $status = self::get_varnish_refresh_ahead_status();
        }
        $settings = self::get_varnish_cli_settings();
        $ttl_seconds = max(1, (int) ($settings['htmlTtlMinutes'] ?? 0) * MINUTE_IN_SECONDS);
        $threshold_percent = max(50, min(95, (int) ($status['thresholdPercent'] ?? 85)));
        return array(
            'ttlSeconds' => $ttl_seconds,
            'thresholdPercent' => $threshold_percent,
            'thresholdSeconds' => max(1, (int) floor($ttl_seconds * ($threshold_percent / 100))),
        );
    }

    /**
     * Calculate the shared bounded retry backoff used by transient public-probe
     * and durable queue failures.
     *
     * @param int $probe_count Previous probe count.
     * @return int
     */
    private static function calculate_varnish_refresh_ahead_retry_backoff_at($probe_count = 0)
    {
        $multiplier = max(1, min(6, (int) $probe_count + 1));
        return time() + (self::get_varnish_refresh_ahead_scan_interval() * $multiplier);
    }

    /**
     * Return the current scanner priority/capacity gate.
     *
     * Ordinary shared page-refill rows do not block discovery. Each candidate
     * still checks its own pending refill before probing, while foreground
     * ownership, invalidation ordering, and a paused global background rate
     * remain hard gates.
     *
     * @return array<string,mixed>
     */
    private static function get_varnish_refresh_ahead_scan_gate()
    {
        if (self::is_manual_warmup_blocking_cron()) {
            return array(
                'allowed' => false,
                'reason' => 'foreground-priority',
                'message' => self::maybe_translate('Refresh-ahead scan yielded to the active foreground warm-up owner.'),
            );
        }
        if (self::has_pending_varnish_invalidation_rows()) {
            return array(
                'allowed' => false,
                'reason' => 'invalidation-pending',
                'message' => self::maybe_translate('Refresh-ahead scan yielded while a Varnish invalidation is pending or processing.'),
            );
        }
        if (self::get_shared_automation_pages_per_minute() < 1) {
            return array(
                'allowed' => false,
                'reason' => 'background-rate-paused',
                'message' => self::maybe_translate('Refresh-ahead scan is paused because the shared background page rate is zero.'),
            );
        }

        return array(
            'allowed' => true,
            'reason' => 'ready',
            'message' => '',
        );
    }

    /**
     * Calculate a bounded next-probe timestamp from one public observation.
     *
     * @param array    $probe             Normalized public response.
     * @param int|null $age               Numeric Age when available.
     * @param int      $threshold_seconds Shared threshold in seconds.
     * @param bool     $queued            Whether durable work was accepted.
     * @param int      $probe_count       Previous probe count.
     * @return int
     */
    private static function calculate_varnish_refresh_ahead_next_probe_at(array $probe, $age, $threshold_seconds, $queued, $probe_count = 0)
    {
        $now = time();
        $scan_interval = self::get_varnish_refresh_ahead_scan_interval();
        $status = strtoupper((string) ($probe['status'] ?? 'INCONCLUSIVE'));
        if ($queued) {
            return $now + max($scan_interval, $threshold_seconds);
        }
        if (!empty($probe['success']) && 'HIT' === $status && null !== $age) {
            return $now + max($scan_interval, $threshold_seconds - min($threshold_seconds, (int) $age));
        }
        if (!empty($probe['success']) && 'MISS' === $status) {
            return $now + max($scan_interval, $threshold_seconds);
        }
        if (empty($probe['success']) && self::is_varnish_refill_failure_retryable($probe)) {
            return self::calculate_varnish_refresh_ahead_retry_backoff_at($probe_count);
        }
        return $now + max(15 * MINUTE_IN_SECONDS, $scan_interval);
    }

    /**
     * Prepare an already-claimed refresh-ahead page job before origin rebuild.
     *
     * A durable queue row already exists at this point. The worker rechecks the
     * public object, persists partial endpoint retry state, performs soft purge,
     * and only then allows the strict origin-refresh/public-refill pipeline.
     *
     * @param string        $url       Exact local page URL.
     * @param array         $row       Claimed queue row.
     * @param callable|null $heartbeat Queue lease heartbeat.
     * @return array
     */
    private static function prepare_varnish_refresh_ahead_page_warm($url, array $row, $heartbeat = null)
    {
        $status = self::get_varnish_refresh_ahead_status();
        if (empty($status['active'])) {
            return array(
                'proceed' => false,
                'skipped' => true,
                'retryable' => false,
                'message' => self::maybe_translate('Refresh ahead became inactive before the queued page job was claimed.'),
            );
        }

        $url = esc_url_raw((string) $url);
        $thresholds = self::get_varnish_refresh_ahead_thresholds($status);
        $threshold_seconds = (int) $thresholds['thresholdSeconds'];
        $bucket = method_exists(static::class, 'get_varnish_refresh_candidate_probe_bucket')
            ? self::get_varnish_refresh_candidate_probe_bucket($url)
            : 'orig';
        if (!in_array($bucket, self::get_varnish_refresh_ahead_probe_buckets(), true)) {
            $bucket = 'orig';
        }

        $settings = self::get_varnish_cli_settings();
        $current_targets = self::resolve_varnish_invalidation_targets((array) ($settings['servers'] ?? array()));
        sort($current_targets, SORT_STRING);
        if (empty($current_targets)) {
            return array(
                'proceed' => false,
                'skipped' => false,
                'retryable' => false,
                'message' => self::maybe_translate('Refresh ahead could not resolve a usable Varnish HTTP endpoint.'),
            );
        }

        $target_state = self::decode_varnish_queue_pending_targets($row['pending_targets'] ?? '');
        $pending_targets = (array) ($target_state['pending'] ?? array());
        $required_targets = (array) ($target_state['required'] ?? array());
        $phase = sanitize_key((string) ($target_state['phase'] ?? ''));
        sort($pending_targets, SORT_STRING);
        sort($required_targets, SORT_STRING);
        if ($required_targets !== $current_targets) {
            $pending_targets = array();
            $required_targets = $current_targets;
            $phase = '';
        }

        if ('purged' === $phase) {
            return array(
                'proceed' => true,
                'alreadyPurged' => true,
                'bucket' => $bucket,
                'message' => self::maybe_translate('The durable refresh-ahead row already recorded a completed soft purge.'),
            );
        }

        if (empty($pending_targets)) {
            if (!self::invoke_varnish_refill_heartbeat($heartbeat, 'refresh-ahead-recheck-before', $bucket)) {
                return array('proceed' => false, 'retryable' => true, 'ownershipLost' => true, 'message' => self::maybe_translate('Refresh-ahead queue ownership expired before the public eligibility recheck.'));
            }
            $probe = self::summarize_varnish_refill_response(self::send_varnish_refresh_ahead_probe_request($url, $bucket));
            if (!self::invoke_varnish_refill_heartbeat($heartbeat, 'refresh-ahead-recheck-after', $bucket)) {
                return array('proceed' => false, 'retryable' => true, 'ownershipLost' => true, 'message' => self::maybe_translate('Refresh-ahead queue ownership expired after the public eligibility recheck.'));
            }

            $probe_status = strtoupper((string) ($probe['status'] ?? 'INCONCLUSIVE'));
            $age_value = $probe['headers']['age'] ?? null;
            $age = is_numeric($age_value) ? max(0, (int) $age_value) : null;
            $probe_update = array(
                'url' => $url,
                'incrementProbe' => true,
                'lastProbedAt' => time(),
                'lastProbeBucket' => $bucket,
                'lastProbeAge' => $age,
                'lastResult' => sanitize_key(strtolower($probe_status)),
                'nextProbeAt' => self::calculate_varnish_refresh_ahead_next_probe_at($probe, $age, $threshold_seconds, false),
            );
            self::record_varnish_refresh_candidate_probe_updates(array($probe_update));

            if (empty($probe['success'])) {
                return array(
                    'proceed' => false,
                    'skipped' => false,
                    'retryable' => self::is_varnish_refill_failure_retryable($probe),
                    'message' => (string) ($probe['detail'] ?? self::maybe_translate('Refresh-ahead public eligibility recheck failed.')),
                    'probe' => $probe,
                );
            }
            if ('STALE' === $probe_status) {
                $encoded = self::encode_varnish_queue_pending_targets(array(), $current_targets, 'purged');
                if (!self::update_cron_warm_queue_claim_pending_targets($row, $encoded)) {
                    return array('proceed' => false, 'retryable' => true, 'ownershipLost' => true, 'message' => self::maybe_translate('Refresh-ahead ownership was lost while recording the already-stale object state.'));
                }
                return array(
                    'proceed' => true,
                    'alreadyPurged' => true,
                    'bucket' => $bucket,
                    'probe' => $probe,
                    'message' => self::maybe_translate('The public Varnish object was already stale, so the claimed job continued directly to strict origin refresh.'),
                );
            }
            if ('HIT' !== $probe_status || null === $age || $age < $threshold_seconds) {
                return array(
                    'proceed' => false,
                    'skipped' => true,
                    'retryable' => false,
                    'message' => 'HIT' === $probe_status && null !== $age
                        ? self::maybe_translate('The public Varnish object was no longer near the refresh-ahead threshold when the queued job was claimed.')
                        : self::maybe_translate('The queued refresh-ahead object was no longer a verified public HIT with numeric Age.'),
                    'probe' => $probe,
                );
            }
            $pending_targets = $current_targets;
        }

        if (!self::invoke_varnish_refill_heartbeat($heartbeat, 'refresh-ahead-soft-purge-before', $bucket)) {
            return array('proceed' => false, 'retryable' => true, 'ownershipLost' => true, 'message' => self::maybe_translate('Refresh-ahead queue ownership expired before soft purge.'));
        }
        $prepared = self::prepare_varnish_invalidation_urls(array($url));
        $purge = self::send_varnish_soft_purge_prepared_urls($prepared, 'refresh-ahead-worker', $pending_targets, true);
        if (!self::invoke_varnish_refill_heartbeat($heartbeat, 'refresh-ahead-soft-purge-after', $bucket)) {
            return array('proceed' => false, 'retryable' => true, 'ownershipLost' => true, 'message' => self::maybe_translate('Refresh-ahead queue ownership expired after soft purge.'));
        }

        $url_result = is_array($purge['urlResults'][$url] ?? null) ? $purge['urlResults'][$url] : array();
        if (!empty($url_result['success'])) {
            $encoded = self::encode_varnish_queue_pending_targets(array(), $current_targets, 'purged');
            if (!self::update_cron_warm_queue_claim_pending_targets($row, $encoded)) {
                return array('proceed' => false, 'retryable' => true, 'ownershipLost' => true, 'message' => self::maybe_translate('Refresh-ahead ownership was lost while recording completed soft purge.'));
            }
            return array(
                'proceed' => true,
                'alreadyPurged' => false,
                'bucket' => $bucket,
                'purge' => $purge,
                'message' => self::maybe_translate('The claimed refresh-ahead job completed soft purge and can continue to strict origin refresh.'),
            );
        }

        $failed_targets = array_values((array) ($url_result['failedEndpointTargets'] ?? $pending_targets));
        $encoded = self::encode_varnish_queue_pending_targets($failed_targets, $current_targets, 'purge-pending');
        if (!self::update_cron_warm_queue_claim_pending_targets($row, $encoded)) {
            return array('proceed' => false, 'retryable' => true, 'ownershipLost' => true, 'message' => self::maybe_translate('Refresh-ahead ownership was lost while preserving failed soft-purge endpoints.'));
        }
        return array(
            'proceed' => false,
            'skipped' => false,
            'retryable' => !empty($url_result['retryable']),
            'message' => (string) ($url_result['message'] ?? ($purge['message'] ?? self::maybe_translate('Refresh-ahead soft purge failed.'))),
            'purge' => $purge,
        );
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

            $scan_gate = self::get_varnish_refresh_ahead_scan_gate();
            if (empty($scan_gate['allowed'])) {
                $state['lastMessage'] = (string) ($scan_gate['message'] ?? '');
                self::save_varnish_refresh_ahead_state($state);
                return array(
                    'ran' => false,
                    'reason' => sanitize_key((string) ($scan_gate['reason'] ?? 'priority-gate')),
                );
            }

            $max_pages = max(1, min(10, (int) ($status['maxPagesPerRun'] ?? 5)));
            $selection_limit = max($max_pages, min(50, $max_pages * 4));
            $candidates = method_exists(static::class, 'get_varnish_refresh_ahead_candidates')
                ? self::get_varnish_refresh_ahead_candidates($selection_limit, true)
                : array();
            $thresholds = self::get_varnish_refresh_ahead_thresholds($status);
            $threshold_seconds = (int) $thresholds['thresholdSeconds'];
            $bucket = self::select_varnish_refresh_ahead_probe_bucket($state);
            $engine = method_exists(static::class, 'get_engine_instance') ? self::get_engine_instance() : null;

            $details = array();
            $probe_updates = array();
            $eligible_urls = array();
            $eligible_probe_counts = array();
            $probed = 0;
            $eligible = 0;
            $queued = 0;
            $skipped_pending = 0;
            $skipped_excluded = 0;
            $errors = 0;

            foreach ($candidates as $candidate) {
                if ($probed >= $max_pages) {
                    break;
                }
                $url = esc_url_raw((string) ($candidate['url'] ?? ''));
                if ('' === $url || !$engine || !method_exists($engine, 'is_cacheable_local_url') || !$engine->is_cacheable_local_url($url)) {
                    $probe_updates[] = array(
                        'url' => $url,
                        'incrementProbe' => false,
                        'lastResult' => 'ineligible',
                        'nextProbeAt' => $now + HOUR_IN_SECONDS,
                    );
                    continue;
                }

                $eligibility = method_exists($engine, 'get_warm_pipeline_eligibility')
                    ? $engine->get_warm_pipeline_eligibility($url)
                    : array('eligible' => true, 'reason' => '', 'message' => '');
                if (empty($eligibility['eligible'])) {
                    ++$skipped_excluded;
                    $exclusion_reason = sanitize_key((string) ($eligibility['reason'] ?? 'not-cacheable'));
                    $details[] = array(
                        'url' => $url,
                        'status' => 'excluded',
                        'age' => null,
                        'thresholdSeconds' => $threshold_seconds,
                        'bucket' => $bucket,
                        'sources' => array_slice(array_values(array_map('sanitize_key', (array) ($candidate['sources'] ?? array()))), 0, 8),
                        'queued' => false,
                        'reason' => '' !== $exclusion_reason ? $exclusion_reason : 'not-cacheable',
                        'message' => (string) ($eligibility['message'] ?? self::maybe_translate('The page cache policy excludes this URL.')),
                    );
                    $probe_updates[] = array(
                        'url' => $url,
                        'incrementProbe' => false,
                        'lastProbeBucket' => $bucket,
                        'lastResult' => 'excluded',
                        'nextProbeAt' => $now + self::get_varnish_refresh_ahead_scan_interval(),
                    );
                    continue;
                }

                if (self::has_pending_varnish_refill_for_url($url)) {
                    ++$skipped_pending;
                    $probe_updates[] = array(
                        'url' => $url,
                        'incrementProbe' => false,
                        'lastProbeBucket' => $bucket,
                        'lastResult' => 'pending',
                        'nextProbeAt' => $now + self::get_varnish_refresh_ahead_scan_interval(),
                    );
                    continue;
                }

                ++$probed;
                $probe = self::summarize_varnish_refill_response(self::send_varnish_refresh_ahead_probe_request($url, $bucket));
                $probe_status = strtoupper((string) ($probe['status'] ?? 'INCONCLUSIVE'));
                $age_value = $probe['headers']['age'] ?? null;
                $age = is_numeric($age_value) ? max(0, (int) $age_value) : null;
                $detail = array(
                    'url' => $url,
                    'status' => sanitize_key(strtolower($probe_status)),
                    'age' => $age,
                    'thresholdSeconds' => $threshold_seconds,
                    'bucket' => $bucket,
                    'sources' => array_slice(array_values(array_map('sanitize_key', (array) ($candidate['sources'] ?? array()))), 0, 8),
                    'queued' => false,
                );

                $near_expiry = !empty($probe['success'])
                    && (('HIT' === $probe_status && null !== $age && $age >= $threshold_seconds) || 'STALE' === $probe_status);
                if ($near_expiry) {
                    ++$eligible;
                    $eligible_urls[$url] = $url;
                    $eligible_probe_counts[$url] = max(0, (int) ($candidate['probeCount'] ?? 0));
                    $detail['status'] = 'eligible';
                } elseif (empty($probe['success'])) {
                    ++$errors;
                }

                $probe_updates[] = array(
                    'url' => $url,
                    'incrementProbe' => true,
                    'lastProbedAt' => $now,
                    'lastProbeBucket' => $bucket,
                    'lastProbeAge' => $age,
                    'lastResult' => $near_expiry ? 'eligible' : sanitize_key(strtolower($probe_status)),
                    'nextProbeAt' => self::calculate_varnish_refresh_ahead_next_probe_at(
                        $probe,
                        $age,
                        $threshold_seconds,
                        false,
                        max(0, (int) ($candidate['probeCount'] ?? 0))
                    ),
                );
                $details[] = $detail;
            }

            // Persist the selected bucket and observation before scheduling cron,
            // so a fast worker rechecks the same public variant that qualified.
            self::record_varnish_refresh_candidate_probe_updates($probe_updates);

            $queue_result = array('success' => true, 'queued' => false);
            if (!empty($eligible_urls)) {
                $queue_result = method_exists(static::class, 'enqueue_targeted_warm_pipeline_urls')
                    ? self::enqueue_targeted_warm_pipeline_urls(array_values($eligible_urls), true, 'refresh-ahead')
                    : array('success' => false, 'queued' => false);
                $queued_url_map = array();
                foreach ((array) ($queue_result['queuedUrls'] ?? array()) as $queued_url) {
                    $queued_url = esc_url_raw((string) $queued_url);
                    if (isset($eligible_urls[$queued_url])) {
                        $queued_url_map[$queued_url] = true;
                    }
                }
                $queued = count($queued_url_map);
                $errors += max(0, count($eligible_urls) - $queued);

                $queue_updates = array();
                foreach ($details as &$detail) {
                    $detail_url = (string) ($detail['url'] ?? '');
                    if (!isset($eligible_urls[$detail_url])) {
                        continue;
                    }
                    $url_queued = isset($queued_url_map[$detail_url]);
                    $detail['queued'] = $url_queued;
                    $detail['status'] = $url_queued ? 'queued' : 'queue-failed';
                    $queue_updates[] = array(
                        'url' => $detail_url,
                        'incrementProbe' => false,
                        'lastProbeBucket' => $bucket,
                        'lastResult' => $url_queued ? 'queued' : 'queue-failed',
                        'nextProbeAt' => $url_queued
                            ? $now + max(self::get_varnish_refresh_ahead_scan_interval(), $threshold_seconds)
                            : self::calculate_varnish_refresh_ahead_retry_backoff_at(
                                max(0, (int) ($eligible_probe_counts[$detail_url] ?? 0))
                            ),
                    );
                }
                unset($detail);
                self::record_varnish_refresh_candidate_probe_updates($queue_updates);
            }
            $candidate_summary = method_exists(static::class, 'get_varnish_refresh_candidate_summary') ? self::get_varnish_refresh_candidate_summary() : array('count' => count($candidates), 'sources' => array());
            $state = array(
                'lastScanAt' => $now,
                'probeBucketIndex' => max(0, (int) ($state['probeBucketIndex'] ?? 0)),
                'lastProbeBucket' => $bucket,
                'candidateCount' => max(count($candidates), (int) ($candidate_summary['count'] ?? 0)),
                'candidateSources' => is_array($candidate_summary['sources'] ?? null) ? $candidate_summary['sources'] : array(),
                'probedCount' => $probed,
                'eligibleCount' => $eligible,
                'queuedCount' => $queued,
                'skippedPendingCount' => $skipped_pending,
                'skippedExcludedCount' => $skipped_excluded,
                'errorCount' => $errors,
                'lastMessage' => self::maybe_translate_sprintf('Refresh ahead anonymously probed %1$d due cacheable candidate(s) in the %4$s bucket, found %2$d near expiry, durably queued %3$d page job(s), and excluded %5$d non-cacheable candidate(s). No soft purge ran in the scanner.', $probed, $eligible, $queued, strtoupper($bucket), $skipped_excluded),
                'details' => $details,
            );
            self::save_varnish_refresh_ahead_state($state);

            if (
                $queued > 0
                && !self::is_manual_warmup_blocking_cron()
                && self::get_shared_automation_pages_per_minute() > 0
            ) {
                self::ensure_cron_warm_events_scheduled(1);
            }

            return array_merge(array('ran' => true), $state);
        } finally {
            self::release_varnish_refresh_ahead_lock($lock_token);
        }
    }
}
