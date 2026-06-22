<?php
/**
 * Ultra Cache Media Diagnostics Trait for UltraCache media converter.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Diagnostics_Trait
{

		private function get_default_batch_size() {
			return 250;
		}

		private function invalidate_media_work_summary_cache() {
			delete_transient(self::MEDIA_WORK_SUMMARY_TRANSIENT);
			delete_transient(self::MEDIA_STORAGE_STATS_TRANSIENT);
			foreach (array('best', 'avif', 'webp', 'both') as $format) {
				delete_transient(self::MEDIA_STORAGE_HEALTH_TRANSIENT . '_' . $format);
			}
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
				ultracache_require_wordpress_admin_include('image.php', 'wp_get_image_editor');
			}

			if ($this->supports_imagick_avif() || $this->supports_imagick_webp()) {
				return 'WP_Image_Editor_Imagick';
			}

			if ($this->supports_gd_avif() || $this->supports_gd_webp()) {
				return 'WP_Image_Editor_GD';
			}

			$editors = apply_filters(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter name.
				'wp_image_editors', array('WP_Image_Editor_Imagick', 'WP_Image_Editor_GD'));
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

		public function get_support_status() {
			$cache_key = 'ultracache_media_support_status_v4';
			$cached = get_transient($cache_key);
			if (is_array($cached) && array_key_exists('supported', $cached)) {
				$cached['cached'] = true;
				return $cached;
			}

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

			$status = array(
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
				'cached'       => false,
			);

			set_transient($cache_key, $status, 10 * MINUTE_IN_SECONDS);
			return $status;
		}

		private function scan_media_storage_stats($max_files = 8000, $time_budget = 1.5) {
			$bytes = 0;
			$scan = static function($dir, $extension) use (&$bytes, $max_files, $time_budget) {
				$count = 0;
				$scanned = 0;
				$truncated = false;
				$timed_out = false;
				$deadline = microtime(true) + max(0.1, min(5.0, (float) $time_budget));
				if (!$dir || !is_dir($dir) || !is_readable($dir)) {
					return array('files' => 0, 'bytes' => 0, 'scannedFiles' => 0, 'truncated' => false, 'timedOut' => false, 'error' => '');
				}

				try {
					$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
					foreach ($iterator as $item) {
						if (microtime(true) >= $deadline) {
							$truncated = true;
							$timed_out = true;
							break;
						}
						if (!$item->isFile()) {
							continue;
						}
						$scanned++;
						$size = max(0, (int) $item->getSize());
						$bytes += $size;
						if (strtolower($item->getExtension()) === strtolower($extension)) {
							$count++;
						}
						if ($scanned >= $max_files) {
							$truncated = true;
							break;
						}
					}
				} catch (Exception $e) {
					return array('files' => $count, 'bytes' => $bytes, 'scannedFiles' => $scanned, 'truncated' => $truncated, 'timedOut' => $timed_out, 'error' => (string) $e->getMessage());
				}

				return array('files' => $count, 'bytes' => $bytes, 'scannedFiles' => $scanned, 'truncated' => $truncated, 'timedOut' => $timed_out, 'error' => '');
			};

			$avif = $scan(defined('ULTRACACHE_AVIF_DIR') ? ULTRACACHE_AVIF_DIR : '', 'avif');
			$webp = $scan(defined('ULTRACACHE_WEBP_DIR') ? ULTRACACHE_WEBP_DIR : '', 'webp');
			$avif_files = (int) ($avif['files'] ?? 0);
			$webp_files = (int) ($webp['files'] ?? 0);
			return array(
				'optimizedImages' => $avif_files + $webp_files,
				'avifFiles' => $avif_files,
				'webpFiles' => $webp_files,
				'mediaSizeBytes' => $bytes,
				'mediaSizeHuman' => function_exists('size_format') ? size_format($bytes, 2) : (string) $bytes,
				'mediaStatsCached' => false,
				'mediaStatsScanSkipped' => false,
				'mediaStatsScannedAt' => time(),
				'mediaStatsTruncated' => !empty($avif['truncated']) || !empty($webp['truncated']),
				'mediaStatsTimedOut' => !empty($avif['timedOut']) || !empty($webp['timedOut']),
				'avifScan' => $avif,
				'webpScan' => $webp,
			);
		}

		public function refresh_media_storage_stats() {
			$stats = $this->scan_media_storage_stats();
			set_transient(self::MEDIA_STORAGE_STATS_TRANSIENT, $stats, 10 * MINUTE_IN_SECONDS);
			return $stats;
		}

		public function get_stats($force_refresh = false) {
			if ($force_refresh) {
				return $this->refresh_media_storage_stats();
			}

			$cached = get_transient(self::MEDIA_STORAGE_STATS_TRANSIENT);
			if (is_array($cached) && isset($cached['optimizedImages'])) {
				$cached['mediaStatsCached'] = true;
				$cached['mediaStatsScanSkipped'] = false;
				return $cached;
			}

			return array(
				'optimizedImages' => 0,
				'avifFiles' => 0,
				'webpFiles' => 0,
				'mediaSizeBytes' => 0,
				'mediaSizeHuman' => function_exists('size_format') ? size_format(0, 2) : '0',
				'mediaStatsCached' => false,
				'mediaStatsScanSkipped' => true,
				'mediaStatsScannedAt' => 0,
				'mediaStatsMessage' => 'Media storage stats are passive. Use Refresh Storage Stats to run the capped filesystem scan.',
			);
		}

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

		public function bulk_optimize() {
			$report = $this->bulk_optimize_report('best', false);
			return (int) $report['attachments_converted'];
		}
}
