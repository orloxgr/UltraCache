<?php
/**
 * UltraCache resumable media queue rebuild helpers.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Queue_Rebuild_Trait
{
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache uses private custom media conversion queue tables with validated table identifiers.


		/**
		 * Normalize a media queue rebuild generation token.
		 *
		 * @param mixed $generation Generation token.
		 * @return string
		 */
		private function normalize_media_queue_rebuild_generation($generation) {
			$generation = sanitize_text_field((string) $generation);
			return substr($generation, 0, 64);
		}


		/**
		 * Read the authoritative rebuild generation intent.
		 *
		 * @return string
		 */
		private function get_media_queue_rebuild_generation_intent() {
			return $this->normalize_media_queue_rebuild_generation(
				get_option(self::MEDIA_QUEUE_REBUILD_GENERATION_OPTION, '')
			);
		}


		/**
		 * Publish the authoritative rebuild generation intent.
		 *
		 * Publishing a restart generation before waiting for the rebuild lock lets
		 * an older in-flight chunk detect that it is stale and stop safely.
		 *
		 * @param string $generation Generation token.
		 * @return string
		 */
		private function set_media_queue_rebuild_generation_intent($generation) {
			$generation = $this->normalize_media_queue_rebuild_generation($generation);
			if ('' !== $generation) {
				update_option(self::MEDIA_QUEUE_REBUILD_GENERATION_OPTION, $generation, false);
			}
			return $generation;
		}


		/**
		 * Check whether a rebuild generation is still authoritative.
		 *
		 * @param string $generation Expected generation token.
		 * @return bool
		 */
		private function is_media_queue_rebuild_generation_current($generation) {
			$generation = $this->normalize_media_queue_rebuild_generation($generation);
			$current = $this->get_media_queue_rebuild_generation_intent();
			return '' === $generation || '' === $current || hash_equals($current, $generation);
		}


		/**
		 * Acquire the dedicated media queue rebuild lock.
		 *
		 * @param string $generation Rebuild generation token.
		 * @param string $context    Lock context.
		 * @return string Owner token, or an empty string when unavailable.
		 */
		private function acquire_media_queue_rebuild_lock($generation = '', $context = 'rebuild') {
			if (!function_exists('ultracache_acquire_lock')) {
				return '';
			}

			$token = wp_generate_uuid4();
			$ttl = (int) apply_filters('ultracache_media_queue_rebuild_lock_ttl', 150, $context);
			$ttl = max(60, min(300, $ttl));
			$payload = array(
				'context' => sanitize_key((string) $context),
				'generation' => $this->normalize_media_queue_rebuild_generation($generation),
				'acquired_at' => time(),
				'request_uri' => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '',
			);

			return ultracache_acquire_lock(self::MEDIA_QUEUE_REBUILD_LOCK, $token, $ttl, $payload) ? $token : '';
		}


		/**
		 * Release the media queue rebuild lock only for its owner.
		 *
		 * @param string $token Owner token.
		 * @return void
		 */
		private function release_media_queue_rebuild_lock($token) {
			if ('' !== (string) $token && function_exists('ultracache_release_lock')) {
				ultracache_release_lock(self::MEDIA_QUEUE_REBUILD_LOCK, $token);
			}
		}


		/**
		 * Return a standardized rebuild lock response.
		 *
		 * @return array<string,mixed>
		 */
		private function get_media_queue_rebuild_locked_result() {
			return array(
				'success' => false,
				'locked' => true,
				'code' => 'media_queue_rebuild_locked',
				'message' => __('Another media conversion or queue rebuild is still running. UltraCache will retry after it finishes.', 'ultracache'),
			);
		}


		/**
		 * Return a standardized stale-generation response.
		 *
		 * @param string $current_generation Current authoritative generation.
		 * @return array<string,mixed>
		 */
		private function get_media_queue_rebuild_stale_result($current_generation = '') {
			return array(
				'success' => false,
				'staleGeneration' => true,
				'code' => 'media_queue_rebuild_stale_generation',
				'message' => __('This media queue rebuild request belongs to an older run and was ignored.', 'ultracache'),
				'rebuildGeneration' => $this->normalize_media_queue_rebuild_generation($current_generation),
			);
		}


		private function start_media_queue_rebuild($format = 'best', $generation = '') {
			if (!$this->ensure_media_queue_table()) {
				return '';
			}
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			$generation = $this->normalize_media_queue_rebuild_generation($generation);
			if ('' === $generation) {
				$generation = wp_generate_uuid4();
			}
			$generation = $this->set_media_queue_rebuild_generation_intent($generation);
			$wpdb->delete($table, array('format' => $format), array('%s'));
			update_option(self::MEDIA_QUEUE_BUILD_STATE_OPTION, array(
				'format' => $format,
				'offset' => 0,
				'total' => 0,
				'complete' => false,
				'mode' => 'chunked',
				'generation' => $generation,
				'updatedAt' => time(),
			), false);
			return $generation;
		}


		private function get_media_queue_build_state($format = 'best') {
			$format = $this->normalize_media_queue_format($format);
			$state = get_option(self::MEDIA_QUEUE_BUILD_STATE_OPTION, array());
			if (!is_array($state) || (string) ($state['format'] ?? '') !== $format) {
				return array('format' => $format, 'offset' => 0, 'total' => 0, 'complete' => false, 'mode' => 'none', 'generation' => '', 'updatedAt' => 0);
			}
			return array(
				'format' => $format,
				'offset' => max(0, (int) ($state['offset'] ?? 0)),
				'total' => max(0, (int) ($state['total'] ?? 0)),
				'complete' => !empty($state['complete']),
				'mode' => isset($state['mode']) ? (string) $state['mode'] : 'legacy',
				'generation' => $this->normalize_media_queue_rebuild_generation($state['generation'] ?? ''),
				'updatedAt' => (int) ($state['updatedAt'] ?? 0),
			);
		}


		/**
		 * Determine the authoritative queue state for one attachment during repair.
		 *
		 * @param int    $attachment_id Attachment ID.
		 * @param string $format        Requested output policy.
		 * @return array{status:string,message:string}
		 */
		private function get_media_queue_rebuild_item_state($attachment_id, $format) {
			$units = $this->get_attachment_conversion_units($attachment_id, $format);
			if (empty($units)) {
				return array(
					'status'  => 'skipped',
					'message' => 'No supported physical image files were found.',
				);
			}

			$progress = $this->get_attachment_conversion_unit_progress($units);
			if (0 === (int) ($progress['remainingUnits'] ?? 0)) {
				return array(
					'status'  => 'skipped',
					'message' => 'Required optimized files already exist and are current.',
				);
			}

			return array(
				'status'  => 'pending',
				'message' => 'Missing or stale optimized files queued for generation.',
			);
		}


		private function append_media_queue_build_batch($format = 'best', $limit = 500, array $budget = array(), $expected_generation = '') {
			$format = $this->normalize_media_queue_format($format);
			$limit = max(25, min(500, (int) $limit));
			$state = $this->get_media_queue_build_state($format);
			$expected_generation = $this->normalize_media_queue_rebuild_generation($expected_generation);
			$current_generation = $this->normalize_media_queue_rebuild_generation($state['generation'] ?? '');
			$intent_generation = $this->get_media_queue_rebuild_generation_intent();
			if ('' !== $expected_generation && '' !== $intent_generation && !hash_equals($intent_generation, $expected_generation)) {
				return $this->get_media_queue_rebuild_stale_result($intent_generation);
			}
			if ('' !== $expected_generation && !hash_equals($current_generation, $expected_generation)) {
				return $this->get_media_queue_rebuild_stale_result($current_generation);
			}
			if (!empty($state['complete'])) {
				return array('success' => true, 'scanned' => 0, 'queued' => 0, 'complete' => true, 'offset' => (int) $state['offset'], 'total' => (int) $state['total'], 'rebuildGeneration' => $current_generation);
			}

			$batch = $this->get_media_ids_batch((int) $state['offset'], $limit, false);
			$library_total = max(0, (int) ($batch['total'] ?? ($state['total'] ?? 0)));
			$items = array_map('intval', (array) ($batch['items'] ?? array()));
			$queued = 0;
			$processed = 0;
			$pause_reason = '';
			foreach ($items as $attachment_id) {
				if (!$this->is_media_queue_rebuild_generation_current($expected_generation)) {
					return $this->get_media_queue_rebuild_stale_result($this->get_media_queue_rebuild_generation_intent());
				}
				$pause_reason = function_exists('ultracache_operation_pause_reason') ? ultracache_operation_pause_reason($budget) : '';
				if ('' !== $pause_reason) {
					break;
				}
				$processed++;
				$item_state = $this->get_media_queue_rebuild_item_state($attachment_id, $format);
				if ($this->upsert_media_queue_item($attachment_id, $format, $item_state['status'], $item_state['message'], 0, true)) {
					$queued++;
				}
			}
			$next_offset = '' !== $pause_reason ? ((int) $state['offset'] + $processed) : (int) ($batch['nextOffset'] ?? ((int) $state['offset'] + count($items)));
			$complete = empty($batch['hasMore']) || empty($items);
			if ('' !== $pause_reason && !empty($items)) {
				$complete = false;
			}
			$current_state = $this->get_media_queue_build_state($format);
			$current_generation = $this->normalize_media_queue_rebuild_generation($current_state['generation'] ?? '');
			$intent_generation = $this->get_media_queue_rebuild_generation_intent();
			if ('' !== $expected_generation && '' !== $intent_generation && !hash_equals($intent_generation, $expected_generation)) {
				return $this->get_media_queue_rebuild_stale_result($intent_generation);
			}
			if ('' !== $expected_generation && !hash_equals($current_generation, $expected_generation)) {
				return $this->get_media_queue_rebuild_stale_result($current_generation);
			}

			update_option(self::MEDIA_QUEUE_BUILD_STATE_OPTION, array(
				'format' => $format,
				'offset' => $next_offset,
				'total' => $library_total,
				'complete' => $complete,
				'mode' => 'chunked',
				'generation' => $current_generation,
				'updatedAt' => time(),
			), false);

			return array('success' => true, 'scanned' => $processed, 'queued' => $queued, 'complete' => $complete, 'offset' => $next_offset, 'total' => $library_total, 'pauseReason' => $pause_reason, 'rebuildGeneration' => $current_generation);
		}


		public function rebuild_media_conversion_queue($format = 'best', $only_missing = true, $limit = 0, array $args = array()) {
			if (!$this->ensure_media_queue_table()) {
				return array('success' => false, 'message' => __('Media queue table could not be created.', 'ultracache'));
			}

			$format = $this->normalize_media_queue_format($format);
			$limit = max(0, (int) $limit);
			$is_limited_sample = ($limit > 0);
			$reset = array_key_exists('reset', $args) ? (bool) $args['reset'] : !$is_limited_sample;
			$time_budget = isset($args['time_budget']) ? max(0, (int) $args['time_budget']) : null;
			$requested_generation = $this->normalize_media_queue_rebuild_generation($args['generation'] ?? '');
			if (!$is_limited_sample && $reset) {
				if ('' === $requested_generation) {
					$requested_generation = wp_generate_uuid4();
				}
				$this->set_media_queue_rebuild_generation_intent($requested_generation);
			} elseif (!$is_limited_sample && '' !== $requested_generation) {
				$intent_generation = $this->get_media_queue_rebuild_generation_intent();
				if ('' !== $intent_generation && !hash_equals($intent_generation, $requested_generation)) {
					return $this->get_media_queue_rebuild_stale_result($intent_generation);
				}
			}

			$budget = function_exists('ultracache_get_safe_operation_budget') ? ultracache_get_safe_operation_budget('media_rebuild', $time_budget, 45) : array('started_at' => microtime(true), 'seconds' => 20);
			$lock_token = $this->acquire_media_queue_rebuild_lock($requested_generation, $is_limited_sample ? 'limited_sample' : 'rebuild');
			if ('' === $lock_token) {
				return $this->get_media_queue_rebuild_locked_result();
			}

			$process_lock_token = $this->acquire_media_queue_process_lock('rebuild_repair');
			if ('' === $process_lock_token) {
				$this->release_media_queue_rebuild_lock($lock_token);
				return $this->get_media_queue_rebuild_locked_result();
			}

			try {
				$generation = '';
				if (!$is_limited_sample) {
					$intent_generation = $this->get_media_queue_rebuild_generation_intent();
					if ('' !== $requested_generation && '' !== $intent_generation && !hash_equals($intent_generation, $requested_generation)) {
						return $this->get_media_queue_rebuild_stale_result($intent_generation);
					}
					$state = $this->get_media_queue_build_state($format);
					$current_generation = $this->normalize_media_queue_rebuild_generation($state['generation'] ?? '');

					if ($reset) {
						$generation = $requested_generation;
						if ('' === $current_generation || !hash_equals($current_generation, $generation)) {
							$generation = $this->start_media_queue_rebuild($format, $generation);
							if ('' === $generation) {
								return array('success' => false, 'message' => __('Media queue rebuild could not be initialized.', 'ultracache'));
							}
						}
					} else {
						$intent_generation = $this->get_media_queue_rebuild_generation_intent();
						if ('' !== $requested_generation && '' !== $intent_generation && !hash_equals($intent_generation, $requested_generation)) {
							return $this->get_media_queue_rebuild_stale_result($intent_generation);
						}
						if ('' !== $requested_generation && '' !== $current_generation && !hash_equals($current_generation, $requested_generation)) {
							return $this->get_media_queue_rebuild_stale_result($current_generation);
						}

						$generation = '' !== $current_generation ? $current_generation : $requested_generation;
						if ('' === $generation) {
							$generation = wp_generate_uuid4();
						}
						$generation = $this->set_media_queue_rebuild_generation_intent($generation);

						if ('' === $current_generation) {
							update_option(self::MEDIA_QUEUE_BUILD_STATE_OPTION, array(
								'format' => $format,
								'offset' => max(0, (int) ($state['offset'] ?? 0)),
								'total' => max(0, (int) ($state['total'] ?? 0)),
								'complete' => !empty($state['complete']),
								'mode' => (string) ($state['mode'] ?? 'chunked'),
								'generation' => $generation,
								'updatedAt' => time(),
							), false);
						}
					}
				}

				$queued = 0;
				$scanned = 0;
				$pause_reason = '';

				if (!$is_limited_sample) {
					do {
						$chunk = $this->append_media_queue_build_batch($format, 250, $budget, $generation);
						if (empty($chunk['success'])) {
							return $chunk;
						}
						$queued += (int) ($chunk['queued'] ?? 0);
						$scanned += (int) ($chunk['scanned'] ?? 0);
						$pause_reason = (string) ($chunk['pauseReason'] ?? '');
						if (!empty($chunk['complete']) || '' !== $pause_reason || (int) ($chunk['scanned'] ?? 0) < 1) {
							break;
						}
					} while (true);
				} else {
					$offset = 0;
					$batch_size = min(250, max(25, $limit));
					$batch = array('hasMore' => false);
					do {
						$pause_reason = function_exists('ultracache_operation_pause_reason') ? ultracache_operation_pause_reason($budget) : '';
						if ('' !== $pause_reason) {
							break;
						}
						$batch = $this->get_media_ids_batch($offset, $batch_size, false);
						$items = array_map('intval', (array) ($batch['items'] ?? array()));
						foreach ($items as $attachment_id) {
							$pause_reason = function_exists('ultracache_operation_pause_reason') ? ultracache_operation_pause_reason($budget) : '';
							if ('' !== $pause_reason || $scanned >= $limit) {
								break 2;
							}
							$scanned++;
							$item_state = $this->get_media_queue_rebuild_item_state($attachment_id, $format);
							if ($this->upsert_media_queue_item($attachment_id, $format, $item_state['status'], $item_state['message'], 0, true)) {
								$queued++;
							}
						}
						$offset = (int) ($batch['nextOffset'] ?? ($offset + count($items)));
					} while (!empty($batch['hasMore']) && !empty($items) && $scanned < $limit);
				}

				$this->invalidate_media_work_summary_cache();
				$status = $this->get_media_queue_status($format);
				$complete = !empty($status['buildComplete']);
				$message = $is_limited_sample
					? 'Limited media queue sample scanned and verified.'
					: ($complete ? 'Media conversion queue rebuilt and repaired.' : 'Media queue rebuild/repair chunk complete. Continue to scan the remaining library.');

				return array_merge(
					array(
						'success' => true,
						'message' => $message,
						'buildMode' => $is_limited_sample ? 'limited_sample' : 'chunked',
						'buildLimit' => $limit,
						'queued' => $queued,
						'scanned' => $scanned,
						'onlyMissing' => (bool) $only_missing,
						'hasMore' => !$complete,
						'complete' => $complete,
						'pauseReason' => $pause_reason,
						'timeBudgetReached' => 'time_budget' === $pause_reason,
						'memoryBudgetReached' => 'memory_budget' === $pause_reason,
						'rebuildGeneration' => $generation,
					),
					$status
				);
			} finally {
				$this->release_media_queue_process_lock($process_lock_token);
				$this->release_media_queue_rebuild_lock($lock_token);
			}
		}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
