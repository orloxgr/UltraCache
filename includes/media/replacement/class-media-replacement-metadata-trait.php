<?php
/**
 * UltraCache Media Library replacement attachment metadata planning, apply, verification, and rollback.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Replacement_Metadata_Trait
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache uses private custom Media Library replacement registry tables with validated table identifiers.

    private function decode_media_replacement_json_array($json)
    {
        if (!is_string($json) || '' === trim($json)) {
            return array();
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function get_media_replacement_metadata_rows($limit = 50)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $limit       = max(1, min(250, absint($limit)));

        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, attachment_id, item_scope, size_name, target_format, fallback_format, old_relative_path, old_file_path, new_relative_path, new_url, new_file_path, old_mime, new_mime, old_size, new_size, old_metadata_json, new_metadata_json, status FROM %i WHERE status = %s ORDER BY id ASC LIMIT %d',
                $items_table,
                'copied',
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private function get_media_replacement_metadata_summary()
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT status, COUNT(*) AS item_count FROM %i GROUP BY status',
                $items_table
            ),
            ARRAY_A
        );

        $summary = array(
            'total'                 => 0,
            'copied'                => 0,
            'metadataReady'         => 0,
            'metadataUpdated'       => 0,
            'metadataFailed'        => 0,
            'failed'                => 0,
            'remainingToPrepare'    => 0,
            'metadataProgressItems' => 0,
            'metadataProgressTotal' => 0,
        );

        foreach ((array) $rows as $row) {
            $status = isset($row['status']) ? sanitize_key((string) $row['status']) : '';
            $count  = isset($row['item_count']) ? max(0, (int) $row['item_count']) : 0;
            $summary['total'] += $count;

            if ('copied' === $status) {
                $summary['copied'] += $count;
            } elseif ('metadata_ready' === $status) {
                $summary['metadataReady'] += $count;
            } elseif ('metadata_updated' === $status) {
                $summary['metadataUpdated'] += $count;
            } elseif ('metadata_failed' === $status) {
                $summary['metadataFailed'] += $count;
            } elseif ('failed' === $status) {
                $summary['failed'] += $count;
            }
        }

        $summary['remainingToPrepare']    = max(0, (int) $summary['copied']);
        $summary['metadataProgressItems'] = max(0, (int) $summary['metadataReady'] + (int) $summary['metadataUpdated']);
        $summary['metadataProgressTotal'] = max(0, (int) $summary['metadataReady'] + (int) $summary['metadataUpdated'] + (int) $summary['copied']);

        return $summary;
    }

    private function get_media_replacement_image_dimensions($file_path, array $fallback_metadata)
    {
        $width  = isset($fallback_metadata['width']) ? absint($fallback_metadata['width']) : 0;
        $height = isset($fallback_metadata['height']) ? absint($fallback_metadata['height']) : 0;

        if (function_exists('wp_getimagesize')) {
            $dimensions = wp_getimagesize($file_path);
            if (is_array($dimensions)) {
                $maybe_width  = isset($dimensions[0]) ? absint($dimensions[0]) : 0;
                $maybe_height = isset($dimensions[1]) ? absint($dimensions[1]) : 0;
                if ($maybe_width > 0 && $maybe_height > 0) {
                    $width  = $maybe_width;
                    $height = $maybe_height;
                }
            }
        }

        return array($width, $height);
    }


    private function is_media_replacement_intermediate_item_row(array $row)
    {
        return isset($row['item_scope']) && 'intermediate' === sanitize_key((string) $row['item_scope']);
    }

    private function build_media_replacement_intermediate_metadata_plan(array $row)
    {
        $item_id       = isset($row['id']) ? absint($row['id']) : 0;
        $attachment_id = isset($row['attachment_id']) ? absint($row['attachment_id']) : 0;
        $size_name     = isset($row['size_name']) ? substr(sanitize_key((string) $row['size_name']), 0, 64) : '';
        $target_format = isset($row['target_format']) ? sanitize_key((string) $row['target_format']) : '';
        $new_file      = isset($row['new_file_path']) ? wp_normalize_path((string) $row['new_file_path']) : '';
        $new_relative  = isset($row['new_relative_path']) ? ltrim(str_replace('\\', '/', (string) $row['new_relative_path']), '/') : '';
        $new_mime      = isset($row['new_mime']) ? sanitize_mime_type((string) $row['new_mime']) : '';

        if ($item_id <= 0 || $attachment_id <= 0 || '' === $size_name || !in_array($target_format, array('avif', 'webp'), true) || '' === $new_file || '' === $new_relative || '' === $new_mime) {
            return array('prepared' => false, 'message' => __('Invalid Media Library replacement intermediate metadata row.', 'ultracache'));
        }

        if (!$this->optimized_storage_path_exists($new_file, true) || !$this->is_valid_generated_media_file($new_file, $target_format, 'media_replacement_intermediate_metadata_destination_validate')) {
            return array('prepared' => false, 'message' => __('Copied intermediate replacement file is missing or invalid.', 'ultracache'));
        }

        $current_metadata = wp_get_attachment_metadata($attachment_id);
        $current_metadata = is_array($current_metadata) ? $current_metadata : array();
        if (empty($current_metadata['sizes']) || !is_array($current_metadata['sizes']) || empty($current_metadata['sizes'][$size_name]) || !is_array($current_metadata['sizes'][$size_name])) {
            return array('prepared' => false, 'message' => __('Current attachment metadata does not contain this intermediate size.', 'ultracache'));
        }

        $old_metadata = $this->decode_media_replacement_json_array(isset($row['old_metadata_json']) ? (string) $row['old_metadata_json'] : '');
        $old_size_entry = array();
        if (!empty($old_metadata['sizes']) && is_array($old_metadata['sizes']) && !empty($old_metadata['sizes'][$size_name]) && is_array($old_metadata['sizes'][$size_name])) {
            $old_size_entry = $old_metadata['sizes'][$size_name];
        }
        if (empty($old_size_entry)) {
            $old_size_entry = $current_metadata['sizes'][$size_name];
        }

        $new_size_entry = $current_metadata['sizes'][$size_name];
        $new_size_entry['file'] = basename($new_relative);
        $new_size_entry['mime-type'] = $new_mime;
        $new_size_entry['filesize'] = function_exists('ultracache_safe_filesize') ? max(0, (int) ultracache_safe_filesize($new_file, 'media_replacement_intermediate_metadata_new_file_size')) : max(0, (int) $row['new_size']);

        list($width, $height) = $this->get_media_replacement_image_dimensions($new_file, $new_size_entry);
        if ($width > 0) {
            $new_size_entry['width'] = $width;
        }
        if ($height > 0) {
            $new_size_entry['height'] = $height;
        }

        $plan = array(
            'metadata_prepared'       => true,
            'metadata_update_pending' => true,
            'item_scope'              => 'intermediate',
            'attachment_id'           => $attachment_id,
            'size_name'               => $size_name,
            'old_size_file'           => isset($old_size_entry['file']) ? (string) $old_size_entry['file'] : '',
            'new_size_file'           => basename($new_relative),
            'old_post_mime_type'      => isset($row['old_mime']) ? sanitize_mime_type((string) $row['old_mime']) : '',
            'new_post_mime_type'      => $new_mime,
            'old_metadata'            => $old_metadata,
            'previous_size_entry'     => $current_metadata['sizes'][$size_name],
            'old_size_entry'          => $old_size_entry,
            'new_size_entry'          => $new_size_entry,
            'new_relative_path'       => $new_relative,
            'database_replacement_ready' => false,
            'prepared_at'             => current_time('mysql', true),
        );

        return array('prepared' => true, 'plan' => $plan, 'message' => '');
    }


    private function build_media_replacement_metadata_plan(array $row)
    {
        if ($this->is_media_replacement_intermediate_item_row($row)) {
            return $this->build_media_replacement_intermediate_metadata_plan($row);
        }

        $item_id       = isset($row['id']) ? absint($row['id']) : 0;
        $attachment_id = isset($row['attachment_id']) ? absint($row['attachment_id']) : 0;
        $target_format = isset($row['target_format']) ? sanitize_key((string) $row['target_format']) : '';
        $new_file      = isset($row['new_file_path']) ? wp_normalize_path((string) $row['new_file_path']) : '';
        $new_relative  = isset($row['new_relative_path']) ? ltrim(str_replace('\\', '/', (string) $row['new_relative_path']), '/') : '';
        $new_mime      = isset($row['new_mime']) ? sanitize_mime_type((string) $row['new_mime']) : '';

        if ($item_id <= 0 || $attachment_id <= 0 || !in_array($target_format, array('avif', 'webp'), true) || '' === $new_file || '' === $new_relative || '' === $new_mime) {
            return array('prepared' => false, 'message' => __('Invalid Media Library replacement metadata row.', 'ultracache'));
        }

        if (!$this->optimized_storage_path_exists($new_file, true) || !$this->is_valid_generated_media_file($new_file, $target_format, 'media_replacement_metadata_destination_validate')) {
            return array('prepared' => false, 'message' => __('Copied replacement file is missing or invalid.', 'ultracache'));
        }

        $old_metadata = $this->decode_media_replacement_json_array(isset($row['old_metadata_json']) ? (string) $row['old_metadata_json'] : '');
        if (empty($old_metadata)) {
            $current_metadata = wp_get_attachment_metadata($attachment_id);
            $old_metadata = is_array($current_metadata) ? $current_metadata : array();
        }

        $planned_metadata = $old_metadata;
        if (!is_array($planned_metadata)) {
            $planned_metadata = array();
        }

        list($width, $height) = $this->get_media_replacement_image_dimensions($new_file, $planned_metadata);
        if ($width > 0) {
            $planned_metadata['width'] = $width;
        }
        if ($height > 0) {
            $planned_metadata['height'] = $height;
        }

        $planned_metadata['file']     = $new_relative;
        $planned_metadata['filesize'] = function_exists('ultracache_safe_filesize') ? max(0, (int) ultracache_safe_filesize($new_file, 'media_replacement_metadata_new_file_size')) : max(0, (int) $row['new_size']);

        $plan = array(
            'metadata_prepared'          => true,
            'metadata_update_pending'    => true,
            'attachment_id'              => $attachment_id,
            'old_attached_file'          => isset($row['old_relative_path']) ? ltrim(str_replace('\\', '/', (string) $row['old_relative_path']), '/') : '',
            'new_attached_file'          => $new_relative,
            'old_post_mime_type'         => isset($row['old_mime']) ? sanitize_mime_type((string) $row['old_mime']) : '',
            'new_post_mime_type'         => $new_mime,
            'old_metadata'               => $old_metadata,
            'planned_metadata'           => $planned_metadata,
            'existing_sizes_preserved'   => !empty($planned_metadata['sizes']) && is_array($planned_metadata['sizes']),
            'database_replacement_ready' => false,
            'prepared_at'                => current_time('mysql', true),
        );

        return array('prepared' => true, 'plan' => $plan, 'message' => '');
    }

    private function update_media_replacement_item_metadata_result($item_id, array $data)
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

        foreach (array('new_metadata_json', 'status', 'error_message') as $key) {
            if (array_key_exists($key, $data)) {
                $row[$key] = $data[$key];
                $formats[] = '%s';
            }
        }

        if (isset($row['status'])) {
            $row['status'] = in_array((string) $row['status'], array('copied', 'metadata_ready', 'metadata_updated', 'refs_scanned', 'metadata_restored', 'metadata_failed', 'metadata_rollback_failed', 'failed'), true) ? (string) $row['status'] : 'metadata_failed';
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

    private function prepare_media_replacement_item_metadata(array $row)
    {
        $item_id = isset($row['id']) ? absint($row['id']) : 0;
        if ($item_id <= 0) {
            return array('prepared' => false, 'message' => __('Invalid Media Library replacement item.', 'ultracache'));
        }

        $plan = $this->build_media_replacement_metadata_plan($row);
        if (empty($plan['prepared'])) {
            $this->update_media_replacement_item_metadata_result($item_id, array(
                'status'        => 'metadata_failed',
                'error_message' => isset($plan['message']) ? wp_strip_all_tags((string) $plan['message']) : __('Metadata plan preparation failed.', 'ultracache'),
            ));
            return array('prepared' => false, 'message' => isset($plan['message']) ? (string) $plan['message'] : '');
        }

        $metadata_json = function_exists('wp_json_encode') ? wp_json_encode($plan['plan']) : '{}';
        $updated = $this->update_media_replacement_item_metadata_result($item_id, array(
            'new_metadata_json' => is_string($metadata_json) ? $metadata_json : '{}',
            'status'            => 'metadata_ready',
            'error_message'     => null,
        ));

        if (!$updated) {
            return array('prepared' => false, 'message' => __('Metadata plan registry update failed.', 'ultracache'));
        }

        return array('prepared' => true, 'message' => '');
    }

    public function prepare_media_library_replacement_metadata_updates($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array(
                'success' => false,
                'message' => __('Media Library replacement registry tables are not available.', 'ultracache'),
            );
        }

        if (!$this->media_replacement_has_registry_rows()) {
            return $this->build_media_replacement_empty_registry_response(__('No Media Library replacement registry rows are available for metadata preparation.', 'ultracache'));
        }

        $args = is_array($args) ? $args : array();
        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        $limit = max(1, min(250, $limit));
        $time_budget = isset($args['time_budget']) && (float) $args['time_budget'] > 0 ? (float) $args['time_budget'] : 15.0;
        $time_budget = max(1.0, min(30.0, $time_budget));
        $deadline = microtime(true) + $time_budget;
        $rows = $this->get_media_replacement_metadata_rows($limit);

        $prepared = 0;
        $failed = 0;
        foreach ($rows as $row) {
            if (($prepared + $failed) > 0 && microtime(true) >= $deadline) {
                break;
            }
            $result = $this->prepare_media_replacement_item_metadata($row);
            if (!empty($result['prepared'])) {
                $prepared++;
            } else {
                $failed++;
            }
        }

        $summary = $this->get_media_replacement_metadata_summary();
        $row_planning_has_more = !empty($summary['remainingToPrepare']);
        $attachment_plans = array('prepared' => 0, 'failed' => 0, 'remaining' => 0);
        if (!$row_planning_has_more && 0 === (int) $summary['metadataFailed']) {
            $attachment_plans = $this->materialize_media_replacement_attachment_metadata_plans($limit, $deadline);
        }
        $has_more = $row_planning_has_more || !empty($attachment_plans['remaining']);
        $total = max(0, (int) $summary['metadataProgressTotal']);
        $progress = $total > 0 ? min(100, round(((int) $summary['metadataProgressItems'] / $total) * 100, 1)) : 100;

        return array(
            'success'              => 0 === (int) $summary['metadataFailed'],
            'message'              => (int) $summary['metadataFailed'] > 0
                /* translators: %d: failed registry row count. */
                ? sprintf(__('Metadata planning stopped with %d failed registry rows.', 'ultracache'), (int) $summary['metadataFailed'])
                : ($has_more
                    /* translators: %1$d: prepared replacement row count; %2$d: total replacement row count. */
                    ? sprintf(__('Metadata planning: %1$d of %2$d replacement rows prepared.', 'ultracache'), (int) $summary['metadataReady'], $total)
                    /* translators: %d: prepared replacement row count. */
                    : sprintf(__('Metadata planning completed for %d replacement rows.', 'ultracache'), (int) $summary['metadataReady'])),
            'status'               => (int) $summary['metadataFailed'] > 0 ? 'metadata_plan_failed' : ($has_more ? 'metadata_preparing' : 'metadata_ready'),
            'hasMore'              => $has_more && 0 === (int) $summary['metadataFailed'],
            'batchSize'            => $limit,
            'batchPrepared'        => $prepared,
            'batchFailed'          => $failed + max(0, (int) ($attachment_plans['failed'] ?? 0)),
            'batchAttachmentPlansPrepared' => max(0, (int) ($attachment_plans['prepared'] ?? 0)),
            'remainingAttachmentPlans' => max(0, (int) ($attachment_plans['remaining'] ?? 0)),
            'metadataPrepared'     => (int) $summary['metadataReady'],
            'remainingMetadata'    => (int) $summary['remainingToPrepare'],
            'metadataFailed'       => (int) $summary['metadataFailed'],
            'progressPercent'      => $progress,
            'metadataPlanReady'    => !$has_more && 0 === (int) $summary['metadataFailed'],
            'metadataUpdated'      => false,
            'databaseReplaced'     => false,
            'nextStep'             => $has_more
                ? __('Continue metadata planning. No attachment metadata has been changed.', 'ultracache')
                : __('Continue Prepare with the database-wide reference index. No attachment metadata has been changed.', 'ultracache'),
        );
    }


    private function get_media_replacement_attachment_plan_registry_rows($attachment_id)
    {
        global $wpdb;

        $items_table  = $this->get_media_replacement_items_table_name();
        $attachment_id = absint($attachment_id);
        if ('' === $items_table || $attachment_id <= 0 || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, attachment_id, item_scope, size_name, target_format, old_relative_path, new_relative_path, new_file_path, old_mime, new_mime, old_metadata_json, new_metadata_json, status FROM %i WHERE attachment_id = %d AND status IN (%s, %s, %s, %s, %s, %s, %s, %s) ORDER BY id ASC',
                $items_table,
                $attachment_id,
                'metadata_ready',
                'metadata_updated',
                'metadata_failed',
                'refs_scanned',
                'cleanup_deleted',
                'cleanup_failed',
                'metadata_restored',
                'metadata_rollback_failed'
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private function media_replacement_item_status_has_applied_metadata($status)
    {
        return in_array(sanitize_key((string) $status), array('metadata_updated', 'refs_scanned', 'cleanup_deleted', 'cleanup_failed'), true);
    }

    private function build_media_replacement_attachment_metadata_plan_from_rows($attachment_id, array $rows)
    {
        $attachment_id = absint($attachment_id);
        $main_row      = array();
        $main_plan     = array();
        $intermediate_plans = array();

        foreach ($rows as $row) {
            $scope = isset($row['item_scope']) ? sanitize_key((string) $row['item_scope']) : 'main';
            $item_plan = $this->decode_media_replacement_json_array(isset($row['new_metadata_json']) ? (string) $row['new_metadata_json'] : '');
            if ('main' === $scope) {
                $main_row  = $row;
                $main_plan = $item_plan;
                continue;
            }
            if ('intermediate' === $scope) {
                $size_name = isset($row['size_name']) ? substr(sanitize_key((string) $row['size_name']), 0, 64) : '';
                if ('' !== $size_name && !empty($item_plan['new_size_entry']) && is_array($item_plan['new_size_entry'])) {
                    $intermediate_plans[$size_name] = $item_plan;
                }
            }
        }

        if ($attachment_id <= 0 || empty($main_row) || empty($main_plan['metadata_prepared']) || empty($main_plan['old_metadata']) || !is_array($main_plan['old_metadata']) || empty($main_plan['planned_metadata']) || !is_array($main_plan['planned_metadata'])) {
            return array('prepared' => false, 'message' => __('The prepared replacement registry does not contain a complete main attachment metadata plan.', 'ultracache'));
        }

        $old_attached_file = isset($main_plan['old_attached_file']) ? ltrim(str_replace('\\', '/', (string) $main_plan['old_attached_file']), '/') : '';
        $new_attached_file = isset($main_plan['new_attached_file']) ? ltrim(str_replace('\\', '/', (string) $main_plan['new_attached_file']), '/') : '';
        $old_mime          = isset($main_plan['old_post_mime_type']) ? sanitize_mime_type((string) $main_plan['old_post_mime_type']) : '';
        $new_mime          = isset($main_plan['new_post_mime_type']) ? sanitize_mime_type((string) $main_plan['new_post_mime_type']) : '';
        $old_metadata      = $main_plan['old_metadata'];
        $final_metadata    = $main_plan['planned_metadata'];

        if ('' === $old_attached_file || '' === $new_attached_file || '' === $new_mime) {
            return array('prepared' => false, 'message' => __('The prepared replacement main attachment transition is incomplete.', 'ultracache'));
        }

        if (!isset($final_metadata['sizes']) || !is_array($final_metadata['sizes'])) {
            $final_metadata['sizes'] = array();
        }
        foreach ($intermediate_plans as $size_name => $item_plan) {
            $final_metadata['sizes'][$size_name] = $item_plan['new_size_entry'];
        }

        $plan_payload = array(
            'plan_version'      => 1,
            'attachment_id'     => $attachment_id,
            'main_item_id'      => absint($main_row['id'] ?? 0),
            'old_attached_file' => $old_attached_file,
            'new_attached_file' => $new_attached_file,
            'old_mime'          => $old_mime,
            'new_mime'          => $new_mime,
            'old_metadata'      => $old_metadata,
            'final_metadata'    => $final_metadata,
        );
        $encoded = wp_json_encode($plan_payload);
        $plan_payload['plan_hash'] = is_string($encoded) ? hash('sha256', $encoded) : '';
        $plan_payload['prepared_at'] = current_time('mysql', true);

        return array('prepared' => true, 'plan' => $plan_payload, 'main_row' => $main_row, 'main_plan' => $main_plan, 'message' => '');
    }

    private function persist_media_replacement_attachment_metadata_plan(array $plan)
    {
        global $wpdb;

        $table = $this->get_media_replacement_attachment_plans_table_name();
        $attachment_id = absint($plan['attachment_id'] ?? 0);
        if ('' === $table || $attachment_id <= 0 || !($wpdb instanceof wpdb)) {
            return false;
        }

        $old_json   = wp_json_encode((array) ($plan['old_metadata'] ?? array()));
        $final_json = wp_json_encode((array) ($plan['final_metadata'] ?? array()));
        $now        = current_time('mysql', true);
        $existing   = $wpdb->get_row(
            $wpdb->prepare('SELECT id, plan_hash, status FROM %i WHERE attachment_id = %d LIMIT 1', $table, $attachment_id),
            ARRAY_A
        );
        $status = in_array((string) ($plan['initial_status'] ?? ''), array('prepared', 'applied'), true) ? (string) $plan['initial_status'] : 'prepared';
        if (is_array($existing) && hash_equals((string) ($existing['plan_hash'] ?? ''), (string) ($plan['plan_hash'] ?? '')) && in_array((string) ($existing['status'] ?? ''), array('applied', 'restored'), true)) {
            $status = (string) $existing['status'];
        }

        $row = array(
            'job_id'              => '', // Legacy schema column; the singleton workflow owns every row.
            'attachment_id'       => $attachment_id,
            'main_item_id'        => absint($plan['main_item_id'] ?? 0),
            'old_attached_file'   => (string) ($plan['old_attached_file'] ?? ''),
            'new_attached_file'   => (string) ($plan['new_attached_file'] ?? ''),
            'old_mime'            => sanitize_mime_type((string) ($plan['old_mime'] ?? '')),
            'new_mime'            => sanitize_mime_type((string) ($plan['new_mime'] ?? '')),
            'old_metadata_json'   => is_string($old_json) ? $old_json : '{}',
            'final_metadata_json' => is_string($final_json) ? $final_json : '{}',
            'plan_hash'           => (string) ($plan['plan_hash'] ?? ''),
            'status'              => $status,
            'error_message'       => null,
            'updated_at'          => $now,
        );

        if (is_array($existing) && !empty($existing['id'])) {
            return false !== $wpdb->update($table, $row, array('id' => absint($existing['id'])), array('%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'), array('%d'));
        }

        $row['created_at'] = $now;
        return false !== $wpdb->insert($table, $row, array('%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));
    }

    private function materialize_media_replacement_attachment_metadata_plans($limit = 50, $deadline = 0.0)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $plans_table = $this->get_media_replacement_attachment_plans_table_name();
        $limit       = max(1, min(250, absint($limit)));
        if ('' === $items_table || '' === $plans_table || !($wpdb instanceof wpdb)) {
            return array('prepared' => 0, 'failed' => 0, 'remaining' => 0);
        }

        $attachment_ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT DISTINCT i.attachment_id FROM %i i LEFT JOIN %i p ON p.attachment_id = i.attachment_id WHERE i.status IN (%s, %s, %s, %s, %s, %s) AND p.id IS NULL ORDER BY i.attachment_id ASC LIMIT %d',
                $items_table,
                $plans_table,
                'metadata_ready',
                'metadata_updated',
                'metadata_failed',
                'refs_scanned',
                'cleanup_deleted',
                'cleanup_failed',
                $limit
            )
        );

        $prepared = 0;
        $failed   = 0;
        foreach ((array) $attachment_ids as $attachment_id) {
            if ($deadline > 0 && microtime(true) >= $deadline) {
                break;
            }
            $attachment_id = absint($attachment_id);
            $rows = $this->get_media_replacement_attachment_plan_registry_rows($attachment_id);
            $built = $this->build_media_replacement_attachment_metadata_plan_from_rows($attachment_id, $rows);
            $main_item_id = absint($built['plan']['main_item_id'] ?? $built['main_row']['id'] ?? 0);
            $main_plan_persisted = true;
            if (!empty($built['prepared']) && !empty($built['plan']) && $main_item_id > 0) {
                $main_plan = (array) ($built['main_plan'] ?? array());
                $main_plan['attachment_plan_version'] = 1;
                $main_plan['planned_metadata'] = $built['plan']['final_metadata'];
                $main_plan['attachment_plan_hash'] = $built['plan']['plan_hash'];
                $encoded_main_plan = wp_json_encode($main_plan);
                $main_plan_persisted = $this->update_media_replacement_item_metadata_apply_result($main_item_id, array(
                    'new_metadata_json' => is_string($encoded_main_plan) ? $encoded_main_plan : '{}',
                    'error_message'     => null,
                ));
            }

            if (empty($built['prepared']) || empty($built['plan']) || !$main_plan_persisted || !$this->persist_media_replacement_attachment_metadata_plan($built['plan'])) {
                $failed++;
                if ($main_item_id > 0) {
                    $this->update_media_replacement_item_metadata_apply_result($main_item_id, array(
                        'status'        => 'metadata_failed',
                        'error_message' => wp_strip_all_tags((string) ($built['message'] ?? __('Attachment-level metadata plan could not be persisted.', 'ultracache'))),
                    ));
                }
                continue;
            }

            if ($this->verify_media_replacement_attachment_metadata_switch(
                $attachment_id,
                (string) ($built['plan']['new_attached_file'] ?? ''),
                (string) ($built['plan']['new_mime'] ?? ''),
                (array) ($built['plan']['final_metadata'] ?? array())
            )) {
                $reconciled_rows = $this->get_media_replacement_attachment_plan_registry_rows($attachment_id);
                if ($this->persist_media_replacement_attachment_items_applied($reconciled_rows)) {
                    $plan_id = absint($wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE attachment_id = %d LIMIT 1', $plans_table, $attachment_id)));
                    if ($plan_id > 0) {
                        $this->update_media_replacement_attachment_plan_status($plan_id, 'applied', null);
                    }
                }
            }
            $prepared++;
        }

        $remaining = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT i.attachment_id) FROM %i i LEFT JOIN %i p ON p.attachment_id = i.attachment_id WHERE i.status IN (%s, %s, %s, %s, %s, %s) AND p.id IS NULL',
                $items_table,
                $plans_table,
                'metadata_ready',
                'metadata_updated',
                'metadata_failed',
                'refs_scanned',
                'cleanup_deleted',
                'cleanup_failed'
            )
        );

        return array('prepared' => $prepared, 'failed' => $failed, 'remaining' => max(0, $remaining));
    }

    private function get_media_replacement_attachment_metadata_apply_rows($limit = 50)
    {
        global $wpdb;

        $table  = $this->get_media_replacement_attachment_plans_table_name();
        $limit  = max(1, min(250, absint($limit)));
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE status = %s ORDER BY attachment_id ASC LIMIT %d',
                $table,
                'prepared',
                $limit
            ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }

    private function update_media_replacement_attachment_plan_status($plan_id, $status, $error_message = null)
    {
        global $wpdb;
        $table   = $this->get_media_replacement_attachment_plans_table_name();
        $plan_id = absint($plan_id);
        $status  = in_array((string) $status, array('prepared', 'applied', 'failed', 'restored'), true) ? (string) $status : 'failed';
        if ('' === $table || $plan_id <= 0 || !($wpdb instanceof wpdb)) {
            return false;
        }
        $row = array('status' => $status, 'error_message' => $error_message, 'updated_at' => current_time('mysql', true));
        if ('applied' === $status) {
            $row['applied_at'] = current_time('mysql', true);
        } elseif ('restored' === $status) {
            $row['restored_at'] = current_time('mysql', true);
        }
        return false !== $wpdb->update($table, $row, array('id' => $plan_id), null, array('%d'));
    }

    private function build_media_replacement_expected_current_metadata(array $plan, array $registry_rows)
    {
        $expected = isset($plan['old_metadata']) && is_array($plan['old_metadata']) ? $plan['old_metadata'] : array();
        foreach ($registry_rows as $row) {
            if (!$this->media_replacement_item_status_has_applied_metadata($row['status'] ?? '')) {
                continue;
            }
            $item_plan = $this->decode_media_replacement_json_array(isset($row['new_metadata_json']) ? (string) $row['new_metadata_json'] : '');
            $scope = sanitize_key((string) ($row['item_scope'] ?? 'main'));
            if ('intermediate' === $scope) {
                $size_name = substr(sanitize_key((string) ($row['size_name'] ?? '')), 0, 64);
                if ('' !== $size_name && !empty($item_plan['new_size_entry']) && is_array($item_plan['new_size_entry'])) {
                    if (!isset($expected['sizes']) || !is_array($expected['sizes'])) {
                        $expected['sizes'] = array();
                    }
                    $expected['sizes'][$size_name] = $item_plan['new_size_entry'];
                }
            } elseif ('main' === $scope && !empty($plan['final_metadata']) && is_array($plan['final_metadata'])) {
                foreach (array('file', 'width', 'height', 'filesize') as $main_key) {
                    if (array_key_exists($main_key, $plan['final_metadata'])) {
                        $expected[$main_key] = $plan['final_metadata'][$main_key];
                    } else {
                        unset($expected[$main_key]);
                    }
                }
            }
        }
        return $expected;
    }

    private function persist_media_replacement_attachment_items_applied(array $registry_rows)
    {
        $all_persisted = true;
        foreach ($registry_rows as $row) {
            $item_id = absint($row['id'] ?? 0);
            if ($item_id <= 0) {
                $all_persisted = false;
                continue;
            }
            $item_plan = $this->decode_media_replacement_json_array(isset($row['new_metadata_json']) ? (string) $row['new_metadata_json'] : '');
            if (empty($item_plan)) {
                $all_persisted = false;
                continue;
            }
            if (!$this->persist_media_replacement_metadata_applied_plan($item_id, $item_plan)) {
                $all_persisted = false;
            }
        }
        return $all_persisted;
    }

    private function apply_media_replacement_attachment_metadata_plan(array $plan_row)
    {
        $plan_id       = absint($plan_row['id'] ?? 0);
        $attachment_id = absint($plan_row['attachment_id'] ?? 0);
        $old_file      = ltrim(str_replace('\\', '/', (string) ($plan_row['old_attached_file'] ?? '')), '/');
        $new_file      = ltrim(str_replace('\\', '/', (string) ($plan_row['new_attached_file'] ?? '')), '/');
        $old_mime      = sanitize_mime_type((string) ($plan_row['old_mime'] ?? ''));
        $new_mime      = sanitize_mime_type((string) ($plan_row['new_mime'] ?? ''));
        $old_metadata  = $this->decode_media_replacement_json_array((string) ($plan_row['old_metadata_json'] ?? ''));
        $final_metadata= $this->decode_media_replacement_json_array((string) ($plan_row['final_metadata_json'] ?? ''));

        if ($plan_id <= 0 || $attachment_id <= 0 || '' === $old_file || '' === $new_file || '' === $new_mime || empty($old_metadata) || empty($final_metadata)) {
            return array('updated' => false, 'message' => __('Prepared attachment-level metadata plan is invalid.', 'ultracache'));
        }

        $registry_rows = $this->get_media_replacement_attachment_plan_registry_rows($attachment_id);
        if (empty($registry_rows)) {
            return array('updated' => false, 'message' => __('Prepared attachment-level metadata plan has no registry items.', 'ultracache'));
        }

        $current_file     = $this->get_media_replacement_current_attached_file_meta($attachment_id);
        $current_mime     = sanitize_mime_type((string) get_post_mime_type($attachment_id));
        $current_metadata = wp_get_attachment_metadata($attachment_id);
        $current_metadata = is_array($current_metadata) ? $current_metadata : array();

        $final_verified = $this->verify_media_replacement_attachment_metadata_switch($attachment_id, $new_file, $new_mime, $final_metadata);
        if ($final_verified) {
            if (!$this->persist_media_replacement_attachment_items_applied($registry_rows) || !$this->update_media_replacement_attachment_plan_status($plan_id, 'applied', null)) {
                return array('updated' => false, 'recoverable' => true, 'message' => __('Attachment metadata is already switched, but its attachment-level registry state could not be reconciled.', 'ultracache'));
            }
            return array('updated' => true, 'already_updated' => true, 'message' => '');
        }

        $expected_current_metadata = $this->build_media_replacement_expected_current_metadata(array('old_metadata' => $old_metadata, 'final_metadata' => $final_metadata), $registry_rows);
        $expected_current_file = $old_file;
        $expected_current_mime = $old_mime;
        foreach ($registry_rows as $row) {
            if ('main' === sanitize_key((string) ($row['item_scope'] ?? 'main')) && $this->media_replacement_item_status_has_applied_metadata($row['status'] ?? '')) {
                $expected_current_file = $new_file;
                $expected_current_mime = $new_mime;
                break;
            }
        }

        if ($current_file !== $expected_current_file || ('' !== $expected_current_mime && $current_mime !== $expected_current_mime) || !$this->media_replacement_metadata_values_match($current_metadata, $expected_current_metadata)) {
            return array('updated' => false, 'message' => __('Current attachment state no longer matches the prepared attachment-level transition and was not overwritten.', 'ultracache'));
        }

        $post_update = wp_update_post(array('ID' => $attachment_id, 'post_mime_type' => $new_mime), true);
        if (is_wp_error($post_update) || !$post_update) {
            return array('updated' => false, 'message' => __('Attachment MIME type could not be updated.', 'ultracache'));
        }
        update_attached_file($attachment_id, $new_file);
        wp_update_attachment_metadata($attachment_id, $final_metadata);
        clean_post_cache($attachment_id);

        if (!$this->verify_media_replacement_attachment_metadata_switch($attachment_id, $new_file, $new_mime, $final_metadata)) {
            $this->restore_media_replacement_attachment_metadata($attachment_id, $current_file, $current_mime, $current_metadata);
            return array('updated' => false, 'message' => __('Attachment-level metadata update did not verify and was restored.', 'ultracache'));
        }

        if (!$this->persist_media_replacement_attachment_items_applied($registry_rows) || !$this->update_media_replacement_attachment_plan_status($plan_id, 'applied', null)) {
            return array('updated' => false, 'recoverable' => true, 'message' => __('Attachment metadata was switched, but its attachment-level registry state could not be persisted. Resume Do to reconcile it.', 'ultracache'));
        }

        return array('updated' => true, 'message' => '');
    }


    private function get_media_replacement_metadata_apply_summary()
    {
        global $wpdb;

        $plans_table = $this->get_media_replacement_attachment_plans_table_name();
        $items_table = $this->get_media_replacement_items_table_name();
        if ('' === $plans_table || '' === $items_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $summary = array(
            'total'              => 0,
            'metadataReady'      => 0,
            'metadataUpdated'    => 0,
            'metadataFailed'     => 0,
            'failed'             => 0,
            'remainingToApply'   => 0,
            'metadataApplyItems' => 0,
            'metadataApplyTotal' => 0,
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT status, COUNT(*) AS item_count FROM %i GROUP BY status', $plans_table),
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            $status = sanitize_key((string) ($row['status'] ?? ''));
            $count  = max(0, (int) ($row['item_count'] ?? 0));
            $summary['total'] += $count;
            if ('prepared' === $status) {
                $summary['metadataReady'] += $count;
            } elseif ('applied' === $status) {
                $summary['metadataUpdated'] += $count;
            } elseif ('failed' === $status) {
                $summary['metadataFailed'] += $count;
            }
        }

        // Count unmaterialized attachments from their persisted physical registry state so the denominator stays stable.
        $legacy = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT i.attachment_id, SUM(i.status = %s) AS ready_rows, SUM(i.status = %s) AS failed_rows, SUM(i.status IN (%s, %s, %s, %s)) AS applied_rows, COUNT(*) AS total_rows FROM %i i LEFT JOIN %i p ON p.attachment_id = i.attachment_id WHERE p.id IS NULL AND i.status IN (%s, %s, %s, %s, %s, %s, %s, %s) GROUP BY i.attachment_id',
                'metadata_ready',
                'metadata_failed',
                'metadata_updated',
                'refs_scanned',
                'cleanup_deleted',
                'cleanup_failed',
                $items_table,
                $plans_table,
                'metadata_ready',
                'metadata_updated',
                'metadata_failed',
                'refs_scanned',
                'cleanup_deleted',
                'cleanup_failed',
                'metadata_restored',
                'metadata_rollback_failed'
            ),
            ARRAY_A
        );
        foreach ((array) $legacy as $row) {
            $summary['total']++;
            if ((int) ($row['failed_rows'] ?? 0) > 0) {
                $summary['metadataFailed']++;
            } elseif ((int) ($row['ready_rows'] ?? 0) > 0) {
                $summary['metadataReady']++;
            } elseif ((int) ($row['applied_rows'] ?? 0) === (int) ($row['total_rows'] ?? 0)) {
                $summary['metadataUpdated']++;
            } else {
                $summary['metadataReady']++;
            }
        }

        $summary['remainingToApply']   = max(0, (int) $summary['metadataReady']);
        $summary['metadataApplyItems'] = max(0, (int) $summary['metadataUpdated']);
        $summary['metadataApplyTotal'] = max(0, (int) $summary['metadataReady'] + (int) $summary['metadataUpdated'] + (int) $summary['metadataFailed']);
        return $summary;
    }

    private function retry_media_replacement_failed_metadata_updates()
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $plans_table = $this->get_media_replacement_attachment_plans_table_name();
        if ('' === $items_table || '' === $plans_table || !($wpdb instanceof wpdb)) {
            return 0;
        }

        $updated_items = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET status = %s, error_message = NULL, updated_at = %s WHERE status = %s',
                $items_table,
                'metadata_ready',
                current_time('mysql', true),
                'metadata_failed'
            )
        );
        $updated_plans = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET status = %s, error_message = NULL, updated_at = %s WHERE status = %s',
                $plans_table,
                'prepared',
                current_time('mysql', true),
                'failed'
            )
        );

        return max(0, false === $updated_items ? 0 : (int) $updated_items) + max(0, false === $updated_plans ? 0 : (int) $updated_plans);
    }

    private function update_media_replacement_item_metadata_apply_result($item_id, array $data)
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

        foreach (array('new_metadata_json', 'status', 'error_message') as $key) {
            if (array_key_exists($key, $data)) {
                $row[$key] = $data[$key];
                $formats[] = '%s';
            }
        }

        if (isset($row['status'])) {
            $row['status'] = in_array((string) $row['status'], array('metadata_ready', 'metadata_updated', 'refs_scanned', 'metadata_restored', 'metadata_failed', 'metadata_rollback_failed', 'failed'), true) ? (string) $row['status'] : 'metadata_failed';
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

    private function get_media_replacement_current_attached_file_meta($attachment_id)
    {
        $value = get_post_meta(absint($attachment_id), '_wp_attached_file', true);
        return is_string($value) ? ltrim(str_replace('\\', '/', $value), '/') : '';
    }

    private function verify_media_replacement_attachment_metadata_switch($attachment_id, $new_attached_file, $new_mime, array $planned_metadata)
    {
        $attachment_id     = absint($attachment_id);
        $new_attached_file = ltrim(str_replace('\\', '/', (string) $new_attached_file), '/');
        $new_mime          = sanitize_mime_type((string) $new_mime);

        if ($attachment_id <= 0 || '' === $new_attached_file || '' === $new_mime) {
            return false;
        }

        $stored_file = $this->get_media_replacement_current_attached_file_meta($attachment_id);
        if ($stored_file !== $new_attached_file) {
            return false;
        }

        if ((string) get_post_mime_type($attachment_id) !== $new_mime) {
            return false;
        }

        $stored_metadata = wp_get_attachment_metadata($attachment_id);
        if (!is_array($stored_metadata)) {
            return false;
        }

        $stored_metadata_file = isset($stored_metadata['file']) ? ltrim(str_replace('\\', '/', (string) $stored_metadata['file']), '/') : '';
        $planned_file         = isset($planned_metadata['file']) ? ltrim(str_replace('\\', '/', (string) $planned_metadata['file']), '/') : '';

        return '' !== $stored_metadata_file
            && $stored_metadata_file === $planned_file
            && $planned_file === $new_attached_file
            && $this->media_replacement_metadata_values_match($stored_metadata, $planned_metadata);
    }

    private function restore_media_replacement_attachment_metadata($attachment_id, $attached_file, $mime_type, array $metadata)
    {
        $attachment_id = absint($attachment_id);
        if ($attachment_id <= 0) {
            return;
        }

        $attached_file = ltrim(str_replace('\\', '/', (string) $attached_file), '/');
        $mime_type     = sanitize_mime_type((string) $mime_type);

        if ('' !== $mime_type) {
            wp_update_post(array(
                'ID'             => $attachment_id,
                'post_mime_type' => $mime_type,
            ));
        }

        if ('' !== $attached_file) {
            update_attached_file($attachment_id, $attached_file);
        }

        wp_update_attachment_metadata($attachment_id, $metadata);
        clean_post_cache($attachment_id);
    }


    private function verify_media_replacement_intermediate_metadata_switch($attachment_id, $size_name, $new_relative, $new_mime)
    {
        $attachment_id = absint($attachment_id);
        $size_name     = substr(sanitize_key((string) $size_name), 0, 64);
        $new_filename  = basename(ltrim(str_replace('\\', '/', (string) $new_relative), '/'));
        $new_mime      = sanitize_mime_type((string) $new_mime);

        if ($attachment_id <= 0 || '' === $size_name || '' === $new_filename || '' === $new_mime) {
            return false;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (empty($metadata['sizes']) || !is_array($metadata['sizes']) || empty($metadata['sizes'][$size_name]) || !is_array($metadata['sizes'][$size_name])) {
            return false;
        }

        $entry = $metadata['sizes'][$size_name];
        $file  = isset($entry['file']) ? (string) $entry['file'] : '';
        $mime  = isset($entry['mime-type']) ? sanitize_mime_type((string) $entry['mime-type']) : '';

        return $file === $new_filename && ('' === $mime || $mime === $new_mime);
    }


    private function media_replacement_metadata_values_match(array $current, array $expected)
    {
        $current_json = function_exists('wp_json_encode') ? wp_json_encode($current) : false;
        $expected_json = function_exists('wp_json_encode') ? wp_json_encode($expected) : false;
        return is_string($current_json) && is_string($expected_json) && hash_equals($expected_json, $current_json);
    }


    private function persist_media_replacement_metadata_applied_plan($item_id, array $plan)
    {
        $item_id = absint($item_id);
        if ($item_id <= 0) {
            return false;
        }

        $plan['metadata_update_pending']    = false;
        $plan['metadata_updated']           = true;
        $plan['database_replacement_ready'] = true;
        $plan['metadata_updated_at']        = current_time('mysql', true);

        $metadata_json = function_exists('wp_json_encode') ? wp_json_encode($plan) : '{}';
        return $this->update_media_replacement_item_metadata_apply_result($item_id, array(
            'new_metadata_json' => is_string($metadata_json) ? $metadata_json : '{}',
            'status'            => 'metadata_updated',
            'error_message'     => null,
        ));
    }


    public function apply_media_library_replacement_metadata_updates($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array('success' => false, 'message' => __('Media Library replacement registry tables are not available.', 'ultracache'));
        }

        if (!$this->media_replacement_has_registry_rows()) {
            return $this->build_media_replacement_empty_registry_response(__('No Media Library replacement registry rows are available for metadata switching. Restore the database backup or roll back attachment metadata, then run Restart Replacement Plan again.', 'ultracache'));
        }

        $args = is_array($args) ? $args : array();
        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        $limit = max(1, min(250, $limit));
        $time_budget = isset($args['time_budget']) && (float) $args['time_budget'] > 0 ? (float) $args['time_budget'] : 15.0;
        $time_budget = max(1.0, min(30.0, $time_budget));
        $deadline = microtime(true) + $time_budget;

        $materialized = $this->materialize_media_replacement_attachment_metadata_plans(max($limit, 100), $deadline);
        if (!empty($materialized['failed'])) {
            return array(
                'success' => false,
                'message' => sprintf(
                    /* translators: %d: failed attachment-level plan count. */
                    __('Attachment-level metadata planning failed for %d attachments.', 'ultracache'),
                    (int) $materialized['failed']
                ),
                'status' => 'metadata_plan_failed',
                'hasMore' => false,
                'batchFailed' => (int) $materialized['failed'],
            );
        }

        $rows = $this->get_media_replacement_attachment_metadata_apply_rows($limit);
        $updated = 0;
        $failed = 0;
        $recoverable = 0;

        foreach ($rows as $row) {
            if (($updated + $failed + $recoverable) > 0 && microtime(true) >= $deadline) {
                break;
            }
            $result = $this->apply_media_replacement_attachment_metadata_plan($row);
            if (!empty($result['updated'])) {
                $updated++;
            } elseif (!empty($result['recoverable'])) {
                $recoverable++;
            } else {
                $failed++;
                $message = wp_strip_all_tags((string) ($result['message'] ?? __('Attachment metadata switch failed.', 'ultracache')));
                $this->update_media_replacement_attachment_plan_status(absint($row['id'] ?? 0), 'failed', $message);
                $main_item_id = absint($row['main_item_id'] ?? 0);
                if ($main_item_id > 0) {
                    $this->update_media_replacement_item_metadata_apply_result($main_item_id, array('status' => 'metadata_failed', 'error_message' => $message));
                }
            }
        }

        $summary  = $this->get_media_replacement_metadata_apply_summary();
        $has_more = !empty($summary['remainingToApply']) || !empty($materialized['remaining']);
        $total    = max(0, (int) $summary['metadataApplyTotal']);
        $progress = $total > 0 ? min(100, round(((int) $summary['metadataApplyItems'] / $total) * 100, 1)) : 100;

        if ($recoverable > 0) {
            return array(
                'success'          => false,
                'blocked'          => true,
                'retryRequired'    => true,
                'message'          => __('Attachment metadata was changed, but its attachment-level registry state could not be persisted. Resume Do to reconcile it before continuing.', 'ultracache'),
                'status'           => 'metadata_reconcile_required',
                'hasMore'          => true,
                'batchUpdated'     => $updated,
                'batchFailed'      => $failed,
                'batchRecoverable' => $recoverable,
            );
        }

        return array(
            'success'             => true,
            'message'             => $has_more
                ? sprintf(
                    /* translators: 1: updated attachment count, 2: prepared attachment count. */
                    __('Media Library replacement metadata switch is in progress: %1$d of %2$d attachments updated.', 'ultracache'),
                    (int) $summary['metadataUpdated'],
                    $total
                )
                : sprintf(
                    /* translators: %d: updated attachment count. */
                    __('Media Library replacement switched attachment metadata for %d attachments.', 'ultracache'),
                    (int) $summary['metadataUpdated']
                ),
            'status'              => $has_more ? 'metadata_applying' : 'metadata_updated',
            'hasMore'             => $has_more,
            'batchSize'           => $limit,
            'batchUpdated'        => $updated,
            'batchFailed'         => $failed,
            'metadataPrepared'    => (int) $summary['metadataReady'],
            'metadataUpdated'     => (int) $summary['metadataUpdated'],
            'remainingMetadata'   => (int) $summary['remainingToApply'],
            'metadataFailed'      => (int) $summary['metadataFailed'],
            'progressPercent'     => $progress,
            'filesCopiedOnly'     => false,
            'metadataPlanReady'   => false,
            'metadataUpdatedDone' => !$has_more,
            'databaseReplaced'    => false,
            'nextStep'            => $has_more
                ? __('Continue switching attachment metadata in chunks. Site content references have not been replaced.', 'ultracache')
                : __('Next step: scan database references for the old image URLs and paths. Site content references have not been replaced yet.', 'ultracache'),
        );
    }


    private function get_media_replacement_metadata_rollback_rows($limit = 50)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $limit       = max(1, min(250, absint($limit)));

        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, attachment_id, item_scope, size_name, target_format, old_relative_path, new_relative_path, old_file_path, new_file_path, old_mime, new_mime, old_metadata_json, new_metadata_json, destination_existed, destination_overwritten, destination_previous_size, destination_previous_hash, destination_backup_path, destination_backup_size, destination_backup_hash, destination_published_size, destination_published_hash, status FROM %i WHERE status IN (%s, %s, %s) ORDER BY id ASC LIMIT %d',
                $items_table,
                'metadata_updated',
                'refs_scanned',
                'metadata_rollback_failed',
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private function get_media_replacement_metadata_rollback_summary()
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT status, COUNT(*) AS item_count FROM %i GROUP BY status',
                $items_table
            ),
            ARRAY_A
        );

        $summary = array(
            'total'                   => 0,
            'metadataUpdated'         => 0,
            'refsScanned'             => 0,
            'metadataRestored'        => 0,
            'metadataRollbackFailed'  => 0,
            'metadataFailed'          => 0,
            'pendingMetadataRollback' => 0,
            'metadataRollbackTotal'   => 0,
        );

        foreach ((array) $rows as $row) {
            $status = isset($row['status']) ? sanitize_key((string) $row['status']) : '';
            $count  = isset($row['item_count']) ? max(0, (int) $row['item_count']) : 0;
            $summary['total'] += $count;

            if ('metadata_updated' === $status) {
                $summary['metadataUpdated'] += $count;
            } elseif ('refs_scanned' === $status) {
                $summary['refsScanned'] += $count;
            } elseif ('metadata_restored' === $status) {
                $summary['metadataRestored'] += $count;
            } elseif ('metadata_rollback_failed' === $status) {
                $summary['metadataRollbackFailed'] += $count;
            } elseif ('metadata_failed' === $status) {
                $summary['metadataFailed'] += $count;
            }
        }

        $summary['pendingMetadataRollback'] = max(0, (int) $summary['metadataUpdated'] + (int) $summary['refsScanned'] + (int) $summary['metadataRollbackFailed']);
        $summary['metadataRollbackTotal'] = max(0, (int) $summary['pendingMetadataRollback'] + (int) $summary['metadataRestored']);
        return $summary;
    }

    private function verify_media_replacement_attachment_metadata_restore($attachment_id, $old_attached_file, $old_mime, array $old_metadata)
    {
        $attachment_id     = absint($attachment_id);
        $old_attached_file = ltrim(str_replace('\\', '/', (string) $old_attached_file), '/');
        $old_mime          = sanitize_mime_type((string) $old_mime);

        if ($attachment_id <= 0 || '' === $old_attached_file || '' === $old_mime) {
            return false;
        }

        $stored_file = $this->get_media_replacement_current_attached_file_meta($attachment_id);
        if ($stored_file !== $old_attached_file) {
            return false;
        }

        if ((string) get_post_mime_type($attachment_id) !== $old_mime) {
            return false;
        }

        $stored_metadata = wp_get_attachment_metadata($attachment_id);
        if (!is_array($stored_metadata)) {
            return false;
        }

        $stored_metadata_file = isset($stored_metadata['file']) ? ltrim(str_replace('\\', '/', (string) $stored_metadata['file']), '/') : '';
        $old_metadata_file    = isset($old_metadata['file']) ? ltrim(str_replace('\\', '/', (string) $old_metadata['file']), '/') : $old_attached_file;

        return '' !== $stored_metadata_file && $stored_metadata_file === $old_metadata_file && $stored_metadata_file === $old_attached_file;
    }


    private function verify_media_replacement_intermediate_metadata_restore($attachment_id, $size_name, array $old_size_entry)
    {
        $attachment_id = absint($attachment_id);
        $size_name     = substr(sanitize_key((string) $size_name), 0, 64);
        $old_file      = isset($old_size_entry['file']) ? (string) $old_size_entry['file'] : '';
        $old_mime      = isset($old_size_entry['mime-type']) ? sanitize_mime_type((string) $old_size_entry['mime-type']) : '';

        if ($attachment_id <= 0 || '' === $size_name || '' === $old_file) {
            return false;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (empty($metadata['sizes']) || !is_array($metadata['sizes']) || empty($metadata['sizes'][$size_name]) || !is_array($metadata['sizes'][$size_name])) {
            return false;
        }

        $entry = $metadata['sizes'][$size_name];
        $file = isset($entry['file']) ? (string) $entry['file'] : '';
        $mime = isset($entry['mime-type']) ? sanitize_mime_type((string) $entry['mime-type']) : '';

        return $file === $old_file && ('' === $old_mime || '' === $mime || $mime === $old_mime);
    }

    private function rollback_media_replacement_intermediate_metadata(array $row)
    {
        $item_id       = isset($row['id']) ? absint($row['id']) : 0;
        $attachment_id = isset($row['attachment_id']) ? absint($row['attachment_id']) : 0;
        $size_name     = isset($row['size_name']) ? substr(sanitize_key((string) $row['size_name']), 0, 64) : '';

        if ($item_id <= 0 || $attachment_id <= 0 || '' === $size_name) {
            return array('restored' => false, 'message' => __('Invalid Media Library replacement intermediate metadata rollback row.', 'ultracache'));
        }

        $plan = $this->decode_media_replacement_json_array(isset($row['new_metadata_json']) ? (string) $row['new_metadata_json'] : '');
        if (empty($plan['metadata_updated']) || 'intermediate' !== (string) ($plan['item_scope'] ?? '') || empty($plan['old_size_entry']) || !is_array($plan['old_size_entry'])) {
            return array('restored' => false, 'message' => __('Intermediate replacement metadata plan does not contain the original size entry needed for rollback.', 'ultracache'));
        }

        $plan_size_name = isset($plan['size_name']) ? substr(sanitize_key((string) $plan['size_name']), 0, 64) : '';
        if ($plan_size_name !== $size_name) {
            return array('restored' => false, 'message' => __('Intermediate replacement metadata rollback plan does not match the registry size.', 'ultracache'));
        }

        $current_metadata = wp_get_attachment_metadata($attachment_id);
        $current_metadata = is_array($current_metadata) ? $current_metadata : array();
        if (empty($current_metadata['sizes']) || !is_array($current_metadata['sizes']) || empty($current_metadata['sizes'][$size_name]) || !is_array($current_metadata['sizes'][$size_name])) {
            return array('restored' => false, 'message' => __('Current attachment metadata does not contain this intermediate size.', 'ultracache'));
        }

        $current_entry = $current_metadata['sizes'][$size_name];
        $current_metadata['sizes'][$size_name] = $plan['old_size_entry'];
        wp_update_attachment_metadata($attachment_id, $current_metadata);
        clean_post_cache($attachment_id);

        if (!$this->verify_media_replacement_intermediate_metadata_restore($attachment_id, $size_name, $plan['old_size_entry'])) {
            $current_metadata['sizes'][$size_name] = $current_entry;
            wp_update_attachment_metadata($attachment_id, $current_metadata);
            clean_post_cache($attachment_id);
            return array('restored' => false, 'message' => __('Intermediate attachment metadata rollback did not verify and the current replacement state was restored.', 'ultracache'));
        }

        $backup_restore = $this->restore_media_replacement_destination_backup($row);
        if (empty($backup_restore['restored'])) {
            return array('restored' => false, 'message' => (string) ($backup_restore['message'] ?? __('The overwritten destination backup could not be restored.', 'ultracache')));
        }

        $plan['metadata_update_pending']    = false;
        $plan['metadata_updated']           = false;
        $plan['metadata_restored']          = true;
        $plan['metadata_restored_at']       = current_time('mysql', true);
        $plan['database_replacement_ready'] = false;
        $plan['restored_size_entry']        = $plan['old_size_entry'];

        $metadata_json = function_exists('wp_json_encode') ? wp_json_encode($plan) : '{}';
        $updated = $this->update_media_replacement_item_metadata_apply_result($item_id, array(
            'new_metadata_json' => is_string($metadata_json) ? $metadata_json : '{}',
            'status'            => 'metadata_restored',
            'error_message'     => null,
        ));

        if (!$updated) {
            return array('restored' => false, 'message' => __('Intermediate attachment metadata was restored, but the replacement registry could not be updated.', 'ultracache'));
        }

        return array('restored' => true, 'message' => '');
    }


    private function rollback_media_replacement_item_metadata(array $row)
    {
        if ($this->is_media_replacement_intermediate_item_row($row)) {
            return $this->rollback_media_replacement_intermediate_metadata($row);
        }

        $item_id       = isset($row['id']) ? absint($row['id']) : 0;
        $attachment_id = isset($row['attachment_id']) ? absint($row['attachment_id']) : 0;
        $target_format = isset($row['target_format']) ? sanitize_key((string) $row['target_format']) : '';
        $new_file      = isset($row['new_file_path']) ? wp_normalize_path((string) $row['new_file_path']) : '';
        $new_relative  = isset($row['new_relative_path']) ? ltrim(str_replace('\\', '/', (string) $row['new_relative_path']), '/') : '';
        $new_mime      = isset($row['new_mime']) ? sanitize_mime_type((string) $row['new_mime']) : '';

        if ($item_id <= 0 || $attachment_id <= 0 || !in_array($target_format, array('avif', 'webp'), true)) {
            return array('restored' => false, 'message' => __('Invalid Media Library replacement metadata rollback row.', 'ultracache'));
        }

        $plan = $this->decode_media_replacement_json_array(isset($row['new_metadata_json']) ? (string) $row['new_metadata_json'] : '');
        if (empty($plan['metadata_updated']) || empty($plan['old_metadata']) || !is_array($plan['old_metadata'])) {
            return array('restored' => false, 'message' => __('Replacement metadata plan does not contain the original attachment metadata needed for rollback.', 'ultracache'));
        }

        $old_attached_file = isset($plan['old_attached_file']) ? ltrim(str_replace('\\', '/', (string) $plan['old_attached_file']), '/') : '';
        $new_attached_file = isset($plan['new_attached_file']) ? ltrim(str_replace('\\', '/', (string) $plan['new_attached_file']), '/') : $new_relative;
        $old_mime          = isset($plan['old_post_mime_type']) ? sanitize_mime_type((string) $plan['old_post_mime_type']) : (isset($row['old_mime']) ? sanitize_mime_type((string) $row['old_mime']) : '');
        $planned_mime      = isset($plan['new_post_mime_type']) ? sanitize_mime_type((string) $plan['new_post_mime_type']) : $new_mime;
        $old_metadata      = isset($plan['old_metadata']) && is_array($plan['old_metadata']) ? $plan['old_metadata'] : array();

        if ('' === $old_attached_file || '' === $old_mime || '' === $new_attached_file || '' === $planned_mime || $new_attached_file !== $new_relative || $planned_mime !== $new_mime) {
            return array('restored' => false, 'message' => __('Replacement metadata rollback plan does not match the copied replacement file.', 'ultracache'));
        }

        if ('' !== $new_file && (!$this->optimized_storage_path_exists($new_file, true) || !$this->is_valid_generated_media_file($new_file, $target_format, 'media_replacement_metadata_rollback_destination_validate'))) {
            return array('restored' => false, 'message' => __('Copied replacement file is missing or invalid; rollback is paused so the workflow remains inspectable.', 'ultracache'));
        }

        $current_attached_file = $this->get_media_replacement_current_attached_file_meta($attachment_id);
        $current_mime          = sanitize_mime_type((string) get_post_mime_type($attachment_id));
        $current_metadata      = wp_get_attachment_metadata($attachment_id);
        $current_metadata      = is_array($current_metadata) ? $current_metadata : array();

        if ($current_attached_file === $old_attached_file && $current_mime === $old_mime) {
            if (!$this->verify_media_replacement_attachment_metadata_restore($attachment_id, $old_attached_file, $old_mime, $old_metadata)) {
                $this->restore_media_replacement_attachment_metadata($attachment_id, $old_attached_file, $old_mime, $old_metadata);
            }
        } else {
            if ($current_attached_file !== $new_attached_file) {
                return array('restored' => false, 'message' => __('Current attachment file no longer matches the replacement metadata state, so rollback was not applied.', 'ultracache'));
            }

            $this->restore_media_replacement_attachment_metadata($attachment_id, $old_attached_file, $old_mime, $old_metadata);
        }

        if (!$this->verify_media_replacement_attachment_metadata_restore($attachment_id, $old_attached_file, $old_mime, $old_metadata)) {
            $this->restore_media_replacement_attachment_metadata($attachment_id, $current_attached_file, $current_mime, $current_metadata);
            return array('restored' => false, 'message' => __('Attachment metadata rollback did not verify and the current replacement state was restored.', 'ultracache'));
        }

        $backup_restore = $this->restore_media_replacement_destination_backup($row);
        if (empty($backup_restore['restored'])) {
            return array('restored' => false, 'message' => (string) ($backup_restore['message'] ?? __('The overwritten destination backup could not be restored.', 'ultracache')));
        }

        $plan['metadata_update_pending']   = false;
        $plan['metadata_updated']          = false;
        $plan['metadata_restored']         = true;
        $plan['metadata_restored_at']      = current_time('mysql', true);
        $plan['restored_attached_file']    = $old_attached_file;
        $plan['restored_post_mime_type']   = $old_mime;
        $plan['database_replacement_ready']= false;

        $metadata_json = function_exists('wp_json_encode') ? wp_json_encode($plan) : '{}';
        $updated = $this->update_media_replacement_item_metadata_apply_result($item_id, array(
            'new_metadata_json' => is_string($metadata_json) ? $metadata_json : '{}',
            'status'            => 'metadata_restored',
            'error_message'     => null,
        ));

        if (!$updated) {
            return array('restored' => false, 'message' => __('Attachment metadata was restored, but the replacement registry could not be updated.', 'ultracache'));
        }

        return array('restored' => true, 'message' => '');
    }

    public function rollback_media_library_replacement_metadata_updates($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array(
                'success' => false,
                'message' => __('Media Library replacement registry tables are not available.', 'ultracache'),
            );
        }

        if (!$this->media_replacement_has_registry_rows()) {
            return $this->build_media_replacement_empty_registry_response(__('No Media Library replacement registry rows are available for metadata rollback. Restore the database backup, then run Restart Replacement Plan again.', 'ultracache'));
        }

        $database_summary = $this->get_media_replacement_database_preview_summary();
        if (!empty($database_summary)) {
            $total_refs    = isset($database_summary['totalRefs']) ? max(0, (int) $database_summary['totalRefs']) : 0;
            $restored_refs = isset($database_summary['restoredRefs']) ? max(0, (int) $database_summary['restoredRefs']) : 0;
            if ($total_refs > 0 && $restored_refs < $total_refs) {
                return array(
                    'success' => false,
                    'message' => __('Rollback DB Replacements first. Attachment metadata rollback is blocked while database references still point to replacement files.', 'ultracache'),
                    'status'  => 'metadata_rollback_blocked',
                );
            }
        }

        $args = is_array($args) ? $args : array();
        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        $limit = max(1, min(250, $limit));
        $rows  = $this->get_media_replacement_metadata_rollback_rows($limit);

        $restored = 0;
        $failed   = 0;

        foreach ($rows as $row) {
            $item_id = isset($row['id']) ? absint($row['id']) : 0;
            $result  = $this->rollback_media_replacement_item_metadata($row);
            if (!empty($result['restored'])) {
                $restored++;
            } else {
                $failed++;
                $this->update_media_replacement_item_metadata_apply_result($item_id, array(
                    'status'        => 'metadata_rollback_failed',
                    'error_message' => isset($result['message']) ? wp_strip_all_tags((string) $result['message']) : __('Attachment metadata rollback failed.', 'ultracache'),
                ));
            }
        }

        $summary  = $this->get_media_replacement_metadata_rollback_summary();
        $has_more = !empty($summary['pendingMetadataRollback']);
        $total    = max(0, (int) $summary['metadataRollbackTotal']);
        $progress = $total > 0 ? min(100, round(((int) $summary['metadataRestored'] / $total) * 100, 1)) : 100;

        return array(
            'success'                    => true,
            'message'                    => $has_more
                ? sprintf(
                    /* translators: 1: restored metadata count, 2: total rollback metadata count. */
                    __('Media Library replacement attachment metadata rollback is in progress: %1$d of %2$d attachments restored.', 'ultracache'),
                    (int) $summary['metadataRestored'],
                    $total
                )
                : sprintf(
                    /* translators: 1: restored metadata count, 2: failed metadata rollback count. */
                    __('Media Library replacement restored attachment metadata for %1$d files. Failed: %2$d.', 'ultracache'),
                    (int) $summary['metadataRestored'],
                    (int) $summary['metadataRollbackFailed']
                ),
            'status'                     => $has_more ? 'metadata_rollback_running' : 'metadata_rollback_complete',
            'hasMore'                    => $has_more,
            'batchSize'                  => $limit,
            'batchRestored'              => $restored,
            'batchFailed'                => $failed,
            'metadataRestored'           => (int) $summary['metadataRestored'],
            'pendingMetadataRollback'    => (int) $summary['pendingMetadataRollback'],
            'metadataRollbackFailed'     => (int) $summary['metadataRollbackFailed'],
            'metadataRollbackTotal'      => $total,
            'progressPercent'            => $progress,
            'metadataRolledBack'         => !$has_more && (int) $summary['metadataRestored'] === $total && 0 === (int) $summary['metadataRollbackFailed'],
            'nextStep'                   => $has_more
                ? __('Continue rolling back attachment metadata in chunks. Copied replacement files are not deleted.', 'ultracache')
                : __('Attachment metadata rollback is complete. Copied replacement files still remain until cleanup tools are added.', 'ultracache'),
        );
    }




}
