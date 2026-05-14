<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!trait_exists('Ultra_Cache_Engine_LCP_Slider_Trait')) {
    trait Ultra_Cache_Engine_LCP_Slider_Trait
    {
        private function record_analytics_sr7_lcp(array $stats = array())
        {
            self::mutate_analytics(function ($analytics) use ($stats) {
                $analytics['sr7LcpDetected'] = (int) ($analytics['sr7LcpDetected'] ?? 0) + max(0, (int) ($stats['detected'] ?? 0));
                $analytics['sr7LcpPreloadsInjected'] = (int) ($analytics['sr7LcpPreloadsInjected'] ?? 0) + max(0, (int) ($stats['preloadsInjected'] ?? 0));
                $analytics['sr7LcpSkipped'] = (int) ($analytics['sr7LcpSkipped'] ?? 0) + max(0, (int) ($stats['skipped'] ?? 0));
                $analytics['sr7LcpUnresolved'] = (int) ($analytics['sr7LcpUnresolved'] ?? 0) + max(0, (int) ($stats['unresolved'] ?? 0));
                return $analytics;
            });
        }

        private function get_slider_hero_protected_fragments()
        {
            $fragments = array(
                'revslider',
                'sliderrevolution',
                'slider-revolution',
                'revolution',
                'sr7',
                'rs6',
                'rs7',
                'tptools',
                'tp-tools',
                '/plugins/revslider/',
                '/plugins/slider-revolution/',
                '/wp-content/uploads/revslider/',
                'wp-block-themepunch-revslider',
                'swiper',
                'swiper-bundle',
                'swiper-container',
                'swiper-wrapper',
                'slick',
                'slick-slider',
                'splide',
                'splide__track',
                'owl.carousel',
                'owl-carousel',
                'owlcarousel',
                'flickity',
                'keen-slider',
                'bxslider',
                'masterslider',
                'master-slider',
                'layerslider',
                'layer-slider',
                'metaslider',
                'smartslider',
                'smart-slider',
                'n2-ss',
                'soliloquy',
                'royalslider',
                'elementor-widget-slides',
                'elementor-widget-image-carousel',
                'elementor-widget-media-carousel',
                // Keep URL/handle protection strict. Generic words such as "slider", "carousel",
                // "slideshow" and "hero" cause false positives for non-hero assets such as
                // product-filter range sliders. Broad generic markers are used only for markup
                // detection in get_slider_hero_markup_markers().
            );

            $filtered = apply_filters('ucwp_slider_hero_protected_fragments', $fragments);
            if (is_array($filtered)) {
                $fragments = $filtered;
            }

            return array_values(array_unique(array_filter(array_map('strval', $fragments), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function get_slider_hero_protected_script_handles()
        {
            $handles = array(
                'revslider',
                'sr7',
                'tptools',
                'tp-tools',
                'rs6',
                'rs7',
                'slider-revolution',
                'swiper-js',
                'swiper-bundle-js',
                'slick-js',
                'splide-js',
                'owl-carousel-js',
                'flickity-js',
                'smartslider-frontend',
                'smartslider-simple-type-frontend',
                'n2-ss-public',
                'layerslider',
                'masterslider-core',
                'metaslider-flex-slider',
                'metaslider-responsive-slides',
            );

            $filtered = apply_filters('ucwp_slider_hero_protected_script_handles', $handles);
            if (is_array($filtered)) {
                $handles = $filtered;
            }

            return array_values(array_unique(array_filter(array_map('strval', $handles), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function get_slider_hero_markup_markers()
        {
            return array_values(array_unique(array_merge(
                $this->get_slider_hero_protected_fragments(),
                array(
                    'slider',
                    'carousel',
                    'slideshow',
                    'hero',
                    'hero-slider',
                    'main-hero',
                    'homepage-slider',
                )
            )));
        }

        private function should_apply_lcp_boundary_defer(array $settings, $slider_safe_mode)
        {
            unset($slider_safe_mode);

            if (empty($settings['lcp_boundary_defer']) || empty($settings['lcp_image_priority'])) {
                return false;
            }

            return true;
        }

        private function apply_lcp_priority_pipeline($html, array $settings, $slider_safe_mode)
        {
            if (empty($settings['lcp_image_priority'])) {
                return $html;
            }

            if (!empty($slider_safe_mode)) {
                $html = $this->apply_html_rewrite_safely($html, 'sr7-first-slide-lcp-priority', function ($html) {
                    return $this->apply_sr7_first_slide_lcp_priority_markup($html);
                });
                $html = $this->apply_html_rewrite_safely($html, 'safe-lcp-priority-preloads', function ($html) {
                    return $this->inject_safe_lcp_priority_preloads($html);
                });
                return $this->apply_html_rewrite_safely($html, 'lcp-preload-guard-cleanup', function ($html) {
                    return $this->cleanup_ambiguous_sr7_generated_lcp_preloads($html);
                });
            }

            return $this->apply_html_rewrite_safely($html, 'lcp-image-markup', function ($html) {
                return $this->optimize_lcp_image_markup($html);
            });
        }

        private function apply_lcp_boundary_defer_to_html($html, array $settings = array())
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<script')) {
                return $html;
            }

            $boundary = $this->find_lcp_boundary_offset($html);
            if ($boundary <= 0) {
                return $html;
            }

            if (!preg_match_all('/<script\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1[^>]*>\s*<\/script>/is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                return $html;
            }

            $callback_fragments = $this->get_lcp_boundary_callback_dependency_fragments($html);
            if (!empty($callback_fragments)) {
                $settings['_lcp_boundary_callback_dependency_fragments'] = $callback_fragments;
            }

            $out = '';
            $last = 0;
            $changed = false;

            foreach ($matches as $match) {
                $tag = isset($match[0][0]) ? (string) $match[0][0] : '';
                $offset = isset($match[0][1]) ? (int) $match[0][1] : -1;
                if ('' === $tag || $offset < $boundary) {
                    continue;
                }

                $replacement = $this->maybe_build_lcp_boundary_delayed_script_tag($tag, $settings);
                if (!is_string($replacement) || '' === $replacement || $replacement === $tag) {
                    continue;
                }

                $out .= substr($html, $last, $offset - $last) . $replacement;
                $last = $offset + strlen($tag);
                $changed = true;
            }

            if (!$changed) {
                return $html;
            }

            return $out . substr($html, $last);
        }

        private function apply_lazy_load_images_to_html($html, array $settings = array())
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<img')) {
                return $html;
            }

            $start_offset = 0;
            $skip_first_eligible = 2;

            if (!empty($settings['lcp_image_priority'])) {
                $boundary = $this->find_lcp_boundary_offset($html);
                if ($boundary <= 0) {
                    return $html;
                }

                $tag_end = strpos($html, '>', $boundary);
                if (false === $tag_end) {
                    return $html;
                }

                $start_offset = (int) $tag_end + 1;
                $skip_first_eligible = 0;
            }

            $head = (0 < $start_offset) ? substr($html, 0, $start_offset) : '';
            $tail = (0 < $start_offset) ? substr($html, $start_offset) : $html;
            if (!is_string($tail) || '' === $tail || false === stripos($tail, '<img')) {
                return $html;
            }

            $rewritten = $this->lazy_load_images_in_html_fragment($tail, $skip_first_eligible);
            if (!is_string($rewritten) || $rewritten === $tail) {
                return $html;
            }

            return $head . $rewritten;
        }

        private function lazy_load_images_in_html_fragment($html, $skip_first_eligible = 0)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<img')) {
                return $html;
            }

            if (class_exists('WP_HTML_Tag_Processor')) {
                try {
                    $processor = new WP_HTML_Tag_Processor($html);
                    $changed = false;
                    $eligible_seen = 0;
                    $processed = 0;

                    while ($processor->next_tag('IMG')) {
                        $processed++;
                        if ($processed > 180) {
                            break;
                        }

                        $attributes = array(
                            'src' => $processor->get_attribute('src'),
                            'srcset' => $processor->get_attribute('srcset'),
                            'class' => $processor->get_attribute('class'),
                            'id' => $processor->get_attribute('id'),
                            'alt' => $processor->get_attribute('alt'),
                            'width' => $processor->get_attribute('width'),
                            'height' => $processor->get_attribute('height'),
                            'loading' => $processor->get_attribute('loading'),
                            'decoding' => $processor->get_attribute('decoding'),
                            'fetchpriority' => $processor->get_attribute('fetchpriority'),
                            'data-ucwp-lcp' => $processor->get_attribute('data-ucwp-lcp'),
                            'data-ucwp-sr7-lcp' => $processor->get_attribute('data-ucwp-sr7-lcp'),
                            'data-no-lazy' => $processor->get_attribute('data-no-lazy'),
                            'data-skip-lazy' => $processor->get_attribute('data-skip-lazy'),
                        );

                        if ($this->should_skip_lazy_load_image($attributes)) {
                            continue;
                        }

                        $eligible_seen++;
                        if ($eligible_seen <= (int) $skip_first_eligible) {
                            continue;
                        }

                        $loading = $processor->get_attribute('loading');
                        if (null === $loading || false === $loading || '' === trim((string) $loading)) {
                            $processor->set_attribute('loading', 'lazy');
                            $changed = true;
                        }

                        $decoding = $processor->get_attribute('decoding');
                        if (null === $decoding || false === $decoding || '' === trim((string) $decoding)) {
                            $processor->set_attribute('decoding', 'async');
                            $changed = true;
                        }

                        if (null === $processor->get_attribute('data-ucwp-lazy-image')) {
                            $processor->set_attribute('data-ucwp-lazy-image', '1');
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        $updated = $processor->get_updated_html();
                        return is_string($updated) && '' !== $updated ? $updated : $html;
                    }
                } catch (\Throwable $e) {
                    // Fall through to the conservative regex fallback below.
                }
            }

            $eligible_seen = 0;
            $processed = 0;
            $changed = false;
            $updated = preg_replace_callback('/<img\b[^>]*>/i', function ($matches) use (&$eligible_seen, &$processed, &$changed, $skip_first_eligible) {
                $processed++;
                $tag = isset($matches[0]) ? (string) $matches[0] : '';
                if ('' === $tag || $processed > 180) {
                    return $tag;
                }

                $attributes = array(
                    'src' => $this->extract_attribute_from_html_tag($tag, 'src'),
                    'srcset' => $this->extract_attribute_from_html_tag($tag, 'srcset'),
                    'class' => $this->extract_attribute_from_html_tag($tag, 'class'),
                    'id' => $this->extract_attribute_from_html_tag($tag, 'id'),
                    'alt' => $this->extract_attribute_from_html_tag($tag, 'alt'),
                    'width' => $this->extract_attribute_from_html_tag($tag, 'width'),
                    'height' => $this->extract_attribute_from_html_tag($tag, 'height'),
                    'loading' => $this->extract_attribute_from_html_tag($tag, 'loading'),
                    'decoding' => $this->extract_attribute_from_html_tag($tag, 'decoding'),
                    'fetchpriority' => $this->extract_attribute_from_html_tag($tag, 'fetchpriority'),
                    'data-ucwp-lcp' => $this->extract_attribute_from_html_tag($tag, 'data-ucwp-lcp'),
                    'data-ucwp-sr7-lcp' => $this->extract_attribute_from_html_tag($tag, 'data-ucwp-sr7-lcp'),
                    'data-no-lazy' => $this->extract_attribute_from_html_tag($tag, 'data-no-lazy'),
                    'data-skip-lazy' => $this->extract_attribute_from_html_tag($tag, 'data-skip-lazy'),
                );

                if ($this->should_skip_lazy_load_image($attributes)) {
                    return $tag;
                }

                $eligible_seen++;
                if ($eligible_seen <= (int) $skip_first_eligible) {
                    return $tag;
                }

                $replacement = $tag;
                if ('' === trim((string) $attributes['loading'])) {
                    $replacement = $this->set_or_add_html_tag_attribute($replacement, 'loading', 'lazy');
                }
                if ('' === trim((string) $attributes['decoding'])) {
                    $replacement = $this->set_or_add_html_tag_attribute($replacement, 'decoding', 'async');
                }
                $replacement = $this->set_or_add_html_tag_attribute($replacement, 'data-ucwp-lazy-image', '1');

                if ($replacement !== $tag) {
                    $changed = true;
                }

                return $replacement;
            }, $html);

            return ($changed && is_string($updated) && '' !== $updated) ? $updated : $html;
        }

        private function should_skip_lazy_load_image(array $attributes)
        {
            $loading = strtolower(trim((string) (isset($attributes['loading']) ? $attributes['loading'] : '')));
            if ('eager' === $loading) {
                return true;
            }

            $fetchpriority = strtolower(trim((string) (isset($attributes['fetchpriority']) ? $attributes['fetchpriority'] : '')));
            if ('high' === $fetchpriority) {
                return true;
            }

            if ('' !== trim((string) (isset($attributes['data-ucwp-lcp']) ? $attributes['data-ucwp-lcp'] : '')) || '' !== trim((string) (isset($attributes['data-ucwp-sr7-lcp']) ? $attributes['data-ucwp-sr7-lcp'] : ''))) {
                return true;
            }

            if ('' !== trim((string) (isset($attributes['data-no-lazy']) ? $attributes['data-no-lazy'] : '')) || '' !== trim((string) (isset($attributes['data-skip-lazy']) ? $attributes['data-skip-lazy'] : ''))) {
                return true;
            }

            $src = trim((string) (isset($attributes['src']) ? $attributes['src'] : ''));
            $srcset = trim((string) (isset($attributes['srcset']) ? $attributes['srcset'] : ''));
            if ('' === $src && '' === $srcset) {
                return true;
            }

            $source_haystack = strtolower($src . ' ' . $srcset);
            if (preg_match('/^(?:data|blob):/i', $src) || preg_match('/\.(?:svg)(?:$|[?#])/i', $source_haystack)) {
                return true;
            }

            $haystack = strtolower(implode(' ', array_filter(array(
                isset($attributes['class']) ? $attributes['class'] : '',
                isset($attributes['id']) ? $attributes['id'] : '',
                isset($attributes['alt']) ? $attributes['alt'] : '',
                $source_haystack,
            ))));

            if (preg_match('/\b(?:custom-logo|site-logo|logo|brand|branding|avatar|icon|sprite|favicon|emoji|smiley|wp-smiley|admin-bar|skip-lazy|no-lazy|nolazy)\b/i', $haystack)) {
                return true;
            }

            $width = (int) preg_replace('/[^0-9]/', '', (string) (isset($attributes['width']) ? $attributes['width'] : ''));
            $height = (int) preg_replace('/[^0-9]/', '', (string) (isset($attributes['height']) ? $attributes['height'] : ''));
            if ($width > 0 && $height > 0) {
                if ($width <= 32 || $height <= 32 || ($width * $height) <= 4096) {
                    return true;
                }
            }

            return false;
        }

        private function find_lcp_boundary_offset($html)
        {
            if (!is_string($html) || '' === $html) {
                return -1;
            }

            $candidate = $this->find_manual_lcp_candidate($html);
            if (null === $candidate) {
                $candidate = $this->find_best_sr7_lcp_candidate($html);
            }
            if (null === $candidate) {
                $candidate = $this->find_best_lcp_candidate_with_regex($html);
            }
            if (null === $candidate || empty($candidate['url'])) {
                return -1;
            }

            if (!empty($candidate['boundary_offset'])) {
                return max(1, (int) $candidate['boundary_offset']);
            }

            $needles = array();
            foreach (array('raw_url', 'url') as $key) {
                if (!empty($candidate[$key])) {
                    $needles[] = (string) $candidate[$key];
                    $needles[] = esc_url((string) $candidate[$key]);
                    $needles[] = esc_attr((string) $candidate[$key]);
                    $needles[] = str_replace('&', '&amp;', (string) $candidate[$key]);
                }
            }

            foreach (array_values(array_unique(array_filter($needles))) as $needle) {
                $pos = stripos($html, $needle);
                if (false !== $pos) {
                    return (int) $pos + strlen($needle);
                }
            }

            return -1;
        }

        private function get_lcp_boundary_callback_dependency_fragments($html)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<script')) {
                return array();
            }

            if (!preg_match_all('/<script\b[^>]*(?:>.*?<\/script>|\/?>)/is', $html, $matches)) {
                return array();
            }

            $symbols = array();
            $script_tags = array();
            foreach ((array) $matches[0] as $tag) {
                $tag = (string) $tag;
                $src = $this->extract_attribute_from_html_tag($tag, 'src');
                $decoded_src = html_entity_decode((string) $src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $script_tags[] = array('tag' => $tag, 'src' => $decoded_src, 'id' => $this->extract_attribute_from_html_tag($tag, 'id'));
                if ('' !== $decoded_src && preg_match_all('/(?:[?&]|&amp;)(?:callback|cb|jsonp)=([A-Za-z_$][A-Za-z0-9_$\.]{1,120})(?:[&#]|$)/i', $decoded_src, $cb_matches)) {
                    foreach ((array) $cb_matches[1] as $symbol) {
                        $symbol = trim((string) $symbol);
                        if ('' !== $symbol) {
                            $symbols[$symbol] = true;
                        }
                    }
                }
            }

            if (empty($symbols)) {
                return array();
            }

            $fragments = array();
            foreach (array_keys($symbols) as $symbol) {
                $fragments[] = $symbol;
                $fragments[] = 'callback=' . $symbol;
                foreach (preg_split('/(?=[A-Z])|[_\-\.\s]+/', $symbol) as $token) {
                    $token = strtolower(trim((string) $token));
                    if (strlen($token) >= 3 && !in_array($token, array('init', 'load', 'callback', 'function'), true)) {
                        $fragments[] = $token;
                    }
                }
            }

            foreach ($script_tags as $record) {
                $haystack = strtolower((string) $record['tag'] . ' ' . (string) $record['src'] . ' ' . (string) $record['id']);
                foreach (array_keys($symbols) as $symbol) {
                    $symbol_lc = strtolower($symbol);
                    if (false !== strpos($haystack, $symbol_lc)) {
                        $src = (string) $record['src'];
                        $id = (string) $record['id'];
                        if ('' !== $id) {
                            $fragments[] = $id;
                        }
                        if ('' !== $src) {
                            $path = trim((string) wp_parse_url($src, PHP_URL_PATH), '/');
                            if ('' !== $path) {
                                $parts = array_values(array_filter(explode('/', strtolower($path)), 'strlen'));
                                if (!empty($parts)) {
                                    $fragments[] = end($parts);
                                }
                                if (count($parts) >= 2) {
                                    $fragments[] = implode('/', array_slice($parts, -2));
                                }
                                if (count($parts) >= 4) {
                                    $fragments[] = implode('/', array_slice($parts, -4));
                                }
                            }
                        }
                    }
                }
            }

            $fragments = array_filter(array_map('strval', $fragments), static function ($item) {
                return '' !== trim($item);
            });

            return array_values(array_unique($fragments));
        }

        private function lcp_boundary_script_tag_matches_fragments($tag, array $fragments)
        {
            $tag_lc = strtolower((string) $tag);
            if ('' === $tag_lc) {
                return false;
            }

            $decoded = strtolower(html_entity_decode($tag_lc, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            foreach ($fragments as $fragment) {
                $fragment = strtolower(trim((string) $fragment));
                if ('' === $fragment) {
                    continue;
                }
                if (false !== strpos($tag_lc, $fragment) || false !== strpos($decoded, $fragment)) {
                    return true;
                }
            }

            return false;
        }


        private function should_delay_lcp_boundary_script($handle, $src, $tag, array $settings = array())
        {
            $src = trim((string) $src);
            if ('' === $src || false === stripos((string) $tag, '<script')) {
                return false;
            }

            if (!$this->is_delayable_external_script_tag($tag)) {
                return false;
            }

            if (!$this->is_same_host_public_url($src)) {
                return false;
            }

            if ($this->should_native_defer_all_local_script($src, $settings)) {
                return false;
            }

            $exclude_fragments = $this->get_delay_non_critical_js_exclude_fragments();
            if (!empty($settings['_lcp_boundary_callback_dependency_fragments']) && is_array($settings['_lcp_boundary_callback_dependency_fragments'])) {
                $exclude_fragments = array_merge($exclude_fragments, (array) $settings['_lcp_boundary_callback_dependency_fragments']);
            }
            if ($this->script_matches_fragment_list($handle, $src, $exclude_fragments) || $this->lcp_boundary_script_tag_matches_fragments($tag, $exclude_fragments)) {
                return false;
            }

            if ($this->script_handle_has_inline_after_segments($handle)) {
                return false;
            }

            if ($this->script_handle_has_enqueued_dependents($handle)) {
                return false;
            }

            $src_lc = strtolower((string) $src);
            $handle_lc = strtolower((string) $handle);

            if (0 === strpos($handle_lc, 'wp-') || 0 === strpos($handle_lc, 'wc-')) {
                return false;
            }

            if (false !== strpos($src_lc, '/wp-includes/js/')) {
                return false;
            }

            if (false !== strpos($src_lc, '/plugins/woocommerce/assets/')) {
                return false;
            }

            return true;
        }

        private function maybe_build_lcp_boundary_delayed_script_tag($tag, array $settings = array())
        {
            $tag = (string) $tag;
            if ('' === $tag || false === stripos($tag, '<script')) {
                return $tag;
            }
            if (false !== stripos($tag, 'type="text/ucwp-delayed-js"') || false !== stripos($tag, "type='text/ucwp-delayed-js'") || false !== stripos($tag, 'data-ucwp-src=')) {
                return $tag;
            }

            $src = $this->extract_attribute_from_html_tag($tag, 'src');
            if ('' === $src) {
                return $tag;
            }

            $handle = $this->infer_script_handle_from_tag($tag, $src);
            if ('' === $handle) {
                $handle = $src;
            }

            if ($this->should_delay_lcp_boundary_script($handle, $src, $tag, $settings)) {
                return $this->build_delayed_script_tag($tag, $handle, $src, 'lcp-boundary');
            }

            return $tag;
        }

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

        private function inject_detected_slider_fetch_preloads($html, array $settings = array())
        {
            if (false === stripos((string) $html, 'srengine=7') && false === stripos((string) $html, '/sliders/')) {
                return $html;
            }

            if (!preg_match_all('~(?:https?:)?//[^\s"\']+/sliders/\d+\?srengine=7[^\s"\'<)]*|/sliders/\d+\?srengine=7[^\s"\'<)]*~i', $html, $matches)) {
                return $html;
            }

            $links = array();
            foreach ((array) ($matches[0] ?? array()) as $url) {
                $url = html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8');
                $url = $this->absolutize_public_resource_url($url, home_url('/'));
                if ('' === $url) {
                    continue;
                }
                $links[$url] = array('url' => $url, 'as' => 'fetch');
                if (count($links) >= 4) {
                    break;
                }
            }

            return $this->inject_critical_preload_link_candidates($html, array_values($links));
        }

        private function should_skip_sr7_generated_manual_preload(array $candidate, $html, array $settings = array())
        {
            $url = isset($candidate['url']) ? $this->normalize_public_resource_url((string) $candidate['url']) : '';
            $as = isset($candidate['as']) ? strtolower(trim((string) $candidate['as'])) : '';
            if ('image' !== $as || '' === $url) {
                return false;
            }

            // SR7 stores generated/optimized image-list assets under /revslider/o/. Those hidden
            // placeholders are often not consumed within a few seconds, so manual preloading them
            // can create Chrome "preloaded but not used" warnings and compete with real LCP work.
            if (!$this->is_sr7_generated_image_list_url($url)) {
                return false;
            }

            if (empty($settings['slider_safe_mode']) && empty($settings['lcp_image_priority'])) {
                return false;
            }

            return false !== stripos((string) $html, '<sr7-')
                || false !== stripos((string) $html, 'sr7-module')
                || false !== stripos((string) $html, '/wp-content/uploads/revslider/');
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
            if (preg_match('/\.(avif|webp|png|jpe?g|gif)$/', $path)) {
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

                $tags[] = '<link ' . $attrs . ' data-ucwp-critical-chain="1">';
            }

            if (empty($tags)) {
                return $html;
            }

            return $this->insert_html_before_closing_head($html, implode("\n", $tags));
        }

        private function html_has_slider_safe_mode_marker($html)
        {
            if (!is_string($html) || '' === $html) {
                return false;
            }

            $html_lc = strtolower($html);
            foreach ($this->get_slider_hero_markup_markers() as $marker) {
                if (false !== strpos($html_lc, strtolower($marker))) {
                    return true;
                }
            }

            return false;
        }

        private function has_fragile_revslider_shell($html)
        {
            if (!is_string($html) || '' === $html) {
                return false;
            }

            $html_lc = strtolower($html);
            $has_revslider = false;
            foreach (array('wp-block-themepunch-revslider', 'revslider', 'sr7-', 'rs-module') as $marker) {
                if (false !== strpos($html_lc, $marker)) {
                    $has_revslider = true;
                    break;
                }
            }

            if (!$has_revslider) {
                return false;
            }

            $empty_sr7_slides = 0;
            if (preg_match_all('/<sr7-slide\b[^>]*>\s*<\/sr7-slide>/i', $html, $matches)) {
                $empty_sr7_slides = is_array($matches[0]) ? count($matches[0]) : 0;
            }

            if ($empty_sr7_slides < 2) {
                return false;
            }

            $has_inline_slider_bootstrap = false !== strpos($html_lc, 'window.sr7')
                || false !== strpos($html_lc, 'sr7.pmh')
                || false !== strpos($html_lc, '_tpt.preparemoduleheight');
            if (!$has_inline_slider_bootstrap) {
                return false;
            }

            $has_image_shell_media = false;
            foreach (array(
                'image_lists',
                'data-dbsrc=',
                '/wp-content/uploads/revslider/',
                '<sr7-img',
                '<img data-src=',
            ) as $marker) {
                if (false !== strpos($html_lc, strtolower($marker))) {
                    $has_image_shell_media = true;
                    break;
                }
            }

            $has_video_driven_media = false;
            foreach (array(
                '<video',
                '.mp4',
                'youtube',
                'vimeo',
                'data-video',
            ) as $marker) {
                if (false !== strpos($html_lc, strtolower($marker))) {
                    $has_video_driven_media = true;
                    break;
                }
            }

            if (!$has_image_shell_media) {
                return true;
            }

            return $has_video_driven_media;
        }

        private function optimize_lcp_image_markup($html)
        {
            if (false === stripos($html, '</head')) {
                return $html;
            }

            $manual_candidate = $this->find_manual_lcp_candidate($html);
            if (null !== $manual_candidate) {
                return $this->apply_lcp_candidate_optimizations($html, $manual_candidate);
            }

            $manual_selector_candidates = $this->find_manual_lcp_hero_selector_candidates($html, 1);
            if (!empty($manual_selector_candidates) && !empty($manual_selector_candidates[0])) {
                return $this->apply_lcp_candidate_optimizations($html, $manual_selector_candidates[0]);
            }

            $has_standard_images = false !== stripos($html, '<img');
            $has_sr7_markup = false !== stripos($html, '<sr7-') || false !== stripos($html, 'sr7-module') || false !== stripos($html, '/wp-content/uploads/revslider/');
            $has_manual_lcp_override = !empty($this->get_settings()['lcp_image_priority_override_list']);
            if (!$has_standard_images && !$has_sr7_markup && !$has_manual_lcp_override) {
                return $html;
            }

            if (class_exists('WP_HTML_Tag_Processor')) {
                return $this->optimize_lcp_image_markup_with_tag_processor($html);
            }

            return $this->optimize_lcp_image_markup_with_regex($html);
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

            $manual_selector_candidates = $this->find_manual_lcp_hero_selector_candidates($html, 1);
            if (!empty($manual_selector_candidates)) {
                $updated = $html;
                foreach ($manual_selector_candidates as $candidate) {
                    if (!empty($candidate['url'])) {
                        $updated = $this->inject_lcp_preload_link($updated, $candidate['url']);
                    }
                }
                return $updated;
            }

            // 2.56.193: after image rewriting and SR7 runtime markup generation, the
            // safest preload target is the actual final sr7-module-bg background URL
            // present in the cached HTML. This restores LCP preload after the 2.56.192
            // /revslider/o/ guard without preloading ambiguous helper assets.
            $sr7_confirmed_module_bg_candidates = $this->find_confirmed_sr7_module_bg_lcp_preload_candidates($html, 1);
            if (!empty($sr7_confirmed_module_bg_candidates)) {
                $updated = $html;
                foreach ($sr7_confirmed_module_bg_candidates as $candidate) {
                    if (!empty($candidate['url'])) {
                        $updated = $this->inject_lcp_preload_link($updated, $candidate['url']);
                    }
                }
                return $updated;
            }

            $sr7_first_slide_candidates = $this->find_sr7_first_slide_lcp_candidates($html, 1);
            if (!empty($sr7_first_slide_candidates)) {
                $updated = $html;
                foreach ($sr7_first_slide_candidates as $candidate) {
                    if (!empty($candidate['url'])) {
                        $updated = $this->inject_lcp_preload_link($updated, $candidate['url']);
                    }
                }
                return $updated;
            }

            $sr7_markup_candidates = $this->find_marked_sr7_lcp_preload_candidates($html, 1);
            if (!empty($sr7_markup_candidates)) {
                $updated = $html;
                foreach ($sr7_markup_candidates as $candidate) {
                    if (!empty($candidate['url'])) {
                        $updated = $this->inject_lcp_preload_link($updated, $candidate['url']);
                    }
                }
                return $updated;
            }

            $sr7_static_candidates = $this->find_sr7_static_slide_lcp_candidates($html, 1);
            if (!empty($sr7_static_candidates)) {
                $updated = $html;
                foreach ($sr7_static_candidates as $candidate) {
                    if (!empty($candidate['url'])) {
                        $updated = $this->inject_lcp_preload_link($updated, $candidate['url']);
                    }
                }
                return $updated;
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

        private function find_confirmed_sr7_module_bg_lcp_preload_candidates($html, $limit = 1)
        {
            $html = (string) $html;
            $limit = max(1, min(3, (int) $limit));
            if ('' === $html || false === stripos($html, '<sr7-module-bg')) {
                return array();
            }

            if (!preg_match_all('/<sr7-module-bg\b[^>]*>/i', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                return array();
            }

            $candidates = array();
            $seen = array();
            foreach ($matches as $index => $match) {
                $tag_html = isset($match[0][0]) ? (string) $match[0][0] : '';
                $tag_offset = isset($match[0][1]) ? (int) $match[0][1] : 0;
                if ('' === $tag_html) {
                    continue;
                }

                $urls = array();
                foreach ($this->extract_candidate_urls_from_style($this->extract_attribute_from_html_tag($tag_html, 'style')) as $style_url) {
                    $urls[] = array('url' => $style_url, 'attribute' => 'style');
                }

                foreach (array('src', 'data-src', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image', 'poster') as $attribute) {
                    $value = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                    if ('' !== $value) {
                        $urls[] = array('url' => $value, 'attribute' => $attribute);
                    }
                }

                foreach ($urls as $url_entry) {
                    $raw_url = isset($url_entry['url']) ? (string) $url_entry['url'] : '';
                    $attribute = isset($url_entry['attribute']) ? (string) $url_entry['attribute'] : 'style';
                    if ('' === trim($raw_url)) {
                        continue;
                    }

                    if (0 === strpos($raw_url, '//')) {
                        $raw_url = (is_ssl() ? 'https:' : 'http:') . $raw_url;
                    } elseif (0 === strpos($raw_url, '/')) {
                        $raw_url = $this->absolutize_public_resource_url($raw_url);
                    }

                    $normalized_url = $this->normalize_public_resource_url($raw_url);
                    if ('' === $normalized_url || !$this->is_lcp_candidate_image_url($normalized_url)) {
                        continue;
                    }

                    // /revslider/o/ optimized helper URLs are valid generated assets, but they
                    // are not reliable first-paint preload targets. The confirmed module-bg path
                    // must point to the final background URL already present in cached HTML.
                    if ($this->is_sr7_generated_image_list_url($normalized_url)) {
                        continue;
                    }

                    $key = strtolower($this->normalize_public_resource_url($normalized_url));
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;

                    $dimensions = $this->get_public_image_dimensions($normalized_url);
                    $width = isset($dimensions['width']) ? (int) $dimensions['width'] : 0;
                    $height = isset($dimensions['height']) ? (int) $dimensions['height'] : 0;

                    $candidates[] = array(
                        'url' => $normalized_url,
                        'raw_url' => $raw_url,
                        'attribute' => $attribute,
                        'tag' => 'SR7-MODULE-BG',
                        'is_sr7' => true,
                        'sr7_module_bg_candidate' => true,
                        'sr7_verified_first_slide' => true,
                        'sr7_role' => 'module-bg-final-html',
                        'lcp_reason' => 'sr7-module-bg-final-html',
                        'width' => $width,
                        'height' => $height,
                        'area' => ($width > 0 && $height > 0) ? ($width * $height) : 0,
                        'score' => 3000000 - ((int) $index * 100),
                        'tag_offset' => $tag_offset,
                        'source' => 'sr7-module-bg-final-html',
                    );
                    break;
                }
            }

            if (empty($candidates)) {
                return array();
            }

            return $this->dedupe_lcp_candidates_by_url($this->sort_lcp_candidates_by_area_then_score($candidates), $limit);
        }

        private function find_manual_lcp_hero_selector_candidates($html, $limit = 1)
        {
            $settings = $this->get_settings();
            $selectors = isset($settings['manual_lcp_hero_selector_list']) && is_array($settings['manual_lcp_hero_selector_list']) ? $settings['manual_lcp_hero_selector_list'] : array();
            $limit = max(1, min(5, (int) $limit));
            if (empty($selectors) || !is_string($html) || '' === $html) {
                return array();
            }

            $results = array();
            foreach ($selectors as $selector) {
                $normalized_selector = $this->normalize_manual_lcp_hero_selector($selector);
                if ('' === $normalized_selector) {
                    continue;
                }

                $block = $this->extract_manual_lcp_hero_selector_block($html, $normalized_selector);
                if ('' === $block) {
                    continue;
                }

                $candidates = $this->find_lcp_candidates_in_manual_hero_block($block, $normalized_selector);
                $is_direct_manual_img_selector = (bool) preg_match('/^\s*<\s*(?:img|sr7-img)\b/i', $block);
                foreach ($candidates as $candidate) {
                    if (empty($candidate['url'])) {
                        continue;
                    }
                    $candidate['manual_lcp_hero_selector'] = $normalized_selector;
                    $candidate['manual_lcp_hero_selector_found'] = true;
                    $candidate['is_manual_selector'] = true;
                    $candidate['lcp_reason'] = !empty($candidate['lcp_reason']) ? (string) $candidate['lcp_reason'] : ($is_direct_manual_img_selector ? 'manual-selector' : 'manual-selector-container');
                    $candidate['score'] = 2000000 - count($results);
                    $results[] = $candidate;
                    if (count($results) >= $limit) {
                        break 2;
                    }
                }
            }

            return $this->dedupe_lcp_candidates_by_url($results, $limit);
        }

        private function normalize_manual_lcp_hero_selector($selector)
        {
            $selector = trim((string) $selector);
            if ('' === $selector) {
                return '';
            }

            $selector = preg_replace('/\s+/', ' ', $selector);
            $selector = trim((string) $selector);
            if ('' === $selector || strlen($selector) > 240) {
                return '';
            }

            // Accept a plain element ID like "homepage-slider" and treat it as #homepage-slider.
            if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,80}$/', $selector)) {
                return '#' . $selector;
            }

            // Support simple, real CSS selectors for manual LCP targeting, for example:
            // #hero > div.mask > img, article img.wp-post-image, .hero img.
            // Deliberately reject pseudo selectors, commas and attribute selectors here; this
            // field is a safe/manual selector hint, not a full CSS parser.
            if (!preg_match('/^[A-Za-z0-9_*#.>:\-\s]+$/', $selector)) {
                return '';
            }

            $parts = $this->parse_manual_lcp_css_selector_parts($selector);
            if (empty($parts)) {
                return '';
            }

            return $selector;
        }

        private function parse_manual_lcp_css_selector_parts($selector)
        {
            $selector = trim((string) $selector);
            if ('' === $selector) {
                return array();
            }

            $selector = str_replace('>', ' > ', $selector);
            $tokens = preg_split('/\s+/', trim($selector));
            if (!is_array($tokens)) {
                return array();
            }

            $parts = array();
            foreach ($tokens as $token) {
                $token = trim((string) $token);
                if ('' === $token || '>' === $token) {
                    continue;
                }
                if (!$this->manual_lcp_css_simple_selector_is_supported($token)) {
                    return array();
                }
                $parts[] = $token;
            }

            return $parts;
        }

        private function manual_lcp_css_simple_selector_is_supported($simple)
        {
            $simple = trim((string) $simple);
            if ('' === $simple) {
                return false;
            }

            return (bool) preg_match('/^(?:\*|[A-Za-z][A-Za-z0-9_:-]*)?(?:#[A-Za-z][A-Za-z0-9_-]{0,120})?(?:\.[A-Za-z][A-Za-z0-9_-]{0,120})*$/', $simple);
        }

        private function manual_lcp_css_simple_selector_matches_tag($tag_html, $simple)
        {
            $tag_html = (string) $tag_html;
            $simple = trim((string) $simple);
            if ('' === $tag_html || '' === $simple) {
                return false;
            }

            $tag_name = '';
            if (preg_match('/^<\s*([a-z0-9:_-]+)/i', $tag_html, $tag_match) && !empty($tag_match[1])) {
                $tag_name = strtolower((string) $tag_match[1]);
            }

            $simple_tag = '';
            if (preg_match('/^(\*|[A-Za-z][A-Za-z0-9_:-]*)/', $simple, $tag_match) && isset($tag_match[1])) {
                $simple_tag = strtolower((string) $tag_match[1]);
            }
            if ('' !== $simple_tag && '*' !== $simple_tag && $tag_name !== $simple_tag) {
                return false;
            }

            if (preg_match('/#([A-Za-z][A-Za-z0-9_-]{0,120})/', $simple, $id_match) && !empty($id_match[1])) {
                $id = strtolower($this->extract_attribute_from_html_tag($tag_html, 'id'));
                if ($id !== strtolower((string) $id_match[1])) {
                    return false;
                }
            }

            if (preg_match_all('/\.([A-Za-z][A-Za-z0-9_-]{0,120})/', $simple, $class_matches) && !empty($class_matches[1])) {
                $classes = preg_split('/\s+/', strtolower($this->extract_attribute_from_html_tag($tag_html, 'class')));
                $classes = is_array($classes) ? array_filter($classes, 'strlen') : array();
                foreach ($class_matches[1] as $required_class) {
                    if (!in_array(strtolower((string) $required_class), $classes, true)) {
                        return false;
                    }
                }
            }

            return true;
        }

        private function get_manual_lcp_open_ancestor_start_tags($html, $offset)
        {
            $html = (string) $html;
            $offset = max(0, (int) $offset);
            if ('' === $html || $offset <= 0) {
                return array();
            }

            $prefix = substr($html, 0, $offset);
            if ('' === $prefix || !preg_match_all('/<\/?([a-z0-9:_-]+)\b[^>]*>/i', $prefix, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                return array();
            }

            $void_tags = array('area' => true, 'base' => true, 'br' => true, 'col' => true, 'embed' => true, 'hr' => true, 'img' => true, 'input' => true, 'link' => true, 'meta' => true, 'param' => true, 'source' => true, 'track' => true, 'wbr' => true);
            $stack = array();
            foreach ($matches as $match) {
                $tag_html = isset($match[0][0]) ? (string) $match[0][0] : '';
                $tag_name = isset($match[1][0]) ? strtolower((string) $match[1][0]) : '';
                if ('' === $tag_html || '' === $tag_name) {
                    continue;
                }

                $is_close = isset($tag_html[1]) && '/' === $tag_html[1];
                $is_self_closing = isset($void_tags[$tag_name]) || (bool) preg_match('/\/\s*>$/', $tag_html);
                if ($is_close) {
                    for ($i = count($stack) - 1; $i >= 0; $i--) {
                        if (isset($stack[$i]['tag']) && $stack[$i]['tag'] === $tag_name) {
                            array_splice($stack, $i, 1);
                            break;
                        }
                    }
                    continue;
                }

                if (!$is_self_closing) {
                    $stack[] = array(
                        'tag' => $tag_name,
                        'html' => $tag_html,
                    );
                }
            }

            return $stack;
        }

        private function manual_lcp_css_selector_matches_tag_at_offset($html, $selector, $tag_html, $offset)
        {
            $parts = $this->parse_manual_lcp_css_selector_parts($selector);
            if (empty($parts)) {
                return false;
            }

            $last = array_pop($parts);
            if (!$this->manual_lcp_css_simple_selector_matches_tag($tag_html, $last)) {
                return false;
            }

            if (empty($parts)) {
                return true;
            }

            $ancestors = $this->get_manual_lcp_open_ancestor_start_tags($html, $offset);
            if (empty($ancestors)) {
                return false;
            }

            $ancestor_index = count($ancestors) - 1;
            for ($part_index = count($parts) - 1; $part_index >= 0; $part_index--) {
                $part = $parts[$part_index];
                $matched = false;
                for (; $ancestor_index >= 0; $ancestor_index--) {
                    $ancestor_html = isset($ancestors[$ancestor_index]['html']) ? (string) $ancestors[$ancestor_index]['html'] : '';
                    if ($this->manual_lcp_css_simple_selector_matches_tag($ancestor_html, $part)) {
                        $matched = true;
                        $ancestor_index--;
                        break;
                    }
                }
                if (!$matched) {
                    return false;
                }
            }

            return true;
        }

        private function extract_manual_lcp_css_selector_direct_tag($html, $selector)
        {
            $html = (string) $html;
            $selector = $this->normalize_manual_lcp_hero_selector($selector);
            if ('' === $html || '' === $selector) {
                return '';
            }

            $parts = $this->parse_manual_lcp_css_selector_parts($selector);
            if (empty($parts)) {
                return '';
            }

            $last = end($parts);
            $tag_hint = '*';
            if (preg_match('/^(\*|[A-Za-z][A-Za-z0-9_:-]*)/', (string) $last, $tag_match) && !empty($tag_match[1])) {
                $tag_hint = strtolower((string) $tag_match[1]);
            }
            $tag_pattern = ('*' === $tag_hint || '' === $tag_hint) ? '[a-z0-9:_-]+' : preg_quote($tag_hint, '/');

            if (!preg_match_all('/<(' . $tag_pattern . ')\b[^>]*>/i', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                return '';
            }

            foreach ($matches as $match) {
                $tag_html = isset($match[0][0]) ? (string) $match[0][0] : '';
                $offset = isset($match[0][1]) ? (int) $match[0][1] : 0;
                if ('' === $tag_html) {
                    continue;
                }
                if ($this->manual_lcp_css_selector_matches_tag_at_offset($html, $selector, $tag_html, $offset)) {
                    return $tag_html;
                }
            }

            return '';
        }

        private function extract_manual_lcp_hero_selector_block($html, $selector)
        {
            $html = (string) $html;
            $selector = $this->normalize_manual_lcp_hero_selector($selector);
            if ('' === $html || '' === $selector) {
                return '';
            }

            $direct_tag = $this->extract_manual_lcp_css_selector_direct_tag($html, $selector);
            if ('' !== $direct_tag) {
                if (preg_match('/^<\s*(?:img|sr7-img)\b/i', $direct_tag)) {
                    return $direct_tag;
                }

                if (preg_match('/^<\s*([a-z0-9:_-]+)/i', $direct_tag, $direct_match) && !empty($direct_match[1])) {
                    $start = strpos($html, $direct_tag);
                    $tag = strtolower((string) $direct_match[1]);
                    if (false !== $start && !in_array($tag, array('area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'), true)) {
                        $start_tag_end = strpos($html, '>', $start);
                        if (false !== $start_tag_end) {
                            $closing = '</' . $tag . '>';
                            $end = stripos($html, $closing, $start_tag_end);
                            if (false !== $end) {
                                return substr($html, $start, ($end + strlen($closing)) - $start);
                            }
                            return substr($html, $start, 120000);
                        }
                    }
                }
            }

            $parts = $this->parse_manual_lcp_css_selector_parts($selector);
            if (count($parts) !== 1) {
                return '';
            }

            $name = substr($selector, 1);
            $name_quoted = preg_quote($name, '/');
            if ('#' === $selector[0]) {
                $pattern = '/<([a-z0-9:-]+)\b(?=[^>]*\bid\s*=\s*(["\'])' . $name_quoted . '\2)[^>]*>/i';
            } elseif ('.' === $selector[0]) {
                $pattern = '/<([a-z0-9:-]+)\b(?=[^>]*\bclass\s*=\s*(["\'])(?=[^"\']*(?:^|\s)' . $name_quoted . '(?:\s|$))[^"\']*\2)[^>]*>/i';
            } else {
                return '';
            }

            if (!preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE)) {
                return '';
            }

            $start = (int) $match[0][1];
            $tag = strtolower((string) $match[1][0]);
            $start_tag_end = strpos($html, '>', $start);
            if (false === $start_tag_end) {
                return '';
            }

            $closing = '</' . $tag . '>';
            $end = stripos($html, $closing, $start_tag_end);
            if (false === $end) {
                return substr($html, $start, 120000);
            }

            return substr($html, $start, ($end + strlen($closing)) - $start);
        }

        private function find_lcp_candidates_in_manual_hero_block($block, $selector)
        {
            $block = (string) $block;
            if ('' === $block) {
                return array();
            }

            $candidates = array();

            if (false !== stripos($block, 'sr7')) {
                $sr7_candidates = $this->find_sr7_first_slide_lcp_candidates($block, 5);
                if (!empty($sr7_candidates)) {
                    return $this->sort_lcp_candidates_by_area_then_score($sr7_candidates);
                }
            }

            // In a manually scoped hero/slider, prefer a specifically marked SR7 LCP image when present.
            if (preg_match_all('/<(?:sr7-img|img)\b[^>]*\bdata-ucwp-sr7-lcp\s*=\s*(["\'])1\1[^>]*>/i', $block, $matches)) {
                foreach ($matches[0] as $index => $tag_html) {
                    $candidate = $this->extract_lcp_candidate_from_html_tag($tag_html, array(
                        'manual_lcp_hero_selector' => $selector,
                        'manual_lcp_order' => $index,
                        'prefer_dom_order' => true,
                    ));
                    if (null !== $candidate) {
                        $candidate['is_sr7'] = true;
                        $candidate['sr7_markup_candidate'] = true;
                        $candidate['sr7_verified_first_slide'] = true;
                        $candidate['score'] = 2000000 - ((int) $index * 10);
                        $candidates[] = $candidate;
                    }
                }
            }

            if (!empty($candidates)) {
                return $candidates;
            }

            if (preg_match_all('/<(img|sr7-img|div|section|figure|picture|sr7-slide|sr7-content|sr7-module)\b[^>]*>/i', $block, $matches)) {
                foreach ($matches[0] as $index => $tag_html) {
                    $candidate = $this->extract_lcp_candidate_from_html_tag($tag_html, array(
                        'manual_lcp_hero_selector' => $selector,
                        'manual_lcp_order' => $index,
                    ));
                    if (null !== $candidate) {
                        $candidate['score'] += 5000 - ((int) $index * 10);
                        $candidates[] = $candidate;
                    }
                }
            }

            if (empty($candidates)) {
                return array();
            }

            return $this->sort_lcp_candidates_by_area_then_score($candidates);
        }

        private function extract_lcp_candidate_from_html_tag($tag_html, array $extra_context = array())
        {
            $tag_html = (string) $tag_html;
            if ('' === $tag_html) {
                return null;
            }

            $tag_name = '';
            if (preg_match('/^<\s*([a-z0-9:-]+)/i', $tag_html, $tag_match) && !empty($tag_match[1])) {
                $tag_name = strtoupper((string) $tag_match[1]);
            }

            $context = array_merge(array(
                'tag'        => $tag_name,
                'class'      => $this->extract_attribute_from_html_tag($tag_html, 'class'),
                'id'         => $this->extract_attribute_from_html_tag($tag_html, 'id'),
                'title'      => $this->extract_attribute_from_html_tag($tag_html, 'title'),
                'alt'        => $this->extract_attribute_from_html_tag($tag_html, 'alt'),
                'aria-label' => $this->extract_attribute_from_html_tag($tag_html, 'aria-label'),
                'width'      => $this->extract_attribute_from_html_tag($tag_html, 'width'),
                'height'     => $this->extract_attribute_from_html_tag($tag_html, 'height'),
                'loading'    => $this->extract_attribute_from_html_tag($tag_html, 'loading'),
                'style'      => $this->extract_attribute_from_html_tag($tag_html, 'style'),
            ), $extra_context);

            foreach (array('src', 'data-src', 'data-dbsrc', 'data-lazy-src', 'data-lazyload', 'data-image', 'data-origin', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image', 'poster') as $attribute) {
                $value = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                if ('' === $value) {
                    continue;
                }

                $candidate = $this->build_lcp_candidate_from_values($value, $context + array('attribute' => $attribute));
                if (null !== $candidate) {
                    return $candidate;
                }
            }

            foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
                foreach ($this->extract_candidate_urls_from_srcset($this->extract_attribute_from_html_tag($tag_html, $attribute)) as $srcset_url) {
                    $candidate = $this->build_lcp_candidate_from_values($srcset_url, $context + array('attribute' => $attribute));
                    if (null !== $candidate) {
                        return $candidate;
                    }
                }
            }

            foreach ($this->extract_candidate_urls_from_style($context['style']) as $style_url) {
                $candidate = $this->build_lcp_candidate_from_values($style_url, $context + array('attribute' => 'style'));
                if (null !== $candidate) {
                    return $candidate;
                }
            }

            return null;
        }

        private function dedupe_lcp_candidates_by_url(array $candidates, $limit = 1)
        {
            $unique = array();
            $seen = array();
            $limit = max(1, min(10, (int) $limit));
            foreach ($candidates as $candidate) {
                $key = $this->normalize_public_resource_url(isset($candidate['url']) ? (string) $candidate['url'] : '');
                if ('' === $key || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $unique[] = $candidate;
                if (count($unique) >= $limit) {
                    break;
                }
            }

            return $unique;
        }

        private function find_marked_sr7_lcp_preload_candidates($html, $limit = 1)
        {
            $html = (string) $html;
            $limit = max(1, min(5, (int) $limit));
            if ('' === $html || false === stripos($html, 'data-ucwp-sr7-lcp')) {
                return array();
            }

            if (!preg_match_all('/<(?:sr7-img|img)\b[^>]*\bdata-ucwp-sr7-lcp\s*=\s*(["\'])1\1[^>]*>/i', $html, $matches)) {
                return array();
            }

            $preferred = array();
            $generated = array();
            foreach ($matches[0] as $index => $tag_html) {
                $tag_html = (string) $tag_html;
                $tag_name = 'SR7-IMG';
                if (preg_match('/^<\s*([a-z0-9:-]+)/i', $tag_html, $tag_match) && !empty($tag_match[1])) {
                    $tag_name = strtoupper((string) $tag_match[1]);
                }

                $context = array(
                    'tag'        => $tag_name,
                    'class'      => $this->extract_attribute_from_html_tag($tag_html, 'class'),
                    'id'         => $this->extract_attribute_from_html_tag($tag_html, 'id'),
                    'title'      => $this->extract_attribute_from_html_tag($tag_html, 'title'),
                    'alt'        => $this->extract_attribute_from_html_tag($tag_html, 'alt'),
                    'aria-label' => $this->extract_attribute_from_html_tag($tag_html, 'aria-label'),
                    'width'      => $this->extract_attribute_from_html_tag($tag_html, 'width'),
                    'height'     => $this->extract_attribute_from_html_tag($tag_html, 'height'),
                    'loading'    => $this->extract_attribute_from_html_tag($tag_html, 'loading'),
                    'style'      => $this->extract_attribute_from_html_tag($tag_html, 'style'),
                );

                foreach (array('src', 'data-src', 'data-dbsrc', 'data-lazy-src', 'data-lazyload', 'data-image', 'data-origin', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image', 'poster') as $attribute) {
                    $value = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                    if ('' === $value) {
                        continue;
                    }

                    $candidate = $this->build_lcp_candidate_from_values($value, $context + array('attribute' => $attribute));
                    if (null === $candidate || empty($candidate['url'])) {
                        continue;
                    }

                    $candidate['is_sr7'] = true;
                    $candidate['sr7_markup_candidate'] = true;
                    $candidate['sr7_verified_first_slide'] = true;
                    $candidate['score'] += 1400 + max(0, 100 - ((int) $index * 12));

                    if ($this->is_sr7_generated_image_list_url($candidate['url'])) {
                        $generated[] = $candidate;
                    } else {
                        $preferred[] = $candidate;
                    }

                    // The rendered SR7 element's first concrete image URL is the safest preload
                    // target. Do not continue into generated image-list placeholders when a real
                    // markup candidate exists.
                    break;
                }

                foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
                    if (!empty($preferred)) {
                        break;
                    }
                    foreach ($this->extract_candidate_urls_from_srcset($this->extract_attribute_from_html_tag($tag_html, $attribute)) as $srcset_url) {
                        $candidate = $this->build_lcp_candidate_from_values($srcset_url, $context + array('attribute' => $attribute));
                        if (null === $candidate || empty($candidate['url'])) {
                            continue;
                        }
                        $candidate['is_sr7'] = true;
                        $candidate['sr7_markup_candidate'] = true;
                        $candidate['sr7_verified_first_slide'] = true;
                        $candidate['score'] += 1300 + max(0, 100 - ((int) $index * 12));
                        if ($this->is_sr7_generated_image_list_url($candidate['url'])) {
                            $generated[] = $candidate;
                        } else {
                            $preferred[] = $candidate;
                        }
                        break 2;
                    }
                }
            }

            $candidates = !empty($preferred) ? $preferred : $generated;
            if (empty($candidates)) {
                return array();
            }

            $candidates = $this->sort_lcp_candidates_by_area_then_score($candidates);

            $unique = array();
            $seen = array();
            foreach ($candidates as $candidate) {
                $key = $this->normalize_public_resource_url(isset($candidate['url']) ? (string) $candidate['url'] : '');
                if ('' === $key || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $unique[] = $candidate;
                if (count($unique) >= $limit) {
                    break;
                }
            }

            return $unique;
        }

        private function apply_sr7_first_slide_lcp_priority_markup($html)
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            if (false === stripos($html, '<sr7-') && false === stripos($html, 'sr7-module')) {
                return $html;
            }

            $candidate = null;
            $first_slide_candidates = $this->find_sr7_first_slide_lcp_candidates($html, 1);
            if (!empty($first_slide_candidates)) {
                $candidate = $first_slide_candidates[0];
            }

            if (null === $candidate) {
                $candidate = $this->find_best_sr7_lcp_candidate($html);
            }

            if (null !== $candidate && !empty($candidate['url'])) {
                $processed = $this->boost_lcp_candidate_markup($html, $candidate);
                if (is_string($processed) && '' !== $processed) {
                    $html = $processed;
                }
            }

            // Some SR7 static/shared backgrounds are created or normalized by runtime JS.
            // The runtime guard is deliberately static-slide aware; it does not promote
            // arbitrary first-slide decorative layers just because they are large.
            return $this->inject_sr7_lcp_priority_runtime_script($html);
        }

        private function is_sr7_lcp_markup_candidate($tag_name, $src, $data_src, $dbsrc)
        {
            $tag_name = strtoupper((string) $tag_name);
            if ('SR7-IMG' === $tag_name) {
                return true;
            }

            if ('IMG' !== $tag_name) {
                return false;
            }

            foreach (array($src, $data_src) as $value) {
                $value = is_string($value) ? $value : '';
                if ('' === $value) {
                    continue;
                }

                if (false !== stripos($value, '/revslider/') || false !== stripos($value, '/uploads/uc-images/avif/revslider/') || false !== stripos($value, '/uploads/uc-images/webp/revslider/')) {
                    return true;
                }
            }

            return is_string($dbsrc) && '' !== trim($dbsrc);
        }

        private function set_lcp_marker_on_start_tag($tag, $is_sr7 = false)
        {
            $tag = (string) $tag;
            if ('' === $tag) {
                return $tag;
            }

            $attribute = $is_sr7 ? 'data-ucwp-sr7-lcp' : 'data-ucwp-lcp';
            if (false !== stripos($tag, $attribute . '=')) {
                return $tag;
            }

            return $this->set_or_add_html_tag_attribute($tag, $attribute, '1');
        }

        private function apply_sr7_first_slide_lcp_priority_markup_with_processor($html)
        {
            if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html) {
                return null;
            }

            $candidate = null;
            $first_slide_candidates = $this->find_sr7_first_slide_lcp_candidates($html, 1);
            if (!empty($first_slide_candidates)) {
                $candidate = $first_slide_candidates[0];
            }
            if (null === $candidate) {
                $candidate = $this->find_best_sr7_lcp_candidate($html);
            }
            if (null === $candidate || empty($candidate['raw_url']) || empty($candidate['attribute'])) {
                return null;
            }

            return $this->boost_lcp_candidate_markup_with_processor(
                $html,
                $candidate,
                isset($candidate['tag']) ? (string) $candidate['tag'] : 'SR7-IMG',
                (string) $candidate['attribute'],
                (string) $candidate['raw_url']
            );
        }

        private function add_lcp_priority_attributes_to_start_tag($tag, $include_loading = false)
        {
            $tag = (string) $tag;
            if ('' === $tag || '>' !== substr(rtrim($tag), -1)) {
                return $tag;
            }

            $replacement = $this->set_or_add_html_tag_attribute($tag, 'fetchpriority', 'high');
            $replacement = $this->set_lcp_marker_on_start_tag($replacement, (bool) $include_loading);

            if ($include_loading) {
                $loading = strtolower((string) $this->extract_attribute_from_html_tag($replacement, 'loading'));
                if ('' === $loading || 'lazy' === $loading) {
                    $replacement = $this->set_or_add_html_tag_attribute($replacement, 'loading', 'eager');
                }
            }

            return is_string($replacement) && '' !== $replacement ? $replacement : $tag;
        }

        private function inject_sr7_lcp_priority_runtime_script($html)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '</head>')) {
                return $html;
            }

            if (false !== stripos($html, 'id="ucwp-sr7-lcp-priority"') || false !== stripos($html, "id='ucwp-sr7-lcp-priority'")) {
                return $html;
            }

            $settings = $this->get_settings();
            $selectors = array();
            if (!empty($settings['manual_lcp_hero_selector_list']) && is_array($settings['manual_lcp_hero_selector_list'])) {
                foreach ($settings['manual_lcp_hero_selector_list'] as $selector) {
                    $selector = $this->normalize_manual_lcp_hero_selector($selector);
                    if ('' !== $selector) {
                        $selectors[] = $selector;
                    }
                }
            }
            $selectors = array_values(array_unique($selectors));
            $selectors_json = wp_json_encode($selectors);
            if (!is_string($selectors_json) || '' === $selectors_json) {
                $selectors_json = '[]';
            }

            $script = <<<'HTML'
<script id="ucwp-sr7-lcp-priority">(function(){"use strict";if(window.__ucwpSr7LcpPriorityV107){return;}window.__ucwpSr7LcpPriorityV107=1;var manualSelectors=__UCWP_MANUAL_SELECTORS__;function tag(n){return n&&n.tagName?String(n.tagName).toLowerCase():"";}function abs(u){try{return new URL(String(u||""),document.baseURI).href;}catch(e){return String(u||"");}}function clean(u){u=abs(u).split("#")[0].split("?")[0];return u.replace(/^https?:\/\/[^/]+/i,"");}function imageUrl(n){try{if(!n){return"";}var v=(n.currentSrc||n.src||"");if(!v&&n.getAttribute){v=n.getAttribute("src")||n.getAttribute("data-src")||n.getAttribute("data-bg")||n.getAttribute("data-background")||n.getAttribute("data-bg-image")||"";}if(!v&&n.style&&n.style.backgroundImage){var m=String(n.style.backgroundImage).match(/url\(["']?([^"')]+)["']?\)/i);v=m&&m[1]?m[1]:"";}return v;}catch(e){return"";}}function getPreloadUrl(){try{var l=document.querySelector('link[rel="preload"][as="image"][data-ucwp-lcp-preload="1"]');return l?(l.href||l.getAttribute("href")||""):"";}catch(e){return"";}}function addScope(out,n){if(!n||n.nodeType!==1){return;}for(var i=0;i<out.length;i++){if(out[i]===n){return;}}out.push(n);}function getScopes(){var out=[];if(manualSelectors&&manualSelectors.length){for(var i=0;i<manualSelectors.length;i++){try{document.querySelectorAll(manualSelectors[i]).forEach(function(n){addScope(out,n);});}catch(e){}}}try{document.querySelectorAll("sr7-module,rs-module").forEach(function(n){addScope(out,n);});}catch(e){}return out;}function collect(scope){var nodes=[];try{if(/^(sr7-module-bg|sr7-img|img)$/i.test(tag(scope))){nodes.push(scope);}if(scope&&scope.querySelectorAll){scope.querySelectorAll("sr7-module-bg,sr7-img,img").forEach(function(n){nodes.push(n);});}}catch(e){}return nodes;}function matchesPreload(n,pre){var u=imageUrl(n);if(!u||!pre){return false;}return clean(u)===clean(pre)||abs(u)===abs(pre);}function scoreNoLayout(n,pre){var t=tag(n),u=imageUrl(n).toLowerCase(),score=0;if(matchesPreload(n,pre)){score+=1000000;}if(t==="sr7-module-bg"){score+=250000;}else if(t==="sr7-img"){score+=50000;}if(u.indexOf("revslider/o/")!==-1){score-=180000;}if(/book|cover|product|thumb|thumbnail|logo|icon|avatar/.test(u)){score-=120000;}if(/lcp|hero|background|bg|banner/.test(u)){score+=90000;}return score;}function findBest(pre){var scopes=getScopes(),best=null,bestScore=-999999999;for(var i=0;i<scopes.length;i++){var nodes=collect(scopes[i]);for(var j=0;j<nodes.length;j++){var u=imageUrl(nodes[j]);if(!/\.(avif|webp|png|jpe?g|gif)(\?|#|$)/i.test(u)){continue;}var sc=scoreNoLayout(nodes[j],pre);if(sc>bestScore){best=nodes[j];bestScore=sc;}}}return best;}function mark(n,pre){try{if(!n||n.nodeType!==1){return false;}if(!n.hasAttribute("fetchpriority")){n.setAttribute("fetchpriority","high");n.setAttribute("data-ucwp-added-fetchpriority","1");}else if(n.getAttribute("fetchpriority")!=="high"){n.setAttribute("fetchpriority","high");}n.setAttribute("data-ucwp-sr7-lcp","1");n.setAttribute("data-ucwp-sr7-role",matchesPreload(n,pre)?"preload-matched":"preload-scoped");n.setAttribute("data-ucwp-lcp-runtime-winner","1");n.setAttribute("data-ucwp-lcp-reason",matchesPreload(n,pre)?"sr7-preload-matched-runtime":"sr7-preload-scoped-runtime");if((tag(n)==="img"||tag(n)==="sr7-img")&&(!n.hasAttribute("loading")||n.getAttribute("loading")==="lazy")){n.setAttribute("loading","eager");}if(!n.hasAttribute("decoding")){n.setAttribute("decoding","sync");}window.__ucwpLcpDiscovery=window.__ucwpLcpDiscovery||{};window.__ucwpLcpDiscovery.runtimeWinner={url:imageUrl(n),preload:pre||"",tag:tag(n),id:n.id||"",role:n.getAttribute("data-ucwp-sr7-role")||"",reason:n.getAttribute("data-ucwp-lcp-reason")||""};return true;}catch(e){return false;}}function run(){var pre=getPreloadUrl();var n=findBest(pre);if(n){mark(n,pre);return true;}return false;}function schedule(){try{run();}catch(e){}}document.addEventListener("sr.module.ready",schedule,true);document.addEventListener("SR7_MODULE_READY",schedule,true);document.addEventListener("DOMContentLoaded",schedule,{once:true});if(document.readyState!=="loading"){schedule();}var tries=[100,400,1000,2200];for(var x=0;x<tries.length;x++){setTimeout(schedule,tries[x]);}}());</script>
HTML;

            $script = str_replace('__UCWP_MANUAL_SELECTORS__', $selectors_json, $script);

            return $this->insert_html_before_closing_head($html, $script);
        }

        private function find_manual_lcp_candidate($html)
        {
            $settings = $this->get_settings();
            $entries = isset($settings['lcp_image_priority_override_list']) && is_array($settings['lcp_image_priority_override_list']) ? $settings['lcp_image_priority_override_list'] : array();
            if (empty($entries) || !is_string($html) || '' === $html) {
                return null;
            }

            foreach ($entries as $entry) {
                $needle = trim((string) $entry);
                if ('' === $needle) {
                    continue;
                }

                $tag_candidate = $this->find_lcp_candidate_matching_manual_fragment($html, $needle);
                if (null !== $tag_candidate) {
                    $tag_candidate['score'] = 1000000;
                    $tag_candidate['is_manual'] = true;
                    $tag_candidate['lcp_reason'] = !empty($tag_candidate['lcp_reason']) ? (string) $tag_candidate['lcp_reason'] : 'manual-fragment';
                    return $tag_candidate;
                }

                $equivalent_tag_candidate = $this->find_lcp_candidate_matching_manual_url_equivalent($html, $needle);
                if (null !== $equivalent_tag_candidate) {
                    $equivalent_tag_candidate['score'] = 1000000;
                    $equivalent_tag_candidate['is_manual'] = true;
                    $equivalent_tag_candidate['lcp_reason'] = 'manual-url-equivalent';
                    return $equivalent_tag_candidate;
                }

                $matched_url = $this->find_image_url_in_html_by_fragment($html, $needle);
                if ('' !== $matched_url) {
                    $candidate = $this->build_lcp_candidate_from_values($matched_url, array(
                        'tag' => 'MANUAL',
                        'attribute' => 'manual',
                    ));
                    if (null !== $candidate) {
                        $candidate['score'] = 1000000;
                        $candidate['is_manual'] = true;
                        return $candidate;
                    }
                }

                $normalized = $this->normalize_public_resource_url($needle);
                if ($this->is_lcp_candidate_image_url($normalized)) {
                    return array(
                        'url' => $normalized,
                        'raw_url' => $needle,
                        'attribute' => 'manual',
                        'tag' => 'MANUAL',
                        'score' => 1000000,
                        'is_manual' => true,
                    );
                }
            }

            return null;
        }

        private function find_lcp_candidate_matching_manual_url_equivalent($html, $needle)
        {
            $html = (string) $html;
            $needle = trim((string) $needle);
            if ('' === $html || '' === $needle || false === stripos($html, '<img')) {
                return null;
            }

            $manual_key = $this->normalize_lcp_image_identity_key($needle);
            if ('' === $manual_key) {
                return null;
            }

            if (!preg_match_all('/<img\b[^>]*>/i', $html, $matches)) {
                return null;
            }

            $candidates = array();
            foreach ($matches[0] as $tag_html) {
                $tag_html = (string) $tag_html;
                if ('' === $tag_html) {
                    continue;
                }

                $context = array(
                    'tag'        => 'IMG',
                    'class'      => $this->extract_attribute_from_html_tag($tag_html, 'class'),
                    'id'         => $this->extract_attribute_from_html_tag($tag_html, 'id'),
                    'title'      => $this->extract_attribute_from_html_tag($tag_html, 'title'),
                    'alt'        => $this->extract_attribute_from_html_tag($tag_html, 'alt'),
                    'aria-label' => $this->extract_attribute_from_html_tag($tag_html, 'aria-label'),
                    'width'      => $this->extract_attribute_from_html_tag($tag_html, 'width'),
                    'height'     => $this->extract_attribute_from_html_tag($tag_html, 'height'),
                    'loading'    => $this->extract_attribute_from_html_tag($tag_html, 'loading'),
                    'style'      => $this->extract_attribute_from_html_tag($tag_html, 'style'),
                );

                foreach (array('src', 'data-src', 'data-lazy-src', 'data-lazyload') as $attribute) {
                    $value = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                    if ('' === $value || $this->normalize_lcp_image_identity_key($value) !== $manual_key) {
                        continue;
                    }
                    $candidate = $this->build_lcp_candidate_from_values($value, $context + array('attribute' => $attribute));
                    if (null !== $candidate) {
                        $candidate = $this->hydrate_lcp_candidate_dimensions($candidate, $value);
                        $candidate['manual_lcp_url_equivalent'] = true;
                        $candidate['manual_lcp_url_key'] = $manual_key;
                        $candidate['lcp_reason'] = 'manual-url-equivalent';
                        $candidates[] = $candidate;
                    }
                }

                foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
                    $raw_srcset = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                    if ('' === $raw_srcset) {
                        continue;
                    }
                    foreach ($this->extract_candidate_urls_from_srcset($raw_srcset) as $srcset_url) {
                        if ($this->normalize_lcp_image_identity_key($srcset_url) !== $manual_key) {
                            continue;
                        }
                        $candidate = $this->build_lcp_candidate_from_values($srcset_url, $context + array('attribute' => $attribute));
                        if (null !== $candidate) {
                            $candidate = $this->hydrate_lcp_candidate_dimensions($candidate, $srcset_url);
                            $candidate['manual_lcp_url_equivalent'] = true;
                            $candidate['manual_lcp_url_key'] = $manual_key;
                            $candidate['lcp_reason'] = 'manual-url-equivalent';
                            $candidates[] = $candidate;
                        }
                    }
                }
            }

            if (empty($candidates)) {
                return null;
            }

            $candidates = $this->sort_lcp_candidates_by_area_then_score($candidates);
            return $candidates[0];
        }

        private function find_lcp_candidate_matching_manual_fragment($html, $needle)
        {
            if (!preg_match_all('/<(img|video|div|section|figure|picture|a|sr7-img|sr7-slide|sr7-content|sr7-module)\b[^>]*>/i', (string) $html, $matches)) {
                return null;
            }

            $candidates = array();
            foreach ($matches[0] as $tag_html) {
                if (!$this->manual_lcp_fragment_matches_tag($tag_html, $needle)) {
                    continue;
                }

                $tag_name = '';
                if (preg_match('/^<([a-z0-9:-]+)/i', $tag_html, $tag_match) && !empty($tag_match[1])) {
                    $tag_name = strtoupper((string) $tag_match[1]);
                }

                $context = array(
                    'tag'        => $tag_name,
                    'class'      => $this->extract_attribute_from_html_tag($tag_html, 'class'),
                    'id'         => $this->extract_attribute_from_html_tag($tag_html, 'id'),
                    'title'      => $this->extract_attribute_from_html_tag($tag_html, 'title'),
                    'alt'        => $this->extract_attribute_from_html_tag($tag_html, 'alt'),
                    'aria-label' => $this->extract_attribute_from_html_tag($tag_html, 'aria-label'),
                    'width'      => $this->extract_attribute_from_html_tag($tag_html, 'width'),
                    'height'     => $this->extract_attribute_from_html_tag($tag_html, 'height'),
                    'loading'    => $this->extract_attribute_from_html_tag($tag_html, 'loading'),
                    'style'      => $this->extract_attribute_from_html_tag($tag_html, 'style'),
                );

                foreach (array('src', 'data-src', 'data-lazy-src', 'data-lazyload', 'data-image', 'data-origin', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image', 'poster') as $attribute) {
                    $value = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                    if ('' === $value) {
                        continue;
                    }
                    $candidate = $this->build_lcp_candidate_from_values($value, $context + array('attribute' => $attribute));
                    if (null !== $candidate) {
                        $candidates[] = $candidate;
                    }
                }

                foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
                    foreach ($this->extract_candidate_urls_from_srcset($this->extract_attribute_from_html_tag($tag_html, $attribute)) as $srcset_url) {
                        $candidate = $this->build_lcp_candidate_from_values($srcset_url, $context + array('attribute' => $attribute));
                        if (null !== $candidate) {
                            $candidates[] = $candidate;
                        }
                    }
                }

                foreach ($this->extract_candidate_urls_from_style($context['style']) as $style_url) {
                    $candidate = $this->build_lcp_candidate_from_values($style_url, $context + array('attribute' => 'style'));
                    if (null !== $candidate) {
                        $candidates[] = $candidate;
                    }
                }
            }

            if (empty($candidates)) {
                return null;
            }

            $candidates = $this->sort_lcp_candidates_by_area_then_score($candidates);

            return $candidates[0];
        }

        private function manual_lcp_fragment_matches_tag($tag_html, $needle)
        {
            $tag_html = (string) $tag_html;
            $needle = trim((string) $needle);
            if ('' === $needle) {
                return false;
            }

            if ('#' === substr($needle, 0, 1)) {
                $id = substr($needle, 1);
                return '' !== $id && strtolower($this->extract_attribute_from_html_tag($tag_html, 'id')) === strtolower($id);
            }

            if ('.' === substr($needle, 0, 1)) {
                $class = substr($needle, 1);
                if ('' === $class) {
                    return false;
                }
                $classes = preg_split('/\s+/', strtolower($this->extract_attribute_from_html_tag($tag_html, 'class')));
                return in_array(strtolower($class), is_array($classes) ? $classes : array(), true);
            }

            return false !== stripos($tag_html, $needle);
        }

        private function find_image_url_in_html_by_fragment($html, $needle)
        {
            $needle = trim((string) $needle);
            if ('' === $needle) {
                return '';
            }

            if (preg_match_all('/[^\s"\'<>]+\.(?:avif|webp|png|jpe?g|gif|bmp|heic|heif)(?:\?[^\s"\'<>]*)?/i', (string) $html, $matches)) {
                foreach ($matches[0] as $raw_url) {
                    $candidate_url = trim((string) $raw_url, " \t\n\r\0\x0B()[]{}.,;");
                    if ('' !== $candidate_url && false !== stripos($candidate_url, $needle) && $this->is_lcp_candidate_image_url($candidate_url)) {
                        return $candidate_url;
                    }
                }
            }

            return '';
        }

        private function optimize_lcp_image_markup_with_tag_processor($html)
        {
            $processor = new WP_HTML_Tag_Processor($html);
            $best = $this->find_best_sr7_lcp_candidate($html);

            while ($processor->next_tag()) {
                $candidate = $this->extract_best_lcp_candidate_from_current_tag($processor);
                if (null === $candidate) {
                    continue;
                }

                if (null === $best || $candidate['score'] > $best['score']) {
                    $best = $candidate;
                }
            }

            $wp_post_image_candidate = $this->find_first_wp_post_image_lcp_candidate($html);
            if ($this->should_prefer_wp_post_image_lcp_candidate($wp_post_image_candidate, $best)) {
                $best = $wp_post_image_candidate;
            }

            return $this->apply_lcp_candidate_optimizations($html, $best);
        }

        private function optimize_lcp_image_markup_with_regex($html)
        {
            $best = $this->find_best_sr7_lcp_candidate($html);
            $regex_best = $this->find_best_lcp_candidate_with_regex($html);
            if (null !== $regex_best && (null === $best || $regex_best['score'] > $best['score'])) {
                $best = $regex_best;
            }

            $wp_post_image_candidate = $this->find_first_wp_post_image_lcp_candidate($html);
            if ($this->should_prefer_wp_post_image_lcp_candidate($wp_post_image_candidate, $best)) {
                $best = $wp_post_image_candidate;
            }

            return $this->apply_lcp_candidate_optimizations($html, $best);
        }

        private function extract_best_lcp_candidate_from_current_tag($processor)
        {
            $tag = strtoupper((string) $processor->get_tag());
            if (in_array($tag, array('SCRIPT', 'LINK', 'META', 'NOSCRIPT', 'STYLE', 'IFRAME'), true)) {
                return null;
            }

            $context = array(
                'tag'        => $tag,
                'class'      => (string) $processor->get_attribute('class'),
                'id'         => (string) $processor->get_attribute('id'),
                'title'      => (string) $processor->get_attribute('title'),
                'alt'        => (string) $processor->get_attribute('alt'),
                'aria-label' => (string) $processor->get_attribute('aria-label'),
                'width'      => (string) $processor->get_attribute('width'),
                'height'     => (string) $processor->get_attribute('height'),
                'loading'    => (string) $processor->get_attribute('loading'),
                'style'      => (string) $processor->get_attribute('style'),
            );

            $candidates = array();
            foreach (array('src', 'data-src', 'data-lazy-src', 'data-lazyload', 'data-image', 'data-origin', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image', 'poster') as $attribute) {
                $raw = $processor->get_attribute($attribute);
                if (!is_string($raw) || '' === trim($raw)) {
                    continue;
                }

                $candidate = $this->build_lcp_candidate_from_values($raw, $context + array('attribute' => $attribute));
                if (null !== $candidate) {
                    $candidates[] = $candidate;
                }
            }

            foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
                $raw = $processor->get_attribute($attribute);
                if (!is_string($raw) || '' === trim($raw)) {
                    continue;
                }
                foreach ($this->extract_candidate_urls_from_srcset($raw) as $srcset_url) {
                    $candidate = $this->build_lcp_candidate_from_values($srcset_url, $context + array('attribute' => $attribute));
                    if (null !== $candidate) {
                        $candidates[] = $candidate;
                    }
                }
            }

            foreach ($this->extract_candidate_urls_from_style($context['style']) as $style_url) {
                $candidate = $this->build_lcp_candidate_from_values($style_url, $context + array('attribute' => 'style'));
                if (null !== $candidate) {
                    $candidates[] = $candidate;
                }
            }

            if (empty($candidates)) {
                return null;
            }

            $candidates = $this->sort_lcp_candidates_by_area_then_score($candidates);

            return $candidates[0];
        }

        private function find_best_lcp_candidate_with_regex($html)
        {
            $candidates = array();
            if (preg_match_all('/<(img|video|div|section|figure|picture|a|sr7-img|sr7-slide|sr7-content|sr7-module)\b[^>]*>/i', $html, $matches)) {
                foreach ($matches[0] as $tag_html) {
                    $tag_name = '';
                    if (preg_match('/^<([a-z0-9:-]+)/i', $tag_html, $tag_match) && !empty($tag_match[1])) {
                        $tag_name = strtoupper((string) $tag_match[1]);
                    }

                    $context = array(
                        'tag'        => $tag_name,
                        'class'      => $this->extract_attribute_from_html_tag($tag_html, 'class'),
                        'id'         => $this->extract_attribute_from_html_tag($tag_html, 'id'),
                        'title'      => $this->extract_attribute_from_html_tag($tag_html, 'title'),
                        'alt'        => $this->extract_attribute_from_html_tag($tag_html, 'alt'),
                        'aria-label' => $this->extract_attribute_from_html_tag($tag_html, 'aria-label'),
                        'width'      => $this->extract_attribute_from_html_tag($tag_html, 'width'),
                        'height'     => $this->extract_attribute_from_html_tag($tag_html, 'height'),
                        'loading'    => $this->extract_attribute_from_html_tag($tag_html, 'loading'),
                        'style'      => $this->extract_attribute_from_html_tag($tag_html, 'style'),
                    );

                    foreach (array('src', 'data-src', 'data-lazy-src', 'data-lazyload', 'data-image', 'data-origin', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image', 'poster') as $attribute) {
                        $value = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                        if ('' === $value) {
                            continue;
                        }
                        $candidate = $this->build_lcp_candidate_from_values($value, $context + array('attribute' => $attribute));
                        if (null !== $candidate) {
                            $candidates[] = $candidate;
                        }
                    }

                    foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
                        foreach ($this->extract_candidate_urls_from_srcset($this->extract_attribute_from_html_tag($tag_html, $attribute)) as $srcset_url) {
                            $candidate = $this->build_lcp_candidate_from_values($srcset_url, $context + array('attribute' => $attribute));
                            if (null !== $candidate) {
                                $candidates[] = $candidate;
                            }
                        }
                    }

                    foreach ($this->extract_candidate_urls_from_style($context['style']) as $style_url) {
                        $candidate = $this->build_lcp_candidate_from_values($style_url, $context + array('attribute' => 'style'));
                        if (null !== $candidate) {
                            $candidates[] = $candidate;
                        }
                    }
                }
            }

            if (empty($candidates)) {
                return null;
            }

            $candidates = $this->sort_lcp_candidates_by_area_then_score($candidates);

            return $candidates[0];
        }

        private function build_lcp_candidate_from_values($raw_url, array $context = array())
        {
            $normalized_url = $this->normalize_public_resource_url($raw_url);
            if (!$this->is_lcp_candidate_image_url($normalized_url)) {
                return null;
            }

            $score = 0;
            $attribute = strtolower((string) (isset($context['attribute']) ? $context['attribute'] : ''));
            $tag = strtoupper((string) (isset($context['tag']) ? $context['tag'] : ''));

            $attribute_weights = array(
                'src' => 60,
                'srcset' => 55,
                'data-src' => 80,
                'data-lazy-src' => 80,
                'data-lazyload' => 92,
                'data-lazyload-src' => 92,
                'data-image' => 88,
                'data-origin' => 78,
                'data-orig' => 78,
                'data-srcset' => 72,
                'data-lazy-srcset' => 72,
                'data-lazyload-srcset' => 72,
                'data-bg' => 84,
                'data-background' => 84,
                'data-bg-url' => 84,
                'data-bg-image' => 84,
                'data-background-image' => 84,
                'data-lazy-bg' => 84,
                'data-lazy-background' => 84,
                'data-thumb' => 30,
                'poster' => 70,
                'style' => 115,
                'script' => 84,
            );
            $score += isset($attribute_weights[$attribute]) ? (int) $attribute_weights[$attribute] : 20;

            $tag_weights = array(
                'IMG' => 20,
                'VIDEO' => 15,
                'DIV' => 10,
                'SECTION' => 10,
                'FIGURE' => 8,
                'PICTURE' => 8,
                'SOURCE' => 10,
                'A' => 4,
                'SR7-IMG' => 110,
                'SR7-MODULE-BG' => 150,
                'SR7-SLIDE' => 55,
                'SR7-CONTENT' => 34,
                'SR7-MODULE' => 60,
                'STYLE' => 8,
                'SCRIPT' => 18,
            );
            $score += isset($tag_weights[$tag]) ? (int) $tag_weights[$tag] : 0;

            $meta_haystack = strtolower(implode(' ', array_filter(array(
                isset($context['class']) ? $context['class'] : '',
                isset($context['id']) ? $context['id'] : '',
                isset($context['title']) ? $context['title'] : '',
                isset($context['alt']) ? $context['alt'] : '',
                isset($context['aria-label']) ? $context['aria-label'] : '',
                isset($context['style']) ? $context['style'] : '',
                $normalized_url,
            ))));

            if (!empty($context['inside_manual_lcp_selector']) || !empty($context['manual_lcp_hero_selector'])) {
                $score += 900;
            }

            if ('style' === $attribute && false !== strpos((string) (isset($context['style']) ? $context['style'] : ''), 'url(')) {
                // Rendered inline background/background shorthand URLs inside a manual hero
                // block are strong LCP signals across sliders and custom builders.
                $score += 520;
            }

            if (preg_match('/\.(avif|webp)(?:$|\?)/i', $normalized_url) && false !== strpos($meta_haystack, $normalized_url)) {
                // Prefer optimized URLs only when they are actually present in rendered HTML/CSS.
                $score += 160;
            }

            foreach (array('lcp', 'hero', 'banner', 'slider', 'slide', 'cover', 'featured', 'showcase', 'intro', 'splash', 'main', 'cta', 'background', 'bg') as $positive_term) {
                if (false !== strpos($meta_haystack, $positive_term)) {
                    $score += 18;
                }
            }

            $is_wp_post_image = ('IMG' === $tag && (false !== strpos($meta_haystack, 'wp-post-image') || false !== strpos($meta_haystack, 'post-thumbnail')));
            if ($is_wp_post_image) {
                $score += 120;
                if (false !== strpos($meta_haystack, 'attachment-') || false !== strpos($meta_haystack, 'size-')) {
                    $score += 35;
                }
            }

            if (false !== strpos($meta_haystack, '/wp-content/uploads/revslider/')) {
                $score += 160;
            }
            if (false !== strpos($meta_haystack, 'sr7_') || false !== strpos($meta_haystack, 'sr7-')) {
                $score += 90;
            }

            foreach (array('logo', 'brand', 'branding', 'header', 'nav', 'menu', 'icon', 'avatar', 'thumb', 'thumbnail', 'badge', 'favicon') as $negative_term) {
                if (false !== strpos($meta_haystack, $negative_term)) {
                    $score -= 45;
                }
            }

            foreach (array('admin', 'preview', 'placeholder', 'spinner', 'loading') as $negative_term) {
                if (false !== strpos($meta_haystack, $negative_term)) {
                    $score -= 30;
                }
            }

            if (false !== strpos((string) (isset($context['loading']) ? $context['loading'] : ''), 'lazy')) {
                $score -= 10;
            }

            $style = strtolower((string) (isset($context['style']) ? $context['style'] : ''));
            if (false !== strpos($style, 'display:none') || false !== strpos($style, 'visibility:hidden')) {
                $score -= 120;
            }

            $width = (int) preg_replace('/[^0-9]/', '', (string) (isset($context['width']) ? $context['width'] : ''));
            $height = (int) preg_replace('/[^0-9]/', '', (string) (isset($context['height']) ? $context['height'] : ''));
            if (($width <= 0 || $height <= 0) && '' !== $style) {
                if ($width <= 0 && preg_match('/(?:^|;)\s*width\s*:\s*([0-9]+(?:\.[0-9]+)?)px/i', $style, $style_width_match)) {
                    $width = (int) round((float) $style_width_match[1]);
                }
                if ($height <= 0 && preg_match('/(?:^|;)\s*height\s*:\s*([0-9]+(?:\.[0-9]+)?)px/i', $style, $style_height_match)) {
                    $height = (int) round((float) $style_height_match[1]);
                }
            }
            if ($width > 0 && $height > 0) {
                $area = $width * $height;
                if ($area >= 120000) {
                    $score += 50;
                } elseif ($area >= 40000) {
                    $score += 30;
                } elseif ($area <= 20000) {
                    $score -= 20;
                }

                if (!empty($is_wp_post_image)) {
                    if ($area >= 120000) {
                        $score += 260;
                    } elseif ($area >= 40000) {
                        $score += 180;
                    } elseif ($area <= 20000) {
                        $score -= 220;
                    }
                }
            } elseif (0 === $width || 0 === $height) {
                $score -= 10;
                if (!empty($is_wp_post_image)) {
                    $score += 60;
                }
            }

            $area = ($width > 0 && $height > 0) ? ($width * $height) : 0;

            return array(
                'url' => $normalized_url,
                'raw_url' => (string) $raw_url,
                'attribute' => $attribute,
                'tag' => $tag,
                'score' => $score,
                'width' => $width,
                'height' => $height,
                'area' => $area,
                'source' => $attribute,
            );
        }

        private function normalize_lcp_image_identity_key($url)
        {
            $url = str_replace('\\/', '/', html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
            if ('' === trim($url)) {
                return '';
            }

            $url = preg_replace('/[#?].*$/', '', $url);
            $path = wp_parse_url($url, PHP_URL_PATH);
            if (!is_string($path) || '' === $path) {
                $path = $url;
            }

            $base = rawurldecode(basename($path));
            $base = preg_replace('/\.(?:jpe?g|png|gif|webp|avif)$/i', '', $base);
            $base = preg_replace('/-\d+x\d+$/', '', $base);
            $base = preg_replace('/-scaled$/i', '', $base);
            $base = trim(strtolower((string) $base));
            if (strlen($base) < 4) {
                return '';
            }

            return $base;
        }

        private function extract_lcp_primary_image_keys_from_metadata($html)
        {
            $html = (string) $html;
            if ('' === $html) {
                return array();
            }

            $keys = array();
            $patterns = array(
                '/<meta\b[^>]+(?:property|name|itemprop)\s*=\s*(["\'])(?:og:image(?::url)?|twitter:image(?::src)?|image|thumbnailUrl)\1[^>]+content\s*=\s*(["\'])(.*?)\2[^>]*>/i',
                '/<meta\b[^>]+content\s*=\s*(["\'])(.*?)\1[^>]+(?:property|name|itemprop)\s*=\s*(["\'])(?:og:image(?::url)?|twitter:image(?::src)?|image|thumbnailUrl)\3[^>]*>/i',
                '/"(?:url|contentUrl|thumbnailUrl|image)"\s*:\s*"([^"\\]*(?:\\.[^"\\]*)*\.(?:jpe?g|png|webp|avif)(?:[^"\\]*(?:\\.[^"\\]*)*)?)"/i',
            );

            foreach ($patterns as $index => $pattern) {
                if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($matches as $match) {
                    $url = '';
                    if (0 === $index) {
                        $url = isset($match[3]) ? (string) $match[3] : '';
                    } elseif (1 === $index) {
                        $url = isset($match[2]) ? (string) $match[2] : '';
                    } else {
                        $url = isset($match[1]) ? (string) $match[1] : '';
                    }

                    $url = stripcslashes(str_replace('\\/', '/', $url));
                    if (false === stripos($url, '/wp-content/uploads/') && false === stripos($url, 'wp-content/uploads')) {
                        continue;
                    }

                    $key = $this->normalize_lcp_image_identity_key($url);
                    if ('' !== $key) {
                        $keys[$key] = true;
                    }

                    if (count($keys) >= 12) {
                        break 2;
                    }
                }
            }

            return array_keys($keys);
        }

        private function lcp_image_tag_matches_primary_metadata($tag_html, array $primary_keys)
        {
            if (empty($primary_keys)) {
                return false;
            }

            foreach (array('src', 'data-src', 'data-lazy-src', 'data-lazyload') as $attribute) {
                $value = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                if ('' === $value) {
                    continue;
                }
                $key = $this->normalize_lcp_image_identity_key($value);
                if ('' !== $key && in_array($key, $primary_keys, true)) {
                    return true;
                }
            }

            foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
                $raw_srcset = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                if ('' === $raw_srcset) {
                    continue;
                }
                foreach ($this->extract_candidate_urls_from_srcset($raw_srcset) as $srcset_url) {
                    $key = $this->normalize_lcp_image_identity_key($srcset_url);
                    if ('' !== $key && in_array($key, $primary_keys, true)) {
                        return true;
                    }
                }
            }

            return false;
        }

        private function is_lcp_candidate_inside_navigation_context($html, $offset)
        {
            $html = (string) $html;
            $offset = max(0, (int) $offset);
            if ('' === $html) {
                return false;
            }

            $before = strtolower(substr($html, 0, $offset));
            $last_nav_open = strrpos($before, '<nav');
            $last_nav_close = strrpos($before, '</nav');
            if (false !== $last_nav_open && (false === $last_nav_close || $last_nav_open > $last_nav_close)) {
                return true;
            }

            $near = strtolower(substr($html, max(0, $offset - 5000), 7000));
            $has_main_context = (bool) preg_match('/<(?:article|main)\b|\brole\s*=\s*(["\'])main\1|\b(?:class|id)\s*=\s*(["\'])[^"\']*(?:entry-content|post-content|article-body|single-content|main-content)[^"\']*\2/i', $near);
            if ($has_main_context) {
                return false;
            }

            return (bool) preg_match('/<(?:nav|ul|ol|li|div|section)\b[^>]*(?:\brole\s*=\s*(["\'])navigation\1|\b(?:class|id)\s*=\s*(["\'])[^"\']*(?:mega[-_ ]?menu|main[-_ ]?nav|mobile[-_ ]?menu|mob[-_ ]?menu|sub[-_ ]?menu|nav[-_ ]?bar|navbar|navigation|menu)[^"\']*\2)/i', $near);
        }

        private function lcp_candidate_has_main_article_context($html, $offset)
        {
            $html = (string) $html;
            $offset = max(0, (int) $offset);
            if ('' === $html) {
                return false;
            }

            $near = strtolower(substr($html, max(0, $offset - 9000), 14000));
            return (bool) preg_match('/<(?:article|main)\b|\brole\s*=\s*(["\'])main\1|\b(?:class|id)\s*=\s*(["\'])[^"\']*(?:entry-content|post-content|article-body|single-content|main-content|hentry|post-[0-9]+|type-post|has-post-thumbnail)[^"\']*\2/i', $near);
        }

        private function find_first_wp_post_image_lcp_candidate($html)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<img')) {
                return null;
            }

            if (false !== stripos($html, '<sr7-') || false !== stripos($html, 'sr7-module') || false !== stripos($html, '/wp-content/uploads/revslider/')) {
                return null;
            }

            $html_lc = strtolower($html);
            $page_has_featured_context = false !== strpos($html_lc, 'wp-post-image')
                || false !== strpos($html_lc, 'post-thumbnail')
                || false !== strpos($html_lc, 'featured')
                || false !== strpos($html_lc, 'wp-singular')
                || false !== strpos($html_lc, 'single-post')
                || false !== strpos($html_lc, 'single-')
                || false !== strpos($html_lc, 'archive')
                || false !== strpos($html_lc, 'blog')
                || false !== strpos($html_lc, 'og:image')
                || false !== strpos($html_lc, 'twitter:image')
                || false !== strpos($html_lc, 'primaryimageofpage');
            if (!$page_has_featured_context) {
                return null;
            }

            if (!preg_match_all('/<img\b[^>]*>/i', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                return null;
            }

            $primary_image_keys = $this->extract_lcp_primary_image_keys_from_metadata($html);
            $is_wp_singular_context = false !== strpos($html_lc, 'wp-singular') || false !== strpos($html_lc, 'single-post') || false !== strpos($html_lc, 'single-');
            $checked = 0;
            $candidates = array();

            foreach ($matches as $match) {
                $tag_html = isset($match[0][0]) ? (string) $match[0][0] : '';
                $offset = isset($match[0][1]) ? (int) $match[0][1] : 0;
                if ('' === $tag_html) {
                    continue;
                }

                if ($this->is_lcp_candidate_inside_navigation_context($html, $offset)) {
                    continue;
                }

                $class = $this->extract_attribute_from_html_tag($tag_html, 'class');
                $id = $this->extract_attribute_from_html_tag($tag_html, 'id');
                $title = $this->extract_attribute_from_html_tag($tag_html, 'title');
                $alt = $this->extract_attribute_from_html_tag($tag_html, 'alt');
                $aria_label = $this->extract_attribute_from_html_tag($tag_html, 'aria-label');
                $style = $this->extract_attribute_from_html_tag($tag_html, 'style');
                $src_attr = $this->extract_attribute_from_html_tag($tag_html, 'src');
                $srcset_attr = $this->extract_attribute_from_html_tag($tag_html, 'srcset');

                $meta_haystack = strtolower(implode(' ', array_filter(array(
                    $class,
                    $id,
                    $title,
                    $alt,
                    $aria_label,
                    $style,
                    $src_attr,
                    $srcset_attr,
                ))));

                $is_wp_post_image = false !== strpos($meta_haystack, 'wp-post-image') || false !== strpos($meta_haystack, 'post-thumbnail');
                $has_featured_term = (bool) preg_match('/(?:^|[\s_\-])featured(?:[\s_\-]|$)|featured[-_ ]?(?:image|img|post|media|thumb|thumbnail)/i', $meta_haystack);
                $has_attachment_size_pair = false !== strpos($meta_haystack, 'attachment-') && false !== strpos($meta_haystack, 'size-');
                $matches_primary_image = $this->lcp_image_tag_matches_primary_metadata($tag_html, $primary_image_keys);
                $has_main_article_context = $this->lcp_candidate_has_main_article_context($html, $offset);

                if (!$matches_primary_image && !$is_wp_post_image && !$has_featured_term && !($is_wp_singular_context && $has_attachment_size_pair)) {
                    continue;
                }

                $checked++;
                if ($checked > 120) {
                    break;
                }

                if (preg_match('/\b(?:logo|brand|branding|avatar|icon|sprite|favicon|emoji|smiley|wp-smiley)\b/i', $meta_haystack)) {
                    continue;
                }

                $context = array(
                    'tag'        => 'IMG',
                    'class'      => $class,
                    'id'         => $id,
                    'title'      => $title,
                    'alt'        => $alt,
                    'aria-label' => $aria_label,
                    'width'      => $this->extract_attribute_from_html_tag($tag_html, 'width'),
                    'height'     => $this->extract_attribute_from_html_tag($tag_html, 'height'),
                    'loading'    => $this->extract_attribute_from_html_tag($tag_html, 'loading'),
                    'style'      => $style,
                );

                $candidate_values = array();
                foreach (array('src', 'data-src', 'data-lazy-src', 'data-lazyload') as $attribute) {
                    $value = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                    if ('' !== $value) {
                        $candidate_values[] = array($attribute, $value);
                    }
                }
                foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
                    $raw_srcset = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                    if ('' === $raw_srcset) {
                        continue;
                    }
                    foreach ($this->extract_candidate_urls_from_srcset($raw_srcset) as $srcset_url) {
                        $candidate_values[] = array($attribute, $srcset_url);
                    }
                }

                foreach ($candidate_values as $candidate_value) {
                    $attribute = isset($candidate_value[0]) ? (string) $candidate_value[0] : '';
                    $value = isset($candidate_value[1]) ? (string) $candidate_value[1] : '';
                    if ('' === $attribute || '' === $value) {
                        continue;
                    }

                    $candidate = $this->build_lcp_candidate_from_values($value, $context + array('attribute' => $attribute));
                    if (null === $candidate) {
                        continue;
                    }

                    $candidate = $this->hydrate_lcp_candidate_dimensions($candidate, $value);
                    $area = isset($candidate['area']) ? (int) $candidate['area'] : 0;
                    if ($area > 0 && $area <= 20000) {
                        continue;
                    }

                    $reason = 'wp-post-image-fallback';
                    if ($matches_primary_image) {
                        $reason = 'primary-image-metadata-match';
                    } elseif ($has_featured_term) {
                        $reason = 'featured-image-fallback';
                    } elseif ($is_wp_singular_context && $has_attachment_size_pair) {
                        $reason = 'wp-singular-featured-fallback';
                    }

                    $boost = 520;
                    if ($matches_primary_image) {
                        $boost += 2400;
                    }
                    if (!empty($has_main_article_context)) {
                        $boost += 760;
                    }
                    if (!empty($is_wp_post_image)) {
                        $boost += 220;
                    }
                    if (!empty($has_featured_term)) {
                        $boost += 180;
                    }
                    if (!empty($is_wp_singular_context) && !empty($has_attachment_size_pair)) {
                        $boost += 120;
                    }

                    $candidate['score'] = isset($candidate['score']) ? ((int) $candidate['score'] + $boost) : $boost;
                    $candidate['tag_offset'] = $offset;
                    $candidate['lcp_reason'] = $reason;
                    $candidate['source'] = 'featured-image-fallback';
                    $candidate['is_featured_fallback'] = true;
                    $candidate['is_wp_post_image_fallback'] = $is_wp_post_image;
                    $candidate['is_wp_singular_fallback'] = $is_wp_singular_context;
                    $candidate['is_primary_image_metadata_match'] = $matches_primary_image;
                    $candidate['has_main_article_context'] = $has_main_article_context;
                    $candidate['featured_match'] = $matches_primary_image ? 'primary-image-metadata' : ($has_featured_term ? 'featured' : ($has_attachment_size_pair ? 'attachment-size' : 'wp-post-image'));
                    $candidates[] = $candidate;
                }
            }

            if (empty($candidates)) {
                return null;
            }

            usort($candidates, function ($left, $right) {
                $left_primary = !empty($left['is_primary_image_metadata_match']) ? 1 : 0;
                $right_primary = !empty($right['is_primary_image_metadata_match']) ? 1 : 0;
                if ($left_primary !== $right_primary) {
                    return $right_primary <=> $left_primary;
                }

                $left_main = !empty($left['has_main_article_context']) ? 1 : 0;
                $right_main = !empty($right['has_main_article_context']) ? 1 : 0;
                if ($left_main !== $right_main) {
                    return $right_main <=> $left_main;
                }

                $left_score = isset($left['score']) ? (int) $left['score'] : 0;
                $right_score = isset($right['score']) ? (int) $right['score'] : 0;
                if ($left_score !== $right_score) {
                    return $right_score <=> $left_score;
                }

                $left_area = isset($left['area']) ? (int) $left['area'] : 0;
                $right_area = isset($right['area']) ? (int) $right['area'] : 0;
                if ($left_area !== $right_area) {
                    return $right_area <=> $left_area;
                }

                $left_offset = isset($left['tag_offset']) ? (int) $left['tag_offset'] : 0;
                $right_offset = isset($right['tag_offset']) ? (int) $right['tag_offset'] : 0;
                return $left_offset <=> $right_offset;
            });

            return $candidates[0];
        }

        private function should_prefer_wp_post_image_lcp_candidate($wp_post_image_candidate, $current_best)
        {
            if (null === $wp_post_image_candidate || empty($wp_post_image_candidate['url'])) {
                return false;
            }

            if (null === $current_best || empty($current_best['url'])) {
                return true;
            }

            if (!empty($current_best['is_sr7'])) {
                return false;
            }

            $candidate_area = isset($wp_post_image_candidate['area']) ? (int) $wp_post_image_candidate['area'] : 0;
            $best_area = isset($current_best['area']) ? (int) $current_best['area'] : 0;
            if ($candidate_area > 0 && $candidate_area <= 20000) {
                return false;
            }

            if ($best_area <= 0) {
                return true;
            }

            if (!empty($wp_post_image_candidate['is_primary_image_metadata_match']) && $candidate_area >= 40000) {
                return true;
            }

            if (!empty($wp_post_image_candidate['has_main_article_context']) && !empty($wp_post_image_candidate['is_wp_post_image_fallback']) && $candidate_area >= 40000) {
                return true;
            }

            if (!empty($wp_post_image_candidate['is_featured_fallback']) && $candidate_area >= 40000) {
                // Homepage/archive/singular themes often put the real LCP in the first
                // .wp-post-image / featured image while another background/helper image
                // can win the generic scorer. Keep SR7/manual logic above this, but let
                // strong featured-image markup win over generic non-SR7 candidates.
                if (!empty($wp_post_image_candidate['is_wp_post_image_fallback'])) {
                    return true;
                }

                if (!empty($wp_post_image_candidate['featured_match']) && in_array((string) $wp_post_image_candidate['featured_match'], array('featured', 'attachment-size'), true)) {
                    return $candidate_area >= (int) floor($best_area * 0.55);
                }
            }

            if ($candidate_area >= 40000 && $candidate_area >= (int) floor($best_area * 0.75)) {
                return true;
            }

            $candidate_score = isset($wp_post_image_candidate['score']) ? (int) $wp_post_image_candidate['score'] : 0;
            $best_score = isset($current_best['score']) ? (int) $current_best['score'] : 0;
            return $candidate_area >= 40000 && $candidate_score > ($best_score + 180);
        }

        private function hydrate_lcp_candidate_dimensions(array $candidate, $fallback_url = '')
        {
            $width = isset($candidate['width']) ? (int) $candidate['width'] : 0;
            $height = isset($candidate['height']) ? (int) $candidate['height'] : 0;

            $urls = array();
            if (!empty($candidate['url'])) {
                $urls[] = (string) $candidate['url'];
            }
            if ('' !== (string) $fallback_url) {
                $urls[] = (string) $fallback_url;
            }
            if (!empty($candidate['source_url'])) {
                $urls[] = (string) $candidate['source_url'];
            }

            foreach (array_values(array_unique($urls)) as $url) {
                $dimensions = $this->get_public_image_dimensions($url);
                if (!empty($dimensions)) {
                    $width = isset($dimensions['width']) ? (int) $dimensions['width'] : $width;
                    $height = isset($dimensions['height']) ? (int) $dimensions['height'] : $height;
                    if ($width > 0 && $height > 0) {
                        break;
                    }
                }
            }

            $candidate['width'] = $width;
            $candidate['height'] = $height;
            $candidate['area'] = ($width > 0 && $height > 0) ? ($width * $height) : 0;

            return $candidate;
        }

        private function compare_lcp_candidates_by_area_then_score($left, $right)
        {
            $left_area = isset($left['area']) ? (int) $left['area'] : ((isset($left['width'], $left['height'])) ? ((int) $left['width'] * (int) $left['height']) : 0);
            $right_area = isset($right['area']) ? (int) $right['area'] : ((isset($right['width'], $right['height'])) ? ((int) $right['width'] * (int) $right['height']) : 0);
            if ($left_area !== $right_area) {
                return $right_area <=> $left_area;
            }

            $left_score = isset($left['score']) ? (int) $left['score'] : 0;
            $right_score = isset($right['score']) ? (int) $right['score'] : 0;
            if ($left_score !== $right_score) {
                return $right_score <=> $left_score;
            }

            $left_offset = isset($left['tag_offset']) ? (int) $left['tag_offset'] : (isset($left['offset']) ? (int) $left['offset'] : 0);
            $right_offset = isset($right['tag_offset']) ? (int) $right['tag_offset'] : (isset($right['offset']) ? (int) $right['offset'] : 0);
            return $left_offset <=> $right_offset;
        }

        private function sort_lcp_candidates_by_area_then_score(array $candidates)
        {
            usort($candidates, function ($left, $right) {
                return $this->compare_lcp_candidates_by_area_then_score($left, $right);
            });
            return $candidates;
        }

        private function apply_lcp_candidate_optimizations($html, $best)
        {
            if (null === $best || empty($best['url'])) {
                if (false !== stripos((string) $html, 'sr7-')) {
                    $this->record_analytics_sr7_lcp(array(
                        'detected' => 0,
                        'preloadsInjected' => 0,
                        'skipped' => 0,
                        'unresolved' => 1,
                    ));
                }
                return $html;
            }

            $updated = $this->boost_lcp_candidate_markup($html, $best);
            $preload = $this->inject_lcp_preload_link($updated, $best['url']);

            if (!empty($best['is_sr7'])) {
                $preloads_injected = ($preload !== $updated) ? 1 : 0;
                $this->record_analytics_sr7_lcp(array(
                    'detected' => 1,
                    'preloadsInjected' => $preloads_injected,
                    'skipped' => $preloads_injected ? 0 : 1,
                    'unresolved' => 0,
                ));
            }

            return $preload;
        }

        private function find_sr7_static_slide_blocks($html)
        {
            $html = (string) $html;
            if ('' === $html || false === stripos($html, 'static')) {
                return array();
            }

            $blocks = array();

            $patterns = array(
                '~<sr7-staticslide\b[^>]*>.*?</sr7-staticslide>~is',
                '~<sr7-slide\b[^>]*(?:staticslide|static-slide|data-key\s*=\s*["\']static["\']|data-static)[^>]*>.*?</sr7-slide>~is',
                '~<[^>]+\b(?:id|class)\s*=\s*(["\'])[^"\']*(?:staticslide|static-slide|sr7-staticslide)[^"\']*\1[^>]*>.*?</[^>]+>~is',
            );

            foreach ($patterns as $pattern) {
                if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                foreach ($matches as $match) {
                    $block = isset($match[0][0]) ? (string) $match[0][0] : '';
                    $offset = isset($match[0][1]) ? (int) $match[0][1] : 0;
                    if ('' === $block) {
                        continue;
                    }

                    $blocks[] = array(
                        'html' => $block,
                        'offset' => $offset,
                    );
                }
            }

            $unique = array();
            $seen = array();
            foreach ($blocks as $block) {
                $key = md5((string) $block['html']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $unique[] = $block;
            }

            return $unique;
        }

        private function find_sr7_static_slide_lcp_candidates($html, $limit = 1)
        {
            $html = str_replace('\\/', '/', (string) $html);
            $limit = max(1, min(3, (int) $limit));
            if ('' === $html || false === stripos($html, 'sr7')) {
                return array();
            }

            $contexts = array();

            foreach ($this->find_sr7_static_slide_blocks($html) as $block) {
                $block_html = isset($block['html']) ? (string) $block['html'] : '';
                if ('' === $block_html) {
                    continue;
                }

                $contexts[] = array(
                    'html' => $block_html,
                    'offset' => isset($block['offset']) ? (int) $block['offset'] : 0,
                    'source' => 'static-block',
                );
            }

            if (preg_match_all("~(?:sr7-staticslide|staticslide|static-slide|static\\s+slide|data-key\\s*=\\s*[\"']static[\"']|data-static)~i", $html, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $pos = isset($match[1]) ? (int) $match[1] : 0;
                    $start = max(0, $pos - 4000);
                    $contexts[] = array(
                        'html' => substr($html, $start, 90000),
                        'offset' => $start,
                        'source' => 'static-window',
                    );
                }
            }

            if (empty($contexts)) {
                return array();
            }

            $unique_contexts = array();
            $seen_contexts = array();
            foreach ($contexts as $context) {
                $context_html = isset($context['html']) ? (string) $context['html'] : '';
                if ('' === $context_html) {
                    continue;
                }
                $key = md5($context_html);
                if (isset($seen_contexts[$key])) {
                    continue;
                }
                $seen_contexts[$key] = true;
                $unique_contexts[] = $context;
            }

            $candidates = array();
            foreach ($unique_contexts as $context_index => $context) {
                foreach ($this->extract_sr7_static_context_lcp_candidates(
                    isset($context['html']) ? (string) $context['html'] : '',
                    isset($context['offset']) ? (int) $context['offset'] : 0,
                    (int) $context_index,
                    isset($context['source']) ? (string) $context['source'] : 'static-context'
                ) as $candidate) {
                    $candidates[] = $candidate;
                }
            }

            if (empty($candidates)) {
                return array();
            }

            usort($candidates, function ($left, $right) {
                return (int) $right['score'] <=> (int) $left['score'];
            });

            return $this->dedupe_lcp_candidates_by_url($candidates, $limit);
        }

        private function extract_sr7_static_context_lcp_candidates($context_html, $context_offset = 0, $context_index = 0, $context_source = 'static-context')
        {
            $context_html = (string) $context_html;
            if ('' === $context_html) {
                return array();
            }

            if (!preg_match_all('/<(sr7-module-bg|sr7-img|img|div|section|figure|picture|sr7-slide|sr7-content)\\b[^>]*>/i', $context_html, $tag_matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                return array();
            }

            $candidates = array();
            foreach ($tag_matches as $tag_index => $tag_match) {
                $tag_html = isset($tag_match[0][0]) ? (string) $tag_match[0][0] : '';
                $tag_name = isset($tag_match[1][0]) ? strtoupper((string) $tag_match[1][0]) : 'IMG';
                $relative_tag_offset = isset($tag_match[0][1]) ? (int) $tag_match[0][1] : 0;
                if ('' === $tag_html) {
                    continue;
                }

                $near_context = strtolower(substr($context_html, max(0, $relative_tag_offset - 1800), 5200));
                $context = array(
                    'tag'        => $tag_name,
                    'class'      => $this->extract_attribute_from_html_tag($tag_html, 'class'),
                    'id'         => $this->extract_attribute_from_html_tag($tag_html, 'id'),
                    'title'      => $this->extract_attribute_from_html_tag($tag_html, 'title'),
                    'alt'        => $this->extract_attribute_from_html_tag($tag_html, 'alt'),
                    'aria-label' => $this->extract_attribute_from_html_tag($tag_html, 'aria-label'),
                    'width'      => $this->extract_attribute_from_html_tag($tag_html, 'width'),
                    'height'     => $this->extract_attribute_from_html_tag($tag_html, 'height'),
                    'loading'    => $this->extract_attribute_from_html_tag($tag_html, 'loading'),
                    'style'      => $this->extract_attribute_from_html_tag($tag_html, 'style') . ' sr7 static slide shared background module-bg ' . $near_context,
                );

                foreach (array('src', 'data-src', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image', 'poster', 'style', 'data-dbsrc') as $attribute) {
                    $values = array();
                    if ('style' === $attribute) {
                        $values = $this->extract_candidate_urls_from_style($this->extract_attribute_from_html_tag($tag_html, 'style'));
                    } else {
                        $value = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                        if ('' !== $value) {
                            $values[] = $value;
                        }
                    }

                    foreach ($values as $value) {
                        $raw_url = (string) $value;
                        if ('data-dbsrc' === $attribute && '' !== $raw_url) {
                            $decoded = base64_decode($raw_url, true);
                            if (is_string($decoded) && '' !== $decoded) {
                                $raw_url = $decoded;
                            }
                        }

                        if (0 === strpos($raw_url, '//')) {
                            $raw_url = (is_ssl() ? 'https:' : 'http:') . $raw_url;
                        } elseif (0 === strpos($raw_url, '/')) {
                            $raw_url = $this->absolutize_public_resource_url($raw_url);
                        }

                        $normalized_source_url = $this->normalize_public_resource_url($raw_url);
                        if ('' === $normalized_source_url || !$this->is_lcp_candidate_image_url($normalized_source_url)) {
                            continue;
                        }

                        $preferred_url = $this->prefer_existing_nextgen_public_image_url($normalized_source_url);
                        $candidate = $this->build_lcp_candidate_from_values($preferred_url, $context + array('attribute' => $attribute));
                        if (null === $candidate || empty($candidate['url'])) {
                            continue;
                        }

                        $width = isset($candidate['width']) ? (int) $candidate['width'] : 0;
                        $height = isset($candidate['height']) ? (int) $candidate['height'] : 0;
                        if (($width <= 0 || $height <= 0) && '' !== $preferred_url) {
                            $dimensions = $this->get_public_image_dimensions($preferred_url);
                            if (!empty($dimensions)) {
                                $width = isset($dimensions['width']) ? (int) $dimensions['width'] : $width;
                                $height = isset($dimensions['height']) ? (int) $dimensions['height'] : $height;
                            }
                        }

                        $candidate['width'] = $width;
                        $candidate['height'] = $height;
                        $candidate['is_sr7'] = true;
                        $candidate['sr7_static_slide'] = true;
                        $candidate['sr7_shared_background'] = true;
                        $candidate['sr7_role'] = ('SR7-MODULE-BG' === $tag_name) ? 'module-bg' : 'static-slide';
                        $candidate['lcp_reason'] = 'sr7-static-slide';
                        $candidate['source_url'] = $normalized_source_url;
                        $candidate['raw_url'] = $raw_url;
                        $candidate['tag'] = $tag_name;
                        $candidate['attribute'] = $attribute;
                        $candidate['tag_offset'] = (int) $context_offset + $relative_tag_offset;
                        $candidate['context_source'] = (string) $context_source;
                        $candidate['score'] += $this->score_sr7_static_lcp_candidate($candidate, $tag_html, $near_context, (int) $context_index, (int) $tag_index, (string) $context_source);

                        $visual_boundary = $this->find_sr7_visual_boundary_offset($context_html);
                        if ($visual_boundary >= 0) {
                            $candidate['boundary_offset'] = (int) $context_offset + (int) $visual_boundary;
                        }

                        $candidates[] = $candidate;
                    }
                }
            }

            return $candidates;
        }

        private function score_sr7_static_lcp_candidate(array $candidate, $tag_html, $near_context, $context_index = 0, $tag_index = 0, $context_source = 'static-context')
        {
            $tag_html = strtolower((string) $tag_html);
            $near_context = strtolower((string) $near_context);
            $context_source = strtolower((string) $context_source);
            $url = strtolower((string) (isset($candidate['url']) ? $candidate['url'] : ''));
            $source_url = strtolower((string) (isset($candidate['source_url']) ? $candidate['source_url'] : ''));
            $meta = $tag_html . ' ' . $near_context . ' ' . $url . ' ' . $source_url;

            $width = isset($candidate['width']) ? (int) $candidate['width'] : 0;
            $height = isset($candidate['height']) ? (int) $candidate['height'] : 0;
            $area = max(0, $width * $height);
            $ratio = ($width > 0 && $height > 0) ? ($width / max(1, $height)) : 0.0;

            $score = 5000 + max(0, 180 - ((int) $context_index * 12)) + max(0, 140 - ((int) $tag_index * 8));
            if (false !== strpos($context_source, 'static-window')) {
                $score += 120;
            }
            if (false !== strpos($meta, 'sr7-staticslide') || false !== strpos($meta, 'staticslide') || false !== strpos($meta, 'static-slide') || false !== strpos($meta, 'static slide')) {
                $score += 900;
            }
            if (false !== strpos($meta, 'module-bg') || false !== strpos($meta, 'sr7-module-bg')) {
                $score += 700;
            }
            if (false !== strpos($meta, 'shared')) {
                $score += 260;
            }
            foreach (array('lcp', 'hero', 'background', 'bg', 'banner') as $term) {
                if (false !== strpos($meta, $term)) {
                    $score += 260;
                }
            }
            if ($width >= 1000 && $height >= 250) {
                $score += 620;
            } elseif ($width >= 800 && $height >= 300) {
                $score += 360;
            }
            if ($area >= 350000) {
                $score += 420;
            } elseif ($area >= 120000) {
                $score += 180;
            }
            if ($ratio >= 1.9) {
                $score += 900;
            } elseif ($ratio >= 1.55) {
                $score += 220;
            } elseif ($ratio > 0 && $ratio < 1.15) {
                $score -= 950;
            }
            if (false !== strpos($source_url, '/revslider/o/') || false !== strpos($url, '/revslider/o/')) {
                $score -= 1200;
            }
            foreach (array('book', 'cover', 'product', 'thumb', 'thumbnail', 'logo', 'icon', 'avatar') as $negative) {
                if (false !== strpos($meta, $negative)) {
                    $score -= 650;
                }
            }
            if (false !== strpos($tag_html, 'loading="lazy"') || false !== strpos($tag_html, "loading='lazy'")) {
                $score -= 360;
            }

            return $score;
        }

        private function extract_sr7_first_slide_slice($html, $max_length = 120000)
        {
            $html = (string) $html;
            $max_length = max(20000, min(180000, (int) $max_length));

            $slides_pos = stripos($html, '"slides"');
            if (false === $slides_pos) {
                $slides_pos = stripos($html, 'slides');
            }
            if (false === $slides_pos) {
                return '';
            }

            $probe = substr($html, $slides_pos, min($max_length, 120000));
            $first_pos = strpos($probe, '"1"');
            if (false === $first_pos) {
                $first_pos = strpos($probe, "'1'");
            }

            $start = false !== $first_pos ? $slides_pos + (int) $first_pos : $slides_pos;
            return substr($html, $start, $max_length);
        }

        private function sr7_context_has_image_marker($context)
        {
            $context = (string) $context;
            if ('' === $context) {
                return false;
            }

            if (preg_match('~"subtype"\s*:\s*"image"~i', $context)) {
                return true;
            }

            if (preg_match('~"bg"\s*:\s*\{~i', $context) && preg_match('~"image"\s*:\s*\{~i', $context)) {
                return true;
            }

            return false;
        }

        private function extract_sr7_dimensions_from_context($context)
        {
            $context = (string) $context;
            $width = 0;
            $height = 0;

            if (preg_match('~"size"\s*:\s*\{.{0,900}?"w"\s*:\s*\[\s*"?(\d+)px"?.{0,500}?"h"\s*:\s*\[\s*"?(\d+)px"?~s', $context, $dim)) {
                $width = (int) $dim[1];
                $height = (int) $dim[2];
            } elseif (preg_match('~"w"\s*:\s*\[\s*"?(\d+)px"?.{0,500}?"h"\s*:\s*\[\s*"?(\d+)px"?~s', $context, $dim)) {
                $width = (int) $dim[1];
                $height = (int) $dim[2];
            } else {
                if (preg_match('~"width"\s*:\s*\[\s*([0-9]+)~s', $context, $wm)) {
                    $width = (int) $wm[1];
                }
                if (preg_match('~"height"\s*:\s*\[\s*([0-9]+)~s', $context, $hm)) {
                    $height = (int) $hm[1];
                }
            }

            if ($width <= 0 || $height <= 0 || $width > 5000 || $height > 5000) {
                return array(0, 0);
            }

            return array($width, $height);
        }

        private function extract_sr7_first_slide_layer_image_candidates($html)
        {
            $html = str_replace('\/', '/', (string) $html);
            if ('' === $html || false === stripos($html, 'sr7')) {
                return array();
            }

            $slice = $this->extract_sr7_first_slide_slice($html, 120000);
            if ('' === $slice) {
                return array();
            }

            if (!preg_match_all('~"src"\s*:\s*"([^"]+\.(?:avif|webp|png|jpe?g|gif)(?:\?[^"]*)?)"~i', $slice, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                return array();
            }

            $candidates = array();
            $processed = 0;
            foreach ($matches as $match) {
                $processed++;
                if ($processed > 120) {
                    break;
                }

                $url = isset($match[1][0]) ? (string) $match[1][0] : '';
                $offset = isset($match[1][1]) ? (int) $match[1][1] : (isset($match[0][1]) ? (int) $match[0][1] : 0);
                $context = substr($slice, max(0, $offset - 2600), 6200);
                if (!$this->sr7_context_has_image_marker($context)) {
                    continue;
                }

                $url = html_entity_decode(trim($url), ENT_QUOTES, 'UTF-8');
                if (0 === strpos($url, '/')) {
                    $url = $this->absolutize_public_resource_url($url);
                }
                $url = $this->normalize_public_resource_url($url);
                if ('' === $url || !$this->is_lcp_candidate_image_url($url)) {
                    continue;
                }

                list($width, $height) = $this->extract_sr7_dimensions_from_context($context);

                $id = '';
                if (preg_match('~"id"\s*:\s*"?([^",}\]]+)"?~', $context, $id_match)) {
                    $id = (string) $id_match[1];
                }

                $candidates[$url] = array(
                    'url' => $url,
                    'width' => $width,
                    'height' => $height,
                    'layer' => $id,
                    'offset' => $offset,
                    'context' => strtolower($context),
                );
            }

            return array_values($candidates);
        }

        private function extract_sr7_module_background_image_candidates($html)
        {
            $html = str_replace('\/', '/', (string) $html);
            if ('' === $html || false === stripos($html, 'sr7')) {
                return array();
            }

            $search_html = strlen($html) > 500000 ? substr($html, 0, 500000) : $html;
            if (!preg_match_all('~"src"\s*:\s*"([^"]+\.(?:avif|webp|png|jpe?g|gif|svg)(?:\?[^"]*)?)"~i', $search_html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                return array();
            }

            $candidates = array();
            $processed = 0;
            foreach ($matches as $match) {
                $processed++;
                if ($processed > 160) {
                    break;
                }

                $url = isset($match[1][0]) ? html_entity_decode(trim((string) $match[1][0]), ENT_QUOTES, 'UTF-8') : '';
                $offset = isset($match[1][1]) ? (int) $match[1][1] : (isset($match[0][1]) ? (int) $match[0][1] : 0);
                if ('' === $url) {
                    continue;
                }

                $context = substr($search_html, max(0, $offset - 4500), 11000);
                if (!preg_match('~"bg"\s*:\s*\{~i', $context) || !preg_match('~"image"\s*:\s*\{~i', $context)) {
                    continue;
                }

                if (0 === strpos($url, '/')) {
                    $url = $this->absolutize_public_resource_url($url);
                }
                $url = $this->normalize_public_resource_url($url);
                if ('' === $url || !$this->is_lcp_candidate_image_url($url)) {
                    continue;
                }

                list($width, $height) = $this->extract_sr7_dimensions_from_context($context);

                $key = $this->normalize_public_resource_url($url);
                $candidates[$key] = array(
                    'url' => $url,
                    'width' => $width,
                    'height' => $height,
                    'layer' => 'module-bg',
                    'offset' => $offset,
                    'context' => strtolower($context),
                    'sr7_module_background' => true,
                );
            }

            return array_values($candidates);
        }

        private function is_generated_image_fresh_for_source($generated_path, $source_url)
        {
            $generated_path = (string) $generated_path;
            if ('' === $generated_path || !is_readable($generated_path)) {
                return false;
            }

            $source_path = $this->resolve_local_path_from_public_url((string) $source_url);
            if ('' === $source_path || !is_readable($source_path)) {
                return true;
            }

            $generated_mtime = @filemtime($generated_path);
            $source_mtime = @filemtime($source_path);
            if (!$generated_mtime || !$source_mtime) {
                return true;
            }

            return (int) $generated_mtime >= (int) $source_mtime;
        }

        private function prefer_existing_nextgen_public_image_url($url)
        {
            $url = $this->normalize_public_resource_url($url);
            if ('' === $url) {
                return '';
            }

            if ($this->is_sr7_generated_image_list_url($url)) {
                return $this->prefer_existing_nextgen_revslider_url($url);
            }

            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if ('' === $path || false === strpos($path, '/wp-content/uploads/')) {
                return $url;
            }

            $relative = ltrim((string) substr($path, strpos($path, '/wp-content/uploads/') + strlen('/wp-content/uploads/')), '/');
            if ('' === $relative || !preg_match('/\.(png|jpe?g|webp|avif)$/i', $relative)) {
                return $url;
            }

            $relative_no_ext = preg_replace('/\.(png|jpe?g|webp|avif)$/i', '', $relative);
            if (!is_string($relative_no_ext) || '' === $relative_no_ext) {
                return $url;
            }

            if (defined('UCWP_AVIF_DIR') && defined('UCWP_AVIF_URL')) {
                $avif_path = trailingslashit(UCWP_AVIF_DIR) . $relative_no_ext . '.avif';
                if ($this->is_generated_image_fresh_for_source($avif_path, $url)) {
                    return $this->normalize_public_resource_url(trailingslashit(UCWP_AVIF_URL) . $relative_no_ext . '.avif');
                }
            }

            if (defined('UCWP_WEBP_DIR') && defined('UCWP_WEBP_URL')) {
                $webp_path = trailingslashit(UCWP_WEBP_DIR) . $relative_no_ext . '.webp';
                if ($this->is_generated_image_fresh_for_source($webp_path, $url)) {
                    return $this->normalize_public_resource_url(trailingslashit(UCWP_WEBP_URL) . $relative_no_ext . '.webp');
                }
            }

            return $url;
        }

        private function find_sr7_first_slide_lcp_candidates($html, $limit = 3)
        {
            $html = (string) $html;
            $limit = max(1, min(5, (int) $limit));
            if ('' === $html || (false === stripos($html, 'sr7') && false === stripos($html, '/revslider/o/'))) {
                return array();
            }

            $module_bg_candidates = $this->extract_sr7_module_background_image_candidates($html);
            $layer_candidates = $this->extract_sr7_first_slide_layer_image_candidates($html);
            $generated_urls = array();
            if (empty($module_bg_candidates) && empty($layer_candidates)) {
                return array();
            }

            $target_dimensions = $this->extract_sr7_first_slide_layer_dimensions($html);
            $candidates = array();

            foreach ($module_bg_candidates as $index => $layer) {
                $raw_url = isset($layer['url']) ? (string) $layer['url'] : '';
                $preferred_url = $this->prefer_existing_nextgen_public_image_url($raw_url);
                $candidate = $this->build_lcp_candidate_from_values($preferred_url, array(
                    'tag' => 'SR7-MODULE-BG',
                    'attribute' => 'sr7-json-bg',
                    'class' => 'sr7 module background hero slider',
                    'id' => 'module-bg',
                    'style' => 'sr7 module background image ' . (isset($layer['context']) ? (string) $layer['context'] : ''),
                    'width' => isset($layer['width']) ? (string) (int) $layer['width'] : '',
                    'height' => isset($layer['height']) ? (string) (int) $layer['height'] : '',
                ));
                if (null === $candidate) {
                    continue;
                }
                $candidate = $this->hydrate_lcp_candidate_dimensions($candidate, $raw_url);
                $area = isset($candidate['area']) ? (int) $candidate['area'] : 0;
                $candidate['is_sr7'] = true;
                $candidate['sr7_verified_first_slide'] = true;
                $candidate['sr7_module_background'] = true;
                $candidate['sr7_role'] = 'module-background';
                $candidate['lcp_reason'] = 'sr7-largest-eligible';
                $candidate['source_url'] = $this->normalize_public_resource_url($raw_url);
                $candidate['raw_url'] = $preferred_url;
                $candidate['score'] += 850 + max(0, 120 - ((int) $index * 4)) + min(900, (int) floor($area / 1000));
                $boundary_offset = $this->find_sr7_visual_boundary_offset($html);
                if ($boundary_offset > 0) {
                    $candidate['boundary_offset'] = $boundary_offset;
                }
                $candidates[] = $candidate;
            }

            foreach ($layer_candidates as $index => $layer) {
                $raw_url = isset($layer['url']) ? (string) $layer['url'] : '';
                $preferred_url = $this->prefer_existing_nextgen_public_image_url($raw_url);
                $dimensions = array(
                    'width' => isset($layer['width']) ? (int) $layer['width'] : 0,
                    'height' => isset($layer['height']) ? (int) $layer['height'] : 0,
                );
                if ($dimensions['width'] <= 0 || $dimensions['height'] <= 0) {
                    $dimensions = $this->get_public_image_dimensions($preferred_url);
                }
                if (empty($dimensions)) {
                    $dimensions = $this->get_public_image_dimensions($raw_url);
                }

                $width = isset($dimensions['width']) ? (int) $dimensions['width'] : 0;
                $height = isset($dimensions['height']) ? (int) $dimensions['height'] : 0;
                if ($width <= 0 || $height <= 0) {
                    $width = isset($layer['width']) ? (int) $layer['width'] : 0;
                    $height = isset($layer['height']) ? (int) $layer['height'] : 0;
                }

                $candidate = $this->build_lcp_candidate_from_values($preferred_url, array(
                    'tag' => 'SR7-IMG',
                    'attribute' => 'sr7-json',
                    'class' => 'sr7 first-slide revslider json layer',
                    'id' => isset($layer['layer']) ? (string) $layer['layer'] : '',
                    'style' => 'sr7 first slide layer image ' . (isset($layer['context']) ? (string) $layer['context'] : ''),
                    'width' => $width > 0 ? (string) $width : '',
                    'height' => $height > 0 ? (string) $height : '',
                ));
                if (null === $candidate) {
                    continue;
                }

                $area = max(0, $width * $height);
                $candidate['is_sr7'] = true;
                $candidate['sr7_verified_first_slide'] = true;
                $candidate['sr7_json_layer'] = true;
                $candidate['source_url'] = $this->normalize_public_resource_url($raw_url);
                $candidate['raw_url'] = $preferred_url;
                $candidate = $this->hydrate_lcp_candidate_dimensions($candidate, $raw_url);
                $area = isset($candidate['area']) ? (int) $candidate['area'] : max(0, $width * $height);
                $candidate['sr7_role'] = 'slide-layer';
                $candidate['lcp_reason'] = 'sr7-largest-eligible';
                $candidate['score'] += 780 + max(0, 150 - ((int) $index * 6)) + min(900, (int) floor($area / 1000));
                if ($this->matches_sr7_dimension_target($width, $height, $target_dimensions)) {
                    $candidate['score'] += 250;
                }
                if ($width >= 800 || $height >= 520) {
                    $candidate['score'] += 260;
                }

                $boundary_offset = $this->find_sr7_visual_boundary_offset($html);
                if ($boundary_offset > 0) {
                    $candidate['boundary_offset'] = $boundary_offset;
                }

                $candidates[] = $candidate;
            }

            foreach ($generated_urls as $index => $url) {
                $preferred_url = $this->prefer_existing_nextgen_revslider_url($url);
                $dimensions = $this->get_public_image_dimensions($preferred_url);
                if (empty($dimensions)) {
                    $dimensions = $this->get_public_image_dimensions($url);
                }

                $width = isset($dimensions['width']) ? (int) $dimensions['width'] : 0;
                $height = isset($dimensions['height']) ? (int) $dimensions['height'] : 0;
                if ($width <= 0 || $height <= 0) {
                    continue;
                }

                $matched_target = $this->matches_sr7_dimension_target($width, $height, $target_dimensions);

                $candidate = $this->build_lcp_candidate_from_values($preferred_url, array(
                    'tag' => 'SR7-IMG',
                    'attribute' => 'script',
                    'class' => 'sr7 first-slide revslider optimized',
                    'style' => 'sr7 first slide generated optimized image',
                    'width' => (string) $width,
                    'height' => (string) $height,
                ));
                if (null === $candidate) {
                    continue;
                }

                $area = $width * $height;
                $candidate['is_sr7'] = true;
                $candidate['sr7_verified_first_slide'] = true;
                $candidate['sr7_generated'] = true;
                $candidate['source_url'] = $this->normalize_public_resource_url($url);
                $candidate['raw_url'] = $preferred_url;
                $candidate['score'] += 700 + max(0, 120 - ((int) $index * 4)) + min(500, (int) floor($area / 1000));
                if ($matched_target) {
                    $candidate['score'] += 300;
                }
                if ($width >= 800 || $height >= 520) {
                    $candidate['score'] += 160;
                }

                $boundary_offset = $this->find_sr7_visual_boundary_offset($html);
                if ($boundary_offset > 0) {
                    $candidate['boundary_offset'] = $boundary_offset;
                }

                $candidates[] = $candidate;
            }

            if (empty($candidates)) {
                return array();
            }

            $candidates = $this->sort_lcp_candidates_by_area_then_score($candidates);

            $unique = array();
            $seen = array();
            foreach ($candidates as $candidate) {
                $key = $this->normalize_public_resource_url(isset($candidate['url']) ? $candidate['url'] : '');
                if ('' === $key || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $unique[] = $candidate;
                if (count($unique) >= $limit) {
                    break;
                }
            }

            return $unique;
        }

        private function extract_sr7_first_slide_layer_dimensions($html)
        {
            $html = str_replace('\/', '/', (string) $html);
            $slice = $this->extract_sr7_first_slide_slice($html, 80000);
            if ('' === $slice) {
                return array();
            }

            if (!preg_match_all('~"subtype"\s*:\s*"image"~i', $slice, $matches, PREG_OFFSET_CAPTURE)) {
                return array();
            }

            $targets = array();
            $processed = 0;
            foreach ($matches[0] as $match) {
                $processed++;
                if ($processed > 120) {
                    break;
                }

                $offset = isset($match[1]) ? (int) $match[1] : 0;
                $context = substr($slice, $offset, 4200);
                list($width, $height) = $this->extract_sr7_dimensions_from_context($context);
                if ($width > 0 && $height > 0) {
                    $targets[$width . 'x' . $height] = array('width' => $width, 'height' => $height);
                }
            }

            return array_values($targets);
        }

        private function matches_sr7_dimension_target($width, $height, array $targets)
        {
            $width = (int) $width;
            $height = (int) $height;
            if ($width <= 0 || $height <= 0 || empty($targets)) {
                return false;
            }

            foreach ($targets as $target) {
                $target_width = isset($target['width']) ? (int) $target['width'] : 0;
                $target_height = isset($target['height']) ? (int) $target['height'] : 0;
                if ($target_width <= 0 || $target_height <= 0) {
                    continue;
                }
                if (abs($width - $target_width) <= 3 && abs($height - $target_height) <= 3) {
                    return true;
                }
            }

            return false;
        }

        private function extract_sr7_generated_image_urls_from_html($html)
        {
            $html = str_replace('\/', '/', (string) $html);
            $urls = array();

            // Deliberately stop at whitespace, quotes, or tag delimiters. These are generated SR7 hash assets, not hardcoded IDs.
            $patterns = array(
                "~https?://[^\s\"'<>\)\(]+/wp-content/(?:uploads|cache/ultracache-(?:avif|webp))/revslider/o/[^\s\"'<>\)\(]+~i",
                "~/wp-content/(?:uploads|cache/ultracache-(?:avif|webp))/revslider/o/[^\s\"'<>\)\(]+~i",
            );

            foreach ($patterns as $pattern) {
                if (!preg_match_all($pattern, $html, $matches)) {
                    continue;
                }
                foreach ($matches[0] as $raw_url) {
                    $url = trim((string) $raw_url);
                    $url = trim($url, " \t\n\r\0\x0B\"'()[]{}.,;");
                    if (0 === strpos($url, '/')) {
                        $url = $this->absolutize_public_resource_url($url);
                    }
                    if ('' !== $url && $this->is_lcp_candidate_image_url($url) && $this->is_sr7_generated_image_list_url($url)) {
                        $normalized = $this->normalize_public_resource_url($url);
                        $urls[$normalized] = $normalized;
                    }
                }
            }

            return array_values($urls);
        }

        private function prefer_existing_nextgen_revslider_url($url)
        {
            $url = $this->normalize_public_resource_url($url);
            if ('' === $url) {
                return '';
            }

            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if ('' === $path || !preg_match('#/wp-content/(?:uploads|cache/ultracache-(?:avif|webp))/revslider/o/(.+?)\.(avif|webp|png|jpe?g)(?:$|\?)#i', $path, $match)) {
                return $url;
            }

            $relative_no_ext = isset($match[1]) ? ltrim((string) $match[1], '/') : '';
            if ('' === $relative_no_ext) {
                return $url;
            }

            if (defined('UCWP_AVIF_DIR') && defined('UCWP_AVIF_URL')) {
                $avif_path = trailingslashit(UCWP_AVIF_DIR) . 'revslider/o/' . $relative_no_ext . '.avif';
                if ($this->is_generated_image_fresh_for_source($avif_path, $url)) {
                    return $this->normalize_public_resource_url(trailingslashit(UCWP_AVIF_URL) . 'revslider/o/' . $relative_no_ext . '.avif');
                }
            }

            if (defined('UCWP_WEBP_DIR') && defined('UCWP_WEBP_URL')) {
                $webp_path = trailingslashit(UCWP_WEBP_DIR) . 'revslider/o/' . $relative_no_ext . '.webp';
                if ($this->is_generated_image_fresh_for_source($webp_path, $url)) {
                    return $this->normalize_public_resource_url(trailingslashit(UCWP_WEBP_URL) . 'revslider/o/' . $relative_no_ext . '.webp');
                }
            }

            return $url;
        }

        private function get_public_image_dimensions($url)
        {
            $path = $this->resolve_local_path_from_public_url($url);
            if ('' === $path || !is_readable($path)) {
                return array();
            }

            $size = @getimagesize($path);
            if (!is_array($size) || empty($size[0]) || empty($size[1])) {
                return array();
            }

            return array('width' => (int) $size[0], 'height' => (int) $size[1]);
        }

        private function find_sr7_visual_boundary_offset($html)
        {
            $html = (string) $html;
            foreach (array('</sr7-module>', '</rs-module>', '</sr7-slide>') as $needle) {
                $pos = stripos($html, $needle);
                if (false !== $pos) {
                    return (int) $pos + strlen($needle);
                }
            }
            foreach (array('<sr7-module', '<rs-module', '<sr7-slide') as $needle) {
                $pos = stripos($html, $needle);
                if (false !== $pos) {
                    return (int) $pos;
                }
            }
            return -1;
        }

        private function find_best_sr7_lcp_candidate($html)
        {
            $html = (string) $html;
            if ('' === $html) {
                return null;
            }

            if (false === stripos($html, 'sr7') && false === stripos($html, '/wp-content/uploads/revslider/')) {
                return null;
            }

            $candidates = array();

            foreach ($this->find_sr7_first_slide_lcp_candidates($html, 3) as $first_slide_candidate) {
                $candidates[] = $first_slide_candidate;
            }

            foreach ($this->find_sr7_static_slide_lcp_candidates($html, 1) as $static_slide_candidate) {
                $candidates[] = $static_slide_candidate;
            }

            if (preg_match_all('/<sr7-img\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $tag_html = (string) $match[0];
                    $offset = isset($match[1]) ? (int) $match[1] : 0;
                    $context = array(
                        'tag'        => 'SR7-IMG',
                        'class'      => $this->extract_attribute_from_html_tag($tag_html, 'class'),
                        'id'         => $this->extract_attribute_from_html_tag($tag_html, 'id'),
                        'title'      => $this->extract_attribute_from_html_tag($tag_html, 'title'),
                        'alt'        => $this->extract_attribute_from_html_tag($tag_html, 'alt'),
                        'aria-label' => $this->extract_attribute_from_html_tag($tag_html, 'aria-label'),
                        'width'      => $this->extract_attribute_from_html_tag($tag_html, 'width'),
                        'height'     => $this->extract_attribute_from_html_tag($tag_html, 'height'),
                        'loading'    => $this->extract_attribute_from_html_tag($tag_html, 'loading'),
                        'style'      => $this->extract_attribute_from_html_tag($tag_html, 'style'),
                    );

                    foreach (array('src', 'data-src', 'data-lazy-src', 'data-lazyload', 'data-image', 'data-origin', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image', 'poster') as $attribute) {
                        $value = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                        if ('' === $value) {
                            continue;
                        }
                        $candidate = $this->build_lcp_candidate_from_values($value, $context + array('attribute' => $attribute));
                        if (null !== $candidate) {
                            $candidate['is_sr7'] = true;
                            $candidate['score'] += max(0, 120 - min(100, (int) floor($offset / 5000)));
                            $candidates[] = $candidate;
                        }
                    }

                    foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
                        foreach ($this->extract_candidate_urls_from_srcset($this->extract_attribute_from_html_tag($tag_html, $attribute)) as $srcset_url) {
                            $candidate = $this->build_lcp_candidate_from_values($srcset_url, $context + array('attribute' => $attribute));
                            if (null !== $candidate) {
                                $candidate['is_sr7'] = true;
                                $candidate['score'] += max(0, 120 - min(100, (int) floor($offset / 5000)));
                                $candidates[] = $candidate;
                            }
                        }
                    }

                    foreach ($this->extract_candidate_urls_from_style($context['style']) as $style_url) {
                        $candidate = $this->build_lcp_candidate_from_values($style_url, $context + array('attribute' => 'style'));
                        if (null !== $candidate) {
                            $candidate['is_sr7'] = true;
                            $candidate['score'] += max(0, 120 - min(100, (int) floor($offset / 5000)));
                            $candidates[] = $candidate;
                        }
                    }
                }
            }

            if (preg_match_all("#https?://[^\"'\\s<>()]+/wp-content/uploads/revslider/[^\"'\\s<>()]+#i", $html, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $raw_url = (string) $match[0];
                    $offset = isset($match[1]) ? (int) $match[1] : 0;
                    $context_slice = strtolower((string) substr($html, max(0, $offset - 240), 480));
                    $candidate = $this->build_lcp_candidate_from_values($raw_url, array(
                        'tag' => false !== strpos($context_slice, 'sr7') ? 'SR7-IMG' : 'SCRIPT',
                        'attribute' => false !== strpos($context_slice, 'sr7') ? 'data-lazyload' : 'script',
                        'class' => $context_slice,
                        'id' => $context_slice,
                        'style' => $context_slice,
                    ));
                    if (null !== $candidate) {
                        $candidate['is_sr7'] = true;
                        $candidate['score'] += max(0, 160 - min(120, (int) floor($offset / 4000)));
                        if (false !== strpos($context_slice, 'sr7_1') || false !== strpos($context_slice, '-1-')) {
                            $candidate['score'] += 80;
                        }
                        $candidates[] = $candidate;
                    }
                }
            }

            if (empty($candidates)) {
                return null;
            }

            $candidates = $this->sort_lcp_candidates_by_area_then_score($candidates);

            return $candidates[0];
        }

        private function extract_candidate_urls_from_srcset($srcset)
        {
            $srcset = (string) $srcset;
            if ('' === trim($srcset)) {
                return array();
            }

            $urls = array();
            foreach (array_map('trim', explode(',', $srcset)) as $part) {
                if ('' === $part) {
                    continue;
                }
                $segments = preg_split('/\s+/', $part, 2);
                if (!empty($segments[0])) {
                    $urls[] = $segments[0];
                }
            }

            return array_values(array_unique($urls));
        }

        private function extract_candidate_urls_from_style($style)
        {
            $style = (string) $style;
            if ('' === $style) {
                return array();
            }

            $urls = array();
            if (preg_match_all('/url\(([^)]+)\)/i', $style, $matches)) {
                foreach ($matches[1] as $raw) {
                    $raw = trim((string) $raw, " \t\n\r\0\x0B\"'");
                    if ('' !== $raw) {
                        $urls[] = $raw;
                    }
                }
            }

            return array_values(array_unique($urls));
        }

        private function extract_attribute_from_html_tag($html, $attribute)
        {
            $attribute_name = (string) $attribute;
            $processed = $this->get_html_tag_attribute_with_processor($html, $attribute_name);
            if (null !== $processed) {
                return (string) $processed;
            }

            $attribute = preg_quote($attribute_name, '/');
            if (preg_match('/\b' . $attribute . '\s*=\s*("|\')(.*?)\1/i', (string) $html, $matches) && isset($matches[2])) {
                return html_entity_decode((string) $matches[2], ENT_QUOTES, 'UTF-8');
            }

            if (preg_match('/\b' . $attribute . '\s*=\s*([^\s"\'=<>`]+)/i', (string) $html, $matches) && isset($matches[1])) {
                return html_entity_decode((string) $matches[1], ENT_QUOTES, 'UTF-8');
            }

            return '';
        }

        private function boost_lcp_candidate_markup($html, array $candidate)
        {
            $raw_url = isset($candidate['raw_url']) ? (string) $candidate['raw_url'] : '';
            $attribute = isset($candidate['attribute']) ? (string) $candidate['attribute'] : '';
            $tag = isset($candidate['tag']) ? strtoupper((string) $candidate['tag']) : '';
            if ('' === $raw_url || '' === $attribute || '' === $tag) {
                return $html;
            }

            if ('IMG' !== $tag && 'VIDEO' !== $tag && 'SR7-IMG' !== $tag) {
                return $html;
            }

            $processed = $this->boost_lcp_candidate_markup_with_processor($html, $candidate, $tag, $attribute, $raw_url);
            if (is_string($processed)) {
                return $processed;
            }

            $tag_name = ('SR7-IMG' === $tag) ? 'sr7-img' : strtolower($tag);
            $pattern = '~<' . $tag_name . '\b[^>]*\b' . preg_quote($attribute, '~') . '=(["\'])' . preg_quote($raw_url, '~') . '\1[^>]*>~i';
            return (string) preg_replace_callback(
                $pattern,
                function ($matches) use ($tag, $tag_name, $candidate) {
                    $replacement = $matches[0];
                    if (false === stripos($replacement, 'fetchpriority=')) {
                        $replacement = preg_replace('~<' . preg_quote($tag_name, '~') . '\b~i', '<' . $tag_name . ' fetchpriority="high"', $replacement, 1);
                    }

                    $replacement = $this->set_lcp_marker_on_start_tag($replacement, ('SR7-IMG' === $tag || !empty($candidate['is_sr7'])));

                    if (!empty($candidate['lcp_reason'])) {
                        $replacement = $this->set_or_add_html_tag_attribute($replacement, 'data-ucwp-lcp-reason', (string) $candidate['lcp_reason']);
                    }
                    if (isset($candidate['score'])) {
                        $replacement = $this->set_or_add_html_tag_attribute($replacement, 'data-ucwp-lcp-score', (string) (int) $candidate['score']);
                    }

                    if (!empty($candidate['is_sr7'])) {
                        if (!empty($candidate['sr7_role'])) {
                            $replacement = $this->set_or_add_html_tag_attribute($replacement, 'data-ucwp-sr7-role', (string) $candidate['sr7_role']);
                        }
                    }

                    if ('IMG' === $tag || 'SR7-IMG' === $tag) {
                        if (preg_match('~\sloading=(["\'])lazy\1~i', $replacement)) {
                            $replacement = preg_replace('~\sloading=(["\'])lazy\1~i', ' loading="eager"', $replacement, 1);
                        } elseif (false === stripos($replacement, ' loading=')) {
                            $replacement = preg_replace('~<' . preg_quote($tag_name, '~') . '\b~i', '<' . $tag_name . ' loading="eager"', $replacement, 1);
                        }
                    }

                    return $replacement;
                },
                $html,
                1
            );
        }

        private function boost_lcp_candidate_markup_with_processor($html, array $candidate, $tag, $attribute, $raw_url)
        {
            if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html) {
                return null;
            }

            $tag = strtoupper((string) $tag);
            $tag_name = ('SR7-IMG' === $tag) ? 'SR7-IMG' : $tag;
            $attribute = strtolower((string) $attribute);
            $normalized_raw_url = $this->normalize_public_resource_url($raw_url);

            try {
                $processor = new WP_HTML_Tag_Processor($html);
                $changed = false;
                while ($processor->next_tag($tag_name)) {
                    $value = $processor->get_attribute($attribute);
                    if (!$this->lcp_candidate_attribute_value_matches($value, $attribute, $normalized_raw_url, $raw_url)) {
                        continue;
                    }

                    if ('high' !== (string) $processor->get_attribute('fetchpriority')) {
                        $processor->set_attribute('fetchpriority', 'high');
                        $changed = true;
                    }

                    $marker_attribute = ('SR7-IMG' === $tag || !empty($candidate['is_sr7'])) ? 'data-ucwp-sr7-lcp' : 'data-ucwp-lcp';
                    if (null === $processor->get_attribute($marker_attribute)) {
                        $processor->set_attribute($marker_attribute, '1');
                        $changed = true;
                    }

                    if (!empty($candidate['lcp_reason']) && (string) $processor->get_attribute('data-ucwp-lcp-reason') !== (string) $candidate['lcp_reason']) {
                        $processor->set_attribute('data-ucwp-lcp-reason', (string) $candidate['lcp_reason']);
                        $changed = true;
                    }
                    if (isset($candidate['score']) && (string) $processor->get_attribute('data-ucwp-lcp-score') !== (string) (int) $candidate['score']) {
                        $processor->set_attribute('data-ucwp-lcp-score', (string) (int) $candidate['score']);
                        $changed = true;
                    }

                    if (!empty($candidate['is_sr7'])) {
                        if (!empty($candidate['sr7_role']) && (string) $processor->get_attribute('data-ucwp-sr7-role') !== (string) $candidate['sr7_role']) {
                            $processor->set_attribute('data-ucwp-sr7-role', (string) $candidate['sr7_role']);
                            $changed = true;
                        }
                    }

                    if ('IMG' === $tag || 'SR7-IMG' === $tag) {
                        $loading = $processor->get_attribute('loading');
                        if (null === $loading || false === $loading || 'lazy' === strtolower((string) $loading)) {
                            $processor->set_attribute('loading', 'eager');
                            $changed = true;
                        }

                        if (null === $processor->get_attribute('decoding')) {
                            $processor->set_attribute('decoding', 'sync');
                            $changed = true;
                        }
                    }

                    break;
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

        private function lcp_candidate_attribute_value_matches($value, $attribute, $normalized_raw_url, $raw_url = '')
        {
            if (!is_string($value) || '' === trim($value)) {
                return false;
            }

            $attribute = strtolower((string) $attribute);
            if (in_array($attribute, array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset'), true)) {
                foreach ($this->extract_candidate_urls_from_srcset($value) as $candidate_url) {
                    if ($this->normalize_public_resource_url($candidate_url) === $normalized_raw_url) {
                        return true;
                    }
                }
                return false;
            }

            if ('style' === $attribute) {
                foreach ($this->extract_candidate_urls_from_style($value) as $candidate_url) {
                    if ($this->normalize_public_resource_url($candidate_url) === $normalized_raw_url) {
                        return true;
                    }
                }
                return false;
            }

            return $this->normalize_public_resource_url($value) === $normalized_raw_url || ('' !== (string) $raw_url && (string) $value === (string) $raw_url);
        }

        private function get_sr7_generated_image_list_key($url)
        {
            $url = $this->normalize_public_resource_url($url);
            if ('' === $url) {
                return '';
            }

            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if ('' === $path) {
                return '';
            }

            if (!preg_match('#/wp-content/(?:uploads|cache/ultracache-(?:avif|webp))/revslider/o/(.+?)\.(?:avif|webp|png|jpe?g|gif)(?:$|\?)#i', $path, $match)) {
                return '';
            }

            $relative_no_ext = isset($match[1]) ? trim((string) $match[1], '/') : '';
            if ('' === $relative_no_ext) {
                return '';
            }

            return strtolower('revslider/o/' . $relative_no_ext);
        }

        private function resolve_sr7_generated_image_list_source_url($generated_url, $html)
        {
            $generated_url = $this->normalize_public_resource_url($generated_url);
            if ('' === $generated_url || !$this->is_sr7_generated_image_list_url($generated_url)) {
                return '';
            }

            $target_key = $this->get_sr7_generated_image_list_key($generated_url);
            if ('' === $target_key || !is_string($html) || '' === $html || false === stripos($html, 'data-dbsrc')) {
                return '';
            }

            $scan_html = (string) $html;
            if (preg_match_all('~<image_lists\b[^>]*>.*?</image_lists>~is', $scan_html, $image_list_matches) && !empty($image_list_matches[0])) {
                $scan_html = implode("\n", $image_list_matches[0]);
            }

            if (!preg_match_all('/<img\b[^>]*>/i', $scan_html, $tag_matches)) {
                return '';
            }

            foreach ($tag_matches[0] as $tag_html) {
                $tag_html = (string) $tag_html;
                $dbsrc = $this->extract_attribute_from_html_tag($tag_html, 'data-dbsrc');
                if ('' === $dbsrc) {
                    continue;
                }

                $tag_urls = array();
                foreach (array('src', 'data-src', 'data-lazy-src', 'data-lazyload', 'data-image', 'data-origin', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image') as $attribute) {
                    $value = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                    if ('' !== $value) {
                        $tag_urls[] = $value;
                    }
                }
                foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
                    foreach ($this->extract_candidate_urls_from_srcset($this->extract_attribute_from_html_tag($tag_html, $attribute)) as $srcset_url) {
                        $tag_urls[] = $srcset_url;
                    }
                }

                $matches_target = false;
                foreach ($tag_urls as $tag_url) {
                    $tag_url = (string) $tag_url;
                    if (0 === strpos($tag_url, '//')) {
                        $tag_url = (is_ssl() ? 'https:' : 'http:') . $tag_url;
                    } elseif (0 === strpos($tag_url, '/')) {
                        $tag_url = $this->absolutize_public_resource_url($tag_url);
                    }

                    if ($this->get_sr7_generated_image_list_key($tag_url) === $target_key) {
                        $matches_target = true;
                        break;
                    }
                }

                if (!$matches_target) {
                    continue;
                }

                $decoded = base64_decode($dbsrc, true);
                if (!is_string($decoded) || '' === trim($decoded)) {
                    continue;
                }

                $decoded = trim($decoded);
                if (0 === strpos($decoded, '//')) {
                    $decoded = (is_ssl() ? 'https:' : 'http:') . $decoded;
                } elseif (0 === strpos($decoded, '/')) {
                    $decoded = $this->absolutize_public_resource_url($decoded);
                }

                $decoded = $this->normalize_public_resource_url($decoded);
                if ('' === $decoded || !$this->is_lcp_candidate_image_url($decoded)) {
                    continue;
                }

                return $decoded;
            }

            return '';
        }

        private function is_sr7_generated_image_list_url($url)
        {
            $url = $this->normalize_public_resource_url($url);
            if ('' === $url) {
                return false;
            }

            return false !== stripos($url, '/wp-content/uploads/revslider/o/')
                || false !== stripos($url, '/wp-content/uploads/uc-images/avif/revslider/o/')
                || false !== stripos($url, '/wp-content/uploads/uc-images/webp/revslider/o/');
        }

        private function is_lcp_candidate_image_url($src)
        {
            $src = $this->normalize_public_resource_url($src);
            if ('' === $src || 0 === strpos($src, 'data:')) {
                return false;
            }

            if (preg_match('/\.(svg|ico)($|\?)/i', $src)) {
                return false;
            }

            return (bool) preg_match('/\.(avif|webp|png|jpe?g|gif|bmp|heic|heif)($|\?)/i', $src);
        }

        private function html_has_equivalent_lcp_image_preload($html, $src)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<link')) {
                return false;
            }

            $normalized_src = strtolower($this->normalize_public_resource_url($src));
            if ('' === $normalized_src) {
                return false;
            }

            if (!preg_match_all('/<link\b[^>]*>/i', $html, $matches)) {
                return false;
            }

            foreach ((array) $matches[0] as $tag) {
                $tag = (string) $tag;
                $rel = strtolower((string) $this->extract_attribute_from_html_tag($tag, 'rel'));
                $as = strtolower((string) $this->extract_attribute_from_html_tag($tag, 'as'));
                if (false === strpos($rel, 'preload') || 'image' !== trim($as)) {
                    continue;
                }

                $href = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag, 'href'), ENT_QUOTES, 'UTF-8');
                if (strtolower($this->normalize_public_resource_url($href)) === $normalized_src) {
                    return true;
                }
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

            $pattern = '~<link\b[^>]*\brel=(["\'])preload\1[^>]*\bas=(["\'])image\2[^>]*\bhref=(["\'])' . preg_quote($src, '~') . '\3[^>]*>~i';
            if (preg_match($pattern, $html, $matches)) {
                $existing = (string) $matches[0];
                $replacement = $existing;

                if (false === stripos($replacement, 'fetchpriority=')) {
                    $replacement = rtrim(substr($replacement, 0, -1)) . ' fetchpriority="high">';
                }

                if ('' !== $mime_type && false === stripos($replacement, ' type=')) {
                    $replacement = rtrim(substr($replacement, 0, -1)) . ' type="' . esc_attr($mime_type) . '">';
                }

                if (false === stripos($replacement, ' data-ucwp-lcp-preload=')) {
                    $replacement = rtrim(substr($replacement, 0, -1)) . ' data-ucwp-lcp-preload="1">';
                }
                if (false === stripos($replacement, ' data-ucwp-lcp-preload-reason=')) {
                    $replacement = rtrim(substr($replacement, 0, -1)) . ' data-ucwp-lcp-preload-reason="lcp-image-priority">';
                }

                if ($is_same_origin) {
                    $replacement = (string) preg_replace('/\s+crossorigin(?:\s*=\s*(?:["\'][^"\']*["\']|[^\s>]+))?/i', '', $replacement);
                } elseif (false === stripos($replacement, 'crossorigin=')) {
                    $replacement = rtrim(substr($replacement, 0, -1)) . ' crossorigin="anonymous">';
                }

                if ($replacement !== $existing) {
                    $html = preg_replace($pattern, addcslashes($replacement, '\\$'), $html, 1);
                }

                return $html;
            }

            if ($this->html_has_equivalent_lcp_image_preload($html, $src)) {
                return $this->cleanup_ambiguous_sr7_generated_lcp_preloads($html);
            }

            $link = '<link rel="preload" as="image" href="' . $src . '"';
            if ('' !== $mime_type) {
                $link .= ' type="' . esc_attr($mime_type) . '"';
            }
            $link .= ' fetchpriority="high" data-ucwp-lcp-preload="1" data-ucwp-lcp-preload-reason="lcp-image-priority"';
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
                    if ('1' !== (string) $processor->get_attribute('data-ucwp-lcp-preload')) {
                        $processor->set_attribute('data-ucwp-lcp-preload', '1');
                        $changed = true;
                    }
                    if (null === $processor->get_attribute('data-ucwp-lcp-preload-reason')) {
                        $processor->set_attribute('data-ucwp-lcp-preload-reason', 'lcp-image-priority');
                        $changed = true;
                    }

                    $existing_crossorigin = $processor->get_attribute('crossorigin');
                    if ($is_same_origin && null !== $existing_crossorigin) {
                        // WP_HTML_Tag_Processor does not exist on all supported WP versions and
                        // remove_attribute availability differs, so fall back to the regex path
                        // where same-origin image preloads can have crossorigin stripped safely.
                        return null;
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

        private function cleanup_ambiguous_sr7_generated_lcp_preloads($html)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<link')) {
                return $html;
            }

            $seen_plugin_image_preloads = array();
            $changed = false;

            $updated = preg_replace_callback('/<link\b[^>]*>/i', function ($matches) use (&$seen_plugin_image_preloads, &$changed) {
                $tag = isset($matches[0]) ? (string) $matches[0] : '';
                if ('' === $tag) {
                    return $tag;
                }

                $rel = strtolower((string) $this->extract_attribute_from_html_tag($tag, 'rel'));
                $as = strtolower((string) $this->extract_attribute_from_html_tag($tag, 'as'));
                $href = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag, 'href'), ENT_QUOTES, 'UTF-8');
                if (false === strpos($rel, 'preload') || 'image' !== trim($as) || '' === trim($href)) {
                    return $tag;
                }

                $is_ucwp_preload = false !== stripos($tag, 'data-ucwp-lcp-preload')
                    || false !== stripos($tag, 'data-ucwp-critical-chain');
                if (!$is_ucwp_preload) {
                    return $tag;
                }

                $normalized = $this->normalize_public_resource_url($href);
                if ('' === $normalized) {
                    return $tag;
                }

                // 2.56.192: remove UltraCache-managed preloads for SR7 generated
                // /revslider/o/ helper assets. They are safe to rewrite/use when SR7 asks
                // for them, but they are not reliable first-paint preload targets.
                if ($this->is_sr7_generated_image_list_url($normalized)) {
                    $changed = true;
                    return '';
                }

                $key = strtolower($normalized);
                if (isset($seen_plugin_image_preloads[$key])) {
                    $changed = true;
                    return '';
                }

                $seen_plugin_image_preloads[$key] = true;
                return $tag;
            }, $html);

            if (!is_string($updated) || '' === $updated) {
                return $html;
            }

            return $changed ? $updated : $html;
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
            if (preg_match('/\.(?:avif|webp)$/i', $path)) {
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

            $uploads_marker = '/wp-content/uploads/';
            $avif_marker = '/wp-content/uploads/uc-images/avif/';
            $webp_marker = '/wp-content/uploads/uc-images/webp/';
            $legacy_avif_marker = '/wp-content/cache/ultracache-avif/';
            $legacy_webp_marker = '/wp-content/cache/ultracache-webp/';
            $original_relative = false !== strpos($original_base, $uploads_marker) ? substr($original_base, strpos($original_base, $uploads_marker) + strlen($uploads_marker)) : '';
            $preferred_relative = '';
            if (false !== strpos($preferred_base, $avif_marker)) {
                $preferred_relative = substr($preferred_base, strpos($preferred_base, $avif_marker) + strlen($avif_marker));
            } elseif (false !== strpos($preferred_base, $webp_marker)) {
                $preferred_relative = substr($preferred_base, strpos($preferred_base, $webp_marker) + strlen($webp_marker));
            } elseif (false !== strpos($preferred_base, $legacy_avif_marker)) {
                $preferred_relative = substr($preferred_base, strpos($preferred_base, $legacy_avif_marker) + strlen($legacy_avif_marker));
            } elseif (false !== strpos($preferred_base, $legacy_webp_marker)) {
                $preferred_relative = substr($preferred_base, strpos($preferred_base, $legacy_webp_marker) + strlen($legacy_webp_marker));
            }

            if ('' === $original_relative || '' === $preferred_relative || $original_relative !== $preferred_relative) {
                return $url;
            }

            return $preferred;
        }

    }
}
