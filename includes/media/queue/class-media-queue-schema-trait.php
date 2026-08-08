<?php
/**
 * UltraCache media queue schema, storage health, state, and status helpers.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Queue_Schema_Trait
{
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache uses private custom media conversion queue tables with validated table identifiers.


		private function get_media_queue_table_name() {
			global $wpdb;
			$table = $wpdb->prefix . 'ultracache_media_queue';
			return function_exists('ultracache_validate_custom_table_name') ? ultracache_validate_custom_table_name($table, 'media_queue') : $table;
		}


		private function media_queue_table_exists() {
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
			return (string) $found === (string) $table;
		}


		private function media_queue_failure_column_exists() {
			global $wpdb;
			$table  = $this->get_media_queue_table_name();
			$column = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $table, 'consecutive_failures'));
			return 'consecutive_failures' === (string) $column;
		}


		private function media_queue_stale_recovery_column_exists() {
			global $wpdb;
			$table  = $this->get_media_queue_table_name();
			$column = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $table, 'stale_recoveries'));
			return 'stale_recoveries' === (string) $column;
		}


		private function media_queue_local_source_columns_exist() {
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$required = array('source_kind', 'source_identity', 'source_scope', 'source_owner', 'source_relative_path', 'source_url', 'requested_format');
			$columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table));
			return empty(array_diff($required, array_map('strval', (array) $columns)));
		}


		private function media_queue_index_rows($index_name) {
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$rows = $wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i', $table), ARRAY_A);
			$index_name = (string) $index_name;
			$matches = array_values(array_filter((array) $rows, static function ($row) use ($index_name) {
				return $index_name === (string) ($row['Key_name'] ?? '');
			}));
			usort($matches, static function ($left, $right) {
				return ((int) ($left['Seq_in_index'] ?? 0)) <=> ((int) ($right['Seq_in_index'] ?? 0));
			});
			return $matches;
		}


		private function media_queue_index_matches($index_name, array $columns, $unique) {
			$rows = $this->media_queue_index_rows($index_name);
			if (empty($rows)) {
				return false;
			}
			$actual_columns = array_map(static function ($row) {
				return (string) ($row['Column_name'] ?? '');
			}, $rows);
			$actual_unique = '0' === (string) ($rows[0]['Non_unique'] ?? '1');
			return array_values($columns) === $actual_columns && (bool) $unique === $actual_unique;
		}


		private function media_queue_source_request_index_exists() {
			return $this->media_queue_index_matches(
				'source_request',
				array('source_kind', 'source_identity', 'format', 'requested_format'),
				true
			);
		}


		private function media_queue_attachment_format_index_exists() {
			return $this->media_queue_index_matches('attachment_format', array('attachment_id', 'format'), false);
		}


		private function deduplicate_media_queue_source_requests() {
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$duplicates = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT source_kind, source_identity, format, requested_format, COUNT(*) AS duplicate_count FROM %i GROUP BY source_kind, source_identity, format, requested_format HAVING COUNT(*) > 1',
					$table
				),
				ARRAY_A
			);
			foreach ((array) $duplicates as $duplicate) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, status, attempts, consecutive_failures, stale_recoveries, updated_at FROM %i WHERE source_kind = %s AND source_identity = %s AND format = %s AND requested_format = %s ORDER BY CASE status WHEN 'processing' THEN 0 WHEN 'pending' THEN 1 WHEN 'error' THEN 2 WHEN 'done' THEN 3 WHEN 'skipped' THEN 4 ELSE 5 END ASC, updated_at DESC, id DESC",
						$table,
						(string) ($duplicate['source_kind'] ?? ''),
						(string) ($duplicate['source_identity'] ?? ''),
						(string) ($duplicate['format'] ?? ''),
						(string) ($duplicate['requested_format'] ?? '')
					),
					ARRAY_A
				);
				if (count((array) $rows) < 2) {
					continue;
				}
				$survivor = array_shift($rows);
				$wpdb->update(
					$table,
					array(
						'attempts' => max(array_map(static function ($row) { return (int) ($row['attempts'] ?? 0); }, array_merge(array($survivor), $rows))),
						'consecutive_failures' => max(array_map(static function ($row) { return (int) ($row['consecutive_failures'] ?? 0); }, array_merge(array($survivor), $rows))),
						'stale_recoveries' => max(array_map(static function ($row) { return (int) ($row['stale_recoveries'] ?? 0); }, array_merge(array($survivor), $rows))),
					),
					array('id' => (int) ($survivor['id'] ?? 0)),
					array('%d', '%d', '%d'),
					array('%d')
				);
				foreach ($rows as $row) {
					$wpdb->delete($table, array('id' => (int) ($row['id'] ?? 0)), array('%d'));
				}
			}
			$remaining = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM (SELECT 1 FROM %i GROUP BY source_kind, source_identity, format, requested_format HAVING COUNT(*) > 1) duplicate_groups',
					$table
				)
			);
			return 0 === $remaining;
		}


		private function ensure_media_queue_source_identity_indexes() {
			global $wpdb;
			$table = $this->get_media_queue_table_name();

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET source_kind = 'attachment', source_identity = SHA2(CONCAT('attachment:', attachment_id), 256), requested_format = '' WHERE attachment_id > 0 AND (source_identity = '' OR source_kind = '')",
					$table
				)
			);

			if (!$this->media_queue_attachment_format_index_exists()) {
				if (!empty($this->media_queue_index_rows('attachment_format'))) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Versioned repair of an UltraCache-owned custom-table index whose existing definition cannot be corrected reliably by dbDelta().
					$wpdb->query($wpdb->prepare('ALTER TABLE %i DROP INDEX attachment_format', $table));
				}
				if (empty($this->media_queue_index_rows('attachment_format'))) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Recreates the verified UltraCache-owned index after the versioned legacy-definition repair above.
					$wpdb->query($wpdb->prepare('ALTER TABLE %i ADD KEY attachment_format (attachment_id, format)', $table));
				}
			}

			if (!$this->media_queue_source_request_index_exists()) {
				if (!empty($this->media_queue_index_rows('source_request'))) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Versioned repair of an UltraCache-owned custom-table index whose existing definition cannot be corrected reliably by dbDelta().
					$wpdb->query($wpdb->prepare('ALTER TABLE %i DROP INDEX source_request', $table));
				}
				if (!$this->deduplicate_media_queue_source_requests()) {
					return false;
				}
				if (empty($this->media_queue_index_rows('source_request'))) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Recreates the deduplicated UltraCache-owned unique index after the versioned legacy-definition repair above.
					$wpdb->query($wpdb->prepare('ALTER TABLE %i ADD UNIQUE KEY source_request (source_kind, source_identity, format, requested_format)', $table));
				}
			}

			return $this->media_queue_attachment_format_index_exists() && $this->media_queue_source_request_index_exists();
		}


		public function ensure_media_queue_table() {
			global $wpdb;

			$table = $this->get_media_queue_table_name();
			$version = (string) get_option(self::MEDIA_QUEUE_DB_VERSION_OPTION, '');
			if (self::MEDIA_QUEUE_DB_VERSION === $version && $this->media_queue_table_exists() && $this->media_queue_failure_column_exists() && $this->media_queue_stale_recovery_column_exists() && $this->media_queue_local_source_columns_exist() && $this->media_queue_attachment_format_index_exists() && $this->media_queue_source_request_index_exists()) {
				return $this->ensure_media_queue_units_table();
			}

			if (!ultracache_require_wordpress_admin_include('upgrade.php', 'dbDelta')) {
				return false;
			}
			$charset_collate = $wpdb->get_charset_collate();
			$sql = "CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				source_kind varchar(20) NOT NULL DEFAULT 'attachment',
				source_identity char(64) NOT NULL DEFAULT '',
				source_scope varchar(32) NOT NULL DEFAULT '',
				source_owner varchar(191) NOT NULL DEFAULT '',
				source_relative_path text NULL,
				source_url text NULL,
				format varchar(12) NOT NULL DEFAULT 'best',
				requested_format varchar(12) NOT NULL DEFAULT '',
				source_mtime bigint(20) unsigned NOT NULL DEFAULT 0,
				source_size bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				consecutive_failures smallint(5) unsigned NOT NULL DEFAULT 0,
				stale_recoveries smallint(5) unsigned NOT NULL DEFAULT 0,
				last_error text NULL,
				created_at datetime NULL DEFAULT NULL,
				updated_at datetime NULL DEFAULT NULL,
				started_at datetime NULL DEFAULT NULL,
				completed_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY status_id (status, id),
				KEY format_status_id (format, status, id),
				KEY updated_at (updated_at)
			) {$charset_collate};";

			dbDelta($sql);
			if ($this->media_queue_table_exists() && $this->media_queue_failure_column_exists() && $this->media_queue_stale_recovery_column_exists() && $this->media_queue_local_source_columns_exist() && $this->ensure_media_queue_source_identity_indexes()) {
				update_option(self::MEDIA_QUEUE_DB_VERSION_OPTION, self::MEDIA_QUEUE_DB_VERSION, false);
				return $this->ensure_media_queue_units_table();
			}

			return false;
		}


		private function normalize_media_queue_format($format) {
			$format = strtolower(trim((string) $format));
			return in_array($format, array('best', 'avif', 'webp', 'both'), true) ? $format : 'best';
		}


		private function get_media_queue_force_regenerate_marker($cursor = 0) {
			return '__ultracache_force_regenerate__:' . max(0, (int) $cursor);
		}


		private function parse_media_queue_force_regenerate_marker($value) {
			$value = (string) $value;
			$prefix = '__ultracache_force_regenerate__:';
			if (0 !== strpos($value, $prefix)) {
				return null;
			}

			return max(0, (int) substr($value, strlen($prefix)));
		}


		private function get_attachment_source_signature($attachment_id) {
			$file = get_attached_file(absint($attachment_id));
			if (!$file || !is_string($file) || !$this->optimized_storage_readable_source_exists($file)) {
				return array('mtime' => 0, 'size' => 0);
			}

			return array(
				'mtime' => (int) @filemtime($file),
				'size'  => (int) @filesize($file),
			);
		}


		private function optimized_output_dir_has_file($dir, $extension, $max_files = 1500, $time_budget = 0.75) {
			$dir = (string) $dir;
			$extension = strtolower(ltrim((string) $extension, '.'));
			$max_files = max(100, min(10000, (int) $max_files));
			$deadline = microtime(true) + max(0.1, min(5.0, (float) $time_budget));
			if ('' === $dir || '' === $extension || !is_dir($dir) || !is_readable($dir)) {
				return array('hasFiles' => false, 'scannedFiles' => 0, 'truncated' => false, 'timedOut' => false, 'error' => '');
			}

			$scanned = 0;
			$truncated = false;
			$timed_out = false;
			try {
				$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
				foreach ($iterator as $item) {
					if (microtime(true) >= $deadline) {
						$timed_out = true;
						$truncated = true;
						break;
					}
					if (!$item->isFile()) {
						continue;
					}
					$scanned++;
					if (strtolower($item->getExtension()) === $extension) {
						return array('hasFiles' => true, 'scannedFiles' => $scanned, 'truncated' => false, 'timedOut' => false, 'error' => '');
					}
					if ($scanned >= $max_files) {
						$truncated = true;
						break;
					}
				}
			} catch (Exception $e) {
				return array('hasFiles' => false, 'scannedFiles' => $scanned, 'truncated' => $truncated, 'timedOut' => $timed_out, 'error' => (string) $e->getMessage());
			}

			return array('hasFiles' => false, 'scannedFiles' => $scanned, 'truncated' => $truncated, 'timedOut' => $timed_out, 'error' => '');
		}


		private function get_media_optimized_storage_health_state_name($format = 'best') {
			return self::MEDIA_STORAGE_HEALTH_STATE_PREFIX . $this->normalize_media_queue_format($format);
		}

		private function get_media_optimized_storage_health_fingerprint($format = 'best') {
			$payload = array(
				'schema' => 1,
				'format' => $this->normalize_media_queue_format($format),
				'avifDir' => defined('ULTRACACHE_AVIF_DIR') ? wp_normalize_path((string) ULTRACACHE_AVIF_DIR) : '',
				'webpDir' => defined('ULTRACACHE_WEBP_DIR') ? wp_normalize_path((string) ULTRACACHE_WEBP_DIR) : '',
				'contractVersion' => 1,
			);

			return substr(hash('sha256', (string) wp_json_encode($payload)), 0, 24);
		}

		private function read_media_optimized_storage_health_state($format = 'best') {
			if (!function_exists('ultracache_get_state_record_read_only')) {
				return array();
			}

			$record = ultracache_get_state_record_read_only($this->get_media_optimized_storage_health_state_name($format));
			$payload = is_array($record['payload'] ?? null) ? $record['payload'] : array();
			$health = is_array($payload['health'] ?? null) ? $payload['health'] : array();
			if (empty($health)) {
				return array();
			}

			$fingerprint = sanitize_text_field((string) ($payload['fingerprint'] ?? ($health['fingerprint'] ?? '')));
			$scanned_at = max(0, (int) ($health['scannedAt'] ?? ($payload['recordedAt'] ?? 0)));
			$configuration_changed = '' === $fingerprint || !hash_equals($this->get_media_optimized_storage_health_fingerprint($format), $fingerprint);
			$dirty = !empty($payload['dirty']);
			$stale = $scanned_at > 0 && (time() - $scanned_at) > WEEK_IN_SECONDS;
			$health['fingerprint'] = $fingerprint;
			$health['configurationChanged'] = $configuration_changed;
			$health['dirty'] = $dirty;
			$health['stale'] = $stale;
			$health['diagnosticStatus'] = $configuration_changed ? 'configuration-changed' : (($dirty || $stale) ? 'stale' : 'current');
			$health['cached'] = true;
			$health['source'] = 'persistent';
			return $health;
		}

		private function persist_media_optimized_storage_health_state($format, array $health) {
			if (!function_exists('ultracache_mutate_state_record')) {
				return false;
			}

			$format = $this->normalize_media_queue_format($format);
			$health['scannedAt'] = max(0, (int) ($health['scannedAt'] ?? time()));
			$health['fingerprint'] = $this->get_media_optimized_storage_health_fingerprint($format);
			$health['configurationChanged'] = false;
			$health['dirty'] = false;
			$health['stale'] = false;
			$health['diagnosticStatus'] = 'current';
			$health['cached'] = false;
			$health['source'] = 'live';
			$mutation = ultracache_mutate_state_record(
				$this->get_media_optimized_storage_health_state_name($format),
				static function () use ($health) {
					return array(
						'schemaVersion' => 1,
						'recordedAt' => (int) $health['scannedAt'],
						'fingerprint' => (string) $health['fingerprint'],
						'dirty' => false,
						'invalidatedAt' => 0,
						'health' => $health,
					);
				},
				5,
				array()
			);

			return !empty($mutation['success']);
		}

		private function get_media_optimized_storage_health($format = 'best', $completed_rows = 0, $force_refresh = false) {
			$format = $this->normalize_media_queue_format($format);
			$completed_rows = max(0, (int) $completed_rows);
			$avif_dir = defined('ULTRACACHE_AVIF_DIR') ? ULTRACACHE_AVIF_DIR : '';
			$webp_dir = defined('ULTRACACHE_WEBP_DIR') ? ULTRACACHE_WEBP_DIR : '';
			$avif_dir_exists = ('' !== (string) $avif_dir && is_dir($avif_dir));
			$webp_dir_exists = ('' !== (string) $webp_dir && is_dir($webp_dir));

			$stored = $force_refresh ? array() : $this->read_media_optimized_storage_health_state($format);
			if (!$force_refresh && !empty($stored)) {
				$health = $stored;
				$health['scanSkipped'] = true;
				if ('current' !== (string) ($health['diagnosticStatus'] ?? '')) {
					$health['targetHasFiles'] = false;
					$health['targetMissing'] = false;
					$health['needsRepair'] = false;
					$health['message'] = __('Optimized storage health evidence is stale or configuration-changed. Use Recount Optimized Image Files or Rebuild / Repair Media Queue to refresh it.', 'ultracache');
				}
			} elseif ($force_refresh) {
				$avif_scan = $this->optimized_output_dir_has_file($avif_dir, 'avif');
				$webp_scan = $this->optimized_output_dir_has_file($webp_dir, 'webp');
				$health = array(
					'storageRoot' => 'uploads/ultracache/images',
					'persistentStorage' => true,
					'avifDir' => (string) $avif_dir,
					'webpDir' => (string) $webp_dir,
					'avifDirExists' => $avif_dir_exists,
					'webpDirExists' => $webp_dir_exists,
					'avifHasFiles' => !empty($avif_scan['hasFiles']),
					'webpHasFiles' => !empty($webp_scan['hasFiles']),
					'avifScan' => $avif_scan,
					'webpScan' => $webp_scan,
					'scannedAt' => time(),
					'fingerprint' => $this->get_media_optimized_storage_health_fingerprint($format),
					'configurationChanged' => false,
					'dirty' => false,
					'stale' => false,
					'diagnosticStatus' => 'current',
					'cached' => false,
					'source' => 'live',
					'scanSkipped' => false,
				);
				$this->persist_media_optimized_storage_health_state($format, $health);
			} else {
				$health = array(
					'storageRoot' => 'uploads/ultracache/images',
					'persistentStorage' => true,
					'avifDir' => (string) $avif_dir,
					'webpDir' => (string) $webp_dir,
					'avifDirExists' => $avif_dir_exists,
					'webpDirExists' => $webp_dir_exists,
					'avifHasFiles' => false,
					'webpHasFiles' => false,
					'targetHasFiles' => false,
					'targetMissing' => false,
					'needsRepair' => false,
					'cached' => false,
					'scanSkipped' => true,
					'scannedAt' => 0,
					'diagnosticStatus' => 'not-tested',
					'source' => 'none',
					'message' => __('Optimized storage health is passive. Use Recount Optimized Image Files or Rebuild / Repair Media Queue to reconcile it.', 'ultracache'),
				);
				return $health;
			}

			$avif_has_files = !empty($health['avifHasFiles']);
			$webp_has_files = !empty($health['webpHasFiles']);
			if ('avif' === $format) {
				$target_has_files = $avif_has_files;
				$target_missing = !$avif_has_files;
			} elseif ('webp' === $format) {
				$target_has_files = $webp_has_files;
				$target_missing = !$webp_has_files;
			} elseif ('both' === $format) {
				$target_has_files = ($avif_has_files && $webp_has_files);
				$target_missing = (!$avif_has_files || !$webp_has_files);
			} else {
				$target_has_files = ($avif_has_files || $webp_has_files);
				$target_missing = !$target_has_files;
			}

			$proof_current = 'current' === (string) ($health['diagnosticStatus'] ?? 'current');
			$needs_repair = ($proof_current && $completed_rows > 0 && $target_missing);
			$health['targetHasFiles'] = $proof_current ? $target_has_files : false;
			$health['targetMissing'] = $proof_current ? $target_missing : false;
			$health['needsRepair'] = $needs_repair;
			if ($proof_current) {
				$health['message'] = $needs_repair ? 'Optimized image files appear to be missing from persistent uploads/ultracache/images storage. Start/Resume or warm-up regeneration can repair missing variants without relying on the old cache directory.' : '';
			}
			return $health;
		}


		private function repair_media_queue_if_optimized_storage_missing($format = 'best') {
			if (!$this->media_queue_table_exists()) {
				return array('repaired' => false, 'requeued' => 0, 'reason' => 'table_unavailable');
			}

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			$completed_rows = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE source_kind = 'attachment' AND format = %s AND status IN ('done','skipped')", $table, $format));
			$health = $this->get_media_optimized_storage_health($format, $completed_rows, true);
			if (empty($health['needsRepair'])) {
				return array_merge(array('repaired' => false, 'requeued' => 0, 'reason' => 'not_needed'), $health);
			}

			if (defined('ULTRACACHE_AVIF_DIR') && ULTRACACHE_AVIF_DIR) {
				$this->optimized_storage_ensure_directory(ULTRACACHE_AVIF_DIR);
			}
			if (defined('ULTRACACHE_WEBP_DIR') && ULTRACACHE_WEBP_DIR) {
				$this->optimized_storage_ensure_directory(ULTRACACHE_WEBP_DIR);
			}

			$count = $wpdb->query($wpdb->prepare(
				"UPDATE %i SET status = 'pending', consecutive_failures = 0, stale_recoveries = 0, last_error = %s, updated_at = %s, started_at = NULL, completed_at = NULL WHERE source_kind = 'attachment' AND format = %s AND status IN ('done','skipped') LIMIT %d",
				$table,
				'Optimized image files were missing; queued for repair.',
				current_time('mysql'),
				$format,
				1000
			));

			return array_merge(array('repaired' => true, 'requeued' => is_numeric($count) ? (int) $count : 0, 'reason' => 'optimized_storage_missing'), $health);
		}


		public function upsert_media_queue_item($attachment_id, $format = 'best', $status = 'pending', $last_error = '', $attempts = 0, $force_pending = false) {
			$attachment_id = absint($attachment_id);
			if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
				return false;
			}
			if (!$this->ensure_media_queue_table()) {
				return false;
			}

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			$status = in_array($status, array('pending', 'processing', 'done', 'failed', 'skipped'), true) ? $status : 'pending';
			$signature = $this->get_attachment_source_signature($attachment_id);
			$source_identity = hash('sha256', 'attachment:' . $attachment_id);
			$now = current_time('mysql');

			$existing = $wpdb->get_row($wpdb->prepare("SELECT id, status, source_mtime, source_size, attempts, consecutive_failures, stale_recoveries FROM %i WHERE source_kind = 'attachment' AND attachment_id = %d AND format = %s", $table, $attachment_id, $format), ARRAY_A);
			if (is_array($existing) && !empty($existing['id'])) {
				$same_source = ((int) $existing['source_mtime'] === (int) $signature['mtime'] && (int) $existing['source_size'] === (int) $signature['size']);
				$reset_failure_state = $force_pending || !$same_source;
				$next_status = $status;
				if ('pending' === $status && in_array((string) $existing['status'], array('done', 'skipped'), true)) {
					$next_status = (!$force_pending && $same_source) ? (string) $existing['status'] : 'pending';
				}

				$wpdb->update(
					$table,
					array(
						'source_mtime'         => (int) $signature['mtime'],
						'source_size'          => (int) $signature['size'],
						'status'               => $next_status,
						'attempts'             => max((int) $attempts, (int) $existing['attempts']),
						'consecutive_failures' => $reset_failure_state ? 0 : max(0, (int) $existing['consecutive_failures']),
						'stale_recoveries'     => $reset_failure_state ? 0 : max(0, (int) $existing['stale_recoveries']),
						'last_error'           => (string) $last_error,
						'updated_at'           => $now,
					),
					array('id' => (int) $existing['id']),
					array('%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s'),
					array('%d')
				);
				return true;
			}

			$created = $wpdb->insert(
				$table,
				array(
					'attachment_id' => $attachment_id,
					'source_kind' => 'attachment',
					'source_identity' => $source_identity,
					'format'        => $format,
					'requested_format' => '',
					'source_mtime'         => (int) $signature['mtime'],
					'source_size'          => (int) $signature['size'],
					'status'               => $status,
					'attempts'             => max(0, (int) $attempts),
					'consecutive_failures' => 0,
					'stale_recoveries'     => 0,
					'last_error'           => (string) $last_error,
					'created_at'           => $now,
					'updated_at'           => $now,
				),
				array('%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s')
			);

			return false !== $created;
		}



		private function normalize_local_asset_queue_source(array $source, $requested_format = '') {
			$requested_format = strtolower(trim((string) $requested_format));
			if (!in_array($requested_format, array('avif', 'webp'), true)) {
				return array();
			}

			$url = esc_url_raw((string) ($source['public_url'] ?? $source['source_url'] ?? ''));
			if ('' === $url || !function_exists('ultracache_local_public_source_descriptor')) {
				return array();
			}
			$canonical = ultracache_local_public_source_descriptor($url, array('jpg', 'jpeg', 'png', 'webp', 'avif'));
			if (empty($canonical) || 'uploads' === (string) ($canonical['source_scope'] ?? '')) {
				return array();
			}

			$identity = strtolower(trim((string) ($canonical['source_identity'] ?? '')));
			$provided_identity = strtolower(trim((string) ($source['source_identity'] ?? '')));
			if ('' !== $provided_identity && (!preg_match('/^[a-f0-9]{64}$/', $provided_identity) || !hash_equals($identity, $provided_identity))) {
				return array();
			}
			$scope = sanitize_key((string) ($canonical['source_scope'] ?? ''));
			$owner = sanitize_text_field((string) ($canonical['source_owner'] ?? ''));
			$relative_path = ltrim(str_replace('\\', '/', (string) ($canonical['source_relative_path'] ?? '')), '/');
			$canonical_url = esc_url_raw((string) ($canonical['public_url'] ?? $url));
			$local_path = wp_normalize_path((string) ($canonical['local_path'] ?? ''));
			if (!preg_match('/^[a-f0-9]{64}$/', $identity) || '' === $scope || '' === $relative_path || '' === $canonical_url || '' === $local_path) {
				return array();
			}
			if (!$this->optimized_storage_readable_source_exists($local_path) || !$this->is_source_file_supported_for_format($local_path, $requested_format)) {
				return array();
			}

			$fingerprint = $this->get_optimized_source_fingerprint($local_path, true);
			if (empty($fingerprint['exists'])) {
				return array();
			}

			return array(
				'source_kind' => 'local_asset',
				'source_identity' => $identity,
				'source_scope' => $scope,
				'source_owner' => $owner,
				'source_relative_path' => $relative_path,
				'source_url' => $canonical_url,
				'local_path' => $local_path,
				'requested_format' => $requested_format,
				'source_mtime' => max(0, (int) ($fingerprint['mtime'] ?? 0)),
				'source_size' => max(0, (int) ($fingerprint['size'] ?? 0)),
			);
		}


		public function upsert_local_asset_media_queue_item(array $source, $requested_format, $status = 'pending', $last_error = '', $attempts = 0, $force_pending = false) {
			$source = $this->normalize_local_asset_queue_source($source, $requested_format);
			if (empty($source) || !$this->ensure_media_queue_table()) {
				return false;
			}

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$status = in_array($status, array('pending', 'processing', 'done', 'failed', 'skipped'), true) ? $status : 'pending';
			$queue_format = 'best';
			$now = current_time('mysql');
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id, status, source_mtime, source_size, attempts, consecutive_failures, stale_recoveries FROM %i WHERE source_kind = %s AND source_identity = %s AND format = %s AND requested_format = %s',
					$table,
					'local_asset',
					$source['source_identity'],
					$queue_format,
					$source['requested_format']
				),
				ARRAY_A
			);
			if (is_array($existing) && !empty($existing['id'])) {
				if ('processing' === (string) ($existing['status'] ?? '')) {
					return true;
				}
				$same_source = (int) $existing['source_mtime'] === (int) $source['source_mtime'] && (int) $existing['source_size'] === (int) $source['source_size'];
				$reset = $force_pending || !$same_source;
				$next_status = $status;
				if ('pending' === $status && in_array((string) $existing['status'], array('done', 'skipped'), true)) {
					$next_status = (!$force_pending && $same_source) ? (string) $existing['status'] : 'pending';
				}
				$update_data = array(
					'source_scope' => $source['source_scope'],
					'source_owner' => $source['source_owner'],
					'source_relative_path' => $source['source_relative_path'],
					'source_url' => $source['source_url'],
					'source_mtime' => $source['source_mtime'],
					'source_size' => $source['source_size'],
					'status' => $next_status,
					'attempts' => max((int) $attempts, (int) $existing['attempts']),
					'consecutive_failures' => $reset ? 0 : max(0, (int) $existing['consecutive_failures']),
					'stale_recoveries' => $reset ? 0 : max(0, (int) $existing['stale_recoveries']),
					'last_error' => (string) $last_error,
					'updated_at' => $now,
				);
				$update_formats = array('%s','%s','%s','%s','%d','%d','%s','%d','%d','%d','%s','%s');
				if ($reset) {
					$update_data['started_at'] = null;
					$update_data['completed_at'] = null;
					$update_formats[] = '%s';
					$update_formats[] = '%s';
				}
				$updated = $wpdb->update(
					$table,
					$update_data,
					array('id' => (int) $existing['id']),
					$update_formats,
					array('%d')
				);
				return false !== $updated;
			}

			$created = $wpdb->insert(
				$table,
				array(
					'attachment_id' => 0,
					'source_kind' => 'local_asset',
					'source_identity' => $source['source_identity'],
					'source_scope' => $source['source_scope'],
					'source_owner' => $source['source_owner'],
					'source_relative_path' => $source['source_relative_path'],
					'source_url' => $source['source_url'],
					'format' => $queue_format,
					'requested_format' => $source['requested_format'],
					'source_mtime' => $source['source_mtime'],
					'source_size' => $source['source_size'],
					'status' => $status,
					'attempts' => max(0, (int) $attempts),
					'consecutive_failures' => 0,
					'stale_recoveries' => 0,
					'last_error' => (string) $last_error,
					'created_at' => $now,
					'updated_at' => $now,
				),
				array('%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%d','%d','%d','%s','%s','%s')
			);
			return false !== $created;
		}


		private function is_background_media_queue_enabled() {
			return !$this->is_media_background_work_paused()
				&& $this->is_media_optimization_enabled()
				&& ($this->is_generate_on_upload_enabled() || $this->is_generate_on_demand_enabled());
		}


		private function get_media_queue_pending_count($format = null) {
			if (!$this->media_queue_table_exists()) {
				return 0;
			}
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			if (null !== $format) {
				$format = $this->normalize_media_queue_format($format);
				return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status = 'pending' AND format = %s", $table, $format));
			}

			return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status = 'pending'", $table));
		}


		private function get_media_queue_processing_count($format = null) {
			if (!$this->media_queue_table_exists()) {
				return 0;
			}
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			if (null !== $format) {
				$format = $this->normalize_media_queue_format($format);
				return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status = 'processing' AND format = %s", $table, $format));
			}

			return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status = 'processing'", $table));
		}


		private function get_media_queue_max_stale_recoveries() {
			$limit = (int) apply_filters('ultracache_media_queue_max_stale_recoveries', 3);
			return max(1, min(100, $limit));
		}


		public function reset_stale_media_queue_items($lock_token = '') {
			if (!$this->ensure_media_queue_table()) {
				return 0;
			}

			if ('' === (string) $lock_token || !function_exists('ultracache_get_lock')) {
				return 0;
			}

			$lock = ultracache_get_lock(self::MEDIA_QUEUE_PROCESS_LOCK);
			if (
				empty($lock['token'])
				|| !empty($lock['expired'])
				|| !hash_equals((string) $lock['token'], (string) $lock_token)
			) {
				return 0;
			}

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$cutoff_gmt = gmdate('Y-m-d H:i:s', time() - self::MEDIA_QUEUE_PROCESSING_TTL);
			$cutoff = get_date_from_gmt($cutoff_gmt);
			$lock_acquired_gmt = gmdate('Y-m-d H:i:s', max(0, (int) ($lock['acquiredAt'] ?? time())));
			$lock_acquired = get_date_from_gmt($lock_acquired_gmt);
			$now = current_time('mysql');
			$max_stale_recoveries = $this->get_media_queue_max_stale_recoveries();
			$terminal_recovery_index = max(0, $max_stale_recoveries - 1);
			$force_regenerate_error_like = $wpdb->esc_like('__ultracache_force_regenerate__:') . '%';
			$worker_terminated_error = 'media_worker_terminated';
			$recovery_limit_error = 'media_worker_stale_recovery_limit';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only the current global lease owner may recover claims created before its acquisition. The dedicated stale counter is incremented atomically and never conflated with conversion failures or work-unit attempts.
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = CASE WHEN stale_recoveries >= %d THEN 'failed' ELSE 'pending' END, last_error = CASE WHEN stale_recoveries >= %d THEN %s WHEN last_error LIKE %s THEN last_error ELSE %s END, updated_at = %s, started_at = NULL, completed_at = CASE WHEN stale_recoveries >= %d THEN %s ELSE NULL END, stale_recoveries = LEAST(65535, stale_recoveries + 1) WHERE status = 'processing' AND updated_at < %s AND updated_at < %s",
					$table,
					$terminal_recovery_index,
					$terminal_recovery_index,
					$recovery_limit_error,
					$force_regenerate_error_like,
					$worker_terminated_error,
					$now,
					$terminal_recovery_index,
					$now,
					$cutoff,
					$lock_acquired
				)
			);
			$stale_count = is_numeric($result) ? max(0, (int) $result) : 0;
			$unit_stale_count = method_exists($this, 'reset_stale_media_queue_unit_items')
				? max(0, (int) $this->reset_stale_media_queue_unit_items($lock_token))
				: 0;
			if (($stale_count + $unit_stale_count) <= 0) {
				return 0;
			}

			$this->invalidate_media_work_summary_cache();

			return $stale_count + $unit_stale_count;
		}



		/**
		 * Return all currently claimed rows to pending after an administrator pause.
		 *
		 * @return int Number of rows reset.
		 */
		public function reset_active_media_queue_items() {
			if (!$this->media_queue_table_exists()) {
				return 0;
			}

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom media queue table is authoritative and must be reset immediately when an administrator pauses generation.
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = 'pending', last_error = %s, updated_at = %s, started_at = NULL, completed_at = NULL WHERE status = 'processing'",
					$table,
					'Paused by administrator.',
					current_time('mysql')
				)
			);
			$parent_count = is_numeric($result) ? max(0, (int) $result) : 0;
			$unit_count = method_exists($this, 'reset_active_media_queue_unit_items')
				? max(0, (int) $this->reset_active_media_queue_unit_items())
				: 0;
			return $parent_count + $unit_count;
		}


		public function get_media_queue_status($format = 'best', $force_storage_refresh = false) {
			if (!$this->ensure_media_queue_table()) {
				return array('enabled' => false, 'message' => __('Media queue table unavailable.', 'ultracache'));
			}

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			$rows = $wpdb->get_results($wpdb->prepare("SELECT source_kind, status, COUNT(*) AS count FROM %i WHERE format = %s GROUP BY source_kind, status", $table, $format), ARRAY_A);
			$empty_counts = array('pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0);
			$counts = $empty_counts;
			$attachment_counts = $empty_counts;
			$local_asset_counts = $empty_counts;
			foreach ((array) $rows as $row) {
				$status = isset($row['status']) ? (string) $row['status'] : '';
				$source_kind = isset($row['source_kind']) ? (string) $row['source_kind'] : 'attachment';
				if (!array_key_exists($status, $counts)) {
					continue;
				}
				$count = (int) $row['count'];
				$counts[$status] += $count;
				if ('local_asset' === $source_kind) {
					$local_asset_counts[$status] += $count;
				} else {
					$attachment_counts[$status] += $count;
				}
			}
			$total = array_sum($counts);
			$completed = (int) ($counts['done'] + $counts['skipped']);
			$remaining = (int) ($counts['pending'] + $counts['processing']);
			$attachment_total = array_sum($attachment_counts);
			$attachment_completed = (int) ($attachment_counts['done'] + $attachment_counts['skipped']);
			$attachment_remaining = (int) ($attachment_counts['pending'] + $attachment_counts['processing']);
			$local_asset_total = array_sum($local_asset_counts);
			$local_asset_completed = (int) ($local_asset_counts['done'] + $local_asset_counts['skipped']);
			$local_asset_remaining = (int) ($local_asset_counts['pending'] + $local_asset_counts['processing']);
			$build_state = $this->get_media_queue_build_state($format);
			$build_complete = !empty($build_state['complete']);
			$unit_status = method_exists($this, 'get_media_queue_unit_status_summary')
				? $this->get_media_queue_unit_status_summary($format)
				: array();
			$unit_inventory_complete = $build_complete
				&& !empty($unit_status['unitStatusAvailable'])
				&& !empty($unit_status['unitCoverageComplete']);
			$unit_status['unitInventoryComplete'] = $unit_inventory_complete;
			$unit_status['unitIsComplete'] = $unit_inventory_complete
				&& 0 === (int) ($unit_status['unitOutstanding'] ?? 0);
			$attachment_is_complete = $build_complete
				&& 0 === $attachment_remaining
				&& 0 === (int) $attachment_counts['failed']
				&& !empty($unit_status['unitIsComplete']);
			$queue_is_complete = $attachment_is_complete
				&& 0 === $local_asset_remaining
				&& 0 === (int) $local_asset_counts['failed'];
			$storage_health = $this->get_media_optimized_storage_health($format, $attachment_completed, (bool) $force_storage_refresh);
			$background_state = $this->get_media_background_work_state();
			$manual_state = $this->get_manual_media_conversion_state();
			$recoverable_interrupted = method_exists($this, 'get_media_queue_recoverable_interrupted_count')
				? $this->get_media_queue_recoverable_interrupted_count($format)
				: 0;
			return array_merge(array(
				'enabled' => true,
				'format' => $format,
				'total' => (int) $total,
				'pending' => (int) $counts['pending'],
				'processing' => (int) $counts['processing'],
				'done' => (int) $counts['done'],
				'failed' => (int) $counts['failed'],
				'recoverableInterrupted' => (int) $recoverable_interrupted,
				'retryable' => (int) $counts['failed'] + (int) $recoverable_interrupted,
				'skipped' => (int) $counts['skipped'],
				'alreadyOptimized' => (int) $counts['skipped'],
				'completed' => $completed,
				'remaining' => $remaining,
				'isComplete' => $queue_is_complete,
				'attachmentQueueTotal' => (int) $attachment_total,
				'attachmentPending' => (int) $attachment_counts['pending'],
				'attachmentProcessing' => (int) $attachment_counts['processing'],
				'attachmentDone' => (int) $attachment_counts['done'],
				'attachmentFailed' => (int) $attachment_counts['failed'],
				'attachmentSkipped' => (int) $attachment_counts['skipped'],
				'attachmentCompleted' => $attachment_completed,
				'attachmentRemaining' => $attachment_remaining,
				'attachmentIsComplete' => $attachment_is_complete,
				'localAssetTotal' => (int) $local_asset_total,
				'localAssetPending' => (int) $local_asset_counts['pending'],
				'localAssetProcessing' => (int) $local_asset_counts['processing'],
				'localAssetDone' => (int) $local_asset_counts['done'],
				'localAssetFailed' => (int) $local_asset_counts['failed'],
				'localAssetSkipped' => (int) $local_asset_counts['skipped'],
				'localAssetCompleted' => $local_asset_completed,
				'localAssetRemaining' => $local_asset_remaining,
				'table' => $table,
				'buildOffset' => (int) ($build_state['offset'] ?? 0),
				'libraryTotal' => (int) ($build_state['total'] ?? 0),
				'buildComplete' => $build_complete,
				'buildMode' => (string) ($build_state['mode'] ?? 'unknown'),
				'rebuildGeneration' => $this->normalize_media_queue_rebuild_generation($build_state['generation'] ?? ''),
				'buildUpdatedAt' => (int) ($build_state['updatedAt'] ?? 0),
				'optimizedStorage' => $storage_health,
				'needsRepair' => !empty($storage_health['needsRepair']),
			), $unit_status, $background_state, $manual_state);
		}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
