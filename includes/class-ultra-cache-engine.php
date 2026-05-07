<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/engine/class-engine-storage-trait.php';
require_once __DIR__ . '/engine/class-engine-css-bundle-trait.php';
require_once __DIR__ . '/engine/class-engine-font-optimization-trait.php';
require_once __DIR__ . '/engine/class-engine-lcp-slider-trait.php';
require_once __DIR__ . '/engine/class-engine-js-optimization-trait.php';
require_once __DIR__ . '/engine/class-engine-media-image-trait.php';
require_once __DIR__ . '/engine/class-engine-html-output-trait.php';
require_once __DIR__ . '/engine/class-engine-cache-decision-trait.php';
require_once __DIR__ . '/engine/class-engine-warm-crawl-trait.php';
require_once __DIR__ . '/engine/class-engine-profiling-metrics-trait.php';
require_once __DIR__ . '/engine/class-engine-dropin-lifecycle-trait.php';
require_once __DIR__ . '/engine/class-engine-analytics-trait.php';
require_once __DIR__ . '/engine/class-engine-async-css-trait.php';

if (!class_exists('Ultra_Cache_Engine')) {
    class Ultra_Cache_Engine
    {
        use Ultra_Cache_Engine_Storage_Trait;
        use Ultra_Cache_Engine_CSS_Bundle_Trait;
        use Ultra_Cache_Engine_Font_Optimization_Trait;
        use Ultra_Cache_Engine_LCP_Slider_Trait;
        use Ultra_Cache_Engine_JS_Optimization_Trait;
        use Ultra_Cache_Engine_Media_Image_Trait;
        use Ultra_Cache_Engine_HTML_Output_Trait;
        use Ultra_Cache_Engine_Cache_Decision_Trait;
        use Ultra_Cache_Engine_Warm_Crawl_Trait;
        use Ultra_Cache_Engine_Profiling_Metrics_Trait;
        use Ultra_Cache_Engine_Dropin_Lifecycle_Trait;
        use Ultra_Cache_Engine_Analytics_Trait;
        use Ultra_Cache_Engine_Async_CSS_Trait;

        /** @var Ultra_Cache_Engine|null */
        private static $instance = null;

        /** @var bool */
        private $buffering = false;

        /** @var bool */
        private $template_enhancement_buffer_required = false;

        /** @var bool */
        private $template_enhancement_buffer_started = false;

        /** @var string */
        private $last_bypass_reason = '';

        /** @var array<string, resource> */
        private $runtime_locks = array();

        /** @var bool */
        private $google_fonts_async_pending = false;

        /** @var bool */
        private $google_fonts_sync_build_mode = false;

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

        private function register_hooks()
        {
            add_action('init', array($this, 'maybe_apply_runtime_js_scan_anonymous_context'), -999);
            add_action('init', array($this, 'profile_init_checkpoint'), 0);
            add_action('wp_loaded', array($this, 'profile_wp_loaded_checkpoint'), 0);
            add_action('template_redirect', array($this, 'profile_template_redirect_checkpoint'), -1000);
            add_action('template_redirect', array($this, 'maybe_start_buffering'), 0);
            add_filter('wp_should_output_buffer_template_for_enhancement', array($this, 'should_force_template_enhancement_output_buffer'), PHP_INT_MAX);
            add_action('wp_template_enhancement_output_buffer_started', array($this, 'template_enhancement_output_buffer_started_checkpoint'), 0);
            add_action('save_post', array($this, 'handle_post_update'), 20);
            add_action('delete_post', array($this, 'handle_post_deletion'), 20);
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
            add_filter('wp_template_enhancement_output_buffer', array($this, 'inject_runtime_js_scan_collector_into_output'), PHP_INT_MAX);
            add_action('wp_head', array($this, 'print_runtime_js_scan_collector'), 0);
            add_action('wp_head', array($this, 'print_delayed_script_loader'), 1);
            add_action('wp_enqueue_scripts', array($this, 'profile_wp_enqueue_scripts_start_checkpoint'), -1000);
            add_action('wp_enqueue_scripts', array($this, 'profile_wp_enqueue_scripts_end_checkpoint'), PHP_INT_MAX);
            add_action('wp_enqueue_scripts', array($this, 'cleanup_asset_chain_enqueue_assets'), 9999);
            add_action('shutdown', array($this, 'run_deferred_store_post_response_actions'), PHP_INT_MAX - 2);
            add_action('shutdown', array($this, 'update_store_profile_after_shutdown'), PHP_INT_MAX);
            add_filter('script_loader_tag', array($this, 'defer_scripts'), 10, 3);
            add_filter('style_loader_src', array($this, 'add_display_swap_to_google_fonts'), 20, 2);
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
            $this->profile_request_checkpoint('page_generation_lock_checked', array('has_lock' => '' !== (string) $this->page_cache_generation_lock_name ? 'true' : 'false'));

            $this->profile_request_checkpoint('record_analytics_miss_start');
            $this->record_analytics_miss();
            $this->profile_request_checkpoint('record_analytics_miss_end');
            $this->profile_request_checkpoint('send_debug_headers_start');
            $this->send_debug_headers('MISS');
            $this->profile_request_checkpoint('send_debug_headers_end');
            $this->buffering = true;
            $this->template_enhancement_buffer_required = true;
            $this->profile_request_checkpoint('buffer_start');
            add_filter('wp_template_enhancement_output_buffer', array($this, 'cache_output_callback'), 100);
        }

        public function should_force_template_enhancement_output_buffer($should_buffer)
        {
            if (method_exists($this, 'is_runtime_js_scan_request') && $this->is_runtime_js_scan_request()) {
                $this->profile_request_checkpoint('runtime_js_scan_template_buffer_forced');
                return true;
            }

            if ($this->template_enhancement_buffer_required) {
                $this->profile_request_checkpoint('template_enhancement_buffer_forced');
                return true;
            }

            return (bool) $should_buffer;
        }

        public function template_enhancement_output_buffer_started_checkpoint()
        {
            $this->template_enhancement_buffer_started = true;
            $this->profile_request_checkpoint('template_enhancement_buffer_started');
        }

        private function maybe_acquire_page_generation_lock()
        {
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

            $lock_name = 'page-cache-build-' . md5($file_path);
            $this->profile_request_checkpoint('page_generation_lock_acquire_start');
            if ($this->acquire_runtime_lock($lock_name, 45)) {
                $this->page_cache_generation_lock_name = $lock_name;
                $this->profile_request_checkpoint('page_generation_lock_acquired');
                return true;
            }

            $this->profile_request_checkpoint('page_generation_lock_wait_start');
            if ($this->wait_for_page_cache_file($file_path, 18.0)) {
                $this->profile_request_checkpoint('page_generation_lock_wait_hit_ready');
                $this->maybe_serve_cache_file_path($file_path, 'HIT', 'stampede-wait');
                exit;
            }

            $this->profile_request_checkpoint('page_generation_lock_wait_timeout');
            if (!headers_sent()) {
                header('X-Ultra-Cache-Stampede: timeout');
            }

            return false;
        }

        private function release_page_generation_lock()
        {
            if ('' === $this->page_cache_generation_lock_name) {
                return;
            }

            $lock_name = $this->page_cache_generation_lock_name;
            $this->page_cache_generation_lock_name = '';
            $this->release_runtime_lock($lock_name);
        }


        public function cache_output_callback($html)
        {
            $this->profile_request_checkpoint('cache_output_callback_start', array(
                'html_bytes' => is_string($html) ? strlen($html) : 0,
                'buffer_started' => $this->template_enhancement_buffer_started ? 'yes' : 'no',
            ));
            $this->buffering = false;
            $this->template_enhancement_buffer_required = false;

            if (!is_string($html) || '' === $html) {
                $this->release_page_generation_lock();
                return $html;
            }

            $this->start_store_profile($html);

            $current_request_url = $this->get_current_request_url();
            $html = $this->process_final_html_for_cache_storage($html, true, array(
                'accept'      => ucwp_server_value('HTTP_ACCEPT'),
                'source'      => 'store',
                'url'         => $current_request_url,
                'request_url' => $current_request_url,
            ));
            $skip_reason = $this->get_skip_store_reason($html);
            if ('' !== $skip_reason) {
                $this->record_analytics_store_skip($skip_reason);
                if ($this->is_internal_revalidate_request()) {
                    $this->clear_revalidate_lock($current_request_url);
                }
                $this->send_debug_headers('SKIP', $skip_reason);
                $this->finalize_store_profile('SKIP', $skip_reason, '');
                $this->release_page_generation_lock();
                return $html;
            }

            $url = $current_request_url;
            $file_path = $this->get_cache_path($url);
            if (empty($file_path)) {
                $this->send_debug_headers('SKIP', 'empty-cache-path');
                $this->finalize_store_profile('SKIP', 'empty-cache-path', '');
                $this->release_page_generation_lock();
                return $html;
            }

            $store_write_started_at = microtime(true);
            $write_ok = $this->profile_store_event('final_cache_write', $html, function ($html) use ($file_path) {
                return $this->write_cache_file($file_path, $html);
            });
            $store_write_ms = (int) round((microtime(true) - $store_write_started_at) * 1000);
            if (!headers_sent()) {
                header('X-Ultra-Cache-Store-Write-Ms: ' . (string) $store_write_ms);
                $warning_threshold_ms = (int) apply_filters('ucwp_store_write_warning_ms', 1200);
                if ($store_write_ms >= max(250, $warning_threshold_ms)) {
                    header('X-Ultra-Cache-Store-Warning: slow-write-' . (string) $store_write_ms . 'ms');
                }
            }
            if (!$write_ok || !file_exists($file_path)) {
                $this->record_analytics_store_skip('write-failed');
                if ($this->is_internal_revalidate_request()) {
                    $this->clear_revalidate_lock($url);
                }
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
            $this->send_debug_headers('STORE');
            if (!headers_sent()) {
                header('X-Ultra-Cache-Store-Post-Response: deferred');
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

            return $html;
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
                $html = $this->profile_store_stage('frontend_performance_optimizations_total', $html, function ($html) use ($context) {
                    return $this->apply_frontend_performance_optimizations($html, $context);
                });
                $html = $this->profile_store_stage('final_google_fonts_rewrite_before_skip_check', $html, function ($html) {
                    return $this->apply_final_google_fonts_rewrite_before_cache_store($html);
                });
                $html = $this->profile_store_stage('final-media-url-reconciliation', $html, function ($html) use ($context) {
                    return $this->apply_final_media_html_rewrite($html, $context);
                });
                $html = $this->profile_store_stage('final-generated-asset-root-relative-urls', $html, function ($html) use ($context) {
                    return $this->normalize_generated_asset_urls_to_root_relative($html, $context);
                });

                return is_string($html) ? $html : '';
            }

            $html = $this->apply_frontend_performance_optimizations($html, $context);
            $html = $this->apply_final_google_fonts_rewrite_before_cache_store($html);
            $html = $this->apply_final_media_html_rewrite($html, $context);
            $html = $this->normalize_generated_asset_urls_to_root_relative($html, $context);

            return is_string($html) ? $html : '';
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
                (string) wp_parse_url(content_url('cache/ultracache/'), PHP_URL_PATH),
                (string) wp_parse_url(content_url('uploads/uc-images/'), PHP_URL_PATH),
                '/wp-content/cache/ultracache/',
                '/wp-content/uploads/uc-images/',
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

            $accept_header = isset($context['accept']) ? (string) $context['accept'] : '';
            if ('' !== $accept_header && method_exists($converter, 'rewrite_html_image_urls_with_accept')) {
                $rewritten = $converter->rewrite_html_image_urls_with_accept($html, $accept_header);
            } else {
                $rewritten = $converter->rewrite_html_image_urls($html);
            }

            return is_string($rewritten) && '' !== $rewritten ? $rewritten : $html;
        }


        private function get_skip_store_reason($html)
        {
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

            foreach (headers_list() as $header) {
                if (0 === stripos($header, 'Set-Cookie:')) {
                    return 'set-cookie';
                }

                if (0 === stripos($header, 'Cache-Control:') && false !== stripos($header, 'no-cache')) {
                    return 'cache-control-no-cache';
                }
            }

            return '';
        }

        private function should_send_source_debug_header()
        {
            $flag = function_exists('ucwp_server_value') ? strtolower(trim((string) ucwp_server_value('HTTP_X_ULTRACACHE_DEBUG'))) : '';
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

            header('Vary: Accept, Accept-Encoding', false);

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
            $saved = get_option(UCWP_SETTINGS_KEY, array());
            $settings = is_array($saved) ? $saved : array();
            $this->profile_settings_request_checkpoint('engine_get_settings_after_raw_option', array(
                'settings_count' => count($settings),
            ));
            return $settings;
        }


        private function get_request_image_bucket($accept_header = null)
        {
            if (null === $accept_header) {
                $accept_header = ucwp_server_value('HTTP_ACCEPT');
            }

            $accept_header = strtolower((string) $accept_header);
            if (false !== strpos($accept_header, 'image/avif')) {
                return 'avif';
            }

            if (false !== strpos($accept_header, 'image/webp')) {
                return 'webp';
            }

            return 'orig';
        }


    }
}

