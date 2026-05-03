<?php
/**
 * Ultra Cache Media Queue Trait for UltraCache media converter.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Queue_Trait
{

		private function get_media_queue_table_name() {
			global $wpdb;
			return $wpdb->prefix . 'ucwp_media_queue';
		}

		private function media_queue_table_exists() {
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
			return (string) $found === (string) $table;
		}

		public function ensure_media_queue_table() {
			global $wpdb;

			$table = $this->get_media_queue_table_name();
			$version = (string) get_option(self::MEDIA_QUEUE_DB_VERSION_OPTION, '');
			if (self::MEDIA_QUEUE_DB_VERSION === $version && $this->media_queue_table_exists()) {
				return true;
			}

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset_collate = $wpdb->get_charset_collate();
			$sql = "CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				attachment_id bigint(20) unsigned NOT NULL,
				format varchar(12) NOT NULL DEFAULT 'best',
				source_mtime bigint(20) unsigned NOT NULL DEFAULT 0,
				source_size bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				last_error text NULL,
				created_at datetime NULL DEFAULT NULL,
				updated_at datetime NULL DEFAULT NULL,
				started_at datetime NULL DEFAULT NULL,
				completed_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY attachment_format (attachment_id, format),
				KEY status_id (status, id),
				KEY updated_at (updated_at)
			) {$charset_collate};";

			dbDelta($sql);
			if ($this->media_queue_table_exists()) {
				update_option(self::MEDIA_QUEUE_DB_VERSION_OPTION, self::MEDIA_QUEUE_DB_VERSION, false);
				return true;
			}

			return false;
		}

		private function normalize_media_queue_format($format) {
			$format = strtolower(trim((string) $format));
			return in_array($format, array('best', 'avif', 'webp', 'both'), true) ? $format : 'best';
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

		private function optimized_output_dir_has_file($dir, $extension) {
			$dir = (string) $dir;
			$extension = strtolower(ltrim((string) $extension, '.'));
			if ('' === $dir || '' === $extension || !is_dir($dir) || !is_readable($dir)) {
				return false;
			}

			try {
				$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
				foreach ($iterator as $item) {
					if ($item->isFile() && strtolower($item->getExtension()) === $extension) {
						return true;
					}
				}
			} catch (Exception $e) {
				return false;
			}

			return false;
		}

		private function get_media_optimized_storage_health($format = 'best', $completed_rows = 0) {
			$format = $this->normalize_media_queue_format($format);
			$completed_rows = max(0, (int) $completed_rows);
			$avif_dir = defined('UCWP_AVIF_DIR') ? UCWP_AVIF_DIR : '';
			$webp_dir = defined('UCWP_WEBP_DIR') ? UCWP_WEBP_DIR : '';
			$avif_dir_exists = ('' !== (string) $avif_dir && is_dir($avif_dir));
			$webp_dir_exists = ('' !== (string) $webp_dir && is_dir($webp_dir));
			$avif_has_files = $this->optimized_output_dir_has_file($avif_dir, 'avif');
			$webp_has_files = $this->optimized_output_dir_has_file($webp_dir, 'webp');

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

			return array(
				'storageRoot' => 'uploads/uc-images',
				'persistentStorage' => true,
				'avifDir' => (string) $avif_dir,
				'webpDir' => (string) $webp_dir,
				'avifDirExists' => $avif_dir_exists,
				'webpDirExists' => $webp_dir_exists,
				'avifHasFiles' => $avif_has_files,
				'webpHasFiles' => $webp_has_files,
				'targetHasFiles' => $target_has_files,
				'targetMissing' => $target_missing,
				'needsRepair' => $needs_repair,
				'message' => $needs_repair ? 'Optimized image files appear to be missing from persistent uploads/uc-images storage. Start/Resume or warm-up regeneration can repair missing variants without relying on the old cache directory.' : '',
			);
		}

		private function repair_media_queue_if_optimized_storage_missing($format = 'best') {
			if (!$this->media_queue_table_exists()) {
				return array('repaired' => false, 'requeued' => 0, 'reason' => 'table_unavailable');
			}

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			$completed_rows = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE format = %s AND status IN ('done','skipped')", $format));
			$health = $this->get_media_optimized_storage_health($format, $completed_rows);
			if (empty($health['needsRepair'])) {
				return array_merge(array('repaired' => false, 'requeued' => 0, 'reason' => 'not_needed'), $health);
			}

			if (defined('UCWP_AVIF_DIR') && UCWP_AVIF_DIR) {
				$this->optimized_storage_ensure_directory(UCWP_AVIF_DIR);
			}
			if (defined('UCWP_WEBP_DIR') && UCWP_WEBP_DIR) {
				$this->optimized_storage_ensure_directory(UCWP_WEBP_DIR);
			}

			$count = $wpdb->query($wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', last_error = %s, updated_at = %s, started_at = NULL, completed_at = NULL WHERE format = %s AND status IN ('done','skipped')",
				'Optimized image files were missing; queued for repair.',
				current_time('mysql'),
				$format
			));

			return array_merge(array('repaired' => true, 'requeued' => is_numeric($count) ? (int) $count : 0, 'reason' => 'optimized_storage_missing'), $health);
		}

		public function upsert_media_queue_item($attachment_id, $format = 'best', $status = 'pending', $last_error = '', $attempts = 0) {
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

			$existing = $wpdb->get_row($wpdb->prepare("SELECT id, status, source_mtime, source_size, attempts FROM {$table} WHERE attachment_id = %d AND format = %s", $attachment_id, $format), ARRAY_A);
			if (is_array($existing) && !empty($existing['id'])) {
				$next_status = $status;
				if ('pending' === $status && in_array((string) $existing['status'], array('done', 'skipped'), true)) {
					$same_source = ((int) $existing['source_mtime'] === (int) $signature['mtime'] && (int) $existing['source_size'] === (int) $signature['size']);
					$next_status = $same_source ? (string) $existing['status'] : 'pending';
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

		private function get_media_queue_pending_count() {
			if (!$this->media_queue_table_exists()) {
				return 0;
			}
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
		}

		public function reset_stale_media_queue_items() {
			if (!$this->media_queue_table_exists()) {
				return 0;
			}
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$cutoff = gmdate('Y-m-d H:i:s', time() - self::MEDIA_QUEUE_PROCESSING_TTL);
			$result = $wpdb->query($wpdb->prepare("UPDATE {$table} SET status = 'pending', updated_at = %s WHERE status = 'processing' AND updated_at < %s", current_time('mysql'), get_date_from_gmt($cutoff)));
			return is_numeric($result) ? (int) $result : 0;
		}

		private function start_media_queue_rebuild($format = 'best') {
			if (!$this->ensure_media_queue_table()) {
				return false;
			}
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			$wpdb->delete($table, array('format' => $format), array('%s'));
			update_option(self::MEDIA_QUEUE_BUILD_STATE_OPTION, array(
				'format' => $format,
				'offset' => 0,
				'complete' => false,
				'mode' => 'chunked',
				'updatedAt' => time(),
			), false);
			return true;
		}

		private function get_media_queue_build_state($format = 'best') {
			$format = $this->normalize_media_queue_format($format);
			$state = get_option(self::MEDIA_QUEUE_BUILD_STATE_OPTION, array());
			if (!is_array($state) || (string) ($state['format'] ?? '') !== $format) {
				return array('format' => $format, 'offset' => 0, 'complete' => false, 'mode' => 'none', 'updatedAt' => 0);
			}
			return array(
				'format' => $format,
				'offset' => max(0, (int) ($state['offset'] ?? 0)),
				'complete' => !empty($state['complete']),
				'mode' => isset($state['mode']) ? (string) $state['mode'] : 'legacy',
				'updatedAt' => (int) ($state['updatedAt'] ?? 0),
			);
		}

		private function append_media_queue_build_batch($format = 'best', $limit = 500) {
			$format = $this->normalize_media_queue_format($format);
			$limit = max(25, min(500, (int) $limit));
			$state = $this->get_media_queue_build_state($format);
			if (!empty($state['complete'])) {
				return array('scanned' => 0, 'queued' => 0, 'complete' => true, 'offset' => (int) $state['offset']);
			}

			$batch = $this->get_media_ids_batch((int) $state['offset'], $limit, false);
			$items = array_map('intval', (array) ($batch['items'] ?? array()));
			$queued = 0;
			foreach ($items as $attachment_id) {
				if ($this->upsert_media_queue_item($attachment_id, $format, 'pending', '', 0)) {
					$queued++;
				}
			}
			$next_offset = (int) ($batch['nextOffset'] ?? ((int) $state['offset'] + count($items)));
			$complete = empty($batch['hasMore']) || empty($items);
			update_option(self::MEDIA_QUEUE_BUILD_STATE_OPTION, array(
				'format' => $format,
				'offset' => $next_offset,
				'complete' => $complete,
				'mode' => 'chunked',
				'updatedAt' => time(),
			), false);

			return array('scanned' => count($items), 'queued' => $queued, 'complete' => $complete, 'offset' => $next_offset);
		}

		public function rebuild_media_conversion_queue($format = 'best', $only_missing = true, $limit = 0) {
			if (!$this->ensure_media_queue_table()) {
				return array('success' => false, 'message' => 'Media queue table could not be created.');
			}

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			$limit = max(0, (int) $limit);
			$is_limited_sample = ($limit > 0);

			if (!$is_limited_sample) {
				$wpdb->delete($table, array('format' => $format), array('%s'));
				update_option(self::MEDIA_QUEUE_BUILD_STATE_OPTION, array(
					'format' => $format,
					'offset' => 0,
					'complete' => false,
					'mode' => 'full',
					'updatedAt' => time(),
				), false);
			}

			$offset = 0;
			$batch_size = 250;
			$queued = 0;
			$scanned = 0;
			$batch = array('hasMore' => false);
			do {
				$batch = $this->get_media_ids_batch($offset, $batch_size, false);
				$items = array_map('intval', (array) ($batch['items'] ?? array()));
				foreach ($items as $attachment_id) {
					if ($limit > 0 && $scanned >= $limit) {
						break 2;
					}
					$scanned++;
					if ($this->upsert_media_queue_item($attachment_id, $format, 'pending', '', 0)) {
						$queued++;
					}
				}
				$offset = (int) ($batch['nextOffset'] ?? ($offset + count($items)));
			} while (!empty($batch['hasMore']) && !empty($items));

			if (!$is_limited_sample) {
				update_option(self::MEDIA_QUEUE_BUILD_STATE_OPTION, array(
					'format' => $format,
					'offset' => $offset,
					'complete' => empty($batch['hasMore']),
					'mode' => 'full',
					'updatedAt' => time(),
				), false);
			}

			$this->invalidate_media_work_summary_cache();
			$message = $is_limited_sample
				? 'Limited media queue sample scanned. Existing completed queue state was preserved.'
				: 'Media conversion queue rebuilt.';

			return array_merge(
				array(
					'success' => true,
					'message' => $message,
					'buildMode' => $is_limited_sample ? 'limited_sample' : 'full',
					'buildLimit' => $limit,
				),
				$this->get_media_queue_status($format),
				array('queued' => $queued, 'scanned' => $scanned, 'onlyMissing' => (bool) $only_missing)
			);
		}

		public function get_media_queue_status($format = 'best') {
			if (!$this->ensure_media_queue_table()) {
				return array('enabled' => false, 'message' => 'Media queue table unavailable.');
			}

			$this->reset_stale_media_queue_items();
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			$rows = $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) AS count FROM {$table} WHERE format = %s GROUP BY status", $format), ARRAY_A);
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
			$storage_health = $this->get_media_optimized_storage_health($format, $completed);
			return array(
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
				'buildComplete' => !empty($build_state['complete']),
				'buildMode' => (string) ($build_state['mode'] ?? 'unknown'),
				'buildUpdatedAt' => (int) ($build_state['updatedAt'] ?? 0),
				'optimizedStorage' => $storage_health,
				'needsRepair' => !empty($storage_health['needsRepair']),
			);
		}

		public function get_media_queue_batch($cursor = 0, $limit = 100, $format = 'best', $auto_rebuild = true) {
			$cursor = max(0, (int) $cursor);
			$limit = max(1, min(500, (int) $limit));
			$format = $this->normalize_media_queue_format($format);
			if (!$this->ensure_media_queue_table()) {
				return array('items' => array(), 'total' => 0, 'cursor' => (string) $cursor, 'nextCursor' => '', 'hasMore' => false, 'message' => 'Media queue table unavailable.');
			}

			$status = $this->get_media_queue_status($format);
			$repair = array('repaired' => false, 'requeued' => 0);
			if ($auto_rebuild && !empty($status['needsRepair'])) {
				$repair = $this->repair_media_queue_if_optimized_storage_missing($format);
				$status = $this->get_media_queue_status($format);
			}
			$build_chunk = array('scanned' => 0, 'queued' => 0, 'complete' => !empty($status['buildComplete']));
			if ($auto_rebuild && empty($status['total'])) {
				$this->start_media_queue_rebuild($format);
				$build_chunk = $this->append_media_queue_build_batch($format, max(100, min(500, $limit * 5)));
				$status = $this->get_media_queue_status($format);
			} elseif ($auto_rebuild && empty($status['pending']) && empty($status['buildComplete'])) {
				$build_chunk = $this->append_media_queue_build_batch($format, max(100, min(500, $limit * 5)));
				$status = $this->get_media_queue_status($format);
			}

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$rows = $wpdb->get_results($wpdb->prepare("SELECT id, attachment_id FROM {$table} WHERE format = %s AND status = 'pending' AND id > %d ORDER BY id ASC LIMIT %d", $format, $cursor, $limit), ARRAY_A);
			if (empty($rows) && $cursor > 0 && !empty($status['pending'])) {
				$rows = $wpdb->get_results($wpdb->prepare("SELECT id, attachment_id FROM {$table} WHERE format = %s AND status = 'pending' ORDER BY id ASC LIMIT %d", $format, $limit), ARRAY_A);
			}

			$items = array();
			$next_cursor = '';
			foreach ((array) $rows as $row) {
				$items[] = (int) $row['attachment_id'];
				$next_cursor = (string) (int) $row['id'];
			}

			$has_more = false;
			if ('' !== $next_cursor) {
				$has_more = (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table} WHERE format = %s AND status = 'pending' AND id > %d LIMIT 1", $format, (int) $next_cursor));
			} elseif (!empty($status['pending'])) {
				$has_more = true;
			}
			if (!$has_more && empty($status['buildComplete'])) {
				$has_more = true;
			}

			return array(
				'items' => $items,
				'total' => (int) ($status['total'] ?? count($items)),
				'attachmentTotal' => (int) ($status['total'] ?? count($items)),
				'workTotal' => (int) max(1, ($status['total'] ?? count($items))),
				'cursor' => (string) $cursor,
				'nextCursor' => $next_cursor,
				'nextOffset' => (int) $next_cursor,
				'limit' => $limit,
				'hasMore' => $has_more,
				'complete' => empty($items) && !$has_more && !empty($status['isComplete']),
				'message' => (empty($items) && !$has_more && !empty($status['isComplete'])) ? 'Media conversion complete. All queued media items are already optimized or processed.' : '',
				'queue' => $status,
				'repair' => $repair,
				'buildChunk' => $build_chunk,
			);
		}

		public function process_queued_attachment($attachment_id, $format = 'best', $only_missing = true) {
			$attachment_id = absint($attachment_id);
			$format = $this->normalize_media_queue_format($format);
			if ($attachment_id <= 0) {
				return array('success' => false, 'attachment_id' => 0, 'message' => 'Invalid attachment ID.');
			}
			$this->upsert_media_queue_item($attachment_id, $format, 'pending', '', 0);

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$now = current_time('mysql');
			$row = $wpdb->get_row($wpdb->prepare("SELECT id, attempts, status FROM {$table} WHERE attachment_id = %d AND format = %s", $attachment_id, $format), ARRAY_A);
			if (!is_array($row) || empty($row['id'])) {
				return array('success' => false, 'attachment_id' => $attachment_id, 'message' => 'Queue row unavailable.');
			}
			if (in_array((string) $row['status'], array('done', 'skipped'), true)) {
				$result = $this->generate_attachment_formats($attachment_id, $format, true);
				$result['success'] = true;
				$result['converted'] = false;
				$result['queueStatus'] = (string) $row['status'];
				if ('skipped' === (string) $row['status']) {
					$result['skippedReason'] = 'already_optimized';
					$result['alreadyOptimized'] = true;
				}
				return $result;
			}

			$wpdb->update($table, array('status' => 'processing', 'attempts' => ((int) $row['attempts']) + 1, 'started_at' => $now, 'updated_at' => $now, 'last_error' => ''), array('id' => (int) $row['id']), array('%s', '%d', '%s', '%s', '%s'), array('%d'));

			try {
				$result = $this->generate_attachment_formats($attachment_id, $format, (bool) $only_missing);
				$generated = ((int) ($result['avif'] ?? 0)) + ((int) ($result['webp'] ?? 0));
				$skipped_existing = (int) ($result['skippedExisting'] ?? 0);
				if (!empty($result['success'])) {
					$status = $generated > 0 ? 'done' : 'skipped';
					$error = '';
					if ('skipped' === $status) {
						$result['skippedReason'] = !empty($result['skippedExisting']) ? 'already_optimized' : 'no_supported_work';
						$result['alreadyOptimized'] = !empty($result['skippedExisting']);
					}
				} else {
					$status = 'failed';
					$error = 'No supported source files were converted.';
				}
				$wpdb->update($table, array('status' => $status, 'last_error' => $error, 'updated_at' => current_time('mysql'), 'completed_at' => current_time('mysql')), array('id' => (int) $row['id']), array('%s', '%s', '%s', '%s'), array('%d'));
				$result['converted'] = $generated > 0;
				$result['queueStatus'] = $status;
				$result['skippedExisting'] = $skipped_existing;
				return $result;
			} catch (Throwable $e) {
				$message = $e->getMessage();
				$wpdb->update($table, array('status' => 'failed', 'last_error' => $message, 'updated_at' => current_time('mysql'), 'completed_at' => current_time('mysql')), array('id' => (int) $row['id']), array('%s', '%s', '%s', '%s'), array('%d'));
				return array('success' => false, 'attachment_id' => $attachment_id, 'message' => $message, 'queueStatus' => 'failed', 'converted' => false);
			}
		}

		public function process_media_queue_batch(array $args = array()) {
			$limit = isset($args['limit']) ? max(1, min(100, (int) $args['limit'])) : 10;
			$format = $this->normalize_media_queue_format($args['format'] ?? 'best');
			$only_missing = array_key_exists('only_missing', $args) ? (bool) $args['only_missing'] : true;
			$time_budget = isset($args['time_budget']) ? max(0, (int) $args['time_budget']) : 20;
			$started = microtime(true);

			if (get_transient(self::MEDIA_QUEUE_PROCESS_LOCK)) {
				$status = $this->get_media_queue_status($format);
				return array_merge(array('success' => false, 'paused' => true, 'reason' => 'locked'), $status);
			}

			set_transient(self::MEDIA_QUEUE_PROCESS_LOCK, 1, 5 * MINUTE_IN_SECONDS);
			$processed = 0;
			$generated_avif = 0;
			$generated_webp = 0;
			$failed = 0;
			$skipped = 0;
			try {
				$batch = $this->get_media_queue_batch(0, $limit, $format, true);
				foreach ((array) ($batch['items'] ?? array()) as $attachment_id) {
					if ($time_budget > 0 && (microtime(true) - $started) >= $time_budget) {
						break;
					}
					$result = $this->process_queued_attachment((int) $attachment_id, $format, $only_missing);
					$processed++;
					$generated_avif += (int) ($result['avif'] ?? 0);
					$generated_webp += (int) ($result['webp'] ?? 0);
					if (empty($result['success']) || 'failed' === (string) ($result['queueStatus'] ?? '')) {
						$failed++;
					} elseif ('skipped' === (string) ($result['queueStatus'] ?? '')) {
						$skipped++;
					}
				}
			} finally {
				delete_transient(self::MEDIA_QUEUE_PROCESS_LOCK);
			}

			$status = $this->get_media_queue_status($format);
			$time_budget_reached = ($time_budget > 0 && (microtime(true) - $started) >= $time_budget && !empty($status['pending']));
			$batch_limit_reached = (!empty($status['pending']) && $processed >= $limit);
			$pause_reason = '';
			if ($time_budget_reached) {
				$pause_reason = 'time_budget';
			} elseif ($batch_limit_reached) {
				$pause_reason = 'batch_limit';
			}
			return array_merge(array(
				'success' => true,
				'processed' => $processed,
				'avif' => $generated_avif,
				'webp' => $generated_webp,
				'failedThisRun' => $failed,
				'skippedThisRun' => $skipped,
				'alreadyOptimizedThisRun' => $skipped,
				'batchLimitReached' => $batch_limit_reached,
				'timeBudgetReached' => $time_budget_reached,
				'complete' => empty($status['remaining']) && empty($status['failed']),
				'pauseReason' => $pause_reason,
			), $status);
		}

		public function repair_media_conversion_queue($format = 'best') {
			if (!$this->ensure_media_queue_table()) {
				return array('success' => false, 'message' => 'Media queue table unavailable.');
			}

			$repair = $this->repair_media_queue_if_optimized_storage_missing($format);
			return array_merge(array('success' => true, 'repair' => $repair), $this->get_media_queue_status($format));
		}

		public function retry_failed_media_queue_items($format = 'best') {
			if (!$this->ensure_media_queue_table()) {
				return array('success' => false, 'message' => 'Media queue table unavailable.');
			}
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			$count = $wpdb->query($wpdb->prepare("UPDATE {$table} SET status = 'pending', last_error = '', updated_at = %s WHERE format = %s AND status = 'failed'", current_time('mysql'), $format));
			return array_merge(array('success' => true, 'retried' => is_numeric($count) ? (int) $count : 0), $this->get_media_queue_status($format));
		}

		public function clear_completed_media_queue_items($format = 'best') {
			if (!$this->ensure_media_queue_table()) {
				return array('success' => false, 'message' => 'Media queue table unavailable.');
			}
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			$count = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE format = %s AND status IN ('done','skipped')", $format));
			return array_merge(array('success' => true, 'cleared' => is_numeric($count) ? (int) $count : 0), $this->get_media_queue_status($format));
		}
}
