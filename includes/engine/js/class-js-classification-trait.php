<?php
/**
 * JavaScript classification and runtime-context helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_JS_Classification_Trait
{
    private function get_script_critical_request_candidate($tag, $offset, $head_end, array $settings = array())
    {
        $tag = (string) $tag;
        $delayed = (false !== stripos($tag, 'type="text/ultracache-delayed-js"') || false !== stripos($tag, "type='text/ultracache-delayed-js'") || false !== stripos($tag, 'data-ultracache-src='));
        $src = $delayed ? (string) $this->extract_attribute_from_html_tag($tag, 'data-ultracache-src') : (string) $this->extract_attribute_from_html_tag($tag, 'src');
        $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5);
        if ('' === $src) {
            return array();
        }

        $handle = (string) $this->extract_attribute_from_html_tag($tag, 'data-ultracache-handle');
        if ('' === $handle) {
            $handle = (string) $this->extract_attribute_from_html_tag($tag, 'id');
            $handle = preg_replace('/-js(?:-extra)?$/', '', $handle);
        }
        $handle = is_string($handle) ? $handle : '';

        $location = $this->get_html_offset_location($offset, $head_end);
        $has_async = $this->html_tag_has_attribute($tag, 'async');
        $has_defer = $this->html_tag_has_attribute($tag, 'defer');
        $is_module = (false !== stripos($tag, 'type="module"') || false !== stripos($tag, "type='module'"));
        $render_blocking = (!$delayed && 'head' === $location && !$has_async && !$has_defer && !$is_module && $this->is_delayable_external_script_tag($tag));
        $origin = $this->get_public_resource_origin_type($src);
        $path = $this->get_public_resource_path_fragment($src);
        $bytes = 0;
        $local_path = $this->resolve_local_path_from_public_url($src);
        if ('' !== $local_path && is_readable($local_path)) {
            $bytes = (int) filesize($local_path);
        }

        $protected = false;
        $protected_reason = '';
        $status = $render_blocking ? 'render-blocking' : 'non-blocking';
        $reason = $render_blocking ? 'head script without async/defer/delay marker' : 'not a parser-blocking head script';
        $suggested = 'Review before changing.';

        if ($delayed) {
            $status = 'delayed';
            $reason = 'already delayed by UltraCache';
            $suggested = 'No action needed unless the delayed script is needed before interaction.';
        } elseif ($has_defer) {
            $status = 'deferred';
            $reason = 'defer attribute present';
            $suggested = 'Already out of the parser-blocking path.';
        } elseif ($has_async) {
            $status = 'async';
            $reason = 'async attribute present';
            $suggested = 'Already out of the parser-blocking path.';
        }

        if (!$delayed) {
            $slider_fragment = !empty($settings['slider_safe_mode']) ? $this->get_matching_fragment($handle, $src, $tag, $this->get_slider_hero_protected_fragments()) : '';
            if ('' !== $slider_fragment) {
                $protected = true;
                $protected_reason = 'slider/hero runtime fragment: ' . $slider_fragment;
                $reason = $protected_reason;
                $suggested = 'Keep protected while Fix sliders / hero sections is enabled and the slider is above the fold.';
            } elseif ($this->is_script_user_defer_excluded($handle, $src, $settings)) {
                $protected = true;
                $protected_reason = 'user-visible defer/delay exclusion matched';
                $reason = $protected_reason;
                $suggested = 'Review the visible exclusion list before changing.';
            } elseif ($render_blocking && !empty($settings['lcp_boundary_defer']) && $this->matches_non_critical_delay_patterns($handle, $src, $tag)) {
                $suggested = 'Candidate for LCP Boundary Defer / critical-chain relief after visual testing.';
            } elseif ($render_blocking) {
                $suggested = 'Candidate for defer/delay only after dependency checks.';
            }
        }

        return array(
            'type' => 'script',
            'url' => $src,
            'path' => $path,
            'handle' => $handle,
            'origin' => $origin,
            'location' => $location,
            'renderBlocking' => (bool) $render_blocking,
            'delayed' => (bool) $delayed,
            'protected' => (bool) $protected,
            'protectedReason' => $protected_reason,
            'status' => $status,
            'reason' => $reason,
            'suggestedAction' => $suggested,
            'bytes' => $bytes,
        );
    }




    private function get_runtime_js_scan_request_data($allow_preverified = true)
    {
        if (!empty($allow_preverified) && isset($GLOBALS['ultracache_runtime_js_scan_request_data']) && is_array($GLOBALS['ultracache_runtime_js_scan_request_data'])) {
            return $GLOBALS['ultracache_runtime_js_scan_request_data'];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only scanner markers; the short-lived scan token is verified before collector activation.
        if (is_admin() || empty($_GET['ultracache_runtime_js_scan']) || empty($_GET['ultracache_runtime_js_scan_id']) || empty($_GET['ultracache_runtime_js_scan_token'])) {
            return false;
        }

        if ((function_exists('is_user_logged_in') && is_user_logged_in()) || (function_exists('ultracache_runtime_js_scan_has_auth_cookie') && ultracache_runtime_js_scan_has_auth_cookie())) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Authorization is provided by the bound short-lived scan token below.
        $scan_id = sanitize_key(wp_unslash($_GET['ultracache_runtime_js_scan_id']));
        if ('' === $scan_id || strlen($scan_id) > 64) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This value is the scanner authorization token and is verified immediately below.
        $token = sanitize_text_field(wp_unslash($_GET['ultracache_runtime_js_scan_token']));
        $request_url = function_exists('ultracache_runtime_js_scan_current_request_url') ? ultracache_runtime_js_scan_current_request_url() : '';
        if ('' === $request_url || !function_exists('ultracache_runtime_js_scan_verify_token') || !ultracache_runtime_js_scan_verify_token($token, $scan_id, $request_url)) {
            return false;
        }

        $data = array(
            'scan_id'      => $scan_id,
            'scan_context' => 'anonymous',
        );
        $GLOBALS['ultracache_runtime_js_scan_request_data'] = $data;

        return $data;
    }



    public function is_runtime_js_scan_request()
    {
        return false !== $this->get_runtime_js_scan_request_data();
    }



    private function get_wp_inline_dependency_protected_script_groups(array $records)
    {
        $inline_groups = array();
        $protected = array();
        $ordered_indexes = array_keys($records);
        sort($ordered_indexes);

        foreach ($records as $index => $record) {
            if (!empty($record['has_src'])) {
                continue;
            }
            $group = isset($record['group']) ? (string) $record['group'] : '';
            if ('' === $group) {
                continue;
            }
            if (!$this->is_wp_inline_dependency_script_record($record)) {
                continue;
            }
            $inline_groups[$group] = true;
        }

        foreach ($records as $index => $record) {
            if (empty($record['has_src'])) {
                continue;
            }
            $group = isset($record['group']) ? (string) $record['group'] : '';
            if ('' !== $group && !empty($inline_groups[$group])) {
                $protected[$group] = true;
                continue;
            }

            $nearby = $this->get_nearby_inline_dependency_groups($records, $ordered_indexes, (int) $index);
            foreach ($nearby as $nearby_group) {
                if ('' !== $nearby_group) {
                    if ('' !== $group) {
                        $protected[$group] = true;
                    }
                    $protected[$nearby_group] = true;
                }
            }
        }

        return $protected;
    }



    private function get_nearby_script_dependency_groups(array $records, array $ordered_indexes, $index, $radius = 2)
    {
        $groups = array();
        $position = array_search($index, $ordered_indexes, true);
        if (false === $position) {
            return $groups;
        }

        $radius = max(1, (int) $radius);
        for ($distance = 1; $distance <= $radius; $distance++) {
            foreach (array($position - $distance, $position + $distance) as $near_position) {
                if (!isset($ordered_indexes[$near_position])) {
                    continue;
                }
                $near_index = $ordered_indexes[$near_position];
                if (!isset($records[$near_index])) {
                    continue;
                }
                $near_group = isset($records[$near_index]['group']) ? (string) $records[$near_index]['group'] : '';
                if ('' !== $near_group) {
                    $groups[$near_group] = $near_group;
                }
            }
        }

        return array_values($groups);
    }



    private function get_nearby_inline_dependency_groups(array $records, array $ordered_indexes, $index)
    {
        $groups = array();
        $position = array_search($index, $ordered_indexes, true);
        if (false === $position) {
            return $groups;
        }

        foreach (array($position - 1, $position + 1) as $near_position) {
            if (!isset($ordered_indexes[$near_position])) {
                continue;
            }
            $near_index = $ordered_indexes[$near_position];
            if (!isset($records[$near_index]) || !empty($records[$near_index]['has_src'])) {
                continue;
            }
            if (!$this->is_wp_inline_dependency_script_record($records[$near_index])) {
                continue;
            }
            $near_group = isset($records[$near_index]['group']) ? (string) $records[$near_index]['group'] : '';
            if ('' !== $near_group) {
                $groups[$near_group] = $near_group;
            }
        }

        return array_values($groups);
    }



    private function is_wp_inline_dependency_script_record(array $record)
    {
        unset($record);
        /*
         * Do not silently protect WordPress/theme/plugin inline blocks.
         * Browser Scanner and Console Error Handler should discover actual
         * breakage and propose visible safeguards. UltraCache-owned helper
         * records are protected separately by is_ultracache_frontend_js_helper_record().
         */
        return false;
    }




private function script_handle_has_inline_before_segments($handle)
    {
        return $this->script_handle_has_wp_script_data_segment($handle, 'before');
    }



    private function script_handle_has_inline_after_segments($handle)
    {
        return $this->script_handle_has_wp_script_data_segment($handle, 'after');
    }



    private function script_handle_has_inline_extra_segments($handle)
    {
        return $this->script_handle_has_wp_script_data_segment($handle, 'data') || $this->script_handle_has_wp_script_data_segment($handle, 'translations');
    }



    private function script_handle_has_wp_inline_companion_segments($handle)
    {
        return $this->script_handle_has_inline_before_segments($handle) || $this->script_handle_has_inline_after_segments($handle) || $this->script_handle_has_inline_extra_segments($handle);
    }



    private function script_handle_has_wp_script_data_segment($handle, $key)
    {
        $handle = (string) $handle;
        $key = (string) $key;
        if ('' === $handle || '' === $key) {
            return false;
        }

        global $wp_scripts;
        if (!($wp_scripts instanceof WP_Scripts)) {
            return false;
        }

        $segment = $wp_scripts->get_data($handle, $key);
        if (is_array($segment)) {
            return !empty($segment);
        }

        return is_string($segment) && '' !== trim($segment);
    }



    private function script_handle_has_enqueued_dependents($handle)
    {
        $handle = sanitize_key((string) $handle);
        if ('' === $handle) {
            return false;
        }

        global $wp_scripts;
        if (!($wp_scripts instanceof WP_Scripts)) {
            return false;
        }

        $candidates = array();
        foreach (array('queue', 'to_do', 'done') as $property) {
            if (isset($wp_scripts->{$property}) && is_array($wp_scripts->{$property})) {
                $candidates = array_merge($candidates, $wp_scripts->{$property});
            }
        }

        foreach (array_unique(array_filter(array_map('sanitize_key', array_map('strval', $candidates)))) as $candidate) {
            if ($candidate === $handle || empty($wp_scripts->registered[$candidate]) || empty($wp_scripts->registered[$candidate]->deps)) {
                continue;
            }

            $deps = array_values(array_filter(array_map('sanitize_key', array_map('strval', (array) $wp_scripts->registered[$candidate]->deps))));
            if (in_array($handle, $deps, true)) {
                return true;
            }
        }

        return false;
    }



    private function script_handle_has_enqueued_dependencies($handle)
    {
        $handle = sanitize_key((string) $handle);
        if ('' === $handle) {
            return false;
        }

        global $wp_scripts;
        if (!($wp_scripts instanceof WP_Scripts) || empty($wp_scripts->registered[$handle]) || !is_object($wp_scripts->registered[$handle])) {
            return false;
        }

        foreach ((array) ($wp_scripts->registered[$handle]->deps ?? array()) as $dependency) {
            if ('' !== sanitize_key((string) $dependency)) {
                return true;
            }
        }

        return false;
    }



    private function script_handle_has_active_dependency_edges($handle)
    {
        return $this->script_handle_has_enqueued_dependencies($handle) || $this->script_handle_has_enqueued_dependents($handle);
    }



    private function is_third_party_script_src($src)
    {
        $src = trim((string) $src);
        if ('' === $src) {
            return false;
        }

        $src_host = (string) wp_parse_url($src, PHP_URL_HOST);
        if ('' === $src_host) {
            return false;
        }

        if (function_exists('ultracache_is_trusted_public_host')) {
            return !ultracache_is_trusted_public_host($src_host);
        }

        $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        return '' !== $home_host && strtolower($src_host) !== strtolower($home_host);
    }


    /**
     * Return whether a plugin-owned frontend helper is safe to execute with
     * native defer instead of blocking the HTML parser.
     *
     * Keep this whitelist deliberately narrow. Helpers that install interception
     * hooks (Delay JS early interactions, MailerLite fetch, dynamic font/CSS,
     * runtime scan, cart fragments, request-credentials learning) must remain
     * parser-early. The full delayed loader can defer because the tiny dedicated
     * interaction bootstrap captures eligible visitor input until it initializes.
     *
     * @param string $handle Script handle.
     * @return bool
     */
    private function should_native_defer_ultracache_frontend_js_helper_handle($handle)
    {
        $module = $this->ultracache_get_frontend_runtime_module($handle);
        return !empty($module) && 'defer' === (string) ($module['lane'] ?? '');
    }

    private function is_ultracache_frontend_js_helper_handle($handle)
    {
        return !empty($this->ultracache_get_frontend_runtime_module($handle));
    }


    private function is_ultracache_frontend_js_helper_record(array $record)
    {
        foreach (array('handle', 'id', 'group') as $key) {
            $candidate = isset($record[$key]) ? (string) $record[$key] : '';
            if ('' !== $candidate && !empty($this->ultracache_get_frontend_runtime_module($candidate))) {
                return true;
            }
        }

        $src = isset($record['src']) ? (string) $record['src'] : '';
        return '' !== $src && !empty($this->ultracache_get_frontend_runtime_module_by_src($src));
    }



    private function is_same_host_public_url($url)
    {
        $url = trim((string) $url);
        if ('' === $url) {
            return false;
        }

        $absolute = $this->absolutize_public_resource_url($url, home_url('/'));
        if ('' === $absolute) {
            return false;
        }

        $src_host = (string) wp_parse_url($absolute, PHP_URL_HOST);
        if ('' === $src_host) {
            return false;
        }

        if (function_exists('ultracache_is_public_site_url')) {
            return ultracache_is_public_site_url($absolute);
        }

        $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        return '' !== $home_host && strtolower($src_host) === strtolower($home_host);
    }




private function script_handle_is_footer_group($handle)
    {
        $handle = (string) $handle;
        if ('' === $handle) {
            return false;
        }

        global $wp_scripts;
        if (!($wp_scripts instanceof WP_Scripts)) {
            return false;
        }

        $group = $wp_scripts->get_data($handle, 'group');
        return (is_numeric($group) && 1 <= (int) $group);
    }



    private function is_local_wp_content_script_url($src)
    {
        $path = strtolower((string) wp_parse_url((string) $src, PHP_URL_PATH));
        if ('' === $path) {
            $path = strtolower((string) $src);
        }

        $markers = array();
        if (function_exists('ultracache_plugins_public_path')) {
            $markers[] = ultracache_plugins_public_path();
        }
        if (function_exists('ultracache_themes_public_paths')) {
            $markers = array_merge($markers, ultracache_themes_public_paths());
        }
        if (function_exists('ultracache_uploads_public_path')) {
            $markers[] = ultracache_uploads_public_path();
        }

        return function_exists('ultracache_public_path_contains_any') && ultracache_public_path_contains_any($path, $markers);
    }



    private function is_external_third_party_script_url($src)
    {
        $src = trim((string) $src);
        if ('' === $src) {
            return false;
        }

        $absolute = $this->absolutize_public_resource_url($src, home_url('/'));
        if ('' === $absolute) {
            $absolute = $src;
        }

        $src_host = strtolower((string) wp_parse_url((string) $absolute, PHP_URL_HOST));
        if ('' === $src_host) {
            return false;
        }

        if (function_exists('ultracache_is_public_site_url')) {
            return !ultracache_is_public_site_url($absolute);
        }

        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        return '' !== $home_host && $src_host !== $home_host;
    }



    public function cleanup_asset_chain_enqueue_assets()
    {
        if (is_admin() || (function_exists('ultracache_should_bypass_logged_in_frontend_optimizations') && ultracache_should_bypass_logged_in_frontend_optimizations())) {
            return;
        }

        $settings = $this->get_settings();
        if (empty($settings['asset_chain_cleanup'])) {
            return;
        }

        if ($this->current_request_matches_asset_cleanup_exclusion($settings)) {
            return;
        }

        if (!empty($settings['asset_cleanup_woo_product_assets']) && !$this->is_runtime_single_product_context()) {
            $this->dequeue_matching_queued_assets('script', $this->get_woocommerce_product_asset_cleanup_fragments());
            $this->dequeue_matching_queued_assets('style', $this->get_woocommerce_product_asset_cleanup_fragments());
        }

        if (!empty($settings['asset_cleanup_product_filter_assets']) && !$this->is_runtime_product_filter_context()) {
            $this->dequeue_matching_queued_assets('script', $this->get_product_filter_asset_cleanup_fragments());
            $this->dequeue_matching_queued_assets('style', $this->get_product_filter_asset_cleanup_fragments());
        }

        if (!empty($settings['asset_cleanup_woo_blocks_css']) && !$this->is_runtime_woocommerce_context()) {
            $this->dequeue_matching_queued_assets('style', array('wc-blocks.css', 'wc-blocks-style', 'woocommerce-blocks'));
        }
    }



    private function dequeue_matching_queued_assets($type, array $fragments)
    {
        $type = ('style' === $type) ? 'style' : 'script';
        $registry = ('style' === $type) ? wp_styles() : wp_scripts();
        if (!$registry || empty($registry->queue) || !is_array($registry->queue)) {
            return;
        }

        foreach ((array) $registry->queue as $handle) {
            $src = '';
            if (isset($registry->registered[$handle]) && is_object($registry->registered[$handle])) {
                $src = (string) ($registry->registered[$handle]->src ?? '');
            }

            if (!$this->asset_matches_fragment_list($handle, $src, $fragments)) {
                continue;
            }

            if ('style' === $type) {
                wp_dequeue_style($handle);
            } else {
                wp_dequeue_script($handle);
            }
        }
    }



    private function is_runtime_single_product_context()
    {
        return function_exists('is_product') && is_product();
    }



    private function is_runtime_woocommerce_context()
    {
        if (function_exists('is_product') && is_product()) {
            return true;
        }
        if (function_exists('is_shop') && is_shop()) {
            return true;
        }
        if (function_exists('is_product_taxonomy') && is_product_taxonomy()) {
            return true;
        }
        if (function_exists('is_cart') && is_cart()) {
            return true;
        }
        if (function_exists('is_checkout') && is_checkout()) {
            return true;
        }
        if (function_exists('is_account_page') && is_account_page()) {
            return true;
        }

        return false;
    }



    private function is_runtime_product_filter_context()
    {
        if (function_exists('is_shop') && is_shop()) {
            return true;
        }
        if (function_exists('is_product_taxonomy') && is_product_taxonomy()) {
            return true;
        }

        return false;
    }



    private function get_woocommerce_product_asset_cleanup_fragments()
    {
        return array(
            'jquery.zoom',
            'jquery.flexslider',
            'photoswipe',
            'photoswipe-ui-default',
            'wc-single-product',
            'single-product.min.js',
            'add-to-cart-variation',
            'wc-add-to-cart-variation',
            '/woocommerce/assets/js/frontend/single-product',
            '/woocommerce/assets/js/frontend/add-to-cart-variation',
            '/woocommerce/assets/js/zoom/',
            '/woocommerce/assets/js/flexslider/',
            '/woocommerce/assets/js/photoswipe/',
            '/woocommerce/assets/css/photoswipe',
        );
    }



    private function get_product_filter_asset_cleanup_fragments()
    {
        // Keep this list plugin-specific. Broad fragments such as tooltipster,
        // icheck, html_types/slider, or by_sku can also belong to unrelated UI.
        $fragments = array(
            'handle:woocommerce-products-filter',
            'handle:woof',
            'handle:woof_',
            'handle:woof-',
        );

        $plugin_paths = array(
            'woocommerce-products-filter',
            'woof-products-filter',
            'woocommerce-filter',
            'woocommerce-product-filter',
            'woocommerce-products-filter/js',
            'woocommerce-products-filter/ext',
            'woocommerce-products-filter/views',
            'woocommerce-products-filter/css',
        );
        foreach ($plugin_paths as $plugin_path) {
            $marker = function_exists('ultracache_plugins_public_path') ? ultracache_plugins_public_path($plugin_path) : '';
            if ('' !== $marker) {
                $fragments[] = 'src:' . $marker;
            }
        }

        return $fragments;
    }



    private function asset_matches_fragment_list($handle, $src, array $fragments)
    {
        $handle_lc = strtolower(trim((string) $handle));
        $src_lc = strtolower(trim((string) $src));
        $path_lc = strtolower((string) wp_parse_url((string) $src, PHP_URL_PATH));
        $haystacks = array($handle_lc, $src_lc, $path_lc);

        foreach ($fragments as $fragment) {
            $fragment = strtolower(trim((string) $fragment));
            if ('' === $fragment) {
                continue;
            }

            if (0 === strpos($fragment, 'handle:')) {
                $needle = trim(substr($fragment, 7));
                if ('' !== $needle && '' !== $handle_lc && false !== strpos($handle_lc, $needle)) {
                    return true;
                }
                continue;
            }

            if (0 === strpos($fragment, 'src:')) {
                $needle = trim(substr($fragment, 4));
                if ('' !== $needle && (('' !== $src_lc && false !== strpos($src_lc, $needle)) || ('' !== $path_lc && false !== strpos($path_lc, $needle)))) {
                    return true;
                }
                continue;
            }

            foreach ($haystacks as $haystack) {
                if ('' !== $haystack && false !== strpos($haystack, $fragment)) {
                    return true;
                }
            }
        }

        return false;
    }

}
