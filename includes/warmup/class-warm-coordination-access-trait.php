<?php
/**
 * Warm plan, rate, and generation access boundary.
 *
 * Global ownership lives in warm_decision, full-site discovery lives in
 * warm_plan, and the real-minute background allowance lives exclusively in
 * the atomic warm_rate record. The legacy cron option retains only lifecycle
 * and presentation compatibility fields.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Warm_Coordination_Access_Trait
{
    /**
     * Return the verified legacy cron-state field ownership map.
     *
     * This inventory is intentionally executable so later storage migrations
     * can assert that every persisted field has one declared responsibility.
     *
     * @return array<string,array<int,string>>
     */
    private static function get_legacy_cron_warm_field_groups()
    {
        return array(
            'legacy_plan_lifecycle' => array(
                'active',
                'startedAt',
                'updatedAt',
                'lastRunAt',
                'finishedAt',
                'completed',
                'stopped',
                'stopReason',
                'workerRecovery',
            ),
            'plan' => array(
                'reason',
                'cursor',
                'totalLimit',
                'workloadType',
                'fullSiteDiscoveryComplete',
                'mixedWorkloadNextClass',
                'currentBatch',
                'batchIndex',
                'batchHasMore',
                'nextCursorPending',
                'invokedBy',
            ),
            'rate' => array(
                'pagesPerMinute',
                'backgroundRateWindowMinute',
                'backgroundRateWindowClaimedAt',
                'backgroundRateWindowLimit',
            ),
            'derived_queue_status' => array(
                'processed',
                'total',
                'successCount',
                'skippedCount',
                'errorCount',
                'fullSitePlanned',
                'fullSiteProcessed',
                'fullSiteSuccessCount',
                'fullSiteSkippedCount',
                'fullSiteErrorCount',
            ),
            'presentation_only' => array(
                'lastError',
                'lastMessage',
                'lastUrl',
            ),
            'legacy_dead' => array(
                'warmupGeneration',
            ),
        );
    }

    /**
     * Return the verified legacy foreground-state field ownership map.
     *
     * @return array<string,array<int,string>>
     */
    private static function get_legacy_manual_warm_field_groups()
    {
        return array(
            'legacy_ownership_snapshot' => array(
                'status',
                'source',
                'active',
                'paused',
                'interrupted',
                'jobType',
                'token',
                'generation',
                'startedAt',
                'updatedAt',
                'heartbeatAt',
                'pausedAt',
                'leaseExpiresAt',
            ),
            'plan' => array(),
            'rate' => array(),
            'derived_queue_status' => array(),
            'presentation_only' => array(
                'currentUrl',
                'currentStage',
            ),
            'legacy_dead' => array(
                'ownerUserId',
            ),
        );
    }

    /**
     * Read the complete legacy cron state option.
     *
     * @return array<string,mixed>
     */
    private static function read_legacy_cron_warm_state_option()
    {
        $state = get_option(ULTRACACHE_CRON_WARM_STATE_KEY, array());
        return is_array($state) ? $state : array();
    }

    /**
     * Write the complete legacy cron state option.
     *
     * @param array<string,mixed> $state Normalized state.
     * @return void
     */
    private static function write_legacy_cron_warm_state_option(array $state)
    {
        update_option(ULTRACACHE_CRON_WARM_STATE_KEY, $state, false);
    }

    /**
     * Read the legacy cache-flush generation option.
     *
     * @return int
     */
    private static function read_legacy_warmup_generation_option()
    {
        return max(0, (int) get_option('ultracache_warmup_generation', 0));
    }

    /**
     * Write the legacy cache-flush generation option.
     *
     * @param int $generation Generation value.
     * @return void
     */
    private static function write_legacy_warmup_generation_option($generation)
    {
        update_option('ultracache_warmup_generation', max(0, (int) $generation), false);
    }

    /**
     * Default legacy cron state.
     *
     * @return array<string,mixed>
     */
    private static function get_default_cron_warm_state()
    {
        return array(
            'active'       => false,
            'reason'       => '',
            'cursor'       => '',
            'processed'    => 0,
            'total'        => 0,
            'successCount' => 0,
            'skippedCount' => 0,
            'errorCount'   => 0,
            'startedAt'    => 0,
            'updatedAt'    => 0,
            'lastRunAt'    => 0,
            'finishedAt'   => 0,
            'pagesPerMinute' => 15,
            'totalLimit'   => 0,
            'workloadType' => '',
            'fullSitePlanned' => 0,
            'fullSiteProcessed' => 0,
            'fullSiteSuccessCount' => 0,
            'fullSiteSkippedCount' => 0,
            'fullSiteErrorCount' => 0,
            'fullSiteDiscoveryComplete' => false,
            'mixedWorkloadNextClass' => 'full_site',
            'backgroundRateWindowMinute' => 0,
            'backgroundRateWindowClaimedAt' => 0,
            'backgroundRateWindowLimit' => 0,
            'currentBatch' => array(),
            'batchIndex'   => 0,
            'batchHasMore' => false,
            'nextCursorPending' => '',
            'lastError'    => '',
            'lastMessage'  => '',
            'lastUrl'      => '',
            'completed'    => false,
            'stopped'      => false,
            'stopReason'   => '',
            'invokedBy'    => '',
            'workerRecovery' => array(
                'lastRecoveredAt' => 0,
                'recoveredQueueRows' => 0,
                'recoveredExecutionLock' => false,
                'resumedQueueState' => false,
                'restoredSchedule' => false,
                'message' => '',
            ),
        );
    }

    /**
     * Read and normalize the legacy cron state through the bounded access layer.
     *
     * @return array<string,mixed>
     */
    public static function get_cron_warm_state()
    {
        $state = array_merge(
            self::get_default_cron_warm_state(),
            self::read_legacy_cron_warm_state_option()
        );
        $state = self::normalize_legacy_cron_warm_decision_state($state);
        $state = self::overlay_warm_plan_on_cron_state($state);
        return self::overlay_warm_rate_on_cron_state($state);
    }

    /**
     * Persist the legacy cron state through the bounded access layer.
     *
     * @param array<string,mixed> $state State patch or complete state.
     * @return array<string,mixed>
     */
    private static function save_cron_warm_state(array $state)
    {
        $state = array_merge(self::get_default_cron_warm_state(), $state);
        $state = self::normalize_legacy_cron_warm_decision_state($state);

        $plan = self::get_warm_plan_state();
        $legacy_workload_type = self::normalize_cron_warm_workload_type(
            $state['workloadType'] ?? '',
            $state['reason'] ?? ''
        );
        if (self::is_warm_plan_active($plan) || 'full_site' === $legacy_workload_type) {
            // Full-site provenance is authoritative only in warm_plan. Once a
            // plan completes or stops, do not leave a legacy reason that can be
            // reinterpreted as a second full-site plan on a later read.
            $state['reason'] = '';
            $state['workloadType'] = '';
            $state['invokedBy'] = '';
        }

        foreach (array(
            'cursor',
            'totalLimit',
            'fullSitePlanned',
            'fullSiteProcessed',
            'fullSiteSuccessCount',
            'fullSiteSkippedCount',
            'fullSiteErrorCount',
            'fullSiteDiscoveryComplete',
            'mixedWorkloadNextClass',
            'currentBatch',
            'batchIndex',
            'batchHasMore',
            'nextCursorPending',
            'warmPlan',
        ) as $plan_field) {
            unset($state[$plan_field]);
        }
        foreach (array(
            'pagesPerMinute',
            'backgroundRateWindowMinute',
            'backgroundRateWindowClaimedAt',
            'backgroundRateWindowLimit',
            'backgroundRateWindowClaimedCount',
            'warmRate',
        ) as $rate_field) {
            unset($state[$rate_field]);
        }

        self::write_legacy_cron_warm_state_option($state);
        $state = self::overlay_warm_plan_on_cron_state(
            array_merge(self::get_default_cron_warm_state(), $state)
        );
        return self::overlay_warm_rate_on_cron_state($state);
    }

    /**
     * Normalize fields assigned to execution decision/recovery state.
     *
     * @param array<string,mixed> $state State.
     * @return array<string,mixed>
     */
    private static function normalize_legacy_cron_warm_decision_state(array $state)
    {
        $state['workerRecovery'] = self::normalize_cron_warm_worker_recovery_state($state['workerRecovery'] ?? array());
        return $state;
    }

    /**
     * Normalize a legacy cron workload type.
     *
     * @param mixed $value  Stored workload type.
     * @param mixed $reason Stored trigger reason.
     * @return string
     */
    private static function normalize_cron_warm_workload_type($value, $reason = '')
    {
        $value = sanitize_key((string) $value);
        if (in_array($value, array('full_site', 'targeted'), true)) {
            return $value;
        }

        $reason = sanitize_key((string) $reason);
        if (in_array($reason, array('css_bundle_async', 'lcp_refresh_async', 'targeted_purge_async', 'queue_recovery'), true)) {
            return 'targeted';
        }
        return '' !== $reason ? 'full_site' : '';
    }

    /**
     * Normalize worker-recovery diagnostics retained by the legacy state.
     *
     * @param mixed $value Stored recovery value.
     * @return array<string,mixed>
     */
    private static function normalize_cron_warm_worker_recovery_state($value)
    {
        $value = is_array($value) ? $value : array();
        return array(
            'lastRecoveredAt' => max(0, (int) ($value['lastRecoveredAt'] ?? 0)),
            'recoveredQueueRows' => max(0, (int) ($value['recoveredQueueRows'] ?? 0)),
            'recoveredExecutionLock' => !empty($value['recoveredExecutionLock']),
            'resumedQueueState' => !empty($value['resumedQueueState']),
            'restoredSchedule' => !empty($value['restoredSchedule']),
            'message' => sanitize_text_field((string) ($value['message'] ?? '')),
        );
    }

    /**
     * Foreground session lease duration.
     *
     * @return int
     */
    private static function get_manual_warm_session_lease_seconds()
    {
        $seconds = (int) apply_filters('ultracache_manual_warm_session_lease_seconds', 600);
        return max(120, min(3600, $seconds));
    }

    /**
     * Normalize a foreground source.
     *
     * @param mixed $source Source value.
     * @return string
     */
    private static function normalize_foreground_warm_source($source)
    {
        $source = sanitize_key((string) $source);
        return in_array($source, array('ui', 'cli'), true) ? $source : '';
    }

    /**
     * Read the current cache-flush generation.
     *
     * @return int
     */
    public static function get_warmup_generation()
    {
        return self::read_legacy_warmup_generation_option();
    }

    /**
     * Increment the current cache-flush generation.
     *
     * @param string $reason Reserved for the atomic backend migration.
     * @return int
     */
    public static function bump_warmup_generation($reason = 'cache_flush')
    {
        $generation = self::read_legacy_warmup_generation_option() + 1;
        self::write_legacy_warmup_generation_option($generation);
        return $generation;
    }
}
