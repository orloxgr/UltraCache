<?php
/**
 * SQLite backend for the generated UltraCache object-cache drop-in.
 */

defined('ABSPATH') || exit;

final class Ultra_Cache_Object_Cache_SQLite_Backend extends Ultra_Cache_Object_Cache_Abstract_Backend {
	public function __construct(Ultra_Cache_Object_Cache_Backend_Context $context) {
		parent::__construct('sqlite', $context);
	}

	protected function read_persistent_payload($key, $group) {
		return $this->context->read_sqlite_payload($key, $group);
	}

	protected function write_persistent_payload($key, $group, $payload, $expire) {
		return $this->context->write_sqlite_payload($key, $group, $payload);
	}

	public function add($key, $data, $group = 'default', $expire = 0) {
		$group = $this->context->normalize_group($group);
		$key = $this->context->normalize_key($key);

		if ($this->context->should_suspend_cache_addition()) {
			$found = false;
			$this->get($key, $group, true, $found);
			return !$found;
		}

		if ($this->context->is_non_persistent_group($group)) {
			if ($this->context->runtime_has($key, $group)) {
				return false;
			}
			$this->context->runtime_set($key, $group, $data);
			return true;
		}

		if ($this->context->runtime_has($key, $group)) {
			return false;
		}
		$payload = $this->context->build_payload($key, $group, $data, (int) $expire);
		$stored = $this->context->add_sqlite_payload($key, $group, $payload);
		if ($stored) {
			$this->context->runtime_set($key, $group, $data);
			return true;
		}

		$existing = $this->context->read_sqlite_payload($key, $group);
		if (is_array($existing) && array_key_exists('value', $existing)) {
			$this->context->runtime_set($key, $group, $existing['value']);
		} else {
			// Preserve request-local cache semantics without falsely reporting
			// that the persistent SQLite add succeeded.
			$this->context->runtime_set($key, $group, $data);
		}
		return false;
	}

	public function replace($key, $data, $group = 'default', $expire = 0) {
		$group = $this->context->normalize_group($group);
		$key = $this->context->normalize_key($key);

		if ($this->context->is_non_persistent_group($group)) {
			if (!$this->context->runtime_has($key, $group)) {
				return false;
			}
			$this->context->runtime_set($key, $group, $data);
			return true;
		}

		$payload = $this->context->build_payload($key, $group, $data, (int) $expire);
		$stored = $this->context->replace_sqlite_payload($key, $group, $payload);
		if ($stored) {
			$this->context->runtime_set($key, $group, $data);
		}
		return $stored;
	}

	public function incr($key, $offset = 1, $group = 'default') {
		$group = $this->context->normalize_group($group);
		$key = $this->context->normalize_key($key);
		if (!$this->context->is_non_persistent_group($group)) {
			return $this->context->mutate_sqlite_numeric_payload($key, $group, (int) $offset, false);
		}
		return parent::incr($key, $offset, $group);
	}

	public function decr($key, $offset = 1, $group = 'default') {
		$group = $this->context->normalize_group($group);
		$key = $this->context->normalize_key($key);
		if (!$this->context->is_non_persistent_group($group)) {
			return $this->context->mutate_sqlite_numeric_payload($key, $group, (int) $offset, true);
		}
		return parent::decr($key, $offset, $group);
	}
}
