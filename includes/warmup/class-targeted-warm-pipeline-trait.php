<?php
/**
 * Targeted invalidation integration with the shared page warm pipeline.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Targeted_Warm_Pipeline_Trait
{
    /**
     * Queue targeted purge URLs into the shared per-page warm pipeline.
     *
     * @param array  $urls                     Local URLs invalidated in Varnish.
     * @param bool   $requires_verified_origin Whether strict origin proof is required.
     * @param string $reason                   Targeted source label.
     * @return array
     */
    public static function enqueue_targeted_warm_pipeline_urls(array $urls, $requires_verified_origin = false, $reason = 'targeted-purge')
    {
        $engine = self::get_engine_instance();
        if (!$engine || !method_exists($engine, 'get_warm_pipeline_eligibility')) {
            return array(
                'success' => false,
                'queued' => false,
                'queuedUrlCount' => 0,
                'message' => self::maybe_translate('The shared page warm pipeline is unavailable.'),
            );
        }

        $canonical_urls = array();
        $skipped = 0;
        $canonicalized = 0;
        foreach ($urls as $url) {
            $input_url = esc_url_raw(is_string($url) ? trim($url) : '');
            if ('' === $input_url) {
                ++$skipped;
                continue;
            }

            $canonical_url = $input_url;
            if (method_exists(static::class, 'normalize_varnish_invalidation_url')) {
                $normalized = self::normalize_varnish_invalidation_url($input_url);
                if (empty($normalized['valid']) || empty($normalized['url'])) {
                    ++$skipped;
                    continue;
                }
                $canonical_url = esc_url_raw((string) $normalized['url']);
            }
            if ('' === $canonical_url) {
                ++$skipped;
                continue;
            }
            if (!hash_equals($input_url, $canonical_url)) {
                ++$canonicalized;
            }

            $eligibility = $engine->get_warm_pipeline_eligibility($canonical_url);
            if (empty($eligibility['eligible'])) {
                ++$skipped;
                continue;
            }
            $canonical_urls[$canonical_url] = $canonical_url;
        }

        if (empty($canonical_urls) || !self::ensure_cron_warm_queue_table()) {
            return array(
                'success' => true,
                'queued' => false,
                'queuedUrlCount' => 0,
                'skippedUrlCount' => $skipped,
                'canonicalizedUrlCount' => $canonicalized,
                'message' => self::maybe_translate('No eligible HTML URLs required targeted warm-up.'),
            );
        }

        $accepted_count = count($canonical_urls);
        $source_context = sanitize_key((string) $reason);
        if ('' === $source_context) {
            $source_context = 'targeted-purge';
        }
        $pending_before = self::count_pending_targeted_page_warm_queue_rows();
        $accepted_queue_urls = array();
        $enqueue_summary = array();
        $accepted = self::insert_cron_warm_queue_urls(
            array_values($canonical_urls),
            0,
            'page_warm',
            $source_context,
            (bool) $requires_verified_origin,
            $accepted_queue_urls,
            $enqueue_summary
        );
        $pending_after = self::count_pending_targeted_page_warm_queue_rows();
        if ($accepted < 1 || empty($accepted_queue_urls)) {
            $queue_result = array(
                'success' => false,
                'queued' => false,
                'queuedUrlCount' => 0,
                'queuedUrls' => array(),
                'acceptedUrlCount' => 0,
                'insertedUrlCount' => 0,
                'coalescedUrlCount' => 0,
                'upgradedUrlCount' => 0,
                'queueFailedUrlCount' => max(0, (int) ($enqueue_summary['failed'] ?? $accepted_count)),
                'failedUrlCount' => $accepted_count,
                'skippedUrlCount' => $skipped,
                'message' => self::maybe_translate('Targeted URLs could not be added to the shared warm pipeline.'),
            );
            if (method_exists(static::class, 'record_varnish_queue_enqueue_metrics')) {
                self::record_varnish_queue_enqueue_metrics($queue_result);
            }
            return $queue_result;
        }

        $now = time();
        $manual_blocked = self::is_manual_warmup_blocking_cron();
        $state = self::get_cron_warm_state();
        if (!$manual_blocked) {
            if (empty($state['active'])) {
                $pages_per_tick = max(1, min(100, (int) apply_filters('ultracache_targeted_warm_pages_per_tick', 5)));
                $state = self::save_cron_warm_state(array(
                    'active' => true,
                    'reason' => 'targeted_purge_async',
                    'cursor' => '',
                    'processed' => 0,
                    'total' => $pending_after,
                    'successCount' => 0,
                    'skippedCount' => 0,
                    'errorCount' => 0,
                    'startedAt' => $now,
                    'updatedAt' => $now,
                    'lastRunAt' => 0,
                    'finishedAt' => 0,
                    'pagesPerMinute' => $pages_per_tick,
                    'totalLimit' => 0,
                    'currentBatch' => array(),
                    'batchIndex' => 0,
                    'batchHasMore' => false,
                    'nextCursorPending' => '',
                    'lastError' => '',
                    'lastMessage' => self::maybe_translate('Targeted purge URLs queued in the shared warm pipeline.'),
                    'lastUrl' => '',
                    'completed' => false,
                    'stopped' => false,
                    'stopReason' => '',
                    'invokedBy' => sanitize_key((string) $reason),
                    'warmupGeneration' => self::get_warmup_generation(),
                ));
            } else {
                $new_rows = max(0, $pending_after - $pending_before);
                $state['active'] = true;
                $state['completed'] = false;
                $state['stopped'] = false;
                $state['updatedAt'] = $now;
                $state['total'] = max((int) ($state['total'] ?? 0), (int) ($state['processed'] ?? 0) + $pending_after);
                if (!empty($state['totalLimit'])) {
                    $state['totalLimit'] = max((int) $state['totalLimit'], (int) ($state['processed'] ?? 0) + $new_rows);
                }
                $state['lastMessage'] = self::maybe_translate('Targeted purge URLs joined the active shared warm pipeline.');
                $state['invokedBy'] = sanitize_key((string) $reason);
                self::save_cron_warm_state($state);
            }
            self::ensure_cron_warm_events_scheduled(1);
        }

        $queue_failed = max(0, $accepted_count - count($accepted_queue_urls));
        $queue_result = array(
            'success' => $queue_failed < 1,
            'partial' => $queue_failed > 0,
            'warning' => $queue_failed > 0,
            'queued' => true,
            'deferredByManualWarm' => $manual_blocked,
            'queuedUrlCount' => count($accepted_queue_urls),
            'acceptedUrlCount' => count($accepted_queue_urls),
            'insertedUrlCount' => max(0, (int) ($enqueue_summary['inserted'] ?? 0)),
            'coalescedUrlCount' => max(0, (int) ($enqueue_summary['coalesced'] ?? 0)),
            'upgradedUrlCount' => max(0, (int) ($enqueue_summary['upgraded'] ?? 0)),
            'queueFailedUrlCount' => $queue_failed,
            'queuedUrls' => array_values($accepted_queue_urls),
            'failedUrlCount' => $queue_failed,
            'pendingUrlCount' => $pending_after,
            'skippedUrlCount' => $skipped,
            'canonicalizedUrlCount' => $canonicalized,
            'strictOriginRequired' => (bool) $requires_verified_origin,
            'pipeline' => 'shared-page-warm',
            'message' => $queue_failed > 0
                ? self::maybe_translate_sprintf('%1$d targeted URL(s) were accepted: %2$d inserted, %3$d coalesced, %4$d upgraded, and %5$d could not be persisted.', count($accepted_queue_urls), max(0, (int) ($enqueue_summary['inserted'] ?? 0)), max(0, (int) ($enqueue_summary['coalesced'] ?? 0)), max(0, (int) ($enqueue_summary['upgraded'] ?? 0)), $queue_failed)
                : ($manual_blocked
                    ? self::maybe_translate_sprintf('%1$d targeted URL(s) were accepted: %2$d inserted, %3$d coalesced, and %4$d upgraded. Processing will resume after the manual warm-up.', count($accepted_queue_urls), max(0, (int) ($enqueue_summary['inserted'] ?? 0)), max(0, (int) ($enqueue_summary['coalesced'] ?? 0)), max(0, (int) ($enqueue_summary['upgraded'] ?? 0)))
                    : self::maybe_translate_sprintf('%1$d targeted URL(s) were accepted: %2$d inserted, %3$d coalesced, and %4$d upgraded.', count($accepted_queue_urls), max(0, (int) ($enqueue_summary['inserted'] ?? 0)), max(0, (int) ($enqueue_summary['coalesced'] ?? 0)), max(0, (int) ($enqueue_summary['upgraded'] ?? 0)))),
        );
        if (method_exists(static::class, 'record_varnish_queue_enqueue_metrics')) {
            self::record_varnish_queue_enqueue_metrics($queue_result);
        }
        return $queue_result;
    }

    /**
     * Count pending targeted page-warm rows.
     *
     * @return int
     */
    private static function count_pending_targeted_page_warm_queue_rows($ensure_schema = true)
    {
        global $wpdb;
        $queue_ready = $ensure_schema ? self::ensure_cron_warm_queue_table() : self::cron_warm_queue_table_read_ready();
        if (!($wpdb instanceof wpdb) || !$queue_ready) {
            return 0;
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Counts UltraCache-owned targeted page-warm rows.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE status = %s AND job_type = %s AND source_context <> %s',
                $table,
                'pending',
                'page_warm',
                ''
            )
        );
    }

    /**
     * Read the targeted page-warm worker state without scheduling or recovering
     * anything. Dashboard status uses this method exclusively.
     *
     * @param int|null $pending   Optional already-read pending row count.
     * @param bool     $recovered Whether the current lifecycle call recovered it.
     * @return array<string,mixed>
     */
    private static function get_targeted_page_warm_worker_status($pending = null, $recovered = false)
    {
        $pending = null === $pending
            ? self::count_pending_targeted_page_warm_queue_rows(false)
            : max(0, (int) $pending);
        $blocked = self::is_manual_warmup_blocking_cron(false);
        $state = self::get_cron_warm_state();
        $next_scheduled = self::get_next_cron_warm_scheduled_at();

        if ($pending < 1) {
            $status = 'idle';
            $message = self::maybe_translate('No targeted page-warm rows are waiting.');
        } elseif ($blocked) {
            $status = 'blocked-manual';
            $message = self::maybe_translate('Targeted page-warm rows are waiting for the manual warm-up owner to finish.');
        } elseif ($recovered) {
            $status = 'recovered';
            $message = self::maybe_translate('The orphaned targeted page-warm queue was reattached to the shared worker.');
        } elseif (!empty($state['active']) && $next_scheduled > 0) {
            $status = 'scheduled';
            $message = self::maybe_translate('The targeted page-warm queue has an active scheduled worker.');
        } else {
            $status = 'unscheduled';
            $message = self::maybe_translate('Targeted page-warm rows are pending but no executable worker is scheduled.');
        }

        return array(
            'status' => $status,
            'pending' => $pending,
            'active' => !empty($state['active']),
            'blockedByManualWarm' => $blocked,
            'recovered' => (bool) $recovered,
            'nextScheduledAt' => max(0, (int) $next_scheduled),
            'message' => $message,
        );
    }

    /**
     * Keep persistent targeted page-warm rows attached to an executable worker.
     *
     * This mutating recovery path is called only from queue/lifecycle work, not
     * from status generation.
     *
     * @return array<string,mixed>
     */
    private static function ensure_targeted_page_warm_worker_ready()
    {
        $pending = self::count_pending_targeted_page_warm_queue_rows();
        $blocked = self::is_manual_warmup_blocking_cron();
        $resumed = false;

        if ($pending > 0 && !$blocked) {
            $state = self::get_cron_warm_state();
            if (empty($state['active'])) {
                $resumed = self::resume_deferred_targeted_page_warm_queue();
            } else {
                self::ensure_cron_warm_events_scheduled(1);
            }
        }

        return self::get_targeted_page_warm_worker_status($pending, $resumed);
    }

    /**
     * Resume targeted page-warm rows after manual ownership is released.
     *
     * @return bool
     */
    private static function resume_deferred_targeted_page_warm_queue()
    {
        if (self::is_manual_warmup_blocking_cron()) {
            return false;
        }

        $pending = self::count_pending_targeted_page_warm_queue_rows();
        if ($pending < 1) {
            return false;
        }

        $state = self::get_cron_warm_state();
        if (empty($state['active'])) {
            $now = time();
            $pages_per_tick = max(1, min(100, (int) apply_filters('ultracache_targeted_warm_pages_per_tick', 5)));
            self::save_cron_warm_state(array(
                'active' => true,
                'reason' => 'targeted_purge_async',
                'cursor' => '',
                'processed' => 0,
                'total' => $pending,
                'successCount' => 0,
                'skippedCount' => 0,
                'errorCount' => 0,
                'startedAt' => $now,
                'updatedAt' => $now,
                'lastRunAt' => 0,
                'finishedAt' => 0,
                'pagesPerMinute' => $pages_per_tick,
                'totalLimit' => 0,
                'currentBatch' => array(),
                'batchIndex' => 0,
                'batchHasMore' => false,
                'nextCursorPending' => '',
                'lastError' => '',
                'lastMessage' => self::maybe_translate('Deferred targeted purge warm queue resumed.'),
                'lastUrl' => '',
                'completed' => false,
                'stopped' => false,
                'stopReason' => '',
                'invokedBy' => 'targeted-purge-resume',
                'warmupGeneration' => self::get_warmup_generation(),
            ));
        }
        self::ensure_cron_warm_events_scheduled(1);
        return true;
    }

}
