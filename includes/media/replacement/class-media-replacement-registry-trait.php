<?php
/**
 * UltraCache Media Library replacement attachment and generated-size registry.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Replacement_Registry_Trait
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache uses private custom Media Library replacement registry tables with validated table identifiers.

    private function get_media_replacement_generated_path_for_source($source_file, $target_format)
    {
        $target_format = strtolower((string) $target_format);
        if ('avif' === $target_format && method_exists($this, 'get_avif_path_from_source')) {
            return $this->get_avif_path_from_source($source_file);
        }

        if ('webp' === $target_format && method_exists($this, 'get_webp_path_from_source')) {
            return $this->get_webp_path_from_source($source_file);
        }

        return false;
    }

    private function get_media_replacement_mime_for_format($format)
    {
        $format = strtolower((string) $format);
        if ('avif' === $format) {
            return 'image/avif';
        }

        if ('webp' === $format) {
            return 'image/webp';
        }

        return '';
    }

    private function build_media_replacement_public_url($relative_path)
    {
        $uploads = function_exists('ultracache_uploads_base_info') ? ultracache_uploads_base_info() : wp_upload_dir(null, false);
        if (empty($uploads['baseurl'])) {
            return '';
        }

        $relative_path = ltrim(str_replace('\\', '/', (string) $relative_path), '/');
        return trailingslashit((string) $uploads['baseurl']) . $relative_path;
    }

    private function reset_media_replacement_job_registry($job_id)
    {
        global $wpdb;

        $job_id          = sanitize_key((string) $job_id);
        $items_table     = $this->get_media_replacement_items_table_name();
        $refs_table      = $this->get_media_replacement_refs_table_name();
        $ref_index_table = $this->get_media_replacement_ref_index_table_name();
        $file_refs_table = $this->get_media_replacement_file_refs_table_name();
        $theme_css_files_table = $this->get_media_replacement_theme_css_files_table_name();
        if ('' === $job_id || '' === $items_table || '' === $refs_table || '' === $ref_index_table || '' === $file_refs_table || '' === $theme_css_files_table || !($wpdb instanceof wpdb)) {
            return false;
        }

        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE job_id = %s', $file_refs_table, $job_id));
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE job_id = %s', $theme_css_files_table, $job_id));
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE job_id = %s', $refs_table, $job_id));
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE job_id = %s', $ref_index_table, $job_id));
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE job_id = %s', $items_table, $job_id));
        delete_option($this->get_media_replacement_intermediate_expand_option_name());
        delete_option($this->get_media_replacement_ref_index_scan_option_name());
        $this->clear_media_replacement_database_reference_specs();
        delete_option($this->get_media_replacement_theme_css_scan_option_name());
        if (method_exists($this, 'clear_media_replacement_theme_css_stream_state')) {
            $this->clear_media_replacement_theme_css_stream_state(true);
        }
        $this->clear_media_replacement_theme_css_manifest();
        return true;
    }

    private function reset_media_replacement_active_job_for_restart(array $saved_state)
    {
        $saved_state = $this->normalize_media_replacement_job_state($saved_state);
        $old_job_id  = isset($saved_state['job_id']) ? sanitize_key((string) $saved_state['job_id']) : '';

        if ('' !== $old_job_id) {
            $this->reset_media_replacement_job_registry($old_job_id);
        } else {
            delete_option($this->get_media_replacement_intermediate_expand_option_name());
            delete_option($this->get_media_replacement_ref_index_scan_option_name());
            $this->clear_media_replacement_database_reference_specs();
            delete_option($this->get_media_replacement_theme_css_scan_option_name());
            if (method_exists($this, 'clear_media_replacement_theme_css_stream_state')) {
                $this->clear_media_replacement_theme_css_stream_state(true);
            }
            $this->clear_media_replacement_theme_css_manifest();
        }

        delete_option($this->get_media_replacement_active_job_option_name());
        delete_option('ultracache_media_replacement_cli_pause_request_v1');
        return true;
    }

    private function get_media_replacement_candidate_attachment_count()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return 0;
        }

        return max(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = %s
               AND p.post_mime_type IN ('image/jpeg', 'image/png')
               AND pm.meta_key = %s",
            'attachment',
            '_wp_attached_file'
        )));
    }

    private function get_media_replacement_converted_attachment_count()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return 0;
        }

        return max(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = %s
               AND p.post_mime_type IN ('image/avif', 'image/webp')
               AND pm.meta_key = %s",
            'attachment',
            '_wp_attached_file'
        )));
    }

    private function build_media_replacement_no_candidate_response(array $state, $reset_requested = false)
    {
        $state = $this->normalize_media_replacement_job_state($state);
        $job_id = !empty($state['job_id']) ? sanitize_key((string) $state['job_id']) : '';
        $converted_count = $this->get_media_replacement_converted_attachment_count();
        $message = $converted_count > 0
            ? __('No JPG/PNG attachment candidates were found. The Media Library appears to already contain AVIF/WebP attachment metadata, so restore the database backup or run attachment metadata rollback before restarting the replacement plan.', 'ultracache')
            : __('No JPG/PNG attachment candidates were found for Media Library replacement.', 'ultracache');

        if ($reset_requested && '' !== $job_id) {
            $message = $converted_count > 0
                ? __('Restart Replacement Plan was blocked because no JPG/PNG attachment candidates were found. The existing registry was left untouched. Restore the database backup or roll back attachment metadata before restarting.', 'ultracache')
                : __('Restart Replacement Plan was blocked because no JPG/PNG attachment candidates were found. The existing registry was left untouched.', 'ultracache');
        }

        return array(
            'success'             => true,
            'message'             => $message,
            'version'             => self::MEDIA_REPLACEMENT_DB_VERSION,
            'jobId'               => $job_id,
            'status'              => 'blocked_no_candidates',
            'blocked'             => true,
            'restartBlocked'      => (bool) $reset_requested,
            'hasMore'             => false,
            'batchSize'           => 0,
            'batchScanned'        => 0,
            'nextCursor'          => 0,
            'totalCandidates'     => 0,
            'convertedCandidates' => $converted_count,
            'progressPercent'     => 100,
            'scanned'             => 0,
            'matched'             => 0,
            'missingGenerated'    => 0,
            'skipped'             => 0,
            'failed'              => 0,
            'oldTotalSize'        => 0,
            'targetTotalSize'     => 0,
            'registryOnly'        => true,
            'chunked'             => true,
            'nextStep'            => __('Restore the database backup or roll back attachment metadata, then run Restart Replacement Plan again.', 'ultracache'),
        );
    }

    private function get_media_replacement_candidate_attachment_ids_batch($after_id = 0, $limit = 250)
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return array();
        }

        $after_id = max(0, absint($after_id));
        $limit    = max(1, min(1000, absint($limit)));

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = %s
               AND p.post_mime_type IN ('image/jpeg', 'image/png')
               AND pm.meta_key = %s
               AND p.ID > %d
             ORDER BY p.ID ASC
             LIMIT %d",
            'attachment',
            '_wp_attached_file',
            $after_id,
            $limit
        ));

        return array_values(array_unique(array_map('absint', (array) $ids)));
    }

    private function get_media_replacement_default_scan_stats()
    {
        return array(
            'scanned'          => 0,
            'matched'          => 0,
            'missingGenerated' => 0,
            'skipped'          => 0,
            'failed'           => 0,
            'oldTotalSize'     => 0,
            'targetTotalSize'  => 0,
        );
    }

    private function normalize_media_replacement_job_state(array $state)
    {
        $defaults = array_merge(array(
            'orchestration_version' => self::MEDIA_REPLACEMENT_ORCHESTRATION_VERSION,
            'job_id'               => '',
            'generation'           => '',
            'target_format'        => 'webp',
            'fallback_format'      => 'original',
            'status'               => 'idle',
            'run_status'           => 'idle',
            'active_step'          => '',
            'cursor_attachment_id' => 0,
            'total_candidates'     => 0,
            'created_at'           => '',
            'started_at'           => '',
            'updated_at'           => '',
            'heartbeat_at'         => '',
            'paused_at'            => '',
            'completed_at'         => '',
            'last_error'           => '',
            'workflow_stage'       => 'prepare',
            'workflow_message'     => '',
            'workflow_updated_at'  => '',
            'workflow_verified_at' => '',
            'confirmation_tokens'  => array(),
            'readiness_generation' => '',
            'readiness_completed_at' => '',
            'prepare_started_at'     => '',
            'prepare_completed_at'   => '',
            'do_started_at'          => '',
            'do_completed_at'        => '',
            'verify_started_at'      => '',
            'verify_completed_at'    => '',
            'delete_started_at'      => '',
            'delete_completed_at'    => '',
            'verify_destination_cursor_item_id' => 0,
            'verify_destination_checked'        => 0,
            'verify_destination_failed'         => 0,
            'verify_metadata_cursor_item_id'    => 0,
            'verify_metadata_checked'           => 0,
            'verify_metadata_failed'            => 0,
            'verify_cleanup_ready'              => false,
            'verify_cleanup_candidates'         => 0,
            'verify_cleanup_blocked_items'       => 0,
            'verify_cleanup_potential_free_bytes'=> 0,
            'verify_cleanup_blockers'            => array(),
            'validation_cursor_item_id' => 0,
            'validated_items'        => 0,
            'validation_failed'      => 0,
            'pre_do_validation_cursor_item_id' => 0,
            'pre_do_validated_items'        => 0,
            'pre_do_validation_failed'      => 0,
            'pre_do_guard_completed_at'     => '',
            'pre_do_plan_fingerprint'       => '',
        ), $this->get_media_replacement_default_scan_stats());

        $state = array_merge($defaults, $state);
        $state['orchestration_version'] = self::MEDIA_REPLACEMENT_ORCHESTRATION_VERSION;
        $state['job_id']               = sanitize_key((string) $state['job_id']);
        $state['generation']           = sanitize_key((string) $state['generation']);
        $state['target_format']        = in_array((string) $state['target_format'], array('avif', 'webp'), true) ? (string) $state['target_format'] : 'webp';
        $state['fallback_format']      = ('avif' === $state['target_format'] && 'webp' === (string) $state['fallback_format']) ? 'webp' : 'original';
        $state['status']               = in_array((string) $state['status'], array('idle', 'scanning', 'copying', 'validating', 'planning_metadata', 'scanning_database', 'matching_database', 'planning_database', 'scanning_theme', 'planning_theme', 'validating_pre_do', 'applying_metadata', 'applying_database', 'applying_theme', 'verifying_files', 'verifying_metadata', 'verifying_database', 'verifying_theme', 'planning_cleanup', 'deleting_originals', 'completed', 'failed'), true) ? (string) $state['status'] : 'idle';
        $state['run_status']           = in_array((string) $state['run_status'], array('idle', 'running', 'paused', 'failed', 'completed'), true) ? (string) $state['run_status'] : 'idle';
        $state['active_step']          = sanitize_key((string) $state['active_step']);
        $state['last_error']           = sanitize_text_field((string) $state['last_error']);
        $state['workflow_stage']       = in_array((string) $state['workflow_stage'], array('prepare', 'do', 'verify', 'delete', 'complete'), true) ? (string) $state['workflow_stage'] : 'prepare';
        $state['workflow_message']     = sanitize_text_field((string) $state['workflow_message']);
        $state['workflow_updated_at']  = sanitize_text_field((string) $state['workflow_updated_at']);
        $state['workflow_verified_at'] = sanitize_text_field((string) $state['workflow_verified_at']);
        $state['readiness_generation'] = sanitize_key((string) $state['readiness_generation']);
        $state['readiness_completed_at'] = sanitize_text_field((string) $state['readiness_completed_at']);
        $state['prepare_started_at']     = sanitize_text_field((string) $state['prepare_started_at']);
        $state['prepare_completed_at']   = sanitize_text_field((string) $state['prepare_completed_at']);
        $state['do_started_at']          = sanitize_text_field((string) $state['do_started_at']);
        $state['do_completed_at']        = sanitize_text_field((string) $state['do_completed_at']);
        $state['verify_started_at']      = sanitize_text_field((string) $state['verify_started_at']);
        $state['verify_completed_at']    = sanitize_text_field((string) $state['verify_completed_at']);
        $state['delete_started_at']      = sanitize_text_field((string) $state['delete_started_at']);
        $state['delete_completed_at']    = sanitize_text_field((string) $state['delete_completed_at']);
        $state['verify_destination_cursor_item_id'] = max(0, absint($state['verify_destination_cursor_item_id']));
        $state['verify_destination_checked']        = max(0, (int) $state['verify_destination_checked']);
        $state['verify_destination_failed']         = max(0, (int) $state['verify_destination_failed']);
        $state['verify_metadata_cursor_item_id']    = max(0, absint($state['verify_metadata_cursor_item_id']));
        $state['verify_metadata_checked']           = max(0, (int) $state['verify_metadata_checked']);
        $state['verify_metadata_failed']            = max(0, (int) $state['verify_metadata_failed']);
        $state['verify_cleanup_ready']              = !empty($state['verify_cleanup_ready']);
        $state['verify_cleanup_candidates']         = max(0, (int) $state['verify_cleanup_candidates']);
        $state['verify_cleanup_blocked_items']       = max(0, (int) $state['verify_cleanup_blocked_items']);
        $state['verify_cleanup_potential_free_bytes']= max(0, (int) $state['verify_cleanup_potential_free_bytes']);
        $cleanup_blockers = array();
        foreach ((array) $state['verify_cleanup_blockers'] as $blocker) {
            if (!is_array($blocker)) {
                continue;
            }
            $cleanup_blockers[] = array(
                'code'    => sanitize_key((string) ($blocker['code'] ?? '')),
                'message' => sanitize_text_field((string) ($blocker['message'] ?? '')),
                'count'   => max(0, (int) ($blocker['count'] ?? 0)),
            );
        }
        $state['verify_cleanup_blockers'] = $cleanup_blockers;
        $state['validation_cursor_item_id'] = max(0, absint($state['validation_cursor_item_id']));
        $state['validated_items']        = max(0, (int) $state['validated_items']);
        $state['validation_failed']      = max(0, (int) $state['validation_failed']);
        $state['pre_do_validation_cursor_item_id'] = max(0, absint($state['pre_do_validation_cursor_item_id']));
        $state['pre_do_validated_items']        = max(0, (int) $state['pre_do_validated_items']);
        $state['pre_do_validation_failed']      = max(0, (int) $state['pre_do_validation_failed']);
        $state['pre_do_guard_completed_at']     = sanitize_text_field((string) $state['pre_do_guard_completed_at']);
        $state['pre_do_plan_fingerprint']       = sanitize_text_field((string) $state['pre_do_plan_fingerprint']);
        foreach (array('created_at', 'started_at', 'updated_at', 'heartbeat_at', 'paused_at', 'completed_at') as $timestamp_key) {
            $state[$timestamp_key] = sanitize_text_field((string) $state[$timestamp_key]);
        }
        $state['confirmation_tokens']  = $this->normalize_media_replacement_confirmation_tokens(isset($state['confirmation_tokens']) ? $state['confirmation_tokens'] : array());
        $state['cursor_attachment_id'] = max(0, absint($state['cursor_attachment_id']));
        $state['total_candidates']     = max(0, (int) $state['total_candidates']);

        foreach (array_keys($this->get_media_replacement_default_scan_stats()) as $key) {
            $state[$key] = max(0, (int) $state[$key]);
        }

        return $state;
    }

    private function update_media_replacement_active_job_state(array $state)
    {
        $state = $this->normalize_media_replacement_job_state($state);
        update_option($this->get_media_replacement_active_job_option_name(), $state, false);
        return $state;
    }

    private function insert_media_replacement_scan_item($job_id, $attachment_id, array $data)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return false;
        }

        $now = current_time('mysql', true);
        $row = array_merge(array(
            'job_id'             => sanitize_key((string) $job_id),
            'attachment_id'      => absint($attachment_id),
            'item_scope'         => 'main',
            'size_name'          => '',
            'old_path_hash'      => '',
            'source_format'      => '',
            'target_format'      => '',
            'fallback_format'    => '',
            'old_relative_path'  => '',
            'old_url'            => '',
            'old_file_path'      => '',
            'generated_file_path'=> '',
            'new_relative_path'  => '',
            'new_url'            => '',
            'new_file_path'      => '',
            'old_mime'           => '',
            'new_mime'           => '',
            'old_size'           => 0,
            'new_size'           => 0,
            'old_metadata_json'  => '',
            'new_metadata_json'  => '',
            'status'             => 'pending',
            'error_message'      => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ), $data);

        $row['job_id']        = sanitize_key((string) $row['job_id']);
        $row['attachment_id'] = absint($row['attachment_id']);
        $row['item_scope']    = in_array((string) $row['item_scope'], array('main', 'intermediate'), true) ? (string) $row['item_scope'] : 'main';
        $row['size_name']     = substr(sanitize_key((string) $row['size_name']), 0, 64);
        if ('main' === $row['item_scope']) {
            $row['size_name'] = '';
        }
        $row['old_path_hash'] = '' !== (string) $row['old_path_hash'] ? substr(sanitize_text_field((string) $row['old_path_hash']), 0, 32) : md5((string) $row['old_relative_path']);
        $row['old_size']      = max(0, (int) $row['old_size']);
        $row['new_size']      = max(0, (int) $row['new_size']);
        $row['status']        = in_array($row['status'], array('pending', 'matched', 'copied', 'metadata_ready', 'metadata_updated', 'refs_scanned', 'cleanup_deleted', 'cleanup_failed', 'metadata_restored', 'metadata_failed', 'metadata_rollback_failed', 'skipped', 'failed'), true) ? $row['status'] : 'pending';

        $format = array(
            '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s'
        );

        return false !== $wpdb->replace($items_table, $row, $format);
    }

    private function get_media_replacement_registry_unique_variant_count($job_id)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $job_id = sanitize_key((string) $job_id);
        if ('' === $items_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return 0;
        }

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT attachment_id, old_path_hash) FROM %i WHERE job_id = %s AND status = 'matched' AND old_path_hash <> ''",
                $items_table,
                $job_id
            )
        );

        return max(0, (int) $count);
    }

    private function scan_media_replacement_attachment_item($job_id, $attachment_id, $target_format, $fallback_format)
    {
        $stats = $this->get_media_replacement_default_scan_stats();
        $stats['scanned'] = 1;

        $attachment_id = absint($attachment_id);
        if ($attachment_id <= 0) {
            $stats['failed'] = 1;
            return $stats;
        }

        $source_file = get_attached_file($attachment_id);
        $old_mime    = (string) get_post_mime_type($attachment_id);
        $source_file = is_string($source_file) ? wp_normalize_path($source_file) : '';
        $source_format = preg_match('/\.png$/i', $source_file) ? 'png' : 'jpeg';

        if ('' === $source_file || !preg_match('/\.(?:jpe?g|png)$/i', $source_file) || !$this->optimized_storage_readable_source_exists($source_file)) {
            $inserted = $this->insert_media_replacement_scan_item($job_id, $attachment_id, array(
                'source_format'   => $source_format,
                'target_format'   => $target_format,
                'fallback_format' => $fallback_format,
                'old_file_path'   => $source_file,
                'old_mime'        => $old_mime,
                'new_mime'        => $this->get_media_replacement_mime_for_format($target_format),
                'status'          => 'skipped',
                'error_message'   => __('Attachment source file is missing, unreadable, or not a JPG/PNG image.', 'ultracache'),
            ));

            if ($inserted) {
                $stats['skipped'] = 1;
            } else {
                $stats['failed'] = 1;
            }

            return $stats;
        }

        $relative_path = $this->get_uploads_relative_path_from_source($source_file);
        if (!$relative_path) {
            $inserted = $this->insert_media_replacement_scan_item($job_id, $attachment_id, array(
                'source_format'   => $source_format,
                'target_format'   => $target_format,
                'fallback_format' => $fallback_format,
                'old_file_path'   => $source_file,
                'old_mime'        => $old_mime,
                'new_mime'        => $this->get_media_replacement_mime_for_format($target_format),
                'status'          => 'skipped',
                'error_message'   => __('Attachment source file is outside the WordPress uploads directory.', 'ultracache'),
            ));

            if ($inserted) {
                $stats['skipped'] = 1;
            } else {
                $stats['failed'] = 1;
            }

            return $stats;
        }

        $generated_path = $this->get_media_replacement_generated_path_for_source($source_file, $target_format);
        $old_size       = function_exists('ultracache_safe_filesize') ? (int) ultracache_safe_filesize($source_file, 'media_replacement_scan_source_size') : 0;
        $metadata       = wp_get_attachment_metadata($attachment_id);
        $metadata_json  = function_exists('wp_json_encode') ? wp_json_encode(is_array($metadata) ? $metadata : array()) : '{}';
        $target_size    = 0;
        $status         = 'matched';
        $error_message  = null;

        if (!$generated_path || !$this->optimized_storage_path_exists($generated_path, true) || !$this->is_valid_generated_media_file($generated_path, $target_format, 'media_replacement_scan_generated_validate')) {
            $status = 'skipped';
            $error_message = sprintf(
                /* translators: %s: target image format. */
                __('No valid UltraCache generated %s file was found for this attachment.', 'ultracache'),
                strtoupper($target_format)
            );
        } else {
            $target_size = function_exists('ultracache_safe_filesize') ? (int) ultracache_safe_filesize($generated_path, 'media_replacement_scan_generated_size') : 0;
        }

        $inserted = $this->insert_media_replacement_scan_item($job_id, $attachment_id, array(
            'source_format'       => $source_format,
            'target_format'       => $target_format,
            'fallback_format'     => $fallback_format,
            'item_scope'          => 'main',
            'size_name'           => '',
            'old_path_hash'       => md5($relative_path),
            'old_relative_path'   => $relative_path,
            'old_url'             => $this->build_media_replacement_public_url($relative_path),
            'old_file_path'       => $source_file,
            'generated_file_path' => $generated_path ? wp_normalize_path((string) $generated_path) : '',
            'old_mime'            => $old_mime,
            'new_mime'            => $this->get_media_replacement_mime_for_format($target_format),
            'old_size'            => $old_size,
            'new_size'            => $target_size,
            'old_metadata_json'   => is_string($metadata_json) ? $metadata_json : '{}',
            'status'              => $status,
            'error_message'       => $error_message,
        ));

        if (!$inserted) {
            $stats['failed'] = 1;
            return $stats;
        }

        if ('matched' === $status) {
            $stats['matched'] = 1;
            $stats['oldTotalSize'] = max(0, $old_size);
            $stats['targetTotalSize'] = max(0, $target_size);
        } else {
            $stats['missingGenerated'] = 1;
            $stats['skipped'] = 1;
        }

        $intermediate_stats = $this->expand_media_replacement_item_intermediate_sizes(array(
            'job_id'             => $job_id,
            'attachment_id'      => $attachment_id,
            'item_scope'         => 'main',
            'size_name'          => '',
            'source_format'      => $source_format,
            'target_format'      => $target_format,
            'fallback_format'    => $fallback_format,
            'old_relative_path'  => $relative_path,
            'old_url'            => $this->build_media_replacement_public_url($relative_path),
            'old_file_path'      => $source_file,
            'old_mime'           => $old_mime,
            'old_metadata_json'  => is_string($metadata_json) ? $metadata_json : '{}',
            'status'             => $status,
        ));

        $stats['matched']          += isset($intermediate_stats['matched_sizes']) ? max(0, (int) $intermediate_stats['matched_sizes']) : 0;
        $stats['missingGenerated'] += isset($intermediate_stats['missing_generated']) ? max(0, (int) $intermediate_stats['missing_generated']) : 0;
        $stats['skipped']          += isset($intermediate_stats['skipped_sizes']) ? max(0, (int) $intermediate_stats['skipped_sizes']) : 0;
        $stats['failed']           += isset($intermediate_stats['failed_sizes']) ? max(0, (int) $intermediate_stats['failed_sizes']) : 0;
        $stats['oldTotalSize']     += isset($intermediate_stats['old_total_size']) ? max(0, (int) $intermediate_stats['old_total_size']) : 0;
        $stats['targetTotalSize']  += isset($intermediate_stats['target_total_size']) ? max(0, (int) $intermediate_stats['target_total_size']) : 0;

        return $stats;
    }

    public function scan_media_library_replacement_eligible_items($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array(
                'success' => false,
                'message' => __('Media Library replacement registry tables could not be created.', 'ultracache'),
            );
        }

        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'reset_settings_cache')) {
            Ultra_Cache_WP::reset_settings_cache();
        }

        $args = is_array($args) ? $args : array();
        list($target_format, $fallback_format) = $this->get_media_replacement_current_output_policy();

        $requested_job_id = isset($args['job_id']) ? sanitize_key((string) $args['job_id']) : '';
        $reset             = !empty($args['reset']) || !empty($args['start']);
        $batch_size        = isset($args['limit']) ? absint($args['limit']) : 100;
        $batch_size        = max(1, min(250, $batch_size));
        $time_budget       = isset($args['time_budget']) && (float) $args['time_budget'] > 0 ? (float) $args['time_budget'] : 15.0;
        $time_budget       = max(1.0, min(30.0, $time_budget));
        $saved             = $this->normalize_media_replacement_job_state($this->get_media_replacement_active_job_data());
        $start_new         = $reset
            || '' === $saved['job_id']
            || ('' !== $requested_job_id && $requested_job_id !== $saved['job_id'])
            || $target_format !== $saved['target_format']
            || $fallback_format !== $saved['fallback_format'];
        $start_guard = null;

        if ($start_new) {
            $start_guard = $this->get_media_library_replacement_start_guard(array(
                'generation' => sanitize_key((string) ($args['readiness_generation'] ?? $args['readinessGeneration'] ?? '')),
            ));
            if (empty($start_guard['allowed'])) {
                return array(
                    'success'    => false,
                    'blocked'    => true,
                    'status'     => 'replacement_readiness_blocked',
                    'message'    => (string) ($start_guard['message'] ?? __('Media Library replacement readiness validation failed.', 'ultracache')),
                    'hasMore'    => false,
                    'startGuard' => $start_guard,
                    'readiness'  => (array) ($start_guard['readiness'] ?? array()),
                );
            }

            $candidate_count = $this->get_media_replacement_candidate_attachment_count();
            if ($candidate_count <= 0) {
                return $this->build_media_replacement_no_candidate_response($saved, $reset);
            }

            if ('' !== $saved['job_id']) {
                $this->reset_media_replacement_active_job_for_restart($saved);
            }

            $job_id = $this->get_media_replacement_job_id($target_format, $fallback_format, true);
            $this->reset_media_replacement_job_registry($job_id);
            $now = current_time('mysql', true);
            $state = $this->update_media_replacement_active_job_state(array(
                'job_id'               => $job_id,
                'generation'           => sanitize_key('replace-' . gmdate('Ymd-His') . '-' . strtolower((string) wp_generate_password(5, false, false))),
                'target_format'        => $target_format,
                'fallback_format'      => $fallback_format,
                'status'               => 'scanning',
                'run_status'           => 'running',
                'active_step'          => 'registry_scan',
                'cursor_attachment_id' => 0,
                'total_candidates'     => $candidate_count,
                'created_at'           => $now,
                'started_at'           => $now,
                'prepare_started_at'   => $now,
                'updated_at'           => $now,
                'heartbeat_at'         => $now,
                'completed_at'         => '',
                'prepare_completed_at' => '',
                'validation_cursor_item_id' => 0,
                'validated_items'      => 0,
                'validation_failed'    => 0,
                'pre_do_validation_cursor_item_id' => 0,
                'pre_do_validated_items'      => 0,
                'pre_do_validation_failed'    => 0,
                'pre_do_guard_completed_at'   => '',
                'pre_do_plan_fingerprint'     => '',
                'readiness_generation' => sanitize_key((string) ($start_guard['generation'] ?? '')),
                'readiness_completed_at' => sanitize_text_field((string) (($start_guard['readiness']['completedAt'] ?? ''))),
                'workflow_stage'       => 'prepare',
                'workflow_message'     => __('Prepare is building the replacement registry.', 'ultracache'),
                'workflow_updated_at'  => $now,
            ));
        } else {
            $state = $saved;
            if ('failed' === $state['run_status'] || 'failed' === $state['status']) {
                return array(
                    'success' => false,
                    'blocked' => true,
                    'status'  => 'prepare_failed',
                    'message' => $state['last_error'] ?: __('Prepare failed. Restart Prepare to build a clean replacement plan.', 'ultracache'),
                    'jobId'   => $state['job_id'],
                    'hasMore' => false,
                );
            }
            $state['status'] = 'scanning';
            $state['run_status'] = 'running';
            $state['active_step'] = 'registry_scan';
            $state['heartbeat_at'] = current_time('mysql', true);
            $state['updated_at'] = current_time('mysql', true);
            $state = $this->update_media_replacement_active_job_state($state);
        }

        $job_id = sanitize_key((string) $state['job_id']);
        if ('' === $job_id) {
            return array(
                'success' => false,
                'message' => __('Media Library replacement job could not be initialized.', 'ultracache'),
            );
        }

        $deadline = microtime(true) + $time_budget;
        $ids = $this->get_media_replacement_candidate_attachment_ids_batch($state['cursor_attachment_id'], $batch_size);
        $batch_stats = $this->get_media_replacement_default_scan_stats();
        $last_id = $state['cursor_attachment_id'];
        $processed_ids = 0;

        foreach ($ids as $attachment_id) {
            if ($processed_ids > 0 && microtime(true) >= $deadline) {
                break;
            }

            $attachment_id = absint($attachment_id);
            if ($attachment_id <= 0) {
                continue;
            }

            $item_stats = $this->scan_media_replacement_attachment_item($job_id, $attachment_id, $target_format, $fallback_format);
            foreach (array_keys($batch_stats) as $key) {
                $batch_stats[$key] += isset($item_stats[$key]) ? max(0, (int) $item_stats[$key]) : 0;
            }
            $last_id = max($last_id, $attachment_id);
            $processed_ids++;
        }

        foreach (array_keys($this->get_media_replacement_default_scan_stats()) as $key) {
            $state[$key] += $batch_stats[$key];
        }
        $state['cursor_attachment_id'] = $last_id;

        $has_more = !empty($this->get_media_replacement_candidate_attachment_ids_batch($last_id, 1));
        $state['heartbeat_at'] = current_time('mysql', true);
        $state['updated_at'] = current_time('mysql', true);

        if (!$has_more) {
            $readiness = $this->get_media_library_replacement_readiness_status();
            $current_candidate_count = $this->get_media_replacement_candidate_attachment_count();
            $readiness_generation_matches = !empty($readiness['generation']) && hash_equals((string) $readiness['generation'], (string) $state['readiness_generation']);
            $expected_variants = max(0, (int) ($readiness['requiredVariants'] ?? 0));
            $unique_registry_variants = $this->get_media_replacement_registry_unique_variant_count($job_id);
            $registry_mismatch = !$readiness_generation_matches
                || $current_candidate_count !== (int) $state['total_candidates']
                || (int) $state['scanned'] !== (int) $state['total_candidates']
                || $expected_variants <= 0
                || $unique_registry_variants !== $expected_variants;

            if ($state['missingGenerated'] > 0 || $state['skipped'] > 0 || $state['failed'] > 0 || $registry_mismatch) {
                $state['status'] = 'failed';
                $state['run_status'] = 'failed';
                $state['active_step'] = 'prepare_failed';
                $state['last_error'] = sprintf(
                    /* translators: 1: readiness generation match, 2: current candidates, 3: planned candidates, 4: scanned candidates, 5: unique registry variants, 6: readiness variants, 7: metadata mapping rows. */
                    __('Prepare consistency check failed. Generation match: %1$s · candidates current/planned/scanned: %2$d/%3$d/%4$d · unique variants registry/readiness: %5$d/%6$d · metadata mappings: %7$d.', 'ultracache'),
                    $readiness_generation_matches ? __('yes', 'ultracache') : __('no', 'ultracache'),
                    (int) $current_candidate_count,
                    (int) $state['total_candidates'],
                    (int) $state['scanned'],
                    (int) $unique_registry_variants,
                    (int) $expected_variants,
                    (int) $state['matched']
                );
            } else {
                $state['status'] = 'copying';
                $state['run_status'] = 'running';
                $state['active_step'] = 'copy';
                $state['workflow_message'] = __('Replacement registry is complete. Prepare is copying and validating destination files.', 'ultracache');
                $state['workflow_updated_at'] = current_time('mysql', true);
            }
        }
        $state = $this->update_media_replacement_active_job_state($state);

        $progress = $state['total_candidates'] > 0 ? min(100, round(($state['scanned'] / $state['total_candidates']) * 100, 1)) : 100;
        $success = 'failed' !== $state['run_status'];
        $message = !$success
            ? $state['last_error']
            : ($has_more
                ? sprintf(
                    /* translators: %1$d: scanned attachment count; %2$d: total candidate attachment count. */
                    __('Replacement registry scan: %1$d of %2$d JPG/PNG attachments scanned.', 'ultracache'),
                    (int) $state['scanned'],
                    (int) $state['total_candidates']
                )
                : sprintf(
                    /* translators: %1$d: registry mapping count; %2$d: scanned attachment count. */
                    __('Replacement registry contains %1$d main/intermediate file mappings from %2$d attachments.', 'ultracache'),
                    (int) $state['matched'],
                    (int) $state['scanned']
                ));

        return array(
            'success'          => $success,
            'message'          => $message,
            'version'          => self::MEDIA_REPLACEMENT_DB_VERSION,
            'jobId'            => $job_id,
            'generation'       => $state['generation'],
            'targetFormat'     => $target_format,
            'fallbackFormat'   => $fallback_format,
            'status'           => $state['status'],
            'activeStep'       => $state['active_step'],
            'hasMore'          => $has_more,
            'batchSize'        => $batch_size,
            'batchScanned'     => (int) $batch_stats['scanned'],
            'nextCursor'       => (int) $state['cursor_attachment_id'],
            'totalCandidates'  => (int) $state['total_candidates'],
            'progressPercent'  => $progress,
            'scanned'          => (int) $state['scanned'],
            'matched'          => (int) $state['matched'],
            'uniqueMatchedVariants' => isset($unique_registry_variants) ? (int) $unique_registry_variants : 0,
            'missingGenerated' => (int) $state['missingGenerated'],
            'skipped'          => (int) $state['skipped'],
            'failed'           => (int) $state['failed'],
            'oldTotalSize'     => (int) $state['oldTotalSize'],
            'targetTotalSize'  => (int) $state['targetTotalSize'],
            'registryOnly'     => true,
            'chunked'          => true,
            'readinessGeneration' => (string) $state['readiness_generation'],
            'startGuard'       => is_array($start_guard) ? $start_guard : null,
            'nextStep'         => $has_more
                ? __('Continue the server-backed registry scan.', 'ultracache')
                : ($success ? __('The next Prepare chunk copies replacement files into their final WordPress uploads locations.', 'ultracache') : __('Restart Prepare after resolving the registry mismatch.', 'ultracache')),
        );
    }


    private function get_media_replacement_intermediate_expand_option_name()
    {
        return 'ultracache_media_replacement_intermediate_expand_v1';
    }

    private function get_media_replacement_intermediate_expand_state()
    {
        $state = get_option($this->get_media_replacement_intermediate_expand_option_name(), array());
        return is_array($state) ? $state : array();
    }

    private function update_media_replacement_intermediate_expand_state(array $state)
    {
        $defaults = array(
            'job_id'             => '',
            'status'             => 'idle',
            'cursor_item_id'     => 0,
            'processed_items'    => 0,
            'scanned_sizes'      => 0,
            'matched_sizes'      => 0,
            'existing_sizes'     => 0,
            'missing_generated'  => 0,
            'skipped_sizes'      => 0,
            'failed_sizes'       => 0,
            'old_total_size'     => 0,
            'target_total_size'  => 0,
            'created_at'         => '',
            'updated_at'         => '',
            'completed_at'       => '',
        );

        $state = array_merge($defaults, $state);
        $state['job_id']         = sanitize_key((string) $state['job_id']);
        $state['status']         = in_array((string) $state['status'], array('idle', 'expanding', 'completed', 'failed'), true) ? (string) $state['status'] : 'idle';
        $state['cursor_item_id'] = max(0, absint($state['cursor_item_id']));

        foreach (array('processed_items', 'scanned_sizes', 'matched_sizes', 'existing_sizes', 'missing_generated', 'skipped_sizes', 'failed_sizes', 'old_total_size', 'target_total_size') as $key) {
            $state[$key] = max(0, (int) $state[$key]);
        }

        update_option($this->get_media_replacement_intermediate_expand_option_name(), $state, false);
        return $state;
    }

    private function get_media_replacement_main_items_for_intermediate_expand($job_id, $after_item_id = 0, $limit = 50)
    {
        global $wpdb;

        $items_table   = $this->get_media_replacement_items_table_name();
        $job_id        = sanitize_key((string) $job_id);
        $after_item_id = max(0, absint($after_item_id));
        $limit         = max(1, min(250, absint($limit)));

        if ('' === $items_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, attachment_id, item_scope, size_name, source_format, target_format, fallback_format, old_relative_path, old_url, old_file_path, old_mime, old_metadata_json, status FROM %i WHERE job_id = %s AND item_scope = %s AND id > %d AND status IN (%s, %s, %s, %s, %s) ORDER BY id ASC LIMIT %d',
                $items_table,
                $job_id,
                'main',
                $after_item_id,
                'matched',
                'copied',
                'metadata_ready',
                'metadata_updated',
                'refs_scanned',
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private function get_media_replacement_variant_item_status($job_id, $attachment_id, $item_scope, $size_name)
    {
        global $wpdb;

        $items_table   = $this->get_media_replacement_items_table_name();
        $job_id        = sanitize_key((string) $job_id);
        $attachment_id = absint($attachment_id);
        $item_scope    = in_array((string) $item_scope, array('main', 'intermediate'), true) ? (string) $item_scope : 'main';
        $size_name     = 'intermediate' === $item_scope ? substr(sanitize_key((string) $size_name), 0, 64) : '';

        if ('' === $items_table || '' === $job_id || $attachment_id <= 0 || !($wpdb instanceof wpdb)) {
            return '';
        }

        $status = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT status FROM %i WHERE job_id = %s AND attachment_id = %d AND item_scope = %s AND size_name = %s LIMIT 1',
                $items_table,
                $job_id,
                $attachment_id,
                $item_scope,
                $size_name
            )
        );

        return is_string($status) ? sanitize_key($status) : '';
    }

    private function get_media_replacement_intermediate_size_stats_defaults()
    {
        return array(
            'processed_items'   => 0,
            'scanned_sizes'     => 0,
            'matched_sizes'     => 0,
            'existing_sizes'    => 0,
            'missing_generated' => 0,
            'skipped_sizes'     => 0,
            'failed_sizes'      => 0,
            'old_total_size'    => 0,
            'target_total_size' => 0,
        );
    }

    private function get_media_replacement_relative_size_path($main_relative_path, $size_file)
    {
        $main_relative_path = ltrim(str_replace('\\', '/', (string) $main_relative_path), '/');
        $size_file          = ltrim(str_replace('\\', '/', (string) $size_file), '/');

        if ('' === $main_relative_path || '' === $size_file || false !== strpos($size_file, "\0") || false !== strpos($size_file, '..')) {
            return '';
        }

        foreach (explode('/', $size_file) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return '';
            }
        }

        $directory = wp_normalize_path(dirname($main_relative_path));
        $directory = ('.' === $directory || '/' === $directory) ? '' : trim($directory, '/');

        return ('' !== $directory ? trailingslashit($directory) : '') . $size_file;
    }

    private function expand_media_replacement_item_intermediate_sizes(array $row)
    {
        $stats = $this->get_media_replacement_intermediate_size_stats_defaults();
        $stats['processed_items'] = 1;

        $job_id        = isset($row['job_id']) ? sanitize_key((string) $row['job_id']) : '';
        $attachment_id = isset($row['attachment_id']) ? absint($row['attachment_id']) : 0;
        $target_format = isset($row['target_format']) ? sanitize_key((string) $row['target_format']) : '';
        $fallback      = isset($row['fallback_format']) ? sanitize_key((string) $row['fallback_format']) : '';
        $main_file     = isset($row['old_file_path']) ? wp_normalize_path((string) $row['old_file_path']) : '';
        $main_relative = isset($row['old_relative_path']) ? ltrim(str_replace('\\', '/', (string) $row['old_relative_path']), '/') : '';
        $metadata      = $this->decode_media_replacement_json_array(isset($row['old_metadata_json']) ? (string) $row['old_metadata_json'] : '');

        if ('' === $job_id || $attachment_id <= 0 || !in_array($target_format, array('avif', 'webp'), true) || '' === $main_file || '' === $main_relative || empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
            $stats['skipped_sizes'] = 1;
            return $stats;
        }

        $base_dir = wp_normalize_path(dirname($main_file));
        foreach ($metadata['sizes'] as $raw_size_name => $size_data) {
            if (!is_array($size_data) || empty($size_data['file'])) {
                continue;
            }

            $size_name = substr(sanitize_key((string) $raw_size_name), 0, 64);
            if ('' === $size_name) {
                $stats['skipped_sizes']++;
                continue;
            }

            $existing_status = $this->get_media_replacement_variant_item_status($job_id, $attachment_id, 'intermediate', $size_name);
            if ('' !== $existing_status) {
                $stats['existing_sizes']++;
                continue;
            }

            $size_file = ltrim(str_replace('\\', '/', (string) $size_data['file']), '/');
            if ('' === $size_file || false !== strpos($size_file, "\0") || false !== strpos($size_file, '..') || !preg_match('/\.(?:jpe?g|png)$/i', $size_file)) {
                $stats['skipped_sizes']++;
                continue;
            }

            $source_file = trailingslashit($base_dir) . $size_file;
            $source_file = wp_normalize_path($source_file);
            $relative_path = $this->get_uploads_relative_path_from_source($source_file);
            if (!$relative_path) {
                $relative_path = $this->get_media_replacement_relative_size_path($main_relative, $size_file);
            }

            $stats['scanned_sizes']++;
            $source_format = preg_match('/\.png$/i', $source_file) ? 'png' : 'jpeg';
            $old_mime = !empty($size_data['mime-type']) ? sanitize_mime_type((string) $size_data['mime-type']) : ('png' === $source_format ? 'image/png' : 'image/jpeg');
            $old_size = function_exists('ultracache_safe_filesize') ? (int) ultracache_safe_filesize($source_file, 'media_replacement_intermediate_source_size') : 0;
            $generated_path = $this->get_media_replacement_generated_path_for_source($source_file, $target_format);
            $target_size = 0;
            $status = 'matched';
            $error = null;

            if ('' === $relative_path || !$this->optimized_storage_readable_source_exists($source_file)) {
                $status = 'skipped';
                $error = __('Intermediate source file is missing or outside the WordPress uploads directory.', 'ultracache');
            } elseif (!$generated_path || !$this->optimized_storage_path_exists($generated_path, true) || !$this->is_valid_generated_media_file($generated_path, $target_format, 'media_replacement_intermediate_generated_validate')) {
                $status = 'skipped';
                $error = sprintf(
                    /* translators: %s: target image format. */
                    __('No valid UltraCache generated %s file was found for this intermediate size.', 'ultracache'),
                    strtoupper($target_format)
                );
                $stats['missing_generated']++;
            } else {
                $target_size = function_exists('ultracache_safe_filesize') ? (int) ultracache_safe_filesize($generated_path, 'media_replacement_intermediate_generated_size') : 0;
            }

            $inserted = $this->insert_media_replacement_scan_item($job_id, $attachment_id, array(
                'item_scope'          => 'intermediate',
                'size_name'           => $size_name,
                'old_path_hash'       => md5((string) $relative_path),
                'source_format'       => $source_format,
                'target_format'       => $target_format,
                'fallback_format'     => $fallback,
                'old_relative_path'   => (string) $relative_path,
                'old_url'             => $this->build_media_replacement_public_url($relative_path),
                'old_file_path'       => $source_file,
                'generated_file_path' => $generated_path ? wp_normalize_path((string) $generated_path) : '',
                'old_mime'            => $old_mime,
                'new_mime'            => $this->get_media_replacement_mime_for_format($target_format),
                'old_size'            => max(0, $old_size),
                'new_size'            => max(0, $target_size),
                'old_metadata_json'   => isset($row['old_metadata_json']) ? (string) $row['old_metadata_json'] : '{}',
                'status'              => $status,
                'error_message'       => $error,
            ));

            if (!$inserted) {
                $stats['failed_sizes']++;
                continue;
            }

            if ('matched' === $status) {
                $stats['matched_sizes']++;
                $stats['old_total_size'] += max(0, $old_size);
                $stats['target_total_size'] += max(0, $target_size);
            } else {
                $stats['skipped_sizes']++;
            }
        }

        return $stats;
    }

    public function expand_media_library_replacement_intermediate_sizes($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array(
                'success' => false,
                'message' => __('Media Library replacement registry tables are not available.', 'ultracache'),
            );
        }

        $args = is_array($args) ? $args : array();
        $job_id = $this->get_media_replacement_preview_job_id(isset($args['job_id']) ? (string) $args['job_id'] : '');
        if ('' === $job_id) {
            return array(
                'success' => false,
                'message' => __('Run Prepare Library Replacement before expanding intermediate sizes.', 'ultracache'),
            );
        }

        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        $limit = max(1, min(250, $limit));
        $saved = $this->get_media_replacement_intermediate_expand_state();
        $start_new = !empty($args['reset']) || !empty($args['start']) || empty($saved['job_id']) || $job_id !== sanitize_key((string) $saved['job_id']) || 'completed' === (string) ($saved['status'] ?? '');

        if ($start_new) {
            $state = $this->update_media_replacement_intermediate_expand_state(array(
                'job_id'             => $job_id,
                'status'             => 'expanding',
                'cursor_item_id'     => 0,
                'processed_items'    => 0,
                'scanned_sizes'      => 0,
                'matched_sizes'      => 0,
                'existing_sizes'     => 0,
                'missing_generated'  => 0,
                'skipped_sizes'      => 0,
                'failed_sizes'       => 0,
                'old_total_size'     => 0,
                'target_total_size'  => 0,
                'created_at'         => current_time('mysql', true),
                'updated_at'         => current_time('mysql', true),
                'completed_at'       => '',
            ));
        } else {
            $state = $this->update_media_replacement_intermediate_expand_state(array_merge($saved, array('status' => 'expanding', 'updated_at' => current_time('mysql', true))));
        }

        $rows = $this->get_media_replacement_main_items_for_intermediate_expand($job_id, $state['cursor_item_id'], $limit);
        $batch = $this->get_media_replacement_intermediate_size_stats_defaults();
        $last_id = $state['cursor_item_id'];

        foreach ($rows as $row) {
            $row['job_id'] = $job_id;
            $item_id = isset($row['id']) ? absint($row['id']) : 0;
            $result = $this->expand_media_replacement_item_intermediate_sizes($row);
            foreach (array_keys($batch) as $key) {
                $batch[$key] += isset($result[$key]) ? max(0, (int) $result[$key]) : 0;
            }
            $last_id = max($last_id, $item_id);
        }

        foreach (array('processed_items', 'scanned_sizes', 'matched_sizes', 'existing_sizes', 'missing_generated', 'skipped_sizes', 'failed_sizes', 'old_total_size', 'target_total_size') as $key) {
            $state[$key] += isset($batch[$key]) ? max(0, (int) $batch[$key]) : 0;
        }
        $state['cursor_item_id'] = $last_id;

        $has_more = !empty($this->get_media_replacement_main_items_for_intermediate_expand($job_id, $last_id, 1));
        $state['status'] = $has_more ? 'expanding' : 'completed';
        $state['updated_at'] = current_time('mysql', true);
        if (!$has_more) {
            $state['completed_at'] = current_time('mysql', true);
        }
        $state = $this->update_media_replacement_intermediate_expand_state($state);

        return array(
            'success'                  => true,
            'message'                  => $has_more
                ? sprintf(
                    /* translators: 1: registered intermediate replacements, 2: scanned intermediate sizes. */
                    __('Media Library replacement intermediate-size expansion is in progress: %1$d registered from %2$d scanned sizes.', 'ultracache'),
                    (int) $state['matched_sizes'],
                    (int) $state['scanned_sizes']
                )
                : sprintf(
                    /* translators: 1: registered intermediate replacements, 2: scanned intermediate sizes. */
                    __('Media Library replacement registered %1$d intermediate-size replacements from %2$d scanned sizes.', 'ultracache'),
                    (int) $state['matched_sizes'],
                    (int) $state['scanned_sizes']
                ),
            'jobId'                    => $job_id,
            'status'                   => $has_more ? 'intermediate_expanding' : 'intermediate_expanded',
            'hasMore'                  => $has_more,
            'batchSize'                => $limit,
            'batchIntermediateScanned' => (int) $batch['scanned_sizes'],
            'batchIntermediateMatched' => (int) $batch['matched_sizes'],
            'batchIntermediateExisting'=> (int) $batch['existing_sizes'],
            'intermediateScanned'      => (int) $state['scanned_sizes'],
            'intermediateMatched'      => (int) $state['matched_sizes'],
            'intermediateExisting'     => (int) $state['existing_sizes'],
            'intermediateMissing'      => (int) $state['missing_generated'],
            'intermediateSkipped'      => (int) $state['skipped_sizes'],
            'intermediateFailed'       => (int) $state['failed_sizes'],
            'oldTotalSize'             => (int) $state['old_total_size'],
            'targetTotalSize'          => (int) $state['target_total_size'],
            'progressPercent'          => $has_more ? 50 : 100,
            'nextStep'                 => $has_more
                ? __('Continue expanding intermediate-size mappings in chunks. No files, metadata, or database content are changed.', 'ultracache')
                : __('Next step: copy the newly registered intermediate replacement files into the normal WordPress uploads folders.', 'ultracache'),
        );
    }



}
