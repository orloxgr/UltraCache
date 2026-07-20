<?php
/**
 * Page-cache and object-cache drop-in reconciliation.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Dropin_Reconciliation_Trait
{
    private static function should_run_bootstrap_reconcile_hooks()
    {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        // Drop-in reconciliation performs privileged filesystem work. Do not
        // run it on every wp-admin, REST, AJAX, or plugin-management request.
        // Activation and settings-save paths already synchronize the drop-ins
        // explicitly; the dashboard keeps a scoped repair opportunity.
        if (function_exists('is_admin') && is_admin()) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing; no state is changed from this value.
            $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
            return 'ultracache' === $page;
        }

        return false;
    }

    public function reconcile_object_cache_dropin()
    {
        ultracache_request_profile_checkpoint('object_cache_reconcile_entry');

        if (!self::should_run_bootstrap_reconcile_hooks()) {
            ultracache_request_profile_checkpoint('object_cache_reconcile_skipped', array('reason' => 'frontend_bootstrap_reconcile_disabled'));
            return;
        }

        $is_full_reconcile = $this->should_run_full_object_cache_reconcile();
        ultracache_request_profile_checkpoint('object_cache_reconcile_context_checked', array(
            'full_reconcile' => $is_full_reconcile ? 'true' : 'false',
        ));

        if (!class_exists('Ultra_Cache_Object_Cache_Manager')) {
            ultracache_request_profile_checkpoint('object_cache_reconcile_skipped', array('reason' => 'manager_class_missing'));
            return;
        }

        // Frontend traffic performs only a read-only drop-in health check.
        // File writes/removals remain in admin, activation, settings-save, and WP-CLI contexts
        // so WordPress Filesystem API can be used where mutation is required.
        if (!$is_full_reconcile) {
            ultracache_request_profile_checkpoint('object_cache_reconcile_frontend_before_raw_setting');
            $object_cache_enabled = self::is_object_cache_enabled_raw_fast();
            ultracache_request_profile_checkpoint('object_cache_reconcile_frontend_after_raw_setting', array(
                'object_cache_enabled' => $object_cache_enabled ? 'true' : 'false',
            ));

            if (!$object_cache_enabled) {
                ultracache_request_profile_checkpoint('object_cache_reconcile_skipped', array('reason' => 'disabled_frontend_fast'));
                return;
            }

            ultracache_request_profile_checkpoint('object_cache_reconcile_light_start');
            $status = method_exists('Ultra_Cache_Object_Cache_Manager', 'get_dropin_status_fast')
                ? Ultra_Cache_Object_Cache_Manager::get_dropin_status_fast()
                : array('healthy' => false, 'reason' => 'status_method_missing');

            if (!empty($status['healthy']) && function_exists('wp_using_ext_object_cache')) {
                wp_using_ext_object_cache(true);
            }

            ultracache_request_profile_checkpoint('object_cache_reconcile_light_end', array(
                'mode' => 'read_only_frontend',
                'healthy' => !empty($status['healthy']) ? 'true' : 'false',
                'reason' => isset($status['reason']) ? (string) $status['reason'] : '',
                'exists' => !empty($status['exists']) ? 'true' : 'false',
                'readable' => !empty($status['readable']) ? 'true' : 'false',
                'has_marker' => !empty($status['has_marker']) ? 'true' : 'false',
                'build' => isset($status['build']) ? (string) $status['build'] : '',
                'expected_build' => isset($status['expected_build']) ? (string) $status['expected_build'] : '',
            ));
            return;
        }

        ultracache_request_profile_checkpoint('object_cache_reconcile_full_start', array('mode' => 'admin_cli_repair'));
        if (method_exists('Ultra_Cache_Object_Cache_Manager', 'sync_dropin')) {
            $result = Ultra_Cache_Object_Cache_Manager::sync_dropin();
            ultracache_request_profile_checkpoint('object_cache_reconcile_full_end', array(
                'mode' => 'admin_cli_repair',
                'result' => $result ? 'true' : 'false',
            ));
            return;
        }

        ultracache_request_profile_checkpoint('object_cache_reconcile_full_end', array(
            'mode' => 'admin_cli_repair',
            'result' => 'method_missing',
        ));
    }

    private static function can_run_privileged_file_mutations()
    {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        if (!function_exists('current_user_can')) {
            return false;
        }

        return current_user_can('manage_options') && current_user_can('activate_plugins');
    }

    private function should_run_full_object_cache_reconcile()
    {
        return self::can_run_privileged_file_mutations();
    }

    private function should_run_full_page_cache_reconcile()
    {
        // File mutations are intentionally kept in privileged plugin-management
        // contexts. is_admin() is not sufficient because admin-ajax.php and
        // low-privilege wp-admin requests can also run in an admin context.
        return self::can_run_privileged_file_mutations();
    }

    private static function is_page_cache_enabled_raw_fast()
    {
        static $cached = null;

        if (null !== $cached) {
            return $cached;
        }

        $saved = get_option(ULTRACACHE_SETTINGS_KEY, array());
        $cached = is_array($saved) && !empty($saved['pageCacheEnabled']);

        return $cached;
    }

    private static function is_object_cache_enabled_raw_fast()
    {
        static $cached = null;

        if (null !== $cached) {
            return $cached;
        }

        $saved = get_option(ULTRACACHE_SETTINGS_KEY, array());
        $cached = is_array($saved) && !empty($saved['objectCacheEnabled']);

        return $cached;
    }

    public function reconcile_page_cache_dropin()
    {
        ultracache_request_profile_checkpoint('page_cache_reconcile_entry');

        if (!self::should_run_bootstrap_reconcile_hooks()) {
            ultracache_request_profile_checkpoint('page_cache_reconcile_skipped', array('reason' => 'frontend_bootstrap_reconcile_disabled'));
            return;
        }

        $is_full_reconcile = $this->should_run_full_page_cache_reconcile();
        ultracache_request_profile_checkpoint('page_cache_reconcile_context_checked', array(
            'full_reconcile' => $is_full_reconcile ? 'true' : 'false',
        ));

        // Frontend traffic must stay cheap and read-only. Do not call the full
        // dashboard settings sanitizer here because it may canonicalize/update the
        // settings option. File writes and repairs remain in admin/CLI contexts.
        if (!$is_full_reconcile) {
            ultracache_request_profile_checkpoint('page_cache_reconcile_frontend_before_raw_setting');
            $page_cache_enabled = self::is_page_cache_enabled_raw_fast();
            ultracache_request_profile_checkpoint('page_cache_reconcile_frontend_after_raw_setting', array(
                'page_cache_enabled' => $page_cache_enabled ? 'true' : 'false',
            ));

            if (!$page_cache_enabled || !defined('WP_CACHE') || !WP_CACHE) {
                ultracache_request_profile_checkpoint('page_cache_reconcile_skipped', array('reason' => 'disabled_or_wp_cache_false_frontend_fast'));
                return;
            }

            ultracache_request_profile_checkpoint('page_cache_reconcile_frontend_before_engine_class');
            $engine_class = self::get_engine_class();
            ultracache_request_profile_checkpoint('page_cache_reconcile_frontend_after_engine_class', array(
                'engine_class' => $engine_class ? 'true' : 'false',
            ));
            if (!$engine_class) {
                ultracache_request_profile_checkpoint('page_cache_reconcile_skipped', array('reason' => 'engine_class_missing'));
                return;
            }

            ultracache_request_profile_checkpoint('page_cache_reconcile_light_start');
            $status = method_exists($engine_class, 'get_advanced_cache_dropin_status')
                ? $engine_class::get_advanced_cache_dropin_status()
                : array('healthy' => false, 'reason' => 'status_method_missing');

            ultracache_request_profile_checkpoint('page_cache_reconcile_light_end', array(
                'mode' => 'read_only_frontend',
                'healthy' => !empty($status['healthy']) ? 'true' : 'false',
                'reason' => isset($status['reason']) ? (string) $status['reason'] : '',
                'exists' => !empty($status['exists']) ? 'true' : 'false',
                'readable' => !empty($status['readable']) ? 'true' : 'false',
                'has_marker' => !empty($status['has_marker']) ? 'true' : 'false',
                'build' => isset($status['build']) ? (string) $status['build'] : '',
                'expected_build' => isset($status['expected_build']) ? (string) $status['expected_build'] : '',
            ));
            return;
        }

        ultracache_request_profile_checkpoint('page_cache_reconcile_full_before_settings');
        $settings = self::get_dashboard_settings();
        ultracache_request_profile_checkpoint('page_cache_reconcile_full_after_settings', array(
            'page_cache_enabled' => !empty($settings['pageCacheEnabled']) ? 'true' : 'false',
        ));

        if (empty($settings['pageCacheEnabled']) || !defined('WP_CACHE') || !WP_CACHE) {
            ultracache_request_profile_checkpoint('page_cache_reconcile_skipped', array('reason' => 'disabled_or_wp_cache_false_admin_cli'));
            return;
        }

        $engine_class = self::get_engine_class();
        if (!$engine_class) {
            ultracache_request_profile_checkpoint('page_cache_reconcile_skipped', array('reason' => 'engine_class_missing'));
            return;
        }

        ultracache_request_profile_checkpoint('page_cache_reconcile_full_start', array('mode' => 'admin_cli_repair'));
        if (method_exists($engine_class, 'setup_advanced_cache')) {
            $engine_class::setup_advanced_cache(true);
        }
        ultracache_request_profile_checkpoint('page_cache_reconcile_full_end', array('mode' => 'admin_cli_repair'));
    }
}
