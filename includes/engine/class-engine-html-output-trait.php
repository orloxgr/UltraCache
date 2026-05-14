<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_HTML_Output_Trait
{
        private function get_public_resource_origin_type($url)
        {
            $url = (string) $url;
            $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
            $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
            if ('' !== $host && '' !== $home_host && $host !== $home_host) {
                return 'external';
            }
            if (false !== strpos($path, '/wp-includes/') || false !== strpos($path, '/wp-admin/')) {
                return 'core';
            }
            if (false !== strpos($path, '/wp-content/plugins/')) {
                return 'plugin';
            }
            if (false !== strpos($path, '/wp-content/themes/')) {
                return 'theme';
            }
            if (false !== strpos($path, '/wp-content/uploads/')) {
                return 'uploads';
            }
            if (false !== strpos($path, '/wp-content/cache/ultracache/')) {
                return 'ultracache-cache';
            }
            return 'local';
        }

        private function get_public_resource_path_fragment($url)
        {
            $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
            if ('' === $path) {
                $path = (string) $url;
            }
            $path = rawurldecode($path);
            $path = preg_replace('/[\r\n\t]+/', '', $path);
            return trim((string) $path);
        }

        private function html_tag_has_attribute($tag, $attribute)
        {
            $tag = (string) $tag;
            $attribute = preg_quote((string) $attribute, '/');
            return (bool) preg_match('/\s' . $attribute . '(?:\s*=|\s|>|\/)/i', $tag);
        }

        private function get_html_offset_location($offset, $head_end)
        {
            $offset = (int) $offset;
            $head_end = is_int($head_end) ? $head_end : -1;
            return ($head_end < 0 || $offset <= $head_end) ? 'head' : 'body';
        }

        private function get_html_tag_ranges_by_name($html, $tag_name)
        {
            $html = is_string($html) ? $html : (string) $html;
            $tag_name = preg_replace('/[^a-z0-9_-]/i', '', (string) $tag_name);
            if ('' === $html || '' === $tag_name) {
                return array();
            }

            $ranges = array();
            if (preg_match_all("/<" . preg_quote($tag_name, "/") . "\\b[^>]*>[\\s\\S]*?<\\/" . preg_quote($tag_name, "/") . ">/i", $html, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ((array) $matches[0] as $match) {
                    $start = isset($match[1]) ? (int) $match[1] : -1;
                    $text = isset($match[0]) ? (string) $match[0] : '';
                    if ($start >= 0 && '' !== $text) {
                        $ranges[] = array($start, $start + strlen($text));
                    }
                }
            }

            return $ranges;
        }

        private function is_html_offset_inside_ranges($offset, array $ranges)
        {
            $offset = (int) $offset;
            foreach ($ranges as $range) {
                if (!is_array($range) || count($range) < 2) {
                    continue;
                }
                if ($offset >= (int) $range[0] && $offset < (int) $range[1]) {
                    return true;
                }
            }
            return false;
        }

        private function get_matching_fragment($handle, $url, $tag, array $fragments)
        {
            $haystacks = array(
                strtolower(trim((string) $handle)),
                strtolower(trim((string) $url)),
                strtolower((string) wp_parse_url((string) $url, PHP_URL_PATH)),
                strtolower((string) $tag),
            );
            foreach ($fragments as $fragment) {
                $fragment = strtolower(trim((string) $fragment));
                if ('' === $fragment) {
                    continue;
                }
                foreach ($haystacks as $haystack) {
                    if ('' !== $haystack && false !== strpos($haystack, $fragment)) {
                        return $fragment;
                    }
                }
            }
            return '';
        }

        private function apply_html_rewrite_safely($html, $label, callable $callback, $profile = true)
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            if ($profile && $this->is_store_profiler_enabled()) {
                return $this->profile_store_stage($label, $html, function ($html) use ($label, $callback) {
                    return $this->apply_html_rewrite_safely($html, $label, $callback, false);
                });
            }

            $original = $html;
            try {
                $candidate = $callback($html);
            } catch (\Throwable $e) {
                $this->record_html_rewrite_safety_bailout($label, 'exception');
                return $original;
            }

            if (!is_string($candidate)) {
                $this->record_html_rewrite_safety_bailout($label, 'non-string');
                return $original;
            }

            if (!$this->is_safe_html_rewrite_result($original, $candidate)) {
                $this->record_html_rewrite_safety_bailout($label, 'invalid-html-shape');
                return $original;
            }

            return $candidate;
        }

        private function apply_html_array_rewrite_safely($html, $label, callable $callback, $profile = true)
        {
            $result = array(
                'html' => $html,
                'safe' => true,
            );

            if (!is_string($html) || '' === $html) {
                return $result;
            }

            if ($profile && $this->is_store_profiler_enabled()) {
                $before = $html;
                $before_bytes = strlen($before);
                $start = microtime(true);
                $candidate = $this->apply_html_array_rewrite_safely($html, $label, $callback, false);
                $duration_ms = (int) round((microtime(true) - $start) * 1000);
                $candidate_html = isset($candidate['html']) && is_string($candidate['html']) ? $candidate['html'] : $html;
                $after_bytes = strlen($candidate_html);
                $this->store_profile['stages'][] = array_merge(array(
                    'stage' => sanitize_key((string) $label),
                    'bytes_in' => (int) $before_bytes,
                    'bytes_out' => (int) $after_bytes,
                    'delta_bytes' => (int) ($after_bytes - $before_bytes),
                    'duration_ms' => $duration_ms,
                    'safe' => !empty($candidate['safe']) ? 'true' : 'false',
                ), $this->collect_store_profile_html_counts($candidate_html));
                return $candidate;
            }

            try {
                $candidate = $callback($html);
            } catch (\Throwable $e) {
                $this->record_html_rewrite_safety_bailout($label, 'exception');
                $result['safe'] = false;
                return $result;
            }

            if (!is_array($candidate)) {
                $this->record_html_rewrite_safety_bailout($label, 'non-array');
                $result['safe'] = false;
                return $result;
            }

            $candidate_html = isset($candidate['html']) && is_string($candidate['html']) ? $candidate['html'] : $html;
            if (!$this->is_safe_html_rewrite_result($html, $candidate_html)) {
                $this->record_html_rewrite_safety_bailout($label, 'invalid-html-shape');
                $result['safe'] = false;
                return $result;
            }

            $candidate['html'] = $candidate_html;
            $candidate['safe'] = true;
            return $candidate;
        }

        private function is_safe_html_rewrite_result($original, $candidate)
        {
            if (!is_string($original) || !is_string($candidate)) {
                return false;
            }

            if ('' === $candidate) {
                return '' === $original;
            }

            $original_trimmed = trim($original);
            $candidate_trimmed = trim($candidate);
            if ('' !== $original_trimmed && '' === $candidate_trimmed) {
                return false;
            }

            $original_length = strlen($original);
            $candidate_length = strlen($candidate);
            if ($original_length > 1000 && $candidate_length < max(250, (int) floor($original_length * 0.25))) {
                return false;
            }

            foreach (array('</head>', '<body', '</body>', '</html>') as $marker) {
                if (false !== stripos($original, $marker) && false === stripos($candidate, $marker)) {
                    return false;
                }
            }

            if (false !== stripos($original, '<head') && false === stripos($candidate, '<head')) {
                return false;
            }

            $original_lt = substr_count($original, '<');
            $candidate_lt = substr_count($candidate, '<');
            if ($original_lt > 20 && $candidate_lt < (int) floor($original_lt * 0.35)) {
                return false;
            }

            return true;
        }

        private function record_html_rewrite_safety_bailout($label, $reason)
        {
            $label = sanitize_key((string) $label);
            $reason = sanitize_key((string) $reason);
            if ('' === $label) {
                $label = 'unknown';
            }
            if ('' === $reason) {
                $reason = 'unknown';
            }

            $analytics = self::read_analytics();
            if (!is_array($analytics)) {
                $analytics = array();
            }
            $analytics['htmlRewriteSafetyBailouts'] = isset($analytics['htmlRewriteSafetyBailouts']) ? (int) $analytics['htmlRewriteSafetyBailouts'] + 1 : 1;
            $analytics['htmlRewriteLastBailout'] = array(
                'label' => $label,
                'reason' => $reason,
                'time' => current_time('timestamp'),
                'time_mysql' => current_time('mysql'),
            );
            self::write_analytics($analytics);
        }

        private function get_frontend_rewrite_profile($html, array $settings = array())
        {
            $slider_safe_mode = !empty($settings['slider_safe_mode']) && $this->should_use_safe_frontend_optimization_mode($html);
            $safe_mode = $slider_safe_mode;

            $reason = '';
            if ($slider_safe_mode) {
                $reason = 'slider-hero-detected';
            }

            return array(
                'slider_safe_mode' => (bool) $slider_safe_mode,
                'safe_mode' => (bool) $safe_mode,
                'reason' => $reason,
            );
        }

        private function apply_frontend_performance_optimizations($html, array $context = array())
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            $target_url = '';
            if (!empty($context['url'])) {
                $target_url = esc_url_raw((string) $context['url']);
            } elseif (!empty($context['request_url'])) {
                $target_url = esc_url_raw((string) $context['request_url']);
            }

            $html = $this->normalize_protocol_relative_urls_in_html($html);

            if ($this->is_frontpage_css_scan_mode()) {
                return $html;
            }

            $settings = $this->get_settings();
            $html = $this->apply_html_rewrite_safely($html, 'normalize-script-loading-attrs', function ($html) use ($settings) {
                return $this->normalize_protected_script_loading_attributes_in_html($html, $settings);
            });
            $rewrite_profile = $this->get_frontend_rewrite_profile($html, $settings);
            $slider_safe_mode = !empty($rewrite_profile['slider_safe_mode']);
            $safe_mode = !empty($rewrite_profile['safe_mode']);

            if (!empty($settings['critical_request_chain_relief'])) {
                if ($slider_safe_mode) {
                    // Slider Safe Mode protects fragile slider runtime/CSS from delay/rewrite
                    // transforms, but manual priority preloads are safe and still useful for LCP.
                    $html = $this->apply_html_rewrite_safely($html, 'manual-critical-preloads', function ($html) use ($settings) {
                        return $this->inject_manual_critical_preload_links($html, $settings);
                    });
                    $html = $this->apply_html_rewrite_safely($html, 'slider-fetch-preloads', function ($html) use ($settings) {
                        return $this->inject_detected_slider_fetch_preloads($html, $settings);
                    });
                } else {
                    $html = $this->apply_html_rewrite_safely($html, 'critical-request-chain-relief', function ($html) use ($settings) {
                        return $this->apply_critical_request_chain_relief($html, $settings);
                    });
                }
            }

            if (!$slider_safe_mode && !empty($settings['asset_chain_cleanup']) && !$this->current_request_matches_asset_cleanup_exclusion($settings)) {
                $html = $this->apply_html_rewrite_safely($html, 'asset-chain-cleanup', function ($html) use ($settings) {
                    return $this->apply_asset_chain_cleanup_to_html($html, $settings);
                });
            }

            // Slider/Hero Safe Mode becomes active only when protected hero markup is detected in the rendered HTML.
            if (!$safe_mode) {
                $html = $this->apply_html_rewrite_safely($html, 'strip-authoring-assets', function ($html) {
                    return $this->strip_probable_frontend_authoring_assets($html);
                });
            }

            if (!empty($settings['homepage_css_bundle'])) {
                $bundle_mode = isset($settings['homepage_css_bundle_mode']) ? strtolower(trim((string) $settings['homepage_css_bundle_mode'])) : 'safe';
                $bundle_mode = in_array($bundle_mode, array('safe', 'aggressive', 'full'), true) ? $bundle_mode : 'safe';
                $bundle_scope = $this->get_css_bundle_scope($settings);

                // Slider Safe Mode protects fragile hero/runtime assets from destructive rewrites,
                // but safe CSS bundling is intentionally non-destructive: it injects the generated
                // bundle while keeping the original stylesheet links as authoritative fallback.
                // Do not blank $bundle_mode here; otherwise explicit CSS warm can build a bundle
                // that never appears in the cached HTML on SR7/hero pages.


                if ('' !== $bundle_mode && !$this->is_ultracache_internal_loopback_request()) {
                    $is_frontpage_context = '' !== $target_url ? $this->is_frontpage_request_url($target_url) : $this->is_frontpage_request_url();
                    if ('per-page' === $bundle_scope || ('homepage' === $bundle_scope && $is_frontpage_context) || ('shared' === $bundle_scope && $is_frontpage_context)) {
                        if (!empty($settings['page_css_bundle_on_entry'])) {
                            $this->profile_store_event('build_page_css_bundle_on_entry', $html, function ($html) use ($settings) {
                                $this->maybe_build_page_css_bundle_on_entry($html, $settings);
                                return true;
                            });
                        } elseif (!empty($settings['page_css_bundle_async_on_entry'])) {
                            $this->profile_store_event('queue_page_css_bundle_async_on_entry', $html, function ($html) use ($settings) {
                                $this->maybe_enqueue_page_css_bundle_async_on_entry($settings);
                                return true;
                            });
                        }
                    }
                }

                if ('' !== $bundle_mode) {
                    $bundle_entry_url = '' !== $target_url ? $target_url : $this->get_current_request_url();
                    if ('homepage' === $bundle_scope) {
                        $is_frontpage_context = '' !== $target_url ? $this->is_frontpage_request_url($target_url) : $this->is_frontpage_request_url();
                        if ($is_frontpage_context) {
                            $html = $this->apply_html_rewrite_safely($html, 'replace-homepage-css-bundle', function ($html) {
                                return $this->maybe_replace_page_stylesheet_links_with_bundle($html, home_url('/'));
                            });
                        }
                    } elseif ('shared' === $bundle_scope) {
                        $html = $this->apply_html_rewrite_safely($html, 'replace-shared-css-bundle', function ($html) {
                            return $this->maybe_replace_page_stylesheet_links_with_bundle($html, home_url('/'));
                        });
                    } else {
                        $html = $this->apply_html_rewrite_safely($html, 'replace-page-css-bundle', function ($html) use ($bundle_entry_url) {
                            return $this->maybe_replace_page_stylesheet_links_with_bundle($html, $bundle_entry_url);
                        });
                    }
                }
            }

            if (!empty($settings['leftover_css_bundle']) && !empty($settings['homepage_css_bundle']) && isset($bundle_mode) && in_array((string) $bundle_mode, array('safe', 'aggressive', 'full'), true)) {
                // Consolidate Remaining CSS is an independent post-bundle pass. It should
                // follow the user's leftoverCssBundleEnabled setting regardless of the
                // selected main CSS bundle mode; the mode only controls the main bundle.
                $html = $this->profile_store_stage('consolidate-leftover-css-bundle', $html, function ($html) use ($settings) {
                    return $this->maybe_consolidate_leftover_stylesheet_links($html, $settings);
                });
            }



            if (!empty($settings['cls_dimensions'])) {
                $cls_dimensions_result = $this->apply_html_array_rewrite_safely($html, 'cls-dimensions', function ($html) {
                    return $this->inject_safe_cls_dimensions($html);
                });
                if (is_array($cls_dimensions_result)) {
                    if (!empty($cls_dimensions_result['safe'])) {
                        $html = isset($cls_dimensions_result['html']) && is_string($cls_dimensions_result['html']) ? $cls_dimensions_result['html'] : $html;
                        $this->record_analytics_cls_dimensions(isset($cls_dimensions_result['stats']) && is_array($cls_dimensions_result['stats']) ? $cls_dimensions_result['stats'] : array());
                    }
                }
            }

            if (!empty($settings['google_fonts_local_optimization'])) {
                $html = $this->apply_html_rewrite_safely($html, 'google-fonts-local-links', function ($html) {
                    return $this->rewrite_google_fonts_links_to_local_in_html($html);
                });
            } elseif (!empty($settings['google_fonts_swap'])) {
                $html = $this->apply_html_rewrite_safely($html, 'google-fonts-display-swap', function ($html) {
                    return $this->rewrite_google_fonts_display_swap_in_html($html);
                });
            }


            $font_policy = $this->get_font_optimization_policy($settings);

            if (!empty($font_policy['local_font_css_rewrite'])) {
                $html = $this->apply_html_rewrite_safely($html, 'central-inline-font-display-normalize', function ($html) {
                    return $this->normalize_inline_style_font_display_in_html($html);
                });
            }

            if (!empty($font_policy['font_css_links'])) {
                $html = $this->apply_html_rewrite_safely($html, 'self-hosted-font-css-links', function ($html) {
                    return $this->optimize_self_hosted_font_css_links($html);
                });
            }

            if (!empty($font_policy['local_font_css_rewrite'])) {
                $html = $this->apply_html_rewrite_safely($html, 'central-linked-font-display-normalize', function ($html) {
                    return $this->normalize_linked_local_stylesheet_font_display_in_html($html);
                });
            }

            if (!empty($settings['google_fonts_swap'])) {
                $html = $this->apply_html_rewrite_safely($html, 'local-font-display-patches', function ($html) {
                    return $this->apply_local_font_display_patches_to_html($html);
                });
            }

            if ((!empty($settings['self_hosted_font_css_optimization']) || !empty($settings['delay_icon_fonts']) || !empty($settings['google_fonts_swap'])) && false !== stripos((string) $html, '.ttf')) {
                $html = $this->apply_html_rewrite_safely($html, 'final-generic-ttf-font-face-cleanup', function ($html) {
                    return $this->rewrite_inline_font_face_ttf_sources_to_linked_woff2($html);
                });
            }

            if (!empty($font_policy['runtime_rewrite'])) {
                // Runtime font CSS rewrites are intentionally allowed during slider/hero safe mode.
                // The helper only rewrites late stylesheet href attributes via MutationObserver and
                // does not alter slider markup, script ordering, or LCP preload selection.
                $html = $this->apply_html_rewrite_safely($html, 'runtime-font-css-map', function ($html) {
                    return $this->inject_runtime_font_css_url_map($html);
                });


                // Some plugins/themes register @font-face rules dynamically through JS/CSSOM
                // after the server-side HTML/CSS rewrite stage. Keep this generic and tiny:
                // patch only @font-face font-display declarations, without changing URLs,
                // selectors, script order, or layout markup.
                $html = $this->apply_html_rewrite_safely($html, 'runtime-font-display-cssom-patch', function ($html) {
                    return $this->inject_runtime_font_display_cssom_patch($html);
                });
            }

            if (!empty($settings['async_css']) || !empty($settings['aggressive_async_css'])) {
                $html = $this->apply_async_css_links_to_html($html);
            }
            if (!empty($settings['lazy_mailerlite_nonce'])) {
                $html = $this->apply_html_rewrite_safely($html, 'lazy-mailerlite-nonce-refresh', function ($html) {
                    return $this->inject_mailerlite_lazy_nonce_refresh($html);
                });
            }

            if (!empty($settings['delay_safe_third_party_js']) || !empty($settings['delay_functional_third_party_js']) || !empty($settings['delay_all_third_party_js'])) {
                $html = $this->apply_html_rewrite_safely($html, 'delay-third-party-pattern-scripts', function ($html) use ($settings) {
                    return $this->delay_third_party_analytics_scripts_in_html($html, $settings);
                });
            }

            if (!empty($settings['speculation_rules_enabled'])) {
                $html = $this->apply_html_rewrite_safely($html, 'speculation-rules-prefetch', function ($html) use ($settings) {
                    return $this->inject_speculation_rules_prefetch($html, $settings);
                });
            }

            $html = $this->apply_lcp_priority_pipeline($html, $settings, $slider_safe_mode);

            if (!empty($settings['lazy_load_images'])) {
                $html = $this->apply_html_rewrite_safely($html, 'lazy-load-async-images', function ($html) use ($settings) {
                    return $this->apply_lazy_load_images_to_html($html, $settings);
                });
            }

            if ($this->should_apply_lcp_boundary_defer($settings, $slider_safe_mode)) {
                $html = $this->apply_html_rewrite_safely($html, 'lcp-boundary-defer', function ($html) use ($settings) {
                    return $this->apply_lcp_boundary_defer_to_html($html, $settings);
                });
            }

            if (!empty($settings['defer_js']) && !empty($settings['defer_all_js'])) {
                $html = $this->apply_html_rewrite_safely($html, 'defer-all-js-final-pass', function ($html) use ($settings) {
                    return $this->apply_defer_all_js_to_html($html, $settings);
                });
            }

            if (!empty($settings['defer_js']) || !empty($settings['delay_safe_third_party_js']) || !empty($settings['delay_functional_third_party_js']) || !empty($settings['delay_all_third_party_js']) || !empty($settings['delay_non_critical_js'])) {
                $html = $this->apply_html_rewrite_safely($html, 'restore-user-excluded-delayed-js', function ($html) use ($settings) {
                    return $this->restore_user_excluded_delayed_scripts_in_html($html, $settings);
                });
            }

            $html = $this->apply_html_rewrite_safely($html, 'dedupe-ucwp-stylesheet-links', function ($html) {
                return $this->dedupe_ultracache_stylesheet_links_in_html($html);
            });


            return $html;
        }

        private function dedupe_ultracache_stylesheet_links_in_html($html)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<link')) {
                return $html;
            }

            $outer_seen = array();
            $noscript_seen = array();
            $noscript_blocks = array();
            $placeholder_prefix = '%%UCWP_NOSCRIPT_CSS_DEDUPE_' . md5((string) strlen($html) . '|' . (defined('UCWP_VERSION') ? (string) UCWP_VERSION : '')) . '_';

            $without_noscript = preg_replace_callback('/<noscript\b[^>]*>.*?<\/noscript>/is', function ($matches) use (&$noscript_blocks, &$noscript_seen, $placeholder_prefix) {
                $index = count($noscript_blocks);
                $placeholder = $placeholder_prefix . $index . '%%';
                $block = isset($matches[0]) ? (string) $matches[0] : '';
                $noscript_blocks[$placeholder] = $this->dedupe_ultracache_stylesheet_links_in_fragment($block, true, $noscript_seen);
                return $placeholder;
            }, $html);

            if (!is_string($without_noscript) || '' === $without_noscript) {
                return $html;
            }

            $deduped = $this->dedupe_ultracache_stylesheet_links_in_fragment($without_noscript, false, $outer_seen);
            if (!is_string($deduped) || '' === $deduped) {
                return $html;
            }

            if (!empty($noscript_blocks)) {
                $deduped = strtr($deduped, $noscript_blocks);
            }

            return $deduped;
        }

        private function dedupe_ultracache_stylesheet_links_in_fragment($html, $inside_noscript, array &$seen)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<link')) {
                return $html;
            }

            $updated = preg_replace_callback('/<link\b[^>]*>/i', function ($matches) use ($inside_noscript, &$seen) {
                $tag = isset($matches[0]) ? (string) $matches[0] : '';
                if ('' === $tag || !$this->html_tag_rel_contains_stylesheet($tag)) {
                    return $tag;
                }

                $href = $this->extract_attribute_from_html_tag($tag, 'href');
                if ('' === $href || false === stripos($href, '.css')) {
                    return $tag;
                }

                if (!$this->is_ultracache_stylesheet_dedupe_candidate($tag, $href, (bool) $inside_noscript)) {
                    return $tag;
                }

                $key = $this->build_ultracache_stylesheet_dedupe_key($tag, $href, (bool) $inside_noscript);
                if ('' === $key) {
                    return $tag;
                }

                if (isset($seen[$key])) {
                    return '';
                }

                $seen[$key] = true;
                return $tag;
            }, $html);

            return is_string($updated) ? $updated : $html;
        }

        private function is_ultracache_stylesheet_dedupe_candidate($tag, $href, $inside_noscript = false)
        {
            $tag = (string) $tag;
            $href = (string) $href;
            $tag_lc = strtolower($tag);
            $href_lc = strtolower(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));

            if (false !== strpos($tag_lc, 'data-ucwp-')) {
                return true;
            }

            if (false !== strpos($href_lc, '/wp-content/cache/ultracache/')) {
                return true;
            }

            if ($inside_noscript && false !== strpos($tag_lc, 'data-ucwp-async-css-fallback')) {
                return true;
            }

            return false;
        }

        private function build_ultracache_stylesheet_dedupe_key($tag, $href, $inside_noscript = false)
        {
            $tag = (string) $tag;
            $href = trim(html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5));
            if ('' === $href) {
                return '';
            }

            $absolute = $this->absolutize_public_resource_url($href, home_url('/'));
            if (!is_string($absolute) || '' === $absolute) {
                $absolute = $href;
            }

            $absolute = strtolower($absolute);
            $absolute = preg_replace('/#.*$/', '', $absolute);
            $absolute = is_string($absolute) ? $absolute : strtolower($href);

            if ($inside_noscript) {
                return 'noscript|' . $absolute;
            }

            $tag_lc = strtolower($tag);
            $media = strtolower(trim((string) $this->extract_attribute_from_html_tag($tag, 'media')));
            $is_async = false !== strpos($tag_lc, 'data-ucwp-async-css')
                || false !== strpos($tag_lc, 'data-ucwp-delayed-icon-fonts')
                || false !== strpos($tag_lc, 'data-ucwp-css-async-reason')
                || false !== strpos($tag_lc, 'onload=')
                || 'print' === $media;

            if ($is_async) {
                return 'async|' . $absolute;
            }

            return 'blocking|' . $absolute;
        }

        private function inject_mailerlite_lazy_nonce_refresh($html)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, 'ml_create_nonce') || false === stripos($html, 'mailerlite')) {
                return $html;
            }

            if (false !== strpos($html, 'data-ucwp-mailerlite-lazy-nonce="1"')) {
                return $html;
            }

            $script = <<<'JS'
<script data-ucwp-mailerlite-lazy-nonce="1">
(function(){
  if (window.__ucwpMailerLiteLazyNonceV1) { return; }
  window.__ucwpMailerLiteLazyNonceV1 = true;

  var realFetch = window.fetch;
  if (typeof realFetch !== 'function') { return; }

  var ajaxUrl = '';
  var refreshStarted = false;

  function toBodyString(body) {
    try {
      if (!body) { return ''; }
      if (typeof body === 'string') { return body; }
      if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) { return body.toString(); }
      if (typeof FormData !== 'undefined' && body instanceof FormData) {
        var parts = [];
        body.forEach(function(value, key){ parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value))); });
        return parts.join('&');
      }
    } catch (e) {}
    return '';
  }

  function getRequestUrl(input) {
    try {
      if (typeof input === 'string') { return input; }
      if (input && typeof input.url === 'string') { return input.url; }
    } catch (e) {}
    return '';
  }

  function isMailerLiteNonceRequest(input, init) {
    var url = getRequestUrl(input);
    var body = toBodyString(init && init.body ? init.body : '');
    if (url.indexOf('admin-ajax.php') === -1) { return false; }
    return body.indexOf('ml_create_nonce') !== -1 || body.indexOf('action=ml_create_nonce') !== -1 || body.indexOf('action%3Dml_create_nonce') !== -1;
  }

  function getNonceFromBody(body) {
    var str = toBodyString(body);
    var match = str.match(/(?:^|&)ml_nonce=([^&]*)/);
    if (!match) { return ''; }
    try { return decodeURIComponent(match[1].replace(/\+/g, ' ')); } catch (e) { return match[1]; }
  }

  function fakeNonceResponse(nonce) {
    return Promise.resolve({
      ok: true,
      status: 200,
      json: function(){ return Promise.resolve({ success: true, data: { ml_nonce: nonce || '' } }); },
      text: function(){ return Promise.resolve('{"success":true,"data":{"ml_nonce":"' + String(nonce || '').replace(/"/g, '\\"') + '"}}'); }
    });
  }

  function formLooksLikeMailerLite(form) {
    if (!form || !form.querySelector || !form.querySelector('input[name="ml_nonce"]')) { return false; }
    try {
      return !!(form.closest('[id^="mailerlite-form_"]') || form.closest('[data-temp-id]') || form.querySelector('.mailerlite-subscribe-submit') || form.querySelector('[class*="mailerlite"]'));
    } catch (e) {
      return true;
    }
  }

  function findFormFromTarget(target) {
    try {
      if (target && target.closest) {
        var form = target.closest('form');
        if (form && formLooksLikeMailerLite(form)) { return form; }
      }
    } catch (e) {}
    return null;
  }

  function setSubmitDisabled(form, disabled) {
    try {
      var buttons = form.querySelectorAll('.mailerlite-subscribe-submit, button[type="submit"], input[type="submit"]');
      for (var i = 0; i < buttons.length; i++) { buttons[i].disabled = !!disabled; }
    } catch (e) {}
  }

  function refreshFormNonce(form) {
    if (!formLooksLikeMailerLite(form)) { return Promise.resolve(false); }
    if (form.__ucwpMlNonceRefreshing) { return form.__ucwpMlNonceRefreshing; }

    var input = form.querySelector('input[name="ml_nonce"]');
    if (!input) { return Promise.resolve(false); }

    var url = ajaxUrl || (window.location.origin + '/wp-admin/admin-ajax.php');
    var body = new URLSearchParams();
    body.append('action', 'ml_create_nonce');
    body.append('ml_nonce', input.value || '');

    form.__ucwpMlNonceRefreshing = realFetch.call(window, url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    }).then(function(response){
      return response.json();
    }).then(function(json){
      if (json && json.success && json.data && json.data.ml_nonce) {
        input.value = json.data.ml_nonce;
        form.__ucwpMlNonceReady = true;
        setSubmitDisabled(form, false);
        return true;
      }
      return false;
    }).catch(function(error){
      try { console.warn('UltraCache MailerLite nonce refresh failed', error); } catch (e) {}
      return false;
    }).then(function(ok){
      form.__ucwpMlNonceRefreshing = null;
      return ok;
    });

    return form.__ucwpMlNonceRefreshing;
  }

  window.fetch = function(input, init) {
    if (isMailerLiteNonceRequest(input, init || {})) {
      ajaxUrl = getRequestUrl(input) || ajaxUrl;
      var oldNonce = getNonceFromBody(init && init.body ? init.body : '');
      return fakeNonceResponse(oldNonce);
    }
    return realFetch.apply(this, arguments);
  };

  function maybeRefreshFromInteraction(event) {
    var form = findFormFromTarget(event && event.target ? event.target : null);
    if (!form || form.__ucwpMlNonceReady || refreshStarted) { return; }
    refreshStarted = true;
    refreshFormNonce(form).then(function(){ refreshStarted = false; });
  }

  document.addEventListener('focusin', maybeRefreshFromInteraction, true);
  document.addEventListener('pointerdown', maybeRefreshFromInteraction, true);
  document.addEventListener('touchstart', maybeRefreshFromInteraction, true);
  document.addEventListener('keydown', maybeRefreshFromInteraction, true);

  document.addEventListener('submit', function(event){
    var form = event && event.target ? event.target : null;
    if (!formLooksLikeMailerLite(form) || form.__ucwpMlNonceReady) { return; }

    event.preventDefault();
    event.stopImmediatePropagation();
    setSubmitDisabled(form, true);

    refreshFormNonce(form).then(function(ok){
      if (!ok) {
        setSubmitDisabled(form, false);
        return;
      }
      setTimeout(function(){
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          var submitEvent = document.createEvent('Event');
          submitEvent.initEvent('submit', true, true);
          form.dispatchEvent(submitEvent);
        }
      }, 0);
    });
  }, true);
})();
</script>
JS;

            if (preg_match('/<script\b[^>]*>[\s\S]*?ml_create_nonce[\s\S]*?<\/script>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
                $offset = isset($match[0][1]) ? (int) $match[0][1] : -1;
                if ($offset >= 0) {
                    return substr($html, 0, $offset) . $script . "\n" . substr($html, $offset);
                }
            }

            if (false !== stripos($html, '</head>')) {
                return preg_replace('/<\/head>/i', $script . "\n</head>", $html, 1);
            }

            return $script . "\n" . $html;
        }

        private function apply_critical_request_chain_relief($html, array $settings = array())
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            $html = $this->inject_manual_critical_preload_links($html, $settings);
            $html = $this->inject_detected_slider_fetch_preloads($html, $settings);
            $html = $this->rewrite_chain_delay_stylesheet_links($html, $settings);

            return $html;
        }

        private function get_critical_request_chain_delay_fragments(array $settings = array())
        {
            if (isset($settings['critical_request_chain_delay_list']) && is_array($settings['critical_request_chain_delay_list'])) {
                return array_values(array_unique(array_filter(array_map('strval', $settings['critical_request_chain_delay_list']), static function ($item) {
                    return '' !== trim((string) $item);
                })));
            }

            return array();
        }

        private function rewrite_chain_delay_stylesheet_links($html, array $settings = array())
        {
            $fragments = $this->get_critical_request_chain_delay_fragments($settings);
            if (empty($fragments) || false === stripos((string) $html, '<link')) {
                return $html;
            }

            $processed = $this->rewrite_chain_delay_stylesheet_links_with_processor($html, $fragments);
            if (is_string($processed)) {
                return $processed;
            }

            $that = $this;
            $rewritten = preg_replace_callback('/<link\b[^>]*>/i', static function ($matches) use ($that, $fragments) {
                $tag = isset($matches[0]) ? (string) $matches[0] : '';
                if ('' === $tag || !$that->html_tag_rel_contains_stylesheet($tag)) {
                    return $tag;
                }
                if (false !== stripos($tag, 'data-ucwp-async-css=') || false !== stripos($tag, 'data-ucwp-frontpage-css=') || false !== stripos($tag, 'data-ucwp-page-css-bundle=')) {
                    return $tag;
                }
                $href = $that->extract_attribute_from_html_tag($tag, 'href');
                if ('' === $href || !$that->asset_matches_fragment_list('', $href, $fragments)) {
                    return $tag;
                }

                return $that->force_async_stylesheet_link_tag($tag);
            }, $html);

            return is_string($rewritten) ? $rewritten : $html;
        }

        private function rewrite_chain_delay_stylesheet_links_with_processor($html, array $fragments)
        {
            if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || false === stripos($html, '<link')) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor($html);
                $changed = false;
                $fallbacks = array();
                $index = 0;

                while ($processor->next_tag('LINK')) {
                    $rel = $processor->get_attribute('rel');
                    if (!$this->html_rel_attribute_contains_stylesheet($rel)) {
                        continue;
                    }

                    if (null !== $processor->get_attribute('data-ucwp-async-css')
                        || null !== $processor->get_attribute('data-ucwp-frontpage-css')
                        || null !== $processor->get_attribute('data-ucwp-page-css-bundle')) {
                        continue;
                    }

                    if (null !== $processor->get_attribute('onload')) {
                        continue;
                    }

                    $href = $processor->get_attribute('href');
                    if (!is_string($href) || '' === $href || !$this->asset_matches_fragment_list('', $href, $fragments)) {
                        continue;
                    }

                    $marker = 'ucwp-chain-delay-' . md5($href . '|' . (++$index));
                    $fallbacks[$marker] = $this->build_async_css_noscript_fallback_link($href, $processor->get_attribute('media'));

                    $processor->set_attribute('media', 'print');
                    $processor->set_attribute('onload', "this.media='all'");
                    $processor->set_attribute('data-ucwp-async-css', '1');
                    $processor->set_attribute('data-ucwp-noscript-token', $marker);
                    $changed = true;
                }

                if (!$changed) {
                    return (string) $html;
                }

                $updated_html = $processor->get_updated_html();
                if (!is_string($updated_html) || '' === $updated_html) {
                    return null;
                }

                return $this->append_async_css_noscript_fallbacks_from_markers($updated_html, $fallbacks);
            } catch (\Throwable $e) {
                return null;
            }
        }

        private function force_async_stylesheet_link_tag($tag)
        {
            $tag = (string) $tag;
            if ('' === $tag || false !== stripos($tag, ' onload=')) {
                return $tag;
            }

            $rewritten = $this->remove_html_tag_attribute($tag, 'media');
            $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'media', 'print');
            $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'onload', "this.media='all'");
            $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'data-ucwp-async-css', '1');
            return $rewritten . '<noscript>' . $tag . '</noscript>';
        }

        private function apply_asset_chain_cleanup_to_html($html, array $settings = array())
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            if ($this->html_has_asset_cleanup_exclusion($html, $settings)) {
                return $html;
            }

            if (!empty($settings['asset_cleanup_woo_product_assets']) && !$this->html_has_single_product_context($html)) {
                $html = $this->remove_asset_tags_matching_fragments($html, $this->get_woocommerce_product_asset_cleanup_fragments());
            }

            if (!empty($settings['asset_cleanup_product_filter_assets']) && !$this->html_has_product_filter_context($html)) {
                $html = $this->remove_asset_tags_matching_fragments($html, $this->get_product_filter_asset_cleanup_fragments());
            }

            if (!empty($settings['asset_cleanup_woo_blocks_css']) && !$this->html_has_woocommerce_block_context($html)) {
                $html = $this->remove_asset_tags_matching_fragments($html, array('wc-blocks.css', 'wc-blocks-style', 'woocommerce-blocks'));
            }

            return $html;
        }

        private function remove_asset_tags_matching_fragments($html, array $fragments)
        {
            if (empty($fragments)) {
                return $html;
            }

            $processed = $this->remove_asset_tags_matching_fragments_with_processor($html, $fragments);
            if (is_string($processed)) {
                return $processed;
            }

            $that = $this;
            $html = preg_replace_callback('/<script\b[^>]*\bsrc=("|\')(.*?)\1[^>]*>\s*<\/script>/is', static function ($matches) use ($that, $fragments) {
                $tag = isset($matches[0]) ? (string) $matches[0] : '';
                $src = $that->extract_attribute_from_html_tag($tag, 'src');
                return $that->asset_matches_fragment_list('', $src, $fragments) ? '' : $tag;
            }, $html);

            $html = preg_replace_callback('/<link\b[^>]*>/i', static function ($matches) use ($that, $fragments) {
                $tag = isset($matches[0]) ? (string) $matches[0] : '';
                $href = $that->extract_attribute_from_html_tag($tag, 'href');
                return ('' !== $href && $that->asset_matches_fragment_list('', $href, $fragments)) ? '' : $tag;
            }, is_string($html) ? $html : '');

            return is_string($html) ? $html : '';
        }

        private function remove_asset_tags_matching_fragments_with_processor($html, array $fragments)
        {
            if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || (false === stripos($html, '<script') && false === stripos($html, '<link'))) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor($html);
                $changed = false;
                $tokens = array();
                $index = 0;

                while ($processor->next_tag()) {
                    $tag_name = strtoupper((string) $processor->get_tag());
                    if ('SCRIPT' !== $tag_name && 'LINK' !== $tag_name) {
                        continue;
                    }

                    $url = ('SCRIPT' === $tag_name) ? $processor->get_attribute('src') : $processor->get_attribute('href');
                    if (!is_string($url) || '' === $url || !$this->asset_matches_fragment_list('', $url, $fragments)) {
                        continue;
                    }

                    $token = 'ucwp-remove-asset-' . md5($tag_name . '|' . $url . '|' . (++$index));
                    $processor->set_attribute('data-ucwp-remove-asset-token', $token);
                    $tokens[$token] = strtolower($tag_name);
                    $changed = true;
                }

                if (!$changed) {
                    return null;
                }

                $updated_html = $processor->get_updated_html();
                if (!is_string($updated_html) || '' === $updated_html) {
                    return null;
                }

                foreach ($tokens as $token => $tag_name) {
                    if ('script' === $tag_name) {
                        $pattern = '/<script\b(?=[^>]*\bdata-ucwp-remove-asset-token=("|\')' . preg_quote($token, '/') . '\1)[^>]*>\s*<\/script>/is';
                    } else {
                        $pattern = '/<link\b(?=[^>]*\bdata-ucwp-remove-asset-token=("|\')' . preg_quote($token, '/') . '\1)[^>]*>/i';
                    }
                    $updated_html = preg_replace($pattern, '', $updated_html, 1);
                }

                if (!is_string($updated_html) || false !== stripos($updated_html, 'data-ucwp-remove-asset-token=')) {
                    return null;
                }

                return $updated_html;
            } catch (\Throwable $e) {
                return null;
            }
        }

        private function get_asset_cleanup_exclude_fragments(array $settings = array())
        {
            $defaults = array(
                'elementor',
                'bricks',
                'oxygen',
                'wpbakery',
                'vc_',
                'revslider',
                'sr7',
                'ajaxsearch',
                'fibosearch',
                '.dgwt-wcas',
                'aws-container',
                'cart',
                'checkout',
                'account',
            );

            $user_list = array();
            if (isset($settings['asset_cleanup_exclude_list']) && is_array($settings['asset_cleanup_exclude_list'])) {
                $user_list = $settings['asset_cleanup_exclude_list'];
            }

            return array_values(array_unique(array_filter(array_map('strval', array_merge($defaults, $user_list)), 'strlen')));
        }

        private function current_request_matches_asset_cleanup_exclusion(array $settings = array())
        {
            $request_uri = function_exists('ucwp_server_value') ? strtolower(sanitize_text_field(ucwp_server_value('REQUEST_URI'))) : '';
            if ('' === $request_uri) {
                return false;
            }

            foreach ($this->get_asset_cleanup_exclude_fragments($settings) as $fragment) {
                $fragment = strtolower(trim((string) $fragment));
                if ('' !== $fragment && false !== strpos($request_uri, $fragment)) {
                    return true;
                }
            }

            return false;
        }

        private function html_has_asset_cleanup_exclusion($html, array $settings = array())
        {
            $haystack = strtolower((string) $html);
            if ('' === $haystack) {
                return false;
            }

            foreach ($this->get_asset_cleanup_exclude_fragments($settings) as $fragment) {
                $fragment = strtolower(trim((string) $fragment));
                if ('' !== $fragment && false !== strpos($haystack, $fragment)) {
                    return true;
                }
            }

            return false;
        }

        private function html_has_single_product_context($html)
        {
            $haystack = strtolower((string) $html);
            return false !== strpos($haystack, 'single-product')
                || false !== strpos($haystack, 'product_title')
                || false !== strpos($haystack, 'woocommerce-product-gallery')
                || false !== strpos($haystack, 'summary entry-summary');
        }

        private function html_has_product_filter_context($html)
        {
            $haystack = strtolower((string) $html);
            return false !== strpos($haystack, 'woocommerce-products-filter')
                || false !== strpos($haystack, 'woof_')
                || false !== strpos($haystack, 'woof_container')
                || false !== strpos($haystack, 'wpf-')
                || false !== strpos($haystack, 'berocket')
                || false !== strpos($haystack, 'data-css-class="woof"')
                || false !== strpos($haystack, 'ion.rangeSlider');
        }

        private function html_has_woocommerce_block_context($html)
        {
            $haystack = strtolower((string) $html);
            return false !== strpos($haystack, 'wp-block-woocommerce')
                || false !== strpos($haystack, 'wc-block-')
                || false !== strpos($haystack, 'woocommerce-cart')
                || false !== strpos($haystack, 'woocommerce-checkout')
                || false !== strpos($haystack, 'woocommerce-account');
        }

        private function inject_speculation_rules_prefetch($html, array $settings = array())
        {
            if (!$this->should_inject_speculation_rules_prefetch($html, $settings)) {
                return $html;
            }

            if (false !== stripos($html, 'type="speculationrules"') || false !== stripos($html, "type='speculationrules'")) {
                return $html;
            }

            $rules = $this->build_speculation_rules_prefetch_config($settings);
            if (empty($rules)) {
                return $html;
            }

            $json = wp_json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json) || '' === $json) {
                return $html;
            }

            $script = '<script type="speculationrules">' . $json . '</script>';

            return $this->insert_html_before_closing_head($html, $script);
        }

        private function should_inject_speculation_rules_prefetch($html, array $settings = array())
        {
            if (empty($settings['speculation_rules_enabled'])) {
                return false;
            }

            if (!is_string($html) || '' === $html || false === stripos($html, '</head>')) {
                return false;
            }

            if (is_admin() || is_user_logged_in() || is_preview() || is_feed() || is_trackback() || is_robots()) {
                return false;
            }

            if ('' !== sanitize_text_field(ucwp_query_value('preview')) || '' !== sanitize_text_field(ucwp_query_value('customize_changeset_uuid')) || '' !== sanitize_text_field(ucwp_query_value('customize_autosaved'))) {
                return false;
            }

            if (function_exists('is_search') && is_search()) {
                return false;
            }

            if (function_exists('is_cart') && is_cart()) {
                return false;
            }

            if (function_exists('is_checkout') && is_checkout()) {
                return false;
            }

            if (function_exists('is_account_page') && is_account_page()) {
                return false;
            }

            return true;
        }

        private function build_speculation_rules_prefetch_config(array $settings = array())
        {
            $conditions = array(
                array('href_matches' => '/*'),
                array('not' => array('href_matches' => '/wp-admin/*')),
                array('not' => array('href_matches' => '/wp-login.php*')),
                array('not' => array('href_matches' => '/cart/*')),
                array('not' => array('href_matches' => '/checkout/*')),
                array('not' => array('href_matches' => '/my-account/*')),
                array('not' => array('href_matches' => '/wc-api/*')),
                array('not' => array('href_matches' => '/logout*')),
                array('not' => array('href_matches' => '/*\?*')),
                array('not' => array('selector_matches' => '[rel~=nofollow]')),
                array('not' => array('selector_matches' => '[target]')),
                array('not' => array('selector_matches' => '[download]')),
                array('not' => array('selector_matches' => '.no-speculate')),
                array('not' => array('selector_matches' => '.no-prerender')),
                array('not' => array('selector_matches' => '.ajax_add_to_cart')),
            );

            $excluded_paths = array();
            if (!empty($settings['excluded_paths']) && is_array($settings['excluded_paths'])) {
                $excluded_paths = $settings['excluded_paths'];
            }

            foreach ($excluded_paths as $path) {
                $path = trim((string) $path);
                if ('' === $path || '/' === $path) {
                    continue;
                }

                $pattern = $this->convert_path_to_speculation_href_pattern($path);
                if ('' !== $pattern) {
                    $conditions[] = array('not' => array('href_matches' => $pattern));
                }
            }

            return array(
                'prefetch' => array(
                    array(
                        'where'     => array('and' => $conditions),
                        'eagerness' => 'moderate',
                    ),
                ),
            );
        }

        private function convert_path_to_speculation_href_pattern($path)
        {
            $path = trim((string) $path);
            if ('' === $path) {
                return '';
            }

            $path = preg_replace('#https?://[^/]+#i', '', $path);
            if (!is_string($path) || '' === $path) {
                return '';
            }

            if ('/' !== $path[0]) {
                $path = '/' . $path;
            }

            if (substr($path, -1) === '*') {
                return $path;
            }

            if (substr($path, -1) === '/') {
                return $path . '*';
            }

            return $path . '*';
        }

        private function should_use_safe_frontend_optimization_mode($html)
        {
            if (!is_string($html) || '' === $html) {
                return false;
            }

            if ($this->has_fragile_revslider_shell($html) || $this->html_has_slider_safe_mode_marker($html)) {
                return true;
            }

            $html_lc = strtolower($html);
            $empty_custom_elements = 0;
            if (preg_match_all('/<([a-z][a-z0-9]*-[a-z0-9-]*)\b[^>]*>\s*<\/\1>/i', $html, $matches)) {
                $empty_custom_elements = is_array($matches[0]) ? count($matches[0]) : 0;
            }

            $has_client_bootstrap = false;
            foreach (array(
                'data-reactroot',
                '__next',
                'astro-island',
                'ng-version',
                'window.__nuxt',
                'window.__remixcontext',
                'customElements.define',
            ) as $marker) {
                if (false !== strpos($html_lc, strtolower($marker))) {
                    $has_client_bootstrap = true;
                    break;
                }
            }

            if ($empty_custom_elements >= 8 && $has_client_bootstrap) {
                return true;
            }

            return false;
        }

        private function protect_html_regions_from_safe_minify($html, array &$tokens)
        {
            $pattern = '#<(script|style|pre|textarea|svg|math|title|code|noscript|template)\b[^>]*>.*?</\1>#is';
            $counter = 0;

            return (string) preg_replace_callback(
                $pattern,
                function ($matches) use (&$tokens, &$counter) {
                    $placeholder = "%%UCWP_HTML_MINIFY_TOKEN_" . (++$counter) . "%%";
                    $tokens[$placeholder] = (string) $matches[0];
                    return $placeholder;
                },
                (string) $html
            );
        }

        private function remove_noncritical_html_comments_for_safe_minify($html)
        {
            return (string) preg_replace_callback(
                '/<!--([\s\S]*?)-->/u',
                function ($matches) {
                    $comment = isset($matches[1]) ? trim((string) $matches[1]) : '';
                    if ('' === $comment) {
                        return '';
                    }

                    $normalized = strtolower($comment);
                    foreach (array('[if ', '<![endif', 'wp:', '/wp:', 'more', 'nextpage', 'googleoff:', 'googleon:', 'noindex', '/noindex') as $prefix) {
                        if (0 === strpos($normalized, $prefix)) {
                            return (string) $matches[0];
                        }
                    }

                    return '';
                },
                (string) $html
            );
        }

        private function minify_head_html_safely($html)
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            if (!preg_match('/<head\b[^>]*>([\s\S]*?)<\/head>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
                return $html;
            }

            $head_html = (string) $matches[0][0];
            $head_offset = (int) $matches[0][1];
            $head_inner = isset($matches[1][0]) ? (string) $matches[1][0] : '';
            $minified_inner = (string) preg_replace('/>\s+</', '><', $head_inner);
            $minified_head = preg_replace('/<head\b([^>]*)>[\s\S]*<\/head>/i', '<head$1>' . $minified_inner . '</head>', $head_html, 1);
            if (!is_string($minified_head) || '' === $minified_head) {
                return $html;
            }

            return substr($html, 0, $head_offset) . $minified_head . substr($html, $head_offset + strlen($head_html));
        }

        private function html_tag_processor_available()
        {
            return class_exists('WP_HTML_Tag_Processor');
        }

        private function get_current_html_processor_tag_markup($processor, $fallback_tag = 'tag')
        {
            $fallback_tag = preg_replace('/[^A-Za-z0-9:-]/', '', (string) $fallback_tag);
            if ('' === $fallback_tag) {
                $fallback_tag = 'tag';
            }

            if (!is_object($processor) || !method_exists($processor, 'get_updated_html')) {
                return '<' . $fallback_tag . '>';
            }

            try {
                $html = (string) $processor->get_updated_html();
                if (preg_match('/<' . preg_quote($fallback_tag, '/') . '\b[^>]*>/i', $html, $matches)) {
                    return (string) $matches[0];
                }

                if (preg_match('/<([a-zA-Z][a-zA-Z0-9:-]*)\b[^>]*>/i', $html, $matches)) {
                    return (string) $matches[0];
                }
            } catch (\Throwable $e) {
                // Fall through to minimal synthetic markup.
            }

            return '<' . $fallback_tag . '>';
        }

        private function get_html_tag_attribute_with_processor($html, $attribute)
        {
            if (!$this->html_tag_processor_available()) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor((string) $html);
                if (!$processor->next_tag()) {
                    return null;
                }

                $value = $processor->get_attribute((string) $attribute);
                if (null === $value) {
                    return null;
                }

                if (true === $value) {
                    return (string) $attribute;
                }

                if (false === $value) {
                    return '';
                }

                return html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
            } catch (\Throwable $e) {
                return null;
            }
        }

        private function set_html_tag_attribute_with_processor($html, $attribute, $value)
        {
            if (!$this->html_tag_processor_available()) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor((string) $html);
                if (!$processor->next_tag()) {
                    return null;
                }

                $processor->set_attribute((string) $attribute, (string) $value);
                $updated = $processor->get_updated_html();
                return is_string($updated) && '' !== $updated ? $updated : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        private function remove_html_tag_attribute_with_processor($html, $attribute)
        {
            if (!$this->html_tag_processor_available()) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor((string) $html);
                if (!$processor->next_tag()) {
                    return null;
                }

                $processor->remove_attribute((string) $attribute);
                $updated = $processor->get_updated_html();
                return is_string($updated) && '' !== $updated ? $updated : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        private function remove_html_tag_attribute($html, $attribute)
        {
            $attribute = trim((string) $attribute);
            if ('' === $attribute) {
                return (string) $html;
            }

            $processed = $this->remove_html_tag_attribute_with_processor($html, $attribute);
            if (is_string($processed)) {
                return $processed;
            }

            return (string) preg_replace('/\s+' . preg_quote($attribute, '/') . '(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?/i', '', (string) $html);
        }

        private function set_or_add_html_tag_attribute($html, $attribute, $value)
        {
            $attribute = trim((string) $attribute);
            if ('' === $attribute) {
                return (string) $html;
            }

            $processed = $this->set_html_tag_attribute_with_processor($html, $attribute, $value);
            if (is_string($processed)) {
                return $processed;
            }

            $quoted_value = esc_attr((string) $value);
            $pattern = '/\b' . preg_quote($attribute, '/') . '(?:\s*=\s*("|\')(.*?)(\1)|\s*=\s*[^\s"\'=<>`]+)?/i';
            if (preg_match($pattern, (string) $html)) {
                return (string) preg_replace($pattern, $attribute . '="' . $quoted_value . '"', (string) $html, 1);
            }

            return (string) preg_replace('/\s*\/?>$/', ' ' . $attribute . '="' . $quoted_value . '"$0', (string) $html, 1);
        }

        private function html_link_href_exists($html, $href)
        {
            $href = html_entity_decode((string) $href, ENT_QUOTES, 'UTF-8');
            if ('' === $href || !is_string($html) || '' === $html || false === stripos($html, '<link')) {
                return false;
            }

            if ($this->html_tag_processor_available()) {
                try {
                    $processor = new WP_HTML_Tag_Processor($html);
                    $target = $this->normalize_public_resource_url($href);
                    while ($processor->next_tag('LINK')) {
                        $current = $processor->get_attribute('href');
                        if (!is_string($current) || '' === $current) {
                            continue;
                        }

                        $decoded_current = html_entity_decode($current, ENT_QUOTES, 'UTF-8');
                        if ($current === $href || $decoded_current === $href) {
                            return true;
                        }

                        if ('' !== $target && $this->normalize_public_resource_url($decoded_current) === $target) {
                            return true;
                        }
                    }
                } catch (\Throwable $e) {
                    // Fall through to the conservative string checks below.
                }
            }

            $escaped = esc_attr($href);
            return false !== strpos($html, 'href="' . $escaped . '"')
                || false !== strpos($html, "href='" . $escaped . "'")
                || false !== strpos($html, 'href="' . $href . '"')
                || false !== strpos($html, "href='" . $href . "'");
        }

        private function insert_html_before_closing_head($html, $markup)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '</head')) {
                return $html;
            }

            $markup = (string) $markup;
            if ('' === trim($markup)) {
                return $html;
            }

            $updated = preg_replace('/<\/head>/i', rtrim($markup) . "\n</head>", $html, 1);
            return is_string($updated) && '' !== $updated ? $updated : $html;
        }

        private function normalize_public_resource_url($url)
        {
            $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
            if ('' === $url) {
                return '';
            }

            $home_url = home_url('/');
            $preferred_scheme = (string) wp_parse_url($home_url, PHP_URL_SCHEME);
            if ('' === $preferred_scheme) {
                $preferred_scheme = is_ssl() ? 'https' : 'http';
            }
            $preferred_host = strtolower((string) wp_parse_url($home_url, PHP_URL_HOST));

            if (0 === strpos($url, '//')) {
                return $preferred_scheme . ':' . $url;
            }

            if (preg_match('#^https?://#i', $url)) {
                $url_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
                $url_scheme = (string) wp_parse_url($url, PHP_URL_SCHEME);
                if ('' !== $preferred_host && '' !== $url_host && $preferred_host === $url_host && strtolower($url_scheme) !== strtolower($preferred_scheme)) {
                    if (function_exists('set_url_scheme')) {
                        return set_url_scheme($url, $preferred_scheme);
                    }
                }
            }

            return $url;
        }

        private function normalize_protocol_relative_urls_in_html($html)
        {
            $scheme = wp_parse_url(home_url('/'), PHP_URL_SCHEME);
            if (!$scheme) {
                $scheme = is_ssl() ? 'https' : 'http';
            }

            $processed = $this->normalize_protocol_relative_tag_attributes_with_processor($html, $scheme);
            if (is_string($processed)) {
                $html = $processed;
            }

            $html = (string) preg_replace_callback(
                "/(\b(?:src|href|poster|data-src|data-lazy-src|data-bg|data-background|data-bg-image|data-background-image)=)([\"'])(?:\/\/)/i",
                function ($matches) use ($scheme) {
                    return $matches[1] . $matches[2] . $scheme . '://';
                },
                (string) $html
            );

            $html = (string) preg_replace_callback(
                "/url\((\s*[\"']?)\/\//i",
                function ($matches) use ($scheme) {
                    return 'url(' . $matches[1] . $scheme . '://';
                },
                $html
            );

            $html = (string) preg_replace_callback(
                "/(\bsrcset=)([\"'])([^\"']+)\2/i",
                function ($matches) use ($scheme) {
                    $parts = array_map('trim', explode(',', $matches[3]));
                    foreach ($parts as $index => $part) {
                        $segments = preg_split('/\s+/', $part, 2);
                        if (!empty($segments[0]) && 0 === strpos($segments[0], '//')) {
                            $segments[0] = $scheme . ':' . $segments[0];
                            $parts[$index] = trim(implode(' ', array_filter($segments, 'strlen')));
                        }
                    }
                    return $matches[1] . $matches[2] . implode(', ', $parts) . $matches[2];
                },
                $html
            );

            $html = (string) preg_replace_callback(
                "/([\"'])\/\/([^\"'\s<>]+)\1/",
                function ($matches) use ($scheme) {
                    return $matches[1] . $scheme . '://' . $matches[2] . $matches[1];
                },
                $html
            );

            return $html;
        }

        private function normalize_protocol_relative_tag_attributes_with_processor($html, $scheme)
        {
            if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || false === strpos($html, '//')) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor($html);
                $changed = false;
                $url_attributes = array('src', 'href', 'poster', 'data-src', 'data-lazy-src', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image');

                while ($processor->next_tag()) {
                    foreach ($url_attributes as $attribute) {
                        $value = $processor->get_attribute($attribute);
                        if (!is_string($value) || '' === $value || 0 !== strpos($value, '//')) {
                            continue;
                        }

                        $processor->set_attribute($attribute, (string) $scheme . ':' . $value);
                        $changed = true;
                    }

                    $srcset = $processor->get_attribute('srcset');
                    if (is_string($srcset) && false !== strpos($srcset, '//')) {
                        $parts = array_map('trim', explode(',', $srcset));
                        $srcset_changed = false;
                        foreach ($parts as $index => $part) {
                            $segments = preg_split('/\s+/', $part, 2);
                            if (!empty($segments[0]) && 0 === strpos($segments[0], '//')) {
                                $segments[0] = (string) $scheme . ':' . $segments[0];
                                $parts[$index] = trim(implode(' ', array_filter($segments, 'strlen')));
                                $srcset_changed = true;
                            }
                        }

                        if ($srcset_changed) {
                            $processor->set_attribute('srcset', implode(', ', $parts));
                            $changed = true;
                        }
                    }

                    $style = $processor->get_attribute('style');
                    if (is_string($style) && false !== strpos($style, 'url(//')) {
                        $updated_style = (string) preg_replace('/url\((\s*[\"\']?)\/\//i', 'url($1' . $scheme . '://', $style);
                        if ($updated_style !== $style) {
                            $processor->set_attribute('style', $updated_style);
                            $changed = true;
                        }
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

        private function normalize_local_path_for_compare($path)
        {
            return rtrim(str_replace('\\', '/', (string) $path), '/');
        }

        private function path_is_within_root($path, $root)
        {
            $path = $this->normalize_local_path_for_compare($path);
            $root = $this->normalize_local_path_for_compare($root);

            if ('' === $path || '' === $root) {
                return false;
            }

            return $path === $root || 0 === strpos($path, $root . '/');
        }

        private function build_canonical_local_path_from_relative($root, $relative)
        {
            $root_real = realpath($root);
            if (!is_string($root_real) || '' === $root_real) {
                return '';
            }

            $relative = rawurldecode(str_replace('\\', '/', (string) $relative));
            $relative = ltrim($relative, '/');
            if ('' === $relative) {
                return '';
            }

            foreach (explode('/', $relative) as $segment) {
                if ('' === $segment || '.' === $segment || '..' === $segment) {
                    return '';
                }
            }

            $candidate = trailingslashit($root_real) . $relative;
            $candidate_real = realpath($candidate);
            if (!is_string($candidate_real) || '' === $candidate_real) {
                return '';
            }

            return $this->path_is_within_root($candidate_real, $root_real) ? str_replace('\\', '/', $candidate_real) : '';
        }

        private function resolve_local_path_from_public_url($url)
        {
            $url = $this->normalize_public_resource_url($url);
            $host = (string) wp_parse_url($url, PHP_URL_HOST);
            $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            if ('' === $host || '' === $home_host || strtolower($host) !== strtolower($home_host)) {
                return '';
            }

            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if ('' === $path) {
                return '';
            }

            $content_path = (string) wp_parse_url(content_url('/'), PHP_URL_PATH);
            if ('' !== $content_path && 0 === strpos($path, $content_path)) {
                $relative = ltrim(substr($path, strlen($content_path)), '/');
                return $this->build_canonical_local_path_from_relative(WP_CONTENT_DIR, $relative);
            }

            $site_path = (string) wp_parse_url(site_url('/'), PHP_URL_PATH);
            if ('' !== $site_path && 0 === strpos($path, $site_path)) {
                $relative = ltrim(substr($path, strlen($site_path)), '/');
                return $this->build_canonical_local_path_from_relative(ABSPATH, $relative);
            }

            return $this->build_canonical_local_path_from_relative(ABSPATH, ltrim($path, '/'));
        }

        private function absolutize_public_resource_url($url, $base_url = '')
        {
            $url = trim((string) $url, " \t\n\r\0\x0B\"'");
            if ('' === $url || 0 === strpos($url, 'data:') || 0 === strpos($url, 'about:') || '#' === $url[0]) {
                return $url;
            }

            if (0 === strpos($url, '//')) {
                return $this->normalize_public_resource_url($url);
            }

            if (preg_match('#^[a-z][a-z0-9+\-.]*:#i', $url)) {
                return $url;
            }

            $base = '' !== $base_url ? $this->normalize_public_resource_url($base_url) : home_url('/');
            $base_parts = wp_parse_url($base);
            if (empty($base_parts['host'])) {
                return $url;
            }

            $scheme = !empty($base_parts['scheme']) ? $base_parts['scheme'] : (is_ssl() ? 'https' : 'http');
            $host = $base_parts['host'];
            $port = isset($base_parts['port']) ? ':' . $base_parts['port'] : '';

            if ('/' === $url[0]) {
                return $scheme . '://' . $host . $port . $url;
            }

            $base_path = !empty($base_parts['path']) ? $base_parts['path'] : '/';
            $dir = preg_replace('#/[^/]*$#', '/', $base_path);
            $path = $dir . $url;

            $fragment = '';
            if (false !== strpos($path, '#')) {
                list($path, $fragment) = explode('#', $path, 2);
                $fragment = '#' . $fragment;
            }

            $query = '';
            if (false !== strpos($path, '?')) {
                list($path, $query) = explode('?', $path, 2);
                $query = '?' . $query;
            }

            $segments = array();
            foreach (explode('/', $path) as $segment) {
                if ('' === $segment || '.' === $segment) {
                    continue;
                }
                if ('..' === $segment) {
                    array_pop($segments);
                    continue;
                }
                $segments[] = $segment;
            }

            return $scheme . '://' . $host . $port . '/' . implode('/', $segments) . $query . $fragment;
        }

        private function strip_probable_frontend_authoring_assets($html)
        {
            if (false === stripos($html, '<script') && false === stripos($html, '<link')) {
                return $html;
            }

            $processed = $this->strip_probable_frontend_authoring_assets_with_processor($html);
            if (is_string($processed)) {
                return $processed;
            }

            foreach (array(
                '/<script\b[^>]*\bsrc=(\"|\')(.*?)\1[^>]*>\s*<\/script>/is',
                '/<link\b[^>]*\bhref=(\"|\')(.*?)\1[^>]*>/is',
            ) as $pattern) {
                $html = (string) preg_replace_callback(
                    $pattern,
                    function ($matches) {
                        $tag = (string) $matches[0];
                        $url = $this->extract_attribute_from_html_tag($tag, false !== stripos($tag, '<script') ? 'src' : 'href');
                        if ($this->should_strip_probable_frontend_authoring_asset($url, $tag)) {
                            return '';
                        }
                        return $tag;
                    },
                    $html
                );
            }

            return $html;
        }

        private function strip_probable_frontend_authoring_assets_with_processor($html)
        {
            if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || (false === stripos($html, '<script') && false === stripos($html, '<link'))) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor($html);
                $changed = false;
                $tokens = array();
                $index = 0;

                while ($processor->next_tag()) {
                    $tag_name = strtoupper((string) $processor->get_tag());
                    if ('SCRIPT' !== $tag_name && 'LINK' !== $tag_name) {
                        continue;
                    }

                    $url = ('SCRIPT' === $tag_name) ? $processor->get_attribute('src') : $processor->get_attribute('href');
                    if (!is_string($url) || '' === $url) {
                        continue;
                    }

                    $tag_markup = $this->get_current_html_processor_tag_markup($processor, strtolower($tag_name));
                    if (!$this->should_strip_probable_frontend_authoring_asset($url, $tag_markup)) {
                        continue;
                    }

                    $token = 'ucwp-strip-authoring-' . md5($tag_name . '|' . $url . '|' . (++$index));
                    $processor->set_attribute('data-ucwp-strip-authoring-token', $token);
                    $tokens[$token] = strtolower($tag_name);
                    $changed = true;
                }

                if (!$changed) {
                    return null;
                }

                $updated_html = $processor->get_updated_html();
                if (!is_string($updated_html) || '' === $updated_html) {
                    return null;
                }

                foreach ($tokens as $token => $tag_name) {
                    if ('script' === $tag_name) {
                        $pattern = '/<script\b(?=[^>]*\bdata-ucwp-strip-authoring-token=(\"|\')' . preg_quote($token, '/') . '\1)[^>]*>\s*<\/script>/is';
                    } else {
                        $pattern = '/<link\b(?=[^>]*\bdata-ucwp-strip-authoring-token=(\"|\')' . preg_quote($token, '/') . '\1)[^>]*>/i';
                    }
                    $updated_html = preg_replace($pattern, '', $updated_html, 1);
                }

                if (!is_string($updated_html) || false !== stripos($updated_html, 'data-ucwp-strip-authoring-token=')) {
                    return null;
                }

                return $updated_html;
            } catch (\Throwable $e) {
                return null;
            }
        }

        private function should_strip_probable_frontend_authoring_asset($url, $tag_html)
        {
            $url = strtolower($this->normalize_public_resource_url($url));
            $tag_html = strtolower((string) $tag_html);
            if ('' === $url) {
                return false;
            }

            $location_haystack = $url . ' ' . $tag_html;
            if (false === strpos($location_haystack, '/wp-content/plugins/') && false === strpos($location_haystack, '/wp-content/themes/')) {
                return false;
            }

            foreach (array('/admin/', '/wp-admin/', '/shortcode_generator/') as $pattern) {
                if (false !== strpos($url, $pattern)) {
                    return true;
                }
            }

            if ((false !== strpos($location_haystack, 'preview') || false !== strpos($location_haystack, 'backend'))
                && (false !== strpos($location_haystack, '/wp-content/plugins/') || false !== strpos($location_haystack, '/wp-content/themes/'))) {
                return true;
            }

            return false;
        }
}
