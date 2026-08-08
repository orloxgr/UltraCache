<?php
/**
 * LCP candidate discovery, selection, scoring, and metadata helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_LCP_Detection_Trait
{
private function should_apply_lcp_boundary_defer(array $settings, $slider_safe_mode)
    {
        unset($slider_safe_mode);

        if (empty($settings['lcp_boundary_defer']) || empty($settings['lcp_image_priority'])) {
            return false;
        }

        return true;
    }

private function find_manual_lcp_hero_selector_candidates($html, $limit = 1)
    {
        $target = $this->find_manual_lcp_selector_target($html);
        if (empty($target) || empty($target['resource_url'])) {
            return array();
        }

        $candidate = $this->build_lcp_candidate_from_values($target['resource_url'], array(
            'tag'                      => (string) ($target['tag'] ?? ''),
            'attribute'                => (string) ($target['attribute'] ?? ''),
            'style'                    => (string) ($target['style'] ?? ''),
            'manual_lcp_hero_selector' => (string) ($target['selector'] ?? ''),
            'inside_manual_lcp_selector' => true,
        ));
        if (null === $candidate) {
            return array();
        }

        $candidate['manual_lcp_hero_selector'] = (string) ($target['selector'] ?? '');
        $candidate['manual_lcp_hero_selector_found'] = true;
        $candidate['is_manual_selector'] = true;
        $candidate['resource_type'] = (string) ($target['resource_type'] ?? 'unknown');
        $candidate['boundary_offset'] = absint($target['boundary_offset'] ?? 0);
        $candidate['score'] = 2000000;
        $candidate['lcp_reason'] = 'manual-selector-direct-resource';

        return array_slice(array($candidate), 0, max(1, min(5, (int) $limit)));
    }

private function normalize_manual_lcp_hero_selector($selector)
    {
        $selector = trim((string) $selector);
        if ('' === $selector || strlen($selector) > 500 || preg_match('/[\x00-\x1F\x7F]/', $selector)) {
            return '';
        }

        // Preserve the CSS selector exactly as entered. Validation and matching are
        // delegated to querySelectorAll() in the browser; the server performs only
        // best-effort parsing for simple selectors that are present in initial HTML.
        return $selector;
    }

private function lcp_manual_entry_is_image($entry)
    {
        $entry = trim((string) $entry);
        if ('' === $entry) {
            return false;
        }

        if (preg_match('/^image\s+\S+/i', $entry)) {
            return true;
        }

        if (preg_match('#^(?:https?:)?//#i', $entry) || preg_match('#^/#', $entry)) {
            return true;
        }

        return (bool) preg_match('/\.(?:avif|webp|png|jpe?g|gif|svg)(?:[?#].*)?$/i', $entry);
    }

private function split_lcp_manual_entry($entry)
    {
        $entry = trim((string) $entry);
        if ('' === $entry) {
            return array('selectors' => array(), 'images' => array());
        }

        if ($this->lcp_manual_entry_is_image($entry)) {
            if (preg_match('/^image\s+(.+)$/i', $entry, $matches)) {
                $entry = trim((string) ($matches[1] ?? ''));
            }
            return array('selectors' => array(), 'images' => '' === $entry ? array() : array($entry));
        }

        return array('selectors' => array($entry), 'images' => array());
    }

private function get_effective_manual_lcp_configuration($page_url = '')
    {
        $settings = $this->get_settings();
        if (!empty($settings['lcp_frontend_discovery'])) {
            if ('' === (string) $page_url && method_exists($this, 'get_current_request_url')) {
                $page_url = $this->get_current_request_url();
            }
            $page_url = method_exists($this, 'normalize_lcp_observation_page_url')
                ? $this->normalize_lcp_observation_page_url($page_url)
                : esc_url_raw((string) $page_url);
            $entry = '' !== $page_url && method_exists($this, 'get_lcp_page_manual_selector_for_url')
                ? $this->get_lcp_page_manual_selector_for_url($page_url)
                : '';
            return $this->split_lcp_manual_entry($entry);
        }

        return array(
            'selectors' => isset($settings['manual_lcp_hero_selector_list']) && is_array($settings['manual_lcp_hero_selector_list'])
                ? array_values($settings['manual_lcp_hero_selector_list'])
                : array(),
            'images' => isset($settings['lcp_image_priority_override_list']) && is_array($settings['lcp_image_priority_override_list'])
                ? array_values($settings['lcp_image_priority_override_list'])
                : array(),
        );
    }

private function has_manual_lcp_selector_configuration()
    {
        $configuration = $this->get_effective_manual_lcp_configuration();
        return !empty($configuration['selectors']);
    }

private function find_manual_lcp_selector_target($html)
    {
        $html = (string) $html;
        if ('' === $html) {
            return array();
        }

        $configuration = $this->get_effective_manual_lcp_configuration();
        $selectors = isset($configuration['selectors']) && is_array($configuration['selectors']) ? $configuration['selectors'] : array();
        foreach ($selectors as $selector) {
            $selector = $this->normalize_manual_lcp_hero_selector($selector);
            if ('' === $selector) {
                continue;
            }

            $parts = $this->parse_manual_lcp_css_selector_parts($selector);
            if (1 !== count($parts) || preg_match('/[>+~\s]/', $selector)) {
                // Complex selectors are evaluated only by the browser so their
                // native CSS semantics are not approximated by the server parser.
                continue;
            }

            $tag_html = $this->extract_manual_lcp_css_selector_direct_tag($html, $selector);
            if ('' === $tag_html) {
                continue;
            }

            $offset = strpos($html, $tag_html);
            $offset = false === $offset ? 0 : (int) $offset;
            $tag = '';
            if (preg_match('/^<\s*([a-z0-9:_-]+)/i', $tag_html, $tag_match) && !empty($tag_match[1])) {
                $tag = strtoupper((string) $tag_match[1]);
            }

            $block = $this->extract_manual_lcp_hero_selector_block($html, $selector);
            $boundary_offset = $offset + strlen('' !== $block ? $block : $tag_html);
            $style = $this->extract_attribute_from_html_tag($tag_html, 'style');
            $resource_url = '';
            $attribute = '';
            $resource_type = 'text';

            if (in_array($tag, array('IMG', 'SR7-IMG', 'IMAGE'), true)) {
                foreach (array('src', 'data-src', 'data-lazy-src', 'data-lazyload') as $candidate_attribute) {
                    $value = $this->extract_attribute_from_html_tag($tag_html, $candidate_attribute);
                    if ('' !== $value && $this->is_lcp_candidate_image_url($value)) {
                        $resource_url = $value;
                        $attribute = $candidate_attribute;
                        $resource_type = 'image';
                        break;
                    }
                }
                if ('' === $resource_url) {
                    foreach (array('srcset', 'data-srcset', 'data-lazy-srcset') as $candidate_attribute) {
                        $urls = $this->extract_candidate_urls_from_srcset($this->extract_attribute_from_html_tag($tag_html, $candidate_attribute));
                        if (!empty($urls)) {
                            $resource_url = (string) end($urls);
                            $attribute = $candidate_attribute;
                            $resource_type = 'image';
                            break;
                        }
                    }
                }
            } elseif ('VIDEO' === $tag) {
                $poster = $this->extract_attribute_from_html_tag($tag_html, 'poster');
                if ('' !== $poster && $this->is_lcp_candidate_image_url($poster)) {
                    $resource_url = $poster;
                    $attribute = 'poster';
                    $resource_type = 'poster';
                } else {
                    foreach (array('src', 'data-src', 'data-lazy-src') as $candidate_attribute) {
                        $value = $this->extract_attribute_from_html_tag($tag_html, $candidate_attribute);
                        if ('' !== $value && $this->is_lcp_candidate_video_url($value)) {
                            $resource_url = $value;
                            $attribute = $candidate_attribute;
                            $resource_type = 'video';
                            break;
                        }
                    }
                }
            }

            if ('' === $resource_url) {
                $style_urls = $this->extract_candidate_urls_from_style($style);
                if (!empty($style_urls)) {
                    $resource_url = (string) $style_urls[0];
                    $attribute = 'style';
                    $resource_type = 'background';
                }
            }

            return array(
                'selector'        => $selector,
                'tag_html'        => $tag_html,
                'tag'             => $tag,
                'style'           => $style,
                'resource_type'   => $resource_type,
                'resource_url'    => $resource_url,
                'attribute'       => $attribute,
                'boundary_offset' => max(1, $boundary_offset),
            );
        }

        return array();
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
        $marked_tags = function_exists('ultracache_scan_raw_html_tags')
            ? ultracache_scan_raw_html_tags($block, array('sr7-img', 'img'))
            : array();
        $marked_index = 0;
        foreach ($marked_tags as $tag_record) {
            if (!empty($tag_record['closing'])) {
                continue;
            }
            $tag_html = isset($tag_record['raw']) ? (string) $tag_record['raw'] : '';
            if ('1' !== trim($this->extract_attribute_from_html_tag($tag_html, 'data-ultracache-sr7-lcp'))) {
                continue;
            }
            $candidate = $this->extract_lcp_candidate_from_html_tag($tag_html, array(
                'manual_lcp_hero_selector' => $selector,
                'manual_lcp_order' => $marked_index,
                'prefer_dom_order' => true,
            ));
            if (null !== $candidate) {
                $candidate['is_sr7'] = true;
                $candidate['sr7_markup_candidate'] = true;
                $candidate['sr7_verified_first_slide'] = true;
                $candidate['sr7_role'] = 'marked-sr7-image';
                $candidate['lcp_reason'] = 'sr7-marked-image';
                $candidate['score'] = 2000000 - ((int) $marked_index * 10);
                $candidates[] = $candidate;
            }
            ++$marked_index;
        }

        if (!empty($candidates)) {
            return $candidates;
        }

        $candidate_tags = function_exists('ultracache_scan_raw_html_tags')
            ? ultracache_scan_raw_html_tags($block, array('img', 'sr7-img', 'div', 'section', 'figure', 'picture', 'sr7-slide', 'sr7-content', 'sr7-module'))
            : array();
        $candidate_index = 0;
        foreach ($candidate_tags as $tag_record) {
            if (!empty($tag_record['closing'])) {
                continue;
            }
            $tag_html = isset($tag_record['raw']) ? (string) $tag_record['raw'] : '';
            $candidate = $this->extract_lcp_candidate_from_html_tag($tag_html, array(
                'manual_lcp_hero_selector' => $selector,
                'manual_lcp_order' => $candidate_index,
            ));
            if (null !== $candidate) {
                $candidate['score'] += 5000 - ((int) $candidate_index * 10);
                $candidates[] = $candidate;
            }
            ++$candidate_index;
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

private function find_manual_lcp_candidate($html)
    {
        $configuration = $this->get_effective_manual_lcp_configuration();
        $entries = isset($configuration['images']) && is_array($configuration['images']) ? $configuration['images'] : array();
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

        if (preg_match_all('/[^\s"\'<>]+\.(?:avif|webp|png|jpe?g|gif|bmp|heic|heif|svg)(?:[?#][^\s"\'<>]*)?/i', (string) $html, $matches)) {
            foreach ($matches[0] as $raw_url) {
                $candidate_url = trim((string) $raw_url, " \t\n\r\0\x0B()[]{}.,;");
                if ('' !== $candidate_url && false !== stripos($candidate_url, $needle) && $this->is_lcp_candidate_image_url($candidate_url)) {
                    return $candidate_url;
                }
            }
        }

        return '';
    }

private function find_best_lcp_candidate_with_tag_processor($html)
    {
        if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html) {
            return null;
        }

        try {
            $processor = new WP_HTML_Tag_Processor($html);
            $candidates = array();

            while ($processor->next_tag()) {
                $candidate = $this->extract_best_lcp_candidate_from_current_tag($processor);
                if (null !== $candidate) {
                    $candidates[] = $candidate;
                }
            }

            if (empty($candidates)) {
                return null;
            }

            // Whole-document heuristic selection is semantic-first. This mirrors
            // the actual markup optimization path, where a visible hero/featured
            // candidate must beat a larger logo, hidden image, or navigation asset.
            // Area-first ordering remains available for homogeneous/manual scopes.
            $candidates = $this->sort_lcp_candidates_by_score_then_area($candidates);
            return $candidates[0];
        } catch (\Throwable $e) {
            return null;
        }
    }

private function extract_best_lcp_candidate_from_current_tag($processor)
    {
        if (!$processor instanceof WP_HTML_Tag_Processor) {
            return null;
        }

        $tag = strtoupper((string) $processor->get_tag());
        if ('' === $tag || in_array($tag, array('SCRIPT', 'STYLE', 'LINK', 'META', 'HTML', 'HEAD'), true)) {
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
        foreach (array('src', 'data-src', 'data-dbsrc', 'data-lazy-src', 'data-lazyload', 'data-image', 'data-origin', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image', 'poster') as $attribute) {
            $value = $processor->get_attribute($attribute);
            if (!is_string($value) || '' === trim($value)) {
                continue;
            }
            $candidate = $this->build_lcp_candidate_from_values($value, $context + array('attribute' => $attribute));
            if (null !== $candidate) {
                $candidates[] = $candidate;
            }
        }

        foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
            $value = $processor->get_attribute($attribute);
            if (!is_string($value) || '' === trim($value)) {
                continue;
            }
            foreach ($this->extract_candidate_urls_from_srcset($value) as $url) {
                $candidate = $this->build_lcp_candidate_from_values($url, $context + array('attribute' => $attribute));
                if (null !== $candidate) {
                    $candidates[] = $candidate;
                }
            }
        }

        foreach ($this->extract_candidate_urls_from_style((string) $context['style']) as $url) {
            $candidate = $this->build_lcp_candidate_from_values($url, $context + array('attribute' => 'style'));
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

        if (false !== strpos($meta_haystack, $this->get_revslider_uploads_public_path_marker())) {
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
        $base = preg_replace('/\.(?:jpe?g|png|gif|webp|avif|svg)$/i', '', $base);
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
            '/"(?:url|contentUrl|thumbnailUrl|image)"\s*:\s*"([^"\\\\]*(?:\\\\.[^"\\\\]*)*\.(?:jpe?g|png|webp|avif|svg)(?:[^"\\\\]*(?:\\\\.[^"\\\\]*)*)?)"/i',
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
                $uploads_marker = $this->get_uploads_public_path_marker();
                if (false === stripos($url, $uploads_marker) && false === stripos($url, ltrim($uploads_marker, '/'))) {
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

        if (false !== stripos($html, '<sr7-') || false !== stripos($html, 'sr7-module') || false !== stripos($html, $this->get_revslider_uploads_public_path_marker())) {
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

        $image_tags = ultracache_scan_raw_html_tags($html, array('img'));
        if (empty($image_tags)) {
            return null;
        }

        $primary_image_keys = $this->extract_lcp_primary_image_keys_from_metadata($html);
        $is_wp_singular_context = false !== strpos($html_lc, 'wp-singular') || false !== strpos($html_lc, 'single-post') || false !== strpos($html_lc, 'single-');
        $checked = 0;
        $candidates = array();

        foreach ($image_tags as $image_tag) {
            if (!empty($image_tag['closing'])) {
                continue;
            }

            $tag_html = isset($image_tag['raw']) ? (string) $image_tag['raw'] : '';
            $offset = isset($image_tag['offset']) ? (int) $image_tag['offset'] : 0;
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

private function compare_lcp_candidates_by_score_then_area($left, $right)
    {
        $left_score = isset($left['score']) ? (int) $left['score'] : 0;
        $right_score = isset($right['score']) ? (int) $right['score'] : 0;
        if ($left_score !== $right_score) {
            return $right_score <=> $left_score;
        }

        $left_area = isset($left['area']) ? (int) $left['area'] : ((isset($left['width'], $left['height'])) ? ((int) $left['width'] * (int) $left['height']) : 0);
        $right_area = isset($right['area']) ? (int) $right['area'] : ((isset($right['width'], $right['height'])) ? ((int) $right['width'] * (int) $right['height']) : 0);
        if ($left_area !== $right_area) {
            return $right_area <=> $left_area;
        }

        $left_offset = isset($left['tag_offset']) ? (int) $left['tag_offset'] : (isset($left['offset']) ? (int) $left['offset'] : 0);
        $right_offset = isset($right['tag_offset']) ? (int) $right['tag_offset'] : (isset($right['offset']) ? (int) $right['offset'] : 0);
        return $left_offset <=> $right_offset;
    }

private function sort_lcp_candidates_by_score_then_area(array $candidates)
    {
        usort($candidates, function ($left, $right) {
            return $this->compare_lcp_candidates_by_score_then_area($left, $right);
        });
        return $candidates;
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

        if (function_exists('ultracache_prefer_existing_nextgen_public_image_url')) {
            $preferred = ultracache_prefer_existing_nextgen_public_image_url($url);
            $preferred = $this->normalize_public_resource_url($preferred);
            return '' !== $preferred ? $preferred : $url;
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

private function extract_candidate_urls_from_srcset($srcset)
    {
        return ultracache_extract_srcset_urls((string) $srcset);
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

private function is_lcp_candidate_video_url($src)
    {
        $src = $this->normalize_public_resource_url($src);
        if ('' === $src || 0 === strpos($src, 'data:')) {
            return false;
        }

        return (bool) preg_match('/\.(mp4|m4v|webm|ogv|ogg|mov|qt|m3u8)(?:$|[?#])/i', $src);
    }

private function is_lcp_candidate_image_url($src)
    {
        $src = $this->normalize_public_resource_url($src);
        if ('' === $src || 0 === strpos($src, 'data:')) {
            return false;
        }

        if (preg_match('/\.ico(?:$|[?#])/i', $src)) {
            return false;
        }

        return (bool) preg_match('/\.(avif|webp|png|jpe?g|gif|bmp|heic|heif|svg)(?:$|[?#])/i', $src);
    }
}
