<?php
/** Hotfix Bundle Version: 2.55.72 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Ultra_Cache_Engine')) {
    class Ultra_Cache_Engine
    {
        /** @var Ultra_Cache_Engine|null */
        private static $instance = null;

        /** @var bool */
        private $buffering = false;

        /** @var string */
        private $last_bypass_reason = '';

        public static function get_instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        private function __construct()
        {
            $this->register_hooks();
        }

        private function register_hooks()
        {
            add_action('template_redirect', array($this, 'maybe_start_buffering'), 0);
            add_action('save_post', array($this, 'handle_post_update'), 20);
            add_action('delete_post', array($this, 'handle_post_deletion'), 20);
            add_action('trashed_post', array($this, 'handle_post_deletion'), 20);
            add_action('untrashed_post', array($this, 'handle_post_update'), 20);
            add_action('woocommerce_update_product', array($this, 'handle_woocommerce_object_update'), 20);
            add_action('woocommerce_new_product', array($this, 'handle_woocommerce_object_update'), 20);
            add_action('woocommerce_update_product_variation', array($this, 'handle_woocommerce_object_update'), 20);
            add_action('woocommerce_variation_set_stock', array($this, 'handle_woocommerce_object_update'), 20);
            add_action('woocommerce_product_set_stock_status', array($this, 'handle_woocommerce_object_update'), 20);
            add_action('edited_term', array($this, 'handle_term_update'), 20, 3);
            add_action('created_term', array($this, 'handle_term_update'), 20, 3);
            add_action('set_object_terms', array($this, 'handle_object_terms_set'), 20, 6);
            add_action('wp_update_nav_menu', array($this, 'handle_navigation_update'), 20, 2);
            add_action('customize_save_after', array($this, 'handle_global_frontend_change'), 20);
            add_action('update_option_sidebars_widgets', array($this, 'handle_sidebars_widgets_update'), 20, 2);
            add_action('switch_theme', array($this, 'handle_global_frontend_change'), 20);
            add_action('update_option_show_on_front', array($this, 'handle_front_page_option_change'), 20, 2);
            add_action('update_option_page_on_front', array($this, 'handle_front_page_option_change'), 20, 2);
            add_action('update_option_page_for_posts', array($this, 'handle_front_page_option_change'), 20, 2);
            add_action('update_option_posts_per_page', array($this, 'handle_front_page_option_change'), 20, 2);
            add_action('update_option_permalink_structure', array($this, 'handle_global_frontend_change'), 20, 2);
            add_action('wp_head', array($this, 'print_delayed_script_loader'), 1);
            add_action('wp_enqueue_scripts', array($this, 'cleanup_asset_chain_enqueue_assets'), 9999);
            add_filter('script_loader_tag', array($this, 'defer_scripts'), 10, 3);
            add_filter('style_loader_src', array($this, 'add_display_swap_to_google_fonts'), 20, 2);
        }

        public static function activate()
        {
            self::ensure_cache_directories();
            self::setup_advanced_cache();
        }

        public static function deactivate()
        {
            self::maybe_remove_advanced_cache();
        }

        public static function ensure_cache_directories()
        {
            $dirs = array(
                UCWP_CACHE_DIR,
                UCWP_AVIF_DIR,
                UCWP_WEBP_DIR,
                trailingslashit(UCWP_CACHE_DIR) . 'google-fonts/',
            );

            foreach ($dirs as $dir) {
                if (!file_exists($dir)) {
                    wp_mkdir_p($dir);
                }

                $index_file = trailingslashit($dir) . 'index.php';
                if (!file_exists($index_file)) {
                    ucwp_safe_file_put_contents($index_file, "<?php\n// Silence is golden.\n");
                }
            }
        }

        private static function get_analytics_file()
        {
            return trailingslashit(UCWP_CACHE_DIR) . 'analytics.json';
        }

        private static function get_analytics_hit_buffer_file()
        {
            return trailingslashit(UCWP_CACHE_DIR) . 'analytics-hit-buffer.log';
        }

        private static function get_analytics_hit_buffer_key_prefix()
        {
            $base = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : UCWP_CACHE_DIR;
            return 'ucwp_analytics_hit_buffer_' . md5((string) $base) . '_';
        }

        private static function analytics_apcu_available()
        {
            if (!function_exists('apcu_fetch') || !function_exists('apcu_inc') || !function_exists('apcu_dec') || !function_exists('apcu_delete')) {
                return false;
            }

            if (function_exists('apcu_enabled') && !apcu_enabled()) {
                return false;
            }

            return true;
        }

        private static function get_analytics_hit_buffer_counters()
        {
            return array(
                'pageHits',
                'pageStaleHits',
                'bucket_orig',
                'bucket_webp',
                'bucket_avif',
                'encoding_identity',
                'encoding_gzip',
                'encoding_brotli',
            );
        }

        private static function apply_analytics_hit_delta(array &$analytics, $counter, $amount)
        {
            $amount = max(0, (int) $amount);
            if ($amount <= 0) {
                return;
            }

            $counter = (string) $counter;
            if ('pageHits' === $counter || 'pageStaleHits' === $counter) {
                $analytics[$counter] = (int) ($analytics[$counter] ?? 0) + $amount;
                return;
            }

            if (0 === strpos($counter, 'bucket_')) {
                $bucket = substr($counter, 7);
                if (!in_array($bucket, array('orig', 'webp', 'avif'), true)) {
                    return;
                }
                if (!isset($analytics['bucketHits']) || !is_array($analytics['bucketHits'])) {
                    $analytics['bucketHits'] = array('orig' => 0, 'webp' => 0, 'avif' => 0);
                }
                $analytics['bucketHits'][$bucket] = (int) ($analytics['bucketHits'][$bucket] ?? 0) + $amount;
                return;
            }

            if (0 === strpos($counter, 'encoding_')) {
                $encoding = substr($counter, 9);
                if (!in_array($encoding, array('identity', 'gzip', 'brotli'), true)) {
                    return;
                }
                if (!isset($analytics['encodingHits']) || !is_array($analytics['encodingHits'])) {
                    $analytics['encodingHits'] = array('identity' => 0, 'gzip' => 0, 'brotli' => 0);
                }
                $analytics['encodingHits'][$encoding] = (int) ($analytics['encodingHits'][$encoding] ?? 0) + $amount;
            }
        }

        private static function snapshot_apcu_analytics_hit_buffer()
        {
            if (!self::analytics_apcu_available()) {
                return array();
            }

            $prefix = self::get_analytics_hit_buffer_key_prefix();
            $deltas = array();
            foreach (self::get_analytics_hit_buffer_counters() as $counter) {
                $success = false;
                $value = apcu_fetch($prefix . $counter, $success);
                $value = $success ? max(0, (int) $value) : 0;
                if ($value > 0) {
                    $deltas[$counter] = $value;
                }
            }

            return $deltas;
        }

        private static function decrement_apcu_analytics_hit_buffer(array $deltas)
        {
            if (!self::analytics_apcu_available() || empty($deltas)) {
                return;
            }

            $prefix = self::get_analytics_hit_buffer_key_prefix();
            foreach ($deltas as $counter => $amount) {
                $amount = max(0, (int) $amount);
                if ($amount > 0) {
                    @apcu_dec($prefix . (string) $counter, $amount);
                }
            }
        }

        private static function consume_file_analytics_hit_buffer()
        {
            $file = self::get_analytics_hit_buffer_file();
            if (!file_exists($file) || !is_readable($file)) {
                return array();
            }

            $handle = @fopen($file, 'c+');
            if (!$handle) {
                return array();
            }

            $raw = '';
            if (@flock($handle, LOCK_EX)) {
                rewind($handle);
                $raw = stream_get_contents($handle);
                ftruncate($handle, 0);
                rewind($handle);
                @flock($handle, LOCK_UN);
            }
            fclose($handle);

            if (!is_string($raw) || '' === trim($raw)) {
                return array();
            }

            $deltas = array();
            foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
                if ('' === $line) {
                    continue;
                }
                $parts = explode("\t", $line);
                if (count($parts) < 3) {
                    continue;
                }

                $state = strtoupper((string) $parts[0]);
                $bucket = in_array($parts[1], array('orig', 'webp', 'avif'), true) ? $parts[1] : 'orig';
                $encoding = in_array($parts[2], array('identity', 'gzip', 'brotli'), true) ? $parts[2] : 'identity';

                $hit_counter = ('S' === $state) ? 'pageStaleHits' : 'pageHits';
                foreach (array($hit_counter, 'bucket_' . $bucket, 'encoding_' . $encoding) as $counter) {
                    if (!isset($deltas[$counter])) {
                        $deltas[$counter] = 0;
                    }
                    $deltas[$counter]++;
                }
            }

            return $deltas;
        }

        private static function clear_analytics_hit_buffer()
        {
            if (self::analytics_apcu_available()) {
                $prefix = self::get_analytics_hit_buffer_key_prefix();
                foreach (self::get_analytics_hit_buffer_counters() as $counter) {
                    @apcu_delete($prefix . $counter);
                }
                @apcu_delete($prefix . 'total');
                @apcu_delete($prefix . 'last_flush');
                @apcu_delete($prefix . 'flush_lock');
            }

            ucwp_safe_unlink(self::get_analytics_hit_buffer_file(), 'analytics_hit_buffer_clear');
        }

        private static function flush_analytics_hit_buffer()
        {
            $apcu_lock_acquired = false;
            $prefix = self::get_analytics_hit_buffer_key_prefix();
            if (self::analytics_apcu_available()) {
                if (function_exists('apcu_add') && @apcu_add($prefix . 'flush_lock', 1, 10)) {
                    $apcu_lock_acquired = true;
                }
            }

            $apcu_deltas = $apcu_lock_acquired ? self::snapshot_apcu_analytics_hit_buffer() : array();
            $file_deltas = self::consume_file_analytics_hit_buffer();
            if (empty($apcu_deltas) && empty($file_deltas)) {
                if ($apcu_lock_acquired) {
                    @apcu_delete($prefix . 'flush_lock');
                }
                return false;
            }

            $analytics = self::read_analytics(false);
            foreach (array($apcu_deltas, $file_deltas) as $deltas) {
                foreach ($deltas as $counter => $amount) {
                    self::apply_analytics_hit_delta($analytics, $counter, $amount);
                }
            }

            self::write_analytics($analytics);
            self::decrement_apcu_analytics_hit_buffer($apcu_deltas);
            if ($apcu_lock_acquired) {
                @apcu_delete($prefix . 'total');
                @apcu_delete($prefix . 'flush_lock');
            }

            return true;
        }

        private static function get_default_analytics()
        {
            return array(
                'version'        => 1,
                'pageHits'       => 0,
                'pageMisses'     => 0,
                'pageBypasses'   => 0,
                'pageStores'     => 0,
                'pageStoreSkips' => 0,
                'pageStaleHits'  => 0,
                'pageBackgroundRevalidations' => 0,
                'bucketHits'     => array(
                    'orig' => 0,
                    'webp' => 0,
                    'avif' => 0,
                ),
                'encodingHits'   => array(
                    'identity' => 0,
                    'gzip'     => 0,
                    'brotli'   => 0,
                ),
                'bypassReasons'  => array(),
                'lastPurge'      => array(),
                'lastWarm'       => array(),
                'warmSuccess'    => 0,
                'warmFailed'     => 0,
                'clsImagesScanned' => 0,
                'clsDimensionsInjected' => 0,
                'clsImagesSkipped' => 0,
                'clsImagesUnresolved' => 0,
                'cssAsyncStylesScanned' => 0,
                'cssAsyncStylesRewritten' => 0,
                'cssAsyncStylesSkipped' => 0,
                'cssAsyncStylesUnresolved' => 0,
                'frontpageCssStylesScanned' => 0,
                'frontpageCssStylesBundled' => 0,
                'frontpageCssStylesSkipped' => 0,
                'frontpageCssStylesUnresolved' => 0,
                'frontpageCssBundlesBuilt' => 0,
                'lastFrontpageCssWarm' => array(),
                'sr7LcpDetected' => 0,
                'sr7LcpPreloadsInjected' => 0,
                'sr7LcpSkipped' => 0,
                'sr7LcpUnresolved' => 0,
            );
        }

        private static function normalize_analytics(array $data = array())
        {
            $defaults = self::get_default_analytics();
            $data = array_replace_recursive($defaults, $data);

            foreach (array('pageHits', 'pageMisses', 'pageBypasses', 'pageStores', 'pageStoreSkips', 'pageStaleHits', 'pageBackgroundRevalidations', 'warmSuccess', 'warmFailed', 'clsImagesScanned', 'clsDimensionsInjected', 'clsImagesSkipped', 'clsImagesUnresolved', 'cssAsyncStylesScanned', 'cssAsyncStylesRewritten', 'cssAsyncStylesSkipped', 'cssAsyncStylesUnresolved', 'frontpageCssStylesScanned', 'frontpageCssStylesBundled', 'frontpageCssStylesSkipped', 'frontpageCssStylesUnresolved', 'frontpageCssBundlesBuilt', 'sr7LcpDetected', 'sr7LcpPreloadsInjected', 'sr7LcpSkipped', 'sr7LcpUnresolved') as $key) {
                $data[$key] = max(0, (int) ($data[$key] ?? 0));
            }

            foreach (array('orig', 'webp', 'avif') as $bucket) {
                $data['bucketHits'][$bucket] = max(0, (int) ($data['bucketHits'][$bucket] ?? 0));
            }

            foreach (array('identity', 'gzip', 'brotli') as $encoding) {
                $data['encodingHits'][$encoding] = max(0, (int) ($data['encodingHits'][$encoding] ?? 0));
            }

            if (!is_array($data['bypassReasons'])) {
                $data['bypassReasons'] = array();
            }

            if (!is_array($data['lastPurge'])) {
                $data['lastPurge'] = array();
            }

            if (!is_array($data['lastWarm'])) {
                $data['lastWarm'] = array();
            }

            if (!is_array($data['lastFrontpageCssWarm'])) {
                $data['lastFrontpageCssWarm'] = array();
            }

            return $data;
        }

        private static function read_analytics($flush_hit_buffer = true)
        {
            if ($flush_hit_buffer) {
                self::flush_analytics_hit_buffer();
            }

            $file = self::get_analytics_file();
            if (!file_exists($file) || !is_readable($file)) {
                return self::get_default_analytics();
            }

            $raw = ucwp_safe_file_get_contents($file);
            if (false === $raw || '' === $raw) {
                return self::get_default_analytics();
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return self::get_default_analytics();
            }

            return self::normalize_analytics($decoded);
        }

        private static function write_analytics(array $data)
        {
            self::ensure_cache_directories();
            $file = self::get_analytics_file();
            ucwp_safe_file_put_contents($file, wp_json_encode(self::normalize_analytics($data)), LOCK_EX);
        }

        private static function mutate_analytics($callback)
        {
            $current = self::read_analytics();
            $updated = is_callable($callback) ? call_user_func($callback, $current) : $current;
            if (!is_array($updated)) {
                $updated = $current;
            }

            self::write_analytics($updated);
        }

        public static function reset_analytics()
        {
            self::clear_analytics_hit_buffer();
            self::write_analytics(self::get_default_analytics());
            return true;
        }

        public static function get_analytics_stats()
        {
            $analytics = self::read_analytics();
            $hits = (int) ($analytics['pageHits'] ?? 0);
            $misses = (int) ($analytics['pageMisses'] ?? 0);
            $bypasses = (int) ($analytics['pageBypasses'] ?? 0);
            $stores = (int) ($analytics['pageStores'] ?? 0);
            $store_skips = (int) ($analytics['pageStoreSkips'] ?? 0);
            $hit_ratio = ($hits + $misses) > 0 ? round(($hits / ($hits + $misses)) * 100, 1) : 0.0;

            $reasons = is_array($analytics['bypassReasons']) ? $analytics['bypassReasons'] : array();
            arsort($reasons);

            return array(
                'pageCacheHits'         => $hits,
                'pageCacheMisses'       => $misses,
                'pageCacheBypasses'     => $bypasses,
                'pageCacheStores'       => $stores,
                'pageCacheStoreSkips'   => $store_skips,
                'pageCacheHitRatio'     => $hit_ratio,
                'pageCacheStaleHits'    => (int) ($analytics['pageStaleHits'] ?? 0),
                'pageCacheBackgroundRevalidations' => (int) ($analytics['pageBackgroundRevalidations'] ?? 0),
                'pageCacheBucketHits'   => is_array($analytics['bucketHits']) ? $analytics['bucketHits'] : array(),
                'pageCacheEncodingHits' => is_array($analytics['encodingHits']) ? $analytics['encodingHits'] : array(),
                'topBypassReasons'      => array_slice($reasons, 0, 5, true),
                'lastPurge'             => is_array($analytics['lastPurge']) ? $analytics['lastPurge'] : array(),
                'lastWarm'              => is_array($analytics['lastWarm']) ? $analytics['lastWarm'] : array(),
                'warmSuccessCount'      => (int) ($analytics['warmSuccess'] ?? 0),
                'warmFailureCount'      => (int) ($analytics['warmFailed'] ?? 0),
                'clsImagesScanned'      => (int) ($analytics['clsImagesScanned'] ?? 0),
                'clsDimensionsInjected' => (int) ($analytics['clsDimensionsInjected'] ?? 0),
                'clsImagesSkipped'      => (int) ($analytics['clsImagesSkipped'] ?? 0),
                'clsImagesUnresolved'   => (int) ($analytics['clsImagesUnresolved'] ?? 0),
                'cssAsyncStylesScanned' => (int) ($analytics['cssAsyncStylesScanned'] ?? 0),
                'cssAsyncStylesRewritten' => (int) ($analytics['cssAsyncStylesRewritten'] ?? 0),
                'cssAsyncStylesSkipped' => (int) ($analytics['cssAsyncStylesSkipped'] ?? 0),
                'cssAsyncStylesUnresolved' => (int) ($analytics['cssAsyncStylesUnresolved'] ?? 0),
                'frontpageCssStylesScanned' => (int) ($analytics['frontpageCssStylesScanned'] ?? 0),
                'frontpageCssStylesBundled' => (int) ($analytics['frontpageCssStylesBundled'] ?? 0),
                'frontpageCssStylesSkipped' => (int) ($analytics['frontpageCssStylesSkipped'] ?? 0),
                'frontpageCssStylesUnresolved' => (int) ($analytics['frontpageCssStylesUnresolved'] ?? 0),
                'frontpageCssBundlesBuilt' => (int) ($analytics['frontpageCssBundlesBuilt'] ?? 0),
                'lastFrontpageCssWarm' => is_array($analytics['lastFrontpageCssWarm']) ? $analytics['lastFrontpageCssWarm'] : array(),
                'sr7LcpDetected' => (int) ($analytics['sr7LcpDetected'] ?? 0),
                'sr7LcpPreloadsInjected' => (int) ($analytics['sr7LcpPreloadsInjected'] ?? 0),
                'sr7LcpSkipped' => (int) ($analytics['sr7LcpSkipped'] ?? 0),
                'sr7LcpUnresolved' => (int) ($analytics['sr7LcpUnresolved'] ?? 0),
            );
        }

        private function record_analytics_miss()
        {
            self::mutate_analytics(function ($analytics) {
                $analytics['pageMisses'] = (int) ($analytics['pageMisses'] ?? 0) + 1;
                return $analytics;
            });
        }

        private function record_analytics_bypass($reason)
        {
            $reason = (string) $reason;
            self::mutate_analytics(function ($analytics) use ($reason) {
                $analytics['pageBypasses'] = (int) ($analytics['pageBypasses'] ?? 0) + 1;
                if (!isset($analytics['bypassReasons']) || !is_array($analytics['bypassReasons'])) {
                    $analytics['bypassReasons'] = array();
                }
                if (!isset($analytics['bypassReasons'][$reason])) {
                    $analytics['bypassReasons'][$reason] = 0;
                }
                $analytics['bypassReasons'][$reason] = (int) $analytics['bypassReasons'][$reason] + 1;
                return $analytics;
            });
        }

        private function record_analytics_store()
        {
            self::mutate_analytics(function ($analytics) {
                $analytics['pageStores'] = (int) ($analytics['pageStores'] ?? 0) + 1;
                return $analytics;
            });
        }

        private function record_analytics_store_skip($reason)
        {
            self::mutate_analytics(function ($analytics) use ($reason) {
                $analytics['pageStoreSkips'] = (int) ($analytics['pageStoreSkips'] ?? 0) + 1;
                if ($reason) {
                    if (!isset($analytics['bypassReasons']) || !is_array($analytics['bypassReasons'])) {
                        $analytics['bypassReasons'] = array();
                    }
                    $key = 'skip:' . (string) $reason;
                    if (!isset($analytics['bypassReasons'][$key])) {
                        $analytics['bypassReasons'][$key] = 0;
                    }
                    $analytics['bypassReasons'][$key] = (int) $analytics['bypassReasons'][$key] + 1;
                }
                return $analytics;
            });
        }

        private function record_analytics_purge($scope, $url = '')
        {
            self::mutate_analytics(function ($analytics) use ($scope, $url) {
                $analytics['lastPurge'] = array(
                    'scope'      => (string) $scope,
                    'url'        => (string) $url,
                    'time'       => current_time('timestamp'),
                    'time_mysql' => current_time('mysql'),
                );
                return $analytics;
            });
        }

        private function record_analytics_warm($url, array $result = array())
        {
            self::mutate_analytics(function ($analytics) use ($url, $result) {
                $success = !empty($result['success']);
                if ($success) {
                    $analytics['warmSuccess'] = (int) ($analytics['warmSuccess'] ?? 0) + 1;
                } else {
                    $analytics['warmFailed'] = (int) ($analytics['warmFailed'] ?? 0) + 1;
                }
                $analytics['lastWarm'] = array(
                    'url'        => (string) $url,
                    'success'    => $success,
                    'message'    => isset($result['message']) ? (string) $result['message'] : '',
                    'cached'     => isset($result['cached']) ? (bool) $result['cached'] : false,
                    'files'      => !empty($result['files']) && is_array($result['files']) ? count($result['files']) : 0,
                    'time'       => current_time('timestamp'),
                    'time_mysql' => current_time('mysql'),
                );
                return $analytics;
            });
        }


        private function record_analytics_cls_dimensions(array $stats = array())
        {
            self::mutate_analytics(function ($analytics) use ($stats) {
                $analytics['clsImagesScanned'] = (int) ($analytics['clsImagesScanned'] ?? 0) + max(0, (int) ($stats['scanned'] ?? 0));
                $analytics['clsDimensionsInjected'] = (int) ($analytics['clsDimensionsInjected'] ?? 0) + max(0, (int) ($stats['injected'] ?? 0));
                $analytics['clsImagesSkipped'] = (int) ($analytics['clsImagesSkipped'] ?? 0) + max(0, (int) ($stats['skipped'] ?? 0));
                $analytics['clsImagesUnresolved'] = (int) ($analytics['clsImagesUnresolved'] ?? 0) + max(0, (int) ($stats['unresolved'] ?? 0));
                return $analytics;
            });
        }

        private function record_analytics_safe_async_css(array $stats = array())
        {
            self::mutate_analytics(function ($analytics) use ($stats) {
                $analytics['cssAsyncStylesScanned'] = (int) ($analytics['cssAsyncStylesScanned'] ?? 0) + max(0, (int) ($stats['scanned'] ?? 0));
                $analytics['cssAsyncStylesRewritten'] = (int) ($analytics['cssAsyncStylesRewritten'] ?? 0) + max(0, (int) ($stats['rewritten'] ?? 0));
                $analytics['cssAsyncStylesSkipped'] = (int) ($analytics['cssAsyncStylesSkipped'] ?? 0) + max(0, (int) ($stats['skipped'] ?? 0));
                $analytics['cssAsyncStylesUnresolved'] = (int) ($analytics['cssAsyncStylesUnresolved'] ?? 0) + max(0, (int) ($stats['unresolved'] ?? 0));
                return $analytics;
            });
        }

        private function record_analytics_frontpage_css_warm(array $result = array())
        {
            self::mutate_analytics(function ($analytics) use ($result) {
                $stats = isset($result['stats']) && is_array($result['stats']) ? $result['stats'] : array();
                $analytics['frontpageCssStylesScanned'] = (int) ($analytics['frontpageCssStylesScanned'] ?? 0) + max(0, (int) ($stats['scanned'] ?? 0));
                $analytics['frontpageCssStylesBundled'] = (int) ($analytics['frontpageCssStylesBundled'] ?? 0) + max(0, (int) ($stats['bundled'] ?? 0));
                $analytics['frontpageCssStylesSkipped'] = (int) ($analytics['frontpageCssStylesSkipped'] ?? 0) + max(0, (int) ($stats['skipped'] ?? 0));
                $analytics['frontpageCssStylesUnresolved'] = (int) ($analytics['frontpageCssStylesUnresolved'] ?? 0) + max(0, (int) ($stats['unresolved'] ?? 0));
                $analytics['frontpageCssBundlesBuilt'] = (int) ($analytics['frontpageCssBundlesBuilt'] ?? 0) + max(0, (int) ($result['bundleCount'] ?? 0));
                $analytics['lastFrontpageCssWarm'] = array(
                    'success' => !empty($result['success']),
                    'message' => isset($result['message']) ? (string) $result['message'] : '',
                    'bundleCount' => max(0, (int) ($result['bundleCount'] ?? 0)),
                    'stylesBundled' => max(0, (int) ($stats['bundled'] ?? 0)),
                    'stylesScanned' => max(0, (int) ($stats['scanned'] ?? 0)),
                    'time' => current_time('timestamp'),
                    'time_mysql' => current_time('mysql'),
                );
                return $analytics;
            });
        }

        private function record_analytics_sr7_lcp(array $stats = array())
        {
            self::mutate_analytics(function ($analytics) use ($stats) {
                $analytics['sr7LcpDetected'] = (int) ($analytics['sr7LcpDetected'] ?? 0) + max(0, (int) ($stats['detected'] ?? 0));
                $analytics['sr7LcpPreloadsInjected'] = (int) ($analytics['sr7LcpPreloadsInjected'] ?? 0) + max(0, (int) ($stats['preloadsInjected'] ?? 0));
                $analytics['sr7LcpSkipped'] = (int) ($analytics['sr7LcpSkipped'] ?? 0) + max(0, (int) ($stats['skipped'] ?? 0));
                $analytics['sr7LcpUnresolved'] = (int) ($analytics['sr7LcpUnresolved'] ?? 0) + max(0, (int) ($stats['unresolved'] ?? 0));
                return $analytics;
            });
        }

        public static function setup_advanced_cache()
        {
            if (!defined('WP_CONTENT_DIR')) {
                return;
            }

            $target = trailingslashit(WP_CONTENT_DIR) . 'advanced-cache.php';
            $marker = 'UltraCache advanced-cache drop-in';

            $dropin = self::get_advanced_cache_dropin_contents();
            if ('' === $dropin) {
                return;
            }

            if (file_exists($target) && is_readable($target)) {
                $existing = (string) ucwp_safe_file_get_contents($target);
                if ('' !== $existing && $existing === $dropin) {
                    return;
                }

                if ('' !== $existing && false === strpos($existing, $marker)) {
                    return;
                }
            }

            $tmp = $target . '.tmp-' . uniqid('', true);
            if (false === ucwp_safe_file_put_contents($tmp, $dropin, LOCK_EX)) {
                ucwp_safe_unlink($tmp);
                return;
            }

            if (!ucwp_safe_rename($tmp, $target)) {
                ucwp_safe_unlink($tmp);
            }
        }

        public static function get_advanced_cache_dropin_contents()
        {
            if (!defined('WP_CONTENT_DIR')) {
                return '';
            }

            $template = trailingslashit(UCWP_PATH) . 'templates/advanced-cache.php.tpl';
            if (!file_exists($template) || !is_readable($template)) {
                return '';
            }

            $dropin = (string) ucwp_safe_file_get_contents($template, 'advanced_cache_template');
            if ('' === $dropin) {
                return '';
            }

            return str_replace('__UCWP_DROPIN_BUILD__', UCWP_VERSION, $dropin);
        }

        public static function maybe_remove_advanced_cache()
        {
            if (!defined('WP_CONTENT_DIR')) {
                return;
            }

            $target = trailingslashit(WP_CONTENT_DIR) . 'advanced-cache.php';
            if (!file_exists($target)) {
                return;
            }

            $contents = (string) ucwp_safe_file_get_contents($target);
            if (false !== strpos($contents, 'UltraCache advanced-cache drop-in')) {
                ucwp_safe_unlink($target);
            }
        }

        public function maybe_start_buffering()
        {
            if ($this->buffering) {
                return;
            }

            if ($this->should_bypass_cache()) {
                $this->record_analytics_bypass($this->last_bypass_reason);
                $this->send_debug_headers('BYPASS', $this->last_bypass_reason);
                return;
            }

            $this->record_analytics_miss();
            $this->send_debug_headers('MISS');
            $this->buffering = true;
            ob_start(array($this, 'cache_output_callback'));
        }

        public function cache_output_callback($html)
        {
            $this->buffering = false;

            if (!is_string($html) || '' === $html) {
                return $html;
            }

            $html = $this->apply_frontend_performance_optimizations($html);
            $skip_reason = $this->get_skip_store_reason($html);
            if ('' !== $skip_reason) {
                $this->record_analytics_store_skip($skip_reason);
                if ($this->is_internal_revalidate_request()) {
                    $this->clear_revalidate_lock($this->get_current_request_url());
                }
                $this->send_debug_headers('SKIP', $skip_reason);
                return $html;
            }

            $url = $this->get_current_request_url();
            $file_path = $this->get_cache_path($url);
            if (empty($file_path)) {
                $this->send_debug_headers('SKIP', 'empty-cache-path');
                return $html;
            }

            $write_ok = $this->write_cache_file($file_path, $html);
            if (!$write_ok || !file_exists($file_path)) {
                $this->record_analytics_store_skip('write-failed');
                if ($this->is_internal_revalidate_request()) {
                    $this->clear_revalidate_lock($url);
                }
                $this->send_debug_headers('SKIP', 'write-failed');
                return $html;
            }

            $this->record_analytics_store();
            if ($this->is_internal_revalidate_request()) {
                $this->clear_revalidate_lock($url);
                if (!headers_sent()) {
                    header('X-Ultra-Cache-Revalidate: refreshed');
                }
            }
            $this->record_cache_event('store', array('url' => $url, 'file' => $file_path));
            $this->send_debug_headers('STORE');

            return $html;
        }

        private function write_cache_file($file_path, $html)
        {
            $dir = dirname($file_path);
            if (!file_exists($dir) && !ucwp_safe_mkdir($dir, 0755, true) && !file_exists($dir)) {
                return false;
            }

            if (!$this->write_cache_variant_atomically($file_path, $html)) {
                return false;
            }

            $settings = $this->get_settings();
            if (!empty($settings['gzip_enabled']) && function_exists('gzencode')) {
                self::gz_file_put_contents($file_path . '.gz', $html);
            } else {
                ucwp_safe_unlink($file_path . '.gz');
            }

            if (!empty($settings['brotli_enabled']) && function_exists('brotli_compress')) {
                $compressed = brotli_compress($html, 11, BROTLI_TEXT);
                if (false !== $compressed) {
                    $this->write_cache_variant_atomically($file_path . '.br', $compressed);
                }
            } else {
                ucwp_safe_unlink($file_path . '.br');
            }

            return true;
        }

        private function write_cache_variant_atomically($path, $contents)
        {
            $dir = dirname($path);
            if (!file_exists($dir) && !ucwp_safe_mkdir($dir, 0755, true) && !file_exists($dir)) {
                return false;
            }

            $tmp = $path . '.tmp-' . uniqid('', true);
            $result = ucwp_safe_file_put_contents($tmp, $contents, LOCK_EX);
            if (false === $result) {
                ucwp_safe_unlink($tmp);
                return false;
            }

            if (!ucwp_safe_rename($tmp, $path)) {
                ucwp_safe_unlink($tmp);
                return false;
            }

            clearstatcache(true, $tmp);
            clearstatcache(true, $path);
            if (!file_exists($path) || file_exists($tmp)) {
                ucwp_safe_unlink($tmp);
                return false;
            }

            return true;
        }

        private function get_skip_store_reason($html)
        {
            if (is_admin()) {
                return 'admin';
            }

            if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
                return 'donotcachepage';
            }

            $settings = $this->get_settings();
            if ($this->is_woocommerce_dynamic_request($this->get_current_request_url(), $settings)) {
                return 'woocommerce-dynamic';
            }

            if (function_exists('is_user_logged_in') && is_user_logged_in() && empty($settings['cache_logged_in_users'])) {
                return 'logged-in-user';
            }

            $status_code = function_exists('http_response_code') ? (int) http_response_code() : 200;
            if ($status_code && 200 !== $status_code) {
                return 'status-' . $status_code;
            }

            if (strlen($html) < 255) {
                return 'response-too-short';
            }

            if (false === stripos($html, '<html')) {
                return 'missing-html-tag';
            }

            if ($this->has_fragile_revslider_shell($html)) {
                return 'fragile-revslider-shell';
            }

            foreach (headers_list() as $header) {
                if (0 === stripos($header, 'Set-Cookie:')) {
                    return 'set-cookie';
                }

                if (0 === stripos($header, 'Cache-Control:') && false !== stripos($header, 'no-cache')) {
                    return 'cache-control-no-cache';
                }
            }

            return '';
        }

        private function send_debug_headers($status, $reason = '')
        {
            if (headers_sent()) {
                return;
            }

            $status = strtoupper((string) preg_replace('/[^A-Z-]/', '', (string) $status));
            if ('' !== $status) {
                header('X-Ultra-Cache: ' . $status);
            }

            header('Vary: Accept, Accept-Encoding', false);

            if ('' !== $reason) {
                $reason = substr((string) preg_replace('/[^A-Za-z0-9_. -]/', '-', (string) $reason), 0, 120);
                header('X-Ultra-Cache-Reason: ' . $reason);
            }
        }

        private static function gz_file_put_contents($path, $html)
        {
            $gz = gzencode($html, 9);
            if (false === $gz) {
                return;
            }

            $dir = dirname($path);
            if (!file_exists($dir) && !ucwp_safe_mkdir($dir, 0755, true) && !file_exists($dir)) {
                return;
            }

            $tmp = $path . '.tmp-' . uniqid('', true);
            if (false === ucwp_safe_file_put_contents($tmp, $gz, LOCK_EX)) {
                ucwp_safe_unlink($tmp);
                return;
            }

            if (!ucwp_safe_rename($tmp, $path)) {
                ucwp_safe_unlink($tmp);
            }
        }

        private function get_settings()
        {
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
                return Ultra_Cache_WP::get_settings();
            }

            $saved = get_option(UCWP_SETTINGS_KEY, array());
            return is_array($saved) ? $saved : array();
        }

        public function defer_scripts($tag, $handle, $src)
        {
            $settings = $this->get_settings();
            if (is_admin()) {
                return $tag;
            }

            $defer_stage = $this->get_defer_stage_level($settings);
            if ($this->is_script_user_force_deferred($handle, $src, $tag, $settings)) {
                return $this->add_defer_attribute_to_script_tag($tag, true);
            }

            if (0 < $defer_stage && $this->is_script_force_blocking($handle, $src, $tag, $settings)) {
                return $this->strip_native_loading_attributes_from_script_tag($tag);
            }

            if (0 < $defer_stage && $this->is_script_user_defer_excluded($handle, $src, $settings)) {
                return $tag;
            }

            if (1 === $defer_stage && $this->is_script_safe_stage_excluded($handle, $src, $tag, $settings)) {
                return $tag;
            }

            if (2 <= $defer_stage && !empty($settings['delay_third_party_js']) && $this->should_delay_script($handle, $src, $tag, $settings)) {
                return $this->build_delayed_script_tag($tag, $handle, $src);
            }

            if (2 <= $defer_stage && !empty($settings['delay_non_critical_js']) && $this->should_delay_non_critical_script($handle, $src, $tag, $settings)) {
                return $this->build_delayed_script_tag($tag, $handle, $src);
            }

            if (!empty($settings['async_external_scripts']) && $this->should_async_external_script($handle, $src, $tag, $settings)) {
                return $this->add_async_attribute_to_script_tag($tag);
            }

            if (0 === $defer_stage || empty($settings['defer_js'])) {
                return $tag;
            }

            return $this->add_defer_attribute_to_script_tag($tag, false);
        }

        private function get_defer_stage_level(array $settings = array())
        {
            if (!empty($settings['defer_stage_aggressive']) || !empty($settings['delay_non_critical_js_aggressive'])) {
                return 3;
            }

            if (!empty($settings['defer_stage_balanced'])) {
                return 2;
            }

            if (!empty($settings['defer_stage_safe']) || !empty($settings['defer_js'])) {
                return 1;
            }

            return 0;
        }

        private function strip_native_loading_attributes_from_script_tag($tag)
        {
            $tag = (string) $tag;
            if ('' === $tag) {
                return $tag;
            }

            if (false === stripos($tag, ' async') && false === stripos($tag, ' defer') && false === stripos($tag, 'data-wp-strategy=')) {
                return $tag;
            }

            $tag = preg_replace('/\s(async|defer)(?=(\s|>))/i', '', $tag);
            $tag = preg_replace('/\sdata-wp-strategy=("|\")(?:async|defer)\1/i', '', $tag);
            $tag = preg_replace("/\sdata-wp-strategy=(')(?:async|defer)\1/i", '', $tag);
            $tag = preg_replace('/\sdata-wp-strategy=(?:async|defer)(?=(\s|>))/i', '', $tag);
            $tag = preg_replace('/\s{2,}/', ' ', $tag);

            return $tag;
        }

        private function normalize_protected_script_loading_attributes_in_html($html, array $settings = array())
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<script')) {
                return $html;
            }

            $that = $this;
            $rewritten = preg_replace_callback('/<script\b[^>]*>/i', static function ($matches) use ($that, $settings) {
                $tag = isset($matches[0]) ? (string) $matches[0] : '';
                if ('' === $tag) {
                    return $tag;
                }

                if (false === stripos($tag, ' async') && false === stripos($tag, ' defer') && false === stripos($tag, 'data-wp-strategy=')) {
                    return $tag;
                }

                $handle = '';
                if (preg_match('/\sid=("|\")(.*?)\1/i', $tag, $id_matches) && isset($id_matches[2])) {
                    $handle = html_entity_decode((string) $id_matches[2], ENT_QUOTES, 'UTF-8');
                } elseif (preg_match("/\sid=(')(.*?)\1/i", $tag, $id_matches) && isset($id_matches[2])) {
                    $handle = html_entity_decode((string) $id_matches[2], ENT_QUOTES, 'UTF-8');
                }

                $src = '';
                if (preg_match('/\ssrc=("|\")(.*?)\1/i', $tag, $src_matches) && isset($src_matches[2])) {
                    $src = html_entity_decode((string) $src_matches[2], ENT_QUOTES, 'UTF-8');
                } elseif (preg_match("/\ssrc=(')(.*?)\1/i", $tag, $src_matches) && isset($src_matches[2])) {
                    $src = html_entity_decode((string) $src_matches[2], ENT_QUOTES, 'UTF-8');
                }

                if ($that->is_script_user_force_deferred($handle, $src, $tag, $settings)) {
                    return $that->add_defer_attribute_to_script_tag($tag, true);
                }

                if (!$that->is_script_force_blocking($handle, $src, $tag, $settings)) {
                    return $tag;
                }

                return $that->strip_native_loading_attributes_from_script_tag($tag);
            }, $html);

            return is_string($rewritten) ? $rewritten : $html;
        }

        private function script_handle_has_inline_segments($handle)
        {
            $handle = (string) $handle;
            if ('' === $handle) {
                return false;
            }

            global $wp_scripts;
            if (!($wp_scripts instanceof WP_Scripts)) {
                return false;
            }

            foreach (array('before', 'after', 'data') as $key) {
                $segment = $wp_scripts->get_data($handle, $key);
                if (is_array($segment) && !empty($segment)) {
                    return true;
                }
                if (is_string($segment) && '' !== trim($segment)) {
                    return true;
                }
            }

            return false;
        }

        private function script_handle_has_inline_after_segments($handle)
        {
            $handle = (string) $handle;
            if ('' === $handle) {
                return false;
            }

            global $wp_scripts;
            if (!($wp_scripts instanceof WP_Scripts)) {
                return false;
            }

            $segment = $wp_scripts->get_data($handle, 'after');
            if (is_array($segment) && !empty($segment)) {
                return true;
            }

            return is_string($segment) && '' !== trim($segment);
        }

        private function script_handle_has_enqueued_dependents($handle)
        {
            $handle = (string) $handle;
            if ('' === $handle) {
                return false;
            }

            global $wp_scripts;
            if (!($wp_scripts instanceof WP_Scripts)) {
                return false;
            }

            $candidates = array();
            foreach (array('queue', 'to_do', 'done') as $property) {
                if (isset($wp_scripts->{$property}) && is_array($wp_scripts->{$property})) {
                    $candidates = array_merge($candidates, $wp_scripts->{$property});
                }
            }

            foreach (array_unique(array_filter(array_map('strval', $candidates))) as $candidate) {
                if ($candidate === $handle || empty($wp_scripts->registered[$candidate]) || empty($wp_scripts->registered[$candidate]->deps)) {
                    continue;
                }

                if (in_array($handle, array_map('strval', (array) $wp_scripts->registered[$candidate]->deps), true)) {
                    return true;
                }
            }

            return false;
        }

        private function add_defer_attribute_to_script_tag($tag, $force = false)
        {
            $tag = (string) $tag;
            if ('' === $tag || false === stripos($tag, '<script') || false === stripos($tag, ' src=')) {
                return $tag;
            }

            if (!$force && (false !== stripos($tag, ' defer') || false !== stripos($tag, ' async') || false !== stripos($tag, ' type="module"'))) {
                return $tag;
            }

            if ($force) {
                $tag = preg_replace('/\sasync(?=(\s|>))/i', '', $tag);
                $tag = preg_replace('/\sdata-wp-strategy=("|\")(?:async|defer)\1/i', '', $tag);
                $tag = preg_replace("/\sdata-wp-strategy=(')(?:async|defer)\1/i", '', $tag);
                $tag = preg_replace('/\sdata-wp-strategy=(?:async|defer)(?=(\s|>))/i', '', $tag);
                $tag = preg_replace('/\s{2,}/', ' ', $tag);
            }

            if (false !== stripos($tag, ' defer')) {
                return $tag;
            }

            $deferred = preg_replace('/\ssrc=/i', ' defer src=', $tag, 1);
            return is_string($deferred) ? $deferred : $tag;
        }

        private function add_async_attribute_to_script_tag($tag)
        {
            $tag = (string) $tag;
            if ('' === $tag) {
                return $tag;
            }

            if (false !== stripos($tag, ' async') || false !== stripos($tag, ' type="module"') || false !== stripos($tag, ' nomodule')) {
                return $tag;
            }

            if (false !== stripos($tag, ' defer')) {
                return preg_replace('/\sdefer(?=(\s|>))/i', ' async', $tag, 1);
            }

            return str_replace(' src=', ' async src=', $tag);
        }

        private function should_async_external_script($handle, $src, $tag, array $settings = array())
        {
            $src = trim((string) $src);
            if ('' === $src || false === stripos((string) $tag, '<script')) {
                return false;
            }

            if (false !== stripos((string) $tag, ' async') || false !== stripos((string) $tag, ' type="module"') || false !== stripos((string) $tag, ' nomodule')) {
                return false;
            }

            $src_host = (string) wp_parse_url($src, PHP_URL_HOST);
            $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            if ('' === $src_host || '' === $home_host || strtolower($src_host) === strtolower($home_host)) {
                return false;
            }

            $haystack = strtolower((string) $handle . ' ' . $src . ' ' . $tag);
            $patterns = array(
                'googletagmanager.com',
                'google-analytics.com',
                'googleanalytics.com',
                'gtag/js',
                'googleadservices.com',
                'g.doubleclick.net',
                'connect.facebook.net',
                'facebook.com/tr',
                'bat.bing.com',
                'clarity.ms',
                'usefathom.com',
                'plausible.io',
                'analytics.tiktok.com',
                'static.hotjar.com',
                'script.hotjar.com',
                'snap.licdn.com',
                'px.ads.linkedin.com',
                'pinimg.com/ct/',
                'redditstatic.com/ads/',
                'mc.yandex.ru',
            );

            foreach ($patterns as $pattern) {
                if (false !== strpos($haystack, strtolower($pattern))) {
                    return true;
                }
            }

            return false;
        }

        private function is_script_optimization_excluded($handle, $src, $tag = '', array $settings = array())
        {
            return $this->is_script_force_blocking($handle, $src, $tag, $settings)
                || $this->is_script_user_defer_excluded($handle, $src, $settings)
                || $this->is_script_safe_stage_excluded($handle, $src, $tag, $settings);
        }

        private function is_script_force_blocking($handle, $src, $tag = '', array $settings = array())
        {
            $handle = (string) $handle;
            $src    = (string) $src;
            $tag    = (string) $tag;

            $handle_lc = strtolower($handle);
            $src_lc    = strtolower($src);
            $tag_lc    = strtolower($tag);
            $haystack  = $handle_lc . ' ' . $src_lc . ' ' . $tag_lc;

            if (in_array($handle_lc, array_map('strtolower', $this->get_force_blocking_script_handles($settings)), true)) {
                return true;
            }

            if ($this->script_handle_has_inline_segments($handle)) {
                return true;
            }

            if (0 === strpos($handle_lc, 'wp-') || 0 === strpos($handle_lc, 'wc-')) {
                return true;
            }

            if (false !== strpos($src_lc, '/wp-includes/js/')) {
                return true;
            }

            $patterns = array(
                'jquery',
                'wp-hooks',
                'wp-i18n',
                'wp-util',
                'wp-api',
                'wp-polyfill',
                'underscore',
                'backbone',
                'jquery/ui',
                'heartbeat',
                '/plugins/woocommerce/assets/js/frontend/cart-fragments',
                '/plugins/woocommerce/assets/js/frontend/add-to-cart',
                '/plugins/woocommerce/assets/js/frontend/checkout',
                '/plugins/woocommerce/assets/js/frontend/single-product',
                '/plugins/woocommerce/assets/js/selectwoo',
                'wc-cart',
                'wc-checkout',
                'wc-add-to-cart',
                'wc-single-product',
                'wc-country-select',
                'wc-address-i18n',
                'wc-credit-card-form',
                'selectwoo',
            );

            foreach ($patterns as $pattern) {
                if (false !== strpos($haystack, $pattern)) {
                    return true;
                }
            }

            if (!empty($settings['woo_safe_mode'])) {
                $woo_patterns = array(
                    'woocommerce',
                    '/plugins/woocommerce/assets/js/',
                    'js-cookie',
                    'sourcebuster-js',
                    'wc-order-attribution',
                    'order-attribution',
                );

                foreach ($woo_patterns as $pattern) {
                    if (false !== strpos($haystack, $pattern)) {
                        return true;
                    }
                }
            }

            return false;
        }

        private function is_script_user_force_deferred($handle, $src, $tag = '', array $settings = array())
        {
            return $this->script_matches_force_defer_fragment_list($handle, $src, $tag, $this->get_force_defer_js_fragments($settings));
        }

        private function get_force_defer_js_fragments(array $settings = array())
        {
            $list = array();
            if (isset($settings['defer_js_force_list']) && is_array($settings['defer_js_force_list'])) {
                $list = array_merge($list, $settings['defer_js_force_list']);
            }

            return array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function script_matches_force_defer_fragment_list($handle, $src, $tag, array $fragments)
        {
            $haystacks = array(
                strtolower(trim((string) $handle)),
                strtolower(trim((string) $src)),
                strtolower((string) wp_parse_url((string) $src, PHP_URL_PATH)),
                strtolower((string) $tag),
            );

            foreach ($fragments as $fragment) {
                $fragment = strtolower(trim((string) $fragment));
                if ('' === $fragment) {
                    continue;
                }
                foreach ($haystacks as $haystack) {
                    if ('' !== $haystack && false !== strpos($haystack, $fragment)) {
                        return true;
                    }
                }
            }

            return false;
        }

        private function is_script_user_defer_excluded($handle, $src, array $settings = array())
        {
            return $this->script_matches_fragment_list($handle, $src, $this->get_defer_stage_user_exclude_fragments($settings));
        }

        private function is_script_safe_stage_excluded($handle, $src, $tag = '', array $settings = array())
        {
            $handle_lc = strtolower((string) $handle);
            if (in_array($handle_lc, array_map('strtolower', $this->get_safe_stage_excluded_handles($settings)), true)) {
                return true;
            }

            return $this->script_matches_fragment_list($handle, $src, $this->get_safe_stage_defer_exclude_fragments($settings));
        }

        private function get_defer_stage_user_exclude_fragments(array $settings = array())
        {
            $list = array();

            if (isset($settings['defer_js_exclude_list']) && is_array($settings['defer_js_exclude_list'])) {
                $list = array_merge($list, $settings['defer_js_exclude_list']);
            }

            // Backward compatibility for sites that already saved the old separate Delay Non-Critical JS exclude list.
            if (isset($settings['delay_non_critical_js_exclude_list']) && is_array($settings['delay_non_critical_js_exclude_list'])) {
                $list = array_merge($list, $settings['delay_non_critical_js_exclude_list']);
            }

            return array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function get_safe_stage_defer_exclude_fragments(array $settings = array())
        {
            $list = $this->get_builtin_defer_js_exclude_fragments();
            $list = array_merge($list, $this->get_defer_stage_user_exclude_fragments($settings));

            return array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function get_defer_js_exclude_fragments(array $settings = array())
        {
            return $this->get_safe_stage_defer_exclude_fragments($settings);
        }

        private function get_builtin_defer_js_exclude_fragments()
        {
            return array(
                'googlesitekit',
                'google-site-kit',
                'sitekit',
                'revslider',
                'sr7',
                'tptools',
                'tp-tools',
                'sliderrevolution',
                'revolution',
                'rs6',
                '/plugins/revslider/',
                '/plugins/slider-revolution/',
                'elementor/assets/js/frontend',
                'elementor-pro/assets/js/frontend',
                'elementor-frontend',
                'elementor-pro-frontend',
                'frontend-modules',
                'header-footer-elementor',
                'hfe-',
                'smartmenus',
                'swiper',
                'swiper-bundle',
                'slick',
                'splide',
                'owl.carousel',
                'owlcarousel',
                'flickity',
                'keen-slider',
                'bxslider',
                'masterslider',
                'master-slider',
                'layerslider',
                'layer-slider',
                'metaslider',
                'smartslider',
                'smart-slider',
                'n2-ss',
                'soliloquy',
                'royalslider',
                'slider',
                'carousel',
                'slideshow',
                'hero',
                'html_types/image',
                'html_types/color',
                'html_types/label',
                'html_types/slide',
                'product-ajax-search',
                'search-popup',
                'nav-mobile',
                'megamenu',
                'header-cart',
                'cart-canvas',
                'off-canvas',
                'woocommerce-products-filter',
                'woof_',
                'dgwt-wcas',
                'woosq',
                'wpcbn',
                'contact-form-7',
                'author-arc',
                'mailerlite',
                'mc4wp',
                'complianz',
                'sourcebuster',
                'order-attribution',
                'eael-general',
            );
        }

        private function get_force_blocking_script_handles(array $settings = array())
        {
            $handles = array(
                'jquery',
                'jquery-core',
                'jquery-migrate',
                'wp-hooks',
                'wp-i18n',
                'wp-util',
                'wp-api-request',
                'wp-api-fetch',
                'wp-url',
                'wp-polyfill',
                'heartbeat',
            );

            if (!empty($settings['woo_safe_mode'])) {
                $handles = array_merge(
                    $handles,
                    array(
                        'woocommerce',
                        'wc-cart-fragments',
                        'wc-add-to-cart',
                        'wc-checkout',
                        'wc-single-product',
                        'wc-country-select',
                        'wc-address-i18n',
                        'wc-credit-card-form',
                        'selectWoo',
                        'js-cookie',
                        'sourcebuster-js',
                        'wc-order-attribution',
                    )
                );
            }

            return array_values(array_unique($handles));
        }

        private function get_safe_stage_excluded_handles(array $settings = array())
        {
            return array(
                'revslider',
                'sr7',
                'tptools',
                'tp-tools',
                'rs6',
                'slider-revolution',
                'elementor-frontend-js',
                'elementor-pro-frontend-js',
                'elementor-frontend-modules-js',
                'pro-elements-handlers-js',
                'swiper-js',
                'swiper-bundle-js',
                'slick-js',
                'splide-js',
                'owl-carousel-js',
                'flickity-js',
                'smartslider-frontend',
                'smartslider-simple-type-frontend',
                'n2-ss-public',
                'layerslider',
                'masterslider-core',
                'metaslider-flex-slider',
                'metaslider-responsive-slides',
                'hfe-frontend-js-js',
                'smartmenus-js',
                'bokifa-theme-js',
                'bokifa-elementor-frontend-js',
                'bokifa-elementor-products-js',
                'bokifa-elementor-posts-grid-js',
                'bokifa-nav-mobile-js',
                'bokifa-megamenu-frontend-js',
            );
        }

        private function get_defer_excluded_handles(array $settings = array())
        {
            return array_values(array_unique(array_merge(
                $this->get_force_blocking_script_handles($settings),
                $this->get_safe_stage_excluded_handles($settings)
            )));
        }

        private function is_same_host_public_url($url)
        {
            $url = trim((string) $url);
            if ('' === $url) {
                return false;
            }

            $absolute = $this->absolutize_public_resource_url($url, home_url('/'));
            if ('' === $absolute) {
                return false;
            }

            $src_host = (string) wp_parse_url($absolute, PHP_URL_HOST);
            $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            if ('' === $src_host || '' === $home_host) {
                return false;
            }

            return strtolower($src_host) === strtolower($home_host);
        }

        private function get_delay_non_critical_js_exclude_fragments()
        {
            $settings = $this->get_settings();
            $list = $this->get_defer_stage_user_exclude_fragments(is_array($settings) ? $settings : array());
            $list = array_merge($this->get_builtin_delay_non_critical_js_exclude_fragments(), $list);

            return array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function get_builtin_delay_non_critical_js_exclude_fragments()
        {
            $fragments = array(
                'jquery',
                'wp-hooks',
                'wp-i18n',
                'wp-util',
                'wp-api',
                'wp-polyfill',
                'jquery/ui',
                '/wp-includes/js/',
                'woocommerce',
                'wc-',
                '/plugins/woocommerce/assets/js/',
                'elementor',
                'elementor-pro',
                'frontend-modules',
                'pro-elements-handlers',
                'swiper',
                'swiper-bundle',
                'slick',
                'splide',
                'owl.carousel',
                'owlcarousel',
                'flickity',
                'keen-slider',
                'bxslider',
                'masterslider',
                'master-slider',
                'layerslider',
                'layer-slider',
                'metaslider',
                'smartslider',
                'smart-slider',
                'n2-ss',
                'soliloquy',
                'royalslider',
                'revslider',
                'sliderrevolution',
                'sr7',
                'tptools',
                'tp-tools',
                'html_types/image',
                'html_types/color',
                'html_types/label',
                'html_types/slide',
                'smartmenus',
                'megamenu',
                'nav-mobile',
                'off-canvas',
                'offcanvas',
                'modal',
                'popup',
                'lightbox',
                'fancybox',
                'photoswipe',
                'magnific-popup',
                'video',
                'mediaelement',
                'mejs',
                'plyr',
                'youtube',
                'vimeo',
                'wistia',
                'bricks',
                'oxygen',
                'wpbakery',
                'visual-composer',
                'vc_',
                'wpb_',
                'jet-',
                'crocoblock',
                'elementskit',
                'eael',
                'essential-addons',
                'contact-form-7',
                'wpforms',
                'fluentform',
                'forminator',
                'gravityforms',
            );

            $filtered = apply_filters('ucwp_delay_js_blocking_fragments', $fragments);
            if (is_array($filtered)) {
                $fragments = $filtered;
            }

            return array_values(array_unique(array_filter(array_map('strval', $fragments), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function script_handle_is_footer_group($handle)
        {
            $handle = (string) $handle;
            if ('' === $handle) {
                return false;
            }

            global $wp_scripts;
            if (!($wp_scripts instanceof WP_Scripts)) {
                return false;
            }

            $group = $wp_scripts->get_data($handle, 'group');
            return (is_numeric($group) && 1 <= (int) $group);
        }

        private function is_local_wp_content_script_url($src)
        {
            $path = strtolower((string) wp_parse_url((string) $src, PHP_URL_PATH));
            if ('' === $path) {
                $path = strtolower((string) $src);
            }

            return false !== strpos($path, '/wp-content/plugins/')
                || false !== strpos($path, '/wp-content/themes/')
                || false !== strpos($path, '/wp-content/uploads/');
        }

        private function script_matches_fragment_list($handle, $src, array $fragments)
        {
            $haystacks = array(
                strtolower(trim((string) $handle)),
                strtolower(trim((string) $src)),
                strtolower((string) wp_parse_url((string) $src, PHP_URL_PATH)),
            );

            foreach ($fragments as $fragment) {
                $fragment = strtolower(trim((string) $fragment));
                if ('' === $fragment) {
                    continue;
                }
                foreach ($haystacks as $haystack) {
                    if ('' !== $haystack && false !== strpos($haystack, $fragment)) {
                        return true;
                    }
                }
            }

            return false;
        }

        private function matches_non_critical_delay_patterns($handle, $src, $tag = '')
        {
            $haystack = strtolower((string) $handle . ' ' . $src . ' ' . $tag);
            $patterns = array(
                'cmplz',
                'complianz',
                'googlesitekit-events-provider',
                'google-site-kit',
                'sitekit',
                'mailerlite',
                'mc4wp',
                'sourcebuster',
                'order-attribution',
                'tooltipster',
                'magnific-popup',
                'perfect-scrollbar',
                'plainoverlay',
                'ion.range',
                'icheck',
                'easy-autocomplete',
                'jarallax',
                'tweenmax',
                'gsap',
                'sticky-kit',
                'slick',
                'swiper',
                'carousel',
                'slider',
                'popup',
                'modal',
                'lightbox',
                'off-canvas',
                'offcanvas',
                'search-popup',
                'ajax-search',
                'filter',
                'animation',
                'animate',
            );

            foreach ($patterns as $pattern) {
                if (false !== strpos($haystack, strtolower($pattern))) {
                    return true;
                }
            }

            return false;
        }

        private function should_delay_non_critical_script($handle, $src, $tag, array $settings = array())
        {
            $src = trim((string) $src);
            if ('' === $src || false === stripos((string) $tag, '<script')) {
                return false;
            }

            if (!$this->is_delayable_external_script_tag($tag)) {
                return false;
            }

            if (!$this->is_same_host_public_url($src)) {
                return false;
            }

            if ($this->script_matches_fragment_list($handle, $src, $this->get_delay_non_critical_js_exclude_fragments())) {
                return false;
            }

            if ($this->script_handle_has_inline_after_segments($handle)) {
                return false;
            }

            if ($this->is_script_force_blocking($handle, $src, $tag, $settings)) {
                return false;
            }

            if ($this->script_handle_has_enqueued_dependents($handle)) {
                return false;
            }

            $handle_lc = strtolower((string) $handle);
            $src_lc    = strtolower((string) $src);

            $forced_blocking_handles = array(
                'elementor-webpack-runtime',
                'elementor-pro-webpack-runtime',
                'elementor-frontend-js',
                'elementor-pro-frontend-js',
                'contact-form-7-js',
                'author-arc-handler-js',
            );

            if (in_array($handle_lc, $forced_blocking_handles, true)) {
                return false;
            }

            if (false !== strpos($src_lc, '/plugins/woocommerce/assets/')) {
                return false;
            }

            if (!empty($settings['critical_request_chain_relief']) && $this->script_matches_fragment_list($handle, $src, $this->get_critical_request_chain_delay_fragments($settings))) {
                return true;
            }

            if ($this->matches_non_critical_delay_patterns($handle, $src, $tag)) {
                return true;
            }

            if (empty($settings['delay_non_critical_js_aggressive'])) {
                return false;
            }

            if (!$this->is_local_wp_content_script_url($src)) {
                return false;
            }

            return $this->script_handle_is_footer_group($handle);
        }

        private function should_delay_script($handle, $src, $tag, array $settings = array())
        {
            $src = trim((string) $src);
            if ('' === $src || false === stripos($tag, '<script')) {
                return false;
            }

            if (!$this->is_delayable_external_script_tag($tag)) {
                return false;
            }

            if ($this->is_script_force_blocking($handle, $src, $tag, $settings) || $this->is_script_user_defer_excluded($handle, $src, $settings)) {
                return false;
            }

            $src_host = (string) wp_parse_url($src, PHP_URL_HOST);
            $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            if ('' !== $src_host && '' !== $home_host && strtolower($src_host) === strtolower($home_host)) {
                return false;
            }

            $haystack = strtolower((string) $handle . ' ' . $src);
            $patterns = array(
                'googletagmanager.com',
                'google-analytics.com',
                'gtag/js',
                'googleadservices.com',
                'g.doubleclick.net',
                'connect.facebook.net',
                'maps.googleapis.com',
                'maps.gstatic.com',
                'recaptcha',
                'hotjar',
                'clarity.ms',
                'bat.bing.com',
                'usefathom.com',
                'plausible.io',
            );

            foreach ($patterns as $pattern) {
                if (false !== strpos($haystack, strtolower($pattern))) {
                    return true;
                }
            }

            return false;
        }

        private function build_delayed_script_tag($tag, $handle, $src)
        {
            $original_attributes = $this->extract_html_tag_attributes($tag);
            $preserved_attributes = array();

            foreach ($original_attributes as $name => $value) {
                $name_lc = strtolower((string) $name);
                if (in_array($name_lc, array('src', 'async', 'defer', 'data-wp-strategy'), true)) {
                    continue;
                }

                if ('type' === $name_lc && !$this->is_javascript_mime_type((string) $value)) {
                    continue;
                }

                if (0 === strpos($name_lc, 'data-ucwp-')) {
                    continue;
                }

                $preserved_attributes[$name_lc] = (string) $value;
            }

            $attributes = array(
                'type'             => 'text/ucwp-delayed-js',
                'data-ucwp-src'    => esc_url($src),
                'data-ucwp-handle' => esc_attr((string) $handle),
            );

            if (!empty($preserved_attributes)) {
                $encoded = base64_encode((string) wp_json_encode($preserved_attributes));
                if ('' !== $encoded) {
                    $attributes['data-ucwp-attrs'] = esc_attr($encoded);
                }
            }

            foreach (array('id', 'crossorigin', 'referrerpolicy', 'integrity', 'nonce') as $attribute) {
                if (isset($preserved_attributes[$attribute]) && '' !== $preserved_attributes[$attribute]) {
                    $attributes['data-ucwp-' . $attribute] = esc_attr($preserved_attributes[$attribute]);
                }
            }

            $compiled = array();
            foreach ($attributes as $name => $value) {
                $compiled[] = sprintf('%s="%s"', $name, $value);
            }

            return '<script ' . implode(' ', $compiled) . '></script>';
        }

        private function extract_html_tag_attributes($tag)
        {
            $attributes = array();
            $tag = (string) $tag;
            if ('' === $tag || false === stripos($tag, '<script')) {
                return $attributes;
            }

            $inside = preg_replace('/^\s*<script\b/i', '', $tag, 1);
            $inside = preg_replace('/>.*$/s', '', is_string($inside) ? $inside : '');
            if (!is_string($inside) || '' === trim($inside)) {
                return $attributes;
            }

            if (preg_match_all('/\s+([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/i', $inside, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (empty($match[1])) {
                        continue;
                    }

                    $name = strtolower((string) $match[1]);
                    $value = '';
                    if (isset($match[2]) && '' !== $match[2]) {
                        $value = (string) $match[2];
                    } elseif (isset($match[3]) && '' !== $match[3]) {
                        $value = (string) $match[3];
                    } elseif (isset($match[4]) && '' !== $match[4]) {
                        $value = (string) $match[4];
                    } else {
                        $value = $name;
                    }

                    $attributes[$name] = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
                }
            }

            return $attributes;
        }

        private function is_delayable_external_script_tag($tag)
        {
            $tag = (string) $tag;
            if (false !== stripos($tag, ' nomodule')) {
                return false;
            }

            $type = $this->extract_attribute_from_html_tag($tag, 'type');
            if ('' === $type) {
                return true;
            }

            return $this->is_javascript_mime_type($type);
        }

        private function is_javascript_mime_type($type)
        {
            $type = strtolower(trim((string) $type));
            if ('' === $type) {
                return true;
            }

            $type = preg_replace('/\s*;.*$/', '', $type);
            return in_array($type, array(
                'text/javascript',
                'application/javascript',
                'application/ecmascript',
                'text/ecmascript',
                'text/jscript',
                'application/x-javascript',
            ), true);
        }


        public function cleanup_asset_chain_enqueue_assets()
        {
            if (is_admin()) {
                return;
            }

            $settings = $this->get_settings();
            if (empty($settings['asset_chain_cleanup'])) {
                return;
            }

            if ($this->current_request_matches_asset_cleanup_exclusion($settings)) {
                return;
            }

            if (!empty($settings['asset_cleanup_woo_product_assets']) && !$this->is_runtime_single_product_context()) {
                $this->dequeue_matching_queued_assets('script', $this->get_woocommerce_product_asset_cleanup_fragments());
                $this->dequeue_matching_queued_assets('style', $this->get_woocommerce_product_asset_cleanup_fragments());
            }

            if (!empty($settings['asset_cleanup_product_filter_assets']) && !$this->is_runtime_product_filter_context()) {
                $this->dequeue_matching_queued_assets('script', $this->get_product_filter_asset_cleanup_fragments());
                $this->dequeue_matching_queued_assets('style', $this->get_product_filter_asset_cleanup_fragments());
            }

            if (!empty($settings['asset_cleanup_woo_blocks_css']) && !$this->is_runtime_woocommerce_context()) {
                $this->dequeue_matching_queued_assets('style', array('wc-blocks.css', 'wc-blocks-style', 'woocommerce-blocks'));
            }
        }

        private function dequeue_matching_queued_assets($type, array $fragments)
        {
            $type = ('style' === $type) ? 'style' : 'script';
            $registry = ('style' === $type) ? wp_styles() : wp_scripts();
            if (!$registry || empty($registry->queue) || !is_array($registry->queue)) {
                return;
            }

            foreach ((array) $registry->queue as $handle) {
                $src = '';
                if (isset($registry->registered[$handle]) && is_object($registry->registered[$handle])) {
                    $src = (string) ($registry->registered[$handle]->src ?? '');
                }

                if (!$this->asset_matches_fragment_list($handle, $src, $fragments)) {
                    continue;
                }

                if ('style' === $type) {
                    wp_dequeue_style($handle);
                } else {
                    wp_dequeue_script($handle);
                }
            }
        }

        private function is_runtime_single_product_context()
        {
            return function_exists('is_product') && is_product();
        }

        private function is_runtime_woocommerce_context()
        {
            if (function_exists('is_product') && is_product()) {
                return true;
            }
            if (function_exists('is_shop') && is_shop()) {
                return true;
            }
            if (function_exists('is_product_taxonomy') && is_product_taxonomy()) {
                return true;
            }
            if (function_exists('is_cart') && is_cart()) {
                return true;
            }
            if (function_exists('is_checkout') && is_checkout()) {
                return true;
            }
            if (function_exists('is_account_page') && is_account_page()) {
                return true;
            }

            return false;
        }

        private function is_runtime_product_filter_context()
        {
            if (function_exists('is_shop') && is_shop()) {
                return true;
            }
            if (function_exists('is_product_taxonomy') && is_product_taxonomy()) {
                return true;
            }

            return false;
        }

        private function get_woocommerce_product_asset_cleanup_fragments()
        {
            return array(
                'jquery.zoom',
                'jquery.flexslider',
                'photoswipe',
                'photoswipe-ui-default',
                'wc-single-product',
                'single-product.min.js',
                'add-to-cart-variation',
                'wc-add-to-cart-variation',
                '/woocommerce/assets/js/frontend/single-product',
                '/woocommerce/assets/js/frontend/add-to-cart-variation',
                '/woocommerce/assets/js/zoom/',
                '/woocommerce/assets/js/flexslider/',
                '/woocommerce/assets/js/photoswipe/',
                '/woocommerce/assets/css/photoswipe',
            );
        }

        private function get_product_filter_asset_cleanup_fragments()
        {
            // Keep this list plugin-specific. Broad fragments such as tooltipster,
            // icheck, html_types/slider, or by_sku can also belong to unrelated UI.
            return array(
                'handle:woocommerce-products-filter',
                'handle:woof',
                'handle:woof_',
                'handle:woof-',
                'src:/plugins/woocommerce-products-filter/',
                'src:/plugins/woof-products-filter/',
                'src:/plugins/woocommerce-filter/',
                'src:/plugins/woocommerce-product-filter/',
                'src:/plugins/woocommerce-products-filter/js/',
                'src:/plugins/woocommerce-products-filter/ext/',
                'src:/plugins/woocommerce-products-filter/views/',
                'src:/plugins/woocommerce-products-filter/css/',
            );
        }

        private function asset_matches_fragment_list($handle, $src, array $fragments)
        {
            $handle_lc = strtolower(trim((string) $handle));
            $src_lc = strtolower(trim((string) $src));
            $path_lc = strtolower((string) wp_parse_url((string) $src, PHP_URL_PATH));
            $haystacks = array($handle_lc, $src_lc, $path_lc);

            foreach ($fragments as $fragment) {
                $fragment = strtolower(trim((string) $fragment));
                if ('' === $fragment) {
                    continue;
                }

                if (0 === strpos($fragment, 'handle:')) {
                    $needle = trim(substr($fragment, 7));
                    if ('' !== $needle && '' !== $handle_lc && false !== strpos($handle_lc, $needle)) {
                        return true;
                    }
                    continue;
                }

                if (0 === strpos($fragment, 'src:')) {
                    $needle = trim(substr($fragment, 4));
                    if ('' !== $needle && (('' !== $src_lc && false !== strpos($src_lc, $needle)) || ('' !== $path_lc && false !== strpos($path_lc, $needle)))) {
                        return true;
                    }
                    continue;
                }

                foreach ($haystacks as $haystack) {
                    if ('' !== $haystack && false !== strpos($haystack, $fragment)) {
                        return true;
                    }
                }
            }

            return false;
        }

        public function print_delayed_script_loader()
        {
            $settings = $this->get_settings();
            if ((empty($settings['delay_third_party_js']) && empty($settings['delay_non_critical_js'])) || is_admin()) {
                return;
            }

            $main_thread_relief = !empty($settings['main_thread_relief']) ? '1' : '0';
            echo '<script id="ucwp-delayed-loader">(function(){if(window.__ucwpDelayLoader){return;}window.__ucwpDelayLoader=1;var relief=' . $main_thread_relief . ';var fired=false;var timeoutMs=8000;function q(){return Array.prototype.slice.call(document.querySelectorAll(\'script[type="text/ucwp-delayed-js"][data-ucwp-src]\'));}function c(n,a){var v=n.getAttribute(\'data-ucwp-\'+a);return v||\'\';}function decodeAttrs(node){var raw=c(node,\'attrs\');var attrs={};if(raw){try{attrs=JSON.parse(atob(raw))||{};}catch(e){attrs={};}}[\'id\',\'crossorigin\',\'referrerpolicy\',\'integrity\',\'nonce\'].forEach(function(attr){var val=c(node,attr);if(val&&!attrs[attr]){attrs[attr]=val;}});return attrs;}function idle(cb){if(!relief){cb();return;}if(\'requestIdleCallback\' in window){window.requestIdleCallback(cb,{timeout:1500});return;}setTimeout(cb,80);}function wait(ms,cb){if(!relief||ms<=0){cb();return;}setTimeout(cb,ms);}function loadOne(node,done){var src=node.getAttribute(\'data-ucwp-src\');if(!src){done();return;}var s=document.createElement(\'script\');var attrs=decodeAttrs(node);Object.keys(attrs).forEach(function(attr){var val=attrs[attr];if(!attr||attr===\'src\'||attr===\'async\'||attr===\'defer\'||attr===\'data-wp-strategy\'||val===null||typeof val===\'undefined\'){return;}try{s.setAttribute(attr,String(val));}catch(e){}});s.async=false;var finished=false;function finish(){if(finished){return;}finished=true;done();}s.onload=finish;s.onerror=finish;setTimeout(finish,timeoutMs);s.src=src;if(node.parentNode){node.parentNode.insertBefore(s,node);node.parentNode.removeChild(node);}else{document.head.appendChild(s);}}function load(list,i){if(i>=list.length){return;}idle(function(){loadOne(list[i],function(){wait(relief?120:0,function(){load(list,i+1);});});});}function trigger(){if(fired){return;}fired=true;var list=q();if(!list.length){return;}load(list,0);}[\'scroll\',\'mousemove\',\'touchstart\',\'keydown\',\'click\'].forEach(function(evt){window.addEventListener(evt,trigger,{passive:true,once:true});});window.addEventListener(\'load\',function(){setTimeout(trigger,relief?2500:2000);},{once:true});setTimeout(trigger,relief?7000:6000);}());</script>' . "\n";
        }

        public function add_display_swap_to_google_fonts($src, $handle)
        {
            $settings = $this->get_settings();
            $font_url = $this->append_google_fonts_display_swap($src);

            if (!empty($settings['google_fonts_local_optimization'])) {
                $localized = $this->maybe_get_local_google_fonts_stylesheet_url($font_url);
                if (is_string($localized) && '' !== $localized) {
                    return $localized;
                }
            }

            if (!empty($settings['google_fonts_swap'])) {
                return $font_url;
            }

            return $src;
        }

        private function append_google_fonts_display_swap($url)
        {
            $url = (string) $url;
            if ('' === $url) {
                return $url;
            }

            $host = (string) wp_parse_url($url, PHP_URL_HOST);
            if (false === stripos($host, 'fonts.googleapis.com')) {
                return $url;
            }

            return add_query_arg('display', 'swap', $url);
        }

        private function apply_frontend_performance_optimizations($html)
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            $html = $this->normalize_protocol_relative_urls_in_html($html);

            if ($this->is_frontpage_css_scan_mode()) {
                return $html;
            }

            $settings = $this->get_settings();
            $html = $this->normalize_protected_script_loading_attributes_in_html($html, $settings);
            $slider_safe_mode = !empty($settings['slider_safe_mode']) && $this->should_use_safe_frontend_optimization_mode($html);
            $safe_mode = !empty($settings['frontend_safe_mode']) || $slider_safe_mode;

            if (!empty($settings['critical_request_chain_relief'])) {
                if ($slider_safe_mode) {
                    // Slider Safe Mode protects fragile slider runtime/CSS from delay/rewrite
                    // transforms, but manual priority preloads are safe and still useful for LCP.
                    $html = $this->inject_manual_critical_preload_links($html, $settings);
                    $html = $this->inject_detected_slider_fetch_preloads($html, $settings);
                } else {
                    $html = $this->apply_critical_request_chain_relief($html, $settings);
                }
            }

            if (!$slider_safe_mode && !empty($settings['asset_chain_cleanup']) && !$this->current_request_matches_asset_cleanup_exclusion($settings)) {
                $html = $this->apply_asset_chain_cleanup_to_html($html, $settings);
            }

            // Frontend Safe Mode is user-controlled. When it is off, UltraCache does not silently
            // re-enable it from automatic SR7/Elementor heuristics.
            if (!$safe_mode) {
                $html = $this->strip_probable_frontend_authoring_assets($html);
            }

            if (!empty($settings['homepage_css_bundle']) || !empty($settings['critical_css'])) {
                $bundle_mode = isset($settings['homepage_css_bundle_mode']) && 'aggressive' === strtolower((string) $settings['homepage_css_bundle_mode']) ? 'aggressive' : 'safe';
                $bundle_scope = $this->get_css_bundle_scope($settings);

                // Slider Safe Mode should not automatically disable all CSS bundling. On fragile
                // slider/hero pages, keep Stage 1 fully conservative, but let Stage 2 build/serve
                // bundles for non-slider local CSS while built-in slider stylesheet exclusions keep
                // SR7/Revolution/Swiper/Slick CSS as normal stylesheet links.
                if ($slider_safe_mode && 'aggressive' !== $bundle_mode) {
                    $bundle_mode = '';
                }

                if ('' !== $bundle_mode && !empty($settings['page_css_bundle_on_entry'])) {
                    if ('per-page' === $bundle_scope || ('homepage' === $bundle_scope && $this->is_frontpage_request_url()) || ('shared' === $bundle_scope && $this->is_frontpage_request_url())) {
                        $this->maybe_build_page_css_bundle_on_entry($html, $settings);
                    }
                }

                if ('' !== $bundle_mode) {
                    if ('homepage' === $bundle_scope) {
                        if ($this->is_frontpage_request_url()) {
                            $html = $this->maybe_replace_page_stylesheet_links_with_bundle($html, home_url('/'));
                        }
                    } elseif ('shared' === $bundle_scope) {
                        $html = $this->maybe_replace_page_stylesheet_links_with_bundle($html, home_url('/'));
                    } else {
                        $html = $this->maybe_replace_page_stylesheet_links_with_bundle($html);
                    }
                }
            }

            if (!$slider_safe_mode && (!empty($settings['async_css']) || !empty($settings['aggressive_async_css']))) {
                $safe_async_css_result = $this->rewrite_safe_async_css_links($html);
                if (is_array($safe_async_css_result)) {
                    $html = isset($safe_async_css_result['html']) && is_string($safe_async_css_result['html']) ? $safe_async_css_result['html'] : $html;
                    $this->record_analytics_safe_async_css(isset($safe_async_css_result['stats']) && is_array($safe_async_css_result['stats']) ? $safe_async_css_result['stats'] : array());
                }
            }

            if (!empty($settings['cls_dimensions'])) {
                $cls_dimensions_result = $this->inject_safe_cls_dimensions($html);
                if (is_array($cls_dimensions_result)) {
                    $html = isset($cls_dimensions_result['html']) && is_string($cls_dimensions_result['html']) ? $cls_dimensions_result['html'] : $html;
                    $this->record_analytics_cls_dimensions(isset($cls_dimensions_result['stats']) && is_array($cls_dimensions_result['stats']) ? $cls_dimensions_result['stats'] : array());
                }
            }

            if (!empty($settings['google_fonts_local_optimization'])) {
                $html = $this->rewrite_google_fonts_links_to_local_in_html($html);
            } elseif (!empty($settings['google_fonts_swap'])) {
                $html = $this->rewrite_google_fonts_display_swap_in_html($html);
            }

            if (!$slider_safe_mode && !empty($settings['self_hosted_font_css_optimization'])) {
                $html = $this->optimize_self_hosted_font_css_links($html);
                if (!$safe_mode && !empty($settings['self_hosted_font_runtime_rewrite'])) {
                    $html = $this->inject_runtime_font_css_url_map($html);
                }
            }

            if (!empty($settings['speculation_rules_enabled'])) {
                $html = $this->inject_speculation_rules_prefetch($html, $settings);
            }

            if (!empty($settings['lcp_image_priority'])) {
                if ($safe_mode) {
                    // Frontend Safe Mode avoids broad structural rewrites. When Slider Safe Mode is
                    // actually active on this page, apply only the SR7 lifecycle-aware priority guard.
                    if ($slider_safe_mode) {
                        $html = $this->apply_sr7_first_slide_lcp_priority_markup($html);
                    }
                    $html = $this->inject_safe_lcp_priority_preloads($html);
                } else {
                    $html = $this->optimize_lcp_image_markup($html);
                }
            }

            if (!$safe_mode) {
                $html = $this->minify_html_output_safely($html);
            }

            return $html;
        }


        private function apply_critical_request_chain_relief($html, array $settings = array())
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            $html = $this->inject_manual_critical_preload_links($html, $settings);
            $html = $this->inject_detected_slider_fetch_preloads($html, $settings);
            $html = $this->rewrite_chain_delay_stylesheet_links($html, $settings);

            return $html;
        }

        private function get_critical_request_chain_delay_fragments(array $settings = array())
        {
            if (isset($settings['critical_request_chain_delay_list']) && is_array($settings['critical_request_chain_delay_list'])) {
                return array_values(array_unique(array_filter(array_map('strval', $settings['critical_request_chain_delay_list']), static function ($item) {
                    return '' !== trim((string) $item);
                })));
            }

            return array();
        }

        private function inject_manual_critical_preload_links($html, array $settings = array())
        {
            if (false === stripos((string) $html, '</head>')) {
                return $html;
            }

            $links = array();
            $resource_lines = isset($settings['critical_resource_preload_list']) && is_array($settings['critical_resource_preload_list']) ? $settings['critical_resource_preload_list'] : array();
            $fetch_lines = isset($settings['critical_fetch_preload_list']) && is_array($settings['critical_fetch_preload_list']) ? $settings['critical_fetch_preload_list'] : array();

            foreach ($resource_lines as $line) {
                $candidate = $this->parse_critical_preload_line($line, '');
                if (!empty($candidate['url'])) {
                    if ($this->should_skip_sr7_generated_manual_preload($candidate, $html, $settings)) {
                        continue;
                    }
                    $links[] = $candidate;
                }
            }

            foreach ($fetch_lines as $line) {
                $candidate = $this->parse_critical_preload_line($line, 'fetch');
                if (!empty($candidate['url'])) {
                    $links[] = $candidate;
                }
            }

            return $this->inject_critical_preload_link_candidates($html, $links);
        }

        private function inject_detected_slider_fetch_preloads($html, array $settings = array())
        {
            if (false === stripos((string) $html, 'srengine=7') && false === stripos((string) $html, '/sliders/')) {
                return $html;
            }

            if (!preg_match_all('~(?:https?:)?//[^\s"\']+/sliders/\d+\?srengine=7[^\s"\'<)]*|/sliders/\d+\?srengine=7[^\s"\'<)]*~i', $html, $matches)) {
                return $html;
            }

            $links = array();
            foreach ((array) ($matches[0] ?? array()) as $url) {
                $url = html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8');
                $url = $this->absolutize_public_resource_url($url, home_url('/'));
                if ('' === $url) {
                    continue;
                }
                $links[$url] = array('url' => $url, 'as' => 'fetch');
                if (count($links) >= 4) {
                    break;
                }
            }

            return $this->inject_critical_preload_link_candidates($html, array_values($links));
        }

        private function should_skip_sr7_generated_manual_preload(array $candidate, $html, array $settings = array())
        {
            $url = isset($candidate['url']) ? $this->normalize_public_resource_url((string) $candidate['url']) : '';
            $as = isset($candidate['as']) ? strtolower(trim((string) $candidate['as'])) : '';
            if ('image' !== $as || '' === $url) {
                return false;
            }

            // SR7 stores generated/optimized image-list assets under /revslider/o/. Those hidden
            // placeholders are often not consumed within a few seconds, so manual preloading them
            // can create Chrome "preloaded but not used" warnings and compete with real LCP work.
            if (!$this->is_sr7_generated_image_list_url($url)) {
                return false;
            }

            if (empty($settings['slider_safe_mode']) && empty($settings['lcp_image_priority'])) {
                return false;
            }

            return false !== stripos((string) $html, '<sr7-')
                || false !== stripos((string) $html, 'sr7-module')
                || false !== stripos((string) $html, '/wp-content/uploads/revslider/');
        }
        private function parse_critical_preload_line($line, $forced_as = '')
        {
            $line = trim((string) $line);
            if ('' === $line || '#' === $line[0]) {
                return array('url' => '', 'as' => '');
            }

            $as = strtolower(trim((string) $forced_as));
            $url = $line;

            if (preg_match('/^(script|style|image|font|fetch|document)\s+(.+)$/i', $line, $matches)) {
                $as = strtolower((string) $matches[1]);
                $url = trim((string) $matches[2]);
            }

            $url = $this->absolutize_public_resource_url($url, home_url('/'));
            if ('' === $url || 0 === strpos($url, 'data:') || 0 === strpos($url, '#')) {
                return array('url' => '', 'as' => '');
            }

            if ('' === $as) {
                $as = $this->infer_preload_as_from_url($url);
            }

            return array('url' => $url, 'as' => $as);
        }

        private function infer_preload_as_from_url($url)
        {
            $path = strtolower((string) wp_parse_url((string) $url, PHP_URL_PATH));
            if (preg_match('/\.css$/', $path)) {
                return 'style';
            }
            if (preg_match('/\.js$/', $path)) {
                return 'script';
            }
            if (preg_match('/\.(woff2?|ttf|otf)$/', $path)) {
                return 'font';
            }
            if (preg_match('/\.(avif|webp|png|jpe?g|gif)$/', $path)) {
                return 'image';
            }

            return 'fetch';
        }

        private function inject_critical_preload_link_candidates($html, array $candidates)
        {
            if (empty($candidates) || false === stripos((string) $html, '</head>')) {
                return $html;
            }

            $tags = array();
            $seen = array();
            foreach ($candidates as $candidate) {
                $url = isset($candidate['url']) ? esc_url((string) $candidate['url']) : '';
                $as = isset($candidate['as']) ? strtolower(trim((string) $candidate['as'])) : 'fetch';
                if ('' === $url || isset($seen[$url])) {
                    continue;
                }
                if (false !== strpos($html, 'href="' . $url . '"') || false !== strpos($html, "href='" . $url . "'")) {
                    continue;
                }

                $seen[$url] = true;
                if (!in_array($as, array('script', 'style', 'image', 'font', 'fetch', 'document'), true)) {
                    $as = 'fetch';
                }

                $attrs = 'rel="preload" as="' . esc_attr($as) . '" href="' . $url . '"';
                if ('image' === $as) {
                    $attrs .= ' fetchpriority="high"';
                } elseif ('font' === $as) {
                    $attrs .= ' type="font/woff2" crossorigin';
                } elseif ('fetch' === $as) {
                    $attrs .= ' crossorigin';
                }

                $tags[] = '<link ' . $attrs . ' data-ucwp-critical-chain="1">';
            }

            if (empty($tags)) {
                return $html;
            }

            return preg_replace('/<\/head>/i', implode("\n", $tags) . "\n</head>", $html, 1);
        }

        private function rewrite_chain_delay_stylesheet_links($html, array $settings = array())
        {
            $fragments = $this->get_critical_request_chain_delay_fragments($settings);
            if (empty($fragments) || false === stripos((string) $html, '<link')) {
                return $html;
            }

            $that = $this;
            $rewritten = preg_replace_callback('/<link\b[^>]*>/i', static function ($matches) use ($that, $fragments) {
                $tag = isset($matches[0]) ? (string) $matches[0] : '';
                if ('' === $tag || !$that->html_tag_rel_contains_stylesheet($tag)) {
                    return $tag;
                }
                if (false !== stripos($tag, 'data-ucwp-async-css=') || false !== stripos($tag, 'data-ucwp-frontpage-css=') || false !== stripos($tag, 'data-ucwp-page-css-bundle=')) {
                    return $tag;
                }
                $href = $that->extract_attribute_from_html_tag($tag, 'href');
                if ('' === $href || !$that->asset_matches_fragment_list('', $href, $fragments)) {
                    return $tag;
                }

                return $that->force_async_stylesheet_link_tag($tag);
            }, $html);

            return is_string($rewritten) ? $rewritten : $html;
        }

        private function force_async_stylesheet_link_tag($tag)
        {
            $tag = (string) $tag;
            if ('' === $tag || false !== stripos($tag, ' onload=')) {
                return $tag;
            }

            $rewritten = $this->remove_html_tag_attribute($tag, 'media');
            $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'media', 'print');
            $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'onload', "this.media='all'");
            $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'data-ucwp-async-css', '1');
            return $rewritten . '<noscript>' . $tag . '</noscript>';
        }

        private function apply_asset_chain_cleanup_to_html($html, array $settings = array())
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            if ($this->html_has_asset_cleanup_exclusion($html, $settings)) {
                return $html;
            }

            if (!empty($settings['asset_cleanup_woo_product_assets']) && !$this->html_has_single_product_context($html)) {
                $html = $this->remove_asset_tags_matching_fragments($html, $this->get_woocommerce_product_asset_cleanup_fragments());
            }

            if (!empty($settings['asset_cleanup_product_filter_assets']) && !$this->html_has_product_filter_context($html)) {
                $html = $this->remove_asset_tags_matching_fragments($html, $this->get_product_filter_asset_cleanup_fragments());
            }

            if (!empty($settings['asset_cleanup_woo_blocks_css']) && !$this->html_has_woocommerce_block_context($html)) {
                $html = $this->remove_asset_tags_matching_fragments($html, array('wc-blocks.css', 'wc-blocks-style', 'woocommerce-blocks'));
            }

            return $html;
        }

        private function remove_asset_tags_matching_fragments($html, array $fragments)
        {
            if (empty($fragments)) {
                return $html;
            }

            $that = $this;
            $html = preg_replace_callback('/<script\b[^>]*\bsrc=("|\')(.*?)\1[^>]*>\s*<\/script>/is', static function ($matches) use ($that, $fragments) {
                $tag = isset($matches[0]) ? (string) $matches[0] : '';
                $src = isset($matches[2]) ? html_entity_decode((string) $matches[2], ENT_QUOTES, 'UTF-8') : '';
                return $that->asset_matches_fragment_list('', $src, $fragments) ? '' : $tag;
            }, $html);

            $html = preg_replace_callback('/<link\b[^>]*>/i', static function ($matches) use ($that, $fragments) {
                $tag = isset($matches[0]) ? (string) $matches[0] : '';
                $href = $that->extract_attribute_from_html_tag($tag, 'href');
                return ('' !== $href && $that->asset_matches_fragment_list('', $href, $fragments)) ? '' : $tag;
            }, is_string($html) ? $html : '');

            return is_string($html) ? $html : '';
        }

        private function get_asset_cleanup_exclude_fragments(array $settings = array())
        {
            $defaults = array(
                'elementor',
                'bricks',
                'oxygen',
                'wpbakery',
                'vc_',
                'revslider',
                'sr7',
                'ajaxsearch',
                'fibosearch',
                '.dgwt-wcas',
                'aws-container',
                'cart',
                'checkout',
                'account',
            );

            $user_list = array();
            if (isset($settings['asset_cleanup_exclude_list']) && is_array($settings['asset_cleanup_exclude_list'])) {
                $user_list = $settings['asset_cleanup_exclude_list'];
            }

            return array_values(array_unique(array_filter(array_map('strval', array_merge($defaults, $user_list)), 'strlen')));
        }

        private function current_request_matches_asset_cleanup_exclusion(array $settings = array())
        {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? strtolower((string) wp_unslash($_SERVER['REQUEST_URI'])) : '';
            if ('' === $request_uri) {
                return false;
            }

            foreach ($this->get_asset_cleanup_exclude_fragments($settings) as $fragment) {
                $fragment = strtolower(trim((string) $fragment));
                if ('' !== $fragment && false !== strpos($request_uri, $fragment)) {
                    return true;
                }
            }

            return false;
        }

        private function html_has_asset_cleanup_exclusion($html, array $settings = array())
        {
            $haystack = strtolower((string) $html);
            if ('' === $haystack) {
                return false;
            }

            foreach ($this->get_asset_cleanup_exclude_fragments($settings) as $fragment) {
                $fragment = strtolower(trim((string) $fragment));
                if ('' !== $fragment && false !== strpos($haystack, $fragment)) {
                    return true;
                }
            }

            return false;
        }

        private function html_has_single_product_context($html)
        {
            $haystack = strtolower((string) $html);
            return false !== strpos($haystack, 'single-product')
                || false !== strpos($haystack, 'product_title')
                || false !== strpos($haystack, 'woocommerce-product-gallery')
                || false !== strpos($haystack, 'summary entry-summary');
        }

        private function html_has_product_filter_context($html)
        {
            $haystack = strtolower((string) $html);
            return false !== strpos($haystack, 'woocommerce-products-filter')
                || false !== strpos($haystack, 'woof_')
                || false !== strpos($haystack, 'woof_container')
                || false !== strpos($haystack, 'wpf-')
                || false !== strpos($haystack, 'berocket')
                || false !== strpos($haystack, 'data-css-class="woof"')
                || false !== strpos($haystack, 'ion.rangeSlider');
        }

        private function html_has_woocommerce_block_context($html)
        {
            $haystack = strtolower((string) $html);
            return false !== strpos($haystack, 'wp-block-woocommerce')
                || false !== strpos($haystack, 'wc-block-')
                || false !== strpos($haystack, 'woocommerce-cart')
                || false !== strpos($haystack, 'woocommerce-checkout')
                || false !== strpos($haystack, 'woocommerce-account');
        }

        private function inject_speculation_rules_prefetch($html, array $settings = array())
        {
            if (!$this->should_inject_speculation_rules_prefetch($html, $settings)) {
                return $html;
            }

            if (false !== stripos($html, 'type="speculationrules"') || false !== stripos($html, "type='speculationrules'")) {
                return $html;
            }

            $rules = $this->build_speculation_rules_prefetch_config($settings);
            if (empty($rules)) {
                return $html;
            }

            $json = wp_json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json) || '' === $json) {
                return $html;
            }

            $script = '<script type="speculationrules">' . $json . '</script>';

            return preg_replace('/<\/head>/i', $script . "\n</head>", $html, 1);
        }

        private function should_inject_speculation_rules_prefetch($html, array $settings = array())
        {
            if (empty($settings['speculation_rules_enabled'])) {
                return false;
            }

            if (!is_string($html) || '' === $html || false === stripos($html, '</head>')) {
                return false;
            }

            if (is_admin() || is_user_logged_in() || is_preview() || is_feed() || is_trackback() || is_robots()) {
                return false;
            }

            if ('' !== sanitize_text_field(ucwp_query_value('preview')) || '' !== sanitize_text_field(ucwp_query_value('customize_changeset_uuid')) || '' !== sanitize_text_field(ucwp_query_value('customize_autosaved'))) {
                return false;
            }

            if (function_exists('is_search') && is_search()) {
                return false;
            }

            if (function_exists('is_cart') && is_cart()) {
                return false;
            }

            if (function_exists('is_checkout') && is_checkout()) {
                return false;
            }

            if (function_exists('is_account_page') && is_account_page()) {
                return false;
            }

            return true;
        }

        private function build_speculation_rules_prefetch_config(array $settings = array())
        {
            $conditions = array(
                array('href_matches' => '/*'),
                array('not' => array('href_matches' => '/wp-admin/*')),
                array('not' => array('href_matches' => '/wp-login.php*')),
                array('not' => array('href_matches' => '/cart/*')),
                array('not' => array('href_matches' => '/checkout/*')),
                array('not' => array('href_matches' => '/my-account/*')),
                array('not' => array('href_matches' => '/wc-api/*')),
                array('not' => array('href_matches' => '/logout*')),
                array('not' => array('href_matches' => '/*\?*')),
                array('not' => array('selector_matches' => '[rel~=nofollow]')),
                array('not' => array('selector_matches' => '[target]')),
                array('not' => array('selector_matches' => '[download]')),
                array('not' => array('selector_matches' => '.no-speculate')),
                array('not' => array('selector_matches' => '.no-prerender')),
                array('not' => array('selector_matches' => '.ajax_add_to_cart')),
            );

            $excluded_paths = array();
            if (!empty($settings['excluded_paths']) && is_array($settings['excluded_paths'])) {
                $excluded_paths = $settings['excluded_paths'];
            }

            foreach ($excluded_paths as $path) {
                $path = trim((string) $path);
                if ('' === $path || '/' === $path) {
                    continue;
                }

                $pattern = $this->convert_path_to_speculation_href_pattern($path);
                if ('' !== $pattern) {
                    $conditions[] = array('not' => array('href_matches' => $pattern));
                }
            }

            return array(
                'prefetch' => array(
                    array(
                        'where'     => array('and' => $conditions),
                        'eagerness' => 'moderate',
                    ),
                ),
            );
        }

        private function convert_path_to_speculation_href_pattern($path)
        {
            $path = trim((string) $path);
            if ('' === $path) {
                return '';
            }

            $path = preg_replace('#https?://[^/]+#i', '', $path);
            if (!is_string($path) || '' === $path) {
                return '';
            }

            if ('/' !== $path[0]) {
                $path = '/' . $path;
            }

            if (substr($path, -1) === '*') {
                return $path;
            }

            if (substr($path, -1) === '/') {
                return $path . '*';
            }

            return $path . '*';
        }

        private function minify_html_output_safely($html)
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            $html = str_replace("
", '', $html);
            $tokens = array();
            $html = $this->protect_html_regions_from_safe_minify($html, $tokens);
            $html = $this->remove_noncritical_html_comments_for_safe_minify($html);
            $html = $this->minify_head_html_safely($html);
            $html = trim($html);

            if (!empty($tokens)) {
                $html = strtr($html, $tokens);
            }

            return $html;
        }

        private function should_use_safe_frontend_optimization_mode($html)
        {
            if (!is_string($html) || '' === $html) {
                return false;
            }

            if ($this->has_fragile_revslider_shell($html) || $this->html_has_slider_safe_mode_marker($html)) {
                return true;
            }

            $html_lc = strtolower($html);
            $empty_custom_elements = 0;
            if (preg_match_all('/<([a-z][a-z0-9]*-[a-z0-9-]*)\b[^>]*>\s*<\/\1>/i', $html, $matches)) {
                $empty_custom_elements = is_array($matches[0]) ? count($matches[0]) : 0;
            }

            $has_client_bootstrap = false;
            foreach (array(
                'data-reactroot',
                '__next',
                'astro-island',
                'ng-version',
                'window.__nuxt',
                'window.__remixcontext',
                'customElements.define',
            ) as $marker) {
                if (false !== strpos($html_lc, strtolower($marker))) {
                    $has_client_bootstrap = true;
                    break;
                }
            }

            if ($empty_custom_elements >= 8 && $has_client_bootstrap) {
                return true;
            }

            return false;
        }

        private function html_has_slider_safe_mode_marker($html)
        {
            if (!is_string($html) || '' === $html) {
                return false;
            }

            $html_lc = strtolower($html);
            foreach (array(
                'revslider',
                'slider revolution',
                'wp-block-themepunch-revslider',
                'sr7-',
                '<sr7-',
                'rs-module',
                'rs-fullwidth-wrap',
                '/wp-content/uploads/revslider/',
                'revapi',
                'tptools',
                'smartslider',
                'n2-ss',
                'metaslider',
                'layerslider',
                'masterslider',
                'swiper-container',
                'swiper-wrapper',
                'slick-slider',
                'splide__track',
                'owl-carousel',
                'elementor-widget-slides',
                'elementor-widget-image-carousel',
                'elementor-widget-media-carousel'
            ) as $marker) {
                if (false !== strpos($html_lc, strtolower($marker))) {
                    return true;
                }
            }

            return false;
        }

        private function has_fragile_revslider_shell($html)
        {
            if (!is_string($html) || '' === $html) {
                return false;
            }

            $html_lc = strtolower($html);
            $has_revslider = false;
            foreach (array('wp-block-themepunch-revslider', 'revslider', 'sr7-', 'rs-module') as $marker) {
                if (false !== strpos($html_lc, $marker)) {
                    $has_revslider = true;
                    break;
                }
            }

            if (!$has_revslider) {
                return false;
            }

            $empty_sr7_slides = 0;
            if (preg_match_all('/<sr7-slide\b[^>]*>\s*<\/sr7-slide>/i', $html, $matches)) {
                $empty_sr7_slides = is_array($matches[0]) ? count($matches[0]) : 0;
            }

            if ($empty_sr7_slides < 2) {
                return false;
            }

            $has_inline_slider_bootstrap = false !== strpos($html_lc, 'window.sr7')
                || false !== strpos($html_lc, 'sr7.pmh')
                || false !== strpos($html_lc, '_tpt.preparemoduleheight');
            if (!$has_inline_slider_bootstrap) {
                return false;
            }

            $has_image_shell_media = false;
            foreach (array(
                'image_lists',
                'data-dbsrc=',
                '/wp-content/uploads/revslider/',
                '<sr7-img',
                '<img data-src=',
            ) as $marker) {
                if (false !== strpos($html_lc, strtolower($marker))) {
                    $has_image_shell_media = true;
                    break;
                }
            }

            $has_video_driven_media = false;
            foreach (array(
                '<video',
                '.mp4',
                'youtube',
                'vimeo',
                'data-video',
            ) as $marker) {
                if (false !== strpos($html_lc, strtolower($marker))) {
                    $has_video_driven_media = true;
                    break;
                }
            }

            if (!$has_image_shell_media) {
                return true;
            }

            return $has_video_driven_media;
        }

        private function protect_html_regions_from_safe_minify($html, array &$tokens)
        {
            $pattern = '#<(script|style|pre|textarea|svg|math|title|code|noscript|template)\b[^>]*>.*?</\1>#is';
            $counter = 0;

            return (string) preg_replace_callback(
                $pattern,
                function ($matches) use (&$tokens, &$counter) {
                    $placeholder = "%%UCWP_HTML_MINIFY_TOKEN_" . (++$counter) . "%%";
                    $tokens[$placeholder] = (string) $matches[0];
                    return $placeholder;
                },
                (string) $html
            );
        }

        private function remove_noncritical_html_comments_for_safe_minify($html)
        {
            return (string) preg_replace_callback(
                '/<!--([\s\S]*?)-->/u',
                function ($matches) {
                    $comment = isset($matches[1]) ? trim((string) $matches[1]) : '';
                    if ('' === $comment) {
                        return '';
                    }

                    $normalized = strtolower($comment);
                    foreach (array('[if ', '<![endif', 'wp:', '/wp:', 'more', 'nextpage', 'googleoff:', 'googleon:', 'noindex', '/noindex') as $prefix) {
                        if (0 === strpos($normalized, $prefix)) {
                            return (string) $matches[0];
                        }
                    }

                    return '';
                },
                (string) $html
            );
        }

        private function minify_head_html_safely($html)
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            if (!preg_match('/<head\b[^>]*>([\s\S]*?)<\/head>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
                return $html;
            }

            $head_html = (string) $matches[0][0];
            $head_offset = (int) $matches[0][1];
            $head_inner = isset($matches[1][0]) ? (string) $matches[1][0] : '';
            $minified_inner = (string) preg_replace('/>\s+</', '><', $head_inner);
            $minified_head = preg_replace('/<head\b([^>]*)>[\s\S]*<\/head>/i', '<head$1>' . $minified_inner . '</head>', $head_html, 1);
            if (!is_string($minified_head) || '' === $minified_head) {
                return $html;
            }

            return substr($html, 0, $head_offset) . $minified_head . substr($html, $head_offset + strlen($head_html));
        }


        private function rewrite_safe_async_css_links($html)
        {
            $result = array(
                'html' => $html,
                'stats' => $this->get_default_safe_async_css_stats(),
            );

            if (!is_string($html) || '' === $html || false === stripos($html, '<link')) {
                return $result;
            }

            $stats = $this->get_default_safe_async_css_stats();
            $updated_html = (string) preg_replace_callback(
                '/<link\b[^>]*>/i',
                function ($matches) use (&$stats) {
                    return $this->maybe_rewrite_safe_async_css_link_tag((string) $matches[0], $stats);
                },
                $html
            );

            $result['html'] = $updated_html;
            $result['stats'] = $stats;
            return $result;
        }

        private function get_default_safe_async_css_stats()
        {
            return array(
                'scanned' => 0,
                'rewritten' => 0,
                'skipped' => 0,
                'unresolved' => 0,
            );
        }

        private function maybe_rewrite_safe_async_css_link_tag($tag, array &$stats)
        {
            $tag = (string) $tag;
            if ('' === $tag) {
                return $tag;
            }

            if (!$this->html_tag_rel_contains_stylesheet($tag)) {
                return $tag;
            }

            $stats['scanned']++;

            if (false !== stripos($tag, 'data-ucwp-async-css=') || false !== stripos($tag, 'data-ucwp-frontpage-css=') || false !== stripos($tag, 'data-ucwp-page-css-bundle=')) {
                $stats['skipped']++;
                return $tag;
            }

            $href = $this->extract_attribute_from_html_tag($tag, 'href');
            if ('' === $href) {
                $stats['unresolved']++;
                return $tag;
            }

            $media = strtolower(trim((string) $this->extract_attribute_from_html_tag($tag, 'media')));
            if ('' !== $media && 'all' !== $media) {
                $stats['skipped']++;
                return $tag;
            }

            if (preg_match('/\s(?:disabled|onload)\b/i', $tag)) {
                $stats['skipped']++;
                return $tag;
            }

            $absolute_url = $this->absolutize_public_resource_url($href, home_url('/'));
            if ('' === $absolute_url || !$this->is_safe_local_public_stylesheet_url($absolute_url)) {
                $stats['unresolved']++;
                return $tag;
            }

            if (!$this->should_async_css_stylesheet_url($absolute_url, $tag)) {
                $stats['skipped']++;
                return $tag;
            }

            $rewritten = $this->remove_html_tag_attribute($tag, 'media');
            $rewritten = $this->remove_html_tag_attribute($rewritten, 'onload');
            $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'media', 'print');
            $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'onload', "this.media='all'");
            $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'data-ucwp-async-css', '1');
            $rewritten .= '<noscript>' . $tag . '</noscript>';

            $stats['rewritten']++;
            return $rewritten;
        }

        private function html_tag_rel_contains_stylesheet($tag)
        {
            $rel = strtolower(trim((string) $this->extract_attribute_from_html_tag($tag, 'rel')));
            if ('' === $rel) {
                return false;
            }

            $parts = preg_split('/\s+/', $rel);
            if (!is_array($parts)) {
                return false;
            }

            return in_array('stylesheet', $parts, true) && !in_array('preload', $parts, true) && !in_array('alternate', $parts, true);
        }


        private function should_exclude_stylesheet_url_by_fragments($url, array $fragments)
        {
            $haystacks = array(
                strtolower((string) $url),
                strtolower((string) wp_parse_url((string) $url, PHP_URL_PATH)),
            );

            foreach ($fragments as $fragment) {
                $fragment = strtolower(trim((string) $fragment));
                if ('' === $fragment) {
                    continue;
                }
                foreach ($haystacks as $haystack) {
                    if ('' !== $haystack && false !== strpos($haystack, $fragment)) {
                        return true;
                    }
                }
            }

            return false;
        }

        private function get_async_css_exclude_fragments()
        {
            $settings = $this->get_settings();
            $list = isset($settings['async_css_exclude_list']) && is_array($settings['async_css_exclude_list']) ? $settings['async_css_exclude_list'] : array();
            return array_values(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            }));
        }

        private function get_aggressive_async_css_exclude_fragments()
        {
            $settings = $this->get_settings();
            $list = isset($settings['aggressive_async_css_exclude_list']) && is_array($settings['aggressive_async_css_exclude_list']) ? $settings['aggressive_async_css_exclude_list'] : array();
            $list = array_merge($this->get_builtin_aggressive_async_css_exclude_fragments(), $list);
            return array_values(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            }));
        }

        private function get_builtin_aggressive_async_css_exclude_fragments()
        {
            return array(
                '/uploads/elementor/css/',
                '/plugins/woocommerce/',
                '/post-',
                'base/elementor.css',
                'gutenberg-blocks.css',
                'font-css/',
                'fontawesome',
                'eicons',
                'elementor-icons',
                'wc-blocks.css',
                'select2.css',
                'mailerlite_forms.css',
                'woocommerce-products-filter',
                '/woof',
            );
        }

        private function get_builtin_homepage_css_bundle_exclude_fragments()
        {
            return array(
                // Keep fragile slider/hero runtime CSS outside generated bundles. These files often
                // coordinate with JS initialization order and should remain explicit stylesheet links.
                'revslider',
                'slider-revolution',
                'revolution',
                'sr7',
                'rs7',
                'rs6',
                'tptools',
                'tp-tools',
                'rs-module',
                'swiper',
                'slick',
                'splide',
                'owl.carousel',
                'smartslider',
                'n2-ss',
                'layerslider',
                'metaslider',
            );
        }

        private function get_homepage_css_bundle_exclude_fragments()
        {
            $settings = $this->get_settings();
            $list = isset($settings['homepage_css_bundle_exclude_list']) && is_array($settings['homepage_css_bundle_exclude_list'])
                ? $settings['homepage_css_bundle_exclude_list']
                : (isset($settings['critical_css_exclude_list']) && is_array($settings['critical_css_exclude_list']) ? $settings['critical_css_exclude_list'] : array());

            $list = array_merge($this->get_builtin_homepage_css_bundle_exclude_fragments(), (array) $list);

            return array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function get_critical_css_exclude_fragments()
        {
            return $this->get_homepage_css_bundle_exclude_fragments();
        }

        private function is_safe_local_public_stylesheet_url($url)
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
            return 'css' === $extension;
        }

        private function should_async_css_stylesheet_url($url, $tag = '')
        {
            $settings = $this->get_settings();
            $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
            if ('' === $path) {
                return false;
            }

            $exclude_fragments = $this->get_async_css_exclude_fragments();
            $aggressive_enabled = !empty($settings['aggressive_async_css']);
            if ($aggressive_enabled) {
                $exclude_fragments = array_values(array_unique(array_merge($exclude_fragments, $this->get_aggressive_async_css_exclude_fragments())));
            }

            if ($this->should_exclude_stylesheet_url_by_fragments($url, $exclude_fragments)) {
                return false;
            }

            if ($aggressive_enabled) {
                $hard_block_patterns = array(
                    '/dashicons/i',
                    '/admin-bar/i',
                    '/\/wp-admin\//i',
                    '/\/uploads\/elementor\/css\//i',
                    '/\/plugins\/woocommerce\//i',
                    '/\/post-\d+\.css$/i',
                    '/base\/elementor\.css$/i',
                    '/gutenberg-blocks\.css$/i',
                    '/font-css\//i',
                    '/fontawesome/i',
                    '/eicons/i',
                    '/wc-blocks\.css$/i',
                    '/select2\.css$/i',
                    '/mailerlite_forms\.css$/i',
                    '/woocommerce-products-filter/i',
                    '/\/woof/i',
                );

                foreach ($hard_block_patterns as $pattern) {
                    if (preg_match($pattern, $path) || preg_match($pattern, (string) $tag)) {
                        return false;
                    }
                }

                return true;
            }

            $critical_patterns = array(
                '/\/themes\//',
                '/\/woocommerce\//',
                '/\/elementor\//',
                '/\/elementor-pro\//',
                '/\/header-footer-elementor\.css$/',
                '/\/widgets-css\//',
                '/\/post-\d+\.css$/',
                '/\/base\/elementor\.css$/',
                '/\/fontawesome(?:\.min)?\.css$/',
                '/\/(?:solid|brands|regular|all)(?:\.min)?\.css$/',
                '/\/elementor-icons(?:-shared-0)?(?:\.min)?\.css$/',
                '/\/eicons(?:\.min)?\.css$/',
                '/\/manrope\.css$/',
                '/\/fraunces\.css$/',
            );

            foreach ($critical_patterns as $pattern) {
                if (preg_match($pattern, $path)) {
                    return false;
                }
            }

            $safe_patterns = array(
                '/e-animation/i',
                '/(?:^|[\/_.-])animate(?:[\/_.-]|$)/i',
                '/fadein/i',
                '/magnific-popup/i',
                '/tooltipster/i',
                '/plainoverlay/i',
                '/perfect-scrollbar/i',
                '/easy-autocomplete/i',
            );

            foreach ($safe_patterns as $pattern) {
                if (preg_match($pattern, $path)) {
                    return true;
                }
            }

            return false;
        }

        private function remove_html_tag_attribute($html, $attribute)
        {
            $attribute = trim((string) $attribute);
            if ('' === $attribute) {
                return (string) $html;
            }

            return (string) preg_replace('/\s+' . preg_quote($attribute, '/') . '=(\"|\').*?\1/i', '', (string) $html);
        }

        private function inject_safe_cls_dimensions($html)
        {
            $result = array(
                'html' => $html,
                'stats' => $this->get_default_safe_cls_dimension_stats(),
            );

            if (!is_string($html) || '' === $html || false === stripos($html, '<img')) {
                return $result;
            }

            if (class_exists('WP_HTML_Tag_Processor')) {
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
                $content_path . '/cache/ultracache-avif/',
                $content_path . '/cache/ultracache-webp/',
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

        private function set_or_add_html_tag_attribute($html, $attribute, $value)
        {
            $attribute = trim((string) $attribute);
            if ('' === $attribute) {
                return (string) $html;
            }

            $quoted_value = esc_attr((string) $value);
            $pattern = '/\\b' . preg_quote($attribute, '/') . '=(\"|\')(.*?)(\\1)/i';
            if (preg_match($pattern, (string) $html)) {
                return (string) preg_replace($pattern, $attribute . '="' . $quoted_value . '"', (string) $html, 1);
            }

            return (string) preg_replace('/\s*\/?>$/', ' ' . $attribute . '="' . $quoted_value . '"$0', (string) $html, 1);
        }

        private function rewrite_google_fonts_display_swap_in_html($html)
        {
            if (false === stripos($html, 'fonts.googleapis.com')) {
                return $html;
            }

            return (string) preg_replace_callback(
                "#https?://fonts\.googleapis\.com/[^\"'\s>]+#i",
                function ($matches) {
                    return $this->append_google_fonts_display_swap($matches[0]);
                },
                $html
            );
        }

        private function rewrite_google_fonts_links_to_local_in_html($html)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, 'fonts.googleapis.com')) {
                return $html;
            }

            return (string) preg_replace_callback(
                "#https?://fonts\.googleapis\.com/[^\"'\s>]+#i",
                function ($matches) {
                    $url = $this->append_google_fonts_display_swap($matches[0]);
                    $localized = $this->maybe_get_local_google_fonts_stylesheet_url($url);
                    return (is_string($localized) && '' !== $localized) ? $localized : $url;
                },
                $html
            );
        }

        private function maybe_get_local_google_fonts_stylesheet_url($url)
        {
            $normalized_url = $this->append_google_fonts_display_swap((string) $url);
            if (!$this->is_google_fonts_stylesheet_url($normalized_url)) {
                return '';
            }

            $dir = $this->get_google_fonts_cache_dir();
            if (!is_dir($dir) && !wp_mkdir_p($dir)) {
                return '';
            }

            $hash = md5($normalized_url);
            $css_file = $dir . $hash . '.css';
            $css_url = $this->get_google_fonts_cache_url_base() . $hash . '.css';

            if (is_readable($css_file) && filesize($css_file) > 0) {
                return $css_url;
            }

            $response = wp_remote_get(
                $normalized_url,
                array(
                    'timeout' => 15,
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
            if ('' === trim($localized_css)) {
                return '';
            }

            if (false === ucwp_safe_file_put_contents($css_file, $localized_css, 0, 'google-fonts-css')) {
                return '';
            }

            return $css_url;
        }

        private function build_local_google_fonts_css($css, $css_url, $group_hash)
        {
            $css = $this->normalize_font_face_display_in_css((string) $css);
            if ('' === trim($css)) {
                return '';
            }

            return (string) preg_replace_callback('/url\(([^)]+)\)/i', function ($matches) use ($css_url, $group_hash) {
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

            $dir = $this->get_google_fonts_cache_dir();
            if (!is_dir($dir) && !wp_mkdir_p($dir)) {
                return '';
            }

            $file_hash = md5($remote_url);
            $file_name = $group_hash . '-' . $file_hash . '.' . $extension;
            $file_path = $dir . $file_name;
            $file_url = $this->get_google_fonts_cache_url_base() . $file_name;

            if (is_readable($file_path) && filesize($file_path) > 0) {
                return $file_url;
            }

            $response = wp_remote_get(
                $remote_url,
                array(
                    'timeout' => 20,
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

            if (false === ucwp_safe_file_put_contents($file_path, $body, 0, 'google-font-binary')) {
                return '';
            }

            return $file_url;
        }

        private function is_google_fonts_stylesheet_url($url)
        {
            $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
            return '' !== $host && false !== strpos($host, 'fonts.googleapis.com');
        }

        private function get_google_fonts_cache_dir()
        {
            return trailingslashit(UCWP_CACHE_DIR) . 'google-fonts/';
        }

        private function get_google_fonts_cache_url_base()
        {
            return trailingslashit(content_url('cache/ultracache/google-fonts'));
        }

        private function get_google_fonts_remote_user_agent()
        {
            return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
        }

        private function optimize_lcp_image_markup($html)
        {
            if (false === stripos($html, '</head')) {
                return $html;
            }

            $manual_candidate = $this->find_manual_lcp_candidate($html);
            if (null !== $manual_candidate) {
                return $this->apply_lcp_candidate_optimizations($html, $manual_candidate);
            }

            $has_standard_images = false !== stripos($html, '<img');
            $has_sr7_markup = false !== stripos($html, '<sr7-') || false !== stripos($html, 'sr7-module') || false !== stripos($html, '/wp-content/uploads/revslider/');
            $has_manual_lcp_override = !empty($this->get_settings()['lcp_image_priority_override_list']);
            if (!$has_standard_images && !$has_sr7_markup && !$has_manual_lcp_override) {
                return $html;
            }

            if (class_exists('WP_HTML_Tag_Processor')) {
                return $this->optimize_lcp_image_markup_with_tag_processor($html);
            }

            return $this->optimize_lcp_image_markup_with_regex($html);
        }


        private function inject_safe_lcp_priority_preloads($html)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '</head')) {
                return $html;
            }

            $manual_candidate = $this->find_manual_lcp_candidate($html);
            if (null !== $manual_candidate && !empty($manual_candidate['url'])) {
                return $this->inject_lcp_preload_link($html, $manual_candidate['url']);
            }

            $sr7_candidate = $this->find_best_sr7_lcp_candidate($html);
            if (null !== $sr7_candidate && !empty($sr7_candidate['url'])) {
                if ($this->is_sr7_generated_image_list_url($sr7_candidate['url'])) {
                    return $html;
                }
                return $this->inject_lcp_preload_link($html, $sr7_candidate['url']);
            }

            return $html;
        }


        private function apply_sr7_first_slide_lcp_priority_markup($html)
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            if (false === stripos($html, '<sr7-') && false === stripos($html, 'sr7-module')) {
                return $html;
            }

            $html = preg_replace_callback(
                '/<sr7-slide\b[^>]*>.*?<\/sr7-slide>/is',
                function ($matches) {
                    $slide = isset($matches[0]) ? (string) $matches[0] : '';
                    if ('' === $slide || false === stripos($slide, '<sr7-img')) {
                        return $slide;
                    }

                    $slide = preg_replace_callback(
                        '/<sr7-img\b[^>]*>/i',
                        function ($tag_matches) {
                            $tag = isset($tag_matches[0]) ? (string) $tag_matches[0] : '';
                            return $this->add_lcp_priority_attributes_to_start_tag($tag, true);
                        },
                        $slide
                    );

                    return is_string($slide) ? $slide : (string) $matches[0];
                },
                $html,
                1
            );

            if (!is_string($html)) {
                return '';
            }

            // Some SR7 layers are created or normalized by runtime JS. Add a tiny, ID-independent
            // guard so first-slide image layers are still marked when generated dynamically.
            return $this->inject_sr7_lcp_priority_runtime_script($html);
        }

        private function add_lcp_priority_attributes_to_start_tag($tag, $include_loading = false)
        {
            $tag = (string) $tag;
            if ('' === $tag || '>' !== substr(rtrim($tag), -1)) {
                return $tag;
            }

            $replacement = $tag;
            if (false === stripos($replacement, 'fetchpriority=')) {
                $replacement = rtrim(substr($replacement, 0, -1)) . ' fetchpriority="high">';
            }

            if ($include_loading) {
                if (preg_match('~\sloading=("|\')lazy\1~i', $replacement)) {
                    $replacement = preg_replace('~\sloading=("|\')lazy\1~i', ' loading="eager"', $replacement, 1);
                } elseif (false === stripos($replacement, ' loading=')) {
                    $replacement = rtrim(substr($replacement, 0, -1)) . ' loading="eager">';
                }
            }

            return $replacement;
        }

        private function inject_sr7_lcp_priority_runtime_script($html)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '</head>')) {
                return $html;
            }

            if (false !== stripos($html, 'id="ucwp-sr7-lcp-priority"') || false !== stripos($html, "id='ucwp-sr7-lcp-priority'")) {
                return $html;
            }

            $script = <<<'HTML'
<script id="ucwp-sr7-lcp-priority">(function(){"use strict";if(window.__ucwpSr7LcpPriority){return;}window.__ucwpSr7LcpPriority=1;var MAX=8;function tagName(n){return n&&n.tagName?String(n.tagName).toLowerCase():"";}function mark(n){try{if(!n||n.nodeType!==1){return false;}var t=tagName(n);if(t!=="sr7-img"&&t!=="img"){return false;}n.setAttribute("fetchpriority","high");n.setAttribute("data-ucwp-sr7-lcp","1");if(!n.hasAttribute("loading")||n.getAttribute("loading")==="lazy"){n.setAttribute("loading","eager");}if(!n.hasAttribute("decoding")){n.setAttribute("decoding","sync");}return true;}catch(e){return false;}}function firstSlide(m){if(!m){return null;}return m.querySelector('sr7-slide[data-key="1"]')||m.querySelector('sr7-slide:not([aria-hidden="true"])')||m.querySelector("sr7-slide");}function markSlide(s){if(!s){return false;}var nodes=s.querySelectorAll("sr7-img,img");var did=false;for(var i=0;i<nodes.length&&i<MAX;i++){did=mark(nodes[i])||did;var inner=nodes[i].querySelectorAll?nodes[i].querySelectorAll("img"):[];for(var j=0;j<inner.length&&j<MAX;j++){did=mark(inner[j])||did;}}return did;}function markModule(m){return markSlide(firstSlide(m));}function markAll(){var did=false;var modules=document.querySelectorAll("sr7-module,rs-module");if(modules.length){for(var i=0;i<modules.length;i++){did=markModule(modules[i])||did;}}else{var slides=document.querySelectorAll('sr7-slide[data-key="1"],sr7-slide');for(var k=0;k<slides.length&&k<2;k++){did=markSlide(slides[k])||did;}}return did;}function schedule(){markAll();requestAnimationFrame(function(){markAll();});}document.addEventListener("sr.module.ready",function(){schedule();},true);document.addEventListener("sr.slide.afterChange",function(){schedule();},true);document.addEventListener("SR7_MODULE_READY",function(){schedule();},true);if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",schedule,{once:true});}else{schedule();}var tries=[100,300,800,1500,3000,5000];for(var x=0;x<tries.length;x++){setTimeout(schedule,tries[x]);}var mo;if("MutationObserver" in window){mo=new MutationObserver(function(muts){for(var i=0;i<muts.length;i++){for(var j=0;j<muts[i].addedNodes.length;j++){var n=muts[i].addedNodes[j];if(!n||n.nodeType!==1){continue;}var tn=tagName(n);if(tn==="sr7-img"||tn==="img"||tn==="sr7-slide"||tn==="sr7-module"||tn==="rs-module"||(n.querySelector&&(n.querySelector("sr7-img,img,sr7-slide,sr7-module,rs-module")))){schedule();return;}}}});mo.observe(document.documentElement,{childList:true,subtree:true});setTimeout(function(){try{if(mo){mo.disconnect();}}catch(e){}schedule();},8000);}}());</script>
HTML;

            return preg_replace('/<\/head>/i', $script . "\n</head>", $html, 1);
        }

        private function find_manual_lcp_candidate($html)
        {
            $settings = $this->get_settings();
            $entries = isset($settings['lcp_image_priority_override_list']) && is_array($settings['lcp_image_priority_override_list']) ? $settings['lcp_image_priority_override_list'] : array();
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
                    return $tag_candidate;
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

            usort($candidates, function ($left, $right) {
                return (int) $right['score'] <=> (int) $left['score'];
            });

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

            if (preg_match_all('/[^\s"\'<>]+\.(?:avif|webp|png|jpe?g|gif|bmp|heic|heif)(?:\?[^\s"\'<>]*)?/i', (string) $html, $matches)) {
                foreach ($matches[0] as $raw_url) {
                    $candidate_url = trim((string) $raw_url, " \t\n\r\0\x0B()[]{}.,;");
                    if ('' !== $candidate_url && false !== stripos($candidate_url, $needle) && $this->is_lcp_candidate_image_url($candidate_url)) {
                        return $candidate_url;
                    }
                }
            }

            return '';
        }

        private function optimize_lcp_image_markup_with_tag_processor($html)
        {
            $processor = new WP_HTML_Tag_Processor($html);
            $best = $this->find_best_sr7_lcp_candidate($html);

            while ($processor->next_tag()) {
                $candidate = $this->extract_best_lcp_candidate_from_current_tag($processor);
                if (null === $candidate) {
                    continue;
                }

                if (null === $best || $candidate['score'] > $best['score']) {
                    $best = $candidate;
                }
            }

            return $this->apply_lcp_candidate_optimizations($html, $best);
        }

        private function optimize_lcp_image_markup_with_regex($html)
        {
            $best = $this->find_best_sr7_lcp_candidate($html);
            $regex_best = $this->find_best_lcp_candidate_with_regex($html);
            if (null !== $regex_best && (null === $best || $regex_best['score'] > $best['score'])) {
                $best = $regex_best;
            }

            return $this->apply_lcp_candidate_optimizations($html, $best);
        }

        private function extract_best_lcp_candidate_from_current_tag($processor)
        {
            $tag = strtoupper((string) $processor->get_tag());
            if (in_array($tag, array('SCRIPT', 'LINK', 'META', 'NOSCRIPT', 'STYLE', 'SOURCE', 'IFRAME'), true)) {
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
            foreach (array('src', 'data-src', 'data-lazy-src', 'data-lazyload', 'data-image', 'data-origin', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image', 'poster') as $attribute) {
                $raw = $processor->get_attribute($attribute);
                if (!is_string($raw) || '' === trim($raw)) {
                    continue;
                }

                $candidate = $this->build_lcp_candidate_from_values($raw, $context + array('attribute' => $attribute));
                if (null !== $candidate) {
                    $candidates[] = $candidate;
                }
            }

            foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
                $raw = $processor->get_attribute($attribute);
                if (!is_string($raw) || '' === trim($raw)) {
                    continue;
                }
                foreach ($this->extract_candidate_urls_from_srcset($raw) as $srcset_url) {
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

            if (empty($candidates)) {
                return null;
            }

            usort($candidates, function ($left, $right) {
                return (int) $right['score'] <=> (int) $left['score'];
            });

            return $candidates[0];
        }

        private function find_best_lcp_candidate_with_regex($html)
        {
            $candidates = array();
            if (preg_match_all('/<(img|video|div|section|figure|picture|a|sr7-img|sr7-slide|sr7-content|sr7-module)\b[^>]*>/i', $html, $matches)) {
                foreach ($matches[0] as $tag_html) {
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
            }

            if (empty($candidates)) {
                return null;
            }

            usort($candidates, function ($left, $right) {
                return (int) $right['score'] <=> (int) $left['score'];
            });

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
                'data-image' => 88,
                'data-origin' => 78,
                'data-srcset' => 72,
                'data-lazy-srcset' => 72,
                'data-lazyload-srcset' => 72,
                'data-bg' => 75,
                'data-background' => 75,
                'data-bg-image' => 75,
                'data-background-image' => 75,
                'poster' => 70,
                'style' => 65,
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
                'A' => 4,
                'SR7-IMG' => 110,
                'SR7-SLIDE' => 35,
                'SR7-CONTENT' => 24,
                'SR7-MODULE' => 20,
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

            foreach (array('hero', 'banner', 'slider', 'slide', 'cover', 'featured', 'showcase', 'intro', 'splash', 'main', 'cta', 'background', 'bg') as $positive_term) {
                if (false !== strpos($meta_haystack, $positive_term)) {
                    $score += 18;
                }
            }

            if (false !== strpos($meta_haystack, '/wp-content/uploads/revslider/')) {
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
            if ($width > 0 && $height > 0) {
                $area = $width * $height;
                if ($area >= 120000) {
                    $score += 50;
                } elseif ($area >= 40000) {
                    $score += 30;
                } elseif ($area <= 20000) {
                    $score -= 20;
                }
            } elseif (0 === $width || 0 === $height) {
                $score -= 10;
            }

            return array(
                'url' => $normalized_url,
                'raw_url' => (string) $raw_url,
                'attribute' => $attribute,
                'tag' => $tag,
                'score' => $score,
            );
        }

        private function apply_lcp_candidate_optimizations($html, $best)
        {
            if (null === $best || empty($best['url'])) {
                if (false !== stripos((string) $html, 'sr7-')) {
                    $this->record_analytics_sr7_lcp(array(
                        'detected' => 0,
                        'preloadsInjected' => 0,
                        'skipped' => 0,
                        'unresolved' => 1,
                    ));
                }
                return $html;
            }

            $updated = $this->boost_lcp_candidate_markup($html, $best);
            $preload = $this->inject_lcp_preload_link($updated, $best['url']);

            if (!empty($best['is_sr7'])) {
                $preloads_injected = ($preload !== $updated) ? 1 : 0;
                $this->record_analytics_sr7_lcp(array(
                    'detected' => 1,
                    'preloadsInjected' => $preloads_injected,
                    'skipped' => $preloads_injected ? 0 : 1,
                    'unresolved' => 0,
                ));
            }

            return $preload;
        }

        private function find_best_sr7_lcp_candidate($html)
        {
            $html = (string) $html;
            if ('' === $html) {
                return null;
            }

            if (false === stripos($html, 'sr7') && false === stripos($html, '/wp-content/uploads/revslider/')) {
                return null;
            }

            $candidates = array();

            if (preg_match_all('/<sr7-img\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $tag_html = (string) $match[0];
                    $offset = isset($match[1]) ? (int) $match[1] : 0;
                    $context = array(
                        'tag'        => 'SR7-IMG',
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
                            $candidate['is_sr7'] = true;
                            $candidate['score'] += max(0, 120 - min(100, (int) floor($offset / 5000)));
                            $candidates[] = $candidate;
                        }
                    }

                    foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
                        foreach ($this->extract_candidate_urls_from_srcset($this->extract_attribute_from_html_tag($tag_html, $attribute)) as $srcset_url) {
                            $candidate = $this->build_lcp_candidate_from_values($srcset_url, $context + array('attribute' => $attribute));
                            if (null !== $candidate) {
                                $candidate['is_sr7'] = true;
                                $candidate['score'] += max(0, 120 - min(100, (int) floor($offset / 5000)));
                                $candidates[] = $candidate;
                            }
                        }
                    }

                    foreach ($this->extract_candidate_urls_from_style($context['style']) as $style_url) {
                        $candidate = $this->build_lcp_candidate_from_values($style_url, $context + array('attribute' => 'style'));
                        if (null !== $candidate) {
                            $candidate['is_sr7'] = true;
                            $candidate['score'] += max(0, 120 - min(100, (int) floor($offset / 5000)));
                            $candidates[] = $candidate;
                        }
                    }
                }
            }

            if (preg_match_all("#https?://[^\"'\\s<>()]+/wp-content/uploads/revslider/[^\"'\\s<>()]+#i", $html, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $raw_url = (string) $match[0];
                    $offset = isset($match[1]) ? (int) $match[1] : 0;
                    $context_slice = strtolower((string) substr($html, max(0, $offset - 240), 480));
                    $candidate = $this->build_lcp_candidate_from_values($raw_url, array(
                        'tag' => false !== strpos($context_slice, 'sr7') ? 'SR7-IMG' : 'SCRIPT',
                        'attribute' => false !== strpos($context_slice, 'sr7') ? 'data-lazyload' : 'script',
                        'class' => $context_slice,
                        'id' => $context_slice,
                        'style' => $context_slice,
                    ));
                    if (null !== $candidate) {
                        $candidate['is_sr7'] = true;
                        $candidate['score'] += max(0, 160 - min(120, (int) floor($offset / 4000)));
                        if (false !== strpos($context_slice, 'sr7_1') || false !== strpos($context_slice, '-1-')) {
                            $candidate['score'] += 80;
                        }
                        $candidates[] = $candidate;
                    }
                }
            }

            if (empty($candidates)) {
                return null;
            }

            usort($candidates, function ($left, $right) {
                return (int) $right['score'] <=> (int) $left['score'];
            });

            return $candidates[0];
        }

        private function extract_candidate_urls_from_srcset($srcset)
        {
            $srcset = (string) $srcset;
            if ('' === trim($srcset)) {
                return array();
            }

            $urls = array();
            foreach (array_map('trim', explode(',', $srcset)) as $part) {
                if ('' === $part) {
                    continue;
                }
                $segments = preg_split('/\s+/', $part, 2);
                if (!empty($segments[0])) {
                    $urls[] = $segments[0];
                }
            }

            return array_values(array_unique($urls));
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
            $attribute = preg_quote((string) $attribute, '/');
            if (preg_match('/\b' . $attribute . '=(\"|\')(.*?)\1/i', (string) $html, $matches) && isset($matches[2])) {
                return (string) $matches[2];
            }

            return '';
        }

        private function boost_lcp_candidate_markup($html, array $candidate)
        {
            $raw_url = isset($candidate['raw_url']) ? (string) $candidate['raw_url'] : '';
            $attribute = isset($candidate['attribute']) ? (string) $candidate['attribute'] : '';
            $tag = isset($candidate['tag']) ? strtoupper((string) $candidate['tag']) : '';
            if ('' === $raw_url || '' === $attribute || '' === $tag) {
                return $html;
            }

            if ('IMG' !== $tag && 'VIDEO' !== $tag && 'SR7-IMG' !== $tag) {
                return $html;
            }

            $tag_name = ('SR7-IMG' === $tag) ? 'sr7-img' : strtolower($tag);
            $pattern = '~<' . $tag_name . '\b[^>]*\b' . preg_quote($attribute, '~') . '=(["\'])' . preg_quote($raw_url, '~') . '\1[^>]*>~i';
            return (string) preg_replace_callback(
                $pattern,
                function ($matches) use ($tag, $tag_name) {
                    $replacement = $matches[0];
                    if (false === stripos($replacement, 'fetchpriority=')) {
                        $replacement = preg_replace('~<' . preg_quote($tag_name, '~') . '\b~i', '<' . $tag_name . ' fetchpriority="high"', $replacement, 1);
                    }

                    if ('IMG' === $tag || 'SR7-IMG' === $tag) {
                        if (preg_match('~\sloading=(["\'])lazy\1~i', $replacement)) {
                            $replacement = preg_replace('~\sloading=(["\'])lazy\1~i', ' loading="eager"', $replacement, 1);
                        } elseif (false === stripos($replacement, ' loading=')) {
                            $replacement = preg_replace('~<' . preg_quote($tag_name, '~') . '\b~i', '<' . $tag_name . ' loading="eager"', $replacement, 1);
                        }
                    }

                    return $replacement;
                },
                $html,
                1
            );
        }

        private function is_sr7_generated_image_list_url($url)
        {
            $url = $this->normalize_public_resource_url($url);
            if ('' === $url) {
                return false;
            }

            return false !== stripos($url, '/wp-content/uploads/revslider/o/');
        }
        private function is_lcp_candidate_image_url($src)
        {
            $src = $this->normalize_public_resource_url($src);
            if ('' === $src || 0 === strpos($src, 'data:')) {
                return false;
            }

            if (preg_match('/\.(svg|ico)($|\?)/i', $src)) {
                return false;
            }

            return (bool) preg_match('/\.(avif|webp|png|jpe?g|gif|bmp|heic|heif)($|\?)/i', $src);
        }

        private function inject_lcp_preload_link($html, $src)
        {
            $src = esc_url($this->normalize_public_resource_url($src));
            if ('' === $src) {
                return $html;
            }

            if ($this->should_skip_lcp_preload_url($src)) {
                return $html;
            }

            $pattern = '~<link\b[^>]*\brel=(["\'])preload\1[^>]*\bas=(["\'])image\2[^>]*\bhref=(["\'])' . preg_quote($src, '~') . '\3[^>]*>~i';
            if (preg_match($pattern, $html, $matches)) {
                $existing = (string) $matches[0];
                $replacement = $existing;

                if (false === stripos($replacement, 'fetchpriority=')) {
                    $replacement = rtrim(substr($replacement, 0, -1)) . ' fetchpriority="high">';
                }

                if (false === stripos($replacement, 'crossorigin=')) {
                    $replacement = rtrim(substr($replacement, 0, -1)) . ' crossorigin="anonymous">';
                }

                if ($replacement !== $existing) {
                    $html = preg_replace($pattern, addcslashes($replacement, '\\\$'), $html, 1);
                }

                return $html;
            }

            $link = '<link rel="preload" as="image" href="' . $src . '" fetchpriority="high" crossorigin="anonymous">';
            if (false === stripos($html, '</head>')) {
                return $html;
            }

            return preg_replace('/<\/head>/i', $link . "
</head>", $html, 1);
        }

        private function should_skip_lcp_preload_url($url)
        {
            $url = $this->normalize_public_resource_url($url);
            if ('' === $url) {
                return true;
            }

            return !$this->is_lcp_candidate_image_url($url);
        }

        private function normalize_public_resource_url($url)
        {
            $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
            if ('' === $url) {
                return '';
            }

            $home_url = home_url('/');
            $preferred_scheme = (string) wp_parse_url($home_url, PHP_URL_SCHEME);
            if ('' === $preferred_scheme) {
                $preferred_scheme = is_ssl() ? 'https' : 'http';
            }
            $preferred_host = strtolower((string) wp_parse_url($home_url, PHP_URL_HOST));

            if (0 === strpos($url, '//')) {
                return $preferred_scheme . ':' . $url;
            }

            if (preg_match('#^https?://#i', $url)) {
                $url_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
                $url_scheme = (string) wp_parse_url($url, PHP_URL_SCHEME);
                if ('' !== $preferred_host && '' !== $url_host && $preferred_host === $url_host && strtolower($url_scheme) !== strtolower($preferred_scheme)) {
                    if (function_exists('set_url_scheme')) {
                        return set_url_scheme($url, $preferred_scheme);
                    }
                }
            }

            return $url;
        }

        private function normalize_protocol_relative_urls_in_html($html)
        {
            $scheme = wp_parse_url(home_url('/'), PHP_URL_SCHEME);
            if (!$scheme) {
                $scheme = is_ssl() ? 'https' : 'http';
            }

            $html = (string) preg_replace_callback(
                "/(\b(?:src|href|poster|data-src|data-lazy-src|data-bg|data-background|data-bg-image|data-background-image)=)([\"'])(?:\/\/)/i",
                function ($matches) use ($scheme) {
                    return $matches[1] . $matches[2] . $scheme . '://';
                },
                $html
            );

            $html = (string) preg_replace_callback(
                "/url\((\s*[\"']?)\/\//i",
                function ($matches) use ($scheme) {
                    return 'url(' . $matches[1] . $scheme . '://';
                },
                $html
            );

            $html = (string) preg_replace_callback(
                "/(\bsrcset=)([\"'])([^\"']+)\2/i",
                function ($matches) use ($scheme) {
                    $parts = array_map('trim', explode(',', $matches[3]));
                    foreach ($parts as $index => $part) {
                        $segments = preg_split('/\s+/', $part, 2);
                        if (!empty($segments[0]) && 0 === strpos($segments[0], '//')) {
                            $segments[0] = $scheme . ':' . $segments[0];
                            $parts[$index] = trim(implode(' ', array_filter($segments, 'strlen')));
                        }
                    }
                    return $matches[1] . $matches[2] . implode(', ', $parts) . $matches[2];
                },
                $html
            );

            $html = (string) preg_replace_callback(
                "/([\"'])\/\/([^\"'\s<>]+)\1/",
                function ($matches) use ($scheme) {
                    return $matches[1] . $scheme . '://' . $matches[2] . $matches[1];
                },
                $html
            );

            return $html;
        }


        private function optimize_self_hosted_font_css_links($html)
        {
            if (false === stripos($html, '<link') || false === stripos($html, '.css')) {
                return $html;
            }

            $preload_urls = array();
            $html = (string) preg_replace_callback(
                '/<link\b[^>]*\bhref=(\"|\')(.*?)\1[^>]*>/is',
                function ($matches) use (&$preload_urls) {
                    $tag = (string) $matches[0];
                    $href = isset($matches[2]) ? (string) $matches[2] : '';
                    if (false === stripos($tag, 'rel=') || false === stripos(strtolower($tag), 'stylesheet')) {
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

                    $asset = $this->build_optimized_font_css_asset($href);
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

                    $replacement_url = esc_url($asset['css_url']);
                    return preg_replace('/\bhref=(\"|\')(.*?)\1/i', 'href="' . $replacement_url . '"', $tag, 1);
                },
                $html
            );

            if (!empty($preload_urls)) {
                $html = $this->inject_font_preload_links($html, $preload_urls);
            }

            return $html;
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
            if (false !== strpos($source_path_lc, '/cache/ultracache/css-bundles/')) {
                return array();
            }

            $css = ucwp_safe_file_get_contents($source_path, 'build_optimized_font_css_asset', true);
            if (!is_string($css) || '' === $css || false === stripos($css, '@font-face')) {
                return array();
            }

            $optimized_css = $this->rewrite_self_hosted_font_css_content($css, $source_url);
            $hash = md5($source_url . '|' . md5($optimized_css));
            $dir = trailingslashit(UCWP_CACHE_DIR) . 'font-css/';
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            $index_file = $dir . 'index.php';
            if (!file_exists($index_file)) {
                ucwp_safe_file_put_contents($index_file, "<?php
// Silence is golden.
");
            }

            $file = $dir . $hash . '.css';
            if (!file_exists($file)) {
                ucwp_safe_file_put_contents($file, $optimized_css);
            }

            return array(
                'css_url'      => content_url('cache/ultracache/font-css/' . $hash . '.css'),
                'preload_urls' => $this->extract_preloadable_font_urls_from_css($optimized_css, 2),
            );
        }

        private function inject_runtime_font_css_url_map($html)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '</head>')) {
                return $html;
            }

            if (false !== stripos($html, 'data-ucwp-font-css-map=')) {
                return $html;
            }

            $map = $this->get_runtime_local_font_css_url_map();
            if (empty($map) || !is_array($map)) {
                return $html;
            }

            $json = wp_json_encode($map);
            if (!is_string($json) || '' === $json) {
                return $html;
            }

            $script = '<script data-ucwp-font-css-map="1">(function(){var map=' . $json . ';if(!map||typeof map!=="object"){return;}var toAbs=function(url){if(!url){return "";}try{return new URL(url, document.baseURI).href;}catch(e){try{var a=document.createElement("a");a.href=url;return a.href||url;}catch(err){return url;}}};var rewrite=function(node){if(!node||node.nodeType!==1){return;}var tag=String(node.tagName||"").toLowerCase();if(tag!=="link"){return;}var rel=String(node.getAttribute("rel")||"").toLowerCase();if(rel.indexOf("stylesheet")===-1){return;}var href=node.getAttribute("href")||node.href||"";if(!href){return;}var abs=toAbs(href);if(abs&&map[abs]&&abs!==map[abs]){node.setAttribute("href",map[abs]);try{node.href=map[abs];}catch(e){}}};var scan=function(root){try{var links=(root||document).querySelectorAll? (root||document).querySelectorAll("link[rel][href]") : [];for(var i=0;i<links.length;i++){rewrite(links[i]);}}catch(e){}};scan(document);try{var mo=new MutationObserver(function(list){for(var i=0;i<list.length;i++){var added=list[i]&&list[i].addedNodes?list[i].addedNodes:[];for(var j=0;j<added.length;j++){var node=added[j];rewrite(node);scan(node);}}});mo.observe(document.documentElement||document.head||document.body,{childList:true,subtree:true});}catch(e){}})();</script>';

            return preg_replace('/<\/head>/i', $script . "\n</head>", $html, 1);
        }

        private function get_runtime_local_font_css_url_map()
        {
            static $cached_map = null;
            if (null !== $cached_map) {
                return $cached_map;
            }

            $cache_key = 'ucwp_runtime_font_css_url_map_v1';
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                $cached_map = $cached;
                return $cached_map;
            }

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
            $cached_map = $map;
            set_transient($cache_key, $cached_map, 30 * MINUTE_IN_SECONDS);
            return $cached_map;
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

        private function rewrite_self_hosted_font_css_content($css, $source_url)
        {
            $css = $this->normalize_protocol_relative_urls_in_css($css, $source_url);
            return $this->normalize_font_face_display_in_css($css);
        }

        private function normalize_font_face_display_in_css($css)
        {
            $css = (string) $css;
            if ('' === $css || false === stripos($css, '@font-face')) {
                return $css;
            }

            return (string) preg_replace_callback(
                '/@font-face\s*{.*?}/is',
                function ($matches) {
                    $block = (string) $matches[0];
                    if (false !== stripos($block, 'font-display')) {
                        $updated = preg_replace('/font-display\s*:\s*[^;}{]+;?/i', 'font-display: swap;', $block, 1);
                        return is_string($updated) && '' !== $updated ? $updated : $block;
                    }

                    $updated = preg_replace('/}\s*$/', "  font-display: swap;
}", $block, 1);
                    return is_string($updated) && '' !== $updated ? $updated : $block;
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

            return preg_replace('/<\/head>/i', implode("
", $links) . "
</head>", $html, 1);
        }

        private function normalize_local_path_for_compare($path)
        {
            return rtrim(str_replace('\\', '/', (string) $path), '/');
        }

        private function path_is_within_root($path, $root)
        {
            $path = $this->normalize_local_path_for_compare($path);
            $root = $this->normalize_local_path_for_compare($root);

            if ('' === $path || '' === $root) {
                return false;
            }

            return $path === $root || 0 === strpos($path, $root . '/');
        }

        private function build_canonical_local_path_from_relative($root, $relative)
        {
            $root_real = realpath($root);
            if (!is_string($root_real) || '' === $root_real) {
                return '';
            }

            $relative = rawurldecode(str_replace('\\', '/', (string) $relative));
            $relative = ltrim($relative, '/');
            if ('' === $relative) {
                return '';
            }

            foreach (explode('/', $relative) as $segment) {
                if ('' === $segment || '.' === $segment || '..' === $segment) {
                    return '';
                }
            }

            $candidate = trailingslashit($root_real) . $relative;
            $candidate_real = realpath($candidate);
            if (!is_string($candidate_real) || '' === $candidate_real) {
                return '';
            }

            return $this->path_is_within_root($candidate_real, $root_real) ? str_replace('\\', '/', $candidate_real) : '';
        }

        private function resolve_local_path_from_public_url($url)
        {
            $url = $this->normalize_public_resource_url($url);
            $host = (string) wp_parse_url($url, PHP_URL_HOST);
            $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            if ('' === $host || '' === $home_host || strtolower($host) !== strtolower($home_host)) {
                return '';
            }

            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if ('' === $path) {
                return '';
            }

            $content_path = (string) wp_parse_url(content_url('/'), PHP_URL_PATH);
            if ('' !== $content_path && 0 === strpos($path, $content_path)) {
                $relative = ltrim(substr($path, strlen($content_path)), '/');
                return $this->build_canonical_local_path_from_relative(WP_CONTENT_DIR, $relative);
            }

            $site_path = (string) wp_parse_url(site_url('/'), PHP_URL_PATH);
            if ('' !== $site_path && 0 === strpos($path, $site_path)) {
                $relative = ltrim(substr($path, strlen($site_path)), '/');
                return $this->build_canonical_local_path_from_relative(ABSPATH, $relative);
            }

            return $this->build_canonical_local_path_from_relative(ABSPATH, ltrim($path, '/'));
        }

        private function absolutize_public_resource_url($url, $base_url = '')
        {
            $url = trim((string) $url, " \t\n\r\0\x0B\"'");
            if ('' === $url || 0 === strpos($url, 'data:') || 0 === strpos($url, 'about:') || '#' === $url[0]) {
                return $url;
            }

            if (0 === strpos($url, '//')) {
                return $this->normalize_public_resource_url($url);
            }

            if (preg_match('#^[a-z][a-z0-9+\-.]*:#i', $url)) {
                return $url;
            }

            $base = '' !== $base_url ? $this->normalize_public_resource_url($base_url) : home_url('/');
            $base_parts = wp_parse_url($base);
            if (empty($base_parts['host'])) {
                return $url;
            }

            $scheme = !empty($base_parts['scheme']) ? $base_parts['scheme'] : (is_ssl() ? 'https' : 'http');
            $host = $base_parts['host'];
            $port = isset($base_parts['port']) ? ':' . $base_parts['port'] : '';

            if ('/' === $url[0]) {
                return $scheme . '://' . $host . $port . $url;
            }

            $base_path = !empty($base_parts['path']) ? $base_parts['path'] : '/';
            $dir = preg_replace('#/[^/]*$#', '/', $base_path);
            $path = $dir . $url;

            $fragment = '';
            if (false !== strpos($path, '#')) {
                list($path, $fragment) = explode('#', $path, 2);
                $fragment = '#' . $fragment;
            }

            $query = '';
            if (false !== strpos($path, '?')) {
                list($path, $query) = explode('?', $path, 2);
                $query = '?' . $query;
            }

            $segments = array();
            foreach (explode('/', $path) as $segment) {
                if ('' === $segment || '.' === $segment) {
                    continue;
                }
                if ('..' === $segment) {
                    array_pop($segments);
                    continue;
                }
                $segments[] = $segment;
            }

            return $scheme . '://' . $host . $port . '/' . implode('/', $segments) . $query . $fragment;
        }

        private function strip_probable_frontend_authoring_assets($html)
        {
            if (false === stripos($html, '<script') && false === stripos($html, '<link')) {
                return $html;
            }

            foreach (array(
                '/<script\b[^>]*\bsrc=(\"|\')(.*?)\1[^>]*>\s*<\/script>/is',
                '/<link\b[^>]*\bhref=(\"|\')(.*?)\1[^>]*>/is',
            ) as $pattern) {
                $html = (string) preg_replace_callback(
                    $pattern,
                    function ($matches) {
                        $tag = (string) $matches[0];
                        $url = isset($matches[2]) ? (string) $matches[2] : '';
                        if ($this->should_strip_probable_frontend_authoring_asset($url, $tag)) {
                            return '';
                        }
                        return $tag;
                    },
                    $html
                );
            }

            return $html;
        }

        private function should_strip_probable_frontend_authoring_asset($url, $tag_html)
        {
            $url = strtolower($this->normalize_public_resource_url($url));
            $tag_html = strtolower((string) $tag_html);
            if ('' === $url) {
                return false;
            }

            $location_haystack = $url . ' ' . $tag_html;
            if (false === strpos($location_haystack, '/wp-content/plugins/') && false === strpos($location_haystack, '/wp-content/themes/')) {
                return false;
            }

            foreach (array('/admin/', '/wp-admin/', '/shortcode_generator/') as $pattern) {
                if (false !== strpos($url, $pattern)) {
                    return true;
                }
            }

            if ((false !== strpos($location_haystack, 'preview') || false !== strpos($location_haystack, 'backend'))
                && (false !== strpos($location_haystack, '/wp-content/plugins/') || false !== strpos($location_haystack, '/wp-content/themes/'))) {
                return true;
            }

            return false;
        }

        private function get_dynamic_query_args()
        {
            return array(
                'add-to-cart',
                'wc-ajax',
                'remove_item',
                'undo_item',
                'apply_coupon',
                'remove_coupon',
                'order_again',
            );
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

        private function query_contains_excluded_keys($query, array $excluded_query_args)
        {
            if ('' === (string) $query) {
                return false;
            }

            parse_str((string) $query, $query_vars);
            foreach (array_keys($query_vars) as $query_key) {
                if (in_array((string) $query_key, $excluded_query_args, true)) {
                    return true;
                }
            }

            return false;
        }

        private function get_query_allowlist(array $settings = array())
        {
            if (empty($settings)) {
                $settings = $this->get_settings();
            }

            if (empty($settings['cache_query_allowlist']) || !is_array($settings['cache_query_allowlist'])) {
                return array();
            }

            return array_values(array_unique(array_filter(array_map('sanitize_key', $settings['cache_query_allowlist']))));
        }

        private function sort_query_value_for_cache($value)
        {
            if (!is_array($value)) {
                return $value;
            }

            foreach ($value as $key => $child) {
                $value[$key] = $this->sort_query_value_for_cache($child);
            }

            if (array_keys($value) === range(0, count($value) - 1)) {
                usort($value, static function ($a, $b) {
                    return strcmp((string) wp_json_encode($a), (string) wp_json_encode($b));
                });
                return $value;
            }

            ksort($value);
            return $value;
        }

        private function normalize_query_vars_for_cache($query, array $allowlist = array())
        {
            if (is_string($query)) {
                parse_str($query, $query_vars);
            } elseif (is_array($query)) {
                $query_vars = $query;
            } else {
                $query_vars = array();
            }

            if (empty($query_vars) || !is_array($query_vars)) {
                return array();
            }

            $lookup = array();
            foreach ($allowlist as $allowed_key) {
                $allowed_key = sanitize_key((string) $allowed_key);
                if ('' !== $allowed_key) {
                    $lookup[$allowed_key] = true;
                }
            }

            $filtered = array();
            foreach ($query_vars as $query_key => $query_value) {
                $normalized_key = sanitize_key((string) $query_key);
                if ('' === $normalized_key) {
                    continue;
                }
                if (!empty($lookup) && !isset($lookup[$normalized_key])) {
                    continue;
                }

                $filtered[$normalized_key] = $this->sort_query_value_for_cache($query_value);
            }

            if (empty($filtered)) {
                return array();
            }

            ksort($filtered);
            return $filtered;
        }

        private function build_normalized_query_string_for_cache($query, array $allowlist = array())
        {
            $filtered = $this->normalize_query_vars_for_cache($query, $allowlist);
            if (empty($filtered)) {
                return '';
            }

            return http_build_query($filtered, '', '&', PHP_QUERY_RFC3986);
        }

        private function get_first_non_allowlisted_query_key($query, array $allowlist = array())
        {
            if ('' === (string) $query) {
                return '';
            }

            if (is_string($query)) {
                parse_str($query, $query_vars);
            } elseif (is_array($query)) {
                $query_vars = $query;
            } else {
                $query_vars = array();
            }

            if (empty($query_vars) || !is_array($query_vars)) {
                return '';
            }

            $lookup = array();
            foreach ($allowlist as $allowed_key) {
                $allowed_key = sanitize_key((string) $allowed_key);
                if ('' !== $allowed_key) {
                    $lookup[$allowed_key] = true;
                }
            }

            if (empty($lookup)) {
                return '';
            }

            foreach (array_keys($query_vars) as $query_key) {
                $normalized_key = sanitize_key((string) $query_key);
                if ('' === $normalized_key || !isset($lookup[$normalized_key])) {
                    return '' !== $normalized_key ? $normalized_key : (string) $query_key;
                }
            }

            return '';
        }

        private function query_has_cacheable_allowlisted_variant($query, array $allowlist = array())
        {
            if ('' === (string) $query) {
                return false;
            }

            if ('' !== $this->get_first_non_allowlisted_query_key($query, $allowlist)) {
                return false;
            }

            return !empty($this->normalize_query_vars_for_cache($query, $allowlist));
        }

        private function get_matching_path_rule($path, array $rules)
        {
            foreach ($rules as $rule) {
                if ($this->matches_path_rule($path, $rule)) {
                    return (string) $rule;
                }
            }

            return '';
        }

        private function get_matching_query_arg($query, array $candidate_args)
        {
            if ('' === (string) $query) {
                return '';
            }

            parse_str((string) $query, $query_vars);
            foreach (array_keys($query_vars) as $query_key) {
                if (in_array((string) $query_key, $candidate_args, true)) {
                    return (string) $query_key;
                }
            }

            return '';
        }

        private function normalize_inspection_url($url)
        {
            $url = trim((string) $url);
            if ('' === $url) {
                return '';
            }

            if (0 === strpos($url, '//')) {
                $home_parts = wp_parse_url(home_url('/'));
                $scheme = !empty($home_parts['scheme']) ? strtolower((string) $home_parts['scheme']) : 'https';
                return esc_url_raw($scheme . ':' . $url);
            }

            $parts = wp_parse_url($url);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                return esc_url_raw($url);
            }

            if ('/' === substr($url, 0, 1) || '?' === substr($url, 0, 1) || '#' === substr($url, 0, 1)) {
                return esc_url_raw(home_url($url));
            }

            return esc_url_raw(home_url('/' . ltrim($url, '/')));
        }

        private function get_bypass_reason_label($reason)
        {
            $labels = array(
                'cacheable'                 => 'Cacheable',
                'invalid-url'               => 'Invalid URL',
                'disabled'                  => 'Page caching is disabled',
                'non-local-url'             => 'URL is not local to this site',
                'excluded-path'             => 'Excluded by path rule',
                'excluded-query-arg'        => 'Excluded by query-arg rule',
                'query-strings-disabled'    => 'Query strings are not cached',
                'query-arg-not-allowlisted' => 'Query arg is not in the cache allowlist',
                'woocommerce-dynamic-path'  => 'WooCommerce dynamic path bypass',
                'woocommerce-dynamic-query' => 'WooCommerce dynamic query bypass',
                'woocommerce-cart'          => 'WooCommerce cart bypass',
                'woocommerce-checkout'      => 'WooCommerce checkout bypass',
                'woocommerce-account'       => 'WooCommerce account bypass',
                'woocommerce-endpoint'      => 'WooCommerce endpoint bypass',
            );

            return isset($labels[$reason]) ? $labels[$reason] : ucwords(str_replace(array('-', '_'), ' ', (string) $reason));
        }

        private function is_woocommerce_dynamic_request($url = '', array $settings = array())
        {
            if (empty($settings)) {
                $settings = $this->get_settings();
            }

            if (empty($settings['woo_safe_mode'])) {
                return false;
            }

            if (function_exists('is_cart') && is_cart()) {
                $this->last_bypass_reason = 'woocommerce-cart';
                return true;
            }

            if (function_exists('is_checkout') && is_checkout()) {
                $this->last_bypass_reason = 'woocommerce-checkout';
                return true;
            }

            if (function_exists('is_account_page') && is_account_page()) {
                $this->last_bypass_reason = 'woocommerce-account';
                return true;
            }

            if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url()) {
                $this->last_bypass_reason = 'woocommerce-endpoint';
                return true;
            }

            if (empty($url)) {
                $url = $this->get_current_request_url();
            }

            if (empty($url)) {
                return false;
            }

            $parts = wp_parse_url($url);
            $path  = isset($parts['path']) ? $this->normalize_path_value((string) $parts['path']) : '/';
            $query = isset($parts['query']) ? (string) $parts['query'] : '';

            $dynamic_paths = array(
                '/cart/',
                '/checkout/',
                '/my-account/',
                '/order-pay/',
                '/order-received/',
                '/add-payment-method/',
                '/lost-password/',
            );

            if ($this->path_matches_any_rule($path, $dynamic_paths)) {
                $this->last_bypass_reason = 'woocommerce-dynamic-path';
                return true;
            }

            if ($this->query_contains_excluded_keys($query, $this->get_dynamic_query_args())) {
                $this->last_bypass_reason = 'woocommerce-dynamic-query';
                return true;
            }

            return false;
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


        private function get_revalidate_secret()
        {
            return wp_hash('ucwp-revalidate-v1');
        }

        private function is_internal_revalidate_request()
        {
            $request_flag = sanitize_text_field(ucwp_query_value('ucwp_revalidate'));
            $header_flag  = sanitize_text_field(ucwp_server_value('HTTP_X_ULTRACACHE_REVALIDATE'));
            if ('1' !== $request_flag && '1' !== $header_flag) {
                return false;
            }

            $token = sanitize_text_field(ucwp_query_value('ucwp_rt'));
            if ('' === $token) {
                $token = sanitize_text_field(ucwp_server_value('HTTP_X_ULTRACACHE_TOKEN'));
            }

            if ('' === $token) {
                return false;
            }

            return hash_equals($this->get_revalidate_secret(), $token);
        }

        private function get_revalidate_lock_path($url)
        {
            $file = $this->get_cache_path($url, 'orig');
            return $file ? $file . '.revalidate.lock' : '';
        }

        private function clear_revalidate_lock($url)
        {
            $lock = $this->get_revalidate_lock_path($url);
            if ($lock && file_exists($lock)) {
                ucwp_safe_unlink($lock);
            }
        }

        public function should_bypass_cache($url = '')
        {
            $this->last_bypass_reason = '';
            $settings = $this->get_settings();

            if (empty($settings['enabled'])) {
                $this->last_bypass_reason = 'disabled';
                return true;
            }

            if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
                $this->last_bypass_reason = 'donotcachepage';
                return true;
            }

            if (wp_doing_ajax() || wp_doing_cron()) {
                $this->last_bypass_reason = 'ajax-or-cron';
                return true;
            }

            if (function_exists('is_admin') && is_admin()) {
                $this->last_bypass_reason = 'admin';
                return true;
            }

            if (function_exists('is_feed') && is_feed()) {
                $this->last_bypass_reason = 'feed';
                return true;
            }

            if (function_exists('is_preview') && is_preview()) {
                $this->last_bypass_reason = 'preview';
                return true;
            }

            if (function_exists('is_customize_preview') && is_customize_preview()) {
                $this->last_bypass_reason = 'customize-preview';
                return true;
            }

            $request_method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
            if (!in_array($request_method, array('GET', 'HEAD'), true)) {
                $this->last_bypass_reason = 'request-method';
                return true;
            }

            if ($this->is_internal_revalidate_request()) {
                return false;
            }

            if ($this->is_woocommerce_dynamic_request($url, $settings)) {
                return true;
            }

            if (function_exists('is_user_logged_in') && is_user_logged_in() && empty($settings['cache_logged_in_users'])) {
                $this->last_bypass_reason = 'logged-in-user';
                return true;
            }

            $cookies_to_bypass = array(
                'wordpress_logged_in_',
                'comment_author_',
                'wp-postpass_',
                'woocommerce_items_in_cart',
                'woocommerce_cart_hash',
                'wp_woocommerce_session_',
            );

            foreach ((array) $_COOKIE as $cookie_name => $cookie_value) {
                foreach ($cookies_to_bypass as $needle) {
                    if (false !== strpos((string) $cookie_name, $needle)) {
                        $this->last_bypass_reason = 'cookie-' . $needle;
                        return true;
                    }
                }
            }

            if (empty($url)) {
                $url = $this->get_current_request_url();
            }

            if (empty($url) || !$this->is_cacheable_local_url($url)) {
                $this->last_bypass_reason = 'non-local-url';
                return true;
            }

            $parts = wp_parse_url($url);
            $path = isset($parts['path']) ? $this->normalize_path_value((string) $parts['path']) : '/';
            $query = isset($parts['query']) ? (string) $parts['query'] : '';

            $excluded_paths = !empty($settings['excluded_paths']) && is_array($settings['excluded_paths']) ? $settings['excluded_paths'] : array();
            if ($this->path_matches_any_rule($path, $excluded_paths)) {
                $this->last_bypass_reason = 'excluded-path';
                return true;
            }

            $excluded_query_args = !empty($settings['excluded_query_args']) && is_array($settings['excluded_query_args']) ? $settings['excluded_query_args'] : array();
            if ('' !== $query) {
                if ($this->query_contains_excluded_keys($query, $excluded_query_args)) {
                    $this->last_bypass_reason = 'excluded-query-arg';
                    return true;
                }

                $query_allowlist = $this->get_query_allowlist($settings);
                if (empty($settings['cache_query_strings'])) {
                    $this->last_bypass_reason = 'query-strings-disabled';
                    return true;
                }

                if (!empty($query_allowlist) && !$this->query_has_cacheable_allowlisted_variant($query, $query_allowlist)) {
                    $this->last_bypass_reason = 'query-arg-not-allowlisted';
                    return true;
                }
            }

            return false;
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


        private function should_verify_loopback_ssl($url)
        {
            return !function_exists('ucwp_is_local_https_url') || !ucwp_is_local_https_url($url);
        }

        public function warm_url($url, array $args = array())
        {
            $args = is_array($args) ? $args : array();
            $ignore_runtime_bypass = !empty($args['ignore_runtime_bypass']);
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
                $result = array(
                    'success' => false,
                    'cached'  => false,
                    'url'     => $url,
                    'message' => 'URL is configured to bypass cache: ' . $this->last_bypass_reason,
                    'files'   => array(),
                );
                $this->record_analytics_warm($url, $result);
                return $result;
            }

            $requested_buckets = isset($args['buckets']) && is_array($args['buckets']) ? $args['buckets'] : array('orig', 'webp', 'avif');
            $buckets = array_values(array_unique(array_intersect(array('orig', 'webp', 'avif'), array_map('strval', $requested_buckets))));
            if (empty($buckets)) {
                $buckets = array('orig', 'webp', 'avif');
            }

            $cached_files = array();
            $last_error = '';

            foreach ($buckets as $bucket) {
                $response = ucwp_safe_loopback_remote_request(
                    $url,
                    array(
                        'method'      => 'GET',
                        'timeout'     => 20,
                        'redirection' => 5,
                        'sslverify'   => $this->should_verify_loopback_ssl($url),
                        'user-agent'  => 'Mozilla/5.0 (compatible; UltraCache-Warm/' . UCWP_VERSION . '; +https://wordpress.org)',
                        'headers'     => array_filter(
                            array(
                                'Accept'             => $this->get_accept_header_for_bucket($bucket),
                                'X-UltraCache-Warm'  => '1',
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

            if ($success && !empty($args['build_css_bundle'])) {
                $settings = $this->get_settings();
                $bundle_scope = $this->get_css_bundle_scope($settings);
                $bundle_result = array('success' => false, 'skipped' => true, 'message' => 'CSS bundle skipped for this URL by the selected CSS Bundling Scope.');
                if ('per-page' === $bundle_scope || $this->is_frontpage_request_url($url)) {
                    $bundle_result = $this->build_frontpage_css_bundle($url);
                }
                $result['cssBundle'] = is_array($bundle_result) ? $bundle_result : array();
                if (!empty($bundle_result['success'])) {
                    $result['message'] .= ' CSS bundle built.';
                } elseif (!empty($bundle_result['message'])) {
                    $result['message'] .= ' CSS bundle skipped: ' . (string) $bundle_result['message'];
                }
            }

            $this->record_analytics_warm($url, $result);

            return $result;
        }

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

        private function get_default_frontpage_css_stats()
        {
            return array(
                'scanned' => 0,
                'bundled' => 0,
                'skipped' => 0,
                'unresolved' => 0,
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

            return $decoded;
        }

        private function write_frontpage_css_manifest(array $manifest)
        {
            $dir = $this->get_frontpage_css_dir();
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            ucwp_safe_file_put_contents($this->get_frontpage_css_manifest_file(), wp_json_encode($manifest), LOCK_EX);
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

            if (empty($entry['bundleFile']) || !file_exists((string) $entry['bundleFile']) || !is_readable((string) $entry['bundleFile'])) {
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
                $entry = ('' !== $key && isset($manifest['entries'][$key]) && is_array($manifest['entries'][$key])) ? $manifest['entries'][$key] : array();
                if (empty($entry) && $this->is_frontpage_request_url($url) && isset($manifest['entry']) && is_array($manifest['entry'])) {
                    $entry = $manifest['entry'];
                }
                if (!empty($entry['bundleFile']) && file_exists((string) $entry['bundleFile'])) {
                    ucwp_safe_unlink((string) $entry['bundleFile']);
                }
                if ('' !== $key && isset($manifest['entries'][$key])) {
                    unset($manifest['entries'][$key]);
                }
                if ($this->is_frontpage_request_url($url)) {
                    $manifest['entry'] = array();
                }
                $this->write_frontpage_css_manifest($manifest);
                return;
            }

            foreach ((array) ($manifest['entries'] ?? array()) as $entry) {
                if (is_array($entry) && !empty($entry['bundleFile']) && file_exists((string) $entry['bundleFile'])) {
                    ucwp_safe_unlink((string) $entry['bundleFile']);
                }
            }

            $legacy_entry = isset($manifest['entry']) && is_array($manifest['entry']) ? $manifest['entry'] : array();
            if (!empty($legacy_entry['bundleFile']) && file_exists((string) $legacy_entry['bundleFile'])) {
                ucwp_safe_unlink((string) $legacy_entry['bundleFile']);
            }

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

        public function build_frontpage_css_bundle($url = '')
        {
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

            $previous = $this->get_frontpage_css_manifest_entry($frontpage_url);
            $manifest = $this->read_frontpage_css_manifest();
            $manifest['version'] = 2;
            $manifest['updatedAt'] = current_time('timestamp');
            $manifest['updatedAtMysql'] = current_time('mysql');
            if (!isset($manifest['entries']) || !is_array($manifest['entries'])) {
                $manifest['entries'] = array();
            }
            $entry = array(
                'normalizedUrl' => $this->normalize_url($frontpage_url),
                'bundleFile' => (string) $prepared['bundleFile'],
                'bundleUrl' => (string) $prepared['bundleUrl'],
                'sourceUrls' => array_values(array_unique(array_map('strval', (array) ($prepared['sourceUrls'] ?? array())))),
                'sourceCount' => count((array) ($prepared['sourceUrls'] ?? array())),
                'bundleCount' => 1,
                'mode' => (string) ($prepared['mode'] ?? 'safe'),
                'time' => current_time('timestamp'),
                'time_mysql' => current_time('mysql'),
            );
            $key = $this->get_css_bundle_manifest_key($frontpage_url);
            if ('' !== $key) {
                $manifest['entries'][$key] = $entry;
            }
            if ($this->is_frontpage_request_url($frontpage_url)) {
                $manifest['entry'] = $entry;
            }
            $this->write_frontpage_css_manifest($manifest);

            if (!empty($previous['bundleFile']) && (string) $previous['bundleFile'] !== (string) $prepared['bundleFile'] && file_exists((string) $previous['bundleFile'])) {
                ucwp_safe_unlink((string) $previous['bundleFile']);
            }

            $warm_result = $this->warm_url($frontpage_url);
            $result['success'] = true;
            $result['bundleCount'] = 1;
            $result['bundleFile'] = (string) $prepared['bundleFile'];
            $result['bundleUrl'] = (string) $prepared['bundleUrl'];
            $result['sourceUrls'] = array_values(array_unique(array_map('strval', (array) ($prepared['sourceUrls'] ?? array()))));
            $result['warmResult'] = is_array($warm_result) ? $warm_result : array();
            $result['message'] = 'Built 1 CSS bundle from ' . max(0, (int) ($result['stats']['bundled'] ?? 0)) . ' stylesheet(s).' . (!empty($warm_result['success']) ? ' Page cache warmed.' : ' Page cache warm returned: ' . (!empty($warm_result['message']) ? (string) $warm_result['message'] : 'unknown result'));
            $this->record_cache_event('page-css-bundle-build', array(
                'url' => $frontpage_url,
                'bundleFile' => $result['bundleFile'],
                'sourceCount' => count($result['sourceUrls']),
            ));
            $this->record_analytics_frontpage_css_warm($result);

            return $result;
        }

        private function fetch_frontpage_css_source_html($url)
        {
            $scan_url = add_query_arg(
                array(
                    'ucwp_frontpage_css_scan' => 1,
                    'ucwp_css_v' => rawurlencode(UCWP_VERSION . '-' . UCWP_HOTFIX_BUNDLE_VERSION),
                ),
                $url
            );

            $response = ucwp_safe_loopback_remote_request(
                $scan_url,
                array(
                    'method' => 'GET',
                    'timeout' => 25,
                    'redirection' => 5,
                    'sslverify' => $this->should_verify_loopback_ssl($scan_url),
                    'user-agent' => 'Mozilla/5.0 (compatible; UltraCache-CSSBundle/' . UCWP_VERSION . '; +https://wordpress.org)',
                    'headers' => array(
                        'Cache-Control' => 'no-cache',
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
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

            return array('success' => true, 'message' => '', 'html' => $html);
        }

        private function build_frontpage_css_bundle_from_html($html, $page_url, $mode = '')
        {
            $settings = $this->get_settings();
            $mode = in_array((string) $mode, array('safe', 'aggressive'), true) ? (string) $mode : (string) ($settings['homepage_css_bundle_mode'] ?? 'safe');
            $mode = in_array($mode, array('safe', 'aggressive'), true) ? $mode : 'safe';
            $stats = $this->get_default_frontpage_css_stats();
            $html = $this->normalize_protocol_relative_urls_in_html((string) $html);
            if ('' === $html || false === stripos($html, '<head') || false === stripos($html, '<link')) {
                return array('success' => false, 'skipped' => true, 'message' => 'No stylesheet links were found on the page.', 'stats' => $stats);
            }

            if (!preg_match('/<head\b[^>]*>([\s\S]*?)<\/head>/i', $html, $matches)) {
                return array('success' => false, 'skipped' => true, 'message' => 'No <head> element was found on the page.', 'stats' => $stats);
            }

            $head_inner = isset($matches[1]) ? (string) $matches[1] : '';
            if (!preg_match_all('/<link\b[^>]*>/i', $head_inner, $tag_matches)) {
                return array('success' => false, 'skipped' => true, 'message' => 'No <link> tags were found on the page.', 'stats' => $stats);
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
                return array('success' => false, 'skipped' => true, 'message' => 'Not enough eligible local stylesheets were found for CSS bundling.', 'stats' => $stats);
            }

            $bundle = $this->build_frontpage_css_bundle_file($page_url, $assets, $mode);
            if (!empty($bundle['stats']) && is_array($bundle['stats'])) {
                $stats['bundled'] += max(0, (int) ($bundle['stats']['bundled'] ?? 0));
                $stats['skipped'] += max(0, (int) ($bundle['stats']['skipped'] ?? 0));
                $stats['unresolved'] += max(0, (int) ($bundle['stats']['unresolved'] ?? 0));
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
                'stats' => $stats,
            );
        }

        private function is_homepage_css_bundle_allowed_media($media, $mode = 'safe')
        {
            $media = strtolower(trim((string) $media));
            if ('' === $media || 'all' === $media) {
                return true;
            }

            if ('aggressive' !== strtolower((string) $mode)) {
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
            );
        }

        private function build_frontpage_css_bundle_file($page_url, array $assets, $mode = 'safe')
        {
            $dir = $this->get_frontpage_css_dir();
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }

            $page_hash = md5($this->normalize_url($page_url));
            $signature_parts = array();
            $bundle_body = '';
            $used_urls = array();
            $stats = array(
                'bundled' => 0,
                'skipped' => 0,
                'unresolved' => 0,
            );

            foreach ($assets as $asset) {
                $path = (string) ($asset['path'] ?? '');
                $url = (string) ($asset['url'] ?? '');
                if ('' === $path || '' === $url || !is_readable($path)) {
                    return array(
                        'success' => false,
                        'skipped' => false,
                        'message' => 'A stylesheet could not be read.',
                        'stats' => $stats,
                    );
                }

                $css = ucwp_safe_file_get_contents($path);
                if (!is_string($css) || '' === $css) {
                    $stats['skipped']++;
                    continue;
                }

                $signature_parts[] = $url . '|' . (string) ucwp_safe_filemtime($path, 'frontpage_css_bundle_signature') . '|' . strlen($css);
                $bundle_body .= "
/* UltraCache CSS Bundle Source: " . $url . " */
";
                $bundle_body .= $this->rewrite_frontpage_css_urls_for_bundle($css, $url) . "
";
                $used_urls[] = $url;
                $stats['bundled']++;
            }

            if ($stats['bundled'] < 2 || '' === trim($bundle_body)) {
                return array(
                    'success' => false,
                    'skipped' => true,
                    'message' => 'Not enough non-empty stylesheets were eligible for bundling.',
                    'stats' => $stats,
                    'sourceUrls' => array_values(array_unique(array_map('strval', $used_urls))),
                );
            }

            $signature = md5($page_hash . '|' . implode('||', $signature_parts));
            $mode = in_array((string) $mode, array('safe', 'aggressive'), true) ? (string) $mode : 'safe';
            $filename = 'page-' . $mode . '-' . $page_hash . '-' . $signature . '.css';
            $file = $dir . $filename;
            ucwp_safe_file_put_contents($file, trim($bundle_body) . "
", LOCK_EX);

            $message = 'Prepared ' . $mode . ' CSS bundle.';
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
                'sourceUrls' => array_values(array_unique(array_map('strval', $used_urls))),
            );
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

                return 'url("' . esc_url_raw($absolute) . '")';
            }, $css);

            return $this->normalize_font_face_display_in_css($css);
        }

        private function maybe_build_page_css_bundle_on_entry($html, array $settings = array())
        {
            $url = $this->get_current_request_url();
            if ('' === $url || !$this->is_cacheable_local_url($url)) {
                return false;
            }

            if (!empty($_SERVER['REQUEST_METHOD']) && 'GET' !== strtoupper((string) $_SERVER['REQUEST_METHOD'])) {
                return false;
            }

            if (function_exists('is_user_logged_in') && is_user_logged_in()) {
                return false;
            }

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

            $entry = array(
                'normalizedUrl' => $this->normalize_url($url),
                'bundleFile' => (string) $prepared['bundleFile'],
                'bundleUrl' => (string) $prepared['bundleUrl'],
                'sourceUrls' => array_values(array_unique(array_map('strval', (array) ($prepared['sourceUrls'] ?? array())))),
                'sourceCount' => count((array) ($prepared['sourceUrls'] ?? array())),
                'bundleCount' => 1,
                'mode' => (string) ($prepared['mode'] ?? 'safe'),
                'time' => current_time('timestamp'),
                'time_mysql' => current_time('mysql'),
            );

            $key = $this->get_css_bundle_manifest_key($url);
            if ('' !== $key) {
                $manifest['version'] = 2;
                $manifest['updatedAt'] = current_time('timestamp');
                $manifest['updatedAtMysql'] = current_time('mysql');
                $manifest['entries'][$key] = $entry;
                if ($this->is_frontpage_request_url($url)) {
                    $manifest['entry'] = $entry;
                }
                $this->write_frontpage_css_manifest($manifest);
                return true;
            }

            return false;
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

            $replacement = '<link rel="stylesheet" id="ucwp-frontpage-css" href="' . esc_url($bundle_url) . '" data-ucwp-frontpage-css="1" />'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
            if (!empty($settings['homepage_css_bundle_inline']) || !empty($settings['critical_css_inline'])) {
                $bundle_css = ('' !== $bundle_file && is_readable($bundle_file)) ? ucwp_safe_file_get_contents($bundle_file) : '';
                $bundle_css = $this->prepare_inline_css_bundle_for_style_tag($bundle_css);
                if ('' !== $bundle_css) {
                    $replacement = '<style id="ucwp-frontpage-css" data-ucwp-frontpage-css="1">' . $bundle_css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
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
            $replacement = '<link rel="stylesheet" id="ucwp-page-css-bundle" href="' . esc_url($bundle_url) . '" data-ucwp-page-css-bundle="1" />'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
            if (!empty($settings['homepage_css_bundle_inline']) || !empty($settings['critical_css_inline'])) {
                $maybe_css = ucwp_safe_file_get_contents($bundle_file);
                $bundle_css = $this->prepare_inline_css_bundle_for_style_tag($maybe_css);
                if ('' !== $bundle_css) {
                    $replacement = '<style id="ucwp-page-css-bundle" data-ucwp-page-css-bundle="1">' . $bundle_css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
            }

            $mode = isset($entry['mode']) && 'aggressive' === strtolower((string) $entry['mode']) ? 'aggressive' : 'safe';

            // Stage 1 is intentionally non-destructive: inject the bundle before the first matching
            // stylesheet but keep the original stylesheets as the authoritative fallback. This avoids
            // blank/unstyled frontends if a generated bundle is incomplete or blocked.
            if ('aggressive' !== $mode) {
                $insert_at = (int) $matched_tags[0]['start'];
                $rebuilt_head = substr($head_inner, 0, $insert_at) . $replacement . "\n" . substr($head_inner, $insert_at);
                return substr($html, 0, $head_inner_offset) . $rebuilt_head . substr($html, $head_inner_offset + strlen($head_inner));
            }

            $fallback_links = array();
            foreach ($matched_tags as $tag) {
                $url = (string) $tag['url'];
                // Stage 2 is intentionally more aggressive: do not preload every original
                // bundled stylesheet, otherwise the browser still downloads the whole chain.
                // Keep noscript fallbacks only; slider/hero stylesheets are excluded from the
                // bundle earlier and therefore remain as normal stylesheet links.
                $fallback_links[$url] = '<noscript><link rel="stylesheet" href="' . esc_url($url) . '" data-ucwp-page-css-bundle-fallback="1" /></noscript>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
            }

            $rebuilt_head = '';
            $cursor = 0;
            $inserted = false;
            foreach ($matched_tags as $tag) {
                $start = (int) $tag['start'];
                $end = (int) $tag['end'];
                $rebuilt_head .= substr($head_inner, $cursor, $start - $cursor);
                if (!$inserted) {
                    $rebuilt_head .= $replacement . "\n" . implode("\n", array_values($fallback_links)) . "\n";
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

        public function purge_all()
        {
            $this->recursive_delete(UCWP_CACHE_DIR);
            self::ensure_cache_directories();
            $this->delete_frontpage_css_bundle();
            $this->invalidate_dashboard_cache_activity_snapshot();

            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache')) {
                Ultra_Cache_Object_Cache_Manager::flush_cache();
            }

            $this->record_cache_event('purge-all');
            $this->record_analytics_purge('all');
            do_action('ucwp_after_purge_all', array('scope' => 'all'));
            return true;
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

        private function delete_cache_variants($file)
        {
            foreach (array($file, $file . '.gz', $file . '.br') as $variant) {
                if (file_exists($variant)) {
                    ucwp_safe_unlink($variant);
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
            $base_dir = trailingslashit(UCWP_CACHE_DIR) . $host . '/' . $path;

            return trailingslashit($base_dir) . 'index-' . $bucket . '-' . $hash . '.html';
        }

        private function get_cache_paths_for_all_buckets($url)
        {
            $files = array();
            foreach (array('orig', 'webp', 'avif') as $bucket) {
                $file = $this->get_cache_path($url, $bucket);
                if ($file) {
                    $files[] = $file;
                }
            }

            return array_values(array_unique($files));
        }


        private function invalidate_dashboard_cache_activity_snapshot()
        {
            delete_transient('ucwp_dashboard_cache_activity_v1');
        }

        private function get_request_image_bucket($accept_header = null)
        {
            if (null === $accept_header) {
                $accept_header = ucwp_server_value('HTTP_ACCEPT');
            }

            $accept_header = strtolower((string) $accept_header);
            if (false !== strpos($accept_header, 'image/avif')) {
                return 'avif';
            }

            if (false !== strpos($accept_header, 'image/webp')) {
                return 'webp';
            }

            return 'orig';
        }

        private function normalize_url($url)
        {
            $url = trim((string) $url);
            if ('' === $url) {
                return '';
            }

            $parts = wp_parse_url($url);
            if (empty($parts['host'])) {
                return '';
            }

            $settings = $this->get_settings();
            $allowlist = $this->get_query_allowlist($settings);

            $scheme = !empty($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'http';
            $host = strtolower((string) $parts['host']);
            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
            $path = isset($parts['path']) ? '/' . ltrim((string) $parts['path'], '/') : '/';
            $query = '';

            if (!empty($parts['query']) && !empty($settings['cache_query_strings'])) {
                $query = $this->build_normalized_query_string_for_cache((string) $parts['query'], $allowlist);
            }

            return $scheme . '://' . $host . $port . $path . ($query ? '?' . $query : '');
        }

        public function is_cacheable_local_url($url)
        {
            $url = trim((string) $url);
            if ('' === $url) {
                return false;
            }

            $parts = wp_parse_url($url);
            $home_parts = wp_parse_url(home_url('/'));
            if (empty($parts['scheme']) || empty($parts['host']) || empty($home_parts['host'])) {
                return false;
            }

            if (!in_array(strtolower((string) $parts['scheme']), array('http', 'https'), true)) {
                return false;
            }

            return strtolower((string) $parts['host']) === strtolower((string) $home_parts['host']);
        }

        public function inspect_url($url)
        {
            $input_url = trim((string) $url);
            $absolute_url = $this->normalize_inspection_url($input_url);
            $settings = $this->get_settings();
            $decision_parts = '' !== $absolute_url ? wp_parse_url($absolute_url) : array();
            $path = isset($decision_parts['path']) ? $this->normalize_path_value((string) $decision_parts['path']) : '/';
            $query = isset($decision_parts['query']) ? (string) $decision_parts['query'] : '';
            $normalized_url = '' !== $absolute_url ? $this->normalize_url($absolute_url) : '';
            $parts = !empty($decision_parts) && is_array($decision_parts) ? $decision_parts : array();
            $query_vars = array();
            if ('' !== $query) {
                parse_str($query, $query_vars);
            }

            $excluded_paths = !empty($settings['excluded_paths']) && is_array($settings['excluded_paths']) ? $settings['excluded_paths'] : array();
            $excluded_query_args = !empty($settings['excluded_query_args']) && is_array($settings['excluded_query_args']) ? $settings['excluded_query_args'] : array();
            $dynamic_paths = array(
                '/cart/',
                '/checkout/',
                '/my-account/',
                '/order-pay/',
                '/order-received/',
                '/add-payment-method/',
                '/lost-password/',
            );
            $dynamic_query_args = $this->get_dynamic_query_args();

            $matched_excluded_path_rule = '' !== $normalized_url ? $this->get_matching_path_rule($path, $excluded_paths) : '';
            $matched_excluded_query_arg = '' !== $query ? $this->get_matching_query_arg($query, $excluded_query_args) : '';
            $query_allowlist = $this->get_query_allowlist($settings);
            $matched_non_allowlisted_query_arg = '' !== $query ? $this->get_first_non_allowlisted_query_key($query, $query_allowlist) : '';
            $matched_woo_path_rule = '' !== $absolute_url ? $this->get_matching_path_rule($path, $dynamic_paths) : '';
            $matched_woo_query_arg = '' !== $query ? $this->get_matching_query_arg($query, $dynamic_query_args) : '';

            $reason = 'cacheable';
            $matched_woo_rule = '';
            $matched_woo_rule_type = '';

            if ('' === $input_url || '' === $absolute_url) {
                $reason = 'invalid-url';
            } elseif (empty($settings['enabled'])) {
                $reason = 'disabled';
            } elseif (!$this->is_cacheable_local_url($absolute_url)) {
                $reason = 'non-local-url';
            } elseif (!empty($settings['woo_safe_mode']) && '' !== $matched_woo_path_rule) {
                $reason = 'woocommerce-dynamic-path';
                $matched_woo_rule = $matched_woo_path_rule;
                $matched_woo_rule_type = 'path';
            } elseif (!empty($settings['woo_safe_mode']) && '' !== $matched_woo_query_arg) {
                $reason = 'woocommerce-dynamic-query';
                $matched_woo_rule = $matched_woo_query_arg;
                $matched_woo_rule_type = 'query';
            } elseif ('' !== $matched_excluded_path_rule) {
                $reason = 'excluded-path';
            } elseif ('' !== $matched_excluded_query_arg) {
                $reason = 'excluded-query-arg';
            } elseif ('' !== $query && empty($settings['cache_query_strings'])) {
                $reason = 'query-strings-disabled';
            } elseif ('' !== $query && !empty($query_allowlist) && !$this->query_has_cacheable_allowlisted_variant($query, $query_allowlist)) {
                $reason = 'query-arg-not-allowlisted';
            }

            $cacheable = 'cacheable' === $reason;
            $cache_paths = array();
            if ($cacheable && '' !== $normalized_url) {
                $cache_paths = array(
                    'orig' => $this->get_cache_path($normalized_url, 'orig'),
                    'webp' => $this->get_cache_path($normalized_url, 'webp'),
                    'avif' => $this->get_cache_path($normalized_url, 'avif'),
                );
            }

            return array(
                'success'                 => true,
                'inputUrl'                => $input_url,
                'url'                     => $absolute_url,
                'normalizedUrl'           => $normalized_url,
                'cacheable'               => $cacheable,
                'reason'                  => $reason,
                'reasonLabel'             => $this->get_bypass_reason_label($reason),
                'local'                   => '' !== $absolute_url ? $this->is_cacheable_local_url($absolute_url) : false,
                'host'                    => isset($parts['host']) ? (string) $parts['host'] : '',
                'path'                    => isset($parts['path']) ? (string) $parts['path'] : '',
                'normalizedPath'          => $path,
                'query'                   => $query,
                'queryArgKeys'            => array_values(array_map('strval', array_keys($query_vars))),
                'matchedExcludedPathRule' => $matched_excluded_path_rule,
                'matchedExcludedQueryArg' => $matched_excluded_query_arg,
                'matchedNonAllowlistedQueryArg' => $matched_non_allowlisted_query_arg,
                'matchedWooRule'          => $matched_woo_rule,
                'matchedWooRuleType'      => $matched_woo_rule_type,
                'pageCacheEnabled'        => !empty($settings['enabled']),
                'wooSafeModeEnabled'      => !empty($settings['woo_safe_mode']),
                'cacheQueryStrings'       => !empty($settings['cache_query_strings']),
                'cachePaths'              => $cache_paths,
                'simulationNote'          => 'Inspection simulates an anonymous frontend request. Admin login state and browser cookies are ignored.',
            );
        }

        private function get_current_request_url()
        {
            if (empty($_SERVER['HTTP_HOST']) || empty($_SERVER['REQUEST_URI'])) {
                return '';
            }

            $is_ssl = ucwp_server_flag_enabled('HTTPS')
                || ('443' === ucwp_server_value('SERVER_PORT'));
            $scheme = $is_ssl ? 'https://' : 'http://';
            $host = ucwp_get_validated_http_host(ucwp_server_value('HTTP_HOST'), 'engine_current_request_url');
            if ('' === $host) {
                return '';
            }

            $uri = ucwp_server_value('REQUEST_URI');
            $url = $scheme . $host . $uri;
            $parts = wp_parse_url($url);
            if (!is_array($parts) || empty($parts['host'])) {
                return esc_url_raw($url);
            }

            $path = isset($parts['path']) ? '/' . ltrim((string) $parts['path'], '/') : '/';
            $query = '';
            if (!empty($parts['query'])) {
                parse_str((string) $parts['query'], $query_vars);
                unset($query_vars['ucwp_revalidate'], $query_vars['ucwp_rt']);
                if (!empty($query_vars)) {
                    ksort($query_vars);
                    $query = http_build_query($query_vars);
                }
            }

            return esc_url_raw($scheme . $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . $path . ($query ? '?' . $query : ''));
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

        public static function get_stats()
        {
            $count = 0;
            $size = 0;

            if (is_dir(UCWP_CACHE_DIR)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(UCWP_CACHE_DIR, FilesystemIterator::SKIP_DOTS)
                );

                foreach ($iterator as $item) {
                    if ($item->isFile()) {
                        $size += (int) $item->getSize();
                        if ('html' === strtolower($item->getExtension())) {
                            $count++;
                        }
                    }
                }
            }

            return array_merge(
                array(
                    'pageCacheFiles' => $count,
                    'cacheSizeBytes' => $size,
                    'cacheSizeHuman' => function_exists('size_format') ? size_format($size, 2) : (string) $size,
                ),
                self::get_analytics_stats()
            );
        }


        private function should_bypass_preload_url($url, array $args = array())
        {
            $this->last_bypass_reason = '';
            $settings = $this->get_settings();
            $ignore_runtime_bypass = !empty($args['ignore_runtime_bypass']);

            if (empty($settings['enabled'])) {
                $this->last_bypass_reason = 'disabled';
                return true;
            }

            if (!$ignore_runtime_bypass && defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
                $this->last_bypass_reason = 'donotcachepage';
                return true;
            }

            if ($this->is_woocommerce_dynamic_request($url, $settings)) {
                return true;
            }

            if (empty($url) || !$this->is_cacheable_local_url($url)) {
                $this->last_bypass_reason = 'non-local-url';
                return true;
            }

            $parts = wp_parse_url($url);
            $path = isset($parts['path']) ? $this->normalize_path_value((string) $parts['path']) : '/';
            $query = isset($parts['query']) ? (string) $parts['query'] : '';

            $excluded_paths = !empty($settings['excluded_paths']) && is_array($settings['excluded_paths']) ? $settings['excluded_paths'] : array();
            if ($this->path_matches_any_rule($path, $excluded_paths)) {
                $this->last_bypass_reason = 'excluded-path';
                return true;
            }

            $excluded_query_args = !empty($settings['excluded_query_args']) && is_array($settings['excluded_query_args']) ? $settings['excluded_query_args'] : array();
            if ('' !== $query) {
                if ($this->query_contains_excluded_keys($query, $excluded_query_args)) {
                    $this->last_bypass_reason = 'excluded-query-arg';
                    return true;
                }

                $query_allowlist = $this->get_query_allowlist($settings);
                if (empty($settings['cache_query_strings'])) {
                    $this->last_bypass_reason = 'query-strings-disabled';
                    return true;
                }

                if (!empty($query_allowlist) && !$this->query_has_cacheable_allowlisted_variant($query, $query_allowlist)) {
                    $this->last_bypass_reason = 'query-arg-not-allowlisted';
                    return true;
                }
            }

            return false;
        }

        private function recursive_delete($dir)
        {
            if (!is_dir($dir)) {
                return;
            }

            $items = function_exists('ucwp_safe_scandir') ? ucwp_safe_scandir($dir, 'page_cache_recursive_delete scandir') : scandir($dir);
            if (!is_array($items)) {
                return;
            }

            foreach ($items as $item) {
                if ('.' === $item || '..' === $item) {
                    continue;
                }

                $path = $dir . DIRECTORY_SEPARATOR . $item;
                if (is_dir($path) && !is_link($path)) {
                    $this->recursive_delete($path);
                } else {
                    ucwp_safe_unlink($path);
                }
            }

            ucwp_safe_rmdir($dir);
        }

        private function record_cache_event($type, array $payload = array())
        {
            $bucket = '';
            if (!empty($payload['bucket'])) {
                $bucket = (string) $payload['bucket'];
            } elseif (!empty($payload['file'])) {
                $bucket = $this->infer_bucket_from_cache_path($payload['file']);
            } elseif (!empty($payload['files']) && is_array($payload['files'])) {
                foreach ($payload['files'] as $file) {
                    $bucket = $this->infer_bucket_from_cache_path($file);
                    if ('' !== $bucket) {
                        break;
                    }
                }
            }

            $event = array_merge(
                array(
                    'type'      => (string) $type,
                    'status'    => (string) $type,
                    'time'      => current_time('timestamp'),
                    'time_mysql'=> current_time('mysql'),
                    'bucket'    => $bucket,
                    'reason'    => isset($payload['reason']) ? (string) $payload['reason'] : '',
                    'payload'   => $payload,
                ),
                $payload
            );

            set_transient('ucwp_last_cache_event', $event, DAY_IN_SECONDS);
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

if (!class_exists('UltraCache_V246_Engine') && class_exists('Ultra_Cache_Engine')) {
    class_alias('Ultra_Cache_Engine', 'UltraCache_V246_Engine');
}
