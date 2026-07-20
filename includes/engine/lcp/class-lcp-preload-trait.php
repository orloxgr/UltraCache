<?php
/**
 * LCP preload candidate parsing, validation, and injection helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_LCP_Preload_Trait
{
private function inject_manual_critical_preload_links($html, array $settings = array())
    {
        if (false === stripos((string) $html, '</head>')) {
            return $html;
        }

        $links = array();
        $resource_lines = isset($settings['critical_resource_preload_list']) && is_array($settings['critical_resource_preload_list']) ? $settings['critical_resource_preload_list'] : array();

        foreach ($resource_lines as $line) {
            $candidate = $this->parse_critical_preload_line($line, '');
            if (!empty($candidate['url'])) {
                if ($this->should_skip_sr7_generated_manual_preload($candidate, $html, $settings)) {
                    continue;
                }
                $links[] = $candidate;
            }
        }

        return $this->inject_critical_preload_link_candidates($html, $links);
    }

private function parse_critical_preload_line($line, $forced_as = '')
    {
        $line = trim((string) $line);
        if ('' === $line || '#' === $line[0]) {
            return array('url' => '', 'as' => '');
        }

        $as = strtolower(trim((string) $forced_as));
        $url = $line;

        if (preg_match('/^(script|style|image|font|fetch|document)\s+(.+)$/i', $line, $matches)) {
            $as = strtolower((string) $matches[1]);
            $url = trim((string) $matches[2]);
        }

        $url = $this->absolutize_public_resource_url($url, home_url('/'));
        if ('' === $url || 0 === strpos($url, 'data:') || 0 === strpos($url, '#')) {
            return array('url' => '', 'as' => '');
        }

        if ('' === $as) {
            $as = $this->infer_preload_as_from_url($url);
        }

        return array('url' => $url, 'as' => $as);
    }

private function infer_preload_as_from_url($url)
    {
        $path = strtolower((string) wp_parse_url((string) $url, PHP_URL_PATH));
        if (preg_match('/\.css$/', $path)) {
            return 'style';
        }
        if (preg_match('/\.js$/', $path)) {
            return 'script';
        }
        if (preg_match('/\.(woff2?|ttf|otf)$/', $path)) {
            return 'font';
        }
        if (preg_match('/\.(avif|webp|png|jpe?g|gif|svg)$/', $path)) {
            return 'image';
        }

        return 'fetch';
    }

private function inject_critical_preload_link_candidates($html, array $candidates)
    {
        if (empty($candidates) || false === stripos((string) $html, '</head>')) {
            return $html;
        }

        $tags = array();
        $seen = array();
        foreach ($candidates as $candidate) {
            $url = isset($candidate['url']) ? esc_url((string) $candidate['url']) : '';
            $as = isset($candidate['as']) ? strtolower(trim((string) $candidate['as'])) : 'fetch';
            if ('' === $url || isset($seen[$url])) {
                continue;
            }
            if ($this->html_link_href_exists($html, $url)) {
                continue;
            }

            $seen[$url] = true;
            if (!in_array($as, array('script', 'style', 'image', 'font', 'fetch', 'document'), true)) {
                $as = 'fetch';
            }

            $attrs = 'rel="preload" as="' . esc_attr($as) . '" href="' . $url . '"';
            if ('image' === $as) {
                $attrs .= ' fetchpriority="high"';
            } elseif ('font' === $as) {
                $attrs .= ' type="font/woff2" crossorigin';
            } elseif ('fetch' === $as) {
                $attrs .= ' crossorigin';
            }

            // This injects a final-HTML preload discovered after LCP/critical-chain analysis.
            // It is not an enqueued stylesheet/script, so wp_enqueue_style() / wp_enqueue_script() is not applicable.
            // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
            $tags[] = '<link ' . $attrs . ' data-ultracache-critical-chain="1">';
        }

        if (empty($tags)) {
            return $html;
        }

        return $this->insert_html_before_closing_head($html, implode("\n", $tags));
    }

private function inject_safe_lcp_priority_preloads($html)
    {
        if (!is_string($html) || '' === $html || false === stripos($html, '</head')) {
            return $html;
        }

        $manual_candidate = $this->find_manual_lcp_candidate($html);
        if (null !== $manual_candidate && !empty($manual_candidate['url'])) {
            return $this->inject_lcp_preload_link($html, $manual_candidate['url']);
        }

        if ($this->has_manual_lcp_selector_configuration()) {
            $manual_selector_candidates = $this->find_manual_lcp_hero_selector_candidates($html, 1);
            if (!empty($manual_selector_candidates) && !empty($manual_selector_candidates[0]['url'])) {
                return $this->inject_lcp_preload_link($html, $manual_selector_candidates[0]['url']);
            }

            // A selector identifies an element, not an arbitrary descendant image.
            // Dynamic/text targets wait for browser observation instead of falling
            // through to slider-specific or generic image guessing.
            return $html;
        }

        $sr7_confirmed_module_bg_candidates = $this->find_confirmed_sr7_module_bg_lcp_preload_candidates($html, 1);
        if (!empty($sr7_confirmed_module_bg_candidates)) {
            return $this->inject_lcp_preload_link($html, $sr7_confirmed_module_bg_candidates[0]['url']);
        }

        $sr7_first_slide_candidates = $this->find_sr7_first_slide_lcp_candidates($html, 1);
        if (!empty($sr7_first_slide_candidates)) {
            return $this->inject_lcp_preload_link($html, $sr7_first_slide_candidates[0]['url']);
        }

        $sr7_markup_candidates = $this->find_marked_sr7_lcp_preload_candidates($html, 1);
        if (!empty($sr7_markup_candidates)) {
            return $this->inject_lcp_preload_link($html, $sr7_markup_candidates[0]['url']);
        }

        $sr7_static_candidates = $this->find_sr7_static_slide_lcp_candidates($html, 1);
        if (!empty($sr7_static_candidates)) {
            return $this->inject_lcp_preload_link($html, $sr7_static_candidates[0]['url']);
        }

        $sr7_candidate = $this->find_best_sr7_lcp_candidate($html);
        if (null !== $sr7_candidate && !empty($sr7_candidate['url'])) {
            if ($this->is_sr7_generated_image_list_url($sr7_candidate['url']) && empty($sr7_candidate['sr7_verified_first_slide'])) {
                return $html;
            }
            return $this->inject_lcp_preload_link($html, $sr7_candidate['url']);
        }

        return $html;
    }

private function html_has_equivalent_lcp_image_preload($html, $src)
    {
        if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || false === stripos($html, '<link')) {
            return false;
        }

        $normalized_src = strtolower($this->normalize_public_resource_url($src));
        if ('' === $normalized_src) {
            return false;
        }

        try {
            $processor = new WP_HTML_Tag_Processor($html);
            while ($processor->next_tag('LINK')) {
                $rel = $processor->get_attribute('rel');
                $as = $processor->get_attribute('as');
                $href = $processor->get_attribute('href');
                if (!is_string($rel) || !is_string($as) || !is_string($href)) {
                    continue;
                }

                if (false === stripos($rel, 'preload') || 'image' !== strtolower(trim($as))) {
                    continue;
                }

                if (strtolower($this->normalize_public_resource_url($href)) === $normalized_src) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

private function inject_lcp_preload_link($html, $src)
    {
        $sr7_source_url = $this->resolve_sr7_generated_image_list_source_url($src, $html);
        if ('' !== $sr7_source_url) {
            $src = $sr7_source_url;
        }

        $src = $this->prefer_existing_lcp_preload_equivalent_url($src);
        $src = esc_url($this->normalize_public_resource_url($src));
        if ('' === $src) {
            return $html;
        }

        // 2.56.192: never preload SR7 generated image-list placeholders under
        // /revslider/o/. They can be valid generated files but SR7 often does not
        // consume them immediately, which creates Chrome "preloaded but not used"
        // warnings. The actual rewritten first-slide/source image can still get
        // fetchpriority/runtime priority; ambiguous helper assets should not get
        // a network preload.
        if ($this->is_sr7_generated_image_list_url($src)) {
            return $html;
        }

        if ($this->should_skip_lcp_preload_url($src)) {
            return $html;
        }

        $is_same_origin = $this->is_same_origin_public_resource_url($src);
        $mime_type = $this->get_lcp_preload_image_type($src);

        $processed = $this->ensure_lcp_preload_link_with_processor($html, $src, $is_same_origin, $mime_type);
        if (is_string($processed)) {
            return $processed;
        }

        if ($this->html_has_equivalent_lcp_image_preload($html, $src)) {
            return $this->cleanup_ambiguous_sr7_generated_lcp_preloads($html);
        }

        $preload_href = esc_url($src);
        if ('' === $preload_href) {
            return $html;
        }

        // Intentional final HTML optimization output: LCP image preloads are inserted after analyzing the rendered hero/slider markup.
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
        $link = '<link rel="preload" as="image" href="' . $preload_href . '"';
        if ('' !== $mime_type) {
            $link .= ' type="' . esc_attr($mime_type) . '"';
        }
        $link .= ' fetchpriority="high" data-ultracache-lcp-preload="1" data-ultracache-lcp-preload-reason="lcp-image-priority"';
        if (!$is_same_origin) {
            $link .= ' crossorigin="anonymous"';
        }
        $link .= '>';
        if (false === stripos($html, '</head>')) {
            return $html;
        }

        return $this->insert_html_before_closing_head($html, $link);
    }

private function ensure_lcp_preload_link_with_processor($html, $src, $is_same_origin = false, $mime_type = '')
    {
        if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || false === stripos($html, '<link')) {
            return null;
        }

        try {
            $processor = new WP_HTML_Tag_Processor($html);
            $changed = false;
            $found = false;
            $normalized_src = $this->normalize_public_resource_url($src);

            while ($processor->next_tag('LINK')) {
                $rel = $processor->get_attribute('rel');
                $as = $processor->get_attribute('as');
                $href = $processor->get_attribute('href');
                if (!is_string($rel) || !is_string($as) || !is_string($href)) {
                    continue;
                }

                if (false === stripos($rel, 'preload') || 'image' !== strtolower(trim($as))) {
                    continue;
                }

                if ($this->normalize_public_resource_url($href) !== $normalized_src) {
                    continue;
                }

                $found = true;
                if ('high' !== (string) $processor->get_attribute('fetchpriority')) {
                    $processor->set_attribute('fetchpriority', 'high');
                    $changed = true;
                }
                if ('' !== (string) $mime_type && null === $processor->get_attribute('type')) {
                    $processor->set_attribute('type', (string) $mime_type);
                    $changed = true;
                }
                if ('1' !== (string) $processor->get_attribute('data-ultracache-lcp-preload')) {
                    $processor->set_attribute('data-ultracache-lcp-preload', '1');
                    $changed = true;
                }
                if (null === $processor->get_attribute('data-ultracache-lcp-preload-reason')) {
                    $processor->set_attribute('data-ultracache-lcp-preload-reason', 'lcp-image-priority');
                    $changed = true;
                }

                $existing_crossorigin = $processor->get_attribute('crossorigin');
                if ($is_same_origin && null !== $existing_crossorigin) {
                    $processor->remove_attribute('crossorigin');
                    $changed = true;
                }
                if (!$is_same_origin && null === $existing_crossorigin) {
                    $processor->set_attribute('crossorigin', 'anonymous');
                    $changed = true;
                }
                break;
            }

            if (!$found) {
                return null;
            }

            if (!$changed) {
                return (string) $html;
            }

            $updated_html = $processor->get_updated_html();
            return is_string($updated_html) && '' !== $updated_html ? $updated_html : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

private function is_same_origin_public_resource_url($url)
    {
        $url = $this->normalize_public_resource_url($url);
        if ('' === $url) {
            return false;
        }

        $url_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ('' === $url_host || '' === $home_host) {
            return 0 === strpos($url, '/');
        }

        return $url_host === $home_host;
    }

private function get_lcp_preload_image_type($url)
    {
        $path = strtolower((string) wp_parse_url((string) $url, PHP_URL_PATH));
        if (preg_match('/\.avif$/i', $path)) {
            return 'image/avif';
        }
        if (preg_match('/\.webp$/i', $path)) {
            return 'image/webp';
        }
        if (preg_match('/\.jpe?g$/i', $path)) {
            return 'image/jpeg';
        }
        if (preg_match('/\.png$/i', $path)) {
            return 'image/png';
        }
        if (preg_match('/\.gif$/i', $path)) {
            return 'image/gif';
        }
        if (preg_match('/\.svg$/i', $path)) {
            return 'image/svg+xml';
        }

        return '';
    }

private function should_skip_lcp_preload_url($url)
    {
        $url = $this->normalize_public_resource_url($url);
        if ('' === $url) {
            return true;
        }

        return !$this->is_lcp_candidate_image_url($url);
    }

private function prefer_existing_lcp_preload_equivalent_url($url)
    {
        $url = $this->normalize_public_resource_url($url);
        if ('' === $url || !$this->is_lcp_candidate_image_url($url)) {
            return $url;
        }

        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if (preg_match('/\.(?:avif|webp|svg)$/i', $path)) {
            return $url;
        }

        $settings = $this->get_settings();
        $media_enabled = !empty($settings['media_optimization_enabled']) || !empty($settings['mediaOptimizationEnabled']);
        if (!$media_enabled) {
            return $url;
        }

        $preferred = $this->prefer_existing_nextgen_public_image_url($url);
        $preferred = $this->normalize_public_resource_url($preferred);
        if ('' === $preferred || $preferred === $url || !$this->is_lcp_candidate_image_url($preferred)) {
            return $url;
        }

        $preferred_path = (string) wp_parse_url($preferred, PHP_URL_PATH);
        if ('' === $path || '' === $preferred_path) {
            return $url;
        }

        $original_base = preg_replace('/\.(?:png|jpe?g|webp|avif)$/i', '', $path);
        $preferred_base = preg_replace('/\.(?:png|jpe?g|webp|avif)$/i', '', $preferred_path);
        if (!is_string($original_base) || !is_string($preferred_base) || '' === $original_base || '' === $preferred_base) {
            return $url;
        }

        $uploads_marker = $this->get_uploads_public_path_marker();
        $avif_marker = $this->get_ultracache_optimized_images_public_path_marker('avif');
        $webp_marker = $this->get_ultracache_optimized_images_public_path_marker('webp');
        $original_relative = false !== strpos($original_base, $uploads_marker) ? substr($original_base, strpos($original_base, $uploads_marker) + strlen($uploads_marker)) : '';
        $preferred_relative = '';
        if (false !== strpos($preferred_base, $avif_marker)) {
            $preferred_relative = substr($preferred_base, strpos($preferred_base, $avif_marker) + strlen($avif_marker));
        } elseif (false !== strpos($preferred_base, $webp_marker)) {
            $preferred_relative = substr($preferred_base, strpos($preferred_base, $webp_marker) + strlen($webp_marker));
        }

        if ('' === $original_relative || '' === $preferred_relative || $original_relative !== $preferred_relative) {
            return $url;
        }

        return $preferred;
    }
}
