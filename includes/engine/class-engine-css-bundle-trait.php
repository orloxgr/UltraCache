<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!trait_exists('Ultra_Cache_Engine_CSS_Bundle_Trait')) {
    trait Ultra_Cache_Engine_CSS_Bundle_Trait
    {
        private function is_frontpage_css_scan_mode()
        {
            return '1' === sanitize_text_field(ucwp_query_value('ucwp_frontpage_css_scan'));
        }

        private function get_css_bundle_scope(array $settings = array())
        {
            $scope = isset($settings['css_bundle_scope']) ? strtolower(trim((string) $settings['css_bundle_scope'])) : 'homepage';
            return in_array($scope, array('homepage', 'shared', 'per-page'), true) ? $scope : 'homepage';
        }

        private function is_frontpage_request_url($url = '')
        {
            $current_url = '' !== (string) $url ? (string) $url : $this->get_current_request_url();
            $normalized_current = $this->normalize_url($current_url);
            $normalized_home = $this->normalize_url(home_url('/'));

            return '' !== $normalized_current && '' !== $normalized_home && $normalized_current === $normalized_home;
        }

        private function get_frontpage_css_dir()
        {
            return trailingslashit(UCWP_CACHE_DIR) . 'css-bundles/';
        }

        private function get_frontpage_css_manifest_file()
        {
            return $this->get_frontpage_css_dir() . 'manifest.json';
        }

        private function get_css_bundle_manifest_max_entries()
        {
            /**
             * Caps per-page CSS bundle manifest growth. The manifest is a runtime lookup file,
             * not a diagnostics archive; old entries are safe to rebuild on demand.
             */
            $max = (int) apply_filters('ucwp_css_bundle_manifest_max_entries', 500);
            return max(50, min(5000, $max));
        }

        private function get_css_bundle_manifest_tmp_cleanup_age_seconds()
        {
            $seconds = (int) apply_filters('ucwp_css_bundle_manifest_tmp_cleanup_age_seconds', 10 * MINUTE_IN_SECONDS);
            return max(60, min(DAY_IN_SECONDS, $seconds));
        }

        private function cleanup_css_manifest_tmp_files($age_seconds = null, $max_delete = 10)
        {
            $dir = $this->get_frontpage_css_dir();
            if (!is_dir($dir) || !is_readable($dir)) {
                return 0;
            }

            $age_seconds = null === $age_seconds ? $this->get_css_bundle_manifest_tmp_cleanup_age_seconds() : (int) $age_seconds;
            $age_seconds = max(60, $age_seconds);
            $max_delete = max(1, min(100, (int) $max_delete));
            $now = time();
            $deleted = 0;
            $files = (array) glob(trailingslashit($dir) . 'manifest.json.tmp-*');

            foreach ($files as $file) {
                $file = wp_normalize_path((string) $file);
                if ('' === $file || !is_file($file) || 'manifest.json.tmp-' !== substr(basename($file), 0, 18)) {
                    continue;
                }
                $mtime = @filemtime($file);
                if (!$mtime || ($now - (int) $mtime) < $age_seconds) {
                    continue;
                }
                if (ucwp_safe_unlink($file)) {
                    $deleted++;
                }
                if ($deleted >= $max_delete) {
                    break;
                }
            }

            if ($deleted > 0) {
                $this->record_cache_event('css-manifest-tmp-cleanup', array(
                    'deleted' => $deleted,
                    'age_seconds' => $age_seconds,
                ));
            }

            return $deleted;
        }

        private function normalize_frontpage_css_source_urls_for_manifest(array $source_urls)
        {
            $normalized = array();
            foreach ($source_urls as $source_url) {
                $url = trim((string) $source_url);
                if ('' === $url) {
                    continue;
                }
                $normalized[$url] = true;
            }
            return array_keys($normalized);
        }

        private function build_frontpage_css_manifest_entry($url, array $prepared)
        {
            $source_urls = $this->normalize_frontpage_css_source_urls_for_manifest((array) ($prepared['sourceUrls'] ?? array()));
            return array(
                'normalizedUrl' => $this->normalize_url((string) $url),
                'bundleFile' => (string) ($prepared['bundleFile'] ?? ''),
                'bundleUrl' => (string) ($prepared['bundleUrl'] ?? ''),
                'sourceUrls' => $source_urls,
                'sourceCount' => count($source_urls),
                'bundleCount' => 1,
                'mode' => (string) ($prepared['mode'] ?? 'safe'),
                'bundleSignature' => (string) ($prepared['bundleSignature'] ?? ''),
                'bundleContentHash' => (string) ($prepared['bundleContentHash'] ?? ''),
                'delayedFontFile' => (string) ($prepared['delayedFontFile'] ?? ''),
                'delayedFontUrl' => (string) ($prepared['delayedFontUrl'] ?? ''),
                'delayedFontBytes' => isset($prepared['delayedFontBytes']) ? (int) $prepared['delayedFontBytes'] : 0,
                'delayedFontFaceBlocks' => isset($prepared['delayedFontFaceBlocks']) ? (int) $prepared['delayedFontFaceBlocks'] : 0,
                'sourceBytesTotal' => isset($prepared['sourceBytesTotal']) ? (int) $prepared['sourceBytesTotal'] : 0,
                'time' => current_time('timestamp'),
                'time_mysql' => current_time('mysql'),
            );
        }

        private function compact_frontpage_css_manifest_entry(array $entry)
        {
            if (empty($entry)) {
                return array();
            }

            $source_urls = $this->normalize_frontpage_css_source_urls_for_manifest((array) ($entry['sourceUrls'] ?? array()));
            if (empty($source_urls) && !empty($entry['sourceDetails']) && is_array($entry['sourceDetails'])) {
                foreach ((array) $entry['sourceDetails'] as $detail) {
                    if (is_array($detail) && !empty($detail['url'])) {
                        $source_urls[] = (string) $detail['url'];
                    }
                }
                $source_urls = $this->normalize_frontpage_css_source_urls_for_manifest($source_urls);
            }

            $compact = array(
                'normalizedUrl' => isset($entry['normalizedUrl']) ? (string) $entry['normalizedUrl'] : '',
                'bundleFile' => isset($entry['bundleFile']) ? (string) $entry['bundleFile'] : '',
                'bundleUrl' => isset($entry['bundleUrl']) ? (string) $entry['bundleUrl'] : '',
                'sourceUrls' => $source_urls,
                'sourceCount' => isset($entry['sourceCount']) ? max(0, (int) $entry['sourceCount']) : count($source_urls),
                'bundleCount' => isset($entry['bundleCount']) ? max(0, (int) $entry['bundleCount']) : 1,
                'mode' => isset($entry['mode']) ? (string) $entry['mode'] : 'safe',
                'bundleSignature' => isset($entry['bundleSignature']) ? (string) $entry['bundleSignature'] : '',
                'bundleContentHash' => isset($entry['bundleContentHash']) ? (string) $entry['bundleContentHash'] : '',
                'delayedFontFile' => isset($entry['delayedFontFile']) ? (string) $entry['delayedFontFile'] : '',
                'delayedFontUrl' => isset($entry['delayedFontUrl']) ? (string) $entry['delayedFontUrl'] : '',
                'delayedFontBytes' => isset($entry['delayedFontBytes']) ? max(0, (int) $entry['delayedFontBytes']) : 0,
                'delayedFontFaceBlocks' => isset($entry['delayedFontFaceBlocks']) ? max(0, (int) $entry['delayedFontFaceBlocks']) : 0,
                'sourceBytesTotal' => isset($entry['sourceBytesTotal']) ? max(0, (int) $entry['sourceBytesTotal']) : 0,
                'time' => isset($entry['time']) ? max(0, (int) $entry['time']) : 0,
                'time_mysql' => isset($entry['time_mysql']) ? (string) $entry['time_mysql'] : '',
            );

            if ('' === $compact['normalizedUrl'] && !empty($entry['url'])) {
                $compact['normalizedUrl'] = $this->normalize_url((string) $entry['url']);
            }
            if ($compact['sourceCount'] <= 0) {
                $compact['sourceCount'] = count($source_urls);
            }

            return $compact;
        }

        private function compact_frontpage_css_manifest(array $manifest)
        {
            $manifest['version'] = 3;
            if (empty($manifest['entry']) || !is_array($manifest['entry'])) {
                $manifest['entry'] = array();
            } else {
                $manifest['entry'] = $this->compact_frontpage_css_manifest_entry($manifest['entry']);
            }
            if (empty($manifest['entries']) || !is_array($manifest['entries'])) {
                $manifest['entries'] = array();
            }

            $entries = array();
            foreach ((array) $manifest['entries'] as $key => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $compact = $this->compact_frontpage_css_manifest_entry($entry);
                if (empty($compact['bundleFile']) || empty($compact['bundleUrl']) || empty($compact['sourceUrls'])) {
                    continue;
                }
                $entries[(string) $key] = $compact;
            }

            if (count($entries) > $this->get_css_bundle_manifest_max_entries()) {
                uasort($entries, function ($a, $b) {
                    $at = isset($a['time']) ? (int) $a['time'] : 0;
                    $bt = isset($b['time']) ? (int) $b['time'] : 0;
                    if ($at === $bt) {
                        return 0;
                    }
                    return ($at < $bt) ? 1 : -1;
                });
                $entries = array_slice($entries, 0, $this->get_css_bundle_manifest_max_entries(), true);
            }

            $manifest['entries'] = $entries;
            $manifest['updatedAt'] = isset($manifest['updatedAt']) ? (int) $manifest['updatedAt'] : current_time('timestamp');
            $manifest['updatedAtMysql'] = isset($manifest['updatedAtMysql']) ? (string) $manifest['updatedAtMysql'] : current_time('mysql');

            return $manifest;
        }

        private function get_default_frontpage_css_stats()
        {
            return array(
                'scanned' => 0,
                'bundled' => 0,
                'skipped' => 0,
                'unresolved' => 0,
                'delayedFontFaceBlocks' => 0,
                'delayedFontFamilies' => array(),
                'delayedFontPatterns' => array(),
                'cssImageUrlsScanned' => 0,
                'cssImageUrlsRewritten' => 0,
                'cssImageUrlsImageSet' => 0,
                'cssImageUrlsSkipped' => 0,
                'fontDisplayAdded' => 0,
                'fontFaceBlocksScanned' => 0,
            );
        }

        private function read_frontpage_css_manifest()
        {
            $file = $this->get_frontpage_css_manifest_file();
            if (!file_exists($file) || !is_readable($file)) {
                return array(
                    'version' => 1,
                    'entry' => array(),
                );
            }

            $raw = ucwp_safe_file_get_contents($file);
            $decoded = is_string($raw) && '' !== $raw ? json_decode($raw, true) : array();
            if (!is_array($decoded)) {
                $decoded = array();
            }

            if (empty($decoded['version'])) {
                $decoded['version'] = 1;
            }
            if (empty($decoded['entry']) || !is_array($decoded['entry'])) {
                $decoded['entry'] = array();
            }
            if (empty($decoded['entries']) || !is_array($decoded['entries'])) {
                $decoded['entries'] = array();
            }

            return $this->compact_frontpage_css_manifest($decoded);
        }

        private function write_frontpage_css_manifest(array $manifest)
        {
            $dir = $this->get_frontpage_css_dir();
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }

            $this->cleanup_css_manifest_tmp_files(null, 5);
            $manifest = $this->compact_frontpage_css_manifest($manifest);
            $json = wp_json_encode($manifest);
            if (!is_string($json)) {
                return false;
            }

            $written = $this->write_cache_variant_atomically($this->get_frontpage_css_manifest_file(), $json);
            $this->cleanup_css_manifest_tmp_files(null, 5);
            return $written;
        }

        private function get_frontpage_css_manifest_bundle_files(array $manifest)
        {
            $files = array();
            foreach ((array) ($manifest['entries'] ?? array()) as $entry) {
                if (is_array($entry) && !empty($entry['bundleFile'])) {
                    $files[] = (string) $entry['bundleFile'];
                }
                if (is_array($entry) && !empty($entry['delayedFontFile'])) {
                    $files[] = (string) $entry['delayedFontFile'];
                }
            }

            if (!empty($manifest['entry']) && is_array($manifest['entry']) && !empty($manifest['entry']['bundleFile'])) {
                $files[] = (string) $manifest['entry']['bundleFile'];
            }
            if (!empty($manifest['entry']) && is_array($manifest['entry']) && !empty($manifest['entry']['delayedFontFile'])) {
                $files[] = (string) $manifest['entry']['delayedFontFile'];
            }

            $dir = wp_normalize_path($this->get_frontpage_css_dir());
            $active = array();
            foreach ($files as $file) {
                $file = wp_normalize_path((string) $file);
                if ('' === $file || 0 !== strpos($file, $dir)) {
                    continue;
                }
                $active[basename($file)] = true;
            }

            return $active;
        }

        private function get_css_bundle_cleanup_grace_seconds()
        {
            $settings = $this->get_settings();
            $default_seconds = 48 * HOUR_IN_SECONDS;
            $seconds = isset($settings['css_bundle_cleanup_grace_seconds'])
                ? (int) $settings['css_bundle_cleanup_grace_seconds']
                : $default_seconds;

            /**
             * Keep this filter as an advanced server-side override. The dashboard setting
             * supplies the default value, while the filter can still tighten or extend the
             * policy for managed hosting or custom deployments.
             */
            $seconds = (int) apply_filters('ucwp_css_bundle_cleanup_grace_seconds', $seconds);
            return max(HOUR_IN_SECONDS, min(WEEK_IN_SECONDS, $seconds));
        }

        private function get_css_bundle_cleanup_max_deletes_per_run()
        {
            $settings = $this->get_settings();
            $max = isset($settings['css_bundle_cleanup_delete_limit'])
                ? (int) $settings['css_bundle_cleanup_delete_limit']
                : 60;

            /**
             * Advanced server-side override. Dashboard value is the default; filter may
             * override it for hosts that need stricter filesystem cleanup limits.
             */
            $max = (int) apply_filters('ucwp_css_bundle_cleanup_max_deletes_per_run', $max);
            return max(5, min(500, $max));
        }

        private function is_css_bundle_file_recently_protected($file)
        {
            $file = (string) $file;
            if ('' === $file || !is_file($file)) {
                return false;
            }

            $mtime = (int) filemtime($file);
            if ($mtime <= 0) {
                return true;
            }

            return (time() - $mtime) < $this->get_css_bundle_cleanup_grace_seconds();
        }

        private function get_css_bundle_pair_basename($basename)
        {
            $basename = (string) $basename;
            if ('' === $basename) {
                return '';
            }

            return (string) preg_replace('/-delayed-fonts\.css$/i', '.css', $basename);
        }


        private function get_css_bundle_companion_basename($basename)
        {
            $basename = (string) $basename;
            if ('' === $basename || !preg_match('/^bundle-[A-Za-z0-9_.-]+\.css$/', $basename)) {
                return '';
            }

            if (preg_match('/-delayed-fonts\.css$/i', $basename)) {
                return $this->get_css_bundle_pair_basename($basename);
            }

            return (string) preg_replace('/\.css$/i', '-delayed-fonts.css', $basename);
        }

        private function get_css_bundle_ref_basenames_from_html($html)
        {
            $html = (string) $html;
            $refs = array();
            if ('' === $html || false === stripos($html, '/cache/ultracache/css-bundles/')) {
                return $refs;
            }

            preg_match_all('~(?:https?:)?//[^\s"\'<>]+/wp-content/cache/ultracache/css-bundles/[^\s"\'<>?#)]+\.css~i', $html, $absolute_matches);
            preg_match_all('~/wp-content/cache/ultracache/css-bundles/[^\s"\'<>?#)]+\.css~i', $html, $path_matches);

            $matches = array_merge(
                isset($absolute_matches[0]) && is_array($absolute_matches[0]) ? $absolute_matches[0] : array(),
                isset($path_matches[0]) && is_array($path_matches[0]) ? $path_matches[0] : array()
            );

            foreach (array_unique(array_map('strval', $matches)) as $ref) {
                $path = (string) wp_parse_url($ref, PHP_URL_PATH);
                if ('' === $path) {
                    $path = $ref;
                }
                $basename = basename(rawurldecode($path));
                if ('' === $basename || !preg_match('/^bundle-[A-Za-z0-9_.-]+\.css$/', $basename)) {
                    continue;
                }
                $refs[$basename] = true;
                $companion = $this->get_css_bundle_companion_basename($basename);
                if ('' !== $companion) {
                    $refs[$companion] = true;
                }
            }

            return $refs;
        }

        private function get_css_bundle_cached_html_ref_basenames($max_files = 800)
        {
            // 2.57.167: generated CSS refs are tracked in an UltraCache DB table during cache STORE.
            // Do not scan cached HTML here; this runs in cleanup/warm flows and must stay bounded.
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_protected_generated_css_basenames')) {
                $protected = Ultra_Cache_WP::get_protected_generated_css_basenames('css-bundles');
                return is_array($protected) ? $protected : array();
            }

            return array();
        }

        private function normalize_css_bundle_entry_for_manifest(array $entry)
        {
            if (empty($entry['bundleFile']) || !is_readable((string) $entry['bundleFile']) || filesize((string) $entry['bundleFile']) <= 0) {
                return array();
            }

            if (!empty($entry['delayedFontUrl']) || !empty($entry['delayedFontFile']) || !empty($entry['delayedFontFaceBlocks'])) {
                $delayed_file = isset($entry['delayedFontFile']) ? (string) $entry['delayedFontFile'] : '';
                if ('' === $delayed_file || !is_readable($delayed_file) || filesize($delayed_file) <= 0) {
                    return array();
                }
            }

            return $entry;
        }

        private function cleanup_orphan_frontpage_css_bundles(array $manifest)
        {
            $dir = $this->get_frontpage_css_dir();
            if (!is_dir($dir) || !is_readable($dir)) {
                return 0;
            }

            $active = $this->get_frontpage_css_manifest_bundle_files($manifest);
            $cached_html_refs = $this->get_css_bundle_cached_html_ref_basenames();
            $deleted = 0;
            $protected_by_ref_index = 0;
            $max_deletes = $this->get_css_bundle_cleanup_max_deletes_per_run();
            $files = (array) glob(trailingslashit($dir) . '*.css');

            foreach ($files as $file) {
                $file = (string) $file;
                if ('' === $file || !is_file($file)) {
                    continue;
                }

                $basename = basename($file);
                $pair_basename = $this->get_css_bundle_pair_basename($basename);
                if (isset($active[$basename]) || ('' !== $pair_basename && isset($active[$pair_basename]))) {
                    continue;
                }

                $companion_basename = $this->get_css_bundle_companion_basename($basename);
                if (isset($cached_html_refs[$basename]) || ('' !== $pair_basename && isset($cached_html_refs[$pair_basename])) || ('' !== $companion_basename && isset($cached_html_refs[$companion_basename]))) {
                    $protected_by_ref_index++;
                    continue;
                }

                // Proxy-stale-safe lifecycle: Varnish/browser/CDN can still serve older cached HTML
                // after the manifest changed. Keep recent bundle files around long enough for stale
                // HTML refs to keep working instead of returning 404 and breaking CSS.
                if ($this->is_css_bundle_file_recently_protected($file)) {
                    continue;
                }

                if (ucwp_safe_unlink($file)) {
                    $deleted++;
                }

                if ($deleted >= $max_deletes) {
                    break;
                }
            }

            if ($deleted > 0 || $protected_by_ref_index > 0) {
                $this->record_cache_event('page-css-bundle-cleanup', array(
                    'deleted' => $deleted,
                    'max' => $max_deletes,
                    'grace_seconds' => $this->get_css_bundle_cleanup_grace_seconds(),
                    'protected_by_ref_index' => $protected_by_ref_index,
                ));
            }

            return $deleted;
        }

        private function delete_all_frontpage_css_bundle_files($force = false)
        {
            $dir = $this->get_frontpage_css_dir();
            if (!is_dir($dir) || !is_readable($dir)) {
                return 0;
            }

            $deleted = 0;
            $cached_html_refs = $force ? array() : $this->get_css_bundle_cached_html_ref_basenames();
            $max_deletes = $force ? PHP_INT_MAX : $this->get_css_bundle_cleanup_max_deletes_per_run();
            foreach ((array) glob(trailingslashit($dir) . '*.css') as $file) {
                $file = (string) $file;
                if ('' === $file || !is_file($file)) {
                    continue;
                }
                if (!$force) {
                    $basename = basename($file);
                    $pair_basename = $this->get_css_bundle_pair_basename($basename);
                    $companion_basename = $this->get_css_bundle_companion_basename($basename);
                    if (isset($cached_html_refs[$basename]) || ('' !== $pair_basename && isset($cached_html_refs[$pair_basename])) || ('' !== $companion_basename && isset($cached_html_refs[$companion_basename]))) {
                        continue;
                    }
                    if ($this->is_css_bundle_file_recently_protected($file)) {
                        continue;
                    }
                }
                if (ucwp_safe_unlink($file)) {
                    $deleted++;
                }
                if ($deleted >= $max_deletes) {
                    break;
                }
            }
            return $deleted;
        }

        private function get_css_bundle_manifest_key($url)
        {
            $normalized = $this->normalize_url((string) $url);
            return '' === $normalized ? '' : md5($normalized);
        }

        private function get_frontpage_css_manifest_entry($url = '')
        {
            $url = '' !== (string) $url ? (string) $url : $this->get_current_request_url();
            $key = $this->get_css_bundle_manifest_key($url);
            $manifest = $this->read_frontpage_css_manifest();
            $entry = array();

            if ('' !== $key && isset($manifest['entries'][$key]) && is_array($manifest['entries'][$key])) {
                $entry = $manifest['entries'][$key];
            } elseif ($this->is_frontpage_request_url($url) && isset($manifest['entry']) && is_array($manifest['entry'])) {
                $entry = $manifest['entry'];
            }

            $entry = $this->normalize_css_bundle_entry_for_manifest($entry);
            if (empty($entry)) {
                return array();
            }
            if (empty($entry['bundleUrl']) || empty($entry['sourceUrls']) || !is_array($entry['sourceUrls'])) {
                return array();
            }

            return $entry;
        }

        private function delete_frontpage_css_bundle($url = '')
        {
            $manifest = $this->read_frontpage_css_manifest();

            if ('' !== (string) $url) {
                $key = $this->get_css_bundle_manifest_key($url);
                if ('' !== $key && isset($manifest['entries'][$key])) {
                    unset($manifest['entries'][$key]);
                }
                if ($this->is_frontpage_request_url($url)) {
                    $manifest['entry'] = array();
                }
                $this->write_frontpage_css_manifest($manifest);
                // Do not run orphan cleanup immediately after a single-URL purge.
                // Reverse proxies or browser caches can still serve stale HTML for that URL
                // after the local cache file is removed; cleanup will age out retired bundles
                // through the normal grace-window path instead.
                return;
            }

            // Do not remove recent CSS bundles immediately on purge/flush: reverse proxies can
            // still serve stale HTML that references those files. Cleanup will remove aged files.
            $this->delete_all_frontpage_css_bundle_files(false);

            $file = $this->get_frontpage_css_manifest_file();
            if (file_exists($file)) {
                ucwp_safe_unlink($file);
            }
        }

        public function warm_frontpage_html(array $args = array())
        {
            $frontpage_url = home_url('/');
            $result = $this->warm_url($frontpage_url, $args);

            if (!empty($result['success'])) {
                $result['message'] = 'Front page HTML cache warmed.';
                if (!empty($result['files']) && is_array($result['files'])) {
                    $result['message'] .= ' Generated ' . count($result['files']) . ' cache file(s).';
                }
            }

            return $result;
        }

        public function warm_frontpage_html_with_css()
        {
            return $this->build_frontpage_css_bundle(home_url('/'));
        }

        public function build_frontpage_css_bundle($url = '', array $args = array())
        {
            $args = is_array($args) ? $args : array();
            $skip_final_warm = !empty($args['skip_final_warm']);
            $frontpage_url = '' !== (string) $url ? esc_url_raw((string) $url) : home_url('/');
            $result = array(
                'success' => false,
                'skipped' => false,
                'url' => $frontpage_url,
                'message' => '',
                'bundleCount' => 0,
                'bundleFile' => '',
                'bundleUrl' => '',
                'sourceUrls' => array(),
                'stats' => $this->get_default_frontpage_css_stats(),
                'warmResult' => array(),
            );

            if (!$this->is_cacheable_local_url($frontpage_url)) {
                $result['message'] = 'Page is not a local cacheable URL.';
                $this->record_analytics_frontpage_css_warm($result);
                return $result;
            }

            $lock_name = 'css-bundle-' . md5($this->normalize_url($frontpage_url));
            if (!$this->acquire_runtime_lock($lock_name, 180)) {
                $result['skipped'] = true;
                $result['message'] = 'CSS bundle build skipped because another CSS/frontpage build is already running for this URL.';
                $this->record_analytics_frontpage_css_warm($result);
                return $result;
            }

            try {
                $scan = $this->fetch_frontpage_css_source_html($frontpage_url);
                if (empty($scan['success']) || empty($scan['html'])) {
                    $result['message'] = !empty($scan['message']) ? (string) $scan['message'] : 'Could not fetch page HTML.';
                    $this->record_analytics_frontpage_css_warm($result);
                    return $result;
                }

                $prepared = $this->build_frontpage_css_bundle_from_html((string) $scan['html'], $frontpage_url);
                if (!empty($prepared['stats']) && is_array($prepared['stats'])) {
                    $result['stats'] = $prepared['stats'];
                }

                if (empty($prepared['success'])) {
                    $result['skipped'] = !empty($prepared['skipped']);
                    $result['message'] = !empty($prepared['message']) ? (string) $prepared['message'] : 'Could not build CSS bundle.';
                    $this->record_analytics_frontpage_css_warm($result);
                    return $result;
                }

                $manifest = $this->read_frontpage_css_manifest();
                $manifest['version'] = 3;
                $manifest['updatedAt'] = current_time('timestamp');
                $manifest['updatedAtMysql'] = current_time('mysql');
                if (!isset($manifest['entries']) || !is_array($manifest['entries'])) {
                    $manifest['entries'] = array();
                }
                $entry = $this->build_frontpage_css_manifest_entry($frontpage_url, $prepared);
                $key = $this->get_css_bundle_manifest_key($frontpage_url);
                if ('' !== $key) {
                    $manifest['entries'][$key] = $entry;
                }
                if ($this->is_frontpage_request_url($frontpage_url)) {
                    $manifest['entry'] = $entry;
                }
                $this->write_frontpage_css_manifest($manifest);
                $this->cleanup_orphan_frontpage_css_bundles($manifest);

                $warm_result = $skip_final_warm ? array('success' => true, 'skipped' => true, 'message' => __('Final HTML warm skipped because the caller will warm the page after the CSS bundle is available.', 'ultracache')) : $this->warm_url($frontpage_url, array('force_refresh' => true));
                $verification = $skip_final_warm ? array(
                    'checked' => false,
                    'cachedHtmlAvailable' => false,
                    'containsCssBundle' => false,
                    'cssBundleRefs' => 0,
                    'stylesheetLinks' => 0,
                    'inspectedFile' => '',
                    'message' => __('Final HTML warm skipped; caller must verify after writing cached HTML.', 'ultracache'),
                ) : $this->inspect_css_bundle_html_after_warm($frontpage_url, is_array($warm_result) ? $warm_result : array());
                $bundle_bytes = (!empty($prepared['bundleFile']) && is_readable((string) $prepared['bundleFile'])) ? (int) filesize((string) $prepared['bundleFile']) : 0;
                $warm_success = $skip_final_warm || !empty($warm_result['success']);
                $injection_verified = $skip_final_warm || !empty($verification['containsCssBundle']);

                $result['bundleCount'] = 1;
                $result['bundleFile'] = (string) $prepared['bundleFile'];
                $result['bundleUrl'] = (string) $prepared['bundleUrl'];
                $result['bundleBytes'] = $bundle_bytes;
                $result['delayedFontFile'] = (string) ($prepared['delayedFontFile'] ?? '');
                $result['delayedFontUrl'] = (string) ($prepared['delayedFontUrl'] ?? '');
                $result['delayedFontBytes'] = isset($prepared['delayedFontBytes']) ? (int) $prepared['delayedFontBytes'] : 0;
                $result['delayedFontFaceBlocks'] = isset($prepared['delayedFontFaceBlocks']) ? (int) $prepared['delayedFontFaceBlocks'] : 0;
                $result['delayedFontFamilies'] = isset($prepared['delayedFontFamilies']) && is_array($prepared['delayedFontFamilies']) ? $prepared['delayedFontFamilies'] : array();
                $result['delayedFontPatterns'] = isset($prepared['delayedFontPatterns']) && is_array($prepared['delayedFontPatterns']) ? $prepared['delayedFontPatterns'] : array();
                $result['sourceUrls'] = array_values(array_unique(array_map('strval', (array) ($prepared['sourceUrls'] ?? array()))));
                $result['sourceDetails'] = isset($prepared['sourceDetails']) && is_array($prepared['sourceDetails']) ? $prepared['sourceDetails'] : array();
                $result['sourceBytesTotal'] = isset($prepared['sourceBytesTotal']) ? (int) $prepared['sourceBytesTotal'] : 0;
                $result['warmResult'] = is_array($warm_result) ? $warm_result : array();
                $result['warmVerification'] = is_array($verification) ? $verification : array();

                $verified_cached_html = !$skip_final_warm && !empty($verification['cachedHtmlAvailable']) && !empty($verification['containsCssBundle']);
                $result['warmVerifiedAfterTimeout'] = (!$skip_final_warm && !$warm_success && $verified_cached_html);

                // A heavy loopback warm can time out at the HTTP client while the generated page
                // still reaches the cache write path. Treat that state as a verified success only
                // when the post-warm cache inspection proves the cached HTML exists and contains
                // the freshly generated CSS bundle marker/reference. This keeps automation stable
                // without hiding genuine failed warms or missing bundle injection.
                $result['success'] = ($skip_final_warm || $warm_success || !empty($result['warmVerifiedAfterTimeout'])) && $injection_verified;

                $warm_status = $skip_final_warm ? 'skipped' : (!empty($warm_result['success']) ? 'success' : (!empty($result['warmVerifiedAfterTimeout']) ? 'verified-after-timeout' : 'failed'));
                $warm_message = !$skip_final_warm && !empty($warm_result['message']) ? ' (' . (string) $warm_result['message'] . ')' : '';
                $contains_label = $skip_final_warm ? 'not checked' : (!empty($verification['containsCssBundle']) ? 'yes' : 'no');
                $result['message'] = 'Built 1 CSS bundle from ' . max(0, (int) ($result['stats']['bundled'] ?? 0)) . ' stylesheet(s).'
                    . ' Bundle bytes: ' . $bundle_bytes . '.'
                    . (!empty($result['delayedFontFaceBlocks']) ? ' Delayed icon font-face blocks: ' . (int) $result['delayedFontFaceBlocks'] . ' (' . (int) $result['delayedFontBytes'] . ' bytes).' : '')
                    . ' Final page warm: ' . $warm_status . $warm_message . '.'
                    . ' Cached HTML contains CSS bundle: ' . $contains_label . '.'
                    . (!$skip_final_warm ? ' CSS bundle refs in cached HTML: ' . (int) ($verification['cssBundleRefs'] ?? 0) . '. Stylesheet links in cached HTML: ' . (int) ($verification['stylesheetLinks'] ?? 0) . '.' : '');
                if (!empty($result['warmVerifiedAfterTimeout'])) {
                    $result['message'] .= ' Warning: the loopback HTTP client timed out, but cached HTML was readable and contains the CSS bundle, so the warm is treated as verified.';
                } elseif (!$skip_final_warm && !$warm_success) {
                    $result['message'] .= ' Warning: final page warm did not complete and cached HTML could not be verified, so cached HTML may remain stale.';
                } elseif (!$skip_final_warm && !$injection_verified) {
                    $result['message'] .= ' Warning: CSS bundle was built but was not found in cached HTML.';
                }
                $this->record_cache_event('page-css-bundle-build', array(
                    'url' => $frontpage_url,
                    'bundleFile' => $result['bundleFile'],
                    'sourceCount' => count($result['sourceUrls']),
                    'delayedFontFaceBlocks' => (int) ($result['delayedFontFaceBlocks'] ?? 0),
                ));
                $this->record_analytics_frontpage_css_warm($result);

                return $result;
            } finally {
                $this->release_runtime_lock($lock_name);
            }
        }

        private function inspect_css_bundle_html_after_warm($url, array $warm_result = array())
        {
            $verification = array(
                'checked' => true,
                'cachedHtmlAvailable' => false,
                'containsCssBundle' => false,
                'cssBundleRefs' => 0,
                'stylesheetLinks' => 0,
                'inspectedFile' => '',
                'message' => '',
            );

            $files = array();
            if (!empty($warm_result['files']) && is_array($warm_result['files'])) {
                foreach ($warm_result['files'] as $file) {
                    $file = (string) $file;
                    if ('' !== $file) {
                        $files[] = $file;
                    }
                }
            }

            $orig_cache_path = $this->get_cache_path((string) $url, 'orig');
            if ('' !== (string) $orig_cache_path) {
                $files[] = (string) $orig_cache_path;
            }

            $files = array_values(array_unique($files));
            $html = '';
            foreach ($files as $file) {
                if ('' === $file || !is_readable($file)) {
                    continue;
                }
                $maybe_html = ucwp_safe_file_get_contents($file);
                if (is_string($maybe_html) && '' !== $maybe_html) {
                    $html = $maybe_html;
                    $verification['cachedHtmlAvailable'] = true;
                    $verification['inspectedFile'] = $file;
                    break;
                }
            }

            if ('' === $html) {
                $verification['message'] = 'No readable cached HTML file was available after warm.';
                return $verification;
            }

            if (preg_match_all('/<link\b[^>]*>/i', $html, $link_matches)) {
                foreach ((array) $link_matches[0] as $tag_html) {
                    if ($this->html_tag_rel_contains_stylesheet((string) $tag_html)) {
                        $verification['stylesheetLinks']++;
                    }
                }
            }

            $lower_html = strtolower($html);
            $path_refs = substr_count($lower_html, '/wp-content/cache/ultracache/css-bundles/');
            $marker_refs = substr_count($lower_html, 'data-ucwp-page-css-bundle=') + substr_count($lower_html, 'id="ucwp-page-css-bundle"') + substr_count($lower_html, "id='ucwp-page-css-bundle'");
            $verification['cssBundleRefs'] = max((int) $path_refs, (int) $marker_refs);
            $verification['containsCssBundle'] = $verification['cssBundleRefs'] > 0;
            $verification['message'] = $verification['containsCssBundle'] ? 'Cached HTML contains a CSS bundle reference.' : 'Cached HTML does not contain a CSS bundle reference.';

            return $verification;
        }

        private function fetch_frontpage_css_source_html($url)
        {
            $scan_url = add_query_arg(
                array(
                    'ucwp_frontpage_css_scan' => 1,
                    'ucwp_css_v' => rawurlencode(UCWP_VERSION),
                ),
                $url
            );

            $response = ucwp_safe_loopback_remote_request(
                $scan_url,
                array(
                    'method' => 'GET',
                    'timeout' => 10,
                    'redirection' => 3,
                    'sslverify' => $this->should_verify_loopback_ssl($scan_url),
                    'user-agent' => 'Mozilla/5.0 (compatible; UltraCache-CSSBundle/' . UCWP_VERSION . '; +https://wordpress.org)',
                    'headers' => array(
                        'Cache-Control' => 'no-cache',
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'X-UltraCache-CSS-Bundle' => '1',
                        'X-UltraCache-Internal-Request' => '1',
                    ),
                ),
                'css_bundle_scan'
            );

            if (is_wp_error($response)) {
                return array('success' => false, 'message' => $response->get_error_message(), 'html' => '');
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $html = (string) wp_remote_retrieve_body($response);
            if (200 !== $code || '' === $html) {
                return array('success' => false, 'message' => 200 !== $code ? 'Remote page did not return HTTP 200.' : 'Remote page returned an empty body.', 'html' => '');
            }
            if (!$this->is_html_loopback_response($response, $html)) {
                return array('success' => false, 'message' => __('Remote page did not return an HTML Content-Type.', 'ultracache'), 'html' => '');
            }

            return array('success' => true, 'message' => '', 'html' => $html);
        }

        private function build_frontpage_css_bundle_from_html($html, $page_url, $mode = '')
        {
            $settings = $this->get_settings();
            $mode = in_array((string) $mode, array('safe', 'aggressive', 'full'), true) ? (string) $mode : (string) ($settings['homepage_css_bundle_mode'] ?? 'safe');
            $mode = in_array($mode, array('safe', 'aggressive', 'full'), true) ? $mode : 'safe';
            $stats = $this->get_default_frontpage_css_stats();
            $html = $this->normalize_protocol_relative_urls_in_html((string) $html);
            if ('' === $html || false === stripos($html, '<head') || false === stripos($html, '<link')) {
                return array('success' => false, 'skipped' => true, 'message' => __('No stylesheet links were found on the page.', 'ultracache'), 'stats' => $stats);
            }

            if (!preg_match('/<head\b[^>]*>([\s\S]*?)<\/head>/i', $html, $matches)) {
                return array('success' => false, 'skipped' => true, 'message' => __('No <head> element was found on the page.', 'ultracache'), 'stats' => $stats);
            }

            $head_inner = isset($matches[1]) ? (string) $matches[1] : '';
            if (!preg_match_all('/<link\b[^>]*>/i', $head_inner, $tag_matches)) {
                return array('success' => false, 'skipped' => true, 'message' => __('No <link> tags were found on the page.', 'ultracache'), 'stats' => $stats);
            }

            $assets = array();
            foreach ((array) $tag_matches[0] as $tag_html) {
                $tag_html = (string) $tag_html;
                if (!$this->html_tag_rel_contains_stylesheet($tag_html)) {
                    continue;
                }

                $stats['scanned']++;
                $asset = $this->get_safe_frontpage_stylesheet_asset($tag_html, $page_url, $mode);
                if (!empty($asset)) {
                    $assets[] = $asset;
                } else {
                    $href = $this->extract_attribute_from_html_tag($tag_html, 'href');
                    if ('' === $href) {
                        $stats['unresolved']++;
                    } else {
                        $stats['skipped']++;
                    }
                }
            }

            if (count($assets) < 2) {
                return array('success' => false, 'skipped' => true, 'message' => __('Not enough eligible local stylesheets were found for CSS bundling.', 'ultracache'), 'stats' => $stats);
            }

            $bundle = $this->build_frontpage_css_bundle_file($page_url, $assets, $mode);
            if (!empty($bundle['stats']) && is_array($bundle['stats'])) {
                $stats['bundled'] += max(0, (int) ($bundle['stats']['bundled'] ?? 0));
                $stats['skipped'] += max(0, (int) ($bundle['stats']['skipped'] ?? 0));
                $stats['unresolved'] += max(0, (int) ($bundle['stats']['unresolved'] ?? 0));
                $stats['delayedFontFaceBlocks'] += max(0, (int) ($bundle['stats']['delayedFontFaceBlocks'] ?? 0));
                $stats['delayedFontFamilies'] = array_values(array_unique(array_merge((array) ($stats['delayedFontFamilies'] ?? array()), (array) ($bundle['stats']['delayedFontFamilies'] ?? array()))));
                $stats['delayedFontPatterns'] = array_values(array_unique(array_merge((array) ($stats['delayedFontPatterns'] ?? array()), (array) ($bundle['stats']['delayedFontPatterns'] ?? array()))));
                foreach (array('cssImageUrlsScanned', 'cssImageUrlsRewritten', 'cssImageUrlsImageSet', 'cssImageUrlsSkipped') as $css_image_stat_key) {
                    $stats[$css_image_stat_key] = max(0, (int) ($stats[$css_image_stat_key] ?? 0)) + max(0, (int) ($bundle['stats'][$css_image_stat_key] ?? 0));
                }
            }
            if (empty($bundle['success'])) {
                return array('success' => false, 'skipped' => !empty($bundle['skipped']), 'message' => !empty($bundle['message']) ? (string) $bundle['message'] : 'Could not write the CSS bundle.', 'stats' => $stats);
            }

            return array(
                'success' => true,
                'skipped' => false,
                'message' => !empty($bundle['message']) ? (string) $bundle['message'] : 'Prepared CSS bundle.',
                'bundleFile' => (string) $bundle['file'],
                'bundleUrl' => (string) $bundle['url'],
                'sourceUrls' => array_values(array_unique(array_map('strval', (array) ($bundle['sourceUrls'] ?? wp_list_pluck($assets, 'url'))))),
                'mode' => $mode,
                'bundleSignature' => (string) ($bundle['signature'] ?? ''),
                'bundleContentHash' => (string) ($bundle['contentHash'] ?? ''),
                'delayedFontFile' => (string) ($bundle['delayedFontFile'] ?? ''),
                'delayedFontUrl' => (string) ($bundle['delayedFontUrl'] ?? ''),
                'delayedFontBytes' => isset($bundle['delayedFontBytes']) ? (int) $bundle['delayedFontBytes'] : 0,
                'delayedFontFaceBlocks' => isset($bundle['delayedFontFaceBlocks']) ? (int) $bundle['delayedFontFaceBlocks'] : 0,
                'delayedFontFamilies' => isset($bundle['delayedFontFamilies']) && is_array($bundle['delayedFontFamilies']) ? $bundle['delayedFontFamilies'] : array(),
                'delayedFontPatterns' => isset($bundle['delayedFontPatterns']) && is_array($bundle['delayedFontPatterns']) ? $bundle['delayedFontPatterns'] : array(),
                'sourceDetails' => isset($bundle['sourceDetails']) && is_array($bundle['sourceDetails']) ? $bundle['sourceDetails'] : array(),
                'sourceBytesTotal' => isset($bundle['sourceBytesTotal']) ? (int) $bundle['sourceBytesTotal'] : 0,
                'stats' => $stats,
            );
        }

        private function is_homepage_css_bundle_allowed_media($media, $mode = 'safe')
        {
            $mode = strtolower(trim((string) $mode));
            $media = strtolower(trim((string) $media));
            if ('' === $media || 'all' === $media) {
                return true;
            }

            // Full CSS Bundle accepts every normal local stylesheet media value.
            // Non-all media are wrapped with the original media query in the bundle,
            // so print/speech/responsive semantics remain preserved.
            if ('full' === $mode) {
                return true;
            }

            if (!in_array($mode, array('aggressive', 'leftover'), true)) {
                return false;
            }

            if (false !== strpos($media, 'print') || false !== strpos($media, 'speech')) {
                return false;
            }

            return (0 === strpos($media, 'screen') || 0 === strpos($media, 'all '));
        }


        private function get_safe_frontpage_stylesheet_asset($tag_html, $page_url = '', $mode = 'safe')
        {
            $tag_html = (string) $tag_html;
            if (!$this->html_tag_rel_contains_stylesheet($tag_html)) {
                return array();
            }

            if (false !== stripos($tag_html, 'data-ucwp-frontpage-css=') || false !== stripos($tag_html, 'data-ucwp-page-css-bundle=') || false !== stripos($tag_html, 'data-ucwp-async-css=')) {
                return array();
            }

            foreach (array('onload', 'disabled', 'data-href', 'data-src') as $attribute) {
                if (preg_match('/\b' . preg_quote($attribute, '/') . '\b/i', $tag_html)) {
                    return array();
                }
            }

            $href = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag_html, 'href'), ENT_QUOTES);
            if ('' === $href) {
                return array();
            }

            $media = strtolower(trim((string) $this->extract_attribute_from_html_tag($tag_html, 'media')));
            if (!$this->is_homepage_css_bundle_allowed_media($media, $mode)) {
                return array();
            }

            $absolute_url = $this->absolutize_public_resource_url($href, '' !== (string) $page_url ? (string) $page_url : home_url('/'));
            if ('' === $absolute_url) {
                return array();
            }

            if ($this->should_exclude_stylesheet_url_by_fragments($absolute_url, $this->get_homepage_css_bundle_exclude_fragments())) {
                return array();
            }

            $host = (string) wp_parse_url($absolute_url, PHP_URL_HOST);
            $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            if ('' === $host || '' === $home_host || strtolower($host) !== strtolower($home_host)) {
                return array();
            }

            $path = (string) wp_parse_url($absolute_url, PHP_URL_PATH);
            if ('' === $path || '.css' !== strtolower(substr($path, -4))) {
                return array();
            }
            if (false !== strpos(strtolower($path), '/cache/ultracache/css-bundles/')) {
                return array();
            }

            $local_path = $this->resolve_local_path_from_public_url($absolute_url);
            if ('' === $local_path || !is_readable($local_path)) {
                return array();
            }

            return array(
                'url' => $absolute_url,
                'path' => $local_path,
                'media' => $media,
            );
        }

        private function is_css_bundle_media_wrapper_safe($media)
        {
            $media = trim((string) $media);
            if ('' === $media || 'all' === strtolower($media)) {
                return false;
            }

            return !preg_match('/[{}<>]/', $media);
        }

        private function normalize_icon_font_pattern($pattern)
        {
            $pattern = strtolower(trim((string) $pattern));
            $pattern = preg_replace('/\s+/', ' ', $pattern);
            return is_string($pattern) ? $pattern : '';
        }

        private function normalize_icon_font_pattern_list(array $patterns)
        {
            $normalized = array();
            foreach ($patterns as $pattern) {
                $pattern = $this->normalize_icon_font_pattern($pattern);
                if ('' !== $pattern) {
                    $normalized[$pattern] = true;
                }
            }

            return array_keys($normalized);
        }

        private function icon_font_text_matches_patterns($text, array $patterns, &$matched_pattern = '')
        {
            $text = $this->normalize_icon_font_pattern($text);
            if ('' === $text || empty($patterns)) {
                return false;
            }

            foreach ($patterns as $pattern) {
                $pattern = $this->normalize_icon_font_pattern($pattern);
                if ('' === $pattern) {
                    continue;
                }
                if (false !== strpos($text, $pattern)) {
                    $matched_pattern = $pattern;
                    return true;
                }
            }

            return false;
        }

        private function extract_font_family_from_font_face_block($block)
        {
            $block = (string) $block;
            if (preg_match('/font-family\s*:\s*([^;]+);/i', $block, $matches)) {
                return trim(trim((string) $matches[1]), "\"' \t\r\n");
            }

            return '';
        }

        private function should_delay_css_font_face_block($block, $css_context, array $settings, &$meta = array())
        {
            $block = (string) $block;
            $css_context = (string) $css_context;
            $family = $this->extract_font_family_from_font_face_block($block);
            $combined = strtolower($family . "\n" . $block);
            $meta = array(
                'family' => $family,
                'matchedPattern' => '',
                'reason' => '',
            );

            $exclude_patterns = $this->normalize_icon_font_pattern_list((array) ($settings['delay_icon_fonts_exclude_list'] ?? array()));
            $include_patterns = $this->normalize_icon_font_pattern_list((array) ($settings['delay_icon_fonts_list'] ?? array()));

            $matched = '';
            if ($this->icon_font_text_matches_patterns($combined, $exclude_patterns, $matched)) {
                $meta['matchedPattern'] = $matched;
                $meta['reason'] = 'excluded';
                return false;
            }

            if ($this->icon_font_text_matches_patterns($combined, $include_patterns, $matched)) {
                $meta['matchedPattern'] = $matched;
                $meta['reason'] = 'user-pattern';
                return true;
            }

            if (empty($settings['delay_icon_fonts_auto_detect'])) {
                return false;
            }

            $auto_patterns = array(
                ' icon',
                '-icon',
                '_icon',
                'icons',
                'fontawesome',
                'font awesome',
                'dashicons',
                'eicons',
                'icomoon',
                'flaticon',
                'themify',
                'simple-line-icons',
                'linearicons',
                'material-icons',
                'materialicons',
                'ionicons',
                'feather.ttf',
                'feather fonts',
                'star.ttf',
                'woocommerce star',
                '/webfonts/',
                '/icons/',
                'fa-solid',
                'fa-regular',
                'fa-brands',
            );

            if ($this->icon_font_text_matches_patterns($combined, $auto_patterns, $matched)) {
                $meta['matchedPattern'] = $matched;
                $meta['reason'] = 'auto-pattern';
                return true;
            }

            if ('' !== $family) {
                $family_pattern = preg_quote($family, '/');
                if (preg_match('/font-family\s*:\s*[^;]*' . $family_pattern . '[^;]*;[\s\S]{0,200}?content\s*:\s*[\'"]\\\\[a-f0-9]{3,6}/i', $css_context)
                    || preg_match('/content\s*:\s*[\'"]\\\\[a-f0-9]{3,6}[\s\S]{0,200}?font-family\s*:\s*[^;]*' . $family_pattern . '[^;]*;/i', $css_context)) {
                    $meta['matchedPattern'] = 'unicode-content-usage';
                    $meta['reason'] = 'auto-usage';
                    return true;
                }
            }

            return false;
        }

        private function split_delayed_icon_font_faces_from_css($css, $source_url, array $settings)
        {
            $css = (string) $css;
            $result = array(
                'body' => $css,
                'delayedCss' => '',
                'delayedCount' => 0,
                'families' => array(),
                'patterns' => array(),
            );

            if ('' === $css || empty($settings['delay_icon_fonts'])) {
                return $result;
            }

            if (false === stripos($css, '@font-face')) {
                return $result;
            }

            $delayed_blocks = array();
            $result['body'] = (string) preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($matches) use (&$delayed_blocks, &$result, $css, $source_url, $settings) {
                $block = (string) ($matches[0] ?? '');
                $meta = array();
                if (!$this->should_delay_css_font_face_block($block, $css, $settings, $meta)) {
                    return $block;
                }

                $family = isset($meta['family']) ? trim((string) $meta['family']) : '';
                $pattern = isset($meta['matchedPattern']) ? trim((string) $meta['matchedPattern']) : '';
                if ('' !== $family) {
                    $result['families'][] = $family;
                }
                if ('' !== $pattern) {
                    $result['patterns'][] = $pattern;
                }
                $delayed_block = $this->normalize_protocol_relative_urls_in_css($block, $source_url);
                if (false !== stripos($delayed_block, '.ttf')) {
                    // 2.56.197: delayed icon-font CSS must also prefer same-path
                    // WOFF2/WOFF siblings. The generic inline cleanup is not enough
                    // for quoted src() values and multi-source icon font declarations.
                    $preferred_delayed_block = $this->rewrite_font_face_ttf_sources_to_preferred_formats($delayed_block, $source_url);
                    if (is_string($preferred_delayed_block) && '' !== trim($preferred_delayed_block)) {
                        $delayed_block = $preferred_delayed_block;
                    }
                }
                if (function_exists('ucwp_optimize_font_face_block')) {
                    $delayed_stats = array(
                        'fontDisplayAdded' => 0,
                        'duplicateSrcRemoved' => 0,
                        'ttfSourcesRemoved' => 0,
                        'fontFaceBlocksChanged' => 0,
                    );
                    $optimized_delayed_block = ucwp_optimize_font_face_block($delayed_block, $delayed_stats);
                    if (is_string($optimized_delayed_block) && '' !== trim($optimized_delayed_block)) {
                        $delayed_block = $optimized_delayed_block;
                    }
                } else {
                    $delayed_block = $this->normalize_font_face_display_in_css($delayed_block);
                }

                $delayed_blocks[] = "/* UltraCache Delayed Font Source: " . (string) $source_url . ('' !== $family ? ' | ' . $family : '') . " */\n" . $delayed_block;
                $result['delayedCount']++;
                return "\n/* UltraCache delayed icon font-face removed: " . ('' !== $family ? $family : 'matched font') . " */\n";
            }, $css);

            if (!is_string($result['body'])) {
                $result['body'] = $css;
                return $result;
            }

            if (!empty($delayed_blocks)) {
                $delayed_css = trim(implode("\n\n", $delayed_blocks)) . "\n";
                if (false !== stripos($delayed_css, '.ttf')) {
                    $delayed_css = $this->rewrite_font_face_ttf_sources_to_preferred_formats($delayed_css, $source_url);
                }
                $result['delayedCss'] = trim((string) $delayed_css) . "\n";
                $result['families'] = array_values(array_unique(array_map('strval', $result['families'])));
                $result['patterns'] = array_values(array_unique(array_map('strval', $result['patterns'])));
            }

            return $result;
        }

        private function build_delayed_icon_fonts_stylesheet_markup(array $entry, $id = 'ucwp-delayed-icon-fonts')
        {
            $policy = $this->get_font_optimization_policy();
            if (empty($policy['delay_icon_fonts'])) {
                return '';
            }

            $url = isset($entry['delayedFontUrl']) ? (string) $entry['delayedFontUrl'] : '';
            if ('' === $url) {
                return '';
            }

            // 2.56.199: never inject a delayed icon-font stylesheet unless the
            // generated CSS file exists and has content. Otherwise a clean generated
            // CSS rebuild can remove the file while stale runtime state still injects
            // the URL, breaking icon-font glyphs.
            $file = !empty($entry['delayedFontFile']) ? (string) $entry['delayedFontFile'] : $this->resolve_local_path_from_public_url($url);
            clearstatcache(true, $file);
            if ('' === $file || !is_readable($file) || filesize($file) <= 0) {
                return '';
            }

            $id = preg_replace('/[^a-z0-9_\-]/i', '-', (string) $id);
            if (!is_string($id) || '' === $id) {
                $id = 'ucwp-delayed-icon-fonts';
            }

            $href = esc_url($url);
            // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Final HTML rewrite emits generated delayed-font stylesheet links after WordPress enqueue has already completed.
            $markup = '<link rel="stylesheet" id="' . esc_attr($id) . '" href="' . $href . '" media="print" onload="this.media=&quot;all&quot;" data-ucwp-delayed-icon-fonts="1" data-ucwp-css-role="delayed-fonts-css" data-ucwp-css-async-reason="delayed-fonts" />'
                . '<noscript><link rel="stylesheet" href="' . $href . '" data-ucwp-delayed-icon-fonts-noscript="1" data-ucwp-css-role="delayed-fonts-css" /></noscript>';
            // phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet

            return $markup;
        }

        private function build_frontpage_css_bundle_file($page_url, array $assets, $mode = 'safe')
        {
            $dir = $this->get_frontpage_css_dir();
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }

            $signature_parts = array();
            $bundle_body = '';
            $bundle_charset = '';
            $bundle_imports = array();
            $bundle_import_keys = array();
            $used_urls = array();
            $source_details = array();
            $source_bytes_total = 0;
            $delayed_font_css = '';
            $delayed_font_families = array();
            $delayed_font_patterns = array();
            $settings = $this->get_settings();
            $font_policy = $this->get_font_optimization_policy($settings);
            $stats = array(
                'bundled' => 0,
                'skipped' => 0,
                'unresolved' => 0,
                'delayedFontFaceBlocks' => 0,
                'delayedFontFamilies' => array(),
                'delayedFontPatterns' => array(),
                'cssImageUrlsScanned' => 0,
                'cssImageUrlsRewritten' => 0,
                'cssImageUrlsImageSet' => 0,
                'cssImageUrlsSkipped' => 0,
                'fontDisplayAdded' => 0,
                'fontFaceBlocksScanned' => 0,
            );

            foreach ($assets as $asset) {
                $path = (string) ($asset['path'] ?? '');
                $url = (string) ($asset['url'] ?? '');
                $media = strtolower(trim((string) ($asset['media'] ?? '')));
                if ('' === $path || '' === $url || !is_readable($path)) {
                    return array(
                        'success' => false,
                        'skipped' => false,
                        'message' => __('A stylesheet could not be read.', 'ultracache'),
                        'stats' => $stats,
                    );
                }

                $css = ucwp_guarded_asset_file_get_contents($path, 'css', 'frontpage_css_bundle_asset', false);
                if (!is_string($css) || '' === $css) {
                    $stats['skipped']++;
                    continue;
                }

                $original_bytes = strlen($css);
                $signature_parts[] = $url . '|' . (string) ucwp_safe_filemtime($path, 'frontpage_css_bundle_signature') . '|' . $original_bytes;
                $prepared_css = $this->prepare_css_asset_for_bundle($css, $url);
                $prepared_body = isset($prepared_css['body']) ? (string) $prepared_css['body'] : '';
                $css_image_stats = array();
                $prepared_body = $this->rewrite_stylesheet_css_image_urls_for_media_optimization($prepared_body, $url, $css_image_stats);
                foreach (array('cssImageUrlsScanned', 'cssImageUrlsRewritten', 'cssImageUrlsImageSet', 'cssImageUrlsSkipped') as $css_image_stat_key) {
                    $stats[$css_image_stat_key] = max(0, (int) ($stats[$css_image_stat_key] ?? 0)) + max(0, (int) ($css_image_stats[$css_image_stat_key] ?? 0));
                }
                $font_display_stats = array();
                if (!empty($font_policy['bundle_font_display'])) {
                    $prepared_body = $this->normalize_font_face_display_in_css($prepared_body, $font_display_stats);
                    $stats['fontDisplayAdded'] += max(0, (int) ($font_display_stats['fontDisplayAdded'] ?? 0));
                    $stats['fontFaceBlocksScanned'] += max(0, (int) ($font_display_stats['fontFaceBlocksScanned'] ?? 0));
                }
                $font_split = !empty($font_policy['delay_icon_fonts']) ? $this->split_delayed_icon_font_faces_from_css($prepared_body, $url, $settings) : array('body' => $prepared_body, 'delayedCss' => '', 'delayedCount' => 0, 'families' => array(), 'patterns' => array());
                if (!empty($font_split['delayedCount'])) {
                    $prepared_body = (string) ($font_split['body'] ?? $prepared_body);
                    $delayed_font_css .= "\n" . (string) ($font_split['delayedCss'] ?? '');
                    $delayed_font_families = array_values(array_unique(array_merge($delayed_font_families, (array) ($font_split['families'] ?? array()))));
                    $delayed_font_patterns = array_values(array_unique(array_merge($delayed_font_patterns, (array) ($font_split['patterns'] ?? array()))));
                    $stats['delayedFontFaceBlocks'] += max(0, (int) ($font_split['delayedCount'] ?? 0));
                }
                $source_bytes_total += $original_bytes;
                $source_details[] = array(
                    'url' => $url,
                    'bytes' => $original_bytes,
                    'preparedBytes' => strlen($prepared_body),
                    'type' => $this->get_css_bundle_source_type($url),
                    'media' => $media,
                    'delayedFontFaceBlocks' => max(0, (int) ($font_split['delayedCount'] ?? 0)),
                    'cssImageUrlsScanned' => max(0, (int) ($css_image_stats['cssImageUrlsScanned'] ?? 0)),
                    'cssImageUrlsRewritten' => max(0, (int) ($css_image_stats['cssImageUrlsRewritten'] ?? 0)),
                    'cssImageUrlsImageSet' => max(0, (int) ($css_image_stats['cssImageUrlsImageSet'] ?? 0)),
                    'cssImageUrlsSkipped' => max(0, (int) ($css_image_stats['cssImageUrlsSkipped'] ?? 0)),
                );
                if ('' === $bundle_charset && !empty($prepared_css['charset'])) {
                    $bundle_charset = (string) $prepared_css['charset'];
                }
                foreach ((array) ($prepared_css['imports'] ?? array()) as $import_rule) {
                    $import_rule = trim((string) $import_rule);
                    if ('' === $import_rule) {
                        continue;
                    }
                    $import_key = strtolower(preg_replace('/\s+/', ' ', $import_rule));
                    if (!isset($bundle_import_keys[$import_key])) {
                        $bundle_import_keys[$import_key] = true;
                        $bundle_imports[] = $import_rule;
                    }
                }

                $bundle_body .= "
/* UltraCache CSS Bundle Source: " . $url . " */
";
                if ('' !== $media && 'all' !== $media && $this->is_css_bundle_media_wrapper_safe($media)) {
                    $bundle_body .= "@media " . $media . " {\n" . $prepared_body . "\n}\n";
                } else {
                    $bundle_body .= $prepared_body . "
";
                }
                $used_urls[] = $url;
                $stats['bundled']++;
            }

            if ($stats['bundled'] < 2 || '' === trim($bundle_body)) {
                return array(
                    'success' => false,
                    'skipped' => true,
                    'message' => __('Not enough non-empty stylesheets were eligible for bundling.', 'ultracache'),
                    'stats' => $stats,
                    'sourceUrls' => array_values(array_unique(array_map('strval', $used_urls))),
                );
            }

            $bundle_prelude = '';
            if ('' !== trim($bundle_charset)) {
                $bundle_prelude .= trim($bundle_charset) . "
";
            }
            if (!empty($bundle_imports)) {
                $bundle_prelude .= implode("
", $bundle_imports) . "

";
            }

            $mode = in_array((string) $mode, array('safe', 'aggressive', 'full', 'leftover'), true) ? (string) $mode : 'safe';
            $bundle_content = trim($bundle_prelude . trim($bundle_body)) . "
";
            $bundle_font_display_stats = array();
            if (!empty($font_policy['bundle_font_display'])) {
                $bundle_content = $this->normalize_font_face_display_in_css($bundle_content, $bundle_font_display_stats);
                $stats['fontDisplayAdded'] += max(0, (int) ($bundle_font_display_stats['fontDisplayAdded'] ?? 0));
                $stats['fontFaceBlocksScanned'] += max(0, (int) ($bundle_font_display_stats['fontFaceBlocksScanned'] ?? 0));
            }
            if (function_exists('ucwp_strip_source_mapping_url_comments')) {
                $bundle_content = trim(ucwp_strip_source_mapping_url_comments($bundle_content)) . "
";
            }
            $content_hash = md5($bundle_content);
            $signature = md5($mode . '|' . implode('||', $signature_parts) . '|' . $content_hash);
            $filename = 'bundle-' . $mode . '-' . $signature . '.css';
            $file = $dir . $filename;
            clearstatcache(true, $file);
            $existing_hash = (is_readable($file) && filesize($file) > 0) ? md5_file($file) : '';
            if ($existing_hash !== $content_hash) {
                if (!$this->write_cache_variant_atomically($file, $bundle_content)) {
                    return array(
                        'success' => false,
                        'skipped' => true,
                        'message' => __('Could not write the generated CSS bundle file.', 'ultracache'),
                        'stats' => $stats,
                    );
                }
            }
            clearstatcache(true, $file);
            $verified_hash = (is_readable($file) && filesize($file) > 0) ? md5_file($file) : '';
            if ($verified_hash !== $content_hash) {
                return array(
                    'success' => false,
                    'skipped' => true,
                    'message' => __('Generated CSS bundle file failed verification.', 'ultracache'),
                    'stats' => $stats,
                );
            }

            $delayed_font_file = '';
            $delayed_font_url = '';
            $delayed_font_bytes = 0;
            if ('' !== trim($delayed_font_css)) {
                $delayed_font_content = trim($delayed_font_css) . "\n";
                if (function_exists('ucwp_strip_source_mapping_url_comments')) {
                    $delayed_font_content = trim(ucwp_strip_source_mapping_url_comments($delayed_font_content)) . "\n";
                }
                if (false !== stripos($delayed_font_content, '.ttf')) {
                    $delayed_font_content = $this->rewrite_font_face_ttf_sources_to_preferred_formats($delayed_font_content, home_url('/'));
                    $delayed_font_content = trim((string) $delayed_font_content) . "\n";
                }
                $delayed_font_display_stats = array();
                if (!empty($font_policy['bundle_font_display'])) {
                    $delayed_font_content = $this->normalize_font_face_display_in_css($delayed_font_content, $delayed_font_display_stats);
                    $stats['fontDisplayAdded'] += max(0, (int) ($delayed_font_display_stats['fontDisplayAdded'] ?? 0));
                    $stats['fontFaceBlocksScanned'] += max(0, (int) ($delayed_font_display_stats['fontFaceBlocksScanned'] ?? 0));
                }
                $delayed_font_hash = md5($delayed_font_content);
                $delayed_font_filename = 'bundle-' . $mode . '-' . $signature . '-delayed-fonts.css';
                $delayed_font_file = $dir . $delayed_font_filename;
                clearstatcache(true, $delayed_font_file);
                $existing_delayed_hash = (is_readable($delayed_font_file) && filesize($delayed_font_file) > 0) ? md5_file($delayed_font_file) : '';
                if ($existing_delayed_hash !== $delayed_font_hash) {
                    if (!$this->write_cache_variant_atomically($delayed_font_file, $delayed_font_content)) {
                        return array(
                            'success' => false,
                            'skipped' => true,
                            'message' => __('Could not write the delayed icon-font CSS companion file.', 'ultracache'),
                            'stats' => $stats,
                        );
                    }
                }
                clearstatcache(true, $delayed_font_file);
                $verified_delayed_hash = (is_readable($delayed_font_file) && filesize($delayed_font_file) > 0) ? md5_file($delayed_font_file) : '';
                if ($verified_delayed_hash !== $delayed_font_hash) {
                    return array(
                        'success' => false,
                        'skipped' => true,
                        'message' => __('Delayed icon-font CSS companion file failed verification.', 'ultracache'),
                        'stats' => $stats,
                    );
                }
                $delayed_font_bytes = (int) filesize($delayed_font_file);
                $delayed_font_url = home_url('/wp-content/cache/ultracache/css-bundles/' . rawurlencode($delayed_font_filename));
                $delayed_font_url = $this->normalize_public_resource_url($delayed_font_url);
            }

            $stats['delayedFontFamilies'] = array_values(array_unique(array_map('strval', $delayed_font_families)));
            $stats['delayedFontPatterns'] = array_values(array_unique(array_map('strval', $delayed_font_patterns)));

            $message = 'Prepared ' . $mode . ' CSS bundle.';
            if (!empty($bundle_imports)) {
                $message .= ' Hoisted ' . count($bundle_imports) . ' @import rule(s).';
            }
            if ($stats['delayedFontFaceBlocks'] > 0) {
                $message .= ' Delayed ' . (int) $stats['delayedFontFaceBlocks'] . ' icon font-face block(s).';
            }
            if (!empty($stats['cssImageUrlsRewritten'])) {
                $message .= ' Rewrote ' . (int) $stats['cssImageUrlsRewritten'] . ' CSS background image URL(s).';
            }
            if ($stats['skipped'] > 0) {
                $message .= ' Skipped ' . (int) $stats['skipped'] . ' empty stylesheet(s).';
            }

            $bundle_url = home_url('/wp-content/cache/ultracache/css-bundles/' . rawurlencode($filename));
            $bundle_url = $this->normalize_public_resource_url($bundle_url);

            return array(
                'success' => true,
                'file' => $file,
                'url' => $bundle_url,
                'message' => $message,
                'stats' => $stats,
                'mode' => $mode,
                'signature' => $signature,
                'contentHash' => $content_hash,
                'delayedFontFile' => $delayed_font_file,
                'delayedFontUrl' => $delayed_font_url,
                'delayedFontBytes' => $delayed_font_bytes,
                'delayedFontFaceBlocks' => (int) ($stats['delayedFontFaceBlocks'] ?? 0),
                'delayedFontFamilies' => (array) ($stats['delayedFontFamilies'] ?? array()),
                'delayedFontPatterns' => (array) ($stats['delayedFontPatterns'] ?? array()),
                'sourceUrls' => array_values(array_unique(array_map('strval', $used_urls))),
                'sourceDetails' => $this->normalize_css_bundle_source_details($source_details),
                'sourceBytesTotal' => (int) $source_bytes_total,
            );
        }

        private function rewrite_stylesheet_css_image_urls_for_media_optimization($css, $source_url, array &$stats = array())
        {
            $css = (string) $css;
            if ('' === $css || false === stripos($css, 'url(')) {
                return $css;
            }

            if (!class_exists('Ultra_Cache_Media_Converter') || !method_exists('Ultra_Cache_Media_Converter', 'get_instance')) {
                return $css;
            }

            $converter = Ultra_Cache_Media_Converter::get_instance();
            if (!is_object($converter) || !method_exists($converter, 'rewrite_css_image_urls_for_stylesheet')) {
                return $css;
            }

            $media_stats = array();
            try {
                $rewritten = $converter->rewrite_css_image_urls_for_stylesheet($css, (string) $source_url, $media_stats);
            } catch (\Throwable $e) {
                return $css;
            }

            if (is_array($media_stats)) {
                foreach (array('cssImageUrlsScanned', 'cssImageUrlsRewritten', 'cssImageUrlsImageSet', 'cssImageUrlsSkipped') as $key) {
                    $stats[$key] = max(0, (int) ($stats[$key] ?? 0)) + max(0, (int) ($media_stats[$key] ?? 0));
                }
            }

            return is_string($rewritten) && '' !== $rewritten ? $rewritten : $css;
        }

        private function prepare_css_asset_for_bundle($css, $source_url)
        {
            $css = (string) $css;
            if ('' === $css) {
                return array('body' => '', 'imports' => array(), 'charset' => '');
            }

            $css = preg_replace('/^\xEF\xBB\xBF/', '', $css);
            $comments = array();
            $masked_css = (string) preg_replace_callback('/\/\*[\s\S]*?\*\//', function ($matches) use (&$comments) {
                $key = '___UCWP_CSS_COMMENT_' . count($comments) . '___';
                $comments[$key] = (string) $matches[0];
                return $key;
            }, $css);

            $charset = '';
            $masked_css = (string) preg_replace_callback('/@charset\s+(["\'])([^"\']+)\1\s*;/i', function ($matches) use (&$charset) {
                if ('' === $charset) {
                    $charset = '@charset "' . addcslashes((string) $matches[2], "\\\"") . '";';
                }
                return "\n";
            }, $masked_css);

            $imports = array();
            $masked_css = (string) preg_replace_callback('/@import\s+(?:url\(\s*"([^"]+)"\s*\)|url\(\s*\'([^\']+)\'\s*\)|url\(\s*([^)]+?)\s*\)|"([^"]+)"|\'([^\']+)\')([^;]*);/i', function ($matches) use (&$imports, $source_url) {
                $import_url = '';
                for ($i = 1; $i <= 5; $i++) {
                    if (isset($matches[$i]) && '' !== trim((string) $matches[$i])) {
                        $import_url = trim((string) $matches[$i]);
                        break;
                    }
                }
                $suffix = isset($matches[6]) ? trim((string) $matches[6]) : '';
                $rewritten = $this->rewrite_css_import_rule_for_bundle($import_url, $suffix, (string) $matches[0], $source_url);
                if ('' !== $rewritten) {
                    $imports[] = $rewritten;
                }
                return "\n";
            }, $masked_css);

            if (!empty($comments)) {
                $masked_css = strtr($masked_css, $comments);
            }

            return array(
                'body' => $this->rewrite_frontpage_css_urls_for_bundle($masked_css, $source_url),
                'imports' => $imports,
                'charset' => $charset,
            );
        }

        private function rewrite_css_import_rule_for_bundle($import_url, $suffix, $fallback_rule, $source_url)
        {
            $import_url = trim((string) $import_url);
            $suffix = trim((string) $suffix);
            if ('' === $import_url) {
                return trim((string) $fallback_rule);
            }

            $lower = strtolower($import_url);
            foreach (array('data:', 'blob:', 'about:', 'javascript:', '#') as $prefix) {
                if (0 === strpos($lower, $prefix)) {
                    return trim((string) $fallback_rule);
                }
            }

            $absolute = $this->absolutize_public_resource_url($import_url, $source_url);
            if ('' === $absolute) {
                return trim((string) $fallback_rule);
            }

            $rule = '@import url("' . esc_url_raw($absolute) . '")' . ('' !== $suffix ? ' ' . $suffix : '') . ';';
            if (false !== stripos($absolute, 'fonts.googleapis.com') && method_exists($this, 'rewrite_google_fonts_imports_in_css')) {
                $google_import_stats = array();
                $rewritten_rule = $this->rewrite_google_fonts_imports_in_css($rule, $source_url, $google_import_stats);
                if (is_string($rewritten_rule) && '' !== $rewritten_rule) {
                    return trim($rewritten_rule);
                }
            }

            return $rule;
        }

        private function rewrite_frontpage_css_urls_for_bundle($css, $source_url)
        {
            $css = (string) $css;
            if ('' === $css) {
                return '';
            }

            $css = (string) preg_replace_callback('/url\(([^)]+)\)/i', function ($matches) use ($source_url) {
                $raw = trim((string) $matches[1]);
                $trimmed = trim($raw, " \t\n\r\0\x0B\"'");
                if ('' === $trimmed) {
                    return (string) $matches[0];
                }

                $lower = strtolower($trimmed);
                foreach (array('data:', 'blob:', 'about:', 'javascript:', '#') as $prefix) {
                    if (0 === strpos($lower, $prefix)) {
                        return (string) $matches[0];
                    }
                }

                $absolute = $this->absolutize_public_resource_url($trimmed, $source_url);
                if ('' === $absolute) {
                    return (string) $matches[0];
                }

                $local_google_font = $this->normalize_google_fonts_cache_url_for_css($absolute);
                if ('' !== $local_google_font) {
                    return 'url("' . esc_url_raw($local_google_font) . '")';
                }

                return 'url("' . esc_url_raw($absolute) . '")';
            }, $css);

            $css = $this->normalize_google_fonts_cache_urls_in_css($css);
            $policy = $this->get_font_optimization_policy();

            return !empty($policy['bundle_font_display']) ? $this->normalize_font_face_display_in_css($css) : $css;
        }


        private function maybe_enqueue_page_css_bundle_async_on_entry(array $settings = array())
        {
            $url = $this->get_current_request_url();
            if ('' === $url || !$this->is_cacheable_local_url($url)) {
                return false;
            }

            $request_method = function_exists('ucwp_server_value') ? ucwp_server_value('REQUEST_METHOD') : '';
            $request_method = strtoupper(sanitize_text_field($request_method));
            if ('' !== $request_method && 'GET' !== $request_method) {
                return false;
            }

            if (function_exists('is_user_logged_in') && is_user_logged_in()) {
                return false;
            }

            if (!empty($this->get_frontpage_css_manifest_entry($url))) {
                return false;
            }

            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'enqueue_async_css_bundle_url')) {
                return false;
            }

            return (bool) Ultra_Cache_WP::enqueue_async_css_bundle_url($url);
        }

        private function maybe_build_page_css_bundle_on_entry($html, array $settings = array())
        {
            $url = $this->get_current_request_url();
            if ('' === $url || !$this->is_cacheable_local_url($url)) {
                return false;
            }

            $request_method = function_exists('ucwp_server_value') ? ucwp_server_value('REQUEST_METHOD') : '';
            $request_method = strtoupper(sanitize_text_field($request_method));
            if ('' !== $request_method && 'GET' !== $request_method) {
                return false;
            }

            if (function_exists('is_user_logged_in') && is_user_logged_in()) {
                return false;
            }

            if (!empty($this->get_frontpage_css_manifest_entry($url))) {
                return false;
            }

            $lock_name = 'css-entry-' . md5($this->normalize_url($url));
            if (!$this->acquire_runtime_lock($lock_name, 120)) {
                return false;
            }

            try {
                if (!empty($this->get_frontpage_css_manifest_entry($url))) {
                    return false;
                }

                $prepared = $this->build_frontpage_css_bundle_from_html((string) $html, $url, (string) ($settings['homepage_css_bundle_mode'] ?? 'safe'));
                if (empty($prepared['success'])) {
                    return false;
                }

            $manifest = $this->read_frontpage_css_manifest();
            if (!isset($manifest['entries']) || !is_array($manifest['entries'])) {
                $manifest['entries'] = array();
            }

            $entry = $this->build_frontpage_css_manifest_entry($url, $prepared);

            $key = $this->get_css_bundle_manifest_key($url);
            if ('' !== $key) {
                $manifest['version'] = 3;
                $manifest['updatedAt'] = current_time('timestamp');
                $manifest['updatedAtMysql'] = current_time('mysql');
                $manifest['entries'][$key] = $entry;
                if ($this->is_frontpage_request_url($url)) {
                    $manifest['entry'] = $entry;
                }
                $this->write_frontpage_css_manifest($manifest);
                $this->cleanup_orphan_frontpage_css_bundles($manifest);
                return true;
            }

            return false;
            } finally {
                $this->release_runtime_lock($lock_name);
            }
        }

        private function prepare_inline_css_bundle_for_style_tag($css)
        {
            $css = is_string($css) ? trim($css) : '';
            if ('' === $css) {
                return '';
            }

            // Keep inline CSS bundles safe inside an HTML <style> element. A literal
            // </style sequence inside a bundled stylesheet can prematurely close the
            // tag and leave the document malformed.
            $css = preg_replace('/<\/(style)/i', '<\\/$1', $css);
            if (!is_string($css)) {
                return '';
            }

            // @charset belongs at the top of external stylesheets. Concatenated inline
            // bundles may contain multiple declarations, so strip them for inline mode.
            $css = preg_replace('/@charset\s+["\'][^"\']+["\']\s*;/i', '', $css);
            if (!is_string($css)) {
                return '';
            }

            return trim($css);
        }

        private function maybe_replace_legacy_frontpage_stylesheet_links_with_bundle($html)
        {
            if (!is_string($html) || '' === $html || !$this->is_frontpage_request_url()) {
                return $html;
            }

            // Stage 1 is the legacy homepage-only bundle path. Do not apply it to page-entry bundles.
            $entry = $this->get_frontpage_css_manifest_entry(home_url('/'));
            if (empty($entry)) {
                return $html;
            }

            $source_urls = array();
            foreach ((array) ($entry['sourceUrls'] ?? array()) as $url) {
                $normalized = $this->absolutize_public_resource_url((string) $url, home_url('/'));
                if ('' !== $normalized) {
                    $source_urls[$normalized] = true;
                }
            }
            if (empty($source_urls)) {
                return $html;
            }

            if (!preg_match('/<head\b[^>]*>([\s\S]*?)<\/head>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
                return $html;
            }

            $head_html = (string) $matches[0][0];
            $head_offset = (int) $matches[0][1];
            $head_inner = isset($matches[1][0]) ? (string) $matches[1][0] : '';
            if (!preg_match_all('/<link\b[^>]*>/i', $head_inner, $tag_matches, PREG_OFFSET_CAPTURE)) {
                return $html;
            }

            $settings = $this->get_settings();
            $bundle_url = isset($entry['bundleUrl']) ? (string) $entry['bundleUrl'] : '';
            $bundle_file = isset($entry['bundleFile']) ? (string) $entry['bundleFile'] : (isset($entry['file']) ? (string) $entry['file'] : '');
            if ('' === $bundle_url) {
                return $html;
            }

            $frontpage_bundle_role = $this->get_generated_css_bundle_role_from_mode(isset($entry['mode']) ? (string) $entry['mode'] : 'safe');
            $replacement = '<link rel="stylesheet" id="ucwp-frontpage-css" href="' . esc_url($bundle_url) . '" data-ucwp-frontpage-css="1" data-ucwp-css-role="' . esc_attr($frontpage_bundle_role) . '" data-ucwp-css-blocking-reason="main-layout-risk" />'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
            if (!empty($settings['homepage_css_bundle_inline'])) {
                $bundle_css = ('' !== $bundle_file && is_readable($bundle_file)) ? ucwp_guarded_asset_file_get_contents($bundle_file, 'generated-css', 'frontpage_css_bundle_inline_generated_asset', false) : '';
                $bundle_css = $this->prepare_inline_css_bundle_for_style_tag($bundle_css);
                if ('' !== $bundle_css) {
                    $replacement = '<style id="ucwp-frontpage-css" data-ucwp-frontpage-css="1" data-ucwp-css-role="' . esc_attr($frontpage_bundle_role) . '" data-ucwp-css-blocking-reason="main-layout-risk">' . $bundle_css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
            }
            $delayed_font_markup = $this->build_delayed_icon_fonts_stylesheet_markup($entry, 'ucwp-frontpage-delayed-icon-fonts');
            if ('' !== $delayed_font_markup) {
                $replacement .= "\n" . $delayed_font_markup;
            }

            $rebuilt_head = '';
            $cursor = 0;
            $matched = 0;
            foreach ($tag_matches[0] as $match) {
                $tag_html = (string) $match[0];
                $start = (int) $match[1];
                $end = $start + strlen($tag_html);

                if (!$this->html_tag_rel_contains_stylesheet($tag_html)) {
                    continue;
                }

                $href = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag_html, 'href'), ENT_QUOTES);
                if ('' === $href) {
                    continue;
                }

                $absolute_url = $this->absolutize_public_resource_url($href, home_url('/'));
                if ('' === $absolute_url || !isset($source_urls[$absolute_url])) {
                    continue;
                }

                $rebuilt_head .= substr($head_inner, $cursor, $start - $cursor);
                if (0 === $matched) {
                    $rebuilt_head .= $replacement;
                }
                $cursor = $end;
                $matched++;
            }

            if ($matched <= 0) {
                return $html;
            }

            $rebuilt_head .= substr($head_inner, $cursor);
            $updated_head = preg_replace('/<head\b([^>]*)>[\s\S]*<\/head>/i', '<head$1>' . $rebuilt_head . '</head>', $head_html, 1);
            if (!is_string($updated_head) || '' === $updated_head) {
                return $html;
            }

            return substr($html, 0, $head_offset) . $updated_head . substr($html, $head_offset + strlen($head_html));
        }

        private function maybe_replace_page_stylesheet_links_with_bundle($html, $entry_url = '')
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            // Do not stack bundle injections on already-processed HTML.
            if (false !== stripos($html, 'data-ucwp-page-css-bundle=') || false !== stripos($html, 'id="ucwp-page-css-bundle"')) {
                return $html;
            }

            $current_url = $this->get_current_request_url();
            $entry_url = '' !== (string) $entry_url ? (string) $entry_url : $current_url;
            $entry = $this->get_frontpage_css_manifest_entry($entry_url);
            if (empty($entry)) {
                return $html;
            }

            $bundle_file = isset($entry['bundleFile']) ? (string) $entry['bundleFile'] : '';
            $bundle_url = isset($entry['bundleUrl']) ? (string) $entry['bundleUrl'] : '';
            if ('' === $bundle_file || !is_readable($bundle_file) || '' === $bundle_url) {
                return $html;
            }

            $source_urls = array();
            foreach ((array) ($entry['sourceUrls'] ?? array()) as $url) {
                $normalized = $this->absolutize_public_resource_url((string) $url, home_url('/'));
                if ('' !== $normalized) {
                    $source_urls[$normalized] = true;
                }
            }
            if (empty($source_urls)) {
                return $html;
            }

            if (!preg_match('/<head\\b[^>]*>([\\s\\S]*?)<\\/head>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
                return $html;
            }

            $head_inner = isset($matches[1][0]) ? (string) $matches[1][0] : '';
            $head_inner_offset = isset($matches[1][1]) ? (int) $matches[1][1] : -1;
            if ('' === $head_inner || $head_inner_offset < 0 || !preg_match_all('/<link\\b[^>]*>/i', $head_inner, $tag_matches, PREG_OFFSET_CAPTURE)) {
                return $html;
            }

            $matched_tags = array();
            foreach ($tag_matches[0] as $match) {
                $tag_html = (string) $match[0];
                $start = (int) $match[1];
                $end = $start + strlen($tag_html);

                if (!$this->html_tag_rel_contains_stylesheet($tag_html)) {
                    continue;
                }

                $href = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag_html, 'href'), ENT_QUOTES);
                if ('' === $href) {
                    continue;
                }

                $absolute_url = $this->absolutize_public_resource_url($href, '' !== $current_url ? $current_url : home_url('/'));
                if ('' === $absolute_url || !isset($source_urls[$absolute_url])) {
                    continue;
                }

                $matched_tags[] = array(
                    'html' => $tag_html,
                    'start' => $start,
                    'end' => $end,
                    'url' => $absolute_url,
                );
            }

            if (empty($matched_tags)) {
                return $html;
            }

            $settings = $this->get_settings();
            $mode = isset($entry['mode']) && 'aggressive' === strtolower((string) $entry['mode']) ? 'aggressive' : (isset($entry['mode']) && 'full' === strtolower((string) $entry['mode']) ? 'full' : 'safe');
            $page_bundle_role = $this->get_generated_css_bundle_role_from_mode($mode);
            $replacement = '<link rel="stylesheet" id="ucwp-page-css-bundle" href="' . esc_url($bundle_url) . '" data-ucwp-page-css-bundle="1" data-ucwp-css-role="' . esc_attr($page_bundle_role) . '" data-ucwp-css-blocking-reason="main-layout-risk" />'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
            if (!empty($settings['homepage_css_bundle_inline'])) {
                $maybe_css = ucwp_guarded_asset_file_get_contents($bundle_file, 'generated-css', 'page_css_bundle_inline_generated_asset', false);
                $bundle_css = $this->prepare_inline_css_bundle_for_style_tag($maybe_css);
                if ('' !== $bundle_css) {
                    $replacement = '<style id="ucwp-page-css-bundle" data-ucwp-page-css-bundle="1" data-ucwp-css-role="' . esc_attr($page_bundle_role) . '" data-ucwp-css-blocking-reason="main-layout-risk">' . $bundle_css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
            }
            $delayed_font_markup = $this->build_delayed_icon_fonts_stylesheet_markup($entry, 'ucwp-page-delayed-icon-fonts');
            if ('' !== $delayed_font_markup) {
                $replacement .= "\n" . $delayed_font_markup;
            }

            $rebuilt_head = '';
            $cursor = 0;
            $inserted = false;
            foreach ($matched_tags as $tag) {
                $start = (int) $tag['start'];
                $end = (int) $tag['end'];
                $rebuilt_head .= substr($head_inner, $cursor, $start - $cursor);
                if (!$inserted) {
                    // Safe mode is conservative replacement, not duplicate injection: only the
                    // exact stylesheet links recorded in the bundle manifest are removed, while all
                    // unmatched/excluded/runtime/protected stylesheets remain as normal links. This
                    // gives the safe bundle a real request-reduction effect without touching assets
                    // that were not bundled. Aggressive mode uses the same manifest-based removal,
                    // with a broader eligibility set at bundle-build time.
                    $rebuilt_head .= $replacement . "\n";
                    $inserted = true;
                }
                $cursor = $end;
            }
            $rebuilt_head .= substr($head_inner, $cursor);

            if (!$inserted || '' === $rebuilt_head) {
                return $html;
            }

            return substr($html, 0, $head_inner_offset) . $rebuilt_head . substr($html, $head_inner_offset + strlen($head_inner));
        }

        private function get_leftover_css_bundle_default_stats()
        {
            return array(
                'enabled' => true,
                'success' => false,
                'candidate_count' => 0,
                'replaced_link_count' => 0,
                'skipped_protected_count' => 0,
                'skipped_nonlocal_count' => 0,
                'skipped_unreadable_count' => 0,
                'skipped_async_count' => 0,
                'skipped_media_count' => 0,
                'skipped_existing_bundle_count' => 0,
                'skipped_reason' => '',
                'bundle_url' => '',
                'bundle_file' => '',
                'bundle_bytes' => 0,
                'source_bytes_total' => 0,
                'source_urls' => array(),
                'protected_urls' => array(),
            );
        }

        private function record_leftover_css_bundle_profile(array $stats)
        {
            if (!$this->is_store_profiler_enabled()) {
                return;
            }
            $this->store_profile['leftover_css_bundle'] = $stats;
        }

        private function get_leftover_css_bundle_candidate_from_tag($tag_html, $page_url, array $settings = array())
        {
            $tag_html = (string) $tag_html;
            if (!$this->html_tag_rel_contains_stylesheet($tag_html)) {
                return array('asset' => array(), 'skip' => 'not-stylesheet');
            }

            if (false !== stripos($tag_html, 'data-ucwp-frontpage-css=') || false !== stripos($tag_html, 'data-ucwp-page-css-bundle=') || false !== stripos($tag_html, 'data-ucwp-leftover-css-bundle=') || false !== stripos($tag_html, 'data-ucwp-async-css=')) {
                return array('asset' => array(), 'skip' => 'existing-bundle');
            }

            foreach (array('onload', 'disabled', 'data-href', 'data-src') as $attribute) {
                if (preg_match('/\b' . preg_quote($attribute, '/') . '\b/i', $tag_html)) {
                    return array('asset' => array(), 'skip' => 'async');
                }
            }

            $href = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag_html, 'href'), ENT_QUOTES | ENT_HTML5);
            if ('' === $href) {
                return array('asset' => array(), 'skip' => 'unresolved');
            }

            $media = strtolower(trim((string) $this->extract_attribute_from_html_tag($tag_html, 'media')));
            if (!$this->is_homepage_css_bundle_allowed_media($media, 'leftover')) {
                return array('asset' => array(), 'skip' => 'media');
            }

            $absolute_url = $this->absolutize_public_resource_url($href, '' !== (string) $page_url ? (string) $page_url : home_url('/'));
            if ('' === $absolute_url) {
                return array('asset' => array(), 'skip' => 'unresolved');
            }

            if ($this->should_exclude_stylesheet_url_by_fragments($absolute_url, $this->get_homepage_css_bundle_exclude_fragments())) {
                return array('asset' => array(), 'skip' => 'protected', 'url' => $absolute_url, 'reason' => __('CSS Bundle Exclusions matched', 'ultracache'));
            }

            $slider_fragment = !empty($settings['slider_safe_mode']) ? $this->get_matching_fragment('', $absolute_url, $tag_html, $this->get_slider_hero_protected_fragments()) : '';
            if ('' !== $slider_fragment) {
                return array('asset' => array(), 'skip' => 'protected', 'url' => $absolute_url, 'reason' => sprintf(
					/* translators: %s: matched slider/hero stylesheet fragment. */
					__('slider/hero stylesheet fragment: %s', 'ultracache'),
					$slider_fragment
				));
            }

            $host = (string) wp_parse_url($absolute_url, PHP_URL_HOST);
            $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            if ('' === $host || '' === $home_host || strtolower($host) !== strtolower($home_host)) {
                return array('asset' => array(), 'skip' => 'nonlocal');
            }

            $path = (string) wp_parse_url($absolute_url, PHP_URL_PATH);
            if ('' === $path || '.css' !== strtolower(substr($path, -4))) {
                return array('asset' => array(), 'skip' => 'nonlocal');
            }
            if (false !== strpos(strtolower($path), '/cache/ultracache/css-bundles/')) {
                return array('asset' => array(), 'skip' => 'existing-bundle');
            }

            $local_path = $this->resolve_local_path_from_public_url($absolute_url);
            if ('' === $local_path || !is_readable($local_path)) {
                return array('asset' => array(), 'skip' => 'unreadable');
            }

            return array(
                'asset' => array(
                    'url' => $absolute_url,
                    'path' => $local_path,
                ),
                'skip' => '',
            );
        }

        private function maybe_consolidate_leftover_stylesheet_links($html, array $settings = array())
        {
            $stats = $this->get_leftover_css_bundle_default_stats();
            if (empty($settings['leftover_css_bundle'])) {
                $stats['enabled'] = false;
                $stats['skipped_reason'] = 'disabled';
                $this->record_leftover_css_bundle_profile($stats);
                return $html;
            }

            if (!is_string($html) || '' === $html || false === stripos($html, '<link')) {
                $stats['skipped_reason'] = 'no-html-or-links';
                $this->record_leftover_css_bundle_profile($stats);
                return $html;
            }

            if (false !== stripos($html, 'data-ucwp-leftover-css-bundle=')) {
                $stats['skipped_reason'] = 'already-applied';
                $this->record_leftover_css_bundle_profile($stats);
                return $html;
            }

            if (!preg_match_all('/<link\b[^>]*>/i', $html, $tag_matches, PREG_OFFSET_CAPTURE)) {
                $stats['skipped_reason'] = 'no-link-tags';
                $this->record_leftover_css_bundle_profile($stats);
                return $html;
            }

            $page_url = $this->get_current_request_url();
            $assets = array();
            $matched_tags = array();
            $seen = array();
            foreach ($tag_matches[0] as $match) {
                $tag_html = (string) $match[0];
                $start = (int) $match[1];
                $end = $start + strlen($tag_html);
                $candidate = $this->get_leftover_css_bundle_candidate_from_tag($tag_html, $page_url, $settings);
                $asset = isset($candidate['asset']) && is_array($candidate['asset']) ? $candidate['asset'] : array();
                $skip = isset($candidate['skip']) ? (string) $candidate['skip'] : '';

                if (empty($asset)) {
                    switch ($skip) {
                        case 'protected':
                            $stats['skipped_protected_count']++;
                            if (!empty($candidate['url']) && count($stats['protected_urls']) < 20) {
                                $stats['protected_urls'][] = array(
                                    'url' => (string) $candidate['url'],
                                    'reason' => isset($candidate['reason']) ? (string) $candidate['reason'] : 'protected',
                                );
                            }
                            break;
                        case 'nonlocal':
                            $stats['skipped_nonlocal_count']++;
                            break;
                        case 'unreadable':
                            $stats['skipped_unreadable_count']++;
                            break;
                        case 'async':
                            $stats['skipped_async_count']++;
                            break;
                        case 'media':
                            $stats['skipped_media_count']++;
                            break;
                        case 'existing-bundle':
                            $stats['skipped_existing_bundle_count']++;
                            break;
                    }
                    continue;
                }

                $url = (string) ($asset['url'] ?? '');
                if ('' === $url || isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $assets[] = $asset;
                $matched_tags[] = array(
                    'start' => $start,
                    'end' => $end,
                    'url' => $url,
                );
            }

            $stats['candidate_count'] = count($assets);
            $stats['source_urls'] = array_values(array_map('strval', array_keys($seen)));

            if (count($assets) < 2 || count($matched_tags) < 2) {
                $stats['skipped_reason'] = 'not-enough-eligible-leftover-css';
                $this->record_leftover_css_bundle_profile($stats);
                return $html;
            }

            $bundle = $this->build_frontpage_css_bundle_file($page_url, $assets, 'leftover');
            if (empty($bundle['success'])) {
                $stats['skipped_reason'] = !empty($bundle['message']) ? (string) $bundle['message'] : 'bundle-build-failed';
                $this->record_leftover_css_bundle_profile($stats);
                return $html;
            }

            $bundle_url = isset($bundle['url']) ? (string) $bundle['url'] : '';
            $bundle_file = isset($bundle['file']) ? (string) $bundle['file'] : '';
            if ('' === $bundle_url || '' === $bundle_file || !is_readable($bundle_file)) {
                $stats['skipped_reason'] = 'bundle-file-unreadable';
                $this->record_leftover_css_bundle_profile($stats);
                return $html;
            }

            $replacement = '<link rel="stylesheet" id="ucwp-leftover-css-bundle" href="' . esc_url($bundle_url) . '" data-ucwp-leftover-css-bundle="1" data-ucwp-css-role="leftover-bundle" />'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
            $delayed_font_markup = $this->build_delayed_icon_fonts_stylesheet_markup($bundle, 'ucwp-leftover-delayed-icon-fonts');
            if ('' !== $delayed_font_markup) {
                $replacement .= "\n" . $delayed_font_markup;
            }
            $rebuilt = '';
            $cursor = 0;
            $inserted = false;
            $replaced = 0;
            foreach ($matched_tags as $tag) {
                $start = (int) $tag['start'];
                $end = (int) $tag['end'];
                if ($start < $cursor) {
                    continue;
                }
                $rebuilt .= substr($html, $cursor, $start - $cursor);
                if (!$inserted) {
                    $rebuilt .= $replacement . "\n";
                    $inserted = true;
                }
                $cursor = $end;
                $replaced++;
            }
            $rebuilt .= substr($html, $cursor);

            if (!$inserted || $replaced < 2 || '' === $rebuilt) {
                $stats['skipped_reason'] = 'replacement-failed';
                $this->record_leftover_css_bundle_profile($stats);
                return $html;
            }

            $stats['success'] = true;
            $stats['replaced_link_count'] = $replaced;
            $stats['bundle_url'] = $bundle_url;
            $stats['bundle_file'] = $bundle_file;
            $stats['bundle_bytes'] = is_readable($bundle_file) ? (int) filesize($bundle_file) : 0;
            $stats['source_bytes_total'] = isset($bundle['sourceBytesTotal']) ? (int) $bundle['sourceBytesTotal'] : 0;
            $stats['source_details'] = isset($bundle['sourceDetails']) && is_array($bundle['sourceDetails']) ? $bundle['sourceDetails'] : array();
            $this->record_leftover_css_bundle_profile($stats);

            return $rebuilt;
        }

    }
}
