<?php
/**
 * UltraCache on-demand affected-page tracking and purge helpers.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Affected_Pages_Trait
{
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache uses private custom media conversion queue tables with validated table identifiers.


		private function get_on_demand_queue_max_per_request() {
			$default = defined('ULTRACACHE_MEDIA_ON_DEMAND_QUEUE_MAX_PER_REQUEST') ? (int) ULTRACACHE_MEDIA_ON_DEMAND_QUEUE_MAX_PER_REQUEST : 20;
			$context = method_exists($this, 'get_media_generation_context') ? $this->get_media_generation_context() : 'frontend';
			$limit = (int) apply_filters('ultracache_media_on_demand_queue_max_per_request', $default, $context);
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


		private function get_on_demand_queue_dedupe_lock_name($attachment_id, $queue_format, $source_file, $missing_format, array $source_signature = array()) {
			$attachment_id = absint($attachment_id);
			$queue_format = $this->normalize_media_queue_format($queue_format);
			$missing_format = strtolower((string) $missing_format);
			$source_file = is_string($source_file) ? wp_normalize_path($source_file) : '';
			$signature = array(
				'mtime' => max(0, (int) ($source_signature['mtime'] ?? 0)),
				'size' => max(0, (int) ($source_signature['size'] ?? 0)),
			);
			if ($signature['mtime'] <= 0 && $signature['size'] <= 0) {
				$signature = $this->get_attachment_source_signature($attachment_id);
			}
			$hash = md5($attachment_id . '|' . $queue_format . '|' . $missing_format . '|' . $source_file . '|' . (int) $signature['mtime'] . '|' . (int) $signature['size']);
			return self::MEDIA_ON_DEMAND_QUEUE_LOCK_PREFIX . $hash;
		}



		private function get_media_page_refs_table_name() {
			global $wpdb;
			$table = $wpdb->prefix . 'ultracache_media_page_refs';
			return function_exists('ultracache_validate_custom_table_name') ? ultracache_validate_custom_table_name($table, 'media_page_refs') : $table;
		}


		private function media_page_refs_table_exists() {
			global $wpdb;
			$table = $this->get_media_page_refs_table_name();
			$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
			return (string) $found === (string) $table;
		}


		private function media_page_refs_index_rows($index_name) {
			global $wpdb;
			$table = $this->get_media_page_refs_table_name();
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


		private function media_page_refs_index_matches($index_name, array $columns, $unique) {
			$rows = $this->media_page_refs_index_rows($index_name);
			if (empty($rows)) {
				return false;
			}
			$actual_columns = array_map(static function ($row) {
				return (string) ($row['Column_name'] ?? '');
			}, $rows);
			$actual_unique = '0' === (string) ($rows[0]['Non_unique'] ?? '1');
			return array_values($columns) === $actual_columns && (bool) $unique === $actual_unique;
		}


		private function media_page_refs_source_contract_exists() {
			if (!$this->media_page_refs_table_exists()) {
				return false;
			}
			global $wpdb;
			$table = $this->get_media_page_refs_table_name();
			$columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table));
			$required = array('source_kind', 'source_identity', 'requested_format');
			if (!empty(array_diff($required, array_map('strval', (array) $columns)))) {
				return false;
			}
			return $this->media_page_refs_index_matches(
				'page_source_format',
				array('page_url_hash', 'source_kind', 'source_identity', 'format', 'requested_format'),
				true
			) && $this->media_page_refs_index_matches(
				'page_attachment_format',
				array('page_url_hash', 'attachment_id', 'format'),
				false
			);
		}


		private function deduplicate_media_page_source_references() {
			global $wpdb;
			$table = $this->get_media_page_refs_table_name();
			$duplicates = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT page_url_hash, source_kind, source_identity, format, requested_format, COUNT(*) AS duplicate_count FROM %i GROUP BY page_url_hash, source_kind, source_identity, format, requested_format HAVING COUNT(*) > 1',
					$table
				),
				ARRAY_A
			);
			foreach ((array) $duplicates as $duplicate) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, status, converted, discovered_at, updated_at FROM %i WHERE page_url_hash = %s AND source_kind = %s AND source_identity = %s AND format = %s AND requested_format = %s ORDER BY CASE status WHEN 'pending' THEN 0 WHEN 'complete' THEN 1 ELSE 2 END ASC, updated_at DESC, id DESC",
						$table,
						(string) ($duplicate['page_url_hash'] ?? ''),
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
				array_shift($rows);
				foreach ($rows as $row) {
					$wpdb->delete($table, array('id' => (int) ($row['id'] ?? 0)), array('%d'));
				}
			}
			$remaining = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM (SELECT 1 FROM %i GROUP BY page_url_hash, source_kind, source_identity, format, requested_format HAVING COUNT(*) > 1) duplicate_groups',
					$table
				)
			);
			return 0 === $remaining;
		}


		private function ensure_media_page_refs_source_indexes() {
			global $wpdb;
			$table = $this->get_media_page_refs_table_name();

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET source_kind = 'attachment', source_identity = SHA2(CONCAT('attachment:', attachment_id), 256), requested_format = '' WHERE attachment_id > 0 AND (source_identity = '' OR source_kind = '')",
					$table
				)
			);

			if (!$this->media_page_refs_index_matches('page_attachment_format', array('page_url_hash', 'attachment_id', 'format'), false)) {
				if (!empty($this->media_page_refs_index_rows('page_attachment_format'))) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Versioned repair of an UltraCache-owned custom-table index whose existing definition cannot be corrected reliably by dbDelta().
					$wpdb->query($wpdb->prepare('ALTER TABLE %i DROP INDEX page_attachment_format', $table));
				}
				if (empty($this->media_page_refs_index_rows('page_attachment_format'))) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Recreates the verified UltraCache-owned index after the versioned legacy-definition repair above.
					$wpdb->query($wpdb->prepare('ALTER TABLE %i ADD KEY page_attachment_format (page_url_hash, attachment_id, format)', $table));
				}
			}

			if (!$this->media_page_refs_index_matches('page_source_format', array('page_url_hash', 'source_kind', 'source_identity', 'format', 'requested_format'), true)) {
				if (!empty($this->media_page_refs_index_rows('page_source_format'))) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Versioned repair of an UltraCache-owned custom-table index whose existing definition cannot be corrected reliably by dbDelta().
					$wpdb->query($wpdb->prepare('ALTER TABLE %i DROP INDEX page_source_format', $table));
				}
				if (!$this->deduplicate_media_page_source_references()) {
					return false;
				}
				if (empty($this->media_page_refs_index_rows('page_source_format'))) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Recreates the deduplicated UltraCache-owned unique index after the versioned legacy-definition repair above.
					$wpdb->query($wpdb->prepare('ALTER TABLE %i ADD UNIQUE KEY page_source_format (page_url_hash, source_kind, source_identity, format, requested_format)', $table));
				}
			}

			return $this->media_page_refs_source_contract_exists();
		}


		public function ensure_media_page_refs_table() {
			global $wpdb;

			$table = $this->get_media_page_refs_table_name();
			$version = (string) get_option(self::MEDIA_PAGE_REFS_DB_VERSION_OPTION, '');
			if (self::MEDIA_PAGE_REFS_DB_VERSION === $version && $this->media_page_refs_source_contract_exists()) {
				return true;
			}

			if (!ultracache_require_wordpress_admin_include('upgrade.php', 'dbDelta')) {
				return false;
			}
			$charset_collate = $wpdb->get_charset_collate();
			$sql = "CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				page_url_hash char(32) NOT NULL,
				page_url text NOT NULL,
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				source_kind varchar(20) NOT NULL DEFAULT 'attachment',
				source_identity char(64) NOT NULL DEFAULT '',
				format varchar(12) NOT NULL DEFAULT 'best',
				requested_format varchar(12) NOT NULL DEFAULT '',
				missing_formats varchar(40) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				converted tinyint(1) unsigned NOT NULL DEFAULT 0,
				discovered_at datetime NULL DEFAULT NULL,
				updated_at datetime NULL DEFAULT NULL,
				completed_at datetime NULL DEFAULT NULL,
				purge_ready_at datetime NULL DEFAULT NULL,
				purged_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY attachment_format_status (attachment_id, format, status),
				KEY source_format_status (source_kind, source_identity, format, requested_format, status),
				KEY page_status (page_url_hash, status),
				KEY purge_ready (purge_ready_at, purged_at),
				KEY purged_at (purged_at),
				KEY updated_at (updated_at)
			) {$charset_collate};";

			dbDelta($sql);
			if ($this->media_page_refs_table_exists() && $this->ensure_media_page_refs_source_indexes()) {
				update_option(self::MEDIA_PAGE_REFS_DB_VERSION_OPTION, self::MEDIA_PAGE_REFS_DB_VERSION, false);
				return true;
			}

			return false;
		}


		private function maybe_cleanup_on_demand_affected_page_refs() {
			if (!$this->ensure_media_page_refs_table() || !function_exists('ultracache_acquire_lock')) {
				return;
			}

			$cleanup_token = 'media-page-refs-cleanup-' . wp_generate_uuid4();
			if (!ultracache_acquire_lock(
				'ultracache_media_page_refs_cleanup_lock',
				$cleanup_token,
				HOUR_IN_SECONDS,
				array('type' => 'media_page_refs_cleanup', 'startedAt' => time())
			)) {
				return;
			}

			global $wpdb;
			$table = $this->get_media_page_refs_table_name();
			$limit = (int) apply_filters('ultracache_media_page_refs_cleanup_max_deletes_per_run', 250);
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



		private function get_on_demand_affected_source_key($source_kind, $source_identity, $format = 'best', $requested_format = '') {
			$requested_format = in_array(strtolower((string) $requested_format), array('avif', 'webp'), true) ? strtolower((string) $requested_format) : '';
			return sanitize_key((string) $source_kind) . '|' . strtolower((string) $source_identity) . '|' . $this->normalize_media_queue_format($format) . '|' . $requested_format;
		}


		private function get_current_on_demand_affected_page_url() {
			$explicit_page_url = isset($this->media_rewrite_page_url_context)
				? esc_url_raw((string) $this->media_rewrite_page_url_context)
				: '';
			if ('' !== $explicit_page_url) {
				return $explicit_page_url;
			}

			if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
				return '';
			}

			$request_uri = ultracache_server_value('REQUEST_URI');
			if ('' === trim((string) $request_uri)) {
				return '';
			}

			$path = wp_unslash((string) $request_uri);
			$parts = wp_parse_url($path);
			if (is_array($parts) && isset($parts['path'])) {
				$path = (string) $parts['path'] . (isset($parts['query']) && '' !== (string) $parts['query'] ? '?' . (string) $parts['query'] : '');
			}

			$url = home_url($path);
			$url = remove_query_arg(array('ultracache_action', '_wpnonce', 'ultracache_odq_test', 'ultracache_cache_bust'), $url);
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
			if ($attachment_id <= 0) {
				return false;
			}
			return $this->record_on_demand_affected_source_page(
				'attachment',
				hash('sha256', 'attachment:' . $attachment_id),
				$format,
				$missing_format,
				$attachment_id,
				''
			);
		}


		private function record_on_demand_affected_source_page($source_kind, $source_identity, $format = 'best', $missing_format = '', $attachment_id = 0, $requested_format = '') {
			$source_kind = sanitize_key((string) $source_kind);
			$source_identity = strtolower(trim((string) $source_identity));
			$attachment_id = absint($attachment_id);
			$format = $this->normalize_media_queue_format($format);
			$requested_format = in_array(strtolower((string) $requested_format), array('avif', 'webp'), true) ? strtolower((string) $requested_format) : '';
			if (!in_array($source_kind, array('attachment', 'local_asset'), true) || !preg_match('/^[a-f0-9]{64}$/', $source_identity)) {
				return false;
			}

			$page_url = $this->get_current_on_demand_affected_page_url();
			if ('' === $page_url) {
				return false;
			}
			$page_key = md5($page_url);
			$seen_key = $page_key . '|' . $this->get_on_demand_affected_source_key($source_kind, $source_identity, $format, $requested_format);
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
			$missing_formats = $this->merge_on_demand_missing_format('', $missing_format);
			$upserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO %i (page_url_hash, page_url, attachment_id, source_kind, source_identity, format, requested_format, missing_formats, status, converted, discovered_at, updated_at) VALUES (%s, %s, %d, %s, %s, %s, %s, %s, 'pending', 0, %s, %s) ON DUPLICATE KEY UPDATE page_url = VALUES(page_url), missing_formats = CASE WHEN VALUES(missing_formats) = '' THEN missing_formats WHEN missing_formats = '' THEN VALUES(missing_formats) WHEN FIND_IN_SET(VALUES(missing_formats), missing_formats) > 0 THEN missing_formats ELSE CONCAT(missing_formats, ',', VALUES(missing_formats)) END, status = 'pending', updated_at = VALUES(updated_at), purged_at = NULL, purge_ready_at = NULL",
					$table,
					$page_key,
					$page_url,
					$attachment_id,
					$source_kind,
					$source_identity,
					$format,
					$requested_format,
					$missing_formats,
					$now,
					$now
				)
			);
			return false !== $upserted;
		}


		private function mark_on_demand_affected_media_processed($attachment_id, $format, array $result) {
			$attachment_id = absint($attachment_id);
			if ($attachment_id <= 0) {
				return array();
			}
			return $this->mark_on_demand_affected_source_processed('attachment', hash('sha256', 'attachment:' . $attachment_id), $format, $result, '');
		}


		private function mark_on_demand_affected_source_processed($source_kind, $source_identity, $format, array $result, $requested_format = '') {
			$source_kind = sanitize_key((string) $source_kind);
			$source_identity = strtolower(trim((string) $source_identity));
			$format = $this->normalize_media_queue_format($format);
			$requested_format = in_array(strtolower((string) $requested_format), array('avif', 'webp'), true) ? strtolower((string) $requested_format) : '';
			if (!in_array($source_kind, array('attachment', 'local_asset'), true) || !preg_match('/^[a-f0-9]{64}$/', $source_identity) || !$this->ensure_media_page_refs_table()) {
				return array();
			}

			global $wpdb;
			$table = $this->get_media_page_refs_table_name();
			$now = current_time('mysql');
			$converted = !empty($result['converted']) || !empty($result['alreadyOptimized']) || ((int) ($result['skippedExisting'] ?? 0) > 0) || (((int) ($result['avif'] ?? 0) + (int) ($result['webp'] ?? 0)) > 0);
			$page_rows = $wpdb->get_results($wpdb->prepare(
				"SELECT DISTINCT page_url_hash FROM %i WHERE source_kind = %s AND source_identity = %s AND format = %s AND requested_format = %s AND (purged_at IS NULL OR purged_at = '0000-00-00 00:00:00') LIMIT 250",
				$table,
				$source_kind,
				$source_identity,
				$format,
				$requested_format
			), ARRAY_A);
			if (empty($page_rows)) {
				return array();
			}
			$wpdb->query($wpdb->prepare(
				"UPDATE %i SET status = 'complete', converted = %d, completed_at = %s, updated_at = %s WHERE source_kind = %s AND source_identity = %s AND format = %s AND requested_format = %s AND (purged_at IS NULL OR purged_at = '0000-00-00 00:00:00')",
				$table,
				$converted ? 1 : 0,
				$now,
				$now,
				$source_kind,
				$source_identity,
				$format,
				$requested_format
			));

			$ready_urls = array();
			foreach ((array) $page_rows as $page_row) {
				$page_key = isset($page_row['page_url_hash']) ? (string) $page_row['page_url_hash'] : '';
				if (!preg_match('/^[a-f0-9]{32}$/', $page_key)) {
					continue;
				}
				$summary = $wpdb->get_row($wpdb->prepare(
					"SELECT MAX(page_url) AS page_url, SUM(CASE WHEN status <> 'complete' THEN 1 ELSE 0 END) AS pending_media, SUM(CASE WHEN converted = 1 THEN 1 ELSE 0 END) AS converted_media FROM %i WHERE page_url_hash = %s AND (purged_at IS NULL OR purged_at = '0000-00-00 00:00:00')",
					$table,
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
						"UPDATE %i SET purge_ready_at = %s, updated_at = %s WHERE page_url_hash = %s AND (purged_at IS NULL OR purged_at = '0000-00-00 00:00:00')",
						$table,
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
					"UPDATE %i SET purged_at = %s, updated_at = %s WHERE page_url_hash = %s AND (purged_at IS NULL OR purged_at = '0000-00-00 00:00:00')",
					$table,
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


		private function maybe_queue_missing_optimized_media_from_public_url($public_url, $missing_format, $discovery_reason = 'missing', array $source_signature = array()) {
			$missing_format = strtolower((string) $missing_format);
			$discovery_reason = strtolower((string) $discovery_reason);
			if (!in_array($discovery_reason, array('missing', 'stale', 'indeterminate'), true)) {
				$discovery_reason = 'missing';
			}
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
			$already_discovered = isset($this->on_demand_queue_discovery_seen[$discovery_key]);

			$uploads = ultracache_uploads_base_info();
			$uploads_root = !empty($uploads['basedir']) ? realpath($uploads['basedir']) : false;
			if (!is_string($uploads_root) || '' === $uploads_root) {
				return false;
			}

			$source_file = trailingslashit($uploads_root) . ltrim(str_replace('\\', '/', (string) $relative_path), '/');
			if (!$this->optimized_storage_readable_source_exists($source_file) || !$this->is_source_file_supported_for_format($source_file, $missing_format)) {
				return false;
			}

			$attachment_id = $this->find_attachment_id_for_source_file($source_file);
			if ($attachment_id <= 0) {
				return false;
			}

			$queue_format = 'best';
			$this->record_on_demand_affected_page($attachment_id, $queue_format, $missing_format);
			if ($already_discovered) {
				return false;
			}
			$this->on_demand_queue_discovery_seen[$discovery_key] = true;
			$dedupe_lock_name = $this->get_on_demand_queue_dedupe_lock_name($attachment_id, $queue_format, $source_file, $missing_format, $source_signature);
			$dedupe_lock_token = 'media-odq-' . wp_generate_uuid4();
			if (!function_exists('ultracache_acquire_lock') || !ultracache_acquire_lock(
				$dedupe_lock_name,
				$dedupe_lock_token,
				HOUR_IN_SECONDS,
				array(
					'type' => 'media_on_demand_queue_dedupe',
					'attachmentId' => $attachment_id,
					'format' => $queue_format,
					'missingFormat' => $missing_format,
				)
			)) {
				$this->record_on_demand_affected_page($attachment_id, $queue_format, $missing_format);
				return false;
			}

			$message = 'Queued by on-demand ' . $discovery_reason . ' media discovery (' . $missing_format . '). Current request stayed lookup-only.';
			$queued = $this->upsert_media_queue_item($attachment_id, $queue_format, 'pending', $message, 0, true);
			if (!$queued) {
				if (function_exists('ultracache_release_lock')) {
					ultracache_release_lock($dedupe_lock_name, $dedupe_lock_token);
				}
				return false;
			}

			$this->record_on_demand_affected_page($attachment_id, $queue_format, $missing_format);
			$this->on_demand_queue_discovery_count++;
			$this->invalidate_media_work_summary_cache();
			$this->queue_background_generation_dispatch('on_demand');

			return true;
		}


		private function maybe_queue_missing_local_asset_media($public_url, array $source, $missing_format, $discovery_reason = 'missing', array $source_signature = array()) {
			$missing_format = strtolower((string) $missing_format);
			$discovery_reason = in_array((string) $discovery_reason, array('missing', 'stale', 'indeterminate'), true) ? (string) $discovery_reason : 'missing';
			if (!in_array($missing_format, array('avif', 'webp'), true) || !$this->media_output_mode_allows($missing_format) || !$this->can_queue_missing_media_from_lookup()) {
				return false;
			}
			$normalized = $this->normalize_local_asset_queue_source($source, $missing_format);
			if (empty($normalized)) {
				return false;
			}
			$discovery_key = 'local_asset|' . $normalized['source_identity'] . '|' . $missing_format;
			$already_discovered = isset($this->on_demand_queue_discovery_seen[$discovery_key]);
			$this->record_on_demand_affected_source_page('local_asset', $normalized['source_identity'], 'best', $missing_format, 0, $missing_format);
			if ($already_discovered) {
				return false;
			}
			$this->on_demand_queue_discovery_seen[$discovery_key] = true;
			$mtime = max(0, (int) ($source_signature['mtime'] ?? $normalized['source_mtime']));
			$size = max(0, (int) ($source_signature['size'] ?? $normalized['source_size']));
			$lock_hash = hash('sha256', $normalized['source_identity'] . '|' . $missing_format . '|' . $mtime . '|' . $size);
			$lock_name = self::MEDIA_ON_DEMAND_QUEUE_LOCK_PREFIX . $lock_hash;
			$lock_token = 'media-local-odq-' . wp_generate_uuid4();
			if (!function_exists('ultracache_acquire_lock') || !ultracache_acquire_lock(
				$lock_name,
				$lock_token,
				HOUR_IN_SECONDS,
				array('type' => 'media_local_asset_on_demand_queue_dedupe', 'sourceIdentity' => $normalized['source_identity'], 'requestedFormat' => $missing_format)
			)) {
				return false;
			}
			$message = 'Queued by on-demand ' . $discovery_reason . ' local-asset discovery (' . $missing_format . '). Current request stayed lookup-only.';
			$queued = $this->upsert_local_asset_media_queue_item($source, $missing_format, 'pending', $message, 0, true);
			if (!$queued) {
				if (function_exists('ultracache_release_lock')) {
					ultracache_release_lock($lock_name, $lock_token);
				}
				return false;
			}
			$this->on_demand_queue_discovery_count++;
			$this->invalidate_media_work_summary_cache();
			$this->queue_background_generation_dispatch('on_demand_local_asset');
			return true;
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

			$uploads = ultracache_uploads_base_info();
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

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
