<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!trait_exists('Ultra_Cache_Engine_JS_Optimization_Trait')) {
    trait Ultra_Cache_Engine_JS_Optimization_Trait
    {
        private function get_script_critical_request_candidate($tag, $offset, $head_end, array $settings = array())
        {
            $tag = (string) $tag;
            $delayed = (false !== stripos($tag, 'type="text/ucwp-delayed-js"') || false !== stripos($tag, "type='text/ucwp-delayed-js'") || false !== stripos($tag, 'data-ucwp-src='));
            $src = $delayed ? (string) $this->extract_attribute_from_html_tag($tag, 'data-ucwp-src') : (string) $this->extract_attribute_from_html_tag($tag, 'src');
            $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5);
            if ('' === $src) {
                return array();
            }

            $handle = (string) $this->extract_attribute_from_html_tag($tag, 'data-ucwp-handle');
            if ('' === $handle) {
                $handle = (string) $this->extract_attribute_from_html_tag($tag, 'id');
                $handle = preg_replace('/-js(?:-extra)?$/', '', $handle);
            }
            $handle = is_string($handle) ? $handle : '';

            $location = $this->get_html_offset_location($offset, $head_end);
            $has_async = $this->html_tag_has_attribute($tag, 'async');
            $has_defer = $this->html_tag_has_attribute($tag, 'defer');
            $is_module = (false !== stripos($tag, 'type="module"') || false !== stripos($tag, "type='module'"));
            $render_blocking = (!$delayed && 'head' === $location && !$has_async && !$has_defer && !$is_module && $this->is_delayable_external_script_tag($tag));
            $origin = $this->get_public_resource_origin_type($src);
            $path = $this->get_public_resource_path_fragment($src);
            $bytes = 0;
            $local_path = $this->resolve_local_path_from_public_url($src);
            if ('' !== $local_path && is_readable($local_path)) {
                $bytes = (int) filesize($local_path);
            }

            $protected = false;
            $protected_reason = '';
            $status = $render_blocking ? 'render-blocking' : 'non-blocking';
            $reason = $render_blocking ? 'head script without async/defer/delay marker' : 'not a parser-blocking head script';
            $suggested = 'Review before changing.';

            if ($delayed) {
                $status = 'delayed';
                $reason = 'already delayed by UltraCache';
                $suggested = 'No action needed unless the delayed script is needed before interaction.';
            } elseif ($has_defer) {
                $status = 'deferred';
                $reason = 'defer attribute present';
                $suggested = 'Already out of the parser-blocking path.';
            } elseif ($has_async) {
                $status = 'async';
                $reason = 'async attribute present';
                $suggested = 'Already out of the parser-blocking path.';
            }

            if (!$delayed) {
                $slider_fragment = !empty($settings['slider_safe_mode']) ? $this->get_matching_fragment($handle, $src, $tag, $this->get_slider_hero_protected_fragments()) : '';
                if ('' !== $slider_fragment) {
                    $protected = true;
                    $protected_reason = 'slider/hero runtime fragment: ' . $slider_fragment;
                    $reason = $protected_reason;
                    $suggested = 'Keep protected while Fix sliders / hero sections is enabled and the slider is above the fold.';
                } elseif ($this->is_script_user_defer_excluded($handle, $src, $settings)) {
                    $protected = true;
                    $protected_reason = 'user-visible defer/delay exclusion matched';
                    $reason = $protected_reason;
                    $suggested = 'Review the visible exclusion list before changing.';
                } elseif ($this->is_script_safe_stage_excluded($handle, $src, $tag, $settings)) {
                    $protected = true;
                    $protected_reason = 'safe-stage protected dependency';
                    $reason = $protected_reason;
                    $suggested = 'Candidate only for a focused dependency-safe defer test.';
                } elseif ($this->is_script_force_blocking($handle, $src, $tag, $settings)) {
                    $protected = true;
                    $protected_reason = 'force-blocking dependency/core/WooCommerce/Elementor rule';
                    $reason = $protected_reason;
                    $suggested = 'Keep blocking unless a dedicated dependency-safe mode is tested.';
                } elseif ($render_blocking && !empty($settings['lcp_boundary_defer']) && $this->matches_non_critical_delay_patterns($handle, $src, $tag)) {
                    $suggested = 'Candidate for LCP Boundary Defer / critical-chain relief after visual testing.';
                } elseif ($render_blocking) {
                    $suggested = 'Candidate for defer/delay only after dependency checks.';
                }
            }

            return array(
                'type' => 'script',
                'url' => $src,
                'path' => $path,
                'handle' => $handle,
                'origin' => $origin,
                'location' => $location,
                'renderBlocking' => (bool) $render_blocking,
                'delayed' => (bool) $delayed,
                'protected' => (bool) $protected,
                'protectedReason' => $protected_reason,
                'status' => $status,
                'reason' => $reason,
                'suggestedAction' => $suggested,
                'bytes' => $bytes,
            );
        }

        private function get_delay_safety_suggested_exclusion_from_url($url)
        {
            $path = (string) wp_parse_url($this->normalize_public_resource_url((string) $url), PHP_URL_PATH);
            $path = rawurldecode($path);
            $path = trim($path);
            if ('' === $path) {
                return trim((string) $url);
            }

            $markers = array(
                '/wp-content/plugins/' => 'plugin',
                '/wp-content/themes/'  => 'theme',
                '/wp-includes/js/'     => 'core',
            );

            foreach ($markers as $marker => $type) {
                $pos = stripos($path, $marker);
                if (false !== $pos) {
                    return ltrim(substr($path, $pos + strlen($marker)), '/');
                }
            }

            return ltrim($path, '/');
        }

        private function delay_safety_exclusion_already_matches($suggestion, array $settings = array())
        {
            $suggestion = strtolower(trim((string) $suggestion));
            if ('' === $suggestion) {
                return false;
            }

            $lists = array();
            if (isset($settings['defer_js_exclude_list']) && is_array($settings['defer_js_exclude_list'])) {
                $lists = array_merge($lists, $settings['defer_js_exclude_list']);
            }
            if (isset($settings['delay_non_critical_js_exclude_list']) && is_array($settings['delay_non_critical_js_exclude_list'])) {
                $lists = array_merge($lists, $settings['delay_non_critical_js_exclude_list']);
            }

            foreach ($lists as $line) {
                $line = strtolower(trim((string) $line));
                if ('' === $line) {
                    continue;
                }
                if ($line === $suggestion || false !== strpos($suggestion, $line) || false !== strpos($line, $suggestion)) {
                    return true;
                }
            }

            return false;
        }

        private function is_js_delay_safety_common_symbol($symbol)
        {
            $symbol = (string) $symbol;
            if ('' === $symbol) {
                return true;
            }

            static $common = null;
            if (null === $common) {
                $common = array_fill_keys(array(
                    'if','for','while','switch','return','function','var','let','const','new','this','true','false','null','undefined','window','document','console','Math','Date','Array','Object','String','Number','Boolean','Promise','fetch','setTimeout','setInterval','clearTimeout','clearInterval','addEventListener','removeEventListener','querySelector','querySelectorAll','getElementById','URLSearchParams','FormData','Event','CustomEvent','JSON','parseInt','parseFloat','isNaN','typeof','decodeURIComponent','encodeURIComponent','jQuery','$'
                ), true);
            }

            return isset($common[$symbol]);
        }

        private function is_js_delay_safety_meaningful_symbol($symbol, $source = 'inline-script')
        {
            $symbol = trim((string) $symbol);
            $source = (string) $source;
            if ('' === $symbol || $this->is_js_delay_safety_common_symbol($symbol)) {
                return false;
            }

            if ('inline-event-handler' === $source) {
                return strlen($symbol) >= 3;
            }

            $allowed_lowercase_globals = array_fill_keys(array(
                'messages','dataLayer','grecaptcha','gtag','fbq','ml'
            ), true);

            if (isset($allowed_lowercase_globals[$symbol])) {
                return true;
            }

            if (strlen($symbol) < 4) {
                return false;
            }

            if (preg_match('/[A-Z_]/', $symbol)) {
                return true;
            }

            return false;
        }

        private function get_js_delay_safety_declared_symbols_from_inline_code($code)
        {
            $code = (string) $code;
            $declared = array();
            $patterns = array(
                '/\b(?:var|let|const)\s+([A-Za-z_$][A-Za-z0-9_$]*)\b/',
                '/\bfunction\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/',
                '/\b(?:function)?\s*\(([^)]*)\)\s*=>/',
                '/\bfunction\b[^\(]*\(([^)]*)\)/',
            );

            foreach ($patterns as $index => $pattern) {
                if (!preg_match_all($pattern, $code, $matches)) {
                    continue;
                }
                foreach ((array) ($matches[1] ?? array()) as $match) {
                    if ($index >= 2) {
                        foreach (explode(',', (string) $match) as $param) {
                            $param = trim((string) preg_replace('/[^A-Za-z0-9_$].*$/', '', trim($param)));
                            if ('' !== $param) {
                                $declared[$param] = true;
                            }
                        }
                    } else {
                        $declared[(string) $match] = true;
                    }
                }
            }

            return $declared;
        }

        private function collect_js_delay_safety_inline_symbols($html)
        {
            $html = is_string($html) ? $html : (string) $html;
            $symbols = array();

            if (preg_match_all('/\son[a-z]+\s*=\s*(["\'])(.*?)\1/is', $html, $handlers, PREG_SET_ORDER)) {
                foreach ($handlers as $handler) {
                    $code = html_entity_decode((string) ($handler[2] ?? ''), ENT_QUOTES | ENT_HTML5);
                    if (preg_match_all('/\b([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $code, $calls)) {
                        foreach ((array) ($calls[1] ?? array()) as $symbol) {
                            $symbol = (string) $symbol;
                            if (!$this->is_js_delay_safety_meaningful_symbol($symbol, 'inline-event-handler')) {
                                continue;
                            }
                            $symbols[$symbol] = array(
                                'symbol' => $symbol,
                                'source' => 'inline-event-handler',
                                'sample' => function_exists('mb_substr') ? mb_substr(trim($code), 0, 220) : substr(trim($code), 0, 220),
                            );
                        }
                    }
                }
            }

            if (preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $html, $scripts, PREG_SET_ORDER)) {
                foreach ($scripts as $script) {
                    $attrs = (string) ($script[1] ?? '');
                    $code = (string) ($script[2] ?? '');
                    $trimmed_code = trim($code);
                    if ('' === $trimmed_code) {
                        continue;
                    }
                    if (false !== stripos($attrs, 'src=') || false !== stripos($attrs, 'data-ucwp-src=') || false !== stripos($attrs, 'text/ucwp-delayed-js') || false !== stripos($attrs, 'application/ld+json') || false !== stripos($attrs, 'speculationrules')) {
                        continue;
                    }
                    if (false !== stripos($trimmed_code, '__ucwpDelayLoader') || false !== stripos($trimmed_code, 'text/ucwp-delayed-js') || false !== stripos($trimmed_code, 'gtm.start') || false !== stripos($trimmed_code, 'googletagmanager.com/gtm.js') || false !== stripos($trimmed_code, 'wp-emoji-settings') || false !== stripos($trimmed_code, '_wpemojiSettings')) {
                        continue;
                    }

                    $declared = $this->get_js_delay_safety_declared_symbols_from_inline_code($trimmed_code);
                    $refs = array();
                    if (preg_match_all('/\b([A-Za-z_$][A-Za-z0-9_$]*)\s*(?:\[|\.)/m', $trimmed_code, $matches)) {
                        foreach ((array) ($matches[1] ?? array()) as $symbol) {
                            $refs[(string) $symbol] = true;
                        }
                    }
                    if (preg_match_all('/\bwindow\.([A-Za-z_$][A-Za-z0-9_$]*)\b/m', $trimmed_code, $matches)) {
                        foreach ((array) ($matches[1] ?? array()) as $symbol) {
                            $refs[(string) $symbol] = true;
                        }
                    }

                    foreach (array_keys($refs) as $symbol) {
                        if (isset($declared[$symbol]) || !$this->is_js_delay_safety_meaningful_symbol($symbol, 'inline-script')) {
                            continue;
                        }
                        if (!isset($symbols[$symbol])) {
                            $sample = trim((string) preg_replace('/\s+/', ' ', $trimmed_code));
                            $symbols[$symbol] = array(
                                'symbol' => $symbol,
                                'source' => 'inline-script',
                                'sample' => function_exists('mb_substr') ? mb_substr($sample, 0, 220) : substr($sample, 0, 220),
                            );
                        }
                    }
                }
            }

            return $symbols;
        }

        private function collect_js_delay_safety_delayed_definitions($html)
        {
            $html = is_string($html) ? $html : (string) $html;
            $definitions = array();

            if (!preg_match_all('/<script\b[^>]*(?:type\s*=\s*["\']text\/ucwp-delayed-js["\']|data-ucwp-src\s*=)[^>]*>/i', $html, $matches)) {
                return $definitions;
            }

            foreach ((array) ($matches[0] ?? array()) as $tag) {
                $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag, 'data-ucwp-src'), ENT_QUOTES | ENT_HTML5);
                if ('' === $src) {
                    $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag, 'src'), ENT_QUOTES | ENT_HTML5);
                }
                if ('' === $src) {
                    continue;
                }

                $local_path = $this->resolve_local_path_from_public_url($src);
                if ('' === $local_path || !is_readable($local_path) || (int) @filesize($local_path) > 1048576) {
                    continue;
                }

                $content = ucwp_guarded_asset_file_get_contents($local_path, 'js', 'js_delay_safety_local_asset', true);
                if (!is_string($content) || '' === $content) {
                    continue;
                }

                $handle = (string) $this->extract_attribute_from_html_tag($tag, 'data-ucwp-handle');
                $suggestion = $this->get_delay_safety_suggested_exclusion_from_url($src);
                $symbols = array();

                $patterns = array(
                    '/\bfunction\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/',
                    '/\b(?:var|let|const)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=/',
                    '/\bwindow\.([A-Za-z_$][A-Za-z0-9_$]*)\s*=/',
                    '/\bglobalThis\.([A-Za-z_$][A-Za-z0-9_$]*)\s*=/',
                    '/(?:^|[;\s])([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*function\b/',
                );

                foreach ($patterns as $pattern) {
                    if (preg_match_all($pattern, $content, $found)) {
                        foreach ((array) ($found[1] ?? array()) as $symbol) {
                            $symbol = (string) $symbol;
                            if ($this->is_js_delay_safety_meaningful_symbol($symbol, 'inline-script')) {
                                $symbols[$symbol] = true;
                            }
                        }
                    }
                }

                foreach (array_keys($symbols) as $symbol) {
                    if (!isset($definitions[$symbol])) {
                        $definitions[$symbol] = array();
                    }
                    $definitions[$symbol][] = array(
                        'symbol' => $symbol,
                        'url' => $src,
                        'handle' => $handle,
                        'localPath' => $local_path,
                        'suggestedExclusion' => $suggestion,
                    );
                }
            }

            return $definitions;
        }

        private function collect_js_delay_safety_delayed_script_records($html)
        {
            $html = is_string($html) ? $html : (string) $html;
            $records = array();

            if (!preg_match_all('/<script\b[^>]*(?:type\s*=\s*["\']text\/ucwp-delayed-js["\']|data-ucwp-src\s*=)[^>]*>/i', $html, $matches)) {
                return $records;
            }

            foreach ((array) ($matches[0] ?? array()) as $tag) {
                $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag, 'data-ucwp-src'), ENT_QUOTES | ENT_HTML5);
                if ('' === $src) {
                    $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag, 'src'), ENT_QUOTES | ENT_HTML5);
                }
                if ('' === $src) {
                    continue;
                }

                $local_path = $this->resolve_local_path_from_public_url($src);
                if ('' === $local_path || !is_readable($local_path) || (int) @filesize($local_path) > 1048576) {
                    continue;
                }

                $content = ucwp_guarded_asset_file_get_contents($local_path, 'js', 'js_delay_safety_local_asset', true);
                if (!is_string($content) || '' === $content) {
                    continue;
                }

                $records[] = array(
                    'url' => $src,
                    'handle' => (string) $this->extract_attribute_from_html_tag($tag, 'data-ucwp-handle'),
                    'localPath' => $local_path,
                    'suggestedExclusion' => $this->get_delay_safety_suggested_exclusion_from_url($src),
                    'content' => $content,
                );
            }

            return $records;
        }

        private function delayed_script_record_defines_symbol(array $record, $symbol)
        {
            $symbol = (string) $symbol;
            if ('' === $symbol || empty($record['content']) || !is_string($record['content'])) {
                return false;
            }

            $quoted = preg_quote($symbol, '/');
            $patterns = array(
                '/\bfunction\s+' . $quoted . '\s*\(/',
                '/\b(?:var|let|const)\s+' . $quoted . '\s*=/',
                '/\bwindow\.' . $quoted . '\s*=/',
                '/\bglobalThis\.' . $quoted . '\s*=/',
                '/(?:^|[;\s])' . $quoted . '\s*=\s*function\b/',
            );

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $record['content'])) {
                    return true;
                }
            }

            return false;
        }

        private function add_js_delay_safety_suggestion(&$suggestions, &$seen, $symbol, $source, $sample, array $definition, array $settings)
        {
            $symbol = (string) $symbol;
            $source = (string) $source;
            $suggestion = (string) ($definition['suggestedExclusion'] ?? '');
            if ('' === trim($suggestion) || !$this->is_js_delay_safety_meaningful_symbol($symbol, $source)) {
                return;
            }

            $key = strtolower($symbol . '|' . $suggestion);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;

            $reason = 'inline-event-handler' === $source
                ? 'Inline event handler calls ' . $symbol . '(), but the script that defines it is delayed.'
                : 'Inline script references global "' . $symbol . '", but the script that defines it is delayed.';
            $already = $this->delay_safety_exclusion_already_matches($suggestion, $settings);

            $suggestions[] = array(
                'symbol' => $symbol,
                'source' => $source,
                'sample' => (string) $sample,
                'definingScriptUrl' => (string) ($definition['url'] ?? ''),
                'definingHandle' => (string) ($definition['handle'] ?? ''),
                'suggestedExclusion' => $suggestion,
                'confidence' => 'high',
                'reason' => $reason,
                'alreadyExcluded' => (bool) $already,
            );
        }

        private function collect_js_delay_safety_targeted_suggestions($html, array $settings = array())
        {
            $html = is_string($html) ? $html : (string) $html;
            $records = $this->collect_js_delay_safety_delayed_script_records($html);
            $suggestions = array();
            $seen = array();

            if (empty($records)) {
                return $suggestions;
            }

            if (preg_match_all('/\son[a-z]+\s*=\s*(["\'])(.*?)\1/is', $html, $handlers, PREG_SET_ORDER)) {
                foreach ($handlers as $handler) {
                    $code = html_entity_decode((string) ($handler[2] ?? ''), ENT_QUOTES | ENT_HTML5);
                    if (!preg_match_all('/\b([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $code, $calls)) {
                        continue;
                    }
                    foreach ((array) ($calls[1] ?? array()) as $symbol) {
                        $symbol = (string) $symbol;
                        if (!$this->is_js_delay_safety_meaningful_symbol($symbol, 'inline-event-handler')) {
                            continue;
                        }
                        foreach ($records as $record) {
                            if (!$this->delayed_script_record_defines_symbol($record, $symbol)) {
                                continue;
                            }
                            $sample = function_exists('mb_substr') ? mb_substr(trim($code), 0, 220) : substr(trim($code), 0, 220);
                            $this->add_js_delay_safety_suggestion($suggestions, $seen, $symbol, 'inline-event-handler', $sample, $record, $settings);
                        }
                    }
                }
            }

            $allowed_globals = array('messages');
            if (preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $html, $scripts, PREG_SET_ORDER)) {
                foreach ($scripts as $script) {
                    $attrs = (string) ($script[1] ?? '');
                    $code = (string) ($script[2] ?? '');
                    $trimmed_code = trim($code);
                    if ('' === $trimmed_code) {
                        continue;
                    }
                    if (false !== stripos($attrs, 'src=') || false !== stripos($attrs, 'data-ucwp-src=') || false !== stripos($attrs, 'text/ucwp-delayed-js') || false !== stripos($attrs, 'application/ld+json') || false !== stripos($attrs, 'speculationrules')) {
                        continue;
                    }
                    if (false !== stripos($trimmed_code, '__ucwpDelayLoader') || false !== stripos($trimmed_code, 'text/ucwp-delayed-js') || false !== stripos($trimmed_code, 'gtm.start') || false !== stripos($trimmed_code, 'googletagmanager.com/gtm.js') || false !== stripos($trimmed_code, 'wp-emoji-settings') || false !== stripos($trimmed_code, '_wpemojiSettings')) {
                        continue;
                    }

                    foreach ($allowed_globals as $symbol) {
                        if (!preg_match('/\b' . preg_quote($symbol, '/') . '\s*(?:\[|\.|\b)/', $trimmed_code)) {
                            continue;
                        }
                        foreach ($records as $record) {
                            if (!$this->delayed_script_record_defines_symbol($record, $symbol)) {
                                continue;
                            }
                            $sample = trim((string) preg_replace('/\s+/', ' ', $trimmed_code));
                            $sample = function_exists('mb_substr') ? mb_substr($sample, 0, 220) : substr($sample, 0, 220);
                            $this->add_js_delay_safety_suggestion($suggestions, $seen, $symbol, 'inline-script', $sample, $record, $settings);
                        }
                    }
                }
            }

            return $suggestions;
        }

        private function add_js_delay_component_recommendation(&$suggestions, &$seen, $suggestion, $category, $category_label, $reason, array $settings, $confidence = 'recommended', $sample = '', $appendable = true)
        {
            $suggestion = trim((string) $suggestion);
            if ('' === $suggestion) {
                return;
            }

            $category = trim((string) $category);
            if ('' === $category) {
                $category = 'detected-component-protection';
            }

            $key = strtolower($category . '|' . $suggestion);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;

            $already = $this->delay_safety_exclusion_already_matches($suggestion, $settings);
            $suggestions[] = array(
                'symbol' => $suggestion,
                'source' => $category,
                'category' => $category,
                'categoryLabel' => (string) $category_label,
                'sample' => (string) $sample,
                'definingScriptUrl' => '',
                'definingHandle' => '',
                'suggestedExclusion' => $suggestion,
                'confidence' => (string) $confidence,
                'reason' => (string) $reason,
                'alreadyExcluded' => (bool) $already,
                'appendable' => (bool) $appendable,
            );
        }

        private function js_delay_scan_html_has_any_marker($html, array $markers)
        {
            $html = is_string($html) ? $html : (string) $html;
            if ('' === $html) {
                return false;
            }

            foreach ($markers as $marker) {
                $marker = trim((string) $marker);
                if ('' === $marker) {
                    continue;
                }
                if (false !== stripos($html, $marker)) {
                    return true;
                }
            }

            return false;
        }

        private function collect_js_delay_component_recommendations($html, array $settings = array())
        {
            $html = is_string($html) ? $html : (string) $html;
            $suggestions = array();
            $seen = array();

            if ('' === $html) {
                return $suggestions;
            }

            $groups = array(
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('sr7-module', 'sr7-slide', 'revslider', '/plugins/revslider/', 'themepunch', 'rs-module', 'wp-block-themepunch-revslider'),
                    'suggestions' => array('revslider', 'sr7', 'tptools', 'tp-tools', 'rs6', 'rs-module'),
                    'reason' => __('Slider Revolution / SR7 assets or markup were detected on this page. Keep slider runtime assets out of Delay JS unless visually tested.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('swiper', 'swiper-container', 'swiper-wrapper', '/swiper/', 'elementor-widget-slides', 'elementor-widget-image-carousel', 'elementor-widget-media-carousel'),
                    'suggestions' => array('swiper', 'swiper-bundle'),
                    'reason' => __('Swiper slider/carousel assets or markup were detected on this page. Keep these runtime assets protected unless visually tested.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('slick', 'slick-slider', 'slick-track', 'slick.min.js'),
                    'suggestions' => array('slick'),
                    'reason' => __('Slick carousel assets or markup were detected on this page. Keep carousel runtime assets protected unless visually tested.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('splide', 'splide__track', 'splide.min.js'),
                    'suggestions' => array('splide'),
                    'reason' => __('Splide slider assets or markup were detected on this page. Keep slider runtime assets protected unless visually tested.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('owl.carousel', 'owl-carousel', 'owl-stage', 'owlCarousel'),
                    'suggestions' => array('owl.carousel', 'owl-carousel'),
                    'reason' => __('Owl Carousel assets or markup were detected on this page. Keep carousel runtime assets protected unless visually tested.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('smartslider', 'smart-slider', 'n2-ss', 'nextend'),
                    'suggestions' => array('smartslider', 'n2-ss'),
                    'reason' => __('Smart Slider / Nextend assets or markup were detected on this page. Keep slider runtime assets protected unless visually tested.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('layerslider', 'layer-slider', 'masterslider', 'master-slider', 'metaslider', 'soliloquy', 'royalslider', 'sliderpro', 'flickity', 'glide'),
                    'suggestions' => array('layerslider', 'masterslider', 'metaslider', 'soliloquy', 'royalslider', 'sliderpro', 'flickity', 'glide'),
                    'reason' => __('Known slider/carousel assets or markup were detected on this page. Keep matching runtime assets protected unless visually tested.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('elementor', 'elementor-widget', '/plugins/elementor/', '/plugins/elementor-pro/'),
                    'suggestions' => array('elementor', 'elementor-frontend', 'elementor-pro', 'frontend-modules', 'webpack.runtime'),
                    'reason' => __('Elementor assets or widgets were detected on this page. Keep core Elementor runtime dependencies protected unless dependency-safe testing passes.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('et_pb_', 'et-builder', 'et-core', '/themes/Divi/', '/plugins/divi-builder/'),
                    'suggestions' => array('divi', 'et-core', 'et-builder', 'et_pb', 'cmplz_activated_divi_recaptcha'),
                    'reason' => __('Divi builder assets or markup were detected on this page. Keep Divi runtime dependencies protected unless dependency-safe testing passes.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('js_composer', 'wpb_', 'vc_', 'wpbakery'),
                    'suggestions' => array('wpbakery', 'js_composer', 'vc_', 'wpb_'),
                    'reason' => __('WPBakery/Visual Composer assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('bricks', 'bricks-frontend', 'brxe-'),
                    'suggestions' => array('bricks', 'bricks-frontend', 'brxe-'),
                    'reason' => __('Bricks Builder assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('oxygen', 'ct-section', 'ct-div-block', 'ct-inner-content'),
                    'suggestions' => array('oxygen', 'ct-', 'oxy-'),
                    'reason' => __('Oxygen Builder assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('fl-builder', 'beaver-builder'),
                    'suggestions' => array('fl-builder', 'beaver-builder'),
                    'reason' => __('Beaver Builder assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('fusion-builder', 'avada-', 'fusion-'),
                    'suggestions' => array('fusion-builder', 'avada', 'fusion-'),
                    'reason' => __('Avada/Fusion Builder assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('thrive-', 'tcb-', 'tve_'),
                    'suggestions' => array('thrive', 'tcb-', 'tve_'),
                    'reason' => __('Thrive builder assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('seedprod', 'siteorigin', 'so-widget', 'uagb-', 'spectra', 'kadence', 'kt-', 'generateblocks', 'gb-'),
                    'suggestions' => array('seedprod', 'siteorigin', 'uagb', 'spectra', 'kadence', 'generateblocks'),
                    'reason' => __('Known block/page-builder assets or markup were detected on this page. Keep matching runtime assets protected unless visually tested.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('complianz', 'cmplz', 'complianz-gdpr', 'complianz-gdpr-premium'),
                    'suggestions' => array('complianz', 'cmplz', 'complianz-gdpr/cookiebanner/js/complianz.min.js', 'complianz-gdpr-premium/cookiebanner/js/complianz.min.js', 'complianz-gdpr-premium/pro/tcf/build/index.js', 'complianz-gdpr-premium/pro/tcf-stub/build/index.js'),
                    'reason' => __('Complianz consent assets were detected on this page. Consent/cookie scripts should stay out of Delay JS to avoid banner or consent-state issues.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('cookieyes', 'cookielawinfo', 'cky-', 'cookiebot', 'uc.js', 'iubenda', 'onetrust', 'optanon'),
                    'suggestions' => array('cookieyes', 'cookielawinfo', 'cky-', 'cookiebot', 'iubenda', 'onetrust', 'optanon'),
                    'reason' => __('Cookie/consent-management assets were detected on this page. Consent scripts are safer when excluded from Delay JS.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('google.com/recaptcha', 'gstatic.com/recaptcha', 'grecaptcha', 'hcaptcha', 'hcaptcha.com', 'turnstile', 'challenges.cloudflare.com', 'cf-turnstile'),
                    'suggestions' => array('google.com/recaptcha', 'gstatic.com/recaptcha', 'grecaptcha', 'hcaptcha', 'turnstile', 'challenges.cloudflare.com', 'cf-turnstile'),
                    'reason' => __('Captcha/anti-bot assets were detected on this page. These are commonly unsafe to delay because forms may need them immediately.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('contact-form-7', 'wpforms', 'gform', 'gravityforms', 'formidable', 'ninja-forms', 'fluentform', 'forminator', 'mailerlite', 'mailchimp', 'mc4wp', 'klaviyo', 'hubspot'),
                    'suggestions' => array('contact-form-7', 'wpforms', 'gform', 'gravityforms', 'formidable', 'ninja-forms', 'fluentform', 'forminator', 'mailerlite', 'validation-messages', 'mailchimp', 'mc4wp', 'klaviyo', 'hubspot'),
                    'reason' => __('Form, validation, newsletter, or CRM assets were detected on this page. Exclude matching form runtime assets if the form must work before interaction.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => __('Detected component protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('js.stripe.com', 'stripe', 'paypal.com/sdk/js', 'paypal', 'braintree', 'klarna', 'afterpay', 'squareup', 'square-web-payments'),
                    'suggestions' => array('js.stripe.com', 'stripe', 'paypal.com/sdk/js', 'paypal', 'braintree', 'klarna', 'afterpay', 'square'),
                    'reason' => __('Payment gateway assets were detected on this page. Payment/checkout scripts are safer when excluded from Delay JS.', 'ultracache'),
                ),
                array(
                    'category' => 'detected-elementor-load-order',
                    'label' => __('Elementor load-order protections', 'ultracache'),
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('elementorModules', 'elementor/assets/js/common.min.js', 'common.min.js?ver=', 'elementor-admin-bar.min.js', 'frontend-modules.min.js', 'elementor-frontend-modules', 'elementor-webpack-runtime'),
                    'suggestions' => array('elementor', 'elementor-frontend', 'elementor-frontend-modules', 'frontend-modules', 'elementor-webpack-runtime', 'elementor-pro-webpack-runtime', 'elementorModules', 'elementor/assets/js/frontend-modules', 'elementor/assets/js/common.min.js', 'elementor/assets/js/elementor-admin-bar.min.js', 'common.min.js', 'elementor-admin-bar.min.js'),
                    'reason' => __('Elementor module/runtime scripts were detected. When Defer all JS is enabled, Elementor module providers and dependent common/admin-bar scripts should stay in the visible JS Delay / Defer Exclusions list unless the page has been verified clean.', 'ultracache'),
                ),
                array(
                    'category' => 'review-only',
                    'label' => __('Review-only candidates', 'ultracache'),
                    'confidence' => 'review',
                    'appendable' => false,
                    'markers' => array('woocommerce', 'wc-', 'cart', 'checkout', 'account', 'add-to-cart', 'wc-cart-fragments'),
                    'suggestions' => array('woocommerce', 'wc-', 'cart', 'checkout', 'account', 'add-to-cart', 'wc-cart-fragments'),
                    'reason' => __('WooCommerce/cart/account markers were detected. Review these before excluding broadly because shop pages vary by site.', 'ultracache'),
                ),
                array(
                    'category' => 'review-only',
                    'label' => __('Review-only candidates', 'ultracache'),
                    'confidence' => 'review',
                    'appendable' => false,
                    'markers' => array('googletagmanager.com', 'google-analytics.com/analytics.js', 'gtag', 'gtm', 'dataLayer', 'adsbygoogle', 'doubleclick', 'facebook.net', 'fbevents', 'connect.facebook.net', 'tiktok', 'pinterest', 'hotjar', 'clarity', 'stats.wp.com', '_stq'),
                    'suggestions' => array('gtag', 'gtm', 'dataLayer', 'adsbygoogle', 'stats.wp.com/e-', '_stq', 'facebook.net', 'fbevents', 'hotjar', 'clarity'),
                    'reason' => __('Tracking/ads scripts were detected. These are review-only because delaying them often improves performance but may affect analytics/ads timing.', 'ultracache'),
                ),
            );

            foreach ($groups as $group) {
                if (!$this->js_delay_scan_html_has_any_marker($html, isset($group['markers']) && is_array($group['markers']) ? $group['markers'] : array())) {
                    continue;
                }

                foreach ((array) ($group['suggestions'] ?? array()) as $line) {
                    $line = trim((string) $line);
                    if ('' === $line) {
                        continue;
                    }
                    $category = (string) ($group['category'] ?? 'detected-component-protection');
                    if (!$this->js_delay_scan_html_has_any_marker($html, array($line))) {
                        continue;
                    }
                    $this->add_js_delay_component_recommendation(
                        $suggestions,
                        $seen,
                        $line,
                        $category,
                        (string) ($group['label'] ?? 'Detected component protections'),
                        (string) ($group['reason'] ?? ''),
                        $settings,
                        (string) ($group['confidence'] ?? 'recommended'),
                        '',
                        !empty($group['appendable'])
                    );
                }
            }

            return $suggestions;
        }

        private function collect_js_inline_dependency_defer_recommendations($html, array $settings = array())
        {
            $html = is_string($html) ? $html : (string) $html;
            $suggestions = array();
            $seen = array();

            if ('' === $html || false === stripos($html, '<script')) {
                return $suggestions;
            }

            if (!preg_match_all('/<script\b(?![^>]*\bsrc\s*=)([^>]*)>(.*?)<\/script>/is', $html, $scripts, PREG_SET_ORDER)) {
                return $suggestions;
            }

            foreach ($scripts as $script) {
                $attrs = isset($script[1]) ? (string) $script[1] : '';
                $code = isset($script[2]) ? (string) $script[2] : '';
                $id = (string) $this->extract_attribute_from_html_tag('<script ' . $attrs . '>', 'id');
                $id_lc = strtolower(trim($id));
                $code_lc = strtolower($code);
                $sample = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($code)));
                $sample = function_exists('mb_substr') ? mb_substr($sample, 0, 220) : substr($sample, 0, 220);

                if ('' === $id_lc && '' === trim($code)) {
                    continue;
                }

                $recommendations = array();

                if (false !== strpos($id_lc, 'jquery-js-after') || false !== strpos($code_lc, 'jquery') || false !== strpos($code, '$(')) {
                    $recommendations[] = array(
                        'symbol' => 'jQuery',
                        'suggestedExclusion' => 'jquery',
                        'reason' => sprintf(
							/* translators: %s: inline script block ID, or no id. */
							__('Inline script block %s references jQuery. Keep the jQuery handle in the visible JS Delay / Defer Exclusions list unless the inline block is also moved into a delayed/replayed execution group.', 'ultracache'),
							'' !== $id ? $id : '(no id)'
						),
                    );
                }

                if (false !== strpos($id_lc, 'wp-i18n-js-after') || false !== strpos($id_lc, 'js-translations') || false !== strpos($code_lc, 'wp.i18n') || false !== strpos($code_lc, 'setlocaledata')) {
                    $recommendations[] = array(
                        'symbol' => 'wp.i18n',
                        'suggestedExclusion' => 'wp-i18n',
                        'reason' => sprintf(
							/* translators: %s: inline script block ID, or no id. */
							__('Inline WordPress translations/i18n block %s references wp.i18n. Keep wp-i18n and its core dependency chain visible/excluded before using Defer all JS.', 'ultracache'),
							'' !== $id ? $id : '(no id)'
						),
                    );
                }

                if (false !== strpos($id_lc, 'wp-api-fetch-js-after') || false !== strpos($code_lc, 'wp.apifetch') || false !== strpos($code_lc, 'api-fetch')) {
                    $recommendations[] = array(
                        'symbol' => 'wp.apiFetch',
                        'suggestedExclusion' => 'wp-api-fetch',
                        'reason' => sprintf(
							/* translators: %s: inline script block ID, or no id. */
							__('Inline wp-api-fetch configuration block %s references wp.apiFetch. Keep wp-api-fetch/core WP globals visible/excluded unless the inline block is also deferred with its dependency group.', 'ultracache'),
							'' !== $id ? $id : '(no id)'
						),
                    );
                }

                if (false !== strpos($code_lc, 'wp.hooks')) {
                    $recommendations[] = array(
                        'symbol' => 'wp.hooks',
                        'suggestedExclusion' => 'wp-hooks',
                        'reason' => sprintf(
							/* translators: %s: inline script block ID, or no id. */
							__('Inline script block %s references wp.hooks. Keep wp-hooks visible/excluded for Defer all JS safety.', 'ultracache'),
							'' !== $id ? $id : '(no id)'
						),
                    );
                }

                foreach ($recommendations as $recommendation) {
                    $suggestion = trim((string) ($recommendation['suggestedExclusion'] ?? ''));
                    if ('' === $suggestion) {
                        continue;
                    }
                    $key = strtolower((string) ($recommendation['symbol'] ?? '') . '|' . $suggestion . '|' . $id_lc);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $suggestions[] = array(
                        'symbol' => (string) ($recommendation['symbol'] ?? 'inline dependency'),
                        'source' => 'inline-script-dependency-group',
                        'sample' => $sample,
                        'definingScriptUrl' => '',
                        'definingHandle' => '',
                        'suggestedExclusion' => $suggestion,
                        'confidence' => 'high',
                        'reason' => (string) ($recommendation['reason'] ?? 'Inline script depends on a global that may not exist if its external handle is deferred.'),
                        'alreadyExcluded' => (bool) $this->delay_safety_exclusion_already_matches($suggestion, $settings),
                    );

                    if (count($suggestions) >= 30) {
                        return $suggestions;
                    }
                }
            }

            return $suggestions;
        }

        private function collect_store_profile_js_delay_safety_scan($html)
        {
            $html = is_string($html) ? $html : (string) $html;
            $settings = $this->get_settings();
            $symbols = $this->collect_js_delay_safety_inline_symbols($html);
            $definitions = $this->collect_js_delay_safety_delayed_definitions($html);
            $suggestions = array();
            $seen = array();

            foreach ($symbols as $symbol => $reference) {
                if (empty($definitions[$symbol]) || !is_array($definitions[$symbol])) {
                    continue;
                }
                foreach ($definitions[$symbol] as $definition) {
                    $suggestion = (string) ($definition['suggestedExclusion'] ?? '');
                    $source = (string) ($reference['source'] ?? 'inline-script');
                    if ('' === trim($suggestion) || !$this->is_js_delay_safety_meaningful_symbol($symbol, $source)) {
                        continue;
                    }
                    $key = strtolower($symbol . '|' . $suggestion);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $reason = 'inline-event-handler' === $source
                        ? 'Inline event handler calls ' . $symbol . '(), but the script that defines it is delayed.'
                        : 'Inline script references global "' . $symbol . '", but the script that defines it is delayed.';
                    $already = $this->delay_safety_exclusion_already_matches($suggestion, $settings);
                    $suggestions[] = array(
                        'symbol' => $symbol,
                        'source' => $source,
                        'sample' => (string) ($reference['sample'] ?? ''),
                        'definingScriptUrl' => (string) ($definition['url'] ?? ''),
                        'definingHandle' => (string) ($definition['handle'] ?? ''),
                        'suggestedExclusion' => $suggestion,
                        'confidence' => 'high',
                        'reason' => $reason,
                        'alreadyExcluded' => (bool) $already,
                    );
                    if (count($suggestions) >= 20) {
                        break 2;
                    }
                }
            }

            foreach ($this->collect_js_inline_dependency_defer_recommendations($html, $settings) as $inline_dependency_suggestion) {
                if (!is_array($inline_dependency_suggestion)) {
                    continue;
                }
                $symbol = (string) ($inline_dependency_suggestion['symbol'] ?? 'inline-dependency');
                $suggestion = (string) ($inline_dependency_suggestion['suggestedExclusion'] ?? '');
                $source = (string) ($inline_dependency_suggestion['source'] ?? 'inline-script-dependency-group');
                $key = strtolower($symbol . '|' . $source . '|' . $suggestion);
                if ('' === trim($suggestion) || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $suggestions[] = $inline_dependency_suggestion;
                if (count($suggestions) >= 40) {
                    break;
                }
            }

            foreach ($this->collect_js_delay_safety_targeted_suggestions($html, $settings) as $targeted_suggestion) {
                if (!is_array($targeted_suggestion)) {
                    continue;
                }
                $symbol = (string) ($targeted_suggestion['symbol'] ?? '');
                $suggestion = (string) ($targeted_suggestion['suggestedExclusion'] ?? '');
                $key = strtolower($symbol . '|' . $suggestion);
                if ('' === trim($suggestion) || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $suggestions[] = $targeted_suggestion;
                if (count($suggestions) >= 20) {
                    break;
                }
            }

            foreach ($this->collect_js_delay_component_recommendations($html, $settings) as $component_suggestion) {
                if (!is_array($component_suggestion)) {
                    continue;
                }
                $category = (string) ($component_suggestion['category'] ?? $component_suggestion['source'] ?? 'detected-component-protection');
                $suggestion = (string) ($component_suggestion['suggestedExclusion'] ?? '');
                $key = strtolower($category . '|' . $suggestion);
                if ('' === trim($suggestion) || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $suggestions[] = $component_suggestion;
                if (count($suggestions) >= 80) {
                    break;
                }
            }

            $missing = 0;
            foreach ($suggestions as $suggestion) {
                if (empty($suggestion['alreadyExcluded'])) {
                    $missing++;
                }
            }

            return array(
                'available' => true,
                'suggestion_count' => count($suggestions),
                'missing_count' => (int) $missing,
                'already_excluded_count' => count($suggestions) - (int) $missing,
                'suggestions' => $suggestions,
            );
        }


        public function maybe_apply_runtime_js_scan_anonymous_context()
        {
            $data = $this->get_runtime_js_scan_request_data(false);
            if (false === $data || empty($data['anonymous_context'])) {
                return;
            }

            $GLOBALS['ucwp_runtime_js_scan_request_data'] = $data;
            if (function_exists('wp_set_current_user')) {
                wp_set_current_user(0);
            }
            add_filter('show_admin_bar', '__return_false', PHP_INT_MAX);
        }


        private function get_runtime_js_scan_request_data($allow_preverified = true)
        {
            if (!empty($allow_preverified) && isset($GLOBALS['ucwp_runtime_js_scan_request_data']) && is_array($GLOBALS['ucwp_runtime_js_scan_request_data'])) {
                return $GLOBALS['ucwp_runtime_js_scan_request_data'];
            }

            if (is_admin() || empty($_GET['ucwp_runtime_js_scan']) || empty($_GET['ucwp_runtime_js_scan_id']) || empty($_GET['ucwp_runtime_js_scan_nonce'])) {
                return false;
            }

            if (!is_user_logged_in() || !current_user_can('manage_options')) {
                return false;
            }

            $nonce = sanitize_text_field(wp_unslash($_GET['ucwp_runtime_js_scan_nonce']));
            if (!wp_verify_nonce($nonce, 'ucwp_runtime_js_scan')) {
                return false;
            }

            $scan_id = sanitize_key(wp_unslash($_GET['ucwp_runtime_js_scan_id']));
            if ('' === $scan_id || strlen($scan_id) > 64) {
                return false;
            }

            $context = isset($_GET['ucwp_runtime_js_scan_context']) ? sanitize_key(wp_unslash($_GET['ucwp_runtime_js_scan_context'])) : 'anonymous';
            $context = 'logged-in' === $context ? 'logged-in' : 'anonymous';

            return array(
                'scan_id'           => $scan_id,
                'endpoint'          => esc_url_raw(rest_url('ultracache/v1/runtime-js-scan/report')),
                'rest_nonce'        => wp_create_nonce('wp_rest'),
                'scan_context'      => $context,
                'anonymous_context' => 'anonymous' === $context,
            );
        }

        public function is_runtime_js_scan_request()
        {
            return false !== $this->get_runtime_js_scan_request_data();
        }

        private function build_runtime_js_scan_collector_script(array $data)
        {
            $scan_id = isset($data['scan_id']) ? (string) $data['scan_id'] : '';
            $endpoint = isset($data['endpoint']) ? (string) $data['endpoint'] : '';
            $rest_nonce = isset($data['rest_nonce']) ? (string) $data['rest_nonce'] : '';
            $scan_context = isset($data['scan_context']) && 'logged-in' === $data['scan_context'] ? 'logged-in' : 'anonymous';
            if ('' === $scan_id || '' === $endpoint || '' === $rest_nonce) {
                return '';
            }

            $scan_id_json = wp_json_encode($scan_id);
            $endpoint_json = wp_json_encode($endpoint);
            $rest_nonce_json = wp_json_encode($rest_nonce);
            $scan_context_json = wp_json_encode($scan_context);

            return '<script id="ucwp-runtime-js-scan-collector" data-ucwp-runtime-scan="early">' . "\n" .
                "(function(){\n" .
                "\t'use strict';\n" .
                "\tvar scanId = " . $scan_id_json . ";\n" .
                "\tvar endpoint = " . $endpoint_json . ";\n" .
                "\tvar restNonce = " . $rest_nonce_json . ";\n" .
                "\tvar scanContext = " . $scan_context_json . ";\n" .
                "\tvar startedAt = Date.now();\n" .
                "\tvar errors = [];\n" .
                "\tvar sentCount = 0;\n" .
                "\tvar maxErrors = 120;\n" .
                "\tvar originalOnError = window.onerror;\n" .
                "\tvar originalOnUnhandledRejection = window.onunhandledrejection;\n" .
                "\twindow.__ucwpRuntimeJsScan = window.__ucwpRuntimeJsScan || { injectedAt: startedAt, context: scanContext, errors: errors, sentCount: 0, debug: { installed: true, source: 'head-final-output', context: scanContext, onerror: false, eventError: false, consoleError: false, directHarvest: false } };\n" .
                "\tfunction asText(value){\n" .
                "\t\ttry {\n" .
                "\t\t\tif (value instanceof Error) { return value.name + ': ' + value.message; }\n" .
                "\t\t\tif (typeof value === 'string') { return value; }\n" .
                "\t\t\treturn JSON.stringify(value);\n" .
                "\t\t} catch (e) { return String(value); }\n" .
                "\t}\n" .
                "\tfunction trimText(value, max){ value = String(value || ''); max = max || 800; return value.length > max ? value.slice(0, max) : value; }\n" .
                "\tfunction addError(kind, message, source, line, column, detail){\n" .
                "\t\tvar item = { kind: trimText(kind, 40), message: trimText(message, 1000), source: trimText(source, 1000), line: Number(line || 0), column: Number(column || 0), detail: trimText(detail, 1000), atMs: Date.now() - startedAt };\n" .
                "\t\terrors.push(item);\n" .
                "\t\twindow.__ucwpRuntimeJsScan.errors = errors;\n" .
                "\t\tif (errors.length > maxErrors) { errors = errors.slice(errors.length - maxErrors); window.__ucwpRuntimeJsScan.errors = errors; }\n" .
                "\t\tsend(false);\n" .
                "\t}\n" .
                "\tfunction getResourceUrl(target){\n" .
                "\t\ttry {\n" .
                "\t\t\tif (!target || !target.getAttribute) { return ''; }\n" .
                "\t\t\treturn String(target.getAttribute('src') || target.getAttribute('href') || target.currentSrc || target.src || target.href || '');\n" .
                "\t\t} catch (e) { return ''; }\n" .
                "\t}\n" .
                "\tfunction describeResourceTarget(target){\n" .
                "\t\ttry {\n" .
                "\t\t\tif (!target) { return ''; }\n" .
                "\t\t\tvar tag = target.tagName ? String(target.tagName).toLowerCase() : 'resource';\n" .
                "\t\t\tvar id = target.id ? ('#' + target.id) : '';\n" .
                "\t\t\tvar rel = target.rel ? (' rel=' + target.rel) : '';\n" .
                "\t\t\tvar type = target.type ? (' type=' + target.type) : '';\n" .
                "\t\t\treturn tag + id + rel + type;\n" .
                "\t\t} catch (e) { return ''; }\n" .
                "\t}\n" .
                "\tfunction collectScripts(){\n" .
                "\t\tvar list = [];\n" .
                "\t\ttry {\n" .
                "\t\t\tvar scripts = document.getElementsByTagName('script');\n" .
                "\t\t\tfor (var i = 0; i < scripts.length && list.length < 240; i++) {\n" .
                "\t\t\t\tvar s = scripts[i];\n" .
                "\t\t\t\tvar src = s && s.getAttribute ? String(s.getAttribute('src') || s.getAttribute('data-ucwp-src') || s.getAttribute('data-ucwp-original-src') || '') : '';\n" .
                "\t\t\t\tvar id = s && s.getAttribute ? String((s.id || '') || s.getAttribute('data-ucwp-id') || s.getAttribute('data-ucwp-handle') || '') : '';\n" .
                "\t\t\t\tvar handle = s && s.getAttribute ? String(s.getAttribute('data-ucwp-handle') || '') : '';\n" .
                "\t\t\t\tvar delayed = !!(s && (s.type === 'text/ucwp-delayed-js' || (s.hasAttribute && (s.hasAttribute('data-ucwp-src') || s.hasAttribute('data-ucwp-inline') || s.hasAttribute('data-ucwp-delayed')))));\n" .
                "\t\t\t\tvar text = '';\n" .
                "\t\t\t\tif ((!src || delayed) && s && s.textContent) { text = trimText(s.textContent, 60000); }\n" .
                "\t\t\t\tlist.push({ id: trimText(id, 160), handle: trimText(handle, 160), src: trimText(src, 1200), type: trimText(s && s.type ? s.type : '', 120), defer: !!(s && s.defer), async: !!(s && s.async), strategy: trimText(s && s.getAttribute ? (s.getAttribute('data-wp-strategy') || '') : '', 80), delayed: delayed, text: text });\n" .
                "\t\t\t}\n" .
                "\t\t} catch (e) {}\n" .
                "\t\treturn list;\n" .
                "\t}\n" .
                "\tfunction send(completed){\n" .
                "\t\tif (!endpoint || !scanId || !window.fetch) { return; }\n" .
                "\t\tvar payload = { scanId: scanId, url: String(window.location.href || ''), completed: !!completed, scanContext: scanContext, errors: errors, scripts: collectScripts(), userAgent: String(window.navigator && window.navigator.userAgent ? window.navigator.userAgent : ''), sentCount: ++sentCount, elapsedMs: Date.now() - startedAt, debug: window.__ucwpRuntimeJsScan && window.__ucwpRuntimeJsScan.debug ? window.__ucwpRuntimeJsScan.debug : {} };\n" .
                "\t\twindow.__ucwpRuntimeJsScan.sentCount = sentCount;\n" .
                "\t\twindow.__ucwpRuntimeJsScan.lastPayload = payload;\n" .
                "\t\ttry { window.fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce }, body: JSON.stringify(payload), keepalive: !!completed }).catch(function(){}); } catch (e) {}\n" .
                "\t}\n" .
                "\twindow.__ucwpRuntimeJsScan.flush = send;\n" .
                "\twindow.onerror = function(message, source, line, column, error){\n" .
                "\t\ttry { window.__ucwpRuntimeJsScan.debug.onerror = true; addError('window-onerror', message || 'Script error', source || '', line || 0, column || 0, error && error.stack ? error.stack : asText(error || message)); } catch (e) {}\n" .
                "\t\tif (typeof originalOnError === 'function') { return originalOnError.apply(this, arguments); }\n" .
                "\t\treturn false;\n" .
                "\t};\n" .
                "\twindow.addEventListener('error', function(event){\n" .
                "\t\tif (!event) { return; }\n" .
                "\t\twindow.__ucwpRuntimeJsScan.debug.eventError = true;\n" .
                "\t\tvar target = event.target || event.srcElement || null;\n" .
                "\t\tif (target && target !== window && (target.tagName || target.getAttribute)) {\n" .
                "\t\t\tvar resourceUrl = getResourceUrl(target);\n" .
                "\t\t\tif (resourceUrl) {\n" .
                "\t\t\t\taddError('resource-error', 'Resource failed to load', resourceUrl, 0, 0, describeResourceTarget(target));\n" .
                "\t\t\t\treturn;\n" .
                "\t\t\t}\n" .
                "\t\t}\n" .
                "\t\tvar detail = event.error ? asText(event.error && event.error.stack ? event.error.stack : event.error) : '';\n" .
                "\t\taddError('error', event.message || 'Script error', event.filename || '', event.lineno || 0, event.colno || 0, detail);\n" .
                "\t}, true);\n" .
                "\twindow.onunhandledrejection = function(event){\n" .
                "\t\ttry { var reason = event && event.reason ? event.reason : ''; addError('window-unhandledrejection', asText(reason), '', 0, 0, reason && reason.stack ? reason.stack : ''); } catch (e) {}\n" .
                "\t\tif (typeof originalOnUnhandledRejection === 'function') { return originalOnUnhandledRejection.apply(this, arguments); }\n" .
                "\t};\n" .
                "\twindow.addEventListener('unhandledrejection', function(event){ var reason = event && event.reason ? event.reason : ''; addError('unhandledrejection', asText(reason), '', 0, 0, reason && reason.stack ? reason.stack : ''); }, true);\n" .
                "\tif (window.console && typeof window.console.error === 'function' && !window.console.__ucwpRuntimeScanWrapped) {\n" .
                "\t\tvar originalError = window.console.error;\n" .
                "\t\twindow.console.error = function(){\n" .
                "\t\t\ttry { window.__ucwpRuntimeJsScan.debug.consoleError = true; var parts = []; for (var i = 0; i < arguments.length; i++) { parts.push(asText(arguments[i])); } addError('console-error', parts.join(' '), '', 0, 0, ''); } catch (e) {}\n" .
                "\t\t\treturn originalError.apply(window.console, arguments);\n" .
                "\t\t};\n" .
                "\t\twindow.console.__ucwpRuntimeScanWrapped = true;\n" .
                "\t}\n" .
                "\tsend(false);\n" .
                "\tvar tick = 0;\n" .
                "\tvar timer = setInterval(function(){ tick++; send(false); if (tick >= 12) { clearInterval(timer); } }, 1000);\n" .
                "\twindow.addEventListener('load', function(){ setTimeout(function(){ send(true); }, 4500); }, false);\n" .
                "\tsetTimeout(function(){ send(true); }, 12000);\n" .
                "})();\n" .
                '</script>';
        }

        public function inject_runtime_js_scan_collector_into_output($html)
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            $data = $this->get_runtime_js_scan_request_data();
            if (false === $data) {
                return $html;
            }

            $collector = $this->build_runtime_js_scan_collector_script($data);
            if ('' === $collector) {
                return $html;
            }

            $html = preg_replace('#<script\s+id=["\']ucwp-runtime-js-scan-collector["\'][\s\S]*?</script>\s*#i', '', $html);
            if (!is_string($html)) {
                return $collector;
            }

            if (preg_match('#<head\b[^>]*>#i', $html, $match, PREG_OFFSET_CAPTURE)) {
                $insert_at = (int) $match[0][1] + strlen($match[0][0]);
                return substr($html, 0, $insert_at) . "\n" . $collector . "\n" . substr($html, $insert_at);
            }

            return $collector . "\n" . $html;
        }

        public function print_runtime_js_scan_collector()
        {
            $data = $this->get_runtime_js_scan_request_data();
            if (false === $data) {
                return;
            }

            echo $this->build_runtime_js_scan_collector_script($data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Script is generated from sanitized IDs, REST URL, and nonces via wp_json_encode.
        }

        public function defer_scripts($tag, $handle, $src)
        {
            $settings = $this->get_settings();
            if (is_admin()) {
                return $tag;
            }

            $defer_stage = $this->get_defer_stage_level($settings);
            $defer_all_js = !empty($settings['defer_js']) && !empty($settings['defer_all_js']);

            if (!$defer_all_js && 0 < $defer_stage && $this->is_script_absolute_defer_blocking($handle, $src, $tag, $settings)) {
                return $this->strip_native_loading_attributes_from_script_tag($tag);
            }

            if (0 < $defer_stage && $this->is_script_user_defer_excluded($handle, $src, $settings)) {
                return $this->strip_native_loading_attributes_from_script_tag($tag);
            }

            if (0 < $defer_stage && $this->is_script_user_force_deferred($handle, $src, $tag, $settings)) {
                return $this->add_defer_attribute_to_script_tag($tag, true);
            }

            if ($defer_all_js && $this->should_native_defer_all_local_script($src, $settings) && $this->is_defer_all_js_candidate($handle, $src, $tag, $settings)) {
                return $this->add_defer_attribute_to_script_tag($tag, false);
            }

            if (!$defer_all_js && 0 < $defer_stage && $this->is_script_force_blocking($handle, $src, $tag, $settings)) {
                return $this->strip_native_loading_attributes_from_script_tag($tag);
            }

            if (!$defer_all_js && 0 < $defer_stage && $this->is_script_safe_stage_excluded($handle, $src, $tag, $settings)) {
                return $this->strip_native_loading_attributes_from_script_tag($tag);
            }

            if (2 <= $defer_stage) {
                $third_party_delay_match = $this->get_third_party_delay_match($handle, $src, $tag, $settings);
                if (!empty($third_party_delay_match['matched'])) {
                    return $this->build_delayed_script_tag($tag, $handle, $src, $third_party_delay_match['reason']);
                }
            }

            if (2 <= $defer_stage && !empty($settings['delay_non_critical_js']) && $this->should_delay_non_critical_script($handle, $src, $tag, $settings)) {
                return $this->build_delayed_script_tag($tag, $handle, $src);
            }

            if (!empty($settings['async_external_scripts']) && $this->should_async_external_script($handle, $src, $tag, $settings)) {
                return $this->add_async_attribute_to_script_tag($tag);
            }

            if (0 === $defer_stage || empty($settings['defer_js'])) {
                return $tag;
            }

            if ($defer_all_js && !$this->is_defer_all_js_candidate($handle, $src, $tag, $settings)) {
                return $tag;
            }

            return $this->add_defer_attribute_to_script_tag($tag, false);
        }

        private function should_keep_script_blocking_for_defer_all($handle, $src, $tag = '', array $settings = array())
        {
            // Defer all JS is intentionally literal/aggressive: the only scripts
            // kept blocking are those matching the visible JS Delay / Defer
            // Exclusions field. WordPress/core/slider protections belong in that
            // editable list via Populate Defaults, not in hidden runtime rules.
            return $this->is_script_user_defer_excluded($handle, $src, $settings);
        }

        private function should_native_defer_all_local_script($src, array $settings = array())
        {
            /*
             * 2.56.122 regression guard: 2.56.120 bypassed the ordered
             * delayed-loader for every same-host script when Defer all JS was
             * enabled. That broke grouped inline-before / inline-after config
             * scripts for Complianz, Site Kit, WooCommerce and similar assets.
             * Keep this helper as a no-op so the dependency-aware ordered path
             * remains authoritative.
             */
            return false;
        }

        private function apply_defer_all_js_to_html($html, array $settings = array())
        {
            if (empty($settings['defer_js']) || empty($settings['defer_all_js']) || !is_string($html) || '' === $html || false === stripos($html, '<script')) {
                return $html;
            }

            $records = $this->collect_script_dependency_records_from_html($html);
            if (empty($records)) {
                return $html;
            }

            $protected_groups = array();
            $user_protected_groups = $this->get_user_excluded_script_dependency_groups($records, $settings);
            if (!empty($user_protected_groups)) {
                $protected_groups = array_merge($protected_groups, $user_protected_groups);
            }
            $mutations = array();

            foreach ($records as $index => $record) {
                if (empty($record['has_src']) || empty($record['open']) || empty($record['tag'])) {
                    continue;
                }

                $handle = isset($record['handle']) ? (string) $record['handle'] : '';
                $src = isset($record['src']) ? (string) $record['src'] : '';
                $open = isset($record['open']) ? (string) $record['open'] : '';
                $group = isset($record['group']) ? (string) $record['group'] : '';

                if ($this->should_keep_script_blocking_for_defer_all($handle, $src, $open, $settings) || ('' !== $group && !empty($protected_groups[$group]))) {
                    $mutations[$index] = 'strip-loading';
                } elseif ($this->is_defer_all_js_candidate($handle, $src, $open, $settings)) {
                    $mutations[$index] = 'defer';
                }
            }

            if (empty($mutations)) {
                return $html;
            }

            $updated = $this->apply_script_loading_attribute_mutations_with_processor($html, $mutations);
            return is_string($updated) ? $updated : $html;
        }

        private function restore_user_excluded_delayed_scripts_in_html($html, array $settings = array())
        {
            if (!is_string($html) || '' === $html || false === stripos($html, 'text/ucwp-delayed-js')) {
                return $html;
            }

            $records = $this->collect_script_dependency_records_from_html($html);
            if (empty($records)) {
                return $html;
            }

            $protected_groups = $this->get_user_excluded_script_dependency_groups($records, $settings);
            $replacements = array();

            foreach ($records as $index => $record) {
                if (empty($record['delayed']) || empty($record['tag'])) {
                    continue;
                }

                $group = isset($record['group']) ? (string) $record['group'] : '';
                if (!$this->script_record_matches_user_defer_exclusion($record, $settings) && ('' === $group || empty($protected_groups[$group]))) {
                    continue;
                }

                $restored = $this->restore_delayed_script_record_tag($record);
                if (is_string($restored) && '' !== $restored && $restored !== $record['tag']) {
                    $replacements[(int) $index] = $restored;
                }
            }

            if (empty($replacements)) {
                return $html;
            }

            ksort($replacements);
            $out = '';
            $last = 0;
            foreach ($replacements as $index => $replacement) {
                if (!isset($records[$index])) {
                    continue;
                }
                $record = $records[$index];
                $offset = isset($record['offset']) ? (int) $record['offset'] : -1;
                $tag = isset($record['tag']) ? (string) $record['tag'] : '';
                if ($offset < 0 || '' === $tag) {
                    continue;
                }
                $out .= substr($html, $last, $offset - $last) . $replacement;
                $last = $offset + strlen($tag);
            }

            return $out . substr($html, $last);
        }

        private function restore_delayed_script_record_tag(array $record)
        {
            $tag = isset($record['tag']) ? (string) $record['tag'] : '';
            if ('' === $tag || false === stripos($tag, 'text/ucwp-delayed-js')) {
                return $tag;
            }

            if (!preg_match('/^<script\b[^>]*>(.*?)<\/script>$/is', $tag, $content_match) || !preg_match('/^<script\b[^>]*>/i', $tag, $open_match)) {
                return $tag;
            }

            $open = (string) $open_match[0];
            $content = isset($content_match[1]) ? (string) $content_match[1] : '';
            $attrs = $this->extract_html_tag_attributes($open);
            $preserved = $this->decode_delayed_script_preserved_attributes($attrs);

            foreach (array('id', 'nonce', 'crossorigin', 'referrerpolicy', 'integrity') as $attr) {
                $data_key = 'data-ucwp-' . $attr;
                if (!isset($preserved[$attr]) && isset($attrs[$data_key]) && '' !== $attrs[$data_key]) {
                    $preserved[$attr] = (string) $attrs[$data_key];
                }
            }

            $is_inline = !empty($record['inline_delayed']) || (isset($attrs['data-ucwp-inline']) && '1' === (string) $attrs['data-ucwp-inline']);
            if (!$is_inline) {
                $src = isset($record['src']) ? (string) $record['src'] : '';
                if ('' === $src && isset($attrs['data-ucwp-src'])) {
                    $src = (string) $attrs['data-ucwp-src'];
                }
                if ('' === $src && isset($attrs['data-ucwp-original-src'])) {
                    $src = (string) $attrs['data-ucwp-original-src'];
                }
                if ('' === $src) {
                    return $tag;
                }
                $preserved['src'] = $src;
            } else {
                unset($preserved['src']);
            }

            unset($preserved['type'], $preserved['async'], $preserved['defer'], $preserved['data-wp-strategy']);
            foreach (array_keys($preserved) as $name) {
                if (0 === strpos(strtolower((string) $name), 'data-ucwp-')) {
                    unset($preserved[$name]);
                }
            }

            $compiled = array();
            foreach ($preserved as $name => $value) {
                $name = strtolower(trim((string) $name));
                if ('' === $name || !preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $name)) {
                    continue;
                }
                if (true === $value || $value === $name) {
                    $compiled[] = esc_attr($name);
                    continue;
                }
                $compiled[] = esc_attr($name) . '="' . esc_attr((string) $value) . '"';
            }

            $open_restored = '<script' . (!empty($compiled) ? ' ' . implode(' ', $compiled) : '') . '>';
            return $open_restored . ($is_inline ? $content : '') . '</script>';
        }

        private function decode_delayed_script_preserved_attributes(array $attrs)
        {
            $encoded = isset($attrs['data-ucwp-attrs']) ? (string) $attrs['data-ucwp-attrs'] : '';
            if ('' === $encoded) {
                return array();
            }

            $decoded = base64_decode($encoded, true);
            if (!is_string($decoded) || '' === $decoded) {
                return array();
            }

            $json = json_decode($decoded, true);
            if (!is_array($json)) {
                return array();
            }

            $out = array();
            foreach ($json as $name => $value) {
                $name = strtolower(trim((string) $name));
                if ('' === $name || 0 === strpos($name, 'data-ucwp-')) {
                    continue;
                }
                if (is_scalar($value)) {
                    $out[$name] = (string) $value;
                }
            }

            return $out;
        }

        private function collect_script_dependency_records_from_html($html)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<script')) {
                return array();
            }

            if (!preg_match_all('/<script\b[^>]*>.*?<\/script>/is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                return array();
            }

            $records = array();
            foreach ($matches as $index => $match) {
                $tag = isset($match[0][0]) ? (string) $match[0][0] : '';
                $offset = isset($match[0][1]) ? (int) $match[0][1] : -1;
                if ('' === $tag || $offset < 0 || !preg_match('/^<script\b[^>]*>/i', $tag, $open_match)) {
                    continue;
                }

                $open = (string) $open_match[0];
                $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($open, 'src'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ('' === $src) {
                    $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($open, 'data-ucwp-src'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                if ('' === $src) {
                    $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($open, 'data-ucwp-original-src'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }

                $id = (string) $this->extract_attribute_from_html_tag($open, 'id');
                if ('' === $id) {
                    $id = (string) $this->extract_attribute_from_html_tag($open, 'data-ucwp-id');
                }

                $type = strtolower((string) $this->extract_attribute_from_html_tag($open, 'type'));
                $is_delayed = (false !== stripos($type, 'ucwp-delayed') || false !== stripos($open, 'data-ucwp-src=') || false !== stripos($open, 'data-ucwp-inline=') || false !== stripos($open, 'data-ucwp-delayed'));

                $code = (string) preg_replace('/^<script\b[^>]*>|<\/script>$/is', '', $tag);
                if ('' === $id && '' !== trim($code) && preg_match('/#\s*sourceURL\s*=\s*([^\s\r\n<]+)/i', $code, $source_url_match)) {
                    $source_id = trim((string) $source_url_match[1]);
                    $source_id = preg_replace('/[?#].*$/', '', $source_id);
                    $source_id = basename((string) $source_id);
                    if (is_string($source_id) && '' !== $source_id) {
                        $id = sanitize_text_field(substr($source_id, 0, 160));
                    }
                }

                $handle = $this->infer_script_handle_from_tag($open, $src);
                if ('' === $handle && '' !== $src) {
                    $handle = $src;
                }
                if ('' === $handle && '' !== $id) {
                    $handle = $id;
                }

                $records[$index] = array(
                    'tag' => $tag,
                    'open' => $open,
                    'offset' => $offset,
                    'src' => $src,
                    'id' => $id,
                    'handle' => $handle,
                    'group' => $this->normalize_delayed_script_group_handle($handle),
                    'has_src' => ('' !== $src),
                    'delayed' => (bool) $is_delayed,
                    'inline_delayed' => (bool) (false !== stripos($open, 'data-ucwp-inline=') || false !== stripos($open, 'data-ucwp-inline="1"') || false !== stripos($open, "data-ucwp-inline='1'")),
                    'code' => ('' === $src) ? $code : '',
                );
            }

            return $records;
        }

        private function get_wp_inline_dependency_protected_script_groups(array $records)
        {
            $inline_groups = array();
            $protected = array();
            $ordered_indexes = array_keys($records);
            sort($ordered_indexes);

            foreach ($records as $index => $record) {
                if (!empty($record['has_src'])) {
                    continue;
                }
                $group = isset($record['group']) ? (string) $record['group'] : '';
                if ('' === $group) {
                    continue;
                }
                if (!$this->is_wp_inline_dependency_script_record($record)) {
                    continue;
                }
                $inline_groups[$group] = true;
            }

            foreach ($records as $index => $record) {
                if (empty($record['has_src'])) {
                    continue;
                }
                $group = isset($record['group']) ? (string) $record['group'] : '';
                if ('' !== $group && !empty($inline_groups[$group])) {
                    $protected[$group] = true;
                    continue;
                }

                $nearby = $this->get_nearby_inline_dependency_groups($records, $ordered_indexes, (int) $index);
                foreach ($nearby as $nearby_group) {
                    if ('' !== $nearby_group) {
                        if ('' !== $group) {
                            $protected[$group] = true;
                        }
                        $protected[$nearby_group] = true;
                    }
                }
            }

            return $protected;
        }

        private function get_user_excluded_script_dependency_groups(array $records, array $settings = array())
        {
            $protected = array();
            $matched_indexes = array();
            $ordered_indexes = array_keys($records);
            sort($ordered_indexes);

            foreach ($records as $index => $record) {
                if (!$this->script_record_matches_user_defer_exclusion($record, $settings)) {
                    continue;
                }

                $matched_indexes[(int) $index] = true;
                $group = isset($record['group']) ? (string) $record['group'] : '';
                if ('' !== $group) {
                    $protected[$group] = true;
                }
            }

            /*
             * Do not add hidden nearby/defining-script/jQuery cluster rules.
             * If those dependencies are needed, Populate Defaults adds visible
             * editable entries such as jquery, jquery-migrate, functions.js,
             * 360imagerotate.js, and WooCommerce/Google Analytics tokens.
             */
            return $protected;
        }

        private function add_script_record_dependency_protection(array &$protected, array $records, array $ordered_indexes, $index, $radius = 3)
        {
            if (!isset($records[$index])) {
                return;
            }

            $group = isset($records[$index]['group']) ? (string) $records[$index]['group'] : '';
            if ('' !== $group) {
                $protected[$group] = true;
            }

            $nearby_groups = $this->get_nearby_script_dependency_groups($records, $ordered_indexes, (int) $index, (int) $radius);
            foreach ($nearby_groups as $nearby_group) {
                if ('' !== $nearby_group) {
                    $protected[$nearby_group] = true;
                }
            }
        }

        private function add_user_exclusion_defining_script_protection(array &$protected, array $records, array $ordered_indexes, array $matched_indexes, array $settings = array())
        {
            $fragments = $this->get_defer_stage_user_exclude_fragments($settings);
            $definition_fragments = $this->get_definition_candidate_fragments_from_user_exclusions($fragments);
            if (empty($definition_fragments)) {
                return;
            }

            foreach ($records as $index => $record) {
                if (isset($matched_indexes[(int) $index]) || empty($record['has_src'])) {
                    continue;
                }

                if (!$this->script_record_matches_definition_candidate_fragments($record, $definition_fragments)) {
                    continue;
                }

                $this->add_script_record_dependency_protection($protected, $records, $ordered_indexes, (int) $index, 4);
            }
        }

        private function get_definition_candidate_fragments_from_user_exclusions(array $fragments)
        {
            $candidates = array();
            foreach ($fragments as $fragment) {
                $fragment = trim((string) $fragment);
                if ('' === $fragment) {
                    continue;
                }

                $lc = strtolower($fragment);
                $normalized = $this->normalize_js_fragment_match_text($lc);
                if (strlen($normalized) < 6) {
                    continue;
                }

                $tokens = preg_split('/[^a-z0-9]+/i', $fragment, -1, PREG_SPLIT_NO_EMPTY);
                if (empty($tokens) || 1 === count($tokens)) {
                    $camel = preg_replace('/(?<!^)([A-Z])/', ' $1', $fragment);
                    $tokens = preg_split('/[^a-z0-9]+/i', (string) $camel, -1, PREG_SPLIT_NO_EMPTY);
                }

                $clean_tokens = array();
                foreach ((array) $tokens as $token) {
                    $token = strtolower(trim((string) $token));
                    if (strlen($token) >= 3 && !in_array($token, array('params', 'data', 'min', 'script', 'function'), true)) {
                        $clean_tokens[] = $token;
                    }
                }

                $candidates[$lc] = $lc;
                $candidates[$normalized] = $normalized;

                if (count($clean_tokens) >= 2) {
                    $dash = implode('-', $clean_tokens);
                    $plain = implode('', $clean_tokens);
                    $candidates[$dash] = $dash;
                    $candidates[$plain] = $plain;
                }

            }

            return array_values(array_unique(array_filter($candidates, static function ($candidate) {
                return is_string($candidate) && strlen($candidate) >= 6;
            })));
        }

        private function script_record_matches_definition_candidate_fragments(array $record, array $fragments)
        {
            $haystacks = array(
                isset($record['handle']) ? (string) $record['handle'] : '',
                isset($record['src']) ? (string) $record['src'] : '',
                (string) wp_parse_url(isset($record['src']) ? (string) $record['src'] : '', PHP_URL_PATH),
                isset($record['id']) ? (string) $record['id'] : '',
                isset($record['open']) ? (string) $record['open'] : '',
            );

            $normalized_haystacks = array();
            foreach ($haystacks as $haystack) {
                $haystack = strtolower(trim((string) $haystack));
                if ('' === $haystack) {
                    continue;
                }
                $normalized_haystacks[] = $haystack;
                $normalized_haystacks[] = $this->normalize_js_fragment_match_text($haystack);
            }

            foreach ($fragments as $fragment) {
                $fragment = strtolower(trim((string) $fragment));
                if ('' === $fragment) {
                    continue;
                }
                $normalized_fragment = $this->normalize_js_fragment_match_text($fragment);
                foreach ($normalized_haystacks as $haystack) {
                    if ('' !== $haystack && (false !== strpos($haystack, $fragment) || (strlen($normalized_fragment) >= 6 && false !== strpos($haystack, $normalized_fragment)))) {
                        return true;
                    }
                }
            }

            return false;
        }

        private function matched_exclusions_need_jquery_legacy_cluster(array $records, array $matched_indexes, array $settings = array())
        {
            $fragments = strtolower(implode(' ', array_map('strval', $this->get_defer_stage_user_exclude_fragments($settings))));
            $legacy_markers = array(
                'jquery',
                'jquery-migrate',
                'functions.js',
                'themes/',
                '/wp-content/themes/',
                'woocommerce-coupon-box',
                'wcb_params',
                'elementor',
            );
            foreach ($legacy_markers as $marker) {
                if (false !== strpos($fragments, $marker)) {
                    return true;
                }
            }

            foreach ($matched_indexes as $index => $_) {
                if (empty($records[$index])) {
                    continue;
                }
                $record = $records[$index];
                $haystack = strtolower(
                    (isset($record['handle']) ? (string) $record['handle'] : '') . ' ' .
                    (isset($record['src']) ? (string) $record['src'] : '') . ' ' .
                    (isset($record['tag']) ? (string) $record['tag'] : '') . ' ' .
                    (isset($record['code']) ? (string) $record['code'] : '')
                );
                if (false !== strpos($haystack, '/wp-content/themes/') || false !== strpos($haystack, 'jquery')) {
                    return true;
                }
            }

            return false;
        }


private function script_record_matches_user_defer_exclusion(array $record, array $settings = array())
        {
            $fragments = $this->get_defer_stage_user_exclude_fragments($settings);
            if (empty($fragments)) {
                return false;
            }

            $handle = isset($record['handle']) ? (string) $record['handle'] : '';
            $src = isset($record['src']) ? (string) $record['src'] : '';
            if ($this->script_matches_fragment_list($handle, $src, $fragments)) {
                return true;
            }

            $haystacks = array(
                isset($record['id']) ? (string) $record['id'] : '',
                isset($record['group']) ? (string) $record['group'] : '',
                isset($record['open']) ? (string) $record['open'] : '',
                isset($record['tag']) ? (string) $record['tag'] : '',
                isset($record['code']) ? (string) $record['code'] : '',
            );

            $normalized_haystacks = array();
            foreach ($haystacks as $haystack) {
                $haystack_lc = strtolower((string) $haystack);
                if ('' !== $haystack_lc) {
                    foreach ($fragments as $fragment) {
                        $fragment = strtolower(trim((string) $fragment));
                        if ('' !== $fragment && false !== strpos($haystack_lc, $fragment)) {
                            return true;
                        }
                    }
                    $normalized_haystacks[] = $this->normalize_js_fragment_match_text($haystack_lc);
                }
            }

            foreach ($fragments as $fragment) {
                $fragment = strtolower(trim((string) $fragment));
                if ('' === $fragment) {
                    continue;
                }
                $normalized_fragment = $this->normalize_js_fragment_match_text($fragment);
                if (strlen($normalized_fragment) < 4) {
                    continue;
                }
                foreach ($normalized_haystacks as $normalized_haystack) {
                    if ('' !== $normalized_haystack && false !== strpos($normalized_haystack, $normalized_fragment)) {
                        return true;
                    }
                }
            }

            return false;
        }

        private function get_nearby_script_dependency_groups(array $records, array $ordered_indexes, $index, $radius = 2)
        {
            $groups = array();
            $position = array_search($index, $ordered_indexes, true);
            if (false === $position) {
                return $groups;
            }

            $radius = max(1, (int) $radius);
            for ($distance = 1; $distance <= $radius; $distance++) {
                foreach (array($position - $distance, $position + $distance) as $near_position) {
                    if (!isset($ordered_indexes[$near_position])) {
                        continue;
                    }
                    $near_index = $ordered_indexes[$near_position];
                    if (!isset($records[$near_index])) {
                        continue;
                    }
                    $near_group = isset($records[$near_index]['group']) ? (string) $records[$near_index]['group'] : '';
                    if ('' !== $near_group) {
                        $groups[$near_group] = $near_group;
                    }
                }
            }

            return array_values($groups);
        }

        private function get_nearby_inline_dependency_groups(array $records, array $ordered_indexes, $index)
        {
            $groups = array();
            $position = array_search($index, $ordered_indexes, true);
            if (false === $position) {
                return $groups;
            }

            foreach (array($position - 1, $position + 1) as $near_position) {
                if (!isset($ordered_indexes[$near_position])) {
                    continue;
                }
                $near_index = $ordered_indexes[$near_position];
                if (!isset($records[$near_index]) || !empty($records[$near_index]['has_src'])) {
                    continue;
                }
                if (!$this->is_wp_inline_dependency_script_record($records[$near_index])) {
                    continue;
                }
                $near_group = isset($records[$near_index]['group']) ? (string) $records[$near_index]['group'] : '';
                if ('' !== $near_group) {
                    $groups[$near_group] = $near_group;
                }
            }

            return array_values($groups);
        }

        private function is_wp_inline_dependency_script_record(array $record)
        {
            if (!empty($record['has_src'])) {
                return false;
            }

            $id = strtolower(trim(isset($record['id']) ? (string) $record['id'] : ''));
            if ('' !== $id && preg_match('/(?:^|-)js-(?:extra|before|after)$/', $id)) {
                return true;
            }

            $tag = isset($record['tag']) ? (string) $record['tag'] : '';
            if ('' === $tag || !$this->is_delayable_inline_script_tag($tag)) {
                return false;
            }

            $code = (string) preg_replace('/^<script\b[^>]*>|<\/script>$/is', '', $tag);
            if ('' === trim($code)) {
                return false;
            }

            $markers = array(
                'var ',
                'const ',
                'let ',
                'window.',
                'wp.i18n.setLocaleData',
                'wp_add_inline_script',
            );
            foreach ($markers as $marker) {
                if (false !== stripos($code, $marker)) {
                    return true;
                }
            }

            return false;
        }

        private function apply_script_loading_attribute_mutations_with_processor($html, array $mutations)
        {
            if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || empty($mutations)) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor($html);
                $index = 0;
                $changed = false;

                while ($processor->next_tag('SCRIPT')) {
                    if (!isset($mutations[$index])) {
                        $index++;
                        continue;
                    }

                    $action = (string) $mutations[$index];
                    if ('strip-loading' === $action) {
                        $processor->remove_attribute('async');
                        $processor->remove_attribute('defer');
                        $processor->remove_attribute('data-wp-strategy');
                        $changed = true;
                    } elseif ('defer' === $action) {
                        $processor->remove_attribute('async');
                        $processor->remove_attribute('data-wp-strategy');
                        $processor->set_attribute('defer', 'defer');
                        $changed = true;
                    } elseif ('async' === $action) {
                        $processor->remove_attribute('defer');
                        $processor->remove_attribute('data-wp-strategy');
                        $processor->set_attribute('async', 'async');
                        $changed = true;
                    }

                    $index++;
                }

                if (!$changed) {
                    return null;
                }

                $updated = $processor->get_updated_html();
                return is_string($updated) && '' !== $updated ? $updated : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        private function get_defer_stage_level(array $settings = array())
        {
            if (!empty($settings['defer_stage_aggressive']) || !empty($settings['delay_non_critical_js_aggressive'])) {
                return 3;
            }

            if (!empty($settings['defer_stage_balanced'])) {
                return 2;
            }

            if (!empty($settings['defer_stage_safe']) || !empty($settings['defer_js'])) {
                return 1;
            }

            return 0;
        }

        private function strip_native_loading_attributes_from_script_tag($tag)
        {
            $tag = (string) $tag;
            if ('' === $tag) {
                return $tag;
            }

            if (false === stripos($tag, ' async') && false === stripos($tag, ' defer') && false === stripos($tag, 'data-wp-strategy=')) {
                return $tag;
            }

            $tag = $this->remove_html_tag_attribute($tag, 'async');
            $tag = $this->remove_html_tag_attribute($tag, 'defer');
            $tag = $this->remove_html_tag_attribute($tag, 'data-wp-strategy');
            $tag = preg_replace('/\s{2,}/', ' ', $tag);

            return is_string($tag) ? $tag : '';
        }

        private function normalize_protected_script_loading_attributes_in_html($html, array $settings = array())
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<script')) {
                return $html;
            }

            $processed = $this->normalize_protected_script_loading_attributes_with_processor($html, $settings);
            return is_string($processed) ? $processed : $html;
        }

        private function normalize_protected_script_loading_attributes_with_processor($html, array $settings = array())
        {
            if (!$this->html_tag_processor_available()) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor((string) $html);
                $changed = false;
                $defer_all_js = !empty($settings['defer_js']) && !empty($settings['defer_all_js']);

                while ($processor->next_tag('SCRIPT')) {
                    $async = $processor->get_attribute('async');
                    $defer = $processor->get_attribute('defer');
                    $strategy = $processor->get_attribute('data-wp-strategy');
                    if (null === $async && null === $defer && null === $strategy) {
                        continue;
                    }

                    $handle = $processor->get_attribute('id');
                    $src = $processor->get_attribute('src');
                    $handle = (null === $handle || false === $handle) ? '' : html_entity_decode((string) $handle, ENT_QUOTES, 'UTF-8');
                    $src = (null === $src || false === $src) ? '' : html_entity_decode((string) $src, ENT_QUOTES, 'UTF-8');
                    $tag = $this->get_current_html_processor_tag_markup($processor, 'script');

                    if ($this->should_keep_script_blocking_for_defer_all($handle, $src, $tag, $settings)
                        || (!$defer_all_js && $this->is_script_force_blocking($handle, $src, $tag, $settings))) {
                        $processor->remove_attribute('async');
                        $processor->remove_attribute('defer');
                        $processor->remove_attribute('data-wp-strategy');
                        $changed = true;
                        continue;
                    }

                    if ($this->is_script_user_force_deferred($handle, $src, $tag, $settings)) {
                        $processor->remove_attribute('async');
                        $processor->remove_attribute('data-wp-strategy');
                        $processor->set_attribute('defer', 'defer');
                        $changed = true;
                        continue;
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


private function script_handle_has_inline_after_segments($handle)
        {
            $handle = (string) $handle;
            if ('' === $handle) {
                return false;
            }

            global $wp_scripts;
            if (!($wp_scripts instanceof WP_Scripts)) {
                return false;
            }

            $segment = $wp_scripts->get_data($handle, 'after');
            if (is_array($segment) && !empty($segment)) {
                return true;
            }

            return is_string($segment) && '' !== trim($segment);
        }

        private function script_handle_has_enqueued_dependents($handle)
        {
            $handle = (string) $handle;
            if ('' === $handle) {
                return false;
            }

            global $wp_scripts;
            if (!($wp_scripts instanceof WP_Scripts)) {
                return false;
            }

            $candidates = array();
            foreach (array('queue', 'to_do', 'done') as $property) {
                if (isset($wp_scripts->{$property}) && is_array($wp_scripts->{$property})) {
                    $candidates = array_merge($candidates, $wp_scripts->{$property});
                }
            }

            foreach (array_unique(array_filter(array_map('strval', $candidates))) as $candidate) {
                if ($candidate === $handle || empty($wp_scripts->registered[$candidate]) || empty($wp_scripts->registered[$candidate]->deps)) {
                    continue;
                }

                if (in_array($handle, array_map('strval', (array) $wp_scripts->registered[$candidate]->deps), true)) {
                    return true;
                }
            }

            return false;
        }

        private function add_defer_attribute_to_script_tag($tag, $force = false)
        {
            $tag = (string) $tag;
            if ('' === $tag || false === stripos($tag, '<script') || false === stripos($tag, ' src=')) {
                return $tag;
            }

            if (!$force && (false !== stripos($tag, ' defer') || false !== stripos($tag, ' async') || false !== stripos($tag, ' type="module"'))) {
                return $tag;
            }

            if ($force) {
                $tag = $this->remove_html_tag_attribute($tag, 'async');
                $tag = $this->remove_html_tag_attribute($tag, 'data-wp-strategy');
                $tag = preg_replace('/\s{2,}/', ' ', $tag);
            }

            if (false !== stripos($tag, ' defer')) {
                return $tag;
            }

            return $this->set_or_add_html_tag_attribute($tag, 'defer', 'defer');
        }

        private function add_async_attribute_to_script_tag($tag)
        {
            $tag = (string) $tag;
            if ('' === $tag) {
                return $tag;
            }

            if (false !== stripos($tag, ' async') || false !== stripos($tag, ' type="module"') || false !== stripos($tag, ' nomodule')) {
                return $tag;
            }

            if (false !== stripos($tag, ' defer')) {
                $tag = $this->remove_html_tag_attribute($tag, 'defer');
            }

            return $this->set_or_add_html_tag_attribute($tag, 'async', 'async');
        }

        private function should_async_external_script($handle, $src, $tag, array $settings = array())
        {
            $src = trim((string) $src);
            if ('' === $src || false === stripos((string) $tag, '<script')) {
                return false;
            }

            if (false !== stripos((string) $tag, ' async') || false !== stripos((string) $tag, ' type="module"') || false !== stripos((string) $tag, ' nomodule')) {
                return false;
            }

            $src_host = (string) wp_parse_url($src, PHP_URL_HOST);
            $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            if ('' === $src_host || '' === $home_host || strtolower($src_host) === strtolower($home_host)) {
                return false;
            }

            $haystack = strtolower((string) $handle . ' ' . $src . ' ' . $tag);
            $patterns = array(
                'googletagmanager.com',
                'google-analytics.com',
                'googleanalytics.com',
                'gtag/js',
                'googleadservices.com',
                'g.doubleclick.net',
                'connect.facebook.net',
                'facebook.com/tr',
                'bat.bing.com',
                'clarity.ms',
                'usefathom.com',
                'plausible.io',
                'analytics.tiktok.com',
                'static.hotjar.com',
                'script.hotjar.com',
                'snap.licdn.com',
                'px.ads.linkedin.com',
                'pinimg.com/ct/',
                'redditstatic.com/ads/',
                'mc.yandex.ru',
            );

            foreach ($patterns as $pattern) {
                if (false !== strpos($haystack, strtolower($pattern))) {
                    return true;
                }
            }

            return false;
        }

        private function is_script_optimization_excluded($handle, $src, $tag = '', array $settings = array())
        {
            return $this->is_script_force_blocking($handle, $src, $tag, $settings)
                || $this->is_script_user_defer_excluded($handle, $src, $settings)
                || $this->is_script_safe_stage_excluded($handle, $src, $tag, $settings);
        }

        private function is_defer_all_js_candidate($handle, $src, $tag = '', array $settings = array())
        {
            $src = trim((string) $src);
            $tag = (string) $tag;

            if ('' === $src || false === stripos($tag, '<script')) {
                return false;
            }

            if (false !== stripos($tag, ' async') || false !== stripos($tag, ' type="module"') || false !== stripos($tag, " type='module'") || false !== stripos($tag, ' nomodule')) {
                return false;
            }

            if (false !== stripos($tag, ' defer')) {
                return false;
            }

            return true;
        }

        private function is_script_absolute_defer_blocking($handle, $src, $tag = '', array $settings = array())
        {
            /*
             * Absolute dependency recommendations are no longer forced by a
             * hidden runtime list. Populate Defaults places those entries in
             * the visible JS Delay / Defer Exclusions textarea.
             */
            return false;
        }

        private function is_script_force_blocking($handle, $src, $tag = '', array $settings = array())
        {
            /*
             * Do not silently force-block core/WooCommerce/Elementor/theme
             * scripts. The user-visible JS Delay / Defer Exclusions list is the
             * authoritative safeguard list.
             */
            return false;
        }

        private function is_script_user_force_deferred($handle, $src, $tag = '', array $settings = array())
        {
            return $this->script_matches_force_defer_fragment_list($handle, $src, $tag, $this->get_force_defer_js_fragments($settings));
        }

        private function get_force_defer_js_fragments(array $settings = array())
        {
            $list = array();
            if (isset($settings['defer_js_force_list']) && is_array($settings['defer_js_force_list'])) {
                $list = array_merge($list, $settings['defer_js_force_list']);
            }

            return array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function script_matches_force_defer_fragment_list($handle, $src, $tag, array $fragments)
        {
            $haystacks = array(
                strtolower(trim((string) $handle)),
                strtolower(trim((string) $src)),
                strtolower((string) wp_parse_url((string) $src, PHP_URL_PATH)),
                strtolower((string) $tag),
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

        private function is_script_user_defer_excluded($handle, $src, array $settings = array())
        {
            return $this->script_matches_fragment_list($handle, $src, $this->get_defer_stage_user_exclude_fragments($settings));
        }

        private function is_script_safe_stage_excluded($handle, $src, $tag = '', array $settings = array())
        {
            /*
             * No hidden safe-stage exclusions. The existing visible JS Delay /
             * Defer Exclusions textarea is the only exclusion source; Populate
             * Defaults exposes recommended dependency fragments for users to
             * add, edit, save, or remove.
             */
            return $this->is_script_user_defer_excluded($handle, $src, $settings);
        }

        private function get_defer_stage_user_exclude_fragments(array $settings = array())
        {
            $list = array();

            if (isset($settings['defer_js_exclude_list']) && is_array($settings['defer_js_exclude_list'])) {
                $list = array_merge($list, $settings['defer_js_exclude_list']);
            }

            // Backward compatibility for sites that already saved the old separate Delay Non-Critical JS exclude list.
            if (isset($settings['delay_non_critical_js_exclude_list']) && is_array($settings['delay_non_critical_js_exclude_list'])) {
                $list = array_merge($list, $settings['delay_non_critical_js_exclude_list']);
            }

            $list = array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            })));

            // The visible JS Delay / Defer Exclusions list is the user's final
            // override for aggressive Defer all JS. Never strip legacy-looking
            // fragments here; if the user adds validation-messages.js, sr7,
            // elementor, or any other broad line, it must remain effective.
            return $list;
        }


private function get_safe_stage_defer_exclude_fragments(array $settings = array())
        {
            /*
             * Former built-in defer fragments are now surfaced through the
             * existing JS Delay / Defer Exclusions Populate Defaults payload.
             */
            return $this->get_defer_stage_user_exclude_fragments($settings);
        }

        private function get_defer_js_exclude_fragments(array $settings = array())
        {
            return $this->get_safe_stage_defer_exclude_fragments($settings);
        }


private function get_force_blocking_script_handles(array $settings = array())
        {
            /*
             * No hidden force-blocking handles. Recommended dependency handles
             * are exposed through JS Delay / Defer Exclusions Populate Defaults.
             */
            return array();
        }

        private function get_safe_stage_excluded_handles(array $settings = array())
        {
            /*
             * No hidden safe-stage handle list. Use the visible JS Delay /
             * Defer Exclusions textarea instead.
             */
            return array();
        }

        private function get_defer_excluded_handles(array $settings = array())
        {
            /*
             * Kept as an API surface for diagnostics. Runtime exclusions are
             * user-visible fragments only, not hidden handle lists.
             */
            return array();
        }

        private function is_same_host_public_url($url)
        {
            $url = trim((string) $url);
            if ('' === $url) {
                return false;
            }

            $absolute = $this->absolutize_public_resource_url($url, home_url('/'));
            if ('' === $absolute) {
                return false;
            }

            $src_host = (string) wp_parse_url($absolute, PHP_URL_HOST);
            $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            if ('' === $src_host || '' === $home_host) {
                return false;
            }

            return strtolower($src_host) === strtolower($home_host);
        }

        private function get_delay_non_critical_js_exclude_fragments()
        {
            $settings = $this->get_settings();
            $list = $this->get_defer_stage_user_exclude_fragments(is_array($settings) ? $settings : array());

            return array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }


private function script_handle_is_footer_group($handle)
        {
            $handle = (string) $handle;
            if ('' === $handle) {
                return false;
            }

            global $wp_scripts;
            if (!($wp_scripts instanceof WP_Scripts)) {
                return false;
            }

            $group = $wp_scripts->get_data($handle, 'group');
            return (is_numeric($group) && 1 <= (int) $group);
        }

        private function is_local_wp_content_script_url($src)
        {
            $path = strtolower((string) wp_parse_url((string) $src, PHP_URL_PATH));
            if ('' === $path) {
                $path = strtolower((string) $src);
            }

            return false !== strpos($path, '/wp-content/plugins/')
                || false !== strpos($path, '/wp-content/themes/')
                || false !== strpos($path, '/wp-content/uploads/');
        }

        private function script_matches_fragment_list($handle, $src, array $fragments)
        {
            $haystacks = array(
                strtolower(trim((string) $handle)),
                strtolower(trim((string) $src)),
                strtolower((string) wp_parse_url((string) $src, PHP_URL_PATH)),
            );

            $normalized_haystacks = array();
            foreach ($haystacks as $haystack) {
                if ('' !== $haystack) {
                    $normalized_haystacks[] = $this->normalize_js_fragment_match_text($haystack);
                }
            }

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

                $normalized_fragment = $this->normalize_js_fragment_match_text($fragment);
                if (strlen($normalized_fragment) < 4) {
                    continue;
                }
                foreach ($normalized_haystacks as $normalized_haystack) {
                    if ('' !== $normalized_haystack && false !== strpos($normalized_haystack, $normalized_fragment)) {
                        return true;
                    }
                }
            }

            return false;
        }

        private function normalize_js_fragment_match_text($value)
        {
            $value = strtolower((string) $value);
            $value = preg_replace('/[^a-z0-9_]+/', '', $value);

            return is_string($value) ? $value : '';
        }

        private function matches_non_critical_delay_patterns($handle, $src, $tag = '')
        {
            $haystack = strtolower((string) $handle . ' ' . $src . ' ' . $tag);
            $patterns = array(
                'cmplz',
                'complianz',
                'googlesitekit-events-provider',
                'google-site-kit',
                'sitekit',
                'mailerlite',
                'mc4wp',
                'sourcebuster',
                'order-attribution',
                'tooltipster',
                'magnific-popup',
                'perfect-scrollbar',
                'plainoverlay',
                'ion.range',
                'icheck',
                'easy-autocomplete',
                'jarallax',
                'tweenmax',
                'gsap',
                'sticky-kit',
                'slick',
                'swiper',
                'carousel',
                'slider',
                'popup',
                'modal',
                'lightbox',
                'off-canvas',
                'offcanvas',
                'search-popup',
                'ajax-search',
                'filter',
                'animation',
                'animate',
            );

            foreach ($patterns as $pattern) {
                if (false !== strpos($haystack, strtolower($pattern))) {
                    return true;
                }
            }

            return false;
        }

        private function should_delay_non_critical_script($handle, $src, $tag, array $settings = array())
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

            if ($this->script_matches_fragment_list($handle, $src, $this->get_delay_non_critical_js_exclude_fragments())) {
                return false;
            }

            if ($this->script_handle_has_inline_after_segments($handle)) {
                return false;
            }

            if ($this->is_script_force_blocking($handle, $src, $tag, $settings)) {
                return false;
            }

            if ($this->script_handle_has_enqueued_dependents($handle)) {
                return false;
            }

            $handle_lc = strtolower((string) $handle);
            $src_lc    = strtolower((string) $src);

            $forced_blocking_handles = array(
                'elementor-webpack-runtime',
                'elementor-pro-webpack-runtime',
                'elementor-frontend-js',
                'elementor-pro-frontend-js',
                'contact-form-7-js',
                'author-arc-handler-js',
            );

            if (in_array($handle_lc, $forced_blocking_handles, true)) {
                return false;
            }

            if (false !== strpos($src_lc, '/plugins/woocommerce/assets/')) {
                return false;
            }

            if (!empty($settings['critical_request_chain_relief']) && $this->script_matches_fragment_list($handle, $src, $this->get_critical_request_chain_delay_fragments($settings))) {
                return true;
            }

            if ($this->matches_non_critical_delay_patterns($handle, $src, $tag)) {
                return true;
            }

            if (empty($settings['delay_non_critical_js_aggressive'])) {
                return false;
            }

            if (!$this->is_local_wp_content_script_url($src)) {
                return false;
            }

            return $this->script_handle_is_footer_group($handle);
        }

        private function should_delay_script($handle, $src, $tag, array $settings = array())
        {
            $match = $this->get_third_party_delay_match($handle, $src, $tag, $settings);
            return !empty($match['matched']);
        }

        private function get_third_party_delay_match($handle, $src, $tag, array $settings = array())
        {
            $src = trim((string) $src);
            $tag = (string) $tag;

            if ('' === $src || false === stripos($tag, '<script')) {
                return array('matched' => false);
            }

            if (!$this->is_delayable_external_script_tag($tag)) {
                return array('matched' => false);
            }

            if (false !== stripos($tag, 'type="text/ucwp-delayed-js"') || false !== stripos($tag, "type='text/ucwp-delayed-js'") || false !== stripos($tag, 'data-ucwp-src=')) {
                return array('matched' => false);
            }
            if ($this->is_third_party_delay_excluded($handle, $src, $settings)) {
                return array('matched' => false, 'reason' => 'excluded');
            }

            if ($this->should_native_defer_all_local_script($src, $settings)) {
                return array('matched' => false, 'reason' => 'native-defer-all-local');
            }

            if ($this->is_third_party_delay_dependency_library($handle, $src, $tag)) {
                return array('matched' => false, 'reason' => 'dependency-library');
            }

            if (!empty($settings['delay_safe_third_party_js'])) {
                $safe_pattern = $this->get_matching_third_party_delay_pattern($handle, $src, $tag, $this->get_safe_third_party_delay_patterns($settings));
                if ('' !== $safe_pattern) {
                    return array(
                        'matched' => true,
                        'category' => 'safe-third-party',
                        'reason' => 'safe-third-party',
                        'matched_pattern' => $safe_pattern,
                    );
                }
            }

            if (!empty($settings['delay_functional_third_party_js'])) {
                $functional_pattern = $this->get_matching_third_party_delay_pattern($handle, $src, $tag, $this->get_functional_third_party_delay_patterns($settings));
                if ('' !== $functional_pattern) {
                    return array(
                        'matched' => true,
                        'category' => 'functional-third-party',
                        'reason' => 'functional-third-party',
                        'matched_pattern' => $functional_pattern,
                    );
                }
            }

            if (!empty($settings['delay_all_third_party_js']) && $this->is_external_third_party_script_url($src)) {
                return array(
                    'matched' => true,
                    'category' => 'all-third-party',
                    'reason' => 'all-third-party',
                    'matched_pattern' => 'third-party-origin',
                );
            }

            return array('matched' => false);
        }

        private function get_inline_third_party_delay_match($handle, $tag, array $settings = array())
        {
            $tag = (string) $tag;
            if ('' === $tag || false === stripos($tag, '<script')) {
                return array('matched' => false);
            }

            if (!$this->is_delayable_inline_script_tag($tag)) {
                return array('matched' => false);
            }

            $handle = (string) $handle;
            $haystacks = array(
                strtolower(trim($handle)),
                strtolower($tag),
            );

            if (!empty($settings['delay_safe_third_party_js'])) {
                foreach ($this->get_safe_third_party_delay_patterns($settings) as $pattern) {
                    $pattern = strtolower(trim((string) $pattern));
                    if ('' === $pattern) {
                        continue;
                    }
                    foreach ($haystacks as $haystack) {
                        if ('' !== $haystack && false !== strpos($haystack, $pattern)) {
                            return array(
                                'matched' => true,
                                'category' => 'safe-third-party',
                                'reason' => 'safe-third-party',
                                'matched_pattern' => $pattern,
                            );
                        }
                    }
                }

                $safe_inline_markers = array(
                    'gtag(',
                    'dataLayer',
                    'gtm.start',
                    'googletagmanager.com/gtm.js',
                    'googletagmanager.com/gtag/js',
                    'google-analytics.com',
                    'fbq(',
                    'connect.facebook.net',
                    'pintrk(',
                    'clarity(',
                    'hotjar',
                );

                foreach ($safe_inline_markers as $marker) {
                    foreach ($haystacks as $haystack) {
                        if ('' !== $haystack && false !== strpos($haystack, strtolower($marker))) {
                            return array(
                                'matched' => true,
                                'category' => 'safe-third-party',
                                'reason' => 'safe-third-party',
                                'matched_pattern' => strtolower($marker),
                            );
                        }
                    }
                }
            }

            if (!empty($settings['delay_functional_third_party_js'])) {
                foreach ($this->get_functional_third_party_delay_patterns($settings) as $pattern) {
                    $pattern = strtolower(trim((string) $pattern));
                    if ('' === $pattern) {
                        continue;
                    }
                    foreach ($haystacks as $haystack) {
                        if ('' !== $haystack && false !== strpos($haystack, $pattern)) {
                            return array(
                                'matched' => true,
                                'category' => 'functional-third-party',
                                'reason' => 'functional-third-party',
                                'matched_pattern' => $pattern,
                            );
                        }
                    }
                }
            }

            return array('matched' => false);
        }

        private function get_safe_third_party_delay_patterns(array $settings = array())
        {
            if (isset($settings['delay_safe_third_party_js_patterns']) && is_array($settings['delay_safe_third_party_js_patterns'])) {
                return array_values(array_unique(array_filter(array_map('strval', $settings['delay_safe_third_party_js_patterns']), static function ($item) {
                    return '' !== trim((string) $item);
                })));
            }

            return array();
        }

        private function get_functional_third_party_delay_patterns(array $settings = array())
        {
            if (isset($settings['delay_functional_third_party_js_patterns']) && is_array($settings['delay_functional_third_party_js_patterns'])) {
                return array_values(array_unique(array_filter(array_map('strval', $settings['delay_functional_third_party_js_patterns']), static function ($item) {
                    return '' !== trim((string) $item);
                })));
            }

            return array();
        }

        private function get_third_party_delay_exclude_fragments(array $settings = array())
        {
            $list = $this->get_defer_stage_user_exclude_fragments($settings);

            return array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function is_third_party_delay_excluded($handle, $src, array $settings = array())
        {
            return $this->script_matches_fragment_list($handle, $src, $this->get_third_party_delay_exclude_fragments($settings));
        }

        private function is_third_party_delay_dependency_library($handle, $src, $tag = '')
        {
            $src_path = strtolower((string) wp_parse_url((string) $src, PHP_URL_PATH));
            $haystack = strtolower(trim((string) $handle . ' ' . (string) $src . ' ' . $src_path . ' ' . (string) $tag));
            if ('' === $haystack) {
                return false;
            }

            $dependency_patterns = array(
                'js-cookie',
                'js.cookie',
                'jquery.cookie',
                '/sourcebuster',
                'sourcebuster-js',
                'wc-order-attribution',
                'order-attribution',
                'wc-cart-fragments',
                'cart-fragments',
            );

            foreach ($dependency_patterns as $pattern) {
                if (false !== strpos($haystack, $pattern)) {
                    return true;
                }
            }

            return false;
        }

        private function is_external_third_party_script_url($src)
        {
            $src = trim((string) $src);
            if ('' === $src) {
                return false;
            }

            $absolute = $this->absolutize_public_resource_url($src, home_url('/'));
            if ('' === $absolute) {
                $absolute = $src;
            }

            $src_host = strtolower((string) wp_parse_url((string) $absolute, PHP_URL_HOST));
            $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
            if ('' === $src_host || '' === $home_host) {
                return false;
            }

            return $src_host !== $home_host;
        }

        private function get_matching_third_party_delay_pattern($handle, $src, $tag, array $patterns)
        {
            $haystacks = array(
                strtolower(trim((string) $handle)),
                strtolower(trim((string) $src)),
                strtolower((string) wp_parse_url((string) $src, PHP_URL_HOST)),
                strtolower((string) wp_parse_url((string) $src, PHP_URL_PATH)),
                strtolower((string) $tag),
            );

            foreach ($patterns as $pattern) {
                $pattern = strtolower(trim((string) $pattern));
                if ('' === $pattern) {
                    continue;
                }

                foreach ($haystacks as $haystack) {
                    if ('' !== $haystack && false !== strpos($haystack, $pattern)) {
                        return $pattern;
                    }
                }
            }

            return '';
        }

        private function build_delayed_script_tag($tag, $handle, $src, $reason = '')
        {
            $original_attributes = $this->extract_html_tag_attributes($tag);
            $preserved_attributes = array();

            foreach ($original_attributes as $name => $value) {
                $name_lc = strtolower((string) $name);
                if (in_array($name_lc, array('src', 'async', 'defer', 'data-wp-strategy'), true)) {
                    continue;
                }

                if ('type' === $name_lc && !$this->is_javascript_mime_type((string) $value)) {
                    continue;
                }

                if (0 === strpos($name_lc, 'data-ucwp-')) {
                    continue;
                }

                $preserved_attributes[$name_lc] = (string) $value;
            }

            $delayed_src = $this->absolutize_public_resource_url($src, home_url('/'));
            if ('' === $delayed_src) {
                $delayed_src = (string) $src;
            }

            $attributes = array(
                'type'                   => 'text/ucwp-delayed-js',
                'data-ucwp-src'          => esc_url($delayed_src),
                'data-ucwp-original-src' => esc_attr((string) $src),
                'data-ucwp-handle'       => esc_attr((string) $handle),
            );

            $reason = sanitize_key((string) $reason);
            if ('' !== $reason) {
                $attributes['data-ucwp-delay-reason'] = esc_attr($reason);
            }

            if (!empty($preserved_attributes)) {
                $encoded = base64_encode((string) wp_json_encode($preserved_attributes));
                if ('' !== $encoded) {
                    $attributes['data-ucwp-attrs'] = esc_attr($encoded);
                }
            }

            foreach (array('id', 'crossorigin', 'referrerpolicy', 'integrity', 'nonce') as $attribute) {
                if (isset($preserved_attributes[$attribute]) && '' !== $preserved_attributes[$attribute]) {
                    $attributes['data-ucwp-' . $attribute] = esc_attr($preserved_attributes[$attribute]);
                }
            }

            $compiled = array();
            foreach ($attributes as $name => $value) {
                $compiled[] = sprintf('%s="%s"', $name, $value);
            }

            return '<script ' . implode(' ', $compiled) . '></script>';
        }

        private function normalize_delayed_script_group_handle($handle)
        {
            $handle = strtolower(trim((string) $handle));
            if ('' === $handle) {
                return '';
            }

            $handle = preg_replace('/-js(?:-extra|-before|-after|-translations)?$/', '', $handle);
            $handle = preg_replace('/-(?:extra|before|after)$/', '', (string) $handle);
            $handle = preg_replace('/\.min\.js$|\.js$/', '', (string) $handle);

            return is_string($handle) ? trim($handle) : '';
        }

        private function is_delayable_inline_script_tag($tag)
        {
            $tag = (string) $tag;
            if ('' === $tag || false === stripos($tag, '<script')) {
                return false;
            }

            if (false !== stripos($tag, 'id="ucwp-runtime-js-scan-collector"') || false !== stripos($tag, "id='ucwp-runtime-js-scan-collector'") || false !== stripos($tag, '__ucwpRuntimeJsScan')) {
                return false;
            }

            if (false !== stripos($tag, ' src=') || false !== stripos($tag, ' data-ucwp-src=') || false !== stripos($tag, 'text/ucwp-delayed-js')) {
                return false;
            }

            $type = strtolower(trim((string) $this->extract_attribute_from_html_tag($tag, 'type')));
            if ('' !== $type && !$this->is_javascript_mime_type($type)) {
                return false;
            }

            $code = trim((string) preg_replace('/^<script\b[^>]*>|<\/script>$/is', '', $tag));
            if ('' === $code) {
                return false;
            }

            if (false !== stripos($code, '__ucwpDelayLoader') || false !== stripos($code, 'wp-emoji-settings') || false !== stripos($code, '_wpemojiSettings')) {
                return false;
            }

            return true;
        }

        private function build_delayed_inline_script_tag($tag, $handle, $reason = '')
        {
            $tag = (string) $tag;
            if ('' === $tag || !preg_match('/^<script\b[^>]*>(.*?)<\/script>$/is', $tag, $content_match)) {
                return $tag;
            }

            $content = isset($content_match[1]) ? (string) $content_match[1] : '';
            $original_attributes = $this->extract_html_tag_attributes($tag);
            $preserved_attributes = array();

            foreach ($original_attributes as $name => $value) {
                $name_lc = strtolower((string) $name);
                if (in_array($name_lc, array('src', 'async', 'defer', 'data-wp-strategy'), true)) {
                    continue;
                }
                if ('type' === $name_lc && !$this->is_javascript_mime_type((string) $value)) {
                    continue;
                }
                if (0 === strpos($name_lc, 'data-ucwp-')) {
                    continue;
                }
                $preserved_attributes[$name_lc] = (string) $value;
            }

            $attributes = array(
                'type'             => 'text/ucwp-delayed-js',
                'data-ucwp-inline' => '1',
                'data-ucwp-handle' => esc_attr((string) $handle),
            );

            $reason = sanitize_key((string) $reason);
            if ('' !== $reason) {
                $attributes['data-ucwp-delay-reason'] = esc_attr($reason);
            }

            if (!empty($preserved_attributes)) {
                $encoded = base64_encode((string) wp_json_encode($preserved_attributes));
                if ('' !== $encoded) {
                    $attributes['data-ucwp-attrs'] = esc_attr($encoded);
                }
            }

            foreach (array('id', 'nonce') as $attribute) {
                if (isset($preserved_attributes[$attribute]) && '' !== $preserved_attributes[$attribute]) {
                    $attributes['data-ucwp-' . $attribute] = esc_attr($preserved_attributes[$attribute]);
                }
            }

            $compiled = array();
            foreach ($attributes as $name => $value) {
                $compiled[] = sprintf('%s="%s"', $name, $value);
            }

            return '<script ' . implode(' ', $compiled) . '>' . $content . '</script>';
        }

        private function extract_html_tag_attributes($tag)
        {
            $attributes = array();
            $tag = (string) $tag;
            if ('' === $tag || false === strpos($tag, '<')) {
                return $attributes;
            }

            $processed = $this->extract_html_tag_attributes_with_processor($tag);
            if (is_array($processed)) {
                return $processed;
            }

            $inside = preg_replace('/^\s*<[a-zA-Z][a-zA-Z0-9:-]*\b/i', '', $tag, 1);
            $inside = preg_replace('/>.*$/s', '', is_string($inside) ? $inside : '');
            if (!is_string($inside) || '' === trim($inside)) {
                return $attributes;
            }

            if (preg_match_all('/\s+([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/i', $inside, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (empty($match[1])) {
                        continue;
                    }

                    $name = strtolower((string) $match[1]);
                    $value = '';
                    if (isset($match[2]) && '' !== $match[2]) {
                        $value = (string) $match[2];
                    } elseif (isset($match[3]) && '' !== $match[3]) {
                        $value = (string) $match[3];
                    } elseif (isset($match[4]) && '' !== $match[4]) {
                        $value = (string) $match[4];
                    } else {
                        $value = $name;
                    }

                    $attributes[$name] = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
                }
            }

            return $attributes;
        }

        private function extract_html_tag_attributes_with_processor($tag)
        {
            if (!$this->html_tag_processor_available()) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor((string) $tag);
                if (!$processor->next_tag()) {
                    return null;
                }

                $attributes = array();
                $tag_markup = $this->get_current_html_processor_tag_markup($processor, (string) $processor->get_tag());
                if (preg_match_all('/\s+([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/i', $tag_markup, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        if (empty($match[1])) {
                            continue;
                        }

                        $name = strtolower((string) $match[1]);
                        $value = $processor->get_attribute($name);
                        if (true === $value) {
                            $attributes[$name] = $name;
                        } elseif (false === $value || null === $value) {
                            $attributes[$name] = '';
                        } else {
                            $attributes[$name] = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
                        }
                    }
                }

                return $attributes;
            } catch (\Throwable $e) {
                return null;
            }
        }

        private function is_delayable_external_script_tag($tag)
        {
            $tag = (string) $tag;
            if (false !== stripos($tag, ' nomodule')) {
                return false;
            }

            $type = $this->extract_attribute_from_html_tag($tag, 'type');
            if ('' === $type) {
                return true;
            }

            return $this->is_javascript_mime_type($type);
        }

        private function is_javascript_mime_type($type)
        {
            $type = strtolower(trim((string) $type));
            if ('' === $type) {
                return true;
            }

            $type = preg_replace('/\s*;.*$/', '', $type);
            return in_array($type, array(
                'text/javascript',
                'application/javascript',
                'application/ecmascript',
                'text/ecmascript',
                'text/jscript',
                'application/x-javascript',
            ), true);
        }

        public function cleanup_asset_chain_enqueue_assets()
        {
            if (is_admin()) {
                return;
            }

            $settings = $this->get_settings();
            if (empty($settings['asset_chain_cleanup'])) {
                return;
            }

            if ($this->current_request_matches_asset_cleanup_exclusion($settings)) {
                return;
            }

            if (!empty($settings['asset_cleanup_woo_product_assets']) && !$this->is_runtime_single_product_context()) {
                $this->dequeue_matching_queued_assets('script', $this->get_woocommerce_product_asset_cleanup_fragments());
                $this->dequeue_matching_queued_assets('style', $this->get_woocommerce_product_asset_cleanup_fragments());
            }

            if (!empty($settings['asset_cleanup_product_filter_assets']) && !$this->is_runtime_product_filter_context()) {
                $this->dequeue_matching_queued_assets('script', $this->get_product_filter_asset_cleanup_fragments());
                $this->dequeue_matching_queued_assets('style', $this->get_product_filter_asset_cleanup_fragments());
            }

            if (!empty($settings['asset_cleanup_woo_blocks_css']) && !$this->is_runtime_woocommerce_context()) {
                $this->dequeue_matching_queued_assets('style', array('wc-blocks.css', 'wc-blocks-style', 'woocommerce-blocks'));
            }
        }

        private function dequeue_matching_queued_assets($type, array $fragments)
        {
            $type = ('style' === $type) ? 'style' : 'script';
            $registry = ('style' === $type) ? wp_styles() : wp_scripts();
            if (!$registry || empty($registry->queue) || !is_array($registry->queue)) {
                return;
            }

            foreach ((array) $registry->queue as $handle) {
                $src = '';
                if (isset($registry->registered[$handle]) && is_object($registry->registered[$handle])) {
                    $src = (string) ($registry->registered[$handle]->src ?? '');
                }

                if (!$this->asset_matches_fragment_list($handle, $src, $fragments)) {
                    continue;
                }

                if ('style' === $type) {
                    wp_dequeue_style($handle);
                } else {
                    wp_dequeue_script($handle);
                }
            }
        }

        private function is_runtime_single_product_context()
        {
            return function_exists('is_product') && is_product();
        }

        private function is_runtime_woocommerce_context()
        {
            if (function_exists('is_product') && is_product()) {
                return true;
            }
            if (function_exists('is_shop') && is_shop()) {
                return true;
            }
            if (function_exists('is_product_taxonomy') && is_product_taxonomy()) {
                return true;
            }
            if (function_exists('is_cart') && is_cart()) {
                return true;
            }
            if (function_exists('is_checkout') && is_checkout()) {
                return true;
            }
            if (function_exists('is_account_page') && is_account_page()) {
                return true;
            }

            return false;
        }

        private function is_runtime_product_filter_context()
        {
            if (function_exists('is_shop') && is_shop()) {
                return true;
            }
            if (function_exists('is_product_taxonomy') && is_product_taxonomy()) {
                return true;
            }

            return false;
        }

        private function get_woocommerce_product_asset_cleanup_fragments()
        {
            return array(
                'jquery.zoom',
                'jquery.flexslider',
                'photoswipe',
                'photoswipe-ui-default',
                'wc-single-product',
                'single-product.min.js',
                'add-to-cart-variation',
                'wc-add-to-cart-variation',
                '/woocommerce/assets/js/frontend/single-product',
                '/woocommerce/assets/js/frontend/add-to-cart-variation',
                '/woocommerce/assets/js/zoom/',
                '/woocommerce/assets/js/flexslider/',
                '/woocommerce/assets/js/photoswipe/',
                '/woocommerce/assets/css/photoswipe',
            );
        }

        private function get_product_filter_asset_cleanup_fragments()
        {
            // Keep this list plugin-specific. Broad fragments such as tooltipster,
            // icheck, html_types/slider, or by_sku can also belong to unrelated UI.
            return array(
                'handle:woocommerce-products-filter',
                'handle:woof',
                'handle:woof_',
                'handle:woof-',
                'src:/plugins/woocommerce-products-filter/',
                'src:/plugins/woof-products-filter/',
                'src:/plugins/woocommerce-filter/',
                'src:/plugins/woocommerce-product-filter/',
                'src:/plugins/woocommerce-products-filter/js/',
                'src:/plugins/woocommerce-products-filter/ext/',
                'src:/plugins/woocommerce-products-filter/views/',
                'src:/plugins/woocommerce-products-filter/css/',
            );
        }

        private function asset_matches_fragment_list($handle, $src, array $fragments)
        {
            $handle_lc = strtolower(trim((string) $handle));
            $src_lc = strtolower(trim((string) $src));
            $path_lc = strtolower((string) wp_parse_url((string) $src, PHP_URL_PATH));
            $haystacks = array($handle_lc, $src_lc, $path_lc);

            foreach ($fragments as $fragment) {
                $fragment = strtolower(trim((string) $fragment));
                if ('' === $fragment) {
                    continue;
                }

                if (0 === strpos($fragment, 'handle:')) {
                    $needle = trim(substr($fragment, 7));
                    if ('' !== $needle && '' !== $handle_lc && false !== strpos($handle_lc, $needle)) {
                        return true;
                    }
                    continue;
                }

                if (0 === strpos($fragment, 'src:')) {
                    $needle = trim(substr($fragment, 4));
                    if ('' !== $needle && (('' !== $src_lc && false !== strpos($src_lc, $needle)) || ('' !== $path_lc && false !== strpos($path_lc, $needle)))) {
                        return true;
                    }
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

        public function print_delayed_script_loader()
        {
            $settings = $this->get_settings();
            if ((empty($settings['delay_safe_third_party_js']) && empty($settings['delay_functional_third_party_js']) && empty($settings['delay_all_third_party_js']) && empty($settings['delay_non_critical_js']) && empty($settings['lcp_boundary_defer'])) || is_admin()) {
                return;
            }

            $main_thread_relief = !empty($settings['main_thread_relief']) ? '1' : '0';
            $loader = <<<'UCWP_DELAY_LOADER'
<script id="ucwp-delayed-loader" data-ucwp-loader-policy="third-party-after-load" data-ucwp-relief="__UCWP_RELIEF__">(function(){if(window.__ucwpDelayLoader){return;}window.__ucwpDelayLoader=1;var relief=__UCWP_RELIEF__;var timeoutMs=8000;var localAutoDone=false;var thirdPartyAutoDone=false;var allDone=false;var started=Date.now?Date.now():0;function root(){return document.documentElement||document.body||document.head;}function mark(k,v){try{var r=root();if(r){r.setAttribute('data-ucwp-delay-'+k,String(v));}}catch(e){}}function qa(){return Array.prototype.slice.call(document.querySelectorAll('script[type="text/ucwp-delayed-js"][data-ucwp-src],script[type="text/ucwp-delayed-js"][data-ucwp-inline="1"]'));}function c(n,a){var v=n&&n.getAttribute?n.getAttribute('data-ucwp-'+a):'';return v||'';}function reason(n){return c(n,'delay-reason');}function isThirdPartyDelayed(n){var r=reason(n);return r==='safe-third-party'||r==='functional-third-party'||r==='all-third-party';}function q(mode){return qa().filter(function(n){if(!n||n.getAttribute('data-ucwp-loading')==='1'||n.getAttribute('data-ucwp-loaded')==='1'){return false;}if(mode==='thirdparty'){return isThirdPartyDelayed(n);}if(mode==='local'){return !isThirdPartyDelayed(n);}return true;});}function counts(){var all=qa(),tp=0,local=0;for(var i=0;i<all.length;i++){if(isThirdPartyDelayed(all[i])){tp++;}else{local++;}}mark('queued',all.length);mark('queued-local',local);mark('queued-thirdparty',tp);}function decodeAttrs(node){var raw=c(node,'attrs');var attrs={};if(raw){try{attrs=JSON.parse(atob(raw))||{};}catch(e){attrs={};}}['id','crossorigin','referrerpolicy','integrity','nonce'].forEach(function(attr){var val=c(node,attr);if(val&&!attrs[attr]){attrs[attr]=val;}});return attrs;}function applyAttrs(s,node){var attrs=decodeAttrs(node);Object.keys(attrs).forEach(function(attr){var val=attrs[attr];if(!attr||attr==='src'||attr==='async'||attr==='defer'||attr==='data-wp-strategy'||val===null||typeof val==='undefined'){return;}try{s.setAttribute(attr,String(val));}catch(e){}});}function idle(cb){if(!relief){cb();return;}if('requestIdleCallback' in window){window.requestIdleCallback(cb,{timeout:1200});return;}setTimeout(cb,60);}function wait(ms,cb){if(!relief||ms<=0){cb();return;}setTimeout(cb,ms);}function emit(name,detail){try{window.dispatchEvent(new CustomEvent(name,{detail:detail||{}}));}catch(e){}}function insertAndRemove(node,s){if(node.parentNode){node.parentNode.insertBefore(s,node);node.parentNode.removeChild(node);}else{(document.head||document.body||document.documentElement).appendChild(s);}}function loadOne(node,done){if(!node||node.getAttribute('data-ucwp-loading')==='1'||node.getAttribute('data-ucwp-loaded')==='1'){done();return;}node.setAttribute('data-ucwp-loading','1');var isInline=node.getAttribute('data-ucwp-inline')==='1';var src=node.getAttribute('data-ucwp-src');var s=document.createElement('script');applyAttrs(s,node);s.async=false;if(isInline){try{s.text=node.textContent||'';}catch(e){s.text='';}insertAndRemove(node,s);node.setAttribute('data-ucwp-loaded','1');done();return;}if(!src){done();return;}var finished=false;function finish(){if(finished){return;}finished=true;node.setAttribute('data-ucwp-loaded','1');done();}s.onload=finish;s.onerror=finish;setTimeout(finish,timeoutMs);s.src=src;insertAndRemove(node,s);}function load(list,i,mode){if(i>=list.length){mark(mode+'-done','1');emit('ucwp:delayed-scripts-done',{mode:mode,count:list.length});return;}idle(function(){loadOne(list[i],function(){wait(relief?80:0,function(){load(list,i+1,mode);});});});}function run(mode){counts();var list=q(mode);if(!list.length){mark(mode+'-done','empty');return;}mark(mode+'-started','1');mark(mode+'-count',list.length);emit('ucwp:delayed-scripts-start',{mode:mode,count:list.length});load(list,0,mode);}function triggerAll(){if(allDone){return;}allDone=true;localAutoDone=true;thirdPartyAutoDone=true;run('all');}function triggerLocal(){if(allDone||localAutoDone){return;}localAutoDone=true;run('local');}function triggerThirdParty(){if(allDone||thirdPartyAutoDone){return;}thirdPartyAutoDone=true;run('thirdparty');}function afterDomReady(cb,delay){if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',function(){setTimeout(cb,delay||0);},{once:true});}else{setTimeout(cb,delay||0);}}function afterLoad(cb,delay){if(document.readyState==='complete'){setTimeout(cb,delay||0);return;}window.addEventListener('load',function(){setTimeout(cb,delay||0);},{once:true});}counts();mark('loader','active');mark('policy','third-party-after-load');mark('started-ms',started);['scroll','mousemove','touchstart','keydown','click','pointerdown'].forEach(function(evt){window.addEventListener(evt,triggerAll,{passive:true,once:true});});afterDomReady(triggerLocal,relief?1800:900);setTimeout(function(){if(document.readyState==='complete'){triggerLocal();}},relief?6500:4500);afterLoad(triggerThirdParty,relief?4500:2500);setTimeout(function(){if(document.readyState==='complete'){triggerThirdParty();}},relief?18000:12000);}());</script>
UCWP_DELAY_LOADER;
            $loader = str_replace('__UCWP_RELIEF__', $main_thread_relief, $loader);
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline loader script with a validated numeric placeholder.
            echo $loader . "\n";
        }

        private function apply_delayed_script_replacements_with_processor($html, array $records, array $replacements)
        {
            if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || empty($records) || empty($replacements)) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor((string) $html);
                $index = 0;
                $changed = false;

                while ($processor->next_tag('SCRIPT')) {
                    if (!isset($records[$index]) || !isset($replacements[$index])) {
                        $index++;
                        continue;
                    }

                    $replacement = (string) $replacements[$index];
                    $replacement_attributes = $this->extract_html_tag_attributes($replacement);
                    if (empty($replacement_attributes)) {
                        $index++;
                        continue;
                    }

                    $record = $records[$index];
                    $open = isset($record['open']) ? (string) $record['open'] : '<script>';
                    $original_attributes = $this->extract_html_tag_attributes($open);
                    foreach (array_keys($original_attributes) as $attribute) {
                        $attribute = strtolower(trim((string) $attribute));
                        if ('' !== $attribute) {
                            $processor->remove_attribute($attribute);
                        }
                    }

                    foreach (array('src', 'async', 'defer', 'data-wp-strategy', 'type', 'id', 'crossorigin', 'referrerpolicy', 'integrity', 'nonce') as $attribute) {
                        $processor->remove_attribute($attribute);
                    }

                    foreach ($replacement_attributes as $attribute => $value) {
                        $attribute = strtolower(trim((string) $attribute));
                        if ('' === $attribute) {
                            continue;
                        }

                        $processor->set_attribute($attribute, (string) $value);
                    }

                    $changed = true;
                    $index++;
                }

                if (!$changed) {
                    return null;
                }

                $updated = $processor->get_updated_html();
                return is_string($updated) && '' !== $updated ? $updated : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        private function delay_third_party_analytics_scripts_in_html($html, array $settings = array())
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<script') || (empty($settings['delay_safe_third_party_js']) && empty($settings['delay_functional_third_party_js']) && empty($settings['delay_all_third_party_js']))) {
                return $html;
            }

            if (!preg_match_all('/<script\b[^>]*>.*?<\/script>/is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                return $html;
            }

            $records = array();
            foreach ($matches as $index => $match) {
                $tag = isset($match[0][0]) ? (string) $match[0][0] : '';
                $offset = isset($match[0][1]) ? (int) $match[0][1] : -1;
                if ('' === $tag || $offset < 0 || !preg_match('/^<script\b[^>]*>/i', $tag, $open_match)) {
                    continue;
                }

                $open = (string) $open_match[0];
                $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($open, 'src'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ('' === $src) {
                    $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($open, 'data-ucwp-src'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                if ('' === $src) {
                    $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($open, 'data-ucwp-original-src'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }

                $id = (string) $this->extract_attribute_from_html_tag($open, 'id');
                if ('' === $id) {
                    $id = (string) $this->extract_attribute_from_html_tag($open, 'data-ucwp-id');
                }
                $code = (string) preg_replace('/^<script\b[^>]*>|<\/script>$/is', '', $tag);
                if ('' === $id && '' !== trim($code) && preg_match('/#\s*sourceURL\s*=\s*([^\s\r\n<]+)/i', $code, $source_url_match)) {
                    $source_id = trim((string) $source_url_match[1]);
                    $source_id = preg_replace('/[?#].*$/', '', $source_id);
                    $source_id = basename((string) $source_id);
                    if (is_string($source_id) && '' !== $source_id) {
                        $id = sanitize_text_field(substr($source_id, 0, 160));
                    }
                }

                $type = strtolower((string) $this->extract_attribute_from_html_tag($open, 'type'));
                $is_delayed = (false !== stripos($type, 'ucwp-delayed') || false !== stripos($open, 'data-ucwp-src=') || false !== stripos($open, 'data-ucwp-inline=') || false !== stripos($open, 'data-ucwp-delayed'));
                $handle = $this->infer_script_handle_from_tag($open, $src);
                if ('' === $handle && '' !== $src) {
                    $handle = $src;
                }
                if ('' === $handle && '' !== $id) {
                    $handle = $id;
                }

                $records[$index] = array(
                    'tag'     => $tag,
                    'open'    => $open,
                    'offset'  => $offset,
                    'src'     => $src,
                    'id'      => $id,
                    'handle'  => $handle,
                    'group'   => $this->normalize_delayed_script_group_handle($handle),
                    'has_src' => ('' !== $src),
                    'delayed' => (bool) $is_delayed,
                    'inline_delayed' => (bool) (false !== stripos($open, 'data-ucwp-inline=') || false !== stripos($open, 'data-ucwp-inline="1"') || false !== stripos($open, "data-ucwp-inline='1'")),
                    'code'    => ('' === $src) ? $code : '',
                );
            }

            if (empty($records)) {
                return $html;
            }

            $protected_groups = $this->get_user_excluded_script_dependency_groups($records, $settings);
            $replacements = array();
            foreach ($records as $index => $record) {
                if (empty($record['has_src']) || '' === $record['src']) {
                    continue;
                }

                $record_group = isset($record['group']) ? (string) $record['group'] : '';
                if ($this->script_record_matches_user_defer_exclusion($record, $settings) || ('' !== $record_group && !empty($protected_groups[$record_group]))) {
                    continue;
                }

                $match = $this->get_third_party_delay_match($record['handle'], $record['src'], $record['open'], $settings);
                if (empty($match['matched'])) {
                    continue;
                }

                $reason = isset($match['reason']) ? (string) $match['reason'] : 'third-party';
                $replacements[$index] = $this->build_delayed_script_tag($record['open'], $record['handle'], $record['src'], $reason);

                if ('' === $record['group']) {
                    continue;
                }

                foreach ($records as $inline_index => $inline_record) {
                    if ($inline_index === $index || !empty($inline_record['has_src']) || isset($replacements[$inline_index])) {
                        continue;
                    }
                    $inline_group = isset($inline_record['group']) ? (string) $inline_record['group'] : '';
                    if ('' !== $inline_group && !empty($protected_groups[$inline_group])) {
                        continue;
                    }
                    if ('' === $inline_record['group'] || $inline_record['group'] !== $record['group']) {
                        continue;
                    }
                    if (!$this->is_delayable_inline_script_tag($inline_record['tag'])) {
                        continue;
                    }
                    $replacements[$inline_index] = $this->build_delayed_inline_script_tag($inline_record['tag'], $inline_record['handle'], $reason);
                }
            }

            foreach ($records as $inline_index => $inline_record) {
                if (!empty($inline_record['has_src']) || isset($replacements[$inline_index])) {
                    continue;
                }
                $inline_group = isset($inline_record['group']) ? (string) $inline_record['group'] : '';
                if ($this->script_record_matches_user_defer_exclusion($inline_record, $settings) || ('' !== $inline_group && !empty($protected_groups[$inline_group]))) {
                    continue;
                }
                if (!$this->is_delayable_inline_script_tag($inline_record['tag'])) {
                    continue;
                }

                /*
                 * WordPress attached inline scripts must not be delayed as
                 * standalone snippets. They are handle-bound config/data blocks
                 * (for example {handle}-js-before / {handle}-js-after) and must
                 * follow the parent external script decision. Delaying only the
                 * inline block while the parent remains parser-loaded breaks
                 * ordered loaders such as WooCommerce Google Analytics.
                 */
                if ($this->is_wp_inline_dependency_script_record($inline_record)) {
                    continue;
                }

                $inline_match = $this->get_inline_third_party_delay_match($inline_record['handle'], $inline_record['tag'], $settings);
                if (empty($inline_match['matched'])) {
                    continue;
                }
                $inline_reason = isset($inline_match['reason']) ? (string) $inline_match['reason'] : 'third-party';
                $replacements[$inline_index] = $this->build_delayed_inline_script_tag($inline_record['tag'], $inline_record['handle'], $inline_reason);
            }

            if (empty($replacements)) {
                return $html;
            }

            ksort($replacements);
            $processed = $this->apply_delayed_script_replacements_with_processor($html, $records, $replacements);

            return is_string($processed) ? $processed : $html;
        }

        private function infer_script_handle_from_tag($tag, $src = '')
        {
            $handle = $this->extract_attribute_from_html_tag($tag, 'data-ucwp-handle');
            $handle = trim((string) $handle);
            if ('' !== $handle) {
                return $handle;
            }

            $id = $this->extract_attribute_from_html_tag($tag, 'id');
            $id = trim((string) $id);
            if ('' === $id) {
                $id = trim((string) $this->extract_attribute_from_html_tag($tag, 'data-ucwp-id'));
            }
            if ('' !== $id) {
                $id = preg_replace('/-js(?:-extra|-before|-after|-translations)?$/', '', $id);
                return is_string($id) ? $id : '';
            }

            $path = (string) wp_parse_url((string) $src, PHP_URL_PATH);
            $base = basename($path);
            if ('' === $base) {
                return '';
            }

            return preg_replace('/\.min\.js$|\.js$/i', '', $base);
        }

    }
}
