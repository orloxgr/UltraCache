<?php
/**
 * Font resource hints, enqueues, and preload output helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Font_Preload_Trait
{

    public function add_display_swap_to_google_fonts($src, $handle)
    {
        /*
         * The style_loader_src filter also runs in wp-admin and during some
         * plugin maintenance requests. Keep the Google Fonts localization
         * workflow frontend-only so admin CSS is never rewritten or delayed.
         */
        if (function_exists('is_admin') && is_admin()) {
            return $src;
        }

        $settings = $this->get_settings();
        $font_url = $this->append_google_fonts_display_swap($src);

        if (!empty($settings['google_fonts_local_optimization'])) {
            $localized = $this->get_google_fonts_url_for_current_request($font_url, true);
            if (is_string($localized) && '' !== $localized) {
                return $localized;
            }
        }

        if (!empty($settings['google_fonts_swap'])) {
            return $font_url;
        }

        return $src;
    }


    public function filter_google_fonts_resource_hints($urls, $relation_type)
    {
        if (!is_array($urls)) {
            return $urls;
        }

        $relation_type = strtolower((string) $relation_type);
        if (!in_array($relation_type, array('dns-prefetch', 'preconnect'), true)) {
            return $urls;
        }

        if (function_exists('is_admin') && is_admin()) {
            return $urls;
        }

        $settings = $this->get_settings();
        if (empty($settings['google_fonts_local_optimization'])) {
            return $urls;
        }

        $filtered = array();
        foreach ($urls as $key => $value) {
            if ($this->is_google_fonts_resource_hint_value($value)) {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }


    private function is_google_fonts_resource_hint_value($value)
    {
        if (is_string($value)) {
            return $this->is_google_fonts_resource_hint_url($value);
        }

        if (is_array($value)) {
            foreach (array('href', 'url', 0) as $key) {
                if (isset($value[$key]) && is_string($value[$key]) && $this->is_google_fonts_resource_hint_url($value[$key])) {
                    return true;
                }
            }
        }

        return false;
    }


    private function is_google_fonts_resource_hint_url($url)
    {
        $url = $this->decode_google_fonts_html_url((string) $url);
        if ('' === $url) {
            return false;
        }

        if (0 === strpos($url, '//')) {
            $url = 'https:' . $url;
        }

        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ('' === $host) {
            $fallback_host = ltrim($url, '/');
            $cut_at = strlen($fallback_host);

            foreach (array('/', '?', '#') as $separator) {
                $position = strpos($fallback_host, $separator);
                if (false !== $position && $position < $cut_at) {
                    $cut_at = $position;
                }
            }

            $host = strtolower(trim(substr($fallback_host, 0, $cut_at)));
        }

        return in_array($host, array('fonts.googleapis.com', 'fonts.gstatic.com'), true);
    }

    public function enqueue_runtime_font_helpers()
    {
        if (is_admin()) {
            return;
        }

        $settings = $this->get_settings();
        $policy = $this->get_font_optimization_policy($settings);

        if (!empty($policy['delay_icon_fonts'])) {
            $patterns = isset($settings['delay_icon_fonts_list']) && is_array($settings['delay_icon_fonts_list'])
                ? array_values(array_filter(array_map('sanitize_text_field', $settings['delay_icon_fonts_list'])))
                : array();
            $exclude_patterns = isset($settings['delay_icon_fonts_exclude_list']) && is_array($settings['delay_icon_fonts_exclude_list'])
                ? array_values(array_filter(array_map('sanitize_text_field', $settings['delay_icon_fonts_exclude_list'])))
                : array();

            if (!empty($patterns)) {
                $dynamic_icon_handle = 'ultracache-dynamic-icon-font-delay';
                if ($this->ultracache_enqueue_frontend_js_helper($dynamic_icon_handle, 'dynamic-icon-font-delay.js', array(), false)) {
                    $this->ultracache_add_frontend_js_helper_data($dynamic_icon_handle, 'ultracacheDynamicIconFontDelayConfig', array(
                        'patterns'        => $patterns,
                        'excludePatterns' => $exclude_patterns,
                    ));
                }
            }
        }

        if (empty($policy['runtime_rewrite'])) {
            return;
        }

        $map_data = $this->get_runtime_font_css_url_map_enqueue_data();

        $map_handle = 'ultracache-runtime-font-css-map';
        if ($this->ultracache_enqueue_frontend_js_helper($map_handle, 'runtime-font-css-map.js', array(), false)) {
            $this->ultracache_add_frontend_js_helper_data($map_handle, 'ultracacheRuntimeFontCssMapConfig', array(
                'map'    => isset($map_data['map']) && is_array($map_data['map']) ? $map_data['map'] : array(),
                'count'  => isset($map_data['count']) ? max(0, (int) $map_data['count']) : 0,
                'source' => isset($map_data['source']) ? sanitize_text_field((string) $map_data['source']) : 'empty',
            ));
        }

        $cssom_handle = 'ultracache-font-display-cssom-patch';
        $this->ultracache_enqueue_frontend_js_helper($cssom_handle, 'font-display-cssom-patch.js', array(), false);
    }

    private function get_runtime_font_css_url_map_enqueue_data()
    {
        $map_sources = array();
        $map = $this->get_runtime_local_font_css_url_map();
        if (!empty($map) && is_array($map)) {
            $map_sources[] = 'cache';
        }

        if (!empty($this->runtime_font_css_url_map_current_request) && is_array($this->runtime_font_css_url_map_current_request)) {
            $map = array_merge(is_array($map) ? $map : array(), $this->runtime_font_css_url_map_current_request);
            $map_sources[] = 'current-request';
        }

        $bundle_map = $this->build_runtime_font_css_url_map_from_bundle_manifest($this->get_current_request_url());
        if (!empty($bundle_map)) {
            $this->remember_runtime_font_css_url_mappings($bundle_map);
            $map = array_merge(is_array($map) ? $map : array(), $bundle_map);
            $map_sources[] = 'bundle-manifest';
        }

        $map = $this->normalize_runtime_font_css_url_map(is_array($map) ? $map : array());
        $filtered_map = $this->filter_runtime_font_css_url_map_to_existing_targets($map);
        if ($filtered_map !== $map) {
            $map_sources[] = 'stale-pruned';
        }
        $map = $filtered_map;
        $this->save_runtime_local_font_css_url_map($map);

        $source_label = implode(',', array_values(array_unique(array_filter($map_sources))));
        if ('' === $source_label) {
            $source_label = 'empty';
        }

        return array(
            'map'    => $map,
            'count'  => count($map),
            'source' => $source_label,
        );
    }

    public function enqueue_local_font_display_patch_stylesheet()
    {
        if (function_exists('is_admin') && is_admin()) {
            return;
        }

        $settings = $this->get_settings();
        if (empty($settings['google_fonts_swap'])) {
            return;
        }

        $source_urls = $this->collect_enqueued_local_stylesheet_urls_for_font_display_patch();
        if (empty($source_urls)) {
            return;
        }

        $asset = $this->build_combined_local_font_display_patch_asset($source_urls);
        if (empty($asset['css_url'])) {
            return;
        }

        $href = esc_url_raw((string) $asset['css_url']);
        if ('' === $href) {
            return;
        }

        wp_register_style(
            'ultracache-font-display-patch',
            $href,
            array(),
            defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : null
        );
        wp_enqueue_style('ultracache-font-display-patch');
    }

    public function add_local_font_display_patch_style_attributes($html, $handle, $href, $media)
    {
        if ('ultracache-font-display-patch' !== (string) $handle || '' === (string) $html) {
            return $html;
        }

        if ($this->html_tag_processor_available()) {
            try {
                $processor = new WP_HTML_Tag_Processor((string) $html);
                if ($processor->next_tag('LINK')) {
                    $processor->set_attribute('data-ultracache-font-display-patch', '1');
                    $processor->set_attribute('data-ultracache-css-role', 'font-display-patch');
                    $updated = $processor->get_updated_html();
                    return is_string($updated) && '' !== $updated ? $updated : $html;
                }
            } catch (\Throwable $e) {
                return $html;
            }
        }

        // Supported WordPress versions provide WP_HTML_Tag_Processor. If it is unavailable or cannot process the tag, keep the original WordPress-generated markup instead of using a raw regex fallback.
        return $html;
    }


    private function extract_preloadable_font_urls_from_css($css, $limit = 2)
    {
        $urls = array();
        if (preg_match_all('/url\(([^)]+\.woff2(?:\?[^)]*)?)\)/i', (string) $css, $matches)) {
            foreach ($matches[1] as $raw) {
                $raw = trim((string) $raw, " \t\n\r\0\x0B\"'");
                $raw = esc_url_raw($raw);
                if ('' === $raw || in_array($raw, $urls, true)) {
                    continue;
                }

                $urls[] = $raw;
                if (count($urls) >= max(1, (int) $limit)) {
                    break;
                }
            }
        }

        return $urls;
    }


    private function inject_font_preload_links($html, array $urls)
    {
        if (false === stripos($html, '</head>')) {
            return $html;
        }

        $links = array();
        foreach ($urls as $url) {
            $url = esc_url($url);
            if ('' === $url) {
                continue;
            }

            // Intentional final HTML optimization output: font preloads are added to the rendered document head after font discovery/localization.
            // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
            $link = '<link rel="preload" as="font" type="font/woff2" href="' . $url . '" crossorigin />';
            if (false === strpos($html, $link)) {
                $links[] = $link;
            }
        }

        if (empty($links)) {
            return $html;
        }

        return $this->insert_html_before_closing_head($html, implode("
", $links));
    }


    private function inject_delayed_font_css_links($html, array $assets, $id_prefix = 'ultracache-delayed-font-css')
    {
        // Delayed icon-font stylesheets are emitted only through wp_enqueue_style()
        // in enqueue_delayed_icon_font_stylesheets(). If a delayed font asset is
        // discovered too late in the final HTML buffer, keep the original stylesheet
        // active for this request instead of injecting a raw stylesheet link.
        return $html;
    }
}
