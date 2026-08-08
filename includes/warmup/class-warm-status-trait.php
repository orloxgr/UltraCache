<?php
/**
 * Read-only consolidated warm-up status projections.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Warm_Status_Trait
{
    /**
     * Read only the legacy worker lifecycle fields retained for compatibility.
     *
     * The authoritative decision, plan, and rate records are projected later;
     * this reader deliberately avoids their normal schema-ensuring accessors.
     *
     * @return array<string,mixed>
     */
    private static function get_read_only_legacy_cron_warm_status_state()
    {
        $state = array_merge(
            self::get_default_cron_warm_state(),
            self::read_legacy_cron_warm_state_option()
        );
        return self::normalize_legacy_cron_warm_decision_state($state);
    }

    /**
     * Project the foreground section of one already-read decision snapshot.
     *
     * @param array<string,mixed> $decision Public warm decision projection.
     * @return array<string,mixed>
     */
    private static function get_manual_warm_status_from_decision(array $decision)
    {
        $foreground = isset($decision['foreground']) && is_array($decision['foreground'])
            ? $decision['foreground']
            : array();
        $status = sanitize_key((string) ($foreground['status'] ?? 'idle'));

        return array(
            'status' => $status,
            'source' => sanitize_key((string) ($foreground['source'] ?? '')),
            'active' => !empty($foreground['active']),
            'paused' => 'paused' === $status,
            'interrupted' => in_array($status, array('interrupted', 'expired'), true),
            'jobType' => sanitize_key((string) ($foreground['jobType'] ?? '')),
            'generation' => max(0, (int) ($foreground['generation'] ?? 0)),
            'startedAt' => max(0, (int) ($foreground['startedAt'] ?? 0)),
            'updatedAt' => max(0, (int) ($foreground['heartbeatAt'] ?? 0)),
            'heartbeatAt' => max(0, (int) ($foreground['heartbeatAt'] ?? 0)),
            'pausedAt' => max(0, (int) ($foreground['pausedAt'] ?? 0)),
            'leaseExpiresAt' => max(0, (int) ($foreground['leaseExpiresAt'] ?? 0)),
            'currentUrl' => esc_url_raw((string) ($foreground['currentUrl'] ?? '')),
            'currentStage' => sanitize_key((string) ($foreground['currentStage'] ?? '')),
        );
    }

    /**
     * Project authoritative full-site plan data onto the compatibility state.
     *
     * @param array<string,mixed> $state Legacy worker lifecycle state.
     * @param array<string,mixed> $plan  Read-only full-site plan status.
     * @return array<string,mixed>
     */
    private static function overlay_read_only_warm_plan_status(array $state, array $plan)
    {
        $active = !empty($plan['active']);
        if (!$active) {
            $state['workloadType'] = self::normalize_cron_warm_workload_type(
                $state['workloadType'] ?? '',
                $state['reason'] ?? ''
            );
            if ('full_site' === $state['workloadType']) {
                $state['reason'] = '';
                $state['invokedBy'] = '';
                $state['workloadType'] = '';
            }
            $state['totalLimit'] = 0;
            $state['fullSitePlanned'] = 0;
            $state['fullSiteProcessed'] = 0;
            $state['fullSiteSuccessCount'] = 0;
            $state['fullSiteSkippedCount'] = 0;
            $state['fullSiteErrorCount'] = 0;
            $state['fullSiteDiscoveryComplete'] = true;
            $state['mixedWorkloadNextClass'] = 'full_site';
            $state['warmPlan'] = $plan;
            return $state;
        }

        $state['reason'] = sanitize_key((string) ($plan['reason'] ?? ''));
        $state['invokedBy'] = sanitize_key((string) ($plan['invokedBy'] ?? ''));
        $state['workloadType'] = 'full_site';
        $state['totalLimit'] = max(0, (int) ($plan['selectionLimit'] ?? 0));
        $state['fullSitePlanned'] = max(0, (int) ($plan['selected'] ?? 0));
        $state['fullSiteProcessed'] = min(
            $state['fullSitePlanned'],
            max(0, (int) ($plan['processed'] ?? 0))
        );
        $state['fullSiteSuccessCount'] = max(0, (int) ($plan['success'] ?? 0));
        $state['fullSiteSkippedCount'] = max(0, (int) ($plan['skipped'] ?? 0));
        $state['fullSiteErrorCount'] = max(0, (int) ($plan['error'] ?? 0));
        $state['fullSiteDiscoveryComplete'] = !empty($plan['discoveryComplete']);
        $state['mixedWorkloadNextClass'] = 'targeted' === sanitize_key((string) ($plan['mixedWorkloadNextClass'] ?? ''))
            ? 'targeted'
            : 'full_site';
        $state['warmPlan'] = $plan;
        return $state;
    }

    /**
     * Project authoritative rate data onto the compatibility state.
     *
     * @param array<string,mixed> $state Worker lifecycle state.
     * @param array<string,int>   $rate  Read-only rate payload.
     * @return array<string,mixed>
     */
    private static function overlay_read_only_warm_rate_status(array $state, array $rate)
    {
        $state['pagesPerMinute'] = max(0, (int) ($rate['configuredLimit'] ?? 0));
        $state['backgroundRateWindowMinute'] = max(0, (int) ($rate['windowMinute'] ?? 0));
        $state['backgroundRateWindowClaimedAt'] = max(0, (int) ($rate['updatedAt'] ?? 0));
        $state['backgroundRateWindowLimit'] = max(0, (int) ($rate['effectiveLimit'] ?? 0));
        $state['backgroundRateWindowClaimedCount'] = max(0, (int) ($rate['claimedCount'] ?? 0));
        $state['warmRate'] = $rate;
        return $state;
    }

    /**
     * Return the consolidated, read-only warm-up status contract.
     *
     * @return array<string,mixed>
     */
    private static function get_consolidated_warm_status()
    {
        $settings = self::get_settings();
        $decision = self::get_warm_decision_status(false);
        $manual_warm = self::get_manual_warm_status_from_decision($decision);
        $plan = self::get_warm_plan_status(true, true);
        $rate = self::get_warm_rate_state(true);

        $state = self::get_read_only_legacy_cron_warm_status_state();
        $state = self::overlay_read_only_warm_plan_status($state, $plan);
        $state = self::overlay_read_only_warm_rate_status($state, $rate);

        $next = self::get_next_cron_warm_scheduled_at();
        $queue_stage_status = self::get_cron_warm_queue_stage_status(false);
        $queue_lifecycle = isset($queue_stage_status['lifecycle']) && is_array($queue_stage_status['lifecycle'])
            ? $queue_stage_status['lifecycle']
            : array();
        $queued_pending = max(0, (int) ($queue_lifecycle['planned'] ?? 0))
            + max(0, (int) ($queue_lifecycle['retrying'] ?? 0));
        $queued_processing = max(0, (int) ($queue_lifecycle['processing'] ?? 0));
        $foreground = isset($decision['foreground']) && is_array($decision['foreground'])
            ? $decision['foreground']
            : array();
        $blocked_by_manual = !empty($foreground['active']);

        $status_context = array(
            'blockedByManualWarm' => $blocked_by_manual,
            'pagesPerMinute' => max(0, (int) ($rate['configuredLimit'] ?? 0)),
            'state' => $state,
            'nextScheduledAt' => max(0, (int) $next),
            'manualWarm' => $manual_warm,
            'warmDecision' => $decision,
        );
        $varnish_queue = self::get_varnish_queue_stats($status_context);
        $targeted_worker = isset($varnish_queue['refillWorker']) && is_array($varnish_queue['refillWorker'])
            ? $varnish_queue['refillWorker']
            : array('status' => 'unavailable', 'pending' => 0, 'active' => false, 'nextScheduledAt' => 0);

        $execution_mutex = function_exists('ultracache_get_lock_read_only')
            ? ultracache_get_lock_read_only(self::get_cron_warm_lock_name())
            : array();
        $worker_health = self::get_cron_warm_worker_health_status(
            $state,
            $queue_stage_status,
            $manual_warm,
            $next,
            $decision,
            $execution_mutex,
            $settings,
            $varnish_queue
        );

        if (
            empty($worker_health['currentUrl'])
            && max(0, (int) ($queue_stage_status['processingUrls'] ?? 0)) > 0
        ) {
            $current_activity = self::get_cron_warm_queue_current_activity(false);
            if (!empty($current_activity)) {
                $worker_health['currentUrl'] = esc_url_raw((string) ($current_activity['url'] ?? ''));
                $worker_health['currentStage'] = sanitize_key((string) ($current_activity['stage'] ?? ''));
                $worker_health['currentSourceContext'] = sanitize_key((string) ($current_activity['sourceContext'] ?? ''));
            }
        }

        $full_site_plan = !empty($plan['active']);
        $remaining = $full_site_plan
            ? max(0, (int) ($plan['remaining'] ?? 0))
            : max(0, (int) ($state['total'] ?? 0) - (int) ($state['processed'] ?? 0));

        return array(
            'enabled' => max(0, (int) $settings['cron_warm_pages_per_minute']) > 0,
            'fullSiteAutomationEnabled' => !empty($settings['cron_warm_start_after_cleanup'])
                || !empty($settings['cron_warm_start_after_manual_purge']),
            'startAfterCleanup' => !empty($settings['cron_warm_start_after_cleanup']),
            'startAfterManualPurge' => !empty($settings['cron_warm_start_after_manual_purge']),
            'pagesPerMinute' => max(0, (int) $settings['cron_warm_pages_per_minute']),
            'scheduledWarmLimit' => max(0, (int) $settings['scheduled_warm_limit']),
            'totalLimit' => $full_site_plan ? max(0, (int) ($plan['selectionLimit'] ?? 0)) : 0,
            'workloadType' => (string) ($state['workloadType'] ?? ''),
            'fullSitePlanned' => max(0, (int) ($plan['selected'] ?? 0)),
            'fullSiteProcessed' => max(0, (int) ($plan['processed'] ?? 0)),
            'fullSiteRemaining' => max(0, (int) ($plan['remaining'] ?? 0)),
            'fullSiteSuccessCount' => max(0, (int) ($plan['success'] ?? 0)),
            'fullSiteSkippedCount' => max(0, (int) ($plan['skipped'] ?? 0)),
            'fullSiteErrorCount' => max(0, (int) ($plan['error'] ?? 0)),
            'fullSiteDiscoveryComplete' => !empty($plan['discoveryComplete']),
            'executionProfile' => 'cron',
            'executionProfiles' => array(
                'cron' => array(
                    'rateLimited' => true,
                    'pagesPerMinute' => max(0, (int) $settings['cron_warm_pages_per_minute']),
                    'pageTimeBudget' => 20,
                ),
                'ui' => array(
                    'rateLimited' => false,
                    'pageTimeBudget' => 60,
                ),
                'cli' => array(
                    'rateLimited' => false,
                    'pageTimeBudget' => 180,
                ),
                'visit' => array(
                    'rateLimited' => false,
                    'pageTimeBudget' => 15,
                ),
            ),
            'active' => !empty($state['active']),
            'processed' => max(0, (int) ($state['processed'] ?? 0)),
            'total' => max(0, (int) ($state['total'] ?? 0)),
            'remaining' => $remaining,
            'queuedPending' => $queued_pending,
            'queuedProcessing' => $queued_processing,
            'workerHealth' => $worker_health,
            'queueStatus' => $queue_lifecycle,
            'queueStageStatus' => $queue_stage_status,
            'targetedWorker' => $targeted_worker,
            'varnishQueue' => $varnish_queue,
            'queueStorage' => 'db',
            'successCount' => max(0, (int) ($state['successCount'] ?? 0)),
            'skippedCount' => max(0, (int) ($state['skippedCount'] ?? 0)),
            'errorCount' => max(0, (int) ($state['errorCount'] ?? 0)),
            'startedAt' => max(0, (int) ($state['startedAt'] ?? 0)),
            'updatedAt' => max(0, (int) ($state['updatedAt'] ?? 0)),
            'lastRunAt' => max(0, (int) ($state['lastRunAt'] ?? 0)),
            'finishedAt' => max(0, (int) ($state['finishedAt'] ?? 0)),
            'lastError' => (string) ($state['lastError'] ?? ''),
            'lastMessage' => (string) ($state['lastMessage'] ?? ''),
            'lastUrl' => (string) ($state['lastUrl'] ?? ''),
            'reason' => (string) ($state['reason'] ?? ''),
            'completed' => !empty($state['completed']),
            'stopped' => !empty($state['stopped']),
            'stopReason' => (string) ($state['stopReason'] ?? ''),
            'invokedBy' => (string) ($state['invokedBy'] ?? ''),
            'nextScheduledAt' => (int) $next,
            'serverCronCommand' => self::get_cron_warm_server_cron_command(),
            'warmupGeneration' => self::get_warmup_generation(),
            'blockedByManualWarm' => $blocked_by_manual,
            'manualWarm' => $manual_warm,
            'warmDecision' => $decision,
            'warmPlan' => $plan,
            'warmRate' => $rate,
            'varnishWithSiteWarmup' => method_exists(static::class, 'should_include_varnish_in_site_warmup')
                ? self::should_include_varnish_in_site_warmup()
                : false,
            'varnishWarmPlan' => method_exists(static::class, 'get_site_warm_varnish_plan')
                ? self::get_site_warm_varnish_plan()
                : array('enabled' => false, 'buckets' => array()),
            'liteSpeedWithSiteWarmup' => method_exists(static::class, 'should_include_litespeed_in_site_warmup')
                ? self::should_include_litespeed_in_site_warmup()
                : false,
            'liteSpeedWarmPlan' => method_exists(static::class, 'get_site_warm_litespeed_plan')
                ? self::get_site_warm_litespeed_plan()
                : array('enabled' => false, 'buckets' => array()),
        );
    }
}
