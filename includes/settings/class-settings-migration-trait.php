<?php
/**
 * Legacy cache-conflict detection and cleanup.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Settings_Migration_Trait
{


    /**
     * Migrate the removed Scroll delayed-JS release trigger once its legacy
     * value is encountered in stored settings.
     *
     * Scroll could fire without intentional user interaction on some pages.
     * Existing Scroll users are therefore moved to Mouse move, Keyboard,
     * Touch / pointer, and Click while Scroll is permanently disabled.
     *
     * @return array<string,mixed>
     */
    public static function maybe_migrate_removed_delayed_js_scroll_trigger()
    {
        $missing = '__ultracache_missing_' . md5(__METHOD__);
        $stored = get_option(ULTRACACHE_SETTINGS_KEY, $missing);
        if ($missing === $stored || !is_array($stored)) {
            return array('success' => true, 'skipped' => true, 'reason' => 'settings_missing');
        }

        $scroll_enabled = self::normalize_boolean_setting_value(
            $stored['delayedJsAutostartScrollEnabled'] ?? false,
            false
        );
        if (!$scroll_enabled) {
            return array('success' => true, 'skipped' => true, 'reason' => 'scroll_not_enabled');
        }

        $stored['delayedJsAutostartScrollEnabled'] = false;
        $stored['delayedJsAutostartMousemoveEnabled'] = true;
        $stored['delayedJsAutostartKeyboardEnabled'] = true;
        $stored['delayedJsAutostartTouchPointerEnabled'] = true;
        $stored['delayedJsAutostartClickEnabled'] = true;
        update_option(ULTRACACHE_SETTINGS_KEY, $stored, false);

        return array('success' => true, 'skipped' => false, 'reason' => 'scroll_migrated');
    }


    /**
     * Finalize the 3.05 LiteSpeed Automation contract once per installation.
     *
     * Retired LiteSpeed warm/refill/stale/refresh-ahead settings are removed
     * from the canonical stored dashboard option. The old refresh-ahead
     * candidate/state stores are also deleted. This migration does not purge
     * cache, schedule warm work, or change active Automation settings.
     *
     * @return array<string,mixed>
     */
    public static function maybe_finalize_litespeed_automation_contract()
    {
        $marker_key = 'ultracache_litespeed_automation_contract';
        $target = '1';
        if ($target === (string) get_option($marker_key, '')) {
            return array('success' => true, 'skipped' => true, 'reason' => 'already_complete');
        }

        $missing = '__ultracache_missing_' . md5(__METHOD__);
        $stored = get_option(ULTRACACHE_SETTINGS_KEY, $missing);
        if ($missing !== $stored && is_array($stored)) {
            foreach (array(
                'liteSpeedRefillAfterTargetedInvalidation',
                'liteSpeedWarmDuringSiteWarmup',
                'liteSpeedStalePurgeEnabled',
                'liteSpeedRefreshAheadEnabled',
                'liteSpeedRefreshAheadThresholdPercent',
                'liteSpeedRefreshAheadMaxPages',
                'liteSpeedRefreshAheadPinnedUrls',
            ) as $retired_key) {
                unset($stored[$retired_key]);
            }
            update_option(ULTRACACHE_SETTINGS_KEY, $stored, false);
        }

        delete_option('ultracache_litespeed_refresh_ahead_state_v1');
        delete_option('ultracache_litespeed_refresh_candidates_v1');
        update_option($marker_key, $target, false);

        return array('success' => true, 'skipped' => false, 'reason' => 'finalized');
    }

    /** Return the persistent LiteSpeed semantic server-rule contract marker. */
    private static function get_litespeed_semantic_rules_contract_option_key()
    {
        return 'ultracache_litespeed_semantic_rules_contract';
    }

    /**
     * Synchronize the one-time server-rule transition required by semantic
     * LiteSpeed tags. When native LSCache is active, direct Apache static HTML
     * aliases must be removed so outer-cache MISS requests pass through the
     * advanced-cache drop-in, which can emit the per-page semantic tag sidecar.
     *
     * This runs only from admin_init and persists completion in a normal
     * WordPress option; it is never backed by a transient.
     *
     * @return array<string,mixed>
     */
    public static function maybe_sync_litespeed_semantic_rules_contract()
    {
        $target = '1';
        $option_key = self::get_litespeed_semantic_rules_contract_option_key();
        if ($target === (string) get_option($option_key, '')) {
            return array('success' => true, 'skipped' => true, 'reason' => 'already_complete');
        }

        $settings = self::get_settings();
        $litespeed_enabled = (!empty($settings['litespeed_cache_enabled']) || !empty($settings['liteSpeedCacheEnabled']))
            && (!empty($settings['enabled']) || !empty($settings['pageCacheEnabled']));

        if (!$litespeed_enabled) {
            update_option($option_key, $target, false);
            return array('success' => true, 'skipped' => true, 'reason' => 'litespeed_disabled');
        }

        $apache_static_sync = self::sync_apache_static_html_delivery_rules();
        $litespeed_sync = self::sync_litespeed_cache_rules();
        if (false === $apache_static_sync || false === $litespeed_sync) {
            return array(
                'success' => false,
                'reason' => 'server_rules_sync_failed',
                'apacheStatic' => false !== $apache_static_sync,
                'liteSpeed' => false !== $litespeed_sync,
            );
        }

        update_option($option_key, $target, false);
        return array('success' => true, 'skipped' => false, 'reason' => 'synchronized');
    }

    /**
     * Return the only public warm-runtime reset target.
     *
     * UltraCache 2.59.10.01 is the public WordPress.org/SVN baseline. Later
     * 2.59.10.x builds were private development snapshots, so their warm
     * runtime may be discarded instead of migrated.
     *
     * @return string
     */
    private static function get_public_warm_runtime_reset_target_version()
    {
        return '2.59.12.14';
    }

    /**
     * Return the completed reset marker option key.
     *
     * @return string
     */
    private static function get_public_warm_runtime_reset_version_option_key()
    {
        return 'ultracache_warm_runtime_reset_version';
    }

    /**
     * Return the resumable reset phase option key.
     *
     * @return string
     */
    private static function get_public_warm_runtime_reset_state_option_key()
    {
        return 'ultracache_warm_runtime_reset_state';
    }

    /**
     * Return the dedicated update-reset lock name.
     *
     * @return string
     */
    private static function get_public_warm_runtime_reset_lock_name()
    {
        return 'ultracache_plugin_upgrade_warm_reset';
    }

    /**
     * Detect an existing UltraCache installation before activation creates any
     * new schema/version markers. Fresh installs are marked complete without a
     * cache flush.
     *
     * @return bool
     */
    private static function has_existing_ultracache_installation_evidence()
    {
        $missing = '__ultracache_missing_' . md5(__METHOD__);
        $option_keys = array(
            ULTRACACHE_SETTINGS_KEY,
            self::get_cron_warm_queue_db_version_option_key(),
            self::get_public_warm_runtime_reset_version_option_key(),
            self::get_public_warm_runtime_reset_state_option_key(),
            ULTRACACHE_CRON_WARM_STATE_KEY,
            ULTRACACHE_MANUAL_WARM_STATE_KEY,
            'ultracache_warmup_generation',
            ultracache_get_locks_db_version_option_key(),
            ULTRACACHE_WP_CACHE_MANAGED_KEY,
        );

        foreach ($option_keys as $option_key) {
            if ($missing !== get_option((string) $option_key, $missing)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the normalized resumable reset state.
     *
     * @return array<string,mixed>
     */
    private static function get_public_warm_runtime_reset_state()
    {
        $state = get_option(self::get_public_warm_runtime_reset_state_option_key(), array());
        $state = is_array($state) ? $state : array();
        $phase = sanitize_key((string) ($state['phase'] ?? ''));
        if (!in_array($phase, array('', 'preparing', 'flushed', 'initialized'), true)) {
            $phase = '';
        }

        return array(
            'targetVersion' => sanitize_text_field((string) ($state['targetVersion'] ?? '')),
            'phase' => $phase,
            'context' => sanitize_key((string) ($state['context'] ?? '')),
            'detectedQueueSchema' => sanitize_text_field((string) ($state['detectedQueueSchema'] ?? '')),
            'updatedAt' => max(0, (int) ($state['updatedAt'] ?? 0)),
        );
    }

    /**
     * Persist one resumable reset phase.
     *
     * @param string $phase   Reset phase.
     * @param string $context Invocation context.
     * @return array<string,mixed>
     */
    private static function save_public_warm_runtime_reset_state($phase, $context)
    {
        $state = array(
            'targetVersion' => self::get_public_warm_runtime_reset_target_version(),
            'phase' => sanitize_key((string) $phase),
            'context' => sanitize_key((string) $context),
            'detectedQueueSchema' => sanitize_text_field((string) get_option(self::get_cron_warm_queue_db_version_option_key(), '')),
            'updatedAt' => time(),
        );
        update_option(self::get_public_warm_runtime_reset_state_option_key(), $state, false);
        return $state;
    }

    /**
     * Check whether this exact public reset already completed.
     *
     * @return bool
     */
    private static function is_public_warm_runtime_reset_complete()
    {
        $completed = (string) get_option(self::get_public_warm_runtime_reset_version_option_key(), '');
        $target = self::get_public_warm_runtime_reset_target_version();
        return '' !== $completed && version_compare($completed, $target, '>=');
    }

    /**
     * Delete legacy option/transient runtime without touching user settings.
     *
     * @param bool $keep_generation Preserve the new post-flush generation.
     * @return void
     */
    private static function delete_legacy_warm_runtime_storage($keep_generation = false)
    {
        delete_option(ULTRACACHE_CRON_WARM_STATE_KEY);
        delete_option(ULTRACACHE_MANUAL_WARM_STATE_KEY);
        if (!$keep_generation) {
            delete_option('ultracache_warmup_generation');
        }
        self::delete_legacy_transient(ULTRACACHE_CRON_WARM_LOCK_KEY);
        self::delete_legacy_transient('ultracache_cron_warm_recovery_checked');
        wp_cache_flush_group('ultracache-warm-queue');
    }

    /**
     * Delete all private-development warm state and leases while preserving the
     * upgrade lock itself.
     *
     * @return bool
     */
    private static function delete_private_warm_coordination_runtime()
    {
        if (!function_exists('ultracache_delete_coordination_records_by_prefix')) {
            return false;
        }

        $state_deleted = ultracache_delete_coordination_records_by_prefix('state', 'ultracache_state:warm_');
        $cron_locks_deleted = ultracache_delete_coordination_records_by_prefix('lock', 'ultracache_cron_warm_');
        $page_locks_deleted = ultracache_delete_coordination_records_by_prefix('lock', 'ultracache_warm_page_');
        return false !== $state_deleted && false !== $cron_locks_deleted && false !== $page_locks_deleted;
    }

    /**
     * Initialize clean authoritative decision, plan, and rate records.
     *
     * @return bool
     */
    private static function initialize_clean_warm_coordination_runtime()
    {
        if (!function_exists('ultracache_create_state_record')) {
            return false;
        }

        if (!self::delete_private_warm_coordination_runtime()) {
            return false;
        }

        $configured_limit = self::get_configured_warm_rate_limit(self::get_settings());
        $now = time();
        $records = array(
            self::get_warm_decision_state_name() => self::normalize_warm_decision_state(self::get_default_warm_decision_state()),
            self::get_warm_plan_state_name() => self::normalize_warm_plan_state(self::get_default_warm_plan_state()),
            self::get_warm_rate_state_name() => self::normalize_warm_rate_state(array(
                'windowMinute' => (int) floor($now / MINUTE_IN_SECONDS),
                'claimedCount' => 0,
                'configuredLimit' => $configured_limit,
                'effectiveLimit' => $configured_limit,
                'updatedAt' => $now,
            )),
        );

        foreach ($records as $state_name => $payload) {
            $created = ultracache_create_state_record($state_name, $payload);
            if (empty($created['success'])) {
                return false;
            }
        }

        delete_option(ULTRACACHE_CRON_WARM_STATE_KEY);
        delete_option(ULTRACACHE_MANUAL_WARM_STATE_KEY);
        return true;
    }

    /**
     * Execute the canonical full cache purge once with warm-after-flush
     * scheduling suppressed until clean coordination state is committed.
     *
     * @return bool
     */
    private static function run_public_update_flush_all()
    {
        $engine = self::get_engine_instance();
        if (!$engine || !method_exists($engine, 'purge_all')) {
            return false;
        }

        self::$suppress_after_purge_warm = true;
        try {
            $purged = (bool) $engine->purge_all(array(
                'reason' => 'plugin_update',
                'source' => 'public_upgrade_reset',
            ));
        } finally {
            self::$suppress_after_purge_warm = false;
        }

        if (!$purged) {
            return false;
        }

        if (method_exists(__CLASS__, 'maybe_flush_external_caches_after_purge')) {
            self::maybe_flush_external_caches_after_purge();
        }
        return true;
    }

    /**
     * Finalize the reset after the destructive phase lock is released.
     *
     * The atomic warm-plan start path coalesces concurrent finalizers, so an
     * enabled warm-after-flush setting creates one fresh plan only.
     *
     * @return array<string,mixed>
     */
    private static function finalize_public_warm_runtime_reset()
    {
        $settings = self::get_settings();
        $warm_enabled = !empty($settings['cron_warm_start_after_manual_purge']);
        $rate_enabled = self::get_configured_warm_rate_limit($settings) > 0;
        $plan_result = array('success' => true, 'queued' => false);

        if ($warm_enabled && $rate_enabled) {
            $plan_result = self::maybe_start_cron_warmup_after_purge('plugin_update', false);
            if (empty($plan_result['success'])) {
                return array(
                    'success' => false,
                    'reason' => 'warm_plan_start_failed',
                    'plan' => $plan_result,
                );
            }
            $plan_result['queued'] = true;
        }

        update_option(
            self::get_public_warm_runtime_reset_version_option_key(),
            self::get_public_warm_runtime_reset_target_version(),
            false
        );
        delete_option(self::get_public_warm_runtime_reset_state_option_key());

        return array(
            'success' => true,
            'freshInstall' => false,
            'flushed' => true,
            'warmPlanCreated' => !empty($plan_result['queued']),
            'plan' => $plan_result,
        );
    }

    /**
     * Run the only supported public warm-runtime transition.
     *
     * @param string $context Invocation context.
     * @return array<string,mixed>
     */
    public static function maybe_run_public_warm_runtime_upgrade_reset($context = 'runtime')
    {
        $context = sanitize_key((string) $context);
        if (self::is_public_warm_runtime_reset_complete()) {
            return array('success' => true, 'skipped' => true, 'reason' => 'already_complete');
        }

        if (!self::has_existing_ultracache_installation_evidence()) {
            update_option(
                self::get_public_warm_runtime_reset_version_option_key(),
                self::get_public_warm_runtime_reset_target_version(),
                false
            );
            delete_option(self::get_public_warm_runtime_reset_state_option_key());
            return array('success' => true, 'freshInstall' => true, 'flushed' => false);
        }

        if (!ultracache_ensure_locks_table()) {
            return array('success' => false, 'reason' => 'coordination_schema_unavailable');
        }

        $lock_token = 'upgrade-reset-' . gmdate('YmdHis') . '-' . wp_generate_password(20, false, false);
        if (!ultracache_acquire_lock(
            self::get_public_warm_runtime_reset_lock_name(),
            $lock_token,
            300,
            array('targetVersion' => self::get_public_warm_runtime_reset_target_version(), 'context' => $context)
        )) {
            return array('success' => false, 'reason' => 'upgrade_reset_locked');
        }

        $destructive_success = false;
        self::$public_warm_runtime_reset_active = true;
        try {
            if (self::is_public_warm_runtime_reset_complete()) {
                return array('success' => true, 'skipped' => true, 'reason' => 'already_complete');
            }

            $reset_state = self::get_public_warm_runtime_reset_state();
            $same_target = self::get_public_warm_runtime_reset_target_version() === (string) $reset_state['targetVersion'];
            $phase = $same_target ? (string) $reset_state['phase'] : '';

            if (!in_array($phase, array('flushed', 'initialized'), true)) {
                self::save_public_warm_runtime_reset_state('preparing', $context);
                self::unschedule_cron_warm_events(true);
                self::delete_legacy_warm_runtime_storage(false);
                if (!self::delete_private_warm_coordination_runtime()) {
                    return array('success' => false, 'reason' => 'warm_coordination_clear_failed');
                }
                if (!self::recreate_cron_warm_queue_table_for_upgrade()) {
                    return array('success' => false, 'reason' => 'warm_queue_recreate_failed');
                }
                if (!self::run_public_update_flush_all()) {
                    return array('success' => false, 'reason' => 'flush_all_failed');
                }
                self::save_public_warm_runtime_reset_state('flushed', $context);
                $phase = 'flushed';
            }

            if ('flushed' === $phase) {
                self::unschedule_cron_warm_events(true);
                if (!self::recreate_cron_warm_queue_table_for_upgrade()) {
                    return array('success' => false, 'reason' => 'post_flush_queue_recreate_failed');
                }
                self::delete_legacy_warm_runtime_storage(true);
                if (!self::initialize_clean_warm_coordination_runtime()) {
                    return array('success' => false, 'reason' => 'warm_coordination_initialize_failed');
                }
                self::save_public_warm_runtime_reset_state('initialized', $context);
            }

            $destructive_success = true;
        } finally {
            self::$public_warm_runtime_reset_active = false;
            ultracache_release_lock(self::get_public_warm_runtime_reset_lock_name(), $lock_token);
        }

        if (!$destructive_success) {
            return array('success' => false, 'reason' => 'upgrade_reset_incomplete');
        }

        self::$public_warm_runtime_reset_active = true;
        try {
            return self::finalize_public_warm_runtime_reset();
        } finally {
            self::$public_warm_runtime_reset_active = false;
        }
    }



    /**
     * Delete one specifically named legacy UltraCache transient from both
     * single-site and network transient stores. This helper is the only
     * production allowlist for direct transient deletion after 2.59.12.36.
     *
     * @param string $transient_name Legacy transient name.
     * @return void
     */
    private static function delete_legacy_transient($transient_name)
    {
        $transient_name = sanitize_key((string) $transient_name);
        if ('' === $transient_name || 0 !== strpos($transient_name, 'ultracache_')) {
            return;
        }

        delete_transient($transient_name);
        delete_site_transient($transient_name);
    }

    /**
     * Return every fixed legacy UltraCache transient name. Dynamic rows are
     * removed separately through the explicit option-pattern cleanup.
     *
     * @return array<int,string>
     */
    private static function get_legacy_transient_names()
    {
        $legacy_transients = array(
            'ultracache_admin_notice',
            ULTRACACHE_CRON_WARM_LOCK_KEY,
            'ultracache_cron_warm_recovery_checked',
            'ultracache_loopback_ssl_status_v1',
            'ultracache_frontend_compression_probe_v1',
            'ultracache_object_cache_support_status_v1',
            'ultracache_media_conversion_queue_lock',
            'ultracache_media_queue_process_lock_v1',
            'ultracache_runtime_font_css_url_map_v3',
            'ultracache_media_library_conversion_test_v1',
            'ultracache_lcp_observation_map_v1',
            'ultracache_lcp_observations_cleanup_v2',
            'ultracache_media_work_summary_v1',
            'ultracache_media_storage_stats_v1',
            'ultracache_media_page_refs_cleanup_lock',
            'ultracache_media_queue_init_maintenance_v1',
            'ultracache_dashboard_cache_activity_v1',
            'ultracache_cache_storage_diagnostics_v2',
            'ultracache_dashboard_stats_snapshot_v2',
            'ultracache_last_cache_event',
            'ultracache_reverse_proxy_status_v2',
            'ultracache_varnish_last_result',
            'ultracache_varnish_html_flush_capability_v1',
            'ultracache_varnish_two_stage_refill_v1',
            'ultracache_varnish_soft_purge_capability_v1',
            'ultracache_varnish_refresh_ahead_capability_v1',
            'ultracache_varnish_performance_snapshot_v1',
            'ultracache_media_support_status_v4',
            'ultracache_media_support_status_v5',
            'ultracache_media_support_status_v6',
            'ultracache_imagick_avif_alpha_probe_v1',
            'ultracache_gd_avif_alpha_probe_v3',
            'ultracache_gd_webp_encode_probe_v2',
        );
        if (defined('ULTRACACHE_SETTINGS_KEY')) {
            $legacy_transients[] = ULTRACACHE_SETTINGS_KEY . '_dashboard_stats_snapshot_v2';
        }

        return array_values(array_unique($legacy_transients));
    }

    /**
     * Return the final transient-elimination migration marker.
     *
     * @return string
     */
    private static function get_final_transient_cleanup_state_name()
    {
        return 'ultracache_state:migration.transient_elimination_v1';
    }

    /**
     * Remove every known legacy UltraCache transient and superseded migration
     * marker without importing transient values into authoritative state.
     * Runtime readers and writers are forbidden after this migration.
     *
     * @return void
     */
    public static function maybe_run_final_transient_cleanup()
    {
        if (!function_exists('ultracache_get_state_record_read_only')
            || !function_exists('ultracache_mutate_state_record')) {
            return;
        }

        $state_name = self::get_final_transient_cleanup_state_name();
        $record = ultracache_get_state_record_read_only($state_name);
        $payload = is_array($record['payload'] ?? null) ? $record['payload'] : array();
        if (!empty($payload['completed'])) {
            return;
        }

        $legacy_transients = self::get_legacy_transient_names();

        foreach ($legacy_transients as $legacy_transient) {
            self::delete_legacy_transient($legacy_transient);
        }

        delete_option('ultracache_avif_encoder_self_test_v1');

        if (method_exists(__CLASS__, 'delete_option_rows_by_like_patterns')) {
            self::delete_option_rows_by_like_patterns(array(
                '_transient_ultracache_%',
                '_transient_timeout_ultracache_%',
                '_site_transient_ultracache_%',
                '_site_transient_timeout_ultracache_%',
                'ultracache_google_fonts_lock_%',
                'ultracache_lcp_manual_selector_%',
            ));
        }

        foreach (array(
            'ultracache_state:migration.varnish_last_operation_v1',
            'ultracache_state:migration.varnish_capabilities_v1',
            'ultracache_state:migration.varnish_diagnostics_v1',
            'ultracache_state:migration.warm_coordination_transients_v1',
            'ultracache_state:migration.media_lcp_litespeed_coordination_transients_v1',
            'ultracache_state:migration.runtime_server_capabilities_transients_v1',
            'ultracache_state:migration.media_encoder_capabilities_transients_v1',
            'ultracache_state:migration.dashboard_diagnostics_transients_v1',
            'ultracache_state:migration.font_js_diagnostics_transients_v1',
        ) as $legacy_migration_state) {
            ultracache_delete_state_record($legacy_migration_state);
        }

        ultracache_mutate_state_record(
            $state_name,
            static function () {
                return array(
                    'schemaVersion' => 1,
                    'completed' => true,
                    'completedVersion' => defined('ULTRACACHE_VERSION') ? (string) ULTRACACHE_VERSION : '2.59.12.40',
                    'completedAt' => time(),
                );
            },
            3,
            array()
        );
    }


    private static function get_cache_plugin_registry()
    {
        return array(
            'w3-total-cache' => array('label' => self::maybe_translate('W3 Total Cache'), 'aliases' => array('w3-total-cache'), 'pageCache' => true, 'objectCache' => true, 'markers' => array('W3 Total Cache', 'W3TC', 'w3-total-cache', 'w3tc_')),
            'wp-rocket' => array('label' => self::maybe_translate('WP Rocket'), 'aliases' => array('wp-rocket'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('WP Rocket', 'WP_ROCKET', 'rocket_clean_domain', 'wp-rocket')),
            'wp-super-cache' => array('label' => self::maybe_translate('WP Super Cache'), 'aliases' => array('wp-super-cache'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('WP Super Cache', 'WPCACHEHOME', 'wp-cache-phase1', 'wp-super-cache')),
            'litespeed-cache' => array('label' => self::maybe_translate('LiteSpeed Cache'), 'aliases' => array('litespeed-cache'), 'pageCache' => true, 'objectCache' => true, 'markers' => array('LiteSpeed Cache', 'LSCWP', 'litespeed-cache', 'LiteSpeed_Cache')),
            'sg-cachepress' => array('label' => self::maybe_translate('SiteGround Speed Optimizer'), 'aliases' => array('sg-cachepress'), 'pageCache' => true, 'objectCache' => true, 'markers' => array('SiteGround Optimizer', 'Speed Optimizer', 'SG Optimizer', 'sg-cachepress', 'SiteGround_Optimizer')),
            'wp-fastest-cache' => array('label' => self::maybe_translate('WP Fastest Cache'), 'aliases' => array('wp-fastest-cache'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('WP Fastest Cache', 'WpFastestCache', 'wp-fastest-cache')),
            'breeze' => array('label' => self::maybe_translate('Breeze'), 'aliases' => array('breeze'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('Breeze', 'BREEZE', 'breeze-cache')),
            'cache-enabler' => array('label' => self::maybe_translate('Cache Enabler'), 'aliases' => array('cache-enabler'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('Cache Enabler', 'cache-enabler')),
            'wp-optimize' => array('label' => self::maybe_translate('WP-Optimize'), 'aliases' => array('wp-optimize'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('WP-Optimize', 'WP_Optimize', 'wp-optimize')),
            'hummingbird-performance' => array('label' => self::maybe_translate('Hummingbird'), 'aliases' => array('hummingbird-performance'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('Hummingbird', 'wphb', 'hummingbird-performance')),
            'comet-cache' => array('label' => self::maybe_translate('Comet Cache'), 'aliases' => array('comet-cache'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('Comet Cache', 'COMET_CACHE', 'comet-cache')),
            'powered-cache' => array('label' => self::maybe_translate('Powered Cache'), 'aliases' => array('powered-cache'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('Powered Cache', 'powered-cache')),
            'nitropack' => array('label' => self::maybe_translate('NitroPack'), 'aliases' => array('nitropack', 'nitropackio'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('NitroPack', 'nitropack')),
            'flying-press' => array('label' => self::maybe_translate('FlyingPress'), 'aliases' => array('flying-press', 'flyingpress'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('FlyingPress', 'flying-press', 'flyingpress')),
            'swift-performance-lite' => array('label' => self::maybe_translate('Swift Performance Lite'), 'aliases' => array('swift-performance-lite'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('Swift Performance', 'swift-performance-lite')),
            'swift-performance' => array('label' => self::maybe_translate('Swift Performance'), 'aliases' => array('swift-performance'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('Swift Performance', 'SWIFT_PERFORMANCE', 'swift-performance')),
            'speedycache' => array('label' => self::maybe_translate('SpeedyCache'), 'aliases' => array('speedycache'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('SpeedyCache', 'speedycache')),
            'seraphinite-accelerator' => array('label' => self::maybe_translate('Seraphinite Accelerator'), 'aliases' => array('seraphinite-accelerator'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('Seraphinite Accelerator', 'seraphinite-accelerator')),
            'tenweb-speed-optimizer' => array('label' => self::maybe_translate('10Web Booster'), 'aliases' => array('tenweb-speed-optimizer', '10web-booster'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('10Web Booster', 'TENWEB', 'tenweb-speed-optimizer')),
            'wp-cloudflare-page-cache' => array('label' => self::maybe_translate('Super Page Cache for Cloudflare'), 'aliases' => array('wp-cloudflare-page-cache'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('Super Page Cache', 'WP Cloudflare Super Page Cache', 'wp-cloudflare-page-cache')),
            'cachify' => array('label' => self::maybe_translate('Cachify'), 'aliases' => array('cachify'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('Cachify', 'cachify')),
            'hyper-cache' => array('label' => self::maybe_translate('Hyper Cache'), 'aliases' => array('hyper-cache'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('Hyper Cache', 'hyper-cache')),
            'simple-cache' => array('label' => self::maybe_translate('Simple Cache'), 'aliases' => array('simple-cache'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('Simple Cache', 'simple-cache')),
            'atec-cache-apcu' => array('label' => self::maybe_translate('atec Cache APCu'), 'aliases' => array('atec-cache-apcu'), 'pageCache' => true, 'objectCache' => true, 'markers' => array('atec Cache APCu', 'atec-cache-apcu')),
            'ezcache' => array('label' => self::maybe_translate('ezCache'), 'aliases' => array('ezcache'), 'pageCache' => true, 'objectCache' => true, 'markers' => array('ezCache', 'ezcache')),
            'batcache' => array('label' => self::maybe_translate('Batcache'), 'aliases' => array('batcache'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('Batcache', 'batcache')),
            'vendi-cache' => array('label' => self::maybe_translate('Vendi Cache'), 'aliases' => array('vendi-cache'), 'pageCache' => true, 'objectCache' => false, 'markers' => array('Vendi Cache', 'vendi-cache')),

            'redis-cache' => array('label' => self::maybe_translate('Redis Object Cache'), 'aliases' => array('redis-cache'), 'pageCache' => false, 'objectCache' => true, 'markers' => array('Redis Object Cache', 'Redis_Object_Cache', 'redis-cache', 'Rhubarb\\RedisCache')),
            'object-cache-pro' => array('label' => self::maybe_translate('Object Cache Pro'), 'aliases' => array('object-cache-pro'), 'pageCache' => false, 'objectCache' => true, 'markers' => array('Object Cache Pro', 'objectcache.pro', 'ObjectCachePro')),
            'docket-cache' => array('label' => self::maybe_translate('Docket Cache'), 'aliases' => array('docket-cache'), 'pageCache' => false, 'objectCache' => true, 'markers' => array('Docket Cache', 'DocketCache', 'docket-cache')),
            'sqlite-object-cache' => array('label' => self::maybe_translate('SQLite Object Cache'), 'aliases' => array('sqlite-object-cache'), 'pageCache' => false, 'objectCache' => true, 'markers' => array('SQLite Object Cache', 'sqlite-object-cache')),
            'wp-redis' => array('label' => self::maybe_translate('WP Redis'), 'aliases' => array('wp-redis'), 'pageCache' => false, 'objectCache' => true, 'markers' => array('WP Redis', 'wp-redis')),
            'object-cache-4-everyone' => array('label' => self::maybe_translate('Object Cache 4 everyone'), 'aliases' => array('object-cache-4-everyone'), 'pageCache' => false, 'objectCache' => true, 'markers' => array('Object Cache 4 everyone', 'object-cache-4-everyone')),
            'memcached-redux' => array('label' => self::maybe_translate('Memcached Redux'), 'aliases' => array('memcached-redux'), 'pageCache' => false, 'objectCache' => true, 'markers' => array('Memcached Redux', 'memcached-redux')),
            'memcached' => array('label' => self::maybe_translate('Memcached Object Cache'), 'aliases' => array('memcached'), 'pageCache' => false, 'objectCache' => true, 'markers' => array('Memcached Object Cache', 'Memcached', 'Memcache', 'memcached', 'memcache')),
            'memcached-is-your-friend' => array('label' => self::maybe_translate('MemcacheD Is Your Friend'), 'aliases' => array('memcached-is-your-friend'), 'pageCache' => false, 'objectCache' => true, 'markers' => array('MemcacheD Is Your Friend', 'memcached-is-your-friend')),
            'apcu-object-cache' => array('label' => self::maybe_translate('APCu Object Cache'), 'aliases' => array('apcu-object-cache', 'zapcu'), 'pageCache' => false, 'objectCache' => true, 'markers' => array('APCu Object Cache', 'ZapCu', 'apcu-object-cache')),
            'eacobjectcache' => array('label' => self::maybe_translate('{eac}ObjectCache'), 'aliases' => array('eacobjectcache'), 'pageCache' => false, 'objectCache' => true, 'markers' => array('{eac}ObjectCache', 'eacObjectCache', 'eacobjectcache')),
            'snapcache' => array('label' => self::maybe_translate('SnapCache'), 'aliases' => array('snapcache'), 'pageCache' => false, 'objectCache' => true, 'markers' => array('SnapCache', 'snapcache')),

            // Optimization-only plugins are intentionally omitted from Page/Object hard conflicts.
            // Hosting integrations are recognized for diagnostics/fingerprints but are not treated
            // as user-deactivatable hard conflicts by this registry.
            'kinsta-mu-plugins' => array('label' => self::maybe_translate('Kinsta MU Plugin'), 'aliases' => array('kinsta-mu-plugins', 'kinsta-mu-plugin'), 'pageCache' => false, 'objectCache' => false, 'infrastructureCache' => true, 'markers' => array('Kinsta MU Plugin', 'kinsta-mu-plugins')),
            'wpengine-common' => array('label' => self::maybe_translate('WP Engine MU Plugin'), 'aliases' => array('wpengine-common', 'wpengine'), 'pageCache' => false, 'objectCache' => false, 'infrastructureCache' => true, 'markers' => array('WP Engine', 'wpengine-common')),
            'pressable' => array('label' => self::maybe_translate('Pressable Cache Integration'), 'aliases' => array('pressable', 'pressable-mu-plugin'), 'pageCache' => false, 'objectCache' => false, 'infrastructureCache' => true, 'markers' => array('Pressable', 'Batcache')),
            'flywp' => array('label' => self::maybe_translate('FlyWP Helper'), 'aliases' => array('flywp'), 'pageCache' => false, 'objectCache' => false, 'infrastructureCache' => true, 'markers' => array('FlyWP', 'flywp')),
        );
    }


    private static function get_known_cache_plugin_signatures()
    {
        return self::get_cache_plugin_registry();
    }


    private static function is_ultracache_managed_cache_dropin($basename, $contents)
    {
        $basename = basename((string) $basename);
        $contents = (string) $contents;
        if ('' === $basename || '' === $contents) {
            return false;
        }

        $markers = array(
            'advanced-cache.php' => 'UltraCache advanced-cache drop-in',
            'object-cache.php' => 'UltraCache generated object-cache drop-in',
        );

        return isset($markers[$basename]) && false !== strpos($contents, $markers[$basename]);
    }


    private static function detect_cache_dropin_owner($contents, $basename = '')
    {
        $contents = (string) $contents;
        if ('' === $contents) {
            return 'Unknown';
        }

        if (self::is_ultracache_managed_cache_dropin($basename, $contents)) {
            return 'UltraCache';
        }

        foreach (self::get_cache_plugin_registry() as $signature) {
            $label = (string) ($signature['label'] ?? 'Unknown');
            $markers = isset($signature['markers']) && is_array($signature['markers']) ? $signature['markers'] : array();
            foreach ($markers as $marker) {
                if ('' !== (string) $marker && false !== stripos($contents, (string) $marker)) {
                    return $label;
                }
            }
        }

        return 'Unknown';
    }


    private static function get_cache_dropin_conflict_status()
    {
        $dropins = array();
        $detected = false;
        $active_page_cache = false;
        $active_object_cache = false;
        foreach (self::get_active_cache_implementations() as $active_implementation) {
            $active_page_cache = $active_page_cache || !empty($active_implementation['pageCache']);
            $active_object_cache = $active_object_cache || !empty($active_implementation['objectCache']);
        }

        $targets = array(
            'advanced-cache.php' => self::maybe_translate('Page cache drop-in'),
            'object-cache.php' => self::maybe_translate('Object cache drop-in'),
        );

        foreach ($targets as $basename => $label) {
            $path = ultracache_dropin_path($basename);
            $exists = ultracache_dropin_exists($basename);
            $read = $exists ? ultracache_read_dropin($basename) : false;
            $contents = is_string($read) ? $read : '';
            $managed = $exists && self::is_ultracache_managed_cache_dropin($basename, $contents);
            $owner = $exists ? self::detect_cache_dropin_owner($contents, $basename) : '';
            $is_conflict = $exists && !$managed;
            $active_owner_protected = ('advanced-cache.php' === $basename && $active_page_cache)
                || ('object-cache.php' === $basename && $active_object_cache);

            if ($is_conflict) {
                $detected = true;
            }

            $dropins[] = array(
                'file' => $basename,
                'label' => (string) $label,
                'path' => $path,
                'exists' => (bool) $exists,
                'managed' => (bool) $managed,
                'owner' => $exists ? $owner : '',
                'removable' => (bool) ($is_conflict && !$active_owner_protected),
                'activeOwnerProtected' => (bool) ($is_conflict && $active_owner_protected),
                'size' => $exists ? ultracache_dropin_filesize($basename) : 0,
                'modified' => $exists ? ultracache_dropin_filemtime($basename) : 0,
            );
        }

        return array(
            'detected' => (bool) $detected,
            'dropins' => $dropins,
            'message' => $detected ? self::maybe_translate('Conflicting WordPress cache drop-ins detected.') : '',
        );
    }


    private static function match_cache_plugin_registry_entry($plugin_file)
    {
        $plugin_file = wp_normalize_path((string) $plugin_file);
        if ('' === $plugin_file) {
            return array();
        }

        $trimmed = trim($plugin_file, '/');
        $parts = array_values(array_filter(explode('/', $trimmed), 'strlen'));
        $basename = strtolower((string) pathinfo(basename($trimmed), PATHINFO_FILENAME));
        $parent = count($parts) > 1 ? strtolower((string) $parts[count($parts) - 2]) : '';
        $first = !empty($parts) ? strtolower((string) $parts[0]) : '';
        $identities = array_values(array_unique(array_filter(array($first, $parent, $basename))));

        foreach (self::get_cache_plugin_registry() as $slug => $entry) {
            $aliases = isset($entry['aliases']) && is_array($entry['aliases']) ? $entry['aliases'] : array();
            $aliases[] = $slug;
            $aliases = array_values(array_unique(array_filter(array_map(static function ($value) {
                return strtolower(trim((string) $value));
            }, $aliases))));

            if (array_intersect($identities, $aliases)) {
                $entry['slug'] = (string) $slug;
                return $entry;
            }
        }

        return array();
    }


    private static function get_active_cache_implementations()
    {
        $active = array();
        $site_plugins = get_option('active_plugins', array());
        if (is_array($site_plugins)) {
            foreach ($site_plugins as $plugin_file) {
                $active[] = array('file' => (string) $plugin_file, 'source' => 'site');
            }
        }

        if (is_multisite()) {
            $network_plugins = get_site_option('active_sitewide_plugins', array());
            if (is_array($network_plugins)) {
                foreach (array_keys($network_plugins) as $plugin_file) {
                    $active[] = array('file' => (string) $plugin_file, 'source' => 'network');
                }
            }
        }

        if (!function_exists('get_mu_plugins')) {
            $plugin_api = defined('ABSPATH') ? ABSPATH . 'wp-admin/includes/plugin.php' : '';
            if ('' !== $plugin_api && file_exists($plugin_api)) {
                require_once $plugin_api;
            }
        }
        if (function_exists('get_mu_plugins')) {
            $mu_plugins = get_mu_plugins();
            if (is_array($mu_plugins)) {
                foreach (array_keys($mu_plugins) as $plugin_file) {
                    $active[] = array('file' => (string) $plugin_file, 'source' => 'mu');
                }
            }
        }

        $items = array();
        foreach ($active as $active_plugin) {
            $plugin_file = (string) ($active_plugin['file'] ?? '');
            $entry = self::match_cache_plugin_registry_entry($plugin_file);
            if (empty($entry['slug']) || 'ultracache' === $entry['slug']) {
                continue;
            }

            $slug = (string) $entry['slug'];
            if (isset($items[$slug])) {
                $sources = isset($items[$slug]['sources']) && is_array($items[$slug]['sources']) ? $items[$slug]['sources'] : array();
                $source = (string) ($active_plugin['source'] ?? 'site');
                if ('' !== $source && !in_array($source, $sources, true)) {
                    $sources[] = $source;
                }
                $items[$slug]['sources'] = $sources;
                continue;
            }

            $items[$slug] = array(
                'slug' => $slug,
                'name' => (string) ($entry['label'] ?? $slug),
                'pluginFile' => $plugin_file,
                'sources' => array((string) ($active_plugin['source'] ?? 'site')),
                'pageCache' => !empty($entry['pageCache']),
                'objectCache' => !empty($entry['objectCache']),
                'infrastructureCache' => !empty($entry['infrastructureCache']),
            );
        }

        return array_values($items);
    }


    private static function get_active_cache_plugin_conflict_status()
    {
        $items = array_values(array_filter(self::get_active_cache_implementations(), static function ($item) {
            return !empty($item['pageCache']) || !empty($item['objectCache']);
        }));

        return array(
            'detected' => !empty($items),
            'items' => $items,
            'message' => !empty($items) ? self::maybe_translate('Another active Page Cache or Object Cache implementation was detected.') : '',
        );
    }


    public static function get_cache_conflict_preflight_status($page_cache_requested = true, $object_cache_requested = false)
    {
        $page_cache_requested = (bool) $page_cache_requested;
        $object_cache_requested = (bool) $object_cache_requested;
        $conflicts = array();

        foreach (self::get_active_cache_implementations() as $item) {
            $page_conflict = $page_cache_requested && !empty($item['pageCache']);
            $object_conflict = $object_cache_requested && !empty($item['objectCache']);
            if (!$page_conflict && !$object_conflict) {
                continue;
            }

            $item['pageConflict'] = $page_conflict;
            $item['objectConflict'] = $object_conflict;
            $conflicts[] = $item;
        }

        $parts = array();
        foreach ($conflicts as $item) {
            $capabilities = array();
            if (!empty($item['pageConflict'])) {
                $capabilities[] = self::maybe_translate('Page Cache');
            }
            if (!empty($item['objectConflict'])) {
                $capabilities[] = self::maybe_translate('Object Cache');
            }
            $parts[] = (string) ($item['name'] ?? $item['slug'] ?? 'Unknown') . ' (' . implode(' + ', $capabilities) . ')';
        }

        $message = '';
        if (!empty($parts)) {
            $message = self::maybe_translate('Another active cache implementation conflicts with the UltraCache options selected in the Setup Wizard: ')
                . implode(', ', $parts)
                . '. '
                . self::maybe_translate('Please deactivate the conflicting plugin first, then run the Setup Wizard again.');
            if ($object_cache_requested) {
                $message .= ' ' . self::maybe_translate('If you want to keep an existing Object Cache plugin, choose “Do not manage Object Cache with UltraCache”.');
            }
        }

        return array(
            'success' => true,
            'blocked' => !empty($conflicts),
            'pageCacheRequested' => $page_cache_requested,
            'objectCacheRequested' => $object_cache_requested,
            'conflicts' => $conflicts,
            'message' => $message,
        );
    }


    private static function remove_foreign_cache_dropin_for_takeover($basename)
    {
        $basename = basename((string) $basename);
        if (!in_array($basename, array('advanced-cache.php', 'object-cache.php'), true)) {
            return new WP_Error('ultracache_cache_dropin_invalid', self::maybe_translate('UltraCache received an invalid cache drop-in takeover target.'));
        }
        if (!ultracache_dropin_exists($basename)) {
            return true;
        }

        $read = ultracache_read_dropin($basename);
        $contents = is_string($read) ? $read : '';
        if (self::is_ultracache_managed_cache_dropin($basename, $contents)) {
            return true;
        }

        if (!ultracache_delete_dropin($basename)) {
            return new WP_Error(
                'ultracache_cache_dropin_remove_failed',
                sprintf(
                    /* translators: %s: WordPress cache drop-in file name. */
                    self::maybe_translate('UltraCache could not remove the inactive cache helper %s. Check wp-content permissions and retry.'),
                    $basename
                )
            );
        }

        if (ultracache_dropin_exists($basename)) {
            return new WP_Error(
                'ultracache_cache_dropin_remove_verification_failed',
                sprintf(
                    /* translators: %s: WordPress cache drop-in file name. */
                    self::maybe_translate('UltraCache removed %s but could not verify that the file is gone. Check wp-content permissions and retry.'),
                    $basename
                )
            );
        }

        return true;
    }


    private static function preflight_cache_takeover($page_cache_requested, $object_cache_requested)
    {
        $status = self::get_cache_conflict_preflight_status($page_cache_requested, $object_cache_requested);
        if (!empty($status['blocked'])) {
            return new WP_Error(
                'ultracache_active_cache_plugin_conflict',
                (string) ($status['message'] ?? self::maybe_translate('Another active cache plugin must be deactivated before UltraCache can take over the selected cache layer.')),
                array('conflicts' => $status['conflicts'] ?? array())
            );
        }

        if ($page_cache_requested && method_exists(static::class, 'validate_wp_cache_takeover_preflight')) {
            $wp_cache_preflight = self::validate_wp_cache_takeover_preflight();
            if (is_wp_error($wp_cache_preflight)) {
                return $wp_cache_preflight;
            }
        }

        if ($page_cache_requested) {
            $cleanup = self::remove_foreign_cache_dropin_for_takeover('advanced-cache.php');
            if (is_wp_error($cleanup)) {
                return $cleanup;
            }
        }

        if ($object_cache_requested) {
            $cleanup = self::remove_foreign_cache_dropin_for_takeover('object-cache.php');
            if (is_wp_error($cleanup)) {
                return $cleanup;
            }
        }

        return true;
    }


    private static function get_legacy_cache_conflict_status()
    {
        $option_names = array(
            'purge_varnish_action',
            'purge_varnish_expire',
            'varnish_bantype',
            'varnish_control_key',
            'varnish_control_terminal',
            'varnish_socket_timeout',
            'varnish_version',
            'vhp_varnish_debug',
            'w3x_varnish_cli_secret',
            'w3x_varnish_cli_timeout_ms',
            'w3x_varnish_http_servers',
            'w3tc_state',
        );

        $found_options = array();
        foreach ($option_names as $option_name) {
            if (false !== get_option($option_name, false)) {
                $found_options[] = $option_name;
            }
        }

        $found_plugins = array();
        foreach (array('w3-total-cache', 'w3tc-varnish-cli-helper') as $plugin_dir) {
            if (function_exists('ultracache_plugin_main_file') && '' !== ultracache_plugin_main_file($plugin_dir)) {
                $found_plugins[] = $plugin_dir;
            }
        }

        $dropin_conflicts = self::get_cache_dropin_conflict_status();
        $active_cache_plugins = self::get_active_cache_plugin_conflict_status();

        /*
         * Disabled/installed cache plugins and legacy options are advisory diagnostics only.
         * They must not create dashboard warnings unless an active cache plugin is detected
         * or a non-UltraCache WordPress drop-in is actually present/removable.
         */
        $detected = !empty($dropin_conflicts['detected']) || !empty($active_cache_plugins['detected']);

        return array(
            'detected' => (bool) $detected,
            'options'  => $found_options,
            'plugins'  => $found_plugins,
            'dropins'  => isset($dropin_conflicts['dropins']) && is_array($dropin_conflicts['dropins']) ? $dropin_conflicts['dropins'] : array(),
            'dropinConflictsDetected' => !empty($dropin_conflicts['detected']),
            'activeCachePlugins' => isset($active_cache_plugins['items']) && is_array($active_cache_plugins['items']) ? $active_cache_plugins['items'] : array(),
            'activeCachePluginsDetected' => !empty($active_cache_plugins['detected']),
            'message'  => $detected ? self::maybe_translate('Cache helper or active cache plugin conflicts detected. Review the details before enabling UltraCache Varnish or Object Cache.') : '',
        );
    }



    public static function cleanup_legacy_dropin_backup_directory()
    {
        if (!current_user_can('manage_options') || !defined('ULTRACACHE_CACHE_DIR') || !function_exists('ultracache_safe_rmdir')) {
            return false;
        }

        $backup_root = trailingslashit(ULTRACACHE_CACHE_DIR) . 'backups/';
        $backup_dir = trailingslashit($backup_root) . 'dropins/';
        if (!is_dir($backup_dir)) {
            return true;
        }

        $removed = ultracache_safe_rmdir($backup_dir, 'cleanup legacy drop-in backups');

        if ($removed && function_exists('ultracache_safe_rmdir_empty')) {
            ultracache_safe_rmdir_empty($backup_root, 'cleanup empty legacy drop-in backup root');
        }

        return (bool) $removed;
    }



    public static function remove_conflicting_cache_dropins()
    {
        if (!current_user_can('manage_options') || !current_user_can('activate_plugins')) {
            return array(
                'success' => false,
                'message' => self::maybe_translate('Removing conflicting cache drop-ins requires manage_options and activate_plugins permissions.'),
                'removed' => array(),
                'failed' => array(),
            );
        }

        if (!ultracache_get_wp_filesystem() || '' === ultracache_wordpress_content_dir()) {
            return array(
                'success' => false,
                'message' => self::maybe_translate('The WordPress filesystem is unavailable for cache drop-in management.'),
                'removed' => array(),
                'failed' => array(),
            );
        }

        self::cleanup_legacy_dropin_backup_directory();

        $status = self::get_cache_dropin_conflict_status();
        $dropins = isset($status['dropins']) && is_array($status['dropins']) ? $status['dropins'] : array();
        $removed = array();
        $failed = array();

        foreach ($dropins as $dropin) {
            if (empty($dropin['removable']) || empty($dropin['file'])) {
                continue;
            }

            $basename = basename((string) $dropin['file']);
            if (!in_array($basename, array('advanced-cache.php', 'object-cache.php'), true)) {
                continue;
            }

            if (!ultracache_dropin_exists($basename)) {
                continue;
            }

            $active_conflict = self::get_cache_conflict_preflight_status(
                'advanced-cache.php' === $basename,
                'object-cache.php' === $basename
            );
            if (!empty($active_conflict['blocked'])) {
                $failed[] = array(
                    'file' => $basename,
                    'owner' => 'Active plugin',
                    'message' => (string) ($active_conflict['message'] ?? self::maybe_translate('An active cache plugin still owns this cache layer.')),
                );
                continue;
            }

            $read = ultracache_read_dropin($basename);
            $contents = is_string($read) ? $read : '';
            if (self::is_ultracache_managed_cache_dropin($basename, $contents)) {
                $failed[] = array(
                    'file' => $basename,
                    'owner' => 'UltraCache',
                    'message' => self::maybe_translate('Skipped UltraCache-managed drop-in.'),
                );
                continue;
            }

            $owner = self::detect_cache_dropin_owner($contents, $basename);
            if (!ultracache_delete_dropin($basename)) {
                $failed[] = array(
                    'file' => $basename,
                    'owner' => $owner,
                    'message' => self::maybe_translate('Could not remove drop-in.'),
                );
                continue;
            }

            $removed[] = array(
                'file' => $basename,
                'owner' => $owner,
            );
        }

        $success = empty($failed);
        if (empty($removed) && empty($failed)) {
            $message = self::maybe_translate('No conflicting cache helpers were found.');
        } elseif ($success) {
            $message = self::maybe_translate_sprintf('Removed %d conflicting cache helper(s).', count($removed));
        } else {
            $message = self::maybe_translate_sprintf('Removed %d cache helper(s); %d failed.', count($removed), count($failed));
        }

        return array(
            'success' => (bool) $success,
            'message' => $message,
            'removed' => $removed,
            'failed' => $failed,
            'diagnostics' => self::get_dashboard_diagnostics(),
            'stats' => self::get_engine_stats(),
        );
    }


}
