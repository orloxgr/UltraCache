<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!trait_exists('Ultra_Cache_WP_Admin_Trait')) {
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

        public function render_dashboard()
        {
            $version_label = self::maybe_translate_sprintf(
                'UltraCache %s',
                (string) UCWP_VERSION
            );

            echo '<div id="uc-dashboard"></div>';
            echo '<div class="ucwp-version-badge" aria-label="' . esc_attr($version_label) . '">' . esc_html($version_label) . '</div>';
            echo '<div id="ucwp-root" style="display:none"></div>';
            echo '<div id="ucwp-admin-root" style="display:none"></div>';
            echo '<div id="ultracache-root" style="display:none"></div>';
        }

        public function enqueue_admin_assets($hook)
        {
            if ('toplevel_page_ultracache' !== $hook) {
                return;
            }

            wp_enqueue_style('ucwp-admin-css', UCWP_URL . 'includes/admin-dashboard.css', array(), UCWP_VERSION);
            wp_enqueue_script('ucwp-admin-js', UCWP_URL . 'includes/admin-dashboard.js', array('wp-element', 'wp-i18n'), UCWP_VERSION, true);
            wp_set_script_translations('ucwp-admin-js', 'ultracache', UCWP_PATH . 'languages');
            wp_script_add_data('ucwp-admin-js', 'type', 'module');

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

            $ucwp_runtime_config = array(
                'restBase'     => esc_url_raw(rest_url('ultracache/v1/')),
                'restNonce'    => wp_create_nonce('wp_rest'),
                'runtimeJsScanNonce' => wp_create_nonce('ucwp_runtime_js_scan'),
                'frontendProbeUrl' => esc_url_raw(home_url('/')),
                'version'      => UCWP_VERSION,
                'stats'        => $dashboard_stats,
                'settings'     => $settings_for_client,
                'defaults'     => self::get_dashboard_defaults_for_client(),
                'jsDelayDeferRecommendedExclusions' => implode("\n", self::get_default_js_delay_defer_exclusion_patterns()),
                'avifSupport'  => self::get_media_support_status(),
                'diagnostics'  => $dashboard_diagnostics,
                'crawlScopeSummary' => self::get_crawl_scope_summary(),
                'warmupGeneration' => method_exists(__CLASS__, 'get_warmup_generation') ? self::get_warmup_generation() : 0,
            );
            $ucwp_runtime_config_json = wp_json_encode($ucwp_runtime_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            if (false === $ucwp_runtime_config_json) {
                $ucwp_runtime_config_json = '{}';
            }
            wp_add_inline_script('ucwp-admin-js', 'window.ucwpData = ' . $ucwp_runtime_config_json . ';', 'before');
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
                    'href'   => wp_nonce_url(add_query_arg('ucwp_action', 'purge_all'), 'ucwp_purge_nonce'),
                )
            );

            if (!is_admin()) {
                $admin_bar->add_node(
                    array(
                        'id'     => 'ultracache-purge-page',
                        'parent' => 'ultracache',
                        'title'  => __('Clear This Page', 'ultracache'),
                        'href'   => wp_nonce_url(add_query_arg('ucwp_action', 'purge_page'), 'ucwp_purge_nonce'),
                    )
                );
            }
        }

        public function handle_admin_bar_actions()
        {
            if (empty($_GET['ucwp_action']) || !current_user_can('manage_options')) {
                return;
            }

            if (empty($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'ucwp_purge_nonce')) {
                return;
            }

            $engine = self::get_engine_instance();
            if (!$engine) {
                return;
            }

            $action = sanitize_key(wp_unslash($_GET['ucwp_action']));
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

            wp_safe_redirect(remove_query_arg(array('ucwp_action', '_wpnonce')));
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

    }
}
