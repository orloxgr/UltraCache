<?php
/**
 * Hybrid object cache drop-in manager for UltraCache.
 */

defined('ABSPATH') || exit;


final class Ultra_Cache_Object_Cache_Manager {

	private static $plugin_settings_cache = null;
	private static $redis_last_error = '';

	private const REDIS_APCU_PAYLOAD_MAX_BYTES = 1048576;
	private const DISK_PAYLOAD_MAX_BYTES = 8388608;
	private const DIAGNOSTIC_PAYLOAD_PROBE_MAX_BYTES = 262144;
	private const SQLITE_DATABASE_DEFAULT_MB = 256;
	private const SQLITE_WAL_TARGET_BYTES = 16777216;

	public static function sync_dropin() {
		self::reset_plugin_settings_cache();
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

	public static function reset_plugin_settings_cache() {
		self::$plugin_settings_cache = null;
	}

	public static function get_unavailable_reason() {
		$settings = self::get_plugin_settings();
		if (!empty($settings['object_cache_enabled']) && 'redis' === self::get_selected_backend() && !self::redis_supported()) {
			return __('Redis backend selected, but the PHP Redis extension is not loaded.', 'ultracache');
		}
		if (!empty($settings['object_cache_enabled']) && 'sqlite' === self::get_selected_backend() && !self::sqlite_supported()) {
			return __('SQLite backend selected, but the PHP SQLite3 extension is not loaded.', 'ultracache');
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
		return in_array($backend, array('redis', 'apcu', 'sqlite', 'disk'), true) ? $backend : 'redis';
	}

	private static function sanitize_fallback_backend($value) {
		$value = strtolower(trim((string) $value));
		if ('none' === $value || 'runtime' === $value || '' === $value) {
			return 'none';
		}
		return in_array($value, array('apcu', 'sqlite', 'disk'), true) ? $value : 'apcu';
	}

	private static function sanitize_sqlite_database_size_mb($value) {
		$value = absint($value);
		return in_array($value, array(32, 64, 128, 256, 512, 1024, 2048), true) ? $value : self::SQLITE_DATABASE_DEFAULT_MB;
	}

	private static function get_sqlite_database_max_bytes($settings = null) {
		$settings = is_array($settings) ? $settings : self::get_plugin_settings();
		$size_mb = self::sanitize_sqlite_database_size_mb($settings['sqlite_database_size_mb'] ?? self::SQLITE_DATABASE_DEFAULT_MB);
		return $size_mb * MB_IN_BYTES;
	}

	private static function get_backend_label($backend) {
		$labels = array(
			'redis' => 'Redis',
			'apcu' => 'APCu',
			'sqlite' => 'SQLite',
			'disk' => 'Disk',
			'runtime' => 'runtime-only',
		);
		$backend = strtolower(trim((string) $backend));
		return $labels[$backend] ?? $backend;
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
			'sqlite' => array(
				'enabled' => false,
				'available' => self::sqlite_supported(),
				'version' => self::get_sqlite_version(),
				'path' => self::get_sqlite_path(),
				'journalMode' => '',
				'databaseMaxMb' => self::sanitize_sqlite_database_size_mb(self::get_plugin_settings()['sqlite_database_size_mb'] ?? self::SQLITE_DATABASE_DEFAULT_MB),
				'databaseMaxBytes' => self::get_sqlite_database_max_bytes(),
				'journalTargetMb' => (int) round(self::SQLITE_WAL_TARGET_BYTES / MB_IN_BYTES),
				'journalTargetBytes' => self::SQLITE_WAL_TARGET_BYTES,
				'error' => '',
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

		$runtime_status_used = false;
		global $wp_object_cache;
		if (is_object($wp_object_cache)) {
			if (method_exists($wp_object_cache, 'get_backend_status')) {
				$runtime_status = $wp_object_cache->get_backend_status();
				if (is_array($runtime_status)) {
					$runtime_selected = isset($runtime_status['selected']) ? strtolower(trim((string) $runtime_status['selected'])) : '';
					$runtime_fallback = self::sanitize_fallback_backend($runtime_status['configuredFallback'] ?? $runtime_status['fallback'] ?? $selected_fallback);
					if ($runtime_selected === $selected && $runtime_fallback === $selected_fallback) {
						$status = array_replace_recursive($status, $runtime_status);
						$runtime_status_used = true;
					} else {
						$status['runtimeConfigStale'] = true;
						$status['runtimeSelected'] = $runtime_selected;
						$status['runtimeConfiguredFallback'] = $runtime_fallback;
					}
				}
			} elseif (method_exists($wp_object_cache, 'get_backend')) {
				$runtime_backend = (string) $wp_object_cache->get_backend();
				if ('' !== $runtime_backend) {
					$status['runtimeActive'] = $runtime_backend;
				}
			}
		}

		$status['runtimeStatusUsed'] = $runtime_status_used;
		$status['selected'] = $selected;
		$status['active'] = in_array((string) ($status['active'] ?? ''), array('redis', 'apcu', 'sqlite', 'disk', 'runtime'), true) ? (string) $status['active'] : $selected;
		if (!$runtime_status_used) {
			$status['active'] = $selected;
			$selected_available = 'redis' === $selected
				? !empty($status['redis']['available'])
				: ('apcu' === $selected
					? !empty($status['apcu']['available'])
					: ('sqlite' === $selected ? !empty($status['sqlite']['available']) : true));
			if (!$selected_available) {
				if ('apcu' === $selected_fallback && !empty($status['apcu']['available'])) {
					$status['active'] = 'apcu';
				} elseif ('sqlite' === $selected_fallback && !empty($status['sqlite']['available'])) {
					$status['active'] = 'sqlite';
				} elseif ('disk' === $selected_fallback) {
					$status['active'] = 'disk';
				} else {
					$status['active'] = 'runtime';
				}
			}
		}
		$configured_fallback = self::sanitize_fallback_backend($status['configuredFallback'] ?? $selected_fallback);
		$standby_fallback = ('none' === $configured_fallback ? 'runtime' : $configured_fallback);
		$active_backend = (string) $status['active'];
		$status['fallbackActive'] = ((string) $status['selected'] !== $active_backend);
		$status['configuredFallback'] = $configured_fallback;
		$status['fallback'] = $status['fallbackActive'] ? $active_backend : $standby_fallback;
		$status['activeFallbackBackend'] = $status['fallbackActive'] ? $active_backend : '';
		$status['activeFallbackKind'] = $status['fallbackActive'] ? ('runtime' === $active_backend ? 'runtime-only' : 'persistent') : '';
		$status['fallbackPersistent'] = $status['fallbackActive'] && in_array($active_backend, array('redis', 'apcu', 'sqlite', 'disk'), true);
		$status['activeBackendRuntimeOnly'] = ('runtime' === $active_backend);
		if ($status['fallbackActive']) {
			$backend_error = '';
			if ('redis' === $status['selected']) {
				$backend_error = isset($status['redis']['error']) ? trim((string) $status['redis']['error']) : '';
			} elseif ('sqlite' === $status['selected']) {
				$backend_error = isset($status['sqlite']['error']) ? trim((string) $status['sqlite']['error']) : '';
			}
			$fallback_label = self::get_backend_label($status['fallback']);
			$selected_label = self::get_backend_label($status['selected']);
			$status['fallbackReason'] = '' !== $backend_error ? $backend_error : $selected_label . ' was selected but did not become active during drop-in bootstrap.';
			$status['fallbackMessage'] = $selected_label . ' selected, ' . $fallback_label . ' fallback active.' . ('' !== $status['fallbackReason'] ? ' Reason: ' . $status['fallbackReason'] : '');
		}

		return $status;
	}

	public static function is_dropin_active() {
		$dropin = ultracache_dropin_path('object-cache.php');

		return (bool) (
			function_exists('wp_using_ext_object_cache')
			&& wp_using_ext_object_cache()
			&& self::is_our_dropin($dropin)
		);
	}

	public static function ensure_cache_directory() {
		if (!file_exists(ULTRACACHE_OBJECT_CACHE_DIR)) {
			wp_mkdir_p(ULTRACACHE_OBJECT_CACHE_DIR);
		}

		$index = trailingslashit(ULTRACACHE_OBJECT_CACHE_DIR) . 'index.php';
		if (!file_exists($index)) {
			ultracache_safe_file_put_contents($index, "<?php\n// Silence is golden.\n");
		}

		$htaccess = trailingslashit(ULTRACACHE_OBJECT_CACHE_DIR) . '.htaccess';
		if (!file_exists($htaccess)) {
			ultracache_safe_file_put_contents($htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
		}

		$web_config = trailingslashit(ULTRACACHE_OBJECT_CACHE_DIR) . 'web.config';
		if (!file_exists($web_config)) {
			ultracache_safe_file_put_contents($web_config, '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>');
		}

		$filesystem = function_exists('ultracache_get_wp_filesystem') ? ultracache_get_wp_filesystem() : null;
		if (is_object($filesystem) && method_exists($filesystem, 'chmod')) {
			$filesystem->chmod(ULTRACACHE_OBJECT_CACHE_DIR, 0700);
		}
	}


	private static function get_dropin_path() {
		return ultracache_dropin_path('object-cache.php');
	}

	public static function get_dropin_status_fast() {
		$contents = ultracache_dropin_exists('object-cache.php') ? ultracache_read_dropin('object-cache.php') : false;
		$status = array(
			'exists' => ultracache_dropin_exists('object-cache.php'),
			'readable' => is_string($contents),
			'has_marker' => false,
			'build' => '',
			'expected_build' => defined('ULTRACACHE_VERSION') ? (string) ULTRACACHE_VERSION : '',
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

	private static function get_dropin_backend_classes_source() {
		$files = array(
			'includes/object-cache/backends/class-object-cache-backend-interface.php',
			'includes/object-cache/backends/class-object-cache-backend-context.php',
			'includes/object-cache/backends/class-object-cache-abstract-backend.php',
			'includes/object-cache/backends/class-object-cache-runtime-backend.php',
			'includes/object-cache/backends/class-object-cache-redis-backend.php',
			'includes/object-cache/backends/class-object-cache-sqlite-backend.php',
			'includes/object-cache/backends/class-object-cache-apcu-backend.php',
			'includes/object-cache/backends/class-object-cache-disk-backend.php',
		);
		$source = array();

		foreach ($files as $relative_file) {
			$file = ultracache_plugin_dir($relative_file);
			if (!file_exists($file) || !is_readable($file)) {
				return '';
			}

			$contents = (string) ultracache_safe_file_get_contents($file, 'object_cache_backend_source');
			if ('' === trim($contents)) {
				return '';
			}

			$contents = preg_replace('/^(?:\xEF\xBB\xBF)?\s*<\?php\s*/', '', $contents, 1);
			if (!is_string($contents) || false !== strpos($contents, '?>')) {
				return '';
			}
			$source[] = trim($contents);
		}

		return implode("\n\n", $source) . "\n";
	}

	public static function setup_dropin() {
		self::ensure_cache_directory();

		$dropin = self::get_dropin_path();

		if (ultracache_dropin_exists('object-cache.php') && !self::is_our_dropin($dropin)) {
			return false;
		}

		$settings = self::get_plugin_settings();
		$backend_classes_source = self::get_dropin_backend_classes_source();
		if ('' === $backend_classes_source) {
			return false;
		}

		$placeholders = array(
			'__ULTRACACHE_OBJECT_CACHE_BACKEND_CLASSES__' => $backend_classes_source,
			'__ULTRACACHE_DROPIN_BUILD__' => ULTRACACHE_VERSION,
			'__ULTRACACHE_OBJECT_CACHE_DIR__' => ultracache_php_string_literal(ULTRACACHE_OBJECT_CACHE_DIR),
			'__ULTRACACHE_SQLITE_PATH__' => ultracache_php_string_literal(self::get_sqlite_path()),
			'__ULTRACACHE_SELECTED_BACKEND__' => ultracache_php_string_literal(self::get_selected_backend()),
			'__ULTRACACHE_FALLBACK_BACKEND__' => ultracache_php_string_literal(self::get_selected_fallback_backend()),
			'__ULTRACACHE_SQLITE_DATABASE_MAX_BYTES__' => (string) self::get_sqlite_database_max_bytes($settings),
			'__ULTRACACHE_CACHE_STATS_ENABLED__' => !empty($settings['cache_stats_enabled']) ? 'true' : 'false',
			'__ULTRACACHE_REDIS_HOST__'       => ultracache_php_string_literal((string) ($settings['redis_host'] ?? '127.0.0.1')),
			'__ULTRACACHE_REDIS_PORT__'       => (string) max(1, absint($settings['redis_port'] ?? 6379)),
			'__ULTRACACHE_REDIS_USERNAME__'   => ultracache_php_string_literal((string) ($settings['redis_username'] ?? '')),
			'__ULTRACACHE_REDIS_DATABASE__'   => (string) max(0, absint($settings['redis_database'] ?? 0)),
			'__ULTRACACHE_REDIS_PREFIX__'     => ultracache_php_string_literal(self::get_redis_prefix($settings)),
			'__ULTRACACHE_SITE_NAMESPACE_SEED__' => ultracache_php_string_literal(ultracache_site_namespace_seed()),
			'__ULTRACACHE_REDIS_USE_TLS__'    => !empty($settings['redis_use_tls']) ? 'true' : 'false',
			'__ULTRACACHE_REDIS_PERSISTENT__' => !empty($settings['redis_persistent']) ? 'true' : 'false',
			'__ULTRACACHE_REDIS_CONNECT_TIMEOUT__' => ultracache_php_float_literal(max(0.05, ((int) ($settings['redis_connect_timeout_ms'] ?? 200)) / 1000)),
			'__ULTRACACHE_REDIS_READ_TIMEOUT__'    => ultracache_php_float_literal(max(0.05, ((int) ($settings['redis_read_timeout_ms'] ?? 200)) / 1000)),
		);

		$template = ultracache_plugin_dir('templates/object-cache.php.tpl');
		if (!file_exists($template) || !is_readable($template)) {
			return false;
		}

		$code = (string) ultracache_safe_file_get_contents($template, 'object_cache_template');
		if ('' === $code) {
			return false;
		}

		$code = str_replace(array_keys($placeholders), array_values($placeholders), $code);

		if (ultracache_dropin_exists('object-cache.php')) {
			$existing = ultracache_read_dropin('object-cache.php');
			if (is_string($existing) && $existing === $code) {
				return true;
			}
		}

		return ultracache_write_dropin('object-cache.php', $code);
	}

	public static function maybe_remove_dropin() {
		$dropin = self::get_dropin_path();
		if (self::is_our_dropin($dropin)) {
			ultracache_delete_dropin('object-cache.php');
		}
	}

	public static function flush_cache($force_hard = false, $reset_plugin_state = true) {
		$report = self::flush_cache_with_report($force_hard, $reset_plugin_state, 'active');
		return !empty($report['success']);
	}

	public static function flush_cache_with_report($force_hard = false, $reset_plugin_state = true, $backend = 'active') {
		$force_hard = (bool) $force_hard;
		$reset_plugin_state = (bool) $reset_plugin_state;
		$requested_backend = self::sanitize_flush_backend($backend);
		$target_backend = self::resolve_flush_backend($requested_backend);
		$pre_files = self::capture_cache_file_state(ULTRACACHE_OBJECT_CACHE_DIR);
		$report = array(
			'success' => false,
			'forceHard' => $force_hard,
			'requestedBackend' => $requested_backend,
			'targetBackend' => $target_backend,
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
		if (is_object($wp_object_cache) && method_exists($wp_object_cache, 'flush_runtime')) {
			$wp_object_cache->flush_runtime();
		}

		if ('redis' === $target_backend) {
			$report['dropinFlushCalled'] = false;
			$report['dropinFlushResult'] = self::flush_redis_namespace();
			$report['success'] = (bool) $report['dropinFlushResult'];
			$report['semanticStatus'] = $report['success'] ? 'redis_namespace_flushed' : 'redis_flush_failed';
		} elseif ('apcu' === $target_backend) {
			$report['dropinFlushCalled'] = false;
			$report['dropinFlushResult'] = self::flush_apcu_namespace(self::get_apcu_prefix());
			$report['success'] = (bool) $report['dropinFlushResult'];
			$report['semanticStatus'] = $report['success'] ? 'apcu_namespace_flushed' : 'apcu_flush_failed';
		} elseif ('sqlite' === $target_backend) {
			$report['dropinFlushCalled'] = false;
			$report['dropinFlushResult'] = self::flush_sqlite_object_cache();
			$report['success'] = (bool) $report['dropinFlushResult'];
			$report['semanticStatus'] = $report['success'] ? 'sqlite_cache_flushed' : 'sqlite_flush_failed';
		} elseif ('disk' === $target_backend) {
			self::flush_disk_cache_files();
			self::ensure_cache_directory();
			$report['dropinFlushResult'] = true;
			$report['success'] = true;
			$report['semanticStatus'] = 'disk_cache_flushed';
		} else {
			$report['success'] = true;
			$report['semanticStatus'] = 'runtime_cache_flushed';
		}

		self::ensure_cache_directory();
		if ($reset_plugin_state) {
			self::reset_plugin_state_cache();
		}
		self::prune_cache_directory();
		self::flush_stale_temp_files(ULTRACACHE_OBJECT_CACHE_DIR);
		clearstatcache(true);
		$report['completedAt'] = microtime(true);

		$post_files = self::capture_cache_file_state(ULTRACACHE_OBJECT_CACHE_DIR);
		$classified = self::classify_flush_cache_files($pre_files, $post_files, $report['completedAt']);
		$report['postFlushEntries'] = count($post_files);
		$report['staleEntries'] = count($classified['stale']);
		$report['recreatedEntries'] = count($classified['recreated']);
		$report['staleFiles'] = self::limit_cache_file_samples($classified['stale']);
		$report['recreatedFiles'] = self::limit_cache_file_samples($classified['recreated']);
		if ('disk' === $target_backend) {
			$report['semanticStatus'] = self::determine_flush_semantic_status($report['staleEntries'], $report['recreatedEntries']);
			$report['success'] = (0 === $report['staleEntries']);
		}
		$report['message'] = self::build_backend_flush_report_message($report);
		self::store_last_flush_report($report);
		return $report;
	}


	private static function sanitize_flush_backend($backend) {
		$backend = strtolower(trim((string) $backend));
		if ('' === $backend) {
			return 'active';
		}
		return in_array($backend, array('active', 'selected', 'redis', 'apcu', 'sqlite', 'disk', 'runtime'), true) ? $backend : 'active';
	}

	private static function resolve_flush_backend($backend) {
		$backend = self::sanitize_flush_backend($backend);
		$status = self::get_backend_status();
		if ('selected' === $backend) {
			$selected = isset($status['selected']) ? strtolower((string) $status['selected']) : self::get_selected_backend();
			return in_array($selected, array('redis', 'apcu', 'sqlite', 'disk'), true) ? $selected : 'runtime';
		}
		if ('active' === $backend) {
			$active = isset($status['active']) ? strtolower((string) $status['active']) : self::get_active_backend();
			return in_array($active, array('redis', 'apcu', 'sqlite', 'disk', 'runtime'), true) ? $active : 'runtime';
		}
		return $backend;
	}

	private static function build_backend_flush_report_message($report) {
		$backend = isset($report['targetBackend']) ? strtolower((string) $report['targetBackend']) : 'object';
		$label = 'redis' === $backend ? 'Redis' : ('apcu' === $backend ? 'APCu' : ('sqlite' === $backend ? 'SQLite' : ('disk' === $backend ? 'Disk' : 'Runtime')));
		if (empty($report['success'])) {
			return sprintf(
				/* translators: %s: object cache backend label, for example Redis. */
				__('%s object cache flush failed.', 'ultracache'),
				$label
			);
		}
		if ('disk' === $backend) {
			$base = self::build_flush_report_message($report);
			return sprintf(
				/* translators: %s: disk object cache flush report message. */
				__('Disk object cache: %s', 'ultracache'),
				$base
			);
		}
		if ('apcu' === $backend && defined('WP_CLI') && WP_CLI) {
			return __('APCu object cache flushed for this PHP process. Note: PHP-FPM/web APCu pools may require a dashboard/web flush or PHP-FPM restart.', 'ultracache');
		}
		return sprintf(
			/* translators: %s: object cache backend label, for example Redis. */
			__('%s object cache flushed.', 'ultracache'),
			$label
		);
	}

	public static function test_object_cache_backend($backend = 'selected', array $override = array()) {
		$backend = self::resolve_flush_backend($backend);
		if ('redis' === $backend) {
			$result = self::test_redis_read_write($override);
			$result['backend'] = 'redis';
			return $result;
		}
		if ('apcu' === $backend) {
			return self::test_apcu_connection();
		}
		if ('sqlite' === $backend) {
			return self::test_sqlite_object_cache();
		}
		if ('disk' === $backend) {
			return self::test_disk_object_cache();
		}
		return array(
			'success' => true,
			'backend' => 'runtime',
			'available' => true,
			'message' => __('Runtime-only object cache is available for the current PHP request.', 'ultracache'),
		);
	}

	public static function test_apcu_connection() {
		$result = array(
			'success' => false,
			'backend' => 'apcu',
			'available' => function_exists('apcu_store') && function_exists('apcu_fetch') && function_exists('apcu_delete') && (!function_exists('apcu_enabled') || apcu_enabled()),
			'message' => '',
		);
		if (!$result['available']) {
			$result['message'] = 'APCu is not available for this PHP runtime.';
			return $result;
		}
		$key = self::get_apcu_prefix() . 'probe:' . md5(uniqid('ultracache-apcu', true));
		$value = 'ultracache:' . md5($key . microtime(true));
		$success = false;
		try {
			$stored = @apcu_store($key, $value, 30);
			$fetched = @apcu_fetch($key, $success);
			@apcu_delete($key);
			$result['success'] = (bool) $stored && (bool) $success && (string) $fetched === (string) $value;
			$result['message'] = $result['success'] ? 'APCu read/write probe passed.' : 'APCu is available, but the read/write probe failed.';
		} catch (Throwable $e) {
			$result['message'] = $e->getMessage();
		}
		return $result;
	}

	public static function test_sqlite_object_cache() {
		$result = array(
			'success' => false,
			'backend' => 'sqlite',
			'available' => self::sqlite_supported(),
			'version' => self::get_sqlite_version(),
			'path' => self::get_sqlite_path(),
			'journalMode' => '',
			'checks' => array(
				'write' => false,
				'read' => false,
				'delete' => false,
				'expiration' => false,
			),
			'message' => '',
		);
		if (!$result['available']) {
			$result['message'] = __('PHP SQLite3 is not available for this runtime.', 'ultracache');
			return $result;
		}

		$sqlite = self::open_sqlite_database();
		if (!$sqlite instanceof SQLite3) {
			$result['message'] = __('SQLite object-cache database could not be opened.', 'ultracache');
			return $result;
		}

		$live_key = 'probe-live-' . md5(uniqid('ultracache-sqlite', true));
		$expired_key = 'probe-expired-' . md5(uniqid('ultracache-sqlite', true));
		$value = 'ultracache:' . md5($live_key . microtime(true));
		try {
			$result['journalMode'] = strtolower((string) $sqlite->querySingle('PRAGMA journal_mode'));

			$write_statement = $sqlite->prepare('INSERT OR REPLACE INTO ultracache_object_cache (cache_id, cache_scope, cache_group, payload, expires_at, updated_at) VALUES (:cache_id, :cache_scope, :cache_group, :payload, :expires_at, :updated_at)');
			if (!$write_statement) {
				throw new RuntimeException((string) $sqlite->lastErrorMsg());
			}
			$write_statement->bindValue(':cache_id', $live_key, SQLITE3_TEXT);
			$write_statement->bindValue(':cache_scope', 'diagnostic', SQLITE3_TEXT);
			$write_statement->bindValue(':cache_group', 'diagnostic', SQLITE3_TEXT);
			$write_statement->bindValue(':payload', $value, SQLITE3_BLOB);
			$write_statement->bindValue(':expires_at', time() + 30, SQLITE3_INTEGER);
			$write_statement->bindValue(':updated_at', time(), SQLITE3_INTEGER);
			$write_result = $write_statement->execute();
			$result['checks']['write'] = $write_result instanceof SQLite3Result;
			if ($write_result instanceof SQLite3Result) {
				$write_result->finalize();
			}
			$write_statement->close();

			$read_statement = $sqlite->prepare('SELECT payload FROM ultracache_object_cache WHERE cache_id = :cache_id LIMIT 1');
			if (!$read_statement) {
				throw new RuntimeException((string) $sqlite->lastErrorMsg());
			}
			$read_statement->bindValue(':cache_id', $live_key, SQLITE3_TEXT);
			$read_result = $read_statement->execute();
			$row = $read_result ? $read_result->fetchArray(SQLITE3_ASSOC) : false;
			if ($read_result instanceof SQLite3Result) {
				$read_result->finalize();
			}
			$read_statement->close();
			$result['checks']['read'] = is_array($row) && isset($row['payload']) && (string) $row['payload'] === $value;

			$delete_statement = $sqlite->prepare('DELETE FROM ultracache_object_cache WHERE cache_id = :cache_id');
			if (!$delete_statement) {
				throw new RuntimeException((string) $sqlite->lastErrorMsg());
			}
			$delete_statement->bindValue(':cache_id', $live_key, SQLITE3_TEXT);
			$delete_result = $delete_statement->execute();
			if ($delete_result instanceof SQLite3Result) {
				$delete_result->finalize();
			}
			$delete_statement->close();

			$verify_delete_statement = $sqlite->prepare('SELECT COUNT(*) FROM ultracache_object_cache WHERE cache_id = :cache_id');
			if (!$verify_delete_statement) {
				throw new RuntimeException((string) $sqlite->lastErrorMsg());
			}
			$verify_delete_statement->bindValue(':cache_id', $live_key, SQLITE3_TEXT);
			$verify_delete_result = $verify_delete_statement->execute();
			$delete_count_row = $verify_delete_result ? $verify_delete_result->fetchArray(SQLITE3_NUM) : false;
			$remaining = is_array($delete_count_row) ? (int) ($delete_count_row[0] ?? 1) : 1;
			if ($verify_delete_result instanceof SQLite3Result) {
				$verify_delete_result->finalize();
			}
			$verify_delete_statement->close();
			$result['checks']['delete'] = (0 === $remaining);

			$expiry_statement = $sqlite->prepare('INSERT OR REPLACE INTO ultracache_object_cache (cache_id, cache_scope, cache_group, payload, expires_at, updated_at) VALUES (:cache_id, :cache_scope, :cache_group, :payload, :expires_at, :updated_at)');
			if (!$expiry_statement) {
				throw new RuntimeException((string) $sqlite->lastErrorMsg());
			}
			$expiry_statement->bindValue(':cache_id', $expired_key, SQLITE3_TEXT);
			$expiry_statement->bindValue(':cache_scope', 'diagnostic', SQLITE3_TEXT);
			$expiry_statement->bindValue(':cache_group', 'diagnostic', SQLITE3_TEXT);
			$expiry_statement->bindValue(':payload', $value, SQLITE3_BLOB);
			$expiry_statement->bindValue(':expires_at', time() - 1, SQLITE3_INTEGER);
			$expiry_statement->bindValue(':updated_at', time(), SQLITE3_INTEGER);
			$expiry_result = $expiry_statement->execute();
			if ($expiry_result instanceof SQLite3Result) {
				$expiry_result->finalize();
			}
			$expiry_statement->close();

			$expiry_read_statement = $sqlite->prepare('SELECT expires_at FROM ultracache_object_cache WHERE cache_id = :cache_id LIMIT 1');
			if (!$expiry_read_statement) {
				throw new RuntimeException((string) $sqlite->lastErrorMsg());
			}
			$expiry_read_statement->bindValue(':cache_id', $expired_key, SQLITE3_TEXT);
			$expiry_read_result = $expiry_read_statement->execute();
			$expiry_row = $expiry_read_result ? $expiry_read_result->fetchArray(SQLITE3_ASSOC) : false;
			if ($expiry_read_result instanceof SQLite3Result) {
				$expiry_read_result->finalize();
			}
			$expiry_read_statement->close();
			$expired = is_array($expiry_row) && !empty($expiry_row['expires_at']) && (int) $expiry_row['expires_at'] < time();
			if ($expired) {
				$expire_delete_statement = $sqlite->prepare('DELETE FROM ultracache_object_cache WHERE cache_id = :cache_id');
				if (!$expire_delete_statement) {
					throw new RuntimeException((string) $sqlite->lastErrorMsg());
				}
				$expire_delete_statement->bindValue(':cache_id', $expired_key, SQLITE3_TEXT);
				$expire_delete_result = $expire_delete_statement->execute();
				if ($expire_delete_result instanceof SQLite3Result) {
					$expire_delete_result->finalize();
				}
				$expire_delete_statement->close();
			}
			$expired_remaining_statement = $sqlite->prepare('SELECT COUNT(*) FROM ultracache_object_cache WHERE cache_id = :cache_id');
			if (!$expired_remaining_statement) {
				throw new RuntimeException((string) $sqlite->lastErrorMsg());
			}
			$expired_remaining_statement->bindValue(':cache_id', $expired_key, SQLITE3_TEXT);
			$expired_remaining_result = $expired_remaining_statement->execute();
			$expired_count_row = $expired_remaining_result ? $expired_remaining_result->fetchArray(SQLITE3_NUM) : false;
			$expired_remaining = is_array($expired_count_row) ? (int) ($expired_count_row[0] ?? 1) : 1;
			if ($expired_remaining_result instanceof SQLite3Result) {
				$expired_remaining_result->finalize();
			}
			$expired_remaining_statement->close();
			$result['checks']['expiration'] = $expired && 0 === $expired_remaining;

			$result['success'] = !in_array(false, $result['checks'], true);
			$result['message'] = $result['success']
				? __('SQLite object cache functional test passed.', 'ultracache')
				: __('SQLite object cache functional test failed.', 'ultracache');
		} catch (Throwable $e) {
			$result['message'] = $e->getMessage();
		} finally {
			try {
				$cleanup_statement = $sqlite->prepare('DELETE FROM ultracache_object_cache WHERE cache_id IN (:live_key, :expired_key)');
				if ($cleanup_statement) {
					$cleanup_statement->bindValue(':live_key', $live_key, SQLITE3_TEXT);
					$cleanup_statement->bindValue(':expired_key', $expired_key, SQLITE3_TEXT);
					$cleanup_result = $cleanup_statement->execute();
					if ($cleanup_result instanceof SQLite3Result) {
						$cleanup_result->finalize();
					}
					$cleanup_statement->close();
				}
			} catch (Throwable $e) {
				if ('' === (string) $result['message']) {
					$result['message'] = $e->getMessage();
				}
			}
			$sqlite->close();
		}

		$exposure = self::get_sqlite_public_exposure_status();
		$result['publicExposure'] = $exposure;
		$result['checks']['publicAccessBlocked'] = !empty($exposure['checked']) && empty($exposure['exposed']);
		$result['success'] = !in_array(false, $result['checks'], true);
		if (empty($exposure['checked']) || !empty($exposure['exposed'])) {
			$result['message'] = (string) $exposure['message'];
		}
		return $result;
	}

	public static function test_disk_object_cache() {
		$result = array(
			'success' => false,
			'backend' => 'disk',
			'available' => false,
			'message' => '',
		);
		self::ensure_cache_directory();
		$result['available'] = is_dir(ULTRACACHE_OBJECT_CACHE_DIR) && function_exists('ultracache_path_is_writable') && ultracache_path_is_writable(ULTRACACHE_OBJECT_CACHE_DIR);
		if (!$result['available']) {
			$result['message'] = 'Disk object cache directory is not writable.';
			return $result;
		}
		$file = trailingslashit(ULTRACACHE_OBJECT_CACHE_DIR) . 'disk-probe-' . md5(uniqid('ultracache-disk', true)) . '.tmp';
		$value = 'ultracache:' . md5($file . microtime(true));
		try {
			$written = ultracache_safe_file_put_contents($file, $value, LOCK_EX, 'object_cache_disk_probe');
			$read = is_readable($file) ? ultracache_safe_file_get_contents($file, 'object_cache_disk_probe_read') : false;
			ultracache_safe_unlink($file, 'object_cache_disk_probe_delete');
			$result['success'] = false !== $written && (string) $read === (string) $value;
			$result['message'] = $result['success'] ? 'Disk object cache read/write probe passed.' : 'Disk object cache read/write probe failed.';
		} catch (Throwable $e) {
			$result['message'] = $e->getMessage();
			if (file_exists($file)) {
				ultracache_safe_unlink($file, 'object_cache_disk_probe_cleanup');
			}
		}
		return $result;
	}

	public static function get_last_flush_report() {
		$report = get_option('ultracache_object_cache_last_flush_report', array());
		return is_array($report) ? $report : array();
	}

	public static function test_redis_connection($override = array()) {
		$settings = self::get_plugin_settings();
		if (is_array($override) && !empty($override)) {
			$settings = array_merge($settings, array_filter(array(
				'redis_host' => isset($override['redisHost']) ? (string) $override['redisHost'] : (isset($override['redis_host']) ? (string) $override['redis_host'] : null),
				'redis_port' => isset($override['redisPort']) ? absint($override['redisPort']) : (isset($override['redis_port']) ? absint($override['redis_port']) : null),
				'redis_username' => isset($override['redisUsername']) ? sanitize_text_field((string) $override['redisUsername']) : (isset($override['redis_username']) ? sanitize_text_field((string) $override['redis_username']) : null),
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
			'usernameConfigured' => '' !== trim((string) ($settings['redis_username'] ?? '')),
			'message' => '',
		);

		if (!$result['available']) {
			$result['message'] = 'PHP Redis extension is not loaded on this server.';
			return $result;
		}

		$target_validation = self::validate_redis_socket_target($settings, 'object_cache_redis_test');
		if (is_wp_error($target_validation)) {
			$result['blocked'] = true;
			$result['code'] = $target_validation->get_error_code();
			$result['message'] = $target_validation->get_error_message();
			return $result;
		}

		$redis = self::connect_redis($settings);
		if (!$redis instanceof Redis) {
			$result['message'] = '' !== self::$redis_last_error ? self::$redis_last_error : 'Could not connect to Redis with the provided settings.';
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
				'redis_username' => isset($override['redisUsername']) ? sanitize_text_field((string) $override['redisUsername']) : (isset($override['redis_username']) ? sanitize_text_field((string) $override['redis_username']) : null),
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
		$key = $prefix . 'analytics-probe:' . md5(uniqid('ultracache', true));
		$value = 'ultracache:' . md5($key . '|' . microtime(true));

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


	private static function get_diagnostic_payload_limit_bytes($backend) {
		$backend = strtolower((string) $backend);
		if (in_array($backend, array('sqlite', 'disk'), true)) {
			return self::DISK_PAYLOAD_MAX_BYTES;
		}
		return self::REDIS_APCU_PAYLOAD_MAX_BYTES;
	}

	private static function get_diagnostic_payload_probe_size_bytes($backend) {
		$limit = self::get_diagnostic_payload_limit_bytes($backend);
		$size = (int) floor($limit / 4);
		$size = max(1024, $size);
		return min(self::DIAGNOSTIC_PAYLOAD_PROBE_MAX_BYTES, $size);
	}


	public static function test_runtime_object_cache_payloads() {
		$backend_status = self::get_backend_status();
		$result = array(
			'success' => false,
			'backendStatus' => $backend_status,
			'probes' => array(),
			'message' => '',
		);

		if (!empty($backend_status['runtimeConfigStale'])) {
			$fresh_backend = isset($backend_status['active']) ? strtolower((string) $backend_status['active']) : self::get_selected_backend();
			if (!in_array($fresh_backend, array('redis', 'apcu', 'sqlite', 'disk'), true)) {
				$fresh_backend = self::get_selected_backend();
			}

			$fresh_probe = self::test_object_cache_backend($fresh_backend);
			$result['success'] = !empty($fresh_probe['success']);
			$result['staleRuntimeSkipped'] = true;
			$result['freshBackendProbe'] = $fresh_probe;
			$result['probes']['fresh_backend'] = $fresh_probe;
			$label = self::get_backend_label($fresh_backend);
			$result['message'] = !empty($fresh_probe['success'])
				? $label . ' backend read/write probe passed. Runtime object-cache payload probe is waiting for the next WordPress bootstrap after the backend switch.'
				: $label . ' backend read/write probe failed after the backend switch.';

			return $result;
		}

		if (!function_exists('wp_cache_set') || !function_exists('wp_cache_get')) {
			$result['message'] = 'WordPress object cache functions are unavailable.';
			return $result;
		}

		$active_backend = isset($backend_status['active']) ? strtolower((string) $backend_status['active']) : 'runtime';
		$payload_limit_bytes = self::get_diagnostic_payload_limit_bytes($active_backend);
		$safe_probe_bytes = self::get_diagnostic_payload_probe_size_bytes($active_backend);
		$result['payloadLimitBytes'] = (int) $payload_limit_bytes;
		$result['safeProbeBytes'] = (int) $safe_probe_bytes;

		$object = new stdClass();
		$object->alpha = 1;
		$object->nested = new stdClass();
		$object->nested->beta = 'two';

		$payloads = array(
			'string' => 'ultracache:' . md5((string) microtime(true)),
			'array' => array('alpha' => 1, 'nested' => array('beta' => 'two')),
			'object' => $object,
			'safe_size' => str_repeat('u', $safe_probe_bytes),
		);

		$group = 'ultracache_diagnostics';
		$all_ok = true;

		foreach ($payloads as $type => $value) {
			$key = 'ultracache_payload_probe_' . $type . '_' . md5(uniqid('ultracache', true));
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
			$serialized_size = 0;
			try {
				$serialized_probe = serialize($value);
				$serialized_size = is_string($serialized_probe) ? strlen($serialized_probe) : 0;
			} catch (Throwable $e) {
				$serialized_size = 0;
			}
			$result['probes'][$type] = array(
				'set' => (bool) $set,
				'found' => (bool) $found,
				'matches' => (bool) $matches,
				'success' => (bool) $ok,
				'readType' => is_object($read) ? get_class($read) : gettype($read),
				'sizeBytes' => (int) $serialized_size,
			);
		}

		$result['success'] = (bool) $all_ok;
		$result['backendStatus'] = self::get_backend_status();
		$result['message'] = $all_ok
			? 'Object cache payload probe passed for string, array, object, and safe-size values. Tested safe payload: ' . size_format($safe_probe_bytes) . ' / limit ' . size_format($payload_limit_bytes) . '.'
			: 'Object cache payload probe failed below the configured payload limit. Tested safe payload: ' . size_format($safe_probe_bytes) . ' / limit ' . size_format($payload_limit_bytes) . '.';
		return $result;
	}

	private static function get_metrics_file() {
		return trailingslashit(ULTRACACHE_OBJECT_CACHE_DIR) . 'object-cache-metrics.json';
	}

	private static function read_metrics_snapshot() {
		$data = array('hits' => 0, 'misses' => 0);
		$file = self::get_metrics_file();
		if (!file_exists($file) || !is_readable($file)) {
			return $data;
		}
		$raw = ultracache_safe_file_get_contents($file);
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
			ultracache_safe_unlink($file);
		}
		return true;
	}

	private static function capture_cache_file_state($dir) {
		$state = array();
		$files = self::collect_cache_files($dir, 'cache');
		foreach ($files as $file) {
			clearstatcache(true, $file);
			$state[$file] = array(
				'mtime' => max(0, (int) ultracache_safe_filemtime($file, 'object_cache_flush_state_filemtime')),
				'size' => max(0, (int) ultracache_safe_filesize($file, 'object_cache_flush_state_filesize')),
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
				return sprintf(
					/* translators: 1: stale cache entry count, 2: singular/plural suffix, 3: recreated cache entry count, 4: singular/plural suffix. */
					__('Object cache flush completed, but %1$d stale entr%2$s remained. %3$d entr%4$s were recreated after flush by live runtime activity.', 'ultracache'),
					$stale_count,
					1 === $stale_count ? 'y' : 'ies',
					$recreated_count,
					1 === $recreated_count ? 'y' : 'ies'
				);
			}
			return sprintf(
				/* translators: 1: stale cache entry count, 2: singular/plural suffix. */
				__('Object cache flush completed, but %1$d stale entr%2$s remained.', 'ultracache'),
				$stale_count,
				1 === $stale_count ? 'y' : 'ies'
			);
		}
		if ($recreated_count > 0) {
			return sprintf(
				/* translators: 1: recreated cache entry count, 2: singular/plural suffix. */
				__('Object cache flushed. No stale entries remained. %1$d entr%2$s were recreated after flush by live runtime activity.', 'ultracache'),
				$recreated_count,
				1 === $recreated_count ? 'y' : 'ies'
			);
		}
		return __('Object cache flushed. No cache entries remained after flush.', 'ultracache');
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
		$root = trailingslashit(ULTRACACHE_OBJECT_CACHE_DIR);
		if ('' !== $root && 0 === strpos($file, $root)) {
			return ltrim(substr($file, strlen($root)), '/\\');
		}
		return $file;
	}

	private static function store_last_flush_report($report) {
		if ((defined('ULTRACACHE_UNINSTALL_IN_PROGRESS') && ULTRACACHE_UNINSTALL_IN_PROGRESS) || !is_array($report)) {
			return;
		}
		update_option('ultracache_object_cache_last_flush_report', $report, false);
	}

	public static function get_stats($full_count = false) {
		$backend_status = self::get_backend_status();
		$selected_backend = isset($backend_status['selected']) ? (string) $backend_status['selected'] : self::get_selected_backend();
		$active_backend = isset($backend_status['active']) ? (string) $backend_status['active'] : $selected_backend;
		$fallback_active = !empty($backend_status['fallbackActive']);
		$fallback_backend = isset($backend_status['fallback']) ? strtolower(trim((string) $backend_status['fallback'])) : '';
		if (!in_array($fallback_backend, array('redis', 'apcu', 'sqlite', 'disk', 'runtime'), true)) {
			$fallback_backend = $fallback_active ? $active_backend : (!empty($backend_status['apcu']['available']) ? 'apcu' : 'runtime');
		}
		$fallback_message = isset($backend_status['fallbackMessage']) ? (string) $backend_status['fallbackMessage'] : '';

		$disk_entries = 0;
		$disk_bytes = 0;
		$redis_entries = 0;
		$redis_bytes = 0;
		$apcu_entries = 0;
		$apcu_bytes = 0;
		$sqlite_entries = 0;
		$sqlite_bytes = 0;
		$partial = false;
		$partial_reason = '';
		$stats_limit = 0;
		$disk_stats_partial = false;
		$disk_stats_partial_reason = '';
		$disk_stats_limit = 0;

		$should_collect_disk_stats = in_array('disk', array($selected_backend, $active_backend, $fallback_backend), true);
		if ($should_collect_disk_stats) {
			$disk_stats = self::collect_disk_cache_stats((bool) $full_count);
			$disk_entries = (int) ($disk_stats['entries'] ?? 0);
			$disk_bytes = (int) ($disk_stats['bytes'] ?? 0);
			$disk_stats_partial = !empty($disk_stats['partial']);
			$disk_stats_partial_reason = (string) ($disk_stats['partialReason'] ?? '');
			$disk_stats_limit = (int) ($disk_stats['limit'] ?? 0);
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

		$should_collect_sqlite_stats = in_array('sqlite', array($selected_backend, $active_backend, $fallback_backend), true);
		if ($should_collect_sqlite_stats) {
			$sqlite_stats = self::collect_sqlite_cache_stats();
			$sqlite_entries = (int) ($sqlite_stats['entries'] ?? 0);
			$sqlite_bytes = (int) ($sqlite_stats['bytes'] ?? 0);
			if ('sqlite' === $active_backend) {
				$partial = !empty($sqlite_stats['partial']);
				$partial_reason = (string) ($sqlite_stats['partialReason'] ?? '');
			}
		}

		$entry_count = 0;
		$bytes = 0;
		if ('redis' === $active_backend) {
			$entry_count = $redis_entries;
			$bytes = $redis_bytes;
		} elseif ('apcu' === $active_backend) {
			$entry_count = $apcu_entries;
			$bytes = $apcu_bytes;
		} elseif ('sqlite' === $active_backend) {
			$entry_count = $sqlite_entries;
			$bytes = $sqlite_bytes;
		} elseif ('disk' === $active_backend) {
			$entry_count = $disk_entries;
			$bytes = $disk_bytes;
			$partial = $disk_stats_partial;
			$partial_reason = $disk_stats_partial_reason;
			$stats_limit = $disk_stats_limit;
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
			'objectCacheSqliteEntries' => $sqlite_entries,
			'objectCacheSqliteSizeBytes' => $sqlite_bytes,
			'objectCacheSqliteSizeHuman' => function_exists('size_format') ? size_format($sqlite_bytes, 2) : (string) $sqlite_bytes,
			'objectCacheSqliteStatsCollected' => (bool) $should_collect_sqlite_stats,
			'objectCacheDiskEntries' => $disk_entries,
			'objectCacheDiskSizeBytes' => $disk_bytes,
			'objectCacheDiskSizeHuman' => function_exists('size_format') ? size_format($disk_bytes, 2) : (string) $disk_bytes,
			'objectCacheDiskStatsCollected' => (bool) $should_collect_disk_stats,
			'objectCacheDiskStatsPartial' => (bool) $disk_stats_partial,
			'objectCacheDiskStatsLimit' => $disk_stats_limit,
			'objectCacheDiskStatsPartialReason' => $disk_stats_partial_reason,
			'objectCacheHits'         => $hits,
			'objectCacheMisses'       => $misses,
			'objectCacheHitRatio'     => $ratio,
			'objectCacheStatsPartial' => (bool) $partial,
			'objectCacheStatsLimit'   => $stats_limit,
			'objectCacheStatsMode'    => $full_count ? 'full' : 'sampled',
			'objectCacheStatsPartialReason' => $partial_reason,
		);
	}

	private static function collect_disk_cache_stats($full_count = false) {
		$default_max_files = (int) apply_filters('ultracache_disk_object_cache_stats_max_files', 5000);
		$default_max_files = max(250, min(50000, $default_max_files));
		$full_max_files = (int) apply_filters('ultracache_disk_object_cache_stats_full_max_files', 50000);
		$full_max_files = max($default_max_files, min(200000, $full_max_files));
		$max_files = $full_count ? $full_max_files : $default_max_files;
		$deadline_seconds = $full_count
			? (float) apply_filters('ultracache_disk_object_cache_stats_full_scan_timeout', 10)
			: (float) apply_filters('ultracache_disk_object_cache_stats_scan_timeout', 1);
		$deadline_seconds = $full_count ? max(1, min(30, $deadline_seconds)) : max(0.1, min(3, $deadline_seconds));

		$stats = array(
			'entries' => 0,
			'bytes' => 0,
			'partial' => false,
			'partialReason' => '',
			'limit' => $max_files,
		);

		if (!is_dir(ULTRACACHE_OBJECT_CACHE_DIR)) {
			return $stats;
		}

		$deadline = microtime(true) + $deadline_seconds;
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(ULTRACACHE_OBJECT_CACHE_DIR, FilesystemIterator::SKIP_DOTS)
			);
			foreach ($iterator as $file) {
				if (microtime(true) > $deadline) {
					$stats['partial'] = true;
					$stats['partialReason'] = 'deadline';
					break;
				}
				if (!$file->isFile() || 'cache' !== strtolower($file->getExtension())) {
					continue;
				}
				$stats['entries']++;
				$size = ultracache_safe_filesize($file->getPathname(), 'object_cache_manager_disk_stats');
				if (false !== $size) {
					$stats['bytes'] += (int) $size;
				}
				if ($max_files > 0 && $stats['entries'] >= $max_files) {
					$stats['partial'] = true;
					$stats['partialReason'] = 'limit';
					break;
				}
			}
		} catch (Exception $e) {
			$stats['partial'] = true;
			$stats['partialReason'] = 'error';
		}

		return $stats;
	}

	private static function collect_sqlite_cache_stats() {
		$stats = array(
			'entries' => 0,
			'bytes' => 0,
			'partial' => false,
			'partialReason' => '',
		);
		if (!file_exists(self::get_sqlite_path())) {
			return $stats;
		}
		$sqlite = self::open_sqlite_database();
		if (!$sqlite instanceof SQLite3) {
			$stats['partial'] = true;
			$stats['partialReason'] = 'SQLite database could not be opened.';
			return $stats;
		}
		try {
			$statement = $sqlite->prepare('SELECT COUNT(*) AS entries, COALESCE(SUM(LENGTH(payload)), 0) AS bytes FROM ultracache_object_cache WHERE expires_at = 0 OR expires_at >= :now');
			$statement->bindValue(':now', time(), SQLITE3_INTEGER);
			$query_result = $statement->execute();
			$row = $query_result->fetchArray(SQLITE3_ASSOC);
			$query_result->finalize();
			$statement->close();
			if (is_array($row)) {
				$stats['entries'] = (int) ($row['entries'] ?? 0);
				$stats['bytes'] = (int) ($row['bytes'] ?? 0);
			}
		} catch (Throwable $e) {
			$stats['partial'] = true;
			$stats['partialReason'] = $e->getMessage();
		}
		$sqlite->close();
		return $stats;
	}

	private static function collect_apcu_namespace_stats($prefix, $full_count = false) {
		$default_max_keys = (int) apply_filters('ultracache_apcu_object_cache_stats_max_keys', 5000);
		$default_max_keys = max(250, min(50000, $default_max_keys));
		$max_keys = $full_count ? 0 : $default_max_keys;
		$deadline_seconds = $full_count
			? (float) apply_filters('ultracache_apcu_object_cache_stats_full_scan_timeout', 15)
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
		$default_max_keys = (int) apply_filters('ultracache_redis_object_cache_stats_max_keys', 5000);
		$default_max_keys = max(250, min(50000, $default_max_keys));
		$max_keys = $full_count ? 0 : $default_max_keys;
		$deadline_seconds = $full_count
			? (float) apply_filters('ultracache_redis_object_cache_stats_full_scan_timeout', 30)
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
		$removed = self::cleanup_expired_directory(ULTRACACHE_OBJECT_CACHE_DIR);
		$removed += self::cleanup_expired_sqlite_entries();
		self::flush_stale_temp_files(ULTRACACHE_OBJECT_CACHE_DIR);
		return $removed;
	}

	private static function cleanup_expired_sqlite_entries() {
		if (!file_exists(self::get_sqlite_path())) {
			return 0;
		}
		$sqlite = self::open_sqlite_database();
		if (!$sqlite instanceof SQLite3) {
			return 0;
		}
		$removed = 0;
		try {
			$statement = $sqlite->prepare('DELETE FROM ultracache_object_cache WHERE cache_id IN (SELECT cache_id FROM ultracache_object_cache WHERE expires_at > 0 AND expires_at < :now LIMIT 500)');
			$statement->bindValue(':now', time(), SQLITE3_INTEGER);
			$query_result = $statement->execute();
			if (false === $query_result) {
				throw new RuntimeException((string) $sqlite->lastErrorMsg());
			}
			if ($query_result instanceof SQLite3Result) {
				$query_result->finalize();
			}
			$statement->close();
			$removed = max(0, (int) $sqlite->changes());
			if ($removed > 0) {
				$sqlite->exec('PRAGMA wal_checkpoint(PASSIVE)');
			}
			self::harden_sqlite_storage_permissions();
		} catch (Throwable $e) {
			$removed = 0;
		}
		$sqlite->close();
		return $removed;
	}

	private static function cleanup_expired_directory($dir) {
		if (!is_dir($dir) || is_link($dir)) {
			return 0;
		}

		$removed = 0;
		$items = ultracache_safe_scandir($dir, 'object_cache_cleanup_expired_directory scandir');
		if (!is_array($items)) {
			return 0;
		}

		foreach ($items as $item) {
			if ('.' === $item || '..' === $item) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if (is_link($path)) {
				continue;
			}
			if (is_dir($path)) {
				$removed += self::cleanup_expired_directory($path);
				$left = ultracache_safe_scandir($path, 'object_cache_cleanup_expired_directory child scandir');
				if (is_array($left) && 2 === count($left)) {
					ultracache_safe_rmdir_empty($path, 'object_cache_cleanup_expired_directory empty child rmdir');
				}
				continue;
			}

			if ('cache' !== strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
				continue;
			}

			$payload = self::read_payload_for_cleanup($path);
			$expired = !is_array($payload) || (!empty($payload['expires_at']) && (int) $payload['expires_at'] < time());
			if ($expired && ultracache_safe_unlink($path)) {
				$removed++;
			}
		}

		return $removed;
	}

	private static function flush_stale_temp_files($dir) {
		if (!is_dir($dir) || is_link($dir)) {
			return;
		}
		$items = ultracache_safe_scandir($dir, 'object_cache_cleanup_tmp scandir');
		if (!is_array($items)) {
			return;
		}
		foreach ($items as $item) {
			if ('.' === $item || '..' === $item) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if (is_link($path)) {
				continue;
			}
			if (is_dir($path)) {
				self::flush_stale_temp_files($path);
				continue;
			}
			if (false === strpos($item, '.tmp-')) {
				continue;
			}
			$mtime = ultracache_safe_filemtime($path, 'object_cache_cleanup_tmp filemtime');
			if (false === $mtime || $mtime < (time() - HOUR_IN_SECONDS)) {
				ultracache_safe_unlink($path);
			}
		}
	}

	private static function read_payload_for_cleanup($path) {
		$raw = ultracache_safe_file_get_contents($path);
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
		$canonical = self::get_dropin_path();
		if ('' === $canonical || wp_normalize_path((string) $file) !== wp_normalize_path($canonical)) {
			return false;
		}

		$contents = ultracache_read_dropin('object-cache.php');
		return (is_string($contents) && false !== strpos($contents, 'UltraCache generated object-cache drop-in'));
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
		if (!is_dir($dir) || is_link($dir)) {
			return;
		}
		$items = ultracache_safe_scandir($dir, 'object_cache_recursive_delete scandir');
		if (!is_array($items)) {
			return;
		}
		foreach ($items as $item) {
			if ('.' === $item || '..' === $item) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if (is_link($path)) {
				continue;
			}
			if (is_dir($path)) {
				self::recursive_delete($path);
			} else {
				ultracache_safe_unlink($path);
			}
		}
		ultracache_safe_rmdir_empty($dir, 'object_cache_recursive_delete empty root rmdir');
	}

	private static function flush_disk_cache_files() {
		foreach (self::collect_cache_files(ULTRACACHE_OBJECT_CACHE_DIR, 'cache') as $file) {
			ultracache_safe_unlink($file, 'object_cache_disk_flush');
		}
		self::remove_empty_cache_directories(ULTRACACHE_OBJECT_CACHE_DIR, false);
	}

	private static function remove_empty_cache_directories($dir, $remove_root = true) {
		if (!is_dir($dir) || is_link($dir)) {
			return;
		}
		$items = ultracache_safe_scandir($dir, 'object_cache_remove_empty_directories scandir');
		if (!is_array($items)) {
			return;
		}
		foreach ($items as $item) {
			if ('.' === $item || '..' === $item) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if (is_dir($path) && !is_link($path)) {
				self::remove_empty_cache_directories($path, true);
			}
		}
		if ($remove_root) {
			ultracache_safe_rmdir_empty($dir, 'object_cache_remove_empty_directories rmdir');
		}
	}


	private static function prune_cache_directory() {
		if (!is_dir(ULTRACACHE_OBJECT_CACHE_DIR)) {
			return;
		}

		$preserve = array(
			'index.php',
			'.htaccess',
			'web.config',
			'object-cache-metrics.json',
			basename(self::get_sqlite_path()),
			basename(self::get_sqlite_path()) . '-wal',
			basename(self::get_sqlite_path()) . '-shm',
		);

		$items = ultracache_safe_scandir(ULTRACACHE_OBJECT_CACHE_DIR, 'object_cache_prune_root scandir');
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
			$path = ULTRACACHE_OBJECT_CACHE_DIR . DIRECTORY_SEPARATOR . $item;
			if (is_link($path)) {
				continue;
			}
			if (is_dir($path)) {
				self::recursive_delete($path);
			} elseif (file_exists($path)) {
				ultracache_safe_unlink($path);
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

	private static function sqlite_supported() {
		return class_exists('SQLite3') && extension_loaded('sqlite3');
	}

	private static function get_sqlite_version() {
		if (!self::sqlite_supported()) {
			return '';
		}
		$version = SQLite3::version();
		return is_array($version) && isset($version['versionString']) ? (string) $version['versionString'] : '';
	}

	private static function get_sqlite_path() {
		$seed = function_exists('ultracache_site_namespace_seed') ? ultracache_site_namespace_seed() : home_url('/');
		$hash = hash('sha256', 'ultracache-sqlite-object-cache|' . (string) $seed);
		return trailingslashit(ULTRACACHE_OBJECT_CACHE_DIR) . '.ht.object-cache-' . substr($hash, 0, 20) . '.sqlite';
	}

	private static function harden_sqlite_storage_permissions() {
		$filesystem = function_exists('ultracache_get_wp_filesystem') ? ultracache_get_wp_filesystem() : null;
		if (!is_object($filesystem) || !method_exists($filesystem, 'chmod')) {
			return;
		}

		$filesystem->chmod(ULTRACACHE_OBJECT_CACHE_DIR, 0700);
		$path = self::get_sqlite_path();
		foreach (array($path, $path . '-wal', $path . '-shm') as $sqlite_file) {
			if (file_exists($sqlite_file)) {
				$filesystem->chmod($sqlite_file, 0600);
			}
		}
	}

	private static function configure_sqlite_database(SQLite3 $sqlite) {
		if (
			!$sqlite->exec('PRAGMA synchronous=NORMAL')
			|| !$sqlite->exec('PRAGMA temp_store=MEMORY')
			|| !$sqlite->exec('PRAGMA secure_delete=ON')
			|| !$sqlite->exec('PRAGMA wal_autocheckpoint=1000')
		) {
			return false;
		}

		$sqlite->querySingle('PRAGMA journal_size_limit=' . self::SQLITE_WAL_TARGET_BYTES);
		return true;
	}

	private static function apply_sqlite_database_limit(SQLite3 $sqlite) {
		$page_size = max(512, (int) $sqlite->querySingle('PRAGMA page_size'));
		$max_pages = max(1.0, floor(((float) self::get_sqlite_database_max_bytes()) / $page_size));
		$page_count = max(0, (int) $sqlite->querySingle('PRAGMA page_count'));

		if ((float) $page_count > $max_pages) {
			if (!$sqlite->exec('DELETE FROM ultracache_object_cache')) {
				return false;
			}
			$sqlite->querySingle('PRAGMA wal_checkpoint(TRUNCATE)', true);
			if (!$sqlite->exec('VACUUM')) {
				return false;
			}
		}

		$applied = (float) $sqlite->querySingle('PRAGMA max_page_count=' . sprintf('%.0f', $max_pages));
		return $applied > 0 && $applied <= ($max_pages + 1.0);
	}

	private static function get_sqlite_public_url() {
		if (!function_exists('ultracache_object_cache_storage_url')) {
			return '';
		}
		return ultracache_object_cache_storage_url(basename(self::get_sqlite_path()));
	}

	public static function get_sqlite_public_exposure_status() {
		$status = array(
			'checked' => false,
			'exposed' => false,
			'httpStatus' => 0,
			'message' => '',
		);

		$url = self::get_sqlite_public_url();
		if (!file_exists(self::get_sqlite_path())) {
			$sqlite = self::open_sqlite_database();
			if ($sqlite instanceof SQLite3) {
				$sqlite->close();
			}
		}
		if ('' === $url || !file_exists(self::get_sqlite_path())) {
			$status['message'] = __('SQLite public exposure test could not resolve the database URL.', 'ultracache');
			return $status;
		}

		$probe_url = add_query_arg('ultracache_sqlite_exposure_probe', wp_generate_password(16, false, false), $url);
		$response = ultracache_safe_loopback_remote_request(
			$probe_url,
			array(
				'method'              => 'GET',
				'timeout'             => 5,
				'redirection'         => 0,
				'decompress'          => false,
				'limit_response_size' => 32,
				'headers'             => array(
					'Accept-Encoding' => 'identity',
					'Cache-Control'   => 'no-cache, no-store',
					'Pragma'          => 'no-cache',
					'Range'           => 'bytes=0-31',
				),
			),
			'sqlite_public_exposure_probe'
		);

		if (is_wp_error($response)) {
			$status['message'] = __('SQLite public exposure test could not complete the loopback request.', 'ultracache');
			return $status;
		}

		$status['checked'] = true;
		$status['httpStatus'] = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		$status['exposed'] = in_array($status['httpStatus'], array(200, 206), true) && 0 === strpos($body, 'SQLite format 3');
		$status['message'] = $status['exposed']
			? __('The SQLite object-cache database is publicly readable and cannot be used safely.', 'ultracache')
			: __('The SQLite object-cache database was not exposed by the web server.', 'ultracache');
		return $status;
	}

	private static function open_sqlite_database() {
		if (!self::sqlite_supported()) {
			return false;
		}
		self::ensure_cache_directory();
		$path = self::get_sqlite_path();
		$root = trailingslashit(wp_normalize_path(ULTRACACHE_OBJECT_CACHE_DIR));
		if (0 !== strpos(wp_normalize_path($path), $root)) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped handler converts SQLite open warnings into exceptions and is restored immediately.
		set_error_handler(
			static function ($severity, $message, $file = null, $line = null) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception context is not rendered as HTML output.
				throw new ErrorException($message, 0, $severity, (string) $file, (int) $line);
			}
		);
		try {
			$sqlite = new SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
			$sqlite->enableExceptions(true);
			$sqlite->busyTimeout(250);
			$journal_mode = strtolower((string) $sqlite->querySingle('PRAGMA journal_mode=WAL'));
			if ('wal' !== $journal_mode) {
				$sqlite->close();
				return false;
			}
			if (!self::configure_sqlite_database($sqlite)) {
				$sqlite->close();
				return false;
			}
			$schema_version = (int) $sqlite->querySingle('PRAGMA user_version');
			if ($schema_version < 1) {
				if (!$sqlite->exec('CREATE TABLE IF NOT EXISTS ultracache_object_cache (cache_id TEXT PRIMARY KEY NOT NULL, cache_scope TEXT NOT NULL, cache_group TEXT NOT NULL, payload BLOB NOT NULL, expires_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL)')) {
					$sqlite->close();
					return false;
				}
				if (!$sqlite->exec('CREATE INDEX IF NOT EXISTS ultracache_object_cache_scope_group ON ultracache_object_cache (cache_scope, cache_group)') || !$sqlite->exec('CREATE INDEX IF NOT EXISTS ultracache_object_cache_expiry ON ultracache_object_cache (expires_at)')) {
					$sqlite->close();
					return false;
				}
				if (!$sqlite->exec('PRAGMA user_version=1')) {
					$sqlite->close();
					return false;
				}
			}
			if (!self::apply_sqlite_database_limit($sqlite)) {
				$sqlite->close();
				return false;
			}
			self::harden_sqlite_storage_permissions();
			return $sqlite;
		} catch (Throwable $e) {
			if (isset($sqlite) && $sqlite instanceof SQLite3) {
				$sqlite->close();
			}
			return false;
		} finally {
			restore_error_handler();
		}
	}

	private static function flush_sqlite_object_cache() {
		$sqlite = self::open_sqlite_database();
		if (!$sqlite instanceof SQLite3) {
			return false;
		}
		$success = false;
		try {
			$success = (bool) $sqlite->exec('DELETE FROM ultracache_object_cache');
			if ($success) {
				$sqlite->exec('PRAGMA wal_checkpoint(TRUNCATE)');
				$sqlite->exec('PRAGMA optimize');
				self::harden_sqlite_storage_permissions();
			}
		} catch (Throwable $e) {
			$success = false;
		}
		$sqlite->close();
		return $success;
	}

	public static function reset_settings_cache() {
		self::$plugin_settings_cache = null;
	}

	private static function get_plugin_settings() {
		if (null !== self::$plugin_settings_cache) {
			return self::$plugin_settings_cache;
		}

		$saved = defined('ULTRACACHE_SETTINGS_KEY') ? get_option(ULTRACACHE_SETTINGS_KEY, array()) : array();
		if (!is_array($saved)) {
			self::$plugin_settings_cache = array();
			return self::$plugin_settings_cache;
		}

        $redis_credentials = function_exists('ultracache_get_redis_credentials')
            ? ultracache_get_redis_credentials()
            : array('username' => '', 'password' => '');
		self::$plugin_settings_cache = array(
			'object_cache_enabled' => !empty($saved['objectCacheEnabled']),
			'object_cache_backend' => !empty($saved['objectCacheBackend']) ? (string) $saved['objectCacheBackend'] : 'redis',
			'object_cache_fallback_backend' => isset($saved['objectCacheFallbackBackend']) ? self::sanitize_fallback_backend($saved['objectCacheFallbackBackend']) : 'apcu',
			'sqlite_database_size_mb' => self::sanitize_sqlite_database_size_mb($saved['sqliteDatabaseSizeMb'] ?? self::SQLITE_DATABASE_DEFAULT_MB),
			'cache_stats_enabled'  => !empty($saved['cacheStatsEnabled']),
			'redis_host'           => !empty($saved['redisHost']) ? (string) $saved['redisHost'] : '127.0.0.1',
			'redis_port'           => isset($saved['redisPort']) ? absint($saved['redisPort']) : 6379,
			'redis_username'       => '' !== trim((string) ($redis_credentials['username'] ?? '')) ? sanitize_text_field((string) $redis_credentials['username']) : (isset($saved['redisUsername']) ? sanitize_text_field((string) $saved['redisUsername']) : ''),
			'redis_password'       => isset($redis_credentials['password']) ? (string) $redis_credentials['password'] : '',
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
		$seed = ultracache_site_namespace_seed() . '|' . (string) self::get_redis_prefix($settings);
		$hash = function_exists('hash') ? hash('sha256', 'ultracache-apcu|' . $seed) : md5('ultracache-apcu|' . $seed);
		return 'ultracache_apcu:' . substr((string) $hash, 0, 16) . ':';
	}

	private static function get_redis_prefix($settings = null) {
		if (is_array($settings) && !empty($settings['redis_prefix'])) {
			$prefix = preg_replace('/[^A-Za-z0-9:_\-]/', '', (string) $settings['redis_prefix']);
			$prefix = trim((string) $prefix, ':');
			if ('' !== $prefix) {
				return $prefix . ':';
			}
		}

		$seed = ultracache_site_namespace_seed();
		$hash = function_exists('hash') ? hash('sha256', 'ultracache-redis|' . $seed) : md5('ultracache-redis|' . $seed);
		return 'ultracache:' . substr((string) $hash, 0, 12) . ':';
	}

	private static function with_redis_error_handler_static($callback, $default = false) {
		try {
			return $callback();
		} catch (Throwable $e) {
			self::$redis_last_error = sanitize_text_field($e->getMessage());
			return $default;
		}
	}

	private static function get_redis_policy_host($host) {
		$host = trim((string) $host);
		$host = preg_replace('#^(?:tcp|tls|ssl)://#i', '', $host);
		return trim((string) $host, " \t\n\r\0\x0B[]");
	}

	private static function validate_redis_socket_target($settings, $context = 'object_cache_redis') {
		$settings = is_array($settings) ? $settings : self::get_plugin_settings();
		$host = !empty($settings['redis_host']) ? (string) $settings['redis_host'] : '127.0.0.1';
		$port = max(1, min(65535, absint($settings['redis_port'] ?? 6379)));
		$policy_host = self::get_redis_policy_host($host);

		if (function_exists('ultracache_is_allowed_redis_socket_target') && !ultracache_is_allowed_redis_socket_target($policy_host, $port, $context)) {
			return new WP_Error(
				'ultracache_unsafe_redis_endpoint',
				sprintf(
					/* translators: 1: Redis host, 2: Redis port. */
					__('Blocked invalid Redis endpoint %1$s:%2$d. Use a valid explicitly configured Redis host and port. External Redis infrastructure is supported when intentionally configured.', 'ultracache'),
					$policy_host,
					$port
				)
			);
		}

		return true;
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
		$username = isset($settings['redis_username']) ? trim((string) $settings['redis_username']) : '';
		$password = isset($settings['redis_password']) ? (string) $settings['redis_password'] : '';
		$database = max(0, absint($settings['redis_database'] ?? 0));
		$persistent = !empty($settings['redis_persistent']);
		$prefix = self::get_redis_prefix($settings);
		$connect_timeout = max(0.05, ((int) ($settings['redis_connect_timeout_ms'] ?? 200)) / 1000);
		$read_timeout = max(0.05, ((int) ($settings['redis_read_timeout_ms'] ?? 200)) / 1000);

		$target_validation = self::validate_redis_socket_target($settings, 'object_cache_redis_connect');
		if (is_wp_error($target_validation)) {
			self::$redis_last_error = $target_validation->get_error_message();
			return null;
		}

		try {
			$redis = new Redis();
			$connected = false;
			if ($persistent) {
				$persistent_id = 'ultracache:' . md5(implode('|', array($host, (string) $port, (string) $database, (string) $prefix)));
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
				$authed = self::with_redis_error_handler_static(function () use ($redis, $username, $password) {
					if ('' !== $username) {
						return $redis->auth(array($username, $password));
					}
					return $redis->auth($password);
				}, false);
				if (!$authed) {
					if ('' === self::$redis_last_error) {
						self::$redis_last_error = '' !== $username ? 'Redis ACL authentication failed.' : 'Redis authentication failed.';
					}
					return null;
				}
			} elseif ('' !== $username) {
				self::$redis_last_error = 'Redis username was provided without a password.';
				return null;
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
			return false;
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
			return true;
		} catch (Throwable $e) {
			return false;
		}
	}

	private static function flush_apcu_namespace($prefix) {
		if (!function_exists('apcu_store') || (function_exists('apcu_enabled') && !apcu_enabled())) {
			return false;
		}
		$prefix = is_string($prefix) ? trim($prefix) : '';
		if ('' === $prefix) {
			return false;
		}
		try {
			return (bool) @apcu_store($prefix . 'namespace', sha1(uniqid('ultracache-apcu-flush-', true)));
		} catch (Throwable $e) {
			return false;
		}
	}
}

