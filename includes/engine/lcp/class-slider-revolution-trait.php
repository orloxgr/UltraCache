<?php
/**
 * Slider Revolution and slider-hero LCP integration helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Slider_Revolution_Trait
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

private function get_uploads_public_path_marker()
    {
        return function_exists('ultracache_uploads_public_path') ? ultracache_uploads_public_path() : '';
    }

private function get_revslider_uploads_public_path_marker()
    {
        return function_exists('ultracache_revslider_uploads_public_path') ? ultracache_revslider_uploads_public_path() : trailingslashit($this->get_uploads_public_path_marker() . 'revslider/');
    }

private function get_revslider_optimized_uploads_public_path_marker()
    {
        return function_exists('ultracache_revslider_optimized_uploads_public_path') ? ultracache_revslider_optimized_uploads_public_path() : trailingslashit($this->get_uploads_public_path_marker() . 'revslider/o/');
    }

private function get_ultracache_optimized_images_public_path_marker($format)
    {
        if (function_exists('ultracache_optimized_images_storage_url_path')) {
            return ultracache_optimized_images_storage_url_path($format);
        }

        $format = strtolower(trim((string) $format));
        return trailingslashit($this->get_uploads_public_path_marker() . 'ultracache/images/' . (in_array($format, array('avif', 'webp'), true) ? $format . '/' : ''));
    }

private function html_has_revslider_upload_reference($html)
    {
        return false !== stripos((string) $html, $this->get_revslider_uploads_public_path_marker());
    }

private function get_sr7_generated_image_public_path_markers()
    {
        return array(
            $this->get_revslider_optimized_uploads_public_path_marker(),
            trailingslashit($this->get_ultracache_optimized_images_public_path_marker('avif')) . 'revslider/o/',
            trailingslashit($this->get_ultracache_optimized_images_public_path_marker('webp')) . 'revslider/o/',
        );
    }

private function extract_sr7_generated_image_source_relative_path_from_path($path)
    {
        $path = '/' . ltrim(str_replace('\\', '/', rawurldecode((string) $path)), '/');
        if ('' === trim($path, '/')) {
            return '';
        }

        $markers = array(
            array('marker' => $this->get_revslider_optimized_uploads_public_path_marker(), 'format' => ''),
            array('marker' => trailingslashit($this->get_ultracache_optimized_images_public_path_marker('avif')) . 'revslider/o/', 'format' => 'avif'),
            array('marker' => trailingslashit($this->get_ultracache_optimized_images_public_path_marker('webp')) . 'revslider/o/', 'format' => 'webp'),
        );

        foreach ($markers as $marker_state) {
            $marker = '/' . ltrim(str_replace('\\', '/', (string) $marker_state['marker']), '/');
            $marker = trailingslashit($marker);
            if (0 !== stripos($path, $marker)) {
                continue;
            }

            $relative = ltrim(substr($path, strlen($marker)), '/');
            if ('' === $relative || false !== strpos($relative, '..')) {
                return '';
            }

            $format = (string) $marker_state['format'];
            if ('' !== $format) {
                if (!function_exists('ultracache_get_source_relative_path_from_optimized_media_path')) {
                    return '';
                }
                $source_relative = ultracache_get_source_relative_path_from_optimized_media_path('revslider/o/' . ltrim($relative, '/'), $format);
                if (!$source_relative || 0 !== strpos($source_relative, 'revslider/o/')) {
                    return '';
                }
                $relative = ltrim(substr($source_relative, strlen('revslider/o/')), '/');
            }

            return preg_match('/\.(?:avif|webp|png|jpe?g|gif|svg)$/i', (string) $relative)
                ? trim((string) $relative, '/')
                : '';
        }

        return '';
    }

private function extract_sr7_generated_image_relative_no_ext_from_path($path)
    {
        $relative = $this->extract_sr7_generated_image_source_relative_path_from_path($path);
        if ('' === $relative) {
            return '';
        }

        $relative_no_ext = preg_replace('/\.(?:avif|webp|png|jpe?g|gif|svg)$/i', '', $relative);
        return is_string($relative_no_ext) && '' !== $relative_no_ext ? trim($relative_no_ext, '/') : '';
    }

private function find_revslider_upload_urls_in_html($html)
    {
        $html = (string) $html;
        if ('' === $html) {
            return array();
        }

        $marker = preg_quote($this->get_revslider_uploads_public_path_marker(), '#');
        if ('' === $marker) {
            return array();
        }

        $urls = array();
        if (preg_match_all('#https?://[^"\'\s<>()]+' . $marker . '[^"\'\s<>()]+#i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $urls[] = array(
                    'url' => (string) $match[0],
                    'offset' => isset($match[1]) ? (int) $match[1] : 0,
                );
            }
        }

        return $urls;
    }

private function get_sr7_scanned_opening_tags($html, array $allowed_names, $start_offset = 0, $end_offset = null)
    {
        if (!function_exists('ultracache_scan_raw_html_tags')) {
            return array();
        }

        $tags = array();
        foreach (ultracache_scan_raw_html_tags((string) $html, $allowed_names, $start_offset, $end_offset) as $tag) {
            if (!empty($tag['closing'])) {
                continue;
            }
            $tags[] = $tag;
        }

        return $tags;
    }

private function find_sr7_url_attribute_in_raw_tag($tag_html, $raw_url)
    {
        $tag_html = (string) $tag_html;
        $raw_url = html_entity_decode((string) $raw_url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ('' === $tag_html || '' === $raw_url) {
            return '';
        }

        foreach (array('src', 'data-src', 'data-dbsrc', 'data-lazy-src', 'data-lazyload', 'data-image', 'data-origin', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image', 'poster') as $attribute) {
            $value = $this->extract_attribute_from_html_tag($tag_html, $attribute);
            if ('' !== $value && (false !== strpos($value, $raw_url) || false !== strpos($raw_url, $value))) {
                return $attribute;
            }
        }

        foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
            $value = $this->extract_attribute_from_html_tag($tag_html, $attribute);
            foreach ($this->extract_candidate_urls_from_srcset($value) as $candidate_url) {
                if ($candidate_url === $raw_url || false !== strpos($candidate_url, $raw_url) || false !== strpos($raw_url, $candidate_url)) {
                    return $attribute;
                }
            }
        }

        foreach ($this->extract_candidate_urls_from_style($this->extract_attribute_from_html_tag($tag_html, 'style')) as $candidate_url) {
            if ($candidate_url === $raw_url || false !== strpos($candidate_url, $raw_url) || false !== strpos($raw_url, $candidate_url)) {
                return 'style';
            }
        }

        return '';
    }

private function get_sr7_structural_marker_from_raw_tag($tag_html, $tag_name = '')
    {
        $tag_html = (string) $tag_html;
        $parts = array(strtolower(trim((string) $tag_name)));
        foreach (array('class', 'id', 'style', 'data-key', 'data-index', 'data-slide', 'data-slide-index', 'data-type', 'data-role') as $attribute) {
            $value = trim($this->extract_attribute_from_html_tag($tag_html, $attribute));
            if ('' !== $value) {
                $parts[] = strtolower($value);
            }
        }

        return trim(implode(' ', array_filter($parts)));
    }

private function sr7_raw_tag_marks_first_slide($tag_html)
    {
        $markers = array();
        foreach (array('class', 'id', 'data-key', 'data-index', 'data-slide', 'data-slide-index') as $attribute) {
            $value = strtolower(trim($this->extract_attribute_from_html_tag((string) $tag_html, $attribute)));
            if ('' !== $value) {
                $markers[] = $value;
            }
        }

        foreach ($markers as $marker) {
            if ('1' === $marker || 'slide-1' === $marker || 'slide_1' === $marker || 'sr7_1' === $marker) {
                return true;
            }
            if (false !== strpos($marker, 'sr7_1') || false !== strpos($marker, '-1-')) {
                return true;
            }
        }

        return false;
    }

private function get_sr7_structured_raw_url_context($html, $offset, $raw_url)
    {
        $html = (string) $html;
        $offset = max(0, (int) $offset);
        if ('' === $html || $offset >= strlen($html) || !function_exists('ultracache_scan_raw_html_tags')) {
            return array(
                'tag' => 'SCRIPT',
                'attribute' => 'script',
                'class' => '',
                'id' => '',
                'style' => '',
                'origin' => 'raw-block-fallback',
                'first_slide' => false,
                'tag_name' => '',
            );
        }

        $allowed_names = array(
            'sr7-module-bg',
            'sr7-img',
            'img',
            'sr7-slide',
            'sr7-content',
            'sr7-module',
            'rs-module',
            'image_lists',
            'script',
        );
        $container_names = array('sr7-slide', 'sr7-content', 'sr7-module', 'rs-module', 'image_lists', 'script');
        $stack = array();
        $exact_tag = null;

        foreach (ultracache_scan_raw_html_tags($html, $allowed_names) as $tag) {
            $tag_offset = isset($tag['offset']) ? (int) $tag['offset'] : 0;
            $tag_end = isset($tag['end']) ? (int) $tag['end'] : $tag_offset;
            $tag_name = isset($tag['name']) ? strtolower((string) $tag['name']) : '';

            if ($tag_offset > $offset) {
                break;
            }

            if (!empty($tag['closing'])) {
                for ($index = count($stack) - 1; $index >= 0; --$index) {
                    if ((string) ($stack[$index]['name'] ?? '') === $tag_name) {
                        $stack = array_slice($stack, 0, $index);
                        break;
                    }
                }
                continue;
            }

            if ($offset >= $tag_offset && $offset < $tag_end) {
                $exact_tag = $tag;
                break;
            }

            if (in_array($tag_name, $container_names, true) && empty($tag['self_closing'])) {
                $stack[] = $tag;
            }
        }

        $context_tag = is_array($exact_tag) ? $exact_tag : (!empty($stack) ? $stack[count($stack) - 1] : null);
        $context_raw = is_array($context_tag) ? (string) ($context_tag['raw'] ?? '') : '';
        $context_name = is_array($context_tag) ? strtolower((string) ($context_tag['name'] ?? '')) : '';
        $structural_marker = $this->get_sr7_structural_marker_from_raw_tag($context_raw, $context_name);
        $first_slide = $this->sr7_raw_tag_marks_first_slide($context_raw);
        $has_structured_sr7_container = false;

        if (!empty($stack)) {
            foreach ($stack as $stack_tag) {
                $stack_name = strtolower((string) ($stack_tag['name'] ?? ''));
                if (0 === strpos($stack_name, 'sr7-') || 'rs-module' === $stack_name || 'image_lists' === $stack_name) {
                    $has_structured_sr7_container = true;
                    $stack_raw = (string) ($stack_tag['raw'] ?? '');
                    $structural_marker .= ' ' . $this->get_sr7_structural_marker_from_raw_tag($stack_raw, $stack_name);
                    $first_slide = $first_slide || $this->sr7_raw_tag_marks_first_slide($stack_raw);
                }
            }
        }
        $structural_marker = trim($structural_marker);

        $is_explicit_sr7_tag = 0 === strpos($context_name, 'sr7-') || 'rs-module' === $context_name || 'image_lists' === $context_name;
        $tag_name = '' !== $context_name ? strtoupper($context_name) : 'SCRIPT';
        if ('IMAGE_LISTS' === $tag_name) {
            $tag_name = 'SCRIPT';
        }
        $attribute = is_array($exact_tag) ? $this->find_sr7_url_attribute_in_raw_tag($context_raw, $raw_url) : '';
        if ('' === $attribute) {
            $attribute = 'script';
        }

        $origin = 'raw-block-fallback';
        if ('sr7-img' === $context_name) {
            $origin = 'sr7-image-tag';
        } elseif ('sr7-module-bg' === $context_name) {
            $origin = 'confirmed-module-background';
        } elseif ($is_explicit_sr7_tag || $has_structured_sr7_container) {
            $origin = 'sr7-structured-context';
        }

        return array(
            'tag' => $tag_name,
            'attribute' => $attribute,
            'class' => $this->extract_attribute_from_html_tag($context_raw, 'class'),
            'id' => $this->extract_attribute_from_html_tag($context_raw, 'id'),
            'style' => trim($this->extract_attribute_from_html_tag($context_raw, 'style') . ' ' . $structural_marker),
            'origin' => $origin,
            'first_slide' => $first_slide,
            'tag_name' => $context_name,
        );
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
            function_exists('ultracache_plugins_public_path') ? ultracache_plugins_public_path('revslider') : '',
            function_exists('ultracache_plugins_public_path') ? ultracache_plugins_public_path('slider-revolution') : '',
            $this->get_revslider_uploads_public_path_marker(),
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

        $filtered = apply_filters('ultracache_slider_hero_protected_fragments', $fragments);
        if (is_array($filtered)) {
            $fragments = $filtered;
        }

        return array_values(array_unique(array_filter(array_map('strval', $fragments), static function ($item) {
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
            || false !== stripos((string) $html, $this->get_revslider_uploads_public_path_marker());
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
            $this->get_revslider_uploads_public_path_marker(),
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

private function find_confirmed_sr7_module_bg_lcp_preload_candidates($html, $limit = 1)
    {
        $html = (string) $html;
        $limit = max(1, min(3, (int) $limit));
        if ('' === $html || false === stripos($html, '<sr7-module-bg')) {
            return array();
        }

        $matches = $this->get_sr7_scanned_opening_tags($html, array('sr7-module-bg'));
        if (empty($matches)) {
            return array();
        }

        $candidates = array();
        $seen = array();
        foreach ($matches as $index => $match) {
            $tag_html = isset($match['raw']) ? (string) $match['raw'] : '';
            $tag_offset = isset($match['offset']) ? (int) $match['offset'] : 0;
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

private function find_marked_sr7_lcp_preload_candidates($html, $limit = 1)
    {
        $html = (string) $html;
        $limit = max(1, min(5, (int) $limit));
        if ('' === $html || false === stripos($html, 'data-ultracache-sr7-lcp')) {
            return array();
        }

        $matches = $this->get_sr7_scanned_opening_tags($html, array('sr7-img', 'img'));
        if (empty($matches)) {
            return array();
        }

        $preferred = array();
        $generated = array();
        $matched_index = 0;
        foreach ($matches as $match) {
            $tag_html = isset($match['raw']) ? (string) $match['raw'] : '';
            if ('1' !== trim($this->extract_attribute_from_html_tag($tag_html, 'data-ultracache-sr7-lcp'))) {
                continue;
            }
            $index = $matched_index++;
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
                $candidate['sr7_role'] = 'marked-sr7-image';
                $candidate['lcp_reason'] = 'sr7-marked-image';
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
                    $candidate['sr7_role'] = 'marked-sr7-image';
                    $candidate['lcp_reason'] = 'sr7-marked-image';
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

        if ($this->has_manual_lcp_selector_configuration()) {
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

public function enqueue_sr7_lcp_priority_runtime_helper()
    {
        // Backward-compatible method alias; the runtime helper is now generic
        // browser-observed LCP learning and contains no Slider Revolution rules.
        $this->enqueue_lcp_observer_runtime_helper();
    }

private function inject_sr7_lcp_priority_runtime_script($html)
    {
        // SR7/LCP runtime priority is now printed through wp_enqueue_scripts
        // as assets/js/lcp-observer.js with wp_add_inline_script()
        // configuration. Keep this HTML rewrite hook as a no-op so the LCP
        // priority markup pipeline does not output raw script tags.
        return $html;
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

        $tag_matches = $this->get_sr7_scanned_opening_tags($context_html, array('sr7-module-bg', 'sr7-img', 'img', 'div', 'section', 'figure', 'picture', 'sr7-slide', 'sr7-content'));
        if (empty($tag_matches)) {
            return array();
        }

        $candidates = array();
        foreach ($tag_matches as $tag_index => $tag_match) {
            $tag_html = isset($tag_match['raw']) ? (string) $tag_match['raw'] : '';
            $tag_name = isset($tag_match['name']) ? strtoupper((string) $tag_match['name']) : 'IMG';
            $relative_tag_offset = isset($tag_match['offset']) ? (int) $tag_match['offset'] : 0;
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

        if (!preg_match('/"slides"\s*:\s*\{/i', $html, $slides_match, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $slides_marker = isset($slides_match[0][0]) ? (string) $slides_match[0][0] : '';
        $slides_offset = isset($slides_match[0][1]) ? (int) $slides_match[0][1] : 0;
        $slides_open_in_marker = strrpos($slides_marker, '{');
        if (false === $slides_open_in_marker) {
            return '';
        }

        $slides_open = $slides_offset + $slides_open_in_marker;
        $probe = substr($html, $slides_open + 1, $max_length);
        if ('' === $probe || !preg_match('/"[^"]+"\s*:\s*\{/i', $probe, $first_match, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $first_marker = isset($first_match[0][0]) ? (string) $first_match[0][0] : '';
        $first_offset = isset($first_match[0][1]) ? (int) $first_match[0][1] : 0;
        $object_open_in_marker = strrpos($first_marker, '{');
        if (false === $object_open_in_marker) {
            return '';
        }

        $slice_start = $slides_open + 1 + $first_offset;
        $object_open = $slice_start + $object_open_in_marker;
        $scan_limit = min(strlen($html), $object_open + $max_length);
        $depth = 0;
        $quote = '';
        $escaped = false;

        for ($index = $object_open; $index < $scan_limit; $index++) {
            $char = $html[$index];
            if ('' !== $quote) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ('\\' === $char) {
                    $escaped = true;
                    continue;
                }
                if ($char === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ('"' === $char || "'" === $char) {
                $quote = $char;
                continue;
            }
            if ('{' === $char) {
                $depth++;
                continue;
            }
            if ('}' === $char) {
                $depth--;
                if (0 === $depth) {
                    return substr($html, $slice_start, ($index - $slice_start) + 1);
                }
            }
        }

        return substr($html, $slice_start, $max_length);
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

        if (!preg_match_all('~"src"\s*:\s*"([^"]+\.(?:avif|webp|png|jpe?g|gif|svg)(?:[?#][^"]*)?)"~i', $slice, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
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

        $search_html = $this->extract_sr7_first_slide_slice($html, 140000);
        if ('' === $search_html || false === stripos($search_html, 'image_cache')) {
            return array();
        }

        if (!preg_match_all('~"image_cache"\s*:\s*"([^"]+\.(?:avif|webp|png|jpe?g|gif|svg)(?:[?#][^" ]*)?)"~i', $search_html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return array();
        }

        $candidates = array();
        foreach ($matches as $match) {
            $url = isset($match[1][0]) ? html_entity_decode(trim((string) $match[1][0]), ENT_QUOTES, 'UTF-8') : '';
            $offset = isset($match[1][1]) ? (int) $match[1][1] : 0;
            if ('' === $url) {
                continue;
            }

            $context = substr($search_html, max(0, $offset - 5000), 10000);
            if (false === stripos($context, 'slidebg') && false === stripos($context, 'slide bg')) {
                continue;
            }

            if (0 === strpos($url, '//')) {
                $url = (is_ssl() ? 'https:' : 'http:') . $url;
            } elseif (0 === strpos($url, '/')) {
                $url = $this->absolutize_public_resource_url($url);
            }
            $url = $this->normalize_public_resource_url($url);
            if ('' === $url || !$this->is_lcp_candidate_image_url($url)) {
                continue;
            }

            list($width, $height) = $this->extract_sr7_dimensions_from_context($context);
            $key = strtolower($url);
            $candidates[$key] = array(
                'url' => $url,
                'width' => $width,
                'height' => $height,
                'layer' => 'slide-bg',
                'offset' => $offset,
                'context' => strtolower($context),
                'sr7_module_background' => true,
            );
        }

        return array_values($candidates);
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

private function prefer_existing_nextgen_revslider_url($url)
    {
        $url = $this->normalize_public_resource_url($url);
        if ('' === $url) {
            return '';
        }

        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $source_relative = $this->extract_sr7_generated_image_source_relative_path_from_path($path);
        if ('' === $path || '' === $source_relative || !function_exists('ultracache_build_optimized_media_relative_path')) {
            return $url;
        }

        $uploads_relative = 'revslider/o/' . ltrim($source_relative, '/');
        $source_url = function_exists('ultracache_uploads_storage_url')
            ? ultracache_uploads_storage_url($uploads_relative)
            : $url;

        $avif_relative = ultracache_build_optimized_media_relative_path($uploads_relative, 'avif');
        if ($avif_relative && defined('ULTRACACHE_AVIF_DIR') && defined('ULTRACACHE_AVIF_URL')) {
            $avif_path = trailingslashit(ULTRACACHE_AVIF_DIR) . $avif_relative;
            if ($this->is_generated_image_fresh_for_source($avif_path, $source_url)) {
                return $this->normalize_public_resource_url(trailingslashit(ULTRACACHE_AVIF_URL) . $avif_relative);
            }
        }

        $webp_relative = ultracache_build_optimized_media_relative_path($uploads_relative, 'webp');
        if ($webp_relative && defined('ULTRACACHE_WEBP_DIR') && defined('ULTRACACHE_WEBP_URL')) {
            $webp_path = trailingslashit(ULTRACACHE_WEBP_DIR) . $webp_relative;
            if ($this->is_generated_image_fresh_for_source($webp_path, $source_url)) {
                return $this->normalize_public_resource_url(trailingslashit(ULTRACACHE_WEBP_URL) . $webp_relative);
            }
        }

        return $url;
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

private function get_sr7_lcp_candidate_contract_priority(array $candidate)
    {
        if (!empty($candidate['sr7_module_bg_candidate']) || 'module-bg-final-html' === (string) ($candidate['sr7_role'] ?? '')) {
            return 600;
        }

        if (!empty($candidate['sr7_verified_first_slide']) || in_array((string) ($candidate['sr7_role'] ?? ''), array('module-background', 'slide-layer'), true)) {
            return 500;
        }

        if (!empty($candidate['sr7_markup_candidate']) || 'marked-sr7-image' === (string) ($candidate['sr7_role'] ?? '')) {
            return 450;
        }

        if (!empty($candidate['sr7_static_slide']) || in_array((string) ($candidate['sr7_role'] ?? ''), array('module-bg', 'static-slide'), true)) {
            return 400;
        }

        $role = (string) ($candidate['sr7_role'] ?? '');
        if ('sr7-image-tag' === $role) {
            return 300;
        }
        if ('sr7-structured-context' === $role) {
            return 200;
        }
        if ('raw-block-fallback' === $role) {
            return 100;
        }

        return !empty($candidate['is_sr7']) ? 50 : 0;
    }

private function compare_sr7_lcp_candidates_by_contract($left, $right)
    {
        $left_priority = $this->get_sr7_lcp_candidate_contract_priority(is_array($left) ? $left : array());
        $right_priority = $this->get_sr7_lcp_candidate_contract_priority(is_array($right) ? $right : array());
        if ($left_priority !== $right_priority) {
            return $right_priority <=> $left_priority;
        }

        return $this->compare_lcp_candidates_by_area_then_score($left, $right);
    }

private function sort_sr7_lcp_candidates_by_contract(array $candidates)
    {
        usort($candidates, function ($left, $right) {
            return $this->compare_sr7_lcp_candidates_by_contract($left, $right);
        });
        return $candidates;
    }

private function find_best_sr7_lcp_candidate($html)
    {
        $html = (string) $html;
        if ('' === $html) {
            return null;
        }

        if (false === stripos($html, 'sr7') && false === stripos($html, $this->get_revslider_uploads_public_path_marker())) {
            return null;
        }

        $candidates = array();

        foreach ($this->find_sr7_first_slide_lcp_candidates($html, 3) as $first_slide_candidate) {
            $candidates[] = $first_slide_candidate;
        }

        foreach ($this->find_sr7_static_slide_lcp_candidates($html, 1) as $static_slide_candidate) {
            $candidates[] = $static_slide_candidate;
        }

        $matches = $this->get_sr7_scanned_opening_tags($html, array('sr7-img'));
        if (!empty($matches)) {
            foreach ($matches as $match) {
                $tag_html = isset($match['raw']) ? (string) $match['raw'] : '';
                $offset = isset($match['offset']) ? (int) $match['offset'] : 0;
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
                        $candidate['sr7_role'] = 'sr7-image-tag';
                        $candidate['lcp_reason'] = 'sr7-image-tag';
                        $candidate['score'] += max(0, 120 - min(100, (int) floor($offset / 5000)));
                        $candidates[] = $candidate;
                    }
                }

                foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
                    foreach ($this->extract_candidate_urls_from_srcset($this->extract_attribute_from_html_tag($tag_html, $attribute)) as $srcset_url) {
                        $candidate = $this->build_lcp_candidate_from_values($srcset_url, $context + array('attribute' => $attribute));
                        if (null !== $candidate) {
                            $candidate['is_sr7'] = true;
                            $candidate['sr7_role'] = 'sr7-image-tag';
                            $candidate['lcp_reason'] = 'sr7-image-tag';
                            $candidate['score'] += max(0, 120 - min(100, (int) floor($offset / 5000)));
                            $candidates[] = $candidate;
                        }
                    }
                }

                foreach ($this->extract_candidate_urls_from_style($context['style']) as $style_url) {
                    $candidate = $this->build_lcp_candidate_from_values($style_url, $context + array('attribute' => 'style'));
                    if (null !== $candidate) {
                        $candidate['is_sr7'] = true;
                        $candidate['sr7_role'] = 'sr7-image-tag';
                        $candidate['lcp_reason'] = 'sr7-image-tag';
                        $candidate['score'] += max(0, 120 - min(100, (int) floor($offset / 5000)));
                        $candidates[] = $candidate;
                    }
                }
            }
        }

        foreach ($this->find_revslider_upload_urls_in_html($html) as $match) {
            $raw_url = isset($match['url']) ? (string) $match['url'] : '';
            $offset = isset($match['offset']) ? (int) $match['offset'] : 0;
            if ('' === $raw_url) {
                continue;
            }
            $structured_context = $this->get_sr7_structured_raw_url_context($html, $offset, $raw_url);
            $candidate = $this->build_lcp_candidate_from_values($raw_url, array(
                'tag' => isset($structured_context['tag']) ? (string) $structured_context['tag'] : 'SCRIPT',
                'attribute' => isset($structured_context['attribute']) ? (string) $structured_context['attribute'] : 'script',
                'class' => isset($structured_context['class']) ? (string) $structured_context['class'] : '',
                'id' => isset($structured_context['id']) ? (string) $structured_context['id'] : '',
                'style' => isset($structured_context['style']) ? (string) $structured_context['style'] : '',
            ));
            if (null !== $candidate) {
                $candidate['is_sr7'] = true;
                $candidate['score'] += max(0, 160 - min(120, (int) floor($offset / 4000)));
                if (!empty($structured_context['first_slide'])) {
                    $candidate['score'] += 80;
                }
                $candidate['sr7_role'] = isset($structured_context['origin']) ? (string) $structured_context['origin'] : 'raw-block-fallback';
                $candidate['lcp_reason'] = $candidate['sr7_role'];
                $candidates[] = $candidate;
            }
        }

        if (empty($candidates)) {
            return null;
        }

        // Mixed SR7 fallback candidates are ordered by proven semantic origin
        // before size. Within the same origin class, rendered area remains the
        // primary signal, followed by score and DOM offset.
        $candidates = $this->sort_sr7_lcp_candidates_by_contract($candidates);

        return $candidates[0];
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

        $relative_no_ext = $this->extract_sr7_generated_image_relative_no_ext_from_path($path);
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

        $tag_matches = $this->get_sr7_scanned_opening_tags($scan_html, array('img'));
        if (empty($tag_matches)) {
            return '';
        }

        foreach ($tag_matches as $tag_match) {
            $tag_html = isset($tag_match['raw']) ? (string) $tag_match['raw'] : '';
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

        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        return '' !== $this->extract_sr7_generated_image_relative_no_ext_from_path($path);
    }

private function cleanup_ambiguous_sr7_generated_lcp_preloads($html)
    {
        if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || false === stripos($html, '<link')) {
            return $html;
        }

        try {
            $processor = new WP_HTML_Tag_Processor($html);
            $seen_plugin_image_preloads = array();
            $changed = false;

            while ($processor->next_tag('LINK')) {
                $rel = $processor->get_attribute('rel');
                $as = $processor->get_attribute('as');
                $href = $processor->get_attribute('href');
                if (!is_string($rel) || !is_string($as) || !is_string($href)) {
                    continue;
                }

                if (false === stripos($rel, 'preload') || 'image' !== strtolower(trim($as)) || '' === trim($href)) {
                    continue;
                }

                $is_ultracache_preload = null !== $processor->get_attribute('data-ultracache-lcp-preload')
                    || null !== $processor->get_attribute('data-ultracache-critical-chain');
                if (!$is_ultracache_preload) {
                    continue;
                }

                $normalized = $this->normalize_public_resource_url($href);
                if ('' === $normalized) {
                    continue;
                }

                $remove_reason = '';
                if ($this->is_sr7_generated_image_list_url($normalized)) {
                    $remove_reason = 'sr7-generated-helper-preload';
                } else {
                    $key = strtolower($normalized);
                    if (isset($seen_plugin_image_preloads[$key])) {
                        $remove_reason = 'duplicate-lcp-preload';
                    } else {
                        $seen_plugin_image_preloads[$key] = true;
                    }
                }

                if ('' === $remove_reason) {
                    continue;
                }

                foreach (array(
                    'rel',
                    'as',
                    'href',
                    'type',
                    'media',
                    'imagesrcset',
                    'imagesizes',
                    'fetchpriority',
                    'crossorigin',
                    'referrerpolicy',
                    'integrity',
                    'data-ultracache-lcp-preload',
                    'data-ultracache-lcp-preload-reason',
                    'data-ultracache-critical-chain',
                ) as $attribute) {
                    if (null !== $processor->get_attribute($attribute)) {
                        $processor->remove_attribute($attribute);
                        $changed = true;
                    }
                }

                $processor->set_attribute('data-ultracache-lcp-preload-removed', '1');
                $processor->set_attribute('data-ultracache-lcp-preload-removed-reason', $remove_reason);
                $processor->set_attribute('data-ultracache-original-preload-href', $normalized);
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
}
