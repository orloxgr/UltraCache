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

        $accepted_queue_urls = array();
        $enqueue_summary = array();
        $queued = self::insert_cron_warm_queue_urls(
            $canonical_urls,
            0,
            'varnish_invalidate',
            '',
            false,
            $accepted_queue_urls,
            $enqueue_summary
        );
        if ($queued < 1) {
            return null;
        }

        self::ensure_cron_warm_events_scheduled(1, true);
        $queue_failed = max(0, $unique_count - $queued);
        $queue = self::get_varnish_queue_stats();
        $result = array(
            'success' => $queue_failed < 1,
            'partial' => $queue_failed > 0,
            'warning' => $queue_failed > 0,
            'queued' => true,
            'message' => $queue_failed > 0
                ? self::maybe_translate_sprintf(
                    'Accepted %1$d Varnish URL invalidation(s): %2$d inserted, %3$d coalesced, %4$d upgraded, and %5$d could not be persisted.',
                    $queued,
                    max(0, (int) ($enqueue_summary['inserted'] ?? 0)),
                    max(0, (int) ($enqueue_summary['coalesced'] ?? 0)),
                    max(0, (int) ($enqueue_summary['upgraded'] ?? 0)),
                    $queue_failed
                )
                : self::maybe_translate_sprintf(
                    'Accepted %1$d Varnish URL invalidation(s): %2$d inserted, %3$d coalesced, and %4$d upgraded.',
                    $queued,
                    max(0, (int) ($enqueue_summary['inserted'] ?? 0)),
                    max(0, (int) ($enqueue_summary['coalesced'] ?? 0)),
                    max(0, (int) ($enqueue_summary['upgraded'] ?? 0))
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
            'acceptedUrlCount' => $queued,
            'insertedUrlCount' => max(0, (int) ($enqueue_summary['inserted'] ?? 0)),
            'coalescedUrlCount' => max(0, (int) ($enqueue_summary['coalesced'] ?? 0)),
            'upgradedUrlCount' => max(0, (int) ($enqueue_summary['upgraded'] ?? 0)),
            'queueFailedUrlCount' => $queue_failed,
            'failedUrlCount' => $queue_failed,
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
            'processingInvalidations' => 0,
            'pendingRefills' => 0,
            'processingRefills' => 0,
            'pendingSharedPipelineRefills' => 0,
            'planned' => 0,
            'processing' => 0,
            'retrying' => 0,
            'partial' => 0,
            'warnings' => 0,
            'completed' => 0,
            'skipped' => 0,
            'terminalErrors' => 0,
            'failed' => 0,
            'retryAttempts' => 0,
            'nextAttemptAt' => 0,
            'refillWorker' => array(
                'status' => 'idle',
                'pending' => 0,
                'active' => false,
                'nextScheduledAt' => 0,
            ),
        );
        if (!($wpdb instanceof wpdb) || !self::cron_warm_queue_table_read_ready()) {
            return $stats;
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads aggregate counters from one UltraCache-owned queue table query.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN job_type = %s AND status = %s THEN 1 ELSE 0 END) AS pending_invalidations,
                    SUM(CASE WHEN job_type = %s AND status = %s THEN 1 ELSE 0 END) AS processing_invalidations,
                    SUM(CASE WHEN job_type = %s AND source_context <> %s AND status = %s THEN 1 ELSE 0 END) AS pending_refills,
                    SUM(CASE WHEN job_type = %s AND source_context <> %s AND status = %s THEN 1 ELSE 0 END) AS processing_refills,
                    SUM(CASE WHEN ((job_type = %s) OR (job_type = %s AND source_context <> %s)) AND status = %s AND attempt_count = %d THEN 1 ELSE 0 END) AS planned_jobs,
                    SUM(CASE WHEN ((job_type = %s) OR (job_type = %s AND source_context <> %s)) AND status = %s THEN 1 ELSE 0 END) AS processing_jobs,
                    SUM(CASE WHEN ((job_type = %s) OR (job_type = %s AND source_context <> %s)) AND status = %s AND attempt_count > %d THEN 1 ELSE 0 END) AS retrying_jobs,
                    SUM(CASE WHEN ((job_type = %s) OR (job_type = %s AND source_context <> %s)) AND status = %s AND result_level = %s THEN 1 ELSE 0 END) AS partial_jobs,
                    SUM(CASE WHEN ((job_type = %s) OR (job_type = %s AND source_context <> %s)) AND status = %s AND result_level = %s THEN 1 ELSE 0 END) AS warning_jobs,
                    SUM(CASE WHEN ((job_type = %s) OR (job_type = %s AND source_context <> %s)) AND status = %s AND result_level <> %s THEN 1 ELSE 0 END) AS completed_jobs,
                    SUM(CASE WHEN ((job_type = %s) OR (job_type = %s AND source_context <> %s)) AND status = %s THEN 1 ELSE 0 END) AS skipped_jobs,
                    SUM(CASE WHEN ((job_type = %s) OR (job_type = %s AND source_context <> %s)) AND status = %s THEN 1 ELSE 0 END) AS terminal_errors,
                    SUM(CASE WHEN ((job_type = %s) OR (job_type = %s AND source_context <> %s)) AND status = %s AND attempt_count > %d THEN attempt_count ELSE 0 END) AS retry_attempts,
                    MIN(CASE WHEN ((job_type = %s) OR (job_type = %s AND source_context <> %s)) AND status = %s AND next_attempt_at > %d THEN next_attempt_at ELSE NULL END) AS next_attempt_at
                FROM %i
                WHERE job_type IN (%s, %s)",
                'varnish_invalidate',
                'pending',
                'varnish_invalidate',
                'processing',
                'page_warm',
                '',
                'pending',
                'page_warm',
                '',
                'processing',
                'varnish_invalidate',
                'page_warm',
                '',
                'pending',
                0,
                'varnish_invalidate',
                'page_warm',
                '',
                'processing',
                'varnish_invalidate',
                'page_warm',
                '',
                'pending',
                0,
                'varnish_invalidate',
                'page_warm',
                '',
                'pending',
                'partial',
                'varnish_invalidate',
                'page_warm',
                '',
                'done',
                'warning',
                'varnish_invalidate',
                'page_warm',
                '',
                'done',
                'warning',
                'varnish_invalidate',
                'page_warm',
                '',
                'skipped',
                'varnish_invalidate',
                'page_warm',
                '',
                'error',
                'varnish_invalidate',
                'page_warm',
                '',
                'pending',
                0,
                'varnish_invalidate',
                'page_warm',
                '',
                'pending',
                time(),
                $table,
                'varnish_invalidate',
                'page_warm'
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return $stats;
        }

        $stats['pendingInvalidations'] = max(0, (int) ($row['pending_invalidations'] ?? 0));
        $stats['processingInvalidations'] = max(0, (int) ($row['processing_invalidations'] ?? 0));
        $stats['pendingRefills'] = max(0, (int) ($row['pending_refills'] ?? 0));
        $stats['processingRefills'] = max(0, (int) ($row['processing_refills'] ?? 0));
        $stats['pendingSharedPipelineRefills'] = $stats['pendingRefills'] + $stats['processingRefills'];
        $stats['planned'] = max(0, (int) ($row['planned_jobs'] ?? 0));
        $stats['processing'] = max(0, (int) ($row['processing_jobs'] ?? 0));
        $stats['retrying'] = max(0, (int) ($row['retrying_jobs'] ?? 0));
        $stats['partial'] = max(0, (int) ($row['partial_jobs'] ?? 0));
        $stats['warnings'] = max(0, (int) ($row['warning_jobs'] ?? 0));
        $stats['completed'] = max(0, (int) ($row['completed_jobs'] ?? 0));
        $stats['skipped'] = max(0, (int) ($row['skipped_jobs'] ?? 0));
        $stats['terminalErrors'] = max(0, (int) ($row['terminal_errors'] ?? 0));
        $stats['failed'] = $stats['terminalErrors'];
        $stats['retryAttempts'] = max(0, (int) ($row['retry_attempts'] ?? 0));
        $stats['nextAttemptAt'] = max(0, (int) ($row['next_attempt_at'] ?? 0));
        if (method_exists(static::class, 'get_targeted_page_warm_worker_status')) {
            $stats['refillWorker'] = self::get_targeted_page_warm_worker_status($stats['pendingRefills']);
        }
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
        return !empty($stats['pendingInvalidations']) || !empty($stats['pendingSharedPipelineRefills']);
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
                "SELECT id, url, job_type, attempt_count FROM %i WHERE status = %s AND job_type = %s AND next_attempt_at <= %d ORDER BY position ASC, id ASC LIMIT %d",
                $table,
                'pending',
                'varnish_invalidate',
                time(),
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * Decode a bounded list of endpoint labels retained for one invalidation retry.
     *
     * @param mixed $value Stored JSON value.
     * @return array
     */
    private static function decode_varnish_queue_pending_targets($value)
    {
        $value = is_string($value) ? trim($value) : '';
        if ('' === $value || strlen($value) > 8192) {
            return array('pending' => array(), 'required' => array(), 'phase' => '');
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return array('pending' => array(), 'required' => array(), 'phase' => '');
        }

        $pending_values = isset($decoded['pending']) && is_array($decoded['pending']) ? $decoded['pending'] : $decoded;
        $required_values = isset($decoded['required']) && is_array($decoded['required']) ? $decoded['required'] : $pending_values;
        $normalize = static function (array $values) {
            $targets = array();
            foreach (array_slice($values, 0, 32) as $target) {
                $target = trim((string) $target);
                if ('' !== $target && strlen($target) <= 512) {
                    $targets[$target] = true;
                }
            }
            $targets = array_keys($targets);
            sort($targets, SORT_STRING);
            return $targets;
        };

        $phase = sanitize_key((string) ($decoded['phase'] ?? ''));
        if (!in_array($phase, array('', 'purge-pending', 'purged'), true)) {
            $phase = '';
        }

        return array(
            'pending' => $normalize($pending_values),
            'required' => $normalize($required_values),
            'phase' => $phase,
        );
    }

    /**
     * Encode failed endpoint labels and the endpoint set they belong to.
     *
     * @param array $targets          Endpoint labels still requiring invalidation.
     * @param array $required_targets Complete endpoint set required for this request generation.
     * @return string
     */
    private static function encode_varnish_queue_pending_targets(array $targets, array $required_targets = array(), $phase = 'purge-pending')
    {
        $normalize = static function (array $values) {
            $bounded = array();
            foreach (array_slice($values, 0, 32) as $target) {
                $target = trim((string) $target);
                if ('' !== $target && strlen($target) <= 512) {
                    $bounded[$target] = true;
                }
            }
            $bounded = array_keys($bounded);
            sort($bounded, SORT_STRING);
            return $bounded;
        };

        $pending = $normalize($targets);
        $required = $normalize($required_targets);
        $phase = sanitize_key((string) $phase);
        if (!in_array($phase, array('purge-pending', 'purged'), true)) {
            $phase = 'purge-pending';
        }
        if (empty($pending) && 'purged' !== $phase) {
            return '';
        }
        if (empty($required)) {
            $required = $pending;
        }

        $encoded = wp_json_encode(array(
            'pending' => $pending,
            'required' => $required,
            'phase' => $phase,
        ));
        return is_string($encoded) && strlen($encoded) <= 8192 ? $encoded : '';
    }

    /**
     * Read the exact per-URL result returned by the invalidation transport.
     *
     * @param array  $result Batch result.
     * @param string $url    Queue URL.
     * @return array
     */
    private static function get_varnish_queue_url_result(array $result, $url)
    {
        $url = (string) $url;
        $url_results = is_array($result['urlResults'] ?? null) ? $result['urlResults'] : array();
        if (isset($url_results[$url]) && is_array($url_results[$url])) {
            $url_result = $url_results[$url];
            $url_result['attemptedEndpointTargets'] = array_values((array) ($result['attemptedEndpointTargets'] ?? array()));
            return $url_result;
        }

        $normalized = self::normalize_varnish_invalidation_url($url);
        $canonical = !empty($normalized['valid']) ? (string) ($normalized['url'] ?? '') : '';
        if ('' !== $canonical && isset($url_results[$canonical]) && is_array($url_results[$canonical])) {
            $url_result = $url_results[$canonical];
            $url_result['attemptedEndpointTargets'] = array_values((array) ($result['attemptedEndpointTargets'] ?? array()));
            return $url_result;
        }

        return array(
            'url' => $url,
            'success' => false,
            'partial' => false,
            'retryable' => false,
            'successfulEndpointTargets' => array(),
            'failedEndpointTargets' => array_values((array) ($result['attemptedEndpointTargets'] ?? array())),
            'attemptedEndpointTargets' => array_values((array) ($result['attemptedEndpointTargets'] ?? array())),
            'message' => (string) ($result['message'] ?? self::maybe_translate('Varnish invalidation did not return an authoritative per-URL result.')),
        );
    }

    /**
     * Persist one Varnish queue attempt result.
     *
     * @param array $row        Claimed queue row.
     * @param array $url_result Exact per-URL invalidation result.
     * @return array|false
     */
    private static function mark_varnish_queue_row_attempt(array $row, array $url_result)
    {
        global $wpdb;

        $row_id = absint($row['id'] ?? 0);
        $claim_token = sanitize_text_field((string) ($row['claim_token'] ?? ''));
        if ($row_id < 1 || '' === $claim_token || !($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return false;
        }

        $attempt_count = max(1, (int) ($row['attempt_count'] ?? 1));
        $success = !empty($url_result['success']);
        $retryable = !$success && !empty($url_result['retryable']);
        $message = method_exists(static::class, 'sanitize_varnish_string')
            ? self::sanitize_varnish_string((string) ($url_result['message'] ?? ''))
            : (string) ($url_result['message'] ?? '');
        $message = sanitize_textarea_field($message);
        if (strlen($message) > 2000) {
            $message = substr($message, 0, 2000);
        }

        $failed_targets = array_values((array) ($url_result['failedEndpointTargets'] ?? array()));
        $required_targets = array_values((array) ($url_result['requiredEndpointTargets'] ?? ($url_result['attemptedEndpointTargets'] ?? array())));
        $pending_targets = $success ? '' : self::encode_varnish_queue_pending_targets($failed_targets, $required_targets);
        $now = time();
        $status = 'done';
        $processed_at = $now;
        $next_attempt_at = 0;
        if (!$success) {
            if (!$retryable || $attempt_count >= self::get_varnish_queue_max_attempts()) {
                $status = 'error';
            } else {
                $status = 'pending';
                $processed_at = 0;
                $next_attempt_at = $now + self::get_varnish_queue_retry_delay($attempt_count);
            }
        }

        $result_level = $success
            ? 'success'
            : ('error' === $status ? 'error' : (!empty($url_result['partial']) ? 'partial' : 'retrying'));

        $rerun_message = self::maybe_translate('A newer Varnish invalidation request arrived while this row was processing; every configured endpoint will run again.');
        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Claim token prevents a delayed Varnish worker from overwriting newer queue ownership.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET status = CASE WHEN rerun_requested = %d THEN %s ELSE %s END, result_level = CASE WHEN rerun_requested = %d THEN %s ELSE %s END, claim_token = %s, claimed_at = %d, lease_expires_at = %d, pending_targets = CASE WHEN rerun_requested = %d THEN %s ELSE %s END, result_message = CASE WHEN rerun_requested = %d THEN %s ELSE %s END, attempt_count = CASE WHEN rerun_requested = %d THEN %d ELSE attempt_count END, next_attempt_at = CASE WHEN rerun_requested = %d THEN %d ELSE %d END, updated_at = %d, processed_at = CASE WHEN rerun_requested = %d THEN %d ELSE %d END, rerun_requested = %d WHERE id = %d AND status = %s AND claim_token = %s",
                $table,
                1,
                'pending',
                $status,
                1,
                '',
                $result_level,
                '',
                0,
                0,
                1,
                '',
                $pending_targets,
                1,
                $rerun_message,
                $message,
                1,
                0,
                1,
                0,
                $next_attempt_at,
                $now,
                1,
                0,
                $processed_at,
                0,
                $row_id,
                'processing',
                $claim_token
            )
        );

        if (1 !== (int) $updated) {
            return array('success' => false, 'leaseLost' => true, 'status' => 'processing', 'requeued' => false);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads authoritative row state after releasing the owned Varnish claim.
        $saved = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT status, result_level, attempt_count, pending_targets FROM %i WHERE id = %d',
                $table,
                $row_id
            ),
            ARRAY_A
        );
        $saved_status = is_array($saved) ? sanitize_key((string) ($saved['status'] ?? $status)) : $status;
        $saved_attempt_count = is_array($saved) ? max(0, (int) ($saved['attempt_count'] ?? $attempt_count)) : $attempt_count;

        return array(
            'success' => true,
            'leaseLost' => false,
            'status' => $saved_status,
            'resultLevel' => is_array($saved) ? sanitize_key((string) ($saved['result_level'] ?? $result_level)) : $result_level,
            'requeued' => 'pending' === $saved_status && 0 === $saved_attempt_count,
            'pendingTargetState' => is_array($saved) ? self::decode_varnish_queue_pending_targets($saved['pending_targets'] ?? '') : array('pending' => array(), 'required' => array()),
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
            self::prune_completed_varnish_auxiliary_queue_rows();
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
        $partial = 0;
        $retried = 0;
        $endpoint_requests = 0;
        $successful_endpoint_requests = 0;
        $failed_endpoint_requests = 0;
        $completed_urls = array();
        $aggregate_details = array();
        $aggregate_details_truncated = false;
        $requires_verified_origin = false;
        $effective_method = '';
        $invalidation_strategy = '';

        try {
            self::recover_expired_cron_warm_queue_leases();
            $candidates = self::load_ready_varnish_queue_rows($limit);
            $claimed_rows = array();
            foreach ($candidates as $candidate) {
                $claimed_row = self::claim_cron_warm_queue_row($candidate);
                if (!empty($claimed_row)) {
                    $claimed_rows[] = $claimed_row;
                }
            }

            $settings = self::get_varnish_cli_settings();
            $current_targets = self::resolve_varnish_invalidation_targets((array) ($settings['servers'] ?? array()));
            sort($current_targets, SORT_STRING);
            $groups = array();
            foreach ($claimed_rows as $row) {
                $target_state = self::decode_varnish_queue_pending_targets($row['pending_targets'] ?? '');
                $targets = (array) ($target_state['pending'] ?? array());
                $required_targets = (array) ($target_state['required'] ?? array());
                if (empty($targets) || $required_targets !== $current_targets) {
                    $targets = array();
                    $required_targets = $current_targets;
                }
                $group_key = hash('sha256', (string) wp_json_encode(array($targets, $required_targets)));
                if (!isset($groups[$group_key])) {
                    $groups[$group_key] = array(
                        'targets' => $targets,
                        'requiredTargets' => $required_targets,
                        'rows' => array(),
                    );
                }
                $groups[$group_key]['rows'][] = $row;
            }

            foreach ($groups as $group) {
                $group_rows = (array) ($group['rows'] ?? array());
                $group_targets = (array) ($group['targets'] ?? array());
                $group_required_targets = (array) ($group['requiredTargets'] ?? array());
                $urls = array_values(array_filter(array_map(static function ($row) {
                    return isset($row['url']) ? (string) $row['url'] : '';
                }, $group_rows)));
                if (empty($urls)) {
                    continue;
                }

                $result = self::varnish_flush_url_batch($urls, 'queued', '', $group_targets);
                $effective_method = (string) ($result['effectiveMethod'] ?? $effective_method);
                $invalidation_strategy = sanitize_key((string) ($result['invalidationStrategy'] ?? $invalidation_strategy));
                $requires_verified_origin = $requires_verified_origin || 'soft' === $invalidation_strategy;
                $endpoint_requests += max(0, (int) ($result['requestCount'] ?? 0));
                $successful_endpoint_requests += max(0, (int) ($result['successfulEndpointRequestCount'] ?? 0));
                $failed_endpoint_requests += max(0, (int) ($result['failedEndpointRequestCount'] ?? 0));

                foreach ((array) ($result['details'] ?? array()) as $detail) {
                    if (count($aggregate_details) >= 100) {
                        $aggregate_details_truncated = true;
                        break;
                    }
                    $aggregate_details[] = $detail;
                }
                if (!empty($result['detailsTruncated'])) {
                    $aggregate_details_truncated = true;
                }

                foreach ($group_rows as $row) {
                    $url = (string) ($row['url'] ?? '');
                    $url_result = self::get_varnish_queue_url_result($result, $url);
                    $url_result['requiredEndpointTargets'] = $group_required_targets;
                    $attempt_result = self::mark_varnish_queue_row_attempt($row, $url_result);
                    if (empty($attempt_result['success'])) {
                        continue;
                    }

                    ++$processed;
                    if (!empty($attempt_result['requeued'])) {
                        ++$retried;
                        continue;
                    }

                    $saved_status = (string) ($attempt_result['status'] ?? '');
                    if (!empty($url_result['success']) && 'done' === $saved_status) {
                        ++$succeeded;
                        if ('' !== $url) {
                            $completed_urls[$url] = true;
                        }
                        continue;
                    }

                    if (!empty($url_result['partial'])) {
                        ++$partial;
                    }
                    if ('pending' === $saved_status) {
                        ++$retried;
                    } elseif ('error' === $saved_status) {
                        ++$failed;
                    }
                }
            }

            $pipeline_queue = !empty($completed_urls)
                && self::should_refill_after_targeted_varnish_invalidation()
                && method_exists(static::class, 'enqueue_targeted_warm_pipeline_urls')
                ? self::enqueue_targeted_warm_pipeline_urls(array_keys($completed_urls), $requires_verified_origin, 'queued-invalidation')
                : array('success' => empty($completed_urls), 'queued' => false, 'queuedUrlCount' => 0);

            if ($processed > 0) {
                if ($failed < 1 && $retried < 1 && $partial < 1) {
                    $message = self::maybe_translate_sprintf(
                        'Queued Varnish invalidation completed for %d URL(s).',
                        $succeeded
                    );
                } else {
                    $message = self::maybe_translate_sprintf(
                        'Queued Varnish invalidation completed %1$d URL(s), retained %2$d for retry, and ended %3$d with terminal errors.',
                        $succeeded,
                        $retried,
                        $failed
                    );
                }

                if (!empty($pipeline_queue['message'])) {
                    $message .= ' ' . (string) $pipeline_queue['message'];
                }

                $summary = array(
                    'success' => $processed > 0 && $failed < 1 && $retried < 1,
                    'partial' => $succeeded > 0 && ($failed > 0 || $retried > 0 || $partial > 0),
                    'message' => $message,
                    'time' => time(),
                    'scope' => 'queued',
                    'operationType' => 'queued-invalidation',
                    'effectiveMethod' => $effective_method,
                    'invalidationStrategy' => $invalidation_strategy,
                    'receivedUrlCount' => $processed,
                    'validUrlCount' => $processed,
                    'uniqueUrlCount' => $processed,
                    'duplicateUrlCount' => 0,
                    'rejectedUrlCount' => 0,
                    'queueProcessedUrlCount' => $processed,
                    'fullyInvalidatedUrlCount' => $succeeded,
                    'partiallyInvalidatedUrlCount' => $partial,
                    'retryingUrlCount' => $retried,
                    'failedUrlCount' => $failed,
                    'requestCount' => $endpoint_requests,
                    'successfulEndpointRequestCount' => $successful_endpoint_requests,
                    'failedEndpointRequestCount' => $failed_endpoint_requests,
                    'refillQueued' => !empty($pipeline_queue['queued']),
                    'refillQueuedUrlCount' => max(0, (int) ($pipeline_queue['queuedUrlCount'] ?? 0)),
                    'refillMode' => !empty($pipeline_queue['queued']) ? 'shared-page-warm-pipeline' : 'none',
                    'strictOriginRequired' => $requires_verified_origin,
                    'detailCount' => count($aggregate_details),
                    'detailsTruncated' => $aggregate_details_truncated,
                    'details' => $aggregate_details,
                    'queue' => self::get_varnish_queue_stats(),
                );
                self::set_varnish_last_result($summary);
            }

            self::prune_completed_varnish_auxiliary_queue_rows();
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
            'partial' => $partial,
            'retrying' => $retried,
            'queue' => self::get_varnish_queue_stats(),
        );
    }

    /**
     * Prune terminal auxiliary queue rows after bounded retention.
     *
     * @return int
     */
    private static function prune_completed_varnish_auxiliary_queue_rows()
    {
        global $wpdb;
        if (!($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return 0;
        }

        $completed_retention = max(HOUR_IN_SECONDS, min(7 * DAY_IN_SECONDS, (int) apply_filters('ultracache_varnish_queue_completed_retention', DAY_IN_SECONDS)));
        $error_retention = max(DAY_IN_SECONDS, min(30 * DAY_IN_SECONDS, (int) apply_filters('ultracache_varnish_queue_error_retention', 14 * DAY_IN_SECONDS)));
        $table = self::get_cron_warm_queue_table_name();
        $now = time();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prunes retained successful/skipped UltraCache auxiliary rows.
        $completed_deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE (job_type = %s OR (job_type = %s AND source_context <> %s)) AND status IN (%s, %s) AND processed_at > %d AND processed_at < %d",
                $table,
                'varnish_invalidate',
                'page_warm',
                '',
                'done',
                'skipped',
                0,
                $now - $completed_retention
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retains terminal errors longer for diagnostics, then bounds table growth.
        $error_deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE (job_type = %s OR (job_type = %s AND source_context <> %s)) AND status = %s AND processed_at > %d AND processed_at < %d",
                $table,
                'varnish_invalidate',
                'page_warm',
                '',
                'error',
                0,
                $now - $error_retention
            )
        );

        $completed_deleted = false === $completed_deleted ? 0 : max(0, (int) $completed_deleted);
        $error_deleted = false === $error_deleted ? 0 : max(0, (int) $error_deleted);
        if (($completed_deleted > 0 || $error_deleted > 0) && method_exists(static::class, 'record_varnish_queue_prune_metrics')) {
            self::record_varnish_queue_prune_metrics($completed_deleted, $error_deleted);
        }
        return $completed_deleted + $error_deleted;
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
