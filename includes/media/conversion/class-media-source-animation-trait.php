<?php
/**
 * UltraCache media source animation inspection and semantic skip policy.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Source_Animation_Trait
{
	/**
	 * Per-request animation inspection memo, keyed by source identity.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $media_source_animation_inspection_memo = array();

	/**
	 * Inspect whether a source contains animation without decoding its pixels.
	 *
	 * Valid WebP containers are checked structurally. When Imagick metadata ping
	 * is available it is also consulted under the same bounded resource policy
	 * used by the encoder; either detector proving multiple frames is decisive.
	 *
	 * @param string $source_file Source image path.
	 * @return array<string,mixed>
	 */
	private function inspect_media_source_animation($source_file) {
		$source_file = (string) $source_file;
		$key = str_replace('\\', '/', $source_file);
		if (isset($this->media_source_animation_inspection_memo[$key])) {
			return $this->media_source_animation_inspection_memo[$key];
		}

		$mime = $this->get_source_image_mime($source_file);
		$result = array(
			'valid'       => true,
			'determinate' => true,
			'animated'    => false,
			'frames'      => 1,
			'mime'        => $mime,
			'detector'    => 'not-applicable',
			'code'        => '',
			'sourceFile'  => $source_file,
		);

		if ('image/avif' === $mime) {
			$imagick = $this->inspect_media_animation_with_imagick_metadata($source_file);
			$container = $this->inspect_avif_animation_structure($source_file);
			if (!empty($imagick['animated']) || !empty($container['animated'])) {
				$result['animated'] = true;
				$result['frames'] = max(2, (int) ($imagick['frames'] ?? 0), (int) ($container['frames'] ?? 0));
				$result['detector'] = !empty($imagick['animated']) && !empty($container['animated']) ? 'imagick+avif-ftyp' : (!empty($imagick['animated']) ? 'imagick' : 'avif-ftyp');
				$this->media_source_animation_inspection_memo[$key] = $result;
				return $result;
			}
			if (!empty($container['determinate'])) {
				$result['detector'] = 'avif-ftyp';
				$this->media_source_animation_inspection_memo[$key] = $result;
				return $result;
			}
			if (!empty($imagick['determinate'])) {
				$result['detector'] = 'imagick';
				$result['frames'] = max(1, (int) ($imagick['frames'] ?? 1));
				$this->media_source_animation_inspection_memo[$key] = $result;
				return $result;
			}
			$result['valid'] = false;
			$result['determinate'] = false;
			$result['frames'] = 0;
			$result['detector'] = 'unknown';
			$result['code'] = (string) ($container['code'] ?? $imagick['code'] ?? 'avif_animation_inspection_unavailable');
			$this->media_source_animation_inspection_memo[$key] = $result;
			return $result;
		}

		if ('image/webp' !== $mime) {
			$this->media_source_animation_inspection_memo[$key] = $result;
			return $result;
		}

		$imagick = $this->inspect_media_animation_with_imagick_metadata($source_file);
		$riff = $this->inspect_webp_animation_structure($source_file);

		if (!empty($imagick['animated']) || !empty($riff['animated'])) {
			$result['animated'] = true;
			$result['frames'] = max(2, (int) ($imagick['frames'] ?? 0), (int) ($riff['frames'] ?? 0));
			$result['detector'] = !empty($imagick['animated']) && !empty($riff['animated']) ? 'imagick+riff' : (!empty($imagick['animated']) ? 'imagick' : 'riff');
			$this->media_source_animation_inspection_memo[$key] = $result;
			return $result;
		}

		if (!empty($riff['determinate'])) {
			$result['detector'] = 'riff';
			$this->media_source_animation_inspection_memo[$key] = $result;
			return $result;
		}

		if (!empty($imagick['determinate'])) {
			$result['detector'] = 'imagick';
			$result['frames'] = max(1, (int) ($imagick['frames'] ?? 1));
			$this->media_source_animation_inspection_memo[$key] = $result;
			return $result;
		}

		$result['valid'] = false;
		$result['determinate'] = false;
		$result['frames'] = 0;
		$result['detector'] = 'unknown';
		$result['code'] = (string) ($riff['code'] ?? $imagick['code'] ?? 'webp_animation_inspection_unavailable');
		$this->media_source_animation_inspection_memo[$key] = $result;
		return $result;
	}

	/**
	 * Return the semantic skip reason for a source/output conversion pair.
	 *
	 * @param string $source_file   Source image path.
	 * @param string $target_format Requested target format.
	 * @return string
	 */
	private function get_media_source_conversion_skip_reason($source_file, $target_format = '') {
		$target_format = strtolower(trim((string) $target_format));
		if ('' !== $target_format && !in_array($target_format, array('best', 'avif', 'webp', 'both'), true)) {
			return '';
		}

		$inspection = $this->inspect_media_source_animation($source_file);
		if (!empty($inspection['animated'])) {
			return 'image/avif' === (string) ($inspection['mime'] ?? '') ? 'animated_avif_unsupported' : 'animated_webp_unsupported';
		}

		if ($this->should_ignore_media_color_profile_preservation()) {
			return '';
		}

		if ('avif' === $target_format) {
			$profile = $this->inspect_media_source_color_profile($source_file);
			$requires_imagick = empty($profile['determinate']) || !empty($profile['hasProfile']);
			if ($requires_imagick && !$this->supports_imagick_avif()) {
				return 'color_profile_requires_imagick';
			}
			if (!empty($profile['hasProfile']) && !$this->supports_imagick_avif_color_profiles()) {
				return 'color_profile_capability_unverified';
			}
		}

		if ('webp' === $target_format && $this->media_source_requires_color_managed_encoder($source_file)) {
			$imagick_supported = $this->source_file_matches_target_format($source_file, 'avif')
				? $this->supports_imagick_avif_to_webp()
				: $this->supports_imagick_webp();
			if (!$imagick_supported) {
				return 'color_profile_requires_imagick';
			}
		}

		return '';
	}

	/**
	 * Human-readable detail for one semantic conversion skip.
	 *
	 * @param string $reason Semantic skip code.
	 * @return string
	 */
	private function get_media_conversion_skip_detail($reason) {
		$reason = sanitize_key((string) $reason);
		if ('animated_webp_unsupported' === $reason) {
			return $this->get_animated_webp_skip_message();
		}
		if ('animated_avif_unsupported' === $reason) {
			return __('Animated AVIF sources are preserved because converting them would discard their animation.', 'ultracache');
		}
		if ('color_profile_capability_unverified' === $reason) {
			return __('The active Imagick AVIF delegate did not pass the color-profile self-test.', 'ultracache');
		}
		if ('color_profile_requires_imagick' === $reason) {
			return $this->get_color_profile_encoder_skip_message();
		}

		return __('The requested image conversion was skipped by the active media policy.', 'ultracache');
	}

	/**
	 * Human-readable detail for the shared semantic skip code.
	 *
	 * @return string
	 */
	private function get_animated_webp_skip_message() {
		return __('Animated WebP sources are preserved because converting them would discard their animation.', 'ultracache');
	}

	/**
	 * Inspect WebP frame metadata through Imagick without decoding pixels.
	 *
	 * @param string $source_file Source image path.
	 * @return array<string,mixed>
	 */
	private function inspect_media_animation_with_imagick_metadata($source_file) {
		$result = array(
			'determinate' => false,
			'animated'    => false,
			'frames'      => 0,
			'code'        => 'imagick_animation_metadata_unavailable',
		);
		if (!class_exists('Imagick') || !method_exists('Imagick', 'pingImage') || !method_exists('Imagick', 'getNumberImages')) {
			return $result;
		}

		$admission = $this->get_media_source_decode_admission($source_file, 'imagick');
		if (empty($admission['allowed'])) {
			$result['code'] = (string) ($admission['code'] ?? 'imagick_animation_metadata_not_admitted');
			return $result;
		}

		$image = null;
		$resource_state = array('applied' => false, 'previous' => array());
		try {
			$image = new Imagick();
			$resource_state = $this->apply_media_imagick_resource_limits($image, $admission);
			if (false === $resource_state) {
				$result['code'] = 'imagick_animation_resource_limit_failed';
				return $result;
			}
			if (!$image->pingImage($source_file)) {
				$result['code'] = 'imagick_animation_ping_failed';
				return $result;
			}

			$frames = max(0, (int) $image->getNumberImages());
			if ($frames <= 0) {
				$result['code'] = 'imagick_animation_frame_count_invalid';
				return $result;
			}

			$result['determinate'] = true;
			$result['animated'] = $frames > 1;
			$result['frames'] = $frames;
			$result['code'] = '';
			return $result;
		} catch (Throwable $e) {
			$result['code'] = 'imagick_animation_metadata_exception';
			ultracache_debug_log('imagick animation metadata inspection failed', array(
				'file'  => wp_basename((string) $source_file),
				'error' => sanitize_text_field($e->getMessage()),
			));
			return $result;
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
		}
	}

	/**
	 * Inspect the AVIF file-type box for the still-image or sequence brand.
	 *
	 * @param string $source_file Source AVIF path.
	 * @return array<string,mixed>
	 */
	private function inspect_avif_animation_structure($source_file) {
		$result = array(
			'determinate' => false,
			'animated'    => false,
			'frames'      => 0,
			'code'        => 'avif_container_invalid',
		);
		if (!function_exists('ultracache_safe_stream_read_chunk') || !function_exists('ultracache_safe_filesize')) {
			$result['code'] = 'avif_animation_stream_reader_unavailable';
			return $result;
		}

		$file_size = max(0, (int) ultracache_safe_filesize($source_file, 'media_avif_animation_filesize'));
		if ($file_size < 16) {
			return $result;
		}

		$header_read = ultracache_safe_stream_read_chunk($source_file, 0, 16, 'media_avif_animation_ftyp_header');
		$header = is_array($header_read) ? (string) ($header_read['data'] ?? '') : '';
		if (16 !== strlen($header) || 'ftyp' !== substr($header, 4, 4)) {
			return $result;
		}

		$size_data = unpack('Nsize', substr($header, 0, 4));
		$box_size = is_array($size_data) ? (int) ($size_data['size'] ?? 0) : 0;
		if ($box_size < 16 || $box_size > $file_size || $box_size > 4096) {
			return $result;
		}

		$box_read = ultracache_safe_stream_read_chunk($source_file, 0, $box_size, 'media_avif_animation_ftyp_box');
		$box = is_array($box_read) ? (string) ($box_read['data'] ?? '') : '';
		if (strlen($box) !== $box_size) {
			return $result;
		}

		$brands = array();
		for ($offset = 8; $offset + 4 <= $box_size; $offset += 4) {
			if (12 === $offset) {
				continue;
			}
			$brands[] = substr($box, $offset, 4);
		}
		if (in_array('avis', $brands, true)) {
			$result['determinate'] = true;
			$result['animated'] = true;
			$result['frames'] = 2;
			$result['code'] = '';
			return $result;
		}
		if (in_array('avif', $brands, true)) {
			$result['determinate'] = true;
			$result['animated'] = false;
			$result['frames'] = 1;
			$result['code'] = '';
		}
		return $result;
	}

	/**
	 * Parse bounded RIFF/WebP chunk headers and animation flags.
	 *
	 * @param string $source_file Source WebP path.
	 * @return array<string,mixed>
	 */
	private function inspect_webp_animation_structure($source_file) {
		$result = array(
			'determinate' => false,
			'animated'    => false,
			'frames'      => 0,
			'code'        => 'webp_container_invalid',
		);
		if (!function_exists('ultracache_safe_stream_read_chunk') || !function_exists('ultracache_safe_filesize')) {
			$result['code'] = 'webp_animation_stream_reader_unavailable';
			return $result;
		}

		$file_size = max(0, (int) ultracache_safe_filesize($source_file, 'media_webp_animation_filesize'));
		if ($file_size < 12) {
			return $result;
		}

		$header_read = ultracache_safe_stream_read_chunk($source_file, 0, 12, 'media_webp_animation_riff_header');
		$header = is_array($header_read) ? (string) ($header_read['data'] ?? '') : '';
		if (12 !== strlen($header) || 'RIFF' !== substr($header, 0, 4) || 'WEBP' !== substr($header, 8, 4)) {
			return $result;
		}

		$size_data = unpack('Vsize', substr($header, 4, 4));
		$riff_payload = is_array($size_data) ? (int) ($size_data['size'] ?? 0) : 0;
		if ($riff_payload < 4 || $riff_payload > PHP_INT_MAX - 8) {
			return $result;
		}

		$declared_end = $riff_payload + 8;
		if ($declared_end > $file_size) {
			$result['code'] = 'webp_container_truncated';
			return $result;
		}

		$max_chunks = (int) apply_filters('ultracache_media_webp_animation_max_chunks', 4096, $source_file, $file_size);
		$max_chunks = max(32, min(65536, $max_chunks));
		$offset = 12;
		$chunks = 0;
		while ($offset < $declared_end) {
			if (++$chunks > $max_chunks || $offset > $declared_end - 8) {
				$result['code'] = $chunks > $max_chunks ? 'webp_animation_chunk_limit_exceeded' : 'webp_container_truncated';
				return $result;
			}

			$chunk_read = ultracache_safe_stream_read_chunk($source_file, $offset, 8, 'media_webp_animation_chunk_header');
			$chunk_header = is_array($chunk_read) ? (string) ($chunk_read['data'] ?? '') : '';
			if (8 !== strlen($chunk_header)) {
				$result['code'] = 'webp_container_truncated';
				return $result;
			}

			$chunk_type = substr($chunk_header, 0, 4);
			$chunk_size_data = unpack('Vsize', substr($chunk_header, 4, 4));
			$chunk_size = is_array($chunk_size_data) ? (int) ($chunk_size_data['size'] ?? -1) : -1;
			if ($chunk_size < 0) {
				return $result;
			}

			$data_offset = $offset + 8;
			$padded_size = $chunk_size + ($chunk_size % 2);
			if ($padded_size < $chunk_size || $data_offset > PHP_INT_MAX - $padded_size) {
				$result['code'] = 'webp_container_size_overflow';
				return $result;
			}
			$next_offset = $data_offset + $padded_size;
			if ($next_offset > $declared_end) {
				$result['code'] = 'webp_container_truncated';
				return $result;
			}

			if ('ANIM' === $chunk_type || 'ANMF' === $chunk_type) {
				$result['determinate'] = true;
				$result['animated'] = true;
				$result['frames'] = 'ANMF' === $chunk_type ? 2 : 1;
				$result['code'] = '';
				return $result;
			}

			if ('VP8X' === $chunk_type && $chunk_size >= 1) {
				$flags_read = ultracache_safe_stream_read_chunk($source_file, $data_offset, 1, 'media_webp_animation_vp8x_flags');
				$flags = is_array($flags_read) ? (string) ($flags_read['data'] ?? '') : '';
				if (1 !== strlen($flags)) {
					$result['code'] = 'webp_container_truncated';
					return $result;
				}
				if (0 !== (ord($flags[0]) & 0x02)) {
					$result['determinate'] = true;
					$result['animated'] = true;
					$result['frames'] = 2;
					$result['code'] = '';
					return $result;
				}
			}

			$offset = $next_offset;
		}

		$result['determinate'] = true;
		$result['frames'] = 1;
		$result['code'] = '';
		return $result;
	}
}
