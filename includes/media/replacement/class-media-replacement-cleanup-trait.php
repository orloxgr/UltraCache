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

    private function get_media_replacement_cleanup_preview_summary($job_id)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $job_id      = sanitize_key((string) $job_id);
        if ('' === $items_table || '' === $job_id || !($wpdb instanceof wpdb)) {
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
                'SELECT status, COUNT(*) AS item_count, SUM(old_size) AS old_total, SUM(new_size) AS new_total FROM %i WHERE job_id = %s GROUP BY status',
                $items_table,
                $job_id
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
                'SELECT old_file_path, old_size FROM %i WHERE job_id = %s AND status IN (%s, %s)',
                $items_table,
                $job_id,
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

        $db_summary = $this->get_media_replacement_database_preview_summary($job_id);
        if (!empty($db_summary)) {
            $summary['databaseRefs']                 = isset($db_summary['totalRefs']) ? max(0, (int) $db_summary['totalRefs']) : 0;
            $summary['databaseVerifiedRefs']         = isset($db_summary['verifiedRefs']) ? max(0, (int) $db_summary['verifiedRefs']) : 0;
            $summary['databasePendingRefs']          = isset($db_summary['pendingRefs']) ? max(0, (int) $db_summary['pendingRefs']) : 0;
            $summary['databaseFailedRefs']           = isset($db_summary['failedRefs']) ? max(0, (int) $db_summary['failedRefs']) : 0;
            $summary['databaseRestoredRefs']         = isset($db_summary['restoredRefs']) ? max(0, (int) $db_summary['restoredRefs']) : 0;
            $summary['databaseRollbackFailedRefs']   = isset($db_summary['rollbackFailedRefs']) ? max(0, (int) $db_summary['rollbackFailedRefs']) : 0;
        }

        $ref_index_state = $this->get_media_replacement_ref_index_state();
        $summary['databaseIndexCompleted'] = isset($ref_index_state['job_id'], $ref_index_state['status']) && $job_id === (string) $ref_index_state['job_id'] && 'completed' === (string) $ref_index_state['status'];
        $ref_index_summary = $this->get_media_replacement_ref_index_summary($job_id);
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

        $theme_css_summary = $this->get_media_replacement_theme_css_summary($job_id);
        if (!empty($theme_css_summary)) {
            $summary['themeCssRefs']         = isset($theme_css_summary['total']) ? max(0, (int) $theme_css_summary['total']) : 0;
            $summary['themeCssVerifiedRefs'] = isset($theme_css_summary['verified']) ? max(0, (int) $theme_css_summary['verified']) : 0;
            $summary['themeCssPendingRefs']  = isset($theme_css_summary['pending']) ? max(0, (int) $theme_css_summary['pending']) + (isset($theme_css_summary['applied']) ? max(0, (int) $theme_css_summary['applied']) : 0) : 0;
            $summary['themeCssFailedRefs']   = (isset($theme_css_summary['failed']) ? max(0, (int) $theme_css_summary['failed']) : 0) + (isset($theme_css_summary['verifyFailed']) ? max(0, (int) $theme_css_summary['verifyFailed']) : 0);
            $summary['themeCssReady']        = 0 === (int) $summary['themeCssRefs'] || ((int) $summary['themeCssVerifiedRefs'] === (int) $summary['themeCssRefs'] && 0 === (int) $summary['themeCssPendingRefs'] && 0 === (int) $summary['themeCssFailedRefs']);
        }

        $summary['cleanupReady'] = $summary['metadataReadyByStatus'] && $summary['databaseReady'] && $summary['themeCssReady'] && 0 === (int) $summary['cleanupFailedItems'];
        $summary['cleanupComplete'] = $summary['cleanupReady'] && $summary['totalItems'] > 0 && 0 === (int) $summary['candidateItems'];

        $orphan_summary = $this->get_media_replacement_orphan_duplicate_upload_preview_summary($job_id);
        if (!empty($orphan_summary)) {
            $summary['orphanDuplicateFiles']   = isset($orphan_summary['count']) ? max(0, (int) $orphan_summary['count']) : 0;
            $summary['orphanDuplicateBytes']   = isset($orphan_summary['bytes']) ? max(0, (int) $orphan_summary['bytes']) : 0;
            $summary['orphanDuplicateSamples'] = isset($orphan_summary['samples']) && is_array($orphan_summary['samples']) ? $orphan_summary['samples'] : array();
        }

        return $summary;
    }

    private function get_media_replacement_cleanup_preview_rows($job_id, $limit = 200, $offset = 0, $global_cleanup_ready = false)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $job_id      = sanitize_key((string) $job_id);
        $limit       = max(1, min(500, absint($limit)));
        $offset      = max(0, absint($offset));

        if ('' === $items_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, attachment_id, item_scope, size_name, source_format, target_format, old_relative_path, old_file_path, new_relative_path, new_file_path, old_mime, new_mime, old_size, new_size, status, error_message FROM %i WHERE job_id = %s ORDER BY id ASC LIMIT %d OFFSET %d',
                $items_table,
                $job_id,
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

            $old_exists = '' !== $old_file && $this->optimized_storage_path_exists($old_file, true);
            $new_exists = '' !== $new_file && $this->optimized_storage_path_exists($new_file, true);
            $metadata_switched = $this->is_media_replacement_cleanup_row_metadata_switched($row);

            $is_candidate_status = in_array($status, $candidate_statuses, true);
            $is_completed_status = in_array($status, $completed_statuses, true);
            $local_ready = $is_candidate_status && $metadata_switched && $old_exists && $new_exists && '' !== $old_relative && '' !== $new_relative && $old_relative !== $new_relative;
            $cleanup_candidate = $global_cleanup_ready && $local_ready;
            $reason = '';
            if (!$global_cleanup_ready) {
                $reason = __('Job-level checks are not complete yet.', 'ultracache');
            } elseif ($is_completed_status) {
                $reason = __('Original file cleanup is already complete for this row.', 'ultracache');
            } elseif (!$is_candidate_status) {
                $reason = __('Registry row is not in a cleanup-ready status.', 'ultracache');
            } elseif (!$metadata_switched) {
                $reason = __('Attachment metadata does not currently point to the copied replacement file.', 'ultracache');
            } elseif (!$new_exists) {
                $reason = __('Copied replacement file is missing.', 'ultracache');
            } elseif (!$old_exists) {
                $reason = __('Original file is already missing, so there is nothing to delete for this row.', 'ultracache');
            } elseif ($old_relative === $new_relative) {
                $reason = __('Original and replacement paths are identical.', 'ultracache');
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

    private function is_media_replacement_cleanup_original_unchanged(array $row)
    {
        $old_file = isset($row['old_file_path']) ? wp_normalize_path((string) $row['old_file_path']) : '';
        $expected_size = isset($row['old_size']) ? max(0, (int) $row['old_size']) : 0;
        if ('' === $old_file || !$this->optimized_storage_path_exists($old_file, true)) {
            return false;
        }

        $current_size = function_exists('ultracache_safe_filesize')
            ? (int) ultracache_safe_filesize($old_file, 'media_replacement_cleanup_original_size')
            : (int) filesize($old_file);

        return $current_size > 0 && (0 === $expected_size || $current_size === $expected_size);
    }

    private function get_media_replacement_cleanup_hard_blocked_status_count($job_id)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $job_id      = sanitize_key((string) $job_id);
        if ('' === $items_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return 0;
        }

        return max(0, (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE job_id = %s AND status NOT IN (%s, %s, %s, %s)',
                $items_table,
                $job_id,
                'metadata_updated',
                'refs_scanned',
                'cleanup_deleted',
                'cleanup_failed'
            )
        ));
    }

    private function recover_media_replacement_cleanup_failed_rows($job_id, $limit = 200)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $job_id      = sanitize_key((string) $job_id);
        $limit       = max(1, min(500, absint($limit)));
        $result      = array(
            'checked'        => 0,
            'requeued'       => 0,
            'markedComplete' => 0,
        );

        if ('' === $items_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return $result;
        }

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, attachment_id, item_scope, size_name, old_relative_path, old_file_path, generated_file_path, new_relative_path, new_file_path, old_mime, new_mime, old_size, new_size, status FROM %i WHERE job_id = %s AND status = %s ORDER BY id ASC LIMIT %d',
                $items_table,
                $job_id,
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
            $old_file = isset($row['old_file_path']) ? wp_normalize_path((string) $row['old_file_path']) : '';
            $generated_file = isset($row['generated_file_path']) ? wp_normalize_path((string) $row['generated_file_path']) : '';
            $new_file = isset($row['new_file_path']) ? wp_normalize_path((string) $row['new_file_path']) : '';

            if (!$this->is_media_replacement_upload_file_cleanup_allowed($old_file)) {
                continue;
            }
            if ('' === $generated_file || '' === $new_file || !$this->optimized_storage_path_exists($generated_file, true) || !$this->optimized_storage_path_exists($new_file, true)) {
                continue;
            }
            if (!$this->media_replacement_files_are_identical($generated_file, $new_file)) {
                continue;
            }
            if (!$this->is_media_replacement_cleanup_row_metadata_switched($row)) {
                continue;
            }

            if (!$this->optimized_storage_path_exists($old_file, true)) {
                if ($this->update_media_replacement_cleanup_item_status($item_id, 'cleanup_deleted', __('Original file was already missing; recovered cleanup row marked complete.', 'ultracache'))) {
                    $result['markedComplete']++;
                }
                continue;
            }
            if (!$this->is_media_replacement_cleanup_original_unchanged($row)) {
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
        if ('' === $path || !preg_match('/\.(?:jpe?g|png)$/i', $path)) {
            return false;
        }

        $uploads = wp_get_upload_dir();
        $basedir = isset($uploads['basedir']) ? wp_normalize_path((string) $uploads['basedir']) : '';
        if ('' === $basedir) {
            return false;
        }

        return 0 === strpos($path, trailingslashit($basedir));
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

    private function get_media_replacement_cleanup_candidate_rows($job_id, $limit = 50)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $job_id      = sanitize_key((string) $job_id);
        $limit       = max(1, min(100, absint($limit)));
        if ('' === $items_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array();
        }

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, attachment_id, item_scope, size_name, old_relative_path, old_file_path, generated_file_path, new_relative_path, new_file_path, old_mime, new_mime, old_size, new_size, status FROM %i WHERE job_id = %s AND status IN (%s, %s) ORDER BY old_file_path ASC, id ASC LIMIT %d',
                $items_table,
                $job_id,
                'metadata_updated',
                'refs_scanned',
                $limit
            ),
            ARRAY_A
        );
    }

    private function get_media_replacement_orphan_duplicate_upload_preview_summary($job_id, $sample_limit = 25)
    {
        global $wpdb;

        $items_table  = $this->get_media_replacement_items_table_name();
        $job_id       = sanitize_key((string) $job_id);
        $sample_limit = max(1, min(100, absint($sample_limit)));
        if ('' === $items_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array('count' => 0, 'bytes' => 0, 'samples' => array());
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT old_file_path, new_file_path, generated_file_path FROM %i WHERE job_id = %s AND new_file_path <> %s',
                $items_table,
                $job_id,
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

        $args = is_array($args) ? $args : array();
        $job_id = $this->get_media_replacement_preview_job_id(isset($args['job_id']) ? (string) $args['job_id'] : '');
        if ('' === $job_id) {
            return array(
                'success' => false,
                'message' => __('Run Media Library replacement before previewing cleanup candidates.', 'ultracache'),
                'hasCleanupPreview' => false,
            );
        }
        if (!$this->media_replacement_job_has_registry_rows($job_id)) {
            $empty = $this->build_media_replacement_empty_registry_response($job_id, __('No Media Library replacement registry rows are available for cleanup preview. Restore the database backup or roll back attachment metadata, then run Restart Replacement Plan again.', 'ultracache'));
            $empty['hasCleanupPreview'] = false;
            return $empty;
        }

        $limit  = isset($args['limit']) ? absint($args['limit']) : 200;
        $offset = isset($args['offset']) ? absint($args['offset']) : 0;
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $summary = $this->get_media_replacement_cleanup_preview_summary($job_id);
        if (empty($summary)) {
            return array(
                'success' => false,
                'message' => __('Cleanup preview is not available for the active Media Library replacement job.', 'ultracache'),
                'jobId' => $job_id,
                'hasCleanupPreview' => false,
            );
        }

        $total = isset($summary['totalItems']) ? max(0, (int) $summary['totalItems']) : 0;
        $cleanup_ready = !empty($summary['cleanupReady']);
        $items = $total > 0 ? $this->get_media_replacement_cleanup_preview_rows($job_id, $limit, $offset, $cleanup_ready) : array();
        $has_more = ($offset + count($items)) < $total;

        if (!$cleanup_ready) {
            $message = __('Media Library replacement cleanup preview found blockers. Do not delete originals yet.', 'ultracache');
            $next_step = __('Resolve database verification or attachment metadata blockers before running Cleanup Apply.', 'ultracache');
        } elseif (!empty($summary['cleanupComplete'])) {
            $message = __('Media Library replacement cleanup is complete. No original JPG/PNG cleanup candidates remain for this job.', 'ultracache');
            $next_step = __('Cleanup is complete. Review the site and keep backups until you are done testing.', 'ultracache');
        } else {
            $message = sprintf(
                /* translators: 1: row count, 2: unique file count, 3: human-readable bytes. */
                __('Media Library replacement cleanup preview found %1$d main/intermediate original rows across %2$d unique files. Potential free space: %3$s. No files were deleted.', 'ultracache'),
                isset($summary['candidateItems']) ? (int) $summary['candidateItems'] : 0,
                isset($summary['uniqueOriginalFiles']) ? (int) $summary['uniqueOriginalFiles'] : 0,
                size_format(isset($summary['potentialFreeBytes']) ? (int) $summary['potentialFreeBytes'] : 0, 1)
            );
            $next_step = __('Next step: Cleanup Apply with confirmation deletes only verified original JPG/PNG files for this job.', 'ultracache');
        }

        $response = array(
            'success'             => true,
            'message'             => $message,
            'jobId'               => $job_id,
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

        return $this->add_media_replacement_confirmation_token_to_response($response, $job_id, 'cleanup_apply', $cleanup_ready && empty($summary['cleanupComplete']) && !empty($summary['candidateItems']));
    }


    public function apply_media_library_replacement_cleanup($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array(
                'success' => false,
                'message' => __('Media Library replacement registry tables are not available.', 'ultracache'),
            );
        }

        $args = is_array($args) ? $args : array();
        $job_id = $this->get_media_replacement_preview_job_id(isset($args['job_id']) ? (string) $args['job_id'] : '');
        $limit  = isset($args['limit']) ? absint($args['limit']) : 50;
        $limit  = max(1, min(100, $limit));

        if ('' === $job_id) {
            return array(
                'success' => false,
                'message' => __('Run and verify Media Library replacement before applying cleanup.', 'ultracache'),
                'blocked' => true,
            );
        }
        if (!$this->media_replacement_job_has_registry_rows($job_id)) {
            $empty = $this->build_media_replacement_empty_registry_response($job_id, __('No Media Library replacement registry rows are available for cleanup apply.', 'ultracache'));
            $empty['blocked'] = true;
            return $empty;
        }

        $confirmation = $this->validate_media_replacement_confirmation_token($job_id, 'cleanup_apply', $args);
        if (empty($confirmation['success'])) {
            return $confirmation;
        }

        $recovered_cleanup_rows = $this->recover_media_replacement_cleanup_failed_rows($job_id);
        $summary = $this->get_media_replacement_cleanup_preview_summary($job_id);
        $hard_blocked_statuses = $this->get_media_replacement_cleanup_hard_blocked_status_count($job_id);
        $cleanup_ready = !empty($summary['cleanupReady']);
        if (!$cleanup_ready && !empty($summary)) {
            $total_items = isset($summary['totalItems']) ? max(0, (int) $summary['totalItems']) : 0;
            $ready_or_retryable_items = (int) ($summary['candidateItems'] ?? 0) + (int) ($summary['cleanupDeletedItems'] ?? 0) + (int) ($summary['cleanupFailedItems'] ?? 0);
            $cleanup_ready = $total_items > 0
                && $ready_or_retryable_items === $total_items
                && !empty($summary['databaseReady'])
                && 0 === (int) $hard_blocked_statuses;
        }

        if (empty($summary) || !$cleanup_ready) {
            return array(
                'success' => false,
                'message' => __('Cleanup apply is blocked until database verification and attachment metadata checks are complete. Run Cleanup Preview again and resolve any blocked rows before deleting originals.', 'ultracache'),
                'jobId'   => $job_id,
                'summary' => $summary,
                'blocked' => true,
                'status'  => 'cleanup_blocked',
                'cleanupCandidates' => isset($summary['candidateItems']) ? (int) $summary['candidateItems'] : 0,
                'cleanupBlockedItems' => isset($summary['blockedItems']) ? (int) $summary['blockedItems'] : 0,
                'cleanupDeleted' => isset($summary['cleanupDeletedItems']) ? (int) $summary['cleanupDeletedItems'] : 0,
                'cleanupFailed' => isset($summary['cleanupFailedItems']) ? (int) $summary['cleanupFailedItems'] : 0,
                'cleanupHardBlockedStatuses' => isset($hard_blocked_statuses) ? (int) $hard_blocked_statuses : 0,
                'recoveredCleanupRows' => isset($recovered_cleanup_rows) ? $recovered_cleanup_rows : array(),
                'databaseVerified' => isset($summary['databaseVerifiedRefs']) ? (int) $summary['databaseVerifiedRefs'] : 0,
                'databaseRefs' => isset($summary['databaseRefs']) ? (int) $summary['databaseRefs'] : 0,
                'nextStep' => __('Run Cleanup Preview again. Cleanup Apply will stay blocked until DB verification, attachment metadata, and row-status checks are complete.', 'ultracache'),
            );
        }

        $rows = $this->get_media_replacement_cleanup_candidate_rows($job_id, $limit);
        if (empty($rows)) {
            $summary = $this->get_media_replacement_cleanup_preview_summary($job_id);
            $failed_items = isset($summary['cleanupFailedItems']) ? (int) $summary['cleanupFailedItems'] : 0;
            if ($failed_items > 0) {
                return array(
                    'success' => false,
                    'message' => __('Media Library replacement cleanup has failed rows that could not be recovered automatically. Run Cleanup Preview and inspect the row messages before deleting more originals.', 'ultracache'),
                    'jobId' => $job_id,
                    'status' => 'cleanup_failed_rows_remaining',
                    'blocked' => true,
                    'hasMore' => false,
                    'deleted' => 0,
                    'failed' => $failed_items,
                    'remainingCleanup' => 0,
                    'summary' => $summary,
                    'progressPercent' => 100,
                    'nextStep' => __('Run Cleanup Preview and inspect the cleanup failed rows.', 'ultracache'),
                );
            }
            return array(
                'success' => true,
                'message' => __('Media Library replacement cleanup is complete. Original JPG/PNG files for verified registry rows have already been deleted or marked complete.', 'ultracache'),
                'jobId' => $job_id,
                'status' => 'cleanup_complete',
                'hasMore' => false,
                'deleted' => 0,
                'failed' => 0,
                'remainingCleanup' => 0,
                'summary' => $summary,
                'progressPercent' => 100,
                'nextStep' => __('Cleanup is complete. Review the site and keep backups until you are done testing.', 'ultracache'),
            );
        }

        $deleted = 0;
        $already_missing = 0;
        $failed = 0;
        $processed = 0;
        $deleted_paths = array();

        foreach ($rows as $row) {
            $item_id = isset($row['id']) ? absint($row['id']) : 0;
            $attachment_id = isset($row['attachment_id']) ? absint($row['attachment_id']) : 0;
            $item_scope = isset($row['item_scope']) ? sanitize_key((string) $row['item_scope']) : 'main';
            $size_name = isset($row['size_name']) ? substr(sanitize_key((string) $row['size_name']), 0, 64) : '';
            $old_file = isset($row['old_file_path']) ? wp_normalize_path((string) $row['old_file_path']) : '';
            $new_file = isset($row['new_file_path']) ? wp_normalize_path((string) $row['new_file_path']) : '';
            $new_relative = isset($row['new_relative_path']) ? ltrim(str_replace('\\', '/', (string) $row['new_relative_path']), '/') : '';
            $new_mime = isset($row['new_mime']) ? sanitize_mime_type((string) $row['new_mime']) : '';

            if ($item_id <= 0) {
                continue;
            }
            $processed++;

            if (!$this->is_media_replacement_upload_file_cleanup_allowed($old_file)) {
                $failed++;
                $this->update_media_replacement_cleanup_item_status($item_id, 'cleanup_failed', __('Original file path is outside the WordPress uploads directory or is not a JPG/PNG file.', 'ultracache'));
                continue;
            }

            if ('' === $new_file || !$this->optimized_storage_path_exists($new_file, true)) {
                $failed++;
                $this->update_media_replacement_cleanup_item_status($item_id, 'cleanup_failed', __('Replacement file is missing; original was not deleted.', 'ultracache'));
                continue;
            }

            $metadata_switched = $this->is_media_replacement_cleanup_row_metadata_switched($row);

            if (!$metadata_switched) {
                $failed++;
                $this->update_media_replacement_cleanup_item_status($item_id, 'cleanup_failed', __('Attachment metadata no longer points to the replacement file; original was not deleted.', 'ultracache'));
                continue;
            }

            if (!$this->optimized_storage_path_exists($old_file, true)) {
                $already_missing++;
                $this->update_media_replacement_cleanup_item_status($item_id, 'cleanup_deleted', __('Original file was already missing; row marked complete.', 'ultracache'));
                continue;
            }

            if (!isset($deleted_paths[$old_file])) {
                if (!function_exists('ultracache_safe_unlink') || !ultracache_safe_unlink($old_file, 'media_library_replacement_cleanup_original')) {
                    $failed++;
                    $this->update_media_replacement_cleanup_item_status($item_id, 'cleanup_failed', __('Original file could not be deleted.', 'ultracache'));
                    continue;
                }
                $deleted_paths[$old_file] = true;
                $deleted++;
            }

            if ($this->optimized_storage_path_exists($old_file, true)) {
                $failed++;
                $this->update_media_replacement_cleanup_item_status($item_id, 'cleanup_failed', __('Original file still exists after delete attempt.', 'ultracache'));
                continue;
            }

            $this->update_media_replacement_cleanup_item_status($item_id, 'cleanup_deleted', '');
        }

        $summary = $this->get_media_replacement_cleanup_preview_summary($job_id);
        $remaining = isset($summary['candidateItems']) ? max(0, (int) $summary['candidateItems']) : 0;
        $has_more = $remaining > 0;

        $completed_items = isset($summary['cleanupDeletedItems']) ? (int) $summary['cleanupDeletedItems'] : 0;
        $failed_items = isset($summary['cleanupFailedItems']) ? (int) $summary['cleanupFailedItems'] : 0;
        $total_items = isset($summary['totalItems']) ? max(0, (int) $summary['totalItems']) : 0;

        return array(
            'success' => true,
            'message' => $has_more
                ? sprintf(
                    /* translators: 1: processed rows, 2: deleted unique files, 3: already missing rows, 4: remaining rows. */
                    __('Media Library replacement cleanup processed %1$d rows in this batch. Deleted %2$d unique original files; %3$d rows were already missing or shared a file deleted earlier. Remaining cleanup rows: %4$d.', 'ultracache'),
                    (int) $processed,
                    (int) $deleted,
                    (int) $already_missing,
                    (int) $remaining
                )
                : sprintf(
                    /* translators: 1: processed rows, 2: deleted unique files, 3: already missing rows, 4: failed rows. */
                    __('Media Library replacement cleanup complete. Final batch processed %1$d rows. Deleted %2$d unique original files; %3$d rows were already missing or shared a file deleted earlier. Failed rows: %4$d.', 'ultracache'),
                    (int) $processed,
                    (int) $deleted,
                    (int) $already_missing,
                    $failed_items
                ),
            'jobId' => $job_id,
            'status' => $has_more ? 'cleanup_applying' : 'cleanup_complete',
            'hasMore' => $has_more,
            'processed' => $processed,
            'deleted' => $deleted,
            'alreadyMissing' => $already_missing,
            'failed' => $failed,
            'recoveredCleanupRows' => isset($recovered_cleanup_rows) ? $recovered_cleanup_rows : array(),
            'remainingCleanup' => $remaining,
            'summary' => $summary,
            'cleanupDeleted' => $completed_items,
            'cleanupFailed' => $failed_items,
            'cleanupCandidates' => isset($summary['candidateItems']) ? (int) $summary['candidateItems'] : 0,
            'progressPercent' => $total_items > 0 ? round((($completed_items + $failed_items) / max(1, $total_items)) * 100, 1) : 100,
            'nextStep' => $has_more ? __('Continue Cleanup Apply until all cleanup rows are processed.', 'ultracache') : __('Cleanup is complete. Review the site and keep backups until you are done testing.', 'ultracache'),
        );
    }

}
