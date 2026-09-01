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
     * Resolve external-cache stages that must remain pending after HTML is
     * satisfied. The returned list is merged into the one canonical URL row.
     *
     * @param string $source_context Targeted source label.
     * @return array
     */
    private static function get_targeted_warm_additional_required_stages($source_context)
    {
        $source_context = sanitize_key((string) $source_context);
        if ('first-visit' === $source_context) {
            return method_exists(static::class, 'get_cron_warm_site_required_stages')
                ? self::get_cron_warm_site_required_stages()
                : array();
        }
        if ('refresh-ahead' === $source_context) {
            return array('varnish');
        }
        if ('litespeed-queued-invalidation' === $source_context) {
            return array('litespeed');
        }

        $stages = array();
        if (
            method_exists(static::class, 'should_refill_after_targeted_varnish_invalidation')
            && self::should_refill_after_targeted_varnish_invalidation()
        ) {
            $stages[] = 'varnish';
        }
        if (
            method_exists(static::class, 'should_refill_after_targeted_litespeed_invalidation')
            && self::should_refill_after_targeted_litespeed_invalidation()
        ) {
            $stages[] = 'litespeed';
        }

        return $stages;
    }

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
        $additional_required_stages = self::get_targeted_warm_additional_required_stages($source_context);

        $accepted_queue_urls = array();
        $enqueue_summary = array();
        $accepted = self::insert_cron_warm_queue_urls(
            array_values($canonical_urls),
            0,
            'page_warm',
            $source_context,
            (bool) $requires_verified_origin,
            $accepted_queue_urls,
            $enqueue_summary,
            $additional_required_stages
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
        $pages_per_tick = self::get_shared_automation_pages_per_minute();
        $paused_by_work_limit = $pages_per_tick < 1;
        $state = self::get_cron_warm_state();
        if (!$manual_blocked && !$paused_by_work_limit) {
            if (empty($state['active'])) {
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
                $full_site_plan = 'full_site' === (string) ($state['workloadType'] ?? '');
                $state['active'] = true;
                $state['completed'] = false;
                $state['stopped'] = false;
                $state['pagesPerMinute'] = $pages_per_tick;
                $state['updatedAt'] = $now;
                if (!$full_site_plan) {
                    $state['total'] = max((int) ($state['total'] ?? 0), (int) ($state['processed'] ?? 0) + $pending_after);
                }
                // Targeted URLs share execution with an active full-site plan, but
                // they must never expand or replace its discovery-only limit or
                // provenance. Their own source contexts live on canonical rows.
                if (!$full_site_plan) {
                    $state['invokedBy'] = sanitize_key((string) $reason);
                }
                $state['lastMessage'] = self::maybe_translate('Targeted purge URLs joined the active shared warm pipeline.');
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
            'pausedByWorkLimit' => $paused_by_work_limit,
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
                    : ($paused_by_work_limit
                        ? self::maybe_translate_sprintf('%1$d targeted URL(s) were accepted: %2$d inserted, %3$d coalesced, and %4$d upgraded. Processing is paused by the central Automation & Scheduling work limit.', count($accepted_queue_urls), max(0, (int) ($enqueue_summary['inserted'] ?? 0)), max(0, (int) ($enqueue_summary['coalesced'] ?? 0)), max(0, (int) ($enqueue_summary['upgraded'] ?? 0)))
                        : self::maybe_translate_sprintf('%1$d targeted URL(s) were accepted: %2$d inserted, %3$d coalesced, and %4$d upgraded.', count($accepted_queue_urls), max(0, (int) ($enqueue_summary['inserted'] ?? 0)), max(0, (int) ($enqueue_summary['coalesced'] ?? 0)), max(0, (int) ($enqueue_summary['upgraded'] ?? 0))))),
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
     * @param int|null            $pending        Optional already-read pending row count.
     * @param bool                $recovered      Whether the current lifecycle call recovered it.
     * @param array<string,mixed> $status_context Optional read-only status inputs.
     * @return array<string,mixed>
     */
    private static function get_targeted_page_warm_worker_status($pending = null, $recovered = false, array $status_context = array())
    {
        $pending = null === $pending
            ? self::count_pending_targeted_page_warm_queue_rows(false)
            : max(0, (int) $pending);
        $decision = isset($status_context['warmDecision']) && is_array($status_context['warmDecision'])
            ? $status_context['warmDecision']
            : self::get_warm_decision_status(false);
        $foreground = isset($decision['foreground']) && is_array($decision['foreground'])
            ? $decision['foreground']
            : array();
        $blocked = array_key_exists('blockedByManualWarm', $status_context)
            ? !empty($status_context['blockedByManualWarm'])
            : !empty($foreground['active']);
        $pages_per_minute = array_key_exists('pagesPerMinute', $status_context)
            ? max(0, (int) $status_context['pagesPerMinute'])
            : max(0, (int) (self::get_warm_rate_state(true)['configuredLimit'] ?? 0));
        $state = isset($status_context['state']) && is_array($status_context['state'])
            ? $status_context['state']
            : (method_exists(static::class, 'get_read_only_legacy_cron_warm_status_state')
                ? self::get_read_only_legacy_cron_warm_status_state()
                : self::get_cron_warm_state());
        $next_scheduled = array_key_exists('nextScheduledAt', $status_context)
            ? max(0, (int) $status_context['nextScheduledAt'])
            : self::get_next_cron_warm_scheduled_at();

        if ($pending < 1) {
            $status = 'idle';
            $message = self::maybe_translate('No targeted page-warm rows are waiting.');
        } elseif ($blocked) {
            $foreground = isset($status_context['manualWarm']) && is_array($status_context['manualWarm'])
                ? $status_context['manualWarm']
                : self::get_manual_warm_status();
            $status = 'cli' === (string) ($foreground['source'] ?? '') ? 'yielding-cli' : 'yielding-ui';
            $message = 'cli' === (string) ($foreground['source'] ?? '')
                ? self::maybe_translate('Targeted page-warm rows are yielding to the active WP-CLI warm-up.')
                : self::maybe_translate('Targeted page-warm rows are yielding to the active dashboard warm-up.');
        } elseif ($pages_per_minute < 1) {
            $status = 'paused';
            $message = self::maybe_translate('Targeted page-warm rows are paused by the central Automation & Scheduling work limit.');
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
            'pausedByWorkLimit' => $pages_per_minute < 1,
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
        $pages_per_minute = self::get_shared_automation_pages_per_minute();
        $resumed = false;

        if ($pending > 0 && !$blocked && $pages_per_minute > 0) {
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
        $pages_per_tick = self::get_shared_automation_pages_per_minute();
        if ($pending < 1 || $pages_per_tick < 1) {
            return false;
        }

        $state = self::get_cron_warm_state();
        if (empty($state['active'])) {
            $now = time();
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
