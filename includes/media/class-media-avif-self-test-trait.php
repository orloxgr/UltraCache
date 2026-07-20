<?php
/**
 * AVIF encoder self-test support for UltraCache media conversion.
 */

if (!defined('ABSPATH')) {
	exit;
}

trait Ultra_Cache_Media_Avif_Self_Test_Trait {

	/**
	 * Run or read the deterministic AVIF encoder self-test.
	 *
	 * The test encodes both a bundled 300x169 opaque JPEG and a 16x16 RGBA
	 * PNG with every available AVIF engine, fully decodes both outputs, and
	 * validates sampled RGB and alpha values.
	 * Results are persisted against an environment fingerprint so normal media
	 * requests do not repeat the test.
	 *
	 * @param bool $force Whether to ignore the stored result and run again.
	 * @return array<string,mixed>
	 */
	public function run_avif_encoder_self_test($force = false) {
		$alpha_source  = $this->get_avif_encoder_self_test_source_path();
		$opaque_source = $this->get_avif_encoder_opaque_self_test_source_path();
		$environment   = $this->get_avif_encoder_self_test_environment($alpha_source, $opaque_source);
		$fingerprint   = hash('sha256', wp_json_encode($environment));
		$cached        = get_option(self::AVIF_SELF_TEST_OPTION, array());

		if (
			!$force &&
			is_array($cached) &&
			isset($cached['fingerprint'], $cached['engines']) &&
			hash_equals((string) $cached['fingerprint'], $fingerprint)
		) {
			$cached['cached'] = true;
			return $cached;
		}

		if (
			!is_file($alpha_source) || !is_readable($alpha_source) ||
			!is_file($opaque_source) || !is_readable($opaque_source)
		) {
			$missing = $this->build_avif_self_test_report(
				$fingerprint,
				$environment,
				array(
					'imagick' => $this->build_avif_self_test_engine_result('imagick', false, false, 'source_missing', __('A bundled AVIF self-test image is missing or unreadable.', 'ultracache')),
					'gd'      => $this->build_avif_self_test_engine_result('gd', false, false, 'source_missing', __('A bundled AVIF self-test image is missing or unreadable.', 'ultracache')),
				)
			);
			update_option(self::AVIF_SELF_TEST_OPTION, $missing, false);
			$this->invalidate_avif_self_test_support_caches();
			return $missing;
		}

		$engines = array(
			'imagick' => $this->run_imagick_avif_encoder_self_test($alpha_source, $opaque_source),
			'gd'      => $this->run_gd_avif_encoder_self_test($alpha_source, $opaque_source),
		);
		$report  = $this->build_avif_self_test_report($fingerprint, $environment, $engines);

		update_option(self::AVIF_SELF_TEST_OPTION, $report, false);
		$this->invalidate_avif_self_test_support_caches();

		return $report;
	}

	/**
	 * Get the bundled deterministic PNG path.
	 *
	 * @return string
	 */
	private function get_avif_encoder_self_test_source_path() {
		return ultracache_plugin_dir('assets/diagnostics/avif-self-test-16x16.png');
	}

	/**
	 * Get the bundled opaque JPEG regression-test path.
	 *
	 * @return string
	 */
	private function get_avif_encoder_opaque_self_test_source_path() {
		return ultracache_plugin_dir('assets/diagnostics/avif-self-test-opaque-300x169.jpg');
	}

	/**
	 * Build the environment data that invalidates stale self-test results.
	 *
	 * @param string $alpha_source  Transparent PNG fixture path.
	 * @param string $opaque_source Opaque JPEG fixture path.
	 * @return array<string,mixed>
	 */
	private function get_avif_encoder_self_test_environment($alpha_source, $opaque_source) {
		$imagick_version = '';
		if (class_exists('Imagick') && method_exists('Imagick', 'getVersion')) {
			try {
				$version = \Imagick::getVersion();
				if (is_array($version) && isset($version['versionString'])) {
					$imagick_version = (string) $version['versionString'];
				}
			} catch (\Throwable $e) {
				$imagick_version = '';
			}
		}

		$gd_version = '';
		if (function_exists('gd_info')) {
			$gd_info = gd_info();
			if (is_array($gd_info) && isset($gd_info['GD Version'])) {
				$gd_version = (string) $gd_info['GD Version'];
			}
		}

		$alpha_contents = is_readable($alpha_source)
			? (string) ultracache_safe_file_get_contents($alpha_source, 'media_avif_self_test_alpha_asset_hash', true)
			: '';
		$opaque_contents = is_readable($opaque_source)
			? (string) ultracache_safe_file_get_contents($opaque_source, 'media_avif_self_test_opaque_asset_hash', true)
			: '';

		$imagick_avif_reported = false;
		if (class_exists('Imagick') && method_exists('Imagick', 'queryFormats')) {
			try {
				$formats = \Imagick::queryFormats('AVIF');
				$imagick_avif_reported = is_array($formats) && in_array('AVIF', $formats, true);
			} catch (\Throwable $e) {
				$imagick_avif_reported = false;
			}
		}

		return array(
			'testVersion'             => self::AVIF_SELF_TEST_VERSION,
			'phpVersion'              => PHP_VERSION,
			'imagickExtensionVersion' => extension_loaded('imagick') ? (string) phpversion('imagick') : '',
			'imageMagickVersion'       => $imagick_version,
			'imagickAvifReported'      => $imagick_avif_reported,
			'gdVersion'                => $gd_version,
			'gdAvifEncode'             => function_exists('imageavif'),
			'gdAvifDecode'             => function_exists('imagecreatefromavif'),
			'alphaAssetSha256'         => '' !== $alpha_contents ? hash('sha256', $alpha_contents) : '',
			'opaqueAssetSha256'        => '' !== $opaque_contents ? hash('sha256', $opaque_contents) : '',
		);
	}

	/**
	 * Run the Imagick AVIF test.
	 *
	 * @param string $source_file        Transparent PNG fixture.
	 * @param string $opaque_source_file Opaque JPEG fixture.
	 * @return array<string,mixed>
	 */
	private function run_imagick_avif_encoder_self_test($source_file, $opaque_source_file) {
		if (!extension_loaded('imagick') || !class_exists('Imagick') || !class_exists('ImagickPixel')) {
			return $this->build_avif_self_test_engine_result('imagick', false, false, 'unavailable', __('Imagick is unavailable.', 'ultracache'));
		}

		if (!method_exists('Imagick', 'queryFormats')) {
			return $this->build_avif_self_test_engine_result('imagick', false, false, 'unavailable', __('Imagick AVIF format detection is unavailable.', 'ultracache'));
		}

		try {
			$formats = \Imagick::queryFormats('AVIF');
		} catch (\Throwable $e) {
			$formats = array();
		}

		if (!is_array($formats) || !in_array('AVIF', $formats, true)) {
			return $this->build_avif_self_test_engine_result('imagick', false, false, 'unavailable', __('Imagick reports no AVIF encoder.', 'ultracache'));
		}

		$tmp = $this->create_temp_file('ultracache-avif-self-test-imagick');
		if (!$tmp) {
			return $this->build_avif_self_test_engine_result('imagick', true, false, 'temp_file_failed', __('Unable to create the Imagick AVIF self-test file.', 'ultracache'));
		}

		$test_file = $tmp . '.avif';
		ultracache_safe_unlink($tmp);
		$image   = null;
		$decoded = null;

		try {
			$image = new \Imagick($source_file);
			$image->setImageFormat('avif');
			$image->setImageCompressionQuality(60);
			if (method_exists($image, 'stripImage')) {
				$image->stripImage();
			}

			if (!$image->writeImage($test_file) || !$this->is_valid_generated_media_file($test_file, 'avif', 'media_avif_self_test_imagick_encode')) {
				return $this->build_avif_self_test_engine_result('imagick', true, false, 'failed_encode', __('Imagick did not create a valid AVIF container.', 'ultracache'));
			}

			$decoded    = new \Imagick($test_file);
			$dimensions = array(
				'width'  => (int) $decoded->getImageWidth(),
				'height' => (int) $decoded->getImageHeight(),
			);
			$samples    = $this->read_imagick_avif_self_test_samples($decoded);
			$validation = $this->validate_avif_self_test_samples($dimensions, $samples);
			$result     = $this->build_avif_self_test_engine_result(
				'imagick',
				true,
				!empty($validation['passed']),
				(string) $validation['status'],
				(string) $validation['message']
			);
			$result['dimensions']  = $dimensions;
			$result['samples']     = $samples;
			$result['outputBytes'] = max(0, (int) ultracache_safe_filesize($test_file, 'media_avif_self_test_imagick_size'));
			$result['tests']       = array('alpha' => $result);

			if (empty($validation['passed'])) {
				return $result;
			}

			$opaque_result = $this->run_imagick_opaque_avif_self_test($opaque_source_file);
			$result['tests']['opaque'] = $opaque_result;
			if (empty($opaque_result['passed'])) {
				$result['passed']  = false;
				$result['status']  = (string) ($opaque_result['status'] ?? 'failed_pixel_validation');
				$result['message'] = (string) ($opaque_result['message'] ?? __('Imagick failed the opaque JPEG AVIF regression test.', 'ultracache'));
				return $result;
			}

			$result['passed']  = true;
			$result['status']  = 'passed';
			$result['message'] = __('Imagick passed both the opaque JPEG and transparent PNG AVIF tests.', 'ultracache');
			return $result;
		} catch (\Throwable $e) {
			/* translators: %s: Imagick AVIF encode/decode error message. */
			return $this->build_avif_self_test_engine_result('imagick', true, false, 'failed_decode', sprintf(__('Imagick AVIF encode/decode failed: %s', 'ultracache'), $e->getMessage()));
		} finally {
			if ($decoded instanceof \Imagick) {
				$decoded->clear();
				$decoded->destroy();
			}
			if ($image instanceof \Imagick) {
				$image->clear();
				$image->destroy();
			}
			if (is_file($test_file)) {
				ultracache_safe_unlink($test_file);
				$this->optimized_storage_forget_path($test_file);
			}
		}
	}

	/**
	 * Run the GD AVIF test.
	 *
	 * @param string $source_file        Transparent PNG fixture.
	 * @param string $opaque_source_file Opaque JPEG fixture.
	 * @return array<string,mixed>
	 */
	private function run_gd_avif_encoder_self_test($source_file, $opaque_source_file) {
		$required = array('imagecreatefrompng', 'imageavif', 'imagecreatefromavif', 'imagecolorat', 'imagecolorsforindex', 'imagealphablending', 'imagesavealpha', 'imagesx', 'imagesy');
		foreach ($required as $function_name) {
			if (!function_exists($function_name)) {
				return $this->build_avif_self_test_engine_result('gd', false, false, 'unavailable', __('GD AVIF encode/decode support is unavailable.', 'ultracache'));
			}
		}

		$tmp = $this->create_temp_file('ultracache-avif-self-test-gd');
		if (!$tmp) {
			return $this->build_avif_self_test_engine_result('gd', true, false, 'temp_file_failed', __('Unable to create the GD AVIF self-test file.', 'ultracache'));
		}

		$test_file = $tmp . '.avif';
		ultracache_safe_unlink($tmp);
		$image   = null;
		$decoded = null;

		try {
			$image = imagecreatefrompng($source_file);
			if (!$image) {
				return $this->build_avif_self_test_engine_result('gd', true, false, 'source_decode_failed', __('GD could not decode the bundled AVIF self-test PNG.', 'ultracache'));
			}
			imagealphablending($image, false);
			imagesavealpha($image, true);

			$gd_warning = '';
			$encoded    = false;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped handler captures GD codec warnings and is restored immediately.
			set_error_handler(static function($severity, $message) use (&$gd_warning) {
				$gd_warning = (string) $message;
				return true;
			});
			try {
				$encoded = imageavif($image, $test_file, 60);
			} finally {
				restore_error_handler();
			}

			if (!$encoded || !$this->is_valid_generated_media_file($test_file, 'avif', 'media_avif_self_test_gd_encode')) {
				$message = $gd_warning
					/* translators: %s: GD AVIF encoding warning message. */
					? sprintf(__('GD AVIF encoding failed: %s', 'ultracache'), $gd_warning)
					: __('GD did not create a valid AVIF container.', 'ultracache');
				return $this->build_avif_self_test_engine_result('gd', true, false, 'failed_encode', $message);
			}

			$gd_warning = '';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped handler captures GD codec warnings and is restored immediately.
			set_error_handler(static function($severity, $message) use (&$gd_warning) {
				$gd_warning = (string) $message;
				return true;
			});
			try {
				$decoded = imagecreatefromavif($test_file);
			} finally {
				restore_error_handler();
			}
			if (!$decoded) {
				$message = $gd_warning
					/* translators: %s: GD AVIF decoding warning message. */
					? sprintf(__('GD AVIF decoding failed: %s', 'ultracache'), $gd_warning)
					: __('GD could not decode its generated AVIF file.', 'ultracache');
				return $this->build_avif_self_test_engine_result('gd', true, false, 'failed_decode', $message);
			}

			$dimensions = array(
				'width'  => (int) imagesx($decoded),
				'height' => (int) imagesy($decoded),
			);
			$samples    = $this->read_gd_avif_self_test_samples($decoded);
			$validation = $this->validate_avif_self_test_samples($dimensions, $samples);
			$result     = $this->build_avif_self_test_engine_result(
				'gd',
				true,
				!empty($validation['passed']),
				(string) $validation['status'],
				(string) $validation['message']
			);
			$result['dimensions']  = $dimensions;
			$result['samples']     = $samples;
			$result['outputBytes'] = max(0, (int) ultracache_safe_filesize($test_file, 'media_avif_self_test_gd_size'));
			$result['tests']       = array('alpha' => $result);

			if (empty($validation['passed'])) {
				return $result;
			}

			$opaque_result = $this->run_gd_opaque_avif_self_test($opaque_source_file);
			$result['tests']['opaque'] = $opaque_result;
			if (empty($opaque_result['passed'])) {
				$result['passed']  = false;
				$result['status']  = (string) ($opaque_result['status'] ?? 'failed_pixel_validation');
				$result['message'] = (string) ($opaque_result['message'] ?? __('GD failed the opaque JPEG AVIF regression test.', 'ultracache'));
				return $result;
			}

			$result['passed']  = true;
			$result['status']  = 'passed';
			$result['message'] = __('GD passed both the opaque JPEG and transparent PNG AVIF tests.', 'ultracache');
			return $result;
		} catch (\Throwable $e) {
			/* translators: %s: GD AVIF encode/decode error message. */
			return $this->build_avif_self_test_engine_result('gd', true, false, 'failed_decode', sprintf(__('GD AVIF encode/decode failed: %s', 'ultracache'), $e->getMessage()));
		} finally {
			if ($decoded) {
				imagedestroy($decoded);
			}
			if ($image) {
				imagedestroy($image);
			}
			if (is_file($test_file)) {
				ultracache_safe_unlink($test_file);
				$this->optimized_storage_forget_path($test_file);
			}
		}
	}


	/**
	 * Test the production Imagick path with an opaque odd-height JPEG.
	 *
	 * @param string $source_file Opaque JPEG fixture.
	 * @return array<string,mixed>
	 */
	private function run_imagick_opaque_avif_self_test($source_file) {
		$tmp = $this->create_temp_file('ultracache-avif-self-test-imagick-opaque');
		if (!$tmp) {
			return $this->build_avif_self_test_fixture_result(false, 'temp_file_failed', __('Unable to create the Imagick opaque AVIF self-test file.', 'ultracache'));
		}

		$test_file = $tmp . '.avif';
		ultracache_safe_unlink($tmp);
		$image   = null;
		$decoded = null;

		try {
			$image = new \Imagick($source_file);
			if (method_exists($image, 'getImageAlphaChannel') && $image->getImageAlphaChannel()) {
				return $this->build_avif_self_test_fixture_result(false, 'source_alpha_unexpected', __('The bundled opaque JPEG unexpectedly contains an active alpha channel.', 'ultracache'));
			}

			$coordinates   = $this->get_avif_opaque_self_test_sample_coordinates();
			$source_samples = $this->read_imagick_avif_self_test_samples($image, $coordinates);
			$image->setImageFormat('avif');
			$image->setImageCompressionQuality(60);
			if (method_exists($image, 'stripImage')) {
				$image->stripImage();
			}

			if (!$image->writeImage($test_file) || !$this->is_valid_generated_media_file($test_file, 'avif', 'media_avif_self_test_imagick_opaque_encode')) {
				return $this->build_avif_self_test_fixture_result(false, 'failed_encode', __('Imagick did not create a valid AVIF from the opaque JPEG.', 'ultracache'));
			}

			$decoded = new \Imagick($test_file);
			$dimensions = array(
				'width'  => (int) $decoded->getImageWidth(),
				'height' => (int) $decoded->getImageHeight(),
			);
			$decoded_samples = $this->read_imagick_avif_self_test_samples($decoded, $coordinates);
			$validation = $this->validate_avif_opaque_self_test_samples($dimensions, $source_samples, $decoded_samples);
			$result = $this->build_avif_self_test_fixture_result(
				!empty($validation['passed']),
				(string) $validation['status'],
				(string) $validation['message']
			);
			$result['dimensions']    = $dimensions;
			$result['sourceSamples'] = $source_samples;
			$result['samples']       = $decoded_samples;
			$result['outputBytes']   = max(0, (int) ultracache_safe_filesize($test_file, 'media_avif_self_test_imagick_opaque_size'));
			return $result;
		} catch (\Throwable $e) {
			/* translators: %s: Imagick opaque JPEG AVIF test error message. */
			return $this->build_avif_self_test_fixture_result(false, 'failed_decode', sprintf(__('Imagick opaque JPEG AVIF test failed: %s', 'ultracache'), $e->getMessage()));
		} finally {
			if ($decoded instanceof \Imagick) {
				$decoded->clear();
				$decoded->destroy();
			}
			if ($image instanceof \Imagick) {
				$image->clear();
				$image->destroy();
			}
			if (is_file($test_file)) {
				ultracache_safe_unlink($test_file);
				$this->optimized_storage_forget_path($test_file);
			}
		}
	}

	/**
	 * Test the production GD path with an opaque odd-height JPEG.
	 *
	 * @param string $source_file Opaque JPEG fixture.
	 * @return array<string,mixed>
	 */
	private function run_gd_opaque_avif_self_test($source_file) {
		if (!function_exists('imagecreatefromjpeg')) {
			return $this->build_avif_self_test_fixture_result(false, 'unavailable', __('GD JPEG decoding is unavailable for the opaque AVIF test.', 'ultracache'));
		}

		$tmp = $this->create_temp_file('ultracache-avif-self-test-gd-opaque');
		if (!$tmp) {
			return $this->build_avif_self_test_fixture_result(false, 'temp_file_failed', __('Unable to create the GD opaque AVIF self-test file.', 'ultracache'));
		}

		$test_file = $tmp . '.avif';
		ultracache_safe_unlink($tmp);
		$image   = null;
		$decoded = null;

		try {
			$source_warning = '';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped handler captures GD JPEG decode warnings and is restored immediately.
			set_error_handler(static function($severity, $message) use (&$source_warning) {
				$source_warning = (string) $message;
				return true;
			});
			try {
				$image = imagecreatefromjpeg($source_file);
			} finally {
				restore_error_handler();
			}
			if (!$image) {
				$message = $source_warning
					/* translators: %s: GD bundled JPEG decoding warning message. */
					? sprintf(__('GD could not decode the bundled opaque JPEG: %s', 'ultracache'), $source_warning)
					: __('GD could not decode the bundled opaque JPEG.', 'ultracache');
				return $this->build_avif_self_test_fixture_result(false, 'source_decode_failed', $message);
			}

			$coordinates    = $this->get_avif_opaque_self_test_sample_coordinates();
			$source_samples = $this->read_gd_avif_self_test_samples($image, $coordinates);
			$warning        = '';
			$encoded        = false;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped handler captures GD codec warnings and is restored immediately.
			set_error_handler(static function($severity, $message) use (&$warning) {
				$warning = (string) $message;
				return true;
			});
			try {
				$encoded = imageavif($image, $test_file, 60);
			} finally {
				restore_error_handler();
			}

			if (!$encoded || !$this->is_valid_generated_media_file($test_file, 'avif', 'media_avif_self_test_gd_opaque_encode')) {
				$message = $warning
					/* translators: %s: GD opaque JPEG AVIF encoding warning message. */
					? sprintf(__('GD opaque JPEG AVIF encoding failed: %s', 'ultracache'), $warning)
					: __('GD did not create a valid AVIF from the opaque JPEG.', 'ultracache');
				return $this->build_avif_self_test_fixture_result(false, 'failed_encode', $message);
			}

			$decode_warning = '';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped handler captures GD AVIF decode warnings and is restored immediately.
			set_error_handler(static function($severity, $message) use (&$decode_warning) {
				$decode_warning = (string) $message;
				return true;
			});
			try {
				$decoded = imagecreatefromavif($test_file);
			} finally {
				restore_error_handler();
			}
			if (!$decoded) {
				$message = $decode_warning
					/* translators: %s: GD opaque JPEG AVIF decoding warning message. */
					? sprintf(__('GD could not decode the opaque JPEG AVIF test output: %s', 'ultracache'), $decode_warning)
					: __('GD could not decode the opaque JPEG AVIF test output.', 'ultracache');
				return $this->build_avif_self_test_fixture_result(false, 'failed_decode', $message);
			}

			$dimensions = array(
				'width'  => (int) imagesx($decoded),
				'height' => (int) imagesy($decoded),
			);
			$decoded_samples = $this->read_gd_avif_self_test_samples($decoded, $coordinates);
			$validation = $this->validate_avif_opaque_self_test_samples($dimensions, $source_samples, $decoded_samples);
			$result = $this->build_avif_self_test_fixture_result(
				!empty($validation['passed']),
				(string) $validation['status'],
				(string) $validation['message']
			);
			$result['dimensions']    = $dimensions;
			$result['sourceSamples'] = $source_samples;
			$result['samples']       = $decoded_samples;
			$result['outputBytes']   = max(0, (int) ultracache_safe_filesize($test_file, 'media_avif_self_test_gd_opaque_size'));
			return $result;
		} catch (\Throwable $e) {
			/* translators: %s: GD opaque JPEG AVIF test error message. */
			return $this->build_avif_self_test_fixture_result(false, 'failed_decode', sprintf(__('GD opaque JPEG AVIF test failed: %s', 'ultracache'), $e->getMessage()));
		} finally {
			if ($decoded) {
				imagedestroy($decoded);
			}
			if ($image) {
				imagedestroy($image);
			}
			if (is_file($test_file)) {
				ultracache_safe_unlink($test_file);
				$this->optimized_storage_forget_path($test_file);
			}
		}
	}

	/**
	 * Build a normalized per-fixture result.
	 *
	 * @param bool   $passed  Whether the fixture passed.
	 * @param string $status  Machine-readable status.
	 * @param string $message Human-readable status.
	 * @return array<string,mixed>
	 */
	private function build_avif_self_test_fixture_result($passed, $status, $message) {
		return array(
			'passed'        => (bool) $passed,
			'status'        => sanitize_key((string) $status),
			'message'       => sanitize_text_field((string) $message),
			'dimensions'    => array(),
			'sourceSamples' => array(),
			'samples'       => array(),
			'outputBytes'   => 0,
		);
	}

	/**
	 * Read known pixel samples with Imagick.
	 *
	 * @param Imagick $image Decoded AVIF image.
	 * @return array<string,array<string,float>>
	 */
	private function read_imagick_avif_self_test_samples($image, array $coordinates = array()) {
		if (empty($coordinates)) {
			$coordinates = $this->get_avif_self_test_sample_coordinates();
		}
		$samples     = array();
		foreach ($coordinates as $name => $coordinate) {
			$pixel = $image->getImagePixelColor((int) $coordinate[0], (int) $coordinate[1]);
			$samples[$name] = array(
				'r' => round((float) $pixel->getColorValue(\Imagick::COLOR_RED), 4),
				'g' => round((float) $pixel->getColorValue(\Imagick::COLOR_GREEN), 4),
				'b' => round((float) $pixel->getColorValue(\Imagick::COLOR_BLUE), 4),
				'a' => round((float) $pixel->getColorValue(\Imagick::COLOR_ALPHA), 4),
			);
		}
		return $samples;
	}

	/**
	 * Read known pixel samples with GD.
	 *
	 * @param resource|GdImage $image Decoded AVIF image.
	 * @return array<string,array<string,float>>
	 */
	private function read_gd_avif_self_test_samples($image, array $coordinates = array()) {
		if (empty($coordinates)) {
			$coordinates = $this->get_avif_self_test_sample_coordinates();
		}
		$samples     = array();
		foreach ($coordinates as $name => $coordinate) {
			$index = imagecolorat($image, (int) $coordinate[0], (int) $coordinate[1]);
			$color = imagecolorsforindex($image, $index);
			$alpha = isset($color['alpha']) ? (int) $color['alpha'] : 0;
			$samples[$name] = array(
				'r' => round(((int) ($color['red'] ?? 0)) / 255, 4),
				'g' => round(((int) ($color['green'] ?? 0)) / 255, 4),
				'b' => round(((int) ($color['blue'] ?? 0)) / 255, 4),
				'a' => round(1 - (max(0, min(127, $alpha)) / 127), 4),
			);
		}
		return $samples;
	}

	/**
	 * Get deterministic sample coordinates.
	 *
	 * @return array<string,array<int,int>>
	 */
	private function get_avif_self_test_sample_coordinates() {
		return array(
			'red'         => array(2, 2),
			'green'       => array(11, 2),
			'blue'        => array(2, 11),
			'white'       => array(11, 11),
			'transparent' => array(14, 5),
			'partial'     => array(15, 5),
		);
	}


	/**
	 * Get opaque JPEG regression-test sample coordinates.
	 *
	 * @return array<string,array<int,int>>
	 */
	private function get_avif_opaque_self_test_sample_coordinates() {
		return array(
			'top_left'     => array(33, 31),
			'top_right'    => array(237, 31),
			'bottom_left'  => array(33, 137),
			'bottom_right' => array(237, 137),
			'centre_left'  => array(93, 83),
			'centre_right' => array(207, 83),
		);
	}

	/**
	 * Compare decoded opaque AVIF pixels with the source JPEG samples.
	 *
	 * @param array<string,int>                 $dimensions      Decoded dimensions.
	 * @param array<string,array<string,float>> $source_samples  Source JPEG samples.
	 * @param array<string,array<string,float>> $decoded_samples Decoded AVIF samples.
	 * @return array<string,mixed>
	 */
	private function validate_avif_opaque_self_test_samples(array $dimensions, array $source_samples, array $decoded_samples) {
		if (300 !== (int) ($dimensions['width'] ?? 0) || 169 !== (int) ($dimensions['height'] ?? 0)) {
			return array(
				'passed'  => false,
				'status'  => 'failed_dimensions',
				'message' => __('The opaque JPEG AVIF dimensions do not match the 300x169 source.', 'ultracache'),
			);
		}

		$total_difference = 0.0;
		$channel_count    = 0;
		foreach ($this->get_avif_opaque_self_test_sample_coordinates() as $name => $coordinate) {
			if (!isset($source_samples[$name], $decoded_samples[$name])) {
				return array(
					'passed'  => false,
					'status'  => 'failed_pixel_validation',
					/* translators: %s: Missing AVIF test sample name. */
					'message' => sprintf(__('The opaque JPEG AVIF test is missing the %s sample.', 'ultracache'), $name),
				);
			}

			foreach (array('r', 'g', 'b') as $channel) {
				$difference = abs((float) $source_samples[$name][$channel] - (float) $decoded_samples[$name][$channel]);
				if ($difference > 0.38) {
					return array(
						'passed'  => false,
						'status'  => 'failed_pixel_validation',
						/* translators: 1: AVIF test sample name, 2: Color channel name. */
						'message' => sprintf(__('Opaque JPEG AVIF pixel validation failed for the %1$s %2$s channel.', 'ultracache'), $name, strtoupper($channel)),
					);
				}
				$total_difference += $difference;
				$channel_count++;
			}

			if ((float) ($decoded_samples[$name]['a'] ?? 1) < 0.85) {
				return array(
					'passed'  => false,
					'status'  => 'failed_alpha_validation',
					'message' => __('The opaque JPEG AVIF output contains unexpected transparency.', 'ultracache'),
				);
			}
		}

		$mean_difference = $channel_count > 0 ? $total_difference / $channel_count : 1.0;
		if ($mean_difference > 0.18) {
			return array(
				'passed'  => false,
				'status'  => 'failed_pixel_validation',
				'message' => __('The opaque JPEG AVIF output differs too much from the source pixels.', 'ultracache'),
			);
		}

		return array(
			'passed'  => true,
			'status'  => 'passed',
			'message' => __('Opaque JPEG AVIF encode, full decode, and sampled pixel validation passed.', 'ultracache'),
		);
	}

	/**
	 * Validate decoded dimensions, RGB regions, and alpha samples.
	 *
	 * @param array<string,int>                         $dimensions Decoded dimensions.
	 * @param array<string,array<string,float>>         $samples    Decoded samples.
	 * @return array<string,mixed>
	 */
	private function validate_avif_self_test_samples(array $dimensions, array $samples) {
		if (16 !== (int) ($dimensions['width'] ?? 0) || 16 !== (int) ($dimensions['height'] ?? 0)) {
			return array(
				'passed'  => false,
				'status'  => 'failed_dimensions',
				'message' => __('The decoded AVIF dimensions do not match the 16x16 test source.', 'ultracache'),
			);
		}

		$required = array('red', 'green', 'blue', 'white', 'transparent', 'partial');
		foreach ($required as $name) {
			if (!isset($samples[$name])) {
				return array(
					'passed'  => false,
					'status'  => 'failed_pixel_validation',
					/* translators: %s: Missing decoded AVIF sample name. */
					'message' => sprintf(__('The decoded AVIF is missing the expected %s sample.', 'ultracache'), $name),
				);
			}
		}

		$red   = $samples['red'];
		$green = $samples['green'];
		$blue  = $samples['blue'];
		$white = $samples['white'];
		$pixel_checks = array(
			'red'   => ($red['r'] >= 0.55 && ($red['r'] - $red['g']) >= 0.25 && ($red['r'] - $red['b']) >= 0.25 && $red['a'] >= 0.75),
			'green' => ($green['g'] >= 0.55 && ($green['g'] - $green['r']) >= 0.25 && ($green['g'] - $green['b']) >= 0.25 && $green['a'] >= 0.75),
			'blue'  => ($blue['b'] >= 0.55 && ($blue['b'] - $blue['r']) >= 0.25 && ($blue['b'] - $blue['g']) >= 0.25 && $blue['a'] >= 0.75),
			'white' => ($white['r'] >= 0.55 && $white['g'] >= 0.55 && $white['b'] >= 0.55 && $white['a'] >= 0.75),
		);

		foreach ($pixel_checks as $name => $passed) {
			if (!$passed) {
				return array(
					'passed'  => false,
					'status'  => 'failed_pixel_validation',
					/* translators: %s: AVIF test region name. */
					'message' => sprintf(__('AVIF pixel validation failed for the %s test region.', 'ultracache'), $name),
				);
			}
		}

		$transparent_alpha = (float) ($samples['transparent']['a'] ?? 1);
		$partial_alpha     = (float) ($samples['partial']['a'] ?? 1);
		if ($transparent_alpha > 0.20 || $partial_alpha < 0.20 || $partial_alpha > 0.80) {
			return array(
				'passed'  => false,
				'status'  => 'failed_alpha_validation',
				'message' => __('AVIF alpha validation failed for the transparent or semi-transparent test pixels.', 'ultracache'),
			);
		}

		return array(
			'passed'  => true,
			'status'  => 'passed',
			'message' => __('AVIF encode, full decode, RGB, and alpha validation passed.', 'ultracache'),
		);
	}

	/**
	 * Build a normalized engine result.
	 *
	 * @param string $engine    Encoder name.
	 * @param bool   $available Whether the encoder is available.
	 * @param bool   $passed    Whether validation passed.
	 * @param string $status    Machine-readable result.
	 * @param string $message   Human-readable result.
	 * @return array<string,mixed>
	 */
	private function build_avif_self_test_engine_result($engine, $available, $passed, $status, $message) {
		return array(
			'engine'      => sanitize_key((string) $engine),
			'available'   => (bool) $available,
			'passed'      => (bool) $passed,
			'status'      => sanitize_key((string) $status),
			'message'     => sanitize_text_field((string) $message),
			'dimensions'  => array(),
			'samples'     => array(),
			'outputBytes' => 0,
		);
	}

	/**
	 * Build the complete stored report.
	 *
	 * @param string                    $fingerprint Environment fingerprint.
	 * @param array<string,mixed>       $environment Fingerprint source values.
	 * @param array<string,array>       $engines     Engine results.
	 * @return array<string,mixed>
	 */
	private function build_avif_self_test_report($fingerprint, array $environment, array $engines) {
		$passed_engines    = array();
		$available_engines = array();
		$failed_messages   = array();

		foreach ($engines as $engine => $result) {
			if (!empty($result['available'])) {
				$available_engines[] = (string) $engine;
			}
			if (!empty($result['passed'])) {
				$passed_engines[] = (string) $engine;
			} elseif (!empty($result['available']) && !empty($result['message'])) {
				$failed_messages[] = ucfirst((string) $engine) . ': ' . (string) $result['message'];
			}
		}

		if (!empty($passed_engines)) {
			$status  = 'passed';
			/* translators: %s: Comma-separated list of AVIF encoder names. */
			$message = sprintf(__('AVIF encoder self-test passed with: %s.', 'ultracache'), implode(', ', array_map('ucfirst', $passed_engines)));
		} elseif (empty($available_engines)) {
			$status  = 'unavailable';
			$message = __('No AVIF encoder with matching decode support is available.', 'ultracache');
		} else {
			$status  = 'failed';
			$message = !empty($failed_messages)
				? implode(' ', $failed_messages)
				: __('Every available AVIF encoder failed pixel validation.', 'ultracache');
		}

		return array(
			'testVersion'     => self::AVIF_SELF_TEST_VERSION,
			'fingerprint'     => (string) $fingerprint,
			'testedAt'        => time(),
			'cached'          => false,
			'passed'          => !empty($passed_engines),
			'status'          => $status,
			'message'         => $message,
			'preferredEngine' => !empty($passed_engines) ? (string) reset($passed_engines) : '',
			'testAssets'      => array(
				'opaque' => 'assets/diagnostics/avif-self-test-opaque-300x169.jpg',
				'alpha'  => 'assets/diagnostics/avif-self-test-16x16.png',
			),
			'environment'     => $environment,
			'engines'         => $engines,
		);
	}

	/**
	 * Invalidate old support summaries after a forced or automatic test.
	 *
	 * @return void
	 */
	private function invalidate_avif_self_test_support_caches() {
		delete_transient('ultracache_media_support_status_v4');
		delete_transient('ultracache_media_support_status_v5');
		delete_transient('ultracache_imagick_avif_alpha_probe_v1');
		delete_transient('ultracache_gd_avif_alpha_probe_v3');
	}
}
