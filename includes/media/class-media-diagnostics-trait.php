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

		private function get_media_encoder_capability_fingerprint() {
			$gd = function_exists('gd_info') ? gd_info() : array();
			$alpha_source = $this->get_avif_encoder_self_test_source_path();
			$opaque_source = $this->get_avif_encoder_opaque_self_test_source_path();
			$decoder_source = $this->get_avif_decoder_self_test_source_path();
			$avif_environment = $this->get_avif_encoder_self_test_environment($alpha_source, $opaque_source, $decoder_source);
			$payload = array(
				'schema' => 1,
				'phpVersion' => PHP_VERSION,
				'imagickVersion' => extension_loaded('imagick') ? (string) phpversion('imagick') : '',
				'gdVersion' => is_array($gd) ? (string) ($gd['GD Version'] ?? '') : '',
				'imageAvif' => function_exists('imageavif'),
				'imageCreateFromAvif' => function_exists('imagecreatefromavif'),
				'imageWebp' => function_exists('imagewebp'),
				'imageCreateFromWebp' => function_exists('imagecreatefromwebp'),
				'avifSelfTestVersion' => self::AVIF_SELF_TEST_VERSION,
				'avifEnvironmentFingerprint' => hash('sha256', (string) wp_json_encode($avif_environment)),
				'contractVersion' => 1,
			);

			return substr(hash('sha256', (string) wp_json_encode($payload)), 0, 24);
		}

		private function read_media_encoder_capability_status() {
			if (!function_exists('ultracache_get_state_record_read_only')) {
				return array();
			}

			$record = ultracache_get_state_record_read_only(self::MEDIA_SUPPORT_STATE);
			$payload = is_array($record['payload'] ?? null) ? $record['payload'] : array();
			$status = is_array($payload['status'] ?? null) ? $payload['status'] : array();
			if (empty($status)) {
				return array();
			}

			$tested_at = max(0, (int) ($status['testedAt'] ?? ($payload['recordedAt'] ?? 0)));
			$fingerprint = sanitize_text_field((string) ($status['fingerprint'] ?? ($payload['fingerprint'] ?? '')));
			$invalidated_at = max(0, (int) ($payload['invalidatedAt'] ?? 0));
			$gd_webp_retry_after = max(0, (int) ($status['gd_webp_retry_after'] ?? 0));
			if (
				'' === $fingerprint
				|| !hash_equals($this->get_media_encoder_capability_fingerprint(), $fingerprint)
				|| $invalidated_at >= $tested_at
				|| (empty($status['gd_webp']) && $gd_webp_retry_after > 0 && time() >= $gd_webp_retry_after)
			) {
				return array();
			}

			$status['testedAt'] = $tested_at;
			$status['fingerprint'] = $fingerprint;
			$status['diagnosticStatus'] = 'current';
			$status['cached'] = true;
			$status['source'] = 'persistent';
			return $status;
		}

		private function persist_media_encoder_capability_status(array $status) {
			if (!function_exists('ultracache_mutate_state_record')) {
				return false;
			}

			$status['testedAt'] = time();
			$status['fingerprint'] = $this->get_media_encoder_capability_fingerprint();
			$status['diagnosticStatus'] = 'current';
			$status['cached'] = false;
			$status['source'] = 'live';
			$mutation = ultracache_mutate_state_record(
				self::MEDIA_SUPPORT_STATE,
				static function () use ($status) {
					return array(
						'schemaVersion' => 1,
						'recordedAt' => (int) $status['testedAt'],
						'fingerprint' => (string) $status['fingerprint'],
						'invalidatedAt' => 0,
						'status' => $status,
					);
				},
				5,
				array()
			);

			return !empty($mutation['success']);
		}

		private function invalidate_media_encoder_capability_state($reason = 'dependency_changed') {
			if (!function_exists('ultracache_mutate_state_record')) {
				return false;
			}

			$now = time();
			$mutation = ultracache_mutate_state_record(
				self::MEDIA_SUPPORT_STATE,
				static function (array $payload) use ($now, $reason) {
					$payload['invalidatedAt'] = $now;
					$payload['invalidationReason'] = sanitize_key((string) $reason);
					return $payload;
				},
				5,
				array('invalidatedAt' => $now, 'invalidationReason' => sanitize_key((string) $reason))
			);

			return !empty($mutation['success']);
		}

		private function mark_media_summary_state_dirty($state_name, $reason) {
			if (!function_exists('ultracache_mutate_state_record')) {
				return false;
			}

			$now = time();
			$mutation = ultracache_mutate_state_record(
				$state_name,
				static function (array $payload) use ($now, $reason) {
					$payload['dirty'] = true;
					$payload['invalidatedAt'] = $now;
					$payload['invalidationReason'] = sanitize_key((string) $reason);
					return $payload;
				},
				5,
				array('dirty' => true, 'invalidatedAt' => $now, 'invalidationReason' => sanitize_key((string) $reason))
			);

			return !empty($mutation['success']);
		}

		private function invalidate_media_work_summary_cache() {
			$this->mark_media_summary_state_dirty(self::MEDIA_WORK_SUMMARY_STATE, 'media_work_changed');
			foreach (array('best', 'avif', 'webp', 'both') as $format) {
				$this->mark_media_summary_state_dirty(self::MEDIA_STORAGE_HEALTH_STATE_PREFIX . $format, 'media_storage_changed');
			}
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
				ultracache_require_wordpress_admin_include('image.php', 'wp_get_image_editor');
			}

			if ($this->supports_imagick_avif() || $this->supports_imagick_webp()) {
				return 'WP_Image_Editor_Imagick';
			}

			if ($this->supports_gd_avif() || $this->supports_gd_webp()) {
				return 'WP_Image_Editor_GD';
			}

			$editors = apply_filters(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter name.
				'wp_image_editors', array('WP_Image_Editor_Imagick', 'WP_Image_Editor_GD'));
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
			if (function_exists('ultracache_get_state_record_read_only')) {
				$record = ultracache_get_state_record_read_only(self::MEDIA_WORK_SUMMARY_STATE);
				$payload = is_array($record['payload'] ?? null) ? $record['payload'] : array();
				$summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : array();
				if (empty($payload['dirty']) && isset($summary['attachmentsTotal'], $summary['workTotal'])) {
					$summary['cached'] = true;
					$summary['source'] = 'persistent';
					return $summary;
				}
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
				'computedAt' => time(),
				'cached' => false,
				'source' => 'live',
			);
			if (function_exists('ultracache_mutate_state_record')) {
				ultracache_mutate_state_record(
					self::MEDIA_WORK_SUMMARY_STATE,
					static function () use ($summary) {
						return array(
							'schemaVersion' => 1,
							'recordedAt' => (int) $summary['computedAt'],
							'dirty' => false,
							'invalidatedAt' => 0,
							'summary' => $summary,
						);
					},
					5,
					array()
				);
			}
			return $summary;
		}

		public function get_support_status($force_refresh = false) {
			if (!$force_refresh) {
				$stored = $this->read_media_encoder_capability_status();
				if (is_array($stored) && array_key_exists('supported', $stored)) {
					return $stored;
				}
			}

			$avif_self_test = $this->run_avif_encoder_self_test(false);
			$imagick              = extension_loaded('imagick');
			$imagick_avif         = !empty($avif_self_test['engines']['imagick']['passed']);
			$imagick_avif_decode  = !empty($avif_self_test['engines']['imagick']['avifDecodePassed']);
			$imagick_avif_to_webp = !empty($avif_self_test['engines']['imagick']['avifToWebpPassed']);
			$imagick_webp         = $this->supports_imagick_webp();
			$gd_avif              = !empty($avif_self_test['engines']['gd']['passed']);
			$gd_avif_decode       = !empty($avif_self_test['engines']['gd']['avifDecodePassed']);
			$gd_avif_to_webp      = !empty($avif_self_test['engines']['gd']['avifToWebpPassed']);
			$gd_webp              = $this->supports_gd_webp();
			$gd_webp_probe        = $this->read_gd_webp_probe_state();
			$diag           = $this->get_media_diagnostic_state();
			$preferred      = $this->detect_preferred_image_editor_class();
			$last_error     = (string) ($diag['lastAvifEncodeError'] ?? '');
			$last_engine    = (string) ($diag['lastAvifEncodeEngine'] ?? '');
			$last_class     = (string) ($diag['lastImageEditorClass'] ?? '');

			if ('WP_Image_Editor_Imagick' === $preferred && $imagick_avif && !$gd_avif && '' !== $last_error && false !== stripos($last_engine, 'gd')) {
				$last_error  = '';
				$last_engine = 'imagick';
				$last_class  = 'WP_Image_Editor_Imagick';
			}

			$status = array(
				'imagick'                  => $imagick,
				'imagick_avif'             => $imagick_avif,
				'imagick_avif_decode'      => $imagick_avif_decode,
				'imagick_avif_to_webp'     => $imagick_avif_to_webp,
				'imagick_webp'             => $imagick_webp,
				'gd_avif'                  => $gd_avif,
				'gd_avif_decode'           => $gd_avif_decode,
				'gd_avif_to_webp'          => $gd_avif_to_webp,
				'gd_webp'                  => $gd_webp,
				'gd_webp_tested_at'        => max(0, (int) ($gd_webp_probe['testedAt'] ?? 0)),
				'gd_webp_retry_after'      => max(0, (int) ($gd_webp_probe['retryAfter'] ?? 0)),
				'avif_source_to_webp'       => ($imagick_avif_to_webp || $gd_avif_to_webp),
				'preferred_editor'         => $preferred,
				'last_avif_encode_error'   => $last_error,
				'last_avif_encode_engine'  => $last_engine,
				'last_avif_encode_file'    => (string) ($diag['lastAvifEncodeFile'] ?? ''),
				'last_avif_encode_at'      => (int) ($diag['lastAvifEncodeAt'] ?? 0),
				'last_image_editor_class'  => $last_class,
				'avif_self_test'           => $avif_self_test,
				'supported'                => ($imagick_avif || $gd_avif || $imagick_webp || $gd_webp),
				'cached'                   => false,
			);

			$this->persist_media_encoder_capability_status($status);
			$status['testedAt'] = time();
			$status['fingerprint'] = $this->get_media_encoder_capability_fingerprint();
			$status['diagnosticStatus'] = 'current';
			$status['source'] = 'live';
			return $status;
		}

		private function get_default_media_file_counts() {
			return array(
				'total'        => 0,
				'avif'         => 0,
				'webp'         => 0,
				'initialized'  => false,
				'needsRecount' => true,
				'updatedAt'    => 0,
				'recountedAt'  => 0,
			);
		}

		private function normalize_media_file_counts($counts) {
			$defaults = $this->get_default_media_file_counts();
			$counts = is_array($counts) ? array_merge($defaults, $counts) : $defaults;
			$counts['avif'] = max(0, (int) $counts['avif']);
			$counts['webp'] = max(0, (int) $counts['webp']);
			$counts['total'] = $counts['avif'] + $counts['webp'];
			$counts['initialized'] = !empty($counts['initialized']);
			$counts['needsRecount'] = !empty($counts['needsRecount']);
			$counts['updatedAt'] = max(0, (int) $counts['updatedAt']);
			$counts['recountedAt'] = max(0, (int) $counts['recountedAt']);
			return $counts;
		}

		private function save_media_file_counts(array $counts) {
			$counts = $this->normalize_media_file_counts($counts);
			update_option(self::MEDIA_FILE_COUNTS_OPTION, $counts, false);
			return $counts;
		}

		public function get_media_file_counts() {
			$stored = get_option(self::MEDIA_FILE_COUNTS_OPTION, null);
			if (is_array($stored)) {
				return $this->normalize_media_file_counts($stored);
			}

			// One-time database option rename from releases 2.59.06.99-2.59.07.07.
			$previous = get_option('ultracache_media_storage_stats_v2', null);
			if (is_array($previous)) {
				$avif = max(0, (int) ($previous['avifFiles'] ?? 0));
				$webp = max(0, (int) ($previous['webpFiles'] ?? 0));
				$initialized = !empty($previous['mediaStatsInitialized']) || ($avif + $webp) > 0;
				$counts = array(
					'avif'         => $avif,
					'webp'         => $webp,
					'initialized'  => $initialized,
					'needsRecount' => !$initialized || !empty($previous['mediaStatsDirty']),
					'updatedAt'    => max(0, (int) ($previous['mediaStatsUpdatedAt'] ?? 0)),
					'recountedAt'  => max(0, (int) ($previous['mediaStatsReconciledAt'] ?? 0)),
				);
				$counts = $this->save_media_file_counts($counts);
				delete_option('ultracache_media_storage_stats_v2');
				return $counts;
			}

			return $this->save_media_file_counts($this->get_default_media_file_counts());
		}

		private function get_media_file_state($path) {
			$path = (string) $path;
			clearstatcache(true, $path);
			return array(
				'exists' => ('' !== $path && is_file($path)),
			);
		}

		private function record_media_file_transition($path, $format, array $before) {
			$format = strtolower((string) $format);
			if (!in_array($format, array('avif', 'webp'), true)) {
				return;
			}

			$after = $this->get_media_file_state($path);
			$file_delta = (!empty($after['exists']) ? 1 : 0) - (!empty($before['exists']) ? 1 : 0);
			if (0 === $file_delta) {
				return;
			}

			$counts = $this->get_media_file_counts();
			$counts[$format] = max(0, (int) $counts[$format] + $file_delta);
			$counts['initialized'] = true;
			$counts['updatedAt'] = time();
			$this->save_media_file_counts($counts);
		}

		private function scan_media_file_counts($max_files = 500000, $time_budget = 10.0) {
			$scan = static function($dir, $extension) use ($max_files, $time_budget) {
				$count = 0;
				$scanned = 0;
				$truncated = false;
				$timed_out = false;
				$deadline = microtime(true) + max(0.1, min(20.0, (float) $time_budget));
				if (!$dir || !is_dir($dir) || !is_readable($dir)) {
					return array('files' => 0, 'scannedFiles' => 0, 'truncated' => false, 'timedOut' => false, 'error' => '');
				}

				try {
					$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
					foreach ($iterator as $item) {
						if (microtime(true) >= $deadline) {
							$truncated = true;
							$timed_out = true;
							break;
						}
						if (!$item->isFile()) {
							continue;
						}
						$scanned++;
						if (strtolower($item->getExtension()) === strtolower($extension)) {
							$count++;
						}
						if ($scanned >= $max_files) {
							$truncated = true;
							break;
						}
					}
				} catch (Exception $e) {
					return array('files' => $count, 'scannedFiles' => $scanned, 'truncated' => true, 'timedOut' => $timed_out, 'error' => (string) $e->getMessage());
				}

				return array('files' => $count, 'scannedFiles' => $scanned, 'truncated' => $truncated, 'timedOut' => $timed_out, 'error' => '');
			};

			$avif = $scan(defined('ULTRACACHE_AVIF_DIR') ? ULTRACACHE_AVIF_DIR : '', 'avif');
			$webp = $scan(defined('ULTRACACHE_WEBP_DIR') ? ULTRACACHE_WEBP_DIR : '', 'webp');
			$avif_files = max(0, (int) ($avif['files'] ?? 0));
			$webp_files = max(0, (int) ($webp['files'] ?? 0));

			return array(
				'total'          => $avif_files + $webp_files,
				'avif'           => $avif_files,
				'webp'           => $webp_files,
				'scanIncomplete' => !empty($avif['truncated']) || !empty($webp['truncated']) || !empty($avif['timedOut']) || !empty($webp['timedOut']) || !empty($avif['error']) || !empty($webp['error']),
				'avifScan'       => $avif,
				'webpScan'       => $webp,
			);
		}

		public function recount_media_files() {
			$scan = $this->scan_media_file_counts();
			if (empty($scan['scanIncomplete'])) {
				return $this->save_media_file_counts(
					array(
						'avif'         => (int) $scan['avif'],
						'webp'         => (int) $scan['webp'],
						'initialized'  => true,
						'needsRecount' => false,
						'updatedAt'    => time(),
						'recountedAt'  => time(),
					)
				);
			}

			$counts = $this->get_media_file_counts();
			$counts['needsRecount'] = true;
			$counts['updatedAt'] = time();
			return $this->save_media_file_counts($counts);
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
						'image/avif',
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
}
