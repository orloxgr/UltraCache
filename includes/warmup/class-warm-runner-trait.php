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
        private function queue_affected_url_plan_rebuild(array $affected_plan, $reason)
        {
            $settings = $this->get_settings();
            if (empty($settings['preload_on_save'])) {
                return array(
                    'success' => true,
                    'queued' => false,
                    'queuedUrlCount' => 0,
                    'message' => __('Warm affected pages after save is disabled.', 'ultracache'),
                );
            }

            $warm_urls = isset($affected_plan['warmUrls']) && is_array($affected_plan['warmUrls'])
                ? array_values($affected_plan['warmUrls'])
                : array();
            if (empty($warm_urls)) {
                return array(
                    'success' => true,
                    'queued' => false,
                    'queuedUrlCount' => 0,
                    'message' => __('No cacheable affected HTML URLs require rebuilding.', 'ultracache'),
                );
            }

            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'enqueue_targeted_warm_pipeline_urls')) {
                return array(
                    'success' => false,
                    'queued' => false,
                    'queuedUrlCount' => 0,
                    'message' => __('The shared affected-page warm pipeline is unavailable.', 'ultracache'),
                );
            }

            $reason = sanitize_key((string) $reason);
            if (0 !== strpos($reason, 'affected-')) {
                $reason = 'affected-' . $reason;
            }

            return Ultra_Cache_WP::enqueue_targeted_warm_pipeline_urls(
                $warm_urls,
                false,
                substr($reason, 0, 32)
            );
        }
        private function exclude_post_permalink_from_affected_url_plan(array $affected_plan, $post_id, $language_code = '')
        {
            $post_id = (int) $post_id;
            if ($post_id <= 0) {
                return $affected_plan;
            }

            $front_page_id = (int) get_option('page_on_front');
            $posts_page_id = (int) get_option('page_for_posts');
            if ($post_id === $front_page_id || $post_id === $posts_page_id) {
                return $affected_plan;
            }

            $permalink = $this->run_warm_language_context(
                $language_code,
                function () use ($post_id) {
                    return $this->safe_get_permalink($post_id);
                }
            );
            $permalink = is_string($permalink) ? trim($permalink) : '';
            if ('' === $permalink) {
                return $affected_plan;
            }

            $permalinks = function_exists('ultracache_multilingual_expand_shared_object_public_urls')
                ? ultracache_multilingual_expand_shared_object_public_urls(array($permalink))
                : array($permalink);
            $excluded = array();
            foreach ($permalinks as $candidate) {
                $candidate = $this->normalize_url((string) $candidate);
                if ('' !== $candidate) {
                    $excluded[$candidate] = true;
                }
            }
            if (empty($excluded)) {
                return $affected_plan;
            }

            $affected_plan['warmUrls'] = array_values(array_filter(
                (array) ($affected_plan['warmUrls'] ?? array()),
                function ($url) use ($excluded) {
                    return !isset($excluded[$this->normalize_url($url)]);
                }
            ));

            return $affected_plan;
        }
        private function exclude_term_permalink_from_affected_url_plan(array $affected_plan, $term_id, $taxonomy, $language_code = '')
        {
            $term_id = absint($term_id);
            $taxonomy = sanitize_key((string) $taxonomy);
            if ($term_id < 1 || '' === $taxonomy) {
                return $affected_plan;
            }

            $term_link = $this->run_warm_language_context(
                $language_code,
                function () use ($term_id, $taxonomy) {
                    $url = get_term_link($term_id, $taxonomy);
                    return is_wp_error($url) ? '' : (string) $url;
                }
            );
            $term_link = trim((string) $term_link);
            if ('' === $term_link) {
                return $affected_plan;
            }

            $term_links = function_exists('ultracache_multilingual_expand_shared_object_public_urls')
                ? ultracache_multilingual_expand_shared_object_public_urls(array($term_link))
                : array($term_link);
            $excluded = array();
            foreach ($term_links as $candidate) {
                $candidate = $this->normalize_url((string) $candidate);
                if ('' !== $candidate) {
                    $excluded[$candidate] = true;
                }
            }
            if (empty($excluded)) {
                return $affected_plan;
            }

            $affected_plan['warmUrls'] = array_values(array_filter(
                (array) ($affected_plan['warmUrls'] ?? array()),
                function ($url) use ($excluded) {
                    return !isset($excluded[$this->normalize_url($url)]);
                }
            ));

            return $affected_plan;
        }

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

            if (method_exists($this, 'ultracache_reset_lcp_observation_lifecycle_for_post')) {
                $this->ultracache_reset_lcp_observation_lifecycle_for_post($post_id);
            }

            $settings = $this->get_settings();
            $warm = !empty($settings['preload_on_save'])
                && $this->acquire_post_save_warm_cooldown($post_id);
            $this->record_affected_post_change(
                $post_id,
                'publish' === $post->post_status ? 'post-save' : 'post-status',
                $warm
            );
        }
        /**
         * Reconcile one translated post after WPML has finalized translation state.
         *
         * save_post remains the normal WordPress trigger. This additive WPML hook
         * updates the coalesced record with the finalized language code and does
         * not create a sibling-translation purge fan-out.
         */
        public function handle_wpml_after_save_post($post_id, $trid = 0, $language_code = '', $source_language = '')
        {
            $post_id = absint($post_id);
            if ($post_id < 1 || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
                return;
            }

            $post = get_post($post_id);
            if (!$post || 'revision' === $post->post_type || 'auto-draft' === $post->post_status) {
                return;
            }

            $language_code = function_exists('ultracache_wpml_normalize_language_code')
                ? ultracache_wpml_normalize_language_code($language_code)
                : '';
            if ('' === $language_code && method_exists($this, 'get_wpml_post_language_code')) {
                $language_code = $this->get_wpml_post_language_code($post_id);
            }

            $settings = $this->get_settings();
            $warm = !empty($settings['preload_on_save'])
                && $this->acquire_post_save_warm_cooldown($post_id);
            $this->record_affected_post_change(
                $post_id,
                'wpml-post-save',
                $warm,
                $language_code
            );
        }

        public function handle_post_deletion($post_id)
        {
            $post_id = (int) $post_id;
            if ($post_id <= 0 || wp_is_post_revision($post_id)) {
                return;
            }

            $semantic_tags = method_exists($this, 'get_litespeed_semantic_invalidation_tags_for_post')
                ? $this->get_litespeed_semantic_invalidation_tags_for_post($post_id)
                : array();
            if (!empty($semantic_tags) && method_exists($this, 'queue_litespeed_semantic_invalidation_tags')) {
                $this->queue_litespeed_semantic_invalidation_tags($semantic_tags, 'post-delete');
            }

            $language_code = method_exists($this, 'get_wpml_post_language_code')
                ? $this->get_wpml_post_language_code($post_id)
                : '';
            $affected_plan = $this->exclude_post_permalink_from_affected_url_plan(
                $this->get_affected_url_plan_for_post($post_id, $language_code),
                $post_id,
                $language_code
            );
            $this->purge_urls(
                $affected_plan['purgeUrls'],
                'related-post-delete',
                array(
                    'post_id' => $post_id,
                    'language' => $language_code,
                    'warm_url_count' => count($affected_plan['warmUrls']),
                )
            );
            $this->queue_affected_url_plan_rebuild($affected_plan, 'post-delete');
        }
        public function handle_term_update($term_id, $tt_id = 0, $taxonomy = '')
        {
            $term_id = (int) $term_id;
            $taxonomy = is_string($taxonomy) ? $taxonomy : '';
            if ($term_id <= 0 || '' === $taxonomy) {
                return;
            }

            $this->record_affected_term_change($term_id, $taxonomy, 'term-update', true);
        }
        public function handle_term_deletion($term_id, $taxonomy)
        {
            $term_id = absint($term_id);
            $taxonomy = sanitize_key((string) $taxonomy);
            if ($term_id < 1 || '' === $taxonomy) {
                return;
            }

            $taxonomy_object = get_taxonomy($taxonomy);
            if (!$taxonomy_object || empty($taxonomy_object->public)) {
                return;
            }

            $language_code = method_exists($this, 'get_wpml_term_language_code')
                ? $this->get_wpml_term_language_code($term_id, $taxonomy)
                : '';
            $affected_plan = $this->exclude_term_permalink_from_affected_url_plan(
                $this->get_affected_url_plan_for_term($term_id, $taxonomy, $language_code),
                $term_id,
                $taxonomy,
                $language_code
            );

            $this->purge_urls(
                $affected_plan['purgeUrls'],
                'related-term-delete',
                array(
                    'term_id' => $term_id,
                    'taxonomy' => $taxonomy,
                    'language' => $language_code,
                    'warm_url_count' => count($affected_plan['warmUrls']),
                )
            );
            $this->queue_affected_url_plan_rebuild($affected_plan, 'term-delete');
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

            $this->record_affected_term_assignment_change(
                $object_id,
                $taxonomy,
                (array) $terms,
                (array) $tt_ids,
                (array) $old_tt_ids,
                'publish' === $post->post_status
            );
        }
        public function handle_navigation_update($menu_id = 0, $menu_data = array())
        {
            $this->purge_urls(
                $this->get_all_language_site_front_urls(true),
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

            $this->purge_urls($this->get_all_language_site_front_urls(true), 'widgets');
        }
        public function handle_front_page_option_change($old_value = null, $value = null)
        {
            if ((string) $old_value === (string) $value) {
                return;
            }

            $this->purge_urls($this->get_all_language_site_front_urls(true), 'front-settings');
        }
        public function handle_global_frontend_change()
        {
            $this->clear_runtime_font_css_map_cache();
            $this->delete_frontpage_css_bundle();
            $this->purge_urls($this->get_all_language_site_front_urls(true), 'global-front');
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
        private function get_loopback_response_cookie_diagnostics($response)
        {
            $names_header = wp_remote_retrieve_header($response, 'x-ultra-cache-set-cookie-names');
            if (is_array($names_header)) {
                $names_header = implode(',', array_map('strval', $names_header));
            }
            $names = array();
            foreach (explode(',', (string) $names_header) as $name) {
                $name = preg_replace('/[^A-Za-z0-9_\-.]/', '', trim((string) $name));
                if ('' !== (string) $name) {
                    $names[(string) $name] = (string) $name;
                }
            }

            $policy = wp_remote_retrieve_header($response, 'x-ultra-cache-response-cookie-policy');
            if (is_array($policy)) {
                $policy = end($policy);
            }

            return array(
                'names' => array_values($names),
                'policy' => sanitize_key((string) $policy),
            );
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
        private function is_runtime_lock_active($name)
        {
            $name = (string) $name;
            if ('' === $name) {
                return false;
            }

            $file = $this->get_runtime_lock_file($name);
            if (!file_exists($file)) {
                return false;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native flock probing is required for UltraCache runtime lock ownership.
            $handle = @fopen($file, 'c+');
            if (!$handle) {
                return true;
            }

            $active = !@flock($handle, LOCK_EX | LOCK_NB);
            if (!$active) {
                @flock($handle, LOCK_UN);
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing native flock probe handle.
            @fclose($handle);
            return $active;
        }

        /**
         * Wait until cache mutation locks owned before Flush All have drained.
         *
         * The active purge-all lock prevents new mutation locks from being
         * acquired while this method waits for existing page/CSS owners.
         *
         * @return bool
         */
        private function wait_for_cache_mutation_locks_to_drain()
        {
            $dir = $this->get_runtime_locks_dir();
            if (!is_dir($dir)) {
                return true;
            }

            $files = (array) glob(trailingslashit($dir) . '*.lock');
            foreach ($files as $file) {
                $file = wp_normalize_path((string) $file);
                $base = basename($file);
                if (!preg_match('/^(?:page-cache-write-[a-f0-9]{32}|page-cache-build-(?:[a-f0-9]{32}|slot-[0-9]+)|css-(?:bundle|entry)-[a-f0-9]{32})\.lock$/i', $base)) {
                    continue;
                }

                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Blocking flock is required to establish the Flush All cache-mutation barrier.
                $handle = @fopen($file, 'c+');
                if (!$handle) {
                    return false;
                }
                if (!@flock($handle, LOCK_EX)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing native lock handle after failed barrier acquisition.
                    @fclose($handle);
                    return false;
                }
                @flock($handle, LOCK_UN);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing native lock barrier handle.
                @fclose($handle);
            }

            return true;
        }

        private function acquire_runtime_lock($name, $ttl = 180)
        {
            $name = (string) $name;
            if ('' === $name) {
                return false;
            }

            if ('purge-all' !== $name && $this->is_runtime_lock_active('purge-all')) {
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

            // A direct caller that requests targeted invalidation must enter the
            // shared page pipeline first so purge and rebuild are protected by the
            // same per-URL ownership lock. The pipeline removes this flag before
            // delegating back to warm_url(), preventing recursion.
            if (!empty($args['purge_target_first']) && method_exists($this, 'warm_page_pipeline')) {
                if (empty($args['warm_context'])) {
                    $args['warm_context'] = 'manual';
                }
                return $this->warm_page_pipeline($url, $args);
            }

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
            $force_apache_static_alias = !empty($args['force_apache_static_alias']);
            $execution_profile = sanitize_key((string) ($args['execution_profile'] ?? 'default'));
            $php_max_execution = function_exists('ultracache_get_php_max_execution_time_seconds')
                ? ultracache_get_php_max_execution_time_seconds()
                : max(0, (int) ini_get('max_execution_time'));
            $profile_hard_caps = array(
                'cron' => $php_max_execution,
                'ui' => $php_max_execution,
                'cli' => $php_max_execution,
                'visit' => $php_max_execution,
                'default' => $php_max_execution,
            );
            if (!isset($profile_hard_caps[$execution_profile])) {
                $execution_profile = 'default';
            }
            $settings_for_warm = $this->get_settings();
            $operation_budget = function_exists('ultracache_get_safe_operation_budget')
                ? ultracache_get_safe_operation_budget(
                    'warm_url_' . $execution_profile,
                    $args['time_budget'] ?? null,
                    $profile_hard_caps[$execution_profile]
                )
                : array();
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

            // Warm loopbacks explicitly request a raw rendered source document. The
            // public cache transformer is owned by this runner, so one WordPress render can
            // be finalized exactly once for each orig/WebP/AVIF storage bucket.
            $shared_source_render = count($buckets) > 1 && in_array('orig', $buckets, true);
            $request_buckets = $shared_source_render ? array('orig') : $buckets;

            $skip_css_bundle = !empty($args['skip_css_bundle']);
            $css_bundle_requested = !$skip_css_bundle && !empty($args['build_css_bundle']);
            $css_bundle_auto_warm = !$skip_css_bundle
                && !$css_bundle_requested
                && !empty($settings_for_warm['homepage_css_bundle']);
            $css_bundle_result = array();
            $css_bundle_build_attempted = false;
            $css_bundle_build_required = $css_bundle_requested || $css_bundle_auto_warm;

            $cached_files = array();
            $cached_buckets = array();
            $failed_buckets = array();
            $bucket_errors = array();
            $response_cookie_names_observed = array();
            $response_cookie_policies_observed = array();
            $last_error = '';
            $operation_pause_reason = '';
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

            foreach ($request_buckets as $request_bucket) {
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
                            'ultracache_bucket'     => sanitize_key((string) $request_bucket),
                        ),
                        $url
                    );
                }
                if (!$run_heartbeat('html-bucket-' . sanitize_key((string) $request_bucket) . '-before')) {
                    return $ownership_lost_result($url, $buckets, $cached_files);
                }
                $pause_reason = function_exists('ultracache_operation_pause_reason') ? ultracache_operation_pause_reason($operation_budget) : '';
                if ('' !== $pause_reason) {
                    $operation_pause_reason = sanitize_key((string) $pause_reason);
                    $last_error = 'Warm paused by ' . $operation_pause_reason . '.';
                    $retryable_failure = true;
                    break;
                }
                $accept_header = $this->get_accept_header_for_bucket($request_bucket);
                $request_timeout = $php_max_execution > 0 ? $php_max_execution : 0;
                $request_args = array(
                    'method'      => 'GET',
                    'timeout'     => $request_timeout,
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
                            'X-UltraCache-Warm-Source'        => 'raw-v1',
                            'X-UltraCache-Force-Refresh'      => $force_refresh ? '1' : '',
                            'X-UltraCache-Revalidate'         => $force_refresh ? '1' : '',
                            'X-UltraCache-Token'              => $internal_request_token,
                            'X-UltraCache-VCL-Signature'      => $force_refresh && function_exists('ultracache_get_varnish_revalidation_vcl_signature') ? ultracache_get_varnish_revalidation_vcl_signature() : '',
                            'Cache-Control'                   => 'no-cache, no-store, must-revalidate, max-age=0',
                            'Pragma'                          => 'no-cache',
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
                    if (!$run_heartbeat('html-bucket-' . sanitize_key((string) $request_bucket) . '-after')) {
                        return $ownership_lost_result($url, $buckets, $cached_files);
                    }
                    continue;
                }

                if (!$run_heartbeat('html-bucket-' . sanitize_key((string) $request_bucket) . '-network-after')) {
                    return $ownership_lost_result($url, $buckets, $cached_files);
                }
                $code = (int) wp_remote_retrieve_response_code($response);
                $html = wp_remote_retrieve_body($response);
                $warm_body_contract = strtolower(trim((string) wp_remote_retrieve_header($response, 'x-ultracache-warm-body-contract')));
                $response_body_is_raw = 'raw-v1' === $warm_body_contract;
                $litespeed_semantic_tags = null;
                $litespeed_tag_header = trim((string) wp_remote_retrieve_header($response, 'x-litespeed-tag'));
                if ('' !== $litespeed_tag_header) {
                    $candidate_litespeed_tags = preg_split('/\s*,\s*/', $litespeed_tag_header);
                    $candidate_litespeed_tags = is_array($candidate_litespeed_tags) ? $candidate_litespeed_tags : array();
                    $litespeed_semantic_tags = function_exists('ultracache_normalize_litespeed_cache_tags')
                        ? ultracache_normalize_litespeed_cache_tags($candidate_litespeed_tags, 48)
                        : array_values(array_unique(array_filter(array_map('strval', $candidate_litespeed_tags))));
                }
                $response_cookie_diagnostics = $this->get_loopback_response_cookie_diagnostics($response);
                foreach ((array) ($response_cookie_diagnostics['names'] ?? array()) as $response_cookie_name) {
                    $response_cookie_names_observed[(string) $response_cookie_name] = (string) $response_cookie_name;
                }
                $response_cookie_policy = sanitize_key((string) ($response_cookie_diagnostics['policy'] ?? ''));
                if ('' !== $response_cookie_policy) {
                    $response_cookie_policies_observed[$response_cookie_policy] = $response_cookie_policy;
                }
                if ($force_refresh) {
                    $force_refresh_marker = trim((string) wp_remote_retrieve_header($response, 'x-ultra-cache-force-refresh'));
                    $reached_origin = 'wp-engine' === strtolower($force_refresh_marker);
                    if ($reached_origin) {
                        $force_refresh_reached_bucket_count += $shared_source_render ? count($buckets) : 1;
                    }
                    $force_refresh_details[] = array(
                        'bucket' => (string) $request_bucket,
                        'httpCode' => $code,
                        'reachedOrigin' => $reached_origin,
                        'marker' => sanitize_text_field($force_refresh_marker),
                        'bodyContract' => sanitize_key($warm_body_contract),
                        'responseBodyRaw' => $response_body_is_raw,
                        'transport' => $shared_source_render ? 'shared-source-render' : 'public-route',
                        'sharedSourceRender' => $shared_source_render,
                        'derivedBuckets' => $shared_source_render ? array_values($buckets) : array((string) $request_bucket),
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
                            'responseCookieNames' => array_values((array) ($response_cookie_diagnostics['names'] ?? array())),
                            'responseCookiePolicy' => $response_cookie_policy,
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
                        $result['cssBundle'] = array('success' => false, 'skipped' => true, 'outcome' => 'dependency-skipped', 'message' => __('CSS bundle skipped because the page was not eligible for HTML cache warm-up.', 'ultracache'));
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
                        $result['cssBundle'] = array('success' => false, 'skipped' => true, 'outcome' => 'dependency-skipped', 'message' => __('CSS bundle skipped because the remote response was not HTML.', 'ultracache'));
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
                        'responseCookieNames' => array_values($response_cookie_names_observed),
                        'responseCookiePolicies' => array_values($response_cookie_policies_observed),
                    );
                    if ($css_bundle_requested) {
                        $result['cssBundle'] = array('success' => false, 'skipped' => true, 'outcome' => 'dependency-skipped', 'message' => __('CSS bundle skipped because the page rejected cache storage.', 'ultracache'));
                    }
                    $this->record_analytics_warm($url, $result);
                    return $result;
                }

                if ($css_bundle_build_required && !$css_bundle_build_attempted) {
                    $css_bundle_build_attempted = true;
                    $bundle_scope = $this->get_css_bundle_scope($settings_for_warm);
                    $css_bundle_result = array('success' => false, 'skipped' => true, 'outcome' => 'scope-skipped', 'message' => __('CSS bundle skipped for this URL by the selected CSS Bundling Scope.', 'ultracache'));
                    $should_build_bundle_for_url = ('per-page' === $bundle_scope || $this->is_frontpage_request_url($url));
                    if ($should_build_bundle_for_url && empty($this->get_frontpage_css_manifest_entry($url))) {
                        if (!$run_heartbeat('css-before')) {
                            return $ownership_lost_result($url, $buckets, $cached_files);
                        }
                        // Build the CSS bundle only after the warm loopback proved that the
                        // public page returns cacheable HTML. This keeps manual warm-up progress
                        // aligned with the actual work and prevents CSS scans for 404/feed/non-HTML URLs.
                        $css_bundle_result = $this->build_frontpage_css_bundle($url, array(
                            'skip_final_warm' => true,
                            'request_timeout' => $request_timeout,
                            'source_html' => (string) $html,
                            '_warm_pipeline_heartbeat' => $heartbeat,
                        ));
                        if (!isset($css_bundle_result['outcome'])) {
                            $css_bundle_result['outcome'] = !empty($css_bundle_result['success'])
                                ? 'built'
                                : (!empty($css_bundle_result['skipped']) ? 'skipped' : 'failed');
                        }
                        if (!empty($css_bundle_result['ownershipLost'])) {
                            return $ownership_lost_result($url, $buckets, $cached_files);
                        }
                        if (!$run_heartbeat('css-after')) {
                            return $ownership_lost_result($url, $buckets, $cached_files);
                        }
                    } elseif ($should_build_bundle_for_url) {
                        $css_bundle_result = array('success' => true, 'skipped' => true, 'outcome' => 'reused', 'message' => __('Existing CSS bundle manifest entry found for this URL.', 'ultracache'));
                    }
                }

                $source_html = (string) $html;
                $storage_buckets = $shared_source_render ? $buckets : array($request_bucket);

                foreach ($storage_buckets as $bucket) {
                    if (!$run_heartbeat('html-bucket-' . sanitize_key((string) $bucket) . '-storage-before')) {
                        return $ownership_lost_result($url, $buckets, $cached_files);
                    }

                    $bucket_html = $source_html;
                    $bucket_accept_header = $this->get_accept_header_for_bucket($bucket);
                    if (method_exists($this, 'process_final_html_for_cache_storage')) {
                        // warm_url() can execute inside an authenticated admin/REST
                        // request, but $bucket_html came from the authenticated raw-v1
                        // loopback whose WordPress render is anonymous frontend output.
                        // Scope the logged-in optimization bypass off only while this
                        // public cache document is finalized, then restore the caller.
                        $had_public_transform_scope = array_key_exists('ultracache_anonymous_public_cache_transform', $GLOBALS);
                        $previous_public_transform_scope = $had_public_transform_scope
                            ? $GLOBALS['ultracache_anonymous_public_cache_transform']
                            : null;
                        $GLOBALS['ultracache_anonymous_public_cache_transform'] = true;
                        try {
                            $processed_html = $this->process_final_html_for_cache_storage($bucket_html, false, array(
                                'accept'               => $bucket_accept_header,
                                'bucket'               => (string) $bucket,
                                'source'               => $shared_source_render ? 'warm_url_shared_source' : 'warm_url',
                                'url'                  => $url,
                                'request_url'          => $url,
                                'public_cache_storage' => true,
                            ));
                        } finally {
                            if ($had_public_transform_scope) {
                                $GLOBALS['ultracache_anonymous_public_cache_transform'] = $previous_public_transform_scope;
                            } else {
                                unset($GLOBALS['ultracache_anonymous_public_cache_transform']);
                            }
                        }
                        if (is_string($processed_html) && '' !== $processed_html) {
                            $bucket_html = $processed_html;
                        }
                    }

                    $elementor_dependency_error = method_exists($this, 'get_elementor_page_css_dependency_error')
                        ? (string) $this->get_elementor_page_css_dependency_error()
                        : '';
                    if ('' !== $elementor_dependency_error) {
                        $last_error = $elementor_dependency_error;
                        $failed_buckets[] = (string) $bucket;
                        $bucket_errors[(string) $bucket] = array(
                            'bucket' => (string) $bucket,
                            'code' => 'elementor-css-dependency-unresolved',
                            'message' => $elementor_dependency_error,
                            'file' => '',
                            'rotationFailedHash' => '',
                        );
                        $terminal_failure = true;
                        if (!$run_heartbeat('html-bucket-' . sanitize_key((string) $bucket) . '-after')) {
                            return $ownership_lost_result($url, $buckets, $cached_files);
                        }
                        continue;
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

                    $wrote = $this->write_cache_file($file_path, $bucket_html, $url, $litespeed_semantic_tags, $force_apache_static_alias);
                    if (!$wrote || !file_exists($file_path)) {
                        $write_error_details = method_exists($this, 'get_last_cache_write_error')
                            ? $this->get_last_cache_write_error()
                            : array();
                        $write_error = method_exists($this, 'get_last_cache_write_error_message') ? $this->get_last_cache_write_error_message() : '';
                        $write_error_code = sanitize_key((string) ($write_error_details['code'] ?? 'cache-write-failed'));
                        $last_error = '' !== (string) $write_error ? 'Failed to write cache file: ' . (string) $write_error : 'Failed to write cache file.';
                        $failed_buckets[] = (string) $bucket;
                        $bucket_errors[(string) $bucket] = array(
                            'bucket' => (string) $bucket,
                            'code' => $write_error_code,
                            'message' => (string) ($write_error_details['message'] ?? $last_error),
                            'file' => basename((string) ($write_error_details['file'] ?? $file_path)),
                            'rotationFailedHash' => (string) ($write_error_details['rotationFailedHash'] ?? ''),
                        );
                        if ('query_variant_limit_reached' === $write_error_code) {
                            $terminal_failure = true;
                        } else {
                            $retryable_failure = true;
                        }
                        if (!$run_heartbeat('html-bucket-' . sanitize_key((string) $bucket) . '-after')) {
                            return $ownership_lost_result($url, $buckets, $cached_files);
                        }
                        continue;
                    }

                    $cached_files[] = $file_path;
                    $cached_buckets[] = (string) $bucket;
                    if (!$run_heartbeat('html-bucket-' . sanitize_key((string) $bucket) . '-after')) {
                        return $ownership_lost_result($url, $buckets, $cached_files);
                    }
                }
            }

            $success = !empty($buckets) && count($cached_files) === count($buckets);
            $partial = !empty($cached_files) && count($cached_files) < count($buckets);
            $failed_buckets = array_values(array_unique(array_map('strval', $failed_buckets)));
            $cached_buckets = array_values(array_unique(array_map('strval', $cached_buckets)));
            $query_variant_limit_skipped = !$success && empty($cached_files) && !empty($bucket_errors);
            if ($query_variant_limit_skipped) {
                foreach ($bucket_errors as $bucket_error) {
                    if ('query_variant_limit_reached' !== sanitize_key((string) ($bucket_error['code'] ?? ''))) {
                        $query_variant_limit_skipped = false;
                        break;
                    }
                }
            }
            $partial_message = __('Only some requested HTML variants were cached.', 'ultracache');
            if ($partial && !empty($failed_buckets)) {
                $failed_labels = array();
                foreach ($failed_buckets as $failed_bucket) {
                    $failed_code = sanitize_key((string) ($bucket_errors[$failed_bucket]['code'] ?? ''));
                    $failed_labels[] = '' !== $failed_code
                        ? $failed_bucket . ' [' . $failed_code . ']'
                        : $failed_bucket;
                }
                $partial_message .= ' Failed buckets: ' . implode(', ', $failed_labels) . '.';
            }
            if ($success) {
                $this->record_cache_event('warm', array('url' => $url, 'files' => $cached_files, 'cached_buckets' => $cached_buckets));
            }

            $html_failure_class = '';
            if ($query_variant_limit_skipped) {
                $html_failure_class = 'query-variant-limit-reached';
            } elseif (!$success) {
                $html_failure_class = $terminal_failure ? 'html-terminal' : ($retryable_failure ? 'html-transient' : 'cache-write-failed');
            }
            if ($partial) {
                $html_failure_class = 'partial-html-variants';
                foreach ($bucket_errors as $bucket_error) {
                    if ('query_variant_limit_reached' === sanitize_key((string) ($bucket_error['code'] ?? ''))) {
                        $html_failure_class = 'query-variant-limit-reached';
                        break;
                    }
                }
            }

            $result = array(
                'success' => $success,
                'cached'  => $success,
                'skipped' => $query_variant_limit_skipped,
                'url'     => $url,
                'message' => $success
                    ? ($css_bundle_requested ? __('Cached + CSS.', 'ultracache') : __('Cached.', 'ultracache'))
                    : ($query_variant_limit_skipped
                        ? self::maybe_translate('Query cache combination limit reached; this new query variant intentionally bypassed cache.')
                        : ($partial ? $partial_message : ('' !== $last_error ? $last_error : __('Cache write failed.', 'ultracache')))),
                'files'   => $cached_files,
                'buckets' => $buckets,
                'cachedBuckets' => $cached_buckets,
                'failedBuckets' => $failed_buckets,
                'bucketErrors' => $bucket_errors,
                'responseCookieNames' => array_values($response_cookie_names_observed),
                'responseCookiePolicies' => array_values($response_cookie_policies_observed),
                'sharedSourceRender' => $shared_source_render,
                'sourceRenderCount' => count($request_buckets),
                'retryable' => !$success && !$query_variant_limit_skipped && $retryable_failure && !$terminal_failure,
                'terminal' => !$success && ($query_variant_limit_skipped || !$retryable_failure || $terminal_failure),
                'deferred' => !$success && '' !== $operation_pause_reason,
                'pauseReason' => $operation_pause_reason,
                'failureClass' => '' !== $operation_pause_reason
                    ? 'operation-budget'
                    : $html_failure_class,
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

            if (!function_exists('ultracache_acquire_lock')) {
                return false;
            }

            $lock_name = 'ultracache_post_save_warm_cooldown_' . $post_id;
            $lock_token = 'post-save-warm-' . $post_id . '-' . wp_generate_password(20, false, false);
            return ultracache_acquire_lock(
                $lock_name,
                $lock_token,
                $cooldown,
                array(
                    'postId' => $post_id,
                    'acquiredAt' => time(),
                    'cooldownSeconds' => $cooldown,
                )
            );
        }
}
