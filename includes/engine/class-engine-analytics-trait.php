<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Analytics counters, cache-event summaries, and cache storage statistics for the engine.
 */
trait Ultra_Cache_Engine_Analytics_Trait
{
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

                $username = isset($settings['redisUsername']) ? trim((string) $settings['redisUsername']) : '';
                $password = isset($settings['redisPassword']) ? (string) $settings['redisPassword'] : '';
                if ('' !== $password) {
                    $authenticated = '' !== $username ? @$client->auth(array($username, $password)) : @$client->auth($password);
                    if (!$authenticated) {
                        return null;
                    }
                } elseif ('' !== $username) {
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

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Atomic file-buffer lock requires native fopen/flock semantics and is path-guarded to UltraCache cache storage.
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
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing native flock handle acquired above.
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
}
