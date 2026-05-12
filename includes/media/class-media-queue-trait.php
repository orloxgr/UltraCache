<?php
/**
 * Ultra Cache Media Queue Trait for UltraCache media converter.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Queue_Trait
{
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- UltraCache uses a private custom media conversion queue table with validated table identifiers.

		private function get_media_queue_table_name() {
			global $wpdb;
			return $wpdb->prefix . 'ultracache_media_queue';
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
			$avif_dir = defined('UCWP_AVIF_DIR') ? UCWP_AVIF_DIR : '';
			$webp_dir = defined('UCWP_WEBP_DIR') ? UCWP_WEBP_DIR : '';
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
					'storageRoot' => 'uploads/uc-images',
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
					'storageRoot' => 'uploads/uc-images',
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
					'message' => 'Optimized storage health is passive. Use Refresh Storage Stats or Verify / Repair Queue to run the capped filesystem scan.',
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
			$health['message'] = $needs_repair ? 'Optimized image files appear to be missing from persistent uploads/uc-images storage. Start/Resume or warm-up regeneration can repair missing variants without relying on the old cache directory.' : '';
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

			if (defined('UCWP_AVIF_DIR') && UCWP_AVIF_DIR) {
				$this->optimized_storage_ensure_directory(UCWP_AVIF_DIR);
			}
			if (defined('UCWP_WEBP_DIR') && UCWP_WEBP_DIR) {
				$this->optimized_storage_ensure_directory(UCWP_WEBP_DIR);
			}

			$count = $wpdb->query($wpdb->prepare(
				"UPDATE %i SET status = 'pending', last_error = %s, updated_at = %s, started_at = NULL, completed_at = NULL WHERE format = %s AND status IN ('done','skipped')",
				$table,
				'Optimized image files were missing; queued for repair.',
				current_time('mysql'),
				$format
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

			$existing = $wpdb->get_row($wpdb->prepare("SELECT id, status, source_mtime, source_size, attempts FROM {$table} WHERE attachment_id = %d AND format = %s", $attachment_id, $format), ARRAY_A);
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
			return $this->is_media_optimization_enabled() && ($this->is_generate_on_upload_enabled() || $this->is_generate_on_demand_enabled());
		}

		private function get_on_demand_queue_max_per_request() {
			$default = defined('UCWP_MEDIA_ON_DEMAND_QUEUE_MAX_PER_REQUEST') ? (int) UCWP_MEDIA_ON_DEMAND_QUEUE_MAX_PER_REQUEST : 20;
			$context = method_exists($this, 'get_media_generation_context') ? $this->get_media_generation_context() : 'frontend';
			$limit = (int) apply_filters('ucwp_media_on_demand_queue_max_per_request', $default, $context);
			return max(0, min(100, $limit));
		}

		private function can_queue_missing_media_from_lookup() {
			if (!$this->is_background_media_queue_enabled() || !$this->is_generate_on_demand_enabled()) {
				return false;
			}

			if (method_exists($this, 'is_supported') && !$this->is_supported()) {
				return false;
			}

			$context = method_exists($this, 'get_media_generation_context') ? $this->get_media_generation_context() : 'frontend';
			if ('manual' === $context) {
				return false;
			}

			if (!in_array($context, array('frontend', 'warm', 'cron', 'stale'), true)) {
				return false;
			}

			if ('frontend' === $context && method_exists($this, 'is_frontend_on_demand_request') && !$this->is_frontend_on_demand_request()) {
				return false;
			}

			$max = $this->get_on_demand_queue_max_per_request();
			return $max > 0 && $this->on_demand_queue_discovery_count < $max;
		}

		private function get_on_demand_queue_dedupe_transient_key($attachment_id, $queue_format, $source_file, $missing_format) {
			$attachment_id = absint($attachment_id);
			$queue_format = $this->normalize_media_queue_format($queue_format);
			$missing_format = strtolower((string) $missing_format);
			$source_file = is_string($source_file) ? wp_normalize_path($source_file) : '';
			$signature = $this->get_attachment_source_signature($attachment_id);
			$hash = md5($attachment_id . '|' . $queue_format . '|' . $missing_format . '|' . $source_file . '|' . (int) $signature['mtime'] . '|' . (int) $signature['size']);
			return self::MEDIA_ON_DEMAND_QUEUE_TRANSIENT_PREFIX . $hash;
		}


		private function get_media_page_refs_table_name() {
			global $wpdb;
			return $wpdb->prefix . 'ultracache_media_page_refs';
		}

		private function media_page_refs_table_exists() {
			global $wpdb;
			$table = $this->get_media_page_refs_table_name();
			$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
			return (string) $found === (string) $table;
		}

		public function ensure_media_page_refs_table() {
			global $wpdb;

			$table = $this->get_media_page_refs_table_name();
			$version = (string) get_option(self::MEDIA_PAGE_REFS_DB_VERSION_OPTION, '');
			if (self::MEDIA_PAGE_REFS_DB_VERSION === $version && $this->media_page_refs_table_exists()) {
				return true;
			}

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset_collate = $wpdb->get_charset_collate();
			$sql = "CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				page_url_hash char(32) NOT NULL,
				page_url text NOT NULL,
				attachment_id bigint(20) unsigned NOT NULL,
				format varchar(12) NOT NULL DEFAULT 'best',
				missing_formats varchar(40) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				converted tinyint(1) unsigned NOT NULL DEFAULT 0,
				discovered_at datetime NULL DEFAULT NULL,
				updated_at datetime NULL DEFAULT NULL,
				completed_at datetime NULL DEFAULT NULL,
				purge_ready_at datetime NULL DEFAULT NULL,
				purged_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY page_attachment_format (page_url_hash, attachment_id, format),
				KEY attachment_format_status (attachment_id, format, status),
				KEY page_status (page_url_hash, status),
				KEY purge_ready (purge_ready_at, purged_at),
				KEY purged_at (purged_at),
				KEY updated_at (updated_at)
			) {$charset_collate};";

			dbDelta($sql);
			if ($this->media_page_refs_table_exists()) {
				update_option(self::MEDIA_PAGE_REFS_DB_VERSION_OPTION, self::MEDIA_PAGE_REFS_DB_VERSION, false);
				return true;
			}

			return false;
		}

		private function maybe_cleanup_on_demand_affected_page_refs() {
			if (get_transient('ultracache_media_page_refs_cleanup_lock')) {
				return;
			}
			set_transient('ultracache_media_page_refs_cleanup_lock', 1, HOUR_IN_SECONDS);

			if (!$this->ensure_media_page_refs_table()) {
				return;
			}

			global $wpdb;
			$table = $this->get_media_page_refs_table_name();
			$limit = (int) apply_filters('ucwp_media_page_refs_cleanup_max_deletes_per_run', 250);
			$limit = max(25, min(1000, $limit));
			$purged_cutoff = get_date_from_gmt(gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS));
			$complete_cutoff = get_date_from_gmt(gmdate('Y-m-d H:i:s', time() - (2 * DAY_IN_SECONDS)));

			// Delete only rows that already triggered a page purge. Never delete pending refs here:
			// on very large stores the media queue may legitimately take days to drain.
			$wpdb->query($wpdb->prepare(
				'DELETE FROM %i WHERE purged_at IS NOT NULL AND purged_at <> %s AND purged_at < %s LIMIT %d',
				$table,
				'0000-00-00 00:00:00',
				$purged_cutoff,
				$limit
			));

			// If a page became purge-ready but the purge could not be recorded, keep it for
			// a grace window and then remove only the completed refs, not pending work.
			$wpdb->query($wpdb->prepare(
				'DELETE FROM %i WHERE status = %s AND (purged_at IS NULL OR purged_at = %s) AND purge_ready_at IS NOT NULL AND purge_ready_at <> %s AND purge_ready_at < %s LIMIT %d',
				$table,
				'complete',
				'0000-00-00 00:00:00',
				'0000-00-00 00:00:00',
				$complete_cutoff,
				$limit
			));
		}

		private function get_on_demand_affected_media_key($attachment_id, $format = 'best') {
			return absint($attachment_id) . '|' . $this->normalize_media_queue_format($format);
		}

		private function get_current_on_demand_affected_page_url() {
			if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
				return '';
			}

			$request_uri = ucwp_server_value('REQUEST_URI');
			if ('' === trim((string) $request_uri)) {
				return '';
			}

			$path = wp_unslash((string) $request_uri);
			$parts = wp_parse_url($path);
			if (is_array($parts) && isset($parts['path'])) {
				$path = (string) $parts['path'] . (isset($parts['query']) && '' !== (string) $parts['query'] ? '?' . (string) $parts['query'] : '');
			}

			$url = home_url($path);
			$url = remove_query_arg(array('ucwp_action', '_wpnonce', 'ucwp_odq_test', 'ucwp_cache_bust'), $url);
			$url = esc_url_raw($url);
			if ('' === $url) {
				return '';
			}

			return $url;
		}

		private function normalize_on_demand_missing_formats($formats) {
			$items = array();
			foreach (preg_split('/[,\s]+/', (string) $formats) as $format) {
				$format = strtolower(trim((string) $format));
				if (in_array($format, array('avif', 'webp'), true)) {
					$items[$format] = $format;
				}
			}
			ksort($items);
			return implode(',', array_values($items));
		}

		private function merge_on_demand_missing_format($existing_formats, $missing_format) {
			$items = array();
			foreach (preg_split('/[,\s]+/', (string) $existing_formats) as $format) {
				$format = strtolower(trim((string) $format));
				if (in_array($format, array('avif', 'webp'), true)) {
					$items[$format] = $format;
				}
			}
			$missing_format = strtolower((string) $missing_format);
			if (in_array($missing_format, array('avif', 'webp'), true)) {
				$items[$missing_format] = $missing_format;
			}
			ksort($items);
			return implode(',', array_values($items));
		}

		private function record_on_demand_affected_page($attachment_id, $format = 'best', $missing_format = '') {
			$attachment_id = absint($attachment_id);
			$format = $this->normalize_media_queue_format($format);
			if ($attachment_id <= 0) {
				return false;
			}

			$page_url = $this->get_current_on_demand_affected_page_url();
			if ('' === $page_url) {
				return false;
			}

			$page_key = md5($page_url);
			$seen_key = $page_key . '|' . $this->get_on_demand_affected_media_key($attachment_id, $format);
			if (isset($this->on_demand_affected_page_seen[$seen_key])) {
				return true;
			}
			$this->on_demand_affected_page_seen[$seen_key] = true;

			if (!$this->ensure_media_page_refs_table()) {
				return false;
			}

			$this->maybe_cleanup_on_demand_affected_page_refs();

			global $wpdb;
			$table = $this->get_media_page_refs_table_name();
			$now = current_time('mysql');
			$row = $wpdb->get_row($wpdb->prepare(
				"SELECT id, missing_formats, status FROM {$table} WHERE page_url_hash = %s AND attachment_id = %d AND format = %s LIMIT 1",
				$page_key,
				$attachment_id,
				$format
			), ARRAY_A);

			if (is_array($row) && !empty($row['id'])) {
				$missing_formats = $this->merge_on_demand_missing_format($row['missing_formats'] ?? '', $missing_format);
				$wpdb->query($wpdb->prepare(
					"UPDATE {$table} SET page_url = %s, missing_formats = %s, status = 'pending', updated_at = %s, purged_at = NULL, purge_ready_at = NULL WHERE id = %d",
					$page_url,
					$missing_formats,
					$now,
					(int) $row['id']
				));
				return true;
			}

			$missing_formats = $this->merge_on_demand_missing_format('', $missing_format);
			$inserted = $wpdb->insert(
				$table,
				array(
					'page_url_hash'  => $page_key,
					'page_url'       => $page_url,
					'attachment_id'  => $attachment_id,
					'format'         => $format,
					'missing_formats'=> $missing_formats,
					'status'         => 'pending',
					'converted'      => 0,
					'discovered_at'  => $now,
					'updated_at'     => $now,
				),
				array('%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s')
			);

			return false !== $inserted;
		}

		private function mark_on_demand_affected_media_processed($attachment_id, $format, array $result) {
			$attachment_id = absint($attachment_id);
			$format = $this->normalize_media_queue_format($format);
			if ($attachment_id <= 0 || !$this->ensure_media_page_refs_table()) {
				return array();
			}

			global $wpdb;
			$table = $this->get_media_page_refs_table_name();
			$now = current_time('mysql');
			$converted = !empty($result['converted']) || !empty($result['alreadyOptimized']) || ((int) ($result['skippedExisting'] ?? 0) > 0) || (((int) ($result['avif'] ?? 0) + (int) ($result['webp'] ?? 0)) > 0);

			$page_rows = $wpdb->get_results($wpdb->prepare(
				"SELECT DISTINCT page_url_hash FROM {$table} WHERE attachment_id = %d AND format = %s AND (purged_at IS NULL OR purged_at = '0000-00-00 00:00:00') LIMIT 250",
				$attachment_id,
				$format
			), ARRAY_A);

			if (empty($page_rows)) {
				return array();
			}

			$wpdb->query($wpdb->prepare(
				"UPDATE {$table} SET status = 'complete', converted = %d, completed_at = %s, updated_at = %s WHERE attachment_id = %d AND format = %s AND (purged_at IS NULL OR purged_at = '0000-00-00 00:00:00')",
				$converted ? 1 : 0,
				$now,
				$now,
				$attachment_id,
				$format
			));

			$ready_urls = array();
			foreach ((array) $page_rows as $page_row) {
				$page_key = isset($page_row['page_url_hash']) ? (string) $page_row['page_url_hash'] : '';
				if (!preg_match('/^[a-f0-9]{32}$/', $page_key)) {
					continue;
				}

				$summary = $wpdb->get_row($wpdb->prepare(
					"SELECT MAX(page_url) AS page_url, SUM(CASE WHEN status <> 'complete' THEN 1 ELSE 0 END) AS pending_media, SUM(CASE WHEN converted = 1 THEN 1 ELSE 0 END) AS converted_media FROM {$table} WHERE page_url_hash = %s AND (purged_at IS NULL OR purged_at = '0000-00-00 00:00:00')",
					$page_key
				), ARRAY_A);

				if (!is_array($summary)) {
					continue;
				}

				$pending = (int) ($summary['pending_media'] ?? 0);
				$converted_count = (int) ($summary['converted_media'] ?? 0);
				$page_url = isset($summary['page_url']) ? esc_url_raw((string) $summary['page_url']) : '';
				if ($pending <= 0 && $converted_count > 0 && '' !== $page_url) {
					$wpdb->query($wpdb->prepare(
						"UPDATE {$table} SET purge_ready_at = %s, updated_at = %s WHERE page_url_hash = %s AND (purged_at IS NULL OR purged_at = '0000-00-00 00:00:00')",
						$now,
						$now,
						$page_key
					));
					$ready_urls[$page_url] = $page_url;
				}
			}

			return array_values($ready_urls);
		}

		private function mark_on_demand_affected_pages_purged(array $urls) {
			$urls = array_values(array_unique(array_filter(array_map('strval', $urls))));
			if (empty($urls) || !$this->ensure_media_page_refs_table()) {
				return 0;
			}

			global $wpdb;
			$table = $this->get_media_page_refs_table_name();
			$now = current_time('mysql');
			$count = 0;
			foreach ($urls as $url) {
				$page_key = md5($url);
				$updated = $wpdb->query($wpdb->prepare(
					"UPDATE {$table} SET purged_at = %s, updated_at = %s WHERE page_url_hash = %s AND (purged_at IS NULL OR purged_at = '0000-00-00 00:00:00')",
					$now,
					$now,
					$page_key
				));
				if (is_numeric($updated) && (int) $updated > 0) {
					$count++;
				}
			}

			return $count;
		}

		private function purge_ready_on_demand_affected_pages(array $urls) {
			$urls = array_values(array_unique(array_filter(array_map('strval', $urls))));
			if (empty($urls)) {
				return 0;
			}

			$engine = null;
			if (class_exists('Ultra_Cache_Engine') && method_exists('Ultra_Cache_Engine', 'get_instance')) {
				$engine = Ultra_Cache_Engine::get_instance();
			}

			$purged = false;
			if ($engine && method_exists($engine, 'purge_urls')) {
				$purged = $engine->purge_urls($urls, 'media-on-demand', array(
					'reason' => 'on-demand-media-ready',
					'count'  => count($urls),
				));
			} elseif ($engine && method_exists($engine, 'purge_page_by_url')) {
				$success = 0;
				foreach ($urls as $url) {
					if ($engine->purge_page_by_url($url)) {
						$success++;
					}
				}
				$purged = $success > 0;
			}

			if ($purged) {
				$this->mark_on_demand_affected_pages_purged($urls);
				return count($urls);
			}

			return 0;
		}

		private function maybe_queue_missing_optimized_media_from_public_url($public_url, $missing_format) {
			$missing_format = strtolower((string) $missing_format);
			if (!in_array($missing_format, array('avif', 'webp'), true) || !$this->media_output_mode_allows($missing_format)) {
				return false;
			}

			if (!$this->can_queue_missing_media_from_lookup()) {
				return false;
			}

			$relative_path = $this->get_uploads_relative_path_from_public_url($public_url);
			if (!$relative_path) {
				return false;
			}

			$discovery_key = $missing_format . '|' . $relative_path;
			if (isset($this->on_demand_queue_discovery_seen[$discovery_key])) {
				return false;
			}
			$this->on_demand_queue_discovery_seen[$discovery_key] = true;

			$uploads = wp_get_upload_dir();
			$uploads_root = !empty($uploads['basedir']) ? realpath($uploads['basedir']) : false;
			if (!is_string($uploads_root) || '' === $uploads_root) {
				return false;
			}

			$source_file = trailingslashit($uploads_root) . ltrim(str_replace('\\', '/', (string) $relative_path), '/');
			if (!$this->optimized_storage_readable_source_exists($source_file) || !$this->is_allowed_source_file($source_file)) {
				return false;
			}

			$attachment_id = $this->find_attachment_id_for_source_file($source_file);
			if ($attachment_id <= 0) {
				return false;
			}

			$queue_format = 'best';
			$dedupe_key = $this->get_on_demand_queue_dedupe_transient_key($attachment_id, $queue_format, $source_file, $missing_format);
			if (get_transient($dedupe_key)) {
				$this->record_on_demand_affected_page($attachment_id, $queue_format, $missing_format);
				return false;
			}

			$message = 'Queued by on-demand missing media discovery (' . $missing_format . '). Current request stayed lookup-only.';
			$queued = $this->upsert_media_queue_item($attachment_id, $queue_format, 'pending', $message, 0, true);
			if (!$queued) {
				return false;
			}

			$this->record_on_demand_affected_page($attachment_id, $queue_format, $missing_format);
			$this->on_demand_queue_discovery_count++;
			set_transient($dedupe_key, 1, HOUR_IN_SECONDS);
			$this->invalidate_media_work_summary_cache();
			$this->schedule_background_generation_queue();

			return true;
		}

		private function get_media_queue_pending_count($format = null) {
			if (!$this->media_queue_table_exists()) {
				return 0;
			}
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			if (null !== $format) {
				$format = $this->normalize_media_queue_format($format);
				return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND format = %s", $format));
			}

			return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
		}

		private function retire_on_demand_partial_media_queue_rows() {
			if (!$this->media_queue_table_exists()) {
				return 0;
			}

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$count = $wpdb->query($wpdb->prepare(
				"UPDATE {$table} SET status = 'skipped', last_error = %s, updated_at = %s, started_at = NULL, completed_at = %s WHERE status = 'pending' AND format IN ('avif','webp','both') AND last_error LIKE %s",
				'Frontend on-demand partial queue was retired; use manual bulk or WP-CLI media conversion to complete missing variants.',
				current_time('mysql'),
				current_time('mysql'),
				'%Partially optimized by on-demand generation%'
			));

			if (is_numeric($count) && (int) $count > 0) {
				$this->invalidate_media_work_summary_cache();
			}

			return is_numeric($count) ? (int) $count : 0;
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

		public function get_media_queue_status($format = 'best', $force_storage_refresh = false) {
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
			$storage_health = $this->get_media_optimized_storage_health($format, $completed, (bool) $force_storage_refresh);
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
				$result['onDemandAffectedPagePurgeReadyUrls'] = $this->mark_on_demand_affected_media_processed($attachment_id, $format, $result);
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
				$result['onDemandAffectedPagePurgeReadyUrls'] = $this->mark_on_demand_affected_media_processed($attachment_id, $format, $result);
				return $result;
			} catch (Throwable $e) {
				$message = $e->getMessage();
				$wpdb->update($table, array('status' => 'failed', 'last_error' => $message, 'updated_at' => current_time('mysql'), 'completed_at' => current_time('mysql')), array('id' => (int) $row['id']), array('%s', '%s', '%s', '%s'), array('%d'));
				$result = array('success' => false, 'attachment_id' => $attachment_id, 'message' => $message, 'queueStatus' => 'failed', 'converted' => false);
				$result['onDemandAffectedPagePurgeReadyUrls'] = $this->mark_on_demand_affected_media_processed($attachment_id, $format, $result);
				return $result;
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
			$affected_page_purge_ready_urls = array();
			$affected_page_purged = 0;
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
					foreach ((array) ($result['onDemandAffectedPagePurgeReadyUrls'] ?? array()) as $ready_url) {
						$affected_page_purge_ready_urls[(string) $ready_url] = (string) $ready_url;
					}
				}
				$affected_page_purged = $this->purge_ready_on_demand_affected_pages($affected_page_purge_ready_urls);
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
				'onDemandAffectedPagesReady' => count($affected_page_purge_ready_urls),
				'onDemandAffectedPagesPurged' => $affected_page_purged,
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
			$count = $wpdb->query($wpdb->prepare("UPDATE %i SET status = 'pending', last_error = '', updated_at = %s WHERE format = %s AND status = 'failed'", $table, current_time('mysql'), $format));
			return array_merge(array('success' => true, 'retried' => is_numeric($count) ? (int) $count : 0), $this->get_media_queue_status($format));
		}

		public function clear_completed_media_queue_items($format = 'best') {
			if (!$this->ensure_media_queue_table()) {
				return array('success' => false, 'message' => 'Media queue table unavailable.');
			}
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			$count = $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE format = %s AND status IN ('done','skipped')", $table, $format));
			return array_merge(array('success' => true, 'cleared' => is_numeric($count) ? (int) $count : 0), $this->get_media_queue_status($format));
		}

		private function find_attachment_id_for_source_file($source_file) {
			$source_file = is_string($source_file) ? wp_normalize_path($source_file) : '';
			if ('' === $source_file || !$this->optimized_storage_readable_source_exists($source_file)) {
				return 0;
			}

			$source_real = realpath($source_file);
			$memo_key = is_string($source_real) && '' !== $source_real ? wp_normalize_path($source_real) : $source_file;
			if (isset($this->on_demand_source_attachment_memo[$memo_key])) {
				return max(0, (int) $this->on_demand_source_attachment_memo[$memo_key]);
			}

			$relative_path = $this->get_uploads_relative_path_from_source($source_file);
			if (!$relative_path) {
				$this->on_demand_source_attachment_memo[$memo_key] = 0;
				return 0;
			}

			global $wpdb;
			$attachment_id = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id WHERE p.post_type = 'attachment' AND pm.meta_key = '_wp_attached_file' AND pm.meta_value = %s LIMIT 1",
				$relative_path
			));

			if ($attachment_id > 0 && wp_attachment_is_image($attachment_id)) {
				$this->on_demand_source_attachment_memo[$memo_key] = $attachment_id;
				return $attachment_id;
			}

			$uploads = wp_get_upload_dir();
			if (!empty($uploads['baseurl'])) {
				$public_url = trailingslashit((string) $uploads['baseurl']) . ltrim(str_replace('\\', '/', (string) $relative_path), '/');
				$attachment_id = function_exists('attachment_url_to_postid') ? absint(attachment_url_to_postid($public_url)) : 0;
				if ($attachment_id > 0 && wp_attachment_is_image($attachment_id)) {
					$this->on_demand_source_attachment_memo[$memo_key] = $attachment_id;
					return $attachment_id;
				}
			}

			$directory = trim(str_replace('\\', '/', dirname((string) $relative_path)), '/');
			$like = ('.' === $directory || '' === $directory)
				? '%'
				: $wpdb->esc_like($directory . '/') . '%';

			$candidate_ids = $wpdb->get_col($wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id WHERE p.post_type = 'attachment' AND pm.meta_key = '_wp_attached_file' AND pm.meta_value LIKE %s ORDER BY p.ID DESC LIMIT 75",
				$like
			));

			foreach ((array) $candidate_ids as $candidate_id) {
				$candidate_id = absint($candidate_id);
				if ($candidate_id <= 0 || !wp_attachment_is_image($candidate_id)) {
					continue;
				}

				foreach ($this->get_attachment_source_files($candidate_id) as $candidate_source) {
					$candidate_real = realpath($candidate_source);
					if (is_string($candidate_real) && is_string($source_real) && wp_normalize_path($candidate_real) === wp_normalize_path($source_real)) {
						$this->on_demand_source_attachment_memo[$memo_key] = $candidate_id;
						return $candidate_id;
					}
				}
			}

			$this->on_demand_source_attachment_memo[$memo_key] = 0;
			return 0;
		}

		private function get_on_demand_queue_completion_for_format($attachment_id, $format) {
			$attachment_id = absint($attachment_id);
			$format = $this->normalize_media_queue_format($format);
			$source_files = $this->get_attachment_source_files($attachment_id);

			if ($attachment_id <= 0 || empty($source_files)) {
				return array(
					'status' => 'skipped',
					'message' => 'No supported source files were available for this media item.',
					'targets' => 0,
					'completed' => 0,
				);
			}

			$targets = 0;
			$completed = 0;

			foreach ($source_files as $source_file) {
				$avif_target = (bool) $this->get_avif_path_from_source($source_file);
				$webp_target = (bool) $this->get_webp_path_from_source($source_file);
				$avif_done = $avif_target && $this->generated_variant_exists($source_file, 'avif');
				$webp_done = $webp_target && $this->generated_variant_exists($source_file, 'webp');

				if ('best' === $format) {
					if (!$avif_target && !$webp_target) {
						continue;
					}
					$targets++;
					if ($avif_done || $webp_done) {
						$completed++;
					}
					continue;
				}

				$formats = ('both' === $format) ? array('avif', 'webp') : array($format);
				foreach ($formats as $single_format) {
					if ('avif' === $single_format) {
						if (!$avif_target) {
							continue;
						}
						$targets++;
						if ($avif_done) {
							$completed++;
						}
					} elseif ('webp' === $single_format) {
						if (!$webp_target) {
							continue;
						}
						$targets++;
						if ($webp_done) {
							$completed++;
						}
					}
				}
			}

			if ($targets <= 0) {
				return array(
					'status' => 'skipped',
					'message' => 'No supported source files were available for this media format.',
					'targets' => 0,
					'completed' => 0,
				);
			}

			if ($completed >= $targets) {
				return array(
					'status' => 'skipped',
					'message' => 'Already optimized by on-demand generation.',
					'targets' => (int) $targets,
					'completed' => (int) $completed,
				);
			}

			return array(
				'status' => 'pending',
				'message' => 'Partially optimized by on-demand generation; remaining variants queued.',
				'targets' => (int) $targets,
				'completed' => (int) $completed,
			);
		}

		private function write_on_demand_queue_status($attachment_id, $format, array $completion) {
			$attachment_id = absint($attachment_id);
			$format = $this->normalize_media_queue_format($format);
			$status = isset($completion['status']) ? (string) $completion['status'] : 'pending';
			$status = in_array($status, array('pending', 'skipped'), true) ? $status : 'pending';
			$original_status = $status;
			$message = isset($completion['message']) ? (string) $completion['message'] : '';

			if ('pending' === $status) {
				$status = 'skipped';
				$message = 'Frontend on-demand generated an optimized variant; remaining variants were not queued automatically. Use manual bulk or WP-CLI media conversion to complete missing variants.';
			}

			if ($attachment_id <= 0 || !$this->ensure_media_queue_table()) {
				return false;
			}

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$signature = $this->get_attachment_source_signature($attachment_id);
			$now = current_time('mysql');
			$completed = ('skipped' === $status);
			$existing = $wpdb->get_row($wpdb->prepare("SELECT id, status, attempts, last_error FROM {$table} WHERE attachment_id = %d AND format = %s", $attachment_id, $format), ARRAY_A);

			if (is_array($existing) && !empty($existing['id'])) {
				$existing_status = isset($existing['status']) ? (string) $existing['status'] : '';
				$existing_error = isset($existing['last_error']) ? (string) $existing['last_error'] : '';
				$existing_from_on_demand = (false !== stripos($existing_error, 'on-demand'));

				if (!$existing_from_on_demand) {
					return false;
				}

				if ('done' === $existing_status) {
					return false;
				}

				$updated = $wpdb->update(
					$table,
					array(
						'source_mtime' => (int) $signature['mtime'],
						'source_size' => (int) $signature['size'],
						'status' => $status,
						'attempts' => max(0, (int) ($existing['attempts'] ?? 0)),
						'last_error' => $message,
						'updated_at' => $now,
						'started_at' => null,
						'completed_at' => $completed ? $now : null,
					),
					array('id' => (int) $existing['id']),
					array('%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s'),
					array('%d')
				);

				return false !== $updated;
			}

			if ('pending' === $original_status || 'skipped' === $status) {
				return false;
			}

			$inserted = $wpdb->insert(
				$table,
				array(
					'attachment_id' => $attachment_id,
					'format' => $format,
					'source_mtime' => (int) $signature['mtime'],
					'source_size' => (int) $signature['size'],
					'status' => $status,
					'attempts' => 0,
					'last_error' => $message,
					'created_at' => $now,
					'updated_at' => $now,
					'started_at' => null,
					'completed_at' => $completed ? $now : null,
				),
				array('%d', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
			);

			return false !== $inserted;
		}

		public function sync_media_queue_after_on_demand_generation($source_file, $generated_format) {
			$generated_format = strtolower((string) $generated_format);
			if (!in_array($generated_format, array('avif', 'webp'), true)) {
				return array('synced' => false, 'reason' => 'invalid_format');
			}

			$attachment_id = $this->find_attachment_id_for_source_file($source_file);
			if ($attachment_id <= 0) {
				return array('synced' => false, 'reason' => 'attachment_not_found');
			}

			$formats = array_values(array_unique(array('best', 'avif', 'webp', 'both')));
			$statuses = array();
			$synced = 0;

			foreach ($formats as $format) {
				$completion = $this->get_on_demand_queue_completion_for_format($attachment_id, $format);
				if ($this->write_on_demand_queue_status($attachment_id, $format, $completion)) {
					$synced++;
				}
				$statuses[$format] = array(
					'status' => (string) ($completion['status'] ?? 'pending'),
					'targets' => (int) ($completion['targets'] ?? 0),
					'completed' => (int) ($completion['completed'] ?? 0),
				);
			}

			$this->invalidate_media_work_summary_cache();

			return array(
				'synced' => $synced > 0,
				'attachment_id' => $attachment_id,
				'generatedFormat' => $generated_format,
				'rowsSynced' => (int) $synced,
				'statuses' => $statuses,
			);
		}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
}
