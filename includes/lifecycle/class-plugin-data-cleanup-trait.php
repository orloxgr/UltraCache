<?php
/**
 * Plugin deactivation and owned-data cleanup lifecycle handling.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Plugin_Data_Cleanup_Trait
{
    public static function deactivate()
    {
        if (defined('ULTRACACHE_UNINSTALL_IN_PROGRESS') && ULTRACACHE_UNINSTALL_IN_PROGRESS) {
            return;
        }

        self::reset_manual_warmup_session('deactivate');
        self::sync_page_cache_bootstrap(false);
        self::unschedule_scheduled_events();
        self::unschedule_cron_warm_events(true);
        wp_clear_scheduled_hook('ultracache_process_media_conversion_queue');
        wp_clear_scheduled_hook('ultracache_flush_affected_url_batch');
        delete_option('ultracache_affected_url_batch_v1');
        self::sync_browser_cache_rules(false);
        self::sync_apache_static_html_delivery_rules(false);
        self::sync_litespeed_cache_rules(false);

        if (class_exists('Ultra_Cache_Object_Cache_Manager')) {
            if (method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache')) {
                Ultra_Cache_Object_Cache_Manager::flush_cache(true, true);
            }
            if (method_exists('Ultra_Cache_Object_Cache_Manager', 'maybe_remove_dropin')) {
                Ultra_Cache_Object_Cache_Manager::maybe_remove_dropin();
            }
        }
    }

    // Settings methods live in includes/settings/class-settings-trait.php.


private static function get_uninstall_cleanup_policy($policy = null)
{
if (null !== $policy && '' !== trim((string) $policy)) {
    return self::sanitize_uninstall_cleanup_policy($policy);
}

$settings = get_option(ULTRACACHE_SETTINGS_KEY, array());
if (is_array($settings) && isset($settings['uninstallCleanupPolicy'])) {
    return self::sanitize_uninstall_cleanup_policy($settings['uninstallCleanupPolicy']);
}

return 'delete_everything';
}

private static function drop_plugin_custom_tables()
{
global $wpdb;

if (!($wpdb instanceof wpdb)) {
    return;
}

$tables = array(
    $wpdb->prefix . 'ultracache_media_queue',
    $wpdb->prefix . 'ultracache_media_queue_units',
    $wpdb->prefix . 'ultracache_media_page_refs',
    $wpdb->prefix . 'ultracache_media_replacement_items',
    $wpdb->prefix . 'ultracache_media_replacement_attachment_plans',
    $wpdb->prefix . 'ultracache_media_replacement_refs',
    $wpdb->prefix . 'ultracache_media_replacement_ref_index',
    $wpdb->prefix . 'ultracache_media_replacement_file_refs',
    $wpdb->prefix . 'ultracache_media_replacement_theme_css_files',
    $wpdb->prefix . 'ultracache_action_jobs',
    $wpdb->prefix . 'ultracache_js_diagnostic_jobs',
    $wpdb->prefix . 'ultracache_cron_warm_queue',
    $wpdb->prefix . 'ultracache_analytics',
    $wpdb->prefix . 'ultracache_lcp_observations',
    $wpdb->prefix . 'ultracache_cache_asset_refs',
    $wpdb->prefix . 'ultracache_css_rewrite_map',
    $wpdb->prefix . 'ultracache_locks',
);

foreach ($tables as $table) {
    if (preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit UltraCache cleanup drops only UltraCache-owned custom tables.
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
    }
}
}


private static function delete_option_rows_by_like_patterns(array $patterns)
{
global $wpdb;

if (!($wpdb instanceof wpdb)) {
    return;
}

foreach ($patterns as $pattern) {
    $pattern = (string) $pattern;
    if ('' === $pattern) {
        continue;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit Delete All cleanup removes only UltraCache-owned option/transient prefixes.
    $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $pattern));
}

if (is_multisite()) {
    foreach ($patterns as $pattern) {
        $pattern = (string) $pattern;
        if ('' === $pattern) {
            continue;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit Delete All cleanup removes only UltraCache-owned site option/transient prefixes.
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->sitemeta, $pattern));
    }
}
}

private static function delete_generated_runtime_asset_directories()
{
if (!function_exists('ultracache_generated_asset_dir') || !function_exists('ultracache_safe_rmdir')) {
    return;
}

// Persistent optimized media under uploads/ultracache/images is intentionally kept.
foreach (array('css-bundles', 'font-css', 'google-fonts', 'optimized-css', 'deferred-inline-js') as $bucket) {
    $dir = ultracache_generated_asset_dir($bucket);
    if (is_string($dir) && '' !== $dir && is_dir($dir)) {
        ultracache_safe_rmdir($dir, 'delete_all_plugin_data generated runtime asset dir');
    }
}

if (function_exists('ultracache_uploads_storage_dir')) {
    foreach (array('theme-css-temp', 'theme-css-backups', 'varnishtest', 'js-bundles') as $private_bucket) {
        $dir = ultracache_uploads_storage_dir('ultracache/' . $private_bucket);
        if (is_string($dir) && '' !== $dir && is_dir($dir)) {
            ultracache_safe_rmdir($dir, 'delete_all_plugin_data private runtime asset dir');
        }
    }
}
}

private static function delete_plugin_options_and_transients($keep_settings = false, $keep_tables = false)
{
global $wpdb;

$keep_settings = (bool) $keep_settings;
$keep_tables = (bool) $keep_tables;

$option_names = array(
    ULTRACACHE_CRON_WARM_STATE_KEY,
    ULTRACACHE_CRON_WARM_LOCK_KEY . '_atomic',
    defined('ULTRACACHE_CRAWL_SCOPE_SUMMARY_KEY') ? ULTRACACHE_CRAWL_SCOPE_SUMMARY_KEY : 'ultracache_crawl_scope_summary',
    ULTRACACHE_WP_CACHE_MANAGED_KEY,
    'ultracache_media_diagnostics_v1',
    'ultracache_setup_wizard_state_v1',
    'ultracache_media_library_conversion_test_v1',
    'ultracache_media_library_conversion_test_sample_v1',
    'ultracache_media_replacement_workflow_state_v1',
    'ultracache_avif_encoder_self_test_v1',
    'ultracache_object_cache_last_flush_report',
    'ultracache_last_css_bundle_summary',
    'ultracache_settings_google_fonts_last_scan',
    'ultracache_opcache_last_flush_at',
    'ultracache_external_cache_detection',
    'ultracache_apache_static_capability_v1',
    'ultracache_action_queue_heavy_lock_v1',
    'ultracache_warmup_generation',
    'ultracache_warm_runtime_reset_version',
    'ultracache_warm_runtime_reset_state',
    'ultracache_runtime_js_scan_last_javascript_strategy',
    'ultracache_runtime_js_scan_javascript_strategy_dirty',
    'ultracache_runtime_js_scan_owned_safeguards_v1',
    'ultracache_locks_schema_install_lock',
    'ultracache_affected_url_batch_v1',
    'ultracache_lcp_last_refresh',
    'ultracache_varnish_refresh_ahead_state_v1',
    'ultracache_varnish_refresh_candidates_v1',
    'ultracache_varnish_metrics_v1',
    'ultracache_litespeed_detection_v1',
    'ultracache_litespeed_esi_capability_v1',
    'ultracache_litespeed_rules_state_v1',
    'ultracache_woocommerce_endpoint_contract_v1',
    'ultracache_wpml_topology_v1',
    'ultracache_multilingual_topology_v1',
    'ultracache_litespeed_metrics_v1',
    'ultracache_litespeed_diagnostic_behavior_v1',
    'ultracache_litespeed_refresh_candidates_v1',
    'ultracache_litespeed_refresh_ahead_state_v1',
            'ultracache_litespeed_automation_contract',
    'ultracache_litespeed_semantic_rules_contract',
    'ultracache_varnish_diagnostic_basic_v1',
    'ultracache_varnish_endpoint_capabilities_v1',
    'ultracache_varnish_esi_capability_v1',
    'ultracache_varnish_html_variant_capability_v1',
    'ultracache_varnish_diagnostic_flush_scope_v1',
    'ultracache_varnish_diagnostic_validators_v1',
    'ultracache_varnish_diagnostic_accept_vcl_v1',
    'ultracache_varnish_diagnostic_soft_purge_v1',
    'ultracache_varnish_diagnostic_multi_endpoint_v1',
    'ultracache_varnish_html_ttl_default_migration_v1',
    ULTRACACHE_MANUAL_WARM_STATE_KEY,
);

if (!$keep_settings) {
    array_unshift($option_names, ULTRACACHE_SETTINGS_KEY);
}

if (!$keep_tables) {
    $option_names[] = 'ultracache_media_queue_db_version';
    $option_names[] = 'ultracache_media_queue_units_db_version';
    $option_names[] = 'ultracache_media_queue_units_migration_state_v1';
    $option_names[] = 'ultracache_media_page_refs_db_version';
    $option_names[] = 'ultracache_media_replacement_db_version';
    $option_names[] = 'ultracache_media_replacement_schema_lock_v1';
    $option_names[] = 'ultracache_action_jobs_db_version';
    $option_names[] = 'ultracache_js_diagnostic_queue_db_version';
    $option_names[] = 'ultracache_cron_warm_queue_db_version';
    $option_names[] = 'ultracache_analytics_db_version';
    $option_names[] = 'ultracache_lcp_observations_db_version';
    $option_names[] = self::get_cache_asset_refs_db_version_option_key();
    $option_names[] = self::get_css_rewrite_map_db_version_option_key();
    $option_names[] = ultracache_get_locks_db_version_option_key();
    $option_names[] = self::get_schema_lifecycle_contract_option_key();
    $option_names[] = 'ultracache_media_queue_build_state_v1';
}

foreach ($option_names as $option_name) {
    delete_option($option_name);
    delete_site_option($option_name);
}

foreach (self::get_legacy_transient_names() as $legacy_transient) {
    self::delete_legacy_transient($legacy_transient);
}

if (function_exists('ultracache_delete_coordination_records_by_prefix')) {
    ultracache_delete_coordination_records_by_prefix('state', 'ultracache_state:admin.notice.');
}

self::delete_option_rows_by_like_patterns(array(
    '_transient_ultracache_%',
    '_transient_timeout_ultracache_%',
    '_site_transient_ultracache_%',
    '_site_transient_timeout_ultracache_%',
    'ultracache_google_fonts_lock_%',
    'ultracache_lcp_manual_selector_%',
));

if (!$keep_tables) {
    self::drop_plugin_custom_tables();
}
}

public static function delete_all_plugin_data_and_deactivate($cleanup_policy = null)
{
if (!current_user_can('manage_options') || !current_user_can('activate_plugins')) {
    return new WP_Error('ultracache_forbidden', __('Deleting UltraCache data and deactivating the plugin requires manage_options and activate_plugins permissions.', 'ultracache'));
}

if (!defined('ULTRACACHE_UNINSTALL_IN_PROGRESS')) {
    define('ULTRACACHE_UNINSTALL_IN_PROGRESS', true);
}

$cleanup_policy = self::get_uninstall_cleanup_policy($cleanup_policy);
$keep_settings = in_array($cleanup_policy, array('plugin_only', 'keep_settings', 'keep_settings_tables'), true);
$keep_tables = in_array($cleanup_policy, array('plugin_only', 'keep_settings_tables'), true);
$delete_cache_files = ('plugin_only' !== $cleanup_policy);
$delete_options = ('plugin_only' !== $cleanup_policy);

self::reset_manual_warmup_session('delete-all-data');
self::stop_cron_warmup_queue('delete-all-data');
self::unschedule_cache_cleanup();
self::unschedule_cron_warm_events(true);
wp_clear_scheduled_hook('ultracache_scheduled_cache_cleanup');
wp_clear_scheduled_hook('ultracache_cron_warm_tick');
wp_clear_scheduled_hook('ultracache_cron_warm_tick_kickoff');
wp_clear_scheduled_hook('ultracache_process_media_conversion_queue');

if (class_exists('Ultra_Cache_Engine') && method_exists('Ultra_Cache_Engine', 'maybe_remove_advanced_cache')) {
    Ultra_Cache_Engine::maybe_remove_advanced_cache();
}

if (class_exists('Ultra_Cache_Object_Cache_Manager')) {
    if (method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache')) {
        Ultra_Cache_Object_Cache_Manager::flush_cache(true, true);
    }
    if (method_exists('Ultra_Cache_Object_Cache_Manager', 'maybe_remove_dropin')) {
        Ultra_Cache_Object_Cache_Manager::maybe_remove_dropin();
    }
}

self::sync_browser_cache_rules(false);
self::sync_apache_static_html_delivery_rules(false);
self::sync_litespeed_cache_rules(false);
self::set_wp_cache_flag(false);

if ($delete_cache_files) {
    if (defined('ULTRACACHE_CACHE_DIR') && is_dir(ULTRACACHE_CACHE_DIR)) {
        ultracache_safe_rmdir(ULTRACACHE_CACHE_DIR, 'delete_all_plugin_data cache dir');
    }
    if (defined('ULTRACACHE_OBJECT_CACHE_DIR') && is_dir(ULTRACACHE_OBJECT_CACHE_DIR)) {
        ultracache_safe_rmdir(ULTRACACHE_OBJECT_CACHE_DIR, 'delete_all_plugin_data object cache dir');
    }
    self::delete_generated_runtime_asset_directories();
}

// Keep converted media files by design. ULTRACACHE_AVIF_DIR and ULTRACACHE_WEBP_DIR
// are intentionally not removed here.
if ($delete_options) {
    self::delete_plugin_options_and_transients($keep_settings, $keep_tables);
}
self::reset_settings_cache();

if (!function_exists('deactivate_plugins')) {
    ultracache_require_wordpress_admin_include('plugin.php', 'deactivate_plugins');
}

if (function_exists('deactivate_plugins')) {
    deactivate_plugins(ULTRACACHE_BASENAME, false, is_multisite());
}

$messages = array(
    'plugin_only' => 'UltraCache was deactivated. Settings, custom tables, cache files, and converted media were kept.',
    'keep_settings' => 'UltraCache was deactivated. Settings were kept; runtime/cache files and custom tables were removed. Converted media folders were not deleted.',
    'keep_settings_tables' => 'UltraCache was deactivated. Settings and custom tables were kept; runtime/cache files were removed. Converted media folders were not deleted.',
    'delete_everything' => 'UltraCache data was deleted and the plugin was deactivated. Converted media folders were not deleted.',
);

return array(
    'success' => true,
    'cleanupPolicy' => $cleanup_policy,
    'message' => isset($messages[$cleanup_policy]) ? $messages[$cleanup_policy] : $messages['delete_everything'],
    'settingsKept' => $keep_settings,
    'tablesKept' => $keep_tables,
    'cacheFilesKept' => !$delete_cache_files,
    'mediaFoldersKept' => array(
        'avif' => defined('ULTRACACHE_AVIF_DIR') ? ULTRACACHE_AVIF_DIR : '',
        'webp' => defined('ULTRACACHE_WEBP_DIR') ? ULTRACACHE_WEBP_DIR : '',
    ),
);
}

    /**
     * Return the schema-lifecycle contract version.
     *
     * Bump this only when UltraCache-owned schema lifecycle expectations change.
     * Normal plugin releases do not need to re-verify every custom table.
     *
     * @return string
     */
    private static function get_schema_lifecycle_contract_version()
    {
        return '1';
    }

    /**
     * Return the option key storing the completed schema-lifecycle contract.
     *
     * @return string
     */
    private static function get_schema_lifecycle_contract_option_key()
    {
        return 'ultracache_schema_lifecycle_contract_version';
    }

    /**
     * Return the shared lock serializing UltraCache schema lifecycle work.
     *
     * @return string
     */
    private static function get_schema_lifecycle_upgrade_lock_name()
    {
        return 'ultracache_schema_upgrade_v1';
    }

    /**
     * Return whether the current schema-lifecycle contract has completed.
     *
     * @return bool
     */
    private static function schema_lifecycle_contract_is_current()
    {
        return self::get_schema_lifecycle_contract_version()
            === (string) get_option(self::get_schema_lifecycle_contract_option_key(), '');
    }

    /**
     * Run the one-time schema lifecycle after a schema-contract upgrade.
     *
     * This hook is intentionally cheap after completion: one option read and
     * return. Heavy SHOW/dbDelta/ALTER work is serialized behind the shared
     * schema-upgrade lock and never runs concurrently across requests.
     *
     * @return array<string,mixed>
     */
    public static function maybe_run_schema_lifecycle_upgrade()
    {
        if (self::schema_lifecycle_contract_is_current()) {
            return array('success' => true, 'skipped' => true, 'reason' => 'schema_contract_current');
        }

        return self::run_schema_lifecycle_upgrade('plugin_upgrade', false);
    }

    /**
     * Explicitly verify/repair the centralized UltraCache schema lifecycle.
     *
     * @param string $context Invocation context.
     * @return array<string,mixed>
     */
    public static function repair_schema_lifecycle($context = 'repair')
    {
        return self::run_schema_lifecycle_upgrade($context, true);
    }

    /**
     * Serialize schema verification/migration for runtime-critical UltraCache
     * tables and the existing activation-owned component schemas.
     *
     * The lock table bootstraps itself through its narrow option lock. Once it
     * is ready, every later custom-table ensure in this lifecycle runs under a
     * single token-owned DB lock. Only the lock owner may execute schema DDL.
     *
     * @param string $context Invocation context.
     * @param bool   $force   Force structural verification even when the
     *                        lifecycle contract is already current.
     * @return array<string,mixed>
     */
    private static function run_schema_lifecycle_upgrade($context, $force = false)
    {
        $context = sanitize_key((string) $context);
        $force = (bool) $force;

        if (defined('ULTRACACHE_UNINSTALL_IN_PROGRESS') && ULTRACACHE_UNINSTALL_IN_PROGRESS) {
            return array('success' => false, 'reason' => 'uninstall_in_progress');
        }

        if (!$force && self::schema_lifecycle_contract_is_current()) {
            return array('success' => true, 'skipped' => true, 'reason' => 'schema_contract_current');
        }

        if (!function_exists('ultracache_ensure_locks_table') || !ultracache_ensure_locks_table($force)) {
            return array('success' => false, 'reason' => 'schema_lock_storage_unavailable');
        }

        if (!function_exists('ultracache_acquire_lock') || !function_exists('ultracache_release_lock')) {
            return array('success' => false, 'reason' => 'schema_lock_api_unavailable');
        }

        $token = 'schema-upgrade-' . gmdate('YmdHis') . '-' . wp_generate_password(20, false, false);
        $lock_name = self::get_schema_lifecycle_upgrade_lock_name();
        $lock_ttl = max(60, min(600, (int) apply_filters('ultracache_schema_upgrade_lock_ttl', 180)));
        $payload = array(
            'context' => $context,
            'contractVersion' => self::get_schema_lifecycle_contract_version(),
            'pluginVersion' => defined('ULTRACACHE_VERSION') ? (string) ULTRACACHE_VERSION : '',
        );

        if (!ultracache_acquire_lock($lock_name, $token, $lock_ttl, $payload)) {
            // During a one-time plugin/schema upgrade, let the sole owner finish
            // before UltraCache's later bootstrap paths can observe a half-migrated
            // schema. This wait never runs after the contract marker is current.
            if (!$force) {
                $wait_attempts = max(1, min(100, (int) apply_filters('ultracache_schema_upgrade_wait_attempts', 40)));
                $wait_micros = max(10000, min(250000, (int) apply_filters('ultracache_schema_upgrade_wait_micros', 100000)));
                for ($attempt = 0; $attempt < $wait_attempts; $attempt++) {
                    usleep($wait_micros);
                    if (self::schema_lifecycle_contract_is_current()) {
                        return array('success' => true, 'skipped' => true, 'reason' => 'schema_contract_completed_by_peer');
                    }
                }
            }
            return array('success' => false, 'reason' => 'schema_upgrade_locked');
        }

        try {
            // Another request may have completed the lifecycle while this
            // request was acquiring the shared lock.
            if (!$force && self::schema_lifecycle_contract_is_current()) {
                return array('success' => true, 'skipped' => true, 'reason' => 'schema_contract_completed_by_peer');
            }

            $results = array();
            $critical_ready = true;

            $media = self::get_media_instance();
            if ($media && method_exists($media, 'ensure_media_queue_table')) {
                $results['mediaQueue'] = (bool) $media->ensure_media_queue_table(true);
                $critical_ready = $critical_ready && $results['mediaQueue'];
            } else {
                $results['mediaQueue'] = false;
                $critical_ready = false;
            }

            if ($media && method_exists($media, 'ensure_media_page_refs_table')) {
                $results['mediaPageRefs'] = (bool) $media->ensure_media_page_refs_table(true);
                $critical_ready = $critical_ready && $results['mediaPageRefs'];
            } else {
                $results['mediaPageRefs'] = false;
                $critical_ready = false;
            }

            $results['cacheAssetRefs'] = (bool) self::ensure_cache_asset_refs_table(true);
            $critical_ready = $critical_ready && $results['cacheAssetRefs'];

            $results['cssRewriteMap'] = (bool) self::ensure_css_rewrite_map_table(true);
            $critical_ready = $critical_ready && $results['cssRewriteMap'];

            // These component schemas were already activation-owned. Keep them
            // inside the same global lifecycle lock so their dbDelta work cannot
            // overlap the runtime-critical schema upgrades above.
            $instance = self::instance();
            if ($instance && method_exists($instance, 'load_rest_api_dependency')) {
                $instance->load_rest_api_dependency();
            }
            if (class_exists('Ultra_Cache_Rest_API') && method_exists('Ultra_Cache_Rest_API', 'get_instance')) {
                $rest_api = Ultra_Cache_Rest_API::get_instance();
                if ($rest_api && method_exists($rest_api, 'ensure_action_jobs_table')) {
                    $results['actionJobs'] = (bool) $rest_api->ensure_action_jobs_table(true);
                }
            }

            $results['cronWarmQueue'] = (bool) self::ensure_cron_warm_queue_table(true);

            if (class_exists('Ultra_Cache_Engine') && method_exists('Ultra_Cache_Engine', 'ensure_analytics_table')) {
                $results['analytics'] = (bool) Ultra_Cache_Engine::ensure_analytics_table(true);
            }
            if (class_exists('Ultra_Cache_Engine') && method_exists('Ultra_Cache_Engine', 'ensure_lcp_observations_table')) {
                $results['lcpObservations'] = (bool) Ultra_Cache_Engine::ensure_lcp_observations_table(true);
            }

            if (!$critical_ready) {
                return array(
                    'success' => false,
                    'reason' => 'critical_schema_incomplete',
                    'results' => $results,
                );
            }

            // Tiny lifecycle marker is autoloaded intentionally: the normal
            // plugins_loaded fast path must not add an individual option query to
            // every frontend request after schema initialization has completed.
            update_option(
                self::get_schema_lifecycle_contract_option_key(),
                self::get_schema_lifecycle_contract_version(),
                true
            );

            return array(
                'success' => true,
                'skipped' => false,
                'results' => $results,
            );
        } finally {
            ultracache_release_lock($lock_name, $token);
        }
    }

    public static function activate()
    {
        self::maybe_run_public_warm_runtime_upgrade_reset('activation');
        self::ensure_directories();
        self::cleanup_legacy_dropin_backup_directory();

        // Do not create a full settings row on first install. Missing boolean
        // switches are treated as off at runtime; non-boolean settings use safe
        // runtime fallbacks until the user explicitly saves settings or applies Optimal Settings.
        self::reset_settings_cache();

        self::sync_page_cache_bootstrap();
        self::sync_scheduled_events();
        // Centralized schema lifecycle: the lock table bootstraps first, then
        // all activation-owned custom-table verification runs under one shared
        // schema-upgrade lock.
        self::run_schema_lifecycle_upgrade('activation', true);
        $browser_cache_sync = self::sync_browser_cache_rules();
        if (false === $browser_cache_sync) {
            self::persist_admin_notice('browser_cache_rules_sync_failed', 'warning', 90);
        }
        $apache_static_sync = self::sync_apache_static_html_delivery_rules();
        if (false === $apache_static_sync) {
            self::persist_admin_notice('apache_static_rules_sync_failed', 'warning', 90);
        }
        if (method_exists(__CLASS__, 'refresh_litespeed_esi_capability_from_persisted_detection')) {
            self::refresh_litespeed_esi_capability_from_persisted_detection('ultracache-activation');
        }
        $litespeed_sync = self::sync_litespeed_cache_rules();
        if (false === $litespeed_sync) {
            self::persist_admin_notice('litespeed_cache_rules_sync_failed', 'warning', 90);
        }

        if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'sync_dropin')) {
            Ultra_Cache_Object_Cache_Manager::sync_dropin();
            if (method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache')) {
                Ultra_Cache_Object_Cache_Manager::flush_cache(true, true);
            }
        }
    }

}
