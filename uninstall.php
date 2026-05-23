<?php
/**
 * UltraCache uninstall cleanup.
 *
 * Conservative policy: remove plugin settings, runtime/cache files, generated cache files,
 * scheduled events, UltraCache-managed drop-ins, runtime secret files, and UltraCache queue tables.
 * Do not delete optimized uploads under wp-content/uploads/ultracache/images/ automatically.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!function_exists('ucwp_uninstall_normalize_path')) {
    function ucwp_uninstall_normalize_path($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        $real = realpath($path);
        return false !== $real ? str_replace('\\', '/', $real) : $path;
    }
}

if (!function_exists('ucwp_uninstall_path_is_under')) {
    function ucwp_uninstall_path_is_under($path, $root)
    {
        $path = rtrim(ucwp_uninstall_normalize_path($path), '/');
        $root = rtrim(ucwp_uninstall_normalize_path($root), '/');
        return '' !== $path && '' !== $root && ($path === $root || 0 === strpos($path . '/', $root . '/'));
    }
}

if (!function_exists('ucwp_uninstall_dropin_path')) {
    /**
     * Return the WordPress-required drop-in path during uninstall cleanup.
     *
     * advanced-cache.php and object-cache.php are intentionally located directly
     * under WP_CONTENT_DIR because WordPress only loads drop-ins from that location.
     * Uninstall cannot rely on the normal plugin bootstrap helpers, so it keeps a
     * small local equivalent for this required exception.
     *
     * @param string $basename Drop-in basename.
     * @return string
     */
    function ucwp_uninstall_dropin_path($basename)
    {
        $basename = basename((string) $basename);
        if (!in_array($basename, array('advanced-cache.php', 'object-cache.php'), true) || !defined('WP_CONTENT_DIR')) {
            return '';
        }

        return trailingslashit(WP_CONTENT_DIR) . $basename;
    }
}

if (!function_exists('ucwp_uninstall_get_wp_filesystem')) {
    function ucwp_uninstall_get_wp_filesystem()
    {
        static $initialized = null;
        global $wp_filesystem;

        if (true === $initialized && is_object($wp_filesystem)) {
            return $wp_filesystem;
        }

        if (false === $initialized || !defined('ABSPATH')) {
            return false;
        }

        $initialized = false;

        if (!function_exists('WP_Filesystem')) {
            $file_api = ABSPATH . 'wp-admin/includes/file.php';
            if (!file_exists($file_api)) {
                return false;
            }
            require_once $file_api;
        }

        if (!function_exists('WP_Filesystem') || !WP_Filesystem() || !is_object($wp_filesystem)) {
            return false;
        }

        $initialized = true;
        return $wp_filesystem;
    }
}

if (!function_exists('ucwp_uninstall_get_contents')) {
    function ucwp_uninstall_get_contents($path)
    {
        $path = (string) $path;
        if ('' === $path || !is_readable($path)) {
            return false;
        }

        $filesystem = ucwp_uninstall_get_wp_filesystem();
        if ($filesystem && $filesystem->exists($path) && $filesystem->is_file($path)) {
            return $filesystem->get_contents($path);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Guarded uninstall fallback after WP_Filesystem read is unavailable.
        return file_get_contents($path);
    }
}

if (!function_exists('ucwp_uninstall_delete_path')) {
    function ucwp_uninstall_delete_path($path, array $allowed_roots)
    {
        $path = (string) $path;
        if ('' === $path || (!file_exists($path) && !is_link($path))) {
            return true;
        }

        if (is_link($path)) {
            return true;
        }

        $allowed = false;
        foreach ($allowed_roots as $root) {
            if (ucwp_uninstall_path_is_under($path, $root)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            return false;
        }

        if (is_dir($path)) {
            $items = scandir($path);
            if (!is_array($items)) {
                return false;
            }

            foreach ($items as $item) {
                if ('.' === $item || '..' === $item) {
                    continue;
                }

                $child = $path . DIRECTORY_SEPARATOR . $item;
                if (is_link($child)) {
                    continue;
                }

                ucwp_uninstall_delete_path($child, $allowed_roots);
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Non-recursive rmdir is intentional so uninstall cleanup never traverses symlink directories through filesystem adapters.
            return @rmdir($path) || !file_exists($path);
        }

        $filesystem = ucwp_uninstall_get_wp_filesystem();
        if ($filesystem && $filesystem->exists($path) && $filesystem->is_file($path)) {
            $result = $filesystem->delete($path, false, 'f');
            clearstatcache(true, $path);
            if ($result || !file_exists($path)) {
                return true;
            }
        }

        if (function_exists('wp_delete_file')) {
            wp_delete_file($path);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Fallback after WP_Filesystem/wp_delete_file() for path-guarded uninstall cleanup.
        return !file_exists($path) || @unlink($path) || !file_exists($path);
    }
}

if (!function_exists('ucwp_uninstall_delete_options_by_like_patterns')) {
    /**
     * Delete UltraCache-owned option/transient rows by targeted option_name prefixes.
     *
     * @param array $patterns SQL LIKE patterns such as "_transient_ucwp_%".
     * @return void
     */
    function ucwp_uninstall_delete_options_by_like_patterns(array $patterns)
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

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup removes only UltraCache-owned option/transient prefixes.
            $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE option_name LIKE %s", $wpdb->options, $pattern));
        }

        if (is_multisite()) {
            foreach ($patterns as $pattern) {
                $pattern = (string) $pattern;
                if ('' === $pattern) {
                    continue;
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup removes only UltraCache-owned site option/transient prefixes.
                $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE meta_key LIKE %s", $wpdb->sitemeta, $pattern));
            }
        }
    }
}

if (!function_exists('ucwp_uninstall_generated_runtime_asset_dirs')) {
    /**
     * Return public generated runtime asset directories that are safe to remove.
     *
     * Persistent optimized media under uploads/ultracache/images is intentionally excluded.
     *
     * @return array<int, string>
     */
    function ucwp_uninstall_generated_runtime_asset_dirs()
    {
        if (!function_exists('wp_upload_dir')) {
            return array();
        }

        $uploads = wp_upload_dir(null, false);
        if (!is_array($uploads) || empty($uploads['basedir'])) {
            return array();
        }

        $base = trailingslashit($uploads['basedir']) . 'ultracache/';
        return array(
            $base . 'css-bundles/',
            $base . 'font-css/',
            $base . 'google-fonts/',
            $base . 'optimized-css/',
        );
    }
}

function ucwp_uninstall_sanitize_cleanup_policy($policy)
{
    $policy = strtolower(trim((string) $policy));
    $allowed = array('plugin_only', 'keep_settings', 'keep_settings_tables', 'delete_everything');
    return in_array($policy, $allowed, true) ? $policy : 'plugin_only';
}

function ucwp_uninstall_get_cleanup_policy()
{
    $settings = get_option('ultracache_settings', array());
    if (is_array($settings) && isset($settings['uninstallCleanupPolicy'])) {
        return ucwp_uninstall_sanitize_cleanup_policy($settings['uninstallCleanupPolicy']);
    }

    return 'delete_everything';
}

function ucwp_run_uninstall_cleanup()
{
    $ucwp_policy = ucwp_uninstall_get_cleanup_policy();
    $ucwp_keep_settings = in_array($ucwp_policy, array('plugin_only', 'keep_settings', 'keep_settings_tables'), true);
    $ucwp_keep_tables = in_array($ucwp_policy, array('plugin_only', 'keep_settings_tables'), true);
    $ucwp_delete_cache_files = ('plugin_only' !== $ucwp_policy);
    $ucwp_remove_secrets = ('delete_everything' === $ucwp_policy);
    $ucwp_delete_runtime_options = ('plugin_only' !== $ucwp_policy);

    $ucwp_scheduled_hooks = array(
        'ucwp_scheduled_cache_cleanup',
        'ucwp_cron_warm_tick',
        'ucwp_cron_warm_tick_kickoff',
        'ucwp_process_media_conversion_queue',
    );

    foreach ($ucwp_scheduled_hooks as $ucwp_hook) {
        wp_clear_scheduled_hook($ucwp_hook);
    }

    if ($ucwp_delete_runtime_options) {
        $ucwp_options = array(
            'ultracache_settings_action_jobs',
            'ultracache_settings_action_queue_heavy_lock',
            'ultracache_cron_warm_state',
            'ultracache_cron_warm_lock',
            'ultracache_cron_warm_lock_atomic',
            'ultracache_wp_cache_managed',
            'ultracache_wp_config_backup_registry',
            'ultracache_media_conversion_queue',
            'ultracache_media_diagnostics_v1',
            'ultracache_media_queue_build_state_v1',
            'ultracache_object_cache_last_flush_report',
            'ultracache_last_css_bundle_summary',
            'ultracache_settings_google_fonts_last_scan',
            'ultracache_opcache_last_flush_at',
            'ultracache_external_cache_detection',
            'ucwp_external_cache_detection',
            'ultracache_warmup_generation',
        );

        if (!$ucwp_keep_settings) {
            array_unshift($ucwp_options, 'ultracache_settings');
        }

        if (!$ucwp_keep_tables) {
            $ucwp_options[] = 'ultracache_media_queue_db_version';
            $ucwp_options[] = 'ultracache_media_page_refs_db_version';
            $ucwp_options[] = 'ultracache_action_jobs_db_version';
            $ucwp_options[] = 'ultracache_js_diagnostic_queue_db_version';
            $ucwp_options[] = 'ultracache_cron_warm_queue_db_version';
            $ucwp_options[] = 'ultracache_analytics_db_version';
            $ucwp_options[] = 'ultracache_cache_asset_refs_db_version';
            $ucwp_options[] = 'ultracache_css_rewrite_map_db_version';
        }

        foreach ($ucwp_options as $ucwp_option) {
            delete_option($ucwp_option);
            delete_site_option($ucwp_option);
        }

        $ucwp_transients = array(
            'ultracache_admin_notice',
            'ultracache_dashboard_cache_activity_v1',
            'ultracache_frontend_compression_probe_v1',
            'ultracache_last_cache_event',
            'ultracache_loopback_ssl_status_v1',
            'ultracache_media_conversion_queue_lock',
            'ultracache_media_queue_process_lock_v1',
            'ultracache_media_page_refs_cleanup_lock',
            'ultracache_media_work_summary_v1',
            'ultracache_object_cache_support_status_v1',
            'ultracache_reverse_proxy_status_v2',
            'ultracache_varnish_last_result',
            'ucwp_runtime_font_css_url_map_v3',
        );

        foreach ($ucwp_transients as $ucwp_transient) {
            delete_transient($ucwp_transient);
            delete_site_transient($ucwp_transient);
        }

        ucwp_uninstall_delete_options_by_like_patterns(array(
            '_transient_ucwp_%',
            '_transient_timeout_ucwp_%',
            '_site_transient_ucwp_%',
            '_site_transient_timeout_ucwp_%',
            '_transient_ultracache_%',
            '_transient_timeout_ultracache_%',
            '_site_transient_ultracache_%',
            '_site_transient_timeout_ultracache_%',
        ));
    }

    if (!$ucwp_keep_tables && isset($GLOBALS['wpdb']) && $GLOBALS['wpdb'] instanceof wpdb) {
        global $wpdb;
        $ucwp_custom_tables = array(
            $wpdb->prefix . 'ultracache_media_queue',
            $wpdb->prefix . 'ultracache_media_page_refs',
            $wpdb->prefix . 'ultracache_action_jobs',
            $wpdb->prefix . 'ultracache_js_diagnostic_jobs',
            $wpdb->prefix . 'ultracache_cron_warm_queue',
            $wpdb->prefix . 'ultracache_analytics',
            $wpdb->prefix . 'ultracache_cache_asset_refs',
            $wpdb->prefix . 'ultracache_css_rewrite_map',
        );

        foreach ($ucwp_custom_tables as $ucwp_custom_table) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $ucwp_custom_table)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup drops only UltraCache-owned custom tables.
                $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $ucwp_custom_table));
            }
        }
    }


    $ucwp_cache_root = defined('WP_CONTENT_DIR') ? trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache/' : '';
    $ucwp_object_root = defined('WP_CONTENT_DIR') ? trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache-objects/' : '';
    $ucwp_allowed_roots = array_filter(array($ucwp_cache_root, $ucwp_object_root));

    if ($ucwp_delete_cache_files) {
        foreach ($ucwp_allowed_roots as $ucwp_root) {
            ucwp_uninstall_delete_path($ucwp_root, $ucwp_allowed_roots);
        }

        $ucwp_generated_dirs = ucwp_uninstall_generated_runtime_asset_dirs();
        $ucwp_generated_roots = array_filter(array_map('dirname', $ucwp_generated_dirs));
        foreach ($ucwp_generated_dirs as $ucwp_generated_dir) {
            ucwp_uninstall_delete_path($ucwp_generated_dir, $ucwp_generated_roots);
        }
    }

    foreach (array('advanced-cache.php', 'object-cache.php') as $ucwp_dropin_name) {
        $ucwp_dropin = ucwp_uninstall_dropin_path($ucwp_dropin_name);
        if ('' === $ucwp_dropin || !is_readable($ucwp_dropin)) {
            continue;
        }

        $ucwp_contents = (string) ucwp_uninstall_get_contents($ucwp_dropin);
        if (false !== strpos($ucwp_contents, 'UltraCache')) {
            ucwp_uninstall_delete_path($ucwp_dropin, array(dirname($ucwp_dropin)));
        }
    }

    $ucwp_runtime_secret_candidates = array();

    if (defined('ABSPATH') && $ucwp_remove_secrets) {
        $ucwp_site_root = wp_basename(untrailingslashit(ABSPATH));
        $ucwp_site_root = strtolower(preg_replace('/[^a-z0-9._-]+/', '-', (string) $ucwp_site_root));
        $ucwp_site_root = trim($ucwp_site_root, '.-_');
        if ('' === $ucwp_site_root) {
            $ucwp_site_root = 'site';
        }
        $ucwp_runtime_secret_candidates[] = dirname(untrailingslashit(ABSPATH)) . '/.' . $ucwp_site_root . '-ultracache-runtime-secrets.php';
    }

    if ('' !== $ucwp_cache_root && $ucwp_delete_cache_files) {
        $ucwp_runtime_secret_candidates[] = trailingslashit($ucwp_cache_root) . 'runtime-config.php';
        $ucwp_runtime_secret_candidates[] = trailingslashit($ucwp_cache_root) . 'runtime-config.json';
    }

    foreach (array_unique(array_filter($ucwp_runtime_secret_candidates)) as $ucwp_runtime_secret) {
        if (!is_readable($ucwp_runtime_secret)) {
            continue;
        }

        $ucwp_contents = (string) ucwp_uninstall_get_contents($ucwp_runtime_secret);
        if (false === strpos($ucwp_contents, 'UltraCache')) {
            continue;
        }

        $ucwp_runtime_root = dirname($ucwp_runtime_secret);
        ucwp_uninstall_delete_path($ucwp_runtime_secret, array($ucwp_runtime_root));
    }
}

ucwp_run_uninstall_cleanup();
