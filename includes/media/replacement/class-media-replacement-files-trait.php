<?php
/**
 * UltraCache Media Library replacement file copying and destination validation.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Replacement_Files_Trait
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache uses private custom Media Library replacement registry tables with validated table identifiers.

    private function get_media_replacement_copy_rows($job_id, $limit = 50)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $job_id      = sanitize_key((string) $job_id);
        $limit       = max(1, min(250, absint($limit)));

        if ('' === $items_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, attachment_id, item_scope, size_name, source_format, target_format, fallback_format, old_relative_path, generated_file_path, old_size, new_size, status FROM %i WHERE job_id = %s AND status = %s ORDER BY id ASC LIMIT %d',
                $items_table,
                $job_id,
                'matched',
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private function get_media_replacement_copy_summary($job_id)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $job_id      = sanitize_key((string) $job_id);
        if ('' === $items_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT status, COUNT(*) AS item_count, SUM(new_size) AS new_total FROM %i WHERE job_id = %s GROUP BY status',
                $items_table,
                $job_id
            ),
            ARRAY_A
        );

        $summary = array(
            'total'             => 0,
            'matched'           => 0,
            'copied'            => 0,
            'metadata_ready'    => 0,
            'metadata_updated'  => 0,
            'refs_scanned'     => 0,
            'metadata_restored' => 0,
            'metadata_rollback_failed' => 0,
            'metadata_failed'   => 0,
            'skipped'           => 0,
            'failed'            => 0,
            'pending'           => 0,
            'copiedBytes'       => 0,
            'remainingToCopy'   => 0,
            'copyProgressItems' => 0,
            'copyProgressTotal' => 0,
        );

        foreach ((array) $rows as $row) {
            $status = isset($row['status']) ? sanitize_key((string) $row['status']) : '';
            $count  = isset($row['item_count']) ? max(0, (int) $row['item_count']) : 0;
            $summary['total'] += $count;
            if (array_key_exists($status, $summary)) {
                $summary[$status] += $count;
            }
            if (in_array($status, array('copied', 'metadata_ready', 'metadata_updated', 'refs_scanned', 'metadata_restored'), true)) {
                $summary['copiedBytes'] += isset($row['new_total']) ? max(0, (int) $row['new_total']) : 0;
            }
        }

        $summary['remainingToCopy']   = max(0, (int) $summary['matched']);
        $summary['copyProgressItems'] = max(0, (int) $summary['copied'] + (int) $summary['metadata_ready'] + (int) $summary['metadata_updated'] + (int) $summary['refs_scanned'] + (int) $summary['metadata_restored']);
        $summary['copyProgressTotal'] = max(0, (int) $summary['copied'] + (int) $summary['metadata_ready'] + (int) $summary['metadata_updated'] + (int) $summary['refs_scanned'] + (int) $summary['metadata_restored'] + (int) $summary['matched']);

        return $summary;
    }

    private function update_media_replacement_item_copy_result($item_id, array $data)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $item_id     = absint($item_id);
        if ('' === $items_table || $item_id <= 0 || !($wpdb instanceof wpdb)) {
            return false;
        }

        $row = array(
            'updated_at' => current_time('mysql', true),
        );
        $formats = array('%s');

        foreach (array('new_relative_path', 'new_url', 'new_file_path', 'new_metadata_json', 'status', 'error_message') as $key) {
            if (array_key_exists($key, $data)) {
                $row[$key] = $data[$key];
                $formats[] = 'error_message' === $key && null === $data[$key] ? '%s' : '%s';
            }
        }

        if (isset($row['status'])) {
            $row['status'] = in_array((string) $row['status'], array('matched', 'copied', 'metadata_ready', 'metadata_updated', 'metadata_failed', 'skipped', 'failed'), true) ? (string) $row['status'] : 'failed';
        }

        if (array_key_exists('error_message', $row) && null === $row['error_message']) {
            $row['error_message'] = null;
        }

        return false !== $wpdb->update(
            $items_table,
            $row,
            array('id' => $item_id),
            $formats,
            array('%d')
        );
    }

    private function build_media_replacement_destination_file_path($relative_path)
    {
        $uploads = function_exists('ultracache_uploads_base_info') ? ultracache_uploads_base_info() : wp_upload_dir(null, false);
        if (empty($uploads['basedir'])) {
            return '';
        }

        $relative_path = ltrim(str_replace('\\', '/', (string) $relative_path), '/');
        if ('' === $relative_path || false !== strpos($relative_path, "\0")) {
            return '';
        }

        foreach (explode('/', $relative_path) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return '';
            }
        }

        return trailingslashit(wp_normalize_path((string) $uploads['basedir'])) . $relative_path;
    }

    private function media_replacement_files_are_identical($source_file, $destination_file)
    {
        $source_file = wp_normalize_path((string) $source_file);
        $destination_file = wp_normalize_path((string) $destination_file);
        if ('' === $source_file || '' === $destination_file || !is_readable($source_file) || !is_readable($destination_file)) {
            return false;
        }

        $source_size = function_exists('ultracache_safe_filesize') ? (int) ultracache_safe_filesize($source_file, 'media_replacement_identity_source_size') : (int) filesize($source_file);
        $destination_size = function_exists('ultracache_safe_filesize') ? (int) ultracache_safe_filesize($destination_file, 'media_replacement_identity_destination_size') : (int) filesize($destination_file);
        if ($source_size <= 0 || $source_size !== $destination_size) {
            return false;
        }

        $source_hash = md5_file($source_file);
        $destination_hash = md5_file($destination_file);
        return is_string($source_hash) && is_string($destination_hash) && '' !== $source_hash && hash_equals($source_hash, $destination_hash);
    }

    private function copy_media_replacement_item_to_library(array $row)
    {
        $item_id       = isset($row['id']) ? absint($row['id']) : 0;
        $target_format = isset($row['target_format']) ? sanitize_key((string) $row['target_format']) : '';
        $old_relative  = isset($row['old_relative_path']) ? ltrim(str_replace('\\', '/', (string) $row['old_relative_path']), '/') : '';
        $generated     = isset($row['generated_file_path']) ? wp_normalize_path((string) $row['generated_file_path']) : '';

        if ($item_id <= 0 || !in_array($target_format, array('avif', 'webp'), true) || '' === $old_relative || '' === $generated) {
            return array('copied' => false, 'bytes' => 0, 'message' => __('Invalid Media Library replacement registry row.', 'ultracache'));
        }

        if (!$this->optimized_storage_path_exists($generated, true) || !$this->is_valid_generated_media_file($generated, $target_format, 'media_replacement_copy_source_validate')) {
            return array('copied' => false, 'bytes' => 0, 'message' => __('Generated UltraCache source file is missing or invalid.', 'ultracache'));
        }

        $destination = $this->build_media_replacement_planned_destination($old_relative, $target_format);
        $relative    = isset($destination['relativePath']) ? ltrim(str_replace('\\', '/', (string) $destination['relativePath']), '/') : '';
        $url         = isset($destination['url']) ? esc_url_raw((string) $destination['url']) : '';
        $target_file = $this->build_media_replacement_destination_file_path($relative);

        if ('' === $relative || '' === $target_file || '' === $url) {
            return array('copied' => false, 'bytes' => 0, 'message' => __('WordPress upload destination could not be resolved.', 'ultracache'));
        }

        $target_dir = dirname($target_file);
        if (!$this->optimized_storage_ensure_directory($target_dir)) {
            return array('copied' => false, 'bytes' => 0, 'message' => __('WordPress upload destination directory could not be created.', 'ultracache'));
        }
        $this->optimized_storage_harden_upload_permissions($target_dir, 'directory');

        $filesystem = $this->optimized_storage_filesystem();
        if (!$filesystem || !method_exists($filesystem, 'copy') || !method_exists($filesystem, 'exists')) {
            return array('copied' => false, 'bytes' => 0, 'message' => __('WordPress filesystem is not available for Media Library replacement copy.', 'ultracache'));
        }

        $reused_existing = false;
        $target_exists = $filesystem->exists($target_file);
        if ($target_exists) {
            $this->optimized_storage_forget_path($target_file);
            if ($this->optimized_storage_path_exists($target_file, true)
                && $this->is_valid_generated_media_file($target_file, $target_format, 'media_replacement_reuse_existing_destination_validate')
                && $this->media_replacement_files_are_identical($generated, $target_file)
            ) {
                $this->optimized_storage_harden_upload_permissions($target_file, 'file');
                $reused_existing = true;
            }
        }

        if (!$reused_existing) {
            if (!$filesystem->copy($generated, $target_file, true, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644)) {
                return array('copied' => false, 'bytes' => 0, 'message' => __('Generated image could not be copied into the WordPress uploads folder.', 'ultracache'));
            }

            $this->optimized_storage_harden_upload_permissions($target_file, 'file');
            $this->optimized_storage_forget_path($target_file);
            if (!$this->optimized_storage_path_exists($target_file, true)
                || !$this->is_valid_generated_media_file($target_file, $target_format, 'media_replacement_copy_destination_validate')
                || !$this->media_replacement_files_are_identical($generated, $target_file)
            ) {
                if (function_exists('ultracache_safe_unlink')) {
                    ultracache_safe_unlink($target_file, 'media_replacement_copy_invalid_destination');
                }
                return array('copied' => false, 'bytes' => 0, 'message' => __('Copied file failed validation or does not match the current UltraCache rewrite output.', 'ultracache'));
            }
        }

        $bytes = function_exists('ultracache_safe_filesize') ? max(0, (int) ultracache_safe_filesize($target_file, 'media_replacement_copy_destination_size')) : 0;
        $metadata_plan = array(
            'copied_from'       => $generated,
            'copied_to'         => $target_file,
            'new_relative_path' => $relative,
            'new_url'           => $url,
            'target_format'     => $target_format,
            'copy_skipped'      => $reused_existing,
            'reused_existing'   => $reused_existing,
            'copied_at'         => current_time('mysql', true),
            'metadata_updated'  => false,
            'db_replaced'       => false,
        );
        $metadata_json = function_exists('wp_json_encode') ? wp_json_encode($metadata_plan) : '{}';

        $updated = $this->update_media_replacement_item_copy_result($item_id, array(
            'new_relative_path' => $relative,
            'new_url'           => $url,
            'new_file_path'     => $target_file,
            'new_metadata_json' => is_string($metadata_json) ? $metadata_json : '{}',
            'status'            => 'copied',
            'error_message'     => $reused_existing ? __('Existing WordPress upload replacement file reused; copy skipped.', 'ultracache') : null,
        ));

        if (!$updated) {
            return array('copied' => false, 'bytes' => 0, 'message' => __('Copied file registry update failed.', 'ultracache'));
        }

        return array('copied' => true, 'bytes' => $bytes, 'message' => '');
    }

    public function copy_media_library_replacement_files($args = array())
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
                'message' => __('Run Prepare before copying replacement files.', 'ultracache'),
            );
        }
        if (!$this->media_replacement_job_has_registry_rows($job_id)) {
            return $this->build_media_replacement_empty_registry_response($job_id, __('No replacement registry rows are available to copy.', 'ultracache'));
        }

        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        $limit = max(1, min(250, $limit));
        $time_budget = isset($args['time_budget']) && (float) $args['time_budget'] > 0 ? (float) $args['time_budget'] : 15.0;
        $time_budget = max(1.0, min(30.0, $time_budget));
        $deadline = microtime(true) + $time_budget;
        $rows = $this->get_media_replacement_copy_rows($job_id, $limit);

        $copied = 0;
        $failed = 0;
        $bytes = 0;
        foreach ($rows as $row) {
            if (($copied + $failed) > 0 && microtime(true) >= $deadline) {
                break;
            }

            $item_id = isset($row['id']) ? absint($row['id']) : 0;
            $result = $this->copy_media_replacement_item_to_library($row);
            if (!empty($result['copied'])) {
                $copied++;
                $bytes += isset($result['bytes']) ? max(0, (int) $result['bytes']) : 0;
            } else {
                $failed++;
                $this->update_media_replacement_item_copy_result($item_id, array(
                    'status'        => 'failed',
                    'error_message' => isset($result['message']) ? wp_strip_all_tags((string) $result['message']) : __('Copy failed.', 'ultracache'),
                ));
            }
        }

        $summary = $this->get_media_replacement_copy_summary($job_id);
        $has_more = !empty($summary['remainingToCopy']);
        $state = $this->normalize_media_replacement_job_state($this->get_media_replacement_active_job_data());
        $state['heartbeat_at'] = current_time('mysql', true);
        $state['updated_at'] = current_time('mysql', true);

        if ((int) $summary['failed'] > 0) {
            $state['status'] = 'failed';
            $state['run_status'] = 'failed';
            $state['active_step'] = 'prepare_failed';
            $state['last_error'] = __('Prepare stopped because one or more destination replacement files could not be copied or validated.', 'ultracache');
        } elseif ($has_more) {
            $state['status'] = 'copying';
            $state['run_status'] = 'running';
            $state['active_step'] = 'copy';
        } else {
            $state['status'] = 'validating';
            $state['run_status'] = 'running';
            $state['active_step'] = 'validate';
            $state['validation_cursor_item_id'] = 0;
            $state['validated_items'] = 0;
            $state['validation_failed'] = 0;
        }
        $state = $this->update_media_replacement_active_job_state($state);

        $total = max(0, (int) $summary['copyProgressTotal']);
        $progress = $total > 0 ? min(100, round(((int) $summary['copyProgressItems'] / $total) * 100, 1)) : 100;
        $success = 'failed' !== $state['run_status'];

        return array(
            'success'         => $success,
            'message'         => !$success
                ? $state['last_error']
                : ($has_more
                    /* translators: %1$d: copied file count; %2$d: total replacement file count. */
                    ? sprintf(__('Prepare copied %1$d of %2$d replacement files.', 'ultracache'), (int) $summary['copied'], $total)
                    /* translators: %1$d: total copied or reused replacement file count. */
                    : sprintf(__('Prepare copied or reused all %1$d replacement files. Destination validation is next.', 'ultracache'), (int) $summary['copied'])),
            'jobId'           => $job_id,
            'generation'      => $state['generation'],
            'status'          => $state['status'],
            'activeStep'      => $state['active_step'],
            'hasMore'         => $has_more,
            'batchSize'       => $limit,
            'batchCopied'     => $copied,
            'batchFailed'     => $failed,
            'batchBytes'      => $bytes,
            'copied'          => (int) $summary['copied'],
            'remainingToCopy' => (int) $summary['remainingToCopy'],
            'failed'          => (int) $summary['failed'],
            'copiedBytes'     => (int) $summary['copiedBytes'],
            'progressPercent' => $progress,
            'filesCopiedOnly' => true,
            'metadataUpdated' => false,
            'databaseReplaced'=> false,
            'nextStep'        => $has_more ? __('Continue copying replacement files.', 'ultracache') : __('Validate every copied/reused destination file before Prepare completes.', 'ultracache'),
        );
    }

    private function get_media_replacement_prepare_validation_rows($job_id, $after_item_id = 0, $limit = 50)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $job_id = sanitize_key((string) $job_id);
        $after_item_id = max(0, absint($after_item_id));
        $limit = max(1, min(250, absint($limit)));
        if ('' === $items_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, target_format, generated_file_path, new_relative_path, new_file_path FROM %i WHERE job_id = %s AND status = %s AND id > %d ORDER BY id ASC LIMIT %d',
            $items_table,
            $job_id,
            'copied',
            $after_item_id,
            $limit
        ), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    private function validate_media_replacement_destination_row(array $row)
    {
        $target_format = sanitize_key((string) ($row['target_format'] ?? ''));
        $relative_path = ltrim(str_replace('\\', '/', (string) ($row['new_relative_path'] ?? '')), '/');
        $generated_path = wp_normalize_path((string) ($row['generated_file_path'] ?? ''));
        $stored_path = wp_normalize_path((string) ($row['new_file_path'] ?? ''));
        $expected_path = wp_normalize_path($this->build_media_replacement_destination_file_path($relative_path));

        if (!in_array($target_format, array('avif', 'webp'), true) || '' === $relative_path || '' === $generated_path || '' === $stored_path || '' === $expected_path || $stored_path !== $expected_path) {
            return array('valid' => false, 'message' => __('The copied replacement destination path is inconsistent.', 'ultracache'));
        }

        if (!$this->optimized_storage_path_exists($generated_path, true)
            || !$this->is_valid_generated_media_file($generated_path, $target_format, 'media_replacement_prepare_source_revalidate')
            || !$this->optimized_storage_path_exists($stored_path, true)
            || !$this->is_valid_generated_media_file($stored_path, $target_format, 'media_replacement_prepare_destination_validate')
            || !$this->media_replacement_files_are_identical($generated_path, $stored_path)
        ) {
            return array('valid' => false, 'message' => __('The destination replacement file is missing, invalid, or no longer matches the current UltraCache rewrite output.', 'ultracache'));
        }

        return array('valid' => true, 'message' => '');
    }

    private function validate_media_library_replacement_destination_files($args = array())
    {
        $args = is_array($args) ? $args : array();
        $state = $this->normalize_media_replacement_job_state($this->get_media_replacement_active_job_data());
        $job_id = sanitize_key((string) ($args['job_id'] ?? $state['job_id']));
        if ('' === $job_id || $job_id !== $state['job_id']) {
            return array('success' => false, 'message' => __('The active Prepare job could not be resolved.', 'ultracache'));
        }

        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        $limit = max(1, min(250, $limit));
        $time_budget = isset($args['time_budget']) && (float) $args['time_budget'] > 0 ? (float) $args['time_budget'] : 15.0;
        $time_budget = max(1.0, min(30.0, $time_budget));
        $deadline = microtime(true) + $time_budget;
        $rows = $this->get_media_replacement_prepare_validation_rows($job_id, $state['validation_cursor_item_id'], $limit);
        $validated = 0;
        $failed = 0;
        $last_id = $state['validation_cursor_item_id'];

        foreach ($rows as $row) {
            if (($validated + $failed) > 0 && microtime(true) >= $deadline) {
                break;
            }
            $item_id = absint($row['id'] ?? 0);
            $result = $this->validate_media_replacement_destination_row($row);
            if (empty($result['valid'])) {
                $failed++;
                $this->update_media_replacement_item_copy_result($item_id, array(
                    'status'        => 'failed',
                    'error_message' => wp_strip_all_tags((string) ($result['message'] ?? __('Destination validation failed.', 'ultracache'))),
                ));
            } else {
                $validated++;
            }
            $last_id = max($last_id, $item_id);
        }

        $state['validation_cursor_item_id'] = $last_id;
        $state['validated_items'] += $validated;
        $state['validation_failed'] += $failed;
        $state['heartbeat_at'] = current_time('mysql', true);
        $state['updated_at'] = current_time('mysql', true);
        $has_more = !empty($this->get_media_replacement_prepare_validation_rows($job_id, $last_id, 1));
        $summary = $this->get_media_replacement_copy_summary($job_id);

        $final_guard = !$has_more ? $this->get_media_library_replacement_start_guard(array('generation' => $state['readiness_generation'])) : array('allowed' => true);
        $validation_count_mismatch = !$has_more && (int) $state['validated_items'] !== (int) $summary['copied'];
        if ($state['validation_failed'] > 0 || (int) $summary['failed'] > 0 || $validation_count_mismatch || empty($final_guard['allowed'])) {
            $state['status'] = 'failed';
            $state['run_status'] = 'failed';
            $state['active_step'] = 'prepare_failed';
            $state['last_error'] = empty($final_guard['allowed'])
                ? __('Prepare stopped because the readiness guard changed before destination validation completed.', 'ultracache')
                : __('Prepare stopped because destination validation failed or did not cover every copied replacement file.', 'ultracache');
            $has_more = false;
        } elseif ($has_more) {
            $state['status'] = 'validating';
            $state['run_status'] = 'running';
            $state['active_step'] = 'validate';
        } else {
            $state['status'] = 'planning_metadata';
            $state['run_status'] = 'running';
            $state['active_step'] = 'metadata_plan';
            $state['completed_at'] = '';
            $state['workflow_stage'] = 'prepare';
            $state['workflow_message'] = __('Destination replacement files are validated. Prepare is building attachment metadata plans.', 'ultracache');
            $state['workflow_updated_at'] = current_time('mysql', true);
        }
        $state = $this->update_media_replacement_active_job_state($state);

        $total = max(0, (int) $summary['copied']);
        return array(
            'success'        => 'failed' !== $state['run_status'],
            'message'        => 'failed' === $state['run_status']
                ? $state['last_error']
                : ($has_more
                    /* translators: %1$d: validated destination count; %2$d: total destination count. */
                    ? sprintf(__('Prepare validated %1$d of %2$d destination files.', 'ultracache'), (int) $state['validated_items'], $total)
                    /* translators: %1$d: total validated destination replacement file count. */
                    : sprintf(__('Prepare completed. All %1$d destination replacement files were copied/reused and validated.', 'ultracache'), $total)),
            'jobId'          => $job_id,
            'generation'     => $state['generation'],
            'status'         => $state['status'],
            'activeStep'     => $state['active_step'],
            'hasMore'        => $has_more,
            'batchValidated' => $validated,
            'batchFailed'    => $failed,
            'validated'      => (int) $state['validated_items'],
            'validationFailed' => (int) $state['validation_failed'],
            'totalToValidate'=> $total,
            'prepareComplete'=> false,
            'nextStep'       => $has_more ? __('Continue destination validation.', 'ultracache') : __('Continue Prepare to build metadata, database, and Theme CSS plans.', 'ultracache'),
        );
    }




}
