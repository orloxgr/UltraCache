<?php
/**
 * Disk backend for the generated UltraCache object-cache drop-in.
 */

defined('ABSPATH') || exit;

final class Ultra_Cache_Object_Cache_Disk_Backend extends Ultra_Cache_Object_Cache_Abstract_Backend {
	public function __construct(Ultra_Cache_Object_Cache_Backend_Context $context) {
		parent::__construct('disk', $context);
	}

	protected function read_persistent_payload($key, $group) {
		return $this->context->read_disk_payload($key, $group);
	}

	protected function write_persistent_payload($key, $group, $payload, $expire) {
		return $this->context->write_disk_payload($key, $group, $payload);
	}
}
