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
            $registry = method_exists(static::class, 'get_varnish_endpoint_capability_registry_status')
                ? self::get_varnish_endpoint_capability_registry_status($settings)
                : array();
            $capabilities = is_array($registry['effective'] ?? null) ? $registry['effective'] : array();
            $operations = !empty($capabilities['batchBan'])
                ? self::build_varnish_ban_batches($prepared['urls'])
                : self::build_varnish_exact_ban_batches($prepared['urls']);
            return count($operations) * $endpoint_count;
        }

        return count($prepared['urls']) * $endpoint_count;
    }

    /**
     * Bound synchronous invalidation when a large set cannot enter durable storage.
     *
     * @return int
     */
    private static function get_varnish_queue_failure_sync_url_limit()
    {
        $limit = (int) apply_filters('ultracache_varnish_queue_failure_sync_url_limit', 20);
        return max(1, min(200, $limit));
    }

    /**
     * Bound transport requests during a queue-failure synchronous fallback.
     *
     * @return int
     */
    private static function get_varnish_queue_failure_sync_request_limit()
    {
        $limit = (int) apply_filters('ultracache_varnish_queue_failure_sync_request_limit', 20);
        return max(1, min(200, $limit));
    }

    /**
     * Extract canonical URL strings from a prepared invalidation set.
     *
     * @param array $prepared Prepared invalidation set.
     * @return array<int,string>
     */
    private static function get_varnish_prepared_canonical_urls(array $prepared)
    {
        $canonical_urls = array();
        foreach ((array) ($prepared['urls'] ?? array()) as $item) {
            $canonical_url = isset($item['url']) ? esc_url_raw((string) $item['url']) : '';
            if ('' !== $canonical_url) {
                $canonical_urls[$canonical_url] = $canonical_url;
            }
        }

        return array_values($canonical_urls);
    }

    /**
     * Build the common typed queue-decision envelope.
     *
     * @param string $mode         Decision mode.
     * @param string $reason       Decision reason.
     * @param array  $prepared     Prepared invalidation set.
     * @param array  $runtime_plan Runtime operation plan.
     * @param array  $extra        Additional decision fields.
     * @return array<string,mixed>
     */
    private static function build_varnish_invalidation_queue_decision($mode, $reason, array $prepared, array $runtime_plan, array $extra = array())
    {
        $canonical_urls = self::get_varnish_prepared_canonical_urls($prepared);
        $decision = array(
            'mode' => sanitize_key((string) $mode),
            'reason' => sanitize_key((string) $reason),
            'queueAttempted' => false,
            'preparedUrls' => $canonical_urls,
            'directUrls' => array(),
            'fallbackDirectUrls' => array(),
            'deferredToTtlUrlCount' => 0,
            'receivedUrlCount' => (int) ($prepared['receivedCount'] ?? 0),
            'validUrlCount' => (int) ($prepared['validCount'] ?? count($canonical_urls)),
            'uniqueUrlCount' => (int) ($prepared['uniqueCount'] ?? count($canonical_urls)),
            'duplicateUrlCount' => (int) ($prepared['duplicateCount'] ?? 0),
            'rejectedUrlCount' => (int) ($prepared['rejectedCount'] ?? 0),
            'rejections' => (array) ($prepared['rejections'] ?? array()),
            'rejectionsTruncated' => !empty($prepared['rejectionsTruncated']),
            'runtimePlan' => $runtime_plan,
            'result' => array(),
        );

        return array_merge($decision, $extra);
    }

    /**
     * Build a no-transport queue decision result.
     *
     * @param string $mode         Decision mode.
     * @param string $reason       Decision reason.
     * @param string $message      Visible result message.
     * @param array  $prepared     Prepared invalidation set.
     * @param array  $runtime_plan Runtime operation plan.
     * @param string $scope        Invalidation scope.
     * @return array<string,mixed>
     */
    private static function build_varnish_queue_terminal_decision($mode, $reason, $message, array $prepared, array $runtime_plan, $scope)
    {
        $result = array(
            'success' => false,
            'partial' => false,
            'warning' => true,
            'skipped' => 'unavailable' === (string) $mode,
            'unsupported' => 'runtime-unavailable' === (string) $reason,
            'message' => (string) $message,
            'time' => time(),
            'scope' => sanitize_key((string) $scope),
            'operationType' => 'queued-invalidation',
            'queued' => false,
            'queueMode' => sanitize_key((string) $mode),
            'queueReason' => sanitize_key((string) $reason),
            'receivedUrlCount' => (int) ($prepared['receivedCount'] ?? 0),
            'validUrlCount' => (int) ($prepared['validCount'] ?? 0),
            'uniqueUrlCount' => (int) ($prepared['uniqueCount'] ?? 0),
            'duplicateUrlCount' => (int) ($prepared['duplicateCount'] ?? 0),
            'rejectedUrlCount' => (int) ($prepared['rejectedCount'] ?? 0),
            'acceptedUrlCount' => 0,
            'failedUrlCount' => (int) ($prepared['uniqueCount'] ?? 0),
            'rejections' => (array) ($prepared['rejections'] ?? array()),
            'rejectionsTruncated' => !empty($prepared['rejectionsTruncated']),
            'queue' => self::get_varnish_queue_stats(),
        );
        $result = self::finalize_varnish_runtime_result($result, $runtime_plan, false);

        return self::build_varnish_invalidation_queue_decision(
            $mode,
            $reason,
            $prepared,
            $runtime_plan,
            array('result' => $result)
        );
    }

    /**
     * Build a bounded direct-fallback decision after durable queue failure.
     *
     * The fallback is constrained by both canonical URL count and the actual
     * endpoint request estimate. A small URL set can still be expensive in
     * exact-PURGE mode or when many endpoints are configured.
     *
     * @param string $mode         Decision mode.
     * @param string $reason       Decision reason.
     * @param array  $prepared     Prepared invalidation set.
     * @param array  $runtime_plan Runtime operation plan.
     * @param array  $settings     Varnish connection settings.
     * @param string $scope        Invalidation scope.
     * @return array<string,mixed>
     */
    private static function build_varnish_queue_failure_decision($mode, $reason, array $prepared, array $runtime_plan, array $settings, $scope)
    {
        $canonical_urls = self::get_varnish_prepared_canonical_urls($prepared);
        $sync_url_limit = self::get_varnish_queue_failure_sync_url_limit();
        $sync_request_limit = self::get_varnish_queue_failure_sync_request_limit();
        $max_candidate_count = min(count($canonical_urls), $sync_url_limit);
        $fallback_urls = array();
        $fallback_request_count = 0;

        for ($candidate_count = 1; $candidate_count <= $max_candidate_count; ++$candidate_count) {
            $candidate_prepared = $prepared;
            $candidate_prepared['urls'] = array_slice((array) ($prepared['urls'] ?? array()), 0, $candidate_count);
            $candidate_prepared['uniqueCount'] = $candidate_count;
            $candidate_request_count = self::estimate_varnish_invalidation_request_count($candidate_prepared, $settings);
            if ($candidate_request_count > $sync_request_limit) {
                break;
            }
            $fallback_urls = array_slice($canonical_urls, 0, $candidate_count);
            $fallback_request_count = $candidate_request_count;
        }

        $deferred_count = max(0, count($canonical_urls) - count($fallback_urls));
        $result = array(
            'success' => false,
            'partial' => false,
            'warning' => true,
            'message' => empty($fallback_urls)
                ? self::maybe_translate_sprintf(
                    'The persistent Varnish invalidation queue was unavailable and no URL fit inside the bounded synchronous request limit. %d URL(s) were left to expire by TTL.',
                    $deferred_count
                )
                : self::maybe_translate('The persistent Varnish invalidation queue was unavailable. A bounded synchronous fallback may be attempted.'),
            'time' => time(),
            'scope' => sanitize_key((string) $scope),
            'operationType' => 'queued-invalidation',
            'queued' => false,
            'queueMode' => sanitize_key((string) $mode),
            'queueReason' => sanitize_key((string) $reason),
            'queueAttempted' => true,
            'queueFallback' => !empty($fallback_urls),
            'queueFallbackDirectUrlCount' => count($fallback_urls),
            'queueFallbackDirectUrlLimit' => $sync_url_limit,
            'queueFallbackEstimatedRequestCount' => $fallback_request_count,
            'queueFallbackRequestLimit' => $sync_request_limit,
            'deferredToTtlUrlCount' => $deferred_count,
            'receivedUrlCount' => (int) ($prepared['receivedCount'] ?? 0),
            'validUrlCount' => (int) ($prepared['validCount'] ?? count($canonical_urls)),
            'uniqueUrlCount' => (int) ($prepared['uniqueCount'] ?? count($canonical_urls)),
            'duplicateUrlCount' => (int) ($prepared['duplicateCount'] ?? 0),
            'rejectedUrlCount' => (int) ($prepared['rejectedCount'] ?? 0),
            'acceptedUrlCount' => 0,
            'failedUrlCount' => $deferred_count,
            'rejections' => (array) ($prepared['rejections'] ?? array()),
            'rejectionsTruncated' => !empty($prepared['rejectionsTruncated']),
            'queue' => self::get_varnish_queue_stats(),
        );
        $result = self::finalize_varnish_runtime_result($result, $runtime_plan, false);

        return self::build_varnish_invalidation_queue_decision(
            $mode,
            $reason,
            $prepared,
            $runtime_plan,
            array(
                'queueAttempted' => true,
                'fallbackDirectUrls' => $fallback_urls,
                'fallbackDirectUrlLimit' => $sync_url_limit,
                'fallbackEstimatedRequestCount' => $fallback_request_count,
                'fallbackRequestLimit' => $sync_request_limit,
                'deferredToTtlUrlCount' => $deferred_count,
                'result' => $result,
            )
        );
    }

    /**
     * Attach queue-failure fallback accounting to a direct invalidation result.
     *
     * @param array $result   Direct invalidation result.
     * @param array $decision Typed queue decision.
     * @return array<string,mixed>
     */
    private static function apply_varnish_queue_decision_to_direct_result(array $result, array $decision)
    {
        $mode = sanitize_key((string) ($decision['mode'] ?? 'direct'));
        $reason = sanitize_key((string) ($decision['reason'] ?? 'below-threshold'));
        $fallback_count = count((array) ($decision['fallbackDirectUrls'] ?? array()));
        $deferred_count = max(0, (int) ($decision['deferredToTtlUrlCount'] ?? 0));

        $result['queueMode'] = $mode;
        $result['queueReason'] = $reason;
        $result['queueAttempted'] = !empty($decision['queueAttempted']);
        $result['queueFallback'] = in_array($mode, array('unavailable', 'failed'), true);
        $result['queueFallbackDirectUrlCount'] = $fallback_count;
        $result['queueFallbackDirectUrlLimit'] = max(0, (int) ($decision['fallbackDirectUrlLimit'] ?? 0));
        $result['queueFallbackEstimatedRequestCount'] = max(0, (int) ($decision['fallbackEstimatedRequestCount'] ?? 0));
        $result['queueFallbackRequestLimit'] = max(0, (int) ($decision['fallbackRequestLimit'] ?? 0));
        $result['deferredToTtlUrlCount'] = $deferred_count;
        $result['receivedUrlCount'] = (int) ($decision['receivedUrlCount'] ?? $result['receivedUrlCount'] ?? 0);
        $result['validUrlCount'] = (int) ($decision['validUrlCount'] ?? $result['validUrlCount'] ?? 0);
        $result['uniqueUrlCount'] = (int) ($decision['uniqueUrlCount'] ?? $result['uniqueUrlCount'] ?? 0);
        $result['duplicateUrlCount'] = (int) ($decision['duplicateUrlCount'] ?? $result['duplicateUrlCount'] ?? 0);
        $result['rejectedUrlCount'] = (int) ($decision['rejectedUrlCount'] ?? $result['rejectedUrlCount'] ?? 0);
        $result['rejections'] = (array) ($decision['rejections'] ?? $result['rejections'] ?? array());
        $result['rejectionsTruncated'] = !empty($decision['rejectionsTruncated']);

        if (!empty($result['queueFallback'])) {
            $result['warning'] = true;
            if ($deferred_count > 0) {
                $result['partial'] = !empty($result['success']) || !empty($result['partial']);
                $result['success'] = false;
                $result['failedUrlCount'] = max(0, (int) ($result['failedUrlCount'] ?? 0)) + $deferred_count;
                $result['message'] = trim((string) ($result['message'] ?? '')) . ' ' . self::maybe_translate_sprintf(
                    'The persistent Varnish invalidation queue was unavailable: %1$d URL(s) used the bounded synchronous fallback and %2$d URL(s) were left to expire by TTL.',
                    $fallback_count,
                    $deferred_count
                );
            } else {
                $result['message'] = trim((string) ($result['message'] ?? '')) . ' ' . self::maybe_translate_sprintf(
                    'The persistent Varnish invalidation queue was unavailable, so all %d URL(s) used the bounded synchronous fallback.',
                    $fallback_count
                );
            }
        }

        return $result;
    }

    /**
     * Queue a large targeted invalidation set when persistent storage is ready.
     *
     * Every path returns an explicit typed decision. Callers must never infer
     * queue availability from the PHP value type.
     *
     * @param array  $urls  Candidate URLs.
     * @param string $scope Invalidation scope.
     * @return array<string,mixed>
     */
    private static function maybe_queue_varnish_invalidation(array $urls, $scope = 'batch')
    {
        $settings = self::get_varnish_cli_settings();
        $runtime_plan = self::plan_varnish_runtime_operation('targeted');
        $empty_prepared = array(
            'urls' => array(),
            'receivedCount' => count($urls),
            'validCount' => 0,
            'uniqueCount' => 0,
            'duplicateCount' => 0,
            'rejectedCount' => 0,
            'rejections' => array(),
            'rejectionsTruncated' => false,
        );
        if (empty($runtime_plan['canExecute'])) {
            return self::build_varnish_queue_terminal_decision(
                'unavailable',
                'runtime-unavailable',
                self::maybe_translate('The current Varnish runtime contract cannot execute targeted invalidation.'),
                $empty_prepared,
                $runtime_plan,
                $scope
            );
        }

        $prepared = self::prepare_varnish_invalidation_urls($urls);
        $canonical_urls = self::get_varnish_prepared_canonical_urls($prepared);
        if (empty($canonical_urls)) {
            return self::build_varnish_queue_terminal_decision(
                'failed',
                'invalid-input',
                self::maybe_translate('No valid local URL remained after Varnish invalidation validation.'),
                $prepared,
                $runtime_plan,
                $scope
            );
        }

        $estimated_requests = self::estimate_varnish_invalidation_request_count($prepared, $settings);
        $unique_count = (int) ($prepared['uniqueCount'] ?? count($canonical_urls));
        if (
            $unique_count <= self::get_varnish_queue_unique_url_threshold()
            && $estimated_requests <= self::get_varnish_queue_request_threshold()
        ) {
            return self::build_varnish_invalidation_queue_decision(
                'direct',
                'below-threshold',
                $prepared,
                $runtime_plan,
                array(
                    'directUrls' => $canonical_urls,
                    'estimatedRequestCount' => $estimated_requests,
                )
            );
        }

        if (!method_exists(static::class, 'insert_cron_warm_queue_urls') || !self::ensure_cron_warm_queue_table()) {
            return self::build_varnish_queue_failure_decision('unavailable', 'storage-unavailable', $prepared, $runtime_plan, $settings, $scope);
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
            return self::build_varnish_queue_failure_decision('failed', 'persist-failed', $prepared, $runtime_plan, $settings, $scope);
        }

        // Foreground ownership pauses execution, not durable Varnish work
        // discovery. Queued invalidation has its own operation rate and must be
        // scheduled even when page warming is disabled.
        if (!self::is_manual_warmup_blocking_cron()) {
            self::ensure_cron_warm_events_scheduled(1);
        }
        $queue_failed = max(0, $unique_count - $queued);
        $queue = self::get_varnish_queue_stats();
        $result = array(
            'success' => $queue_failed < 1,
            'partial' => $queue_failed > 0,
            'warning' => $queue_failed > 0,
            'queued' => true,
            'queueMode' => 'queued',
            'queueReason' => $queue_failed > 0 ? 'partial-persist' : 'persisted',
            'queueAttempted' => true,
            'message' => $queue_failed > 0
                ? self::maybe_translate_sprintf(
                    'Accepted %1$d Varnish URL invalidation(s): %2$d inserted, %3$d coalesced, %4$d upgraded, and %5$d could not be persisted. The unpersisted URL(s) were left to expire by TTL.',
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
            'deferredToTtlUrlCount' => $queue_failed,
            'failedUrlCount' => $queue_failed,
            'rejections' => (array) ($prepared['rejections'] ?? array()),
            'rejectionsTruncated' => !empty($prepared['rejectionsTruncated']),
            'queue' => $queue,
        );
        $result = self::finalize_varnish_runtime_result($result, $runtime_plan, false);
        $result['runtimeExecutionState'] = 'queued';
        self::set_varnish_last_result($result);

        return self::build_varnish_invalidation_queue_decision(
            'queued',
            $queue_failed > 0 ? 'partial-persist' : 'persisted',
            $prepared,
            $runtime_plan,
            array(
                'queueAttempted' => true,
                'estimatedRequestCount' => $estimated_requests,
                'deferredToTtlUrlCount' => $queue_failed,
                'result' => $result,
            )
        );
    }

    /**
     * Read bounded aggregate Varnish queue counters.
     *
     * @param array<string,mixed> $status_context Optional read-only worker snapshot.
     * @return array
     */
    private static function get_varnish_queue_stats(array $status_context = array())
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
            'terminalInvalidationErrors' => 0,
            'terminalRefillErrors' => 0,
            'failed' => 0,
            'retryAttempts' => 0,
            'nextAttemptAt' => 0,
            'terminalErrorDetails' => array(),
            'terminalErrorDetailsTruncated' => false,
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
        // Legacy varnish_invalidate rows remain executable only while an old processing
        // lease drains. New work is represented by the varnish stage on one canonical
        // page_warm row per URL.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads aggregate counters from one UltraCache-owned queue table query.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN job_type = %s AND status = %s THEN 1 ELSE 0 END) AS pending_invalidations,
                    SUM(CASE WHEN job_type = %s AND status = %s THEN 1 ELSE 0 END) AS processing_invalidations,
                    SUM(CASE WHEN job_type = %s AND FIND_IN_SET(%s, required_stages) > %d AND status = %s THEN 1 ELSE 0 END) AS pending_refills,
                    SUM(CASE WHEN job_type = %s AND FIND_IN_SET(%s, required_stages) > %d AND status = %s THEN 1 ELSE 0 END) AS processing_refills,
                    SUM(CASE WHEN (job_type = %s OR (job_type = %s AND FIND_IN_SET(%s, required_stages) > %d)) AND status = %s AND attempt_count = %d THEN 1 ELSE 0 END) AS planned_jobs,
                    SUM(CASE WHEN (job_type = %s OR (job_type = %s AND FIND_IN_SET(%s, required_stages) > %d)) AND status = %s THEN 1 ELSE 0 END) AS processing_jobs,
                    SUM(CASE WHEN (job_type = %s OR (job_type = %s AND FIND_IN_SET(%s, required_stages) > %d)) AND status = %s AND attempt_count > %d THEN 1 ELSE 0 END) AS retrying_jobs,
                    SUM(CASE WHEN (job_type = %s OR (job_type = %s AND FIND_IN_SET(%s, required_stages) > %d)) AND status = %s AND result_level = %s THEN 1 ELSE 0 END) AS partial_jobs,
                    SUM(CASE WHEN (job_type = %s OR (job_type = %s AND FIND_IN_SET(%s, required_stages) > %d)) AND status = %s AND result_level = %s THEN 1 ELSE 0 END) AS warning_jobs,
                    SUM(CASE WHEN (job_type = %s OR (job_type = %s AND FIND_IN_SET(%s, required_stages) > %d)) AND status = %s AND result_level <> %s THEN 1 ELSE 0 END) AS completed_jobs,
                    SUM(CASE WHEN (job_type = %s OR (job_type = %s AND FIND_IN_SET(%s, required_stages) > %d)) AND status = %s THEN 1 ELSE 0 END) AS skipped_jobs,
                    SUM(CASE WHEN (job_type = %s OR (job_type = %s AND FIND_IN_SET(%s, required_stages) > %d)) AND status = %s THEN 1 ELSE 0 END) AS terminal_errors,
                    SUM(CASE WHEN job_type = %s AND status = %s THEN 1 ELSE 0 END) AS terminal_invalidation_errors,
                    SUM(CASE WHEN job_type = %s AND FIND_IN_SET(%s, required_stages) > %d AND status = %s THEN 1 ELSE 0 END) AS terminal_refill_errors,
                    SUM(CASE WHEN (job_type = %s OR (job_type = %s AND FIND_IN_SET(%s, required_stages) > %d)) AND status = %s AND attempt_count > %d THEN attempt_count ELSE 0 END) AS retry_attempts,
                    MIN(CASE WHEN (job_type = %s OR (job_type = %s AND FIND_IN_SET(%s, required_stages) > %d)) AND status = %s AND next_attempt_at > %d THEN next_attempt_at ELSE NULL END) AS next_attempt_at
                FROM %i
                WHERE job_type IN (%s, %s)",
                'varnish_invalidate', 'pending',
                'varnish_invalidate', 'processing',
                'page_warm', 'varnish', 0, 'pending',
                'page_warm', 'varnish', 0, 'processing',
                'varnish_invalidate', 'page_warm', 'varnish', 0, 'pending', 0,
                'varnish_invalidate', 'page_warm', 'varnish', 0, 'processing',
                'varnish_invalidate', 'page_warm', 'varnish', 0, 'pending', 0,
                'varnish_invalidate', 'page_warm', 'varnish', 0, 'pending', 'partial',
                'varnish_invalidate', 'page_warm', 'varnish', 0, 'done', 'warning',
                'varnish_invalidate', 'page_warm', 'varnish', 0, 'done', 'warning',
                'varnish_invalidate', 'page_warm', 'varnish', 0, 'skipped',
                'varnish_invalidate', 'page_warm', 'varnish', 0, 'error',
                'varnish_invalidate', 'error',
                'page_warm', 'varnish', 0, 'error',
                'varnish_invalidate', 'page_warm', 'varnish', 0, 'pending', 0,
                'varnish_invalidate', 'page_warm', 'varnish', 0, 'pending', time(),
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
        $stats['terminalInvalidationErrors'] = max(0, (int) ($row['terminal_invalidation_errors'] ?? 0));
        $stats['terminalRefillErrors'] = max(0, (int) ($row['terminal_refill_errors'] ?? 0));
        $stats['failed'] = $stats['terminalErrors'];
        $stats['retryAttempts'] = max(0, (int) ($row['retry_attempts'] ?? 0));
        $stats['nextAttemptAt'] = max(0, (int) ($row['next_attempt_at'] ?? 0));

        if ($stats['terminalErrors'] > 0) {
            $detail_limit = 3;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads a bounded current error sample from the UltraCache-owned queue for actionable dashboard output.
            $error_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT url, job_type, source_context, result_message, attempt_count, processed_at, updated_at
                    FROM %i
                    WHERE (job_type = %s OR (job_type = %s AND FIND_IN_SET(%s, required_stages) > %d)) AND status = %s
                    ORDER BY CASE WHEN processed_at > 0 THEN processed_at ELSE updated_at END DESC, id DESC
                    LIMIT %d",
                    $table,
                    'varnish_invalidate',
                    'page_warm',
                    'varnish',
                    0,
                    'error',
                    $detail_limit
                ),
                ARRAY_A
            );
            if (is_array($error_rows)) {
                foreach ($error_rows as $error_row) {
                    $job_type = sanitize_key((string) ($error_row['job_type'] ?? ''));
                    $stats['terminalErrorDetails'][] = array(
                        'type' => ('varnish_invalidate' === $job_type) ? 'invalidation' : 'pipeline',
                        'url' => esc_url_raw((string) ($error_row['url'] ?? '')),
                        'sourceContext' => sanitize_key((string) ($error_row['source_context'] ?? '')),
                        'message' => sanitize_textarea_field((string) ($error_row['result_message'] ?? '')),
                        'attempts' => max(0, (int) ($error_row['attempt_count'] ?? 0)),
                        'failedAt' => max(0, (int) (($error_row['processed_at'] ?? 0) ?: ($error_row['updated_at'] ?? 0))),
                        'retryStopped' => true,
                    );
                }
            }
            $stats['terminalErrorDetailsTruncated'] = $stats['terminalErrors'] > count($stats['terminalErrorDetails']);
        }

        if (method_exists(static::class, 'get_targeted_page_warm_worker_status')) {
            $stats['refillWorker'] = self::get_targeted_page_warm_worker_status(
                $stats['pendingRefills'],
                false,
                $status_context
            );
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
     * Whether persistent Varnish invalidations still need to run before warming.
     *
     * Shared page-refill rows are deliberately excluded: they may run after a
     * site warm, but an invalidation must never be allowed to purge an object
     * that the same site-warm pipeline has just refreshed.
     *
     * @return bool
     */
    private static function has_pending_varnish_invalidation_rows()
    {
        $stats = self::get_varnish_queue_stats();
        return !empty($stats['pendingInvalidations']) || !empty($stats['processingInvalidations']);
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
     * Atomically claim one Varnish invalidation row without charging a retry.
     *
     * Retry attempts belong to failed transport operations, not queue ownership
     * or budget deferral. The generic warm-row claim increments attempts, so the
     * Varnish operation worker uses this bounded claim primitive instead.
     *
     * @param array $candidate Pending Varnish queue row.
     * @return array<string,mixed>
     */
    private static function claim_varnish_queue_row_for_operations(array $candidate)
    {
        global $wpdb;

        $row_id = absint($candidate['id'] ?? 0);
        if ($row_id < 1 || !($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return array();
        }

        $table = self::get_cron_warm_queue_table_name();
        $now = time();
        $claim_token = 'varnish-' . wp_generate_password(32, false, false);
        $lease_expires_at = $now + self::get_cron_warm_queue_lease_seconds();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional UPDATE is the atomic Varnish queue ownership primitive.
        $claimed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET status = %s, claim_token = %s, claimed_at = %d, lease_expires_at = %d, rerun_requested = %d, updated_at = %d WHERE id = %d AND status = %s AND next_attempt_at <= %d",
                $table,
                'processing',
                $claim_token,
                $now,
                $lease_expires_at,
                0,
                $now,
                $row_id,
                'pending',
                $now
            )
        );
        if (1 !== (int) $claimed) {
            return array();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads back only the Varnish row owned by the new claim token.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, url, job_type, source_context, source_contexts, required_stages, completed_stages, requires_verified_origin, status, claim_token, claimed_at, lease_expires_at, rerun_requested, pending_targets, attempt_count, next_attempt_at FROM %i WHERE id = %d AND status = %s AND claim_token = %s",
                $table,
                $row_id,
                'processing',
                $claim_token
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : array();
    }

    /**
     * Release Varnish operation ownership without changing retry accounting.
     *
     * @param array  $row     Claimed Varnish row.
     * @param string $message Result detail.
     * @param int    $delay   Delay before the next ownership attempt.
     * @return bool
     */
    private static function release_varnish_queue_operation_claim(array $row, $message = '', $delay = 15)
    {
        global $wpdb;

        $row_id = absint($row['id'] ?? 0);
        $claim_token = sanitize_text_field((string) ($row['claim_token'] ?? ''));
        if ($row_id < 1 || '' === $claim_token || !($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return false;
        }

        $message = sanitize_textarea_field((string) $message);
        if (strlen($message) > 2000) {
            $message = substr($message, 0, 2000);
        }
        $rerun_message = self::maybe_translate('A newer Varnish invalidation request arrived while this row was processing; every configured endpoint will run again.');
        $now = time();
        $delay = max(0, min(600, absint($delay)));
        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases only the Varnish row still owned by this claim token.
        $released = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET status = %s, result_level = CASE WHEN rerun_requested = %d THEN %s ELSE result_level END, claim_token = %s, claimed_at = %d, lease_expires_at = %d, pending_targets = CASE WHEN rerun_requested = %d THEN %s ELSE pending_targets END, result_message = CASE WHEN rerun_requested = %d THEN %s ELSE %s END, attempt_count = CASE WHEN rerun_requested = %d THEN %d ELSE attempt_count END, next_attempt_at = CASE WHEN rerun_requested = %d THEN %d ELSE %d END, updated_at = %d, processed_at = %d, rerun_requested = %d WHERE id = %d AND status = %s AND claim_token = %s",
                $table,
                'pending',
                1,
                '',
                '',
                0,
                0,
                1,
                '',
                1,
                $rerun_message,
                $message,
                1,
                0,
                1,
                0,
                $now + $delay,
                $now,
                0,
                0,
                $row_id,
                'processing',
                $claim_token
            )
        );

        return 1 === (int) $released;
    }

    /**
     * Build endpoint-operation units for ready Varnish queue rows.
     *
     * Every returned unit maps to exactly one transport operation: one exact
     * PURGE or exact BAN for one URL and endpoint, or one bounded verified BAN
     * batch and endpoint.
     *
     * @param array $rows            Ready queue rows.
     * @param array $settings        Varnish settings snapshot.
     * @param array $current_targets Current configured endpoint labels.
     * @return array<string,mixed>
     */
    private static function build_varnish_queue_operation_plan(array $rows, array $settings, array $current_targets)
    {
        $current_targets = self::resolve_varnish_invalidation_targets($current_targets);
        sort($current_targets, SORT_STRING);
        $row_states = array();
        $groups = array();

        foreach ($rows as $row) {
            $row_id = absint($row['id'] ?? 0);
            $url = isset($row['url']) ? (string) $row['url'] : '';
            if ($row_id < 1 || '' === $url) {
                continue;
            }

            $target_state = self::decode_varnish_queue_pending_targets($row['pending_targets'] ?? '');
            $targets = (array) ($target_state['pending'] ?? array());
            $required_targets = (array) ($target_state['required'] ?? array());
            if (empty($targets) || $required_targets !== $current_targets) {
                $targets = $current_targets;
                $required_targets = $current_targets;
            }
            $targets = self::resolve_varnish_invalidation_targets($current_targets, $targets);
            sort($targets, SORT_STRING);
            if (empty($targets)) {
                continue;
            }

            $prepared = self::prepare_varnish_invalidation_urls(array($url));
            $prepared_item = isset($prepared['urls'][0]) && is_array($prepared['urls'][0])
                ? $prepared['urls'][0]
                : array();
            $canonical_url = (string) ($prepared_item['url'] ?? '');
            if ('' === $canonical_url) {
                continue;
            }

            $row_states[$row_id] = array(
                'row' => $row,
                'url' => $canonical_url,
                'prepared' => $prepared_item,
                'pendingTargets' => $targets,
                'requiredTargets' => $required_targets,
            );
            $group_key = hash('sha256', (string) wp_json_encode(array($targets, $required_targets)));
            if (!isset($groups[$group_key])) {
                $groups[$group_key] = array(
                    'targets' => $targets,
                    'requiredTargets' => $required_targets,
                    'rowIds' => array(),
                    'prepared' => array(),
                );
            }
            $groups[$group_key]['rowIds'][$canonical_url] = $row_id;
            $groups[$group_key]['prepared'][] = $prepared_item;
        }

        $mode = (string) ($settings['mode'] ?? 'http');
        $strategy = self::get_varnish_invalidation_strategy_status($settings);
        $effective_strategy = (string) ($strategy['effective'] ?? 'ban');
        $uses_ban_transport = 'admin' === $mode || 'ban' === $effective_strategy;
        $registry = $uses_ban_transport && method_exists(static::class, 'get_varnish_endpoint_capability_registry_status')
            ? self::get_varnish_endpoint_capability_registry_status($settings)
            : array();
        $effective_capabilities = is_array($registry['effective'] ?? null)
            ? $registry['effective']
            : array();
        $batch_ban_verified = $uses_ban_transport && !empty($effective_capabilities['batchBan']);
        $uses_ban_batches = $uses_ban_transport && $batch_ban_verified;
        $operations = array();

        foreach ($groups as $group) {
            $targets = (array) ($group['targets'] ?? array());
            $required_targets = (array) ($group['requiredTargets'] ?? array());
            $row_ids_by_url = (array) ($group['rowIds'] ?? array());
            if ($uses_ban_transport) {
                $ban_operations = $uses_ban_batches
                    ? self::build_varnish_ban_batches((array) ($group['prepared'] ?? array()))
                    : self::build_varnish_exact_ban_batches((array) ($group['prepared'] ?? array()));
                foreach ($ban_operations as $batch) {
                    $batch_urls = array_values((array) ($batch['urls'] ?? array()));
                    $batch_row_ids = array();
                    foreach ($batch_urls as $batch_url) {
                        if (isset($row_ids_by_url[$batch_url])) {
                            $batch_row_ids[] = (int) $row_ids_by_url[$batch_url];
                        }
                    }
                    if (empty($batch_row_ids)) {
                        continue;
                    }
                    foreach ($targets as $target) {
                        $operations[] = array(
                            'type' => $uses_ban_batches ? 'ban-batch' : 'exact-ban',
                            'target' => (string) $target,
                            'urls' => $batch_urls,
                            'rowIds' => $batch_row_ids,
                            'requiredTargets' => $required_targets,
                        );
                    }
                }
                continue;
            }

            foreach ((array) ($group['prepared'] ?? array()) as $prepared_item) {
                $canonical_url = (string) ($prepared_item['url'] ?? '');
                $row_id = isset($row_ids_by_url[$canonical_url]) ? (int) $row_ids_by_url[$canonical_url] : 0;
                if ($row_id < 1) {
                    continue;
                }
                foreach ($targets as $target) {
                    $operations[] = array(
                        'type' => 'exact-purge',
                        'target' => (string) $target,
                        'urls' => array($canonical_url),
                        'rowIds' => array($row_id),
                        'requiredTargets' => $required_targets,
                    );
                }
            }
        }

        return array(
            'rows' => $row_states,
            'operations' => $operations,
            'operationCount' => count($operations),
            'rowCount' => count($row_states),
            'usesBanTransport' => $uses_ban_transport,
            'usesBanBatches' => $uses_ban_batches,
            'batchBanVerified' => $batch_ban_verified,
            'effectiveStrategy' => $effective_strategy,
        );
    }

    /**
     * Select the bounded operation prefix and the rows required to execute it.
     *
     * @param array $plan             Full operation plan.
     * @param int   $operation_budget Granted endpoint operations.
     * @return array<string,mixed>
     */
    private static function select_varnish_queue_operation_dispatch(array $plan, $operation_budget)
    {
        $operation_budget = max(0, min(600, absint($operation_budget)));
        $operations = array_slice((array) ($plan['operations'] ?? array()), 0, $operation_budget);
        $row_ids = array();
        foreach ($operations as $operation) {
            foreach ((array) ($operation['rowIds'] ?? array()) as $row_id) {
                $row_id = absint($row_id);
                if ($row_id > 0) {
                    $row_ids[$row_id] = true;
                }
            }
        }

        return array(
            'operations' => $operations,
            'operationCount' => count($operations),
            'rowIds' => array_keys($row_ids),
            'rowCount' => count($row_ids),
            'deferredOperationCount' => max(0, count((array) ($plan['operations'] ?? array())) - count($operations)),
        );
    }

    /**
     * Resolve one row's durable outcome after a bounded operation dispatch.
     *
     * @param array $row                  Claimed queue row.
     * @param array $url_result           Aggregated attempted endpoint result.
     * @param array $unattempted_targets  Pending endpoints deferred by budget.
     * @return array<string,mixed>
     */
    private static function resolve_varnish_queue_operation_outcome(array $row, array $url_result, array $unattempted_targets)
    {
        $normalize = static function (array $targets) {
            $normalized = array();
            foreach (array_slice($targets, 0, 32) as $target) {
                $target = trim((string) $target);
                if ('' !== $target && strlen($target) <= 512) {
                    $normalized[$target] = true;
                }
            }
            $normalized = array_keys($normalized);
            sort($normalized, SORT_STRING);
            return $normalized;
        };

        $successful_targets = $normalize((array) ($url_result['successfulEndpointTargets'] ?? array()));
        $failed_targets = $normalize((array) ($url_result['failedEndpointTargets'] ?? array()));
        $attempted_targets = $normalize((array) ($url_result['attemptedEndpointTargets'] ?? array_merge($successful_targets, $failed_targets)));
        $required_targets = $normalize((array) ($url_result['requiredEndpointTargets'] ?? $attempted_targets));
        $unattempted_targets = $normalize($unattempted_targets);
        $pending_targets = $normalize(array_merge($failed_targets, $unattempted_targets));
        $degraded = !empty($url_result['degraded']);
        $transport_failed = !empty($failed_targets);
        $prior_attempt_count = max(0, (int) ($row['attempt_count'] ?? 0));
        $attempt_count = $prior_attempt_count + ($transport_failed ? 1 : 0);
        $retryable = !$transport_failed || !empty($url_result['retryable']);
        $terminal_failure = $transport_failed
            && (!$retryable || $attempt_count >= self::get_varnish_queue_max_attempts());

        $status = 'pending';
        $result_level = !empty($successful_targets) ? 'partial' : '';
        $next_attempt_at = 0;
        $processed_at = 0;
        if ($degraded) {
            $status = 'done';
            $result_level = 'warning';
            $pending_targets = array();
            $processed_at = time();
        } elseif (empty($pending_targets) && !empty($successful_targets)) {
            $status = 'done';
            $result_level = 'success';
            $processed_at = time();
        } elseif ($terminal_failure) {
            $status = 'error';
            $result_level = 'error';
            $processed_at = time();
        } elseif ($transport_failed) {
            $status = 'pending';
            $result_level = !empty($successful_targets) ? 'partial' : 'retrying';
            $next_attempt_at = time() + self::get_varnish_queue_retry_delay($attempt_count);
        }

        $message = (string) ($url_result['message'] ?? '');
        if (!$degraded && !empty($unattempted_targets)) {
            $budget_message = self::maybe_translate_sprintf(
                '%d endpoint operation(s) remain queued for the available Varnish invalidation minute budget.',
                count($unattempted_targets)
            );
            $message = trim($message . ' ' . $budget_message);
        }
        if ('' === $message) {
            $message = 'done' === $status
                ? self::maybe_translate('Varnish invalidation completed on every required endpoint.')
                : self::maybe_translate('Varnish invalidation remains queued.');
        }

        return array(
            'status' => $status,
            'resultLevel' => $result_level,
            'attemptCount' => $attempt_count,
            'nextAttemptAt' => $next_attempt_at,
            'processedAt' => $processed_at,
            'pendingTargets' => $pending_targets,
            'requiredTargets' => $required_targets,
            'successfulTargets' => $successful_targets,
            'failedTargets' => $failed_targets,
            'attemptedTargets' => $attempted_targets,
            'unattemptedTargets' => $unattempted_targets,
            'budgetDeferred' => !empty($unattempted_targets),
            'retrying' => $transport_failed && 'pending' === $status,
            'terminalFailure' => $terminal_failure,
            'degraded' => $degraded,
            'message' => $message,
        );
    }

    /**
     * Persist one bounded Varnish operation dispatch result.
     *
     * @param array $row     Claimed queue row.
     * @param array $outcome Pure resolved durable outcome.
     * @return array<string,mixed>|false
     */
    private static function commit_varnish_queue_operation_outcome(array $row, array $outcome)
    {
        global $wpdb;

        $row_id = absint($row['id'] ?? 0);
        $claim_token = sanitize_text_field((string) ($row['claim_token'] ?? ''));
        if ($row_id < 1 || '' === $claim_token || !($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return false;
        }

        $status = sanitize_key((string) ($outcome['status'] ?? 'pending'));
        if (!in_array($status, array('pending', 'done', 'error'), true)) {
            $status = 'pending';
        }
        $result_level = sanitize_key((string) ($outcome['resultLevel'] ?? ''));
        $message = sanitize_textarea_field((string) ($outcome['message'] ?? ''));
        if (strlen($message) > 2000) {
            $message = substr($message, 0, 2000);
        }
        $required_targets = array_values((array) ($outcome['requiredTargets'] ?? array()));
        $pending_targets = 'pending' === $status
            ? self::encode_varnish_queue_pending_targets(
                array_values((array) ($outcome['pendingTargets'] ?? array())),
                $required_targets
            )
            : '';
        $attempt_count = max(0, min(self::get_varnish_queue_max_attempts(), (int) ($outcome['attemptCount'] ?? 0)));
        $next_attempt_at = 'pending' === $status ? max(0, (int) ($outcome['nextAttemptAt'] ?? 0)) : 0;
        $processed_at = in_array($status, array('done', 'error'), true) ? max(1, (int) ($outcome['processedAt'] ?? time())) : 0;
        $rerun_message = self::maybe_translate('A newer Varnish invalidation request arrived while this row was processing; every configured endpoint will run again.');
        $now = time();
        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Claim token prevents a delayed Varnish worker from overwriting newer queue ownership.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET status = CASE WHEN rerun_requested = %d THEN %s ELSE %s END, result_level = CASE WHEN rerun_requested = %d THEN %s ELSE %s END, claim_token = %s, claimed_at = %d, lease_expires_at = %d, pending_targets = CASE WHEN rerun_requested = %d THEN %s ELSE %s END, result_message = CASE WHEN rerun_requested = %d THEN %s ELSE %s END, attempt_count = CASE WHEN rerun_requested = %d THEN %d ELSE %d END, next_attempt_at = CASE WHEN rerun_requested = %d THEN %d ELSE %d END, updated_at = %d, processed_at = CASE WHEN rerun_requested = %d THEN %d ELSE %d END, rerun_requested = %d WHERE id = %d AND status = %s AND claim_token = %s",
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
                $attempt_count,
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
            'attemptCount' => $saved_attempt_count,
            'requeued' => 'pending' === $saved_status && 0 === $saved_attempt_count && 'pending' !== $status,
            'budgetDeferred' => !empty($outcome['budgetDeferred']) && 'pending' === $saved_status,
            'retrying' => !empty($outcome['retrying']) && 'pending' === $saved_status,
            'pendingTargetState' => is_array($saved) ? self::decode_varnish_queue_pending_targets($saved['pending_targets'] ?? '') : array('pending' => array(), 'required' => array()),
        );
    }

    /**
     * Process ready persistent Varnish jobs before ordinary cron warming.
     *
     * Queue rows are planned into actual endpoint operations before ownership is
     * claimed. The dedicated atomic Varnish budget is charged per exact PURGE or
     * per BAN batch sent to one endpoint, never per URL or database row.
     *
     * @param int $limit Maximum queue rows considered.
     * @return array<string,mixed>
     */
    private static function process_ready_varnish_queue_rows($limit = 100)
    {
        if (method_exists(static::class, 'is_manual_warmup_blocking_cron') && self::is_manual_warmup_blocking_cron()) {
            return array(
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
                'yielded' => true,
                'reason' => 'foreground-warm-priority',
                'queue' => self::get_varnish_queue_stats(),
            );
        }

        $rows = self::load_ready_varnish_queue_rows($limit);
        if (empty($rows)) {
            self::prune_completed_varnish_auxiliary_queue_rows();
            return array('processed' => 0, 'success' => 0, 'failed' => 0, 'queue' => self::get_varnish_queue_stats());
        }

        $lock_ttl = 120;
        $lock_token = 'varnish-queue-' . wp_generate_password(20, false, false);
        $cron_execution_fence = array();
        if (!self::acquire_cron_warm_lock($lock_token, $lock_ttl, $cron_execution_fence)) {
            return array('processed' => 0, 'success' => 0, 'failed' => 0, 'locked' => true, 'queue' => self::get_varnish_queue_stats());
        }
        $cron_generation = max(0, (int) ($cron_execution_fence['generation'] ?? 0));

        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $partial = 0;
        $degraded = 0;
        $retried = 0;
        $budget_deferred = 0;
        $endpoint_requests = 0;
        $successful_endpoint_requests = 0;
        $failed_endpoint_requests = 0;
        $completed_urls = array();
        $aggregate_details = array();
        $aggregate_details_truncated = false;
        $requires_verified_origin = false;
        $effective_method = '';
        $invalidation_strategy = '';
        $rate_claim = array();
        $operation_plan = array();
        $dispatch = array();
        $yielded_to_foreground = false;
        $execution_fence_lost = false;

        try {
            if (method_exists(static::class, 'is_manual_warmup_blocking_cron') && self::is_manual_warmup_blocking_cron()) {
                return array(
                    'processed' => 0,
                    'success' => 0,
                    'failed' => 0,
                    'yielded' => true,
                    'reason' => 'foreground-warm-priority',
                    'queue' => self::get_varnish_queue_stats(),
                );
            }

            self::recover_expired_cron_warm_queue_leases();
            $candidates = self::load_ready_varnish_queue_rows($limit);
            $settings = self::get_varnish_cli_settings();
            $current_targets = self::resolve_varnish_invalidation_targets((array) ($settings['servers'] ?? array()));
            sort($current_targets, SORT_STRING);
            $operation_plan = self::build_varnish_queue_operation_plan($candidates, $settings, $current_targets);
            $planned_operation_count = max(0, (int) ($operation_plan['operationCount'] ?? 0));
            if ($planned_operation_count < 1) {
                return array(
                    'processed' => 0,
                    'success' => 0,
                    'failed' => 0,
                    'reason' => 'no-operation-plan',
                    'queue' => self::get_varnish_queue_stats(),
                );
            }

            $rate_claim = self::claim_varnish_invalidation_rate_operations(
                min(600, $planned_operation_count),
                time(),
                'varnish_queue'
            );
            $rate_granted = max(0, (int) ($rate_claim['granted'] ?? 0));
            if ($rate_granted < 1) {
                return array(
                    'processed' => 0,
                    'success' => 0,
                    'failed' => 0,
                    'reason' => sanitize_key((string) ($rate_claim['reason'] ?? 'minute-budget-exhausted')),
                    'rateClaim' => $rate_claim,
                    'plannedOperationCount' => $planned_operation_count,
                    'queue' => self::get_varnish_queue_stats(),
                );
            }

            $dispatch = self::select_varnish_queue_operation_dispatch($operation_plan, $rate_granted);
            $selected_row_ids = array_fill_keys(array_map('intval', (array) ($dispatch['rowIds'] ?? array())), true);
            $planned_rows = (array) ($operation_plan['rows'] ?? array());
            $claimed_rows = array();
            foreach ($candidates as $candidate) {
                $candidate_id = absint($candidate['id'] ?? 0);
                if ($candidate_id < 1 || empty($selected_row_ids[$candidate_id]) || !isset($planned_rows[$candidate_id])) {
                    continue;
                }
                if (!self::is_warm_execution_fence_current($cron_execution_fence)) {
                    $execution_fence_lost = true;
                    break;
                }
                $claimed_row = self::claim_varnish_queue_row_for_operations($candidate);
                if (!empty($claimed_row)) {
                    $claimed_rows[$candidate_id] = $claimed_row;
                }
            }
            if (empty($claimed_rows)) {
                return array(
                    'processed' => 0,
                    'success' => 0,
                    'failed' => 0,
                    'reason' => $execution_fence_lost ? 'execution-fence-lost' : 'row-claim-conflict',
                    'rateClaim' => $rate_claim,
                    'plannedOperationCount' => $planned_operation_count,
                    'selectedOperationCount' => max(0, (int) ($dispatch['operationCount'] ?? 0)),
                    'queue' => self::get_varnish_queue_stats(),
                );
            }

            $row_execution = array();
            foreach ($claimed_rows as $row_id => $claimed_row) {
                $planned_row = (array) ($planned_rows[$row_id] ?? array());
                $row_execution[$row_id] = array(
                    'row' => $claimed_row,
                    'url' => (string) ($planned_row['url'] ?? $claimed_row['url'] ?? ''),
                    'pendingTargets' => array_values((array) ($planned_row['pendingTargets'] ?? array())),
                    'requiredTargets' => array_values((array) ($planned_row['requiredTargets'] ?? array())),
                    'attemptedTargets' => array(),
                    'successfulTargets' => array(),
                    'failedTargets' => array(),
                    'failedRetryability' => array(),
                    'degraded' => false,
                    'messages' => array(),
                );
            }

            foreach ((array) ($dispatch['operations'] ?? array()) as $operation) {
                if (
                    !self::is_warm_execution_fence_current($cron_execution_fence)
                    || (method_exists(static::class, 'is_manual_warmup_blocking_cron') && self::is_manual_warmup_blocking_cron())
                ) {
                    foreach ($claimed_rows as $claimed_row) {
                        self::release_varnish_queue_operation_claim(
                            $claimed_row,
                            self::maybe_translate('Queued Varnish invalidation yielded to an active foreground warm-up.'),
                            1
                        );
                    }
                    $yielded_to_foreground = method_exists(static::class, 'is_manual_warmup_blocking_cron')
                        && self::is_manual_warmup_blocking_cron();
                    $execution_fence_lost = true;
                    $claimed_rows = array();
                    break;
                }

                $target = (string) ($operation['target'] ?? '');
                $operation_row_ids = array();
                $operation_urls = array();
                foreach ((array) ($operation['rowIds'] ?? array()) as $operation_row_id) {
                    $operation_row_id = absint($operation_row_id);
                    if ($operation_row_id < 1 || !isset($row_execution[$operation_row_id])) {
                        continue;
                    }
                    $operation_url = (string) ($row_execution[$operation_row_id]['url'] ?? '');
                    if ('' === $operation_url) {
                        continue;
                    }
                    $operation_row_ids[$operation_url] = $operation_row_id;
                    $operation_urls[] = $operation_url;
                }
                $operation_urls = array_values(array_unique($operation_urls));
                if ('' === $target || empty($operation_urls)) {
                    continue;
                }

                $activity_url = esc_url_raw((string) reset($operation_urls));
                if (!self::renew_cron_warm_lock($lock_token, $lock_ttl, $cron_generation, 'varnish-invalidation-before', $activity_url)) {
                    foreach ($claimed_rows as $claimed_row) {
                        self::release_varnish_queue_operation_claim(
                            $claimed_row,
                            self::maybe_translate('Queued Varnish invalidation lost its execution fence before network work.'),
                            1
                        );
                    }
                    $claimed_rows = array();
                    $execution_fence_lost = true;
                    break;
                }

                $result = self::varnish_flush_url_batch($operation_urls, 'queued', '', array($target), $settings);
                if (!self::renew_cron_warm_lock($lock_token, $lock_ttl, $cron_generation, 'varnish-invalidation-after', $activity_url)) {
                    foreach ($claimed_rows as $claimed_row) {
                        self::release_varnish_queue_operation_claim(
                            $claimed_row,
                            self::maybe_translate('Queued Varnish invalidation was preempted before its result could be committed.'),
                            1
                        );
                    }
                    $claimed_rows = array();
                    $execution_fence_lost = true;
                    break;
                }

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

                $runtime_outcome = sanitize_key((string) ($result['runtimeOutcome'] ?? ''));
                $runtime_fallback_without_execution = 'degraded' === $runtime_outcome
                    && empty($result['runtimeExecutionAttempted']);
                foreach ($operation_row_ids as $operation_url => $operation_row_id) {
                    if (!isset($row_execution[$operation_row_id])) {
                        continue;
                    }
                    $row_execution[$operation_row_id]['attemptedTargets'][$target] = true;
                    if ($runtime_fallback_without_execution) {
                        $row_execution[$operation_row_id]['degraded'] = true;
                        $row_execution[$operation_row_id]['messages'][] = (string) ($result['message'] ?? self::maybe_translate('Immediate Varnish invalidation was skipped; the shared object will expire by TTL.'));
                        continue;
                    }

                    $url_result = self::get_varnish_queue_url_result($result, $operation_url);
                    foreach ((array) ($url_result['successfulEndpointTargets'] ?? array()) as $successful_target) {
                        $row_execution[$operation_row_id]['successfulTargets'][(string) $successful_target] = true;
                    }
                    foreach ((array) ($url_result['failedEndpointTargets'] ?? array()) as $failed_target) {
                        $failed_target = (string) $failed_target;
                        $row_execution[$operation_row_id]['failedTargets'][$failed_target] = true;
                        $row_execution[$operation_row_id]['failedRetryability'][$failed_target] = !empty($url_result['retryable']);
                    }
                    $url_message = trim((string) ($url_result['message'] ?? ''));
                    if ('' !== $url_message) {
                        $row_execution[$operation_row_id]['messages'][] = $url_message;
                    }
                }
            }

            if (!$execution_fence_lost) {
                foreach ($row_execution as $row_id => $execution) {
                    if (!isset($claimed_rows[$row_id])) {
                        continue;
                    }
                    if (!self::is_warm_execution_fence_current($cron_execution_fence)) {
                        foreach ($claimed_rows as $claimed_row) {
                            self::release_varnish_queue_operation_claim(
                                $claimed_row,
                                self::maybe_translate('Queued Varnish invalidation lost ownership immediately before its result commit.'),
                                1
                            );
                        }
                        $claimed_rows = array();
                        $execution_fence_lost = true;
                        break;
                    }

                    $attempted_targets = array_keys((array) ($execution['attemptedTargets'] ?? array()));
                    $successful_targets = array_keys((array) ($execution['successfulTargets'] ?? array()));
                    $failed_targets = array_keys((array) ($execution['failedTargets'] ?? array()));
                    sort($attempted_targets, SORT_STRING);
                    sort($successful_targets, SORT_STRING);
                    sort($failed_targets, SORT_STRING);
                    $pending_before = array_values((array) ($execution['pendingTargets'] ?? array()));
                    sort($pending_before, SORT_STRING);
                    $unattempted_targets = array_values(array_diff($pending_before, $attempted_targets));
                    $retryable = !empty($failed_targets);
                    foreach ($failed_targets as $failed_target) {
                        if (empty($execution['failedRetryability'][$failed_target])) {
                            $retryable = false;
                            break;
                        }
                    }

                    if (!empty($execution['degraded'])) {
                        $messages = array_values(array_unique(array_filter(array_map('trim', (array) ($execution['messages'] ?? array())))));
                        $message = !empty($messages)
                            ? implode(' ', $messages)
                            : self::maybe_translate('Immediate Varnish invalidation was skipped; the shared object will expire by TTL.');
                    } elseif (!empty($failed_targets) && !empty($successful_targets)) {
                        $message = self::maybe_translate_sprintf(
                            'Varnish invalidation completed on %1$d endpoint(s) and failed on %2$d endpoint(s).',
                            count($successful_targets),
                            count($failed_targets)
                        );
                    } elseif (!empty($failed_targets)) {
                        $message = self::maybe_translate_sprintf(
                            'Varnish invalidation failed on %d attempted endpoint(s).',
                            count($failed_targets)
                        );
                    } elseif (!empty($successful_targets)) {
                        $message = self::maybe_translate('Varnish invalidation completed on every attempted endpoint.');
                    } else {
                        $message = self::maybe_translate('Varnish invalidation remains queued.');
                    }

                    $url_result = array(
                        'url' => (string) ($execution['url'] ?? ''),
                        'success' => empty($failed_targets) && empty($unattempted_targets) && !empty($successful_targets),
                        'partial' => !empty($successful_targets) && (!empty($failed_targets) || !empty($unattempted_targets)),
                        'retryable' => $retryable,
                        'degraded' => !empty($execution['degraded']),
                        'successfulEndpointTargets' => $successful_targets,
                        'failedEndpointTargets' => $failed_targets,
                        'attemptedEndpointTargets' => $attempted_targets,
                        'requiredEndpointTargets' => array_values((array) ($execution['requiredTargets'] ?? array())),
                        'message' => $message,
                    );
                    $outcome = self::resolve_varnish_queue_operation_outcome(
                        (array) ($execution['row'] ?? array()),
                        $url_result,
                        $unattempted_targets
                    );
                    $commit = self::commit_varnish_queue_operation_outcome(
                        (array) ($execution['row'] ?? array()),
                        $outcome
                    );
                    if (empty($commit['success'])) {
                        continue;
                    }

                    ++$processed;
                    unset($claimed_rows[$row_id]);
                    if (!empty($outcome['degraded'])) {
                        ++$degraded;
                        continue;
                    }
                    if ('done' === (string) ($outcome['status'] ?? '')) {
                        ++$succeeded;
                        $completed_url = (string) ($execution['url'] ?? '');
                        if ('' !== $completed_url) {
                            $completed_urls[$completed_url] = true;
                        }
                        continue;
                    }
                    if ('error' === (string) ($outcome['status'] ?? '')) {
                        ++$failed;
                        continue;
                    }
                    if (!empty($outcome['budgetDeferred'])) {
                        ++$budget_deferred;
                    }
                    if (!empty($outcome['retrying'])) {
                        ++$retried;
                    }
                    if ('partial' === (string) ($outcome['resultLevel'] ?? '')) {
                        ++$partial;
                    }
                }
            }

            if (!empty($claimed_rows)) {
                foreach ($claimed_rows as $claimed_row) {
                    self::release_varnish_queue_operation_claim(
                        $claimed_row,
                        self::maybe_translate('Queued Varnish invalidation ownership ended before its bounded result could be committed.'),
                        1
                    );
                }
                $claimed_rows = array();
            }

            $pipeline_queue = !$execution_fence_lost && !empty($completed_urls)
                && self::should_refill_after_targeted_varnish_invalidation()
                && method_exists(static::class, 'enqueue_targeted_warm_pipeline_urls')
                ? self::enqueue_targeted_warm_pipeline_urls(array_keys($completed_urls), $requires_verified_origin, 'queued-invalidation')
                : array('success' => empty($completed_urls), 'queued' => false, 'queuedUrlCount' => 0);

            if ($processed > 0 && !$execution_fence_lost) {
                if ($degraded > 0 && $failed < 1 && $retried < 1 && $partial < 1 && $succeeded < 1 && $budget_deferred < 1) {
                    $message = self::maybe_translate_sprintf(
                        'Queued Varnish invalidation moved %d URL(s) to TTL-expiry fallback because immediate invalidation is no longer verified.',
                        $degraded
                    );
                } elseif ($failed < 1 && $retried < 1 && $partial < 1 && $degraded < 1 && $budget_deferred < 1) {
                    $message = self::maybe_translate_sprintf(
                        'Queued Varnish invalidation completed for %d URL(s).',
                        $succeeded
                    );
                } else {
                    $message = self::maybe_translate_sprintf(
                        'Queued Varnish invalidation completed %1$d URL(s), deferred %2$d by operation budget, retained %3$d for transport retry, used TTL fallback for %4$d, and ended %5$d with terminal errors.',
                        $succeeded,
                        $budget_deferred,
                        $retried,
                        $degraded,
                        $failed
                    );
                }

                if (!empty($pipeline_queue['message'])) {
                    $message .= ' ' . (string) $pipeline_queue['message'];
                }

                $summary = array(
                    'success' => $processed > 0 && $failed < 1 && $retried < 1 && $degraded < 1 && $budget_deferred < 1,
                    'partial' => $succeeded > 0 && ($failed > 0 || $retried > 0 || $partial > 0 || $budget_deferred > 0),
                    'degraded' => $degraded > 0,
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
                    'budgetDeferredUrlCount' => $budget_deferred,
                    'degradedUrlCount' => $degraded,
                    'retryingUrlCount' => $retried,
                    'failedUrlCount' => $failed,
                    'plannedOperationCount' => max(0, (int) ($operation_plan['operationCount'] ?? 0)),
                    'claimedOperationCount' => max(0, (int) ($rate_claim['granted'] ?? 0)),
                    'selectedOperationCount' => max(0, (int) ($dispatch['operationCount'] ?? 0)),
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
                $summary_plan = self::plan_varnish_runtime_operation('targeted');
                $summary = self::finalize_varnish_runtime_result($summary, $summary_plan, $endpoint_requests > 0);
                self::set_varnish_last_result($summary);
            }

            self::prune_completed_varnish_auxiliary_queue_rows();
        } finally {
            self::release_cron_warm_lock($lock_token);
        }

        $foreground_active = method_exists(static::class, 'is_manual_warmup_blocking_cron')
            && self::is_manual_warmup_blocking_cron();
        if (self::has_pending_varnish_invalidation_rows() && !$foreground_active) {
            self::ensure_cron_warm_events_scheduled(null, true);
        }

        return array(
            'processed' => $processed,
            'success' => $succeeded,
            'failed' => $failed,
            'partial' => $partial,
            'retrying' => $retried,
            'budgetDeferred' => $budget_deferred,
            'degraded' => $degraded,
            'yielded' => $yielded_to_foreground || $execution_fence_lost,
            'reason' => $yielded_to_foreground ? 'foreground-warm-priority' : ($execution_fence_lost ? 'execution-fence-lost' : ''),
            'plannedOperationCount' => max(0, (int) ($operation_plan['operationCount'] ?? 0)),
            'claimedOperationCount' => max(0, (int) ($rate_claim['granted'] ?? 0)),
            'selectedOperationCount' => max(0, (int) ($dispatch['operationCount'] ?? 0)),
            'requestCount' => $endpoint_requests,
            'successfulEndpointRequestCount' => $successful_endpoint_requests,
            'failedEndpointRequestCount' => $failed_endpoint_requests,
            'rateClaim' => $rate_claim,
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
                "DELETE FROM %i WHERE (job_type = %s OR (job_type = %s AND FIND_IN_SET(%s, required_stages) > %d)) AND status IN (%s, %s) AND processed_at > %d AND processed_at < %d",
                $table,
                'varnish_invalidate',
                'page_warm',
                'varnish',
                0,
                'done',
                'skipped',
                0,
                $now - $completed_retention
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retains terminal errors longer for diagnostics, then bounds table growth.
        $error_deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE (job_type = %s OR (job_type = %s AND FIND_IN_SET(%s, required_stages) > %d)) AND status = %s AND processed_at > %d AND processed_at < %d",
                $table,
                'varnish_invalidate',
                'page_warm',
                'varnish',
                0,
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
