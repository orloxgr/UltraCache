<?php
/**
 * Compatibility facade for the UltraCache Media Library replacement service.
 */

defined('ABSPATH') || exit;

trait Ultra_Cache_Media_Replacement_Facade_Trait {

	/**
	 * Media Library replacement service.
	 *
	 * @var Ultra_Cache_Media_Replacement_Manager|null
	 */
	private $media_replacement_manager = null;

	/**
	 * Load the Media Library replacement service implementation on demand.
	 *
	 * The compatibility facade remains available on every media converter load,
	 * while the manager and its large implementation traits are parsed only when
	 * a dashboard REST, WP-CLI, cron, or direct replacement call actually needs them.
	 *
	 * @return void
	 */
	private function load_media_replacement_manager_class() {
		if (class_exists('Ultra_Cache_Media_Replacement_Manager', false)) {
			return;
		}

		require_once ultracache_plugin_dir('includes/media/class-media-replacement-manager.php');

		if (!class_exists('Ultra_Cache_Media_Replacement_Manager', false)) {
			throw new RuntimeException('UltraCache Media Library replacement service could not be loaded.');
		}
	}

	/**
	 * Get the Media Library replacement service.
	 *
	 * @return Ultra_Cache_Media_Replacement_Manager
	 */
	private function get_media_replacement_manager() {
		$this->load_media_replacement_manager_class();

		if (!$this->media_replacement_manager instanceof Ultra_Cache_Media_Replacement_Manager) {
			$this->media_replacement_manager = new Ultra_Cache_Media_Replacement_Manager(
				array(
					'get_avif_path_from_source'                     => function ($source_file) {
						return $this->get_avif_path_from_source($source_file);
					},
					'get_media_fallback_format'                     => function () {
						return $this->get_media_fallback_format();
					},
					'get_media_output_mode'                         => function () {
						return $this->get_media_output_mode();
					},
					'get_media_queue_table_name'                    => function () {
						return $this->get_media_queue_table_name();
					},
					'get_uploads_relative_path_from_source'         => function ($source_file) {
						return $this->get_uploads_relative_path_from_source($source_file);
					},
					'get_webp_path_from_source'                     => function ($source_file) {
						return $this->get_webp_path_from_source($source_file);
					},
					'is_valid_generated_media_file'                 => function ($file, $format, $context = '') {
						return $this->is_valid_generated_media_file($file, $format, $context);
					},
					'media_queue_table_exists'                      => function () {
						return $this->media_queue_table_exists();
					},
					'optimized_storage_ensure_directory'            => function ($dir) {
						return $this->optimized_storage_ensure_directory($dir);
					},
					'optimized_storage_filesystem'                  => function () {
						return $this->optimized_storage_filesystem();
					},
					'optimized_storage_forget_path'                 => function ($path) {
						return $this->optimized_storage_forget_path($path);
					},
					'optimized_storage_harden_upload_permissions'   => function ($path, $type = 'file') {
						return $this->optimized_storage_harden_upload_permissions($path, $type);
					},
					'optimized_storage_path_exists'                 => function ($path, $refresh = false) {
						return $this->optimized_storage_path_exists($path, $refresh);
					},
					'optimized_storage_readable_source_exists'      => function ($path) {
						return $this->optimized_storage_readable_source_exists($path);
					},
					'path_is_within_root'                           => function ($path, $root) {
						return $this->path_is_within_root($path, $root);
					},
				)
			);
		}

		return $this->media_replacement_manager;
	}

	public function prepare_media_library_replacement_foundation($args = array()) {
		return $this->get_media_replacement_manager()->prepare_media_library_replacement_foundation($args);
	}

	public function get_media_library_replacement_cleanup_preview($args = array()) {
		return $this->get_media_replacement_manager()->get_media_library_replacement_cleanup_preview($args);
	}

	public function apply_media_library_replacement_cleanup($args = array()) {
		return $this->get_media_replacement_manager()->apply_media_library_replacement_cleanup($args);
	}

	public function scan_media_library_replacement_database_references($args = array()) {
		return $this->get_media_replacement_manager()->scan_media_library_replacement_database_references($args);
	}

	public function match_media_library_replacement_database_references($args = array()) {
		return $this->get_media_replacement_manager()->match_media_library_replacement_database_references($args);
	}

	public function apply_media_library_replacement_database_replacements($args = array()) {
		return $this->get_media_replacement_manager()->apply_media_library_replacement_database_replacements($args);
	}

	public function verify_media_library_replacement_database_replacements($args = array()) {
		return $this->get_media_replacement_manager()->verify_media_library_replacement_database_replacements($args);
	}

	public function rollback_media_library_replacement_database_replacements($args = array()) {
		return $this->get_media_replacement_manager()->rollback_media_library_replacement_database_replacements($args);
	}

	public function get_media_library_replacement_database_replacement_preview($args = array()) {
		return $this->get_media_replacement_manager()->get_media_library_replacement_database_replacement_preview($args);
	}

	public function copy_media_library_replacement_files($args = array()) {
		return $this->get_media_replacement_manager()->copy_media_library_replacement_files($args);
	}

	public function prepare_media_library_replacement_metadata_updates($args = array()) {
		return $this->get_media_replacement_manager()->prepare_media_library_replacement_metadata_updates($args);
	}

	public function apply_media_library_replacement_metadata_updates($args = array()) {
		return $this->get_media_replacement_manager()->apply_media_library_replacement_metadata_updates($args);
	}

	public function rollback_media_library_replacement_metadata_updates($args = array()) {
		return $this->get_media_replacement_manager()->rollback_media_library_replacement_metadata_updates($args);
	}

	public function get_media_library_replacement_start_guard($args = array()) {
		return $this->get_media_replacement_manager()->get_media_library_replacement_start_guard($args);
	}

	public function get_media_library_replacement_readiness_status() {
		return $this->get_media_replacement_manager()->get_media_library_replacement_readiness_status();
	}

	public function scan_media_library_replacement_readiness_inventory($args = array()) {
		return $this->get_media_replacement_manager()->scan_media_library_replacement_readiness_inventory($args);
	}

	public function scan_media_library_replacement_eligible_items($args = array()) {
		return $this->get_media_replacement_manager()->scan_media_library_replacement_eligible_items($args);
	}

	public function expand_media_library_replacement_intermediate_sizes($args = array()) {
		return $this->get_media_replacement_manager()->expand_media_library_replacement_intermediate_sizes($args);
	}

	public function ensure_media_replacement_tables() {
		return $this->get_media_replacement_manager()->ensure_media_replacement_tables();
	}

	public function get_media_library_replacement_session_status() {
		return $this->get_media_replacement_manager()->get_media_library_replacement_session_status();
	}

	public function restart_media_library_replacement_workflow() {
		return $this->get_media_replacement_manager()->restart_media_library_replacement_workflow();
	}

	public function manage_media_library_replacement_session($action, $token = '', $active_step = 'readiness', $owner = 'dashboard') {
		return $this->get_media_replacement_manager()->manage_media_library_replacement_session($action, $token, $active_step, $owner);
	}

	public function scan_media_library_replacement_theme_css_references($args = array()) {
		return $this->get_media_replacement_manager()->scan_media_library_replacement_theme_css_references($args);
	}

	public function get_media_library_replacement_theme_css_replacement_preview($args = array()) {
		return $this->get_media_replacement_manager()->get_media_library_replacement_theme_css_replacement_preview($args);
	}

	public function apply_media_library_replacement_theme_css_replacements($args = array()) {
		return $this->get_media_replacement_manager()->apply_media_library_replacement_theme_css_replacements($args);
	}

	public function verify_media_library_replacement_theme_css_replacements($args = array()) {
		return $this->get_media_replacement_manager()->verify_media_library_replacement_theme_css_replacements($args);
	}

	public function set_media_library_replacement_workflow_stage($args = array()) {
		return $this->get_media_replacement_manager()->set_media_library_replacement_workflow_stage($args);
	}

	public function get_media_library_replacement_workflow_status($args = array()) {
		return $this->get_media_replacement_manager()->get_media_library_replacement_workflow_status($args);
	}

	public function get_media_library_replacement_mapping_preview($args = array()) {
		return $this->get_media_replacement_manager()->get_media_library_replacement_mapping_preview($args);
	}

	public function get_media_library_replacement_pre_do_guard($args = array()) {
		return $this->get_media_replacement_manager()->get_media_library_replacement_pre_do_guard($args);
	}

	public function get_media_library_replacement_prepare_status() {
		return $this->get_media_replacement_manager()->get_media_library_replacement_prepare_status();
	}

	public function run_media_library_replacement_prepare_chunk($args = array()) {
		return $this->get_media_replacement_manager()->run_media_library_replacement_prepare_chunk($args);
	}

	public function get_media_library_replacement_do_status() {
		return $this->get_media_replacement_manager()->get_media_library_replacement_do_status();
	}

	public function run_media_library_replacement_do_chunk($args = array()) {
		return $this->get_media_replacement_manager()->run_media_library_replacement_do_chunk($args);
	}

	public function get_media_library_replacement_verify_status() {
		return $this->get_media_replacement_manager()->get_media_library_replacement_verify_status();
	}

	public function run_media_library_replacement_verify_chunk($args = array()) {
		return $this->get_media_replacement_manager()->run_media_library_replacement_verify_chunk($args);
	}

	public function get_media_library_replacement_delete_status() {
		return $this->get_media_replacement_manager()->get_media_library_replacement_delete_status();
	}

	public function confirm_media_library_replacement_delete($args = array()) {
		return $this->get_media_replacement_manager()->confirm_media_library_replacement_delete($args);
	}

	public function run_media_library_replacement_delete_chunk($args = array()) {
		return $this->get_media_replacement_manager()->run_media_library_replacement_delete_chunk($args);
	}
}
