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
				'skippedReason'  => '',
				'skipDetail'     => '',
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

		private function record_media_conversion_skip($code, $message) {
			$code = sanitize_key((string) $code);
			$message = sanitize_text_field(substr((string) $message, 0, 500));
			if (!is_array($this->last_media_conversion_failure)) {
				$this->last_media_conversion_failure = array();
			}
			$this->last_media_conversion_failure['failureCode'] = '';
			$this->last_media_conversion_failure['failureStage'] = '';
			$this->last_media_conversion_failure['failureDetail'] = '';
			$this->last_media_conversion_failure['skippedReason'] = $code;
			$this->last_media_conversion_failure['skipDetail'] = $message;
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

			$semantic_skip_reason = $this->get_media_source_conversion_skip_reason($source_file, 'avif');
			if ('' !== $semantic_skip_reason) {
				$this->record_media_conversion_skip($semantic_skip_reason, $this->get_media_conversion_skip_detail($semantic_skip_reason));
				return false;
			}

			if (!$this->ensure_media_source_geometry_admitted($source_file)) {
				return false;
			}

			$color_profile_requires_imagick = $this->media_source_requires_color_managed_encoder($source_file);
			$imagick_avif_supported = $this->supports_imagick_avif();

			if (!$imagick_avif_supported && !$this->supports_gd_avif()) {
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
			if ($imagick_avif_supported) {
				$success = $this->convert_with_imagick($source_file, $dest_file, 'avif', $quality);
			}

			if (!$success && !$color_profile_requires_imagick && $this->supports_gd_avif()) {
				$success = $this->convert_with_gd($source_file, $dest_file, 'avif', $quality);
			}

			if (!$success) {
				if (empty($this->last_media_conversion_failure['failureCode']) && empty($this->last_media_conversion_failure['skippedReason'])) {
					$this->record_media_conversion_failure('encoder', 'all_encoders_failed', __('All available encoders failed to generate the requested image format.', 'ultracache'), 'encode');
				}
				// Encoder failures occur against temporary files. Preserve any previously valid destination.
				$this->optimized_storage_forget_path($dest_file);
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

			$semantic_skip_reason = $this->get_media_source_conversion_skip_reason($source_file, 'webp');
			if ('' !== $semantic_skip_reason) {
				$this->record_media_conversion_skip($semantic_skip_reason, $this->get_media_conversion_skip_detail($semantic_skip_reason));
				return false;
			}

			if (!$this->ensure_media_source_geometry_admitted($source_file)) {
				return false;
			}

			$source_is_avif = $this->source_file_matches_target_format($source_file, 'avif');
			$color_profile_requires_imagick = $this->media_source_requires_color_managed_encoder($source_file);
			$imagick_webp_supported = $source_is_avif ? $this->supports_imagick_avif_to_webp() : $this->supports_imagick_webp();
			$gd_webp_supported = $source_is_avif ? $this->supports_gd_avif_to_webp() : $this->supports_gd_webp();

			if (!$imagick_webp_supported && !$gd_webp_supported) {
				$this->record_media_conversion_failure(
					'encoder',
					'encoder_unavailable',
					$source_is_avif
						? __('No verified AVIF decoder and WebP encoder combination is available.', 'ultracache')
						: __('No WebP encoder is available.', 'ultracache'),
					'preflight'
				);
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
			if (!$source_is_avif && !$color_profile_requires_imagick) {
				$success = $this->convert_webp_with_wp_image_editor($source_file, $dest_file, $quality);
			}

			if (!$success && $imagick_webp_supported) {
				$success = $this->convert_with_imagick($source_file, $dest_file, 'webp', $quality);
			}

			if (!$success && !$color_profile_requires_imagick && $gd_webp_supported) {
				$success = $this->convert_with_gd($source_file, $dest_file, 'webp', $quality);
			}

			if (!$success) {
				if (empty($this->last_media_conversion_failure['failureCode']) && empty($this->last_media_conversion_failure['skippedReason'])) {
					$this->record_media_conversion_failure('encoder', 'all_encoders_failed', __('All available encoders failed to generate WebP.', 'ultracache'), 'encode');
				}
				// Encoder failures occur against temporary files. Preserve any previously valid destination.
				$this->optimized_storage_forget_path($dest_file);
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

		private function generate_local_asset_variant(array $source, $format) {
			$format = strtolower(trim((string) $format));
			$source_file = isset($source['local_path']) ? wp_normalize_path((string) $source['local_path']) : '';
			$this->reset_last_media_conversion_failure($source_file, $format);
			if (!in_array($format, array('avif', 'webp'), true) || '' === $source_file || !$this->optimized_storage_readable_source_exists($source_file)) {
				$this->record_media_conversion_failure('source', 'source_unreadable', __('The local asset source image is missing or unreadable.', 'ultracache'), 'preflight');
				return false;
			}
			if (!$this->is_source_file_supported_for_format($source_file, $format)) {
				$this->record_media_conversion_failure('source', 'unsupported_source', __('The local asset source image type is not supported for this conversion.', 'ultracache'), 'preflight');
				return false;
			}
			$semantic_skip_reason = $this->get_media_source_conversion_skip_reason($source_file, $format);
			if ('' !== $semantic_skip_reason) {
				$this->record_media_conversion_skip($semantic_skip_reason, $this->get_media_conversion_skip_detail($semantic_skip_reason));
				return false;
			}
			if (!$this->ensure_media_source_geometry_admitted($source_file)) {
				return false;
			}
			$relative_path = function_exists('ultracache_build_local_asset_optimized_media_relative_path')
				? ultracache_build_local_asset_optimized_media_relative_path($source, $format)
				: false;
			$base_dir = function_exists('ultracache_local_asset_optimized_images_storage_dir')
				? ultracache_local_asset_optimized_images_storage_dir($format)
				: '';
			if (!is_string($relative_path) || '' === $relative_path || '' === $base_dir) {
				$this->record_media_conversion_failure('storage', 'destination_unavailable', __('The local asset optimized destination path could not be resolved.', 'ultracache'), 'storage');
				return false;
			}
			$dest_file = trailingslashit($base_dir) . $relative_path;
			if (!$this->optimized_storage_ensure_directory(dirname($dest_file))) {
				$this->record_media_conversion_failure('storage', 'destination_unwritable', __('The local asset optimized media directory could not be created or is not writable.', 'ultracache'), 'storage');
				return false;
			}
			$storage_before = $this->get_media_file_state($dest_file);
			$quality = $this->get_media_encoder_quality($format);
			$success = false;
			$color_profile_requires_imagick = $this->media_source_requires_color_managed_encoder($source_file);
			if ('avif' === $format) {
				$imagick_supported = $this->supports_imagick_avif();
				if (!$imagick_supported && !$this->supports_gd_avif()) {
					$this->record_media_conversion_failure('encoder', 'encoder_unavailable', __('No verified AVIF encoder is available.', 'ultracache'), 'preflight');
					return false;
				}
				$this->reset_media_diagnostic_state();
				if ($imagick_supported) {
					$success = $this->convert_with_imagick($source_file, $dest_file, 'avif', $quality);
				}
				if (!$success && !$color_profile_requires_imagick && $this->supports_gd_avif()) {
					$success = $this->convert_with_gd($source_file, $dest_file, 'avif', $quality);
				}
			} else {
				$source_is_avif = $this->source_file_matches_target_format($source_file, 'avif');
				$imagick_supported = $source_is_avif ? $this->supports_imagick_avif_to_webp() : $this->supports_imagick_webp();
				$gd_supported = $source_is_avif ? $this->supports_gd_avif_to_webp() : $this->supports_gd_webp();
				if (!$imagick_supported && !$gd_supported) {
					$this->record_media_conversion_failure('encoder', 'encoder_unavailable', __('No verified WebP conversion engine is available.', 'ultracache'), 'preflight');
					return false;
				}
				if (!$source_is_avif && !$color_profile_requires_imagick) {
					$success = $this->convert_webp_with_wp_image_editor($source_file, $dest_file, $quality);
				}
				if (!$success && $imagick_supported) {
					$success = $this->convert_with_imagick($source_file, $dest_file, 'webp', $quality);
				}
				if (!$success && !$color_profile_requires_imagick && $gd_supported) {
					$success = $this->convert_with_gd($source_file, $dest_file, 'webp', $quality);
				}
			}
			if (!$success) {
				if (empty($this->last_media_conversion_failure['failureCode']) && empty($this->last_media_conversion_failure['skippedReason'])) {
					$this->record_media_conversion_failure('encoder', 'all_encoders_failed', __('All available encoders failed to generate the local asset variant.', 'ultracache'), 'encode');
				}
				$this->optimized_storage_forget_path($dest_file);
				$this->record_media_file_transition($dest_file, $format, $storage_before);
				return false;
			}
			if (!$this->optimized_storage_path_exists($dest_file, true) || !$this->is_valid_generated_media_file($dest_file, $format, 'media_local_asset_dest_verify')) {
				$this->record_media_conversion_failure('validation', 'invalid_generated_file', __('The generated local asset variant failed final validation.', 'ultracache'), 'validation');
				if ($this->optimized_storage_path_exists($dest_file, true)) {
					ultracache_safe_unlink($dest_file);
					$this->optimized_storage_forget_path($dest_file);
				}
				$this->record_media_file_transition($dest_file, $format, $storage_before);
				return false;
			}
			$this->optimized_storage_forget_path($dest_file);
			$this->record_media_file_transition($dest_file, $format, $storage_before);
			return $dest_file;
		}


		private function generate_best_format($source_file) {
			$semantic_skip_reason = $this->get_media_source_conversion_skip_reason($source_file, 'best');
			if ('' !== $semantic_skip_reason) {
				$this->record_media_conversion_skip($semantic_skip_reason, $this->get_media_conversion_skip_detail($semantic_skip_reason));
				return false;
			}

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

			if (!$path) {
				return false;
			}

			return 'fresh' === $this->get_optimized_variant_freshness_state($source_file, $path, true);
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
					if ($this->source_file_matches_target_format($source_file, $required_format)) {
						continue;
					}
					if (!$this->generated_variant_exists($source_file, $required_format)) {
						return false;
					}
					if ('avif' === $required_format) {
						$this->mark_existing_avif_variant_available($source_file);
					}
				}

				return true;
			}

			if ($this->source_file_matches_target_format($source_file, $format)) {
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

			if ('' !== $this->get_media_source_conversion_skip_reason($source_file, $format)) {
				return false;
			}

			if ($this->source_file_matches_target_format($source_file, $format)) {
				return true;
			}

			if ('avif' === $format) {
				return $this->supports_avif() && $this->is_source_file_supported_for_format($source_file, 'avif');
			}

			if ('webp' === $format) {
				return $this->supports_webp() && $this->is_source_file_supported_for_format($source_file, 'webp');
			}

			foreach ($this->get_best_media_conversion_formats() as $required_format) {
				if ('avif' === $required_format && $this->supports_avif() && $this->is_source_file_supported_for_format($source_file, 'avif')) {
					return true;
				}
				if ('webp' === $required_format && $this->supports_webp() && $this->is_source_file_supported_for_format($source_file, 'webp')) {
					return true;
				}
			}

			return false;
		}

		private function get_attachment_conversion_unit_key(array $unit) {
			return str_replace('\\', '/', (string) ($unit['source_file'] ?? '')) . '|' . strtolower((string) ($unit['format'] ?? ''));
		}

		/**
		 * Summarize completed, unsupported, and remaining conversion units.
		 *
		 * @param array<int,array<string,string>> $units Conversion units.
		 * @param array<string,array<string,string>> $runtime_skipped_units Semantic skips discovered while attempting a unit in this request.
		 * @return array<string,mixed>
		 */
		private function get_attachment_conversion_unit_progress(array $units, array $runtime_skipped_units = array()) {
			$progress = array(
				'workTotal'          => count($units),
				'workCompleted'      => 0,
				'skippedExisting'    => 0,
				'skippedUnsupported' => 0,
				'remainingUnits'     => 0,
				'semanticSkipReason'=> '',
			);

			foreach ($units as $unit) {
				$unit_key = $this->get_attachment_conversion_unit_key($unit);
				if (isset($runtime_skipped_units[$unit_key])) {
					$progress['workCompleted']++;
					$progress['skippedUnsupported']++;
					if ('' === (string) $progress['semanticSkipReason']) {
						$progress['semanticSkipReason'] = (string) ($runtime_skipped_units[$unit_key]['reason'] ?? '');
					}
					continue;
				}

				if ($this->is_attachment_conversion_unit_complete($unit)) {
					$progress['workCompleted']++;
					$progress['skippedExisting']++;
					continue;
				}

				if (!$this->is_attachment_conversion_unit_supported($unit)) {
					$progress['workCompleted']++;
					$progress['skippedUnsupported']++;
					$semantic_skip_reason = $this->get_media_source_conversion_skip_reason(
						(string) ($unit['source_file'] ?? ''),
						(string) ($unit['format'] ?? 'best')
					);
					if ('' !== $semantic_skip_reason && '' === (string) $progress['semanticSkipReason']) {
						$progress['semanticSkipReason'] = $semantic_skip_reason;
					}
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
				$semantic_skip_reason = (string) ($progress['semanticSkipReason'] ?? '');
				$summary['alreadyOptimized'] = '' === $semantic_skip_reason && !empty($progress['skippedExisting']);
				$summary['skippedReason'] = '' !== $semantic_skip_reason
					? $semantic_skip_reason
					: (!empty($summary['alreadyOptimized']) ? 'already_optimized' : 'no_supported_work');
				if ('' !== $semantic_skip_reason) {
					$summary['skipDetail'] = $this->get_media_conversion_skip_detail($semantic_skip_reason);
				}
				unset($summary['semanticSkipReason']);
				return $summary;
			}

			$runtime_skipped_units = array();
			$completed_this_run = 0;
			foreach ($units as $unit) {
				if ($only_missing && $this->is_attachment_conversion_unit_complete($unit)) {
					continue;
			}
				if (!$this->is_attachment_conversion_unit_supported($unit)) {
					$reason = $this->get_media_source_conversion_skip_reason(
						(string) ($unit['source_file'] ?? ''),
						(string) ($unit['format'] ?? '')
					);
					if ('' !== $reason) {
						$detail = $this->get_media_conversion_skip_detail($reason);
						$runtime_skipped_units[$this->get_attachment_conversion_unit_key($unit)] = array(
							'reason' => $reason,
							'detail' => $detail,
						);
						$summary['skippedReason'] = $reason;
						$summary['skipDetail'] = $detail;
						$summary['skippedFormat'] = (string) ($unit['format'] ?? '');
					}
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
					$failure = $this->get_last_media_conversion_failure();
					$skip_reason = (string) ($failure['skippedReason'] ?? '');
					if ('' !== $skip_reason) {
						$skip_detail = (string) ($failure['skipDetail'] ?? $this->get_media_conversion_skip_detail($skip_reason));
						$runtime_skipped_units[$this->get_attachment_conversion_unit_key($unit)] = array(
							'reason' => $skip_reason,
							'detail' => $skip_detail,
						);
						$summary['skippedReason'] = $skip_reason;
						$summary['skipDetail'] = $skip_detail;
						$summary['skippedFormat'] = $unit_format;
						$completed_this_run++;
						continue;
					}

					$summary['success'] = false;
					$summary['message'] = __('The image conversion unit could not be generated.', 'ultracache');
					foreach (array('failureCode', 'failureStage', 'failureDetail', 'encoderAttempts') as $failure_key) {
						if (isset($failure[$failure_key]) && '' !== $failure[$failure_key] && array() !== $failure[$failure_key]) {
							$summary[$failure_key] = $failure[$failure_key];
						}
					}
					$summary['workCompletedThisRun'] = $completed_this_run;
					return $summary;
				}

				$summary['processed']            = 1;
				$summary['converted']            = true;
				$completed_this_run++;
				$summary['workCompletedThisRun'] = $completed_this_run;
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

				$after = $this->get_attachment_conversion_unit_progress($units, $runtime_skipped_units);
				$summary = array_merge($summary, $after);
				$summary['complete'] = 0 === (int) $after['remainingUnits'];
				unset($summary['semanticSkipReason']);
				return $summary;
			}

			$final_progress = $this->get_attachment_conversion_unit_progress($units, $runtime_skipped_units);
			$summary = array_merge($summary, $final_progress);
			$summary['workCompletedThisRun'] = $completed_this_run;
			$summary['complete'] = 0 === (int) ($final_progress['remainingUnits'] ?? 0);
			$semantic_skip_reason = (string) ($summary['skippedReason'] ?? $final_progress['semanticSkipReason'] ?? '');
			$summary['skippedReason'] = '' !== $semantic_skip_reason
				? $semantic_skip_reason
				: (!empty($final_progress['skippedExisting']) ? 'already_optimized' : 'no_supported_work');
			$summary['alreadyOptimized'] = 'already_optimized' === $summary['skippedReason'];
			unset($summary['semanticSkipReason']);
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
				'skippedUnsupported'=> 0,
				'skippedReason'    => '',
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

					$semantic_skip_reason = $this->get_media_source_conversion_skip_reason($source_file, $single_format);
					if ('' !== $semantic_skip_reason) {
						$summary['workCompleted']++;
						$summary['skippedUnsupported']++;
						$summary['skippedReason'] = $semantic_skip_reason;
						$summary['skipDetail'] = $this->get_media_conversion_skip_detail($semantic_skip_reason);
						$summary['skippedFormat'] = $single_format;
						$summary['success'] = true;
						continue;
					}

					if ($this->source_file_matches_target_format($source_file, $single_format) || ($only_missing && $this->generated_variant_exists($source_file, $single_format))) {
						$summary['workCompleted']++;
						$summary['skippedExisting']++;
						if ('avif' === $single_format && !$this->source_file_matches_target_format($source_file, 'avif')) {
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
					} else {
						$failure = $this->get_last_media_conversion_failure();
						$skip_reason = (string) ($failure['skippedReason'] ?? '');
						if ('' !== $skip_reason) {
							$summary['success'] = true;
							$summary['skippedUnsupported']++;
							$summary['skippedReason'] = $skip_reason;
							$summary['skipDetail'] = (string) ($failure['skipDetail'] ?? $this->get_media_conversion_skip_detail($skip_reason));
							$summary['skippedFormat'] = $single_format;
						}
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

		private function supports_imagick_avif_color_profiles() {
			$report = $this->run_avif_encoder_self_test(false);
			return !empty($report['engines']['imagick']['passed']) && !empty($report['engines']['imagick']['colorProfilePassed']);
		}

		private function supports_imagick_avif_decode() {
			$report = $this->run_avif_encoder_self_test(false);
			return !empty($report['engines']['imagick']['avifDecodePassed']);
		}

		private function supports_imagick_avif_to_webp() {
			$report = $this->run_avif_encoder_self_test(false);
			return !empty($report['engines']['imagick']['avifToWebpPassed']);
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

		private function supports_gd_avif_decode() {
			$report = $this->run_avif_encoder_self_test(false);
			return !empty($report['engines']['gd']['avifDecodePassed']);
		}

		private function supports_gd_avif_to_webp() {
			$report = $this->run_avif_encoder_self_test(false);
			return !empty($report['engines']['gd']['avifToWebpPassed']);
		}

		private function supports_avif_source_to_webp() {
			return ($this->supports_imagick_avif_to_webp() || $this->supports_gd_avif_to_webp());
		}

		private function get_gd_webp_probe_fingerprint() {
			$gd = function_exists('gd_info') ? gd_info() : array();
			$payload = array(
				'schema' => 1,
				'phpVersion' => PHP_VERSION,
				'gdVersion' => is_array($gd) ? (string) ($gd['GD Version'] ?? '') : '',
				'imageWebp' => function_exists('imagewebp'),
				'imageCreateTrueColor' => function_exists('imagecreatetruecolor'),
				'contractVersion' => 1,
			);

			return substr(hash('sha256', (string) wp_json_encode($payload)), 0, 24);
		}

		private function read_gd_webp_probe_state() {
			if (!function_exists('ultracache_get_state_record_read_only')) {
				return array();
			}

			$record = ultracache_get_state_record_read_only(self::GD_WEBP_PROBE_STATE);
			$payload = is_array($record['payload'] ?? null) ? $record['payload'] : array();
			$result = is_array($payload['result'] ?? null) ? $payload['result'] : array();
			$fingerprint = sanitize_text_field((string) ($payload['fingerprint'] ?? ($result['fingerprint'] ?? '')));
			if (empty($result) || '' === $fingerprint || !hash_equals($this->get_gd_webp_probe_fingerprint(), $fingerprint)) {
				return array();
			}

			$retry_after = max(0, (int) ($result['retryAfter'] ?? 0));
			if (empty($result['supported']) && $retry_after > 0 && time() >= $retry_after) {
				return array();
			}

			$result['source'] = 'persistent';
			return $result;
		}

		private function persist_gd_webp_probe_state($supported, $error = '', $retry_after = 0) {
			if (!function_exists('ultracache_mutate_state_record')) {
				return false;
			}

			$result = array(
				'supported' => (bool) $supported,
				'error' => sanitize_text_field((string) $error),
				'testedAt' => time(),
				'retryAfter' => max(0, (int) $retry_after),
				'fingerprint' => $this->get_gd_webp_probe_fingerprint(),
				'source' => 'live',
			);
			$mutation = ultracache_mutate_state_record(
				self::GD_WEBP_PROBE_STATE,
				static function () use ($result) {
					return array(
						'schemaVersion' => 1,
						'recordedAt' => (int) $result['testedAt'],
						'fingerprint' => (string) $result['fingerprint'],
						'result' => $result,
					);
				},
				5,
				array()
			);

			return !empty($mutation['success']);
		}

		private function supports_gd_webp() {
			static $gd_webp_supported = null;

			if (null !== $gd_webp_supported) {
				return $gd_webp_supported;
			}

			$stored = $this->read_gd_webp_probe_state();
			if (is_array($stored) && array_key_exists('supported', $stored)) {
				$gd_webp_supported = !empty($stored['supported']);
				return $gd_webp_supported;
			}

			if (!function_exists('imagewebp') || !function_exists('imagecreatetruecolor')) {
				$gd_webp_supported = false;
				$this->persist_gd_webp_probe_state(false, __('GD imagewebp() is unavailable', 'ultracache'), 0);
				return false;
			}

			$tmp = $this->create_temp_file('ultracache-webp-test');
			if (!$tmp) {
				$gd_webp_supported = false;
				$this->persist_gd_webp_probe_state(false, __('Unable to create GD WebP probe file', 'ultracache'), time() + HOUR_IN_SECONDS);
				return false;
			}

			$test_file = $tmp . '.webp';
			ultracache_safe_unlink($tmp);

			$image = imagecreatetruecolor(2, 2);
			if (!$image) {
				$gd_webp_supported = false;
				$this->persist_gd_webp_probe_state(false, __('Unable to create GD WebP probe canvas', 'ultracache'), time() + HOUR_IN_SECONDS);
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

			$this->persist_gd_webp_probe_state(
				(bool) $gd_webp_supported,
				$gd_webp_supported ? '' : 'GD WebP probe did not produce a valid non-empty WebP file',
				$gd_webp_supported ? 0 : (time() + DAY_IN_SECONDS)
			);

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

		private function get_media_source_format($source_file) {
			$mime = $this->get_source_image_mime($source_file);
			if ('image/avif' === $mime) {
				return 'avif';
			}
			if ('image/webp' === $mime) {
				return 'webp';
			}
			if ('image/png' === $mime) {
				return 'png';
			}
			if ('image/jpeg' === $mime) {
				return 'jpeg';
			}
			return '';
		}

		private function source_file_matches_target_format($source_file, $target_format) {
			$target_format = strtolower(trim((string) $target_format));
			return in_array($target_format, array('avif', 'webp'), true)
				&& $target_format === $this->get_media_source_format($source_file);
		}

		private function is_source_file_supported_for_format($source_file, $target_format) {
			$target_format = strtolower(trim((string) $target_format));
			if ('avif' === $target_format) {
				return $this->is_allowed_source_file($source_file);
			}
			if ('webp' === $target_format) {
				return $this->is_webp_fallback_source_file($source_file);
			}
			return false;
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

			if (!(bool) preg_match('/\.(jpe?g|png|avif)$/i', (string) $source_file)) {
				return false;
			}

			$mime = $this->get_source_image_mime($source_file);
			if ('image/avif' === $mime) {
				return $this->supports_avif_source_to_webp();
			}

			return in_array($mime, array('image/jpeg', 'image/png'), true);
		}

		private function convert_webp_with_wp_image_editor($source_file, $dest_file, $quality) {
			if (!$this->ensure_media_source_decode_admitted($source_file, 'wordpress-image-editor')) {
				return false;
			}

			if ($this->media_source_requires_color_managed_encoder($source_file)) {
				return false;
			}

			/*
			 * WordPress image-editor implementations do not expose a portable,
			 * complete orientation 1..8 contract here. Oriented sources therefore
			 * fall through to the explicit Imagick/GD normalization paths below.
			 */
			if ($this->get_media_source_orientation($source_file) > 1) {
				return false;
			}

			if (!function_exists('wp_get_image_editor')) {
				$this->record_media_conversion_failure('wordpress-image-editor', 'editor_unavailable', __('The WordPress image editor API is unavailable.', 'ultracache'), 'encode');
				return false;
			}

			$temp_file = $this->create_media_output_temp_path($dest_file, 'webp');
			if (!$temp_file) {
				$this->record_media_conversion_failure('wordpress-image-editor', 'temporary_output_unavailable', __('A same-directory temporary WebP path could not be created.', 'ultracache'), 'storage');
				return false;
			}

			$editor = wp_get_image_editor($source_file);
			if (is_wp_error($editor)) {
				$this->record_media_conversion_failure('wordpress-image-editor', (string) $editor->get_error_code(), $editor->get_error_message(), 'decode');
				$this->cleanup_media_output_temp_file($temp_file);
				return false;
			}
			if (!is_object($editor)) {
				$this->record_media_conversion_failure('wordpress-image-editor', 'editor_unavailable', __('WordPress could not create an image editor for the source file.', 'ultracache'), 'decode');
				$this->cleanup_media_output_temp_file($temp_file);
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
				$saved = $editor->save($temp_file, 'image/webp');
			} catch (\Throwable $e) {
				$this->record_media_conversion_failure('wordpress-image-editor', 'exception', $e->getMessage(), 'encode');
				$this->cleanup_media_output_temp_file($temp_file);
				return false;
			} finally {
				restore_error_handler();
			}

			if (is_wp_error($saved)) {
				$this->record_media_conversion_failure('wordpress-image-editor', (string) $saved->get_error_code(), $saved->get_error_message(), 'encode');
				$this->cleanup_media_output_temp_file($temp_file);
				return false;
			}

			$saved_path = !empty($saved['path']) ? (string) $saved['path'] : '';
			if ('' === $saved_path || !$this->media_output_paths_match($saved_path, $temp_file)) {
				$this->record_media_conversion_failure('wordpress-image-editor', 'unexpected_output_path', __('The WordPress image editor wrote outside the requested temporary output path.', 'ultracache'), 'storage');
				$this->cleanup_media_output_temp_file($temp_file);
				return false;
			}

			return $this->commit_media_output_temp_file($temp_file, $dest_file, 'webp', 'wordpress-image-editor', $source_file);
		}

		private function resize_media_imagick_image_to_max_side($image, $max_side) {
			$max_side = max(0, min(8192, absint($max_side)));
			if (0 === $max_side) {
				return true;
			}
			if (!is_object($image) || !method_exists($image, 'getImageWidth') || !method_exists($image, 'getImageHeight')) {
				return false;
			}
			$width = max(0, (int) $image->getImageWidth());
			$height = max(0, (int) $image->getImageHeight());
			if ($width <= 0 || $height <= 0) {
				return false;
			}
			if (max($width, $height) <= $max_side) {
				return true;
			}
			if (method_exists($image, 'thumbnailImage')) {
				return false !== $image->thumbnailImage($max_side, $max_side, true);
			}
			if (method_exists($image, 'resizeImage') && defined('Imagick::FILTER_LANCZOS')) {
				return false !== $image->resizeImage($max_side, $max_side, Imagick::FILTER_LANCZOS, 1, true);
			}
			return false;
		}

		private function convert_with_imagick($source_file, $dest_file, $format, $quality, $max_side = 0) {
			$admission = $this->ensure_media_source_decode_admitted($source_file, 'imagick');
			if (false === $admission) {
				return false;
			}

			$image = null;
			$temp_file = '';
			$resource_state = array('applied' => false, 'previous' => array());
			$stage = 'preflight';
			try {
				$image = new Imagick();
				$resource_state = $this->apply_media_imagick_resource_limits($image, $admission);
				if (false === $resource_state) {
					$this->record_media_conversion_failure('imagick', 'resource_limit_failed', __('Imagick resource limits could not be applied before decoding the source image.', 'ultracache'), 'preflight');
					return false;
				}

				$stage = 'decode';
				if (!$image->readImage($source_file)) {
					$this->record_media_conversion_failure('imagick', 'source_decode_failed', __('Imagick could not read the source image.', 'ultracache'), 'decode');
					return false;
				}

				$stage = 'normalize';
				if (!$this->normalize_media_imagick_orientation($image, $source_file)) {
					$this->record_media_conversion_failure('imagick', 'orientation_normalization_failed', __('Imagick could not normalize the source image orientation.', 'ultracache'), 'normalize');
					return false;
				}

				if (!$this->resize_media_imagick_image_to_max_side($image, $max_side)) {
					$this->record_media_conversion_failure('imagick', 'resize_failed', __('Imagick could not resize the uploaded image within the configured maximum side.', 'ultracache'), 'normalize');
					return false;
				}

				$profile_inspection = $this->inspect_media_source_color_profile($source_file);
				$ignore_color_profile_preservation = $this->should_ignore_media_color_profile_preservation();
				$color_profiles = $ignore_color_profile_preservation ? array() : $this->capture_media_imagick_color_profiles($image);
				if (false === $color_profiles) {
					if (empty($profile_inspection['determinate']) || !empty($profile_inspection['hasProfile'])) {
						$this->record_media_conversion_skip('color_profile_unreadable', __('Imagick could not read the source image color profile safely.', 'ultracache'));
						return false;
					}
					$color_profiles = array();
				}
				if (!$ignore_color_profile_preservation && !empty($profile_inspection['hasProfile']) && empty($color_profiles)) {
					$this->record_media_conversion_skip('color_profile_unreadable', __('The source container declares an embedded color profile, but Imagick did not expose it.', 'ultracache'));
					return false;
				}

				/*
				 * Preserve the source's native channel layout. Imagick already keeps a
				 * real PNG/WebP alpha channel when one exists. Activating alpha on an
				 * opaque JPEG can make some AVIF delegates emit corrupted pixel data.
				 */
				$stage = 'encode';
				$image->setImageFormat($format);
				$image->setImageCompressionQuality((int) $quality);

				if (!$this->strip_and_restore_media_imagick_color_profiles($image, $color_profiles)) {
					$this->record_media_conversion_skip('color_profile_restore_failed', __('Imagick could not restore the source color profile after metadata cleanup.', 'ultracache'));
					return false;
				}

				$temp_file = $this->create_media_output_temp_path($dest_file, $format);
				if (!$temp_file) {
					$this->record_media_conversion_failure('imagick', 'temporary_output_unavailable', __('A same-directory temporary image path could not be created.', 'ultracache'), 'storage');
					return false;
				}

				$result = $image->writeImage($temp_file);
				if (!$result) {
					$this->record_media_conversion_failure('imagick', 'write_failed', __('Imagick could not write the generated image.', 'ultracache'), 'encode');
					$this->cleanup_media_output_temp_file($temp_file);
				} elseif (!empty($color_profiles) && !$this->verify_media_imagick_output_color_profiles($temp_file, $color_profiles, $admission)) {
					/*
					 * Some delegates silently discard ICC/ICM metadata. Convert the already
					 * decoded pixels through the bundled sRGB profile and retry without an
					 * embedded profile; browsers then interpret the output as sRGB.
					 */
					$this->cleanup_media_output_temp_file($temp_file);
					$temp_file = '';
					if (!$this->convert_media_imagick_image_to_srgb($image, $color_profiles)) {
						$this->record_media_conversion_skip('color_profile_not_preserved', __('The active Imagick delegate discarded the source color profile and could not convert the pixels safely to sRGB.', 'ultracache'));
						return false;
					}
					$temp_file = $this->create_media_output_temp_path($dest_file, $format);
					if (!$temp_file) {
						$this->record_media_conversion_failure('imagick', 'temporary_output_unavailable', __('A same-directory temporary image path could not be created for the sRGB fallback.', 'ultracache'), 'storage');
						return false;
					}
					$result = $image->writeImage($temp_file);
					if (!$result) {
						$this->record_media_conversion_skip('color_profile_srgb_encode_failed', __('Imagick could not encode the color-managed sRGB fallback.', 'ultracache'));
						$this->cleanup_media_output_temp_file($temp_file);
						return false;
					}
					$result = $this->commit_media_output_temp_file($temp_file, $dest_file, $format, 'imagick-srgb', $source_file);
				} else {
					$result = $this->commit_media_output_temp_file($temp_file, $dest_file, $format, 'imagick', $source_file);
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
				ultracache_debug_log('imagick conversion failed', array('format' => strtoupper($format), 'stage' => $stage, 'error' => $e->getMessage()));
				$this->record_media_conversion_failure('imagick', 'exception', $e->getMessage(), $stage);
				if ('avif' === $format) {
					$this->update_media_diagnostic_state(array(
						'lastAvifEncodeEngine' => 'imagick',
						'lastAvifEncodeError' => $e->getMessage(),
						'lastAvifEncodeFile' => (string) $source_file,
						'lastAvifEncodeAt' => time(),
					));
				}
				return false;
			} finally {
				if (is_object($image)) {
					if (!empty($resource_state['applied']) && !empty($resource_state['previous']) && is_array($resource_state['previous'])) {
						$this->restore_media_imagick_resource_limits($image, $resource_state['previous']);
					}
					if (method_exists($image, 'clear')) {
						$image->clear();
					}
					if (method_exists($image, 'destroy')) {
						$image->destroy();
					}
				}
				if ('' !== $temp_file) {
					$this->cleanup_media_output_temp_file($temp_file);
				}
			}
		}

		private function convert_with_gd($source_file, $dest_file, $format, $quality) {
			if (!$this->ensure_media_source_decode_admitted($source_file, 'gd')) {
				return false;
			}

			if ($this->media_source_requires_color_managed_encoder($source_file)) {
				$this->record_media_conversion_skip('color_profile_requires_imagick', $this->get_color_profile_encoder_skip_message());
				return false;
			}

			if ('avif' === $format && !$this->supports_gd_avif()) {
				return false;
			}

			if ('webp' === $format && !$this->supports_gd_webp()) {
				return false;
			}

			$source_mime = $this->get_source_image_mime($source_file);
			if (!in_array($source_mime, array('image/jpeg', 'image/png', 'image/webp', 'image/avif'), true)) {
				$this->record_media_conversion_failure('gd', 'source_type_unknown', __('GD could not determine the source image type.', 'ultracache'), 'decode');
				return false;
			}

			$image = null;

			switch ($source_mime) {
				case 'image/jpeg':
					$image = function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($source_file) : null;
					break;

				case 'image/png':
					$image = function_exists('imagecreatefrompng') ? @imagecreatefrompng($source_file) : null;
					break;

				case 'image/webp':
					$image = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source_file) : null;
					break;

				case 'image/avif':
					if ('webp' === $format && $this->supports_gd_avif_to_webp() && function_exists('imagecreatefromavif')) {
						$image = @imagecreatefromavif($source_file);
					}
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
			$source_can_have_alpha = in_array($source_mime, array('image/png', 'image/webp', 'image/avif'), true);
			if ($source_can_have_alpha && function_exists('imagealphablending')) {
				imagealphablending($image, false);
			}

			if ($source_can_have_alpha && function_exists('imagesavealpha')) {
				imagesavealpha($image, true);
			}

			$orientation = $this->get_media_source_orientation($source_file);
			if (!$this->normalize_media_gd_orientation($image, $orientation, $source_can_have_alpha)) {
				imagedestroy($image);
				$this->record_media_conversion_failure('gd', 'orientation_normalization_failed', __('GD could not normalize the source image orientation.', 'ultracache'), 'normalize');
				return false;
			}

			$gd_error = '';
			$temp_file = $this->create_media_output_temp_path($dest_file, $format);
			if (!$temp_file) {
				imagedestroy($image);
				$this->record_media_conversion_failure('gd', 'temporary_output_unavailable', __('A same-directory temporary image path could not be created.', 'ultracache'), 'storage');
				return false;
			}

			$result = false;

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped handler captures GD/Imagick encoder warnings and is restored immediately.
			set_error_handler(static function($severity, $message) use (&$gd_error) {
				$gd_error = (string) $message;
				return true;
			});
			try {
				if ('avif' === $format) {
					$result = imageavif($image, $temp_file, (int) $quality);
				} elseif ('webp' === $format) {
					$result = imagewebp($image, $temp_file, (int) $quality);
				}
			} catch (\Throwable $e) {
				$gd_error = $e->getMessage();
				$result   = false;
			} finally {
				restore_error_handler();
			}

			imagedestroy($image);

			$ok = $result && $this->commit_media_output_temp_file($temp_file, $dest_file, $format, 'gd', $source_file);

			if (!$ok) {
				$this->cleanup_media_output_temp_file($temp_file);

				if (!$result) {
					if ($gd_error) {
						ultracache_debug_log('gd conversion failed', array('format' => strtoupper($format), 'error' => $gd_error));
					}
					$this->record_media_conversion_failure('gd', $gd_error ? 'encoder_error' : 'write_failed', $gd_error ?: __('GD could not write the generated image.', 'ultracache'), 'encode');
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
