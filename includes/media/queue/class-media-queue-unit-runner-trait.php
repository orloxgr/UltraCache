<?php
/**
 * UltraCache authoritative physical media conversion unit worker.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Queue_Unit_Runner_Trait
{
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache uses private authoritative media queue tables with validated identifiers.

	/**
	 * Return active physical units for one attachment parent row.
	 *
	 * @param int $parent_queue_id Parent queue row ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_active_media_queue_units_for_parent($parent_queue_id) {
		$units = array();
		foreach ($this->get_media_queue_units_for_parent($parent_queue_id) as $unit) {
			if ('superseded' === (string) ($unit['status'] ?? '')) {
				continue;
			}
			$units[] = $unit;
		}
		return $units;
	}


	/**
	 * Re-derive recovered attachment parents from their persisted child units.
	 *
	 * Recovery, pause, retry, and cooperative handoff operations may update the
	 * exact child and parent claims in separate guarded statements. Reconcile the
	 * affected parents immediately so every execution surface observes the same
	 * aggregate state instead of a temporary attachment-level approximation.
	 *
	 * @param array<int,int|string> $parent_ids Parent queue row IDs.
	 * @return int Number of parents reconciled successfully.
	 */
	private function reconcile_media_queue_parent_ids_after_unit_recovery(array $parent_ids) {
		$parent_ids = array_values(array_filter(array_unique(array_map('absint', $parent_ids))));
		if (empty($parent_ids)) {
			return 0;
		}

		$reconciled = 0;
		foreach ($parent_ids as $parent_id) {
			$result = $this->reconcile_media_queue_units_for_parent($parent_id);
			if (!empty($result['success'])) {
				$reconciled++;
			}
		}
		return $reconciled;
	}

	/**
	 * Start one explicit regeneration generation without rebuilding parent rows.
	 *
	 * The parent marker prevents every subsequent UI/CLI/background chunk from
	 * resetting units that were already regenerated during the same operation.
	 *
	 * @param int $parent_queue_id Parent queue row ID.
	 * @return bool
	 */
	private function begin_media_queue_unit_regeneration($parent_queue_id) {
		$parent_queue_id = absint($parent_queue_id);
		if ($parent_queue_id <= 0 || !$this->ensure_media_queue_units_table()) {
			return false;
		}

		global $wpdb;
		$units_table = $this->get_media_queue_units_table_name();
		$parent_table = $this->get_media_queue_table_name();
		$now = current_time('mysql');
		$units_updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'pending', consecutive_failures = 0, stale_recoveries = 0, failure_code = '', failure_stage = '', failure_detail = '', resolution_code = '', resolution_detail = '', resolution_context = '', encoder_attempts = '', updated_at = %s, started_at = NULL, completed_at = NULL WHERE parent_queue_id = %d AND status <> 'superseded'",
				$units_table,
				$now,
				$parent_queue_id
			)
		);
		if (false === $units_updated) {
			return false;
		}

		$parent_updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'pending', consecutive_failures = 0, stale_recoveries = 0, last_error = %s, updated_at = %s, started_at = NULL, completed_at = NULL WHERE id = %d AND source_kind = 'attachment'",
				$parent_table,
				$this->get_media_queue_force_regenerate_marker(0),
				$now,
				$parent_queue_id
			)
		);
		if (false === $parent_updated) {
			return false;
		}

		$this->invalidate_media_work_summary_cache();
		return true;
	}

	/**
	 * Return the next pending physical unit for one parent.
	 *
	 * @param int $parent_queue_id Parent queue row ID.
	 * @return array<string,mixed>
	 */
	private function get_next_pending_media_queue_unit($parent_queue_id) {
		$parent_queue_id = absint($parent_queue_id);
		if ($parent_queue_id <= 0 || !$this->ensure_media_queue_units_table()) {
			return array();
		}

		global $wpdb;
		$table = $this->get_media_queue_units_table_name();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE parent_queue_id = %d AND status = 'pending' ORDER BY attempts ASC, id ASC LIMIT 1",
				$table,
				$parent_queue_id
			),
			ARRAY_A
		);
		return is_array($row) ? $row : array();
	}

	/**
	 * Atomically claim one physical unit.
	 *
	 * @param array<string,mixed> $unit Pending unit row.
	 * @return array<string,mixed>
	 */
	private function claim_media_queue_unit(array $unit) {
		$unit_id = absint($unit['id'] ?? 0);
		if ($unit_id <= 0 || !$this->ensure_media_queue_units_table()) {
			return array();
		}

		global $wpdb;
		$table = $this->get_media_queue_units_table_name();
		$started_at = current_time('mysql');
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'processing', attempts = attempts + 1, started_at = %s, updated_at = %s, completed_at = NULL WHERE id = %d AND status = 'pending'",
				$table,
				$started_at,
				$started_at,
				$unit_id
			)
		);
		if (1 !== (int) $claimed) {
			return array();
		}

		$unit['status'] = 'processing';
		$unit['attempts'] = max(0, (int) ($unit['attempts'] ?? 0)) + 1;
		$unit['started_at'] = $started_at;
		$unit['updated_at'] = $started_at;
		return $unit;
	}

	/**
	 * Convert or semantically resolve one claimed physical unit.
	 *
	 * @param array<string,mixed> $unit         Claimed unit row.
	 * @param bool                $only_missing Skip an already valid output.
	 * @return array<string,mixed>
	 */
	private function execute_claimed_media_queue_unit(array $unit, $only_missing) {
		$source_file = $this->get_media_queue_unit_source_path($unit);
		$target_file = $this->get_media_queue_unit_target_path($unit);
		$output_format = strtolower((string) ($unit['output_format'] ?? ''));
		$result = array(
			'success'              => false,
			'converted'            => false,
			'unitStatus'           => 'pending',
			'unitId'               => absint($unit['id'] ?? 0),
			'unitIdentity'         => (string) ($unit['unit_identity'] ?? ''),
			'itemScope'            => (string) ($unit['item_scope'] ?? ''),
			'sizeName'             => (string) ($unit['size_name'] ?? ''),
			'sourceFile'           => wp_basename($source_file),
			'sourcePath'           => $source_file,
			'targetPath'           => $target_file,
			'requestedFormat'      => $output_format,
			'attemptedFormat'      => $output_format,
			'workCompletedThisRun' => 1,
			'avif'                 => 0,
			'webp'                 => 0,
		);

		if ('' === $source_file || '' === $target_file || !in_array($output_format, array('avif', 'webp'), true)) {
			$result['success'] = true;
			$result['unitStatus'] = 'skipped';
			$result['skippedReason'] = 'unsupported_source_or_target_mapping';
			$result['skipDetail'] = __('The physical source or generated target mapping is not supported.', 'ultracache');
			return $result;
		}

		$inspection = $this->inspect_media_queue_unit_filesystem_state($unit);
		if ($only_missing && 'done' === (string) ($inspection['status'] ?? '')) {
			$result['success'] = true;
			$result['unitStatus'] = 'done';
			$result['alreadyOptimized'] = true;
			$result['skippedReason'] = 'already_optimized';
			return $result;
		}
		if ('skipped' === (string) ($inspection['status'] ?? '')) {
			$result['success'] = true;
			$result['unitStatus'] = 'skipped';
			$result['skippedReason'] = (string) ($inspection['reason'] ?? 'source_missing_or_unreadable');
			$result['skipDetail'] = $this->get_media_conversion_skip_detail((string) $result['skippedReason']);
			return $result;
		}

		$semantic_skip_reason = $this->get_media_source_conversion_skip_reason($source_file, $output_format);
		if ('' !== $semantic_skip_reason) {
			$result['success'] = true;
			$result['unitStatus'] = 'skipped';
			$result['skippedReason'] = $semantic_skip_reason;
			$result['skipDetail'] = $this->get_media_conversion_skip_detail($semantic_skip_reason);
			return $result;
		}

		$conversion_unit = array(
			'source_file' => $source_file,
			'format'      => $output_format,
		);
		if (!$this->is_attachment_conversion_unit_supported($conversion_unit)) {
			$result['success'] = true;
			$result['unitStatus'] = 'skipped';
			$result['skippedReason'] = 'encoder_unavailable';
			$result['skipDetail'] = __('The required image encoder is not available for this physical file.', 'ultracache');
			return $result;
		}

		$generated = 'avif' === $output_format
			? $this->to_avif($source_file)
			: $this->to_webp($source_file);
		if (!$generated) {
			$failure = $this->get_last_media_conversion_failure();
			$skip_reason = (string) ($failure['skippedReason'] ?? '');
			if ('' !== $skip_reason) {
				$result['success'] = true;
				$result['unitStatus'] = 'skipped';
				$result['skippedReason'] = $skip_reason;
				$result['skipDetail'] = (string) ($failure['skipDetail'] ?? $this->get_media_conversion_skip_detail($skip_reason));
				return $result;
			}

			$result['failureCode'] = (string) ($failure['failureCode'] ?? 'conversion_failed');
			$result['failureStage'] = (string) ($failure['failureStage'] ?? 'encode');
			$result['failureDetail'] = (string) ($failure['failureDetail'] ?? __('The physical image conversion failed.', 'ultracache'));
			$result['encoderAttempts'] = is_array($failure['encoderAttempts'] ?? null) ? $failure['encoderAttempts'] : array();
			$result['message'] = __('The physical image conversion unit could not be generated.', 'ultracache');
			return $result;
		}

		clearstatcache(true, $target_file);
		$verification = $this->inspect_media_queue_unit_filesystem_state($unit);
		if ('done' !== (string) ($verification['status'] ?? '')) {
			$result['failureCode'] = 'generated_output_verification_failed';
			$result['failureStage'] = 'verify';
			$result['failureDetail'] = sprintf(
				/* translators: %s: exact output verification reason. */
				__('The encoder returned success but the exact generated output did not pass verification: %s', 'ultracache'),
				(string) ($verification['reason'] ?? 'target_missing')
			);
			$result['message'] = __('The exact generated image output could not be verified.', 'ultracache');
			return $result;
		}

		$result['success'] = true;
		$result['converted'] = true;
		$result['unitStatus'] = 'done';
		$result['generatedPath'] = (string) $generated;
		$result['generatedFormat'] = $output_format;
		$result[$output_format] = 1;
		return $result;
	}

	/**
	 * Persist a claimed physical-unit result with exact claim guards.
	 *
	 * @param array<string,mixed> $unit   Claimed unit row.
	 * @param array<string,mixed> $result Conversion result.
	 * @return array<string,mixed>
	 */
	private function persist_claimed_media_queue_unit_result(array $unit, array $result) {
		$unit_id = absint($unit['id'] ?? 0);
		$attachment_id = absint($unit['attachment_id'] ?? 0);
		$output_format = strtolower((string) ($unit['output_format'] ?? ''));
		$claim_attempt = max(0, (int) ($unit['attempts'] ?? 0));
		$claim_started = (string) ($unit['started_at'] ?? '');
		if ($unit_id <= 0 || $claim_attempt <= 0 || '' === $claim_started) {
			return array('success' => false, 'error' => 'media_queue_unit_claim_invalid');
		}

		$failure_count = max(0, (int) ($unit['consecutive_failures'] ?? 0)) + 1;
		$max_failures = max(1, (int) apply_filters('ultracache_media_queue_max_consecutive_failures', 3, $attachment_id, $output_format));
		$successful = !empty($result['success']);
		$status = (string) ($result['unitStatus'] ?? '');
		if ($successful) {
			$status = in_array($status, array('done', 'skipped'), true) ? $status : 'done';
		} else {
			$status = $failure_count >= $max_failures ? 'failed' : 'pending';
		}

		$source_path = $this->get_media_queue_unit_source_path($unit);
		$fingerprint = $this->get_optimized_source_fingerprint($source_path, true);
		$now = current_time('mysql');
		$terminal = in_array($status, array('done', 'skipped', 'failed'), true);
		$encoder_attempts = '';
		$resolution_code = '';
		$resolution_detail = '';
		$resolution_context = '';
		if ($successful && 'skipped' === $status) {
			$resolution_code = sanitize_key((string) ($result['skippedReason'] ?? 'semantic_skip'));
			$resolution_detail = (string) ($result['skipDetail'] ?? $this->get_media_conversion_skip_detail($resolution_code));
			$resolution_context = $this->get_media_queue_unit_resolution_context($unit);
		}
		if (!$successful && !empty($result['encoderAttempts'])) {
			$encoded = wp_json_encode($result['encoderAttempts'], JSON_UNESCAPED_SLASHES);
			$encoder_attempts = is_string($encoded) ? $encoded : '';
		}

		global $wpdb;
		$table = $this->get_media_queue_units_table_name();
		$persisted = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET source_mtime = %d, source_size = %d, status = %s, consecutive_failures = %d, failure_code = %s, failure_stage = %s, failure_detail = %s, resolution_code = %s, resolution_detail = %s, resolution_context = %s, encoder_attempts = %s, updated_at = %s, started_at = NULL, completed_at = NULLIF(%s, '') WHERE id = %d AND status = 'processing' AND attempts = %d AND started_at = %s",
				$table,
				max(0, (int) ($fingerprint['mtime'] ?? ($unit['source_mtime'] ?? 0))),
				max(0, (int) ($fingerprint['size'] ?? ($unit['source_size'] ?? 0))),
				$status,
				$successful ? 0 : $failure_count,
				$successful ? '' : (string) ($result['failureCode'] ?? 'conversion_failed'),
				$successful ? '' : (string) ($result['failureStage'] ?? 'encode'),
				$successful ? '' : (string) ($result['failureDetail'] ?? $result['message'] ?? __('Physical media conversion failed.', 'ultracache')),
				$resolution_code,
				$resolution_detail,
				$resolution_context,
				$successful ? '' : $encoder_attempts,
				$now,
				$terminal ? $now : '',
				$unit_id,
				$claim_attempt,
				$claim_started
			)
		);
		if (1 !== (int) $persisted) {
			return array('success' => false, 'error' => 'media_queue_unit_stale_claim');
		}

		return array(
			'success'      => true,
			'status'       => $status,
			'failureCount' => $successful ? 0 : $failure_count,
			'failureLimit' => $max_failures,
			'terminal'     => $terminal,
		);
	}

	/**
	 * Return the most useful persisted child failure for parent/UI compatibility.
	 *
	 * @param array<int,array<string,mixed>> $units Active unit rows.
	 * @return array<string,mixed>
	 */
	private function get_media_queue_unit_parent_failure(array $units) {
		$fallback = array();
		foreach ($units as $unit) {
			if (!in_array((string) ($unit['status'] ?? ''), array('failed', 'pending'), true)) {
				continue;
			}
			if ('' === (string) ($unit['failure_code'] ?? '') && '' === (string) ($unit['failure_detail'] ?? '')) {
				continue;
			}
			$payload = array(
				'unitId'       => absint($unit['id'] ?? 0),
				'itemScope'    => (string) ($unit['item_scope'] ?? ''),
				'sizeName'     => (string) ($unit['size_name'] ?? ''),
				'sourceFile'   => wp_basename((string) ($unit['source_relative_path'] ?? '')),
				'failureCode'  => (string) ($unit['failure_code'] ?? ''),
				'failureStage' => (string) ($unit['failure_stage'] ?? ''),
				'failureDetail'=> (string) ($unit['failure_detail'] ?? ''),
				'attempts'     => max(0, (int) ($unit['attempts'] ?? 0)),
			);
			if ('failed' === (string) ($unit['status'] ?? '')) {
				return $payload;
			}
			if (empty($fallback)) {
				$fallback = $payload;
			}
		}
		return $fallback;
	}

	/**
	 * Finalize a claimed parent strictly from persisted active child states.
	 *
	 * @param array<string,mixed> $parent          Claimed parent row.
	 * @param bool                $force_generation Whether regeneration marker must remain while pending.
	 * @return array<string,mixed>
	 */
	private function finalize_claimed_media_queue_parent(array $parent, $force_generation) {
		$parent_id = absint($parent['id'] ?? 0);
		$claim_attempt = max(0, (int) ($parent['attempts'] ?? 0));
		$claim_started = (string) ($parent['started_at'] ?? '');
		if ($parent_id <= 0 || $claim_attempt <= 0 || '' === $claim_started) {
			return array('success' => false, 'error' => 'media_queue_parent_claim_invalid');
		}

		$units = $this->get_active_media_queue_units_for_parent($parent_id);
		if (empty($units)) {
			return array('success' => false, 'error' => 'media_queue_unit_inventory_unavailable');
		}
		$state = $this->derive_media_queue_parent_state_from_units($units);
		$status = (string) ($state['status'] ?? 'pending');
		if ('processing' === $status) {
			$status = 'pending';
		}
		$failure = $this->get_media_queue_unit_parent_failure($units);
		$last_error = '';
		if ('failed' === $status) {
			$failure_detail = (string) ($failure['failureDetail'] ?? $failure['failureCode'] ?? 'A physical media conversion unit failed.');
			$last_error = $force_generation
				? $this->get_media_queue_force_regenerate_marker(0) . "\n" . $failure_detail
				: $failure_detail;
		} elseif ('pending' === $status) {
			$last_error = $force_generation
				? $this->get_media_queue_force_regenerate_marker(0)
				: (string) ($failure['failureDetail'] ?? '');
		}

		$consecutive_failures = 0;
		$stale_recoveries = 0;
		foreach ($units as $unit) {
			$consecutive_failures = max($consecutive_failures, max(0, (int) ($unit['consecutive_failures'] ?? 0)));
			$stale_recoveries = max($stale_recoveries, max(0, (int) ($unit['stale_recoveries'] ?? 0)));
		}
		if (in_array($status, array('done', 'skipped'), true)) {
			$consecutive_failures = 0;
			$stale_recoveries = 0;
		}

		$now = current_time('mysql');
		$terminal = in_array($status, array('done', 'skipped', 'failed'), true);
		global $wpdb;
		$table = $this->get_media_queue_table_name();
		$persisted = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, consecutive_failures = %d, stale_recoveries = %d, last_error = %s, updated_at = %s, started_at = NULL, completed_at = NULLIF(%s, '') WHERE id = %d AND status = 'processing' AND attempts = %d AND started_at = %s",
				$table,
				$status,
				$consecutive_failures,
				$stale_recoveries,
				$last_error,
				$now,
				$terminal ? $now : '',
				$parent_id,
				$claim_attempt,
				$claim_started
			)
		);
		if (1 !== (int) $persisted) {
			return array('success' => false, 'error' => 'media_queue_parent_stale_claim');
		}

		$this->invalidate_media_work_summary_cache();
		return array(
			'success'      => true,
			'parentStatus' => $status,
			'counts'       => (array) ($state['counts'] ?? array()),
			'activeTotal'  => max(0, (int) ($state['activeTotal'] ?? count($units))),
			'failure'      => $failure,
		);
	}

	/**
	 * Unified attachment worker used by dashboard, REST, WP-CLI, readiness, and cron.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $format        Parent output policy.
	 * @param bool   $only_missing  Whether valid outputs remain untouched.
	 * @param string $lock_token    Optional caller-owned process lease.
	 * @param string $manual_session_token Dashboard session token.
	 * @param bool   $force_regenerate_existing Explicit regeneration request.
	 * @return array<string,mixed>
	 */
	private function process_queued_attachment_unit_worker($attachment_id, $format = 'best', $only_missing = true, $lock_token = '', $manual_session_token = '', $force_regenerate_existing = false) {
		if ($this->is_media_background_work_paused()) {
			return array('success' => false, 'paused' => true, 'reason' => 'background_paused', 'queueStatus' => 'paused', 'message' => __('Media generation is paused by an administrator.', 'ultracache'));
		}
		if (method_exists($this, 'is_media_stale_worker_cooldown_active') && $this->is_media_stale_worker_cooldown_active()) {
			return array('success' => false, 'paused' => true, 'reason' => 'stale_worker_cooldown', 'queueStatus' => 'cooldown', 'message' => __('Media conversion is cooling down after a stale worker was quarantined.', 'ultracache'));
		}
		if ($this->is_manual_media_conversion_active()) {
			if (!$this->owns_manual_media_conversion_session($manual_session_token)) {
				return array('success' => false, 'paused' => true, 'reason' => 'manual_session_active', 'queueStatus' => 'locked', 'message' => __('Dashboard media conversion currently has exclusive queue ownership.', 'ultracache'));
			}
			$renewed = $this->renew_manual_media_conversion_session($manual_session_token);
			if (empty($renewed['success'])) {
				return array('success' => false, 'paused' => true, 'reason' => 'manual_session_lost', 'queueStatus' => 'locked', 'message' => __('The dashboard media-conversion session expired or changed owner.', 'ultracache'));
			}
		}

		$attachment_id = absint($attachment_id);
		$format = $this->normalize_media_queue_format($format);
		$only_missing = (bool) $only_missing;
		$force_regenerate_existing = (bool) $force_regenerate_existing || !$only_missing;
		$owns_lock = false;
		if ($attachment_id <= 0) {
			return array('success' => false, 'attachment_id' => 0, 'message' => __('Invalid attachment ID.', 'ultracache'));
		}
		if ('' === (string) $lock_token) {
			$lock_token = $this->acquire_media_queue_process_lock('attachment_unit');
			$owns_lock = '' !== $lock_token;
		}
		if (!$this->renew_media_queue_process_lock($lock_token, 'attachment_unit')) {
			if ($owns_lock) {
				$this->release_media_queue_process_lock($lock_token);
			}
			return array('success' => false, 'paused' => true, 'reason' => 'locked', 'queueStatus' => 'locked', 'attachment_id' => $attachment_id, 'message' => __('Another media conversion is already running.', 'ultracache'));
		}

		try {
			if (!$this->ensure_media_queue_table() || !$this->ensure_media_queue_units_table()) {
				return array('success' => false, 'attachment_id' => $attachment_id, 'message' => __('Media queue storage is unavailable.', 'ultracache'));
			}
			$this->reset_stale_media_queue_items($lock_token);

			global $wpdb;
			$parent_table = $this->get_media_queue_table_name();
			$parent = $this->get_media_queue_unit_parent_row_for_attachment($attachment_id, $format);
			if (empty($parent)) {
				$this->upsert_media_queue_item($attachment_id, $format, 'pending', '', 0);
				$parent = $this->get_media_queue_unit_parent_row_for_attachment($attachment_id, $format);
			}
			if (empty($parent)) {
				return array('success' => false, 'attachment_id' => $attachment_id, 'message' => __('Queue row unavailable.', 'ultracache'));
			}

			$reconciled = $this->reconcile_media_queue_units_for_parent((int) $parent['id']);
			if (empty($reconciled['success'])) {
				return array('success' => false, 'attachment_id' => $attachment_id, 'message' => __('Physical media unit reconciliation failed.', 'ultracache'), 'failureCode' => (string) ($reconciled['error'] ?? 'media_queue_unit_reconciliation_failed'));
			}
			$parent = $this->get_media_queue_unit_parent_row((int) $parent['id']);
			if (empty($parent)) {
				return array('success' => false, 'attachment_id' => $attachment_id, 'message' => __('Queue row unavailable after physical media reconciliation.', 'ultracache'));
			}

			$regeneration_marker = $this->parse_media_queue_force_regenerate_marker($parent['last_error'] ?? '');
			if ($force_regenerate_existing && null === $regeneration_marker) {
				if (!$this->begin_media_queue_unit_regeneration((int) $parent['id'])) {
					return array('success' => false, 'attachment_id' => $attachment_id, 'message' => __('Physical media regeneration could not be initialized.', 'ultracache'));
				}
				$parent = $this->get_media_queue_unit_parent_row((int) $parent['id']);
				$regeneration_marker = 0;
			}
			$force_generation_active = null !== $regeneration_marker;

			if (in_array((string) ($parent['status'] ?? ''), array('done', 'skipped'), true) && !$force_generation_active) {
				$result = array(
					'success' => true,
					'attachment_id' => $attachment_id,
					'converted' => false,
					'complete' => true,
					'workCompletedThisRun' => 0,
					'queueStatus' => (string) $parent['status'],
					'alreadyOptimized' => true,
					'skippedReason' => 'already_optimized',
				);
				$result['onDemandAffectedPagePurgeReadyUrls'] = $this->mark_on_demand_affected_media_processed($attachment_id, $format, $result);
				return $result;
			}
			if ('failed' === (string) ($parent['status'] ?? '')) {
				$failure = $this->get_media_queue_unit_parent_failure($this->get_active_media_queue_units_for_parent((int) $parent['id']));
				return array_merge(
					array(
						'success' => false,
						'attachment_id' => $attachment_id,
						'converted' => false,
						'complete' => false,
						'queueStatus' => 'failed',
						'reason' => 'retry_limit',
						'message' => __('A physical media conversion unit must be retried before this attachment can continue.', 'ultracache'),
					),
					$failure
				);
			}

			$parent_started = current_time('mysql');
			$parent_attempt = max(0, (int) ($parent['attempts'] ?? 0)) + 1;
			$parent_claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = 'processing', attempts = attempts + 1, started_at = %s, updated_at = %s, completed_at = NULL WHERE id = %d AND status = 'pending'",
					$parent_table,
					$parent_started,
					$parent_started,
					(int) $parent['id']
				)
			);
			if (1 !== (int) $parent_claimed) {
				return array('success' => false, 'paused' => true, 'reason' => 'already_claimed', 'queueStatus' => 'processing', 'attachment_id' => $attachment_id, 'message' => __('This media item is already being processed.', 'ultracache'));
			}
			$parent['status'] = 'processing';
			$parent['attempts'] = $parent_attempt;
			$parent['started_at'] = $parent_started;

			$unit = $this->get_next_pending_media_queue_unit((int) $parent['id']);
			if (empty($unit)) {
				$finalized = $this->finalize_claimed_media_queue_parent($parent, $force_generation_active);
				if (empty($finalized['success'])) {
					return array('success' => false, 'paused' => true, 'reason' => 'stale_claim', 'queueStatus' => 'failed', 'attachment_id' => $attachment_id, 'message' => __('The parent queue state could not be finalized.', 'ultracache'));
				}
				$status = (string) ($finalized['parentStatus'] ?? 'pending');
				return array(
					'success' => 'failed' !== $status,
					'attachment_id' => $attachment_id,
					'converted' => false,
					'complete' => in_array($status, array('done', 'skipped'), true),
					'queueStatus' => $status,
					'workCompletedThisRun' => 0,
					'onDemandAffectedPagePurgeReadyUrls' => array(),
				);
			}
			$unit = $this->claim_media_queue_unit($unit);
			if (empty($unit)) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE %i SET status = 'pending', updated_at = %s, started_at = NULL, completed_at = NULL WHERE id = %d AND status = 'processing' AND attempts = %d AND started_at = %s",
						$parent_table,
						current_time('mysql'),
						(int) $parent['id'],
						$parent_attempt,
						$parent_started
					)
				);
				return array('success' => false, 'paused' => true, 'reason' => 'unit_already_claimed', 'queueStatus' => 'processing', 'attachment_id' => $attachment_id, 'message' => __('The next physical media unit is already being processed.', 'ultracache'));
			}

			$this->arm_media_queue_shutdown_claim(
				(int) $parent['id'],
				$parent_attempt,
				$parent_started,
				(int) $unit['id'],
				(int) $unit['attempts'],
				(string) $unit['started_at']
			);
			try {
				if (!$this->renew_media_queue_process_lock($lock_token, 'attachment_unit')) {
					return array('success' => false, 'paused' => true, 'reason' => 'lease_lost', 'queueStatus' => 'processing', 'attachment_id' => $attachment_id, 'message' => __('The media worker lost its processing lease before conversion.', 'ultracache'));
				}

				$unit_result = $this->execute_claimed_media_queue_unit($unit, $only_missing && !$force_generation_active);
				if (!$this->renew_media_queue_process_lock($lock_token, 'attachment_unit')) {
					return array_merge($unit_result, array('success' => false, 'paused' => true, 'reason' => 'lease_lost', 'queueStatus' => 'processing', 'attachment_id' => $attachment_id, 'message' => __('The media worker lost its processing lease before the unit result could be saved.', 'ultracache')));
				}

				$unit_persisted = $this->persist_claimed_media_queue_unit_result($unit, $unit_result);
				if (empty($unit_persisted['success'])) {
					return array_merge($unit_result, array('success' => false, 'paused' => true, 'reason' => 'stale_claim', 'queueStatus' => 'failed', 'attachment_id' => $attachment_id, 'message' => __('The physical media unit claim changed before its result could be saved.', 'ultracache')));
				}
				$unit_result['unitStatus'] = (string) ($unit_persisted['status'] ?? $unit_result['unitStatus'] ?? 'pending');
				$unit_result['failureAttempt'] = (int) ($unit_persisted['failureCount'] ?? 0);
				$unit_result['failureLimit'] = (int) ($unit_persisted['failureLimit'] ?? 0);

				$finalized = $this->finalize_claimed_media_queue_parent($parent, $force_generation_active);
				if (empty($finalized['success'])) {
					return array_merge($unit_result, array('success' => false, 'paused' => true, 'reason' => 'stale_claim', 'queueStatus' => 'failed', 'attachment_id' => $attachment_id, 'message' => __('The attachment parent claim changed before child aggregation could be saved.', 'ultracache')));
				}

				$this->clear_media_queue_shutdown_claim((int) $parent['id'], $parent_attempt, $parent_started);
				$status = (string) ($finalized['parentStatus'] ?? 'pending');
				$counts = (array) ($finalized['counts'] ?? array());
				$unit_result['attachment_id'] = $attachment_id;
				$unit_result['queueStatus'] = $status;
				$unit_result['complete'] = in_array($status, array('done', 'skipped'), true);
				$unit_result['workTotal'] = max(0, (int) ($finalized['activeTotal'] ?? array_sum($counts)));
				$unit_result['workCompleted'] = max(0, (int) ($counts['done'] ?? 0) + (int) ($counts['skipped'] ?? 0));
				$unit_result['remainingUnits'] = max(0, (int) ($counts['pending'] ?? 0) + (int) ($counts['processing'] ?? 0) + (int) ($counts['failed'] ?? 0));
				$unit_result['physicalUnitAggregation'] = $finalized;
				if ('failed' === $status) {
					$unit_result['success'] = false;
					$unit_result['reason'] = 'retry_limit';
				} elseif (empty($unit_result['success'])) {
					$unit_result['reason'] = 'conversion_failed';
				}
				$unit_result['onDemandAffectedPagePurgeReadyUrls'] = in_array($status, array('done', 'skipped'), true)
					? $this->mark_on_demand_affected_media_processed($attachment_id, $format, $unit_result)
					: array();
				return $unit_result;
			} catch (Throwable $exception) {
				$exception_result = array(
					'success' => false,
					'converted' => false,
					'unitStatus' => 'pending',
					'failureCode' => 'conversion_exception',
					'failureStage' => 'exception',
					'failureDetail' => $exception->getMessage(),
					'message' => $exception->getMessage(),
					'encoderAttempts' => array(),
					'workCompletedThisRun' => 1,
					'avif' => 0,
					'webp' => 0,
				);
				$unit_persisted = $this->persist_claimed_media_queue_unit_result($unit, $exception_result);
				if (!empty($unit_persisted['success'])) {
					$this->finalize_claimed_media_queue_parent($parent, $force_generation_active);
					$this->clear_media_queue_shutdown_claim((int) $parent['id'], $parent_attempt, $parent_started);
				}
				return array_merge($exception_result, array('attachment_id' => $attachment_id, 'queueStatus' => (string) ($unit_persisted['status'] ?? 'pending'), 'reason' => 'conversion_failed', 'complete' => false, 'onDemandAffectedPagePurgeReadyUrls' => array()));
			} finally {
				$this->release_interrupted_media_queue_claim_to_pending();
			}
		} finally {
			if ($owns_lock) {
				$this->release_media_queue_process_lock($lock_token);
			}
		}
	}

	/**
	 * Reset child claims during an administrator pause.
	 *
	 * @return int
	 */
	private function reset_active_media_queue_unit_items() {
		if (!$this->media_queue_units_table_exists()) {
			return 0;
		}
		global $wpdb;
		$table = $this->get_media_queue_units_table_name();
		$parent_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT parent_queue_id FROM %i WHERE status = 'processing'",
				$table
			)
		);
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'pending', failure_code = %s, failure_stage = %s, failure_detail = %s, resolution_code = '', resolution_detail = '', resolution_context = '', encoder_attempts = '', updated_at = %s, started_at = NULL, completed_at = NULL WHERE status = 'processing'",
				$table,
				'media_generation_paused',
				'worker',
				'Paused by administrator.',
				current_time('mysql')
			)
		);
		$count = is_numeric($result) ? max(0, (int) $result) : 0;
		if ($count > 0) {
			$this->reconcile_media_queue_parent_ids_after_unit_recovery((array) $parent_ids);
		}
		return $count;
	}

	/**
	 * Recover stale physical-unit claims under the current parent process lease.
	 *
	 * @param string $lock_token Current process lease owner.
	 * @return int
	 */
	private function reset_stale_media_queue_unit_items($lock_token) {
		if (!$this->media_queue_units_table_exists() || '' === (string) $lock_token || !function_exists('ultracache_get_lock')) {
			return 0;
		}
		$lock = ultracache_get_lock(self::MEDIA_QUEUE_PROCESS_LOCK);
		if (empty($lock['token']) || !empty($lock['expired']) || !hash_equals((string) $lock['token'], (string) $lock_token)) {
			return 0;
		}

		global $wpdb;
		$table = $this->get_media_queue_units_table_name();
		$cutoff = get_date_from_gmt(gmdate('Y-m-d H:i:s', time() - self::MEDIA_QUEUE_PROCESSING_TTL));
		$lock_acquired = get_date_from_gmt(gmdate('Y-m-d H:i:s', max(0, (int) ($lock['acquiredAt'] ?? time()))));
		$now = current_time('mysql');
		$terminal_index = max(0, $this->get_media_queue_max_stale_recoveries() - 1);
		$parent_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT parent_queue_id FROM %i WHERE status = 'processing' AND updated_at < %s AND updated_at < %s",
				$table,
				$cutoff,
				$lock_acquired
			)
		);
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = CASE WHEN stale_recoveries >= %d THEN 'failed' ELSE 'pending' END, stale_recoveries = LEAST(65535, stale_recoveries + 1), failure_code = CASE WHEN stale_recoveries >= %d THEN 'media_worker_stale_recovery_limit' ELSE 'media_worker_terminated' END, failure_stage = 'worker', failure_detail = CASE WHEN stale_recoveries >= %d THEN 'The physical media unit reached the stale-worker recovery limit.' ELSE 'The physical media unit worker terminated before persistence.' END, resolution_code = '', resolution_detail = '', resolution_context = '', encoder_attempts = '', updated_at = %s, started_at = NULL, completed_at = CASE WHEN stale_recoveries >= %d THEN %s ELSE NULL END WHERE status = 'processing' AND updated_at < %s AND updated_at < %s",
				$table,
				$terminal_index,
				$terminal_index,
				$terminal_index,
				$now,
				$terminal_index,
				$now,
				$cutoff,
				$lock_acquired
			)
		);
		$count = is_numeric($result) ? max(0, (int) $result) : 0;
		if ($count > 0) {
			$this->reconcile_media_queue_parent_ids_after_unit_recovery((array) $parent_ids);
		}
		return $count;
	}

	/**
	 * Retry failed child units for parent rows using one format policy.
	 *
	 * @param string $format Parent format policy.
	 * @return int
	 */
	private function retry_media_queue_units_for_parent_format($format) {
		if (!$this->media_queue_units_table_exists() || !$this->media_queue_table_exists()) {
			return 0;
		}
		global $wpdb;
		$units = $this->get_media_queue_units_table_name();
		$parents = $this->get_media_queue_table_name();
		$format = $this->normalize_media_queue_format($format);
		$parent_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT units.parent_queue_id FROM %i units INNER JOIN %i parents ON parents.id = units.parent_queue_id WHERE parents.source_kind = 'attachment' AND parents.format = %s AND units.status = 'failed'",
				$units,
				$parents,
				$format
			)
		);
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i units INNER JOIN %i parents ON parents.id = units.parent_queue_id SET units.status = 'pending', units.consecutive_failures = 0, units.stale_recoveries = 0, units.failure_code = '', units.failure_stage = '', units.failure_detail = '', units.resolution_code = '', units.resolution_detail = '', units.resolution_context = '', units.encoder_attempts = '', units.updated_at = %s, units.started_at = NULL, units.completed_at = NULL WHERE parents.source_kind = 'attachment' AND parents.format = %s AND units.status = 'failed'",
				$units,
				$parents,
				current_time('mysql'),
				$format
			)
		);
		$count = is_numeric($result) ? max(0, (int) $result) : 0;
		if ($count > 0) {
			$this->reconcile_media_queue_parent_ids_after_unit_recovery((array) $parent_ids);
		}
		return $count;
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
