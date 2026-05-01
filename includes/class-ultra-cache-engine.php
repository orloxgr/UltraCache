<?php
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

        /** @var array<string, resource> */
        private $runtime_locks = array();

        /** @var bool */
        private $google_fonts_async_pending = false;

        /** @var bool */
        private $google_fonts_sync_build_mode = false;

        /** @var array<string, string>|null */
        private $runtime_font_css_url_map = null;

        /** @var array<string, string> */
        private $runtime_font_css_url_map_current_request = array();

        /** @var array<string, array<string, mixed>> */
        private $delayed_font_css_assets_current_request = array();

        /** @var array<string, array<string, mixed>> */
        private $cls_dimension_resolution_cache_current_request = array();


        /** @var string */
        private $page_cache_generation_lock_name = '';

        /** @var bool|null */
        private $store_profile_enabled = null;

        /** @var array<string, mixed> */
        private $store_profile = array();

        /** @var float */
        private $store_profile_started_at = 0.0;

        /** @var array<int, array<string, mixed>> */
        private $store_profile_request_checkpoints = array();

        /** @var float */
        private $store_profile_last_checkpoint_at = 0.0;

        /**  bool */
        private $store_profile_shutdown_written = false;

        /** @var array<int, array<string, mixed>> */
        private $deferred_store_post_response_actions = array();

        public static function get_instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        private function __construct()
        {
            $this->profile_request_checkpoint('engine_construct');
            $this->register_hooks();
        }

        private function register_hooks()
        {
            add_action('init', array($this, 'profile_init_checkpoint'), 0);
            add_action('wp_loaded', array($this, 'profile_wp_loaded_checkpoint'), 0);
            add_action('template_redirect', array($this, 'profile_template_redirect_checkpoint'), -1000);
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
            add_action('wp_enqueue_scripts', array($this, 'profile_wp_enqueue_scripts_start_checkpoint'), -1000);
            add_action('wp_enqueue_scripts', array($this, 'profile_wp_enqueue_scripts_end_checkpoint'), PHP_INT_MAX);
            add_action('wp_enqueue_scripts', array($this, 'cleanup_asset_chain_enqueue_assets'), 9999);
            add_action('shutdown', array($this, 'run_deferred_store_post_response_actions'), PHP_INT_MAX - 2);
            add_action('shutdown', array($this, 'update_store_profile_after_shutdown'), PHP_INT_MAX);
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

        private static function get_analytics_redis_prefix()
        {
            $settings = defined('UCWP_SETTINGS_KEY') ? get_option(UCWP_SETTINGS_KEY, array()) : array();
            $prefix = is_array($settings) && isset($settings['redisPrefix']) ? preg_replace('/[^A-Za-z0-9:_\-]/', '', (string) $settings['redisPrefix']) : '';
            $prefix = trim((string) $prefix, ':');
            if ('' !== $prefix) {
                $prefix .= ':';
            } else {
                $seed = implode('|', array(
                    defined('DB_NAME') ? DB_NAME : '',
                    defined('ABSPATH') ? ABSPATH : '',
                    defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '',
                ));
                $prefix = 'ucwp:' . substr((string) (function_exists('hash') ? hash('sha256', 'ucwp-redis|' . $seed) : md5('ucwp-redis|' . $seed)), 0, 12) . ':';
            }

            return $prefix . 'analytics-hit-buffer:';
        }

        private static function connect_analytics_redis()
        {
            static $redis = null;
            static $attempted = false;

            if ($attempted) {
                return $redis;
            }
            $attempted = true;

            if (!class_exists('Redis') || !defined('UCWP_SETTINGS_KEY')) {
                return null;
            }

            $settings = get_option(UCWP_SETTINGS_KEY, array());
            if (!is_array($settings) || empty($settings['objectCacheEnabled']) || 'redis' !== strtolower(trim((string) ($settings['objectCacheBackend'] ?? 'redis')))) {
                return null;
            }

            try {
                $client = new Redis();
                $host = trim((string) ($settings['redisHost'] ?? '127.0.0.1'));
                if ('' === $host) {
                    $host = '127.0.0.1';
                }
                if (!empty($settings['redisUseTls']) && 0 !== strpos($host, 'tls://')) {
                    $host = 'tls://' . ltrim($host, '/');
                }

                $port = max(1, min(65535, (int) ($settings['redisPort'] ?? 6379)));
                $database = max(0, (int) ($settings['redisDatabase'] ?? 0));
                $connect_timeout = max(0.05, ((int) ($settings['redisConnectTimeoutMs'] ?? 200)) / 1000);
                $read_timeout = max(0.05, ((int) ($settings['redisReadTimeoutMs'] ?? 200)) / 1000);
                $persistent = !empty($settings['redisPersistent']);

                if ($persistent) {
                    $connected = @$client->pconnect($host, $port, $connect_timeout, 'ucwp-analytics-' . md5($host . '|' . $port . '|' . $database));
                } else {
                    $connected = @$client->connect($host, $port, $connect_timeout);
                }
                if (!$connected) {
                    return null;
                }

                if (defined('Redis::OPT_SERIALIZER') && defined('Redis::SERIALIZER_NONE')) {
                    @$client->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
                }
                if (defined('Redis::OPT_READ_TIMEOUT')) {
                    @$client->setOption(Redis::OPT_READ_TIMEOUT, $read_timeout);
                }

                $password = isset($settings['redisPassword']) ? (string) $settings['redisPassword'] : '';
                if ('' !== $password && !@$client->auth($password)) {
                    return null;
                }

                if ($database > 0 && !@$client->select($database)) {
                    return null;
                }

                $redis = $client;
                return $redis;
            } catch (Throwable $e) {
                return null;
            }
        }

        private static function snapshot_redis_analytics_hit_buffer()
        {
            $redis = self::connect_analytics_redis();
            if (!$redis instanceof Redis) {
                return array();
            }

            $prefix = self::get_analytics_redis_prefix();
            $deltas = array();
            foreach (self::get_analytics_hit_buffer_counters() as $counter) {
                try {
                    $value = $redis->get($prefix . $counter);
                } catch (Throwable $e) {
                    $value = false;
                }
                $value = is_numeric($value) ? max(0, (int) $value) : 0;
                if ($value > 0) {
                    $deltas[$counter] = $value;
                }
            }

            return $deltas;
        }

        private static function decrement_redis_analytics_hit_buffer(array $deltas)
        {
            $redis = self::connect_analytics_redis();
            if (!$redis instanceof Redis || empty($deltas)) {
                return;
            }

            $prefix = self::get_analytics_redis_prefix();
            foreach ($deltas as $counter => $amount) {
                $amount = max(0, (int) $amount);
                if ($amount <= 0) {
                    continue;
                }
                try {
                    $redis->decrBy($prefix . (string) $counter, $amount);
                } catch (Throwable $e) {
                }
            }

            try {
                $redis->del($prefix . 'total');
                $redis->setEx($prefix . 'last_flush', 3600, (string) time());
            } catch (Throwable $e) {
            }
        }

        private static function acquire_redis_analytics_flush_lock()
        {
            $redis = self::connect_analytics_redis();
            if (!$redis instanceof Redis) {
                return false;
            }

            try {
                return (bool) $redis->set(self::get_analytics_redis_prefix() . 'flush_lock', '1', array('nx', 'ex' => 10));
            } catch (Throwable $e) {
                return false;
            }
        }

        private static function release_redis_analytics_flush_lock()
        {
            $redis = self::connect_analytics_redis();
            if (!$redis instanceof Redis) {
                return;
            }

            try {
                $redis->del(self::get_analytics_redis_prefix() . 'flush_lock');
            } catch (Throwable $e) {
            }
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

            $redis = self::connect_analytics_redis();
            if ($redis instanceof Redis) {
                $prefix = self::get_analytics_redis_prefix();
                foreach (self::get_analytics_hit_buffer_counters() as $counter) {
                    try {
                        $redis->del($prefix . $counter);
                    } catch (Throwable $e) {
                    }
                }
                try {
                    $redis->del($prefix . 'total');
                    $redis->del($prefix . 'last_flush');
                    $redis->del($prefix . 'flush_lock');
                } catch (Throwable $e) {
                }
            }

            ucwp_safe_unlink(self::get_analytics_hit_buffer_file(), 'analytics_hit_buffer_clear');
        }

        private static function flush_analytics_hit_buffer()
        {
            if (!self::analytics_enabled()) {
                return false;
            }

            $apcu_lock_acquired = false;
            $prefix = self::get_analytics_hit_buffer_key_prefix();
            if (self::analytics_apcu_available()) {
                if (function_exists('apcu_add') && @apcu_add($prefix . 'flush_lock', 1, 10)) {
                    $apcu_lock_acquired = true;
                }
            }

            $redis_lock_acquired = self::acquire_redis_analytics_flush_lock();
            if (!$apcu_lock_acquired && !$redis_lock_acquired) {
                return false;
            }

            $apcu_deltas = $apcu_lock_acquired ? self::snapshot_apcu_analytics_hit_buffer() : array();
            $redis_deltas = $redis_lock_acquired ? self::snapshot_redis_analytics_hit_buffer() : array();

            // Legacy drain only. New advanced-cache hits do not write to this file
            // when APCu/Redis is unavailable.
            $file_deltas = self::consume_file_analytics_hit_buffer();

            if (empty($apcu_deltas) && empty($redis_deltas) && empty($file_deltas)) {
                if ($apcu_lock_acquired) {
                    @apcu_delete($prefix . 'flush_lock');
                }
                if ($redis_lock_acquired) {
                    self::release_redis_analytics_flush_lock();
                }
                return false;
            }

            $analytics = self::read_analytics(false);
            foreach (array($apcu_deltas, $redis_deltas, $file_deltas) as $deltas) {
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

            if ($redis_lock_acquired) {
                self::decrement_redis_analytics_hit_buffer($redis_deltas);
                self::release_redis_analytics_flush_lock();
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

        private static function analytics_enabled()
        {
            $settings = defined('UCWP_SETTINGS_KEY') ? get_option(UCWP_SETTINGS_KEY, array()) : array();
            return is_array($settings) && !empty($settings['cacheStatsEnabled']);
        }

        private static function read_analytics($flush_hit_buffer = true)
        {
            if ($flush_hit_buffer && self::analytics_enabled()) {
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

        private static function write_analytics(array $data, $force = false)
        {
            if (!$force && !self::analytics_enabled()) {
                return;
            }

            self::ensure_cache_directories();
            $file = self::get_analytics_file();
            ucwp_safe_file_put_contents($file, wp_json_encode(self::normalize_analytics($data)), LOCK_EX);
        }

        private static function mutate_analytics($callback)
        {
            if (!self::analytics_enabled()) {
                return;
            }

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
            self::write_analytics(self::get_default_analytics(), true);
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
                // Backward/client compatibility aliases. The UI historically reads the homepage* names,
                // while the analytics writer stores the canonical frontpage* counters.
                'homepageCssStylesScanned' => (int) ($analytics['frontpageCssStylesScanned'] ?? 0),
                'homepageCssStylesBundled' => (int) ($analytics['frontpageCssStylesBundled'] ?? 0),
                'homepageCssStylesSkipped' => (int) ($analytics['frontpageCssStylesSkipped'] ?? 0),
                'homepageCssStylesUnresolved' => (int) ($analytics['frontpageCssStylesUnresolved'] ?? 0),
                'homepageCssBundlesBuilt' => (int) ($analytics['frontpageCssBundlesBuilt'] ?? 0),
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

        private function record_analytics_hit()
        {
            self::mutate_analytics(function ($analytics) {
                $analytics['pageHits'] = (int) ($analytics['pageHits'] ?? 0) + 1;
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
            $this->record_last_css_bundle_summary($result);

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
                    'delayedFontFaceBlocks' => max(0, (int) ($result['delayedFontFaceBlocks'] ?? ($stats['delayedFontFaceBlocks'] ?? 0))),
                    'delayedFontFamilies' => isset($result['delayedFontFamilies']) && is_array($result['delayedFontFamilies']) ? array_values(array_unique(array_map('strval', $result['delayedFontFamilies']))) : array(),
                    'delayedFontPatterns' => isset($result['delayedFontPatterns']) && is_array($result['delayedFontPatterns']) ? array_values(array_unique(array_map('strval', $result['delayedFontPatterns']))) : array(),
                    'delayedFontUrl' => isset($result['delayedFontUrl']) ? (string) $result['delayedFontUrl'] : '',
                    'time' => current_time('timestamp'),
                    'time_mysql' => current_time('mysql'),
                );
                return $analytics;
            });
        }

        private function record_last_css_bundle_summary(array $result = array())
        {
            if (!function_exists('update_option')) {
                return;
            }

            $stats = isset($result['stats']) && is_array($result['stats']) ? $result['stats'] : array();
            $verification = isset($result['warmVerification']) && is_array($result['warmVerification']) ? $result['warmVerification'] : array();
            $source_urls = isset($result['sourceUrls']) && is_array($result['sourceUrls']) ? array_values(array_unique(array_map('strval', $result['sourceUrls']))) : array();

            $summary = array(
                'version' => (defined('UCWP_VERSION') ? (string) UCWP_VERSION : ''),
                'success' => !empty($result['success']),
                'message' => isset($result['message']) ? (string) $result['message'] : '',
                'bundleCount' => max(0, (int) ($result['bundleCount'] ?? 0)),
                'bundleFile' => isset($result['bundleFile']) ? (string) $result['bundleFile'] : '',
                'bundleUrl' => isset($result['bundleUrl']) ? (string) $result['bundleUrl'] : '',
                'bundleBytes' => max(0, (int) ($result['bundleBytes'] ?? 0)),
                'delayedFontFile' => isset($result['delayedFontFile']) ? (string) $result['delayedFontFile'] : '',
                'delayedFontUrl' => isset($result['delayedFontUrl']) ? (string) $result['delayedFontUrl'] : '',
                'delayedFontBytes' => max(0, (int) ($result['delayedFontBytes'] ?? 0)),
                'delayedFontFaceBlocks' => max(0, (int) ($result['delayedFontFaceBlocks'] ?? ($stats['delayedFontFaceBlocks'] ?? 0))),
                'stylesBundled' => max(0, (int) ($stats['bundled'] ?? 0)),
                'stylesScanned' => max(0, (int) ($stats['scanned'] ?? 0)),
                'stylesSkipped' => max(0, (int) ($stats['skipped'] ?? 0)),
                'stylesUnresolved' => max(0, (int) ($stats['unresolved'] ?? 0)),
                'sourceUrlCount' => count($source_urls),
                'sourceUrls' => array_slice($source_urls, 0, 25),
                'warmVerification' => array(
                    'checked' => !empty($verification['checked']),
                    'cachedHtmlAvailable' => !empty($verification['cachedHtmlAvailable']),
                    'containsCssBundle' => !empty($verification['containsCssBundle']),
                    'cssBundleRefs' => max(0, (int) ($verification['cssBundleRefs'] ?? 0)),
                    'stylesheetLinks' => max(0, (int) ($verification['stylesheetLinks'] ?? 0)),
                    'message' => isset($verification['message']) ? (string) $verification['message'] : '',
                ),
                'time' => current_time('timestamp'),
                'time_mysql' => current_time('mysql'),
            );

            if (false === get_option('ucwp_last_css_bundle_summary', false)) {
                add_option('ucwp_last_css_bundle_summary', $summary, '', 'no');
                return;
            }

            update_option('ucwp_last_css_bundle_summary', $summary, false);
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

        public static function setup_advanced_cache($profile = false)
        {
            $profile = (bool) $profile;
            $checkpoint = function ($stage, array $extra = array()) use ($profile) {
                if ($profile && function_exists('ucwp_request_profile_checkpoint')) {
                    ucwp_request_profile_checkpoint('advanced_cache_setup_' . $stage, $extra);
                }
            };

            if (!defined('WP_CONTENT_DIR')) {
                $checkpoint('skipped', array('reason' => 'wp_content_dir_missing'));
                return;
            }

            $target = trailingslashit(WP_CONTENT_DIR) . 'advanced-cache.php';
            $marker = 'UltraCache advanced-cache drop-in';

            $checkpoint('template_read_start');
            $dropin = self::get_advanced_cache_dropin_contents();
            $checkpoint('template_read_end', array('dropin_bytes' => strlen((string) $dropin)));
            if ('' === $dropin) {
                $checkpoint('skipped', array('reason' => 'template_empty'));
                return;
            }

            if (file_exists($target) && is_readable($target)) {
                $checkpoint('existing_read_start', array('target' => basename((string) $target)));
                $existing = (string) ucwp_safe_file_get_contents($target, 'advanced_cache_existing_read');
                $checkpoint('existing_read_end', array('existing_bytes' => strlen((string) $existing)));
                if ('' !== $existing && $existing === $dropin) {
                    $checkpoint('unchanged', array('result' => 'already_current'));
                    return;
                }

                if ('' !== $existing && false === strpos($existing, $marker)) {
                    $checkpoint('skipped', array('reason' => 'foreign_dropin'));
                    return;
                }
            }

            $tmp = $target . '.tmp-' . uniqid('', true);
            $checkpoint('write_temp_start');
            if (false === ucwp_safe_file_put_contents($tmp, $dropin, LOCK_EX, 'advanced_cache_dropin_write')) {
                $checkpoint('write_temp_failed');
                ucwp_safe_unlink($tmp);
                return;
            }
            $checkpoint('write_temp_end');

            $checkpoint('rename_start');
            if (!ucwp_safe_rename($tmp, $target)) {
                $checkpoint('rename_failed');
                ucwp_safe_unlink($tmp);
                return;
            }
            $checkpoint('rename_end', array('result' => 'written'));
        }

        public static function get_advanced_cache_dropin_status()
        {
            $status = array(
                'exists' => false,
                'readable' => false,
                'has_marker' => false,
                'build' => '',
                'expected_build' => defined('UCWP_VERSION') ? (string) UCWP_VERSION : '',
                'healthy' => false,
                'reason' => '',
            );

            if (!defined('WP_CONTENT_DIR')) {
                $status['reason'] = 'wp_content_dir_missing';
                return $status;
            }

            $target = trailingslashit(WP_CONTENT_DIR) . 'advanced-cache.php';
            $status['exists'] = file_exists($target);
            $status['readable'] = $status['exists'] && is_readable($target) && is_file($target);

            if (!$status['exists']) {
                $status['reason'] = 'missing';
                return $status;
            }

            if (!$status['readable']) {
                $status['reason'] = 'not_readable';
                return $status;
            }

            // Frontend health checks are read-only and intentionally avoid
            // WP_Filesystem initialization. All writes/repairs are handled in
            // admin, activation, settings-save, or WP-CLI contexts.
            $contents = @file_get_contents($target);
            if (!is_string($contents) || '' === $contents) {
                $status['reason'] = 'read_failed';
                return $status;
            }

            $status['has_marker'] = false !== strpos($contents, 'UltraCache advanced-cache drop-in');
            if (preg_match('/Drop-in Build:\s*([^\r\n*]+)/', $contents, $matches)) {
                $status['build'] = trim((string) $matches[1]);
            }

            if (!$status['has_marker']) {
                $status['reason'] = 'foreign_dropin';
                return $status;
            }

            if ('' !== $status['expected_build'] && '' !== $status['build'] && $status['build'] !== $status['expected_build']) {
                $status['reason'] = 'stale_build';
                return $status;
            }

            if ('' === $status['build']) {
                $status['reason'] = 'build_marker_missing';
                return $status;
            }

            $status['healthy'] = true;
            $status['reason'] = 'current';
            return $status;
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
            $this->profile_request_checkpoint('maybe_start_buffering_start');
            $this->profile_request_checkpoint('maybe_start_buffering_before_reentry_check');
            if ($this->buffering) {
                $this->profile_request_checkpoint('maybe_start_buffering_reentry_return');
                return;
            }
            $this->profile_request_checkpoint('maybe_start_buffering_after_reentry_check');

            $this->profile_request_checkpoint('maybe_start_buffering_before_should_bypass');
            $should_bypass = $this->should_bypass_cache();
            $this->profile_request_checkpoint('maybe_start_buffering_after_should_bypass', array(
                'result' => $should_bypass ? 'true' : 'false',
                'reason' => (string) $this->last_bypass_reason,
            ));
            if ($should_bypass) {
                $this->profile_request_checkpoint('bypass_selected', array('reason' => (string) $this->last_bypass_reason));
                $this->record_analytics_bypass($this->last_bypass_reason);
                $this->send_debug_headers('BYPASS', $this->last_bypass_reason);
                return;
            }

            $this->profile_request_checkpoint('early_hit_check_start');
            if ($this->maybe_serve_cached_file_during_wp_boot('early-hit')) {
                $this->profile_request_checkpoint('early_hit_served');
                exit;
            }
            $this->profile_request_checkpoint('early_hit_check_end');

            $this->profile_request_checkpoint('page_generation_lock_before');
            $this->maybe_acquire_page_generation_lock();
            $this->profile_request_checkpoint('page_generation_lock_checked', array('has_lock' => '' !== (string) $this->page_cache_generation_lock_name ? 'true' : 'false'));

            $this->profile_request_checkpoint('record_analytics_miss_start');
            $this->record_analytics_miss();
            $this->profile_request_checkpoint('record_analytics_miss_end');
            $this->profile_request_checkpoint('send_debug_headers_start');
            $this->send_debug_headers('MISS');
            $this->profile_request_checkpoint('send_debug_headers_end');
            $this->buffering = true;
            $this->profile_request_checkpoint('buffer_start');
            ob_start(array($this, 'cache_output_callback'));
        }

        private function maybe_acquire_page_generation_lock()
        {
            $this->profile_request_checkpoint('page_generation_lock_before_current_url');
            $url = $this->get_current_request_url();
            $this->profile_request_checkpoint('page_generation_lock_after_current_url', array('url_empty' => '' === (string) $url ? 'yes' : 'no'));
            if ('' === $url) {
                return false;
            }

            $this->profile_request_checkpoint('page_generation_lock_before_cache_path');
            $file_path = $this->get_cache_path($url);
            $this->profile_request_checkpoint('page_generation_lock_after_cache_path', array('file_path_empty' => '' === (string) $file_path ? 'yes' : 'no'));
            if ('' === $file_path) {
                return false;
            }

            $lock_name = 'page-cache-build-' . md5($file_path);
            $this->profile_request_checkpoint('page_generation_lock_acquire_start');
            if ($this->acquire_runtime_lock($lock_name, 45)) {
                $this->page_cache_generation_lock_name = $lock_name;
                $this->profile_request_checkpoint('page_generation_lock_acquired');
                return true;
            }

            $this->profile_request_checkpoint('page_generation_lock_wait_start');
            if ($this->wait_for_page_cache_file($file_path, 18.0)) {
                $this->profile_request_checkpoint('page_generation_lock_wait_hit_ready');
                $this->maybe_serve_cache_file_path($file_path, 'HIT', 'stampede-wait');
                exit;
            }

            $this->profile_request_checkpoint('page_generation_lock_wait_timeout');
            if (!headers_sent()) {
                header('X-Ultra-Cache-Stampede: timeout');
            }

            return false;
        }

        private function release_page_generation_lock()
        {
            if ('' === $this->page_cache_generation_lock_name) {
                return;
            }

            $lock_name = $this->page_cache_generation_lock_name;
            $this->page_cache_generation_lock_name = '';
            $this->release_runtime_lock($lock_name);
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

        private function maybe_serve_cached_file_during_wp_boot($reason = 'early-hit')
        {
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
            if ('' === $file_path || !is_readable($file_path)) {
                return false;
            }

            $this->profile_request_checkpoint('early_hit_before_file_read', array('file' => basename((string) $file_path)));
            $html = ucwp_safe_file_get_contents($file_path);
            $this->profile_request_checkpoint('early_hit_after_file_read', array('html_bytes' => is_string($html) ? strlen($html) : 0));
            if (!is_string($html) || '' === $html) {
                return false;
            }

            $this->profile_request_checkpoint('early_hit_before_css_ref_validation');
            if (!$this->validate_cached_html_css_bundle_refs($html, $file_path)) {
                $this->profile_request_checkpoint('early_hit_css_ref_validation_failed');
                return false;
            }
            $this->profile_request_checkpoint('early_hit_after_css_ref_validation');

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
            echo $html;
            return true;
        }

        private function validate_cached_html_css_bundle_refs($html, $cache_file = '')
        {
            $html = (string) $html;
            if ('' === $html || false === stripos($html, '/cache/ultracache/css-bundles/')) {
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
                ucwp_safe_unlink($cache_file);
                ucwp_safe_unlink($cache_file . '.gz');
                ucwp_safe_unlink($cache_file . '.br');
            }

            $this->record_cache_event('stale-css-bundle-html-invalidated', array(
                'file' => $cache_file,
                'missing' => array_slice(array_values($missing), 0, 20),
                'missing_count' => count($missing),
            ));

            if (!headers_sent()) {
                header('X-Ultra-Cache-Stale-CSS-Bundle: invalidated');
            }

            return false;
        }

        private function get_missing_css_bundle_refs_from_html($html)
        {
            $html = (string) $html;
            $missing = array();
            if ('' === $html || false === stripos($html, '/cache/ultracache/css-bundles/')) {
                return $missing;
            }

            preg_match_all('#(?:https?:)?//[^\s\"\'<>]+/wp-content/cache/ultracache/css-bundles/[^\s\"\'<>?#)]+\.css#i', $html, $absolute_matches);
            preg_match_all('#/wp-content/cache/ultracache/css-bundles/[^\s\"\'<>?#)]+\.css#i', $html, $path_matches);

            $refs = array_merge(
                isset($absolute_matches[0]) && is_array($absolute_matches[0]) ? $absolute_matches[0] : array(),
                isset($path_matches[0]) && is_array($path_matches[0]) ? $path_matches[0] : array()
            );

            $refs = array_values(array_unique(array_map('strval', $refs)));
            $bundle_dir = wp_normalize_path($this->get_frontpage_css_dir());
            foreach ($refs as $ref) {
                $path = (string) wp_parse_url($ref, PHP_URL_PATH);
                if ('' === $path) {
                    $path = $ref;
                }
                $basename = basename(rawurldecode($path));
                if ('' === $basename || false === preg_match('/^bundle-[A-Za-z0-9_.-]+\.css$/', $basename)) {
                    continue;
                }

                $file = wp_normalize_path($bundle_dir . $basename);
                clearstatcache(true, $file);
                if (!is_readable($file) || filesize($file) <= 0) {
                    $missing[$basename] = $basename;
                }
            }

            return array_values($missing);
        }

        public function cache_output_callback($html)
        {
            $this->profile_request_checkpoint('cache_output_callback_start', array('html_bytes' => is_string($html) ? strlen($html) : 0));
            $this->buffering = false;

            if (!is_string($html) || '' === $html) {
                $this->release_page_generation_lock();
                return $html;
            }

            $this->start_store_profile($html);

            $html = $this->profile_store_stage('frontend_performance_optimizations_total', $html, function ($html) {
                return $this->apply_frontend_performance_optimizations($html);
            });
            $html = $this->profile_store_stage('final_google_fonts_rewrite_before_skip_check', $html, function ($html) {
                return $this->apply_final_google_fonts_rewrite_before_cache_store($html);
            });
            $skip_reason = $this->get_skip_store_reason($html);
            if ('' !== $skip_reason) {
                $this->record_analytics_store_skip($skip_reason);
                if ($this->is_internal_revalidate_request()) {
                    $this->clear_revalidate_lock($this->get_current_request_url());
                }
                $this->send_debug_headers('SKIP', $skip_reason);
                $this->finalize_store_profile('SKIP', $skip_reason, '');
                $this->release_page_generation_lock();
                return $html;
            }

            $url = $this->get_current_request_url();
            $file_path = $this->get_cache_path($url);
            if (empty($file_path)) {
                $this->send_debug_headers('SKIP', 'empty-cache-path');
                $this->finalize_store_profile('SKIP', 'empty-cache-path', '');
                $this->release_page_generation_lock();
                return $html;
            }

            $store_write_started_at = microtime(true);
            $write_ok = $this->profile_store_event('final_cache_write', $html, function ($html) use ($file_path) {
                return $this->write_cache_file($file_path, $html);
            });
            $store_write_ms = (int) round((microtime(true) - $store_write_started_at) * 1000);
            if (!headers_sent()) {
                header('X-Ultra-Cache-Store-Write-Ms: ' . (string) $store_write_ms);
                $warning_threshold_ms = (int) apply_filters('ucwp_store_write_warning_ms', 1200);
                if ($store_write_ms >= max(250, $warning_threshold_ms)) {
                    header('X-Ultra-Cache-Store-Warning: slow-write-' . (string) $store_write_ms . 'ms');
                }
            }
            if (!$write_ok || !file_exists($file_path)) {
                $this->record_analytics_store_skip('write-failed');
                if ($this->is_internal_revalidate_request()) {
                    $this->clear_revalidate_lock($url);
                }
                $this->send_debug_headers('SKIP', 'write-failed');
                $this->finalize_store_profile('SKIP', 'write-failed', $file_path);
                $this->release_page_generation_lock();
                return $html;
            }

            if ($this->is_internal_revalidate_request()) {
                $this->clear_revalidate_lock($url);
                if (!headers_sent()) {
                    header('X-Ultra-Cache-Revalidate: refreshed');
                }
            }
            $this->send_debug_headers('STORE');
            if (!headers_sent()) {
                header('X-Ultra-Cache-Store-Post-Response: deferred');
            }
            $this->profile_request_checkpoint('cache_output_callback_end', array('html_bytes' => is_string($html) ? strlen($html) : 0));

            // Speed Diagnostics requests need the timing breakdown saved before the
            // dashboard REST request returns. Normal visitor analytics still stay
            // deferred so the 2.56.123 TTFB protection remains intact.
            if ($this->is_store_profiler_enabled()) {
                $this->finalize_store_profile('STORE', '', $file_path);
            }

            $this->defer_store_post_response_action('store_success', array(
                'url' => $url,
                'file_path' => $file_path,
            ));
            $this->release_page_generation_lock();

            return $html;
        }

        private function write_cache_file($file_path, $html)
        {
            $html = $this->profile_store_stage('final_google_fonts_rewrite_inside_write', $html, function ($html) {
                return $this->apply_final_google_fonts_rewrite_before_cache_store($html);
            });
            $dir = dirname($file_path);
            if (!file_exists($dir) && !ucwp_safe_mkdir($dir, 0755, true) && !file_exists($dir)) {
                return false;
            }

            if ($this->page_cache_variant_cap_reached($file_path)) {
                $this->record_cache_event('variant-cap', array('file' => $file_path));
                return false;
            }

            $write_lock_name = 'page-cache-write-' . md5((string) $file_path);
            if (!$this->acquire_runtime_lock($write_lock_name, 90)) {
                $this->record_cache_event('store-write-lock-busy', array('file' => $file_path));
                return false;
            }

            try {
                if (!$this->validate_cached_html_css_bundle_refs($html, '')) {
                    $this->record_cache_event('skip-store-missing-css-bundle-ref', array('file' => $file_path));
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
            $cap = (int) apply_filters('ucwp_page_cache_variant_cap_per_bucket', 8);
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

            // Google Fonts localization is best-effort and must never block page-cache storage.
            // If local font CSS is still being built, the HTML keeps the original Google Fonts fallback
            // and the background/real-cron build can localize it on a later regeneration.

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

        private function should_send_source_debug_header()
        {
            $flag = function_exists('ucwp_server_value') ? strtolower(trim((string) ucwp_server_value('HTTP_X_ULTRACACHE_DEBUG'))) : '';
            return in_array($flag, array('1', 'true', 'yes', 'on'), true);
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

        public function profile_init_checkpoint()
        {
            $this->profile_request_checkpoint('init');
        }

        public function profile_wp_loaded_checkpoint()
        {
            $this->profile_request_checkpoint('wp_loaded');
        }

        public function profile_template_redirect_checkpoint()
        {
            $this->profile_request_checkpoint('template_redirect_start');
        }

        public function profile_wp_enqueue_scripts_start_checkpoint()
        {
            $this->profile_request_checkpoint('wp_enqueue_scripts_start');
        }

        public function profile_wp_enqueue_scripts_end_checkpoint()
        {
            $this->profile_request_checkpoint('wp_enqueue_scripts_end');
        }

        private function get_store_profile_request_started_at()
        {
            if (isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])) {
                return (float) $_SERVER['REQUEST_TIME_FLOAT'];
            }

            if (isset($_SERVER['REQUEST_TIME']) && is_numeric($_SERVER['REQUEST_TIME'])) {
                return (float) $_SERVER['REQUEST_TIME'];
            }

            return $this->store_profile_started_at > 0 ? (float) $this->store_profile_started_at : microtime(true);
        }

        private function profile_request_checkpoint($stage, array $extra = array())
        {
            if (!$this->is_store_profiler_enabled()) {
                return;
            }

            $stage_key = sanitize_key((string) $stage);
            if (function_exists('ucwp_request_profile_should_record_checkpoint') && !ucwp_request_profile_should_record_checkpoint($stage_key)) {
                return;
            }

            $now = microtime(true);
            $request_start = $this->get_store_profile_request_started_at();
            $previous = $this->store_profile_last_checkpoint_at > 0 ? $this->store_profile_last_checkpoint_at : $request_start;
            $checkpoint = array_merge(array(
                'stage' => $stage_key,
                'at_ms' => (int) round(max(0, $now - $request_start) * 1000),
                'since_previous_ms' => (int) round(max(0, $now - $previous) * 1000),
                'memory_bytes' => function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0,
                'peak_memory_bytes' => function_exists('memory_get_peak_usage') ? (int) memory_get_peak_usage(true) : 0,
            ), $extra);

            $this->store_profile_request_checkpoints[] = $checkpoint;
            $this->store_profile_last_checkpoint_at = $now;
        }

        private function profile_settings_request_checkpoint($stage, array $extra = array())
        {
            if (function_exists('ucwp_request_profile_verbose_settings_enabled') && !ucwp_request_profile_verbose_settings_enabled()) {
                return;
            }

            $this->profile_request_checkpoint($stage, $extra);
        }

        private function get_store_profile_request_checkpoints()
        {
            $external = function_exists('ucwp_get_request_profile_checkpoints') ? ucwp_get_request_profile_checkpoints() : array();
            $checkpoints = array_merge(is_array($external) ? $external : array(), $this->store_profile_request_checkpoints);

            if (empty($checkpoints)) {
                return array();
            }

            $indexed = array();
            foreach ($checkpoints as $index => $checkpoint) {
                if (!is_array($checkpoint)) {
                    continue;
                }
                $checkpoint['_ucwp_order'] = $index;
                $indexed[] = $checkpoint;
            }

            usort($indexed, function ($a, $b) {
                $a_ms = isset($a['at_ms']) ? (int) $a['at_ms'] : 0;
                $b_ms = isset($b['at_ms']) ? (int) $b['at_ms'] : 0;
                if ($a_ms === $b_ms) {
                    return (int) ($a['_ucwp_order'] ?? 0) <=> (int) ($b['_ucwp_order'] ?? 0);
                }
                return $a_ms <=> $b_ms;
            });

            $previous_at = 0;
            foreach ($indexed as $i => $checkpoint) {
                $at = isset($checkpoint['at_ms']) ? (int) $checkpoint['at_ms'] : 0;
                $checkpoint['since_previous_ms'] = 0 === $i ? $at : max(0, $at - $previous_at);
                unset($checkpoint['_ucwp_order']);
                $indexed[$i] = $checkpoint;
                $previous_at = $at;
            }

            return $indexed;
        }

        private function get_store_profile_request_phase_summary(array $checkpoints)
        {
            $wanted = array(
                'plugin_file_loaded',
                'ultracache_wp_construct_start',
                'ultracache_dependencies_loaded',
                'ultracache_hooks_registered',
                'plugins_loaded_p-1000',
                'plugins_loaded_p0',
                'plugins_loaded_p5_components',
                'engine_construct',
                'plugins_loaded_p18_before_reconcile',
                'plugins_loaded_p19_before_page_cache_reconcile',
                'page_cache_reconcile_skipped',
                'page_cache_reconcile_light_start',
                'page_cache_reconcile_light_end',
                'page_cache_reconcile_full_start',
                'advanced_cache_setup_template_read_start',
                'advanced_cache_setup_template_read_end',
                'advanced_cache_setup_existing_read_start',
                'advanced_cache_setup_existing_read_end',
                'advanced_cache_setup_unchanged',
                'advanced_cache_setup_write_temp_start',
                'advanced_cache_setup_write_temp_end',
                'advanced_cache_setup_rename_start',
                'advanced_cache_setup_rename_end',
                'page_cache_reconcile_full_end',
                'plugins_loaded_p20_before_object_cache_reconcile',
                'plugins_loaded_p21_before_runtime_config_reconcile',
                'plugins_loaded_p22_after_reconcile',
                'plugins_loaded_end',
                'setup_theme_start',
                'setup_theme_end',
                'after_setup_theme_start',
                'after_setup_theme_end',
                'init_start',
                'init',
                'init_end',
                'wp_loaded_start',
                'wp_loaded',
                'wp_loaded_end',
                'template_redirect_global_start',
                'template_redirect_start',
                'maybe_start_buffering_start',
                'maybe_start_buffering_after_reentry_check',
                'maybe_start_buffering_before_should_bypass',
                'maybe_start_buffering_after_should_bypass',
                'early_hit_check_start',
                'early_hit_check_end',
                'page_generation_lock_before',
                'page_generation_lock_checked',
                'record_analytics_miss_start',
                'record_analytics_miss_end',
                'send_debug_headers_start',
                'send_debug_headers_end',
                'buffer_start',
                'cache_output_callback_start',
                'cache_output_callback_end',
                'shutdown_start',
                'shutdown_end',
            );

            $by_stage = array();
            foreach ($checkpoints as $checkpoint) {
                if (!is_array($checkpoint) || empty($checkpoint['stage'])) {
                    continue;
                }
                $stage = (string) $checkpoint['stage'];
                if (!isset($by_stage[$stage])) {
                    $by_stage[$stage] = isset($checkpoint['at_ms']) ? (int) $checkpoint['at_ms'] : 0;
                }
            }

            $summary = array();
            $previous_stage = '';
            $previous_ms = null;
            foreach ($wanted as $stage) {
                if (!isset($by_stage[$stage])) {
                    continue;
                }
                $at = (int) $by_stage[$stage];
                $summary[] = array(
                    'stage' => $stage,
                    'at_ms' => $at,
                    'since_previous_wanted_ms' => null === $previous_ms ? $at : max(0, $at - (int) $previous_ms),
                    'previous_stage' => $previous_stage,
                );
                $previous_stage = $stage;
                $previous_ms = $at;
            }

            return $summary;
        }

        private function get_store_profile_callback_timings()
        {
            if (function_exists('ucwp_get_request_profile_callback_timings')) {
                $timings = ucwp_get_request_profile_callback_timings(120);
                return is_array($timings) ? $timings : array();
            }

            return array();
        }

        private function get_store_profile_callback_timing_summary()
        {
            if (function_exists('ucwp_get_request_profile_callback_timing_summary')) {
                $summary = ucwp_get_request_profile_callback_timing_summary(80);
                return is_array($summary) ? $summary : array();
            }

            return array();
        }

        private function get_store_profile_settings_snapshot()
        {
            $settings = $this->get_settings();
            return array(
                'homepage_css_bundle' => !empty($settings['homepage_css_bundle']),
                'homepage_css_bundle_inline' => !empty($settings['homepage_css_bundle_inline']),
                'homepage_css_bundle_mode' => isset($settings['homepage_css_bundle_mode']) ? (string) $settings['homepage_css_bundle_mode'] : '',
                'css_bundle_scope' => isset($settings['css_bundle_scope']) ? (string) $settings['css_bundle_scope'] : '',
                'page_css_bundle_on_entry' => !empty($settings['page_css_bundle_on_entry']),
                'async_css' => !empty($settings['async_css']),
                'aggressive_async_css' => !empty($settings['aggressive_async_css']),
                'defer_js' => !empty($settings['defer_js']),
                'defer_all_js' => !empty($settings['defer_all_js']),
                'delay_safe_third_party_js' => !empty($settings['delay_safe_third_party_js']),
                'delay_non_critical_js' => !empty($settings['delay_non_critical_js']),
                'lcp_image_priority' => !empty($settings['lcp_image_priority']),
                'lcp_boundary_defer' => !empty($settings['lcp_boundary_defer']),
                'frontend_safe_mode' => !empty($settings['frontend_safe_mode']),
                'slider_safe_mode' => !empty($settings['slider_safe_mode']),
            );
        }

        private function get_store_profile_css_bundle_context()
        {
            $context = array(
                'entry_url' => '',
                'bundle_url' => '',
                'bundle_file' => '',
                'bundle_file_exists' => false,
                'bundle_file_bytes' => 0,
                'source_url_count' => 0,
                'source_bytes_total' => 0,
                'largest_source_bytes' => 0,
                'largest_source_url' => '',
                'source_top' => array(),
                'mode' => '',
                'large_bundle_warning' => false,
                'very_large_bundle_warning' => false,
                'source_control_ready' => false,
            );

            $settings = $this->get_settings();
            if (empty($settings['homepage_css_bundle'])) {
                return $context;
            }

            $scope = $this->get_css_bundle_scope($settings);
            $current_url = $this->get_current_request_url();
            $entry_url = $current_url;
            if ('homepage' === $scope || 'shared' === $scope) {
                $entry_url = home_url('/');
            }
            $context['entry_url'] = (string) $entry_url;

            $entry = $this->get_frontpage_css_manifest_entry($entry_url);
            if (empty($entry)) {
                return $context;
            }

            $bundle_file = isset($entry['bundleFile']) ? (string) $entry['bundleFile'] : (isset($entry['file']) ? (string) $entry['file'] : '');
            $context['bundle_url'] = isset($entry['bundleUrl']) ? (string) $entry['bundleUrl'] : '';
            $context['bundle_file'] = $bundle_file;
            $context['bundle_file_exists'] = ('' !== $bundle_file && is_readable($bundle_file));
            $context['bundle_file_bytes'] = $context['bundle_file_exists'] ? (int) filesize($bundle_file) : 0;
            $context['source_url_count'] = isset($entry['sourceUrls']) && is_array($entry['sourceUrls']) ? count($entry['sourceUrls']) : 0;
            $context['mode'] = isset($entry['mode']) ? (string) $entry['mode'] : '';

            $source_details = array();
            if (isset($entry['sourceDetails']) && is_array($entry['sourceDetails'])) {
                $source_details = $entry['sourceDetails'];
            } elseif (!empty($entry['sourceUrls']) && is_array($entry['sourceUrls'])) {
                $source_details = $this->build_css_bundle_source_details_from_urls((array) $entry['sourceUrls']);
            }

            $source_details = $this->normalize_css_bundle_source_details($source_details);
            $context['source_top'] = array_slice($source_details, 0, 12);
            $context['source_control_ready'] = !empty($context['source_top']);
            $context['source_bytes_total'] = isset($entry['sourceBytesTotal']) ? max(0, (int) $entry['sourceBytesTotal']) : $this->sum_css_bundle_source_detail_bytes($source_details);
            if (!empty($source_details[0])) {
                $context['largest_source_bytes'] = isset($source_details[0]['bytes']) ? (int) $source_details[0]['bytes'] : 0;
                $context['largest_source_url'] = isset($source_details[0]['url']) ? (string) $source_details[0]['url'] : '';
            }

            $context['large_bundle_warning'] = ($context['bundle_file_bytes'] > 153600);
            $context['very_large_bundle_warning'] = ($context['bundle_file_bytes'] > 204800);

            return $context;
        }

        private function get_css_bundle_source_type($url)
        {
            $path = strtolower((string) wp_parse_url((string) $url, PHP_URL_PATH));
            if (false !== strpos($path, '/wp-content/plugins/')) {
                return 'plugin';
            }
            if (false !== strpos($path, '/wp-content/themes/')) {
                return 'theme';
            }
            if (false !== strpos($path, '/wp-content/uploads/')) {
                return 'uploads';
            }
            if (false !== strpos($path, '/wp-content/cache/ultracache/')) {
                return 'ultracache-cache';
            }
            return 'local';
        }

        private function get_css_bundle_source_exclusion_suggestion($url)
        {
            $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
            $path = trim($path);
            if ('' === $path) {
                $path = trim((string) $url);
            }
            if ('' === $path) {
                return '';
            }
            $path = rawurldecode($path);
            $path = preg_replace('/[\r\n\t]+/', '', $path);
            return trim((string) $path);
        }

        private function build_css_bundle_source_details_from_urls(array $source_urls)
        {
            $details = array();
            foreach ($source_urls as $source_url) {
                $url = trim((string) $source_url);
                if ('' === $url) {
                    continue;
                }
                $path = $this->resolve_local_path_from_public_url($url);
                $bytes = ('' !== $path && is_readable($path)) ? (int) filesize($path) : 0;
                $details[] = array(
                    'url' => $url,
                    'bytes' => $bytes,
                    'preparedBytes' => 0,
                    'type' => $this->get_css_bundle_source_type($url),
                );
            }
            return $details;
        }

        private function normalize_css_bundle_source_details(array $details)
        {
            $normalized = array();
            foreach ($details as $detail) {
                if (!is_array($detail)) {
                    continue;
                }
                $url = isset($detail['url']) ? trim((string) $detail['url']) : '';
                if ('' === $url) {
                    continue;
                }
                $bytes = isset($detail['bytes']) ? max(0, (int) $detail['bytes']) : 0;
                $prepared_bytes = isset($detail['preparedBytes']) ? max(0, (int) $detail['preparedBytes']) : 0;
                $normalized[] = array(
                    'url' => $url,
                    'bytes' => $bytes,
                    'preparedBytes' => $prepared_bytes,
                    'type' => isset($detail['type']) ? sanitize_key((string) $detail['type']) : $this->get_css_bundle_source_type($url),
                    'suggestedExclusion' => $this->get_css_bundle_source_exclusion_suggestion($url),
                    'largeSourceWarning' => ($bytes > 51200),
                );
            }

            usort($normalized, function ($a, $b) {
                $a_bytes = isset($a['bytes']) ? (int) $a['bytes'] : 0;
                $b_bytes = isset($b['bytes']) ? (int) $b['bytes'] : 0;
                if ($a_bytes === $b_bytes) {
                    return strcmp((string) ($a['url'] ?? ''), (string) ($b['url'] ?? ''));
                }
                return ($a_bytes < $b_bytes) ? 1 : -1;
            });

            return $normalized;
        }

        private function sum_css_bundle_source_detail_bytes(array $details)
        {
            $total = 0;
            foreach ($details as $detail) {
                if (is_array($detail) && isset($detail['bytes'])) {
                    $total += max(0, (int) $detail['bytes']);
                }
            }
            return (int) $total;
        }

        private function sum_store_profile_regex_group_bytes($pattern, $html, $group = 1)
        {
            if (!is_string($html) || '' === $html) {
                return 0;
            }

            $count = preg_match_all($pattern, $html, $matches);
            if (!is_int($count) || $count <= 0 || empty($matches[$group]) || !is_array($matches[$group])) {
                return 0;
            }

            $bytes = 0;
            foreach ($matches[$group] as $match) {
                $bytes += strlen((string) $match);
            }

            return (int) $bytes;
        }

        private function is_store_profiler_enabled()
        {
            if (null !== $this->store_profile_enabled) {
                return (bool) $this->store_profile_enabled;
            }

            $query_flag = sanitize_text_field(ucwp_query_value('ucwp_store_profile'));
            $header_flag = sanitize_text_field(ucwp_server_value('HTTP_X_ULTRACACHE_STORE_PROFILE'));
            $constant_flag = defined('UCWP_STORE_PROFILE') && UCWP_STORE_PROFILE;

            $this->store_profile_enabled = ('1' === $query_flag || 'true' === strtolower((string) $query_flag) || '1' === $header_flag || 'true' === strtolower((string) $header_flag) || $constant_flag);
            return (bool) $this->store_profile_enabled;
        }

        private function get_store_profile_dir()
        {
            return trailingslashit(UCWP_CACHE_DIR) . 'diagnostics/';
        }

        private function get_store_profile_last_file()
        {
            return $this->get_store_profile_dir() . 'store-profile-last.json';
        }

        public function get_last_store_profile()
        {
            $file = $this->get_store_profile_last_file();
            if (!is_readable($file)) {
                return array();
            }

            $json = ucwp_safe_file_get_contents($file);
            $data = json_decode((string) $json, true);
            return is_array($data) ? $data : array();
        }

        public function clear_last_store_profile()
        {
            $file = $this->get_store_profile_last_file();
            if (file_exists($file)) {
                ucwp_safe_unlink($file);
            }
            return !file_exists($file);
        }

        private function start_store_profile($html)
        {
            if (!$this->is_store_profiler_enabled()) {
                return;
            }

            $this->profile_request_checkpoint('store_profile_start', array('html_bytes' => is_string($html) ? strlen($html) : 0));
            $this->store_profile_started_at = microtime(true);
            $request_id = gmdate('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 10);
            $this->store_profile = array(
                'label' => 'UCWP STORE PROFILE',
                'version' => defined('UCWP_VERSION') ? UCWP_VERSION : '',
                'request_id' => $request_id,
                'url' => $this->get_current_request_url(),
                'bucket' => $this->get_request_image_bucket(),
                'started_at_utc' => gmdate('c'),
                'started_at_site' => function_exists('current_time') ? current_time('mysql') : '',
                'request_profile' => array(
                    'request_started_at_ms' => 0,
                    'checkpoints' => $this->get_store_profile_request_checkpoints(),
                ),
                'settings_snapshot' => $this->get_store_profile_settings_snapshot(),
                'css_bundle_context' => $this->get_store_profile_css_bundle_context(),
                'stages' => array(),
            );

            $counts = $this->collect_store_profile_html_counts($html);
            $this->store_profile['stages'][] = array_merge(array(
                'stage' => 'original_wordpress_html',
                'bytes_in' => (int) strlen((string) $html),
                'bytes_out' => (int) strlen((string) $html),
                'delta_bytes' => 0,
                'duration_ms' => 0,
            ), $counts);
        }

        private function profile_store_stage($stage, $html, callable $callback)
        {
            if (!$this->is_store_profiler_enabled()) {
                return $callback($html);
            }

            $before = is_string($html) ? $html : (string) $html;
            $before_bytes = strlen($before);
            $start = microtime(true);
            $result = $callback($html);
            $duration_ms = (int) round((microtime(true) - $start) * 1000);

            if (!is_string($result)) {
                $result = $html;
            }

            $after = (string) $result;
            $after_bytes = strlen($after);
            $this->store_profile['stages'][] = array_merge(array(
                'stage' => sanitize_key((string) $stage),
                'bytes_in' => (int) $before_bytes,
                'bytes_out' => (int) $after_bytes,
                'delta_bytes' => (int) ($after_bytes - $before_bytes),
                'duration_ms' => $duration_ms,
            ), $this->collect_store_profile_html_counts($after));

            return $result;
        }

        private function profile_store_event($stage, $html, callable $callback)
        {
            if (!$this->is_store_profiler_enabled()) {
                return $callback($html);
            }

            $before = is_string($html) ? $html : (string) $html;
            $before_bytes = strlen($before);
            $start = microtime(true);
            $result = $callback($html);
            $duration_ms = (int) round((microtime(true) - $start) * 1000);
            $after = is_string($html) ? $html : (string) $html;
            $after_bytes = strlen($after);

            $this->store_profile['stages'][] = array_merge(array(
                'stage' => sanitize_key((string) $stage),
                'bytes_in' => (int) $before_bytes,
                'bytes_out' => (int) $after_bytes,
                'delta_bytes' => (int) ($after_bytes - $before_bytes),
                'duration_ms' => $duration_ms,
                'result' => is_bool($result) ? ($result ? 'true' : 'false') : (is_scalar($result) ? (string) $result : gettype($result)),
            ), $this->collect_store_profile_html_counts($after));

            return $result;
        }

        private function sum_store_profile_page_css_inline_bytes($html)
        {
            if (!is_string($html) || '' === $html) {
                return 0;
            }

            $bytes = 0;
            $offset = 0;
            $needle = 'data-ucwp-page-css-bundle';
            while (false !== ($style_start = stripos($html, '<style', $offset))) {
                $tag_end = strpos($html, '>', $style_start);
                if (false === $tag_end) {
                    break;
                }

                $open_tag = substr($html, $style_start, $tag_end - $style_start + 1);
                $close_tag = stripos($html, '</style>', $tag_end + 1);
                if (false === $close_tag) {
                    break;
                }

                if (false !== stripos($open_tag, $needle)) {
                    $bytes += max(0, $close_tag - $tag_end - 1);
                }

                $offset = $close_tag + 8;
            }

            return (int) $bytes;
        }

        private function count_store_profile_regex($pattern, $html)
        {
            if (!is_string($html) || '' === $html) {
                return 0;
            }

            $count = preg_match_all($pattern, $html, $matches);
            return is_int($count) ? (int) $count : 0;
        }


        private function get_public_resource_origin_type($url)
        {
            $url = (string) $url;
            $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
            $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
            if ('' !== $host && '' !== $home_host && $host !== $home_host) {
                return 'external';
            }
            if (false !== strpos($path, '/wp-includes/') || false !== strpos($path, '/wp-admin/')) {
                return 'core';
            }
            if (false !== strpos($path, '/wp-content/plugins/')) {
                return 'plugin';
            }
            if (false !== strpos($path, '/wp-content/themes/')) {
                return 'theme';
            }
            if (false !== strpos($path, '/wp-content/uploads/')) {
                return 'uploads';
            }
            if (false !== strpos($path, '/wp-content/cache/ultracache/')) {
                return 'ultracache-cache';
            }
            return 'local';
        }

        private function get_public_resource_path_fragment($url)
        {
            $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
            if ('' === $path) {
                $path = (string) $url;
            }
            $path = rawurldecode($path);
            $path = preg_replace('/[\r\n\t]+/', '', $path);
            return trim((string) $path);
        }

        private function html_tag_has_attribute($tag, $attribute)
        {
            $tag = (string) $tag;
            $attribute = preg_quote((string) $attribute, '/');
            return (bool) preg_match('/\s' . $attribute . '(?:\s*=|\s|>|\/)/i', $tag);
        }

        private function get_html_offset_location($offset, $head_end)
        {
            $offset = (int) $offset;
            $head_end = is_int($head_end) ? $head_end : -1;
            return ($head_end < 0 || $offset <= $head_end) ? 'head' : 'body';
        }

        private function get_html_tag_ranges_by_name($html, $tag_name)
        {
            $html = is_string($html) ? $html : (string) $html;
            $tag_name = preg_replace('/[^a-z0-9_-]/i', '', (string) $tag_name);
            if ('' === $html || '' === $tag_name) {
                return array();
            }

            $ranges = array();
            if (preg_match_all("/<" . preg_quote($tag_name, "/") . "\\b[^>]*>[\\s\\S]*?<\\/" . preg_quote($tag_name, "/") . ">/i", $html, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ((array) $matches[0] as $match) {
                    $start = isset($match[1]) ? (int) $match[1] : -1;
                    $text = isset($match[0]) ? (string) $match[0] : '';
                    if ($start >= 0 && '' !== $text) {
                        $ranges[] = array($start, $start + strlen($text));
                    }
                }
            }

            return $ranges;
        }

        private function is_html_offset_inside_ranges($offset, array $ranges)
        {
            $offset = (int) $offset;
            foreach ($ranges as $range) {
                if (!is_array($range) || count($range) < 2) {
                    continue;
                }
                if ($offset >= (int) $range[0] && $offset < (int) $range[1]) {
                    return true;
                }
            }
            return false;
        }

        private function get_matching_fragment($handle, $url, $tag, array $fragments)
        {
            $haystacks = array(
                strtolower(trim((string) $handle)),
                strtolower(trim((string) $url)),
                strtolower((string) wp_parse_url((string) $url, PHP_URL_PATH)),
                strtolower((string) $tag),
            );
            foreach ($fragments as $fragment) {
                $fragment = strtolower(trim((string) $fragment));
                if ('' === $fragment) {
                    continue;
                }
                foreach ($haystacks as $haystack) {
                    if ('' !== $haystack && false !== strpos($haystack, $fragment)) {
                        return $fragment;
                    }
                }
            }
            return '';
        }

        private function get_style_critical_request_candidate($tag, $offset, $head_end, array $settings = array())
        {
            $href = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag, 'href'), ENT_QUOTES | ENT_HTML5);
            if ('' === $href) {
                return array();
            }

            $media = strtolower(trim((string) $this->extract_attribute_from_html_tag($tag, 'media')));
            $is_print = (false !== strpos($media, 'print') || false !== strpos($media, 'speech'));
            $async_marker = (bool) preg_match('/\b(?:disabled|onload|data-ucwp-async-css|data-ucwp-page-css-bundle-fallback)\b/i', (string) $tag);
            $render_blocking = (!$is_print && !$async_marker);
            $origin = $this->get_public_resource_origin_type($href);
            $path = $this->get_public_resource_path_fragment($href);
            $is_bundle = false !== stripos($href, '/cache/ultracache/css-bundles/');
            $slider_fragment = !empty($settings['slider_safe_mode']) ? $this->get_matching_fragment('', $href, $tag, $this->get_slider_hero_protected_fragments()) : '';
            $bytes = 0;
            $local_path = $this->resolve_local_path_from_public_url($href);
            if ('' !== $local_path && is_readable($local_path)) {
                $bytes = (int) filesize($local_path);
            }

            $status = $render_blocking ? 'render-blocking' : 'non-blocking';
            $reason = $render_blocking ? 'stylesheet link without async/print/deferred marker' : 'async/deferred/print stylesheet';
            $suggested = 'Review before changing.';
            $protected = false;
            $protected_reason = '';
            if ($is_bundle) {
                $reason = 'external UltraCache CSS bundle is render-blocking';
                $suggested = 'Candidate for critical CSS split or deferred non-critical bundle mode.';
            } elseif ('' !== $slider_fragment) {
                $protected = true;
                $protected_reason = 'slider/hero stylesheet fragment: ' . $slider_fragment;
                $reason = $protected_reason;
                $suggested = 'Keep blocking if this slider/hero CSS is needed above the fold.';
            } elseif ($render_blocking && $bytes > 0 && $bytes <= 8192) {
                $suggested = 'Small stylesheet: candidate to fold into a critical/vendor CSS group.';
            } elseif ($render_blocking) {
                $suggested = 'Candidate for async CSS or bundle review after visual testing.';
            }

            return array(
                'type' => 'style',
                'url' => $href,
                'path' => $path,
                'origin' => $origin,
                'location' => $this->get_html_offset_location($offset, $head_end),
                'renderBlocking' => (bool) $render_blocking,
                'delayed' => false,
                'protected' => (bool) $protected,
                'protectedReason' => $protected_reason,
                'status' => $status,
                'reason' => $reason,
                'suggestedAction' => $suggested,
                'bytes' => $bytes,
                'isBundle' => (bool) $is_bundle,
            );
        }

        private function get_script_critical_request_candidate($tag, $offset, $head_end, array $settings = array())
        {
            $tag = (string) $tag;
            $delayed = (false !== stripos($tag, 'type="text/ucwp-delayed-js"') || false !== stripos($tag, "type='text/ucwp-delayed-js'") || false !== stripos($tag, 'data-ucwp-src='));
            $src = $delayed ? (string) $this->extract_attribute_from_html_tag($tag, 'data-ucwp-src') : (string) $this->extract_attribute_from_html_tag($tag, 'src');
            $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5);
            if ('' === $src) {
                return array();
            }

            $handle = (string) $this->extract_attribute_from_html_tag($tag, 'data-ucwp-handle');
            if ('' === $handle) {
                $handle = (string) $this->extract_attribute_from_html_tag($tag, 'id');
                $handle = preg_replace('/-js(?:-extra)?$/', '', $handle);
            }
            $handle = is_string($handle) ? $handle : '';

            $location = $this->get_html_offset_location($offset, $head_end);
            $has_async = $this->html_tag_has_attribute($tag, 'async');
            $has_defer = $this->html_tag_has_attribute($tag, 'defer');
            $is_module = (false !== stripos($tag, 'type="module"') || false !== stripos($tag, "type='module'"));
            $render_blocking = (!$delayed && 'head' === $location && !$has_async && !$has_defer && !$is_module && $this->is_delayable_external_script_tag($tag));
            $origin = $this->get_public_resource_origin_type($src);
            $path = $this->get_public_resource_path_fragment($src);
            $bytes = 0;
            $local_path = $this->resolve_local_path_from_public_url($src);
            if ('' !== $local_path && is_readable($local_path)) {
                $bytes = (int) filesize($local_path);
            }

            $protected = false;
            $protected_reason = '';
            $status = $render_blocking ? 'render-blocking' : 'non-blocking';
            $reason = $render_blocking ? 'head script without async/defer/delay marker' : 'not a parser-blocking head script';
            $suggested = 'Review before changing.';

            if ($delayed) {
                $status = 'delayed';
                $reason = 'already delayed by UltraCache';
                $suggested = 'No action needed unless the delayed script is needed before interaction.';
            } elseif ($has_defer) {
                $status = 'deferred';
                $reason = 'defer attribute present';
                $suggested = 'Already out of the parser-blocking path.';
            } elseif ($has_async) {
                $status = 'async';
                $reason = 'async attribute present';
                $suggested = 'Already out of the parser-blocking path.';
            }

            if (!$delayed) {
                $slider_fragment = !empty($settings['slider_safe_mode']) ? $this->get_matching_fragment($handle, $src, $tag, $this->get_slider_hero_protected_fragments()) : '';
                if ('' !== $slider_fragment) {
                    $protected = true;
                    $protected_reason = 'slider/hero runtime fragment: ' . $slider_fragment;
                    $reason = $protected_reason;
                    $suggested = 'Keep protected while Fix sliders / hero sections is enabled and the slider is above the fold.';
                } elseif ($this->is_script_user_defer_excluded($handle, $src, $settings)) {
                    $protected = true;
                    $protected_reason = 'user-visible defer/delay exclusion matched';
                    $reason = $protected_reason;
                    $suggested = 'Review the visible exclusion list before changing.';
                } elseif ($this->is_script_safe_stage_excluded($handle, $src, $tag, $settings)) {
                    $protected = true;
                    $protected_reason = 'safe-stage protected dependency';
                    $reason = $protected_reason;
                    $suggested = 'Candidate only for a focused dependency-safe defer test.';
                } elseif ($this->is_script_force_blocking($handle, $src, $tag, $settings)) {
                    $protected = true;
                    $protected_reason = 'force-blocking dependency/core/WooCommerce/Elementor rule';
                    $reason = $protected_reason;
                    $suggested = 'Keep blocking unless a dedicated dependency-safe mode is tested.';
                } elseif ($render_blocking && !empty($settings['lcp_boundary_defer']) && $this->matches_non_critical_delay_patterns($handle, $src, $tag)) {
                    $suggested = 'Candidate for LCP Boundary Defer / critical-chain relief after visual testing.';
                } elseif ($render_blocking) {
                    $suggested = 'Candidate for defer/delay only after dependency checks.';
                }
            }

            return array(
                'type' => 'script',
                'url' => $src,
                'path' => $path,
                'handle' => $handle,
                'origin' => $origin,
                'location' => $location,
                'renderBlocking' => (bool) $render_blocking,
                'delayed' => (bool) $delayed,
                'protected' => (bool) $protected,
                'protectedReason' => $protected_reason,
                'status' => $status,
                'reason' => $reason,
                'suggestedAction' => $suggested,
                'bytes' => $bytes,
            );
        }

        private function get_delay_safety_suggested_exclusion_from_url($url)
        {
            $path = (string) wp_parse_url($this->normalize_public_resource_url((string) $url), PHP_URL_PATH);
            $path = rawurldecode($path);
            $path = trim($path);
            if ('' === $path) {
                return trim((string) $url);
            }

            $markers = array(
                '/wp-content/plugins/' => 'plugin',
                '/wp-content/themes/'  => 'theme',
                '/wp-includes/js/'     => 'core',
            );

            foreach ($markers as $marker => $type) {
                $pos = stripos($path, $marker);
                if (false !== $pos) {
                    return ltrim(substr($path, $pos + strlen($marker)), '/');
                }
            }

            return ltrim($path, '/');
        }

        private function delay_safety_exclusion_already_matches($suggestion, array $settings = array())
        {
            $suggestion = strtolower(trim((string) $suggestion));
            if ('' === $suggestion) {
                return false;
            }

            $lists = array();
            if (isset($settings['defer_js_exclude_list']) && is_array($settings['defer_js_exclude_list'])) {
                $lists = array_merge($lists, $settings['defer_js_exclude_list']);
            }
            if (isset($settings['delay_non_critical_js_exclude_list']) && is_array($settings['delay_non_critical_js_exclude_list'])) {
                $lists = array_merge($lists, $settings['delay_non_critical_js_exclude_list']);
            }

            foreach ($lists as $line) {
                $line = strtolower(trim((string) $line));
                if ('' === $line) {
                    continue;
                }
                if ($line === $suggestion || false !== strpos($suggestion, $line) || false !== strpos($line, $suggestion)) {
                    return true;
                }
            }

            return false;
        }


        private function is_js_delay_safety_common_symbol($symbol)
        {
            $symbol = (string) $symbol;
            if ('' === $symbol) {
                return true;
            }

            static $common = null;
            if (null === $common) {
                $common = array_fill_keys(array(
                    'if','for','while','switch','return','function','var','let','const','new','this','true','false','null','undefined','window','document','console','Math','Date','Array','Object','String','Number','Boolean','Promise','fetch','setTimeout','setInterval','clearTimeout','clearInterval','addEventListener','removeEventListener','querySelector','querySelectorAll','getElementById','URLSearchParams','FormData','Event','CustomEvent','JSON','parseInt','parseFloat','isNaN','typeof','decodeURIComponent','encodeURIComponent','jQuery','$'
                ), true);
            }

            return isset($common[$symbol]);
        }

        private function is_js_delay_safety_meaningful_symbol($symbol, $source = 'inline-script')
        {
            $symbol = trim((string) $symbol);
            $source = (string) $source;
            if ('' === $symbol || $this->is_js_delay_safety_common_symbol($symbol)) {
                return false;
            }

            if ('inline-event-handler' === $source) {
                return strlen($symbol) >= 3;
            }

            $allowed_lowercase_globals = array_fill_keys(array(
                'messages','dataLayer','grecaptcha','gtag','fbq','ml'
            ), true);

            if (isset($allowed_lowercase_globals[$symbol])) {
                return true;
            }

            if (strlen($symbol) < 4) {
                return false;
            }

            if (preg_match('/[A-Z_]/', $symbol)) {
                return true;
            }

            return false;
        }

        private function get_js_delay_safety_declared_symbols_from_inline_code($code)
        {
            $code = (string) $code;
            $declared = array();
            $patterns = array(
                '/\b(?:var|let|const)\s+([A-Za-z_$][A-Za-z0-9_$]*)\b/',
                '/\bfunction\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/',
                '/\b(?:function)?\s*\(([^)]*)\)\s*=>/',
                '/\bfunction\b[^\(]*\(([^)]*)\)/',
            );

            foreach ($patterns as $index => $pattern) {
                if (!preg_match_all($pattern, $code, $matches)) {
                    continue;
                }
                foreach ((array) ($matches[1] ?? array()) as $match) {
                    if ($index >= 2) {
                        foreach (explode(',', (string) $match) as $param) {
                            $param = trim((string) preg_replace('/[^A-Za-z0-9_$].*$/', '', trim($param)));
                            if ('' !== $param) {
                                $declared[$param] = true;
                            }
                        }
                    } else {
                        $declared[(string) $match] = true;
                    }
                }
            }

            return $declared;
        }

        private function collect_js_delay_safety_inline_symbols($html)
        {
            $html = is_string($html) ? $html : (string) $html;
            $symbols = array();

            if (preg_match_all('/\son[a-z]+\s*=\s*(["\'])(.*?)\1/is', $html, $handlers, PREG_SET_ORDER)) {
                foreach ($handlers as $handler) {
                    $code = html_entity_decode((string) ($handler[2] ?? ''), ENT_QUOTES | ENT_HTML5);
                    if (preg_match_all('/\b([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $code, $calls)) {
                        foreach ((array) ($calls[1] ?? array()) as $symbol) {
                            $symbol = (string) $symbol;
                            if (!$this->is_js_delay_safety_meaningful_symbol($symbol, 'inline-event-handler')) {
                                continue;
                            }
                            $symbols[$symbol] = array(
                                'symbol' => $symbol,
                                'source' => 'inline-event-handler',
                                'sample' => function_exists('mb_substr') ? mb_substr(trim($code), 0, 220) : substr(trim($code), 0, 220),
                            );
                        }
                    }
                }
            }

            if (preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $html, $scripts, PREG_SET_ORDER)) {
                foreach ($scripts as $script) {
                    $attrs = (string) ($script[1] ?? '');
                    $code = (string) ($script[2] ?? '');
                    $trimmed_code = trim($code);
                    if ('' === $trimmed_code) {
                        continue;
                    }
                    if (false !== stripos($attrs, 'src=') || false !== stripos($attrs, 'data-ucwp-src=') || false !== stripos($attrs, 'text/ucwp-delayed-js') || false !== stripos($attrs, 'application/ld+json') || false !== stripos($attrs, 'speculationrules')) {
                        continue;
                    }
                    if (false !== stripos($trimmed_code, '__ucwpDelayLoader') || false !== stripos($trimmed_code, 'text/ucwp-delayed-js') || false !== stripos($trimmed_code, 'gtm.start') || false !== stripos($trimmed_code, 'googletagmanager.com/gtm.js') || false !== stripos($trimmed_code, 'wp-emoji-settings') || false !== stripos($trimmed_code, '_wpemojiSettings')) {
                        continue;
                    }

                    $declared = $this->get_js_delay_safety_declared_symbols_from_inline_code($trimmed_code);
                    $refs = array();
                    if (preg_match_all('/\b([A-Za-z_$][A-Za-z0-9_$]*)\s*(?:\[|\.)/m', $trimmed_code, $matches)) {
                        foreach ((array) ($matches[1] ?? array()) as $symbol) {
                            $refs[(string) $symbol] = true;
                        }
                    }
                    if (preg_match_all('/\bwindow\.([A-Za-z_$][A-Za-z0-9_$]*)\b/m', $trimmed_code, $matches)) {
                        foreach ((array) ($matches[1] ?? array()) as $symbol) {
                            $refs[(string) $symbol] = true;
                        }
                    }

                    foreach (array_keys($refs) as $symbol) {
                        if (isset($declared[$symbol]) || !$this->is_js_delay_safety_meaningful_symbol($symbol, 'inline-script')) {
                            continue;
                        }
                        if (!isset($symbols[$symbol])) {
                            $sample = trim((string) preg_replace('/\s+/', ' ', $trimmed_code));
                            $symbols[$symbol] = array(
                                'symbol' => $symbol,
                                'source' => 'inline-script',
                                'sample' => function_exists('mb_substr') ? mb_substr($sample, 0, 220) : substr($sample, 0, 220),
                            );
                        }
                    }
                }
            }

            return $symbols;
        }

        private function collect_js_delay_safety_delayed_definitions($html)
        {
            $html = is_string($html) ? $html : (string) $html;
            $definitions = array();

            if (!preg_match_all('/<script\b[^>]*(?:type\s*=\s*["\']text\/ucwp-delayed-js["\']|data-ucwp-src\s*=)[^>]*>/i', $html, $matches)) {
                return $definitions;
            }

            foreach ((array) ($matches[0] ?? array()) as $tag) {
                $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag, 'data-ucwp-src'), ENT_QUOTES | ENT_HTML5);
                if ('' === $src) {
                    $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag, 'src'), ENT_QUOTES | ENT_HTML5);
                }
                if ('' === $src) {
                    continue;
                }

                $local_path = $this->resolve_local_path_from_public_url($src);
                if ('' === $local_path || !is_readable($local_path) || (int) @filesize($local_path) > 1048576) {
                    continue;
                }

                $content = ucwp_safe_file_get_contents($local_path);
                if (!is_string($content) || '' === $content) {
                    continue;
                }

                $handle = (string) $this->extract_attribute_from_html_tag($tag, 'data-ucwp-handle');
                $suggestion = $this->get_delay_safety_suggested_exclusion_from_url($src);
                $symbols = array();

                $patterns = array(
                    '/\bfunction\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/',
                    '/\b(?:var|let|const)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=/',
                    '/\bwindow\.([A-Za-z_$][A-Za-z0-9_$]*)\s*=/',
                    '/\bglobalThis\.([A-Za-z_$][A-Za-z0-9_$]*)\s*=/',
                    '/(?:^|[;\s])([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*function\b/',
                );

                foreach ($patterns as $pattern) {
                    if (preg_match_all($pattern, $content, $found)) {
                        foreach ((array) ($found[1] ?? array()) as $symbol) {
                            $symbol = (string) $symbol;
                            if ($this->is_js_delay_safety_meaningful_symbol($symbol, 'inline-script')) {
                                $symbols[$symbol] = true;
                            }
                        }
                    }
                }

                foreach (array_keys($symbols) as $symbol) {
                    if (!isset($definitions[$symbol])) {
                        $definitions[$symbol] = array();
                    }
                    $definitions[$symbol][] = array(
                        'symbol' => $symbol,
                        'url' => $src,
                        'handle' => $handle,
                        'localPath' => $local_path,
                        'suggestedExclusion' => $suggestion,
                    );
                }
            }

            return $definitions;
        }

        private function collect_js_delay_safety_delayed_script_records($html)
        {
            $html = is_string($html) ? $html : (string) $html;
            $records = array();

            if (!preg_match_all('/<script\b[^>]*(?:type\s*=\s*["\']text\/ucwp-delayed-js["\']|data-ucwp-src\s*=)[^>]*>/i', $html, $matches)) {
                return $records;
            }

            foreach ((array) ($matches[0] ?? array()) as $tag) {
                $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag, 'data-ucwp-src'), ENT_QUOTES | ENT_HTML5);
                if ('' === $src) {
                    $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag, 'src'), ENT_QUOTES | ENT_HTML5);
                }
                if ('' === $src) {
                    continue;
                }

                $local_path = $this->resolve_local_path_from_public_url($src);
                if ('' === $local_path || !is_readable($local_path) || (int) @filesize($local_path) > 1048576) {
                    continue;
                }

                $content = ucwp_safe_file_get_contents($local_path);
                if (!is_string($content) || '' === $content) {
                    continue;
                }

                $records[] = array(
                    'url' => $src,
                    'handle' => (string) $this->extract_attribute_from_html_tag($tag, 'data-ucwp-handle'),
                    'localPath' => $local_path,
                    'suggestedExclusion' => $this->get_delay_safety_suggested_exclusion_from_url($src),
                    'content' => $content,
                );
            }

            return $records;
        }

        private function delayed_script_record_defines_symbol(array $record, $symbol)
        {
            $symbol = (string) $symbol;
            if ('' === $symbol || empty($record['content']) || !is_string($record['content'])) {
                return false;
            }

            $quoted = preg_quote($symbol, '/');
            $patterns = array(
                '/\bfunction\s+' . $quoted . '\s*\(/',
                '/\b(?:var|let|const)\s+' . $quoted . '\s*=/',
                '/\bwindow\.' . $quoted . '\s*=/',
                '/\bglobalThis\.' . $quoted . '\s*=/',
                '/(?:^|[;\s])' . $quoted . '\s*=\s*function\b/',
            );

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $record['content'])) {
                    return true;
                }
            }

            return false;
        }

        private function add_js_delay_safety_suggestion(&$suggestions, &$seen, $symbol, $source, $sample, array $definition, array $settings)
        {
            $symbol = (string) $symbol;
            $source = (string) $source;
            $suggestion = (string) ($definition['suggestedExclusion'] ?? '');
            if ('' === trim($suggestion) || !$this->is_js_delay_safety_meaningful_symbol($symbol, $source)) {
                return;
            }

            $key = strtolower($symbol . '|' . $suggestion);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;

            $reason = 'inline-event-handler' === $source
                ? 'Inline event handler calls ' . $symbol . '(), but the script that defines it is delayed.'
                : 'Inline script references global "' . $symbol . '", but the script that defines it is delayed.';
            $already = $this->delay_safety_exclusion_already_matches($suggestion, $settings);

            $suggestions[] = array(
                'symbol' => $symbol,
                'source' => $source,
                'sample' => (string) $sample,
                'definingScriptUrl' => (string) ($definition['url'] ?? ''),
                'definingHandle' => (string) ($definition['handle'] ?? ''),
                'suggestedExclusion' => $suggestion,
                'confidence' => 'high',
                'reason' => $reason,
                'alreadyExcluded' => (bool) $already,
            );
        }

        private function collect_js_delay_safety_targeted_suggestions($html, array $settings = array())
        {
            $html = is_string($html) ? $html : (string) $html;
            $records = $this->collect_js_delay_safety_delayed_script_records($html);
            $suggestions = array();
            $seen = array();

            if (empty($records)) {
                return $suggestions;
            }

            if (preg_match_all('/\son[a-z]+\s*=\s*(["\'])(.*?)\1/is', $html, $handlers, PREG_SET_ORDER)) {
                foreach ($handlers as $handler) {
                    $code = html_entity_decode((string) ($handler[2] ?? ''), ENT_QUOTES | ENT_HTML5);
                    if (!preg_match_all('/\b([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $code, $calls)) {
                        continue;
                    }
                    foreach ((array) ($calls[1] ?? array()) as $symbol) {
                        $symbol = (string) $symbol;
                        if (!$this->is_js_delay_safety_meaningful_symbol($symbol, 'inline-event-handler')) {
                            continue;
                        }
                        foreach ($records as $record) {
                            if (!$this->delayed_script_record_defines_symbol($record, $symbol)) {
                                continue;
                            }
                            $sample = function_exists('mb_substr') ? mb_substr(trim($code), 0, 220) : substr(trim($code), 0, 220);
                            $this->add_js_delay_safety_suggestion($suggestions, $seen, $symbol, 'inline-event-handler', $sample, $record, $settings);
                        }
                    }
                }
            }

            $allowed_globals = array('messages');
            if (preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $html, $scripts, PREG_SET_ORDER)) {
                foreach ($scripts as $script) {
                    $attrs = (string) ($script[1] ?? '');
                    $code = (string) ($script[2] ?? '');
                    $trimmed_code = trim($code);
                    if ('' === $trimmed_code) {
                        continue;
                    }
                    if (false !== stripos($attrs, 'src=') || false !== stripos($attrs, 'data-ucwp-src=') || false !== stripos($attrs, 'text/ucwp-delayed-js') || false !== stripos($attrs, 'application/ld+json') || false !== stripos($attrs, 'speculationrules')) {
                        continue;
                    }
                    if (false !== stripos($trimmed_code, '__ucwpDelayLoader') || false !== stripos($trimmed_code, 'text/ucwp-delayed-js') || false !== stripos($trimmed_code, 'gtm.start') || false !== stripos($trimmed_code, 'googletagmanager.com/gtm.js') || false !== stripos($trimmed_code, 'wp-emoji-settings') || false !== stripos($trimmed_code, '_wpemojiSettings')) {
                        continue;
                    }

                    foreach ($allowed_globals as $symbol) {
                        if (!preg_match('/\b' . preg_quote($symbol, '/') . '\s*(?:\[|\.|\b)/', $trimmed_code)) {
                            continue;
                        }
                        foreach ($records as $record) {
                            if (!$this->delayed_script_record_defines_symbol($record, $symbol)) {
                                continue;
                            }
                            $sample = trim((string) preg_replace('/\s+/', ' ', $trimmed_code));
                            $sample = function_exists('mb_substr') ? mb_substr($sample, 0, 220) : substr($sample, 0, 220);
                            $this->add_js_delay_safety_suggestion($suggestions, $seen, $symbol, 'inline-script', $sample, $record, $settings);
                        }
                    }
                }
            }

            return $suggestions;
        }


        private function add_js_delay_component_recommendation(&$suggestions, &$seen, $suggestion, $category, $category_label, $reason, array $settings, $confidence = 'recommended', $sample = '', $appendable = true)
        {
            $suggestion = trim((string) $suggestion);
            if ('' === $suggestion) {
                return;
            }

            $category = trim((string) $category);
            if ('' === $category) {
                $category = 'detected-component-protection';
            }

            $key = strtolower($category . '|' . $suggestion);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;

            $already = $this->delay_safety_exclusion_already_matches($suggestion, $settings);
            $suggestions[] = array(
                'symbol' => $suggestion,
                'source' => $category,
                'category' => $category,
                'categoryLabel' => (string) $category_label,
                'sample' => (string) $sample,
                'definingScriptUrl' => '',
                'definingHandle' => '',
                'suggestedExclusion' => $suggestion,
                'confidence' => (string) $confidence,
                'reason' => (string) $reason,
                'alreadyExcluded' => (bool) $already,
                'appendable' => (bool) $appendable,
            );
        }

        private function js_delay_scan_html_has_any_marker($html, array $markers)
        {
            $html = is_string($html) ? $html : (string) $html;
            if ('' === $html) {
                return false;
            }

            foreach ($markers as $marker) {
                $marker = trim((string) $marker);
                if ('' === $marker) {
                    continue;
                }
                if (false !== stripos($html, $marker)) {
                    return true;
                }
            }

            return false;
        }

        private function collect_js_delay_component_recommendations($html, array $settings = array())
        {
            $html = is_string($html) ? $html : (string) $html;
            $suggestions = array();
            $seen = array();

            if ('' === $html) {
                return $suggestions;
            }

            $groups = array(
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('sr7-module', 'sr7-slide', 'revslider', '/plugins/revslider/', 'themepunch', 'rs-module', 'wp-block-themepunch-revslider'),
                    'suggestions' => array('revslider', 'sr7', 'tptools', 'tp-tools', 'rs6', 'rs-module'),
                    'reason' => 'Slider Revolution / SR7 assets or markup were detected on this page. Keep slider runtime assets out of Delay JS unless visually tested.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('swiper', 'swiper-container', 'swiper-wrapper', '/swiper/', 'elementor-widget-slides', 'elementor-widget-image-carousel', 'elementor-widget-media-carousel'),
                    'suggestions' => array('swiper', 'swiper-bundle'),
                    'reason' => 'Swiper slider/carousel assets or markup were detected on this page. Keep these runtime assets protected unless visually tested.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('slick', 'slick-slider', 'slick-track', 'slick.min.js'),
                    'suggestions' => array('slick'),
                    'reason' => 'Slick carousel assets or markup were detected on this page. Keep carousel runtime assets protected unless visually tested.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('splide', 'splide__track', 'splide.min.js'),
                    'suggestions' => array('splide'),
                    'reason' => 'Splide slider assets or markup were detected on this page. Keep slider runtime assets protected unless visually tested.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('owl.carousel', 'owl-carousel', 'owl-stage', 'owlCarousel'),
                    'suggestions' => array('owl.carousel', 'owl-carousel'),
                    'reason' => 'Owl Carousel assets or markup were detected on this page. Keep carousel runtime assets protected unless visually tested.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('smartslider', 'smart-slider', 'n2-ss', 'nextend'),
                    'suggestions' => array('smartslider', 'n2-ss'),
                    'reason' => 'Smart Slider / Nextend assets or markup were detected on this page. Keep slider runtime assets protected unless visually tested.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('layerslider', 'layer-slider', 'masterslider', 'master-slider', 'metaslider', 'soliloquy', 'royalslider', 'sliderpro', 'flickity', 'glide'),
                    'suggestions' => array('layerslider', 'masterslider', 'metaslider', 'soliloquy', 'royalslider', 'sliderpro', 'flickity', 'glide'),
                    'reason' => 'Known slider/carousel assets or markup were detected on this page. Keep matching runtime assets protected unless visually tested.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('elementor', 'elementor-widget', '/plugins/elementor/', '/plugins/elementor-pro/'),
                    'suggestions' => array('elementor', 'elementor-frontend', 'elementor-pro', 'frontend-modules', 'webpack.runtime'),
                    'reason' => 'Elementor assets or widgets were detected on this page. Keep core Elementor runtime dependencies protected unless dependency-safe testing passes.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('et_pb_', 'et-builder', 'et-core', '/themes/Divi/', '/plugins/divi-builder/'),
                    'suggestions' => array('divi', 'et-core', 'et-builder', 'et_pb', 'cmplz_activated_divi_recaptcha'),
                    'reason' => 'Divi builder assets or markup were detected on this page. Keep Divi runtime dependencies protected unless dependency-safe testing passes.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('js_composer', 'wpb_', 'vc_', 'wpbakery'),
                    'suggestions' => array('wpbakery', 'js_composer', 'vc_', 'wpb_'),
                    'reason' => 'WPBakery/Visual Composer assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('bricks', 'bricks-frontend', 'brxe-'),
                    'suggestions' => array('bricks', 'bricks-frontend', 'brxe-'),
                    'reason' => 'Bricks Builder assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('oxygen', 'ct-section', 'ct-div-block', 'ct-inner-content'),
                    'suggestions' => array('oxygen', 'ct-', 'oxy-'),
                    'reason' => 'Oxygen Builder assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('fl-builder', 'beaver-builder'),
                    'suggestions' => array('fl-builder', 'beaver-builder'),
                    'reason' => 'Beaver Builder assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('fusion-builder', 'avada-', 'fusion-'),
                    'suggestions' => array('fusion-builder', 'avada', 'fusion-'),
                    'reason' => 'Avada/Fusion Builder assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('thrive-', 'tcb-', 'tve_'),
                    'suggestions' => array('thrive', 'tcb-', 'tve_'),
                    'reason' => 'Thrive builder assets or markup were detected on this page. Keep builder runtime dependencies protected unless dependency-safe testing passes.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('seedprod', 'siteorigin', 'so-widget', 'uagb-', 'spectra', 'kadence', 'kt-', 'generateblocks', 'gb-'),
                    'suggestions' => array('seedprod', 'siteorigin', 'uagb', 'spectra', 'kadence', 'generateblocks'),
                    'reason' => 'Known block/page-builder assets or markup were detected on this page. Keep matching runtime assets protected unless visually tested.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('complianz', 'cmplz', 'complianz-gdpr', 'complianz-gdpr-premium'),
                    'suggestions' => array('complianz', 'cmplz', 'complianz-gdpr/cookiebanner/js/complianz.min.js', 'complianz-gdpr-premium/cookiebanner/js/complianz.min.js', 'complianz-gdpr-premium/pro/tcf/build/index.js', 'complianz-gdpr-premium/pro/tcf-stub/build/index.js'),
                    'reason' => 'Complianz consent assets were detected on this page. Consent/cookie scripts should stay out of Delay JS to avoid banner or consent-state issues.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('cookieyes', 'cookielawinfo', 'cky-', 'cookiebot', 'uc.js', 'iubenda', 'onetrust', 'optanon'),
                    'suggestions' => array('cookieyes', 'cookielawinfo', 'cky-', 'cookiebot', 'iubenda', 'onetrust', 'optanon'),
                    'reason' => 'Cookie/consent-management assets were detected on this page. Consent scripts are safer when excluded from Delay JS.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('google.com/recaptcha', 'gstatic.com/recaptcha', 'grecaptcha', 'hcaptcha', 'hcaptcha.com', 'turnstile', 'challenges.cloudflare.com', 'cf-turnstile'),
                    'suggestions' => array('google.com/recaptcha', 'gstatic.com/recaptcha', 'grecaptcha', 'hcaptcha', 'turnstile', 'challenges.cloudflare.com', 'cf-turnstile'),
                    'reason' => 'Captcha/anti-bot assets were detected on this page. These are commonly unsafe to delay because forms may need them immediately.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('contact-form-7', 'wpforms', 'gform', 'gravityforms', 'formidable', 'ninja-forms', 'fluentform', 'forminator', 'mailerlite', 'mailchimp', 'mc4wp', 'klaviyo', 'hubspot'),
                    'suggestions' => array('contact-form-7', 'wpforms', 'gform', 'gravityforms', 'formidable', 'ninja-forms', 'fluentform', 'forminator', 'mailerlite', 'validation-messages', 'mailchimp', 'mc4wp', 'klaviyo', 'hubspot'),
                    'reason' => 'Form, validation, newsletter, or CRM assets were detected on this page. Exclude matching form runtime assets if the form must work before interaction.',
                ),
                array(
                    'category' => 'detected-component-protection',
                    'label' => 'Detected component protections',
                    'confidence' => 'recommended',
                    'appendable' => true,
                    'markers' => array('js.stripe.com', 'stripe', 'paypal.com/sdk/js', 'paypal', 'braintree', 'klarna', 'afterpay', 'squareup', 'square-web-payments'),
                    'suggestions' => array('js.stripe.com', 'stripe', 'paypal.com/sdk/js', 'paypal', 'braintree', 'klarna', 'afterpay', 'square'),
                    'reason' => 'Payment gateway assets were detected on this page. Payment/checkout scripts are safer when excluded from Delay JS.',
                ),
                array(
                    'category' => 'review-only',
                    'label' => 'Review-only candidates',
                    'confidence' => 'review',
                    'appendable' => false,
                    'markers' => array('woocommerce', 'wc-', 'cart', 'checkout', 'account', 'add-to-cart', 'wc-cart-fragments'),
                    'suggestions' => array('woocommerce', 'wc-', 'cart', 'checkout', 'account', 'add-to-cart', 'wc-cart-fragments'),
                    'reason' => 'WooCommerce/cart/account markers were detected. Review these before excluding broadly because shop pages vary by site.',
                ),
                array(
                    'category' => 'review-only',
                    'label' => 'Review-only candidates',
                    'confidence' => 'review',
                    'appendable' => false,
                    'markers' => array('googletagmanager.com', 'google-analytics.com/analytics.js', 'gtag', 'gtm', 'dataLayer', 'adsbygoogle', 'doubleclick', 'facebook.net', 'fbevents', 'connect.facebook.net', 'tiktok', 'pinterest', 'hotjar', 'clarity', 'stats.wp.com', '_stq'),
                    'suggestions' => array('gtag', 'gtm', 'dataLayer', 'adsbygoogle', 'stats.wp.com/e-', '_stq', 'facebook.net', 'fbevents', 'hotjar', 'clarity'),
                    'reason' => 'Tracking/ads scripts were detected. These are review-only because delaying them often improves performance but may affect analytics/ads timing.',
                ),
            );

            foreach ($groups as $group) {
                if (!$this->js_delay_scan_html_has_any_marker($html, isset($group['markers']) && is_array($group['markers']) ? $group['markers'] : array())) {
                    continue;
                }

                foreach ((array) ($group['suggestions'] ?? array()) as $line) {
                    $line = trim((string) $line);
                    if ('' === $line) {
                        continue;
                    }
                    $category = (string) ($group['category'] ?? 'detected-component-protection');
                    if (!$this->js_delay_scan_html_has_any_marker($html, array($line))) {
                        continue;
                    }
                    $this->add_js_delay_component_recommendation(
                        $suggestions,
                        $seen,
                        $line,
                        $category,
                        (string) ($group['label'] ?? 'Detected component protections'),
                        (string) ($group['reason'] ?? ''),
                        $settings,
                        (string) ($group['confidence'] ?? 'recommended'),
                        '',
                        !empty($group['appendable'])
                    );
                }
            }

            return $suggestions;
        }


        private function collect_store_profile_js_delay_safety_scan($html)
        {
            $html = is_string($html) ? $html : (string) $html;
            $settings = $this->get_settings();
            $symbols = $this->collect_js_delay_safety_inline_symbols($html);
            $definitions = $this->collect_js_delay_safety_delayed_definitions($html);
            $suggestions = array();
            $seen = array();

            foreach ($symbols as $symbol => $reference) {
                if (empty($definitions[$symbol]) || !is_array($definitions[$symbol])) {
                    continue;
                }
                foreach ($definitions[$symbol] as $definition) {
                    $suggestion = (string) ($definition['suggestedExclusion'] ?? '');
                    $source = (string) ($reference['source'] ?? 'inline-script');
                    if ('' === trim($suggestion) || !$this->is_js_delay_safety_meaningful_symbol($symbol, $source)) {
                        continue;
                    }
                    $key = strtolower($symbol . '|' . $suggestion);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $reason = 'inline-event-handler' === $source
                        ? 'Inline event handler calls ' . $symbol . '(), but the script that defines it is delayed.'
                        : 'Inline script references global "' . $symbol . '", but the script that defines it is delayed.';
                    $already = $this->delay_safety_exclusion_already_matches($suggestion, $settings);
                    $suggestions[] = array(
                        'symbol' => $symbol,
                        'source' => $source,
                        'sample' => (string) ($reference['sample'] ?? ''),
                        'definingScriptUrl' => (string) ($definition['url'] ?? ''),
                        'definingHandle' => (string) ($definition['handle'] ?? ''),
                        'suggestedExclusion' => $suggestion,
                        'confidence' => 'high',
                        'reason' => $reason,
                        'alreadyExcluded' => (bool) $already,
                    );
                    if (count($suggestions) >= 20) {
                        break 2;
                    }
                }
            }

            foreach ($this->collect_js_delay_safety_targeted_suggestions($html, $settings) as $targeted_suggestion) {
                if (!is_array($targeted_suggestion)) {
                    continue;
                }
                $symbol = (string) ($targeted_suggestion['symbol'] ?? '');
                $suggestion = (string) ($targeted_suggestion['suggestedExclusion'] ?? '');
                $key = strtolower($symbol . '|' . $suggestion);
                if ('' === trim($suggestion) || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $suggestions[] = $targeted_suggestion;
                if (count($suggestions) >= 20) {
                    break;
                }
            }

            foreach ($this->collect_js_delay_component_recommendations($html, $settings) as $component_suggestion) {
                if (!is_array($component_suggestion)) {
                    continue;
                }
                $category = (string) ($component_suggestion['category'] ?? $component_suggestion['source'] ?? 'detected-component-protection');
                $suggestion = (string) ($component_suggestion['suggestedExclusion'] ?? '');
                $key = strtolower($category . '|' . $suggestion);
                if ('' === trim($suggestion) || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $suggestions[] = $component_suggestion;
                if (count($suggestions) >= 80) {
                    break;
                }
            }

            $missing = 0;
            foreach ($suggestions as $suggestion) {
                if (empty($suggestion['alreadyExcluded'])) {
                    $missing++;
                }
            }

            return array(
                'available' => true,
                'suggestion_count' => count($suggestions),
                'missing_count' => (int) $missing,
                'already_excluded_count' => count($suggestions) - (int) $missing,
                'suggestions' => $suggestions,
            );
        }

        private function collect_store_profile_critical_request_chain($html)
        {
            $html = is_string($html) ? $html : (string) $html;
            $settings = $this->get_settings();
            $head_end = stripos($html, '</head>');
            $head_end = false === $head_end ? -1 : (int) $head_end;
            $noscript_ranges = $this->get_html_tag_ranges_by_name($html, 'noscript');

            $styles = array();
            $scripts = array();
            $render_blocking_styles = 0;
            $render_blocking_scripts = 0;
            $delayed_scripts = 0;
            $protected_scripts = 0;
            $protected_styles = 0;

            if ('' !== $html && preg_match_all('/<link\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ((array) $matches[0] as $match) {
                    $tag = isset($match[0]) ? (string) $match[0] : '';
                    $offset = isset($match[1]) ? (int) $match[1] : 0;
                    if ($this->is_html_offset_inside_ranges($offset, $noscript_ranges)) {
                        continue;
                    }
                    if ('' === $tag || !$this->html_tag_rel_contains_stylesheet($tag)) {
                        continue;
                    }
                    $item = $this->get_style_critical_request_candidate($tag, $offset, $head_end, $settings);
                    if (empty($item)) {
                        continue;
                    }
                    if (!empty($item['renderBlocking'])) {
                        $render_blocking_styles++;
                    }
                    if (!empty($item['protected'])) {
                        $protected_styles++;
                    }
                    if (!empty($item['renderBlocking']) || !empty($item['protected']) || count($styles) < 20) {
                        $styles[] = $item;
                    }
                    if (count($styles) >= 40) {
                        break;
                    }
                }
            }

            if ('' !== $html && preg_match_all('/<script\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ((array) $matches[0] as $match) {
                    $tag = isset($match[0]) ? (string) $match[0] : '';
                    $offset = isset($match[1]) ? (int) $match[1] : 0;
                    if ('' === $tag) {
                        continue;
                    }
                    $item = $this->get_script_critical_request_candidate($tag, $offset, $head_end, $settings);
                    if (empty($item)) {
                        continue;
                    }
                    if (!empty($item['renderBlocking'])) {
                        $render_blocking_scripts++;
                    }
                    if (!empty($item['delayed'])) {
                        $delayed_scripts++;
                    }
                    if (!empty($item['protected'])) {
                        $protected_scripts++;
                    }
                    if (!empty($item['renderBlocking']) || !empty($item['protected']) || !empty($item['delayed']) || count($scripts) < 20) {
                        $scripts[] = $item;
                    }
                    if (count($scripts) >= 60) {
                        break;
                    }
                }
            }

            return array(
                'available' => true,
                'render_blocking_style_count' => (int) $render_blocking_styles,
                'render_blocking_script_count' => (int) $render_blocking_scripts,
                'delayed_script_count' => (int) $delayed_scripts,
                'protected_script_count' => (int) $protected_scripts,
                'protected_style_count' => (int) $protected_styles,
                'style_candidates' => array_slice($styles, 0, 40),
                'script_candidates' => array_slice($scripts, 0, 60),
            );
        }

        private function collect_store_profile_render_blocking_css_counts($html)
        {
            $html = is_string($html) ? $html : (string) $html;
            $result = array(
                'render_blocking_stylesheet_links' => 0,
                'render_blocking_css_bundle_links' => 0,
                'render_blocking_non_bundle_stylesheet_links' => 0,
                'render_blocking_stylesheet_hrefs' => array(),
                'render_blocking_non_bundle_stylesheet_hrefs' => array(),
            );

            $noscript_ranges = $this->get_html_tag_ranges_by_name($html, 'noscript');
            if ('' === $html || !preg_match_all('/<link\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
                return $result;
            }

            foreach ((array) $matches[0] as $match) {
                $tag_html = isset($match[0]) ? (string) $match[0] : '';
                $offset = isset($match[1]) ? (int) $match[1] : 0;
                if ($this->is_html_offset_inside_ranges($offset, $noscript_ranges)) {
                    continue;
                }
                if (!$this->html_tag_rel_contains_stylesheet($tag_html)) {
                    continue;
                }
                if (preg_match('/\b(?:disabled|onload|data-ucwp-async-css|data-ucwp-page-css-bundle-fallback)\b/i', $tag_html)) {
                    continue;
                }

                $media = strtolower(trim((string) $this->extract_attribute_from_html_tag($tag_html, 'media')));
                if (false !== strpos($media, 'print') || false !== strpos($media, 'speech')) {
                    continue;
                }

                $href = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag_html, 'href'), ENT_QUOTES | ENT_HTML5);
                $result['render_blocking_stylesheet_links']++;
                if ('' !== $href && count($result['render_blocking_stylesheet_hrefs']) < 20) {
                    $result['render_blocking_stylesheet_hrefs'][] = $href;
                }

                if (false !== stripos($href, '/cache/ultracache/css-bundles/')) {
                    $result['render_blocking_css_bundle_links']++;
                } else {
                    $result['render_blocking_non_bundle_stylesheet_links']++;
                    if ('' !== $href && count($result['render_blocking_non_bundle_stylesheet_hrefs']) < 20) {
                        $result['render_blocking_non_bundle_stylesheet_hrefs'][] = $href;
                    }
                }
            }

            return $result;
        }

        private function collect_store_profile_html_counts($html)
        {
            $html = is_string($html) ? $html : (string) $html;
            $render_blocking_css = $this->collect_store_profile_render_blocking_css_counts($html);

            return array_merge($render_blocking_css, array(
                'link_tags' => $this->count_store_profile_regex('/<link\b/i', $html),
                'stylesheet_links' => $this->count_store_profile_regex('/<link\b(?=[^>]*\brel\s*=)[^>]*stylesheet[^>]*>/i', $html),
                'script_tags' => $this->count_store_profile_regex('/<script\b/i', $html),
                'noscript_tags' => $this->count_store_profile_regex('/<noscript\b/i', $html),
                'fonts_googleapis_refs' => $this->count_store_profile_regex('/fonts\.googleapis\.com/i', $html),
                'local_google_fonts_refs' => $this->count_store_profile_regex('#cache/ultracache/google-fonts#i', $html),
                'css_bundle_refs' => $this->count_store_profile_regex('#cache/ultracache/css-bundles#i', $html),
                'page_css_bundle_markers' => $this->count_store_profile_regex('/\bdata-ucwp-page-css-bundle\s*=/i', $html),
                'page_css_bundle_external_links' => $this->count_store_profile_regex('/<link\b(?=[^>]*\bdata-ucwp-page-css-bundle\s*=)[^>]*>/i', $html),
                'page_css_bundle_inline_style_tags' => $this->count_store_profile_regex('/<style\b(?=[^>]*\bdata-ucwp-page-css-bundle\s*=)[^>]*>/i', $html),
                'page_css_bundle_inline_style_bytes' => $this->sum_store_profile_page_css_inline_bytes($html),
                'page_css_bundle_fallback_markers' => $this->count_store_profile_regex('/\bdata-ucwp-page-css-bundle-fallback\s*=/i', $html),
                'page_css_bundle_fallback_blocks' => $this->count_store_profile_regex('/\bdata-ucwp-page-css-bundle-fallback-block\s*=/i', $html),
                'page_css_bundle_fallback_links' => $this->count_store_profile_regex('/<link\b(?=[^>]*\bdata-ucwp-page-css-bundle-fallback\s*=)[^>]*>/i', $html),
                'leftover_css_bundle_refs' => $this->count_store_profile_regex('#cache/ultracache/css-bundles#i', $html),
                'leftover_css_bundle_markers' => $this->count_store_profile_regex('/\bdata-ucwp-leftover-css-bundle\s*=/i', $html),
                'frontpage_css_bundle_markers' => $this->count_store_profile_regex('/\bdata-ucwp-frontpage-css\s*=/i', $html),
                'async_css_markers' => $this->count_store_profile_regex('/\bdata-ucwp-async-css\s*=/i', $html),
                'async_css_fallback_markers' => $this->count_store_profile_regex('/\bdata-ucwp-async-css-fallback\s*=/i', $html),
                'delayed_js_markers' => $this->count_store_profile_regex('/text\/ucwp-delayed-js/i', $html),
                'data_ucwp_src_markers' => $this->count_store_profile_regex('/\bdata-ucwp-src\s*=/i', $html),
                'lcp_priority_markers' => $this->count_store_profile_regex('/\bfetchpriority\s*=\s*["\']high/i', $html),
            ));
        }

        private function defer_store_post_response_action($type, array $payload = array())
        {
            $this->deferred_store_post_response_actions[] = array(
                'type' => sanitize_key((string) $type),
                'payload' => $payload,
            );
        }

        public function run_deferred_store_post_response_actions()
        {
            if (empty($this->deferred_store_post_response_actions)) {
                return;
            }

            $actions = $this->deferred_store_post_response_actions;
            $this->deferred_store_post_response_actions = array();

            foreach ($actions as $action) {
                if (!is_array($action)) {
                    continue;
                }

                $type = isset($action['type']) ? sanitize_key((string) $action['type']) : '';
                $payload = isset($action['payload']) && is_array($action['payload']) ? $action['payload'] : array();

                if ('store_success' === $type) {
                    $url = isset($payload['url']) ? (string) $payload['url'] : '';
                    $file_path = isset($payload['file_path']) ? (string) $payload['file_path'] : '';

                    $this->record_analytics_store();
                    $this->record_cache_event('store', array('url' => $url, 'file' => $file_path));
                    $this->finalize_store_profile('STORE', '', $file_path);
                }
            }
        }
        public function update_store_profile_after_shutdown()
        {
            if (!$this->is_store_profiler_enabled() || empty($this->store_profile) || $this->store_profile_shutdown_written) {
                return;
            }

            $this->profile_request_checkpoint('engine_shutdown_profile_update');
            $now_for_profile = microtime(true);
            $request_start_for_profile = $this->get_store_profile_request_started_at();
            $total_request_ms = (int) round(max(0, $now_for_profile - $request_start_for_profile) * 1000);
            $total_ms = $this->store_profile_started_at > 0 ? (int) round(($now_for_profile - $this->store_profile_started_at) * 1000) : 0;
            $merged_checkpoints = $this->get_store_profile_request_checkpoints();

            $this->store_profile['total_request_duration_ms'] = $total_request_ms;
            $this->store_profile['shutdown_updated_at_utc'] = gmdate('c');
            $this->store_profile['shutdown_total_duration_ms'] = $total_ms;
            $this->store_profile['peak_memory_bytes'] = function_exists('memory_get_peak_usage') ? (int) memory_get_peak_usage(true) : (int) ($this->store_profile['peak_memory_bytes'] ?? 0);
            $this->store_profile['request_profile'] = array(
                'request_started_at_ms' => 0,
                'mode' => (function_exists('ucwp_request_profile_verbose_enabled') && ucwp_request_profile_verbose_enabled()) ? 'verbose' : 'compact',
                'total_request_duration_ms' => $total_request_ms,
                'unmeasured_before_store_profile_ms' => max(0, $total_request_ms - $total_ms),
                'checkpoints' => $merged_checkpoints,
                'phase_summary' => $this->get_store_profile_request_phase_summary($merged_checkpoints),
                'callback_timings' => $this->get_store_profile_callback_timings(),
                'callback_timing_summary' => $this->get_store_profile_callback_timing_summary(),
            );

            $dir = $this->get_store_profile_dir();
            if (!is_dir($dir)) {
                ucwp_safe_mkdir($dir, 0755, true);
            }

            if (is_dir($dir) && is_writable($dir)) {
                $json = wp_json_encode($this->store_profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($json) && '' !== $json) {
                    ucwp_safe_file_put_contents($this->get_store_profile_last_file(), $json, LOCK_EX, 'store_profile_shutdown_write');
                    $this->store_profile_shutdown_written = true;
                }
            }
        }

        private function finalize_store_profile($status, $reason = '', $file_path = '')
        {
            if (!$this->is_store_profiler_enabled() || empty($this->store_profile)) {
                return;
            }

            $this->profile_request_checkpoint('store_profile_finalize_start', array('status' => strtoupper((string) $status)));
            $now_for_profile = microtime(true);
            $request_start_for_profile = $this->get_store_profile_request_started_at();
            $total_request_ms = (int) round(max(0, $now_for_profile - $request_start_for_profile) * 1000);
            $total_ms = $this->store_profile_started_at > 0 ? (int) round(($now_for_profile - $this->store_profile_started_at) * 1000) : 0;
            $this->store_profile['status'] = strtoupper((string) $status);
            $this->store_profile['reason'] = (string) $reason;
            $this->store_profile['cache_file'] = (string) $file_path;
            $this->store_profile['total_duration_ms'] = $total_ms;
            $this->store_profile['peak_memory_bytes'] = function_exists('memory_get_peak_usage') ? (int) memory_get_peak_usage(true) : 0;
            $this->store_profile['finished_at_utc'] = gmdate('c');
            $this->store_profile['total_request_duration_ms'] = $total_request_ms;
            $merged_checkpoints = $this->get_store_profile_request_checkpoints();
            $this->store_profile['request_profile'] = array(
                'request_started_at_ms' => 0,
                'mode' => (function_exists('ucwp_request_profile_verbose_enabled') && ucwp_request_profile_verbose_enabled()) ? 'verbose' : 'compact',
                'total_request_duration_ms' => $total_request_ms,
                'unmeasured_before_store_profile_ms' => max(0, $total_request_ms - $total_ms),
                'checkpoints' => $merged_checkpoints,
                'phase_summary' => $this->get_store_profile_request_phase_summary($merged_checkpoints),
                'callback_timings' => $this->get_store_profile_callback_timings(),
                'callback_timing_summary' => $this->get_store_profile_callback_timing_summary(),
            );
            $this->store_profile['css_bundle_context_after'] = $this->get_store_profile_css_bundle_context();
            $critical_request_html = '';
            if ('' !== (string) $file_path && is_readable((string) $file_path)) {
                $critical_request_html = ucwp_safe_file_get_contents((string) $file_path);
            }
            $this->store_profile['critical_request_chain'] = $this->collect_store_profile_critical_request_chain(is_string($critical_request_html) ? $critical_request_html : '');
            $this->store_profile['js_delay_safety_scan'] = $this->collect_store_profile_js_delay_safety_scan(is_string($critical_request_html) ? $critical_request_html : '');

            $largest_delta = array('stage' => '', 'delta_bytes' => 0);
            $slowest = array('stage' => '', 'duration_ms' => 0);
            foreach ((array) ($this->store_profile['stages'] ?? array()) as $stage) {
                $delta = isset($stage['delta_bytes']) ? (int) $stage['delta_bytes'] : 0;
                $duration = isset($stage['duration_ms']) ? (int) $stage['duration_ms'] : 0;
                if ($delta > (int) $largest_delta['delta_bytes']) {
                    $largest_delta = array('stage' => (string) ($stage['stage'] ?? ''), 'delta_bytes' => $delta);
                }
                if ($duration > (int) $slowest['duration_ms']) {
                    $slowest = array('stage' => (string) ($stage['stage'] ?? ''), 'duration_ms' => $duration);
                }
            }
            $this->store_profile['largest_positive_delta'] = $largest_delta;
            $this->store_profile['slowest_stage'] = $slowest;

            $dir = $this->get_store_profile_dir();
            if (!is_dir($dir)) {
                ucwp_safe_mkdir($dir, 0755, true);
            }

            if (is_dir($dir) && is_writable($dir)) {
                $json = wp_json_encode($this->store_profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($json) && '' !== $json) {
                    ucwp_safe_file_put_contents($this->get_store_profile_last_file(), $json, LOCK_EX, 'store_profile_write');
                }
            }

            if (!headers_sent()) {
                header('X-Ultra-Cache-Store-Profile: saved');
                header('X-Ultra-Cache-Store-Profile-Id: ' . substr((string) ($this->store_profile['request_id'] ?? ''), 0, 40));
                header('X-Ultra-Cache-Store-Profile-Slowest: ' . substr((string) $slowest['stage'] . ':' . (string) $slowest['duration_ms'] . 'ms', 0, 120));
                header('X-Ultra-Cache-Store-Profile-Largest-Delta: ' . substr((string) $largest_delta['stage'] . ':' . (string) $largest_delta['delta_bytes'] . 'b', 0, 120));
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
            $this->profile_settings_request_checkpoint('engine_get_settings_start');
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
                $this->profile_settings_request_checkpoint('engine_get_settings_before_wp_static');
                $settings = Ultra_Cache_WP::get_settings();
                $this->profile_settings_request_checkpoint('engine_get_settings_after_wp_static', array(
                    'settings_count' => is_array($settings) ? count($settings) : 0,
                ));
                return $settings;
            }

            $this->profile_settings_request_checkpoint('engine_get_settings_before_raw_option');
            $saved = get_option(UCWP_SETTINGS_KEY, array());
            $settings = is_array($saved) ? $saved : array();
            $this->profile_settings_request_checkpoint('engine_get_settings_after_raw_option', array(
                'settings_count' => count($settings),
            ));
            return $settings;
        }

        public function defer_scripts($tag, $handle, $src)
        {
            $settings = $this->get_settings();
            if (is_admin()) {
                return $tag;
            }

            $defer_stage = $this->get_defer_stage_level($settings);
            $defer_all_js = !empty($settings['defer_js']) && !empty($settings['defer_all_js']);

            if (0 < $defer_stage && $this->is_script_absolute_defer_blocking($handle, $src, $tag, $settings)) {
                return $this->strip_native_loading_attributes_from_script_tag($tag);
            }

            if (0 < $defer_stage && $this->is_script_user_defer_excluded($handle, $src, $settings)) {
                return $this->strip_native_loading_attributes_from_script_tag($tag);
            }

            if (0 < $defer_stage && $this->is_script_user_force_deferred($handle, $src, $tag, $settings)) {
                return $this->add_defer_attribute_to_script_tag($tag, true);
            }

            if ($defer_all_js && $this->should_native_defer_all_local_script($src, $settings) && $this->is_defer_all_js_candidate($handle, $src, $tag, $settings)) {
                return $this->add_defer_attribute_to_script_tag($tag, false);
            }

            if (!$defer_all_js && 0 < $defer_stage && $this->is_script_force_blocking($handle, $src, $tag, $settings)) {
                return $this->strip_native_loading_attributes_from_script_tag($tag);
            }

            if (!$defer_all_js && 0 < $defer_stage && $this->is_script_safe_stage_excluded($handle, $src, $tag, $settings)) {
                return $this->strip_native_loading_attributes_from_script_tag($tag);
            }

            if (2 <= $defer_stage) {
                $third_party_delay_match = $this->get_third_party_delay_match($handle, $src, $tag, $settings);
                if (!empty($third_party_delay_match['matched'])) {
                    return $this->build_delayed_script_tag($tag, $handle, $src, $third_party_delay_match['reason']);
                }
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

            if ($defer_all_js && !$this->is_defer_all_js_candidate($handle, $src, $tag, $settings)) {
                return $tag;
            }

            return $this->add_defer_attribute_to_script_tag($tag, false);
        }

        private function should_keep_script_blocking_for_defer_all($handle, $src, $tag = '', array $settings = array())
        {
            return $this->is_script_absolute_defer_blocking($handle, $src, $tag, $settings)
                || $this->is_script_user_defer_excluded($handle, $src, $settings);
        }

        private function should_native_defer_all_local_script($src, array $settings = array())
        {
            /*
             * 2.56.122 regression guard: 2.56.120 bypassed the ordered
             * delayed-loader for every same-host script when Defer all JS was
             * enabled. That broke grouped inline-before / inline-after config
             * scripts for Complianz, Site Kit, WooCommerce and similar assets.
             * Keep this helper as a no-op so the dependency-aware ordered path
             * remains authoritative.
             */
            return false;
        }

        private function apply_defer_all_js_to_html($html, array $settings = array())
        {
            if (empty($settings['defer_js']) || empty($settings['defer_all_js']) || !is_string($html) || '' === $html || false === stripos($html, '<script')) {
                return $html;
            }

            $that = $this;
            $rewritten = preg_replace_callback('/<script\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1[^>]*>\s*<\/script>/is', static function ($matches) use ($that, $settings) {
                $tag = isset($matches[0]) ? (string) $matches[0] : '';
                if ('' === $tag) {
                    return $tag;
                }

                $handle = $that->extract_attribute_from_html_tag($tag, 'id');
                $src = $that->extract_attribute_from_html_tag($tag, 'src');

                if ($that->should_keep_script_blocking_for_defer_all($handle, $src, $tag, $settings)) {
                    return $that->strip_native_loading_attributes_from_script_tag($tag);
                }

                if (!$that->is_defer_all_js_candidate($handle, $src, $tag, $settings)) {
                    return $tag;
                }

                return $that->add_defer_attribute_to_script_tag($tag, false);
            }, $html);

            return is_string($rewritten) ? $rewritten : $html;
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

            $tag = $this->remove_html_tag_attribute($tag, 'async');
            $tag = $this->remove_html_tag_attribute($tag, 'defer');
            $tag = $this->remove_html_tag_attribute($tag, 'data-wp-strategy');
            $tag = preg_replace('/\s{2,}/', ' ', $tag);

            return is_string($tag) ? $tag : '';
        }

        private function normalize_protected_script_loading_attributes_in_html($html, array $settings = array())
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<script')) {
                return $html;
            }

            $processed = $this->normalize_protected_script_loading_attributes_with_processor($html, $settings);
            if (is_string($processed)) {
                return $processed;
            }

            $that = $this;
            $defer_all_js = !empty($settings['defer_js']) && !empty($settings['defer_all_js']);
            $rewritten = preg_replace_callback('/<script\b[^>]*>/i', static function ($matches) use ($that, $settings, $defer_all_js) {
                $tag = isset($matches[0]) ? (string) $matches[0] : '';
                if ('' === $tag) {
                    return $tag;
                }

                if (false === stripos($tag, ' async') && false === stripos($tag, ' defer') && false === stripos($tag, 'data-wp-strategy=')) {
                    return $tag;
                }

                $handle = $that->extract_attribute_from_html_tag($tag, 'id');
                $src = $that->extract_attribute_from_html_tag($tag, 'src');

                if ($that->should_keep_script_blocking_for_defer_all($handle, $src, $tag, $settings)
                    || (!$defer_all_js && $that->is_script_force_blocking($handle, $src, $tag, $settings))) {
                    return $that->strip_native_loading_attributes_from_script_tag($tag);
                }

                if ($that->is_script_user_force_deferred($handle, $src, $tag, $settings)) {
                    return $that->add_defer_attribute_to_script_tag($tag, true);
                }

                return $tag;
            }, $html);

            return is_string($rewritten) ? $rewritten : $html;
        }

        private function normalize_protected_script_loading_attributes_with_processor($html, array $settings = array())
        {
            if (!$this->html_tag_processor_available()) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor((string) $html);
                $changed = false;
                $defer_all_js = !empty($settings['defer_js']) && !empty($settings['defer_all_js']);

                while ($processor->next_tag('SCRIPT')) {
                    $async = $processor->get_attribute('async');
                    $defer = $processor->get_attribute('defer');
                    $strategy = $processor->get_attribute('data-wp-strategy');
                    if (null === $async && null === $defer && null === $strategy) {
                        continue;
                    }

                    $handle = $processor->get_attribute('id');
                    $src = $processor->get_attribute('src');
                    $handle = (null === $handle || false === $handle) ? '' : html_entity_decode((string) $handle, ENT_QUOTES, 'UTF-8');
                    $src = (null === $src || false === $src) ? '' : html_entity_decode((string) $src, ENT_QUOTES, 'UTF-8');
                    $tag = $this->get_current_html_processor_tag_markup($processor, 'script');

                    if ($this->should_keep_script_blocking_for_defer_all($handle, $src, $tag, $settings)
                        || (!$defer_all_js && $this->is_script_force_blocking($handle, $src, $tag, $settings))) {
                        $processor->remove_attribute('async');
                        $processor->remove_attribute('defer');
                        $processor->remove_attribute('data-wp-strategy');
                        $changed = true;
                        continue;
                    }

                    if ($this->is_script_user_force_deferred($handle, $src, $tag, $settings)) {
                        $processor->remove_attribute('async');
                        $processor->remove_attribute('data-wp-strategy');
                        $processor->set_attribute('defer', 'defer');
                        $changed = true;
                        continue;
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
                $tag = $this->remove_html_tag_attribute($tag, 'async');
                $tag = $this->remove_html_tag_attribute($tag, 'data-wp-strategy');
                $tag = preg_replace('/\s{2,}/', ' ', $tag);
            }

            if (false !== stripos($tag, ' defer')) {
                return $tag;
            }

            return $this->set_or_add_html_tag_attribute($tag, 'defer', 'defer');
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
                $tag = $this->remove_html_tag_attribute($tag, 'defer');
            }

            return $this->set_or_add_html_tag_attribute($tag, 'async', 'async');
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

        private function is_defer_all_js_candidate($handle, $src, $tag = '', array $settings = array())
        {
            $src = trim((string) $src);
            $tag = (string) $tag;

            if ('' === $src || false === stripos($tag, '<script')) {
                return false;
            }

            if (false !== stripos($tag, ' async') || false !== stripos($tag, ' type="module"') || false !== stripos($tag, " type='module'") || false !== stripos($tag, ' nomodule')) {
                return false;
            }

            if (false !== stripos($tag, ' defer')) {
                return false;
            }

            return true;
        }

        private function is_script_absolute_defer_blocking($handle, $src, $tag = '', array $settings = array())
        {
            $handle = (string) $handle;
            $src    = (string) $src;
            $tag    = (string) $tag;

            $handle_lc = strtolower($handle);
            $src_lc    = strtolower($src);
            $tag_lc    = strtolower($tag);
            $haystack  = $handle_lc . ' ' . $src_lc . ' ' . $tag_lc;

            $absolute_patterns = array(
                'jquery',
                'jquery-core',
                'jquery-migrate',
                'wp-hooks',
                'wp-i18n',
                'wp-util',
                'wp-api',
                'api-fetch',
                'underscore',
                'backbone',
                'heartbeat',
                'wp-dom-ready',
                'wp-a11y',
                'wp-components',
                'wp-element',
                'wp-data',
                'wp-compose',
            );

            foreach ($absolute_patterns as $pattern) {
                if (false !== strpos($haystack, $pattern)) {
                    return true;
                }
            }

            return false;
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
                'elementor',
                'elementor-frontend',
                'elementor-frontend-modules',
                'frontend-modules',
                'elementor-webpack-runtime',
                'elementor-pro-webpack-runtime',
                'pro-elements-handlers',
                'swiper',
                'swiper-bundle',            );

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

            $list = array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            })));

            // The visible JS Delay / Defer Exclusions list is the user's final
            // override for aggressive Defer all JS. Never strip legacy-looking
            // fragments here; if the user adds validation-messages.js, sr7,
            // elementor, or any other broad line, it must remain effective.
            return $list;
        }

        private function get_defer_all_js_legacy_conservative_exclude_fragments()
        {
            return array(
                'official-mailerlite-sign-up-forms/assets/js/localization/validation-messages.js',
                'revslider',
                'sliderrevolution',
                'slider-revolution',
                'revolution',
                'sr7',
                'rs6',
                'rs7',
                'tptools',
                'tp-tools',
                'rs-module',
                'wp-block-themepunch-revslider',
                'swiper',
                'swiper-bundle',
                'slick',
                'splide',
                'owl.carousel',
                'smartslider',
                'smart-slider',
                'n2-ss',
                'elementor',
                'elementor-frontend',
                'frontend-modules',
                'webpack.runtime',
                'webpack-pro.runtime',
                'pro-elements-handlers',
                'smartmenus',
                'html_types/image',
                'html_types/color',
                'html_types/label',
                'html_types/slide',
                'html_types/slider',
                'product-ajax-search',
                'search-popup',
                'nav-mobile',
                'megamenu',
                'header-cart',
                'cart-canvas',
                'off-canvas',
                'woocommerce-products-filter',
                'woof_',
                'contact-form-7',
                'author-arc',
                'typewriting-author-arc/assets/js/form-handler.js',
                'mailerlite',
                'mailchimp',
                'mc4wp',
                'complianz',
                'cmplz',
                'cky-',
            );
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

        private function get_slider_hero_protected_fragments()
        {
            $fragments = array(
                'revslider',
                'sliderrevolution',
                'slider-revolution',
                'revolution',
                'sr7',
                'rs6',
                'rs7',
                'tptools',
                'tp-tools',
                '/plugins/revslider/',
                '/plugins/slider-revolution/',
                '/wp-content/uploads/revslider/',
                'wp-block-themepunch-revslider',
                'swiper',
                'swiper-bundle',
                'swiper-container',
                'swiper-wrapper',
                'slick',
                'slick-slider',
                'splide',
                'splide__track',
                'owl.carousel',
                'owl-carousel',
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
                'elementor-widget-slides',
                'elementor-widget-image-carousel',
                'elementor-widget-media-carousel',
                // Keep URL/handle protection strict. Generic words such as "slider", "carousel",
                // "slideshow" and "hero" cause false positives for non-hero assets such as
                // product-filter range sliders. Broad generic markers are used only for markup
                // detection in get_slider_hero_markup_markers().
            );

            $filtered = apply_filters('ucwp_slider_hero_protected_fragments', $fragments);
            if (is_array($filtered)) {
                $fragments = $filtered;
            }

            return array_values(array_unique(array_filter(array_map('strval', $fragments), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function get_slider_hero_protected_script_handles()
        {
            $handles = array(
                'revslider',
                'sr7',
                'tptools',
                'tp-tools',
                'rs6',
                'rs7',
                'slider-revolution',
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
            );

            $filtered = apply_filters('ucwp_slider_hero_protected_script_handles', $handles);
            if (is_array($filtered)) {
                $handles = $filtered;
            }

            return array_values(array_unique(array_filter(array_map('strval', $handles), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function get_slider_hero_markup_markers()
        {
            return array_values(array_unique(array_merge(
                $this->get_slider_hero_protected_fragments(),
                array(
                    'slider',
                    'carousel',
                    'slideshow',
                    'hero',
                    'hero-slider',
                    'main-hero',
                    'homepage-slider',
                )
            )));
        }

        private function get_builtin_defer_js_exclude_fragments()
        {
            $fragments = array_merge(
                array(
                    'googlesitekit',
                    'google-site-kit',
                    'sitekit',
                    'elementor/assets/js/frontend',
                    'elementor-pro/assets/js/frontend',
                    'elementor-frontend',
                    'elementor-pro-frontend',
                    'frontend-modules',
                    'header-footer-elementor',
                    'hfe-',
                    'smartmenus',
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
                    'elementor-frontend.js',
                ),
                $this->get_slider_hero_protected_fragments()
            );

            return array_values(array_unique(array_filter(array_map('strval', $fragments), static function ($item) {
                return '' !== trim((string) $item);
            })));
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
            $handles = array_merge(
                array(
                    'elementor-frontend-js',
                    'elementor-pro-frontend-js',
                    'elementor-frontend-modules-js',
                    'pro-elements-handlers-js',
                    'hfe-frontend-js-js',
                    'smartmenus-js',
                ),
                $this->get_slider_hero_protected_script_handles()
            );

            return array_values(array_unique(array_filter(array_map('strval', $handles), static function ($item) {
                return '' !== trim((string) $item);
            })));
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
            $fragments = array_merge(array(
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
                'elementor-frontend',
                'elementor-frontend.js',
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
            ), $this->get_slider_hero_protected_fragments());

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

            if ($this->should_native_defer_all_local_script($src, $settings)) {
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
            $match = $this->get_third_party_delay_match($handle, $src, $tag, $settings);
            return !empty($match['matched']);
        }

        private function get_third_party_delay_match($handle, $src, $tag, array $settings = array())
        {
            $src = trim((string) $src);
            $tag = (string) $tag;

            if ('' === $src || false === stripos($tag, '<script')) {
                return array('matched' => false);
            }

            if (!$this->is_delayable_external_script_tag($tag)) {
                return array('matched' => false);
            }

            if (false !== stripos($tag, 'type="text/ucwp-delayed-js"') || false !== stripos($tag, "type='text/ucwp-delayed-js'") || false !== stripos($tag, 'data-ucwp-src=')) {
                return array('matched' => false);
            }
            if ($this->is_third_party_delay_excluded($handle, $src, $settings)) {
                return array('matched' => false, 'reason' => 'excluded');
            }

            if ($this->should_native_defer_all_local_script($src, $settings)) {
                return array('matched' => false, 'reason' => 'native-defer-all-local');
            }

            if ($this->is_third_party_delay_dependency_library($handle, $src, $tag)) {
                return array('matched' => false, 'reason' => 'dependency-library');
            }

            if (!empty($settings['delay_safe_third_party_js'])) {
                $safe_pattern = $this->get_matching_third_party_delay_pattern($handle, $src, $tag, $this->get_safe_third_party_delay_patterns($settings));
                if ('' !== $safe_pattern) {
                    return array(
                        'matched' => true,
                        'category' => 'safe-third-party',
                        'reason' => 'safe-third-party',
                        'matched_pattern' => $safe_pattern,
                    );
                }
            }

            if (!empty($settings['delay_functional_third_party_js'])) {
                $functional_pattern = $this->get_matching_third_party_delay_pattern($handle, $src, $tag, $this->get_functional_third_party_delay_patterns($settings));
                if ('' !== $functional_pattern) {
                    return array(
                        'matched' => true,
                        'category' => 'functional-third-party',
                        'reason' => 'functional-third-party',
                        'matched_pattern' => $functional_pattern,
                    );
                }
            }

            return array('matched' => false);
        }

        private function get_inline_third_party_delay_match($handle, $tag, array $settings = array())
        {
            $tag = (string) $tag;
            if ('' === $tag || false === stripos($tag, '<script')) {
                return array('matched' => false);
            }

            if (!$this->is_delayable_inline_script_tag($tag)) {
                return array('matched' => false);
            }

            $handle = (string) $handle;
            $haystacks = array(
                strtolower(trim($handle)),
                strtolower($tag),
            );

            if (!empty($settings['delay_safe_third_party_js'])) {
                foreach ($this->get_safe_third_party_delay_patterns($settings) as $pattern) {
                    $pattern = strtolower(trim((string) $pattern));
                    if ('' === $pattern) {
                        continue;
                    }
                    foreach ($haystacks as $haystack) {
                        if ('' !== $haystack && false !== strpos($haystack, $pattern)) {
                            return array(
                                'matched' => true,
                                'category' => 'safe-third-party',
                                'reason' => 'safe-third-party',
                                'matched_pattern' => $pattern,
                            );
                        }
                    }
                }

                $safe_inline_markers = array(
                    'gtag(',
                    'dataLayer',
                    'gtm.start',
                    'googletagmanager.com/gtm.js',
                    'googletagmanager.com/gtag/js',
                    'google-analytics.com',
                    'fbq(',
                    'connect.facebook.net',
                    'pintrk(',
                    'clarity(',
                    'hotjar',
                );

                foreach ($safe_inline_markers as $marker) {
                    foreach ($haystacks as $haystack) {
                        if ('' !== $haystack && false !== strpos($haystack, strtolower($marker))) {
                            return array(
                                'matched' => true,
                                'category' => 'safe-third-party',
                                'reason' => 'safe-third-party',
                                'matched_pattern' => strtolower($marker),
                            );
                        }
                    }
                }
            }

            if (!empty($settings['delay_functional_third_party_js'])) {
                foreach ($this->get_functional_third_party_delay_patterns($settings) as $pattern) {
                    $pattern = strtolower(trim((string) $pattern));
                    if ('' === $pattern) {
                        continue;
                    }
                    foreach ($haystacks as $haystack) {
                        if ('' !== $haystack && false !== strpos($haystack, $pattern)) {
                            return array(
                                'matched' => true,
                                'category' => 'functional-third-party',
                                'reason' => 'functional-third-party',
                                'matched_pattern' => $pattern,
                            );
                        }
                    }
                }
            }

            return array('matched' => false);
        }
        private function get_safe_third_party_delay_patterns(array $settings = array())
        {
            if (isset($settings['delay_safe_third_party_js_patterns']) && is_array($settings['delay_safe_third_party_js_patterns'])) {
                return array_values(array_unique(array_filter(array_map('strval', $settings['delay_safe_third_party_js_patterns']), static function ($item) {
                    return '' !== trim((string) $item);
                })));
            }

            return array();
        }

        private function get_functional_third_party_delay_patterns(array $settings = array())
        {
            if (isset($settings['delay_functional_third_party_js_patterns']) && is_array($settings['delay_functional_third_party_js_patterns'])) {
                return array_values(array_unique(array_filter(array_map('strval', $settings['delay_functional_third_party_js_patterns']), static function ($item) {
                    return '' !== trim((string) $item);
                })));
            }

            return array();
        }

        private function get_third_party_delay_exclude_fragments(array $settings = array())
        {
            $list = $this->get_defer_stage_user_exclude_fragments($settings);

            if (isset($settings['delay_third_party_js_exclude_list']) && is_array($settings['delay_third_party_js_exclude_list'])) {
                $list = array_merge($list, $settings['delay_third_party_js_exclude_list']);
            }

            return array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            })));
        }

        private function is_third_party_delay_excluded($handle, $src, array $settings = array())
        {
            return $this->script_matches_fragment_list($handle, $src, $this->get_third_party_delay_exclude_fragments($settings));
        }

        private function is_third_party_delay_dependency_library($handle, $src, $tag = '')
        {
            $src_path = strtolower((string) wp_parse_url((string) $src, PHP_URL_PATH));
            $haystack = strtolower(trim((string) $handle . ' ' . (string) $src . ' ' . $src_path . ' ' . (string) $tag));
            if ('' === $haystack) {
                return false;
            }

            $dependency_patterns = array(
                'js-cookie',
                'js.cookie',
                'jquery.cookie',
                '/sourcebuster',
                'sourcebuster-js',
                'wc-order-attribution',
                'order-attribution',
                'wc-cart-fragments',
                'cart-fragments',
            );

            foreach ($dependency_patterns as $pattern) {
                if (false !== strpos($haystack, $pattern)) {
                    return true;
                }
            }

            return false;
        }

        private function get_matching_third_party_delay_pattern($handle, $src, $tag, array $patterns)
        {
            $haystacks = array(
                strtolower(trim((string) $handle)),
                strtolower(trim((string) $src)),
                strtolower((string) wp_parse_url((string) $src, PHP_URL_HOST)),
                strtolower((string) wp_parse_url((string) $src, PHP_URL_PATH)),
                strtolower((string) $tag),
            );

            foreach ($patterns as $pattern) {
                $pattern = strtolower(trim((string) $pattern));
                if ('' === $pattern) {
                    continue;
                }

                foreach ($haystacks as $haystack) {
                    if ('' !== $haystack && false !== strpos($haystack, $pattern)) {
                        return $pattern;
                    }
                }
            }

            return '';
        }

        private function build_delayed_script_tag($tag, $handle, $src, $reason = '')
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

            $delayed_src = $this->absolutize_public_resource_url($src, home_url('/'));
            if ('' === $delayed_src) {
                $delayed_src = (string) $src;
            }

            $attributes = array(
                'type'                   => 'text/ucwp-delayed-js',
                'data-ucwp-src'          => esc_url($delayed_src),
                'data-ucwp-original-src' => esc_attr((string) $src),
                'data-ucwp-handle'       => esc_attr((string) $handle),
            );

            $reason = sanitize_key((string) $reason);
            if ('' !== $reason) {
                $attributes['data-ucwp-delay-reason'] = esc_attr($reason);
            }

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

        private function normalize_delayed_script_group_handle($handle)
        {
            $handle = strtolower(trim((string) $handle));
            if ('' === $handle) {
                return '';
            }

            $handle = preg_replace('/-js(?:-extra|-before|-after)?$/', '', $handle);
            $handle = preg_replace('/-(?:extra|before|after)$/', '', (string) $handle);
            $handle = preg_replace('/\.min\.js$|\.js$/', '', (string) $handle);

            return is_string($handle) ? trim($handle) : '';
        }

        private function is_delayable_inline_script_tag($tag)
        {
            $tag = (string) $tag;
            if ('' === $tag || false === stripos($tag, '<script')) {
                return false;
            }

            if (false !== stripos($tag, ' src=') || false !== stripos($tag, ' data-ucwp-src=') || false !== stripos($tag, 'text/ucwp-delayed-js')) {
                return false;
            }

            $type = strtolower(trim((string) $this->extract_attribute_from_html_tag($tag, 'type')));
            if ('' !== $type && !$this->is_javascript_mime_type($type)) {
                return false;
            }

            $code = trim((string) preg_replace('/^<script\b[^>]*>|<\/script>$/is', '', $tag));
            if ('' === $code) {
                return false;
            }

            if (false !== stripos($code, '__ucwpDelayLoader') || false !== stripos($code, 'wp-emoji-settings') || false !== stripos($code, '_wpemojiSettings')) {
                return false;
            }

            return true;
        }

        private function build_delayed_inline_script_tag($tag, $handle, $reason = '')
        {
            $tag = (string) $tag;
            if ('' === $tag || !preg_match('/^<script\b[^>]*>(.*?)<\/script>$/is', $tag, $content_match)) {
                return $tag;
            }

            $content = isset($content_match[1]) ? (string) $content_match[1] : '';
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
                'data-ucwp-inline' => '1',
                'data-ucwp-handle' => esc_attr((string) $handle),
            );

            $reason = sanitize_key((string) $reason);
            if ('' !== $reason) {
                $attributes['data-ucwp-delay-reason'] = esc_attr($reason);
            }

            if (!empty($preserved_attributes)) {
                $encoded = base64_encode((string) wp_json_encode($preserved_attributes));
                if ('' !== $encoded) {
                    $attributes['data-ucwp-attrs'] = esc_attr($encoded);
                }
            }

            foreach (array('id', 'nonce') as $attribute) {
                if (isset($preserved_attributes[$attribute]) && '' !== $preserved_attributes[$attribute]) {
                    $attributes['data-ucwp-' . $attribute] = esc_attr($preserved_attributes[$attribute]);
                }
            }

            $compiled = array();
            foreach ($attributes as $name => $value) {
                $compiled[] = sprintf('%s="%s"', $name, $value);
            }

            return '<script ' . implode(' ', $compiled) . '>' . $content . '</script>';
        }

        private function extract_html_tag_attributes($tag)
        {
            $attributes = array();
            $tag = (string) $tag;
            if ('' === $tag || false === strpos($tag, '<')) {
                return $attributes;
            }

            $processed = $this->extract_html_tag_attributes_with_processor($tag);
            if (is_array($processed)) {
                return $processed;
            }

            $inside = preg_replace('/^\s*<[a-zA-Z][a-zA-Z0-9:-]*\b/i', '', $tag, 1);
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

        private function extract_html_tag_attributes_with_processor($tag)
        {
            if (!$this->html_tag_processor_available()) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor((string) $tag);
                if (!$processor->next_tag()) {
                    return null;
                }

                $attributes = array();
                $tag_markup = $this->get_current_html_processor_tag_markup($processor, (string) $processor->get_tag());
                if (preg_match_all('/\s+([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/i', $tag_markup, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        if (empty($match[1])) {
                            continue;
                        }

                        $name = strtolower((string) $match[1]);
                        $value = $processor->get_attribute($name);
                        if (true === $value) {
                            $attributes[$name] = $name;
                        } elseif (false === $value || null === $value) {
                            $attributes[$name] = '';
                        } else {
                            $attributes[$name] = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
                        }
                    }
                }

                return $attributes;
            } catch (\Throwable $e) {
                return null;
            }
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
            if ((empty($settings['delay_safe_third_party_js']) && empty($settings['delay_functional_third_party_js']) && empty($settings['delay_non_critical_js']) && empty($settings['lcp_boundary_defer'])) || is_admin()) {
                return;
            }

            $main_thread_relief = !empty($settings['main_thread_relief']) ? '1' : '0';
            $loader = <<<'UCWP_DELAY_LOADER'
<script id="ucwp-delayed-loader">(function(){if(window.__ucwpDelayLoader){return;}window.__ucwpDelayLoader=1;var relief=__UCWP_RELIEF__;var timeoutMs=8000;var regularAutoDone=false;var safeAutoDone=false;var allDone=false;function qa(){return Array.prototype.slice.call(document.querySelectorAll('script[type="text/ucwp-delayed-js"][data-ucwp-src],script[type="text/ucwp-delayed-js"][data-ucwp-inline="1"]'));}function c(n,a){var v=n.getAttribute('data-ucwp-'+a);return v||'';}function isSafe(n){return c(n,'delay-reason')==='safe-third-party';}function q(mode){return qa().filter(function(n){if(!n||n.getAttribute('data-ucwp-loading')==='1'){return false;}if(mode==='safe'){return isSafe(n);}if(mode==='regular'){return !isSafe(n);}return true;});}function decodeAttrs(node){var raw=c(node,'attrs');var attrs={};if(raw){try{attrs=JSON.parse(atob(raw))||{};}catch(e){attrs={};}}['id','crossorigin','referrerpolicy','integrity','nonce'].forEach(function(attr){var val=c(node,attr);if(val&&!attrs[attr]){attrs[attr]=val;}});return attrs;}function applyAttrs(s,node){var attrs=decodeAttrs(node);Object.keys(attrs).forEach(function(attr){var val=attrs[attr];if(!attr||attr==='src'||attr==='async'||attr==='defer'||attr==='data-wp-strategy'||val===null||typeof val==='undefined'){return;}try{s.setAttribute(attr,String(val));}catch(e){}});}function idle(cb){if(!relief){cb();return;}if('requestIdleCallback' in window){window.requestIdleCallback(cb,{timeout:1500});return;}setTimeout(cb,80);}function wait(ms,cb){if(!relief||ms<=0){cb();return;}setTimeout(cb,ms);}function insertAndRemove(node,s){if(node.parentNode){node.parentNode.insertBefore(s,node);node.parentNode.removeChild(node);}else{document.head.appendChild(s);}}function loadOne(node,done){if(!node||node.getAttribute('data-ucwp-loading')==='1'){done();return;}node.setAttribute('data-ucwp-loading','1');var isInline=node.getAttribute('data-ucwp-inline')==='1';var src=node.getAttribute('data-ucwp-src');var s=document.createElement('script');applyAttrs(s,node);s.async=false;if(isInline){try{s.text=node.textContent||'';}catch(e){s.text='';}insertAndRemove(node,s);done();return;}if(!src){done();return;}var finished=false;function finish(){if(finished){return;}finished=true;done();}s.onload=finish;s.onerror=finish;setTimeout(finish,timeoutMs);s.src=src;insertAndRemove(node,s);}function load(list,i){if(i>=list.length){return;}idle(function(){loadOne(list[i],function(){wait(relief?120:0,function(){load(list,i+1);});});});}function run(mode){var list=q(mode);if(!list.length){return;}load(list,0);}function triggerAll(){if(allDone){return;}allDone=true;regularAutoDone=true;safeAutoDone=true;run('all');}function triggerRegular(){if(allDone||regularAutoDone){return;}regularAutoDone=true;run('regular');}function triggerSafe(){if(allDone||safeAutoDone){return;}safeAutoDone=true;run('safe');}['scroll','mousemove','touchstart','keydown','click','pointerdown'].forEach(function(evt){window.addEventListener(evt,triggerAll,{passive:true,once:true});});window.addEventListener('load',function(){setTimeout(triggerRegular,relief?2500:2000);setTimeout(triggerSafe,relief?25000:22000);},{once:true});setTimeout(triggerRegular,relief?7000:6000);setTimeout(triggerSafe,relief?30000:26000);}());</script>
UCWP_DELAY_LOADER;
            echo str_replace('__UCWP_RELIEF__', $main_thread_relief, $loader) . "\n";
        }

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



        private function apply_html_rewrite_safely($html, $label, callable $callback, $profile = true)
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            if ($profile && $this->is_store_profiler_enabled()) {
                return $this->profile_store_stage($label, $html, function ($html) use ($label, $callback) {
                    return $this->apply_html_rewrite_safely($html, $label, $callback, false);
                });
            }

            $original = $html;
            try {
                $candidate = $callback($html);
            } catch (\Throwable $e) {
                $this->record_html_rewrite_safety_bailout($label, 'exception');
                return $original;
            }

            if (!is_string($candidate)) {
                $this->record_html_rewrite_safety_bailout($label, 'non-string');
                return $original;
            }

            if (!$this->is_safe_html_rewrite_result($original, $candidate)) {
                $this->record_html_rewrite_safety_bailout($label, 'invalid-html-shape');
                return $original;
            }

            return $candidate;
        }

        private function apply_html_array_rewrite_safely($html, $label, callable $callback, $profile = true)
        {
            $result = array(
                'html' => $html,
                'safe' => true,
            );

            if (!is_string($html) || '' === $html) {
                return $result;
            }

            if ($profile && $this->is_store_profiler_enabled()) {
                $before = $html;
                $before_bytes = strlen($before);
                $start = microtime(true);
                $candidate = $this->apply_html_array_rewrite_safely($html, $label, $callback, false);
                $duration_ms = (int) round((microtime(true) - $start) * 1000);
                $candidate_html = isset($candidate['html']) && is_string($candidate['html']) ? $candidate['html'] : $html;
                $after_bytes = strlen($candidate_html);
                $this->store_profile['stages'][] = array_merge(array(
                    'stage' => sanitize_key((string) $label),
                    'bytes_in' => (int) $before_bytes,
                    'bytes_out' => (int) $after_bytes,
                    'delta_bytes' => (int) ($after_bytes - $before_bytes),
                    'duration_ms' => $duration_ms,
                    'safe' => !empty($candidate['safe']) ? 'true' : 'false',
                ), $this->collect_store_profile_html_counts($candidate_html));
                return $candidate;
            }

            try {
                $candidate = $callback($html);
            } catch (\Throwable $e) {
                $this->record_html_rewrite_safety_bailout($label, 'exception');
                $result['safe'] = false;
                return $result;
            }

            if (!is_array($candidate)) {
                $this->record_html_rewrite_safety_bailout($label, 'non-array');
                $result['safe'] = false;
                return $result;
            }

            $candidate_html = isset($candidate['html']) && is_string($candidate['html']) ? $candidate['html'] : $html;
            if (!$this->is_safe_html_rewrite_result($html, $candidate_html)) {
                $this->record_html_rewrite_safety_bailout($label, 'invalid-html-shape');
                $result['safe'] = false;
                return $result;
            }

            $candidate['html'] = $candidate_html;
            $candidate['safe'] = true;
            return $candidate;
        }

        private function is_safe_html_rewrite_result($original, $candidate)
        {
            if (!is_string($original) || !is_string($candidate)) {
                return false;
            }

            if ('' === $candidate) {
                return '' === $original;
            }

            $original_trimmed = trim($original);
            $candidate_trimmed = trim($candidate);
            if ('' !== $original_trimmed && '' === $candidate_trimmed) {
                return false;
            }

            $original_length = strlen($original);
            $candidate_length = strlen($candidate);
            if ($original_length > 1000 && $candidate_length < max(250, (int) floor($original_length * 0.25))) {
                return false;
            }

            foreach (array('</head>', '<body', '</body>', '</html>') as $marker) {
                if (false !== stripos($original, $marker) && false === stripos($candidate, $marker)) {
                    return false;
                }
            }

            if (false !== stripos($original, '<head') && false === stripos($candidate, '<head')) {
                return false;
            }

            $original_lt = substr_count($original, '<');
            $candidate_lt = substr_count($candidate, '<');
            if ($original_lt > 20 && $candidate_lt < (int) floor($original_lt * 0.35)) {
                return false;
            }

            return true;
        }

        private function record_html_rewrite_safety_bailout($label, $reason)
        {
            $label = sanitize_key((string) $label);
            $reason = sanitize_key((string) $reason);
            if ('' === $label) {
                $label = 'unknown';
            }
            if ('' === $reason) {
                $reason = 'unknown';
            }

            $analytics = self::read_analytics();
            if (!is_array($analytics)) {
                $analytics = array();
            }
            $analytics['htmlRewriteSafetyBailouts'] = isset($analytics['htmlRewriteSafetyBailouts']) ? (int) $analytics['htmlRewriteSafetyBailouts'] + 1 : 1;
            $analytics['htmlRewriteLastBailout'] = array(
                'label' => $label,
                'reason' => $reason,
                'time' => current_time('timestamp'),
                'time_mysql' => current_time('mysql'),
            );
            self::write_analytics($analytics);
        }

        private function get_frontend_rewrite_profile($html, array $settings = array())
        {
            $frontend_safe_mode = !empty($settings['frontend_safe_mode']);
            $slider_safe_mode = !empty($settings['slider_safe_mode']) && $this->should_use_safe_frontend_optimization_mode($html);
            $safe_mode = $frontend_safe_mode || $slider_safe_mode;

            $reason = '';
            if ($slider_safe_mode) {
                $reason = 'slider-hero-detected';
            } elseif ($frontend_safe_mode) {
                $reason = 'frontend-safe-mode';
            }

            return array(
                'frontend_safe_mode' => (bool) $frontend_safe_mode,
                'slider_safe_mode' => (bool) $slider_safe_mode,
                'safe_mode' => (bool) $safe_mode,
                'reason' => $reason,
            );
        }

        private function should_apply_lcp_boundary_defer(array $settings, $frontend_safe_mode, $slider_safe_mode)
        {
            unset($slider_safe_mode);

            if (empty($settings['lcp_boundary_defer']) || empty($settings['lcp_image_priority'])) {
                return false;
            }

            if (!empty($frontend_safe_mode)) {
                return false;
            }

            return true;
        }

        private function apply_lcp_priority_pipeline($html, array $settings, $frontend_safe_mode, $slider_safe_mode)
        {
            if (empty($settings['lcp_image_priority'])) {
                return $html;
            }

            if (!empty($slider_safe_mode)) {
                $html = $this->apply_html_rewrite_safely($html, 'sr7-first-slide-lcp-priority', function ($html) {
                    return $this->apply_sr7_first_slide_lcp_priority_markup($html);
                });
                return $this->apply_html_rewrite_safely($html, 'safe-lcp-priority-preloads', function ($html) {
                    return $this->inject_safe_lcp_priority_preloads($html);
                });
            }

            if (!empty($frontend_safe_mode)) {
                return $this->apply_html_rewrite_safely($html, 'safe-lcp-priority-preloads', function ($html) {
                    return $this->inject_safe_lcp_priority_preloads($html);
                });
            }

            return $this->apply_html_rewrite_safely($html, 'lcp-image-markup', function ($html) {
                return $this->optimize_lcp_image_markup($html);
            });
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
            $html = $this->apply_html_rewrite_safely($html, 'normalize-script-loading-attrs', function ($html) use ($settings) {
                return $this->normalize_protected_script_loading_attributes_in_html($html, $settings);
            });
            $rewrite_profile = $this->get_frontend_rewrite_profile($html, $settings);
            $frontend_safe_mode = !empty($rewrite_profile['frontend_safe_mode']);
            $slider_safe_mode = !empty($rewrite_profile['slider_safe_mode']);
            $safe_mode = !empty($rewrite_profile['safe_mode']);

            if (!empty($settings['critical_request_chain_relief'])) {
                if ($slider_safe_mode) {
                    // Slider Safe Mode protects fragile slider runtime/CSS from delay/rewrite
                    // transforms, but manual priority preloads are safe and still useful for LCP.
                    $html = $this->apply_html_rewrite_safely($html, 'manual-critical-preloads', function ($html) use ($settings) {
                        return $this->inject_manual_critical_preload_links($html, $settings);
                    });
                    $html = $this->apply_html_rewrite_safely($html, 'slider-fetch-preloads', function ($html) use ($settings) {
                        return $this->inject_detected_slider_fetch_preloads($html, $settings);
                    });
                } else {
                    $html = $this->apply_html_rewrite_safely($html, 'critical-request-chain-relief', function ($html) use ($settings) {
                        return $this->apply_critical_request_chain_relief($html, $settings);
                    });
                }
            }

            if (!$slider_safe_mode && !empty($settings['asset_chain_cleanup']) && !$this->current_request_matches_asset_cleanup_exclusion($settings)) {
                $html = $this->apply_html_rewrite_safely($html, 'asset-chain-cleanup', function ($html) use ($settings) {
                    return $this->apply_asset_chain_cleanup_to_html($html, $settings);
                });
            }

            // Frontend Safe Mode is user-controlled. Slider/Hero Safe Mode is separate and only
            // becomes active when protected hero markup is detected in the rendered HTML.
            if (!$safe_mode) {
                $html = $this->apply_html_rewrite_safely($html, 'strip-authoring-assets', function ($html) {
                    return $this->strip_probable_frontend_authoring_assets($html);
                });
            }

            if (!empty($settings['homepage_css_bundle'])) {
                $bundle_mode = isset($settings['homepage_css_bundle_mode']) ? strtolower(trim((string) $settings['homepage_css_bundle_mode'])) : 'safe';
                $bundle_mode = in_array($bundle_mode, array('safe', 'aggressive', 'full'), true) ? $bundle_mode : 'safe';
                $bundle_scope = $this->get_css_bundle_scope($settings);

                // Slider Safe Mode protects fragile hero/runtime assets from destructive rewrites,
                // but safe CSS bundling is intentionally non-destructive: it injects the generated
                // bundle while keeping the original stylesheet links as authoritative fallback.
                // Do not blank $bundle_mode here; otherwise explicit CSS warm can build a bundle
                // that never appears in the cached HTML on SR7/hero pages.


                if ('' !== $bundle_mode && !empty($settings['page_css_bundle_on_entry']) && !$this->is_ultracache_internal_loopback_request()) {
                    if ('per-page' === $bundle_scope || ('homepage' === $bundle_scope && $this->is_frontpage_request_url()) || ('shared' === $bundle_scope && $this->is_frontpage_request_url())) {
                        $this->profile_store_event('build_page_css_bundle_on_entry', $html, function ($html) use ($settings) {
                            $this->maybe_build_page_css_bundle_on_entry($html, $settings);
                            return true;
                        });
                    }
                }

                if ('' !== $bundle_mode) {
                    if ('homepage' === $bundle_scope) {
                        if ($this->is_frontpage_request_url()) {
                            $html = $this->apply_html_rewrite_safely($html, 'replace-homepage-css-bundle', function ($html) {
                                return $this->maybe_replace_page_stylesheet_links_with_bundle($html, home_url('/'));
                            });
                        }
                    } elseif ('shared' === $bundle_scope) {
                        $html = $this->apply_html_rewrite_safely($html, 'replace-shared-css-bundle', function ($html) {
                            return $this->maybe_replace_page_stylesheet_links_with_bundle($html, home_url('/'));
                        });
                    } else {
                        $html = $this->apply_html_rewrite_safely($html, 'replace-page-css-bundle', function ($html) {
                            return $this->maybe_replace_page_stylesheet_links_with_bundle($html);
                        });
                    }
                }
            }

            if (!empty($settings['leftover_css_bundle']) && !empty($settings['homepage_css_bundle']) && isset($bundle_mode) && in_array((string) $bundle_mode, array('safe', 'aggressive', 'full'), true)) {
                // Consolidate Remaining CSS is an independent post-bundle pass. It should
                // follow the user's leftoverCssBundleEnabled setting regardless of the
                // selected main CSS bundle mode; the mode only controls the main bundle.
                $html = $this->profile_store_stage('consolidate-leftover-css-bundle', $html, function ($html) use ($settings) {
                    return $this->maybe_consolidate_leftover_stylesheet_links($html, $settings);
                });
            }



            if (!empty($settings['cls_dimensions'])) {
                $cls_dimensions_result = $this->apply_html_array_rewrite_safely($html, 'cls-dimensions', function ($html) {
                    return $this->inject_safe_cls_dimensions($html);
                });
                if (is_array($cls_dimensions_result)) {
                    if (!empty($cls_dimensions_result['safe'])) {
                        $html = isset($cls_dimensions_result['html']) && is_string($cls_dimensions_result['html']) ? $cls_dimensions_result['html'] : $html;
                        $this->record_analytics_cls_dimensions(isset($cls_dimensions_result['stats']) && is_array($cls_dimensions_result['stats']) ? $cls_dimensions_result['stats'] : array());
                    }
                }
            }

            if (!empty($settings['google_fonts_local_optimization'])) {
                $html = $this->apply_html_rewrite_safely($html, 'google-fonts-local-links', function ($html) {
                    return $this->rewrite_google_fonts_links_to_local_in_html($html);
                });
            } elseif (!empty($settings['google_fonts_swap'])) {
                $html = $this->apply_html_rewrite_safely($html, 'google-fonts-display-swap', function ($html) {
                    return $this->rewrite_google_fonts_display_swap_in_html($html);
                });
            }

            if (!empty($settings['self_hosted_font_css_optimization'])) {
                $html = $this->apply_html_rewrite_safely($html, 'self-hosted-font-css-links', function ($html) {
                    return $this->optimize_self_hosted_font_css_links($html);
                });
            }

            if (empty($settings['frontend_safe_mode']) && !empty($settings['self_hosted_font_runtime_rewrite'])) {
                // Runtime font CSS rewrites are intentionally allowed during slider/hero safe mode.
                // The helper only rewrites late stylesheet href attributes via MutationObserver and
                // does not alter slider markup, script ordering, or LCP preload selection.
                $html = $this->apply_html_rewrite_safely($html, 'runtime-font-css-map', function ($html) {
                    return $this->inject_runtime_font_css_url_map($html);
                });
            }

            if (!empty($settings['async_css']) || !empty($settings['aggressive_async_css'])) {
                $html = $this->apply_async_css_links_to_html($html);
            }
            if (empty($settings['frontend_safe_mode']) && !empty($settings['lazy_mailerlite_nonce'])) {
                $html = $this->apply_html_rewrite_safely($html, 'lazy-mailerlite-nonce-refresh', function ($html) {
                    return $this->inject_mailerlite_lazy_nonce_refresh($html);
                });
            }

            if (!empty($settings['delay_safe_third_party_js']) || !empty($settings['delay_functional_third_party_js'])) {
                $html = $this->apply_html_rewrite_safely($html, 'delay-third-party-pattern-scripts', function ($html) use ($settings) {
                    return $this->delay_third_party_analytics_scripts_in_html($html, $settings);
                });
            }

            if (!empty($settings['speculation_rules_enabled'])) {
                $html = $this->apply_html_rewrite_safely($html, 'speculation-rules-prefetch', function ($html) use ($settings) {
                    return $this->inject_speculation_rules_prefetch($html, $settings);
                });
            }

            $html = $this->apply_lcp_priority_pipeline($html, $settings, $frontend_safe_mode, $slider_safe_mode);

            if ($this->should_apply_lcp_boundary_defer($settings, $frontend_safe_mode, $slider_safe_mode)) {
                $html = $this->apply_html_rewrite_safely($html, 'lcp-boundary-defer', function ($html) use ($settings) {
                    return $this->apply_lcp_boundary_defer_to_html($html, $settings);
                });
            }

            if (!empty($settings['defer_js']) && !empty($settings['defer_all_js'])) {
                $html = $this->apply_html_rewrite_safely($html, 'defer-all-js-final-pass', function ($html) use ($settings) {
                    return $this->apply_defer_all_js_to_html($html, $settings);
                });
            }

            if (!$safe_mode) {
                $html = $this->apply_html_rewrite_safely($html, 'safe-html-minify', function ($html) {
                    return $this->minify_html_output_safely($html);
                });
            }

            return $html;
        }



        private function inject_mailerlite_lazy_nonce_refresh($html)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, 'ml_create_nonce') || false === stripos($html, 'mailerlite')) {
                return $html;
            }

            if (false !== strpos($html, 'data-ucwp-mailerlite-lazy-nonce="1"')) {
                return $html;
            }

            $script = <<<'JS'
<script data-ucwp-mailerlite-lazy-nonce="1">
(function(){
  if (window.__ucwpMailerLiteLazyNonceV1) { return; }
  window.__ucwpMailerLiteLazyNonceV1 = true;

  var realFetch = window.fetch;
  if (typeof realFetch !== 'function') { return; }

  var ajaxUrl = '';
  var refreshStarted = false;

  function toBodyString(body) {
    try {
      if (!body) { return ''; }
      if (typeof body === 'string') { return body; }
      if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) { return body.toString(); }
      if (typeof FormData !== 'undefined' && body instanceof FormData) {
        var parts = [];
        body.forEach(function(value, key){ parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value))); });
        return parts.join('&');
      }
    } catch (e) {}
    return '';
  }

  function getRequestUrl(input) {
    try {
      if (typeof input === 'string') { return input; }
      if (input && typeof input.url === 'string') { return input.url; }
    } catch (e) {}
    return '';
  }

  function isMailerLiteNonceRequest(input, init) {
    var url = getRequestUrl(input);
    var body = toBodyString(init && init.body ? init.body : '');
    if (url.indexOf('admin-ajax.php') === -1) { return false; }
    return body.indexOf('ml_create_nonce') !== -1 || body.indexOf('action=ml_create_nonce') !== -1 || body.indexOf('action%3Dml_create_nonce') !== -1;
  }

  function getNonceFromBody(body) {
    var str = toBodyString(body);
    var match = str.match(/(?:^|&)ml_nonce=([^&]*)/);
    if (!match) { return ''; }
    try { return decodeURIComponent(match[1].replace(/\+/g, ' ')); } catch (e) { return match[1]; }
  }

  function fakeNonceResponse(nonce) {
    return Promise.resolve({
      ok: true,
      status: 200,
      json: function(){ return Promise.resolve({ success: true, data: { ml_nonce: nonce || '' } }); },
      text: function(){ return Promise.resolve('{"success":true,"data":{"ml_nonce":"' + String(nonce || '').replace(/"/g, '\\"') + '"}}'); }
    });
  }

  function formLooksLikeMailerLite(form) {
    if (!form || !form.querySelector || !form.querySelector('input[name="ml_nonce"]')) { return false; }
    try {
      return !!(form.closest('[id^="mailerlite-form_"]') || form.closest('[data-temp-id]') || form.querySelector('.mailerlite-subscribe-submit') || form.querySelector('[class*="mailerlite"]'));
    } catch (e) {
      return true;
    }
  }

  function findFormFromTarget(target) {
    try {
      if (target && target.closest) {
        var form = target.closest('form');
        if (form && formLooksLikeMailerLite(form)) { return form; }
      }
    } catch (e) {}
    return null;
  }

  function setSubmitDisabled(form, disabled) {
    try {
      var buttons = form.querySelectorAll('.mailerlite-subscribe-submit, button[type="submit"], input[type="submit"]');
      for (var i = 0; i < buttons.length; i++) { buttons[i].disabled = !!disabled; }
    } catch (e) {}
  }

  function refreshFormNonce(form) {
    if (!formLooksLikeMailerLite(form)) { return Promise.resolve(false); }
    if (form.__ucwpMlNonceRefreshing) { return form.__ucwpMlNonceRefreshing; }

    var input = form.querySelector('input[name="ml_nonce"]');
    if (!input) { return Promise.resolve(false); }

    var url = ajaxUrl || (window.location.origin + '/wp-admin/admin-ajax.php');
    var body = new URLSearchParams();
    body.append('action', 'ml_create_nonce');
    body.append('ml_nonce', input.value || '');

    form.__ucwpMlNonceRefreshing = realFetch.call(window, url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    }).then(function(response){
      return response.json();
    }).then(function(json){
      if (json && json.success && json.data && json.data.ml_nonce) {
        input.value = json.data.ml_nonce;
        form.__ucwpMlNonceReady = true;
        setSubmitDisabled(form, false);
        return true;
      }
      return false;
    }).catch(function(error){
      try { console.warn('UltraCache MailerLite nonce refresh failed', error); } catch (e) {}
      return false;
    }).then(function(ok){
      form.__ucwpMlNonceRefreshing = null;
      return ok;
    });

    return form.__ucwpMlNonceRefreshing;
  }

  window.fetch = function(input, init) {
    if (isMailerLiteNonceRequest(input, init || {})) {
      ajaxUrl = getRequestUrl(input) || ajaxUrl;
      var oldNonce = getNonceFromBody(init && init.body ? init.body : '');
      return fakeNonceResponse(oldNonce);
    }
    return realFetch.apply(this, arguments);
  };

  function maybeRefreshFromInteraction(event) {
    var form = findFormFromTarget(event && event.target ? event.target : null);
    if (!form || form.__ucwpMlNonceReady || refreshStarted) { return; }
    refreshStarted = true;
    refreshFormNonce(form).then(function(){ refreshStarted = false; });
  }

  document.addEventListener('focusin', maybeRefreshFromInteraction, true);
  document.addEventListener('pointerdown', maybeRefreshFromInteraction, true);
  document.addEventListener('touchstart', maybeRefreshFromInteraction, true);
  document.addEventListener('keydown', maybeRefreshFromInteraction, true);

  document.addEventListener('submit', function(event){
    var form = event && event.target ? event.target : null;
    if (!formLooksLikeMailerLite(form) || form.__ucwpMlNonceReady) { return; }

    event.preventDefault();
    event.stopImmediatePropagation();
    setSubmitDisabled(form, true);

    refreshFormNonce(form).then(function(ok){
      if (!ok) {
        setSubmitDisabled(form, false);
        return;
      }
      setTimeout(function(){
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          var submitEvent = document.createEvent('Event');
          submitEvent.initEvent('submit', true, true);
          form.dispatchEvent(submitEvent);
        }
      }, 0);
    });
  }, true);
})();
</script>
JS;

            if (preg_match('/<script\b[^>]*>[\s\S]*?ml_create_nonce[\s\S]*?<\/script>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
                $offset = isset($match[0][1]) ? (int) $match[0][1] : -1;
                if ($offset >= 0) {
                    return substr($html, 0, $offset) . $script . "\n" . substr($html, $offset);
                }
            }

            if (false !== stripos($html, '</head>')) {
                return preg_replace('/<\/head>/i', $script . "\n</head>", $html, 1);
            }

            return $script . "\n" . $html;
        }

        private function delay_third_party_analytics_scripts_in_html($html, array $settings = array())
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<script') || (empty($settings['delay_safe_third_party_js']) && empty($settings['delay_functional_third_party_js']))) {
                return $html;
            }

            if (!preg_match_all('/<script\b[^>]*>.*?<\/script>/is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                return $html;
            }

            $records = array();
            foreach ($matches as $index => $match) {
                $tag = isset($match[0][0]) ? (string) $match[0][0] : '';
                $offset = isset($match[0][1]) ? (int) $match[0][1] : -1;
                if ('' === $tag || $offset < 0 || !preg_match('/^<script\b[^>]*>/i', $tag, $open_match)) {
                    continue;
                }

                $open = (string) $open_match[0];
                $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($open, 'src'), ENT_QUOTES | ENT_HTML5);
                $id = (string) $this->extract_attribute_from_html_tag($open, 'id');
                $handle = $this->infer_script_handle_from_tag($open, $src);
                if ('' === $handle && '' !== $src) {
                    $handle = $src;
                }

                $records[$index] = array(
                    'tag'     => $tag,
                    'open'    => $open,
                    'offset'  => $offset,
                    'src'     => $src,
                    'id'      => $id,
                    'handle'  => $handle,
                    'group'   => $this->normalize_delayed_script_group_handle($handle),
                    'has_src' => ('' !== $src),
                );
            }

            if (empty($records)) {
                return $html;
            }

            $replacements = array();
            foreach ($records as $index => $record) {
                if (empty($record['has_src']) || '' === $record['src']) {
                    continue;
                }

                $match = $this->get_third_party_delay_match($record['handle'], $record['src'], $record['open'], $settings);
                if (empty($match['matched'])) {
                    continue;
                }

                $reason = isset($match['reason']) ? (string) $match['reason'] : 'third-party';
                $replacements[$index] = $this->build_delayed_script_tag($record['open'], $record['handle'], $record['src'], $reason);

                if ('' === $record['group']) {
                    continue;
                }

                foreach ($records as $inline_index => $inline_record) {
                    if ($inline_index === $index || !empty($inline_record['has_src']) || isset($replacements[$inline_index])) {
                        continue;
                    }
                    if ('' === $inline_record['group'] || $inline_record['group'] !== $record['group']) {
                        continue;
                    }
                    if (!$this->is_delayable_inline_script_tag($inline_record['tag'])) {
                        continue;
                    }
                    $replacements[$inline_index] = $this->build_delayed_inline_script_tag($inline_record['tag'], $inline_record['handle'], $reason);
                }
            }

            foreach ($records as $inline_index => $inline_record) {
                if (!empty($inline_record['has_src']) || isset($replacements[$inline_index])) {
                    continue;
                }
                if (!$this->is_delayable_inline_script_tag($inline_record['tag'])) {
                    continue;
                }
                $inline_match = $this->get_inline_third_party_delay_match($inline_record['handle'], $inline_record['tag'], $settings);
                if (empty($inline_match['matched'])) {
                    continue;
                }
                $inline_reason = isset($inline_match['reason']) ? (string) $inline_match['reason'] : 'third-party';
                $replacements[$inline_index] = $this->build_delayed_inline_script_tag($inline_record['tag'], $inline_record['handle'], $inline_reason);
            }

            if (empty($replacements)) {
                return $html;
            }

            ksort($replacements);
            $out = '';
            $last = 0;
            foreach ($replacements as $index => $replacement) {
                if (!isset($records[$index])) {
                    continue;
                }
                $record = $records[$index];
                $out .= substr($html, $last, $record['offset'] - $last) . $replacement;
                $last = $record['offset'] + strlen($record['tag']);
            }

            return $out . substr($html, $last);
        }

        private function apply_lcp_boundary_defer_to_html($html, array $settings = array())
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '<script')) {
                return $html;
            }

            $boundary = $this->find_lcp_boundary_offset($html);
            if ($boundary <= 0) {
                return $html;
            }

            if (!preg_match_all('/<script\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1[^>]*>\s*<\/script>/is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                return $html;
            }

            $out = '';
            $last = 0;
            $changed = false;

            foreach ($matches as $match) {
                $tag = isset($match[0][0]) ? (string) $match[0][0] : '';
                $offset = isset($match[0][1]) ? (int) $match[0][1] : -1;
                if ('' === $tag || $offset < $boundary) {
                    continue;
                }

                $replacement = $this->maybe_build_lcp_boundary_delayed_script_tag($tag, $settings);
                if (!is_string($replacement) || '' === $replacement || $replacement === $tag) {
                    continue;
                }

                $out .= substr($html, $last, $offset - $last) . $replacement;
                $last = $offset + strlen($tag);
                $changed = true;
            }

            if (!$changed) {
                return $html;
            }

            return $out . substr($html, $last);
        }

        private function find_lcp_boundary_offset($html)
        {
            if (!is_string($html) || '' === $html) {
                return -1;
            }

            $candidate = $this->find_manual_lcp_candidate($html);
            if (null === $candidate) {
                $candidate = $this->find_best_sr7_lcp_candidate($html);
            }
            if (null === $candidate) {
                $candidate = $this->find_best_lcp_candidate_with_regex($html);
            }
            if (null === $candidate || empty($candidate['url'])) {
                return -1;
            }

            if (!empty($candidate['boundary_offset'])) {
                return max(1, (int) $candidate['boundary_offset']);
            }

            $needles = array();
            foreach (array('raw_url', 'url') as $key) {
                if (!empty($candidate[$key])) {
                    $needles[] = (string) $candidate[$key];
                    $needles[] = esc_url((string) $candidate[$key]);
                    $needles[] = esc_attr((string) $candidate[$key]);
                    $needles[] = str_replace('&', '&amp;', (string) $candidate[$key]);
                }
            }

            foreach (array_values(array_unique(array_filter($needles))) as $needle) {
                $pos = stripos($html, $needle);
                if (false !== $pos) {
                    return (int) $pos + strlen($needle);
                }
            }

            return -1;
        }

        private function should_delay_lcp_boundary_script($handle, $src, $tag, array $settings = array())
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

            if ($this->should_native_defer_all_local_script($src, $settings)) {
                return false;
            }

            if ($this->script_matches_fragment_list($handle, $src, $this->get_delay_non_critical_js_exclude_fragments())) {
                return false;
            }

            if ($this->script_handle_has_inline_after_segments($handle)) {
                return false;
            }

            if ($this->script_handle_has_enqueued_dependents($handle)) {
                return false;
            }

            $src_lc = strtolower((string) $src);
            $handle_lc = strtolower((string) $handle);

            if (0 === strpos($handle_lc, 'wp-') || 0 === strpos($handle_lc, 'wc-')) {
                return false;
            }

            if (false !== strpos($src_lc, '/wp-includes/js/')) {
                return false;
            }

            if (false !== strpos($src_lc, '/plugins/woocommerce/assets/')) {
                return false;
            }

            return true;
        }

        private function maybe_build_lcp_boundary_delayed_script_tag($tag, array $settings = array())
        {
            $tag = (string) $tag;
            if ('' === $tag || false === stripos($tag, '<script')) {
                return $tag;
            }
            if (false !== stripos($tag, 'type="text/ucwp-delayed-js"') || false !== stripos($tag, "type='text/ucwp-delayed-js'") || false !== stripos($tag, 'data-ucwp-src=')) {
                return $tag;
            }

            $src = $this->extract_attribute_from_html_tag($tag, 'src');
            if ('' === $src) {
                return $tag;
            }

            $handle = $this->infer_script_handle_from_tag($tag, $src);
            if ('' === $handle) {
                $handle = $src;
            }

            if ($this->should_delay_lcp_boundary_script($handle, $src, $tag, $settings)) {
                return $this->build_delayed_script_tag($tag, $handle, $src, 'lcp-boundary');
            }

            return $tag;
        }

        private function infer_script_handle_from_tag($tag, $src = '')
        {
            $id = $this->extract_attribute_from_html_tag($tag, 'id');
            $id = trim((string) $id);
            if ('' !== $id) {
                $id = preg_replace('/-js(?:-extra|-before|-after)?$/', '', $id);
                return is_string($id) ? $id : '';
            }

            $path = (string) wp_parse_url((string) $src, PHP_URL_PATH);
            $base = basename($path);
            if ('' === $base) {
                return '';
            }

            return preg_replace('/\.min\.js$|\.js$/i', '', $base);
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
                if ($this->html_link_href_exists($html, $url)) {
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

            return $this->insert_html_before_closing_head($html, implode("\n", $tags));
        }

        private function rewrite_chain_delay_stylesheet_links($html, array $settings = array())
        {
            $fragments = $this->get_critical_request_chain_delay_fragments($settings);
            if (empty($fragments) || false === stripos((string) $html, '<link')) {
                return $html;
            }

            $processed = $this->rewrite_chain_delay_stylesheet_links_with_processor($html, $fragments);
            if (is_string($processed)) {
                return $processed;
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

        private function rewrite_chain_delay_stylesheet_links_with_processor($html, array $fragments)
        {
            if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || false === stripos($html, '<link')) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor($html);
                $changed = false;
                $fallbacks = array();
                $index = 0;

                while ($processor->next_tag('LINK')) {
                    $rel = $processor->get_attribute('rel');
                    if (!$this->html_rel_attribute_contains_stylesheet($rel)) {
                        continue;
                    }

                    if (null !== $processor->get_attribute('data-ucwp-async-css')
                        || null !== $processor->get_attribute('data-ucwp-frontpage-css')
                        || null !== $processor->get_attribute('data-ucwp-page-css-bundle')) {
                        continue;
                    }

                    if (null !== $processor->get_attribute('onload')) {
                        continue;
                    }

                    $href = $processor->get_attribute('href');
                    if (!is_string($href) || '' === $href || !$this->asset_matches_fragment_list('', $href, $fragments)) {
                        continue;
                    }

                    $marker = 'ucwp-chain-delay-' . md5($href . '|' . (++$index));
                    $fallbacks[$marker] = $this->build_async_css_noscript_fallback_link($href, $processor->get_attribute('media'));

                    $processor->set_attribute('media', 'print');
                    $processor->set_attribute('onload', "this.media='all'");
                    $processor->set_attribute('data-ucwp-async-css', '1');
                    $processor->set_attribute('data-ucwp-noscript-token', $marker);
                    $changed = true;
                }

                if (!$changed) {
                    return (string) $html;
                }

                $updated_html = $processor->get_updated_html();
                if (!is_string($updated_html) || '' === $updated_html) {
                    return null;
                }

                return $this->append_async_css_noscript_fallbacks_from_markers($updated_html, $fallbacks);
            } catch (\Throwable $e) {
                return null;
            }
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

            $processed = $this->remove_asset_tags_matching_fragments_with_processor($html, $fragments);
            if (is_string($processed)) {
                return $processed;
            }

            $that = $this;
            $html = preg_replace_callback('/<script\b[^>]*\bsrc=("|\')(.*?)\1[^>]*>\s*<\/script>/is', static function ($matches) use ($that, $fragments) {
                $tag = isset($matches[0]) ? (string) $matches[0] : '';
                $src = $that->extract_attribute_from_html_tag($tag, 'src');
                return $that->asset_matches_fragment_list('', $src, $fragments) ? '' : $tag;
            }, $html);

            $html = preg_replace_callback('/<link\b[^>]*>/i', static function ($matches) use ($that, $fragments) {
                $tag = isset($matches[0]) ? (string) $matches[0] : '';
                $href = $that->extract_attribute_from_html_tag($tag, 'href');
                return ('' !== $href && $that->asset_matches_fragment_list('', $href, $fragments)) ? '' : $tag;
            }, is_string($html) ? $html : '');

            return is_string($html) ? $html : '';
        }

        private function remove_asset_tags_matching_fragments_with_processor($html, array $fragments)
        {
            if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || (false === stripos($html, '<script') && false === stripos($html, '<link'))) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor($html);
                $changed = false;
                $tokens = array();
                $index = 0;

                while ($processor->next_tag()) {
                    $tag_name = strtoupper((string) $processor->get_tag());
                    if ('SCRIPT' !== $tag_name && 'LINK' !== $tag_name) {
                        continue;
                    }

                    $url = ('SCRIPT' === $tag_name) ? $processor->get_attribute('src') : $processor->get_attribute('href');
                    if (!is_string($url) || '' === $url || !$this->asset_matches_fragment_list('', $url, $fragments)) {
                        continue;
                    }

                    $token = 'ucwp-remove-asset-' . md5($tag_name . '|' . $url . '|' . (++$index));
                    $processor->set_attribute('data-ucwp-remove-asset-token', $token);
                    $tokens[$token] = strtolower($tag_name);
                    $changed = true;
                }

                if (!$changed) {
                    return null;
                }

                $updated_html = $processor->get_updated_html();
                if (!is_string($updated_html) || '' === $updated_html) {
                    return null;
                }

                foreach ($tokens as $token => $tag_name) {
                    if ('script' === $tag_name) {
                        $pattern = '/<script\b(?=[^>]*\bdata-ucwp-remove-asset-token=("|\')' . preg_quote($token, '/') . '\1)[^>]*>\s*<\/script>/is';
                    } else {
                        $pattern = '/<link\b(?=[^>]*\bdata-ucwp-remove-asset-token=("|\')' . preg_quote($token, '/') . '\1)[^>]*>/i';
                    }
                    $updated_html = preg_replace($pattern, '', $updated_html, 1);
                }

                if (!is_string($updated_html) || false !== stripos($updated_html, 'data-ucwp-remove-asset-token=')) {
                    return null;
                }

                return $updated_html;
            } catch (\Throwable $e) {
                return null;
            }
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

            return $this->insert_html_before_closing_head($html, $script);
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
            foreach ($this->get_slider_hero_markup_markers() as $marker) {
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


        private function apply_async_css_links_to_html($html)
        {
            $safe_async_css_result = $this->apply_html_array_rewrite_safely($html, 'async-css-links', function ($html) {
                return $this->rewrite_safe_async_css_links($html);
            });

            if (!is_array($safe_async_css_result)) {
                return $html;
            }

            $stats = isset($safe_async_css_result['stats']) && is_array($safe_async_css_result['stats']) ? $safe_async_css_result['stats'] : $this->get_default_safe_async_css_stats();

            if (!empty($safe_async_css_result['safe'])) {
                $this->record_analytics_safe_async_css($stats);
                $this->record_store_profile_async_css_diagnostics($stats);
                return isset($safe_async_css_result['html']) && is_string($safe_async_css_result['html']) ? $safe_async_css_result['html'] : $html;
            }

            $stats['safe'] = false;
            $this->record_store_profile_async_css_diagnostics($stats);
            return $html;
        }

        private function record_store_profile_async_css_diagnostics(array $stats)
        {
            if (!$this->is_store_profiler_enabled() || empty($this->store_profile)) {
                return;
            }

            $settings = $this->get_settings();
            $items = isset($stats['items']) && is_array($stats['items']) ? array_slice($stats['items'], 0, 80) : array();
            $reasons = isset($stats['reasons']) && is_array($stats['reasons']) ? $stats['reasons'] : array();
            arsort($reasons);

            $this->store_profile['async_css_diagnostics'] = array(
                'available' => true,
                'enabled' => !empty($settings['async_css']),
                'aggressive_enabled' => !empty($settings['aggressive_async_css']),
                'safe' => !isset($stats['safe']) || !empty($stats['safe']),
                'scanned' => isset($stats['scanned']) ? (int) $stats['scanned'] : 0,
                'rewritten' => isset($stats['rewritten']) ? (int) $stats['rewritten'] : 0,
                'skipped' => isset($stats['skipped']) ? (int) $stats['skipped'] : 0,
                'unresolved' => isset($stats['unresolved']) ? (int) $stats['unresolved'] : 0,
                'reason_counts' => $reasons,
                'items' => $items,
            );
        }

        private function add_safe_async_css_diagnostic_item(array &$stats, $url, $status, $reason, $detail = '')
        {
            $status = sanitize_key((string) $status);
            $reason = sanitize_key((string) $reason);
            if ('' === $status) {
                $status = 'unknown';
            }
            if ('' === $reason) {
                $reason = 'unknown';
            }

            if (!isset($stats['reasons']) || !is_array($stats['reasons'])) {
                $stats['reasons'] = array();
            }
            if (!isset($stats['reasons'][$reason])) {
                $stats['reasons'][$reason] = 0;
            }
            $stats['reasons'][$reason]++;

            if (!isset($stats['items']) || !is_array($stats['items'])) {
                $stats['items'] = array();
            }
            if (count($stats['items']) >= 80) {
                return;
            }

            $url = is_scalar($url) ? (string) $url : '';
            $stats['items'][] = array(
                'url' => $url,
                'path' => (string) wp_parse_url($url, PHP_URL_PATH),
                'status' => $status,
                'reason' => $reason,
                'detail' => is_scalar($detail) ? (string) $detail : '',
            );
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

            $processed = $this->rewrite_safe_async_css_links_with_processor($html);
            if (is_array($processed)) {
                return $processed;
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

        private function rewrite_safe_async_css_links_with_processor($html)
        {
            if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || false === stripos($html, '<link')) {
                return null;
            }

            $stats = $this->get_default_safe_async_css_stats();

            try {
                $processor = new WP_HTML_Tag_Processor($html);
                $changed = false;
                $fallbacks = array();
                $index = 0;

                while ($processor->next_tag('LINK')) {
                    $rel = $processor->get_attribute('rel');
                    if (!$this->html_rel_attribute_contains_stylesheet($rel)) {
                        continue;
                    }

                    $stats['scanned']++;

                    $href = $processor->get_attribute('href');
                    $href_for_diag = is_string($href) ? $this->absolutize_public_resource_url($href, home_url('/')) : '';

                    if (null !== $processor->get_attribute('data-ucwp-async-css')) {
                        $stats['skipped']++;
                        $this->add_safe_async_css_diagnostic_item($stats, $href_for_diag, 'skipped', 'already_async');
                        continue;
                    }

                    if (null !== $processor->get_attribute('data-ucwp-frontpage-css') || null !== $processor->get_attribute('data-ucwp-page-css-bundle')) {
                        $stats['skipped']++;
                        $this->add_safe_async_css_diagnostic_item($stats, $href_for_diag, 'skipped', 'css_bundle_link');
                        continue;
                    }

                    if (!is_string($href) || '' === $href) {
                        $stats['unresolved']++;
                        $this->add_safe_async_css_diagnostic_item($stats, '', 'unresolved', 'missing_href');
                        continue;
                    }

                    $media = strtolower(trim((string) $processor->get_attribute('media')));
                    if ('' !== $media && 'all' !== $media) {
                        $stats['skipped']++;
                        $this->add_safe_async_css_diagnostic_item($stats, $href_for_diag, 'skipped', 'non_all_media', $media);
                        continue;
                    }

                    if (null !== $processor->get_attribute('disabled') || null !== $processor->get_attribute('onload')) {
                        $stats['skipped']++;
                        $this->add_safe_async_css_diagnostic_item($stats, $href_for_diag, 'skipped', 'already_has_loading_attribute');
                        continue;
                    }

                    $absolute_url = $this->absolutize_public_resource_url($href, home_url('/'));
                    if ('' === $absolute_url || !$this->is_safe_local_public_stylesheet_url($absolute_url)) {
                        $stats['unresolved']++;
                        $this->add_safe_async_css_diagnostic_item($stats, $absolute_url, 'unresolved', 'not_local_css');
                        continue;
                    }

                    $decision = $this->get_async_css_stylesheet_decision($absolute_url, '');
                    if (empty($decision['eligible'])) {
                        $stats['skipped']++;
                        $this->add_safe_async_css_diagnostic_item($stats, $absolute_url, 'skipped', isset($decision['reason']) ? (string) $decision['reason'] : 'not_eligible');
                        continue;
                    }

                    $marker = 'ucwp-safe-async-' . md5($absolute_url . '|' . (++$index));
                    $fallbacks[$marker] = $this->build_async_css_noscript_fallback_link($href, $processor->get_attribute('media'));

                    $processor->set_attribute('media', 'print');
                    $processor->set_attribute('onload', "this.media='all'");
                    $processor->set_attribute('data-ucwp-async-css', '1');
                    $processor->set_attribute('data-ucwp-noscript-token', $marker);

                    $stats['rewritten']++;
                    $this->add_safe_async_css_diagnostic_item($stats, $absolute_url, 'applied', isset($decision['reason']) ? (string) $decision['reason'] : 'eligible');
                    $changed = true;
                }

                if (!$changed) {
                    return array('html' => (string) $html, 'stats' => $stats);
                }

                $updated_html = $processor->get_updated_html();
                if (!is_string($updated_html) || '' === $updated_html) {
                    return null;
                }

                return array(
                    'html' => $this->append_async_css_noscript_fallbacks_from_markers($updated_html, $fallbacks),
                    'stats' => $stats,
                );
            } catch (\Throwable $e) {
                return null;
            }
        }

        private function get_default_safe_async_css_stats()
        {
            $settings = $this->get_settings();
            return array(
                'enabled' => !empty($settings['async_css']),
                'aggressive_enabled' => !empty($settings['aggressive_async_css']),
                'safe' => true,
                'scanned' => 0,
                'rewritten' => 0,
                'skipped' => 0,
                'unresolved' => 0,
                'reasons' => array(),
                'items' => array(),
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

            $href = $this->extract_attribute_from_html_tag($tag, 'href');
            $absolute_for_diag = '' !== $href ? $this->absolutize_public_resource_url($href, home_url('/')) : '';

            if (false !== stripos($tag, 'data-ucwp-async-css=')) {
                $stats['skipped']++;
                $this->add_safe_async_css_diagnostic_item($stats, $absolute_for_diag, 'skipped', 'already_async');
                return $tag;
            }

            if (false !== stripos($tag, 'data-ucwp-frontpage-css=') || false !== stripos($tag, 'data-ucwp-page-css-bundle=')) {
                $stats['skipped']++;
                $this->add_safe_async_css_diagnostic_item($stats, $absolute_for_diag, 'skipped', 'css_bundle_link');
                return $tag;
            }

            if ('' === $href) {
                $stats['unresolved']++;
                $this->add_safe_async_css_diagnostic_item($stats, '', 'unresolved', 'missing_href');
                return $tag;
            }

            $media = strtolower(trim((string) $this->extract_attribute_from_html_tag($tag, 'media')));
            if ('' !== $media && 'all' !== $media) {
                $stats['skipped']++;
                $this->add_safe_async_css_diagnostic_item($stats, $absolute_for_diag, 'skipped', 'non_all_media', $media);
                return $tag;
            }

            if (preg_match('/\s(?:disabled|onload)\b/i', $tag)) {
                $stats['skipped']++;
                $this->add_safe_async_css_diagnostic_item($stats, $absolute_for_diag, 'skipped', 'already_has_loading_attribute');
                return $tag;
            }

            $absolute_url = $this->absolutize_public_resource_url($href, home_url('/'));
            if ('' === $absolute_url || !$this->is_safe_local_public_stylesheet_url($absolute_url)) {
                $stats['unresolved']++;
                $this->add_safe_async_css_diagnostic_item($stats, $absolute_url, 'unresolved', 'not_local_css');
                return $tag;
            }

            $decision = $this->get_async_css_stylesheet_decision($absolute_url, $tag);
            if (empty($decision['eligible'])) {
                $stats['skipped']++;
                $this->add_safe_async_css_diagnostic_item($stats, $absolute_url, 'skipped', isset($decision['reason']) ? (string) $decision['reason'] : 'not_eligible');
                return $tag;
            }

            $rewritten = $this->remove_html_tag_attribute($tag, 'media');
            $rewritten = $this->remove_html_tag_attribute($rewritten, 'onload');
            $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'media', 'print');
            $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'onload', "this.media='all'");
            $rewritten = $this->set_or_add_html_tag_attribute($rewritten, 'data-ucwp-async-css', '1');
            $rewritten .= '<noscript>' . $tag . '</noscript>';

            $stats['rewritten']++;
            $this->add_safe_async_css_diagnostic_item($stats, $absolute_url, 'applied', isset($decision['reason']) ? (string) $decision['reason'] : 'eligible');
            return $rewritten;
        }

        private function html_tag_rel_contains_stylesheet($tag)
        {
            return $this->html_rel_attribute_contains_stylesheet($this->extract_attribute_from_html_tag($tag, 'rel'));
        }

        private function html_rel_attribute_contains_stylesheet($rel)
        {
            if (null === $rel || false === $rel) {
                return false;
            }

            $rel = strtolower(trim((string) $rel));
            if ('' === $rel) {
                return false;
            }

            $parts = preg_split('/\s+/', $rel);
            if (!is_array($parts)) {
                return false;
            }

            return in_array('stylesheet', $parts, true) && !in_array('preload', $parts, true) && !in_array('alternate', $parts, true);
        }

        private function build_async_css_noscript_fallback_link($href, $media = null)
        {
            $href = trim((string) $href);
            if ('' === $href) {
                return '';
            }

            $attrs = 'rel="stylesheet" href="' . esc_url($href) . '"';
            $media = is_scalar($media) ? trim((string) $media) : '';
            if ('' !== $media && 'all' !== strtolower($media) && 'print' !== strtolower($media)) {
                $attrs .= ' media="' . esc_attr($media) . '"';
            }

            return '<noscript><link ' . $attrs . ' data-ucwp-async-css-fallback="1" /></noscript>';
        }

        private function append_async_css_noscript_fallbacks_from_markers($html, array $fallbacks)
        {
            if (empty($fallbacks) || !is_string($html) || '' === $html) {
                return $html;
            }

            foreach ($fallbacks as $marker => $fallback) {
                $marker = (string) $marker;
                $fallback = (string) $fallback;
                if ('' === $marker) {
                    continue;
                }

                $pattern = '/<link\b(?=[^>]*\bdata-ucwp-noscript-token=("|\')' . preg_quote($marker, '/') . '\1)[^>]*>/i';
                $updated = preg_replace_callback($pattern, function ($matches) use ($marker, $fallback) {
                    $tag = (string) ($matches[0] ?? '');
                    if ('' === $tag) {
                        return $tag;
                    }

                    $tag = $this->remove_html_tag_attribute($tag, 'data-ucwp-noscript-token');
                    return $tag . $fallback;
                }, $html, 1);

                if (is_string($updated) && '' !== $updated) {
                    $html = $updated;
                }
            }

            return $html;
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
            return array_values(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            }));
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
                : array();

            $list = array_merge($this->get_builtin_homepage_css_bundle_exclude_fragments(), (array) $list);

            return array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
                return '' !== trim((string) $item);
            })));
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
            $decision = $this->get_async_css_stylesheet_decision($url, $tag);
            return !empty($decision['eligible']);
        }

        private function get_async_css_stylesheet_decision($url, $tag = '')
        {
            $settings = $this->get_settings();
            $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
            if ('' === $path) {
                return array('eligible' => false, 'reason' => 'missing_path');
            }

            $async_exclude_fragments = $this->get_async_css_exclude_fragments();
            if ($this->should_exclude_stylesheet_url_by_fragments($url, $async_exclude_fragments)) {
                return array('eligible' => false, 'reason' => 'async_exclude_list');
            }

            $aggressive_enabled = !empty($settings['aggressive_async_css']);
            if ($aggressive_enabled) {
                $aggressive_exclude_fragments = $this->get_aggressive_async_css_exclude_fragments();
                if ($this->should_exclude_stylesheet_url_by_fragments($url, $aggressive_exclude_fragments)) {
                    return array('eligible' => false, 'reason' => 'aggressive_async_exclude_list');
                }

                /*
                 * Aggressive Async CSS means almost all remaining local stylesheet
                 * links are eligible. CSS Bundle Exclusions must not silently
                 * disable this pass; use the visible Aggressive Async CSS
                 * Exclude List for styles that must remain blocking.
                 */
                $hard_block_patterns = array(
                    '/dashicons/i',
                    '/admin-bar/i',
                    '/\/wp-admin\//i',
                );

                foreach ($hard_block_patterns as $pattern) {
                    if (preg_match($pattern, $path) || preg_match($pattern, (string) $tag)) {
                        return array('eligible' => false, 'reason' => 'hard_admin_asset');
                    }
                }

                return array('eligible' => true, 'reason' => 'aggressive_async_css_enabled');
            }

            $critical_patterns = array(
                'theme_layout' => array('/\/themes\//'),
                'woocommerce_layout' => array('/\/woocommerce\//'),
                'elementor_layout' => array('/\/elementor\//', '/\/elementor-pro\//', '/\/header-footer-elementor\.css$/', '/\/widgets-css\//', '/\/post-\d+\.css$/', '/\/base\/elementor\.css$/'),
                'font_icon_css' => array('/\/fontawesome(?:\.min)?\.css$/', '/\/(?:solid|brands|regular|all)(?:\.min)?\.css$/', '/\/elementor-icons(?:-shared-0)?(?:\.min)?\.css$/', '/\/eicons(?:\.min)?\.css$/', '/\/manrope\.css$/', '/\/fraunces\.css$/'),
            );

            foreach ($critical_patterns as $reason => $patterns) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $path)) {
                        return array('eligible' => false, 'reason' => 'low_risk_skipped_' . $reason);
                    }
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
                    return array('eligible' => true, 'reason' => 'low_risk_safe_pattern');
                }
            }

            return array('eligible' => false, 'reason' => 'low_risk_no_safe_pattern');
        }
        private function html_tag_processor_available()
        {
            return class_exists('WP_HTML_Tag_Processor');
        }

        private function get_current_html_processor_tag_markup($processor, $fallback_tag = 'tag')
        {
            $fallback_tag = preg_replace('/[^A-Za-z0-9:-]/', '', (string) $fallback_tag);
            if ('' === $fallback_tag) {
                $fallback_tag = 'tag';
            }

            if (!is_object($processor) || !method_exists($processor, 'get_updated_html')) {
                return '<' . $fallback_tag . '>';
            }

            try {
                $html = (string) $processor->get_updated_html();
                if (preg_match('/<' . preg_quote($fallback_tag, '/') . '\b[^>]*>/i', $html, $matches)) {
                    return (string) $matches[0];
                }

                if (preg_match('/<([a-zA-Z][a-zA-Z0-9:-]*)\b[^>]*>/i', $html, $matches)) {
                    return (string) $matches[0];
                }
            } catch (\Throwable $e) {
                // Fall through to minimal synthetic markup.
            }

            return '<' . $fallback_tag . '>';
        }

        private function get_html_tag_attribute_with_processor($html, $attribute)
        {
            if (!$this->html_tag_processor_available()) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor((string) $html);
                if (!$processor->next_tag()) {
                    return null;
                }

                $value = $processor->get_attribute((string) $attribute);
                if (null === $value) {
                    return null;
                }

                if (true === $value) {
                    return (string) $attribute;
                }

                if (false === $value) {
                    return '';
                }

                return html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
            } catch (\Throwable $e) {
                return null;
            }
        }

        private function set_html_tag_attribute_with_processor($html, $attribute, $value)
        {
            if (!$this->html_tag_processor_available()) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor((string) $html);
                if (!$processor->next_tag()) {
                    return null;
                }

                $processor->set_attribute((string) $attribute, (string) $value);
                $updated = $processor->get_updated_html();
                return is_string($updated) && '' !== $updated ? $updated : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        private function remove_html_tag_attribute_with_processor($html, $attribute)
        {
            if (!$this->html_tag_processor_available()) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor((string) $html);
                if (!$processor->next_tag()) {
                    return null;
                }

                $processor->remove_attribute((string) $attribute);
                $updated = $processor->get_updated_html();
                return is_string($updated) && '' !== $updated ? $updated : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        private function remove_html_tag_attribute($html, $attribute)
        {
            $attribute = trim((string) $attribute);
            if ('' === $attribute) {
                return (string) $html;
            }

            $processed = $this->remove_html_tag_attribute_with_processor($html, $attribute);
            if (is_string($processed)) {
                return $processed;
            }

            return (string) preg_replace('/\s+' . preg_quote($attribute, '/') . '(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?/i', '', (string) $html);
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

            $processed = $this->set_html_tag_attribute_with_processor($html, $attribute, $value);
            if (is_string($processed)) {
                return $processed;
            }

            $quoted_value = esc_attr((string) $value);
            $pattern = '/\b' . preg_quote($attribute, '/') . '(?:\s*=\s*("|\')(.*?)(\1)|\s*=\s*[^\s"\'=<>`]+)?/i';
            if (preg_match($pattern, (string) $html)) {
                return (string) preg_replace($pattern, $attribute . '="' . $quoted_value . '"', (string) $html, 1);
            }

            return (string) preg_replace('/\s*\/?>$/', ' ' . $attribute . '="' . $quoted_value . '"$0', (string) $html, 1);
        }

        private function html_link_href_exists($html, $href)
        {
            $href = html_entity_decode((string) $href, ENT_QUOTES, 'UTF-8');
            if ('' === $href || !is_string($html) || '' === $html || false === stripos($html, '<link')) {
                return false;
            }

            if ($this->html_tag_processor_available()) {
                try {
                    $processor = new WP_HTML_Tag_Processor($html);
                    $target = $this->normalize_public_resource_url($href);
                    while ($processor->next_tag('LINK')) {
                        $current = $processor->get_attribute('href');
                        if (!is_string($current) || '' === $current) {
                            continue;
                        }

                        $decoded_current = html_entity_decode($current, ENT_QUOTES, 'UTF-8');
                        if ($current === $href || $decoded_current === $href) {
                            return true;
                        }

                        if ('' !== $target && $this->normalize_public_resource_url($decoded_current) === $target) {
                            return true;
                        }
                    }
                } catch (\Throwable $e) {
                    // Fall through to the conservative string checks below.
                }
            }

            $escaped = esc_attr($href);
            return false !== strpos($html, 'href="' . $escaped . '"')
                || false !== strpos($html, "href='" . $escaped . "'")
                || false !== strpos($html, 'href="' . $href . '"')
                || false !== strpos($html, "href='" . $href . "'");
        }

        private function insert_html_before_closing_head($html, $markup)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '</head')) {
                return $html;
            }

            $markup = (string) $markup;
            if ('' === trim($markup)) {
                return $html;
            }

            $updated = preg_replace('/<\/head>/i', rtrim($markup) . "\n</head>", $html, 1);
            return is_string($updated) && '' !== $updated ? $updated : $html;
        }
        private function apply_final_google_fonts_rewrite_before_cache_store($html)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, 'fonts.googleapis.com')) {
                return $html;
            }

            $settings = $this->get_settings();
            if (!empty($settings['google_fonts_local_optimization'])) {
                return $this->rewrite_google_fonts_links_to_local_in_html($html);
            }

            if (!empty($settings['google_fonts_swap'])) {
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
            if (!is_string($html) || '' === $html || false === stripos($html, 'fonts.googleapis.com')) {
                return $html;
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
            if ('' === $html || false === stripos($html, 'fonts.googleapis.com')) {
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
                } else {
                    @mkdir($dir, 0755, true);
                }
            }

            if (!is_dir($dir)) {
                return false;
            }

            if (!is_writable($dir)) {
                @chmod($dir, 0755);
            }

            if (!is_writable($dir)) {
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

                return array(
                    'success' => true,
                    'message' => sprintf('Google Fonts scan finished. Scanned %d URL(s), found %d Google Fonts stylesheet URL(s), built %d local stylesheet(s).', count($scan_urls), count($font_urls), $built),
                    'reason' => (string) $reason,
                    'scannedUrls' => count($scan_urls),
                    'fontUrls' => count($font_urls),
                    'built' => $built,
                    'failed' => $failed,
                    'cleared' => !empty($clear_cache),
                );
            } finally {
                $this->google_fonts_sync_build_mode = $previous_sync;
                $this->google_fonts_async_pending = $previous_pending;
                $this->release_google_fonts_lock('ucwp_gf_scan_rebuild_lock', $lock_token);
            }
        }

        public function get_google_fonts_cache_summary()
        {
            $dir = trailingslashit($this->get_google_fonts_cache_dir());
            $settings = $this->get_settings();
            $summary = array(
                'enabled' => !empty($settings['google_fonts_local_optimization']),
                'built' => false,
                'path' => $dir,
                'cssFiles' => 0,
                'fontFiles' => 0,
                'totalFiles' => 0,
                'bytes' => 0,
                'lastBuilt' => 0,
                'message' => 'Google Fonts cache has not been built yet. Original Google Fonts URLs will remain until you rebuild the local cache.',
            );

            if ('' === $dir || !is_dir($dir)) {
                return $summary;
            }

            $items = @scandir($dir);
            if (!is_array($items)) {
                return $summary;
            }

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

            $summary['built'] = $summary['cssFiles'] > 0 && $summary['fontFiles'] > 0;
            if ($summary['built']) {
                $summary['message'] = sprintf('Google Fonts cache built: %d stylesheet(s), %d font file(s).', (int) $summary['cssFiles'], (int) $summary['fontFiles']);
            } elseif ($summary['totalFiles'] > 0) {
                $summary['message'] = 'Google Fonts cache contains partial files. Rebuild the Google Fonts cache to refresh it.';
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
            $response = wp_remote_get(
                $scan_url,
                array(
                    'timeout' => 20,
                    'redirection' => 3,
                    'sslverify' => false,
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

                $response = wp_remote_get(
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

                $response = wp_remote_get(
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
            if (!is_string($normalized) || $normalized === $css) {
                return false;
            }

            return false !== ucwp_safe_file_put_contents($css_file, $normalized, 0, 'google-fonts-css-normalize');
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

            $manual_selector_candidates = $this->find_manual_lcp_hero_selector_candidates($html, 1);
            if (!empty($manual_selector_candidates) && !empty($manual_selector_candidates[0])) {
                return $this->apply_lcp_candidate_optimizations($html, $manual_selector_candidates[0]);
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

            $manual_selector_candidates = $this->find_manual_lcp_hero_selector_candidates($html, 1);
            if (!empty($manual_selector_candidates)) {
                $updated = $html;
                foreach ($manual_selector_candidates as $candidate) {
                    if (!empty($candidate['url'])) {
                        $updated = $this->inject_lcp_preload_link($updated, $candidate['url']);
                    }
                }
                return $updated;
            }

            $sr7_first_slide_candidates = $this->find_sr7_first_slide_lcp_candidates($html, 1);
            if (!empty($sr7_first_slide_candidates)) {
                $updated = $html;
                foreach ($sr7_first_slide_candidates as $candidate) {
                    if (!empty($candidate['url'])) {
                        $updated = $this->inject_lcp_preload_link($updated, $candidate['url']);
                    }
                }
                return $updated;
            }

            $sr7_markup_candidates = $this->find_marked_sr7_lcp_preload_candidates($html, 1);
            if (!empty($sr7_markup_candidates)) {
                $updated = $html;
                foreach ($sr7_markup_candidates as $candidate) {
                    if (!empty($candidate['url'])) {
                        $updated = $this->inject_lcp_preload_link($updated, $candidate['url']);
                    }
                }
                return $updated;
            }

            $sr7_static_candidates = $this->find_sr7_static_slide_lcp_candidates($html, 1);
            if (!empty($sr7_static_candidates)) {
                $updated = $html;
                foreach ($sr7_static_candidates as $candidate) {
                    if (!empty($candidate['url'])) {
                        $updated = $this->inject_lcp_preload_link($updated, $candidate['url']);
                    }
                }
                return $updated;
            }

            $sr7_candidate = $this->find_best_sr7_lcp_candidate($html);
            if (null !== $sr7_candidate && !empty($sr7_candidate['url'])) {
                if ($this->is_sr7_generated_image_list_url($sr7_candidate['url']) && empty($sr7_candidate['sr7_verified_first_slide'])) {
                    return $html;
                }
                return $this->inject_lcp_preload_link($html, $sr7_candidate['url']);
            }

            return $html;
        }


        private function find_manual_lcp_hero_selector_candidates($html, $limit = 1)
        {
            $settings = $this->get_settings();
            $selectors = isset($settings['manual_lcp_hero_selector_list']) && is_array($settings['manual_lcp_hero_selector_list']) ? $settings['manual_lcp_hero_selector_list'] : array();
            $limit = max(1, min(5, (int) $limit));
            if (empty($selectors) || !is_string($html) || '' === $html) {
                return array();
            }

            $results = array();
            foreach ($selectors as $selector) {
                $normalized_selector = $this->normalize_manual_lcp_hero_selector($selector);
                if ('' === $normalized_selector) {
                    continue;
                }

                $block = $this->extract_manual_lcp_hero_selector_block($html, $normalized_selector);
                if ('' === $block) {
                    continue;
                }

                $candidates = $this->find_lcp_candidates_in_manual_hero_block($block, $normalized_selector);
                foreach ($candidates as $candidate) {
                    if (empty($candidate['url'])) {
                        continue;
                    }
                    $candidate['manual_lcp_hero_selector'] = $normalized_selector;
                    $candidate['manual_lcp_hero_selector_found'] = true;
                    $candidate['is_manual_selector'] = true;
                    $candidate['score'] = 2000000 - count($results);
                    $results[] = $candidate;
                    if (count($results) >= $limit) {
                        break 2;
                    }
                }
            }

            return $this->dedupe_lcp_candidates_by_url($results, $limit);
        }

        private function normalize_manual_lcp_hero_selector($selector)
        {
            $selector = trim((string) $selector);
            if ('' === $selector) {
                return '';
            }

            // Accept a plain element ID like "homepage-slider" and treat it as #homepage-slider.
            if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,80}$/', $selector)) {
                return '#' . $selector;
            }

            if (preg_match('/^#[A-Za-z][A-Za-z0-9_-]{0,80}$/', $selector)) {
                return $selector;
            }

            if (preg_match('/^\.[A-Za-z][A-Za-z0-9_-]{0,80}$/', $selector)) {
                return $selector;
            }

            return '';
        }

        private function extract_manual_lcp_hero_selector_block($html, $selector)
        {
            $html = (string) $html;
            $selector = $this->normalize_manual_lcp_hero_selector($selector);
            if ('' === $html || '' === $selector) {
                return '';
            }

            $name = substr($selector, 1);
            $name_quoted = preg_quote($name, '/');
            if ('#' === $selector[0]) {
                $pattern = '/<([a-z0-9:-]+)\b(?=[^>]*\bid\s*=\s*(["\'])' . $name_quoted . '\2)[^>]*>/i';
            } else {
                $pattern = '/<([a-z0-9:-]+)\b(?=[^>]*\bclass\s*=\s*(["\'])(?=[^"\']*(?:^|\s)' . $name_quoted . '(?:\s|$))[^"\']*\2)[^>]*>/i';
            }

            if (!preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE)) {
                return '';
            }

            $start = (int) $match[0][1];
            $tag = strtolower((string) $match[1][0]);
            $start_tag_end = strpos($html, '>', $start);
            if (false === $start_tag_end) {
                return '';
            }

            $closing = '</' . $tag . '>';
            $end = stripos($html, $closing, $start_tag_end);
            if (false === $end) {
                return substr($html, $start, 120000);
            }

            return substr($html, $start, ($end + strlen($closing)) - $start);
        }

        private function find_lcp_candidates_in_manual_hero_block($block, $selector)
        {
            $block = (string) $block;
            if ('' === $block) {
                return array();
            }

            $candidates = array();

            if (false !== stripos($block, 'sr7')) {
                $sr7_candidates = $this->find_sr7_first_slide_lcp_candidates($block, 5);
                if (!empty($sr7_candidates)) {
                    return $this->sort_lcp_candidates_by_area_then_score($sr7_candidates);
                }
            }

            // In a manually scoped hero/slider, prefer a specifically marked SR7 LCP image when present.
            if (preg_match_all('/<(?:sr7-img|img)\b[^>]*\bdata-ucwp-sr7-lcp\s*=\s*(["\'])1\1[^>]*>/i', $block, $matches)) {
                foreach ($matches[0] as $index => $tag_html) {
                    $candidate = $this->extract_lcp_candidate_from_html_tag($tag_html, array(
                        'manual_lcp_hero_selector' => $selector,
                        'manual_lcp_order' => $index,
                        'prefer_dom_order' => true,
                    ));
                    if (null !== $candidate) {
                        $candidate['is_sr7'] = true;
                        $candidate['sr7_markup_candidate'] = true;
                        $candidate['sr7_verified_first_slide'] = true;
                        $candidate['score'] = 2000000 - ((int) $index * 10);
                        $candidates[] = $candidate;
                    }
                }
            }

            if (!empty($candidates)) {
                return $candidates;
            }

            if (preg_match_all('/<(img|sr7-img|div|section|figure|picture|sr7-slide|sr7-content|sr7-module)\b[^>]*>/i', $block, $matches)) {
                foreach ($matches[0] as $index => $tag_html) {
                    $candidate = $this->extract_lcp_candidate_from_html_tag($tag_html, array(
                        'manual_lcp_hero_selector' => $selector,
                        'manual_lcp_order' => $index,
                    ));
                    if (null !== $candidate) {
                        $candidate['score'] += 5000 - ((int) $index * 10);
                        $candidates[] = $candidate;
                    }
                }
            }

            if (empty($candidates)) {
                return array();
            }

            return $this->sort_lcp_candidates_by_area_then_score($candidates);
        }

        private function extract_lcp_candidate_from_html_tag($tag_html, array $extra_context = array())
        {
            $tag_html = (string) $tag_html;
            if ('' === $tag_html) {
                return null;
            }

            $tag_name = '';
            if (preg_match('/^<\s*([a-z0-9:-]+)/i', $tag_html, $tag_match) && !empty($tag_match[1])) {
                $tag_name = strtoupper((string) $tag_match[1]);
            }

            $context = array_merge(array(
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
            ), $extra_context);

            foreach (array('src', 'data-src', 'data-dbsrc', 'data-lazy-src', 'data-lazyload', 'data-image', 'data-origin', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image', 'poster') as $attribute) {
                $value = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                if ('' === $value) {
                    continue;
                }

                $candidate = $this->build_lcp_candidate_from_values($value, $context + array('attribute' => $attribute));
                if (null !== $candidate) {
                    return $candidate;
                }
            }

            foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
                foreach ($this->extract_candidate_urls_from_srcset($this->extract_attribute_from_html_tag($tag_html, $attribute)) as $srcset_url) {
                    $candidate = $this->build_lcp_candidate_from_values($srcset_url, $context + array('attribute' => $attribute));
                    if (null !== $candidate) {
                        return $candidate;
                    }
                }
            }

            foreach ($this->extract_candidate_urls_from_style($context['style']) as $style_url) {
                $candidate = $this->build_lcp_candidate_from_values($style_url, $context + array('attribute' => 'style'));
                if (null !== $candidate) {
                    return $candidate;
                }
            }

            return null;
        }

        private function dedupe_lcp_candidates_by_url(array $candidates, $limit = 1)
        {
            $unique = array();
            $seen = array();
            $limit = max(1, min(10, (int) $limit));
            foreach ($candidates as $candidate) {
                $key = $this->normalize_public_resource_url(isset($candidate['url']) ? (string) $candidate['url'] : '');
                if ('' === $key || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $unique[] = $candidate;
                if (count($unique) >= $limit) {
                    break;
                }
            }

            return $unique;
        }

        private function find_marked_sr7_lcp_preload_candidates($html, $limit = 1)
        {
            $html = (string) $html;
            $limit = max(1, min(5, (int) $limit));
            if ('' === $html || false === stripos($html, 'data-ucwp-sr7-lcp')) {
                return array();
            }

            if (!preg_match_all('/<(?:sr7-img|img)\b[^>]*\bdata-ucwp-sr7-lcp\s*=\s*(["\'])1\1[^>]*>/i', $html, $matches)) {
                return array();
            }

            $preferred = array();
            $generated = array();
            foreach ($matches[0] as $index => $tag_html) {
                $tag_html = (string) $tag_html;
                $tag_name = 'SR7-IMG';
                if (preg_match('/^<\s*([a-z0-9:-]+)/i', $tag_html, $tag_match) && !empty($tag_match[1])) {
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

                foreach (array('src', 'data-src', 'data-dbsrc', 'data-lazy-src', 'data-lazyload', 'data-image', 'data-origin', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image', 'poster') as $attribute) {
                    $value = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                    if ('' === $value) {
                        continue;
                    }

                    $candidate = $this->build_lcp_candidate_from_values($value, $context + array('attribute' => $attribute));
                    if (null === $candidate || empty($candidate['url'])) {
                        continue;
                    }

                    $candidate['is_sr7'] = true;
                    $candidate['sr7_markup_candidate'] = true;
                    $candidate['sr7_verified_first_slide'] = true;
                    $candidate['score'] += 1400 + max(0, 100 - ((int) $index * 12));

                    if ($this->is_sr7_generated_image_list_url($candidate['url'])) {
                        $generated[] = $candidate;
                    } else {
                        $preferred[] = $candidate;
                    }

                    // The rendered SR7 element's first concrete image URL is the safest preload
                    // target. Do not continue into generated image-list placeholders when a real
                    // markup candidate exists.
                    break;
                }

                foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
                    if (!empty($preferred)) {
                        break;
                    }
                    foreach ($this->extract_candidate_urls_from_srcset($this->extract_attribute_from_html_tag($tag_html, $attribute)) as $srcset_url) {
                        $candidate = $this->build_lcp_candidate_from_values($srcset_url, $context + array('attribute' => $attribute));
                        if (null === $candidate || empty($candidate['url'])) {
                            continue;
                        }
                        $candidate['is_sr7'] = true;
                        $candidate['sr7_markup_candidate'] = true;
                        $candidate['sr7_verified_first_slide'] = true;
                        $candidate['score'] += 1300 + max(0, 100 - ((int) $index * 12));
                        if ($this->is_sr7_generated_image_list_url($candidate['url'])) {
                            $generated[] = $candidate;
                        } else {
                            $preferred[] = $candidate;
                        }
                        break 2;
                    }
                }
            }

            $candidates = !empty($preferred) ? $preferred : $generated;
            if (empty($candidates)) {
                return array();
            }

            $candidates = $this->sort_lcp_candidates_by_area_then_score($candidates);

            $unique = array();
            $seen = array();
            foreach ($candidates as $candidate) {
                $key = $this->normalize_public_resource_url(isset($candidate['url']) ? (string) $candidate['url'] : '');
                if ('' === $key || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $unique[] = $candidate;
                if (count($unique) >= $limit) {
                    break;
                }
            }

            return $unique;
        }

        private function apply_sr7_first_slide_lcp_priority_markup($html)
        {
            if (!is_string($html) || '' === $html) {
                return $html;
            }

            if (false === stripos($html, '<sr7-') && false === stripos($html, 'sr7-module')) {
                return $html;
            }

            $candidate = null;
            $first_slide_candidates = $this->find_sr7_first_slide_lcp_candidates($html, 1);
            if (!empty($first_slide_candidates)) {
                $candidate = $first_slide_candidates[0];
            }

            if (null === $candidate) {
                $candidate = $this->find_best_sr7_lcp_candidate($html);
            }

            if (null !== $candidate && !empty($candidate['url'])) {
                $processed = $this->boost_lcp_candidate_markup($html, $candidate);
                if (is_string($processed) && '' !== $processed) {
                    $html = $processed;
                }
            }

            // Some SR7 static/shared backgrounds are created or normalized by runtime JS.
            // The runtime guard is deliberately static-slide aware; it does not promote
            // arbitrary first-slide decorative layers just because they are large.
            return $this->inject_sr7_lcp_priority_runtime_script($html);
        }

        private function is_sr7_lcp_markup_candidate($tag_name, $src, $data_src, $dbsrc)
        {
            $tag_name = strtoupper((string) $tag_name);
            if ('SR7-IMG' === $tag_name) {
                return true;
            }

            if ('IMG' !== $tag_name) {
                return false;
            }

            foreach (array($src, $data_src) as $value) {
                $value = is_string($value) ? $value : '';
                if ('' === $value) {
                    continue;
                }

                if (false !== stripos($value, '/revslider/') || false !== stripos($value, '/cache/ultracache-avif/revslider/') || false !== stripos($value, '/cache/ultracache-webp/revslider/')) {
                    return true;
                }
            }

            return is_string($dbsrc) && '' !== trim($dbsrc);
        }

        private function set_lcp_marker_on_start_tag($tag, $is_sr7 = false)
        {
            $tag = (string) $tag;
            if ('' === $tag) {
                return $tag;
            }

            $attribute = $is_sr7 ? 'data-ucwp-sr7-lcp' : 'data-ucwp-lcp';
            if (false !== stripos($tag, $attribute . '=')) {
                return $tag;
            }

            return $this->set_or_add_html_tag_attribute($tag, $attribute, '1');
        }

        private function apply_sr7_first_slide_lcp_priority_markup_with_processor($html)
        {
            if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html) {
                return null;
            }

            $candidate = null;
            $first_slide_candidates = $this->find_sr7_first_slide_lcp_candidates($html, 1);
            if (!empty($first_slide_candidates)) {
                $candidate = $first_slide_candidates[0];
            }
            if (null === $candidate) {
                $candidate = $this->find_best_sr7_lcp_candidate($html);
            }
            if (null === $candidate || empty($candidate['raw_url']) || empty($candidate['attribute'])) {
                return null;
            }

            return $this->boost_lcp_candidate_markup_with_processor(
                $html,
                $candidate,
                isset($candidate['tag']) ? (string) $candidate['tag'] : 'SR7-IMG',
                (string) $candidate['attribute'],
                (string) $candidate['raw_url']
            );
        }
        private function add_lcp_priority_attributes_to_start_tag($tag, $include_loading = false)
        {
            $tag = (string) $tag;
            if ('' === $tag || '>' !== substr(rtrim($tag), -1)) {
                return $tag;
            }

            $replacement = $this->set_or_add_html_tag_attribute($tag, 'fetchpriority', 'high');
            $replacement = $this->set_lcp_marker_on_start_tag($replacement, (bool) $include_loading);

            if ($include_loading) {
                $loading = strtolower((string) $this->extract_attribute_from_html_tag($replacement, 'loading'));
                if ('' === $loading || 'lazy' === $loading) {
                    $replacement = $this->set_or_add_html_tag_attribute($replacement, 'loading', 'eager');
                }
            }

            return is_string($replacement) && '' !== $replacement ? $replacement : $tag;
        }

        private function inject_sr7_lcp_priority_runtime_script($html)
        {
            if (!is_string($html) || '' === $html || false === stripos($html, '</head>')) {
                return $html;
            }

            if (false !== stripos($html, 'id="ucwp-sr7-lcp-priority"') || false !== stripos($html, "id='ucwp-sr7-lcp-priority'")) {
                return $html;
            }

            $settings = $this->get_settings();
            $selectors = array();
            if (!empty($settings['manual_lcp_hero_selector_list']) && is_array($settings['manual_lcp_hero_selector_list'])) {
                foreach ($settings['manual_lcp_hero_selector_list'] as $selector) {
                    $selector = $this->normalize_manual_lcp_hero_selector($selector);
                    if ('' !== $selector) {
                        $selectors[] = $selector;
                    }
                }
            }
            $selectors = array_values(array_unique($selectors));
            $selectors_json = wp_json_encode($selectors);
            if (!is_string($selectors_json) || '' === $selectors_json) {
                $selectors_json = '[]';
            }

            $script = <<<'HTML'
<script id="ucwp-sr7-lcp-priority">(function(){"use strict";if(window.__ucwpSr7LcpPriorityV107){return;}window.__ucwpSr7LcpPriorityV107=1;var manualSelectors=__UCWP_MANUAL_SELECTORS__;function tag(n){return n&&n.tagName?String(n.tagName).toLowerCase():"";}function abs(u){try{return new URL(String(u||""),document.baseURI).href;}catch(e){return String(u||"");}}function clean(u){u=abs(u).split("#")[0].split("?")[0];return u.replace(/^https?:\/\/[^/]+/i,"");}function imageUrl(n){try{if(!n){return"";}var v=(n.currentSrc||n.src||"");if(!v&&n.getAttribute){v=n.getAttribute("src")||n.getAttribute("data-src")||n.getAttribute("data-bg")||n.getAttribute("data-background")||n.getAttribute("data-bg-image")||"";}if(!v&&n.style&&n.style.backgroundImage){var m=String(n.style.backgroundImage).match(/url\(["']?([^"')]+)["']?\)/i);v=m&&m[1]?m[1]:"";}return v;}catch(e){return"";}}function getPreloadUrl(){try{var l=document.querySelector('link[rel="preload"][as="image"][data-ucwp-lcp-preload="1"]');return l?(l.href||l.getAttribute("href")||""):"";}catch(e){return"";}}function addScope(out,n){if(!n||n.nodeType!==1){return;}for(var i=0;i<out.length;i++){if(out[i]===n){return;}}out.push(n);}function getScopes(){var out=[];if(manualSelectors&&manualSelectors.length){for(var i=0;i<manualSelectors.length;i++){try{document.querySelectorAll(manualSelectors[i]).forEach(function(n){addScope(out,n);});}catch(e){}}}try{document.querySelectorAll("sr7-module,rs-module").forEach(function(n){addScope(out,n);});}catch(e){}return out;}function collect(scope){var nodes=[];try{if(/^(sr7-module-bg|sr7-img|img)$/i.test(tag(scope))){nodes.push(scope);}if(scope&&scope.querySelectorAll){scope.querySelectorAll("sr7-module-bg,sr7-img,img").forEach(function(n){nodes.push(n);});}}catch(e){}return nodes;}function matchesPreload(n,pre){var u=imageUrl(n);if(!u||!pre){return false;}return clean(u)===clean(pre)||abs(u)===abs(pre);}function scoreNoLayout(n,pre){var t=tag(n),u=imageUrl(n).toLowerCase(),score=0;if(matchesPreload(n,pre)){score+=1000000;}if(t==="sr7-module-bg"){score+=250000;}else if(t==="sr7-img"){score+=50000;}if(u.indexOf("revslider/o/")!==-1){score-=180000;}if(/book|cover|product|thumb|thumbnail|logo|icon|avatar/.test(u)){score-=120000;}if(/lcp|hero|background|bg|banner/.test(u)){score+=90000;}return score;}function findBest(pre){var scopes=getScopes(),best=null,bestScore=-999999999;for(var i=0;i<scopes.length;i++){var nodes=collect(scopes[i]);for(var j=0;j<nodes.length;j++){var u=imageUrl(nodes[j]);if(!/\.(avif|webp|png|jpe?g|gif)(\?|#|$)/i.test(u)){continue;}var sc=scoreNoLayout(nodes[j],pre);if(sc>bestScore){best=nodes[j];bestScore=sc;}}}return best;}function mark(n,pre){try{if(!n||n.nodeType!==1){return false;}if(!n.hasAttribute("fetchpriority")){n.setAttribute("fetchpriority","high");n.setAttribute("data-ucwp-added-fetchpriority","1");}else if(n.getAttribute("fetchpriority")!=="high"){n.setAttribute("fetchpriority","high");}n.setAttribute("data-ucwp-sr7-lcp","1");n.setAttribute("data-ucwp-sr7-role",matchesPreload(n,pre)?"preload-matched":"preload-scoped");n.setAttribute("data-ucwp-lcp-runtime-winner","1");n.setAttribute("data-ucwp-lcp-reason",matchesPreload(n,pre)?"sr7-preload-matched-runtime":"sr7-preload-scoped-runtime");if((tag(n)==="img"||tag(n)==="sr7-img")&&(!n.hasAttribute("loading")||n.getAttribute("loading")==="lazy")){n.setAttribute("loading","eager");}if(!n.hasAttribute("decoding")){n.setAttribute("decoding","sync");}window.__ucwpLcpDiscovery=window.__ucwpLcpDiscovery||{};window.__ucwpLcpDiscovery.runtimeWinner={url:imageUrl(n),preload:pre||"",tag:tag(n),id:n.id||"",role:n.getAttribute("data-ucwp-sr7-role")||"",reason:n.getAttribute("data-ucwp-lcp-reason")||""};return true;}catch(e){return false;}}function run(){var pre=getPreloadUrl();var n=findBest(pre);if(n){mark(n,pre);return true;}return false;}function schedule(){try{run();}catch(e){}}document.addEventListener("sr.module.ready",schedule,true);document.addEventListener("SR7_MODULE_READY",schedule,true);document.addEventListener("DOMContentLoaded",schedule,{once:true});if(document.readyState!=="loading"){schedule();}var tries=[100,400,1000,2200];for(var x=0;x<tries.length;x++){setTimeout(schedule,tries[x]);}}());</script>
HTML;

            $script = str_replace('__UCWP_MANUAL_SELECTORS__', $selectors_json, $script);

            return $this->insert_html_before_closing_head($html, $script);
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

            $candidates = $this->sort_lcp_candidates_by_area_then_score($candidates);

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
            if (in_array($tag, array('SCRIPT', 'LINK', 'META', 'NOSCRIPT', 'STYLE', 'IFRAME'), true)) {
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

            $candidates = $this->sort_lcp_candidates_by_area_then_score($candidates);

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

            $candidates = $this->sort_lcp_candidates_by_area_then_score($candidates);

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
                'data-lazyload-src' => 92,
                'data-image' => 88,
                'data-origin' => 78,
                'data-orig' => 78,
                'data-srcset' => 72,
                'data-lazy-srcset' => 72,
                'data-lazyload-srcset' => 72,
                'data-bg' => 84,
                'data-background' => 84,
                'data-bg-url' => 84,
                'data-bg-image' => 84,
                'data-background-image' => 84,
                'data-lazy-bg' => 84,
                'data-lazy-background' => 84,
                'data-thumb' => 30,
                'poster' => 70,
                'style' => 115,
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
                'SOURCE' => 10,
                'A' => 4,
                'SR7-IMG' => 110,
                'SR7-MODULE-BG' => 150,
                'SR7-SLIDE' => 55,
                'SR7-CONTENT' => 34,
                'SR7-MODULE' => 60,
                'STYLE' => 8,
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

            if (!empty($context['inside_manual_lcp_selector']) || !empty($context['manual_lcp_hero_selector'])) {
                $score += 900;
            }

            if ('style' === $attribute && false !== strpos((string) (isset($context['style']) ? $context['style'] : ''), 'url(')) {
                // Rendered inline background/background shorthand URLs inside a manual hero
                // block are strong LCP signals across sliders and custom builders.
                $score += 520;
            }

            if (preg_match('/\.(avif|webp)(?:$|\?)/i', $normalized_url) && false !== strpos($meta_haystack, $normalized_url)) {
                // Prefer optimized URLs only when they are actually present in rendered HTML/CSS.
                $score += 160;
            }

            foreach (array('lcp', 'hero', 'banner', 'slider', 'slide', 'cover', 'featured', 'showcase', 'intro', 'splash', 'main', 'cta', 'background', 'bg') as $positive_term) {
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
            if (($width <= 0 || $height <= 0) && '' !== $style) {
                if ($width <= 0 && preg_match('/(?:^|;)\s*width\s*:\s*([0-9]+(?:\.[0-9]+)?)px/i', $style, $style_width_match)) {
                    $width = (int) round((float) $style_width_match[1]);
                }
                if ($height <= 0 && preg_match('/(?:^|;)\s*height\s*:\s*([0-9]+(?:\.[0-9]+)?)px/i', $style, $style_height_match)) {
                    $height = (int) round((float) $style_height_match[1]);
                }
            }
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

            $area = ($width > 0 && $height > 0) ? ($width * $height) : 0;

            return array(
                'url' => $normalized_url,
                'raw_url' => (string) $raw_url,
                'attribute' => $attribute,
                'tag' => $tag,
                'score' => $score,
                'width' => $width,
                'height' => $height,
                'area' => $area,
                'source' => $attribute,
            );
        }

        private function hydrate_lcp_candidate_dimensions(array $candidate, $fallback_url = '')
        {
            $width = isset($candidate['width']) ? (int) $candidate['width'] : 0;
            $height = isset($candidate['height']) ? (int) $candidate['height'] : 0;

            $urls = array();
            if (!empty($candidate['url'])) {
                $urls[] = (string) $candidate['url'];
            }
            if ('' !== (string) $fallback_url) {
                $urls[] = (string) $fallback_url;
            }
            if (!empty($candidate['source_url'])) {
                $urls[] = (string) $candidate['source_url'];
            }

            foreach (array_values(array_unique($urls)) as $url) {
                $dimensions = $this->get_public_image_dimensions($url);
                if (!empty($dimensions)) {
                    $width = isset($dimensions['width']) ? (int) $dimensions['width'] : $width;
                    $height = isset($dimensions['height']) ? (int) $dimensions['height'] : $height;
                    if ($width > 0 && $height > 0) {
                        break;
                    }
                }
            }

            $candidate['width'] = $width;
            $candidate['height'] = $height;
            $candidate['area'] = ($width > 0 && $height > 0) ? ($width * $height) : 0;

            return $candidate;
        }

        private function compare_lcp_candidates_by_area_then_score($left, $right)
        {
            $left_area = isset($left['area']) ? (int) $left['area'] : ((isset($left['width'], $left['height'])) ? ((int) $left['width'] * (int) $left['height']) : 0);
            $right_area = isset($right['area']) ? (int) $right['area'] : ((isset($right['width'], $right['height'])) ? ((int) $right['width'] * (int) $right['height']) : 0);
            if ($left_area !== $right_area) {
                return $right_area <=> $left_area;
            }

            $left_score = isset($left['score']) ? (int) $left['score'] : 0;
            $right_score = isset($right['score']) ? (int) $right['score'] : 0;
            if ($left_score !== $right_score) {
                return $right_score <=> $left_score;
            }

            $left_offset = isset($left['tag_offset']) ? (int) $left['tag_offset'] : (isset($left['offset']) ? (int) $left['offset'] : 0);
            $right_offset = isset($right['tag_offset']) ? (int) $right['tag_offset'] : (isset($right['offset']) ? (int) $right['offset'] : 0);
            return $left_offset <=> $right_offset;
        }

        private function sort_lcp_candidates_by_area_then_score(array $candidates)
        {
            usort($candidates, function ($left, $right) {
                return $this->compare_lcp_candidates_by_area_then_score($left, $right);
            });
            return $candidates;
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


        private function find_sr7_static_slide_blocks($html)
        {
            $html = (string) $html;
            if ('' === $html || false === stripos($html, 'static')) {
                return array();
            }

            $blocks = array();

            $patterns = array(
                '~<sr7-staticslide\b[^>]*>.*?</sr7-staticslide>~is',
                '~<sr7-slide\b[^>]*(?:staticslide|static-slide|data-key\s*=\s*["\']static["\']|data-static)[^>]*>.*?</sr7-slide>~is',
                '~<[^>]+\b(?:id|class)\s*=\s*(["\'])[^"\']*(?:staticslide|static-slide|sr7-staticslide)[^"\']*\1[^>]*>.*?</[^>]+>~is',
            );

            foreach ($patterns as $pattern) {
                if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                foreach ($matches as $match) {
                    $block = isset($match[0][0]) ? (string) $match[0][0] : '';
                    $offset = isset($match[0][1]) ? (int) $match[0][1] : 0;
                    if ('' === $block) {
                        continue;
                    }

                    $blocks[] = array(
                        'html' => $block,
                        'offset' => $offset,
                    );
                }
            }

            $unique = array();
            $seen = array();
            foreach ($blocks as $block) {
                $key = md5((string) $block['html']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $unique[] = $block;
            }

            return $unique;
        }

        private function find_sr7_static_slide_lcp_candidates($html, $limit = 1)
        {
            $html = str_replace('\\/', '/', (string) $html);
            $limit = max(1, min(3, (int) $limit));
            if ('' === $html || false === stripos($html, 'sr7')) {
                return array();
            }

            $contexts = array();

            foreach ($this->find_sr7_static_slide_blocks($html) as $block) {
                $block_html = isset($block['html']) ? (string) $block['html'] : '';
                if ('' === $block_html) {
                    continue;
                }

                $contexts[] = array(
                    'html' => $block_html,
                    'offset' => isset($block['offset']) ? (int) $block['offset'] : 0,
                    'source' => 'static-block',
                );
            }

            if (preg_match_all("~(?:sr7-staticslide|staticslide|static-slide|static\\s+slide|data-key\\s*=\\s*[\"']static[\"']|data-static)~i", $html, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $pos = isset($match[1]) ? (int) $match[1] : 0;
                    $start = max(0, $pos - 4000);
                    $contexts[] = array(
                        'html' => substr($html, $start, 90000),
                        'offset' => $start,
                        'source' => 'static-window',
                    );
                }
            }

            if (empty($contexts)) {
                return array();
            }

            $unique_contexts = array();
            $seen_contexts = array();
            foreach ($contexts as $context) {
                $context_html = isset($context['html']) ? (string) $context['html'] : '';
                if ('' === $context_html) {
                    continue;
                }
                $key = md5($context_html);
                if (isset($seen_contexts[$key])) {
                    continue;
                }
                $seen_contexts[$key] = true;
                $unique_contexts[] = $context;
            }

            $candidates = array();
            foreach ($unique_contexts as $context_index => $context) {
                foreach ($this->extract_sr7_static_context_lcp_candidates(
                    isset($context['html']) ? (string) $context['html'] : '',
                    isset($context['offset']) ? (int) $context['offset'] : 0,
                    (int) $context_index,
                    isset($context['source']) ? (string) $context['source'] : 'static-context'
                ) as $candidate) {
                    $candidates[] = $candidate;
                }
            }

            if (empty($candidates)) {
                return array();
            }

            usort($candidates, function ($left, $right) {
                return (int) $right['score'] <=> (int) $left['score'];
            });

            return $this->dedupe_lcp_candidates_by_url($candidates, $limit);
        }

        private function extract_sr7_static_context_lcp_candidates($context_html, $context_offset = 0, $context_index = 0, $context_source = 'static-context')
        {
            $context_html = (string) $context_html;
            if ('' === $context_html) {
                return array();
            }

            if (!preg_match_all('/<(sr7-module-bg|sr7-img|img|div|section|figure|picture|sr7-slide|sr7-content)\\b[^>]*>/i', $context_html, $tag_matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                return array();
            }

            $candidates = array();
            foreach ($tag_matches as $tag_index => $tag_match) {
                $tag_html = isset($tag_match[0][0]) ? (string) $tag_match[0][0] : '';
                $tag_name = isset($tag_match[1][0]) ? strtoupper((string) $tag_match[1][0]) : 'IMG';
                $relative_tag_offset = isset($tag_match[0][1]) ? (int) $tag_match[0][1] : 0;
                if ('' === $tag_html) {
                    continue;
                }

                $near_context = strtolower(substr($context_html, max(0, $relative_tag_offset - 1800), 5200));
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
                    'style'      => $this->extract_attribute_from_html_tag($tag_html, 'style') . ' sr7 static slide shared background module-bg ' . $near_context,
                );

                foreach (array('src', 'data-src', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image', 'poster', 'style', 'data-dbsrc') as $attribute) {
                    $values = array();
                    if ('style' === $attribute) {
                        $values = $this->extract_candidate_urls_from_style($this->extract_attribute_from_html_tag($tag_html, 'style'));
                    } else {
                        $value = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                        if ('' !== $value) {
                            $values[] = $value;
                        }
                    }

                    foreach ($values as $value) {
                        $raw_url = (string) $value;
                        if ('data-dbsrc' === $attribute && '' !== $raw_url) {
                            $decoded = base64_decode($raw_url, true);
                            if (is_string($decoded) && '' !== $decoded) {
                                $raw_url = $decoded;
                            }
                        }

                        if (0 === strpos($raw_url, '//')) {
                            $raw_url = (is_ssl() ? 'https:' : 'http:') . $raw_url;
                        } elseif (0 === strpos($raw_url, '/')) {
                            $raw_url = $this->absolutize_public_resource_url($raw_url);
                        }

                        $normalized_source_url = $this->normalize_public_resource_url($raw_url);
                        if ('' === $normalized_source_url || !$this->is_lcp_candidate_image_url($normalized_source_url)) {
                            continue;
                        }

                        $preferred_url = $this->prefer_existing_nextgen_public_image_url($normalized_source_url);
                        $candidate = $this->build_lcp_candidate_from_values($preferred_url, $context + array('attribute' => $attribute));
                        if (null === $candidate || empty($candidate['url'])) {
                            continue;
                        }

                        $width = isset($candidate['width']) ? (int) $candidate['width'] : 0;
                        $height = isset($candidate['height']) ? (int) $candidate['height'] : 0;
                        if (($width <= 0 || $height <= 0) && '' !== $preferred_url) {
                            $dimensions = $this->get_public_image_dimensions($preferred_url);
                            if (!empty($dimensions)) {
                                $width = isset($dimensions['width']) ? (int) $dimensions['width'] : $width;
                                $height = isset($dimensions['height']) ? (int) $dimensions['height'] : $height;
                            }
                        }

                        $candidate['width'] = $width;
                        $candidate['height'] = $height;
                        $candidate['is_sr7'] = true;
                        $candidate['sr7_static_slide'] = true;
                        $candidate['sr7_shared_background'] = true;
                        $candidate['sr7_role'] = ('SR7-MODULE-BG' === $tag_name) ? 'module-bg' : 'static-slide';
                        $candidate['lcp_reason'] = 'sr7-static-slide';
                        $candidate['source_url'] = $normalized_source_url;
                        $candidate['raw_url'] = $raw_url;
                        $candidate['tag'] = $tag_name;
                        $candidate['attribute'] = $attribute;
                        $candidate['tag_offset'] = (int) $context_offset + $relative_tag_offset;
                        $candidate['context_source'] = (string) $context_source;
                        $candidate['score'] += $this->score_sr7_static_lcp_candidate($candidate, $tag_html, $near_context, (int) $context_index, (int) $tag_index, (string) $context_source);

                        $visual_boundary = $this->find_sr7_visual_boundary_offset($context_html);
                        if ($visual_boundary >= 0) {
                            $candidate['boundary_offset'] = (int) $context_offset + (int) $visual_boundary;
                        }

                        $candidates[] = $candidate;
                    }
                }
            }

            return $candidates;
        }

        private function score_sr7_static_lcp_candidate(array $candidate, $tag_html, $near_context, $context_index = 0, $tag_index = 0, $context_source = 'static-context')
        {
            $tag_html = strtolower((string) $tag_html);
            $near_context = strtolower((string) $near_context);
            $context_source = strtolower((string) $context_source);
            $url = strtolower((string) (isset($candidate['url']) ? $candidate['url'] : ''));
            $source_url = strtolower((string) (isset($candidate['source_url']) ? $candidate['source_url'] : ''));
            $meta = $tag_html . ' ' . $near_context . ' ' . $url . ' ' . $source_url;

            $width = isset($candidate['width']) ? (int) $candidate['width'] : 0;
            $height = isset($candidate['height']) ? (int) $candidate['height'] : 0;
            $area = max(0, $width * $height);
            $ratio = ($width > 0 && $height > 0) ? ($width / max(1, $height)) : 0.0;

            $score = 5000 + max(0, 180 - ((int) $context_index * 12)) + max(0, 140 - ((int) $tag_index * 8));
            if (false !== strpos($context_source, 'static-window')) {
                $score += 120;
            }
            if (false !== strpos($meta, 'sr7-staticslide') || false !== strpos($meta, 'staticslide') || false !== strpos($meta, 'static-slide') || false !== strpos($meta, 'static slide')) {
                $score += 900;
            }
            if (false !== strpos($meta, 'module-bg') || false !== strpos($meta, 'sr7-module-bg')) {
                $score += 700;
            }
            if (false !== strpos($meta, 'shared')) {
                $score += 260;
            }
            foreach (array('lcp', 'hero', 'background', 'bg', 'banner') as $term) {
                if (false !== strpos($meta, $term)) {
                    $score += 260;
                }
            }
            if ($width >= 1000 && $height >= 250) {
                $score += 620;
            } elseif ($width >= 800 && $height >= 300) {
                $score += 360;
            }
            if ($area >= 350000) {
                $score += 420;
            } elseif ($area >= 120000) {
                $score += 180;
            }
            if ($ratio >= 1.9) {
                $score += 900;
            } elseif ($ratio >= 1.55) {
                $score += 220;
            } elseif ($ratio > 0 && $ratio < 1.15) {
                $score -= 950;
            }
            if (false !== strpos($source_url, '/revslider/o/') || false !== strpos($url, '/revslider/o/')) {
                $score -= 1200;
            }
            foreach (array('book', 'cover', 'product', 'thumb', 'thumbnail', 'logo', 'icon', 'avatar') as $negative) {
                if (false !== strpos($meta, $negative)) {
                    $score -= 650;
                }
            }
            if (false !== strpos($tag_html, 'loading="lazy"') || false !== strpos($tag_html, "loading='lazy'")) {
                $score -= 360;
            }

            return $score;
        }

        private function extract_sr7_first_slide_layer_image_candidates($html)
        {
            $html = str_replace('\/', '/', (string) $html);
            if ('' === $html || false === stripos($html, 'sr7')) {
                return array();
            }

            $slice = '';
            if (preg_match('~"slides"\s*:\s*\{\s*"1"\s*:\s*\{(.+?)(?:,"2"\s*:|,"3"\s*:|,"4"\s*:|\}\s*\}\s*[,;])~s', $html, $match)) {
                $slice = (string) $match[1];
            } else {
                $pos = strpos($html, '"slides":{"1"');
                if (false !== $pos) {
                    $slice = substr($html, $pos, 120000);
                }
            }

            if ('' === $slice) {
                return array();
            }

            $candidates = array();
            $patterns = array(
                '~"subtype"\s*:\s*"image".{0,6500}?"src"\s*:\s*"([^"]+\.(?:avif|webp|png|jpe?g|gif)(?:\?[^"]*)?)"~is',
                '~"src"\s*:\s*"([^"]+\.(?:avif|webp|png|jpe?g|gif)(?:\?[^"]*)?)".{0,6500}?"subtype"\s*:\s*"image"~is',
                '~"bg"\s*:\s*\{.{0,6500}?"image"\s*:\s*\{.{0,2500}?"src"\s*:\s*"([^"]+\.(?:avif|webp|png|jpe?g|gif)(?:\?[^"]*)?)"~is',
            );

            foreach ($patterns as $pattern) {
                if (!preg_match_all($pattern, $slice, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                foreach ($matches as $match) {
                    $url = isset($match[1][0]) ? (string) $match[1][0] : '';
                    $offset = isset($match[0][1]) ? (int) $match[0][1] : 0;
                    $url = html_entity_decode(trim($url), ENT_QUOTES, 'UTF-8');
                    if (0 === strpos($url, '/')) {
                        $url = $this->absolutize_public_resource_url($url);
                    }
                    $url = $this->normalize_public_resource_url($url);
                    if ('' === $url || !$this->is_lcp_candidate_image_url($url)) {
                        continue;
                    }

                    $context = substr($slice, max(0, $offset - 2200), 5200);
                    $width = 0;
                    $height = 0;
                    if (preg_match('~"size"\s*:\s*\{.{0,900}?"w"\s*:\s*\[\s*"?(\d+)px"?.{0,500}?"h"\s*:\s*\[\s*"?(\d+)px"?~s', $context, $dim)) {
                        $width = (int) $dim[1];
                        $height = (int) $dim[2];
                    }

                    if (($width <= 0 || $height <= 0) && preg_match('~"w"\s*:\s*\[\s*"?(\d+)px"?.{0,500}?"h"\s*:\s*\[\s*"?(\d+)px"?~s', $context, $dim)) {
                        $width = (int) $dim[1];
                        $height = (int) $dim[2];
                    }

                    $id = '';
                    if (preg_match('~"id"\s*:\s*"?([^",}\]]+)"?~', $context, $id_match)) {
                        $id = (string) $id_match[1];
                    }

                    $candidates[$url] = array(
                        'url' => $url,
                        'width' => $width,
                        'height' => $height,
                        'layer' => $id,
                        'offset' => $offset,
                        'context' => strtolower($context),
                    );
                }
            }

            return array_values($candidates);
        }

        private function extract_sr7_module_background_image_candidates($html)
        {
            $html = str_replace('\/', '/', (string) $html);
            if ('' === $html || false === stripos($html, 'sr7')) {
                return array();
            }

            if (!preg_match_all('~"bg"\s*:\s*\{.{0,7000}?"image"\s*:\s*\{.{0,3500}?"src"\s*:\s*"([^"]+\.(?:avif|webp|png|jpe?g|gif|svg)(?:\?[^"]*)?)"~is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                return array();
            }

            $candidates = array();
            foreach ($matches as $match) {
                $url = isset($match[1][0]) ? html_entity_decode(trim((string) $match[1][0]), ENT_QUOTES, 'UTF-8') : '';
                $offset = isset($match[0][1]) ? (int) $match[0][1] : 0;
                if ('' === $url) {
                    continue;
                }
                if (0 === strpos($url, '/')) {
                    $url = $this->absolutize_public_resource_url($url);
                }
                $url = $this->normalize_public_resource_url($url);
                if ('' === $url || !$this->is_lcp_candidate_image_url($url)) {
                    continue;
                }

                $context = substr($html, max(0, $offset - 3500), 9000);
                $width = 0;
                $height = 0;
                if (preg_match('~"width"\s*:\s*\[\s*([0-9]+)~s', $context, $wm)) {
                    $width = (int) $wm[1];
                }
                if (preg_match('~"height"\s*:\s*\[\s*([0-9]+)~s', $context, $hm)) {
                    $height = (int) $hm[1];
                }

                $key = $this->normalize_public_resource_url($url);
                $candidates[$key] = array(
                    'url' => $url,
                    'width' => $width,
                    'height' => $height,
                    'layer' => 'module-bg',
                    'offset' => $offset,
                    'context' => strtolower($context),
                    'sr7_module_background' => true,
                );
            }

            return array_values($candidates);
        }

        private function is_generated_image_fresh_for_source($generated_path, $source_url)
        {
            $generated_path = (string) $generated_path;
            if ('' === $generated_path || !is_readable($generated_path)) {
                return false;
            }

            $source_path = $this->resolve_local_path_from_public_url((string) $source_url);
            if ('' === $source_path || !is_readable($source_path)) {
                return true;
            }

            $generated_mtime = @filemtime($generated_path);
            $source_mtime = @filemtime($source_path);
            if (!$generated_mtime || !$source_mtime) {
                return true;
            }

            return (int) $generated_mtime >= (int) $source_mtime;
        }

        private function prefer_existing_nextgen_public_image_url($url)
        {
            $url = $this->normalize_public_resource_url($url);
            if ('' === $url) {
                return '';
            }

            if ($this->is_sr7_generated_image_list_url($url)) {
                return $this->prefer_existing_nextgen_revslider_url($url);
            }

            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if ('' === $path || false === strpos($path, '/wp-content/uploads/')) {
                return $url;
            }

            $relative = ltrim((string) substr($path, strpos($path, '/wp-content/uploads/') + strlen('/wp-content/uploads/')), '/');
            if ('' === $relative || !preg_match('/\.(png|jpe?g|webp|avif)$/i', $relative)) {
                return $url;
            }

            $relative_no_ext = preg_replace('/\.(png|jpe?g|webp|avif)$/i', '', $relative);
            if (!is_string($relative_no_ext) || '' === $relative_no_ext) {
                return $url;
            }

            if (defined('UCWP_AVIF_DIR') && defined('UCWP_AVIF_URL')) {
                $avif_path = trailingslashit(UCWP_AVIF_DIR) . $relative_no_ext . '.avif';
                if ($this->is_generated_image_fresh_for_source($avif_path, $url)) {
                    return $this->normalize_public_resource_url(trailingslashit(UCWP_AVIF_URL) . $relative_no_ext . '.avif');
                }
            }

            if (defined('UCWP_WEBP_DIR') && defined('UCWP_WEBP_URL')) {
                $webp_path = trailingslashit(UCWP_WEBP_DIR) . $relative_no_ext . '.webp';
                if ($this->is_generated_image_fresh_for_source($webp_path, $url)) {
                    return $this->normalize_public_resource_url(trailingslashit(UCWP_WEBP_URL) . $relative_no_ext . '.webp');
                }
            }

            return $url;
        }

        private function find_sr7_first_slide_lcp_candidates($html, $limit = 3)
        {
            $html = (string) $html;
            $limit = max(1, min(5, (int) $limit));
            if ('' === $html || (false === stripos($html, 'sr7') && false === stripos($html, '/revslider/o/'))) {
                return array();
            }

            $module_bg_candidates = $this->extract_sr7_module_background_image_candidates($html);
            $layer_candidates = $this->extract_sr7_first_slide_layer_image_candidates($html);
            $generated_urls = array();
            if (empty($module_bg_candidates) && empty($layer_candidates)) {
                return array();
            }

            $target_dimensions = $this->extract_sr7_first_slide_layer_dimensions($html);
            $candidates = array();

            foreach ($module_bg_candidates as $index => $layer) {
                $raw_url = isset($layer['url']) ? (string) $layer['url'] : '';
                $preferred_url = $this->prefer_existing_nextgen_public_image_url($raw_url);
                $candidate = $this->build_lcp_candidate_from_values($preferred_url, array(
                    'tag' => 'SR7-MODULE-BG',
                    'attribute' => 'sr7-json-bg',
                    'class' => 'sr7 module background hero slider',
                    'id' => 'module-bg',
                    'style' => 'sr7 module background image ' . (isset($layer['context']) ? (string) $layer['context'] : ''),
                    'width' => isset($layer['width']) ? (string) (int) $layer['width'] : '',
                    'height' => isset($layer['height']) ? (string) (int) $layer['height'] : '',
                ));
                if (null === $candidate) {
                    continue;
                }
                $candidate = $this->hydrate_lcp_candidate_dimensions($candidate, $raw_url);
                $area = isset($candidate['area']) ? (int) $candidate['area'] : 0;
                $candidate['is_sr7'] = true;
                $candidate['sr7_verified_first_slide'] = true;
                $candidate['sr7_module_background'] = true;
                $candidate['sr7_role'] = 'module-background';
                $candidate['lcp_reason'] = 'sr7-largest-eligible';
                $candidate['source_url'] = $this->normalize_public_resource_url($raw_url);
                $candidate['raw_url'] = $preferred_url;
                $candidate['score'] += 850 + max(0, 120 - ((int) $index * 4)) + min(900, (int) floor($area / 1000));
                $boundary_offset = $this->find_sr7_visual_boundary_offset($html);
                if ($boundary_offset > 0) {
                    $candidate['boundary_offset'] = $boundary_offset;
                }
                $candidates[] = $candidate;
            }

            foreach ($layer_candidates as $index => $layer) {
                $raw_url = isset($layer['url']) ? (string) $layer['url'] : '';
                $preferred_url = $this->prefer_existing_nextgen_public_image_url($raw_url);
                $dimensions = array(
                    'width' => isset($layer['width']) ? (int) $layer['width'] : 0,
                    'height' => isset($layer['height']) ? (int) $layer['height'] : 0,
                );
                if ($dimensions['width'] <= 0 || $dimensions['height'] <= 0) {
                    $dimensions = $this->get_public_image_dimensions($preferred_url);
                }
                if (empty($dimensions)) {
                    $dimensions = $this->get_public_image_dimensions($raw_url);
                }

                $width = isset($dimensions['width']) ? (int) $dimensions['width'] : 0;
                $height = isset($dimensions['height']) ? (int) $dimensions['height'] : 0;
                if ($width <= 0 || $height <= 0) {
                    $width = isset($layer['width']) ? (int) $layer['width'] : 0;
                    $height = isset($layer['height']) ? (int) $layer['height'] : 0;
                }

                $candidate = $this->build_lcp_candidate_from_values($preferred_url, array(
                    'tag' => 'SR7-IMG',
                    'attribute' => 'sr7-json',
                    'class' => 'sr7 first-slide revslider json layer',
                    'id' => isset($layer['layer']) ? (string) $layer['layer'] : '',
                    'style' => 'sr7 first slide layer image ' . (isset($layer['context']) ? (string) $layer['context'] : ''),
                    'width' => $width > 0 ? (string) $width : '',
                    'height' => $height > 0 ? (string) $height : '',
                ));
                if (null === $candidate) {
                    continue;
                }

                $area = max(0, $width * $height);
                $candidate['is_sr7'] = true;
                $candidate['sr7_verified_first_slide'] = true;
                $candidate['sr7_json_layer'] = true;
                $candidate['source_url'] = $this->normalize_public_resource_url($raw_url);
                $candidate['raw_url'] = $preferred_url;
                $candidate = $this->hydrate_lcp_candidate_dimensions($candidate, $raw_url);
                $area = isset($candidate['area']) ? (int) $candidate['area'] : max(0, $width * $height);
                $candidate['sr7_role'] = 'slide-layer';
                $candidate['lcp_reason'] = 'sr7-largest-eligible';
                $candidate['score'] += 780 + max(0, 150 - ((int) $index * 6)) + min(900, (int) floor($area / 1000));
                if ($this->matches_sr7_dimension_target($width, $height, $target_dimensions)) {
                    $candidate['score'] += 250;
                }
                if ($width >= 800 || $height >= 520) {
                    $candidate['score'] += 260;
                }

                $boundary_offset = $this->find_sr7_visual_boundary_offset($html);
                if ($boundary_offset > 0) {
                    $candidate['boundary_offset'] = $boundary_offset;
                }

                $candidates[] = $candidate;
            }

            foreach ($generated_urls as $index => $url) {
                $preferred_url = $this->prefer_existing_nextgen_revslider_url($url);
                $dimensions = $this->get_public_image_dimensions($preferred_url);
                if (empty($dimensions)) {
                    $dimensions = $this->get_public_image_dimensions($url);
                }

                $width = isset($dimensions['width']) ? (int) $dimensions['width'] : 0;
                $height = isset($dimensions['height']) ? (int) $dimensions['height'] : 0;
                if ($width <= 0 || $height <= 0) {
                    continue;
                }

                $matched_target = $this->matches_sr7_dimension_target($width, $height, $target_dimensions);

                $candidate = $this->build_lcp_candidate_from_values($preferred_url, array(
                    'tag' => 'SR7-IMG',
                    'attribute' => 'script',
                    'class' => 'sr7 first-slide revslider optimized',
                    'style' => 'sr7 first slide generated optimized image',
                    'width' => (string) $width,
                    'height' => (string) $height,
                ));
                if (null === $candidate) {
                    continue;
                }

                $area = $width * $height;
                $candidate['is_sr7'] = true;
                $candidate['sr7_verified_first_slide'] = true;
                $candidate['sr7_generated'] = true;
                $candidate['source_url'] = $this->normalize_public_resource_url($url);
                $candidate['raw_url'] = $preferred_url;
                $candidate['score'] += 700 + max(0, 120 - ((int) $index * 4)) + min(500, (int) floor($area / 1000));
                if ($matched_target) {
                    $candidate['score'] += 300;
                }
                if ($width >= 800 || $height >= 520) {
                    $candidate['score'] += 160;
                }

                $boundary_offset = $this->find_sr7_visual_boundary_offset($html);
                if ($boundary_offset > 0) {
                    $candidate['boundary_offset'] = $boundary_offset;
                }

                $candidates[] = $candidate;
            }

            if (empty($candidates)) {
                return array();
            }

            $candidates = $this->sort_lcp_candidates_by_area_then_score($candidates);

            $unique = array();
            $seen = array();
            foreach ($candidates as $candidate) {
                $key = $this->normalize_public_resource_url(isset($candidate['url']) ? $candidate['url'] : '');
                if ('' === $key || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $unique[] = $candidate;
                if (count($unique) >= $limit) {
                    break;
                }
            }

            return $unique;
        }


        private function extract_sr7_first_slide_layer_dimensions($html)
        {
            $html = str_replace('\/', '/', (string) $html);
            $slice = '';
            if (preg_match('~"slides"\s*:\s*\{\s*"1"\s*:\s*\{(.+?)(?:,"2"\s*:|,"3"\s*:|,"4"\s*:)~s', $html, $match)) {
                $slice = (string) $match[1];
            } else {
                $pos = strpos($html, '"slides":{"1"');
                if (false !== $pos) {
                    $slice = substr($html, $pos, 80000);
                }
            }

            if ('' === $slice) {
                return array();
            }

            $targets = array();
            if (preg_match_all('~"subtype"\s*:\s*"image".{0,1800}?"size"\s*:\s*\{.{0,800}?"w"\s*:\s*\[\s*"?(\d+)px"?.{0,300}?"h"\s*:\s*\[\s*"?(\d+)px"?~s', $slice, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $width = isset($match[1]) ? (int) $match[1] : 0;
                    $height = isset($match[2]) ? (int) $match[2] : 0;
                    if ($width > 0 && $height > 0 && $width <= 5000 && $height <= 5000) {
                        $targets[$width . 'x' . $height] = array('width' => $width, 'height' => $height);
                    }
                }
            }

            return array_values($targets);
        }

        private function matches_sr7_dimension_target($width, $height, array $targets)
        {
            $width = (int) $width;
            $height = (int) $height;
            if ($width <= 0 || $height <= 0 || empty($targets)) {
                return false;
            }

            foreach ($targets as $target) {
                $target_width = isset($target['width']) ? (int) $target['width'] : 0;
                $target_height = isset($target['height']) ? (int) $target['height'] : 0;
                if ($target_width <= 0 || $target_height <= 0) {
                    continue;
                }
                if (abs($width - $target_width) <= 3 && abs($height - $target_height) <= 3) {
                    return true;
                }
            }

            return false;
        }

        private function extract_sr7_generated_image_urls_from_html($html)
        {
            $html = str_replace('\/', '/', (string) $html);
            $urls = array();

            // Deliberately stop at whitespace, quotes, or tag delimiters. These are generated SR7 hash assets, not hardcoded IDs.
            $patterns = array(
                "~https?://[^\s\"'<>\)\(]+/wp-content/(?:uploads|cache/ultracache-(?:avif|webp))/revslider/o/[^\s\"'<>\)\(]+~i",
                "~/wp-content/(?:uploads|cache/ultracache-(?:avif|webp))/revslider/o/[^\s\"'<>\)\(]+~i",
            );

            foreach ($patterns as $pattern) {
                if (!preg_match_all($pattern, $html, $matches)) {
                    continue;
                }
                foreach ($matches[0] as $raw_url) {
                    $url = trim((string) $raw_url);
                    $url = trim($url, " \t\n\r\0\x0B\"'()[]{}.,;");
                    if (0 === strpos($url, '/')) {
                        $url = $this->absolutize_public_resource_url($url);
                    }
                    if ('' !== $url && $this->is_lcp_candidate_image_url($url) && $this->is_sr7_generated_image_list_url($url)) {
                        $normalized = $this->normalize_public_resource_url($url);
                        $urls[$normalized] = $normalized;
                    }
                }
            }

            return array_values($urls);
        }

        private function prefer_existing_nextgen_revslider_url($url)
        {
            $url = $this->normalize_public_resource_url($url);
            if ('' === $url) {
                return '';
            }

            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if ('' === $path || !preg_match('#/wp-content/(?:uploads|cache/ultracache-(?:avif|webp))/revslider/o/(.+?)\.(avif|webp|png|jpe?g)(?:$|\?)#i', $path, $match)) {
                return $url;
            }

            $relative_no_ext = isset($match[1]) ? ltrim((string) $match[1], '/') : '';
            if ('' === $relative_no_ext) {
                return $url;
            }

            if (defined('UCWP_AVIF_DIR') && defined('UCWP_AVIF_URL')) {
                $avif_path = trailingslashit(UCWP_AVIF_DIR) . 'revslider/o/' . $relative_no_ext . '.avif';
                if ($this->is_generated_image_fresh_for_source($avif_path, $url)) {
                    return $this->normalize_public_resource_url(trailingslashit(UCWP_AVIF_URL) . 'revslider/o/' . $relative_no_ext . '.avif');
                }
            }

            if (defined('UCWP_WEBP_DIR') && defined('UCWP_WEBP_URL')) {
                $webp_path = trailingslashit(UCWP_WEBP_DIR) . 'revslider/o/' . $relative_no_ext . '.webp';
                if ($this->is_generated_image_fresh_for_source($webp_path, $url)) {
                    return $this->normalize_public_resource_url(trailingslashit(UCWP_WEBP_URL) . 'revslider/o/' . $relative_no_ext . '.webp');
                }
            }

            return $url;
        }

        private function get_public_image_dimensions($url)
        {
            $path = $this->resolve_local_path_from_public_url($url);
            if ('' === $path || !is_readable($path)) {
                return array();
            }

            $size = @getimagesize($path);
            if (!is_array($size) || empty($size[0]) || empty($size[1])) {
                return array();
            }

            return array('width' => (int) $size[0], 'height' => (int) $size[1]);
        }

        private function find_sr7_visual_boundary_offset($html)
        {
            $html = (string) $html;
            foreach (array('</sr7-module>', '</rs-module>', '</sr7-slide>') as $needle) {
                $pos = stripos($html, $needle);
                if (false !== $pos) {
                    return (int) $pos + strlen($needle);
                }
            }
            foreach (array('<sr7-module', '<rs-module', '<sr7-slide') as $needle) {
                $pos = stripos($html, $needle);
                if (false !== $pos) {
                    return (int) $pos;
                }
            }
            return -1;
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

            foreach ($this->find_sr7_first_slide_lcp_candidates($html, 3) as $first_slide_candidate) {
                $candidates[] = $first_slide_candidate;
            }

            foreach ($this->find_sr7_static_slide_lcp_candidates($html, 1) as $static_slide_candidate) {
                $candidates[] = $static_slide_candidate;
            }

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

            $candidates = $this->sort_lcp_candidates_by_area_then_score($candidates);

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
            $attribute_name = (string) $attribute;
            $processed = $this->get_html_tag_attribute_with_processor($html, $attribute_name);
            if (null !== $processed) {
                return (string) $processed;
            }

            $attribute = preg_quote($attribute_name, '/');
            if (preg_match('/\b' . $attribute . '\s*=\s*("|\')(.*?)\1/i', (string) $html, $matches) && isset($matches[2])) {
                return html_entity_decode((string) $matches[2], ENT_QUOTES, 'UTF-8');
            }

            if (preg_match('/\b' . $attribute . '\s*=\s*([^\s"\'=<>`]+)/i', (string) $html, $matches) && isset($matches[1])) {
                return html_entity_decode((string) $matches[1], ENT_QUOTES, 'UTF-8');
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

            $processed = $this->boost_lcp_candidate_markup_with_processor($html, $candidate, $tag, $attribute, $raw_url);
            if (is_string($processed)) {
                return $processed;
            }

            $tag_name = ('SR7-IMG' === $tag) ? 'sr7-img' : strtolower($tag);
            $pattern = '~<' . $tag_name . '\b[^>]*\b' . preg_quote($attribute, '~') . '=(["\'])' . preg_quote($raw_url, '~') . '\1[^>]*>~i';
            return (string) preg_replace_callback(
                $pattern,
                function ($matches) use ($tag, $tag_name, $candidate) {
                    $replacement = $matches[0];
                    if (false === stripos($replacement, 'fetchpriority=')) {
                        $replacement = preg_replace('~<' . preg_quote($tag_name, '~') . '\b~i', '<' . $tag_name . ' fetchpriority="high"', $replacement, 1);
                    }

                    $replacement = $this->set_lcp_marker_on_start_tag($replacement, ('SR7-IMG' === $tag || !empty($candidate['is_sr7'])));

                    if (!empty($candidate['is_sr7'])) {
                        if (!empty($candidate['sr7_role'])) {
                            $replacement = $this->set_or_add_html_tag_attribute($replacement, 'data-ucwp-sr7-role', (string) $candidate['sr7_role']);
                        }
                        if (!empty($candidate['lcp_reason'])) {
                            $replacement = $this->set_or_add_html_tag_attribute($replacement, 'data-ucwp-lcp-reason', (string) $candidate['lcp_reason']);
                        }
                        if (isset($candidate['score'])) {
                            $replacement = $this->set_or_add_html_tag_attribute($replacement, 'data-ucwp-lcp-score', (string) (int) $candidate['score']);
                        }
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

        private function boost_lcp_candidate_markup_with_processor($html, array $candidate, $tag, $attribute, $raw_url)
        {
            if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html) {
                return null;
            }

            $tag = strtoupper((string) $tag);
            $tag_name = ('SR7-IMG' === $tag) ? 'SR7-IMG' : $tag;
            $attribute = strtolower((string) $attribute);
            $normalized_raw_url = $this->normalize_public_resource_url($raw_url);

            try {
                $processor = new WP_HTML_Tag_Processor($html);
                $changed = false;
                while ($processor->next_tag($tag_name)) {
                    $value = $processor->get_attribute($attribute);
                    if (!$this->lcp_candidate_attribute_value_matches($value, $attribute, $normalized_raw_url, $raw_url)) {
                        continue;
                    }

                    if ('high' !== (string) $processor->get_attribute('fetchpriority')) {
                        $processor->set_attribute('fetchpriority', 'high');
                        $changed = true;
                    }

                    $marker_attribute = ('SR7-IMG' === $tag || !empty($candidate['is_sr7'])) ? 'data-ucwp-sr7-lcp' : 'data-ucwp-lcp';
                    if (null === $processor->get_attribute($marker_attribute)) {
                        $processor->set_attribute($marker_attribute, '1');
                        $changed = true;
                    }

                    if (!empty($candidate['is_sr7'])) {
                        if (!empty($candidate['sr7_role']) && (string) $processor->get_attribute('data-ucwp-sr7-role') !== (string) $candidate['sr7_role']) {
                            $processor->set_attribute('data-ucwp-sr7-role', (string) $candidate['sr7_role']);
                            $changed = true;
                        }
                        if (!empty($candidate['lcp_reason']) && (string) $processor->get_attribute('data-ucwp-lcp-reason') !== (string) $candidate['lcp_reason']) {
                            $processor->set_attribute('data-ucwp-lcp-reason', (string) $candidate['lcp_reason']);
                            $changed = true;
                        }
                        if (isset($candidate['score']) && (string) $processor->get_attribute('data-ucwp-lcp-score') !== (string) (int) $candidate['score']) {
                            $processor->set_attribute('data-ucwp-lcp-score', (string) (int) $candidate['score']);
                            $changed = true;
                        }
                    }

                    if ('IMG' === $tag || 'SR7-IMG' === $tag) {
                        $loading = $processor->get_attribute('loading');
                        if (null === $loading || false === $loading || 'lazy' === strtolower((string) $loading)) {
                            $processor->set_attribute('loading', 'eager');
                            $changed = true;
                        }

                        if (null === $processor->get_attribute('decoding')) {
                            $processor->set_attribute('decoding', 'sync');
                            $changed = true;
                        }
                    }

                    break;
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

        private function lcp_candidate_attribute_value_matches($value, $attribute, $normalized_raw_url, $raw_url = '')
        {
            if (!is_string($value) || '' === trim($value)) {
                return false;
            }

            $attribute = strtolower((string) $attribute);
            if (in_array($attribute, array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset'), true)) {
                foreach ($this->extract_candidate_urls_from_srcset($value) as $candidate_url) {
                    if ($this->normalize_public_resource_url($candidate_url) === $normalized_raw_url) {
                        return true;
                    }
                }
                return false;
            }

            if ('style' === $attribute) {
                foreach ($this->extract_candidate_urls_from_style($value) as $candidate_url) {
                    if ($this->normalize_public_resource_url($candidate_url) === $normalized_raw_url) {
                        return true;
                    }
                }
                return false;
            }

            return $this->normalize_public_resource_url($value) === $normalized_raw_url || ('' !== (string) $raw_url && (string) $value === (string) $raw_url);
        }

        private function get_sr7_generated_image_list_key($url)
        {
            $url = $this->normalize_public_resource_url($url);
            if ('' === $url) {
                return '';
            }

            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if ('' === $path) {
                return '';
            }

            if (!preg_match('#/wp-content/(?:uploads|cache/ultracache-(?:avif|webp))/revslider/o/(.+?)\.(?:avif|webp|png|jpe?g|gif)(?:$|\?)#i', $path, $match)) {
                return '';
            }

            $relative_no_ext = isset($match[1]) ? trim((string) $match[1], '/') : '';
            if ('' === $relative_no_ext) {
                return '';
            }

            return strtolower('revslider/o/' . $relative_no_ext);
        }

        private function resolve_sr7_generated_image_list_source_url($generated_url, $html)
        {
            $generated_url = $this->normalize_public_resource_url($generated_url);
            if ('' === $generated_url || !$this->is_sr7_generated_image_list_url($generated_url)) {
                return '';
            }

            $target_key = $this->get_sr7_generated_image_list_key($generated_url);
            if ('' === $target_key || !is_string($html) || '' === $html || false === stripos($html, 'data-dbsrc')) {
                return '';
            }

            $scan_html = (string) $html;
            if (preg_match_all('~<image_lists\b[^>]*>.*?</image_lists>~is', $scan_html, $image_list_matches) && !empty($image_list_matches[0])) {
                $scan_html = implode("\n", $image_list_matches[0]);
            }

            if (!preg_match_all('/<img\b[^>]*>/i', $scan_html, $tag_matches)) {
                return '';
            }

            foreach ($tag_matches[0] as $tag_html) {
                $tag_html = (string) $tag_html;
                $dbsrc = $this->extract_attribute_from_html_tag($tag_html, 'data-dbsrc');
                if ('' === $dbsrc) {
                    continue;
                }

                $tag_urls = array();
                foreach (array('src', 'data-src', 'data-lazy-src', 'data-lazyload', 'data-image', 'data-origin', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image') as $attribute) {
                    $value = $this->extract_attribute_from_html_tag($tag_html, $attribute);
                    if ('' !== $value) {
                        $tag_urls[] = $value;
                    }
                }
                foreach (array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset') as $attribute) {
                    foreach ($this->extract_candidate_urls_from_srcset($this->extract_attribute_from_html_tag($tag_html, $attribute)) as $srcset_url) {
                        $tag_urls[] = $srcset_url;
                    }
                }

                $matches_target = false;
                foreach ($tag_urls as $tag_url) {
                    $tag_url = (string) $tag_url;
                    if (0 === strpos($tag_url, '//')) {
                        $tag_url = (is_ssl() ? 'https:' : 'http:') . $tag_url;
                    } elseif (0 === strpos($tag_url, '/')) {
                        $tag_url = $this->absolutize_public_resource_url($tag_url);
                    }

                    if ($this->get_sr7_generated_image_list_key($tag_url) === $target_key) {
                        $matches_target = true;
                        break;
                    }
                }

                if (!$matches_target) {
                    continue;
                }

                $decoded = base64_decode($dbsrc, true);
                if (!is_string($decoded) || '' === trim($decoded)) {
                    continue;
                }

                $decoded = trim($decoded);
                if (0 === strpos($decoded, '//')) {
                    $decoded = (is_ssl() ? 'https:' : 'http:') . $decoded;
                } elseif (0 === strpos($decoded, '/')) {
                    $decoded = $this->absolutize_public_resource_url($decoded);
                }

                $decoded = $this->normalize_public_resource_url($decoded);
                if ('' === $decoded || !$this->is_lcp_candidate_image_url($decoded)) {
                    continue;
                }

                return $decoded;
            }

            return '';
        }

        private function is_sr7_generated_image_list_url($url)
        {
            $url = $this->normalize_public_resource_url($url);
            if ('' === $url) {
                return false;
            }

            return false !== stripos($url, '/wp-content/uploads/revslider/o/')
                || false !== stripos($url, '/wp-content/cache/ultracache-avif/revslider/o/')
                || false !== stripos($url, '/wp-content/cache/ultracache-webp/revslider/o/');
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
            $sr7_source_url = $this->resolve_sr7_generated_image_list_source_url($src, $html);
            if ('' !== $sr7_source_url) {
                $src = $sr7_source_url;
            }

            $src = $this->prefer_existing_lcp_preload_equivalent_url($src);
            $src = esc_url($this->normalize_public_resource_url($src));
            if ('' === $src) {
                return $html;
            }

            if ($this->should_skip_lcp_preload_url($src)) {
                return $html;
            }

            $is_same_origin = $this->is_same_origin_public_resource_url($src);
            $mime_type = $this->get_lcp_preload_image_type($src);

            $processed = $this->ensure_lcp_preload_link_with_processor($html, $src, $is_same_origin, $mime_type);
            if (is_string($processed)) {
                return $processed;
            }

            $pattern = '~<link\b[^>]*\brel=(["\'])preload\1[^>]*\bas=(["\'])image\2[^>]*\bhref=(["\'])' . preg_quote($src, '~') . '\3[^>]*>~i';
            if (preg_match($pattern, $html, $matches)) {
                $existing = (string) $matches[0];
                $replacement = $existing;

                if (false === stripos($replacement, 'fetchpriority=')) {
                    $replacement = rtrim(substr($replacement, 0, -1)) . ' fetchpriority="high">';
                }

                if ('' !== $mime_type && false === stripos($replacement, ' type=')) {
                    $replacement = rtrim(substr($replacement, 0, -1)) . ' type="' . esc_attr($mime_type) . '">';
                }

                if (false === stripos($replacement, ' data-ucwp-lcp-preload=')) {
                    $replacement = rtrim(substr($replacement, 0, -1)) . ' data-ucwp-lcp-preload="1">';
                }
                if (false === stripos($replacement, ' data-ucwp-lcp-preload-reason=')) {
                    $replacement = rtrim(substr($replacement, 0, -1)) . ' data-ucwp-lcp-preload-reason="lcp-image-priority">';
                }

                if ($is_same_origin) {
                    $replacement = (string) preg_replace('/\s+crossorigin(?:\s*=\s*(?:["\'][^"\']*["\']|[^\s>]+))?/i', '', $replacement);
                } elseif (false === stripos($replacement, 'crossorigin=')) {
                    $replacement = rtrim(substr($replacement, 0, -1)) . ' crossorigin="anonymous">';
                }

                if ($replacement !== $existing) {
                    $html = preg_replace($pattern, addcslashes($replacement, '\\$'), $html, 1);
                }

                return $html;
            }

            $link = '<link rel="preload" as="image" href="' . $src . '"';
            if ('' !== $mime_type) {
                $link .= ' type="' . esc_attr($mime_type) . '"';
            }
            $link .= ' fetchpriority="high" data-ucwp-lcp-preload="1" data-ucwp-lcp-preload-reason="lcp-image-priority"';
            if (!$is_same_origin) {
                $link .= ' crossorigin="anonymous"';
            }
            $link .= '>';
            if (false === stripos($html, '</head>')) {
                return $html;
            }

            return $this->insert_html_before_closing_head($html, $link);
        }

        private function ensure_lcp_preload_link_with_processor($html, $src, $is_same_origin = false, $mime_type = '')
        {
            if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || false === stripos($html, '<link')) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor($html);
                $changed = false;
                $found = false;
                $normalized_src = $this->normalize_public_resource_url($src);

                while ($processor->next_tag('LINK')) {
                    $rel = $processor->get_attribute('rel');
                    $as = $processor->get_attribute('as');
                    $href = $processor->get_attribute('href');
                    if (!is_string($rel) || !is_string($as) || !is_string($href)) {
                        continue;
                    }

                    if (false === stripos($rel, 'preload') || 'image' !== strtolower(trim($as))) {
                        continue;
                    }

                    if ($this->normalize_public_resource_url($href) !== $normalized_src) {
                        continue;
                    }

                    $found = true;
                    if ('high' !== (string) $processor->get_attribute('fetchpriority')) {
                        $processor->set_attribute('fetchpriority', 'high');
                        $changed = true;
                    }
                    if ('' !== (string) $mime_type && null === $processor->get_attribute('type')) {
                        $processor->set_attribute('type', (string) $mime_type);
                        $changed = true;
                    }
                    if ('1' !== (string) $processor->get_attribute('data-ucwp-lcp-preload')) {
                        $processor->set_attribute('data-ucwp-lcp-preload', '1');
                        $changed = true;
                    }
                    if (null === $processor->get_attribute('data-ucwp-lcp-preload-reason')) {
                        $processor->set_attribute('data-ucwp-lcp-preload-reason', 'lcp-image-priority');
                        $changed = true;
                    }

                    $existing_crossorigin = $processor->get_attribute('crossorigin');
                    if ($is_same_origin && null !== $existing_crossorigin) {
                        // WP_HTML_Tag_Processor does not exist on all supported WP versions and
                        // remove_attribute availability differs, so fall back to the regex path
                        // where same-origin image preloads can have crossorigin stripped safely.
                        return null;
                    }
                    if (!$is_same_origin && null === $existing_crossorigin) {
                        $processor->set_attribute('crossorigin', 'anonymous');
                        $changed = true;
                    }
                    break;
                }

                if (!$found) {
                    return null;
                }

                if (!$changed) {
                    return (string) $html;
                }

                $updated_html = $processor->get_updated_html();
                return is_string($updated_html) && '' !== $updated_html ? $updated_html : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        private function is_same_origin_public_resource_url($url)
        {
            $url = $this->normalize_public_resource_url($url);
            if ('' === $url) {
                return false;
            }

            $url_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
            if ('' === $url_host || '' === $home_host) {
                return 0 === strpos($url, '/');
            }

            return $url_host === $home_host;
        }

        private function get_lcp_preload_image_type($url)
        {
            $path = strtolower((string) wp_parse_url((string) $url, PHP_URL_PATH));
            if (preg_match('/\.avif$/i', $path)) {
                return 'image/avif';
            }
            if (preg_match('/\.webp$/i', $path)) {
                return 'image/webp';
            }
            if (preg_match('/\.jpe?g$/i', $path)) {
                return 'image/jpeg';
            }
            if (preg_match('/\.png$/i', $path)) {
                return 'image/png';
            }
            if (preg_match('/\.gif$/i', $path)) {
                return 'image/gif';
            }

            return '';
        }

        private function should_skip_lcp_preload_url($url)
        {
            $url = $this->normalize_public_resource_url($url);
            if ('' === $url) {
                return true;
            }

            return !$this->is_lcp_candidate_image_url($url);
        }

        private function prefer_existing_lcp_preload_equivalent_url($url)
        {
            $url = $this->normalize_public_resource_url($url);
            if ('' === $url || !$this->is_lcp_candidate_image_url($url)) {
                return $url;
            }

            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if (preg_match('/\.(?:avif|webp)$/i', $path)) {
                return $url;
            }

            $settings = $this->get_settings();
            $media_enabled = !empty($settings['media_optimization_enabled']) || !empty($settings['mediaOptimizationEnabled']);
            if (!$media_enabled) {
                return $url;
            }

            $preferred = $this->prefer_existing_nextgen_public_image_url($url);
            $preferred = $this->normalize_public_resource_url($preferred);
            if ('' === $preferred || $preferred === $url || !$this->is_lcp_candidate_image_url($preferred)) {
                return $url;
            }

            $preferred_path = (string) wp_parse_url($preferred, PHP_URL_PATH);
            if ('' === $path || '' === $preferred_path) {
                return $url;
            }

            $original_base = preg_replace('/\.(?:png|jpe?g|webp|avif)$/i', '', $path);
            $preferred_base = preg_replace('/\.(?:png|jpe?g|webp|avif)$/i', '', $preferred_path);
            if (!is_string($original_base) || !is_string($preferred_base) || '' === $original_base || '' === $preferred_base) {
                return $url;
            }

            $uploads_marker = '/wp-content/uploads/';
            $avif_marker = '/wp-content/cache/ultracache-avif/';
            $webp_marker = '/wp-content/cache/ultracache-webp/';
            $original_relative = false !== strpos($original_base, $uploads_marker) ? substr($original_base, strpos($original_base, $uploads_marker) + strlen($uploads_marker)) : '';
            $preferred_relative = '';
            if (false !== strpos($preferred_base, $avif_marker)) {
                $preferred_relative = substr($preferred_base, strpos($preferred_base, $avif_marker) + strlen($avif_marker));
            } elseif (false !== strpos($preferred_base, $webp_marker)) {
                $preferred_relative = substr($preferred_base, strpos($preferred_base, $webp_marker) + strlen($webp_marker));
            }

            if ('' === $original_relative || '' === $preferred_relative || $original_relative !== $preferred_relative) {
                return $url;
            }

            return $preferred;
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

            $processed = $this->normalize_protocol_relative_tag_attributes_with_processor($html, $scheme);
            if (is_string($processed)) {
                $html = $processed;
            }

            $html = (string) preg_replace_callback(
                "/(\b(?:src|href|poster|data-src|data-lazy-src|data-bg|data-background|data-bg-image|data-background-image)=)([\"'])(?:\/\/)/i",
                function ($matches) use ($scheme) {
                    return $matches[1] . $matches[2] . $scheme . '://';
                },
                (string) $html
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

        private function normalize_protocol_relative_tag_attributes_with_processor($html, $scheme)
        {
            if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || false === strpos($html, '//')) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor($html);
                $changed = false;
                $url_attributes = array('src', 'href', 'poster', 'data-src', 'data-lazy-src', 'data-bg', 'data-background', 'data-bg-image', 'data-background-image');

                while ($processor->next_tag()) {
                    foreach ($url_attributes as $attribute) {
                        $value = $processor->get_attribute($attribute);
                        if (!is_string($value) || '' === $value || 0 !== strpos($value, '//')) {
                            continue;
                        }

                        $processor->set_attribute($attribute, (string) $scheme . ':' . $value);
                        $changed = true;
                    }

                    $srcset = $processor->get_attribute('srcset');
                    if (is_string($srcset) && false !== strpos($srcset, '//')) {
                        $parts = array_map('trim', explode(',', $srcset));
                        $srcset_changed = false;
                        foreach ($parts as $index => $part) {
                            $segments = preg_split('/\s+/', $part, 2);
                            if (!empty($segments[0]) && 0 === strpos($segments[0], '//')) {
                                $segments[0] = (string) $scheme . ':' . $segments[0];
                                $parts[$index] = trim(implode(' ', array_filter($segments, 'strlen')));
                                $srcset_changed = true;
                            }
                        }

                        if ($srcset_changed) {
                            $processor->set_attribute('srcset', implode(', ', $parts));
                            $changed = true;
                        }
                    }

                    $style = $processor->get_attribute('style');
                    if (is_string($style) && false !== strpos($style, 'url(//')) {
                        $updated_style = (string) preg_replace('/url\((\s*[\"\']?)\/\//i', 'url($1' . $scheme . '://', $style);
                        if ($updated_style !== $style) {
                            $processor->set_attribute('style', $updated_style);
                            $changed = true;
                        }
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


        private function optimize_self_hosted_font_css_links($html)
        {
            if (false === stripos($html, '<link') || false === stripos($html, '.css')) {
                return $html;
            }

            $processed = $this->optimize_self_hosted_font_css_links_with_processor($html);
            if (is_string($processed)) {
                return $processed;
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
                    return $this->set_or_add_html_tag_attribute($tag, 'href', $replacement_url);
                },
                $html
            );

            if (!empty($preload_urls)) {
                $html = $this->inject_font_preload_links($html, $preload_urls);
            }

            if (!empty($delayed_font_assets)) {
                $html = $this->inject_delayed_font_css_links($html, array_values($delayed_font_assets), 'ucwp-no-bundle-delayed-icon-fonts');
            }

            return $html;
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

                return $updated_html;
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
            if (false !== strpos($normalized_path, '/cache/ultracache/css-bundles/') || false !== strpos($normalized_path, '/cache/ultracache/font-css/')) {
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
            if (false !== strpos($source_path_lc, '/cache/ultracache/css-bundles/') || false !== strpos($source_path_lc, '/cache/ultracache/font-css/')) {
                return array();
            }

            $css = ucwp_safe_file_get_contents($source_path, 'build_optimized_font_css_asset', true);
            if (!is_string($css) || '' === $css || false === stripos($css, '@font-face')) {
                return array();
            }

            $optimized_css = $this->rewrite_self_hosted_font_css_content($css, $source_url);
            $settings = $this->get_settings();
            $delayed_font_css = '';
            $delayed_font_families = array();
            $delayed_font_patterns = array();
            $delayed_font_count = 0;

            if (!empty($settings['delay_icon_fonts'])) {
                $font_split = $this->split_delayed_icon_font_faces_from_css($optimized_css, $source_url, $settings);
                if (!empty($font_split['delayedCount']) && is_string($font_split['body'])) {
                    $optimized_css = (string) $font_split['body'];
                    $delayed_font_css = (string) ($font_split['delayedCss'] ?? '');
                    $delayed_font_families = array_values(array_unique(array_map('strval', (array) ($font_split['families'] ?? array()))));
                    $delayed_font_patterns = array_values(array_unique(array_map('strval', (array) ($font_split['patterns'] ?? array()))));
                    $delayed_font_count = max(0, (int) ($font_split['delayedCount'] ?? 0));
                }
            }

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
            if (!file_exists($file) || md5_file($file) !== md5($optimized_css)) {
                ucwp_safe_file_put_contents($file, $optimized_css, LOCK_EX, 'optimized font css');
            }

            $asset = array(
                'css_url'      => content_url('cache/ultracache/font-css/' . $hash . '.css'),
                'preload_urls' => $this->extract_preloadable_font_urls_from_css($optimized_css, 2),
            );

            if ('' !== trim($delayed_font_css)) {
                $delayed_font_content = trim($delayed_font_css) . "
";
                $delayed_font_hash = md5($source_url . '|delayed|' . md5($delayed_font_content));
                $delayed_font_file = $dir . 'delayed-' . $delayed_font_hash . '.css';
                if (!file_exists($delayed_font_file) || md5_file($delayed_font_file) !== md5($delayed_font_content)) {
                    ucwp_safe_file_put_contents($delayed_font_file, $delayed_font_content, LOCK_EX, 'delayed optimized font css');
                }
                $delayed_font_url = content_url('cache/ultracache/font-css/delayed-' . $delayed_font_hash . '.css');
                $asset['delayedFontUrl'] = $delayed_font_url;
                $asset['delayed_font_url'] = $delayed_font_url;
                $asset['delayedFontFaceBlocks'] = $delayed_font_count;
                $asset['delayedFontFamilies'] = $delayed_font_families;
                $asset['delayedFontPatterns'] = $delayed_font_patterns;
                $this->delayed_font_css_assets_current_request[$source_url] = $asset;
            }

            return $asset;
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
                if (false !== strpos($path, '/cache/ultracache/css-bundles/') || false !== strpos($path, '/cache/ultracache/font-css/')) {
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

            $script = '<script data-ucwp-font-css-map="1" data-ucwp-runtime-font-rewrite="1" data-ucwp-font-css-map-count="' . esc_attr((string) count($map)) . '" data-ucwp-font-css-map-source="' . esc_attr($source_label) . '">(function(){var map=' . $json . ';if(!map||typeof map!=="object"){map={};}var toAbs=function(url){if(!url){return "";}try{return new URL(url, document.baseURI).href;}catch(e){try{var a=document.createElement("a");a.href=url;return a.href||url;}catch(err){return url;}}};var rewrite=function(node){if(!node||node.nodeType!==1){return;}var tag=String(node.tagName||"").toLowerCase();if(tag!=="link"){return;}var rel=String(node.getAttribute("rel")||"").toLowerCase();if(rel.indexOf("stylesheet")===-1){return;}var href=node.getAttribute("href")||node.href||"";if(!href){return;}var abs=toAbs(href);if(abs&&map[abs]&&abs!==map[abs]){node.setAttribute("href",map[abs]);node.setAttribute("data-ucwp-runtime-font-rewrite-hit","1");try{node.href=map[abs];}catch(e){}}};var scan=function(root){try{var links=(root||document).querySelectorAll? (root||document).querySelectorAll("link[rel][href]") : [];for(var i=0;i<links.length;i++){rewrite(links[i]);}}catch(e){}};scan(document);try{var mo=new MutationObserver(function(list){for(var i=0;i<list.length;i++){var added=list[i]&&list[i].addedNodes?list[i].addedNodes:[];for(var j=0;j<added.length;j++){var node=added[j];rewrite(node);scan(node);}}});mo.observe(document.documentElement||document.head||document.body,{childList:true,subtree:true});}catch(e){}})();</script>';

            return $this->insert_html_before_closing_head($html, $script);
        }

        private function get_runtime_font_css_map_cache_key()
        {
            return 'ucwp_runtime_font_css_url_map_v2';
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
                    $block = (string) preg_replace('/([^;{}\s])\s+(font-display\s*:)/i', '$1; $2', $block);

                    if (false !== stripos($block, 'font-display')) {
                        $updated = preg_replace('/font-display\s*:\s*[^;}{]+;?/i', 'font-display: swap;', $block, 1);
                        return is_string($updated) && '' !== $updated ? $updated : $block;
                    }

                    $body = (string) preg_replace('/}\s*$/', '', $block, 1);
                    $body = rtrim($body);
                    if ('' !== $body && '{' !== substr($body, -1) && ';' !== substr($body, -1)) {
                        $body .= ';';
                    }

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

            $processed = $this->strip_probable_frontend_authoring_assets_with_processor($html);
            if (is_string($processed)) {
                return $processed;
            }

            foreach (array(
                '/<script\b[^>]*\bsrc=(\"|\')(.*?)\1[^>]*>\s*<\/script>/is',
                '/<link\b[^>]*\bhref=(\"|\')(.*?)\1[^>]*>/is',
            ) as $pattern) {
                $html = (string) preg_replace_callback(
                    $pattern,
                    function ($matches) {
                        $tag = (string) $matches[0];
                        $url = $this->extract_attribute_from_html_tag($tag, false !== stripos($tag, '<script') ? 'src' : 'href');
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

        private function strip_probable_frontend_authoring_assets_with_processor($html)
        {
            if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || (false === stripos($html, '<script') && false === stripos($html, '<link'))) {
                return null;
            }

            try {
                $processor = new WP_HTML_Tag_Processor($html);
                $changed = false;
                $tokens = array();
                $index = 0;

                while ($processor->next_tag()) {
                    $tag_name = strtoupper((string) $processor->get_tag());
                    if ('SCRIPT' !== $tag_name && 'LINK' !== $tag_name) {
                        continue;
                    }

                    $url = ('SCRIPT' === $tag_name) ? $processor->get_attribute('src') : $processor->get_attribute('href');
                    if (!is_string($url) || '' === $url) {
                        continue;
                    }

                    $tag_markup = $this->get_current_html_processor_tag_markup($processor, strtolower($tag_name));
                    if (!$this->should_strip_probable_frontend_authoring_asset($url, $tag_markup)) {
                        continue;
                    }

                    $token = 'ucwp-strip-authoring-' . md5($tag_name . '|' . $url . '|' . (++$index));
                    $processor->set_attribute('data-ucwp-strip-authoring-token', $token);
                    $tokens[$token] = strtolower($tag_name);
                    $changed = true;
                }

                if (!$changed) {
                    return null;
                }

                $updated_html = $processor->get_updated_html();
                if (!is_string($updated_html) || '' === $updated_html) {
                    return null;
                }

                foreach ($tokens as $token => $tag_name) {
                    if ('script' === $tag_name) {
                        $pattern = '/<script\b(?=[^>]*\bdata-ucwp-strip-authoring-token=(\"|\')' . preg_quote($token, '/') . '\1)[^>]*>\s*<\/script>/is';
                    } else {
                        $pattern = '/<link\b(?=[^>]*\bdata-ucwp-strip-authoring-token=(\"|\')' . preg_quote($token, '/') . '\1)[^>]*>/i';
                    }
                    $updated_html = preg_replace($pattern, '', $updated_html, 1);
                }

                if (!is_string($updated_html) || false !== stripos($updated_html, 'data-ucwp-strip-authoring-token=')) {
                    return null;
                }

                return $updated_html;
            } catch (\Throwable $e) {
                return null;
            }
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

        private function get_hard_security_excluded_query_args()
        {
            return array(
                '_wpnonce',
                '_ajax_nonce',
                'nonce',
                'security',
                'token',
                'auth',
                'auth_token',
                'access_token',
                'key',
                'order_key',
                'password',
                'pass',
                'pwd',
                'redirect_to',
                'customer-logout',
                'logout',
                'pay_for_order',
                'cancel_order',
                'download_file',
            );
        }

        private function merge_hard_security_excluded_query_args(array $excluded_query_args)
        {
            return array_values(array_unique(array_merge($excluded_query_args, $this->get_hard_security_excluded_query_args())));
        }

        private function query_contains_excluded_keys($query, array $excluded_query_args)
        {
            if ('' === (string) $query) {
                return false;
            }

            $lookup = array();
            foreach ($excluded_query_args as $excluded_query_arg) {
                $normalized_arg = sanitize_key((string) $excluded_query_arg);
                if ('' !== $normalized_arg) {
                    $lookup[$normalized_arg] = true;
                }
            }

            if (empty($lookup)) {
                return false;
            }

            parse_str((string) $query, $query_vars);
            foreach (array_keys($query_vars) as $query_key) {
                $normalized_key = sanitize_key((string) $query_key);
                if ('' !== $normalized_key && isset($lookup[$normalized_key])) {
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
            /*
             * Query-string HTML cache variants are intentionally allowlist-only.
             * An enabled query-string switch with an empty allowlist must not cache
             * every tracking/session/bot query as a separate homepage variant.
             */
            if (empty($allowlist)) {
                return '';
            }

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
            if ('' === (string) $query || empty($allowlist)) {
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

            $lookup = array();
            foreach ($candidate_args as $candidate_arg) {
                $normalized_arg = sanitize_key((string) $candidate_arg);
                if ('' !== $normalized_arg) {
                    $lookup[$normalized_arg] = true;
                }
            }

            if (empty($lookup)) {
                return '';
            }

            parse_str((string) $query, $query_vars);
            foreach (array_keys($query_vars) as $query_key) {
                $normalized_key = sanitize_key((string) $query_key);
                if ('' !== $normalized_key && isset($lookup[$normalized_key])) {
                    return $normalized_key;
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
                'query-allowlist-empty'     => 'Query-string caching requires a whitelist',
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

        private function is_profile_bypass_request()
        {
            $header_flag = sanitize_text_field(ucwp_server_value('HTTP_X_ULTRACACHE_PROFILE_BYPASS'));
            $query_flag = sanitize_text_field(ucwp_query_value('ucwp_profile_bypass'));
            if ('1' !== $header_flag && 'true' !== strtolower((string) $header_flag) && '1' !== $query_flag && 'true' !== strtolower((string) $query_flag)) {
                return false;
            }

            $token = sanitize_text_field(ucwp_server_value('HTTP_X_ULTRACACHE_TOKEN'));
            if ('' === $token) {
                $token = sanitize_text_field(ucwp_query_value('ucwp_rt'));
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
            $this->profile_request_checkpoint('should_bypass_start');
            $this->last_bypass_reason = '';
            $this->profile_request_checkpoint('should_bypass_before_get_settings');
            $settings = $this->get_settings();
            $this->profile_request_checkpoint('should_bypass_after_get_settings', array(
                'settings_count' => is_array($settings) ? count($settings) : 0,
            ));

            $this->profile_request_checkpoint('should_bypass_before_basic_checks');
            if (empty($settings['enabled'])) {
                $this->last_bypass_reason = 'disabled';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }

            if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
                $this->last_bypass_reason = 'donotcachepage';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }

            if (wp_doing_ajax() || wp_doing_cron()) {
                $this->last_bypass_reason = 'ajax-or-cron';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }

            if (function_exists('is_admin') && is_admin()) {
                $this->last_bypass_reason = 'admin';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }

            if (function_exists('is_feed') && is_feed()) {
                $this->last_bypass_reason = 'feed';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }

            if (function_exists('is_preview') && is_preview()) {
                $this->last_bypass_reason = 'preview';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }

            if (function_exists('is_customize_preview') && is_customize_preview()) {
                $this->last_bypass_reason = 'customize-preview';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }

            $request_method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
            if (!in_array($request_method, array('GET', 'HEAD'), true)) {
                $this->last_bypass_reason = 'request-method';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }
            $this->profile_request_checkpoint('should_bypass_after_basic_checks', array('request_method' => $request_method));

            $this->profile_request_checkpoint('should_bypass_before_internal_revalidate');
            if ($this->is_internal_revalidate_request()) {
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => 'internal-revalidate-allowed'));
                return false;
            }
            $this->profile_request_checkpoint('should_bypass_after_internal_revalidate');

            $this->profile_request_checkpoint('should_bypass_before_woocommerce_dynamic');
            if ($this->is_woocommerce_dynamic_request($url, $settings)) {
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => (string) $this->last_bypass_reason));
                return true;
            }
            $this->profile_request_checkpoint('should_bypass_after_woocommerce_dynamic');

            $this->profile_request_checkpoint('should_bypass_before_user_check');
            if (function_exists('is_user_logged_in') && is_user_logged_in() && empty($settings['cache_logged_in_users'])) {
                $this->last_bypass_reason = 'logged-in-user';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }
            $this->profile_request_checkpoint('should_bypass_after_user_check');

            $cookies_to_bypass = array(
                'wordpress_logged_in_',
                'wordpress_sec_',
                'comment_author_',
                'wp-postpass_',
                'woocommerce_items_in_cart',
                'woocommerce_cart_hash',
                'wp_woocommerce_session_',
            );

            $this->profile_request_checkpoint('should_bypass_before_cookie_checks', array('cookie_count' => count((array) $_COOKIE)));
            foreach ((array) $_COOKIE as $cookie_name => $cookie_value) {
                foreach ($cookies_to_bypass as $needle) {
                    if (false !== strpos((string) $cookie_name, $needle)) {
                        $this->last_bypass_reason = 'cookie-' . $needle;
                        $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                        return true;
                    }
                }
            }
            $this->profile_request_checkpoint('should_bypass_after_cookie_checks');

            if (empty($url)) {
                $this->profile_request_checkpoint('should_bypass_before_current_url');
                $url = $this->get_current_request_url();
                $this->profile_request_checkpoint('should_bypass_after_current_url', array('url_length' => strlen((string) $url)));
            }

            $this->profile_request_checkpoint('should_bypass_before_local_url_check');
            if (empty($url) || !$this->is_cacheable_local_url($url)) {
                $this->last_bypass_reason = 'non-local-url';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }
            $this->profile_request_checkpoint('should_bypass_after_local_url_check');

            $this->profile_request_checkpoint('should_bypass_before_url_parse');
            $parts = wp_parse_url($url);
            $path = isset($parts['path']) ? $this->normalize_path_value((string) $parts['path']) : '/';
            $query = isset($parts['query']) ? (string) $parts['query'] : '';
            if ('' !== $query) {
                parse_str($query, $ucwp_query_vars_for_cacheability);
                unset(
                    $ucwp_query_vars_for_cacheability['ucwp_revalidate'],
                    $ucwp_query_vars_for_cacheability['ucwp_rt'],
                    $ucwp_query_vars_for_cacheability['ucwp_store_profile'],
                    $ucwp_query_vars_for_cacheability['ucwp_callback_profile'],
                    $ucwp_query_vars_for_cacheability['ucwp_store_profile_verbose'],
                    $ucwp_query_vars_for_cacheability['ucwp_store_profile_verbose_settings'],
                    $ucwp_query_vars_for_cacheability['ucwp_profile_bypass'],
                    $ucwp_query_vars_for_cacheability['ucwp_profile_run']
                );
                $query = !empty($ucwp_query_vars_for_cacheability) ? http_build_query($ucwp_query_vars_for_cacheability) : '';
            }
            $this->profile_request_checkpoint('should_bypass_after_url_parse', array('path' => substr((string) $path, 0, 160), 'query_length' => strlen($query)));

            $excluded_paths = !empty($settings['excluded_paths']) && is_array($settings['excluded_paths']) ? $settings['excluded_paths'] : array();
            $this->profile_request_checkpoint('should_bypass_before_excluded_path_rules', array('rule_count' => count($excluded_paths)));
            if ($this->path_matches_any_rule($path, $excluded_paths)) {
                $this->last_bypass_reason = 'excluded-path';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }
            $this->profile_request_checkpoint('should_bypass_after_excluded_path_rules');

            $excluded_query_args = !empty($settings['excluded_query_args']) && is_array($settings['excluded_query_args']) ? $settings['excluded_query_args'] : array();
            $excluded_query_args = $this->merge_hard_security_excluded_query_args($excluded_query_args);
            if ('' !== $query) {
                $this->profile_request_checkpoint('should_bypass_before_excluded_query_args', array('rule_count' => count($excluded_query_args)));
                if ($this->query_contains_excluded_keys($query, $excluded_query_args)) {
                    $this->last_bypass_reason = 'excluded-query-arg';
                    $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                    return true;
                }
                $this->profile_request_checkpoint('should_bypass_after_excluded_query_args');

                $this->profile_request_checkpoint('should_bypass_before_query_allowlist');
                $query_allowlist = $this->get_query_allowlist($settings);
                $this->profile_request_checkpoint('should_bypass_after_query_allowlist', array('allowlist_count' => count($query_allowlist)));
                if (empty($settings['cache_query_strings'])) {
                    $this->last_bypass_reason = 'query-strings-disabled';
                    $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                    return true;
                }

                if (empty($query_allowlist)) {
                    $this->last_bypass_reason = 'query-allowlist-empty';
                    $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                    return true;
                }

                $this->profile_request_checkpoint('should_bypass_before_query_variant');
                if (!$this->query_has_cacheable_allowlisted_variant($query, $query_allowlist)) {
                    $this->last_bypass_reason = 'query-arg-not-allowlisted';
                    $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                    return true;
                }
                $this->profile_request_checkpoint('should_bypass_after_query_variant');
            }

            $this->profile_request_checkpoint('should_bypass_return', array('reason' => 'cacheable'));
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

        private function get_runtime_lock_file($name)
        {
            $safe = preg_replace('/[^a-z0-9_-]/', '-', strtolower((string) $name));
            $safe = trim((string) $safe, '-');
            if ('' === $safe) {
                $safe = 'runtime';
            }

            $dir = trailingslashit(UCWP_CACHE_DIR) . 'locks/';
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }

            return $dir . $safe . '.lock';
        }

        private function acquire_runtime_lock($name, $ttl = 180)
        {
            $name = (string) $name;
            if ('' === $name) {
                return false;
            }

            $file = $this->get_runtime_lock_file($name);
            $handle = @fopen($file, 'c+');
            if (!$handle) {
                return false;
            }

            if (!@flock($handle, LOCK_EX | LOCK_NB)) {
                $mtime = @filemtime($file);
                if ($mtime && (time() - (int) $mtime) > max(30, (int) $ttl)) {
                    @touch($file);
                }
                @fclose($handle);
                return false;
            }

            @ftruncate($handle, 0);
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
                                'Accept'                          => $this->get_accept_header_for_bucket($bucket),
                                'X-UltraCache-Warm'               => '1',
                                'X-UltraCache-Internal-Request'   => '1',
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
                'delayedFontFaceBlocks' => 0,
                'delayedFontFamilies' => array(),
                'delayedFontPatterns' => array(),
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

            $json = wp_json_encode($manifest);
            if (!is_string($json)) {
                return false;
            }

            return $this->write_cache_variant_atomically($this->get_frontpage_css_manifest_file(), $json);
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
            $seconds = (int) apply_filters('ucwp_css_bundle_cleanup_grace_seconds', 172800);
            return max(3600, min(604800, $seconds));
        }

        private function get_css_bundle_cleanup_max_deletes_per_run()
        {
            $max = (int) apply_filters('ucwp_css_bundle_cleanup_max_deletes_per_run', 60);
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
            $deleted = 0;
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

            if ($deleted > 0) {
                $this->record_cache_event('page-css-bundle-cleanup', array(
                    'deleted' => $deleted,
                    'max' => $max_deletes,
                    'grace_seconds' => $this->get_css_bundle_cleanup_grace_seconds(),
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
            $max_deletes = $force ? PHP_INT_MAX : $this->get_css_bundle_cleanup_max_deletes_per_run();
            foreach ((array) glob(trailingslashit($dir) . '*.css') as $file) {
                $file = (string) $file;
                if ('' === $file || !is_file($file)) {
                    continue;
                }
                if (!$force && $this->is_css_bundle_file_recently_protected($file)) {
                    continue;
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
                $this->cleanup_orphan_frontpage_css_bundles($manifest);
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
                    'bundleSignature' => (string) ($prepared['bundleSignature'] ?? ''),
                    'bundleContentHash' => (string) ($prepared['bundleContentHash'] ?? ''),
                    'delayedFontFile' => (string) ($prepared['delayedFontFile'] ?? ''),
                    'delayedFontUrl' => (string) ($prepared['delayedFontUrl'] ?? ''),
                    'delayedFontBytes' => isset($prepared['delayedFontBytes']) ? (int) $prepared['delayedFontBytes'] : 0,
                    'delayedFontFaceBlocks' => isset($prepared['delayedFontFaceBlocks']) ? (int) $prepared['delayedFontFaceBlocks'] : 0,
                    'delayedFontFamilies' => isset($prepared['delayedFontFamilies']) && is_array($prepared['delayedFontFamilies']) ? $prepared['delayedFontFamilies'] : array(),
                    'delayedFontPatterns' => isset($prepared['delayedFontPatterns']) && is_array($prepared['delayedFontPatterns']) ? $prepared['delayedFontPatterns'] : array(),
                    'sourceDetails' => isset($prepared['sourceDetails']) && is_array($prepared['sourceDetails']) ? $prepared['sourceDetails'] : array(),
                    'sourceBytesTotal' => isset($prepared['sourceBytesTotal']) ? (int) $prepared['sourceBytesTotal'] : 0,
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
                $this->cleanup_orphan_frontpage_css_bundles($manifest);

                $warm_result = $skip_final_warm ? array('success' => true, 'skipped' => true, 'message' => 'Final HTML warm skipped because the caller will warm the page after the CSS bundle is available.') : $this->warm_url($frontpage_url);
                $verification = $skip_final_warm ? array(
                    'checked' => false,
                    'cachedHtmlAvailable' => false,
                    'containsCssBundle' => false,
                    'cssBundleRefs' => 0,
                    'stylesheetLinks' => 0,
                    'inspectedFile' => '',
                    'message' => 'Final HTML warm skipped; caller must verify after writing cached HTML.',
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
                return array('success' => false, 'message' => 'Remote page did not return an HTML Content-Type.', 'html' => '');
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
                $stats['delayedFontFaceBlocks'] += max(0, (int) ($bundle['stats']['delayedFontFaceBlocks'] ?? 0));
                $stats['delayedFontFamilies'] = array_values(array_unique(array_merge((array) ($stats['delayedFontFamilies'] ?? array()), (array) ($bundle['stats']['delayedFontFamilies'] ?? array()))));
                $stats['delayedFontPatterns'] = array_values(array_unique(array_merge((array) ($stats['delayedFontPatterns'] ?? array()), (array) ($bundle['stats']['delayedFontPatterns'] ?? array()))));
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
                $delayed_blocks[] = "/* UltraCache Delayed Font Source: " . (string) $source_url . ('' !== $family ? ' | ' . $family : '') . " */\n" . $block;
                $result['delayedCount']++;
                return "\n/* UltraCache delayed icon font-face removed: " . ('' !== $family ? $family : 'matched font') . " */\n";
            }, $css);

            if (!is_string($result['body'])) {
                $result['body'] = $css;
                return $result;
            }

            if (!empty($delayed_blocks)) {
                $result['delayedCss'] = trim(implode("\n\n", $delayed_blocks)) . "\n";
                $result['families'] = array_values(array_unique(array_map('strval', $result['families'])));
                $result['patterns'] = array_values(array_unique(array_map('strval', $result['patterns'])));
            }

            return $result;
        }

        private function build_delayed_icon_fonts_stylesheet_markup(array $entry, $id = 'ucwp-delayed-icon-fonts')
        {
            $url = isset($entry['delayedFontUrl']) ? (string) $entry['delayedFontUrl'] : '';
            if ('' === $url) {
                return '';
            }

            $id = preg_replace('/[^a-z0-9_\-]/i', '-', (string) $id);
            if (!is_string($id) || '' === $id) {
                $id = 'ucwp-delayed-icon-fonts';
            }

            $href = esc_url($url);
            return '<link rel="stylesheet" id="' . esc_attr($id) . '" href="' . $href . '" media="print" onload="this.media=&quot;all&quot;" data-ucwp-delayed-icon-fonts="1" />'
                . '<noscript><link rel="stylesheet" href="' . $href . '" data-ucwp-delayed-icon-fonts-noscript="1" /></noscript>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
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
            $stats = array(
                'bundled' => 0,
                'skipped' => 0,
                'unresolved' => 0,
                'delayedFontFaceBlocks' => 0,
                'delayedFontFamilies' => array(),
                'delayedFontPatterns' => array(),
            );

            foreach ($assets as $asset) {
                $path = (string) ($asset['path'] ?? '');
                $url = (string) ($asset['url'] ?? '');
                $media = strtolower(trim((string) ($asset['media'] ?? '')));
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

                $original_bytes = strlen($css);
                $signature_parts[] = $url . '|' . (string) ucwp_safe_filemtime($path, 'frontpage_css_bundle_signature') . '|' . $original_bytes;
                $prepared_css = $this->prepare_css_asset_for_bundle($css, $url);
                $prepared_body = isset($prepared_css['body']) ? (string) $prepared_css['body'] : '';
                $font_split = $this->split_delayed_icon_font_faces_from_css($prepared_body, $url, $settings);
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
                    'message' => 'Not enough non-empty stylesheets were eligible for bundling.',
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
            $content_hash = md5($bundle_content);
            $signature = md5($mode . '|' . implode('||', $signature_parts) . '|' . $content_hash);
            $filename = 'bundle-' . $mode . '-' . $signature . '.css';
            $file = $dir . $filename;
            if (!file_exists($file) || md5_file($file) !== $content_hash) {
                $this->write_cache_variant_atomically($file, $bundle_content);
            }

            $delayed_font_file = '';
            $delayed_font_url = '';
            $delayed_font_bytes = 0;
            if ('' !== trim($delayed_font_css)) {
                $delayed_font_content = trim($delayed_font_css) . "\n";
                $delayed_font_hash = md5($delayed_font_content);
                $delayed_font_filename = 'bundle-' . $mode . '-' . $signature . '-delayed-fonts.css';
                $delayed_font_file = $dir . $delayed_font_filename;
                if (!file_exists($delayed_font_file) || md5_file($delayed_font_file) !== $delayed_font_hash) {
                    $this->write_cache_variant_atomically($delayed_font_file, $delayed_font_content);
                }
                $delayed_font_bytes = is_readable($delayed_font_file) ? (int) filesize($delayed_font_file) : strlen($delayed_font_content);
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

            return '@import url("' . esc_url_raw($absolute) . '")' . ('' !== $suffix ? ' ' . $suffix : '') . ';';
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

            $entry = array(
                'normalizedUrl' => $this->normalize_url($url),
                'bundleFile' => (string) $prepared['bundleFile'],
                'bundleUrl' => (string) $prepared['bundleUrl'],
                'sourceUrls' => array_values(array_unique(array_map('strval', (array) ($prepared['sourceUrls'] ?? array())))),
                'sourceCount' => count((array) ($prepared['sourceUrls'] ?? array())),
                'bundleCount' => 1,
                'mode' => (string) ($prepared['mode'] ?? 'safe'),
                'bundleSignature' => (string) ($prepared['bundleSignature'] ?? ''),
                'bundleContentHash' => (string) ($prepared['bundleContentHash'] ?? ''),
                'delayedFontFile' => (string) ($prepared['delayedFontFile'] ?? ''),
                'delayedFontUrl' => (string) ($prepared['delayedFontUrl'] ?? ''),
                'delayedFontBytes' => isset($prepared['delayedFontBytes']) ? (int) $prepared['delayedFontBytes'] : 0,
                'delayedFontFaceBlocks' => isset($prepared['delayedFontFaceBlocks']) ? (int) $prepared['delayedFontFaceBlocks'] : 0,
                'delayedFontFamilies' => isset($prepared['delayedFontFamilies']) && is_array($prepared['delayedFontFamilies']) ? $prepared['delayedFontFamilies'] : array(),
                'delayedFontPatterns' => isset($prepared['delayedFontPatterns']) && is_array($prepared['delayedFontPatterns']) ? $prepared['delayedFontPatterns'] : array(),
                'sourceDetails' => isset($prepared['sourceDetails']) && is_array($prepared['sourceDetails']) ? $prepared['sourceDetails'] : array(),
                'sourceBytesTotal' => isset($prepared['sourceBytesTotal']) ? (int) $prepared['sourceBytesTotal'] : 0,
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

            $replacement = '<link rel="stylesheet" id="ucwp-frontpage-css" href="' . esc_url($bundle_url) . '" data-ucwp-frontpage-css="1" />'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
            if (!empty($settings['homepage_css_bundle_inline'])) {
                $bundle_css = ('' !== $bundle_file && is_readable($bundle_file)) ? ucwp_safe_file_get_contents($bundle_file) : '';
                $bundle_css = $this->prepare_inline_css_bundle_for_style_tag($bundle_css);
                if ('' !== $bundle_css) {
                    $replacement = '<style id="ucwp-frontpage-css" data-ucwp-frontpage-css="1">' . $bundle_css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
            $replacement = '<link rel="stylesheet" id="ucwp-page-css-bundle" href="' . esc_url($bundle_url) . '" data-ucwp-page-css-bundle="1" />'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
            if (!empty($settings['homepage_css_bundle_inline'])) {
                $maybe_css = ucwp_safe_file_get_contents($bundle_file);
                $bundle_css = $this->prepare_inline_css_bundle_for_style_tag($maybe_css);
                if ('' !== $bundle_css) {
                    $replacement = '<style id="ucwp-page-css-bundle" data-ucwp-page-css-bundle="1">' . $bundle_css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
            }
            $delayed_font_markup = $this->build_delayed_icon_fonts_stylesheet_markup($entry, 'ucwp-page-delayed-icon-fonts');
            if ('' !== $delayed_font_markup) {
                $replacement .= "\n" . $delayed_font_markup;
            }

            $mode = isset($entry['mode']) && 'aggressive' === strtolower((string) $entry['mode']) ? 'aggressive' : 'safe';

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
                return array('asset' => array(), 'skip' => 'protected', 'url' => $absolute_url, 'reason' => 'CSS Bundle Exclusions matched');
            }

            $slider_fragment = !empty($settings['slider_safe_mode']) ? $this->get_matching_fragment('', $absolute_url, $tag_html, $this->get_slider_hero_protected_fragments()) : '';
            if ('' !== $slider_fragment) {
                return array('asset' => array(), 'skip' => 'protected', 'url' => $absolute_url, 'reason' => 'slider/hero stylesheet fragment: ' . $slider_fragment);
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

            $replacement = '<link rel="stylesheet" id="ucwp-leftover-css-bundle" href="' . esc_url($bundle_url) . '" data-ucwp-leftover-css-bundle="1" />'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
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
                if ('.' === $item || '..' === $item || 'google-fonts' === $item || 'css-bundles' === $item) {
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
            $excluded_query_args = $this->merge_hard_security_excluded_query_args($excluded_query_args);
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
            } elseif ('' !== $query && empty($query_allowlist)) {
                $reason = 'query-allowlist-empty';
            } elseif ('' !== $query && !$this->query_has_cacheable_allowlisted_variant($query, $query_allowlist)) {
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

        private function get_current_request_scheme()
        {
            $is_ssl = ucwp_server_flag_enabled('HTTPS')
                || ('443' === ucwp_server_value('SERVER_PORT'));

            if ($is_ssl) {
                return 'https';
            }

            $forwarded_proto_parts = explode(',', ucwp_server_value('HTTP_X_FORWARDED_PROTO'));
            $forwarded_proto = strtolower(trim((string) reset($forwarded_proto_parts)));
            if ('https' === $forwarded_proto) {
                return 'https';
            }

            $forwarded_scheme = strtolower(trim((string) ucwp_server_value('HTTP_X_FORWARDED_SCHEME')));
            if ('https' === $forwarded_scheme) {
                return 'https';
            }

            $forwarded_ssl = strtolower(trim((string) ucwp_server_value('HTTP_X_FORWARDED_SSL')));
            if (in_array($forwarded_ssl, array('on', '1', 'true', 'https'), true)) {
                return 'https';
            }

            $frontend_https = strtolower(trim((string) ucwp_server_value('HTTP_FRONT_END_HTTPS')));
            if (in_array($frontend_https, array('on', '1', 'true'), true)) {
                return 'https';
            }

            $cloudfront_proto = strtolower(trim((string) ucwp_server_value('HTTP_CLOUDFRONT_FORWARDED_PROTO')));
            if ('https' === $cloudfront_proto) {
                return 'https';
            }

            $cf_visitor = (string) ucwp_server_value('HTTP_CF_VISITOR');
            if (false !== stripos($cf_visitor, '"scheme":"https"')) {
                return 'https';
            }

            return 'http';
        }

        private function get_current_request_url()
        {
            if (empty($_SERVER['HTTP_HOST']) || empty($_SERVER['REQUEST_URI'])) {
                return '';
            }

            $scheme_value = $this->get_current_request_scheme();
            $scheme = $scheme_value . '://';
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
                unset($query_vars['ucwp_revalidate'], $query_vars['ucwp_rt'], $query_vars['ucwp_store_profile'], $query_vars['ucwp_callback_profile'], $query_vars['ucwp_store_profile_verbose'], $query_vars['ucwp_store_profile_verbose_settings'], $query_vars['ucwp_profile_bypass'], $query_vars['ucwp_profile_run']);
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
            $excluded_query_args = $this->merge_hard_security_excluded_query_args($excluded_query_args);
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

                if (empty($query_allowlist)) {
                    $this->last_bypass_reason = 'query-allowlist-empty';
                    return true;
                }

                if (!$this->query_has_cacheable_allowlisted_variant($query, $query_allowlist)) {
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

