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

                $content = ucwp_safe_file_get_contents($local_path);
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

                $content = ucwp_safe_file_get_contents($local_path);
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
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('sr7-module', 'sr7-slide', 'revslider', '/plugins/revslider/', 'themepunch', 'rs-module', 'wp-block-themepunch-revslider'),
                    'suggestions' => array('revslider', 'sr7', 'tptools', 'tp-tools', 'rs6', 'rs-module'),
                    'reason' => 'Slider Revolution / SR7 assets or markup were detected on this page. Keep slider runtime assets out of Delay JS unless visually tested.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('swiper', 'swiper-container', 'swiper-wrapper', '/swiper/', 'elementor-widget-slides', 'elementor-widget-image-carousel', 'elementor-widget-media-carousel'),
                    'suggestions' => array('swiper', 'swiper-bundle'),
                    'reason' => 'Swiper slider/carousel assets or markup were detected on this page. Keep these runtime assets protected unless visually tested.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('slick', 'slick-slider', 'slick-track', 'slick.min.js'),
                    'suggestions' => array('slick'),
                    'reason' => 'Slick carousel assets or markup were detected on this page. Keep carousel runtime assets protected unless visually tested.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('splide', 'splide__track', 'splide.min.js'),
                    'suggestions' => array('splide'),
                    'reason' => 'Splide slider assets or markup were detected on this page. Keep slider runtime assets protected unless visually tested.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('owl.carousel', 'owl-carousel', 'owl-stage', 'owlCarousel'),
                    'suggestions' => array('owl.carousel', 'owl-carousel'),
                    'reason' => 'Owl Carousel assets or markup were detected on this page. Keep carousel runtime assets protected unless visually tested.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('smartslider', 'smart-slider', 'n2-ss', 'nextend'),
                    'suggestions' => array('smartslider', 'n2-ss'),
                    'reason' => 'Smart Slider / Nextend assets or markup were detected on this page. Keep slider runtime assets protected unless visually tested.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('layerslider', 'layer-slider', 'masterslider', 'master-slider', 'metaslider', 'soliloquy', 'royalslider', 'sliderpro', 'flickity', 'glide'),
                    'suggestions' => array('layerslider', 'masterslider', 'metaslider', 'soliloquy', 'royalslider', 'sliderpro', 'flickity', 'glide'),
                    'reason' => 'Known slider/carousel assets or markup were detected on this page. Keep matching runtime assets protected unless visually tested.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('elementor', 'elementor-widget', '/plugins/elementor/', '/plugins/elementor-pro/'),
                    'suggestions' => array('elementor', 'elementor-frontend', 'elementor-pro', 'frontend-modules', 'webpack.runtime'),
                    'reason' => 'Elementor assets or widgets were detected on this page. Keep core Elementor runtime dependencies protected unless dependency-safe testing passes.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('et_pb_', 'et-builder', 'et-core', '/themes/Divi/', '/plugins/divi-builder/'),
                    'suggestions' => array('divi', 'et-core', 'et-builder', 'et_pb', 'cmplz_activated_divi_recaptcha'),
                    'reason' => 'Divi builder assets or markup were detected on this page. Keep Divi runtime dependencies protected unless dependency-safe testing passes.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('js_composer', 'wpb_', 'vc_', 'wpbakery'),
                    'suggestions' => array('wpbakery', 'js_composer', 'vc_', 'wpb_'),
                    'reason' => 'WPBakery/Visual Composer assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('bricks', 'bricks-frontend', 'brxe-'),
                    'suggestions' => array('bricks', 'bricks-frontend', 'brxe-'),
                    'reason' => 'Bricks Builder assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('oxygen', 'ct-section', 'ct-div-block', 'ct-inner-content'),
                    'suggestions' => array('oxygen', 'ct-', 'oxy-'),
                    'reason' => 'Oxygen Builder assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('fl-builder', 'beaver-builder'),
                    'suggestions' => array('fl-builder', 'beaver-builder'),
                    'reason' => 'Beaver Builder assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('fusion-builder', 'avada-', 'fusion-'),
                    'suggestions' => array('fusion-builder', 'avada', 'fusion-'),
                    'reason' => 'Avada/Fusion Builder assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('thrive-', 'tcb-', 'tve_'),
                    'suggestions' => array('thrive', 'tcb-', 'tve_'),
                    'reason' => 'Thrive builder assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('seedprod', 'siteorigin', 'so-widget', 'uagb-', 'spectra', 'kadence', 'kt-', 'generateblocks', 'gb-'),
                    'suggestions' => array('seedprod', 'siteorigin', 'uagb', 'spectra', 'kadence', 'generateblocks'),
                    'reason' => 'Known block/page-builder assets or markup were detected on this page. Keep matching runtime assets protected unless visually tested.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('complianz', 'cmplz', 'complianz-gdpr', 'complianz-gdpr-premium'),
                    'suggestions' => array('complianz', 'cmplz', 'complianz-gdpr/cookiebanner/js/complianz.min.js', 'complianz-gdpr-premium/cookiebanner/js/complianz.min.js', 'complianz-gdpr-premium/pro/tcf/build/index.js', 'complianz-gdpr-premium/pro/tcf-stub/build/index.js'),
                    'reason' => 'Complianz consent assets were detected on this page. Consent/cookie scripts should stay out of Delay JS to avoid banner or consent-state issues.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('cookieyes', 'cookielawinfo', 'cky-', 'cookiebot', 'uc.js', 'iubenda', 'onetrust', 'optanon'),
                    'suggestions' => array('cookieyes', 'cookielawinfo', 'cky-', 'cookiebot', 'iubenda', 'onetrust', 'optanon'),
                    'reason' => 'Cookie/consent-management assets were detected on this page. Consent scripts are safer when excluded from Delay JS.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('google.com/recaptcha', 'gstatic.com/recaptcha', 'grecaptcha', 'hcaptcha', 'hcaptcha.com', 'turnstile', 'challenges.cloudflare.com', 'cf-turnstile'),
                    'suggestions' => array('google.com/recaptcha', 'gstatic.com/recaptcha', 'grecaptcha', 'hcaptcha', 'turnstile', 'challenges.cloudflare.com', 'cf-turnstile'),
                    'reason' => 'Captcha/anti-bot assets were detected on this page. These are commonly unsafe to delay because forms may need them immediately.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('contact-form-7', 'wpforms', 'gform', 'gravityforms', 'formidable', 'ninja-forms', 'fluentform', 'forminator', 'mailerlite', 'mailchimp', 'mc4wp', 'klaviyo', 'hubspot'),
                    'suggestions' => array('contact-form-7', 'wpforms', 'gform', 'gravityforms', 'formidable', 'ninja-forms', 'fluentform', 'forminator', 'mailerlite', 'validation-messages', 'mailchimp', 'mc4wp', 'klaviyo', 'hubspot'),
                    'reason' => 'Form, validation, newsletter, or CRM assets were detected on this page. Exclude matching form runtime assets if the form must work before interaction.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('js.stripe.com', 'stripe', 'paypal.com/sdk/js', 'paypal', 'braintree', 'klarna', 'afterpay', 'squareup', 'square-web-payments'),
                    'suggestions' => array('js.stripe.com', 'stripe', 'paypal.com/sdk/js', 'paypal', 'braintree', 'klarna', 'afterpay', 'square'),
                    'reason' => 'Payment gateway assets were detected on this page. Payment/checkout scripts are safer when excluded from Delay JS.',
                ),
                array(
                    'category' => 'review-only',
                    'label' => 'Review-only candidates',
                    'confidence' => 'review',
                    'appendable' => false,
                    'markers' => array('woocommerce', 'wc-', 'cart', 'checkout', 'account', 'add-to-cart', 'wc-cart-fragments'),
                    'suggestions' => array('woocommerce', 'wc-', 'cart', 'checkout', 'account', 'add-to-cart', 'wc-cart-fragments'),
                    'reason' => 'WooCommerce/cart/account markers were detected. Review these before excluding broadly because shop pages vary by site.',
                ),
                array(
                    'category' => 'review-only',
                    'label' => 'Review-only candidates',
                    'confidence' => 'review',
                    'appendable' => false,
                    'markers' => array('googletagmanager.com', 'google-analytics.com/analytics.js', 'gtag', 'gtm', 'dataLayer', 'adsbygoogle', 'doubleclick', 'facebook.net', 'fbevents', 'connect.facebook.net', 'tiktok', 'pinterest', 'hotjar', 'clarity', 'stats.wp.com', '_stq'),
                    'suggestions' => array('gtag', 'gtm', 'dataLayer', 'adsbygoogle', 'stats.wp.com/e-', '_stq', 'facebook.net', 'fbevents', 'hotjar', 'clarity'),
                    'reason' => 'Tracking/ads scripts were detected. These are review-only because delaying them often improves performance but may affect analytics/ads timing.',
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

        public function defer_scripts($tag, $handle, $src)
        {
            $settings = $this->get_settings();
            if (is_admin()) {
                return $tag;
            }

            $defer_stage = $this->get_defer_stage_level($settings);
            $defer_all_js = !empty($settings['defer_js']) && !empty($settings['defer_all_js']);

            if (0 < $defer_stage && $this->is_script_absolute_defer_blocking($handle, $src, $tag, $settings)) {
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
            return $this->is_script_absolute_defer_blocking($handle, $src, $tag, $settings)
                || $this->is_script_user_defer_excluded($handle, $src, $settings);
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

            $that = $this;
            $rewritten = preg_replace_callback('/<script\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1[^>]*>\s*<\/script>/is', static function ($matches) use ($that, $settings) {
                $tag = isset($matches[0]) ? (string) $matches[0] : '';
                if ('' === $tag) {
                    return $tag;
                }

                $handle = $that->extract_attribute_from_html_tag($tag, 'id');
                $src = $that->extract_attribute_from_html_tag($tag, 'src');

                if ($that->should_keep_script_blocking_for_defer_all($handle, $src, $tag, $settings)) {
                    return $that->strip_native_loading_attributes_from_script_tag($tag);
                }

                if (!$that->is_defer_all_js_candidate($handle, $src, $tag, $settings)) {
                    return $tag;
                }

                return $that->add_defer_attribute_to_script_tag($tag, false);
            }, $html);

            return is_string($rewritten) ? $rewritten : $html;
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
            if (is_string($processed)) {
                return $processed;
            }

            $that = $this;
            $defer_all_js = !empty($settings['defer_js']) && !empty($settings['defer_all_js']);
            $rewritten = preg_replace_callback('/<script\b[^>]*>/i', static function ($matches) use ($that, $settings, $defer_all_js) {
                $tag = isset($matches[0]) ? (string) $matches[0] : '';
                if ('' === $tag) {
                    return $tag;
                }

                if (false === stripos($tag, ' async') && false === stripos($tag, ' defer') && false === stripos($tag, 'data-wp-strategy=')) {
                    return $tag;
                }

                $handle = $that->extract_attribute_from_html_tag($tag, 'id');
                $src = $that->extract_attribute_from_html_tag($tag, 'src');

                if ($that->should_keep_script_blocking_for_defer_all($handle, $src, $tag, $settings)
                    || (!$defer_all_js && $that->is_script_force_blocking($handle, $src, $tag, $settings))) {
                    return $that->strip_native_loading_attributes_from_script_tag($tag);
                }

                if ($that->is_script_user_force_deferred($handle, $src, $tag, $settings)) {
                    return $that->add_defer_attribute_to_script_tag($tag, true);
                }

                return $tag;
            }, $html);

            return is_string($rewritten) ? $rewritten : $html;
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

        private function script_handle_has_inline_segments($handle)
        {
            $handle = (string) $handle;
            if ('' === $handle) {
                return false;
            }

            global $wp_scripts;
            if (!($wp_scripts instanceof WP_Scripts)) {
                return false;
            }

            foreach (array('before', 'after', 'data') as $key) {
                $segment = $wp_scripts->get_data($handle, $key);
                if (is_array($segment) && !empty($segment)) {
                    return true;
                }
                if (is_string($segment) && '' !== trim($segment)) {
                    return true;
                }
            }

            return false;
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
            $handle = (string) $handle;
            $src    = (string) $src;
            $tag    = (string) $tag;

            $handle_lc = strtolower($handle);
            $src_lc    = strtolower($src);
            $tag_lc    = strtolower($tag);
            $haystack  = $handle_lc . ' ' . $src_lc . ' ' . $tag_lc;

            $absolute_patterns = array(
                'jquery',
                'jquery-core',
                'jquery-migrate',
                'wp-hooks',
                'wp-i18n',
                'wp-util',
                'wp-api',
                'api-fetch',
                'underscore',
                'backbone',
                'heartbeat',
                'wp-dom-ready',
                'wp-a11y',
                'wp-components',
                'wp-element',
                'wp-data',
                'wp-compose',
            );

            foreach ($absolute_patterns as $pattern) {
                if (false !== strpos($haystack, $pattern)) {
                    return true;
                }
            }

            return false;
        }

        private function is_script_force_blocking($handle, $src, $tag = '', array $settings = array())
        {
            $handle = (string) $handle;
            $src    = (string) $src;
            $tag    = (string) $tag;

            $handle_lc = strtolower($handle);
            $src_lc    = strtolower($src);
            $tag_lc    = strtolower($tag);
            $haystack  = $handle_lc . ' ' . $src_lc . ' ' . $tag_lc;

            if (in_array($handle_lc, array_map('strtolower', $this->get_force_blocking_script_handles($settings)), true)) {
                return true;
            }

            if ($this->script_handle_has_inline_segments($handle)) {
                return true;
            }

            if (0 === strpos($handle_lc, 'wp-') || 0 === strpos($handle_lc, 'wc-')) {
                return true;
            }

            if (false !== strpos($src_lc, '/wp-includes/js/')) {
                return true;
            }

            $patterns = array(
                'jquery',
                'wp-hooks',
                'wp-i18n',
                'wp-util',
                'wp-api',
                'wp-polyfill',
                'underscore',
                'backbone',
                'jquery/ui',
                'heartbeat',
                '/plugins/woocommerce/assets/js/frontend/cart-fragments',
                '/plugins/woocommerce/assets/js/frontend/add-to-cart',
                '/plugins/woocommerce/assets/js/frontend/checkout',
                '/plugins/woocommerce/assets/js/frontend/single-product',
                '/plugins/woocommerce/assets/js/selectwoo',
                'wc-cart',
                'wc-checkout',
                'wc-add-to-cart',
                'wc-single-product',
                'wc-country-select',
                'wc-address-i18n',
                'wc-credit-card-form',
                'selectwoo',
                'elementor',
                'elementor-frontend',
                'elementor-frontend-modules',
                'frontend-modules',
                'elementor-webpack-runtime',
                'elementor-pro-webpack-runtime',
                'pro-elements-handlers',
                'swiper',
                'swiper-bundle',            );

            foreach ($patterns as $pattern) {
                if (false !== strpos($haystack, $pattern)) {
                    return true;
                }
            }

            if (!empty($settings['woo_safe_mode'])) {
                $woo_patterns = array(
                    'woocommerce',
                    '/plugins/woocommerce/assets/js/',
                    'js-cookie',
                    'sourcebuster-js',
                    'wc-order-attribution',
                    'order-attribution',
                );

                foreach ($woo_patterns as $pattern) {
                    if (false !== strpos($haystack, $pattern)) {
                        return true;
                    }
                }
            }

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
            $handle_lc = strtolower((string) $handle);
            if (in_array($handle_lc, array_map('strtolower', $this->get_safe_stage_excluded_handles($settings)), true)) {
                return true;
            }

            return $this->script_matches_fragment_list($handle, $src, $this->get_safe_stage_defer_exclude_fragments($settings));
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

        private function get_defer_all_js_legacy_conservative_exclude_fragments()
        {
            return array(
                'official-mailerlite-sign-up-forms/assets/js/localization/validation-messages.js',
                'revslider',
                'sliderrevolution',
                'slider-revolution',
                'revolution',
                'sr7',
                'rs6',
                'rs7',
                'tptools',
                'tp-tools',
                'rs-module',
                'wp-block-themepunch-revslider',
                'swiper',
                'swiper-bundle',
                'slick',
                'splide',
                'owl.carousel',
                'smartslider',
                'smart-slider',
                'n2-ss',
                'elementor',
                'elementor-frontend',
                'frontend-modules',
                'webpack.runtime',
                'webpack-pro.runtime',
                'pro-elements-handlers',
                'smartmenus',
                'html_types/image',
                'html_types/color',
                'html_types/label',
                'html_types/slide',
                'html_types/slider',
                'product-ajax-search',
                'search-popup',
                'nav-mobile',
                'megamenu',
                'header-cart',
                'cart-canvas',
                'off-canvas',
                'woocommerce-products-filter',
                'woof_',
                'contact-form-7',
                'author-arc',
                'typewriting-author-arc/assets/js/form-handler.js',
                'mailerlite',
                'mailchimp',
                'mc4wp',
                'complianz',
                'cmplz',
                'cky-',
            );
        }

        private function get_safe_stage_defer_exclude_fragments(array $settings = array())
        {
            $list = $this->get_builtin_defer_js_exclude_fragments();
            $list = array_merge($list, $this->get_defer_stage_user_exclude_fragments($settings));

            return array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function get_defer_js_exclude_fragments(array $settings = array())
        {
            return $this->get_safe_stage_defer_exclude_fragments($settings);
        }

        private function get_builtin_defer_js_exclude_fragments()
        {
            $fragments = array_merge(
                array(
                    'googlesitekit',
                    'google-site-kit',
                    'sitekit',
                    'elementor/assets/js/frontend',
                    'elementor-pro/assets/js/frontend',
                    'elementor-frontend',
                    'elementor-pro-frontend',
                    'frontend-modules',
                    'header-footer-elementor',
                    'hfe-',
                    'smartmenus',
                    'html_types/image',
                    'html_types/color',
                    'html_types/label',
                    'html_types/slide',
                    'product-ajax-search',
                    'search-popup',
                    'nav-mobile',
                    'megamenu',
                    'header-cart',
                    'cart-canvas',
                    'off-canvas',
                    'woocommerce-products-filter',
                    'woof_',
                    'dgwt-wcas',
                    'woosq',
                    'wpcbn',
                    'contact-form-7',
                    'author-arc',
                    'mailerlite',
                    'mc4wp',
                    'complianz',
                    'sourcebuster',
                    'order-attribution',
                    'eael-general',
                    'elementor-frontend.js',
                ),
                $this->get_slider_hero_protected_fragments()
            );

            return array_values(array_unique(array_filter(array_map('strval', $fragments), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function get_force_blocking_script_handles(array $settings = array())
        {
            $handles = array(
                'jquery',
                'jquery-core',
                'jquery-migrate',
                'wp-hooks',
                'wp-i18n',
                'wp-util',
                'wp-api-request',
                'wp-api-fetch',
                'wp-url',
                'wp-polyfill',
                'heartbeat',
            );

            if (!empty($settings['woo_safe_mode'])) {
                $handles = array_merge(
                    $handles,
                    array(
                        'woocommerce',
                        'wc-cart-fragments',
                        'wc-add-to-cart',
                        'wc-checkout',
                        'wc-single-product',
                        'wc-country-select',
                        'wc-address-i18n',
                        'wc-credit-card-form',
                        'selectWoo',
                        'js-cookie',
                        'sourcebuster-js',
                        'wc-order-attribution',
                    )
                );
            }

            return array_values(array_unique($handles));
        }

        private function get_safe_stage_excluded_handles(array $settings = array())
        {
            $handles = array_merge(
                array(
                    'elementor-frontend-js',
                    'elementor-pro-frontend-js',
                    'elementor-frontend-modules-js',
                    'pro-elements-handlers-js',
                    'hfe-frontend-js-js',
                    'smartmenus-js',
                ),
                $this->get_slider_hero_protected_script_handles()
            );

            return array_values(array_unique(array_filter(array_map('strval', $handles), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function get_defer_excluded_handles(array $settings = array())
        {
            return array_values(array_unique(array_merge(
                $this->get_force_blocking_script_handles($settings),
                $this->get_safe_stage_excluded_handles($settings)
            )));
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
            $list = array_merge($this->get_builtin_delay_non_critical_js_exclude_fragments(), $list);

            return array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function get_builtin_delay_non_critical_js_exclude_fragments()
        {
            $fragments = array_merge(array(
                'jquery',
                'wp-hooks',
                'wp-i18n',
                'wp-util',
                'wp-api',
                'wp-polyfill',
                'jquery/ui',
                '/wp-includes/js/',
                'woocommerce',
                'wc-',
                '/plugins/woocommerce/assets/js/',
                'elementor',
                'elementor-pro',
                'elementor-frontend',
                'elementor-frontend.js',
                'frontend-modules',
                'pro-elements-handlers',
                'swiper',
                'swiper-bundle',
                'slick',
                'splide',
                'owl.carousel',
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
                'revslider',
                'sliderrevolution',
                'sr7',
                'tptools',
                'tp-tools',
                'html_types/image',
                'html_types/color',
                'html_types/label',
                'html_types/slide',
                'smartmenus',
                'megamenu',
                'nav-mobile',
                'off-canvas',
                'offcanvas',
                'modal',
                'popup',
                'lightbox',
                'fancybox',
                'photoswipe',
                'magnific-popup',
                'video',
                'mediaelement',
                'mejs',
                'plyr',
                'youtube',
                'vimeo',
                'wistia',
                'bricks',
                'oxygen',
                'wpbakery',
                'visual-composer',
                'vc_',
                'wpb_',
                'jet-',
                'crocoblock',
                'elementskit',
                'eael',
                'essential-addons',
                'contact-form-7',
                'wpforms',
                'fluentform',
                'forminator',
                'gravityforms',
            ), $this->get_slider_hero_protected_fragments());

            $filtered = apply_filters('ucwp_delay_js_blocking_fragments', $fragments);
            if (is_array($filtered)) {
                $fragments = $filtered;
            }

            return array_values(array_unique(array_filter(array_map('strval', $fragments), static function ($item) {
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

            if (isset($settings['delay_third_party_js_exclude_list']) && is_array($settings['delay_third_party_js_exclude_list'])) {
                $list = array_merge($list, $settings['delay_third_party_js_exclude_list']);
            }

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

            $handle = preg_replace('/-js(?:-extra|-before|-after)?$/', '', $handle);
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
            if ((empty($settings['delay_safe_third_party_js']) && empty($settings['delay_functional_third_party_js']) && empty($settings['delay_non_critical_js']) && empty($settings['lcp_boundary_defer'])) || is_admin()) {
                return;
            }

            $main_thread_relief = !empty($settings['main_thread_relief']) ? '1' : '0';
            $loader = <<<'UCWP_DELAY_LOADER'
<script id="ucwp-delayed-loader">(function(){if(window.__ucwpDelayLoader){return;}window.__ucwpDelayLoader=1;var relief=__UCWP_RELIEF__;var timeoutMs=8000;var regularAutoDone=false;var safeAutoDone=false;var allDone=false;function qa(){return Array.prototype.slice.call(document.querySelectorAll('script[type="text/ucwp-delayed-js"][data-ucwp-src],script[type="text/ucwp-delayed-js"][data-ucwp-inline="1"]'));}function c(n,a){var v=n.getAttribute('data-ucwp-'+a);return v||'';}function isSafe(n){return c(n,'delay-reason')==='safe-third-party';}function q(mode){return qa().filter(function(n){if(!n||n.getAttribute('data-ucwp-loading')==='1'){return false;}if(mode==='safe'){return isSafe(n);}if(mode==='regular'){return !isSafe(n);}return true;});}function decodeAttrs(node){var raw=c(node,'attrs');var attrs={};if(raw){try{attrs=JSON.parse(atob(raw))||{};}catch(e){attrs={};}}['id','crossorigin','referrerpolicy','integrity','nonce'].forEach(function(attr){var val=c(node,attr);if(val&&!attrs[attr]){attrs[attr]=val;}});return attrs;}function applyAttrs(s,node){var attrs=decodeAttrs(node);Object.keys(attrs).forEach(function(attr){var val=attrs[attr];if(!attr||attr==='src'||attr==='async'||attr==='defer'||attr==='data-wp-strategy'||val===null||typeof val==='undefined'){return;}try{s.setAttribute(attr,String(val));}catch(e){}});}function idle(cb){if(!relief){cb();return;}if('requestIdleCallback' in window){window.requestIdleCallback(cb,{timeout:1500});return;}setTimeout(cb,80);}function wait(ms,cb){if(!relief||ms<=0){cb();return;}setTimeout(cb,ms);}function insertAndRemove(node,s){if(node.parentNode){node.parentNode.insertBefore(s,node);node.parentNode.removeChild(node);}else{document.head.appendChild(s);}}function loadOne(node,done){if(!node||node.getAttribute('data-ucwp-loading')==='1'){done();return;}node.setAttribute('data-ucwp-loading','1');var isInline=node.getAttribute('data-ucwp-inline')==='1';var src=node.getAttribute('data-ucwp-src');var s=document.createElement('script');applyAttrs(s,node);s.async=false;if(isInline){try{s.text=node.textContent||'';}catch(e){s.text='';}insertAndRemove(node,s);done();return;}if(!src){done();return;}var finished=false;function finish(){if(finished){return;}finished=true;done();}s.onload=finish;s.onerror=finish;setTimeout(finish,timeoutMs);s.src=src;insertAndRemove(node,s);}function load(list,i){if(i>=list.length){return;}idle(function(){loadOne(list[i],function(){wait(relief?120:0,function(){load(list,i+1);});});});}function run(mode){var list=q(mode);if(!list.length){return;}load(list,0);}function triggerAll(){if(allDone){return;}allDone=true;regularAutoDone=true;safeAutoDone=true;run('all');}function triggerRegular(){if(allDone||regularAutoDone){return;}regularAutoDone=true;run('regular');}function triggerSafe(){if(allDone||safeAutoDone){return;}safeAutoDone=true;run('safe');}['scroll','mousemove','touchstart','keydown','click','pointerdown'].forEach(function(evt){window.addEventListener(evt,triggerAll,{passive:true,once:true});});window.addEventListener('load',function(){setTimeout(triggerRegular,relief?2500:2000);setTimeout(triggerSafe,relief?25000:22000);},{once:true});setTimeout(triggerRegular,relief?7000:6000);setTimeout(triggerSafe,relief?30000:26000);}());</script>
UCWP_DELAY_LOADER;
            echo str_replace('__UCWP_RELIEF__', $main_thread_relief, $loader) . "\n";
        }

        private function delay_third_party_analytics_scripts_in_html($html, array $settings = array())
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<script') || (empty($settings['delay_safe_third_party_js']) && empty($settings['delay_functional_third_party_js']))) {
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
                $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($open, 'src'), ENT_QUOTES | ENT_HTML5);
                $id = (string) $this->extract_attribute_from_html_tag($open, 'id');
                $handle = $this->infer_script_handle_from_tag($open, $src);
                if ('' === $handle && '' !== $src) {
                    $handle = $src;
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
                );
            }

            if (empty($records)) {
                return $html;
            }

            $replacements = array();
            foreach ($records as $index => $record) {
                if (empty($record['has_src']) || '' === $record['src']) {
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
                if (!$this->is_delayable_inline_script_tag($inline_record['tag'])) {
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
            $out = '';
            $last = 0;
            foreach ($replacements as $index => $replacement) {
                if (!isset($records[$index])) {
                    continue;
                }
                $record = $records[$index];
                $out .= substr($html, $last, $record['offset'] - $last) . $replacement;
                $last = $record['offset'] + strlen($record['tag']);
            }

            return $out . substr($html, $last);
        }

        private function infer_script_handle_from_tag($tag, $src = '')
        {
            $id = $this->extract_attribute_from_html_tag($tag, 'id');
            $id = trim((string) $id);
            if ('' !== $id) {
                $id = preg_replace('/-js(?:-extra|-before|-after)?$/', '', $id);
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
