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
            wp_enqueue_script('ucwp-admin-js', UCWP_URL . 'includes/admin-dashboard.js', array('wp-element'), UCWP_VERSION, true);
            wp_script_add_data('ucwp-admin-js', 'type', 'module');

            $settings_for_client = self::get_dashboard_settings_for_client();
            $cache_stats_enabled = !empty($settings_for_client['cacheStatsEnabled']);

            if (!$cache_stats_enabled && method_exists(__CLASS__, 'get_cache_stats_disabled_payload')) {
                $dashboard_stats = self::get_cache_stats_disabled_payload('admin_bootstrap_disabled');
                $dashboard_diagnostics = isset($dashboard_stats['diagnostics']) && is_array($dashboard_stats['diagnostics']) ? $dashboard_stats['diagnostics'] : array();
            } else {
                $dashboard_stats = method_exists(__CLASS__, 'get_dashboard_stats_snapshot') ? self::get_dashboard_stats_snapshot(20, false) : array('success' => true);
                $dashboard_diagnostics = isset($dashboard_stats['diagnostics']) && is_array($dashboard_stats['diagnostics']) ? $dashboard_stats['diagnostics'] : array();
            }

            wp_localize_script(
                'ucwp-admin-js',
                'ucwpData',
                array(
                    'restBase'     => esc_url_raw(rest_url('ultracache/v1/')),
                    'restNonce'    => wp_create_nonce('wp_rest'),
                    'runtimeJsScanNonce' => wp_create_nonce('ucwp_runtime_js_scan'),
                    'frontendProbeUrl' => esc_url_raw(home_url('/')),
                    'version'      => UCWP_VERSION,
                    'stats'        => $dashboard_stats,
                    'settings'     => $settings_for_client,
                    'defaults'     => self::get_dashboard_defaults_for_client(),
                    'jsDelayDeferRecommendedExclusions' => implode("\n", self::get_default_js_delay_defer_exclusion_patterns()),
                    'jsDelayDeferSliderExclusions'      => implode("\n", self::get_default_slider_js_delay_defer_exclusion_patterns()),
                    'avifSupport'  => self::get_media_support_status(),
                    'diagnostics'  => $dashboard_diagnostics,
                    'crawlScopeSummary' => self::get_crawl_scope_summary(),
                )
            );
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
                set_transient('ucwp_admin_notice', __('UltraCache: all cache cleared.', 'ultracache'), 30);
            } elseif ('purge_page' === $action) {
                $url = self::get_current_url_without_plugin_args();
                if ($url) {
                    if (method_exists($engine, 'purge_url')) {
                        $engine->purge_url($url);
                    } elseif (method_exists($engine, 'purge_page_by_url')) {
                        $engine->purge_page_by_url($url);
                    }

                    set_transient('ucwp_admin_notice', __('UltraCache: current page cache cleared.', 'ultracache'), 30);
                }
            }

            wp_safe_redirect(remove_query_arg(array('ucwp_action', '_wpnonce')));
            exit;
        }

        public function render_admin_notice()
        {
            $notice = get_transient('ucwp_admin_notice');
            if (!$notice) {
                return;
            }

            delete_transient('ucwp_admin_notice');

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
