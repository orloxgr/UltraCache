<?php
/**
 * UltraCache Media Library replacement readiness inventory and start guards.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Replacement_Readiness_Trait
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache reads private custom Media Library replacement and media queue tables with validated table identifiers.

    private function get_media_replacement_readiness_defaults()
    {
        return array(
            'version'                  => 3,
            'status'                   => 'idle',
            'target_format'            => 'webp',
            'fallback_format'          => 'original',
            'cursor_attachment_id'     => 0,
            'verification_pass'         => 0,
            'queueable_variants'        => 0,
            'queue_enqueued_attachments'=> 0,
            'queue_processed_attachments'=> 0,
            'generated_units'           => 0,
            'attempted_units'           => 0,
            'last_queue_message'        => '',
            'last_queue_pause_reason'   => '',
            'pass_blocker_signature'   => '',
            'previous_blocker_signature'=> '',
            'pass_queue_enqueued_start'=> 0,
            'pass_queue_processed_start'=> 0,
            'pass_generated_units_start'=> 0,
            'pass_attempted_units_start'=> 0,
            'no_progress_detected'      => false,
            'no_progress_reason'        => '',
            'completion_reason'         => '',
            'candidate_attachments'    => 0,
            'scanned_attachments'      => 0,
            'eligible_attachments'     => 0,
            'ready_attachments'        => 0,
            'blocked_attachments'      => 0,
            'required_variants'        => 0,
            'ready_variants'           => 0,
            'missing_variants'         => 0,
            'stale_variants'           => 0,
            'failed_variants'          => 0,
            'pending_variants'         => 0,
            'unsupported_variants'     => 0,
            'library_changed'          => false,
            'candidate_count_at_finish'=> 0,
            'blocker_samples'          => array(),
            'created_at'               => '',
            'updated_at'               => '',
            'completed_at'             => '',
            'last_error'               => '',
        );
    }

    private function normalize_media_replacement_readiness_state($state)
    {
        $state = is_array($state) ? $state : array();
        $stored_version = max(0, (int) ($state['version'] ?? 0));
        $state = array_merge($this->get_media_replacement_readiness_defaults(), $state);
        $state['version']               = 3;
        $state['status']                = in_array((string) $state['status'], array('idle', 'scanning', 'paused', 'completed', 'failed'), true) ? (string) $state['status'] : 'idle';
        $state['target_format']         = in_array((string) $state['target_format'], array('avif', 'webp'), true) ? (string) $state['target_format'] : 'webp';
        $state['fallback_format']       = ('avif' === $state['target_format'] && 'webp' === (string) $state['fallback_format']) ? 'webp' : 'original';
        $state['cursor_attachment_id']  = max(0, absint($state['cursor_attachment_id']));
        $state['library_changed']       = !empty($state['library_changed']);
        $state['last_error']            = sanitize_text_field((string) $state['last_error']);
        $state['last_queue_message']    = sanitize_text_field((string) $state['last_queue_message']);
        $state['last_queue_pause_reason'] = sanitize_key((string) $state['last_queue_pause_reason']);
        $state['pass_blocker_signature'] = preg_match('/^[a-f0-9]{64}$/', (string) $state['pass_blocker_signature']) ? (string) $state['pass_blocker_signature'] : '';
        $state['previous_blocker_signature'] = preg_match('/^[a-f0-9]{64}$/', (string) $state['previous_blocker_signature']) ? (string) $state['previous_blocker_signature'] : '';
        $state['no_progress_detected'] = !empty($state['no_progress_detected']);
        $state['no_progress_reason'] = sanitize_key((string) $state['no_progress_reason']);
        $state['completion_reason'] = sanitize_key((string) $state['completion_reason']);
        foreach (array('created_at', 'updated_at', 'completed_at') as $timestamp_key) {
            $state[$timestamp_key] = sanitize_text_field((string) $state[$timestamp_key]);
        }
        foreach (array(
            'candidate_attachments',
            'verification_pass',
            'queueable_variants',
            'queue_enqueued_attachments',
            'queue_processed_attachments',
            'generated_units',
            'attempted_units',
            'scanned_attachments',
            'eligible_attachments',
            'ready_attachments',
            'blocked_attachments',
            'required_variants',
            'ready_variants',
            'missing_variants',
            'stale_variants',
            'failed_variants',
            'pending_variants',
            'unsupported_variants',
            'candidate_count_at_finish',
            'pass_queue_enqueued_start',
            'pass_queue_processed_start',
            'pass_generated_units_start',
            'pass_attempted_units_start',
        ) as $count_key) {
            $state[$count_key] = max(0, (int) $state[$count_key]);
        }

        if ($stored_version < 3) {
            $state['pass_blocker_signature'] = '';
            $state['previous_blocker_signature'] = '';
            $state['pass_queue_enqueued_start'] = (int) $state['queue_enqueued_attachments'];
            $state['pass_queue_processed_start'] = (int) $state['queue_processed_attachments'];
            $state['pass_generated_units_start'] = (int) $state['generated_units'];
            $state['pass_attempted_units_start'] = (int) $state['attempted_units'];
            $state['no_progress_detected'] = false;
            $state['no_progress_reason'] = '';
            $state['completion_reason'] = '';
        }

        $samples = array();
        foreach ((array) $state['blocker_samples'] as $sample) {
            if (!is_array($sample) || count($samples) >= 25) {
                continue;
            }
            $samples[] = array(
                'attachmentId' => absint($sample['attachmentId'] ?? 0),
                'scope'        => in_array((string) ($sample['scope'] ?? ''), array('main', 'intermediate'), true) ? (string) $sample['scope'] : 'main',
                'sizeName'     => substr(sanitize_key((string) ($sample['sizeName'] ?? '')), 0, 64),
                'status'       => in_array((string) ($sample['status'] ?? ''), array('missing', 'stale', 'failed', 'pending', 'unsupported'), true) ? (string) $sample['status'] : 'failed',
                'source'       => sanitize_text_field((string) ($sample['source'] ?? '')),
                'reasonCode'   => sanitize_key((string) ($sample['reasonCode'] ?? '')),
                'reason'       => sanitize_text_field((string) ($sample['reason'] ?? '')),
                'unitId'       => absint($sample['unitId'] ?? 0),
                'unitStatus'   => in_array((string) ($sample['unitStatus'] ?? ''), array('pending', 'processing', 'done', 'failed', 'skipped'), true) ? (string) $sample['unitStatus'] : '',
                'unitAttempts' => max(0, (int) ($sample['unitAttempts'] ?? 0)),
                'failureCode'  => sanitize_key((string) ($sample['failureCode'] ?? '')),
                'failureStage' => sanitize_key((string) ($sample['failureStage'] ?? '')),
                'failureDetail'=> sanitize_text_field((string) ($sample['failureDetail'] ?? '')),
                'skippedReason'=> sanitize_key((string) ($sample['skippedReason'] ?? '')),
                'skipDetail'   => sanitize_text_field((string) ($sample['skipDetail'] ?? '')),
                'encoderAttempts' => array_slice(array_values(array_filter((array) ($sample['encoderAttempts'] ?? array()), 'is_array')), 0, 10),
            );
        }
        $state['blocker_samples'] = $samples;

        return $state;
    }

    private function get_media_replacement_readiness_state()
    {
        $workflow = $this->get_media_replacement_workflow_state();
        return $this->normalize_media_replacement_readiness_state($workflow['readiness'] ?? array());
    }

    private function update_media_replacement_readiness_state(array $state)
    {
        $state = $this->normalize_media_replacement_readiness_state($state);
        $workflow = $this->get_media_replacement_workflow_state();
        $workflow['readiness'] = $state;
        $this->update_media_replacement_workflow_state($workflow);
        return $state;
    }

    private function reset_media_replacement_readiness_state($target_format, $fallback_format)
    {
        return $this->update_media_replacement_readiness_state(array_merge(
            $this->get_media_replacement_readiness_defaults(),
            array(
                'status'                => 'scanning',
                'target_format'         => $target_format,
                'fallback_format'       => $fallback_format,
                'candidate_attachments' => $this->get_media_replacement_candidate_attachment_count(),
                'created_at'            => current_time('mysql', true),
                'updated_at'            => current_time('mysql', true),
            )
        ));
    }

    private function get_media_replacement_readiness_queue_status_map(array $attachment_ids, $target_format)
    {
        $attachment_ids = array_values(array_filter(array_unique(array_map('absint', $attachment_ids))));
        if (empty($attachment_ids)) {
            return array();
        }

        $target_format = sanitize_key((string) $target_format);
        foreach ($attachment_ids as $attachment_id) {
            $this->reconcile_media_queue_units_for_attachment($attachment_id, $target_format, false);
        }

        return $this->get_media_queue_readiness_diagnostics($attachment_ids, $target_format);
    }

    private function get_media_replacement_required_source_variants($attachment_id)
    {
        $attachment_id = absint($attachment_id);
        if ($attachment_id <= 0) {
            return array();
        }

        $main_file = get_attached_file($attachment_id);
        $main_file = is_string($main_file) ? wp_normalize_path($main_file) : '';
        $metadata = wp_get_attachment_metadata($attachment_id);
        $metadata = is_array($metadata) ? $metadata : array();
        $variants = array();
        $seen_paths = array();

        $append = static function ($scope, $size_name, $source_file) use (&$variants, &$seen_paths) {
            $source_file = is_string($source_file) ? wp_normalize_path($source_file) : '';
            $dedupe_key = strtolower($source_file);
            if ('' !== $dedupe_key && isset($seen_paths[$dedupe_key])) {
                return;
            }
            if ('' !== $dedupe_key) {
                $seen_paths[$dedupe_key] = true;
            }
            $variants[] = array(
                'scope'       => $scope,
                'size_name'   => $size_name,
                'source_file' => $source_file,
            );
        };

        $append('main', '', $main_file);
        if ('' !== $main_file && !empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $base_dir = dirname($main_file);
            foreach ($metadata['sizes'] as $size_name => $size_data) {
                if (!is_array($size_data) || empty($size_data['file'])) {
                    continue;
                }
                $size_file = ltrim(str_replace('\\', '/', (string) $size_data['file']), '/');
                if ('' === $size_file || !preg_match('/\.(?:jpe?g|png)$/i', $size_file)) {
                    continue;
                }
                $append(
                    'intermediate',
                    substr(sanitize_key((string) $size_name), 0, 64),
                    trailingslashit($base_dir) . $size_file
                );
            }
        }

        return $variants;
    }

    private function get_media_replacement_readiness_source_relative_path($source_file)
    {
        $source_file = is_string($source_file) ? wp_normalize_path($source_file) : '';
        if ('' === $source_file) {
            return '';
        }

        $existing_relative = $this->get_uploads_relative_path_from_source($source_file);
        if ($existing_relative) {
            return (string) $existing_relative;
        }

        $uploads = function_exists('ultracache_uploads_base_info') ? ultracache_uploads_base_info() : wp_upload_dir(null, false);
        $uploads_root = isset($uploads['basedir']) ? wp_normalize_path((string) $uploads['basedir']) : '';
        if ('' === $uploads_root || !$this->path_is_within_root($source_file, $uploads_root)) {
            return '';
        }

        $relative = ltrim(substr($source_file, strlen(rtrim($uploads_root, '/'))), '/');
        if ('' === $relative) {
            return '';
        }
        foreach (explode('/', str_replace('\\', '/', $relative)) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return '';
            }
        }

        return $relative;
    }

    private function get_media_replacement_readiness_unit_diagnostic(array $queue_context, $relative_path)
    {
        $relative_path = ltrim(str_replace('\\', '/', (string) $relative_path), '/');
        if ('' === $relative_path) {
            return array();
        }
        $units = isset($queue_context['units']) && is_array($queue_context['units']) ? $queue_context['units'] : array();
        return isset($units[$relative_path]) && is_array($units[$relative_path]) ? $units[$relative_path] : array();
    }

    private function update_media_replacement_readiness_blocker_signature(array &$state, array $result)
    {
        if ('ready' === (string) ($result['status'] ?? '')) {
            return;
        }
        $canonical = array(
            'attachmentId' => absint($result['attachmentId'] ?? 0),
            'scope'        => (string) ($result['scope'] ?? 'main'),
            'sizeName'     => (string) ($result['sizeName'] ?? ''),
            'source'       => (string) ($result['source'] ?? ''),
            'status'       => (string) ($result['status'] ?? 'failed'),
            'reasonCode'   => (string) ($result['reasonCode'] ?? ''),
            'unitStatus'   => (string) ($result['unitStatus'] ?? ''),
            'unitAttempts' => max(0, (int) ($result['unitAttempts'] ?? 0)),
            'failureCode'  => (string) ($result['failureCode'] ?? ''),
            'failureStage' => (string) ($result['failureStage'] ?? ''),
            'failureDetail'=> (string) ($result['failureDetail'] ?? ''),
            'skippedReason'=> (string) ($result['skippedReason'] ?? ''),
            'skipDetail'   => (string) ($result['skipDetail'] ?? ''),
        );
        $encoded = wp_json_encode($canonical, JSON_UNESCAPED_SLASHES);
        $state['pass_blocker_signature'] = hash('sha256', (string) $state['pass_blocker_signature'] . "\n" . (is_string($encoded) ? $encoded : ''));
    }

    private function get_media_replacement_readiness_pass_progress(array $state)
    {
        $enqueued = max(0, (int) $state['queue_enqueued_attachments'] - (int) $state['pass_queue_enqueued_start']);
        $processed = max(0, (int) $state['queue_processed_attachments'] - (int) $state['pass_queue_processed_start']);
        $attempted = max(0, (int) $state['attempted_units'] - (int) $state['pass_attempted_units_start']);
        $generated = max(0, (int) $state['generated_units'] - (int) $state['pass_generated_units_start']);
        $signature_changed = '' !== (string) $state['previous_blocker_signature']
            && (string) $state['pass_blocker_signature'] !== (string) $state['previous_blocker_signature'];
        return array(
            'enqueued'         => $enqueued,
            'processed'        => $processed,
            'attempted'        => $attempted,
            'generated'        => $generated,
            'signatureChanged' => $signature_changed,
            'madeProgress'     => $enqueued > 0 || $attempted > 0 || $generated > 0 || $signature_changed,
        );
    }

    private function inspect_media_replacement_readiness_variant($attachment_id, array $variant, $target_format, array $queue_context = array())
    {
        $source_file = isset($variant['source_file']) ? wp_normalize_path((string) $variant['source_file']) : '';
        $scope = isset($variant['scope']) && 'intermediate' === (string) $variant['scope'] ? 'intermediate' : 'main';
        $size_name = 'intermediate' === $scope ? substr(sanitize_key((string) ($variant['size_name'] ?? '')), 0, 64) : '';
        $relative_path = '' !== $source_file ? $this->get_media_replacement_readiness_source_relative_path($source_file) : '';
        $source_label = '' !== (string) $relative_path ? (string) $relative_path : basename($source_file);
        $unit_diagnostic = $this->get_media_replacement_readiness_unit_diagnostic($queue_context, $relative_path);
        $queue_status = !empty($unit_diagnostic['status'])
            ? (string) $unit_diagnostic['status']
            : (string) ($queue_context['parentStatus'] ?? '');
        $base = array(
            'attachmentId' => absint($attachment_id),
            'scope'        => $scope,
            'sizeName'     => $size_name,
            'source'       => sanitize_text_field((string) $source_label),
            'unitId'       => absint($unit_diagnostic['unitId'] ?? 0),
            'unitStatus'   => sanitize_key((string) ($unit_diagnostic['status'] ?? '')),
            'unitAttempts' => max(0, (int) ($unit_diagnostic['attempts'] ?? 0)),
            'failureCode'  => sanitize_key((string) ($unit_diagnostic['failureCode'] ?? '')),
            'failureStage' => sanitize_key((string) ($unit_diagnostic['failureStage'] ?? '')),
            'failureDetail'=> sanitize_text_field((string) ($unit_diagnostic['failureDetail'] ?? '')),
            'skippedReason'=> sanitize_key((string) ($unit_diagnostic['skippedReason'] ?? '')),
            'skipDetail'   => sanitize_text_field((string) ($unit_diagnostic['skipDetail'] ?? '')),
            'encoderAttempts' => array_slice(array_values(array_filter((array) ($unit_diagnostic['encoderAttempts'] ?? array()), 'is_array')), 0, 10),
        );

        if ('' === $source_file || !preg_match('/\.(?:jpe?g|png)$/i', $source_file) || '' === (string) $relative_path) {
            return array_merge($base, array('status' => 'unsupported', 'reasonCode' => 'unsupported_source', 'sourceEligible' => false, 'reason' => __('The source variant is not a JPG/PNG file inside the WordPress uploads directory.', 'ultracache')));
        }

        if (!$this->optimized_storage_readable_source_exists($source_file)) {
            return array_merge($base, array('status' => 'failed', 'reasonCode' => 'source_missing', 'sourceEligible' => false, 'reason' => __('The source variant is missing or unreadable.', 'ultracache')));
        }

        $generated_path = $this->get_media_replacement_generated_path_for_source($source_file, $target_format);
        if (!$generated_path) {
            return array_merge($base, array('status' => 'unsupported', 'reasonCode' => 'target_mapping_unavailable', 'sourceEligible' => false, 'reason' => __('UltraCache could not map this source variant to the selected replacement format.', 'ultracache')));
        }

        $target_exists = $this->optimized_storage_path_exists($generated_path, true);
        $queue_pending = in_array((string) $queue_status, array('pending', 'processing'), true);
        $queue_failed = 'failed' === (string) $queue_status;
        $queue_skipped = 'skipped' === (string) $queue_status;

        if (!$target_exists) {
            if ($queue_pending) {
                return array_merge($base, array('status' => 'pending', 'reasonCode' => 'target_missing_queue_pending', 'sourceEligible' => true, 'reason' => __('The target replacement is still pending in the media conversion queue.', 'ultracache')));
            }
            if ($queue_failed) {
                $failure_reason = '' !== (string) ($base['failureDetail'] ?? '')
                    ? (string) $base['failureDetail']
                    : __('The target replacement is missing and the media conversion queue reports a failure.', 'ultracache');
                return array_merge($base, array('status' => 'failed', 'reasonCode' => 'target_missing_queue_failed', 'sourceEligible' => true, 'reason' => $failure_reason));
            }
            if ($queue_skipped) {
                $skip_reason = '' !== (string) ($base['skipDetail'] ?? '')
                    ? (string) $base['skipDetail']
                    : __('The exact target queue row was skipped and no replacement file was generated.', 'ultracache');
                return array_merge($base, array('status' => 'unsupported', 'reasonCode' => 'target_missing_queue_skipped', 'sourceEligible' => false, 'reason' => $skip_reason));
            }
            return array_merge($base, array('status' => 'missing', 'reasonCode' => 'target_missing', 'sourceEligible' => true, 'reason' => __('The target replacement file does not exist.', 'ultracache')));
        }

        if (!$this->is_valid_generated_media_file($generated_path, $target_format, 'media_replacement_readiness_validate')) {
            if ($queue_pending) {
                return array_merge($base, array('status' => 'pending', 'reasonCode' => 'target_invalid_queue_pending', 'sourceEligible' => true, 'reason' => __('The existing target replacement is invalid, but regeneration is pending.', 'ultracache')));
            }
            return array_merge($base, array('status' => 'failed', 'reasonCode' => 'target_invalid', 'sourceEligible' => true, 'reason' => __('The target replacement exists but failed image validation.', 'ultracache')));
        }

        $source_mtime = function_exists('ultracache_safe_filemtime') ? (int) ultracache_safe_filemtime($source_file, 'media_replacement_readiness_source_mtime') : 0;
        $target_mtime = function_exists('ultracache_safe_filemtime') ? (int) ultracache_safe_filemtime($generated_path, 'media_replacement_readiness_target_mtime') : 0;
        if ($source_mtime <= 0 || $target_mtime <= 0) {
            return array_merge($base, array('status' => 'failed', 'reasonCode' => 'mtime_unavailable', 'sourceEligible' => true, 'reason' => __('UltraCache could not verify source and target modification times.', 'ultracache')));
        }
        if ($target_mtime < $source_mtime) {
            if ($queue_pending) {
                return array_merge($base, array('status' => 'pending', 'reasonCode' => 'target_stale_queue_pending', 'sourceEligible' => true, 'reason' => __('The target replacement is stale and regeneration is pending.', 'ultracache')));
            }
            if ($queue_failed) {
                $failure_reason = '' !== (string) ($base['failureDetail'] ?? '')
                    ? (string) $base['failureDetail']
                    : __('The target replacement is stale and the media conversion queue reports a failure.', 'ultracache');
                return array_merge($base, array('status' => 'failed', 'reasonCode' => 'target_stale_queue_failed', 'sourceEligible' => true, 'reason' => $failure_reason));
            }
            return array_merge($base, array('status' => 'stale', 'reasonCode' => 'target_stale', 'sourceEligible' => true, 'reason' => __('The target replacement is older than its source variant.', 'ultracache')));
        }

        return array_merge($base, array('status' => 'ready', 'reasonCode' => 'ready', 'sourceEligible' => true, 'reason' => ''));
    }

    private function get_media_replacement_readiness_blocker_priority(array $sample)
    {
        if ('' !== (string) ($sample['failureCode'] ?? '') || '' !== (string) ($sample['failureDetail'] ?? '')) {
            return 100;
        }
        $priority = array(
            'failed'      => 90,
            'unsupported' => 80,
            'stale'       => 70,
            'missing'     => 60,
            'pending'     => 50,
        );
        return (int) ($priority[(string) ($sample['status'] ?? '')] ?? 0);
    }

    private function add_media_replacement_readiness_blocker_sample(array &$state, array $result)
    {
        if ('ready' === (string) ($result['status'] ?? '')) {
            return;
        }
        $samples = array_values((array) $state['blocker_samples']);
        $samples[] = $result;
        usort(
            $samples,
            function ($left, $right) {
                $left = is_array($left) ? $left : array();
                $right = is_array($right) ? $right : array();
                $priority_compare = $this->get_media_replacement_readiness_blocker_priority($right) <=> $this->get_media_replacement_readiness_blocker_priority($left);
                if (0 !== $priority_compare) {
                    return $priority_compare;
                }
                $left_key = sprintf(
                    '%012d|%s|%s|%s',
                    absint($left['attachmentId'] ?? 0),
                    (string) ($left['scope'] ?? 'main'),
                    (string) ($left['sizeName'] ?? ''),
                    (string) ($left['source'] ?? '')
                );
                $right_key = sprintf(
                    '%012d|%s|%s|%s',
                    absint($right['attachmentId'] ?? 0),
                    (string) ($right['scope'] ?? 'main'),
                    (string) ($right['sizeName'] ?? ''),
                    (string) ($right['source'] ?? '')
                );
                return strcmp($left_key, $right_key);
            }
        );
        $state['blocker_samples'] = array_slice($samples, 0, 25);
    }

    private function get_media_replacement_readiness_primary_blocker_message(array $state)
    {
        $samples = array_values((array) ($state['blocker_samples'] ?? array()));
        if (empty($samples) || !is_array($samples[0])) {
            return '';
        }
        $sample = $samples[0];
        $scope = 'intermediate' === (string) ($sample['scope'] ?? '')
            ? sprintf(
                /* translators: %s: registered intermediate image size name. */
                __('intermediate size %s', 'ultracache'),
                (string) ($sample['sizeName'] ?? '')
            )
            : __('main image', 'ultracache');
        $reason = (string) ($sample['failureDetail'] ?? '');
        if ('' === $reason) {
            $reason = (string) ($sample['skipDetail'] ?? '');
        }
        if ('' === $reason) {
            $reason = (string) ($sample['reason'] ?? '');
        }
        $reason_code = (string) ($sample['failureCode'] ?? '');
        if ('' === $reason_code) {
            $reason_code = (string) ($sample['skippedReason'] ?? '');
        }
        if ('' !== $reason_code && false === strpos($reason, $reason_code)) {
            $reason = '' !== $reason ? $reason_code . ': ' . $reason : $reason_code;
        }
        return sprintf(
            /* translators: 1: attachment ID, 2: main/intermediate scope, 3: source path, 4: exact failure reason. */
            __('Attachment %1$d, %2$s, %3$s: %4$s', 'ultracache'),
            absint($sample['attachmentId'] ?? 0),
            $scope,
            (string) ($sample['source'] ?? ''),
            '' !== $reason ? $reason : __('The required target file is not ready.', 'ultracache')
        );
    }

    private function build_media_replacement_readiness_response(array $state, $has_more = false, $batch_scanned = 0, array $queue_result = array())
    {
        $state = $this->normalize_media_replacement_readiness_state($state);
        $blocker_variants = (int) $state['missing_variants']
            + (int) $state['stale_variants']
            + (int) $state['failed_variants']
            + (int) $state['pending_variants']
            + (int) $state['unsupported_variants'];
        $inventory_complete = 'completed' === $state['status'] && !$has_more;
        $ready = $inventory_complete
            && !$state['library_changed']
            && $state['candidate_attachments'] > 0
            && $state['eligible_attachments'] === $state['candidate_attachments']
            && $state['required_variants'] > 0
            && $state['ready_variants'] === $state['required_variants']
            && 0 === $blocker_variants;
        $progress = $state['candidate_attachments'] > 0
            ? round((min($state['scanned_attachments'], $state['candidate_attachments']) / max(1, $state['candidate_attachments'])) * 100, 1)
            : ($inventory_complete ? 100 : 0);

        if ('failed' === $state['status']) {
            $message = $state['last_error'] ?: __('Replacement readiness inventory failed.', 'ultracache');
        } elseif ('paused' === $state['status']) {
            $message = '' !== $state['last_queue_message']
                ? $state['last_queue_message']
                : __('Replacement readiness scan is paused and can be resumed.', 'ultracache');
        } elseif ($has_more || 'scanning' === $state['status']) {
            if ((int) $state['verification_pass'] > 0 || (int) $state['queue_processed_attachments'] > 0) {
                $message = sprintf(
                    /* translators: 1: target image format, 2: verification pass, 3: scanned attachments, 4: total candidate attachments. */
                    __('Generating and verifying exact %1$s replacement files. Pass %2$d scanned %3$d of %4$d candidate attachments.', 'ultracache'),
                    strtoupper((string) $state['target_format']),
                    max(1, (int) $state['verification_pass']),
                    (int) $state['scanned_attachments'],
                    (int) $state['candidate_attachments']
                );
            } else {
                $message = sprintf(
                    /* translators: 1: scanned attachments, 2: total candidate attachments. */
                    __('Replacement readiness inventory scanned %1$d of %2$d candidate attachments.', 'ultracache'),
                    (int) $state['scanned_attachments'],
                    (int) $state['candidate_attachments']
                );
            }
        } elseif ($ready) {
            $message = sprintf(
                /* translators: 1: replacement variants, 2: target image format. */
                __('Replacement readiness complete. All %1$d required %2$s variants are valid and current.', 'ultracache'),
                (int) $state['required_variants'],
                strtoupper((string) $state['target_format'])
            );
        } else {
            $primary_blocker = $this->get_media_replacement_readiness_primary_blocker_message($state);
            if (!empty($state['no_progress_detected'])) {
                $message = sprintf(
                    /* translators: 1: blocked target variants, 2: target image format, 3: exact first blocker. */
                    __('Replacement readiness stopped after detecting no conversion progress. %1$d required %2$s variant(s) remain blocked. %3$s', 'ultracache'),
                    $blocker_variants,
                    strtoupper((string) $state['target_format']),
                    $primary_blocker
                );
            } elseif ('' !== $primary_blocker) {
                $message = sprintf(
                    /* translators: %s: exact first blocker. */
                    __('Replacement readiness complete, but blockers remain. %s', 'ultracache'),
                    $primary_blocker
                );
            } else {
                $message = __('Replacement readiness complete, but blockers remain. Media Library replacement must stay locked.', 'ultracache');
            }
        }

        return array(
            'success'                 => 'failed' !== $state['status'],
            'status'                  => $state['status'],
            'message'                 => $message,
            'targetFormat'            => $state['target_format'],
            'fallbackFormat'          => $state['fallback_format'],
            'hasMore'                 => (bool) $has_more,
            'batchScanned'            => max(0, (int) $batch_scanned),
            'batchProcessed'          => max(0, (int) ($queue_result['attachmentsTouchedThisRun'] ?? $queue_result['processed'] ?? 0)),
            'batchGeneratedUnits'     => max(0, (int) ($queue_result['unitsGeneratedThisRun'] ?? 0)),
            'batchAttemptedUnits'     => max(0, (int) ($queue_result['unitAttemptsThisRun'] ?? $queue_result['unitsProcessed'] ?? 0)),
            'queuePaused'             => !empty($queue_result['paused']),
            'queuePauseReason'        => sanitize_key((string) ($queue_result['pauseReason'] ?? $state['last_queue_pause_reason'])),
            'nextCursor'              => (int) $state['cursor_attachment_id'],
            'progressPercent'         => $progress,
            'inventoryComplete'       => $inventory_complete,
            'readyForReplacement'     => $ready,
            'libraryChanged'          => (bool) $state['library_changed'],
            'candidateAttachments'    => (int) $state['candidate_attachments'],
            'candidateCountAtFinish'  => (int) $state['candidate_count_at_finish'],
            'scannedAttachments'      => (int) $state['scanned_attachments'],
            'eligibleAttachments'     => (int) $state['eligible_attachments'],
            'readyAttachments'        => (int) $state['ready_attachments'],
            'blockedAttachments'      => (int) $state['blocked_attachments'],
            'requiredVariants'        => (int) $state['required_variants'],
            'readyVariants'           => (int) $state['ready_variants'],
            'missingVariants'         => (int) $state['missing_variants'],
            'staleVariants'           => (int) $state['stale_variants'],
            'failedVariants'          => (int) $state['failed_variants'],
            'pendingVariants'         => (int) $state['pending_variants'],
            'unsupportedVariants'     => (int) $state['unsupported_variants'],
            'verificationPass'        => (int) $state['verification_pass'],
            'queueableVariants'       => (int) $state['queueable_variants'],
            'queueEnqueuedAttachments'=> (int) $state['queue_enqueued_attachments'],
            'queueProcessedAttachments'=> (int) $state['queue_processed_attachments'],
            'generatedUnits'          => (int) $state['generated_units'],
            'attemptedUnits'          => (int) $state['attempted_units'],
            'lastQueueMessage'        => $state['last_queue_message'],
            'lastQueuePauseReason'    => $state['last_queue_pause_reason'],
            'blockerSignature'        => $state['pass_blocker_signature'],
            'previousBlockerSignature'=> $state['previous_blocker_signature'],
            'noProgressDetected'      => (bool) $state['no_progress_detected'],
            'noProgressReason'        => $state['no_progress_reason'],
            'completionReason'        => $state['completion_reason'],
            'blockerVariants'         => $blocker_variants,
            'blockerSamples'          => array_values((array) $state['blocker_samples']),
            'createdAt'               => $state['created_at'],
            'updatedAt'               => $state['updated_at'],
            'completedAt'             => $state['completed_at'],
            'lastError'               => $state['last_error'],
        );
    }

    private function add_media_replacement_start_guard_blocker(array &$blockers, $code, $message, $count = 0)
    {
        $code = sanitize_key((string) $code);
        if ('' === $code) {
            return;
        }

        foreach ($blockers as $blocker) {
            if (isset($blocker['code']) && $code === (string) $blocker['code']) {
                return;
            }
        }

        $blockers[] = array(
            'code'    => $code,
            'message' => sanitize_text_field((string) $message),
            'count'   => max(0, (int) $count),
        );
    }

    public function get_media_library_replacement_start_guard($args = array())
    {
        $args = is_array($args) ? $args : array();
        list($target_format, $fallback_format) = $this->get_media_replacement_current_output_policy();
        $state = $this->get_media_replacement_readiness_state();
        $readiness = $this->get_media_library_replacement_readiness_status();
        $blockers = array();

        $inventory_complete = 'completed' === $state['status'] && !empty($readiness['inventoryComplete']) && empty($readiness['hasMore']);
        $current_candidate_count = $inventory_complete
            ? $this->get_media_replacement_candidate_attachment_count()
            : (int) $state['candidate_attachments'];
        if ('idle' === $state['status']) {
            $this->add_media_replacement_start_guard_blocker(
                $blockers,
                'readiness_not_scanned',
                __('Run the complete replacement readiness inventory before starting Media Library replacement.', 'ultracache')
            );
        } elseif ('failed' === $state['status']) {
            $this->add_media_replacement_start_guard_blocker(
                $blockers,
                'readiness_failed',
                $state['last_error'] ?: __('The replacement readiness inventory failed.', 'ultracache')
            );
        } elseif (!$inventory_complete) {
            $this->add_media_replacement_start_guard_blocker(
                $blockers,
                'readiness_incomplete',
                __('The replacement readiness inventory is not complete.', 'ultracache')
            );
        }

        if ($state['target_format'] !== $target_format || $state['fallback_format'] !== $fallback_format) {
            $this->add_media_replacement_start_guard_blocker(
                $blockers,
                'output_policy_changed',
                __('The image replacement policy changed after the readiness inventory. Run the inventory again.', 'ultracache')
            );
        }

        if ($inventory_complete) {
            $candidate_set_changed = !empty($state['library_changed'])
                || $state['candidate_attachments'] !== $state['candidate_count_at_finish']
                || $state['candidate_attachments'] !== $current_candidate_count;
            if ($candidate_set_changed) {
                $this->add_media_replacement_start_guard_blocker(
                    $blockers,
                    'candidate_set_changed',
                    __('The Media Library candidate set changed after the readiness inventory. Run the inventory again.', 'ultracache'),
                    abs($current_candidate_count - (int) $state['candidate_attachments'])
                );
            }

            if ($state['candidate_attachments'] <= 0) {
                $this->add_media_replacement_start_guard_blocker(
                    $blockers,
                    'no_candidate_attachments',
                    __('No JPG/PNG Media Library attachments are available for replacement.', 'ultracache')
                );
            }

            if ($state['scanned_attachments'] !== $state['candidate_attachments']) {
                $this->add_media_replacement_start_guard_blocker(
                    $blockers,
                    'candidate_scan_incomplete',
                    __('Not every candidate attachment was scanned by the readiness inventory.', 'ultracache'),
                    max(0, (int) $state['candidate_attachments'] - (int) $state['scanned_attachments'])
                );
            }

            if ($state['eligible_attachments'] !== $state['candidate_attachments']) {
                $this->add_media_replacement_start_guard_blocker(
                    $blockers,
                    'ineligible_attachments',
                    __('One or more candidate attachments do not have a complete eligible JPG/PNG source set.', 'ultracache'),
                    max(0, (int) $state['candidate_attachments'] - (int) $state['eligible_attachments'])
                );
            }

            if ($state['required_variants'] <= 0) {
                $this->add_media_replacement_start_guard_blocker(
                    $blockers,
                    'no_required_variants',
                    __('The readiness inventory did not find any source variants to replace.', 'ultracache')
                );
            }

            $variant_blockers = array(
                'missing_variants'     => array('missing_target_variants', __('Required target-format files are missing.', 'ultracache')),
                'stale_variants'       => array('stale_target_variants', __('Required target-format files are older than their source files.', 'ultracache')),
                'failed_variants'      => array('failed_target_variants', __('Required target-format files failed validation or conversion.', 'ultracache')),
                'pending_variants'     => array('pending_target_variants', __('Required target-format files are still pending or processing.', 'ultracache')),
                'unsupported_variants' => array('unsupported_source_variants', __('One or more source variants are unsupported for replacement.', 'ultracache')),
            );
            foreach ($variant_blockers as $state_key => $definition) {
                $count = max(0, (int) $state[$state_key]);
                if ($count > 0) {
                    $this->add_media_replacement_start_guard_blocker($blockers, $definition[0], $definition[1], $count);
                }
            }

            if ($state['ready_variants'] !== $state['required_variants']) {
                $this->add_media_replacement_start_guard_blocker(
                    $blockers,
                    'variant_count_mismatch',
                    __('Not every required target-format variant is ready.', 'ultracache'),
                    max(0, (int) $state['required_variants'] - (int) $state['ready_variants'])
                );
            }

            if ($state['blocked_attachments'] > 0 || $state['ready_attachments'] !== $state['candidate_attachments']) {
                $this->add_media_replacement_start_guard_blocker(
                    $blockers,
                    'blocked_attachments',
                    __('One or more Media Library attachments are not fully ready for replacement.', 'ultracache'),
                    max((int) $state['blocked_attachments'], max(0, (int) $state['candidate_attachments'] - (int) $state['ready_attachments']))
                );
            }

        }

        $decision_codes = array(
            'ineligible_attachments',
            'missing_target_variants',
            'stale_target_variants',
            'failed_target_variants',
            'pending_target_variants',
            'unsupported_source_variants',
            'variant_count_mismatch',
            'blocked_attachments',
        );
        $hard_blockers = array();
        $decision_blockers = array();
        foreach ($blockers as $blocker) {
            if (in_array((string) ($blocker['code'] ?? ''), $decision_codes, true)) {
                $decision_blockers[] = $blocker;
            } else {
                $hard_blockers[] = $blocker;
            }
        }
        $allowed = empty($hard_blockers);
        $decisions_required = $allowed && !empty($decision_blockers);
        $message = !$allowed
            ? __('Media Library replacement Prepare cannot start until the readiness inventory is structurally current.', 'ultracache')
            : ($decisions_required
                ? __('Readiness inventory is complete. Prepare will freeze the blocker groups so they can be resolved with Decide Blockers.', 'ultracache')
                : sprintf(
                    /* translators: 1: ready target variants, 2: target image format. */
                    __('Replacement start guard passed. All %1$d required %2$s variants are valid and current.', 'ultracache'),
                    (int) $state['ready_variants'],
                    strtoupper($target_format)
                ));

        return array(
            'success'                     => true,
            'allowed'                     => $allowed,
            'blocked'                     => !$allowed,
            'decisionsRequired'           => $decisions_required,
            'status'                      => !$allowed ? 'replacement_start_blocked' : ($decisions_required ? 'replacement_prepare_ready_with_blockers' : 'replacement_start_ready'),
            'message'                     => $message,
            'targetFormat'                => $target_format,
            'fallbackFormat'              => $fallback_format,
            'currentCandidateAttachments' => $current_candidate_count,
            'blockers'                    => $blockers,
            'hardBlockers'                => $hard_blockers,
            'decisionBlockers'            => $decision_blockers,
            'readiness'                   => $readiness,
        );
    }

    private function reset_media_replacement_readiness_verification_pass(array $state)
    {
        foreach (array(
            'scanned_attachments',
            'eligible_attachments',
            'ready_attachments',
            'blocked_attachments',
            'required_variants',
            'ready_variants',
            'missing_variants',
            'stale_variants',
            'failed_variants',
            'pending_variants',
            'unsupported_variants',
            'queueable_variants',
        ) as $count_key) {
            $state[$count_key] = 0;
        }
        $state['cursor_attachment_id'] = 0;
        $state['previous_blocker_signature'] = (string) $state['pass_blocker_signature'];
        $state['pass_blocker_signature'] = '';
        $state['pass_queue_enqueued_start'] = (int) $state['queue_enqueued_attachments'];
        $state['pass_queue_processed_start'] = (int) $state['queue_processed_attachments'];
        $state['pass_generated_units_start'] = (int) $state['generated_units'];
        $state['pass_attempted_units_start'] = (int) $state['attempted_units'];
        $state['blocker_samples'] = array();
        $state['verification_pass'] = max(0, (int) $state['verification_pass']) + 1;
        $state['status'] = 'scanning';
        $state['no_progress_detected'] = false;
        $state['no_progress_reason'] = '';
        $state['completion_reason'] = '';
        $state['completed_at'] = '';
        $state['last_error'] = '';
        $state['updated_at'] = current_time('mysql', true);
        return $this->update_media_replacement_readiness_state($state);
    }

    private function media_replacement_readiness_result_is_queueable(array $result, $queue_status)
    {
        $exact_queue_status = (string) ($result['unitStatus'] ?? '');
        if ('' === $exact_queue_status) {
            $exact_queue_status = (string) $queue_status;
        }
        if (empty($result['sourceEligible']) || in_array($exact_queue_status, array('failed', 'skipped'), true)) {
            return false;
        }

        $status = (string) ($result['status'] ?? 'failed');
        if (in_array($status, array('missing', 'stale', 'pending'), true)) {
            return true;
        }

        return 'failed' === $status && 'target_invalid' === (string) ($result['reasonCode'] ?? '');
    }

    public function get_media_library_replacement_readiness_status()
    {
        $state = $this->get_media_replacement_readiness_state();
        $has_more = in_array($state['status'], array('scanning', 'paused'), true)
            && !empty($this->get_media_replacement_candidate_attachment_ids_batch($state['cursor_attachment_id'], 1));
        return $this->build_media_replacement_readiness_response($state, $has_more, 0);
    }

    public function scan_media_library_replacement_readiness_inventory($args = array())
    {
        $args = is_array($args) ? $args : array();
        list($target_format, $fallback_format) = $this->get_media_replacement_current_output_policy();
        $limit = isset($args['limit']) && absint($args['limit']) > 0 ? absint($args['limit']) : 50;
        $limit = max(1, min(250, $limit));
        $lock_token = $this->acquire_media_replacement_readiness_lock($target_format);
        if ('' === $lock_token) {
            $locked = $this->get_media_library_replacement_readiness_status();
            $locked['success'] = false;
            $locked['blocked'] = true;
            $locked['status'] = 'readiness_locked';
            $locked['message'] = __('Another Media Library replacement readiness chunk is already running.', 'ultracache');
            return $locked;
        }

        $state = $this->get_media_replacement_readiness_state();
        $batch_scanned = 0;
        $queue_result = array();
        try {
            $reset = !empty($args['reset']);
            $policy_changed = $state['target_format'] !== $target_format || $state['fallback_format'] !== $fallback_format;
            if ($reset || $policy_changed || 'idle' === $state['status']) {
                $state = $this->reset_media_replacement_readiness_state($target_format, $fallback_format);
            } elseif ('completed' === $state['status']) {
                return $this->build_media_replacement_readiness_response($state, false, 0);
            } else {
                $state['status'] = 'scanning';
                $state['last_error'] = '';
                $state['last_queue_pause_reason'] = '';
                $state['updated_at'] = current_time('mysql', true);
                $state = $this->update_media_replacement_readiness_state($state);
            }

            $attachment_ids = $this->get_media_replacement_candidate_attachment_ids_batch($state['cursor_attachment_id'], $limit);
            $queue_statuses = $this->get_media_replacement_readiness_queue_status_map($attachment_ids, $target_format);
            $queue_attachment_ids = array();

            foreach ($attachment_ids as $attachment_id) {
                $variants = $this->get_media_replacement_required_source_variants($attachment_id);
                $attachment_ready = true;
                $attachment_eligible = false;
                $attachment_queueable = false;
                $force_pending = false;
                $queue_context = isset($queue_statuses[$attachment_id]) && is_array($queue_statuses[$attachment_id]) ? $queue_statuses[$attachment_id] : array();
                $queue_status = (string) ($queue_context['parentStatus'] ?? '');

                foreach ($variants as $variant) {
                    $result = $this->inspect_media_replacement_readiness_variant($attachment_id, $variant, $target_format, $queue_context);
                    $status = (string) ($result['status'] ?? 'failed');
                    $state['required_variants']++;
                    if ('ready' === $status) {
                        $state['ready_variants']++;
                        $attachment_eligible = true;
                    } else {
                        $attachment_ready = false;
                        if ('missing' === $status) {
                            $state['missing_variants']++;
                            $attachment_eligible = true;
                        } elseif ('stale' === $status) {
                            $state['stale_variants']++;
                            $attachment_eligible = true;
                        } elseif ('pending' === $status) {
                            $state['pending_variants']++;
                            $attachment_eligible = true;
                        } elseif ('unsupported' === $status) {
                            $state['unsupported_variants']++;
                        } else {
                            $state['failed_variants']++;
                            if (!empty($result['sourceEligible'])) {
                                $attachment_eligible = true;
                            }
                        }

                        if ($this->media_replacement_readiness_result_is_queueable($result, $queue_status)) {
                            $state['queueable_variants']++;
                            $attachment_queueable = true;
                            if (in_array($status, array('stale', 'failed'), true) || in_array($queue_status, array('done', 'skipped'), true)) {
                                $force_pending = true;
                            }
                        }
                        $this->update_media_replacement_readiness_blocker_signature($state, $result);
                        $this->add_media_replacement_readiness_blocker_sample($state, $result);
                    }
                }

                if ($attachment_queueable && 'failed' !== $queue_status) {
                    if (in_array($queue_status, array('pending', 'processing'), true)) {
                        $queue_attachment_ids[$attachment_id] = absint($attachment_id);
                    } elseif (method_exists($this, 'upsert_media_queue_item') && $this->upsert_media_queue_item($attachment_id, $target_format, 'pending', '', 0, $force_pending)) {
                        $queue_attachment_ids[$attachment_id] = absint($attachment_id);
                        $state['queue_enqueued_attachments']++;
                    }
                }

                $state['scanned_attachments']++;
                if ($attachment_eligible) {
                    $state['eligible_attachments']++;
                }
                if ($attachment_ready && !empty($variants)) {
                    $state['ready_attachments']++;
                } else {
                    $state['blocked_attachments']++;
                }
                $state['cursor_attachment_id'] = max((int) $state['cursor_attachment_id'], absint($attachment_id));
                $batch_scanned++;
            }

            if (!empty($queue_attachment_ids) && method_exists($this, 'process_media_queue_batch')) {
                $queue_result = $this->process_media_queue_batch(array(
                    'limit'          => min(count($queue_attachment_ids), max(1, $limit)),
                    'format'         => $target_format,
                    'only_missing'   => true,
                    'auto_rebuild'   => false,
                    'attachment_ids' => array_values($queue_attachment_ids),
                ));
                $state['queue_processed_attachments'] += max(0, (int) ($queue_result['attachmentsTouchedThisRun'] ?? $queue_result['processed'] ?? 0));
                $state['attempted_units'] += max(0, (int) ($queue_result['unitAttemptsThisRun'] ?? $queue_result['unitsProcessed'] ?? 0));
                $state['generated_units'] += max(0, (int) ($queue_result['unitsGeneratedThisRun'] ?? 0));
                $state['last_queue_message'] = sanitize_text_field((string) ($queue_result['message'] ?? ''));
                $state['last_queue_pause_reason'] = sanitize_key((string) ($queue_result['pauseReason'] ?? $queue_result['reason'] ?? ''));
            }

            $has_more_candidates = !empty($this->get_media_replacement_candidate_attachment_ids_batch($state['cursor_attachment_id'], 1));
            if ($has_more_candidates) {
                $queue_pause_reason = sanitize_key((string) ($queue_result['pauseReason'] ?? $queue_result['reason'] ?? ''));
                $hard_queue_pause = !empty($queue_result['paused']) && in_array($queue_pause_reason, array('background_paused', 'manual_session_active'), true);
                $state['status'] = $hard_queue_pause ? 'paused' : 'scanning';
                $state['updated_at'] = current_time('mysql', true);
                $state = $this->update_media_replacement_readiness_state($state);
                $response = $this->build_media_replacement_readiness_response($state, true, $batch_scanned, $queue_result);
                if ($hard_queue_pause) {
                    $response['success'] = false;
                    $response['blocked'] = true;
                }
                return $response;
            }

            $state['candidate_count_at_finish'] = $this->get_media_replacement_candidate_attachment_count();
            $state['library_changed'] = $state['candidate_count_at_finish'] !== $state['candidate_attachments'];
            $needs_verification_pass = (int) $state['queueable_variants'] > 0 && !$state['library_changed'];
            $queue_paused = !empty($queue_result['paused']);
            $pass_progress = $this->get_media_replacement_readiness_pass_progress($state);
            $no_progress = $needs_verification_pass && !$queue_paused && empty($pass_progress['madeProgress']);

            if ($no_progress) {
                $state['status'] = 'completed';
                $state['no_progress_detected'] = true;
                $state['no_progress_reason'] = 'queueable_variants_without_transition';
                $state['completion_reason'] = 'completed_with_blockers_no_progress';
                $state['last_error'] = '';
                $state['updated_at'] = current_time('mysql', true);
                $state['completed_at'] = current_time('mysql', true);
                $state = $this->update_media_replacement_readiness_state($state);
                return $this->build_media_replacement_readiness_response($state, false, $batch_scanned, $queue_result);
            }

            if ($needs_verification_pass) {
                $state = $this->reset_media_replacement_readiness_verification_pass($state);
                if (!empty($queue_result['paused'])) {
                    $state['status'] = 'paused';
                    $state['last_queue_message'] = sanitize_text_field((string) ($queue_result['message'] ?? __('Exact replacement conversion is paused.', 'ultracache')));
                    $state['last_queue_pause_reason'] = sanitize_key((string) ($queue_result['pauseReason'] ?? $queue_result['reason'] ?? ''));
                    $state = $this->update_media_replacement_readiness_state($state);
                }
                $response = $this->build_media_replacement_readiness_response($state, true, $batch_scanned, $queue_result);
                if (!empty($queue_result['paused']) && in_array((string) ($state['last_queue_pause_reason'] ?? ''), array('background_paused', 'manual_session_active'), true)) {
                    $response['success'] = false;
                    $response['blocked'] = true;
                }
                return $response;
            }

            $state['status'] = 'completed';
            $state['completion_reason'] = 0 === ((int) $state['missing_variants']
                + (int) $state['stale_variants']
                + (int) $state['failed_variants']
                + (int) $state['pending_variants']
                + (int) $state['unsupported_variants'])
                ? 'ready'
                : 'completed_with_blockers';
            $state['updated_at'] = current_time('mysql', true);
            $state['completed_at'] = current_time('mysql', true);
            $state = $this->update_media_replacement_readiness_state($state);
            return $this->build_media_replacement_readiness_response($state, false, $batch_scanned, $queue_result);
        } catch (Throwable $error) {
            $state['status'] = 'failed';
            $state['last_error'] = sanitize_text_field((string) $error->getMessage());
            $state['updated_at'] = current_time('mysql', true);
            $state = $this->update_media_replacement_readiness_state($state);
            return $this->build_media_replacement_readiness_response($state, true, $batch_scanned, $queue_result);
        } finally {
            $this->release_media_replacement_readiness_lock($lock_token);
        }
    }



}
