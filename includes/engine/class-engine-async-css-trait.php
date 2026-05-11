<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Safe async CSS rewrite and stylesheet decision helpers for the engine.
 */
trait Ultra_Cache_Engine_Async_CSS_Trait
{
        private function apply_async_css_links_to_html($html)
        {
            $safe_async_css_result = $this->apply_html_array_rewrite_safely($html, 'async-css-links', function ($html) {
                return $this->rewrite_safe_async_css_links($html);
            });

            if (!is_array($safe_async_css_result)) {
                return $html;
            }

            $stats = isset($safe_async_css_result['stats']) && is_array($safe_async_css_result['stats']) ? $safe_async_css_result['stats'] : $this->get_default_safe_async_css_stats();

            if (!empty($safe_async_css_result['safe'])) {
                $this->record_analytics_safe_async_css($stats);
                $this->record_store_profile_async_css_diagnostics($stats);
                return isset($safe_async_css_result['html']) && is_string($safe_async_css_result['html']) ? $safe_async_css_result['html'] : $html;
            }

            $stats['safe'] = false;
            $this->record_store_profile_async_css_diagnostics($stats);
            return $html;
        }


        private function add_safe_async_css_diagnostic_item(array &$stats, $url, $status, $reason, $detail = '')
        {
            $status = sanitize_key((string) $status);
            $reason = sanitize_key((string) $reason);
            if ('' === $status) {
                $status = 'unknown';
            }
            if ('' === $reason) {
                $reason = 'unknown';
            }

            if (!isset($stats['reasons']) || !is_array($stats['reasons'])) {
                $stats['reasons'] = array();
            }
            if (!isset($stats['reasons'][$reason])) {
                $stats['reasons'][$reason] = 0;
            }
            $stats['reasons'][$reason]++;

            if (!isset($stats['items']) || !is_array($stats['items'])) {
                $stats['items'] = array();
            }
            if (count($stats['items']) >= 80) {
                return;
            }

            $url = is_scalar($url) ? (string) $url : '';
            $stats['items'][] = array(
                'url' => $url,
                'path' => (string) wp_parse_url($url, PHP_URL_PATH),
                'status' => $status,
                'reason' => $reason,
                'detail' => is_scalar($detail) ? (string) $detail : '',
            );
        }
        private function rewrite_safe_async_css_links($html)
        {
            $result = array(
                'html' => $html,
                'stats' => $this->get_default_safe_async_css_stats(),
            );

            if (!is_string($html) || '' === $html || false === stripos($html, '<link')) {
                return $result;
            }

            $processed = $this->rewrite_safe_async_css_links_with_processor($html);
            if (is_array($processed)) {
                return $processed;
            }

            $stats = $this->get_default_safe_async_css_stats();
            $updated_html = (string) preg_replace_callback(
                '/<link\b[^>]*>/i',
                function ($matches) use (&$stats) {
                    return $this->maybe_rewrite_safe_async_css_link_tag((string) $matches[0], $stats);
                },
                $html
            );

            $result['html'] = $updated_html;
            $result['stats'] = $stats;
            return $result;
        }

        private function rewrite_safe_async_css_links_with_processor($html)
        {
            if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || false === stripos($html, '<link')) {
                return null;
            }

            $stats = $this->get_default_safe_async_css_stats();

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

                    $stats['scanned']++;

                    $href = $processor->get_attribute('href');
                    $href_for_diag = is_string($href) ? $this->absolutize_public_resource_url($href, home_url('/')) : '';

                    if (null !== $processor->get_attribute('data-ucwp-async-css')) {
                        $stats['skipped']++;
                        $this->add_safe_async_css_diagnostic_item($stats, $href_for_diag, 'skipped', 'already_async');
                        continue;
                    }

                    $is_ucwp_generated_css_link = null !== $processor->get_attribute('data-ucwp-frontpage-css')
                        || null !== $processor->get_attribute('data-ucwp-page-css-bundle')
                        || null !== $processor->get_attribute('data-ucwp-leftover-css-bundle');

                    if (!is_string($href) || '' === $href) {
                        $stats['unresolved']++;
                        $this->add_safe_async_css_diagnostic_item($stats, '', 'unresolved', 'missing_href');
                        continue;
                    }

                    $media = strtolower(trim((string) $processor->get_attribute('media')));
                    if ('' !== $media && 'all' !== $media) {
                        $stats['skipped']++;
                        $this->add_safe_async_css_diagnostic_item($stats, $href_for_diag, 'skipped', 'non_all_media', $media);
                        continue;
                    }

                    if (null !== $processor->get_attribute('disabled') || null !== $processor->get_attribute('onload')) {
                        $stats['skipped']++;
                        $this->add_safe_async_css_diagnostic_item($stats, $href_for_diag, 'skipped', 'already_has_loading_attribute');
                        continue;
                    }

                    $absolute_url = $this->absolutize_public_resource_url($href, home_url('/'));
                    if ('' === $absolute_url || !$this->is_safe_local_public_stylesheet_url($absolute_url)) {
                        $stats['unresolved']++;
                        $this->add_safe_async_css_diagnostic_item($stats, $absolute_url, 'unresolved', 'not_local_css');
                        continue;
                    }

                    $tag_context = $this->build_async_css_processor_tag_context($processor);
                    $decision = $this->get_async_css_stylesheet_decision($absolute_url, $tag_context);
                    $role = isset($decision['role']) ? (string) $decision['role'] : $this->get_ultracache_generated_stylesheet_role($absolute_url, $tag_context);
                    if ('' !== $role) {
                        $processor->set_attribute('data-ucwp-css-role', $role);
                    }
                    if (empty($decision['eligible'])) {
                        $stats['skipped']++;
                        $reason = isset($decision['reason']) ? (string) $decision['reason'] : 'not_eligible';
                        if ('' !== $role && $this->is_generated_css_blocking_reason($reason)) {
                            $processor->set_attribute('data-ucwp-css-blocking-reason', $this->normalize_css_decision_attribute_value($reason));
                            $changed = true;
                        }
                        $this->add_safe_async_css_diagnostic_item($stats, $absolute_url, 'skipped', $reason, '' !== $role ? ('role=' . $role) : '');
                        continue;
                    }

                    $marker = 'ucwp-safe-async-' . md5($absolute_url . '|' . (++$index));
                    $fallbacks[$marker] = $this->build_async_css_noscript_fallback_link($href, $processor->get_attribute('media'));

                    $processor->set_attribute('media', 'print');
                    $processor->set_attribute('onload', "this.media='all'");
                    $processor->set_attribute('data-ucwp-async-css', '1');
                    if (!empty($is_ucwp_generated_css_link) || '' !== $role) {
                        $processor->set_attribute('data-ucwp-generated-css-async', '1');
                    }
                    if ('' !== $role) {
                        $processor->set_attribute('data-ucwp-css-role', $role);
                    }
                    $processor->set_attribute('data-ucwp-css-async-reason', $this->normalize_css_decision_attribute_value(isset($decision['reason']) ? (string) $decision['reason'] : 'eligible'));
                    $processor->set_attribute('data-ucwp-noscript-token', $marker);

                    $stats['rewritten']++;
                    $this->add_safe_async_css_diagnostic_item($stats, $absolute_url, 'applied', isset($decision['reason']) ? (string) $decision['reason'] : 'eligible', '' !== $role ? ('role=' . $role) : '');
                    $changed = true;
                }

                if (!$changed) {
                    return array('html' => (string) $html, 'stats' => $stats);
                }

                $updated_html = $processor->get_updated_html();
                if (!is_string($updated_html) || '' === $updated_html) {
                    return null;
                }

                return array(
                    'html' => $this->append_async_css_noscript_fallbacks_from_markers($updated_html, $fallbacks),
                    'stats' => $stats,
                );
            } catch (\Throwable $e) {
                return null;
            }
        }

        private function get_default_safe_async_css_stats()
        {
            $settings = $this->get_settings();
            return array(
                'enabled' => !empty($settings['async_css']),
                'aggressive_enabled' => !empty($settings['aggressive_async_css']),
                'safe' => true,
                'scanned' => 0,
                'rewritten' => 0,
                'skipped' => 0,
                'unresolved' => 0,
                'reasons' => array(),
                'items' => array(),
            );
        }

        private function maybe_rewrite_safe_async_css_link_tag($tag, array &$stats)
        {
            $tag = (string) $tag;
            if ('' === $tag) {
                return $tag;
            }

            if (!$this->html_tag_rel_contains_stylesheet($tag)) {
                return $tag;
            }

            $stats['scanned']++;

            $href = $this->extract_attribute_from_html_tag($tag, 'href');
            $absolute_for_diag = '' !== $href ? $this->absolutize_public_resource_url($href, home_url('/')) : '';

            if (false !== stripos($tag, 'data-ucwp-async-css=')) {
                $stats['skipped']++;
                $this->add_safe_async_css_diagnostic_item($stats, $absolute_for_diag, 'skipped', 'already_async');
                return $tag;
            }

            $is_ucwp_generated_css_link = false !== stripos($tag, 'data-ucwp-frontpage-css=')
                || false !== stripos($tag, 'data-ucwp-page-css-bundle=')
                || false !== stripos($tag, 'data-ucwp-leftover-css-bundle=');

            if ('' === $href) {
                $stats['unresolved']++;
                $this->add_safe_async_css_diagnostic_item($stats, '', 'unresolved', 'missing_href');
                return $tag;
            }

            $media = strtolower(trim((string) $this->extract_attribute_from_html_tag($tag, 'media')));
            if ('' !== $media && 'all' !== $media) {
                $stats['skipped']++;
                $this->add_safe_async_css_diagnostic_item($stats, $absolute_for_diag, 'skipped', 'non_all_media', $media);
                return $tag;
            }

            if (preg_match('/\s(?:disabled|onload)\b/i', $tag)) {
                $stats['skipped']++;
                $this->add_safe_async_css_diagnostic_item($stats, $absolute_for_diag, 'skipped', 'already_has_loading_attribute');
                return $tag;
            }

            $absolute_url = $this->absolutize_public_resource_url($href, home_url('/'));
            if ('' === $absolute_url || !$this->is_safe_local_public_stylesheet_url($absolute_url)) {
                $stats['unresolved']++;
                $this->add_safe_async_css_diagnostic_item($stats, $absolute_url, 'unresolved', 'not_local_css');
                return $tag;
            }

            $decision = $this->get_async_css_stylesheet_decision($absolute_url, $tag);
            $role = isset($decision['role']) ? (string) $decision['role'] : $this->get_ultracache_generated_stylesheet_role($absolute_url, $tag);
            if (empty($decision['eligible'])) {
                $stats['skipped']++;
                $reason = isset($decision['reason']) ? (string) $decision['reason'] : 'not_eligible';
                $annotated = $this->annotate_generated_css_link_tag_with_decision($tag, $role, $reason, false);
                $this->add_safe_async_css_diagnostic_item($stats, $absolute_url, 'skipped', $reason, '' !== $role ? ('role=' . $role) : '');
                return $annotated;
            }

            $rewritten = $this->remove_html_tag_attribute($tag, 'media');
            $rewritten = $this->remove_html_tag_attribute($rewritten, 'onload');
            $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'media', 'print');
            $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'onload', "this.media='all'");
            $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'data-ucwp-async-css', '1');
            if (!empty($is_ucwp_generated_css_link) || '' !== $role) {
                $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'data-ucwp-generated-css-async', '1');
            }
            if ('' !== $role) {
                $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'data-ucwp-css-role', $role);
            }
            $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'data-ucwp-css-async-reason', $this->normalize_css_decision_attribute_value(isset($decision['reason']) ? (string) $decision['reason'] : 'eligible'));
            $rewritten .= '<noscript>' . $tag . '</noscript>';

            $stats['rewritten']++;
            $this->add_safe_async_css_diagnostic_item($stats, $absolute_url, 'applied', isset($decision['reason']) ? (string) $decision['reason'] : 'eligible', '' !== $role ? ('role=' . $role) : '');
            return $rewritten;
        }

        private function html_tag_rel_contains_stylesheet($tag)
        {
            return $this->html_rel_attribute_contains_stylesheet($this->extract_attribute_from_html_tag($tag, 'rel'));
        }

        private function html_rel_attribute_contains_stylesheet($rel)
        {
            if (null === $rel || false === $rel) {
                return false;
            }

            $rel = strtolower(trim((string) $rel));
            if ('' === $rel) {
                return false;
            }

            $parts = preg_split('/\s+/', $rel);
            if (!is_array($parts)) {
                return false;
            }

            return in_array('stylesheet', $parts, true) && !in_array('preload', $parts, true) && !in_array('alternate', $parts, true);
        }

        private function build_async_css_noscript_fallback_link($href, $media = null)
        {
            $href = trim((string) $href);
            if ('' === $href) {
                return '';
            }

            $attrs = 'rel="stylesheet" href="' . esc_url($href) . '"';
            $media = is_scalar($media) ? trim((string) $media) : '';
            if ('' !== $media && 'all' !== strtolower($media) && 'print' !== strtolower($media)) {
                $attrs .= ' media="' . esc_attr($media) . '"';
            }

            return '<noscript><link ' . $attrs . ' data-ucwp-async-css-fallback="1" /></noscript>';
        }

        private function append_async_css_noscript_fallbacks_from_markers($html, array $fallbacks)
        {
            if (empty($fallbacks) || !is_string($html) || '' === $html) {
                return $html;
            }

            foreach ($fallbacks as $marker => $fallback) {
                $marker = (string) $marker;
                $fallback = (string) $fallback;
                if ('' === $marker) {
                    continue;
                }

                $pattern = '/<link\b(?=[^>]*\bdata-ucwp-noscript-token=("|\')' . preg_quote($marker, '/') . '\1)[^>]*>/i';
                $updated = preg_replace_callback($pattern, function ($matches) use ($marker, $fallback) {
                    $tag = (string) ($matches[0] ?? '');
                    if ('' === $tag) {
                        return $tag;
                    }

                    $tag = $this->remove_html_tag_attribute($tag, 'data-ucwp-noscript-token');
                    return $tag . $fallback;
                }, $html, 1);

                if (is_string($updated) && '' !== $updated) {
                    $html = $updated;
                }
            }

            return $html;
        }


        private function normalize_css_decision_attribute_value($value)
        {
            $value = strtolower(trim((string) $value));
            if ('' === $value) {
                return '';
            }
            $value = str_replace('_', '-', $value);
            $value = preg_replace('/[^a-z0-9\-]+/', '-', $value);
            $value = trim((string) $value, '-_');
            return '' !== $value ? $value : '';
        }

        private function is_generated_css_blocking_reason($reason)
        {
            $reason = $this->normalize_css_decision_attribute_value($reason);
            return in_array($reason, array(
                'main-layout-risk',
                'optimized-css-layout-risk',
                'font-css-text-metric-risk',
                'generated-css-unclassified',
                'async-css-disabled',
                'async-exclude-list',
                'aggressive-async-exclude-list',
            ), true);
        }

        private function annotate_generated_css_link_tag_with_decision($tag, $role, $reason, $async = false)
        {
            $tag = (string) $tag;
            $role = $this->normalize_css_decision_attribute_value($role);
            $reason = $this->normalize_css_decision_attribute_value($reason);
            if ('' === $tag || '' === $role) {
                return $tag;
            }

            $tag = $this->set_or_add_html_tag_attribute($tag, 'data-ucwp-css-role', $role);
            if ($async) {
                if ('' !== $reason) {
                    $tag = $this->set_or_add_html_tag_attribute($tag, 'data-ucwp-css-async-reason', $reason);
                }
                return $tag;
            }

            if ('' !== $reason && $this->is_generated_css_blocking_reason($reason)) {
                $tag = $this->set_or_add_html_tag_attribute($tag, 'data-ucwp-css-blocking-reason', $reason);
            }

            return $tag;
        }

        private function build_async_css_processor_tag_context($processor)
        {
            $parts = array();
            foreach (array(
                'id',
                'href',
                'data-ucwp-frontpage-css',
                'data-ucwp-page-css-bundle',
                'data-ucwp-leftover-css-bundle',
                'data-ucwp-delayed-icon-fonts',
                'data-ucwp-css-role',
            ) as $attribute) {
                $value = $processor->get_attribute($attribute);
                if (null !== $value && false !== $value) {
                    $parts[] = $attribute . '="' . (is_scalar($value) ? (string) $value : '1') . '"';
                }
            }

            return implode(' ', $parts);
        }

        private function should_exclude_stylesheet_url_by_fragments($url, array $fragments)
        {
            $haystacks = array(
                strtolower((string) $url),
                strtolower((string) wp_parse_url((string) $url, PHP_URL_PATH)),
            );

            foreach ($fragments as $fragment) {
                $fragment = strtolower(trim((string) $fragment));
                if ('' === $fragment) {
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

        private function get_async_css_exclude_fragments()
        {
            $settings = $this->get_settings();
            $list = isset($settings['async_css_exclude_list']) && is_array($settings['async_css_exclude_list']) ? $settings['async_css_exclude_list'] : array();
            return array_values(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            }));
        }

        private function get_aggressive_async_css_exclude_fragments()
        {
            // Aggressive Async CSS now uses the same visible Async CSS Exclude List.
            return $this->get_async_css_exclude_fragments();
        }


        private function get_builtin_homepage_css_bundle_exclude_fragments()
        {
            return array(
                // Keep fragile slider/hero runtime CSS outside generated bundles. These files often
                // coordinate with JS initialization order and should remain explicit stylesheet links.
                'revslider',
                'slider-revolution',
                'revolution',
                'sr7',
                'rs7',
                'rs6',
                'tptools',
                'tp-tools',
                'rs-module',
                'swiper',
                'slick',
                'splide',
                'owl.carousel',
                'smartslider',
                'n2-ss',
                'layerslider',
                'metaslider',
            );
        }

        private function get_homepage_css_bundle_exclude_fragments()
        {
            $settings = $this->get_settings();
            $list = isset($settings['homepage_css_bundle_exclude_list']) && is_array($settings['homepage_css_bundle_exclude_list'])
                ? $settings['homepage_css_bundle_exclude_list']
                : array();

            $list = array_merge($this->get_builtin_homepage_css_bundle_exclude_fragments(), (array) $list);

            return array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function is_safe_local_public_stylesheet_url($url)
        {
            $url = $this->normalize_public_resource_url($url);
            if ('' === $url || 0 === strpos($url, 'data:') || 0 === strpos($url, 'blob:')) {
                return false;
            }

            $absolute = $this->absolutize_public_resource_url($url, home_url('/'));
            $host = (string) wp_parse_url($absolute, PHP_URL_HOST);
            $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            if ('' === $host || '' === $home_host || strtolower($host) !== strtolower($home_host)) {
                return false;
            }

            $path = (string) wp_parse_url($absolute, PHP_URL_PATH);
            if ('' === $path) {
                return false;
            }

            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            return 'css' === $extension;
        }

        private function is_ultracache_generated_stylesheet_url($url)
        {
            $path = strtolower((string) wp_parse_url((string) $url, PHP_URL_PATH));
            if ('' === $path || 'css' !== strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
                return false;
            }

            return false !== strpos($path, '/wp-content/cache/ultracache/css-bundles/')
                || false !== strpos($path, '/wp-content/cache/ultracache/optimized-css/')
                || false !== strpos($path, '/wp-content/cache/ultracache/font-css/');
        }

        private function get_generated_css_bundle_role_from_mode($mode)
        {
            $mode = strtolower(trim((string) $mode));
            if ('leftover' === $mode) {
                return 'leftover-bundle';
            }
            if ('aggressive' === $mode) {
                return 'aggressive-bundle';
            }
            if ('full' === $mode) {
                return 'full-bundle';
            }
            return 'safe-bundle';
        }

        private function get_ultracache_generated_stylesheet_role($url, $tag = '')
        {
            $path = strtolower((string) wp_parse_url((string) $url, PHP_URL_PATH));
            $tag = strtolower((string) $tag);

            if (false !== strpos($path, '-delayed-fonts.css') || false !== strpos($path, '/font-css/delayed-') || false !== strpos($tag, 'delayed-icon-fonts') || false !== strpos($tag, 'data-ucwp-css-role="delayed-fonts-css"')) {
                return 'delayed-fonts-css';
            }

            if (false !== strpos($path, 'bundle-leftover-') || false !== strpos($tag, 'data-ucwp-leftover-css-bundle=') || false !== strpos($tag, 'data-ucwp-css-role="leftover-bundle"')) {
                return 'leftover-bundle';
            }

            if (false !== strpos($path, '/wp-content/cache/ultracache/optimized-css/') || false !== strpos($tag, 'data-ucwp-css-role="optimized-css"')) {
                return 'optimized-css';
            }

            if (false !== strpos($path, '/wp-content/cache/ultracache/font-css/') || false !== strpos($tag, 'data-ucwp-css-role="font-css"')) {
                return 'font-css';
            }

            if (false !== strpos($path, 'bundle-full-') || false !== strpos($tag, 'data-ucwp-css-role="full-bundle"')) {
                return 'full-bundle';
            }

            if (false !== strpos($path, 'bundle-aggressive-') || false !== strpos($tag, 'data-ucwp-css-role="aggressive-bundle"')) {
                return 'aggressive-bundle';
            }

            if (false !== strpos($path, 'bundle-safe-') || false !== strpos($tag, 'data-ucwp-page-css-bundle=') || false !== strpos($tag, 'data-ucwp-frontpage-css=') || false !== strpos($tag, 'data-ucwp-css-role="safe-bundle"')) {
                return 'safe-bundle';
            }

            if ($this->is_ultracache_generated_stylesheet_url($url)) {
                return 'generated-css';
            }

            return '';
        }

        private function get_ultracache_generated_stylesheet_async_decision($url, $tag = '')
        {
            $role = $this->get_ultracache_generated_stylesheet_role($url, $tag);
            switch ($role) {
                case 'leftover-bundle':
                    return array('eligible' => true, 'reason' => 'leftover-noncritical', 'role' => $role);

                case 'delayed-fonts-css':
                    return array('eligible' => true, 'reason' => 'delayed-fonts', 'role' => $role);

                case 'safe-bundle':
                case 'aggressive-bundle':
                case 'full-bundle':
                    return array('eligible' => false, 'reason' => 'main-layout-risk', 'role' => $role);

                case 'optimized-css':
                    return array('eligible' => false, 'reason' => 'optimized-css-layout-risk', 'role' => $role);

                case 'font-css':
                    return array('eligible' => false, 'reason' => 'font-css-text-metric-risk', 'role' => $role);

                case 'generated-css':
                    return array('eligible' => false, 'reason' => 'generated-css-unclassified', 'role' => $role);
            }

            return array('eligible' => false, 'reason' => 'not_generated_css');
        }


        private function should_async_css_stylesheet_url($url, $tag = '')
        {
            $decision = $this->get_async_css_stylesheet_decision($url, $tag);
            return !empty($decision['eligible']);
        }

        private function get_async_css_stylesheet_decision($url, $tag = '')
        {
            $settings = $this->get_settings();
            $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
            if ('' === $path) {
                return array('eligible' => false, 'reason' => 'missing_path');
            }

            $async_exclude_fragments = $this->get_async_css_exclude_fragments();
            if ($this->should_exclude_stylesheet_url_by_fragments($url, $async_exclude_fragments)) {
                return array('eligible' => false, 'reason' => 'async_exclude_list');
            }

            $is_generated_css = $this->is_ultracache_generated_stylesheet_url($url);
            if ($is_generated_css) {
                $generated_decision = $this->get_ultracache_generated_stylesheet_async_decision($url, $tag);
                if (empty($settings['async_css']) && empty($settings['aggressive_async_css'])) {
                    $generated_decision['eligible'] = false;
                    $generated_decision['reason'] = 'async-css-disabled';
                }
                return $generated_decision;
            }

            $aggressive_enabled = !empty($settings['aggressive_async_css']);
            if ($aggressive_enabled) {
                $aggressive_exclude_fragments = $this->get_aggressive_async_css_exclude_fragments();
                if ($this->should_exclude_stylesheet_url_by_fragments($url, $aggressive_exclude_fragments)) {
                    return array('eligible' => false, 'reason' => 'aggressive_async_exclude_list');
                }

                /*
                 * Aggressive Async CSS means almost all remaining local stylesheet
                 * links are eligible. CSS Bundle Exclusions must not silently
                 * disable this pass; use the visible Async CSS
                 * Exclude List for styles that must remain blocking.
                 */
                $hard_block_patterns = array(
                    '/dashicons/i',
                    '/admin-bar/i',
                    '/\/wp-admin\//i',
                );

                foreach ($hard_block_patterns as $pattern) {
                    if (preg_match($pattern, $path) || preg_match($pattern, (string) $tag)) {
                        return array('eligible' => false, 'reason' => 'hard_admin_asset');
                    }
                }

                return array('eligible' => true, 'reason' => 'aggressive_async_css_enabled');
            }

            $critical_patterns = array(
                'theme_layout' => array('/\/themes\//'),
                'woocommerce_layout' => array('/\/woocommerce\//'),
                'elementor_layout' => array('/\/elementor\//', '/\/elementor-pro\//', '/\/header-footer-elementor\.css$/', '/\/widgets-css\//', '/\/post-\d+\.css$/', '/\/base\/elementor\.css$/'),
                'font_icon_css' => array('/\/fontawesome(?:\.min)?\.css$/', '/\/(?:solid|brands|regular|all)(?:\.min)?\.css$/', '/\/elementor-icons(?:-shared-0)?(?:\.min)?\.css$/', '/\/eicons(?:\.min)?\.css$/', '/\/manrope\.css$/', '/\/fraunces\.css$/'),
            );

            foreach ($critical_patterns as $reason => $patterns) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $path)) {
                        return array('eligible' => false, 'reason' => 'low_risk_skipped_' . $reason);
                    }
                }
            }

            $safe_patterns = array(
                '/e-animation/i',
                '/(?:^|[\/_.-])animate(?:[\/_.-]|$)/i',
                '/fadein/i',
                '/magnific-popup/i',
                '/tooltipster/i',
                '/plainoverlay/i',
                '/perfect-scrollbar/i',
                '/easy-autocomplete/i',
            );

            foreach ($safe_patterns as $pattern) {
                if (preg_match($pattern, $path)) {
                    return array('eligible' => true, 'reason' => 'low_risk_safe_pattern');
                }
            }

            return array('eligible' => false, 'reason' => 'low_risk_no_safe_pattern');
        }

































}
