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


    private static function get_known_cache_plugin_signatures()
    {
        return array(
            'w3-total-cache' => array('label' => self::maybe_translate('W3 Total Cache'), 'markers' => array('W3 Total Cache', 'W3TC', 'w3-total-cache', 'w3tc_')),
            'wp-rocket' => array('label' => self::maybe_translate('WP Rocket'), 'markers' => array('WP Rocket', 'WP_ROCKET', 'rocket_clean_domain', 'wp-rocket')),
            'wp-super-cache' => array('label' => self::maybe_translate('WP Super Cache'), 'markers' => array('WP Super Cache', 'WPCACHEHOME', 'wp-cache-phase1', 'wp-super-cache')),
            'litespeed-cache' => array('label' => self::maybe_translate('LiteSpeed Cache'), 'markers' => array('LiteSpeed Cache', 'LSCWP', 'litespeed-cache', 'LiteSpeed_Cache')),
            'sg-cachepress' => array('label' => self::maybe_translate('SiteGround Optimizer'), 'markers' => array('SiteGround Optimizer', 'SG Optimizer', 'sg-cachepress', 'SiteGround_Optimizer')),
            'wp-fastest-cache' => array('label' => self::maybe_translate('WP Fastest Cache'), 'markers' => array('WP Fastest Cache', 'WpFastestCache', 'wp-fastest-cache')),
            'breeze' => array('label' => self::maybe_translate('Breeze'), 'markers' => array('Breeze', 'BREEZE', 'breeze-cache')),
            'redis-cache' => array('label' => self::maybe_translate('Redis Object Cache'), 'markers' => array('Redis Object Cache', 'Redis_Object_Cache', 'redis-cache', 'Rhubarb\\RedisCache')),
            'docket-cache' => array('label' => self::maybe_translate('Docket Cache'), 'markers' => array('Docket Cache', 'DocketCache', 'docket-cache')),
            'object-cache-pro' => array('label' => self::maybe_translate('Object Cache Pro'), 'markers' => array('Object Cache Pro', 'objectcache.pro', 'ObjectCachePro')),
            'memcached' => array('label' => self::maybe_translate('Memcached Object Cache'), 'markers' => array('Memcached', 'Memcache', 'memcached', 'memcache')),
            'powered-cache' => array('label' => self::maybe_translate('Powered Cache'), 'markers' => array('Powered Cache', 'powered-cache')),
            'cache-enabler' => array('label' => self::maybe_translate('Cache Enabler'), 'markers' => array('Cache Enabler', 'cache-enabler')),
            'autoptimize' => array('label' => self::maybe_translate('Autoptimize'), 'markers' => array('Autoptimize', 'autoptimize')),
        );
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

        foreach (self::get_known_cache_plugin_signatures() as $signature) {
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
                'removable' => (bool) $is_conflict,
                'size' => $exists ? ultracache_dropin_filesize($basename) : 0,
                'modified' => $exists ? ultracache_dropin_filemtime($basename) : 0,
            );
        }

        return array(
            'detected' => (bool) $detected,
            'dropins' => $dropins,
            'message' => $detected ? self::maybe_translate('Conflicting WordPress cache drop-ins detected. UltraCache can remove them if you choose.') : '',
        );
    }



    private static function get_active_cache_plugin_conflict_status()
    {
        $known = array(
            'w3-total-cache' => 'W3 Total Cache',
            'wp-rocket' => 'WP Rocket',
            'wp-super-cache' => 'WP Super Cache',
            'litespeed-cache' => 'LiteSpeed Cache',
            'sg-cachepress' => 'SiteGround Optimizer',
            'wp-fastest-cache' => 'WP Fastest Cache',
            'breeze' => 'Breeze',
            'redis-cache' => 'Redis Object Cache',
            'docket-cache' => 'Docket Cache',
            'object-cache-pro' => 'Object Cache Pro',
            'memcached' => 'Memcached Object Cache',
            'powered-cache' => 'Powered Cache',
            'cache-enabler' => 'Cache Enabler',
            'comet-cache' => 'Comet Cache',
            'hummingbird-performance' => 'Hummingbird',
            'nitropack' => 'NitroPack',
            'autoptimize' => 'Autoptimize',
            'wp-optimize' => 'WP-Optimize',
        );

        $active = array();
        $site_plugins = get_option('active_plugins', array());
        if (is_array($site_plugins)) {
            $active = array_merge($active, $site_plugins);
        }

        if (is_multisite()) {
            $network_plugins = get_site_option('active_sitewide_plugins', array());
            if (is_array($network_plugins)) {
                $active = array_merge($active, array_keys($network_plugins));
            }
        }

        $items = array();
        foreach (array_unique(array_filter(array_map('strval', $active))) as $plugin_file) {
            $slug = strtolower(trim(strtok($plugin_file, '/')));
            if ('' === $slug || 'ultracache' === $slug || !isset($known[$slug])) {
                continue;
            }

            $items[] = array(
                'slug' => $slug,
                'name' => $known[$slug],
                'pluginFile' => $plugin_file,
            );
        }

        return array(
            'detected' => !empty($items),
            'items' => array_values($items),
            'message' => !empty($items) ? self::maybe_translate('Potential cache plugin conflict detected. Running multiple cache/performance plugins together can cause stale pages, purge loops, or object cache conflicts.') : '',
        );
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
