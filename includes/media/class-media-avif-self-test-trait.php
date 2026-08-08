<?php
/**
 * AVIF encoder self-test support for UltraCache media conversion.
 */

if (!defined('ABSPATH')) {
	exit;
}

trait Ultra_Cache_Media_Avif_Self_Test_Trait {

	private function read_avif_encoder_self_test_state($fingerprint) {
		if (!function_exists('ultracache_get_state_record_read_only')) {
			return array();
		}

		$record = ultracache_get_state_record_read_only(self::AVIF_SELF_TEST_STATE);
		$payload = is_array($record['payload'] ?? null) ? $record['payload'] : array();
		$report = is_array($payload['report'] ?? null) ? $payload['report'] : array();
		if (empty($report['fingerprint']) || !hash_equals((string) $report['fingerprint'], (string) $fingerprint)) {
			return array();
		}

		$report['cached'] = true;
		$report['source'] = 'persistent';
		return $report;
	}

	private function persist_avif_encoder_self_test_state(array $report) {
		if (!function_exists('ultracache_mutate_state_record')) {
			return false;
		}

		$mutation = ultracache_mutate_state_record(
			self::AVIF_SELF_TEST_STATE,
			static function () use ($report) {
				return array(
					'schemaVersion' => 1,
					'recordedAt' => max(0, (int) ($report['testedAt'] ?? time())),
					'fingerprint' => (string) ($report['fingerprint'] ?? ''),
					'report' => $report,
				);
			},
			5,
			array()
		);

		return !empty($mutation['success']);
	}


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
		$alpha_source   = $this->get_avif_encoder_self_test_source_path();
		$opaque_source  = $this->get_avif_encoder_opaque_self_test_source_path();
		$decoder_source = $this->get_avif_decoder_self_test_source_path();
		$environment    = $this->get_avif_encoder_self_test_environment($alpha_source, $opaque_source, $decoder_source);
		$fingerprint    = hash('sha256', wp_json_encode($environment));
		$cached         = $this->read_avif_encoder_self_test_state($fingerprint);

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
			$missing_engines = array(
				'imagick' => $this->build_avif_self_test_engine_result('imagick', false, false, 'source_missing', __('A bundled AVIF self-test image is missing or unreadable.', 'ultracache')),
				'gd'      => $this->build_avif_self_test_engine_result('gd', false, false, 'source_missing', __('A bundled AVIF self-test image is missing or unreadable.', 'ultracache')),
			);
			$missing_engines['imagick'] = $this->attach_avif_source_capability_results(
				$missing_engines['imagick'],
				$this->run_imagick_avif_source_capability_self_test($decoder_source, $opaque_source)
			);
			$missing_engines['gd'] = $this->attach_avif_source_capability_results(
				$missing_engines['gd'],
				$this->run_gd_avif_source_capability_self_test($decoder_source, $opaque_source)
			);
			$missing = $this->build_avif_self_test_report($fingerprint, $environment, $missing_engines);
			$this->persist_avif_encoder_self_test_state($missing);
			$this->invalidate_avif_self_test_support_caches();
			return $missing;
		}

		$engines = array(
			'imagick' => $this->run_imagick_avif_encoder_self_test($alpha_source, $opaque_source),
			'gd'      => $this->run_gd_avif_encoder_self_test($alpha_source, $opaque_source),
		);
		$engines['imagick'] = $this->attach_avif_source_capability_results(
			$engines['imagick'],
			$this->run_imagick_avif_source_capability_self_test($decoder_source, $opaque_source)
		);
		$engines['gd'] = $this->attach_avif_source_capability_results(
			$engines['gd'],
			$this->run_gd_avif_source_capability_self_test($decoder_source, $opaque_source)
		);
		$report  = $this->build_avif_self_test_report($fingerprint, $environment, $engines);

		$this->persist_avif_encoder_self_test_state($report);
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
	 * Get the bundled AVIF fixture used to prove source decoding independently
	 * from AVIF encoding support.
	 *
	 * @return string
	 */
	private function get_avif_decoder_self_test_source_path() {
		return ultracache_plugin_dir('assets/diagnostics/avif-decode-self-test-opaque-300x169.avif');
	}

	/**
	 * Get the bundled JPEG carrying a valid non-sRGB ICC profile.
	 *
	 * @return string
	 */
	private function get_avif_encoder_color_profile_self_test_source_path() {
		return ultracache_plugin_dir('assets/diagnostics/color-profile-self-test-cmyk.jpg');
	}

	/**
	 * Build the environment data that invalidates stale self-test results.
	 *
	 * @param string $alpha_source  Transparent PNG fixture path.
	 * @param string $opaque_source  Opaque JPEG fixture path.
	 * @param string $decoder_source Bundled AVIF decode fixture path.
	 * @return array<string,mixed>
	 */
	private function get_avif_encoder_self_test_environment($alpha_source, $opaque_source, $decoder_source) {
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
		$decoder_contents = is_readable($decoder_source)
			? (string) ultracache_safe_file_get_contents($decoder_source, 'media_avif_self_test_decoder_asset_hash', true)
			: '';
		$profile_source = $this->get_avif_encoder_color_profile_self_test_source_path();
		$profile_contents = is_readable($profile_source)
			? (string) ultracache_safe_file_get_contents($profile_source, 'media_avif_self_test_profile_asset_hash', true)
			: '';
		$srgb_path = ultracache_plugin_dir('assets/diagnostics/ultracache-srgb.icc');
		$srgb_contents = is_readable($srgb_path)
			? (string) ultracache_safe_file_get_contents($srgb_path, 'media_avif_self_test_srgb_asset_hash', true)
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

		$imagick_webp_reported = false;
		if (class_exists('Imagick') && method_exists('Imagick', 'queryFormats')) {
			try {
				$formats = \Imagick::queryFormats('WEBP');
				$imagick_webp_reported = is_array($formats) && in_array('WEBP', $formats, true);
			} catch (\Throwable $e) {
				$imagick_webp_reported = false;
			}
		}

		return array(
			'testVersion'             => self::AVIF_SELF_TEST_VERSION,
			'phpVersion'              => PHP_VERSION,
			'imagickExtensionVersion' => extension_loaded('imagick') ? (string) phpversion('imagick') : '',
			'imageMagickVersion'       => $imagick_version,
			'imagickAvifReported'      => $imagick_avif_reported,
			'imagickWebpReported'      => $imagick_webp_reported,
			'gdVersion'                => $gd_version,
			'gdAvifEncode'             => function_exists('imageavif'),
			'gdAvifDecode'             => function_exists('imagecreatefromavif'),
			'gdWebpEncode'             => function_exists('imagewebp'),
			'gdWebpDecode'             => function_exists('imagecreatefromwebp'),
			'alphaAssetSha256'         => '' !== $alpha_contents ? hash('sha256', $alpha_contents) : '',
			'opaqueAssetSha256'        => '' !== $opaque_contents ? hash('sha256', $opaque_contents) : '',
			'decoderAssetSha256'       => '' !== $decoder_contents ? hash('sha256', $decoder_contents) : '',
			'profileAssetSha256'       => '' !== $profile_contents ? hash('sha256', $profile_contents) : '',
			'srgbProfileSha256'        => '' !== $srgb_contents ? hash('sha256', $srgb_contents) : '',
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

			$profile_result = $this->run_imagick_color_profile_avif_self_test($this->get_avif_encoder_color_profile_self_test_source_path());
			$result['tests']['colorProfile'] = $profile_result;
			$result['colorProfilePassed'] = !empty($profile_result['passed']);

			$result['passed']  = true;
			$result['status']  = 'passed';
			$result['message'] = !empty($profile_result['passed'])
				? __('Imagick passed opaque, transparent, and color-profile AVIF tests.', 'ultracache')
				: __('Imagick passed the pixel tests, but profiled sources will be skipped because color management was not verified.', 'ultracache');
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
	 * Verify that the production Imagick color contract either preserves ICC
	 * bytes or converts the decoded pixels to sRGB before metadata removal.
	 *
	 * @param string $source_file Profiled JPEG fixture.
	 * @return array<string,mixed>
	 */
	private function run_imagick_color_profile_avif_self_test($source_file) {
		if (!is_file($source_file) || !is_readable($source_file)) {
			return $this->build_avif_self_test_fixture_result(false, 'source_missing', __('The bundled color-profile AVIF self-test image is missing or unreadable.', 'ultracache'));
		}

		$admission = $this->get_media_source_decode_admission($source_file, 'imagick');
		if (empty($admission['allowed'])) {
			return $this->build_avif_self_test_fixture_result(false, 'source_rejected', __('The color-profile AVIF self-test source failed decoder admission.', 'ultracache'));
		}

		$tmp = $this->create_temp_file('ultracache-avif-self-test-imagick-profile');
		if (!$tmp) {
			return $this->build_avif_self_test_fixture_result(false, 'temp_file_failed', __('Unable to create the Imagick color-profile AVIF self-test file.', 'ultracache'));
		}
		$test_file = $tmp . '.avif';
		ultracache_safe_unlink($tmp);
		$image = null;
		$reference = null;
		$decoded = null;
		$resource_state = array('applied' => false, 'previous' => array());
		$decoded_resource_state = array('applied' => false, 'previous' => array());
		try {
			$image = new \Imagick();
			$resource_state = $this->apply_media_imagick_resource_limits($image, $admission);
			if (false === $resource_state || !$image->readImage($source_file)) {
				return $this->build_avif_self_test_fixture_result(false, 'source_decode_failed', __('Imagick could not decode the color-profile AVIF self-test source.', 'ultracache'));
			}
			$profiles = $this->capture_media_imagick_color_profiles($image);
			if (false === $profiles || empty($profiles)) {
				return $this->build_avif_self_test_fixture_result(false, 'profile_unreadable', __('Imagick did not expose the bundled source ICC profile.', 'ultracache'));
			}

			/*
			 * Build a reference from the same decoded pixels and the same runtime color
			 * management stack. The final AVIF must round-trip close to these sRGB
			 * samples whether the delegate preserves ICC or uses the sRGB fallback.
			 */
			$reference = clone $image;
			if (!$this->convert_media_imagick_image_to_srgb($reference, $profiles)) {
				return $this->build_avif_self_test_fixture_result(false, 'reference_conversion_failed', __('Imagick could not create the sRGB reference pixels for the color-profile self-test.', 'ultracache'));
			}
			$coordinates = $this->get_avif_color_profile_self_test_sample_coordinates();
			$source_samples = $this->read_imagick_avif_self_test_samples($reference, $coordinates);

			$image->setImageFormat('avif');
			$image->setImageCompressionQuality(60);
			if (!$this->strip_and_restore_media_imagick_color_profiles($image, $profiles)) {
				return $this->build_avif_self_test_fixture_result(false, 'profile_restore_failed', __('Imagick could not restore the bundled source ICC profile.', 'ultracache'));
			}
			$mode = 'preserved';
			if (!$image->writeImage($test_file) || !$this->is_valid_generated_media_file($test_file, 'avif', 'media_avif_self_test_imagick_profile_encode')) {
				return $this->build_avif_self_test_fixture_result(false, 'failed_encode', __('Imagick did not create a valid AVIF from the profiled JPEG.', 'ultracache'));
			}
			if (!$this->verify_media_imagick_output_color_profiles($test_file, $profiles, $admission)) {
				ultracache_safe_unlink($test_file);
				if (!$this->convert_media_imagick_image_to_srgb($image, $profiles)) {
					return $this->build_avif_self_test_fixture_result(false, 'color_management_failed', __('Imagick neither preserved the ICC profile nor converted the pixels to sRGB.', 'ultracache'));
				}
				$mode = 'srgb-fallback';
				if (!$image->writeImage($test_file) || !$this->is_valid_generated_media_file($test_file, 'avif', 'media_avif_self_test_imagick_profile_srgb_encode')) {
					return $this->build_avif_self_test_fixture_result(false, 'srgb_encode_failed', __('Imagick could not encode the sRGB color-management fallback.', 'ultracache'));
				}
			}

			$decoded = new \Imagick();
			$decoded_resource_state = $this->apply_media_imagick_resource_limits($decoded, $admission);
			if (false === $decoded_resource_state || !$decoded->readImage($test_file)) {
				return $this->build_avif_self_test_fixture_result(false, 'output_decode_failed', __('Imagick could not decode the generated color-profile AVIF for pixel validation.', 'ultracache'));
			}
			$output_profiles = $this->capture_media_imagick_color_profiles($decoded);
			if (false === $output_profiles) {
				return $this->build_avif_self_test_fixture_result(false, 'output_profile_unreadable', __('Imagick could not inspect the generated color-profile AVIF during pixel validation.', 'ultracache'));
			}
			if (!empty($output_profiles) && !$this->convert_media_imagick_image_to_srgb($decoded, $output_profiles)) {
				return $this->build_avif_self_test_fixture_result(false, 'output_conversion_failed', __('Imagick could not normalize the generated color-profile AVIF to sRGB for pixel validation.', 'ultracache'));
			}
			$dimensions = array(
				'width' => (int) $decoded->getImageWidth(),
				'height' => (int) $decoded->getImageHeight(),
			);
			$decoded_samples = $this->read_imagick_avif_self_test_samples($decoded, $coordinates);
			$pixel_validation = $this->validate_avif_color_profile_self_test_samples($dimensions, $source_samples, $decoded_samples);
			if (empty($pixel_validation['passed'])) {
				return $this->build_avif_self_test_fixture_result(false, (string) $pixel_validation['status'], (string) $pixel_validation['message']);
			}

			$result = $this->build_avif_self_test_fixture_result(true, 'passed', __('Imagick preserved the source ICC profile or converted the pixels safely to sRGB.', 'ultracache'));
			$result['mode'] = $mode;
			$result['dimensions'] = $dimensions;
			$result['sourceSamples'] = $source_samples;
			$result['samples'] = $decoded_samples;
			$result['meanRgbDifference'] = (float) ($pixel_validation['meanDifference'] ?? 0.0);
			$result['maxRgbDifference'] = (float) ($pixel_validation['maxDifference'] ?? 0.0);
			$result['sourceProfileSha256'] = $this->get_media_color_profile_hashes($profiles);
			$result['outputBytes'] = max(0, (int) ultracache_safe_filesize($test_file, 'media_avif_self_test_imagick_profile_size'));
			return $result;
		} catch (\Throwable $e) {
			/* translators: %s: Imagick exception message. */
			return $this->build_avif_self_test_fixture_result(false, 'failed_decode', sprintf(__('Imagick color-profile AVIF test failed: %s', 'ultracache'), $e->getMessage()));
		} finally {
			if ($decoded instanceof \Imagick) {
				if (!empty($decoded_resource_state['applied']) && !empty($decoded_resource_state['previous']) && is_array($decoded_resource_state['previous'])) {
					$this->restore_media_imagick_resource_limits($decoded, $decoded_resource_state['previous']);
				}
				$decoded->clear();
				$decoded->destroy();
			}
			if ($reference instanceof \Imagick) {
				$reference->clear();
				$reference->destroy();
			}
			if ($image instanceof \Imagick) {
				if (!empty($resource_state['applied']) && !empty($resource_state['previous']) && is_array($resource_state['previous'])) {
					$this->restore_media_imagick_resource_limits($image, $resource_state['previous']);
				}
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
	 * Get deterministic sample coordinates for the 32x32 profiled JPEG fixture.
	 *
	 * @return array<string,array<int,int>>
	 */
	private function get_avif_color_profile_self_test_sample_coordinates() {
		return array(
			'top_left' => array(4, 4),
			'top_right' => array(27, 4),
			'centre' => array(16, 16),
			'bottom_left' => array(4, 27),
			'bottom_right' => array(27, 27),
		);
	}

	/**
	 * Compare the generated AVIF against the runtime-generated sRGB reference.
	 *
	 * @param array<string,int>                 $dimensions      Output dimensions.
	 * @param array<string,array<string,float>> $source_samples  Reference samples.
	 * @param array<string,array<string,float>> $decoded_samples Decoded AVIF samples.
	 * @return array<string,mixed>
	 */
	private function validate_avif_color_profile_self_test_samples(array $dimensions, array $source_samples, array $decoded_samples) {
		if (32 !== (int) ($dimensions['width'] ?? 0) || 32 !== (int) ($dimensions['height'] ?? 0)) {
			return array(
				'passed' => false,
				'status' => 'failed_dimensions',
				'message' => __('The color-profile AVIF dimensions do not match the 32x32 source.', 'ultracache'),
			);
		}

		$total_difference = 0.0;
		$maximum_difference = 0.0;
		$channel_count = 0;
		foreach ($this->get_avif_color_profile_self_test_sample_coordinates() as $name => $coordinate) {
			unset($coordinate);
			if (!isset($source_samples[$name], $decoded_samples[$name])) {
				return array(
					'passed' => false,
					'status' => 'failed_pixel_validation',
					/* translators: %s: Missing color-profile test sample name. */
					'message' => sprintf(__('The color-profile AVIF test is missing the %s sample.', 'ultracache'), $name),
				);
			}
			foreach (array('r', 'g', 'b') as $channel) {
				$difference = abs((float) ($source_samples[$name][$channel] ?? 0.0) - (float) ($decoded_samples[$name][$channel] ?? 0.0));
				$total_difference += $difference;
				$maximum_difference = max($maximum_difference, $difference);
				++$channel_count;
			}
		}

		$mean_difference = $channel_count > 0 ? $total_difference / $channel_count : 1.0;
		if ($mean_difference > 0.12 || $maximum_difference > 0.30) {
			return array(
				'passed' => false,
				'status' => 'failed_color_validation',
				'message' => __('The generated AVIF color samples differ too far from the sRGB reference.', 'ultracache'),
				'meanDifference' => $mean_difference,
				'maxDifference' => $maximum_difference,
			);
		}

		return array(
			'passed' => true,
			'status' => 'passed',
			'message' => __('The generated AVIF passed the color-managed sRGB pixel round trip.', 'ultracache'),
			'meanDifference' => $mean_difference,
			'maxDifference' => $maximum_difference,
		);
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
	 * Attach AVIF source decode and AVIF-to-WebP capability results without
	 * changing the existing AVIF encoder support contract.
	 *
	 * @param array<string,mixed> $engine_result     Existing encoder result.
	 * @param array<string,array> $capability_results Source capability results.
	 * @return array<string,mixed>
	 */
	private function attach_avif_source_capability_results(array $engine_result, array $capability_results) {
		$decode_result    = isset($capability_results['decode']) && is_array($capability_results['decode'])
			? $capability_results['decode']
			: $this->build_avif_self_test_fixture_result(false, 'unavailable', __('AVIF source decoding was not tested.', 'ultracache'));
		$transcode_result = isset($capability_results['avifToWebp']) && is_array($capability_results['avifToWebp'])
			? $capability_results['avifToWebp']
			: $this->build_avif_self_test_fixture_result(false, 'unavailable', __('AVIF to WebP conversion was not tested.', 'ultracache'));

		$engine_result['avifEncodePassed']  = !empty($engine_result['passed']);
		$engine_result['avifDecodePassed']  = !empty($decode_result['passed']);
		$engine_result['avifToWebpPassed'] = !empty($transcode_result['passed']);
		$engine_result['capabilities']      = array(
			'avifEncode' => array(
				'passed'  => !empty($engine_result['passed']),
				'status'  => (string) ($engine_result['status'] ?? 'unavailable'),
				'message' => (string) ($engine_result['message'] ?? ''),
			),
			'avifDecode' => $decode_result,
			'avifToWebp' => $transcode_result,
		);
		if (!isset($engine_result['tests']) || !is_array($engine_result['tests'])) {
			$engine_result['tests'] = array();
		}
		$engine_result['tests']['sourceDecode'] = $decode_result;
		$engine_result['tests']['avifToWebp']   = $transcode_result;

		return $engine_result;
	}

	/**
	 * Prove Imagick AVIF source decoding and AVIF-to-WebP conversion using a
	 * bundled AVIF fixture that does not depend on runtime AVIF encoding.
	 *
	 * @param string $source_file           Bundled AVIF fixture.
	 * @param string $reference_source_file Matching opaque JPEG reference.
	 * @return array<string,array>
	 */
	private function run_imagick_avif_source_capability_self_test($source_file, $reference_source_file) {
		$unavailable = array(
			'decode'      => $this->build_avif_self_test_fixture_result(false, 'unavailable', __('Imagick AVIF source decoding is unavailable.', 'ultracache')),
			'avifToWebp' => $this->build_avif_self_test_fixture_result(false, 'unavailable', __('Imagick AVIF to WebP conversion is unavailable.', 'ultracache')),
		);
		if (!extension_loaded('imagick') || !class_exists('Imagick') || !method_exists('Imagick', 'queryFormats')) {
			return $unavailable;
		}
		if (!is_file($source_file) || !is_readable($source_file) || !is_file($reference_source_file) || !is_readable($reference_source_file)) {
			$missing = $this->build_avif_self_test_fixture_result(false, 'source_missing', __('The bundled AVIF source-decode fixture or its JPEG reference is missing or unreadable.', 'ultracache'));
			return array('decode' => $missing, 'avifToWebp' => $missing);
		}

		try {
			$formats = \Imagick::queryFormats('AVIF');
		} catch (\Throwable $e) {
			$formats = array();
		}
		if (!is_array($formats) || !in_array('AVIF', $formats, true)) {
			return $unavailable;
		}

		$reference    = null;
		$decoded      = null;
		$webp_image   = null;
		$webp_decoded = null;
		$test_file    = '';
		try {
			$reference = new \Imagick($reference_source_file);
			$coordinates = $this->get_avif_opaque_self_test_sample_coordinates();
			$source_samples = $this->read_imagick_avif_self_test_samples($reference, $coordinates);
			$decoded = new \Imagick($source_file);
			$dimensions = array(
				'width'  => (int) $decoded->getImageWidth(),
				'height' => (int) $decoded->getImageHeight(),
			);
			$decoded_samples = $this->read_imagick_avif_self_test_samples($decoded, $coordinates);
			$validation = $this->validate_avif_opaque_self_test_samples($dimensions, $source_samples, $decoded_samples);
			$decode_result = $this->build_avif_self_test_fixture_result(
				!empty($validation['passed']),
				(string) $validation['status'],
				(string) $validation['message']
			);
			$decode_result['dimensions']    = $dimensions;
			$decode_result['sourceSamples'] = $source_samples;
			$decode_result['samples']       = $decoded_samples;
			$decode_result['outputBytes']   = max(0, (int) ultracache_safe_filesize($source_file, 'media_avif_source_self_test_imagick_size'));
			if (empty($decode_result['passed'])) {
				$blocked = $this->build_avif_self_test_fixture_result(false, 'source_decode_failed', __('Imagick AVIF to WebP conversion was not attempted because AVIF source decoding failed.', 'ultracache'));
				return array('decode' => $decode_result, 'avifToWebp' => $blocked);
			}

			try {
				$webp_formats = \Imagick::queryFormats('WEBP');
			} catch (\Throwable $e) {
				$webp_formats = array();
			}
			if (!is_array($webp_formats) || !in_array('WEBP', $webp_formats, true)) {
				$webp_unavailable = $this->build_avif_self_test_fixture_result(false, 'unavailable', __('Imagick WebP encoding is unavailable.', 'ultracache'));
				return array('decode' => $decode_result, 'avifToWebp' => $webp_unavailable);
			}

			$tmp = $this->create_temp_file('ultracache-avif-to-webp-self-test-imagick');
			if (!$tmp) {
				$temp_failed = $this->build_avif_self_test_fixture_result(false, 'temp_file_failed', __('Unable to create the Imagick AVIF to WebP self-test file.', 'ultracache'));
				return array('decode' => $decode_result, 'avifToWebp' => $temp_failed);
			}
			$test_file = $tmp . '.webp';
			ultracache_safe_unlink($tmp);
			$webp_image = clone $decoded;
			$webp_image->setImageFormat('webp');
			$webp_image->setImageCompressionQuality(82);
			if (method_exists($webp_image, 'stripImage')) {
				$webp_image->stripImage();
			}
			if (!$webp_image->writeImage($test_file) || !$this->is_valid_generated_media_file($test_file, 'webp', 'media_avif_to_webp_self_test_imagick_encode')) {
				$failed = $this->build_avif_self_test_fixture_result(false, 'failed_encode', __('Imagick did not create a valid WebP from the bundled AVIF source.', 'ultracache'));
				return array('decode' => $decode_result, 'avifToWebp' => $failed);
			}

			$webp_decoded = new \Imagick($test_file);
			$webp_dimensions = array(
				'width'  => (int) $webp_decoded->getImageWidth(),
				'height' => (int) $webp_decoded->getImageHeight(),
			);
			$webp_samples = $this->read_imagick_avif_self_test_samples($webp_decoded, $coordinates);
			$webp_validation = $this->validate_avif_opaque_self_test_samples($webp_dimensions, $source_samples, $webp_samples);
			$webp_result = $this->build_avif_self_test_fixture_result(
				!empty($webp_validation['passed']),
				(string) $webp_validation['status'],
				!empty($webp_validation['passed'])
					? __('Imagick decoded the bundled AVIF, encoded WebP, and passed sampled pixel validation.', 'ultracache')
					: (string) $webp_validation['message']
			);
			$webp_result['dimensions']    = $webp_dimensions;
			$webp_result['sourceSamples'] = $source_samples;
			$webp_result['samples']       = $webp_samples;
			$webp_result['outputBytes']   = max(0, (int) ultracache_safe_filesize($test_file, 'media_avif_to_webp_self_test_imagick_size'));
			return array('decode' => $decode_result, 'avifToWebp' => $webp_result);
		} catch (\Throwable $e) {
			$failed = $this->build_avif_self_test_fixture_result(
				false,
				'failed_decode',
				sprintf(
					/* translators: %s: Imagick AVIF source capability error. */
					__('Imagick AVIF source capability test failed: %s', 'ultracache'),
					$e->getMessage()
				)
			);
			return array('decode' => $failed, 'avifToWebp' => $failed);
		} finally {
			foreach (array($webp_decoded, $webp_image, $decoded, $reference) as $image) {
				if ($image instanceof \Imagick) {
					$image->clear();
					$image->destroy();
				}
			}
			if ('' !== $test_file && is_file($test_file)) {
				ultracache_safe_unlink($test_file);
				$this->optimized_storage_forget_path($test_file);
			}
		}
	}

	/**
	 * Prove GD AVIF source decoding and AVIF-to-WebP conversion using the same
	 * bundled fixture and validation contract as the Imagick capability test.
	 *
	 * @param string $source_file           Bundled AVIF fixture.
	 * @param string $reference_source_file Matching opaque JPEG reference.
	 * @return array<string,array>
	 */
	private function run_gd_avif_source_capability_self_test($source_file, $reference_source_file) {
		$required_decode = array('imagecreatefromavif', 'imagecreatefromjpeg', 'imagecolorat', 'imagecolorsforindex', 'imagesx', 'imagesy');
		foreach ($required_decode as $function_name) {
			if (!function_exists($function_name)) {
				$unavailable = $this->build_avif_self_test_fixture_result(false, 'unavailable', __('GD AVIF source decoding is unavailable.', 'ultracache'));
				return array('decode' => $unavailable, 'avifToWebp' => $unavailable);
			}
		}
		if (!is_file($source_file) || !is_readable($source_file) || !is_file($reference_source_file) || !is_readable($reference_source_file)) {
			$missing = $this->build_avif_self_test_fixture_result(false, 'source_missing', __('The bundled AVIF source-decode fixture or its JPEG reference is missing or unreadable.', 'ultracache'));
			return array('decode' => $missing, 'avifToWebp' => $missing);
		}

		$reference    = null;
		$decoded      = null;
		$webp_decoded = null;
		$test_file    = '';
		try {
			$reference_warning = '';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped handler captures codec warnings and is restored immediately.
			set_error_handler(static function($severity, $message) use (&$reference_warning) {
				$reference_warning = (string) $message;
				return true;
			});
			try {
				$reference = imagecreatefromjpeg($reference_source_file);
			} finally {
				restore_error_handler();
			}
			if (!$reference) {
				$message = $reference_warning
					? sprintf(
						/* translators: %s: GD JPEG decoding warning. */
						__('GD could not decode the AVIF capability reference JPEG: %s', 'ultracache'),
						$reference_warning
					)
					: __('GD could not decode the AVIF capability reference JPEG.', 'ultracache');
				$failed = $this->build_avif_self_test_fixture_result(false, 'source_decode_failed', $message);
				return array('decode' => $failed, 'avifToWebp' => $failed);
			}

			$decode_warning = '';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped handler captures codec warnings and is restored immediately.
			set_error_handler(static function($severity, $message) use (&$decode_warning) {
				$decode_warning = (string) $message;
				return true;
			});
			try {
				$decoded = imagecreatefromavif($source_file);
			} finally {
				restore_error_handler();
			}
			if (!$decoded) {
				$message = $decode_warning
					? sprintf(
						/* translators: %s: GD AVIF decoding warning. */
						__('GD could not decode the bundled AVIF source fixture: %s', 'ultracache'),
						$decode_warning
					)
					: __('GD could not decode the bundled AVIF source fixture.', 'ultracache');
				$failed = $this->build_avif_self_test_fixture_result(false, 'failed_decode', $message);
				return array('decode' => $failed, 'avifToWebp' => $failed);
			}

			$coordinates = $this->get_avif_opaque_self_test_sample_coordinates();
			$source_samples = $this->read_gd_avif_self_test_samples($reference, $coordinates);
			$dimensions = array(
				'width'  => (int) imagesx($decoded),
				'height' => (int) imagesy($decoded),
			);
			$decoded_samples = $this->read_gd_avif_self_test_samples($decoded, $coordinates);
			$validation = $this->validate_avif_opaque_self_test_samples($dimensions, $source_samples, $decoded_samples);
			$decode_result = $this->build_avif_self_test_fixture_result(
				!empty($validation['passed']),
				(string) $validation['status'],
				(string) $validation['message']
			);
			$decode_result['dimensions']    = $dimensions;
			$decode_result['sourceSamples'] = $source_samples;
			$decode_result['samples']       = $decoded_samples;
			$decode_result['outputBytes']   = max(0, (int) ultracache_safe_filesize($source_file, 'media_avif_source_self_test_gd_size'));
			if (empty($decode_result['passed'])) {
				$blocked = $this->build_avif_self_test_fixture_result(false, 'source_decode_failed', __('GD AVIF to WebP conversion was not attempted because AVIF source decoding failed.', 'ultracache'));
				return array('decode' => $decode_result, 'avifToWebp' => $blocked);
			}

			if (!function_exists('imagewebp') || !function_exists('imagecreatefromwebp')) {
				$webp_unavailable = $this->build_avif_self_test_fixture_result(false, 'unavailable', __('GD WebP encode/decode support is unavailable.', 'ultracache'));
				return array('decode' => $decode_result, 'avifToWebp' => $webp_unavailable);
			}
			$tmp = $this->create_temp_file('ultracache-avif-to-webp-self-test-gd');
			if (!$tmp) {
				$temp_failed = $this->build_avif_self_test_fixture_result(false, 'temp_file_failed', __('Unable to create the GD AVIF to WebP self-test file.', 'ultracache'));
				return array('decode' => $decode_result, 'avifToWebp' => $temp_failed);
			}
			$test_file = $tmp . '.webp';
			ultracache_safe_unlink($tmp);
			$webp_warning = '';
			$encoded = false;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped handler captures codec warnings and is restored immediately.
			set_error_handler(static function($severity, $message) use (&$webp_warning) {
				$webp_warning = (string) $message;
				return true;
			});
			try {
				$encoded = imagewebp($decoded, $test_file, 82);
			} finally {
				restore_error_handler();
			}
			if (!$encoded || !$this->is_valid_generated_media_file($test_file, 'webp', 'media_avif_to_webp_self_test_gd_encode')) {
				$message = $webp_warning
					? sprintf(
						/* translators: %s: GD WebP encoding warning. */
						__('GD AVIF to WebP encoding failed: %s', 'ultracache'),
						$webp_warning
					)
					: __('GD did not create a valid WebP from the bundled AVIF source.', 'ultracache');
				$failed = $this->build_avif_self_test_fixture_result(false, 'failed_encode', $message);
				return array('decode' => $decode_result, 'avifToWebp' => $failed);
			}

			$webp_decode_warning = '';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped handler captures codec warnings and is restored immediately.
			set_error_handler(static function($severity, $message) use (&$webp_decode_warning) {
				$webp_decode_warning = (string) $message;
				return true;
			});
			try {
				$webp_decoded = imagecreatefromwebp($test_file);
			} finally {
				restore_error_handler();
			}
			if (!$webp_decoded) {
				$message = $webp_decode_warning
					? sprintf(
						/* translators: %s: GD WebP decoding warning. */
						__('GD could not decode the AVIF-to-WebP self-test output: %s', 'ultracache'),
						$webp_decode_warning
					)
					: __('GD could not decode the AVIF-to-WebP self-test output.', 'ultracache');
				$failed = $this->build_avif_self_test_fixture_result(false, 'failed_decode', $message);
				return array('decode' => $decode_result, 'avifToWebp' => $failed);
			}

			$webp_dimensions = array(
				'width'  => (int) imagesx($webp_decoded),
				'height' => (int) imagesy($webp_decoded),
			);
			$webp_samples = $this->read_gd_avif_self_test_samples($webp_decoded, $coordinates);
			$webp_validation = $this->validate_avif_opaque_self_test_samples($webp_dimensions, $source_samples, $webp_samples);
			$webp_result = $this->build_avif_self_test_fixture_result(
				!empty($webp_validation['passed']),
				(string) $webp_validation['status'],
				!empty($webp_validation['passed'])
					? __('GD decoded the bundled AVIF, encoded WebP, and passed sampled pixel validation.', 'ultracache')
					: (string) $webp_validation['message']
			);
			$webp_result['dimensions']    = $webp_dimensions;
			$webp_result['sourceSamples'] = $source_samples;
			$webp_result['samples']       = $webp_samples;
			$webp_result['outputBytes']   = max(0, (int) ultracache_safe_filesize($test_file, 'media_avif_to_webp_self_test_gd_size'));
			return array('decode' => $decode_result, 'avifToWebp' => $webp_result);
		} catch (\Throwable $e) {
			$failed = $this->build_avif_self_test_fixture_result(
				false,
				'failed_decode',
				sprintf(
					/* translators: %s: GD AVIF source capability error. */
					__('GD AVIF source capability test failed: %s', 'ultracache'),
					$e->getMessage()
				)
			);
			return array('decode' => $failed, 'avifToWebp' => $failed);
		} finally {
			if ($webp_decoded) {
				imagedestroy($webp_decoded);
			}
			if ($decoded) {
				imagedestroy($decoded);
			}
			if ($reference) {
				imagedestroy($reference);
			}
			if ('' !== $test_file && is_file($test_file)) {
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
				'opaque'      => 'assets/diagnostics/avif-self-test-opaque-300x169.jpg',
				'alpha'       => 'assets/diagnostics/avif-self-test-16x16.png',
				'avifDecode' => 'assets/diagnostics/avif-decode-self-test-opaque-300x169.avif',
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
		$this->invalidate_media_encoder_capability_state('avif_self_test_updated');
	}
}
