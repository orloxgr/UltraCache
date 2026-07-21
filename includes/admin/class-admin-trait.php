<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Admin_Trait
{

    public function register_admin_menu()
    {
        add_menu_page(
            __('UltraCache', 'ultracache'),
            __('UltraCache', 'ultracache'),
            'manage_options',
            'ultracache',
            array($this, 'render_dashboard'),
            'dashicons-performance',
            100
        );
    }


    public static function is_ultracache_admin_dashboard_request()
    {
        if (!function_exists('is_admin') || !is_admin()) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page detection; no state-changing action is performed.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ('ultracache' === $page) {
            return true;
        }

        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && 'toplevel_page_ultracache' === (string) $screen->id) {
                return true;
            }
        }

        return false;
    }

    public static function send_ultracache_admin_no_cache_headers()
    {
        if (headers_sent()) {
            return;
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', true);
        header('Pragma: no-cache', true);
        header('Expires: Wed, 11 Jan 1984 05:00:00 GMT', true);
        header('X-Accel-Expires: 0', true);
        header('Surrogate-Control: no-store', true);
        header('CDN-Cache-Control: no-store', true);
        header('X-LiteSpeed-Cache-Control: no-cache', true);
        header('X-UltraCache-Admin-No-Cache: 1', true);
    }

    public function maybe_mark_ultracache_admin_no_cache()
    {
        if (!self::is_ultracache_admin_dashboard_request()) {
            return;
        }

        if (!defined('DONOTCACHEPAGE')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress cache plugins use DONOTCACHEPAGE as the standard no-cache signal.
            define('DONOTCACHEPAGE', true);
        }
    }

    public function maybe_send_ultracache_admin_no_cache_headers()
    {
        if (!self::is_ultracache_admin_dashboard_request()) {
            return;
        }

        $this->maybe_mark_ultracache_admin_no_cache();
        self::send_ultracache_admin_no_cache_headers();
    }

    public static function get_ultracache_admin_theme_preference()
    {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return 'native';
        }

        $theme = sanitize_key((string) get_user_meta($user_id, 'ultracache_admin_theme', true));
        return 'ultracache' === $theme ? 'ultracache' : 'native';
    }

    public function add_ultracache_admin_theme_body_class($classes)
    {
        if (!self::is_ultracache_admin_dashboard_request()) {
            return $classes;
        }

        $theme = self::get_ultracache_admin_theme_preference();
        return trim((string) $classes . ' ultracache-admin-theme-' . $theme);
    }

    public function render_dashboard()
    {
        $help_label = self::maybe_translate_sprintf(
            'Help for %s',
            (string) ULTRACACHE_VERSION
        );
        $help_aria_label = self::maybe_translate_sprintf(
            'Open help for UltraCache %s',
            (string) ULTRACACHE_VERSION
        );
        $rate_label = __('Rate us!', 'ultracache');
        $rate_aria_label = __('Open UltraCache reviews on WordPress.org', 'ultracache');
        $rate_url = 'https://wordpress.org/support/plugin/ultracache/reviews/#new-post';

        $admin_theme = self::get_ultracache_admin_theme_preference();

        echo '<div id="uc-dashboard" data-uc-theme="' . esc_attr($admin_theme) . '"></div>';
        echo '<a id="ultracache-rate-trigger" class="ultracache-version-badge ultracache-rate-badge" href="' . esc_url($rate_url) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr($rate_aria_label) . '">' . esc_html($rate_label) . '</a>';
        echo '<button type="button" id="ultracache-version-help-trigger" class="ultracache-version-badge" aria-label="' . esc_attr($help_aria_label) . '" aria-haspopup="dialog" aria-expanded="false" aria-controls="uc-version-help-modal">' . esc_html($help_label) . '</button>';
        echo '<div id="ultracache-root" style="display:none"></div>';
        echo '<div id="ultracache-admin-root" style="display:none"></div>';
    }

    public function enqueue_admin_assets($hook)
    {
        if ('toplevel_page_ultracache' !== $hook) {
            return;
        }

        wp_register_style(
            'ultracache-admin-css-tokens',
            ultracache_plugin_url('includes/admin/css/tokens.css'),
            array(),
            ULTRACACHE_VERSION
        );
        wp_register_style(
            'ultracache-admin-css-foundation',
            ultracache_plugin_url('includes/admin/css/foundation.css'),
            array('ultracache-admin-css-tokens'),
            ULTRACACHE_VERSION
        );
        wp_register_style(
            'ultracache-admin-css-components',
            ultracache_plugin_url('includes/admin/css/components.css'),
            array('ultracache-admin-css-foundation'),
            ULTRACACHE_VERSION
        );
        wp_register_style(
            'ultracache-admin-css-sections',
            ultracache_plugin_url('includes/admin/css/sections.css'),
            array('ultracache-admin-css-components'),
            ULTRACACHE_VERSION
        );
        wp_register_style(
            'ultracache-admin-css',
            ultracache_plugin_url('includes/admin/css/themes.css'),
            array('ultracache-admin-css-sections'),
            ULTRACACHE_VERSION
        );
        wp_enqueue_style('ultracache-admin-css');

        $admin_script_manifest = array(
            'ultracache-admin-namespace' => array(
                'path'      => 'includes/admin/js/namespace.js',
                'deps'      => array(),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-core' => array(
                'path'      => 'includes/admin/js/core.js',
                'deps'      => array('wp-element', 'wp-i18n', 'ultracache-admin-namespace'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-api' => array(
                'path'      => 'includes/admin/js/api.js',
                'deps'      => array('ultracache-admin-namespace'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-settings' => array(
                'path'      => 'includes/admin/js/settings.js',
                'deps'      => array('wp-i18n', 'ultracache-admin-core'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-dashboard-settings-actions' => array(
                'path'      => 'includes/admin/js/dashboard-settings-actions.js',
                'deps'      => array('wp-i18n', 'ultracache-admin-core', 'ultracache-admin-api', 'ultracache-admin-settings'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-help' => array(
                'path'      => 'includes/admin/js/help.js',
                'deps'      => array('ultracache-admin-core'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-ui' => array(
                'path'      => 'includes/admin/js/ui.js',
                'deps'      => array('wp-element', 'wp-i18n', 'ultracache-admin-core'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-theme' => array(
                'path'      => 'includes/admin/js/theme.js',
                'deps'      => array('wp-element', 'wp-i18n', 'ultracache-admin-core', 'ultracache-admin-api'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-diagnostics' => array(
                'path'      => 'includes/admin/js/diagnostics.js',
                'deps'      => array('wp-element', 'wp-i18n', 'ultracache-admin-core', 'ultracache-admin-api', 'ultracache-admin-settings', 'ultracache-admin-help', 'ultracache-admin-ui'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-dashboard-sections' => array(
                'path'      => 'includes/admin/js/dashboard-sections.js',
                'deps'      => array('wp-element', 'wp-i18n', 'ultracache-admin-core', 'ultracache-admin-ui'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-dashboard-diagnostics-ui' => array(
                'path'      => 'includes/admin/js/dashboard-diagnostics-ui.js',
                'deps'      => array('wp-element', 'wp-i18n', 'ultracache-admin-core', 'ultracache-admin-ui'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-jobs' => array(
                'path'      => 'includes/admin/js/jobs.js',
                'deps'      => array('ultracache-admin-namespace', 'ultracache-admin-diagnostics'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-warmup' => array(
                'path'      => 'includes/admin/js/warmup.js',
                'deps'      => array('wp-i18n', 'ultracache-admin-core', 'ultracache-admin-api'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-media' => array(
                'path'      => 'includes/admin/js/media.js',
                'deps'      => array('wp-i18n', 'ultracache-admin-core', 'ultracache-admin-api'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-media-replacement' => array(
                'path'      => 'includes/admin/js/media-replacement.js',
                'deps'      => array('wp-element', 'wp-i18n', 'ultracache-admin-core', 'ultracache-admin-api', 'ultracache-admin-jobs'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-media-replacement-ui' => array(
                'path'      => 'includes/admin/js/media-replacement-ui.js',
                'deps'      => array('wp-element', 'wp-i18n', 'ultracache-admin-core', 'ultracache-admin-media-replacement'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-cache-shared' => array(
                'path'      => 'includes/admin/js/cache-shared.js',
                'deps'      => array('wp-element', 'wp-i18n', 'ultracache-admin-core', 'ultracache-admin-ui'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-varnish-refresh-ahead' => array(
                'path'      => 'includes/admin/js/varnish-refresh-ahead.js',
                'deps'      => array('wp-element', 'wp-i18n', 'ultracache-admin-core', 'ultracache-admin-ui'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-varnish-flush-scope' => array(
                'path'      => 'includes/admin/js/varnish-flush-scope.js',
                'deps'      => array('wp-element', 'wp-i18n', 'ultracache-admin-core', 'ultracache-admin-ui'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-varnish' => array(
                'path'      => 'includes/admin/js/varnish.js',
                'deps'      => array('wp-element', 'wp-i18n', 'ultracache-admin-core', 'ultracache-admin-api', 'ultracache-admin-ui', 'ultracache-admin-cache-shared', 'ultracache-admin-varnish-refresh-ahead', 'ultracache-admin-varnish-flush-scope'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-cache' => array(
                'path'      => 'includes/admin/js/cache.js',
                'deps'      => array('wp-element', 'wp-i18n', 'ultracache-admin-core', 'ultracache-admin-api', 'ultracache-admin-help', 'ultracache-admin-ui', 'ultracache-admin-cache-shared', 'ultracache-admin-varnish'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-lifecycle' => array(
                'path'      => 'includes/admin/js/lifecycle.js',
                'deps'      => array('wp-element', 'wp-i18n', 'ultracache-admin-core'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-dashboard-application' => array(
                'path'      => 'includes/admin/js/dashboard-application.js',
                'deps'      => array('wp-element', 'wp-i18n', 'ultracache-admin-core', 'ultracache-admin-api', 'ultracache-admin-settings', 'ultracache-admin-dashboard-settings-actions', 'ultracache-admin-dashboard-sections', 'ultracache-admin-dashboard-diagnostics-ui', 'ultracache-admin-help', 'ultracache-admin-ui', 'ultracache-admin-theme', 'ultracache-admin-diagnostics', 'ultracache-admin-jobs', 'ultracache-admin-warmup', 'ultracache-admin-media', 'ultracache-admin-media-replacement', 'ultracache-admin-media-replacement-ui', 'ultracache-admin-cache', 'ultracache-admin-lifecycle'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-app' => array(
                'path'      => 'includes/admin/js/app.js',
                'deps'      => array('ultracache-admin-dashboard-application'),
                'in_footer' => true,
                'module'    => false,
            ),
            'ultracache-admin-js' => array(
                'path'      => 'includes/admin-dashboard.js',
                'deps'      => array('ultracache-admin-app'),
                'in_footer' => true,
                'module'    => true,
            ),
        );

        foreach ($admin_script_manifest as $handle => $asset) {
            wp_register_script(
                $handle,
                ultracache_plugin_url($asset['path']),
                $asset['deps'],
                ULTRACACHE_VERSION,
                array('in_footer' => $asset['in_footer'])
            );

            if (!empty($asset['module'])) {
                wp_script_add_data($handle, 'type', 'module');
            }

            wp_enqueue_script($handle);
        }

        wp_set_script_translations('ultracache-admin-settings', 'ultracache', ultracache_plugin_dir('languages'));
        wp_set_script_translations('ultracache-admin-dashboard-settings-actions', 'ultracache', ultracache_plugin_dir('languages'));
        wp_set_script_translations('ultracache-admin-dashboard-sections', 'ultracache', ultracache_plugin_dir('languages'));
        wp_set_script_translations('ultracache-admin-dashboard-diagnostics-ui', 'ultracache', ultracache_plugin_dir('languages'));
        wp_set_script_translations('ultracache-admin-ui', 'ultracache', ultracache_plugin_dir('languages'));
        wp_set_script_translations('ultracache-admin-theme', 'ultracache', ultracache_plugin_dir('languages'));
        wp_set_script_translations('ultracache-admin-diagnostics', 'ultracache', ultracache_plugin_dir('languages'));
        wp_set_script_translations('ultracache-admin-warmup', 'ultracache', ultracache_plugin_dir('languages'));
        wp_set_script_translations('ultracache-admin-media', 'ultracache', ultracache_plugin_dir('languages'));
        wp_set_script_translations('ultracache-admin-media-replacement', 'ultracache', ultracache_plugin_dir('languages'));
        wp_set_script_translations('ultracache-admin-media-replacement-ui', 'ultracache', ultracache_plugin_dir('languages'));
        wp_set_script_translations('ultracache-admin-cache', 'ultracache', ultracache_plugin_dir('languages'));
        wp_set_script_translations('ultracache-admin-lifecycle', 'ultracache', ultracache_plugin_dir('languages'));
        wp_set_script_translations('ultracache-admin-dashboard-application', 'ultracache', ultracache_plugin_dir('languages'));
        wp_set_script_translations('ultracache-admin-app', 'ultracache', ultracache_plugin_dir('languages'));
        wp_set_script_translations('ultracache-admin-js', 'ultracache', ultracache_plugin_dir('languages'));

        $settings_for_client = self::get_dashboard_settings_for_client();
        $cache_stats_enabled = !empty($settings_for_client['cacheStatsEnabled']);

        if (!$cache_stats_enabled && method_exists(__CLASS__, 'get_cache_stats_disabled_payload')) {
            $dashboard_stats = self::get_cache_stats_disabled_payload('admin_bootstrap_disabled');
        } else {
            $dashboard_stats = method_exists(__CLASS__, 'get_dashboard_stats_snapshot') ? self::get_dashboard_stats_snapshot(60, false) : array('success' => true);
        }

        // Runtime cache cards are lightweight admin visibility tools, not
        // counter/stat collection. Keep them available on first dashboard load
        // even when cache stats are passive or an old cached stats snapshot did
        // not contain OPcache/APCu/external-cache payloads yet.
        if (empty($dashboard_stats['opcache']) && method_exists(__CLASS__, 'get_opcache_status_summary')) {
            $dashboard_stats['opcache'] = self::get_opcache_status_summary();
        }
        if (empty($dashboard_stats['apcu']) && method_exists(__CLASS__, 'get_apcu_status_summary')) {
            $dashboard_stats['apcu'] = self::get_apcu_status_summary();
        }
        if (empty($dashboard_stats['externalCaches']) && method_exists(__CLASS__, 'get_external_cache_detection')) {
            $dashboard_stats['externalCaches'] = self::get_external_cache_detection(false);
        }

        // Cache Statistics OFF must not turn the dashboard into a dummy shell.
        // Counter/stat collection and polling remain disabled through
        // $dashboard_stats, but read-only diagnostics are loaded once during
        // the UltraCache admin bootstrap so boxes such as Advanced Diagnostics,
        // Server/PHP environment, media runtime, storage, and warm-up source
        // selectors do not depend on enabling Cache Statistics first.
        $dashboard_diagnostics = method_exists(__CLASS__, 'get_dashboard_diagnostics')
            ? self::get_dashboard_diagnostics()
            : (isset($dashboard_stats['diagnostics']) && is_array($dashboard_stats['diagnostics']) ? $dashboard_stats['diagnostics'] : array());

        $media_file_counts = array(
            'total'        => 0,
            'avif'         => 0,
            'webp'         => 0,
            'initialized'  => false,
            'needsRecount' => true,
            'updatedAt'    => 0,
            'recountedAt'  => 0,
        );
        $media = self::get_media_instance();
        if ($media && method_exists($media, 'get_media_file_counts')) {
            $current_counts = $media->get_media_file_counts();
            if (is_array($current_counts)) {
                $media_file_counts = $current_counts;
            }
        }

        $ultracache_runtime_config = array(
            'restBase'     => esc_url_raw(rest_url('ultracache/v1/')),
            'restNonce'    => wp_create_nonce('wp_rest'),
            'runtimeJsScanNonce' => wp_create_nonce('ultracache_runtime_js_scan'),
            'frontendProbeUrl' => esc_url_raw(home_url('/')),
            'version'      => ULTRACACHE_VERSION,
            'adminTheme'   => self::get_ultracache_admin_theme_preference(),
            'canManageInfrastructure' => current_user_can('manage_options') && (current_user_can('activate_plugins') || current_user_can('manage_network_plugins')),
            'stats'        => $dashboard_stats,
            'mediaFileCounts' => $media_file_counts,
            'mediaLibraryReplacementStatus' => ($media && method_exists($media, 'get_media_library_replacement_workflow_status')) ? $media->get_media_library_replacement_workflow_status() : array(),
            'settings'     => $settings_for_client,
            'defaults'     => self::get_dashboard_defaults_for_client(),
            'jsDelayDeferRecommendedExclusions' => implode("\n", self::get_broad_wp_dependency_preset_patterns()),
            'avifSupport'  => self::get_media_support_status(),
            'diagnostics'  => $dashboard_diagnostics,
            'crawlScopeSummary' => self::get_crawl_scope_summary(),
            'warmupGeneration' => method_exists(__CLASS__, 'get_warmup_generation') ? self::get_warmup_generation() : 0,
            'publicPaths' => array(
                'admin' => function_exists('ultracache_wordpress_admin_public_path') ? ultracache_wordpress_admin_public_path() : '',
                'includes' => function_exists('ultracache_wordpress_includes_public_path') ? ultracache_wordpress_includes_public_path() : '',
                'uploads' => function_exists('ultracache_uploads_public_path') ? ultracache_uploads_public_path() : '',
                'generatedAssets' => function_exists('ultracache_generated_asset_public_path') ? ultracache_generated_asset_public_path() : '',
                'plugins' => function_exists('ultracache_plugins_public_path') ? ultracache_plugins_public_path() : '',
                'themes' => function_exists('ultracache_themes_public_paths') ? ultracache_themes_public_paths() : array(),
                'woocommerce' => function_exists('ultracache_plugins_public_path') ? ultracache_plugins_public_path('woocommerce') : '',
                'jquery' => function_exists('ultracache_wordpress_includes_public_path') ? ultracache_wordpress_includes_public_path('js/jquery/jquery.min.js') : '',
                'wpUtil' => function_exists('ultracache_wordpress_includes_public_path') ? ultracache_wordpress_includes_public_path('js/wp-util.min.js') : '',
                'apiFetch' => function_exists('ultracache_wordpress_includes_public_path') ? ultracache_wordpress_includes_public_path('js/dist/api-fetch.min.js') : '',
            ),
        );
        $ultracache_runtime_config_json = wp_json_encode($ultracache_runtime_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (false === $ultracache_runtime_config_json) {
            $ultracache_runtime_config_json = '{}';
        }
        wp_add_inline_script('ultracache-admin-js', 'window.ultracacheData = ' . $ultracache_runtime_config_json . ';', 'before');
    }

    /**
     * Enqueue the lightweight plugin-list deactivation dialog.
     *
     * The dialog only stores the uninstall cleanup policy, then resumes the
     * standard WordPress deactivation URL. WordPress performs the actual
     * plugin deletion later through uninstall.php.
     *
     * @param string $hook Current admin page hook suffix.
     * @return void
     */
    public function enqueue_plugin_deactivation_assets($hook)
    {
        if ('plugins.php' !== (string) $hook) {
            return;
        }

        $network_admin = function_exists('is_network_admin') && is_network_admin();
        $required_capability = $network_admin ? 'manage_network_plugins' : 'activate_plugins';
        if (!current_user_can($required_capability)) {
            return;
        }

        wp_register_style(
            'ultracache-plugin-deactivation',
            ultracache_plugin_url('includes/admin/css/plugin-deactivation.css'),
            array(),
            ULTRACACHE_VERSION
        );
        wp_enqueue_style('ultracache-plugin-deactivation');

        wp_register_script(
            'ultracache-plugin-deactivation',
            ultracache_plugin_url('includes/admin/js/plugin-deactivation.js'),
            array(),
            ULTRACACHE_VERSION,
            array('in_footer' => true)
        );
        wp_enqueue_script('ultracache-plugin-deactivation');

        $settings = get_option(ULTRACACHE_SETTINGS_KEY, array());
        $current_policy = is_array($settings) && isset($settings['uninstallCleanupPolicy'])
            ? self::sanitize_uninstall_cleanup_policy($settings['uninstallCleanupPolicy'])
            : 'delete_everything';

        $config = array(
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('ultracache_save_uninstall_cleanup_policy'),
            'pluginBasename' => ULTRACACHE_BASENAME,
            'currentPolicy'  => $current_policy,
            'adminTheme'     => self::get_ultracache_admin_theme_preference(),
            'title'          => __('UltraCache deactivation', 'ultracache'),
            'intro'          => __('Choose what UltraCache should remove if you delete the plugin after deactivation.', 'ultracache'),
            'cancelLabel'    => __('Cancel', 'ultracache'),
            'confirmLabel'   => __('Save choice and deactivate', 'ultracache'),
            'savingLabel'    => __('Saving…', 'ultracache'),
            'errorLabel'     => __('The uninstall cleanup policy could not be saved. UltraCache was not deactivated.', 'ultracache'),
            'options'        => array(
                array(
                    'value'       => 'plugin_only',
                    'label'       => __('Only deactivate/delete the plugin', 'ultracache'),
                    'description' => __('Keep settings, custom tables, runtime/cache files, and converted media.', 'ultracache'),
                ),
                array(
                    'value'       => 'keep_settings',
                    'label'       => __('Keep plugin settings', 'ultracache'),
                    'description' => __('Keep settings. Remove custom tables and runtime/cache files when the plugin is deleted.', 'ultracache'),
                ),
                array(
                    'value'       => 'keep_settings_tables',
                    'label'       => __('Keep plugin settings and tables', 'ultracache'),
                    'description' => __('Keep settings and UltraCache custom tables. Remove runtime/cache files when the plugin is deleted.', 'ultracache'),
                ),
                array(
                    'value'       => 'delete_everything',
                    'label'       => __('Delete everything', 'ultracache'),
                    'description' => __('Remove UltraCache settings, custom tables, and runtime/cache files. Converted AVIF/WebP media folders remain.', 'ultracache'),
                ),
            ),
        );

        $config_json = wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (false === $config_json) {
            $config_json = '{}';
        }

        wp_add_inline_script(
            'ultracache-plugin-deactivation',
            'window.UltraCachePluginDeactivation = ' . $config_json . ';',
            'before'
        );
    }

    /**
     * Save the selected uninstall cleanup policy before normal deactivation.
     *
     * @return void
     */
    public function handle_save_uninstall_cleanup_policy()
    {
        check_ajax_referer('ultracache_save_uninstall_cleanup_policy', 'nonce');

        if (!current_user_can('activate_plugins') && !current_user_can('manage_network_plugins')) {
            wp_send_json_error(
                array('message' => __('You are not allowed to deactivate plugins.', 'ultracache')),
                403
            );
        }

        $policy = isset($_POST['policy'])
            ? self::sanitize_uninstall_cleanup_policy(sanitize_text_field(wp_unslash($_POST['policy'])))
            : 'delete_everything';

        $settings = get_option(ULTRACACHE_SETTINGS_KEY, array());
        if (!is_array($settings)) {
            $settings = array();
        }

        $settings['uninstallCleanupPolicy'] = $policy;
        update_option(ULTRACACHE_SETTINGS_KEY, $settings, false);
        self::reset_settings_cache();

        $stored_settings = get_option(ULTRACACHE_SETTINGS_KEY, array());
        $stored_policy = is_array($stored_settings) && isset($stored_settings['uninstallCleanupPolicy'])
            ? self::sanitize_uninstall_cleanup_policy($stored_settings['uninstallCleanupPolicy'])
            : '';

        if ($policy !== $stored_policy) {
            wp_send_json_error(
                array('message' => __('The uninstall cleanup policy could not be saved.', 'ultracache')),
                500
            );
        }

        wp_send_json_success(array('cleanupPolicy' => $stored_policy));
    }

    public function suppress_conflicting_admin_assets($hook = '')
    {
        $is_ultracache_screen = ('toplevel_page_ultracache' === (string) $hook);

        if (!$is_ultracache_screen && function_exists('get_current_screen')) {
            $screen = get_current_screen();
            $is_ultracache_screen = $screen && 'toplevel_page_ultracache' === (string) $screen->id;
        }

        if (!$is_ultracache_screen) {
            return;
        }

        // Elementor Notes can be enqueued on unrelated admin pages and may
        // throw "React is not defined" when its dependency chain is not
        // present. UltraCache does not use it, so keep the dashboard clean.
        $blocked_handles = array(
            'elementor-notes',
            'elementor-notes-app',
            'elementor-notes-app-initiator',
            'elementor-pro-notes',
            'elementor-pro-notes-app',
            'elementor-pro-notes-app-initiator',
        );

        foreach ($blocked_handles as $handle) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
        }

        global $wp_scripts, $wp_styles;

        if ($wp_scripts instanceof WP_Scripts) {
            foreach ((array) $wp_scripts->registered as $handle => $script) {
                $src = isset($script->src) ? (string) $script->src : '';
                $handle_lc = strtolower((string) $handle);
                $src_lc = strtolower($src);

                if (false !== strpos($handle_lc, 'elementor-notes') || false !== strpos($src_lc, 'notes.min.js') || false !== strpos($src_lc, 'notes-app-initiator.min.js')) {
                    wp_dequeue_script($handle);
                    wp_deregister_script($handle);
                }
            }
        }

        if ($wp_styles instanceof WP_Styles) {
            foreach ((array) $wp_styles->registered as $handle => $style) {
                $src = isset($style->src) ? strtolower((string) $style->src) : '';
                $handle_lc = strtolower((string) $handle);

                if (false !== strpos($handle_lc, 'elementor-notes') || false !== strpos($src, 'notes.min.css')) {
                    wp_dequeue_style($handle);
                    wp_deregister_style($handle);
                }
            }
        }
    }

    public function maybe_fix_revslider_footer_conflict()
    {
        if (!is_admin()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || 'toplevel_page_ultracache' !== (string) $screen->id) {
            return;
        }

        if (class_exists('RevSliderAdmin')) {
            remove_action('admin_footer', array('RevSliderAdmin', 'add_ajax_footer_functionality'));
        }
    }

    public function register_admin_bar_menu($admin_bar)
    {
        if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
            return;
        }

        $admin_bar->add_node(
            array(
                'id'    => 'ultracache',
                'title' => __('UltraCache', 'ultracache'),
                'href'  => admin_url('admin.php?page=ultracache'),
                'meta'  => array('title' => __('UltraCache', 'ultracache')),
            )
        );

        $admin_bar->add_node(
            array(
                'id'     => 'ultracache-purge-all',
                'parent' => 'ultracache',
                'title'  => __('Clear All Cache', 'ultracache'),
                'href'   => wp_nonce_url(add_query_arg('ultracache_action', 'purge_all'), 'ultracache_purge_nonce'),
            )
        );

        if (!is_admin()) {
            $admin_bar->add_node(
                array(
                    'id'     => 'ultracache-purge-page',
                    'parent' => 'ultracache',
                    'title'  => __('Clear This Page', 'ultracache'),
                    'href'   => wp_nonce_url(add_query_arg('ultracache_action', 'purge_page'), 'ultracache_purge_nonce'),
                )
            );
        }
    }

    public function handle_admin_bar_actions()
    {
        if (empty($_GET['ultracache_action']) || !current_user_can('manage_options')) {
            return;
        }

        if (empty($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'ultracache_purge_nonce')) {
            return;
        }

        $engine = self::get_engine_instance();
        if (!$engine) {
            return;
        }

        $action = sanitize_key(wp_unslash($_GET['ultracache_action']));
        if ('purge_all' === $action && method_exists($engine, 'purge_all')) {
            $engine->purge_all();
            set_transient('ultracache_admin_notice', __('UltraCache: all cache cleared.', 'ultracache'), 30);
        } elseif ('purge_page' === $action) {
            $url = self::get_current_url_without_plugin_args();
            if ($url) {
                if (method_exists($engine, 'purge_url')) {
                    $engine->purge_url($url);
                } elseif (method_exists($engine, 'purge_page_by_url')) {
                    $engine->purge_page_by_url($url);
                }

                set_transient('ultracache_admin_notice', __('UltraCache: current page cache cleared.', 'ultracache'), 30);
            }
        }

        wp_safe_redirect(remove_query_arg(array('ultracache_action', '_wpnonce')));
        exit;
    }

    public function render_admin_notice()
    {
        $notice = get_transient('ultracache_admin_notice');
        if (!$notice) {
            return;
        }

        delete_transient('ultracache_admin_notice');

        $type = 'success';
        $message = $notice;
        if (is_array($notice)) {
            $message = isset($notice['message']) ? (string) $notice['message'] : '';
            $type = isset($notice['type']) ? sanitize_key((string) $notice['type']) : 'success';
        }

        if ('' === trim((string) $message)) {
            return;
        }

        if (!in_array($type, array('success', 'warning', 'error', 'info'), true)) {
            $type = 'success';
        }

        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }


    private static function get_current_url_without_plugin_args()
    {
        if (empty($_SERVER['HTTP_HOST']) || empty($_SERVER['REQUEST_URI'])) {
            return '';
        }

        $is_ssl = ultracache_server_flag_enabled('HTTPS')
            || ('443' === ultracache_server_value('SERVER_PORT'));
        $scheme = $is_ssl ? 'https://' : 'http://';
        $host = ultracache_get_validated_http_host(ultracache_server_value('HTTP_HOST'), 'plugin_current_url');
        if ('' === $host) {
            return '';
        }

        $url = $scheme . $host . ultracache_server_value('REQUEST_URI');

        return esc_url_raw(remove_query_arg(array('ultracache_action', '_wpnonce'), $url));
    }

}
