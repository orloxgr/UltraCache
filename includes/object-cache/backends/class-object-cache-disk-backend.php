<?php
/**
 * Disk backend for the generated UltraCache object-cache drop-in.
 */

defined('ABSPATH') || exit;

final class Ultra_Cache_Object_Cache_Disk_Backend extends Ultra_Cache_Object_Cache_Abstract_Backend {
	private $runtime_dirty = array();

	public function __construct(Ultra_Cache_Object_Cache_Backend_Context $context) {
		parent::__construct('disk', $context);
	}

	private function get_runtime_dirty_id($key, $group) {
		return $this->context->get_runtime_scope($group) . "\0" . (string) $group . "\0" . (string) $key;
	}

	private function mark_runtime_dirty($key, $group) {
		$this->runtime_dirty[$this->get_runtime_dirty_id($key, $group)] = true;
	}

	private function clear_runtime_dirty($key, $group) {
		unset($this->runtime_dirty[$this->get_runtime_dirty_id($key, $group)]);
	}

	private function clear_runtime_dirty_group($group) {
		$prefix = $this->context->get_runtime_scope($group) . "\0" . (string) $group . "\0";
		foreach (array_keys($this->runtime_dirty) as $dirty_id) {
			if (0 === strpos((string) $dirty_id, $prefix)) {
				unset($this->runtime_dirty[$dirty_id]);
			}
		}
	}

	private function clear_runtime_dirty_local_scopes() {
		foreach (array_keys($this->runtime_dirty) as $dirty_id) {
			if (0 !== strpos((string) $dirty_id, "global\0")) {
				unset($this->runtime_dirty[$dirty_id]);
			}
		}
	}

	private function is_runtime_dirty($key, $group) {
		return !empty($this->runtime_dirty[$this->get_runtime_dirty_id($key, $group)]);
	}

	protected function read_persistent_payload($key, $group) {
		if ($this->is_runtime_dirty($key, $group)) {
			return false;
		}
		return $this->context->read_disk_payload($key, $group);
	}

	protected function write_persistent_payload($key, $group, $payload, $expire) {
		$status = $this->context->set_disk_payload($key, $group, $payload);
		if ('stored' === $status) {
			$this->clear_runtime_dirty($key, $group);
			return true;
		}
		if ('unsupported' === $status || 'unavailable' === $status) {
			$this->mark_runtime_dirty($key, $group);
			return true;
		}
		return false;
	}

	public function set($key, $data, $group = 'default', $expire = 0) {
		$group = $this->context->normalize_group($group);
		$key = $this->context->normalize_key($key);

		if ($this->context->is_non_persistent_group($group)) {
			$this->context->runtime_set($key, $group, $data);
			return true;
		}

		$payload = $this->context->build_payload($key, $group, $data, (int) $expire);
		if (!is_array($payload)) {
			return false;
		}

		$status = $this->context->set_disk_payload($key, $group, $payload);
		if ('stored' === $status) {
			$this->clear_runtime_dirty($key, $group);
			$this->context->runtime_set($key, $group, $data);
			return true;
		}
		if ('unsupported' === $status) {
			$this->mark_runtime_dirty($key, $group);
			$this->context->runtime_set($key, $group, $data);
			return true;
		}
		return false;
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

		$payload = $this->context->build_payload($key, $group, $data, (int) $expire);
		if (!is_array($payload)) {
			return false;
		}

		$status = $this->context->add_disk_payload($key, $group, $payload);
		if ('stored' === $status) {
			$this->clear_runtime_dirty($key, $group);
			$this->context->runtime_set($key, $group, $data);
			return true;
		}
		if ('unsupported' === $status) {
			$this->mark_runtime_dirty($key, $group);
			$this->context->runtime_set($key, $group, $data);
			return true;
		}
		return false;
	}

	public function replace($key, $data, $group = 'default', $expire = 0) {
		$group = $this->context->normalize_group($group);
		$key = $this->context->normalize_key($key);
		$runtime_exists = $this->context->runtime_has($key, $group);

		if ($this->context->is_non_persistent_group($group)) {
			if (!$runtime_exists) {
				return false;
			}
			$this->context->runtime_set($key, $group, $data);
			return true;
		}

		$payload = $this->context->build_payload($key, $group, $data, (int) $expire);
		if (!is_array($payload)) {
			return false;
		}

		$status = $this->context->replace_disk_payload($key, $group, $payload);
		if ('stored' === $status) {
			$this->clear_runtime_dirty($key, $group);
			$this->context->runtime_set($key, $group, $data);
			return true;
		}
		if ('unsupported_existing' === $status) {
			$this->mark_runtime_dirty($key, $group);
			$this->context->runtime_set($key, $group, $data);
			return true;
		}
		if ('missing' === $status && $runtime_exists) {
			$this->mark_runtime_dirty($key, $group);
			$this->context->runtime_set($key, $group, $data);
			return true;
		}
		return false;
	}

	private function mutate_numeric($key, $offset, $group, $decrement) {
		$group = $this->context->normalize_group($group);
		$key = $this->context->normalize_key($key);

		if ($this->context->is_non_persistent_group($group)) {
			return $decrement
				? parent::decr($key, (int) $offset, $group)
				: parent::incr($key, (int) $offset, $group);
		}

		$runtime_present = $this->context->runtime_has($key, $group);
		$runtime_value = $runtime_present ? $this->context->runtime_get($key, $group) : null;
		$result = $this->context->mutate_disk_numeric_payload(
			$key,
			$group,
			(int) $offset,
			(bool) $decrement,
			$runtime_present,
			$runtime_value,
			$runtime_present && $this->is_runtime_dirty($key, $group)
		);
		$status = isset($result['status']) ? (string) $result['status'] : 'unavailable';

		if ('stored' === $status && array_key_exists('value', $result)) {
			$this->clear_runtime_dirty($key, $group);
			$this->context->record_hit();
			$this->context->runtime_set($key, $group, $result['value']);
			return $result['value'];
		}

		if ('unavailable' === $status && $runtime_present) {
			$this->mark_runtime_dirty($key, $group);
			$value = is_numeric($runtime_value) ? $runtime_value : 0;
			$value = $decrement ? ($value - (int) $offset) : ($value + (int) $offset);
			if ($value < 0) {
				$value = 0;
			}
			$this->context->record_hit();
			$this->context->runtime_set($key, $group, $value);
			return $value;
		}

		if ('missing' === $status || ('unavailable' === $status && !$runtime_present)) {
			$this->context->record_miss();
		}
		return false;
	}

	public function incr($key, $offset = 1, $group = 'default') {
		return $this->mutate_numeric($key, (int) $offset, $group, false);
	}

	public function decr($key, $offset = 1, $group = 'default') {
		return $this->mutate_numeric($key, (int) $offset, $group, true);
	}

	public function delete($key, $group = 'default') {
		$group = $this->context->normalize_group($group);
		$key = $this->context->normalize_key($key);
		$runtime_exists = $this->context->runtime_has($key, $group);

		if ($this->context->is_non_persistent_group($group)) {
			if (!$runtime_exists) {
				return false;
			}
			$this->context->runtime_delete($key, $group);
			return true;
		}

		$status = $this->context->delete_disk_payload_status($key, $group);
		if ('unavailable' === $status) {
			return false;
		}
		$persistent_deleted = ('deleted' === $status);
		if (!$runtime_exists && !$persistent_deleted) {
			return false;
		}
		$this->context->runtime_delete($key, $group);
		$this->clear_runtime_dirty($key, $group);
		return true;
	}

	public function flush() {
		$this->runtime_dirty = array();
		return parent::flush();
	}

	public function flush_runtime() {
		$this->runtime_dirty = array();
		return parent::flush_runtime();
	}

	public function reset_runtime() {
		$this->clear_runtime_dirty_local_scopes();
		return parent::reset_runtime();
	}

	public function flush_group($group) {
		$group = $this->context->normalize_group($group);
		$this->clear_runtime_dirty_group($group);
		return parent::flush_group($group);
	}
}
