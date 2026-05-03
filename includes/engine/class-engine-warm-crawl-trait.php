<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Warm_Crawl_Trait
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
            if ($post_id <= 0 || wp_is_post_revision($post_id)) {
                return;
            }

            $post = get_post($post_id);
            if (!$post || 'auto-draft' === $post->post_status) {
                return;
            }

            $this->purge_related_post_urls($post_id);

            $settings = $this->get_settings();
            if (!empty($settings['preload_on_save']) && 'publish' === $post->post_status) {
                foreach ($this->get_urls_to_warm_for_post($post_id) as $url) {
                    $this->warm_url($url);
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
            return !function_exists('ucwp_is_local_https_url') || !ucwp_is_local_https_url($url);
        }

        private function acquire_runtime_lock($name, $ttl = 180)
        {
            $name = (string) $name;
            if ('' === $name) {
                return false;
            }

            $file = $this->get_runtime_lock_file($name);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Runtime lock requires native fopen/flock semantics and is limited to UltraCache cache storage.
            $handle = @fopen($file, 'c+');
            if (!$handle) {
                return false;
            }

            if (!@flock($handle, LOCK_EX | LOCK_NB)) {
                $mtime = @filemtime($file);
                if ($mtime && (time() - (int) $mtime) > max(30, (int) $ttl)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Refreshes only a path-guarded UltraCache runtime lock file.
                    @touch($file);
                }
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

        private function release_runtime_lock($name)
        {
            $name = (string) $name;
            if (empty($this->runtime_locks[$name])) {
                return;
            }

            $handle = $this->runtime_locks[$name];
            @flock($handle, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing native lock handle.
            @fclose($handle);
            unset($this->runtime_locks[$name]);
        }

        private function is_ultracache_internal_loopback_request()
        {
            if ('1' === sanitize_text_field(ucwp_server_value('HTTP_X_ULTRACACHE_INTERNAL_REQUEST'))) {
                return true;
            }
            if ('1' === sanitize_text_field(ucwp_server_value('HTTP_X_ULTRACACHE_WARM'))) {
                return true;
            }
            if ('1' === sanitize_text_field(ucwp_server_value('HTTP_X_ULTRACACHE_CSS_BUNDLE'))) {
                return true;
            }
            if ($this->is_frontpage_css_scan_mode()) {
                return true;
            }

            return false;
        }

        public function warm_url($url, array $args = array())
        {
            $args = is_array($args) ? $args : array();
            $ignore_runtime_bypass = !empty($args['ignore_runtime_bypass']);
            $force_refresh = !empty($args['force_refresh']);
            $url = esc_url_raw((string) $url);
            if (!$this->is_cacheable_local_url($url)) {
                $result = array(
                    'success' => false,
                    'cached'  => false,
                    'url'     => $url,
                    'message' => 'Only local site URLs can be warmed.',
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
                    'url'     => $url,
                    'message' => $bypass_message,
                    'files'   => array(),
                );
                $this->record_analytics_warm($url, $result);
                return $result;
            }

            // 2.56.185: warm AVIF before WebP so auto/best warm generation does not
            // settle on a WebP fallback before the AVIF bucket has had a chance to generate.
            $bucket_priority = array('orig', 'avif', 'webp');
            $requested_buckets = isset($args['buckets']) && is_array($args['buckets']) ? $args['buckets'] : $bucket_priority;
            $buckets = array_values(array_unique(array_intersect($bucket_priority, array_map('strval', $requested_buckets))));
            if (empty($buckets)) {
                $buckets = $bucket_priority;
            }

            if ($force_refresh) {
                foreach ($buckets as $bucket) {
                    $existing_cache_file = $this->get_cache_path($url, $bucket);
                    if ('' !== (string) $existing_cache_file) {
                        $this->delete_cache_variants($existing_cache_file);
                    }
                }
            }

            $settings_for_warm = $this->get_settings();
            $css_bundle_requested = !empty($args['build_css_bundle']);
            $css_bundle_auto_warm = !$css_bundle_requested
                && !empty($settings_for_warm['homepage_css_bundle'])
                && !empty($settings_for_warm['page_css_bundle_on_entry']);
            $css_bundle_result = array();
            if ($css_bundle_requested || $css_bundle_auto_warm) {
                $bundle_scope = $this->get_css_bundle_scope($settings_for_warm);
                $css_bundle_result = array('success' => false, 'skipped' => true, 'message' => 'CSS bundle skipped for this URL by the selected CSS Bundling Scope.');
                $should_build_bundle_for_url = ('per-page' === $bundle_scope || $this->is_frontpage_request_url($url));
                if ($should_build_bundle_for_url && empty($this->get_frontpage_css_manifest_entry($url))) {
                    // Build the CSS bundle/manifest before writing the HTML cache. The HTML warm below
                    // then sees the fresh manifest and only needs one loopback pass instead of a
                    // warm -> bundle -> warm sequence. The page_css_bundle_on_entry setting is documented
                    // as entry/warm, so explicit warms and cron warms may also populate missing bundles.
                    $css_bundle_result = $this->build_frontpage_css_bundle($url, array('skip_final_warm' => true));
                } elseif ($should_build_bundle_for_url) {
                    $css_bundle_result = array('success' => true, 'skipped' => true, 'message' => 'Existing CSS bundle manifest entry found for this URL.');
                }
            }

            $cached_files = array();
            $last_error = '';

            foreach ($buckets as $bucket) {
                $accept_header = $this->get_accept_header_for_bucket($bucket);
                $response = ucwp_safe_loopback_remote_request(
                    $url,
                    array(
                        'method'      => 'GET',
                        'timeout'     => 10,
                        'redirection' => 3,
                        'sslverify'   => $this->should_verify_loopback_ssl($url),
                        'user-agent'  => 'Mozilla/5.0 (compatible; UltraCache-Warm/' . UCWP_VERSION . '; +https://wordpress.org)',
                        'headers'     => array_filter(
                            array(
                                'Accept'                          => $accept_header,
                                'X-UltraCache-Warm'               => '1',
                                'X-UltraCache-Internal-Request'   => '1',
                                'X-UltraCache-Force-Refresh'      => $force_refresh ? '1' : '',
                                'Cache-Control'                   => $force_refresh ? 'no-cache, no-store, must-revalidate, max-age=0' : '',
                                'Pragma'                          => $force_refresh ? 'no-cache' : '',
                            )
                        ),
                    ),
                    'warm_url'
                );

                if (is_wp_error($response)) {
                    $last_error = $response->get_error_message();
                    continue;
                }

                $code = (int) wp_remote_retrieve_response_code($response);
                $html = wp_remote_retrieve_body($response);
                if (200 !== $code || empty($html)) {
                    $last_error = 200 !== $code ? 'Remote page did not return HTTP 200.' : 'Remote page returned an empty body.';
                    continue;
                }

                if (!$this->is_html_loopback_response($response, $html)) {
                    $last_error = 'Remote page did not return an HTML Content-Type.';
                    continue;
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
                    continue;
                }

                $wrote = $this->write_cache_file($file_path, $html);
                if (!$wrote || !file_exists($file_path)) {
                    $last_error = 'Failed to write cache file.';
                    continue;
                }

                $cached_files[] = $file_path;
            }

            $success = !empty($cached_files);
            if ($success) {
                $this->record_cache_event('warm', array('url' => $url, 'files' => $cached_files));
            }

            $result = array(
                'success' => $success,
                'cached'  => $success,
                'url'     => $url,
                'message' => $success ? sprintf('Generated %d cache file(s).', count($cached_files)) : $last_error,
                'files'   => $cached_files,
            );

            if ($css_bundle_requested) {
                $result['cssBundle'] = is_array($css_bundle_result) ? $css_bundle_result : array();
                if (!empty($css_bundle_result['success'])) {
                    $result['message'] .= ' CSS bundle built before HTML warm.';
                } elseif (!empty($css_bundle_result['message'])) {
                    $result['message'] .= ' CSS bundle skipped: ' . (string) $css_bundle_result['message'];
                }
            }

            $this->record_analytics_warm($url, $result);

            return $result;
        }

        private function get_accept_header_for_bucket($bucket)
        {
            switch ((string) $bucket) {
                case 'avif':
                    return 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';
                case 'webp':
                    return 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8';
                case 'orig':
                default:
                    return 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
            }
        }

        private function purge_cache_directory_preserving_google_fonts()
        {
            $root = trailingslashit(UCWP_CACHE_DIR);
            if ('' === $root || !is_dir($root)) {
                return;
            }

            $items = function_exists('ucwp_safe_scandir') ? ucwp_safe_scandir($root, 'purge_all_preserve_google_fonts scandir') : scandir($root);
            if (!is_array($items)) {
                return;
            }

            foreach ($items as $item) {
                if ('.' === $item || '..' === $item || 'google-fonts' === $item || 'css-bundles' === $item || 'js-bundles' === $item) {
                    continue;
                }

                $path = $root . $item;
                if (is_dir($path) && !is_link($path)) {
                    $this->recursive_delete($path);
                } else {
                    ucwp_safe_unlink($path);
                }
            }
        }

        public function purge_all()
        {
            $lock_name = 'purge-all';
            if (!$this->acquire_runtime_lock($lock_name, 180)) {
                return false;
            }

            try {
                $this->purge_cache_directory_preserving_google_fonts();
                self::ensure_cache_directories();
                if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'sync_runtime_config')) {
                    Ultra_Cache_WP::sync_runtime_config();
                }
                if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'reset_cron_warmup_queue_after_cache_flush')) {
                    Ultra_Cache_WP::reset_cron_warmup_queue_after_cache_flush('purge_all');
                }
                $this->delete_frontpage_css_bundle();
                $this->invalidate_dashboard_cache_activity_snapshot();

                if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache')) {
                    Ultra_Cache_Object_Cache_Manager::flush_cache();
                }

                $this->record_cache_event('purge-all');
                $this->record_analytics_purge('all');
                do_action('ucwp_after_purge_all', array('scope' => 'all'));
                return true;
            } finally {
                $this->release_runtime_lock($lock_name);
            }
        }

        public function purge_url($url)
        {
            return $this->purge_urls(array($url), 'url', array('url' => $url));
        }

        public function purge_urls(array $urls, $scope = 'batch', array $payload = array())
        {
            $purged_urls = array();

            foreach ($urls as $url) {
                $normalized_url = $this->normalize_url($url);
                if ('' === $normalized_url || !$this->is_cacheable_local_url($normalized_url)) {
                    continue;
                }

                foreach ($this->get_cache_paths_for_all_buckets($normalized_url) as $file) {
                    $this->delete_cache_variants($file);
                }

                $this->delete_frontpage_css_bundle($normalized_url);

                $purged_urls[$normalized_url] = $normalized_url;
            }

            if (empty($purged_urls)) {
                return false;
            }

            $purged_urls = array_values($purged_urls);
            $primary_url = isset($purged_urls[0]) ? $purged_urls[0] : '';
            $this->record_cache_event(
                'purge-' . sanitize_key((string) $scope),
                array_merge(
                    array(
                        'scope'     => (string) $scope,
                        'url'       => $primary_url,
                        'count'     => count($purged_urls),
                        'urls'      => array_slice($purged_urls, 0, 20),
                        'truncated' => count($purged_urls) > 20,
                    ),
                    $payload
                )
            );
            $this->record_analytics_purge($scope, $primary_url);
            do_action('ucwp_after_purge_urls', $purged_urls, (string) $scope, array_merge(array('url' => $primary_url), $payload));

            return true;
        }

        public function purge_page_by_url($url)
        {
            return $this->purge_url($url);
        }

        public function purge_post_cache($post_id)
        {
            $this->purge_related_post_urls((int) $post_id);
        }

        private function purge_related_post_urls($post_id)
        {
            $this->purge_urls(
                $this->get_related_urls_for_post($post_id),
                'related-post',
                array(
                    'post_id' => (int) $post_id,
                )
            );
        }

        private function get_urls_to_warm_for_post($post_id)
        {
            return $this->get_related_urls_for_post(
                $post_id,
                array(
                    'includeFeeds'            => false,
                    'includePagination'       => false,
                    'includeAuthorArchive'    => false,
                    'includeDateArchives'     => false,
                    'includePostCommentsFeed' => false,
                )
            );
        }

        private function get_related_urls_for_post($post_id, array $args = array())
        {
            $post_id = (int) $post_id;
            $defaults = array(
                'includeFeeds'            => true,
                'includePagination'       => true,
                'includeAuthorArchive'    => true,
                'includeDateArchives'     => true,
                'includePostCommentsFeed' => true,
            );
            $args = wp_parse_args($args, $defaults);

            $urls = array();
            foreach ($this->get_site_front_urls(false) as $seed_url) {
                $this->append_related_url($urls, $seed_url);
            }

            if ($post_id <= 0) {
                return array_values($urls);
            }

            $post = get_post($post_id);
            if (!$post) {
                return array_values($urls);
            }

            $permalink = get_permalink($post_id);
            if ($permalink) {
                $this->append_related_url($urls, $permalink);
                if (!empty($args['includePostCommentsFeed'])) {
                    $comments_feed = get_post_comments_feed_link($post_id);
                    if ($comments_feed) {
                        $this->append_related_url($urls, $comments_feed);
                    }
                }
            }

            if ('post' === $post->post_type) {
                $blog_base_url = $this->get_posts_index_url();
                if ($blog_base_url) {
                    $this->append_related_url($urls, $blog_base_url);
                    if (!empty($args['includeFeeds'])) {
                        $this->append_related_url($urls, $this->build_archive_feed_url($blog_base_url));
                    }
                    if (!empty($args['includePagination'])) {
                        $this->append_paged_related_url($urls, $blog_base_url, $this->get_post_listing_page_number($post));
                    }
                }
            }

            $post_type_object = get_post_type_object($post->post_type);
            if ($post_type_object && !empty($post_type_object->has_archive)) {
                $archive_url = get_post_type_archive_link($post->post_type);
                if ($archive_url) {
                    $this->append_related_url($urls, $archive_url);
                    if (!empty($args['includeFeeds'])) {
                        $this->append_related_url($urls, $this->build_archive_feed_url($archive_url));
                    }
                    if (!empty($args['includePagination'])) {
                        $this->append_paged_related_url($urls, $archive_url, $this->get_post_type_archive_page_number($post));
                    }
                }
            }

            if ('product' === $post->post_type && function_exists('wc_get_page_permalink')) {
                $shop_url = wc_get_page_permalink('shop');
                if ($shop_url) {
                    $this->append_related_url($urls, $shop_url);
                    if (!empty($args['includeFeeds'])) {
                        $this->append_related_url($urls, $this->build_archive_feed_url($shop_url));
                    }
                    if (!empty($args['includePagination'])) {
                        $this->append_paged_related_url($urls, $shop_url, $this->get_post_type_archive_page_number($post));
                    }
                }
            }

            if (!empty($args['includeAuthorArchive']) && 'post' === $post->post_type && !empty($post->post_author)) {
                $author_url = get_author_posts_url((int) $post->post_author);
                if ($author_url) {
                    $this->append_related_url($urls, $author_url);
                    if (!empty($args['includeFeeds'])) {
                        $this->append_related_url($urls, $this->build_archive_feed_url($author_url));
                    }
                    if (!empty($args['includePagination'])) {
                        $this->append_paged_related_url($urls, $author_url, $this->get_author_archive_page_number($post));
                    }
                }
            }

            if (!empty($args['includeDateArchives']) && 'post' === $post->post_type) {
                $this->append_date_archive_urls($urls, $post, $args);
            }

            $taxonomies = get_object_taxonomies($post->post_type, 'names');
            foreach ((array) $taxonomies as $taxonomy) {
                $taxonomy_object = get_taxonomy($taxonomy);
                if (!$taxonomy_object || empty($taxonomy_object->public)) {
                    continue;
                }

                $terms = get_the_terms($post_id, $taxonomy);
                if (empty($terms) || is_wp_error($terms)) {
                    continue;
                }

                foreach ($terms as $term) {
                    $term_link = get_term_link($term);
                    if (is_wp_error($term_link) || !$term_link) {
                        continue;
                    }

                    $this->append_related_url($urls, $term_link);
                    if (!empty($args['includeFeeds'])) {
                        $this->append_related_url($urls, $this->build_archive_feed_url($term_link));
                    }
                    if (!empty($args['includePagination'])) {
                        $this->append_paged_related_url($urls, $term_link, $this->get_term_archive_page_number($post, $taxonomy, (int) $term->term_id));
                    }
                }
            }

            return array_values($urls);
        }

        private function get_related_urls_for_term($term_id, $taxonomy, array $args = array())
        {
            $term_id = (int) $term_id;
            $taxonomy = is_string($taxonomy) ? $taxonomy : '';
            $defaults = array(
                'includeFeeds'      => true,
                'includePagination' => true,
                'includeSiteFront'  => true,
            );
            $args = wp_parse_args($args, $defaults);

            $urls = array();
            if (!empty($args['includeSiteFront'])) {
                foreach ($this->get_site_front_urls(false) as $seed_url) {
                    $this->append_related_url($urls, $seed_url);
                }
            }

            if ($term_id <= 0 || '' === $taxonomy) {
                return array_values($urls);
            }

            $term = get_term($term_id, $taxonomy);
            if (!$term || is_wp_error($term)) {
                return array_values($urls);
            }

            $term_link = get_term_link($term);
            if (!is_wp_error($term_link) && $term_link) {
                $this->append_related_url($urls, $term_link);
                if (!empty($args['includeFeeds'])) {
                    $this->append_related_url($urls, $this->build_archive_feed_url($term_link));
                }
                if (!empty($args['includePagination']) && (int) $term->count > $this->get_archive_posts_per_page($this->get_primary_post_type_for_taxonomy($taxonomy))) {
                    $this->append_paged_related_url($urls, $term_link, 2);
                }
            }

            $taxonomy_object = get_taxonomy($taxonomy);
            if ($taxonomy_object && !empty($taxonomy_object->object_type) && is_array($taxonomy_object->object_type)) {
                foreach ($taxonomy_object->object_type as $object_type) {
                    $post_type_object = get_post_type_object($object_type);
                    if ($post_type_object && !empty($post_type_object->has_archive)) {
                        $archive_url = get_post_type_archive_link($object_type);
                        if ($archive_url) {
                            $this->append_related_url($urls, $archive_url);
                        }
                    }

                    if ('product' === $object_type && function_exists('wc_get_page_permalink')) {
                        $shop_url = wc_get_page_permalink('shop');
                        if ($shop_url) {
                            $this->append_related_url($urls, $shop_url);
                        }
                    }
                }
            }

            return array_values($urls);
        }

        private function get_site_front_urls($include_archives = false)
        {
            $urls = array();
            $this->append_related_url($urls, home_url('/'));

            if (function_exists('get_feed_link')) {
                $this->append_related_url($urls, get_feed_link());
            }

            $posts_page_url = $this->get_posts_index_url();
            if ($posts_page_url && home_url('/') !== $posts_page_url) {
                $this->append_related_url($urls, $posts_page_url);
                $this->append_related_url($urls, $this->build_archive_feed_url($posts_page_url));
            }

            if ($include_archives) {
                foreach ($this->get_public_crawl_post_types() as $post_type) {
                    $post_type_object = get_post_type_object($post_type);
                    if (!$post_type_object || empty($post_type_object->has_archive)) {
                        continue;
                    }

                    $archive_url = get_post_type_archive_link($post_type);
                    if ($archive_url) {
                        $this->append_related_url($urls, $archive_url);
                        $this->append_related_url($urls, $this->build_archive_feed_url($archive_url));
                    }
                }

                if (function_exists('wc_get_page_permalink')) {
                    $shop_url = wc_get_page_permalink('shop');
                    if ($shop_url) {
                        $this->append_related_url($urls, $shop_url);
                        $this->append_related_url($urls, $this->build_archive_feed_url($shop_url));
                    }
                }
            }

            return array_values($urls);
        }

        private function get_posts_index_url()
        {
            $posts_page_id = (int) get_option('page_for_posts');
            if ($posts_page_id > 0) {
                $posts_page_url = get_permalink($posts_page_id);
                if ($posts_page_url) {
                    return $posts_page_url;
                }
            }

            return home_url('/');
        }

        private function append_related_url(array &$urls, $url)
        {
            $normalized_url = $this->normalize_url($url);
            if ('' === $normalized_url || !$this->is_cacheable_local_url($normalized_url)) {
                return false;
            }

            $urls[$normalized_url] = $normalized_url;
            return true;
        }

        private function append_paged_related_url(array &$urls, $base_url, $page_number)
        {
            $page_number = (int) $page_number;
            if ($page_number <= 1) {
                return false;
            }

            return $this->append_related_url($urls, $this->build_paged_archive_url($base_url, $page_number));
        }

        private function build_paged_archive_url($base_url, $page_number)
        {
            $page_number = max(1, (int) $page_number);
            $base_url = trailingslashit((string) $base_url);
            if ($page_number <= 1 || '' === $base_url) {
                return $base_url;
            }

            return $base_url . ltrim(user_trailingslashit('page/' . $page_number, 'paged'), '/');
        }

        private function build_archive_feed_url($base_url)
        {
            $base_url = trailingslashit((string) $base_url);
            if ('' === $base_url) {
                return '';
            }

            return $base_url . ltrim(user_trailingslashit('feed', 'feed'), '/');
        }

        private function append_date_archive_urls(array &$urls, $post, array $args)
        {
            $post = get_post($post);
            if (!$post) {
                return;
            }

            $year = (int) mysql2date('Y', $post->post_date);
            $month = (int) mysql2date('m', $post->post_date);
            $day = (int) mysql2date('d', $post->post_date);

            $year_url = get_year_link($year);
            $month_url = get_month_link($year, $month);
            $day_url = get_day_link($year, $month, $day);

            foreach (array(
                'year'  => $year_url,
                'month' => $month_url,
                'day'   => $day_url,
            ) as $period => $archive_url) {
                if (!$archive_url) {
                    continue;
                }

                $this->append_related_url($urls, $archive_url);
                if (!empty($args['includeFeeds'])) {
                    $this->append_related_url($urls, $this->build_archive_feed_url($archive_url));
                }
                if (!empty($args['includePagination'])) {
                    $this->append_paged_related_url($urls, $archive_url, $this->get_date_archive_page_number($post, $period));
                }
            }
        }

        private function get_archive_posts_per_page($post_type = 'post')
        {
            $per_page = (int) get_option('posts_per_page', 10);
            if ('product' === $post_type) {
                $per_page = (int) apply_filters('loop_shop_per_page', $per_page); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            }

            return max(1, $per_page);
        }

        private function get_primary_post_type_for_taxonomy($taxonomy)
        {
            $taxonomy_object = get_taxonomy($taxonomy);
            if ($taxonomy_object && !empty($taxonomy_object->object_type) && is_array($taxonomy_object->object_type)) {
                foreach ($taxonomy_object->object_type as $object_type) {
                    if (post_type_exists($object_type)) {
                        return (string) $object_type;
                    }
                }
            }

            return 'post';
        }

        private function get_descending_position_for_post($post, $post_type = '', $join = '', $where = '', array $params = array())
        {
            global $wpdb;

            $post = get_post($post);
            if (!$post) {
                return 0;
            }

            $post_type = $post_type ? (string) $post_type : (string) $post->post_type;
            $post_date = (string) $post->post_date;
            if ('' === $post_date) {
                return 0;
            }

            $sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$join} WHERE p.post_status = 'publish' AND p.post_type = %s {$where} AND (p.post_date > %s OR (p.post_date = %s AND p.ID >= %d))";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $prepared = $wpdb->prepare($sql, array_merge(array($post_type), $params, array($post_date, $post_date, (int) $post->ID)));
            $cache_key = 'ucwp_desc_pos_' . md5((string) $prepared);
            $cached = wp_cache_get($cache_key, 'ultracache');
            if (false !== $cached) {
                return max(1, (int) $cached);
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $count = (int) $wpdb->get_var($prepared);
            wp_cache_set($cache_key, $count, 'ultracache', HOUR_IN_SECONDS);

            return max(1, $count);
        }

        private function get_post_listing_page_number($post)
        {
            $post = get_post($post);
            if (!$post || 'post' !== $post->post_type) {
                return 1;
            }

            $position = $this->get_descending_position_for_post($post, 'post');
            return (int) ceil($position / $this->get_archive_posts_per_page('post'));
        }

        private function get_post_type_archive_page_number($post)
        {
            $post = get_post($post);
            if (!$post) {
                return 1;
            }

            $position = $this->get_descending_position_for_post($post, $post->post_type);
            return (int) ceil($position / $this->get_archive_posts_per_page($post->post_type));
        }

        private function get_author_archive_page_number($post)
        {
            $post = get_post($post);
            if (!$post || empty($post->post_author)) {
                return 1;
            }

            $position = $this->get_descending_position_for_post($post, $post->post_type, '', ' AND p.post_author = %d', array((int) $post->post_author));
            return (int) ceil($position / $this->get_archive_posts_per_page($post->post_type));
        }

        private function get_date_archive_page_number($post, $period = 'month')
        {
            $post = get_post($post);
            if (!$post) {
                return 1;
            }

            $year = (int) mysql2date('Y', $post->post_date);
            $month = (int) mysql2date('m', $post->post_date);
            $day = (int) mysql2date('d', $post->post_date);
            $where = ' AND YEAR(p.post_date) = %d';
            $params = array($year);

            if ('month' === $period || 'day' === $period) {
                $where .= ' AND MONTH(p.post_date) = %d';
                $params[] = $month;
            }

            if ('day' === $period) {
                $where .= ' AND DAY(p.post_date) = %d';
                $params[] = $day;
            }

            $position = $this->get_descending_position_for_post($post, $post->post_type, '', $where, $params);
            return (int) ceil($position / $this->get_archive_posts_per_page($post->post_type));
        }

        private function get_term_archive_page_number($post, $taxonomy, $term_id)
        {
            global $wpdb;

            $post = get_post($post);
            $taxonomy = is_string($taxonomy) ? $taxonomy : '';
            $term_id = (int) $term_id;
            if (!$post || '' === $taxonomy || $term_id <= 0) {
                return 1;
            }

            $join = " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id";
            $where = ' AND tt.taxonomy = %s AND tt.term_id = %d';
            $position = $this->get_descending_position_for_post($post, $post->post_type, $join, $where, array($taxonomy, $term_id));
            return (int) ceil($position / $this->get_archive_posts_per_page($post->post_type));
        }

        private function invalidate_dashboard_cache_activity_snapshot()
        {
            delete_transient('ucwp_dashboard_cache_activity_v1');
        }

        public function get_crawl_urls($scope = 'full')
        {
            $scope = $this->normalize_crawl_scope($scope);
            $max_urls = (int) apply_filters('ucwp_max_crawl_urls', 5000);
            if ($max_urls <= 0) {
                $max_urls = 5000;
            }

            if ('menu' === $scope) {
                $urls = array();
                foreach ($this->get_safe_nav_menu_urls() as $menu_url) {
                    if ($this->is_cacheable_local_url($menu_url)) {
                        $urls[] = $menu_url;
                    }
                }

                $urls = array_values(array_unique(array_filter($urls)));
                if (count($urls) > $max_urls) {
                    $urls = array_slice($urls, 0, $max_urls);
                }

                return apply_filters('ucwp_crawl_urls', $urls, $scope);
            }

            $urls = array(home_url('/'));

            $posts_page_id = (int) get_option('page_for_posts');
            if ($posts_page_id > 0) {
                $posts_page_url = $this->safe_get_permalink($posts_page_id);
                if ($posts_page_url) {
                    $urls[] = $posts_page_url;
                }
            }

            foreach ($this->get_safe_nav_menu_urls() as $menu_url) {
                if ($this->is_cacheable_local_url($menu_url)) {
                    $urls[] = $menu_url;
                }
            }

            foreach (get_post_types(array('public' => true), 'names') as $post_type) {
                $post_ids = get_posts(
                    array(
                        'post_type'              => $post_type,
                        'post_status'            => 'publish',
                        'posts_per_page'         => -1,
                        'fields'                 => 'ids',
                        'no_found_rows'          => true,
                        'update_post_meta_cache' => false,
                        'update_post_term_cache' => false,
                        'suppress_filters'       => false,
                    )
                );

                foreach ((array) $post_ids as $post_id) {
                    $link = $this->safe_get_permalink($post_id);
                    if ($link) {
                        $urls[] = $link;
                    }
                }

                if (count($urls) >= $max_urls) {
                    break;
                }
            }

            if (count($urls) < $max_urls) {
                foreach (get_taxonomies(array('public' => true), 'names') as $taxonomy) {
                    $term_ids = get_terms(
                        array(
                            'taxonomy'   => $taxonomy,
                            'hide_empty' => false,
                            'fields'     => 'ids',
                        )
                    );

                    if (is_wp_error($term_ids) || empty($term_ids)) {
                        continue;
                    }

                    foreach ($term_ids as $term_id) {
                        $term_link = get_term_link((int) $term_id, $taxonomy);
                        if (!is_wp_error($term_link) && $term_link) {
                            $urls[] = $term_link;
                        }
                    }

                    if (count($urls) >= $max_urls) {
                        break;
                    }
                }
            }

            $urls = array_values(array_unique(array_filter($urls)));
            if (count($urls) > $max_urls) {
                $urls = array_slice($urls, 0, $max_urls);
            }

            return apply_filters('ucwp_crawl_urls', $urls, $scope);
        }

        public function get_crawl_urls_batch($offset = 0, $limit = 100, $scope = 'full')
        {
            $offset = max(0, (int) $offset);
            $limit = max(1, min(500, (int) $limit));
            $scope = $this->normalize_crawl_scope($scope);

            if ($offset <= 0) {
                return $this->get_crawl_urls_cursor_batch('', $limit, $scope);
            }

            $cursor = '';
            $remaining = $offset;
            $safety = 0;

            while ($remaining > 0 && $safety < 10000) {
                $step = min(500, max(1, $remaining));
                $batch = $this->get_crawl_urls_cursor_batch($cursor, $step, $scope);
                $count = isset($batch['items']) && is_array($batch['items']) ? count($batch['items']) : 0;

                if ($count <= 0 && empty($batch['hasMore'])) {
                    return $batch;
                }

                $remaining -= $count;
                $cursor = !empty($batch['nextCursor']) ? (string) $batch['nextCursor'] : '';

                if (empty($batch['hasMore'])) {
                    break;
                }

                $safety++;
            }

            return $this->get_crawl_urls_cursor_batch($cursor, $limit, $scope);
        }

        public function get_crawl_urls_cursor_batch($cursor = '', $limit = 100, $scope = 'full')
        {
            $limit = max(1, min(500, (int) $limit));
            $max_urls = (int) apply_filters('ucwp_max_crawl_urls', 5000);
            if ($max_urls <= 0) {
                $max_urls = 5000;
            }

            $state = $this->decode_crawl_cursor_state($cursor, $scope);
            $scope = $this->normalize_crawl_scope(isset($state['scope']) ? $state['scope'] : $scope);
            $start_generated = (int) $state['generated'];
            $items = array();
            $batch_seen = array();

            while (count($items) < $limit && 'done' !== $state['stage']) {
                if ((int) $state['generated'] >= $max_urls) {
                    $state['stage'] = 'done';
                    break;
                }

                switch ($state['stage']) {
                    case 'seed':
                        $seed_urls = $this->get_crawl_seed_urls($scope);
                        $seed_total = count($seed_urls);

                        while (count($items) < $limit && (int) $state['seed_index'] < $seed_total && (int) $state['generated'] < $max_urls) {
                            $url = $seed_urls[(int) $state['seed_index']];
                            $state['seed_index']++;
                            $this->append_crawl_batch_item($items, $batch_seen, $url, $state, $max_urls);
                        }

                        if ((int) $state['seed_index'] >= $seed_total) {
                            $state['stage'] = ('menu' === $scope) ? 'done' : 'posts';
                        }
                        break;

                    case 'posts':
                        $post_types = $this->get_public_crawl_post_types();
                        if ((int) $state['post_type_index'] >= count($post_types)) {
                            $state['stage'] = 'terms';
                            break;
                        }

                        $post_type = $post_types[(int) $state['post_type_index']];
                        $remaining = max(1, min(200, $limit - count($items)));
                        $post_ids = get_posts(
                            array(
                                'post_type'              => $post_type,
                                'post_status'            => 'publish',
                                'posts_per_page'         => $remaining,
                                'offset'                 => (int) $state['post_offset'],
                                'orderby'                => 'ID',
                                'order'                  => 'ASC',
                                'fields'                 => 'ids',
                                'no_found_rows'          => true,
                                'update_post_meta_cache' => false,
                                'update_post_term_cache' => false,
                                'suppress_filters'       => false,
                            )
                        );

                        if (empty($post_ids)) {
                            $state['post_type_index']++;
                            $state['post_offset'] = 0;
                            break;
                        }

                        $state['post_offset'] += count($post_ids);

                        foreach ((array) $post_ids as $post_id) {
                            if (count($items) >= $limit || (int) $state['generated'] >= $max_urls) {
                                break;
                            }

                            $link = $this->safe_get_permalink($post_id);
                            if ($link) {
                                $this->append_crawl_batch_item($items, $batch_seen, $link, $state, $max_urls);
                            }
                        }

                        if (count($post_ids) < $remaining) {
                            $state['post_type_index']++;
                            $state['post_offset'] = 0;
                        }
                        break;

                    case 'terms':
                        $taxonomies = $this->get_public_crawl_taxonomies();
                        if ((int) $state['taxonomy_index'] >= count($taxonomies)) {
                            $state['stage'] = 'done';
                            break;
                        }

                        $taxonomy = $taxonomies[(int) $state['taxonomy_index']];
                        $remaining = max(1, min(200, $limit - count($items)));
                        $term_ids = get_terms(
                            array(
                                'taxonomy'   => $taxonomy,
                                'hide_empty' => false,
                                'fields'     => 'ids',
                                'number'     => $remaining,
                                'offset'     => (int) $state['term_offset'],
                                'orderby'    => 'term_id',
                                'order'      => 'ASC',
                            )
                        );

                        if (is_wp_error($term_ids) || empty($term_ids)) {
                            $state['taxonomy_index']++;
                            $state['term_offset'] = 0;
                            break;
                        }

                        $term_ids = array_values(array_map('intval', (array) $term_ids));
                        $state['term_offset'] += count($term_ids);

                        foreach ($term_ids as $term_id) {
                            if (count($items) >= $limit || (int) $state['generated'] >= $max_urls) {
                                break;
                            }

                            $term_link = get_term_link($term_id, $taxonomy);
                            if (!is_wp_error($term_link) && $term_link) {
                                $this->append_crawl_batch_item($items, $batch_seen, $term_link, $state, $max_urls);
                            }
                        }

                        if (count($term_ids) < $remaining) {
                            $state['taxonomy_index']++;
                            $state['term_offset'] = 0;
                        }
                        break;

                    default:
                        $state['stage'] = 'done';
                        break;
                }
            }

            $has_more = 'done' !== $state['stage'] && (int) $state['generated'] < $max_urls;
            $estimated_total = max($this->estimate_crawl_url_total($max_urls, $scope), (int) $state['generated']);

            return array(
                'items'      => array_values($items),
                'total'      => $estimated_total,
                'offset'     => $start_generated,
                'limit'      => $limit,
                'cursor'     => (string) $cursor,
                'nextCursor' => $has_more ? $this->encode_crawl_cursor_state($state) : '',
                'nextOffset' => (int) $state['generated'],
                'processed'  => (int) $state['generated'],
                'hasMore'    => $has_more,
            );
        }

        public function get_crawl_scope_summary()
        {
            $menu_urls = $this->get_safe_nav_menu_urls();
            $seed_urls = $this->get_crawl_seed_urls();
            $base_url_count = max(0, count($seed_urls) - count($menu_urls));
            $post_url_count = 0;
            $term_url_count = 0;

            foreach ($this->get_public_crawl_post_types() as $post_type) {
                $counts = wp_count_posts($post_type);
                if ($counts && isset($counts->publish)) {
                    $post_url_count += (int) $counts->publish;
                }
            }

            foreach ($this->get_public_crawl_taxonomies() as $taxonomy) {
                $count = wp_count_terms(
                    array(
                        'taxonomy'   => $taxonomy,
                        'hide_empty' => false,
                    )
                );

                if (!is_wp_error($count)) {
                    $term_url_count += (int) $count;
                }
            }

            $max_urls = (int) apply_filters('ucwp_max_crawl_urls', 5000);
            if ($max_urls <= 0) {
                $max_urls = 5000;
            }

            $content_url_count = max(0, $post_url_count + $term_url_count);
            $estimated_total = min($max_urls, max(0, count($seed_urls) + $content_url_count));
            $default_scheduled_warm_limit = count($menu_urls);
            if ($default_scheduled_warm_limit < 1) {
                $default_scheduled_warm_limit = count($seed_urls);
            }
            if ($default_scheduled_warm_limit < 1) {
                $default_scheduled_warm_limit = 1;
            }

            return array(
                'baseUrlCount' => max(0, $base_url_count),
                'menuUrlCount' => count($menu_urls),
                'seedUrlCount' => count($seed_urls),
                'postUrlCount' => max(0, $post_url_count),
                'termUrlCount' => max(0, $term_url_count),
                'contentUrlCount' => $content_url_count,
                'estimatedTotal' => max(0, $estimated_total),
                'maxUrls' => $max_urls,
                'defaultScheduledWarmLimit' => max(1, (int) $default_scheduled_warm_limit),
            );
        }

        private function get_crawl_seed_urls($scope = 'full')
        {
            $scope = $this->normalize_crawl_scope($scope);
            $urls = array();

            if ('menu' !== $scope) {
                $urls[] = home_url('/');

                $posts_page_id = (int) get_option('page_for_posts');
                if ($posts_page_id > 0) {
                    $posts_page_url = $this->safe_get_permalink($posts_page_id);
                    if ($posts_page_url) {
                        $urls[] = $posts_page_url;
                    }
                }
            }

            foreach ($this->get_safe_nav_menu_urls() as $menu_url) {
                if ($this->is_cacheable_local_url($menu_url)) {
                    $urls[] = $menu_url;
                }
            }

            $urls = array_values(array_unique(array_filter($urls)));

            return apply_filters('ucwp_crawl_seed_urls', $urls, $scope);
        }

        private function normalize_crawl_scope($scope)
        {
            $scope = is_string($scope) ? strtolower(trim($scope)) : 'full';
            return 'menu' === $scope ? 'menu' : 'full';
        }

        private function safe_get_permalink($post_id)
        {
            try {
                $permalink = get_permalink($post_id);
            } catch (Throwable $e) {
                return '';
            }

            return is_string($permalink) ? $permalink : '';
        }

        private function get_safe_nav_menu_urls()
        {
            $urls = array();
            $menus = wp_get_nav_menus();

            if (empty($menus) || !is_array($menus)) {
                return $urls;
            }

            foreach ($menus as $menu) {
                if (empty($menu) || empty($menu->term_id)) {
                    continue;
                }

                try {
                    $items = wp_get_nav_menu_items($menu->term_id);
                } catch (Throwable $e) {
                    $items = array();
                }

                if (empty($items) || !is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    $url = '';
                    if (is_object($item) && !empty($item->url) && is_string($item->url)) {
                        $url = $item->url;
                    } elseif (is_array($item) && !empty($item['url']) && is_string($item['url'])) {
                        $url = $item['url'];
                    }

                    if ($url !== '') {
                        $urls[] = $url;
                    }
                }
            }

            return array_values(array_unique(array_filter($urls)));
        }

        private function get_public_crawl_post_types()
        {
            $post_types = get_post_types(array('public' => true), 'names');
            return array_values(is_array($post_types) ? $post_types : array());
        }

        private function get_public_crawl_taxonomies()
        {
            $taxonomies = get_taxonomies(array('public' => true), 'names');
            return array_values(is_array($taxonomies) ? $taxonomies : array());
        }

        private function get_default_crawl_cursor_state($scope = 'full')
        {
            return array(
                'scope'           => $this->normalize_crawl_scope($scope),
                'stage'           => 'seed',
                'seed_index'      => 0,
                'post_type_index' => 0,
                'post_offset'     => 0,
                'taxonomy_index'  => 0,
                'term_offset'     => 0,
                'generated'       => 0,
            );
        }

        private function encode_crawl_cursor_state($state)
        {
            if (!is_array($state)) {
                $state = $this->get_default_crawl_cursor_state();
            }

            $encoded = wp_json_encode($state);
            return $encoded ? base64_encode($encoded) : '';
        }

        private function decode_crawl_cursor_state($cursor, $scope = 'full')
        {
            $default = $this->get_default_crawl_cursor_state($scope);
            $cursor = is_string($cursor) ? trim($cursor) : '';

            if ('' === $cursor) {
                return $default;
            }

            $decoded = base64_decode($cursor, true);
            if (false === $decoded || '' === $decoded) {
                return $default;
            }

            $state = json_decode($decoded, true);
            if (!is_array($state)) {
                return $default;
            }

            $allowed_stages = array('seed', 'posts', 'terms', 'done');
            $state = array_merge($default, $state);
            $state['scope'] = $this->normalize_crawl_scope(isset($state['scope']) ? $state['scope'] : $default['scope']);
            $state['stage'] = in_array($state['stage'], $allowed_stages, true) ? $state['stage'] : $default['stage'];
            $state['seed_index'] = max(0, (int) $state['seed_index']);
            $state['post_type_index'] = max(0, (int) $state['post_type_index']);
            $state['post_offset'] = max(0, (int) $state['post_offset']);
            $state['taxonomy_index'] = max(0, (int) $state['taxonomy_index']);
            $state['term_offset'] = max(0, (int) $state['term_offset']);
            $state['generated'] = max(0, (int) $state['generated']);

            return $state;
        }

        private function append_crawl_batch_item(array &$items, array &$batch_seen, $url, array &$state, $max_urls)
        {
            if ((int) $state['generated'] >= (int) $max_urls) {
                $state['stage'] = 'done';
                return false;
            }

            $url = is_string($url) ? trim($url) : '';
            if ('' === $url || !$this->is_cacheable_local_url($url)) {
                return false;
            }

            if (isset($batch_seen[$url])) {
                return false;
            }

            $batch_seen[$url] = true;
            $items[] = $url;
            $state['generated']++;

            return true;
        }

        private function estimate_crawl_url_total($max_urls, $scope = 'full')
        {
            $scope = $this->normalize_crawl_scope($scope);
            $total = count($this->get_crawl_seed_urls($scope));

            if ('menu' === $scope) {
                return min((int) $max_urls, max(0, (int) $total));
            }

            foreach ($this->get_public_crawl_post_types() as $post_type) {
                $counts = wp_count_posts($post_type);
                if ($counts && isset($counts->publish)) {
                    $total += (int) $counts->publish;
                }
            }

            foreach ($this->get_public_crawl_taxonomies() as $taxonomy) {
                $count = wp_count_terms(
                    array(
                        'taxonomy'   => $taxonomy,
                        'hide_empty' => false,
                    )
                );

                if (!is_wp_error($count)) {
                    $total += (int) $count;
                }
            }

            return min((int) $max_urls, max(0, (int) $total));
        }
}
