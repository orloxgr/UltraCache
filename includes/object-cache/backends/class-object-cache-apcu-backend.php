<?php
/**
 * APCu backend for the generated UltraCache object-cache drop-in.
 */

defined('ABSPATH') || exit;

final class Ultra_Cache_Object_Cache_APCu_Backend extends Ultra_Cache_Object_Cache_Abstract_Backend {
	public function __construct(Ultra_Cache_Object_Cache_Backend_Context $context) {
		parent::__construct('apcu', $context);
	}

	protected function read_persistent_payload($key, $group) {
		return $this->context->read_apcu_payload($key, $group);
	}

	protected function write_persistent_payload($key, $group, $payload, $expire) {
		$this->context->write_apcu_payload($key, $group, $payload, (int) $expire);
		// Preserve the existing request-local success fallback after APCu write failure.
		return true;
	}
}
