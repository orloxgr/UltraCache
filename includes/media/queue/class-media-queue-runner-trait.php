<?php
/**
 * UltraCache media queue processing and administrator action helpers.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Queue_Runner_Trait
{
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache uses private custom media conversion queue tables with validated table identifiers.


		public function get_media_queue_batch($cursor = 0, $limit = 100, $format = 'best', $auto_rebuild = true) {
			$cursor = max(0, (int) $cursor);
			$limit = max(1, min(500, (int) $limit));
			$format = $this->normalize_media_queue_format($format);
			if (!$this->ensure_media_queue_table()) {
				return array('items' => array(), 'total' => 0, 'cursor' => (string) $cursor, 'nextCursor' => '', 'hasMore' => false, 'message' => __('Media queue table unavailable.', 'ultracache'));
			}

			$status = $this->get_media_queue_status($format);
			$repair = array('repaired' => false, 'requeued' => 0);
			if ($auto_rebuild && !empty($status['needsRepair'])) {
				$repair = $this->repair_media_queue_if_optimized_storage_missing($format);
				$status = $this->get_media_queue_status($format);
			}
			$build_chunk = array('scanned' => 0, 'queued' => 0, 'complete' => !empty($status['buildComplete']));
			$needs_auto_build = $auto_rebuild && (empty($status['total']) || (empty($status['pending']) && empty($status['buildComplete'])));
			if ($needs_auto_build) {
				$state = $this->get_media_queue_build_state($format);
				$generation = $this->normalize_media_queue_rebuild_generation($state['generation'] ?? '');
				$rebuild_lock = $this->acquire_media_queue_rebuild_lock($generation, 'auto_rebuild');
				if ('' !== $rebuild_lock) {
					try {
						$state = $this->get_media_queue_build_state($format);
						$generation = $this->normalize_media_queue_rebuild_generation($state['generation'] ?? '');
						$intent_generation = $this->get_media_queue_rebuild_generation_intent();
						$generation_conflict = '' !== $generation && '' !== $intent_generation && !hash_equals($generation, $intent_generation);

						if (!$generation_conflict && empty($status['total'])) {
							$generation = '' !== $intent_generation ? $intent_generation : ('' !== $generation ? $generation : wp_generate_uuid4());
							$generation = $this->start_media_queue_rebuild($format, $generation);
						} elseif (!$generation_conflict && '' === $generation) {
							$generation = '' !== $intent_generation ? $intent_generation : wp_generate_uuid4();
							$generation = $this->set_media_queue_rebuild_generation_intent($generation);
							update_option(self::MEDIA_QUEUE_BUILD_STATE_OPTION, array(
								'format' => $format,
								'offset' => max(0, (int) ($state['offset'] ?? 0)),
								'total' => max(0, (int) ($state['total'] ?? 0)),
								'complete' => !empty($state['complete']),
								'mode' => (string) ($state['mode'] ?? 'chunked'),
								'generation' => $generation,
								'updatedAt' => time(),
							), false);
						} elseif (!$generation_conflict && '' === $intent_generation) {
							$this->set_media_queue_rebuild_generation_intent($generation);
						}

						if (!$generation_conflict && '' !== $generation) {
							$build_chunk = $this->append_media_queue_build_batch($format, max(100, min(500, $limit * 5)), array(), $generation);
						}
					} finally {
						$this->release_media_queue_rebuild_lock($rebuild_lock);
					}
					$status = $this->get_media_queue_status($format);
				}
			}


			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$rows = $wpdb->get_results($wpdb->prepare("SELECT id, attachment_id FROM %i WHERE format = %s AND status = 'pending' AND id > %d ORDER BY id ASC LIMIT %d", $table, $format, $cursor, $limit), ARRAY_A);
			if (empty($rows) && $cursor > 0 && !empty($status['pending'])) {
				$rows = $wpdb->get_results($wpdb->prepare("SELECT id, attachment_id FROM %i WHERE format = %s AND status = 'pending' ORDER BY id ASC LIMIT %d", $table, $format, $limit), ARRAY_A);
			}

			$items = array();
			$next_cursor = '';
			foreach ((array) $rows as $row) {
				$items[] = (int) $row['attachment_id'];
				$next_cursor = (string) (int) $row['id'];
			}

			$has_more = false;
			if ('' !== $next_cursor) {
				$has_more = (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM %i WHERE format = %s AND status = 'pending' AND id > %d LIMIT 1", $table, $format, (int) $next_cursor));
			} elseif (!empty($status['pending'])) {
				$has_more = true;
			}
			if (!$has_more && empty($status['buildComplete'])) {
				$has_more = true;
			}

			$attachment_total = max(
				(int) ($status['total'] ?? 0),
				(int) ($status['libraryTotal'] ?? 0),
				count($items)
			);

			return array(
				'items' => $items,
				'total' => $attachment_total,
				'attachmentTotal' => $attachment_total,
				'workTotal' => max(1, $attachment_total),
				'cursor' => (string) $cursor,
				'nextCursor' => $next_cursor,
				'nextOffset' => (int) $next_cursor,
				'limit' => $limit,
				'hasMore' => $has_more,
				'complete' => empty($items) && !$has_more && !empty($status['isComplete']),
				'message' => (empty($items) && !$has_more && !empty($status['isComplete'])) ? 'Media conversion complete. All queued media items are already optimized or processed.' : '',
				'queue' => $status,
				'repair' => $repair,
				'buildChunk' => $build_chunk,
			);
		}


		/**
		 * Acquire the shared media processor lock.
		 *
		 * @param string $context Lock context for diagnostics.
		 * @return string Owner token, or an empty string when unavailable.
		 */
		private function acquire_media_queue_process_lock($context = 'media') {
			if (!function_exists('ultracache_acquire_lock')) {
				return '';
			}

			$token = wp_generate_uuid4();
			$ttl   = $this->get_media_queue_process_lock_ttl($context);
			$payload = array(
				'context'      => sanitize_key((string) $context),
				'acquired_at'  => time(),
				'heartbeat_at' => time(),
				'request_uri'  => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '',
			);

			return ultracache_acquire_lock(self::MEDIA_QUEUE_PROCESS_LOCK, $token, $ttl, $payload) ? $token : '';
		}


		/**
		 * Return the renewable media processor lease lifetime.
		 *
		 * The lease remains bounded so a crashed PHP process or restarted server
		 * cannot block the queue indefinitely. Active workers renew it between
		 * atomic attachment units.
		 *
		 * @param string $context Lock context for diagnostics.
		 * @return int
		 */
		private function get_media_queue_process_lock_ttl($context = 'media') {
			$ttl = (int) apply_filters('ultracache_media_queue_process_lock_ttl', self::MEDIA_QUEUE_PROCESSING_TTL, $context);
			return max(30, min(300, $ttl));
		}


		/**
		 * Renew the shared media processor lease for its current owner.
		 *
		 * @param string $token   Owner token.
		 * @param string $context Lock context for diagnostics.
		 * @return bool
		 */
		private function renew_media_queue_process_lock($token, $context = 'media') {
			if ('' === (string) $token || !function_exists('ultracache_get_lock') || !function_exists('ultracache_renew_lock')) {
				return false;
			}

			$lock = ultracache_get_lock(self::MEDIA_QUEUE_PROCESS_LOCK);
			if (empty($lock['token']) || !hash_equals((string) $lock['token'], (string) $token)) {
				return false;
			}

			$payload = is_array($lock['payload'] ?? null) ? $lock['payload'] : array();
			$payload['context'] = sanitize_key((string) $context);
			$payload['acquired_at'] = max(0, (int) ($payload['acquired_at'] ?? $lock['acquiredAt'] ?? time()));
			$payload['heartbeat_at'] = time();
			$payload['request_uri'] = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

			return ultracache_renew_lock(
				self::MEDIA_QUEUE_PROCESS_LOCK,
				$token,
				$this->get_media_queue_process_lock_ttl($context),
				$payload
			);
		}


		/**
		 * Confirm that the supplied token still owns the media processor lock.
		 *
		 * @param string $token Owner token.
		 * @return bool
		 */
		private function owns_media_queue_process_lock($token) {
			if ('' === (string) $token || !function_exists('ultracache_get_lock')) {
				return false;
			}

			$lock = ultracache_get_lock(self::MEDIA_QUEUE_PROCESS_LOCK);
			return !empty($lock['token'])
				&& empty($lock['expired'])
				&& hash_equals((string) $lock['token'], (string) $token);
		}


		/**
		 * Release the shared media processor lock only for its owner.
		 *
		 * @param string $token Owner token.
		 * @return void
		 */
		private function release_media_queue_process_lock($token) {
			if ('' !== (string) $token && function_exists('ultracache_release_lock')) {
				ultracache_release_lock(self::MEDIA_QUEUE_PROCESS_LOCK, $token);
			}
		}


		/**
		 * Recover abandoned processing rows after the previous worker lease expired.
		 *
		 * A temporary recovery owner is required so status reads and concurrent
		 * dashboard requests can never recycle claims that still belong to a live
		 * worker. The actual reset remains timestamp- and acquisition-guarded.
		 *
		 * @return int Number of recovered rows.
		 */
		private function recover_abandoned_media_queue_claims() {
			$token = $this->acquire_media_queue_process_lock('recovery');
			if ('' === $token) {
				return 0;
			}

			try {
				return $this->reset_stale_media_queue_items($token);
			} finally {
				$this->release_media_queue_process_lock($token);
			}
		}


		private function process_next_attachment_force_regeneration_unit($attachment_id, $format = 'best', $cursor = 0) {
			$attachment_id = absint($attachment_id);
			$format        = $this->normalize_media_queue_format($format);
			$units         = $this->get_attachment_conversion_units($attachment_id, $format);
			$total_units   = count($units);
			$cursor        = max(0, min($total_units, (int) $cursor));

			$summary = array(
				'attachment_id'             => $attachment_id,
				'success'                   => true,
				'processed'                 => 0,
				'converted'                 => false,
				'avif'                      => 0,
				'webp'                      => 0,
				'sourceFiles'               => count($this->get_attachment_source_files($attachment_id)),
				'workTotal'                 => $total_units,
				'workCompleted'             => $cursor,
				'workCompletedThisRun'      => 0,
				'remainingUnits'            => max(0, $total_units - $cursor),
				'complete'                  => $cursor >= $total_units,
				'forceRegenerateExisting'   => true,
				'forceRegenerateCursor'     => $cursor,
			);

			if (empty($units)) {
				$summary['complete'] = true;
				$summary['skippedReason'] = 'no_supported_work';
				return $summary;
			}

			for ($index = $cursor; $index < $total_units; $index++) {
				$unit = $units[$index];
				$summary['workCompleted'] = $index;
				$summary['remainingUnits'] = max(0, $total_units - $index);
				if (!$this->is_attachment_conversion_unit_supported($unit)) {
					$summary['workCompleted'] = $index + 1;
					$summary['forceRegenerateCursor'] = $index + 1;
					continue;
				}

				$source_file = (string) ($unit['source_file'] ?? '');
				$unit_format = (string) ($unit['format'] ?? '');
				$result      = false;

				if ('avif' === $unit_format) {
					$result = $this->to_avif($source_file);
				} elseif ('webp' === $unit_format) {
					$result = $this->to_webp($source_file);
				}

				$summary['sourceFile']       = wp_basename($source_file);
				$summary['requestedFormat']  = $unit_format;
				$summary['attemptedFormat']  = $unit_format;
				$summary['workCompleted']    = $index + 1;
				$summary['forceRegenerateCursor'] = $index + 1;

				if (!$result) {
					$summary['success'] = false;
					$summary['message'] = __('The image conversion unit could not be regenerated.', 'ultracache');
					$failure = $this->get_last_media_conversion_failure();
					foreach (array('failureCode', 'failureStage', 'failureDetail', 'encoderAttempts') as $failure_key) {
						if (isset($failure[$failure_key]) && '' !== $failure[$failure_key] && array() !== $failure[$failure_key]) {
							$summary[$failure_key] = $failure[$failure_key];
						}
					}
					return $summary;
				}

				$summary['processed'] = 1;
				$summary['converted'] = true;
				$summary['workCompletedThisRun'] = 1;
				$summary[$unit_format] = 1;
				$summary['generatedFormat'] = $unit_format;
				$summary['remainingUnits'] = max(0, $total_units - ($index + 1));
				$summary['complete'] = 0 === (int) $summary['remainingUnits'];
				return $summary;
			}

			$summary['complete'] = true;
			$summary['remainingUnits'] = 0;
			$summary['skippedReason'] = 'no_supported_work';
			return $summary;
		}


		/**
		 * Process at most one physical media conversion unit.
		 *
		 * @param int    $attachment_id Attachment ID.
		 * @param string $format        Requested output policy.
		 * @param bool   $only_missing  Whether existing outputs should be skipped.
		 * @param string $lock_token           Optional caller-owned shared lock token.
		 * @param string $manual_session_token Optional dashboard manual-session owner token.
		 * @param bool   $force_regenerate_existing Whether existing optimized outputs should be overwritten.
		 * @return array<string,mixed>
		 */
		public function process_queued_attachment($attachment_id, $format = 'best', $only_missing = true, $lock_token = '', $manual_session_token = '', $force_regenerate_existing = false) {
			if ($this->is_media_background_work_paused()) {
				return array(
					'success'     => false,
					'paused'      => true,
					'reason'      => 'background_paused',
					'queueStatus' => 'paused',
					'message'     => __('Media generation is paused by an administrator.', 'ultracache'),
				);
			}

			if (method_exists($this, 'is_media_stale_worker_cooldown_active') && $this->is_media_stale_worker_cooldown_active()) {
				return array(
					'success'     => false,
					'paused'      => true,
					'reason'      => 'stale_worker_cooldown',
					'queueStatus' => 'cooldown',
					'message'     => __('Media conversion is cooling down after a stale worker was quarantined. It will resume automatically when the quiet period ends.', 'ultracache'),
				);
			}

			if ($this->is_manual_media_conversion_active()) {
				if (!$this->owns_manual_media_conversion_session($manual_session_token)) {
					return array(
						'success'     => false,
						'paused'      => true,
						'reason'      => 'manual_session_active',
						'queueStatus' => 'locked',
						'message'     => __('Dashboard media conversion currently has exclusive queue ownership.', 'ultracache'),
					);
				}
				$renewed = $this->renew_manual_media_conversion_session($manual_session_token);
				if (empty($renewed['success'])) {
					return array(
						'success'     => false,
						'paused'      => true,
						'reason'      => 'manual_session_lost',
						'queueStatus' => 'locked',
						'message'     => __('The dashboard media-conversion session expired or changed owner.', 'ultracache'),
					);
				}
			}

			$attachment_id = absint($attachment_id);
			$format        = $this->normalize_media_queue_format($format);
			$owns_lock     = false;

			if ($attachment_id <= 0) {
				return array('success' => false, 'attachment_id' => 0, 'message' => __('Invalid attachment ID.', 'ultracache'));
			}

			if ('' === (string) $lock_token) {
				$lock_token = $this->acquire_media_queue_process_lock('attachment');
				$owns_lock  = '' !== $lock_token;
			}

			if (!$this->renew_media_queue_process_lock($lock_token, 'attachment')) {
				if ($owns_lock) {
					$this->release_media_queue_process_lock($lock_token);
				}
				return array(
					'success'       => false,
					'paused'        => true,
					'reason'        => 'locked',
					'queueStatus'   => 'locked',
					'attachment_id' => $attachment_id,
					'message'       => __('Another media conversion is already running.', 'ultracache'),
				);
			}

			try {
				if (!$this->ensure_media_queue_table()) {
					return array('success' => false, 'attachment_id' => $attachment_id, 'message' => __('Media queue table unavailable.', 'ultracache'));
				}

				$this->reset_stale_media_queue_items($lock_token);
				if ($this->is_media_background_work_paused()) {
					$background_state = $this->get_media_background_work_state();
					return array_merge(
						array(
							'success'       => false,
							'paused'        => true,
							'reason'        => 'background_paused',
							'queueStatus'   => 'failed',
							'attachment_id' => $attachment_id,
						),
						$background_state
					);
				}

				global $wpdb;
				$table = $this->get_media_queue_table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue state must be read from the authoritative UltraCache table.
				$row = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT id, attempts, consecutive_failures, status, last_error FROM %i WHERE attachment_id = %d AND format = %s',
						$table,
						$attachment_id,
						$format
					),
					ARRAY_A
				);
				if (!is_array($row) || empty($row['id'])) {
					$this->upsert_media_queue_item($attachment_id, $format, 'pending', '', 0);
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Re-read the row created in the authoritative UltraCache queue table.
					$row = $wpdb->get_row(
						$wpdb->prepare(
							'SELECT id, attempts, consecutive_failures, status, last_error FROM %i WHERE attachment_id = %d AND format = %s',
							$table,
							$attachment_id,
							$format
						),
						ARRAY_A
					);
				}

				if (!is_array($row) || empty($row['id'])) {
					return array('success' => false, 'attachment_id' => $attachment_id, 'message' => __('Queue row unavailable.', 'ultracache'));
				}

				$force_regenerate_cursor = $this->parse_media_queue_force_regenerate_marker($row['last_error'] ?? '');
				if (null !== $force_regenerate_cursor) {
					$force_regenerate_existing = true;
				} elseif (!$force_regenerate_existing) {
					$force_regenerate_cursor = null;
				}

				if (in_array((string) $row['status'], array('done', 'skipped'), true)) {
					if (!$force_regenerate_existing) {
						$result = array(
							'success'          => true,
							'attachment_id'    => $attachment_id,
							'converted'        => false,
							'complete'         => true,
							'workCompletedThisRun' => 0,
							'queueStatus'      => (string) $row['status'],
							'alreadyOptimized' => true,
						);
						if (!empty($result['alreadyOptimized'])) {
							$result['skippedReason'] = 'already_optimized';
						}
						$result['onDemandAffectedPagePurgeReadyUrls'] = $this->mark_on_demand_affected_media_processed($attachment_id, $format, $result);
						return $result;
					}

					$force_regenerate_cursor = 0;
					$wpdb->update(
						$table,
						array(
							'status'       => 'pending',
							'last_error'   => $this->get_media_queue_force_regenerate_marker(0),
							'updated_at'   => current_time('mysql'),
							'started_at'   => null,
							'completed_at' => null,
						),
						array('id' => (int) $row['id']),
						array('%s', '%s', '%s', '%s', '%s'),
						array('%d')
					);
					$row['status'] = 'pending';
					$row['last_error'] = $this->get_media_queue_force_regenerate_marker(0);
				}

				$max_failures = max(1, (int) apply_filters('ultracache_media_queue_max_consecutive_failures', 3, $attachment_id, $format));
				if ('failed' === (string) $row['status']) {
					return array(
						'success'        => false,
						'attachment_id'  => $attachment_id,
						'converted'      => false,
						'queueStatus'    => 'failed',
						'reason'         => 'retry_limit',
						'failureAttempt' => max(1, (int) $row['consecutive_failures']),
						'failureLimit'   => $max_failures,
						'failureDetail'  => (string) ($row['last_error'] ?? ''),
						'message'        => __('This media queue item must be retried before it can run again.', 'ultracache'),
					);
				}

				if ((int) $row['consecutive_failures'] >= $max_failures) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Persist the terminal retry-limit state in the authoritative queue table.
					$wpdb->update(
						$table,
						array(
							'status'       => 'failed',
							'updated_at'   => current_time('mysql'),
							'started_at'   => null,
							'completed_at' => current_time('mysql'),
						),
						array('id' => (int) $row['id']),
						array('%s', '%s', '%s', '%s'),
						array('%d')
					);
					return array(
						'success'       => false,
						'attachment_id' => $attachment_id,
						'converted'     => false,
						'complete'      => false,
						'queueStatus'    => 'failed',
						'reason'         => 'retry_limit',
						'failureAttempt' => max(1, (int) $row['consecutive_failures']),
						'failureLimit'   => $max_failures,
						'failureDetail'  => (string) ($row['last_error'] ?? ''),
						'message'        => __('This media queue item reached the retry limit and must be retried manually.', 'ultracache'),
					);
				}

				$now           = current_time('mysql');
				$claim_attempt = (int) $row['attempts'] + 1;
				$failure_count = (int) $row['consecutive_failures'] + 1;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional UPDATE is the atomic queue-claim primitive. Failure counters increase only after an actual conversion error, never merely because a worker was interrupted.
				$claimed = $wpdb->query(
					$wpdb->prepare(
						"UPDATE %i SET status = 'processing', attempts = attempts + 1, started_at = %s, updated_at = %s, completed_at = NULL WHERE id = %d AND status = 'pending'",
						$table,
						$now,
						$now,
						(int) $row['id']
					)
				);

				if (1 !== (int) $claimed) {
					return array(
						'success'       => false,
						'paused'        => true,
						'reason'        => 'already_claimed',
						'queueStatus'   => 'processing',
						'attachment_id' => $attachment_id,
						'message'       => __('This media item is already being processed.', 'ultracache'),
					);
				}

				try {
					if (!$this->renew_media_queue_process_lock($lock_token, 'attachment')) {
						return array(
							'success'       => false,
							'paused'        => true,
							'reason'        => 'lease_lost',
							'queueStatus'   => 'processing',
							'attachment_id' => $attachment_id,
							'message'       => __('The media worker could not renew its processing lease before conversion.', 'ultracache'),
						);
					}

					$is_cli_regeneration = !$only_missing
						&& !$force_regenerate_existing
						&& function_exists('ultracache_is_cli_context')
						&& ultracache_is_cli_context();
					if ($force_regenerate_existing) {
						$force_regenerate_cursor = null === $force_regenerate_cursor ? 0 : $force_regenerate_cursor;
						$result = $this->process_next_attachment_force_regeneration_unit($attachment_id, $format, $force_regenerate_cursor);
					} elseif ($is_cli_regeneration) {
						$result = $this->generate_attachment_formats($attachment_id, $format, false);
						$result['converted'] = ((int) ($result['avif'] ?? 0) + (int) ($result['webp'] ?? 0)) > 0;
						$result['complete'] = true;
						$result['workCompletedThisRun'] = (int) ($result['workCompleted'] ?? 0);
					} else {
						$result = $this->process_next_attachment_conversion_unit($attachment_id, $format, (bool) $only_missing);
					}

					$process_lease_owned = $this->renew_media_queue_process_lock($lock_token, 'attachment');
					if ($this->is_media_background_work_paused() || !$process_lease_owned) {
						return array_merge(
							$result,
							array(
								'success'       => false,
								'paused'        => true,
								'reason'        => $process_lease_owned ? 'background_paused' : 'lease_lost',
								'queueStatus'   => 'processing',
								'attachment_id' => $attachment_id,
								'message'       => $process_lease_owned
									? __('Media generation was paused before the result could be saved.', 'ultracache')
									: __('The media worker lost its processing lease. Its delayed result was not allowed to overwrite queue state and will be recovered automatically.', 'ultracache'),
							)
						);
					}

					if (empty($result['success'])) {
						$status             = $failure_count >= $max_failures ? 'failed' : 'pending';
						$error_detail       = (string) ($result['failureDetail'] ?? $result['message'] ?? __('Media conversion failed.', 'ultracache'));
						$error              = $force_regenerate_existing && 'pending' === $status
							? $this->get_media_queue_force_regenerate_marker((int) ($result['forceRegenerateCursor'] ?? $force_regenerate_cursor ?? 0)) . "\n" . $error_detail
							: $error_detail;
						$result['paused']   = true;
						$result['reason']   = 'failed' === $status ? 'retry_limit' : 'conversion_failed';
						$result['complete'] = false;
					} elseif (!empty($result['complete'])) {
						$status = !empty($result['converted']) ? 'done' : 'skipped';
						$error  = '';
						if ('skipped' === $status && empty($result['skippedReason'])) {
							$result['skippedReason'] = !empty($result['alreadyOptimized']) ? 'already_optimized' : 'no_supported_work';
						}
					} else {
						$status = 'pending';
						$error  = $force_regenerate_existing
							? $this->get_media_queue_force_regenerate_marker((int) ($result['forceRegenerateCursor'] ?? 0))
							: '';
					}

					$terminal = in_array($status, array('done', 'skipped', 'failed'), true);
					$updated_at = current_time('mysql');
					$completed_at = $terminal ? $updated_at : null;
					$started_at = $terminal ? $now : null;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Claim generation and start timestamp prevent a delayed or replaced worker from overwriting authoritative queue state.
					$persisted = $wpdb->query(
						$wpdb->prepare(
							"UPDATE %i SET status = %s, consecutive_failures = %d, last_error = %s, updated_at = %s, started_at = NULLIF(%s, ''), completed_at = NULLIF(%s, '') WHERE id = %d AND status = 'processing' AND attempts = %d AND started_at = %s",
							$table,
							$status,
							empty($result['success']) ? $failure_count : 0,
							$error,
							$updated_at,
							$started_at,
							$completed_at,
							(int) $row['id'],
							$claim_attempt,
							$now
						)
					);
					if (1 !== (int) $persisted) {
						return array_merge(
							$result,
							array(
								'success'       => false,
								'paused'        => true,
								'reason'        => 'stale_claim',
								'queueStatus'   => 'failed',
								'attachment_id' => $attachment_id,
								'message'       => __('The media queue claim changed before the result could be saved.', 'ultracache'),
							)
						);
					}

					$result['queueStatus'] = $status;
					if (empty($result['success'])) {
						$result['failureAttempt'] = $failure_count;
						$result['failureLimit'] = $max_failures;
					}
					if (in_array($status, array('done', 'skipped'), true)) {
						$result['onDemandAffectedPagePurgeReadyUrls'] = $this->mark_on_demand_affected_media_processed($attachment_id, $format, $result);
					} else {
						$result['onDemandAffectedPagePurgeReadyUrls'] = array();
					}
					return $result;
				} catch (Throwable $e) {
					$message = $e->getMessage();
					$status  = $failure_count >= $max_failures ? 'failed' : 'pending';
					$updated_at = current_time('mysql');
					$completed_at = 'failed' === $status ? $updated_at : null;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exception persistence is also claim-guarded so a delayed worker cannot overwrite a newer owner or the safety circuit.
					$persisted = $wpdb->query(
						$wpdb->prepare(
							"UPDATE %i SET status = %s, consecutive_failures = %d, last_error = %s, updated_at = %s, started_at = NULL, completed_at = NULLIF(%s, '') WHERE id = %d AND status = 'processing' AND attempts = %d AND started_at = %s",
							$table,
							$status,
							$failure_count,
							$message,
							$updated_at,
							$completed_at,
							(int) $row['id'],
							$claim_attempt,
							$now
						)
					);
					if (1 !== (int) $persisted) {
						return array(
							'success'       => false,
							'attachment_id' => $attachment_id,
							'message'       => __('The media worker lost its claim before the exception could be saved.', 'ultracache'),
							'queueStatus'   => 'failed',
							'converted'     => false,
							'complete'      => false,
							'paused'        => true,
							'reason'        => 'stale_claim',
							'onDemandAffectedPagePurgeReadyUrls' => array(),
						);
					}
					return array(
						'success'       => false,
						'attachment_id' => $attachment_id,
						'message'       => $message,
						'queueStatus'   => $status,
						'converted'     => false,
						'complete'      => false,
						'paused'        => true,
						'reason'         => 'failed' === $status ? 'retry_limit' : 'conversion_failed',
						'failureAttempt' => $failure_count,
						'failureLimit'   => $max_failures,
						'onDemandAffectedPagePurgeReadyUrls' => array(),
					);
				}
			} finally {
				if ($owns_lock) {
					$this->release_media_queue_process_lock($lock_token);
				}
			}
		}


		/**
		 * Process a bounded batch of one-unit media operations.
		 *
		 * @param array<string,mixed> $args Batch arguments.
		 * @return array<string,mixed>
		 */
		public function process_media_queue_batch(array $args = array()) {
			if ($this->is_media_background_work_paused()) {
				$status = $this->get_media_queue_status($args['format'] ?? 'best');
				return array_merge(
					array(
						'success'     => false,
						'paused'      => true,
						'reason'      => 'background_paused',
						'pauseReason' => 'background_paused',
						'message'     => __('Media generation is paused by an administrator.', 'ultracache'),
					),
					$status
				);
			}

			if ($this->is_manual_media_conversion_active()) {
				$status = $this->get_media_queue_status($args['format'] ?? 'best');
				return array_merge(
					array(
						'success'     => false,
						'paused'      => true,
						'reason'      => 'manual_session_active',
						'pauseReason' => 'manual_session_active',
						'message'     => __('Dashboard media conversion currently has exclusive queue ownership.', 'ultracache'),
					),
					$status
				);
			}

			$limit        = isset($args['limit']) ? max(1, min(100, (int) $args['limit'])) : 1;
			$format       = $this->normalize_media_queue_format($args['format'] ?? 'best');
			$only_missing = array_key_exists('only_missing', $args) ? (bool) $args['only_missing'] : true;
			$time_budget  = isset($args['time_budget']) ? max(0, (int) $args['time_budget']) : null;
			$should_cancel = isset($args['should_cancel']) && is_callable($args['should_cancel']) ? $args['should_cancel'] : null;
			$is_cli       = function_exists('ultracache_is_cli_context') && ultracache_is_cli_context();
			$hard_cap     = $is_cli ? 300 : 15;
			$budget       = function_exists('ultracache_get_safe_operation_budget')
				? ultracache_get_safe_operation_budget('media_process', $time_budget, $hard_cap)
				: array('started_at' => microtime(true), 'seconds' => $is_cli ? 120 : 8);
			$lock_token = $this->acquire_media_queue_process_lock('batch');

			if ('' === $lock_token) {
				$status = $this->get_media_queue_status($format);
				return array_merge(array('success' => false, 'paused' => true, 'reason' => 'locked', 'pauseReason' => 'locked'), $status);
			}

			$processed           = 0;
			$units_processed     = 0;
			$generated_avif      = 0;
			$generated_webp      = 0;
			$failed              = 0;
			$skipped             = 0;
			$already_optimized   = 0;
			$affected_page_purge_ready_urls = array();
			$affected_page_purged = 0;
			$cancelled = false;
			$lease_lost = false;
			$recovered_stale_claims = 0;

			try {
				$recovered_stale_claims = $this->reset_stale_media_queue_items($lock_token);
				if (!$this->renew_media_queue_process_lock($lock_token, 'batch')) {
					$lease_lost = true;
				}
				$batch = $this->get_media_queue_batch(0, $limit, $format, true);
				foreach ((array) ($batch['items'] ?? array()) as $attachment_id) {
					if ($lease_lost) {
						break;
					}
					if (is_callable($should_cancel) && call_user_func($should_cancel)) {
						$cancelled = true;
						break;
					}
					$loop_pause_reason = function_exists('ultracache_operation_pause_reason') ? ultracache_operation_pause_reason($budget) : '';
					if ('' !== $loop_pause_reason) {
						break;
					}
					if (!$this->renew_media_queue_process_lock($lock_token, 'batch')) {
						$lease_lost = true;
						break;
					}

					$result = $this->process_queued_attachment((int) $attachment_id, $format, $only_missing, $lock_token);
					$processed++;
					$units_processed += (int) ($result['workCompletedThisRun'] ?? 0);
					$generated_avif  += (int) ($result['avif'] ?? 0);
					$generated_webp  += (int) ($result['webp'] ?? 0);
					if ('lease_lost' === (string) ($result['reason'] ?? '')) {
						$lease_lost = true;
					} elseif (empty($result['success']) || 'failed' === (string) ($result['queueStatus'] ?? '')) {
						$failed++;
					} elseif ('skipped' === (string) ($result['queueStatus'] ?? '')) {
						if (!empty($result['alreadyOptimized']) || 'already_optimized' === (string) ($result['skippedReason'] ?? '')) {
							$already_optimized++;
						} else {
							$skipped++;
						}
					}
					foreach ((array) ($result['onDemandAffectedPagePurgeReadyUrls'] ?? array()) as $ready_url) {
						$affected_page_purge_ready_urls[(string) $ready_url] = (string) $ready_url;
					}
					if ($lease_lost) {
						break;
					}
					if (is_callable($should_cancel) && call_user_func($should_cancel)) {
						$cancelled = true;
						break;
					}
					if (!$this->renew_media_queue_process_lock($lock_token, 'batch')) {
						$lease_lost = true;
						break;
					}
				}
				$affected_page_purged = $this->purge_ready_on_demand_affected_pages($affected_page_purge_ready_urls);
			} finally {
				$this->release_media_queue_process_lock($lock_token);
			}

			$status                = $this->get_media_queue_status($format);
			$pause_detected_reason = function_exists('ultracache_operation_pause_reason') ? ultracache_operation_pause_reason($budget) : '';
			$time_budget_reached   = 'time_budget' === $pause_detected_reason && !empty($status['pending']);
			$memory_budget_reached = 'memory_budget' === $pause_detected_reason && !empty($status['pending']);
			$batch_limit_reached   = !empty($status['pending']) && $processed >= $limit;
			$pause_reason          = '';
			if ($cancelled) {
				$pause_reason = 'cancelled';
			} elseif ($lease_lost) {
				$pause_reason = 'lease_lost';
			} elseif ($time_budget_reached) {
				$pause_reason = 'time_budget';
			} elseif ($memory_budget_reached) {
				$pause_reason = 'memory_budget';
			} elseif ($batch_limit_reached) {
				$pause_reason = 'batch_limit';
			}

			return array_merge(
				array(
					'success'                      => 0 === $failed && !$lease_lost,
					'paused'                       => $cancelled || $lease_lost,
					'processed'                    => $processed,
					'unitsProcessed'               => $units_processed,
					'avif'                         => $generated_avif,
					'webp'                         => $generated_webp,
					'failedThisRun'                => $failed,
					'skippedThisRun'               => $skipped,
					'alreadyOptimizedThisRun'      => $already_optimized,
					'batchLimitReached'            => $batch_limit_reached,
					'timeBudgetReached'            => $time_budget_reached,
					'memoryBudgetReached'          => $memory_budget_reached,
					'cancelled'                    => $cancelled,
					'leaseLost'                    => $lease_lost,
					'recoveredStaleClaims'         => $recovered_stale_claims,
					'onDemandAffectedPagesReady'   => count($affected_page_purge_ready_urls),
					'onDemandAffectedPagesPurged'  => $affected_page_purged,
					'complete'                     => empty($status['remaining']) && empty($status['failed']),
					'pauseReason'                  => $pause_reason,
				),
				$status
			);
		}


		public function retry_failed_media_queue_items($format = 'best') {
			if (!$this->ensure_media_queue_table()) {
				return array('success' => false, 'message' => __('Media queue table unavailable.', 'ultracache'));
			}
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			$limit = 1000;
			$count = $wpdb->query($wpdb->prepare("UPDATE %i SET status = 'pending', consecutive_failures = 0, last_error = '', updated_at = %s, started_at = NULL, completed_at = NULL WHERE format = %s AND status = 'failed' LIMIT %d", $table, current_time('mysql'), $format, $limit));
			$status = $this->get_media_queue_status($format);
			return array_merge(array('success' => true, 'retried' => is_numeric($count) ? (int) $count : 0, 'hasMore' => !empty($status['failed'])), $status);
		}


		public function requeue_completed_media_queue_items_for_regeneration($format = 'best') {
			if (!$this->ensure_media_queue_table()) {
				return array('success' => false, 'message' => __('Media queue table unavailable.', 'ultracache'));
			}
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			$limit = 1000;
			$count = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = 'pending', attempts = 0, consecutive_failures = 0, last_error = %s, updated_at = %s, started_at = NULL, completed_at = NULL WHERE format = %s AND status IN ('done','skipped') LIMIT %d",
					$table,
					$this->get_media_queue_force_regenerate_marker(0),
					current_time('mysql'),
					$format,
					$limit
				)
			);
			$status = $this->get_media_queue_status($format);
			return array_merge(array('success' => true, 'requeued' => is_numeric($count) ? (int) $count : 0, 'hasMore' => ((int) ($status['done'] ?? 0) + (int) ($status['skipped'] ?? 0)) > 0), $status);
		}


		public function clear_completed_media_queue_items($format = 'best') {
			if (!$this->ensure_media_queue_table()) {
				return array('success' => false, 'message' => __('Media queue table unavailable.', 'ultracache'));
			}
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			$limit = 1000;
			$count = $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE format = %s AND status IN ('done','skipped') LIMIT %d", $table, $format, $limit));
			$status = $this->get_media_queue_status($format);
			return array_merge(array('success' => true, 'cleared' => is_numeric($count) ? (int) $count : 0, 'hasMore' => ((int) ($status['done'] ?? 0) + (int) ($status['skipped'] ?? 0)) > 0), $status);
		}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
