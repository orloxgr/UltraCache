<?php
/**
 * Request-local fallback backend for the generated UltraCache drop-in.
 */

defined('ABSPATH') || exit;

final class Ultra_Cache_Object_Cache_Runtime_Backend extends Ultra_Cache_Object_Cache_Abstract_Backend {
	public function __construct(Ultra_Cache_Object_Cache_Backend_Context $context) {
		parent::__construct('runtime', $context);
	}
}
