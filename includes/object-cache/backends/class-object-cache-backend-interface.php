<?php
/**
 * Internal contract for generated UltraCache object-cache backends.
 */

defined('ABSPATH') || exit;

interface Ultra_Cache_Object_Cache_Backend_Interface {
	public function get_name();
	public function get($key, $group = 'default', $force = false, &$found = null);
	public function set($key, $data, $group = 'default', $expire = 0);
	public function add($key, $data, $group = 'default', $expire = 0);
	public function replace($key, $data, $group = 'default', $expire = 0);
	public function delete($key, $group = 'default');
	public function incr($key, $offset = 1, $group = 'default');
	public function decr($key, $offset = 1, $group = 'default');
	public function flush();
	public function flush_runtime();
	public function reset_runtime();
	public function flush_group($group);
	public function health();
}
