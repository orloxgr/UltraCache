<?php
/**
 * Cache invalidation after successful WordPress core, plugin, and theme updates.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Update_Cache_Invalidation_Trait
{
    /** @var array<string,array<string,bool>> Successful plugin/theme installs in the current request. */
    private static $successful_upgrader_items = array(
        'plugin' => array(),
        'theme'  => array(),
    );

    /**
     * Track successful package installs so bulk updates can be evaluated item by item.
     *
     * @param array|WP_Error $result     Package installation result.
     * @param array          $hook_extra Upgrader context.
     * @return array|WP_Error
     */
    public function track_upgrader_install_package_result($result, $hook_extra)
    {
        if (false === $result || is_wp_error($result) || !is_array($hook_extra)) {
            return $result;
        }

        if (!empty($hook_extra['plugin']) && is_scalar($hook_extra['plugin'])) {
            $plugin = self::normalize_updated_plugin_basename($hook_extra['plugin']);
            if ('' !== $plugin) {
                self::$successful_upgrader_items['plugin'][$plugin] = true;
            }
        }

        if (!empty($hook_extra['theme']) && is_scalar($hook_extra['theme'])) {
            $theme = self::normalize_updated_theme_slug($hook_extra['theme']);
            if ('' !== $theme) {
                self::$successful_upgrader_items['theme'][$theme] = true;
            }
        }

        return $result;
    }

    /**
     * Purge frontend cache after an enabled, successful update that can affect output.
     *
     * @param WP_Upgrader $upgrader   Upgrader instance.
     * @param array       $hook_extra Upgrader context.
     * @return void
     */
    public function handle_upgrader_process_complete($upgrader, $hook_extra)
    {
        if (!is_array($hook_extra) || 'update' !== ($hook_extra['action'] ?? '') || empty($hook_extra['type'])) {
            return;
        }

        $settings = self::get_dashboard_settings();
        if (empty($settings['pageCacheEnabled'])) {
            self::clear_tracked_upgrader_items((string) $hook_extra['type'], $hook_extra);
            return;
        }

        $type = sanitize_key((string) $hook_extra['type']);
        $updated_items = array();

        if ('core' === $type && !empty($settings['purgeAfterCoreUpdatesEnabled']) && self::did_core_version_change_in_current_request()) {
            $updated_items[] = 'wordpress-core';
        } elseif ('plugin' === $type && !empty($settings['purgeAfterPluginUpdatesEnabled'])) {
            $updated_items = self::get_successfully_updated_active_plugins($hook_extra);
        } elseif ('theme' === $type && !empty($settings['purgeAfterThemeUpdatesEnabled'])) {
            $updated_items = self::get_successfully_updated_active_themes($hook_extra);
        }

        self::clear_tracked_upgrader_items($type, $hook_extra);

        if (empty($updated_items) || !class_exists('Ultra_Cache_Engine') || !method_exists('Ultra_Cache_Engine', 'get_instance')) {
            return;
        }

        $engine = Ultra_Cache_Engine::get_instance();
        if (!$engine || !method_exists($engine, 'purge_frontend_cache_after_update')) {
            return;
        }

        $purged = (bool) $engine->purge_frontend_cache_after_update(
            array(
                'update_type' => $type,
                'bulk'        => !empty($hook_extra['bulk']),
                'items'       => array_values($updated_items),
            )
        );

        if (!$purged) {
            return;
        }

        $this->flush_external_page_caches_after_update();
        self::maybe_start_cron_warmup_after_purge('manual_purge', false);
    }

    /**
     * Return active plugins that completed successfully in this update operation.
     *
     * @param array $hook_extra Upgrader context.
     * @return string[]
     */
    private static function get_successfully_updated_active_plugins(array $hook_extra)
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $candidates = self::get_upgrader_item_list($hook_extra, 'plugin', 'plugins');
        $active = array();

        foreach ($candidates as $candidate) {
            $plugin = self::normalize_updated_plugin_basename($candidate);
            if ('' === $plugin || empty(self::$successful_upgrader_items['plugin'][$plugin])) {
                continue;
            }

            if (function_exists('is_plugin_active') && is_plugin_active($plugin)) {
                $active[$plugin] = $plugin;
            }
        }

        return array_values($active);
    }

    /**
     * Return active parent/child themes that completed successfully.
     *
     * @param array $hook_extra Upgrader context.
     * @return string[]
     */
    private static function get_successfully_updated_active_themes(array $hook_extra)
    {
        $stylesheet = self::normalize_updated_theme_slug(get_stylesheet());
        $template = self::normalize_updated_theme_slug(get_template());
        $active_slugs = array_filter(array($stylesheet, $template));
        $candidates = self::get_upgrader_item_list($hook_extra, 'theme', 'themes');
        $active = array();

        foreach ($candidates as $candidate) {
            $theme = self::normalize_updated_theme_slug($candidate);
            if ('' === $theme || empty(self::$successful_upgrader_items['theme'][$theme])) {
                continue;
            }

            if (in_array($theme, $active_slugs, true)) {
                $active[$theme] = $theme;
            }
        }

        return array_values($active);
    }

    /**
     * Read singular and bulk updater item fields through one normalized list.
     *
     * @param array  $hook_extra Upgrader context.
     * @param string $single_key Singular field.
     * @param string $bulk_key   Bulk field.
     * @return array
     */
    private static function get_upgrader_item_list(array $hook_extra, $single_key, $bulk_key)
    {
        $items = array();

        if (!empty($hook_extra[$bulk_key]) && is_array($hook_extra[$bulk_key])) {
            $items = $hook_extra[$bulk_key];
        } elseif (!empty($hook_extra[$single_key]) && is_scalar($hook_extra[$single_key])) {
            $items = array($hook_extra[$single_key]);
        }

        return array_values(array_filter($items, 'is_scalar'));
    }

    /**
     * Normalize a plugin basename supplied by WordPress core.
     *
     * @param mixed $plugin Plugin basename.
     * @return string
     */
    private static function normalize_updated_plugin_basename($plugin)
    {
        if (!is_scalar($plugin)) {
            return '';
        }

        $plugin = ltrim(wp_normalize_path((string) $plugin), '/');
        if ('' === $plugin || false !== strpos($plugin, '../') || !preg_match('/\.php$/i', $plugin)) {
            return '';
        }

        return plugin_basename($plugin);
    }

    /**
     * Normalize a theme directory slug supplied by WordPress core.
     *
     * @param mixed $theme Theme slug.
     * @return string
     */
    private static function normalize_updated_theme_slug($theme)
    {
        if (!is_scalar($theme)) {
            return '';
        }

        $theme = trim(wp_normalize_path((string) $theme), '/');
        if ('' === $theme || false !== strpos($theme, '../')) {
            return '';
        }

        return strtolower(basename($theme));
    }

    /**
     * Core_Upgrader does not expose its local update_core() result to the completion hook.
     * A real version update leaves the request running the old loaded version while the
     * installed version.php contains the new version, which provides a deterministic check.
     *
     * @return bool
     */
    private static function did_core_version_change_in_current_request()
    {
        $running_version = function_exists('wp_get_wp_version') ? (string) wp_get_wp_version() : (string) ($GLOBALS['wp_version'] ?? '');
        $version_file = ABSPATH . WPINC . '/version.php';

        if ('' === $running_version || !is_readable($version_file)) {
            return false;
        }

        $installed_version = (static function () {
            $wp_version = '';
            include ABSPATH . WPINC . '/version.php';
            return is_string($wp_version) ? $wp_version : '';
        })();

        return '' !== $installed_version && $installed_version !== $running_version;
    }

    /**
     * Remove request-local success records after the corresponding completion hook.
     *
     * @param string $type       Update type.
     * @param array  $hook_extra Upgrader context.
     * @return void
     */
    private static function clear_tracked_upgrader_items($type, array $hook_extra)
    {
        if (!in_array($type, array('plugin', 'theme'), true)) {
            return;
        }

        $single_key = $type;
        $bulk_key = $type . 's';
        foreach (self::get_upgrader_item_list($hook_extra, $single_key, $bulk_key) as $item) {
            $normalized = 'plugin' === $type
                ? self::normalize_updated_plugin_basename($item)
                : self::normalize_updated_theme_slug($item);
            if ('' !== $normalized) {
                unset(self::$successful_upgrader_items[$type][$normalized]);
            }
        }
    }

    /**
     * Flush only configured external full-page caches after an update purge.
     * Object cache, APCu, and OPcache are intentionally excluded.
     *
     * @return void
     */
    private function flush_external_page_caches_after_update()
    {
        $settings = self::get_dashboard_settings();

        if (!empty($settings['flushAllIncludeLiteSpeed'])) {
            self::flush_litespeed_cache();
        }

        if (!empty($settings['flushAllIncludeNginx'])) {
            self::flush_nginx_cache();
        }

        if (!empty($settings['flushAllIncludeVarnish'])) {
            $this->handle_varnish_after_purge_all(array('scope' => 'update'));
        }
    }
}
