<?php
/**
 * Redis backend for the generated UltraCache object-cache drop-in.
 */

defined('ABSPATH') || exit;

final class Ultra_Cache_Object_Cache_Redis_Backend extends Ultra_Cache_Object_Cache_Abstract_Backend {
	public function __construct(Ultra_Cache_Object_Cache_Backend_Context $context) {
		parent::__construct('redis', $context);
	}

	protected function read_persistent_payload($key, $group) {
		return $this->context->read_redis_payload($key, $group);
	}

	protected function write_persistent_payload($key, $group, $payload, $expire) {
		if ($this->context->write_redis_payload($key, $group, $payload, (int) $expire)) {
			return true;
		}
		$this->context->delete_redis_payload($key, $group);
		// Preserve the existing runtime-only success fallback after Redis write failure.
		return true;
	}
}
