<?php
/**
 * UltraCache Media Library replacement cleanup preview, recovery, validation, and apply operations.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Replacement_Cleanup_Trait
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache uses private custom Media Library replacement registry tables with validated table identifiers.

    private function get_media_replacement_cleanup_preview_statuses()
    {
        return array('metadata_updated', 'refs_scanned');
    }

    private function get_media_replacement_cleanup_completed_statuses()
    {
        return array('cleanup_deleted');
    }

    private function get_media_replacement_cleanup_failed_statuses()
    {
        return array('cleanup_failed');
    }

    private function get_media_replacement_cleanup_preview_summary()
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $summary = array(
            'totalItems'              => 0,
            'candidateItems'          => 0,
            'blockedItems'            => 0,
            'cleanupDeletedItems'     => 0,
            'cleanupFailedItems'      => 0,
            'uniqueOriginalFiles'     => 0,
            'missingOriginalItems'    => 0,
            'missingReplacementItems' => 0,
            'potentialFreeBytes'      => 0,
            'replacementBytes'        => 0,
            'itemsByStatus'           => array(),
            'databaseRefs'            => 0,
            'databaseVerifiedRefs'    => 0,
            'databasePendingRefs'     => 0,
            'databaseFailedRefs'      => 0,
            'databaseRestoredRefs'    => 0,
            'databaseRollbackFailedRefs' => 0,
            'databaseIndexCompleted'  => false,
            'databaseIndexedRefs'     => 0,
            'databaseIndexedPending'  => 0,
            'databaseIndexedIgnored'  => 0,
            'databaseIndexedRelevantUnmatched' => 0,
            'databaseIndexedFailed'   => 0,
            'databaseReady'           => false,
            'themeCssRefs'            => 0,
            'themeCssVerifiedRefs'    => 0,
            'themeCssPendingRefs'     => 0,
            'themeCssFailedRefs'      => 0,
            'themeCssReady'           => true,
            'metadataReadyByStatus'   => false,
            'cleanupReady'            => false,
            'cleanupComplete'         => false,
            'originalMainFilesOnly'   => false,
            'includesIntermediateSizes'=> true,
            'orphanDuplicateFiles'    => 0,
            'orphanDuplicateBytes'    => 0,
            'orphanDuplicateSamples'  => array(),
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT status, COUNT(*) AS item_count, SUM(old_size) AS old_total, SUM(new_size) AS new_total FROM %i GROUP BY status',
                $items_table
            ),
            ARRAY_A
        );

        $candidate_statuses = $this->get_media_replacement_cleanup_preview_statuses();
        $completed_statuses = $this->get_media_replacement_cleanup_completed_statuses();
        $failed_statuses    = $this->get_media_replacement_cleanup_failed_statuses();
        $ready_statuses     = array_merge($candidate_statuses, $completed_statuses);

        foreach ((array) $rows as $row) {
            $status = isset($row['status']) ? sanitize_key((string) $row['status']) : '';
            $count  = isset($row['item_count']) ? max(0, (int) $row['item_count']) : 0;
            $new_total = isset($row['new_total']) ? max(0, (int) $row['new_total']) : 0;

            $summary['itemsByStatus'][$status] = $count;
            $summary['totalItems'] += $count;
            if (in_array($status, $candidate_statuses, true)) {
                $summary['candidateItems'] += $count;
            } elseif (in_array($status, $completed_statuses, true)) {
                $summary['cleanupDeletedItems'] += $count;
            } elseif (in_array($status, $failed_statuses, true)) {
                $summary['cleanupFailedItems'] += $count;
                $summary['blockedItems'] += $count;
            } elseif (!in_array($status, $ready_statuses, true)) {
                $summary['blockedItems'] += $count;
            }

            if (in_array($status, $ready_statuses, true)) {
                $summary['replacementBytes'] += $new_total;
            }
        }

        $summary['metadataReadyByStatus'] = $summary['totalItems'] > 0 && ((int) $summary['candidateItems'] + (int) $summary['cleanupDeletedItems']) === (int) $summary['totalItems'];

        $candidate_rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT old_file_path, old_size FROM %i WHERE status IN (%s, %s)',
                $items_table,
                'metadata_updated',
                'refs_scanned'
            ),
            ARRAY_A
        );

        $unique_old_paths = array();
        foreach ((array) $candidate_rows as $candidate_row) {
            $old_file = isset($candidate_row['old_file_path']) ? wp_normalize_path((string) $candidate_row['old_file_path']) : '';
            if ('' === $old_file || isset($unique_old_paths[$old_file])) {
                continue;
            }
            $unique_old_paths[$old_file] = true;
            $summary['uniqueOriginalFiles']++;
            $summary['potentialFreeBytes'] += isset($candidate_row['old_size']) ? max(0, (int) $candidate_row['old_size']) : 0;
        }

        $db_summary = $this->get_media_replacement_database_preview_summary();
        if (!empty($db_summary)) {
            $summary['databaseRefs']                 = isset($db_summary['totalRefs']) ? max(0, (int) $db_summary['totalRefs']) : 0;
            $summary['databaseVerifiedRefs']         = isset($db_summary['verifiedRefs']) ? max(0, (int) $db_summary['verifiedRefs']) : 0;
            $summary['databasePendingRefs']          = isset($db_summary['pendingRefs']) ? max(0, (int) $db_summary['pendingRefs']) : 0;
            $summary['databaseFailedRefs']           = isset($db_summary['failedRefs']) ? max(0, (int) $db_summary['failedRefs']) : 0;
            $summary['databaseRestoredRefs']         = isset($db_summary['restoredRefs']) ? max(0, (int) $db_summary['restoredRefs']) : 0;
            $summary['databaseRollbackFailedRefs']   = isset($db_summary['rollbackFailedRefs']) ? max(0, (int) $db_summary['rollbackFailedRefs']) : 0;
        }

        $ref_index_state = $this->get_media_replacement_ref_index_state();
        $summary['databaseIndexCompleted'] = isset($ref_index_state['status']) && 'completed' === (string) $ref_index_state['status'];
        $ref_index_summary = $this->get_media_replacement_ref_index_summary();
        if (!empty($ref_index_summary)) {
            $summary['databaseIndexedRefs']    = isset($ref_index_summary['indexedTotal']) ? max(0, (int) $ref_index_summary['indexedTotal']) : 0;
            $summary['databaseIndexedIgnored'] = isset($ref_index_summary['unmatchedIgnored']) ? max(0, (int) $ref_index_summary['unmatchedIgnored']) : 0;
            $summary['databaseIndexedRelevantUnmatched'] = isset($ref_index_summary['unmatchedRelevant']) ? max(0, (int) $ref_index_summary['unmatchedRelevant']) : 0;
            $summary['databaseIndexedFailed']  = isset($ref_index_summary['failedIndexed']) ? max(0, (int) $ref_index_summary['failedIndexed']) : 0;
            $summary['databaseIndexedPending'] = (isset($ref_index_summary['indexedPending']) ? max(0, (int) $ref_index_summary['indexedPending']) : 0)
                + $summary['databaseIndexedRelevantUnmatched']
                + $summary['databaseIndexedFailed'];
        }

        $has_refs = $summary['databaseRefs'] > 0;
        $summary['databaseReady'] = !empty($summary['databaseIndexCompleted'])
            && 0 === (int) $summary['databaseIndexedPending']
            && ($has_refs
                ? ($summary['databaseVerifiedRefs'] === $summary['databaseRefs'] && 0 === $summary['databasePendingRefs'] && 0 === $summary['databaseFailedRefs'] && 0 === $summary['databaseRestoredRefs'] && 0 === $summary['databaseRollbackFailedRefs'])
                : true);

        $theme_css_summary = $this->get_media_replacement_theme_css_summary();
        if (!empty($theme_css_summary)) {
            $summary['themeCssRefs']         = isset($theme_css_summary['total']) ? max(0, (int) $theme_css_summary['total']) : 0;
            $summary['themeCssVerifiedRefs'] = isset($theme_css_summary['verified']) ? max(0, (int) $theme_css_summary['verified']) : 0;
            $summary['themeCssPendingRefs']  = isset($theme_css_summary['pending']) ? max(0, (int) $theme_css_summary['pending']) + (isset($theme_css_summary['applied']) ? max(0, (int) $theme_css_summary['applied']) : 0) : 0;
            $summary['themeCssFailedRefs']   = (isset($theme_css_summary['failed']) ? max(0, (int) $theme_css_summary['failed']) : 0) + (isset($theme_css_summary['verifyFailed']) ? max(0, (int) $theme_css_summary['verifyFailed']) : 0);
            $summary['themeCssReady']        = 0 === (int) $summary['themeCssRefs'] || ((int) $summary['themeCssVerifiedRefs'] === (int) $summary['themeCssRefs'] && 0 === (int) $summary['themeCssPendingRefs'] && 0 === (int) $summary['themeCssFailedRefs']);
        }

        $summary['cleanupReady'] = $summary['metadataReadyByStatus'] && $summary['databaseReady'] && $summary['themeCssReady'] && 0 === (int) $summary['cleanupFailedItems'];
        $summary['cleanupComplete'] = $summary['cleanupReady'] && $summary['totalItems'] > 0 && 0 === (int) $summary['candidateItems'];

        $orphan_summary = $this->get_media_replacement_orphan_duplicate_upload_preview_summary();
        if (!empty($orphan_summary)) {
            $summary['orphanDuplicateFiles']   = isset($orphan_summary['count']) ? max(0, (int) $orphan_summary['count']) : 0;
            $summary['orphanDuplicateBytes']   = isset($orphan_summary['bytes']) ? max(0, (int) $orphan_summary['bytes']) : 0;
            $summary['orphanDuplicateSamples'] = isset($orphan_summary['samples']) && is_array($orphan_summary['samples']) ? $orphan_summary['samples'] : array();
        }

        return $summary;
    }

    private function get_media_replacement_cleanup_preview_rows($limit = 200, $offset = 0, $global_cleanup_ready = false)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $limit       = max(1, min(500, absint($limit)));
        $offset      = max(0, absint($offset));

        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, attachment_id, item_scope, size_name, source_format, target_format, old_relative_path, old_file_path, generated_file_path, new_relative_path, new_file_path, old_mime, new_mime, old_size, old_file_hash, new_size, status, error_message FROM %i ORDER BY id ASC LIMIT %d OFFSET %d',
                $items_table,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        $items = array();
        $candidate_statuses = $this->get_media_replacement_cleanup_preview_statuses();
        $completed_statuses = $this->get_media_replacement_cleanup_completed_statuses();
        foreach ((array) $rows as $row) {
            $attachment_id = isset($row['attachment_id']) ? absint($row['attachment_id']) : 0;
            $status = isset($row['status']) ? sanitize_key((string) $row['status']) : '';
            $old_relative = isset($row['old_relative_path']) ? ltrim(str_replace('\\', '/', (string) $row['old_relative_path']), '/') : '';
            $new_relative = isset($row['new_relative_path']) ? ltrim(str_replace('\\', '/', (string) $row['new_relative_path']), '/') : '';
            $old_file = isset($row['old_file_path']) ? wp_normalize_path((string) $row['old_file_path']) : '';
            $new_file = isset($row['new_file_path']) ? wp_normalize_path((string) $row['new_file_path']) : '';
            $old_mime = isset($row['old_mime']) ? sanitize_mime_type((string) $row['old_mime']) : '';
            $new_mime = isset($row['new_mime']) ? sanitize_mime_type((string) $row['new_mime']) : '';
            $item_scope = isset($row['item_scope']) ? sanitize_key((string) $row['item_scope']) : 'main';
            $size_name = isset($row['size_name']) ? substr(sanitize_key((string) $row['size_name']), 0, 64) : '';

            $evaluation = $this->evaluate_media_replacement_cleanup_row($row);
            $old_exists = !$evaluation['alreadyMissing'] && '' !== $old_file && $this->optimized_storage_path_exists($old_file, true);
            $new_exists = !empty($evaluation['replacementReady']);
            $metadata_switched = !empty($evaluation['metadataSwitched']);

            $is_candidate_status = in_array($status, $candidate_statuses, true);
            $is_completed_status = in_array($status, $completed_statuses, true);
            $cleanup_candidate = $global_cleanup_ready && $is_candidate_status && !empty($evaluation['processable']);
            $reason = '';
            if ($is_completed_status) {
                $reason = __('Original file cleanup is already complete for this row.', 'ultracache');
            } elseif (!$is_candidate_status) {
                $reason = __('Registry row is not in a cleanup-ready status.', 'ultracache');
            } elseif (!$global_cleanup_ready) {
                $reason = __('Workflow-level checks are not complete yet.', 'ultracache');
            } elseif (empty($evaluation['processable'])) {
                $reason = (string) $evaluation['message'];
            } elseif (!empty($evaluation['alreadyMissing'])) {
                $reason = __('Original file is already missing; Delete Originals will mark this row complete.', 'ultracache');
            }

            $title = $attachment_id > 0 ? get_the_title($attachment_id) : '';
            $title = is_string($title) && '' !== $title ? $title : sprintf(
                /* translators: %d: attachment ID. */
                __('Attachment #%d', 'ultracache'),
                $attachment_id
            );

            $items[] = array(
                'id'                   => isset($row['id']) ? absint($row['id']) : 0,
                'attachmentId'         => $attachment_id,
                'itemScope'            => $item_scope,
                'sizeName'             => $size_name,
                'title'                => $title,
                'sourceFormat'         => isset($row['source_format']) ? sanitize_key((string) $row['source_format']) : '',
                'targetFormat'         => isset($row['target_format']) ? sanitize_key((string) $row['target_format']) : '',
                'oldRelativePath'      => $old_relative,
                'newRelativePath'      => $new_relative,
                'oldMime'              => $old_mime,
                'newMime'              => $new_mime,
                'oldSize'              => isset($row['old_size']) ? max(0, (int) $row['old_size']) : 0,
                'newSize'              => isset($row['new_size']) ? max(0, (int) $row['new_size']) : 0,
                'status'               => $status,
                'oldFileExists'        => $old_exists,
                'newFileExists'        => $new_exists,
                'metadataSwitched'     => $metadata_switched,
                'replacementMatchesOutput' => !empty($evaluation['replacementMatchesOutput']),
                'originalFingerprintCurrent' => !empty($evaluation['originalFingerprintCurrent']),
                'cleanupCandidate'     => $cleanup_candidate,
                'reason'               => $reason,
                'errorMessage'         => isset($row['error_message']) ? wp_strip_all_tags((string) $row['error_message']) : '',
            );
        }

        return $items;
    }

    private function is_media_replacement_cleanup_row_metadata_switched(array $row)
    {
        $attachment_id = isset($row['attachment_id']) ? absint($row['attachment_id']) : 0;
        $item_scope    = isset($row['item_scope']) ? sanitize_key((string) $row['item_scope']) : 'main';
        $size_name     = isset($row['size_name']) ? substr(sanitize_key((string) $row['size_name']), 0, 64) : '';
        $new_relative  = isset($row['new_relative_path']) ? ltrim(str_replace('\\', '/', (string) $row['new_relative_path']), '/') : '';
        $new_mime      = isset($row['new_mime']) ? sanitize_mime_type((string) $row['new_mime']) : '';

        if ($attachment_id <= 0 || '' === $new_relative) {
            return false;
        }

        if ('intermediate' === $item_scope) {
            return '' !== $size_name && $this->verify_media_replacement_intermediate_metadata_switch($attachment_id, $size_name, $new_relative, $new_mime);
        }

        $current_attached = ltrim(str_replace('\\', '/', (string) $this->get_media_replacement_current_attached_file_meta($attachment_id)), '/');
        $current_mime     = sanitize_mime_type((string) get_post_mime_type($attachment_id));
        $metadata         = wp_get_attachment_metadata($attachment_id);
        $metadata_file    = is_array($metadata) && isset($metadata['file']) ? ltrim(str_replace('\\', '/', (string) $metadata['file']), '/') : '';

        return $current_attached === $new_relative && $metadata_file === $new_relative && ('' === $new_mime || $current_mime === $new_mime);
    }

    private function is_media_replacement_cleanup_original_fingerprint_current(array $row)
    {
        $old_file = isset($row['old_file_path']) ? wp_normalize_path((string) $row['old_file_path']) : '';
        $expected_size = isset($row['old_size']) ? max(0, (int) $row['old_size']) : 0;
        $expected_hash = isset($row['old_file_hash']) ? strtolower((string) $row['old_file_hash']) : '';
        if ('' === $old_file || $expected_size <= 0 || !preg_match('/^[a-f0-9]{64}$/', $expected_hash) || !$this->optimized_storage_path_exists($old_file, true)) {
            return false;
        }

        $current_size = function_exists('ultracache_safe_filesize')
            ? (int) ultracache_safe_filesize($old_file, 'media_replacement_cleanup_original_size')
            : (int) filesize($old_file);
        if ($current_size !== $expected_size || !function_exists('hash_file')) {
            return false;
        }

        $current_hash = hash_file('sha256', $old_file);
        return is_string($current_hash) && hash_equals($expected_hash, strtolower($current_hash));
    }


    private function evaluate_media_replacement_cleanup_row(array $row)
    {
        $old_file       = isset($row['old_file_path']) ? wp_normalize_path((string) $row['old_file_path']) : '';
        $generated_file = isset($row['generated_file_path']) ? wp_normalize_path((string) $row['generated_file_path']) : '';
        $new_file       = isset($row['new_file_path']) ? wp_normalize_path((string) $row['new_file_path']) : '';
        $result = array(
            'processable'               => false,
            'deletable'                 => false,
            'alreadyMissing'            => false,
            'pathAllowed'               => false,
            'replacementReady'          => false,
            'replacementMatchesOutput'  => false,
            'metadataSwitched'           => false,
            'originalFingerprintCurrent'=> false,
            'oldFile'                   => $old_file,
            'generatedFile'             => $generated_file,
            'newFile'                   => $new_file,
            'code'                      => 'invalid_original_path',
            'message'                   => __('Original path is not an eligible JPG/PNG uploads file or matches the replacement path.', 'ultracache'),
        );

        if (!$this->is_media_replacement_upload_file_cleanup_allowed($old_file) || '' === $new_file || $old_file === $new_file) {
            return $result;
        }
        $result['pathAllowed'] = true;

        if ('' === $generated_file || !$this->optimized_storage_path_exists($generated_file, true) || !$this->optimized_storage_path_exists($new_file, true)) {
            $result['code'] = 'replacement_missing';
            $result['message'] = __('Rewrite source or replacement file is missing or invalid; original was not deleted.', 'ultracache');
            return $result;
        }
        $result['replacementReady'] = true;

        if (!$this->media_replacement_files_are_identical($generated_file, $new_file)) {
            $result['code'] = 'replacement_changed';
            $result['message'] = __('Replacement file no longer matches the verified UltraCache rewrite output; original was not deleted.', 'ultracache');
            return $result;
        }
        $result['replacementMatchesOutput'] = true;

        if (!$this->is_media_replacement_cleanup_row_metadata_switched($row)) {
            $result['code'] = 'metadata_not_switched';
            $result['message'] = __('Attachment metadata no longer points to the replacement file; original was not deleted.', 'ultracache');
            return $result;
        }
        $result['metadataSwitched'] = true;

        if (!$this->optimized_storage_path_exists($old_file, true)) {
            $result['processable'] = true;
            $result['alreadyMissing'] = true;
            $result['code'] = 'original_already_missing';
            $result['message'] = __('Original file was already missing; row can be marked complete.', 'ultracache');
            return $result;
        }

        if (!$this->is_media_replacement_cleanup_original_fingerprint_current($row)) {
            $result['code'] = 'original_changed';
            $result['message'] = __('Original file content changed after the replacement plan was prepared; original was not deleted.', 'ultracache');
            return $result;
        }

        $result['processable'] = true;
        $result['deletable'] = true;
        $result['originalFingerprintCurrent'] = true;
        $result['code'] = 'deletable';
        $result['message'] = '';
        return $result;
    }


    private function get_media_replacement_cleanup_hard_blocked_status_count()
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return 0;
        }

        return max(0, (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE status NOT IN (%s, %s, %s, %s, %s)',
                $items_table,
                'metadata_updated',
                'refs_scanned',
                'cleanup_deleted',
                'cleanup_failed',
                'excluded'
            )
        ));
    }

    private function recover_media_replacement_cleanup_failed_rows($limit = 200)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $limit       = max(1, min(500, absint($limit)));
        $result      = array(
            'checked'        => 0,
            'requeued'       => 0,
            'markedComplete' => 0,
        );

        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return $result;
        }

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, attachment_id, item_scope, size_name, old_relative_path, old_file_path, generated_file_path, new_relative_path, new_file_path, old_mime, new_mime, old_size, old_file_hash, new_size, destination_overwritten, destination_backup_path, destination_backup_size, destination_backup_hash, status FROM %i WHERE status = %s ORDER BY id ASC LIMIT %d',
                $items_table,
                'cleanup_failed',
                $limit
            ),
            ARRAY_A
        );

        foreach ($rows as $row) {
            $item_id = isset($row['id']) ? absint($row['id']) : 0;
            if ($item_id <= 0) {
                continue;
            }

            $result['checked']++;
            $evaluation = $this->evaluate_media_replacement_cleanup_row($row);
            if (empty($evaluation['processable'])) {
                continue;
            }

            if (!empty($evaluation['alreadyMissing'])) {
                $backup_cleanup = $this->finalize_media_replacement_destination_backup_cleanup($row);
                if (empty($backup_cleanup['cleaned'])) {
                    $this->update_media_replacement_cleanup_item_status($item_id, 'cleanup_failed', (string) ($backup_cleanup['message'] ?? __('Overwrite backup cleanup failed.', 'ultracache')));
                    continue;
                }
                if ($this->update_media_replacement_cleanup_item_status($item_id, 'cleanup_deleted', __('Original file was already missing; recovered cleanup row marked complete.', 'ultracache'))) {
                    $result['markedComplete']++;
                }
                continue;
            }

            if ($this->update_media_replacement_cleanup_item_status($item_id, 'metadata_updated', '')) {
                $result['requeued']++;
            }
        }

        return $result;
    }

    private function is_media_replacement_upload_file_cleanup_allowed($path)
    {
        $path = wp_normalize_path((string) $path);
        if ('' === $path || !preg_match('/\.(?:jpe?g|png)$/i', $path) || !function_exists('ultracache_path_has_dir_prefix')) {
            return false;
        }

        $uploads = wp_get_upload_dir();
        $basedir = isset($uploads['basedir']) ? wp_normalize_path((string) $uploads['basedir']) : '';
        return '' !== $basedir && ultracache_path_has_dir_prefix($path, $basedir);
    }

    private function update_media_replacement_cleanup_item_status($item_id, $status, $message = '')
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $item_id     = absint($item_id);
        $status      = sanitize_key((string) $status);
        if ('' === $items_table || $item_id <= 0 || !($wpdb instanceof wpdb) || !in_array($status, array('metadata_updated', 'refs_scanned', 'cleanup_deleted', 'cleanup_failed'), true)) {
            return false;
        }

        $data = array(
            'status'        => $status,
            'error_message' => '' !== $message ? wp_strip_all_tags((string) $message) : null,
            'updated_at'    => current_time('mysql', true),
        );

        return false !== $wpdb->update(
            $items_table,
            $data,
            array('id' => $item_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }

    private function get_media_replacement_cleanup_candidate_rows($limit = 50)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $limit       = max(1, min(100, absint($limit)));
        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, attachment_id, item_scope, size_name, old_relative_path, old_file_path, generated_file_path, new_relative_path, new_file_path, old_mime, new_mime, old_size, old_file_hash, new_size, destination_overwritten, destination_backup_path, destination_backup_size, destination_backup_hash, status FROM %i WHERE status IN (%s, %s) ORDER BY old_file_path ASC, id ASC LIMIT %d',
                $items_table,
                'metadata_updated',
                'refs_scanned',
                $limit
            ),
            ARRAY_A
        );
    }

    private function get_media_replacement_orphan_duplicate_upload_preview_summary($sample_limit = 25)
    {
        global $wpdb;

        $items_table  = $this->get_media_replacement_items_table_name();
        $sample_limit = max(1, min(100, absint($sample_limit)));
        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return array('count' => 0, 'bytes' => 0, 'samples' => array());
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT old_file_path, new_file_path, generated_file_path FROM %i WHERE new_file_path <> %s',
                $items_table,
                ''
            ),
            ARRAY_A
        );

        $protected    = array();
        $patterns_by_dir = array();

        foreach ((array) $rows as $row) {
            foreach (array('old_file_path', 'new_file_path', 'generated_file_path') as $field) {
                $path = isset($row[$field]) ? wp_normalize_path((string) $row[$field]) : '';
                if ('' !== $path) {
                    $protected[$path] = true;
                }
            }

            $new_file = isset($row['new_file_path']) ? wp_normalize_path((string) $row['new_file_path']) : '';
            if ('' === $new_file || !preg_match('/\.(?:avif|webp)$/i', $new_file)) {
                continue;
            }

            $dir      = wp_normalize_path(dirname($new_file));
            $filename = basename($new_file);
            $dot      = strrpos($filename, '.');
            if (false === $dot || '' === $dir) {
                continue;
            }

            $base = substr($filename, 0, $dot);
            $ext  = strtolower(substr($filename, $dot + 1));
            if ('' === $base || '' === $ext) {
                continue;
            }

            if (!isset($patterns_by_dir[$dir])) {
                $patterns_by_dir[$dir] = array();
            }
            $patterns_by_dir[$dir][$base . '|' . $ext] = true;
        }

        $seen    = array();
        $count   = 0;
        $bytes   = 0;
        $samples = array();

        foreach ($patterns_by_dir as $dir => $patterns) {
            $items = function_exists('ultracache_safe_scandir') ? ultracache_safe_scandir($dir, 'media_replacement_orphan_duplicate_preview') : array();
            foreach ((array) $items as $candidate) {
                if (!is_string($candidate) || !preg_match('/^(.+)-\d+\.(avif|webp)$/i', $candidate, $matches)) {
                    continue;
                }

                $base = isset($matches[1]) ? (string) $matches[1] : '';
                $ext  = isset($matches[2]) ? strtolower((string) $matches[2]) : '';
                if ('' === $base || '' === $ext || !isset($patterns[$base . '|' . $ext])) {
                    continue;
                }

                $candidate_path = wp_normalize_path(trailingslashit($dir) . $candidate);
                if (isset($protected[$candidate_path]) || isset($seen[$candidate_path]) || !$this->optimized_storage_path_exists($candidate_path, true)) {
                    continue;
                }

                $seen[$candidate_path] = true;
                $count++;
                $size = function_exists('ultracache_safe_filesize') ? (int) ultracache_safe_filesize($candidate_path, 'media_replacement_orphan_duplicate_preview') : (int) @filesize($candidate_path);
                $bytes += max(0, $size);
                if (count($samples) < $sample_limit) {
                    $samples[] = array(
                        'path' => $candidate_path,
                        'size' => max(0, $size),
                    );
                }
            }
        }

        return array(
            'count'   => $count,
            'bytes'   => $bytes,
            'samples' => $samples,
        );
    }


    public function get_media_library_replacement_cleanup_preview($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array(
                'success' => false,
                'message' => __('Media Library replacement registry tables are not available.', 'ultracache'),
            );
        }

        if (!$this->media_replacement_has_registry_rows()) {
            $empty = $this->build_media_replacement_empty_registry_response(__('No Media Library replacement registry rows are available for cleanup preview. Restore the database backup or roll back attachment metadata, then run Restart Replacement Plan again.', 'ultracache'));
            $empty['hasCleanupPreview'] = false;
            return $empty;
        }

        $args = is_array($args) ? $args : array();
        $limit  = isset($args['limit']) ? absint($args['limit']) : 200;
        $offset = isset($args['offset']) ? absint($args['offset']) : 0;
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $summary = $this->get_media_replacement_cleanup_preview_summary();
        if (empty($summary)) {
            return array(
                'success' => false,
                'message' => __('Cleanup preview is not available for the Media Library replacement workflow.', 'ultracache'),
                'hasCleanupPreview' => false,
            );
        }

        $total = isset($summary['totalItems']) ? max(0, (int) $summary['totalItems']) : 0;
        $cleanup_ready = !empty($summary['cleanupReady']);
        $items = $total > 0 ? $this->get_media_replacement_cleanup_preview_rows($limit, $offset, $cleanup_ready) : array();
        $has_more = ($offset + count($items)) < $total;

        if (!$cleanup_ready) {
            $message = __('Media Library replacement cleanup preview found blockers. Do not delete originals yet.', 'ultracache');
            $next_step = __('Resolve database verification or attachment metadata blockers before using Delete Originals.', 'ultracache');
        } elseif (!empty($summary['cleanupComplete'])) {
            $message = __('Media Library replacement cleanup is complete. No original JPG/PNG cleanup candidates remain.', 'ultracache');
            $next_step = __('Cleanup is complete. Review the site and keep backups until you are done testing.', 'ultracache');
        } else {
            $message = sprintf(
                /* translators: 1: row count, 2: unique file count, 3: human-readable bytes. */
                __('Media Library replacement cleanup preview found %1$d main/intermediate original rows across %2$d unique files. Potential free space: %3$s. No files were deleted.', 'ultracache'),
                isset($summary['candidateItems']) ? (int) $summary['candidateItems'] : 0,
                isset($summary['uniqueOriginalFiles']) ? (int) $summary['uniqueOriginalFiles'] : 0,
                size_format(isset($summary['potentialFreeBytes']) ? (int) $summary['potentialFreeBytes'] : 0, 1)
            );
            $next_step = __('Next step: Delete Originals with confirmation removes only verified original JPG/PNG files for this workflow.', 'ultracache');
        }

        $response = array(
            'success'             => true,
            'message'             => $message,
            'hasCleanupPreview'   => true,
            'summary'             => $summary,
            'items'               => $items,
            'limit'               => $limit,
            'offset'              => $offset,
            'returned'            => count($items),
            'hasMore'             => $has_more,
            'nextOffset'          => $has_more ? $offset + count($items) : $offset,
            'previousOffset'      => max(0, $offset - $limit),
            'cleanupPreviewOnly'  => true,
            'cleanupReady'        => $cleanup_ready,
            'cleanupCandidates'   => isset($summary['candidateItems']) ? (int) $summary['candidateItems'] : 0,
            'cleanupBlockedItems' => isset($summary['blockedItems']) ? (int) $summary['blockedItems'] : 0,
            'cleanupDeleted'      => isset($summary['cleanupDeletedItems']) ? (int) $summary['cleanupDeletedItems'] : 0,
            'cleanupFailed'       => isset($summary['cleanupFailedItems']) ? (int) $summary['cleanupFailedItems'] : 0,
            'themeCssRefs'        => isset($summary['themeCssRefs']) ? (int) $summary['themeCssRefs'] : 0,
            'themeCssVerifiedRefs'=> isset($summary['themeCssVerifiedRefs']) ? (int) $summary['themeCssVerifiedRefs'] : 0,
            'themeCssPendingRefs' => isset($summary['themeCssPendingRefs']) ? (int) $summary['themeCssPendingRefs'] : 0,
            'themeCssFailedRefs'  => isset($summary['themeCssFailedRefs']) ? (int) $summary['themeCssFailedRefs'] : 0,
            'cleanupPotentialFreeBytes' => isset($summary['potentialFreeBytes']) ? (int) $summary['potentialFreeBytes'] : 0,
            'progressPercent'     => 100,
            'status'              => 'cleanup_preview',
            'nextStep'            => $next_step,
        );

        return $this->add_media_replacement_confirmation_token_to_response($response, 'cleanup_apply', $cleanup_ready && empty($summary['cleanupComplete']) && !empty($summary['candidateItems']));
    }



}
