<?php
/**
 * Ultra Cache Media Conversion Trait for UltraCache media converter.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Conversion_Trait
{

		public function to_avif($source_file) {
			$source_file = (string) $source_file;

			if (!$this->optimized_storage_readable_source_exists($source_file)) {
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

			if (!$this->optimized_storage_ensure_directory($dest_dir)) {
				return false;
			}

			$success = false;
			$this->reset_media_diagnostic_state();

			// AVIF always prefers Imagick. GD is used only when its real alpha
			// encode/decode probe confirms that transparency survives intact.
			if ($this->supports_imagick_avif()) {
				$success = $this->convert_with_imagick($source_file, $dest_file, 'avif', 60);
			}

			if (!$success && $this->supports_gd_avif()) {
				$success = $this->convert_with_gd($source_file, $dest_file, 'avif', 60);
			}

			if (!$success) {
				if ($this->optimized_storage_path_exists($dest_file, true)) {
					ultracache_safe_unlink($dest_file);
					$this->optimized_storage_forget_path($dest_file);
				}
				return false;
			}

			if (!$this->optimized_storage_path_exists($dest_file, true) || !$this->is_valid_generated_media_file($dest_file, 'avif', 'media_converter_dest_verify')) {
				if ($this->optimized_storage_path_exists($dest_file, true)) {
					ultracache_safe_unlink($dest_file);
					$this->optimized_storage_forget_path($dest_file);
				}
				return false;
			}

			$this->optimized_storage_forget_path($dest_file);
			return $dest_file;
		}

		public function to_webp($source_file) {
			$source_file = (string) $source_file;

			if (!$this->optimized_storage_readable_source_exists($source_file)) {
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

			if (!$this->optimized_storage_ensure_directory($dest_dir)) {
				return false;
			}

			$success = false;

			$success = $this->convert_webp_with_wp_image_editor($source_file, $dest_file, 82);

			if (!$success && $this->supports_imagick_webp()) {
				$success = $this->convert_with_imagick($source_file, $dest_file, 'webp', 82);
			}

			if (!$success && $this->supports_gd_webp()) {
				$success = $this->convert_with_gd($source_file, $dest_file, 'webp', 82);
			}

			if (!$success) {
				if ($this->optimized_storage_path_exists($dest_file, true)) {
					ultracache_safe_unlink($dest_file);
					$this->optimized_storage_forget_path($dest_file);
				}
				return false;
			}

			if (!$this->optimized_storage_path_exists($dest_file, true) || !$this->is_valid_generated_media_file($dest_file, 'webp', 'media_converter_dest_verify')) {
				if ($this->optimized_storage_path_exists($dest_file, true)) {
					ultracache_safe_unlink($dest_file);
					$this->optimized_storage_forget_path($dest_file);
				}
				return false;
			}

			$this->optimized_storage_forget_path($dest_file);
			return $dest_file;
		}

		private function generate_best_format($source_file) {
			$mode = $this->get_media_output_mode();
			if ('webp' === $mode) {
				return $this->to_webp($source_file);
			}

			$avif = $this->to_avif($source_file);
			if ($avif) {
				return $avif;
			}

			// If neither AVIF engine can produce a verified alpha-safe file,
			// continue with WebP instead of leaving media unoptimized.
			return $this->to_webp($source_file);
		}

		private function get_attachment_source_files($attachment_id) {
			$attachment_id = absint($attachment_id);

			if ($attachment_id <= 0) {
				return array();
			}

			$file = get_attached_file($attachment_id);
			if (!$file || !is_string($file) || !$this->optimized_storage_readable_source_exists($file)) {
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
					if ($this->optimized_storage_readable_source_exists($size_file)) {
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
				return $path && $this->optimized_storage_path_exists($path);
			}

			if ('webp' === $format) {
				$path = $this->get_webp_path_from_source($source_file);
				return $path && $this->optimized_storage_path_exists($path);
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

		public function to_avif_by_id($attachment_id) {
			$result = $this->generate_attachment_formats($attachment_id, 'best', false);
			return !empty($result['success']);
		}

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

		private function is_supported() {
			return ($this->supports_avif() || $this->supports_webp());
		}

		private function supports_avif() {
			return ($this->supports_imagick_avif() || $this->supports_gd_avif());
		}

		private function supports_webp() {
			return ($this->supports_imagick_webp() || $this->supports_gd_webp());
		}

		private function supports_imagick_avif() {
			static $imagick_avif_supported = null;

			if (null !== $imagick_avif_supported) {
				return $imagick_avif_supported;
			}

			$cache_key = 'ultracache_imagick_avif_alpha_probe_v1';
			$cached = get_transient($cache_key);
			if (is_array($cached) && array_key_exists('supported', $cached)) {
				$imagick_avif_supported = !empty($cached['supported']);
				return $imagick_avif_supported;
			}

			if (!extension_loaded('imagick') || !class_exists('Imagick') || !class_exists('ImagickPixel')) {
				$imagick_avif_supported = false;
				set_transient($cache_key, array('supported' => false, 'error' => 'Imagick is unavailable'), DAY_IN_SECONDS);
				return false;
			}

			if (!method_exists('Imagick', 'queryFormats')) {
				$imagick_avif_supported = false;
				set_transient($cache_key, array('supported' => false, 'error' => 'Imagick AVIF format detection is unavailable'), DAY_IN_SECONDS);
				return false;
			}

			try {
				$formats = \Imagick::queryFormats('AVIF');
			} catch (\Throwable $e) {
				$formats = array();
			}

			if (!is_array($formats) || !in_array('AVIF', $formats, true)) {
				$imagick_avif_supported = false;
				set_transient($cache_key, array('supported' => false, 'error' => 'Imagick AVIF codec is unavailable'), DAY_IN_SECONDS);
				return false;
			}

			$tmp = $this->create_temp_file('ultracache-imagick-avif-alpha-test');
			if (!$tmp) {
				$imagick_avif_supported = false;
				set_transient($cache_key, array('supported' => false, 'error' => 'Unable to create Imagick AVIF probe file'), HOUR_IN_SECONDS);
				return false;
			}

			$test_file = $tmp . '.avif';
			ultracache_safe_unlink($tmp);
			$probe_error = '';
			$image = null;
			$decoded = null;

			try {
				$image = new \Imagick();
				$image->newImage(3, 1, new \ImagickPixel('transparent'), 'png');
				if (defined('Imagick::ALPHACHANNEL_ACTIVATE') && method_exists($image, 'setImageAlphaChannel')) {
					$image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
				}

				$iterator = $image->getPixelIterator();
				foreach ($iterator as $row) {
					if (isset($row[0], $row[1], $row[2])) {
						$row[0]->setColor('red');
						$row[0]->setColorValue(\Imagick::COLOR_ALPHA, 0.0);
						$row[1]->setColor('red');
						$row[1]->setColorValue(\Imagick::COLOR_ALPHA, 0.5);
						$row[2]->setColor('red');
						$row[2]->setColorValue(\Imagick::COLOR_ALPHA, 1.0);
					}
					$iterator->syncIterator();
					break;
				}

				$image->setImageFormat('avif');
				$image->setImageCompressionQuality(60);
				if (method_exists($image, 'stripImage')) {
					$image->stripImage();
				}
				$written = $image->writeImage($test_file);

				if ($written && $this->is_valid_generated_media_file($test_file, 'avif', 'media_converter_imagick_avif_alpha_probe')) {
					$decoded = new \Imagick($test_file);
					$transparent_alpha = (float) $decoded->getImagePixelColor(0, 0)->getColorValue(\Imagick::COLOR_ALPHA);
					$partial_alpha = (float) $decoded->getImagePixelColor(1, 0)->getColorValue(\Imagick::COLOR_ALPHA);
					$opaque_alpha = (float) $decoded->getImagePixelColor(2, 0)->getColorValue(\Imagick::COLOR_ALPHA);
					$imagick_avif_supported = (
						$transparent_alpha <= 0.15 &&
						$partial_alpha >= 0.20 && $partial_alpha <= 0.80 &&
						$opaque_alpha >= 0.85
					);
				} else {
					$imagick_avif_supported = false;
				}
			} catch (\Throwable $e) {
				$probe_error = $e->getMessage();
				$imagick_avif_supported = false;
			} finally {
				if ($decoded instanceof \Imagick) {
					$decoded->clear();
					$decoded->destroy();
				}
				if ($image instanceof \Imagick) {
					$image->clear();
					$image->destroy();
				}
			}

			if ($this->optimized_storage_path_exists($test_file, true)) {
				ultracache_safe_unlink($test_file);
				$this->optimized_storage_forget_path($test_file);
			}

			set_transient($cache_key, array(
				'supported' => (bool) $imagick_avif_supported,
				'error' => $imagick_avif_supported ? '' : ($probe_error ?: 'Imagick AVIF alpha probe failed'),
			), DAY_IN_SECONDS);

			return $imagick_avif_supported;
		}

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

		private function supports_gd_avif() {
			static $gd_avif_supported = null;

			if (null !== $gd_avif_supported) {
				return $gd_avif_supported;
			}

			$cache_key = 'ultracache_gd_avif_alpha_probe_v3';
			$cached = get_transient($cache_key);
			if (is_array($cached) && array_key_exists('supported', $cached)) {
				$gd_avif_supported = !empty($cached['supported']);
				return $gd_avif_supported;
			}

			if (
				!function_exists('imageavif') ||
				!function_exists('imagecreatefromavif') ||
				!function_exists('imagecreatetruecolor') ||
				!function_exists('imagecolorallocatealpha') ||
				!function_exists('imagecolorsforindex')
			) {
				$gd_avif_supported = false;
				set_transient($cache_key, array('supported' => false, 'error' => __('GD AVIF encode/decode support is unavailable', 'ultracache')), DAY_IN_SECONDS);
				return false;
			}

			$tmp = $this->create_temp_file('ultracache-gd-avif-alpha-test');
			if (!$tmp) {
				$gd_avif_supported = false;
				set_transient($cache_key, array('supported' => false, 'error' => __('Unable to create GD AVIF probe file', 'ultracache')), HOUR_IN_SECONDS);
				return false;
			}

			$test_file = $tmp . '.avif';
			ultracache_safe_unlink($tmp);
			$image = imagecreatetruecolor(3, 1);
			if (!$image) {
				$gd_avif_supported = false;
				set_transient($cache_key, array('supported' => false, 'error' => __('Unable to create GD AVIF probe canvas', 'ultracache')), HOUR_IN_SECONDS);
				return false;
			}

			imagealphablending($image, false);
			imagesavealpha($image, true);
			imagesetpixel($image, 0, 0, imagecolorallocatealpha($image, 255, 0, 0, 127));
			imagesetpixel($image, 1, 0, imagecolorallocatealpha($image, 255, 0, 0, 64));
			imagesetpixel($image, 2, 0, imagecolorallocatealpha($image, 255, 0, 0, 0));

			$result = false;
			$gd_error = '';

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped handler captures GD encoder warnings and is restored immediately.
			set_error_handler(static function($severity, $message) use (&$gd_error) {
				$gd_error = (string) $message;
				return true;
			});
			try {
				$result = imageavif($image, $test_file, 60);
			} catch (\Throwable $e) {
				$gd_error = $e->getMessage();
				$result = false;
			} finally {
				restore_error_handler();
			}

			imagedestroy($image);
			$decoded = null;
			$gd_avif_supported = false;

			if ($result && $this->is_valid_generated_media_file($test_file, 'avif', 'media_converter_gd_avif_alpha_probe')) {
				try {
					$decoded = imagecreatefromavif($test_file);
					if ($decoded) {
						$transparent = imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0));
						$partial = imagecolorsforindex($decoded, imagecolorat($decoded, 1, 0));
						$opaque = imagecolorsforindex($decoded, imagecolorat($decoded, 2, 0));
						$gd_avif_supported = (
							isset($transparent['alpha'], $partial['alpha'], $opaque['alpha']) &&
							(int) $transparent['alpha'] >= 100 &&
							(int) $partial['alpha'] >= 30 && (int) $partial['alpha'] <= 100 &&
							(int) $opaque['alpha'] <= 15
						);
					}
				} catch (\Throwable $e) {
					$gd_error = $e->getMessage();
					$gd_avif_supported = false;
				}
			}

			if ($decoded) {
				imagedestroy($decoded);
			}

			if ($this->optimized_storage_path_exists($test_file, true)) {
				ultracache_safe_unlink($test_file);
				$this->optimized_storage_forget_path($test_file);
			}

			set_transient($cache_key, array(
				'supported' => (bool) $gd_avif_supported,
				'error' => $gd_avif_supported ? '' : ($gd_error ?: 'GD AVIF alpha probe failed'),
			), DAY_IN_SECONDS);

			return $gd_avif_supported;
		}

		private function supports_gd_webp() {
			static $gd_webp_supported = null;

			if (null !== $gd_webp_supported) {
				return $gd_webp_supported;
			}

			$cache_key = 'ultracache_gd_webp_encode_probe_v2';
			$cached = get_transient($cache_key);
			if (is_array($cached) && array_key_exists('supported', $cached)) {
				$gd_webp_supported = !empty($cached['supported']);
				return $gd_webp_supported;
			}

			if (!function_exists('imagewebp') || !function_exists('imagecreatetruecolor')) {
				$gd_webp_supported = false;
				set_transient($cache_key, array('supported' => false, 'error' => __('GD imagewebp() is unavailable', 'ultracache')), DAY_IN_SECONDS);
				return false;
			}

			$tmp = $this->create_temp_file('ultracache-webp-test');
			if (!$tmp) {
				$gd_webp_supported = false;
				set_transient($cache_key, array('supported' => false, 'error' => __('Unable to create GD WebP probe file', 'ultracache')), HOUR_IN_SECONDS);
				return false;
			}

			$test_file = $tmp . '.webp';
			ultracache_safe_unlink($tmp);

			$image = imagecreatetruecolor(2, 2);
			if (!$image) {
				$gd_webp_supported = false;
				set_transient($cache_key, array('supported' => false, 'error' => __('Unable to create GD WebP probe canvas', 'ultracache')), HOUR_IN_SECONDS);
				return false;
			}

			if (function_exists('imagepalettetotruecolor')) {
				imagepalettetotruecolor($image);
			}

			if (function_exists('imagealphablending')) {
				imagealphablending($image, false);
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
				(int) ultracache_safe_filesize($test_file, 'media_converter_format_support_test') > 0
			);

			if (file_exists($test_file)) {
				ultracache_safe_unlink($test_file);
			}

			set_transient($cache_key, array(
				'supported' => (bool) $gd_webp_supported,
				'error' => $gd_webp_supported ? '' : 'GD WebP probe did not produce a valid non-empty WebP file',
			), DAY_IN_SECONDS);

			return $gd_webp_supported;
		}

		private function is_valid_generated_media_file($file, $format, $context = '') {
			$file = (string) $file;
			$format = strtolower((string) $format);
			$context = (string) $context;

			if ('' === $file || !file_exists($file) || !is_file($file)) {
				return false;
			}

			if ((int) ultracache_safe_filesize($file, $context ?: 'media_converter_generated_file_validate') <= 0) {
				return false;
			}

			if ('avif' === $format) {
				$head = (string) ultracache_safe_file_get_contents($file, $context ?: 'media_converter_avif_header_validate', true);
				$head = substr($head, 0, 128);
				return (false !== strpos($head, 'ftyp') && (false !== stripos($head, 'avif') || false !== stripos($head, 'avis')));
			}

			if ('webp' === $format) {
				$head = (string) ultracache_safe_file_get_contents($file, $context ?: 'media_converter_webp_header_validate', true);
				$head = substr($head, 0, 16);
				return (0 === strpos($head, 'RIFF') && false !== strpos($head, 'WEBP'));
			}

			return true;
		}

		private function create_temp_file($prefix) {
			$prefix = (string) $prefix;
			$dir = $this->get_managed_media_temp_dir();

			if ('' === $dir) {
				return false;
			}

			$sanitized_prefix = preg_replace('/[^A-Za-z0-9_-]/', '', $prefix);
			if (!is_string($sanitized_prefix) || '' === $sanitized_prefix) {
				$sanitized_prefix = 'ultracache';
			}

			$this->cleanup_stale_managed_media_temp_files($dir);

			$tmp = ultracache_safe_tempnam($dir, substr($sanitized_prefix, 0, 32), 'media_converter_managed_tempnam');
			return (is_string($tmp) && '' !== $tmp) ? $tmp : false;
		}

		private function get_managed_media_temp_dir() {
			if (!defined('ULTRACACHE_CACHE_DIR') || '' === (string) ULTRACACHE_CACHE_DIR) {
				return '';
			}

			$dir = trailingslashit(ULTRACACHE_CACHE_DIR) . 'tmp/media/';

			if (!is_dir($dir) && !ultracache_safe_mkdir($dir, 0755, true, 'media_converter_managed_temp_dir')) {
				return '';
			}

			if (!is_dir($dir) || is_link($dir) || !ultracache_path_is_writable($dir)) {
				return '';
			}

			return trailingslashit($dir);
		}

		private function cleanup_stale_managed_media_temp_files($dir) {
			$dir = trailingslashit((string) $dir);
			if ('' === $dir || !is_dir($dir) || is_link($dir)) {
				return;
			}

			$items = ultracache_safe_scandir($dir, 'media_converter_managed_temp_cleanup scandir');
			if (!is_array($items)) {
				return;
			}

			$cutoff = time() - HOUR_IN_SECONDS;
			$removed = 0;

			foreach ($items as $item) {
				if ('.' === $item || '..' === $item) {
					continue;
				}

				$path = $dir . $item;
				if (is_link($path) || !is_file($path)) {
					continue;
				}

				if (0 !== strpos(strtolower((string) $item), 'ultracache')) {
					continue;
				}

				$mtime = ultracache_safe_filemtime($path, 'media_converter_managed_temp_cleanup filemtime');
				if (false !== $mtime && $mtime >= $cutoff) {
					continue;
				}

				if (ultracache_safe_unlink($path, 'media_converter_managed_temp_cleanup unlink')) {
					$removed++;
				}

				if ($removed >= 25) {
					break;
				}
			}
		}

		private function get_source_image_mime($source_file) {
			$source_file = (string) $source_file;
			if ('' === $source_file || !function_exists('wp_get_image_mime')) {
				return '';
			}

			return strtolower(trim((string) wp_get_image_mime($source_file)));
		}

		private function is_svg_source_file($source_file) {
			$extension = strtolower((string) pathinfo((string) $source_file, PATHINFO_EXTENSION));
			if (in_array($extension, array('svg', 'svgz'), true)) {
				return true;
			}

			return 'image/svg+xml' === $this->get_source_image_mime($source_file);
		}

		private function is_allowed_source_file($source_file) {
			if ($this->is_svg_source_file($source_file)) {
				return false;
			}

			if (!(bool) preg_match('/\.(jpe?g|png|webp)$/i', (string) $source_file)) {
				return false;
			}

			return in_array($this->get_source_image_mime($source_file), array('image/jpeg', 'image/png', 'image/webp'), true);
		}

		private function is_webp_fallback_source_file($source_file) {
			if ($this->is_svg_source_file($source_file)) {
				return false;
			}

			if (!(bool) preg_match('/\.(jpe?g|png)$/i', (string) $source_file)) {
				return false;
			}

			return in_array($this->get_source_image_mime($source_file), array('image/jpeg', 'image/png'), true);
		}

		private function convert_webp_with_wp_image_editor($source_file, $dest_file, $quality) {
			if (!function_exists('wp_get_image_editor')) {
				return false;
			}

			$editor = wp_get_image_editor($source_file);
			if (is_wp_error($editor) || !is_object($editor)) {
				return false;
			}

			if (method_exists($editor, 'set_quality')) {
				$editor->set_quality((int) $quality);
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped handler captures image-editor warnings and is restored immediately.
			set_error_handler(static function() {
				return true;
			});
			try {
				$saved = $editor->save($dest_file, 'image/webp');
			} finally {
				restore_error_handler();
			}

			if (is_wp_error($saved)) {
				return false;
			}

			return !empty($saved['path']) && $this->is_valid_generated_media_file($saved['path'], 'webp', 'media_converter_image_editor_webp_save');
		}

		private function convert_with_imagick($source_file, $dest_file, $format, $quality) {
			try {
				$image = new Imagick($source_file);
				if (defined('Imagick::ALPHACHANNEL_ACTIVATE') && method_exists($image, 'setImageAlphaChannel')) {
					$image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
				}
				$image->setImageFormat($format);
				$image->setImageCompressionQuality((int) $quality);

				if (method_exists($image, 'stripImage')) {
					$image->stripImage();
				}

				$result = $image->writeImage($dest_file);
				$image->clear();
				$image->destroy();

				if ($result && !$this->is_valid_generated_media_file($dest_file, $format, 'media_converter_imagick_save')) {
					$result = false;
				}

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
				ultracache_debug_log('imagick conversion failed', array('format' => strtoupper($format), 'error' => $e->getMessage()));
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
				imagealphablending($image, false);
			}

			if (function_exists('imagesavealpha')) {
				imagesavealpha($image, true);
			}

			$gd_error = '';

			$result = false;

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped handler captures GD/Imagick encoder warnings and is restored immediately.
			set_error_handler(static function($severity, $message) use (&$gd_error) {
				$gd_error = (string) $message;
				return true;
			});
			try {
				if ('avif' === $format) {
					$result = imageavif($image, $dest_file, (int) $quality);
				} elseif ('webp' === $format) {
					$result = imagewebp($image, $dest_file, (int) $quality);
				}
			} catch (\Throwable $e) {
				$gd_error = $e->getMessage();
				$result   = false;
			} finally {
				restore_error_handler();
			}


			imagedestroy($image);

			$ok = (
				$result &&
				$this->is_valid_generated_media_file($dest_file, $format, 'media_converter_gd_save')
			);

			if (!$ok) {
				if ($this->optimized_storage_path_exists($dest_file, true)) {
					ultracache_safe_unlink($dest_file);
					$this->optimized_storage_forget_path($dest_file);
				}

				if ($gd_error) {
					ultracache_debug_log('gd conversion failed', array('format' => strtoupper($format), 'error' => $gd_error));
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

		private function delete_generated_file_for_source($source_file, $format) {
			$dest_file = ('webp' === $format)
				? $this->get_webp_path_from_source($source_file)
				: $this->get_avif_path_from_source($source_file);

			if ($dest_file && file_exists($dest_file)) {
				ultracache_safe_unlink($dest_file);
			}
		}
}
