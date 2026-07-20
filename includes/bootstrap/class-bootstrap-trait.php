<?php
/**
 * Bootstrap dependency loading, hook registration, and component startup.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Bootstrap_Trait
{
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        ultracache_request_profile_checkpoint('ultracache_wp_construct_start');
        $this->register_hooks();
        ultracache_request_profile_checkpoint('ultracache_hooks_registered');
    }

    private function load_rest_api_dependency()
    {
        return class_exists('Ultra_Cache_Rest_API');
    }

    private function load_wp_cli_dependency()
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return false;
        }

        return class_exists('Ultra_Cache_WP_CLI');
    }

    private function register_request_profile_hooks()
    {
        if (!ultracache_request_profiler_enabled()) {
            return;
        }

        $hooks = array(
            array('plugins_loaded', -1000, 'plugins_loaded_p-1000'),
            array('plugins_loaded', 0, 'plugins_loaded_p0'),
            array('plugins_loaded', 5, 'plugins_loaded_p5_components'),
            array('plugins_loaded', 18, 'plugins_loaded_p18_before_reconcile'),
            array('plugins_loaded', 19, 'plugins_loaded_p19_before_page_cache_reconcile'),
            array('plugins_loaded', 20, 'plugins_loaded_p20_before_object_cache_reconcile'),
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
                ultracache_request_profile_checkpoint($hook[2]);
            }, $hook[1]);
        }


        if (ultracache_request_callback_profiler_enabled()) {
            add_action('init', function () {
                ultracache_request_profile_checkpoint('callback_profiler_wrap_init', array('target_priorities' => array('1', '2', '5', '10', '20')));
                ultracache_request_profile_wrap_hook_callbacks('init', array(1, 2, 5, 10, 20));
            }, 0);


            add_action('wp_loaded', function () {
                ultracache_request_profile_checkpoint('callback_profiler_wrap_pre_template');
                ultracache_request_profile_wrap_hook_callbacks('template_redirect');
                ultracache_request_profile_wrap_hook_callbacks('wp_enqueue_scripts');
                ultracache_request_profile_wrap_hook_callbacks('wp_calculate_image_srcset');
                ultracache_request_profile_wrap_hook_callbacks('shutdown');
            }, 1000);

            add_action('wp_head', function () {
                ultracache_request_profile_checkpoint('callback_profiler_wrap_pre_head_output');
                ultracache_request_profile_wrap_hook_callbacks('wp_enqueue_scripts');
                ultracache_request_profile_wrap_hook_callbacks('style_loader_src');
                ultracache_request_profile_wrap_hook_callbacks('style_loader_tag');
                ultracache_request_profile_wrap_hook_callbacks('script_loader_src');
                ultracache_request_profile_wrap_hook_callbacks('script_loader_tag');
                ultracache_request_profile_wrap_hook_callbacks('wp_calculate_image_srcset');
            }, -999);
        }
    }

    private function register_hooks()
    {
        $this->register_request_profile_hooks();
        add_action('plugins_loaded', array($this, 'bootstrap_components'), 5);
        add_action('rest_api_init', array($this, 'bootstrap_rest_api'), 0);
        if (self::should_run_bootstrap_reconcile_hooks()) {
            add_action('plugins_loaded', array($this, 'reconcile_page_cache_dropin'), 19);
            add_action('plugins_loaded', array($this, 'reconcile_object_cache_dropin'), 20);
        }
        add_action('init', array($this, 'maybe_mark_ultracache_admin_no_cache'), 0);
        add_action('admin_init', array(__CLASS__, 'register_dashboard_setting'), 0);
        add_action('admin_init', array(__CLASS__, 'cleanup_legacy_dropin_backup_directory'), 1);
        add_action('admin_init', array(__CLASS__, 'maybe_migrate_varnish_html_ttl_default'), 2);
        add_action('template_redirect', array($this, 'maybe_serve_html_compression_probe'), -10000);
        add_action('admin_init', array($this, 'maybe_send_ultracache_admin_no_cache_headers'), 0);
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_plugin_deactivation_assets'));
        add_action('wp_ajax_ultracache_save_uninstall_cleanup_policy', array($this, 'handle_save_uninstall_cleanup_policy'));
        add_filter('admin_body_class', array($this, 'add_ultracache_admin_theme_body_class'));
        add_action('admin_enqueue_scripts', array($this, 'suppress_conflicting_admin_assets'), 999);
        // This late page-specific hook only dequeues the same dashboard-only conflicting assets after registration.
        add_action('admin_print_scripts-toplevel_page_ultracache', array($this, 'suppress_conflicting_admin_assets'), 1);
        add_action('admin_print_footer_scripts-toplevel_page_ultracache', array($this, 'suppress_conflicting_admin_assets'), 1);
        add_action('admin_notices', array($this, 'render_admin_notice'));
        add_action('admin_bar_menu', array($this, 'register_admin_bar_menu'), 100);
        add_action('init', array($this, 'handle_admin_bar_actions'));
        add_filter('cron_schedules', array($this, 'register_cron_schedules'));
        add_action('ultracache_scheduled_cache_cleanup', array($this, 'handle_scheduled_cache_cleanup'));
        add_action('ultracache_cron_warm_tick', array($this, 'handle_cron_warm_tick'));
        add_action('ultracache_cron_warm_tick_kickoff', array($this, 'handle_cron_warm_tick_kickoff'));
        add_filter('upgrader_install_package_result', array($this, 'track_upgrader_install_package_result'), 20, 2);
        add_action('upgrader_process_complete', array($this, 'handle_upgrader_process_complete'), 20, 2);
        add_action('ultracache_after_purge_all', array($this, 'handle_varnish_after_purge_all'), 10, 1);
        add_action('ultracache_after_purge_all', array($this, 'handle_cron_warm_after_purge_all'), 20, 1);
        add_action('ultracache_after_purge_urls', array($this, 'handle_varnish_after_purge_urls'), 10, 3);
        add_action('wp_loaded', array($this, 'maybe_fix_revslider_footer_conflict'), 1);
    }

    public function bootstrap_components()
    {
        $component_classes = array(
            array('Ultra_Cache_Engine', 'get_instance'),
            array('Ultra_Cache_Media_Converter', 'get_instance'),
        );

        foreach ($component_classes as $component) {
            list($class, $method) = $component;
            if (class_exists($class) && method_exists($class, $method)) {
                call_user_func(array($class, $method));
            }
        }

        ultracache_request_profile_checkpoint('ultracache_dependencies_loaded');
        $this->bootstrap_wp_cli();
    }

    public function bootstrap_rest_api()
    {
        if (!$this->load_rest_api_dependency()) {
            return;
        }

        if (!class_exists('Ultra_Cache_Rest_API') || !method_exists('Ultra_Cache_Rest_API', 'get_instance')) {
            return;
        }

        $rest_api = Ultra_Cache_Rest_API::get_instance();
        if ($rest_api && method_exists($rest_api, 'register_routes')) {
            $rest_api->register_routes();
        }
    }

    public function bootstrap_wp_cli()
    {
        if (!$this->load_wp_cli_dependency()) {
            return;
        }

        if (class_exists('Ultra_Cache_WP_CLI') && method_exists('Ultra_Cache_WP_CLI', 'register')) {
            Ultra_Cache_WP_CLI::register();
        }
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

            case 'Server-side compression is already active. UltraCache compression was not enabled.':
                return __('Server-side compression is already active. UltraCache compression was not enabled.', 'ultracache');

            case 'Server-managed HTML compression is active.':
                return __('Server-managed HTML compression is active.', 'ultracache');

            case 'Gzip and Brotli compression are available.':
                return __('Gzip and Brotli compression are available.', 'ultracache');

            case 'Brotli compression is available.':
                return __('Brotli compression is available.', 'ultracache');

            case 'Gzip compression is available.':
                return __('Gzip compression is available.', 'ultracache');

            case 'No supported HTML compression encoder is available.':
                return __('No supported HTML compression encoder is available.', 'ultracache');

            case 'The existing HTML page cache could not be cleared before changing the delivery mode. Please retry the setting change.':
                return __('The existing HTML page cache could not be cleared before changing the delivery mode. Please retry the setting change.', 'ultracache');

            case 'wp-config.php could not be located.':
                return __('wp-config.php could not be located.', 'ultracache');

            case 'wp-config.php could not be read.':
                return __('wp-config.php could not be read.', 'ultracache');

            case 'WP_CACHE is managed by UltraCache.':
                return __('WP_CACHE is managed by UltraCache.', 'ultracache');

            case 'WP_CACHE is already defined as true outside the UltraCache managed block.':
                return __('WP_CACHE is already defined as true outside the UltraCache managed block.', 'ultracache');

            case 'WP_CACHE is defined as false outside the UltraCache managed block and must be changed manually before page cache can be enabled.':
                return __('WP_CACHE is defined as false outside the UltraCache managed block and must be changed manually before page cache can be enabled.', 'ultracache');

            case 'WP_CACHE is not currently defined in wp-config.php. UltraCache can add it to its managed block.':
                return __('WP_CACHE is not currently defined in wp-config.php. UltraCache can add it to its managed block.', 'ultracache');

            case 'WP_CACHE is defined outside the UltraCache managed block in a non-standard way and must be changed manually before page cache can be enabled.':
                return __('WP_CACHE is defined outside the UltraCache managed block in a non-standard way and must be changed manually before page cache can be enabled.', 'ultracache');

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

            case 'Cron warm up is blocked while a manual warm-up is active or paused.':
                return __('Cron warm up is blocked while a manual warm-up is active or paused.', 'ultracache');

            case 'Cron warm up stopped because a manual warm-up has priority.':
                return __('Cron warm up stopped because a manual warm-up has priority.', 'ultracache');

            case 'Cron warm up skipped because a manual warm-up has priority.':
                return __('Cron warm up skipped because a manual warm-up has priority.', 'ultracache');

            case 'Scheduled cache cleanup was skipped because a manual warm-up has priority.':
                return __('Scheduled cache cleanup was skipped because a manual warm-up has priority.', 'ultracache');

            case 'Invalid manual warm-up job type.':
                return __('Invalid manual warm-up job type.', 'ultracache');

            case 'Another administrator has an active or paused manual warm-up.':
                return __('Another administrator has an active or paused manual warm-up.', 'ultracache');

            case 'Manual warm-up started with priority over cron warm-up.':
                return __('Manual warm-up started with priority over cron warm-up.', 'ultracache');

            case 'Manual warm-up ownership could not be verified.':
                return __('Manual warm-up ownership could not be verified.', 'ultracache');

            case 'Manual warm-up paused. Cron warm-up remains blocked.':
                return __('Manual warm-up paused. Cron warm-up remains blocked.', 'ultracache');

            case 'Manual warm-up ownership released.':
                return __('Manual warm-up ownership released.', 'ultracache');

            case 'Cron warm up queue is idle.':
                return __('Cron warm up queue is idle.', 'ultracache');

            case 'Cron warm up tick skipped because another run is active.':
                return __('Cron warm up tick skipped because another run is active.', 'ultracache');

            case 'Varnish integration is disabled.':
                return __('Varnish integration is disabled.', 'ultracache');

            case 'Varnish integration is unavailable.':
                return __('Varnish integration is unavailable.', 'ultracache');

            case 'No Varnish endpoints are configured.':
                return __('No Varnish endpoints are configured.', 'ultracache');

            case 'Could not determine site host for Varnish.':
                return __('Could not determine site host for Varnish.', 'ultracache');

            case 'Invalid URL for Varnish purge.':
                return __('Invalid URL for Varnish purge.', 'ultracache');

            case 'No eligible local cache URLs were available for Varnish invalidation.':
                return __('No eligible local cache URLs were available for Varnish invalidation.', 'ultracache');

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

                case 'Varnish %1$s invalidated %2$d unique URL(s) with %3$d request(s).':
                    /* translators: 1: Varnish action label, 2: unique URL count, 3: request count. */
                    $translated = __('Varnish %1$s invalidated %2$d unique URL(s) with %3$d request(s).', 'ultracache');
                    break;

                case 'Varnish %s failed on one or more invalidation requests.':
                    /* translators: %s: Varnish action label. */
                    $translated = __('Varnish %s failed on one or more invalidation requests.', 'ultracache');
                    break;

                case 'Batch %1$d/%2$d · %3$d URL(s) · %4$s':
                    /* translators: 1: current batch, 2: total batches, 3: URL count, 4: endpoint response detail. */
                    $translated = __('Batch %1$d/%2$d · %3$d URL(s) · %4$s', 'ultracache');
                    break;

                case 'URL %1$d/%2$d · %3$s':
                    /* translators: 1: current URL, 2: total URLs, 3: endpoint response detail. */
                    $translated = __('URL %1$d/%2$d · %3$s', 'ultracache');
                    break;
            }
        }

        if (empty($args)) {
            return $translated;
        }

        return vsprintf($translated, $args);
    }

}
