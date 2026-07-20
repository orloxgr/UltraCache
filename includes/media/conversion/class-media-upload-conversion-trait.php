<?php
/**
 * UltraCache upload-time image conversion workflow.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Upload_Conversion_Trait
{
		/**
		 * Convert the actual uploaded file during the WordPress upload flow.
		 *
		 * @param array<string,mixed> $upload  WordPress upload result.
		 * @param string             $context Upload context.
		 * @return array<string,mixed>
		 */
		public function maybe_convert_uploaded_image_file($upload, $context = 'upload') {
			unset($context);

			if (!is_array($upload) || !empty($upload['error'])) {
				return $upload;
			}

			if (!$this->is_upload_conversion_enabled()) {
				return $upload;
			}

			$source_file = isset($upload['file']) ? (string) $upload['file'] : '';
			if ('' === $source_file || !is_readable($source_file)) {
				return $this->fail_uploaded_image_conversion($upload, __('UltraCache upload conversion could not read the uploaded file.', 'ultracache'), $source_file);
			}

			$source_mime = $this->get_uploaded_image_mime($source_file, $upload);
			if ('' === $source_mime || 0 !== strpos($source_mime, 'image/')) {
				return $upload;
			}

			// SVG is already a vector delivery format. Keep the WordPress upload
			// result unchanged instead of treating it as a failed raster conversion.
			if ('image/svg+xml' === $source_mime) {
				return $upload;
			}

			if (!in_array($source_mime, array('image/jpeg', 'image/png', 'image/webp', 'image/avif'), true)) {
				return $this->fail_uploaded_image_conversion(
					$upload,
					sprintf(
						/* translators: %s: MIME type. */
						__('UltraCache upload conversion does not support %s files.', 'ultracache'),
						$source_mime
					),
					$source_file
				);
			}

			$target_format = $this->resolve_upload_conversion_target_format();
			if (!in_array($target_format, array('avif', 'webp'), true)) {
				return $this->fail_uploaded_image_conversion($upload, __('UltraCache upload conversion could not resolve the requested output format.', 'ultracache'), $source_file);
			}

			if ('avif' === $target_format && !$this->supports_avif()) {
				return $this->fail_uploaded_image_conversion($upload, __('UltraCache upload conversion requires a verified AVIF encoder for the selected output format.', 'ultracache'), $source_file);
			}

			if ('webp' === $target_format && !$this->supports_webp()) {
				return $this->fail_uploaded_image_conversion($upload, __('UltraCache upload conversion requires a WebP encoder for the selected output format.', 'ultracache'), $source_file);
			}

			$result = $this->convert_uploaded_image_with_existing_media_converter($upload, $source_file, $source_mime, $target_format, $this->get_upload_conversion_max_side());
			if (is_wp_error($result)) {
				$error_data = $result->get_error_data();
				return $this->fail_uploaded_image_conversion($upload, $result->get_error_message(), $source_file, is_array($error_data) ? $error_data : array());
			}

			return is_array($result) ? $result : $upload;
		}

		private function is_upload_conversion_enabled() {
			if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
				$settings = Ultra_Cache_WP::get_settings();
				if (array_key_exists('media_upload_conversion_enabled', $settings)) {
					return !empty($settings['media_upload_conversion_enabled']);
				}
			}

			$settings = get_option(defined('ULTRACACHE_SETTINGS_KEY') ? ULTRACACHE_SETTINGS_KEY : 'ultracache_settings', array());
			return !empty($settings['mediaUploadConversionEnabled']);
		}

		private function get_upload_conversion_max_side() {
			$value = 1920;

			if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
				$settings = Ultra_Cache_WP::get_settings();
				if (isset($settings['image_upload_max_side'])) {
					$value = $settings['image_upload_max_side'];
				}
			} else {
				$settings = get_option(defined('ULTRACACHE_SETTINGS_KEY') ? ULTRACACHE_SETTINGS_KEY : 'ultracache_settings', array());
				if (isset($settings['imageUploadMaxSide'])) {
					$value = $settings['imageUploadMaxSide'];
				}
			}

			return max(1, min(8192, absint($value)));
		}

		private function resolve_upload_conversion_target_format() {
			$mode = 'webp';

			if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
				$settings = Ultra_Cache_WP::get_settings();
				if (isset($settings['media_output_mode'])) {
					$mode = strtolower(trim((string) $settings['media_output_mode']));
				}
			} else {
				$settings = get_option(defined('ULTRACACHE_SETTINGS_KEY') ? ULTRACACHE_SETTINGS_KEY : 'ultracache_settings', array());
				if (isset($settings['mediaOutputMode'])) {
					$mode = strtolower(trim((string) $settings['mediaOutputMode']));
				}
			}

			if ('avif' === $mode) {
				return 'avif';
			}

			return 'webp';
		}

		/**
		 * @param array<string,mixed> $upload Upload result.
		 * @return array<string,mixed>|WP_Error
		 */
		private function convert_uploaded_image_with_existing_media_converter(array $upload, $source_file, $source_mime, $target_format, $max_side) {
			$target_format = strtolower((string) $target_format);
			$target_mime   = ('avif' === $target_format) ? 'image/avif' : 'image/webp';
			$target_ext    = ('avif' === $target_format) ? 'avif' : 'webp';
			$max_side      = max(1, min(8192, absint($max_side)));

			$source_size = $this->get_wp_image_editor_size($source_file);
			if (is_wp_error($source_size)) {
				return new WP_Error('ultracache_upload_image_size_unavailable', $source_size->get_error_message());
			}

			$width  = isset($source_size['width']) ? absint($source_size['width']) : 0;
			$height = isset($source_size['height']) ? absint($source_size['height']) : 0;
			if ($width <= 0 || $height <= 0) {
				return new WP_Error('ultracache_upload_image_size_unavailable', __('UltraCache upload conversion could not read the uploaded image dimensions.', 'ultracache'));
			}

			if ($source_mime === $target_mime && max($width, $height) <= $max_side) {
				$upload['type'] = $target_mime;
				return $upload;
			}

			$source_ext = strtolower((string) pathinfo($source_file, PATHINFO_EXTENSION));
			$dest_file  = $source_file;
			if ($source_ext !== $target_ext) {
				$dest_file = $this->get_upload_conversion_destination_file($source_file, $target_ext);
				if ('' === $dest_file) {
					return new WP_Error('ultracache_upload_destination_unavailable', __('UltraCache upload conversion could not resolve a destination filename.', 'ultracache'));
				}
			}

			$prepared_source = $this->prepare_upload_conversion_source_file($source_file, $source_mime, $width, $height, $max_side);
			if (is_wp_error($prepared_source)) {
				return $prepared_source;
			}

			$conversion_source = isset($prepared_source['file']) ? (string) $prepared_source['file'] : $source_file;
			$temp_source       = !empty($prepared_source['temporary']);
			$prepared_width    = isset($prepared_source['width']) ? absint($prepared_source['width']) : $width;
			$prepared_height   = isset($prepared_source['height']) ? absint($prepared_source['height']) : $height;
			$resize_editor     = isset($prepared_source['editor_class']) ? (string) $prepared_source['editor_class'] : '';

			$this->reset_last_media_conversion_failure($conversion_source, $target_format);
			$this->reset_media_diagnostic_state();

			$encoded = $this->convert_upload_image_with_existing_encoder($conversion_source, $dest_file, $target_format);
			if (!$encoded) {
				$failure = $this->get_last_media_conversion_failure();
				$details = array(
					'conversion_stage'    => isset($failure['failureStage']) && '' !== (string) $failure['failureStage'] ? (string) $failure['failureStage'] : 'encode',
					'source_basename'     => wp_basename($source_file),
					'source_mime'         => $source_mime,
					'source_dimensions'   => $width . 'x' . $height,
					'target_format'       => $target_format,
					'target_mime'         => $target_mime,
					'max_side'            => $max_side,
					'editor_class'        => $resize_editor,
					'destination_basename'=> wp_basename($dest_file),
					'output_basename'     => wp_basename($dest_file),
					'output_bytes'        => (is_readable($dest_file) ? $this->get_filesize($dest_file) : 0),
				);
				if (!empty($failure['failureCode'])) {
					$details['encoder_failure_code'] = (string) $failure['failureCode'];
				}
				if (!empty($failure['failureDetail'])) {
					$details['encoder_failure_detail'] = (string) $failure['failureDetail'];
				}
				if (!empty($failure['encoderAttempts']) && is_array($failure['encoderAttempts'])) {
					$details['encoder_attempts'] = $failure['encoderAttempts'];
				}
				ultracache_debug_log('upload conversion encode failed', $details + array(
					'source_path'      => $source_file,
					'conversion_source'=> $conversion_source,
					'destination_path' => $dest_file,
				));

				if ($temp_source && is_readable($conversion_source)) {
					wp_delete_file($conversion_source);
				}
				if ($dest_file !== $source_file && is_readable($dest_file)) {
					wp_delete_file($dest_file);
				}

				return new WP_Error(
					'ultracache_upload_image_encode_failed',
					$this->format_upload_conversion_failure_message(__('UltraCache could not encode the uploaded image with the existing media converter.', 'ultracache'), $details),
					$details
				);
			}

			$this->optimized_storage_harden_upload_permissions($dest_file, 'file');

			$saved_size = is_readable($dest_file) ? $this->get_filesize($dest_file) : 0;
			$saved_mime = $this->get_file_image_mime($dest_file);
			if ($saved_size <= 0 || $saved_mime !== $target_mime) {
				$validation_details = array(
					'conversion_stage'     => ($saved_size <= 0) ? 'output_validation' : 'output_mime_validation',
					'source_basename'      => wp_basename($source_file),
					'source_mime'          => $source_mime,
					'source_dimensions'    => $width . 'x' . $height,
					'target_format'        => $target_format,
					'target_mime'          => $target_mime,
					'max_side'             => $max_side,
					'editor_class'         => $resize_editor,
					'output_basename'      => wp_basename($dest_file),
					'output_bytes'         => $saved_size,
					'output_detected_mime' => $saved_mime,
				);
				ultracache_debug_log('upload conversion output validation failed after existing encoder', $validation_details + array('output_path' => $dest_file));
				if ($temp_source && is_readable($conversion_source)) {
					wp_delete_file($conversion_source);
				}
				if ($dest_file !== $source_file && is_readable($dest_file)) {
					wp_delete_file($dest_file);
				}

				return new WP_Error(
					'ultracache_upload_image_invalid_output',
					$this->format_upload_conversion_failure_message(__('UltraCache encoded the upload, but the output file failed final validation.', 'ultracache'), $validation_details),
					$validation_details
				);
			}

			if ($temp_source && is_readable($conversion_source)) {
				wp_delete_file($conversion_source);
			}

			if ($dest_file !== $source_file && is_readable($source_file)) {
				wp_delete_file($source_file);
			}

			$upload['file'] = $dest_file;
			$upload['url']  = $this->get_upload_conversion_url($dest_file, $upload);
			$upload['type'] = $target_mime;

			return $upload;
		}

		/**
		 * Prepare the source file for upload conversion. Resizing is the only step
		 * that uses the WordPress image editor; final AVIF/WebP encoding is handled
		 * by the existing UltraCache converter chain.
		 *
		 * @return array<string,mixed>|WP_Error
		 */
		private function prepare_upload_conversion_source_file($source_file, $source_mime, $width, $height, $max_side) {
			$width    = absint($width);
			$height   = absint($height);
			$max_side = max(1, min(8192, absint($max_side)));

			if ($width <= 0 || $height <= 0 || max($width, $height) <= $max_side) {
				return array(
					'file'        => (string) $source_file,
					'temporary'   => false,
					'width'       => $width,
					'height'      => $height,
					'editor_class'=> '',
				);
			}

			if (!function_exists('wp_get_image_editor')) {
				return new WP_Error('ultracache_upload_image_editor_unavailable', __('UltraCache upload conversion cannot resize this upload because the WordPress image editor API is unavailable.', 'ultracache'));
			}

			$editor = wp_get_image_editor($source_file);
			if (is_wp_error($editor)) {
				return new WP_Error(
					'ultracache_upload_image_editor_unavailable',
					sprintf(
						/* translators: %s: image editor error message. */
						__('UltraCache upload conversion failed before resize: %s', 'ultracache'),
						sanitize_text_field($editor->get_error_message())
					)
				);
			}

			$resized = $editor->resize($max_side, $max_side, false);
			if (is_wp_error($resized)) {
				return new WP_Error(
					'ultracache_upload_image_resize_failed',
					sprintf(
						/* translators: %s: image editor error message. */
						__('UltraCache upload conversion resize failed: %s', 'ultracache'),
						sanitize_text_field($resized->get_error_message())
					)
				);
			}

			$dir = dirname((string) $source_file);
			$is_writable = wp_is_writable($dir);
			if ('' === $dir || !is_dir($dir) || !$is_writable) {
				return new WP_Error('ultracache_upload_resize_destination_unavailable', __('UltraCache upload conversion could not create the resized intermediate file in the upload directory.', 'ultracache'));
			}

			$source_ext = strtolower((string) pathinfo((string) $source_file, PATHINFO_EXTENSION));
			if ('' === $source_ext) {
				$source_ext = ('image/png' === $source_mime) ? 'png' : (('image/webp' === $source_mime) ? 'webp' : 'jpg');
			}

			$base = sanitize_file_name((string) pathinfo((string) $source_file, PATHINFO_FILENAME));
			if ('' === $base) {
				$base = 'image';
			}

			$temp_file = trailingslashit($dir) . wp_unique_filename($dir, $base . '-ultracache-upload-resized.' . $source_ext);
			$saved = $editor->save($temp_file, $source_mime);
			if (is_wp_error($saved)) {
				return new WP_Error(
					'ultracache_upload_image_resize_save_failed',
					sprintf(
						/* translators: %s: image editor error message. */
						__('UltraCache upload conversion could not save the resized intermediate image: %s', 'ultracache'),
						sanitize_text_field($saved->get_error_message())
					)
				);
			}

			$prepared_file = isset($saved['path']) && '' !== (string) $saved['path'] ? (string) $saved['path'] : $temp_file;
			$this->optimized_storage_harden_upload_permissions($prepared_file, 'file');
			$prepared_size = $this->get_wp_image_editor_size($prepared_file);
			if (is_wp_error($prepared_size)) {
				if (is_readable($prepared_file)) {
					wp_delete_file($prepared_file);
				}
				return new WP_Error('ultracache_upload_resized_image_invalid', __('UltraCache upload conversion created a resized intermediate image, but its dimensions could not be verified.', 'ultracache'));
			}

			return array(
				'file'        => $prepared_file,
				'temporary'   => true,
				'width'       => isset($prepared_size['width']) ? absint($prepared_size['width']) : 0,
				'height'      => isset($prepared_size['height']) ? absint($prepared_size['height']) : 0,
				'editor_class'=> is_object($editor) ? get_class($editor) : '',
			);
		}

		private function convert_upload_image_with_existing_encoder($source_file, $dest_file, $target_format) {
			$target_format = strtolower((string) $target_format);
			$success = false;
			$quality = $this->get_media_encoder_quality($target_format);

			if ('avif' === $target_format) {
				if ($this->supports_imagick_avif()) {
					$success = $this->convert_with_imagick($source_file, $dest_file, 'avif', $quality);
				}

				if (!$success && $this->supports_gd_avif()) {
					$success = $this->convert_with_gd($source_file, $dest_file, 'avif', $quality);
				}
			} elseif ('webp' === $target_format) {
				$success = $this->convert_webp_with_wp_image_editor($source_file, $dest_file, $quality);

				if (!$success && $this->supports_imagick_webp()) {
					$success = $this->convert_with_imagick($source_file, $dest_file, 'webp', $quality);
				}

				if (!$success && $this->supports_gd_webp()) {
					$success = $this->convert_with_gd($source_file, $dest_file, 'webp', $quality);
				}
			}

			if (!$success && empty($this->last_media_conversion_failure['failureCode'])) {
				$this->record_media_conversion_failure('encoder', 'all_encoders_failed', __('All available encoders failed to generate the requested image format.', 'ultracache'), 'encode');
			}

			return (bool) $success;
		}

		private function get_upload_conversion_destination_file($source_file, $target_ext) {
			$dir = dirname((string) $source_file);
			$is_writable = wp_is_writable($dir);
			if ('' === $dir || !is_dir($dir) || !$is_writable) {
				return '';
			}

			$base = (string) pathinfo($source_file, PATHINFO_FILENAME);
			$base = sanitize_file_name('' !== $base ? $base : 'image');
			$filename = wp_unique_filename($dir, $base . '.' . $target_ext);
			return trailingslashit($dir) . $filename;
		}

		/**
		 * Build candidate paths from the WordPress image editor save result.
		 *
		 * WordPress normally returns the final absolute path in $saved['path'], but
		 * some editor implementations can still write the requested destination while
		 * returning sparse metadata. Validate the real written file without changing
		 * the requested output format or silently falling back to the original upload.
		 *
		 * @param array<string,mixed> $saved     Save result.
		 * @param string              $dest_file Requested destination path.
		 * @return string[]
		 */
		private function get_upload_conversion_saved_file_candidates(array $saved, $dest_file) {
			$candidates = array();
			if (isset($saved['path']) && '' !== (string) $saved['path']) {
				$candidates[] = wp_normalize_path((string) $saved['path']);
			}

			$dest_file = wp_normalize_path((string) $dest_file);
			if ('' !== $dest_file) {
				$candidates[] = $dest_file;
			}

			if (isset($saved['file']) && '' !== (string) $saved['file'] && '' !== $dest_file) {
				$candidates[] = trailingslashit(dirname($dest_file)) . wp_basename((string) $saved['file']);
			}

			$unique = array();
			foreach ($candidates as $candidate) {
				$candidate = wp_normalize_path((string) $candidate);
				if ('' !== $candidate && !in_array($candidate, $unique, true)) {
					$unique[] = $candidate;
				}
			}

			return $unique;
		}

		/**
		 * @param string[] $candidates Candidate output paths.
		 * @return array<int,array<string,mixed>>
		 */
		private function get_upload_conversion_candidate_states(array $candidates) {
			$states = array();
			foreach ($candidates as $candidate) {
				$candidate = (string) $candidate;
				$states[] = array(
					'basename'    => '' !== $candidate ? wp_basename($candidate) : '',
					'exists'      => ('' !== $candidate && file_exists($candidate)) ? 1 : 0,
					'is_readable' => ('' !== $candidate && is_readable($candidate)) ? 1 : 0,
					'bytes'       => ('' !== $candidate && is_readable($candidate)) ? $this->get_filesize($candidate) : 0,
				);
			}

			return $states;
		}

		private function get_upload_conversion_url($saved_path, array $upload) {
			$url = function_exists('ultracache_public_url_from_local_path') ? ultracache_public_url_from_local_path($saved_path) : '';
			if ('' !== $url) {
				return $url;
			}

			$previous_url = isset($upload['url']) ? (string) $upload['url'] : '';
			if ('' !== $previous_url) {
				return trailingslashit(dirname($previous_url)) . wp_basename($saved_path);
			}

			return '';
		}

		private function fail_uploaded_image_conversion(array $upload, $message, $source_file = '', array $details = array()) {
			$source_file = (string) $source_file;
			$details = $this->get_upload_conversion_failure_details($upload, $source_file, $details);
			ultracache_debug_log('upload conversion failed', $details + array('message' => (string) $message));

			if ('' !== $source_file && is_readable($source_file)) {
				wp_delete_file($source_file);
			}

			$upload['error'] = sanitize_text_field($this->format_upload_conversion_failure_message((string) $message, $details));
			return $upload;
		}

		private function get_upload_conversion_failure_details(array $upload, $source_file, array $details = array()) {
			$source_file = (string) $source_file;
			if (!isset($details['source_basename'])) {
				$details['source_basename'] = '' !== $source_file ? wp_basename($source_file) : '';
			}

			if (!isset($details['upload_type']) && isset($upload['type'])) {
				$details['upload_type'] = (string) $upload['type'];
			}

			if (!isset($details['detected_source_mime']) && '' !== $source_file && is_readable($source_file)) {
				$details['detected_source_mime'] = $this->get_file_image_mime($source_file);
			}

			if (!isset($details['target_format'])) {
				$details['target_format'] = $this->resolve_upload_conversion_target_format();
			}

			if (!isset($details['max_side'])) {
				$details['max_side'] = $this->get_upload_conversion_max_side();
			}

			if (!isset($details['source_readable'])) {
				$details['source_readable'] = ('' !== $source_file && is_readable($source_file)) ? 1 : 0;
			}

			return $details;
		}

		private function format_upload_conversion_failure_message($message, array $details = array()) {
			$message = trim((string) $message);
			if ('' === $message) {
				$message = __('UltraCache upload conversion failed.', 'ultracache');
			}

			if (false !== strpos($message, 'Details:')) {
				return $message;
			}

			$detail_parts = array();
			$labels = array(
				'conversion_stage'        => 'stage',
				'source_basename'         => 'source',
				'source_mime'             => 'source_mime',
				'detected_source_mime'    => 'detected_source_mime',
				'upload_type'             => 'upload_type',
				'source_dimensions'       => 'source_dimensions',
				'target_format'           => 'target_format',
				'target_mime'             => 'target_mime',
				'max_side'                => 'max_side',
				'editor_class'            => 'editor',
				'destination_basename'    => 'destination',
				'saved_path_basename'     => 'returned_path',
				'saved_file'              => 'returned_file',
				'saved_mime'              => 'returned_mime',
				'saved_filesize_returned' => 'returned_filesize',
				'output_basename'         => 'output',
				'output_bytes'            => 'output_bytes',
				'output_detected_mime'    => 'output_mime',
				'encoder_failure_code'    => 'encoder_code',
				'encoder_failure_detail'  => 'encoder_detail',
				'source_readable'         => 'source_readable',
			);

			foreach ($labels as $key => $label) {
				if (!array_key_exists($key, $details)) {
					continue;
				}
				$value = $details[$key];
				if (is_array($value) || is_object($value)) {
					continue;
				}
				$value = trim((string) $value);
				if ('' !== $value) {
					$detail_parts[] = $label . '=' . $value;
				}
			}

			if (isset($details['encoder_attempts']) && is_array($details['encoder_attempts'])) {
				$attempt_parts = array();
				foreach ($details['encoder_attempts'] as $attempt) {
					if (!is_array($attempt)) {
						continue;
					}
					$attempt_parts[] = sprintf(
						'%s code=%s stage=%s message=%s',
						isset($attempt['engine']) ? sanitize_key((string) $attempt['engine']) : '',
						isset($attempt['code']) ? sanitize_key((string) $attempt['code']) : '',
						isset($attempt['stage']) ? sanitize_key((string) $attempt['stage']) : '',
						isset($attempt['message']) ? sanitize_text_field((string) $attempt['message']) : ''
					);
				}
				if (!empty($attempt_parts)) {
					$detail_parts[] = 'encoder_attempts=' . implode(' | ', $attempt_parts);
				}
			}

			if (isset($details['candidate_states']) && is_array($details['candidate_states'])) {
				$candidate_parts = array();
				foreach ($details['candidate_states'] as $candidate_state) {
					if (!is_array($candidate_state)) {
						continue;
					}
					$candidate_parts[] = sprintf(
						'%s exists=%s readable=%s bytes=%s',
						isset($candidate_state['basename']) ? (string) $candidate_state['basename'] : '',
						isset($candidate_state['exists']) ? (string) $candidate_state['exists'] : '0',
						isset($candidate_state['is_readable']) ? (string) $candidate_state['is_readable'] : '0',
						isset($candidate_state['bytes']) ? (string) $candidate_state['bytes'] : '0'
					);
				}
				if (!empty($candidate_parts)) {
					$detail_parts[] = 'candidates=' . implode(' | ', $candidate_parts);
				}
			}

			return empty($detail_parts) ? $message : $message . ' Details: ' . implode('; ', $detail_parts) . '.';
		}

		private function get_uploaded_image_mime($source_file, array $upload) {
			$mime = $this->get_file_image_mime($source_file);
			if ('' !== $mime) {
				return $mime;
			}

			return isset($upload['type']) ? strtolower(trim((string) $upload['type'])) : '';
		}

		private function get_file_image_mime($file) {
			$mime = function_exists('wp_get_image_mime') ? wp_get_image_mime((string) $file) : '';
			return is_string($mime) ? strtolower(trim($mime)) : '';
		}

		private function get_wp_image_editor_size($source_file) {
			$editor = wp_get_image_editor($source_file);
			if (is_wp_error($editor)) {
				return $editor;
			}

			$size = $editor->get_size();
			$width = isset($size['width']) ? absint($size['width']) : 0;
			$height = isset($size['height']) ? absint($size['height']) : 0;
			if ($width <= 0 || $height <= 0) {
				return new WP_Error('ultracache_upload_image_size_unavailable', __('UltraCache upload conversion could not read the uploaded image dimensions.', 'ultracache'));
			}

			return array('width' => $width, 'height' => $height);
		}

		private function get_filesize($path) {
			$path = (string) $path;
			if ('' === $path) {
				return 0;
			}

			if (function_exists('ultracache_safe_filesize')) {
				$size = ultracache_safe_filesize($path, 'upload_conversion_output_size');
				if (false !== $size) {
					return max(0, (int) $size);
				}
			}

			if (function_exists('wp_filesize')) {
				$size = wp_filesize($path);
				if (false !== $size) {
					return max(0, (int) $size);
				}
			}

			return is_file($path) ? max(0, (int) filesize($path)) : 0;
		}
}
