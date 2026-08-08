<?php
/**
 * Resumable, bounded-memory Theme CSS scanning, mutation, and verification.
 *
 * Native PHP streams are used only after UltraCache path guards succeed because
 * WP_Filesystem does not expose offset-based bounded reads or writes.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Replacement_Theme_CSS_Stream_Trait
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private replacement registry tables use validated identifiers.

    private function get_media_replacement_theme_css_stream_chunk_size()
    {
        return 262144;
    }

    private function get_media_replacement_theme_css_stream_overlap_size()
    {
        return 4096;
    }

    private function get_media_replacement_theme_css_checksum_scheme()
    {
        return 'chain_md5_v1';
    }

    private function get_media_replacement_theme_css_checksum_seed()
    {
        return str_repeat('0', 32);
    }

    private function advance_media_replacement_theme_css_checksum($checksum, $chunk)
    {
        $checksum = preg_match('/^[a-f0-9]{32}$/', (string) $checksum) ? (string) $checksum : $this->get_media_replacement_theme_css_checksum_seed();
        return md5($checksum . pack('N', strlen((string) $chunk)) . (string) $chunk);
    }

    private function normalize_media_replacement_theme_css_stream_state($state)
    {
        $state = is_array($state) ? $state : array();
        $mode = sanitize_key((string) ($state['mode'] ?? ''));
        if (!in_array($mode, array('scan', 'validate', 'apply', 'verify'), true)) {
            $mode = '';
        }
        $phase = sanitize_key((string) ($state['phase'] ?? ''));
        if (!in_array($phase, array('read', 'backup_committed', 'temp_verify', 'target_verify', 'committed', 'complete'), true)) {
            $phase = 'read';
        }

        $state_version = max(0, (int) ($state['state_version'] ?? 0));

        return array(
            'state_version'     => 2,
            'mode'              => $mode,
            'phase'             => $phase,
            'file_id'           => max(0, absint($state['file_id'] ?? 0)),
            'file_path'         => wp_normalize_path((string) ($state['file_path'] ?? '')),
            'relative_path'     => ltrim(str_replace('\\', '/', (string) ($state['relative_path'] ?? '')), '/'),
            'source_size'       => max(0, (int) ($state['source_size'] ?? 0)),
            'source_mtime'      => max(0, (int) ($state['source_mtime'] ?? 0)),
            'source_offset'     => max(0, (int) ($state['source_offset'] ?? 0)),
            'output_offset'     => max(0, (int) ($state['output_offset'] ?? 0)),
            'carry_b64'         => (string) ($state['carry_b64'] ?? ''),
            'checksum_before'   => preg_match('/^[a-f0-9]{32}$/', (string) ($state['checksum_before'] ?? '')) ? (string) $state['checksum_before'] : $this->get_media_replacement_theme_css_checksum_seed(),
            'checksum_after'    => preg_match('/^[a-f0-9]{32}$/', (string) ($state['checksum_after'] ?? '')) ? (string) $state['checksum_after'] : $this->get_media_replacement_theme_css_checksum_seed(),
            'checksum_after_buffer_b64' => (string) ($state['checksum_after_buffer_b64'] ?? ''),
            'temp_path'         => wp_normalize_path((string) ($state['temp_path'] ?? '')),
            'backup_temp_path'  => wp_normalize_path((string) ($state['backup_temp_path'] ?? '')),
            'backup_path'       => wp_normalize_path((string) ($state['backup_path'] ?? '')),
            'commit_verify_path' => wp_normalize_path((string) ($state['commit_verify_path'] ?? '')),
            'commit_verify_offset' => max(0, (int) ($state['commit_verify_offset'] ?? 0)),
            'commit_verify_checksum' => preg_match('/^[a-f0-9]{32}$/', (string) ($state['commit_verify_checksum'] ?? '')) ? (string) $state['commit_verify_checksum'] : $this->get_media_replacement_theme_css_checksum_seed(),
            'commit_verify_size' => max(0, (int) ($state['commit_verify_size'] ?? 0)),
            'commit_verify_mtime' => max(0, (int) ($state['commit_verify_mtime'] ?? 0)),
            'commit_verified'   => $state_version >= 2 && !empty($state['commit_verified']),
            'inserted_refs'     => max(0, (int) ($state['inserted_refs'] ?? 0)),
            'created_at'        => sanitize_text_field((string) ($state['created_at'] ?? '')),
            'updated_at'        => sanitize_text_field((string) ($state['updated_at'] ?? '')),
        );
    }

    private function get_media_replacement_theme_css_stream_state()
    {
        return $this->normalize_media_replacement_theme_css_stream_state($this->get_media_replacement_workflow_section('theme_css_stream'));
    }

    private function update_media_replacement_theme_css_stream_state(array $state)
    {
        $state['state_version'] = 2;
        $state['updated_at'] = current_time('mysql', true);
        $state = $this->normalize_media_replacement_theme_css_stream_state($state);
        $this->update_media_replacement_workflow_section('theme_css_stream', $state);
        return $state;
    }

    private function clear_media_replacement_theme_css_stream_state($delete_temporary_files = true)
    {
        $state = $this->get_media_replacement_theme_css_stream_state();
        if ($delete_temporary_files && function_exists('ultracache_safe_unlink')) {
            foreach (array('temp_path', 'backup_temp_path') as $field) {
                if (!empty($state[$field])) {
                    ultracache_safe_unlink($state[$field], 'media_library_replacement_theme_css_stream_cleanup');
                }
            }
        }
        $this->clear_media_replacement_workflow_section('theme_css_stream');
    }

    private function decode_media_replacement_theme_css_stream_carry(array $state)
    {
        if ('' === (string) ($state['carry_b64'] ?? '')) {
            return '';
        }
        $decoded = base64_decode((string) $state['carry_b64'], true);
        return is_string($decoded) ? $decoded : '';
    }

    private function append_media_replacement_theme_css_output_checksum(array $state, $data, $final = false)
    {
        $buffer = '';
        if ('' !== (string) ($state['checksum_after_buffer_b64'] ?? '')) {
            $decoded = base64_decode((string) $state['checksum_after_buffer_b64'], true);
            $buffer = is_string($decoded) ? $decoded : '';
        }
        $buffer .= (string) $data;
        $chunk_size = $this->get_media_replacement_theme_css_stream_chunk_size();
        while (strlen($buffer) >= $chunk_size) {
            $chunk = substr($buffer, 0, $chunk_size);
            $buffer = (string) substr($buffer, $chunk_size);
            $state['checksum_after'] = $this->advance_media_replacement_theme_css_checksum($state['checksum_after'], $chunk);
        }
        if ($final) {
            $state['checksum_after'] = $this->advance_media_replacement_theme_css_checksum($state['checksum_after'], $buffer);
            $buffer = '';
        }
        $state['checksum_after_buffer_b64'] = '' !== $buffer ? base64_encode($buffer) : '';
        return $state;
    }

    private function read_media_replacement_theme_css_stream_chunk($path, $offset, $length, $context)
    {
        if (!function_exists('ultracache_safe_stream_read_chunk')) {
            return false;
        }
        return ultracache_safe_stream_read_chunk($path, $offset, $length, (string) $context);
    }

    private function initialize_media_replacement_theme_css_stream_file($path, $context)
    {
        return function_exists('ultracache_safe_stream_initialize_file')
            && ultracache_safe_stream_initialize_file($path, (string) $context);
    }

    private function write_media_replacement_theme_css_stream_chunk($path, $offset, $data, $context)
    {
        if (!function_exists('ultracache_safe_stream_write_chunk')) {
            return false;
        }
        return ultracache_safe_stream_write_chunk($path, $offset, $data, (string) $context);
    }

    private function split_media_replacement_theme_css_stream_data($data, $eof)
    {
        $data = (string) $data;
        if ($eof || '' === $data) {
            return array($data, '');
        }
        $overlap = $this->get_media_replacement_theme_css_stream_overlap_size();
        if (strlen($data) <= $overlap) {
            return array('', $data);
        }

        $target = strlen($data) - $overlap;
        $prefix = substr($data, 0, $target);
        $last_delimiter = -1;
        foreach (array("\n", "\r", "\t", ' ', '"', "'", '<', '>') as $delimiter) {
            $position = strrpos($prefix, $delimiter);
            if (false !== $position && $position > $last_delimiter) {
                $last_delimiter = $position;
            }
        }
        $split = $last_delimiter >= 0 ? $last_delimiter + 1 : $target;
        return array(substr($data, 0, $split), substr($data, $split));
    }

    private function get_media_replacement_theme_css_inventory_row_by_id($row_id)
    {
        global $wpdb;
        $table = $this->get_media_replacement_theme_css_files_table_name();
        $row_id = absint($row_id);
        if ('' === $table || $row_id <= 0 || !($wpdb instanceof wpdb)) {
            return array();
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d LIMIT 1', $table, $row_id), ARRAY_A);
        return is_array($row) ? $row : array();
    }

    private function get_media_replacement_theme_css_inventory_row_by_path($path)
    {
        global $wpdb;
        $table = $this->get_media_replacement_theme_css_files_table_name();
        $path = wp_normalize_path((string) $path);
        if ('' === $table || '' === $path || !($wpdb instanceof wpdb)) {
            return array();
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE path_hash = %s LIMIT 1', $table, md5($path)), ARRAY_A);
        return is_array($row) ? $row : array();
    }

    private function update_media_replacement_theme_css_inventory_stream_result($row_id, $scan_status, $checksum_before, $checksum_scheme, $validation_status = null, $checksum_after = null, $error_message = '')
    {
        global $wpdb;
        $table = $this->get_media_replacement_theme_css_files_table_name();
        $row_id = absint($row_id);
        if ('' === $table || $row_id <= 0 || !($wpdb instanceof wpdb)) {
            return false;
        }
        $data = array(
            'scan_status'     => sanitize_key((string) $scan_status),
            'checksum_before' => preg_match('/^[a-f0-9]{32}$/', (string) $checksum_before) ? (string) $checksum_before : '',
            'checksum_scheme' => sanitize_key((string) $checksum_scheme),
            'error_message'   => '' !== (string) $error_message ? wp_strip_all_tags((string) $error_message) : null,
            'updated_at'      => current_time('mysql', true),
        );
        $formats = array('%s', '%s', '%s', '%s', '%s');
        if (null !== $validation_status) {
            $data['validation_status'] = sanitize_key((string) $validation_status);
            $formats[] = '%s';
        }
        if (null !== $checksum_after) {
            $data['checksum_after'] = preg_match('/^[a-f0-9]{32}$/', (string) $checksum_after) ? (string) $checksum_after : '';
            $formats[] = '%s';
        }
        return false !== $wpdb->update($table, $data, array('id' => $row_id), $formats, array('%d'));
    }

    private function update_media_replacement_theme_css_file_refs_checksum($path, $checksum)
    {
        global $wpdb;
        $table = $this->get_media_replacement_file_refs_table_name();
        $path = wp_normalize_path((string) $path);
        $checksum = preg_match('/^[a-f0-9]{32}$/', (string) $checksum) ? (string) $checksum : '';
        if ('' === $table || '' === $path || '' === $checksum || !($wpdb instanceof wpdb)) {
            return false;
        }
        return false !== $wpdb->update(
            $table,
            array('checksum_before' => $checksum, 'updated_at' => current_time('mysql', true)),
            array('file_path' => $path),
            array('%s', '%s'),
            array('%s')
        );
    }

    private function count_media_replacement_theme_css_file_refs($path)
    {
        global $wpdb;
        $table = $this->get_media_replacement_file_refs_table_name();
        $path = wp_normalize_path((string) $path);
        if ('' === $table || '' === $path || !($wpdb instanceof wpdb)) {
            return 0;
        }
        return max(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE file_path = %s', $table, $path)));
    }

    private function insert_media_replacement_theme_css_stream_references($path, $relative, $content)
    {
        $inserted = 0;
        foreach ($this->extract_media_replacement_image_references_from_value((string) $content) as $reference) {
            $match = isset($reference['match']) ? (string) $reference['match'] : '';
            $item = $this->find_media_replacement_item_for_reference_match($match);
            if (empty($item)) {
                continue;
            }
            $index_row = array('raw_fragment' => isset($reference['raw']) ? (string) $reference['raw'] : '');
            $new_fragment = $this->build_media_replacement_new_fragment_for_index($index_row, $item);
            if ('' === $new_fragment) {
                continue;
            }
            $result = $this->insert_media_replacement_theme_css_ref_row(
                $item,
                $path,
                $relative,
                (string) $index_row['raw_fragment'],
                $new_fragment,
                str_repeat('0', 32)
            );
            if ('inserted' === $result) {
                $inserted++;
            }
        }
        return $inserted;
    }

    private function initialize_media_replacement_theme_css_read_state($mode, array $file)
    {
        $now = current_time('mysql', true);
        return $this->update_media_replacement_theme_css_stream_state(array(
            'state_version'    => 2,
            'mode'             => $mode,
            'phase'            => 'read',
            'file_id'          => absint($file['id'] ?? 0),
            'file_path'        => wp_normalize_path((string) ($file['file_path'] ?? $file['path'] ?? '')),
            'relative_path'    => (string) ($file['relative_file_path'] ?? $file['relative'] ?? ''),
            'source_size'      => max(0, (int) ($file['file_size'] ?? $file['size'] ?? 0)),
            'source_mtime'     => max(0, (int) ($file['file_mtime'] ?? $file['mtime'] ?? 0)),
            'source_offset'    => 0,
            'output_offset'    => 0,
            'carry_b64'        => '',
            'checksum_before'  => $this->get_media_replacement_theme_css_checksum_seed(),
            'checksum_after'   => $this->get_media_replacement_theme_css_checksum_seed(),
            'checksum_after_buffer_b64' => '',
            'inserted_refs'    => 0,
            'created_at'       => $now,
            'updated_at'       => $now,
        ));
    }

    private function verify_media_replacement_theme_css_source_stat(array $state)
    {
        $path = (string) $state['file_path'];
        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : false;
        $mtime = is_file($path) ? filemtime($path) : false;
        return is_int($size) && is_int($mtime) && (int) $state['source_size'] === $size && (int) $state['source_mtime'] === $mtime;
    }

    private function initialize_media_replacement_theme_css_commit_verification(array $state, $phase, $path)
    {
        $phase = sanitize_key((string) $phase);
        $path = wp_normalize_path((string) $path);
        if (!in_array($phase, array('temp_verify', 'target_verify'), true) || '' === $path) {
            return array('success' => false, 'message' => __('Theme CSS commit verification could not be initialized.', 'ultracache'));
        }
        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : false;
        $mtime = is_file($path) ? filemtime($path) : false;
        if (!is_int($size) || !is_int($mtime) || $size !== (int) $state['output_offset']) {
            return array('success' => false, 'message' => __('Theme CSS commit verification found a missing file or unexpected output size. Restart Prepare.', 'ultracache'));
        }
        $state['phase'] = $phase;
        $state['commit_verify_path'] = $path;
        $state['commit_verify_offset'] = 0;
        $state['commit_verify_checksum'] = $this->get_media_replacement_theme_css_checksum_seed();
        $state['commit_verify_size'] = $size;
        $state['commit_verify_mtime'] = $mtime;
        return array('success' => true, 'state' => $this->update_media_replacement_theme_css_stream_state($state));
    }

    private function verify_media_replacement_theme_css_commit_stat(array $state)
    {
        $path = (string) ($state['commit_verify_path'] ?? '');
        clearstatcache(true, $path);
        $size = '' !== $path && is_file($path) ? filesize($path) : false;
        $mtime = '' !== $path && is_file($path) ? filemtime($path) : false;
        return is_int($size)
            && is_int($mtime)
            && (int) ($state['commit_verify_size'] ?? -1) === $size
            && (int) ($state['commit_verify_mtime'] ?? -1) === $mtime;
    }

    private function process_media_replacement_theme_css_scan_stream(array $state, $deadline)
    {
        $path = (string) $state['file_path'];
        if (!$this->verify_media_replacement_theme_css_source_stat($state)) {
            return array('success' => false, 'message' => __('A Theme CSS file changed while it was being scanned. Restart Prepare.', 'ultracache'));
        }
        $read = $this->read_media_replacement_theme_css_stream_chunk($path, $state['source_offset'], $this->get_media_replacement_theme_css_stream_chunk_size(), 'media_library_replacement_theme_css_scan_stream');
        if (!is_array($read)) {
            return array('success' => false, 'message' => __('A Theme CSS file could not be read during the resumable scan.', 'ultracache'));
        }
        $chunk = (string) $read['data'];
        $eof = !empty($read['eof']);
        $state['checksum_before'] = $this->advance_media_replacement_theme_css_checksum($state['checksum_before'], $chunk);
        $state['source_offset'] += strlen($chunk);
        $data = $this->decode_media_replacement_theme_css_stream_carry($state) . $chunk;
        list($process, $carry) = $this->split_media_replacement_theme_css_stream_data($data, $eof);
        if ('' !== $process) {
            $state['inserted_refs'] += $this->insert_media_replacement_theme_css_stream_references($path, $state['relative_path'], $process);
        }
        $state['carry_b64'] = '' !== $carry ? base64_encode($carry) : '';
        $state = $this->update_media_replacement_theme_css_stream_state($state);

        if (!$eof) {
            return array('success' => true, 'complete' => false, 'state' => $state);
        }

        $checksum = (string) $state['checksum_before'];
        if (!$this->update_media_replacement_theme_css_file_refs_checksum($path, $checksum)
            || !$this->update_media_replacement_theme_css_inventory_stream_result($state['file_id'], 'scanned', $checksum, $this->get_media_replacement_theme_css_checksum_scheme())) {
            return array('success' => false, 'message' => __('Theme CSS stream scan progress could not be persisted.', 'ultracache'));
        }
        return array('success' => true, 'complete' => true, 'state' => $state, 'checksum' => $checksum);
    }

    private function process_media_replacement_theme_css_validation_stream(array $state)
    {
        if (!$this->verify_media_replacement_theme_css_source_stat($state)) {
            return array('success' => false, 'message' => __('A Theme CSS file changed during Prepare. Restart Prepare.', 'ultracache'));
        }
        $read = $this->read_media_replacement_theme_css_stream_chunk($state['file_path'], $state['source_offset'], $this->get_media_replacement_theme_css_stream_chunk_size(), 'media_library_replacement_theme_css_validate_stream');
        if (!is_array($read)) {
            return array('success' => false, 'message' => __('A Theme CSS file could not be read during file-set validation.', 'ultracache'));
        }
        $chunk = (string) $read['data'];
        $state['checksum_before'] = $this->advance_media_replacement_theme_css_checksum($state['checksum_before'], $chunk);
        $state['source_offset'] += strlen($chunk);
        $state = $this->update_media_replacement_theme_css_stream_state($state);
        if (empty($read['eof'])) {
            return array('success' => true, 'complete' => false, 'state' => $state);
        }

        $inventory = $this->get_media_replacement_theme_css_inventory_row_by_id($state['file_id']);
        $expected = (string) ($inventory['checksum_before'] ?? '');
        $scheme = sanitize_key((string) ($inventory['checksum_scheme'] ?? ''));
        if ($this->get_media_replacement_theme_css_checksum_scheme() !== $scheme || !preg_match('/^[a-f0-9]{32}$/', $expected) || !hash_equals($expected, $state['checksum_before'])) {
            return array('success' => false, 'message' => __('A Theme CSS file changed during Prepare. Restart Prepare to build a consistent plan.', 'ultracache'));
        }

        global $wpdb;
        $table = $this->get_media_replacement_theme_css_files_table_name();
        $updated = $wpdb instanceof wpdb && false !== $wpdb->update(
            $table,
            array('validation_status' => 'validated', 'updated_at' => current_time('mysql', true)),
            array('id' => $state['file_id']),
            array('%s', '%s'),
            array('%d')
        );
        if (!$updated) {
            return array('success' => false, 'message' => __('Theme CSS validation progress could not be persisted.', 'ultracache'));
        }
        return array('success' => true, 'complete' => true, 'state' => $state);
    }

    public function scan_media_library_replacement_theme_css_references($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array('success' => false, 'message' => __('Media Library replacement registry tables are not available.', 'ultracache'));
        }
        $args = is_array($args) ? $args : array();
        if (!$this->media_replacement_has_registry_rows()) {
            return $this->build_media_replacement_empty_registry_response(__('No Media Library replacement registry rows are available for theme CSS scanning.', 'ultracache'));
        }

        $limit = max(1, min(100, absint($args['limit'] ?? 20)));
        $entry_limit = max(25, min(2500, $limit * 25));
        $time_budget = max(1.0, min(30.0, isset($args['time_budget']) ? (float) $args['time_budget'] : 15.0));
        $deadline = microtime(true) + $time_budget;
        $state = $this->get_media_replacement_theme_css_scan_state();
        $start_new = 'idle' === $state['phase'] || !empty($args['reset']) || !empty($args['start']);

        if ($start_new) {
            $this->clear_media_replacement_theme_css_stream_state(true);
            if (!$this->reset_media_replacement_theme_css_refs()) {
                return array('success' => false, 'message' => __('Theme CSS scan storage could not be reset for this workflow.', 'ultracache'));
            }
            $roots = $this->get_media_replacement_theme_css_roots();
            $now = current_time('mysql', true);
            $state = $this->initialize_media_replacement_theme_css_traversal(array(
                'state_version' => 2,
                'status' => 'scanning',
                'phase' => 'discover',
                'cursor_file_id' => 0,
                'total_files' => 0,
                'discovered_files' => 0,
                'scanned_files' => 0,
                'validated_files' => 0,
                'matched_refs' => 0,
                'matched_files' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'completed_at' => '',
            ), $roots);
            $state = $this->update_media_replacement_theme_css_scan_state($state);
        }

        if ('completed' === $state['status'] && 'complete' === $state['phase']) {
            $summary = $this->get_media_replacement_theme_css_summary();
            /* translators: 1: planned Theme CSS replacement count; 2: CSS file count. */
            $message = sprintf(__('Theme CSS reference scan is already complete with %1$d planned replacements across %2$d files.', 'ultracache'), (int) $summary['total'], (int) $summary['files']);
            return $this->build_media_replacement_theme_css_scan_response($state, $summary, array(), false, $message, __('Continue Prepare with the Theme CSS replacement preview.', 'ultracache'));
        }
        if ('failed' === $state['status']) {
            return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_scan_failed', __('The Theme CSS scan is in a failed state. Restart Prepare to rebuild it.', 'ultracache'));
        }

        $batch = array('discovered' => 0, 'scanned' => 0, 'validated' => 0, 'refs' => 0);

        if ('discover' === $state['phase']) {
            $work_units = 0;
            $complete = false;
            while ($work_units < $entry_limit && ($work_units === 0 || microtime(true) < $deadline)) {
                $error = '';
                $entry = $this->get_next_media_replacement_theme_css_traversal_entry($state, $error);
                if ('' !== $error) {
                    return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_discovery_failed', $error);
                }
                if (!empty($entry['done'])) {
                    $complete = true;
                    break;
                }
                if (!empty($entry['worked'])) {
                    $work_units++;
                }
                if (!empty($entry['file'])) {
                    $result = $this->insert_media_replacement_theme_css_inventory_file($entry['file']);
                    if (false === $result) {
                        return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_inventory_write_failed', __('Theme CSS discovery could not persist its file inventory.', 'ultracache'));
                    }
                    if ('inserted' === $result) {
                        $state['discovered_files']++;
                        $batch['discovered']++;
                    }
                }
            }
            if ($complete) {
                $state['total_files'] = $this->get_media_replacement_theme_css_inventory_count();
                $state['discovered_files'] = $state['total_files'];
                $state['phase'] = 'scan';
                $state['cursor_file_id'] = 0;
                $state['root_index'] = count($state['roots']);
                $state['traversal_stack'] = array();
            }
            $state['status'] = 'scanning';
            $state['updated_at'] = current_time('mysql', true);
            $state = $this->update_media_replacement_theme_css_scan_state($state);
            if ($complete) {
                /* translators: %d: discovered Theme CSS file count. */
                $message = sprintf(__('Theme CSS discovery completed with %d files. The resumable content scan is ready to continue.', 'ultracache'), (int) $state['total_files']);
            } else {
                /* translators: %d: Theme CSS files discovered so far. */
                $message = sprintf(__('Theme CSS discovery is continuing. %d CSS files have been found so far.', 'ultracache'), (int) $state['discovered_files']);
            }
            return $this->build_media_replacement_theme_css_scan_response($state, array(), $batch, true, $message, $complete ? __('Continue with the persisted Theme CSS inventory scan.', 'ultracache') : __('Continue discovering Theme CSS files from the saved directory cursor.', 'ultracache'));
        }

        if ('scan' === $state['phase']) {
            $completed_files = 0;
            while ($completed_files < $limit && microtime(true) < $deadline) {
                $stream = $this->get_media_replacement_theme_css_stream_state();
                if ('scan' !== $stream['mode']) {
                    $rows = $this->get_media_replacement_theme_css_inventory_rows($state['cursor_file_id'], 1);
                    if (empty($rows)) {
                        break;
                    }
                    $stream = $this->initialize_media_replacement_theme_css_read_state('scan', $rows[0]);
                }
                $result = $this->process_media_replacement_theme_css_scan_stream($stream, $deadline);
                if (empty($result['success'])) {
                    return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_file_read_failed', (string) ($result['message'] ?? __('Theme CSS stream scanning failed.', 'ultracache')));
                }
                if (empty($result['complete'])) {
                    continue;
                }
                $file_refs = $this->count_media_replacement_theme_css_file_refs($stream['file_path']);
                $state['cursor_file_id'] = $stream['file_id'];
                $state['scanned_files']++;
                $state['matched_refs'] += $file_refs;
                if ($file_refs > 0) {
                    $state['matched_files']++;
                }
                $batch['scanned']++;
                $batch['refs'] += $file_refs;
                $completed_files++;
                $this->clear_media_replacement_theme_css_stream_state(false);
            }

            $active_stream = $this->get_media_replacement_theme_css_stream_state();
            $has_rows = 'scan' === $active_stream['mode'];
            if (!$has_rows) {
                $has_rows = !empty($this->get_media_replacement_theme_css_inventory_rows($state['cursor_file_id'], 1));
            }
            if (!$has_rows) {
                $current_roots = $this->get_media_replacement_theme_css_roots();
                $current_hash = $this->get_media_replacement_theme_css_roots_hash($current_roots);
                if ('' === $state['roots_hash'] || !hash_equals($state['roots_hash'], $current_hash)) {
                    return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_file_set_changed', __('The active Theme CSS roots changed during Prepare. Restart Prepare.', 'ultracache'));
                }
                if (!$this->reset_media_replacement_theme_css_inventory_validation()) {
                    return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_inventory_write_failed', __('Theme CSS validation state could not be initialized.', 'ultracache'));
                }
                $state = $this->initialize_media_replacement_theme_css_traversal($state, $current_roots);
                $state['phase'] = 'validate_file_set';
                $state['validated_files'] = 0;
            }
            $state['status'] = 'scanning';
            $state['updated_at'] = current_time('mysql', true);
            $state = $this->update_media_replacement_theme_css_scan_state($state);
            $summary = array('total' => (int) $state['matched_refs'], 'pending' => (int) $state['matched_refs'], 'applied' => 0, 'verified' => 0, 'failed' => 0, 'files' => (int) $state['matched_files']);
            if ($has_rows) {
                /* translators: 1: scanned CSS files; 2: total CSS files; 3: planned replacement count. */
                $message = sprintf(__('Theme CSS scan: %1$d of %2$d files completed, %3$d planned replacements found.', 'ultracache'), (int) $state['scanned_files'], (int) $state['total_files'], (int) $state['matched_refs']);
            } else {
                $message = __('Theme CSS content scan is complete. Prepare is validating the same file set with resumable checksums.', 'ultracache');
            }
            return $this->build_media_replacement_theme_css_scan_response($state, $summary, $batch, true, $message, $has_rows ? __('Continue the current CSS file from its saved byte offset.', 'ultracache') : __('Continue validating the Theme CSS file set.', 'ultracache'));
        }

        if ('validate_file_set' === $state['phase']) {
            $work_units = 0;
            $complete = false;
            while ($work_units < $entry_limit && microtime(true) < $deadline) {
                $stream = $this->get_media_replacement_theme_css_stream_state();
                if ('validate' === $stream['mode']) {
                    $result = $this->process_media_replacement_theme_css_validation_stream($stream);
                    if (empty($result['success'])) {
                        return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_file_set_changed', (string) ($result['message'] ?? __('Theme CSS validation failed.', 'ultracache')));
                    }
                    $work_units++;
                    if (empty($result['complete'])) {
                        continue;
                    }
                    $state['validated_files']++;
                    $batch['validated']++;
                    $this->clear_media_replacement_theme_css_stream_state(false);
                    continue;
                }

                $error = '';
                $entry = $this->get_next_media_replacement_theme_css_traversal_entry($state, $error);
                if ('' !== $error) {
                    return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_validation_failed', $error);
                }
                if (!empty($entry['done'])) {
                    $complete = true;
                    break;
                }
                if (!empty($entry['worked'])) {
                    $work_units++;
                }
                if (!empty($entry['file'])) {
                    $inventory = $this->get_media_replacement_theme_css_inventory_row_by_path($entry['file']['path']);
                    if (empty($inventory)) {
                        return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_file_set_changed', __('A new Theme CSS file appeared during Prepare. Restart Prepare.', 'ultracache'));
                    }
                    if ((int) $inventory['file_size'] !== (int) $entry['file']['size'] || (int) $inventory['file_mtime'] !== (int) $entry['file']['mtime']) {
                        return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_file_set_changed', __('A Theme CSS file changed during Prepare. Restart Prepare.', 'ultracache'));
                    }
                    $this->initialize_media_replacement_theme_css_read_state('validate', $inventory);
                }
            }

            if ($complete) {
                $unseen = $this->get_media_replacement_theme_css_unseen_inventory_count();
                if ($unseen > 0) {
                    return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_file_set_changed', __('One or more Theme CSS files disappeared during Prepare. Restart Prepare.', 'ultracache'));
                }
                $state['status'] = 'completed';
                $state['phase'] = 'complete';
                $state['validated_files'] = $state['total_files'];
                $state['completed_at'] = current_time('mysql', true);
                $state['root_index'] = count($state['roots']);
                $state['traversal_stack'] = array();
            }
            $state['updated_at'] = current_time('mysql', true);
            $state = $this->update_media_replacement_theme_css_scan_state($state);
            $summary = $complete ? $this->get_media_replacement_theme_css_summary() : array('total' => (int) $state['matched_refs'], 'pending' => (int) $state['matched_refs'], 'applied' => 0, 'verified' => 0, 'failed' => 0, 'files' => (int) $state['matched_files']);
            if ($complete) {
                /* translators: 1: planned Theme CSS replacement count; 2: CSS file count. */
                $message = sprintf(__('Theme CSS scan and file-set validation completed with %1$d planned replacements across %2$d files.', 'ultracache'), (int) $summary['total'], (int) $summary['files']);
            } else {
                /* translators: 1: validated CSS files; 2: total CSS files. */
                $message = sprintf(__('Theme CSS file-set validation: %1$d of %2$d files confirmed unchanged.', 'ultracache'), (int) $state['validated_files'], (int) $state['total_files']);
            }
            return $this->build_media_replacement_theme_css_scan_response($state, $summary, $batch, !$complete, $message, $complete ? __('Continue Prepare with the Theme CSS replacement preview.', 'ultracache') : __('Continue the current validation file from its saved byte offset.', 'ultracache'));
        }

        return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_scan_failed', __('The Theme CSS scan reached an unsupported phase. Restart Prepare.', 'ultracache'));
    }

    private function get_media_replacement_theme_css_next_file(array $statuses)
    {
        global $wpdb;
        $table = $this->get_media_replacement_file_refs_table_name();
        $statuses = array_values(array_intersect(array_map('sanitize_key', $statuses), array('pending', 'failed', 'applied', 'verify_failed')));
        if ('' === $table || empty($statuses) || !($wpdb instanceof wpdb)) {
            return '';
        }
        if (1 === count($statuses)) {
            return wp_normalize_path((string) $wpdb->get_var($wpdb->prepare('SELECT file_path FROM %i WHERE status = %s ORDER BY id ASC LIMIT 1', $table, $statuses[0])));
        }
        return wp_normalize_path((string) $wpdb->get_var($wpdb->prepare('SELECT file_path FROM %i WHERE status = %s OR status = %s ORDER BY id ASC LIMIT 1', $table, $statuses[0], $statuses[1])));
    }

    private function get_media_replacement_theme_css_file_rows($path, array $statuses)
    {
        global $wpdb;
        $table = $this->get_media_replacement_file_refs_table_name();
        $path = wp_normalize_path((string) $path);
        $statuses = array_values(array_intersect(array_map('sanitize_key', $statuses), array('pending', 'failed', 'applied', 'verify_failed')));
        if ('' === $table || '' === $path || empty($statuses) || !($wpdb instanceof wpdb)) {
            return array();
        }
        if (1 === count($statuses)) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE file_path = %s AND status = %s ORDER BY id ASC', $table, $path, $statuses[0]), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE file_path = %s AND (status = %s OR status = %s) ORDER BY id ASC', $table, $path, $statuses[0], $statuses[1]), ARRAY_A);
        }
        return is_array($rows) ? $rows : array();
    }

    private function reset_media_replacement_theme_css_file_flags($path, $mode)
    {
        global $wpdb;
        $table = $this->get_media_replacement_file_refs_table_name();
        $path = wp_normalize_path((string) $path);
        if ('' === $table || '' === $path || !($wpdb instanceof wpdb)) {
            return false;
        }
        $data = 'apply' === $mode
            ? array('apply_old_found' => 0, 'apply_new_found' => 0, 'updated_at' => current_time('mysql', true))
            : array('verify_old_found' => 0, 'verify_new_found' => 0, 'updated_at' => current_time('mysql', true));
        return false !== $wpdb->update($table, $data, array('file_path' => $path), array('%d', '%d', '%s'), array('%s'));
    }

    private function mark_media_replacement_theme_css_ref_flag($row_id, $field)
    {
        global $wpdb;
        $allowed = array('apply_old_found', 'apply_new_found', 'verify_old_found', 'verify_new_found');
        if (!in_array($field, $allowed, true)) {
            return false;
        }
        $table = $this->get_media_replacement_file_refs_table_name();
        $row_id = absint($row_id);
        if ('' === $table || $row_id <= 0 || !($wpdb instanceof wpdb)) {
            return false;
        }
        return false !== $wpdb->update($table, array($field => 1, 'updated_at' => current_time('mysql', true)), array('id' => $row_id), array('%d', '%s'), array('%d'));
    }

    private function build_media_replacement_theme_css_temp_path($path, $suffix)
    {
        $uploads = function_exists('ultracache_uploads_base_info') ? ultracache_uploads_base_info() : wp_upload_dir(null, false);
        if (empty($uploads['basedir']) || !function_exists('ultracache_storage_join_path')) {
            return '';
        }
        $name = md5(wp_normalize_path((string) $path)) . '-' . sanitize_file_name(basename((string) $path)) . '.' . sanitize_key((string) $suffix) . '.tmp';
        return ultracache_storage_join_path((string) $uploads['basedir'], 'ultracache/theme-css-temp/current/' . $name);
    }

    private function initialize_media_replacement_theme_css_apply_state($path)
    {
        $inventory = $this->get_media_replacement_theme_css_inventory_row_by_path($path);
        if (empty($inventory) || $this->get_media_replacement_theme_css_checksum_scheme() !== sanitize_key((string) ($inventory['checksum_scheme'] ?? ''))) {
            return array('success' => false, 'message' => __('Theme CSS was prepared with an older checksum format. Restart Prepare before applying replacements.', 'ultracache'));
        }
        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : false;
        $mtime = is_file($path) ? filemtime($path) : false;
        if (!is_int($size) || !is_int($mtime)) {
            return array('success' => false, 'message' => __('Theme CSS file could not be opened for replacement.', 'ultracache'));
        }
        $backup = $this->build_media_replacement_theme_css_backup_path($path);
        $temp = $this->build_media_replacement_theme_css_temp_path($path, 'output');
        $backup_temp = $this->build_media_replacement_theme_css_temp_path($path, 'backup');
        $temp_ready = '' !== $temp && $this->initialize_media_replacement_theme_css_stream_file($temp, 'media_library_replacement_theme_css_temp');
        $backup_temp_ready = '' !== $backup_temp && $this->initialize_media_replacement_theme_css_stream_file($backup_temp, 'media_library_replacement_theme_css_backup_temp');
        $flags_ready = $this->reset_media_replacement_theme_css_file_flags($path, 'apply');
        if ('' === $backup || !$temp_ready || !$backup_temp_ready || !$flags_ready) {
            if (function_exists('ultracache_safe_unlink')) {
                if ($temp_ready) {
                    ultracache_safe_unlink($temp, 'media_library_replacement_theme_css_temp_init_cleanup');
                }
                if ($backup_temp_ready) {
                    ultracache_safe_unlink($backup_temp, 'media_library_replacement_theme_css_backup_temp_init_cleanup');
                }
            }
            return array('success' => false, 'message' => __('Theme CSS temporary replacement files could not be initialized.', 'ultracache'));
        }
        $state = $this->initialize_media_replacement_theme_css_read_state('apply', $inventory);
        $state['temp_path'] = $temp;
        $state['backup_temp_path'] = $backup_temp;
        $state['backup_path'] = $backup;
        return array('success' => true, 'state' => $this->update_media_replacement_theme_css_stream_state($state));
    }

    private function transform_media_replacement_theme_css_segment($path, $segment)
    {
        $rows = $this->get_media_replacement_theme_css_file_rows($path, array('pending', 'failed'));
        $segment = (string) $segment;
        foreach ($rows as $row) {
            $row_id = absint($row['id'] ?? 0);
            $old = (string) ($row['old_fragment'] ?? '');
            $new = (string) ($row['new_fragment'] ?? '');
            if ($row_id <= 0 || '' === $old || '' === $new || $old === $new) {
                continue;
            }
            if (false !== strpos($segment, $old)) {
                if (empty($row['apply_old_found'])) {
                    $this->mark_media_replacement_theme_css_ref_flag($row_id, 'apply_old_found');
                }
                $segment = str_replace($old, $new, $segment);
            }
            if (false !== strpos($segment, $new)) {
                if (empty($row['apply_new_found'])) {
                    $this->mark_media_replacement_theme_css_ref_flag($row_id, 'apply_new_found');
                }
            }
        }
        return $segment;
    }

    private function persist_media_replacement_theme_css_applied_state(array $state)
    {
        global $wpdb;
        $table = $this->get_media_replacement_file_refs_table_name();
        $updated = $wpdb instanceof wpdb && false !== $wpdb->update(
            $table,
            array(
                'status' => 'applied',
                'backup_file_path' => $state['backup_path'],
                'checksum_before' => $state['checksum_before'],
                'checksum_after' => $state['checksum_after'],
                'error_message' => null,
                'updated_at' => current_time('mysql', true),
            ),
            array('file_path' => $state['file_path']),
            array('%s', '%s', '%s', '%s', '%s', '%s'),
            array('%s')
        );
        if (!$updated) {
            return array('success' => false, 'retry' => true, 'message' => __('Theme CSS was committed, but registry rows could not be updated. Resume Do to reconcile.', 'ultracache'));
        }
        if (!$this->update_media_replacement_theme_css_inventory_stream_result($state['file_id'], 'scanned', $state['checksum_before'], $this->get_media_replacement_theme_css_checksum_scheme(), 'validated', $state['checksum_after'])) {
            return array('success' => false, 'retry' => true, 'message' => __('Theme CSS was committed, but its inventory checksum could not be updated. Resume Do to reconcile.', 'ultracache'));
        }
        return array('success' => true, 'complete' => true);
    }

    private function process_media_replacement_theme_css_commit_verification(array $state)
    {
        $phase = (string) $state['phase'];
        if ('temp_verify' === $phase && !is_file((string) $state['temp_path'])) {
            $initialized = $this->initialize_media_replacement_theme_css_commit_verification($state, 'target_verify', $state['file_path']);
            if (empty($initialized['success'])) {
                return $initialized;
            }
            return array('success' => true, 'complete' => false, 'state' => $initialized['state']);
        }
        if (!$this->verify_media_replacement_theme_css_commit_stat($state)) {
            return array('success' => false, 'message' => __('Theme CSS commit verification detected that the file changed while it was being checked. Restart Prepare.', 'ultracache'));
        }
        $read = $this->read_media_replacement_theme_css_stream_chunk(
            $state['commit_verify_path'],
            $state['commit_verify_offset'],
            $this->get_media_replacement_theme_css_stream_chunk_size(),
            'media_library_replacement_theme_css_commit_verify'
        );
        if (!is_array($read)) {
            return array('success' => false, 'message' => __('Theme CSS commit verification could not read the file.', 'ultracache'));
        }
        $chunk = (string) $read['data'];
        $eof = !empty($read['eof']);
        $state['commit_verify_checksum'] = $this->advance_media_replacement_theme_css_checksum($state['commit_verify_checksum'], $chunk);
        $state['commit_verify_offset'] += strlen($chunk);
        if ($eof && $state['commit_verify_offset'] > 0 && 0 === $state['commit_verify_offset'] % $this->get_media_replacement_theme_css_stream_chunk_size()) {
            $state['commit_verify_checksum'] = $this->advance_media_replacement_theme_css_checksum($state['commit_verify_checksum'], '');
        }
        $state = $this->update_media_replacement_theme_css_stream_state($state);
        if (!$eof) {
            return array('success' => true, 'complete' => false, 'state' => $state);
        }
        if (!$this->verify_media_replacement_theme_css_commit_stat($state)
            || (int) $state['commit_verify_offset'] !== (int) $state['output_offset']
            || !hash_equals((string) $state['checksum_after'], (string) $state['commit_verify_checksum'])) {
            return array('success' => false, 'message' => __('Theme CSS commit verification found content that does not match the prepared transformed checksum. Restart Prepare.', 'ultracache'));
        }

        if ('temp_verify' === $phase) {
            if (!$this->verify_media_replacement_theme_css_source_stat($state)) {
                return array('success' => false, 'message' => __('Theme CSS changed before the verified transformed file could be committed and was not overwritten.', 'ultracache'));
            }
            if (!function_exists('ultracache_safe_rename')
                || !ultracache_safe_rename($state['temp_path'], $state['file_path'], 'media_library_replacement_theme_css_apply_commit')) {
                return array('success' => false, 'retry' => true, 'message' => __('Theme CSS backup was committed, but the verified transformed file still needs to be committed. Resume Do.', 'ultracache'));
            }
            $initialized = $this->initialize_media_replacement_theme_css_commit_verification($state, 'target_verify', $state['file_path']);
            if (empty($initialized['success'])) {
                return array('success' => false, 'retry' => true, 'message' => (string) ($initialized['message'] ?? __('Theme CSS target verification could not start after the file commit. Resume Do.', 'ultracache')));
            }
            return array('success' => true, 'complete' => false, 'state' => $initialized['state']);
        }

        $state['checksum_after'] = (string) $state['commit_verify_checksum'];
        $state['phase'] = 'committed';
        $state['commit_verified'] = true;
        $state['commit_verify_path'] = '';
        $state['commit_verify_offset'] = 0;
        $state['commit_verify_checksum'] = $this->get_media_replacement_theme_css_checksum_seed();
        $state['commit_verify_size'] = 0;
        $state['commit_verify_mtime'] = 0;
        $state = $this->update_media_replacement_theme_css_stream_state($state);
        return $this->persist_media_replacement_theme_css_applied_state($state);
    }

    private function complete_media_replacement_theme_css_apply_file(array $state)
    {
        $inventory = $this->get_media_replacement_theme_css_inventory_row_by_id($state['file_id']);
        $expected = (string) ($inventory['checksum_before'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $expected) || !hash_equals($expected, $state['checksum_before'])) {
            return array('success' => false, 'message' => __('Theme CSS changed after Prepare and was not overwritten.', 'ultracache'));
        }
        $rows = $this->get_media_replacement_theme_css_file_rows($state['file_path'], array('pending', 'failed'));
        foreach ($rows as $row) {
            if (empty($row['apply_old_found']) || empty($row['apply_new_found'])) {
                return array('success' => false, 'message' => __('Theme CSS no longer matches every prepared replacement. Restart Prepare.', 'ultracache'));
            }
        }
        if (!function_exists('ultracache_safe_rename')) {
            return array('success' => false, 'message' => __('Theme CSS replacement files could not be committed.', 'ultracache'));
        }
        if ('read' === $state['phase']) {
            if (!$this->verify_media_replacement_theme_css_source_stat($state)) {
                return array('success' => false, 'message' => __('Theme CSS changed after Prepare and was not overwritten.', 'ultracache'));
            }
            if (!ultracache_safe_rename($state['backup_temp_path'], $state['backup_path'], 'media_library_replacement_theme_css_backup_commit')) {
                return array('success' => false, 'message' => __('Theme CSS backup could not be committed.', 'ultracache'));
            }
            $state['phase'] = 'backup_committed';
            $state = $this->update_media_replacement_theme_css_stream_state($state);
        }
        if ('backup_committed' === $state['phase']) {
            if (is_file($state['temp_path'])) {
                if (!$this->verify_media_replacement_theme_css_source_stat($state)) {
                    return array('success' => false, 'message' => __('Theme CSS changed before the transformed file could be verified and was not overwritten.', 'ultracache'));
                }
                return $this->initialize_media_replacement_theme_css_commit_verification($state, 'temp_verify', $state['temp_path']);
            }
            return $this->initialize_media_replacement_theme_css_commit_verification($state, 'target_verify', $state['file_path']);
        }
        if (in_array($state['phase'], array('temp_verify', 'target_verify'), true)) {
            return $this->process_media_replacement_theme_css_commit_verification($state);
        }
        if ('committed' === $state['phase']) {
            if (empty($state['commit_verified'])) {
                return $this->initialize_media_replacement_theme_css_commit_verification($state, 'target_verify', $state['file_path']);
            }
            return $this->persist_media_replacement_theme_css_applied_state($state);
        }
        return array('success' => false, 'message' => __('Theme CSS apply state could not be reconciled.', 'ultracache'));
    }

    private function process_media_replacement_theme_css_apply_stream(array $state)
    {
        if (in_array($state['phase'], array('backup_committed', 'temp_verify', 'target_verify', 'committed'), true)) {
            return $this->complete_media_replacement_theme_css_apply_file($state);
        }
        if (!$this->verify_media_replacement_theme_css_source_stat($state)) {
            return array('success' => false, 'message' => __('Theme CSS changed after Prepare and was not overwritten.', 'ultracache'));
        }
        $read = $this->read_media_replacement_theme_css_stream_chunk($state['file_path'], $state['source_offset'], $this->get_media_replacement_theme_css_stream_chunk_size(), 'media_library_replacement_theme_css_apply_stream');
        if (!is_array($read)) {
            return array('success' => false, 'message' => __('Theme CSS file could not be read during replacement.', 'ultracache'));
        }
        $chunk = (string) $read['data'];
        $eof = !empty($read['eof']);
        if (false === $this->write_media_replacement_theme_css_stream_chunk($state['backup_temp_path'], $state['source_offset'], $chunk, 'media_library_replacement_theme_css_backup_stream')) {
            return array('success' => false, 'message' => __('Theme CSS backup could not be written.', 'ultracache'));
        }
        $state['checksum_before'] = $this->advance_media_replacement_theme_css_checksum($state['checksum_before'], $chunk);
        $state['source_offset'] += strlen($chunk);
        $data = $this->decode_media_replacement_theme_css_stream_carry($state) . $chunk;
        list($process, $carry) = $this->split_media_replacement_theme_css_stream_data($data, $eof);
        if ('' !== $process) {
            $output = $this->transform_media_replacement_theme_css_segment($state['file_path'], $process);
            if (false === $this->write_media_replacement_theme_css_stream_chunk($state['temp_path'], $state['output_offset'], $output, 'media_library_replacement_theme_css_output_stream')) {
                return array('success' => false, 'message' => __('Theme CSS temporary output could not be written.', 'ultracache'));
            }
            $state = $this->append_media_replacement_theme_css_output_checksum($state, $output, false);
            $state['output_offset'] += strlen($output);
        }
        $state['carry_b64'] = '' !== $carry ? base64_encode($carry) : '';
        if ($eof) {
            $state = $this->append_media_replacement_theme_css_output_checksum($state, '', true);
        }
        $state = $this->update_media_replacement_theme_css_stream_state($state);
        if (!$eof) {
            return array('success' => true, 'complete' => false, 'state' => $state);
        }
        return $this->complete_media_replacement_theme_css_apply_file($state);
    }

    public function apply_media_library_replacement_theme_css_replacements($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array('success' => false, 'message' => __('Media Library replacement registry tables are not available.', 'ultracache'));
        }
        $args = is_array($args) ? $args : array();
        $authorization = $this->authorize_media_replacement_destructive_action('theme_css_apply', $args);
        if (empty($authorization['success'])) {
            return $authorization;
        }
        $limit = max(1, min(100, absint($args['limit'] ?? 25)));
        $deadline = microtime(true) + max(1.0, min(30.0, isset($args['time_budget']) ? (float) $args['time_budget'] : 15.0));
        $processed_files = 0;
        $applied_refs = 0;

        while ($processed_files < $limit && microtime(true) < $deadline) {
            $stream = $this->get_media_replacement_theme_css_stream_state();
            if ('apply' !== $stream['mode']) {
                $path = $this->get_media_replacement_theme_css_next_file(array('pending', 'failed'));
                if ('' === $path) {
                    break;
                }
                $initialized = $this->initialize_media_replacement_theme_css_apply_state($path);
                if (empty($initialized['success'])) {
                    return array('success' => false, 'blocked' => true, 'message' => (string) ($initialized['message'] ?? __('Theme CSS apply could not start.', 'ultracache')), 'status' => 'theme_css_apply_failed', 'hasMore' => false);
                }
                $stream = $initialized['state'];
            }
            $before = count($this->get_media_replacement_theme_css_file_rows($stream['file_path'], array('pending', 'failed')));
            $result = $this->process_media_replacement_theme_css_apply_stream($stream);
            if (empty($result['success'])) {
                return array('success' => false, 'blocked' => empty($result['retry']), 'retryRequired' => !empty($result['retry']), 'message' => (string) ($result['message'] ?? __('Theme CSS apply failed.', 'ultracache')), 'status' => !empty($result['retry']) ? 'theme_css_reconcile_required' : 'theme_css_apply_failed', 'hasMore' => !empty($result['retry']));
            }
            if (empty($result['complete'])) {
                continue;
            }
            $applied_refs += $before;
            $processed_files++;
            $this->clear_media_replacement_theme_css_stream_state(false);
        }

        $summary = $this->get_media_replacement_theme_css_summary();
        $remaining = (int) $summary['pending'] + (int) $summary['failed'];
        $active = $this->get_media_replacement_theme_css_stream_state();
        $has_more = $remaining > 0 || ('apply' === $active['mode']);
        $total = (int) $summary['total'];
        if ($has_more) {
            /* translators: 1: applied Theme CSS replacement count; 2: total replacement count. */
            $message = sprintf(__('Theme CSS replacement apply is in progress: %1$d of %2$d replacements applied.', 'ultracache'), (int) $summary['applied'], $total);
        } else {
            /* translators: 1: total Theme CSS replacement count; 2: failed replacement count. */
            $message = sprintf(__('Theme CSS replacement apply completed for %1$d replacements. Failed: %2$d.', 'ultracache'), $total, (int) $summary['failed']);
        }
        if (!$has_more) {
            $this->clear_media_replacement_destructive_authorization('theme_css_apply');
        }

        return array(
            'success' => true,
            'message' => $message,
            'status' => $has_more ? 'theme_css_applying' : 'theme_css_applied',
            'hasMore' => $has_more,
            'batchProcessedThemeCssFiles' => $processed_files,
            'batchProcessedThemeCssRefs' => $applied_refs,
            'batchAppliedThemeCssRefs' => $applied_refs,
            'themeCssRefs' => $total,
            'themeCssPendingRefs' => (int) $summary['pending'],
            'themeCssAppliedRefs' => (int) $summary['applied'],
            'themeCssVerifiedRefs' => (int) $summary['verified'],
            'themeCssFailedRefs' => (int) $summary['failed'],
            'themeCssFilesWithRefs' => (int) $summary['files'],
            'progressPercent' => $total > 0 ? min(100, round(((int) $summary['applied'] / $total) * 100, 1)) : 100,
            'nextStep' => $has_more ? __('Continue the current Theme CSS file from its saved byte offset.', 'ultracache') : __('Next step: Verify Theme CSS Replacements before Cleanup Preview.', 'ultracache'),
        );
    }

    private function initialize_media_replacement_theme_css_verify_state($path)
    {
        $inventory = $this->get_media_replacement_theme_css_inventory_row_by_path($path);
        if (empty($inventory)) {
            return array('success' => false, 'message' => __('Theme CSS inventory row is missing during verification.', 'ultracache'));
        }
        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : false;
        $mtime = is_file($path) ? filemtime($path) : false;
        if (!is_int($size) || !is_int($mtime) || !$this->reset_media_replacement_theme_css_file_flags($path, 'verify')) {
            return array('success' => false, 'message' => __('Theme CSS verification could not initialize its resumable state.', 'ultracache'));
        }
        $inventory['file_size'] = $size;
        $inventory['file_mtime'] = $mtime;
        return array('success' => true, 'state' => $this->initialize_media_replacement_theme_css_read_state('verify', $inventory));
    }

    private function inspect_media_replacement_theme_css_verify_segment($path, $segment)
    {
        $rows = $this->get_media_replacement_theme_css_file_rows($path, array('applied', 'verify_failed'));
        foreach ($rows as $row) {
            $row_id = absint($row['id'] ?? 0);
            if ($row_id <= 0) {
                continue;
            }
            if (false !== strpos((string) $segment, (string) ($row['old_fragment'] ?? '')) && empty($row['verify_old_found'])) {
                $this->mark_media_replacement_theme_css_ref_flag($row_id, 'verify_old_found');
            }
            if (false !== strpos((string) $segment, (string) ($row['new_fragment'] ?? '')) && empty($row['verify_new_found'])) {
                $this->mark_media_replacement_theme_css_ref_flag($row_id, 'verify_new_found');
            }
        }
    }

    private function complete_media_replacement_theme_css_verify_file(array $state)
    {
        global $wpdb;
        $rows = $this->get_media_replacement_theme_css_file_rows($state['file_path'], array('applied', 'verify_failed'));
        $inventory = $this->get_media_replacement_theme_css_inventory_row_by_id($state['file_id']);
        $expected_checksum = (string) ($inventory['checksum_after'] ?? '');
        $valid = $this->get_media_replacement_theme_css_checksum_scheme() === sanitize_key((string) ($inventory['checksum_scheme'] ?? ''))
            && preg_match('/^[a-f0-9]{32}$/', $expected_checksum)
            && hash_equals($expected_checksum, $state['checksum_after']);
        foreach ($rows as $row) {
            if (!empty($row['verify_old_found']) || empty($row['verify_new_found'])) {
                $valid = false;
                break;
            }
        }
        $table = $this->get_media_replacement_file_refs_table_name();
        $updated = $wpdb instanceof wpdb && false !== $wpdb->update(
            $table,
            array(
                'status' => $valid ? 'verified' : 'verify_failed',
                'checksum_after' => $state['checksum_after'],
                'error_message' => $valid ? null : __('Theme CSS verification found a checksum mismatch, an old fragment, or a missing new fragment.', 'ultracache'),
                'updated_at' => current_time('mysql', true),
            ),
            array('file_path' => $state['file_path']),
            array('%s', '%s', '%s', '%s'),
            array('%s')
        );
        if (!$updated) {
            return array('success' => false, 'message' => __('Theme CSS verification results could not be persisted.', 'ultracache'));
        }
        return array('success' => true, 'complete' => true, 'valid' => $valid, 'count' => count($rows));
    }

    private function process_media_replacement_theme_css_verify_stream(array $state)
    {
        if (!$this->verify_media_replacement_theme_css_source_stat($state)) {
            return array('success' => false, 'message' => __('Theme CSS changed while verification was running.', 'ultracache'));
        }
        $read = $this->read_media_replacement_theme_css_stream_chunk($state['file_path'], $state['source_offset'], $this->get_media_replacement_theme_css_stream_chunk_size(), 'media_library_replacement_theme_css_verify_stream');
        if (!is_array($read)) {
            return array('success' => false, 'message' => __('Theme CSS file could not be read during verification.', 'ultracache'));
        }
        $chunk = (string) $read['data'];
        $eof = !empty($read['eof']);
        $state['checksum_after'] = $this->advance_media_replacement_theme_css_checksum($state['checksum_after'], $chunk);
        $state['source_offset'] += strlen($chunk);
        if ($eof && $state['source_offset'] > 0 && 0 === $state['source_offset'] % $this->get_media_replacement_theme_css_stream_chunk_size()) {
            $state['checksum_after'] = $this->advance_media_replacement_theme_css_checksum($state['checksum_after'], '');
        }
        $data = $this->decode_media_replacement_theme_css_stream_carry($state) . $chunk;
        list($process, $carry) = $this->split_media_replacement_theme_css_stream_data($data, $eof);
        if ('' !== $process) {
            $this->inspect_media_replacement_theme_css_verify_segment($state['file_path'], $process);
        }
        $state['carry_b64'] = '' !== $carry ? base64_encode($carry) : '';
        $state = $this->update_media_replacement_theme_css_stream_state($state);
        if (!$eof) {
            return array('success' => true, 'complete' => false);
        }
        return $this->complete_media_replacement_theme_css_verify_file($state);
    }

    public function verify_media_library_replacement_theme_css_replacements($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array('success' => false, 'message' => __('Media Library replacement registry tables are not available.', 'ultracache'));
        }
        $args = is_array($args) ? $args : array();
        $limit = max(1, min(100, absint($args['limit'] ?? 50)));
        $deadline = microtime(true) + max(1.0, min(30.0, isset($args['time_budget']) ? (float) $args['time_budget'] : 15.0));
        $processed_files = 0;
        $verified_refs = 0;
        $failed_refs = 0;

        while ($processed_files < $limit && microtime(true) < $deadline) {
            $stream = $this->get_media_replacement_theme_css_stream_state();
            if ('verify' !== $stream['mode']) {
                $path = $this->get_media_replacement_theme_css_next_file(array('applied', 'verify_failed'));
                if ('' === $path) {
                    break;
                }
                $initialized = $this->initialize_media_replacement_theme_css_verify_state($path);
                if (empty($initialized['success'])) {
                    return array('success' => false, 'message' => (string) ($initialized['message'] ?? __('Theme CSS verification could not start.', 'ultracache')));
                }
                $stream = $initialized['state'];
            }
            $result = $this->process_media_replacement_theme_css_verify_stream($stream);
            if (empty($result['success'])) {
                return array('success' => false, 'message' => (string) ($result['message'] ?? __('Theme CSS verification failed.', 'ultracache')));
            }
            if (empty($result['complete'])) {
                continue;
            }
            if (!empty($result['valid'])) {
                $verified_refs += (int) $result['count'];
            } else {
                $failed_refs += (int) $result['count'];
            }
            $processed_files++;
            $this->clear_media_replacement_theme_css_stream_state(false);
        }

        $summary = $this->get_media_replacement_theme_css_summary();
        $remaining = (int) $summary['applied'] + (int) $summary['verifyFailed'];
        $active = $this->get_media_replacement_theme_css_stream_state();
        $has_more = $remaining > 0 || ('verify' === $active['mode']);
        $total = (int) $summary['total'];
        if ($has_more) {
            /* translators: 1: verified Theme CSS replacement count; 2: replacement count still pending verification. */
            $message = sprintf(__('Theme CSS replacement verification is in progress: %1$d verified, %2$d still pending.', 'ultracache'), (int) $summary['verified'], $remaining);
        } else {
            /* translators: 1: verified Theme CSS replacement count; 2: failed verification count. */
            $message = sprintf(__('Theme CSS replacement verification complete. Verified: %1$d. Failed: %2$d.', 'ultracache'), (int) $summary['verified'], (int) $summary['verifyFailed']);
        }
        return array(
            'success' => true,
            'message' => $message,
            'status' => $has_more ? 'theme_css_verifying' : 'theme_css_verified',
            'hasMore' => $has_more,
            'batchProcessedThemeCssFiles' => $processed_files,
            'batchProcessedThemeCssRefs' => $verified_refs + $failed_refs,
            'batchVerifiedThemeCssRefs' => $verified_refs,
            'batchVerifyFailedThemeCssRefs' => $failed_refs,
            'themeCssRefs' => $total,
            'themeCssPendingRefs' => (int) $summary['pending'],
            'themeCssAppliedRefs' => (int) $summary['applied'],
            'themeCssVerifiedRefs' => (int) $summary['verified'],
            'themeCssFailedRefs' => (int) $summary['failed'],
            'themeCssVerifyFailedRefs' => (int) $summary['verifyFailed'],
            'themeCssFilesWithRefs' => (int) $summary['files'],
            'progressPercent' => $total > 0 ? min(100, round(((int) $summary['verified'] / $total) * 100, 1)) : 100,
            'nextStep' => $has_more ? __('Continue the current Theme CSS verification file from its saved byte offset.', 'ultracache') : __('Theme CSS replacements are verified. Next step: Cleanup Preview.', 'ultracache'),
        );
    }
}
