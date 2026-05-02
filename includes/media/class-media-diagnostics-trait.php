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
