<?php
/**
 * Explicit bridge between generated backend classes and WP_Object_Cache internals.
 */

defined('ABSPATH') || exit;

final class Ultra_Cache_Object_Cache_Backend_Context {
	private $operations = array();

	public function __construct(array $operations) {
		$this->operations = $operations;
	}

	private function invoke($operation, array $arguments = array(), $default = false) {
		$callback = isset($this->operations[$operation]) && is_callable($this->operations[$operation])
			? $this->operations[$operation]
			: null;
		return $callback ? call_user_func_array($callback, $arguments) : $default;
	}

	public function normalize_group($group) {
		return (string) $this->invoke('normalize_group', array($group), 'default');
	}

	public function normalize_key($key) {
		return (string) $this->invoke('normalize_key', array($key), '');
	}

	public function should_suspend_cache_addition() {
		return (bool) $this->invoke('should_suspend_cache_addition', array(), false);
	}

	public function is_non_persistent_group($group) {
		return (bool) $this->invoke('is_non_persistent_group', array($group), false);
	}

	public function runtime_has($key, $group) {
		return (bool) $this->invoke('runtime_has', array($key, $group), false);
	}

	public function runtime_get($key, $group) {
		return $this->invoke('runtime_get', array($key, $group), false);
	}

	public function runtime_set($key, $group, $value) {
		return (bool) $this->invoke('runtime_set', array($key, $group, $value), true);
	}

	public function runtime_delete($key, $group) {
		return (bool) $this->invoke('runtime_delete', array($key, $group), true);
	}

	public function runtime_clear() {
		return (bool) $this->invoke('runtime_clear', array(), true);
	}

	public function runtime_clear_group($group) {
		return (bool) $this->invoke('runtime_clear_group', array($group), true);
	}

	public function build_payload($key, $group, $data, $expire) {
		return $this->invoke('build_payload', array($key, $group, $data, $expire), false);
	}

	public function record_hit() {
		$this->invoke('record_hit', array(), null);
	}

	public function record_miss() {
		$this->invoke('record_miss', array(), null);
	}

	public function delete_persistent_payload($key, $group) {
		return (bool) $this->invoke('delete_persistent_payload', array($key, $group), true);
	}

	public function flush_persistent_cache() {
		return (bool) $this->invoke('flush_persistent_cache', array(), true);
	}

	public function flush_persistent_group($group) {
		return (bool) $this->invoke('flush_persistent_group', array($group), true);
	}

	public function after_flush() {
		$this->invoke('after_flush', array(), null);
	}

	public function health() {
		$health = $this->invoke('health', array(), array());
		return is_array($health) ? $health : array();
	}

	public function read_redis_payload($key, $group) {
		return $this->invoke('read_redis_payload', array($key, $group), false);
	}

	public function write_redis_payload($key, $group, $payload, $expire) {
		return (bool) $this->invoke('write_redis_payload', array($key, $group, $payload, $expire), false);
	}

	public function delete_redis_payload($key, $group) {
		return (bool) $this->invoke('delete_redis_payload', array($key, $group), true);
	}

	public function read_apcu_payload($key, $group) {
		return $this->invoke('read_apcu_payload', array($key, $group), false);
	}

	public function write_apcu_payload($key, $group, $payload, $expire) {
		return (bool) $this->invoke('write_apcu_payload', array($key, $group, $payload, $expire), false);
	}

	public function read_sqlite_payload($key, $group) {
		return $this->invoke('read_sqlite_payload', array($key, $group), false);
	}

	public function write_sqlite_payload($key, $group, $payload) {
		return (bool) $this->invoke('write_sqlite_payload', array($key, $group, $payload), false);
	}

	public function add_sqlite_payload($key, $group, $payload) {
		return (bool) $this->invoke('add_sqlite_payload', array($key, $group, $payload), false);
	}

	public function replace_sqlite_payload($key, $group, $payload) {
		return (bool) $this->invoke('replace_sqlite_payload', array($key, $group, $payload), false);
	}

	public function mutate_sqlite_numeric_payload($key, $group, $offset, $decrement) {
		return $this->invoke('mutate_sqlite_numeric_payload', array($key, $group, $offset, $decrement), false);
	}

	public function read_disk_payload($key, $group) {
		return $this->invoke('read_disk_payload', array($key, $group), false);
	}

	public function write_disk_payload($key, $group, $payload) {
		return (bool) $this->invoke('write_disk_payload', array($key, $group, $payload), false);
	}
}
