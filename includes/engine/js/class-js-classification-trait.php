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




    public function maybe_apply_runtime_js_scan_anonymous_context()
    {
        $data = $this->get_runtime_js_scan_request_data(false);
        if (false === $data || empty($data['anonymous_context'])) {
            return;
        }

        $GLOBALS['ultracache_runtime_js_scan_request_data'] = $data;
        if (function_exists('wp_set_current_user')) {
            wp_set_current_user(0);
        }
        add_filter('show_admin_bar', '__return_false', PHP_INT_MAX);
    }




    private function get_runtime_js_scan_request_data($allow_preverified = true)
    {
        if (!empty($allow_preverified) && isset($GLOBALS['ultracache_runtime_js_scan_request_data']) && is_array($GLOBALS['ultracache_runtime_js_scan_request_data'])) {
            return $GLOBALS['ultracache_runtime_js_scan_request_data'];
        }

        if (is_admin() || empty($_GET['ultracache_runtime_js_scan']) || empty($_GET['ultracache_runtime_js_scan_id']) || empty($_GET['ultracache_runtime_js_scan_nonce'])) {
            return false;
        }

        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return false;
        }

        $nonce = sanitize_text_field(wp_unslash($_GET['ultracache_runtime_js_scan_nonce']));
        if (!wp_verify_nonce($nonce, 'ultracache_runtime_js_scan')) {
            return false;
        }

        $scan_id = sanitize_key(wp_unslash($_GET['ultracache_runtime_js_scan_id']));
        if ('' === $scan_id || strlen($scan_id) > 64) {
            return false;
        }

        $context = isset($_GET['ultracache_runtime_js_scan_context']) ? sanitize_key(wp_unslash($_GET['ultracache_runtime_js_scan_context'])) : 'anonymous';
        $context = 'logged-in' === $context ? 'logged-in' : 'anonymous';

        return array(
            'scan_id'           => $scan_id,
            'endpoint'          => esc_url_raw(rest_url('ultracache/v1/runtime-js-scan/report')),
            'rest_nonce'        => wp_create_nonce('wp_rest'),
            'scan_context'      => $context,
            'anonymous_context' => 'anonymous' === $context,
        );
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
        $handle = (string) $handle;
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

        foreach (array_unique(array_filter(array_map('strval', $candidates))) as $candidate) {
            if ($candidate === $handle || empty($wp_scripts->registered[$candidate]) || empty($wp_scripts->registered[$candidate]->deps)) {
                continue;
            }

            if (in_array($handle, array_map('strval', (array) $wp_scripts->registered[$candidate]->deps), true)) {
                return true;
            }
        }

        return false;
    }



    private function is_third_party_script_src($src)
    {
        $src = trim((string) $src);
        if ('' === $src) {
            return false;
        }

        $src_host = (string) wp_parse_url($src, PHP_URL_HOST);
        $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);

        return '' !== $src_host && '' !== $home_host && strtolower($src_host) !== strtolower($home_host);
    }


    private function is_ultracache_frontend_js_helper_handle($handle)
    {
        $handle = $this->normalize_delayed_script_group_handle($handle);
        if ('' === $handle) {
            return false;
        }

        return in_array($handle, array(
            'ultracache-mailerlite-lazy-nonce',
            'ultracache-async-css-runtime',
            'ultracache-runtime-js-scan-collector',
            'ultracache-delayed-js-loader',
            'ultracache-runtime-font-css-map',
            'ultracache-dynamic-icon-font-delay',
            'ultracache-font-display-cssom-patch',
            'ultracache-woocommerce-cart-fragments-delay',
            'ultracache-woocommerce-esi-optin',
            'ultracache-lcp-observer',
        ), true);
    }


    private function is_ultracache_frontend_js_helper_record(array $record)
    {
        $candidates = array(
            isset($record['handle']) ? (string) $record['handle'] : '',
            isset($record['id']) ? (string) $record['id'] : '',
            isset($record['group']) ? (string) $record['group'] : '',
        );

        foreach ($candidates as $candidate) {
            if ($this->is_ultracache_frontend_js_helper_handle($candidate)) {
                return true;
            }
        }

        $src = isset($record['src']) ? (string) $record['src'] : '';
        if ('' !== $src && false !== strpos($src, '/ultracache/assets/js/')) {
            return (false !== strpos($src, '/mailerlite-lazy-nonce.js') || false !== strpos($src, '/async-css-runtime.js') || false !== strpos($src, '/runtime-js-scan-collector.js') || false !== strpos($src, '/delayed-js-loader.js') || false !== strpos($src, '/runtime-font-css-map.js') || false !== strpos($src, '/dynamic-icon-font-delay.js') || false !== strpos($src, '/font-display-cssom-patch.js') || false !== strpos($src, '/woocommerce-cart-fragments-delay.js') || false !== strpos($src, '/woocommerce-esi-optin.js') || false !== strpos($src, '/lcp-observer.js'));
        }

        return false;
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
        $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        if ('' === $src_host || '' === $home_host) {
            return false;
        }

        return strtolower($src_host) === strtolower($home_host);
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
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ('' === $src_host || '' === $home_host) {
            return false;
        }

        return $src_host !== $home_host;
    }



    public function cleanup_asset_chain_enqueue_assets()
    {
        if (is_admin()) {
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
