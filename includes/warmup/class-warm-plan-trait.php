<?php
/**
 * Atomic full-site warm discovery plan.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Warm_Plan_Trait
{
    /**
     * Return the authoritative full-site plan state name.
     *
     * @return string
     */
    private static function get_warm_plan_state_name()
    {
        return 'ultracache_state:warm_plan';
    }

    /**
     * Return the default full-site plan payload.
     *
     * Queue progress and outcomes intentionally do not live here. They are
     * derived from canonical URL membership rows.
     *
     * @return array<string,mixed>
     */
    private static function get_default_warm_plan_state()
    {
        return array(
            'planId' => '',
            'planGeneration' => 0,
            'status' => 'idle',
            'reason' => '',
            'invokedBy' => '',
            'cursor' => '',
            'nextCursorPending' => '',
            'currentBatch' => array(),
            'batchIndex' => 0,
            'batchHasMore' => false,
            'discoveryComplete' => true,
            'selectionLimit' => 0,
            'mixedWorkloadNextClass' => 'full_site',
            'startedAt' => 0,
            'updatedAt' => 0,
            'completedAt' => 0,
            'lastMessage' => '',
        );
    }

    /**
     * Normalize one cursor value without interpreting its opaque contents.
     *
     * @param mixed $cursor Cursor value.
     * @return string
     */
    private static function normalize_warm_plan_cursor($cursor)
    {
        $cursor = trim((string) $cursor);
        if (strlen($cursor) > 8192) {
            return substr($cursor, 0, 8192);
        }
        return $cursor;
    }

    /**
     * Normalize one complete full-site plan payload.
     *
     * @param array<string,mixed> $state Plan payload.
     * @return array<string,mixed>
     */
    private static function normalize_warm_plan_state(array $state)
    {
        $state = array_merge(self::get_default_warm_plan_state(), $state);
        $status = sanitize_key((string) $state['status']);
        if (!in_array($status, array('idle', 'active', 'completed', 'stopped'), true)) {
            $status = 'idle';
        }

        $plan_id = sanitize_text_field((string) $state['planId']);
        if ('' === $plan_id) {
            $status = 'idle';
        }

        $current_batch = array();
        foreach (array_slice((array) $state['currentBatch'], 0, 600) as $url) {
            $url = esc_url_raw((string) $url);
            if ('' !== $url) {
                $current_batch[$url] = $url;
            }
        }

        return array(
            'planId' => $plan_id,
            'planGeneration' => max(0, (int) $state['planGeneration']),
            'status' => $status,
            'reason' => sanitize_key((string) $state['reason']),
            'invokedBy' => sanitize_key((string) $state['invokedBy']),
            'cursor' => self::normalize_warm_plan_cursor($state['cursor']),
            'nextCursorPending' => self::normalize_warm_plan_cursor($state['nextCursorPending']),
            'currentBatch' => array_values($current_batch),
            'batchIndex' => max(0, (int) $state['batchIndex']),
            'batchHasMore' => !empty($state['batchHasMore']),
            'discoveryComplete' => !empty($state['discoveryComplete']),
            'selectionLimit' => max(0, min(1000000, (int) $state['selectionLimit'])),
            'mixedWorkloadNextClass' => 'targeted' === sanitize_key((string) $state['mixedWorkloadNextClass'])
                ? 'targeted'
                : 'full_site',
            'startedAt' => max(0, (int) $state['startedAt']),
            'updatedAt' => max(0, (int) $state['updatedAt']),
            'completedAt' => max(0, (int) $state['completedAt']),
            'lastMessage' => sanitize_text_field((string) $state['lastMessage']),
        );
    }

    /**
     * Read the complete plan record, including its CAS revision.
     *
     * @param bool $read_only Whether storage must be read without schema ensure.
     * @return array{name:string,payload:array<string,mixed>,revision:int,updatedAt:int}
     */
    private static function get_warm_plan_record($read_only = false)
    {
        if (!function_exists('ultracache_get_state_record')) {
            return array();
        }

        $record = ($read_only && function_exists('ultracache_get_state_record_read_only'))
            ? ultracache_get_state_record_read_only(self::get_warm_plan_state_name())
            : ultracache_get_state_record(self::get_warm_plan_state_name());
        if (empty($record)) {
            return array();
        }
        $record['payload'] = self::normalize_warm_plan_state((array) ($record['payload'] ?? array()));
        $record['revision'] = max(0, (int) ($record['revision'] ?? 0));
        $record['updatedAt'] = max(0, (int) ($record['updatedAt'] ?? 0));
        return $record;
    }

    /**
     * Read the normalized plan payload.
     *
     * @return array<string,mixed>
     */
    private static function get_warm_plan_state()
    {
        $record = self::get_warm_plan_record();
        return empty($record)
            ? self::get_default_warm_plan_state()
            : (array) $record['payload'];
    }

    /**
     * Return whether a plan payload is the active full-site plan.
     *
     * @param array<string,mixed> $plan Plan payload.
     * @return bool
     */
    private static function is_warm_plan_active(array $plan)
    {
        $plan = self::normalize_warm_plan_state($plan);
        return 'active' === $plan['status']
            && '' !== $plan['planId']
            && $plan['planGeneration'] > 0;
    }

    /**
     * Apply one bounded atomic mutation to the full-site plan.
     *
     * @param callable $mutator Plan mutator.
     * @return array<string,mixed>
     */
    private static function mutate_warm_plan_state(callable $mutator)
    {
        if (!function_exists('ultracache_mutate_state_record')) {
            return array(
                'success' => false,
                'reason' => 'storage_unavailable',
                'state' => array(),
            );
        }

        return ultracache_mutate_state_record(
            self::get_warm_plan_state_name(),
            static function ($payload, $record, $attempt) use ($mutator) {
                return call_user_func(
                    $mutator,
                    self::normalize_warm_plan_state((array) $payload),
                    $record,
                    $attempt
                );
            },
            5,
            self::get_default_warm_plan_state()
        );
    }

    /**
     * Create a new full-site plan unless one is already active.
     *
     * @param string $reason          Trigger reason.
     * @param string $invoked_by      Invocation source.
     * @param int    $selection_limit Scheduled selection limit.
     * @return array<string,mixed>
     */
    private static function start_warm_plan($reason, $invoked_by, $selection_limit)
    {
        $reason = sanitize_key((string) $reason);
        $invoked_by = sanitize_key((string) $invoked_by);
        $selection_limit = max(0, min(1000000, (int) $selection_limit));
        $started = false;
        $already_active = false;
        $now = time();

        $mutation = self::mutate_warm_plan_state(
            static function ($plan) use ($reason, $invoked_by, $selection_limit, $now, &$started, &$already_active) {
                if (self::is_warm_plan_active($plan)) {
                    $already_active = true;
                    return $plan;
                }

                $generation = max(0, (int) $plan['planGeneration']) + 1;
                $plan_id = 'warm-plan-' . $generation . '-' . gmdate('YmdHis') . '-' . wp_generate_password(10, false, false);
                $started = true;
                return self::normalize_warm_plan_state(array(
                    'planId' => $plan_id,
                    'planGeneration' => $generation,
                    'status' => 'active',
                    'reason' => $reason,
                    'invokedBy' => $invoked_by,
                    'cursor' => '',
                    'nextCursorPending' => '',
                    'currentBatch' => array(),
                    'batchIndex' => 0,
                    'batchHasMore' => false,
                    'discoveryComplete' => false,
                    'selectionLimit' => $selection_limit,
                    'mixedWorkloadNextClass' => 'full_site',
                    'startedAt' => $now,
                    'updatedAt' => $now,
                    'completedAt' => 0,
                    'lastMessage' => self::maybe_translate('Cron warm up queued.'),
                ));
            }
        );

        $record = isset($mutation['state']) && is_array($mutation['state']) ? $mutation['state'] : array();
        $payload = isset($record['payload']) && is_array($record['payload'])
            ? self::normalize_warm_plan_state($record['payload'])
            : self::get_warm_plan_state();

        return array(
            'success' => !empty($mutation['success']),
            'started' => $started && !empty($mutation['success']),
            'alreadyActive' => $already_active && !empty($mutation['success']),
            'reason' => (string) ($mutation['reason'] ?? ''),
            'revision' => max(0, (int) ($record['revision'] ?? 0)),
            'plan' => $payload,
        );
    }

    /**
     * Commit one discovered source batch against the exact plan revision read
     * before discovery began. A stale writer cannot replace a newer cursor.
     *
     * @param array<string,mixed> $expected_record Exact record read before discovery.
     * @param array<string,mixed> $batch           Discovery batch.
     * @param array<int,string>   $accepted_urls   Canonical URLs accepted into the queue.
     * @param bool                $discovery_complete Whether discovery is complete.
     * @param string              $invoked_by      Invocation source.
     * @param string              $message         Plan message.
     * @return array<string,mixed>
     */
    private static function commit_warm_plan_discovery_batch(array $expected_record, array $batch, array $accepted_urls, $discovery_complete, $invoked_by, $message)
    {
        if (!function_exists('ultracache_compare_and_swap_state_record')) {
            return array('success' => false, 'reason' => 'storage_unavailable', 'state' => array());
        }

        $expected_plan = self::normalize_warm_plan_state((array) ($expected_record['payload'] ?? array()));
        $expected_revision = max(0, (int) ($expected_record['revision'] ?? 0));
        if (!self::is_warm_plan_active($expected_plan) || $expected_revision < 1) {
            return array('success' => false, 'reason' => 'plan_not_active', 'state' => $expected_record);
        }

        $replacement = $expected_plan;
        $replacement['currentBatch'] = array_values($accepted_urls);
        $replacement['batchIndex'] = max(0, (int) $expected_plan['batchIndex']) + 1;
        $replacement['batchHasMore'] = !empty($batch['hasMore']);
        $replacement['nextCursorPending'] = !empty($batch['nextCursor'])
            ? self::normalize_warm_plan_cursor($batch['nextCursor'])
            : '';
        $replacement['discoveryComplete'] = (bool) $discovery_complete;
        $replacement['invokedBy'] = '' !== sanitize_key((string) $invoked_by)
            ? sanitize_key((string) $invoked_by)
            : (string) $replacement['invokedBy'];
        $replacement['updatedAt'] = time();
        $replacement['lastMessage'] = sanitize_text_field((string) $message);
        $replacement = self::normalize_warm_plan_state($replacement);

        return ultracache_compare_and_swap_state_record(
            self::get_warm_plan_state_name(),
            $expected_revision,
            $replacement
        );
    }

    /**
     * Advance to the previously committed next cursor through exact CAS.
     *
     * @param array<string,mixed> $expected_record Exact current plan record.
     * @return array<string,mixed>
     */
    private static function advance_warm_plan_cursor(array $expected_record)
    {
        if (!function_exists('ultracache_compare_and_swap_state_record')) {
            return array('success' => false, 'reason' => 'storage_unavailable', 'state' => array());
        }

        $plan = self::normalize_warm_plan_state((array) ($expected_record['payload'] ?? array()));
        $revision = max(0, (int) ($expected_record['revision'] ?? 0));
        if (
            !self::is_warm_plan_active($plan)
            || $revision < 1
            || empty($plan['batchHasMore'])
            || '' === $plan['nextCursorPending']
        ) {
            return array('success' => false, 'reason' => 'cursor_not_ready', 'state' => $expected_record);
        }

        $plan['cursor'] = (string) $plan['nextCursorPending'];
        $plan['nextCursorPending'] = '';
        $plan['currentBatch'] = array();
        $plan['batchHasMore'] = false;
        $plan['updatedAt'] = time();
        $plan['lastMessage'] = self::maybe_translate('Advanced full-site discovery to the next source batch.');

        return ultracache_compare_and_swap_state_record(
            self::get_warm_plan_state_name(),
            $revision,
            self::normalize_warm_plan_state($plan)
        );
    }

    /**
     * Mark discovery complete for the exact active plan.
     *
     * @param string $plan_id    Plan ID.
     * @param int    $generation Plan generation.
     * @param string $message    Plan message.
     * @return bool
     */
    private static function mark_warm_plan_discovery_complete($plan_id, $generation, $message = '')
    {
        $plan_id = sanitize_text_field((string) $plan_id);
        $generation = max(0, (int) $generation);
        $committed = false;
        $mutation = self::mutate_warm_plan_state(
            static function ($plan) use ($plan_id, $generation, $message, &$committed) {
                if (
                    !self::is_warm_plan_active($plan)
                    || !hash_equals((string) $plan['planId'], $plan_id)
                    || (int) $plan['planGeneration'] !== $generation
                ) {
                    return null;
                }
                $plan['discoveryComplete'] = true;
                $plan['batchHasMore'] = false;
                $plan['nextCursorPending'] = '';
                $plan['currentBatch'] = array();
                $plan['updatedAt'] = time();
                if ('' !== (string) $message) {
                    $plan['lastMessage'] = sanitize_text_field((string) $message);
                }
                $committed = true;
                return self::normalize_warm_plan_state($plan);
            }
        );
        return $committed && !empty($mutation['success']);
    }

    /**
     * Update the single-slot mixed-workload fairness pointer.
     *
     * @param string $plan_id    Plan ID.
     * @param int    $generation Plan generation.
     * @param string $next_class Next preferred class.
     * @return bool
     */
    private static function update_warm_plan_fairness_pointer($plan_id, $generation, $next_class)
    {
        $plan_id = sanitize_text_field((string) $plan_id);
        $generation = max(0, (int) $generation);
        $next_class = 'targeted' === sanitize_key((string) $next_class) ? 'targeted' : 'full_site';
        $committed = false;
        $mutation = self::mutate_warm_plan_state(
            static function ($plan) use ($plan_id, $generation, $next_class, &$committed) {
                if (
                    !self::is_warm_plan_active($plan)
                    || !hash_equals((string) $plan['planId'], $plan_id)
                    || (int) $plan['planGeneration'] !== $generation
                ) {
                    return null;
                }
                $plan['mixedWorkloadNextClass'] = $next_class;
                $plan['updatedAt'] = time();
                $committed = true;
                return self::normalize_warm_plan_state($plan);
            }
        );
        return $committed && !empty($mutation['success']);
    }

    /**
     * Complete the exact active full-site plan.
     *
     * @param string $plan_id    Plan ID.
     * @param int    $generation Plan generation.
     * @param string $message    Completion message.
     * @return bool
     */
    private static function complete_warm_plan($plan_id, $generation, $message = '')
    {
        $plan_id = sanitize_text_field((string) $plan_id);
        $generation = max(0, (int) $generation);
        $committed = false;
        $mutation = self::mutate_warm_plan_state(
            static function ($plan) use ($plan_id, $generation, $message, &$committed) {
                if (
                    !self::is_warm_plan_active($plan)
                    || !hash_equals((string) $plan['planId'], $plan_id)
                    || (int) $plan['planGeneration'] !== $generation
                ) {
                    return null;
                }
                $now = time();
                $plan['status'] = 'completed';
                $plan['discoveryComplete'] = true;
                $plan['batchHasMore'] = false;
                $plan['nextCursorPending'] = '';
                $plan['currentBatch'] = array();
                $plan['updatedAt'] = $now;
                $plan['completedAt'] = $now;
                $plan['lastMessage'] = '' !== (string) $message
                    ? sanitize_text_field((string) $message)
                    : self::maybe_translate('Full-site background warm-up complete.');
                $committed = true;
                return self::normalize_warm_plan_state($plan);
            }
        );
        return $committed && !empty($mutation['success']);
    }

    /**
     * Stop the active plan without deleting its audit identity.
     *
     * @param string $reason Stop reason.
     * @return bool
     */
    private static function stop_warm_plan($reason)
    {
        $reason = sanitize_key((string) $reason);
        $committed = false;
        $mutation = self::mutate_warm_plan_state(
            static function ($plan) use ($reason, &$committed) {
                if (!self::is_warm_plan_active($plan)) {
                    return null;
                }
                $now = time();
                $plan['status'] = 'stopped';
                $plan['updatedAt'] = $now;
                $plan['completedAt'] = $now;
                $plan['lastMessage'] = '' !== $reason
                    ? sanitize_text_field('Full-site warm plan stopped: ' . $reason)
                    : self::maybe_translate('Full-site warm plan stopped.');
                $committed = true;
                return self::normalize_warm_plan_state($plan);
            }
        );
        return $committed && !empty($mutation['success']);
    }

    /**
     * Delete the authoritative plan state during an explicit queue reset.
     *
     * @return bool
     */
    private static function reset_warm_plan_state()
    {
        return function_exists('ultracache_delete_state_record')
            ? ultracache_delete_state_record(self::get_warm_plan_state_name())
            : false;
    }

    /**
     * Return a status projection with queue-derived progress counters.
     *
     * @param bool $include_counts Whether canonical membership counts are needed.
     * @param bool $read_only      Whether storage must be read without schema ensure.
     * @return array<string,mixed>
     */
    private static function get_warm_plan_status($include_counts = true, $read_only = false)
    {
        $record = self::get_warm_plan_record($read_only);
        $plan = empty($record)
            ? self::get_default_warm_plan_state()
            : (array) $record['payload'];
        $active = self::is_warm_plan_active($plan);
        $counts = array(
            'ready' => false,
            'selected' => 0,
            'processed' => 0,
            'success' => 0,
            'skipped' => 0,
            'error' => 0,
        );
        if ($include_counts && $active && method_exists(static::class, 'get_cron_warm_full_site_membership_counts')) {
            $counts = self::get_cron_warm_full_site_membership_counts(false);
        }

        $selected = max(0, (int) ($counts['selected'] ?? 0));
        $processed = min($selected, max(0, (int) ($counts['processed'] ?? 0)));
        return array_merge($plan, array(
            'exists' => '' !== (string) $plan['planId'],
            'active' => $active,
            'revision' => max(0, (int) ($record['revision'] ?? 0)),
            'selected' => $selected,
            'processed' => $processed,
            'remaining' => max(0, $selected - $processed),
            'success' => max(0, (int) ($counts['success'] ?? 0)),
            'skipped' => max(0, (int) ($counts['skipped'] ?? 0)),
            'error' => max(0, (int) ($counts['error'] ?? 0)),
        ));
    }

    /**
     * Project the active plan into the legacy cron-state API without persisting
     * a second copy of any plan field.
     *
     * @param array<string,mixed> $state Legacy worker state.
     * @return array<string,mixed>
     */
    private static function overlay_warm_plan_on_cron_state(array $state)
    {
        $plan = self::get_warm_plan_status(true);
        if (empty($plan['active'])) {
            $state['workloadType'] = self::normalize_cron_warm_workload_type(
                $state['workloadType'] ?? '',
                $state['reason'] ?? ''
            );
            if ('full_site' === $state['workloadType']) {
                $state['reason'] = '';
                $state['invokedBy'] = '';
                $state['workloadType'] = '';
            }
            $state['cursor'] = '';
            $state['totalLimit'] = 0;
            $state['fullSitePlanned'] = 0;
            $state['fullSiteProcessed'] = 0;
            $state['fullSiteSuccessCount'] = 0;
            $state['fullSiteSkippedCount'] = 0;
            $state['fullSiteErrorCount'] = 0;
            $state['fullSiteDiscoveryComplete'] = true;
            $state['mixedWorkloadNextClass'] = 'full_site';
            $state['currentBatch'] = array();
            $state['batchIndex'] = 0;
            $state['batchHasMore'] = false;
            $state['nextCursorPending'] = '';
            $state['warmPlan'] = $plan;
            return $state;
        }

        $state['reason'] = (string) $plan['reason'];
        $state['invokedBy'] = (string) $plan['invokedBy'];
        $state['workloadType'] = 'full_site';
        $state['cursor'] = (string) $plan['cursor'];
        $state['totalLimit'] = max(0, (int) $plan['selectionLimit']);
        $state['fullSitePlanned'] = max(0, (int) $plan['selected']);
        $state['fullSiteProcessed'] = max(0, (int) $plan['processed']);
        $state['fullSiteSuccessCount'] = max(0, (int) $plan['success']);
        $state['fullSiteSkippedCount'] = max(0, (int) $plan['skipped']);
        $state['fullSiteErrorCount'] = max(0, (int) $plan['error']);
        $state['fullSiteDiscoveryComplete'] = !empty($plan['discoveryComplete']);
        $state['mixedWorkloadNextClass'] = (string) $plan['mixedWorkloadNextClass'];
        $state['currentBatch'] = (array) $plan['currentBatch'];
        $state['batchIndex'] = max(0, (int) $plan['batchIndex']);
        $state['batchHasMore'] = !empty($plan['batchHasMore']);
        $state['nextCursorPending'] = (string) $plan['nextCursorPending'];
        $state['warmPlan'] = $plan;
        return $state;
    }
}
