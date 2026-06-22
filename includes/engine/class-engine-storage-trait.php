<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!trait_exists('Ultra_Cache_Engine_Storage_Trait')) {
    trait Ultra_Cache_Engine_Storage_Trait
    {
        private $last_cache_write_error = array();
        private $last_atomic_write_error = array();

        private function reset_cache_write_error()
        {
            $this->last_cache_write_error = array();
        }

        private function set_cache_write_error($code, $message, array $context = array())
        {
            $this->last_cache_write_error = array_merge(
                array(
                    'code'    => sanitize_key((string) $code),
                    'message' => sanitize_text_field((string) $message),
                ),
                $context
            );
        }

        private function get_last_cache_write_error()
        {
            return is_array($this->last_cache_write_error) ? $this->last_cache_write_error : array();
        }

        private function get_last_cache_write_error_message()
        {
            $error = $this->get_last_cache_write_error();
            if (empty($error['message'])) {
                return '';
            }

            $message = (string) $error['message'];
            if (!empty($error['code'])) {
                $message .= ' [' . (string) $error['code'] . ']';
            }

            if (!empty($error['missing']) && is_array($error['missing'])) {
                $message .= ' Missing: ' . implode(', ', array_slice(array_map('strval', $error['missing']), 0, 5));
            }

            if (!empty($error['file'])) {
                $message .= ' File: ' . basename((string) $error['file']);
            }

            return $message;
        }

        private function reset_atomic_write_error()
        {
            $this->last_atomic_write_error = array();
        }

        private function set_atomic_write_error($code, $message, array $context = array())
        {
            $this->last_atomic_write_error = array_merge(
                array(
                    'code'    => sanitize_key((string) $code),
                    'message' => sanitize_text_field((string) $message),
                ),
                $context
            );
        }

        private function get_last_atomic_write_error()
        {
            return is_array($this->last_atomic_write_error) ? $this->last_atomic_write_error : array();
        }

        private function wait_for_page_cache_file($file_path, $timeout_seconds = 12.0)
        {
            $file_path = (string) $file_path;
            if ('' === $file_path) {
                return false;
            }

            $deadline = microtime(true) + max(0.5, (float) $timeout_seconds);
            do {
                clearstatcache(true, $file_path);
                if (is_readable($file_path) && filesize($file_path) > 255) {
                    return true;
                }
                usleep(200000);
            } while (microtime(true) < $deadline);

            return false;
        }

        private function is_force_refresh_loopback_request()
        {
            $force_refresh = sanitize_text_field(ultracache_server_value('HTTP_X_ULTRACACHE_FORCE_REFRESH'));
            if ('1' !== $force_refresh && 'true' !== strtolower((string) $force_refresh)) {
                return false;
            }

            $internal = sanitize_text_field(ultracache_server_value('HTTP_X_ULTRACACHE_INTERNAL_REQUEST'));
            $warm = sanitize_text_field(ultracache_server_value('HTTP_X_ULTRACACHE_WARM'));
            return '1' === $internal || '1' === $warm;
        }

        private function maybe_serve_cached_file_during_wp_boot($reason = 'early-hit')
        {
            if ($this->is_force_refresh_loopback_request()) {
                if (!headers_sent()) {
                    header('X-Ultra-Cache-Force-Refresh: wp-engine');
                }
                return false;
            }

            if ($this->is_profile_bypass_request()) {
                if (!headers_sent()) {
                    header('X-Ultra-Cache-Profile-Bypass: wp-engine');
                }
                return false;
            }

            $this->profile_request_checkpoint('early_hit_before_current_url');
            $url = $this->get_current_request_url();
            $this->profile_request_checkpoint('early_hit_after_current_url', array('url_length' => strlen((string) $url)));
            if ('' === $url) {
                return false;
            }

            $this->profile_request_checkpoint('early_hit_before_cache_path');
            $file_path = $this->get_cache_path($url);
            $this->profile_request_checkpoint('early_hit_after_cache_path', array('file_path_empty' => '' === (string) $file_path ? 'yes' : 'no'));
            $this->profile_request_checkpoint('early_hit_before_file_stat');
            if ('' === $file_path || !is_readable($file_path) || filesize($file_path) <= 255) {
                $this->profile_request_checkpoint('early_hit_no_file_return');
                return false;
            }
            $this->profile_request_checkpoint('early_hit_after_file_stat');

            return $this->maybe_serve_cache_file_path($file_path, 'HIT', $reason);
        }

        private function maybe_serve_cache_file_path($file_path, $status = 'HIT', $reason = '')
        {
            $file_path = (string) $file_path;
            $cache_root = defined('ULTRACACHE_CACHE_DIR') ? ultracache_normalize_filesystem_path_for_guard(ULTRACACHE_CACHE_DIR) : '';
            $resolved_file = '' !== $file_path ? ultracache_normalize_filesystem_path_for_guard($file_path) : '';
            $valid_name = '' !== $resolved_file
                && 1 === preg_match('/\Aindex-(?:orig|avif|webp)-[a-f0-9]{32}\.html\z/', basename($resolved_file));

            if (
                '' === $cache_root
                || '' === $resolved_file
                || !$valid_name
                || !ultracache_path_is_within_root($resolved_file, $cache_root)
                || !is_readable($resolved_file)
            ) {
                return false;
            }

            $file_path = $resolved_file;
            $this->profile_request_checkpoint('early_hit_before_file_read', array('file' => basename((string) $file_path)));
            $html = ultracache_safe_file_get_contents($file_path);
            $this->profile_request_checkpoint('early_hit_after_file_read', array('html_bytes' => is_string($html) ? strlen($html) : 0));
            if (!is_string($html) || '' === $html) {
                return false;
            }

            $mtime = filemtime($file_path);
            $age = $mtime ? max(0, time() - (int) $mtime) : 0;
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=UTF-8');
                header('Vary: Accept, Accept-Encoding', false);
                header('X-Ultra-Cache: ' . strtoupper((string) $status));
                if ($this->should_send_source_debug_header()) {
                    header('X-Ultra-Cache-Source: wp-engine');
                }
                header('X-Ultra-Cache-Age: ' . (string) $age);
                if ('' !== (string) $reason) {
                    header('X-Ultra-Cache-Reason: ' . substr(preg_replace('/[^A-Za-z0-9_. -]/', '-', (string) $reason), 0, 120));
                }
            }

            $this->record_analytics_hit();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Full-page cache payload; canonical cache-root and strict UltraCache filename validation are enforced immediately before output.
            echo $html;
            return true;
        }

        private function validate_cached_html_css_bundle_refs($html, $cache_file = '')
        {
            $html = (string) $html;
            if ('' === $html || !ultracache_generated_asset_reference_matches($html)) {
                return true;
            }

            $has_generated_css = ultracache_generated_asset_reference_matches($html, array('css-bundles', 'font-css', 'optimized-css'));
            if (!$has_generated_css) {
                return true;
            }

            $this->profile_request_checkpoint('css_bundle_ref_validation_before_scan');
            $missing = $this->get_missing_css_bundle_refs_from_html($html);
            $this->profile_request_checkpoint('css_bundle_ref_validation_after_scan', array('missing_count' => count($missing)));
            if (empty($missing)) {
                return true;
            }

            $cache_file = (string) $cache_file;
            if ('' !== $cache_file) {
                ultracache_safe_unlink($cache_file);
                ultracache_safe_unlink($cache_file . '.gz');
                ultracache_safe_unlink($cache_file . '.br');
            }

            $this->record_cache_event('stale-generated-css-html-invalidated', array(
                'file' => $cache_file,
                'missing' => array_slice(array_values($missing), 0, 20),
                'missing_count' => count($missing),
            ));

            if (!headers_sent()) {
                header('X-Ultra-Cache-Stale-Generated-CSS: invalidated');
            }

            return false;
        }

        private function get_missing_css_bundle_refs_from_html($html)
        {
            $html = (string) $html;
            $missing = array();
            $generated_base_path = function_exists('ultracache_generated_asset_public_path') ? ultracache_generated_asset_public_path() : '';
            if ('' === $html || '' === $generated_base_path || false === stripos($html, trim($generated_base_path, '/'))) {
                return $missing;
            }

            $generated_base_pattern = preg_quote(trailingslashit($generated_base_path), '~');
            $generated_asset_patterns = array(
                '~(?:https?:)?//[^\s\"\'<>]+' . $generated_base_pattern . '(?:css-bundles|font-css|optimized-css)/[^\s\"\'<>?#)]+\.css~i',
                '~' . $generated_base_pattern . '(?:css-bundles|font-css|optimized-css)/[^\s\"\'<>?#)]+\.css~i',
            );


            $refs = array();
            $collect_generated_refs = function ($value) use (&$refs, $generated_asset_patterns) {
                $value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
                if ('' === $value || !ultracache_generated_asset_reference_matches($value)) {
                    return;
                }

                foreach ($generated_asset_patterns as $generated_asset_pattern) {
                    $matches = array();
                    $matched = preg_match_all($generated_asset_pattern, $value, $matches);
                    if (false === $matched || empty($matches[0]) || !is_array($matches[0])) {
                        continue;
                    }

                    foreach ($matches[0] as $match) {
                        $refs[] = (string) $match;
                    }
                }
            };

            /*
             * Only validate load-bearing generated CSS references. UltraCache stores
             * internal source/debug metadata in data-* attributes such as
             * data-ultracache-css-bundle-original-href. Those metadata URLs are not browser
             * requests and must not block page-cache writes when their generated
             * intermediate files have already been bundled/removed.
             */
            $link_tags = array();
            if (preg_match_all('#<link\b[^>]*>#i', $html, $link_tags) && !empty($link_tags[0])) {
                foreach ($link_tags[0] as $link_tag) {
                    $attr_matches = array();
                    if (!preg_match_all('/\s([A-Za-z_:][-A-Za-z0-9_:.]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/u', (string) $link_tag, $attr_matches, PREG_SET_ORDER)) {
                        continue;
                    }

                    foreach ($attr_matches as $attr_match) {
                        $attr_name = strtolower((string) $attr_match[1]);
                        if ('href' !== $attr_name) {
                            continue;
                        }

                        $attr_value = isset($attr_match[3]) && '' !== $attr_match[3]
                            ? (string) $attr_match[3]
                            : (isset($attr_match[4]) && '' !== $attr_match[4] ? (string) $attr_match[4] : (string) $attr_match[5]);
                        $collect_generated_refs($attr_value);
                    }
                }
            }

            $style_blocks = array();
            if (preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $html, $style_blocks) && !empty($style_blocks[1])) {
                foreach ($style_blocks[1] as $style_block) {
                    $collect_generated_refs($style_block);
                }
            }

            $style_attr_tags = array();
            if (preg_match_all('#<[^>]+\sstyle\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)[^>]*>#i', $html, $style_attr_tags) && !empty($style_attr_tags[0])) {
                foreach ($style_attr_tags[0] as $tag_with_style) {
                    $attr_matches = array();
                    if (!preg_match_all('/\s([A-Za-z_:][-A-Za-z0-9_:.]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/u', (string) $tag_with_style, $attr_matches, PREG_SET_ORDER)) {
                        continue;
                    }

                    foreach ($attr_matches as $attr_match) {
                        $attr_name = strtolower((string) $attr_match[1]);
                        if ('style' !== $attr_name) {
                            continue;
                        }

                        $attr_value = isset($attr_match[3]) && '' !== $attr_match[3]
                            ? (string) $attr_match[3]
                            : (isset($attr_match[4]) && '' !== $attr_match[4] ? (string) $attr_match[4] : (string) $attr_match[5]);
                        $collect_generated_refs($attr_value);
                    }
                }
            }

            $refs = array_values(array_unique(array_map('strval', $refs)));
            $allowed_dirs = array(
                'css-bundles' => wp_normalize_path(ultracache_generated_asset_dir('css-bundles')),
                'font-css' => wp_normalize_path(ultracache_generated_asset_dir('font-css')),
                'optimized-css' => wp_normalize_path(ultracache_generated_asset_dir('optimized-css')),
            );
            foreach ($refs as $ref) {
                $path = (string) wp_parse_url($ref, PHP_URL_PATH);
                if ('' === $path) {
                    $path = $ref;
                }

                $generated_base_path = function_exists('ultracache_generated_asset_public_path') ? ultracache_generated_asset_public_path() : '';
                $generated_ref_pattern = '' !== $generated_base_path ? '#^' . preg_quote(trailingslashit($generated_base_path), '#') . '(css-bundles|font-css|optimized-css)/([^/]+\.css)$#i' : '';
                if ('' === $generated_ref_pattern || !preg_match($generated_ref_pattern, rawurldecode($path), $match)) {
                    continue;
                }

                $bucket = strtolower((string) $match[1]);
                $basename = basename((string) $match[2]);
                if ('' === $basename || empty($allowed_dirs[$bucket]) || false === preg_match('/^[A-Za-z0-9_.-]+\.css$/', $basename)) {
                    continue;
                }

                $file = wp_normalize_path($allowed_dirs[$bucket] . $basename);
                clearstatcache(true, $file);
                if (!is_readable($file) || filesize($file) <= 0) {
                    $missing[$bucket . '/' . $basename] = $bucket . '/' . $basename;
                }
            }

            return array_values($missing);
        }

        private function write_cache_file($file_path, $html)
        {
            $file_path = (string) $file_path;
            $this->reset_cache_write_error();
            $this->set_cache_write_error('started', 'Cache write started.', array('file' => $file_path));

            $html = $this->profile_store_stage('final_google_fonts_rewrite_inside_write', $html, function ($html) {
                return $this->apply_final_google_fonts_rewrite_before_cache_store($html);
            });
            $html = $this->profile_store_stage('final_font_display_rewrite_inside_write', $html, function ($html) {
                return $this->apply_final_font_display_rewrite_before_cache_store($html);
            });
            if (method_exists($this, 'remove_hrefless_ultracache_link_placeholders')) {
                $html = $this->profile_store_stage('remove_hrefless_link_placeholders_inside_write', $html, function ($html) {
                    return $this->remove_hrefless_ultracache_link_placeholders($html);
                });
            }

            $dir = dirname($file_path);
            if (!file_exists($dir) && !ultracache_safe_mkdir($dir, 0755, true) && !file_exists($dir)) {
                $this->set_cache_write_error('mkdir_failed', 'Could not create cache directory.', array('file' => $file_path, 'dir' => $dir));
                $this->record_cache_event('store-mkdir-failed', array('file' => $file_path, 'dir' => $dir));
                return false;
            }

            if (!ultracache_path_is_writable($dir)) {
                $this->set_cache_write_error('dir_not_writable', 'Cache directory is not writable.', array('file' => $file_path, 'dir' => $dir));
                $this->record_cache_event('store-dir-not-writable', array('file' => $file_path, 'dir' => $dir));
                return false;
            }

            if ($this->page_cache_variant_cap_reached($file_path)) {
                $this->set_cache_write_error('variant_cap_reached', 'Page cache variant cap reached for this URL/bucket.', array('file' => $file_path));
                $this->record_cache_event('variant-cap', array('file' => $file_path));
                return false;
            }

            $write_lock_name = 'page-cache-write-' . md5((string) $file_path);
            if (!$this->acquire_runtime_lock($write_lock_name, 90)) {
                $this->set_cache_write_error('write_lock_busy', 'Page cache write lock is busy.', array('file' => $file_path));
                $this->record_cache_event('store-write-lock-busy', array('file' => $file_path));
                return false;
            }

            try {
                $missing_css_refs = $this->get_missing_css_bundle_refs_from_html($html);
                if (!empty($missing_css_refs)) {
                    $this->set_cache_write_error('missing_generated_css_refs', 'Cached HTML references generated CSS files that are missing on disk.', array(
                        'file' => $file_path,
                        'missing' => array_slice(array_values($missing_css_refs), 0, 20),
                        'missing_count' => count($missing_css_refs),
                    ));
                    $this->record_cache_event('skip-store-missing-css-bundle-ref', array(
                        'file' => $file_path,
                        'missing' => array_slice(array_values($missing_css_refs), 0, 20),
                        'missing_count' => count($missing_css_refs),
                    ));
                    return false;
                }

                if (!$this->write_cache_variant_atomically($file_path, $html)) {
                    $atomic_error = $this->get_last_atomic_write_error();
                    $this->set_cache_write_error(
                        !empty($atomic_error['code']) ? (string) $atomic_error['code'] : 'atomic_write_failed',
                        !empty($atomic_error['message']) ? (string) $atomic_error['message'] : 'Atomic cache write failed.',
                        array_merge(array('file' => $file_path), is_array($atomic_error) ? $atomic_error : array())
                    );
                    $this->record_cache_event('store-atomic-write-failed', $this->get_last_cache_write_error());
                    return false;
                }

                $settings = $this->get_settings();
                if (!empty($settings['gzip_enabled']) && function_exists('gzencode')) {
                    self::gz_file_put_contents($file_path . '.gz', $html);
                } else {
                    ultracache_safe_unlink($file_path . '.gz');
                }

                if (!empty($settings['brotli_enabled']) && function_exists('brotli_compress')) {
                    $compressed = brotli_compress($html, 5, BROTLI_TEXT);
                    if (false !== $compressed) {
                        $this->write_cache_variant_atomically($file_path . '.br', $compressed);
                    }
                } else {
                    ultracache_safe_unlink($file_path . '.br');
                }

                if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'track_cache_asset_refs_for_file')) {
                    Ultra_Cache_WP::track_cache_asset_refs_for_file($file_path, $html);
                }

                $this->set_cache_write_error('ok', 'Cache file written successfully.', array('file' => $file_path));
                return true;
            } finally {
                $this->release_runtime_lock($write_lock_name);
            }
        }

        private function get_page_cache_variant_cap_per_bucket()
        {
            /**
             * Safety cap for same path + same image bucket HTML variants.
             * Normal operation should produce one hash per bucket for a plain URL.
             * Extra variants are only expected for explicitly allowlisted query args.
             */
            $cap = (int) apply_filters('ultracache_page_cache_variant_cap_per_bucket', 8);
            return max(3, min(50, $cap));
        }

        private function page_cache_variant_cap_reached($file_path)
        {
            $file_path = (string) $file_path;
            if ('' === $file_path || file_exists($file_path)) {
                return false;
            }

            $basename = basename($file_path);
            if (!preg_match('/^index-(orig|webp|avif)-[a-f0-9]{32}\.html$/', $basename, $matches)) {
                return false;
            }

            $dir = dirname($file_path);
            if (!is_dir($dir) || !is_readable($dir)) {
                return false;
            }

            $bucket = $matches[1];
            $pattern = trailingslashit($dir) . 'index-' . $bucket . '-*.html';
            $existing = glob($pattern);
            if (!is_array($existing)) {
                return false;
            }

            return count($existing) >= $this->get_page_cache_variant_cap_per_bucket();
        }

        private function write_cache_variant_atomically($path, $contents)
        {
            $path = (string) $path;
            $this->reset_atomic_write_error();

            $dir = dirname($path);
            if (!file_exists($dir) && !ultracache_safe_mkdir($dir, 0755, true) && !file_exists($dir)) {
                $this->set_atomic_write_error('atomic_mkdir_failed', 'Could not create cache directory for atomic write.', array('path' => $path, 'dir' => $dir));
                return false;
            }

            if (!ultracache_path_is_writable($dir)) {
                $this->set_atomic_write_error('atomic_dir_not_writable', 'Cache directory is not writable for atomic write.', array('path' => $path, 'dir' => $dir));
                return false;
            }

            $tmp = $path . '.tmp-' . uniqid('', true);
            $result = ultracache_safe_file_put_contents($tmp, $contents, LOCK_EX);
            if (false === $result) {
                $this->set_atomic_write_error('atomic_tmp_write_failed', 'Could not write temporary cache file.', array('path' => $path, 'tmp' => $tmp));
                ultracache_safe_unlink($tmp);
                return false;
            }

            if (!ultracache_safe_rename($tmp, $path)) {
                $this->set_atomic_write_error('atomic_rename_failed', 'Could not rename temporary cache file into place.', array('path' => $path, 'tmp' => $tmp));
                ultracache_safe_unlink($tmp);
                return false;
            }

            clearstatcache(true, $tmp);
            clearstatcache(true, $path);
            if (!file_exists($path) || file_exists($tmp)) {
                $this->set_atomic_write_error('atomic_final_missing', 'Atomic write finished but final cache file was not found.', array('path' => $path, 'tmp' => $tmp));
                ultracache_safe_unlink($tmp);
                return false;
            }

            $this->set_atomic_write_error('ok', 'Atomic write completed.', array('path' => $path));
            return true;
        }

        private static function gz_file_put_contents($path, $html)
        {
            $gz = gzencode($html, 9);
            if (false === $gz) {
                return;
            }

            $dir = dirname($path);
            if (!file_exists($dir) && !ultracache_safe_mkdir($dir, 0755, true) && !file_exists($dir)) {
                return;
            }

            $tmp = $path . '.tmp-' . uniqid('', true);
            if (false === ultracache_safe_file_put_contents($tmp, $gz, LOCK_EX)) {
                ultracache_safe_unlink($tmp);
                return;
            }

            if (!ultracache_safe_rename($tmp, $path)) {
                ultracache_safe_unlink($tmp);
            }
        }

        private function normalize_path_value($path)
        {
            $path = '/' . ltrim((string) $path, '/');
            return '/' === $path ? '/' : trailingslashit(rtrim($path, '/'));
        }

        private function matches_path_rule($path, $rule)
        {
            $path = $this->normalize_path_value($path);
            $rule = trim((string) $rule);
            if ('' === $rule) {
                return false;
            }

            $wildcard = false;
            if ('*' === substr($rule, -1)) {
                $wildcard = true;
                $rule = substr($rule, 0, -1);
            }

            $rule = $this->normalize_path_value($rule);
            if ('/' === $rule) {
                return '/' === $path;
            }

            if ($path === $rule) {
                return true;
            }

            return $wildcard || 0 === strpos($path, $rule);
        }

        private function path_matches_any_rule($path, array $rules)
        {
            foreach ($rules as $rule) {
                if ($this->matches_path_rule($path, $rule)) {
                    return true;
                }
            }

            return false;
        }

        private function get_revalidate_lock_path($url)
        {
            $file = $this->get_cache_path($url, 'orig');
            return $file ? $file . '.revalidate.lock' : '';
        }

        private function get_runtime_lock_file($name)
        {
            $safe = preg_replace('/[^a-z0-9_-]/', '-', strtolower((string) $name));
            $safe = trim((string) $safe, '-');
            if ('' === $safe) {
                $safe = 'runtime';
            }

            $dir = trailingslashit(ULTRACACHE_CACHE_DIR) . 'locks/';
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }

            return $dir . $safe . '.lock';
        }

        private function delete_cache_variants($file)
        {
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'mark_cache_asset_refs_inactive_for_cache_file')) {
                Ultra_Cache_WP::mark_cache_asset_refs_inactive_for_cache_file($file);
            }

            foreach (array($file, $file . '.gz', $file . '.br') as $variant) {
                if (file_exists($variant)) {
                    ultracache_safe_unlink($variant);
                }
            }
        }

        public function get_cache_path($url, $bucket = null)
        {
            $normalized = $this->normalize_url($url);
            if (empty($normalized)) {
                return '';
            }

            $parts = wp_parse_url($normalized);
            $host = isset($parts['host']) ? sanitize_file_name(strtolower((string) $parts['host'])) : 'site';
            $path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';
            $path = preg_replace('#[^A-Za-z0-9/_-]#', '-', $path);
            $path = trim((string) $path, '/');
            if ('' === $path) {
                $path = 'index';
            }

            if (null === $bucket) {
                $bucket = $this->get_request_image_bucket();
            }

            $bucket = in_array((string) $bucket, array('avif', 'webp', 'orig'), true) ? (string) $bucket : 'orig';
            $hash = md5($normalized);
            $base_dir = trailingslashit(ULTRACACHE_CACHE_DIR) . $host . '/' . $path;

            return trailingslashit($base_dir) . 'index-' . $bucket . '-' . $hash . '.html';
        }

        private function get_cache_paths_for_all_buckets($url)
        {
            $files = array();
            foreach (array('orig', 'avif', 'webp') as $bucket) {
                $file = $this->get_cache_path($url, $bucket);
                if ($file) {
                    $files[] = $file;
                }
            }

            return array_values(array_unique($files));
        }

        private function infer_bucket_from_cache_path($path)
        {
            $path = (string) $path;
            if ('' === $path) {
                return '';
            }

            if (false !== strpos($path, 'index-avif-')) {
                return 'avif';
            }

            if (false !== strpos($path, 'index-webp-')) {
                return 'webp';
            }

            if (false !== strpos($path, 'index-orig-')) {
                return 'orig';
            }

            return '';
        }
    }
}
