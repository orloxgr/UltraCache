<?php
/**
 * UltraCache persistent physical media conversion unit schema and inventory.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Queue_Units_Trait
{
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache uses a private custom physical media conversion unit table with validated table identifiers.

	/** Per-request memo for physical-unit schema verification capabilities. */
	private $media_queue_units_schema_request_memo = array();

	/** Per-request latest physical-unit lookup cache used by frontend terminal-state reads. */
	private $media_queue_terminal_unit_request_memo = array();

	private function reset_media_queue_units_schema_request_memo() {
		$this->media_queue_units_schema_request_memo = array();
	}

	/**
	 * Return the validated physical media conversion unit table name.
	 *
	 * @return string
	 */
	private function get_media_queue_units_table_name() {
		global $wpdb;

		$table = $wpdb->prefix . 'ultracache_media_queue_units';
		return function_exists('ultracache_validate_custom_table_name')
			? ultracache_validate_custom_table_name($table, 'media_queue_units')
			: $table;
	}


	/**
	 * Return the latest physical-unit state for one uploads-relative source path.
	 *
	 * The query belongs to the custom media queue repository layer and is
	 * request-memoized, so frontend URL resolution never issues the same custom
	 * table lookup twice in one PHP request.
	 *
	 * @param string $source_relative_path Uploads-relative source path.
	 * @param string $output_format        avif or webp.
	 * @return array{status:string,target_relative_path:string}|array{}
	 */
	private function get_latest_media_queue_unit_state_by_source_path($source_relative_path, $output_format) {
		$source_relative_path = function_exists('ultracache_normalize_media_source_relative_path')
			? ultracache_normalize_media_source_relative_path((string) $source_relative_path, (string) $output_format)
			: ltrim(str_replace('\\', '/', (string) $source_relative_path), '/');
		$output_format = strtolower(trim((string) $output_format));
		if (!$source_relative_path || !in_array($output_format, array('avif', 'webp'), true)) {
			return array();
		}

		$memo_key = $output_format . '|' . md5((string) $source_relative_path);
		if (array_key_exists($memo_key, $this->media_queue_terminal_unit_request_memo)) {
			return (array) $this->media_queue_terminal_unit_request_memo[$memo_key];
		}
		if (!$this->ensure_media_queue_units_table()) {
			$this->media_queue_terminal_unit_request_memo[$memo_key] = array();
			return array();
		}

		global $wpdb;
		$table = $this->get_media_queue_units_table_name();
		$row = '' !== $table ? $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status, target_relative_path FROM %i WHERE source_relative_path = %s AND output_format = %s ORDER BY id DESC LIMIT 1",
				$table,
				$source_relative_path,
				$output_format
			),
			ARRAY_A
		) : null;

		if (!is_array($row)) {
			$this->media_queue_terminal_unit_request_memo[$memo_key] = array();
			return array();
		}

		$result = array(
			'status'               => sanitize_key((string) ($row['status'] ?? '')),
			'target_relative_path' => ltrim(str_replace('\\', '/', (string) ($row['target_relative_path'] ?? '')), '/'),
		);
		$this->media_queue_terminal_unit_request_memo[$memo_key] = $result;
		return $result;
	}

	/**
	 * Check whether the physical media conversion unit table exists.
	 *
	 * @return bool
	 */
	private function media_queue_units_table_exists($force_schema_verify = false) {
		global $wpdb;

		if (!$force_schema_verify && self::MEDIA_QUEUE_UNITS_DB_VERSION === (string) get_option(self::MEDIA_QUEUE_UNITS_DB_VERSION_OPTION, '')) {
			return true;
		}

		if (array_key_exists('table_exists', $this->media_queue_units_schema_request_memo)) {
			return (bool) $this->media_queue_units_schema_request_memo['table_exists'];
		}

		$table = $this->get_media_queue_units_table_name();
		if ('' === $table) {
			return false;
		}

		$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		$this->media_queue_units_schema_request_memo['table_exists'] = ((string) $found === (string) $table);
		return (bool) $this->media_queue_units_schema_request_memo['table_exists'];
	}

	/**
	 * Return ordered metadata rows for one physical-unit table index.
	 *
	 * @param string $index_name Index name.
	 * @return array<int,array<string,mixed>>
	 */
	private function media_queue_units_index_rows($index_name) {
		global $wpdb;

		$table = $this->get_media_queue_units_table_name();
		if ('' === $table) {
			return array();
		}

		if (!array_key_exists('index_rows', $this->media_queue_units_schema_request_memo)) {
			$this->media_queue_units_schema_request_memo['index_rows'] = (array) $wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i', $table), ARRAY_A);
		}
		$rows = (array) $this->media_queue_units_schema_request_memo['index_rows'];
		$index_name = (string) $index_name;
		$matches = array_values(
			array_filter(
				(array) $rows,
				static function ($row) use ($index_name) {
					return $index_name === (string) ($row['Key_name'] ?? '');
				}
			)
		);

		usort(
			$matches,
			static function ($left, $right) {
				return ((int) ($left['Seq_in_index'] ?? 0)) <=> ((int) ($right['Seq_in_index'] ?? 0));
			}
		);

		return $matches;
	}

	/**
	 * Check one physical-unit table index contract.
	 *
	 * @param string            $index_name Index name.
	 * @param array<int,string> $columns    Ordered columns.
	 * @param bool              $unique     Whether the index must be unique.
	 * @return bool
	 */
	private function media_queue_units_index_matches($index_name, array $columns, $unique) {
		$rows = $this->media_queue_units_index_rows($index_name);
		if (empty($rows)) {
			return false;
		}

		$actual_columns = array_map(
			static function ($row) {
				return (string) ($row['Column_name'] ?? '');
			},
			$rows
		);
		$actual_unique = '0' === (string) ($rows[0]['Non_unique'] ?? '1');

		return array_values($columns) === $actual_columns && (bool) $unique === $actual_unique;
	}

	/**
	 * Check the required physical-unit table columns.
	 *
	 * @return bool
	 */
	private function media_queue_units_columns_exist() {
		global $wpdb;

		$table = $this->get_media_queue_units_table_name();
		if ('' === $table) {
			return false;
		}

		$required = array(
			'id',
			'parent_queue_id',
			'attachment_id',
			'unit_identity',
			'item_scope',
			'size_name',
			'source_relative_path',
			'target_relative_path',
			'output_format',
			'source_mtime',
			'source_size',
			'status',
			'attempts',
			'consecutive_failures',
			'stale_recoveries',
			'failure_code',
			'failure_stage',
			'failure_detail',
			'resolution_code',
			'resolution_detail',
			'resolution_context',
			'encoder_attempts',
			'created_at',
			'updated_at',
			'started_at',
			'completed_at',
		);
		if (!array_key_exists('columns_exist', $this->media_queue_units_schema_request_memo)) {
			$columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table));
			$this->media_queue_units_schema_request_memo['columns_exist'] = empty(array_diff($required, array_map('strval', (array) $columns)));
		}

		return (bool) $this->media_queue_units_schema_request_memo['columns_exist'];
	}

	/**
	 * Check the complete physical-unit table schema contract.
	 *
	 * @return bool
	 */
	private function media_queue_units_schema_is_current() {
		return $this->media_queue_units_table_exists(true)
			&& $this->media_queue_units_columns_exist()
			&& $this->media_queue_units_index_matches(
				'parent_unit',
				array('parent_queue_id', 'unit_identity', 'output_format'),
				true
			)
			&& $this->media_queue_units_index_matches(
				'parent_status',
				array('parent_queue_id', 'status', 'id'),
				false
			)
			&& $this->media_queue_units_index_matches(
				'attachment_format',
				array('attachment_id', 'output_format'),
				false
			)
			&& $this->media_queue_units_index_matches(
				'status_id',
				array('status', 'id'),
				false
			)
			&& $this->media_queue_units_index_matches(
				'source_path_format_id',
				array('source_relative_path', 'output_format', 'id'),
				false
			);
	}

	/**
	 * Install or upgrade the physical media conversion unit table.
	 *
	 * @return bool
	 */
	public function ensure_media_queue_units_table($force_schema_verify = false) {
		global $wpdb;

		$table = $this->get_media_queue_units_table_name();
		if ('' === $table) {
			return false;
		}

		$version = (string) get_option(self::MEDIA_QUEUE_UNITS_DB_VERSION_OPTION, '');
		$force_schema_verify = (bool) $force_schema_verify;

		// Current stored schema version is authoritative during normal runtime.
		// Expensive SHOW TABLES/COLUMNS/INDEX checks run only on forced lifecycle
		// verification or when the stored version is outdated/missing.
		if (!$force_schema_verify && self::MEDIA_QUEUE_UNITS_DB_VERSION === $version) {
			return true;
		}

		if ($force_schema_verify && self::MEDIA_QUEUE_UNITS_DB_VERSION === $version && $this->media_queue_units_schema_is_current()) {
			return true;
		}

		if (!ultracache_require_wordpress_admin_include('upgrade.php', 'dbDelta')) {
			return false;
		}

		$this->reset_media_queue_units_schema_request_memo();
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			parent_queue_id bigint(20) unsigned NOT NULL DEFAULT 0,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			unit_identity char(64) NOT NULL DEFAULT '',
			item_scope varchar(24) NOT NULL DEFAULT 'main',
			size_name varchar(64) NOT NULL DEFAULT '',
			source_relative_path text NULL,
			target_relative_path text NULL,
			output_format varchar(12) NOT NULL DEFAULT '',
			source_mtime bigint(20) unsigned NOT NULL DEFAULT 0,
			source_size bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			consecutive_failures smallint(5) unsigned NOT NULL DEFAULT 0,
			stale_recoveries smallint(5) unsigned NOT NULL DEFAULT 0,
			failure_code varchar(64) NOT NULL DEFAULT '',
			failure_stage varchar(64) NOT NULL DEFAULT '',
			failure_detail text NULL,
			resolution_code varchar(64) NOT NULL DEFAULT '',
			resolution_detail text NULL,
			resolution_context char(64) NOT NULL DEFAULT '',
			encoder_attempts longtext NULL,
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			started_at datetime NULL DEFAULT NULL,
			completed_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY parent_unit (parent_queue_id, unit_identity, output_format),
			KEY parent_status (parent_queue_id, status, id),
			KEY attachment_format (attachment_id, output_format),
			KEY status_id (status, id),
			KEY source_path_format_id (source_relative_path(160), output_format, id),
			KEY updated_at (updated_at)
		) {$charset_collate};";

		dbDelta($sql);
		$this->reset_media_queue_units_schema_request_memo();
		if (!$this->media_queue_units_schema_is_current()) {
			return false;
		}

		update_option(self::MEDIA_QUEUE_UNITS_DB_VERSION_OPTION, self::MEDIA_QUEUE_UNITS_DB_VERSION, false);
		return true;
	}

	/**
	 * Normalize a parent queue output policy to concrete physical output formats.
	 *
	 * @param string $format Parent queue format policy.
	 * @return array<int,string>
	 */
	private function get_media_queue_unit_output_formats($format) {
		$format = strtolower(trim((string) $format));
		if (!in_array($format, array('best', 'avif', 'webp', 'both'), true)) {
			$format = 'best';
		}

		if ('both' === $format) {
			$formats = array('avif', 'webp');
		} elseif ('best' === $format) {
			$formats = $this->get_best_media_conversion_formats();
		} else {
			$formats = array($format);
		}

		return array_values(
			array_unique(
				array_filter(
					array_map('strtolower', (array) $formats),
					static function ($candidate) {
						return in_array($candidate, array('avif', 'webp'), true);
					}
				)
			)
		);
	}

	/**
	 * Return canonical physical source descriptors for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_attachment_media_queue_unit_sources($attachment_id) {
		$attachment_id = absint($attachment_id);
		if ($attachment_id <= 0) {
			return array();
		}

		$main_file = get_attached_file($attachment_id);
		if (!is_string($main_file) || '' === $main_file) {
			return array();
		}

		$sources = array();
		$seen = array();
		$append = function ($item_scope, $size_name, $source_file) use (&$sources, &$seen) {
			$source_file = wp_normalize_path((string) $source_file);
			if ('' === $source_file) {
				return;
			}

			$source_relative_path = $this->get_uploads_relative_path_from_source($source_file);
			if (!is_string($source_relative_path) || '' === $source_relative_path) {
				$uploads = function_exists('ultracache_uploads_base_info') ? ultracache_uploads_base_info() : wp_upload_dir(null, false);
				$uploads_root = !empty($uploads['basedir']) ? wp_normalize_path((string) $uploads['basedir']) : '';
				if ('' === $uploads_root || !$this->path_is_within_root($source_file, $uploads_root)) {
					return;
				}
				$source_relative_path = ltrim(substr($source_file, strlen(rtrim($uploads_root, '/'))), '/');
			}

			$source_relative_path = function_exists('ultracache_normalize_media_source_relative_path')
				? ultracache_normalize_media_source_relative_path($source_relative_path)
				: ltrim(str_replace('\\', '/', (string) $source_relative_path), '/');
			if (!$source_relative_path) {
				return;
			}
			$physical_key = $source_relative_path;
			if (isset($seen[$physical_key])) {
				return;
			}
			$seen[$physical_key] = true;

			$item_scope = 'intermediate' === (string) $item_scope ? 'intermediate' : 'main';
			$size_name = 'intermediate' === $item_scope ? substr(sanitize_key((string) $size_name), 0, 64) : '';
			$source_mtime = function_exists('ultracache_safe_filemtime')
				? ultracache_safe_filemtime($source_file, 'media_queue_unit_source_mtime')
				: @filemtime($source_file);
			$source_size = function_exists('ultracache_safe_filesize')
				? ultracache_safe_filesize($source_file, 'media_queue_unit_source_size')
				: @filesize($source_file);

			$sources[] = array(
				'item_scope'          => $item_scope,
				'size_name'           => $size_name,
				'source_file'         => $source_file,
				'source_relative_path'=> $source_relative_path,
				'source_mtime'        => false === $source_mtime ? 0 : max(0, (int) $source_mtime),
				'source_size'         => false === $source_size ? 0 : max(0, (int) $source_size),
			);
		};

		$append('main', '', $main_file);
		$metadata = wp_get_attachment_metadata($attachment_id);
		if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
			$base_dir = dirname($main_file);
			foreach ($metadata['sizes'] as $raw_size_name => $size_data) {
				if (!is_array($size_data) || empty($size_data['file'])) {
					continue;
				}

				$append(
					'intermediate',
					$raw_size_name,
					trailingslashit($base_dir) . ltrim((string) $size_data['file'], '/')
				);
			}
		}

		return $sources;
	}

	/**
	 * Build the canonical physical conversion unit inventory for one attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $format        Parent output policy.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_attachment_media_queue_unit_inventory($attachment_id, $format) {
		$attachment_id = absint($attachment_id);
		$formats = $this->get_media_queue_unit_output_formats($format);
		if ($attachment_id <= 0 || empty($formats)) {
			return array();
		}

		$inventory = array();
		foreach ($this->get_attachment_media_queue_unit_sources($attachment_id) as $source) {
			foreach ($formats as $output_format) {
				$target_relative_path = function_exists('ultracache_build_optimized_media_relative_path')
					? ultracache_build_optimized_media_relative_path((string) $source['source_relative_path'], $output_format)
					: false;
				$target_relative_path = is_string($target_relative_path)
					? ltrim(str_replace('\\', '/', $target_relative_path), '/')
					: '';

				$identity_material = implode(
					'|',
					array(
						(string) $attachment_id,
						(string) $source['item_scope'],
						(string) $source['size_name'],
						(string) $source['source_relative_path'],
						(string) $output_format,
					)
				);

				$inventory[] = array(
					'attachment_id'       => $attachment_id,
					'unit_identity'       => hash('sha256', $identity_material),
					'item_scope'          => (string) $source['item_scope'],
					'size_name'           => (string) $source['size_name'],
					'source_relative_path'=> (string) $source['source_relative_path'],
					'target_relative_path'=> $target_relative_path,
					'output_format'       => (string) $output_format,
					'source_mtime'        => (int) $source['source_mtime'],
					'source_size'         => (int) $source['source_size'],
				);
			}
		}

		return $inventory;
	}

	/**
	 * Materialize or reconcile the physical unit inventory for one parent queue row.
	 *
	 * This release creates and synchronizes the authoritative child inventory only.
	 * Existing workers continue using the established attachment-level parent row
	 * until the dedicated per-unit execution release activates child claims.
	 *
	 * @param int $parent_queue_id Parent media queue row ID.
	 * @return array<string,mixed>
	 */
	public function sync_media_queue_units_for_parent($parent_queue_id) {
		$parent_queue_id = absint($parent_queue_id);
		$result = array(
			'success'       => false,
			'parentQueueId' => $parent_queue_id,
			'attachmentId'  => 0,
			'format'        => '',
			'unitTotal'     => 0,
			'created'       => 0,
			'updated'       => 0,
			'unchanged'     => 0,
			'superseded'    => 0,
			'notApplicable' => false,
			'error'         => '',
		);

		if ($parent_queue_id <= 0 || !$this->ensure_media_queue_table() || !$this->ensure_media_queue_units_table()) {
			$result['error'] = 'media_queue_unit_storage_unavailable';
			return $result;
		}

		global $wpdb;
		$parent_table = $this->get_media_queue_table_name();
		$units_table = $this->get_media_queue_units_table_name();
		$parent = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, attachment_id, source_kind, format FROM %i WHERE id = %d LIMIT 1',
				$parent_table,
				$parent_queue_id
			),
			ARRAY_A
		);

		if (!is_array($parent) || empty($parent['id'])) {
			$result['error'] = 'media_queue_parent_missing';
			return $result;
		}

		$attachment_id = absint($parent['attachment_id'] ?? 0);
		$format = $this->normalize_media_queue_format($parent['format'] ?? 'best');
		$result['attachmentId'] = $attachment_id;
		$result['format'] = $format;

		if ('attachment' !== (string) ($parent['source_kind'] ?? '') || $attachment_id <= 0) {
			$result['success'] = true;
			$result['notApplicable'] = true;
			return $result;
		}

		$inventory = $this->build_attachment_media_queue_unit_inventory($attachment_id, $format);
		$result['unitTotal'] = count($inventory);
		if (empty($inventory)) {
			$result['error'] = 'media_queue_unit_inventory_unavailable';
			return $result;
		}

		$existing_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE parent_queue_id = %d ORDER BY id ASC',
				$units_table,
				$parent_queue_id
			),
			ARRAY_A
		);
		$existing_by_key = array();
		foreach ((array) $existing_rows as $existing_row) {
			$key = (string) ($existing_row['unit_identity'] ?? '') . '|' . (string) ($existing_row['output_format'] ?? '');
			$existing_by_key[$key] = $existing_row;
		}

		$now = current_time('mysql');
		$active_keys = array();
		foreach ($inventory as $unit) {
			$key = (string) $unit['unit_identity'] . '|' . (string) $unit['output_format'];
			$active_keys[$key] = true;
			$existing = $existing_by_key[$key] ?? null;

			if (!is_array($existing)) {
				$created = $wpdb->insert(
					$units_table,
					array(
						'parent_queue_id'      => $parent_queue_id,
						'attachment_id'        => $attachment_id,
						'unit_identity'        => (string) $unit['unit_identity'],
						'item_scope'           => (string) $unit['item_scope'],
						'size_name'            => (string) $unit['size_name'],
						'source_relative_path' => (string) $unit['source_relative_path'],
						'target_relative_path' => (string) $unit['target_relative_path'],
						'output_format'        => (string) $unit['output_format'],
						'source_mtime'         => (int) $unit['source_mtime'],
						'source_size'          => (int) $unit['source_size'],
						'status'               => 'pending',
						'attempts'             => 0,
						'consecutive_failures' => 0,
						'stale_recoveries'     => 0,
						'failure_code'         => '',
						'failure_stage'        => '',
						'failure_detail'       => '',
						'resolution_code'      => '',
						'resolution_detail'    => '',
						'resolution_context'   => '',
						'encoder_attempts'     => '',
						'created_at'           => $now,
						'updated_at'           => $now,
					),
					array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
				);
				if (false !== $created) {
					$result['created']++;
					continue;
				}

				$existing = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE parent_queue_id = %d AND unit_identity = %s AND output_format = %s LIMIT 1',
						$units_table,
						$parent_queue_id,
						(string) $unit['unit_identity'],
						(string) $unit['output_format']
					),
					ARRAY_A
				);
				if (!is_array($existing) || empty($existing['id'])) {
					$result['error'] = 'media_queue_unit_insert_failed';
					return $result;
				}
			}

			$source_changed = (int) ($existing['source_mtime'] ?? 0) !== (int) $unit['source_mtime']
				|| (int) ($existing['source_size'] ?? 0) !== (int) $unit['source_size'];
			$descriptor_changed = (int) ($existing['attachment_id'] ?? 0) !== $attachment_id
				|| (string) ($existing['item_scope'] ?? '') !== (string) $unit['item_scope']
				|| (string) ($existing['size_name'] ?? '') !== (string) $unit['size_name']
				|| (string) ($existing['source_relative_path'] ?? '') !== (string) $unit['source_relative_path']
				|| (string) ($existing['target_relative_path'] ?? '') !== (string) $unit['target_relative_path'];
			$reactivate = 'superseded' === (string) ($existing['status'] ?? '');

			if (!$source_changed && !$descriptor_changed && !$reactivate) {
				$result['unchanged']++;
				continue;
			}

			$update = array(
				'attachment_id'        => $attachment_id,
				'item_scope'           => (string) $unit['item_scope'],
				'size_name'            => (string) $unit['size_name'],
				'source_relative_path' => (string) $unit['source_relative_path'],
				'target_relative_path' => (string) $unit['target_relative_path'],
				'source_mtime'         => (int) $unit['source_mtime'],
				'source_size'          => (int) $unit['source_size'],
				'updated_at'           => $now,
			);
			$formats = array('%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s');
			if ($source_changed || $descriptor_changed || $reactivate) {
				$update = array_merge(
					$update,
					array(
						'status'               => 'pending',
						'attempts'             => 0,
						'consecutive_failures' => 0,
						'stale_recoveries'     => 0,
						'failure_code'         => '',
						'failure_stage'        => '',
						'failure_detail'       => '',
						'resolution_code'      => '',
						'resolution_detail'    => '',
						'resolution_context'   => '',
						'encoder_attempts'     => '',
						'started_at'           => null,
						'completed_at'         => null,
					)
				);
				$formats = array_merge($formats, array('%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));
			}

			$updated = $wpdb->update(
				$units_table,
				$update,
				array('id' => (int) $existing['id']),
				$formats,
				array('%d')
			);
			if (false === $updated) {
				$result['error'] = 'media_queue_unit_update_failed';
				return $result;
			}
			$result['updated']++;
		}

		foreach ((array) $existing_rows as $existing_row) {
			$key = (string) ($existing_row['unit_identity'] ?? '') . '|' . (string) ($existing_row['output_format'] ?? '');
			if (isset($active_keys[$key]) || 'superseded' === (string) ($existing_row['status'] ?? '')) {
				continue;
			}

			$updated = $wpdb->update(
				$units_table,
				array(
					'status'       => 'superseded',
					'updated_at'   => $now,
					'started_at'   => null,
					'completed_at' => $now,
				),
				array('id' => (int) ($existing_row['id'] ?? 0)),
				array('%s', '%s', '%s', '%s'),
				array('%d')
			);
			if (false === $updated) {
				$result['error'] = 'media_queue_unit_supersede_failed';
				return $result;
			}
			$result['superseded']++;
		}

		$result['success'] = true;
		return $result;
	}


	/**
	 * Return one attachment parent queue row.
	 *
	 * @param int $parent_queue_id Parent queue row ID.
	 * @return array<string,mixed>
	 */
	private function get_media_queue_unit_parent_row($parent_queue_id) {
		$parent_queue_id = absint($parent_queue_id);
		if ($parent_queue_id <= 0 || !$this->media_queue_table_exists()) {
			return array();
		}

		global $wpdb;
		$table = $this->get_media_queue_table_name();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, attachment_id, source_kind, format, status, attempts, consecutive_failures, stale_recoveries, last_error, created_at, updated_at, started_at, completed_at FROM %i WHERE id = %d LIMIT 1',
				$table,
				$parent_queue_id
			),
			ARRAY_A
		);

		return is_array($row) ? $row : array();
	}

	/**
	 * Resolve one attachment parent queue row by attachment and format.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $format        Parent output policy.
	 * @return array<string,mixed>
	 */
	private function get_media_queue_unit_parent_row_for_attachment($attachment_id, $format) {
		$attachment_id = absint($attachment_id);
		$format = $this->normalize_media_queue_format($format);
		if ($attachment_id <= 0 || !$this->media_queue_table_exists()) {
			return array();
		}

		global $wpdb;
		$table = $this->get_media_queue_table_name();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, attachment_id, source_kind, format, status, attempts, consecutive_failures, stale_recoveries, last_error, created_at, updated_at, started_at, completed_at FROM %i WHERE source_kind = 'attachment' AND attachment_id = %d AND format = %s LIMIT 1",
				$table,
				$attachment_id,
				$format
			),
			ARRAY_A
		);

		return is_array($row) ? $row : array();
	}

	/**
	 * Resolve an active physical unit source path inside WordPress uploads.
	 *
	 * @param array<string,mixed> $unit Physical unit row.
	 * @return string
	 */
	private function get_media_queue_unit_source_path(array $unit) {
		$relative_path = (string) ($unit['source_relative_path'] ?? '');
		$format = strtolower((string) ($unit['output_format'] ?? ''));
		$relative_path = function_exists('ultracache_normalize_media_source_relative_path')
			? ultracache_normalize_media_source_relative_path($relative_path, $format)
			: false;
		if (!$relative_path) {
			return '';
		}

		$source_path = $this->get_uploads_source_path_from_relative_path($relative_path);
		return is_string($source_path) ? wp_normalize_path($source_path) : '';
	}

	/**
	 * Resolve an active physical unit destination path inside managed optimized storage.
	 *
	 * @param array<string,mixed> $unit Physical unit row.
	 * @return string
	 */
	private function get_media_queue_unit_target_path(array $unit) {
		$format = strtolower((string) ($unit['output_format'] ?? ''));
		$relative_path = rawurldecode((string) ($unit['target_relative_path'] ?? ''));
		$relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
		if (!in_array($format, array('avif', 'webp'), true) || '' === $relative_path || false !== strpos($relative_path, "\0")) {
			return '';
		}
		foreach (explode('/', $relative_path) as $segment) {
			if ('' === $segment || '.' === $segment || '..' === $segment) {
				return '';
			}
		}
		if (!preg_match('/\.' . preg_quote($format, '/') . '$/i', $relative_path)) {
			return '';
		}

		$root = function_exists('ultracache_optimized_images_storage_dir')
			? ultracache_optimized_images_storage_dir($format)
			: '';
		if ('' === (string) $root) {
			return '';
		}
		$root = wp_normalize_path((string) $root);
		$target = wp_normalize_path(trailingslashit($root) . $relative_path);
		return $this->path_is_within_root($target, $root) ? $target : '';
	}

	/**
	 * Return the conversion context signature for one deterministic unit result.
	 *
	 * @param array<string,mixed> $unit Physical unit row.
	 * @return string
	 */
	private function get_media_queue_unit_resolution_context(array $unit) {
		$format = strtolower((string) ($unit['output_format'] ?? ''));
		$source_path = $this->get_media_queue_unit_source_path($unit);
		$supported = false;
		if ('' !== $source_path && in_array($format, array('avif', 'webp'), true)) {
			$supported = $this->is_attachment_conversion_unit_supported(array(
				'source_file' => $source_path,
				'format'      => $format,
			));
		}

		$imagick_version = '';
		if (extension_loaded('imagick') && class_exists('Imagick')) {
			$imagick_info = Imagick::getVersion();
			$imagick_version = is_array($imagick_info) ? (string) ($imagick_info['versionString'] ?? '') : '';
		}
		$gd_info = extension_loaded('gd') && function_exists('gd_info') ? gd_info() : array();
		$payload = array(
			'plugin'    => defined('ULTRACACHE_VERSION') ? (string) ULTRACACHE_VERSION : '',
			'format'    => $format,
			'supported' => $supported ? '1' : '0',
			'php'       => PHP_VERSION,
			'imagick'   => $imagick_version,
			'gd'        => is_array($gd_info) ? $gd_info : array(),
		);
		$encoded = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
		return hash('sha256', is_string($encoded) ? $encoded : serialize($payload));
	}

	/**
	 * Inspect one physical unit against exact source, target, format, and freshness state.
	 *
	 * @param array<string,mixed> $unit Physical unit row.
	 * @return array<string,mixed>
	 */
	private function inspect_media_queue_unit_filesystem_state(array $unit) {
		$format = strtolower((string) ($unit['output_format'] ?? ''));
		$source_path = $this->get_media_queue_unit_source_path($unit);
		$target_path = $this->get_media_queue_unit_target_path($unit);
		$result = array(
			'status'       => 'pending',
			'reason'       => 'target_missing',
			'sourcePath'   => $source_path,
			'targetPath'   => $target_path,
			'sourceMtime'  => 0,
			'sourceSize'   => 0,
			'targetMtime'  => 0,
		);

		if ('' === $source_path || !$this->optimized_storage_readable_source_exists($source_path)) {
			$result['status'] = 'skipped';
			$result['reason'] = 'source_missing_or_unreadable';
			return $result;
		}
		if (!in_array($format, array('avif', 'webp'), true) || !$this->is_source_file_supported_for_format($source_path, $format) || '' === $target_path) {
			$result['status'] = 'skipped';
			$result['reason'] = 'unsupported_source_or_target_mapping';
			return $result;
		}

		$source_fingerprint = $this->get_optimized_source_fingerprint($source_path, true);
		$result['sourceMtime'] = max(0, (int) ($source_fingerprint['mtime'] ?? 0));
		$result['sourceSize'] = max(0, (int) ($source_fingerprint['size'] ?? 0));
		if (empty($source_fingerprint['exists'])) {
			$result['status'] = 'skipped';
			$result['reason'] = 'source_missing_or_unreadable';
			return $result;
		}
		if (!$this->optimized_storage_path_exists($target_path, true)) {
			return $result;
		}
		if (!$this->is_valid_generated_media_file($target_path, $format, 'media_queue_unit_reconciliation_validate')) {
			$result['reason'] = 'target_invalid';
			return $result;
		}

		$target_mtime = function_exists('ultracache_safe_filemtime')
			? ultracache_safe_filemtime($target_path, 'media_queue_unit_reconciliation_target_mtime')
			: @filemtime($target_path);
		$result['targetMtime'] = false === $target_mtime ? 0 : max(0, (int) $target_mtime);
		if ($result['sourceMtime'] <= 0 || $result['targetMtime'] <= 0) {
			$result['reason'] = 'freshness_indeterminate';
			return $result;
		}
		if ($result['targetMtime'] < $result['sourceMtime']) {
			$result['reason'] = 'target_stale';
			return $result;
		}

		$result['status'] = 'done';
		$result['reason'] = 'target_fresh';
		return $result;
	}

	/**
	 * Persist one reconciled unit state without overriding an active child claim.
	 *
	 * @param array<string,mixed> $unit       Existing unit row.
	 * @param array<string,mixed> $inspection Filesystem inspection result.
	 * @return array<string,mixed>
	 */
	private function persist_media_queue_unit_reconciliation(array $unit, array $inspection) {
		$result = array(
			'success' => false,
			'changed' => false,
			'status'  => (string) ($unit['status'] ?? ''),
			'reason'  => (string) ($inspection['reason'] ?? ''),
		);
		$unit_id = absint($unit['id'] ?? 0);
		$current_status = (string) ($unit['status'] ?? 'pending');
		$next_status = (string) ($inspection['status'] ?? 'pending');
		if ($unit_id <= 0 || 'superseded' === $current_status) {
			return $result;
		}
		if ('processing' === $current_status) {
			$result['success'] = true;
			$result['status'] = 'processing';
			$result['reason'] = 'active_claim_preserved';
			return $result;
		}
		if ('failed' === $current_status && 'pending' === $next_status) {
			$result['success'] = true;
			$result['status'] = 'failed';
			$result['reason'] = 'terminal_failure_preserved';
			return $result;
		}

		$current_context = $this->get_media_queue_unit_resolution_context($unit);
		$persisted_resolution = sanitize_key((string) ($unit['resolution_code'] ?? ''));
		$persisted_context = (string) ($unit['resolution_context'] ?? '');
		$source_unchanged = (int) ($unit['source_mtime'] ?? 0) === max(0, (int) ($inspection['sourceMtime'] ?? 0))
			&& (int) ($unit['source_size'] ?? 0) === max(0, (int) ($inspection['sourceSize'] ?? 0));
		$preserve_semantic_skip = 'skipped' === $current_status
			&& '' !== $persisted_resolution
			&& '' !== $persisted_context
			&& hash_equals($persisted_context, $current_context)
			&& $source_unchanged;
		if ($preserve_semantic_skip) {
			$next_status = 'skipped';
			$result['reason'] = $persisted_resolution;
		}
		if (!in_array($next_status, array('pending', 'done', 'skipped'), true)) {
			$next_status = 'pending';
		}

		$resolution_code = '';
		$resolution_detail = '';
		$resolution_context = '';
		if ('skipped' === $next_status) {
			$resolution_code = $preserve_semantic_skip
				? $persisted_resolution
				: sanitize_key((string) ($inspection['reason'] ?? 'source_missing_or_unreadable'));
			$resolution_detail = $preserve_semantic_skip
				? (string) ($unit['resolution_detail'] ?? '')
				: $this->get_media_conversion_skip_detail($resolution_code);
			$resolution_context = $current_context;
		}

		$now = current_time('mysql');
		$terminal = in_array($next_status, array('done', 'skipped'), true);
		$update = array(
			'source_mtime'         => max(0, (int) ($inspection['sourceMtime'] ?? ($unit['source_mtime'] ?? 0))),
			'source_size'          => max(0, (int) ($inspection['sourceSize'] ?? ($unit['source_size'] ?? 0))),
			'status'               => $next_status,
			'consecutive_failures' => $terminal ? 0 : max(0, (int) ($unit['consecutive_failures'] ?? 0)),
			'failure_code'         => $terminal ? '' : (string) ($unit['failure_code'] ?? ''),
			'failure_stage'        => $terminal ? '' : (string) ($unit['failure_stage'] ?? ''),
			'failure_detail'       => $terminal ? '' : (string) ($unit['failure_detail'] ?? ''),
			'resolution_code'      => $resolution_code,
			'resolution_detail'    => $resolution_detail,
			'resolution_context'   => $resolution_context,
			'encoder_attempts'     => $terminal ? '' : (string) ($unit['encoder_attempts'] ?? ''),
			'updated_at'           => $now,
			'started_at'           => null,
			'completed_at'         => $terminal ? $now : null,
		);
		$changed = $current_status !== $next_status
			|| (int) ($unit['source_mtime'] ?? 0) !== (int) $update['source_mtime']
			|| (int) ($unit['source_size'] ?? 0) !== (int) $update['source_size']
			|| (string) ($unit['resolution_code'] ?? '') !== $resolution_code
			|| (string) ($unit['resolution_detail'] ?? '') !== $resolution_detail
			|| (string) ($unit['resolution_context'] ?? '') !== $resolution_context
			|| ($terminal && ('' !== (string) ($unit['failure_code'] ?? '') || '' !== (string) ($unit['failure_detail'] ?? '')));
		if (!$changed) {
			$result['success'] = true;
			$result['status'] = $next_status;
			return $result;
		}

		global $wpdb;
		$table = $this->get_media_queue_units_table_name();
		$updated = $wpdb->update(
			$table,
			$update,
			array('id' => $unit_id, 'status' => $current_status),
			array('%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'),
			array('%d', '%s')
		);
		if (false === $updated) {
			return $result;
		}
		$result['success'] = true;
		$result['changed'] = (int) $updated > 0;
		$result['status'] = $next_status;
		return $result;
	}


	/**
	 * Return authoritative physical-unit counters for one attachment parent policy.
	 *
	 * Parent queue counters remain attachment based. These counters describe only
	 * active physical main/thumbnail output units and explicitly report lazy
	 * inventory coverage so a partial materialization cannot look complete.
	 *
	 * @param string $format Parent output policy.
	 * @return array<string,mixed>
	 */
	private function get_media_queue_unit_status_summary($format) {
		$summary = array(
			'unitStatusAvailable'       => false,
			'unitParentTotal'           => 0,
			'unitRequiredParentTotal'   => 0,
			'unitMaterializedParents'   => 0,
			'unitMaterializedRequiredParents' => 0,
			'unitUnmaterializedParents' => 0,
			'unitCoverageComplete'      => false,
			'unitTotal'                 => 0,
			'unitPending'               => 0,
			'unitProcessing'            => 0,
			'unitDone'                  => 0,
			'unitFailed'                => 0,
			'unitSkipped'               => 0,
			'unitCompleted'             => 0,
			'unitRemaining'             => 0,
			'unitOutstanding'           => 0,
			'unitRequiredTotal'         => 0,
			'unitRequiredCompleted'     => 0,
			'unitRetryPending'          => 0,
			'unitInventoryComplete'     => false,
			'unitIsComplete'            => false,
		);
		if (!$this->media_queue_table_exists() || !$this->ensure_media_queue_units_table()) {
			return $summary;
		}

		$format = $this->normalize_media_queue_format($format);
		global $wpdb;
		$parents = $this->get_media_queue_table_name();
		$units = $this->get_media_queue_units_table_name();
		$no_inventory_message = 'No supported physical image files were found.';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(units.id) AS unit_total, COUNT(DISTINCT units.parent_queue_id) AS materialized_parents, COUNT(DISTINCT CASE WHEN NOT (parents.status = 'skipped' AND parents.last_error = %s) THEN units.parent_queue_id END) AS materialized_required_parents, SUM(CASE WHEN units.status = 'pending' THEN 1 ELSE 0 END) AS unit_pending, SUM(CASE WHEN units.status = 'processing' THEN 1 ELSE 0 END) AS unit_processing, SUM(CASE WHEN units.status = 'done' THEN 1 ELSE 0 END) AS unit_done, SUM(CASE WHEN units.status = 'failed' THEN 1 ELSE 0 END) AS unit_failed, SUM(CASE WHEN units.status = 'skipped' THEN 1 ELSE 0 END) AS unit_skipped, SUM(CASE WHEN units.status = 'pending' AND (units.failure_code <> '' OR units.failure_detail <> '') THEN 1 ELSE 0 END) AS unit_retry_pending FROM %i units INNER JOIN %i parents ON parents.id = units.parent_queue_id WHERE parents.source_kind = 'attachment' AND parents.format = %s AND units.status <> 'superseded'",
				$no_inventory_message,
				$units,
				$parents,
				$format
			),
			ARRAY_A
		);
		$parent_counts = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS parent_total, SUM(CASE WHEN NOT (status = 'skipped' AND last_error = %s) THEN 1 ELSE 0 END) AS required_parent_total FROM %i WHERE source_kind = 'attachment' AND format = %s",
				$no_inventory_message,
				$parents,
				$format
			),
			ARRAY_A
		);
		$parent_counts = is_array($parent_counts) ? $parent_counts : array();
		$parent_total = max(0, (int) ($parent_counts['parent_total'] ?? 0));
		$required_parent_total = max(0, (int) ($parent_counts['required_parent_total'] ?? 0));
		$row = is_array($row) ? $row : array();
		$pending = max(0, (int) ($row['unit_pending'] ?? 0));
		$processing = max(0, (int) ($row['unit_processing'] ?? 0));
		$done = max(0, (int) ($row['unit_done'] ?? 0));
		$failed = max(0, (int) ($row['unit_failed'] ?? 0));
		$skipped = max(0, (int) ($row['unit_skipped'] ?? 0));
		$materialized = max(0, (int) ($row['materialized_parents'] ?? 0));
		$materialized_required = max(0, (int) ($row['materialized_required_parents'] ?? 0));
		$unmaterialized = max(0, $required_parent_total - $materialized_required);
		$required_total = $pending + $processing + $done + $failed;

		return array(
			'unitStatusAvailable'       => true,
			'unitParentTotal'           => $parent_total,
			'unitRequiredParentTotal'   => $required_parent_total,
			'unitMaterializedParents'   => $materialized,
			'unitMaterializedRequiredParents' => $materialized_required,
			'unitUnmaterializedParents' => $unmaterialized,
			'unitCoverageComplete'      => 0 === $unmaterialized,
			'unitTotal'                 => $pending + $processing + $done + $failed + $skipped,
			'unitPending'               => $pending,
			'unitProcessing'            => $processing,
			'unitDone'                  => $done,
			'unitFailed'                => $failed,
			'unitSkipped'               => $skipped,
			'unitCompleted'             => $done + $skipped,
			'unitRemaining'             => $pending + $processing,
			'unitOutstanding'           => $pending + $processing + $failed,
			'unitRequiredTotal'         => $required_total,
			'unitRequiredCompleted'     => $done,
			'unitRetryPending'          => max(0, (int) ($row['unit_retry_pending'] ?? 0)),
			'unitInventoryComplete'     => false,
			'unitIsComplete'            => false,
		);
	}

	/**
	 * Derive one attachment parent status from active child states.
	 *
	 * @param array<int,array<string,mixed>> $units Active unit rows.
	 * @return array<string,mixed>
	 */
	private function derive_media_queue_parent_state_from_units(array $units) {
		$counts = array(
			'pending'    => 0,
			'processing' => 0,
			'done'       => 0,
			'failed'     => 0,
			'skipped'    => 0,
		);
		foreach ($units as $unit) {
			$status = (string) ($unit['status'] ?? 'pending');
			if (array_key_exists($status, $counts)) {
				$counts[$status]++;
			}
		}

		$status = '';
		if ($counts['failed'] > 0) {
			$status = 'failed';
		} elseif (($counts['pending'] + $counts['processing']) > 0) {
			$status = 'pending';
		} elseif ($counts['done'] > 0) {
			$status = 'done';
		} elseif ($counts['skipped'] > 0) {
			$status = 'skipped';
		}

		return array(
			'status'       => $status,
			'counts'       => $counts,
			'activeTotal'  => array_sum($counts),
			'requiredTotal'=> $counts['pending'] + $counts['processing'] + $counts['done'] + $counts['failed'],
		);
	}

	/**
	 * Persist an attachment parent aggregate after child reconciliation.
	 *
	 * @param array<string,mixed> $parent Parent queue row.
	 * @param array<string,mixed> $state  Derived child aggregate.
	 * @return array<string,mixed>
	 */
	private function persist_media_queue_parent_reconciliation(array $parent, array $state) {
		$result = array(
			'success'       => false,
			'changed'       => false,
			'previousStatus'=> (string) ($parent['status'] ?? ''),
			'parentStatus'  => (string) ($parent['status'] ?? ''),
		);
		$parent_id = absint($parent['id'] ?? 0);
		$current_status = (string) ($parent['status'] ?? 'pending');
		$next_status = (string) ($state['status'] ?? '');
		if ($parent_id <= 0 || '' === $next_status) {
			return $result;
		}
		if ('processing' === $current_status) {
			$result['success'] = true;
			return $result;
		}
		if ('failed' === $current_status && 'pending' === $next_status) {
			$result['success'] = true;
			$result['parentStatus'] = 'failed';
			return $result;
		}
		if ($current_status === $next_status) {
			$result['success'] = true;
			return $result;
		}

		$now = current_time('mysql');
		$terminal = in_array($next_status, array('done', 'skipped', 'failed'), true);
		$last_error = '';
		if ('pending' === $next_status) {
			$last_error = 'Physical media reconciliation found missing, stale, or invalid required output files.';
		} elseif ('failed' === $next_status) {
			$last_error = '' !== (string) ($parent['last_error'] ?? '')
				? (string) $parent['last_error']
				: 'A physical media conversion unit is in a terminal failed state.';
		}

		global $wpdb;
		$table = $this->get_media_queue_table_name();
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, consecutive_failures = %d, stale_recoveries = %d, last_error = %s, updated_at = %s, started_at = NULL, completed_at = NULLIF(%s, \'\') WHERE id = %d AND status = %s',
				$table,
				$next_status,
				'failed' === $next_status ? max(0, (int) ($parent['consecutive_failures'] ?? 0)) : 0,
				'failed' === $next_status ? max(0, (int) ($parent['stale_recoveries'] ?? 0)) : 0,
				$last_error,
				$now,
				$terminal ? $now : '',
				$parent_id,
				$current_status
			)
		);
		if (false === $updated) {
			return $result;
		}

		$result['success'] = true;
		$result['changed'] = 1 === (int) $updated;
		$result['parentStatus'] = $result['changed'] ? $next_status : $current_status;
		return $result;
	}

	/**
	 * Materialize, reconcile, and aggregate one attachment parent row.
	 *
	 * Existing attachment-level workers remain active in this release. This
	 * reconciliation prevents a legacy terminal parent from contradicting exact
	 * physical output state before and after that worker runs.
	 *
	 * @param int $parent_queue_id Parent media queue row ID.
	 * @return array<string,mixed>
	 */
	public function reconcile_media_queue_units_for_parent($parent_queue_id) {
		$parent_queue_id = absint($parent_queue_id);
		$result = array(
			'success'       => false,
			'parentQueueId' => $parent_queue_id,
			'attachmentId'  => 0,
			'unitTotal'     => 0,
			'unitChanged'   => 0,
			'parentChanged' => false,
			'parentStatus'  => '',
			'counts'        => array(),
			'notApplicable' => false,
			'error'         => '',
		);
		if ($parent_queue_id <= 0) {
			$result['error'] = 'media_queue_parent_invalid';
			return $result;
		}

		$sync = $this->sync_media_queue_units_for_parent($parent_queue_id);
		if (empty($sync['success'])) {
			$result['error'] = (string) ($sync['error'] ?? 'media_queue_unit_sync_failed');
			return $result;
		}
		if (!empty($sync['notApplicable'])) {
			$result['success'] = true;
			$result['notApplicable'] = true;
			return $result;
		}

		$parent = $this->get_media_queue_unit_parent_row($parent_queue_id);
		if (empty($parent)) {
			$result['error'] = 'media_queue_parent_missing';
			return $result;
		}
		$result['attachmentId'] = absint($parent['attachment_id'] ?? 0);

		$units = $this->get_media_queue_units_for_parent($parent_queue_id);
		$active_units = array();
		foreach ($units as $unit) {
			if ('superseded' === (string) ($unit['status'] ?? '')) {
				continue;
			}
			$inspection = $this->inspect_media_queue_unit_filesystem_state($unit);
			$persisted = $this->persist_media_queue_unit_reconciliation($unit, $inspection);
			if (empty($persisted['success'])) {
				$result['error'] = 'media_queue_unit_reconciliation_update_failed';
				return $result;
			}
			if (!empty($persisted['changed'])) {
				$result['unitChanged']++;
			}
			$unit['status'] = (string) ($persisted['status'] ?? ($unit['status'] ?? 'pending'));
			$active_units[] = $unit;
		}

		$result['unitTotal'] = count($active_units);
		if (empty($active_units)) {
			$result['error'] = 'media_queue_unit_inventory_unavailable';
			return $result;
		}

		$state = $this->derive_media_queue_parent_state_from_units($active_units);
		$parent_result = $this->persist_media_queue_parent_reconciliation($parent, $state);
		if (empty($parent_result['success'])) {
			$result['error'] = 'media_queue_parent_reconciliation_update_failed';
			return $result;
		}

		$result['success'] = true;
		$result['parentChanged'] = !empty($parent_result['changed']);
		if ($result['parentChanged'] && method_exists($this, 'invalidate_media_work_summary_cache')) {
			$this->invalidate_media_work_summary_cache();
		}
		$result['parentStatus'] = (string) ($parent_result['parentStatus'] ?? ($parent['status'] ?? ''));
		$result['counts'] = (array) ($state['counts'] ?? array());
		return $result;
	}

	/**
	 * Materialize and reconcile an existing attachment parent queue row.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $format        Parent output policy.
	 * @param bool   $create_parent Create a pending parent when absent.
	 * @return array<string,mixed>
	 */
	public function reconcile_media_queue_units_for_attachment($attachment_id, $format = 'best', $create_parent = false) {
		$attachment_id = absint($attachment_id);
		$format = $this->normalize_media_queue_format($format);
		if ($attachment_id <= 0 || !$this->ensure_media_queue_table()) {
			return array('success' => false, 'error' => 'media_queue_attachment_invalid');
		}

		$parents = array();
		if (in_array($format, array('avif', 'webp'), true)) {
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, format FROM %i WHERE source_kind = 'attachment' AND attachment_id = %d ORDER BY id ASC",
					$table,
					$attachment_id
				),
				ARRAY_A
			);
			foreach ((array) $rows as $row) {
				$parent_format = $this->normalize_media_queue_format($row['format'] ?? 'best');
				if (in_array($format, $this->get_media_queue_unit_output_formats($parent_format), true)) {
					$parents[] = $row;
				}
			}
		} else {
			$parent = $this->get_media_queue_unit_parent_row_for_attachment($attachment_id, $format);
			if (!empty($parent)) {
				$parents[] = $parent;
			}
		}

		if (empty($parents) && $create_parent) {
			if (!$this->upsert_media_queue_item($attachment_id, $format, 'pending', '', 0, false)) {
				return array('success' => false, 'error' => 'media_queue_parent_create_failed');
			}
			$parent = $this->get_media_queue_unit_parent_row_for_attachment($attachment_id, $format);
			if (!empty($parent)) {
				$parents[] = $parent;
			}
		}
		if (empty($parents)) {
			return array(
				'success'       => true,
				'notApplicable' => true,
				'attachmentId'  => $attachment_id,
				'format'        => $format,
			);
		}

		$results = array();
		$priority = array('failed' => 4, 'pending' => 3, 'processing' => 3, 'done' => 2, 'skipped' => 1);
		$aggregate_status = '';
		$aggregate_priority = 0;
		$unit_total = 0;
		$unit_changed = 0;
		$parent_changed = 0;
		foreach ($parents as $parent) {
			$reconciled = $this->reconcile_media_queue_units_for_parent((int) ($parent['id'] ?? 0));
			$results[] = $reconciled;
			if (empty($reconciled['success'])) {
				return array(
					'success'      => false,
					'attachmentId' => $attachment_id,
					'format'       => $format,
					'parents'      => $results,
					'error'        => (string) ($reconciled['error'] ?? 'media_queue_attachment_reconciliation_failed'),
				);
			}
			$status = (string) ($reconciled['parentStatus'] ?? '');
			$status_priority = (int) ($priority[$status] ?? 0);
			if ($status_priority > $aggregate_priority) {
				$aggregate_priority = $status_priority;
				$aggregate_status = $status;
			}
			$unit_total += max(0, (int) ($reconciled['unitTotal'] ?? 0));
			$unit_changed += max(0, (int) ($reconciled['unitChanged'] ?? 0));
			$parent_changed += !empty($reconciled['parentChanged']) ? 1 : 0;
		}

		return array(
			'success'       => true,
			'attachmentId'  => $attachment_id,
			'format'        => $format,
			'parentStatus'  => $aggregate_status,
			'parentCount'   => count($results),
			'parentChanged' => $parent_changed > 0,
			'unitTotal'     => $unit_total,
			'unitChanged'   => $unit_changed,
			'parents'       => $results,
		);
	}

	/**
	 * Delete child rows for attachment parents of one queue format before rebuild.
	 *
	 * @param string $format Parent output policy.
	 * @return int
	 */
	public function delete_media_queue_units_for_parent_format($format) {
		if (!$this->media_queue_table_exists() || !$this->media_queue_units_table_exists()) {
			return 0;
		}
		$format = $this->normalize_media_queue_format($format);
		global $wpdb;
		$parent_table = $this->get_media_queue_table_name();
		$units_table = $this->get_media_queue_units_table_name();
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE units FROM %i units INNER JOIN %i parents ON parents.id = units.parent_queue_id WHERE parents.source_kind = 'attachment' AND parents.format = %s",
				$units_table,
				$parent_table,
				$format
			)
		);
		return is_numeric($deleted) ? max(0, (int) $deleted) : 0;
	}

	/**
	 * Delete child rows for an exact bounded set of parent IDs.
	 *
	 * @param array<int,int> $parent_ids Parent row IDs.
	 * @return int
	 */
	public function delete_media_queue_units_for_parent_ids(array $parent_ids) {
		$parent_ids = array_values(array_filter(array_unique(array_map('absint', $parent_ids))));
		if (empty($parent_ids) || !$this->media_queue_units_table_exists()) {
			return 0;
		}
		global $wpdb;
		$table = $this->get_media_queue_units_table_name();
		$total = 0;
		foreach (array_chunk($parent_ids, 20) as $chunk) {
			$chunk = array_pad($chunk, 20, 0);
			$deleted = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE parent_queue_id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)',
					$table,
					$chunk[0],
					$chunk[1],
					$chunk[2],
					$chunk[3],
					$chunk[4],
					$chunk[5],
					$chunk[6],
					$chunk[7],
					$chunk[8],
					$chunk[9],
					$chunk[10],
					$chunk[11],
					$chunk[12],
					$chunk[13],
					$chunk[14],
					$chunk[15],
					$chunk[16],
					$chunk[17],
					$chunk[18],
					$chunk[19]
				)
			);
			if (is_numeric($deleted)) {
				$total += max(0, (int) $deleted);
			}
		}
		return $total;
	}

	/**
	 * Remove bounded physical-unit rows whose parent queue row no longer exists.
	 *
	 * @param int $limit Maximum orphan rows to remove.
	 * @return int
	 */
	public function cleanup_orphaned_media_queue_units($limit = 250) {
		$limit = max(1, min(1000, (int) $limit));
		if (!$this->media_queue_table_exists() || !$this->media_queue_units_table_exists()) {
			return 0;
		}
		global $wpdb;
		$parent_table = $this->get_media_queue_table_name();
		$units_table = $this->get_media_queue_units_table_name();
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT units.id FROM %i units LEFT JOIN %i parents ON parents.id = units.parent_queue_id WHERE parents.id IS NULL ORDER BY units.id ASC LIMIT %d',
				$units_table,
				$parent_table,
				$limit
			)
		);
		$ids = array_values(array_filter(array_unique(array_map('absint', (array) $ids))));
		if (empty($ids)) {
			return 0;
		}
		$total = 0;
		foreach (array_chunk($ids, 20) as $chunk) {
			$chunk = array_pad($chunk, 20, 0);
			$deleted = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)',
					$units_table,
					$chunk[0],
					$chunk[1],
					$chunk[2],
					$chunk[3],
					$chunk[4],
					$chunk[5],
					$chunk[6],
					$chunk[7],
					$chunk[8],
					$chunk[9],
					$chunk[10],
					$chunk[11],
					$chunk[12],
					$chunk[13],
					$chunk[14],
					$chunk[15],
					$chunk[16],
					$chunk[17],
					$chunk[18],
					$chunk[19]
				)
			);
			if (is_numeric($deleted)) {
				$total += max(0, (int) $deleted);
			}
		}
		return $total;
	}

	/**
	 * Run a bounded lazy migration/reconciliation pass over existing parents.
	 *
	 * @param int   $limit       Maximum parent rows.
	 * @param float $time_budget Maximum runtime in seconds.
	 * @return array<string,mixed>
	 */
	public function run_media_queue_units_migration_maintenance($limit = 25, $time_budget = 2.0) {
		$limit = max(1, min(100, (int) $limit));
		$time_budget = max(0.25, min(10.0, (float) $time_budget));
		$result = array(
			'success'        => false,
			'processed'      => 0,
			'failed'         => 0,
			'changedParents' => 0,
			'changedUnits'   => 0,
			'cursor'         => 0,
			'complete'       => false,
			'orphansRemoved' => 0,
			'lastError'      => '',
			'error'          => '',
		);
		if (!$this->ensure_media_queue_table() || !$this->ensure_media_queue_units_table()) {
			$result['error'] = 'media_queue_unit_storage_unavailable';
			return $result;
		}

		$state = get_option(self::MEDIA_QUEUE_UNITS_MIGRATION_STATE_OPTION, array());
		$cursor = max(0, (int) (is_array($state) ? ($state['cursor'] ?? 0) : 0));
		$deadline = microtime(true) + $time_budget;
		global $wpdb;
		$parent_table = $this->get_media_queue_table_name();
		$units_table = $this->get_media_queue_units_table_name();
		$no_inventory_message = 'No supported physical image files were found.';
		if (!empty($state['complete'])) {
			$inconsistent_parent_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT parents.id FROM %i parents INNER JOIN %i units ON units.parent_queue_id = parents.id AND units.status <> 'superseded' WHERE parents.source_kind = 'attachment' AND parents.status IN ('done','skipped') AND units.status IN ('pending','processing','failed') ORDER BY parents.id ASC LIMIT 1",
					$parent_table,
					$units_table
				)
			);
			if ($inconsistent_parent_id > 0) {
				$cursor = max(0, $inconsistent_parent_id - 1);
			}

			$missing_parent_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT parents.id FROM %i parents LEFT JOIN %i units ON units.parent_queue_id = parents.id AND units.status <> 'superseded' WHERE parents.source_kind = 'attachment' AND NOT (parents.status = 'skipped' AND parents.last_error = %s) GROUP BY parents.id HAVING COUNT(units.id) = 0 ORDER BY parents.id ASC LIMIT 1",
					$parent_table,
					$units_table,
					$no_inventory_message
				)
			);
			if ($inconsistent_parent_id <= 0 && $missing_parent_id > 0) {
				$cursor = max(0, $missing_parent_id - 1);
			}
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE source_kind = 'attachment' AND NOT (status = 'skipped' AND last_error = %s) AND id > %d ORDER BY id ASC LIMIT %d",
				$parent_table,
				$no_inventory_message,
				$cursor,
				$limit
			),
			ARRAY_A
		);

		foreach ((array) $rows as $row) {
			if ($result['processed'] > 0 && microtime(true) >= $deadline) {
				break;
			}
			$parent_id = absint($row['id'] ?? 0);
			if ($parent_id <= 0) {
				continue;
			}
			$reconciled = $this->reconcile_media_queue_units_for_parent($parent_id);
			$cursor = max($cursor, $parent_id);
			$result['processed']++;
			if (empty($reconciled['success'])) {
				$result['failed']++;
				$result['lastError'] = (string) ($reconciled['error'] ?? 'media_queue_unit_reconciliation_failed');
			}
			if (!empty($reconciled['parentChanged'])) {
				$result['changedParents']++;
			}
			$result['changedUnits'] += max(0, (int) ($reconciled['unitChanged'] ?? 0));
		}

		$has_more = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM %i WHERE source_kind = 'attachment' AND NOT (status = 'skipped' AND last_error = %s) AND id > %d LIMIT 1",
				$parent_table,
				$no_inventory_message,
				$cursor
			)
		);
		$result['orphansRemoved'] = $this->cleanup_orphaned_media_queue_units(250);
		$result['cursor'] = $cursor;
		$result['complete'] = !$has_more;
		$result['success'] = 0 === $result['failed'];
		$result['error'] = $result['success'] ? '' : 'media_queue_unit_reconciliation_incomplete';
		update_option(
			self::MEDIA_QUEUE_UNITS_MIGRATION_STATE_OPTION,
			array(
				'cursor'      => $cursor,
				'complete'    => !$has_more,
				'updatedAt'   => time(),
				'completedAt' => !$has_more ? time() : 0,
			),
			false
		);
		return $result;
	}

	/**
	 * Read exact attachment and physical-unit queue diagnostics for replacement readiness.
	 *
	 * The output is keyed by attachment ID. Physical units are keyed by their normalized
	 * uploads-relative source path, allowing readiness to report the failure belonging to
	 * the exact main image or intermediate file instead of the attachment aggregate.
	 *
	 * @param int[]  $attachment_ids Attachment IDs.
	 * @param string $output_format  Concrete output format.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_media_queue_readiness_diagnostics(array $attachment_ids, $output_format) {
		$attachment_ids = array_values(array_filter(array_unique(array_map('absint', $attachment_ids))));
		$output_format = strtolower(trim((string) $output_format));
		if (empty($attachment_ids) || !in_array($output_format, array('avif', 'webp'), true) || !$this->ensure_media_queue_table() || !$this->ensure_media_queue_units_table()) {
			return array();
		}

		global $wpdb;
		$parents_table = $this->get_media_queue_table_name();
		$units_table = $this->get_media_queue_units_table_name();
		$contexts = array();
		foreach ($attachment_ids as $attachment_id) {
			$contexts[$attachment_id] = array(
				'parentStatus' => '',
				'parentIds'    => array(),
				'units'        => array(),
			);
		}

		$parent_priority = array('processing' => 5, 'pending' => 4, 'failed' => 3, 'done' => 2, 'skipped' => 1);
		$unit_priority = array('processing' => 60, 'failed' => 50, 'semantic_skipped' => 45, 'pending' => 40, 'done' => 20, 'skipped' => 10);

		foreach (array_chunk($attachment_ids, 20) as $chunk) {
			$chunk = array_pad($chunk, 20, 0);
			$parents = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, attachment_id, format, status FROM %i WHERE source_kind = 'attachment' AND attachment_id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d) ORDER BY id ASC",
					$parents_table,
					$chunk[0],
					$chunk[1],
					$chunk[2],
					$chunk[3],
					$chunk[4],
					$chunk[5],
					$chunk[6],
					$chunk[7],
					$chunk[8],
					$chunk[9],
					$chunk[10],
					$chunk[11],
					$chunk[12],
					$chunk[13],
					$chunk[14],
					$chunk[15],
					$chunk[16],
					$chunk[17],
					$chunk[18],
					$chunk[19]
				),
				ARRAY_A
			);
			$compatible_parent_ids = array();
			$parents_by_id = array();

			foreach ($parents as $parent) {
				$attachment_id = absint($parent['attachment_id'] ?? 0);
				$parent_id = absint($parent['id'] ?? 0);
				if ($attachment_id <= 0 || $parent_id <= 0) {
					continue;
				}
				$compatible_parent_ids[] = $parent_id;
				$parents_by_id[$parent_id] = $parent;
			}

			$compatible_parent_ids = array_values(array_unique(array_filter(array_map('absint', $compatible_parent_ids))));
			if (empty($compatible_parent_ids)) {
				continue;
			}

			$units = array();
			foreach (array_chunk($compatible_parent_ids, 20) as $parent_chunk) {
				$parent_chunk = array_pad($parent_chunk, 20, 0);
				$unit_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, parent_queue_id, attachment_id, item_scope, size_name, source_relative_path, target_relative_path, output_format, status, attempts, consecutive_failures, stale_recoveries, failure_code, failure_stage, failure_detail, resolution_code, resolution_detail, resolution_context, encoder_attempts, updated_at, started_at, completed_at FROM %i WHERE parent_queue_id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d) AND output_format = %s AND status <> 'superseded' ORDER BY id ASC",
						$units_table,
						$parent_chunk[0],
						$parent_chunk[1],
						$parent_chunk[2],
						$parent_chunk[3],
						$parent_chunk[4],
						$parent_chunk[5],
						$parent_chunk[6],
						$parent_chunk[7],
						$parent_chunk[8],
						$parent_chunk[9],
						$parent_chunk[10],
						$parent_chunk[11],
						$parent_chunk[12],
						$parent_chunk[13],
						$parent_chunk[14],
						$parent_chunk[15],
						$parent_chunk[16],
						$parent_chunk[17],
						$parent_chunk[18],
						$parent_chunk[19],
						$output_format
					),
					ARRAY_A
				);
				if (is_array($unit_rows)) {
					$units = array_merge($units, $unit_rows);
				}
			}
			foreach ($units as $unit) {
				$attachment_id = absint($unit['attachment_id'] ?? 0);
				$parent_id = absint($unit['parent_queue_id'] ?? 0);
				$source_relative_path = ltrim(str_replace('\\', '/', (string) ($unit['source_relative_path'] ?? '')), '/');
				if ($attachment_id <= 0 || $parent_id <= 0 || '' === $source_relative_path || !isset($contexts[$attachment_id]) || !isset($parents_by_id[$parent_id])) {
					continue;
				}
				$contexts[$attachment_id]['parentIds'][] = $parent_id;
				$parent_status = sanitize_key((string) ($parents_by_id[$parent_id]['status'] ?? ''));
				$existing_parent_status = (string) ($contexts[$attachment_id]['parentStatus'] ?? '');
				if ('' === $existing_parent_status || (int) ($parent_priority[$parent_status] ?? 0) > (int) ($parent_priority[$existing_parent_status] ?? 0)) {
					$contexts[$attachment_id]['parentStatus'] = $parent_status;
				}
				$status = sanitize_key((string) ($unit['status'] ?? ''));
				$resolution_code = sanitize_key((string) ($unit['resolution_code'] ?? ''));
				$priority_key = ('skipped' === $status && '' !== $resolution_code) ? 'semantic_skipped' : $status;
				$attempts = max(0, (int) ($unit['attempts'] ?? 0));
				$existing = $contexts[$attachment_id]['units'][$source_relative_path] ?? null;
				$existing_status = is_array($existing) ? (string) ($existing['status'] ?? '') : '';
				$existing_resolution = is_array($existing) ? sanitize_key((string) ($existing['skippedReason'] ?? '')) : '';
				$existing_priority_key = ('skipped' === $existing_status && '' !== $existing_resolution) ? 'semantic_skipped' : $existing_status;
				$replace = !is_array($existing)
					|| (int) ($unit_priority[$priority_key] ?? 0) > (int) ($unit_priority[$existing_priority_key] ?? 0)
					|| ((int) ($unit_priority[$priority_key] ?? 0) === (int) ($unit_priority[$existing_priority_key] ?? 0) && $attempts >= (int) ($existing['attempts'] ?? 0));
				if (!$replace) {
					continue;
				}

				$encoder_attempts = json_decode((string) ($unit['encoder_attempts'] ?? ''), true);
				$contexts[$attachment_id]['units'][$source_relative_path] = array(
					'unitId'              => absint($unit['id'] ?? 0),
					'parentQueueId'       => absint($unit['parent_queue_id'] ?? 0),
					'itemScope'           => in_array((string) ($unit['item_scope'] ?? ''), array('main', 'intermediate'), true) ? (string) $unit['item_scope'] : 'main',
					'sizeName'            => substr(sanitize_key((string) ($unit['size_name'] ?? '')), 0, 64),
					'sourceRelativePath'  => $source_relative_path,
					'targetRelativePath'  => ltrim(str_replace('\\', '/', (string) ($unit['target_relative_path'] ?? '')), '/'),
					'outputFormat'        => $output_format,
					'status'              => $status,
					'attempts'            => $attempts,
					'consecutiveFailures' => max(0, (int) ($unit['consecutive_failures'] ?? 0)),
					'staleRecoveries'     => max(0, (int) ($unit['stale_recoveries'] ?? 0)),
					'failureCode'         => sanitize_key((string) ($unit['failure_code'] ?? '')),
					'failureStage'        => sanitize_key((string) ($unit['failure_stage'] ?? '')),
					'failureDetail'       => sanitize_text_field((string) ($unit['failure_detail'] ?? '')),
					'skippedReason'       => sanitize_key((string) ($unit['resolution_code'] ?? '')),
					'skipDetail'          => sanitize_text_field((string) ($unit['resolution_detail'] ?? '')),
					'resolutionContext'   => sanitize_text_field((string) ($unit['resolution_context'] ?? '')),
					'encoderAttempts'     => is_array($encoder_attempts) ? array_slice($encoder_attempts, 0, 10) : array(),
					'updatedAt'           => sanitize_text_field((string) ($unit['updated_at'] ?? '')),
					'startedAt'           => sanitize_text_field((string) ($unit['started_at'] ?? '')),
					'completedAt'         => sanitize_text_field((string) ($unit['completed_at'] ?? '')),
				);
			}
		}

		foreach ($contexts as &$context) {
			$context['parentIds'] = array_values(array_unique(array_map('absint', (array) $context['parentIds'])));
			ksort($context['units'], SORT_STRING);
		}
		unset($context);

		return $contexts;
	}

	/**
	 * Return persisted physical units for one parent queue row.
	 *
	 * @param int $parent_queue_id Parent media queue row ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_media_queue_units_for_parent($parent_queue_id) {
		$parent_queue_id = absint($parent_queue_id);
		if ($parent_queue_id <= 0 || !$this->ensure_media_queue_units_table()) {
			return array();
		}

		global $wpdb;
		$table = $this->get_media_queue_units_table_name();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE parent_queue_id = %d ORDER BY item_scope ASC, size_name ASC, output_format ASC, id ASC',
				$table,
				$parent_queue_id
			),
			ARRAY_A
		);
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
