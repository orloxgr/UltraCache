<?php
/**
 * Warm crawl cache and CSS bundle invalidation integration.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Warm_CSS_Bundle_Trait
{
        private function purge_cache_directory_preserving_google_fonts()
        {
            $root = trailingslashit(ULTRACACHE_CACHE_DIR);
            if ('' === $root || !is_dir($root)) {
                return;
            }

            $items = ultracache_safe_scandir($root, 'purge_all_preserve_google_fonts scandir');
            if (!is_array($items)) {
                return;
            }

            foreach ($items as $item) {
                // Keep runtime locks while purge_all is running; deleting locks/ can orphan the active purge-all.lock FD.
                if ('.' === $item || '..' === $item || 'google-fonts' === $item || 'css-bundles' === $item || 'locks' === $item) {
                    continue;
                }

                $path = $root . $item;
                if (is_dir($path) && !is_link($path)) {
                    $this->recursive_delete($path);
                } else {
                    ultracache_safe_unlink($path);
                }
            }
        }
        public function purge_html_cache_for_delivery_change()
        {
            $lock_name = 'purge-html-delivery-change';
            if (!$this->acquire_runtime_lock($lock_name, 60)) {
                return false;
            }

            try {
                $this->purge_cache_directory_preserving_google_fonts();
                if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'mark_all_cache_asset_refs_inactive')) {
                    Ultra_Cache_WP::mark_all_cache_asset_refs_inactive();
                }
                self::ensure_cache_directories();
                $this->invalidate_dashboard_cache_activity_snapshot();
                $this->record_cache_event('purge-html-delivery-change');
                return true;
            } finally {
                $this->release_runtime_lock($lock_name, true);
            }
        }
        public function purge_frontend_cache_for_lcp_discovery_change()
        {
            $lock_name = 'purge-lcp-discovery-settings';
            if (!$this->acquire_runtime_lock($lock_name, 60)) {
                return false;
            }

            try {
                $this->purge_cache_directory_preserving_google_fonts();
                if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'mark_all_cache_asset_refs_inactive')) {
                    Ultra_Cache_WP::mark_all_cache_asset_refs_inactive();
                }
                self::ensure_cache_directories();
                $this->invalidate_dashboard_cache_activity_snapshot();
                $this->record_cache_event('purge-lcp-discovery-settings');
                return true;
            } finally {
                $this->release_runtime_lock($lock_name, true);
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
                if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'mark_all_cache_asset_refs_inactive')) {
                    Ultra_Cache_WP::mark_all_cache_asset_refs_inactive();
                }
                self::ensure_cache_directories();
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
                do_action('ultracache_after_purge_all', array('scope' => 'all'));
                return true;
            } finally {
                $this->release_runtime_lock($lock_name, true);
            }
        }
        public function purge_url($url)
        {
            return $this->purge_urls(array($url), 'url', array('url' => $url));
        }
        /**
         * Purge only page-cache variants for one URL while preserving generated
         * CSS bundles and their manifest entries.
         *
         * @param string $url Local page URL.
         * @return bool
         */
        public function purge_page_cache_url_only($url)
        {
            $normalized_url = $this->normalize_url($url);
            if ('' === $normalized_url || !$this->is_cacheable_local_url($normalized_url)) {
                return false;
            }

            foreach ($this->get_cache_paths_for_all_buckets($normalized_url) as $file) {
                $this->delete_cache_variants($file);
            }

            $this->record_cache_event('purge-lcp-refresh', array(
                'scope'               => 'lcp-refresh',
                'url'                 => $normalized_url,
                'count'               => 1,
                'urls'                => array($normalized_url),
                'truncated'           => false,
                'preserve_css_bundle' => true,
            ));
            $this->record_analytics_purge('lcp-refresh', $normalized_url);
            do_action('ultracache_after_purge_urls', array($normalized_url), 'lcp-refresh', array(
                'url'                 => $normalized_url,
                'preserve_css_bundle' => true,
            ));

            return true;
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
            do_action('ultracache_after_purge_urls', $purged_urls, (string) $scope, array_merge(array('url' => $primary_url), $payload));

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
        private function invalidate_dashboard_cache_activity_snapshot()
        {
            delete_transient('ultracache_dashboard_cache_activity_v1');
        }
}
