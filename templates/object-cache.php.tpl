<?php
/**
 * UltraCache generated object-cache drop-in.
 * Marker: UltraCache generated object-cache drop-in
 * Drop-in Build: __ULTRACACHE_DROPIN_BUILD__
 * Safe to overwrite.
 * Storage format: redis-apcu-sqlite-disk-runtime-v5 with signed-payload-v1 metrics.
 */

defined('ABSPATH') || exit;

function ultracache_object_cache_guard_normalize_path($path) {
	$path = is_string($path) ? trim($path) : '';
	if ('' === $path || false !== strpos($path, "\0")) {
		return '';
	}
	$path = str_replace('\\', '/', $path);
	$path = preg_replace('#/+#', '/', $path);
	return is_string($path) ? rtrim($path, '/') : '';
}

function ultracache_object_cache_guard_resolve_path($path, $must_exist = false) {
	$path = is_string($path) ? trim($path) : '';
	if ('' === $path || false !== strpos($path, "\0")) {
		return '';
	}
	$real = function_exists('realpath') ? realpath($path) : false;
	if (is_string($real) && '' !== $real) {
		return ultracache_object_cache_guard_normalize_path($real);
	}
	if ($must_exist) {
		return '';
	}
	$parent = dirname($path);
	$leaf = basename($path);
	if ('' === $leaf || '.' === $leaf || '..' === $leaf) {
		return '';
	}
	$real_parent = function_exists('realpath') ? realpath($parent) : false;
	if (is_string($real_parent) && '' !== $real_parent) {
		return ultracache_object_cache_guard_normalize_path(rtrim($real_parent, '/\\') . DIRECTORY_SEPARATOR . $leaf);
	}
	return ultracache_object_cache_guard_normalize_path($path);
}

function ultracache_object_cache_allowed_file_roots() {
	return array(__ULTRACACHE_OBJECT_CACHE_DIR__);
}

function ultracache_object_cache_is_allowed_file_path($path, $must_exist = false) {
	$resolved = ultracache_object_cache_guard_resolve_path($path, (bool) $must_exist);
	if ('' === $resolved) {
		return false;
	}
	foreach (ultracache_object_cache_allowed_file_roots() as $root) {
		$root = ultracache_object_cache_guard_resolve_path($root, false);
		if ('' === $root) {
			continue;
		}
		if ($resolved === $root || 0 === strpos($resolved, $root . '/')) {
			return true;
		}
	}
	return false;
}

function ultracache_object_cache_safe_file_get_contents($file) {
	return ultracache_object_cache_is_allowed_file_path($file, true) && is_readable($file) ? file_get_contents($file) : false;
}

function ultracache_object_cache_safe_file_put_contents($file, $data, $flags = 0, $context = '') {
    $file = is_string($file) ? trim($file) : '';
    if ('' === $file || !ultracache_object_cache_is_allowed_file_path($file, false)) {
        return false;
    }
    $dir = dirname($file);
    if ('' !== $dir && '.' !== $dir && !is_dir($dir)) {
        ultracache_object_cache_safe_mkdir($dir, 0700, true);
    }
    if ('' !== $dir && '.' !== $dir && (!is_dir($dir) || !is_writable($dir))) {
        if (is_dir($dir)) {
            @chmod($dir, 0700);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }
    }
    return @file_put_contents($file, $data, $flags);
}
function ultracache_object_cache_safe_unlink($file) {
	if (!ultracache_object_cache_is_allowed_file_path($file, false)) {
		return false;
	}
	return !file_exists($file) ? true : @unlink($file);
}

function ultracache_object_cache_safe_rename($from, $to) {
	if (!ultracache_object_cache_is_allowed_file_path($from, true) || !ultracache_object_cache_is_allowed_file_path($to, false)) {
		return false;
	}
	return @rename($from, $to);
}

function ultracache_object_cache_safe_mkdir($dir, $mode = 0700, $recursive = true) {
    $dir = is_string($dir) ? trim($dir) : '';
    if ('' === $dir || !ultracache_object_cache_is_allowed_file_path($dir, false)) {
        return false;
    }
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, $mode, $recursive) || is_dir($dir);
}

function ultracache_object_cache_safe_scandir($dir) {
	$dir = is_string($dir) ? trim($dir) : '';
	if ('' === $dir || !ultracache_object_cache_is_allowed_file_path($dir, true) || !is_dir($dir) || !is_readable($dir)) {
		return false;
	}
	return scandir($dir);
}

function ultracache_object_cache_safe_rmdir($dir) {
	$dir = is_string($dir) ? trim($dir) : '';
	if ('' === $dir || !ultracache_object_cache_is_allowed_file_path($dir, false)) {
		return false;
	}
	if (!file_exists($dir)) {
		return true;
	}
	if (!is_dir($dir) || is_link($dir)) {
		return false;
	}
	$items = ultracache_object_cache_safe_scandir($dir);
	if (is_array($items)) {
		foreach ($items as $item) {
			if ('.' === $item || '..' === $item) {
				continue;
			}
			return false;
		}
	}
	clearstatcache(true, $dir);
	return @rmdir($dir) || !file_exists($dir);
}

__ULTRACACHE_OBJECT_CACHE_BACKEND_CLASSES__

if (!class_exists('WP_Object_Cache')) {
	class WP_Object_Cache {
		private $cache = array();
		private $global_groups = array();
		private $non_persistent_groups = array();
		private $cache_dir = __ULTRACACHE_OBJECT_CACHE_DIR__;
		private $blog_id = 1;
		private $metrics_file = '';
		private $stats = array(
			'hits'   => 0,
			'misses' => 0,
		);
		private $selected_backend = __ULTRACACHE_SELECTED_BACKEND__;
		private $fallback_backend_policy = __ULTRACACHE_FALLBACK_BACKEND__;
		private $metrics_enabled = __ULTRACACHE_CACHE_STATS_ENABLED__;
		private $active_backend = 'runtime';
		private $backend_adapter = null;
		private $redis = null;
		private $redis_enabled = false;
		private $apcu_enabled = false;
		private $apcu_prefix = '';
		private $sqlite = null;
		private $sqlite_enabled = false;
		private $sqlite_path = __ULTRACACHE_SQLITE_PATH__;
		private $sqlite_error = '';
		private $sqlite_journal_mode = '';
		private $sqlite_database_max_bytes = __ULTRACACHE_SQLITE_DATABASE_MAX_BYTES__;
		private $sqlite_journal_target_bytes = 16777216;
		private $sqlite_last_checkpoint_at = 0;
		private $sqlite_cleanup_high_watermark = 0.90;
		private $sqlite_cleanup_low_watermark = 0.80;
		private $redis_host = __ULTRACACHE_REDIS_HOST__;
		private $redis_port = __ULTRACACHE_REDIS_PORT__;
		private $redis_username = __ULTRACACHE_REDIS_USERNAME__;
		private $redis_password = '';
		private $redis_database = __ULTRACACHE_REDIS_DATABASE__;
		private $redis_prefix = __ULTRACACHE_REDIS_PREFIX__;
		private $redis_use_tls = __ULTRACACHE_REDIS_USE_TLS__;
		private $redis_persistent = __ULTRACACHE_REDIS_PERSISTENT__;
		private $redis_connect_timeout = __ULTRACACHE_REDIS_CONNECT_TIMEOUT__;
		private $redis_read_timeout = __ULTRACACHE_REDIS_READ_TIMEOUT__;
		private $redis_error = '';
		private $redis_payload_skip_reason = '';
		private $redis_value_max_items = 64;
		private $redis_value_max_depth = 2;
		private $redis_value_max_string_bytes = 16384;
		private $redis_payload_max_bytes = 1048576;
		private $disk_payload_max_bytes = 8388608;
		private $signed_envelope_max_bytes = 12582912;

		public function __construct() {
			$this->blog_id = $this->detect_blog_id();
			$this->metrics_file = rtrim($this->cache_dir, '/\\') . '/object-cache-metrics.json';
			$this->ensure_base_dir();
			$this->load_redis_credentials_from_constants();
			$this->bootstrap_backend();
			$this->initialize_backend_adapter();
			if ($this->metrics_enabled) {
				register_shutdown_function(array($this, 'persist_metrics'));
			}
		}

		public function get_backend() {
			return (string) $this->active_backend;
		}

		public function get_backend_status() {
			$configured_fallback = $this->sanitize_fallback_backend($this->fallback_backend_policy);
			$standby_fallback = ('none' === $configured_fallback ? 'runtime' : $configured_fallback);
			$fallback_backend = $standby_fallback;
			$fallback_active = ((string) $this->selected_backend !== (string) $this->active_backend);
			if ($fallback_active) {
				$fallback_backend = (string) $this->active_backend;
			}
			$fallback_persistent = $fallback_active && in_array((string) $this->active_backend, array('redis', 'apcu', 'sqlite', 'disk'), true);
			$active_runtime_only = ('runtime' === (string) $this->active_backend);
			$fallback_reason = '';
			$fallback_message = '';
			if ($fallback_active) {
				$fallback_label = $this->get_backend_label($fallback_backend);
				$selected_label = $this->get_backend_label($this->selected_backend);
				if ('redis' === (string) $this->selected_backend && '' !== (string) $this->redis_error) {
					$fallback_reason = (string) $this->redis_error;
				} elseif ('sqlite' === (string) $this->selected_backend && '' !== (string) $this->sqlite_error) {
					$fallback_reason = (string) $this->sqlite_error;
				} else {
					$fallback_reason = $selected_label . ' was selected but did not become active during drop-in bootstrap.';
				}
				$fallback_message = $selected_label . ' selected, ' . $fallback_label . ' fallback active.' . ('' !== $fallback_reason ? ' Reason: ' . $fallback_reason : '');
			}

			return array(
				'selected' => (string) $this->selected_backend,
				'active'   => (string) $this->active_backend,
				'configuredFallback' => $configured_fallback,
				'fallback' => (string) $fallback_backend,
				'fallbackActive' => (bool) $fallback_active,
				'activeFallbackBackend' => $fallback_active ? (string) $this->active_backend : '',
				'activeFallbackKind' => $fallback_active ? ($active_runtime_only ? 'runtime-only' : 'persistent') : '',
				'fallbackPersistent' => (bool) $fallback_persistent,
				'activeBackendRuntimeOnly' => (bool) $active_runtime_only,
				'fallbackReason' => $fallback_reason,
				'fallbackMessage' => $fallback_message,
				'apcu'     => array(
					'enabled'   => (bool) $this->apcu_enabled,
					'available' => (bool) $this->apcu_available(),
					'fallback_active' => (bool) ($fallback_active && 'apcu' === $fallback_backend),
				),
				'sqlite'   => array(
					'enabled'   => (bool) $this->sqlite_enabled,
					'available' => class_exists('SQLite3'),
					'path'      => (string) $this->sqlite_path,
					'journalMode' => (string) $this->sqlite_journal_mode,
					'databaseMaxBytes' => $this->sqlite_database_max_bytes,
					'databaseMaxMb' => (int) round(((float) $this->sqlite_database_max_bytes) / 1048576),
					'journalTargetBytes' => $this->sqlite_journal_target_bytes,
					'journalTargetMb' => (int) round(((float) $this->sqlite_journal_target_bytes) / 1048576),
					'error'     => (string) $this->sqlite_error,
					'fallback_active' => (bool) ($fallback_active && 'sqlite' === $fallback_backend),
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
					'payloadSkipReason' => (string) $this->redis_payload_skip_reason,
				),
			);
		}


		private function initialize_backend_adapter() {
			$context = new Ultra_Cache_Object_Cache_Backend_Context(array(
				'normalize_group' => function ($group) {
					return $this->normalize_group($group);
				},
				'normalize_key' => function ($key) {
					return $this->normalize_key($key);
				},
				'should_suspend_cache_addition' => function () {
					return $this->should_suspend_cache_addition();
				},
				'is_non_persistent_group' => function ($group) {
					return $this->is_non_persistent_group($group);
				},
				'runtime_has' => function ($key, $group) {
					return $this->runtime_has($key, $group);
				},
				'runtime_get' => function ($key, $group) {
					$scope = $this->get_runtime_scope($group);
					return $this->copy_value($this->cache[$scope][$group][$key]);
				},
				'runtime_set' => function ($key, $group, $value) {
					$this->set_runtime_value($key, $group, $value);
					return true;
				},
				'runtime_delete' => function ($key, $group) {
					$scope = $this->get_runtime_scope($group);
					if (isset($this->cache[$scope][$group]) && array_key_exists($key, $this->cache[$scope][$group])) {
						unset($this->cache[$scope][$group][$key]);
					}
					return true;
				},
				'runtime_clear' => function () {
					$this->cache = array();
					return true;
				},
				'runtime_clear_group' => function ($group) {
					$scope = $this->get_runtime_scope($group);
					unset($this->cache[$scope][$group]);
					return true;
				},
				'build_payload' => function ($key, $group, $data, $expire) {
					return $this->build_payload($key, $group, $data, (int) $expire);
				},
				'record_hit' => function () {
					$this->stats['hits']++;
				},
				'record_miss' => function () {
					$this->stats['misses']++;
				},
				'delete_persistent_payload' => function ($key, $group) {
					if ($this->is_redis_backend()) {
						$this->delete_redis_payload($key, $group);
					}
					if ($this->apcu_enabled) {
						$this->delete_apcu_payload($key, $group);
					}
					if ($this->sqlite_enabled) {
						$this->delete_sqlite_payload($key, $group);
					}
					if ('disk' === $this->active_backend) {
						$this->delete_disk_payload($key, $group);
					}
					return true;
				},
				'flush_persistent_cache' => function () {
					$this->flush_redis_cache();
					$this->flush_apcu_cache();
					$this->flush_sqlite_cache();
					if ('disk' === $this->active_backend) {
						$this->flush_disk_cache();
					}
					return true;
				},
				'flush_persistent_group' => function ($group) {
					if ($this->is_redis_backend()) {
						$this->flush_redis_group($group);
					}
					if ($this->apcu_enabled) {
						$this->flush_apcu_group($group);
					}
					if ($this->sqlite_enabled) {
						$this->flush_sqlite_group($group);
					}
					if ('disk' === $this->active_backend) {
						$path = $this->get_group_dir($group);
						if ($path && is_dir($path)) {
							$this->recursive_delete($path);
						}
					}
					return true;
				},
				'after_flush' => function () {
					if ('disk' === $this->active_backend) {
						$this->ensure_base_dir();
					}
				},
				'health' => function () {
					return $this->get_backend_status();
				},
				'read_redis_payload' => function ($key, $group) {
					return $this->read_redis_payload($key, $group);
				},
				'write_redis_payload' => function ($key, $group, $payload, $expire) {
					return $this->write_redis_payload($key, $group, $payload, (int) $expire);
				},
				'delete_redis_payload' => function ($key, $group) {
					return $this->delete_redis_payload($key, $group);
				},
				'read_apcu_payload' => function ($key, $group) {
					return $this->read_apcu_payload($key, $group);
				},
				'write_apcu_payload' => function ($key, $group, $payload, $expire) {
					return $this->write_apcu_payload($key, $group, $payload, (int) $expire);
				},
				'read_sqlite_payload' => function ($key, $group) {
					return $this->read_sqlite_payload($key, $group);
				},
				'write_sqlite_payload' => function ($key, $group, $payload) {
					return $this->write_sqlite_payload($key, $group, $payload);
				},
				'add_sqlite_payload' => function ($key, $group, $payload) {
					return $this->add_sqlite_payload($key, $group, $payload);
				},
				'replace_sqlite_payload' => function ($key, $group, $payload) {
					return $this->replace_sqlite_payload($key, $group, $payload);
				},
				'mutate_sqlite_numeric_payload' => function ($key, $group, $offset, $decrement) {
					return $this->mutate_sqlite_numeric_payload($key, $group, (int) $offset, (bool) $decrement);
				},
				'read_disk_payload' => function ($key, $group) {
					return $this->read_disk_payload($key, $group);
				},
				'write_disk_payload' => function ($key, $group, $payload) {
					return $this->write_disk_payload_for_key($key, $group, $payload);
				},
			));

			$classes = array(
				'redis' => 'Ultra_Cache_Object_Cache_Redis_Backend',
				'sqlite' => 'Ultra_Cache_Object_Cache_SQLite_Backend',
				'apcu' => 'Ultra_Cache_Object_Cache_APCu_Backend',
				'disk' => 'Ultra_Cache_Object_Cache_Disk_Backend',
				'runtime' => 'Ultra_Cache_Object_Cache_Runtime_Backend',
			);
			$class_name = isset($classes[$this->active_backend]) ? $classes[$this->active_backend] : $classes['runtime'];
			$this->backend_adapter = new $class_name($context);
		}

		private function load_redis_credentials_from_constants() {
			if (!defined('WP_REDIS_PASSWORD')) {
				return;
			}

			$value = constant('WP_REDIS_PASSWORD');
			if (is_array($value)) {
				if (array_key_exists(0, $value) && is_scalar($value[0])) {
					$this->redis_username = trim((string) $value[0]);
				} elseif (isset($value['username']) && is_scalar($value['username'])) {
					$this->redis_username = trim((string) $value['username']);
				} elseif (isset($value['user']) && is_scalar($value['user'])) {
					$this->redis_username = trim((string) $value['user']);
				}

				if (array_key_exists(1, $value) && is_scalar($value[1])) {
					$this->redis_password = (string) $value[1];
				} elseif (isset($value['password']) && is_scalar($value['password'])) {
					$this->redis_password = (string) $value['password'];
				}
				return;
			}

			if (is_scalar($value)) {
				$this->redis_password = (string) $value;
			}
			if (defined('WP_REDIS_USERNAME')) {
				$username = constant('WP_REDIS_USERNAME');
				if (is_scalar($username)) {
					$this->redis_username = trim((string) $username);
				}
			}
		}

		private function sanitize_fallback_backend($value) {
			$value = strtolower(trim((string) $value));
			if ('none' === $value || 'runtime' === $value || '' === $value) {
				return 'none';
			}
			return in_array($value, array('apcu', 'sqlite', 'disk'), true) ? $value : 'apcu';
		}

		private function get_backend_label($backend) {
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

		private function bootstrap_backend() {
			$this->active_backend = 'runtime';
			$this->fallback_backend_policy = $this->sanitize_fallback_backend($this->fallback_backend_policy);
			$this->apcu_prefix = $this->build_apcu_prefix();

			if ('redis' === $this->selected_backend) {
				$this->bootstrap_redis_backend();
				if ($this->is_redis_backend()) {
					return;
				}
				$this->bootstrap_configured_fallback();
				return;
			}

			if ('apcu' === $this->selected_backend) {
				if ($this->bootstrap_apcu_backend()) {
					return;
				}
				$this->bootstrap_configured_fallback();
				return;
			}

			if ('sqlite' === $this->selected_backend) {
				if ($this->bootstrap_sqlite_backend()) {
					return;
				}
				$this->bootstrap_configured_fallback();
				return;
			}

			if ('disk' === $this->selected_backend) {
				$this->active_backend = 'disk';
			}
		}

		private function bootstrap_configured_fallback() {
			if ($this->fallback_backend_policy === $this->selected_backend) {
				return false;
			}
			if ('apcu' === $this->fallback_backend_policy && $this->bootstrap_apcu_backend()) {
				return true;
			}
			if ('sqlite' === $this->fallback_backend_policy && $this->bootstrap_sqlite_backend()) {
				return true;
			}
			if ('disk' === $this->fallback_backend_policy) {
				$this->active_backend = 'disk';
				return true;
			}
			return false;
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

				if (!$this->is_redis_socket_target_allowed($connection_host, $port)) {
					$this->redis_error = 'Blocked invalid Redis endpoint. Use a valid explicitly configured Redis host and port.';
					return false;
				}

				$connected = false;
				if ($this->redis_persistent) {
					$persistent_id = 'ultracache:' . md5(implode('|', array($connection_host, $port, (string) $this->redis_database, (string) $this->redis_prefix)));
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
						if ('' !== trim((string) $this->redis_username)) {
							return $redis->auth(array((string) $this->redis_username, (string) $this->redis_password));
						}
						return $redis->auth($this->redis_password);
					}, false);
					if (!$authed) {
						if ('' === $this->redis_error) {
							$this->redis_error = '' !== trim((string) $this->redis_username) ? 'Redis ACL authentication failed.' : 'Redis authentication failed.';
						}
						return false;
					}
				} elseif ('' !== trim((string) $this->redis_username)) {
					$this->redis_error = 'Redis username was provided without a password.';
					return false;
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
			$this->add_apcu_non_persistent_option_groups();
			return true;
		}

		private function add_apcu_non_persistent_option_groups() {
			// APCu is per PHP process, so persistent option/alloptions caching can
			// return stale dashboard settings from another PHP-FPM worker after saves.
			$this->add_non_persistent_groups(array('options', 'site-options'));
		}

		private function harden_sqlite_file_permissions() {
			foreach (array($this->sqlite_path, $this->sqlite_path . '-wal', $this->sqlite_path . '-shm') as $path) {
				if (is_string($path) && '' !== $path && file_exists($path) && $this->is_cache_path($path)) {
					@chmod($path, 0600);
				}
			}
		}

		private function get_sqlite_file_size($path) {
			if (!is_string($path) || '' === $path || !file_exists($path)) {
				return 0;
			}
			clearstatcache(true, $path);
			$size = filesize($path);
			return false === $size ? 0 : max(0, (int) $size);
		}

		private function acquire_sqlite_maintenance_lock() {
			$lock_path = $this->sqlite_path . '.maintenance.lock';
			if (!$this->is_cache_path($lock_path)) {
				return false;
			}

			$handle = @fopen($lock_path, 'c+');
			if (!is_resource($handle)) {
				return false;
			}
			@chmod($lock_path, 0600);
			if (!@flock($handle, LOCK_EX | LOCK_NB)) {
				@fclose($handle);
				return false;
			}

			return $handle;
		}

		private function release_sqlite_maintenance_lock($handle) {
			if (!is_resource($handle)) {
				return;
			}
			@flock($handle, LOCK_UN);
			@fclose($handle);
		}

		private function run_sqlite_checkpoint(SQLite3 $sqlite, $mode = 'PASSIVE') {
			$mode = strtoupper(trim((string) $mode));
			if (!in_array($mode, array('PASSIVE', 'TRUNCATE'), true)) {
				$mode = 'PASSIVE';
			}
			$result = $sqlite->querySingle('PRAGMA wal_checkpoint(' . $mode . ')', true);
			$this->sqlite_last_checkpoint_at = time();
			$this->harden_sqlite_file_permissions();
			return is_array($result);
		}

		private function checkpoint_sqlite_wal($mode = 'PASSIVE', $maintenance_lock = null) {
			if (!$this->sqlite_enabled || !($this->sqlite instanceof SQLite3)) {
				return false;
			}

			$owns_lock = !is_resource($maintenance_lock);
			$lock = $owns_lock ? $this->acquire_sqlite_maintenance_lock() : $maintenance_lock;
			if (!is_resource($lock)) {
				return false;
			}

			try {
				return $this->run_sqlite_checkpoint($this->sqlite, $mode);
			} catch (Throwable $e) {
				return false;
			} finally {
				if ($owns_lock) {
					$this->release_sqlite_maintenance_lock($lock);
				}
			}
		}

		private function get_sqlite_page_usage(SQLite3 $sqlite) {
			$page_size = max(512, (int) $sqlite->querySingle('PRAGMA page_size'));
			$page_count = max(0, (int) $sqlite->querySingle('PRAGMA page_count'));
			$free_pages = max(0, (int) $sqlite->querySingle('PRAGMA freelist_count'));
			$max_pages = max(1.0, floor(((float) $this->sqlite_database_max_bytes) / $page_size));

			return array(
				'page_size' => $page_size,
				'page_count' => $page_count,
				'free_pages' => min($page_count, $free_pages),
				'used_pages' => max(0, $page_count - $free_pages),
				'max_pages' => $max_pages,
			);
		}

		private function apply_sqlite_max_page_count(SQLite3 $sqlite) {
			$usage = $this->get_sqlite_page_usage($sqlite);
			$requested = sprintf('%.0f', (float) $usage['max_pages']);
			$applied = (float) $sqlite->querySingle('PRAGMA max_page_count=' . $requested);
			return $applied > 0 && $applied <= ((float) $usage['max_pages'] + 1.0);
		}

		private function ensure_sqlite_database_limit(SQLite3 $sqlite) {
			$usage = $this->get_sqlite_page_usage($sqlite);
			if ((float) $usage['page_count'] <= (float) $usage['max_pages']) {
				return $this->apply_sqlite_max_page_count($sqlite);
			}

			$lock = $this->acquire_sqlite_maintenance_lock();
			if (!is_resource($lock)) {
				$this->sqlite_error = 'SQLite database resize maintenance is already in progress.';
				return false;
			}

			try {
				if (!$sqlite->exec('DELETE FROM ultracache_object_cache')) {
					throw new RuntimeException('SQLite cache entries could not be cleared before reducing the database limit.');
				}
				$this->run_sqlite_checkpoint($sqlite, 'TRUNCATE');
				if (!$sqlite->exec('VACUUM')) {
					throw new RuntimeException('SQLite database could not be rebuilt for the reduced size limit.');
				}
				if (!$this->apply_sqlite_max_page_count($sqlite)) {
					throw new RuntimeException('SQLite maximum database size could not be applied after rebuilding the cache database.');
				}
				return true;
			} catch (Throwable $e) {
				$this->sqlite_error = $e->getMessage();
				return false;
			} finally {
				$this->release_sqlite_maintenance_lock($lock);
			}
		}

		private function maintain_sqlite_capacity_before_write($incoming_bytes = 0) {
			if (!$this->is_sqlite_backend()) {
				return false;
			}

			$journal_size = $this->get_sqlite_file_size($this->sqlite_path . '-wal');
			if (
				$journal_size > (float) $this->sqlite_journal_target_bytes
				&& (time() - (int) $this->sqlite_last_checkpoint_at) >= 5
			) {
				$this->checkpoint_sqlite_wal('PASSIVE');
			}

			$usage = $this->get_sqlite_page_usage($this->sqlite);
			$incoming_pages = max(0.0, ceil(max(0.0, (float) $incoming_bytes) / max(1, (int) $usage['page_size']))) + 8.0;
			$high_watermark = max(1.0, floor((float) $usage['max_pages'] * (float) $this->sqlite_cleanup_high_watermark));
			if (((float) $usage['used_pages'] + $incoming_pages) < $high_watermark) {
				return true;
			}

			$lock = $this->acquire_sqlite_maintenance_lock();
			if (!is_resource($lock)) {
				return true;
			}

			try {
				$now = time();
				$this->sqlite->exec('DELETE FROM ultracache_object_cache WHERE expires_at > 0 AND expires_at < ' . $now);
				$usage = $this->get_sqlite_page_usage($this->sqlite);
				$ratio_low_watermark = max(1.0, floor((float) $usage['max_pages'] * (float) $this->sqlite_cleanup_low_watermark));
				$write_safe_watermark = max(1.0, floor((float) $usage['max_pages'] - $incoming_pages - 8.0));
				$low_watermark = min($ratio_low_watermark, $write_safe_watermark);
				$batches = 0;

				while ((float) $usage['used_pages'] > $low_watermark && $batches < 20) {
					if (!$this->sqlite->exec('DELETE FROM ultracache_object_cache WHERE cache_id IN (SELECT cache_id FROM ultracache_object_cache ORDER BY updated_at ASC LIMIT 500)')) {
						break;
					}
					if ($this->sqlite->changes() < 1) {
						break;
					}
					$batches++;
					$usage = $this->get_sqlite_page_usage($this->sqlite);
				}

				$this->run_sqlite_checkpoint($this->sqlite, 'PASSIVE');
				$usage = $this->get_sqlite_page_usage($this->sqlite);
				if (((float) $usage['used_pages'] + $incoming_pages) >= (float) $usage['max_pages']) {
					$this->sqlite_error = 'SQLite object-cache database capacity reached after cleanup.';
					return false;
				}

				$this->sqlite_error = '';
				return true;
			} catch (Throwable $e) {
				$this->sqlite_error = $e->getMessage();
				return false;
			} finally {
				$this->release_sqlite_maintenance_lock($lock);
			}
		}

		private function configure_sqlite_runtime(SQLite3 $sqlite) {
			if (
				!$sqlite->exec('PRAGMA synchronous=NORMAL')
				|| !$sqlite->exec('PRAGMA temp_store=MEMORY')
				|| !$sqlite->exec('PRAGMA secure_delete=ON')
				|| !$sqlite->exec('PRAGMA wal_autocheckpoint=1000')
			) {
				return false;
			}

			$sqlite->querySingle('PRAGMA journal_size_limit=' . sprintf('%.0f', (float) $this->sqlite_journal_target_bytes));
			return true;
		}

		private function bootstrap_sqlite_backend() {
			$this->sqlite = null;
			$this->sqlite_enabled = false;
			$this->sqlite_journal_mode = '';

			if (!class_exists('SQLite3')) {
				$this->sqlite_error = 'PHP SQLite3 extension not loaded.';
				return false;
			}
			if (!$this->is_cache_path($this->sqlite_path)) {
				$this->sqlite_error = 'SQLite database path is outside the UltraCache object-cache directory.';
				return false;
			}

			$this->ensure_base_dir();
			if (!is_dir($this->cache_dir) || !is_writable($this->cache_dir)) {
				$this->sqlite_error = 'UltraCache object-cache directory is not writable.';
				return false;
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped handler converts SQLite open warnings into exceptions and is restored immediately.
			set_error_handler(function ($severity, $message, $file = null, $line = null) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception context is not rendered as HTML output.
				throw new ErrorException($message, 0, $severity, (string) $file, (int) $line);
			});
			try {
				$sqlite = new SQLite3($this->sqlite_path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
				$sqlite->enableExceptions(true);
				$sqlite->busyTimeout(100);
				$journal_mode = strtolower((string) $sqlite->querySingle('PRAGMA journal_mode=WAL'));
				if ('wal' !== $journal_mode) {
					throw new RuntimeException('SQLite WAL journal mode could not be enabled.');
				}
				if (!$this->configure_sqlite_runtime($sqlite)) {
					throw new RuntimeException('SQLite runtime configuration failed.');
				}
				$schema_version = (int) $sqlite->querySingle('PRAGMA user_version');
				if ($schema_version < 1) {
					if (!$sqlite->exec('CREATE TABLE IF NOT EXISTS ultracache_object_cache (cache_id TEXT PRIMARY KEY NOT NULL, cache_scope TEXT NOT NULL, cache_group TEXT NOT NULL, payload BLOB NOT NULL, expires_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL)')) {
						throw new RuntimeException('SQLite object-cache table creation failed.');
					}
					if (!$sqlite->exec('CREATE INDEX IF NOT EXISTS ultracache_object_cache_scope_group ON ultracache_object_cache (cache_scope, cache_group)') || !$sqlite->exec('CREATE INDEX IF NOT EXISTS ultracache_object_cache_expiry ON ultracache_object_cache (expires_at)')) {
						throw new RuntimeException('SQLite object-cache index creation failed.');
					}
					if (!$sqlite->exec('PRAGMA user_version=1')) {
						throw new RuntimeException('SQLite object-cache schema version could not be stored.');
					}
				}
				if (!$this->ensure_sqlite_database_limit($sqlite)) {
					throw new RuntimeException('' !== (string) $this->sqlite_error ? (string) $this->sqlite_error : 'SQLite maximum database size could not be applied.');
				}
				$this->sqlite = $sqlite;
				$this->sqlite_enabled = true;
				$this->sqlite_journal_mode = $journal_mode;
				$this->sqlite_error = '';
				$this->active_backend = 'sqlite';
				$this->harden_sqlite_file_permissions();
				return true;
			} catch (Throwable $e) {
				$this->sqlite_error = $e->getMessage();
				if (isset($sqlite) && $sqlite instanceof SQLite3) {
					$sqlite->close();
				}
				$this->sqlite = null;
				$this->sqlite_enabled = false;
				$this->active_backend = 'runtime';
				return false;
			} finally {
				restore_error_handler();
			}
		}

		private function get_redis_connection_host() {
			$host = (string) $this->redis_host;
			if ($this->redis_use_tls && 0 !== strpos($host, 'tls://')) {
				$host = 'tls://' . ltrim($host, '/');
			}
			return $host;
		}

		private function is_redis_socket_target_allowed($host, $port) {
			$host = preg_replace('#^(?:tcp|tls|ssl)://#i', '', trim((string) $host));
			$host = trim((string) $host, " \t\n\r\0\x0B[]");
			$host_lc = strtolower($host);
			$port = (int) $port;

			// The drop-in only connects to the Redis endpoint saved in UltraCache settings.
			// External Redis infrastructure and custom ports are valid production setups.
			return '' !== $host_lc && $port > 0 && $port <= 65535 && false === strpos($host_lc, '/');
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
			$raw = ultracache_object_cache_safe_file_get_contents($this->metrics_file);
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
				ultracache_object_cache_safe_mkdir($dir, 0700, true);
			}
			ultracache_object_cache_safe_file_put_contents($this->metrics_file, json_encode($data), LOCK_EX);
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
			return $this->backend_adapter->add($key, $data, $group, $expire);
		}

		public function replace($key, $data, $group = 'default', $expire = 0) {
			return $this->backend_adapter->replace($key, $data, $group, $expire);
		}

		public function set($key, $data, $group = 'default', $expire = 0) {
			return $this->backend_adapter->set($key, $data, $group, $expire);
		}

		public function get($key, $group = 'default', $force = false, &$found = null) {
			return $this->backend_adapter->get($key, $group, $force, $found);
		}

		public function delete($key, $group = 'default', $deprecated = false) {
			return $this->backend_adapter->delete($key, $group);
		}

		public function flush() {
			return $this->backend_adapter->flush();
		}

		public function flush_group($group) {
			return $this->backend_adapter->flush_group($group);
		}

		public function incr($key, $offset = 1, $group = 'default') {
			return $this->backend_adapter->incr($key, $offset, $group);
		}

		public function decr($key, $offset = 1, $group = 'default') {
			return $this->backend_adapter->decr($key, $offset, $group);
		}



		private function should_suspend_cache_addition() {
			return function_exists('wp_suspend_cache_addition') && wp_suspend_cache_addition();
		}





		public function flush_runtime() {
			$this->cache = array();
			return true;
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
			if (!is_dir($dir) && !ultracache_object_cache_safe_mkdir($dir, 0700, true) && !is_dir($dir)) {
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
				ultracache_object_cache_safe_mkdir($this->cache_dir, 0700, true);
			}
			$index = rtrim($this->cache_dir, '/\\') . '/index.php';
			if (!file_exists($index)) {
				ultracache_object_cache_safe_file_put_contents($index, "<?php\n// Silence is golden.\n");
			}
			$htaccess = rtrim($this->cache_dir, '/\\') . '/.htaccess';
			if (!file_exists($htaccess)) {
				ultracache_object_cache_safe_file_put_contents($htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
			}
			$web_config = rtrim($this->cache_dir, '/\\') . '/web.config';
			if (!file_exists($web_config)) {
				ultracache_object_cache_safe_file_put_contents($web_config, '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>');
			}
		}

		private function set_runtime_value($key, $group, $value) {
			$scope = $this->get_runtime_scope($group);
			if (!isset($this->cache[$scope]) || !is_array($this->cache[$scope])) {
				$this->cache[$scope] = array();
			}
			if (!isset($this->cache[$scope][$group]) || !is_array($this->cache[$scope][$group])) {
				$this->cache[$scope][$group] = array();
			}
			$this->cache[$scope][$group][$key] = $this->copy_value($value);
		}

		private function get_runtime_scope($group) {
			return $this->get_redis_scope($group);
		}

		private function runtime_has($key, $group) {
			$scope = $this->get_runtime_scope($group);
			return isset($this->cache[$scope][$group]) && array_key_exists($key, $this->cache[$scope][$group]);
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

		private function is_sqlite_backend() {
			return 'sqlite' === $this->active_backend && $this->sqlite_enabled && $this->sqlite instanceof SQLite3;
		}

		private function build_apcu_prefix() {
			$seed = __ULTRACACHE_SITE_NAMESPACE_SEED__ . '|' . (string) $this->redis_prefix;
			$hash = function_exists('hash') ? hash('sha256', 'ultracache-apcu|' . $seed) : md5('ultracache-apcu|' . $seed);
			return 'ultracache_apcu:' . substr((string) $hash, 0, 16) . ':';
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
			$namespace = sha1(uniqid('ultracache-apcu-', true));
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
			@apcu_store($key, sha1(uniqid('ultracache-apcu-group-', true)));
		}

		private function flush_apcu_cache() {
			if (!$this->apcu_enabled || !$this->apcu_available()) {
				return;
			}
			@apcu_store($this->apcu_prefix . 'namespace', sha1(uniqid('ultracache-apcu-flush-', true)));
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


		private function get_sqlite_cache_id($key, $group) {
			$scope = $this->get_redis_scope($group);
			$material = $scope . "\0" . $group . "\0" . $key;
			return function_exists('hash') ? hash('sha256', $material) : md5($material);
		}

		private function prepare_sqlite_payload_data($payload) {
			if (!$this->is_sqlite_backend() || !is_array($payload)) {
				return false;
			}
			if ($this->payload_contains_complex_types($payload['value'] ?? null)) {
				$this->sqlite_error = 'SQLite payload skipped: unsupported resource/closure or excessive nesting.';
				return false;
			}
			try {
				$serialized = serialize($payload);
			} catch (Throwable $e) {
				$this->sqlite_error = $e->getMessage();
				return false;
			}
			if (!is_string($serialized) || '' === $serialized || strlen($serialized) > $this->disk_payload_max_bytes) {
				$this->sqlite_error = 'SQLite payload skipped: value too large.';
				return false;
			}
			$envelope = $this->build_signed_envelope($serialized);
			if (!is_array($envelope)) {
				$this->sqlite_error = 'SQLite payload skipped: envelope signing failed.';
				return false;
			}
			$data = serialize($envelope);
			if (!is_string($data) || '' === $data || strlen($data) > $this->signed_envelope_max_bytes) {
				$this->sqlite_error = 'SQLite payload skipped: signed value too large.';
				return false;
			}

			return array(
				'data'       => $data,
				'expires_at' => (int) ($payload['expires_at'] ?? 0),
			);
		}

		private function write_sqlite_payload($key, $group, $payload) {
			$prepared = $this->prepare_sqlite_payload_data($payload);
			if (!is_array($prepared)) {
				return false;
			}

			if (!$this->maintain_sqlite_capacity_before_write(strlen($prepared['data']))) {
				return false;
			}

			$statement = null;
			$result = null;
			try {
				$statement = $this->sqlite->prepare('INSERT OR REPLACE INTO ultracache_object_cache (cache_id, cache_scope, cache_group, payload, expires_at, updated_at) VALUES (:cache_id, :cache_scope, :cache_group, :payload, :expires_at, :updated_at)');
				$statement->bindValue(':cache_id', $this->get_sqlite_cache_id($key, $group), SQLITE3_TEXT);
				$statement->bindValue(':cache_scope', $this->get_redis_scope($group), SQLITE3_TEXT);
				$statement->bindValue(':cache_group', $group, SQLITE3_TEXT);
				$statement->bindValue(':payload', $prepared['data'], SQLITE3_BLOB);
				$statement->bindValue(':expires_at', (int) $prepared['expires_at'], SQLITE3_INTEGER);
				$statement->bindValue(':updated_at', time(), SQLITE3_INTEGER);
				$result = $statement->execute();
				$stored = false !== $result && $this->sqlite->changes() > 0;
				if (!$stored) {
					throw new RuntimeException('SQLite object-cache write did not persist the cache entry.');
				}
				$this->sqlite_error = '';
				$this->harden_sqlite_file_permissions();
				return true;
			} catch (Throwable $e) {
				$this->sqlite_error = $e->getMessage();
				return false;
			} finally {
				if ($result instanceof SQLite3Result) {
					$result->finalize();
				}
				if ($statement instanceof SQLite3Stmt) {
					$statement->close();
				}
			}
		}

		private function add_sqlite_payload($key, $group, $payload) {
			$prepared = $this->prepare_sqlite_payload_data($payload);
			if (!is_array($prepared)) {
				return false;
			}

			if (!$this->maintain_sqlite_capacity_before_write(strlen($prepared['data']))) {
				return false;
			}

			$delete_statement = null;
			$delete_result = null;
			$insert_statement = null;
			$insert_result = null;
			try {
				if (!$this->sqlite->exec('BEGIN IMMEDIATE')) {
					throw new RuntimeException('SQLite object-cache add transaction could not start.');
				}

				$cache_id = $this->get_sqlite_cache_id($key, $group);
				$delete_statement = $this->sqlite->prepare('DELETE FROM ultracache_object_cache WHERE cache_id = :cache_id AND expires_at > 0 AND expires_at < :now');
				$delete_statement->bindValue(':cache_id', $cache_id, SQLITE3_TEXT);
				$delete_statement->bindValue(':now', time(), SQLITE3_INTEGER);
				$delete_result = $delete_statement->execute();
				if ($delete_result instanceof SQLite3Result) {
					$delete_result->finalize();
					$delete_result = null;
				}
				$delete_statement->close();
				$delete_statement = null;

				$insert_statement = $this->sqlite->prepare('INSERT OR IGNORE INTO ultracache_object_cache (cache_id, cache_scope, cache_group, payload, expires_at, updated_at) VALUES (:cache_id, :cache_scope, :cache_group, :payload, :expires_at, :updated_at)');
				$insert_statement->bindValue(':cache_id', $cache_id, SQLITE3_TEXT);
				$insert_statement->bindValue(':cache_scope', $this->get_redis_scope($group), SQLITE3_TEXT);
				$insert_statement->bindValue(':cache_group', $group, SQLITE3_TEXT);
				$insert_statement->bindValue(':payload', $prepared['data'], SQLITE3_BLOB);
				$insert_statement->bindValue(':expires_at', (int) $prepared['expires_at'], SQLITE3_INTEGER);
				$insert_statement->bindValue(':updated_at', time(), SQLITE3_INTEGER);
				$insert_result = $insert_statement->execute();
				$stored = false !== $insert_result && $this->sqlite->changes() > 0;
				if ($insert_result instanceof SQLite3Result) {
					$insert_result->finalize();
					$insert_result = null;
				}
				$insert_statement->close();
				$insert_statement = null;

				if (!$this->sqlite->exec('COMMIT')) {
					throw new RuntimeException('SQLite object-cache add transaction could not commit.');
				}

				$this->sqlite_error = '';
				$this->harden_sqlite_file_permissions();
				return $stored;
			} catch (Throwable $e) {
				try {
					$this->sqlite->exec('ROLLBACK');
				} catch (Throwable $rollback_error) {
					// The original SQLite exception remains the actionable error.
				}
				$this->sqlite_error = $e->getMessage();
				return false;
			} finally {
				if ($delete_result instanceof SQLite3Result) {
					$delete_result->finalize();
				}
				if ($delete_statement instanceof SQLite3Stmt) {
					$delete_statement->close();
				}
				if ($insert_result instanceof SQLite3Result) {
					$insert_result->finalize();
				}
				if ($insert_statement instanceof SQLite3Stmt) {
					$insert_statement->close();
				}
			}
		}

		private function replace_sqlite_payload($key, $group, $payload) {
			$prepared = $this->prepare_sqlite_payload_data($payload);
			if (!is_array($prepared)) {
				return false;
			}

			if (!$this->maintain_sqlite_capacity_before_write(strlen($prepared['data']))) {
				return false;
			}

			$statement = null;
			$result = null;
			try {
				$statement = $this->sqlite->prepare('UPDATE ultracache_object_cache SET cache_scope = :cache_scope, cache_group = :cache_group, payload = :payload, expires_at = :expires_at, updated_at = :updated_at WHERE cache_id = :cache_id AND (expires_at = 0 OR expires_at >= :now)');
				$statement->bindValue(':cache_scope', $this->get_redis_scope($group), SQLITE3_TEXT);
				$statement->bindValue(':cache_group', $group, SQLITE3_TEXT);
				$statement->bindValue(':payload', $prepared['data'], SQLITE3_BLOB);
				$statement->bindValue(':expires_at', (int) $prepared['expires_at'], SQLITE3_INTEGER);
				$statement->bindValue(':updated_at', time(), SQLITE3_INTEGER);
				$statement->bindValue(':cache_id', $this->get_sqlite_cache_id($key, $group), SQLITE3_TEXT);
				$statement->bindValue(':now', time(), SQLITE3_INTEGER);
				$result = $statement->execute();
				$stored = false !== $result && $this->sqlite->changes() > 0;
				$this->sqlite_error = '';
				$this->harden_sqlite_file_permissions();
				return $stored;
			} catch (Throwable $e) {
				$this->sqlite_error = $e->getMessage();
				return false;
			} finally {
				if ($result instanceof SQLite3Result) {
					$result->finalize();
				}
				if ($statement instanceof SQLite3Stmt) {
					$statement->close();
				}
			}
		}

		private function mutate_sqlite_numeric_payload($key, $group, $offset, $decrement) {
			if (!$this->maintain_sqlite_capacity_before_write()) {
				return false;
			}

			$statement = null;
			$result = null;
			try {
				if (!$this->sqlite->exec('BEGIN IMMEDIATE')) {
					throw new RuntimeException('SQLite numeric mutation transaction could not start.');
				}

				$payload = $this->read_sqlite_payload($key, $group);
				if (!is_array($payload) || !array_key_exists('value', $payload) || !is_numeric($payload['value'])) {
					$this->sqlite->exec('ROLLBACK');
					return false;
				}

				$value = $decrement
					? max(0, $payload['value'] - (int) $offset)
					: $payload['value'] + (int) $offset;
				$payload['value'] = $value;
				$prepared = $this->prepare_sqlite_payload_data($payload);
				if (!is_array($prepared)) {
					$this->sqlite->exec('ROLLBACK');
					return false;
				}

				$statement = $this->sqlite->prepare('UPDATE ultracache_object_cache SET payload = :payload, expires_at = :expires_at, updated_at = :updated_at WHERE cache_id = :cache_id');
				$statement->bindValue(':payload', $prepared['data'], SQLITE3_BLOB);
				$statement->bindValue(':expires_at', (int) $prepared['expires_at'], SQLITE3_INTEGER);
				$statement->bindValue(':updated_at', time(), SQLITE3_INTEGER);
				$statement->bindValue(':cache_id', $this->get_sqlite_cache_id($key, $group), SQLITE3_TEXT);
				$result = $statement->execute();
				if (false === $result || $this->sqlite->changes() < 1) {
					throw new RuntimeException('SQLite numeric mutation did not update the cache entry.');
				}
				if ($result instanceof SQLite3Result) {
					$result->finalize();
					$result = null;
				}
				$statement->close();
				$statement = null;
				if (!$this->sqlite->exec('COMMIT')) {
					throw new RuntimeException('SQLite numeric mutation transaction could not commit.');
				}

				$this->sqlite_error = '';
				$this->harden_sqlite_file_permissions();
				$this->set_runtime_value($key, $group, $value);
				return $value;
			} catch (Throwable $e) {
				try {
					$this->sqlite->exec('ROLLBACK');
				} catch (Throwable $rollback_error) {
					// The original SQLite exception remains the actionable error.
				}
				$this->sqlite_error = $e->getMessage();
				return false;
			} finally {
				if ($result instanceof SQLite3Result) {
					$result->finalize();
				}
				if ($statement instanceof SQLite3Stmt) {
					$statement->close();
				}
			}
		}

		private function read_sqlite_payload($key, $group) {
			if (!$this->is_sqlite_backend()) {
				return false;
			}
			$statement = null;
			$result = null;
			try {
				$statement = $this->sqlite->prepare('SELECT payload, expires_at FROM ultracache_object_cache WHERE cache_id = :cache_id LIMIT 1');
				$statement->bindValue(':cache_id', $this->get_sqlite_cache_id($key, $group), SQLITE3_TEXT);
				$result = $statement->execute();
				$row = $result->fetchArray(SQLITE3_ASSOC);
			} catch (Throwable $e) {
				$this->sqlite_error = $e->getMessage();
				return false;
			} finally {
				if ($result instanceof SQLite3Result) {
					$result->finalize();
				}
				if ($statement instanceof SQLite3Stmt) {
					$statement->close();
				}
			}
			if (!is_array($row)) {
				return false;
			}
			if (!empty($row['expires_at']) && (int) $row['expires_at'] < time()) {
				$this->delete_sqlite_payload($key, $group);
				return false;
			}
			$data = $row['payload'] ?? '';
			if (!is_string($data) || '' === $data) {
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
			return (is_array($payload) && $this->is_valid_cache_payload($payload, $key, $group)) ? $payload : false;
		}

		private function delete_sqlite_payload($key, $group) {
			if (!$this->sqlite_enabled || !($this->sqlite instanceof SQLite3)) {
				return true;
			}
			$statement = null;
			$result = null;
			try {
				$statement = $this->sqlite->prepare('DELETE FROM ultracache_object_cache WHERE cache_id = :cache_id');
				$statement->bindValue(':cache_id', $this->get_sqlite_cache_id($key, $group), SQLITE3_TEXT);
				$result = $statement->execute();
				$this->sqlite_error = '';
				return true;
			} catch (Throwable $e) {
				$this->sqlite_error = $e->getMessage();
				return false;
			} finally {
				if ($result instanceof SQLite3Result) {
					$result->finalize();
				}
				if ($statement instanceof SQLite3Stmt) {
					$statement->close();
				}
			}
		}

		private function flush_sqlite_group($group) {
			if (!$this->sqlite_enabled || !($this->sqlite instanceof SQLite3)) {
				return;
			}
			$statement = null;
			$result = null;
			try {
				$statement = $this->sqlite->prepare('DELETE FROM ultracache_object_cache WHERE cache_scope = :cache_scope AND cache_group = :cache_group');
				$statement->bindValue(':cache_scope', $this->get_redis_scope($group), SQLITE3_TEXT);
				$statement->bindValue(':cache_group', $group, SQLITE3_TEXT);
				$result = $statement->execute();
				$this->sqlite_error = '';
			} catch (Throwable $e) {
				$this->sqlite_error = $e->getMessage();
			} finally {
				if ($result instanceof SQLite3Result) {
					$result->finalize();
				}
				if ($statement instanceof SQLite3Stmt) {
					$statement->close();
				}
			}
		}

		private function flush_sqlite_cache() {
			if (!$this->sqlite_enabled || !($this->sqlite instanceof SQLite3)) {
				return;
			}
			try {
				$this->sqlite->exec('DELETE FROM ultracache_object_cache');
				$this->sqlite->exec('PRAGMA wal_checkpoint(TRUNCATE)');
				$this->sqlite->exec('PRAGMA optimize');
				$this->sqlite_error = '';
				$this->harden_sqlite_file_permissions();
			} catch (Throwable $e) {
				$this->sqlite_error = $e->getMessage();
			}
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
			return ultracache_object_cache_safe_unlink($path);
		}

		private function write_redis_payload($key, $group, $payload, $expire) {
			if (!$this->is_redis_backend() || !is_array($payload)) {
				return false;
			}

			$value = $payload['value'] ?? null;
			if ($this->payload_contains_complex_types($value)) {
				$this->redis_payload_skip_reason = 'Redis payload skipped: unsupported resource/closure or excessive nesting.';
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
					$this->redis_payload_skip_reason = 'Redis payload skipped: envelope signing failed.';
					return false;
				}
				$data = serialize($envelope);
				if (!is_string($data) || '' === $data) {
					return false;
				}

				if (strlen($data) > $this->redis_payload_max_bytes) {
					$this->redis_payload_skip_reason = 'Redis payload skipped: value too large.';
					return false;
				}

				if ((int) $expire > 0) {
					$stored = (bool) $this->with_redis_error_handler(function () use ($redis_key, $expire, $data) {
						return $this->redis->setEx($redis_key, (int) $expire, $data);
					}, false);
					if ($stored) {
						$this->redis_error = '';
						$this->redis_payload_skip_reason = '';
					}
					return $stored;
				}
				$stored = (bool) $this->with_redis_error_handler(function () use ($redis_key, $data) {
					return $this->redis->set($redis_key, $data);
				}, false);
				if ($stored) {
					$this->redis_error = '';
					$this->redis_payload_skip_reason = '';
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
				$this->redis_payload_skip_reason = 'Redis payload skipped: serialized value too large.';
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
			if (!is_dir($dir) && !ultracache_object_cache_safe_mkdir($dir, 0700, true) && !is_dir($dir)) {
				return false;
			}
			$tmp = $path . '.tmp-' . uniqid('', true);
			$serialized = serialize($payload);
			$envelope = $this->build_signed_envelope($serialized);
			if (!is_array($envelope)) {
				return false;
			}
			$data = serialize($envelope);
			$result = ultracache_object_cache_safe_file_put_contents($tmp, $data, LOCK_EX);
			if (false === $result) {
				ultracache_object_cache_safe_unlink($tmp);
				return false;
			}
			if (!ultracache_object_cache_safe_rename($tmp, $path)) {
				ultracache_object_cache_safe_unlink($tmp);
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
			$data = ultracache_object_cache_safe_file_get_contents($path);
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
			$items = ultracache_object_cache_safe_scandir($this->cache_dir);
			if (!is_array($items)) {
				return;
			}
			foreach ($items as $item) {
				if ('.' === $item || '..' === $item || $this->should_preserve_cache_root_entry($item)) {
					continue;
				}
				$path = $this->cache_dir . DIRECTORY_SEPARATOR . $item;
				if (is_link($path) || !$this->is_cache_path($path)) {
					continue;
				}
				if (is_dir($path)) {
					$this->recursive_delete($path, true);
				} else {
					ultracache_object_cache_safe_unlink($path);
				}
			}
		}

		private function should_preserve_cache_root_entry($item) {
			$item = is_string($item) ? trim($item) : '';
			if ('' === $item) {
				return false;
			}
			$sqlite_name = basename($this->sqlite_path);
			return in_array($item, array('index.php', '.htaccess', 'web.config', 'object-cache-metrics.json', $sqlite_name, $sqlite_name . '-wal', $sqlite_name . '-shm', $sqlite_name . '.maintenance.lock'), true);
		}

		private function recursive_delete($dir, $remove_root = true) {
			if (!is_string($dir) || '' === trim($dir) || !is_dir($dir) || is_link($dir) || !$this->is_cache_path($dir)) {
				return;
			}
			$items = ultracache_object_cache_safe_scandir($dir);
			if (!is_array($items)) {
				return;
			}
			foreach ($items as $item) {
				if ('.' === $item || '..' === $item) {
					continue;
				}
				$path = $dir . DIRECTORY_SEPARATOR . $item;
				if (is_link($path) || !$this->is_cache_path($path)) {
					continue;
				}
				if (is_dir($path)) {
					$this->recursive_delete($path, true);
				} else {
					ultracache_object_cache_safe_unlink($path);
				}
			}
			$remaining = ultracache_object_cache_safe_scandir($dir);
			if (is_array($remaining)) {
				foreach ($remaining as $item) {
					if ('.' === $item || '..' === $item) {
						continue;
					}
					$path = $dir . DIRECTORY_SEPARATOR . $item;
					if (!is_link($path) && $this->is_cache_path($path) && is_file($path)) {
						ultracache_object_cache_safe_unlink($path);
					}
				}
			}
			if ($remove_root) {
				ultracache_object_cache_safe_rmdir($dir);
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
