<?php
/**
 * Scheduled maintenance, cron event coordination, and bounded cleanup.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Scheduled_Maintenance_Trait
{
    private static function get_cache_cleanup_schedule_name($hours)
    {
        return 'ultracache_every_' . max(1, absint($hours)) . '_hours';
    }

    public function register_cron_schedules($schedules)
    {
        $settings = self::get_settings();
        $hours    = max(1, absint($settings['cache_cleanup_interval_hours']));
        $key      = self::get_cache_cleanup_schedule_name($hours);

        if (empty($schedules[$key])) {
            $schedules[$key] = array(
                'interval' => $hours * HOUR_IN_SECONDS,
                'display'  => self::maybe_translate_sprintf('Every %d hour(s) for UltraCache', $hours),
            );
        }

        if (empty($schedules['ultracache_every_minute'])) {
            $schedules['ultracache_every_minute'] = array(
                'interval' => MINUTE_IN_SECONDS,
                'display'  => self::maybe_translate('Every minute for UltraCache'),
            );
        }

        return $schedules;
    }

    public static function unschedule_scheduled_events()
    {
        $timestamp = wp_next_scheduled('ultracache_scheduled_cache_cleanup');
        while ($timestamp) {
            wp_unschedule_event($timestamp, 'ultracache_scheduled_cache_cleanup');
            $timestamp = wp_next_scheduled('ultracache_scheduled_cache_cleanup');
        }
    }

    /**
     * Backward-compatible cleanup scheduler alias.
     *
     * The destructive cleanup action used this method name, while the
     * actual scheduled cleanup helper is unschedule_scheduled_events().
     * Keep this wrapper so the REST cleanup action cannot fatal.
     */
    public static function unschedule_cache_cleanup()
    {
        self::unschedule_scheduled_events();
    }

    private static function unschedule_cron_warm_events($force = false)
    {
        $timestamp = wp_next_scheduled('ultracache_cron_warm_tick');
        while ($timestamp) {
            wp_unschedule_event($timestamp, 'ultracache_cron_warm_tick');
            $timestamp = wp_next_scheduled('ultracache_cron_warm_tick');
        }

        $kickoff_timestamp = wp_next_scheduled('ultracache_cron_warm_tick_kickoff');
        while ($kickoff_timestamp) {
            wp_unschedule_event($kickoff_timestamp, 'ultracache_cron_warm_tick_kickoff');
            $kickoff_timestamp = wp_next_scheduled('ultracache_cron_warm_tick_kickoff');
        }

        $has_pending_varnish = method_exists(static::class, 'has_pending_varnish_queue_rows')
            && self::has_pending_varnish_queue_rows();
        $keep_refresh_ahead = method_exists(static::class, 'should_keep_varnish_refresh_ahead_cron')
            && self::should_keep_varnish_refresh_ahead_cron();
        if (!$force && ($has_pending_varnish || $keep_refresh_ahead)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'ultracache_every_minute', 'ultracache_cron_warm_tick');
            if ($has_pending_varnish) {
                wp_schedule_single_event(time() + 5, 'ultracache_cron_warm_tick_kickoff');
            }
        }
    }

    public static function sync_scheduled_events()
    {
        self::unschedule_scheduled_events();

        $settings = self::get_settings();
        if (!empty($settings['cache_cleanup_enabled'])) {
            $hours    = max(1, absint($settings['cache_cleanup_interval_hours']));
            $schedule = self::get_cache_cleanup_schedule_name($hours);
            wp_schedule_event(time() + MINUTE_IN_SECONDS, $schedule, 'ultracache_scheduled_cache_cleanup');
        }

        if (method_exists(static::class, 'should_keep_varnish_refresh_ahead_cron') && self::should_keep_varnish_refresh_ahead_cron()) {
            self::ensure_cron_warm_events_scheduled();
        }
    }

    private static function has_cron_warm_recurring_event_scheduled()
    {
        return false !== wp_next_scheduled('ultracache_cron_warm_tick');
    }

    private static function get_next_cron_warm_scheduled_at()
    {
        $times = array();
        $main = wp_next_scheduled('ultracache_cron_warm_tick');
        if ($main) {
            $times[] = (int) $main;
        }

        $kickoff = wp_next_scheduled('ultracache_cron_warm_tick_kickoff');
        if ($kickoff) {
            $times[] = (int) $kickoff;
        }

        return empty($times) ? 0 : min($times);
    }

    private static function ensure_cron_warm_events_scheduled($kickoff_delay = null, $allow_during_manual_warm = false)
    {
        if (!$allow_during_manual_warm && self::is_manual_warmup_blocking_cron()) {
            self::unschedule_cron_warm_events();
            return false;
        }

        if (!self::has_cron_warm_recurring_event_scheduled()) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'ultracache_every_minute', 'ultracache_cron_warm_tick');
        }

        if (null !== $kickoff_delay && !wp_next_scheduled('ultracache_cron_warm_tick_kickoff')) {
            $kickoff_delay = max(1, min(300, (int) $kickoff_delay));
            wp_schedule_single_event(time() + $kickoff_delay, 'ultracache_cron_warm_tick_kickoff');
        }
    }

    public function handle_scheduled_cache_cleanup()
    {
        self::run_scheduled_cache_cleanup();
    }

    public function handle_cron_warm_tick()
    {
        self::run_cron_warm_tick(array('invokedBy' => 'wp-cron'));
    }

    public function handle_cron_warm_tick_kickoff()
    {
        self::run_cron_warm_tick(array('invokedBy' => 'wp-cron-kickoff'));
    }

    public function handle_cron_warm_after_purge_all($payload = array())
    {
        self::maybe_start_cron_warmup_after_purge('manual_purge', false);
    }

    public static function maybe_start_cron_warmup_after_purge($reason = 'manual_purge', $run_immediately = false)
    {
        if (self::is_manual_warmup_blocking_cron()) {
            return array('success' => false, 'message' => self::maybe_translate('Cron warm up is blocked while a manual warm-up is active or paused.'), 'state' => self::get_cron_warm_status());
        }

        if (!empty(self::$suppress_after_purge_warm)) {
            return array('success' => false, 'message' => self::maybe_translate('Cron warm start suppressed for this purge.'), 'state' => self::get_cron_warm_status());
        }

        $settings = self::get_settings();
        if (empty($settings['cron_warm_enabled'])) {
            return array('success' => false, 'message' => self::maybe_translate('Cron warm up is disabled.'), 'state' => self::get_cron_warm_status());
        }

        if (!in_array((string) $reason, array('scheduled_cleanup', 'manual_purge', 'manual', 'cli'), true)) {
            $reason = 'manual_purge';
        }

        if ('scheduled_cleanup' === $reason && empty($settings['cron_warm_start_after_cleanup'])) {
            return array('success' => false, 'message' => self::maybe_translate('Cron warm up after scheduled cleanup is disabled.'), 'state' => self::get_cron_warm_status());
        }

        if (in_array((string) $reason, array('manual_purge', 'manual', 'cli'), true) && empty($settings['cron_warm_start_after_manual_purge'])) {
            return array('success' => false, 'message' => self::maybe_translate('Cron warm up after manual purge is disabled.'), 'state' => self::get_cron_warm_status());
        }

        $pages_per_minute = max(0, (int) $settings['cron_warm_pages_per_minute']);
        if ($pages_per_minute < 1) {
            return array('success' => false, 'message' => self::maybe_translate('Cron warm up is paused because pages per minute is 0.'), 'state' => self::get_cron_warm_status());
        }

        return self::start_cron_warmup_queue((string) $reason, (bool) $run_immediately);
    }

    private static function get_database_retention_delete_limit($default = 500)
    {
        $limit = (int) apply_filters('ultracache_database_retention_max_deletes_per_run', $default);
        return max(25, min(1000, $limit));
    }

    private static function cleanup_plugin_database_table_rows($table, $operation, array $args = array())
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb) || !self::plugin_custom_table_exists($table)) {
            return 0;
        }

        $deleted = 0;
        switch ((string) $operation) {
            case 'action_terminal':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded retention cleanup deletes only UltraCache-owned historical custom-table rows.
                $deleted = $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE status IN ('done','failed') AND finished_at > 0 AND finished_at < %d LIMIT %d", $table, (int) ($args[0] ?? 0), (int) ($args[1] ?? 0)));
                break;
            case 'action_stale':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded retention cleanup deletes only stale UltraCache-owned dashboard action rows.
                $deleted = $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE status IN ('queued','running') AND updated_at > 0 AND updated_at < %d LIMIT %d", $table, (int) ($args[0] ?? 0), (int) ($args[1] ?? 0)));
                break;
            case 'cron_processed':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded retention cleanup deletes only processed UltraCache-owned warm queue rows.
                $deleted = $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE status IN ('done','error') AND processed_at > 0 AND processed_at < %d LIMIT %d", $table, (int) ($args[0] ?? 0), (int) ($args[1] ?? 0)));
                break;
            case 'cron_orphan':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded retention cleanup deletes only orphaned UltraCache-owned warm queue rows when the queue is inactive.
                $deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE updated_at > 0 AND updated_at < %d LIMIT %d', $table, (int) ($args[0] ?? 0), (int) ($args[1] ?? 0)));
                break;
            case 'media_refs_purged':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded retention cleanup deletes only already-purged UltraCache-owned media page refs.
                $deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE purged_at IS NOT NULL AND purged_at <> %s AND purged_at < %s LIMIT %d', $table, '0000-00-00 00:00:00', (string) ($args[0] ?? ''), (int) ($args[1] ?? 0)));
                break;
            case 'media_refs_complete':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded retention cleanup deletes only completed stale UltraCache-owned media page refs, never pending refs.
                $deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE status = %s AND (purged_at IS NULL OR purged_at = %s) AND purge_ready_at IS NOT NULL AND purge_ready_at <> %s AND purge_ready_at < %s LIMIT %d', $table, 'complete', '0000-00-00 00:00:00', '0000-00-00 00:00:00', (string) ($args[0] ?? ''), (int) ($args[1] ?? 0)));
                break;
        }

        return is_numeric($deleted) ? max(0, (int) $deleted) : 0;
    }

    public static function cleanup_plugin_database_tables(array $args = array())
    {
        global $wpdb;

        $dry_run = !empty($args['dry_run']);
        $limit = isset($args['limit']) ? (int) $args['limit'] : self::get_database_retention_delete_limit(500);
        $limit = max(25, min(1000, $limit));
        $now = time();
        $summary = array(
            'success' => true,
            'dryRun' => $dry_run,
            'limit' => $limit,
            'deleted' => 0,
            'updated' => 0,
            'tables' => array(),
            'policy' => array(
                'activeRowsPreserved' => true,
                'mediaQueueCompletionRowsKept' => true,
                'boundedDeletes' => true,
            ),
        );

        if (!($wpdb instanceof wpdb)) {
            $summary['success'] = false;
            $summary['message'] = 'Database cleanup skipped because wpdb is unavailable.';
            return $summary;
        }

        $tables = array(
            'actionJobs' => $wpdb->prefix . 'ultracache_action_jobs',
            'jsDiagnosticJobs' => $wpdb->prefix . 'ultracache_js_diagnostic_jobs',
            'cronWarmQueue' => $wpdb->prefix . 'ultracache_cron_warm_queue',
            'mediaPageRefs' => $wpdb->prefix . 'ultracache_media_page_refs',
            'mediaQueue' => $wpdb->prefix . 'ultracache_media_queue',
            'analytics' => $wpdb->prefix . 'ultracache_analytics',
            'cacheAssetRefs' => self::get_cache_asset_refs_table_name(),
            'cssRewriteMap' => self::get_css_rewrite_map_table_name(),
            'locks' => ultracache_get_locks_table_name(),
        );

        if (self::plugin_custom_table_exists($tables['actionJobs'])) {
            $action_cutoff = $now - (int) apply_filters('ultracache_action_jobs_terminal_retention_seconds', DAY_IN_SECONDS);
            $stale_cutoff = $now - (int) apply_filters('ultracache_action_jobs_stale_nonterminal_seconds', 6 * HOUR_IN_SECONDS);
            $deleted = 0;
            if (!$dry_run) {
                $deleted += self::cleanup_plugin_database_table_rows(
                    $tables['actionJobs'],
                    'action_terminal',
                    array($action_cutoff, $limit)
                );
                $remaining = max(0, $limit - $deleted);
                if ($remaining > 0) {
                    $deleted += self::cleanup_plugin_database_table_rows(
                        $tables['actionJobs'],
                        'action_stale',
                        array($stale_cutoff, $remaining)
                    );
                }
            }
            $summary['tables']['actionJobs'] = array('deleted' => $deleted, 'retentionSeconds' => $now - $action_cutoff);
            $summary['deleted'] += $deleted;
        }

        if (self::plugin_custom_table_exists($tables['cronWarmQueue'])) {
            $state = self::get_cron_warm_state();
            $active = !empty($state['active']);
            $processed_cutoff = $now - (int) apply_filters('ultracache_cron_warm_queue_processed_retention_seconds', 6 * HOUR_IN_SECONDS);
            $orphan_cutoff = $now - (int) apply_filters('ultracache_cron_warm_queue_orphan_retention_seconds', DAY_IN_SECONDS);
            $deleted = 0;
            if (!$dry_run) {
                $deleted += self::cleanup_plugin_database_table_rows(
                    $tables['cronWarmQueue'],
                    'cron_processed',
                    array($processed_cutoff, $limit)
                );
                if (!$active) {
                    $remaining = max(0, $limit - $deleted);
                    if ($remaining > 0) {
                        $deleted += self::cleanup_plugin_database_table_rows(
                            $tables['cronWarmQueue'],
                            'cron_orphan',
                            array($orphan_cutoff, $remaining)
                        );
                    }
                }
            }
            $summary['tables']['cronWarmQueue'] = array('deleted' => $deleted, 'activePreserved' => $active);
            $summary['deleted'] += $deleted;
        }

        if (self::plugin_custom_table_exists($tables['mediaPageRefs'])) {
            $purged_cutoff = get_date_from_gmt(gmdate('Y-m-d H:i:s', $now - (int) apply_filters('ultracache_media_page_refs_purged_retention_seconds', HOUR_IN_SECONDS)));
            $complete_cutoff = get_date_from_gmt(gmdate('Y-m-d H:i:s', $now - (int) apply_filters('ultracache_media_page_refs_complete_retention_seconds', 2 * DAY_IN_SECONDS)));
            $deleted = 0;
            if (!$dry_run) {
                $deleted += self::cleanup_plugin_database_table_rows(
                    $tables['mediaPageRefs'],
                    'media_refs_purged',
                    array($purged_cutoff, $limit)
                );
                $remaining = max(0, $limit - $deleted);
                if ($remaining > 0) {
                    $deleted += self::cleanup_plugin_database_table_rows(
                        $tables['mediaPageRefs'],
                        'media_refs_complete',
                        array($complete_cutoff, $remaining)
                    );
                }
            }
            $summary['tables']['mediaPageRefs'] = array('deleted' => $deleted, 'pendingPreserved' => true);
            $summary['deleted'] += $deleted;
        }

        if (self::plugin_custom_table_exists($tables['mediaQueue'])) {
            $processing_cutoff = get_date_from_gmt(gmdate('Y-m-d H:i:s', $now - (int) apply_filters('ultracache_media_queue_processing_stale_seconds', HOUR_IN_SECONDS)));
            $updated = 0;
            if (!$dry_run) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded stale processing recovery updates only UltraCache-owned media queue rows and preserves done/skipped completion history for large stores.
                $updated = $wpdb->query(
                    $wpdb->prepare(
                        'UPDATE %i SET status = %s, last_error = %s, updated_at = %s, started_at = NULL WHERE status = %s AND started_at IS NOT NULL AND started_at <> %s AND started_at < %s LIMIT %d',
                        $tables['mediaQueue'],
                        'pending',
                        'Recovered from stale processing state by scheduled UltraCache DB cleanup.',
                        current_time('mysql'),
                        'processing',
                        '0000-00-00 00:00:00',
                        $processing_cutoff,
                        $limit
                    )
                );
                $updated = is_numeric($updated) ? max(0, (int) $updated) : 0;
            }
            $summary['tables']['mediaQueue'] = array('updated' => $updated, 'deleted' => 0, 'completionRowsKept' => true);
            $summary['updated'] += $updated;
        }

        if (self::plugin_custom_table_exists($tables['analytics'])) {
            $reason_cap = (int) apply_filters('ultracache_analytics_reason_row_cap', 100);
            $reason_cap = max(20, min(500, $reason_cap));
            $deleted = 0;
            if (!$dry_run) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded analytics cleanup removes only low-ranking reason rows; aggregate counters remain intact.
                $deleted = $wpdb->query(
                    $wpdb->prepare(
                        'DELETE FROM %i WHERE metric_type = %s AND metric_key NOT IN (SELECT metric_key FROM (SELECT metric_key FROM %i WHERE metric_type = %s ORDER BY metric_value DESC, updated_at DESC LIMIT %d) AS ultracache_keep_reasons)',
                        $tables['analytics'],
                        'reason',
                        $tables['analytics'],
                        'reason',
                        $reason_cap
                    )
                );
                $deleted = is_numeric($deleted) ? max(0, (int) $deleted) : 0;
            }
            $summary['tables']['analytics'] = array('deleted' => $deleted, 'reasonCap' => $reason_cap);
            $summary['deleted'] += $deleted;
        }

        if (self::plugin_custom_table_exists($tables['cacheAssetRefs'])) {
            $deleted = 0;
            if (!$dry_run) {
                $deleted = self::prune_cache_asset_refs_table($limit);
            }
            $summary['tables']['cacheAssetRefs'] = array('deleted' => $deleted, 'expiredInactiveOnly' => true);
            $summary['deleted'] += $deleted;
        }

        if (self::plugin_custom_table_exists($tables['locks'])) {
            $deleted = 0;
            if (!$dry_run && function_exists('ultracache_prune_expired_locks')) {
                $deleted = ultracache_prune_expired_locks($limit);
            }
            $summary['tables']['locks'] = array('deleted' => $deleted, 'expiredOnly' => true);
            $summary['deleted'] += $deleted;
        }

        return $summary;
    }

    public static function run_scheduled_cache_cleanup()
    {
        if (self::is_manual_warmup_blocking_cron()) {
            return array(
                'success' => false,
                'skipped' => true,
                'skipReason' => 'manual_warm_priority',
                'message' => self::maybe_translate('Scheduled cache cleanup was skipped because a manual warm-up has priority.'),
                'warmed' => 0,
                'queueStarted' => false,
            );
        }

        $engine = self::get_engine_instance();
        $settings = self::get_settings();
        $purged   = false;
        $warmed   = 0;
        $queue_started = false;
        $object_cache_removed = 0;
        $apcu_flushed = false;
        $apcu_flush_message = '';
        $css_storage_before = self::get_cache_storage_diagnostics($settings);

        if ($engine && method_exists($engine, 'purge_all')) {
            self::$suppress_after_purge_warm = true;
            try {
                $purged = (bool) $engine->purge_all();
            } finally {
                self::$suppress_after_purge_warm = false;
            }
        }

        if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'cleanup_expired_entries')) {
            $object_cache_removed = (int) Ultra_Cache_Object_Cache_Manager::cleanup_expired_entries();
        }

        if (!empty($settings['apcu_flush_on_scheduled_cleanup'])) {
            $apcu_flush_result = self::clear_apcu_user_cache(false);
            $apcu_flushed = !empty($apcu_flush_result['success']);
            $apcu_flush_message = isset($apcu_flush_result['message']) ? (string) $apcu_flush_result['message'] : '';
        }

        if ($purged) {
            $start_result = self::maybe_start_cron_warmup_after_purge('scheduled_cleanup', false);
            $queue_started = !empty($start_result['success']) && !empty(($start_result['state']['active'] ?? false));
            $warmed = (int) ($start_result['warmedThisRun'] ?? 0);
        }

        $css_storage_after = self::get_cache_storage_diagnostics($settings);
        $css_before = isset($css_storage_before['cssBundles']) && is_array($css_storage_before['cssBundles']) ? $css_storage_before['cssBundles'] : array();
        $css_after = isset($css_storage_after['cssBundles']) && is_array($css_storage_after['cssBundles']) ? $css_storage_after['cssBundles'] : array();
        $css_files_before = max(0, (int) ($css_before['files'] ?? ($css_before['totalFiles'] ?? 0)));
        $css_files_after = max(0, (int) ($css_after['files'] ?? ($css_after['totalFiles'] ?? 0)));

        $runtime_artifacts_cleanup = self::cleanup_runtime_artifacts(array(
            'dry_run' => false,
            'max_age_seconds' => 600,
        ));

        $db_retention_cleanup = self::cleanup_plugin_database_tables(array(
            'dry_run' => false,
            'limit' => self::get_database_retention_delete_limit(500),
        ));

        return array(
            'success' => ($purged || $object_cache_removed > 0 || $apcu_flushed || $css_files_before !== $css_files_after || !empty($runtime_artifacts_cleanup['deleted']) || !empty($db_retention_cleanup['deleted']) || !empty($db_retention_cleanup['updated'])),
            'warmed'  => $warmed,
            'queueStarted' => $queue_started,
            'objectCacheRemoved' => $object_cache_removed,
            'apcuFlushed' => $apcu_flushed,
            'apcuFlushMessage' => $apcu_flush_message,
            'cssBundleFilesBefore' => $css_files_before,
            'cssBundleFilesAfter' => $css_files_after,
            'cssBundleFilesDeleted' => max(0, $css_files_before - $css_files_after),
            'cssBundleOldOrphanLikeBefore' => max(0, (int) ($css_before['oldOrphanLikeFiles'] ?? 0)),
            'cssBundleRecentOrphanLikeBefore' => max(0, (int) ($css_before['recentOrphanLikeFiles'] ?? 0)),
            'cssBundleProtectedByCachedHtmlBefore' => max(0, (int) ($css_before['protectedByCachedHtmlRefs'] ?? 0)),
            'cssBundleCachedHtmlRefsBefore' => max(0, (int) ($css_before['cachedHtmlRefFiles'] ?? 0)),
            'cssBundleCleanupLimit' => max(0, (int) ($css_after['cleanupDeleteLimit'] ?? self::get_storage_cleanup_max_deletes_per_run())),
            'cssBundleGraceSeconds' => max(0, (int) ($css_after['graceSeconds'] ?? self::get_storage_cleanup_grace_seconds())),
            'runtimeArtifactsScanned' => (int) ($runtime_artifacts_cleanup['scanned'] ?? 0),
            'runtimeArtifactsDeleted' => (int) ($runtime_artifacts_cleanup['deleted'] ?? 0),
            'runtimeArtifactsSkippedActive' => (int) ($runtime_artifacts_cleanup['skippedActive'] ?? 0),
            'runtimeArtifactsSkippedYoung' => (int) ($runtime_artifacts_cleanup['skippedYoung'] ?? 0),
            'databaseRetentionDeleted' => (int) ($db_retention_cleanup['deleted'] ?? 0),
            'databaseRetentionUpdated' => (int) ($db_retention_cleanup['updated'] ?? 0),
            'databaseRetentionTables' => isset($db_retention_cleanup['tables']) && is_array($db_retention_cleanup['tables']) ? $db_retention_cleanup['tables'] : array(),
        );
    }

    public static function cleanup_runtime_artifacts(array $args = array())
    {
        $dry_run = !empty($args['dry_run']);
        $max_age_seconds = isset($args['max_age_seconds']) ? max(60, (int) $args['max_age_seconds']) : 600;
        $now = time();
        $locks_dir = trailingslashit(ULTRACACHE_CACHE_DIR) . 'locks/';

        $result = array(
            'success' => true,
            'dryRun' => $dry_run,
            'directory' => $locks_dir,
            'maxAgeSeconds' => $max_age_seconds,
            'scanned' => 0,
            'matched' => 0,
            'deleted' => 0,
            'wouldDelete' => 0,
            'skippedActive' => 0,
            'skippedYoung' => 0,
            'skippedUnknown' => 0,
            'failed' => 0,
            'items' => array(),
            'message' => '',
        );

        if (!is_dir($locks_dir) || !is_readable($locks_dir)) {
            $result['message'] = 'Runtime locks directory does not exist or is not readable.';
            return $result;
        }

        $items = ultracache_safe_scandir($locks_dir, 'runtime_lock_cleanup');
        if (!is_array($items)) {
            $result['success'] = false;
            $result['message'] = 'Unable to read runtime locks directory.';
            return $result;
        }

        $runtime_lock_pattern = '/^(?:purge-all|page-cache-(?:write|build)-[a-f0-9]{32}|css-(?:bundle|entry)-[a-f0-9]{32})\.lock$/i';
        $test_artifact_pattern = '/^(?:baseline-dummy|verify-dummy(?:-[a-z0-9_.-]+)?|ultracache-test-[a-z0-9_.-]+|ultracache-test-[a-z0-9_.-]+)\.lock$/i';

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $name = basename((string) $item);
            if ($name !== (string) $item || '' === $name || false === strpos($name, '.lock')) {
                continue;
            }

            $path = $locks_dir . $name;
            if (!is_file($path) || is_link($path)) {
                continue;
            }

            $result['scanned']++;
            $is_test_artifact = (bool) preg_match($test_artifact_pattern, $name);
            $is_runtime_lock = (bool) preg_match($runtime_lock_pattern, $name);
            if (!$is_test_artifact && !$is_runtime_lock) {
                $result['skippedUnknown']++;
                continue;
            }

            $mtime = ultracache_safe_filemtime($path, 'runtime_artifact_cleanup');
            $age = false === $mtime ? 0 : max(0, $now - (int) $mtime);
            $delete_reason = $is_test_artifact ? 'test-artifact' : 'expired-runtime-lock-marker';

            if (!$is_test_artifact && $age < $max_age_seconds) {
                $result['matched']++;
                $result['skippedYoung']++;
                $result['items'][] = array(
                    'file' => $name,
                    'action' => 'skip-young',
                    'reason' => $delete_reason,
                    'ageSeconds' => $age,
                );
                continue;
            }

            $locked = true;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native flock check is required to avoid deleting active runtime lock files. Path is restricted to UltraCache locks/.
            $handle = @fopen($path, 'c+');
            if ($handle) {
                $locked = !@flock($handle, LOCK_EX | LOCK_NB);
            }

            $result['matched']++;
            if ($locked) {
                if ($handle) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing native flock probe handle.
                    @fclose($handle);
                }
                $result['skippedActive']++;
                $result['items'][] = array(
                    'file' => $name,
                    'action' => 'skip-active',
                    'reason' => $delete_reason,
                    'ageSeconds' => $age,
                );
                continue;
            }

            if ($dry_run) {
                if ($handle) {
                    @flock($handle, LOCK_UN);
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing native flock probe handle.
                    @fclose($handle);
                }
                $result['wouldDelete']++;
                $result['items'][] = array(
                    'file' => $name,
                    'action' => 'would-delete',
                    'reason' => $delete_reason,
                    'ageSeconds' => $age,
                );
                continue;
            }

            if ($handle) {
                @flock($handle, LOCK_UN);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing native flock probe handle before WP-safe deletion.
                @fclose($handle);
            }

            $deleted = ultracache_safe_unlink($path, 'runtime_artifact_cleanup');

            if ($deleted) {
                $result['deleted']++;
                $result['items'][] = array(
                    'file' => $name,
                    'action' => 'deleted',
                    'reason' => $delete_reason,
                    'ageSeconds' => $age,
                );
            } else {
                $result['failed']++;
                $result['items'][] = array(
                    'file' => $name,
                    'action' => 'failed-delete',
                    'reason' => $delete_reason,
                    'ageSeconds' => $age,
                );
            }
        }

        if ($result['failed'] > 0) {
            $result['success'] = false;
        }

        $result['message'] = sprintf(
            'Runtime artifact cleanup scanned %d lock file(s), matched %d, deleted %d, would delete %d, skipped active %d, skipped young %d.',
            (int) $result['scanned'],
            (int) $result['matched'],
            (int) $result['deleted'],
            (int) $result['wouldDelete'],
            (int) $result['skippedActive'],
            (int) $result['skippedYoung']
        );

        return $result;
    }
}
