<?php
/**
 * UltraCache media queue processing and administrator action helpers.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Queue_Runner_Trait
{
		/** Exact queue claim recovered if the current PHP worker exits before persistence. */
		private $media_queue_shutdown_claim = array();

		/** Whether the per-request queue-claim shutdown recovery callback is registered. */
		private $media_queue_shutdown_claim_registered = false;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache uses private custom media conversion queue tables with validated table identifiers.


		public function get_media_queue_batch($cursor = 0, $limit = 100, $format = 'best', $auto_rebuild = true, $migrate_units = true) {
			$cursor = max(0, (int) $cursor);
			$limit = max(1, min(500, (int) $limit));
			$format = $this->normalize_media_queue_format($format);
			if (!$this->ensure_media_queue_table()) {
				return array('items' => array(), 'total' => 0, 'cursor' => (string) $cursor, 'nextCursor' => '', 'hasMore' => false, 'message' => __('Media queue table unavailable.', 'ultracache'));
			}

			$status = $this->get_media_queue_status($format);
			$unit_migration = array();
			if ($auto_rebuild && $migrate_units && !empty($status['buildComplete']) && empty($status['unitInventoryComplete']) && method_exists($this, 'run_media_queue_units_migration_maintenance')) {
				$unit_migration = $this->run_media_queue_units_migration_maintenance(max(25, min(100, $limit)), 1.0);
				$status = $this->get_media_queue_status($format);
			}
			$repair = array('repaired' => false, 'requeued' => 0);
			if ($auto_rebuild && !empty($status['needsRepair'])) {
				$repair = $this->repair_media_queue_if_optimized_storage_missing($format);
				$status = $this->get_media_queue_status($format);
			}
			$build_chunk = array('scanned' => 0, 'queued' => 0, 'complete' => !empty($status['buildComplete']));
			$queue_wait_reason = '';
			$needs_auto_build = $auto_rebuild && empty($status['buildComplete']);
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
						if ($generation_conflict) {
							$queue_wait_reason = 'generation_conflict';
						}

						if (!$generation_conflict && empty($status['attachmentQueueTotal'])) {
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
							if (!empty($build_chunk['staleGeneration'])) {
								$queue_wait_reason = 'generation_conflict';
							} elseif (!empty($build_chunk['pauseReason'])) {
								$queue_wait_reason = 'budget_pause';
							} elseif (empty($build_chunk['complete']) && empty($build_chunk['scanned']) && empty($build_chunk['queued'])) {
								$queue_wait_reason = 'no_progress';
							}
						}
					} finally {
						$this->release_media_queue_rebuild_lock($rebuild_lock);
					}
					$status = $this->get_media_queue_status($format);
				} else {
					$queue_wait_reason = 'lock_busy';
				}
			}


			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$rows = $wpdb->get_results($wpdb->prepare("SELECT id, attachment_id FROM %i WHERE source_kind = 'attachment' AND format = %s AND status = 'pending' AND id > %d ORDER BY id ASC LIMIT %d", $table, $format, $cursor, $limit), ARRAY_A);
			if (empty($rows) && $cursor > 0 && !empty($status['attachmentPending'])) {
				$rows = $wpdb->get_results($wpdb->prepare("SELECT id, attachment_id FROM %i WHERE source_kind = 'attachment' AND format = %s AND status = 'pending' ORDER BY id ASC LIMIT %d", $table, $format, $limit), ARRAY_A);
			}

			$attachment_pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE source_kind = 'attachment' AND format = %s AND status = 'pending'", $table, $format));
			$items = array();
			$next_cursor = '';
			foreach ((array) $rows as $row) {
				$items[] = (int) $row['attachment_id'];
				$next_cursor = (string) (int) $row['id'];
			}

			$has_more = false;
			if ('' !== $next_cursor) {
				$has_more = (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM %i WHERE source_kind = 'attachment' AND format = %s AND status = 'pending' AND id > %d LIMIT 1", $table, $format, (int) $next_cursor));
			} elseif ($attachment_pending > 0) {
				$has_more = true;
			}
			if (!$has_more && (empty($status['buildComplete']) || empty($status['unitInventoryComplete']))) {
				$has_more = true;
			}

			$attachment_total = max(
				(int) ($status['libraryTotal'] ?? 0),
				(int) ($status['attachmentQueueTotal'] ?? 0),
				$attachment_pending,
				count($items)
			);
			$waiting_for_queue_build = empty($items)
				&& $has_more
				&& (empty($status['buildComplete']) || empty($status['unitInventoryComplete']));
			if ($waiting_for_queue_build && '' === $queue_wait_reason) {
				$queue_wait_reason = empty($status['buildComplete'])
					? 'building'
					: 'unit_inventory_materializing';
			}
			$retry_after_ms = 0;
			if ($waiting_for_queue_build) {
				$retry_after_ms = (int) apply_filters('ultracache_media_queue_build_retry_after_ms', 500, $queue_wait_reason, $status);
				$retry_after_ms = max(250, min(5000, $retry_after_ms));
			}
			$queue_progress_token = hash(
				'sha256',
				wp_json_encode(
					array(
						$this->normalize_media_queue_rebuild_generation($status['rebuildGeneration'] ?? ''),
						max(0, (int) ($status['buildOffset'] ?? 0)),
						max(0, (int) ($status['attachmentPending'] ?? 0)),
						max(0, (int) ($status['attachmentProcessing'] ?? 0)),
						max(0, (int) ($status['attachmentDone'] ?? 0)),
						max(0, (int) ($status['attachmentFailed'] ?? 0)),
						max(0, (int) ($status['attachmentSkipped'] ?? 0)),
						max(0, (int) ($status['unitPending'] ?? 0)),
						max(0, (int) ($status['unitProcessing'] ?? 0)),
						max(0, (int) ($status['unitDone'] ?? 0)),
						max(0, (int) ($status['unitFailed'] ?? 0)),
						max(0, (int) ($status['unitSkipped'] ?? 0)),
						max(0, (int) ($status['unitUnmaterializedParents'] ?? 0)),
						empty($status['unitInventoryComplete']) ? 0 : 1,
					)
				)
			);

			$attachment_queue_status = array_merge(
				$status,
				array(
					'total' => (int) ($status['attachmentQueueTotal'] ?? 0),
					'pending' => (int) ($status['attachmentPending'] ?? 0),
					'processing' => (int) ($status['attachmentProcessing'] ?? 0),
					'done' => (int) ($status['attachmentDone'] ?? 0),
					'failed' => (int) ($status['attachmentFailed'] ?? 0),
					'skipped' => (int) ($status['attachmentSkipped'] ?? 0),
					'alreadyOptimized' => (int) ($status['attachmentSkipped'] ?? 0),
					'completed' => (int) ($status['attachmentCompleted'] ?? 0),
					'remaining' => (int) ($status['attachmentRemaining'] ?? 0),
					'isComplete' => !empty($status['attachmentIsComplete']),
				)
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
				'waitingForQueueBuild' => $waiting_for_queue_build,
				'retryAfterMs' => $retry_after_ms,
				'queueWaitReason' => $queue_wait_reason,
				'queueProgressToken' => $queue_progress_token,
				'physicalUnitMigration' => $unit_migration,
				'buildGeneration' => $this->normalize_media_queue_rebuild_generation($status['rebuildGeneration'] ?? ''),
				'buildOffset' => max(0, (int) ($status['buildOffset'] ?? 0)),
				'complete' => empty($items) && !$has_more && !empty($status['attachmentIsComplete']),
				'message' => (empty($items) && !$has_more && !empty($status['attachmentIsComplete'])) ? 'Media conversion complete. All queued media items are already optimized or processed.' : '',
				'queue' => $attachment_queue_status,
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
		 * Arm exact recovery for one claimed queue row.
		 *
		 * @param int    $row_id        Queue row ID.
		 * @param int    $claim_attempt  Claimed attempts generation.
		 * @param string $claim_started Exact started_at value.
		 * @return void
		 */
		private function arm_media_queue_shutdown_claim($row_id, $claim_attempt, $claim_started, $unit_id = 0, $unit_attempt = 0, $unit_started = '') {
			$this->media_queue_shutdown_claim = array(
				'id'            => max(0, (int) $row_id),
				'attempts'      => max(0, (int) $claim_attempt),
				'startedAt'     => (string) $claim_started,
				'unitId'        => max(0, (int) $unit_id),
				'unitAttempts'  => max(0, (int) $unit_attempt),
				'unitStartedAt' => (string) $unit_started,
			);

			if (!$this->media_queue_shutdown_claim_registered) {
				$this->media_queue_shutdown_claim_registered = true;
				register_shutdown_function(array($this, 'recover_interrupted_media_queue_claim_on_shutdown'));
			}
		}


		/**
		 * Disarm exact recovery after the queue row reaches a persisted state.
		 *
		 * @param int    $row_id        Queue row ID.
		 * @param int    $claim_attempt  Claimed attempts generation.
		 * @param string $claim_started Exact started_at value.
		 * @return void
		 */
		private function clear_media_queue_shutdown_claim($row_id, $claim_attempt, $claim_started) {
			$claim = $this->media_queue_shutdown_claim;
			if (
				!empty($claim)
				&& (int) ($claim['id'] ?? 0) === (int) $row_id
				&& (int) ($claim['attempts'] ?? 0) === (int) $claim_attempt
				&& hash_equals((string) ($claim['startedAt'] ?? ''), (string) $claim_started)
			) {
				$this->media_queue_shutdown_claim = array();
			}
		}


		/**
		 * Release a cooperative early-return claim without consuming the stale-worker
		 * recovery budget. A fatal/terminated request leaves the claim armed for the
		 * registered shutdown callback instead.
		 *
		 * @param string $reason Persisted retry reason.
		 * @return int Number of rows released.
		 */
		private function release_interrupted_media_queue_claim_to_pending($reason = 'media_worker_interrupted') {
			$claim = $this->media_queue_shutdown_claim;
			if (empty($claim) || !$this->media_queue_table_exists()) {
				return 0;
			}

			$row_id = max(0, (int) ($claim['id'] ?? 0));
			$claim_attempt = max(0, (int) ($claim['attempts'] ?? 0));
			$claim_started = (string) ($claim['startedAt'] ?? '');
			if ($row_id <= 0 || $claim_attempt <= 0 || '' === $claim_started) {
				return 0;
			}

			global $wpdb;
			$unit_id = max(0, (int) ($claim['unitId'] ?? 0));
			$unit_attempt = max(0, (int) ($claim['unitAttempts'] ?? 0));
			$unit_started = (string) ($claim['unitStartedAt'] ?? '');
			if ($unit_id > 0 && $unit_attempt > 0 && '' !== $unit_started && $this->media_queue_units_table_exists()) {
				$units_table = $this->get_media_queue_units_table_name();
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE %i SET status = 'pending', failure_code = %s, failure_stage = 'worker', failure_detail = %s, resolution_code = '', resolution_detail = '', resolution_context = '', encoder_attempts = '', updated_at = %s, started_at = NULL, completed_at = NULL WHERE id = %d AND status = 'processing' AND attempts = %d AND started_at = %s",
						$units_table,
						sanitize_key((string) $reason),
						'Physical media unit processing was interrupted before persistence.',
						current_time('mysql'),
						$unit_id,
						$unit_attempt,
						$unit_started
					)
				);
			}
			$table = $this->get_media_queue_table_name();
			$force_marker_like = $wpdb->esc_like('__ultracache_force_regenerate__:') . '%';
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = 'pending', last_error = CASE WHEN last_error LIKE %s THEN last_error ELSE %s END, updated_at = %s, started_at = NULL, completed_at = NULL WHERE id = %d AND status = 'processing' AND attempts = %d AND started_at = %s",
					$table,
					$force_marker_like,
					sanitize_key((string) $reason),
					current_time('mysql'),
					$row_id,
					$claim_attempt,
					$claim_started
				)
			);
			if (1 !== (int) $result) {
				return 0;
			}

			if ($unit_id > 0 && method_exists($this, 'reconcile_media_queue_parent_ids_after_unit_recovery')) {
				$this->reconcile_media_queue_parent_ids_after_unit_recovery(array($row_id));
			}
			$this->clear_media_queue_shutdown_claim($row_id, $claim_attempt, $claim_started);
			$this->invalidate_media_work_summary_cache();
			if (method_exists($this, 'schedule_background_generation_queue')) {
				$this->schedule_background_generation_queue(15);
			}
			return 1;
		}


		/**
		 * Return an interrupted exact claim to pending, or quarantine it after the
		 * persistent stale-worker retry budget is exhausted.
		 *
		 * This method is also the registered PHP shutdown callback. The exact
		 * id/attempt/started_at guard prevents a late shutdown from changing a row
		 * already completed or reclaimed by a newer worker.
		 *
		 * @return int Number of rows recovered.
		 */
		public function recover_interrupted_media_queue_claim_on_shutdown() {
			$claim = $this->media_queue_shutdown_claim;
			$this->media_queue_shutdown_claim = array();
			if (empty($claim) || !$this->media_queue_table_exists()) {
				return 0;
			}

			$row_id = max(0, (int) ($claim['id'] ?? 0));
			$claim_attempt = max(0, (int) ($claim['attempts'] ?? 0));
			$claim_started = (string) ($claim['startedAt'] ?? '');
			if ($row_id <= 0 || $claim_attempt <= 0 || '' === $claim_started) {
				return 0;
			}

			global $wpdb;
			$unit_id = max(0, (int) ($claim['unitId'] ?? 0));
			$unit_attempt = max(0, (int) ($claim['unitAttempts'] ?? 0));
			$unit_started = (string) ($claim['unitStartedAt'] ?? '');
			$unit_recovered = false;
			$unit_recovery_status = '';
			$unit_recovery_count = 0;
			$unit_recovery_error = '';
			if ($unit_id > 0 && $unit_attempt > 0 && '' !== $unit_started && $this->media_queue_units_table_exists()) {
				$units_table = $this->get_media_queue_units_table_name();
				$unit_row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id, stale_recoveries FROM %i WHERE id = %d AND status = 'processing' AND attempts = %d AND started_at = %s LIMIT 1",
						$units_table,
						$unit_id,
						$unit_attempt,
						$unit_started
					),
					ARRAY_A
				);
				if (is_array($unit_row) && !empty($unit_row['id'])) {
					$unit_next_recovery = min(65535, max(0, (int) ($unit_row['stale_recoveries'] ?? 0)) + 1);
					$unit_terminal = $unit_next_recovery >= $this->get_media_queue_max_stale_recoveries();
					$unit_now = current_time('mysql');
					$unit_updated = $wpdb->query(
						$wpdb->prepare(
							"UPDATE %i SET status = %s, stale_recoveries = %d, failure_code = %s, failure_stage = 'worker', failure_detail = %s, resolution_code = '', resolution_detail = '', resolution_context = '', encoder_attempts = '', updated_at = %s, started_at = NULL, completed_at = NULLIF(%s, '') WHERE id = %d AND status = 'processing' AND attempts = %d AND started_at = %s",
							$units_table,
							$unit_terminal ? 'failed' : 'pending',
							$unit_next_recovery,
							$unit_terminal ? 'media_worker_stale_recovery_limit' : 'media_worker_terminated',
							$unit_terminal ? 'The physical media unit reached the stale-worker recovery limit.' : 'The physical media unit worker terminated before persistence.',
							$unit_now,
							$unit_terminal ? $unit_now : '',
							$unit_id,
							$unit_attempt,
							$unit_started
						)
					);
					if (1 === (int) $unit_updated) {
						$unit_recovered = true;
						$unit_recovery_status = $unit_terminal ? 'failed' : 'pending';
						$unit_recovery_count = $unit_next_recovery;
						$unit_recovery_error = $unit_terminal ? 'media_worker_stale_recovery_limit' : 'media_worker_terminated';
					}
				}
			}
			$table = $this->get_media_queue_table_name();
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, stale_recoveries, last_error FROM %i WHERE id = %d AND status = 'processing' AND attempts = %d AND started_at = %s LIMIT 1",
					$table,
					$row_id,
					$claim_attempt,
					$claim_started
				),
				ARRAY_A
			);
			if (!is_array($row) || empty($row['id'])) {
				return 0;
			}

			$next_recovery = $unit_recovered
				? $unit_recovery_count
				: min(65535, max(0, (int) ($row['stale_recoveries'] ?? 0)) + 1);
			$status = $unit_recovered ? $unit_recovery_status : ($next_recovery >= $this->get_media_queue_max_stale_recoveries() ? 'failed' : 'pending');
			$terminal = 'failed' === $status;
			$error = $unit_recovered ? $unit_recovery_error : ($terminal ? 'media_worker_stale_recovery_limit' : 'media_worker_terminated');
			$now = current_time('mysql');
			$completed_at = $terminal ? $now : '';
			$persisted_parent_error = 0 === strpos((string) ($row['last_error'] ?? ''), '__ultracache_force_regenerate__:')
				? (string) $row['last_error']
				: $error;
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = %s, stale_recoveries = %d, last_error = %s, updated_at = %s, started_at = NULL, completed_at = NULLIF(%s, '') WHERE id = %d AND status = 'processing' AND attempts = %d AND started_at = %s",
					$table,
					$status,
					$next_recovery,
					$persisted_parent_error,
					$now,
					$completed_at,
					$row_id,
					$claim_attempt,
					$claim_started
				)
			);
			if (1 !== (int) $result) {
				return 0;
			}

			if ($unit_id > 0 && method_exists($this, 'reconcile_media_queue_parent_ids_after_unit_recovery')) {
				$this->reconcile_media_queue_parent_ids_after_unit_recovery(array($row_id));
			}
			$this->invalidate_media_work_summary_cache();
			if (!$terminal && method_exists($this, 'schedule_background_generation_queue')) {
				$this->schedule_background_generation_queue(15);
			}
			return 1;
		}


		/**
		 * Return processing rows that can be recovered manually because no live
		 * media processor lease currently owns them.
		 *
		 * @param string $format Queue format.
		 * @return int
		 */
		private function get_media_queue_recoverable_interrupted_count($format = 'best') {
			if (!$this->media_queue_table_exists()) {
				return 0;
			}
			if (function_exists('ultracache_get_lock')) {
				$lock = ultracache_get_lock(self::MEDIA_QUEUE_PROCESS_LOCK);
				if (!empty($lock['token']) && empty($lock['expired'])) {
					return 0;
				}
			}
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			return max(0, (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE format = %s AND status = 'processing'",
					$table,
					$format
				)
			));
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
			if (!$this->ensure_media_queue_table()) {
				return 0;
			}

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
			return $this->process_queued_attachment_unit_worker(
				$attachment_id,
				$format,
				$only_missing,
				$lock_token,
				$manual_session_token,
				$force_regenerate_existing
			);
		}


		private function get_media_queue_work_batch($limit, $format = 'best', $auto_rebuild = true, $migrate_units = true) {
			$limit = max(1, min(100, (int) $limit));
			$format = $this->normalize_media_queue_format($format);
			$this->get_media_queue_batch(0, max(1, $limit), $format, $auto_rebuild, $migrate_units);
			if (!$this->ensure_media_queue_table()) {
				return array();
			}
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, attachment_id, source_kind, source_identity, source_scope, source_owner, source_relative_path, source_url, format, requested_format, source_mtime, source_size, status, attempts, consecutive_failures, stale_recoveries, last_error FROM %i WHERE format = %s AND status = 'pending' ORDER BY attempts ASC, id ASC LIMIT %d",
					$table,
					$format,
					$limit
				),
				ARRAY_A
			);
		}


		private function resolve_local_asset_queue_source(array $row) {
			$url = esc_url_raw((string) ($row['source_url'] ?? ''));
			$descriptor = function_exists('ultracache_local_public_source_descriptor')
				? ultracache_local_public_source_descriptor($url, array('jpg','jpeg','png','webp','avif'))
				: array();
			if (empty($descriptor)) {
				return array();
			}
			$expected_identity = strtolower((string) ($row['source_identity'] ?? ''));
			if (!hash_equals($expected_identity, strtolower((string) ($descriptor['source_identity'] ?? '')))) {
				return array('identityMismatch' => true);
			}
			return $descriptor;
		}


		private function process_queued_local_asset(array $row, $lock_token) {
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$row_id = max(0, (int) ($row['id'] ?? 0));
			$requested_format = strtolower((string) ($row['requested_format'] ?? ''));
			$source_identity = strtolower((string) ($row['source_identity'] ?? ''));
			if ($row_id <= 0 || !in_array($requested_format, array('avif','webp'), true) || !preg_match('/^[a-f0-9]{64}$/', $source_identity)) {
				return array('success' => false, 'queueStatus' => 'failed', 'reason' => 'invalid_local_asset_row', 'message' => __('The local asset queue row is invalid.', 'ultracache'));
			}
			$claim_started = current_time('mysql');
			$claimed = $wpdb->query($wpdb->prepare(
				"UPDATE %i SET status = 'processing', attempts = attempts + 1, started_at = %s, updated_at = %s, completed_at = NULL WHERE id = %d AND status = 'pending'",
				$table,
				$claim_started,
				$claim_started,
				$row_id
			));
			if (1 !== (int) $claimed) {
				return array('success' => false, 'paused' => true, 'reason' => 'already_claimed', 'queueStatus' => 'processing', 'sourceIdentity' => $source_identity);
			}
			$claim_attempt = (int) ($row['attempts'] ?? 0) + 1;
			$this->arm_media_queue_shutdown_claim($row_id, $claim_attempt, $claim_started);
			$failure_count = (int) ($row['consecutive_failures'] ?? 0) + 1;
			$max_failures = max(1, (int) apply_filters('ultracache_media_queue_max_consecutive_failures', 3, 0, $requested_format));
			try {
				if (!$this->renew_media_queue_process_lock($lock_token, 'local_asset')) {
					return array('success' => false, 'paused' => true, 'reason' => 'lease_lost', 'queueStatus' => 'processing', 'sourceIdentity' => $source_identity);
				}
				$source = $this->resolve_local_asset_queue_source($row);
				if (!empty($source['identityMismatch'])) {
					$result = array('success' => false, 'failureCode' => 'source_identity_changed', 'failureDetail' => 'The canonical local asset identity changed before conversion.', 'converted' => false, 'complete' => false);
				} elseif (empty($source)) {
					$result = array('success' => true, 'converted' => false, 'complete' => true, 'skippedReason' => 'source_missing', 'sourceMissing' => true);
				} else {
					$fingerprint = $this->get_optimized_source_fingerprint((string) $source['local_path'], true);
					$fingerprint_updated_at = current_time('mysql');
					$fingerprint_persisted = $wpdb->query(
						$wpdb->prepare(
							"UPDATE %i SET source_mtime = %d, source_size = %d, updated_at = %s WHERE id = %d AND status = 'processing' AND attempts = %d AND started_at = %s",
							$table,
							(int) ($fingerprint['mtime'] ?? 0),
							(int) ($fingerprint['size'] ?? 0),
							$fingerprint_updated_at,
							$row_id,
							$claim_attempt,
							$claim_started
						)
					);
					if (false === $fingerprint_persisted) {
						return array('success' => false, 'paused' => true, 'reason' => 'fingerprint_persist_failed', 'queueStatus' => 'processing', 'sourceIdentity' => $source_identity);
					}

					// MySQL/MariaDB report changed rows, not matched rows. A freshly queued local
					// asset already carries the same source mtime/size, and the claim plus this
					// fingerprint write may occur in the same one-second timestamp precision.
					// In that valid no-op case the UPDATE returns 0, so confirm the guarded
					// claim still exists instead of misclassifying it as a stale worker.
					if (0 === (int) $fingerprint_persisted) {
						$claim_still_current = (bool) $wpdb->get_var(
							$wpdb->prepare(
								"SELECT 1 FROM %i WHERE id = %d AND status = 'processing' AND attempts = %d AND started_at = %s LIMIT 1",
								$table,
								$row_id,
								$claim_attempt,
								$claim_started
							)
						);
						if (!$claim_still_current) {
							return array('success' => false, 'paused' => true, 'reason' => 'stale_claim', 'queueStatus' => 'failed', 'sourceIdentity' => $source_identity);
						}
					}
					$lookup = $this->get_optimized_media_variant_lookup_from_source_descriptor($source, $requested_format);
					if (!empty($lookup['url'])) {
						$result = array('success' => true, 'converted' => false, 'complete' => true, 'alreadyOptimized' => true, 'skippedReason' => 'already_optimized');
					} else {
						$generated = $this->generate_local_asset_variant($source, $requested_format);
						if ($generated) {
							$result = array('success' => true, 'converted' => true, 'complete' => true, $requested_format => 1, 'workCompletedThisRun' => 1, 'generatedPath' => $generated);
						} else {
							$failure = $this->get_last_media_conversion_failure();
							$skip = (string) ($failure['skippedReason'] ?? '');
							$result = '' !== $skip
								? array('success' => true, 'converted' => false, 'complete' => true, 'skippedReason' => $skip, 'skipDetail' => (string) ($failure['skipDetail'] ?? ''))
								: array('success' => false, 'converted' => false, 'complete' => false, 'failureCode' => (string) ($failure['failureCode'] ?? 'conversion_failed'), 'failureDetail' => (string) ($failure['failureDetail'] ?? __('Local asset conversion failed.', 'ultracache')));
						}
					}
				}
				$process_lease_owned = $this->renew_media_queue_process_lock($lock_token, 'local_asset');
				if ($this->is_media_background_work_paused() || !$process_lease_owned) {
					return array_merge(
						$result,
						array(
							'success' => false,
							'paused' => true,
							'reason' => $process_lease_owned ? 'background_paused' : 'lease_lost',
							'queueStatus' => 'processing',
							'sourceIdentity' => $source_identity,
						)
					);
				}
				if (empty($result['success'])) {
					$status = $failure_count >= $max_failures ? 'failed' : 'pending';
					$error = (string) ($result['failureDetail'] ?? __('Local asset conversion failed.', 'ultracache'));
				} else {
					$status = !empty($result['converted']) ? 'done' : 'skipped';
					$error = '';
				}
				$updated = current_time('mysql');
				$completed = in_array($status, array('done','skipped','failed'), true) ? $updated : '';
				$persisted = $wpdb->query($wpdb->prepare(
					"UPDATE %i SET status = %s, consecutive_failures = %d, last_error = %s, updated_at = %s, started_at = NULL, completed_at = NULLIF(%s, '') WHERE id = %d AND status = 'processing' AND attempts = %d AND started_at = %s",
					$table,
					$status,
					empty($result['success']) ? $failure_count : 0,
					$error,
					$updated,
					$completed,
					$row_id,
					$claim_attempt,
					$claim_started
				));
				if (1 !== (int) $persisted) {
					$this->clear_media_queue_shutdown_claim($row_id, $claim_attempt, $claim_started);
					return array_merge($result, array('success' => false, 'paused' => true, 'reason' => 'stale_claim', 'queueStatus' => 'failed', 'sourceIdentity' => $source_identity));
				}
				$this->clear_media_queue_shutdown_claim($row_id, $claim_attempt, $claim_started);
				$result['queueStatus'] = $status;
				$result['workCompletedThisRun'] = 1;
				$result['sourceIdentity'] = $source_identity;
				$result['requestedFormat'] = $requested_format;
				$result['onDemandAffectedPagePurgeReadyUrls'] = in_array($status, array('done','skipped'), true)
					? $this->mark_on_demand_affected_source_processed('local_asset', $source_identity, 'best', $result, $requested_format)
					: array();
				return $result;
			} catch (Throwable $e) {
				$status = $failure_count >= $max_failures ? 'failed' : 'pending';
				$updated = current_time('mysql');
				$completed = 'failed' === $status ? $updated : '';
				$persisted = $wpdb->query($wpdb->prepare(
					"UPDATE %i SET status = %s, consecutive_failures = %d, last_error = %s, updated_at = %s, started_at = NULL, completed_at = NULLIF(%s, '') WHERE id = %d AND status = 'processing' AND attempts = %d AND started_at = %s",
					$table, $status, $failure_count, $e->getMessage(), $updated, $completed, $row_id, $claim_attempt, $claim_started
				));
				$this->clear_media_queue_shutdown_claim($row_id, $claim_attempt, $claim_started);
				if (1 !== (int) $persisted) {
					return array('success' => false, 'queueStatus' => 'failed', 'reason' => 'stale_claim', 'message' => __('The media worker lost its queue claim before the exception could be saved.', 'ultracache'), 'sourceIdentity' => $source_identity, 'onDemandAffectedPagePurgeReadyUrls' => array());
				}
				return array('success' => false, 'queueStatus' => $status, 'reason' => 'conversion_failed', 'message' => $e->getMessage(), 'sourceIdentity' => $source_identity, 'workCompletedThisRun' => 1, 'onDemandAffectedPagePurgeReadyUrls' => array());
			} finally {
				// Cooperative early returns release the exact claim without consuming a
				// stale-worker recovery. A fatal/terminated request skips this path and
				// is recovered by the registered shutdown callback.
				$this->release_interrupted_media_queue_claim_to_pending();
			}
		}


		/**
		 * Process a bounded batch of one-unit media operations.
		 *
		 * @param array<string,mixed> $args Batch arguments. Optional attachment_ids scope exact queue work without rebuilding the global queue.
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
			$auto_rebuild = array_key_exists('auto_rebuild', $args) ? (bool) $args['auto_rebuild'] : true;
			$scoped_attachment_ids = array_values(array_filter(array_unique(array_map(
				'absint',
				is_array($args['attachment_ids'] ?? null) ? $args['attachment_ids'] : array()
			))));
			if (count($scoped_attachment_ids) > 100) {
				$scoped_attachment_ids = array_slice($scoped_attachment_ids, 0, 100);
			}
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

			$processed           = 0; // Legacy alias: parent queue rows touched in this batch.
			$units_processed     = 0; // Legacy alias: physical unit attempts in this batch.
			$attachment_touches  = 0;
			$local_asset_touches = 0;
			$attachments_completed = 0;
			$attachments_failed_terminal = 0;
			$local_assets_completed = 0;
			$local_assets_failed_terminal = 0;
			$units_resolved      = 0;
			$units_skipped       = 0;
			$units_already_optimized = 0;
			$unit_failure_attempts = 0;
			$terminal_unit_failures = 0;
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
			$unit_migration = array(
				'success'        => true,
				'processed'      => 0,
				'failed'         => 0,
				'changedParents' => 0,
				'changedUnits'   => 0,
				'complete'       => !empty($scoped_attachment_ids),
			);

			try {
				if (empty($scoped_attachment_ids)) {
					$unit_migration = $this->run_media_queue_units_migration_maintenance(max(25, $limit), 2.0);
				}
				$recovered_stale_claims = $this->reset_stale_media_queue_items($lock_token);
				if (!$this->renew_media_queue_process_lock($lock_token, 'batch')) {
					$lease_lost = true;
				}
				$batch_items = !empty($scoped_attachment_ids)
					? array_map(static function ($attachment_id) { return array('source_kind' => 'attachment', 'attachment_id' => (int) $attachment_id); }, array_slice($scoped_attachment_ids, 0, $limit))
					: $this->get_media_queue_work_batch($limit, $format, $auto_rebuild, false);
				foreach ($batch_items as $queue_item) {
					$attachment_id = is_array($queue_item) ? (int) ($queue_item['attachment_id'] ?? 0) : (int) $queue_item;
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

					$is_local_asset = is_array($queue_item) && 'local_asset' === (string) ($queue_item['source_kind'] ?? '');
					$result = $is_local_asset
						? $this->process_queued_local_asset($queue_item, $lock_token)
						: $this->process_queued_attachment((int) $attachment_id, $format, $only_missing, $lock_token);
					$processed++;
					if ($is_local_asset) {
						$local_asset_touches++;
					} else {
						$attachment_touches++;
					}
					$unit_attempts = max(0, (int) ($result['workCompletedThisRun'] ?? 0));
					$units_processed += $unit_attempts;
					$generated_avif  += (int) ($result['avif'] ?? 0);
					$generated_webp  += (int) ($result['webp'] ?? 0);
					$queue_status = (string) ($result['queueStatus'] ?? '');
					$unit_status = (string) ($result['unitStatus'] ?? '');
					$physical_status = $is_local_asset ? $queue_status : $unit_status;
					if ($unit_attempts > 0 && in_array($physical_status, array('done', 'skipped', 'failed'), true)) {
						$units_resolved += $unit_attempts;
					}
					$unit_already_optimized = $unit_attempts > 0 && (
						!empty($result['alreadyOptimized'])
						|| 'already_optimized' === (string) ($result['skippedReason'] ?? '')
					);
					if ($unit_already_optimized) {
						$units_already_optimized += $unit_attempts;
					} elseif ($unit_attempts > 0 && 'skipped' === $physical_status) {
						$units_skipped += $unit_attempts;
					}
					$conversion_failure = $unit_attempts > 0
						&& empty($result['success'])
						&& in_array($physical_status, array('pending', 'failed'), true);
					if ($conversion_failure) {
						$unit_failure_attempts += $unit_attempts;
					}
					if ($unit_attempts > 0 && 'failed' === $physical_status) {
						$terminal_unit_failures += $unit_attempts;
					}
					if (!$is_local_asset && in_array($queue_status, array('done', 'skipped'), true)) {
						$attachments_completed++;
					} elseif (!$is_local_asset && 'failed' === $queue_status) {
						$attachments_failed_terminal++;
					} elseif ($is_local_asset && in_array($queue_status, array('done', 'skipped'), true)) {
						$local_assets_completed++;
					} elseif ($is_local_asset && 'failed' === $queue_status) {
						$local_assets_failed_terminal++;
					}
					if ('lease_lost' === (string) ($result['reason'] ?? '')) {
						$lease_lost = true;
					} elseif (empty($result['success']) || 'failed' === $queue_status) {
						$failed++;
					} elseif ('skipped' === $queue_status) {
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
			} elseif ($processed <= 0 && (int) ($unit_migration['failed'] ?? 0) > 0) {
				$pause_reason = 'unit_inventory_failed';
			}

			return array_merge(
				array(
					'success'                      => 0 === $failed && 0 === max(0, (int) ($unit_migration['failed'] ?? 0)) && !$lease_lost,
					'paused'                       => $cancelled || $lease_lost,
					'processed'                    => $processed,
					'parentsTouchedThisRun'        => $processed,
					'attachmentsTouchedThisRun'    => $attachment_touches,
					'localAssetsTouchedThisRun'     => $local_asset_touches,
					'attachmentsCompletedThisRun'  => $attachments_completed,
					'attachmentsFailedThisRun'     => $attachments_failed_terminal,
					'attachmentsProcessedThisRun'  => $attachments_completed + $attachments_failed_terminal,
					'localAssetsCompletedThisRun'   => $local_assets_completed,
					'localAssetsFailedThisRun'      => $local_assets_failed_terminal,
					'localAssetsProcessedThisRun'   => $local_assets_completed + $local_assets_failed_terminal,
					'unitsProcessed'               => $units_processed,
					'unitAttemptsThisRun'          => $units_processed,
					'unitsResolvedThisRun'         => $units_resolved,
					'unitsSkippedThisRun'          => $units_skipped,
					'unitsAlreadyOptimizedThisRun' => $units_already_optimized,
					'unitsGeneratedThisRun'        => $generated_avif + $generated_webp,
					'unitFailuresThisRun'          => $unit_failure_attempts,
					'terminalUnitFailuresThisRun'  => $terminal_unit_failures,
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
					'physicalUnitMigration'         => $unit_migration,
					'unitMigrationParentsThisRun'  => max(0, (int) ($unit_migration['processed'] ?? 0)),
					'unitMigrationFailuresThisRun' => max(0, (int) ($unit_migration['failed'] ?? 0)),
					'unitMigrationChangedParentsThisRun' => max(0, (int) ($unit_migration['changedParents'] ?? 0)),
					'unitMigrationChangedUnitsThisRun' => max(0, (int) ($unit_migration['changedUnits'] ?? 0)),
					'unitMigrationComplete'        => !empty($unit_migration['complete']),
					'onDemandAffectedPagesReady'   => count($affected_page_purge_ready_urls),
					'onDemandAffectedPagesPurged'  => $affected_page_purged,
					'complete'                     => !empty($status['isComplete']) && !empty($status['unitIsComplete']),
					'pauseReason'                  => $pause_reason,
				),
				$status
			);
		}


		public function retry_failed_media_queue_items($format = 'best') {
			if (!$this->ensure_media_queue_table()) {
				return array('success' => false, 'message' => __('Media queue table unavailable.', 'ultracache'));
			}

			$format = $this->normalize_media_queue_format($format);
			$lock_token = $this->acquire_media_queue_process_lock('retry_failed');
			if ('' === $lock_token) {
				return array_merge(
					array(
						'success' => false,
						'busy' => true,
						'message' => __('A media worker is active. Retry Failed can run after the current image finishes.', 'ultracache'),
					),
					$this->get_media_queue_status($format)
				);
			}

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$now = current_time('mysql');
			$recovered_interrupted = false;
			$retried_failed = false;
			$retried_units = 0;
			try {
				// Owning the exclusive process lease proves that these processing rows
				// no longer belong to a live worker. Recover them immediately instead
				// of waiting for the stale TTL.
				$recovered_interrupted = $wpdb->query(
					$wpdb->prepare(
						"UPDATE %i SET status = 'pending', consecutive_failures = 0, stale_recoveries = 0, last_error = CASE WHEN last_error LIKE %s THEN last_error ELSE '' END, updated_at = %s, started_at = NULL, completed_at = NULL WHERE format = %s AND status = 'processing'",
						$table,
						$wpdb->esc_like('__ultracache_force_regenerate__:') . '%',
						$now,
						$format
					)
				);
				$retried_failed = $wpdb->query(
					$wpdb->prepare(
						"UPDATE %i SET status = 'pending', consecutive_failures = 0, stale_recoveries = 0, last_error = CASE WHEN last_error LIKE %s THEN last_error ELSE '' END, updated_at = %s, started_at = NULL, completed_at = NULL WHERE format = %s AND status = 'failed'",
						$table,
						$wpdb->esc_like('__ultracache_force_regenerate__:') . '%',
						$now,
						$format
					)
				);
				$retried_units = $this->retry_media_queue_units_for_parent_format($format);
			} finally {
				$this->release_media_queue_process_lock($lock_token);
			}

			$query_failed = false === $recovered_interrupted || false === $retried_failed;
			$recovered_interrupted = is_numeric($recovered_interrupted) ? max(0, (int) $recovered_interrupted) : 0;
			$retried_failed = is_numeric($retried_failed) ? max(0, (int) $retried_failed) : 0;
			$retried = $recovered_interrupted + $retried_failed;
			if (($retried + $retried_units) > 0) {
				$this->clear_media_stale_worker_state();
				$this->invalidate_media_work_summary_cache();
				if ($this->is_background_media_queue_enabled()) {
					$this->queue_background_generation_dispatch('retry_failed');
					$this->schedule_background_generation_queue(15);
				}
			}

			$status = $this->get_media_queue_status($format);
			return array_merge(
				array(
					'success' => !$query_failed,
					'message' => $query_failed ? __('UltraCache could not update every retryable media queue row.', 'ultracache') : '',
					'retried' => $retried,
					'retriedUnits' => $retried_units,
					'retriedFailed' => $retried_failed,
					'recoveredInterrupted' => $recovered_interrupted,
					'hasMore' => !empty($status['failed']) || !empty($status['recoverableInterrupted']),
				),
				$status
			);
		}


		public function requeue_completed_media_queue_items_for_regeneration($format = 'best') {
			if (!$this->ensure_media_queue_table() || !$this->ensure_media_queue_units_table()) {
				return array('success' => false, 'message' => __('Media queue storage unavailable.', 'ultracache'));
			}
			global $wpdb;
			$parent_table = $this->get_media_queue_table_name();
			$units_table = $this->get_media_queue_units_table_name();
			$format = $this->normalize_media_queue_format($format);
			$limit = 1000;
			$parent_ids = array_map(
				'absint',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT id FROM %i WHERE source_kind = 'attachment' AND format = %s AND status IN ('done','skipped') ORDER BY id ASC LIMIT %d",
						$parent_table,
						$format,
						$limit
					)
				)
			);
			$count = 0;
			$unit_count = 0;
			if (!empty($parent_ids)) {
				$now = current_time('mysql');
				foreach (array_chunk($parent_ids, 20) as $chunk) {
					$chunk = array_pad($chunk, 20, 0);
					$updated_units = $wpdb->query(
						$wpdb->prepare(
							"UPDATE %i SET status = 'pending', consecutive_failures = 0, stale_recoveries = 0, failure_code = '', failure_stage = '', failure_detail = '', resolution_code = '', resolution_detail = '', resolution_context = '', encoder_attempts = '', updated_at = %s, started_at = NULL, completed_at = NULL WHERE parent_queue_id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d) AND status <> 'superseded'",
							$units_table,
							$now,
							$chunk[0],
							$chunk[1],
							$chunk[2],
							$chunk[3],
							$chunk[4],
							$chunk[5],
							$chunk[6],
							$chunk[7],
							$chunk[8],
							$chunk[9],
							$chunk[10],
							$chunk[11],
							$chunk[12],
							$chunk[13],
							$chunk[14],
							$chunk[15],
							$chunk[16],
							$chunk[17],
							$chunk[18],
							$chunk[19]
						)
					);
					if (false === $updated_units) {
						$unit_count = false;
						break;
					}
					$unit_count += (int) $updated_units;

					$updated_parents = $wpdb->query(
						$wpdb->prepare(
							"UPDATE %i SET status = 'pending', attempts = 0, consecutive_failures = 0, stale_recoveries = 0, last_error = %s, updated_at = %s, started_at = NULL, completed_at = NULL WHERE id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)",
							$parent_table,
							$this->get_media_queue_force_regenerate_marker(0),
							$now,
							$chunk[0],
							$chunk[1],
							$chunk[2],
							$chunk[3],
							$chunk[4],
							$chunk[5],
							$chunk[6],
							$chunk[7],
							$chunk[8],
							$chunk[9],
							$chunk[10],
							$chunk[11],
							$chunk[12],
							$chunk[13],
							$chunk[14],
							$chunk[15],
							$chunk[16],
							$chunk[17],
							$chunk[18],
							$chunk[19]
						)
					);
					if (false === $updated_parents) {
						$count = false;
						break;
					}
					$count += (int) $updated_parents;
				}
			}
			$this->invalidate_media_work_summary_cache();
			$status = $this->get_media_queue_status($format);
			return array_merge(
				array(
					'success' => false !== $count && false !== $unit_count,
					'requeued' => is_numeric($count) ? (int) $count : 0,
					'requeuedUnits' => is_numeric($unit_count) ? (int) $unit_count : 0,
					'hasMore' => ((int) ($status['done'] ?? 0) + (int) ($status['skipped'] ?? 0)) > 0,
				),
				$status
			);
		}


		public function clear_completed_media_queue_items($format = 'best') {
			if (!$this->ensure_media_queue_table()) {
				return array('success' => false, 'message' => __('Media queue table unavailable.', 'ultracache'));
			}
			global $wpdb;
			$table = $this->get_media_queue_table_name();
			$format = $this->normalize_media_queue_format($format);
			$limit = 1000;
			$parent_ids = array_map(
				'absint',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT id FROM %i WHERE format = %s AND status IN ('done','skipped') ORDER BY id ASC LIMIT %d",
						$table,
						$format,
						$limit
					)
				)
			);
			$this->delete_media_queue_units_for_parent_ids($parent_ids);
			$count = 0;
			if (!empty($parent_ids)) {
				foreach (array_chunk($parent_ids, 20) as $chunk) {
					$chunk = array_pad($chunk, 20, 0);
					$deleted = $wpdb->query(
						$wpdb->prepare(
							'DELETE FROM %i WHERE id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)',
							$table,
							$chunk[0],
							$chunk[1],
							$chunk[2],
							$chunk[3],
							$chunk[4],
							$chunk[5],
							$chunk[6],
							$chunk[7],
							$chunk[8],
							$chunk[9],
							$chunk[10],
							$chunk[11],
							$chunk[12],
							$chunk[13],
							$chunk[14],
							$chunk[15],
							$chunk[16],
							$chunk[17],
							$chunk[18],
							$chunk[19]
						)
					);
					if (false === $deleted) {
						$count = false;
						break;
					}
					$count += (int) $deleted;
				}
			}
			$status = $this->get_media_queue_status($format);
			return array_merge(array('success' => true, 'cleared' => is_numeric($count) ? (int) $count : 0, 'hasMore' => ((int) ($status['done'] ?? 0) + (int) ($status['skipped'] ?? 0)) > 0), $status);
		}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
