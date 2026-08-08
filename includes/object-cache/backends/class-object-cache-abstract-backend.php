<?php
/**
 * Shared WordPress object-cache semantics for generated persistent backends.
 */

defined('ABSPATH') || exit;

abstract class Ultra_Cache_Object_Cache_Abstract_Backend implements Ultra_Cache_Object_Cache_Backend_Interface {
	protected $context;
	private $name = '';

	public function __construct($name, Ultra_Cache_Object_Cache_Backend_Context $context) {
		$this->name = strtolower(trim((string) $name));
		$this->context = $context;
	}

	public function get_name() {
		return (string) $this->name;
	}

	protected function read_persistent_payload($key, $group) {
		return false;
	}

	protected function write_persistent_payload($key, $group, $payload, $expire) {
		return true;
	}

	public function get($key, $group = 'default', $force = false, &$found = null) {
		$group = $this->context->normalize_group($group);
		$key = $this->context->normalize_key($key);

		if ($this->context->is_non_persistent_group($group)) {
			if ($this->context->runtime_has($key, $group)) {
				$found = true;
				$this->context->record_hit();
				return $this->context->runtime_get($key, $group);
			}
			$found = false;
			$this->context->record_miss();
			return false;
		}

		if (!$force && $this->context->runtime_has($key, $group)) {
			$found = true;
			$this->context->record_hit();
			return $this->context->runtime_get($key, $group);
		}

		$payload = $this->read_persistent_payload($key, $group);
		if (!is_array($payload) || !array_key_exists('value', $payload)) {
			$found = false;
			$this->context->record_miss();
			return false;
		}

		if (!empty($payload['expires_at']) && (int) $payload['expires_at'] <= time()) {
			$this->context->runtime_delete($key, $group);
			$this->context->delete_persistent_payload($key, $group);
			$found = false;
			$this->context->record_miss();
			return false;
		}

		$found = true;
		$this->context->record_hit();
		$this->context->runtime_set($key, $group, $payload['value']);
		return $this->context->runtime_get($key, $group);
	}

	public function set($key, $data, $group = 'default', $expire = 0) {
		$group = $this->context->normalize_group($group);
		$key = $this->context->normalize_key($key);

		$this->context->runtime_set($key, $group, $data);
		if ($this->context->is_non_persistent_group($group)) {
			return true;
		}

		$payload = $this->context->build_payload($key, $group, $data, (int) $expire);
		if (!is_array($payload)) {
			return false;
		}
		return $this->write_persistent_payload($key, $group, $payload, (int) $expire);
	}

	public function add($key, $data, $group = 'default', $expire = 0) {
		$group = $this->context->normalize_group($group);
		$key = $this->context->normalize_key($key);

		if ($this->context->should_suspend_cache_addition()) {
			return false;
		}

		if ($this->context->runtime_has($key, $group)) {
			return false;
		}

		if ($this->context->is_non_persistent_group($group)) {
			$this->context->runtime_set($key, $group, $data);
			return true;
		}

		$found = false;
		$this->get($key, $group, true, $found);
		if ($found) {
			return false;
		}
		return $this->set($key, $data, $group, (int) $expire);
	}

	public function replace($key, $data, $group = 'default', $expire = 0) {
		$group = $this->context->normalize_group($group);
		$key = $this->context->normalize_key($key);

		if ($this->context->runtime_has($key, $group)) {
			return $this->set($key, $data, $group, (int) $expire);
		}

		if ($this->context->is_non_persistent_group($group)) {
			return false;
		}

		$found = false;
		$this->get($key, $group, true, $found);
		if (!$found) {
			return false;
		}
		return $this->set($key, $data, $group, (int) $expire);
	}

	public function delete($key, $group = 'default') {
		$group = $this->context->normalize_group($group);
		$key = $this->context->normalize_key($key);
		$runtime_exists = $this->context->runtime_has($key, $group);

		if (!$runtime_exists) {
			if ($this->context->is_non_persistent_group($group)) {
				return false;
			}

			$payload = $this->read_persistent_payload($key, $group);
			if (!is_array($payload) || !array_key_exists('value', $payload)) {
				return false;
			}
			if (!empty($payload['expires_at']) && (int) $payload['expires_at'] <= time()) {
				$this->context->delete_persistent_payload($key, $group);
				return false;
			}
		}

		$this->context->runtime_delete($key, $group);
		if ($this->context->is_non_persistent_group($group)) {
			return true;
		}
		return $this->context->delete_persistent_payload($key, $group);
	}

	public function incr($key, $offset = 1, $group = 'default') {
		$group = $this->context->normalize_group($group);
		$key = $this->context->normalize_key($key);

		$found = $this->context->runtime_has($key, $group);
		if ($found) {
			$this->context->record_hit();
			$value = $this->context->runtime_get($key, $group);
		} else {
			$value = $this->get($key, $group, true, $found);
		}
		if (!$found) {
			return false;
		}
		if (!is_numeric($value)) {
			$value = 0;
		}
		$value += (int) $offset;
		if ($value < 0) {
			$value = 0;
		}
		if (!$this->set($key, $value, $group)) {
			return false;
		}
		return $value;
	}

	public function decr($key, $offset = 1, $group = 'default') {
		$group = $this->context->normalize_group($group);
		$key = $this->context->normalize_key($key);

		$found = $this->context->runtime_has($key, $group);
		if ($found) {
			$this->context->record_hit();
			$value = $this->context->runtime_get($key, $group);
		} else {
			$value = $this->get($key, $group, true, $found);
		}
		if (!$found) {
			return false;
		}
		if (!is_numeric($value)) {
			$value = 0;
		}
		$value -= (int) $offset;
		if ($value < 0) {
			$value = 0;
		}
		if (!$this->set($key, $value, $group)) {
			return false;
		}
		return $value;
	}

	public function flush() {
		$this->context->runtime_clear();
		$this->context->flush_persistent_cache();
		$this->context->after_flush();
		return true;
	}

	public function flush_runtime() {
		return $this->context->runtime_clear();
	}

	public function reset_runtime() {
		return $this->context->runtime_reset();
	}

	public function flush_group($group) {
		$group = $this->context->normalize_group($group);
		$this->context->runtime_clear_group($group);
		$this->context->flush_persistent_group($group);
		return true;
	}

	public function health() {
		$health = $this->context->health();
		$health['backend'] = (string) $this->name;
		return $health;
	}
}
