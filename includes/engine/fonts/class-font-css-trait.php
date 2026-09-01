<?php
/**
 * Font CSS and rendered HTML transformation helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Font_CSS_Trait
{

    private function canonicalize_google_fonts_stylesheet_url($url)
    {
        $url = $this->decode_google_fonts_html_url((string) $url);
        if ('' === $url) {
            return '';
        }

        if (0 === strpos($url, '//')) {
            $url = 'https:' . $url;
        }

        if (!$this->is_google_fonts_stylesheet_url($url)) {
            return $url;
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if ('' === $scheme) {
            $url = 'https://' . ltrim($url, '/');
        } elseif ('http' === $scheme) {
            $url = set_url_scheme($url, 'https');
        }

        $fragment_pos = strpos($url, '#');
        if (false !== $fragment_pos) {
            $url = substr($url, 0, $fragment_pos);
        }

        return $url;
    }


    private function append_google_fonts_display_swap($url)
    {
        $url = $this->canonicalize_google_fonts_stylesheet_url($url);
        if ('' === $url || !$this->is_google_fonts_stylesheet_url($url)) {
            return $url;
        }

        $query = wp_parse_url($url, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $params);
            if (isset($params['display']) && 'swap' === strtolower((string) $params['display'])) {
                return $url;
            }
        }

        return add_query_arg('display', 'swap', $url);
    }


    private function apply_final_google_fonts_rewrite_before_cache_store($html)
    {
        if (!is_string($html) || '' === $html) {
            return $html;
        }

        $has_google_fonts_stylesheet = false !== stripos($html, 'fonts.googleapis.com');
        $has_google_fonts_hint = false !== stripos($html, 'fonts.gstatic.com');
        if (!$has_google_fonts_stylesheet && !$has_google_fonts_hint) {
            return $html;
        }

        $settings = $this->get_settings();
        if (!empty($settings['google_fonts_local_optimization'])) {
            if ($has_google_fonts_stylesheet) {
                return $this->rewrite_google_fonts_links_to_local_in_html($html);
            }

            return $this->remove_google_fonts_remote_resource_hints($html);
        }

        if ($has_google_fonts_stylesheet && !empty($settings['google_fonts_swap'])) {
            return $this->rewrite_google_fonts_display_swap_in_html($html);
        }

        return $html;
    }


    private function apply_final_font_display_rewrite_before_cache_store($html)
    {
        if (!is_string($html) || '' === $html || false === stripos($html, '<link') || false === stripos($html, '.css')) {
            return $html;
        }

        $policy = $this->get_font_optimization_policy();
        if (empty($policy['local_font_css_rewrite'])) {
            return $html;
        }

        return $this->normalize_linked_local_stylesheet_font_display_in_html($html);
    }

    private function should_force_template_buffer_for_google_fonts_cleanup()
    {
        if (is_admin() || wp_doing_ajax() || wp_is_json_request()) {
            return false;
        }

        $settings = $this->get_settings();
        $policy = $this->get_font_optimization_policy(is_array($settings) ? $settings : array());
        return !empty($settings['google_fonts_local_optimization']) || !empty($settings['google_fonts_swap']) || !empty($policy['local_font_css_rewrite']);
    }

    public function apply_live_google_fonts_output_cleanup($html)
    {
        if (!is_string($html) || '' === $html) {
            return $html;
        }

        $html = $this->apply_final_google_fonts_rewrite_before_cache_store($html);
        return $this->apply_final_font_display_rewrite_before_cache_store($html);
    }



    private function rewrite_google_fonts_display_swap_in_html($html)
    {
        if (false === stripos((string) $html, 'fonts.googleapis.com')) {
            return $html;
        }

        $processed = $this->rewrite_google_fonts_link_hrefs_with_processor($html, false);
        return is_string($processed) ? $processed : $html;
    }


    private function rewrite_google_fonts_links_to_local_in_html($html)
    {
        if (!is_string($html) || '' === $html) {
            return $html;
        }

        if (false === stripos($html, 'fonts.googleapis.com')) {
            return false !== stripos($html, 'fonts.gstatic.com') ? $this->remove_google_fonts_remote_resource_hints($html) : $html;
        }

        $processed = $this->rewrite_google_fonts_link_hrefs_with_processor($html, true);
        if (!is_string($processed)) {
            $processed = $html;
        }

        // After aggressive CSS replacement, the original Google Fonts stylesheet link may have
        // been folded into the generated bundle. In that case only preconnect/dns-prefetch hints
        // can remain in the final HTML. Remove those remote hints whenever local Google Fonts
        // optimization is active so cached HTML does not keep stray fonts.googleapis.com refs.
        return $this->remove_google_fonts_remote_resource_hints($processed);
    }


    private function remove_google_fonts_remote_resource_hints($html)
    {
        $html = (string) $html;
        if ('' === $html || (false === stripos($html, 'fonts.googleapis.com') && false === stripos($html, 'fonts.gstatic.com'))) {
            return $html;
        }

        return $this->remove_google_fonts_remote_resource_hints_with_processor($html);
    }


    private function remove_google_fonts_remote_resource_hints_with_processor($html)
    {
        if (!$this->html_tag_processor_available()) {
            return $html;
        }

        try {
            $processor = new WP_HTML_Tag_Processor((string) $html);
            $changed = false;

            while ($processor->next_tag('LINK')) {
                $href = $processor->get_attribute('href');
                if (null === $href || false === $href) {
                    continue;
                }

                $href = $this->decode_google_fonts_html_url((string) $href);
                if (false === stripos($href, 'fonts.googleapis.com') && false === stripos($href, 'fonts.gstatic.com')) {
                    continue;
                }

                $rel = strtolower(trim((string) $processor->get_attribute('rel')));
                if (!in_array($rel, array('dns-prefetch', 'preconnect'), true)) {
                    continue;
                }

                /*
                 * WP_HTML_Tag_Processor does not remove full nodes. Neutralize the
                 * remote font resource hint by removing the attributes that make the
                 * browser initiate the connection.
                 */
                $processor->remove_attribute('rel');
                $processor->remove_attribute('href');
                $processor->remove_attribute('crossorigin');
                $processor->set_attribute('data-ultracache-google-fonts-hint-removed', '1');
                $changed = true;
            }

            if (!$changed) {
                return $html;
            }

            $updated_html = $processor->get_updated_html();
            return is_string($updated_html) && '' !== $updated_html ? $updated_html : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }


    private function rewrite_google_fonts_link_hrefs_with_processor($html, $localize = false)
    {
        if (!$this->html_tag_processor_available()) {
            return null;
        }

        try {
            $processor = new WP_HTML_Tag_Processor((string) $html);
            $changed = false;

            while ($processor->next_tag()) {
                if ('LINK' !== strtoupper((string) $processor->get_tag())) {
                    continue;
                }

                $href = $processor->get_attribute('href');
                if (null === $href || false === $href) {
                    continue;
                }

                $href = $this->decode_google_fonts_html_url((string) $href);
                if (!$this->is_google_fonts_stylesheet_url($href)) {
                    continue;
                }

                $rel = strtolower((string) $processor->get_attribute('rel'));
                $as = strtolower((string) $processor->get_attribute('as'));
                if (false !== strpos($rel, 'preconnect') || false !== strpos($rel, 'dns-prefetch')) {
                    continue;
                }
                if ('' !== $rel && false === strpos($rel, 'stylesheet') && !('preload' === $rel && 'style' === $as)) {
                    continue;
                }

                $updated_href = $this->append_google_fonts_display_swap($href);
                if (!empty($localize)) {
                    $localized_href = $this->get_google_fonts_url_for_current_request($updated_href, false);
                    if (is_string($localized_href) && '' !== $localized_href) {
                        $updated_href = $localized_href;
                    }
                }

                if ($updated_href !== $href) {
                    $processor->set_attribute('href', $updated_href);
                    $changed = true;
                }
            }

            if (!$changed) {
                return null;
            }

            $updated_html = $processor->get_updated_html();
            return is_string($updated_html) && '' !== $updated_html ? $updated_html : null;
        } catch (\Throwable $e) {
            return null;
        }
    }


    private function safe_google_fonts_preg_replace_callback($pattern, callable $callback, $subject, $limit = -1)
    {
        $subject = (string) $subject;
        if ('' === $subject) {
            return $subject;
        }

        $result = @preg_replace_callback($pattern, $callback, $subject, (int) $limit);
        if (!is_string($result)) {
            $this->record_html_rewrite_safety_bailout('google-fonts-css-regex', 'preg-replace-failed');
            return $subject;
        }

        return $result;
    }



private function decode_google_fonts_html_url($url)
    {
        $url = str_replace('\\/', '/', (string) $url);
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = str_replace('&amp;', '&', $url);
        return trim($url);
    }


    private function optimize_self_hosted_font_css_links($html)
    {
        if (false === stripos($html, '<link') || false === stripos($html, '.css')) {
            return $html;
        }

        $policy = $this->get_font_optimization_policy();
        $processed = $this->optimize_self_hosted_font_css_links_with_processor($html);
        if (is_string($processed)) {
            return !empty($policy['local_font_css_rewrite']) ? $this->rewrite_inline_font_face_ttf_sources_to_linked_woff2($processed) : $processed;
        }

        return !empty($policy['local_font_css_rewrite']) ? $this->rewrite_inline_font_face_ttf_sources_to_linked_woff2($html) : $html;
    }


    private function normalize_linked_local_stylesheet_font_display_in_html($html)
    {
        $html = (string) $html;
        if ('' === $html || false === stripos($html, '<link') || false === stripos($html, '.css')) {
            return $html;
        }

        if (!$this->html_tag_processor_available()) {
            return $html;
        }

        try {
            $processor = new WP_HTML_Tag_Processor($html);
            $changed = false;

            while ($processor->next_tag('LINK')) {
                $rel = $processor->get_attribute('rel');
                if (!is_string($rel) || false === stripos($rel, 'stylesheet')) {
                    continue;
                }

                if (null !== $processor->get_attribute('data-ultracache-font-display-patch') || null !== $processor->get_attribute('data-ultracache-delayed-icon-fonts')) {
                    continue;
                }

                $href = $processor->get_attribute('href');
                if (!is_string($href) || '' === $href) {
                    continue;
                }

                $asset = $this->get_font_display_normalized_css_asset_for_current_request($href);
                if (empty($asset['css_url'])) {
                    continue;
                }

                $processor->set_attribute('href', esc_url((string) $asset['css_url']));
                $processor->set_attribute('data-ultracache-css-role', 'optimized-css');
                $processor->set_attribute('data-ultracache-font-display-normalized', '1');
                $processor->set_attribute('data-ultracache-css-blocking-reason', 'font-display-normalized-preserve-layout');
                $changed = true;
            }

            if (!$changed) {
                return $html;
            }

            $updated_html = $processor->get_updated_html();
            return is_string($updated_html) && '' !== $updated_html ? $updated_html : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }


    private function optimize_self_hosted_font_css_links_with_processor($html)
    {
        if (!$this->html_tag_processor_available()) {
            return null;
        }

        try {
            $processor = new WP_HTML_Tag_Processor((string) $html);
            $policy = $this->get_font_optimization_policy();
            $changed = false;
            $preload_urls = array();

            while ($processor->next_tag('LINK')) {
                $href = $processor->get_attribute('href');
                if (!is_string($href) || '' === $href) {
                    continue;
                }

                $rel = $processor->get_attribute('rel');
                if (!is_string($rel) || false === stripos($rel, 'stylesheet')) {
                    continue;
                }

                if (null !== $processor->get_attribute('data-ultracache-frontpage-css') || null !== $processor->get_attribute('data-ultracache-page-css-bundle')) {
                    continue;
                }

                $id = $processor->get_attribute('id');
                if (is_string($id) && in_array($id, array('ultracache-page-css-bundle', 'ultracache-frontpage-css'), true)) {
                    continue;
                }

                $normalized_href = $this->normalize_public_resource_url($href);
                if ('' !== $normalized_href) {
                    $normalized_path = strtolower((string) wp_parse_url($normalized_href, PHP_URL_PATH));
                    if (ultracache_generated_asset_reference_matches($normalized_path, array('css-bundles'))) {
                        continue;
                    }
                }

                $asset = $this->get_optimized_font_css_asset_for_current_request($href);
                if (empty($asset['css_url'])) {
                    continue;
                }

                if (!empty($asset['preload_urls']) && is_array($asset['preload_urls'])) {
                    foreach ($asset['preload_urls'] as $preload_url) {
                        if (count($preload_urls) >= 2) {
                            break;
                        }
                        $preload_url = esc_url_raw((string) $preload_url);
                        if ('' !== $preload_url && !in_array($preload_url, $preload_urls, true)) {
                            $preload_urls[] = $preload_url;
                        }
                    }
                }

                if (!empty($policy['delay_icon_fonts']) && !empty($asset['delayedFontUrl'])) {
                    $delayed_url = esc_url_raw((string) $asset['delayedFontUrl']);
                    if ('' !== $delayed_url) {
                        if (!$this->html_link_href_exists($html, $delayed_url)) {
                            continue;
                        }
                    }
                }

                $processor->set_attribute('href', esc_url($asset['css_url']));
                $asset_role = $this->get_generated_font_css_asset_role($asset);
                if ('' !== $asset_role) {
                    $processor->set_attribute('data-ultracache-css-role', $asset_role);
                    if ('optimized-css' === $asset_role) {
                        $processor->set_attribute('data-ultracache-css-blocking-reason', 'optimized-css-layout-risk');
                    } elseif ('font-css' === $asset_role) {
                        $processor->set_attribute('data-ultracache-css-blocking-reason', 'font-css-text-metric-risk');
                    }
                }
                $changed = true;
            }

            if (!$changed) {
                return null;
            }

            $updated_html = $processor->get_updated_html();
            if (!is_string($updated_html) || '' === $updated_html) {
                return null;
            }

            if (!empty($preload_urls)) {
                $updated_html = $this->inject_font_preload_links($updated_html, $preload_urls);
            }

            return !empty($policy['local_font_css_rewrite']) ? $this->rewrite_inline_font_face_ttf_sources_to_linked_woff2($updated_html) : $updated_html;
        } catch (\Throwable $e) {
            return null;
        }
    }


    private function rewrite_inline_font_face_ttf_sources_to_linked_woff2($html)
    {
        $html = (string) $html;
        if ('' === $html || false === stripos($html, '.ttf')) {
            return $html;
        }

        $registry = $this->build_linked_woff2_font_face_registry_from_html($html);

        $changed = false;
        $updated = $html;

        if (false !== stripos($updated, '@font-face')) {
            // 2.56.195: final generic TTF cleanup. Bundle replacement can remove
            // the stylesheet links that originally exposed matching WOFF2 @font-face
            // declarations, then leave inline @font-face TTF blocks in the final HTML.
            // Rewrite any local @font-face TTF declaration using the full active
            // stylesheet/manifest WOFF2 registry, not a vendor-specific target.
            $font_face_updated = preg_replace_callback(
                '~(<style\b[^>]*>)([\s\S]*?)(</style\s*>)~i',
                function ($style_matches) use ($registry, &$changed) {
                    $style_css = isset($style_matches[2]) ? (string) $style_matches[2] : '';
                    if ('' === $style_css || false === stripos($style_css, '@font-face')) {
                        return (string) ($style_matches[0] ?? '');
                    }

                    $rewritten_css = ultracache_css_rewrite_font_face_blocks($style_css, function ($block) use ($registry, &$changed) {
                        $block = (string) $block;
                        if ('' === $block || false === stripos($block, '.ttf')) {
                            return $block;
                        }

                        $rewritten = $this->rewrite_inline_font_face_ttf_css_with_woff2_registry($block, $registry);
                        if (is_string($rewritten) && $rewritten !== $block) {
                            $changed = true;
                            return $rewritten;
                        }

                        return $block;
                    });

                    if ($rewritten_css === $style_css) {
                        return (string) ($style_matches[0] ?? '');
                    }

                    return (string) $style_matches[1] . $rewritten_css . (string) $style_matches[3];
                },
                $updated
            );

            if (is_string($font_face_updated) && '' !== $font_face_updated) {
                $updated = $font_face_updated;
            }
        }

        if (false !== stripos($updated, '.ttf')) {
            $token_updated = $this->rewrite_generic_local_ttf_url_tokens_to_woff2($updated, $registry);
            if (is_string($token_updated) && $token_updated !== $updated) {
                $changed = true;
                $updated = $token_updated;
            }
        }

        return $changed ? $updated : $html;
    }


    private function rewrite_generic_local_ttf_url_tokens_to_woff2($html, array $registry)
    {
        $html = (string) $html;
        if ('' === $html || false === stripos($html, '.ttf')) {
            return $html;
        }

        // Only rewrite local public font URLs. This is intentionally generic: the
        // target is .ttf, not a specific vendor directory. URL tokens outside
        // @font-face are handled only when a safe same-path .woff2 exists; family
        // matching remains inside the @font-face block pass where family/weight/style
        // are known.
        $content_marker = function_exists('ultracache_content_public_path') ? trim(ultracache_content_public_path(), '/') : 'wp-content';
        $content_marker = '' !== $content_marker ? preg_quote($content_marker, '~') : 'wp-content';
        $escaped_content_marker = str_replace('/', '\\/', $content_marker);
        $patterns = array(
            '~https?://[^\s"\'<>)]+/' . $content_marker . '/[^\s"\'<>)]+?\.ttf(?:\?[^\s"\'<>)]+)?~i',
            '~/' . $content_marker . '/[^\s"\'<>)]+?\.ttf(?:\?[^\s"\'<>)]+)?~i',
            '~https?:\\/\\/[^\s"\'<>)]+\\/' . $escaped_content_marker . '\\/[^\s"\'<>)]+?\.ttf(?:\?[^\s"\'<>)]+)?~i',
            '~\\/' . $escaped_content_marker . '\\/[^\s"\'<>)]+?\.ttf(?:\?[^\s"\'<>)]+)?~i',
        );

        $updated = $html;
        foreach ($patterns as $pattern) {
            $updated = preg_replace_callback($pattern, function ($matches) {
                $original = isset($matches[0]) ? (string) $matches[0] : '';
                if ('' === $original) {
                    return $original;
                }

                $slash_escaped = false !== strpos($original, '\\/');
                $candidate = $slash_escaped ? str_replace('\\/', '/', $original) : $original;
                $replacement = $this->find_same_path_preferred_font_url_for_ttf_url($candidate);
                if ('' === $replacement) {
                    return $original;
                }

                return $this->prepare_font_url_for_inline_replacement($replacement, $slash_escaped);
            }, $updated);

            if (!is_string($updated) || '' === $updated) {
                return $html;
            }
        }

        return $updated;
    }


    private function rewrite_inline_font_face_ttf_css_with_woff2_registry($css, array $registry)
    {
        $css = (string) $css;
        if ('' === $css || false === stripos($css, '@font-face') || false === stripos($css, '.ttf')) {
            return $css;
        }

        $changed = false;
        $updated = ultracache_css_rewrite_font_face_blocks($css, function ($block) use ($registry, &$changed) {
            $block = (string) $block;
            if ('' === $block || false === stripos($block, '.ttf')) {
                return $block;
            }

            $family_key = $this->normalize_font_face_family_key($this->extract_font_face_css_declaration($block, 'font-family'));
            if ('' === $family_key) {
                return $this->normalize_font_face_display_in_css($block);
            }

            $style_key = $this->normalize_font_face_style_key($this->extract_font_face_css_declaration($block, 'font-style'));
            $weight_range = $this->normalize_font_face_weight_range($this->extract_font_face_css_declaration($block, 'font-weight'));
            $replacement_url = $this->find_same_path_preferred_font_url_for_font_face_ttf_block($block);
            if ('' === $replacement_url) {
                $replacement_url = $this->find_matching_woff2_font_face_url($registry, $family_key, $style_key, $weight_range);
            }
            if ('' === $replacement_url) {
                return $this->normalize_font_face_display_in_css($block);
            }

            $slash_escaped = false !== strpos($block, '\\/');
            $replacement_url = $this->prepare_font_url_for_inline_replacement($replacement_url, $slash_escaped);
            if ('' === $replacement_url) {
                return $this->normalize_font_face_display_in_css($block);
            }

            $replacement_format = $this->get_font_format_for_font_url($replacement_url);
            $new_src = 'url(' . $replacement_url . ') format("' . $replacement_format . '")';
            $rewritten = ultracache_font_css_replace_declaration_value($block, 'src', $new_src);
            if (!is_string($rewritten) || '' === $rewritten || $rewritten === $block) {
                return $this->normalize_font_face_display_in_css($block);
            }

            $rewritten = $this->normalize_font_face_display_in_css($rewritten);
            if ($rewritten !== $block) {
                $changed = true;
            }

            return $rewritten;
        });

        return is_string($updated) && $changed ? $updated : $css;
    }

    private function rewrite_font_face_ttf_sources_to_preferred_formats($css, $base_url = '')
    {
        $css = (string) $css;
        if ('' === $css || false === stripos($css, '.ttf')) {
            return $css;
        }

        $base_url = '' !== (string) $base_url ? (string) $base_url : home_url('/');
        $changed = false;
        $updated = ultracache_css_rewrite_font_face_blocks($css, function ($block) use ($base_url, &$changed) {
            $block = (string) $block;
            if ('' === $block || false === stripos($block, '.ttf')) {
                return $block;
            }

            $block_changed = false;
            $src = ultracache_font_css_extract_declaration($block, 'src');
            $rewritten = $block;
            if ('' !== trim($src)) {
                $items = function_exists('ultracache_font_css_split_src_items') ? ultracache_font_css_split_src_items($src) : array_map('trim', explode(',', $src));
                if (!empty($items)) {
                    $has_better_source = false;
                    foreach ($items as $item) {
                        $item_lc = strtolower((string) $item);
                        if (false !== strpos($item_lc, '.woff2') || false !== strpos($item_lc, "format('woff2')") || false !== strpos($item_lc, 'format("woff2")') || preg_match('/format\(\s*woff2\s*\)/i', $item_lc)) {
                            $has_better_source = true;
                            break;
                        }
                        if (false !== strpos($item_lc, '.woff') || false !== strpos($item_lc, "format('woff')") || false !== strpos($item_lc, 'format("woff")') || preg_match('/format\(\s*woff\s*\)/i', $item_lc)) {
                            $has_better_source = true;
                        }
                    }

                    $seen = array();
                    $kept = array();
                    foreach ($items as $item) {
                        $item = trim((string) $item);
                        if ('' === $item) {
                            continue;
                        }

                        $is_ttf_item = (false !== stripos($item, '.ttf')) || (bool) preg_match('/format\(\s*["\']?truetype["\']?\s*\)/i', $item);
                        if ($is_ttf_item) {
                            $ttf_url = $this->extract_first_ttf_url_from_css_src_item($item, $base_url);
                            $replacement_url = '' !== $ttf_url ? $this->find_same_path_preferred_font_url_for_ttf_url($ttf_url) : '';
                            if ('' !== $replacement_url) {
                                $format = $this->get_font_format_for_font_url($replacement_url);
                                $item = 'url(' . esc_url_raw($replacement_url) . ') format("' . $format . '")';
                                $block_changed = true;
                            } elseif ($has_better_source) {
                                $block_changed = true;
                                continue;
                            }
                        }

                        $key = strtolower((string) preg_replace('/\s+/', '', $item));
                        if (isset($seen[$key])) {
                            $block_changed = true;
                            continue;
                        }
                        $seen[$key] = true;
                        $kept[] = $item;
                    }

                    if (!empty($kept)) {
                        $candidate = ultracache_font_css_replace_declaration_value($block, 'src', implode(',', $kept));
                        if (is_string($candidate) && '' !== $candidate) {
                            $rewritten = $candidate;
                        }
                    }
                }
            }

            if (!is_string($rewritten) || '' === $rewritten) {
                return $block;
            }

            $rewritten = $this->normalize_font_face_display_in_css($rewritten);
            if ($block_changed || $rewritten !== $block) {
                $changed = true;
                return $rewritten;
            }

            return $block;
        });

        return is_string($updated) && $changed ? $updated : $css;
    }



private function prepare_font_url_for_inline_replacement($url, $slash_escaped = false)
    {
        $url = $this->normalize_public_resource_url((string) $url);
        if ('' === $url) {
            return '';
        }

        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $is_local = '' !== $host && (
            function_exists('ultracache_is_local_site_url')
                ? ultracache_is_local_site_url($url)
                : $host === strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST))
        );
        if ($is_local) {
            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            $query = (string) wp_parse_url($url, PHP_URL_QUERY);
            if ('' !== $path) {
                $url = $path . ('' !== $query ? ('?' . $query) : '');
            }
        }

        $url = esc_url_raw($url);
        if ('' === $url) {
            return '';
        }

        return $slash_escaped ? str_replace('/', '\/', $url) : $url;
    }

    private function inject_runtime_font_css_url_map($html)
    {
        if (!is_string($html) || '' === $html) {
            return $html;
        }

        $map = array();

        $html_map = $this->build_runtime_font_css_url_map_from_html($html);
        if (!empty($html_map)) {
            $this->remember_runtime_font_css_url_mappings($html_map);
            $map = array_merge($map, $html_map);
        }

        if (false !== stripos($html, 'data-ultracache-page-css-bundle=') || false !== stripos($html, 'data-ultracache-frontpage-css=')) {
            $bundle_map = $this->build_runtime_font_css_url_map_from_bundle_manifest();
            if (!empty($bundle_map)) {
                $this->remember_runtime_font_css_url_mappings($bundle_map);
                $map = array_merge($map, $bundle_map);
            }
        }

        $map = $this->normalize_runtime_font_css_url_map($map);
        $this->save_runtime_local_font_css_url_map($map);

        return $html;
    }

    private function inject_runtime_font_display_cssom_patch($html)
    {
        return $html;
    }


    private function normalize_inline_style_font_display_in_html($html)
    {
        if (!is_string($html) || '' === $html || false === stripos($html, '<style') || false === stripos($html, '@font-face')) {
            return $html;
        }

        $updated = preg_replace_callback(
            '/<style\b([^>]*)>([\s\S]*?)<\/style>/i',
            function ($matches) {
                $attrs = isset($matches[1]) ? (string) $matches[1] : '';
                $css = isset($matches[2]) ? (string) $matches[2] : '';

                if ('' === $css || false === stripos($css, '@font-face')) {
                    return (string) $matches[0];
                }

                if (preg_match('/\btype\s*=\s*(["\'])(.*?)\1/i', $attrs, $type_match)) {
                    $type = strtolower(trim((string) ($type_match[2] ?? '')));
                    if ('' !== $type && 'text/css' !== $type) {
                        return (string) $matches[0];
                    }
                }

                $patched_css = $this->normalize_font_face_display_in_css($css);
                if (!is_string($patched_css) || $patched_css === $css) {
                    return (string) $matches[0];
                }

                // Intentional final HTML optimization output: this rewrites an already-rendered inline style block to normalize @font-face font-display declarations.
                // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Rewrites an existing inline style block in the final frontend document; no plugin asset is printed directly.
                return '<style' . $attrs . ' data-ultracache-inline-font-display-patched="1">' . $patched_css . '</style>';
            },
            $html
        );

        return is_string($updated) && '' !== $updated ? $updated : $html;
    }


    private function rewrite_self_hosted_font_css_content($css, $source_url, ?array &$google_import_stats = null, $normalize_font_display = true)
    {
        $css = $this->normalize_protocol_relative_urls_in_css($css, $source_url);
        $css = $this->rewrite_google_fonts_imports_in_css($css, $source_url, $google_import_stats);
        return $normalize_font_display ? $this->normalize_font_face_display_in_css($css) : $css;
    }


    private function rewrite_google_fonts_imports_in_css($css, $source_url, ?array &$stats = null)
    {
        $css = (string) $css;
        if ('' === $css || false === stripos($css, 'fonts.googleapis.com')) {
            return $css;
        }

        if (null === $stats) {
            $stats = array();
        }

        foreach (array('found', 'localized', 'displaySwapOnly', 'unchanged', 'remainingRemote') as $key) {
            if (!isset($stats[$key])) {
                $stats[$key] = 0;
            }
        }

        $settings = $this->get_settings();
        $localize = !empty($settings['google_fonts_local_optimization']);
        $swap = !empty($settings['google_fonts_swap']) || $localize;

        $updated = $this->safe_google_fonts_preg_replace_callback('/@import\s+(?:url\(\s*"([^"]+)"\s*\)|url\(\s*\'([^\']+)\'\s*\)|url\(\s*([^)]+?)\s*\)|"([^"]+)"|\'([^\']+)\')([^;]*);/i', function ($matches) use ($source_url, $localize, $swap, &$stats) {
            $import_url = '';
            for ($i = 1; $i <= 5; $i++) {
                if (isset($matches[$i]) && '' !== trim((string) $matches[$i])) {
                    $import_url = trim((string) $matches[$i]);
                    break;
                }
            }

            if ('' === $import_url) {
                return (string) $matches[0];
            }

            $absolute = $this->absolutize_public_resource_url($this->decode_google_fonts_html_url($import_url), $source_url);
            if ('' === $absolute || !$this->is_google_fonts_stylesheet_url($absolute)) {
                return (string) $matches[0];
            }

            $stats['found']++;
            $suffix = isset($matches[6]) ? trim((string) $matches[6]) : '';
            $rewritten_url = $swap ? $this->append_google_fonts_display_swap($absolute) : $absolute;
            $localized_url = '';

            if ($localize) {
                $localized_url = $this->get_google_fonts_url_for_current_request($rewritten_url, true);
                if (is_string($localized_url) && '' !== $localized_url) {
                    $rewritten_url = $localized_url;
                    $stats['localized']++;
                } else {
                    $stats['remainingRemote']++;
                }
            } elseif ($rewritten_url !== $absolute) {
                $stats['displaySwapOnly']++;
            }

            if ($rewritten_url === $import_url) {
                $stats['unchanged']++;
                return (string) $matches[0];
            }

            return '@import url("' . esc_url_raw($rewritten_url) . '")' . ('' !== $suffix ? ' ' . $suffix : '') . ';';
        }, $css);

        return is_string($updated) && '' !== $updated ? $updated : $css;
    }


    private function normalize_font_face_display_in_css($css, &$stats = null)
    {
        $css = (string) $css;
        if (null === $stats || !is_array($stats)) {
            $stats = array();
        }
        foreach (array('fontFaceBlocksScanned', 'fontDisplayAdded', 'fontDisplayExisting', 'fontFaceBlocksChanged') as $key) {
            if (!isset($stats[$key])) {
                $stats[$key] = 0;
            }
        }

        if ('' === $css || false === stripos($css, '@font-face')) {
            return $css;
        }

        return ultracache_css_rewrite_font_face_blocks(
            $css,
            function ($block) use (&$stats) {
                $block = (string) $block;
                $stats['fontFaceBlocksScanned']++;
                $declaration_scan = ultracache_font_css_scan_declarations($block);
                if (!empty($declaration_scan['malformed'])) {
                    return $block;
                }

                $display = ultracache_font_css_find_declaration($block, 'font-display');
                if (!empty($display)) {
                    $value = strtolower(trim(ultracache_font_css_extract_declaration($block, 'font-display')));
                    $stats['fontDisplayExisting']++;
                    if (in_array($value, array('auto', 'block'), true)) {
                        $updated_block = ultracache_font_css_replace_declaration_value($block, 'font-display', 'swap');
                        if ($updated_block !== $block) {
                            $stats['fontFaceBlocksChanged']++;
                            return $updated_block;
                        }
                    }
                    return $block;
                }

                $updated_block = ultracache_font_css_append_declaration($block, 'font-display: swap;');
                if ($updated_block !== $block) {
                    $stats['fontDisplayAdded']++;
                    $stats['fontFaceBlocksChanged']++;
                    return $updated_block;
                }

                return $block;
            }
        );
    }


    private function normalize_protocol_relative_urls_in_css($css, $source_url)
    {
        return (string) preg_replace_callback(
            '/url\(([^)]+)\)/i',
            function ($matches) use ($source_url) {
                $raw = trim((string) $matches[1]);
                $quote = '';
                if ('' !== $raw && ('"' === $raw[0] || "'" === $raw[0])) {
                    $quote = $raw[0];
                    $raw = trim($raw, "\"'");
                }

                $normalized = $this->absolutize_public_resource_url($raw, $source_url);
                if ('' === $normalized) {
                    $normalized = $raw;
                }

                return 'url(' . $quote . $normalized . $quote . ')';
            },
            $css
        );
    }
}
