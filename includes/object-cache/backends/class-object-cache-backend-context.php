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

	public function get_runtime_scope($group) {
		return (string) $this->invoke('get_runtime_scope', array($group), 'global');
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

	public function runtime_reset() {
		return (bool) $this->invoke('runtime_reset', array(), true);
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

	public function add_redis_payload($key, $group, $payload, $expire) {
		$status = $this->invoke('add_redis_payload', array($key, $group, $payload, $expire), 'unavailable');
		return is_string($status) ? $status : 'unavailable';
	}

	public function replace_redis_payload($key, $group, $payload, $expire) {
		$status = $this->invoke('replace_redis_payload', array($key, $group, $payload, $expire), 'unavailable');
		return is_string($status) ? $status : 'unavailable';
	}

	public function mutate_redis_numeric_payload($key, $group, $offset, $decrement, $runtime_present = false, $runtime_value = null, $runtime_authoritative = false) {
		$result = $this->invoke(
			'mutate_redis_numeric_payload',
			array($key, $group, (int) $offset, (bool) $decrement, (bool) $runtime_present, $runtime_value, (bool) $runtime_authoritative),
			array('status' => 'unavailable')
		);
		return is_array($result) ? $result : array('status' => 'unavailable');
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

	public function add_apcu_payload($key, $group, $payload, $expire) {
		$status = $this->invoke('add_apcu_payload', array($key, $group, $payload, $expire), 'unavailable');
		return is_string($status) ? $status : 'unavailable';
	}

	public function replace_apcu_payload($key, $group, $payload, $expire) {
		$status = $this->invoke('replace_apcu_payload', array($key, $group, $payload, $expire), 'unavailable');
		return is_string($status) ? $status : 'unavailable';
	}

	public function mutate_apcu_numeric_payload($key, $group, $offset, $decrement, $runtime_present = false, $runtime_value = null, $runtime_authoritative = false) {
		$result = $this->invoke(
			'mutate_apcu_numeric_payload',
			array($key, $group, (int) $offset, (bool) $decrement, (bool) $runtime_present, $runtime_value, (bool) $runtime_authoritative),
			array('status' => 'unavailable')
		);
		return is_array($result) ? $result : array('status' => 'unavailable');
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

	public function mutate_sqlite_numeric_payload($key, $group, $offset, $decrement, $runtime_present = false, $runtime_value = null) {
		return $this->invoke(
			'mutate_sqlite_numeric_payload',
			array($key, $group, $offset, $decrement, (bool) $runtime_present, $runtime_value),
			false
		);
	}

	public function read_disk_payload($key, $group) {
		return $this->invoke('read_disk_payload', array($key, $group), false);
	}

	public function write_disk_payload($key, $group, $payload) {
		return (bool) $this->invoke('write_disk_payload', array($key, $group, $payload), false);
	}

	public function set_disk_payload($key, $group, $payload) {
		$status = $this->invoke('set_disk_payload', array($key, $group, $payload), 'unavailable');
		return is_string($status) ? $status : 'unavailable';
	}

	public function add_disk_payload($key, $group, $payload) {
		$status = $this->invoke('add_disk_payload', array($key, $group, $payload), 'unavailable');
		return is_string($status) ? $status : 'unavailable';
	}

	public function replace_disk_payload($key, $group, $payload) {
		$status = $this->invoke('replace_disk_payload', array($key, $group, $payload), 'unavailable');
		return is_string($status) ? $status : 'unavailable';
	}

	public function mutate_disk_numeric_payload($key, $group, $offset, $decrement, $runtime_present = false, $runtime_value = null, $runtime_authoritative = false) {
		$result = $this->invoke(
			'mutate_disk_numeric_payload',
			array($key, $group, (int) $offset, (bool) $decrement, (bool) $runtime_present, $runtime_value, (bool) $runtime_authoritative),
			array('status' => 'unavailable')
		);
		return is_array($result) ? $result : array('status' => 'unavailable');
	}

	public function delete_disk_payload_status($key, $group) {
		$status = $this->invoke('delete_disk_payload_status', array($key, $group), 'unavailable');
		return is_string($status) ? $status : 'unavailable';
	}
}
