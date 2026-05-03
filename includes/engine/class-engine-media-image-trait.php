<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Media_Image_Trait
{
        private function inject_safe_cls_dimensions($html)
        {
            $result = array(
                'html' => $html,
                'stats' => $this->get_default_safe_cls_dimension_stats(),
            );

            if (!is_string($html) || '' === $html || false === stripos($html, '<img')) {
                return $result;
            }

            // The tag processor is precise, but it scans every HTML tag and was measured as
            // expensive on large Elementor/WooCommerce pages. The regex implementation only walks
            // <img> tags and uses the same dimension resolver, so make it the default for STORE
            // path performance. A filter keeps the processor available for targeted debugging.
            $use_tag_processor = (bool) apply_filters('ucwp_cls_dimensions_use_html_tag_processor', false);
            if ($use_tag_processor && class_exists('WP_HTML_Tag_Processor')) {
                return $this->inject_safe_cls_dimensions_with_tag_processor($html);
            }

            return $this->inject_safe_cls_dimensions_with_regex($html);
        }

        private function get_default_safe_cls_dimension_stats()
        {
            return array(
                'scanned' => 0,
                'injected' => 0,
                'skipped' => 0,
                'unresolved' => 0,
            );
        }

        private function inject_safe_cls_dimensions_with_tag_processor($html)
        {
            $stats = $this->get_default_safe_cls_dimension_stats();
            $processor = new WP_HTML_Tag_Processor($html);

            while ($processor->next_tag()) {
                if ('IMG' !== strtoupper((string) $processor->get_tag())) {
                    continue;
                }

                $stats['scanned']++;

                $width = $this->parse_positive_dimension_value($processor->get_attribute('width'));
                $height = $this->parse_positive_dimension_value($processor->get_attribute('height'));
                if ($width > 0 && $height > 0) {
                    $stats['skipped']++;
                    continue;
                }

                $source_url = $this->extract_best_img_source_from_attributes(array(
                    'src' => $processor->get_attribute('src'),
                    'data-src' => $processor->get_attribute('data-src'),
                    'data-lazy-src' => $processor->get_attribute('data-lazy-src'),
                    'srcset' => $processor->get_attribute('srcset'),
                    'data-srcset' => $processor->get_attribute('data-srcset'),
                    'data-lazy-srcset' => $processor->get_attribute('data-lazy-srcset'),
                ));

                if ('' === $source_url) {
                    $stats['unresolved']++;
                    continue;
                }

                $resolution = $this->resolve_safe_cls_dimensions_for_image_url($source_url);
                if (!empty($resolution['skipped'])) {
                    $stats['skipped']++;
                    continue;
                }

                $resolved_width = max(0, (int) ($resolution['width'] ?? 0));
                $resolved_height = max(0, (int) ($resolution['height'] ?? 0));
                if ($resolved_width < 1 || $resolved_height < 1) {
                    $stats['unresolved']++;
                    continue;
                }

                $changed = false;
                if ($width < 1) {
                    $processor->set_attribute('width', (string) $resolved_width);
                    $changed = true;
                }
                if ($height < 1) {
                    $processor->set_attribute('height', (string) $resolved_height);
                    $changed = true;
                }

                if ($changed) {
                    $stats['injected']++;
                } else {
                    $stats['skipped']++;
                }
            }

            return array(
                'html' => $processor->get_updated_html(),
                'stats' => $stats,
            );
        }

        private function inject_safe_cls_dimensions_with_regex($html)
        {
            $stats = $this->get_default_safe_cls_dimension_stats();

            $updated = (string) preg_replace_callback(
                '/<img\b[^>]*>/i',
                function ($matches) use (&$stats) {
                    $tag = (string) $matches[0];
                    $stats['scanned']++;

                    $width = $this->parse_positive_dimension_value($this->extract_attribute_from_html_tag($tag, 'width'));
                    $height = $this->parse_positive_dimension_value($this->extract_attribute_from_html_tag($tag, 'height'));
                    if ($width > 0 && $height > 0) {
                        $stats['skipped']++;
                        return $tag;
                    }

                    $source_url = $this->extract_best_img_source_from_attributes(array(
                        'src' => $this->extract_attribute_from_html_tag($tag, 'src'),
                        'data-src' => $this->extract_attribute_from_html_tag($tag, 'data-src'),
                        'data-lazy-src' => $this->extract_attribute_from_html_tag($tag, 'data-lazy-src'),
                        'srcset' => $this->extract_attribute_from_html_tag($tag, 'srcset'),
                        'data-srcset' => $this->extract_attribute_from_html_tag($tag, 'data-srcset'),
                        'data-lazy-srcset' => $this->extract_attribute_from_html_tag($tag, 'data-lazy-srcset'),
                    ));

                    if ('' === $source_url) {
                        $stats['unresolved']++;
                        return $tag;
                    }

                    $resolution = $this->resolve_safe_cls_dimensions_for_image_url($source_url);
                    if (!empty($resolution['skipped'])) {
                        $stats['skipped']++;
                        return $tag;
                    }

                    $resolved_width = max(0, (int) ($resolution['width'] ?? 0));
                    $resolved_height = max(0, (int) ($resolution['height'] ?? 0));
                    if ($resolved_width < 1 || $resolved_height < 1) {
                        $stats['unresolved']++;
                        return $tag;
                    }

                    $updated_tag = $tag;
                    $changed = false;
                    if ($width < 1) {
                        $updated_tag = $this->set_or_add_html_tag_attribute($updated_tag, 'width', (string) $resolved_width);
                        $changed = true;
                    }
                    if ($height < 1) {
                        $updated_tag = $this->set_or_add_html_tag_attribute($updated_tag, 'height', (string) $resolved_height);
                        $changed = true;
                    }

                    if ($changed) {
                        $stats['injected']++;
                        return $updated_tag;
                    }

                    $stats['skipped']++;
                    return $tag;
                },
                $html
            );

            return array(
                'html' => $updated,
                'stats' => $stats,
            );
        }

        private function extract_best_img_source_from_attributes(array $attributes)
        {
            foreach (array('src', 'data-src', 'data-lazy-src') as $key) {
                $value = isset($attributes[$key]) ? trim((string) $attributes[$key]) : '';
                if ('' !== $value) {
                    return $value;
                }
            }

            foreach (array('srcset', 'data-srcset', 'data-lazy-srcset') as $key) {
                $value = isset($attributes[$key]) ? (string) $attributes[$key] : '';
                $urls = $this->extract_candidate_urls_from_srcset($value);
                if (!empty($urls[0])) {
                    return (string) $urls[0];
                }
            }

            return '';
        }

        private function resolve_safe_cls_dimensions_for_image_url($url)
        {
            $absolute_url = $this->absolutize_public_resource_url($url, home_url('/'));
            if ('' === $absolute_url || !$this->is_safe_local_public_image_url($absolute_url)) {
                return array('skipped' => true);
            }

            $cache_url = $this->normalize_public_resource_url($absolute_url);
            if ('' !== $cache_url) {
                $fragment_pos = strpos($cache_url, '#');
                if (false !== $fragment_pos) {
                    $cache_url = substr($cache_url, 0, $fragment_pos);
                }
                $query_pos = strpos($cache_url, '?');
                if (false !== $query_pos) {
                    $cache_url = substr($cache_url, 0, $query_pos);
                }
            }

            $cache_key = '' !== $cache_url ? md5($cache_url) : '';
            if ('' !== $cache_key && isset($this->cls_dimension_resolution_cache_current_request[$cache_key])) {
                return $this->cls_dimension_resolution_cache_current_request[$cache_key];
            }

            $resolution = $this->get_uncached_safe_cls_dimensions_for_image_url($absolute_url);
            if ('' !== $cache_key) {
                $this->cls_dimension_resolution_cache_current_request[$cache_key] = $resolution;
            }

            return $resolution;
        }

        private function get_uncached_safe_cls_dimensions_for_image_url($absolute_url)
        {
            $converted_source_dimensions = $this->get_source_dimensions_for_ultracache_converted_image_url($absolute_url);
            if ($converted_source_dimensions['width'] > 0 && $converted_source_dimensions['height'] > 0) {
                return $converted_source_dimensions;
            }

            $attachment_dimensions = $this->get_attachment_dimensions_for_public_image_url($absolute_url);
            if ($attachment_dimensions['width'] > 0 && $attachment_dimensions['height'] > 0) {
                return $attachment_dimensions;
            }

            $file_dimensions = $this->get_local_file_dimensions_for_public_image_url($absolute_url);
            if ($file_dimensions['width'] > 0 && $file_dimensions['height'] > 0) {
                return $file_dimensions;
            }

            return array(
                'width' => 0,
                'height' => 0,
                'source' => '',
                'skipped' => false,
            );
        }

        private function get_source_dimensions_for_ultracache_converted_image_url($url)
        {
            $source_url = $this->map_ultracache_converted_image_url_to_upload_url($url);
            if ('' === $source_url) {
                return array('width' => 0, 'height' => 0, 'source' => '');
            }

            $attachment_dimensions = $this->get_attachment_dimensions_for_public_image_url($source_url);
            if ($attachment_dimensions['width'] > 0 && $attachment_dimensions['height'] > 0) {
                $attachment_dimensions['source'] = 'converted-source-attachment-metadata';
                return $attachment_dimensions;
            }

            $file_dimensions = $this->get_local_file_dimensions_for_public_image_url($source_url);
            if ($file_dimensions['width'] > 0 && $file_dimensions['height'] > 0) {
                $file_dimensions['source'] = 'converted-source-file-dimensions';
                return $file_dimensions;
            }

            return array('width' => 0, 'height' => 0, 'source' => '');
        }

        private function map_ultracache_converted_image_url_to_upload_url($url)
        {
            $url = $this->normalize_public_resource_url($url);
            if ('' === $url) {
                return '';
            }

            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if ('' === $path) {
                return '';
            }

            $content_path = rtrim((string) wp_parse_url(content_url('/'), PHP_URL_PATH), '/');
            $cache_prefixes = array(
                $content_path . '/uploads/uc-images/avif/',
                $content_path . '/uploads/uc-images/webp/',
            );

            $relative = '';
            foreach ($cache_prefixes as $prefix) {
                if ('' !== $prefix && 0 === strpos($path, $prefix)) {
                    $relative = ltrim(substr($path, strlen($prefix)), '/');
                    break;
                }
            }

            if ('' === $relative || false !== strpos($relative, '..')) {
                return '';
            }

            $relative_dir = trim(str_replace('\\', '/', dirname($relative)), '. /');
            $stem = pathinfo($relative, PATHINFO_FILENAME);
            if ('' === $stem) {
                return '';
            }

            $uploads = wp_get_upload_dir();
            if (empty($uploads['basedir']) || empty($uploads['baseurl'])) {
                return '';
            }

            $candidate_dir = trailingslashit((string) $uploads['basedir']) . ('' !== $relative_dir ? trailingslashit($relative_dir) : '');
            $candidate_url_dir = trailingslashit((string) $uploads['baseurl']) . ('' !== $relative_dir ? trailingslashit($relative_dir) : '');
            $extensions = array('jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp');

            foreach ($extensions as $extension) {
                $candidate_file = $candidate_dir . $stem . '.' . $extension;
                if (is_readable($candidate_file) && is_file($candidate_file)) {
                    return $candidate_url_dir . rawurlencode($stem . '.' . $extension);
                }
            }

            return '';
        }

        private function is_safe_local_public_image_url($url)
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
            if ('' === $extension || in_array($extension, array('svg', 'php'), true)) {
                return false;
            }

            return (bool) preg_match('/^(?:jpe?g|png|gif|webp|avif|bmp)$/i', $extension);
        }

        private function get_attachment_dimensions_for_public_image_url($url)
        {
            $normalized = $this->normalize_public_resource_url($url);
            if ('' === $normalized || !function_exists('attachment_url_to_postid')) {
                return array('width' => 0, 'height' => 0, 'source' => '');
            }

            $clean = $normalized;
            $fragment_pos = strpos($clean, '#');
            if (false !== $fragment_pos) {
                $clean = substr($clean, 0, $fragment_pos);
            }
            $query_pos = strpos($clean, '?');
            if (false !== $query_pos) {
                $clean = substr($clean, 0, $query_pos);
            }

            $attachment_id = (int) attachment_url_to_postid($clean);
            if ($attachment_id < 1) {
                return array('width' => 0, 'height' => 0, 'source' => '');
            }

            $meta = wp_get_attachment_metadata($attachment_id);
            if (!is_array($meta)) {
                return array('width' => 0, 'height' => 0, 'source' => '');
            }

            $requested_path = (string) wp_parse_url($clean, PHP_URL_PATH);
            $requested_basename = '' !== $requested_path ? wp_basename($requested_path) : '';
            if ('' === $requested_basename) {
                return array('width' => 0, 'height' => 0, 'source' => '');
            }

            $original_file = !empty($meta['file']) ? wp_basename((string) $meta['file']) : '';
            if ('' !== $original_file && $original_file === $requested_basename) {
                $width = max(0, (int) ($meta['width'] ?? 0));
                $height = max(0, (int) ($meta['height'] ?? 0));
                if ($width > 0 && $height > 0) {
                    return array(
                        'width' => $width,
                        'height' => $height,
                        'source' => 'attachment-metadata',
                        'skipped' => false,
                    );
                }
            }

            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $size_meta) {
                    if (!is_array($size_meta)) {
                        continue;
                    }

                    $size_file = !empty($size_meta['file']) ? wp_basename((string) $size_meta['file']) : '';
                    if ('' === $size_file || $size_file !== $requested_basename) {
                        continue;
                    }

                    $width = max(0, (int) ($size_meta['width'] ?? 0));
                    $height = max(0, (int) ($size_meta['height'] ?? 0));
                    if ($width > 0 && $height > 0) {
                        return array(
                            'width' => $width,
                            'height' => $height,
                            'source' => 'attachment-metadata',
                            'skipped' => false,
                        );
                    }
                }
            }

            return array('width' => 0, 'height' => 0, 'source' => '');
        }

        private function get_local_file_dimensions_for_public_image_url($url)
        {
            $path = $this->resolve_local_path_from_public_url($url);
            if ('' === $path || !is_readable($path) || !is_file($path)) {
                return array('width' => 0, 'height' => 0, 'source' => '');
            }

            $size = false;
            if (function_exists('wp_getimagesize')) {
                $size = wp_getimagesize($path);
            } elseif (function_exists('getimagesize')) {
                $size = getimagesize($path);
            }

            if (!is_array($size) || empty($size[0]) || empty($size[1])) {
                return array('width' => 0, 'height' => 0, 'source' => '');
            }

            return array(
                'width' => max(0, (int) $size[0]),
                'height' => max(0, (int) $size[1]),
                'source' => 'file-dimensions',
                'skipped' => false,
            );
        }

        private function parse_positive_dimension_value($value)
        {
            if (!is_scalar($value)) {
                return 0;
            }

            $value = trim((string) $value);
            if ('' === $value || !preg_match('/^\d+$/', $value)) {
                return 0;
            }

            return max(0, (int) $value);
        }

}
