<?php
/**
 * UltraCache media source admission and decoder resource limits.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Source_Admission_Trait
{
	/**
	 * Per-request source geometry inspection memo.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $media_source_decode_inspection_memo = array();

	/**
	 * Inspect source geometry without decoding the image pixels.
	 *
	 * @param string $source_file Source image path.
	 * @return array<string,mixed>
	 */
	private function inspect_media_source_for_decode($source_file) {
		$source_file = (string) $source_file;
		$key = str_replace('\\', '/', $source_file);
		if (isset($this->media_source_decode_inspection_memo[$key])) {
			return $this->media_source_decode_inspection_memo[$key];
		}

		$result = array(
			'valid'      => false,
			'code'       => 'source_dimensions_invalid',
			'width'      => 0,
			'height'     => 0,
			'pixels'     => 0,
			'bits'       => 0,
			'channels'   => 0,
			'mime'       => '',
			'sourceFile' => $source_file,
		);

		$dimensions = false;
		if (function_exists('wp_getimagesize')) {
			$dimensions = wp_getimagesize($source_file);
		} elseif (function_exists('getimagesize')) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Decoder admission converts image-header warnings into a typed preflight failure.
			$dimensions = @getimagesize($source_file);
		}

		if (!is_array($dimensions) || !isset($dimensions[0], $dimensions[1])) {
			$dimensions = $this->inspect_avif_source_dimensions_with_imagick($source_file);
		}

		if (!is_array($dimensions) || !isset($dimensions[0], $dimensions[1])) {
			$this->media_source_decode_inspection_memo[$key] = $result;
			return $result;
		}

		$width  = (int) $dimensions[0];
		$height = (int) $dimensions[1];
		$result['width']    = $width;
		$result['height']   = $height;
		$result['bits']     = isset($dimensions['bits']) ? max(0, (int) $dimensions['bits']) : 0;
		$result['channels'] = isset($dimensions['channels']) ? max(0, (int) $dimensions['channels']) : 0;
		$result['mime']     = isset($dimensions['mime']) ? strtolower(trim((string) $dimensions['mime'])) : '';

		if ($width <= 0 || $height <= 0) {
			$this->media_source_decode_inspection_memo[$key] = $result;
			return $result;
		}

		$max_dimension = (int) apply_filters('ultracache_media_decode_max_dimension', 65535, $source_file, $result);
		$max_dimension = max(4096, min(262144, $max_dimension));
		if ($width > $max_dimension || $height > $max_dimension) {
			$result['code'] = 'source_dimensions_excessive';
			$this->media_source_decode_inspection_memo[$key] = $result;
			return $result;
		}

		if ($width > intdiv(PHP_INT_MAX, $height)) {
			$result['code'] = 'source_pixel_count_excessive';
			$this->media_source_decode_inspection_memo[$key] = $result;
			return $result;
		}

		$pixels = $width * $height;
		$max_pixels = (int) apply_filters('ultracache_media_decode_max_pixels', 160000000, $source_file, $result);
		$max_pixels = max(16000000, min(1000000000, $max_pixels));
		$result['pixels'] = $pixels;
		if ($pixels > $max_pixels) {
			$result['code'] = 'source_pixel_count_excessive';
			$this->media_source_decode_inspection_memo[$key] = $result;
			return $result;
		}

		$result['valid'] = true;
		$result['code']  = '';
		$this->media_source_decode_inspection_memo[$key] = $result;
		return $result;
	}


	/**
	 * Inspect AVIF dimensions through the already verified Imagick decoder when
	 * the PHP image-header reader does not understand AVIF containers.
	 *
	 * @param string $source_file Source AVIF path.
	 * @return array<int|string,mixed>|false
	 */
	private function inspect_avif_source_dimensions_with_imagick($source_file) {
		$source_file = (string) $source_file;
		$extension = strtolower((string) pathinfo($source_file, PATHINFO_EXTENSION));
		$mime = function_exists('wp_get_image_mime') ? strtolower((string) wp_get_image_mime($source_file)) : '';
		if ('avif' !== $extension || 'image/avif' !== $mime || !$this->supports_imagick_avif_decode()) {
			return false;
		}
		if (!class_exists('Imagick') || !method_exists('Imagick', 'pingImage') || !method_exists('Imagick', 'getImageWidth') || !method_exists('Imagick', 'getImageHeight')) {
			return false;
		}

		$image = null;
		$previous = array();
		try {
			$image = new Imagick();
			if (method_exists($image, 'getResourceLimit') && method_exists($image, 'setResourceLimit') && defined('Imagick::RESOURCETYPE_MEMORY')) {
				$limits = array(
					array('type' => constant('Imagick::RESOURCETYPE_MEMORY'), 'limit' => 16 * 1024 * 1024),
				);
				if (defined('Imagick::RESOURCETYPE_MAP')) {
					$limits[] = array('type' => constant('Imagick::RESOURCETYPE_MAP'), 'limit' => 64 * 1024 * 1024);
				}
				foreach ($limits as $limit) {
					$type = (int) $limit['type'];
					$previous[$type] = (int) $image->getResourceLimit($type);
					if (!$image->setResourceLimit($type, (int) $limit['limit'])) {
						return false;
					}
				}
			}

			if (!$image->pingImage($source_file)) {
				return false;
			}
			$width = (int) $image->getImageWidth();
			$height = (int) $image->getImageHeight();
			if ($width <= 0 || $height <= 0) {
				return false;
			}

			return array(
				0      => $width,
				1      => $height,
				'bits' => 8,
				'mime' => 'image/avif',
			);
		} catch (Throwable $e) {
			ultracache_debug_log('imagick AVIF dimension inspection failed', array(
				'file'  => wp_basename($source_file),
				'error' => sanitize_text_field($e->getMessage()),
			));
			return false;
		} finally {
			if (is_object($image)) {
				if (!empty($previous) && method_exists($image, 'setResourceLimit')) {
					foreach ($previous as $type => $limit) {
						$image->setResourceLimit((int) $type, (int) $limit);
					}
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
	 * Apply geometry-only admission before encoder discovery or construction.
	 *
	 * @param string $source_file Source image path.
	 * @return bool
	 */
	private function ensure_media_source_geometry_admitted($source_file) {
		$inspection = $this->inspect_media_source_for_decode($source_file);
		if (!empty($inspection['valid'])) {
			return true;
		}

		$code = isset($inspection['code']) ? sanitize_key((string) $inspection['code']) : 'source_dimensions_invalid';
		$message = __('The source image dimensions are invalid.', 'ultracache');
		if ('source_dimensions_excessive' === $code) {
			$message = __('The source image dimensions exceed the configured decode limit.', 'ultracache');
		} elseif ('source_pixel_count_excessive' === $code) {
			$message = __('The source image pixel count exceeds the configured decode limit.', 'ultracache');
		}

		$this->record_media_conversion_failure('source', $code, $message, 'preflight');
		return false;
	}

	/**
	 * Return a saturating integer multiplication result.
	 *
	 * @param int $left  Left operand.
	 * @param int $right Right operand.
	 * @return int
	 */
	private function multiply_media_decode_bytes_safely($left, $right) {
		$left  = max(0, (int) $left);
		$right = max(0, (int) $right);
		if (0 === $left || 0 === $right) {
			return 0;
		}
		if ($left > intdiv(PHP_INT_MAX, $right)) {
			return PHP_INT_MAX;
		}
		return $left * $right;
	}

	/**
	 * Add byte counts without integer overflow.
	 *
	 * @param int $left  Left bytes.
	 * @param int $right Right bytes.
	 * @return int
	 */
	private function add_media_decode_bytes_safely($left, $right) {
		$left  = max(0, (int) $left);
		$right = max(0, (int) $right);
		if ($left > PHP_INT_MAX - $right) {
			return PHP_INT_MAX;
		}
		return $left + $right;
	}

	/**
	 * Estimate peak decoder bytes for the selected engine.
	 *
	 * GD uses the audited width * height * 4 * 1.8 bitmap estimate plus bounded
	 * allocator overhead. Imagick and the unknown WordPress image-editor path use
	 * a more conservative Q16/pixel-cache estimate.
	 *
	 * @param array<string,mixed> $inspection Source geometry.
	 * @param string              $engine     Decoder engine.
	 * @return int
	 */
	private function estimate_media_source_decode_bytes(array $inspection, $engine) {
		$pixels = isset($inspection['pixels']) ? max(0, (int) $inspection['pixels']) : 0;
		$engine = sanitize_key((string) $engine);

		if ('gd' === $engine) {
			$scaled = $this->multiply_media_decode_bytes_safely($pixels, 72);
			$bitmap = PHP_INT_MAX === $scaled ? PHP_INT_MAX : intdiv($scaled + 9, 10);
			return $this->add_media_decode_bytes_safely($bitmap, 8 * 1024 * 1024);
		}

		$bitmap = $this->multiply_media_decode_bytes_safely($pixels, 12);
		return $this->add_media_decode_bytes_safely($bitmap, 16 * 1024 * 1024);
	}

	/**
	 * Return bounded PHP/runtime memory headroom for one decoder attempt.
	 *
	 * @param string              $engine     Decoder engine.
	 * @param array<string,mixed> $inspection Source geometry.
	 * @return array<string,int>
	 */
	private function get_media_decode_memory_state($engine, array $inspection) {
		$memory_limit = function_exists('ultracache_parse_size_to_bytes')
			? (int) ultracache_parse_size_to_bytes((string) ini_get('memory_limit'))
			: 0;
		$memory_usage = function_exists('memory_get_usage') ? max(0, (int) memory_get_usage(true)) : 0;

		$reserve_default = 32 * 1024 * 1024;
		if ($memory_limit > 0) {
			$reserve_default = max(16 * 1024 * 1024, min(64 * 1024 * 1024, intdiv($memory_limit, 10)));
		}
		$reserve = (int) apply_filters('ultracache_media_decode_memory_reserve_bytes', $reserve_default, $engine, $inspection);
		$reserve = max(8 * 1024 * 1024, min(256 * 1024 * 1024, $reserve));

		if ($memory_limit > 0) {
			$available = max(0, $memory_limit - $memory_usage - $reserve);
		} else {
			$available = (int) apply_filters('ultracache_media_decode_unlimited_memory_budget_bytes', 512 * 1024 * 1024, $engine, $inspection);
			$available = max(64 * 1024 * 1024, min(2147483647, $available));
		}

		$filtered = (int) apply_filters('ultracache_media_decode_engine_memory_budget_bytes', $available, $engine, $inspection);
		$filtered = max(0, min(2147483647, $filtered));
		if ($memory_limit > 0) {
			$filtered = min($filtered, $available);
		}

		return array(
			'limit'     => $memory_limit,
			'usage'     => $memory_usage,
			'reserve'   => $reserve,
			'available' => $filtered,
		);
	}

	/**
	 * Determine whether Imagick can be bounded before source decoding.
	 *
	 * @return bool
	 */
	private function can_apply_media_imagick_resource_limits() {
		return class_exists('Imagick')
			&& method_exists('Imagick', 'setResourceLimit')
			&& method_exists('Imagick', 'getResourceLimit')
			&& defined('Imagick::RESOURCETYPE_MEMORY');
	}

	/**
	 * Build one typed decoder admission result.
	 *
	 * @param string $source_file Source image path.
	 * @param string $engine      Decoder engine.
	 * @return array<string,mixed>
	 */
	private function get_media_source_decode_admission($source_file, $engine) {
		$engine = sanitize_key((string) $engine);
		$inspection = $this->inspect_media_source_for_decode($source_file);
		$result = array(
			'allowed'        => false,
			'code'           => isset($inspection['code']) ? (string) $inspection['code'] : 'source_dimensions_invalid',
			'engine'         => $engine,
			'inspection'     => $inspection,
			'estimatedBytes' => 0,
			'memoryBudget'   => 0,
			'memoryState'    => array(),
			'resourceBounded'=> false,
		);

		if (empty($inspection['valid'])) {
			return $result;
		}

		$estimated = $this->estimate_media_source_decode_bytes($inspection, $engine);
		$memory = $this->get_media_decode_memory_state($engine, $inspection);
		$result['estimatedBytes'] = $estimated;
		$result['memoryBudget'] = isset($memory['available']) ? max(0, (int) $memory['available']) : 0;
		$result['memoryState'] = $memory;
		$result['resourceBounded'] = ('imagick' === $engine && $this->can_apply_media_imagick_resource_limits());

		if ($result['memoryBudget'] < 8 * 1024 * 1024) {
			$result['code'] = 'source_memory_budget_exceeded';
			return $result;
		}

		if (!$result['resourceBounded'] && $estimated > $result['memoryBudget']) {
			$result['code'] = 'source_memory_budget_exceeded';
			return $result;
		}

		$result['allowed'] = true;
		$result['code'] = '';
		return $result;
	}

	/**
	 * Enforce decoder-specific admission before any pixel decode.
	 *
	 * @param string $source_file Source image path.
	 * @param string $engine      Decoder engine.
	 * @return array<string,mixed>|false
	 */
	private function ensure_media_source_decode_admitted($source_file, $engine) {
		$admission = $this->get_media_source_decode_admission($source_file, $engine);
		if (!empty($admission['allowed'])) {
			return $admission;
		}

		$code = isset($admission['code']) ? sanitize_key((string) $admission['code']) : 'source_memory_budget_exceeded';
		$message = __('The source image cannot be decoded within the available memory budget.', 'ultracache');
		if ('source_dimensions_invalid' === $code) {
			$message = __('The source image dimensions are invalid.', 'ultracache');
		} elseif ('source_dimensions_excessive' === $code) {
			$message = __('The source image dimensions exceed the configured decode limit.', 'ultracache');
		} elseif ('source_pixel_count_excessive' === $code) {
			$message = __('The source image pixel count exceeds the configured decode limit.', 'ultracache');
		}

		$this->record_media_conversion_failure($engine, $code, $message, 'preflight');
		return false;
	}

	/**
	 * Build bounded Imagick MEMORY and MAP limits for one source.
	 *
	 * @param array<string,mixed> $admission Decoder admission result.
	 * @return array<string,int>
	 */
	private function get_media_imagick_resource_plan(array $admission) {
		$budget = isset($admission['memoryBudget']) ? max(0, (int) $admission['memoryBudget']) : 0;
		$estimated = isset($admission['estimatedBytes']) ? max(0, (int) $admission['estimatedBytes']) : 0;
		$minimum = min($budget, 16 * 1024 * 1024);

		$memory_default = min($budget, max($minimum, min(256 * 1024 * 1024, intdiv(max($estimated, $minimum), 2))));
		$memory = (int) apply_filters('ultracache_media_imagick_memory_limit_bytes', $memory_default, $admission);
		$memory = max(1, min(max(1, $budget), $memory));

		$map_default = max($memory, min(1024 * 1024 * 1024, max($estimated, $this->multiply_media_decode_bytes_safely($memory, 2))));
		$map = (int) apply_filters('ultracache_media_imagick_map_limit_bytes', $map_default, $admission);
		$map = max($memory, min(2147483647, $map));

		return array(
			'memory' => $memory,
			'map'    => $map,
		);
	}

	/**
	 * Apply Imagick resource limits before readImage().
	 *
	 * @param Imagick             $image     Empty Imagick instance.
	 * @param array<string,mixed> $admission Decoder admission result.
	 * @return array<string,mixed>|false
	 */
	private function apply_media_imagick_resource_limits($image, array $admission) {
		if (!$this->can_apply_media_imagick_resource_limits()) {
			return array('applied' => false, 'previous' => array());
		}

		$plan = $this->get_media_imagick_resource_plan($admission);
		$resources = array(
			'memory' => array(
				'type'  => constant('Imagick::RESOURCETYPE_MEMORY'),
				'limit' => (int) $plan['memory'],
			),
		);
		if (defined('Imagick::RESOURCETYPE_MAP')) {
			$resources['map'] = array(
				'type'  => constant('Imagick::RESOURCETYPE_MAP'),
				'limit' => (int) $plan['map'],
			);
		}

		$previous = array();
		foreach ($resources as $name => $resource) {
			$type = (int) $resource['type'];
			try {
				$previous[$name] = array(
					'type'  => $type,
					'limit' => (int) $image->getResourceLimit($type),
				);
			} catch (Throwable $e) {
				$this->restore_media_imagick_resource_limits($image, $previous);
				return false;
			}

			try {
				if (!$image->setResourceLimit($type, max(1, (int) $resource['limit']))) {
					$this->restore_media_imagick_resource_limits($image, $previous);
					return false;
				}
			} catch (Throwable $e) {
				$this->restore_media_imagick_resource_limits($image, $previous);
				return false;
			}
		}

		return array('applied' => true, 'previous' => $previous, 'plan' => $plan);
	}

	/**
	 * Restore process-level Imagick resource limits after one conversion.
	 *
	 * @param Imagick                   $image    Imagick instance.
	 * @param array<string,array<string,mixed>> $previous Previous limits.
	 * @return void
	 */
	private function restore_media_imagick_resource_limits($image, array $previous) {
		foreach ($previous as $resource) {
			if (!isset($resource['type']) || !array_key_exists('limit', $resource) || null === $resource['limit']) {
				continue;
			}
			try {
				$image->setResourceLimit((int) $resource['type'], max(1, (int) $resource['limit']));
			} catch (Throwable $e) {
				if (function_exists('ultracache_debug_log')) {
					ultracache_debug_log('imagick resource limit restore failed', array('error' => $e->getMessage()));
				}
			}
		}
	}
}
