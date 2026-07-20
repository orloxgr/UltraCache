<?php
/**
 * Font cache storage, generated assets, locks, and persistence helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Font_Storage_Trait
{

    private function is_google_fonts_cache_dir_writable($dir = '')
    {
        $dir = '' === (string) $dir ? $this->get_google_fonts_cache_dir() : (string) $dir;
        if ('' === $dir) {
            return false;
        }

        $dir = trailingslashit($dir);
        if (!is_dir($dir)) {
            ultracache_safe_mkdir($dir, 0755, true, 'google-fonts-cache-dir');
        }

        if (!is_dir($dir) || !ultracache_path_is_writable($dir)) {
            return false;
        }

        $index_file = $dir . 'index.php';
        if (!file_exists($index_file)) {
            ultracache_safe_file_put_contents($index_file, "<?php\n// Silence is golden.\n", 0, 'google-fonts-index');
        }

        return true;
    }


    private function get_google_fonts_cache_write_failure_key($hash = '')
    {
        $hash = preg_replace('/[^a-z0-9_\\-]/i', '', (string) $hash);
        if ('' === $hash) {
            $hash = 'global';
        }

        return 'ultracache_gf_write_fail_' . substr(strtolower($hash), 0, 64);
    }


    private function get_google_fonts_cache_write_failure_status($hash = '')
    {
        $stored = get_transient($this->get_google_fonts_cache_write_failure_key($hash));
        if (false === $stored) {
            return array();
        }

        if (!is_array($stored)) {
            return array(
                'active' => true,
                'retryIn' => 15,
                'message' => 'Recent Google Fonts cache write failure retry guard is active.',
            );
        }

        $retry_in = isset($stored['expiresAt']) ? max(0, (int) $stored['expiresAt'] - time()) : 15;
        return array(
            'active' => true,
            'retryIn' => min(15, max(0, $retry_in)),
            'message' => sanitize_text_field((string) ($stored['message'] ?? 'Recent Google Fonts cache write failure retry guard is active.')),
        );
    }


    private function should_skip_google_fonts_cache_write($hash = '')
    {
        $status = $this->get_google_fonts_cache_write_failure_status($hash);
        return !empty($status['active']);
    }


    private function mark_google_fonts_cache_write_failure($hash = '', $ttl = 15, $message = 'Recent Google Fonts cache write failure retry guard is active.')
    {
        $ttl = max(10, min(15, (int) $ttl));
        set_transient(
            $this->get_google_fonts_cache_write_failure_key($hash),
            array(
                'createdAt' => time(),
                'expiresAt' => time() + $ttl,
                'ttl' => $ttl,
                'message' => sanitize_text_field((string) $message),
            ),
            $ttl
        );
    }


    private function set_google_fonts_last_build_failure($stage, $message, $url = '')
    {
        $this->google_fonts_last_build_failure = array(
            'stage' => sanitize_key((string) $stage),
            'message' => substr(sanitize_text_field((string) $message), 0, 240),
            'url' => substr(esc_url_raw((string) $url), 0, 500),
        );
    }


    private function get_google_fonts_last_build_failure()
    {
        return is_array($this->google_fonts_last_build_failure) ? $this->google_fonts_last_build_failure : array();
    }


    private function clear_google_fonts_last_build_failure()
    {
        $this->google_fonts_last_build_failure = array();
    }


    private function should_build_google_fonts_synchronously()
    {
        if (!empty($this->google_fonts_sync_build_mode)) {
            return true;
        }

        if (defined('ULTRACACHE_GOOGLE_FONTS_SYNC') && ULTRACACHE_GOOGLE_FONTS_SYNC) {
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
                'message' => __('Local Google Fonts Optimization is disabled.', 'ultracache'),
                'scannedUrls' => 0,
                'fontUrls' => 0,
                'built' => 0,
                'failed' => 0,
            );
        }

        $lock_token = $this->acquire_google_fonts_lock('ultracache_gf_scan_rebuild_lock', 300);
        if ('' === $lock_token) {
            return array(
                'success' => false,
                'message' => __('Google Fonts rebuild is already running.', 'ultracache'),
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
            $failure_details = array();
            foreach (array_values($font_urls) as $font_url) {
                $this->clear_google_fonts_last_build_failure();
                $local = $this->maybe_get_local_google_fonts_stylesheet_url($font_url);
                if (is_string($local) && '' !== $local) {
                    $built++;
                } else {
                    $failed++;
                    $failure = $this->get_google_fonts_last_build_failure();
                    if (empty($failure)) {
                        $failure = array('stage' => 'unknown', 'message' => 'Local Google Fonts stylesheet was not generated.', 'url' => (string) $font_url);
                    }
                    $failure_details[] = $failure;
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
                'failureDetails' => $failure_details,
                'cleared' => !empty($clear_cache),
                'finishedAt' => time(),
                'finishedAtUtc' => gmdate('c'),
            );

            $this->store_google_fonts_last_scan_result($result);

            return $result;
        } finally {
            $this->google_fonts_sync_build_mode = $previous_sync;
            $this->google_fonts_async_pending = $previous_pending;
            $this->release_google_fonts_lock('ultracache_gf_scan_rebuild_lock', $lock_token);
        }
    }


    private function get_google_fonts_last_scan_option_key()
    {
        return (defined('ULTRACACHE_SETTINGS_KEY') ? ULTRACACHE_SETTINGS_KEY : 'ultracache_settings') . '_google_fonts_last_scan';
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
            'failureDetails' => array(),
        );

        if (!empty($result['failureDetails']) && is_array($result['failureDetails'])) {
            foreach (array_slice($result['failureDetails'], 0, 5) as $failure) {
                if (!is_array($failure)) {
                    continue;
                }
                $stored['failureDetails'][] = array(
                    'stage' => sanitize_key((string) ($failure['stage'] ?? 'unknown')),
                    'message' => substr(sanitize_text_field((string) ($failure['message'] ?? '')), 0, 240),
                    'url' => substr(esc_url_raw((string) ($failure['url'] ?? '')), 0, 500),
                );
            }
        }

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
            'failureDetails' => isset($stored['failureDetails']) && is_array($stored['failureDetails']) ? array_slice($stored['failureDetails'], 0, 5) : array(),
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
            $items = ultracache_safe_scandir($dir, 'google_fonts_cache_summary');
            if (is_array($items)) {
                foreach ($items as $item) {
                    if ('.' === $item || '..' === $item || 'index.php' === $item) {
                        continue;
                    }
                    $path = $dir . $item;
                    if (!is_file($path)) {
                        continue;
                    }
                    $size = ultracache_safe_filesize($path, 'google_fonts_cache_summary');
                    $mtime = ultracache_safe_filemtime($path, 'google_fonts_cache_summary');

                    $summary['totalFiles']++;
                    if (is_int($size)) {
                        $summary['bytes'] += $size;
                    }
                    if (is_int($mtime)) {
                        $summary['lastBuilt'] = max((int) $summary['lastBuilt'], $mtime);
                    }
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
            if (!empty($last_scan['failureDetails']) && is_array($last_scan['failureDetails'])) {
                $first_failure = reset($last_scan['failureDetails']);
                if (is_array($first_failure) && !empty($first_failure['message'])) {
                    $summary['message'] .= ' First failure: ' . (string) $first_failure['message'];
                }
                $summary['failureDetails'] = array_slice($last_scan['failureDetails'], 0, 5);
            }
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

        $items = ultracache_safe_scandir($dir, 'google_fonts_cache_clear scandir');
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
                ultracache_safe_unlink($path);
            }
        }

        $this->is_google_fonts_cache_dir_writable($dir);
    }


    private function should_defer_google_fonts_build_on_current_request()
    {
        if (defined('ULTRACACHE_GOOGLE_FONTS_SYNC') && ULTRACACHE_GOOGLE_FONTS_SYNC) {
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
            $this->set_google_fonts_last_build_failure('invalid-url', 'The URL is not a supported Google Fonts stylesheet URL.', $normalized_url);
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
        $write_guard = $this->get_google_fonts_cache_write_failure_status($hash);
        if (!empty($write_guard['active'])) {
            $retry_in = isset($write_guard['retryIn']) ? max(0, (int) $write_guard['retryIn']) : 15;
            $this->set_google_fonts_last_build_failure('write-backoff', 'Recent Google Fonts cache write failure retry guard is active. Retry in about ' . (string) $retry_in . ' second(s).', $normalized_url);
            return '';
        }

        $dir = $this->get_google_fonts_cache_dir();
        if (!$this->is_google_fonts_cache_dir_writable($dir)) {
            $this->mark_google_fonts_cache_write_failure($hash, 15, 'Google Fonts cache directory is not writable.');
            $this->set_google_fonts_last_build_failure('storage', 'Google Fonts cache directory is not writable.', $normalized_url);
            return '';
        }

        $css_file = $dir . $hash . '.css';
        $css_url = $this->get_google_fonts_cache_url_base() . $hash . '.css';

        if (is_readable($css_file) && filesize($css_file) > 0) {
            $this->normalize_google_fonts_cache_css_file($css_file);
            return $css_url;
        }

        if (!$this->should_build_google_fonts_synchronously()) {
            $this->set_google_fonts_last_build_failure('deferred', 'Synchronous Google Fonts build is disabled for this request.', $normalized_url);
            return '';
        }

        $lock_key = $this->get_google_fonts_lock_key('css', $hash);
        $lock_token = $this->acquire_google_fonts_lock($lock_key, 120);
        if ('' === $lock_token) {
            $this->set_google_fonts_last_build_failure('lock', 'Could not acquire Google Fonts stylesheet build lock.', $normalized_url);
            return '';
        }

        try {
            if (is_readable($css_file) && filesize($css_file) > 0) {
                $this->normalize_google_fonts_cache_css_file($css_file);
                return $css_url;
            }

            $response = ultracache_safe_remote_request(
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
                $this->set_google_fonts_last_build_failure('fetch', $response->get_error_message(), $normalized_url);
                return '';
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $css = (string) wp_remote_retrieve_body($response);
            if (200 !== $code || '' === trim($css)) {
                $this->set_google_fonts_last_build_failure('fetch', 'Google Fonts stylesheet fetch returned HTTP ' . (string) $code . ' or empty CSS.', $normalized_url);
                return '';
            }

            $localized_css = $this->build_local_google_fonts_css($css, $normalized_url, $hash);
            if (function_exists('ultracache_strip_source_mapping_url_comments')) {
                $localized_css = ultracache_strip_source_mapping_url_comments($localized_css);
            }
            if ('' === trim($localized_css)) {
                $this->set_google_fonts_last_build_failure('parse', 'Google Fonts stylesheet localized to empty CSS.', $normalized_url);
                return '';
            }

            if (!$this->is_google_fonts_cache_dir_writable($dir)) {
                $this->mark_google_fonts_cache_write_failure($hash, 15, 'Google Fonts cache directory became unavailable before stylesheet write.');
                $this->set_google_fonts_last_build_failure('storage', 'Google Fonts cache directory became unavailable before stylesheet write.', $normalized_url);
                return '';
            }

            if (false === ultracache_safe_file_put_contents($css_file, $localized_css, 0, 'google-fonts-css')) {
                $this->mark_google_fonts_cache_write_failure($hash, 15, 'Could not write localized Google Fonts stylesheet.');
                $this->set_google_fonts_last_build_failure('write', 'Could not write localized Google Fonts stylesheet to uploads/ultracache/google-fonts/.', $normalized_url);
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

            $response = ultracache_safe_remote_request(
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

            if (false === ultracache_safe_file_put_contents($file_path, $body, 0, 'google-font-binary')) {
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

        return 'ultracache_gf_' . strtolower($type) . '_lock_' . substr(strtolower($hash), 0, 64);
    }


    private function get_google_fonts_db_lock_name($key)
    {
        $key = (string) $key;
        if ('' === $key) {
            return '';
        }

        return 'ultracache_google_fonts_lock_' . md5($key);
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
            return wp_cache_add($key, $token, 'ultracache_google_fonts_locks', $ttl) ? ('cache:' . $token) : '';
        }

        $lock_name = $this->get_google_fonts_db_lock_name($key);
        if (
            '' === $lock_name
            || !function_exists('ultracache_acquire_lock')
            || !ultracache_acquire_lock(
                $lock_name,
                $token,
                $ttl,
                array('keyHash' => md5($key))
            )
        ) {
            return '';
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
            if (function_exists('wp_cache_get') && (string) wp_cache_get($key, 'ultracache_google_fonts_locks') === (string) $raw_token && function_exists('wp_cache_delete')) {
                wp_cache_delete($key, 'ultracache_google_fonts_locks');
            }
            return;
        }

        $lock_name = $this->get_google_fonts_db_lock_name($key);
        if ('' === $lock_name || !function_exists('ultracache_release_lock')) {
            return;
        }

        $raw_token = (0 === strpos($token, 'db:')) ? substr($token, 3) : $token;
        ultracache_release_lock($lock_name, $raw_token);
    }


    private function get_google_fonts_cache_dir()
    {
        return ultracache_generated_asset_dir('google-fonts');
    }


    private function get_google_fonts_cache_url_base()
    {
        return $this->get_google_fonts_cache_root_relative_url_base();
    }


    private function get_google_fonts_cache_root_relative_url_base()
    {
        $path = ultracache_generated_asset_public_path('google-fonts');
        if ('' === $path) {
            return '';
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
        if ('' === $css || false === stripos($css, 'uploads/ultracache/google-fonts')) {
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

        $css = ultracache_guarded_asset_file_get_contents($css_file, 'font-css', 'google-fonts-css-normalize', true);
        if (!is_string($css) || '' === $css || false === stripos($css, 'uploads/ultracache/google-fonts')) {
            return false;
        }

        $normalized = $this->normalize_google_fonts_cache_urls_in_css($css);
        $font_display_stats = array();
        $normalized = $this->normalize_font_face_display_in_css($normalized, $font_display_stats);
        if (function_exists('ultracache_strip_source_mapping_url_comments')) {
            $normalized = ultracache_strip_source_mapping_url_comments($normalized);
        }
        if (!is_string($normalized) || $normalized === $css) {
            return false;
        }

        return false !== ultracache_safe_file_put_contents($css_file, $normalized, 0, 'google-fonts-css-normalize');
    }


    private function get_google_fonts_remote_user_agent()
    {
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
    }


    private function get_font_display_normalized_css_asset_for_current_request($url)
    {
        static $request_assets = array();

        $source_url = $this->normalize_public_resource_url($url);
        if ('' !== $source_url && !preg_match('#^https?://#i', $source_url)) {
            $absolute_source_url = $this->absolutize_public_resource_url($source_url, home_url('/'));
            if (is_string($absolute_source_url) && '' !== $absolute_source_url) {
                $source_url = $this->normalize_public_resource_url($absolute_source_url);
            }
        }
        if ('' === $source_url) {
            return array();
        }

        if (array_key_exists($source_url, $request_assets)) {
            return is_array($request_assets[$source_url]) ? $request_assets[$source_url] : array();
        }

        $normalized_path = strtolower((string) wp_parse_url($source_url, PHP_URL_PATH));
        if (ultracache_generated_asset_reference_matches($normalized_path, array('css-bundles', 'font-css', 'optimized-css'))) {
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
        if (ultracache_internal_cache_local_path_matches($source_path_lc) || ultracache_generated_asset_local_path_matches($source_path_lc)) {
            $request_assets[$source_url] = array();
            return array();
        }

        $css = ultracache_guarded_asset_file_get_contents($source_path, 'font-css', 'font_display_normalized_css_asset', true);
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

        if (function_exists('ultracache_strip_source_mapping_url_comments')) {
            $normalized_css = trim(ultracache_strip_source_mapping_url_comments($normalized_css)) . "\n";
        }

        $dir = ultracache_generated_asset_dir('optimized-css');
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $index_file = $dir . 'index.php';
        if (!file_exists($index_file)) {
            ultracache_safe_file_put_contents($index_file, "<?php\n// Silence is golden.\n");
        }

        $hash = md5($source_url . '|font-display|' . (string) ultracache_safe_filemtime($source_path, 'font_display_normalized_signature') . '|' . md5($normalized_css));
        $filename = 'css-font-mix-' . $hash . '.css';
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
            'css_url' => ultracache_generated_asset_url('optimized-css', $filename),
            'file' => $file,
            'sourceUrl' => $source_url,
            'sourceBytes' => strlen($css),
            'cssBytes' => strlen($normalized_css),
            'fontDisplayAdded' => max(0, (int) ($stats['fontDisplayAdded'] ?? 0)),
            'fontFaceBlocksScanned' => max(0, (int) ($stats['fontFaceBlocksScanned'] ?? 0)),
        );

        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'record_css_rewrite_map')) {
            Ultra_Cache_WP::record_css_rewrite_map($source_url, (string) $asset['css_url'], array(
                'source_path'       => $source_path,
                'generated_path'    => $file,
                'optimization_type' => 'css-font-mix',
                'content_hash'      => $content_hash,
            ));
        }

        $request_assets[$source_url] = $asset;
        return $asset;
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
        if (ultracache_generated_asset_reference_matches($normalized_path, array('css-bundles', 'font-css', 'optimized-css'))) {
            $request_assets[$source_url] = array();
            return array();
        }

        $settings = $this->get_settings();
        $policy = $this->get_font_optimization_policy($settings);
        $map = $this->get_runtime_local_font_css_url_map();
        $mapped_css_url = '';
        if (is_array($map) && !empty($map[$source_url])) {
            $mapped_css_url = esc_url_raw((string) $map[$source_url]);
            if ('' !== $mapped_css_url && $mapped_css_url !== $source_url && empty($policy['delay_icon_fonts']) && !empty($policy['local_font_css_rewrite'])) {
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

        if ('' !== $mapped_css_url && $mapped_css_url !== $source_url && !empty($policy['local_font_css_rewrite'])) {
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
        if (ultracache_generated_asset_local_path_matches($source_path_lc, array('css-bundles', 'font-css', 'optimized-css'))) {
            return array();
        }

        $css = ultracache_guarded_asset_file_get_contents($source_path, 'font-css', 'build_optimized_font_css_asset', true);
        if (!is_string($css) || '' === $css) {
            return array();
        }

        $settings = $this->get_settings();
        $policy = $this->get_font_optimization_policy($settings);
        $has_font_faces = false !== stripos($css, '@font-face');
        $has_google_imports = false !== stripos($css, 'fonts.googleapis.com');
        if (!$has_font_faces && !$has_google_imports) {
            return array();
        }
        if (empty($policy['local_font_css_rewrite']) && (empty($policy['google_fonts_local']) || !$has_google_imports)) {
            return array();
        }

        $has_missing_font_display = !empty($policy['local_font_css_rewrite']) && $this->css_has_font_face_requiring_display_normalization($css);
        $google_import_stats = array();
        $optimized_css = $this->rewrite_self_hosted_font_css_content($css, $source_url, $google_import_stats, !empty($policy['local_font_css_rewrite']));
        $google_imports_localized = !empty($google_import_stats['localized']);
        $google_imports_changed = $optimized_css !== $css && !empty($google_import_stats['found']);
        $delayed_font_css = '';
        $delayed_font_families = array();
        $delayed_font_patterns = array();
        $delayed_font_count = 0;
        $preserve_mixed_css_for_delayed_icon_fonts = false;
        $preserve_mixed_css_for_font_display = false;

        if (!empty($policy['delay_icon_fonts'])) {
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
        if (!empty($policy['self_hosted_css']) && !$preserve_mixed_css_for_delayed_icon_fonts && empty($google_imports_changed) && function_exists('ultracache_optimize_generated_font_css')) {
            $font_css_optimization = ultracache_optimize_generated_font_css($optimized_css, $source_url);
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

        if (function_exists('ultracache_strip_source_mapping_url_comments')) {
            $optimized_css = trim(ultracache_strip_source_mapping_url_comments($optimized_css));
        }

        $hash = md5($source_url . '|' . md5($optimized_css));
        $active_css_is_mixed = !empty($preserve_mixed_css_for_delayed_icon_fonts) || !empty($preserve_mixed_css_for_google_imports) || !empty($preserve_mixed_css_for_font_display);
        $asset_dir_slug = $active_css_is_mixed ? 'optimized-css' : 'font-css';
        $asset_file_prefix = $active_css_is_mixed ? 'css-font-mix-' : '';
        $write_reason = !empty($preserve_mixed_css_for_google_imports) ? 'optimized active css with localized Google Fonts imports' : (!empty($preserve_mixed_css_for_font_display) ? 'optimized active css with font-display patches' : ($active_css_is_mixed ? 'optimized active css without delayed icon font-face blocks' : 'optimized font css'));

        /*
         * 2.56.198: /font-css/ is reserved for actual font-only or delayed
         * font-face CSS. When Delay icon font-face blocks preserves a mixed
         * theme/plugin stylesheet after extracting icon @font-face blocks, keep
         * the active non-font CSS under /optimized-css/ instead. Otherwise tools
         * such as Lighthouse correctly flag a large render stylesheet, but show
         * it misleadingly as font-css.
         */
        $dir = ultracache_generated_asset_dir($asset_dir_slug);
        if ('' === $dir) {
            return array();
        }
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $index_file = $dir . 'index.php';
        if (!file_exists($index_file)) {
            ultracache_safe_file_put_contents($index_file, "<?php
// Silence is golden.
");
        }

        $filename = $asset_file_prefix . $hash . '.css';
        $file = $dir . $filename;
        if (!file_exists($file) || md5_file($file) !== md5($optimized_css)) {
            ultracache_safe_file_put_contents($file, $optimized_css, LOCK_EX, $write_reason);
        }
        clearstatcache(true, $file);
        if (!is_readable($file) || filesize($file) <= 0) {
            return array();
        }

        $asset = array(
            'css_url'      => ultracache_generated_asset_url($asset_dir_slug, $filename),
            'file'         => $file,
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
            if (function_exists('ultracache_strip_source_mapping_url_comments')) {
                $delayed_font_content = trim(ultracache_strip_source_mapping_url_comments($delayed_font_content)) . "
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
                $delayed_dir = ultracache_generated_asset_dir('font-css');
                if (!is_dir($delayed_dir)) {
                    wp_mkdir_p($delayed_dir);
                }
                $delayed_index_file = $delayed_dir . 'index.php';
                if (!file_exists($delayed_index_file)) {
                    ultracache_safe_file_put_contents($delayed_index_file, "<?php
// Silence is golden.
");
                }
                $delayed_font_hash = md5($source_url . '|delayed|' . md5($delayed_font_content));
                $delayed_font_filename = 'delayed-' . $delayed_font_hash . '.css';
                $delayed_font_file = $delayed_dir . $delayed_font_filename;
                if (!file_exists($delayed_font_file) || md5_file($delayed_font_file) !== md5($delayed_font_content)) {
                    ultracache_safe_file_put_contents($delayed_font_file, $delayed_font_content, LOCK_EX, 'delayed optimized font css');
                }
                clearstatcache(true, $delayed_font_file);
                if (!is_readable($delayed_font_file) || filesize($delayed_font_file) <= 0) {
                    return array();
                }
                $delayed_font_url = ultracache_generated_asset_url('font-css', $delayed_font_filename);
                $asset['delayedFontUrl'] = $delayed_font_url;
                $asset['delayed_font_url'] = $delayed_font_url;
                $asset['delayedFontFile'] = $delayed_font_file;
                $asset['delayedFontFaceBlocks'] = $delayed_font_count;
                $asset['delayedFontFamilies'] = $delayed_font_families;
                $asset['delayedFontPatterns'] = $delayed_font_patterns;
                $this->delayed_font_css_assets_current_request[$source_url] = $asset;
            }
        }

        if ($active_css_is_mixed && class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'record_css_rewrite_map')) {
            Ultra_Cache_WP::record_css_rewrite_map($source_url, (string) $asset['css_url'], array(
                'source_path'       => $source_path,
                'generated_path'    => $file,
                'optimization_type' => 'css-font-mix',
                'content_hash'      => md5((string) $optimized_css),
            ));
        }

        return $asset;
    }


    private function get_runtime_font_css_map_cache_key()
    {
        return 'ultracache_runtime_font_css_url_map_v3';
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
            $normalized = $this->normalize_runtime_font_css_url_map($cached);
            $filtered = $this->filter_runtime_font_css_url_map_to_existing_targets($normalized);
            if ($filtered !== $normalized) {
                set_transient($this->get_runtime_font_css_map_cache_key(), $filtered, DAY_IN_SECONDS);
            }
            $this->runtime_font_css_url_map = $filtered;
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


    private function get_runtime_font_css_target_asset_type($css_url)
    {
        $css_url = $this->normalize_public_resource_url((string) $css_url);
        if ('' === $css_url) {
            return '';
        }

        $path = strtolower((string) wp_parse_url($css_url, PHP_URL_PATH));
        if (ultracache_generated_asset_reference_matches($path, array('font-css'))) {
            return 'font-css';
        }
        if (ultracache_generated_asset_reference_matches($path, array('optimized-css'))) {
            return 'optimized-css';
        }

        return '';
    }


    private function runtime_font_css_target_file_exists($css_url)
    {
        $css_url = $this->normalize_public_resource_url((string) $css_url);
        $asset_type = $this->get_runtime_font_css_target_asset_type($css_url);
        if ('' === $css_url || '' === $asset_type) {
            return false;
        }

        $path = $this->resolve_local_path_from_public_url($css_url);
        if ('' === $path) {
            return false;
        }

        $contents = ultracache_guarded_asset_file_get_contents($path, $asset_type, 'runtime_font_css_map_target_validate', true);
        return is_string($contents) && '' !== trim($contents);
    }


    private function filter_runtime_font_css_url_map_to_existing_targets(array $map)
    {
        $filtered = array();
        foreach ($this->normalize_runtime_font_css_url_map($map) as $source_url => $css_url) {
            if ($this->runtime_font_css_target_file_exists($css_url)) {
                $filtered[$source_url] = $css_url;
                continue;
            }

            ultracache_debug_log('runtime font CSS map stale target removed', array(
                'source_url' => $source_url,
                'css_url'    => $css_url,
            ));
        }

        return $filtered;
    }


    private function save_runtime_local_font_css_url_map(array $map)
    {
        $map = $this->filter_runtime_font_css_url_map_to_existing_targets($map);
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
        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'record_css_rewrite_map')) {
            $generated_path = $this->resolve_local_path_from_public_url($css_url);
            $source_path = $this->resolve_local_path_from_public_url($source_url);
            $generated_path_lc = strtolower(str_replace('\\', '/', (string) $generated_path));
            if (ultracache_generated_asset_local_path_matches($generated_path_lc, array('optimized-css'))) {
                Ultra_Cache_WP::record_css_rewrite_map($source_url, $css_url, array(
                    'source_path'       => $source_path,
                    'generated_path'    => $generated_path,
                    'optimization_type' => 'css-font-mix',
                    'content_hash'      => (is_readable($generated_path) ? md5_file($generated_path) : ''),
                ));
            }
        }

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
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'record_css_rewrite_map')) {
                $generated_path = $this->resolve_local_path_from_public_url($css_url);
                $source_path = $this->resolve_local_path_from_public_url($source_url);
                $generated_path_lc = strtolower(str_replace('\\', '/', (string) $generated_path));
                if (ultracache_generated_asset_local_path_matches($generated_path_lc, array('optimized-css'))) {
                    Ultra_Cache_WP::record_css_rewrite_map($source_url, $css_url, array(
                        'source_path'       => $source_path,
                        'generated_path'    => $generated_path,
                        'optimization_type' => 'css-font-mix',
                        'content_hash'      => (is_readable($generated_path) ? md5_file($generated_path) : ''),
                    ));
                }
            }
            $merged[$source_url] = $css_url;
        }

        $this->save_runtime_local_font_css_url_map($merged);
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
            if (ultracache_internal_cache_local_path_matches($source_path_lc) || ultracache_generated_asset_local_path_matches($source_path_lc)) {
                continue;
            }

            $css = ultracache_guarded_asset_file_get_contents($source_path, 'font-css', 'build_local_font_display_patch_asset', true);
            if (!is_string($css) || '' === $css || false === stripos($css, '@font-face')) {
                continue;
            }

            if (!function_exists('ultracache_extract_font_face_blocks_from_css')) {
                continue;
            }

            $extracted = ultracache_extract_font_face_blocks_from_css($css);
            $blocks = isset($extracted['blocks']) && is_array($extracted['blocks']) ? $extracted['blocks'] : array();
            if (empty($blocks)) {
                continue;
            }

            $stats['sourceStylesheets']++;
            $signature_parts[] = $source_url . '|' . (string) ultracache_safe_filemtime($source_path, 'font_display_patch_signature') . '|' . strlen($css);

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
                if (function_exists('ultracache_optimize_font_face_block')) {
                    $block = ultracache_optimize_font_face_block($block, $patch_stats);
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
        if (function_exists('ultracache_css_minify_preserve_strings')) {
            $minified = ultracache_css_minify_preserve_strings($content);
            if ('' !== $minified) {
                $content = $minified;
            }
        }
        $content = trim($content);
        if (function_exists('ultracache_strip_source_mapping_url_comments')) {
            $content = trim(ultracache_strip_source_mapping_url_comments($content));
        }
        if ('' === $content) {
            return array();
        }
        $content .= "\n";

        $dir = ultracache_generated_asset_dir('font-css');
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $index_file = $dir . 'index.php';
        if (!file_exists($index_file)) {
            ultracache_safe_file_put_contents($index_file, "<?php\n// Silence is golden.\n");
        }

        $hash = md5(implode('||', $signature_parts) . '|' . md5($content));
        $filename = 'font-display-' . $hash . '.css';
        $file = $dir . $filename;
        if (!file_exists($file) || md5_file($file) !== md5($content)) {
            ultracache_safe_file_put_contents($file, $content, LOCK_EX, 'font display patch css');
        }

        $stats['bytes'] = strlen($content);
        $stats['file'] = $file;
        return array(
            'css_url' => ultracache_generated_asset_url('font-css', $filename),
            'file' => $file,
            'stats' => $stats,
        );
    }
}
