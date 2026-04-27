<?php
/**
 * UltraCache generated object-cache drop-in.
 * Drop-in Build: __UCWP_DROPIN_BUILD__
 * Safe to overwrite.
 * Storage format: redis-apcu-runtime-v4 with explicit disk-only mode and signed-payload-v1 metrics.
 */

defined('ABSPATH') || exit;

if (!function_exists('ucwp_safe_file_get_contents')) {
	function ucwp_safe_file_get_contents($file) {
		return is_readable($file) ? file_get_contents($file) : false;
	}
}

if (!function_exists('ucwp_safe_file_put_contents')) {
	function ucwp_safe_file_put_contents($file, $data, $flags = 0) {
		return file_put_contents($file, $data, $flags);
	}
}

if (!function_exists('ucwp_safe_unlink')) {
	function ucwp_safe_unlink($file) {
		return !file_exists($file) ? true : unlink($file);
	}
}

if (!function_exists('ucwp_safe_rename')) {
	function ucwp_safe_rename($from, $to) {
		return rename($from, $to);
	}
}

if (!function_exists('ucwp_safe_mkdir')) {
	function ucwp_safe_mkdir($dir, $mode = 0755, $recursive = true) {
		$dir = is_string($dir) ? trim($dir) : '';
		if ('' === $dir) {
			return false;
		}
		if (is_dir($dir)) {
			return true;
		}
		return mkdir($dir, $mode, $recursive) || is_dir($dir);
	}
}

if (!function_exists('ucwp_safe_scandir')) {
	function ucwp_safe_scandir($dir) {
		$dir = is_string($dir) ? trim($dir) : '';
		if ('' === $dir || !is_dir($dir) || !is_readable($dir)) {
			return false;
		}
		return scandir($dir);
	}
}

if (!function_exists('ucwp_safe_rmdir')) {
	function ucwp_safe_rmdir($dir) {
		$dir = is_string($dir) ? trim($dir) : '';
		if ('' === $dir) {
			return false;
		}
		if (!file_exists($dir)) {
			return true;
		}
		if (!is_dir($dir)) {
			return false;
		}
		$items = ucwp_safe_scandir($dir);
		if (is_array($items)) {
			foreach ($items as $item) {
				if ('.' === $item || '..' === $item) {
					continue;
				}
				return false;
			}
		}
		clearstatcache(true, $dir);
		return rmdir($dir) || !file_exists($dir);
	}
}

if (!class_exists('WP_Object_Cache')) {
	class WP_Object_Cache {
		private $cache = array();
		private $global_groups = array();
		private $non_persistent_groups = array();
		private $cache_dir = __UCWP_OBJECT_CACHE_DIR__;
		private $blog_id = 1;
		private $metrics_file = '';
		private $stats = array(
			'hits'   => 0,
			'misses' => 0,
		);
		private $selected_backend = __UCWP_SELECTED_BACKEND__;
		private $metrics_enabled = __UCWP_CACHE_STATS_ENABLED__;
		private $active_backend = 'runtime';
		private $redis = null;
		private $redis_enabled = false;
		private $apcu_enabled = false;
		private $apcu_prefix = '';
		private $redis_host = __UCWP_REDIS_HOST__;
		private $redis_port = __UCWP_REDIS_PORT__;
		private $redis_password = __UCWP_REDIS_PASSWORD__;
		private $redis_secret_config = __UCWP_REDIS_SECRET_CONFIG__;
		private $redis_database = __UCWP_REDIS_DATABASE__;
		private $redis_prefix = __UCWP_REDIS_PREFIX__;
		private $redis_use_tls = __UCWP_REDIS_USE_TLS__;
		private $redis_persistent = __UCWP_REDIS_PERSISTENT__;
		private $redis_connect_timeout = __UCWP_REDIS_CONNECT_TIMEOUT__;
		private $redis_read_timeout = __UCWP_REDIS_READ_TIMEOUT__;
		private $redis_error = '';
		private $redis_value_max_items = 64;
		private $redis_value_max_depth = 2;
		private $redis_value_max_string_bytes = 16384;
		private $redis_payload_max_bytes = 32768;
		private $disk_payload_max_bytes = 8388608;
		private $signed_envelope_max_bytes = 12582912;

		public function __construct() {
			$this->blog_id = $this->detect_blog_id();
			$this->metrics_file = rtrim($this->cache_dir, '/\\') . '/object-cache-metrics.json';
			$this->ensure_base_dir();
			$this->load_redis_secret_config();
			$this->bootstrap_backend();
			if ($this->metrics_enabled) {
				register_shutdown_function(array($this, 'persist_metrics'));
			}
		}

		public function get_backend() {
			return (string) $this->active_backend;
		}

		public function get_backend_status() {
			$fallback_active = ('redis' === (string) $this->selected_backend && 'redis' !== (string) $this->active_backend);
			$fallback_backend = $fallback_active ? (string) $this->active_backend : ($this->apcu_available() ? 'apcu' : 'runtime');
			$fallback_reason = '';
			$fallback_message = '';
			if ($fallback_active) {
				$fallback_label = 'apcu' === $fallback_backend ? 'APCu' : ('runtime' === $fallback_backend ? 'runtime-only' : strtoupper($fallback_backend));
				$fallback_reason = '' !== (string) $this->redis_error ? (string) $this->redis_error : 'Redis was selected but did not become active during drop-in bootstrap.';
				$fallback_message = 'Redis selected, ' . $fallback_label . ' fallback active.' . ('' !== $fallback_reason ? ' Redis: ' . $fallback_reason : '');
			}

			return array(
				'selected' => (string) $this->selected_backend,
				'active'   => (string) $this->active_backend,
				'fallback' => (string) $fallback_backend,
				'fallbackActive' => (bool) $fallback_active,
				'fallbackReason' => $fallback_reason,
				'fallbackMessage' => $fallback_message,
				'apcu'     => array(
					'enabled'   => (bool) $this->apcu_enabled,
					'available' => (bool) $this->apcu_available(),
					'fallback_active' => (bool) ($fallback_active && 'apcu' === $fallback_backend),
				),
				'redis'    => array(
					'enabled'  => (bool) $this->redis_enabled,
					'available' => class_exists('Redis'),
					'host'     => (string) $this->redis_host,
					'port'     => (int) $this->redis_port,
					'database' => (int) $this->redis_database,
					'use_tls'  => (bool) $this->redis_use_tls,
					'persistent' => (bool) $this->redis_persistent,
					'error'    => (string) $this->redis_error,
				),
			);
		}


		private function load_redis_secret_config() {
			$config = is_string($this->redis_secret_config) ? trim($this->redis_secret_config) : '';
			if ('' === $config || !is_string($this->cache_dir)) {
				return;
			}

			$base = rtrim((string) $this->cache_dir, '/\\');
			if (!$this->is_path_within_base($config, $base, true)) {
				return;
			}

			if (!is_readable($config)) {
				return;
			}

			$data = require $config;
			if (!is_array($data)) {
				return;
			}

			if (isset($data['redis_password']) && is_scalar($data['redis_password'])) {
				$this->redis_password = (string) $data['redis_password'];
			}
		}

		private function bootstrap_backend() {
			$this->active_backend = 'runtime';
			$this->apcu_prefix = $this->build_apcu_prefix();

			if ('redis' === $this->selected_backend) {
				$this->bootstrap_redis_backend();
				if ($this->is_redis_backend()) {
					return;
				}

				// Redis is preferred, but APCu is the safe local fallback. Do not
				// silently fall back to Disk because it can create thousands of files.
				if ($this->bootstrap_apcu_backend()) {
					return;
				}
				return;
			}

			if ('apcu' === $this->selected_backend) {
				$this->bootstrap_apcu_backend();
				return;
			}

			if ('disk' === $this->selected_backend) {
				$this->active_backend = 'disk';
			}
		}

		private function bootstrap_redis_backend() {
			if (!class_exists('Redis')) {
				$this->redis_error = 'PHP Redis extension not loaded.';
				return false;
			}

			try {
				$redis = new Redis();
				$connection_host = $this->get_redis_connection_host();
				$port = (int) $this->redis_port;
				$timeout = (float) $this->redis_connect_timeout;

				$connected = false;
				if ($this->redis_persistent) {
					$persistent_id = 'ucwp:' . md5(implode('|', array($connection_host, $port, (string) $this->redis_database, (string) $this->redis_prefix)));
					$connected = $this->with_redis_error_handler(function () use ($redis, $connection_host, $port, $timeout, $persistent_id) {
						return $redis->pconnect($connection_host, $port, $timeout, $persistent_id);
					}, false);
				} else {
					$connected = $this->with_redis_error_handler(function () use ($redis, $connection_host, $port, $timeout) {
						return $redis->connect($connection_host, $port, $timeout);
					}, false);
				}

				if (!$connected) {
					if ('' === $this->redis_error) {
						$this->redis_error = 'Could not connect to Redis.';
					}
					return false;
				}

				if (defined('Redis::OPT_SERIALIZER') && defined('Redis::SERIALIZER_NONE')) {
					$this->with_redis_error_handler(function () use ($redis) {
						$redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
						return true;
					}, true);
				}
				if (defined('Redis::OPT_READ_TIMEOUT')) {
					$this->with_redis_error_handler(function () use ($redis) {
						$redis->setOption(Redis::OPT_READ_TIMEOUT, (float) $this->redis_read_timeout);
						return true;
					}, true);
				}

				if ('' !== (string) $this->redis_password) {
					$authed = $this->with_redis_error_handler(function () use ($redis) {
						return $redis->auth($this->redis_password);
					}, false);
					if (!$authed) {
						if ('' === $this->redis_error) {
							$this->redis_error = 'Redis authentication failed.';
						}
						return false;
					}
				}

				if ((int) $this->redis_database > 0) {
					$selected = $this->with_redis_error_handler(function () use ($redis) {
						return $redis->select((int) $this->redis_database);
					}, false);
					if (!$selected) {
						if ('' === $this->redis_error) {
							$this->redis_error = 'Redis database select failed.';
						}
						return false;
					}
				}

				$this->redis = $redis;
				$this->redis_enabled = true;
				$this->active_backend = 'redis';
				return true;
			} catch (Throwable $e) {
				$this->redis_error = $e->getMessage();
				$this->redis = null;
				$this->redis_enabled = false;
				$this->active_backend = 'runtime';
				return false;
			}
		}

		private function apcu_available() {
			if (!function_exists('apcu_fetch') || !function_exists('apcu_store') || !function_exists('apcu_delete') || !function_exists('apcu_add')) {
				return false;
			}
			if (function_exists('apcu_enabled') && !apcu_enabled()) {
				return false;
			}
			return true;
		}

		private function bootstrap_apcu_backend() {
			if (!$this->apcu_available()) {
				$this->apcu_enabled = false;
				return false;
			}
			$this->apcu_enabled = true;
			$this->active_backend = 'apcu';
			return true;
		}

		private function get_redis_connection_host() {
			$host = (string) $this->redis_host;
			if ($this->redis_use_tls && 0 !== strpos($host, 'tls://')) {
				$host = 'tls://' . ltrim($host, '/');
			}
			return $host;
		}

		private function with_redis_error_handler($callback, $default = false) {
			$previous = set_error_handler(function ($severity, $message, $file = null, $line = null) {
				throw new ErrorException($message, 0, $severity, (string) $file, (int) $line);
			});
			try {
				return $callback();
			} catch (Throwable $e) {
				$this->redis_error = $e->getMessage();
				return $default;
			} finally {
				restore_error_handler();
			}
		}

		private function default_metrics() {
			return array(
				'hits' => 0,
				'misses' => 0,
			);
		}

		private function read_metrics() {
			$data = $this->default_metrics();
			if (!$this->metrics_file || !file_exists($this->metrics_file) || !is_readable($this->metrics_file)) {
				return $data;
			}
			if (!$this->is_cache_path($this->metrics_file)) {
				return $data;
			}
			$raw = ucwp_safe_file_get_contents($this->metrics_file);
			if (false === $raw || '' === $raw) {
				return $data;
			}
			$decoded = json_decode($raw, true);
			if (!is_array($decoded)) {
				return $data;
			}
			return array_replace($data, $decoded);
		}

		private function write_metrics($data) {
			if (!$this->metrics_file) {
				return;
			}
			if (!$this->is_cache_path($this->metrics_file)) {
				return;
			}
			$dir = dirname($this->metrics_file);
			if (!file_exists($dir)) {
				ucwp_safe_mkdir($dir, 0755, true);
			}
			ucwp_safe_file_put_contents($this->metrics_file, json_encode($data), LOCK_EX);
		}

		public function persist_metrics() {
			if (!$this->metrics_enabled) {
				return;
			}
			$hits = (int) ($this->stats['hits'] ?? 0);
			$misses = (int) ($this->stats['misses'] ?? 0);
			if ($hits <= 0 && $misses <= 0) {
				return;
			}
			$data = $this->read_metrics();
			$data['hits'] = (int) ($data['hits'] ?? 0) + $hits;
			$data['misses'] = (int) ($data['misses'] ?? 0) + $misses;
			$this->write_metrics($data);
		}

		public function add($key, $data, $group = 'default', $expire = 0) {
			if ($this->_exists($key, $group)) {
				return false;
			}
			return $this->set($key, $data, $group, (int) $expire);
		}

		public function replace($key, $data, $group = 'default', $expire = 0) {
			if (!$this->_exists($key, $group)) {
				return false;
			}
			return $this->set($key, $data, $group, (int) $expire);
		}

		private function should_suspend_cache_addition() {
			return function_exists('wp_suspend_cache_addition') && wp_suspend_cache_addition();
		}

		public function set($key, $data, $group = 'default', $expire = 0) {
			$group = $this->normalize_group($group);
			$key   = $this->normalize_key($key);
			if ($this->should_suspend_cache_addition()) {
				return true;
			}

			$this->set_runtime_value($key, $group, $data);

			if ($this->is_non_persistent_group($group)) {
				return true;
			}

			$payload = $this->build_payload($key, $group, $data, (int) $expire);
			if (!is_array($payload)) {
				return false;
			}

			if ($this->is_redis_backend()) {
				if ($this->write_redis_payload($key, $group, $payload, (int) $expire)) {
					return true;
				}
				$this->delete_redis_payload($key, $group);
			}

			if ($this->is_apcu_backend() && $this->write_apcu_payload($key, $group, $payload, (int) $expire)) {
				return true;
			}

			if ('disk' === $this->active_backend) {
				return $this->write_disk_payload_for_key($key, $group, $payload);
			}

			// Runtime-only fallback: keep the value in memory for this request,
			// but do not create disk object-cache files automatically.
			return true;
		}

		public function get($key, $group = 'default', $force = false, &$found = null) {
			$group = $this->normalize_group($group);
			$key   = $this->normalize_key($key);

			if (!$force && $this->runtime_has($key, $group)) {
				$found = true;
				$this->stats['hits']++;
				return $this->copy_value($this->cache[$group][$key]);
			}

			if ($this->is_non_persistent_group($group)) {
				$found = false;
				$this->stats['misses']++;
				return false;
			}

			$payload = false;
			if ($this->is_redis_backend()) {
				$payload = $this->read_redis_payload($key, $group);
			}
			if ((!is_array($payload) || !array_key_exists('value', $payload)) && $this->is_apcu_backend()) {
				$payload = $this->read_apcu_payload($key, $group);
			}
			if ((!is_array($payload) || !array_key_exists('value', $payload)) && 'disk' === $this->active_backend) {
				$payload = $this->read_disk_payload($key, $group);
			}

			if (!is_array($payload) || !array_key_exists('value', $payload)) {
				$found = false;
				$this->stats['misses']++;
				return false;
			}

			if (!empty($payload['expires_at']) && (int) $payload['expires_at'] < time()) {
				$this->delete($key, $group);
				$found = false;
				$this->stats['misses']++;
				return false;
			}

			$found = true;
			$this->stats['hits']++;
			$this->set_runtime_value($key, $group, $payload['value']);
			return $this->copy_value($payload['value']);
		}

		public function delete($key, $group = 'default', $deprecated = false) {
			$group = $this->normalize_group($group);
			$key   = $this->normalize_key($key);

			if (isset($this->cache[$group]) && array_key_exists($key, $this->cache[$group])) {
				unset($this->cache[$group][$key]);
			}

			if ($this->is_non_persistent_group($group)) {
				return true;
			}

			if ($this->is_redis_backend()) {
				$this->delete_redis_payload($key, $group);
			}
			if ($this->apcu_enabled) {
				$this->delete_apcu_payload($key, $group);
			}
			if ('disk' === $this->active_backend) {
				$this->delete_disk_payload($key, $group);
			}
			return true;
		}

		public function flush() {
			$this->cache = array();
			$this->flush_redis_cache();
			$this->flush_apcu_cache();
			if ('disk' === $this->active_backend) {
				$this->flush_disk_cache();
				$this->ensure_base_dir();
			}
			return true;
		}

		public function flush_runtime() {
			$this->cache = array();
			return true;
		}

		public function flush_group($group) {
			$group = $this->normalize_group($group);
			unset($this->cache[$group]);

			if ($this->is_redis_backend()) {
				$this->flush_redis_group($group);
			}
			if ($this->apcu_enabled) {
				$this->flush_apcu_group($group);
			}
			if ('disk' === $this->active_backend) {
				$path = $this->get_group_dir($group);
				if ($path && is_dir($path)) {
					$this->recursive_delete($path);
				}
			}

			return true;
		}

		public function incr($key, $offset = 1, $group = 'default') {
			$found = false;
			$value = $this->get($key, $group, true, $found);
			if (!$found || !is_numeric($value)) {
				return false;
			}
			$value += (int) $offset;
			$this->set($key, $value, $group);
			return $value;
		}

		public function decr($key, $offset = 1, $group = 'default') {
			$found = false;
			$value = $this->get($key, $group, true, $found);
			if (!$found || !is_numeric($value)) {
				return false;
			}
			$value -= (int) $offset;
			if ($value < 0) {
				$value = 0;
			}
			$this->set($key, $value, $group);
			return $value;
		}

		public function reset() {
			return $this->flush_runtime();
		}

		public function switch_to_blog($blog_id) {
			$this->blog_id = max(1, (int) $blog_id);
			return true;
		}

		public function add_global_groups($groups) {
			foreach ((array) $groups as $group) {
				$group = $this->normalize_group($group);
				if (!in_array($group, $this->global_groups, true)) {
					$this->global_groups[] = $group;
				}
			}
		}

		public function add_non_persistent_groups($groups) {
			foreach ((array) $groups as $group) {
				$group = $this->normalize_group($group);
				if (!in_array($group, $this->non_persistent_groups, true)) {
					$this->non_persistent_groups[] = $group;
				}
			}
		}

		public function get_multiple($keys, $group = 'default', $force = false) {
			$values = array();
			foreach ((array) $keys as $key) {
				$found = false;
				$values[$key] = $this->get($key, $group, $force, $found);
			}
			return $values;
		}

		public function set_multiple($data, $group = 'default', $expire = 0) {
			$results = array();
			foreach ((array) $data as $key => $value) {
				$results[$key] = $this->set($key, $value, $group, $expire);
			}
			return $results;
		}

		public function add_multiple($data, $group = 'default', $expire = 0) {
			$results = array();
			foreach ((array) $data as $key => $value) {
				$results[$key] = $this->add($key, $value, $group, $expire);
			}
			return $results;
		}

		public function delete_multiple($keys, $group = 'default') {
			$results = array();
			foreach ((array) $keys as $key) {
				$results[$key] = $this->delete($key, $group);
			}
			return $results;
		}

		public function stats() {
			echo '<p>UltraCache Object Cache — Backend: ' . esc_html($this->active_backend) . ' — Hits: ' . (int) $this->stats['hits'] . ' Misses: ' . (int) $this->stats['misses'] . '</p>';
		}

		public function supports($feature) {
			return in_array((string) $feature, array(
				'get_multiple',
				'set_multiple',
				'add_multiple',
				'delete_multiple',
				'flush_group',
				'flush_runtime',
			), true);
		}

		public function is_valid_key($key) {
			return !(null === $key || '' === (string) $key);
		}

		private function _exists($key, $group) {
			$found = false;
			$this->get($key, $group, true, $found);
			return $found;
		}

		private function detect_blog_id() {
			if (function_exists('get_current_blog_id')) {
				$blog_id = (int) get_current_blog_id();
				return $blog_id > 0 ? $blog_id : 1;
			}
			global $blog_id;
			return !empty($blog_id) ? (int) $blog_id : 1;
		}

		private function normalize_group($group) {
			$group = (string) $group;
			return '' === $group ? 'default' : $group;
		}

		private function normalize_key($key) {
			if (is_object($key) || is_array($key)) {
				$key = serialize($key);
			}
			return (string) $key;
		}

		private function is_non_persistent_group($group) {
			return in_array($group, $this->non_persistent_groups, true);
		}

		private function is_global_group($group) {
			return in_array($group, $this->global_groups, true);
		}

		private function get_group_blog_id($group) {
			return $this->is_global_group($group) ? 0 : (int) $this->blog_id;
		}

		private function get_scope_dir($group) {
			$scope = $this->is_global_group($group) ? 'global' : 'blog-' . (int) $this->blog_id;
			return rtrim($this->cache_dir, '/\\') . '/' . $scope;
		}

		private function get_group_dir($group) {
			$group_slug = preg_replace('/[^A-Za-z0-9_.-]/', '-', $group);
			$group_slug = trim((string) $group_slug, '-');
			if ('' === $group_slug) {
				$group_slug = 'default';
			}
			return $this->get_scope_dir($group) . '/' . $group_slug;
		}

		private function get_file_path($key, $group) {
			$dir = $this->get_group_dir($group);
			if (!$this->is_cache_path($dir)) {
				return false;
			}
			if (!is_dir($dir) && !ucwp_safe_mkdir($dir, 0755, true) && !is_dir($dir)) {
				return false;
			}
			return $dir . '/' . sha1($key) . '.cache';
		}

		private function normalize_cache_dir($dir) {
			$dir = is_string($dir) ? trim($dir) : '';
			if ('' === $dir) {
				return '';
			}
			$normalized = str_replace('\\', '/', $dir);
			$normalized = preg_replace('#/+#', '/', $normalized);
			return rtrim((string) $normalized, '/');
		}

		private function resolve_path_for_comparison($path, $must_exist) {
			$path = is_string($path) ? trim($path) : '';
			if ('' === $path) {
				return '';
			}

			if (function_exists('realpath')) {
				$real = @realpath($path);
				if (is_string($real) && '' !== $real) {
					return $this->normalize_cache_dir($real);
				}
				if (!$must_exist) {
					$parent = dirname($path);
					$leaf = basename($path);
					$real_parent = @realpath($parent);
					if (is_string($real_parent) && '' !== $real_parent && '' !== $leaf && '.' !== $leaf && '..' !== $leaf) {
						return $this->normalize_cache_dir(rtrim($real_parent, '/\\') . DIRECTORY_SEPARATOR . $leaf);
					}
				}
				if ($must_exist) {
					return '';
				}
			}

			return $this->normalize_cache_dir($path);
		}

		private function is_path_within_base($path, $base, $must_exist) {
			$resolved_path = $this->resolve_path_for_comparison($path, (bool) $must_exist);
			$resolved_base = $this->resolve_path_for_comparison($base, true);
			if ('' === $resolved_path || '' === $resolved_base) {
				return false;
			}
			return $resolved_path === $resolved_base || 0 === strpos($resolved_path, $resolved_base . '/');
		}

		private function is_cache_path($path) {
			$path = is_string($path) ? trim($path) : '';
			if ('' === $path) {
				return false;
			}
			return $this->is_path_within_base($path, $this->cache_dir, false);
		}

		private function ensure_base_dir() {
			if (!is_dir($this->cache_dir)) {
				ucwp_safe_mkdir($this->cache_dir, 0755, true);
			}
			$index = rtrim($this->cache_dir, '/\\') . '/index.php';
			if (!file_exists($index)) {
				ucwp_safe_file_put_contents($index, "<?php\n// Silence is golden.\n");
			}
		}

		private function set_runtime_value($key, $group, $value) {
			if (!isset($this->cache[$group]) || !is_array($this->cache[$group])) {
				$this->cache[$group] = array();
			}
			$this->cache[$group][$key] = $this->copy_value($value);
		}


		private function runtime_has($key, $group) {
			return isset($this->cache[$group]) && array_key_exists($key, $this->cache[$group]);
		}

		private function copy_value($value) {
			return is_object($value) ? clone $value : $value;
		}

		private function is_redis_backend() {
			return 'redis' === $this->active_backend && $this->redis_enabled && $this->redis instanceof Redis;
		}

		private function is_apcu_backend() {
			return 'apcu' === $this->active_backend && $this->apcu_enabled && $this->apcu_available();
		}

		private function build_apcu_prefix() {
			$seed = implode('|', array(
				defined('DB_NAME') ? DB_NAME : '',
				defined('DB_USER') ? DB_USER : '',
				defined('ABSPATH') ? ABSPATH : '',
				defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '',
				(string) $this->redis_prefix,
			));
			$hash = function_exists('hash') ? hash('sha256', 'ucwp-apcu|' . $seed) : md5('ucwp-apcu|' . $seed);
			return 'ucwp_apcu:' . substr((string) $hash, 0, 16) . ':';
		}

		private function get_apcu_namespace() {
			if (!$this->apcu_available()) {
				return '';
			}
			$key = $this->apcu_prefix . 'namespace';
			$success = false;
			$namespace = apcu_fetch($key, $success);
			if ($success && is_string($namespace) && '' !== $namespace) {
				return $namespace;
			}
			$namespace = sha1(uniqid('ucwp-apcu-', true));
			@apcu_add($key, $namespace);
			$stored = apcu_fetch($key, $success);
			return ($success && is_string($stored) && '' !== $stored) ? $stored : $namespace;
		}

		private function get_apcu_group_version($group) {
			if (!$this->apcu_available()) {
				return '1';
			}
			$key = $this->apcu_prefix . 'group_version:' . $this->get_redis_scope($group) . ':' . $this->get_redis_group_slug($group);
			$success = false;
			$version = apcu_fetch($key, $success);
			if ($success && is_scalar($version) && '' !== (string) $version) {
				return (string) $version;
			}
			@apcu_add($key, '1');
			return '1';
		}

		private function get_apcu_key($key, $group) {
			return $this->apcu_prefix . $this->get_apcu_namespace() . ':' . $this->get_apcu_group_version($group) . ':' . $this->get_redis_scope($group) . ':' . $this->get_redis_group_slug($group) . ':' . sha1($key);
		}

		private function write_apcu_payload($key, $group, $payload, $expire) {
			if (!$this->is_apcu_backend() || !is_array($payload)) {
				return false;
			}
			$value = $payload['value'] ?? null;
			if ($this->payload_contains_complex_types($value)) {
				return false;
			}
			try {
				$serialized = serialize($payload);
			} catch (Throwable $e) {
				return false;
			}
			if (!is_string($serialized) || '' === $serialized || strlen($serialized) > $this->redis_payload_max_bytes) {
				return false;
			}
			$envelope = $this->build_signed_envelope($serialized);
			if (!is_array($envelope)) {
				return false;
			}
			$data = serialize($envelope);
			$ttl = max(0, (int) $expire);
			return (bool) @apcu_store($this->get_apcu_key($key, $group), $data, $ttl);
		}

		private function read_apcu_payload($key, $group) {
			if (!$this->is_apcu_backend()) {
				return false;
			}
			$success = false;
			$data = apcu_fetch($this->get_apcu_key($key, $group), $success);
			if (!$success || !is_string($data) || '' === $data) {
				return false;
			}
			$envelope = $this->deserialize_signed_envelope($data, $this->redis_payload_max_bytes * 2);
			if (!is_array($envelope)) {
				return false;
			}
			$payload_serialized = $this->decode_envelope_payload($envelope, $this->redis_payload_max_bytes);
			if (false === $payload_serialized || !$this->verify_signature($payload_serialized, (string) $envelope['sig'])) {
				return false;
			}
			$payload = $this->deserialize_cache_payload($payload_serialized, true);
			return (is_array($payload) && $this->is_valid_cache_payload($payload, $key, $group)) ? $payload : false;
		}

		private function delete_apcu_payload($key, $group) {
			if (!$this->apcu_enabled || !$this->apcu_available()) {
				return true;
			}
			@apcu_delete($this->get_apcu_key($key, $group));
			return true;
		}

		private function flush_apcu_group($group) {
			if (!$this->apcu_enabled || !$this->apcu_available()) {
				return;
			}
			$key = $this->apcu_prefix . 'group_version:' . $this->get_redis_scope($group) . ':' . $this->get_redis_group_slug($group);
			@apcu_store($key, sha1(uniqid('ucwp-apcu-group-', true)));
		}

		private function flush_apcu_cache() {
			if (!$this->apcu_enabled || !$this->apcu_available()) {
				return;
			}
			@apcu_store($this->apcu_prefix . 'namespace', sha1(uniqid('ucwp-apcu-flush-', true)));
		}

		private function get_redis_scope($group) {
			return $this->is_global_group($group) ? 'global' : 'blog-' . (int) $this->blog_id;
		}

		private function get_redis_group_slug($group) {
			$group_slug = preg_replace('/[^A-Za-z0-9_.-]/', '-', $group);
			$group_slug = trim((string) $group_slug, '-');
			return '' !== $group_slug ? $group_slug : 'default';
		}

		private function get_redis_key($key, $group) {
			return $this->redis_prefix . $this->get_redis_scope($group) . ':' . $this->get_redis_group_slug($group) . ':' . sha1($key);
		}

		private function get_redis_group_match($group) {
			return $this->redis_prefix . $this->get_redis_scope($group) . ':' . $this->get_redis_group_slug($group) . ':*';
		}

		private function get_redis_prefix_match() {
			return $this->redis_prefix . '*';
		}

		private function build_payload($key, $group, $data, $expire) {
			$expires_at = ((int) $expire > 0) ? (time() + (int) $expire) : 0;
			return array(
				'key'        => $key,
				'group'      => $group,
				'blog_id'    => $this->get_group_blog_id($group),
				'expires_at' => $expires_at,
				'value'      => $data,
			);
		}

		private function payload_contains_complex_types($value, $depth = 0) {
			// WordPress object cache values may legitimately be arrays or objects.
			// Reject only payloads that cannot be safely serialized or that are extremely deep.
			if ($depth > 32) {
				return true;
			}

			if (is_resource($value)) {
				return true;
			}

			if (is_object($value)) {
				return ($value instanceof Closure);
			}

			if (!is_array($value)) {
				return false;
			}

			foreach ($value as $item) {
				if ($this->payload_contains_complex_types($item, $depth + 1)) {
					return true;
				}
			}

			return false;
		}


		private function write_disk_payload_for_key($key, $group, $payload) {
			$path = $this->get_file_path($key, $group);
			if (!$path) {
				return false;
			}
			return $this->write_file_payload($path, $payload);
		}

		private function delete_disk_payload($key, $group) {
			$path = $this->get_file_path($key, $group);
			if (!$path || !$this->is_cache_path($path) || !file_exists($path)) {
				return true;
			}
			return ucwp_safe_unlink($path);
		}

		private function write_redis_payload($key, $group, $payload, $expire) {
			if (!$this->is_redis_backend() || !is_array($payload)) {
				return false;
			}

			$value = $payload['value'] ?? null;
			if ($this->payload_contains_complex_types($value)) {
				$this->redis_error = 'Redis payload rejected: unsupported resource/closure or excessive nesting.';
				return false;
			}

			$redis_key = $this->get_redis_key($key, $group);

			try {
				$serialized = serialize($payload);
				if (!is_string($serialized) || '' === $serialized) {
					return false;
				}

				$envelope = $this->build_signed_envelope($serialized);
				if (!is_array($envelope)) {
					$this->redis_error = 'Redis payload rejected: envelope signing failed.';
					return false;
				}
				$data = serialize($envelope);
				if (!is_string($data) || '' === $data) {
					return false;
				}

				if (strlen($data) > $this->redis_payload_max_bytes) {
					$this->redis_error = 'Redis payload rejected: value too large.';
					return false;
				}

				if ((int) $expire > 0) {
					$stored = (bool) $this->with_redis_error_handler(function () use ($redis_key, $expire, $data) {
						return $this->redis->setEx($redis_key, (int) $expire, $data);
					}, false);
					if ($stored) {
						$this->redis_error = '';
					}
					return $stored;
				}
				$stored = (bool) $this->with_redis_error_handler(function () use ($redis_key, $data) {
					return $this->redis->set($redis_key, $data);
				}, false);
				if ($stored) {
					$this->redis_error = '';
				}
				return $stored;
			} catch (Throwable $e) {
				$this->redis_error = $e->getMessage();
				return false;
			}
		}

		private function read_redis_payload($key, $group) {
			if (!$this->is_redis_backend()) {
				return false;
			}

			$serialized = $this->with_redis_error_handler(function () use ($key, $group) {
				return $this->redis->get($this->get_redis_key($key, $group));
			}, false);

			if (!is_string($serialized) || '' === $serialized) {
				return false;
			}

			if (strlen($serialized) > ($this->redis_payload_max_bytes * 2)) {
				$this->redis_error = 'Redis payload skipped: serialized value too large.';
				return false;
			}

			$envelope = $this->deserialize_signed_envelope($serialized, $this->redis_payload_max_bytes * 2);
			if (!is_array($envelope)) {
				return false;
			}

			$payload_serialized = $this->decode_envelope_payload($envelope, $this->redis_payload_max_bytes);
			if (false === $payload_serialized || !$this->verify_signature($payload_serialized, (string) $envelope['sig'])) {
				return false;
			}

			$payload = $this->deserialize_cache_payload($payload_serialized, true);
			if (!is_array($payload) || !$this->is_valid_cache_payload($payload, $key, $group)) {
				return false;
			}

			return $payload;
		}

		private function delete_redis_payload($key, $group) {
			if (!$this->is_redis_backend()) {
				return false;
			}

			$this->with_redis_error_handler(function () use ($key, $group) {
				$this->redis->del($this->get_redis_key($key, $group));
				return true;
			}, true);
			return true;
		}

		private function flush_redis_group($group) {
			if (!$this->is_redis_backend()) {
				return;
			}
			$this->delete_redis_keys_by_pattern($this->get_redis_group_match($group));
		}

		private function flush_redis_cache() {
			if (!$this->is_redis_backend()) {
				return;
			}
			$this->delete_redis_keys_by_pattern($this->get_redis_prefix_match());
		}

		private function delete_redis_keys_by_pattern($pattern) {
			if (!$this->is_redis_backend()) {
				return;
			}

			$iterator = null;
			while (false !== ($keys = $this->with_redis_error_handler(function () use (&$iterator, $pattern) {
				return $this->redis->scan($iterator, $pattern, 500);
			}, false))) {
				if (!empty($keys)) {
					$this->with_redis_error_handler(function () use ($keys) {
						$this->redis->del($keys);
						return true;
					}, true);
				}
				if (0 === $iterator) {
					break;
				}
			}
		}

		private function write_file_payload($path, $payload) {
			if (!is_string($path) || '' === trim($path) || !$this->is_cache_path($path)) {
				return false;
			}
			$dir = dirname($path);
			if (!is_dir($dir) && !ucwp_safe_mkdir($dir, 0755, true) && !is_dir($dir)) {
				return false;
			}
			$tmp = $path . '.tmp-' . uniqid('', true);
			$serialized = serialize($payload);
			$envelope = $this->build_signed_envelope($serialized);
			if (!is_array($envelope)) {
				return false;
			}
			$data = serialize($envelope);
			$result = ucwp_safe_file_put_contents($tmp, $data, LOCK_EX);
			if (false === $result) {
				ucwp_safe_unlink($tmp);
				return false;
			}
			if (!ucwp_safe_rename($tmp, $path)) {
				ucwp_safe_unlink($tmp);
				return false;
			}
			return true;
		}

		private function read_disk_payload($key, $group) {
			$path = $this->get_file_path($key, $group);
			if (!$path || !file_exists($path)) {
				return false;
			}
			return $this->read_payload($path, $key, $group);
		}

		private function read_payload($path, $key, $group) {
			if (!is_string($path) || '' === trim($path) || !$this->is_cache_path($path)) {
				return false;
			}
			$data = ucwp_safe_file_get_contents($path);
			if (false === $data || '' === $data) {
				return false;
			}
			$envelope = $this->deserialize_signed_envelope($data, $this->signed_envelope_max_bytes);
			if (!is_array($envelope)) {
				return false;
			}
			$serialized = $this->decode_envelope_payload($envelope, $this->disk_payload_max_bytes);
			if (false === $serialized || !$this->verify_signature($serialized, (string) $envelope['sig'])) {
				return false;
			}
			$payload = $this->deserialize_cache_payload($serialized, true);
			if (!is_array($payload) || !$this->is_valid_cache_payload($payload, $key, $group)) {
				return false;
			}
			return $payload;
		}

		private function deserialize_cache_payload($serialized, $allow_objects) {
			if (!is_string($serialized) || '' === $serialized) {
				return false;
			}

			if (!$allow_objects && $this->serialized_payload_has_disallowed_object_tokens($serialized)) {
				return false;
			}

			try {
				if ($allow_objects) {
					$payload = @unserialize($serialized);
				} else {
					$payload = @unserialize($serialized, array('allowed_classes' => false));
				}
			} catch (Throwable $e) {
				return false;
			}

			return is_array($payload) ? $payload : false;
		}

		private function serialized_payload_has_disallowed_object_tokens($serialized) {
			if (!is_string($serialized) || '' === $serialized) {
				return true;
			}

			if (preg_match('/(^|[;{}])(C|O|o|O\+):\d+:/', $serialized)) {
				return true;
			}

			return false;
		}

		private function is_valid_cache_payload($payload, $key, $group) {
			if (!is_array($payload)) {
				return false;
			}

			$required_keys = array('key', 'group', 'blog_id', 'expires_at', 'value');
			if (count($payload) !== count($required_keys)) {
				return false;
			}

			foreach ($required_keys as $required_key) {
				if (!array_key_exists($required_key, $payload)) {
					return false;
				}
			}

			if (!is_string($payload['key']) || !is_string($payload['group'])) {
				return false;
			}

			if (!is_int($payload['blog_id']) && !ctype_digit((string) $payload['blog_id'])) {
				return false;
			}

			if (!is_int($payload['expires_at']) && !ctype_digit((string) $payload['expires_at'])) {
				return false;
			}

			$expected_key = $this->normalize_key($key);
			$expected_group = $this->normalize_group($group);
			$expected_blog_id = $this->get_group_blog_id($expected_group);

			if ($payload['key'] !== $expected_key || $payload['group'] !== $expected_group) {
				return false;
			}

			if ((int) $payload['blog_id'] !== (int) $expected_blog_id) {
				return false;
			}

			if ((int) $payload['expires_at'] < 0) {
				return false;
			}

			return true;
		}

		private function build_signed_envelope($serialized) {
			$signature = $this->sign_payload($serialized);
			if ('' === $signature) {
				return false;
			}
			return array(
				'v'       => 1,
				'payload' => base64_encode($serialized),
				'sig'     => $signature,
			);
		}

		private function deserialize_signed_envelope($data, $max_bytes) {
			if (!is_string($data) || '' === $data) {
				return false;
			}
			$max_bytes = (int) $max_bytes;
			if ($max_bytes > 0 && strlen($data) > $max_bytes) {
				return false;
			}
			try {
				$envelope = @unserialize($data, array('allowed_classes' => false));
			} catch (Throwable $e) {
				return false;
			}
			return $this->is_valid_signed_envelope($envelope) ? $envelope : false;
		}

		private function is_valid_signed_envelope($envelope) {
			if (!is_array($envelope)) {
				return false;
			}
			if (!isset($envelope['v'], $envelope['payload'], $envelope['sig'])) {
				return false;
			}
			if (count($envelope) !== 3 || 1 !== (int) $envelope['v']) {
				return false;
			}
			if (!is_string($envelope['payload']) || '' === $envelope['payload']) {
				return false;
			}
			if (!is_string($envelope['sig']) || !preg_match('/^[a-f0-9]{64}$/i', $envelope['sig'])) {
				return false;
			}
			return true;
		}

		private function decode_envelope_payload($envelope, $max_bytes) {
			if (!$this->is_valid_signed_envelope($envelope)) {
				return false;
			}
			$payload = (string) $envelope['payload'];
			$max_bytes = (int) $max_bytes;
			if ($max_bytes > 0) {
				$max_base64_length = (int) ceil($max_bytes * 4 / 3) + 8;
				if (strlen($payload) > $max_base64_length) {
					return false;
				}
			}
			$serialized = base64_decode($payload, true);
			if (false === $serialized || !is_string($serialized) || '' === $serialized) {
				return false;
			}
			if ($max_bytes > 0 && strlen($serialized) > $max_bytes) {
				return false;
			}
			return $serialized;
		}

		private function verify_signature($serialized, $signature) {
			$expected = $this->sign_payload($serialized);
			if ('' === $expected || '' === (string) $signature) {
				return false;
			}
			if (function_exists('hash_equals')) {
				return hash_equals($expected, (string) $signature);
			}
			return $expected === (string) $signature;
		}

		private function sign_payload($serialized) {
			if (!is_string($serialized) || '' === $serialized || !function_exists('hash_hmac')) {
				return '';
			}
			$key = $this->get_integrity_key();
			if ('' === $key) {
				return '';
			}
			return hash_hmac('sha256', $serialized, $key);
		}

		private function get_integrity_key() {
			static $key = null;
			if (null !== $key) {
				return $key;
			}
			$material = array(
				defined('AUTH_KEY') ? AUTH_KEY : '',
				defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '',
				defined('LOGGED_IN_KEY') ? LOGGED_IN_KEY : '',
				defined('NONCE_KEY') ? NONCE_KEY : '',
				defined('AUTH_SALT') ? AUTH_SALT : '',
				defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '',
				defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : '',
				defined('NONCE_SALT') ? NONCE_SALT : '',
				defined('DB_NAME') ? DB_NAME : '',
				defined('DB_USER') ? DB_USER : '',
				defined('ABSPATH') ? ABSPATH : '',
				$this->cache_dir,
			);
			$seed = implode('|', $material);
			if ('' === trim(str_replace('|', '', $seed))) {
				$key = md5('ultracache-object-cache|' . $this->cache_dir);
				return $key;
			}
			$key = function_exists('hash')
				? hash('sha256', 'ultracache-object-cache|' . $seed)
				: md5('ultracache-object-cache|' . $seed);
			return (string) $key;
		}

		private function flush_disk_cache() {
			if (!is_dir($this->cache_dir) || !$this->is_cache_path($this->cache_dir)) {
				return;
			}
			$items = ucwp_safe_scandir($this->cache_dir);
			if (!is_array($items)) {
				return;
			}
			foreach ($items as $item) {
				if ('.' === $item || '..' === $item || $this->should_preserve_cache_root_entry($item)) {
					continue;
				}
				$path = $this->cache_dir . DIRECTORY_SEPARATOR . $item;
				if (!$this->is_cache_path($path)) {
					continue;
				}
				if (is_dir($path)) {
					$this->recursive_delete($path, true);
				} else {
					ucwp_safe_unlink($path);
				}
			}
		}

		private function should_preserve_cache_root_entry($item) {
			$item = is_string($item) ? trim($item) : '';
			if ('' === $item) {
				return false;
			}
			$preserve = array('index.php');
			if (is_string($this->redis_secret_config) && '' !== trim($this->redis_secret_config)) {
				$preserve[] = basename($this->redis_secret_config);
			}
			return in_array($item, array_values(array_unique(array_filter($preserve))), true);
		}

		private function recursive_delete($dir, $remove_root = true) {
			if (!is_string($dir) || '' === trim($dir) || !is_dir($dir) || !$this->is_cache_path($dir)) {
				return;
			}
			$items = ucwp_safe_scandir($dir);
			if (!is_array($items)) {
				return;
			}
			foreach ($items as $item) {
				if ('.' === $item || '..' === $item) {
					continue;
				}
				$path = $dir . DIRECTORY_SEPARATOR . $item;
				if (!$this->is_cache_path($path)) {
					continue;
				}
				if (is_dir($path)) {
					$this->recursive_delete($path, true);
				} else {
					ucwp_safe_unlink($path);
				}
			}
			$remaining = ucwp_safe_scandir($dir);
			if (is_array($remaining)) {
				foreach ($remaining as $item) {
					if ('.' === $item || '..' === $item) {
						continue;
					}
					$path = $dir . DIRECTORY_SEPARATOR . $item;
					if ($this->is_cache_path($path) && is_file($path)) {
						ucwp_safe_unlink($path);
					}
				}
			}
			if ($remove_root) {
				ucwp_safe_rmdir($dir);
			}
		}
	}
}

if (!function_exists('wp_cache_init')) {
	function wp_cache_init() {
		$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	}
}

if (!function_exists('wp_cache_add')) {
	function wp_cache_add($key, $data, $group = '', $expire = 0) {
		global $wp_object_cache;
		return $wp_object_cache->add($key, $data, $group, (int) $expire);
	}
}

if (!function_exists('wp_cache_set')) {
	function wp_cache_set($key, $data, $group = '', $expire = 0) {
		global $wp_object_cache;
		return $wp_object_cache->set($key, $data, $group, (int) $expire);
	}
}

if (!function_exists('wp_cache_replace')) {
	function wp_cache_replace($key, $data, $group = '', $expire = 0) {
		global $wp_object_cache;
		return $wp_object_cache->replace($key, $data, $group, (int) $expire);
	}
}

if (!function_exists('wp_cache_get')) {
	function wp_cache_get($key, $group = '', $force = false, &$found = null) {
		global $wp_object_cache;
		return $wp_object_cache->get($key, $group, $force, $found);
	}
}

if (!function_exists('wp_cache_delete')) {
	function wp_cache_delete($key, $group = '') {
		global $wp_object_cache;
		return $wp_object_cache->delete($key, $group);
	}
}

if (!function_exists('wp_cache_flush')) {
	function wp_cache_flush() {
		global $wp_object_cache;
		return $wp_object_cache->flush();
	}
}

if (!function_exists('wp_cache_flush_runtime')) {
	function wp_cache_flush_runtime() {
		global $wp_object_cache;
		return method_exists($wp_object_cache, 'flush_runtime') ? $wp_object_cache->flush_runtime() : false;
	}
}

if (!function_exists('wp_cache_flush_group')) {
	function wp_cache_flush_group($group) {
		global $wp_object_cache;
		return method_exists($wp_object_cache, 'flush_group') ? $wp_object_cache->flush_group($group) : false;
	}
}

if (!function_exists('wp_cache_incr')) {
	function wp_cache_incr($key, $offset = 1, $group = '') {
		global $wp_object_cache;
		return $wp_object_cache->incr($key, $offset, $group);
	}
}

if (!function_exists('wp_cache_decr')) {
	function wp_cache_decr($key, $offset = 1, $group = '') {
		global $wp_object_cache;
		return $wp_object_cache->decr($key, $offset, $group);
	}
}

if (!function_exists('wp_cache_add_global_groups')) {
	function wp_cache_add_global_groups($groups) {
		global $wp_object_cache;
		$wp_object_cache->add_global_groups($groups);
	}
}

if (!function_exists('wp_cache_add_non_persistent_groups')) {
	function wp_cache_add_non_persistent_groups($groups) {
		global $wp_object_cache;
		$wp_object_cache->add_non_persistent_groups($groups);
	}
}

if (!function_exists('wp_cache_switch_to_blog')) {
	function wp_cache_switch_to_blog($blog_id) {
		global $wp_object_cache;
		return method_exists($wp_object_cache, 'switch_to_blog') ? $wp_object_cache->switch_to_blog($blog_id) : false;
	}
}

if (!function_exists('wp_cache_reset')) {
	function wp_cache_reset() {
		global $wp_object_cache;
		return method_exists($wp_object_cache, 'reset') ? $wp_object_cache->reset() : false;
	}
}

if (!function_exists('wp_cache_close')) {
	function wp_cache_close() {
		return true;
	}
}

if (!function_exists('wp_cache_get_multiple')) {
	function wp_cache_get_multiple($keys, $group = '', $force = false) {
		global $wp_object_cache;
		return method_exists($wp_object_cache, 'get_multiple') ? $wp_object_cache->get_multiple($keys, $group, $force) : array();
	}
}

if (!function_exists('wp_cache_set_multiple')) {
	function wp_cache_set_multiple($data, $group = '', $expire = 0) {
		global $wp_object_cache;
		return method_exists($wp_object_cache, 'set_multiple') ? $wp_object_cache->set_multiple($data, $group, $expire) : array();
	}
}

if (!function_exists('wp_cache_add_multiple')) {
	function wp_cache_add_multiple($data, $group = '', $expire = 0) {
		global $wp_object_cache;
		return method_exists($wp_object_cache, 'add_multiple') ? $wp_object_cache->add_multiple($data, $group, $expire) : array();
	}
}

if (!function_exists('wp_cache_delete_multiple')) {
	function wp_cache_delete_multiple($keys, $group = '') {
		global $wp_object_cache;
		return method_exists($wp_object_cache, 'delete_multiple') ? $wp_object_cache->delete_multiple($keys, $group) : array();
	}
}

if (!function_exists('wp_cache_supports')) {
	function wp_cache_supports($feature) {
		global $wp_object_cache;
		return method_exists($wp_object_cache, 'supports') ? $wp_object_cache->supports($feature) : false;
	}
}
