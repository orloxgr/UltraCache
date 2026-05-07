<?php
/**
 * Plugin Name: UltraCache
 * Plugin URI: https://github.com/orloxgr/ultracache
 * Description: WordPress page cache, object cache, media optimization, Varnish purge tools, warm-up, and performance diagnostics.
 * Version: 2.57.95
 * Author: Byron Iniotakis
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: ultracache
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('UCWP_VERSION')) {
    define('UCWP_VERSION', '2.57.95');
}
if (!defined('UCWP_FILE')) {
    define('UCWP_FILE', __FILE__);
}
if (!defined('UCWP_BASENAME')) {
    define('UCWP_BASENAME', plugin_basename(__FILE__));
}
if (!defined('UCWP_PATH')) {
    define('UCWP_PATH', plugin_dir_path(__FILE__));
}
if (!defined('UCWP_URL')) {
    define('UCWP_URL', plugin_dir_url(__FILE__));
}
if (!defined('UCWP_SETTINGS_KEY')) {
    define('UCWP_SETTINGS_KEY', 'ucwp_settings');
}
if (!defined('UCWP_CRON_WARM_STATE_KEY')) {
    define('UCWP_CRON_WARM_STATE_KEY', 'ucwp_cron_warm_state');
}
if (!defined('UCWP_CRON_WARM_LOCK_KEY')) {
    define('UCWP_CRON_WARM_LOCK_KEY', 'ucwp_cron_warm_lock');
}
if (!defined('UCWP_CRAWL_SCOPE_SUMMARY_KEY')) {
    define('UCWP_CRAWL_SCOPE_SUMMARY_KEY', 'ucwp_crawl_scope_summary');
}
if (!defined('UCWP_WP_CACHE_MANAGED_KEY')) {
    define('UCWP_WP_CACHE_MANAGED_KEY', 'ucwp_wp_cache_managed');
}
if (!defined('UCWP_CACHE_DIR')) {
    define('UCWP_CACHE_DIR', trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache/');
}
if (!defined('UCWP_OPTIMIZED_IMAGES_DIR')) {
    define('UCWP_OPTIMIZED_IMAGES_DIR', trailingslashit(WP_CONTENT_DIR) . 'uploads/uc-images/');
}
if (!defined('UCWP_OPTIMIZED_IMAGES_URL')) {
    $ucwp_optimized_images_url_path = (string) wp_parse_url(content_url('uploads/uc-images'), PHP_URL_PATH);
    if ('' === $ucwp_optimized_images_url_path) {
        $ucwp_optimized_images_url_path = '/wp-content/uploads/uc-images';
    }
    define('UCWP_OPTIMIZED_IMAGES_URL', trailingslashit('/' . ltrim(str_replace('\\', '/', $ucwp_optimized_images_url_path), '/')));
}
if (!defined('UCWP_AVIF_DIR')) {
    define('UCWP_AVIF_DIR', trailingslashit(UCWP_OPTIMIZED_IMAGES_DIR) . 'avif/');
}
if (!defined('UCWP_AVIF_URL')) {
    define('UCWP_AVIF_URL', trailingslashit(UCWP_OPTIMIZED_IMAGES_URL) . 'avif/');
}
if (!defined('UCWP_WEBP_DIR')) {
    define('UCWP_WEBP_DIR', trailingslashit(UCWP_OPTIMIZED_IMAGES_DIR) . 'webp/');
}
if (!defined('UCWP_WEBP_URL')) {
    define('UCWP_WEBP_URL', trailingslashit(UCWP_OPTIMIZED_IMAGES_URL) . 'webp/');
}
if (!defined('UCWP_OBJECT_CACHE_DIR')) {
    define('UCWP_OBJECT_CACHE_DIR', trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache-objects/');
}

require_once UCWP_PATH . 'includes/core/functions.php';
require_once UCWP_PATH . 'includes/fonts/functions.php';
require_once UCWP_PATH . 'includes/settings/class-settings-trait.php';
require_once UCWP_PATH . 'includes/admin/class-admin-trait.php';
require_once UCWP_PATH . 'includes/integrations/class-varnish-trait.php';
require_once UCWP_PATH . 'includes/diagnostics/class-diagnostics-trait.php';

if (!class_exists('Ultra_Cache_WP')) {
    class Ultra_Cache_WP
    {
        use Ultra_Cache_WP_Settings_Trait;
        use Ultra_Cache_WP_Admin_Trait;
        use Ultra_Cache_WP_Varnish_Trait;
        use Ultra_Cache_WP_Diagnostics_Trait;

        /** @var Ultra_Cache_WP|null */
        private static $instance = null;

        /** @var array|null */
        private static $dashboard_settings_cache = null;

        /** @var array|null */
        private static $settings_cache = null;
        /** @var bool Temporarily suppress automatic cron warm starts while scheduled cleanup purges cache. */
        private static $suppress_after_purge_warm = false;

        public static function instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        private function __construct()
        {
            ucwp_request_profile_checkpoint('ultracache_wp_construct_start');
            $this->load_dependencies();
            ucwp_request_profile_checkpoint('ultracache_dependencies_loaded');
            $this->register_hooks();
            ucwp_request_profile_checkpoint('ultracache_hooks_registered');
        }

        private function load_dependencies()
        {
            $files = array(
                UCWP_PATH . 'includes/class-ultra-cache-engine.php',
                UCWP_PATH . 'includes/class-media-converter.php',
                UCWP_PATH . 'includes/class-object-cache-manager.php',
                UCWP_PATH . 'includes/class-rest-api.php',
                UCWP_PATH . 'includes/class-wp-cli.php',
            );

            foreach ($files as $file) {
                if (file_exists($file)) {
                    ucwp_request_profile_checkpoint('dependency_load_start', array('file' => basename((string) $file)));
                    require_once $file;
                    ucwp_request_profile_checkpoint('dependency_load_end', array('file' => basename((string) $file)));
                }
            }
        }

        private function register_request_profile_hooks()
        {
            if (!ucwp_request_profiler_enabled()) {
                return;
            }

            $hooks = array(
                array('plugins_loaded', -1000, 'plugins_loaded_p-1000'),
                array('plugins_loaded', 0, 'plugins_loaded_p0'),
                array('plugins_loaded', 5, 'plugins_loaded_p5_components'),
                array('plugins_loaded', 18, 'plugins_loaded_p18_before_reconcile'),
                array('plugins_loaded', 19, 'plugins_loaded_p19_before_page_cache_reconcile'),
                array('plugins_loaded', 20, 'plugins_loaded_p20_before_object_cache_reconcile'),
                array('plugins_loaded', 21, 'plugins_loaded_p21_before_runtime_config_reconcile'),
                array('plugins_loaded', 22, 'plugins_loaded_p22_after_reconcile'),
                array('plugins_loaded', PHP_INT_MAX, 'plugins_loaded_end'),
                array('setup_theme', -1000, 'setup_theme_start'),
                array('setup_theme', PHP_INT_MAX, 'setup_theme_end'),
                array('after_setup_theme', -1000, 'after_setup_theme_start'),
                array('after_setup_theme', PHP_INT_MAX, 'after_setup_theme_end'),
                array('init', -1000, 'init_start'),
                array('init', PHP_INT_MAX, 'init_end'),
                array('wp_loaded', -1000, 'wp_loaded_start'),
                array('wp_loaded', PHP_INT_MAX, 'wp_loaded_end'),
                array('template_redirect', -1000, 'template_redirect_global_start'),
                array('template_redirect', PHP_INT_MAX, 'template_redirect_global_end'),
                array('wp_head', -1000, 'wp_head_start'),
                array('wp_head', PHP_INT_MAX, 'wp_head_end'),
                array('shutdown', -1000, 'shutdown_start'),
                array('shutdown', PHP_INT_MAX, 'shutdown_end'),
            );


            foreach ($hooks as $hook) {
                add_action($hook[0], function () use ($hook) {
                    ucwp_request_profile_checkpoint($hook[2]);
                }, $hook[1]);
            }


            if (ucwp_request_callback_profiler_enabled()) {
                add_action('init', function () {
                    ucwp_request_profile_checkpoint('callback_profiler_wrap_init', array('target_priorities' => array('1', '2', '5', '10', '20')));
                    ucwp_request_profile_wrap_hook_callbacks('init', array(1, 2, 5, 10, 20));
                }, 0);


                add_action('wp_loaded', function () {
                    ucwp_request_profile_checkpoint('callback_profiler_wrap_pre_template');
                    ucwp_request_profile_wrap_hook_callbacks('template_redirect');
                    ucwp_request_profile_wrap_hook_callbacks('wp_enqueue_scripts');
                    ucwp_request_profile_wrap_hook_callbacks('wp_calculate_image_srcset');
                    ucwp_request_profile_wrap_hook_callbacks('shutdown');
                }, 1000);

                add_action('wp_head', function () {
                    ucwp_request_profile_checkpoint('callback_profiler_wrap_pre_head_output');
                    ucwp_request_profile_wrap_hook_callbacks('wp_enqueue_scripts');
                    ucwp_request_profile_wrap_hook_callbacks('style_loader_src');
                    ucwp_request_profile_wrap_hook_callbacks('style_loader_tag');
                    ucwp_request_profile_wrap_hook_callbacks('script_loader_src');
                    ucwp_request_profile_wrap_hook_callbacks('script_loader_tag');
                    ucwp_request_profile_wrap_hook_callbacks('wp_calculate_image_srcset');
                }, -999);
            }
        }

        private function register_hooks()
        {
            $this->register_request_profile_hooks();
            add_action('plugins_loaded', array($this, 'bootstrap_components'), 5);
            add_action('plugins_loaded', array($this, 'reconcile_page_cache_dropin'), 19);
            add_action('plugins_loaded', array($this, 'reconcile_object_cache_dropin'), 20);
            add_action('plugins_loaded', array($this, 'reconcile_runtime_config'), 21);
            add_action('admin_menu', array($this, 'register_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            add_action('admin_enqueue_scripts', array($this, 'suppress_conflicting_admin_assets'), 999);
            add_action('admin_print_scripts-toplevel_page_ultracache', array($this, 'suppress_conflicting_admin_assets'), 1);
            add_action('admin_print_footer_scripts-toplevel_page_ultracache', array($this, 'suppress_conflicting_admin_assets'), 1);
            add_action('admin_notices', array($this, 'render_admin_notice'));
            add_action('admin_bar_menu', array($this, 'register_admin_bar_menu'), 100);
            add_action('init', array($this, 'handle_admin_bar_actions'));
            add_filter('cron_schedules', array($this, 'register_cron_schedules'));
            add_action('ucwp_scheduled_cache_cleanup', array($this, 'handle_scheduled_cache_cleanup'));
            add_action('ucwp_cron_warm_tick', array($this, 'handle_cron_warm_tick'));
            add_action('ucwp_cron_warm_tick_kickoff', array($this, 'handle_cron_warm_tick_kickoff'));
            add_action('ucwp_after_purge_all', array($this, 'handle_varnish_after_purge_all'), 10, 1);
            add_action('ucwp_after_purge_all', array($this, 'handle_cron_warm_after_purge_all'), 20, 1);
            add_action('ucwp_after_purge_urls', array($this, 'handle_varnish_after_purge_urls'), 10, 3);
            add_action('wp_loaded', array($this, 'maybe_fix_revslider_footer_conflict'), 1);
        }


        private static function maybe_translate($text)
        {
            $text = (string) $text;

            if ('' === $text || !did_action('init')) {
                return $text;
            }

            switch ($text) {
                case 'PHP Redis extension is not loaded on this server.':
                    return __('PHP Redis extension is not loaded on this server.', 'ultracache');

                case 'Every minute for UltraCache':
                    return __('Every minute for UltraCache', 'ultracache');

                case 'No PHP compression support detected on this server.':
                    return __('No PHP compression support detected on this server.', 'ultracache');

                case 'Brotli and gzip are available. UltraCache will prefer Brotli and fall back to gzip when needed.':
                    return __('Brotli and gzip are available. UltraCache will prefer Brotli and fall back to gzip when needed.', 'ultracache');

                case 'Brotli is available on this server. UltraCache will prefer Brotli compression.':
                    return __('Brotli is available on this server. UltraCache will prefer Brotli compression.', 'ultracache');

                case 'Brotli is not available on this server. UltraCache will use gzip compression instead.':
                    return __('Brotli is not available on this server. UltraCache will use gzip compression instead.', 'ultracache');

                case 'wp-config.php could not be located.':
                    return __('wp-config.php could not be located.', 'ultracache');

                case 'wp-config.php could not be read.':
                    return __('wp-config.php could not be read.', 'ultracache');

                case 'WP_CACHE is managed by UltraCache.':
                    return __('WP_CACHE is managed by UltraCache.', 'ultracache');

                case 'WP_CACHE is already defined as true in wp-config.php.':
                    return __('WP_CACHE is already defined as true in wp-config.php.', 'ultracache');

                case 'WP_CACHE is currently defined as false in wp-config.php and UltraCache will disable that line safely before enabling page cache.':
                    return __('WP_CACHE is currently defined as false in wp-config.php and UltraCache will disable that line safely before enabling page cache.', 'ultracache');

                case 'WP_CACHE is not currently defined in wp-config.php. UltraCache can add it automatically.':
                    return __('WP_CACHE is not currently defined in wp-config.php. UltraCache can add it automatically.', 'ultracache');

                case 'WP_CACHE is defined in a non-standard way in wp-config.php and UltraCache can replace it safely when enabling page cache.':
                    return __('WP_CACHE is defined in a non-standard way in wp-config.php and UltraCache can replace it safely when enabling page cache.', 'ultracache');

                case 'Reverse Proxy Cache':
                    return __('Reverse Proxy Cache', 'ultracache');

                case 'Read failed':
                    return __('Read failed', 'ultracache');

                case 'UltraCache':
                    return __('UltraCache', 'ultracache');

                case 'Strict local SSL verification failed and UltraCache temporarily retried the same-host HTTPS loopback request without certificate verification.':
                    return __('Strict local SSL verification failed and UltraCache temporarily retried the same-host HTTPS loopback request without certificate verification.', 'ultracache');

                case 'Cron warm start suppressed for this purge.':
                    return __('Cron warm start suppressed for this purge.', 'ultracache');

                case 'Cron warm up is disabled.':
                    return __('Cron warm up is disabled.', 'ultracache');

                case 'Cron warm up after scheduled cleanup is disabled.':
                    return __('Cron warm up after scheduled cleanup is disabled.', 'ultracache');

                case 'Cron warm up is paused because pages per minute is 0.':
                    return __('Cron warm up is paused because pages per minute is 0.', 'ultracache');

                case 'Cron warm up is not available.':
                    return __('Cron warm up is not available.', 'ultracache');

                case 'Cron warm up queued.':
                    return __('Cron warm up queued.', 'ultracache');

                case 'Cron warm up stopped.':
                    return __('Cron warm up stopped.', 'ultracache');

                case 'Cron warm up queue is idle.':
                    return __('Cron warm up queue is idle.', 'ultracache');

                case 'Cron warm up tick skipped because another run is active.':
                    return __('Cron warm up tick skipped because another run is active.', 'ultracache');

                case 'Varnish integration is disabled.':
                    return __('Varnish integration is disabled.', 'ultracache');

                case 'No Varnish endpoints are configured.':
                    return __('No Varnish endpoints are configured.', 'ultracache');

                case 'Could not determine site host for Varnish.':
                    return __('Could not determine site host for Varnish.', 'ultracache');

                case 'Invalid URL for Varnish purge.':
                    return __('Invalid URL for Varnish purge.', 'ultracache');

                case 'Could not determine site host for Varnish test.':
                    return __('Could not determine site host for Varnish test.', 'ultracache');

                case 'Redis helper not available.':
                    return __('Redis helper not available.', 'ultracache');

                case 'Object cache helper not available.':
                    return __('Object cache helper not available.', 'ultracache');
            }

            return $text;
        }

        private static function maybe_translate_sprintf($text)
        {
            $args = func_get_args();
            $text = (string) array_shift($args);
            $translated = $text;

            if (did_action('init')) {
                switch ($text) {
                    case 'Every %d hour(s) for UltraCache':
                        /* translators: %d: Number of hours between UltraCache cleanup runs. */
                        $translated = __('Every %d hour(s) for UltraCache', 'ultracache');
                        break;

                    case '%s detected. UltraCache hit counters reflect only requests that reach PHP/advanced-cache and may under-report public hits served before WordPress.':
                        /* translators: %s: Reverse proxy or server cache provider name. */
                        $translated = __('%s detected. UltraCache hit counters reflect only requests that reach PHP/advanced-cache and may under-report public hits served before WordPress.', 'ultracache');
                        break;

                    case 'UltraCache %1$s · Bundle %2$s':
                        /* translators: 1: UltraCache plugin version, 2: hotfix bundle version. */
                        $translated = __('UltraCache %1$s · Bundle %2$s', 'ultracache');
                        break;

                    case 'Varnish %1$s succeeded on %2$d endpoint(s).':
                        /* translators: 1: Varnish action label, 2: number of endpoints. */
                        $translated = __('Varnish %1$s succeeded on %2$d endpoint(s).', 'ultracache');
                        break;

                    case 'Varnish %s failed on one or more endpoints.':
                        /* translators: %s: Varnish action label. */
                        $translated = __('Varnish %s failed on one or more endpoints.', 'ultracache');
                        break;
                }
            }

            if (empty($args)) {
                return $translated;
            }

            return vsprintf($translated, $args);
        }

        public function reconcile_object_cache_dropin()
        {
            ucwp_request_profile_checkpoint('object_cache_reconcile_entry');

            $is_full_reconcile = $this->should_run_full_object_cache_reconcile();
            ucwp_request_profile_checkpoint('object_cache_reconcile_context_checked', array(
                'full_reconcile' => $is_full_reconcile ? 'true' : 'false',
            ));

            if (!class_exists('Ultra_Cache_Object_Cache_Manager')) {
                ucwp_request_profile_checkpoint('object_cache_reconcile_skipped', array('reason' => 'manager_class_missing'));
                return;
            }

            // Frontend traffic performs only a read-only drop-in health check.
            // File writes/removals remain in admin, activation, settings-save, and WP-CLI contexts
            // so WordPress Filesystem API can be used where mutation is required.
            if (!$is_full_reconcile) {
                ucwp_request_profile_checkpoint('object_cache_reconcile_frontend_before_raw_setting');
                $object_cache_enabled = self::is_object_cache_enabled_raw_fast();
                ucwp_request_profile_checkpoint('object_cache_reconcile_frontend_after_raw_setting', array(
                    'object_cache_enabled' => $object_cache_enabled ? 'true' : 'false',
                ));

                if (!$object_cache_enabled) {
                    ucwp_request_profile_checkpoint('object_cache_reconcile_skipped', array('reason' => 'disabled_frontend_fast'));
                    return;
                }

                ucwp_request_profile_checkpoint('object_cache_reconcile_light_start');
                $status = method_exists('Ultra_Cache_Object_Cache_Manager', 'get_dropin_status_fast')
                    ? Ultra_Cache_Object_Cache_Manager::get_dropin_status_fast()
                    : array('healthy' => false, 'reason' => 'status_method_missing');

                if (!empty($status['healthy']) && function_exists('wp_using_ext_object_cache')) {
                    wp_using_ext_object_cache(true);
                }

                ucwp_request_profile_checkpoint('object_cache_reconcile_light_end', array(
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

            ucwp_request_profile_checkpoint('object_cache_reconcile_full_start', array('mode' => 'admin_cli_repair'));
            if (method_exists('Ultra_Cache_Object_Cache_Manager', 'sync_dropin')) {
                $result = Ultra_Cache_Object_Cache_Manager::sync_dropin();
                ucwp_request_profile_checkpoint('object_cache_reconcile_full_end', array(
                    'mode' => 'admin_cli_repair',
                    'result' => $result ? 'true' : 'false',
                ));
                return;
            }

            ucwp_request_profile_checkpoint('object_cache_reconcile_full_end', array(
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

            $saved = get_option(UCWP_SETTINGS_KEY, array());
            $cached = is_array($saved) && !empty($saved['pageCacheEnabled']);

            return $cached;
        }

        private static function is_object_cache_enabled_raw_fast()
        {
            static $cached = null;

            if (null !== $cached) {
                return $cached;
            }

            $saved = get_option(UCWP_SETTINGS_KEY, array());
            $cached = is_array($saved) && !empty($saved['objectCacheEnabled']);

            return $cached;
        }

        public static function are_cache_stats_enabled()
        {
            $settings = defined('UCWP_SETTINGS_KEY') ? get_option(UCWP_SETTINGS_KEY, array()) : array();
            if (!is_array($settings)) {
                return false;
            }

            return !empty($settings['cacheStatsEnabled']) || !empty($settings['cache_stats_enabled']);
        }

        public static function get_cache_stats_disabled_payload($source = 'stats_disabled')
        {
            $opcache = method_exists(__CLASS__, 'get_opcache_status_summary')
                ? self::get_opcache_status_summary()
                : array();
            $apcu = method_exists(__CLASS__, 'get_apcu_status_summary')
                ? self::get_apcu_status_summary()
                : array();

            return array(
                'success' => true,
                'enabled' => false,
                'disabled' => true,
                'cacheStatsEnabled' => false,
                'message' => 'Cache stats are disabled.',
                'impact' => 'off',
                'timestamp' => time(),
                'source' => (string) $source,
                'dashboardStatsDisabled' => true,
                'dashboardStatsDisabledReason' => 'Cache stats are disabled.',
                'dashboardStatsSnapshotCached' => false,
                'dashboardStatsSnapshotAge' => 0,
                'dashboardStatsRefreshInterval' => 0,
                'dashboardStatsPollingDisabled' => true,
                // Cache Statistics OFF must hard-stop counters/scans/polling, but it must
                // not hide unrelated runtime tools. OPcache/APCu status is lightweight
                // admin runtime visibility and keeps the manual flush buttons usable.
                'opcache' => $opcache,
                'apcu' => $apcu,
                'diagnostics' => array(
                    'cacheStats' => array(
                        'enabled' => false,
                        'disabled' => true,
                        'message' => 'When disabled, UltraCache does not collect, refresh, scan, or poll cache statistics. OPcache/APCu runtime status and manual flush controls remain available.',
                    ),
                    'objectCache' => method_exists(__CLASS__, 'get_object_cache_status_diagnostic_lite')
                        ? self::get_object_cache_status_diagnostic_lite()
                        : array(),
                ),
            );
        }

        public static function get_dashboard_stats_snapshot($max_age = 20, $allow_refresh = true)
        {
            $now = time();
            $max_age = max(3, (int) $max_age);

            // Count cache stats OFF is a hard stop for dashboard/stat snapshots.
            // Do not read cached snapshots, refresh engine stats, scan storage,
            // count Redis/APCu keys, scan manifests, or touch analytics here.
            if (!self::are_cache_stats_enabled()) {
                return self::get_cache_stats_disabled_payload('snapshot_disabled');
            }

            $cache_key = defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY . '_dashboard_stats_snapshot_v1' : 'ucwp_dashboard_stats_snapshot_v1';
            $cached = get_transient($cache_key);

            if (is_array($cached) && isset($cached['time'], $cached['stats']) && is_array($cached['stats'])) {
                $age = max(0, $now - (int) $cached['time']);
                if ($age <= $max_age || !$allow_refresh) {
                    $stats = $cached['stats'];
                    $stats['dashboardStatsSnapshotCached'] = true;
                    $stats['dashboardStatsSnapshotAge'] = $age;
                    $stats['dashboardStatsRefreshInterval'] = $max_age;
                    return $stats;
                }
            }

            if (!$allow_refresh) {
                return array(
                    'success' => true,
                    'dashboardStatsSnapshotCached' => false,
                    'dashboardStatsRefreshInterval' => $max_age,
                    'message' => 'Dashboard stats are passive; no refresh was requested.',
                );
            }

            $stats = self::get_engine_stats(false, true);
            $stats = is_array($stats) ? $stats : array();
            $stats['dashboardStatsSnapshotCached'] = false;
            $stats['dashboardStatsSnapshotAge'] = 0;
            $stats['dashboardStatsRefreshInterval'] = $max_age;
            set_transient($cache_key, array('time' => $now, 'stats' => $stats), max(30, $max_age * 2));
            return $stats;
        }

        private static function should_use_live_settings_support_checks()
        {
            if (defined('WP_CLI') && WP_CLI) {
                return true;
            }

            if (defined('REST_REQUEST') && REST_REQUEST) {
                return true;
            }

            if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
                return true;
            }

            if (function_exists('is_admin') && is_admin()) {
                return true;
            }

            return false;
        }

        public function reconcile_page_cache_dropin()
        {
            ucwp_request_profile_checkpoint('page_cache_reconcile_entry');

            $is_full_reconcile = $this->should_run_full_page_cache_reconcile();
            ucwp_request_profile_checkpoint('page_cache_reconcile_context_checked', array(
                'full_reconcile' => $is_full_reconcile ? 'true' : 'false',
            ));

            // Frontend traffic must stay cheap and read-only. Do not call the full
            // dashboard settings sanitizer here because it may canonicalize/update the
            // settings option. File writes and repairs remain in admin/CLI contexts.
            if (!$is_full_reconcile) {
                ucwp_request_profile_checkpoint('page_cache_reconcile_frontend_before_raw_setting');
                $page_cache_enabled = self::is_page_cache_enabled_raw_fast();
                ucwp_request_profile_checkpoint('page_cache_reconcile_frontend_after_raw_setting', array(
                    'page_cache_enabled' => $page_cache_enabled ? 'true' : 'false',
                ));

                if (!$page_cache_enabled || !defined('WP_CACHE') || !WP_CACHE) {
                    ucwp_request_profile_checkpoint('page_cache_reconcile_skipped', array('reason' => 'disabled_or_wp_cache_false_frontend_fast'));
                    return;
                }

                ucwp_request_profile_checkpoint('page_cache_reconcile_frontend_before_engine_class');
                $engine_class = self::get_engine_class();
                ucwp_request_profile_checkpoint('page_cache_reconcile_frontend_after_engine_class', array(
                    'engine_class' => $engine_class ? 'true' : 'false',
                ));
                if (!$engine_class) {
                    ucwp_request_profile_checkpoint('page_cache_reconcile_skipped', array('reason' => 'engine_class_missing'));
                    return;
                }

                ucwp_request_profile_checkpoint('page_cache_reconcile_light_start');
                $status = method_exists($engine_class, 'get_advanced_cache_dropin_status')
                    ? $engine_class::get_advanced_cache_dropin_status()
                    : array('healthy' => false, 'reason' => 'status_method_missing');

                ucwp_request_profile_checkpoint('page_cache_reconcile_light_end', array(
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

            ucwp_request_profile_checkpoint('page_cache_reconcile_full_before_settings');
            $settings = self::get_dashboard_settings();
            ucwp_request_profile_checkpoint('page_cache_reconcile_full_after_settings', array(
                'page_cache_enabled' => !empty($settings['pageCacheEnabled']) ? 'true' : 'false',
            ));

            if (empty($settings['pageCacheEnabled']) || !defined('WP_CACHE') || !WP_CACHE) {
                ucwp_request_profile_checkpoint('page_cache_reconcile_skipped', array('reason' => 'disabled_or_wp_cache_false_admin_cli'));
                return;
            }

            $engine_class = self::get_engine_class();
            if (!$engine_class) {
                ucwp_request_profile_checkpoint('page_cache_reconcile_skipped', array('reason' => 'engine_class_missing'));
                return;
            }

            ucwp_request_profile_checkpoint('page_cache_reconcile_full_start', array('mode' => 'admin_cli_repair'));
            if (method_exists($engine_class, 'setup_advanced_cache')) {
                $engine_class::setup_advanced_cache(true);
            }
            ucwp_request_profile_checkpoint('page_cache_reconcile_full_end', array('mode' => 'admin_cli_repair'));
        }

        public function reconcile_runtime_config()
        {
            ucwp_request_profile_checkpoint('runtime_config_reconcile_entry');

            $is_full_reconcile = $this->should_run_full_runtime_config_reconcile();
            ucwp_request_profile_checkpoint('runtime_config_reconcile_context_checked', array(
                'full_reconcile' => $is_full_reconcile ? 'true' : 'false',
            ));

            if (!$is_full_reconcile) {
                ucwp_request_profile_checkpoint('runtime_config_reconcile_frontend_before_raw_setting');
                $page_cache_enabled = self::is_page_cache_enabled_raw_fast();
                ucwp_request_profile_checkpoint('runtime_config_reconcile_frontend_after_raw_setting', array(
                    'page_cache_enabled' => $page_cache_enabled ? 'true' : 'false',
                ));

                if (!$page_cache_enabled || !defined('WP_CACHE') || !WP_CACHE) {
                    ucwp_request_profile_checkpoint('runtime_config_reconcile_skipped', array('reason' => 'disabled_or_wp_cache_false_frontend_fast'));
                    return;
                }

                ucwp_request_profile_checkpoint('runtime_config_reconcile_light_start');
                $status = self::get_runtime_config_status_fast();
                ucwp_request_profile_checkpoint('runtime_config_reconcile_light_end', array(
                    'mode' => 'read_only_frontend',
                    'healthy' => !empty($status['healthy']) ? 'true' : 'false',
                    'reason' => isset($status['reason']) ? (string) $status['reason'] : '',
                    'exists' => !empty($status['exists']) ? 'true' : 'false',
                    'readable' => !empty($status['readable']) ? 'true' : 'false',
                    'valid_config' => !empty($status['valid_config']) ? 'true' : 'false',
                ));
                return;
            }

            ucwp_request_profile_checkpoint('runtime_config_reconcile_full_before_settings');
            $settings = self::get_dashboard_settings();
            ucwp_request_profile_checkpoint('runtime_config_reconcile_full_after_settings', array(
                'page_cache_enabled' => !empty($settings['pageCacheEnabled']) ? 'true' : 'false',
            ));

            if (empty($settings['pageCacheEnabled']) || !defined('WP_CACHE') || !WP_CACHE) {
                ucwp_request_profile_checkpoint('runtime_config_reconcile_skipped', array('reason' => 'disabled_or_wp_cache_false_admin_cli'));
                return;
            }

            ucwp_request_profile_checkpoint('runtime_config_reconcile_full_before_needs_sync');
            $needs_sync = self::runtime_config_needs_sync();
            ucwp_request_profile_checkpoint('runtime_config_reconcile_full_after_needs_sync', array(
                'needs_sync' => $needs_sync ? 'true' : 'false',
            ));

            if ($needs_sync) {
                ucwp_request_profile_checkpoint('runtime_config_reconcile_full_before_sync');
                self::sync_runtime_config();
                ucwp_request_profile_checkpoint('runtime_config_reconcile_full_after_sync');
            }
        }

        private function should_run_full_runtime_config_reconcile()
        {
            // Runtime config writes are treated like drop-in/file mutations: WP-CLI
            // or a real plugin-management admin only, never plain is_admin().
            return self::can_run_privileged_file_mutations();
        }

        public function bootstrap_components()
        {
            $component_classes = array(
                array('Ultra_Cache_Engine', 'get_instance'),
                array('Ultra_Cache_Media_Converter', 'get_instance'),
                array('Ultra_Cache_Rest_API', 'get_instance'),
                array('Ultra_Cache_WP_CLI', 'register'),
            );

            foreach ($component_classes as $component) {
                list($class, $method) = $component;
                if (class_exists($class) && method_exists($class, $method)) {
                    call_user_func(array($class, $method));
                }
            }
        }

        public static function activate()
        {
            self::ensure_directories();

            // Do not create a full settings row on first install. Missing boolean
            // switches are treated as off at runtime; non-boolean settings use safe
            // runtime fallbacks until the user explicitly saves settings or applies a profile.
            self::reset_settings_cache();

            self::sync_page_cache_bootstrap();
            self::sync_runtime_config();
            self::sync_scheduled_events();
            $media = self::get_media_instance();
            if ($media && method_exists($media, 'ensure_media_queue_table')) {
                $media->ensure_media_queue_table();
            }
            $browser_cache_sync = self::sync_browser_cache_rules();
            if (false === $browser_cache_sync) {
                set_transient(
                    'ucwp_admin_notice',
                    array(
                        'type'    => 'warning',
                        'message' => self::maybe_translate('UltraCache: Browser Cache Headers are enabled, but .htaccess could not be updated during activation. Check file permissions or disable Browser Cache Headers.'),
                    ),
                    90
                );
            }

            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'sync_dropin')) {
                Ultra_Cache_Object_Cache_Manager::sync_dropin();
                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache')) {
                    Ultra_Cache_Object_Cache_Manager::flush_cache(true, true);
                }
            }
        }

        public static function deactivate()
        {
            self::sync_page_cache_bootstrap(false);
            self::unschedule_scheduled_events();
            self::unschedule_cron_warm_events();
            self::sync_browser_cache_rules(false);

            if (class_exists('Ultra_Cache_Object_Cache_Manager')) {
                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache')) {
                    Ultra_Cache_Object_Cache_Manager::flush_cache(true, true);
                }
                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'maybe_remove_dropin')) {
                    Ultra_Cache_Object_Cache_Manager::maybe_remove_dropin();
                }
            }
        }

        private static function ensure_directories()
        {
            $dirs = array(
                UCWP_CACHE_DIR,
                UCWP_AVIF_DIR,
                UCWP_WEBP_DIR,
                UCWP_OBJECT_CACHE_DIR,
                trailingslashit(UCWP_CACHE_DIR) . 'google-fonts/',
            );

            foreach ($dirs as $dir) {
                if (!file_exists($dir)) {
                    wp_mkdir_p($dir);
                }

                $index = trailingslashit($dir) . 'index.php';
                if (!file_exists($index)) {
                    ucwp_safe_file_put_contents($index, "<?php\n// Silence is golden.\n", 0, 'ensure_directories index');
                }
            }

            self::ensure_runtime_config_protection_files();
        }

        // Settings methods live in includes/settings/class-settings-trait.php.

        private static function get_runtime_config_path()
        {
            return trailingslashit(UCWP_CACHE_DIR) . 'runtime-config.php';
        }

        private static function get_legacy_runtime_config_json_path()
        {
            return trailingslashit(UCWP_CACHE_DIR) . 'runtime-config.json';
        }

        private static function get_runtime_secret_site_token()
        {
            $site_root = wp_normalize_path(untrailingslashit(ABSPATH));
            $token = wp_basename($site_root);
            $token = is_string($token) ? strtolower($token) : '';
            $token = preg_replace('/[^a-z0-9._-]+/', '-', $token);
            $token = trim((string) $token, '.-_');

            if ('' === $token) {
                $token = 'site';
            }

            return $token;
        }

        private static function get_runtime_secret_path()
        {
            $base = dirname(untrailingslashit(ABSPATH));
            if (!is_string($base) || '' === trim($base) || '.' === $base || '/' === $base) {
                $base = dirname(untrailingslashit(WP_CONTENT_DIR));
            }

            return rtrim($base, '/\\') . '/.' . self::get_runtime_secret_site_token() . '-ultracache-runtime-secrets.php';
        }

        private static function normalize_runtime_secret_array(array $loaded)
        {
            $varnish_admin_secret = '';
            if (isset($loaded['varnish_admin_secret'])) {
                $varnish_admin_secret = (string) $loaded['varnish_admin_secret'];
            } elseif (isset($loaded['varnish_cli_key'])) {
                $varnish_admin_secret = (string) $loaded['varnish_cli_key'];
            }

            return array(
                'revalidate_secret' => isset($loaded['revalidate_secret']) ? (string) $loaded['revalidate_secret'] : '',
                'redis_password' => isset($loaded['redis_password']) ? (string) $loaded['redis_password'] : '',
                'varnish_admin_secret' => $varnish_admin_secret,
            );
        }

        private static function get_runtime_redis_password()
        {
            $runtime = self::load_runtime_secret_file();
            return isset($runtime['redis_password']) ? trim((string) $runtime['redis_password']) : '';
        }

        private static function set_runtime_redis_password($password)
        {
            $runtime = self::load_runtime_secret_file();
            $runtime['redis_password'] = trim((string) $password);
            $written = self::write_runtime_secret_array($runtime);
            if ($written) {
                self::reset_settings_cache();
                clearstatcache(true, self::get_runtime_secret_path());
            }
            return $written;
        }

        private static function get_runtime_varnish_admin_secret()
        {
            $runtime = self::load_runtime_secret_file();
            return isset($runtime['varnish_admin_secret']) ? trim((string) $runtime['varnish_admin_secret']) : '';
        }

        private static function write_runtime_secret_array(array $runtime)
        {
            $normalized = self::normalize_runtime_secret_array($runtime);
            $path = self::get_runtime_secret_path();
            $written = self::write_file_atomically($path, self::render_runtime_secret_php($normalized), 'runtime_secret write');
            if ($written && function_exists('ucwp_safe_chmod')) {
                ucwp_safe_chmod($path, 0600, 'runtime_secret permissions');
            }

            return $written;
        }

        private static function ensure_runtime_revalidate_secret()
        {
            $runtime = self::load_runtime_secret_file();
            $expected = wp_hash('ucwp-revalidate-v1');

            if (isset($runtime['revalidate_secret']) && hash_equals((string) $runtime['revalidate_secret'], (string) $expected)) {
                return true;
            }

            $runtime['revalidate_secret'] = (string) $expected;

            return self::write_runtime_secret_array($runtime);
        }

        private static function set_runtime_varnish_admin_secret($secret)
        {
            $runtime = self::load_runtime_secret_file();
            $runtime['varnish_admin_secret'] = trim((string) $secret);
            $written = self::write_runtime_secret_array($runtime);
            if ($written) {
                self::reset_settings_cache();
                clearstatcache(true, self::get_runtime_secret_path());
            }
            return $written;
        }

        private static function ensure_runtime_config_protection_files()
        {
            $runtime_dir = dirname(self::get_runtime_config_path());
            if (!file_exists($runtime_dir) && !ucwp_safe_mkdir($runtime_dir, 0755, true, 'ensure_runtime_config_protection_files mkdir') && !file_exists($runtime_dir)) {
                return;
            }

            $htaccess = trailingslashit($runtime_dir) . '.htaccess';
            $htaccess_rules = "<FilesMatch \"^runtime-config\\.(php|json)$\">\nRequire all denied\n</FilesMatch>\n<IfModule !mod_authz_core.c>\n<FilesMatch \"^runtime-config\\.(php|json)$\">\nDeny from all\n</FilesMatch>\n</IfModule>\n";
            if (!file_exists($htaccess)) {
                ucwp_safe_file_put_contents($htaccess, $htaccess_rules, 0, 'runtime_config htaccess');
            } else {
                $existing_htaccess = ucwp_safe_file_get_contents($htaccess, 'runtime_config htaccess read');
                if (is_string($existing_htaccess) && false === strpos($existing_htaccess, 'runtime-config.php')) {
                    ucwp_safe_file_put_contents($htaccess, rtrim($existing_htaccess) . "\n\n" . $htaccess_rules, 0, 'runtime_config htaccess append');
                }
            }

            $web_config = trailingslashit($runtime_dir) . 'web.config';
            $web_config_rules = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <location path=\"runtime-config.php\">\n    <system.webServer>\n      <security>\n        <authorization>\n          <clear />\n          <add accessType=\"Deny\" users=\"*\" />\n        </authorization>\n      </security>\n    </system.webServer>\n  </location>\n  <location path=\"runtime-config.json\">\n    <system.webServer>\n      <security>\n        <authorization>\n          <clear />\n          <add accessType=\"Deny\" users=\"*\" />\n        </authorization>\n      </security>\n    </system.webServer>\n  </location>\n</configuration>\n";
            if (!file_exists($web_config)) {
                ucwp_safe_file_put_contents($web_config, $web_config_rules, 0, 'runtime_config web_config');
            } else {
                $existing_web_config = ucwp_safe_file_get_contents($web_config, 'runtime_config web_config read');
                if (is_string($existing_web_config) && false === strpos($existing_web_config, 'runtime-config.php')) {
                    ucwp_safe_file_put_contents($web_config, $web_config_rules, 0, 'runtime_config web_config update');
                }
            }
        }

        private static function render_runtime_secret_php(array $runtime)
        {
            $runtime = self::normalize_runtime_secret_array($runtime);
            $secret = isset($runtime['revalidate_secret']) ? (string) $runtime['revalidate_secret'] : '';
            $redis_password = isset($runtime['redis_password']) ? (string) $runtime['redis_password'] : '';
            $varnish_admin_secret = isset($runtime['varnish_admin_secret']) ? (string) $runtime['varnish_admin_secret'] : '';
            return "<?php\n/** UltraCache managed runtime secrets. */\nif (!defined('ABSPATH')) {\n    exit;\n}\nreturn array(\n    'revalidate_secret' => " . ucwp_php_string_literal($secret) . ",\n    'redis_password' => " . ucwp_php_string_literal($redis_password) . ",\n    'varnish_admin_secret' => " . ucwp_php_string_literal($varnish_admin_secret) . ",\n);\n";
        }

        private static function load_runtime_secret_file($path = null)
        {
            $candidates = array();
            if (is_string($path) && '' !== trim($path)) {
                $candidates[] = $path;
            } else {
                $candidates[] = self::get_runtime_secret_path();
            }

            foreach ($candidates as $candidate) {
                if (!is_string($candidate) || '' === trim($candidate) || !file_exists($candidate) || !is_readable($candidate)) {
                    continue;
                }

                clearstatcache(true, $candidate);
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($candidate, true);
                }

                $loaded = require $candidate;
                if (!is_array($loaded)) {
                    continue;
                }

                return self::normalize_runtime_secret_array($loaded);
            }

            return array();
        }

        private static function build_runtime_config()
        {
            $settings = self::get_settings();

            return self::normalize_runtime_config(array(
                'excluded_paths'                  => $settings['excluded_paths'],
                'excluded_query_args'             => array_values(array_unique(array_merge((array) $settings['excluded_query_args'], array('ucwp_runtime_js_scan', 'ucwp_runtime_js_scan_id', 'ucwp_runtime_js_scan_nonce')))),
                'cache_query_strings'             => !empty($settings['cache_query_strings']),
                'cache_query_allowlist'           => !empty($settings['cache_query_allowlist']) ? self::parse_textarea_setting(self::sanitize_setting_key_list((array) $settings['cache_query_allowlist'])) : array(),
                'woo_safe_mode'                   => !empty($settings['woo_safe_mode']),
                'cache_stats_enabled'             => !empty($settings['cache_stats_enabled']),
                'object_cache_enabled'            => !empty($settings['object_cache_enabled']),
                'object_cache_backend'            => self::sanitize_object_cache_backend($settings['object_cache_backend'] ?? 'redis'),
                'object_cache_fallback_backend'   => self::sanitize_object_cache_fallback_backend($settings['object_cache_fallback_backend'] ?? 'apcu'),
                'redis_host'                      => (string) ($settings['redis_host'] ?? '127.0.0.1'),
                'redis_port'                      => max(1, absint($settings['redis_port'] ?? 6379)),
                'redis_username'                  => sanitize_text_field((string) ($settings['redis_username'] ?? '')),
                'redis_password'                  => (string) ($settings['redis_password'] ?? ''),
                'redis_database'                  => max(0, absint($settings['redis_database'] ?? 0)),
                'redis_prefix'                    => preg_replace('/[^A-Za-z0-9:_\\-]/', '', (string) ($settings['redis_prefix'] ?? '')),
                'redis_use_tls'                   => !empty($settings['redis_use_tls']),
                'redis_persistent'                => !empty($settings['redis_persistent']),
                'redis_connect_timeout_ms'        => max(50, absint($settings['redis_connect_timeout_ms'] ?? 200)),
                'redis_read_timeout_ms'           => max(50, absint($settings['redis_read_timeout_ms'] ?? 200)),
                'stale_while_revalidate_enabled'  => !empty($settings['stale_while_revalidate_enabled']),
                'cache_fresh_ttl_minutes'         => max(1, absint($settings['cache_fresh_ttl_minutes'])),
                'cache_max_stale_minutes'         => max(absint($settings['cache_fresh_ttl_minutes']), absint($settings['cache_max_stale_minutes'])),
                'revalidate_secret'               => wp_hash('ucwp-revalidate-v1'),
                'varnish_admin_secret'            => (string) ($settings['varnish_cli_key'] ?? ''),
                'trusted_hosts'                   => ucwp_get_trusted_hosts(),
            ));
        }

        private static function load_runtime_config_public_file($path)
        {
            if (!file_exists($path) || !is_readable($path)) {
                return new WP_Error('ucwp_runtime_config_missing', 'runtime-config.php is missing or not readable.');
            }

            $extension = strtolower((string) pathinfo((string) $path, PATHINFO_EXTENSION));
            if ('php' === $extension) {
                clearstatcache(true, $path);
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($path, true);
                }

                $loaded = require $path;
                if (!is_array($loaded)) {
                    return new WP_Error('ucwp_runtime_config_invalid', 'runtime-config.php did not return a valid array.');
                }

                return $loaded;
            }

            $raw = ucwp_safe_file_get_contents($path, 'load_runtime_config_file');
            if (false === $raw || '' === $raw) {
                return new WP_Error('ucwp_runtime_config_load_failed', 'runtime-config file could not be read.');
            }

            $loaded = json_decode($raw, true);
            if (!is_array($loaded)) {
                return new WP_Error('ucwp_runtime_config_invalid', 'runtime-config file did not contain a valid JSON object.');
            }

            return $loaded;
        }

        private static function load_runtime_config_file($path)
        {
            $loaded = self::load_runtime_config_public_file($path);
            if (is_wp_error($loaded)) {
                $legacy_json = self::get_legacy_runtime_config_json_path();
                if ($path !== $legacy_json && file_exists($legacy_json) && is_readable($legacy_json)) {
                    $loaded = self::load_runtime_config_public_file($legacy_json);
                }
            }

            if (is_wp_error($loaded)) {
                return $loaded;
            }

            $loaded = array_merge($loaded, self::load_runtime_secret_file());

            return self::normalize_runtime_config($loaded);
        }

        private static function render_runtime_config_php(array $runtime)
        {
            $public_runtime = self::normalize_runtime_config($runtime);
            unset($public_runtime['revalidate_secret'], $public_runtime['redis_password'], $public_runtime['varnish_admin_secret']);

            $json = wp_json_encode($public_runtime, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json)) {
                $json = '{}';
            }

            $encoded_json = str_replace(array('\\', "'"), array('\\\\', "\\'"), $json);

            return "<?php\n/** UltraCache managed runtime config. Secret-free by design. */\nif (!defined('ABSPATH')) {\n    exit;\n}\n\$ucwp_runtime_config = json_decode('" . $encoded_json . "', true);\nreturn is_array(\$ucwp_runtime_config) ? \$ucwp_runtime_config : array();\n";
        }

        private static function write_file_atomically($target, $contents, $context)
        {
            $dir = dirname($target);
            if (!file_exists($dir) && !ucwp_safe_mkdir($dir, 0755, true, $context . ' mkdir') && !file_exists($dir)) {
                return false;
            }

            $tmp = trailingslashit($dir) . '.' . wp_basename($target) . '.tmp-' . wp_generate_password(8, false, false);
            if (false === ucwp_safe_file_put_contents($tmp, $contents, LOCK_EX, $context . ' tmp')) {
                ucwp_safe_unlink($tmp, $context . ' tmp cleanup');
                return false;
            }

            if (!ucwp_safe_rename($tmp, $target, $context . ' rename')) {
                ucwp_safe_unlink($tmp, $context . ' rename cleanup');
                return false;
            }

            clearstatcache(true, $target);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($target, true);
            }

            return true;
        }

        private static function get_runtime_config_status_fast()
        {
            $path = self::get_runtime_config_path();
            $status = array(
                'exists' => file_exists($path),
                'readable' => is_readable($path),
                'valid_config' => false,
                'valid_json' => false,
                'healthy' => false,
                'reason' => '',
            );

            if (!$status['exists']) {
                $status['reason'] = 'missing';
                return $status;
            }

            if (!$status['readable']) {
                $status['reason'] = 'not_readable';
                return $status;
            }

            $loaded = self::load_runtime_config_public_file($path);
            $status['valid_config'] = is_array($loaded);
            $status['valid_json'] = false;
            $status['healthy'] = $status['valid_config'];
            $status['reason'] = $status['healthy'] ? 'present_valid_php' : 'invalid_config';

            return $status;
        }

        private static function runtime_config_needs_sync()
        {
            $config_path = self::get_runtime_config_path();
            if (!file_exists($config_path) || !is_readable($config_path)) {
                return true;
            }

            $loaded = self::load_runtime_config_file($config_path);
            if (is_wp_error($loaded)) {
                return true;
            }

            $expected = self::build_runtime_config();
            foreach (array('revalidate_secret', 'redis_password', 'varnish_admin_secret') as $secret_key) {
                unset($loaded[$secret_key], $expected[$secret_key]);
            }

            return $loaded !== $expected;
        }

        private static function normalize_runtime_config(array $runtime)
        {
            $defaults = self::get_dashboard_defaults();
            $fresh_minutes = self::sanitize_bounded_integer_setting($runtime['cache_fresh_ttl_minutes'] ?? $defaults['cacheFreshTtlMinutes'], $defaults['cacheFreshTtlMinutes'], 1, 1440);
            $max_stale_minutes = self::sanitize_bounded_integer_setting($runtime['cache_max_stale_minutes'] ?? $defaults['cacheMaxStaleMinutes'], $defaults['cacheMaxStaleMinutes'], $fresh_minutes, 10080);

            $normalized = array(
                'excluded_paths'                 => self::parse_textarea_setting(self::sanitize_excluded_paths_setting((array) ($runtime['excluded_paths'] ?? array()))),
                'excluded_query_args'            => self::parse_textarea_setting(self::sanitize_setting_key_list((array) ($runtime['excluded_query_args'] ?? array()))),
                'cache_query_strings'            => !empty($runtime['cache_query_strings']),
                'cache_query_allowlist'          => self::parse_textarea_setting(self::sanitize_setting_key_list((array) ($runtime['cache_query_allowlist'] ?? array()))),
                'woo_safe_mode'                  => !empty($runtime['woo_safe_mode']),
                'cache_stats_enabled'            => !empty($runtime['cache_stats_enabled']),
                'object_cache_enabled'           => !empty($runtime['object_cache_enabled']),
                'object_cache_backend'           => self::sanitize_object_cache_backend($runtime['object_cache_backend'] ?? 'redis'),
                'object_cache_fallback_backend'  => self::sanitize_object_cache_fallback_backend($runtime['object_cache_fallback_backend'] ?? 'apcu'),
                'redis_host'                     => trim((string) ($runtime['redis_host'] ?? '127.0.0.1')) ?: '127.0.0.1',
                'redis_port'                     => max(1, min(65535, absint($runtime['redis_port'] ?? 6379))),
                'redis_username'                 => sanitize_text_field((string) ($runtime['redis_username'] ?? '')),
                'redis_password'                 => (string) ($runtime['redis_password'] ?? ''),
                'redis_database'                 => max(0, absint($runtime['redis_database'] ?? 0)),
                'redis_prefix'                   => preg_replace('/[^A-Za-z0-9:_\\-]/', '', (string) ($runtime['redis_prefix'] ?? '')),
                'redis_use_tls'                  => !empty($runtime['redis_use_tls']),
                'redis_persistent'               => !empty($runtime['redis_persistent']),
                'redis_connect_timeout_ms'       => max(50, min(10000, absint($runtime['redis_connect_timeout_ms'] ?? 200))),
                'redis_read_timeout_ms'          => max(50, min(10000, absint($runtime['redis_read_timeout_ms'] ?? 200))),
                'stale_while_revalidate_enabled' => !empty($runtime['stale_while_revalidate_enabled']),
                'cache_fresh_ttl_minutes'        => $fresh_minutes,
                'cache_max_stale_minutes'        => $max_stale_minutes,
                'revalidate_secret'              => (string) ($runtime['revalidate_secret'] ?? ''),
                'varnish_admin_secret'           => (string) ($runtime['varnish_admin_secret'] ?? ''),
                'trusted_hosts'                  => array_values(array_filter(array_map('ucwp_normalize_host', (array) ($runtime['trusted_hosts'] ?? ucwp_get_trusted_hosts())))),
            );

            sort($normalized['excluded_paths']);
            sort($normalized['excluded_query_args']);
            sort($normalized['cache_query_allowlist']);

            return $normalized;
        }

        public static function sync_runtime_config()
        {
            self::ensure_directories();

            // Keep user-provided Redis/Varnish secrets write-only from explicit
            // admin saves. Runtime config sync may only ensure the internal
            // revalidate token and must preserve any existing secret values.
            self::ensure_runtime_revalidate_secret();

            $runtime         = self::build_runtime_config();
            $config_target   = self::get_runtime_config_path();
            $config_contents = self::render_runtime_config_php($runtime);
            $written = self::write_file_atomically($config_target, $config_contents, 'sync_runtime_config');
            if ($written) {
                $legacy_json = self::get_legacy_runtime_config_json_path();
                if (file_exists($legacy_json)) {
                    ucwp_safe_unlink($legacy_json, 'sync_runtime_config legacy_json_cleanup');
                }
            }

            return $written;
        }

        private static function get_cache_cleanup_schedule_name($hours)
        {
            return 'ucwp_every_' . max(1, absint($hours)) . '_hours';
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

            if (empty($schedules['ucwp_every_minute'])) {
                $schedules['ucwp_every_minute'] = array(
                    'interval' => MINUTE_IN_SECONDS,
                    'display'  => self::maybe_translate('Every minute for UltraCache'),
                );
            }

            return $schedules;
        }

        public static function unschedule_scheduled_events()
        {
            $timestamp = wp_next_scheduled('ucwp_scheduled_cache_cleanup');
            while ($timestamp) {
                wp_unschedule_event($timestamp, 'ucwp_scheduled_cache_cleanup');
                $timestamp = wp_next_scheduled('ucwp_scheduled_cache_cleanup');
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



        private static function unschedule_cron_warm_events()
        {
            $timestamp = wp_next_scheduled('ucwp_cron_warm_tick');
            while ($timestamp) {
                wp_unschedule_event($timestamp, 'ucwp_cron_warm_tick');
                $timestamp = wp_next_scheduled('ucwp_cron_warm_tick');
            }

            $kickoff_timestamp = wp_next_scheduled('ucwp_cron_warm_tick_kickoff');
            while ($kickoff_timestamp) {
                wp_unschedule_event($kickoff_timestamp, 'ucwp_cron_warm_tick_kickoff');
                $kickoff_timestamp = wp_next_scheduled('ucwp_cron_warm_tick_kickoff');
            }
        }

        public static function sync_scheduled_events()
        {
            self::unschedule_scheduled_events();

            $settings = self::get_settings();
            if (!empty($settings['cache_cleanup_enabled'])) {
                $hours    = max(1, absint($settings['cache_cleanup_interval_hours']));
                $schedule = self::get_cache_cleanup_schedule_name($hours);
                wp_schedule_event(time() + MINUTE_IN_SECONDS, $schedule, 'ucwp_scheduled_cache_cleanup');
            }
        }

        private static function has_cron_warm_recurring_event_scheduled()
        {
            return false !== wp_next_scheduled('ucwp_cron_warm_tick');
        }

        private static function get_next_cron_warm_scheduled_at()
        {
            $times = array();
            $main = wp_next_scheduled('ucwp_cron_warm_tick');
            if ($main) {
                $times[] = (int) $main;
            }

            $kickoff = wp_next_scheduled('ucwp_cron_warm_tick_kickoff');
            if ($kickoff) {
                $times[] = (int) $kickoff;
            }

            return empty($times) ? 0 : min($times);
        }

        private static function ensure_cron_warm_events_scheduled($kickoff_delay = null)
        {
            if (!self::has_cron_warm_recurring_event_scheduled()) {
                wp_schedule_event(time() + MINUTE_IN_SECONDS, 'ucwp_every_minute', 'ucwp_cron_warm_tick');
            }

            if (null !== $kickoff_delay && !wp_next_scheduled('ucwp_cron_warm_tick_kickoff')) {
                $kickoff_delay = max(1, min(300, (int) $kickoff_delay));
                wp_schedule_single_event(time() + $kickoff_delay, 'ucwp_cron_warm_tick_kickoff');
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

        public static function run_scheduled_cache_cleanup()
        {
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

            return array(
                'success' => ($purged || $object_cache_removed > 0 || $apcu_flushed || $css_files_before !== $css_files_after || !empty($runtime_artifacts_cleanup['deleted'])),
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
            );
        }

        public static function cleanup_runtime_artifacts(array $args = array())
        {
            $dry_run = !empty($args['dry_run']);
            $max_age_seconds = isset($args['max_age_seconds']) ? max(60, (int) $args['max_age_seconds']) : 600;
            $now = time();
            $locks_dir = trailingslashit(UCWP_CACHE_DIR) . 'locks/';

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

            $items = scandir($locks_dir);
            if (!is_array($items)) {
                $result['success'] = false;
                $result['message'] = 'Unable to read runtime locks directory.';
                return $result;
            }

            $runtime_lock_pattern = '/^(?:purge-all|page-cache-(?:write|build)-[a-f0-9]{32}|css-(?:bundle|entry)-[a-f0-9]{32})\.lock$/i';
            $test_artifact_pattern = '/^(?:baseline-dummy|verify-dummy(?:-[a-z0-9_.-]+)?|ucwp-test-[a-z0-9_.-]+|ultracache-test-[a-z0-9_.-]+)\.lock$/i';

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

                $mtime = function_exists('ucwp_safe_filemtime') ? ucwp_safe_filemtime($path, 'runtime_artifact_cleanup') : filemtime($path);
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

                $deleted = function_exists('ucwp_safe_unlink') ? ucwp_safe_unlink($path, 'runtime_artifact_cleanup') : false;
                if ($handle) {
                    @flock($handle, LOCK_UN);
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing native flock probe handle after delete attempt.
                    @fclose($handle);
                }

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


        private static function get_default_cron_warm_state()
        {
            return array(
                'active'       => false,
                'reason'       => '',
                'cursor'       => '',
                'processed'    => 0,
                'total'        => 0,
                'successCount' => 0,
                'errorCount'   => 0,
                'startedAt'    => 0,
                'updatedAt'    => 0,
                'lastRunAt'    => 0,
                'finishedAt'   => 0,
                'pagesPerMinute' => 15,
                'totalLimit'   => 0,
                'currentBatch' => array(),
                'batchIndex'   => 0,
                'batchHasMore' => false,
                'nextCursorPending' => '',
                'lastError'    => '',
                'lastMessage'  => '',
                'lastUrl'      => '',
                'completed'    => false,
                'stopped'      => false,
                'stopReason'   => '',
                'invokedBy'    => '',
            );
        }

        public static function get_cron_warm_state()
        {
            $state = get_option(UCWP_CRON_WARM_STATE_KEY, array());
            if (!is_array($state)) {
                $state = array();
            }

            return array_merge(self::get_default_cron_warm_state(), $state);
        }

        private static function save_cron_warm_state(array $state)
        {
            $state = array_merge(self::get_default_cron_warm_state(), $state);
            if (false === get_option(UCWP_CRON_WARM_STATE_KEY, false)) {
                add_option(UCWP_CRON_WARM_STATE_KEY, $state, '', 'no');
            } else {
                update_option(UCWP_CRON_WARM_STATE_KEY, $state);
            }
            return $state;
        }

        public static function reset_cron_warmup_queue_after_cache_flush($reason = 'cache_flush')
        {
            $state = self::get_default_cron_warm_state();
            $state['active'] = false;
            $state['stopped'] = true;
            $state['completed'] = false;
            $state['stopReason'] = sanitize_key((string) $reason);
            $state['finishedAt'] = time();
            $state['updatedAt'] = time();
            $state['lastMessage'] = self::maybe_translate('Cron warm up queue reset after cache flush.');
            self::save_cron_warm_state($state);
            self::unschedule_cron_warm_events();
            return self::get_cron_warm_status();
        }

        private static function schedule_next_cron_warm_tick($delay_seconds = 5)
        {
            self::ensure_cron_warm_events_scheduled($delay_seconds);
        }

        private static function get_cron_warm_server_cron_command()
        {
            $path = rtrim(ABSPATH, '/\\');
            if ('' === $path) {
                $path = '.';
            }

            return '* * * * * cd ' . escapeshellarg($path) . ' && wp ultracache cron_warm tick --path=' . escapeshellarg($path) . ' >/dev/null 2>&1';
        }

        public static function get_cron_warm_status()
        {
            $settings = self::get_settings();
            $state = self::get_cron_warm_state();
            $next = self::get_next_cron_warm_scheduled_at();
            $remaining = max(0, (int) $state['total'] - (int) $state['processed']);

            return array(
                'enabled' => !empty($settings['cron_warm_enabled']),
                'startAfterCleanup' => !empty($settings['cron_warm_start_after_cleanup']),
                'startAfterManualPurge' => !empty($settings['cron_warm_start_after_manual_purge']),
                'pagesPerMinute' => max(0, (int) $settings['cron_warm_pages_per_minute']),
                'totalLimit' => max(0, (int) ($state['totalLimit'] ?: $settings['scheduled_warm_limit'])),
                'active' => !empty($state['active']),
                'processed' => max(0, (int) $state['processed']),
                'total' => max(0, (int) $state['total']),
                'remaining' => $remaining,
                'successCount' => max(0, (int) $state['successCount']),
                'errorCount' => max(0, (int) $state['errorCount']),
                'startedAt' => max(0, (int) $state['startedAt']),
                'updatedAt' => max(0, (int) $state['updatedAt']),
                'lastRunAt' => max(0, (int) $state['lastRunAt']),
                'finishedAt' => max(0, (int) $state['finishedAt']),
                'lastError' => (string) $state['lastError'],
                'lastMessage' => (string) $state['lastMessage'],
                'lastUrl' => (string) $state['lastUrl'],
                'reason' => (string) $state['reason'],
                'completed' => !empty($state['completed']),
                'stopped' => !empty($state['stopped']),
                'stopReason' => (string) $state['stopReason'],
                'invokedBy' => (string) $state['invokedBy'],
                'nextScheduledAt' => (int) $next,
                'serverCronCommand' => self::get_cron_warm_server_cron_command(),
            );
        }

        public static function start_cron_warmup_queue($reason = 'manual', $run_immediately = false)
        {
            $settings = self::get_settings();
            $engine = self::get_engine_instance();
            if (!$engine || !method_exists($engine, 'get_crawl_urls_cursor_batch') || !method_exists($engine, 'warm_url')) {
                return array('success' => false, 'message' => self::maybe_translate('Cron warm up is not available.'));
            }

            $existing_state = self::get_cron_warm_state();
            $existing_updated_at = !empty($existing_state['updatedAt']) ? (int) $existing_state['updatedAt'] : 0;
            $existing_is_fresh = !empty($existing_state['active']) && empty($existing_state['completed']) && empty($existing_state['stopped']) && $existing_updated_at > (time() - 15 * MINUTE_IN_SECONDS);
            if ($existing_is_fresh) {
                return array(
                    'success' => true,
                    'message' => self::maybe_translate('Cron warm up is already queued or running.'),
                    'state' => self::get_cron_warm_status(),
                );
            }

            $lock_token = 'start-' . gmdate('YmdHis') . '-' . wp_generate_password(12, false, false);
            if (!self::acquire_cron_warm_lock($lock_token, 60)) {
                return array(
                    'success' => false,
                    'message' => self::maybe_translate('Cron warm up start skipped because another warm-up operation is active.'),
                    'state' => self::get_cron_warm_status(),
                );
            }

            try {
                $pages_per_minute = max(0, (int) $settings['cron_warm_pages_per_minute']);
                $total_limit = max(0, (int) $settings['scheduled_warm_limit']);
                $state = self::save_cron_warm_state(array(
                    'active'         => true,
                    'reason'         => sanitize_key((string) $reason),
                    'cursor'         => '',
                    'processed'      => 0,
                    'total'          => 0,
                    'successCount'   => 0,
                    'errorCount'     => 0,
                    'startedAt'      => time(),
                    'updatedAt'      => time(),
                    'lastRunAt'      => 0,
                    'finishedAt'     => 0,
                    'pagesPerMinute' => $pages_per_minute,
                    'totalLimit'     => $total_limit,
                    'currentBatch'   => array(),
                    'batchIndex'     => 0,
                    'batchHasMore'   => false,
                    'nextCursorPending' => '',
                    'lastError'      => '',
                    'lastMessage'    => self::maybe_translate('Cron warm up queued.'),
                    'lastUrl'        => '',
                    'completed'      => false,
                    'stopped'        => false,
                    'stopReason'     => '',
                    'invokedBy'      => '',
                ));

                self::unschedule_cron_warm_events();
                self::ensure_cron_warm_events_scheduled(1);

                return array(
                    'success' => true,
                    'message' => self::maybe_translate('Cron warm up queued.'),
                    'state'   => self::get_cron_warm_status(),
                );
            } finally {
                self::release_cron_warm_lock($lock_token);
            }
        }

        public static function stop_cron_warmup_queue($reason = 'manual')
        {
            $state = self::get_cron_warm_state();
            $state['active'] = false;
            $state['stopped'] = true;
            $state['completed'] = false;
            $state['stopReason'] = sanitize_key((string) $reason);
            $state['finishedAt'] = time();
            $state['updatedAt'] = time();
            $state['lastMessage'] = self::maybe_translate('Cron warm up stopped.');
            self::save_cron_warm_state($state);
            self::unschedule_cron_warm_events();

            return array(
                'success' => true,
                'message' => self::maybe_translate('Cron warm up stopped.'),
                'state'   => self::get_cron_warm_status(),
            );
        }

        private static function get_cron_warm_lock_option_name()
        {
            return UCWP_CRON_WARM_LOCK_KEY . '_atomic';
        }

        private static function decode_cron_warm_lock($raw_lock)
        {
            if (is_array($raw_lock)) {
                return $raw_lock;
            }

            if (!is_string($raw_lock) || '' === $raw_lock) {
                return array();
            }

            $decoded = json_decode($raw_lock, true);
            return is_array($decoded) ? $decoded : array();
        }

        private static function acquire_cron_warm_lock($lock_token, $lock_ttl)
        {
            $now = time();
            $lock_ttl = max(10, (int) $lock_ttl);
            $option_name = self::get_cron_warm_lock_option_name();
            $existing = self::decode_cron_warm_lock(get_option($option_name, ''));

            if (!empty($existing['token']) && !empty($existing['expiresAt']) && (int) $existing['expiresAt'] > $now) {
                return false;
            }

            if (!empty($existing)) {
                delete_option($option_name);
            }

            $lock = array(
                'token' => (string) $lock_token,
                'startedAt' => $now,
                'expiresAt' => $now + $lock_ttl,
            );

            $payload = function_exists('wp_json_encode') ? wp_json_encode($lock) : json_encode($lock);
            if (!add_option($option_name, $payload, '', 'no')) {
                return false;
            }

            set_transient(UCWP_CRON_WARM_LOCK_KEY, $lock, $lock_ttl);
            return true;
        }

        private static function renew_cron_warm_lock($lock_token, $lock_ttl)
        {
            $lock_ttl = max(10, (int) $lock_ttl);
            $option_name = self::get_cron_warm_lock_option_name();
            $existing = self::decode_cron_warm_lock(get_option($option_name, ''));

            if (empty($existing['token']) || !hash_equals((string) $existing['token'], (string) $lock_token)) {
                return false;
            }

            $lock = array(
                'token' => (string) $lock_token,
                'startedAt' => !empty($existing['startedAt']) ? (int) $existing['startedAt'] : time(),
                'expiresAt' => time() + $lock_ttl,
            );

            $payload = function_exists('wp_json_encode') ? wp_json_encode($lock) : json_encode($lock);
            update_option($option_name, $payload, false);
            set_transient(UCWP_CRON_WARM_LOCK_KEY, $lock, $lock_ttl);
            return true;
        }

        private static function release_cron_warm_lock($lock_token)
        {
            $option_name = self::get_cron_warm_lock_option_name();
            $existing = self::decode_cron_warm_lock(get_option($option_name, ''));

            if (!empty($existing['token']) && hash_equals((string) $existing['token'], (string) $lock_token)) {
                delete_option($option_name);
            }

            $latest_lock = get_transient(UCWP_CRON_WARM_LOCK_KEY);
            if (is_array($latest_lock) && isset($latest_lock['token']) && hash_equals((string) $latest_lock['token'], (string) $lock_token)) {
                delete_transient(UCWP_CRON_WARM_LOCK_KEY);
            }
        }

        public static function run_cron_warm_tick(array $args = array())
        {
            $state = self::get_cron_warm_state();
            if (empty($state['active'])) {
                self::unschedule_cron_warm_events();
                return array(
                    'success' => true,
                    'message' => self::maybe_translate('Cron warm up queue is idle.'),
                    'warmedThisRun' => 0,
                    'state' => self::get_cron_warm_status(),
                );
            }

            $lock_ttl = 90;
            $now = time();
            $lock_token = wp_generate_password(20, false, false);
            if (!self::acquire_cron_warm_lock($lock_token, $lock_ttl)) {
                return array(
                    'success' => true,
                    'message' => self::maybe_translate('Cron warm up tick skipped because another run is active.'),
                    'warmedThisRun' => 0,
                    'state' => self::get_cron_warm_status(),
                );
            }

            try {
                $settings = self::get_settings();
                $engine = self::get_engine_instance();
                if (!$engine || !method_exists($engine, 'get_crawl_urls_cursor_batch') || !method_exists($engine, 'warm_url')) {
                    $state['active'] = false;
                    $state['lastError'] = 'Cron warm up engine is not available.';
                    $state['lastMessage'] = $state['lastError'];
                    $state['updatedAt'] = time();
                    self::save_cron_warm_state($state);
                    self::unschedule_cron_warm_events();
                    return array('success' => false, 'message' => $state['lastError'], 'state' => self::get_cron_warm_status());
                }

                $pages_per_minute = isset($args['pagesPerMinute']) && null !== $args['pagesPerMinute']
                    ? max(0, min(600, absint($args['pagesPerMinute'])))
                    : max(0, (int) ($state['pagesPerMinute'] ?: $settings['cron_warm_pages_per_minute']));
                $total_limit = isset($args['totalLimit']) && null !== $args['totalLimit']
                    ? max(0, min(5000, absint($args['totalLimit'])))
                    : max(0, (int) ($state['totalLimit'] ?: $settings['scheduled_warm_limit']));

                if ($pages_per_minute < 1) {
                    $state['active'] = false;
                    $state['completed'] = false;
                    $state['stopped'] = true;
                    $state['stopReason'] = 'paused';
                    $state['updatedAt'] = time();
                    $state['finishedAt'] = time();
                    $state['pagesPerMinute'] = 0;
                    $state['totalLimit'] = $total_limit;
                    $state['lastMessage'] = 'Cron warm up paused because pages per minute is 0.';
                    self::save_cron_warm_state($state);
                    self::unschedule_cron_warm_events();
                    return array('success' => false, 'message' => $state['lastMessage'], 'warmedThisRun' => 0, 'state' => self::get_cron_warm_status());
                }

                if ($total_limit > 0 && max(0, (int) $state['processed']) >= $total_limit) {
                    $state['active'] = false;
                    $state['completed'] = true;
                    $state['stopped'] = false;
                    $state['stopReason'] = '';
                    $state['finishedAt'] = time();
                    $state['pagesPerMinute'] = $pages_per_minute;
                    $state['totalLimit'] = $total_limit;
                    $state['total'] = max(0, min((int) $state['total'], $total_limit));
                    $state['lastMessage'] = 'Cron warm up reached the scheduled warm limit.';
                    self::save_cron_warm_state($state);
                    self::unschedule_cron_warm_events();
                    return array('success' => true, 'message' => $state['lastMessage'], 'warmedThisRun' => 0, 'state' => self::get_cron_warm_status());
                }

                $current_batch = isset($state['currentBatch']) && is_array($state['currentBatch']) ? array_values($state['currentBatch']) : array();
                $batch_index = max(0, (int) $state['batchIndex']);
                if ($batch_index >= count($current_batch)) {
                    $current_batch = array();
                    $batch_index = 0;
                }

                if (empty($current_batch)) {
                    $remaining_budget = $total_limit > 0 ? max(0, $total_limit - max(0, (int) $state['processed'])) : 0;
                    if ($total_limit > 0 && $remaining_budget < 1) {
                        $state['active'] = false;
                        $state['completed'] = true;
                        $state['stopped'] = false;
                        $state['stopReason'] = '';
                        $state['finishedAt'] = time();
                        $state['pagesPerMinute'] = $pages_per_minute;
                        $state['totalLimit'] = $total_limit;
                        $state['total'] = max(0, min((int) $state['total'], $total_limit));
                        $state['lastMessage'] = 'Cron warm up reached the scheduled warm limit.';
                        self::save_cron_warm_state($state);
                        self::unschedule_cron_warm_events();
                        return array('success' => true, 'message' => $state['lastMessage'], 'warmedThisRun' => 0, 'state' => self::get_cron_warm_status());
                    }

                    $batch_limit = $total_limit > 0 ? min($pages_per_minute, $remaining_budget) : $pages_per_minute;
                    $batch = $engine->get_crawl_urls_cursor_batch((string) $state['cursor'], $batch_limit);
                    $current_batch = isset($batch['items']) && is_array($batch['items']) ? array_values($batch['items']) : array();
                    $state['currentBatch'] = $current_batch;
                    $state['batchIndex'] = 0;
                    $state['batchHasMore'] = !empty($batch['hasMore']);
                    $state['nextCursorPending'] = !empty($batch['nextCursor']) ? (string) $batch['nextCursor'] : '';
                    $state['total'] = max((int) $state['total'], (int) ($batch['total'] ?? 0));
                    if ($total_limit > 0) {
                        $state['total'] = max(0, min((int) $state['total'], $total_limit));
                    }
                    $state['pagesPerMinute'] = $pages_per_minute;
                    $state['totalLimit'] = $total_limit;
                    $state['lastRunAt'] = $now;
                    $state['updatedAt'] = $now;
                    $state['invokedBy'] = !empty($args['invokedBy']) ? sanitize_key((string) $args['invokedBy']) : '';
                    $state['lastMessage'] = empty($current_batch) ? 'No eligible URLs found for this cron warm tick.' : 'Cron warm up running.';
                    self::save_cron_warm_state($state);
                } else {
                    $state['pagesPerMinute'] = $pages_per_minute;
                    $state['totalLimit'] = $total_limit;
                    $state['lastRunAt'] = $now;
                    $state['updatedAt'] = $now;
                    $state['invokedBy'] = !empty($args['invokedBy']) ? sanitize_key((string) $args['invokedBy']) : '';
                    self::save_cron_warm_state($state);
                }

                $warmed = 0;
                $errors = 0;
                $last_error = (string) $state['lastError'];
                $last_url = (string) $state['lastUrl'];

                foreach ($current_batch as $index => $url) {
                    if ($index < $batch_index) {
                        continue;
                    }

                    $last_url = (string) $url;
                    $result = $engine->warm_url($url, array('ignore_runtime_bypass' => true));
                    if (!empty($result['success'])) {
                        $warmed++;
                        $state['successCount'] = (int) $state['successCount'] + 1;
                    } else {
                        $errors++;
                        $state['errorCount'] = (int) $state['errorCount'] + 1;
                        if (!empty($result['message'])) {
                            $last_error = (string) $result['message'];
                        }
                    }

                    $state['batchIndex'] = $index + 1;
                    $state['processed'] = max(0, (int) $state['processed']) + 1;
                    $state['lastRunAt'] = time();
                    $state['updatedAt'] = time();
                    $state['lastError'] = (string) $last_error;
                    $state['lastUrl'] = $last_url;
                    $state['lastMessage'] = sprintf('Processed %d/%d URL(s) in the current cron warm batch.', $state['batchIndex'], count($current_batch));
                    self::save_cron_warm_state($state);

                    self::renew_cron_warm_lock($lock_token, $lock_ttl);
                }

                $completed = false;
                if (empty($current_batch)) {
                    if (!empty($state['batchHasMore']) && !empty($state['nextCursorPending'])) {
                        $state['cursor'] = (string) $state['nextCursorPending'];
                        $state['currentBatch'] = array();
                        $state['batchIndex'] = 0;
                        $state['batchHasMore'] = false;
                        $state['nextCursorPending'] = '';
                        $state['active'] = true;
                        $state['completed'] = false;
                        $state['stopped'] = false;
                        $state['stopReason'] = '';
                        $state['updatedAt'] = time();
                        $state['lastMessage'] = 'Advanced cron warm queue to the next batch.';
                        self::save_cron_warm_state($state);
                        self::ensure_cron_warm_events_scheduled();
                    } else {
                        $completed = true;
                    }
                } elseif ((int) $state['batchIndex'] >= count($current_batch)) {
                    if (!empty($state['batchHasMore'])) {
                        $state['cursor'] = (string) $state['nextCursorPending'];
                        $state['currentBatch'] = array();
                        $state['batchIndex'] = 0;
                        $state['batchHasMore'] = false;
                        $state['nextCursorPending'] = '';
                        $state['active'] = true;
                        $state['completed'] = false;
                        $state['stopped'] = false;
                        $state['stopReason'] = '';
                        $state['updatedAt'] = time();
                        $remaining_after = max(0, (int) $state['total'] - (int) $state['processed']);
                        $state['lastMessage'] = sprintf('Warmed %d URL(s) this tick. %d remaining.', $warmed, $remaining_after);
                        self::save_cron_warm_state($state);
                        self::ensure_cron_warm_events_scheduled();
                    } else {
                        $completed = true;
                    }
                }

                if ($completed) {
                    $state['active'] = false;
                    $state['completed'] = true;
                    $state['stopped'] = false;
                    $state['stopReason'] = '';
                    $state['finishedAt'] = time();
                    $state['currentBatch'] = array();
                    $state['batchIndex'] = 0;
                    $state['batchHasMore'] = false;
                    $state['nextCursorPending'] = '';
                    $state['lastMessage'] = $warmed > 0 || $state['processed'] > 0 ? 'Cron warm up complete.' : 'Cron warm up queue completed with no eligible URLs.';
                    self::save_cron_warm_state($state);
                    self::unschedule_cron_warm_events();
                }

                return array(
                    'success' => true,
                    'message' => $state['lastMessage'],
                    'warmedThisRun' => $warmed,
                    'errorsThisRun' => $errors,
                    'state' => self::get_cron_warm_status(),
                );
            } finally {
                self::release_cron_warm_lock($lock_token);
            }
        }

private static function delete_plugin_options_and_transients()
{
    global $wpdb;

    $option_names = array(
        UCWP_SETTINGS_KEY,
        UCWP_CRON_WARM_STATE_KEY,
        UCWP_CRON_WARM_LOCK_KEY . '_atomic',
        defined('UCWP_CRAWL_SCOPE_SUMMARY_KEY') ? UCWP_CRAWL_SCOPE_SUMMARY_KEY : 'ucwp_crawl_scope_summary',
        UCWP_WP_CACHE_MANAGED_KEY,
        UCWP_SETTINGS_KEY . '_action_jobs',
        'ucwp_media_conversion_queue',
        'ucwp_media_diagnostics_v1',
        'ucwp_media_queue_db_version',
        'ucwp_object_cache_last_flush_report',
    );

    foreach ($option_names as $option_name) {
        delete_option($option_name);
        delete_site_option($option_name);
    }

    delete_transient(UCWP_CRON_WARM_LOCK_KEY);
    delete_transient('ucwp_loopback_ssl_status_v1');
    delete_transient('ucwp_frontend_compression_probe_v1');
    delete_transient('ucwp_media_conversion_queue_lock');
    delete_transient('ucwp_media_queue_process_lock_v1');
    delete_transient('ucwp_media_work_summary_v1');

    if ($wpdb instanceof wpdb) {
        $patterns = array(
            'ucwp_%',
            '_transient_ucwp_%',
            '_transient_timeout_ucwp_%',
            '_site_transient_ucwp_%',
            '_site_transient_timeout_ucwp_%',
        );

        foreach ($patterns as $pattern) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Full plugin-data delete intentionally removes UltraCache-owned options.
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE option_name LIKE %s',
                    $wpdb->options,
                    $pattern
                )
            );
        }

        $media_queue_table = $wpdb->prefix . 'ucwp_media_queue';
        if (preg_match('/^[A-Za-z0-9_]+$/', $media_queue_table)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Full plugin-data delete intentionally drops UltraCache's private media queue table.
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $media_queue_table));
        }

        if (is_multisite()) {
            foreach (array('ucwp_%', '_site_transient_ucwp_%', '_site_transient_timeout_ucwp_%') as $site_pattern) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Full plugin-data delete intentionally removes UltraCache-owned site metadata.
                $wpdb->query(
                    $wpdb->prepare(
                        'DELETE FROM %i WHERE meta_key LIKE %s',
                        $wpdb->sitemeta,
                        $site_pattern
                    )
                );
            }
        }
    }
}

private static function remove_runtime_secret_files()
{
    $candidates = array();

    if (defined('UCWP_CACHE_DIR')) {
        $candidates[] = trailingslashit(UCWP_CACHE_DIR) . 'runtime-config.php';
        $candidates[] = trailingslashit(UCWP_CACHE_DIR) . 'runtime-config.json';
    }

    $candidates[] = self::get_runtime_secret_path();

    foreach (array_unique($candidates) as $path) {
        if (is_string($path) && '' !== $path && file_exists($path)) {
            ucwp_safe_unlink($path, 'delete_all_plugin_data runtime cleanup');
        }
    }
}

public static function delete_all_plugin_data_and_deactivate()
{
    if (!current_user_can('manage_options') || !current_user_can('activate_plugins')) {
        return new WP_Error('ucwp_forbidden', 'Deleting UltraCache data and deactivating the plugin requires manage_options and activate_plugins permissions.');
    }

    self::stop_cron_warmup_queue('delete-all-data');
    self::unschedule_cache_cleanup();
    self::unschedule_cron_warm_events();
    wp_clear_scheduled_hook('ucwp_scheduled_cache_cleanup');
    wp_clear_scheduled_hook('ucwp_cron_warm_tick');
    wp_clear_scheduled_hook('ucwp_cron_warm_tick_kickoff');
    wp_clear_scheduled_hook('ucwp_process_media_conversion_queue');

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
    self::set_wp_cache_flag(false);
    self::remove_runtime_secret_files();

    if (defined('UCWP_CACHE_DIR') && is_dir(UCWP_CACHE_DIR)) {
        ucwp_safe_rmdir(UCWP_CACHE_DIR, 'delete_all_plugin_data cache dir');
    }
    if (defined('UCWP_OBJECT_CACHE_DIR') && is_dir(UCWP_OBJECT_CACHE_DIR)) {
        ucwp_safe_rmdir(UCWP_OBJECT_CACHE_DIR, 'delete_all_plugin_data object cache dir');
    }

    // Keep converted media files by design. UCWP_AVIF_DIR and UCWP_WEBP_DIR
    // are intentionally not removed here.
    self::delete_plugin_options_and_transients();
    self::reset_settings_cache();

    if (!function_exists('deactivate_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    if (function_exists('deactivate_plugins')) {
        deactivate_plugins(UCWP_BASENAME, false, is_multisite());
    }

    return array(
        'success' => true,
        'message' => 'UltraCache data was deleted and the plugin was deactivated. Converted media folders were not deleted.',
        'mediaFoldersKept' => array(
            'avif' => defined('UCWP_AVIF_DIR') ? UCWP_AVIF_DIR : '',
            'webp' => defined('UCWP_WEBP_DIR') ? UCWP_WEBP_DIR : '',
        ),
    );
}

        public static function persist_dashboard_settings(array $settings)
        {
            $previous_settings = self::get_dashboard_settings();
            $current_settings = self::sanitize_dashboard_settings(self::merge_protected_dashboard_settings($settings, $previous_settings));
            $varnish_validation = self::validate_varnish_settings($current_settings);
            if (is_wp_error($varnish_validation)) {
                return $varnish_validation;
            }
            // Only explicit non-empty admin inputs write runtime secrets.
            // Hydrated existing secrets in $previous_settings / $current_settings must
            // not cause a write when the UI leaves secret fields blank.
            $redis_password_for_runtime = array_key_exists('redisPassword', $settings) ? trim((string) $settings['redisPassword']) : '';
            $varnish_admin_secret_for_runtime = array_key_exists('varnishCliKey', $settings) ? trim((string) $settings['varnishCliKey']) : '';

            if ('' !== $redis_password_for_runtime && !self::set_runtime_redis_password($redis_password_for_runtime)) {
                return new WP_Error('ucwp_redis_secret_save_failed', self::maybe_translate('Redis password could not be saved to the UltraCache runtime secrets file. Check filesystem permissions.'));
            }

            if ('' !== $varnish_admin_secret_for_runtime && !self::set_runtime_varnish_admin_secret($varnish_admin_secret_for_runtime)) {
                return new WP_Error('ucwp_varnish_secret_save_failed', self::maybe_translate('Varnish admin secret could not be saved to the UltraCache runtime secrets file. Check filesystem permissions.'));
            }

            $current_settings['redisPassword'] = '';
            $current_settings['varnishCliKey'] = '';
            update_option(UCWP_SETTINGS_KEY, $current_settings);
            self::reset_settings_cache();
            self::ensure_directories();
            if (class_exists('Ultra_Cache_Engine') && method_exists('Ultra_Cache_Engine', 'ensure_cache_directories')) {
                Ultra_Cache_Engine::ensure_cache_directories();
            }

            $page_cache_sync = self::sync_page_cache_bootstrap(!empty($current_settings['pageCacheEnabled']));
            if (is_wp_error($page_cache_sync)) {
                return $page_cache_sync;
            }

            self::sync_runtime_config();
            if (empty($current_settings['cronWarmEnabled'])) {
                self::stop_cron_warmup_queue('disabled');
            } else {
                $state = self::get_cron_warm_state();
                if (!empty($state['active'])) {
                    $state['pagesPerMinute'] = max(0, (int) $current_settings['cronWarmPagesPerMinute']);
                    $state['updatedAt'] = time();
                    $state['lastMessage'] = $state['pagesPerMinute'] > 0 ? 'Cron warm up settings updated.' : 'Cron warm up paused because pages per minute is 0.';
                    self::save_cron_warm_state($state);
                    if ($state['pagesPerMinute'] > 0) {
                        self::ensure_cron_warm_events_scheduled();
                    } else {
                        self::unschedule_cron_warm_events();
                    }
                }
            }
            self::sync_scheduled_events();
            $browser_cache_sync = self::sync_browser_cache_rules();
            if (false === $browser_cache_sync) {
                return new WP_Error('ucwp_browser_cache_rules_not_writable', self::maybe_translate('Browser Cache Headers could not be written to .htaccess. Check file permissions or disable Browser Cache Headers.'));
            }

            if (class_exists('Ultra_Cache_Object_Cache_Manager')) {
                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'reset_plugin_settings_cache')) {
                    Ultra_Cache_Object_Cache_Manager::reset_plugin_settings_cache();
                }
                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'sync_dropin')) {
                    Ultra_Cache_Object_Cache_Manager::sync_dropin();
                }
                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'reset_plugin_settings_cache')) {
                    Ultra_Cache_Object_Cache_Manager::reset_plugin_settings_cache();
                }
            }

            $google_fonts_job = null;
            $google_fonts_enabled_now = !empty($current_settings['googleFontsLocalOptimizationEnabled']);
            $google_fonts_was_enabled = !empty($previous_settings['googleFontsLocalOptimizationEnabled']);
            $google_fonts_urls_changed = (string) ($current_settings['googleFontsAdditionalScanUrls'] ?? '') !== (string) ($previous_settings['googleFontsAdditionalScanUrls'] ?? '');
            if ($google_fonts_enabled_now && (!$google_fonts_was_enabled || $google_fonts_urls_changed)) {
                $google_fonts_job = array(
                    'success' => true,
                    'queued'  => false,
                    'message' => 'Google Fonts settings saved. Use the Rebuild Google Fonts Cache button or wp ultracache google_fonts_rebuild --clear to rebuild the local font cache.',
                );
            }

            $crawl_scope_summary = self::get_crawl_scope_summary($current_settings);
            $selected_warm_sources_for_summary = array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) ($current_settings['warmFullSiteSources'] ?? ''))));
            $crawl_scope_source_counts = isset($crawl_scope_summary['sourceCounts']) && is_array($crawl_scope_summary['sourceCounts']) ? $crawl_scope_summary['sourceCounts'] : array();
            $crawl_scope_source_breakdown = isset($crawl_scope_summary['sourceBreakdown']) && is_array($crawl_scope_summary['sourceBreakdown']) ? $crawl_scope_summary['sourceBreakdown'] : array();
            $crawl_scope_selected_sources = isset($crawl_scope_summary['selectedFullSiteSources']) && is_array($crawl_scope_summary['selectedFullSiteSources']) ? $crawl_scope_summary['selectedFullSiteSources'] : array();
            $should_store_crawl_scope_summary = is_array($crawl_scope_summary);
            if (!empty($selected_warm_sources_for_summary) && empty($crawl_scope_source_counts) && empty($crawl_scope_source_breakdown) && empty($crawl_scope_selected_sources)) {
                $should_store_crawl_scope_summary = false;
            }
            if (defined('UCWP_CRAWL_SCOPE_SUMMARY_KEY') && $should_store_crawl_scope_summary) {
                $crawl_scope_summary['storedAt'] = time();
                update_option(
                    UCWP_CRAWL_SCOPE_SUMMARY_KEY,
                    array(
                        'updatedAt' => time(),
                        'summary'   => $crawl_scope_summary,
                    ),
                    false
                );
            }

            $payload = array(
                'success'     => true,
                'settings'    => self::get_dashboard_settings_for_client(),
                'crawlScopeSummary' => $crawl_scope_summary,
                'stats'       => self::get_engine_stats(),
                'diagnostics' => self::get_dashboard_diagnostics(),
            );
            if (is_array($google_fonts_job)) {
                $payload['googleFonts'] = $google_fonts_job;
            }

            return $payload;
        }
        private static function get_browser_cache_htaccess_path()
        {
            return trailingslashit(ABSPATH) . '.htaccess';
        }

        private static function get_browser_cache_htaccess_block()
        {
            return implode("\n", array(
                '<IfModule mod_expires.c>',
                'ExpiresActive On',
                'ExpiresByType text/css "access plus 1 year"',
                'ExpiresByType text/javascript "access plus 1 year"',
                'ExpiresByType application/javascript "access plus 1 year"',
                'ExpiresByType application/x-javascript "access plus 1 year"',
                'ExpiresByType image/jpeg "access plus 1 year"',
                'ExpiresByType image/png "access plus 1 year"',
                'ExpiresByType image/gif "access plus 1 year"',
                'ExpiresByType image/webp "access plus 1 year"',
                'ExpiresByType image/avif "access plus 1 year"',
                'ExpiresByType image/svg+xml "access plus 1 year"',
                'ExpiresByType image/x-icon "access plus 1 year"',
                'ExpiresByType font/ttf "access plus 1 year"',
                'ExpiresByType font/otf "access plus 1 year"',
                'ExpiresByType font/woff "access plus 1 year"',
                'ExpiresByType font/woff2 "access plus 1 year"',
                'ExpiresByType application/font-woff "access plus 1 year"',
                'ExpiresByType application/font-woff2 "access plus 1 year"',
                '</IfModule>',
                '<IfModule mod_headers.c>',
                '<FilesMatch "\\.(css|js|mjs|gif|png|jpe?g|webp|avif|svg|ico|woff2?|ttf|otf|eot)$">',
                'Header set Cache-Control "public, max-age=31536000, immutable"',
                '</FilesMatch>',
                '</IfModule>',
            ));
        }

        public static function sync_browser_cache_rules($enabled = null)
        {
            $begin = '# BEGIN UltraCache Browser Cache';
            $end   = '# END UltraCache Browser Cache';

            if (null === $enabled) {
                $settings = self::get_settings();
                $enabled = !empty($settings['browser_cache_rules']);
            }

            $path = self::get_browser_cache_htaccess_path();
            $contents = file_exists($path) ? (string) ucwp_safe_file_get_contents($path, 'sync_browser_cache_rules') : '';
            $has_block = (false !== strpos($contents, $begin) && false !== strpos($contents, $end));

            if (file_exists($path) && !ucwp_path_is_writable($path)) {
                return !$enabled && !$has_block;
            }
            $pattern  = '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\R*/s';
            $updated  = (string) preg_replace($pattern, '', $contents);
            $updated  = rtrim($updated);

            if ($enabled) {
                $block = $begin . "\n" . self::get_browser_cache_htaccess_block() . "\n" . $end;
                $updated = '' === $updated ? $block : ($updated . "\n\n" . $block);
            }

            $updated = '' === trim($updated) ? '' : (rtrim($updated) . "\n");

            if ($updated === $contents) {
                return true;
            }

            $dir = dirname($path);
            if (!file_exists($dir) && !ucwp_safe_mkdir($dir, 0755, true, 'sync_browser_cache_rules') && !file_exists($dir)) {
                return false;
            }

            $tmp = $path . '.tmp-' . uniqid('', true);
            if (false === ucwp_safe_file_put_contents($tmp, $updated, LOCK_EX, 'sync_browser_cache_rules tmp')) {
                ucwp_safe_unlink($tmp, 'sync_browser_cache_rules tmp cleanup');
                return false;
            }

            if (!ucwp_safe_rename($tmp, $path, 'sync_browser_cache_rules rename')) {
                ucwp_safe_unlink($tmp, 'sync_browser_cache_rules rename cleanup');
                return false;
            }

            return true;
        }

        private static function get_engine_class()
        {
            $candidates = array('Ultra_Cache_Engine');
            foreach ($candidates as $class) {
                if (class_exists($class)) {
                    return $class;
                }
            }

            return null;
        }

        public static function sync_page_cache_bootstrap($enabled = null)
        {
            if (null === $enabled) {
                $settings = self::get_dashboard_settings();
                $enabled = !empty($settings['pageCacheEnabled']);
            }

            $enabled = (bool) $enabled;

            $result = self::set_wp_cache_flag($enabled);
            if (is_wp_error($result)) {
                return $result;
            }

            $engine_class = self::get_engine_class();
            if (!$engine_class) {
                return true;
            }

            if ($enabled) {
                if (method_exists($engine_class, 'setup_advanced_cache')) {
                    $engine_class::setup_advanced_cache();
                }
            } elseif (method_exists($engine_class, 'maybe_remove_advanced_cache')) {
                $engine_class::maybe_remove_advanced_cache();
            }

            return true;
        }

        private static function get_wp_config_path()
        {
            $paths = array(
                ABSPATH . 'wp-config.php',
                dirname(ABSPATH) . '/wp-config.php',
            );

            foreach ($paths as $path) {
                if (file_exists($path) && is_readable($path)) {
                    return $path;
                }
            }

            return false;
        }

        private static function get_managed_wp_cache_block($enabled)
        {
            return "// Added by UltraCache\n"
                . "if ( ! defined('WP_CACHE') ) {\n"
                . "\tdefine('WP_CACHE', " . ($enabled ? 'true' : 'false') . ");\n"
                . "}\n"
                . "// End UltraCache\n";
        }

        private static function strip_managed_wp_cache_block($contents)
        {
            $pattern = '/\n?\/\/ Added by UltraCache\R.*?\/\/ End UltraCache\R?/s';
            return (string) preg_replace($pattern, '', (string) $contents);
        }

        private static function get_wp_cache_insertion_offset($contents)
        {
            $contents = (string) $contents;
            if ('' === $contents) {
                return false;
            }

            $tokens = token_get_all($contents);
            if (!is_array($tokens) || empty($tokens)) {
                return false;
            }

            $offset   = 0;
            $saw_open = false;
            $total    = count($tokens);

            for ($i = 0; $i < $total; $i++) {
                $token = $tokens[$i];
                $text  = is_array($token) ? (string) $token[1] : (string) $token;

                if (!$saw_open) {
                    $offset += strlen($text);
                    if (is_array($token) && T_OPEN_TAG === $token[0]) {
                        $saw_open = true;
                    }
                    continue;
                }

                if (is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                    $offset += strlen($text);
                    continue;
                }

                if (is_array($token) && T_DECLARE === $token[0]) {
                    $offset += strlen($text);
                    $depth = 0;
                    for ($j = $i + 1; $j < $total; $j++) {
                        $next      = $tokens[$j];
                        $next_text = is_array($next) ? (string) $next[1] : (string) $next;
                        $offset += strlen($next_text);
                        if ('(' === $next_text) {
                            $depth++;
                        } elseif (')' === $next_text && $depth > 0) {
                            $depth--;
                        } elseif (';' === $next_text && 0 === $depth) {
                            $i = $j;
                            break;
                        }
                    }
                    continue;
                }

                break;
            }

            return $saw_open ? $offset : false;
        }

        private static function normalize_wp_cache_define_name($raw)
        {
            $raw = trim((string) $raw);
            if ('' === $raw) {
                return '';
            }

            $quote = substr($raw, 0, 1);
            if (("'" === $quote || '"' === $quote) && $quote === substr($raw, -1)) {
                $raw = substr($raw, 1, -1);
            }

            return stripslashes($raw);
        }

        private static function classify_wp_cache_define_value($raw)
        {
            $raw = trim((string) $raw);
            if ('' === $raw) {
                return 'unknown';
            }

            $normalized = strtolower($raw);
            if ('true' === $normalized) {
                return 'true';
            }

            if ('false' === $normalized) {
                return 'false';
            }

            $quote = substr($raw, 0, 1);
            if (("'" === $quote || '"' === $quote) && $quote === substr($raw, -1)) {
                $string_value = strtolower(stripslashes(substr($raw, 1, -1)));
                if ('true' === $string_value) {
                    return 'string-true';
                }
                if ('false' === $string_value) {
                    return 'string-false';
                }
            }

            return 'other';
        }

        private static function find_wp_cache_define_statements($contents)
        {
            $contents = (string) $contents;
            if ('' === $contents) {
                return array();
            }

            $tokens = token_get_all($contents);
            if (!is_array($tokens) || empty($tokens)) {
                return array();
            }

            $matches = array();
            $offset  = 0;
            $total   = count($tokens);

            for ($i = 0; $i < $total; $i++) {
                $token = $tokens[$i];
                $text  = is_array($token) ? (string) $token[1] : (string) $token;
                $len   = strlen($text);

                if (!is_array($token) || T_STRING !== $token[0] || 'define' !== strtolower($text)) {
                    $offset += $len;
                    continue;
                }

                $start_offset = $offset;
                $cursor       = $offset + $len;
                $j            = $i + 1;

                while ($j < $total) {
                    $next      = $tokens[$j];
                    $next_text = is_array($next) ? (string) $next[1] : (string) $next;
                    if (is_array($next) && in_array($next[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                        $cursor += strlen($next_text);
                        $j++;
                        continue;
                    }
                    break;
                }

                if ($j >= $total || '(' !== (is_array($tokens[$j]) ? (string) $tokens[$j][1] : (string) $tokens[$j])) {
                    $offset += $len;
                    continue;
                }

                $cursor += 1;
                $j++;
                $depth       = 0;
                $current_arg = '';
                $args        = array();
                $closed      = false;

                for (; $j < $total; $j++) {
                    $part      = $tokens[$j];
                    $part_text = is_array($part) ? (string) $part[1] : (string) $part;
                    $cursor   += strlen($part_text);

                    if ('(' === $part_text) {
                        $depth++;
                        $current_arg .= $part_text;
                        continue;
                    }

                    if (')' === $part_text) {
                        if ($depth > 0) {
                            $depth--;
                            $current_arg .= $part_text;
                            continue;
                        }

                        $args[] = $current_arg;
                        $current_arg = '';
                        $closed = true;
                        $j++;
                        break;
                    }

                    if (',' === $part_text && 0 === $depth) {
                        $args[] = $current_arg;
                        $current_arg = '';
                        continue;
                    }

                    $current_arg .= $part_text;
                }

                if (!$closed) {
                    $offset += $len;
                    continue;
                }

                while ($j < $total) {
                    $tail      = $tokens[$j];
                    $tail_text = is_array($tail) ? (string) $tail[1] : (string) $tail;
                    $cursor   += strlen($tail_text);
                    if (';' === $tail_text) {
                        break;
                    }
                    $j++;
                }

                if ($j >= $total) {
                    $offset += $len;
                    continue;
                }

                $name = isset($args[0]) ? self::normalize_wp_cache_define_name($args[0]) : '';
                if ('WP_CACHE' !== $name) {
                    $offset += $len;
                    continue;
                }

                $matches[] = array(
                    'start'      => $start_offset,
                    'end'        => $cursor,
                    'statement'  => substr($contents, $start_offset, $cursor - $start_offset),
                    'value_type' => self::classify_wp_cache_define_value(isset($args[1]) ? $args[1] : ''),
                );

                $offset = $cursor;
                $i      = $j;
            }

            return $matches;
        }

        private static function get_wp_cache_define_summary($contents)
        {
            $matches = self::find_wp_cache_define_statements($contents);
            if (empty($matches)) {
                return array(
                    'status'  => 'missing',
                    'matches' => array(),
                );
            }

            $status = 'other';
            foreach ($matches as $match) {
                $value_type = isset($match['value_type']) ? (string) $match['value_type'] : 'other';
                if ('true' === $value_type) {
                    return array(
                        'status'  => 'true',
                        'matches' => $matches,
                    );
                }

                if ('false' === $value_type) {
                    $status = 'false';
                    continue;
                }

                if ('false' !== $status) {
                    $status = 'nonstandard';
                }
            }

            return array(
                'status'  => $status,
                'matches' => $matches,
            );
        }

        private static function remove_wp_cache_define_statements($contents, $matches)
        {
            $contents = (string) $contents;
            if (empty($matches) || !is_array($matches)) {
                return $contents;
            }

            usort($matches, static function ($a, $b) {
                return (int) $b['start'] <=> (int) $a['start'];
            });

            foreach ($matches as $match) {
                $start = isset($match['start']) ? (int) $match['start'] : 0;
                $end   = isset($match['end']) ? (int) $match['end'] : $start;
                if ($end <= $start) {
                    continue;
                }

                $contents = substr($contents, 0, $start) . substr($contents, $end);
            }

            return $contents;
        }

        private static function insert_managed_wp_cache_block($contents, $block)
        {
            $offset = self::get_wp_cache_insertion_offset($contents);
            if (false === $offset) {
                return new WP_Error('ucwp_wp_config_anchor_not_found', 'Could not locate a safe insertion point for WP_CACHE in wp-config.php.');
            }

            $before = substr((string) $contents, 0, $offset);
            $after  = substr((string) $contents, $offset);
            $prefix = '';

            if ('' !== $before && !preg_match('/\R\z/', $before)) {
                $prefix = "\n";
            }

            return $before . $prefix . $block . "\n" . ltrim($after, "\r\n");
        }

        private static function get_wp_config_backup_path($config)
        {
            return dirname($config) . '/wp-config-backup-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false) . '.php';
        }

        private static function cleanup_wp_config_backups($config, $keep = 5)
        {
            $pattern = dirname($config) . '/wp-config-backup-*.php';
            $matches = glob($pattern);
            if (!is_array($matches) || count($matches) <= $keep) {
                return;
            }

            usort($matches, static function ($a, $b) {
                return (int) ucwp_safe_filemtime($b, 'cleanup_wp_config_backups') <=> (int) ucwp_safe_filemtime($a, 'cleanup_wp_config_backups');
            });

            foreach (array_slice($matches, max(0, (int) $keep)) as $old_backup) {
                ucwp_safe_unlink($old_backup, 'cleanup_wp_config_backups');
            }
        }

        private static function write_wp_config_atomically($config, $contents)
        {
            $backup = self::get_wp_config_backup_path($config);
            if (!ucwp_safe_copy($config, $backup, 'set_wp_cache_flag backup')) {
                return new WP_Error('ucwp_wp_config_backup_failed', 'Failed to create a wp-config backup before updating wp-config.php.');
            }

            self::cleanup_wp_config_backups($config);

            $tmp = $config . '.tmp-' . uniqid('', true);
            if (false === ucwp_safe_file_put_contents($tmp, $contents, LOCK_EX, 'set_wp_cache_flag tmp')) {
                ucwp_safe_unlink($tmp, 'set_wp_cache_flag tmp cleanup');
                return new WP_Error('ucwp_wp_config_write_failed', 'Failed to write temporary wp-config.php file.');
            }

            if (!ucwp_safe_rename($tmp, $config, 'set_wp_cache_flag rename')) {
                ucwp_safe_unlink($tmp, 'set_wp_cache_flag rename cleanup');
                return new WP_Error('ucwp_wp_config_write_failed', 'Failed to replace wp-config.php atomically.');
            }

            return true;
        }

        private static function set_wp_cache_flag($enabled = true)
        {
            $config = self::get_wp_config_path();
            if (!$config || !ucwp_path_is_writable($config)) {
                return new WP_Error('ucwp_wp_config_not_writable', 'wp-config.php was not found or is not writable.');
            }

            $raw_contents = ucwp_safe_file_get_contents($config, 'set_wp_cache_flag');
            if (false === $raw_contents) {
                return new WP_Error('ucwp_wp_config_read_failed', 'Failed to read wp-config.php.');
            }

            $enabled           = (bool) $enabled;
            $original_contents = (string) $raw_contents;
            $contents          = self::strip_managed_wp_cache_block($original_contents);

            if ($enabled) {
                $wp_cache_define = self::get_wp_cache_define_summary($contents);
                if ('true' === $wp_cache_define['status']) {
                    delete_option(UCWP_WP_CACHE_MANAGED_KEY);
                } else {
                    if (!empty($wp_cache_define['matches'])) {
                        $contents = self::remove_wp_cache_define_statements($contents, $wp_cache_define['matches']);
                    }

                    $block    = self::get_managed_wp_cache_block(true);
                    $contents = self::insert_managed_wp_cache_block($contents, $block);
                    if (is_wp_error($contents)) {
                        return $contents;
                    }

                    update_option(UCWP_WP_CACHE_MANAGED_KEY, !empty($wp_cache_define['matches']) ? 'replaced-existing' : 'block', false);
                }
            } else {
                delete_option(UCWP_WP_CACHE_MANAGED_KEY);
            }

            if ($contents === $original_contents) {
                return true;
            }

            return self::write_wp_config_atomically($config, $contents);
        }

        // Admin methods live in includes/admin/class-admin-trait.php.

        private static function get_engine_instance()
        {
            $candidates = array('Ultra_Cache_Engine');
            foreach ($candidates as $class) {
                if (class_exists($class) && method_exists($class, 'get_instance')) {
                    return call_user_func(array($class, 'get_instance'));
                }
            }

            return null;
        }

        private static function get_media_instance()
        {
            $candidates = array('Ultra_Cache_Media_Converter');
            foreach ($candidates as $class) {
                if (class_exists($class) && method_exists($class, 'get_instance')) {
                    return call_user_func(array($class, 'get_instance'));
                }
            }

            return null;
        }


        public static function get_opcache_status_summary()
        {
            if (!function_exists('opcache_get_status')) {
                return array(
                    'available' => false,
                    'enabled'   => false,
                    'message'   => 'OPcache functions are unavailable on this server.',
                );
            }

            $status = @opcache_get_status(false);
            if (!is_array($status)) {
                return array(
                    'available' => true,
                    'enabled'   => false,
                    'message'   => 'OPcache is not enabled for the current PHP SAPI.',
                );
            }

            $memory = isset($status['memory_usage']) && is_array($status['memory_usage']) ? $status['memory_usage'] : array();
            $interned = isset($status['interned_strings_usage']) && is_array($status['interned_strings_usage']) ? $status['interned_strings_usage'] : array();
            $statistics = isset($status['opcache_statistics']) && is_array($status['opcache_statistics']) ? $status['opcache_statistics'] : array();

            $used = (int) ($memory['used_memory'] ?? 0);
            $free = (int) ($memory['free_memory'] ?? 0);
            $wasted = (int) ($memory['wasted_memory'] ?? 0);
            $hits = (int) ($statistics['hits'] ?? 0);
            $misses = (int) ($statistics['misses'] ?? 0);
            $requests = $hits + $misses;
            $hit_rate = $requests > 0 ? round(($hits / $requests) * 100, 2) : 0.0;
            $last_restart = (int) ($status['last_restart_time'] ?? 0);
            $last_flush = (int) get_option('ucwp_opcache_last_flush_at', 0);

            return array(
                'available'                 => true,
                'enabled'                   => true,
                'message'                   => '',
                'memoryUsedBytes'           => $used,
                'memoryFreeBytes'           => $free,
                'memoryWastedBytes'         => $wasted,
                'memoryUsedHuman'           => function_exists('size_format') ? size_format($used, 2) : (string) $used,
                'memoryFreeHuman'           => function_exists('size_format') ? size_format($free, 2) : (string) $free,
                'memoryWastedHuman'         => function_exists('size_format') ? size_format($wasted, 2) : (string) $wasted,
                'internedUsedBytes'         => (int) ($interned['used_memory'] ?? 0),
                'internedFreeBytes'         => (int) ($interned['free_memory'] ?? 0),
                'internedUsedHuman'         => function_exists('size_format') ? size_format((int) ($interned['used_memory'] ?? 0), 2) : (string) ((int) ($interned['used_memory'] ?? 0)),
                'internedFreeHuman'         => function_exists('size_format') ? size_format((int) ($interned['free_memory'] ?? 0), 2) : (string) ((int) ($interned['free_memory'] ?? 0)),
                'cachedScripts'             => (int) ($statistics['num_cached_scripts'] ?? 0),
                'cachedKeys'                => (int) ($statistics['num_cached_keys'] ?? 0),
                'maxCachedKeys'             => (int) ($statistics['max_cached_keys'] ?? 0),
                'hits'                      => $hits,
                'misses'                    => $misses,
                'hitRate'                   => $hit_rate,
                'oomRestarts'               => (int) ($statistics['oom_restarts'] ?? 0),
                'hashRestarts'              => (int) ($statistics['hash_restarts'] ?? 0),
                'manualRestarts'            => (int) ($statistics['manual_restarts'] ?? 0),
                'lastRestartTime'           => $last_restart,
                'lastRestartTimeHuman'      => $last_restart > 0 ? gmdate('Y-m-d H:i:s', $last_restart) . ' UTC' : 'Never',
                'lastFlushTime'             => $last_flush,
                'lastFlushTimeHuman'        => $last_flush > 0 ? gmdate('Y-m-d H:i:s', $last_flush) . ' UTC' : 'Never',
                'restartPending'            => !empty($status['restart_pending']),
            );
        }

        public static function flush_opcache()
        {
            if (!function_exists('opcache_reset')) {
                return array(
                    'success' => false,
                    'message' => 'OPcache reset is unavailable on this server.',
                );
            }

            $success = (bool) @opcache_reset();
            if ($success) {
                update_option('ucwp_opcache_last_flush_at', time(), false);
            }
            $response = array(
                'success' => $success,
                'message' => $success ? 'OPcache flushed successfully.' : 'OPcache flush failed.',
                'opcache' => self::get_opcache_status_summary(),
            );

            if (method_exists(__CLASS__, 'get_engine_stats')) {
                $response['stats'] = self::get_engine_stats();
            }

            return $response;
        }

        public static function get_apcu_status_summary()
        {
            if (!function_exists('apcu_fetch') || !function_exists('apcu_store') || !function_exists('apcu_cache_info') || !function_exists('apcu_sma_info')) {
                return array(
                    'available' => false,
                    'enabled'   => false,
                    'message'   => 'APCu functions are unavailable on this server.',
                );
            }

            if (function_exists('apcu_enabled') && !apcu_enabled()) {
                return array(
                    'available' => true,
                    'enabled'   => false,
                    'message'   => 'APCu is loaded but disabled for the current PHP SAPI.',
                );
            }

            $cache_info = @apcu_cache_info(true);
            $sma_info   = @apcu_sma_info(true);
            if (!is_array($cache_info) || !is_array($sma_info)) {
                return array(
                    'available' => true,
                    'enabled'   => false,
                    'message'   => 'APCu status could not be read for the current PHP SAPI.',
                );
            }

            $num_segments = max(1, (int) ($sma_info['num_seg'] ?? 1));
            $segment_size = (int) ($sma_info['seg_size'] ?? 0);
            $total_memory = $segment_size > 0 ? ($num_segments * $segment_size) : 0;
            $free_memory  = (int) ($sma_info['avail_mem'] ?? 0);
            $used_memory  = $total_memory > 0 ? max(0, $total_memory - $free_memory) : (int) ($cache_info['mem_size'] ?? 0);
            $hits         = (int) ($cache_info['num_hits'] ?? 0);
            $misses       = (int) ($cache_info['num_misses'] ?? 0);
            $requests     = $hits + $misses;
            $hit_rate     = $requests > 0 ? round(($hits / $requests) * 100, 2) : 0.0;
            $usage_rate   = $total_memory > 0 ? round(($used_memory / $total_memory) * 100, 2) : 0.0;
            $entries      = (int) ($cache_info['num_entries'] ?? 0);
            if (!$entries && isset($cache_info['cache_list']) && is_array($cache_info['cache_list'])) {
                $entries = count($cache_info['cache_list']);
            }

            return array(
                'available'            => true,
                'enabled'              => true,
                'message'              => '',
                'memoryTotalBytes'     => $total_memory,
                'memoryUsedBytes'      => $used_memory,
                'memoryFreeBytes'      => $free_memory,
                'memoryTotalHuman'     => function_exists('size_format') ? size_format($total_memory, 2) : (string) $total_memory,
                'memoryUsedHuman'      => function_exists('size_format') ? size_format($used_memory, 2) : (string) $used_memory,
                'memoryFreeHuman'      => function_exists('size_format') ? size_format($free_memory, 2) : (string) $free_memory,
                'memoryUsagePercent'   => $usage_rate,
                'cachedEntries'        => $entries,
                'hits'                 => $hits,
                'misses'               => $misses,
                'hitRate'              => $hit_rate,
                'inserts'              => (int) ($cache_info['num_inserts'] ?? 0),
                'expunges'             => (int) ($cache_info['expunges'] ?? 0),
                'startTime'            => (int) ($cache_info['start_time'] ?? 0),
                'startTimeHuman'       => !empty($cache_info['start_time']) ? gmdate('Y-m-d H:i:s', (int) $cache_info['start_time']) . ' UTC' : '—',
            );
        }

        private static function clear_apcu_user_cache($include_stats = true)
        {
            if (!function_exists('apcu_clear_cache')) {
                return array(
                    'success' => false,
                    'message' => 'APCu clear cache is unavailable on this server.',
                );
            }

            if (function_exists('apcu_enabled') && !apcu_enabled()) {
                return array(
                    'success' => false,
                    'message' => 'APCu is loaded but disabled for the current PHP SAPI.',
                );
            }

            $success = (bool) @apcu_clear_cache();
            $response = array(
                'success' => $success,
                'message' => $success ? 'APCu user cache flushed successfully.' : 'APCu user cache flush failed.',
            );

            if ($include_stats && method_exists(__CLASS__, 'get_engine_stats')) {
                $response['stats'] = self::get_engine_stats();
            }

            return $response;
        }

        public static function flush_apcu()
        {
            return self::clear_apcu_user_cache(true);
        }

        public static function get_engine_stats($full_object_count = false, $force = false)
        {
            if (!$force && method_exists(__CLASS__, 'are_cache_stats_enabled') && !self::are_cache_stats_enabled()) {
                return self::get_cache_stats_disabled_payload('engine_stats_disabled');
            }

            $stats = array();
            $candidates = array('Ultra_Cache_Engine');
            foreach ($candidates as $class) {
                if (class_exists($class) && method_exists($class, 'get_stats')) {
                    $stats = call_user_func(array($class, 'get_stats'));
                    $stats = is_array($stats) ? $stats : array();
                    break;
                }
            }

            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'get_stats')) {
                $object_stats = Ultra_Cache_Object_Cache_Manager::get_stats((bool) $full_object_count);
                if (is_array($object_stats)) {
                    $stats = array_merge($stats, $object_stats);
                    $stats['cacheSizeBytes'] = (int) ($stats['cacheSizeBytes'] ?? 0) + (int) ($object_stats['objectCacheSizeBytes'] ?? 0);
                    if (function_exists('size_format')) {
                        $stats['cacheSizeHuman'] = size_format((int) $stats['cacheSizeBytes'], 2);
                    }
                }
            }

            $media = self::get_media_instance();
            if ($media && method_exists($media, 'get_stats')) {
                $media_stats = $media->get_stats();
                if (is_array($media_stats)) {
                    $stats = array_merge($stats, $media_stats);
                }
            }

            $stats['cronWarm'] = self::get_cron_warm_status();
            $stats['opcache'] = self::get_opcache_status_summary();
            $stats['apcu'] = self::get_apcu_status_summary();
            $stats['diagnostics'] = self::get_dashboard_diagnostics();
            return $stats;
        }

        private static function get_compression_support_status()
        {
            $brotli    = function_exists('brotli_compress');
            $gzip      = function_exists('gzencode');
            $preferred = $brotli ? 'brotli' : ($gzip ? 'gzip' : 'none');
            $message   = self::maybe_translate('No PHP compression support detected on this server.');

            if ($brotli && $gzip) {
                $message = self::maybe_translate('Brotli and gzip are available. UltraCache will prefer Brotli and fall back to gzip when needed.');
            } elseif ($brotli) {
                $message = self::maybe_translate('Brotli is available on this server. UltraCache will prefer Brotli compression.');
            } elseif ($gzip) {
                $message = self::maybe_translate('Brotli is not available on this server. UltraCache will use gzip compression instead.');
            }

            return array(
                'brotli'    => $brotli,
                'gzip'      => $gzip,
                'preferred' => $preferred,
                'message'   => $message,
            );
        }

        private static function get_frontend_compression_probe_status($allow_live_probe = false)
        {
            $status = array(
                'detected'      => false,
                'gzip'          => false,
                'brotli'        => false,
                'brokenGzip'    => false,
                'brokenBrotli'  => false,
                'message'       => '',
                'cachedOnly'    => false,
                'liveProbe'     => false,
            );

            $cached = get_transient('ucwp_frontend_compression_probe_v1');
            if (is_array($cached)) {
                return array_merge($status, $cached, array('cachedOnly' => true));
            }

            if (!$allow_live_probe) {
                $status['cachedOnly'] = true;
                $status['message'] = 'Frontend compression probe has not run yet. Live loopback probing is skipped during normal settings sanitization.';
                return $status;
            }

            $status['liveProbe'] = true;

            $probe_base = home_url('/');
            if ('' === (string) $probe_base) {
                set_transient('ucwp_frontend_compression_probe_v1', $status, 5 * MINUTE_IN_SECONDS);
                return $status;
            }

            $probe_url = add_query_arg('ucwp_probe_compression', (string) time(), $probe_base);
            $encodings = array(
                'brotli' => 'br',
                'gzip'   => 'gzip',
            );

            foreach ($encodings as $bucket => $accept_encoding) {
                $response = ucwp_safe_loopback_remote_request($probe_url, array(
                    'method'              => 'GET',
                    'timeout'             => 5,
                    'redirection'         => 2,
                    'decompress'          => false,
                    'limit_response_size' => 1,
                    'headers'             => array(
                        'Cache-Control'           => 'no-cache',
                        'Pragma'                  => 'no-cache',
                        'Accept-Encoding'         => $accept_encoding,
                        'X-UltraCache-Compression-Probe' => '1',
                    ),
                ), 'frontend_compression_probe');

                if (is_wp_error($response)) {
                    continue;
                }

                $headers = wp_remote_retrieve_headers($response);
                $content_encoding = strtolower(trim((string) ($headers['content-encoding'] ?? '')));
                $ultracache_encoding = strtolower(trim((string) ($headers['x-ultracache-encoding'] ?? '')));
                $body = (string) wp_remote_retrieve_body($response);
                $gzip_magic = (strlen($body) >= 2 && 0x1f === ord($body[0]) && 0x8b === ord($body[1]));

                if ('' !== $ultracache_encoding) {
                    if ('gzip' === $ultracache_encoding && false === strpos($content_encoding, 'gzip') && $gzip_magic) {
                        $status['brokenGzip'] = true;
                        $status['detected'] = true;
                    }
                    if ('brotli' === $ultracache_encoding && false === strpos($content_encoding, 'br') && '' !== $body) {
                        $status['brokenBrotli'] = true;
                        $status['detected'] = true;
                    }
                    continue;
                }

                if ('brotli' === $bucket && false !== strpos($content_encoding, 'br')) {
                    $status['brotli'] = true;
                    $status['detected'] = true;
                }
                if ('gzip' === $bucket && false !== strpos($content_encoding, 'gzip')) {
                    $status['gzip'] = true;
                    $status['detected'] = true;
                }
            }

            if ($status['brokenBrotli'] && $status['brokenGzip']) {
                $status['message'] = 'UltraCache detected Brotli and gzip compressed output without matching Content-Encoding headers. Plugin compression has been disabled as a safety measure.';
            } elseif ($status['brokenBrotli']) {
                $status['message'] = 'UltraCache detected Brotli compressed output without a matching Content-Encoding header. Brotli has been disabled as a safety measure.';
            } elseif ($status['brokenGzip']) {
                $status['message'] = 'UltraCache detected gzip-compressed output without a matching Content-Encoding header. Gzip has been disabled as a safety measure.';
            } elseif ($status['brotli'] && $status['gzip']) {
                $status['message'] = 'Your server is already using Brotli and gzip compression by default.';
            } elseif ($status['brotli']) {
                $status['message'] = 'Your server is already using Brotli compression by default.';
            } elseif ($status['gzip']) {
                $status['message'] = 'Your server is already using gzip compression by default.';
            }

            set_transient('ucwp_frontend_compression_probe_v1', $status, 5 * MINUTE_IN_SECONDS);
            return $status;
        }

        private static function get_wp_cache_define_status()
        {
            $config = self::get_wp_config_path();
            if (!$config) {
                return array(
                    'status'  => 'missing-config',
                    'message' => self::maybe_translate('wp-config.php could not be located.'),
                );
            }

            $raw_contents = ucwp_safe_file_get_contents($config, 'get_wp_cache_define_status');
            if (false === $raw_contents) {
                return array(
                    'status'  => 'read-failed',
                    'message' => self::maybe_translate('wp-config.php could not be read.'),
                );
            }

            $contents = (string) $raw_contents;
            if (false !== strpos($contents, '// Added by UltraCache')) {
                return array(
                    'status'  => 'managed',
                    'message' => self::maybe_translate('WP_CACHE is managed by UltraCache.'),
                );
            }

            $wp_cache_define = self::get_wp_cache_define_summary($contents);
            if ('true' === $wp_cache_define['status']) {
                return array(
                    'status'  => 'true',
                    'message' => self::maybe_translate('WP_CACHE is already defined as true in wp-config.php.'),
                );
            }

            if ('false' === $wp_cache_define['status']) {
                return array(
                    'status'  => 'false',
                    'message' => self::maybe_translate('WP_CACHE is currently defined as false in wp-config.php and UltraCache will disable that line safely before enabling page cache.'),
                );
            }

            if ('nonstandard' === $wp_cache_define['status']) {
                return array(
                    'status'  => 'nonstandard',
                    'message' => self::maybe_translate('WP_CACHE is defined in a non-standard way in wp-config.php and UltraCache can replace it safely when enabling page cache.'),
                );
            }

            return array(
                'status'  => 'missing',
                'message' => self::maybe_translate('WP_CACHE is not currently defined in wp-config.php. UltraCache can add it automatically.'),
            );
        }


        public static function test_redis_connection(array $settings_override = array())
        {
            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'test_redis_connection')) {
                return Ultra_Cache_Object_Cache_Manager::test_redis_connection($settings_override);
            }

            return array(
                'success' => false,
                'connected' => false,
                'message' => self::maybe_translate('Redis helper not available.'),
            );
        }

        public static function test_object_cache_backend($backend = 'selected', array $settings_override = array())
        {
            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'test_object_cache_backend')) {
                return Ultra_Cache_Object_Cache_Manager::test_object_cache_backend($backend, $settings_override);
            }

            return array(
                'success' => false,
                'message' => self::maybe_translate('Object cache backend test helper not available.'),
            );
        }
        public static function flush_object_cache($backend = 'active')
        {
            if (!class_exists('Ultra_Cache_Object_Cache_Manager') || (!method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache') && !method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache_with_report'))) {
                return array(
                    'success' => false,
                    'message' => self::maybe_translate('Object cache helper not available.'),
                );
            }

            $report = array(
                'success' => false,
                'message' => 'Object cache flush failed.',
            );

            try {
                if (function_exists('wp_suspend_cache_addition')) {
                    wp_suspend_cache_addition(true);
                }

                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache_with_report')) {
                    $report = Ultra_Cache_Object_Cache_Manager::flush_cache_with_report(true, false, $backend);
                } else {
                    $flushed = (bool) Ultra_Cache_Object_Cache_Manager::flush_cache(true, false);
                    $report = array(
                        'success' => $flushed,
                        'message' => $flushed ? 'Object cache flushed.' : 'Object cache flush failed.',
                    );
                }

                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'reset_metrics')) {
                    Ultra_Cache_Object_Cache_Manager::reset_metrics();
                }
            } finally {
                if (function_exists('wp_suspend_cache_addition')) {
                    wp_suspend_cache_addition(false);
                }
            }

            return is_array($report) ? $report : array(
                'success' => false,
                'message' => 'Object cache flush failed.',
            );
        }



        private static function get_media_support_status()
        {
            $media = self::get_media_instance();
            if ($media && method_exists($media, 'get_support_status')) {
                $status = $media->get_support_status();
                return is_array($status) ? $status : array('supported' => false);
            }

            return array('supported' => false);
        }

        private static function get_current_url_without_plugin_args()
        {
            if (empty($_SERVER['HTTP_HOST']) || empty($_SERVER['REQUEST_URI'])) {
                return '';
            }

            $is_ssl = ucwp_server_flag_enabled('HTTPS')
                || ('443' === ucwp_server_value('SERVER_PORT'));
            $scheme = $is_ssl ? 'https://' : 'http://';
            $host = ucwp_get_validated_http_host(ucwp_server_value('HTTP_HOST'), 'plugin_current_url');
            if ('' === $host) {
                return '';
            }

            $url = $scheme . $host . ucwp_server_value('REQUEST_URI');

            return esc_url_raw(remove_query_arg(array('ucwp_action', '_wpnonce'), $url));
        }
    }
}

if (!function_exists('ucwp_ultracache')) {
    function ucwp_ultracache()
    {
        return Ultra_Cache_WP::instance();
    }
}

register_activation_hook(__FILE__, array('Ultra_Cache_WP', 'activate'));
register_deactivation_hook(__FILE__, array('Ultra_Cache_WP', 'deactivate'));
ucwp_ultracache();
