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

                while ($processor->next_tag('LINK')) {
                    $rel = $processor->get_attribute('rel');
                    if (!$this->html_rel_attribute_contains_stylesheet($rel)) {
                        continue;
                    }

                    $stats['scanned']++;

                    $href = $processor->get_attribute('href');
                    $href_for_diag = is_string($href) ? $this->absolutize_public_resource_url($href, home_url('/')) : '';

                    if (null !== $processor->get_attribute('data-ultracache-async-css')) {
                        $stats['skipped']++;
                        $this->add_safe_async_css_diagnostic_item($stats, $href_for_diag, 'skipped', 'already_async');
                        continue;
                    }

                    if (null !== $processor->get_attribute('data-ultracache-async-css-fallback')) {
                        $stats['skipped']++;
                        $this->add_safe_async_css_diagnostic_item($stats, $href_for_diag, 'skipped', 'noscript_fallback');
                        continue;
                    }

                    $is_ultracache_generated_css_link = null !== $processor->get_attribute('data-ultracache-frontpage-css')
                        || null !== $processor->get_attribute('data-ultracache-page-css-bundle')
                        || null !== $processor->get_attribute('data-ultracache-leftover-css-bundle');

                    if (!is_string($href) || '' === $href) {
                        $stats['unresolved']++;
                        $this->add_safe_async_css_diagnostic_item($stats, '', 'unresolved', 'missing_href');
                        continue;
                    }

                    $media = strtolower(trim((string) $processor->get_attribute('media')));

                    if (null !== $processor->get_attribute('disabled') || null !== $processor->get_attribute('onload')) {
                        $stats['skipped']++;
                        $this->add_safe_async_css_diagnostic_item($stats, $href_for_diag, 'skipped', 'already_has_loading_attribute');
                        continue;
                    }

                    $absolute_url = $this->absolutize_public_resource_url($href, home_url('/'));
                    if ('' === $absolute_url) {
                        $stats['unresolved']++;
                        $this->add_safe_async_css_diagnostic_item($stats, '', 'unresolved', 'not_local_css');
                        continue;
                    }

                    $is_external_css = $this->is_external_public_stylesheet_url($absolute_url);
                    if (!$is_external_css && !$this->is_safe_local_public_stylesheet_url($absolute_url)) {
                        $stats['unresolved']++;
                        $this->add_safe_async_css_diagnostic_item($stats, $absolute_url, 'unresolved', 'not_local_css');
                        continue;
                    }

                    $tag_context = $this->build_async_css_processor_tag_context($processor);
                    $decision = $this->get_async_css_stylesheet_decision($absolute_url, $tag_context);

                    if ('' !== $media && 'all' !== $media && (empty($decision['eligible']) || !$is_external_css)) {
                        $stats['skipped']++;
                        $this->add_safe_async_css_diagnostic_item($stats, $href_for_diag, 'skipped', 'non_all_media', $media);
                        continue;
                    }
                    $role = isset($decision['role']) ? (string) $decision['role'] : $this->get_ultracache_generated_stylesheet_role($absolute_url, $tag_context);
                    if ('' !== $role) {
                        $processor->set_attribute('data-ultracache-css-role', $role);
                    }
                    if (empty($decision['eligible'])) {
                        $stats['skipped']++;
                        $reason = isset($decision['reason']) ? (string) $decision['reason'] : 'not_eligible';
                        if ('' !== $role && $this->is_generated_css_blocking_reason($reason)) {
                            $processor->set_attribute('data-ultracache-css-blocking-reason', $this->normalize_css_decision_attribute_value($reason));
                            $changed = true;
                        }
                        $this->add_safe_async_css_diagnostic_item($stats, $absolute_url, 'skipped', $reason, '' !== $role ? ('role=' . $role) : '');
                        continue;
                    }

                    $fallbacks[] = $this->build_async_css_noscript_fallback_link($href, $processor->get_attribute('media'));

                    $target_media = ('' !== $media && 'all' !== $media) ? preg_replace('/[^a-z0-9,\-\s\(\):\.]+/i', '', $media) : 'all';
                    if (!is_string($target_media) || '' === trim($target_media)) {
                        $target_media = 'all';
                    }

                    $processor->set_attribute('media', 'print');
                    $processor->set_attribute('data-ultracache-target-media', $target_media);
                    $processor->set_attribute('data-ultracache-async-css', '1');
                    if (!empty($is_ultracache_generated_css_link) || '' !== $role) {
                        $processor->set_attribute('data-ultracache-generated-css-async', '1');
                    }
                    if ('' !== $role) {
                        $processor->set_attribute('data-ultracache-css-role', $role);
                    }
                    if (method_exists($processor, 'remove_attribute')) {
                        $processor->remove_attribute('data-ultracache-css-blocking-reason');
                    }
                    $processor->set_attribute('data-ultracache-css-async-reason', $this->normalize_css_decision_attribute_value(isset($decision['reason']) ? (string) $decision['reason'] : 'eligible'));
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
                    'html' => $this->append_async_css_noscript_fallbacks_to_head($updated_html, $fallbacks),
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
                'external_enabled' => !empty($settings['async_external_css']),
                'font_mix_bundle_async_enabled' => !empty($settings['font_mix_css_bundle_async']),
                'safe' => true,
                'scanned' => 0,
                'rewritten' => 0,
                'skipped' => 0,
                'unresolved' => 0,
                'reasons' => array(),
                'items' => array(),
            );
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

            // Intentional final HTML optimization output: noscript fallback for an already-present stylesheet made asynchronous by the optimizer.
            // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Rewrites final rendered HTML with a noscript fallback for a stylesheet already printed by WordPress.
            return '<noscript><link ' . $attrs . ' data-ultracache-async-css-fallback="1" /></noscript>';
        }

        private function append_async_css_noscript_fallbacks_to_head($html, array $fallbacks)
        {
            if (empty($fallbacks) || !is_string($html) || '' === $html) {
                return $html;
            }

            $markup = implode("\n", array_values(array_filter(array_map('strval', $fallbacks))));
            if ('' === trim($markup)) {
                return $html;
            }

            return $this->insert_html_before_closing_head($html, $markup);
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

            $tag = $this->set_or_add_html_tag_attribute($tag, 'data-ultracache-css-role', $role);
            if ($async) {
                if ('' !== $reason) {
                    $tag = $this->set_or_add_html_tag_attribute($tag, 'data-ultracache-css-async-reason', $reason);
                }
                return $tag;
            }

            if ('' !== $reason && $this->is_generated_css_blocking_reason($reason)) {
                $tag = $this->set_or_add_html_tag_attribute($tag, 'data-ultracache-css-blocking-reason', $reason);
            }

            return $tag;
        }

        private function build_async_css_processor_tag_context($processor)
        {
            $parts = array();
            foreach (array(
                'id',
                'href',
                'data-ultracache-frontpage-css',
                'data-ultracache-page-css-bundle',
                'data-ultracache-leftover-css-bundle',
                'data-ultracache-delayed-icon-fonts',
                'data-ultracache-css-role',
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

        private function get_async_external_css_exclude_fragments()
        {
            $settings = $this->get_settings();
            $list = isset($settings['async_external_css_exclude_list']) && is_array($settings['async_external_css_exclude_list']) ? $settings['async_external_css_exclude_list'] : array();
            return array_values(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            }));
        }

        private function get_css_same_site_compare_host($url)
        {
            $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
            if (0 === strpos($host, 'www.')) {
                $host = substr($host, 4);
            }
            return $host;
        }

        private function is_external_public_stylesheet_url($url)
        {
            $absolute = $this->absolutize_public_resource_url((string) $url, home_url('/'));
            if ('' === $absolute || 0 === strpos($absolute, 'data:') || 0 === strpos($absolute, 'blob:')) {
                return false;
            }

            $host = $this->get_css_same_site_compare_host($absolute);
            $home_host = $this->get_css_same_site_compare_host(home_url('/'));
            return '' !== $host && '' !== $home_host && $host !== $home_host;
        }

        private function should_async_external_css_win_bundle_for_url($url)
        {
            $settings = $this->get_settings();
            if (empty($settings['async_external_css']) || !$this->is_external_public_stylesheet_url($url)) {
                return false;
            }

            return !$this->should_exclude_stylesheet_url_by_fragments($url, $this->get_async_external_css_exclude_fragments());
        }



        private function get_builtin_homepage_css_bundle_exclude_fragments()
        {
            /*
             * No hidden CSS bundle exclusions. Compatibility exclusions belong
             * in the visible CSS Bundle Exclusions textarea or scanner output.
             */
            return array();
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
            $host = $this->get_css_same_site_compare_host($absolute);
            $home_host = $this->get_css_same_site_compare_host(home_url('/'));
            if ('' === $host || '' === $home_host || $host !== $home_host) {
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

            $markers = array_filter(array(
                function_exists('ultracache_generated_asset_public_path') ? ultracache_generated_asset_public_path('css-bundles') : '',
                function_exists('ultracache_generated_asset_public_path') ? ultracache_generated_asset_public_path('optimized-css') : '',
                function_exists('ultracache_generated_asset_public_path') ? ultracache_generated_asset_public_path('font-css') : '',
            ));

            return function_exists('ultracache_public_path_contains_any') && ultracache_public_path_contains_any($path, $markers);
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

            if (false !== strpos($path, '-delayed-fonts.css') || false !== strpos($path, '/font-css/delayed-') || false !== strpos($tag, 'delayed-icon-fonts') || false !== strpos($tag, 'data-ultracache-css-role="delayed-fonts-css"')) {
                return 'delayed-fonts-css';
            }

            if (false !== strpos($path, 'bundle-font-mix-') || false !== strpos($tag, 'data-ultracache-font-mix-css-bundle=') || false !== strpos($tag, 'data-ultracache-css-role="font-mix-bundle"')) {
                return 'font-mix-bundle';
            }

            if (false !== strpos($path, 'bundle-leftover-') || false !== strpos($tag, 'data-ultracache-leftover-css-bundle=') || false !== strpos($tag, 'data-ultracache-css-role="leftover-bundle"')) {
                return 'leftover-bundle';
            }

            if ((function_exists('ultracache_public_path_contains') && ultracache_public_path_contains($path, ultracache_generated_asset_public_path('optimized-css'))) || false !== strpos($tag, 'data-ultracache-css-role="optimized-css"')) {
                return 'optimized-css';
            }

            if ((function_exists('ultracache_public_path_contains') && ultracache_public_path_contains($path, ultracache_generated_asset_public_path('font-css'))) || false !== strpos($tag, 'data-ultracache-css-role="font-css"')) {
                return 'font-css';
            }

            if (false !== strpos($path, 'bundle-full-') || false !== strpos($tag, 'data-ultracache-css-role="full-bundle"')) {
                return 'full-bundle';
            }

            if (false !== strpos($path, 'bundle-aggressive-') || false !== strpos($tag, 'data-ultracache-css-role="aggressive-bundle"')) {
                return 'aggressive-bundle';
            }

            if (false !== strpos($path, 'bundle-safe-') || false !== strpos($tag, 'data-ultracache-page-css-bundle=') || false !== strpos($tag, 'data-ultracache-frontpage-css=') || false !== strpos($tag, 'data-ultracache-css-role="safe-bundle"')) {
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

                case 'font-mix-bundle':
                    $settings = $this->get_settings();
                    if (!empty($settings['font_mix_css_bundle']) && !empty($settings['font_mix_css_bundle_async'])) {
                        return array('eligible' => true, 'reason' => 'font-mix-bundle-async-enabled', 'role' => $role);
                    }
                    return array('eligible' => false, 'reason' => 'font-mix-bundle-layout-risk', 'role' => $role);

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



private function get_async_css_stylesheet_decision($url, $tag = '')
        {
            $settings = $this->get_settings();
            $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
            if ('' === $path) {
                return array('eligible' => false, 'reason' => 'missing_path');
            }

            if ($this->is_external_public_stylesheet_url($url)) {
                if (empty($settings['async_external_css'])) {
                    return array('eligible' => false, 'reason' => 'async_external_css_disabled');
                }

                $external_exclude_fragments = $this->get_async_external_css_exclude_fragments();
                if ($this->should_exclude_stylesheet_url_by_fragments($url, $external_exclude_fragments)) {
                    return array('eligible' => false, 'reason' => 'async_external_css_excluded');
                }

                /*
                 * External stylesheets may be emitted by themes/plugins after the
                 * enqueue phase, so this final HTML pass is the only reliable place
                 * to make them non-render-blocking without adding synthetic enqueues.
                 */
                return array('eligible' => true, 'reason' => 'async_external_css_applied');
            }

            $async_exclude_fragments = $this->get_async_css_exclude_fragments();
            if ($this->should_exclude_stylesheet_url_by_fragments($url, $async_exclude_fragments)) {
                return array('eligible' => false, 'reason' => 'async_exclude_list');
            }

            $is_generated_css = $this->is_ultracache_generated_stylesheet_url($url);
            if ($is_generated_css) {
                $generated_decision = $this->get_ultracache_generated_stylesheet_async_decision($url, $tag);
                $is_font_mix_bundle_async = !empty($settings['font_mix_css_bundle_async']) && !empty($generated_decision['role']) && 'font-mix-bundle' === (string) $generated_decision['role'];
                if (empty($settings['async_css']) && empty($settings['aggressive_async_css']) && !$is_font_mix_bundle_async) {
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

                // Aggressive Async CSS uses only the visible Async CSS safeguard list.
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
