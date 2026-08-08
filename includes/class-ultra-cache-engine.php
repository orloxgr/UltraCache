<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/core/html-variant-functions.php';
require_once __DIR__ . '/core/srcset-functions.php';
require_once __DIR__ . '/core/html-tag-scanner-functions.php';
require_once __DIR__ . '/engine/class-engine-response-headers-trait.php';
require_once __DIR__ . '/esi/class-esi-rendering-trait.php';
require_once __DIR__ . '/engine/class-engine-litespeed-response-trait.php';
require_once __DIR__ . '/engine/class-engine-conditional-response-trait.php';
require_once __DIR__ . '/engine/class-engine-storage-trait.php';
require_once __DIR__ . '/engine/class-engine-css-bundle-trait.php';
require_once __DIR__ . '/engine/class-engine-font-optimization-trait.php';
require_once __DIR__ . '/engine/class-engine-lcp-slider-trait.php';
require_once __DIR__ . '/engine/class-engine-js-optimization-trait.php';
require_once __DIR__ . '/engine/class-engine-media-image-trait.php';
require_once __DIR__ . '/engine/class-engine-iframe-lazy-load-trait.php';
require_once __DIR__ . '/engine/class-engine-html-output-trait.php';
require_once __DIR__ . '/engine/class-engine-cache-decision-trait.php';
require_once __DIR__ . '/engine/class-engine-warm-crawl-trait.php';
require_once __DIR__ . '/engine/class-engine-profiling-metrics-trait.php';
require_once __DIR__ . '/engine/class-engine-dropin-lifecycle-trait.php';
require_once __DIR__ . '/engine/class-engine-hot-page-analytics-trait.php';
require_once __DIR__ . '/engine/class-engine-analytics-trait.php';
require_once __DIR__ . '/engine/class-engine-async-css-trait.php';
require_once __DIR__ . '/engine/class-engine-frontend-assets-trait.php';
require_once __DIR__ . '/integrations/woocommerce/class-woocommerce-esi-trait.php';
require_once __DIR__ . '/integrations/elementor/class-elementor-page-css-dependency-trait.php';
require_once __DIR__ . '/maintenance/class-update-cache-purge-trait.php';

class Ultra_Cache_Engine
{
    use Ultra_Cache_Engine_Response_Headers_Trait;
    use Ultra_Cache_Engine_ESI_Rendering_Trait;
    use Ultra_Cache_Engine_LiteSpeed_Response_Trait;
    use Ultra_Cache_Engine_Conditional_Response_Trait;
    use Ultra_Cache_Engine_Storage_Trait;
    use Ultra_Cache_Engine_CSS_Bundle_Trait;
    use Ultra_Cache_Engine_Font_Optimization_Trait;
    use Ultra_Cache_Engine_LCP_Slider_Trait;
    use Ultra_Cache_Engine_JS_Optimization_Trait;
    use Ultra_Cache_Engine_Media_Image_Trait;
    use Ultra_Cache_Engine_Iframe_Lazy_Load_Trait;
    use Ultra_Cache_Engine_HTML_Output_Trait;
    use Ultra_Cache_Engine_Cache_Decision_Trait;
    use Ultra_Cache_Engine_Warm_Crawl_Trait;
    use Ultra_Cache_Engine_Profiling_Metrics_Trait;
    use Ultra_Cache_Engine_Dropin_Lifecycle_Trait;
    use Ultra_Cache_Engine_Hot_Page_Analytics_Trait;
    use Ultra_Cache_Engine_Analytics_Trait;
    use Ultra_Cache_Engine_Async_CSS_Trait;
    use Ultra_Cache_Engine_Frontend_Assets_Trait;
    use Ultra_Cache_Engine_WooCommerce_ESI_Trait;
    use Ultra_Cache_Engine_Elementor_Page_CSS_Dependency_Trait;
    use Ultra_Cache_Engine_Update_Cache_Purge_Trait;

    /** @var Ultra_Cache_Engine|null */
    private static $instance = null;

    /** @var bool */
    private $buffering = false;

    /** @var bool */
    private $template_enhancement_buffer_required = false;

    /** @var bool */
    private $template_enhancement_buffer_started = false;

    /** @var array<string,mixed> */
    private $template_enhancement_buffer_decision = array();

    /** @var bool */
    private $template_enhancement_callbacks_registered = false;

    /** @var bool */
    private $template_enhancement_esi_callback_registered = false;

    /** @var bool */
    private $template_enhancement_google_fonts_callback_registered = false;

    /** @var bool */
    private $template_enhancement_esi_late_registration_compatibility = false;

    /** @var bool */
    private $diagnostic_fallback_output_buffer_active = false;

    /** @var int */
    private $diagnostic_fallback_output_buffer_level = 0;

    /** @var bool */
    private $diagnostic_fallback_output_buffer_used = false;

    /** @var bool */
    private $translatepress_final_output_buffer_active = false;

    /** @var int */
    private $translatepress_final_output_buffer_level = 0;

    /** @var bool */
    private $translatepress_final_output_buffer_used = false;

    /** @var bool */
    private $cache_output_callback_ran = false;

    /** @var string */
    private $last_bypass_reason = '';

    /** @var array<string, resource> */
    private $runtime_locks = array();

    /** @var bool */
    private $google_fonts_async_pending = false;

    /** @var bool */
    private $google_fonts_sync_build_mode = false;

    /** @var array<string, string> */
    private $google_fonts_last_build_failure = array();

    /** @var array<string, string>|null */
    private $runtime_font_css_url_map = null;

    /** @var array<string, string> */
    private $runtime_font_css_url_map_current_request = array();

    /** @var array<string, array<string, mixed>> */
    private $delayed_font_css_assets_current_request = array();

    /** @var array<string, array<string, mixed>> */
    private $cls_dimension_resolution_cache_current_request = array();


    /** @var string */
    private $page_cache_generation_lock_name = '';

    /** @var string */
    private $page_cache_generation_global_lock_name = '';

    /** @var string */
    private $page_cache_generation_lock_denied_reason = '';

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->profile_request_checkpoint('engine_construct');
        $this->register_hooks();
    }

    /**
     * Determine whether the current request is the WordPress login screen.
     *
     * wp-login.php is not an admin request, so is_admin() alone cannot keep
     * frontend script, style, and output transforms away from its dependency
     * graph. Prefer WordPress' request predicate and retain bounded fallbacks
     * for bootstrap contexts where that predicate is unavailable.
     *
     * @return bool
     */
    private function is_wordpress_login_request()
    {
        if (function_exists('is_login') && is_login()) {
            return true;
        }

        $pagenow = isset($GLOBALS['pagenow']) && is_scalar($GLOBALS['pagenow'])
            ? strtolower((string) $GLOBALS['pagenow'])
            : '';
        if ('wp-login.php' === $pagenow) {
            return true;
        }

        foreach (array('SCRIPT_NAME', 'PHP_SELF') as $server_key) {
            $script_path = function_exists('ultracache_server_value')
                ? ultracache_server_value($server_key)
                : '';
            if ('' === $script_path) {
                continue;
            }

            $parsed_path = function_exists('wp_parse_url')
                ? wp_parse_url($script_path, PHP_URL_PATH)
                : $script_path;
            if ('wp-login.php' === strtolower(basename((string) $parsed_path))) {
                return true;
            }
        }

        return false;
    }

    private function register_hooks()
    {
        $is_admin_request = function_exists('is_admin') && is_admin();
        $is_ajax_request = function_exists('wp_doing_ajax') && wp_doing_ajax();
        $is_cron_request = function_exists('wp_doing_cron') && wp_doing_cron();
        $is_rest_request = defined('REST_REQUEST') && REST_REQUEST;
        $is_login_request = $this->is_wordpress_login_request();
        $is_frontend_request = !$is_admin_request && !$is_ajax_request && !$is_cron_request && !$is_rest_request && !$is_login_request;

        // Cache invalidation hooks are required in both frontend and admin
        // contexts. They do not mutate rendered admin assets or output.
        add_action('save_post', array($this, 'handle_post_update'), 20);
        add_action('before_delete_post', array($this, 'ultracache_delete_lcp_observations_for_post'), 10);
        add_action('before_delete_post', array($this, 'handle_post_deletion'), 20);
        add_action('wp_trash_post', array($this, 'ultracache_delete_lcp_observations_for_post'), 10);
        add_action('trashed_post', array($this, 'handle_post_deletion'), 20);
        add_action('untrashed_post', array($this, 'handle_post_update'), 20);
        add_action('woocommerce_update_product', array($this, 'handle_woocommerce_object_update'), 20);
        add_action('woocommerce_new_product', array($this, 'handle_woocommerce_object_update'), 20);
        add_action('woocommerce_update_product_variation', array($this, 'handle_woocommerce_object_update'), 20);
        add_action('woocommerce_variation_set_stock', array($this, 'handle_woocommerce_object_update'), 20);
        add_action('woocommerce_product_set_stock_status', array($this, 'handle_woocommerce_object_update'), 20);
        add_action('edited_term', array($this, 'handle_term_update'), 20, 3);
        add_action('created_term', array($this, 'handle_term_update'), 20, 3);
        add_action('set_object_terms', array($this, 'handle_object_terms_set'), 20, 6);
        add_action('wp_update_nav_menu', array($this, 'handle_navigation_update'), 20, 2);
        add_action('customize_save_after', array($this, 'handle_global_frontend_change'), 20);
        add_action('update_option_sidebars_widgets', array($this, 'handle_sidebars_widgets_update'), 20, 2);
        add_action('switch_theme', array($this, 'handle_global_frontend_change'), 20);
        add_action('update_option_show_on_front', array($this, 'handle_front_page_option_change'), 20, 2);
        add_action('update_option_page_on_front', array($this, 'handle_front_page_option_change'), 20, 2);
        add_action('update_option_page_for_posts', array($this, 'handle_front_page_option_change'), 20, 2);
        add_action('update_option_posts_per_page', array($this, 'handle_front_page_option_change'), 20, 2);
        add_action('update_option_permalink_structure', array($this, 'handle_global_frontend_change'), 20, 2);
        add_action('wp_ajax_ultracache_lcp_observation', array($this, 'handle_lcp_observation_ajax'));
        add_action('wp_ajax_nopriv_ultracache_lcp_observation', array($this, 'handle_lcp_observation_ajax'));
        add_action('pmxi_before_xml_import', array($this, 'handle_wp_all_import_start'), 10, 1);
        add_action('pmxi_saved_post', array($this, 'handle_wp_all_import_record_saved'), 20, 3);
        add_action('pmxi_after_xml_import', array($this, 'handle_wp_all_import_complete'), 10, 2);
        add_action('ultracache_flush_affected_url_batch', array($this, 'process_persisted_affected_url_batch'));
        add_action('shutdown', array($this, 'flush_request_affected_url_batch'), PHP_INT_MAX - 20);
        $this->register_woocommerce_esi_hooks();

        if (!$is_frontend_request) {
            return;
        }

        add_filter('woocommerce_set_cookie_enabled', array($this, 'filter_internal_warm_woocommerce_cookie'), PHP_INT_MAX, 6);

        // Frontend rendering/optimization hooks must never participate in
        // wp-admin, wp-login.php, REST, AJAX, or cron responses. This prevents the frontend
        // engine from affecting third-party admin script dependency graphs or
        // partially generated admin output.
        add_action('init', array($this, 'maybe_start_translatepress_final_output_buffer'), -1000);
        add_action('init', array($this, 'maybe_apply_runtime_js_scan_anonymous_context'), -999);
        add_action('init', array($this, 'profile_init_checkpoint'), 0);
        add_action('wp_loaded', array($this, 'profile_wp_loaded_checkpoint'), 0);
        add_action('template_redirect', array($this, 'profile_template_redirect_checkpoint'), -1000);
        add_filter('redirect_canonical', array($this, 'filter_authenticated_internal_canonical_redirect'), 10, 2);
        add_action('template_redirect', array($this, 'maybe_start_buffering'), 0);
        add_action('wp_before_include_template', array($this, 'register_template_enhancement_output_callbacks'), 900);
        add_filter('wp_should_output_buffer_template_for_enhancement', array($this, 'should_force_template_enhancement_output_buffer'), PHP_INT_MAX);
        add_action('wp_template_enhancement_output_buffer_started', array($this, 'template_enhancement_output_buffer_started_checkpoint'), 0);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_delayed_script_loader'), -998);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_runtime_font_helpers'), -997);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_lcp_observer_runtime_helper'), -996);
        add_action('wp_enqueue_scripts', array($this, 'profile_wp_enqueue_scripts_start_checkpoint'), -1000);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_runtime_js_scan_collector'), -999);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_woocommerce_cart_fragments_delay_helper'), -995);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_mailerlite_lazy_nonce_helper'), 0);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_async_css_runtime_helper'), -994);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_lazy_third_party_iframe_runtime_helper'), -993);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_page_css_bundle_stylesheet'), 9999);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_delayed_icon_font_stylesheets'), 10000);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_local_font_display_patch_stylesheet'), 10001);
        add_filter('wp_speculation_rules_configuration', array($this, 'filter_speculation_rules_configuration'), 20);
        add_filter('wp_speculation_rules_href_exclude_paths', array($this, 'filter_speculation_rules_href_exclude_paths'), 20, 2);
        add_action('wp_enqueue_scripts', array($this, 'profile_wp_enqueue_scripts_end_checkpoint'), PHP_INT_MAX);
        add_action('wp_enqueue_scripts', array($this, 'cleanup_asset_chain_enqueue_assets'), 9999);
        add_action('shutdown', array($this, 'flush_translatepress_final_output_buffer_on_shutdown'), PHP_INT_MAX - 5);
        add_action('shutdown', array($this, 'flush_diagnostic_fallback_output_buffer_on_shutdown'), PHP_INT_MAX - 4);
        add_action('shutdown', array($this, 'finalize_missing_output_buffer_store_profile'), PHP_INT_MAX - 3);
        add_action('shutdown', array($this, 'run_deferred_store_post_response_actions'), PHP_INT_MAX - 2);
        add_action('shutdown', array($this, 'release_page_generation_lock_on_shutdown'), PHP_INT_MAX - 1);
        add_action('shutdown', array($this, 'update_store_profile_after_shutdown'), PHP_INT_MAX);
        add_filter('script_loader_tag', array($this, 'defer_scripts'), 10, 3);
        add_filter('script_loader_tag', array($this, 'annotate_runtime_js_inventory_script_tag'), PHP_INT_MAX, 3);
        add_filter('style_loader_src', array($this, 'add_display_swap_to_google_fonts'), 20, 2);
        add_filter('style_loader_tag', array($this, 'add_local_font_display_patch_style_attributes'), 20, 4);
        add_filter('style_loader_tag', array($this, 'add_page_css_bundle_style_attributes'), 20, 4);
        add_filter('style_loader_tag', array($this, 'add_delayed_icon_font_style_attributes'), 20, 4);
        add_filter('wp_resource_hints', array($this, 'filter_google_fonts_resource_hints'), 20, 2);
        add_filter('woocommerce_get_script_data', array($this, 'filter_woocommerce_cart_fragments_script_data'), 20, 2);
    }

    private function is_translatepress_active_for_output_buffer()
    {
        if (defined('TRP_PLUGIN_DIR') || defined('TRP_PLUGIN_BASE') || class_exists('TRP_Translate_Press') || class_exists('TRP_Translation_Render')) {
            return true;
        }

        $active_plugins = (array) get_option('active_plugins', array());
        foreach ($active_plugins as $active_plugin) {
            $active_plugin = is_scalar($active_plugin) ? strtolower((string) $active_plugin) : '';
            if (0 === strpos($active_plugin, 'translatepress-multilingual/')) {
                return true;
            }
        }

        if (is_multisite()) {
            $sitewide_plugins = (array) get_site_option('active_sitewide_plugins', array());
            foreach (array_keys($sitewide_plugins) as $active_plugin) {
                $active_plugin = is_scalar($active_plugin) ? strtolower((string) $active_plugin) : '';
                if (0 === strpos($active_plugin, 'translatepress-multilingual/')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function get_translatepress_buffer_request_method()
    {
        if (empty($_SERVER['REQUEST_METHOD']) || !is_scalar($_SERVER['REQUEST_METHOD'])) {
            return '';
        }

        return strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])));
    }

    private function get_translatepress_buffer_request_uri_parts()
    {
        if (empty($_SERVER['REQUEST_URI']) || !is_scalar($_SERVER['REQUEST_URI'])) {
            return array('', array());
        }

        $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
        $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
        $query_string = (string) wp_parse_url($request_uri, PHP_URL_QUERY);
        $query_args = array();

        if ('' !== $query_string) {
            wp_parse_str($query_string, $query_args);
        }

        return array(strtolower(trim($path, '/')), is_array($query_args) ? $query_args : array());
    }

    private function should_skip_translatepress_buffer_for_non_html_request()
    {
        $method = $this->get_translatepress_buffer_request_method();
        if (!in_array($method, array('GET', 'HEAD'), true)) {
            return true;
        }

        if (function_exists('wp_is_json_request') && wp_is_json_request()) {
            return true;
        }

        if (function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request()) {
            return true;
        }

        list($path, $query_args) = $this->get_translatepress_buffer_request_uri_parts();

        if ('' !== $path) {
            if ('robots.txt' === $path || 'favicon.ico' === $path) {
                return true;
            }

            if (0 === strpos($path, 'wp-json') || false !== strpos($path, '/wp-json/')) {
                return true;
            }

            if (0 === strpos($path, 'wp-sitemap') || false !== strpos($path, '/wp-sitemap') || 1 === preg_match('#(?:^|/)(?:wp-sitemap[^/]*\.xml|sitemap[^/]*\.xml|feed|rss|rss2|rdf|atom)(?:/|$)#', $path)) {
                return true;
            }
        }

        if (isset($query_args['rest_route']) || isset($query_args['feed']) || isset($query_args['wc-ajax'])) {
            return true;
        }

        $accept_header = '';
        if (!empty($_SERVER['HTTP_ACCEPT']) && is_scalar($_SERVER['HTTP_ACCEPT'])) {
            $accept_header = strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT'])));
        }

        if ('' !== $accept_header && false === strpos($accept_header, 'text/html') && 1 === preg_match('/(?:application\/json|application\/xml|text\/xml)/', $accept_header)) {
            return true;
        }

        return false;
    }

    private function should_start_translatepress_final_output_buffer()
    {
        if ($this->translatepress_final_output_buffer_active || $this->translatepress_final_output_buffer_level > 0) {
            return false;
        }

        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)) {
            return false;
        }

        if ($this->should_skip_translatepress_buffer_for_non_html_request()) {
            return false;
        }

        if (!defined('WP_CACHE') || !WP_CACHE) {
            return false;
        }

        $settings = $this->get_settings();
        if (empty($settings['enabled'])) {
            return false;
        }

        return $this->is_translatepress_active_for_output_buffer();
    }

    public function maybe_start_translatepress_final_output_buffer()
    {
        if (!$this->should_start_translatepress_final_output_buffer()) {
            return;
        }

        $this->translatepress_final_output_buffer_active = true;
        $this->translatepress_final_output_buffer_used = false;
        ob_start(array($this, 'translatepress_final_output_buffer_callback'));
        $this->translatepress_final_output_buffer_level = (int) ob_get_level();
        $this->profile_request_checkpoint('translatepress_final_output_buffer_started', array(
            'level' => (string) $this->translatepress_final_output_buffer_level,
        ));
    }

    public function translatepress_final_output_buffer_callback($html)
    {
        $this->translatepress_final_output_buffer_active = false;
        $this->translatepress_final_output_buffer_level = 0;
        $this->translatepress_final_output_buffer_used = true;

        if (!$this->buffering || $this->cache_output_callback_ran) {
            return $html;
        }

        $this->profile_request_checkpoint('translatepress_final_output_buffer_store_start', array(
            'html_bytes' => is_string($html) ? strlen($html) : 0,
        ));

        return $this->cache_output_callback($html);
    }

    public function flush_translatepress_final_output_buffer_on_shutdown()
    {
        if (!$this->translatepress_final_output_buffer_active || $this->translatepress_final_output_buffer_level <= 0) {
            return;
        }

        $current_level = (int) ob_get_level();
        if ($current_level < $this->translatepress_final_output_buffer_level) {
            $this->translatepress_final_output_buffer_active = false;
            $this->profile_request_checkpoint('translatepress_final_output_buffer_missing_on_shutdown', array(
                'current_level' => (string) $current_level,
                'expected_level' => (string) $this->translatepress_final_output_buffer_level,
            ));
            return;
        }

        $this->profile_request_checkpoint('translatepress_final_output_buffer_flush_start', array(
            'current_level' => (string) $current_level,
            'target_level' => (string) $this->translatepress_final_output_buffer_level,
        ));

        while ((int) ob_get_level() >= $this->translatepress_final_output_buffer_level) {
            $level_before = (int) ob_get_level();
            $status = ob_get_status(false);
            $removable = true;
            if (is_array($status) && isset($status['flags']) && defined('PHP_OUTPUT_HANDLER_REMOVABLE')) {
                $removable = (bool) ((int) $status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE);
            }

            if (!$removable) {
                $this->profile_request_checkpoint('translatepress_final_output_buffer_flush_step', array(
                    'level_before' => (string) $level_before,
                    'flushed' => 'no',
                    'reason' => 'buffer-not-removable',
                ));
                break;
            }

            $flushed = ob_end_flush();
            $this->profile_request_checkpoint('translatepress_final_output_buffer_flush_step', array(
                'level_before' => (string) $level_before,
                'flushed' => $flushed ? 'yes' : 'no',
                'buffering' => $this->buffering ? 'yes' : 'no',
            ));

            if (!$flushed || !$this->translatepress_final_output_buffer_active) {
                break;
            }
        }
    }

    public function maybe_start_buffering()
    {
        $this->profile_request_checkpoint('maybe_start_buffering_start');
        $this->profile_request_checkpoint('maybe_start_buffering_before_reentry_check');
        if ($this->buffering) {
            $this->profile_request_checkpoint('maybe_start_buffering_reentry_return');
            return;
        }
        $this->profile_request_checkpoint('maybe_start_buffering_after_reentry_check');

        $this->profile_request_checkpoint('maybe_start_buffering_before_should_bypass');
        $should_bypass = $this->should_bypass_cache();
        $this->profile_request_checkpoint('maybe_start_buffering_after_should_bypass', array(
            'result' => $should_bypass ? 'true' : 'false',
            'reason' => (string) $this->last_bypass_reason,
        ));
        if ($should_bypass) {
            $this->profile_request_checkpoint('bypass_selected', array('reason' => (string) $this->last_bypass_reason));
            $this->record_analytics_bypass($this->last_bypass_reason);
            $this->send_shared_html_cache_headers(false);
            $this->send_debug_headers('BYPASS', $this->last_bypass_reason);
            return;
        }

        $this->profile_request_checkpoint('early_hit_check_start');
        if ($this->maybe_serve_cached_file_during_wp_boot('early-hit')) {
            $this->profile_request_checkpoint('early_hit_served');
            exit;
        }
        $this->profile_request_checkpoint('early_hit_check_end');

        $this->profile_request_checkpoint('page_generation_lock_before');
        $this->maybe_acquire_page_generation_lock();
        $this->profile_request_checkpoint('page_generation_lock_checked', array(
            'has_lock' => '' !== (string) $this->page_cache_generation_lock_name ? 'true' : 'false',
            'global_lock' => '' !== (string) $this->page_cache_generation_global_lock_name ? 'true' : 'false',
            'denied_reason' => (string) $this->page_cache_generation_lock_denied_reason,
        ));
        if ('' !== (string) $this->page_cache_generation_lock_denied_reason) {
            $skip_reason = (string) $this->page_cache_generation_lock_denied_reason;
            $this->record_analytics_store_skip($skip_reason);
            $this->send_shared_html_cache_headers(false);
            $this->send_debug_headers('SKIP', $skip_reason);
            return;
        }

        $this->profile_request_checkpoint('record_analytics_miss_start');
        $this->record_analytics_miss();
        $this->profile_request_checkpoint('record_analytics_miss_end');
        $this->profile_request_checkpoint('send_debug_headers_start');
        $this->send_debug_headers('MISS');
        $this->profile_request_checkpoint('send_debug_headers_end');
        $this->buffering = true;
        $this->template_enhancement_buffer_required = true;
        $this->profile_request_checkpoint('buffer_start');
        if ($this->translatepress_final_output_buffer_active) {
            $this->profile_request_checkpoint('translatepress_final_output_buffer_store_deferred');
            return;
        }
        add_filter('wp_template_enhancement_output_buffer', array($this, 'cache_output_callback'), 100);
        $this->maybe_start_diagnostic_fallback_output_buffer();
    }

    private function should_start_diagnostic_fallback_output_buffer()
    {
        if (!$this->is_store_profiler_enabled()) {
            return false;
        }

        if ($this->diagnostic_fallback_output_buffer_active || $this->diagnostic_fallback_output_buffer_level > 0) {
            return false;
        }

        if (!$this->buffering || !$this->template_enhancement_buffer_required) {
            return false;
        }

        if (is_admin() || wp_doing_ajax()) {
            return false;
        }

        return true;
    }

    private function maybe_start_diagnostic_fallback_output_buffer()
    {
        if (!$this->should_start_diagnostic_fallback_output_buffer()) {
            return;
        }

        $this->diagnostic_fallback_output_buffer_active = true;
        $this->diagnostic_fallback_output_buffer_used = false;
        ob_start(array($this, 'diagnostic_fallback_output_buffer_callback'));
        $this->diagnostic_fallback_output_buffer_level = (int) ob_get_level();
        $this->profile_request_checkpoint('diagnostic_fallback_output_buffer_started', array(
            'level' => (string) $this->diagnostic_fallback_output_buffer_level,
        ));
    }

    public function diagnostic_fallback_output_buffer_callback($html)
    {
        $this->diagnostic_fallback_output_buffer_active = false;
        $this->diagnostic_fallback_output_buffer_used = true;
        $this->profile_request_checkpoint('diagnostic_fallback_output_buffer_callback', array(
            'html_bytes' => is_string($html) ? strlen($html) : 0,
            'buffering' => $this->buffering ? 'yes' : 'no',
            'template_buffer_required' => $this->template_enhancement_buffer_required ? 'yes' : 'no',
            'cache_output_callback_ran' => $this->cache_output_callback_ran ? 'yes' : 'no',
        ));

        if ($this->cache_output_callback_ran || !$this->buffering || !$this->template_enhancement_buffer_required) {
            return $html;
        }

        if (!is_string($html) || '' === $html) {
            return $html;
        }

        $this->profile_request_checkpoint('diagnostic_fallback_output_buffer_store_start');
        return $this->cache_output_callback($html);
    }

    public function flush_diagnostic_fallback_output_buffer_on_shutdown()
    {
        if (!$this->diagnostic_fallback_output_buffer_active || $this->diagnostic_fallback_output_buffer_level <= 0) {
            return;
        }

        if (!$this->is_store_profiler_enabled() || !$this->buffering || !$this->template_enhancement_buffer_required) {
            return;
        }

        $current_level = (int) ob_get_level();
        if ($current_level < $this->diagnostic_fallback_output_buffer_level) {
            $this->diagnostic_fallback_output_buffer_active = false;
            $this->profile_request_checkpoint('diagnostic_fallback_output_buffer_missing_on_shutdown', array(
                'current_level' => (string) $current_level,
                'expected_level' => (string) $this->diagnostic_fallback_output_buffer_level,
            ));
            return;
        }

        $this->profile_request_checkpoint('diagnostic_fallback_output_buffer_flush_start', array(
            'current_level' => (string) $current_level,
            'target_level' => (string) $this->diagnostic_fallback_output_buffer_level,
        ));

        while ((int) ob_get_level() >= $this->diagnostic_fallback_output_buffer_level) {
            $level_before = (int) ob_get_level();
            $status = ob_get_status(false);
            $removable = true;
            if (is_array($status) && isset($status['flags']) && defined('PHP_OUTPUT_HANDLER_REMOVABLE')) {
                $removable = (bool) ((int) $status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE);
            }

            if (!$removable) {
                $this->profile_request_checkpoint('diagnostic_fallback_output_buffer_flush_step', array(
                    'level_before' => (string) $level_before,
                    'flushed' => 'no',
                    'reason' => 'buffer-not-removable',
                ));
                break;
            }

            $flushed = ob_end_flush();
            $this->profile_request_checkpoint('diagnostic_fallback_output_buffer_flush_step', array(
                'level_before' => (string) $level_before,
                'flushed' => $flushed ? 'yes' : 'no',
                'buffering' => $this->buffering ? 'yes' : 'no',
            ));

            if (!$flushed || !$this->diagnostic_fallback_output_buffer_active || !$this->buffering) {
                break;
            }
        }
    }

    /**
     * Register full-template transformation callbacks only when the current
     * request can actually require them.
     *
     * @return void
     */
    public function register_template_enhancement_output_callbacks()
    {
        if ($this->template_enhancement_callbacks_registered) {
            return;
        }
        $this->template_enhancement_callbacks_registered = true;

        $google_fonts_required = $this->should_force_template_buffer_for_google_fonts_cleanup();
        if ($google_fonts_required) {
            add_filter('wp_template_enhancement_output_buffer', array($this, 'apply_live_google_fonts_output_cleanup'), 90);
            $this->template_enhancement_google_fonts_callback_registered = true;
        }

        $esi_enabled = function_exists('ultracache_esi_is_enabled') && ultracache_esi_is_enabled();
        $esi_diagnostics = array();
        $esi_has_fragments = false;
        if ($esi_enabled && class_exists('Ultra_Cache_ESI_Registry')) {
            $registry = Ultra_Cache_ESI_Registry::instance();
            if (method_exists($registry, 'get_template_buffer_diagnostics')) {
                $candidate_diagnostics = $registry->get_template_buffer_diagnostics();
                if (is_array($candidate_diagnostics)) {
                    $esi_diagnostics = $candidate_diagnostics;
                }
            }
            $esi_has_fragments = method_exists($registry, 'has_fragments') && $registry->has_fragments();
        }

        /**
         * Allow integrations which intentionally register ESI fragments while
         * rendering the template to reserve the legacy full-buffer path.
         *
         * @param bool  $force_buffering Whether to reserve the ESI buffer.
         * @param array $diagnostics     Current ESI registry diagnostics.
         */
        $this->template_enhancement_esi_late_registration_compatibility = $esi_enabled
            && (bool) apply_filters(
                'ultracache_esi_force_template_buffer_for_late_registration',
                false,
                $esi_diagnostics
            );

        $output_filter_registered = function_exists('has_filter')
            && false !== has_filter('wp_template_enhancement_output_buffer');
        $finalized_action_registered = function_exists('has_action')
            && false !== has_action('wp_finalized_template_enhancement_output_buffer');
        $other_buffer_required = $this->template_enhancement_buffer_required
            || (method_exists($this, 'is_runtime_js_scan_request') && $this->is_runtime_js_scan_request())
            || $google_fonts_required
            || $output_filter_registered
            || $finalized_action_registered;

        if (
            $esi_enabled
            && (
                $esi_has_fragments
                || $this->template_enhancement_esi_late_registration_compatibility
                || $other_buffer_required
            )
        ) {
            add_filter('wp_template_enhancement_output_buffer', array($this, 'apply_esi_template_output_buffer'), PHP_INT_MAX - 1);
            $this->template_enhancement_esi_callback_registered = true;
        }

        $this->profile_request_checkpoint('template_enhancement_callbacks_registered', array(
            'google_fonts_callback' => $this->template_enhancement_google_fonts_callback_registered ? 'yes' : 'no',
            'esi_enabled' => $esi_enabled ? 'yes' : 'no',
            'esi_has_fragments' => $esi_has_fragments ? 'yes' : 'no',
            'esi_callback' => $this->template_enhancement_esi_callback_registered ? 'yes' : 'no',
            'esi_late_registration_compatibility' => $this->template_enhancement_esi_late_registration_compatibility ? 'yes' : 'no',
            'other_buffer_required' => $other_buffer_required ? 'yes' : 'no',
        ));
    }

    public function should_force_template_enhancement_output_buffer($should_buffer)
    {
        $this->register_template_enhancement_output_callbacks();

        $esi_diagnostics = array(
            'has_fragments' => false,
            'fragment_count' => 0,
            'fragment_count_at_decision' => 0,
            'late_fragment_count' => 0,
            'decision_observed' => false,
            'registered_after_init_started_count' => 0,
        );
        if (class_exists('Ultra_Cache_ESI_Registry')) {
            $registry = Ultra_Cache_ESI_Registry::instance();
            if (method_exists($registry, 'note_template_buffer_decision')) {
                $candidate_diagnostics = $registry->note_template_buffer_decision();
                if (is_array($candidate_diagnostics)) {
                    $esi_diagnostics = array_merge($esi_diagnostics, $candidate_diagnostics);
                }
            }
        }

        if (method_exists($this, 'is_runtime_js_scan_request') && $this->is_runtime_js_scan_request()) {
            $this->record_template_enhancement_buffer_decision('runtime-js-scan', true, $esi_diagnostics);
            $this->profile_request_checkpoint('runtime_js_scan_template_buffer_forced');
            return true;
        }

        if ($this->template_enhancement_buffer_required) {
            $this->record_template_enhancement_buffer_decision('page-cache-store', true, $esi_diagnostics);
            $this->profile_request_checkpoint('template_enhancement_buffer_forced');
            return true;
        }

        if ($this->should_force_template_buffer_for_google_fonts_cleanup()) {
            $this->record_template_enhancement_buffer_decision('google-fonts-cleanup', true, $esi_diagnostics);
            $this->profile_request_checkpoint('template_enhancement_buffer_for_google_fonts_cleanup_forced');
            return true;
        }

        if (
            function_exists('ultracache_esi_is_enabled')
            && ultracache_esi_is_enabled()
            && (
                !empty($esi_diagnostics['has_fragments'])
                || $this->template_enhancement_esi_late_registration_compatibility
            )
        ) {
            $reason = !empty($esi_diagnostics['has_fragments'])
                ? 'esi-fragments'
                : 'esi-late-registration-compatibility';
            $this->record_template_enhancement_buffer_decision($reason, true, $esi_diagnostics);
            $this->profile_request_checkpoint('template_enhancement_buffer_for_esi_forced', array(
                'esi_has_fragments' => !empty($esi_diagnostics['has_fragments']) ? 'yes' : 'no',
                'esi_fragment_count' => (int) ($esi_diagnostics['fragment_count'] ?? 0),
                'esi_late_registration_compatibility' => $this->template_enhancement_esi_late_registration_compatibility ? 'yes' : 'no',
            ));
            return true;
        }

        if (
            $should_buffer
            && function_exists('ultracache_esi_is_enabled')
            && ultracache_esi_is_enabled()
            && !$this->template_enhancement_esi_callback_registered
        ) {
            add_filter('wp_template_enhancement_output_buffer', array($this, 'apply_esi_template_output_buffer'), PHP_INT_MAX - 1);
            $this->template_enhancement_esi_callback_registered = true;
        }

        $this->record_template_enhancement_buffer_decision(
            $should_buffer ? 'upstream-filter' : 'not-required',
            (bool) $should_buffer,
            $esi_diagnostics
        );

        return (bool) $should_buffer;
    }

    /**
     * Record the effective template-enhancement buffer decision.
     *
     * @param string $reason          Effective forcing reason.
     * @param bool   $required        Whether buffering is required.
     * @param array  $esi_diagnostics ESI registry state at decision time.
     * @return void
     */
    private function record_template_enhancement_buffer_decision($reason, $required, array $esi_diagnostics)
    {
        $this->template_enhancement_buffer_decision = array(
            'reason' => sanitize_key((string) $reason),
            'required' => (bool) $required,
            'esi_has_fragments' => !empty($esi_diagnostics['has_fragments']),
            'esi_fragment_count' => (int) ($esi_diagnostics['fragment_count'] ?? 0),
            'esi_fragment_count_at_decision' => (int) ($esi_diagnostics['fragment_count_at_decision'] ?? 0),
            'esi_late_fragment_count' => (int) ($esi_diagnostics['late_fragment_count'] ?? 0),
            'esi_registered_after_init_started_count' => (int) ($esi_diagnostics['registered_after_init_started_count'] ?? 0),
            'esi_registration_hooks' => implode(',', array_slice((array) ($esi_diagnostics['registration_hooks'] ?? array()), 0, 16)),
            'esi_callback_registered' => $this->template_enhancement_esi_callback_registered,
            'google_fonts_callback_registered' => $this->template_enhancement_google_fonts_callback_registered,
            'esi_late_registration_compatibility' => $this->template_enhancement_esi_late_registration_compatibility,
        );

        $this->profile_request_checkpoint('template_enhancement_buffer_decision', array(
            'required' => $required ? 'yes' : 'no',
            'reason' => (string) $this->template_enhancement_buffer_decision['reason'],
            'esi_has_fragments' => $this->template_enhancement_buffer_decision['esi_has_fragments'] ? 'yes' : 'no',
            'esi_fragment_count' => (int) $this->template_enhancement_buffer_decision['esi_fragment_count'],
            'esi_fragment_count_at_decision' => (int) $this->template_enhancement_buffer_decision['esi_fragment_count_at_decision'],
            'esi_late_fragment_count' => (int) $this->template_enhancement_buffer_decision['esi_late_fragment_count'],
            'esi_registered_after_init_started_count' => (int) $this->template_enhancement_buffer_decision['esi_registered_after_init_started_count'],
            'esi_registration_hooks' => (string) $this->template_enhancement_buffer_decision['esi_registration_hooks'],
            'esi_callback_registered' => $this->template_enhancement_buffer_decision['esi_callback_registered'] ? 'yes' : 'no',
            'google_fonts_callback_registered' => $this->template_enhancement_buffer_decision['google_fonts_callback_registered'] ? 'yes' : 'no',
            'esi_late_registration_compatibility' => $this->template_enhancement_buffer_decision['esi_late_registration_compatibility'] ? 'yes' : 'no',
        ));
    }

    public function template_enhancement_output_buffer_started_checkpoint()
    {
        $this->template_enhancement_buffer_started = true;
        if (
            class_exists('Ultra_Cache_ESI_Registry')
            && method_exists(Ultra_Cache_ESI_Registry::instance(), 'note_template_buffer_started')
        ) {
            Ultra_Cache_ESI_Registry::instance()->note_template_buffer_started();
        }
        $decision = is_array($this->template_enhancement_buffer_decision)
            ? $this->template_enhancement_buffer_decision
            : array();
        $this->profile_request_checkpoint('template_enhancement_buffer_started', array(
            'reason' => (string) ($decision['reason'] ?? 'unknown'),
            'esi_has_fragments' => !empty($decision['esi_has_fragments']) ? 'yes' : 'no',
            'esi_fragment_count_at_decision' => (int) ($decision['esi_fragment_count_at_decision'] ?? 0),
        ));
    }

    private function maybe_acquire_page_generation_lock()
    {
        $this->page_cache_generation_lock_denied_reason = '';
        $this->profile_request_checkpoint('page_generation_lock_before_current_url');
        $url = $this->get_current_request_url();
        $this->profile_request_checkpoint('page_generation_lock_after_current_url', array('url_empty' => '' === (string) $url ? 'yes' : 'no'));
        if ('' === $url) {
            return false;
        }

        $this->profile_request_checkpoint('page_generation_lock_before_cache_path');
        $file_path = $this->get_cache_path($url);
        $this->profile_request_checkpoint('page_generation_lock_after_cache_path', array('file_path_empty' => '' === (string) $file_path ? 'yes' : 'no'));
        if ('' === $file_path) {
            return false;
        }

        $this->maybe_cleanup_stale_page_generation_locks(10 * MINUTE_IN_SECONDS, 50);

        if (!$this->acquire_page_generation_global_slot()) {
            if ($this->wait_for_page_cache_file($file_path, $this->get_page_generation_global_slot_wait_seconds())) {
                $this->profile_request_checkpoint('page_generation_global_slot_wait_hit_ready');
                $this->maybe_serve_cache_file_path($file_path, 'HIT', 'build-slot-wait');
                exit;
            }

            $this->page_cache_generation_lock_denied_reason = 'build-concurrency-limit';
            if (!headers_sent()) {
                header('X-Ultra-Cache-Build-Concurrency: limited');
            }
            return false;
        }

        $lock_name = 'page-cache-build-' . md5($file_path);
        $this->profile_request_checkpoint('page_generation_lock_acquire_start');
        if ($this->acquire_runtime_lock($lock_name, 45)) {
            $this->page_cache_generation_lock_name = $lock_name;
            $this->profile_request_checkpoint('page_generation_lock_acquired');
            return true;
        }

        $this->release_page_generation_global_lock();

        $this->profile_request_checkpoint('page_generation_lock_wait_start');
        if ($this->wait_for_page_cache_file($file_path, 6.0)) {
            $this->profile_request_checkpoint('page_generation_lock_wait_hit_ready');
            $this->maybe_serve_cache_file_path($file_path, 'HIT', 'stampede-wait');
            exit;
        }

        $this->profile_request_checkpoint('page_generation_lock_wait_timeout');
        $this->page_cache_generation_lock_denied_reason = 'stampede-timeout';
        if (!headers_sent()) {
            header('X-Ultra-Cache-Stampede: timeout');
        }

        return false;
    }

    private function get_page_cache_build_concurrency_limit()
    {
        $default = $this->is_ultracache_internal_loopback_request() ? 2 : 1;
        if (defined('ULTRACACHE_PAGE_CACHE_BUILD_CONCURRENCY')) {
            $default = (int) ULTRACACHE_PAGE_CACHE_BUILD_CONCURRENCY;
        }

        $limit = (int) apply_filters('ultracache_page_cache_build_concurrency_limit', $default, $this->is_ultracache_internal_loopback_request() ? 'internal' : 'frontend');
        return max(0, min(8, $limit));
    }

    private function get_page_generation_global_slot_wait_seconds()
    {
        $default = $this->is_ultracache_internal_loopback_request() ? 3.0 : 0.75;
        if (defined('ULTRACACHE_PAGE_CACHE_BUILD_SLOT_WAIT_SECONDS')) {
            $default = (float) ULTRACACHE_PAGE_CACHE_BUILD_SLOT_WAIT_SECONDS;
        }

        $wait = (float) apply_filters('ultracache_page_cache_build_slot_wait_seconds', $default, $this->is_ultracache_internal_loopback_request() ? 'internal' : 'frontend');
        return max(0.0, min(10.0, $wait));
    }

    private function acquire_page_generation_global_slot()
    {
        $limit = $this->get_page_cache_build_concurrency_limit();
        if ($limit <= 0) {
            return true;
        }

        $deadline = microtime(true) + $this->get_page_generation_global_slot_wait_seconds();
        do {
            for ($slot = 1; $slot <= $limit; $slot++) {
                $lock_name = 'page-cache-build-slot-' . (string) $slot;
                if ($this->acquire_runtime_lock($lock_name, 120)) {
                    $this->page_cache_generation_global_lock_name = $lock_name;
                    return true;
                }
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(150000);
        } while (true);

        return false;
    }

    private function release_page_generation_global_lock()
    {
        if ('' === $this->page_cache_generation_global_lock_name) {
            return;
        }

        $lock_name = $this->page_cache_generation_global_lock_name;
        $this->page_cache_generation_global_lock_name = '';
        $this->release_runtime_lock($lock_name);
    }

    private function maybe_cleanup_stale_page_generation_locks($age_seconds = 600, $max_delete = 50)
    {
        static $checked = false;

        if ($checked) {
            return 0;
        }

        $checked = true;
        $probability = 2;
        if (function_exists('apply_filters')) {
            $probability = (int) apply_filters('ultracache_page_generation_lock_cleanup_probability', $probability);
        }
        $probability = max(0, min(100, (int) $probability));

        if ($probability < 100) {
            $roll = wp_rand(1, 100);
            if ($roll > $probability) {
                return 0;
            }
        }

        return $this->cleanup_stale_page_generation_locks($age_seconds, $max_delete);
    }

    private function cleanup_stale_page_generation_locks($age_seconds = 600, $max_delete = 50)
    {
        $dir = $this->get_runtime_locks_dir();
        if (!is_dir($dir) || !is_readable($dir)) {
            return 0;
        }

        $age_seconds = max(120, (int) $age_seconds);
        $max_delete = max(1, min(200, (int) $max_delete));
        $now = time();
        $deleted = 0;
        $files = (array) glob(trailingslashit($dir) . 'page-cache-build-*.lock');

        foreach ($files as $file) {
            $file = wp_normalize_path((string) $file);
            if (!$this->is_runtime_lock_file_path($file) || !is_file($file)) {
                continue;
            }

            $mtime = ultracache_safe_filemtime($file, 'page_generation_lock_cleanup');
            if (!$mtime || ($now - (int) $mtime) < $age_seconds) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native flock probe avoids deleting an active build lock marker.
            $handle = @fopen($file, 'c+');
            if (!$handle) {
                continue;
            }

            $locked = !@flock($handle, LOCK_EX | LOCK_NB);
            if ($locked) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing native flock probe handle.
                @fclose($handle);
                continue;
            }

            @flock($handle, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing native flock probe handle before WP-safe deletion.
            @fclose($handle);

            if ($this->delete_runtime_lock_file($file, 'page_generation_lock_cleanup')) {
                $deleted++;
            }

            if ($deleted >= $max_delete) {
                break;
            }
        }

        return $deleted;
    }

    private function release_page_generation_lock()
    {
        if ('' !== $this->page_cache_generation_lock_name) {
            $lock_name = $this->page_cache_generation_lock_name;
            $this->page_cache_generation_lock_name = '';
            $this->release_runtime_lock($lock_name);
        }

        $this->release_page_generation_global_lock();
    }

    public function release_page_generation_lock_on_shutdown()
    {
        if ('' === $this->page_cache_generation_lock_name && '' === $this->page_cache_generation_global_lock_name) {
            return;
        }

        $this->release_page_generation_lock();
    }


    public function cache_output_callback($html)
    {
        $this->cache_output_callback_ran = true;
        $this->profile_request_checkpoint('cache_output_callback_start', array(
            'html_bytes' => is_string($html) ? strlen($html) : 0,
            'buffer_started' => $this->template_enhancement_buffer_started ? 'yes' : 'no',
            'diagnostic_fallback_used' => $this->diagnostic_fallback_output_buffer_used ? 'yes' : 'no',
        ));
        $this->buffering = false;
        $this->template_enhancement_buffer_required = false;

        if (!is_string($html) || '' === $html) {
            $this->send_shared_html_cache_headers(false);
            if ($this->is_store_profiler_enabled()) {
                $this->start_store_profile_diagnostic_skip('empty-output');
                $this->finalize_store_profile('SKIP', 'empty-output', '');
            }
            $this->release_page_generation_lock();
            return $html;
        }

        $this->start_store_profile($html);

        $current_request_url = $this->get_current_request_url();
        $html = $this->process_final_html_for_cache_storage($html, true, array(
            'accept'      => ultracache_server_value('HTTP_ACCEPT'),
            'source'      => 'store',
            'url'         => $current_request_url,
            'request_url' => $current_request_url,
        ));
        $esi_metadata = method_exists($this, 'get_esi_parent_metadata_from_html')
            ? $this->get_esi_parent_metadata_from_html($html)
            : array();
        $skip_reason = $this->get_skip_store_reason($html);
        if ('' !== $skip_reason) {
            $this->record_analytics_store_skip($skip_reason);
            if ($this->is_internal_revalidate_request()) {
                $this->clear_revalidate_lock($current_request_url);
            }
            $this->send_set_cookie_skip_diagnostic_header($skip_reason);
            $this->send_shared_html_cache_headers(false, null, '', $esi_metadata);
            $this->send_debug_headers('SKIP', $skip_reason);
            $this->finalize_store_profile('SKIP', $skip_reason, '');
            $this->release_page_generation_lock();
            return $html;
        }

        $url = $current_request_url;
        $file_path = $this->get_cache_path($url);
        if (empty($file_path)) {
            $this->send_shared_html_cache_headers(false, null, '', $esi_metadata);
            $this->send_debug_headers('SKIP', 'empty-cache-path');
            $this->finalize_store_profile('SKIP', 'empty-cache-path', '');
            $this->release_page_generation_lock();
            return $html;
        }

        $store_write_started_at = microtime(true);
        $write_ok = $this->profile_store_event('final_cache_write', $html, function ($html) use ($file_path, $url) {
            return $this->write_cache_file($file_path, $html, $url);
        });
        $store_write_ms = (int) round((microtime(true) - $store_write_started_at) * 1000);
        if (!headers_sent()) {
            header('X-Ultra-Cache-Store-Write-Ms: ' . (string) $store_write_ms);
            $warning_threshold_ms = (int) apply_filters('ultracache_store_write_warning_ms', 1200);
            if ($store_write_ms >= max(250, $warning_threshold_ms)) {
                header('X-Ultra-Cache-Store-Warning: slow-write-' . (string) $store_write_ms . 'ms');
            }
        }
        if (!$write_ok || !file_exists($file_path)) {
            $this->record_analytics_store_skip('write-failed');
            if ($this->is_internal_revalidate_request()) {
                $this->clear_revalidate_lock($url);
            }
            $this->send_shared_html_cache_headers(false, null, '', $esi_metadata);
            $this->send_debug_headers('SKIP', 'write-failed');
            $this->finalize_store_profile('SKIP', 'write-failed', $file_path);
            $this->release_page_generation_lock();
            return $html;
        }

        if ($this->is_internal_revalidate_request()) {
            $this->clear_revalidate_lock($url);
            if (!headers_sent()) {
                header('X-Ultra-Cache-Revalidate: refreshed');
            }
        }
        $is_esi_parent = !empty($esi_metadata);
        $validator_metadata = $is_esi_parent
            ? array()
            : $this->get_cached_html_validator_metadata($file_path, 'identity');
        $not_modified = !$is_esi_parent
            && !headers_sent()
            && !empty($validator_metadata)
            && $this->cached_html_request_is_not_modified($validator_metadata);

        $this->send_shared_html_cache_headers(true, null, $url, $esi_metadata);
        $this->send_cached_html_validator_headers($validator_metadata);
        if ($is_esi_parent && $this->should_send_source_debug_header()) {
            header('X-UltraCache-ESI-Validators: disabled');
        }
        $this->send_debug_headers('STORE');
        if (!headers_sent()) {
            header('X-Ultra-Cache-Store-Post-Response: deferred');
            if ($not_modified) {
                $this->send_cached_html_not_modified_status();
            }
        }
        $this->profile_request_checkpoint('cache_output_callback_end', array('html_bytes' => is_string($html) ? strlen($html) : 0));

        // Speed Diagnostics requests need the timing breakdown saved before the
        // dashboard REST request returns. Normal visitor analytics still stay
        // deferred so the 2.56.123 TTFB protection remains intact.
        if ($this->is_store_profiler_enabled()) {
            $this->finalize_store_profile('STORE', '', $file_path);
        }

        $this->defer_store_post_response_action('store_success', array(
            'url' => $url,
            'file_path' => $file_path,
        ));
        $this->release_page_generation_lock();

        $method = strtoupper((string) ultracache_server_value('REQUEST_METHOD'));
        return ($not_modified || 'HEAD' === $method) ? '' : $html;
    }

    /**
     * Apply the final frontend rewrite pipeline before any HTML is stored in
     * UltraCache page-cache files. This method is shared by normal STORE
     * requests and explicit warm/loopback writes so CSS bundle, font, image,
     * JS, and LCP rewrites cannot diverge between browser output and cached
     * HTML.
     *
     * @param string $html    Full frontend HTML.
     * @param bool   $profile Whether to record the outer store-profile stages.
     * @param array  $context Optional finalizer context, including explicit Accept header and target URL for warmed buckets.
     * @return string
     */
    private function process_final_html_for_cache_storage($html, $profile = true, array $context = array())
    {
        if (!is_string($html) || '' === $html) {
            return $html;
        }

        if ($profile) {
            $html = $this->profile_store_stage('elementor-page-css-dependency-reconciliation', $html, function ($html) use ($context) {
                return $this->reconcile_elementor_page_css_dependencies($html, $context);
            });
            $html = $this->profile_store_stage('frontend_performance_optimizations_total', $html, function ($html) use ($context) {
                return $this->apply_frontend_performance_optimizations($html, $context);
            });
            $html = $this->profile_store_stage('final_google_fonts_rewrite_before_skip_check', $html, function ($html) {
                return $this->apply_final_google_fonts_rewrite_before_cache_store($html);
            });
            $html = $this->profile_store_stage('final-font-display-rewrite-before-store', $html, function ($html) {
                return $this->apply_final_font_display_rewrite_before_cache_store($html);
            });
            $html = $this->profile_store_stage('final-media-url-reconciliation', $html, function ($html) use ($context) {
                return $this->apply_final_media_html_rewrite($html, $context);
            });
            $html = $this->profile_store_stage('final-generated-asset-root-relative-urls', $html, function ($html) use ($context) {
                return $this->normalize_generated_asset_urls_to_root_relative($html, $context);
            });
            $html = $this->profile_store_stage('strip-internal-control-query-args', $html, function ($html) {
                return $this->strip_internal_control_query_args_from_cached_html($html);
            });
            $html = $this->profile_store_stage('remove-hrefless-link-placeholders', $html, function ($html) {
                return $this->remove_hrefless_ultracache_link_placeholders($html);
            });
            $html = $this->profile_store_stage('final-esi-placeholder-conversion', $html, function ($html) {
                return $this->apply_esi_placeholders_before_cache_store($html);
            });

            return is_string($html) ? $html : '';
        }

        $html = $this->reconcile_elementor_page_css_dependencies($html, $context);
        $html = $this->apply_frontend_performance_optimizations($html, $context);
        $html = $this->apply_final_google_fonts_rewrite_before_cache_store($html);
        $html = $this->apply_final_font_display_rewrite_before_cache_store($html);
        $html = $this->apply_final_media_html_rewrite($html, $context);
        $html = $this->normalize_generated_asset_urls_to_root_relative($html, $context);
        $html = $this->strip_internal_control_query_args_from_cached_html($html);
        $html = $this->remove_hrefless_ultracache_link_placeholders($html);
        $html = $this->apply_esi_placeholders_before_cache_store($html);

        return is_string($html) ? $html : '';
    }

    /**
     * Remove UltraCache internal request parameters from public cached URLs.
     *
     * Internal warm-up, profiler and stale revalidation requests may carry
     * control query arguments so reverse proxies cannot serve an old cached
     * document to the loopback request. Multilingual plugins can build
     * language-switcher links from the active request URI, so those private
     * arguments must be removed from frontend URL attributes before the HTML
     * is stored as public page cache.
     *
     * @param string $html Full frontend HTML.
     * @return string
     */
    private function strip_internal_control_query_args_from_cached_html($html)
    {
        if (!is_string($html) || '' === $html || false === stripos($html, 'ultracache_')) {
            return $html;
        }

        $control_args = array(
            'ultracache_revalidate',
            'ultracache_rt',
            'ultracache_rv',
            'ultracache_bucket',
            'ultracache_store_profile',
            'ultracache_callback_profile',
            'ultracache_store_profile_verbose',
            'ultracache_store_profile_verbose_settings',
            'ultracache_profile_bypass',
            'ultracache_profile_run',
            'ultracache_runtime_js_scan',
            'ultracache_runtime_js_scan_id',
            'ultracache_runtime_js_scan_nonce',
            'ultracache_runtime_js_scan_context',
        );

        $charset = function_exists('get_bloginfo') ? (string) get_bloginfo('charset') : '';
        if ('' === $charset) {
            $charset = 'UTF-8';
        }

        $cleaned = preg_replace_callback(
            '/\b(href|src|action|formaction|poster|data-[a-z0-9_-]*(?:url|href|src))\s*=\s*(["\'])(.*?)\2/is',
            function ($matches) use ($control_args, $charset) {
                $attribute = isset($matches[1]) ? (string) $matches[1] : '';
                $quote = isset($matches[2]) ? (string) $matches[2] : '"';
                $value = isset($matches[3]) ? (string) $matches[3] : '';

                if ('' === $attribute || '' === $value || false === stripos($value, 'ultracache_')) {
                    return isset($matches[0]) ? (string) $matches[0] : '';
                }

                $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, $charset);
                if ('' === $decoded || false === stripos($decoded, 'ultracache_')) {
                    return isset($matches[0]) ? (string) $matches[0] : '';
                }

                $clean_url = remove_query_arg($control_args, $decoded);
                if (!is_string($clean_url) || $clean_url === $decoded) {
                    return isset($matches[0]) ? (string) $matches[0] : '';
                }

                $escaped_url = esc_url($clean_url);
                if ('' === $escaped_url) {
                    return isset($matches[0]) ? (string) $matches[0] : '';
                }

                return $attribute . '=' . $quote . $escaped_url . $quote;
            },
            $html
        );

        return is_string($cleaned) ? $cleaned : $html;
    }

    /**
     * Remove frontend-only UltraCache diagnostic link placeholders.
     *
     * Removed source stylesheets/resource hints must not remain as href-less
     * <link> elements. Third-party scripts such as Slider Revolution inspect
     * document link nodes and assume link.href is a real load-bearing URL.
     * With debug disabled the placeholder is removed entirely; with debug
     * enabled it is converted to an HTML comment so diagnostics remain visible
     * in View Source without creating a DOM link node.
     *
     * @param string $html Full frontend HTML.
     * @return string
     */
    private function remove_hrefless_ultracache_link_placeholders($html)
    {
        if (!is_string($html) || '' === $html || false === stripos($html, '<link') || false === stripos($html, 'data-ultracache-')) {
            return $html;
        }

        $settings = $this->get_settings();
        $debug_enabled = !empty($settings['debug_headers_enabled']) || !empty($settings['debugHeadersEnabled']);

        $updated = preg_replace_callback('#<link\b[^>]*>#i', function ($matches) use ($debug_enabled) {
            $tag = isset($matches[0]) ? (string) $matches[0] : '';
            if ('' === $tag || false === stripos($tag, 'data-ultracache-')) {
                return $tag;
            }

            $attrs = array();
            if (!preg_match_all('/\s([A-Za-z_:][-A-Za-z0-9_:.]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/u', $tag, $attrs, PREG_SET_ORDER)) {
                return $tag;
            }

            $has_href = false;
            $ultracache_attrs = array();
            foreach ($attrs as $attr) {
                $name = strtolower((string) ($attr[1] ?? ''));
                if ('href' === $name) {
                    $has_href = true;
                    break;
                }

                if (0 === strpos($name, 'data-ultracache-')) {
                    $value = isset($attr[3]) && '' !== $attr[3]
                        ? (string) $attr[3]
                        : (isset($attr[4]) && '' !== $attr[4] ? (string) $attr[4] : (string) ($attr[5] ?? ''));
                    $ultracache_attrs[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }

            if ($has_href || empty($ultracache_attrs)) {
                return $tag;
            }

            if (!$debug_enabled) {
                return '';
            }

            $parts = array();
            foreach ($ultracache_attrs as $name => $value) {
                $value = preg_replace('/\s+/', ' ', (string) $value);
                $parts[] = $name . '=' . $value;
            }

            $summary = implode('; ', $parts);
            $summary = str_replace(array('--', '<', '>'), array('—', '&lt;', '&gt;'), $summary);
            $summary = substr($summary, 0, 900);

            return '<!-- UltraCache removed link placeholder: ' . $summary . ' -->';
        }, $html);

        return is_string($updated) ? $updated : $html;
    }

    /**
     * Convert same-site UltraCache-owned generated asset URLs to root-relative
     * URLs before cached HTML is stored. WP-CLI/loopback warm contexts can make
     * WordPress URL helpers emit http:// URLs even for HTTPS pages; root-relative
     * asset paths avoid mixed-content breakage without forcing a scheme or
     * touching third-party/theme/plugin assets.
     *
     * @param string $html    Full frontend HTML.
     * @param array  $context Finalizer context, optionally containing url/request_url.
     * @return string
     */
    private function normalize_generated_asset_urls_to_root_relative($html, array $context = array())
    {
        if (!is_string($html) || '' === $html) {
            return $html;
        }

        $roots = array(
            ultracache_generated_asset_public_path(),
            ultracache_optimized_images_storage_url_path(),
        );
        $roots = array_values(array_unique(array_filter(array_map(static function ($root) {
            $root = '/' . ltrim((string) $root, '/');
            return '/' === $root ? '' : rtrim($root, '/') . '/';
        }, $roots))));

        if (empty($roots)) {
            return $html;
        }

        $root_found = false;
        foreach ($roots as $root) {
            if (false !== stripos($html, $root)) {
                $root_found = true;
                break;
            }
        }
        if (!$root_found) {
            return $html;
        }

        $host_candidates = array(
            home_url('/'),
            site_url('/'),
            content_url('/'),
        );

        if (!empty($context['url'])) {
            $host_candidates[] = (string) $context['url'];
        }
        if (!empty($context['request_url'])) {
            $host_candidates[] = (string) $context['request_url'];
        }

        $hosts = array();
        foreach ($host_candidates as $candidate) {
            $host = wp_parse_url((string) $candidate, PHP_URL_HOST);
            if (is_string($host) && '' !== $host) {
                $hosts[] = strtolower($host);
            }
        }
        $hosts = array_values(array_unique($hosts));

        if (empty($hosts)) {
            return $html;
        }

        $rewritten = preg_replace_callback('~\\bhttps?://([^/\\s"\'<>),]+)(/[^\\s"\'<>),]*)?~i', function ($matches) use ($hosts, $roots) {
            $url = isset($matches[0]) ? (string) $matches[0] : '';
            $host = isset($matches[1]) ? strtolower((string) $matches[1]) : '';

            if ('' === $url || '' === $host || !in_array($host, $hosts, true)) {
                return $url;
            }

            $path = wp_parse_url($url, PHP_URL_PATH);
            if (!is_string($path) || '' === $path) {
                return $url;
            }

            foreach ($roots as $root) {
                if (0 === strpos($path, $root)) {
                    $relative = wp_make_link_relative($url);
                    return is_string($relative) && '' !== $relative ? $relative : $url;
                }
            }

            return $url;
        }, $html);

        return is_string($rewritten) ? $rewritten : $html;
    }

    /**
     * Run the media converter's final full-document image URL reconciliation
     * after earlier final-output stages have injected generated CSS and font
     * assets, but before root-relative generated asset URL normalization.
     *
     * @param string $html    Full frontend HTML.
     * @param array  $context Finalizer context, including explicit Accept header.
     * @return string
     */
    private function apply_final_media_html_rewrite($html, array $context = array())
    {
        if (!is_string($html) || '' === $html) {
            return $html;
        }

        if (!class_exists('Ultra_Cache_Media_Converter') || !method_exists('Ultra_Cache_Media_Converter', 'get_instance')) {
            return $html;
        }

        $converter = Ultra_Cache_Media_Converter::get_instance();
        if (!is_object($converter) || !method_exists($converter, 'rewrite_html_image_urls')) {
            return $html;
        }

        if (method_exists($converter, 'rewrite_html_image_urls_with_context')) {
            $rewritten = $converter->rewrite_html_image_urls_with_context($html, $context);
        } else {
            $accept_header = isset($context['accept']) ? (string) $context['accept'] : '';
            if ('' !== $accept_header && method_exists($converter, 'rewrite_html_image_urls_with_accept')) {
                $rewritten = $converter->rewrite_html_image_urls_with_accept($html, $accept_header);
            } else {
                $rewritten = $converter->rewrite_html_image_urls($html);
            }
        }

        return is_string($rewritten) && '' !== $rewritten ? $rewritten : $html;
    }


    private function get_skip_store_reason($html)
    {
        if ('' !== $this->get_elementor_page_css_dependency_error()) {
            return 'elementor-css-dependency-unresolved';
        }

        if (is_admin()) {
            return 'admin';
        }

        if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
            return 'donotcachepage';
        }

        $settings = $this->get_settings();
        if ($this->is_woocommerce_dynamic_request($this->get_current_request_url(), $settings)) {
            return 'woocommerce-dynamic';
        }

        if (function_exists('is_user_logged_in') && is_user_logged_in() && empty($settings['cache_logged_in_users'])) {
            return 'logged-in-user';
        }

        $status_code = function_exists('http_response_code') ? (int) http_response_code() : 200;
        if ($status_code && 200 !== $status_code) {
            return 'status-' . $status_code;
        }

        // Google Fonts localization is best-effort and must never block page-cache storage.
        // If local font CSS is still being built, the HTML keeps the original Google Fonts fallback
        // and the background/real-cron build can localize it on a later regeneration.

        if (strlen($html) < 255) {
            return 'response-too-short';
        }

        if (false === stripos($html, '<html')) {
            return 'missing-html-tag';
        }

        if ($this->has_fragile_revslider_shell($html)) {
            return 'fragile-revslider-shell';
        }

        $response_cookie_names = array();
        $checked_response_cookies = false;
        foreach (headers_list() as $header) {
            if (0 === stripos($header, 'Set-Cookie:')) {
                if (!$checked_response_cookies) {
                    $response_cookie_names = $this->get_response_set_cookie_names();
                    $checked_response_cookies = true;
                }

                if (!empty($settings['cache_safe_tracking_cookies']) && !empty($response_cookie_names) && $this->all_cookie_names_match_patterns($response_cookie_names, $this->get_safe_tracking_cookie_patterns($settings))) {
                    continue;
                }

                if (!empty($response_cookie_names)) {
                    return 'set-cookie:' . implode(',', array_slice($response_cookie_names, 0, 6));
                }
                return 'set-cookie';
            }

            if (0 === stripos($header, 'Cache-Control:')) {
                $cache_control_value = strtolower(trim(substr((string) $header, strlen('Cache-Control:'))));
                if (1 === preg_match('/(?:^|[,\s])(private|no-store|no-cache)(?:$|[,=\s])/', $cache_control_value, $cache_control_match)) {
                    return 'cache-control-' . sanitize_key((string) $cache_control_match[1]);
                }
            }
        }

        return '';
    }

    private function get_response_set_cookie_names()
    {
        $names = array();
        foreach (headers_list() as $header) {
            $header = (string) $header;
            if (0 !== stripos($header, 'Set-Cookie:')) {
                continue;
            }
            $value = trim(substr($header, strlen('Set-Cookie:')));
            if ('' === $value) {
                continue;
            }
            $pair = explode(';', $value, 2);
            $name = trim((string) ($pair[0] ?? ''));
            $name = explode('=', $name, 2)[0];
            $name = preg_replace('/[^A-Za-z0-9_\-.]/', '', (string) $name);
            if ('' !== $name) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    private function send_set_cookie_skip_diagnostic_header($skip_reason)
    {
        if (headers_sent() || 0 !== strpos((string) $skip_reason, 'set-cookie')) {
            return;
        }

        $cookie_names = $this->get_response_set_cookie_names();
        if (empty($cookie_names)) {
            return;
        }

        $value = implode(',', array_slice($cookie_names, 0, 12));
        $value = substr((string) preg_replace('/[^A-Za-z0-9_,.\-]/', '', $value), 0, 180);
        if ('' !== $value) {
            header('X-Ultra-Cache-Set-Cookie-Names: ' . $value);
        }
    }

    private function should_send_source_debug_header()
    {
        $settings = $this->get_settings();
        if (empty($settings['debug_headers_enabled']) && empty($settings['debugHeadersEnabled'])) {
            return false;
        }
        $flag = function_exists('ultracache_server_value') ? strtolower(trim((string) ultracache_server_value('HTTP_X_ULTRACACHE_DEBUG'))) : '';
        return in_array($flag, array('1', 'true', 'yes', 'on'), true);
    }

    private function send_debug_headers($status, $reason = '')
    {
        if (headers_sent()) {
            return;
        }

        $status = strtoupper((string) preg_replace('/[^A-Z-]/', '', (string) $status));
        if ('' !== $status) {
            header('X-Ultra-Cache: ' . $status);
        }

        $this->send_html_variant_headers();

        if ('' !== $reason) {
            $reason = substr((string) preg_replace('/[^A-Za-z0-9_. -]/', '-', (string) $reason), 0, 120);
            header('X-Ultra-Cache-Reason: ' . $reason);
        }
    }


    private function get_settings()
    {
        $this->profile_settings_request_checkpoint('engine_get_settings_start');
        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
            $this->profile_settings_request_checkpoint('engine_get_settings_before_wp_static');
            $settings = Ultra_Cache_WP::get_settings();
            $this->profile_settings_request_checkpoint('engine_get_settings_after_wp_static', array(
                'settings_count' => is_array($settings) ? count($settings) : 0,
            ));
            return $settings;
        }

        $this->profile_settings_request_checkpoint('engine_get_settings_before_raw_option');
        $saved = get_option(ULTRACACHE_SETTINGS_KEY, array());
        $settings = is_array($saved) ? $saved : array();
        $this->profile_settings_request_checkpoint('engine_get_settings_after_raw_option', array(
            'settings_count' => count($settings),
        ));
        return $settings;
    }



}
