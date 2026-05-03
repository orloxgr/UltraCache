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

            return @rmdir($path) || !file_exists($path);
        }

        if (function_exists('wp_delete_file')) {
            wp_delete_file($path);
        }

        return !file_exists($path) || @unlink($path) || !file_exists($path);
    }
}

$options = array(
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

foreach ($options as $option) {
    delete_option($option);
    delete_site_option($option);
}

$transients = array(
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

foreach ($transients as $transient) {
    delete_transient($transient);
    delete_site_transient($transient);
}

$scheduled_hooks = array(
    'ucwp_scheduled_cache_cleanup',
    'ucwp_cron_warm_tick',
    'ucwp_cron_warm_tick_kickoff',
    'ucwp_process_media_conversion_queue',
);

foreach ($scheduled_hooks as $hook) {
    wp_clear_scheduled_hook($hook);
}

if (isset($GLOBALS['wpdb']) && $GLOBALS['wpdb'] instanceof wpdb) {
    global $wpdb;

    $patterns = array(
        'ucwp_%',
        '_transient_ucwp_%',
        '_transient_timeout_ucwp_%',
        '_site_transient_ucwp_%',
        '_site_transient_timeout_ucwp_%',
    );

    foreach ($patterns as $pattern) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            )
        );
    }

    $media_queue_table = $wpdb->prefix . 'ucwp_media_queue';
    $wpdb->query("DROP TABLE IF EXISTS `{$media_queue_table}`");

    if (is_multisite()) {
        $site_patterns = array(
            'ucwp_%',
            '_site_transient_ucwp_%',
            '_site_transient_timeout_ucwp_%',
        );

        foreach ($site_patterns as $pattern) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
                    $pattern
                )
            );
        }
    }
}

$cache_root = defined('WP_CONTENT_DIR') ? trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache/' : '';
$object_root = defined('WP_CONTENT_DIR') ? trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache-objects/' : '';
$allowed_roots = array_filter(array($cache_root, $object_root));

foreach ($allowed_roots as $root) {
    ucwp_uninstall_delete_path($root, $allowed_roots);
}

if (defined('WP_CONTENT_DIR')) {
    foreach (array('advanced-cache.php', 'object-cache.php') as $dropin_name) {
        $dropin = trailingslashit(WP_CONTENT_DIR) . $dropin_name;
        if (is_readable($dropin)) {
            $contents = (string) file_get_contents($dropin);
            if (false !== strpos($contents, 'UltraCache')) {
                ucwp_uninstall_delete_path($dropin, array(WP_CONTENT_DIR));
            }
        }
    }
}

$runtime_secret_candidates = array();

if (defined('WP_CONTENT_DIR')) {
    $runtime_secret_candidates[] = trailingslashit(WP_CONTENT_DIR) . 'ultracache-runtime-secrets.php';
    $runtime_secret_candidates[] = trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache-runtime-secrets.php';
}

if (defined('ABSPATH')) {
    $site_root = wp_basename(untrailingslashit(ABSPATH));
    $site_root = strtolower(preg_replace('/[^a-z0-9._-]+/', '-', (string) $site_root));
    $site_root = trim($site_root, '.-_');
    if ('' === $site_root) {
        $site_root = 'site';
    }
    $runtime_secret_candidates[] = dirname(untrailingslashit(ABSPATH)) . '/.' . $site_root . '-ultracache-runtime-secrets.php';
}

if ('' !== $cache_root) {
    $runtime_secret_candidates[] = trailingslashit($cache_root) . 'runtime-config.json';
    $runtime_secret_candidates[] = trailingslashit($cache_root) . 'runtime-secrets.php';
    $runtime_secret_candidates[] = trailingslashit($cache_root) . '.ultracache-runtime-secrets.php';
}

foreach (array_unique(array_filter($runtime_secret_candidates)) as $runtime_secret) {
    if (!is_readable($runtime_secret)) {
        continue;
    }

    $contents = (string) file_get_contents($runtime_secret);
    if (false === strpos($contents, 'UltraCache')) {
        continue;
    }

    $runtime_root = dirname($runtime_secret);
    ucwp_uninstall_delete_path($runtime_secret, array($runtime_root));
}
