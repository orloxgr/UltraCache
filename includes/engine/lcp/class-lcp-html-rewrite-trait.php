<?php
/**
 * LCP HTML rewrite, lazy-loading, and boundary-defer helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_LCP_HTML_Rewrite_Trait
{
private function apply_lcp_priority_pipeline($html, array $settings, $slider_safe_mode)
    {
        if (empty($settings['lcp_image_priority'])) {
            return $html;
        }

        $require_browser_observed_credentials = !empty($slider_safe_mode)
            && $this->is_lcp_request_credentials_preload_contract_enabled($settings);
        $html = $this->apply_html_rewrite_safely($html, 'browser-observed-lcp-preloads', function ($html) use ($require_browser_observed_credentials) {
            return $this->inject_observed_lcp_priority_preloads($html, $require_browser_observed_credentials);
        });

        if ($this->has_confirmed_lcp_observation_for_current_request()) {
            return $this->apply_html_rewrite_safely($html, 'browser-observed-lcp-markup', function ($html) {
                return $this->apply_observed_lcp_priority_markup($html);
            });
        }

        if (!empty($slider_safe_mode)) {
            $html = $this->apply_html_rewrite_safely($html, 'sr7-first-slide-lcp-priority', function ($html) {
                return $this->apply_sr7_first_slide_lcp_priority_markup($html);
            });
            $html = $this->apply_html_rewrite_safely($html, 'safe-lcp-priority-preloads', function ($html) use ($require_browser_observed_credentials) {
                return $this->inject_safe_lcp_priority_preloads($html, $require_browser_observed_credentials);
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

        $script_tags = $this->get_lcp_boundary_external_script_tags($html);
        if (empty($script_tags)) {
            return $html;
        }

        $callback_fragments = $this->get_lcp_boundary_callback_dependency_fragments($html);
        if (!empty($callback_fragments)) {
            $settings['_lcp_boundary_callback_dependency_fragments'] = $callback_fragments;
        }

        $out = '';
        $last = 0;
        $changed = false;

        foreach ($script_tags as $record) {
            $tag = isset($record['tag']) ? (string) $record['tag'] : '';
            $offset = isset($record['offset']) ? (int) $record['offset'] : -1;
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

private function get_lcp_boundary_external_script_tags($html)
    {
        if (!is_string($html) || '' === $html || false === stripos($html, '<script')) {
            return array();
        }

        $script_opening_tags = ultracache_scan_raw_html_tags($html, array('script'));
        if (empty($script_opening_tags)) {
            return array();
        }

        $script_tags = array();
        foreach ($script_opening_tags as $record) {
            if (!empty($record['closing']) || !empty($record['self_closing'])) {
                continue;
            }

            $opening_tag = isset($record['raw']) ? (string) $record['raw'] : '';
            $offset = isset($record['offset']) ? (int) $record['offset'] : -1;
            $end = isset($record['end']) ? (int) $record['end'] : -1;
            if ('' === $opening_tag || $offset < 0 || $end <= $offset || '' === $this->extract_attribute_from_html_tag($opening_tag, 'src')) {
                continue;
            }

            if (!preg_match('/\G\s*<\/script\s*>/i', $html, $closing_match, PREG_OFFSET_CAPTURE, $end)) {
                continue;
            }

            $closing_tag = isset($closing_match[0][0]) ? (string) $closing_match[0][0] : '';
            if ('' === $closing_tag) {
                continue;
            }

            $script_tags[] = array(
                'tag' => $opening_tag . $closing_tag,
                'offset' => $offset,
            );
        }

        return $script_tags;
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

        if (!class_exists('WP_HTML_Tag_Processor')) {
            return $html;
        }

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
                    'data-ultracache-lcp' => $processor->get_attribute('data-ultracache-lcp'),
                    'data-ultracache-sr7-lcp' => $processor->get_attribute('data-ultracache-sr7-lcp'),
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

                if (null === $processor->get_attribute('data-ultracache-lazy-image')) {
                    $processor->set_attribute('data-ultracache-lazy-image', '1');
                    $changed = true;
                }
            }

            if ($changed) {
                $updated = $processor->get_updated_html();
                return is_string($updated) && '' !== $updated ? $updated : $html;
            }
        } catch (\Throwable $e) {
            return $html;
        }

        return $html;
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

        if ('' !== trim((string) (isset($attributes['data-ultracache-lcp']) ? $attributes['data-ultracache-lcp'] : '')) || '' !== trim((string) (isset($attributes['data-ultracache-sr7-lcp']) ? $attributes['data-ultracache-sr7-lcp'] : ''))) {
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
        if (preg_match('/^(?:data|blob):/i', $src)) {
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

        $manual_target = $this->find_manual_lcp_selector_target($html);
        if (!empty($manual_target['boundary_offset'])) {
            return max(1, (int) $manual_target['boundary_offset']);
        }

        $candidate = $this->find_manual_lcp_candidate($html);
        if (null === $candidate) {
            $candidate = $this->find_best_sr7_lcp_candidate($html);
        }
        if (null === $candidate) {
            $candidate = $this->find_best_lcp_candidate_with_tag_processor($html);
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

private function get_lcp_boundary_visible_js_exclusion_fragments(array $settings = array())
    {
        $fragments = array();

        foreach (array('defer_js_exclude_list', 'delay_non_critical_js_exclude_list') as $key) {
            if (isset($settings[$key]) && is_array($settings[$key])) {
                $fragments = array_merge($fragments, $settings[$key]);
            }
        }

        foreach (array('deferJsExcludeList', 'delayNonCriticalJsExcludeList') as $key) {
            if (empty($settings[$key])) {
                continue;
            }

            $value = $settings[$key];
            if (is_array($value)) {
                $fragments = array_merge($fragments, $value);
                continue;
            }

            foreach (preg_split('/\r\n|\r|\n/', (string) $value) as $line) {
                $fragments[] = $line;
            }
        }

        $fragments = array_values(array_unique(array_filter(array_map('strval', $fragments), static function ($item) {
            return '' !== trim((string) $item);
        })));

        return $fragments;
    }

private function lcp_boundary_script_matches_visible_js_exclusions($handle, $src, $tag, array $settings = array())
    {
        $fragments = $this->get_lcp_boundary_visible_js_exclusion_fragments($settings);
        if (empty($fragments)) {
            return false;
        }

        return $this->script_matches_fragment_list_from_haystacks(
            $this->build_js_exclusion_match_haystacks($handle, $src, $tag, ''),
            $fragments
        );
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

        if ($this->lcp_boundary_script_matches_visible_js_exclusions($handle, $src, $tag, $settings)) {
            return false;
        }

        if ($this->should_native_defer_all_local_script($src, $settings)) {
            return false;
        }

        if ($this->is_js_excluded_by_user_patterns($handle, $src, $tag, '', $settings)) {
            return false;
        }

        if ($this->is_script_user_force_deferred($handle, $src, $tag, $settings)) {
            return false;
        }

        if (!empty($settings['_lcp_boundary_callback_dependency_fragments']) && is_array($settings['_lcp_boundary_callback_dependency_fragments'])) {
            $exclude_fragments = (array) $settings['_lcp_boundary_callback_dependency_fragments'];
            if ($this->script_matches_fragment_list($handle, $src, $exclude_fragments) || $this->lcp_boundary_script_tag_matches_fragments($tag, $exclude_fragments)) {
                return false;
            }
        }

        if ($this->script_handle_has_wp_inline_companion_segments($handle)) {
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

        $core_js_path = function_exists('ultracache_wordpress_includes_public_path') ? ultracache_wordpress_includes_public_path('js/') : '';
        if ('' !== $core_js_path && function_exists('ultracache_public_path_contains') && ultracache_public_path_contains($src_lc, $core_js_path)) {
            return false;
        }

        $woocommerce_path = function_exists('ultracache_plugins_public_path') ? ultracache_plugins_public_path('woocommerce') : '';
        if (('' !== $woocommerce_path && function_exists('ultracache_public_path_contains') && ultracache_public_path_contains($src_lc, $woocommerce_path)) || false !== strpos($src_lc, '/woocommerce/assets/')) {
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
        if (false !== stripos($tag, 'type="text/ultracache-delayed-js"') || false !== stripos($tag, "type='text/ultracache-delayed-js'") || false !== stripos($tag, 'data-ultracache-src=')) {
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

        if ($this->is_script_user_force_deferred($handle, $src, $tag, $settings)) {
            return $this->add_defer_attribute_to_script_tag($tag, true);
        }

        if ($this->should_delay_lcp_boundary_script($handle, $src, $tag, $settings)) {
            return $this->build_delayed_script_tag($tag, $handle, $src, 'lcp-boundary');
        }

        return $tag;
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
        if ($this->has_manual_lcp_selector_configuration()) {
            return $html;
        }

        $has_standard_images = false !== stripos($html, '<img');
        $has_sr7_markup = false !== stripos($html, '<sr7-') || false !== stripos($html, 'sr7-module') || false !== stripos($html, $this->get_revslider_uploads_public_path_marker());
        $manual_configuration = $this->get_effective_manual_lcp_configuration();
        $has_manual_lcp_override = !empty($manual_configuration['images']);
        if (!$has_standard_images && !$has_sr7_markup && !$has_manual_lcp_override) {
            return $html;
        }

        if (!$this->html_tag_processor_available()) {
            return $html;
        }

        return $this->optimize_lcp_image_markup_with_tag_processor($html);
    }

private function set_lcp_marker_on_start_tag($tag, $is_sr7 = false)
    {
        $tag = (string) $tag;
        if ('' === $tag) {
            return $tag;
        }

        $attribute = $is_sr7 ? 'data-ultracache-sr7-lcp' : 'data-ultracache-lcp';
        if (false !== stripos($tag, $attribute . '=')) {
            return $tag;
        }

        return $this->set_or_add_html_tag_attribute($tag, $attribute, '1');
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

            if (null === $best || $this->compare_lcp_candidates_by_score_then_area($candidate, $best) < 0) {
                $best = $candidate;
            }
        }

        $wp_post_image_candidate = $this->find_first_wp_post_image_lcp_candidate($html);
        if ($this->should_prefer_wp_post_image_lcp_candidate($wp_post_image_candidate, $best)) {
            $best = $wp_post_image_candidate;
        }

        return $this->apply_lcp_candidate_optimizations($html, $best);
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
        return is_string($processed) ? $processed : $html;
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

                $marker_attribute = ('SR7-IMG' === $tag || !empty($candidate['is_sr7'])) ? 'data-ultracache-sr7-lcp' : 'data-ultracache-lcp';
                if (null === $processor->get_attribute($marker_attribute)) {
                    $processor->set_attribute($marker_attribute, '1');
                    $changed = true;
                }

                if (!empty($candidate['lcp_reason']) && (string) $processor->get_attribute('data-ultracache-lcp-reason') !== (string) $candidate['lcp_reason']) {
                    $processor->set_attribute('data-ultracache-lcp-reason', (string) $candidate['lcp_reason']);
                    $changed = true;
                }
                if (isset($candidate['score']) && (string) $processor->get_attribute('data-ultracache-lcp-score') !== (string) (int) $candidate['score']) {
                    $processor->set_attribute('data-ultracache-lcp-score', (string) (int) $candidate['score']);
                    $changed = true;
                }

                if (!empty($candidate['is_sr7'])) {
                    if (!empty($candidate['sr7_role']) && (string) $processor->get_attribute('data-ultracache-sr7-role') !== (string) $candidate['sr7_role']) {
                        $processor->set_attribute('data-ultracache-sr7-role', (string) $candidate['sr7_role']);
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
}
