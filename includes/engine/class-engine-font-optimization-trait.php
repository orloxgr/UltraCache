<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!trait_exists('Ultra_Cache_Engine_Font_Optimization_Trait')) {
    trait Ultra_Cache_Engine_Font_Optimization_Trait
    {
        public function add_display_swap_to_google_fonts($src, $handle)
        {
            /*
             * The style_loader_src filter also runs in wp-admin and during some
             * plugin maintenance requests. Keep the Google Fonts localization
             * workflow frontend-only so admin CSS is never rewritten or delayed.
             */
            if (function_exists('is_admin') && is_admin()) {
                return $src;
            }

            $settings = $this->get_settings();
            $font_url = $this->append_google_fonts_display_swap($src);

            if (!empty($settings['google_fonts_local_optimization'])) {
                $localized = $this->get_google_fonts_url_for_current_request($font_url, true);
                if (is_string($localized) && '' !== $localized) {
                    return $localized;
                }
            }

            if (!empty($settings['google_fonts_swap'])) {
                return $font_url;
            }

            return $src;
        }

        private function canonicalize_google_fonts_stylesheet_url($url)
        {
            $url = $this->decode_google_fonts_html_url((string) $url);
            if ('' === $url) {
                return '';
            }

            if (0 === strpos($url, '//')) {
                $url = 'https:' . $url;
            }

            if (!$this->is_google_fonts_stylesheet_url($url)) {
                return $url;
            }

            $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
            if ('' === $scheme) {
                $url = 'https://' . ltrim($url, '/');
            } elseif ('http' === $scheme) {
                $url = set_url_scheme($url, 'https');
            }

            $fragment_pos = strpos($url, '#');
            if (false !== $fragment_pos) {
                $url = substr($url, 0, $fragment_pos);
            }

            return $url;
        }

        private function append_google_fonts_display_swap($url)
        {
            $url = $this->canonicalize_google_fonts_stylesheet_url($url);
            if ('' === $url || !$this->is_google_fonts_stylesheet_url($url)) {
                return $url;
            }

            $query = wp_parse_url($url, PHP_URL_QUERY);
            if (is_string($query)) {
                parse_str($query, $params);
                if (isset($params['display']) && 'swap' === strtolower((string) $params['display'])) {
                    return $url;
                }
            }

            return add_query_arg('display', 'swap', $url);
        }

        private function apply_final_google_fonts_rewrite_before_cache_store($html)
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            $has_google_fonts_stylesheet = false !== stripos($html, 'fonts.googleapis.com');
            $has_google_fonts_hint = false !== stripos($html, 'fonts.gstatic.com');
            if (!$has_google_fonts_stylesheet && !$has_google_fonts_hint) {
                return $html;
            }

            $settings = $this->get_settings();
            if (!empty($settings['google_fonts_local_optimization'])) {
                if ($has_google_fonts_stylesheet) {
                    return $this->rewrite_google_fonts_links_to_local_in_html($html);
                }

                return $this->remove_google_fonts_remote_resource_hints($html);
            }

            if ($has_google_fonts_stylesheet && !empty($settings['google_fonts_swap'])) {
                return $this->rewrite_google_fonts_display_swap_in_html($html);
            }

            return $html;
        }

        private function rewrite_google_fonts_display_swap_in_html($html)
        {
            if (false === stripos((string) $html, 'fonts.googleapis.com')) {
                return $html;
            }

            $processed = $this->rewrite_google_fonts_link_hrefs_with_processor($html, false);
            if (is_string($processed)) {
                return $processed;
            }

            return $this->rewrite_google_fonts_stylesheet_urls_with_regex((string) $html, false);
        }

        private function rewrite_google_fonts_links_to_local_in_html($html)
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            if (false === stripos($html, 'fonts.googleapis.com')) {
                return false !== stripos($html, 'fonts.gstatic.com') ? $this->remove_google_fonts_remote_resource_hints($html) : $html;
            }

            $processed = $this->rewrite_google_fonts_link_hrefs_with_processor($html, true);
            if (!is_string($processed)) {
                $processed = $this->rewrite_google_fonts_stylesheet_urls_with_regex($html, true);
            }

            if (!is_string($processed)) {
                $processed = $html;
            }

            // After aggressive CSS replacement, the original Google Fonts stylesheet link may have
            // been folded into the generated bundle. In that case only preconnect/dns-prefetch hints
            // can remain in the final HTML. Remove those remote hints whenever local Google Fonts
            // optimization is active so cached HTML does not keep stray fonts.googleapis.com refs.
            return $this->remove_google_fonts_remote_resource_hints($processed);
        }

        private function remove_google_fonts_remote_resource_hints($html)
        {
            $html = (string) $html;
            if ('' === $html || (false === stripos($html, 'fonts.googleapis.com') && false === stripos($html, 'fonts.gstatic.com'))) {
                return $html;
            }

            return $this->safe_google_fonts_preg_replace_callback('/<link\b(?=[^>]*(?:fonts\.googleapis\.com|fonts\.gstatic\.com))(?=[^>]*rel\s*=\s*(["\'])(?:dns-prefetch|preconnect)\1)[^>]*>\s*/i', function () {
                return '';
            }, $html);
        }

        private function rewrite_google_fonts_link_hrefs_with_processor($html, $localize = false)
        {
            if (!$this->html_tag_processor_available()) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor((string) $html);
                $changed = false;

                while ($processor->next_tag()) {
                    if ('LINK' !== strtoupper((string) $processor->get_tag())) {
                        continue;
                    }

                    $href = $processor->get_attribute('href');
                    if (null === $href || false === $href) {
                        continue;
                    }

                    $href = $this->decode_google_fonts_html_url((string) $href);
                    if (!$this->is_google_fonts_stylesheet_url($href)) {
                        continue;
                    }

                    $rel = strtolower((string) $processor->get_attribute('rel'));
                    $as = strtolower((string) $processor->get_attribute('as'));
                    if (false !== strpos($rel, 'preconnect') || false !== strpos($rel, 'dns-prefetch')) {
                        continue;
                    }
                    if ('' !== $rel && false === strpos($rel, 'stylesheet') && !('preload' === $rel && 'style' === $as)) {
                        continue;
                    }

                    $updated_href = $this->append_google_fonts_display_swap($href);
                    if (!empty($localize)) {
                        $localized_href = $this->get_google_fonts_url_for_current_request($updated_href, false);
                        if (is_string($localized_href) && '' !== $localized_href) {
                            $updated_href = $localized_href;
                        }
                    }

                    if ($updated_href !== $href) {
                        $processor->set_attribute('href', $updated_href);
                        $changed = true;
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

        private function rewrite_google_fonts_stylesheet_urls_with_regex($html, $localize = false)
        {
            $html = (string) $html;
            if ('' === $html || false === stripos($html, 'fonts.googleapis.com')) {
                return $html;
            }

            $html = $this->safe_google_fonts_preg_replace_callback('/<link\\b(?=[^>]*fonts\\.googleapis\\.com)[^>]*>/i', function ($matches) use ($localize) {
                $tag = (string) $matches[0];
                if (false !== stripos($tag, 'rel="preconnect"') || false !== stripos($tag, "rel='preconnect'") || false !== stripos($tag, 'rel="dns-prefetch"') || false !== stripos($tag, "rel='dns-prefetch'")) {
                    return $tag;
                }

                return $this->replace_google_fonts_href_in_link_tag($tag, $localize);
            }, $html);

            $html = $this->safe_google_fonts_preg_replace_callback("#(?<![A-Za-z0-9_:])(?:https?:)?//fonts\\.googleapis\\.com/(?:css2?|icon)\\?[^\"'\\s<>]+#i", function ($matches) use ($localize) {
                return $this->get_updated_google_fonts_public_url((string) $matches[0], $localize, false);
            }, $html);

            $html = $this->safe_google_fonts_preg_replace_callback("#https?:\\\\/\\\\/fonts\\.googleapis\\.com\\\\/(?:css2?|icon)\\\\?[^\"'\\s<>]+#i", function ($matches) use ($localize) {
                return $this->get_updated_google_fonts_public_url((string) $matches[0], $localize, true);
            }, $html);

            return $html;
        }

        private function safe_google_fonts_preg_replace_callback($pattern, callable $callback, $subject, $limit = -1)
        {
            $subject = (string) $subject;
            if ('' === $subject) {
                return $subject;
            }

            $result = @preg_replace_callback($pattern, $callback, $subject, (int) $limit);
            if (!is_string($result)) {
                $this->record_html_rewrite_safety_bailout('google-fonts-regex', 'preg-replace-failed');
                return $subject;
            }

            return $result;
        }

        private function replace_google_fonts_href_in_link_tag($tag, $localize = false)
        {
            $tag = (string) $tag;
            $quoted_pattern = '/(href\s*=\s*)(["\'])([^"\']*fonts\.googleapis\.com[^"\']*)(\2)/i';
            $updated = $this->safe_google_fonts_preg_replace_callback($quoted_pattern, function ($matches) use ($localize) {
                $prefix = (string) $matches[1];
                $quote = (string) $matches[2];
                $href = $this->decode_google_fonts_html_url((string) $matches[3]);
                if (!$this->is_google_fonts_stylesheet_url($href)) {
                    return (string) $matches[0];
                }
                $new_href = $this->get_updated_google_fonts_public_url($href, $localize, false);
                return $prefix . $quote . esc_url($new_href) . $quote;
            }, $tag, 1);

            if (is_string($updated) && $updated !== $tag) {
                return $updated;
            }

            return $this->safe_google_fonts_preg_replace_callback('/(href\s*=\s*)([^"\'\s>]*fonts\.googleapis\.com[^\s>]*)/i', function ($matches) use ($localize) {
                $prefix = (string) $matches[1];
                $href = $this->decode_google_fonts_html_url((string) $matches[2]);
                if (!$this->is_google_fonts_stylesheet_url($href)) {
                    return (string) $matches[0];
                }
                $new_href = $this->get_updated_google_fonts_public_url($href, $localize, false);
                return $prefix . esc_url($new_href);
            }, $tag, 1);
        }

        private function get_updated_google_fonts_public_url($url, $localize = false, $slash_escaped = false)
        {
            $url = $slash_escaped ? str_replace('\\/', '/', (string) $url) : (string) $url;
            $url = $this->decode_google_fonts_html_url($url);
            if (!$this->is_google_fonts_stylesheet_url($url)) {
                return $slash_escaped ? str_replace('/', '\\/', (string) $url) : (string) $url;
            }

            $updated = $this->append_google_fonts_display_swap($url);
            if (!empty($localize)) {
                $localized = $this->get_google_fonts_url_for_current_request($updated, true);
                if (is_string($localized) && '' !== $localized) {
                    $updated = $localized;
                }
            }

            $updated = esc_url_raw($updated);
            return $slash_escaped ? str_replace('/', '\\/', $updated) : $updated;
        }

        private function decode_google_fonts_html_url($url)
        {
            $url = str_replace('\\/', '/', (string) $url);
            $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $url = str_replace('&amp;', '&', $url);
            return trim($url);
        }

        private function is_google_fonts_cache_dir_writable($dir = '')
        {
            $dir = '' === (string) $dir ? $this->get_google_fonts_cache_dir() : (string) $dir;
            if ('' === $dir) {
                return false;
            }

            $dir = trailingslashit($dir);
            if (!is_dir($dir)) {
                if (function_exists('ucwp_safe_mkdir')) {
                    ucwp_safe_mkdir($dir, 0755, true, 'google-fonts-cache-dir');
                } elseif (function_exists('wp_mkdir_p')) {
                    wp_mkdir_p($dir);
                }
            }

            if (!is_dir($dir) || !ucwp_path_is_writable($dir)) {
                return false;
            }

            $index_file = $dir . 'index.php';
            if (!file_exists($index_file)) {
                ucwp_safe_file_put_contents($index_file, "<?php\n// Silence is golden.\n", 0, 'google-fonts-index');
            }

            return true;
        }

        private function get_google_fonts_cache_write_failure_key($hash = '')
        {
            $hash = preg_replace('/[^a-z0-9_\\-]/i', '', (string) $hash);
            if ('' === $hash) {
                $hash = 'global';
            }

            return 'ucwp_gf_write_fail_' . substr(strtolower($hash), 0, 64);
        }

        private function should_skip_google_fonts_cache_write($hash = '')
        {
            return false !== get_transient($this->get_google_fonts_cache_write_failure_key($hash));
        }

        private function mark_google_fonts_cache_write_failure($hash = '', $ttl = 300)
        {
            set_transient($this->get_google_fonts_cache_write_failure_key($hash), 1, max(60, min(900, (int) $ttl)));
        }

        private function should_build_google_fonts_synchronously()
        {
            if (!empty($this->google_fonts_sync_build_mode)) {
                return true;
            }

            if (defined('UCWP_GOOGLE_FONTS_SYNC') && UCWP_GOOGLE_FONTS_SYNC) {
                return true;
            }

            if (defined('WP_CLI') && WP_CLI) {
                return true;
            }

            /*
             * Frontend and loopback/internal revalidation requests must not perform
             * remote Google Fonts downloads on the critical path. Only explicit
             * sync mode and WP-CLI/server-cron executions may build the local cache.
             */
            return false;
        }
        public function rebuild_google_fonts_cache_from_scan_urls(array $extra_urls = array(), $clear_cache = false, $reason = 'manual')
        {
            $settings = $this->get_settings();
            if (empty($settings['google_fonts_local_optimization'])) {
                return array(
                    'success' => false,
                    'message' => 'Local Google Fonts Optimization is disabled.',
                    'scannedUrls' => 0,
                    'fontUrls' => 0,
                    'built' => 0,
                    'failed' => 0,
                );
            }

            $lock_token = $this->acquire_google_fonts_lock('ucwp_gf_scan_rebuild_lock', 300);
            if ('' === $lock_token) {
                return array(
                    'success' => false,
                    'message' => 'Google Fonts rebuild is already running.',
                    'scannedUrls' => 0,
                    'fontUrls' => 0,
                    'built' => 0,
                    'failed' => 0,
                );
            }

            $previous_sync = $this->google_fonts_sync_build_mode;
            $previous_pending = $this->google_fonts_async_pending;
            $this->google_fonts_sync_build_mode = true;
            $this->google_fonts_async_pending = false;

            try {
                if (!empty($clear_cache)) {
                    $this->clear_google_fonts_cache_files();
                }

                $scan_urls = $this->get_google_fonts_scan_page_urls($extra_urls);
                $font_urls = array();
                foreach ($scan_urls as $scan_url) {
                    $html = $this->fetch_google_fonts_scan_html($scan_url);
                    if ('' === $html) {
                        continue;
                    }
                    foreach ($this->extract_google_fonts_stylesheet_urls_from_html($html) as $font_url) {
                        $font_urls[$font_url] = $font_url;
                    }

                    $css_scanned = 0;
                    foreach ($this->extract_same_origin_stylesheet_urls_from_html($html, $scan_url) as $css_url) {
                        if ($css_scanned >= 40) {
                            break;
                        }
                        $css_scanned++;
                        $css = $this->fetch_google_fonts_scan_css($css_url);
                        if ('' === $css) {
                            continue;
                        }
                        foreach ($this->extract_google_fonts_stylesheet_urls_from_css($css, $css_url) as $font_url) {
                            $font_urls[$font_url] = $font_url;
                        }
                    }
                }

                $built = 0;
                $failed = 0;
                foreach (array_values($font_urls) as $font_url) {
                    $local = $this->maybe_get_local_google_fonts_stylesheet_url($font_url);
                    if (is_string($local) && '' !== $local) {
                        $built++;
                    } else {
                        $failed++;
                    }
                }

                $result = array(
                    'success' => true,
                    'message' => sprintf('Google Fonts scan finished. Scanned %d URL(s), found %d Google Fonts stylesheet URL(s), built %d local stylesheet(s).', count($scan_urls), count($font_urls), $built),
                    'reason' => (string) $reason,
                    'scannedUrls' => count($scan_urls),
                    'fontUrls' => count($font_urls),
                    'built' => $built,
                    'failed' => $failed,
                    'cleared' => !empty($clear_cache),
                    'finishedAt' => time(),
                    'finishedAtUtc' => gmdate('c'),
                );

                $this->store_google_fonts_last_scan_result($result);

                return $result;
            } finally {
                $this->google_fonts_sync_build_mode = $previous_sync;
                $this->google_fonts_async_pending = $previous_pending;
                $this->release_google_fonts_lock('ucwp_gf_scan_rebuild_lock', $lock_token);
            }
        }

        private function get_google_fonts_last_scan_option_key()
        {
            return (defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY : 'ultracache_settings') . '_google_fonts_last_scan';
        }

        private function store_google_fonts_last_scan_result(array $result)
        {
            $stored = array(
                'success' => !empty($result['success']),
                'reason' => sanitize_key((string) ($result['reason'] ?? '')),
                'scannedUrls' => max(0, (int) ($result['scannedUrls'] ?? 0)),
                'fontUrls' => max(0, (int) ($result['fontUrls'] ?? 0)),
                'built' => max(0, (int) ($result['built'] ?? 0)),
                'failed' => max(0, (int) ($result['failed'] ?? 0)),
                'cleared' => !empty($result['cleared']),
                'finishedAt' => max(0, (int) ($result['finishedAt'] ?? time())),
                'finishedAtUtc' => sanitize_text_field((string) ($result['finishedAtUtc'] ?? gmdate('c'))),
                'message' => sanitize_text_field((string) ($result['message'] ?? '')),
            );

            update_option($this->get_google_fonts_last_scan_option_key(), $stored, false);
        }

        private function get_google_fonts_last_scan_result()
        {
            $stored = get_option($this->get_google_fonts_last_scan_option_key(), array());
            if (!is_array($stored)) {
                return array();
            }

            return array(
                'success' => !empty($stored['success']),
                'reason' => sanitize_key((string) ($stored['reason'] ?? '')),
                'scannedUrls' => max(0, (int) ($stored['scannedUrls'] ?? 0)),
                'fontUrls' => max(0, (int) ($stored['fontUrls'] ?? 0)),
                'built' => max(0, (int) ($stored['built'] ?? 0)),
                'failed' => max(0, (int) ($stored['failed'] ?? 0)),
                'cleared' => !empty($stored['cleared']),
                'finishedAt' => max(0, (int) ($stored['finishedAt'] ?? 0)),
                'finishedAtUtc' => sanitize_text_field((string) ($stored['finishedAtUtc'] ?? '')),
                'message' => sanitize_text_field((string) ($stored['message'] ?? '')),
            );
        }

        public function get_google_fonts_cache_summary()
        {
            $dir = trailingslashit($this->get_google_fonts_cache_dir());
            $settings = $this->get_settings();
            $last_scan = $this->get_google_fonts_last_scan_result();
            $enabled = !empty($settings['google_fonts_local_optimization']);
            $summary = array(
                'enabled' => $enabled,
                'built' => false,
                'path' => $dir,
                'cssFiles' => 0,
                'fontFiles' => 0,
                'totalFiles' => 0,
                'bytes' => 0,
                'lastBuilt' => 0,
                'lastScan' => $last_scan,
                'lastScanAt' => (int) ($last_scan['finishedAt'] ?? 0),
                'lastScanAtUtc' => (string) ($last_scan['finishedAtUtc'] ?? ''),
                'lastScanScannedUrls' => (int) ($last_scan['scannedUrls'] ?? 0),
                'lastScanGoogleFontsUrls' => (int) ($last_scan['fontUrls'] ?? 0),
                'lastScanBuilt' => (int) ($last_scan['built'] ?? 0),
                'lastScanFailed' => (int) ($last_scan['failed'] ?? 0),
                'message' => $enabled ? 'Google Fonts cache has not been built yet. Rebuild the local cache to localize remote Google Fonts URLs.' : 'Local Google Fonts Optimization is disabled.',
            );

            if ('' !== $dir && is_dir($dir)) {
                $items = @scandir($dir);
                if (is_array($items)) {
                    foreach ($items as $item) {
                        if ('.' === $item || '..' === $item || 'index.php' === $item) {
                            continue;
                        }
                        $path = $dir . $item;
                        if (!is_file($path)) {
                            continue;
                        }
                        $summary['totalFiles']++;
                        $summary['bytes'] += (int) @filesize($path);
                        $summary['lastBuilt'] = max((int) $summary['lastBuilt'], (int) @filemtime($path));
                        if (preg_match('/\.css$/i', $item)) {
                            $summary['cssFiles']++;
                        } elseif (preg_match('/\.woff2?$/i', $item)) {
                            $summary['fontFiles']++;
                        }
                    }
                }
            }

            $summary['built'] = $summary['cssFiles'] > 0 && $summary['fontFiles'] > 0;
            if ($summary['built']) {
                $summary['message'] = sprintf('Google Fonts cache built: %d stylesheet(s), %d font file(s).', (int) $summary['cssFiles'], (int) $summary['fontFiles']);
                if (!empty($last_scan)) {
                    $summary['message'] .= sprintf(' Last scan: %d URL(s), %d remote Google Fonts stylesheet URL(s), %d built, %d failed.', (int) ($last_scan['scannedUrls'] ?? 0), (int) ($last_scan['fontUrls'] ?? 0), (int) ($last_scan['built'] ?? 0), (int) ($last_scan['failed'] ?? 0));
                }
            } elseif ($summary['totalFiles'] > 0) {
                $summary['message'] = 'Google Fonts cache contains partial files. Rebuild the Google Fonts cache to refresh it.';
            } elseif (!empty($last_scan) && 0 === (int) ($last_scan['fontUrls'] ?? 0) && (int) ($last_scan['scannedUrls'] ?? 0) > 0) {
                $summary['message'] = sprintf('Google Fonts scan completed: no remote Google Fonts stylesheet URLs were found across %d scanned URL(s). No local Google Fonts cache is needed for those pages.', (int) ($last_scan['scannedUrls'] ?? 0));
            } elseif (!empty($last_scan) && (int) ($last_scan['fontUrls'] ?? 0) > 0) {
                $summary['message'] = sprintf('Google Fonts cache is not built. Last scan found %d remote Google Fonts stylesheet URL(s), built %d, failed %d.', (int) ($last_scan['fontUrls'] ?? 0), (int) ($last_scan['built'] ?? 0), (int) ($last_scan['failed'] ?? 0));
            }

            return $summary;
        }

        private function clear_google_fonts_cache_files()
        {
            $dir = $this->get_google_fonts_cache_dir();
            if ('' === $dir) {
                return;
            }

            $dir = trailingslashit($dir);
            if (!is_dir($dir)) {
                $this->is_google_fonts_cache_dir_writable($dir);
                return;
            }

            $items = function_exists('ucwp_safe_scandir') ? ucwp_safe_scandir($dir, 'google_fonts_cache_clear scandir') : scandir($dir);
            if (!is_array($items)) {
                return;
            }

            foreach ($items as $item) {
                if ('.' === $item || '..' === $item || 'index.php' === $item) {
                    continue;
                }

                $path = $dir . $item;
                if (is_dir($path) && !is_link($path)) {
                    $this->recursive_delete($path);
                } else {
                    ucwp_safe_unlink($path);
                }
            }

            $this->is_google_fonts_cache_dir_writable($dir);
        }

        private function get_google_fonts_scan_page_urls(array $extra_urls = array())
        {
            $urls = array();
            $home = $this->normalize_google_fonts_scan_page_url(home_url('/'));
            if ('' !== $home) {
                $urls[$home] = $home;
            }

            $settings = $this->get_settings();
            $configured = array();
            if (!empty($settings['google_fonts_additional_scan_urls']) && is_array($settings['google_fonts_additional_scan_urls'])) {
                $configured = $settings['google_fonts_additional_scan_urls'];
            }

            foreach (array_merge($configured, $extra_urls) as $url) {
                $normalized = $this->normalize_google_fonts_scan_page_url($url);
                if ('' !== $normalized) {
                    $urls[$normalized] = $normalized;
                }
            }

            return array_values($urls);
        }

        private function normalize_google_fonts_scan_page_url($url)
        {
            $url = trim((string) $url);
            if ('' === $url) {
                return '';
            }

            if (0 === strpos($url, '//')) {
                $url = (is_ssl() ? 'https:' : 'https:') . $url;
            } elseif (0 === strpos($url, '/')) {
                $url = home_url($url);
            }

            $url = esc_url_raw($url);
            if ('' === $url) {
                return '';
            }

            $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, array('http', 'https'), true)) {
                return '';
            }

            $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
            $url_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            if ('' === $home_host || '' === $url_host || $home_host !== $url_host) {
                return '';
            }

            $fragment_pos = strpos($url, '#');
            if (false !== $fragment_pos) {
                $url = substr($url, 0, $fragment_pos);
            }

            return esc_url_raw($url);
        }

        private function fetch_google_fonts_scan_html($url)
        {
            $url = $this->normalize_google_fonts_scan_page_url($url);
            if ('' === $url) {
                return '';
            }

            $scan_url = add_query_arg('ucwp_google_fonts_scan', '1', $url);
            $response = ucwp_safe_loopback_remote_request(
                $scan_url,
                array(
                    'timeout' => 20,
                    'redirection' => 3,
                    'user-agent' => 'UltraCache-GoogleFontsScanner/' . (defined('UCWP_VERSION') ? UCWP_VERSION : '1.0') . '; ' . home_url('/'),
                    'headers' => array(
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Cache-Control' => 'no-cache',
                    ),
                )
            );

            if (is_wp_error($response)) {
                return '';
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 400) {
                return '';
            }

            $body = wp_remote_retrieve_body($response);
            return is_string($body) ? $body : '';
        }

        private function extract_google_fonts_stylesheet_urls_from_html($html)
        {
            $html = (string) $html;
            if ('' === $html || false === stripos($html, 'fonts.googleapis.com')) {
                return array();
            }

            $urls = array();
            $patterns = array(
                "#(?<![A-Za-z0-9_:])(?:https?:)?//fonts\\.googleapis\\.com/(?:css2?|icon)\\?[^\"'\\s<>]+#i",
                "#https?:\\\\/\\\\/fonts\\.googleapis\\.com\\\\/(?:css2?|icon)\\\\?[^\"'\\s<>]+#i",
            );

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $html, $matches) && !empty($matches[0])) {
                    foreach ($matches[0] as $url) {
                        $url = $this->append_google_fonts_display_swap($this->decode_google_fonts_html_url((string) $url));
                        if ($this->is_google_fonts_stylesheet_url($url)) {
                            $urls[md5($url)] = esc_url_raw($url);
                        }
                    }
                }
            }

            return array_values($urls);
        }

        private function extract_same_origin_stylesheet_urls_from_html($html, $base_url = '')
        {
            $html = (string) $html;
            if ('' === $html || false === stripos($html, '<link')) {
                return array();
            }

            $base_url = '' !== (string) $base_url ? (string) $base_url : home_url('/');
            $urls = array();
            if (!preg_match_all('/<link\b[^>]*>/is', $html, $matches) || empty($matches[0])) {
                return array();
            }

            foreach ((array) $matches[0] as $tag) {
                $tag = (string) $tag;
                if ('' === $tag || !$this->html_tag_rel_contains_stylesheet($tag)) {
                    continue;
                }

                $href = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag, 'href'), ENT_QUOTES | ENT_HTML5);
                if ('' === $href || false !== stripos($href, 'fonts.googleapis.com')) {
                    continue;
                }

                $absolute = $this->absolutize_public_resource_url($href, $base_url);
                $normalized = $this->normalize_public_resource_url($absolute);
                if ('' === $normalized || !$this->is_cacheable_local_url($normalized)) {
                    continue;
                }

                $path = strtolower((string) wp_parse_url($normalized, PHP_URL_PATH));
                if (false === strpos($path, '.css')) {
                    continue;
                }

                if (false !== strpos($path, '/cache/ultracache/')) {
                    continue;
                }

                $urls[$normalized] = $normalized;
            }

            return array_values($urls);
        }

        private function fetch_google_fonts_scan_css($url)
        {
            $url = $this->normalize_public_resource_url((string) $url);
            if ('' === $url || !$this->is_cacheable_local_url($url)) {
                return '';
            }

            $response = ucwp_safe_loopback_remote_request(
                $url,
                array(
                    'timeout' => 12,
                    'redirection' => 3,
                    'user-agent' => 'UltraCache-GoogleFontsCssScanner/' . (defined('UCWP_VERSION') ? UCWP_VERSION : '1.0') . '; ' . home_url('/'),
                    'headers' => array(
                        'Accept' => 'text/css,*/*;q=0.1',
                        'Cache-Control' => 'no-cache',
                    ),
                )
            );

            if (is_wp_error($response)) {
                return '';
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 400) {
                return '';
            }

            $body = wp_remote_retrieve_body($response);
            if (!is_string($body) || '' === $body || false === stripos($body, 'fonts.googleapis.com')) {
                return '';
            }

            return $body;
        }

        private function extract_google_fonts_stylesheet_urls_from_css($css, $css_url)
        {
            $css = (string) $css;
            if ('' === $css || false === stripos($css, 'fonts.googleapis.com')) {
                return array();
            }

            $urls = array();
            $pattern = '/@import\s+(?:url\(\s*"([^"]+)"\s*\)|url\(\s*\'([^\']+)\'\s*\)|url\(\s*([^)]+?)\s*\)|"([^"]+)"|\'([^\']+)\')([^;]*);/i';
            if (!preg_match_all($pattern, $css, $matches, PREG_SET_ORDER)) {
                return array();
            }

            foreach ($matches as $match) {
                $import_url = '';
                for ($i = 1; $i <= 5; $i++) {
                    if (isset($match[$i]) && '' !== trim((string) $match[$i])) {
                        $import_url = trim((string) $match[$i]);
                        break;
                    }
                }

                if ('' === $import_url) {
                    continue;
                }

                $absolute = $this->absolutize_public_resource_url($this->decode_google_fonts_html_url($import_url), $css_url);
                $absolute = $this->append_google_fonts_display_swap($absolute);
                if ($this->is_google_fonts_stylesheet_url($absolute)) {
                    $urls[md5($absolute)] = esc_url_raw($absolute);
                }
            }

            return array_values($urls);
        }

        private function should_defer_google_fonts_build_on_current_request()
        {
            if (defined('UCWP_GOOGLE_FONTS_SYNC') && UCWP_GOOGLE_FONTS_SYNC) {
                return false;
            }

            if (defined('WP_CLI') && WP_CLI) {
                return false;
            }

            if (!empty($this->google_fonts_sync_build_mode)) {
                return false;
            }

            return true;
        }

        private function get_existing_local_google_fonts_stylesheet_url($url, $queue_missing = true)
        {
            $normalized_url = $this->append_google_fonts_display_swap((string) $url);
            if (!$this->is_google_fonts_stylesheet_url($normalized_url)) {
                return '';
            }

            $hash = md5($normalized_url);
            $css_file = $this->get_google_fonts_cache_dir() . $hash . '.css';
            if (is_readable($css_file) && filesize($css_file) > 0) {
                $this->normalize_google_fonts_cache_css_file($css_file);
                return $this->get_google_fonts_cache_url_base() . $hash . '.css';
            }

            return '';
        }

        private function get_google_fonts_url_for_current_request($url, $queue_missing = true)
        {
            if ($this->should_defer_google_fonts_build_on_current_request()) {
                return $this->get_existing_local_google_fonts_stylesheet_url($url, false);
            }

            return $this->maybe_get_local_google_fonts_stylesheet_url($url);
        }

        private function maybe_get_local_google_fonts_stylesheet_url($url)
        {
            $normalized_url = $this->append_google_fonts_display_swap((string) $url);
            if (!$this->is_google_fonts_stylesheet_url($normalized_url)) {
                return '';
            }

            $hash = md5($normalized_url);
            if ($this->should_skip_google_fonts_cache_write($hash)) {
                return '';
            }

            $dir = $this->get_google_fonts_cache_dir();
            if (!$this->is_google_fonts_cache_dir_writable($dir)) {
                $this->mark_google_fonts_cache_write_failure($hash);
                return '';
            }

            $css_file = $dir . $hash . '.css';
            $css_url = $this->get_google_fonts_cache_url_base() . $hash . '.css';

            if (is_readable($css_file) && filesize($css_file) > 0) {
                $this->normalize_google_fonts_cache_css_file($css_file);
                return $css_url;
            }

            if (!$this->should_build_google_fonts_synchronously()) {
                return '';
            }

            $lock_key = $this->get_google_fonts_lock_key('css', $hash);
            $lock_token = $this->acquire_google_fonts_lock($lock_key, 120);
            if ('' === $lock_token) {
                return '';
            }

            try {
                if (is_readable($css_file) && filesize($css_file) > 0) {
                    $this->normalize_google_fonts_cache_css_file($css_file);
                    return $css_url;
                }

                $response = ucwp_safe_remote_request(
                    $normalized_url,
                    array(
                        'timeout' => 10,
                        'redirection' => 3,
                        'sslverify' => true,
                        'user-agent' => $this->get_google_fonts_remote_user_agent(),
                        'headers' => array(
                            'Accept' => 'text/css,*/*;q=0.1',
                        ),
                    )
                );

                if (is_wp_error($response)) {
                    return '';
                }

                $code = (int) wp_remote_retrieve_response_code($response);
                $css = (string) wp_remote_retrieve_body($response);
                if (200 !== $code || '' === trim($css)) {
                    return '';
                }

                $localized_css = $this->build_local_google_fonts_css($css, $normalized_url, $hash);
                if (function_exists('ucwp_strip_source_mapping_url_comments')) {
                    $localized_css = ucwp_strip_source_mapping_url_comments($localized_css);
                }
                if ('' === trim($localized_css)) {
                    return '';
                }

                if (!$this->is_google_fonts_cache_dir_writable($dir)) {
                    $this->mark_google_fonts_cache_write_failure($hash);
                    return '';
                }

                if (false === ucwp_safe_file_put_contents($css_file, $localized_css, 0, 'google-fonts-css')) {
                    $this->mark_google_fonts_cache_write_failure($hash);
                    return '';
                }

                return $css_url;
            } finally {
                $this->release_google_fonts_lock($lock_key, $lock_token);
            }
        }

        private function build_local_google_fonts_css($css, $css_url, $group_hash)
        {
            $css = $this->normalize_font_face_display_in_css((string) $css);
            if ('' === trim($css)) {
                return '';
            }

            return $this->safe_google_fonts_preg_replace_callback('/url\(([^)]+)\)/i', function ($matches) use ($css_url, $group_hash) {
                $raw = trim((string) $matches[1]);
                $trimmed = trim($raw, " \t\n\r\0\x0B\"'");
                if ('' === $trimmed) {
                    return (string) $matches[0];
                }

                $absolute = $this->absolutize_public_resource_url($trimmed, $css_url);
                if ('' === $absolute) {
                    return (string) $matches[0];
                }

                $host = strtolower((string) wp_parse_url($absolute, PHP_URL_HOST));
                if (false === strpos($host, 'fonts.gstatic.com')) {
                    return 'url("' . esc_url_raw($absolute) . '")';
                }

                $local = $this->download_google_font_binary_to_cache($absolute, $group_hash);
                if ('' === $local) {
                    return 'url("' . esc_url_raw($absolute) . '")';
                }

                $local = $this->normalize_google_fonts_cache_url_for_css($local);
                if ('' === $local) {
                    return (string) $matches[0];
                }

                return 'url("' . esc_url_raw($local) . '")';
            }, $css);
        }

        private function download_google_font_binary_to_cache($remote_url, $group_hash)
        {
            $remote_url = (string) $remote_url;
            if ('' === $remote_url) {
                return '';
            }

            $path = (string) wp_parse_url($remote_url, PHP_URL_PATH);
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($extension, array('woff2', 'woff', 'ttf', 'otf'), true)) {
                $extension = 'woff2';
            }

            $file_hash = md5($remote_url);
            $failure_hash = $group_hash . '-' . $file_hash;
            if ($this->should_skip_google_fonts_cache_write($failure_hash)) {
                return '';
            }

            $dir = $this->get_google_fonts_cache_dir();
            if (!$this->is_google_fonts_cache_dir_writable($dir)) {
                $this->mark_google_fonts_cache_write_failure($failure_hash);
                return '';
            }

            $file_name = $group_hash . '-' . $file_hash . '.' . $extension;
            $file_path = $dir . $file_name;
            $file_url = $this->get_google_fonts_cache_url_base() . $file_name;

            if (is_readable($file_path) && filesize($file_path) > 0) {
                return $file_url;
            }

            $lock_key = $this->get_google_fonts_lock_key('bin', $failure_hash);
            $lock_token = $this->acquire_google_fonts_lock($lock_key, 90);
            if ('' === $lock_token) {
                return '';
            }

            try {
                if (is_readable($file_path) && filesize($file_path) > 0) {
                    return $file_url;
                }

                $response = ucwp_safe_remote_request(
                    $remote_url,
                    array(
                        'timeout' => 10,
                        'redirection' => 3,
                        'sslverify' => true,
                        'user-agent' => $this->get_google_fonts_remote_user_agent(),
                    )
                );
                if (is_wp_error($response)) {
                    return '';
                }

                $code = (int) wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                if (200 !== $code || !is_string($body) || '' === $body) {
                    return '';
                }

                if (!$this->is_google_fonts_cache_dir_writable($dir)) {
                    $this->mark_google_fonts_cache_write_failure($failure_hash);
                    return '';
                }

                if (false === ucwp_safe_file_put_contents($file_path, $body, 0, 'google-font-binary')) {
                    $this->mark_google_fonts_cache_write_failure($failure_hash);
                    return '';
                }

                return $file_url;
            } finally {
                $this->release_google_fonts_lock($lock_key, $lock_token);
            }
        }

        private function get_google_fonts_lock_key($type, $hash)
        {
            $type = preg_replace('/[^a-z0-9_\-]/i', '', (string) $type);
            $hash = preg_replace('/[^a-z0-9_\-]/i', '', (string) $hash);
            if ('' === $type) {
                $type = 'asset';
            }
            if ('' === $hash) {
                $hash = md5($type . microtime(true));
            }

            return 'ucwp_gf_' . strtolower($type) . '_lock_' . substr(strtolower($hash), 0, 64);
        }

        private function acquire_google_fonts_lock($key, $ttl = 60)
        {
            $key = (string) $key;
            if ('' === $key) {
                return '';
            }

            $ttl = max(15, min(300, (int) $ttl));
            $token = wp_generate_password(20, false, false) . ':' . (string) microtime(true);

            if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_add')) {
                return wp_cache_add($key, $token, 'ucwp_google_fonts_locks', $ttl) ? ('cache:' . $token) : '';
            }

            $value_option = '_transient_' . $key;
            $timeout_option = '_transient_timeout_' . $key;
            $now = time();
            $timeout = (int) get_option($timeout_option, 0);

            if ($timeout > 0 && $timeout < $now) {
                delete_option($value_option);
                delete_option($timeout_option);
            } elseif (false !== get_transient($key)) {
                return '';
            }

            if (!add_option($value_option, $token, '', 'no')) {
                return '';
            }

            if (!add_option($timeout_option, $now + $ttl, '', 'no')) {
                update_option($timeout_option, $now + $ttl, false);
            }

            return 'db:' . $token;
        }

        private function release_google_fonts_lock($key, $token)
        {
            $key = (string) $key;
            $token = (string) $token;
            if ('' === $key || '' === $token) {
                return;
            }

            if (0 === strpos($token, 'cache:')) {
                $raw_token = substr($token, 6);
                if (function_exists('wp_cache_get') && (string) wp_cache_get($key, 'ucwp_google_fonts_locks') === (string) $raw_token && function_exists('wp_cache_delete')) {
                    wp_cache_delete($key, 'ucwp_google_fonts_locks');
                }
                return;
            }

            $raw_token = (0 === strpos($token, 'db:')) ? substr($token, 3) : $token;
            if ((string) get_transient($key) === (string) $raw_token) {
                delete_transient($key);
            }
        }

        private function is_google_fonts_stylesheet_url($url)
        {
            $url = $this->decode_google_fonts_html_url((string) $url);
            if (0 === strpos($url, '//')) {
                $url = 'https:' . $url;
            }
            $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            if ('' === $host || false === strpos($host, 'fonts.googleapis.com')) {
                return false;
            }

            $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
            if ('' === $path) {
                return false;
            }

            return 0 === strpos($path, '/css') || 0 === strpos($path, '/css2') || 0 === strpos($path, '/icon');
        }

        private function get_google_fonts_cache_dir()
        {
            return trailingslashit(UCWP_CACHE_DIR) . 'google-fonts/';
        }

        private function get_google_fonts_cache_url_base()
        {
            return $this->get_google_fonts_cache_root_relative_url_base();
        }

        private function get_google_fonts_cache_root_relative_url_base()
        {
            $path = (string) wp_parse_url(content_url('cache/ultracache/google-fonts'), PHP_URL_PATH);
            if ('' === $path) {
                $path = '/wp-content/cache/ultracache/google-fonts';
            }

            $path = '/' . ltrim($path, '/');
            return trailingslashit($path);
        }

        private function normalize_google_fonts_cache_url_for_css($url)
        {
            $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
            if ('' === $url) {
                return '';
            }

            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if ('' === $path && 0 === strpos($url, '/')) {
                $path = strtok($url, '?');
                if (false === $path) {
                    $path = $url;
                }
            }

            if ('' === $path) {
                return '';
            }

            $cache_base = untrailingslashit($this->get_google_fonts_cache_root_relative_url_base());
            if (0 !== strpos($path, $cache_base . '/')) {
                return '';
            }

            $query = (string) wp_parse_url($url, PHP_URL_QUERY);
            return $path . ('' !== $query ? ('?' . $query) : '');
        }

        private function normalize_google_fonts_cache_urls_in_css($css)
        {
            $css = (string) $css;
            if ('' === $css || false === stripos($css, 'cache/ultracache/google-fonts')) {
                return $css;
            }

            return $this->safe_google_fonts_preg_replace_callback('/url\(([^)]+)\)/i', function ($matches) {
                $raw = trim((string) $matches[1]);
                $trimmed = trim($raw, " \t\n\r\0\x0B\"'");
                $local = $this->normalize_google_fonts_cache_url_for_css($trimmed);
                if ('' === $local) {
                    return (string) $matches[0];
                }

                return 'url("' . esc_url_raw($local) . '")';
            }, $css);
        }

        private function normalize_google_fonts_cache_css_file($css_file)
        {
            $css_file = (string) $css_file;
            if ('' === $css_file || !is_readable($css_file)) {
                return false;
            }

            $css = ucwp_safe_file_get_contents($css_file, 'google-fonts-css-normalize', true);
            if (!is_string($css) || '' === $css || false === stripos($css, 'cache/ultracache/google-fonts')) {
                return false;
            }

            $normalized = $this->normalize_google_fonts_cache_urls_in_css($css);
            $font_display_stats = array();
            $normalized = $this->normalize_font_face_display_in_css($normalized, $font_display_stats);
            if (function_exists('ucwp_strip_source_mapping_url_comments')) {
                $normalized = ucwp_strip_source_mapping_url_comments($normalized);
            }
            if (!is_string($normalized) || $normalized === $css) {
                return false;
            }

            return false !== ucwp_safe_file_put_contents($css_file, $normalized, 0, 'google-fonts-css-normalize');
        }

        private function get_google_fonts_remote_user_agent()
        {
            return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
        }

        private function get_generated_font_css_asset_role(array $asset)
        {
            $css_url = isset($asset['css_url']) ? strtolower((string) $asset['css_url']) : '';
            if (!empty($asset['activeCssIsMixed']) || false !== strpos($css_url, '/cache/ultracache/optimized-css/')) {
                return 'optimized-css';
            }
            if (false !== strpos($css_url, '/cache/ultracache/font-css/')) {
                return 'font-css';
            }
            return '';
        }

        private function optimize_self_hosted_font_css_links($html)
        {
            if (false === stripos($html, '<link') || false === stripos($html, '.css')) {
                return $html;
            }

            $processed = $this->optimize_self_hosted_font_css_links_with_processor($html);
            if (is_string($processed)) {
                return $this->rewrite_inline_font_face_ttf_sources_to_linked_woff2($processed);
            }

            $preload_urls = array();
            $delayed_font_assets = array();
            $html = (string) preg_replace_callback(
                '/<link\b[^>]*\bhref=(\"|\')(.*?)\1[^>]*>/is',
                function ($matches) use (&$preload_urls, &$delayed_font_assets) {
                    $tag = (string) $matches[0];
                    $href = $this->extract_attribute_from_html_tag($tag, 'href');
                    if (!$this->html_tag_rel_contains_stylesheet($tag)) {
                        return $tag;
                    }

                    if (false !== stripos($tag, 'data-ucwp-frontpage-css=') || false !== stripos($tag, 'id="ucwp-page-css-bundle"') || false !== stripos($tag, "id='ucwp-frontpage-css'")) {
                        return $tag;
                    }

                    $normalized_href = $this->normalize_public_resource_url($href);
                    if ('' !== $normalized_href) {
                        $normalized_path = strtolower((string) wp_parse_url($normalized_href, PHP_URL_PATH));
                        if (false !== strpos($normalized_path, '/cache/ultracache/css-bundles/')) {
                            return $tag;
                        }
                    }

                    $asset = $this->get_optimized_font_css_asset_for_current_request($href);
                    if (empty($asset['css_url'])) {
                        return $tag;
                    }

                    if (!empty($asset['preload_urls']) && is_array($asset['preload_urls'])) {
                        foreach ($asset['preload_urls'] as $preload_url) {
                            if (count($preload_urls) >= 2) {
                                break;
                            }
                            $preload_url = esc_url_raw((string) $preload_url);
                            if ('' !== $preload_url && !in_array($preload_url, $preload_urls, true)) {
                                $preload_urls[] = $preload_url;
                            }
                        }
                    }

                    if (!empty($asset['delayedFontUrl'])) {
                        $delayed_url = esc_url_raw((string) $asset['delayedFontUrl']);
                        if ('' !== $delayed_url) {
                            $delayed_font_assets[$delayed_url] = $asset;
                        }
                    }

                    $replacement_url = esc_url($asset['css_url']);
                    $asset_role = $this->get_generated_font_css_asset_role($asset);
                    $rewritten_tag = $this->set_or_add_html_tag_attribute($tag, 'href', $replacement_url);
                    if ('' !== $asset_role) {
                        $rewritten_tag = $this->set_or_add_html_tag_attribute($rewritten_tag, 'data-ucwp-css-role', $asset_role);
                        if ('optimized-css' === $asset_role) {
                            $rewritten_tag = $this->set_or_add_html_tag_attribute($rewritten_tag, 'data-ucwp-css-blocking-reason', 'optimized-css-layout-risk');
                        } elseif ('font-css' === $asset_role) {
                            $rewritten_tag = $this->set_or_add_html_tag_attribute($rewritten_tag, 'data-ucwp-css-blocking-reason', 'font-css-text-metric-risk');
                        }
                    }
                    return $rewritten_tag;
                },
                $html
            );

            if (!empty($preload_urls)) {
                $html = $this->inject_font_preload_links($html, $preload_urls);
            }

            if (!empty($delayed_font_assets)) {
                $html = $this->inject_delayed_font_css_links($html, array_values($delayed_font_assets), 'ucwp-no-bundle-delayed-icon-fonts');
            }

            return $this->rewrite_inline_font_face_ttf_sources_to_linked_woff2($html);
        }

        private function normalize_linked_local_stylesheet_font_display_in_html($html)
        {
            $html = (string) $html;
            if ('' === $html || false === stripos($html, '<link') || false === stripos($html, '.css')) {
                return $html;
            }

            $changed = false;
            $updated = (string) preg_replace_callback(
                '/<link\b[^>]*\bhref=("|\')(.*?)\1[^>]*>/is',
                function ($matches) use (&$changed) {
                    $tag = (string) $matches[0];
                    if ('' === $tag || !$this->html_tag_rel_contains_stylesheet($tag)) {
                        return $tag;
                    }

                    if (false !== stripos($tag, 'data-ucwp-font-display-patch=') || false !== stripos($tag, 'data-ucwp-delayed-icon-fonts=')) {
                        return $tag;
                    }

                    $href = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag, 'href'), ENT_QUOTES | ENT_HTML5);
                    $asset = $this->get_font_display_normalized_css_asset_for_current_request($href);
                    if (empty($asset['css_url'])) {
                        return $tag;
                    }

                    $rewritten = $this->set_or_add_html_tag_attribute($tag, 'href', esc_url((string) $asset['css_url']));
                    $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'data-ucwp-css-role', 'optimized-css');
                    $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'data-ucwp-font-display-normalized', '1');
                    $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'data-ucwp-css-blocking-reason', 'font-display-normalized-preserve-layout');
                    $changed = true;
                    return $rewritten;
                },
                $html
            );

            return $changed && is_string($updated) && '' !== $updated ? $updated : $html;
        }

        private function get_font_display_normalized_css_asset_for_current_request($url)
        {
            static $request_assets = array();

            $source_url = $this->normalize_public_resource_url($url);
            if ('' === $source_url) {
                return array();
            }

            if (array_key_exists($source_url, $request_assets)) {
                return is_array($request_assets[$source_url]) ? $request_assets[$source_url] : array();
            }

            $normalized_path = strtolower((string) wp_parse_url($source_url, PHP_URL_PATH));
            if (false !== strpos($normalized_path, '/cache/ultracache/css-bundles/') || false !== strpos($normalized_path, '/cache/ultracache/font-css/') || false !== strpos($normalized_path, '/cache/ultracache/optimized-css/')) {
                $request_assets[$source_url] = array();
                return array();
            }

            if (!$this->is_cacheable_local_url($source_url)) {
                $request_assets[$source_url] = array();
                return array();
            }

            $source_path = $this->resolve_local_path_from_public_url($source_url);
            if ('' === $source_path || !is_readable($source_path)) {
                $request_assets[$source_url] = array();
                return array();
            }

            $source_path_lc = strtolower(str_replace('\\', '/', $source_path));
            if (false !== strpos($source_path_lc, '/cache/ultracache/')) {
                $request_assets[$source_url] = array();
                return array();
            }

            $css = ucwp_safe_file_get_contents($source_path, 'font_display_normalized_css_asset', true);
            if (!is_string($css) || '' === $css || false === stripos($css, '@font-face') || !$this->css_has_font_face_requiring_display_normalization($css)) {
                $request_assets[$source_url] = array();
                return array();
            }

            /*
             * Keep this path as an extension of the existing font-face CSS
             * normalization pipeline: preserve the full source stylesheet, preserve
             * every src() entry (woff2/woff/ttf/eot/svg), only add font-display:
             * swap to existing @font-face blocks that miss it, and normalize
             * relative url(...) references before writing the copy under
             * /optimized-css/. Without URL normalization, theme CSS such as
             * css/wpbingo.css would be copied to the cache directory with
             * ../fonts/... paths pointing at the wrong location.
             */
            $stats = array();
            $google_import_stats = array();
            $normalized_css = $this->normalize_protocol_relative_urls_in_css($css, $source_url);
            $normalized_css = $this->rewrite_google_fonts_imports_in_css($normalized_css, $source_url, $google_import_stats);
            $normalized_css = $this->normalize_font_face_display_in_css($normalized_css, $stats);
            if (!is_string($normalized_css) || '' === trim($normalized_css) || $normalized_css === $css || empty($stats['fontFaceBlocksChanged'])) {
                $request_assets[$source_url] = array();
                return array();
            }

            if (function_exists('ucwp_strip_source_mapping_url_comments')) {
                $normalized_css = trim(ucwp_strip_source_mapping_url_comments($normalized_css)) . "\n";
            }

            $dir = trailingslashit(UCWP_CACHE_DIR) . 'optimized-css/';
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            $index_file = $dir . 'index.php';
            if (!file_exists($index_file)) {
                ucwp_safe_file_put_contents($index_file, "<?php\n// Silence is golden.\n");
            }

            $hash = md5($source_url . '|font-display|' . (string) ucwp_safe_filemtime($source_path, 'font_display_normalized_signature') . '|' . md5($normalized_css));
            $filename = 'active-font-display-' . $hash . '.css';
            $file = $dir . $filename;
            $content_hash = md5($normalized_css);
            $existing_hash = (is_readable($file) && filesize($file) > 0) ? md5_file($file) : '';
            if ($existing_hash !== $content_hash) {
                if (!$this->write_cache_variant_atomically($file, $normalized_css)) {
                    $request_assets[$source_url] = array();
                    return array();
                }
            }

            clearstatcache(true, $file);
            $verified_hash = (is_readable($file) && filesize($file) > 0) ? md5_file($file) : '';
            if ($verified_hash !== $content_hash) {
                $request_assets[$source_url] = array();
                return array();
            }

            $asset = array(
                'css_url' => content_url('cache/ultracache/optimized-css/' . $filename),
                'file' => $file,
                'sourceUrl' => $source_url,
                'sourceBytes' => strlen($css),
                'cssBytes' => strlen($normalized_css),
                'fontDisplayAdded' => max(0, (int) ($stats['fontDisplayAdded'] ?? 0)),
                'fontFaceBlocksScanned' => max(0, (int) ($stats['fontFaceBlocksScanned'] ?? 0)),
            );

            $request_assets[$source_url] = $asset;
            return $asset;
        }

        private function optimize_self_hosted_font_css_links_with_processor($html)
        {
            if (!$this->html_tag_processor_available()) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor((string) $html);
                $changed = false;
                $preload_urls = array();
                $delayed_font_assets = array();

                while ($processor->next_tag('LINK')) {
                    $href = $processor->get_attribute('href');
                    if (!is_string($href) || '' === $href) {
                        continue;
                    }

                    $rel = $processor->get_attribute('rel');
                    if (!is_string($rel) || false === stripos($rel, 'stylesheet')) {
                        continue;
                    }

                    if (null !== $processor->get_attribute('data-ucwp-frontpage-css') || null !== $processor->get_attribute('data-ucwp-page-css-bundle')) {
                        continue;
                    }

                    $id = $processor->get_attribute('id');
                    if (is_string($id) && in_array($id, array('ucwp-page-css-bundle', 'ucwp-frontpage-css'), true)) {
                        continue;
                    }

                    $normalized_href = $this->normalize_public_resource_url($href);
                    if ('' !== $normalized_href) {
                        $normalized_path = strtolower((string) wp_parse_url($normalized_href, PHP_URL_PATH));
                        if (false !== strpos($normalized_path, '/cache/ultracache/css-bundles/')) {
                            continue;
                        }
                    }

                    $asset = $this->get_optimized_font_css_asset_for_current_request($href);
                    if (empty($asset['css_url'])) {
                        continue;
                    }

                    if (!empty($asset['preload_urls']) && is_array($asset['preload_urls'])) {
                        foreach ($asset['preload_urls'] as $preload_url) {
                            if (count($preload_urls) >= 2) {
                                break;
                            }
                            $preload_url = esc_url_raw((string) $preload_url);
                            if ('' !== $preload_url && !in_array($preload_url, $preload_urls, true)) {
                                $preload_urls[] = $preload_url;
                            }
                        }
                    }

                    if (!empty($asset['delayedFontUrl'])) {
                        $delayed_url = esc_url_raw((string) $asset['delayedFontUrl']);
                        if ('' !== $delayed_url) {
                            $delayed_font_assets[$delayed_url] = $asset;
                        }
                    }

                    $processor->set_attribute('href', esc_url($asset['css_url']));
                    $asset_role = $this->get_generated_font_css_asset_role($asset);
                    if ('' !== $asset_role) {
                        $processor->set_attribute('data-ucwp-css-role', $asset_role);
                        if ('optimized-css' === $asset_role) {
                            $processor->set_attribute('data-ucwp-css-blocking-reason', 'optimized-css-layout-risk');
                        } elseif ('font-css' === $asset_role) {
                            $processor->set_attribute('data-ucwp-css-blocking-reason', 'font-css-text-metric-risk');
                        }
                    }
                    $changed = true;
                }

                if (!$changed) {
                    return null;
                }

                $updated_html = $processor->get_updated_html();
                if (!is_string($updated_html) || '' === $updated_html) {
                    return null;
                }

                if (!empty($preload_urls)) {
                    $updated_html = $this->inject_font_preload_links($updated_html, $preload_urls);
                }

                if (!empty($delayed_font_assets)) {
                    $updated_html = $this->inject_delayed_font_css_links($updated_html, array_values($delayed_font_assets), 'ucwp-no-bundle-delayed-icon-fonts');
                }

                return $this->rewrite_inline_font_face_ttf_sources_to_linked_woff2($updated_html);
            } catch (\Throwable $e) {
                return null;
            }
        }

        private function get_optimized_font_css_asset_for_current_request($url)
        {
            static $request_assets = array();

            $source_url = $this->normalize_public_resource_url($url);
            if ('' === $source_url) {
                return array();
            }

            if (array_key_exists($source_url, $request_assets)) {
                return is_array($request_assets[$source_url]) ? $request_assets[$source_url] : array();
            }

            $normalized_path = strtolower((string) wp_parse_url($source_url, PHP_URL_PATH));
            if (false !== strpos($normalized_path, '/cache/ultracache/css-bundles/') || false !== strpos($normalized_path, '/cache/ultracache/font-css/') || false !== strpos($normalized_path, '/cache/ultracache/optimized-css/')) {
                $request_assets[$source_url] = array();
                return array();
            }

            $settings = $this->get_settings();
            $map = $this->get_runtime_local_font_css_url_map();
            $mapped_css_url = '';
            if (is_array($map) && !empty($map[$source_url])) {
                $mapped_css_url = esc_url_raw((string) $map[$source_url]);
                if ('' !== $mapped_css_url && $mapped_css_url !== $source_url && empty($settings['delay_icon_fonts'])) {
                    $request_assets[$source_url] = array(
                        'css_url'      => $mapped_css_url,
                        'preload_urls' => array(),
                    );
                    return $request_assets[$source_url];
                }
            }

            /*
             * CSS Bundle Exclusions only protect a stylesheet from being merged into
             * generated CSS bundles. They should not opt that stylesheet out of other
             * safe CSS optimizations. Build a current-request optimized copy only for
             * the explicit local stylesheet links present in this HTML, then keep that
             * CSS as a separate link. This avoids a broad filesystem scan while still
             * allowing excluded local CSS to receive font-display/URL normalization.
             */
            $asset = $this->build_optimized_font_css_asset($source_url);
            if (!empty($asset['css_url'])) {
                $this->remember_runtime_font_css_url_mapping($source_url, (string) $asset['css_url']);
                $request_assets[$source_url] = $asset;
                return $request_assets[$source_url];
            }

            if ('' !== $mapped_css_url && $mapped_css_url !== $source_url) {
                $request_assets[$source_url] = array(
                    'css_url'      => $mapped_css_url,
                    'preload_urls' => array(),
                );
                return $request_assets[$source_url];
            }

            $request_assets[$source_url] = array();
            return array();
        }

        private function build_optimized_font_css_asset($url)
        {
            $source_url = $this->normalize_public_resource_url($url);
            if ('' === $source_url) {
                return array();
            }

            $source_path = $this->resolve_local_path_from_public_url($source_url);
            if ('' === $source_path || !is_readable($source_path)) {
                return array();
            }

            $source_path_lc = strtolower(str_replace('\\', '/', $source_path));
            if (false !== strpos($source_path_lc, '/cache/ultracache/css-bundles/') || false !== strpos($source_path_lc, '/cache/ultracache/font-css/') || false !== strpos($source_path_lc, '/cache/ultracache/optimized-css/')) {
                return array();
            }

            $css = ucwp_safe_file_get_contents($source_path, 'build_optimized_font_css_asset', true);
            if (!is_string($css) || '' === $css) {
                return array();
            }

            $has_font_faces = false !== stripos($css, '@font-face');
            $has_google_imports = false !== stripos($css, 'fonts.googleapis.com');
            if (!$has_font_faces && !$has_google_imports) {
                return array();
            }

            $has_missing_font_display = $this->css_has_font_face_requiring_display_normalization($css);
            $google_import_stats = array();
            $optimized_css = $this->rewrite_self_hosted_font_css_content($css, $source_url, $google_import_stats);
            $google_imports_localized = !empty($google_import_stats['localized']);
            $google_imports_changed = $optimized_css !== $css && !empty($google_import_stats['found']);
            $settings = $this->get_settings();
            $delayed_font_css = '';
            $delayed_font_families = array();
            $delayed_font_patterns = array();
            $delayed_font_count = 0;
            $preserve_mixed_css_for_delayed_icon_fonts = false;
            $preserve_mixed_css_for_font_display = false;

            if (!empty($settings['delay_icon_fonts'])) {
                $font_split = $this->split_delayed_icon_font_faces_from_css($optimized_css, $source_url, $settings);
                if (!empty($font_split['delayedCount']) && is_string($font_split['body'])) {
                    $optimized_css = (string) $font_split['body'];
                    $delayed_font_css = (string) ($font_split['delayedCss'] ?? '');
                    $delayed_font_families = array_values(array_unique(array_map('strval', (array) ($font_split['families'] ?? array()))));
                    $delayed_font_patterns = array_values(array_unique(array_map('strval', (array) ($font_split['patterns'] ?? array()))));
                    $delayed_font_count = max(0, (int) ($font_split['delayedCount'] ?? 0));
                    $preserve_mixed_css_for_delayed_icon_fonts = true;
                }
            }

            $font_css_optimization_stats = array();
            if (!$preserve_mixed_css_for_delayed_icon_fonts && empty($google_imports_changed) && function_exists('ucwp_optimize_generated_font_css')) {
                $font_css_optimization = ucwp_optimize_generated_font_css($optimized_css, $source_url);
                if (is_array($font_css_optimization) && isset($font_css_optimization['stats']) && is_array($font_css_optimization['stats'])) {
                    $font_css_optimization_stats = $font_css_optimization['stats'];
                }
                if (!empty($font_css_optimization_stats['nonFontCssDetected'])) {
                    /*
                     * Public-release safety: do not replace a mixed layout/theme stylesheet
                     * with a font-only generated copy. However, if the only safe optimization
                     * needed is adding font-display to @font-face declarations, keep the full
                     * mixed stylesheet content and write it under /optimized-css/. This removes
                     * the original render-path stylesheet that Lighthouse/Chromium still sees
                     * as missing font-display, while preserving all non-font CSS rules.
                     */
                    if ($has_missing_font_display && $optimized_css !== $css && false !== stripos((string) $optimized_css, '@font-face')) {
                        $preserve_mixed_css_for_font_display = true;
                        $font_css_optimization_stats['mixedCssPreservedForFontDisplay'] = true;
                        $font_css_optimization_stats['beforeBytes'] = strlen($css);
                        $font_css_optimization_stats['afterBytes'] = strlen((string) $optimized_css);
                    } else {
                        return array();
                    }
                }
                if (empty($preserve_mixed_css_for_font_display) && is_array($font_css_optimization) && isset($font_css_optimization['css']) && is_string($font_css_optimization['css'])) {
                    $optimized_css = $font_css_optimization['css'];
                }
            }

            if ($preserve_mixed_css_for_delayed_icon_fonts) {
                $font_css_optimization_stats = array(
                    'sourceUrl' => $source_url,
                    'mixedCssPreservedForDelayedIconFonts' => true,
                    'delayedIconFontFaceBlocks' => $delayed_font_count,
                    'delayedIconFontFamilies' => $delayed_font_families,
                    'delayedIconFontPatterns' => $delayed_font_patterns,
                    'beforeBytes' => strlen($css),
                    'afterBytes' => strlen((string) $optimized_css),
                );
                $optimized_css = trim((string) $optimized_css);
                if ('' === $optimized_css) {
                    $optimized_css = '/* UltraCache delayed all matching icon @font-face blocks from this stylesheet. */';
                }
                $optimized_css = "/* UltraCache delayed icon font active CSS: preserved source CSS without delayed icon @font-face blocks. */
" . $optimized_css . "
";
            }

            $css_image_rewrite_stats = array();
            if (method_exists($this, 'rewrite_stylesheet_css_image_urls_for_media_optimization')) {
                $optimized_css = $this->rewrite_stylesheet_css_image_urls_for_media_optimization((string) $optimized_css, $source_url, $css_image_rewrite_stats);
            }

            $preserve_mixed_css_for_google_imports = !empty($google_imports_changed);

            if ('' === trim((string) $optimized_css) || (false === stripos((string) $optimized_css, '@font-face') && '' === trim($delayed_font_css) && empty($preserve_mixed_css_for_google_imports) && empty($css_image_rewrite_stats['cssImageUrlsRewritten']))) {
                return array();
            }

            if (function_exists('ucwp_strip_source_mapping_url_comments')) {
                $optimized_css = trim(ucwp_strip_source_mapping_url_comments($optimized_css));
            }

            $hash = md5($source_url . '|' . md5($optimized_css));
            $active_css_is_mixed = !empty($preserve_mixed_css_for_delayed_icon_fonts) || !empty($preserve_mixed_css_for_google_imports) || !empty($preserve_mixed_css_for_font_display);
            $asset_dir_slug = $active_css_is_mixed ? 'optimized-css' : 'font-css';
            $asset_file_prefix = $active_css_is_mixed ? 'active-' : '';
            $write_reason = !empty($preserve_mixed_css_for_google_imports) ? 'optimized active css with localized Google Fonts imports' : (!empty($preserve_mixed_css_for_font_display) ? 'optimized active css with font-display patches' : ($active_css_is_mixed ? 'optimized active css without delayed icon font-face blocks' : 'optimized font css'));

            /*
             * 2.56.198: /font-css/ is reserved for actual font-only or delayed
             * font-face CSS. When Delay icon font-face blocks preserves a mixed
             * theme/plugin stylesheet after extracting icon @font-face blocks, keep
             * the active non-font CSS under /optimized-css/ instead. Otherwise tools
             * such as Lighthouse correctly flag a large render stylesheet, but show
             * it misleadingly as font-css.
             */
            $dir = trailingslashit(UCWP_CACHE_DIR) . $asset_dir_slug . '/';
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            $index_file = $dir . 'index.php';
            if (!file_exists($index_file)) {
                ucwp_safe_file_put_contents($index_file, "<?php
// Silence is golden.
");
            }

            $filename = $asset_file_prefix . $hash . '.css';
            $file = $dir . $filename;
            if (!file_exists($file) || md5_file($file) !== md5($optimized_css)) {
                ucwp_safe_file_put_contents($file, $optimized_css, LOCK_EX, $write_reason);
            }
            clearstatcache(true, $file);
            if (!is_readable($file) || filesize($file) <= 0) {
                return array();
            }

            $asset = array(
                'css_url'      => content_url('cache/ultracache/' . $asset_dir_slug . '/' . $filename),
                'preload_urls' => $active_css_is_mixed ? array() : $this->extract_preloadable_font_urls_from_css($optimized_css, 2),
                'sourceBytes'  => strlen($css),
                'cssBytes'     => strlen($optimized_css),
            );
            if ($active_css_is_mixed) {
                $asset['activeCssIsMixed'] = true;
                $asset['activeCssBucket'] = $asset_dir_slug;
            }
            if (!empty($preserve_mixed_css_for_google_imports)) {
                $asset['googleFontsImportOptimization'] = array(
                    'sourceUrl' => $source_url,
                    'found' => (int) ($google_import_stats['found'] ?? 0),
                    'localized' => (int) ($google_import_stats['localized'] ?? 0),
                    'remainingRemote' => (int) ($google_import_stats['remainingRemote'] ?? 0),
                    'displaySwapOnly' => (int) ($google_import_stats['displaySwapOnly'] ?? 0),
                    'beforeBytes' => strlen($css),
                    'afterBytes' => strlen((string) $optimized_css),
                );
            }
            if (!empty($font_css_optimization_stats)) {
                $asset['fontCssOptimization'] = $font_css_optimization_stats;
            }
            if (!empty($css_image_rewrite_stats['cssImageUrlsRewritten'])) {
                $asset['cssImageUrlOptimization'] = array(
                    'sourceUrl' => $source_url,
                    'scanned' => max(0, (int) ($css_image_rewrite_stats['cssImageUrlsScanned'] ?? 0)),
                    'rewritten' => max(0, (int) ($css_image_rewrite_stats['cssImageUrlsRewritten'] ?? 0)),
                    'imageSet' => max(0, (int) ($css_image_rewrite_stats['cssImageUrlsImageSet'] ?? 0)),
                    'skipped' => max(0, (int) ($css_image_rewrite_stats['cssImageUrlsSkipped'] ?? 0)),
                );
            }

            if ('' !== trim($delayed_font_css)) {
                $delayed_font_content = trim($delayed_font_css) . "
";
                if (function_exists('ucwp_strip_source_mapping_url_comments')) {
                    $delayed_font_content = trim(ucwp_strip_source_mapping_url_comments($delayed_font_content)) . "
";
                }
                if (false !== stripos($delayed_font_content, '.ttf')) {
                    // 2.56.197: delayed standalone font-css output must get the
                    // same local TTF -> WOFF2/WOFF sibling cleanup as the render path.
                    $delayed_font_content = $this->rewrite_font_face_ttf_sources_to_preferred_formats($delayed_font_content, $source_url);
                    $delayed_font_content = trim((string) $delayed_font_content) . "
";
                }
                $delayed_font_display_stats = array();
                $delayed_font_content = $this->normalize_font_face_display_in_css($delayed_font_content, $delayed_font_display_stats);
                if ('' === trim($delayed_font_content) || false === stripos($delayed_font_content, '@font-face')) {
                    if ($active_css_is_mixed) {
                        return array();
                    }
                } else {
                    /*
                     * 2.56.199: delayed icon-font CSS always belongs in /font-css/,
                     * even when the active preserved stylesheet is stored under
                     * /optimized-css/. The previous build wrote mixed active CSS to
                     * optimized-css but still used that active directory for delayed-*.css,
                     * while the HTML pointed to font-css/delayed-*.css. That created
                     * missing stylesheet refs and broke icon-font glyphs after a clean CSS
                     * rebuild. Keep the extraction atomic: if the delayed font file cannot
                     * be written and verified, do not replace the original stylesheet.
                     */
                    $delayed_dir = trailingslashit(UCWP_CACHE_DIR) . 'font-css/';
                    if (!is_dir($delayed_dir)) {
                        wp_mkdir_p($delayed_dir);
                    }
                    $delayed_index_file = $delayed_dir . 'index.php';
                    if (!file_exists($delayed_index_file)) {
                        ucwp_safe_file_put_contents($delayed_index_file, "<?php
// Silence is golden.
");
                    }
                    $delayed_font_hash = md5($source_url . '|delayed|' . md5($delayed_font_content));
                    $delayed_font_filename = 'delayed-' . $delayed_font_hash . '.css';
                    $delayed_font_file = $delayed_dir . $delayed_font_filename;
                    if (!file_exists($delayed_font_file) || md5_file($delayed_font_file) !== md5($delayed_font_content)) {
                        ucwp_safe_file_put_contents($delayed_font_file, $delayed_font_content, LOCK_EX, 'delayed optimized font css');
                    }
                    clearstatcache(true, $delayed_font_file);
                    if (!is_readable($delayed_font_file) || filesize($delayed_font_file) <= 0) {
                        return array();
                    }
                    $delayed_font_url = content_url('cache/ultracache/font-css/' . $delayed_font_filename);
                    $asset['delayedFontUrl'] = $delayed_font_url;
                    $asset['delayed_font_url'] = $delayed_font_url;
                    $asset['delayedFontFile'] = $delayed_font_file;
                    $asset['delayedFontFaceBlocks'] = $delayed_font_count;
                    $asset['delayedFontFamilies'] = $delayed_font_families;
                    $asset['delayedFontPatterns'] = $delayed_font_patterns;
                    $this->delayed_font_css_assets_current_request[$source_url] = $asset;
                }
            }

            return $asset;
        }

        private function rewrite_inline_font_face_ttf_sources_to_linked_woff2($html)
        {
            $html = (string) $html;
            if ('' === $html || false === stripos($html, '.ttf')) {
                return $html;
            }

            $registry = $this->build_linked_woff2_font_face_registry_from_html($html);

            $changed = false;
            $updated = $html;

            if (false !== stripos($updated, '@font-face')) {
                // 2.56.195: final generic TTF cleanup. Bundle replacement can remove
                // the stylesheet links that originally exposed matching WOFF2 @font-face
                // declarations, then leave inline @font-face TTF blocks in the final HTML.
                // Rewrite any local @font-face TTF declaration using the full active
                // stylesheet/manifest WOFF2 registry, not a vendor-specific target.
                $font_face_updated = preg_replace_callback('/@font-face\s*\{.*?\}/is', function ($matches) use ($registry, &$changed) {
                    $block = isset($matches[0]) ? (string) $matches[0] : '';
                    if ('' === $block || false === stripos($block, '.ttf')) {
                        return $block;
                    }

                    $rewritten = $this->rewrite_inline_font_face_ttf_css_with_woff2_registry($block, $registry);
                    if (is_string($rewritten) && $rewritten !== $block) {
                        $changed = true;
                        return $rewritten;
                    }

                    return $block;
                }, $updated);

                if (is_string($font_face_updated) && '' !== $font_face_updated) {
                    $updated = $font_face_updated;
                }
            }

            if (false !== stripos($updated, '.ttf')) {
                $token_updated = $this->rewrite_generic_local_ttf_url_tokens_to_woff2($updated, $registry);
                if (is_string($token_updated) && $token_updated !== $updated) {
                    $changed = true;
                    $updated = $token_updated;
                }
            }

            return $changed ? $updated : $html;
        }

        private function rewrite_generic_local_ttf_url_tokens_to_woff2($html, array $registry)
        {
            $html = (string) $html;
            if ('' === $html || false === stripos($html, '.ttf')) {
                return $html;
            }

            // Only rewrite local public font URLs. This is intentionally generic: the
            // target is .ttf, not a specific vendor directory. URL tokens outside
            // @font-face are handled only when a safe same-path .woff2 exists; family
            // matching remains inside the @font-face block pass where family/weight/style
            // are known.
            $patterns = array(
                '~https?://[^\s"\'<>)]+/wp-content/[^\s"\'<>)]+?\.ttf(?:\?[^\s"\'<>)]+)?~i',
                '~/wp-content/[^\s"\'<>)]+?\.ttf(?:\?[^\s"\'<>)]+)?~i',
                '~https?:\\/\\/[^\s"\'<>)]+\\/wp-content\\/[^\s"\'<>)]+?\.ttf(?:\?[^\s"\'<>)]+)?~i',
                '~\\/wp-content\\/[^\s"\'<>)]+?\.ttf(?:\?[^\s"\'<>)]+)?~i',
            );

            $updated = $html;
            foreach ($patterns as $pattern) {
                $updated = preg_replace_callback($pattern, function ($matches) {
                    $original = isset($matches[0]) ? (string) $matches[0] : '';
                    if ('' === $original) {
                        return $original;
                    }

                    $slash_escaped = false !== strpos($original, '\\/');
                    $candidate = $slash_escaped ? str_replace('\\/', '/', $original) : $original;
                    $replacement = $this->find_same_path_preferred_font_url_for_ttf_url($candidate);
                    if ('' === $replacement) {
                        return $original;
                    }

                    return $this->prepare_font_url_for_inline_replacement($replacement, $slash_escaped);
                }, $updated);

                if (!is_string($updated) || '' === $updated) {
                    return $html;
                }
            }

            return $updated;
        }

        private function rewrite_themepunch_gfont_ttf_url_tokens_to_linked_woff2($html, array $registry)
        {
            // Backward-compatible wrapper retained for older internal callers/tests.
            return $this->rewrite_generic_local_ttf_url_tokens_to_woff2($html, $registry);
        }

        private function build_linked_woff2_font_face_registry_from_html($html)
        {
            $registry = array();
            $html = (string) $html;
            // The final HTML may not contain direct .woff2 references when CSS bundling is active:
            // the matching @font-face WOFF2 declarations can live inside linked generated CSS bundles.
            // Therefore only require linked stylesheets here and inspect the stylesheet contents below.
            if ('' === $html || false === stripos($html, '<link')) {
                return $registry;
            }

            if (!preg_match_all('/<link\b[^>]*\bhref=("|\')(.*?)\1[^>]*>/is', $html, $matches, PREG_SET_ORDER)) {
                return $registry;
            }

            foreach ($matches as $match) {
                $tag = isset($match[0]) ? (string) $match[0] : '';
                if ('' === $tag || !$this->html_tag_rel_contains_stylesheet($tag)) {
                    continue;
                }

                $href = isset($match[2]) ? html_entity_decode((string) $match[2], ENT_QUOTES) : '';
                $stylesheet_url = $this->normalize_public_resource_url($this->absolutize_public_resource_url($href, home_url('/')));
                if ('' === $stylesheet_url) {
                    continue;
                }

                $path = $this->resolve_local_path_from_public_url($stylesheet_url);
                if ('' === $path || !is_readable($path)) {
                    continue;
                }

                $css = ucwp_safe_file_get_contents($path, 'build_linked_woff2_font_face_registry_from_html', true);
                if (!is_string($css) || '' === $css || false === stripos($css, '@font-face') || false === stripos($css, '.woff2')) {
                    continue;
                }

                if (!preg_match_all('/@font-face\s*\{.*?\}/is', $css, $blocks)) {
                    continue;
                }

                foreach ((array) $blocks[0] as $block) {
                    $block = (string) $block;
                    if (false === stripos($block, '.woff2')) {
                        continue;
                    }

                    $family_key = $this->normalize_font_face_family_key($this->extract_font_face_css_declaration($block, 'font-family'));
                    if ('' === $family_key) {
                        continue;
                    }

                    $style_key = $this->normalize_font_face_style_key($this->extract_font_face_css_declaration($block, 'font-style'));
                    $weight_range = $this->normalize_font_face_weight_range($this->extract_font_face_css_declaration($block, 'font-weight'));
                    $woff2_url = $this->extract_first_font_face_woff2_url($block, $stylesheet_url);
                    if ('' === $woff2_url) {
                        continue;
                    }

                    if (!isset($registry[$family_key])) {
                        $registry[$family_key] = array();
                    }
                    if (!isset($registry[$family_key][$style_key])) {
                        $registry[$family_key][$style_key] = array();
                    }

                    $entry_key = (string) $weight_range['min'] . '-' . (string) $weight_range['max'] . '|' . $woff2_url;
                    $registry[$family_key][$style_key][$entry_key] = array(
                        'family' => $family_key,
                        'style'  => $style_key,
                        'weight' => $weight_range,
                        'url'    => $woff2_url,
                    );
                }
            }

            if (false !== stripos($html, 'data-ucwp-page-css-bundle=') || false !== stripos($html, 'data-ucwp-frontpage-css=') || false !== stripos($html, 'id="ucwp-page-css-bundle"') || false !== stripos($html, "id='ucwp-page-css-bundle'") || false !== stripos($html, 'id="ucwp-frontpage-css"') || false !== stripos($html, "id='ucwp-frontpage-css'")) {
                $entry = $this->get_frontpage_css_manifest_entry();
                if (!empty($entry['sourceUrls']) && is_array($entry['sourceUrls'])) {
                    foreach ((array) $entry['sourceUrls'] as $source_url) {
                        $source_url = $this->normalize_public_resource_url((string) $source_url);
                        if ('' === $source_url) {
                            continue;
                        }

                        $this->add_woff2_font_faces_from_css_url_to_registry($registry, $source_url);

                        $asset = $this->build_optimized_font_css_asset($source_url);
                        if (!empty($asset['css_url'])) {
                            $this->add_woff2_font_faces_from_css_url_to_registry($registry, (string) $asset['css_url']);
                        }
                    }
                }

                $bundle_map = $this->build_runtime_font_css_url_map_from_bundle_manifest();
                if (!empty($bundle_map) && is_array($bundle_map)) {
                    foreach ((array) $bundle_map as $mapped_css_url) {
                        $this->add_woff2_font_faces_from_css_url_to_registry($registry, (string) $mapped_css_url);
                    }
                }
            }

            foreach ($registry as $family => $styles) {
                foreach ($styles as $style => $entries) {
                    $registry[$family][$style] = array_values($entries);
                }
            }

            return $registry;
        }

        private function add_woff2_font_faces_from_css_url_to_registry(array &$registry, $stylesheet_url)
        {
            $stylesheet_url = $this->normalize_public_resource_url($this->absolutize_public_resource_url((string) $stylesheet_url, home_url('/')));
            if ('' === $stylesheet_url) {
                return 0;
            }

            $path = $this->resolve_local_path_from_public_url($stylesheet_url);
            if ('' === $path || !is_readable($path)) {
                return 0;
            }

            $css = ucwp_safe_file_get_contents($path, 'add_woff2_font_faces_from_css_url_to_registry', true);
            if (!is_string($css) || '' === $css || false === stripos($css, '@font-face') || false === stripos($css, '.woff2')) {
                return 0;
            }

            if (!preg_match_all('/@font-face\s*\{.*?\}/is', $css, $blocks)) {
                return 0;
            }

            $added = 0;
            foreach ((array) $blocks[0] as $block) {
                $block = (string) $block;
                if (false === stripos($block, '.woff2')) {
                    continue;
                }

                $family_key = $this->normalize_font_face_family_key($this->extract_font_face_css_declaration($block, 'font-family'));
                if ('' === $family_key) {
                    continue;
                }

                $style_key = $this->normalize_font_face_style_key($this->extract_font_face_css_declaration($block, 'font-style'));
                $weight_range = $this->normalize_font_face_weight_range($this->extract_font_face_css_declaration($block, 'font-weight'));
                $woff2_url = $this->extract_first_font_face_woff2_url($block, $stylesheet_url);
                if ('' === $woff2_url) {
                    continue;
                }

                if (!isset($registry[$family_key])) {
                    $registry[$family_key] = array();
                }
                if (!isset($registry[$family_key][$style_key])) {
                    $registry[$family_key][$style_key] = array();
                }

                $entry_key = (string) $weight_range['min'] . '-' . (string) $weight_range['max'] . '|' . $woff2_url;
                if (empty($registry[$family_key][$style_key][$entry_key])) {
                    $added++;
                }
                $registry[$family_key][$style_key][$entry_key] = array(
                    'family' => $family_key,
                    'style'  => $style_key,
                    'weight' => $weight_range,
                    'url'    => $woff2_url,
                );
            }

            return $added;
        }

        private function rewrite_inline_font_face_ttf_css_with_woff2_registry($css, array $registry)
        {
            $css = (string) $css;
            if ('' === $css || false === stripos($css, '@font-face') || false === stripos($css, '.ttf')) {
                return $css;
            }

            $changed = false;
            $updated = preg_replace_callback('/@font-face\s*\{.*?\}/is', function ($matches) use ($registry, &$changed) {
                $block = isset($matches[0]) ? (string) $matches[0] : '';
                if ('' === $block || false === stripos($block, '.ttf')) {
                    return $block;
                }

                $family_key = $this->normalize_font_face_family_key($this->extract_font_face_css_declaration($block, 'font-family'));
                if ('' === $family_key) {
                    return $this->normalize_font_face_display_in_css($block);
                }

                $style_key = $this->normalize_font_face_style_key($this->extract_font_face_css_declaration($block, 'font-style'));
                $weight_range = $this->normalize_font_face_weight_range($this->extract_font_face_css_declaration($block, 'font-weight'));
                $replacement_url = $this->find_same_path_preferred_font_url_for_font_face_ttf_block($block);
                if ('' === $replacement_url) {
                    $replacement_url = $this->find_matching_woff2_font_face_url($registry, $family_key, $style_key, $weight_range);
                }
                if ('' === $replacement_url) {
                    return $this->normalize_font_face_display_in_css($block);
                }

                $slash_escaped = false !== strpos($block, '\\/');
                $replacement_url = $this->prepare_font_url_for_inline_replacement($replacement_url, $slash_escaped);
                if ('' === $replacement_url) {
                    return $this->normalize_font_face_display_in_css($block);
                }

                $replacement_format = $this->get_font_format_for_font_url($replacement_url);
                $new_src = 'src:url(' . $replacement_url . ') format("' . $replacement_format . '");';
                $rewritten = preg_replace('/src\s*:\s*[^;}]+\s*;?/i', $new_src, $block, 1);
                if (!is_string($rewritten) || '' === $rewritten) {
                    return $this->normalize_font_face_display_in_css($block);
                }

                $rewritten = $this->normalize_font_face_display_in_css($rewritten);
                if ($rewritten !== $block) {
                    $changed = true;
                }

                return $rewritten;
            }, $css);

            return is_string($updated) && $changed ? $updated : $css;
        }

        private function extract_font_face_css_declaration($block, $property)
        {
            if (function_exists('ucwp_font_css_extract_declaration')) {
                return ucwp_font_css_extract_declaration($block, $property);
            }

            $property = preg_quote((string) $property, '/');
            if (preg_match('/' . $property . '\s*:\s*([^;}]+)\s*;?/i', (string) $block, $matches)) {
                return trim((string) $matches[1]);
            }

            return '';
        }

        private function normalize_font_face_family_key($family)
        {
            $family = trim((string) $family, " \t\n\r\0\x0B\"'");
            $family = preg_replace('/\s+/', ' ', $family);
            return is_string($family) ? strtolower($family) : '';
        }

        private function normalize_font_face_style_key($style)
        {
            $style = strtolower(trim((string) $style, " \t\n\r\0\x0B\"'"));
            if ('' === $style) {
                return 'normal';
            }

            if (false !== strpos($style, 'italic')) {
                return 'italic';
            }

            if (false !== strpos($style, 'oblique')) {
                return 'oblique';
            }

            return 'normal';
        }

        private function normalize_font_face_weight_range($weight)
        {
            $weight = strtolower(trim((string) $weight, " \t\n\r\0\x0B\"'"));
            if ('' === $weight || 'normal' === $weight) {
                return array('raw' => '400', 'min' => 400, 'max' => 400);
            }
            if ('bold' === $weight) {
                return array('raw' => '700', 'min' => 700, 'max' => 700);
            }

            if (preg_match_all('/\d{3}/', $weight, $matches) && !empty($matches[0])) {
                $values = array_map('intval', $matches[0]);
                $values = array_values(array_filter($values, static function ($value) {
                    return $value >= 100 && $value <= 1000;
                }));
                if (!empty($values)) {
                    return array('raw' => $weight, 'min' => min($values), 'max' => max($values));
                }
            }

            return array('raw' => '' !== $weight ? $weight : '400', 'min' => 400, 'max' => 400);
        }

        /**
         * Rewrite local TTF src() entries inside @font-face CSS to same-path WOFF2/WOFF
         * siblings when available. This is intentionally generic and applies to delayed
         * icon-font stylesheets as well as final inline cleanup.
         */
        private function rewrite_font_face_ttf_sources_to_preferred_formats($css, $base_url = '')
        {
            $css = (string) $css;
            if ('' === $css || false === stripos($css, '.ttf')) {
                return $css;
            }

            $base_url = '' !== (string) $base_url ? (string) $base_url : home_url('/');
            $changed = false;
            $updated = preg_replace_callback('/@font-face\s*\{.*?\}/is', function ($matches) use ($base_url, &$changed) {
                $block = isset($matches[0]) ? (string) $matches[0] : '';
                if ('' === $block || false === stripos($block, '.ttf')) {
                    return $block;
                }

                $block_changed = false;
                $rewritten = preg_replace_callback('/src\s*:\s*([^;}]+)\s*;?/i', function ($src_matches) use ($base_url, &$block_changed) {
                    $src = isset($src_matches[1]) ? (string) $src_matches[1] : '';
                    if ('' === trim($src)) {
                        return (string) ($src_matches[0] ?? '');
                    }

                    $items = function_exists('ucwp_font_css_split_src_items') ? ucwp_font_css_split_src_items($src) : array_map('trim', explode(',', $src));
                    if (empty($items)) {
                        return (string) ($src_matches[0] ?? '');
                    }

                    $has_better_source = false;
                    foreach ($items as $item) {
                        $item_lc = strtolower((string) $item);
                        if (false !== strpos($item_lc, '.woff2') || false !== strpos($item_lc, "format('woff2')") || false !== strpos($item_lc, 'format("woff2")') || preg_match('/format\(\s*woff2\s*\)/i', $item_lc)) {
                            $has_better_source = true;
                            break;
                        }
                        if (false !== strpos($item_lc, '.woff') || false !== strpos($item_lc, "format('woff')") || false !== strpos($item_lc, 'format("woff")') || preg_match('/format\(\s*woff\s*\)/i', $item_lc)) {
                            $has_better_source = true;
                        }
                    }

                    $seen = array();
                    $kept = array();
                    foreach ($items as $item) {
                        $item = trim((string) $item);
                        if ('' === $item) {
                            continue;
                        }

                        $is_ttf_item = (false !== stripos($item, '.ttf')) || (bool) preg_match('/format\(\s*["\']?truetype["\']?\s*\)/i', $item);
                        if ($is_ttf_item) {
                            $ttf_url = $this->extract_first_ttf_url_from_css_src_item($item, $base_url);
                            $replacement_url = '' !== $ttf_url ? $this->find_same_path_preferred_font_url_for_ttf_url($ttf_url) : '';
                            if ('' !== $replacement_url) {
                                $format = $this->get_font_format_for_font_url($replacement_url);
                                $item = 'url(' . esc_url_raw($replacement_url) . ') format("' . $format . '")';
                                $block_changed = true;
                            } elseif ($has_better_source) {
                                // A WOFF2/WOFF source is already present in the same src list.
                                // Keep the better source and drop the TTF fallback from delayed CSS.
                                $block_changed = true;
                                continue;
                            }
                        }

                        $key = strtolower((string) preg_replace('/\s+/', '', $item));
                        if (isset($seen[$key])) {
                            $block_changed = true;
                            continue;
                        }
                        $seen[$key] = true;
                        $kept[] = $item;
                    }

                    if (empty($kept)) {
                        return (string) ($src_matches[0] ?? '');
                    }

                    return 'src:' . implode(',', $kept) . ';';
                }, $block);

                if (!is_string($rewritten) || '' === $rewritten) {
                    return $block;
                }

                $rewritten = $this->normalize_font_face_display_in_css($rewritten);
                if ($block_changed || $rewritten !== $block) {
                    $changed = true;
                    return $rewritten;
                }

                return $block;
            }, $css);

            return is_string($updated) && $changed ? $updated : $css;
        }

        private function extract_first_ttf_url_from_css_src_item($item, $base_url = '')
        {
            $item = (string) $item;
            if ('' === $item || false === stripos($item, '.ttf')) {
                return '';
            }

            if (!preg_match('/url\(\s*(["\']?)([^)"\']+\.ttf(?:[?#][^)"\']*)?)\1\s*\)/i', $item, $matches)) {
                return '';
            }

            $raw = isset($matches[2]) ? trim((string) $matches[2]) : '';
            if ('' === $raw) {
                return '';
            }

            $base_url = '' !== (string) $base_url ? (string) $base_url : home_url('/');
            $url = $this->normalize_public_resource_url($this->absolutize_public_resource_url($raw, $base_url));
            return '' !== $url ? esc_url_raw($url) : '';
        }

        private function find_same_path_preferred_font_url_for_font_face_ttf_block($block)
        {
            $block = (string) $block;
            if ('' === $block || false === stripos($block, '.ttf')) {
                return '';
            }

            if (!preg_match_all('/url\(([^)]+\.ttf(?:[\?#][^)]*)?)\)/i', $block, $matches)) {
                return '';
            }

            foreach ((array) $matches[1] as $raw) {
                $raw = trim((string) $raw, " \t\n\r\0\x0B\"'");
                if ('' === $raw) {
                    continue;
                }

                $replacement = $this->find_same_path_preferred_font_url_for_ttf_url($raw);
                if ('' !== $replacement) {
                    return $replacement;
                }
            }

            return '';
        }

        private function find_same_path_preferred_font_url_for_ttf_url($ttf_url)
        {
            $ttf_url = trim((string) $ttf_url);
            if ('' === $ttf_url || false === stripos($ttf_url, '.ttf')) {
                return '';
            }

            $normalized = $this->normalize_public_resource_url($this->absolutize_public_resource_url($ttf_url, home_url('/')));
            if ('' === $normalized) {
                return '';
            }

            $path = (string) wp_parse_url($normalized, PHP_URL_PATH);
            if ('' === $path || !preg_match('/\.ttf$/i', $path)) {
                return '';
            }

            $query = (string) wp_parse_url($normalized, PHP_URL_QUERY);
            foreach (array('.woff2', '.woff') as $extension) {
                $candidate_path = preg_replace('/\.ttf$/i', $extension, $path, 1);
                if (!is_string($candidate_path) || '' === $candidate_path) {
                    continue;
                }

                $candidate_url = $candidate_path . ('' !== $query ? ('?' . $query) : '');
                $candidate_abs = $this->normalize_public_resource_url($this->absolutize_public_resource_url($candidate_url, home_url('/')));
                if ('' === $candidate_abs) {
                    continue;
                }

                $candidate_file = $this->resolve_local_path_from_public_url($candidate_abs);
                if ('' === $candidate_file || !is_readable($candidate_file)) {
                    continue;
                }

                $size = function_exists('ucwp_safe_filesize') ? (int) ucwp_safe_filesize($candidate_file, 'same_path_preferred_font_candidate') : (int) @filesize($candidate_file);
                if ($size <= 0) {
                    continue;
                }

                return esc_url_raw($candidate_abs);
            }

            return '';
        }

        private function find_same_path_woff2_url_for_font_face_ttf_block($block)
        {
            $url = $this->find_same_path_preferred_font_url_for_font_face_ttf_block($block);
            return preg_match('/\.woff2(?:$|[?#])/i', (string) $url) ? $url : '';
        }

        private function find_same_path_woff2_url_for_ttf_url($ttf_url)
        {
            $url = $this->find_same_path_preferred_font_url_for_ttf_url($ttf_url);
            return preg_match('/\.woff2(?:$|[?#])/i', (string) $url) ? $url : '';
        }

        private function get_font_format_for_font_url($url)
        {
            $path = strtolower((string) wp_parse_url((string) $url, PHP_URL_PATH));
            if (preg_match('/\.woff2$/', $path)) {
                return 'woff2';
            }
            if (preg_match('/\.woff$/', $path)) {
                return 'woff';
            }
            return 'woff2';
        }

        private function extract_first_font_face_woff2_url($block, $base_url)
        {
            $block = (string) $block;
            if ('' === $block || false === stripos($block, '.woff2')) {
                return '';
            }

            if (!preg_match_all('/url\(([^)]+\.woff2(?:[\?#][^)]*)?)\)/i', $block, $matches)) {
                return '';
            }

            foreach ((array) $matches[1] as $raw) {
                $raw = trim((string) $raw, " \t\n\r\0\x0B\"'");
                if ('' === $raw) {
                    continue;
                }
                $url = $this->normalize_public_resource_url($this->absolutize_public_resource_url($raw, $base_url));
                if ('' !== $url) {
                    return esc_url_raw($url);
                }
            }

            return '';
        }

        private function find_matching_woff2_font_face_url(array $registry, $family_key, $style_key, array $weight_range)
        {
            $family_key = $this->normalize_font_face_family_key($family_key);
            $style_key = $this->normalize_font_face_style_key($style_key);
            if ('' === $family_key || empty($registry[$family_key])) {
                return '';
            }

            $styles_to_try = array($style_key);
            if ('normal' !== $style_key) {
                $styles_to_try[] = 'normal';
            }

            $requested_min = (int) ($weight_range['min'] ?? 400);
            $requested_max = (int) ($weight_range['max'] ?? $requested_min);
            $requested = $requested_min === $requested_max ? $requested_min : $requested_min;

            foreach ($styles_to_try as $style) {
                if (empty($registry[$family_key][$style]) || !is_array($registry[$family_key][$style])) {
                    continue;
                }

                foreach ($registry[$family_key][$style] as $entry) {
                    $entry_min = (int) ($entry['weight']['min'] ?? 400);
                    $entry_max = (int) ($entry['weight']['max'] ?? $entry_min);
                    if ($requested >= $entry_min && $requested <= $entry_max && !empty($entry['url'])) {
                        return esc_url_raw((string) $entry['url']);
                    }
                }
            }

            return '';
        }

        private function find_first_woff2_font_face_url_for_family(array $registry, $family_key)
        {
            $family_key = $this->normalize_font_face_family_key($family_key);
            if ('' === $family_key || empty($registry[$family_key]) || !is_array($registry[$family_key])) {
                return '';
            }

            foreach (array('normal', 'italic', 'oblique') as $preferred_style) {
                if (empty($registry[$family_key][$preferred_style]) || !is_array($registry[$family_key][$preferred_style])) {
                    continue;
                }
                foreach ($registry[$family_key][$preferred_style] as $entry) {
                    if (!empty($entry['url'])) {
                        return esc_url_raw((string) $entry['url']);
                    }
                }
            }

            foreach ($registry[$family_key] as $entries) {
                if (!is_array($entries)) {
                    continue;
                }
                foreach ($entries as $entry) {
                    if (!empty($entry['url'])) {
                        return esc_url_raw((string) $entry['url']);
                    }
                }
            }

            return '';
        }

        private function prepare_font_url_for_inline_replacement($url, $slash_escaped = false)
        {
            $url = $this->normalize_public_resource_url((string) $url);
            if ('' === $url) {
                return '';
            }

            $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
            if ('' !== $host && '' !== $home_host && $host === $home_host) {
                $path = (string) wp_parse_url($url, PHP_URL_PATH);
                $query = (string) wp_parse_url($url, PHP_URL_QUERY);
                if ('' !== $path) {
                    $url = $path . ('' !== $query ? ('?' . $query) : '');
                }
            }

            $url = esc_url_raw($url);
            if ('' === $url) {
                return '';
            }

            return $slash_escaped ? str_replace('/', '\/', $url) : $url;
        }

        private function build_runtime_font_css_url_map_from_html($html)
        {
            $map = array();
            if (!is_string($html) || '' === $html || false === stripos($html, '<link') || false === stripos($html, '.css')) {
                return $map;
            }

            if (!preg_match_all('/<link\b[^>]*\bhref=(\"|\')(.*?)\1[^>]*>/is', $html, $matches, PREG_SET_ORDER)) {
                return $map;
            }

            foreach ($matches as $match) {
                $tag = isset($match[0]) ? (string) $match[0] : '';
                if (!$this->html_tag_rel_contains_stylesheet($tag)) {
                    continue;
                }

                $href = isset($match[2]) ? html_entity_decode((string) $match[2], ENT_QUOTES) : '';
                $source_url = $this->normalize_public_resource_url($href);
                if ('' === $source_url) {
                    continue;
                }

                $path = strtolower((string) wp_parse_url($source_url, PHP_URL_PATH));
                if (false !== strpos($path, '/cache/ultracache/css-bundles/') || false !== strpos($path, '/cache/ultracache/font-css/') || false !== strpos($path, '/cache/ultracache/optimized-css/')) {
                    continue;
                }

                $asset = $this->build_optimized_font_css_asset($source_url);
                $css_url = isset($asset['css_url']) ? esc_url_raw((string) $asset['css_url']) : '';
                if ('' !== $css_url && $css_url !== $source_url) {
                    $map[$source_url] = $css_url;
                }
            }

            return $this->normalize_runtime_font_css_url_map($map);
        }

        private function build_runtime_font_css_url_map_from_bundle_manifest($entry_url = '')
        {
            $map = array();
            $entry_url = '' !== (string) $entry_url ? (string) $entry_url : $this->get_current_request_url();
            $entry = $this->get_frontpage_css_manifest_entry($entry_url);
            if (empty($entry) || empty($entry['sourceUrls']) || !is_array($entry['sourceUrls'])) {
                return $map;
            }

            foreach ((array) $entry['sourceUrls'] as $source_url) {
                $source_url = $this->normalize_public_resource_url((string) $source_url);
                if ('' === $source_url) {
                    continue;
                }

                $asset = $this->build_optimized_font_css_asset($source_url);
                $css_url = isset($asset['css_url']) ? esc_url_raw((string) $asset['css_url']) : '';
                if ('' !== $css_url && $css_url !== $source_url) {
                    $map[$source_url] = $css_url;
                }
            }

            return $this->normalize_runtime_font_css_url_map($map);
        }

        private function inject_runtime_font_css_url_map($html)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '</head>')) {
                return $html;
            }

            if (false !== stripos($html, 'data-ucwp-font-css-map=')) {
                return $html;
            }

            $map_sources = array();
            $map = $this->get_runtime_local_font_css_url_map();
            if (!empty($map) && is_array($map)) {
                $map_sources[] = 'cache';
            }

            if (!empty($this->runtime_font_css_url_map_current_request) && is_array($this->runtime_font_css_url_map_current_request)) {
                $map = array_merge($map, $this->runtime_font_css_url_map_current_request);
                $map_sources[] = 'current-request';
            }

            $html_map = $this->build_runtime_font_css_url_map_from_html($html);
            if (!empty($html_map)) {
                $this->remember_runtime_font_css_url_mappings($html_map);
                $map = array_merge($map, $html_map);
                $map_sources[] = 'html';
            }

            if (false !== stripos($html, 'data-ucwp-page-css-bundle=') || false !== stripos($html, 'data-ucwp-frontpage-css=')) {
                $bundle_map = $this->build_runtime_font_css_url_map_from_bundle_manifest();
                if (!empty($bundle_map)) {
                    $this->remember_runtime_font_css_url_mappings($bundle_map);
                    $map = array_merge($map, $bundle_map);
                    $map_sources[] = 'bundle-manifest';
                }
            }

            $map = $this->normalize_runtime_font_css_url_map(is_array($map) ? $map : array());
            if (!empty($map)) {
                $this->save_runtime_local_font_css_url_map($map);
            }

            $json = wp_json_encode($map);
            if (!is_string($json) || '' === $json) {
                $json = '{}';
            }

            $source_label = implode(',', array_values(array_unique(array_filter($map_sources))));
            if ('' === $source_label) {
                $source_label = 'empty';
            }

            $script = '<script data-ucwp-font-css-map="1" data-ucwp-runtime-font-rewrite="1" data-ucwp-runtime-font-rewrite-policy="bounded-head-observer" data-ucwp-font-css-map-count="' . esc_attr((string) count($map)) . '" data-ucwp-font-css-map-source="' . esc_attr($source_label) . '">(function(){var map=' . $json . ';if(!map||typeof map!=="object"){map={};}var maxLinks=80;var seen=0;var toAbs=function(url){if(!url){return "";}try{return new URL(url,document.baseURI).href;}catch(e){try{var a=document.createElement("a");a.href=url;return a.href||url;}catch(err){return url;}}};var rewrite=function(node){if(!node||node.nodeType!==1||seen>=maxLinks){return;}var tag=String(node.tagName||"").toLowerCase();if(tag!=="link"){return;}var rel=String(node.getAttribute("rel")||"").toLowerCase();if(rel.indexOf("stylesheet")===-1){return;}seen++;var href=node.getAttribute("href")||node.href||"";if(!href){return;}var abs=toAbs(href);if(abs&&map[abs]&&abs!==map[abs]){node.setAttribute("href",map[abs]);node.setAttribute("data-ucwp-runtime-font-rewrite-hit","1");try{node.href=map[abs];}catch(e){}}};var scan=function(root){try{var base=root&&root.querySelectorAll?root:document;var links=base.querySelectorAll?base.querySelectorAll("link[rel][href]"):[];for(var i=0;i<links.length&&seen<maxLinks;i++){rewrite(links[i]);}if(root&&root.nodeType===1){rewrite(root);}}catch(e){}};scan(document);try{var target=document.head||document.documentElement||document.body;var mo=new MutationObserver(function(list){for(var i=0;i<list.length&&seen<maxLinks;i++){var added=list[i]&&list[i].addedNodes?list[i].addedNodes:[];for(var j=0;j<added.length&&seen<maxLinks;j++){scan(added[j]);}}});if(target){mo.observe(target,{childList:true,subtree:true});setTimeout(function(){try{mo.disconnect();}catch(e){}},10000);}}catch(e){}})();</script>';

            return $this->insert_html_before_closing_head($html, $script);
        }

        private function normalize_inline_style_font_display_in_html($html)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<style') || false === stripos($html, '@font-face')) {
                return $html;
            }

            $updated = preg_replace_callback(
                '/<style\b([^>]*)>([\s\S]*?)<\/style>/i',
                function ($matches) {
                    $attrs = isset($matches[1]) ? (string) $matches[1] : '';
                    $css = isset($matches[2]) ? (string) $matches[2] : '';

                    if ('' === $css || false === stripos($css, '@font-face')) {
                        return (string) $matches[0];
                    }

                    if (preg_match('/\btype\s*=\s*(["\'])(.*?)\1/i', $attrs, $type_match)) {
                        $type = strtolower(trim((string) ($type_match[2] ?? '')));
                        if ('' !== $type && 'text/css' !== $type) {
                            return (string) $matches[0];
                        }
                    }

                    $patched_css = $this->normalize_font_face_display_in_css($css);
                    if (!is_string($patched_css) || $patched_css === $css) {
                        return (string) $matches[0];
                    }

                    return '<style' . $attrs . ' data-ucwp-inline-font-display-patched="1">' . $patched_css . '</style>';
                },
                $html
            );

            return is_string($updated) && '' !== $updated ? $updated : $html;
        }

        private function inject_runtime_font_display_cssom_patch($html)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '</head>')) {
                return $html;
            }

            if (false !== stripos($html, 'data-ucwp-font-display-cssom-patch=')) {
                return $html;
            }

            $script = <<<'JS'
<script data-ucwp-font-display-cssom-patch="1" data-ucwp-font-display-cssom-policy="bounded-idle">(function(){if(window.__ucwpFontDisplayCssomPatch){return;}window.__ucwpFontDisplayCssomPatch=1;var RX=/@font-face\s*\{[^}]*\}/gi;var MAX_SHEETS=48;var MAX_RULES=2500;var patchedRules=0;var scheduled=false;function root(){return document.documentElement||document.body||document.head;}function mark(k,v){try{var r=root();if(r){r.setAttribute('data-ucwp-font-display-'+k,String(v));}}catch(e){}}function patchBlock(block){if(!block||String(block).toLowerCase().indexOf('@font-face')===-1){return block;}if(/font-display\s*:/i.test(block)){return block.replace(/font-display\s*:\s*[^;}]+;?/i,'font-display: swap;');}return block.replace(/}\s*$/,';font-display: swap;}').replace(/\{\s*;/,'{');}function patchText(css){css=String(css||'');if(css.toLowerCase().indexOf('@font-face')===-1){return css;}return css.replace(RX,function(block){return patchBlock(block);});}function patchStyleNode(node){if(!node||node.nodeType!==1||String(node.tagName||'').toLowerCase()!=='style'){return;}var type=String(node.getAttribute('type')||'').toLowerCase();if(type&&type!=='text/css'){return;}var css=node.textContent||'';if(css.toLowerCase().indexOf('@font-face')===-1){return;}var patched=patchText(css);if(patched!==css){node.textContent=patched;node.setAttribute('data-ucwp-font-display-patched','1');}}function patchRule(sheet,rule,index){try{if(!rule||patchedRules>=MAX_RULES){return;}var text=rule.cssText||'';if(String(text).toLowerCase().indexOf('@font-face')===-1){return;}patchedRules++;if(rule.style&&rule.style.setProperty){try{rule.style.setProperty('font-display','swap');return;}catch(e){}}var patched=patchText(text);if(patched!==text&&sheet&&sheet.deleteRule&&sheet.insertRule){sheet.deleteRule(index);sheet.insertRule(patched,index);}}catch(e){}}function patchSheets(){var sheets=document.styleSheets||[];var sheetCount=0;patchedRules=0;for(var i=0;i<sheets.length&&sheetCount<MAX_SHEETS&&patchedRules<MAX_RULES;i++){var rules;try{rules=sheets[i].cssRules||sheets[i].rules;}catch(e){continue;}if(!rules){continue;}sheetCount++;for(var j=0;j<rules.length&&patchedRules<MAX_RULES;j++){patchRule(sheets[i],rules[j],j);}}mark('cssom-sheets',sheetCount);mark('cssom-rules',patchedRules);}function patchStyleNodes(rootNode){try{var base=rootNode&&rootNode.querySelectorAll?rootNode:document;var styles=base.querySelectorAll?base.querySelectorAll('style'):[];for(var i=0;i<styles.length;i++){patchStyleNode(styles[i]);}if(rootNode&&rootNode.nodeType===1&&String(rootNode.tagName||'').toLowerCase()==='style'){patchStyleNode(rootNode);}}catch(e){}}function idle(cb){if('requestIdleCallback' in window){window.requestIdleCallback(cb,{timeout:1200});return;}setTimeout(cb,80);}function scheduleSheets(){if(scheduled){return;}scheduled=true;idle(function(){scheduled=false;patchSheets();});}try{var proto=window.CSSStyleSheet&&window.CSSStyleSheet.prototype;if(proto&&proto.insertRule&&!proto.__ucwpFontDisplayPatched){var insertRule=proto.insertRule;proto.insertRule=function(rule,index){return insertRule.call(this,patchText(rule),index);};if(proto.addRule){var addRule=proto.addRule;proto.addRule=function(selector,style,index){if(String(selector||'').toLowerCase()==='@font-face'){style=patchText('@font-face{'+String(style||'')+'}').replace(/^@font-face\s*\{|}\s*$/gi,'');}return addRule.call(this,selector,style,index);};}proto.__ucwpFontDisplayPatched=1;}}catch(e){}patchStyleNodes(document);scheduleSheets();try{var mo=new MutationObserver(function(list){for(var i=0;i<list.length;i++){var added=list[i]&&list[i].addedNodes?list[i].addedNodes:[];for(var j=0;j<added.length;j++){patchStyleNodes(added[j]);}}});mo.observe(document.documentElement||document.head||document.body,{childList:true,subtree:true});setTimeout(function(){try{mo.disconnect();mark('cssom-observer','disconnected');}catch(e){}},10000);}catch(e){}if(document.addEventListener){document.addEventListener('DOMContentLoaded',scheduleSheets,{once:true});window.addEventListener('load',function(){scheduleSheets();setTimeout(scheduleSheets,1200);},{once:true});}else{window.attachEvent&&window.attachEvent('onload',scheduleSheets);}})();</script>
JS;

            return $this->insert_html_before_closing_head($html, $script);
        }

        private function get_runtime_font_css_map_cache_key()
        {
            return 'ucwp_runtime_font_css_url_map_v3';
        }

        private function clear_runtime_font_css_map_cache()
        {
            $this->runtime_font_css_url_map = null;
            $this->runtime_font_css_url_map_current_request = array();
            delete_transient($this->get_runtime_font_css_map_cache_key());
        }

        private function get_runtime_local_font_css_url_map()
        {
            if (is_array($this->runtime_font_css_url_map)) {
                return $this->runtime_font_css_url_map;
            }

            $cached = get_transient($this->get_runtime_font_css_map_cache_key());
            if (is_array($cached)) {
                $this->runtime_font_css_url_map = $this->normalize_runtime_font_css_url_map($cached);
                return $this->runtime_font_css_url_map;
            }

            $this->runtime_font_css_url_map = array();
            return $this->runtime_font_css_url_map;
        }

        private function normalize_runtime_font_css_url_map(array $map)
        {
            $normalized = array();
            foreach ($map as $source_url => $css_url) {
                $source_url = esc_url_raw((string) $this->normalize_public_resource_url((string) $source_url));
                $css_url = esc_url_raw((string) $this->normalize_public_resource_url((string) $css_url));
                if ('' === $source_url || '' === $css_url || $source_url === $css_url) {
                    continue;
                }
                $normalized[$source_url] = $css_url;
            }

            ksort($normalized);
            return $normalized;
        }

        private function save_runtime_local_font_css_url_map(array $map)
        {
            $map = $this->normalize_runtime_font_css_url_map($map);
            $this->runtime_font_css_url_map = $map;
            set_transient($this->get_runtime_font_css_map_cache_key(), $map, DAY_IN_SECONDS);
            return $map;
        }

        private function remember_runtime_font_css_url_mapping($source_url, $css_url)
        {
            $source_url = esc_url_raw((string) $this->normalize_public_resource_url((string) $source_url));
            $css_url = esc_url_raw((string) $this->normalize_public_resource_url((string) $css_url));
            if ('' === $source_url || '' === $css_url || $source_url === $css_url) {
                return;
            }

            $this->runtime_font_css_url_map_current_request[$source_url] = $css_url;

            $map = $this->get_runtime_local_font_css_url_map();
            if (isset($map[$source_url]) && $map[$source_url] === $css_url) {
                return;
            }

            $map[$source_url] = $css_url;
            $this->save_runtime_local_font_css_url_map($map);
        }

        private function remember_runtime_font_css_url_mappings(array $map)
        {
            if (empty($map)) {
                return;
            }

            $merged = $this->get_runtime_local_font_css_url_map();
            foreach ($map as $source_url => $css_url) {
                $source_url = esc_url_raw((string) $this->normalize_public_resource_url((string) $source_url));
                $css_url = esc_url_raw((string) $this->normalize_public_resource_url((string) $css_url));
                if ('' === $source_url || '' === $css_url || $source_url === $css_url) {
                    continue;
                }
                $this->runtime_font_css_url_map_current_request[$source_url] = $css_url;
                $merged[$source_url] = $css_url;
            }

            $this->save_runtime_local_font_css_url_map($merged);
        }

        private function build_runtime_local_font_css_url_map()
        {
            $map = array();
            foreach ($this->get_local_font_css_scan_roots() as $root) {
                foreach ($this->find_local_font_css_files_in_root($root) as $file) {
                    $public_url = $this->get_public_url_from_local_path($file);
                    if ('' === $public_url) {
                        continue;
                    }

                    $asset = $this->build_optimized_font_css_asset($public_url);
                    $css_url = isset($asset['css_url']) ? esc_url_raw((string) $asset['css_url']) : '';
                    $public_url = esc_url_raw((string) $this->normalize_public_resource_url($public_url));
                    if ('' === $public_url || '' === $css_url || $public_url === $css_url) {
                        continue;
                    }

                    $map[$public_url] = $css_url;
                }
            }

            ksort($map);
            return $map;
        }

        private function get_local_font_css_scan_roots()
        {
            $roots = array();
            foreach (array(WP_CONTENT_DIR . '/plugins', WP_CONTENT_DIR . '/themes', WP_CONTENT_DIR . '/mu-plugins', WP_CONTENT_DIR . '/uploads') as $root) {
                $root = wp_normalize_path((string) $root);
                if ('' !== $root && is_dir($root) && !in_array($root, $roots, true)) {
                    $roots[] = $root;
                }
            }

            return $roots;
        }

        private function find_local_font_css_files_in_root($root)
        {
            $files = array();
            $root = wp_normalize_path((string) $root);
            if ('' === $root || !is_dir($root)) {
                return $files;
            }

            try {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file_info) {
                    if (!$file_info->isFile()) {
                        continue;
                    }

                    $path = wp_normalize_path((string) $file_info->getPathname());
                    if ('.css' !== strtolower(substr($path, -4))) {
                        continue;
                    }

                    if (false !== strpos($path, '/cache/ultracache/')) {
                        continue;
                    }

                    if (!$file_info->isReadable()) {
                        continue;
                    }

                    $contents = ucwp_safe_file_get_contents($path, 'find_local_font_css_files_in_root', true);
                    if (!is_string($contents) || '' === $contents || false === stripos($contents, '@font-face')) {
                        continue;
                    }

                    $files[] = $path;
                }
            } catch (Exception $e) {
                return $files;
            }

            return array_values(array_unique($files));
        }

        private function get_public_url_from_local_path($path)
        {
            $path = wp_normalize_path((string) $path);
            if ('' === $path || !is_readable($path)) {
                return '';
            }

            $content_dir = wp_normalize_path((string) WP_CONTENT_DIR);
            if (0 === strpos($path, $content_dir)) {
                $relative = ltrim(substr($path, strlen($content_dir)), '/');
                return $this->normalize_public_resource_url(content_url($relative));
            }

            $abspath = wp_normalize_path((string) ABSPATH);
            if ('' !== $abspath && 0 === strpos($path, $abspath)) {
                $relative = ltrim(substr($path, strlen($abspath)), '/');
                return $this->normalize_public_resource_url(home_url('/' . $relative));
            }

            return '';
        }

        private function apply_local_font_display_patches_to_html($html)
        {
            $html = (string) $html;
            if ('' === $html || false === stripos($html, '<link') || false === stripos($html, '.css')) {
                return $html;
            }

            $hrefs = array();
            if (preg_match_all('/<link\b[^>]*>/is', $html, $matches)) {
                foreach ((array) $matches[0] as $tag) {
                    $tag = (string) $tag;
                    if ('' === $tag || !$this->html_tag_rel_contains_stylesheet($tag)) {
                        continue;
                    }

                    if (false !== stripos($tag, 'data-ucwp-font-display-patch=') || false !== stripos($tag, 'data-ucwp-delayed-icon-fonts=')) {
                        continue;
                    }

                    $href = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag, 'href'), ENT_QUOTES | ENT_HTML5);
                    if ('' === $href) {
                        continue;
                    }

                    $absolute_href = $this->absolutize_public_resource_url($href, home_url('/'));
                    $normalized = $this->normalize_public_resource_url($absolute_href);
                    if ('' === $normalized || !$this->is_cacheable_local_url($normalized)) {
                        continue;
                    }

                    $path = strtolower((string) wp_parse_url($normalized, PHP_URL_PATH));
                    if (false !== strpos($path, '/cache/ultracache/font-css/') || false !== strpos($path, '/cache/ultracache/css-bundles/') || false !== strpos($path, '/cache/ultracache/optimized-css/')) {
                        continue;
                    }

                    $hrefs[$normalized] = $normalized;
                }
            }

            if (empty($hrefs)) {
                return $html;
            }

            $asset = $this->build_combined_local_font_display_patch_asset(array_values($hrefs));
            if (empty($asset['css_url'])) {
                return $html;
            }

            $href = esc_url((string) $asset['css_url']);
            if ('' === $href || false !== strpos($html, $href)) {
                return $html;
            }

            $markup = '<link rel="stylesheet" id="ucwp-font-display-patch" href="' . $href . '" data-ucwp-font-display-patch="1" />'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
            return $this->insert_html_before_closing_head($html, $markup);
        }

        private function build_combined_local_font_display_patch_asset(array $source_urls)
        {
            $source_urls = array_values(array_unique(array_filter(array_map('strval', $source_urls))));
            if (empty($source_urls)) {
                return array();
            }

            $combined_blocks = array();
            $signature_parts = array();
            $settings = $this->get_settings();
            $stats = array(
                'sourceStylesheets' => 0,
                'fontFaceBlocksScanned' => 0,
                'fontFaceBlocksPatched' => 0,
                'fontDisplayAdded' => 0,
                'duplicateSrcRemoved' => 0,
                'ttfSourcesRemoved' => 0,
                'fontFaceBlocksChanged' => 0,
            );

            foreach ($source_urls as $source_url) {
                $source_url = $this->normalize_public_resource_url($source_url);
                if ('' === $source_url || !$this->is_cacheable_local_url($source_url)) {
                    continue;
                }

                $source_path = $this->resolve_local_path_from_public_url($source_url);
                if ('' === $source_path || !is_readable($source_path)) {
                    continue;
                }

                $source_path_lc = strtolower(str_replace('\\', '/', $source_path));
                if (false !== strpos($source_path_lc, '/cache/ultracache/')) {
                    continue;
                }

                $css = ucwp_safe_file_get_contents($source_path, 'build_local_font_display_patch_asset', true);
                if (!is_string($css) || '' === $css || false === stripos($css, '@font-face')) {
                    continue;
                }

                if (!function_exists('ucwp_extract_font_face_blocks_from_css')) {
                    continue;
                }

                $extracted = ucwp_extract_font_face_blocks_from_css($css);
                $blocks = isset($extracted['blocks']) && is_array($extracted['blocks']) ? $extracted['blocks'] : array();
                if (empty($blocks)) {
                    continue;
                }

                $stats['sourceStylesheets']++;
                $signature_parts[] = $source_url . '|' . (string) ucwp_safe_filemtime($source_path, 'font_display_patch_signature') . '|' . strlen($css);

                foreach ($blocks as $block) {
                    $block = (string) $block;
                    $stats['fontFaceBlocksScanned']++;
                    if (!empty($settings['delay_icon_fonts'])) {
                        $delay_meta = array();
                        if ($this->should_delay_css_font_face_block($block, $css, $settings, $delay_meta)) {
                            // 2.56.196: do not create a render-path font-display patch
                            // that duplicates an icon font scheduled for delayed loading.
                            continue;
                        }
                    }
                    if (false !== stripos($block, 'font-display')) {
                        continue;
                    }

                    $patch_stats = array(
                        'fontDisplayAdded' => 0,
                        'duplicateSrcRemoved' => 0,
                        'ttfSourcesRemoved' => 0,
                        'fontFaceBlocksChanged' => 0,
                    );
                    $block = $this->normalize_protocol_relative_urls_in_css($block, $source_url);
                    if (function_exists('ucwp_optimize_font_face_block')) {
                        $block = ucwp_optimize_font_face_block($block, $patch_stats);
                    } else {
                        $block = $this->normalize_font_face_display_in_css($block);
                    }

                    if ('' === trim($block) || false === stripos($block, '@font-face')) {
                        continue;
                    }

                    $stats['fontFaceBlocksPatched']++;
                    foreach (array('fontDisplayAdded', 'duplicateSrcRemoved', 'ttfSourcesRemoved', 'fontFaceBlocksChanged') as $key) {
                        $stats[$key] += max(0, (int) ($patch_stats[$key] ?? 0));
                    }
                    $combined_blocks[] = trim($block);
                }
            }

            if (empty($combined_blocks)) {
                return array();
            }

            $seen = array();
            $deduped = array();
            foreach ($combined_blocks as $block) {
                $key = strtolower((string) preg_replace('/\s+/', '', $block));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $deduped[] = $block;
            }

            $content = implode("\n", $deduped);
            if (function_exists('ucwp_css_minify_preserve_strings')) {
                $minified = ucwp_css_minify_preserve_strings($content);
                if ('' !== $minified) {
                    $content = $minified;
                }
            }
            $content = trim($content);
            if (function_exists('ucwp_strip_source_mapping_url_comments')) {
                $content = trim(ucwp_strip_source_mapping_url_comments($content));
            }
            if ('' === $content) {
                return array();
            }
            $content .= "\n";

            $dir = trailingslashit(UCWP_CACHE_DIR) . 'font-css/';
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            $index_file = $dir . 'index.php';
            if (!file_exists($index_file)) {
                ucwp_safe_file_put_contents($index_file, "<?php\n// Silence is golden.\n");
            }

            $hash = md5(implode('||', $signature_parts) . '|' . md5($content));
            $filename = 'font-display-' . $hash . '.css';
            $file = $dir . $filename;
            if (!file_exists($file) || md5_file($file) !== md5($content)) {
                ucwp_safe_file_put_contents($file, $content, LOCK_EX, 'font display patch css');
            }

            $stats['bytes'] = strlen($content);
            $stats['file'] = $file;
            return array(
                'css_url' => content_url('cache/ultracache/font-css/' . $filename),
                'file' => $file,
                'stats' => $stats,
            );
        }

        private function css_has_font_face_requiring_display_normalization($css)
        {
            $css = (string) $css;
            if ('' === $css || false === stripos($css, '@font-face')) {
                return false;
            }

            if (function_exists('ucwp_extract_font_face_blocks_from_css')) {
                $extracted = ucwp_extract_font_face_blocks_from_css($css);
                $blocks = isset($extracted['blocks']) && is_array($extracted['blocks']) ? $extracted['blocks'] : array();
                foreach ($blocks as $block) {
                    if ($this->font_face_block_requires_display_normalization((string) $block)) {
                        return true;
                    }
                }

                return false;
            }

            if (!preg_match_all('/@font-face\s*{.*?}/is', $css, $matches)) {
                return false;
            }

            foreach ((array) ($matches[0] ?? array()) as $block) {
                if ($this->font_face_block_requires_display_normalization((string) $block)) {
                    return true;
                }
            }

            return false;
        }

        private function font_face_block_requires_display_normalization($block)
        {
            $block = (string) $block;
            if ('' === $block || false === stripos($block, '@font-face')) {
                return false;
            }

            if (!preg_match('/font-display\s*:\s*([^;}]+)/i', $block, $match)) {
                return true;
            }

            $value = strtolower(trim((string) ($match[1] ?? '')));
            return in_array($value, array('auto', 'block'), true);
        }

        private function rewrite_self_hosted_font_css_content($css, $source_url, array &$google_import_stats = null)
        {
            $css = $this->normalize_protocol_relative_urls_in_css($css, $source_url);
            $css = $this->rewrite_google_fonts_imports_in_css($css, $source_url, $google_import_stats);
            return $this->normalize_font_face_display_in_css($css);
        }

        private function rewrite_google_fonts_imports_in_css($css, $source_url, array &$stats = null)
        {
            $css = (string) $css;
            if ('' === $css || false === stripos($css, 'fonts.googleapis.com')) {
                return $css;
            }

            if (null === $stats) {
                $stats = array();
            }

            foreach (array('found', 'localized', 'displaySwapOnly', 'unchanged', 'remainingRemote') as $key) {
                if (!isset($stats[$key])) {
                    $stats[$key] = 0;
                }
            }

            $settings = $this->get_settings();
            $localize = !empty($settings['google_fonts_local_optimization']);
            $swap = !empty($settings['google_fonts_swap']) || $localize;

            $updated = $this->safe_google_fonts_preg_replace_callback('/@import\s+(?:url\(\s*"([^"]+)"\s*\)|url\(\s*\'([^\']+)\'\s*\)|url\(\s*([^)]+?)\s*\)|"([^"]+)"|\'([^\']+)\')([^;]*);/i', function ($matches) use ($source_url, $localize, $swap, &$stats) {
                $import_url = '';
                for ($i = 1; $i <= 5; $i++) {
                    if (isset($matches[$i]) && '' !== trim((string) $matches[$i])) {
                        $import_url = trim((string) $matches[$i]);
                        break;
                    }
                }

                if ('' === $import_url) {
                    return (string) $matches[0];
                }

                $absolute = $this->absolutize_public_resource_url($this->decode_google_fonts_html_url($import_url), $source_url);
                if ('' === $absolute || !$this->is_google_fonts_stylesheet_url($absolute)) {
                    return (string) $matches[0];
                }

                $stats['found']++;
                $suffix = isset($matches[6]) ? trim((string) $matches[6]) : '';
                $rewritten_url = $swap ? $this->append_google_fonts_display_swap($absolute) : $absolute;
                $localized_url = '';

                if ($localize) {
                    $localized_url = $this->get_google_fonts_url_for_current_request($rewritten_url, true);
                    if (is_string($localized_url) && '' !== $localized_url) {
                        $rewritten_url = $localized_url;
                        $stats['localized']++;
                    } else {
                        $stats['remainingRemote']++;
                    }
                } elseif ($rewritten_url !== $absolute) {
                    $stats['displaySwapOnly']++;
                }

                if ($rewritten_url === $import_url) {
                    $stats['unchanged']++;
                    return (string) $matches[0];
                }

                return '@import url("' . esc_url_raw($rewritten_url) . '")' . ('' !== $suffix ? ' ' . $suffix : '') . ';';
            }, $css);

            return is_string($updated) && '' !== $updated ? $updated : $css;
        }

        private function normalize_font_face_display_in_css($css, &$stats = null)
        {
            $css = (string) $css;
            if (null === $stats) {
                $stats = array();
            }
            foreach (array('fontFaceBlocksScanned', 'fontDisplayAdded', 'fontDisplayExisting', 'fontFaceBlocksChanged') as $key) {
                if (!isset($stats[$key])) {
                    $stats[$key] = 0;
                }
            }

            if ('' === $css || false === stripos($css, '@font-face')) {
                return $css;
            }

            return (string) preg_replace_callback(
                '/@font-face\s*{.*?}/is',
                function ($matches) use (&$stats) {
                    $block = (string) $matches[0];
                    $stats['fontFaceBlocksScanned']++;
                    $block = (string) preg_replace('/([^;{}\s])\s+(font-display\s*:)/i', '$1; $2', $block);

                    if (preg_match('/font-display\s*:\s*([^;}]+);?/i', $block, $display_match)) {
                        $value = strtolower(trim((string) ($display_match[1] ?? '')));
                        $stats['fontDisplayExisting']++;
                        if (in_array($value, array('auto', 'block'), true)) {
                            $updated_block = (string) preg_replace('/font-display\s*:\s*[^;}]+;?/i', 'font-display: swap;', $block, 1);
                            if ($updated_block !== $block) {
                                $stats['fontFaceBlocksChanged']++;
                                return $updated_block;
                            }
                        }

                        return $block;
                    }

                    $body = (string) preg_replace('/}\s*$/', '', $block, 1);
                    $body = rtrim($body);
                    if ('' !== $body && '{' !== substr($body, -1) && ';' !== substr($body, -1)) {
                        $body .= ';';
                    }

                    $stats['fontDisplayAdded']++;
                    $stats['fontFaceBlocksChanged']++;
                    return $body . "\n  font-display: swap;\n}";
                },
                $css
            );
        }

        private function normalize_protocol_relative_urls_in_css($css, $source_url)
        {
            return (string) preg_replace_callback(
                '/url\(([^)]+)\)/i',
                function ($matches) use ($source_url) {
                    $raw = trim((string) $matches[1]);
                    $quote = '';
                    if ('' !== $raw && ('"' === $raw[0] || "'" === $raw[0])) {
                        $quote = $raw[0];
                        $raw = trim($raw, "\"'");
                    }

                    $normalized = $this->absolutize_public_resource_url($raw, $source_url);
                    if ('' === $normalized) {
                        $normalized = $raw;
                    }

                    return 'url(' . $quote . $normalized . $quote . ')';
                },
                $css
            );
        }

        private function extract_preloadable_font_urls_from_css($css, $limit = 2)
        {
            $urls = array();
            if (preg_match_all('/url\(([^)]+\.woff2(?:\?[^)]*)?)\)/i', (string) $css, $matches)) {
                foreach ($matches[1] as $raw) {
                    $raw = trim((string) $raw, " \t\n\r\0\x0B\"'");
                    $raw = esc_url_raw($raw);
                    if ('' === $raw || in_array($raw, $urls, true)) {
                        continue;
                    }

                    $urls[] = $raw;
                    if (count($urls) >= max(1, (int) $limit)) {
                        break;
                    }
                }
            }

            return $urls;
        }

        private function inject_font_preload_links($html, array $urls)
        {
            if (false === stripos($html, '</head>')) {
                return $html;
            }

            $links = array();
            foreach ($urls as $url) {
                $url = esc_url($url);
                if ('' === $url) {
                    continue;
                }

                $link = '<link rel="preload" as="font" type="font/woff2" href="' . $url . '" crossorigin />';
                if (false === strpos($html, $link)) {
                    $links[] = $link;
                }
            }

            if (empty($links)) {
                return $html;
            }

            return $this->insert_html_before_closing_head($html, implode("
", $links));
        }

        private function inject_delayed_font_css_links($html, array $assets, $id_prefix = 'ucwp-delayed-font-css')
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '</head>') || empty($assets)) {
                return $html;
            }

            $markup = array();
            $seen = array();
            $index = 0;
            foreach ($assets as $asset) {
                if (!is_array($asset) || empty($asset['delayedFontUrl'])) {
                    continue;
                }

                $url = esc_url_raw((string) $asset['delayedFontUrl']);
                if ('' === $url || isset($seen[$url]) || $this->html_link_href_exists($html, $url)) {
                    continue;
                }

                $delayed_file = !empty($asset['delayedFontFile']) ? (string) $asset['delayedFontFile'] : $this->resolve_local_path_from_public_url($url);
                clearstatcache(true, $delayed_file);
                if ('' === $delayed_file || !is_readable($delayed_file) || filesize($delayed_file) <= 0) {
                    continue;
                }

                $seen[$url] = true;
                $entry = $asset;
                $entry['delayedFontUrl'] = $url;
                $id = (string) $id_prefix;
                if ($index > 0) {
                    $id .= '-' . (string) ($index + 1);
                }
                $link = $this->build_delayed_icon_fonts_stylesheet_markup($entry, $id);
                if ('' !== trim($link)) {
                    $markup[] = $link;
                    $index++;
                }
            }

            if (empty($markup)) {
                return $html;
            }

            return $this->insert_html_before_closing_head($html, implode("
", $markup));
        }

    }
}
