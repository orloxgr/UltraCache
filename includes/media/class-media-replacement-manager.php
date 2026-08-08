<?php
/**
 * Media Library replacement service for UltraCache.
 */

defined('ABSPATH') || exit;

require_once ultracache_plugin_dir('includes/media/class-media-replacement-trait.php');

final class Ultra_Cache_Media_Replacement_Manager {

	use Ultra_Cache_Media_Replacement_Trait;

	/** Persistent Media Library replacement table version. */
	const MEDIA_REPLACEMENT_DB_VERSION = '15';

	/** Media Library replacement orchestration contract version. */
	const MEDIA_REPLACEMENT_ORCHESTRATION_VERSION = 8;

	/** Short-lived lock protecting readiness inventory cursor and counters from concurrent chunks. */
	const MEDIA_REPLACEMENT_READINESS_LOCK = 'ultracache_media_replacement_readiness_lock_v1';

	/** Lifetime of a destructive Media Replacement start-confirmation token. */
	const MEDIA_REPLACEMENT_CONFIRMATION_TTL = 600;

	/** Token-owned dashboard lease for the resumable Media Library replacement workflow. */
	const MEDIA_REPLACEMENT_MANUAL_SESSION_LOCK = 'ultracache_media_replacement_manual_session_v1';

	/** Dashboard replacement lease lifetime. Each successful chunk renews it. */
	const MEDIA_REPLACEMENT_MANUAL_SESSION_TTL = 120;

	/** Backward-compatible name for the shared destructive confirmation TTL. */
	const MEDIA_REPLACEMENT_DELETE_CONFIRMATION_TTL = self::MEDIA_REPLACEMENT_CONFIRMATION_TTL;

	/** Persistent Media Library replacement database version option. */
	const MEDIA_REPLACEMENT_DB_VERSION_OPTION = 'ultracache_media_replacement_db_version';

	/** Shared database lock serializing Media Library replacement schema upgrades. */
	const MEDIA_REPLACEMENT_SCHEMA_LOCK = 'ultracache_media_replacement_schema_lock_v1';

	/** Maximum lifetime of a Media Library replacement schema-upgrade lock. */
	const MEDIA_REPLACEMENT_SCHEMA_LOCK_TTL = 60;

	/**
	 * Media converter dependencies used by the replacement service.
	 *
	 * @var array<string,callable>
	 */
	private $dependencies = array();

	/**
	 * Required media converter dependency names.
	 *
	 * @var string[]
	 */
	private const REQUIRED_DEPENDENCIES = array(
		'get_avif_path_from_source',
		'get_media_replacement_format',
		'get_media_queue_table_name',
		'get_uploads_relative_path_from_source',
		'get_webp_path_from_source',
		'is_valid_generated_media_file',
		'media_queue_table_exists',
		'optimized_storage_ensure_directory',
		'optimized_storage_filesystem',
		'optimized_storage_forget_path',
		'optimized_storage_harden_upload_permissions',
		'optimized_storage_path_exists',
		'optimized_storage_readable_source_exists',
		'path_is_within_root',
		'reconcile_media_queue_units_for_attachment',
		'get_media_queue_readiness_diagnostics',
	);

	/**
	 * Constructor.
	 *
	 * @param array<string,callable> $dependencies Media converter dependency callbacks.
	 */
	public function __construct($dependencies) {
		$dependencies = is_array($dependencies) ? $dependencies : array();

		foreach (self::REQUIRED_DEPENDENCIES as $dependency_name) {
			if (!isset($dependencies[$dependency_name]) || !is_callable($dependencies[$dependency_name])) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Dependency name comes from the private REQUIRED_DEPENDENCIES allowlist.
				throw new InvalidArgumentException('Missing UltraCache media replacement dependency: ' . $dependency_name);
			}
		}

		$this->dependencies = $dependencies;
	}

	/**
	 * Resolve an AVIF destination path through the media converter.
	 */
	private function get_avif_path_from_source($source_file) {
		return ($this->dependencies['get_avif_path_from_source'])($source_file);
	}

	/**
	 * Read the configured Media Library replacement format through the media converter.
	 */
	private function get_media_replacement_format() {
		return ($this->dependencies['get_media_replacement_format'])();
	}

	/**
	 * Resolve the media queue table through the media converter.
	 */
	private function get_media_queue_table_name() {
		return ($this->dependencies['get_media_queue_table_name'])();
	}

	/**
	 * Resolve an uploads-relative source path through the media converter.
	 */
	private function get_uploads_relative_path_from_source($source_file) {
		return ($this->dependencies['get_uploads_relative_path_from_source'])($source_file);
	}

	/**
	 * Resolve a WebP destination path through the media converter.
	 */
	private function get_webp_path_from_source($source_file) {
		return ($this->dependencies['get_webp_path_from_source'])($source_file);
	}

	/**
	 * Validate a generated media file through the media converter.
	 */
	private function is_valid_generated_media_file($file, $format, $context = '') {
		return ($this->dependencies['is_valid_generated_media_file'])($file, $format, $context);
	}

	/**
	 * Check media queue table availability through the media converter.
	 */
	private function media_queue_table_exists() {
		return ($this->dependencies['media_queue_table_exists'])();
	}

	/**
	 * Ensure an optimized-media directory through the media converter.
	 */
	private function optimized_storage_ensure_directory($dir) {
		return ($this->dependencies['optimized_storage_ensure_directory'])($dir);
	}

	/**
	 * Get the optimized-media filesystem through the media converter.
	 */
	private function optimized_storage_filesystem() {
		return ($this->dependencies['optimized_storage_filesystem'])();
	}

	/**
	 * Forget a memoized optimized-media path through the media converter.
	 */
	private function optimized_storage_forget_path($path) {
		return ($this->dependencies['optimized_storage_forget_path'])($path);
	}

	/**
	 * Harden optimized-media permissions through the media converter.
	 */
	private function optimized_storage_harden_upload_permissions($path, $type = 'file') {
		return ($this->dependencies['optimized_storage_harden_upload_permissions'])($path, $type);
	}

	/**
	 * Check optimized-media path existence through the media converter.
	 */
	private function optimized_storage_path_exists($path, $refresh = false) {
		return ($this->dependencies['optimized_storage_path_exists'])($path, $refresh);
	}

	/**
	 * Check optimized-media source readability through the media converter.
	 */
	private function optimized_storage_readable_source_exists($path) {
		return ($this->dependencies['optimized_storage_readable_source_exists'])($path);
	}

	/**
	 * Validate path containment through the media converter.
	 */
	private function path_is_within_root($path, $root) {
		return ($this->dependencies['path_is_within_root'])($path, $root);
	}

	/**
	 * Materialize and reconcile an existing attachment queue parent through the media converter.
	 */
	private function reconcile_media_queue_units_for_attachment($attachment_id, $format, $create_parent = false) {
		return ($this->dependencies['reconcile_media_queue_units_for_attachment'])($attachment_id, $format, $create_parent);
	}

	/**
	 * Read exact per-file queue diagnostics for readiness blockers.
	 *
	 * @param int[]  $attachment_ids Attachment IDs.
	 * @param string $format         Concrete output format.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_media_queue_readiness_diagnostics(array $attachment_ids, $format) {
		return ($this->dependencies['get_media_queue_readiness_diagnostics'])($attachment_ids, $format);
	}
}
