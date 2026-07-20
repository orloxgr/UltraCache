<?php
/**
 * UltraCache media encoder and attachment conversion core.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Encoder_Trait
{

		/**
		 * Per-request details for the last failed physical conversion unit.
		 *
		 * @var array<string,mixed>
		 */
		private $last_media_conversion_failure = array();

		private function reset_last_media_conversion_failure($source_file = '', $format = '') {
			$this->last_media_conversion_failure = array(
				'failureCode'    => '',
				'failureStage'   => '',
				'failureDetail'  => '',
				'sourceFile'     => wp_basename((string) $source_file),
				'attemptedFormat' => strtolower((string) $format),
				'encoderAttempts'=> array(),
			);
		}

		private function record_media_conversion_failure($engine, $code, $message, $stage = 'encode') {
			$engine = sanitize_key((string) $engine);
			$code = sanitize_key((string) $code);
			$stage = sanitize_key((string) $stage);
			$message = sanitize_text_field(substr((string) $message, 0, 500));
			if (!is_array($this->last_media_conversion_failure)) {
				$this->last_media_conversion_failure = array();
			}
			if (!isset($this->last_media_conversion_failure['encoderAttempts']) || !is_array($this->last_media_conversion_failure['encoderAttempts'])) {
				$this->last_media_conversion_failure['encoderAttempts'] = array();
			}
			$this->last_media_conversion_failure['failureCode'] = $code;
			$this->last_media_conversion_failure['failureStage'] = $stage;
			$this->last_media_conversion_failure['failureDetail'] = $message;
			$this->last_media_conversion_failure['encoderAttempts'][] = array(
				'engine'  => $engine,
				'code'    => $code,
				'stage'   => $stage,
				'message' => $message,
			);
		}

		private function get_last_media_conversion_failure() {
			return is_array($this->last_media_conversion_failure) ? $this->last_media_conversion_failure : array();
		}

		private function resolve_media_conversion_attempted_format($unit_format) {
			$unit_format = strtolower(trim((string) $unit_format));
			if ('best' !== $unit_format) {
				return $unit_format;
			}

			return implode('+', $this->get_best_media_conversion_formats());
		}

		private function get_best_media_conversion_formats() {
			$mode = $this->get_media_output_mode();
			if ('webp' === $mode) {
				return array('webp');
			}

			$formats = array('avif');
			if ('webp' === $this->get_media_fallback_format()) {
				$formats[] = 'webp';
			}

			return $formats;
		}

		private function sanitize_media_quality_profile($profile) {
			$profile = strtolower(trim((string) $profile));
			return in_array($profile, array('original', 'high', 'balanced', 'compact', 'smallest'), true) ? $profile : 'balanced';
		}

		private function get_media_quality_profile() {
			$profile = 'balanced';

			if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
				$settings = Ultra_Cache_WP::get_settings();
				if (isset($settings['media_quality'])) {
					$profile = $settings['media_quality'];
				}
			} else {
				$settings = get_option(defined('ULTRACACHE_SETTINGS_KEY') ? ULTRACACHE_SETTINGS_KEY : 'ultracache_settings', array());
				if (isset($settings['mediaQuality'])) {
					$profile = $settings['mediaQuality'];
				}
			}

			return $this->sanitize_media_quality_profile($profile);
		}

		private function get_media_quality_profiles() {
			return array(
				'original' => array('webp' => 100, 'avif' => 90),
				'high'     => array('webp' => 92, 'avif' => 75),
				'balanced' => array('webp' => 82, 'avif' => 60),
				'compact'  => array('webp' => 72, 'avif' => 50),
				'smallest' => array('webp' => 60, 'avif' => 40),
			);
		}

		private function get_media_encoder_quality_values($profile = '') {
			$profile = '' === (string) $profile ? $this->get_media_quality_profile() : $this->sanitize_media_quality_profile($profile);
			$profiles = $this->get_media_quality_profiles();

			if (!isset($profiles[$profile])) {
				$profile = 'balanced';
			}

			return array(
				'webp' => max(1, min(100, absint($profiles[$profile]['webp']))),
				'avif' => max(1, min(100, absint($profiles[$profile]['avif']))),
			);
		}

		private function get_media_encoder_quality($format) {
			$format = strtolower(trim((string) $format));
			$values = $this->get_media_encoder_quality_values();

			if (!isset($values[$format])) {
				$format = 'webp';
			}

			return max(1, min(100, absint($values[$format])));
		}

		public function to_avif($source_file) {
			$source_file = (string) $source_file;
			$this->reset_last_media_conversion_failure($source_file, 'avif');
			if ($this->is_media_background_work_paused()) {
				$this->record_media_conversion_failure('queue', 'background_paused', __('Media generation is paused by an administrator.', 'ultracache'), 'preflight');
				return false;
			}

			if (!$this->optimized_storage_readable_source_exists($source_file)) {
				$this->record_media_conversion_failure('source', 'source_unreadable', __('The source image is missing or unreadable.', 'ultracache'), 'preflight');
				return false;
			}

			if (!$this->is_allowed_source_file($source_file)) {
				$this->record_media_conversion_failure('source', 'unsupported_source', __('The source image type is not supported for this conversion.', 'ultracache'), 'preflight');
				return false;
			}

			if (!$this->supports_avif()) {
				$this->record_media_conversion_failure('encoder', 'encoder_unavailable', __('No verified AVIF encoder is available.', 'ultracache'), 'preflight');
				return false;
			}

			$dest_file = $this->get_avif_path_from_source($source_file);

			if (!$dest_file) {
				$this->record_media_conversion_failure('storage', 'destination_unavailable', __('The optimized destination path could not be resolved.', 'ultracache'), 'storage');
				return false;
			}

			$dest_dir = dirname($dest_file);

			if (!$this->optimized_storage_ensure_directory($dest_dir)) {
				$this->record_media_conversion_failure('storage', 'destination_unwritable', __('The optimized media directory could not be created or is not writable.', 'ultracache'), 'storage');
				return false;
			}

			$storage_before = $this->get_media_file_state($dest_file);
			$success = false;
			$this->reset_media_diagnostic_state();

			// AVIF always prefers Imagick. GD is used only when its real alpha
			// encode/decode probe confirms that transparency survives intact.
			$quality = $this->get_media_encoder_quality('avif');
			if ($this->supports_imagick_avif()) {
				$success = $this->convert_with_imagick($source_file, $dest_file, 'avif', $quality);
			}

			if (!$success && $this->supports_gd_avif()) {
				$success = $this->convert_with_gd($source_file, $dest_file, 'avif', $quality);
			}

			if (!$success) {
				if (empty($this->last_media_conversion_failure['failureCode'])) {
					$this->record_media_conversion_failure('encoder', 'all_encoders_failed', __('All available encoders failed to generate the requested image format.', 'ultracache'), 'encode');
				}
				if ($this->optimized_storage_path_exists($dest_file, true)) {
					ultracache_safe_unlink($dest_file);
					$this->optimized_storage_forget_path($dest_file);
				}
				$this->record_media_file_transition($dest_file, 'avif', $storage_before);
				return false;
			}

			if (!$this->optimized_storage_path_exists($dest_file, true) || !$this->is_valid_generated_media_file($dest_file, 'avif', 'media_converter_dest_verify')) {
				$this->record_media_conversion_failure('validation', 'invalid_generated_file', __('The generated AVIF file failed the final file validation.', 'ultracache'), 'validation');
				if ($this->optimized_storage_path_exists($dest_file, true)) {
					ultracache_safe_unlink($dest_file);
					$this->optimized_storage_forget_path($dest_file);
				}
				$this->record_media_file_transition($dest_file, 'avif', $storage_before);
				return false;
			}

			$this->optimized_storage_forget_path($dest_file);
			$this->record_media_file_transition($dest_file, 'avif', $storage_before);
			return $dest_file;
		}

		public function to_webp($source_file) {
			$source_file = (string) $source_file;
			$this->reset_last_media_conversion_failure($source_file, 'webp');
			if ($this->is_media_background_work_paused()) {
				$this->record_media_conversion_failure('queue', 'background_paused', __('Media generation is paused by an administrator.', 'ultracache'), 'preflight');
				return false;
			}

			if (!$this->optimized_storage_readable_source_exists($source_file)) {
				$this->record_media_conversion_failure('source', 'source_unreadable', __('The source image is missing or unreadable.', 'ultracache'), 'preflight');
				return false;
			}

			if (!$this->is_webp_fallback_source_file($source_file)) {
				$this->record_media_conversion_failure('source', 'unsupported_source', __('The source image type is not supported for WebP conversion.', 'ultracache'), 'preflight');
				return false;
			}

			if (!$this->supports_webp()) {
				$this->record_media_conversion_failure('encoder', 'encoder_unavailable', __('No WebP encoder is available.', 'ultracache'), 'preflight');
				return false;
			}

			$dest_file = $this->get_webp_path_from_source($source_file);

			if (!$dest_file) {
				$this->record_media_conversion_failure('storage', 'destination_unavailable', __('The optimized destination path could not be resolved.', 'ultracache'), 'storage');
				return false;
			}

			$dest_dir = dirname($dest_file);

			if (!$this->optimized_storage_ensure_directory($dest_dir)) {
				$this->record_media_conversion_failure('storage', 'destination_unwritable', __('The optimized media directory could not be created or is not writable.', 'ultracache'), 'storage');
				return false;
			}

			$storage_before = $this->get_media_file_state($dest_file);
			$success = false;

			$quality = $this->get_media_encoder_quality('webp');
			$success = $this->convert_webp_with_wp_image_editor($source_file, $dest_file, $quality);

			if (!$success && $this->supports_imagick_webp()) {
				$success = $this->convert_with_imagick($source_file, $dest_file, 'webp', $quality);
			}

			if (!$success && $this->supports_gd_webp()) {
				$success = $this->convert_with_gd($source_file, $dest_file, 'webp', $quality);
			}

			if (!$success) {
				if (empty($this->last_media_conversion_failure['failureCode'])) {
					$this->record_media_conversion_failure('encoder', 'all_encoders_failed', __('All available encoders failed to generate WebP.', 'ultracache'), 'encode');
				}
				if ($this->optimized_storage_path_exists($dest_file, true)) {
					ultracache_safe_unlink($dest_file);
					$this->optimized_storage_forget_path($dest_file);
				}
				$this->record_media_file_transition($dest_file, 'webp', $storage_before);
				return false;
			}

			if (!$this->optimized_storage_path_exists($dest_file, true) || !$this->is_valid_generated_media_file($dest_file, 'webp', 'media_converter_dest_verify')) {
				$this->record_media_conversion_failure('validation', 'invalid_generated_file', __('The generated WebP file failed the final file validation.', 'ultracache'), 'validation');
				if ($this->optimized_storage_path_exists($dest_file, true)) {
					ultracache_safe_unlink($dest_file);
					$this->optimized_storage_forget_path($dest_file);
				}
				$this->record_media_file_transition($dest_file, 'webp', $storage_before);
				return false;
			}

			$this->optimized_storage_forget_path($dest_file);
			$this->record_media_file_transition($dest_file, 'webp', $storage_before);
			return $dest_file;
		}

		private function generate_best_format($source_file) {
			foreach ($this->get_best_media_conversion_formats() as $format) {
				$result = ('avif' === $format) ? $this->to_avif($source_file) : $this->to_webp($source_file);
				if ($result) {
					return $result;
				}
			}

			return false;
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
			$path = false;

			if ('avif' === $format) {
				$path = $this->get_avif_path_from_source($source_file);
			} elseif ('webp' === $format) {
				$path = $this->get_webp_path_from_source($source_file);
			}

			if (!$path || !$this->optimized_storage_path_exists($path)) {
				return false;
			}

			$source_mtime = function_exists('ultracache_safe_filemtime')
				? (int) ultracache_safe_filemtime($source_file, 'media_variant_source_freshness')
				: 0;
			$variant_mtime = function_exists('ultracache_safe_filemtime')
				? (int) ultracache_safe_filemtime($path, 'media_variant_output_freshness')
				: 0;

			return $source_mtime <= 0 || ($variant_mtime > 0 && $variant_mtime >= $source_mtime);
		}


		/**
		 * Build deterministic conversion units for one attachment.
		 *
		 * A unit is one physical source image and one output policy. The `both`
		 * policy creates two units per source file so each encoder runs in its own
		 * resumable step.
		 *
		 * @param int    $attachment_id Attachment ID.
		 * @param string $format        Requested output policy.
		 * @return array<int,array<string,string>>
		 */
		private function get_attachment_conversion_units($attachment_id, $format = 'best') {
			$format = strtolower((string) $format);
			if (!in_array($format, array('best', 'avif', 'webp', 'both'), true)) {
				$format = 'best';
			}

			$units = array();
			foreach ($this->get_attachment_source_files($attachment_id) as $source_file) {
				$unit_formats = ('both' === $format) ? array('avif', 'webp') : (('best' === $format) ? $this->get_best_media_conversion_formats() : array($format));
				foreach ($unit_formats as $unit_format) {
					$units[] = array(
						'source_file' => (string) $source_file,
						'format'      => (string) $unit_format,
					);
				}
			}

			return $units;
		}

		/**
		 * Check whether a conversion unit already has its required output.
		 *
		 * @param array<string,string> $unit Conversion unit.
		 * @return bool
		 */
		private function is_attachment_conversion_unit_complete(array $unit) {
			$source_file = (string) ($unit['source_file'] ?? '');
			$format      = (string) ($unit['format'] ?? 'best');

			if ('best' === $format) {
				foreach ($this->get_best_media_conversion_formats() as $required_format) {
					if (!$this->generated_variant_exists($source_file, $required_format)) {
						return false;
					}
					if ('avif' === $required_format) {
						$this->mark_existing_avif_variant_available($source_file);
					}
				}

				return true;
			}

			$complete = $this->generated_variant_exists($source_file, $format);
			if ($complete && 'avif' === $format) {
				$this->mark_existing_avif_variant_available($source_file);
			}
			return $complete;
		}

		/**
		 * Check whether the current server can process a conversion unit.
		 *
		 * @param array<string,string> $unit Conversion unit.
		 * @return bool
		 */
		private function is_attachment_conversion_unit_supported(array $unit) {
			$source_file = (string) ($unit['source_file'] ?? '');
			$format      = (string) ($unit['format'] ?? 'best');

			if ('avif' === $format) {
				return $this->supports_avif() && $this->is_allowed_source_file($source_file);
			}

			if ('webp' === $format) {
				return $this->supports_webp() && $this->is_webp_fallback_source_file($source_file);
			}

			foreach ($this->get_best_media_conversion_formats() as $required_format) {
				if ('avif' === $required_format && $this->supports_avif() && $this->is_allowed_source_file($source_file)) {
					return true;
				}
				if ('webp' === $required_format && $this->supports_webp() && $this->is_webp_fallback_source_file($source_file)) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Summarize completed, unsupported, and remaining conversion units.
		 *
		 * @param array<int,array<string,string>> $units Conversion units.
		 * @return array<string,int>
		 */
		private function get_attachment_conversion_unit_progress(array $units) {
			$progress = array(
				'workTotal'          => count($units),
				'workCompleted'      => 0,
				'skippedExisting'    => 0,
				'skippedUnsupported' => 0,
				'remainingUnits'     => 0,
			);

			foreach ($units as $unit) {
				if ($this->is_attachment_conversion_unit_complete($unit)) {
					$progress['workCompleted']++;
					$progress['skippedExisting']++;
					continue;
				}

				if (!$this->is_attachment_conversion_unit_supported($unit)) {
					$progress['workCompleted']++;
					$progress['skippedUnsupported']++;
					continue;
				}

				$progress['remainingUnits']++;
			}

			return $progress;
		}

		/**
		 * Process at most one physical image conversion unit for an attachment.
		 *
		 * @param int    $attachment_id Attachment ID.
		 * @param string $format        Requested output policy.
		 * @param bool   $only_missing  Whether existing outputs should be skipped.
		 * @return array<string,mixed>
		 */
		public function process_next_attachment_conversion_unit($attachment_id, $format = 'best', $only_missing = true) {
			$attachment_id = absint($attachment_id);
			$format        = strtolower((string) $format);
			$only_missing  = (bool) $only_missing;

			if (!in_array($format, array('best', 'avif', 'webp', 'both'), true)) {
				$format = 'best';
			}

			$units    = $this->get_attachment_conversion_units($attachment_id, $format);
			$progress = $this->get_attachment_conversion_unit_progress($units);
			$summary  = array_merge(
				array(
					'attachment_id'       => $attachment_id,
					'success'             => true,
					'processed'           => 0,
					'converted'           => false,
					'avif'                => 0,
					'webp'                => 0,
					'sourceFiles'         => count($this->get_attachment_source_files($attachment_id)),
					'workCompletedThisRun' => 0,
					'complete'            => 0 === (int) $progress['remainingUnits'],
				),
				$progress
			);

			if (empty($units)) {
				$summary['skippedReason'] = 'no_supported_work';
				return $summary;
			}

			if (!empty($summary['complete'])) {
				$summary['alreadyOptimized'] = !empty($progress['skippedExisting']);
				$summary['skippedReason']    = !empty($summary['alreadyOptimized']) ? 'already_optimized' : 'no_supported_work';
				return $summary;
			}

			foreach ($units as $unit) {
				if ($only_missing && $this->is_attachment_conversion_unit_complete($unit)) {
					continue;
			}
				if (!$this->is_attachment_conversion_unit_supported($unit)) {
					continue;
				}

				$source_file = (string) $unit['source_file'];
				$unit_format = (string) $unit['format'];
				$result      = false;

				if ('best' === $unit_format) {
					$result = $this->generate_best_format($source_file);
				} elseif ('avif' === $unit_format) {
					$result = $this->to_avif($source_file);
				} elseif ('webp' === $unit_format) {
					$result = $this->to_webp($source_file);
				}

				$summary['sourceFile']       = wp_basename($source_file);
				$summary['requestedFormat']  = $unit_format;
				$summary['attemptedFormat']  = $this->resolve_media_conversion_attempted_format($unit_format);

				if (!$result) {
					$summary['success'] = false;
					$summary['message'] = __('The image conversion unit could not be generated.', 'ultracache');
					$failure = $this->get_last_media_conversion_failure();
					foreach (array('failureCode', 'failureStage', 'failureDetail', 'encoderAttempts') as $failure_key) {
						if (isset($failure[$failure_key]) && '' !== $failure[$failure_key] && array() !== $failure[$failure_key]) {
							$summary[$failure_key] = $failure[$failure_key];
						}
					}
					return $summary;
				}

				$summary['processed']            = 1;
				$summary['converted']            = true;
				$summary['workCompletedThisRun'] = 1;
				$extension = strtolower((string) pathinfo($result, PATHINFO_EXTENSION));
				if (in_array($extension, array('avif', 'webp'), true)) {
					$summary['attemptedFormat'] = $extension;
					$summary['generatedFormat'] = $extension;
				}
				if ('avif' === $extension) {
					$summary['avif'] = 1;
				} elseif ('webp' === $extension) {
					$summary['webp'] = 1;
				}

				$after = $this->get_attachment_conversion_unit_progress($units);
				$summary = array_merge($summary, $after);
				$summary['complete'] = 0 === (int) $after['remainingUnits'];
				return $summary;
			}

			$summary['complete']      = true;
			$summary['skippedReason'] = !empty($progress['skippedExisting']) ? 'already_optimized' : 'no_supported_work';
			$summary['alreadyOptimized'] = 'already_optimized' === $summary['skippedReason'];
			return $summary;
		}

		public function generate_attachment_formats($attachment_id, $format = 'best', $only_missing = false, array $budget = array()) {
			$attachment_id = absint($attachment_id);
			$format        = strtolower((string) $format);
			$only_missing  = (bool) $only_missing;

			$summary = array(
				'attachment_id'    => $attachment_id,
				'success'          => false,
				'processed'        => 0,
				'avif'             => 0,
				'webp'             => 0,
				'skippedExisting'  => 0,
				'sourceFiles'      => 0,
				'workTotal'        => 0,
				'workCompleted'    => 0,
				'paused'           => false,
				'pauseReason'      => '',
			);

			if (!in_array($format, array('best', 'avif', 'webp', 'both'), true)) {
				$format = 'best';
			}

			$source_files              = $this->get_attachment_source_files($attachment_id);
			$summary['sourceFiles']     = count($source_files);
			$work_multiplier            = ('both' === $format) ? 2 : (('best' === $format) ? count($this->get_best_media_conversion_formats()) : 1);
			$summary['workTotal']       = (int) ($summary['sourceFiles'] * $work_multiplier);

			foreach ($source_files as $source_file) {
				$pause_reason = !empty($budget) && function_exists('ultracache_operation_pause_reason')
					? ultracache_operation_pause_reason($budget)
					: '';
				if ('' !== $pause_reason) {
					$summary['paused']      = true;
					$summary['pauseReason'] = $pause_reason;
					break;
				}

				$formats = ('both' === $format) ? array('avif', 'webp') : (('best' === $format) ? $this->get_best_media_conversion_formats() : array($format));
				foreach ($formats as $single_format) {
					$pause_reason = !empty($budget) && function_exists('ultracache_operation_pause_reason')
						? ultracache_operation_pause_reason($budget)
						: '';
					if ('' !== $pause_reason) {
						$summary['paused']      = true;
						$summary['pauseReason'] = $pause_reason;
						break 2;
					}

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
			$report = $this->run_avif_encoder_self_test(false);
			return !empty($report['engines']['imagick']['passed']);
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
			$report = $this->run_avif_encoder_self_test(false);
			return !empty($report['engines']['gd']['passed']);
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
				$this->record_media_conversion_failure('wordpress-image-editor', 'editor_unavailable', __('The WordPress image editor API is unavailable.', 'ultracache'), 'encode');
				return false;
			}

			$editor = wp_get_image_editor($source_file);
			if (is_wp_error($editor)) {
				$this->record_media_conversion_failure('wordpress-image-editor', (string) $editor->get_error_code(), $editor->get_error_message(), 'decode');
				return false;
			}
			if (!is_object($editor)) {
				$this->record_media_conversion_failure('wordpress-image-editor', 'editor_unavailable', __('WordPress could not create an image editor for the source file.', 'ultracache'), 'decode');
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
				$this->record_media_conversion_failure('wordpress-image-editor', (string) $saved->get_error_code(), $saved->get_error_message(), 'encode');
				return false;
			}

			if (!empty($saved['path'])) {
				$this->optimized_storage_harden_upload_permissions((string) $saved['path'], 'file');
			}

			$valid = !empty($saved['path']) && $this->is_valid_generated_media_file($saved['path'], 'webp', 'media_converter_image_editor_webp_save');
			if (!$valid) {
				$this->record_media_conversion_failure('wordpress-image-editor', 'invalid_generated_file', __('The WordPress image editor did not produce a valid WebP file.', 'ultracache'), 'validation');
			}
			return $valid;
		}

		private function convert_with_imagick($source_file, $dest_file, $format, $quality) {
			try {
				$image = new Imagick($source_file);

				/*
				 * Preserve the source's native channel layout. Imagick already keeps a
				 * real PNG/WebP alpha channel when one exists. Activating alpha on an
				 * opaque JPEG can make some AVIF delegates emit corrupted pixel data.
				 */
				$image->setImageFormat($format);
				$image->setImageCompressionQuality((int) $quality);

				if (method_exists($image, 'stripImage')) {
					$image->stripImage();
				}

				$result = $image->writeImage($dest_file);
				if ($result) {
					$this->optimized_storage_harden_upload_permissions($dest_file, 'file');
				}
				$image->clear();
				$image->destroy();

				if ($result && !$this->is_valid_generated_media_file($dest_file, $format, 'media_converter_imagick_save')) {
					$this->record_media_conversion_failure('imagick', 'invalid_generated_file', __('Imagick wrote a file that failed format validation.', 'ultracache'), 'validation');
					$result = false;
				} elseif (!$result) {
					$this->record_media_conversion_failure('imagick', 'write_failed', __('Imagick could not write the generated image.', 'ultracache'), 'encode');
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
			} catch (Throwable $e) {
				ultracache_debug_log('imagick conversion failed', array('format' => strtoupper($format), 'error' => $e->getMessage()));
				$this->record_media_conversion_failure('imagick', 'exception', $e->getMessage(), 'encode');
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
				$this->record_media_conversion_failure('gd', 'source_type_unknown', __('GD could not determine the source image type.', 'ultracache'), 'decode');
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
				$this->record_media_conversion_failure('gd', 'source_decode_failed', __('GD could not decode the source image.', 'ultracache'), 'decode');
				return false;
			}

			if (function_exists('imagepalettetotruecolor')) {
				imagepalettetotruecolor($image);
			}

			/*
			 * JPEG cannot contain transparency. Do not request an alpha-bearing
			 * output for opaque JPEG sources; preserve native alpha only for source
			 * formats that can legitimately carry it.
			 */
			$source_can_have_alpha = in_array($type, array(IMAGETYPE_PNG, IMAGETYPE_WEBP), true);
			if ($source_can_have_alpha && function_exists('imagealphablending')) {
				imagealphablending($image, false);
			}

			if ($source_can_have_alpha && function_exists('imagesavealpha')) {
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


			if ($result) {
				$this->optimized_storage_harden_upload_permissions($dest_file, 'file');
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
				$this->record_media_conversion_failure('gd', $gd_error ? 'encoder_error' : 'invalid_generated_file', $gd_error ?: __('GD did not produce a valid generated image.', 'ultracache'), $gd_error ? 'encode' : 'validation');

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

			if (!$dest_file) {
				return;
			}

			$storage_before = $this->get_media_file_state($dest_file);
			if (!empty($storage_before['exists'])) {
				ultracache_safe_unlink($dest_file);
				$this->optimized_storage_forget_path($dest_file);
			}
			$this->record_media_file_transition($dest_file, $format, $storage_before);
		}
}
