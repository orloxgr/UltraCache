<?php
/**
 * JavaScript delay, safety-scan, and delayed-loader helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_JS_Delay_Trait
{

    private function get_delay_safety_suggested_exclusion_from_url($url)
    {
        $path = (string) wp_parse_url($this->normalize_public_resource_url((string) $url), PHP_URL_PATH);
        $path = rawurldecode($path);
        $path = trim($path);
        if ('' === $path) {
            return trim((string) $url);
        }

        $markers = array();
        $core_js_marker = function_exists('ultracache_wordpress_includes_public_path') ? ultracache_wordpress_includes_public_path('js/') : '';
        if ('' !== $core_js_marker) {
            $markers[$core_js_marker] = 'core';
        }
        if (function_exists('ultracache_plugins_public_path')) {
            $markers[ultracache_plugins_public_path()] = 'plugin';
        }
        if (function_exists('ultracache_themes_public_paths')) {
            foreach (ultracache_themes_public_paths() as $theme_marker) {
                $markers[$theme_marker] = 'theme';
            }
        }

        foreach ($markers as $marker => $type) {
            $pos = stripos($path, (string) $marker);
            if (false !== $pos) {
                return ltrim(substr($path, $pos + strlen((string) $marker)), '/');
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

        $lists = $this->get_unified_js_user_exclude_fragments($settings);

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
                if (false !== stripos($attrs, 'src=') || false !== stripos($attrs, 'data-ultracache-src=') || false !== stripos($attrs, 'text/ultracache-delayed-js') || false !== stripos($attrs, 'application/ld+json') || false !== stripos($attrs, 'speculationrules')) {
                    continue;
                }
                if (false !== stripos($trimmed_code, '__ultracacheDelayLoader') || false !== stripos($trimmed_code, 'text/ultracache-delayed-js') || false !== stripos($trimmed_code, 'wp-emoji-settings') || false !== stripos($trimmed_code, '_wpemojiSettings')) {
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

        if (!preg_match_all('/<script\b[^>]*(?:type\s*=\s*["\']text\/ultracache-delayed-js["\']|data-ultracache-src\s*=)[^>]*>/i', $html, $matches)) {
            return $definitions;
        }

        foreach ((array) ($matches[0] ?? array()) as $tag) {
            $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag, 'data-ultracache-src'), ENT_QUOTES | ENT_HTML5);
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

            $content = ultracache_guarded_asset_file_get_contents($local_path, 'js', 'js_delay_safety_local_asset', true);
            if (!is_string($content) || '' === $content) {
                continue;
            }

            $handle = (string) $this->extract_attribute_from_html_tag($tag, 'data-ultracache-handle');
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

        if (!preg_match_all('/<script\b[^>]*(?:type\s*=\s*["\']text\/ultracache-delayed-js["\']|data-ultracache-src\s*=)[^>]*>/i', $html, $matches)) {
            return $records;
        }

        foreach ((array) ($matches[0] ?? array()) as $tag) {
            $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag, 'data-ultracache-src'), ENT_QUOTES | ENT_HTML5);
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

            $content = ultracache_guarded_asset_file_get_contents($local_path, 'js', 'js_delay_safety_local_asset', true);
            if (!is_string($content) || '' === $content) {
                continue;
            }

            $records[] = array(
                'url' => $src,
                'handle' => (string) $this->extract_attribute_from_html_tag($tag, 'data-ultracache-handle'),
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
                if (false !== stripos($attrs, 'src=') || false !== stripos($attrs, 'data-ultracache-src=') || false !== stripos($attrs, 'text/ultracache-delayed-js') || false !== stripos($attrs, 'application/ld+json') || false !== stripos($attrs, 'speculationrules')) {
                    continue;
                }
                if (false !== stripos($trimmed_code, '__ultracacheDelayLoader') || false !== stripos($trimmed_code, 'text/ultracache-delayed-js') || false !== stripos($trimmed_code, 'wp-emoji-settings') || false !== stripos($trimmed_code, '_wpemojiSettings')) {
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

        /*
         * Scanner markers/suggestions are evidence-based matching strings
         * for assets already present in the inspected HTML. They do not
         * enqueue, load, fetch, or contact third-party providers.
         */
        $revslider_markers = array_values(array_filter(array(
            function_exists('ultracache_plugins_public_path') ? ultracache_plugins_public_path('revslider') : '',
            function_exists('ultracache_plugins_public_path') ? ultracache_plugins_public_path('slider-revolution') : '',
        )));
        $elementor_markers = array_values(array_filter(array(
            function_exists('ultracache_plugins_public_path') ? ultracache_plugins_public_path('elementor') : '',
            function_exists('ultracache_plugins_public_path') ? ultracache_plugins_public_path('elementor-pro') : '',
        )));
        $divi_markers = array_values(array_filter(array_merge(
            function_exists('ultracache_themes_public_paths') ? ultracache_themes_public_paths('Divi') : array(),
            array(function_exists('ultracache_plugins_public_path') ? ultracache_plugins_public_path('divi-builder') : '')
        )));

        $groups = array(
            array(
                'category' => 'detected-component-protection',
                'label' => __('Detected component protections', 'ultracache'),
                'confidence' => 'recommended',
                'appendable' => true,
                'markers' => array_merge(array('sr7-module', 'sr7-slide', 'revslider', 'themepunch', 'rs-module', 'wp-block-themepunch-revslider'), $revslider_markers),
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
                'markers' => array_merge(array('elementor', 'elementor-widget'), $elementor_markers),
                'suggestions' => array('elementor', 'elementor-frontend', 'elementor-pro', 'frontend-modules', 'webpack.runtime'),
                'reason' => __('Elementor assets or widgets were detected on this page. Keep core Elementor runtime dependencies protected unless dependency-safe testing passes.', 'ultracache'),
            ),
            array(
                'category' => 'detected-component-protection',
                'label' => __('Detected component protections', 'ultracache'),
                'confidence' => 'recommended',
                'appendable' => true,
                'markers' => array_merge(array('et_pb_', 'et-builder', 'et-core'), $divi_markers),
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
                'appendable' => false,
                'markers' => array('complianz', 'cmplz', 'complianz-gdpr', 'complianz-gdpr-premium', 'real-cookie-banner', 'real-cookie-banner-pro', 'devowl', 'rcb-', 'wp-consent-api', 'wp_consent'),
                'suggestions' => array('complianz', 'cmplz', 'real-cookie-banner', 'devowl', 'wp-consent-api'),
                'reason' => __('Consent-management assets were detected on this page. They follow the configured JavaScript strategy; use the visible Defer Instead of Delay or Do Not Defer or Delay lists if compatibility testing requires an exception.', 'ultracache'),
            ),
            array(
                'category' => 'detected-component-protection',
                'label' => __('Detected component protections', 'ultracache'),
                'confidence' => 'recommended',
                'appendable' => false,
                'markers' => array('cookieyes', 'cookielawinfo', 'cky-', 'cookiebot', 'uc.js', 'iubenda', 'onetrust', 'optanon'),
                'suggestions' => array('cookieyes', 'cookielawinfo', 'cky-', 'cookiebot', 'iubenda', 'onetrust', 'optanon'),
                'reason' => __('Cookie/consent-management assets were detected on this page. They follow the configured JavaScript strategy; use the visible Defer Instead of Delay or Do Not Defer or Delay lists if compatibility testing requires an exception.', 'ultracache'),
            ),
            array(
                'category' => 'detected-component-protection',
                'label' => __('Detected component protections', 'ultracache'),
                'confidence' => 'recommended',
                'appendable' => true,
                'markers' => array('google.com/recaptcha', 'grecaptcha', 'hcaptcha', 'hcaptcha.com', 'turnstile', 'challenges.cloudflare', 'cf-turnstile'),
                'suggestions' => array('google.com/recaptcha', 'grecaptcha', 'hcaptcha', 'turnstile', 'challenges.cloudflare', 'cf-turnstile'),
                'reason' => __('Captcha/anti-bot assets were detected on this page. These are commonly unsafe to delay because forms may need them immediately.', 'ultracache'),
            ),
            array(
                'category' => 'detected-component-protection',
                'label' => __('Detected component protections', 'ultracache'),
                'confidence' => 'recommended',
                'appendable' => true,
                'markers' => array('contact-form-7', 'wpforms', 'gform', 'gravityforms', 'formidable', 'ninja-forms', 'fluentform', 'forminator', 'mailerlite', 'mailchimp', 'mc4wp', 'klaviyo', 'hubspot'),
                'suggestions' => array('contact-form-7', 'wpforms', 'gform', 'gravityforms', 'formidable', 'ninja-forms', 'fluentform', 'forminator', 'mailerlite', 'validation-messages', 'mailchimp', 'mc4wp', 'klaviyo', 'hubspot'),
                'reason' => __('Form, validation, newsletter, or CRM assets were detected on this page. Try Defer Instead of Delay first, then use the fallback list if the form must work before interaction.', 'ultracache'),
            ),
            array(
                'category' => 'detected-component-protection',
                'label' => __('Detected component protections', 'ultracache'),
                'confidence' => 'recommended',
                'appendable' => true,
                'markers' => array('js.stripe.com', 'stripe', 'paypal.com/sdk/js', 'paypal', 'braintree', 'klarna', 'afterpay', 'squareup', 'square-web-payments'),
                'suggestions' => array('js.stripe.com', 'stripe', 'paypal.com/sdk/js', 'paypal', 'braintree', 'klarna', 'afterpay', 'square'),
                'reason' => __('Payment gateway assets were detected on this page. Payment/checkout scripts are safer when kept out of delayed execution.', 'ultracache'),
            ),
            array(
                'category' => 'detected-elementor-load-order',
                'label' => __('Elementor load-order protections', 'ultracache'),
                'confidence' => 'recommended',
                'appendable' => true,
                'markers' => array('elementorModules', 'elementor/assets/js/common.min.js', 'common.min.js?ver=', 'elementor-admin-bar.min.js', 'frontend-modules.min.js', 'elementor-frontend-modules', 'elementor-webpack-runtime'),
                'suggestions' => array('elementor', 'elementor-frontend', 'elementor-frontend-modules', 'frontend-modules', 'elementor-webpack-runtime', 'elementor-pro-webpack-runtime', 'elementorModules', 'elementor/assets/js/frontend-modules', 'elementor/assets/js/common.min.js', 'elementor/assets/js/elementor-admin-bar.min.js', 'common.min.js', 'elementor-admin-bar.min.js'),
                'reason' => __('Elementor module/runtime scripts were detected. Keep Elementor module providers and dependent common/admin-bar scripts in Defer Instead of Delay, or use the fallback list until the page has been verified clean.', 'ultracache'),
            ),
            array(
                'category' => 'review-only',
                'label' => __('Review-only candidates', 'ultracache'),
                'confidence' => 'review',
                'appendable' => false,
                'markers' => array('woocommerce', 'wc-', 'cart', 'checkout', 'account', 'add-to-cart', 'wc-cart-fragments'),
                'suggestions' => array(function_exists('ultracache_plugins_public_path') ? ultracache_plugins_public_path('woocommerce') : 'woocommerce/', 'woocommerce/assets/js/frontend/', 'wc-cart-fragments', 'wc-add-to-cart', 'add-to-cart', 'single-product', 'cart-fragments'),
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
            $tag = isset($script[0]) ? (string) $script[0] : '';
            $code = isset($script[2]) ? (string) $script[2] : '';
            $id = (string) $this->extract_attribute_from_html_tag($tag, 'id');
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
							__('Inline script block %s references jQuery. Keep the jQuery handle in Defer Instead of Delay or the fallback list unless the inline block is also moved into a delayed/replayed execution group.', 'ultracache'),
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



    private function get_non_critical_delay_patterns()
    {
        /*
         * Generic semantic candidates only. Vendor/plugin/library identities
         * are forbidden here: provider-specific behavior belongs in visible
         * lists, explicit integrations, or diagnostics recommendations.
         */
        return array(
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
    }


    private function matches_non_critical_delay_patterns($handle, $src, $tag = '')
    {
        $haystack = strtolower((string) $handle . ' ' . $src . ' ' . $tag);
        $patterns = $this->get_non_critical_delay_patterns();

        foreach ($patterns as $pattern) {
            if (false !== strpos($haystack, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }



    private function should_protect_wpbakery_animation_script($handle, $src, $tag = '', array $settings = array())
    {
        if (empty($settings['protect_wpbakery_animations'])) {
            return false;
        }

        $handle_lc = strtolower(trim((string) $handle));
        $src_lc = strtolower(trim((string) $src));
        $tag_lc = strtolower((string) $tag);

        $protected_handles = array(
            'vc_waypoints',
            'vc_waypoints-js',
            'vc_carousel_js',
            'vc_carousel_js-js',
            'wpb_flexslider',
            'wpb_flexslider-js',
            'nivo-slider',
            'nivo-slider-js',
            'isotope',
            'isotope-js',
            'jquery-ui-tabs',
            'jquery-ui-tabs-js',
            'jquery-ui-accordion',
            'jquery-ui-accordion-js',
        );

        if (in_array($handle_lc, $protected_handles, true)) {
            return true;
        }

        $haystack = $src_lc . ' ' . $tag_lc;
        $is_wpbakery_asset = false !== strpos($haystack, '/js_composer/') || false !== strpos($haystack, 'wpbakery');
        $is_waypoints_asset = false !== strpos($haystack, '/vc_waypoints/') || false !== strpos($haystack, 'vc-waypoints') || false !== strpos($haystack, 'vc_waypoints');
        $is_carousel_asset = false !== strpos($haystack, '/vc_carousel/') || false !== strpos($haystack, 'vc-carousel') || false !== strpos($haystack, 'vc_carousel');
        $is_flexslider_asset = false !== strpos($haystack, '/flexslider/') || false !== strpos($haystack, 'jquery.flexslider');
        $is_nivo_asset = false !== strpos($haystack, '/nivo-slider/') || false !== strpos($haystack, 'jquery.nivo.slider');
        $is_isotope_asset = false !== strpos($haystack, '/isotope-layout/') || false !== strpos($haystack, 'isotope.pkgd');

        return $is_wpbakery_asset && ($is_waypoints_asset || $is_carousel_asset || $is_flexslider_asset || $is_nivo_asset || $is_isotope_asset);
    }



    /**
     * Return whether the current per-page Elementor compatibility resolver has
     * proven that this script must stay out of UltraCache Delay/parallel paths.
     *
     * No Elementor handles are hard-coded here. The protected set is generated
     * from rendered widgets, Elementor's live dependency API, and the installed
     * handler sources for the current response.
     */
    private function should_protect_elementor_compatibility_script($handle, $src, $tag = '', array $settings = array())
    {
        if (empty($settings['protect_elementor_compatibility'])) {
            return false;
        }

        $protected_handles = isset($settings['_elementor_compatibility_protected_handles']) && is_array($settings['_elementor_compatibility_protected_handles'])
            ? $settings['_elementor_compatibility_protected_handles']
            : array();
        $protected_handle_map = array();
        foreach ($protected_handles as $protected_handle) {
            $protected_handle = sanitize_key((string) $protected_handle);
            if ('' !== $protected_handle) {
                $protected_handle_map[$protected_handle] = true;
                $protected_handle_map[$protected_handle . '-js'] = true;
            }
        }

        $handle_lc = sanitize_key((string) $handle);
        if ('' !== $handle_lc && isset($protected_handle_map[$handle_lc])) {
            return true;
        }
        if ('' !== $handle_lc && substr($handle_lc, -3) === '-js') {
            $base_handle = substr($handle_lc, 0, -3);
            if (isset($protected_handle_map[$base_handle])) {
                return true;
            }
        }

        $protected_paths = isset($settings['_elementor_compatibility_protected_src_paths']) && is_array($settings['_elementor_compatibility_protected_src_paths'])
            ? $settings['_elementor_compatibility_protected_src_paths']
            : array();
        if (empty($protected_paths)) {
            return false;
        }

        $src_path = strtolower((string) wp_parse_url((string) $src, PHP_URL_PATH));
        if ('' === $src_path && '' !== (string) $tag) {
            $tag_src = (string) $this->extract_attribute_from_html_tag((string) $tag, 'src');
            if ('' === $tag_src) {
                $tag_src = (string) $this->extract_attribute_from_html_tag((string) $tag, 'data-ultracache-src');
            }
            $src_path = strtolower((string) wp_parse_url(html_entity_decode($tag_src, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_PATH));
        }

        if ('' === $src_path) {
            return false;
        }

        foreach ($protected_paths as $protected_path) {
            $protected_path = strtolower(trim((string) $protected_path));
            if ('' !== $protected_path && $src_path === $protected_path) {
                return true;
            }
        }

        return false;
    }



    /**
     * Whether the current frontend request is a variable WooCommerce product.
     *
     * @return bool
     */
    private function is_runtime_woocommerce_variable_product_context()
    {
        if (!function_exists('is_product') || !is_product() || !function_exists('wc_get_product')) {
            return false;
        }

        $product_id = function_exists('get_queried_object_id') ? absint(get_queried_object_id()) : 0;
        if ($product_id <= 0) {
            return false;
        }

        $product = wc_get_product($product_id);
        return is_object($product) && is_callable(array($product, 'is_type')) && $product->is_type('variable');
    }



    /**
     * Protect product-interaction scripts that may participate in resolving a
     * WooCommerce variation. This is deliberately scoped to variable product
     * pages so unrelated site JavaScript remains eligible for Delay JS.
     *
     * @param string $handle Script handle.
     * @param string $src    Script URL.
     * @param string $tag    Script tag.
     * @return bool
     */
    private function should_protect_woocommerce_variable_product_interaction_script($handle, $src, $tag = '', array $settings = array())
    {
        if (empty($settings['protect_woocommerce_variable_product_compatibility'])
            || !$this->is_runtime_woocommerce_variable_product_context()) {
            return false;
        }

        $haystack = strtolower((string) $handle . ' ' . (string) $src . ' ' . (string) $tag);
        foreach (array('variation', 'swatch', 'single-product', 'single_product') as $marker) {
            if (false !== strpos($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }



    /**
     * Add early browser discovery for the core variable-product runtime that is
     * otherwise emitted late in the WooCommerce footer dependency chain.
     *
     * @param array $preloads WordPress preload resource definitions.
     * @return array
     */
    public function filter_woocommerce_variable_product_preloads($preloads)
    {
        $preloads = is_array($preloads) ? $preloads : array();
        $settings = $this->get_settings();
        if (empty($settings['protect_woocommerce_variable_product_compatibility'])
            || !$this->is_runtime_woocommerce_variable_product_context()
            || !function_exists('wp_scripts')) {
            return $preloads;
        }

        $scripts = wp_scripts();
        if (!is_object($scripts) || empty($scripts->registered)) {
            return $preloads;
        }

        $existing = array();
        foreach ($preloads as $resource) {
            if (is_array($resource) && !empty($resource['href'])) {
                $existing[(string) $resource['href']] = true;
            }
        }

        foreach (array('underscore', 'wp-util', 'wc-add-to-cart-variation') as $handle) {
            if (empty($scripts->registered[$handle]) || empty($scripts->registered[$handle]->src)) {
                continue;
            }

            $dependency = $scripts->registered[$handle];
            $src = (string) $dependency->src;
            if (0 === strpos($src, '//')) {
                $src = (is_ssl() ? 'https:' : 'http:') . $src;
            } elseif (!preg_match('#^https?://#i', $src)) {
                $src = trailingslashit((string) $scripts->base_url) . ltrim($src, '/');
            }

            // A false/empty version means WordPress has not finalized the exact
            // script URL yet. Do not emit a speculative `?ver` preload that can
            // differ from the URL printed later by the script loader.
            $dependency_version = $dependency->ver;
            if ((false === $dependency_version || '' === (string) $dependency_version) && false === strpos($src, 'ver=')) {
                continue;
            }
            if (null !== $dependency_version && false === strpos($src, 'ver=')) {
                $src = add_query_arg('ver', (string) $dependency_version, $src);
            }

            $src = esc_url($src);
            if ('' === $src || isset($existing[$src])) {
                continue;
            }

            $preloads[] = array(
                'href' => $src,
                'as'   => 'script',
            );
            $existing[$src] = true;
        }

        return $preloads;
    }



    /**
     * Enqueue the small pre-submit race guard before footer variation scripts.
     *
     * @return void
     */
    public function enqueue_woocommerce_variable_product_interaction_guard()
    {
        $settings = $this->get_settings();
        if (empty($settings['protect_woocommerce_variable_product_compatibility'])
            || !$this->is_runtime_woocommerce_variable_product_context()
            || !method_exists($this, 'ultracache_enqueue_frontend_js_helper')) {
            return;
        }

        $this->ultracache_enqueue_frontend_js_helper(
            'ultracache-woocommerce-variable-product-guard',
            'woocommerce-variable-product-guard.js',
            array('jquery'),
            false
        );
    }



    private function should_delay_non_critical_script($handle, $src, $tag, array $settings = array())
    {
        $policy = $this->ultracache_build_unified_js_execution_policy($settings);
        $facts = $this->ultracache_build_registered_script_policy_facts($tag, $handle, $src, $settings, $policy);
        $route = $this->ultracache_evaluate_unified_js_execution_policy($policy, $facts);
        $reason = isset($route['reason']) ? (string) $route['reason'] : '';

        return 'delay' === (string) ($route['lane'] ?? '')
            && in_array($reason, array('non-critical-first-party', 'non-critical-first-party-aggressive'), true);
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
        if ('' === $src || false === stripos($tag, '<script') || !$this->is_delayable_external_script_tag($tag)) {
            return array('matched' => false);
        }
        if (false !== stripos($tag, 'type="text/ultracache-delayed-js"')
            || false !== stripos($tag, "type='text/ultracache-delayed-js'")
            || false !== stripos($tag, 'data-ultracache-src=')) {
            return array('matched' => false);
        }

        $policy = $this->ultracache_build_unified_js_execution_policy($settings);
        $patterns = isset($policy['patterns']) && is_array($policy['patterns']) ? $policy['patterns'] : array();
        $visible_native = $this->is_js_excluded_by_user_patterns($handle, $src, $tag, '', $settings);
        $visible_defer = $this->is_script_user_force_deferred($handle, $src, $tag, $settings);
        $elementor_protected = $this->should_protect_elementor_compatibility_script($handle, $src, $tag, $settings);
        $wpbakery_protected = $this->should_protect_wpbakery_animation_script($handle, $src, $tag, $settings);
        $woocommerce_protected = $this->should_protect_woocommerce_variable_product_interaction_script($handle, $src, $tag, $settings);

        $integration_route = array();
        if ($elementor_protected) {
            $integration_route = array('lane' => 'defer', 'reason' => 'explicit-elementor-compatibility', 'action' => 'defer-native', 'interactionEligible' => true);
        } elseif ($wpbakery_protected) {
            $integration_route = array('lane' => 'defer', 'reason' => 'explicit-wpbakery-compatibility', 'action' => 'defer-force-ordered', 'interactionEligible' => true);
        } elseif ($woocommerce_protected) {
            $integration_route = array('lane' => 'defer', 'reason' => 'explicit-woocommerce-variable-product-compatibility', 'action' => 'defer-force-ordered', 'interactionEligible' => true);
        }

        $facts = array(
            'visibleNativePattern' => $visible_native ? 'registered-visible-native' : '',
            'visibleDeferPattern' => $visible_defer ? 'registered-visible-defer' : '',
            'explicitIntegrationRoute' => $integration_route,
            // This adapter answers only third-party Delay policy. Delay All and
            // first-party non-critical routing are owned by their dedicated passes.
            'delayAllCandidate' => false,
            'safePattern' => $this->get_matching_third_party_delay_pattern($handle, $src, $tag, isset($patterns['safe']) && is_array($patterns['safe']) ? $patterns['safe'] : array()),
            'functionalPattern' => $this->get_matching_third_party_delay_pattern($handle, $src, $tag, isset($patterns['functional']) && is_array($patterns['functional']) ? $patterns['functional'] : array()),
            'isThirdParty' => $this->is_external_third_party_script_url($src),
            'nonCriticalPattern' => '',
            'aggressiveNonCriticalCandidate' => false,
            'asyncRoute' => array(),
        );
        $route = $this->ultracache_evaluate_unified_js_execution_policy($policy, $facts);
        $reason = isset($route['reason']) ? (string) $route['reason'] : '';
        if ('delay' !== (string) ($route['lane'] ?? '') || !in_array($reason, array('safe-third-party', 'functional-third-party', 'all-third-party'), true)) {
            return array('matched' => false, 'reason' => $reason);
        }

        return array(
            'matched' => true,
            'category' => $reason,
            'reason' => $reason,
            'matched_pattern' => isset($route['matched_pattern']) ? (string) $route['matched_pattern'] : '',
        );
    }

    private function get_inline_third_party_delay_match($handle, $tag, array $settings = array())
    {
        $tag = (string) $tag;
        if ('' === $tag || false === stripos($tag, '<script') || !$this->is_delayable_inline_script_tag($tag)) {
            return array('matched' => false);
        }

        $inline_code = (string) $this->get_inline_script_code_from_tag($tag);
        $policy = $this->ultracache_build_unified_js_execution_policy($settings);
        $patterns = isset($policy['patterns']) && is_array($policy['patterns']) ? $policy['patterns'] : array();
        $haystacks = array(strtolower(trim((string) $handle)), strtolower($tag), strtolower($inline_code));
        $first_match = function (array $candidates) use ($haystacks) {
            foreach ($candidates as $pattern) {
                $pattern = strtolower(trim((string) $pattern));
                if ('' === $pattern) {
                    continue;
                }
                foreach ($haystacks as $haystack) {
                    if ($this->third_party_delay_pattern_matches_haystack($haystack, $pattern)) {
                        return $pattern;
                    }
                }
            }
            return '';
        };

        $facts = array(
            'visibleNativePattern' => $this->is_js_excluded_by_user_patterns($handle, '', $tag, $inline_code, $settings) ? 'inline-visible-native' : '',
            'visibleDeferPattern' => $this->is_script_user_force_deferred($handle, '', $tag, $settings) ? 'inline-visible-defer' : '',
            'explicitIntegrationRoute' => array(),
            'delayAllCandidate' => false,
            'safePattern' => $first_match(isset($patterns['safe']) && is_array($patterns['safe']) ? $patterns['safe'] : array()),
            'functionalPattern' => $first_match(isset($patterns['functional']) && is_array($patterns['functional']) ? $patterns['functional'] : array()),
            'isThirdParty' => false,
            'nonCriticalPattern' => '',
            'aggressiveNonCriticalCandidate' => false,
            'asyncRoute' => array(),
        );
        $route = $this->ultracache_evaluate_unified_js_execution_policy($policy, $facts);
        $reason = isset($route['reason']) ? (string) $route['reason'] : '';
        if ('delay' !== (string) ($route['lane'] ?? '') || !in_array($reason, array('safe-third-party', 'functional-third-party'), true)) {
            return array('matched' => false, 'reason' => $reason);
        }

        return array(
            'matched' => true,
            'category' => $reason,
            'reason' => $reason,
            'matched_pattern' => isset($route['matched_pattern']) ? (string) $route['matched_pattern'] : '',
        );
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



    private function third_party_delay_pattern_matches_haystack($haystack, $pattern)
    {
        $haystack = strtolower((string) $haystack);
        $pattern = strtolower(trim((string) $pattern));
        if ('' === $haystack || '' === $pattern) {
            return false;
        }

        /*
         * Visible third-party patterns are fragments, but prefix-style token
         * markers ending in '-' or '_' must start at an identifier boundary.
         * This keeps values such as "foo-" matching "foo-banner" without
         * accidentally matching the suffix inside an unrelated identifier.
         * URL/domain/code fragments retain normal substring
         * semantics, as do simple words such as "recaptcha" so established
         * matches like "grecaptcha" are not changed.
         */
        $requires_left_boundary = 1 === preg_match('/^[a-z0-9_-]+[-_]$/', $pattern);
        $offset = 0;
        while (false !== ($position = strpos($haystack, $pattern, $offset))) {
            if (!$requires_left_boundary || 0 === $position) {
                return true;
            }

            $previous = substr($haystack, $position - 1, 1);
            if (1 !== preg_match('/[a-z0-9_]/', $previous)) {
                return true;
            }

            $offset = $position + 1;
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
                if ($this->third_party_delay_pattern_matches_haystack($haystack, $pattern)) {
                    return $pattern;
                }
            }
        }

        return '';
    }


    public function enqueue_delayed_script_loader()
    {
        $settings = $this->get_settings();
        if ((empty($settings['delay_safe_third_party_js']) && empty($settings['delay_functional_third_party_js']) && empty($settings['delay_all_third_party_js']) && empty($settings['delay_non_critical_js']) && empty($settings['lcp_boundary_defer']) && empty($settings['delay_all_js'])) || is_admin() || (function_exists('ultracache_should_bypass_logged_in_frontend_optimizations') && ultracache_should_bypass_logged_in_frontend_optimizations())) {
            return;
        }

        $auto_events = array();
        if (!empty($settings['delayed_js_autostart_mousemove'])) {
            $auto_events[] = 'mousemove';
        }
        if (!empty($settings['delayed_js_autostart_click'])) {
            $auto_events[] = 'click';
        }
        if (!empty($settings['delayed_js_autostart_touch_pointer'])) {
            $auto_events[] = 'touchstart';
            $auto_events[] = 'pointerdown';
        }
        if (!empty($settings['delayed_js_autostart_keyboard'])) {
            $auto_events[] = 'keydown';
        }

        $auto_events = array_values(array_unique(array_map('sanitize_key', $auto_events)));
        $auto_timer_enabled = 'infinite' !== (string) ($settings['delayed_local_js_auto_start'] ?? 'custom');
        $auto_seconds = isset($settings['delayed_local_js_auto_start_seconds']) ? (float) $settings['delayed_local_js_auto_start_seconds'] : 0.05;
        $auto_seconds = max(0.05, min(5.0, $auto_seconds));
        $minimum_release_seconds = isset($settings['delayed_js_minimum_release_seconds']) ? (float) $settings['delayed_js_minimum_release_seconds'] : 0.0;
        $minimum_release_seconds = max(0.0, min(4.0, $minimum_release_seconds));

        /*
         * 3.12.11 Unified Routing. The browser receives the exact same
         * declarative policy snapshot (ordered rules + flags + visible pattern
         * sets) used by the registered-script router. The native bootstrap gets
         * only the visible NATIVE patterns required for parser-early bypass;
         * the full rule interpreter remains inside the deferred loader.
         */
        $dynamic_policy = $this->ultracache_build_unified_js_execution_policy($settings);
        $dynamic_policy_json = wp_json_encode($dynamic_policy, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $dynamic_policy_encoded = is_string($dynamic_policy_json) && '' !== $dynamic_policy_json
            ? rtrim(strtr(base64_encode($dynamic_policy_json), '+/', '-_'), '=')
            : '';

        $dynamic_finder_handle = 'ultracache-dynamic-script-finder-bootstrap';
        if ($this->ultracache_enqueue_frontend_js_helper($dynamic_finder_handle, 'dynamic-script-finder-bootstrap.js', array(), false)) {
            $native_policy_json = wp_json_encode(array(
                'nativePatterns' => isset($dynamic_policy['patterns']['native']) && is_array($dynamic_policy['patterns']['native']) ? $dynamic_policy['patterns']['native'] : array(),
                'realCookieBannerCompatibility' => !empty($dynamic_policy['flags']['realCookieBannerCompatibility']),
                'realCookieBannerInfrastructurePatterns' => isset($dynamic_policy['patterns']['realCookieBannerInfrastructure']) && is_array($dynamic_policy['patterns']['realCookieBannerInfrastructure']) ? $dynamic_policy['patterns']['realCookieBannerInfrastructure'] : array(),
                'complianzCompatibility' => !empty($dynamic_policy['flags']['complianzCompatibility']),
                'complianzInfrastructurePatterns' => isset($dynamic_policy['patterns']['complianzInfrastructure']) && is_array($dynamic_policy['patterns']['complianzInfrastructure']) ? $dynamic_policy['patterns']['complianzInfrastructure'] : array(),
            ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            if (is_string($native_policy_json) && '' !== $native_policy_json) {
                $native_policy_encoded = rtrim(strtr(base64_encode($native_policy_json), '+/', '-_'), '=');
                $this->ultracache_add_frontend_js_helper_data($dynamic_finder_handle, 'ultracacheDynamicScriptFinderConfig', array(
                    'encodedPolicy' => $native_policy_encoded,
                ));
            }
        }

        /*
         * Parser-early interaction capture is infrastructure, not scheduling
         * policy. It records only the configured visitor events that could be
         * lost before the deferred Delay executor is available. No vendor,
         * tracker, CMP, or consent classification is performed here.
         */
        if (!empty($auto_events)) {
            $interaction_bootstrap_handle = 'ultracache-delayed-js-interaction-bootstrap';
            if ($this->ultracache_enqueue_frontend_js_helper($interaction_bootstrap_handle, 'delayed-js-interaction-bootstrap.js', array(), false)) {
                $bootstrap_config = array(
                    'autoEvents' => $auto_events,
                );
                $bootstrap_json = wp_json_encode($bootstrap_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                if (is_string($bootstrap_json) && '' !== $bootstrap_json && function_exists('wp_script_add_data')) {
                    $bootstrap_encoded = rtrim(strtr(base64_encode($bootstrap_json), '+/', '-_'), '=');
                    $this->ultracache_add_frontend_js_helper_script_data($interaction_bootstrap_handle, 'ultracache_opaque_config', $bootstrap_encoded);
                }
            }
        }

        $handle = 'ultracache-delayed-js-loader';
        if (!$this->ultracache_enqueue_frontend_js_helper($handle, 'delayed-js-loader.js', array(), false)) {
            return;
        }
        $this->ultracache_add_frontend_js_helper_script_data($handle, 'strategy', 'defer');

        $runtime_scan_infinite_trigger_ms = (!$auto_timer_enabled && method_exists($this, 'is_runtime_js_scan_request') && $this->is_runtime_js_scan_request())
            ? 10000
            : 0;

        $this->ultracache_add_frontend_js_helper_data($handle, 'ultracacheDelayedJsLoaderConfig', array(
            'relief'          => !empty($settings['main_thread_relief']),
            'autoEvents'       => $auto_events,
            'autoAfterLoad'    => !empty($settings['delayed_js_autostart_after_load']),
            'autoTimerEnabled' => $auto_timer_enabled,
            'autoDelayMs'      => (int) round(1000 * $auto_seconds),
            'minimumReleaseMs'  => (int) round(1000 * $minimum_release_seconds),
            'dynamicPolicyEncoded' => $dynamic_policy_encoded,
            'runtimeScanInfiniteTriggerMs' => $runtime_scan_infinite_trigger_ms,
            'scriptTimeoutMs' => 8000,
            'firstPartyParallelExecution' => !empty($settings['first_party_js_parallel_execution']),
            'thirdPartyParallelExecution' => !empty($settings['third_party_js_parallel_execution']),
            // 3.10.03 benchmark tuning: fetch up to four eligible same-origin delayed scripts in parallel
            // while keeping JavaScript execution strictly ordered. Keep this explicit so 2/4/6 can be A/B tested later.
            'orderedFetchEnabled' => true,
            'orderedFetchConcurrency' => 4,
        ));
    }

}
