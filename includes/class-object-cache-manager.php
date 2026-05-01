<?php
/**
 * Hybrid object cache drop-in manager for UltraCache.
 */

defined('ABSPATH') || exit;

if (!class_exists('Ultra_Cache_Object_Cache_Manager')) {

	final class Ultra_Cache_Object_Cache_Manager {

		private static $plugin_settings_cache = null;
		private static $redis_last_error = '';

		public static function sync_dropin() {
			$enabled = self::is_enabled_in_settings();

			if (!$enabled || !self::supports_dropin()) {
				self::maybe_remove_dropin();

				if (function_exists('wp_using_ext_object_cache')) {
					wp_using_ext_object_cache(false);
				}

				return false;
			}

			$installed = self::setup_dropin();

			if ($installed && function_exists('wp_using_ext_object_cache')) {
				wp_using_ext_object_cache(true);
			}

			return $installed;
		}

		public static function supports_dropin() {
			return true;
		}

		public static function get_unavailable_reason() {
			$settings = self::get_plugin_settings();
			if (!empty($settings['object_cache_enabled']) && 'redis' === self::get_selected_backend() && !self::redis_supported()) {
				return 'Redis backend selected, but the PHP Redis extension is not loaded. UltraCache will use APCu when available, otherwise runtime-only object caching.';
			}

			return '';
		}

		public static function is_enabled_in_settings() {
			$settings = self::get_plugin_settings();
			return !empty($settings['object_cache_enabled']);
		}

		public static function get_selected_backend() {
			$settings = self::get_plugin_settings();
			$backend = isset($settings['object_cache_backend']) ? strtolower(trim((string) $settings['object_cache_backend'])) : 'redis';
			return in_array($backend, array('redis', 'apcu', 'disk'), true) ? $backend : 'redis';
		}

		private static function sanitize_fallback_backend($value) {
			$value = strtolower(trim((string) $value));
			if ('none' === $value || 'runtime' === $value || '' === $value) {
				return 'none';
			}
			return in_array($value, array('apcu', 'disk'), true) ? $value : 'apcu';
		}

		public static function get_selected_fallback_backend() {
			$settings = self::get_plugin_settings();
			return self::sanitize_fallback_backend($settings['object_cache_fallback_backend'] ?? 'apcu');
		}

		public static function get_active_backend() {
			$status = self::get_backend_status();
			$backend = isset($status['active']) ? (string) $status['active'] : '';
			return '' !== $backend ? $backend : self::get_selected_backend();
		}

		public static function get_backend_status() {
			$selected = self::get_selected_backend();
			$active = $selected;
			$selected_fallback = self::get_selected_fallback_backend();
			$status = array(
				'selected' => $selected,
				'active' => $active,
				'configuredFallback' => $selected_fallback,
				'fallback' => ('none' === $selected_fallback ? 'runtime' : $selected_fallback),
				'fallbackActive' => false,
				'fallbackReason' => '',
				'fallbackMessage' => '',
				'apcu' => array(
					'enabled' => false,
					'available' => function_exists('apcu_fetch') && function_exists('apcu_store') && (!function_exists('apcu_enabled') || apcu_enabled()),
				),
				'redis' => array(
					'enabled' => false,
					'available' => self::redis_supported(),
					'host' => '',
					'port' => 0,
					'database' => 0,
					'use_tls' => false,
					'persistent' => false,
					'error' => '',
				),
			);

			global $wp_object_cache;
			if (is_object($wp_object_cache)) {
				if (method_exists($wp_object_cache, 'get_backend_status')) {
					$runtime_status = $wp_object_cache->get_backend_status();
					if (is_array($runtime_status)) {
						$status = array_replace_recursive($status, $runtime_status);
					}
				} elseif (method_exists($wp_object_cache, 'get_backend')) {
					$runtime_backend = (string) $wp_object_cache->get_backend();
					if ('' !== $runtime_backend) {
						$status['active'] = $runtime_backend;
					}
				}
			}

			$status['selected'] = in_array((string) ($status['selected'] ?? ''), array('redis', 'apcu', 'disk'), true) ? (string) $status['selected'] : $selected;
			$status['active'] = in_array((string) ($status['active'] ?? ''), array('redis', 'apcu', 'disk', 'runtime'), true) ? (string) $status['active'] : $selected;
			$status['fallbackActive'] = ('redis' === $status['selected'] && 'redis' !== $status['active']);
			$configured_fallback = self::sanitize_fallback_backend($status['configuredFallback'] ?? $selected_fallback);
			$status['configuredFallback'] = $configured_fallback;
			$status['fallback'] = $status['fallbackActive'] ? (string) $status['active'] : ('none' === $configured_fallback ? 'runtime' : $configured_fallback);
			if ($status['fallbackActive']) {
				$redis_error = isset($status['redis']['error']) ? trim((string) $status['redis']['error']) : '';
				$fallback_label = 'apcu' === (string) $status['fallback'] ? 'APCu' : ('runtime' === (string) $status['fallback'] ? 'runtime-only' : strtoupper((string) $status['fallback']));
				$status['fallbackReason'] = '' !== $redis_error ? $redis_error : 'Redis was selected but did not become active during drop-in bootstrap.';
				$status['fallbackMessage'] = 'Redis selected, ' . $fallback_label . ' fallback active.' . ('' !== $status['fallbackReason'] ? ' Redis: ' . $status['fallbackReason'] : '');
			}

			return $status;
		}

		public static function is_dropin_active() {
			$dropin = trailingslashit(WP_CONTENT_DIR) . 'object-cache.php';

			return (bool) (
				function_exists('wp_using_ext_object_cache')
				&& wp_using_ext_object_cache()
				&& self::is_our_dropin($dropin)
			);
		}

		public static function ensure_cache_directory() {
			if (!file_exists(UCWP_OBJECT_CACHE_DIR)) {
				wp_mkdir_p(UCWP_OBJECT_CACHE_DIR);
			}

			$index = trailingslashit(UCWP_OBJECT_CACHE_DIR) . 'index.php';
			if (!file_exists($index)) {
				ucwp_safe_file_put_contents($index, "<?php\n// Silence is golden.\n");
			}
		}


		private static function get_dropin_path() {
			return trailingslashit(WP_CONTENT_DIR) . 'object-cache.php';
		}

		public static function get_dropin_status_fast() {
			$dropin = self::get_dropin_path();
			$status = array(
				'exists' => file_exists($dropin),
				'readable' => is_readable($dropin),
				'has_marker' => false,
				'build' => '',
				'expected_build' => defined('UCWP_VERSION') ? (string) UCWP_VERSION : '',
				'healthy' => false,
				'reason' => '',
			);

			if (!$status['exists']) {
				$status['reason'] = 'missing';
				return $status;
			}

			if (!$status['readable']) {
				$status['reason'] = 'not_readable';
				return $status;
			}

			$contents = file_get_contents($dropin);
			if (!is_string($contents) || '' === $contents) {
				$status['reason'] = 'empty_or_read_failed';
				return $status;
			}

			$status['has_marker'] = false !== strpos($contents, 'UltraCache generated object-cache drop-in');
			if (preg_match('/Drop-in Build:\s*([^\r\n]+)/', $contents, $matches)) {
				$status['build'] = trim((string) $matches[1]);
			}

			if (!$status['has_marker']) {
				$status['reason'] = 'marker_missing';
				return $status;
			}

			if ('' !== $status['expected_build'] && '' !== $status['build'] && $status['build'] !== $status['expected_build']) {
				$status['reason'] = 'build_mismatch';
				return $status;
			}

			$status['healthy'] = true;
			$status['reason'] = 'current';

			return $status;
		}

		private static function get_runtime_secret_site_token() {
			$site_root = defined('ABSPATH') ? wp_normalize_path(untrailingslashit(ABSPATH)) : '';
			$token = '' !== $site_root ? wp_basename($site_root) : 'site';
			$token = is_string($token) ? strtolower($token) : '';
			$token = preg_replace('/[^a-z0-9._-]+/', '-', $token);
			$token = trim((string) $token, '.-_');
			return '' !== $token ? $token : 'site';
		}

		private static function get_runtime_secret_path() {
			$base = defined('ABSPATH') ? dirname(untrailingslashit(ABSPATH)) : '';
			if (!is_string($base) || '' === trim($base) || '.' === $base || '/' === $base) {
				$base = defined('WP_CONTENT_DIR') ? dirname(untrailingslashit(WP_CONTENT_DIR)) : '';
			}

			return rtrim((string) $base, '/\\') . '/.' . self::get_runtime_secret_site_token() . '-ultracache-runtime-secrets.php';
		}

		private static function get_redis_secret_config_path() {
			return self::get_runtime_secret_path();
		}

		private static function get_legacy_redis_secret_config_path() {
			return trailingslashit(UCWP_OBJECT_CACHE_DIR) . '.redis-auth.php';
		}

		private static function normalize_runtime_secret_array(array $loaded) {
			$varnish_admin_secret = '';
			if (isset($loaded['varnish_admin_secret'])) {
				$varnish_admin_secret = (string) $loaded['varnish_admin_secret'];
			} elseif (isset($loaded['varnish_cli_key'])) {
				$varnish_admin_secret = (string) $loaded['varnish_cli_key'];
			}

			return array(
				'revalidate_secret' => isset($loaded['revalidate_secret']) ? (string) $loaded['revalidate_secret'] : '',
				'redis_password' => isset($loaded['redis_password']) ? (string) $loaded['redis_password'] : '',
				'varnish_admin_secret' => $varnish_admin_secret,
			);
		}

		private static function load_runtime_secret_file($path = null) {
			$candidates = array();
			if (is_string($path) && '' !== trim($path)) {
				$candidates[] = $path;
			} else {
				$candidates[] = self::get_runtime_secret_path();
			}

			foreach ($candidates as $candidate) {
				if (!is_string($candidate) || '' === trim($candidate) || !file_exists($candidate) || !is_readable($candidate)) {
					continue;
				}
				$loaded = require $candidate;
				if (is_array($loaded)) {
					return self::normalize_runtime_secret_array($loaded);
				}
			}

			return array();
		}

		private static function render_runtime_secret_php(array $runtime) {
			$runtime = self::normalize_runtime_secret_array($runtime);
			$secret = isset($runtime['revalidate_secret']) ? (string) $runtime['revalidate_secret'] : '';
			$redis_password = isset($runtime['redis_password']) ? (string) $runtime['redis_password'] : '';
			$varnish_admin_secret = isset($runtime['varnish_admin_secret']) ? (string) $runtime['varnish_admin_secret'] : '';
			return "<?php\n/** UltraCache managed runtime secrets. */\nif (!defined('ABSPATH')) {\n    exit;\n}\nreturn array(\n    'revalidate_secret' => " . ucwp_php_string_literal($secret) . ",\n    'redis_password' => " . ucwp_php_string_literal($redis_password) . ",\n    'varnish_admin_secret' => " . ucwp_php_string_literal($varnish_admin_secret) . ",\n);\n";
		}

		private static function write_runtime_secret_array(array $runtime) {
			$path = self::get_runtime_secret_path();
			$payload = self::render_runtime_secret_php($runtime);
			$result = ucwp_safe_file_put_contents($path, $payload, LOCK_EX, 'object_cache_runtime_secret_write');
			if (false === $result) {
				return false;
			}
			self::apply_restrictive_file_permissions($path, 0600);
			return true;
		}

		private static function remove_legacy_redis_secret_config() {
			$legacy = self::get_legacy_redis_secret_config_path();
			if (file_exists($legacy)) {
				ucwp_safe_unlink($legacy, 'object_cache_legacy_secret_config_remove');
			}
		}

		private static function apply_restrictive_file_permissions($path, $mode = 0600) {
			$path = (string) $path;
			$mode = (int) $mode;
			if ('' === $path || !file_exists($path)) {
				return false;
			}

			$filesystem = function_exists('ucwp_get_wp_filesystem') ? ucwp_get_wp_filesystem() : false;
			if ($filesystem && method_exists($filesystem, 'chmod')) {
				$filesystem->chmod($path, $mode);
				clearstatcache(true, $path);
				$perms = (is_file($path) && is_readable($path)) ? fileperms($path) : false;
				if (false !== $perms && (($perms & 0777) === $mode)) {
					return true;
				}
			}

			@chmod($path, $mode);
			clearstatcache(true, $path);
			$perms = (is_file($path) && is_readable($path)) ? fileperms($path) : false;
			return false !== $perms && (($perms & 0777) === $mode);
		}

		private static function maybe_sync_redis_secret_config(array $settings) {
			$password = isset($settings['redis_password']) ? (string) $settings['redis_password'] : '';
			$runtime = self::load_runtime_secret_file();
			$runtime['redis_password'] = trim((string) $password);

			if (!self::write_runtime_secret_array($runtime)) {
				return false;
			}

			self::remove_legacy_redis_secret_config();
			return true;
		}

		public static function setup_dropin() {
			self::ensure_cache_directory();

			$dropin = self::get_dropin_path();

			if (file_exists($dropin) && !self::is_our_dropin($dropin)) {
				return false;
			}

			$settings = self::get_plugin_settings();
			if (!self::maybe_sync_redis_secret_config($settings)) {
				return false;
			}

			$placeholders = array(
				'__UCWP_DROPIN_BUILD__' => UCWP_VERSION,
				'__UCWP_OBJECT_CACHE_DIR__' => ucwp_php_string_literal(UCWP_OBJECT_CACHE_DIR),
				'__UCWP_SELECTED_BACKEND__' => ucwp_php_string_literal(self::get_selected_backend()),
				'__UCWP_FALLBACK_BACKEND__' => ucwp_php_string_literal(self::get_selected_fallback_backend()),
				'__UCWP_CACHE_STATS_ENABLED__' => !empty($settings['cache_stats_enabled']) ? 'true' : 'false',
				'__UCWP_REDIS_SECRET_CONFIG__' => ucwp_php_string_literal(self::get_redis_secret_config_path()),
				'__UCWP_REDIS_HOST__'       => ucwp_php_string_literal((string) ($settings['redis_host'] ?? '127.0.0.1')),
				'__UCWP_REDIS_PORT__'       => (string) max(1, absint($settings['redis_port'] ?? 6379)),
				'__UCWP_REDIS_PASSWORD__'   => ucwp_php_string_literal(''),
				'__UCWP_REDIS_DATABASE__'   => (string) max(0, absint($settings['redis_database'] ?? 0)),
				'__UCWP_REDIS_PREFIX__'     => ucwp_php_string_literal(self::get_redis_prefix($settings)),
				'__UCWP_REDIS_USE_TLS__'    => !empty($settings['redis_use_tls']) ? 'true' : 'false',
				'__UCWP_REDIS_PERSISTENT__' => !empty($settings['redis_persistent']) ? 'true' : 'false',
				'__UCWP_REDIS_CONNECT_TIMEOUT__' => ucwp_php_float_literal(max(0.05, ((int) ($settings['redis_connect_timeout_ms'] ?? 200)) / 1000)),
				'__UCWP_REDIS_READ_TIMEOUT__'    => ucwp_php_float_literal(max(0.05, ((int) ($settings['redis_read_timeout_ms'] ?? 200)) / 1000)),
			);

			$template = trailingslashit(UCWP_PATH) . 'templates/object-cache.php.tpl';
			if (!file_exists($template) || !is_readable($template)) {
				return false;
			}

			$code = (string) ucwp_safe_file_get_contents($template, 'object_cache_template');
			if ('' === $code) {
				return false;
			}

			$code = str_replace(array_keys($placeholders), array_values($placeholders), $code);

			if (file_exists($dropin)) {
				$existing = ucwp_safe_file_get_contents($dropin);
				if (is_string($existing) && $existing === $code) {
					return true;
				}
			}

			$written = ucwp_safe_file_put_contents($dropin, $code, LOCK_EX, 'object_cache_dropin_write');
			return false !== $written;
		}

		public static function maybe_remove_dropin() {
			$dropin = self::get_dropin_path();
			if (self::is_our_dropin($dropin)) {
				ucwp_safe_unlink($dropin, 'object_cache_dropin_remove');
			}
            $legacy_config = self::get_legacy_redis_secret_config_path();
            if (file_exists($legacy_config)) {
                ucwp_safe_unlink($legacy_config, 'object_cache_legacy_secret_config_remove');
            }
		}

		public static function flush_cache($force_hard = false, $reset_plugin_state = true) {
			$report = self::flush_cache_with_report($force_hard, $reset_plugin_state);
			return !empty($report['success']);
		}

		public static function flush_cache_with_report($force_hard = false, $reset_plugin_state = true) {
			$flushed = false;
			$force_hard = (bool) $force_hard;
			$reset_plugin_state = (bool) $reset_plugin_state;
			$pre_files = self::capture_cache_file_state(UCWP_OBJECT_CACHE_DIR);
			$report = array(
				'success' => false,
				'forceHard' => $force_hard,
				'dropinFlushCalled' => false,
				'dropinFlushResult' => false,
				'preFlushEntries' => count($pre_files),
				'postFlushEntries' => 0,
				'staleEntries' => 0,
				'recreatedEntries' => 0,
				'staleFiles' => array(),
				'recreatedFiles' => array(),
				'semanticStatus' => 'unknown',
				'startedAt' => microtime(true),
				'completedAt' => 0,
				'message' => '',
			);
			global $wp_object_cache;
			if (is_object($wp_object_cache) && method_exists($wp_object_cache, 'flush')) {
				$report['dropinFlushCalled'] = true;
				$flushed = (bool) $wp_object_cache->flush();
				$report['dropinFlushResult'] = $flushed;
			}

			if ($force_hard || !$flushed) {
				self::recursive_delete(UCWP_OBJECT_CACHE_DIR);
			}

			self::flush_redis_namespace();
			self::ensure_cache_directory();
			self::maybe_sync_redis_secret_config(self::get_plugin_settings());
			if ($reset_plugin_state) {
				self::reset_plugin_state_cache();
			}
			self::prune_cache_directory();
			self::flush_stale_temp_files(UCWP_OBJECT_CACHE_DIR);
			clearstatcache(true);
			$report['completedAt'] = microtime(true);

			$post_files = self::capture_cache_file_state(UCWP_OBJECT_CACHE_DIR);
			$classified = self::classify_flush_cache_files($pre_files, $post_files, $report['completedAt']);
			$report['postFlushEntries'] = count($post_files);
			$report['staleEntries'] = count($classified['stale']);
			$report['recreatedEntries'] = count($classified['recreated']);
			$report['staleFiles'] = self::limit_cache_file_samples($classified['stale']);
			$report['recreatedFiles'] = self::limit_cache_file_samples($classified['recreated']);
			$report['semanticStatus'] = self::determine_flush_semantic_status($report['staleEntries'], $report['recreatedEntries']);
			$report['success'] = (0 === $report['staleEntries']);
			$report['message'] = self::build_flush_report_message($report);
			self::store_last_flush_report($report);
			return $report;
		}

		public static function get_last_flush_report() {
			$report = get_option('ucwp_object_cache_last_flush_report', array());
			return is_array($report) ? $report : array();
		}

		public static function test_redis_connection($override = array()) {
			$settings = self::get_plugin_settings();
			if (is_array($override) && !empty($override)) {
				$settings = array_merge($settings, array_filter(array(
					'redis_host' => isset($override['redisHost']) ? (string) $override['redisHost'] : (isset($override['redis_host']) ? (string) $override['redis_host'] : null),
					'redis_port' => isset($override['redisPort']) ? absint($override['redisPort']) : (isset($override['redis_port']) ? absint($override['redis_port']) : null),
					'redis_password' => array_key_exists('redisPassword', $override) ? (string) $override['redisPassword'] : (array_key_exists('redis_password', $override) ? (string) $override['redis_password'] : null),
					'redis_database' => isset($override['redisDatabase']) ? absint($override['redisDatabase']) : (isset($override['redis_database']) ? absint($override['redis_database']) : null),
					'redis_prefix' => isset($override['redisPrefix']) ? (string) $override['redisPrefix'] : (isset($override['redis_prefix']) ? (string) $override['redis_prefix'] : null),
					'redis_use_tls' => isset($override['redisUseTls']) ? (bool) $override['redisUseTls'] : (isset($override['redis_use_tls']) ? (bool) $override['redis_use_tls'] : null),
					'redis_persistent' => isset($override['redisPersistent']) ? (bool) $override['redisPersistent'] : (isset($override['redis_persistent']) ? (bool) $override['redis_persistent'] : null),
					'redis_connect_timeout_ms' => isset($override['redisConnectTimeoutMs']) ? absint($override['redisConnectTimeoutMs']) : (isset($override['redis_connect_timeout_ms']) ? absint($override['redis_connect_timeout_ms']) : null),
					'redis_read_timeout_ms' => isset($override['redisReadTimeoutMs']) ? absint($override['redisReadTimeoutMs']) : (isset($override['redis_read_timeout_ms']) ? absint($override['redis_read_timeout_ms']) : null),
				), static function($value) { return null !== $value; }));
			}

			$result = array(
				'success' => false,
				'connected' => false,
				'available' => self::redis_supported(),
				'host' => !empty($settings['redis_host']) ? (string) $settings['redis_host'] : '127.0.0.1',
				'port' => max(1, absint($settings['redis_port'] ?? 6379)),
				'database' => max(0, absint($settings['redis_database'] ?? 0)),
				'prefix' => self::get_redis_prefix($settings),
				'useTls' => !empty($settings['redis_use_tls']),
				'persistent' => !empty($settings['redis_persistent']),
				'connectTimeoutMs' => max(50, absint($settings['redis_connect_timeout_ms'] ?? 200)),
				'readTimeoutMs' => max(50, absint($settings['redis_read_timeout_ms'] ?? 200)),
				'message' => '',
			);

			if (!$result['available']) {
				$result['message'] = 'PHP Redis extension is not loaded on this server.';
				return $result;
			}

			$redis = self::connect_redis($settings);
			if (!$redis instanceof Redis) {
				$result['message'] = 'Could not connect to Redis with the provided settings.';
				return $result;
			}

			try {
				$pong = $redis->ping();
				$result['connected'] = false !== $pong;
				$result['success'] = $result['connected'];
				$result['message'] = $result['connected'] ? 'Connected to Redis successfully.' : 'Redis connected but did not respond to ping.';
			} catch (Throwable $e) {
				$result['message'] = $e->getMessage();
			}

			return $result;
		}


		public static function test_redis_read_write($override = array()) {
			$settings = self::get_plugin_settings();
			if (is_array($override) && !empty($override)) {
				$settings = array_merge($settings, array_filter(array(
					'redis_host' => isset($override['redisHost']) ? (string) $override['redisHost'] : (isset($override['redis_host']) ? (string) $override['redis_host'] : null),
					'redis_port' => isset($override['redisPort']) ? absint($override['redisPort']) : (isset($override['redis_port']) ? absint($override['redis_port']) : null),
					'redis_password' => array_key_exists('redisPassword', $override) ? (string) $override['redisPassword'] : (array_key_exists('redis_password', $override) ? (string) $override['redis_password'] : null),
					'redis_database' => isset($override['redisDatabase']) ? absint($override['redisDatabase']) : (isset($override['redis_database']) ? absint($override['redis_database']) : null),
					'redis_prefix' => isset($override['redisPrefix']) ? (string) $override['redisPrefix'] : (isset($override['redis_prefix']) ? (string) $override['redis_prefix'] : null),
					'redis_use_tls' => isset($override['redisUseTls']) ? (bool) $override['redisUseTls'] : (isset($override['redis_use_tls']) ? (bool) $override['redis_use_tls'] : null),
					'redis_persistent' => isset($override['redisPersistent']) ? (bool) $override['redisPersistent'] : (isset($override['redis_persistent']) ? (bool) $override['redis_persistent'] : null),
					'redis_connect_timeout_ms' => isset($override['redisConnectTimeoutMs']) ? absint($override['redisConnectTimeoutMs']) : (isset($override['redis_connect_timeout_ms']) ? absint($override['redis_connect_timeout_ms']) : null),
					'redis_read_timeout_ms' => isset($override['redisReadTimeoutMs']) ? absint($override['redisReadTimeoutMs']) : (isset($override['redis_read_timeout_ms']) ? absint($override['redis_read_timeout_ms']) : null),
				), static function($value) { return null !== $value; }));
			}

			$result = self::test_redis_connection($override);
			$result['readWrite'] = false;
			$result['probeKey'] = '';

			if (empty($result['success'])) {
				return $result;
			}

			$redis = self::connect_redis($settings);
			if (!$redis instanceof Redis) {
				$result['success'] = false;
				$result['connected'] = false;
				$result['message'] = '' !== self::$redis_last_error ? self::$redis_last_error : 'Could not reconnect to Redis for read/write probe.';
				return $result;
			}

			$prefix = self::get_redis_prefix($settings);
			$key = $prefix . 'analytics-probe:' . md5(uniqid('ucwp', true));
			$value = 'ucwp:' . md5($key . '|' . microtime(true));

			try {
				$written = self::with_redis_error_handler_static(function () use ($redis, $key, $value) {
					return $redis->setex($key, 30, $value);
				}, false);
				$fetched = self::with_redis_error_handler_static(function () use ($redis, $key) {
					return $redis->get($key);
				}, false);
				self::with_redis_error_handler_static(function () use ($redis, $key) {
					$redis->del($key);
					return true;
				}, true);

				$result['readWrite'] = (bool) $written && ((string) $fetched === (string) $value);
				$result['probeKey'] = $key;
				$result['message'] = $result['readWrite'] ? 'Redis read/write probe passed.' : 'Redis ping passed, but the read/write probe failed.';
				$result['success'] = (bool) $result['readWrite'];
			} catch (Throwable $e) {
				$result['success'] = false;
				$result['readWrite'] = false;
				$result['message'] = $e->getMessage();
			}

			return $result;
		}


		public static function test_runtime_object_cache_payloads() {
			$result = array(
				'success' => false,
				'backendStatus' => self::get_backend_status(),
				'probes' => array(),
				'message' => '',
			);

			if (!function_exists('wp_cache_set') || !function_exists('wp_cache_get')) {
				$result['message'] = 'WordPress object cache functions are unavailable.';
				return $result;
			}

			$object = new stdClass();
			$object->alpha = 1;
			$object->nested = new stdClass();
			$object->nested->beta = 'two';

			$payloads = array(
				'string' => 'ucwp:' . md5((string) microtime(true)),
				'array' => array('alpha' => 1, 'nested' => array('beta' => 'two')),
				'object' => $object,
			);

			$group = 'ucwp_diagnostics';
			$all_ok = true;

			foreach ($payloads as $type => $value) {
				$key = 'payload_probe_' . $type . '_' . md5(uniqid('ucwp', true));
				wp_cache_delete($key, $group);
				$set = wp_cache_set($key, $value, $group, 30);

				global $wp_object_cache;
				if (is_object($wp_object_cache) && method_exists($wp_object_cache, 'flush_runtime')) {
					$wp_object_cache->flush_runtime();
				}

				$found = null;
				$read = wp_cache_get($key, $group, false, $found);
				$matches = false;
				try {
					$matches = (serialize($read) === serialize($value));
				} catch (Throwable $e) {
					$matches = false;
				}
				wp_cache_delete($key, $group);

				$ok = (bool) $set && (bool) $found && (bool) $matches;
				$all_ok = $all_ok && $ok;
				$result['probes'][$type] = array(
					'set' => (bool) $set,
					'found' => (bool) $found,
					'matches' => (bool) $matches,
					'success' => (bool) $ok,
					'readType' => is_object($read) ? get_class($read) : gettype($read),
				);
			}

			$result['success'] = (bool) $all_ok;
			$result['backendStatus'] = self::get_backend_status();
			$result['message'] = $all_ok ? 'Object cache payload probe passed for string, array, and object values.' : 'Object cache payload probe failed for one or more value types.';
			return $result;
		}

		private static function get_metrics_file() {
			return trailingslashit(UCWP_OBJECT_CACHE_DIR) . 'object-cache-metrics.json';
		}

		private static function read_metrics_snapshot() {
			$data = array('hits' => 0, 'misses' => 0);
			$file = self::get_metrics_file();
			if (!file_exists($file) || !is_readable($file)) {
				return $data;
			}
			$raw = ucwp_safe_file_get_contents($file);
			if (false === $raw || '' === $raw) {
				return $data;
			}
			$decoded = json_decode($raw, true);
			if (!is_array($decoded)) {
				return $data;
			}
			return array_replace($data, $decoded);
		}

		public static function reset_metrics() {
			$file = self::get_metrics_file();
			if (file_exists($file)) {
				ucwp_safe_unlink($file);
			}
			return true;
		}

		private static function capture_cache_file_state($dir) {
			$state = array();
			$files = self::collect_cache_files($dir, 'cache');
			foreach ($files as $file) {
				clearstatcache(true, $file);
				$state[$file] = array(
					'mtime' => max(0, (int) ucwp_safe_filemtime($file, 'object_cache_flush_state_filemtime')),
					'size' => max(0, (int) ucwp_safe_filesize($file, 'object_cache_flush_state_filesize')),
				);
			}
			return $state;
		}

		private static function classify_flush_cache_files($pre_files, $post_files, $completed_at) {
			$classified = array(
				'stale' => array(),
				'recreated' => array(),
			);
			$completed_second = (int) floor((float) $completed_at);
			foreach ($post_files as $file => $meta) {
				$post_mtime = isset($meta['mtime']) ? (int) $meta['mtime'] : 0;
				$post_size = isset($meta['size']) ? (int) $meta['size'] : 0;
				if (!isset($pre_files[$file])) {
					$classified['recreated'][] = $file;
					continue;
				}
				$pre_meta = is_array($pre_files[$file]) ? $pre_files[$file] : array();
				$pre_mtime = isset($pre_meta['mtime']) ? (int) $pre_meta['mtime'] : 0;
				$pre_size = isset($pre_meta['size']) ? (int) $pre_meta['size'] : 0;
				if ($post_mtime > $pre_mtime || $post_size !== $pre_size || $post_mtime > $completed_second) {
					$classified['recreated'][] = $file;
					continue;
				}
				$classified['stale'][] = $file;
			}
			return $classified;
		}

		private static function determine_flush_semantic_status($stale_count, $recreated_count) {
			$stale_count = max(0, (int) $stale_count);
			$recreated_count = max(0, (int) $recreated_count);
			if ($stale_count > 0) {
				return 'stale_entries_remained';
			}
			if ($recreated_count > 0) {
				return 'recreated_after_flush';
			}
			return 'fully_flushed';
		}

		private static function build_flush_report_message($report) {
			$stale_count = max(0, (int) ($report['staleEntries'] ?? 0));
			$recreated_count = max(0, (int) ($report['recreatedEntries'] ?? 0));
			if ($stale_count > 0) {
				if ($recreated_count > 0) {
					return sprintf('Object cache flush completed, but %1$d stale entr%2$s remained. %3$d entr%4$s were recreated after flush by live runtime activity.', $stale_count, 1 === $stale_count ? 'y' : 'ies', $recreated_count, 1 === $recreated_count ? 'y' : 'ies');
				}
				return sprintf('Object cache flush completed, but %d stale entr%s remained.', $stale_count, 1 === $stale_count ? 'y' : 'ies');
			}
			if ($recreated_count > 0) {
				return sprintf('Object cache flushed. No stale entries remained. %d entr%s were recreated after flush by live runtime activity.', $recreated_count, 1 === $recreated_count ? 'y' : 'ies');
			}
			return 'Object cache flushed. No cache entries remained after flush.';
		}

		private static function limit_cache_file_samples($files, $limit = 10) {
			$normalized = array();
			$limit = max(1, (int) $limit);
			foreach (array_slice((array) $files, 0, $limit) as $file) {
				$normalized[] = self::normalize_cache_file_path((string) $file);
			}
			return $normalized;
		}

		private static function normalize_cache_file_path($file) {
			$file = (string) $file;
			$root = trailingslashit(UCWP_OBJECT_CACHE_DIR);
			if ('' !== $root && 0 === strpos($file, $root)) {
				return ltrim(substr($file, strlen($root)), '/\\');
			}
			return $file;
		}

		private static function store_last_flush_report($report) {
			if (!is_array($report)) {
				return;
			}
			update_option('ucwp_object_cache_last_flush_report', $report, false);
		}

		public static function get_stats($full_count = false) {
			$backend_status = self::get_backend_status();
			$selected_backend = isset($backend_status['selected']) ? (string) $backend_status['selected'] : self::get_selected_backend();
			$active_backend = isset($backend_status['active']) ? (string) $backend_status['active'] : $selected_backend;
			$fallback_active = !empty($backend_status['fallbackActive']);
			$fallback_backend = isset($backend_status['fallback']) ? strtolower(trim((string) $backend_status['fallback'])) : '';
			if (!in_array($fallback_backend, array('redis', 'apcu', 'disk', 'runtime'), true)) {
				$fallback_backend = $fallback_active ? $active_backend : (!empty($backend_status['apcu']['available']) ? 'apcu' : 'runtime');
			}
			$fallback_message = isset($backend_status['fallbackMessage']) ? (string) $backend_status['fallbackMessage'] : '';

			$disk_entries = 0;
			$disk_bytes = 0;
			$redis_entries = 0;
			$redis_bytes = 0;
			$apcu_entries = 0;
			$apcu_bytes = 0;
			$partial = false;
			$partial_reason = '';
			$stats_limit = 0;

			$files = self::collect_cache_files(UCWP_OBJECT_CACHE_DIR, 'cache');
			$disk_entries = count($files);
			foreach ($files as $file) {
				$size = ucwp_safe_filesize($file, 'object_cache_manager_stats');
				if (false !== $size) {
					$disk_bytes += (int) $size;
				}
			}

			if (('redis' === $selected_backend || 'redis' === $active_backend) && self::redis_supported()) {
				$redis = self::connect_redis();
				if ($redis instanceof Redis) {
					$redis_stats = self::collect_redis_namespace_stats($redis, self::get_redis_prefix(), (bool) $full_count);
					$redis_entries = (int) ($redis_stats['entries'] ?? 0);
					$redis_bytes = (int) ($redis_stats['bytes'] ?? 0);
					if ('redis' === $active_backend) {
						$partial = !empty($redis_stats['partial']);
						$partial_reason = (string) ($redis_stats['partialReason'] ?? '');
						$stats_limit = (int) ($redis_stats['limit'] ?? 0);
					}
				} elseif (isset($backend_status['redis']) && is_array($backend_status['redis']) && empty($backend_status['redis']['error'])) {
					$backend_status['redis']['error'] = '' !== self::$redis_last_error ? self::$redis_last_error : 'Redis stats scan could not connect.';
				}
			}

			$apcu_stats = self::collect_apcu_namespace_stats(self::get_apcu_prefix(), (bool) $full_count);
			$apcu_entries = (int) ($apcu_stats['entries'] ?? 0);
			$apcu_bytes = (int) ($apcu_stats['bytes'] ?? 0);
			if ('apcu' === $active_backend) {
				$partial = !empty($apcu_stats['partial']);
				$partial_reason = (string) ($apcu_stats['partialReason'] ?? '');
				$stats_limit = (int) ($apcu_stats['limit'] ?? 0);
			}

			$entry_count = 0;
			$bytes = 0;
			if ('redis' === $active_backend) {
				$entry_count = $redis_entries;
				$bytes = $redis_bytes;
			} elseif ('apcu' === $active_backend) {
				$entry_count = $apcu_entries;
				$bytes = $apcu_bytes;
			} elseif ('disk' === $active_backend) {
				$entry_count = $disk_entries;
				$bytes = $disk_bytes;
			}

			$metrics = self::read_metrics_snapshot();
			$hits = (int) ($metrics['hits'] ?? 0);
			$misses = (int) ($metrics['misses'] ?? 0);
			$ratio = ($hits + $misses) > 0 ? round(($hits / ($hits + $misses)) * 100, 1) : 0.0;

			return array(
				'objectCacheBackend'      => $active_backend,
				'objectCacheSelectedBackend' => $selected_backend,
				'objectCacheActiveBackend' => $active_backend,
				'objectCacheFallbackBackend' => $fallback_backend,
				'objectCacheFallbackActive' => (bool) $fallback_active,
				'objectCacheFallbackReason' => (string) ($backend_status['fallbackReason'] ?? ''),
				'objectCacheFallbackMessage' => $fallback_message,
				'objectCacheBackendStatus' => $backend_status,
				'objectCacheStatsSource' => $active_backend,
				'objectCacheStatsBackendLabel' => strtoupper((string) $active_backend),
				'objectCacheEntries'      => $entry_count,
				'objectCacheSizeBytes'    => $bytes,
				'objectCacheSizeHuman'    => function_exists('size_format') ? size_format($bytes, 2) : (string) $bytes,
				'objectCacheRedisEntries' => $redis_entries,
				'objectCacheRedisSizeBytes' => $redis_bytes,
				'objectCacheRedisSizeHuman' => function_exists('size_format') ? size_format($redis_bytes, 2) : (string) $redis_bytes,
				'objectCacheApcuEntries' => $apcu_entries,
				'objectCacheApcuSizeBytes' => $apcu_bytes,
				'objectCacheApcuSizeHuman' => function_exists('size_format') ? size_format($apcu_bytes, 2) : (string) $apcu_bytes,
				'objectCacheDiskEntries' => $disk_entries,
				'objectCacheDiskSizeBytes' => $disk_bytes,
				'objectCacheDiskSizeHuman' => function_exists('size_format') ? size_format($disk_bytes, 2) : (string) $disk_bytes,
				'objectCacheHits'         => $hits,
				'objectCacheMisses'       => $misses,
				'objectCacheHitRatio'     => $ratio,
				'objectCacheStatsPartial' => (bool) $partial,
				'objectCacheStatsLimit'   => $stats_limit,
				'objectCacheStatsMode'    => $full_count ? 'full' : 'sampled',
				'objectCacheStatsPartialReason' => $partial_reason,
			);
		}

		private static function collect_apcu_namespace_stats($prefix, $full_count = false) {
			$default_max_keys = (int) apply_filters('ucwp_apcu_object_cache_stats_max_keys', 5000);
			$default_max_keys = max(250, min(50000, $default_max_keys));
			$max_keys = $full_count ? 0 : $default_max_keys;
			$deadline_seconds = $full_count
				? (float) apply_filters('ucwp_apcu_object_cache_stats_full_scan_timeout', 15)
				: 1.0;
			$deadline_seconds = $full_count ? max(3, min(60, $deadline_seconds)) : max(0.25, min(3, $deadline_seconds));

			$stats = array(
				'entries' => 0,
				'bytes' => 0,
				'partial' => false,
				'partialReason' => '',
				'limit' => $max_keys,
			);

			if (!function_exists('apcu_cache_info') || !is_string($prefix) || '' === $prefix) {
				return $stats;
			}
			if (function_exists('apcu_enabled') && !apcu_enabled()) {
				return $stats;
			}

			$deadline = microtime(true) + $deadline_seconds;
			try {
				$info = @apcu_cache_info(false);
				if (!is_array($info) || empty($info['cache_list']) || !is_array($info['cache_list'])) {
					return $stats;
				}
				foreach ($info['cache_list'] as $entry) {
					if (!is_array($entry)) {
						continue;
					}
					$key = '';
					foreach (array('info', 'key') as $key_field) {
						if (isset($entry[$key_field]) && is_scalar($entry[$key_field])) {
							$key = (string) $entry[$key_field];
							break;
						}
					}
					if ('' === $key || 0 !== strpos($key, $prefix)) {
						continue;
					}
					$stats['entries']++;
					if (isset($entry['mem_size']) && (is_int($entry['mem_size']) || ctype_digit((string) $entry['mem_size']))) {
						$stats['bytes'] += (int) $entry['mem_size'];
					}
					if (!$full_count && $max_keys > 0 && $stats['entries'] >= $max_keys) {
						$stats['partial'] = true;
						$stats['partialReason'] = 'sampled, APCu scan capped at ' . number_format_i18n($max_keys) . ' keys';
						break;
					}
					if (microtime(true) >= $deadline) {
						$stats['partial'] = true;
						$stats['partialReason'] = $full_count ? 'full APCu count timed out before scan completed' : 'sampled, APCu scan timed out';
						break;
					}
				}
			} catch (Throwable $e) {
				$stats['partial'] = true;
				$stats['partialReason'] = 'APCu scan failed before completion';
			}

			return $stats;
		}

		private static function collect_redis_namespace_stats($redis, $prefix, $full_count = false) {
			$default_max_keys = (int) apply_filters('ucwp_redis_object_cache_stats_max_keys', 5000);
			$default_max_keys = max(250, min(50000, $default_max_keys));
			$max_keys = $full_count ? 0 : $default_max_keys;
			$deadline_seconds = $full_count
				? (float) apply_filters('ucwp_redis_object_cache_stats_full_scan_timeout', 30)
				: 1.5;
			$deadline_seconds = $full_count ? max(5, min(120, $deadline_seconds)) : max(0.5, min(5, $deadline_seconds));

			$stats = array(
				'entries' => 0,
				'bytes'   => 0,
				'partial' => false,
				'partialReason' => '',
				'limit' => $max_keys,
			);

			if (!$redis instanceof Redis || !is_string($prefix) || '' === $prefix) {
				return $stats;
			}

			$pattern = $prefix . '*';
			$deadline = microtime(true) + $deadline_seconds;

			try {
				$iterator = null;
				while (false !== ($keys = self::with_redis_error_handler_static(function () use ($redis, &$iterator, $pattern) {
					return $redis->scan($iterator, $pattern, 250);
				}, false))) {
					if (!empty($keys)) {
						foreach ($keys as $key) {
							$stats['entries']++;
							$strlen = self::with_redis_error_handler_static(function () use ($redis, $key) {
								return $redis->strlen($key);
							}, false);
							if (is_int($strlen) || ctype_digit((string) $strlen)) {
								$stats['bytes'] += (int) $strlen;
							}

							if (!$full_count && $max_keys > 0 && $stats['entries'] >= $max_keys) {
								$stats['partial'] = true;
								$stats['partialReason'] = 'sampled, Redis scan capped at ' . number_format_i18n($max_keys) . ' keys';
								break 2;
							}

							if (microtime(true) >= $deadline) {
								$stats['partial'] = true;
								$stats['partialReason'] = $full_count ? 'full count timed out before scan completed' : 'sampled, Redis scan timed out';
								break 2;
							}
						}
					}

					if (0 === $iterator) {
						break;
					}
				}
			} catch (Throwable $e) {
				$stats['partial'] = true;
				$stats['partialReason'] = 'Redis scan failed before completion';
			}

			if ($stats['partial'] && '' === $stats['partialReason'] && !$full_count && $max_keys > 0) {
				$stats['partialReason'] = 'sampled, Redis scan capped at ' . number_format_i18n($max_keys) . ' keys';
			}

			return $stats;
		}


		public static function cleanup_expired_entries() {
			$removed = self::cleanup_expired_directory(UCWP_OBJECT_CACHE_DIR);
			self::flush_stale_temp_files(UCWP_OBJECT_CACHE_DIR);
			return $removed;
		}

		private static function cleanup_expired_directory($dir) {
			if (!is_dir($dir)) {
				return 0;
			}

			$removed = 0;
			$items = ucwp_safe_scandir($dir, 'object_cache_cleanup_expired_directory scandir');
			if (!is_array($items)) {
				return 0;
			}

			foreach ($items as $item) {
				if ('.' === $item || '..' === $item) {
					continue;
				}

				$path = $dir . DIRECTORY_SEPARATOR . $item;
				if (is_dir($path)) {
					$removed += self::cleanup_expired_directory($path);
					$left = ucwp_safe_scandir($path, 'object_cache_cleanup_expired_directory child scandir');
					if (is_array($left) && 2 === count($left)) {
						ucwp_safe_rmdir($path);
					}
					continue;
				}

				if ('cache' !== strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
					continue;
				}

				$payload = self::read_payload_for_cleanup($path);
				$expired = !is_array($payload) || (!empty($payload['expires_at']) && (int) $payload['expires_at'] < time());
				if ($expired && ucwp_safe_unlink($path)) {
					$removed++;
				}
			}

			return $removed;
		}

		private static function flush_stale_temp_files($dir) {
			if (!is_dir($dir)) {
				return;
			}
			$items = ucwp_safe_scandir($dir, 'object_cache_cleanup_tmp scandir');
			if (!is_array($items)) {
				return;
			}
			foreach ($items as $item) {
				if ('.' === $item || '..' === $item) {
					continue;
				}
				$path = $dir . DIRECTORY_SEPARATOR . $item;
				if (is_dir($path)) {
					self::flush_stale_temp_files($path);
					continue;
				}
				if (false === strpos($item, '.tmp-')) {
					continue;
				}
				$mtime = ucwp_safe_filemtime($path, 'object_cache_cleanup_tmp filemtime');
				if (false === $mtime || $mtime < (time() - HOUR_IN_SECONDS)) {
					ucwp_safe_unlink($path);
				}
			}
		}

		private static function read_payload_for_cleanup($path) {
			$raw = ucwp_safe_file_get_contents($path);
			if (false === $raw || '' === $raw) {
				return false;
			}

			try {
				$envelope = @unserialize($raw, array('allowed_classes' => false));
			} catch (Throwable $e) {
				return false;
			}

			if (!is_array($envelope) || !isset($envelope['v'], $envelope['payload']) || 1 !== (int) $envelope['v']) {
				return false;
			}

			$serialized = base64_decode((string) $envelope['payload'], true);
			if (false === $serialized || '' === $serialized) {
				return false;
			}

			try {
				$payload = @unserialize($serialized, array('allowed_classes' => false));
			} catch (Throwable $e) {
				return false;
			}

			return is_array($payload) ? $payload : false;
		}

		private static function is_our_dropin($file) {
			if (!file_exists($file) || !is_readable($file)) {
				return false;
			}
			$contents = ucwp_safe_file_get_contents($file);
			return (false !== $contents && false !== strpos($contents, 'UltraCache generated object-cache drop-in'));
		}

		private static function collect_cache_files($dir, $ext) {
			$files = array();
			if (!is_dir($dir)) {
				return $files;
			}
			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
				);
				foreach ($iterator as $file) {
					if ($file->isFile() && strtolower($file->getExtension()) === strtolower($ext)) {
						$files[] = $file->getPathname();
					}
				}
			} catch (Exception $e) {
				return array();
			}
			return $files;
		}

		private static function recursive_delete($dir) {
			if (!is_dir($dir)) {
				return;
			}
			$items = ucwp_safe_scandir($dir, 'object_cache_recursive_delete scandir');
			if (!is_array($items)) {
				return;
			}
			foreach ($items as $item) {
				if ('.' === $item || '..' === $item) {
					continue;
				}
				$path = $dir . DIRECTORY_SEPARATOR . $item;
				if (is_dir($path)) {
					self::recursive_delete($path);
				} else {
					ucwp_safe_unlink($path);
				}
			}
			ucwp_safe_rmdir($dir);
		}


		private static function prune_cache_directory() {
			if (!is_dir(UCWP_OBJECT_CACHE_DIR)) {
				return;
			}

			$preserve = array(
				'index.php',
				'object-cache-metrics.json',
			);

			$items = ucwp_safe_scandir(UCWP_OBJECT_CACHE_DIR, 'object_cache_prune_root scandir');
			if (!is_array($items)) {
				return;
			}

			foreach ($items as $item) {
				if ('.' === $item || '..' === $item) {
					continue;
				}
				if (in_array($item, $preserve, true)) {
					continue;
				}
				$path = UCWP_OBJECT_CACHE_DIR . DIRECTORY_SEPARATOR . $item;
				if (is_dir($path)) {
					self::recursive_delete($path);
				} elseif (file_exists($path)) {
					ucwp_safe_unlink($path);
				}
			}
		}

		private static function reset_plugin_state_cache() {
			if (function_exists('wp_clean_plugins_cache')) {
				wp_clean_plugins_cache(true);
			}
			if (!function_exists('wp_cache_delete')) {
				return;
			}
			wp_cache_delete('active_plugins', 'options');
			wp_cache_delete('alloptions', 'options');
			wp_cache_delete('notoptions', 'options');
			wp_cache_delete('uninstall_plugins', 'options');
			wp_cache_delete('update_plugins', 'site-transient');
			if (function_exists('is_multisite') && is_multisite()) {
				wp_cache_delete('active_sitewide_plugins', 'site-options');
				wp_cache_delete('alloptions', 'site-options');
				wp_cache_delete('notoptions', 'site-options');
			}
		}

		private static function redis_supported() {
			return class_exists('Redis') || extension_loaded('redis');
		}

		public static function reset_settings_cache() {
			self::$plugin_settings_cache = null;
		}

		private static function get_plugin_settings() {
			if (null !== self::$plugin_settings_cache) {
				return self::$plugin_settings_cache;
			}

			$saved = defined('UCWP_SETTINGS_KEY') ? get_option(UCWP_SETTINGS_KEY, array()) : array();
			if (!is_array($saved)) {
				self::$plugin_settings_cache = array();
				return self::$plugin_settings_cache;
			}

            $runtime_secret = self::load_runtime_secret_file();
            $runtime_redis_password = isset($runtime_secret['redis_password']) ? (string) $runtime_secret['redis_password'] : '';
			self::$plugin_settings_cache = array(
				'object_cache_enabled' => !empty($saved['objectCacheEnabled']),
				'object_cache_backend' => !empty($saved['objectCacheBackend']) ? (string) $saved['objectCacheBackend'] : 'redis',
				'object_cache_fallback_backend' => isset($saved['objectCacheFallbackBackend']) ? self::sanitize_fallback_backend($saved['objectCacheFallbackBackend']) : 'apcu',
				'cache_stats_enabled'  => !empty($saved['cacheStatsEnabled']),
				'redis_host'           => !empty($saved['redisHost']) ? (string) $saved['redisHost'] : '127.0.0.1',
				'redis_port'           => isset($saved['redisPort']) ? absint($saved['redisPort']) : 6379,
				'redis_password'       => '' !== trim((string) $runtime_redis_password) ? (string) $runtime_redis_password : (isset($saved['redisPassword']) ? (string) $saved['redisPassword'] : ''),
				'redis_database'       => isset($saved['redisDatabase']) ? absint($saved['redisDatabase']) : 0,
				'redis_prefix'         => isset($saved['redisPrefix']) ? trim((string) $saved['redisPrefix']) : '',
				'redis_use_tls'        => !empty($saved['redisUseTls']),
				'redis_persistent'     => !empty($saved['redisPersistent']),
				'redis_connect_timeout_ms' => isset($saved['redisConnectTimeoutMs']) ? absint($saved['redisConnectTimeoutMs']) : 200,
				'redis_read_timeout_ms'    => isset($saved['redisReadTimeoutMs']) ? absint($saved['redisReadTimeoutMs']) : 200,
			);

			return self::$plugin_settings_cache;
		}

		private static function get_apcu_prefix($settings = null) {
			$settings = is_array($settings) ? $settings : self::get_plugin_settings();
			$seed = implode('|', array(
				defined('DB_NAME') ? DB_NAME : '',
				defined('DB_USER') ? DB_USER : '',
				defined('ABSPATH') ? ABSPATH : '',
				defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '',
				(string) self::get_redis_prefix($settings),
			));
			$hash = function_exists('hash') ? hash('sha256', 'ucwp-apcu|' . $seed) : md5('ucwp-apcu|' . $seed);
			return 'ucwp_apcu:' . substr((string) $hash, 0, 16) . ':';
		}

		private static function get_redis_prefix($settings = null) {
			if (is_array($settings) && !empty($settings['redis_prefix'])) {
				$prefix = preg_replace('/[^A-Za-z0-9:_\-]/', '', (string) $settings['redis_prefix']);
				$prefix = trim((string) $prefix, ':');
				if ('' !== $prefix) {
					return $prefix . ':';
				}
			}

			$seed = implode('|', array(
				defined('DB_NAME') ? DB_NAME : '',
				defined('DB_USER') ? DB_USER : '',
				defined('ABSPATH') ? ABSPATH : '',
				defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '',
			));
			$hash = function_exists('hash') ? hash('sha256', 'ucwp-redis|' . $seed) : md5('ucwp-redis|' . $seed);
			return 'ucwp:' . substr((string) $hash, 0, 12) . ':';
		}

		private static function with_redis_error_handler_static($callback, $default = false) {
			try {
				return $callback();
			} catch (Throwable $e) {
				self::$redis_last_error = sanitize_text_field($e->getMessage());
				return $default;
			}
		}

		private static function connect_redis($settings = null) {
			self::$redis_last_error = '';
			if (!self::redis_supported()) {
				self::$redis_last_error = 'PHP Redis extension is not loaded.';
				return null;
			}

			$settings = is_array($settings) ? $settings : self::get_plugin_settings();
			$host = !empty($settings['redis_host']) ? (string) $settings['redis_host'] : '127.0.0.1';
			$use_tls = !empty($settings['redis_use_tls']);
			if ($use_tls && 0 !== strpos($host, 'tls://')) {
				$host = 'tls://' . ltrim($host, '/');
			}
			$port = max(1, absint($settings['redis_port'] ?? 6379));
			$password = isset($settings['redis_password']) ? (string) $settings['redis_password'] : '';
			$database = max(0, absint($settings['redis_database'] ?? 0));
			$persistent = !empty($settings['redis_persistent']);
			$prefix = self::get_redis_prefix($settings);
			$connect_timeout = max(0.05, ((int) ($settings['redis_connect_timeout_ms'] ?? 200)) / 1000);
			$read_timeout = max(0.05, ((int) ($settings['redis_read_timeout_ms'] ?? 200)) / 1000);

			try {
				$redis = new Redis();
				$connected = false;
				if ($persistent) {
					$persistent_id = 'ucwp:' . md5(implode('|', array($host, (string) $port, (string) $database, (string) $prefix)));
					$connected = self::with_redis_error_handler_static(function () use ($redis, $host, $port, $connect_timeout, $persistent_id) {
						return $redis->pconnect($host, $port, $connect_timeout, $persistent_id);
					}, false);
				} else {
					$connected = self::with_redis_error_handler_static(function () use ($redis, $host, $port, $connect_timeout) {
						return $redis->connect($host, $port, $connect_timeout);
					}, false);
				}
				if (!$connected) {
					if ('' === self::$redis_last_error) {
						self::$redis_last_error = 'Could not connect to Redis.';
					}
					return null;
				}
				if (defined('Redis::OPT_SERIALIZER') && defined('Redis::SERIALIZER_NONE')) {
					self::with_redis_error_handler_static(function () use ($redis) {
						$redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
						return true;
					}, true);
				}
				if (defined('Redis::OPT_READ_TIMEOUT')) {
					self::with_redis_error_handler_static(function () use ($redis, $read_timeout) {
						$redis->setOption(Redis::OPT_READ_TIMEOUT, $read_timeout);
						return true;
					}, true);
				}
				if ('' !== $password) {
					$authed = self::with_redis_error_handler_static(function () use ($redis, $password) {
						return $redis->auth($password);
					}, false);
					if (!$authed) {
						if ('' === self::$redis_last_error) {
							self::$redis_last_error = 'Redis authentication failed.';
						}
						return null;
					}
				}
				if ($database > 0) {
					$selected = self::with_redis_error_handler_static(function () use ($redis, $database) {
						return $redis->select($database);
					}, false);
					if (!$selected) {
						if ('' === self::$redis_last_error) {
							self::$redis_last_error = 'Redis database select failed.';
						}
						return null;
					}
				}
				return $redis;
			} catch (Throwable $e) {
				self::$redis_last_error = $e->getMessage();
				return null;
			}
		}

		private static function flush_redis_namespace() {
			$redis = self::connect_redis();
			if (!$redis instanceof Redis) {
				return;
			}
			$pattern = self::get_redis_prefix(self::get_plugin_settings()) . '*';
			try {
				$iterator = null;
				while (false !== ($keys = self::with_redis_error_handler_static(function () use ($redis, &$iterator, $pattern) {
					return $redis->scan($iterator, $pattern, 500);
				}, false))) {
					if (!empty($keys)) {
						self::with_redis_error_handler_static(function () use ($redis, $keys) {
							$redis->del($keys);
							return true;
						}, true);
					}
					if (0 === $iterator) {
						break;
					}
				}
			} catch (Throwable $e) {
			}
		}
	}
}

