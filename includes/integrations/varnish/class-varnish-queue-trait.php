<?php
/**
 * Persistent Varnish invalidation and refill queue helpers for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Queue_Trait
{
    /**
     * Queue large invalidation sets instead of blocking the WordPress request.
     *
     * @return int
     */
    private static function get_varnish_queue_unique_url_threshold()
    {
        $threshold = (int) apply_filters('ultracache_varnish_queue_unique_url_threshold', 20);
        return max(5, min(200, $threshold));
    }

    /**
     * Queue invalidation when the estimated endpoint request count is large.
     *
     * @return int
     */
    private static function get_varnish_queue_request_threshold()
    {
        $threshold = (int) apply_filters('ultracache_varnish_queue_request_threshold', 10);
        return max(2, min(100, $threshold));
    }

    /**
     * Maximum attempts for one persistent Varnish queue row.
     *
     * @return int
     */
    private static function get_varnish_queue_max_attempts()
    {
        return 3;
    }

    /**
     * Bounded retry delay for a failed queue attempt.
     *
     * @param int $attempt_number Attempt number that just failed.
     * @return int
     */
    private static function get_varnish_queue_retry_delay($attempt_number)
    {
        $attempt_number = max(1, (int) $attempt_number);
        $delays = array(1 => 30, 2 => 120, 3 => 300);
        return isset($delays[$attempt_number]) ? $delays[$attempt_number] : 300;
    }

    /**
     * Estimate the endpoint calls required for one prepared invalidation set.
     *
     * @param array $prepared Prepared URL result.
     * @param array $settings Varnish connection settings.
     * @return int
     */
    private static function estimate_varnish_invalidation_request_count(array $prepared, array $settings)
    {
        $endpoint_count = max(0, count((array) ($settings['servers'] ?? array())));
        if ($endpoint_count < 1 || empty($prepared['urls'])) {
            return 0;
        }

        $mode = (string) ($settings['mode'] ?? 'http');
        $strategy = self::get_varnish_invalidation_strategy_status($settings);
        $effective = (string) ($strategy['effective'] ?? 'ban');
        if ('admin' === $mode || 'ban' === $effective) {
            return count(self::build_varnish_ban_batches($prepared['urls'])) * $endpoint_count;
        }

        return count($prepared['urls']) * $endpoint_count;
    }

    /**
     * Queue a large targeted invalidation set when persistent storage is ready.
     *
     * Returning null tells the caller to use the existing synchronous path.
     *
     * @param array  $urls  Candidate URLs.
     * @param string $scope Invalidation scope.
     * @return array|null
     */
    private static function maybe_queue_varnish_invalidation(array $urls, $scope = 'batch')
    {
        $settings = self::get_varnish_cli_settings();
        $support = is_array($settings['support'] ?? null) ? $settings['support'] : array();
        if (empty($support['available']) || empty($settings['enabled']) || empty($settings['servers'])) {
            return null;
        }

        $prepared = self::prepare_varnish_invalidation_urls($urls);
        if (empty($prepared['urls'])) {
            return null;
        }

        $estimated_requests = self::estimate_varnish_invalidation_request_count($prepared, $settings);
        $unique_count = (int) ($prepared['uniqueCount'] ?? 0);
        if (
            $unique_count <= self::get_varnish_queue_unique_url_threshold()
            && $estimated_requests <= self::get_varnish_queue_request_threshold()
        ) {
            return null;
        }

        if (!method_exists(static::class, 'insert_cron_warm_queue_urls') || !self::ensure_cron_warm_queue_table()) {
            return null;
        }

        $canonical_urls = array();
        foreach ($prepared['urls'] as $item) {
            $canonical_url = isset($item['url']) ? esc_url_raw((string) $item['url']) : '';
            if ('' !== $canonical_url) {
                $canonical_urls[] = $canonical_url;
            }
        }

        if (empty($canonical_urls)) {
            return null;
        }

        $queued = self::insert_cron_warm_queue_urls($canonical_urls, 0, 'varnish_invalidate');
        if ($queued < 1) {
            return null;
        }

        self::ensure_cron_warm_events_scheduled(1, true);
        $queue = self::get_varnish_queue_stats();
        $result = array(
            'success' => true,
            'queued' => true,
            'message' => self::maybe_translate_sprintf(
                'Queued %1$d Varnish URL invalidation(s); the persistent worker will process them before ordinary warm-up jobs.',
                $unique_count
            ),
            'time' => time(),
            'scope' => sanitize_key((string) $scope),
            'operationType' => 'queued-invalidation',
            'receivedUrlCount' => (int) ($prepared['receivedCount'] ?? count($urls)),
            'validUrlCount' => (int) ($prepared['validCount'] ?? $unique_count),
            'uniqueUrlCount' => $unique_count,
            'duplicateUrlCount' => (int) ($prepared['duplicateCount'] ?? 0),
            'rejectedUrlCount' => (int) ($prepared['rejectedCount'] ?? 0),
            'estimatedRequestCount' => $estimated_requests,
            'queuedUrlCount' => $queued,
            'rejections' => (array) ($prepared['rejections'] ?? array()),
            'rejectionsTruncated' => !empty($prepared['rejectionsTruncated']),
            'queue' => $queue,
        );
        self::set_varnish_last_result($result);
        return $result;
    }

    /**
     * Read bounded aggregate Varnish queue counters.
     *
     * @return array
     */
    private static function get_varnish_queue_stats()
    {
        global $wpdb;

        $stats = array(
            'pendingInvalidations' => 0,
            'pendingRefills' => 0,
            'failed' => 0,
            'retrying' => 0,
            'retryAttempts' => 0,
            'nextAttemptAt' => 0,
        );
        if (!($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return $stats;
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads bounded aggregate counters from one UltraCache-owned queue table query.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN job_type = %s AND status = %s THEN 1 ELSE 0 END) AS pending_invalidations,
                    SUM(CASE WHEN job_type = %s AND status = %s THEN 1 ELSE 0 END) AS pending_refills,
                    SUM(CASE WHEN job_type IN (%s, %s) AND status = %s THEN 1 ELSE 0 END) AS failed_jobs,
                    SUM(CASE WHEN job_type IN (%s, %s) AND status = %s AND attempt_count > %d THEN 1 ELSE 0 END) AS retrying_jobs,
                    SUM(CASE WHEN job_type IN (%s, %s) THEN attempt_count ELSE 0 END) AS retry_attempts,
                    MIN(CASE WHEN job_type IN (%s, %s) AND status = %s AND next_attempt_at > %d THEN next_attempt_at ELSE NULL END) AS next_attempt_at
                FROM %i
                WHERE job_type IN (%s, %s)",
                'varnish_invalidate',
                'pending',
                'varnish_refill',
                'pending',
                'varnish_invalidate',
                'varnish_refill',
                'error',
                'varnish_invalidate',
                'varnish_refill',
                'pending',
                0,
                'varnish_invalidate',
                'varnish_refill',
                'varnish_invalidate',
                'varnish_refill',
                'pending',
                time(),
                $table,
                'varnish_invalidate',
                'varnish_refill'
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return $stats;
        }

        $stats['pendingInvalidations'] = max(0, (int) ($row['pending_invalidations'] ?? 0));
        $stats['pendingRefills'] = max(0, (int) ($row['pending_refills'] ?? 0));
        $stats['failed'] = max(0, (int) ($row['failed_jobs'] ?? 0));
        $stats['retrying'] = max(0, (int) ($row['retrying_jobs'] ?? 0));
        $stats['retryAttempts'] = max(0, (int) ($row['retry_attempts'] ?? 0));
        $stats['nextAttemptAt'] = max(0, (int) ($row['next_attempt_at'] ?? 0));
        return $stats;
    }

    /**
     * Whether persistent Varnish work remains queued.
     *
     * @return bool
     */
    private static function has_pending_varnish_queue_rows()
    {
        $stats = self::get_varnish_queue_stats();
        return !empty($stats['pendingInvalidations']) || !empty($stats['pendingRefills']);
    }

    /**
     * Bound queue rows by the configured endpoint fan-out and transport mode.
     *
     * @param int $requested_limit Caller row limit.
     * @return int
     */
    private static function get_varnish_queue_ready_row_limit($requested_limit)
    {
        $requested_limit = max(1, min(200, absint($requested_limit)));
        $settings = self::get_varnish_cli_settings();
        $endpoint_count = max(1, count((array) ($settings['servers'] ?? array())));
        $request_budget = 4;
        $mode = (string) ($settings['mode'] ?? 'http');
        $method = (string) ($settings['method'] ?? 'BAN');

        if ('admin' === $mode || 'BAN' === $method) {
            $batch_budget = max(1, (int) floor($request_budget / $endpoint_count));
            return min($requested_limit, $batch_budget * self::get_varnish_ban_batch_path_limit());
        }

        $url_budget = max(1, (int) floor($request_budget / $endpoint_count));
        return min($requested_limit, $url_budget);
    }

    /**
     * Load Varnish rows whose retry delay has elapsed.
     *
     * @param int $limit Maximum rows.
     * @return array
     */
    private static function load_ready_varnish_queue_rows($limit = 100)
    {
        global $wpdb;

        $limit = self::get_varnish_queue_ready_row_limit($limit);
        if (!($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return array();
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads ready rows from an UltraCache-owned persistent queue.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, url, job_type, attempt_count FROM %i WHERE status = %s AND job_type IN (%s, %s) AND next_attempt_at <= %d ORDER BY CASE job_type WHEN 'varnish_invalidate' THEN 0 ELSE 1 END ASC, position ASC, id ASC LIMIT %d",
                $table,
                'pending',
                'varnish_invalidate',
                'varnish_refill',
                time(),
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * Persist one Varnish queue attempt result.
     *
     * @param array  $row     Queue row.
     * @param bool   $success Whether the operation succeeded.
     * @param string $message Result detail.
     * @return bool
     */
    private static function mark_varnish_queue_row_attempt(array $row, $success, $message)
    {
        global $wpdb;

        $row_id = absint($row['id'] ?? 0);
        if ($row_id < 1 || !($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return false;
        }

        $attempt_count = max(0, (int) ($row['attempt_count'] ?? 0)) + 1;
        $message = method_exists(static::class, 'sanitize_varnish_string')
            ? self::sanitize_varnish_string((string) $message)
            : (string) $message;
        $message = sanitize_textarea_field($message);
        if (strlen($message) > 2000) {
            $message = substr($message, 0, 2000);
        }

        $now = time();
        $status = 'done';
        $processed_at = $now;
        $next_attempt_at = 0;
        if (!$success) {
            if ($attempt_count >= self::get_varnish_queue_max_attempts()) {
                $status = 'error';
            } else {
                $status = 'pending';
                $processed_at = 0;
                $next_attempt_at = $now + self::get_varnish_queue_retry_delay($attempt_count);
            }
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Updates one UltraCache-owned persistent queue row.
        return false !== $wpdb->update(
            $table,
            array(
                'status' => $status,
                'result_message' => $message,
                'attempt_count' => $attempt_count,
                'next_attempt_at' => $next_attempt_at,
                'updated_at' => $now,
                'processed_at' => $processed_at,
            ),
            array('id' => $row_id),
            array('%s', '%s', '%d', '%d', '%d', '%d'),
            array('%d')
        );
    }

    /**
     * Process ready persistent Varnish jobs before ordinary cron warming.
     *
     * @param int $limit Maximum queue rows.
     * @return array
     */
    private static function process_ready_varnish_queue_rows($limit = 100)
    {
        $rows = self::load_ready_varnish_queue_rows($limit);
        if (empty($rows)) {
            return array('processed' => 0, 'success' => 0, 'failed' => 0, 'queue' => self::get_varnish_queue_stats());
        }

        $lock_ttl = 120;
        $lock_token = 'varnish-queue-' . wp_generate_password(20, false, false);
        if (!self::acquire_cron_warm_lock($lock_token, $lock_ttl)) {
            return array('processed' => 0, 'success' => 0, 'failed' => 0, 'locked' => true, 'queue' => self::get_varnish_queue_stats());
        }

        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        try {
            $rows = self::load_ready_varnish_queue_rows($limit);
            $invalidations = array();
            $refills = array();
            foreach ($rows as $row) {
                if ('varnish_invalidate' === (string) ($row['job_type'] ?? '')) {
                    $invalidations[] = $row;
                } elseif ('varnish_refill' === (string) ($row['job_type'] ?? '')) {
                    $refills[] = $row;
                }
            }
            // One refill URL can rebuild and publicly request up to three active HTML
            // variants, plus one verification request per variant when explicitly enabled.
            // Process one refill URL per tick to keep loopback work bounded.
            $refills = array_slice($refills, 0, 1);

            if (!empty($invalidations)) {
                $urls = array_values(array_filter(array_map(static function ($row) {
                    return isset($row['url']) ? (string) $row['url'] : '';
                }, $invalidations)));
                $result = self::varnish_flush_url_batch($urls, 'queued');
                $ok = !empty($result['success']);
                $message = (string) ($result['message'] ?? ($ok ? 'Varnish invalidation complete.' : 'Varnish invalidation failed.'));
                foreach ($invalidations as $row) {
                    self::mark_varnish_queue_row_attempt($row, $ok, $message);
                    ++$processed;
                    if ($ok) {
                        ++$succeeded;
                    } else {
                        ++$failed;
                    }
                }

                $refill_queue = $ok
                    ? self::queue_varnish_refill_urls($urls, 'queued-invalidation')
                    : array('success' => false, 'queued' => false, 'queuedUrlCount' => 0);
                $result['operationType'] = 'queued-invalidation';
                $result['queueProcessedUrlCount'] = count($invalidations);
                $result['refillQueued'] = !empty($refill_queue['queued']);
                $result['refillQueuedUrlCount'] = max(0, (int) ($refill_queue['queuedUrlCount'] ?? 0));
                $result['queue'] = self::get_varnish_queue_stats();
                self::set_varnish_last_result($result);
            }

            $refill_succeeded = 0;
            $refill_failed = 0;
            $refill_verified = 0;
            $refill_bypassed = 0;
            $refill_inconclusive = 0;
            $refill_not_hit = 0;
            $refill_verification_errors = 0;
            $refill_two_stage_available = 0;
            $refill_two_stage_fallback = 0;
            $refill_two_stage_inconclusive = 0;
            $refill_two_stage_errors = 0;
            foreach ($refills as $row) {
                $result = self::send_varnish_refill_request((string) ($row['url'] ?? ''));
                $ok = !empty($result['success']);
                self::mark_varnish_queue_row_attempt($row, $ok, (string) ($result['message'] ?? 'Varnish refill failed.'));
                ++$processed;
                if ($ok) {
                    ++$succeeded;
                    ++$refill_succeeded;
                } else {
                    ++$failed;
                    ++$refill_failed;
                }

                $two_stage = is_array($result['twoStageRefill'] ?? null) ? $result['twoStageRefill'] : array();
                $two_stage_status = sanitize_key((string) ($two_stage['status'] ?? 'inconclusive'));
                if (!empty($two_stage['available'])) {
                    ++$refill_two_stage_available;
                } elseif ('error' === $two_stage_status) {
                    ++$refill_two_stage_errors;
                } elseif ('inconclusive' === $two_stage_status || 'configuration-changed' === $two_stage_status || 'untested' === $two_stage_status) {
                    ++$refill_two_stage_inconclusive;
                } else {
                    ++$refill_two_stage_fallback;
                }

                $public_refill = is_array($result['publicRefill'] ?? null) ? $result['publicRefill'] : array();
                $verification_status = sanitize_key((string) ($public_refill['verificationStatus'] ?? 'disabled'));
                if ('verified' === $verification_status) {
                    ++$refill_verified;
                } elseif ('bypassed' === $verification_status) {
                    ++$refill_bypassed;
                } elseif ('not-hit' === $verification_status) {
                    ++$refill_not_hit;
                } elseif ('inconclusive' === $verification_status) {
                    ++$refill_inconclusive;
                } elseif ('error' === $verification_status) {
                    ++$refill_verification_errors;
                }
                self::renew_cron_warm_lock($lock_token, $lock_ttl);
            }
            if (!empty($refills)) {
                self::set_varnish_last_result(array(
                    'success' => 0 === $refill_failed,
                    'message' => 0 === $refill_failed
                        ? self::maybe_translate_sprintf('Completed %d queued affected-page Varnish refill(s).', $refill_succeeded)
                        : self::maybe_translate_sprintf('Completed %1$d queued Varnish refill(s); %2$d failed and will follow the retry policy.', $refill_succeeded, $refill_failed),
                    'time' => time(),
                    'operationType' => 'queued-refill',
                    'refillSuccessCount' => $refill_succeeded,
                    'refillFailureCount' => $refill_failed,
                    'refillVerifiedCount' => $refill_verified,
                    'refillBypassedCount' => $refill_bypassed,
                    'refillInconclusiveCount' => $refill_inconclusive,
                    'refillNotHitCount' => $refill_not_hit,
                    'refillVerificationErrorCount' => $refill_verification_errors,
                    'refillTwoStageAvailableCount' => $refill_two_stage_available,
                    'refillTwoStageFallbackCount' => $refill_two_stage_fallback,
                    'refillTwoStageInconclusiveCount' => $refill_two_stage_inconclusive,
                    'refillTwoStageErrorCount' => $refill_two_stage_errors,
                    'queue' => self::get_varnish_queue_stats(),
                ));
            }
        } finally {
            self::release_cron_warm_lock($lock_token);
        }

        if (self::has_pending_varnish_queue_rows()) {
            self::ensure_cron_warm_events_scheduled(null, true);
        }

        return array(
            'processed' => $processed,
            'success' => $succeeded,
            'failed' => $failed,
            'queue' => self::get_varnish_queue_stats(),
        );
    }

    /**
     * Resume pending Varnish jobs after another warm-up owner releases control.
     *
     * @return bool
     */
    private static function resume_pending_varnish_queue()
    {
        if (!self::has_pending_varnish_queue_rows()) {
            return false;
        }

        self::ensure_cron_warm_events_scheduled(1, true);
        return true;
    }
}
