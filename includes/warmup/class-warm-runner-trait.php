<?php
/**
 * Warm crawl runner and loopback execution.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Warm_Runner_Trait
{
        public function handle_woocommerce_object_update($object)
        {
            $post_id = 0;

            if (is_numeric($object)) {
                $post_id = (int) $object;
            } elseif (is_object($object) && method_exists($object, 'get_id')) {
                $post_id = (int) $object->get_id();
            }

            if ($post_id <= 0) {
                return;
            }

            $this->handle_post_update($post_id);

            if (function_exists('wp_get_post_parent_id')) {
                $parent_id = (int) wp_get_post_parent_id($post_id);
                if ($parent_id > 0) {
                    $this->handle_post_update($parent_id);
                }
            }
        }
        public function handle_post_update($post_id)
        {
            $post_id = (int) $post_id;
            if ($post_id <= 0 || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
                return;
            }

            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }

            $post = get_post($post_id);
            if (!$post || 'auto-draft' === $post->post_status || 'revision' === $post->post_type) {
                return;
            }

            $this->purge_related_post_urls($post_id);

            $settings = $this->get_settings();
            if (!empty($settings['preload_on_save']) && 'publish' === $post->post_status && $this->acquire_post_save_warm_cooldown($post_id)) {
                foreach ($this->get_urls_to_warm_for_post($post_id) as $url) {
                    $this->warm_url(
                        $url,
                        array(
                            'skip_css_bundle' => true,
                            'buckets'         => array('orig'),
                            'source'          => 'post-save',
                        )
                    );
                }
            }
        }
        public function handle_post_deletion($post_id)
        {
            $this->purge_related_post_urls((int) $post_id);
        }
        public function handle_term_update($term_id, $tt_id = 0, $taxonomy = '')
        {
            $term_id = (int) $term_id;
            $taxonomy = is_string($taxonomy) ? $taxonomy : '';
            if ($term_id <= 0 || '' === $taxonomy) {
                return;
            }

            $this->purge_urls(
                $this->get_related_urls_for_term($term_id, $taxonomy),
                'related-term',
                array(
                    'term_id'  => $term_id,
                    'taxonomy' => $taxonomy,
                )
            );
        }
        public function handle_object_terms_set($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids = array())
        {
            $object_id = (int) $object_id;
            $taxonomy = is_string($taxonomy) ? $taxonomy : '';
            if ($object_id <= 0 || '' === $taxonomy || wp_is_post_revision($object_id)) {
                return;
            }

            $taxonomy_object = get_taxonomy($taxonomy);
            if (!$taxonomy_object || empty($taxonomy_object->public)) {
                return;
            }

            $post = get_post($object_id);
            if (!$post) {
                return;
            }

            $urls = $this->get_related_urls_for_post($object_id);
            $term_ids = array_map('intval', (array) $terms);

            foreach ((array) $old_tt_ids as $old_tt_id) {
                $old_term = get_term_by('term_taxonomy_id', (int) $old_tt_id, $taxonomy);
                if ($old_term && !is_wp_error($old_term)) {
                    $term_ids[] = (int) $old_term->term_id;
                }
            }

            $term_ids = array_values(array_unique(array_filter($term_ids)));
            foreach ($term_ids as $term_id) {
                $urls = array_merge($urls, $this->get_related_urls_for_term($term_id, $taxonomy));
            }

            $this->purge_urls(
                $urls,
                'term-assignment',
                array(
                    'post_id'  => $object_id,
                    'taxonomy' => $taxonomy,
                )
            );
        }
        public function handle_navigation_update($menu_id = 0, $menu_data = array())
        {
            $this->purge_urls(
                $this->get_site_front_urls(true),
                'navigation',
                array(
                    'menu_id' => (int) $menu_id,
                )
            );
        }
        public function handle_sidebars_widgets_update($old_value, $value)
        {
            if ($old_value === $value) {
                return;
            }

            $this->purge_urls($this->get_site_front_urls(true), 'widgets');
        }
        public function handle_front_page_option_change($old_value = null, $value = null)
        {
            if ((string) $old_value === (string) $value) {
                return;
            }

            $this->purge_urls($this->get_site_front_urls(true), 'front-settings');
        }
        public function handle_global_frontend_change()
        {
            $this->clear_runtime_font_css_map_cache();
            $this->purge_urls($this->get_site_front_urls(true), 'global-front');
        }
        public function pre_render_page($post_id)
        {
            $post_id = (int) $post_id;
            if ($post_id <= 0 || wp_is_post_revision($post_id)) {
                return false;
            }

            $url = get_permalink($post_id);
            if (!$url) {
                return false;
            }

            $result = $this->warm_url($url);
            return !empty($result['success']);
        }
        private function is_html_loopback_response($response, $body = '')
        {
            $content_type = strtolower(trim((string) wp_remote_retrieve_header($response, 'content-type')));
            if ('' !== $content_type) {
                return false !== strpos($content_type, 'text/html') || false !== strpos($content_type, 'application/xhtml+xml');
            }

            $sample = ltrim((string) $body);
            if ('' === $sample) {
                return false;
            }

            $prefix = strtolower(substr($sample, 0, 512));
            return 0 === strpos($prefix, '<!doctype html') || 0 === strpos($prefix, '<html') || false !== strpos($prefix, '<html');
        }
        private function should_verify_loopback_ssl($url)
        {
            return !function_exists('ultracache_is_local_https_url') || !ultracache_is_local_https_url($url);
        }
        private function get_loopback_cache_status($response)
        {
            $header = wp_remote_retrieve_header($response, 'x-ultra-cache');
            $values = is_array($header) ? $header : explode(',', (string) $header);
            $status = '';

            foreach ($values as $value) {
                $candidate = strtoupper(trim((string) $value));
                if (in_array($candidate, array('BYPASS', 'SKIP', 'MISS', 'STORE', 'HIT', 'STALE'), true)) {
                    $status = $candidate;
                }
            }

            return $status;
        }
        private function get_loopback_cache_reason($response)
        {
            $header = wp_remote_retrieve_header($response, 'x-ultra-cache-reason');
            if (is_array($header)) {
                $header = end($header);
            }

            return sanitize_key((string) $header);
        }
        private function get_loopback_cache_rejection_message($response)
        {
            $status = $this->get_loopback_cache_status($response);
            if (!in_array($status, array('BYPASS', 'SKIP'), true)) {
                return '';
            }

            $reason = $this->get_loopback_cache_reason($response);

            if ('set-cookie-woocommerce_recently_viewed' === $reason) {
                // The authenticated internal warm request stores only the response body.
                // WooCommerce's visitor-only recently-viewed cookie must not reject the
                // page cache warm when older WooCommerce versions do not expose the
                // woocommerce_set_cookie_enabled filter.
                return '';
            }

            if ('write-failed' === $reason) {
                // Manual warm-up performs its own controlled cache-file write from the
                // fetched HTML below. A frontend loopback storage miss should not stop
                // the manual warm-up when the response itself is a cacheable 200 HTML page.
                return '';
            }

            if ('' !== $reason) {
                /* translators: %s: cache bypass reason. */
                $message = __('Page is not cacheable (%s).', 'ultracache');
                return sprintf($message, $reason);
            }

            return __('Page is not cacheable.', 'ultracache');
        }
        private function get_runtime_locks_dir()
        {
            return trailingslashit(ULTRACACHE_CACHE_DIR) . 'locks/';
        }
        private function is_runtime_lock_file_path($file)
        {
            $file = wp_normalize_path((string) $file);
            if ('' === $file || is_link($file) || '.lock' !== substr($file, -5)) {
                return false;
            }

            $dir = wp_normalize_path($this->get_runtime_locks_dir());
            if (function_exists('ultracache_path_has_dir_prefix')) {
                if (!ultracache_path_has_dir_prefix($file, $dir)) {
                    return false;
                }
            } elseif (0 !== strpos($file, trailingslashit($dir))) {
                return false;
            }

            $base = basename($file);
            return (bool) preg_match('/^(?:purge-all|page-cache-write-[a-f0-9]{32}|page-cache-build-(?:[a-f0-9]{32}|slot-[0-9]+)|css-(?:bundle|entry)-[a-f0-9]{32})\.lock$/i', $base);
        }
        private function delete_runtime_lock_file($file, $context = 'runtime_lock_release')
        {
            $file = wp_normalize_path((string) $file);
            if ('' === $file || !$this->is_runtime_lock_file_path($file)) {
                if (function_exists('ultracache_debug_log')) {
                    ultracache_debug_log('runtime lock delete blocked: invalid path', array(
                        'path' => $file,
                        'context' => (string) $context,
                    ));
                }
                return false;
            }

            if (!file_exists($file)) {
                return true;
            }

            $deleted = ultracache_safe_unlink($file, (string) $context);
            clearstatcache(true, $file);
            if (!$deleted && file_exists($file)) {
                $dir = dirname($file);
                $this->record_cache_event('runtime-lock-delete-failed', array(
                    'context' => (string) $context,
                    'file' => basename($file),
                    'path' => $file,
                    'dirWritable' => function_exists('ultracache_path_is_writable') ? (ultracache_path_is_writable($dir) ? 'yes' : 'no') : 'unknown',
                ));
                if (function_exists('ultracache_debug_log')) {
                    ultracache_debug_log('runtime lock delete failed', array(
                        'path' => $file,
                        'context' => (string) $context,
                    ));
                }
                return false;
            }

            return true;
        }
        private function acquire_runtime_lock($name, $ttl = 180)
        {
            $name = (string) $name;
            if ('' === $name) {
                return false;
            }

            $this->maybe_cleanup_stale_runtime_locks();

            $file = $this->get_runtime_lock_file($name);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Runtime lock requires native fopen/flock semantics and is limited to UltraCache cache storage.
            $handle = @fopen($file, 'c+');
            if (!$handle) {
                return false;
            }

            if (!@flock($handle, LOCK_EX | LOCK_NB)) {
                // Do not refresh the marker mtime for locks we do not own. Touching
                // another worker's marker made old page-cache-build locks look new
                // during diagnostics and could delay stale-lock maintenance.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing native lock handle.
                @fclose($handle);
                return false;
            }

            @ftruncate($handle, 0);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes only to a path-guarded UltraCache runtime lock file.
            @fwrite($handle, (string) time());
            @fflush($handle);
            $this->runtime_locks[$name] = $handle;
            return true;
        }
        private function maybe_cleanup_stale_runtime_locks()
        {
            static $checked = false;
            if ($checked) {
                return 0;
            }
            $checked = true;

            // Keep the normal HIT/MISS path cheap. Locks are also deleted on normal release;
            // this maintenance only handles orphaned files left by timeouts/fatals/killed PHP workers.
            if (wp_rand(1, 200) !== 1 && '1' !== sanitize_text_field(ultracache_query_value('ultracache_lock_maintenance'))) {
                return 0;
            }

            return $this->cleanup_stale_runtime_locks(2 * HOUR_IN_SECONDS, 250);
        }
        private function cleanup_stale_runtime_locks($age_seconds = 7200, $max_delete = 250)
        {
            $dir = $this->get_runtime_locks_dir();
            if (!is_dir($dir) || !is_readable($dir)) {
                return 0;
            }

            $age_seconds = max(300, (int) $age_seconds);
            $max_delete = max(1, min(1000, (int) $max_delete));
            $now = time();
            $deleted = 0;
            $files = (array) glob(trailingslashit($dir) . '*.lock');

            foreach ($files as $file) {
                $file = wp_normalize_path((string) $file);
                if (!$this->is_runtime_lock_file_path($file) || !is_file($file)) {
                    continue;
                }
                $mtime = ultracache_safe_filemtime($file, 'runtime_lock_cleanup');
                if (!$mtime || ($now - (int) $mtime) < $age_seconds) {
                    continue;
                }

                $handle = false;
                $locked = true;
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native flock probe is required to avoid deleting active runtime lock markers. Path is restricted to UltraCache locks/.
                $handle = @fopen($file, 'c+');
                if ($handle) {
                    $locked = !@flock($handle, LOCK_EX | LOCK_NB);
                }

                if ($locked) {
                    if ($handle) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing native flock probe handle.
                        @fclose($handle);
                    }
                    continue;
                }

                if ($handle) {
                    @flock($handle, LOCK_UN);
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing native flock probe handle before WP-safe deletion.
                    @fclose($handle);
                }

                if ($this->delete_runtime_lock_file($file, 'runtime_lock_cleanup')) {
                    $deleted++;
                }
                if ($deleted >= $max_delete) {
                    break;
                }
            }

            if ($deleted > 0) {
                $this->record_cache_event('runtime-lock-cleanup', array(
                    'deleted' => $deleted,
                    'age_seconds' => $age_seconds,
                ));
            }

            return $deleted;
        }
        private function release_runtime_lock($name, $delete_file = true)
        {
            $name = (string) $name;
            if (empty($this->runtime_locks[$name])) {
                return;
            }

            $handle = $this->runtime_locks[$name];
            $file = $delete_file ? $this->get_runtime_lock_file($name) : '';

            @fflush($handle);
            @flock($handle, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing native lock handle before WP-safe marker deletion.
            @fclose($handle);
            unset($this->runtime_locks[$name]);

            if ($delete_file && '' !== $file && is_string($file)) {
                $this->delete_runtime_lock_file($file, 'runtime_lock_release');
            }
        }
        private function is_authenticated_ultracache_internal_warm_request()
        {
            return function_exists('ultracache_is_authenticated_internal_request')
                && ultracache_is_authenticated_internal_request('warm');
        }

        /**
         * Prevent WooCommerce's recently-viewed response cookie from making an
         * authenticated internal warm response uncacheable.
         *
         * @param bool   $enabled Whether WooCommerce may set the cookie.
         * @param string $name    Cookie name.
         * @return bool
         */
        public function filter_internal_warm_woocommerce_cookie($enabled, $name = '')
        {
            if (!$enabled || 'woocommerce_recently_viewed' !== (string) $name) {
                return (bool) $enabled;
            }

            return $this->is_authenticated_ultracache_internal_warm_request() ? false : (bool) $enabled;
        }

        private function is_ultracache_internal_loopback_request()
        {
            return function_exists('ultracache_is_authenticated_internal_request')
                && ultracache_is_authenticated_internal_request();
        }
        private function get_allowed_warm_buckets(array $settings = array())
        {
            $settings = is_array($settings) ? $settings : array();
            $media_rewrite_enabled = !empty($settings['media_optimization_enabled']);
            $mode = isset($settings['media_output_mode']) ? strtolower(trim((string) $settings['media_output_mode'])) : 'webp';
            $mode = in_array($mode, array('avif', 'webp'), true) ? $mode : 'webp';
            $fallback = isset($settings['media_fallback_format']) ? strtolower(trim((string) $settings['media_fallback_format'])) : 'original';
            $fallback = ('avif' === $mode && 'webp' === $fallback) ? 'webp' : 'original';

            $buckets = array('orig');
            if (!$media_rewrite_enabled) {
                return $buckets;
            }

            if ('avif' === $mode) {
                $buckets[] = 'avif';
                if ('webp' === $fallback) {
                    $buckets[] = 'webp';
                }
            } elseif ('webp' === $mode) {
                $buckets[] = 'webp';
            }

            return $buckets;
        }
        public function warm_url($url, array $args = array())
        {
            $args = is_array($args) ? $args : array();
            $heartbeat = isset($args['_warm_pipeline_heartbeat']) && is_callable($args['_warm_pipeline_heartbeat'])
                ? $args['_warm_pipeline_heartbeat']
                : null;
            unset($args['_warm_pipeline_heartbeat']);
            $run_heartbeat = static function ($stage) use ($heartbeat) {
                if (!is_callable($heartbeat)) {
                    return true;
                }
                try {
                    return false !== call_user_func($heartbeat, sanitize_key((string) $stage));
                } catch (Throwable $error) {
                    unset($error);
                    return false;
                }
            };
            $ownership_lost_result = static function ($url, array $buckets = array(), array $files = array()) {
                return array(
                    'success' => false,
                    'cached' => false,
                    'skipped' => false,
                    'retryable' => true,
                    'terminal' => false,
                    'ownershipLost' => true,
                    'failureClass' => 'ownership-lost',
                    'url' => esc_url_raw((string) $url),
                    'message' => __('Warm-up ownership expired or was transferred before the current HTML stage completed.', 'ultracache'),
                    'files' => array_values($files),
                    'buckets' => array_values($buckets),
                );
            };
            $ignore_runtime_bypass = !empty($args['ignore_runtime_bypass']);
            $force_refresh = !empty($args['force_refresh']);
            $settings_for_warm = $this->get_settings();
            $operation_budget = function_exists('ultracache_get_safe_operation_budget') ? ultracache_get_safe_operation_budget('warm_url', $args['time_budget'] ?? null, 45) : array();
            $url = esc_url_raw((string) $url);
            if (!$this->is_cacheable_local_url($url)) {
                $result = array(
                    'success' => false,
                    'cached'  => false,
                    'url'     => $url,
                    'message' => __('Only local site URLs can be warmed.', 'ultracache'),
                    'files'   => array(),
                );
                $this->record_analytics_warm($url, $result);
                return $result;
            }

            if ($this->should_bypass_preload_url($url, array('ignore_runtime_bypass' => $ignore_runtime_bypass))) {
                $bypass_reason = (string) $this->last_bypass_reason;
                $bypass_message = 'URL is configured to bypass cache: ' . $bypass_reason;
                if ('donotcachepage' === strtolower($bypass_reason)) {
                    $bypass_message .= ' (DONOTCACHEPAGE was set during the warm request; this is commonly caused by debugging/logged-in tooling such as Query Monitor, admin-bar integrations, or plugins that intentionally bypass page cache.)';
                }
                $result = array(
                    'success' => false,
                    'cached'  => false,
                    'skipped' => true,
                    'url'     => $url,
                    'message' => $bypass_message,
                    'files'   => array(),
                );
                $this->record_analytics_warm($url, $result);
                return $result;
            }

            $bucket_priority = $this->get_allowed_warm_buckets($settings_for_warm);
            $requested_buckets = isset($args['buckets']) && is_array($args['buckets']) ? $args['buckets'] : $bucket_priority;
            $buckets = array_values(array_unique(array_intersect($bucket_priority, array_map('strval', $requested_buckets))));
            if (empty($buckets)) {
                $buckets = array('orig');
            }

            $skip_css_bundle = !empty($args['skip_css_bundle']);
            $css_bundle_requested = !$skip_css_bundle && !empty($args['build_css_bundle']);
            $css_bundle_auto_warm = !$skip_css_bundle
                && !$css_bundle_requested
                && !empty($settings_for_warm['homepage_css_bundle']);
            $css_bundle_result = array();
            $css_bundle_build_attempted = false;
            $css_bundle_build_required = $css_bundle_requested || $css_bundle_auto_warm;

            $cached_files = array();
            $last_error = '';
            $retryable_failure = false;
            $terminal_failure = false;
            $internal_request_token = function_exists('ultracache_create_runtime_control_token')
                ? ultracache_create_runtime_control_token()
                : '';
            $force_refresh_details = array();
            $force_refresh_reached_bucket_count = 0;
            if ('' === $internal_request_token) {
                $result = array(
                    'success' => false,
                    'cached'  => false,
                    'url'     => $url,
                    'message' => __('Could not authenticate the internal cache warm request.', 'ultracache'),
                    'files'   => array(),
                    'buckets' => $buckets,
                );
                $this->record_analytics_warm($url, $result);
                return $result;
            }

            foreach ($buckets as $bucket) {
                $request_url = $url;
                if ($force_refresh) {
                    $request_nonce = function_exists('wp_generate_uuid4')
                        ? str_replace('-', '', wp_generate_uuid4())
                        : wp_generate_password(24, false, false);
                    $request_url = add_query_arg(
                        array(
                            'ultracache_revalidate' => '1',
                            'ultracache_rt'         => $internal_request_token,
                            'ultracache_rv'         => substr((string) $request_nonce, 0, 32),
                            'ultracache_bucket'     => sanitize_key((string) $bucket),
                        ),
                        $url
                    );
                }
                if (!$run_heartbeat('html-bucket-' . sanitize_key((string) $bucket) . '-before')) {
                    return $ownership_lost_result($url, $buckets, $cached_files);
                }
                $pause_reason = function_exists('ultracache_operation_pause_reason') ? ultracache_operation_pause_reason($operation_budget) : '';
                if ('' !== $pause_reason) {
                    $last_error = 'Warm paused by ' . $pause_reason . '.';
                    $retryable_failure = true;
                    break;
                }
                $accept_header = $this->get_accept_header_for_bucket($bucket);
                $request_args = array(
                    'method'      => 'GET',
                    'timeout'     => 10,
                    'redirection' => 0,
                    'sslverify'   => $this->should_verify_loopback_ssl($url),
                    'user-agent'  => 'Mozilla/5.0 (compatible; UltraCache-Warm/' . ULTRACACHE_VERSION . '; +https://wordpress.org)',
                    'headers'     => array_filter(
                        array(
                            'Accept'                          => $accept_header,
                            'PageSpeed'                       => 'off',
                            'ModPagespeed'                    => 'off',
                            'X-UltraCache-Warm'               => '1',
                            'X-UltraCache-Internal-Request'   => '1',
                            'X-UltraCache-Force-Refresh'      => $force_refresh ? '1' : '',
                            'X-UltraCache-Revalidate'         => $force_refresh ? '1' : '',
                            'X-UltraCache-Token'              => $internal_request_token,
                            'X-UltraCache-VCL-Signature'      => $force_refresh && function_exists('ultracache_get_varnish_revalidation_vcl_signature') ? ultracache_get_varnish_revalidation_vcl_signature() : '',
                            'Cache-Control'                   => $force_refresh ? 'no-cache, no-store, must-revalidate, max-age=0' : '',
                            'Pragma'                          => $force_refresh ? 'no-cache' : '',
                        )
                    ),
                );
                $response = ultracache_safe_loopback_remote_request(
                    $request_url,
                    $request_args,
                    'warm_url'
                );

                if (is_wp_error($response)) {
                    $last_error = $response->get_error_message();
                    $retryable_failure = true;
                    if (!$run_heartbeat('html-bucket-' . sanitize_key((string) $bucket) . '-after')) {
                        return $ownership_lost_result($url, $buckets, $cached_files);
                    }
                    continue;
                }

                $code = (int) wp_remote_retrieve_response_code($response);
                $html = wp_remote_retrieve_body($response);
                if ($force_refresh) {
                    $force_refresh_marker = trim((string) wp_remote_retrieve_header($response, 'x-ultra-cache-force-refresh'));
                    $reached_origin = 'wp-engine' === strtolower($force_refresh_marker);
                    if ($reached_origin) {
                        ++$force_refresh_reached_bucket_count;
                    }
                    $force_refresh_details[] = array(
                        'bucket' => (string) $bucket,
                        'httpCode' => $code,
                        'reachedOrigin' => $reached_origin,
                        'marker' => sanitize_text_field($force_refresh_marker),
                        'transport' => 'public-route',
                        'headers' => array(
                            'via' => sanitize_text_field((string) wp_remote_retrieve_header($response, 'via')),
                            'server' => sanitize_text_field((string) wp_remote_retrieve_header($response, 'server')),
                            'xVarnish' => sanitize_text_field((string) wp_remote_retrieve_header($response, 'x-varnish')),
                            'xVarnishCache' => sanitize_text_field((string) wp_remote_retrieve_header($response, 'x-varnish-cache')),
                            'xCache' => sanitize_text_field((string) wp_remote_retrieve_header($response, 'x-cache')),
                            'xCacheStatus' => sanitize_text_field((string) wp_remote_retrieve_header($response, 'x-cache-status')),
                            'xProxyCache' => sanitize_text_field((string) wp_remote_retrieve_header($response, 'x-proxy-cache')),
                            'age' => sanitize_text_field((string) wp_remote_retrieve_header($response, 'age')),
                            'ultraCache' => sanitize_text_field((string) wp_remote_retrieve_header($response, 'x-ultra-cache')),
                            'ultraCacheSource' => sanitize_text_field((string) wp_remote_retrieve_header($response, 'x-ultra-cache-source')),
                            'ultraCacheVariant' => sanitize_text_field((string) wp_remote_retrieve_header($response, 'x-ultracache-variant')),
                        ),
                    );
                }
                if (200 !== $code || empty($html)) {
                    $is_redirect = in_array($code, array(301, 302, 303, 307, 308), true);
                    $last_error = $is_redirect
                        ? 'Remote page redirected; the exact queued URL was not warmed.'
                        : (200 !== $code ? 'Remote page did not return HTTP 200.' : 'Remote page returned an empty body.');
                    $http_retryable = 0 === $code || 408 === $code || 425 === $code || 429 === $code || $code >= 500 || (200 === $code && empty($html));
                    $retryable_failure = $retryable_failure || $http_retryable;
                    $terminal_failure = $terminal_failure || !$http_retryable;
                    $request_retryable = $retryable_failure && !$terminal_failure;
                    $redirect_url = '';
                    if ($is_redirect) {
                        $location = trim((string) wp_remote_retrieve_header($response, 'location'));
                        if ('' !== $location && class_exists('WP_Http') && method_exists('WP_Http', 'make_absolute_url')) {
                            $location = WP_Http::make_absolute_url($location, $url);
                        }
                        $location = esc_url_raw($location);
                        if ('' !== $location) {
                            $location = wp_http_validate_url($location) ? $location : '';
                        }
                        if ('' !== $location
                            && function_exists('ultracache_is_strict_frontend_loopback_url')
                            && ultracache_is_strict_frontend_loopback_url($location)) {
                            $redirect_url = $location;
                        }
                    }
                    $result = array(
                        'success'  => false,
                        'cached'   => false,
                        'skipped'  => !$request_retryable,
                        'retryable' => $request_retryable,
                        'terminal' => !$request_retryable,
                        'failureClass' => $is_redirect ? 'canonical-redirect' : ($request_retryable ? 'http-transient' : (200 === $code ? 'empty-response' : 'http-terminal')),
                        'url'      => $url,
                        'redirectUrl' => $redirect_url,
                        'message'  => $last_error,
                        'files'    => $cached_files,
                        'buckets'  => $buckets,
                        'httpCode' => $code,
                    );
                    if ($css_bundle_requested) {
                        $result['cssBundle'] = array('success' => false, 'skipped' => true, 'message' => __('CSS bundle skipped because the page was not eligible for HTML cache warm-up.', 'ultracache'));
                    }
                    $this->record_analytics_warm($url, $result);
                    return $result;
                }

                if (!$this->is_html_loopback_response($response, $html)) {
                    $last_error = 'Remote page did not return an HTML Content-Type.';
                    $result = array(
                        'success' => false,
                        'cached'  => false,
                        'skipped' => true,
                        'retryable' => false,
                        'terminal' => true,
                        'failureClass' => 'non-html-response',
                        'url'     => $url,
                        'message' => $last_error,
                        'files'   => array(),
                        'buckets' => $buckets,
                    );
                    if ($css_bundle_requested) {
                        $result['cssBundle'] = array('success' => false, 'skipped' => true, 'message' => __('CSS bundle skipped because the remote response was not HTML.', 'ultracache'));
                    }
                    $this->record_analytics_warm($url, $result);
                    return $result;
                }

                $cache_rejection_message = $this->get_loopback_cache_rejection_message($response);
                if ('' !== $cache_rejection_message) {
                    $last_error = $cache_rejection_message;
                    $result = array(
                        'success' => false,
                        'cached'  => false,
                        'skipped' => true,
                        'retryable' => false,
                        'terminal' => true,
                        'failureClass' => 'cache-rejected',
                        'url'     => $url,
                        'message' => $last_error,
                        'files'   => array(),
                        'buckets' => $buckets,
                    );
                    if ($css_bundle_requested) {
                        $result['cssBundle'] = array('success' => false, 'skipped' => true, 'message' => __('CSS bundle skipped because the page rejected cache storage.', 'ultracache'));
                    }
                    $this->record_analytics_warm($url, $result);
                    return $result;
                }

                if ($css_bundle_build_required && !$css_bundle_build_attempted) {
                    $css_bundle_build_attempted = true;
                    $bundle_scope = $this->get_css_bundle_scope($settings_for_warm);
                    $css_bundle_result = array('success' => false, 'skipped' => true, 'message' => __('CSS bundle skipped for this URL by the selected CSS Bundling Scope.', 'ultracache'));
                    $should_build_bundle_for_url = ('per-page' === $bundle_scope || $this->is_frontpage_request_url($url));
                    if ($should_build_bundle_for_url && empty($this->get_frontpage_css_manifest_entry($url))) {
                        if (!$run_heartbeat('css-before')) {
                            return $ownership_lost_result($url, $buckets, $cached_files);
                        }
                        // Build the CSS bundle only after the warm loopback proved that the
                        // public page returns cacheable HTML. This keeps manual warm-up progress
                        // aligned with the actual work and prevents CSS scans for 404/feed/non-HTML URLs.
                        $css_bundle_result = $this->build_frontpage_css_bundle($url, array('skip_final_warm' => true));
                        if (!$run_heartbeat('css-after')) {
                            return $ownership_lost_result($url, $buckets, $cached_files);
                        }
                    } elseif ($should_build_bundle_for_url) {
                        $css_bundle_result = array('success' => true, 'skipped' => true, 'message' => __('Existing CSS bundle manifest entry found for this URL.', 'ultracache'));
                    }
                }

                if (method_exists($this, 'process_final_html_for_cache_storage')) {
                    $processed_html = $this->process_final_html_for_cache_storage($html, false, array(
                        'accept'      => $accept_header,
                        'bucket'      => (string) $bucket,
                        'source'      => 'warm_url',
                        'url'         => $url,
                        'request_url' => $url,
                    ));
                    if (is_string($processed_html) && '' !== $processed_html) {
                        $html = $processed_html;
                    }
                }

                $file_path = $this->get_cache_path($url, $bucket);
                if (empty($file_path)) {
                    $last_error = 'Could not determine cache path.';
                    $terminal_failure = true;
                    if (!$run_heartbeat('html-bucket-' . sanitize_key((string) $bucket) . '-after')) {
                        return $ownership_lost_result($url, $buckets, $cached_files);
                    }
                    continue;
                }

                $wrote = $this->write_cache_file($file_path, $html, $url);
                if (!$wrote || !file_exists($file_path)) {
                    $write_error = method_exists($this, 'get_last_cache_write_error_message') ? $this->get_last_cache_write_error_message() : '';
                    $last_error = '' !== (string) $write_error ? 'Failed to write cache file: ' . (string) $write_error : 'Failed to write cache file.';
                    $retryable_failure = true;
                    if (!$run_heartbeat('html-bucket-' . sanitize_key((string) $bucket) . '-after')) {
                        return $ownership_lost_result($url, $buckets, $cached_files);
                    }
                    continue;
                }

                $cached_files[] = $file_path;
                if (!$run_heartbeat('html-bucket-' . sanitize_key((string) $bucket) . '-after')) {
                    return $ownership_lost_result($url, $buckets, $cached_files);
                }
            }

            $success = !empty($buckets) && count($cached_files) === count($buckets);
            $partial = !empty($cached_files) && count($cached_files) < count($buckets);
            if ($success) {
                $this->record_cache_event('warm', array('url' => $url, 'files' => $cached_files));
            }

            $result = array(
                'success' => $success,
                'cached'  => $success,
                'url'     => $url,
                'message' => $success
                    ? ($css_bundle_requested ? __('Cached + CSS.', 'ultracache') : __('Cached.', 'ultracache'))
                    : ($partial ? __('Only some requested HTML variants were cached.', 'ultracache') : ('' !== $last_error ? $last_error : __('Cache write failed.', 'ultracache'))),
                'files'   => $cached_files,
                'buckets' => $buckets,
                'retryable' => !$success && $retryable_failure && !$terminal_failure,
                'terminal' => !$success && (!$retryable_failure || $terminal_failure),
                'failureClass' => $partial ? 'partial-html-variants' : ($terminal_failure ? 'html-terminal' : ($retryable_failure ? 'html-transient' : 'cache-write-failed')),
            );

            if ($force_refresh) {
                $expected_bucket_count = count($buckets);
                $result['forceRefreshRequested'] = true;
                $result['forceRefreshReachedOrigin'] = $expected_bucket_count > 0
                    && $force_refresh_reached_bucket_count === $expected_bucket_count;
                $result['forceRefreshReachedBucketCount'] = $force_refresh_reached_bucket_count;
                $result['forceRefreshExpectedBucketCount'] = $expected_bucket_count;
                $result['forceRefreshDetails'] = $force_refresh_details;
            }

            if ($css_bundle_requested) {
                $result['cssBundle'] = is_array($css_bundle_result) ? $css_bundle_result : array();
                if (!$success && !empty($css_bundle_result['message'])) {
                    $result['message'] = '' !== (string) $last_error ? (string) $last_error : (string) $css_bundle_result['message'];
                }
            }

            $this->record_analytics_warm($url, $result);

            return $result;
        }
        private function get_accept_header_for_bucket($bucket)
        {
            if (function_exists('ultracache_get_accept_header_for_html_bucket')) {
                return ultracache_get_accept_header_for_html_bucket($bucket);
            }

            return 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
        }
        private function acquire_post_save_warm_cooldown($post_id)
        {
            $post_id = (int) $post_id;
            if ($post_id <= 0) {
                return false;
            }

            $cooldown = (int) apply_filters('ultracache_post_save_warm_cooldown_seconds', 180, $post_id);
            if ($cooldown < 1) {
                return true;
            }

            $transient_key = 'ultracache_post_save_warm_' . md5((string) $post_id);
            if (get_transient($transient_key)) {
                return false;
            }

            set_transient($transient_key, (string) time(), $cooldown);
            return true;
        }
}
