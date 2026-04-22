<?php
/**
 * UltraCache generated object-cache drop-in.
 * Drop-in Build: __UCWP_DROPIN_BUILD__
 * Safe to overwrite.
 * Storage format: hybrid-disk-redis-v3 with signed-payload-v1 metrics.
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
		if (is_dir($dir)) {
			return true;
		}
		return mkdir($dir, $mode, $recursive) || is_dir($dir);
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
		private $active_backend = 'disk';
		private $redis = null;
		private $redis_enabled = false;
		private $redis_host = __UCWP_REDIS_HOST__;
		private $redis_port = __UCWP_REDIS_PORT__;
		private $redis_password = __UCWP_REDIS_PASSWORD__;
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

		public function __construct() {
			$this->blog_id = $this->detect_blog_id();
			$this->metrics_file = rtrim($this->cache_dir, '/\\') . '/object-cache-metrics.json';
			$this->ensure_base_dir();
			$this->bootstrap_backend();
			register_shutdown_function(array($this, 'persist_metrics'));
		}

		public function get_backend() {
			return (string) $this->active_backend;
		}

		public function get_backend_status() {
			return array(
				'selected' => (string) $this->selected_backend,
				'active'   => (string) $this->active_backend,
				'fallback' => 'disk',
				'redis'    => array(
					'enabled'  => (bool) $this->redis_enabled,
					'host'     => (string) $this->redis_host,
					'port'     => (int) $this->redis_port,
					'database' => (int) $this->redis_database,
					'use_tls'  => (bool) $this->redis_use_tls,
					'persistent' => (bool) $this->redis_persistent,
					'error'    => (string) $this->redis_error,
				),
			);
		}

		private function bootstrap_backend() {
			$this->active_backend = 'disk';

			if ('redis' !== $this->selected_backend) {
				return;
			}

			if (!class_exists('Redis')) {
				$this->redis_error = 'PHP Redis extension not loaded.';
				return;
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
					return;
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
						return;
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
						return;
					}
				}

				$this->redis = $redis;
				$this->redis_enabled = true;
				$this->active_backend = 'redis';
			} catch (Throwable $e) {
				$this->redis_error = $e->getMessage();
				$this->redis = null;
				$this->redis_enabled = false;
				$this->active_backend = 'disk';
			}
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
			$dir = dirname($this->metrics_file);
			if (!file_exists($dir)) {
				ucwp_safe_mkdir($dir, 0755, true);
			}
			ucwp_safe_file_put_contents($this->metrics_file, json_encode($data), LOCK_EX);
		}

		public function persist_metrics() {
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

		public function set($key, $data, $group = 'default', $expire = 0) {
			$group = $this->normalize_group($group);
			$key   = $this->normalize_key($key);

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
					$this->delete_disk_payload($key, $group);
					return true;
				}

				$this->delete_redis_payload($key, $group);
			}

			return $this->write_disk_payload_for_key($key, $group, $payload);
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
				if (!is_array($payload) || !array_key_exists('value', $payload)) {
					$payload = $this->read_disk_payload($key, $group);
				}
			} else {
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
				$redis_deleted = $this->delete_redis_payload($key, $group);
				$disk_deleted  = $this->delete_disk_payload($key, $group);
				return $redis_deleted && $disk_deleted;
			}

			return $this->delete_disk_payload($key, $group);
		}

		public function flush() {
			$this->cache = array();
			$this->flush_disk_cache();
			$this->flush_redis_cache();
			$this->ensure_base_dir();
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

			$path = $this->get_group_dir($group);
			if ($path && is_dir($path)) {
				$this->recursive_delete($path);
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
			if (!is_dir($dir) && !ucwp_safe_mkdir($dir, 0755, true) && !is_dir($dir)) {
				return false;
			}
			return $dir . '/' . sha1($key) . '.cache';
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
			if ($depth > $this->redis_value_max_depth) {
				return true;
			}

			if (is_object($value) || is_resource($value)) {
				return true;
			}

			if (is_string($value)) {
				return strlen($value) > $this->redis_value_max_string_bytes;
			}

			if (!is_array($value)) {
				return false;
			}

			if (count($value) > $this->redis_value_max_items) {
				return true;
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
			return (!$path || !file_exists($path)) ? true : ucwp_safe_unlink($path);
		}

		private function write_redis_payload($key, $group, $payload, $expire) {
			if (!$this->is_redis_backend() || !is_array($payload)) {
				return false;
			}

			$value = $payload['value'] ?? null;
			if ($this->payload_contains_complex_types($value)) {
				$this->redis_error = 'Redis payload rejected: complex value type.';
				return false;
			}

			if (!(is_scalar($value) || null === $value || is_array($value))) {
				$this->redis_error = 'Redis payload rejected: unsupported value type.';
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
					return (bool) $this->with_redis_error_handler(function () use ($redis_key, $expire, $data) {
						return $this->redis->setEx($redis_key, (int) $expire, $data);
					}, false);
				}
				return (bool) $this->with_redis_error_handler(function () use ($redis_key, $data) {
					return $this->redis->set($redis_key, $data);
				}, false);
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

			try {
				$envelope = @unserialize($serialized, array('allowed_classes' => false));
			} catch (Throwable $e) {
				return false;
			}

			if (!is_array($envelope) || !isset($envelope['v'], $envelope['payload'], $envelope['sig']) || 1 !== (int) $envelope['v']) {
				return false;
			}

			$payload_serialized = base64_decode((string) $envelope['payload'], true);
			if (false === $payload_serialized || !$this->verify_signature($payload_serialized, (string) $envelope['sig'])) {
				return false;
			}

			try {
				$payload = @unserialize($payload_serialized, array('allowed_classes' => false));
			} catch (Throwable $e) {
				return false;
			}

			return is_array($payload) ? $payload : false;
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
			return $this->read_payload($path);
		}

		private function read_payload($path) {
			$data = ucwp_safe_file_get_contents($path);
			if (false === $data || '' === $data) {
				return false;
			}
			try {
				$envelope = @unserialize($data, array('allowed_classes' => false));
			} catch (Throwable $e) {
				return false;
			}
			if (!is_array($envelope)) {
				return false;
			}
			if (!isset($envelope['v'], $envelope['payload'], $envelope['sig']) || 1 !== (int) $envelope['v']) {
				return false;
			}
			$serialized = base64_decode((string) $envelope['payload'], true);
			if (false === $serialized || !$this->verify_signature($serialized, (string) $envelope['sig'])) {
				return false;
			}
			try {
				$payload = @unserialize($serialized);
			} catch (Throwable $e) {
				return false;
			}
			return is_array($payload) ? $payload : false;
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
			$this->recursive_delete($this->cache_dir);
		}

		private function recursive_delete($dir) {
			if (!is_dir($dir)) {
				return;
			}
			$items = @scandir($dir);
			if (!is_array($items)) {
				return;
			}
			foreach ($items as $item) {
				if ('.' === $item || '..' === $item) {
					continue;
				}
				$path = $dir . DIRECTORY_SEPARATOR . $item;
				if (is_dir($path)) {
					$this->recursive_delete($path);
				} else {
					ucwp_safe_unlink($path);
				}
			}
			if (rtrim($dir, '/\\') !== rtrim($this->cache_dir, '/\\')) {
				@rmdir($dir);
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
