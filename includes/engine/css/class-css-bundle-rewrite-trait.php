<?php
/**
 * CSS bundle enqueue integration and final rendered-HTML stylesheet rewriting.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_CSS_Bundle_Rewrite_Trait
{

private function normalize_delayed_icon_fonts_stylesheet_entry(array $entry, $handle = 'ultracache-delayed-icon-fonts')
    {
        $policy = $this->get_font_optimization_policy();
        if (empty($policy['delay_icon_fonts'])) {
            return array();
        }

        $url = isset($entry['delayedFontUrl']) ? esc_url_raw((string) $entry['delayedFontUrl']) : '';
        if ('' === $url) {
            return array();
        }

        // Never enqueue a delayed icon-font stylesheet unless the generated CSS file
        // exists and has content. Otherwise stale runtime state can reference a
        // deleted generated file and break icon-font glyphs.
        $file = !empty($entry['delayedFontFile']) ? (string) $entry['delayedFontFile'] : $this->resolve_local_path_from_public_url($url);
        clearstatcache(true, $file);
        if ('' === $file || !is_readable($file) || filesize($file) <= 0) {
            return array();
        }

        $handle = preg_replace('/[^a-z0-9_\-]/i', '-', (string) $handle);
        if (!is_string($handle) || '' === $handle) {
            $handle = 'ultracache-delayed-icon-fonts';
        }

        return array(
            'handle' => $handle,
            'url'    => $url,
            'file'   => $file,
        );
    }

public function enqueue_delayed_icon_font_stylesheets()
    {
        if ((function_exists('is_admin') && is_admin()) || (function_exists('ultracache_should_bypass_logged_in_frontend_optimizations') && ultracache_should_bypass_logged_in_frontend_optimizations())) {
            return;
        }

        $entries = array();
        $settings = $this->get_settings();
        if (!empty($settings['homepage_css_bundle'])) {
            $mode = isset($settings['homepage_css_bundle_mode']) ? strtolower(trim((string) $settings['homepage_css_bundle_mode'])) : 'safe';
            if (!in_array($mode, array('safe', 'aggressive', 'full'), true)) {
                $mode = 'safe';
            }

            $scope = $this->get_css_bundle_scope($settings);
            $current_url = $this->get_current_request_url();
            $entry_url = $current_url;
            if ('homepage' === $scope) {
                if ($this->is_frontpage_request_url($current_url)) {
                    $entry_url = home_url('/');
                } else {
                    $entry_url = '';
                }
            } elseif ('shared' === $scope) {
                $entry_url = home_url('/');
            }

            if ('' !== $entry_url) {
                $entry = $this->get_frontpage_css_manifest_entry($entry_url);
                if (!empty($entry) && is_array($entry)) {
                    $entries[] = array(
                        'entry'  => $entry,
                        'handle' => 'ultracache-page-delayed-icon-fonts',
                    );
                }
            }
        }

        if (!empty($this->delayed_font_css_assets_current_request) && is_array($this->delayed_font_css_assets_current_request)) {
            $index = 0;
            foreach ($this->delayed_font_css_assets_current_request as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $handle = 'ultracache-no-bundle-delayed-icon-fonts';
                if ($index > 0) {
                    $handle .= '-' . (string) ($index + 1);
                }
                $entries[] = array(
                    'entry'  => $asset,
                    'handle' => $handle,
                );
                $index++;
            }
        }

        $seen = array();
        foreach ($entries as $item) {
            if (empty($item['entry']) || !is_array($item['entry'])) {
                continue;
            }

            $normalized = $this->normalize_delayed_icon_fonts_stylesheet_entry($item['entry'], isset($item['handle']) ? (string) $item['handle'] : 'ultracache-delayed-icon-fonts');
            if (empty($normalized['url']) || empty($normalized['handle'])) {
                continue;
            }

            $url_key = strtolower((string) $normalized['url']);
            if (isset($seen[$url_key])) {
                continue;
            }
            $seen[$url_key] = true;

            wp_register_style((string) $normalized['handle'], (string) $normalized['url'], array(), defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : null, 'print');
            wp_enqueue_style((string) $normalized['handle']);
        }
    }

public function add_delayed_icon_font_style_attributes($html, $handle, $href, $media)
    {
        if (function_exists('ultracache_should_bypass_logged_in_frontend_optimizations') && ultracache_should_bypass_logged_in_frontend_optimizations()) {
            return $html;
        }

        $handle = (string) $handle;
        if (0 !== strpos($handle, 'ultracache-page-delayed-icon-fonts') && 0 !== strpos($handle, 'ultracache-no-bundle-delayed-icon-fonts')) {
            return $html;
        }

        if ('' === (string) $html) {
            return $html;
        }

        if ($this->html_tag_processor_available()) {
            try {
                $processor = new WP_HTML_Tag_Processor((string) $html);
                if ($processor->next_tag('LINK')) {
                    $processor->set_attribute('media', 'print');
                    $processor->set_attribute('data-ultracache-target-media', 'all');
                    $processor->set_attribute('data-ultracache-delayed-icon-fonts', '1');
                    $processor->set_attribute('data-ultracache-css-role', 'delayed-fonts-css');
                    $processor->set_attribute('data-ultracache-css-async-reason', 'delayed-fonts');
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

private function maybe_enqueue_page_css_bundle_async_on_entry(array $settings = array())
    {
        $url = $this->get_current_request_url();
        if ('' === $url || !$this->is_cacheable_local_url($url)) {
            return false;
        }

        $request_method = function_exists('ultracache_server_value') ? ultracache_server_value('REQUEST_METHOD') : '';
        $request_method = strtoupper(sanitize_text_field($request_method));
        if ('' !== $request_method && 'GET' !== $request_method) {
            return false;
        }

        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return false;
        }

        if (!empty($this->get_frontpage_css_manifest_entry($url))) {
            return false;
        }

        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'enqueue_async_css_bundle_url')) {
            return false;
        }

        return (bool) Ultra_Cache_WP::enqueue_async_css_bundle_url($url);
    }

private function maybe_build_page_css_bundle_on_entry($html, array $settings = array())
    {
        $url = $this->get_current_request_url();
        if ('' === $url || !$this->is_cacheable_local_url($url)) {
            return false;
        }

        $request_method = function_exists('ultracache_server_value') ? ultracache_server_value('REQUEST_METHOD') : '';
        $request_method = strtoupper(sanitize_text_field($request_method));
        if ('' !== $request_method && 'GET' !== $request_method) {
            return false;
        }

        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return false;
        }

        if (!empty($this->get_frontpage_css_manifest_entry($url))) {
            return false;
        }

        $lock_name = 'css-entry-' . md5($this->normalize_url($url));
        if (!$this->acquire_runtime_lock($lock_name, 120)) {
            return false;
        }

        try {
            if (!empty($this->get_frontpage_css_manifest_entry($url))) {
                return false;
            }

            $prepared = $this->build_frontpage_css_bundle_from_html((string) $html, $url, (string) ($settings['homepage_css_bundle_mode'] ?? 'safe'));
            if (empty($prepared['success'])) {
                return false;
            }

        $manifest = $this->read_frontpage_css_manifest();
        if (!isset($manifest['entries']) || !is_array($manifest['entries'])) {
            $manifest['entries'] = array();
        }

        $entry = $this->build_frontpage_css_manifest_entry($url, $prepared);

        $key = $this->get_css_bundle_manifest_key($url);
        if ('' !== $key) {
            $manifest['version'] = 4;
            $manifest['updatedAt'] = current_time('timestamp');
            $manifest['updatedAtMysql'] = current_time('mysql');
            $manifest['entries'][$key] = $entry;
            if ($this->is_frontpage_request_url($url)) {
                $manifest['entry'] = $entry;
            }
            $this->write_frontpage_css_manifest($manifest);
            $this->cleanup_orphan_frontpage_css_bundles($manifest);
            return true;
        }

        return false;
        } finally {
            $this->release_runtime_lock($lock_name);
        }
    }

private function prepare_inline_css_bundle_for_style_tag($css)
    {
        $css = is_string($css) ? trim($css) : '';
        if ('' === $css) {
            return '';
        }

        // Keep inline CSS bundles safe inside an HTML <style> element. A literal
        // </style sequence inside a bundled stylesheet can prematurely close the
        // tag and leave the document malformed.
        $css = preg_replace('/<\/(style)/i', '<\\/$1', $css);
        if (!is_string($css)) {
            return '';
        }

        // @charset belongs at the top of external stylesheets. Concatenated inline
        // bundles may contain multiple declarations, so strip them for inline mode.
        $css = preg_replace('/@charset\s+["\'][^"\']+["\']\s*;/i', '', $css);
        if (!is_string($css)) {
            return '';
        }

        return trim($css);
    }

/**
     * Replace cached CSS-bundle source stylesheet links using the WordPress HTML API.
     *
     * This intentionally does not fall back to regex-based structural HTML mutation.
     * If the HTML API cannot process the document, the original HTML is returned.
     * Matched source links are made inert so they no longer request their original
     * stylesheet, and the generated bundle markup is inserted at the first matched
     * source position.
     *
     * @param string $html HTML document.
     * @param array  $source_urls Absolute source stylesheet URLs keyed by normalized URL.
     * @param string $replacement_markup Bundle stylesheet or inline style markup.
     * @param string $base_url URL used to resolve relative href attributes.
     * @param string $source_marker Attribute used to mark inert source links.
     * @return string
     */
    private function replace_cached_css_bundle_links_with_html_api($html, array $source_urls, $replacement_markup, $base_url = '', $source_marker = 'data-ultracache-css-bundle-source')
    {
        $html = is_string($html) ? $html : '';
        $replacement_markup = is_string($replacement_markup) ? trim($replacement_markup) : '';
        $has_replacement_markup = '' !== $replacement_markup;
        if ('' === $html || empty($source_urls) || false === stripos($html, '<link')) {
            return $html;
        }

        if (!$this->html_tag_processor_available()) {
            return $html;
        }

        $head_close = stripos($html, '</head>');
        if (false === $head_close) {
            return $html;
        }

        $head_html = substr($html, 0, $head_close);
        $tail_html = substr($html, $head_close);
        if ('' === $head_html) {
            return $html;
        }

        $source_marker = preg_replace('/[^A-Za-z0-9_:-]/', '', (string) $source_marker);
        if (!is_string($source_marker) || '' === $source_marker) {
            $source_marker = 'data-ultracache-css-bundle-source';
        }

        $insertion_marker = 'data-ultracache-css-bundle-insertion-point';
        $insertion_token = 'ultracache-css-bundle-' . md5($replacement_markup . '|' . implode('|', array_keys($source_urls)));
        $base_url = '' !== (string) $base_url ? (string) $base_url : home_url('/');

        try {
            $processor = new WP_HTML_Tag_Processor($head_html);
            $matched = 0;

            while ($processor->next_tag('LINK')) {
                $rel = $processor->get_attribute('rel');
                if (!$this->html_rel_attribute_contains_stylesheet($rel)) {
                    continue;
                }

                $href = $processor->get_attribute('href');
                if (!is_string($href) || '' === $href) {
                    continue;
                }

                $absolute_url = $this->absolutize_public_resource_url(html_entity_decode($href, ENT_QUOTES | ENT_HTML5), $base_url);
                if (!$this->css_bundle_rendered_stylesheet_matches_source_urls($absolute_url, $source_urls)) {
                    continue;
                }

                $matched++;
                if (1 === $matched && $has_replacement_markup) {
                    $processor->set_attribute($insertion_marker, $insertion_token);
                }

                foreach (array('rel', 'href', 'as', 'type', 'media', 'onload', 'crossorigin', 'integrity', 'referrerpolicy') as $attribute) {
                    $processor->remove_attribute($attribute);
                }

                $processor->set_attribute($source_marker, '1');
                $processor->set_attribute('data-ultracache-css-bundle-original-href', $absolute_url);
            }

            if ($matched <= 0) {
                return $html;
            }

            $updated_head = $processor->get_updated_html();
            if (!is_string($updated_head) || '' === $updated_head) {
                return $html;
            }

            if (!$has_replacement_markup) {
                return $updated_head . $tail_html;
            }

            $token_offset = strpos($updated_head, $insertion_token);
            if (false === $token_offset) {
                return $html;
            }

            $prefix = substr($updated_head, 0, $token_offset);
            $tag_offset = strripos($prefix, '<link');
            if (false === $tag_offset) {
                return $html;
            }

            $updated_head = substr($updated_head, 0, $tag_offset) . $replacement_markup . "\n" . substr($updated_head, $tag_offset);

            return $updated_head . $tail_html;
        } catch (\Throwable $e) {
            if (function_exists('ultracache_debug_log')) {
                ultracache_debug_log('CSS bundle HTML API replacement failed.', array(
                    'error' => $e->getMessage(),
                ));
            }

            return $html;
        }
    }

/**
     * Enqueue the generated page CSS bundle using WordPress' native stylesheet queue.
     *
     * The final HTML buffer still owns the source-link inerting step because the bundle
     * replaces already-rendered stylesheet links. This method only makes the generated
     * bundle itself come from wp_enqueue_style()/wp_add_inline_style() instead of raw
     * output-buffer markup.
     *
     * @return void
     */
    public function enqueue_page_css_bundle_stylesheet()
    {
        if ((function_exists('is_admin') && is_admin()) || (function_exists('ultracache_should_bypass_logged_in_frontend_optimizations') && ultracache_should_bypass_logged_in_frontend_optimizations())) {
            return;
        }

        $settings = $this->get_settings();
        if (empty($settings['homepage_css_bundle'])) {
            return;
        }

        $mode = isset($settings['homepage_css_bundle_mode']) ? strtolower(trim((string) $settings['homepage_css_bundle_mode'])) : 'safe';
        if (!in_array($mode, array('safe', 'aggressive', 'full'), true)) {
            $mode = 'safe';
        }

        $scope = $this->get_css_bundle_scope($settings);
        $current_url = $this->get_current_request_url();
        $entry_url = $current_url;

        if ('homepage' === $scope) {
            if (!$this->is_frontpage_request_url($current_url)) {
                return;
            }
            $entry_url = home_url('/');
        } elseif ('shared' === $scope) {
            $entry_url = home_url('/');
        }

        $entry = $this->get_frontpage_css_manifest_entry($entry_url);
        if (empty($entry)) {
            return;
        }

        $bundle_file = isset($entry['bundleFile']) ? (string) $entry['bundleFile'] : '';
        $bundle_url = isset($entry['bundleUrl']) ? (string) $entry['bundleUrl'] : '';
        if ('' === $bundle_file || !is_readable($bundle_file) || '' === $bundle_url) {
            return;
        }

        $source_urls = (array) ($entry['sourceUrls'] ?? array());
        if (empty($source_urls)) {
            return;
        }

        $entry_mode = isset($entry['mode']) ? strtolower((string) $entry['mode']) : $mode;
        $entry_mode = in_array($entry_mode, array('safe', 'aggressive', 'full'), true) ? $entry_mode : $mode;
        $page_bundle_role = $this->get_generated_css_bundle_role_from_mode($entry_mode);

        if (!empty($settings['homepage_css_bundle_inline'])) {
            $maybe_css = ultracache_guarded_asset_file_get_contents($bundle_file, 'generated-css', 'page_css_bundle_inline_generated_asset', false);
            $bundle_css = $this->prepare_inline_css_bundle_for_style_tag($maybe_css);
            if ('' === $bundle_css) {
                return;
            }

            wp_register_style('ultracache-page-css-bundle', false, array(), defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : null);
            wp_style_add_data('ultracache-page-css-bundle', 'ultracache_css_role', $page_bundle_role);
            wp_enqueue_style('ultracache-page-css-bundle');
            wp_add_inline_style('ultracache-page-css-bundle', $bundle_css);
            return;
        }

        $href = esc_url_raw($bundle_url);
        if ('' === $href) {
            return;
        }

        wp_register_style('ultracache-page-css-bundle', $href, array(), defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : null);
        wp_style_add_data('ultracache-page-css-bundle', 'ultracache_css_role', $page_bundle_role);
        wp_enqueue_style('ultracache-page-css-bundle');
    }

/**
     * Add UltraCache metadata to the WordPress-enqueued page CSS bundle link.
     *
     * @param string $html   Link tag HTML generated by WordPress.
     * @param string $handle Stylesheet handle.
     * @param string $href   Stylesheet URL.
     * @param string $media  Media attribute value.
     * @return string
     */
    public function add_page_css_bundle_style_attributes($html, $handle, $href, $media)
    {
        if (function_exists('ultracache_should_bypass_logged_in_frontend_optimizations') && ultracache_should_bypass_logged_in_frontend_optimizations()) {
            return $html;
        }

        if ('ultracache-page-css-bundle' !== (string) $handle || '' === (string) $html) {
            return $html;
        }

        $role = '';
        if (function_exists('wp_styles')) {
            $wp_styles = wp_styles();
            if (is_object($wp_styles) && method_exists($wp_styles, 'get_data')) {
                $role = (string) $wp_styles->get_data('ultracache-page-css-bundle', 'ultracache_css_role');
            }
        }
        if ('' === $role) {
            $role = 'generated-css-bundle';
        }

        if ($this->html_tag_processor_available()) {
            try {
                $processor = new WP_HTML_Tag_Processor((string) $html);
                if ($processor->next_tag('LINK')) {
                    $processor->set_attribute('data-ultracache-page-css-bundle', '1');
                    $processor->set_attribute('data-ultracache-css-role', $role);
                    $processor->set_attribute('data-ultracache-css-blocking-reason', 'main-layout-risk');
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

private function add_page_css_bundle_inline_style_attributes_to_markup($markup, $role)
    {
        $markup = is_string($markup) ? $markup : '';
        if ('' === $markup) {
            return '';
        }

        $role = '' !== (string) $role ? (string) $role : 'generated-css-bundle';
        if ($this->html_tag_processor_available()) {
            try {
                $processor = new WP_HTML_Tag_Processor($markup);
                if ($processor->next_tag('STYLE')) {
                    $processor->set_attribute('data-ultracache-page-css-bundle', '1');
                    $processor->set_attribute('data-ultracache-css-role', $role);
                    $processor->set_attribute('data-ultracache-css-blocking-reason', 'main-layout-risk');
                    $updated = $processor->get_updated_html();
                    return is_string($updated) && '' !== $updated ? $updated : $markup;
                }
            } catch (\Throwable $e) {
                return $markup;
            }
        }

        // Supported WordPress versions provide WP_HTML_Tag_Processor. If it is unavailable or cannot process the tag, keep the original WordPress-generated markup instead of using a raw regex fallback.
        return $markup;
    }

private function extract_wp_enqueued_page_css_bundle_markup_from_html(&$html, $role)
    {
        $html = is_string($html) ? $html : '';
        if ('' === $html) {
            return '';
        }

        $patterns = array(
            '/<style\b(?=[^>]*\bid=([' . "'\"" . '])ultracache-page-css-bundle-inline-css\1)[^>]*>.*?<\/style>/is',
            '/<link\b(?=[^>]*\bid=([' . "'\"" . '])ultracache-page-css-bundle-css\1)[^>]*>/i',
        );

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $html, $matches)) {
                continue;
            }

            $markup = isset($matches[0]) ? (string) $matches[0] : '';
            if ('' === $markup) {
                continue;
            }

            $html = (string) preg_replace($pattern, '', $html, 1);
            if (false !== stripos($markup, '<style')) {
                $markup = $this->add_page_css_bundle_inline_style_attributes_to_markup($markup, $role);
            }

            return trim($markup);
        }

        return '';
    }

/**
     * Build canonical URL variants used when matching stylesheet sources.
     *
     * Bundle manifests keep the rendered stylesheet URL, often including
     * cache-busting query args such as ?ver=. CSS rewrite-map rows are
     * intentionally keyed by the canonical public source URL without those
     * query args. Treat the full URL and no-query URL as aliases so a
     * generated optimized-css href can still be matched back to the bundle
     * manifest source.
     *
     * @param string $url      URL to normalize.
     * @param string $base_url Base URL for relative resources.
     * @return array<int,string>
     */
    private function get_css_bundle_url_match_variants($url, $base_url = '')
    {
        $absolute = $this->absolutize_public_resource_url((string) $url, '' !== (string) $base_url ? (string) $base_url : home_url('/'));
        if ('' === $absolute || 0 === strpos($absolute, 'data:') || 0 === strpos($absolute, 'about:') || '#' === $absolute[0]) {
            return array();
        }

        $variants = array($absolute);
        $parts = wp_parse_url($absolute);
        if (is_array($parts) && !empty($parts['host']) && !empty($parts['path'])) {
            $scheme = !empty($parts['scheme']) ? (string) $parts['scheme'] : (is_ssl() ? 'https' : 'http');
            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
            $without_query = $scheme . '://' . $parts['host'] . $port . $parts['path'];
            $variants[] = $without_query;

            if (!empty($parts['query'])) {
                $query_args = array();
                wp_parse_str((string) $parts['query'], $query_args);
                if (!empty($query_args)) {
                    unset($query_args['ver']);
                    unset($query_args['version']);
                    $filtered_query = http_build_query($query_args, '', '&');
                    if ('' !== $filtered_query) {
                        $variants[] = $without_query . '?' . $filtered_query;
                    }
                }
            }
        }

        $clean = array();
        foreach ($variants as $variant) {
            $variant = esc_url_raw((string) $variant);
            if ('' !== $variant) {
                $clean[$variant] = true;
            }
        }

        return array_keys($clean);
    }

/**
     * Expand bundle source URL matches with generated CSS rewrite aliases.
     *
     * Font-display normalization can rewrite original local stylesheet links to
     * generated optimized CSS URLs before the page CSS bundle replacer
     * runs on the final HTML buffer. The bundle manifest still correctly stores
     * the original source URLs. Use UltraCache's existing rewrite maps so the
     * replacer can treat generated optimized-css/font-css links as aliases of
     * the manifest sources instead of leaving the bundle unused.
     *
     * @param array<string,bool> $source_urls Absolute source URL lookup map.
     * @return array<string,bool>
     */
    private function expand_css_bundle_source_urls_with_rewrite_aliases(array $source_urls)
    {
        if (empty($source_urls)) {
            return $source_urls;
        }

        $expanded = $source_urls;
        foreach (array_keys($source_urls) as $source_url) {
            foreach ($this->get_css_bundle_url_match_variants((string) $source_url, home_url('/')) as $normalized_source) {
                $expanded[$normalized_source] = true;

                if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_css_rewrite_map_by_source_url')) {
                    foreach (array('css-font-mix', '') as $optimization_type) {
                        $row = Ultra_Cache_WP::get_css_rewrite_map_by_source_url($normalized_source, $optimization_type);
                        if (!is_array($row) || empty($row['generated_url'])) {
                            continue;
                        }

                        foreach ($this->get_css_bundle_url_match_variants((string) $row['generated_url'], home_url('/')) as $generated_url) {
                            $expanded[$generated_url] = true;
                        }
                    }
                }

                if (method_exists($this, 'get_runtime_local_font_css_url_map')) {
                    $runtime_map = $this->get_runtime_local_font_css_url_map();
                    if (is_array($runtime_map) && !empty($runtime_map[$normalized_source])) {
                        foreach ($this->get_css_bundle_url_match_variants((string) $runtime_map[$normalized_source], home_url('/')) as $runtime_generated) {
                            $expanded[$runtime_generated] = true;
                        }
                    }
                }
            }
        }

        return $expanded;
    }

/**
     * Check whether a rendered stylesheet href belongs to the bundle source set.
     *
     * The final HTML may already contain generated optimized-css/font-css URLs
     * after font-display normalization. In that case the manifest source URL is
     * not present as the rendered href, so perform a reverse lookup through the
     * existing CSS rewrite map before deciding the link is unrelated.
     *
     * @param string             $absolute_url Absolute rendered stylesheet URL.
     * @param array<string,bool> $source_urls  Bundle source URL lookup map.
     * @return bool
     */
    private function css_bundle_rendered_stylesheet_matches_source_urls($absolute_url, array $source_urls)
    {
        $variants = $this->get_css_bundle_url_match_variants((string) $absolute_url, home_url('/'));
        if (empty($variants)) {
            return false;
        }

        foreach ($variants as $variant) {
            if (isset($source_urls[$variant])) {
                return true;
            }
        }

        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'get_css_rewrite_map_by_generated_url')) {
            return false;
        }

        foreach ($variants as $variant) {
            $row = Ultra_Cache_WP::get_css_rewrite_map_by_generated_url($variant);
            if (!is_array($row) || empty($row['source_url'])) {
                continue;
            }

            foreach ($this->get_css_bundle_url_match_variants((string) $row['source_url'], home_url('/')) as $source_url) {
                if (isset($source_urls[$source_url])) {
                    return true;
                }
            }
        }

        return false;
    }

private function maybe_replace_page_stylesheet_links_with_bundle($html, $entry_url = '')
    {
        if (!is_string($html) || '' === $html) {
            return $html;
        }

        // Do not process the same HTML twice.
        if (false !== stripos($html, 'data-ultracache-page-css-bundle-source=')) {
            return $html;
        }

        $current_url = $this->get_current_request_url();
        $entry_url = '' !== (string) $entry_url ? (string) $entry_url : $current_url;
        $entry = $this->get_frontpage_css_manifest_entry($entry_url);
        if (empty($entry)) {
            return $html;
        }

        $bundle_file = isset($entry['bundleFile']) ? (string) $entry['bundleFile'] : '';
        $bundle_url = isset($entry['bundleUrl']) ? (string) $entry['bundleUrl'] : '';
        if ('' === $bundle_file || !is_readable($bundle_file) || '' === $bundle_url) {
            return $html;
        }

        $source_urls = array();
        foreach ((array) ($entry['sourceUrls'] ?? array()) as $url) {
            foreach ($this->get_css_bundle_url_match_variants((string) $url, home_url('/')) as $normalized) {
                $source_urls[$normalized] = true;
            }
        }
        if (empty($source_urls)) {
            return $html;
        }
        $source_urls = $this->expand_css_bundle_source_urls_with_rewrite_aliases($source_urls);

        $mode = isset($entry['mode']) && 'aggressive' === strtolower((string) $entry['mode']) ? 'aggressive' : (isset($entry['mode']) && 'full' === strtolower((string) $entry['mode']) ? 'full' : 'safe');
        $page_bundle_role = $this->get_generated_css_bundle_role_from_mode($mode);
        $html_before_bundle_extraction = $html;
        $bundle_markup = $this->extract_wp_enqueued_page_css_bundle_markup_from_html($html, $page_bundle_role);
        $extracted_enqueued_bundle = ($html !== $html_before_bundle_extraction);
        if ('' === $bundle_markup) {
            return $html_before_bundle_extraction;
        }

        $replacement = $bundle_markup;

        $updated_html = $this->replace_cached_css_bundle_links_with_html_api(
            $html,
            $source_urls,
            $replacement,
            '' !== $current_url ? $current_url : home_url('/'),
            'data-ultracache-page-css-bundle-source'
        );

        // Preserve an already WordPress-enqueued CSS bundle if no source links were replaced.
        // The extraction step is temporary; returning the intermediate HTML would drop the bundle.
        if ($extracted_enqueued_bundle && $updated_html === $html) {
            return $html_before_bundle_extraction;
        }

        return $updated_html;
    }

private function build_contiguous_css_bundle_runs_from_records(array $records)
    {
        $runs = array();
        $current_run = array();

        $flush_run = static function () use (&$runs, &$current_run) {
            if (!empty($current_run)) {
                $runs[] = $current_run;
                $current_run = array();
            }
        };

        foreach ($records as $record) {
            $record = is_array($record) ? $record : array();
            if (!empty($record['eligible'])) {
                $current_run[] = $record;
                continue;
            }

            if (!empty($record['boundary'])) {
                $flush_run();
            }
        }

        $flush_run();
        return $runs;
    }

private function collect_contiguous_css_bundle_runs_with_processor($html, callable $candidate_resolver)
    {
        $collector = new WP_HTML_Tag_Processor($html);
        $records = array();
        $skipped = array();
        $source_urls = array();
        $link_position = 0;
        $candidate_count = 0;

        while ($collector->next_tag('LINK')) {
            $link_position++;
            $candidate = call_user_func($candidate_resolver, $collector);
            $candidate = is_array($candidate) ? $candidate : array();
            $asset = isset($candidate['asset']) && is_array($candidate['asset']) ? $candidate['asset'] : array();
            $skip = isset($candidate['skip']) ? (string) $candidate['skip'] : '';
            $url = isset($asset['url']) ? (string) $asset['url'] : '';

            if (!empty($asset) && '' !== $url) {
                $candidate_count++;
                $source_urls[] = $url;
                $records[] = array(
                    'eligible' => true,
                    'boundary' => false,
                    'position' => $link_position,
                    'url' => $url,
                    'asset' => $asset,
                );
                continue;
            }

            $skipped[] = $candidate;
            $records[] = array(
                'eligible' => false,
                // Non-stylesheet link tags do not participate in CSS cascade order.
                'boundary' => ('not-stylesheet' !== $skip),
                'position' => $link_position,
                'url' => '',
                'asset' => array(),
                'skip' => $skip,
            );
        }

        return array(
            'runs' => $this->build_contiguous_css_bundle_runs_from_records($records),
            'skipped' => $skipped,
            'candidateCount' => $candidate_count,
            'sourceUrls' => array_values(array_unique(array_map('strval', $source_urls))),
        );
    }

private function apply_contiguous_css_bundle_runs_with_processor($html, array $plans, $mode)
    {
        if (!$this->html_tag_processor_available() || empty($plans) || !is_string($html) || '' === $html) {
            return null;
        }

        $position_actions = array();
        $required_bundle_positions = array();
        foreach (array_values($plans) as $plan_index => $plan) {
            $records = isset($plan['records']) && is_array($plan['records']) ? array_values($plan['records']) : array();
            $bundle_url = isset($plan['bundleUrl']) ? (string) $plan['bundleUrl'] : '';
            $delayed_font_url = isset($plan['delayedFontUrl']) ? (string) $plan['delayedFontUrl'] : '';
            if (count($records) < 2 || '' === $bundle_url) {
                continue;
            }

            $run_number = $plan_index + 1;
            foreach ($records as $record_index => $record) {
                $position = isset($record['position']) ? (int) $record['position'] : 0;
                if ($position < 1) {
                    continue;
                }

                if (0 === $record_index) {
                    $position_actions[$position] = array(
                        'action' => 'bundle',
                        'bundleUrl' => $bundle_url,
                        'runNumber' => $run_number,
                    );
                    $required_bundle_positions[$position] = false;
                    continue;
                }

                if ('font-mix' === (string) $mode && 1 === $record_index && '' !== $delayed_font_url) {
                    $position_actions[$position] = array(
                        'action' => 'delayed-font',
                        'delayedFontUrl' => $delayed_font_url,
                        'runNumber' => $run_number,
                    );
                    continue;
                }

                $position_actions[$position] = array(
                    'action' => 'neutralize',
                    'sourceUrl' => isset($record['url']) ? (string) $record['url'] : '',
                );
            }
        }

        if (empty($position_actions) || empty($required_bundle_positions)) {
            return null;
        }

        try {
            $processor = new WP_HTML_Tag_Processor($html);
            $link_position = 0;
            $changed = false;

            while ($processor->next_tag('LINK')) {
                $link_position++;
                if (!isset($position_actions[$link_position])) {
                    continue;
                }

                $action = $position_actions[$link_position];
                switch ((string) ($action['action'] ?? '')) {
                    case 'bundle':
                        if ('font-mix' === (string) $mode) {
                            $this->rewrite_link_processor_to_font_mix_css_bundle(
                                $processor,
                                (string) ($action['bundleUrl'] ?? ''),
                                (int) ($action['runNumber'] ?? 1)
                            );
                        } else {
                            $this->rewrite_link_processor_to_leftover_css_bundle(
                                $processor,
                                (string) ($action['bundleUrl'] ?? ''),
                                (int) ($action['runNumber'] ?? 1)
                            );
                        }
                        $required_bundle_positions[$link_position] = true;
                        $changed = true;
                        break;

                    case 'delayed-font':
                        $this->rewrite_link_processor_to_delayed_font_mix_icon_fonts(
                            $processor,
                            (string) ($action['delayedFontUrl'] ?? ''),
                            (int) ($action['runNumber'] ?? 1)
                        );
                        $changed = true;
                        break;

                    case 'neutralize':
                        if ('font-mix' === (string) $mode) {
                            $this->neutralize_link_processor_for_font_mix_css_source($processor, (string) ($action['sourceUrl'] ?? ''));
                        } else {
                            $this->neutralize_link_processor_for_leftover_css_source($processor, (string) ($action['sourceUrl'] ?? ''));
                        }
                        $changed = true;
                        break;
                }
            }

            if (!$changed || in_array(false, $required_bundle_positions, true)) {
                return null;
            }

            $updated_html = $processor->get_updated_html();
            return is_string($updated_html) && '' !== $updated_html ? $updated_html : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

private function maybe_consolidate_leftover_stylesheet_links($html, array $settings = array())
    {
        $stats = $this->get_leftover_css_bundle_default_stats();
        if (empty($settings['leftover_css_bundle'])) {
            $stats['enabled'] = false;
            $stats['skipped_reason'] = 'disabled';
            $this->record_leftover_css_bundle_profile($stats);
            return $html;
        }

        if (!is_string($html) || '' === $html || false === stripos($html, '<link')) {
            $stats['skipped_reason'] = 'no-html-or-links';
            $this->record_leftover_css_bundle_profile($stats);
            return $html;
        }

        if (false !== stripos($html, 'data-ultracache-leftover-css-bundle=')) {
            $stats['skipped_reason'] = 'already-applied';
            $this->record_leftover_css_bundle_profile($stats);
            return $html;
        }

        $processed = $this->maybe_consolidate_leftover_stylesheet_links_with_processor($html, $settings, $stats);
        if (is_string($processed) && '' !== $processed) {
            return $processed;
        }

        $stats['skipped_reason'] = 'html-api-leftover-css-consolidation-failed';
        $this->record_leftover_css_bundle_profile($stats);
        return $html;
    }

private function maybe_consolidate_leftover_stylesheet_links_with_processor($html, array $settings, array &$stats)
    {
        if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || false === stripos($html, '<link')) {
            return null;
        }

        try {
            $page_url = $this->get_current_request_url();
            $collected = $this->collect_contiguous_css_bundle_runs_with_processor(
                $html,
                function ($processor) use ($page_url, $settings) {
                    return $this->get_leftover_css_bundle_candidate_from_link_processor($processor, $page_url, $settings);
                }
            );

            foreach ((array) ($collected['skipped'] ?? array()) as $candidate) {
                $skip = isset($candidate['skip']) ? (string) $candidate['skip'] : '';
                switch ($skip) {
                    case 'protected':
                        $stats['skipped_protected_count']++;
                        if (!empty($candidate['url']) && count($stats['protected_urls']) < 20) {
                            $stats['protected_urls'][] = array(
                                'url' => (string) $candidate['url'],
                                'reason' => isset($candidate['reason']) ? (string) $candidate['reason'] : 'protected',
                            );
                        }
                        break;
                    case 'nonlocal':
                        $stats['skipped_nonlocal_count']++;
                        break;
                    case 'unreadable':
                        $stats['skipped_unreadable_count']++;
                        break;
                    case 'async':
                    case 'external-css-async-wins-bundle':
                        $stats['skipped_async_count']++;
                        break;
                    case 'media':
                        $stats['skipped_media_count']++;
                        break;
                    case 'existing-bundle':
                        $stats['skipped_existing_bundle_count']++;
                        break;
                }
            }

            $stats['candidate_count'] = max(0, (int) ($collected['candidateCount'] ?? 0));
            $stats['source_urls'] = isset($collected['sourceUrls']) && is_array($collected['sourceUrls']) ? $collected['sourceUrls'] : array();
            $runs = array_values(array_filter((array) ($collected['runs'] ?? array()), static function ($run) {
                return is_array($run) && count($run) >= 2;
            }));

            if (empty($runs)) {
                $stats['skipped_reason'] = 'not-enough-contiguous-eligible-leftover-css';
                $this->record_leftover_css_bundle_profile($stats);
                return $html;
            }

            $plans = array();
            foreach ($runs as $run) {
                $assets = array_values(array_map(static function ($record) {
                    return isset($record['asset']) && is_array($record['asset']) ? $record['asset'] : array();
                }, $run));
                $bundle = $this->build_frontpage_css_bundle_file($page_url, $assets, 'leftover');
                if (empty($bundle['success'])) {
                    continue;
                }

                $bundle_url = isset($bundle['url']) ? (string) $bundle['url'] : '';
                $bundle_file = isset($bundle['file']) ? (string) $bundle['file'] : '';
                if ('' === $bundle_url || '' === $bundle_file || !is_readable($bundle_file) || !empty($bundle['delayedFontUrl'])) {
                    continue;
                }

                $plans[] = array(
                    'records' => $run,
                    'bundleUrl' => $bundle_url,
                    'bundleFile' => $bundle_file,
                    'bundleBytes' => (int) filesize($bundle_file),
                    'sourceBytesTotal' => isset($bundle['sourceBytesTotal']) ? (int) $bundle['sourceBytesTotal'] : 0,
                    'sourceDetails' => isset($bundle['sourceDetails']) && is_array($bundle['sourceDetails']) ? $bundle['sourceDetails'] : array(),
                    'delayedFontUrl' => '',
                );
            }

            if (empty($plans)) {
                $stats['skipped_reason'] = 'contiguous-leftover-css-bundle-build-failed';
                $this->record_leftover_css_bundle_profile($stats);
                return $html;
            }

            $updated = $this->apply_contiguous_css_bundle_runs_with_processor($html, $plans, 'leftover');
            if (!is_string($updated) || '' === $updated || $updated === $html) {
                $stats['skipped_reason'] = 'html-api-replacement-failed';
                $this->record_leftover_css_bundle_profile($stats);
                return $html;
            }

            $stats['success'] = true;
            $stats['run_count'] = count($plans);
            $stats['replaced_link_count'] = array_sum(array_map(static function ($plan) {
                return count((array) ($plan['records'] ?? array()));
            }, $plans));
            $stats['bundle_urls'] = array_values(array_map(static function ($plan) { return (string) ($plan['bundleUrl'] ?? ''); }, $plans));
            $stats['bundle_files'] = array_values(array_map(static function ($plan) { return (string) ($plan['bundleFile'] ?? ''); }, $plans));
            $stats['bundle_url'] = (string) ($stats['bundle_urls'][0] ?? '');
            $stats['bundle_file'] = (string) ($stats['bundle_files'][0] ?? '');
            $stats['bundle_bytes'] = array_sum(array_map(static function ($plan) { return (int) ($plan['bundleBytes'] ?? 0); }, $plans));
            $stats['source_bytes_total'] = array_sum(array_map(static function ($plan) { return (int) ($plan['sourceBytesTotal'] ?? 0); }, $plans));
            $stats['source_details'] = array_values(array_merge(...array_map(static function ($plan) {
                return (array) ($plan['sourceDetails'] ?? array());
            }, $plans)));
            $this->record_leftover_css_bundle_profile($stats);

            return $updated;
        } catch (\Throwable $e) {
            return null;
        }
    }

private function rewrite_link_processor_to_leftover_css_bundle($processor, $bundle_url, $run_number = 1)
    {
        $this->clear_leftover_css_link_processor_attributes($processor);
        $processor->set_attribute('rel', 'stylesheet');
        $run_number = max(1, (int) $run_number);
        $processor->set_attribute('id', 1 === $run_number ? 'ultracache-leftover-css-bundle' : 'ultracache-leftover-css-bundle-' . $run_number);
        $processor->set_attribute('href', (string) $bundle_url);
        $processor->set_attribute('data-ultracache-leftover-css-bundle', '1');
        $processor->set_attribute('data-ultracache-css-role', 'leftover-bundle');
    }

private function neutralize_link_processor_for_leftover_css_source($processor, $source_url)
    {
        $this->clear_leftover_css_link_processor_attributes($processor);
        $processor->set_attribute('data-ultracache-leftover-css-source-removed', '1');
        $processor->set_attribute('data-ultracache-leftover-css-original-href', (string) $source_url);
    }

private function clear_leftover_css_link_processor_attributes($processor)
    {
        foreach (array('rel', 'href', 'id', 'media', 'onload', 'disabled', 'as', 'crossorigin', 'integrity', 'type', 'data-href', 'data-src') as $attribute) {
            $processor->remove_attribute($attribute);
        }
    }

private function maybe_consolidate_font_mix_stylesheet_links($html, array $settings = array())
    {
        $stats = $this->get_font_mix_css_bundle_default_stats();
        if (empty($settings['font_mix_css_bundle'])) {
            $stats['enabled'] = false;
            $stats['skipped_reason'] = 'disabled';
            $this->record_font_mix_css_bundle_profile($stats);
            return $html;
        }

        if (!is_string($html) || '' === $html || false === stripos($html, '<link')) {
            $stats['skipped_reason'] = 'no-html-or-links';
            $this->record_font_mix_css_bundle_profile($stats);
            return $html;
        }

        if (false !== stripos($html, 'data-ultracache-font-mix-css-bundle=')) {
            $stats['skipped_reason'] = 'already-applied';
            $this->record_font_mix_css_bundle_profile($stats);
            return $html;
        }

        $processed = $this->maybe_consolidate_font_mix_stylesheet_links_with_processor($html, $stats);
        if (is_string($processed) && '' !== $processed) {
            return $processed;
        }

        $stats['skipped_reason'] = 'html-api-font-mix-css-consolidation-failed';
        $this->record_font_mix_css_bundle_profile($stats);
        return $html;
    }

private function maybe_consolidate_font_mix_stylesheet_links_with_processor($html, array &$stats)
    {
        if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || false === stripos($html, '<link')) {
            return null;
        }

        try {
            $page_url = $this->get_current_request_url();
            $collected = $this->collect_contiguous_css_bundle_runs_with_processor(
                $html,
                function ($processor) use ($page_url) {
                    return $this->get_font_mix_css_bundle_candidate_from_link_processor($processor, $page_url);
                }
            );

            foreach ((array) ($collected['skipped'] ?? array()) as $candidate) {
                $skip = isset($candidate['skip']) ? (string) $candidate['skip'] : '';
                switch ($skip) {
                    case 'nonlocal':
                        $stats['skipped_nonlocal_count']++;
                        break;
                    case 'unreadable':
                        $stats['skipped_unreadable_count']++;
                        break;
                    case 'async':
                        $stats['skipped_async_count']++;
                        break;
                    case 'media':
                        $stats['skipped_media_count']++;
                        break;
                    case 'existing-bundle':
                        $stats['skipped_existing_bundle_count']++;
                        break;
                }
            }

            $stats['candidate_count'] = max(0, (int) ($collected['candidateCount'] ?? 0));
            $stats['source_urls'] = isset($collected['sourceUrls']) && is_array($collected['sourceUrls']) ? $collected['sourceUrls'] : array();
            $runs = array_values(array_filter((array) ($collected['runs'] ?? array()), static function ($run) {
                return is_array($run) && count($run) >= 2;
            }));

            if (empty($runs)) {
                $stats['skipped_reason'] = 'not-enough-contiguous-font-mix-css';
                $this->record_font_mix_css_bundle_profile($stats);
                return $html;
            }

            $plans = array();
            foreach ($runs as $run) {
                $assets = array_values(array_map(static function ($record) {
                    return isset($record['asset']) && is_array($record['asset']) ? $record['asset'] : array();
                }, $run));
                $bundle = $this->build_font_mix_css_bundle_file($assets);
                if (empty($bundle['success'])) {
                    continue;
                }

                $bundle_url = isset($bundle['url']) ? (string) $bundle['url'] : '';
                $bundle_file = isset($bundle['file']) ? (string) $bundle['file'] : '';
                if ('' === $bundle_url || '' === $bundle_file || !is_readable($bundle_file)) {
                    continue;
                }

                $plans[] = array(
                    'records' => $run,
                    'bundleUrl' => $bundle_url,
                    'bundleFile' => $bundle_file,
                    'bundleBytes' => (int) filesize($bundle_file),
                    'sourceBytesTotal' => isset($bundle['sourceBytesTotal']) ? (int) $bundle['sourceBytesTotal'] : 0,
                    'sourceDetails' => isset($bundle['sourceDetails']) && is_array($bundle['sourceDetails']) ? $bundle['sourceDetails'] : array(),
                    'delayedFontUrl' => isset($bundle['delayedFontUrl']) ? (string) $bundle['delayedFontUrl'] : '',
                    'delayedFontFile' => isset($bundle['delayedFontFile']) ? (string) $bundle['delayedFontFile'] : '',
                    'delayedFontBytes' => isset($bundle['delayedFontBytes']) ? (int) $bundle['delayedFontBytes'] : 0,
                    'delayedFontFaceBlocks' => isset($bundle['delayedFontFaceBlocks']) ? (int) $bundle['delayedFontFaceBlocks'] : 0,
                );
            }

            if (empty($plans)) {
                $stats['skipped_reason'] = 'contiguous-font-mix-css-bundle-build-failed';
                $this->record_font_mix_css_bundle_profile($stats);
                return $html;
            }

            $updated = $this->apply_contiguous_css_bundle_runs_with_processor($html, $plans, 'font-mix');
            if (!is_string($updated) || '' === $updated || $updated === $html) {
                $stats['skipped_reason'] = 'html-api-replacement-failed';
                $this->record_font_mix_css_bundle_profile($stats);
                return $html;
            }

            $stats['success'] = true;
            $stats['run_count'] = count($plans);
            $stats['replaced_link_count'] = array_sum(array_map(static function ($plan) { return count((array) ($plan['records'] ?? array())); }, $plans));
            $stats['bundle_urls'] = array_values(array_map(static function ($plan) { return (string) ($plan['bundleUrl'] ?? ''); }, $plans));
            $stats['bundle_files'] = array_values(array_map(static function ($plan) { return (string) ($plan['bundleFile'] ?? ''); }, $plans));
            $stats['bundle_url'] = (string) ($stats['bundle_urls'][0] ?? '');
            $stats['bundle_file'] = (string) ($stats['bundle_files'][0] ?? '');
            $stats['bundle_bytes'] = array_sum(array_map(static function ($plan) { return (int) ($plan['bundleBytes'] ?? 0); }, $plans));
            $stats['delayed_font_urls'] = array_values(array_filter(array_map(static function ($plan) { return (string) ($plan['delayedFontUrl'] ?? ''); }, $plans)));
            $stats['delayed_font_files'] = array_values(array_filter(array_map(static function ($plan) { return (string) ($plan['delayedFontFile'] ?? ''); }, $plans)));
            $stats['delayed_font_url'] = (string) ($stats['delayed_font_urls'][0] ?? '');
            $stats['delayed_font_file'] = (string) ($stats['delayed_font_files'][0] ?? '');
            $stats['delayed_font_bytes'] = array_sum(array_map(static function ($plan) { return (int) ($plan['delayedFontBytes'] ?? 0); }, $plans));
            $stats['delayed_font_face_blocks'] = array_sum(array_map(static function ($plan) { return (int) ($plan['delayedFontFaceBlocks'] ?? 0); }, $plans));
            $stats['source_bytes_total'] = array_sum(array_map(static function ($plan) { return (int) ($plan['sourceBytesTotal'] ?? 0); }, $plans));
            $stats['source_details'] = array_values(array_merge(...array_map(static function ($plan) { return (array) ($plan['sourceDetails'] ?? array()); }, $plans)));
            $this->record_font_mix_css_bundle_profile($stats);

            return $updated;
        } catch (\Throwable $e) {
            return null;
        }
    }

private function rewrite_link_processor_to_font_mix_css_bundle($processor, $bundle_url, $run_number = 1)
    {
        $this->clear_leftover_css_link_processor_attributes($processor);
        $processor->set_attribute('rel', 'stylesheet');
        $run_number = max(1, (int) $run_number);
        $processor->set_attribute('id', 1 === $run_number ? 'ultracache-font-mix-css-bundle' : 'ultracache-font-mix-css-bundle-' . $run_number);
        $processor->set_attribute('href', (string) $bundle_url);
        $processor->set_attribute('media', 'all');
        $processor->set_attribute('data-ultracache-font-mix-css-bundle', '1');
        $processor->set_attribute('data-ultracache-css-role', 'font-mix-bundle');
        $processor->set_attribute('data-ultracache-css-blocking-reason', 'font-mix-bundle-layout-risk');
    }

private function rewrite_link_processor_to_delayed_font_mix_icon_fonts($processor, $delayed_font_url, $run_number = 1)
    {
        $this->clear_leftover_css_link_processor_attributes($processor);
        $processor->set_attribute('rel', 'stylesheet');
        $run_number = max(1, (int) $run_number);
        $processor->set_attribute('id', 1 === $run_number ? 'ultracache-font-mix-delayed-icon-fonts' : 'ultracache-font-mix-delayed-icon-fonts-' . $run_number);
        $processor->set_attribute('href', (string) $delayed_font_url);
        $processor->set_attribute('media', 'print');
        $processor->set_attribute('data-ultracache-target-media', 'all');
        $processor->set_attribute('data-ultracache-delayed-icon-fonts', '1');
        $processor->set_attribute('data-ultracache-font-mix-delayed-icon-fonts', '1');
        $processor->set_attribute('data-ultracache-css-role', 'delayed-fonts-css');
        $processor->set_attribute('data-ultracache-css-async-reason', 'font-mix-delayed-icon-fonts');
    }

private function neutralize_link_processor_for_font_mix_css_source($processor, $source_url)
    {
        $this->clear_leftover_css_link_processor_attributes($processor);
        $processor->set_attribute('data-ultracache-font-mix-css-source-removed', '1');
        $processor->set_attribute('data-ultracache-font-mix-css-original-href', (string) $source_url);
    }
}
