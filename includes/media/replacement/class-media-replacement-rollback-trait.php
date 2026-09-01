<?php
/**
 * End-to-end Media Library Replacement rollback orchestration.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Replacement_Rollback_Trait
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private replacement registry tables use validated identifiers.

    private function get_media_replacement_theme_css_rollback_summary()
    {
        global $wpdb;

        $table = $this->get_media_replacement_file_refs_table_name();
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return array(
                'pendingFiles' => 0,
                'restoredFiles' => 0,
                'failedFiles' => 0,
            );
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT
                    COUNT(DISTINCT CASE WHEN status IN (%s, %s, %s) THEN file_path END) AS pending_files,
                    COUNT(DISTINCT CASE WHEN status = %s THEN file_path END) AS restored_files,
                    COUNT(DISTINCT CASE WHEN status = %s THEN file_path END) AS failed_files
                 FROM %i',
                'applied',
                'verified',
                'verify_failed',
                'restored',
                'rollback_failed',
                $table
            ),
            ARRAY_A
        );
        $row = is_array($row) ? $row : array();

        return array(
            'pendingFiles'  => max(0, (int) ($row['pending_files'] ?? 0)),
            'restoredFiles' => max(0, (int) ($row['restored_files'] ?? 0)),
            'failedFiles'   => max(0, (int) ($row['failed_files'] ?? 0)),
        );
    }

    private function get_media_replacement_theme_css_rollback_files($limit)
    {
        global $wpdb;

        $table = $this->get_media_replacement_file_refs_table_name();
        $limit = max(1, min(100, absint($limit)));
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT file_path, MIN(id) AS first_id
                 FROM %i
                 WHERE status IN (%s, %s, %s)
                 GROUP BY file_path
                 ORDER BY first_id ASC
                 LIMIT %d',
                $table,
                'applied',
                'verified',
                'verify_failed',
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private function calculate_media_replacement_theme_css_rollback_checksum($path)
    {
        $path = wp_normalize_path((string) $path);
        if ('' === $path || !is_file($path)) {
            return '';
        }

        $checksum = $this->get_media_replacement_theme_css_checksum_seed();
        $offset = 0;
        $chunk_size = $this->get_media_replacement_theme_css_stream_chunk_size();

        while (true) {
            $read = $this->read_media_replacement_theme_css_stream_chunk(
                $path,
                $offset,
                $chunk_size,
                'media_library_replacement_theme_css_rollback_checksum'
            );
            if (!is_array($read)) {
                return '';
            }

            $chunk = (string) ($read['data'] ?? '');
            $checksum = $this->advance_media_replacement_theme_css_checksum($checksum, $chunk);
            $offset += strlen($chunk);

            if (!empty($read['eof'])) {
                break;
            }
        }

        return $checksum;
    }

    private function get_media_replacement_theme_css_rows_for_rollback($path)
    {
        global $wpdb;

        $table = $this->get_media_replacement_file_refs_table_name();
        $path = wp_normalize_path((string) $path);
        if ('' === $table || '' === $path || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, file_path, backup_file_path, checksum_before, checksum_after, status
                 FROM %i
                 WHERE file_path = %s AND status IN (%s, %s, %s, %s)
                 ORDER BY id ASC',
                $table,
                $path,
                'applied',
                'verified',
                'verify_failed',
                'rollback_failed'
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private function update_media_replacement_theme_css_rollback_file_status($path, $status, $message = '', $clear_backup = false)
    {
        global $wpdb;

        $table = $this->get_media_replacement_file_refs_table_name();
        $path = wp_normalize_path((string) $path);
        if ('' === $table || '' === $path || !($wpdb instanceof wpdb)) {
            return false;
        }

        $data = array(
            'status'        => in_array((string) $status, array('restored', 'rollback_failed'), true) ? (string) $status : 'rollback_failed',
            'error_message' => '' !== (string) $message ? wp_strip_all_tags((string) $message) : null,
            'updated_at'    => current_time('mysql', true),
        );
        $formats = array('%s', '%s', '%s');
        if ($clear_backup) {
            $data['backup_file_path'] = '';
            $formats[] = '%s';
        }

        return false !== $wpdb->update($table, $data, array('file_path' => $path), $formats, array('%s'));
    }

    private function rollback_media_replacement_theme_css_file($path)
    {
        $path = wp_normalize_path((string) $path);
        $rows = $this->get_media_replacement_theme_css_rows_for_rollback($path);
        if ('' === $path || empty($rows)) {
            return array('restored' => false, 'message' => __('No applied Theme CSS replacement rows are available for rollback.', 'ultracache'));
        }

        $first = $rows[0];
        $checksum_before = strtolower((string) ($first['checksum_before'] ?? ''));
        $checksum_after = strtolower((string) ($first['checksum_after'] ?? ''));
        $backup = wp_normalize_path((string) ($first['backup_file_path'] ?? ''));
        if ('' === $backup) {
            $backup = $this->build_media_replacement_theme_css_backup_path($path);
        }

        if (!preg_match('/^[a-f0-9]{32}$/', $checksum_before) || !preg_match('/^[a-f0-9]{32}$/', $checksum_after) || '' === $backup) {
            return array('restored' => false, 'message' => __('Theme CSS rollback plan is missing the prepared checksums or backup path.', 'ultracache'));
        }

        foreach ($rows as $row) {
            if ((string) ($row['checksum_before'] ?? '') !== $checksum_before || (string) ($row['checksum_after'] ?? '') !== $checksum_after) {
                return array('restored' => false, 'message' => __('Theme CSS rollback rows disagree about the prepared file checksums.', 'ultracache'));
            }
        }

        $current_checksum = $this->calculate_media_replacement_theme_css_rollback_checksum($path);
        if ('' === $current_checksum) {
            return array('restored' => false, 'message' => __('The current Theme CSS file could not be checksummed for rollback.', 'ultracache'));
        }

        if (hash_equals($checksum_before, $current_checksum)) {
            if (is_file($backup)) {
                $backup_checksum = $this->calculate_media_replacement_theme_css_rollback_checksum($backup);
                if ('' === $backup_checksum || !hash_equals($checksum_before, $backup_checksum)) {
                    return array('restored' => false, 'message' => __('Theme CSS is already restored, but its UltraCache backup no longer matches the prepared original file.', 'ultracache'));
                }
                if (function_exists('ultracache_safe_unlink') && !ultracache_safe_unlink($backup, 'media_library_replacement_theme_css_rollback_backup_cleanup')) {
                    return array('restored' => false, 'message' => __('Theme CSS is already restored, but its UltraCache backup could not be removed.', 'ultracache'));
                }
            }
            $this->update_media_replacement_theme_css_rollback_file_status($path, 'restored', '', true);
            return array('restored' => true, 'alreadyRestored' => true, 'message' => '');
        }

        if (!hash_equals($checksum_after, $current_checksum)) {
            return array('restored' => false, 'conflict' => true, 'message' => __('The Theme CSS file changed after UltraCache applied the prepared replacement. The newer file was preserved.', 'ultracache'));
        }

        $backup_checksum = $this->calculate_media_replacement_theme_css_rollback_checksum($backup);
        if ('' === $backup_checksum || !hash_equals($checksum_before, $backup_checksum)) {
            return array('restored' => false, 'message' => __('The Theme CSS backup is missing or no longer matches the prepared original file.', 'ultracache'));
        }

        $temp = $this->build_media_replacement_theme_css_temp_path($path, 'rollback');
        $filesystem = function_exists('ultracache_get_wp_filesystem') ? ultracache_get_wp_filesystem() : false;
        if ('' === $temp || !$filesystem || !method_exists($filesystem, 'copy')) {
            return array('restored' => false, 'message' => __('The Theme CSS backup could not be staged for rollback.', 'ultracache'));
        }
        if (!$filesystem->copy($backup, $temp, true, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644)) {
            return array('restored' => false, 'message' => __('The Theme CSS backup could not be staged for rollback.', 'ultracache'));
        }

        $staged_checksum = $this->calculate_media_replacement_theme_css_rollback_checksum($temp);
        if ('' === $staged_checksum || !hash_equals($checksum_before, $staged_checksum)) {
            if (function_exists('ultracache_safe_unlink')) {
                ultracache_safe_unlink($temp, 'media_library_replacement_theme_css_rollback_temp_cleanup');
            }
            return array('restored' => false, 'message' => __('The staged Theme CSS backup failed checksum validation.', 'ultracache'));
        }

        if (!function_exists('ultracache_safe_rename') || !ultracache_safe_rename($temp, $path, 'media_library_replacement_theme_css_rollback_commit')) {
            if (function_exists('ultracache_safe_unlink')) {
                ultracache_safe_unlink($temp, 'media_library_replacement_theme_css_rollback_temp_cleanup');
            }
            return array('restored' => false, 'message' => __('The Theme CSS backup could not be atomically restored.', 'ultracache'));
        }

        $restored_checksum = $this->calculate_media_replacement_theme_css_rollback_checksum($path);
        if ('' === $restored_checksum || !hash_equals($checksum_before, $restored_checksum)) {
            return array('restored' => false, 'message' => __('The restored Theme CSS file does not match its prepared original checksum.', 'ultracache'));
        }

        $inventory = $this->get_media_replacement_theme_css_inventory_row_by_path($path);
        if (!empty($inventory['id'])) {
            $this->update_media_replacement_theme_css_inventory_stream_result(
                absint($inventory['id']),
                'scanned',
                $checksum_before,
                $this->get_media_replacement_theme_css_checksum_scheme(),
                'restored',
                $checksum_before
            );
        }

        if (!$this->update_media_replacement_theme_css_rollback_file_status($path, 'restored', '', false)) {
            return array('restored' => false, 'message' => __('Theme CSS was restored, but its replacement registry rows could not be updated.', 'ultracache'));
        }

        if (function_exists('ultracache_safe_unlink') && is_file($backup)) {
            if (!ultracache_safe_unlink($backup, 'media_library_replacement_theme_css_rollback_backup_cleanup')) {
                return array('restored' => false, 'message' => __('Theme CSS was restored, but its UltraCache backup could not be removed.', 'ultracache'));
            }
        }
        $this->update_media_replacement_theme_css_rollback_file_status($path, 'restored', '', true);

        return array('restored' => true, 'message' => '');
    }

    private function rollback_media_library_replacement_theme_css($limit)
    {
        $files = $this->get_media_replacement_theme_css_rollback_files($limit);
        $restored = 0;
        $failed = 0;

        foreach ($files as $file) {
            $path = wp_normalize_path((string) ($file['file_path'] ?? ''));
            $result = $this->rollback_media_replacement_theme_css_file($path);
            if (!empty($result['restored'])) {
                $restored++;
            } else {
                $failed++;
                $this->update_media_replacement_theme_css_rollback_file_status(
                    $path,
                    'rollback_failed',
                    (string) ($result['message'] ?? __('Theme CSS rollback failed.', 'ultracache')),
                    false
                );
            }
        }

        $summary = $this->get_media_replacement_theme_css_rollback_summary();
        return array(
            'success'       => true,
            'hasMore'       => (int) $summary['pendingFiles'] > 0,
            'batchRestored' => $restored,
            'batchFailed'   => $failed,
            'pendingFiles'  => (int) $summary['pendingFiles'],
            'restoredFiles' => (int) $summary['restoredFiles'],
            'failedFiles'   => (int) $summary['failedFiles'],
        );
    }

    private function get_media_replacement_destination_rollback_summary()
    {
        global $wpdb;

        $table = $this->get_media_replacement_items_table_name();
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return array('pending' => 0);
        }

        return array(
            'pending' => max(0, (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE destination_overwritten = 1 OR destination_backup_path <> ''",
                    $table
                )
            )),
        );
    }

    private function get_media_replacement_destination_rollback_rows($limit)
    {
        global $wpdb;

        $table = $this->get_media_replacement_items_table_name();
        $limit = max(1, min(250, absint($limit)));
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, target_format, generated_file_path, new_file_path, destination_existed, destination_overwritten, destination_previous_size, destination_previous_hash, destination_backup_path, destination_backup_size, destination_backup_hash, destination_published_size, destination_published_hash, status
                 FROM %i
                 WHERE destination_overwritten = 1 OR destination_backup_path <> ''
                 ORDER BY id ASC
                 LIMIT %d",
                $table,
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private function rollback_media_library_replacement_destination_backups($limit)
    {
        $rows = $this->get_media_replacement_destination_rollback_rows($limit);
        $restored = 0;
        $failed = 0;
        $message = '';

        foreach ($rows as $row) {
            $result = $this->restore_media_replacement_destination_backup($row, false);
            if (!empty($result['restored'])) {
                $restored++;
                continue;
            }
            $failed++;
            $message = (string) ($result['message'] ?? __('An overwritten replacement destination backup could not be restored.', 'ultracache'));
            break;
        }

        $summary = $this->get_media_replacement_destination_rollback_summary();
        return array(
            'success'       => 0 === $failed,
            'hasMore'       => (int) $summary['pending'] > 0,
            'batchRestored' => $restored,
            'batchFailed'   => $failed,
            'pending'       => (int) $summary['pending'],
            'message'       => $message,
        );
    }

    private function get_media_replacement_deleted_original_count()
    {
        $summary = $this->get_media_replacement_cleanup_preview_summary();
        return max(0, (int) ($summary['cleanupDeletedItems'] ?? 0));
    }

    public function get_media_library_replacement_rollback_status()
    {
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        $database = $this->get_media_replacement_database_rollback_summary();
        $theme = $this->get_media_replacement_theme_css_rollback_summary();
        $metadata = $this->get_media_replacement_metadata_rollback_summary();
        $destinations = $this->get_media_replacement_destination_rollback_summary();
        $deleted_originals = $this->get_media_replacement_deleted_original_count();

        $database_pending = max(0, (int) ($database['pendingRollback'] ?? 0));
        $database_failed = max(0, (int) ($database['rollbackFailedRefs'] ?? 0));
        $theme_pending = max(0, (int) ($theme['pendingFiles'] ?? 0));
        $theme_failed = max(0, (int) ($theme['failedFiles'] ?? 0));
        $metadata_pending = max(0, (int) ($metadata['pendingMetadataRollback'] ?? 0));
        $metadata_failed = max(0, (int) ($metadata['metadataRollbackFailed'] ?? 0));
        $destination_pending = max(0, (int) ($destinations['pending'] ?? 0));
        $has_work = $database_pending > 0 || $theme_pending > 0 || $metadata_pending > 0 || $destination_pending > 0;
        $failed = $database_failed + $theme_failed + $metadata_failed;
        $active_step = sanitize_key((string) ($state['active_step'] ?? ''));
        $rollback_complete = 'rollback_complete' === $active_step;
        $rollback_running = in_array($active_step, array('rollback_database', 'rollback_theme_css', 'rollback_metadata', 'rollback_files', 'rollback_failed'), true);
        $available = 0 === $deleted_originals && ($has_work || $rollback_running || $rollback_complete);

        $message = '';
        if ($deleted_originals > 0) {
            $message = __('Rollback Replacement is unavailable because Delete Originals has already removed one or more original JPG/PNG files. Use an external backup for a complete pre-replacement restore.', 'ultracache');
        } elseif ($rollback_complete) {
            $message = __('Rollback Replacement is complete. Restart Replacement Plan when you are ready to build a new plan.', 'ultracache');
        } elseif ($failed > 0 || 'rollback_failed' === $active_step) {
            $message = __('Rollback Replacement stopped on a conflict or failed restore. The newer independent content was preserved.', 'ultracache');
        } elseif ($has_work) {
            $message = __('Rollback Replacement can restore the changes made by the current replacement workflow.', 'ultracache');
        } else {
            $message = __('There are no applied replacement changes to roll back.', 'ultracache');
        }

        return array(
            'rollbackAvailable' => $available,
            'rollbackComplete'  => $rollback_complete,
            'rollbackFailed'    => 'rollback_failed' === $active_step || $failed > 0,
            'hasMore'           => $has_work,
            'runStatus'         => (string) ($state['run_status'] ?? 'idle'),
            'activeStep'        => $active_step,
            'deletedOriginals'  => $deleted_originals,
            'pendingDatabaseRefs' => $database_pending,
            'failedDatabaseRefs'  => $database_failed,
            'restoredDatabaseRefs'=> max(0, (int) ($database['restoredRefs'] ?? 0)),
            'pendingThemeCssFiles'=> $theme_pending,
            'failedThemeCssFiles' => $theme_failed,
            'restoredThemeCssFiles'=> max(0, (int) ($theme['restoredFiles'] ?? 0)),
            'pendingMetadata'      => $metadata_pending,
            'failedMetadata'       => $metadata_failed,
            'restoredMetadata'     => max(0, (int) ($metadata['metadataRestored'] ?? 0)),
            'pendingDestinationBackups' => $destination_pending,
            'message'              => $message,
        );
    }

    private function fail_media_library_replacement_rollback($message)
    {
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        $now = current_time('mysql', true);
        $state['status'] = 'rollback_failed';
        $state['run_status'] = 'failed';
        $state['active_step'] = 'rollback_failed';
        $state['workflow_stage'] = 'rollback';
        $state['last_error'] = wp_strip_all_tags((string) $message);
        $state['workflow_message'] = wp_strip_all_tags((string) $message);
        $state['workflow_updated_at'] = $now;
        $state['updated_at'] = $now;
        $this->update_media_replacement_workflow_state($state);

        return array_merge($this->get_media_library_replacement_rollback_status(), array(
            'success' => false,
            'blocked' => true,
            'message' => wp_strip_all_tags((string) $message),
        ));
    }

    private function get_media_replacement_rollback_resume_step()
    {
        $database = $this->get_media_replacement_database_rollback_summary();
        if (max(0, (int) ($database['pendingRollback'] ?? 0)) > 0) {
            return 'rollback_database';
        }
        if (max(0, (int) ($database['rollbackFailedRefs'] ?? 0)) > 0) {
            return 'rollback_failed';
        }

        $theme = $this->get_media_replacement_theme_css_rollback_summary();
        if (max(0, (int) ($theme['pendingFiles'] ?? 0)) > 0) {
            return 'rollback_theme_css';
        }
        if (max(0, (int) ($theme['failedFiles'] ?? 0)) > 0) {
            return 'rollback_failed';
        }

        $metadata = $this->get_media_replacement_metadata_rollback_summary();
        if (max(0, (int) ($metadata['pendingMetadataRollback'] ?? 0)) > 0) {
            return 'rollback_metadata';
        }
        if (max(0, (int) ($metadata['metadataRollbackFailed'] ?? 0)) > 0) {
            return 'rollback_failed';
        }

        $destinations = $this->get_media_replacement_destination_rollback_summary();
        if (max(0, (int) ($destinations['pending'] ?? 0)) > 0) {
            return 'rollback_files';
        }

        return 'rollback_complete';
    }

    private function invalidate_media_replacement_post_rollback_state(array $state)
    {
        $state = $this->normalize_media_replacement_workflow_state($state);

        foreach (array(
            'workflow_verified_at',
            'verify_started_at',
            'verify_completed_at',
            'verified_plan_fingerprint',
            'delete_started_at',
            'delete_completed_at',
            'delete_authorized_at',
            'delete_authorized_fingerprint',
        ) as $key) {
            $state[$key] = '';
        }

        foreach (array(
            'verify_destination_cursor_item_id',
            'verify_destination_checked',
            'verify_destination_failed',
            'verify_metadata_cursor_item_id',
            'verify_metadata_checked',
            'verify_metadata_failed',
            'verify_cleanup_candidates',
            'verify_cleanup_blocked_items',
            'verify_cleanup_potential_free_bytes',
        ) as $key) {
            $state[$key] = 0;
        }

        $state['verify_cleanup_ready'] = false;
        $state['verify_cleanup_blockers'] = array();
        $state['confirmation_tokens'] = array();
        $state['destructive_authorizations'] = array();

        return $state;
    }

    public function run_media_library_replacement_rollback_chunk($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array('success' => false, 'message' => __('Media Library replacement registry tables are not available.', 'ultracache'));
        }

        $args = is_array($args) ? $args : array();
        $session = $this->validate_media_replacement_session_token((string) ($args['session_token'] ?? ''), 'rollback');
        if (empty($session['success'])) {
            return $session;
        }

        if ($this->get_media_replacement_deleted_original_count() > 0) {
            return $this->fail_media_library_replacement_rollback(__('Rollback Replacement is blocked because Delete Originals has already removed original files. Restore an external backup for a complete pre-replacement state.', 'ultracache'));
        }

        $limit = max(1, min(250, absint($args['limit'] ?? 50)));
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        $now = current_time('mysql', true);

        if (!in_array($state['active_step'], array('rollback_database', 'rollback_theme_css', 'rollback_metadata', 'rollback_files'), true)) {
            $resume_step = $this->get_media_replacement_rollback_resume_step();
            if ('rollback_failed' === $resume_step) {
                return $this->fail_media_library_replacement_rollback(__('Rollback Replacement has unresolved failed rows. The conflicting site content was preserved.', 'ultracache'));
            }
            if ('rollback_complete' === $resume_step) {
                $state = $this->invalidate_media_replacement_post_rollback_state($state);
                $state['status'] = 'rollback_complete';
                $state['run_status'] = 'completed';
                $state['active_step'] = 'rollback_complete';
                $state['workflow_stage'] = 'rollback';
                $state['rollback_completed_at'] = '' !== (string) ($state['rollback_completed_at'] ?? '') ? (string) $state['rollback_completed_at'] : $now;
                $state['workflow_message'] = __('Rollback Replacement is complete.', 'ultracache');
                $state['workflow_updated_at'] = $now;
                $state['updated_at'] = $now;
                $this->update_media_replacement_workflow_state($state);
                return array_merge($this->get_media_library_replacement_rollback_status(), array('success' => true, 'hasMore' => false));
            }

            $state['status'] = 'rolling_back';
            $state['run_status'] = 'running';
            $state['active_step'] = $resume_step;
            $state['workflow_stage'] = 'rollback';
            $state['rollback_started_at'] = '' !== (string) ($state['rollback_started_at'] ?? '') ? (string) $state['rollback_started_at'] : $now;
            $state['rollback_completed_at'] = '';
            $state['last_error'] = '';
            $state['workflow_message'] = __('Rollback Replacement is restoring the current replacement workflow.', 'ultracache');
            $state['heartbeat_at'] = $now;
            $state['paused_at'] = '';
            $state['workflow_updated_at'] = $now;
            $state['updated_at'] = $now;
            $state = $this->update_media_replacement_workflow_state($state);
        }

        $active_step = (string) $state['active_step'];
        $result = array('success' => true, 'hasMore' => false);

        if ('rollback_database' === $active_step) {
            $result = $this->rollback_media_library_replacement_database_replacements(array('limit' => $limit));
            if (max(0, (int) ($result['rollbackFailedRefs'] ?? 0)) > 0) {
                return $this->fail_media_library_replacement_rollback(__('Rollback Replacement stopped because one or more database rows changed independently or could not be restored.', 'ultracache'));
            }
        } elseif ('rollback_theme_css' === $active_step) {
            $result = $this->rollback_media_library_replacement_theme_css(min(25, $limit));
            if (max(0, (int) ($result['failedFiles'] ?? 0)) > 0) {
                return $this->fail_media_library_replacement_rollback(__('Rollback Replacement stopped because one or more Theme CSS files changed independently or could not be restored.', 'ultracache'));
            }
        } elseif ('rollback_metadata' === $active_step) {
            $result = $this->rollback_media_library_replacement_metadata_updates(array(
                'limit' => $limit,
                'restore_destination_backups' => false,
            ));
            if (max(0, (int) ($result['metadataRollbackFailed'] ?? 0)) > 0) {
                return $this->fail_media_library_replacement_rollback(__('Rollback Replacement stopped because one or more attachment metadata rows could not be restored.', 'ultracache'));
            }
        } elseif ('rollback_files' === $active_step) {
            $result = $this->rollback_media_library_replacement_destination_backups($limit);
            if (empty($result['success'])) {
                return $this->fail_media_library_replacement_rollback((string) ($result['message'] ?? __('Rollback Replacement stopped because an overwritten destination backup could not be restored.', 'ultracache')));
            }
        }

        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if (empty($result['hasMore'])) {
            $next = $this->get_media_replacement_rollback_resume_step();
            if ('rollback_failed' === $next) {
                return $this->fail_media_library_replacement_rollback(__('Rollback Replacement stopped on a failed restore. The conflicting site content was preserved.', 'ultracache'));
            }
            $state['active_step'] = $next;
            if ('rollback_complete' === $next) {
                $state = $this->invalidate_media_replacement_post_rollback_state($state);
                $state['status'] = 'rollback_complete';
                $state['run_status'] = 'completed';
                $state['rollback_completed_at'] = $now;
                $state['workflow_message'] = __('Rollback Replacement is complete. Restart Replacement Plan before starting another replacement.', 'ultracache');
            } else {
                $state['status'] = 'rolling_back';
                $state['run_status'] = 'running';
                $state['workflow_message'] = __('Rollback Replacement advanced to the next restore phase.', 'ultracache');
            }
        } else {
            $state['status'] = 'rolling_back';
            $state['run_status'] = 'running';
            $state['workflow_message'] = (string) ($result['message'] ?? __('Rollback Replacement is in progress.', 'ultracache'));
        }
        $state['workflow_stage'] = 'rollback';
        $state['heartbeat_at'] = $now;
        $state['workflow_updated_at'] = $now;
        $state['updated_at'] = $now;
        $this->update_media_replacement_workflow_state($state);

        $status = $this->get_media_library_replacement_rollback_status();
        return array_merge($status, array(
            'success' => true,
            'hasMore' => 'rollback_complete' !== (string) ($status['activeStep'] ?? ''),
            'batchProcessed' => max(0, (int) ($result['batchProcessedRefs'] ?? $result['batchRestored'] ?? 0)),
            'message' => (string) ($state['workflow_message'] ?? $status['message']),
        ));
    }
}
