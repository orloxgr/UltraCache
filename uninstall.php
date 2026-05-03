<?php
/**
 * UltraCache uninstall cleanup.
 *
 * Conservative policy: remove plugin settings, runtime/cache files, generated cache files,
 * scheduled events, UltraCache-managed drop-ins, runtime secret files, and UltraCache queue tables.
 * Do not delete optimized uploads under wp-content/uploads/uc-images/ automatically.
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

if (!function_exists('ucwp_uninstall_delete_path')) {
    function ucwp_uninstall_delete_path($path, array $allowed_roots)
    {
        $path = (string) $path;
        if ('' === $path || !file_exists($path)) {
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

        if (is_dir($path) && !is_link($path)) {
            $items = scandir($path);
            if (!is_array($items)) {
                return false;
            }

            foreach ($items as $item) {
                if ('.' === $item || '..' === $item) {
                    continue;
                }
                ucwp_uninstall_delete_path($path . DIRECTORY_SEPARATOR . $item, $allowed_roots);
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Recursive uninstall cleanup is path-guarded to UltraCache-owned roots.
            return @rmdir($path) || !file_exists($path);
        }

        if (function_exists('wp_delete_file')) {
            wp_delete_file($path);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Fallback after wp_delete_file() for path-guarded uninstall cleanup.
        return !file_exists($path) || @unlink($path) || !file_exists($path);
    }
}

function ucwp_run_uninstall_cleanup()
{
    $ucwp_options = array(
        'ucwp_settings',
        'ucwp_settings_action_jobs',
        'ucwp_settings_action_queue_heavy_lock',
        'ucwp_cron_warm_state',
        'ucwp_cron_warm_lock',
        'ucwp_cron_warm_lock_atomic',
        'ucwp_wp_cache_managed',
        'ucwp_media_conversion_queue',
        'ucwp_media_diagnostics_v1',
        'ucwp_media_queue_db_version',
        'ucwp_media_queue_build_state_v1',
        'ucwp_object_cache_last_flush_report',
    );

    foreach ($ucwp_options as $ucwp_option) {
        delete_option($ucwp_option);
        delete_site_option($ucwp_option);
    }

    $ucwp_transients = array(
        'ucwp_admin_notice',
        'ucwp_dashboard_cache_activity_v1',
        'ucwp_frontend_compression_probe_v1',
        'ucwp_last_cache_event',
        'ucwp_loopback_ssl_status_v1',
        'ucwp_media_conversion_queue_lock',
        'ucwp_media_queue_process_lock_v1',
        'ucwp_media_work_summary_v1',
        'ucwp_object_cache_support_status_v1',
        'ucwp_reverse_proxy_status_v2',
        'ucwp_varnish_last_result',
    );

    foreach ($ucwp_transients as $ucwp_transient) {
        delete_transient($ucwp_transient);
        delete_site_transient($ucwp_transient);
    }

    $ucwp_scheduled_hooks = array(
        'ucwp_scheduled_cache_cleanup',
        'ucwp_cron_warm_tick',
        'ucwp_cron_warm_tick_kickoff',
        'ucwp_process_media_conversion_queue',
    );

    foreach ($ucwp_scheduled_hooks as $ucwp_hook) {
        wp_clear_scheduled_hook($ucwp_hook);
    }

    if (isset($GLOBALS['wpdb']) && $GLOBALS['wpdb'] instanceof wpdb) {
        global $wpdb;

        $ucwp_patterns = array(
            'ucwp_%',
            '_transient_ucwp_%',
            '_transient_timeout_ucwp_%',
            '_site_transient_ucwp_%',
            '_site_transient_timeout_ucwp_%',
        );

        foreach ($ucwp_patterns as $ucwp_pattern) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup removes only UltraCache-owned options; caching is not useful during uninstall.
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE option_name LIKE %s',
                    $wpdb->options,
                    $ucwp_pattern
                )
            );
        }

        $ucwp_media_queue_table = $wpdb->prefix . 'ucwp_media_queue';
        if (preg_match('/^[A-Za-z0-9_]+$/', $ucwp_media_queue_table)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup drops the UltraCache-owned custom media queue table.
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $ucwp_media_queue_table));
        }

        if (is_multisite()) {
            $ucwp_site_patterns = array(
                'ucwp_%',
                '_site_transient_ucwp_%',
                '_site_transient_timeout_ucwp_%',
            );

            foreach ($ucwp_site_patterns as $ucwp_pattern) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup removes only UltraCache-owned site metadata; caching is not useful during uninstall.
                $wpdb->query(
                    $wpdb->prepare(
                        'DELETE FROM %i WHERE meta_key LIKE %s',
                        $wpdb->sitemeta,
                        $ucwp_pattern
                    )
                );
            }
        }
    }

    $ucwp_cache_root = defined('WP_CONTENT_DIR') ? trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache/' : '';
    $ucwp_object_root = defined('WP_CONTENT_DIR') ? trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache-objects/' : '';
    $ucwp_allowed_roots = array_filter(array($ucwp_cache_root, $ucwp_object_root));

    foreach ($ucwp_allowed_roots as $ucwp_root) {
        ucwp_uninstall_delete_path($ucwp_root, $ucwp_allowed_roots);
    }

    if (defined('WP_CONTENT_DIR')) {
        foreach (array('advanced-cache.php', 'object-cache.php') as $ucwp_dropin_name) {
            $ucwp_dropin = trailingslashit(WP_CONTENT_DIR) . $ucwp_dropin_name;
            if (is_readable($ucwp_dropin)) {
                $ucwp_contents = (string) file_get_contents($ucwp_dropin);
                if (false !== strpos($ucwp_contents, 'UltraCache')) {
                    ucwp_uninstall_delete_path($ucwp_dropin, array(WP_CONTENT_DIR));
                }
            }
        }
    }

    $ucwp_runtime_secret_candidates = array();

    if (defined('WP_CONTENT_DIR')) {
        $ucwp_runtime_secret_candidates[] = trailingslashit(WP_CONTENT_DIR) . 'ultracache-runtime-secrets.php';
        $ucwp_runtime_secret_candidates[] = trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache-runtime-secrets.php';
    }

    if (defined('ABSPATH')) {
        $ucwp_site_root = wp_basename(untrailingslashit(ABSPATH));
        $ucwp_site_root = strtolower(preg_replace('/[^a-z0-9._-]+/', '-', (string) $ucwp_site_root));
        $ucwp_site_root = trim($ucwp_site_root, '.-_');
        if ('' === $ucwp_site_root) {
            $ucwp_site_root = 'site';
        }
        $ucwp_runtime_secret_candidates[] = dirname(untrailingslashit(ABSPATH)) . '/.' . $ucwp_site_root . '-ultracache-runtime-secrets.php';
    }

    if ('' !== $ucwp_cache_root) {
        $ucwp_runtime_secret_candidates[] = trailingslashit($ucwp_cache_root) . 'runtime-config.json';
        $ucwp_runtime_secret_candidates[] = trailingslashit($ucwp_cache_root) . 'runtime-secrets.php';
        $ucwp_runtime_secret_candidates[] = trailingslashit($ucwp_cache_root) . '.ultracache-runtime-secrets.php';
    }

    foreach (array_unique(array_filter($ucwp_runtime_secret_candidates)) as $ucwp_runtime_secret) {
        if (!is_readable($ucwp_runtime_secret)) {
            continue;
        }

        $ucwp_contents = (string) file_get_contents($ucwp_runtime_secret);
        if (false === strpos($ucwp_contents, 'UltraCache')) {
            continue;
        }

        $ucwp_runtime_root = dirname($ucwp_runtime_secret);
        ucwp_uninstall_delete_path($ucwp_runtime_secret, array($ucwp_runtime_root));
    }
}

ucwp_run_uninstall_cleanup();
