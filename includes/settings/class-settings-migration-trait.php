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


    private static function get_known_cache_plugin_signatures()
    {
        return array(
            'w3-total-cache' => array('label' => __('W3 Total Cache', 'ultracache'), 'markers' => array('W3 Total Cache', 'W3TC', 'w3-total-cache', 'w3tc_')),
            'wp-rocket' => array('label' => __('WP Rocket', 'ultracache'), 'markers' => array('WP Rocket', 'WP_ROCKET', 'rocket_clean_domain', 'wp-rocket')),
            'wp-super-cache' => array('label' => __('WP Super Cache', 'ultracache'), 'markers' => array('WP Super Cache', 'WPCACHEHOME', 'wp-cache-phase1', 'wp-super-cache')),
            'litespeed-cache' => array('label' => __('LiteSpeed Cache', 'ultracache'), 'markers' => array('LiteSpeed Cache', 'LSCWP', 'litespeed-cache', 'LiteSpeed_Cache')),
            'sg-cachepress' => array('label' => __('SiteGround Optimizer', 'ultracache'), 'markers' => array('SiteGround Optimizer', 'SG Optimizer', 'sg-cachepress', 'SiteGround_Optimizer')),
            'wp-fastest-cache' => array('label' => __('WP Fastest Cache', 'ultracache'), 'markers' => array('WP Fastest Cache', 'WpFastestCache', 'wp-fastest-cache')),
            'breeze' => array('label' => __('Breeze', 'ultracache'), 'markers' => array('Breeze', 'BREEZE', 'breeze-cache')),
            'redis-cache' => array('label' => __('Redis Object Cache', 'ultracache'), 'markers' => array('Redis Object Cache', 'Redis_Object_Cache', 'redis-cache', 'Rhubarb\\RedisCache')),
            'docket-cache' => array('label' => __('Docket Cache', 'ultracache'), 'markers' => array('Docket Cache', 'DocketCache', 'docket-cache')),
            'object-cache-pro' => array('label' => __('Object Cache Pro', 'ultracache'), 'markers' => array('Object Cache Pro', 'objectcache.pro', 'ObjectCachePro')),
            'memcached' => array('label' => __('Memcached Object Cache', 'ultracache'), 'markers' => array('Memcached', 'Memcache', 'memcached', 'memcache')),
            'powered-cache' => array('label' => __('Powered Cache', 'ultracache'), 'markers' => array('Powered Cache', 'powered-cache')),
            'cache-enabler' => array('label' => __('Cache Enabler', 'ultracache'), 'markers' => array('Cache Enabler', 'cache-enabler')),
            'autoptimize' => array('label' => __('Autoptimize', 'ultracache'), 'markers' => array('Autoptimize', 'autoptimize')),
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
