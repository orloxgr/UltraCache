<?php
/**
 * Media converter for UltraCache.
 */

defined('ABSPATH') || exit;

if (!class_exists('Ultra_Cache_Media_Converter')) {

	final class Ultra_Cache_Media_Converter {

		/**
		 * Singleton instance.
		 *
		 * @var Ultra_Cache_Media_Converter|null
		 */
		private static $instance = null;

		/**
		 * Whether final HTML rewrite buffering has started.
		 *
		 * @var bool
		 */
		private $final_buffering = false;

		/**
		 * Number of frontend on-demand conversions started during this request.
		 *
		 * @var int
		 */
		private $on_demand_conversions_started = 0;

		/**
		 * Request start timestamp used for frontend on-demand conversion budgeting.
		 *
		 * @var float|null
		 */
		private $on_demand_request_started_at = null;

		/**
		 * Background conversion queue option name.
		 */
		const BACKGROUND_QUEUE_OPTION = 'ucwp_media_conversion_queue';

		/**
		 * Background conversion cron hook.
		 */
		const BACKGROUND_QUEUE_HOOK = 'ucwp_process_media_conversion_queue';

		/**
		 * Background conversion queue lock transient.
		 */
		const BACKGROUND_QUEUE_LOCK = 'ucwp_media_conversion_queue_lock';

		/**
		 * Cached media work summary transient.
		 */
		const MEDIA_WORK_SUMMARY_TRANSIENT = 'ucwp_media_work_summary_v1';

		/**
		 * Stores the most recent media conversion diagnostics.
		 */
		const MEDIA_DIAGNOSTICS_OPTION = 'ucwp_media_diagnostics_v1';

		/** Persistent media conversion queue table version. */
		const MEDIA_QUEUE_DB_VERSION = '1';

		/** Persistent media conversion queue database version option. */
		const MEDIA_QUEUE_DB_VERSION_OPTION = 'ucwp_media_queue_db_version';

		/** Persistent media conversion queue rebuild cursor option. */
		const MEDIA_QUEUE_BUILD_STATE_OPTION = 'ucwp_media_queue_build_state_v1';

		/** Persistent media conversion queue lock transient. */
		const MEDIA_QUEUE_PROCESS_LOCK = 'ucwp_media_queue_process_lock_v1';

		/** Seconds before a processing media queue item is considered stale. */
		const MEDIA_QUEUE_PROCESSING_TTL = 600;

		/**
		 * Get singleton instance.
		 *
		 * @return Ultra_Cache_Media_Converter
		 */
		public static function get_instance() {
			if (null === self::$instance) {
				self::$instance = new self();
			}

			return self::$instance;
		}
		private function get_default_batch_size() {
			return 250;
		}

		private function invalidate_media_work_summary_cache() {
			delete_transient(self::MEDIA_WORK_SUMMARY_TRANSIENT);
		}

		private function update_media_diagnostic_state(array $updates) {
			$current = get_option(self::MEDIA_DIAGNOSTICS_OPTION, array());
			if (!is_array($current)) {
				$current = array();
			}
			$state = array_merge($current, $updates);
			$state['updatedAt'] = time();
			update_option(self::MEDIA_DIAGNOSTICS_OPTION, $state, false);
		}

		private function get_media_diagnostic_state() {
			$state = get_option(self::MEDIA_DIAGNOSTICS_OPTION, array());
			return is_array($state) ? $state : array();
		}

		private function reset_media_diagnostic_state() {
			$this->update_media_diagnostic_state(array(
				'lastAvifEncodeError' => '',
				'lastAvifEncodeEngine' => '',
				'lastAvifEncodeFile' => '',
				'lastAvifEncodeAt' => 0,
				'lastImageEditorClass' => '',
			));
		}

		private function get_preferred_avif_diagnostic_engine() {
			$preferred = $this->detect_preferred_image_editor_class();
			if ('WP_Image_Editor_Imagick' === $preferred || $this->supports_imagick_avif()) {
				return array('engine' => 'imagick', 'class' => 'WP_Image_Editor_Imagick');
			}
			if ('WP_Image_Editor_GD' === $preferred || $this->supports_gd_avif()) {
				return array('engine' => 'gd', 'class' => 'WP_Image_Editor_GD');
			}
			return array('engine' => 'existing', 'class' => $preferred ?: '');
		}

		private function mark_existing_avif_variant_available($source_file) {
			$source_file = (string) $source_file;
			if ('' === $source_file) {
				return;
			}
			$avif_path = $this->get_avif_path_from_source($source_file);
			if (!$avif_path || !file_exists($avif_path)) {
				return;
			}
			$preferred = $this->get_preferred_avif_diagnostic_engine();
			$this->update_media_diagnostic_state(array(
				'lastImageEditorClass' => (string) $preferred['class'],
				'lastAvifEncodeEngine' => (string) $preferred['engine'],
				'lastAvifEncodeError' => '',
				'lastAvifEncodeFile' => $source_file,
				'lastAvifEncodeAt' => time(),
			));
		}

		private function detect_preferred_image_editor_class() {
			if (!function_exists('wp_get_image_editor')) {
				$maybe = ABSPATH . 'wp-admin/includes/image.php';
				if (file_exists($maybe)) {
					require_once $maybe;
				}
			}

			if ($this->supports_imagick_avif() || $this->supports_imagick_webp()) {
				return 'WP_Image_Editor_Imagick';
			}

			if ($this->supports_gd_avif() || $this->supports_gd_webp()) {
				return 'WP_Image_Editor_GD';
			}

			$editors = apply_filters('wp_image_editors', array('WP_Image_Editor_Imagick', 'WP_Image_Editor_GD'));
			if (!is_array($editors)) {
				return '';
			}
			foreach ($editors as $editor_class) {
				$editor_class = is_string($editor_class) ? $editor_class : '';
				if ('' !== $editor_class && class_exists($editor_class)) {
					return $editor_class;
				}
			}
			return '';
		}

		private function count_attachment_source_files($attachment_id) {
			return count($this->get_attachment_source_files($attachment_id));
		}

		private function get_media_work_summary() {
			$cached = get_transient(self::MEDIA_WORK_SUMMARY_TRANSIENT);
			if (is_array($cached) && isset($cached['attachmentsTotal'], $cached['workTotal'])) {
				return $cached;
			}

			$attachments_total = 0;
			$work_total = 0;
			$offset = 0;
			$limit = $this->get_default_batch_size();
			do {
				$batch = $this->get_media_ids_batch($offset, $limit, false);
				$items = array_map('intval', (array) ($batch['items'] ?? array()));
				foreach ($items as $attachment_id) {
					$attachments_total++;
					$work_total += max(1, $this->count_attachment_source_files($attachment_id));
				}
				$offset = (int) ($batch['nextOffset'] ?? ($offset + count($items)));
			} while (!empty($batch['hasMore']) && !empty($items));

			$summary = array(
				'attachmentsTotal' => (int) $attachments_total,
				'workTotal' => (int) max($attachments_total, $work_total),
			);
			set_transient(self::MEDIA_WORK_SUMMARY_TRANSIENT, $summary, 10 * MINUTE_IN_SECONDS);
			return $summary;
		}


		/**
		 * Constructor.
		 */
		private function __construct() {
			add_filter('wp_generate_attachment_metadata', array($this, 'maybe_generate_avif_on_upload'), 20, 2);
			add_action('delete_attachment', array($this, 'delete_avif_by_attachment_id'));
			add_action(self::BACKGROUND_QUEUE_HOOK, array($this, 'process_background_generation_queue'));
			add_action('init', array($this, 'maybe_schedule_pending_background_generation'), 20);
			add_filter('wp_get_attachment_image_attributes', array($this, 'filter_attachment_image_attributes'), 20, 3);
			add_filter('wp_calculate_image_srcset', array($this, 'filter_attachment_image_srcset'), 20, 5);
			add_filter('the_content', array($this, 'rewrite_html_image_urls'), 999);
			add_filter('post_thumbnail_html', array($this, 'rewrite_html_image_urls'), 999);
			add_filter('widget_text_content', array($this, 'rewrite_html_image_urls'), 999);
			add_filter('render_block', array($this, 'rewrite_html_image_urls'), 999);
			add_action('template_redirect', array($this, 'maybe_start_final_html_buffer'), 999);
		}

		/**
		 * Maybe generate next-gen image files when image metadata is generated.
		 *
		 * AVIF is preferred. If AVIF generation fails, WebP is generated as a fallback.
		 *
		 * @param array $metadata      Attachment metadata.
		 * @param int   $attachment_id Attachment ID.
		 * @return array
		 */
		public function maybe_generate_avif_on_upload($metadata, $attachment_id) {
			if (!$this->is_media_optimization_enabled() || !$this->is_generate_on_upload_enabled()) {
				return $metadata;
			}

			if (!$this->is_supported()) {
				return $metadata;
			}

			$attachment_id = absint($attachment_id);
			if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
				return $metadata;
			}

			$this->enqueue_attachment_for_background_generation($attachment_id);

			return $metadata;
		}

		/**
		 * Queue one attachment for background conversion.
		 *
		 * @param int $attachment_id Attachment ID.
		 * @return void
		 */
		private function enqueue_attachment_for_background_generation($attachment_id) {
			$attachment_id = absint($attachment_id);
			if ($attachment_id <= 0) {
				return;
			}

			$this->upsert_media_queue_item($attachment_id, 'best', 'pending', '', 0);
			$this->invalidate_media_work_summary_cache();
			$this->schedule_background_generation_queue();
		}

		/**
		 * Remove one attachment from the background queue.
		 *
		 * @param int $attachment_id Attachment ID.
		 * @return void
		 */
		private function dequeue_attachment_from_background_generation($attachment_id) {
			$attachment_id = absint($attachment_id);
			if ($attachment_id <= 0) {
				return;
			}

			$queue = $this->get_background_generation_queue();
			if (isset($queue[(string) $attachment_id])) {
				unset($queue[(string) $attachment_id]);
				$this->persist_background_generation_queue($queue);
			}

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			if ($this->media_queue_table_exists()) {
				$wpdb->delete($table, array('attachment_id' => $attachment_id), array('%d'));
			}
		}

		/**
		 * Return the queued attachment IDs for background conversion.
		 *
		 * @return array<string,int>
		 */
		private function get_background_generation_queue() {
			$queue = get_option(self::BACKGROUND_QUEUE_OPTION, array());
			if (!is_array($queue)) {
				return array();
			}

			$normalized = array();
			foreach ($queue as $attachment_id => $queued_at) {
				$attachment_id = absint($attachment_id);
				if ($attachment_id <= 0) {
					continue;
				}
				$normalized[(string) $attachment_id] = max(0, (int) $queued_at);
			}

			return $normalized;
		}

		/**
		 * Persist the background conversion queue.
		 *
		 * @param array<string,int> $queue Queue payload.
		 * @return void
		 */
		private function persist_background_generation_queue(array $queue) {
			if (empty($queue)) {
				delete_option(self::BACKGROUND_QUEUE_OPTION);
				return;
			}

			update_option(self::BACKGROUND_QUEUE_OPTION, $queue, false);
		}

		/**
		 * Schedule a near-future background queue run.
		 *
		 * @return void
		 */
		private function schedule_background_generation_queue() {
			if (!wp_next_scheduled(self::BACKGROUND_QUEUE_HOOK)) {
				wp_schedule_single_event(time() + 15, self::BACKGROUND_QUEUE_HOOK);
			}
		}

		/**
		 * Re-schedule the background queue if items exist but no event is pending.
		 *
		 * @return void
		 */
		public function maybe_schedule_pending_background_generation() {
			if ($this->get_media_queue_pending_count() <= 0 && empty($this->get_background_generation_queue())) {
				return;
			}

			$this->schedule_background_generation_queue();
		}

		/**
		 * Process a small batch of queued attachments in WP-Cron.
		 *
		 * @return void
		 */
		public function process_background_generation_queue() {
			if (!$this->is_media_optimization_enabled() || !$this->is_generate_on_upload_enabled() || !$this->is_supported()) {
				return;
			}

			if (get_transient(self::BACKGROUND_QUEUE_LOCK)) {
				return;
			}

			set_transient(self::BACKGROUND_QUEUE_LOCK, 1, 5 * MINUTE_IN_SECONDS);

			try {
				$legacy_queue = $this->get_background_generation_queue();
				foreach (array_keys($legacy_queue) as $attachment_id) {
					$attachment_id = absint($attachment_id);
					if ($attachment_id > 0) {
						$this->upsert_media_queue_item($attachment_id, 'best', 'pending', '', 0);
					}
				}
				$this->persist_background_generation_queue(array());

				$batch_size = (int) apply_filters('ucwp_media_queue_batch_size', 2);
				$batch_size = max(1, min(10, $batch_size));

				$result = $this->process_media_queue_batch(array(
					'limit' => $batch_size,
					'format' => 'best',
					'only_missing' => true,
				));

				if (!empty($result['remaining'])) {
					$this->schedule_background_generation_queue();
				}
			} finally {
				delete_transient(self::BACKGROUND_QUEUE_LOCK);
			}
		}

		/**
		 * Return the persistent media queue table name.
		 *
		 * @return string
		 */
		private function get_media_queue_table_name() {
			global $wpdb;
			return $wpdb->prefix . 'ucwp_media_queue';
		}

		/**
		 * Determine whether the media queue table already exists.
		 *
		 * @return bool
		 */
		private function media_queue_table_exists() {
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
			return (string) $found === (string) $table;
		}

		/**
		 * Create/update the persistent media conversion queue table.
		 *
		 * @return bool
		 */
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

		/**
		 * Normalize a media queue output format.
		 *
		 * @param string $format Format request.
		 * @return string
		 */
		private function normalize_media_queue_format($format) {
			$format = strtolower(trim((string) $format));
			return in_array($format, array('best', 'avif', 'webp', 'both'), true) ? $format : 'best';
		}

		/**
		 * Return source signature for an attachment's original file.
		 *
		 * @param int $attachment_id Attachment ID.
		 * @return array<string,int>
		 */
		private function get_attachment_source_signature($attachment_id) {
			$file = get_attached_file(absint($attachment_id));
			if (!$file || !is_string($file) || !file_exists($file)) {
				return array('mtime' => 0, 'size' => 0);
			}

			return array(
				'mtime' => (int) @filemtime($file),
				'size'  => (int) @filesize($file),
			);
		}


		/**
		 * Return whether an optimized output directory contains at least one file with the expected extension.
		 * This is a lightweight existence probe, not a full media-library verification.
		 *
		 * @param string $dir Directory to inspect.
		 * @param string $extension Expected extension without dot.
		 * @return bool
		 */
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

		/**
		 * Return lightweight optimized-storage health for queue preflight/status reporting.
		 *
		 * @param string $format Queue media format.
		 * @param int    $completed_rows Completed queue rows for this format.
		 * @return array<string,mixed>
		 */
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
				'avifDirExists' => $avif_dir_exists,
				'webpDirExists' => $webp_dir_exists,
				'avifHasFiles' => $avif_has_files,
				'webpHasFiles' => $webp_has_files,
				'targetHasFiles' => $target_has_files,
				'targetMissing' => $target_missing,
				'needsRepair' => $needs_repair,
				'message' => $needs_repair ? 'Optimized image files appear to be missing. Start/Resume can repair the queue without a full library rescan.' : '',
			);
		}

		/**
		 * Requeue completed rows when optimized storage has been removed.
		 *
		 * @param string $format Queue media format.
		 * @return array<string,mixed>
		 */
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

			if (defined('UCWP_AVIF_DIR') && UCWP_AVIF_DIR && !is_dir(UCWP_AVIF_DIR)) {
				wp_mkdir_p(UCWP_AVIF_DIR);
			}
			if (defined('UCWP_WEBP_DIR') && UCWP_WEBP_DIR && !is_dir(UCWP_WEBP_DIR)) {
				wp_mkdir_p(UCWP_WEBP_DIR);
			}

			$count = $wpdb->query($wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', last_error = %s, updated_at = %s, started_at = NULL, completed_at = NULL WHERE format = %s AND status IN ('done','skipped')",
				'Optimized image files were missing; queued for repair.',
				current_time('mysql'),
				$format
			));

			return array_merge(array('repaired' => true, 'requeued' => is_numeric($count) ? (int) $count : 0, 'reason' => 'optimized_storage_missing'), $health);
		}

		/**
		 * Upsert one attachment into the persistent media conversion queue.
		 *
		 * @param int    $attachment_id Attachment ID.
		 * @param string $format        Output format.
		 * @param string $status        Queue status.
		 * @param string $last_error    Last error message.
		 * @param int    $attempts      Attempt count.
		 * @return bool
		 */
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

		/**
		 * Return count of pending queue items.
		 *
		 * @return int
		 */
		private function get_media_queue_pending_count() {
			if (!$this->media_queue_table_exists()) {
				return 0;
			}
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
		}

		/**
		 * Reset stale processing items back to pending.
		 *
		 * @return int
		 */
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

		/** Start a chunked media queue rebuild for admin/REST runs. */
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

		/** Return chunked queue build state. */
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

		/** Append one safe-size chunk to the media queue. */
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

		/**
		 * Rebuild the persistent media conversion queue from the media library.
		 *
		 * @param string $format       Output format.
		 * @param bool   $only_missing Whether processing should skip existing variants.
		 * @param int    $limit        Optional attachment limit.
		 * @return array<string,int|string|bool>
		 */
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

		/**
		 * Return persistent media queue status counts.
		 *
		 * @param string $format Output format.
		 * @return array<string,mixed>
		 */
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

		/**
		 * Get the next pending media queue batch. Cursor is the queue row ID, not a WP_Query offset.
		 *
		 * @param int    $cursor       Last queue row ID.
		 * @param int    $limit        Batch limit.
		 * @param string $format       Output format.
		 * @param bool   $auto_rebuild Build queue if no rows exist.
		 * @return array<string,mixed>
		 */
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

		/**
		 * Process one attachment and update persistent queue state.
		 *
		 * @param int    $attachment_id Attachment ID.
		 * @param string $format        Output format.
		 * @param bool   $only_missing  Whether to skip existing generated variants.
		 * @return array<string,mixed>
		 */
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

		/**
		 * Process a small queue batch with an optional soft time budget.
		 *
		 * @param array<string,mixed> $args Processing args.
		 * @return array<string,mixed>
		 */
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

		/** Repair media queue rows when optimized output storage is missing. */
		public function repair_media_conversion_queue($format = 'best') {
			if (!$this->ensure_media_queue_table()) {
				return array('success' => false, 'message' => 'Media queue table unavailable.');
			}

			$repair = $this->repair_media_queue_if_optimized_storage_missing($format);
			return array_merge(array('success' => true, 'repair' => $repair), $this->get_media_queue_status($format));
		}

		/** Retry failed media queue rows. */
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

		/** Clear completed media queue rows. */
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

		/**
		 * Convert a source image file to AVIF.
		 *
		 * @param string $source_file Absolute source file path.
		 * @return string|false Destination AVIF path on success, false on failure.
		 */
		public function to_avif($source_file) {
			$source_file = (string) $source_file;

			if (!file_exists($source_file) || !is_readable($source_file)) {
				return false;
			}

			if (!$this->is_allowed_source_file($source_file)) {
				return false;
			}

			if (!$this->supports_avif()) {
				return false;
			}

			$dest_file = $this->get_avif_path_from_source($source_file);

			if (!$dest_file) {
				return false;
			}

			$dest_dir = dirname($dest_file);

			if (!file_exists($dest_dir)) {
				wp_mkdir_p($dest_dir);
			}

			$success = false;
			$this->reset_media_diagnostic_state();
			$prefer_imagick = $this->supports_imagick_avif();

			if ($prefer_imagick) {
				$success = $this->convert_with_imagick($source_file, $dest_file, 'avif', 60);
			}

			if (!$success && !$prefer_imagick) {
				$success = $this->convert_with_wp_image_editor($source_file, $dest_file, 'image/avif', 60);
			}

			if (!$success && !$prefer_imagick && $this->supports_gd_avif()) {
				$success = $this->convert_with_gd($source_file, $dest_file, 'avif', 60);
			}

			if (!$success) {
				if (file_exists($dest_file)) {
					ucwp_safe_unlink($dest_file);
				}
				return false;
			}

			if (!file_exists($dest_file) || (int) ucwp_safe_filesize($dest_file, 'media_converter_dest_verify') <= 0) {
				if (file_exists($dest_file)) {
					ucwp_safe_unlink($dest_file);
				}
				return false;
			}

			return $dest_file;
		}

		/**
		 * Convert a source image file to WebP.
		 *
		 * @param string $source_file Absolute source file path.
		 * @return string|false Destination WebP path on success, false on failure.
		 */
		public function to_webp($source_file) {
			$source_file = (string) $source_file;

			if (!file_exists($source_file) || !is_readable($source_file)) {
				return false;
			}

			if (!$this->is_webp_fallback_source_file($source_file)) {
				return false;
			}

			if (!$this->supports_webp()) {
				return false;
			}

			$dest_file = $this->get_webp_path_from_source($source_file);

			if (!$dest_file) {
				return false;
			}

			$dest_dir = dirname($dest_file);

			if (!file_exists($dest_dir)) {
				wp_mkdir_p($dest_dir);
			}

			$success = false;

			$success = $this->convert_with_wp_image_editor($source_file, $dest_file, 'image/webp', 82);

			if (!$success && $this->supports_imagick_webp()) {
				$success = $this->convert_with_imagick($source_file, $dest_file, 'webp', 82);
			}

			if (!$success && $this->supports_gd_webp()) {
				$success = $this->convert_with_gd($source_file, $dest_file, 'webp', 82);
			}

			if (!$success) {
				if (file_exists($dest_file)) {
					ucwp_safe_unlink($dest_file);
				}
				return false;
			}

			if (!file_exists($dest_file) || (int) ucwp_safe_filesize($dest_file, 'media_converter_dest_verify') <= 0) {
				if (file_exists($dest_file)) {
					ucwp_safe_unlink($dest_file);
				}
				return false;
			}

			return $dest_file;
		}

		/**
		 * Generate the best available next-gen format for a source file.
		 *
		 * @param string $source_file Source file path.
		 * @return string|false
		 */
		private function generate_best_format($source_file) {
			$mode = $this->get_media_output_mode();
			if ('avif' === $mode) {
				return $this->to_avif($source_file);
			}
			if ('webp' === $mode) {
				return $this->to_webp($source_file);
			}
			$avif = $this->to_avif($source_file);
			if ($avif) {
				return $avif;
			}

			$webp = $this->to_webp($source_file);
			if ($webp) {
				return $webp;
			}

			return false;
		}


		private function get_attachment_source_files($attachment_id) {
			$attachment_id = absint($attachment_id);

			if ($attachment_id <= 0) {
				return array();
			}

			$file = get_attached_file($attachment_id);
			if (!$file || !is_string($file) || !file_exists($file)) {
				return array();
			}

			$files = array($file);
			$meta  = wp_get_attachment_metadata($attachment_id);

			if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
				$base_dir = dirname($file);
				foreach ($meta['sizes'] as $size) {
					if (empty($size['file'])) {
						continue;
					}

					$size_file = trailingslashit($base_dir) . ltrim((string) $size['file'], '/');
					if (file_exists($size_file)) {
						$files[] = $size_file;
					}
				}
			}

			return array_values(array_unique($files));
		}

		private function generated_variant_exists($source_file, $format) {
			$format = strtolower((string) $format);

			if ('avif' === $format) {
				$path = $this->get_avif_path_from_source($source_file);
				return $path && file_exists($path);
			}

			if ('webp' === $format) {
				$path = $this->get_webp_path_from_source($source_file);
				return $path && file_exists($path);
			}

			return false;
		}

		public function generate_attachment_formats($attachment_id, $format = 'best', $only_missing = false) {
			$attachment_id = absint($attachment_id);
			$format        = strtolower((string) $format);
			$only_missing  = (bool) $only_missing;

			$summary = array(
				'attachment_id' => $attachment_id,
				'success'       => false,
				'processed'     => 0,
				'avif'          => 0,
				'webp'          => 0,
				'skippedExisting' => 0,
				'sourceFiles'   => 0,
				'workTotal'     => 0,
				'workCompleted' => 0,
			);

			if (!in_array($format, array('best', 'avif', 'webp', 'both'), true)) {
				$format = 'best';
			}

			$source_files = $this->get_attachment_source_files($attachment_id);
			$summary['sourceFiles'] = count($source_files);
			$work_multiplier = ('both' === $format) ? 2 : 1;
			$summary['workTotal'] = (int) ($summary['sourceFiles'] * $work_multiplier);

			foreach ($source_files as $source_file) {
				if ('best' === $format) {
					if ($only_missing && ($this->generated_variant_exists($source_file, 'avif') || $this->generated_variant_exists($source_file, 'webp'))) {
						$summary['workCompleted']++;
						$summary['skippedExisting']++;
						if ($this->generated_variant_exists($source_file, 'avif')) {
							$this->mark_existing_avif_variant_available($source_file);
						}
						$summary['success'] = true;
						continue;
					}

					$result = $this->generate_best_format($source_file);
					$summary['workCompleted']++;
					if ($result) {
						$summary['success'] = true;
						$summary['processed']++;
						$extension = strtolower((string) pathinfo($result, PATHINFO_EXTENSION));
						if ('avif' === $extension) {
							$summary['avif']++;
						} elseif ('webp' === $extension) {
							$summary['webp']++;
						}
					}

					continue;
				}

				$formats = ('both' === $format) ? array('avif', 'webp') : array($format);
				foreach ($formats as $single_format) {
					if ($only_missing && $this->generated_variant_exists($source_file, $single_format)) {
						$summary['workCompleted']++;
						$summary['skippedExisting']++;
						if ('avif' === $single_format) {
							$this->mark_existing_avif_variant_available($source_file);
						}
						$summary['success'] = true;
						continue;
					}

					$result = ('avif' === $single_format)
						? $this->to_avif($source_file)
						: $this->to_webp($source_file);
					$summary['workCompleted']++;

					if ($result) {
						$summary['success'] = true;
						$summary['processed']++;
						$summary[$single_format]++;
					}
				}
			}

			return $summary;
		}

		public function bulk_optimize_report($format = 'best', $only_missing = false, array $attachment_ids = array()) {
			$report = array(
				'attachments_total'     => 0,
				'attachments_converted' => 0,
				'avif'                  => 0,
				'webp'                  => 0,
			);

			if (!empty($attachment_ids)) {
				$ids = array_values(array_filter(array_map('intval', $attachment_ids)));
				$report['attachments_total'] = count($ids);

				foreach ($ids as $attachment_id) {
					$result = $this->generate_attachment_formats((int) $attachment_id, $format, $only_missing);
					if (!empty($result['success'])) {
						$report['attachments_converted']++;
						$report['avif'] += (int) $result['avif'];
						$report['webp'] += (int) $result['webp'];
					}
				}

				return $report;
			}

			$offset = 0;
			$limit  = $this->get_default_batch_size();

			do {
				$batch = $this->get_media_ids_batch($offset, $limit);
				$items = array_map('intval', (array) ($batch['items'] ?? array()));
				if (0 === $report['attachments_total']) {
					$report['attachments_total'] = (int) ($batch['total'] ?? 0);
				}

				foreach ($items as $attachment_id) {
					$result = $this->generate_attachment_formats((int) $attachment_id, $format, $only_missing);
					if (!empty($result['success'])) {
						$report['attachments_converted']++;
						$report['avif'] += (int) $result['avif'];
						$report['webp'] += (int) $result['webp'];
					}
				}

				$offset = (int) ($batch['nextOffset'] ?? ($offset + count($items)));
			} while (!empty($batch['hasMore']) && !empty($items));

			return $report;
		}

		/**
		 * Convert one attachment and all generated sizes to AVIF or WebP fallback.
		 *
		 * @param int $attachment_id Attachment ID.
		 * @return bool
		 */
		public function to_avif_by_id($attachment_id) {
			$result = $this->generate_attachment_formats($attachment_id, 'best', false);
			return !empty($result['success']);
		}

		/**
		 * Delete generated AVIF/WebP files for an attachment.
		 *
		 * @param int $attachment_id Attachment ID.
		 * @return void
		 */
		public function delete_avif_by_attachment_id($attachment_id) {
			$this->invalidate_media_work_summary_cache();
			$attachment_id = absint($attachment_id);

			if ($attachment_id <= 0) {
				return;
			}

			$this->dequeue_attachment_from_background_generation($attachment_id);

			$file = get_attached_file($attachment_id);

			if ($file) {
				$this->delete_generated_file_for_source($source_file = $file, 'avif');
				$this->delete_generated_file_for_source($source_file, 'webp');
			}

			$meta = wp_get_attachment_metadata($attachment_id);

			if (!empty($meta['sizes']) && is_array($meta['sizes']) && $file) {
				$base_dir = dirname($file);

				foreach ($meta['sizes'] as $size) {
					if (empty($size['file'])) {
						continue;
					}

					$size_file = trailingslashit($base_dir) . ltrim((string) $size['file'], '/');
					$this->delete_generated_file_for_source($size_file, 'avif');
					$this->delete_generated_file_for_source($size_file, 'webp');
				}
			}
		}

		/**
		 * Return support status info for dashboard/API.
		 *
		 * @return array
		 */
		public function get_support_status() {
			$imagick      = extension_loaded('imagick');
			$imagick_avif = $this->supports_imagick_avif();
			$imagick_webp = $this->supports_imagick_webp();
			$gd_avif      = $this->supports_gd_avif();
			$gd_webp      = $this->supports_gd_webp();
			$diag         = $this->get_media_diagnostic_state();
			$preferred    = $this->detect_preferred_image_editor_class();
			$last_error   = (string) ($diag['lastAvifEncodeError'] ?? '');
			$last_engine  = (string) ($diag['lastAvifEncodeEngine'] ?? '');
			$last_class   = (string) ($diag['lastImageEditorClass'] ?? '');

			if ('WP_Image_Editor_Imagick' === $preferred && $imagick_avif && !$gd_avif && '' !== $last_error && false !== stripos($last_engine, 'gd')) {
				$last_error = '';
				$last_engine = 'imagick';
				$last_class = 'WP_Image_Editor_Imagick';
			}

			return array(
				'imagick'      => $imagick,
				'imagick_avif' => $imagick_avif,
				'imagick_webp' => $imagick_webp,
				'gd_avif'      => $gd_avif,
				'gd_webp'      => $gd_webp,
				'preferred_editor' => $preferred,
				'last_avif_encode_error' => $last_error,
				'last_avif_encode_engine' => $last_engine,
				'last_avif_encode_file' => (string) ($diag['lastAvifEncodeFile'] ?? ''),
				'last_avif_encode_at' => (int) ($diag['lastAvifEncodeAt'] ?? 0),
				'last_image_editor_class' => $last_class,
				'supported'    => ($imagick_avif || $gd_avif || $imagick_webp || $gd_webp),
			);
		}

		/**
		 * Return generated media stats for the dashboard.
		 *
		 * @return array
		 */
		public function get_stats() {
			$avif_files = 0;
			$webp_files = 0;
			$bytes = 0;

			$scan = static function( $dir, $extension ) use ( &$bytes ) {
				$count = 0;
				if ( ! $dir || ! is_dir( $dir ) ) {
					return 0;
				}

				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
				);

				foreach ( $iterator as $item ) {
					if ( ! $item->isFile() ) {
						continue;
					}

					$bytes += (int) $item->getSize();
					if ( strtolower( $item->getExtension() ) === strtolower( $extension ) ) {
						$count++;
					}
				}

				return $count;
			};

			$avif_files = $scan( defined( 'UCWP_AVIF_DIR' ) ? UCWP_AVIF_DIR : '', 'avif' );
			$webp_files = $scan( defined( 'UCWP_WEBP_DIR' ) ? UCWP_WEBP_DIR : '', 'webp' );

			return array(
				'optimizedImages' => $avif_files + $webp_files,
				'avifFiles' => $avif_files,
				'webpFiles' => $webp_files,
				'mediaSizeBytes' => $bytes,
				'mediaSizeHuman' => function_exists( 'size_format' ) ? size_format( $bytes, 2 ) : (string) $bytes,
			);
		}

		/**
		 * Return all image attachment IDs supported for conversion.
		 *
		 * @return array
		 */
		public function get_all_media_ids() {
			$ids = array();
			$offset = 0;
			$limit = $this->get_default_batch_size();

			do {
				$batch = $this->get_media_ids_batch($offset, $limit);
				if (empty($batch['items'])) {
					break;
				}

				$ids = array_merge($ids, array_map('intval', (array) $batch['items']));
				$offset = (int) $batch['nextOffset'];
			} while (!empty($batch['hasMore']));

			return $ids;
		}

		public function get_media_ids_batch( $offset = 0, $limit = 100, $include_work_summary = true ) {
			$offset = max( 0, (int) $offset );
			$limit  = max( 1, min( 500, (int) $limit ) );

			$query = new WP_Query(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'posts_per_page'         => $limit,
					'offset'                 => $offset,
					'post_mime_type'         => array(
						'image/jpeg',
						'image/png',
						'image/webp',
					),
					'fields'                 => 'ids',
					'no_found_rows'          => false,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'suppress_filters'       => false,
				)
			);

			$items       = array_map( 'intval', (array) $query->posts );
			$total       = (int) $query->found_posts;
			$next_offset = min( $total, $offset + count( $items ) );

			$response = array(
				'items'      => $items,
				'total'      => $total,
				'offset'     => $offset,
				'limit'      => $limit,
				'nextOffset' => $next_offset,
				'hasMore'    => $next_offset < $total,
			);

			if ($include_work_summary) {
				$summary = $this->get_media_work_summary();
				$response['attachmentTotal'] = (int) ($summary['attachmentsTotal'] ?? $total);
				$response['workTotal'] = (int) ($summary['workTotal'] ?? $total);
			}

			return $response;
		}

		/**
		 * Bulk optimize all supported attachments.
		 *
		 * @return int Number of attachments that produced at least one next-gen file.
		 */
		public function bulk_optimize() {
			$report = $this->bulk_optimize_report('best', false);
			return (int) $report['attachments_converted'];
		}

		/**
		 * Replace image src with the best generated version supported by the client.
		 *
		 * @param array        $attr       Image attributes.
		 * @param WP_Post      $attachment Attachment object.
		 * @param string|array $size       Requested image size.
		 * @return array
		 */
		public function filter_attachment_image_attributes($attr, $attachment, $size) {
			if (!$this->is_media_optimization_enabled()) {
				return $attr;
			}

			if (empty($attr['src'])) {
				return $attr;
			}

			$replacement_url = $this->get_best_url_from_attachment_context($attachment, $size);

			if ($replacement_url) {
				$attr['src'] = $replacement_url;
			}

			return $attr;
		}

		/**
		 * Replace srcset entries with the best generated versions when available.
		 *
		 * @param array|false $sources       Srcset sources.
		 * @param array       $size_array    Requested size array.
		 * @param string      $image_src     Original image src.
		 * @param array       $image_meta    Image metadata.
		 * @param int         $attachment_id Attachment ID.
		 * @return array|false
		 */
		public function filter_attachment_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
			if (!$this->is_media_optimization_enabled()) {
				return $sources;
			}

			if (empty($sources) || !is_array($sources)) {
				return $sources;
			}

			foreach ($sources as $width => $source) {
				if (empty($source['url'])) {
					continue;
				}

				$replacement_url = $this->get_best_url_from_public_url($source['url']);

				if ($replacement_url) {
					$sources[$width]['url'] = $replacement_url;
				}
			}

			return $sources;
		}

		/**
		 * Start a final frontend HTML rewrite buffer for builders/themes that bypass
		 * the normal content and attachment filters.
		 *
		 * This buffer runs late so the page cache engine can store the rewritten HTML.
		 *
		 * @return void
		 */
		public function maybe_start_final_html_buffer() {
			if ($this->final_buffering) {
				return;
			}

			if (!$this->is_media_optimization_enabled()) {
				return;
			}

			if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
				return;
			}

			if ((defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || is_feed() || is_robots() || is_trackback()) {
				return;
			}

			if (!isset($_SERVER['REQUEST_METHOD'])) {
				return;
			}

			$method = strtoupper(ucwp_server_value('REQUEST_METHOD'));
			if (!in_array($method, array('GET', 'HEAD'), true)) {
				return;
			}

			ob_start(array($this, 'final_html_rewrite_callback'));
			$this->final_buffering = true;
		}

		/**
		 * Rewrite the final frontend HTML buffer.
		 *
		 * @param string $html Full HTML output.
		 * @return string
		 */
		public function final_html_rewrite_callback($html) {
			if (!is_string($html) || '' === $html) {
				return $html;
			}

			return $this->rewrite_html_image_urls($html);
		}

		/**
		 * Rewrite image URLs in rendered HTML for builders that bypass core image helpers.
		 *
		 * @param string $html Rendered HTML.
		 * @return string
		 */
		public function rewrite_html_image_urls($html) {
			if (!$this->is_media_optimization_enabled()) {
				return $html;
			}

			if (!is_string($html) || '' === $html) {
				return $html;
			}

			$uploads = wp_get_upload_dir();
			if (empty($uploads['baseurl']) || false === strpos($html, $uploads['baseurl'])) {
				return $html;
			}

			if (class_exists('WP_HTML_Tag_Processor')) {
				$html = $this->rewrite_html_image_urls_with_tag_processor($html);
			}

			$html = $this->rewrite_html_image_urls_with_regex($html);

			return $this->rewrite_html_upload_urls_globally($html);
		}

		/**
		 * Rewrite image URLs using the native WP HTML tag processor when available.
		 *
		 * @param string $html Rendered HTML.
		 * @return string
		 */
		private function rewrite_html_image_urls_with_tag_processor($html) {
			$processor = new WP_HTML_Tag_Processor($html);
			$single_url_attributes = array('src', 'data-src', 'data-lazy-src', 'href', 'data-href', 'data-lg-src', 'data-mfp-src');
			$srcset_attributes = array('srcset', 'data-srcset', 'data-lazy-srcset');
			$background_attributes = array('data-bg', 'data-background', 'data-bg-image', 'data-background-image');
			$meta_keys = array('og:image', 'twitter:image', 'twitter:image:src');

			while ($processor->next_tag()) {
				foreach ($single_url_attributes as $attribute) {
					$current = $processor->get_attribute($attribute);
					if (!is_string($current) || '' === $current) {
						continue;
					}

					$replacement = $this->get_best_url_from_public_url($current);
					if ($replacement && $replacement !== $current) {
						$processor->set_attribute($attribute, $replacement);
					}
				}

				foreach ($srcset_attributes as $attribute) {
					$current = $processor->get_attribute($attribute);
					if (!is_string($current) || '' === $current) {
						continue;
					}

					$rewritten = $this->rewrite_srcset_string($current);
					if ($rewritten !== $current) {
						$processor->set_attribute($attribute, $rewritten);
					}
				}

				foreach ($background_attributes as $attribute) {
					$current = $processor->get_attribute($attribute);
					if (!is_string($current) || '' === $current) {
						continue;
					}

					$replacement = $this->get_best_url_from_public_url($current);
					if ($replacement && $replacement !== $current) {
						$processor->set_attribute($attribute, $replacement);
					}
				}

				$style = $processor->get_attribute('style');
				if (is_string($style) && '' !== $style) {
					$rewritten_style = $this->rewrite_inline_style_urls($style);
					if ($rewritten_style !== $style) {
						$processor->set_attribute('style', $rewritten_style);
					}
				}

				if ('META' === strtoupper((string) $processor->get_tag())) {
					$property = strtolower(trim((string) $processor->get_attribute('property')));
					$name = strtolower(trim((string) $processor->get_attribute('name')));
					if (in_array($property, $meta_keys, true) || in_array($name, $meta_keys, true)) {
						$content = $processor->get_attribute('content');
						if (is_string($content) && '' !== $content) {
							$replacement = $this->get_best_url_from_public_url($content);
							if ($replacement && $replacement !== $content) {
								$processor->set_attribute('content', $replacement);
							}
						}
					}
				}
			}

			return $processor->get_updated_html();
		}

		/**
		 * Regex fallback for older WordPress installs without WP_HTML_Tag_Processor.
		 *
		 * @param string $html Rendered HTML.
		 * @return string
		 */
		private function rewrite_html_image_urls_with_regex($html) {
			$single_url_attributes = array('src', 'data-src', 'data-lazy-src', 'href', 'data-href', 'data-lg-src', 'data-mfp-src');
			foreach ($single_url_attributes as $attribute) {
				$pattern = "/(" . preg_quote($attribute, '/') . "=[\"'])([^\"']+)([\"'])/i";
				$html    = preg_replace_callback(
					$pattern,
					function ($matches) {
						$replacement = $this->get_best_url_from_public_url($matches[2]);
						return $matches[1] . ($replacement ? $replacement : $matches[2]) . $matches[3];
					},
					$html
				);
			}

			$srcset_attributes = array('srcset', 'data-srcset', 'data-lazy-srcset');
			foreach ($srcset_attributes as $attribute) {
				$pattern = "/(" . preg_quote($attribute, '/') . "=[\"'])([^\"']+)([\"'])/i";
				$html    = preg_replace_callback(
					$pattern,
					function ($matches) {
						return $matches[1] . $this->rewrite_srcset_string($matches[2]) . $matches[3];
					},
					$html
				);
			}

			$html = preg_replace_callback(
				"/(style=[\"'])(.*?)([\"'])/is",
				function ($matches) {
					$style = $this->rewrite_inline_style_urls($matches[2]);
					return $matches[1] . $style . $matches[3];
				},
				$html
			);

			$background_attributes = array('data-bg', 'data-background', 'data-bg-image', 'data-background-image');
			foreach ($background_attributes as $attribute) {
				$pattern = "/(" . preg_quote($attribute, '/') . "=[\"'])([^\"']+)([\"'])/i";
				$html    = preg_replace_callback(
					$pattern,
					function ($matches) {
						$replacement = $this->get_best_url_from_public_url($matches[2]);
						return $matches[1] . ($replacement ? $replacement : $matches[2]) . $matches[3];
					},
					$html
				);
			}

			$meta_patterns = array(
				"/(<meta\b[^>]*\b(?:property|name)=[\"'](?:og:image|twitter:image|twitter:image:src)[\"'][^>]*\bcontent=[\"'])([^\"']+)([\"'][^>]*>)/i",
				"/(<meta\b[^>]*\bcontent=[\"'])([^\"']+)([\"'][^>]*\b(?:property|name)=[\"'](?:og:image|twitter:image|twitter:image:src)[\"'][^>]*>)/i",
			);

			foreach ($meta_patterns as $pattern) {
				$html = preg_replace_callback(
					$pattern,
					function ($matches) {
						$replacement = $this->get_best_url_from_public_url($matches[2]);
						return $matches[1] . ($replacement ? $replacement : $matches[2]) . $matches[3];
					},
					$html
				);
			}

			return $html;
		}

		/**
		 * Rewrite URL values inside inline style attributes.
		 *
		 * @param string $style Inline style string.
		 * @return string
		 */
		private function rewrite_inline_style_urls($style) {
			$style = (string) $style;
			if ('' === $style) {
				return $style;
			}

			return preg_replace_callback(
				'/url\(([^)]+)\)/i',
				function ($matches) {
					$raw   = trim((string) $matches[1]);
					$quote = '';
					if (strlen($raw) >= 2) {
						$first = substr($raw, 0, 1);
						$last  = substr($raw, -1);
						if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
							$quote = $first;
							$raw   = substr($raw, 1, -1);
						}
					}

					$replacement = $this->get_best_url_from_public_url($raw);
					if (!$replacement) {
						return $matches[0];
					}

					return 'url(' . $quote . $replacement . $quote . ')';
				},
				$style
			);
		}

		/**
		 * Rewrite a srcset attribute string to best available formats.
		 *
		 * @param string $srcset Srcset string.
		 * @return string
		 */
		private function rewrite_srcset_string($srcset) {
			$srcset = (string) $srcset;
			if ('' === trim($srcset)) {
				return $srcset;
			}

			$parts = array_map('trim', explode(',', $srcset));
			foreach ($parts as $index => $part) {
				if ('' === $part) {
					continue;
				}

				$segments = preg_split('/\s+/', $part, 2);
				$url      = isset($segments[0]) ? $segments[0] : '';
				$descriptor = isset($segments[1]) ? $segments[1] : '';
				$replacement = $this->get_best_url_from_public_url($url);
				$parts[$index] = $replacement ? trim($replacement . ' ' . $descriptor) : $part;
			}

			return implode(', ', $parts);
		}

		/**
		 * Final safety-net pass: rewrite plain uploads URLs that may still remain in HTML.
		 *
		 * This intentionally scans only uploads URLs and leaves other public URLs untouched.
		 * It is used after the structured/tag/srcset passes so it can catch stubborn encoded
		 * or inline occurrences without depending on a specific tag shape.
		 *
		 * @param string $html Rendered HTML.
		 * @return string
		 */
		private function rewrite_html_upload_urls_globally($html) {
			$html = (string) $html;
			if ('' === $html) {
				return $html;
			}

			$uploads = wp_get_upload_dir();
			if (empty($uploads['baseurl'])) {
				return $html;
			}

			$baseurl = untrailingslashit($this->normalize_public_url($uploads['baseurl']));
			if ('' === $baseurl) {
				return $html;
			}

			$pattern = "/" . preg_quote($baseurl, "/") . "\/[^\"'\s<>()]+/u";
			$rewritten = preg_replace_callback(
				$pattern,
				function ($matches) {
					$current = isset($matches[0]) ? (string) $matches[0] : '';
					if ('' === $current) {
						return $current;
					}

					$replacement = $this->get_best_url_from_public_url($current);
					return $replacement ? $replacement : $current;
				},
				$html
			);

			return is_string($rewritten) ? $rewritten : $html;
		}

		/**
		 * Determine if AVIF can be served for this request.
		 *
		 * @return bool
		 */
		private function can_serve_avif() {
			if (!$this->is_media_optimization_enabled() || !$this->media_output_mode_allows('avif')) {
				return false;
			}

			return $this->browser_accepts_image_format('avif');
		}

		/**
		 * Determine if WebP can be served for this request.
		 *
		 * @return bool
		 */
		private function can_serve_webp() {
			if (!$this->is_media_optimization_enabled() || !$this->media_output_mode_allows('webp')) {
				return false;
			}

			return $this->browser_accepts_image_format('webp');
		}

		/**
		 * Determine if the current browser accepts a specific image format.
		 *
		 * This is intentionally separate from encoder availability. The frontend
		 * can safely serve already-generated files even if the current PHP runtime
		 * cannot generate new ones.
		 *
		 * @param string $format Image format, e.g. avif or webp.
		 * @return bool
		 */
		private function browser_accepts_image_format($format) {
			$format = strtolower((string) $format);
			if ('' === $format) {
				return false;
			}

			$accept = ucwp_server_value('HTTP_ACCEPT');
			if ('' === $accept) {
				return false;
			}

			return (false !== stripos($accept, 'image/' . $format));
		}

		/**
		 * Determine if next-gen media generation is enabled in plugin settings.
		 *
		 * @return bool
		 */
		private function is_media_optimization_enabled() {
			if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
				$settings = Ultra_Cache_WP::get_settings();
				return !empty($settings['media_optimization_enabled']);
			}

			$settings = get_option(defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY : 'ucwp_settings', array());
			return !empty($settings['mediaOptimizationEnabled']);
		}

		private function get_media_output_mode() {
			if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
				$settings = Ultra_Cache_WP::get_settings();
				$mode = isset($settings['media_output_mode']) ? strtolower(trim((string) $settings['media_output_mode'])) : 'auto';
				return in_array($mode, array('auto', 'avif', 'webp'), true) ? $mode : 'auto';
			}

			$settings = get_option(defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY : 'ucwp_settings', array());
			$mode = isset($settings['mediaOutputMode']) ? strtolower(trim((string) $settings['mediaOutputMode'])) : 'auto';
			return in_array($mode, array('auto', 'avif', 'webp'), true) ? $mode : 'auto';
		}

		private function is_generate_on_upload_enabled() {
			if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
				$settings = Ultra_Cache_WP::get_settings();
				if (array_key_exists('media_generate_on_upload', $settings)) {
					return !empty($settings['media_generate_on_upload']);
				}
			}

			$settings = get_option(defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY : 'ucwp_settings', array());
			if (array_key_exists('mediaGenerateOnUploadEnabled', $settings)) {
				return !empty($settings['mediaGenerateOnUploadEnabled']);
			}
			return true;
		}

		private function is_generate_on_demand_enabled() {
			if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
				$settings = Ultra_Cache_WP::get_settings();
				if (array_key_exists('media_generate_on_demand', $settings)) {
					return !empty($settings['media_generate_on_demand']);
				}
			}

			$settings = get_option(defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY : 'ucwp_settings', array());
			if (array_key_exists('mediaGenerateOnDemandEnabled', $settings)) {
				return !empty($settings['mediaGenerateOnDemandEnabled']);
			}
			return true;
		}

		private function media_output_mode_allows($format) {
			$format = strtolower((string) $format);
			$mode = $this->get_media_output_mode();
			if ('auto' === $mode) {
				return in_array($format, array('avif', 'webp'), true);
			}
			return $mode === $format;
		}

		/**
		 * Determine if any supported encoder exists.
		 *
		 * @return bool
		 */
		private function is_supported() {
			return ($this->supports_avif() || $this->supports_webp());
		}

		/**
		 * Determine if AVIF is supported.
		 *
		 * @return bool
		 */
		private function supports_avif() {
			return ($this->supports_imagick_avif() || $this->supports_gd_avif());
		}

		/**
		 * Determine if WebP is supported.
		 *
		 * @return bool
		 */
		private function supports_webp() {
			return ($this->supports_imagick_webp() || $this->supports_gd_webp());
		}

		/**
		 * Check Imagick AVIF support.
		 *
		 * @return bool
		 */
		private function supports_imagick_avif() {
			if (!extension_loaded('imagick')) {
				return false;
			}

			if (!method_exists('Imagick', 'queryFormats')) {
				return false;
			}

			try {
				$formats = \Imagick::queryFormats('AVIF');
				return is_array($formats) && in_array('AVIF', $formats, true);
			} catch (Exception $e) {
				return false;
			}
		}

		/**
		 * Check Imagick WebP support.
		 *
		 * @return bool
		 */
		private function supports_imagick_webp() {
			if (!extension_loaded('imagick')) {
				return false;
			}

			if (!method_exists('Imagick', 'queryFormats')) {
				return false;
			}

			try {
				$formats = \Imagick::queryFormats('WEBP');
				return is_array($formats) && in_array('WEBP', $formats, true);
			} catch (Exception $e) {
				return false;
			}
		}

		/**
		 * Check GD AVIF support with a real write test.
		 *
		 * @return bool
		 */
		private function supports_gd_avif() {
			static $gd_avif_supported = null;

			if (null !== $gd_avif_supported) {
				return $gd_avif_supported;
			}

			if (!function_exists('imageavif') || !function_exists('imagecreatetruecolor')) {
				$gd_avif_supported = false;
				return false;
			}

			$tmp = $this->create_temp_file('ucwp-avif-test');
			if (!$tmp) {
				$gd_avif_supported = false;
				return false;
			}

			$test_file = $tmp . '.avif';
			ucwp_safe_unlink($tmp);

			$image = imagecreatetruecolor(2, 2);
			if (!$image) {
				$gd_avif_supported = false;
				return false;
			}

			if (function_exists('imagepalettetotruecolor')) {
				imagepalettetotruecolor($image);
			}

			if (function_exists('imagealphablending')) {
				imagealphablending($image, true);
			}

			if (function_exists('imagesavealpha')) {
				imagesavealpha($image, true);
			}

			$result = false;

			try {
				$result = @imageavif($image, $test_file, 52);
			} catch (\Throwable $e) {
				$result = false;
			}


			imagedestroy($image);

			$gd_avif_supported = (
				$result &&
				file_exists($test_file) &&
				(int) ucwp_safe_filesize($test_file, 'media_converter_format_support_test') > 0
			);

			if (file_exists($test_file)) {
				ucwp_safe_unlink($test_file);
			}

			return $gd_avif_supported;
		}

		/**
		 * Check GD WebP support with a real write test.
		 *
		 * @return bool
		 */
		private function supports_gd_webp() {
			static $gd_webp_supported = null;

			if (null !== $gd_webp_supported) {
				return $gd_webp_supported;
			}

			if (!function_exists('imagewebp') || !function_exists('imagecreatetruecolor')) {
				$gd_webp_supported = false;
				return false;
			}

			$tmp = $this->create_temp_file('ucwp-webp-test');
			if (!$tmp) {
				$gd_webp_supported = false;
				return false;
			}

			$test_file = $tmp . '.webp';
			ucwp_safe_unlink($tmp);

			$image = imagecreatetruecolor(2, 2);
			if (!$image) {
				$gd_webp_supported = false;
				return false;
			}

			if (function_exists('imagepalettetotruecolor')) {
				imagepalettetotruecolor($image);
			}

			if (function_exists('imagealphablending')) {
				imagealphablending($image, true);
			}

			if (function_exists('imagesavealpha')) {
				imagesavealpha($image, true);
			}

			$result = false;

			try {
				$result = imagewebp($image, $test_file, 82);
			} catch (\Throwable $e) {
				$result = false;
			}


			imagedestroy($image);

			$gd_webp_supported = (
				$result &&
				file_exists($test_file) &&
				(int) ucwp_safe_filesize($test_file, 'media_converter_format_support_test') > 0
			);

			if (file_exists($test_file)) {
				ucwp_safe_unlink($test_file);
			}

			return $gd_webp_supported;
		}

		/**
		 * Create a temporary file path without assuming wp-admin file helpers are loaded.
		 *
		 * @param string $prefix Filename prefix.
		 * @return string|false
		 */
		private function create_temp_file($prefix) {
			$prefix = (string) $prefix;

			if (function_exists('wp_tempnam')) {
				$tmp = wp_tempnam($prefix);
				return (is_string($tmp) && '' !== $tmp) ? $tmp : false;
			}

			$dir = function_exists('get_temp_dir') ? (string) get_temp_dir() : '';
			if ('' === $dir && function_exists('sys_get_temp_dir')) {
				$dir = (string) sys_get_temp_dir();
			}

			if ('' === $dir || !is_dir($dir) || !ucwp_path_is_writable($dir)) {
				return false;
			}

			$sanitized_prefix = preg_replace('/[^A-Za-z0-9_-]/', '', $prefix);
			if (!is_string($sanitized_prefix) || '' === $sanitized_prefix) {
				$sanitized_prefix = 'ucwp';
			}

			$tmp = ucwp_safe_tempnam($dir, substr($sanitized_prefix, 0, 32), 'media_converter_tempnam');
			return (is_string($tmp) && '' !== $tmp) ? $tmp : false;
		}

		/**
		 * Check whether a source file type is eligible.
		 *
		 * @param string $source_file Source file path.
		 * @return bool
		 */
		private function is_allowed_source_file($source_file) {
			return (bool) preg_match('/\.(jpe?g|png|webp)$/i', $source_file);
		}

		/**
		 * Check whether a source file type is eligible for WebP fallback generation.
		 *
		 * @param string $source_file Source file path.
		 * @return bool
		 */
		private function is_webp_fallback_source_file($source_file) {
			return (bool) preg_match('/\.(jpe?g|png)$/i', $source_file);
		}

		/**
		 * Convert image with the WordPress image editor when possible.
		 *
		 * @param string $source_file Source file path.
		 * @param string $dest_file Destination image path.
		 * @param string $mime_type Destination mime type.
		 * @param int    $quality Compression quality.
		 * @return bool
		 */
		private function convert_with_wp_image_editor($source_file, $dest_file, $mime_type, $quality) {
			if (!function_exists('wp_get_image_editor')) {
				return false;
			}

			$editor = wp_get_image_editor($source_file);
			if (is_wp_error($editor) || !is_object($editor)) {
				if ('image/avif' === $mime_type) {
					$this->update_media_diagnostic_state(array(
						'lastImageEditorClass' => '',
						'lastAvifEncodeEngine' => 'wp_image_editor',
						'lastAvifEncodeError' => is_wp_error($editor) ? $editor->get_error_message() : 'Image editor unavailable',
						'lastAvifEncodeFile' => (string) $source_file,
						'lastAvifEncodeAt' => time(),
					));
				}
				return false;
			}

			$editor_class = get_class($editor);
			if ('image/avif' === $mime_type) {
				$this->update_media_diagnostic_state(array('lastImageEditorClass' => $editor_class));
			}

			if (method_exists($editor, 'set_quality')) {
				$editor->set_quality((int) $quality);
			}

			$php_error = '';
			set_error_handler(static function($severity, $message) use (&$php_error) {
				$php_error = (string) $message;
				return true;
			});
			try {
				$saved = $editor->save($dest_file, $mime_type);
			} finally {
				restore_error_handler();
			}
			if (is_wp_error($saved)) {
				if ('image/avif' === $mime_type) {
					$this->update_media_diagnostic_state(array(
						'lastAvifEncodeEngine' => 'wp_image_editor:' . $editor_class,
						'lastAvifEncodeError' => $saved->get_error_message() ?: $php_error,
						'lastAvifEncodeFile' => (string) $source_file,
						'lastAvifEncodeAt' => time(),
					));
				}
				return false;
			}

			$ok = !empty($saved['path']) && file_exists($saved['path']) && (int) ucwp_safe_filesize($saved['path'], 'media_converter_image_editor_save') > 0;
			if ('image/avif' === $mime_type && !$ok) {
				$this->update_media_diagnostic_state(array(
					'lastAvifEncodeEngine' => 'wp_image_editor:' . $editor_class,
					'lastAvifEncodeError' => $php_error ?: 'Image editor save did not produce a valid AVIF file',
					'lastAvifEncodeFile' => (string) $source_file,
					'lastAvifEncodeAt' => time(),
				));
			}

			if ('image/avif' === $mime_type && $ok) {
				$this->update_media_diagnostic_state(array(
					'lastImageEditorClass' => $editor_class,
					'lastAvifEncodeEngine' => 'wp_image_editor:' . $editor_class,
					'lastAvifEncodeError' => '',
					'lastAvifEncodeFile' => (string) $source_file,
					'lastAvifEncodeAt' => time(),
				));
			}

			return $ok;
		}

		/**
		 * Convert image with Imagick.
		 *
		 * @param string $source_file Source file path.
		 * @param string $dest_file   Destination image path.
		 * @param string $format      Destination format.
		 * @param int    $quality     Compression quality.
		 * @return bool
		 */
		private function convert_with_imagick($source_file, $dest_file, $format, $quality) {
			try {
				$image = new Imagick($source_file);
				$image->setImageFormat($format);
				$image->setImageCompressionQuality((int) $quality);

				if (method_exists($image, 'stripImage')) {
					$image->stripImage();
				}

				$result = $image->writeImage($dest_file);
				$image->clear();
				$image->destroy();

				if ('avif' === $format && $result) {
					$this->update_media_diagnostic_state(array(
						'lastImageEditorClass' => 'Imagick',
						'lastAvifEncodeEngine' => 'imagick',
						'lastAvifEncodeError' => '',
						'lastAvifEncodeFile' => (string) $source_file,
						'lastAvifEncodeAt' => time(),
					));
				}

				return (bool) $result;
			} catch (Exception $e) {
				ucwp_debug_log('imagick conversion failed', array('format' => strtoupper($format), 'error' => $e->getMessage()));
				if ('avif' === $format) {
					$this->update_media_diagnostic_state(array(
						'lastAvifEncodeEngine' => 'imagick',
						'lastAvifEncodeError' => $e->getMessage(),
						'lastAvifEncodeFile' => (string) $source_file,
						'lastAvifEncodeAt' => time(),
					));
				}
				return false;
			}
		}

		/**
		 * Convert image with GD.
		 *
		 * @param string $source_file Source file path.
		 * @param string $dest_file   Destination image path.
		 * @param string $format      Destination format.
		 * @param int    $quality     Compression quality.
		 * @return bool
		 */
		private function convert_with_gd($source_file, $dest_file, $format, $quality) {
			if ('avif' === $format && !$this->supports_gd_avif()) {
				return false;
			}

			if ('webp' === $format && !$this->supports_gd_webp()) {
				return false;
			}

			$type = function_exists('exif_imagetype') ? @exif_imagetype($source_file) : false;

			if (!$type) {
				return false;
			}

			$image = null;

			switch ($type) {
				case IMAGETYPE_JPEG:
					$image = function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($source_file) : null;
					break;

				case IMAGETYPE_PNG:
					$image = function_exists('imagecreatefrompng') ? @imagecreatefrompng($source_file) : null;
					break;

				case IMAGETYPE_WEBP:
					$image = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source_file) : null;
					break;
			}

			if (!$image) {
				return false;
			}

			if (function_exists('imagepalettetotruecolor')) {
				imagepalettetotruecolor($image);
			}

			if (function_exists('imagealphablending')) {
				imagealphablending($image, true);
			}

			if (function_exists('imagesavealpha')) {
				imagesavealpha($image, true);
			}

			$gd_error = '';

			$result = false;

			try {
				if ('avif' === $format) {
					$result = @imageavif($image, $dest_file, (int) $quality);
				} elseif ('webp' === $format) {
					$result = @imagewebp($image, $dest_file, (int) $quality);
				}
			} catch (\Throwable $e) {
				$gd_error = $e->getMessage();
				$result   = false;
			}


			imagedestroy($image);

			$ok = (
				$result &&
				file_exists($dest_file) &&
				(int) ucwp_safe_filesize($dest_file, 'media_converter_gd_save') > 0
			);

			if (!$ok) {
				if (file_exists($dest_file)) {
					ucwp_safe_unlink($dest_file);
				}

				if ($gd_error) {
					ucwp_debug_log('gd conversion failed', array('format' => strtoupper($format), 'error' => $gd_error));
				}

				if ('avif' === $format) {
					$this->update_media_diagnostic_state(array(
						'lastImageEditorClass' => 'GD',
						'lastAvifEncodeEngine' => 'gd',
						'lastAvifEncodeError' => $gd_error ?: 'GD AVIF conversion did not produce a valid file',
						'lastAvifEncodeFile' => (string) $source_file,
						'lastAvifEncodeAt' => time(),
					));
				}

				return false;
			}

			if ('avif' === $format) {
				$this->update_media_diagnostic_state(array(
					'lastImageEditorClass' => 'GD',
					'lastAvifEncodeEngine' => 'gd',
					'lastAvifEncodeError' => '',
					'lastAvifEncodeFile' => (string) $source_file,
					'lastAvifEncodeAt' => time(),
				));
			}

			return true;
		}

		/**
		 * Delete one generated file created from a source image.
		 *
		 * @param string $source_file Source file path.
		 * @param string $format      Target format.
		 * @return void
		 */
		private function delete_generated_file_for_source($source_file, $format) {
			$dest_file = ('webp' === $format)
				? $this->get_webp_path_from_source($source_file)
				: $this->get_avif_path_from_source($source_file);

			if ($dest_file && file_exists($dest_file)) {
				ucwp_safe_unlink($dest_file);
			}
		}

		/**
		 * Build AVIF absolute path from source absolute path.
		 *
		 * @param string $source_file Source file path.
		 * @return string|false
		 */
		private function get_avif_path_from_source($source_file) {
			$relative_path = $this->get_uploads_relative_path_from_source($source_file);
			if (!$relative_path) {
				return false;
			}

			$relative_path = preg_replace('/\.(jpe?g|png|webp)$/i', '.avif', $relative_path);

			if (!$relative_path) {
				return false;
			}

			return trailingslashit(UCWP_AVIF_DIR) . $relative_path;
		}

		/**
		 * Build WebP absolute path from source absolute path.
		 *
		 * @param string $source_file Source file path.
		 * @return string|false
		 */
		private function get_webp_path_from_source($source_file) {
			$relative_path = $this->get_uploads_relative_path_from_source($source_file);
			if (!$relative_path) {
				return false;
			}

			if (!$this->is_webp_fallback_source_file($source_file)) {
				return false;
			}

			$relative_path = preg_replace('/\.(jpe?g|png)$/i', '.webp', $relative_path);

			if (!$relative_path) {
				return false;
			}

			return trailingslashit(UCWP_WEBP_DIR) . $relative_path;
		}

		/**
		 * Build AVIF public URL from source absolute path.
		 *
		 * @param string $source_file Source file path.
		 * @return string|false
		 */
		private function get_avif_url_from_source($source_file) {
			$avif_path = $this->get_avif_path_from_source($source_file);

			if (!$avif_path) {
				return false;
			}

			if (!file_exists($avif_path)) {
				$generated = $this->ensure_generated_variant($source_file, 'avif');
				if (!$generated || !file_exists($avif_path)) {
					return false;
				}
			}

			$relative_path = ltrim(str_replace(trailingslashit(UCWP_AVIF_DIR), '', $avif_path), '/\\');

			if ('' === $relative_path) {
				return false;
			}

			return trailingslashit(UCWP_AVIF_URL) . str_replace(DIRECTORY_SEPARATOR, '/', $relative_path);
		}

		/**
		 * Build WebP public URL from source absolute path.
		 *
		 * @param string $source_file Source file path.
		 * @return string|false
		 */
		private function get_webp_url_from_source($source_file) {
			$webp_path = $this->get_webp_path_from_source($source_file);

			if (!$webp_path) {
				return false;
			}

			if (!file_exists($webp_path)) {
				$generated = $this->ensure_generated_variant($source_file, 'webp');
				if (!$generated || !file_exists($webp_path)) {
					return false;
				}
			}

			$relative_path = ltrim(str_replace(trailingslashit(UCWP_WEBP_DIR), '', $webp_path), '/\\');

			if ('' === $relative_path) {
				return false;
			}

			return trailingslashit(UCWP_WEBP_URL) . str_replace(DIRECTORY_SEPARATOR, '/', $relative_path);
		}

		/**
		 * Build AVIF URL from attachment/size context.
		 *
		 * @param WP_Post      $attachment Attachment object.
		 * @param string|array $size       Requested size.
		 * @return string|false
		 */
		private function get_avif_url_from_attachment_context($attachment, $size) {
			if (empty($attachment->ID)) {
				return false;
			}

			$image = wp_get_attachment_image_src($attachment->ID, $size);

			if (empty($image[0])) {
				return false;
			}

			return $this->get_avif_url_from_public_url($image[0]);
		}

		/**
		 * Build WebP URL from attachment/size context.
		 *
		 * @param WP_Post      $attachment Attachment object.
		 * @param string|array $size       Requested size.
		 * @return string|false
		 */
		private function get_webp_url_from_attachment_context($attachment, $size) {
			if (empty($attachment->ID)) {
				return false;
			}

			$image = wp_get_attachment_image_src($attachment->ID, $size);

			if (empty($image[0])) {
				return false;
			}

			return $this->get_webp_url_from_public_url($image[0]);
		}

		/**
		 * Convert a public uploads URL to its AVIF URL if the file exists.
		 *
		 * @param string $public_url Public image URL.
		 * @return string|false
		 */
		private function get_avif_url_from_public_url($public_url) {
			$relative_path = $this->get_uploads_relative_path_from_public_url($public_url);
			if (!$relative_path) {
				return false;
			}

			$uploads = wp_get_upload_dir();
			$uploads_root = realpath($uploads['basedir']);
			if (!is_string($uploads_root) || '' === $uploads_root) {
				return false;
			}

			$source_path = trailingslashit($uploads_root) . $relative_path;

			return $this->get_avif_url_from_source($source_path);
		}

		/**
		 * Convert a public uploads URL to its WebP URL if the file exists.
		 *
		 * @param string $public_url Public image URL.
		 * @return string|false
		 */
		private function get_webp_url_from_public_url($public_url) {
			$relative_path = $this->get_uploads_relative_path_from_public_url($public_url);
			if (!$relative_path) {
				return false;
			}

			$uploads = wp_get_upload_dir();
			$uploads_root = realpath($uploads['basedir']);
			if (!is_string($uploads_root) || '' === $uploads_root) {
				return false;
			}

			$source_path = trailingslashit($uploads_root) . $relative_path;

			return $this->get_webp_url_from_source($source_path);
		}

		/**
		 * Return the current request start time for frontend on-demand generation limits.
		 *
		 * @return float
		 */
		private function get_on_demand_request_started_at() {
			if (null !== $this->on_demand_request_started_at) {
				return $this->on_demand_request_started_at;
			}

			if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
				$this->on_demand_request_started_at = (float) $_SERVER['REQUEST_TIME_FLOAT'];
			} else {
				$this->on_demand_request_started_at = microtime(true);
			}

			return $this->on_demand_request_started_at;
		}

		/**
		 * Maximum number of frontend on-demand conversions allowed per request.
		 *
		 * @return int
		 */
		private function get_on_demand_max_conversions_per_request() {
			$default = defined('UCWP_MEDIA_ON_DEMAND_MAX_PER_REQUEST') ? (int) UCWP_MEDIA_ON_DEMAND_MAX_PER_REQUEST : 3;
			$limit = (int) apply_filters('ucwp_media_on_demand_max_conversions_per_request', $default);
			return max(0, min(25, $limit));
		}

		/**
		 * Maximum request-time budget for frontend on-demand conversion starts.
		 *
		 * @return float
		 */
		private function get_on_demand_timeout_seconds() {
			$default = defined('UCWP_MEDIA_ON_DEMAND_TIMEOUT_SECONDS') ? (float) UCWP_MEDIA_ON_DEMAND_TIMEOUT_SECONDS : 3.0;
			$timeout = (float) apply_filters('ucwp_media_on_demand_timeout_seconds', $default);
			return max(0.1, min(30.0, $timeout));
		}

		/**
		 * Stale lock TTL for frontend on-demand conversion lock files.
		 *
		 * @return int
		 */
		private function get_on_demand_lock_ttl_seconds() {
			$default = defined('UCWP_MEDIA_ON_DEMAND_LOCK_TTL') ? (int) UCWP_MEDIA_ON_DEMAND_LOCK_TTL : 120;
			$ttl = (int) apply_filters('ucwp_media_on_demand_lock_ttl_seconds', $default);
			return max(10, min(DAY_IN_SECONDS, $ttl));
		}

		/**
		 * Determine whether the current request is allowed to start frontend conversions.
		 *
		 * @return bool
		 */
		private function is_frontend_on_demand_request() {
			if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
				return false;
			}

			if ((defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || is_feed() || is_robots() || is_trackback()) {
				return false;
			}

			$method = strtoupper(ucwp_server_value('REQUEST_METHOD'));
			return in_array($method, array('GET', 'HEAD'), true);
		}

		/**
		 * Check request-level safeguards before starting a frontend on-demand conversion.
		 *
		 * @return bool
		 */
		private function can_start_on_demand_conversion() {
			if (!$this->is_frontend_on_demand_request()) {
				return false;
			}

			$max = $this->get_on_demand_max_conversions_per_request();
			if ($max <= 0 || $this->on_demand_conversions_started >= $max) {
				return false;
			}

			$elapsed = microtime(true) - $this->get_on_demand_request_started_at();
			return $elapsed < $this->get_on_demand_timeout_seconds();
		}

		/**
		 * Directory used for frontend on-demand image conversion locks.
		 *
		 * @return string|false
		 */
		private function get_on_demand_lock_dir() {
			if (!defined('UCWP_CACHE_DIR') || '' === (string) UCWP_CACHE_DIR) {
				return false;
			}

			$dir = trailingslashit(UCWP_CACHE_DIR) . 'media-locks/';
			if (!file_exists($dir)) {
				wp_mkdir_p($dir);
			}

			if (!is_dir($dir) || !is_writable($dir)) {
				return false;
			}

			$index = trailingslashit($dir) . 'index.php';
			if (!file_exists($index)) {
				ucwp_safe_file_put_contents($index, "<?php\n// Silence is golden.\n", 0, 'media_on_demand_lock_index');
			}

			return trailingslashit($dir);
		}

		/**
		 * Acquire a per-image/per-format frontend conversion lock.
		 *
		 * @param string $source_file Source image path.
		 * @param string $format      Target format.
		 * @return string|false Lock file path on success.
		 */
		private function acquire_on_demand_image_lock($source_file, $format) {
			$lock_dir = $this->get_on_demand_lock_dir();
			if (!$lock_dir) {
				return false;
			}

			$source_real = realpath($source_file);
			if (!is_string($source_real) || '' === $source_real) {
				return false;
			}

			$key = hash('sha256', strtolower((string) $format) . '|' . $this->normalize_local_path_for_compare($source_real));
			$lock_file = $lock_dir . 'image-' . $key . '.lock';
			$ttl = $this->get_on_demand_lock_ttl_seconds();

			if (file_exists($lock_file)) {
				$mtime = (int) @filemtime($lock_file);
				if ($mtime > 0 && (time() - $mtime) > $ttl) {
					ucwp_safe_unlink($lock_file);
				}
			}

			$handle = @fopen($lock_file, 'x');
			if (!is_resource($handle)) {
				return false;
			}

			@fwrite($handle, json_encode(array(
				'pid' => function_exists('getmypid') ? (int) getmypid() : 0,
				'time' => time(),
				'format' => strtolower((string) $format),
				'source' => $source_real,
			)) . "\n");
			@fclose($handle);

			return $lock_file;
		}

		/**
		 * Release a frontend on-demand image conversion lock.
		 *
		 * @param string|false $lock_file Lock file path.
		 * @return void
		 */
		private function release_on_demand_image_lock($lock_file) {
			if (!is_string($lock_file) || '' === $lock_file || !file_exists($lock_file)) {
				return;
			}

			$lock_dir = $this->get_on_demand_lock_dir();
			if (!$lock_dir || !$this->path_is_within_root($lock_file, $lock_dir)) {
				return;
			}

			ucwp_safe_unlink($lock_file);
		}

		/**
		 * Ensure a generated next-gen variant exists for a source image.
		 *
		 * @param string $source_file Source file path.
		 * @param string $format      Target format, avif or webp.
		 * @return bool
		 */
		private function ensure_generated_variant($source_file, $format) {
			$source_file = (string) $source_file;
			$format      = strtolower((string) $format);

			if (!$this->is_media_optimization_enabled() || !$this->is_generate_on_demand_enabled() || !$this->media_output_mode_allows($format)) {
				return false;
			}

			if ('' === $source_file || !in_array($format, array('avif', 'webp'), true) || !file_exists($source_file) || !is_readable($source_file)) {
				return false;
			}

			if (!$this->is_allowed_source_file($source_file)) {
				return false;
			}

			$existing = ('avif' === $format)
				? $this->get_avif_path_from_source($source_file)
				: $this->get_webp_path_from_source($source_file);

			if (!$existing) {
				return false;
			}

			if (file_exists($existing)) {
				return true;
			}

			if (!$this->can_start_on_demand_conversion()) {
				return false;
			}

			$lock_file = $this->acquire_on_demand_image_lock($source_file, $format);
			if (!$lock_file) {
				return false;
			}

			try {
				if (file_exists($existing)) {
					return true;
				}

				if (!$this->can_start_on_demand_conversion()) {
					return false;
				}

				$this->on_demand_conversions_started++;

				return ('avif' === $format)
					? (bool) $this->to_avif($source_file)
					: (bool) $this->to_webp($source_file);
			} finally {
				$this->release_on_demand_image_lock($lock_file);
			}
		}

		private function normalize_local_path_for_compare($path) {
			return rtrim(str_replace('\\', '/', (string) $path), '/');
		}

		private function path_is_within_root($path, $root) {
			$path = $this->normalize_local_path_for_compare($path);
			$root = $this->normalize_local_path_for_compare($root);

			if ('' === $path || '' === $root) {
				return false;
			}

			return $path === $root || 0 === strpos($path, $root . '/');
		}

		private function get_uploads_relative_path_from_source($source_file) {
			$uploads = wp_get_upload_dir();

			if (empty($uploads['basedir'])) {
				return false;
			}

			$uploads_root = realpath($uploads['basedir']);
			$source_real  = realpath($source_file);

			if (!is_string($uploads_root) || '' === $uploads_root || !is_string($source_real) || '' === $source_real) {
				return false;
			}

			if (!$this->path_is_within_root($source_real, $uploads_root)) {
				return false;
			}

			$relative_path = ltrim(substr($this->normalize_local_path_for_compare($source_real), strlen($this->normalize_local_path_for_compare($uploads_root))), '/');
			return '' !== $relative_path ? $relative_path : false;
		}

		private function get_uploads_relative_path_from_public_url($public_url) {
			$public_url = $this->normalize_public_url($public_url);
			if ('' === $public_url) {
				return false;
			}

			$uploads = wp_get_upload_dir();
			if (empty($uploads['baseurl']) || empty($uploads['basedir'])) {
				return false;
			}

			$baseurl = untrailingslashit($this->normalize_public_url($uploads['baseurl']));
			if ('' === $baseurl) {
				return false;
			}

			$public_parts = wp_parse_url($public_url);
			$base_parts   = wp_parse_url($baseurl);
			if (!is_array($public_parts) || empty($public_parts['path']) || !is_array($base_parts) || empty($base_parts['path'])) {
				return false;
			}

			$public_path = rawurldecode((string) $public_parts['path']);
			$base_path   = rawurldecode((string) $base_parts['path']);
			$public_path = '/' . ltrim(str_replace('\\', '/', $public_path), '/');
			$base_path   = '/' . ltrim(str_replace('\\', '/', $base_path), '/');
			$base_path   = rtrim($base_path, '/');
			if ('' === $public_path || '' === $base_path) {
				return false;
			}

			$public_host = isset($public_parts['host']) ? strtolower((string) $public_parts['host']) : '';
			$base_host   = isset($base_parts['host']) ? strtolower((string) $base_parts['host']) : '';
			$home_host   = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
			$site_host   = strtolower((string) wp_parse_url(site_url('/'), PHP_URL_HOST));
			$allowed_hosts = array_filter(array_unique(array($base_host, $home_host, $site_host)));
			if ('' !== $public_host && !empty($allowed_hosts)) {
				$normalized_public_host = preg_replace('/^www\./', '', $public_host);
				$normalized_allowed = array_map(
					static function ($host) {
						return preg_replace('/^www\./', '', (string) $host);
					},
					$allowed_hosts
				);
				if (!in_array($normalized_public_host, $normalized_allowed, true)) {
					return false;
				}
			}

			if ($public_path !== $base_path && 0 !== strpos($public_path, $base_path . '/')) {
				return false;
			}

			$relative_path = ltrim(substr($public_path, strlen($base_path)), '/');
			if ('' === $relative_path) {
				return false;
			}

			foreach (explode('/', str_replace('\\', '/', $relative_path)) as $segment) {
				if ('' === $segment || '.' === $segment || '..' === $segment) {
					return false;
				}
			}

			return $relative_path;
		}

		/**
		 * Normalize a public uploads URL before mapping it to a local file.
		 *
		 * @param string $public_url Public uploads URL.
		 * @return string
		 */
		private function normalize_public_url($public_url) {
			$public_url = trim((string) $public_url);
			if ('' === $public_url) {
				return '';
			}

			$public_url = preg_replace('/[#?].*$/', '', $public_url);
			if (!is_string($public_url) || '' === $public_url) {
				return '';
			}

			$parts = wp_parse_url($public_url);
			if (!is_array($parts) || empty($parts['path'])) {
				return $public_url;
			}

			$decoded_path = rawurldecode((string) $parts['path']);
			if ('' === $decoded_path) {
				return $public_url;
			}

			$normalized = '';
			if (!empty($parts['scheme'])) {
				$normalized .= $parts['scheme'] . '://';
			} elseif (0 === strpos($public_url, '//')) {
				$normalized .= '//';
			}
			if (!empty($parts['user'])) {
				$normalized .= $parts['user'];
				if (isset($parts['pass'])) {
					$normalized .= ':' . $parts['pass'];
				}
				$normalized .= '@';
			}
			if (!empty($parts['host'])) {
				$normalized .= $parts['host'];
			}
			if (!empty($parts['port'])) {
				$normalized .= ':' . $parts['port'];
			}
			$normalized .= $decoded_path;

			return $normalized ?: $public_url;
		}

		/**
		 * Resolve the best replacement URL for the current request.
		 *
		 * @param WP_Post      $attachment Attachment object.
		 * @param string|array $size       Requested size.
		 * @return string|false
		 */
		private function get_best_url_from_attachment_context($attachment, $size) {
			if ($this->can_serve_avif()) {
				$avif_url = $this->get_avif_url_from_attachment_context($attachment, $size);
				if ($avif_url) {
					return $avif_url;
				}
			}

			if ($this->can_serve_webp()) {
				$webp_url = $this->get_webp_url_from_attachment_context($attachment, $size);
				if ($webp_url) {
					return $webp_url;
				}
			}

			return false;
		}

		/**
		 * Resolve the best replacement URL for the current request.
		 *
		 * @param string $public_url Public image URL.
		 * @return string|false
		 */
		private function get_best_url_from_public_url($public_url) {
			if ($this->can_serve_avif()) {
				$avif_url = $this->get_avif_url_from_public_url($public_url);
				if ($avif_url) {
					return $avif_url;
				}
			}

			if ($this->can_serve_webp()) {
				$webp_url = $this->get_webp_url_from_public_url($public_url);
				if ($webp_url) {
					return $webp_url;
				}
			}

			return false;
		}
	}
}

