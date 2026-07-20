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


		public function ensure_media_queue_table() {
			global $wpdb;

			$table = $this->get_media_queue_table_name();
			$version = (string) get_option(self::MEDIA_QUEUE_DB_VERSION_OPTION, '');
			if (self::MEDIA_QUEUE_DB_VERSION === $version && $this->media_queue_table_exists() && $this->media_queue_failure_column_exists()) {
				return true;
			}

			if (!ultracache_require_wordpress_admin_include('upgrade.php', 'dbDelta')) {
				return false;
			}
			$charset_collate = $wpdb->get_charset_collate();
			$sql = "CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				attachment_id bigint(20) unsigned NOT NULL,
				format varchar(12) NOT NULL DEFAULT 'best',
				source_mtime bigint(20) unsigned NOT NULL DEFAULT 0,
				source_size bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				consecutive_failures smallint(5) unsigned NOT NULL DEFAULT 0,
				last_error text NULL,
				created_at datetime NULL DEFAULT NULL,
				updated_at datetime NULL DEFAULT NULL,
				started_at datetime NULL DEFAULT NULL,
				completed_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY attachment_format (attachment_id, format),
				KEY status_id (status, id),
				KEY format_status_id (format, status, id),
				KEY updated_at (updated_at)
			) {$charset_collate};";

			dbDelta($sql);
			if ($this->media_queue_table_exists() && $this->media_queue_failure_column_exists()) {
				update_option(self::MEDIA_QUEUE_DB_VERSION_OPTION, self::MEDIA_QUEUE_DB_VERSION, false);
				return true;
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


		private function get_media_optimized_storage_health_transient_key($format = 'best') {
			return self::MEDIA_STORAGE_HEALTH_TRANSIENT . '_' . $this->normalize_media_queue_format($format);
		}


		private function get_media_optimized_storage_health($format = 'best', $completed_rows = 0, $force_refresh = false) {
			$format = $this->normalize_media_queue_format($format);
			$completed_rows = max(0, (int) $completed_rows);
			$avif_dir = defined('ULTRACACHE_AVIF_DIR') ? ULTRACACHE_AVIF_DIR : '';
			$webp_dir = defined('ULTRACACHE_WEBP_DIR') ? ULTRACACHE_WEBP_DIR : '';
			$avif_dir_exists = ('' !== (string) $avif_dir && is_dir($avif_dir));
			$webp_dir_exists = ('' !== (string) $webp_dir && is_dir($webp_dir));
			$cache_key = $this->get_media_optimized_storage_health_transient_key($format);
			$cached = $force_refresh ? false : get_transient($cache_key);

			if (is_array($cached) && isset($cached['avifHasFiles'], $cached['webpHasFiles'])) {
				$health = $cached;
				$health['cached'] = true;
				$health['scanSkipped'] = false;
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
					'cached' => false,
					'scanSkipped' => false,
				);
				set_transient($cache_key, $health, 10 * MINUTE_IN_SECONDS);
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

			$needs_repair = ($completed_rows > 0 && $target_missing);
			$health['targetHasFiles'] = $target_has_files;
			$health['targetMissing'] = $target_missing;
			$health['needsRepair'] = $needs_repair;
			$health['message'] = $needs_repair ? 'Optimized image files appear to be missing from persistent uploads/ultracache/images storage. Start/Resume or warm-up regeneration can repair missing variants without relying on the old cache directory.' : '';
			return $health;
		}


		private function repair_media_queue_if_optimized_storage_missing($format = 'best') {
			if (!$this->media_queue_table_exists()) {
				return array('repaired' => false, 'requeued' => 0, 'reason' => 'table_unavailable');
			}

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			$completed_rows = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE format = %s AND status IN ('done','skipped')", $table, $format));
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
				"UPDATE %i SET status = 'pending', last_error = %s, updated_at = %s, started_at = NULL, completed_at = NULL WHERE format = %s AND status IN ('done','skipped') LIMIT %d",
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
			$now = current_time('mysql');

			$existing = $wpdb->get_row($wpdb->prepare("SELECT id, status, source_mtime, source_size, attempts FROM %i WHERE attachment_id = %d AND format = %s", $table, $attachment_id, $format), ARRAY_A);
			if (is_array($existing) && !empty($existing['id'])) {
				$next_status = $status;
				if ('pending' === $status && in_array((string) $existing['status'], array('done', 'skipped'), true)) {
					$same_source = ((int) $existing['source_mtime'] === (int) $signature['mtime'] && (int) $existing['source_size'] === (int) $signature['size']);
					$next_status = (!$force_pending && $same_source) ? (string) $existing['status'] : 'pending';
				}

				$wpdb->update(
					$table,
					array(
						'source_mtime' => (int) $signature['mtime'],
						'source_size'  => (int) $signature['size'],
						'status'       => $next_status,
						'attempts'     => max((int) $attempts, (int) $existing['attempts']),
						'last_error'   => (string) $last_error,
						'updated_at'   => $now,
					),
					array('id' => (int) $existing['id']),
					array('%d', '%d', '%s', '%d', '%s', '%s'),
					array('%d')
				);
				return true;
			}

			$created = $wpdb->insert(
				$table,
				array(
					'attachment_id' => $attachment_id,
					'format'        => $format,
					'source_mtime'  => (int) $signature['mtime'],
					'source_size'   => (int) $signature['size'],
					'status'        => $status,
					'attempts'      => max(0, (int) $attempts),
					'last_error'    => (string) $last_error,
					'created_at'    => $now,
					'updated_at'    => $now,
				),
				array('%d', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s')
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


		public function reset_stale_media_queue_items($lock_token = '') {
			if (!$this->media_queue_table_exists()) {
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only the current global lease owner may recycle claims created before its own acquisition time, preventing dashboard reads or delayed workers from reclaiming active rows.
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = 'pending', consecutive_failures = 0, last_error = CASE WHEN last_error LIKE '__ultracache_force_regenerate__:%' THEN last_error ELSE '' END, updated_at = %s, started_at = NULL, completed_at = NULL WHERE status = 'processing' AND updated_at < %s AND updated_at < %s",
					$table,
					$now,
					$cutoff,
					$lock_acquired
				)
			);
			$stale_count = is_numeric($result) ? max(0, (int) $result) : 0;
			if ($stale_count <= 0) {
				return 0;
			}

			$this->invalidate_media_work_summary_cache();

			return $stale_count;
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
			return is_numeric($result) ? (int) $result : 0;
		}


		public function get_media_queue_status($format = 'best', $force_storage_refresh = false) {
			if (!$this->ensure_media_queue_table()) {
				return array('enabled' => false, 'message' => __('Media queue table unavailable.', 'ultracache'));
			}

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			$rows = $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) AS count FROM %i WHERE format = %s GROUP BY status", $table, $format), ARRAY_A);
			$counts = array('pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0);
			foreach ((array) $rows as $row) {
				$status = isset($row['status']) ? (string) $row['status'] : '';
				if (array_key_exists($status, $counts)) {
					$counts[$status] = (int) $row['count'];
				}
			}
			$total = array_sum($counts);
			$completed = (int) ($counts['done'] + $counts['skipped']);
			$remaining = (int) ($counts['pending'] + $counts['processing']);
			$build_state = $this->get_media_queue_build_state($format);
			$storage_health = $this->get_media_optimized_storage_health($format, $completed, (bool) $force_storage_refresh);
			$background_state = $this->get_media_background_work_state();
			$manual_state = $this->get_manual_media_conversion_state();
			return array_merge(array(
				'enabled' => true,
				'format' => $format,
				'total' => (int) $total,
				'pending' => (int) $counts['pending'],
				'processing' => (int) $counts['processing'],
				'done' => (int) $counts['done'],
				'failed' => (int) $counts['failed'],
				'skipped' => (int) $counts['skipped'],
				'alreadyOptimized' => (int) $counts['skipped'],
				'completed' => $completed,
				'remaining' => $remaining,
				'isComplete' => ($total > 0 && 0 === $remaining && 0 === (int) $counts['failed']),
				'table' => $table,
				'buildOffset' => (int) ($build_state['offset'] ?? 0),
				'libraryTotal' => (int) ($build_state['total'] ?? 0),
				'buildComplete' => !empty($build_state['complete']),
				'buildMode' => (string) ($build_state['mode'] ?? 'unknown'),
				'rebuildGeneration' => $this->normalize_media_queue_rebuild_generation($build_state['generation'] ?? ''),
				'buildUpdatedAt' => (int) ($build_state['updatedAt'] ?? 0),
				'optimizedStorage' => $storage_health,
				'needsRepair' => !empty($storage_health['needsRepair']),
			), $background_state, $manual_state);
		}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
