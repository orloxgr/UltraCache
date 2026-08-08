<?php
/**
 * UltraCache Media Library conversion test workflow.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Conversion_Test_Trait
{
		private function format_media_conversion_test_size($bytes) {
			$bytes = max(0, (int) $bytes);
			return $bytes > 0 ? size_format($bytes, 1) : '0 B';
		}

		private function get_media_conversion_test_file_size($file) {
			$file = is_string($file) ? wp_normalize_path($file) : '';
			if ('' === $file || !$this->optimized_storage_path_exists($file, true)) {
				return 0;
			}

			return (int) ultracache_safe_filesize($file, 'media_library_conversion_test_size');
		}

		private function get_media_library_conversion_test_attachment_ids_by_mime(array $mime_types, $limit, array $exclude_ids = array()) {
			$limit = max(0, absint($limit));
			if ($limit <= 0 || empty($mime_types)) {
				return array();
			}

			$exclude_ids = array_values(array_unique(array_filter(array_map('absint', $exclude_ids))));
			$ids = get_posts(array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => array_values($mime_types),
				'posts_per_page'         => max(50, $limit * 10),
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Bounded admin-only test-image selection query.
				'post__not_in'           => $exclude_ids,
			));

			$results = array();
			foreach ((array) $ids as $attachment_id) {
				$attachment_id = absint($attachment_id);
				if ($attachment_id <= 0 || in_array($attachment_id, $exclude_ids, true) || in_array($attachment_id, $results, true)) {
					continue;
				}

				$file = get_attached_file($attachment_id);
				if (!is_string($file) || '' === $file || !$this->optimized_storage_readable_source_exists($file)) {
					continue;
				}

				$results[] = $attachment_id;
				if (count($results) >= $limit) {
					break;
				}
			}

			return $results;
		}

		private function normalize_media_library_conversion_test_sample_ids(array $ids) {
			$ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
			if (empty($ids)) {
				return array();
			}

			$avif_ids = array();
			$png_ids = array();
			$jpeg_ids = array();
			foreach ($ids as $attachment_id) {
				if ($attachment_id <= 0) {
					continue;
				}

				$mime = sanitize_mime_type((string) get_post_mime_type($attachment_id));
				if (!in_array($mime, array('image/avif', 'image/png', 'image/jpeg'), true)) {
					continue;
				}

				$file = get_attached_file($attachment_id);
				if (!is_string($file) || '' === $file || !$this->optimized_storage_readable_source_exists($file)) {
					continue;
				}

				if ('image/avif' === $mime) {
					if (count($avif_ids) < 1) {
						$avif_ids[] = $attachment_id;
					}
					continue;
				}

				if ('image/png' === $mime) {
					if (count($png_ids) < 3) {
						$png_ids[] = $attachment_id;
					}
					continue;
				}

				$jpeg_ids[] = $attachment_id;
			}

			return array_slice(array_merge($avif_ids, $png_ids, $jpeg_ids), 0, 10);
		}

		private function get_media_library_conversion_test_stored_report() {
			$report = get_option(self::MEDIA_LIBRARY_CONVERSION_TEST_OPTION, array());
			return is_array($report) ? $report : array();
		}

		private function delete_media_library_conversion_test_report() {
			delete_option(self::MEDIA_LIBRARY_CONVERSION_TEST_OPTION);
		}

		private function store_media_library_conversion_test_report(array $report) {
			return update_option(self::MEDIA_LIBRARY_CONVERSION_TEST_OPTION, $report, false);
		}

		private function get_media_library_conversion_test_sample_ids_from_report() {
			$report = $this->get_media_library_conversion_test_stored_report();
			if (!is_array($report) || empty($report['items']) || !is_array($report['items'])) {
				return array();
			}

			$ids = array();
			foreach ($report['items'] as $item) {
				if (!is_array($item) || empty($item['id'])) {
					continue;
				}

				$ids[] = absint($item['id']);
			}

			return $this->normalize_media_library_conversion_test_sample_ids($ids);
		}

		private function build_media_library_conversion_test_attachment_ids() {
			$avif_ids = $this->get_media_library_conversion_test_attachment_ids_by_mime(array('image/avif'), 1, array());
			$png_ids = $this->get_media_library_conversion_test_attachment_ids_by_mime(array('image/png'), 3, $avif_ids);
			$ids = array_merge($avif_ids, $png_ids);

			$jpeg_limit = max(0, 10 - count($ids));
			if ($jpeg_limit > 0) {
				$ids = array_merge(
					$ids,
					$this->get_media_library_conversion_test_attachment_ids_by_mime(array('image/jpeg'), $jpeg_limit, $ids)
				);
			}

			return array_slice(array_values(array_unique(array_filter(array_map('absint', $ids)))), 0, 10);
		}

		private function get_media_library_conversion_test_attachment_ids() {
			$stored_ids = get_option(self::MEDIA_LIBRARY_CONVERSION_TEST_SAMPLE_OPTION, array());
			if (is_array($stored_ids)) {
				$stored_ids = $this->normalize_media_library_conversion_test_sample_ids($stored_ids);
				if (!empty($stored_ids)) {
					return $stored_ids;
				}
			}

			$report_ids = $this->get_media_library_conversion_test_sample_ids_from_report();
			if (!empty($report_ids)) {
				update_option(self::MEDIA_LIBRARY_CONVERSION_TEST_SAMPLE_OPTION, $report_ids, false);
				return $report_ids;
			}

			$ids = $this->build_media_library_conversion_test_attachment_ids();
			if (!empty($ids)) {
				update_option(self::MEDIA_LIBRARY_CONVERSION_TEST_SAMPLE_OPTION, $ids, false);
			}

			return $ids;
		}

		private function get_media_conversion_test_directory() {
			$base_dir = defined('ULTRACACHE_OPTIMIZED_IMAGES_DIR') ? (string) ULTRACACHE_OPTIMIZED_IMAGES_DIR : (function_exists('ultracache_optimized_images_storage_dir') ? ultracache_optimized_images_storage_dir() : '');
			$base_dir = is_string($base_dir) ? wp_normalize_path($base_dir) : '';
			if ('' === $base_dir) {
				return '';
			}

			return trailingslashit($base_dir) . 'test/';
		}

		private function get_media_conversion_test_base_url() {
			$base_url = defined('ULTRACACHE_OPTIMIZED_IMAGES_URL') ? (string) ULTRACACHE_OPTIMIZED_IMAGES_URL : (function_exists('ultracache_optimized_images_storage_url') ? ultracache_optimized_images_storage_url() : '');
			$base_url = is_string($base_url) ? esc_url_raw($base_url) : '';
			if ('' === $base_url) {
				return '';
			}

			return trailingslashit($base_url) . 'test/';
		}

		private function get_media_conversion_test_random_letters() {
			$letters = 'abcdefghijklmnopqrstuvwxyz';
			$value = '';
			for ($i = 0; $i < 5; $i++) {
				$value .= substr($letters, wp_rand(0, strlen($letters) - 1), 1);
			}

			return $value;
		}

		private function get_media_conversion_test_run_key() {
			return sanitize_key($this->get_media_conversion_test_random_letters() . '-' . current_time('Ymd-His'));
		}

		private function clear_media_conversion_test_directory() {
			$dir = $this->get_media_conversion_test_directory();
			if ('' === $dir) {
				return false;
			}

			$normalized = wp_normalize_path($dir);
			if (false === strpos($normalized, '/ultracache/images/test/')) {
				return false;
			}

			if (function_exists('ultracache_safe_rmdir') && !ultracache_safe_rmdir($dir, 'media_library_conversion_test_clear')) {
				return false;
			}

			return $this->optimized_storage_ensure_directory($dir);
		}

		private function get_media_conversion_test_file_url($filename, $run_key) {
			$filename = sanitize_file_name((string) $filename);
			$base_url = $this->get_media_conversion_test_base_url();
			if ('' === $filename || '' === $base_url) {
				return '';
			}

			return esc_url_raw(add_query_arg('uc-test', sanitize_key($run_key), trailingslashit($base_url) . $filename));
		}

		private function get_media_conversion_test_copy_extension($source_file) {
			$extension = strtolower((string) pathinfo((string) $source_file, PATHINFO_EXTENSION));
			if ('jpeg' === $extension) {
				$extension = 'jpg';
			}

			return in_array($extension, array('jpg', 'png', 'avif'), true) ? $extension : '';
		}

		private function copy_media_conversion_test_source($source_file, $attachment_id, $run_key) {
			$source_file = is_string($source_file) ? wp_normalize_path($source_file) : '';
			$attachment_id = absint($attachment_id);
			$run_key = sanitize_key($run_key);
			$extension = $this->get_media_conversion_test_copy_extension($source_file);
			$dir = $this->get_media_conversion_test_directory();

			if ('' === $source_file || $attachment_id <= 0 || '' === $run_key || '' === $extension || '' === $dir) {
				return array();
			}
			if (!$this->optimized_storage_readable_source_exists($source_file) || !$this->optimized_storage_ensure_directory($dir)) {
				return array();
			}

			$filename = sanitize_file_name($run_key . '-' . $attachment_id . '-original.' . $extension);
			$destination = trailingslashit($dir) . $filename;
			$filesystem = $this->optimized_storage_filesystem();
			if (!$filesystem || !method_exists($filesystem, 'copy') || !$filesystem->copy($source_file, $destination, true, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644)) {
				return array();
			}

			$this->optimized_storage_harden_upload_permissions($destination, 'file');
			$this->optimized_storage_forget_path($destination);
			if (!$this->optimized_storage_path_exists($destination, true)) {
				return array();
			}

			$size = $this->get_media_conversion_test_file_size($destination);
			return array(
				'path'      => $destination,
				'filename'  => $filename,
				'url'       => $this->get_media_conversion_test_file_url($filename, $run_key),
				'size'      => $size,
				'sizeHuman' => $this->format_media_conversion_test_size($size),
			);
		}

		private function get_media_conversion_test_destination($attachment_id, $run_key, $format) {
			$attachment_id = absint($attachment_id);
			$run_key = sanitize_key($run_key);
			$format = strtolower((string) $format);
			$dir = $this->get_media_conversion_test_directory();
			if ($attachment_id <= 0 || '' === $run_key || '' === $dir || !in_array($format, array('avif', 'webp'), true)) {
				return array();
			}

			$filename = sanitize_file_name($run_key . '-' . $attachment_id . '-' . $format . '.' . $format);
			return array(
				'path'     => trailingslashit($dir) . $filename,
				'filename' => $filename,
				'url'      => $this->get_media_conversion_test_file_url($filename, $run_key),
			);
		}

		private function convert_media_conversion_test_variant_directly($source_file, $dest_file, $format, $quality) {
			$format = strtolower((string) $format);
			$quality = max(1, min(100, absint($quality)));
			$attempts = array();

			if ('webp' === $format) {
				$source_is_avif = $this->source_file_matches_target_format($source_file, 'avif');
				if ($source_is_avif ? $this->supports_imagick_avif_to_webp() : $this->supports_imagick_webp()) {
					$attempts[] = array('encoder' => 'imagick', 'callback' => 'imagick');
				}
				if ($source_is_avif ? $this->supports_gd_avif_to_webp() : $this->supports_gd_webp()) {
					$attempts[] = array('encoder' => 'gd', 'callback' => 'gd');
				}
				if (!$source_is_avif) {
					$attempts[] = array('encoder' => 'wordpress-image-editor', 'callback' => 'wp-image-editor');
				}
			} elseif ('avif' === $format) {
				if ($this->supports_imagick_avif()) {
					$attempts[] = array('encoder' => 'imagick', 'callback' => 'imagick');
				}
				if ($this->supports_gd_avif()) {
					$attempts[] = array('encoder' => 'gd', 'callback' => 'gd');
				}
			}

			foreach ($attempts as $attempt) {
				$encoder = isset($attempt['encoder']) ? sanitize_key((string) $attempt['encoder']) : '';
				$callback = isset($attempt['callback']) ? sanitize_key((string) $attempt['callback']) : '';
				$success = false;

				if ('imagick' === $callback) {
					$success = $this->convert_with_imagick($source_file, $dest_file, $format, $quality);
				} elseif ('gd' === $callback) {
					$success = $this->convert_with_gd($source_file, $dest_file, $format, $quality);
				} elseif ('wp-image-editor' === $callback && 'webp' === $format) {
					$success = $this->convert_webp_with_wp_image_editor($source_file, $dest_file, $quality);
				}

				$this->optimized_storage_forget_path($dest_file);
				if ($success && $this->optimized_storage_path_exists($dest_file, true) && $this->is_valid_generated_media_file($dest_file, $format, 'media_library_conversion_test_direct_dest_verify')) {
					return array(
						'success' => true,
						'encoder' => $encoder,
					);
				}

				if ($this->optimized_storage_path_exists($dest_file, true)) {
					ultracache_safe_unlink($dest_file, 'media_library_conversion_test_failed_direct_variant');
					$this->optimized_storage_forget_path($dest_file);
				}
			}

			return array(
				'success' => false,
				'encoder' => '',
			);
		}

		private function build_media_conversion_test_format_result($source_file, $attachment_id, $run_key, $format, $supported, $quality) {
			$format = strtolower((string) $format);
			$quality = max(1, min(100, absint($quality)));
			if ($this->source_file_matches_target_format($source_file, $format)) {
				$size = $this->get_media_conversion_test_file_size($source_file);
				return array(
					'supported' => true,
					'status'    => 'source',
					'label'     => __('Source format', 'ultracache'),
					'quality'   => $quality,
					'encoder'   => '',
					'size'      => $size,
					'sizeHuman' => $this->format_media_conversion_test_size($size),
					'url'       => '',
				);
			}

			$semantic_skip_reason = $this->get_media_source_conversion_skip_reason($source_file, $format);
			if ('' !== $semantic_skip_reason) {
				return array(
					'supported' => false,
					'status'    => 'not_supported',
					'label'     => __('Preserved animated image', 'ultracache'),
					'quality'   => $quality,
					'encoder'   => '',
					'size'      => 0,
					'sizeHuman' => __('Preserved animated image', 'ultracache'),
					'url'       => '',
					'skipReason'=> $semantic_skip_reason,
				);
			}

			if (!$supported) {
				return array(
					'supported' => false,
					'status'    => 'not_supported',
					'label'     => __('Not supported', 'ultracache'),
					'quality'   => $quality,
					'encoder'   => '',
					'size'      => 0,
					'sizeHuman' => __('Not supported', 'ultracache'),
					'url'       => '',
				);
			}

			$destination = $this->get_media_conversion_test_destination($attachment_id, $run_key, $format);
			$dest_file = isset($destination['path']) ? (string) $destination['path'] : '';
			if ('' === $dest_file || !$this->optimized_storage_ensure_directory(dirname($dest_file))) {
				return array(
					'supported' => true,
					'status'    => 'failed',
					'label'     => __('Failed', 'ultracache'),
					'quality'   => $quality,
					'encoder'   => '',
					'size'      => 0,
					'sizeHuman' => __('Failed', 'ultracache'),
					'url'       => '',
				);
			}

			$this->reset_last_media_conversion_failure((string) $source_file, $format);
			$this->reset_media_diagnostic_state();
			$success = false;

			$conversion = $this->convert_media_conversion_test_variant_directly($source_file, $dest_file, $format, $quality);
			$success = !empty($conversion['success']);
			$encoder = isset($conversion['encoder']) ? sanitize_key((string) $conversion['encoder']) : '';

			$this->optimized_storage_forget_path($dest_file);
			if (!$success || !$this->optimized_storage_path_exists($dest_file, true) || !$this->is_valid_generated_media_file($dest_file, $format, 'media_library_conversion_test_dest_verify')) {
				if ($this->optimized_storage_path_exists($dest_file, true)) {
					ultracache_safe_unlink($dest_file, 'media_library_conversion_test_failed_variant');
					$this->optimized_storage_forget_path($dest_file);
				}

				return array(
					'supported' => true,
					'status'    => 'failed',
					'label'     => __('Failed', 'ultracache'),
					'quality'   => $quality,
					'encoder'   => '',
					'size'      => 0,
					'sizeHuman' => __('Failed', 'ultracache'),
					'url'       => '',
				);
			}

			$size = $this->get_media_conversion_test_file_size($dest_file);
			return array(
				'supported' => true,
				'status'    => 'generated',
				'label'     => __('Generated', 'ultracache'),
				'quality'   => $quality,
				'encoder'   => $encoder,
				'size'      => $size,
				'sizeHuman' => $this->format_media_conversion_test_size($size),
				'url'       => isset($destination['url']) ? esc_url_raw((string) $destination['url']) : '',
			);
		}

		public function get_media_library_conversion_test_report() {
			$report = $this->get_media_library_conversion_test_stored_report();
			if (is_array($report) && !empty($report)) {
				$report['hasReport'] = true;
				$report['success'] = true;
				return $report;
			}

			return array(
				'success'   => true,
				'hasReport' => false,
				'message'   => __('No image conversion test has been run yet.', 'ultracache'),
				'items'     => array(),
			);
		}

		public function run_media_library_conversion_test() {
			$quality_profile = $this->get_media_quality_profile();
			$quality_values = $this->get_media_encoder_quality_values($quality_profile);
			$quality_source = 'database';

			$attachment_ids = $this->get_media_library_conversion_test_attachment_ids();
			$this->delete_media_library_conversion_test_report();
			if (!$this->clear_media_conversion_test_directory()) {
				return array(
					'success' => false,
					'message' => __('The Media Library conversion test directory could not be prepared.', 'ultracache'),
					'items'   => array(),
				);
			}

			if (empty($attachment_ids)) {
				return array(
					'success' => false,
					'message' => __('No readable AVIF, JPG, or PNG images were found in the Media Library.', 'ultracache'),
					'items'   => array(),
				);
			}

			$run_key = $this->get_media_conversion_test_run_key();
			$webp_supported = $this->supports_webp();
			$avif_supported = $this->supports_avif();
			$items = array();

			foreach ($attachment_ids as $attachment_id) {
				$attachment_id = absint($attachment_id);
				$source_file = get_attached_file($attachment_id);
				if (!is_string($source_file) || '' === $source_file || !$this->optimized_storage_readable_source_exists($source_file)) {
					continue;
				}

				$original = $this->copy_media_conversion_test_source($source_file, $attachment_id, $run_key);
				if (empty($original['path']) || empty($original['url'])) {
					continue;
				}

				$test_source = (string) $original['path'];
				$webp = $this->build_media_conversion_test_format_result(
					$test_source,
					$attachment_id,
					$run_key,
					'webp',
					$webp_supported && ($this->source_file_matches_target_format($test_source, 'webp') || $this->is_source_file_supported_for_format($test_source, 'webp')),
					$quality_values['webp']
				);
				$avif = $this->build_media_conversion_test_format_result(
					$test_source,
					$attachment_id,
					$run_key,
					'avif',
					$avif_supported && ($this->source_file_matches_target_format($test_source, 'avif') || $this->is_source_file_supported_for_format($test_source, 'avif')),
					$quality_values['avif']
				);

				$items[] = array(
					'id'                => $attachment_id,
					'title'             => sanitize_text_field(get_the_title($attachment_id)),
					'filename'          => sanitize_file_name(wp_basename($source_file)),
					'mime'              => sanitize_mime_type((string) get_post_mime_type($attachment_id)),
					'originalSize'      => isset($original['size']) ? absint($original['size']) : 0,
					'originalSizeHuman' => isset($original['sizeHuman']) ? sanitize_text_field((string) $original['sizeHuman']) : '0 B',
					'originalUrl'       => isset($original['url']) ? esc_url_raw((string) $original['url']) : '',
					'previewUrl'        => isset($original['url']) ? esc_url_raw((string) $original['url']) : '',
					'thumbnailUrl'      => isset($original['url']) ? esc_url_raw((string) $original['url']) : '',
					'original'          => array(
						'supported' => true,
						'status'    => 'source',
						'label'     => __('Original', 'ultracache'),
						'size'      => isset($original['size']) ? absint($original['size']) : 0,
						'sizeHuman' => isset($original['sizeHuman']) ? sanitize_text_field((string) $original['sizeHuman']) : '0 B',
						'url'       => isset($original['url']) ? esc_url_raw((string) $original['url']) : '',
					),
					'webp'              => $webp,
					'avif'              => $avif,
				);
			}

			if (empty($items)) {
				return array(
					'success' => false,
					'message' => __('No readable AVIF, JPG, or PNG images were found in the Media Library.', 'ultracache'),
					'items'   => array(),
				);
			}

			$report = array(
				'success'        => true,
				'hasReport'      => true,
				'createdAt'      => current_time('mysql'),
				'runKey'         => $run_key,
				'testDirectory'  => $this->get_media_conversion_test_base_url(),
				'total'          => count($items),
				'qualityProfile' => $quality_profile,
				'qualitySource'  => $quality_source,
				'qualityValues'  => $quality_values,
				'support'        => array(
					'webp' => $webp_supported,
					'avif' => $avif_supported,
				),
				'items'          => $items,
			);

			$this->store_media_library_conversion_test_report($report);
			return $report;
		}
}
