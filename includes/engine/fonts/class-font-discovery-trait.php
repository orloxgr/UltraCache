<?php
/**
 * Font discovery and source inventory helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Font_Discovery_Trait
{
    private function get_font_optimization_policy(array $settings = array())
    {
        if (empty($settings)) {
            $settings = $this->get_settings();
        }
        if (!is_array($settings)) {
            $settings = array();
        }

        $font_display = !empty($settings['google_fonts_swap']);
        $google_fonts_local = !empty($settings['google_fonts_local_optimization']);
        $self_hosted_css = !empty($settings['self_hosted_font_css_optimization']);
        $delay_icon_fonts = !empty($settings['delay_icon_fonts']);
        $runtime_rewrite = !empty($settings['self_hosted_font_runtime_rewrite']);
        $local_font_css_rewrite = $font_display || $self_hosted_css || $delay_icon_fonts;

        return array(
            'font_display' => $font_display,
            'google_fonts_local' => $google_fonts_local,
            'self_hosted_css' => $self_hosted_css,
            'delay_icon_fonts' => $delay_icon_fonts,
            'runtime_rewrite' => $runtime_rewrite,
            'local_font_css_rewrite' => $local_font_css_rewrite,
            'font_css_links' => $local_font_css_rewrite || $google_fonts_local,
            'bundle_font_display' => $local_font_css_rewrite,
            'delayed_icon_fonts_output' => $delay_icon_fonts,
        );
    }


    private function get_google_fonts_scan_page_urls(array $extra_urls = array())
    {
        $urls = array();
        $home = $this->normalize_google_fonts_scan_page_url(home_url('/'));
        if ('' !== $home) {
            $urls[$home] = $home;
        }

        $settings = $this->get_settings();
        $configured = array();
        if (!empty($settings['google_fonts_additional_scan_urls']) && is_array($settings['google_fonts_additional_scan_urls'])) {
            $configured = $settings['google_fonts_additional_scan_urls'];
        }

        foreach (array_merge($configured, $extra_urls) as $url) {
            $normalized = $this->normalize_google_fonts_scan_page_url($url);
            if ('' !== $normalized) {
                $urls[$normalized] = $normalized;
            }
        }

        return array_values($urls);
    }


    private function normalize_google_fonts_scan_page_url($url)
    {
        $url = trim((string) $url);
        if ('' === $url) {
            return '';
        }

        if (0 === strpos($url, '//')) {
            $url = (is_ssl() ? 'https:' : 'https:') . $url;
        } elseif (0 === strpos($url, '/')) {
            $url = home_url($url);
        }

        $url = esc_url_raw($url);
        if ('' === $url) {
            return '';
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, array('http', 'https'), true)) {
            return '';
        }

        $url_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ('' === $url_host) {
            return '';
        }
        if (function_exists('ultracache_is_local_site_url')) {
            if (!ultracache_is_local_site_url($url)) {
                return '';
            }
        } else {
            $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
            if ('' === $home_host || $home_host !== $url_host) {
                return '';
            }
        }

        $fragment_pos = strpos($url, '#');
        if (false !== $fragment_pos) {
            $url = substr($url, 0, $fragment_pos);
        }

        return esc_url_raw($url);
    }


    private function fetch_google_fonts_scan_html($url)
    {
        $url = $this->normalize_google_fonts_scan_page_url($url);
        if ('' === $url) {
            return '';
        }

        $scan_url = add_query_arg('ultracache_google_fonts_scan', '1', $url);
        $response = ultracache_safe_loopback_remote_request(
            $scan_url,
            array(
                'timeout' => 20,
                'redirection' => 3,
                'user-agent' => 'UltraCache-GoogleFontsScanner/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : '1.0') . '; ' . home_url('/'),
                'headers' => array(
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Cache-Control' => 'no-cache',
                ),
            )
        );

        if (is_wp_error($response)) {
            return '';
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) {
            return '';
        }

        $body = wp_remote_retrieve_body($response);
        return is_string($body) ? $body : '';
    }


    private function extract_google_fonts_stylesheet_urls_from_html($html)
    {
        $html = (string) $html;
        if ('' === $html || false === stripos($html, 'fonts.googleapis.com')) {
            return array();
        }

        $urls = array();
        $patterns = array(
            "#(?<![A-Za-z0-9_:])(?:https?:)?//fonts\\.googleapis\\.com/(?:css2?|icon)\\?[^\"'\\s<>]+#i",
            "#https?:\\\\/\\\\/fonts\\.googleapis\\.com\\\\/(?:css2?|icon)\\\\?[^\"'\\s<>]+#i",
        );

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches) && !empty($matches[0])) {
                foreach ($matches[0] as $url) {
                    $url = $this->append_google_fonts_display_swap($this->decode_google_fonts_html_url((string) $url));
                    if ($this->is_google_fonts_stylesheet_url($url)) {
                        $urls[md5($url)] = esc_url_raw($url);
                    }
                }
            }
        }

        return array_values($urls);
    }


    private function extract_same_origin_stylesheet_urls_from_html($html, $base_url = '')
    {
        $html = (string) $html;
        if ('' === $html || false === stripos($html, '<link')) {
            return array();
        }

        $base_url = '' !== (string) $base_url ? (string) $base_url : home_url('/');
        $urls = array();
        foreach ($this->collect_stylesheet_link_attributes_from_html($html) as $link) {
            $href = isset($link['href']) ? (string) $link['href'] : '';
            if ('' === $href || false !== stripos($href, 'fonts.googleapis.com')) {
                continue;
            }

            $absolute = $this->absolutize_public_resource_url($href, $base_url);
            $normalized = $this->normalize_public_resource_url($absolute);
            if ('' === $normalized || !$this->is_cacheable_local_url($normalized)) {
                continue;
            }

            $path = strtolower((string) wp_parse_url($normalized, PHP_URL_PATH));
            if (false === strpos($path, '.css')) {
                continue;
            }

            if (ultracache_internal_cache_local_path_matches($path) || ultracache_generated_asset_local_path_matches($path)) {
                continue;
            }

            $urls[$normalized] = $normalized;
        }

        return array_values($urls);
    }


    private function collect_stylesheet_link_attributes_from_html($html)
    {
        $html = (string) $html;
        if ('' === $html || false === stripos($html, '<link') || !$this->html_tag_processor_available()) {
            return array();
        }

        $links = array();
        try {
            $processor = new WP_HTML_Tag_Processor($html);
            while ($processor->next_tag('LINK')) {
                if (!$this->html_rel_attribute_contains_stylesheet($processor->get_attribute('rel'))) {
                    continue;
                }

                $href = $processor->get_attribute('href');
                if (!is_string($href) || '' === $href) {
                    continue;
                }

                $links[] = array(
                    'href' => html_entity_decode($href, ENT_QUOTES | ENT_HTML5),
                    'data_ultracache_font_display_patch' => null !== $processor->get_attribute('data-ultracache-font-display-patch'),
                    'data_ultracache_delayed_icon_fonts' => null !== $processor->get_attribute('data-ultracache-delayed-icon-fonts'),
                );
            }
        } catch (\Throwable $e) {
            return array();
        }

        return $links;
    }


    private function fetch_google_fonts_scan_css($url)
    {
        $url = $this->normalize_public_resource_url((string) $url);
        if ('' === $url || !$this->is_cacheable_local_url($url)) {
            return '';
        }

        $response = ultracache_safe_loopback_remote_request(
            $url,
            array(
                'timeout' => 12,
                'redirection' => 3,
                'user-agent' => 'UltraCache-GoogleFontsCssScanner/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : '1.0') . '; ' . home_url('/'),
                'headers' => array(
                    'Accept' => 'text/css,*/*;q=0.1',
                    'Cache-Control' => 'no-cache',
                ),
            )
        );

        if (is_wp_error($response)) {
            return '';
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) {
            return '';
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || '' === $body || false === stripos($body, 'fonts.googleapis.com')) {
            return '';
        }

        return $body;
    }


    private function extract_google_fonts_stylesheet_urls_from_css($css, $css_url)
    {
        $css = (string) $css;
        if ('' === $css || false === stripos($css, 'fonts.googleapis.com')) {
            return array();
        }

        $urls = array();
        $pattern = '/@import\s+(?:url\(\s*"([^"]+)"\s*\)|url\(\s*\'([^\']+)\'\s*\)|url\(\s*([^)]+?)\s*\)|"([^"]+)"|\'([^\']+)\')([^;]*);/i';
        if (!preg_match_all($pattern, $css, $matches, PREG_SET_ORDER)) {
            return array();
        }

        foreach ($matches as $match) {
            $import_url = '';
            for ($i = 1; $i <= 5; $i++) {
                if (isset($match[$i]) && '' !== trim((string) $match[$i])) {
                    $import_url = trim((string) $match[$i]);
                    break;
                }
            }

            if ('' === $import_url) {
                continue;
            }

            $absolute = $this->absolutize_public_resource_url($this->decode_google_fonts_html_url($import_url), $css_url);
            $absolute = $this->append_google_fonts_display_swap($absolute);
            if ($this->is_google_fonts_stylesheet_url($absolute)) {
                $urls[md5($absolute)] = esc_url_raw($absolute);
            }
        }

        return array_values($urls);
    }


    private function is_google_fonts_stylesheet_url($url)
    {
        $url = $this->decode_google_fonts_html_url((string) $url);
        if (0 === strpos($url, '//')) {
            $url = 'https:' . $url;
        }
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ('' === $host || false === strpos($host, 'fonts.googleapis.com')) {
            return false;
        }

        $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
        if ('' === $path) {
            return false;
        }

        return 0 === strpos($path, '/css') || 0 === strpos($path, '/css2') || 0 === strpos($path, '/icon');
    }


    private function get_generated_font_css_asset_role(array $asset)
    {
        $css_url = isset($asset['css_url']) ? strtolower((string) $asset['css_url']) : '';
        if (!empty($asset['activeCssIsMixed']) || ultracache_generated_asset_reference_matches($css_url, array('optimized-css'))) {
            return 'optimized-css';
        }
        if (ultracache_generated_asset_reference_matches($css_url, array('font-css'))) {
            return 'font-css';
        }
        return '';
    }



private function build_linked_woff2_font_face_registry_from_html($html)
    {
        $registry = array();
        $html = (string) $html;
        // The final HTML may not contain direct .woff2 references when CSS bundling is active:
        // the matching @font-face WOFF2 declarations can live inside linked generated CSS bundles.
        // Therefore only require linked stylesheets here and inspect the stylesheet contents below.
        if ('' === $html || false === stripos($html, '<link')) {
            return $registry;
        }

        foreach ($this->collect_stylesheet_link_attributes_from_html($html) as $link) {
            $href = isset($link['href']) ? (string) $link['href'] : '';
            $stylesheet_url = $this->normalize_public_resource_url($this->absolutize_public_resource_url($href, home_url('/')));
            if ('' === $stylesheet_url) {
                continue;
            }

            $path = $this->resolve_local_path_from_public_url($stylesheet_url);
            if ('' === $path || !is_readable($path)) {
                continue;
            }

            $css = ultracache_guarded_asset_file_get_contents($path, 'font-css', 'build_linked_woff2_font_face_registry_from_html', true);
            if (!is_string($css) || '' === $css || false === stripos($css, '@font-face') || false === stripos($css, '.woff2')) {
                continue;
            }

            $font_face_scan = ultracache_css_scan_font_face_blocks($css);
            if (!empty($font_face_scan['malformed']) || empty($font_face_scan['blocks'])) {
                continue;
            }

            foreach ((array) $font_face_scan['blocks'] as $block) {
                $block = (string) $block;
                if (false === stripos($block, '.woff2')) {
                    continue;
                }

                $family_key = $this->normalize_font_face_family_key($this->extract_font_face_css_declaration($block, 'font-family'));
                if ('' === $family_key) {
                    continue;
                }

                $style_key = $this->normalize_font_face_style_key($this->extract_font_face_css_declaration($block, 'font-style'));
                $weight_range = $this->normalize_font_face_weight_range($this->extract_font_face_css_declaration($block, 'font-weight'));
                $woff2_url = $this->extract_first_font_face_woff2_url($block, $stylesheet_url);
                if ('' === $woff2_url) {
                    continue;
                }

                if (!isset($registry[$family_key])) {
                    $registry[$family_key] = array();
                }
                if (!isset($registry[$family_key][$style_key])) {
                    $registry[$family_key][$style_key] = array();
                }

                $entry_key = (string) $weight_range['min'] . '-' . (string) $weight_range['max'] . '|' . $woff2_url;
                $registry[$family_key][$style_key][$entry_key] = array(
                    'family' => $family_key,
                    'style'  => $style_key,
                    'weight' => $weight_range,
                    'url'    => $woff2_url,
                );
            }
        }

        if (false !== stripos($html, 'data-ultracache-page-css-bundle=') || false !== stripos($html, 'data-ultracache-frontpage-css=') || false !== stripos($html, 'id="ultracache-page-css-bundle"') || false !== stripos($html, "id='ultracache-page-css-bundle'") || false !== stripos($html, 'id="ultracache-frontpage-css"') || false !== stripos($html, "id='ultracache-frontpage-css'")) {
            $entry = $this->get_frontpage_css_manifest_entry();
            if (!empty($entry['sourceUrls']) && is_array($entry['sourceUrls'])) {
                foreach ((array) $entry['sourceUrls'] as $source_url) {
                    $source_url = $this->normalize_public_resource_url((string) $source_url);
                    if ('' === $source_url) {
                        continue;
                    }

                    $this->add_woff2_font_faces_from_css_url_to_registry($registry, $source_url);

                    $asset = $this->build_optimized_font_css_asset($source_url);
                    if (!empty($asset['css_url'])) {
                        $this->add_woff2_font_faces_from_css_url_to_registry($registry, (string) $asset['css_url']);
                    }
                }
            }

            $bundle_map = $this->build_runtime_font_css_url_map_from_bundle_manifest();
            if (!empty($bundle_map) && is_array($bundle_map)) {
                foreach ((array) $bundle_map as $mapped_css_url) {
                    $this->add_woff2_font_faces_from_css_url_to_registry($registry, (string) $mapped_css_url);
                }
            }
        }

        foreach ($registry as $family => $styles) {
            foreach ($styles as $style => $entries) {
                $registry[$family][$style] = array_values($entries);
            }
        }

        return $registry;
    }


    private function add_woff2_font_faces_from_css_url_to_registry(array &$registry, $stylesheet_url)
    {
        $stylesheet_url = $this->normalize_public_resource_url($this->absolutize_public_resource_url((string) $stylesheet_url, home_url('/')));
        if ('' === $stylesheet_url) {
            return 0;
        }

        $path = $this->resolve_local_path_from_public_url($stylesheet_url);
        if ('' === $path || !is_readable($path)) {
            return 0;
        }

        $css = ultracache_guarded_asset_file_get_contents($path, 'font-css', 'add_woff2_font_faces_from_css_url_to_registry', true);
        if (!is_string($css) || '' === $css || false === stripos($css, '@font-face') || false === stripos($css, '.woff2')) {
            return 0;
        }

        $font_face_scan = ultracache_css_scan_font_face_blocks($css);
        if (!empty($font_face_scan['malformed']) || empty($font_face_scan['blocks'])) {
            return 0;
        }

        $added = 0;
        foreach ((array) $font_face_scan['blocks'] as $block) {
            $block = (string) $block;
            if (false === stripos($block, '.woff2')) {
                continue;
            }

            $family_key = $this->normalize_font_face_family_key($this->extract_font_face_css_declaration($block, 'font-family'));
            if ('' === $family_key) {
                continue;
            }

            $style_key = $this->normalize_font_face_style_key($this->extract_font_face_css_declaration($block, 'font-style'));
            $weight_range = $this->normalize_font_face_weight_range($this->extract_font_face_css_declaration($block, 'font-weight'));
            $woff2_url = $this->extract_first_font_face_woff2_url($block, $stylesheet_url);
            if ('' === $woff2_url) {
                continue;
            }

            if (!isset($registry[$family_key])) {
                $registry[$family_key] = array();
            }
            if (!isset($registry[$family_key][$style_key])) {
                $registry[$family_key][$style_key] = array();
            }

            $entry_key = (string) $weight_range['min'] . '-' . (string) $weight_range['max'] . '|' . $woff2_url;
            if (empty($registry[$family_key][$style_key][$entry_key])) {
                $added++;
            }
            $registry[$family_key][$style_key][$entry_key] = array(
                'family' => $family_key,
                'style'  => $style_key,
                'weight' => $weight_range,
                'url'    => $woff2_url,
            );
        }

        return $added;
    }


    private function extract_font_face_css_declaration($block, $property)
    {
        if (function_exists('ultracache_font_css_extract_declaration')) {
            return ultracache_font_css_extract_declaration($block, $property);
        }

        $property = preg_quote((string) $property, '/');
        if (preg_match('/' . $property . '\s*:\s*([^;}]+)\s*;?/i', (string) $block, $matches)) {
            return trim((string) $matches[1]);
        }

        return '';
    }


    private function normalize_font_face_family_key($family)
    {
        $family = trim((string) $family, " \t\n\r\0\x0B\"'");
        $family = preg_replace('/\s+/', ' ', $family);
        return is_string($family) ? strtolower($family) : '';
    }


    private function normalize_font_face_style_key($style)
    {
        $style = strtolower(trim((string) $style, " \t\n\r\0\x0B\"'"));
        if ('' === $style) {
            return 'normal';
        }

        if (false !== strpos($style, 'italic')) {
            return 'italic';
        }

        if (false !== strpos($style, 'oblique')) {
            return 'oblique';
        }

        return 'normal';
    }


    private function normalize_font_face_weight_range($weight)
    {
        $weight = strtolower(trim((string) $weight, " \t\n\r\0\x0B\"'"));
        if ('' === $weight || 'normal' === $weight) {
            return array('raw' => '400', 'min' => 400, 'max' => 400);
        }
        if ('bold' === $weight) {
            return array('raw' => '700', 'min' => 700, 'max' => 700);
        }

        if (preg_match_all('/\d{3}/', $weight, $matches) && !empty($matches[0])) {
            $values = array_map('intval', $matches[0]);
            $values = array_values(array_filter($values, static function ($value) {
                return $value >= 100 && $value <= 1000;
            }));
            if (!empty($values)) {
                return array('raw' => $weight, 'min' => min($values), 'max' => max($values));
            }
        }

        return array('raw' => '' !== $weight ? $weight : '400', 'min' => 400, 'max' => 400);
    }


    private function extract_first_ttf_url_from_css_src_item($item, $base_url = '')
    {
        $item = (string) $item;
        if ('' === $item || false === stripos($item, '.ttf')) {
            return '';
        }

        if (!preg_match('/url\(\s*(["\']?)([^)"\']+\.ttf(?:[?#][^)"\']*)?)\1\s*\)/i', $item, $matches)) {
            return '';
        }

        $raw = isset($matches[2]) ? trim((string) $matches[2]) : '';
        if ('' === $raw) {
            return '';
        }

        $base_url = '' !== (string) $base_url ? (string) $base_url : home_url('/');
        $url = $this->normalize_public_resource_url($this->absolutize_public_resource_url($raw, $base_url));
        return '' !== $url ? esc_url_raw($url) : '';
    }


    private function find_same_path_preferred_font_url_for_font_face_ttf_block($block)
    {
        $block = (string) $block;
        if ('' === $block || false === stripos($block, '.ttf')) {
            return '';
        }

        if (!preg_match_all('/url\(([^)]+\.ttf(?:[\?#][^)]*)?)\)/i', $block, $matches)) {
            return '';
        }

        foreach ((array) $matches[1] as $raw) {
            $raw = trim((string) $raw, " \t\n\r\0\x0B\"'");
            if ('' === $raw) {
                continue;
            }

            $replacement = $this->find_same_path_preferred_font_url_for_ttf_url($raw);
            if ('' !== $replacement) {
                return $replacement;
            }
        }

        return '';
    }


    private function find_same_path_preferred_font_url_for_ttf_url($ttf_url)
    {
        $ttf_url = trim((string) $ttf_url);
        if ('' === $ttf_url || false === stripos($ttf_url, '.ttf')) {
            return '';
        }

        $normalized = $this->normalize_public_resource_url($this->absolutize_public_resource_url($ttf_url, home_url('/')));
        if ('' === $normalized) {
            return '';
        }

        $path = (string) wp_parse_url($normalized, PHP_URL_PATH);
        if ('' === $path || !preg_match('/\.ttf$/i', $path)) {
            return '';
        }

        $query = (string) wp_parse_url($normalized, PHP_URL_QUERY);
        foreach (array('.woff2', '.woff') as $extension) {
            $candidate_path = preg_replace('/\.ttf$/i', $extension, $path, 1);
            if (!is_string($candidate_path) || '' === $candidate_path) {
                continue;
            }

            $candidate_url = $candidate_path . ('' !== $query ? ('?' . $query) : '');
            $candidate_abs = $this->normalize_public_resource_url($this->absolutize_public_resource_url($candidate_url, home_url('/')));
            if ('' === $candidate_abs) {
                continue;
            }

            $candidate_file = $this->resolve_local_path_from_public_url($candidate_abs);
            if ('' === $candidate_file || !is_readable($candidate_file)) {
                continue;
            }

            $size = (int) ultracache_safe_filesize($candidate_file, 'same_path_preferred_font_candidate');
            if ($size <= 0) {
                continue;
            }

            return esc_url_raw($candidate_abs);
        }

        return '';
    }




private function get_font_format_for_font_url($url)
    {
        $path = strtolower((string) wp_parse_url((string) $url, PHP_URL_PATH));
        if (preg_match('/\.woff2$/', $path)) {
            return 'woff2';
        }
        if (preg_match('/\.woff$/', $path)) {
            return 'woff';
        }
        return 'woff2';
    }


    private function extract_first_font_face_woff2_url($block, $base_url)
    {
        $block = (string) $block;
        if ('' === $block || false === stripos($block, '.woff2')) {
            return '';
        }

        if (!preg_match_all('/url\(([^)]+\.woff2(?:[\?#][^)]*)?)\)/i', $block, $matches)) {
            return '';
        }

        foreach ((array) $matches[1] as $raw) {
            $raw = trim((string) $raw, " \t\n\r\0\x0B\"'");
            if ('' === $raw) {
                continue;
            }
            $url = $this->normalize_public_resource_url($this->absolutize_public_resource_url($raw, $base_url));
            if ('' !== $url) {
                return esc_url_raw($url);
            }
        }

        return '';
    }


    private function find_matching_woff2_font_face_url(array $registry, $family_key, $style_key, array $weight_range)
    {
        $family_key = $this->normalize_font_face_family_key($family_key);
        $style_key = $this->normalize_font_face_style_key($style_key);
        if ('' === $family_key || empty($registry[$family_key])) {
            return '';
        }

        $styles_to_try = array($style_key);
        if ('normal' !== $style_key) {
            $styles_to_try[] = 'normal';
        }

        $requested_min = (int) ($weight_range['min'] ?? 400);
        $requested_max = (int) ($weight_range['max'] ?? $requested_min);
        $requested = $requested_min === $requested_max ? $requested_min : $requested_min;

        foreach ($styles_to_try as $style) {
            if (empty($registry[$family_key][$style]) || !is_array($registry[$family_key][$style])) {
                continue;
            }

            foreach ($registry[$family_key][$style] as $entry) {
                $entry_min = (int) ($entry['weight']['min'] ?? 400);
                $entry_max = (int) ($entry['weight']['max'] ?? $entry_min);
                if ($requested >= $entry_min && $requested <= $entry_max && !empty($entry['url'])) {
                    return esc_url_raw((string) $entry['url']);
                }
            }
        }

        return '';
    }


    private function build_runtime_font_css_url_map_from_html($html)
    {
        $map = array();
        if (!is_string($html) || '' === $html || false === stripos($html, '<link') || false === stripos($html, '.css')) {
            return $map;
        }

        foreach ($this->collect_stylesheet_link_attributes_from_html($html) as $link) {
            $href = isset($link['href']) ? (string) $link['href'] : '';
            $source_url = $this->normalize_public_resource_url($href);
            if ('' === $source_url) {
                continue;
            }

            $path = strtolower((string) wp_parse_url($source_url, PHP_URL_PATH));
            if (ultracache_generated_asset_reference_matches($path, array('css-bundles', 'font-css', 'optimized-css'))) {
                continue;
            }

            $asset = $this->build_optimized_font_css_asset($source_url);
            $css_url = isset($asset['css_url']) ? esc_url_raw((string) $asset['css_url']) : '';
            if ('' !== $css_url && $css_url !== $source_url) {
                $map[$source_url] = $css_url;
            }
        }

        return $this->normalize_runtime_font_css_url_map($map);
    }


    private function build_runtime_font_css_url_map_from_bundle_manifest($entry_url = '')
    {
        $map = array();
        $entry_url = '' !== (string) $entry_url ? (string) $entry_url : $this->get_current_request_url();
        $entry = $this->get_frontpage_css_manifest_entry($entry_url);
        if (empty($entry) || empty($entry['sourceUrls']) || !is_array($entry['sourceUrls'])) {
            return $map;
        }

        foreach ((array) $entry['sourceUrls'] as $source_url) {
            $source_url = $this->normalize_public_resource_url((string) $source_url);
            if ('' === $source_url) {
                continue;
            }

            $asset = $this->build_optimized_font_css_asset($source_url);
            $css_url = isset($asset['css_url']) ? esc_url_raw((string) $asset['css_url']) : '';
            if ('' !== $css_url && $css_url !== $source_url) {
                $map[$source_url] = $css_url;
            }
        }

        return $this->normalize_runtime_font_css_url_map($map);
    }



private function get_local_font_css_scan_roots()
    {
        return function_exists('ultracache_local_font_css_scan_roots') ? ultracache_local_font_css_scan_roots() : array();
    }


    private function find_local_font_css_files_in_root($root)
    {
        $files = array();
        $root = wp_normalize_path((string) $root);
        if ('' === $root || !is_dir($root)) {
            return $files;
        }

        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file_info) {
                if (!$file_info->isFile()) {
                    continue;
                }

                $path = wp_normalize_path((string) $file_info->getPathname());
                if ('.css' !== strtolower(substr($path, -4))) {
                    continue;
                }

                if (ultracache_internal_cache_local_path_matches($path) || ultracache_generated_asset_local_path_matches($path)) {
                    continue;
                }

                if (!$file_info->isReadable()) {
                    continue;
                }

                $contents = ultracache_guarded_asset_file_get_contents($path, 'font-css', 'find_local_font_css_files_in_root', true);
                if (!is_string($contents) || '' === $contents || false === stripos($contents, '@font-face')) {
                    continue;
                }

                $files[] = $path;
            }
        } catch (Exception $e) {
            return $files;
        }

        return array_values(array_unique($files));
    }


    private function get_public_url_from_local_path($path)
    {
        if (!function_exists('ultracache_public_url_from_local_path')) {
            return '';
        }

        $path = wp_normalize_path((string) $path);
        if ('' === $path || !is_readable($path)) {
            return '';
        }

        return $this->normalize_public_resource_url(ultracache_public_url_from_local_path($path));
    }

    private function collect_enqueued_local_stylesheet_urls_for_font_display_patch()
    {
        global $wp_styles;

        if (!($wp_styles instanceof WP_Styles) || empty($wp_styles->queue) || !is_array($wp_styles->queue)) {
            return array();
        }

        $handles = array();
        $seen = array();
        foreach ($wp_styles->queue as $handle) {
            $this->collect_enqueued_style_handle_with_dependencies((string) $handle, $wp_styles, $handles, $seen);
        }

        if (empty($handles)) {
            return array();
        }

        $urls = array();
        foreach ($handles as $handle) {
            if ('ultracache-font-display-patch' === $handle || empty($wp_styles->registered[$handle])) {
                continue;
            }

            $style = $wp_styles->registered[$handle];
            $src = isset($style->src) ? (string) $style->src : '';
            if ('' === $src || false !== stripos($src, 'fonts.googleapis.com')) {
                continue;
            }

            $normalized = $this->normalize_public_resource_url($this->resolve_wp_style_src_to_public_url($src, $wp_styles));
            if ('' === $normalized || !$this->is_cacheable_local_url($normalized)) {
                continue;
            }

            $path = strtolower((string) wp_parse_url($normalized, PHP_URL_PATH));
            if (false === strpos($path, '.css')) {
                continue;
            }

            if (ultracache_generated_asset_reference_matches($path, array('font-css', 'css-bundles', 'optimized-css'))) {
                continue;
            }

            $urls[$normalized] = $normalized;
        }

        return array_values($urls);
    }

    private function collect_enqueued_style_handle_with_dependencies($handle, $styles, array &$handles, array &$seen)
    {
        $handle = (string) $handle;
        if ('' === $handle || isset($seen[$handle])) {
            return;
        }

        $seen[$handle] = true;
        if (empty($styles->registered[$handle])) {
            return;
        }

        $registered = $styles->registered[$handle];
        $deps = isset($registered->deps) && is_array($registered->deps) ? $registered->deps : array();
        foreach ($deps as $dependency) {
            $this->collect_enqueued_style_handle_with_dependencies((string) $dependency, $styles, $handles, $seen);
        }

        $handles[$handle] = $handle;
    }

    private function resolve_wp_style_src_to_public_url($src, $styles)
    {
        $src = trim((string) $src);
        if ('' === $src) {
            return '';
        }

        if (0 === strpos($src, '//') || preg_match('#^https?://#i', $src)) {
            return $this->normalize_public_resource_url($src);
        }

        if ('/' === $src[0]) {
            $home_parts = wp_parse_url(home_url('/'));
            $scheme = !empty($home_parts['scheme']) ? (string) $home_parts['scheme'] : (is_ssl() ? 'https' : 'http');
            $host = !empty($home_parts['host']) ? (string) $home_parts['host'] : '';
            $port = isset($home_parts['port']) ? ':' . (int) $home_parts['port'] : '';
            return '' !== $host ? $this->normalize_public_resource_url($scheme . '://' . $host . $port . $src) : '';
        }

        $base_url = isset($styles->base_url) && is_string($styles->base_url) && '' !== $styles->base_url ? $styles->base_url : includes_url();
        return $this->normalize_public_resource_url($this->absolutize_public_resource_url($src, $base_url));
    }


    private function css_has_font_face_requiring_display_normalization($css)
    {
        $css = (string) $css;
        if ('' === $css || false === stripos($css, '@font-face')) {
            return false;
        }

        $extracted = ultracache_extract_font_face_blocks_from_css($css);
        if (!empty($extracted['malformed'])) {
            return false;
        }
        $blocks = isset($extracted['blocks']) && is_array($extracted['blocks']) ? $extracted['blocks'] : array();
        foreach ($blocks as $block) {
            if ($this->font_face_block_requires_display_normalization((string) $block)) {
                return true;
            }
        }

        return false;
    }


    private function font_face_block_requires_display_normalization($block)
    {
        $block = (string) $block;
        if ('' === $block || false === stripos($block, '@font-face')) {
            return false;
        }

        $declaration_scan = ultracache_font_css_scan_declarations($block);
        if (!empty($declaration_scan['malformed'])) {
            return false;
        }

        $display = ultracache_font_css_find_declaration($block, 'font-display');
        if (empty($display)) {
            return true;
        }

        $value = strtolower(trim(ultracache_font_css_extract_declaration($block, 'font-display')));
        return in_array($value, array('auto', 'block'), true);
    }
}
