<?php
/**
 * UltraCache Media Library replacement Theme CSS reference scanning, preview, apply, and verification.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Replacement_Theme_CSS_Trait
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache uses private custom Media Library replacement registry tables with validated table identifiers.

    private function normalize_media_replacement_theme_css_roots($roots)
    {
        $normalized = array();
        foreach ((array) $roots as $root) {
            if (!is_array($root)) {
                continue;
            }
            $path = isset($root['path']) ? wp_normalize_path((string) $root['path']) : '';
            $label = isset($root['label']) ? sanitize_file_name((string) $root['label']) : '';
            if ('' === $path || '' === $label) {
                continue;
            }
            $normalized[] = array(
                'path'  => untrailingslashit($path),
                'label' => $label,
            );
        }
        return $normalized;
    }

    private function normalize_media_replacement_theme_css_traversal_stack($stack)
    {
        $normalized = array();
        foreach ((array) $stack as $frame) {
            if (!is_array($frame)) {
                continue;
            }
            $path = isset($frame['path']) ? wp_normalize_path((string) $frame['path']) : '';
            $root = isset($frame['root']) ? wp_normalize_path((string) $frame['root']) : '';
            $label = isset($frame['label']) ? sanitize_file_name((string) $frame['label']) : '';
            if ('' === $path || '' === $root || '' === $label) {
                continue;
            }
            $normalized[] = array(
                'path'     => untrailingslashit($path),
                'root'     => untrailingslashit($root),
                'label'    => $label,
                'position' => isset($frame['position']) ? max(0, absint($frame['position'])) : 0,
            );
        }
        return $normalized;
    }

    private function normalize_media_replacement_theme_css_scan_state($state)
    {
        $state = is_array($state) ? $state : array();
        $defaults = array(
            'state_version'       => 2,
            'status'              => 'idle',
            'phase'               => 'idle',
            'roots'               => array(),
            'roots_hash'          => '',
            'root_index'          => 0,
            'traversal_stack'     => array(),
            'cursor_file_id'      => 0,
            'cursor_file_index'   => 0,
            'total_files'         => 0,
            'discovered_files'    => 0,
            'scanned_files'       => 0,
            'validated_files'     => 0,
            'matched_refs'        => 0,
            'matched_files'       => 0,
            'created_at'          => '',
            'updated_at'          => '',
            'completed_at'        => '',
        );

        if (2 !== absint($state['state_version'] ?? 0)) {
            return $defaults;
        }

        $status = isset($state['status']) ? sanitize_key((string) $state['status']) : 'idle';
        if (!in_array($status, array('idle', 'scanning', 'completed', 'failed'), true)) {
            $status = 'idle';
        }
        $phase = isset($state['phase']) ? sanitize_key((string) $state['phase']) : 'idle';
        if (!in_array($phase, array('idle', 'discover', 'scan', 'validate_file_set', 'complete'), true)) {
            $phase = 'idle';
        }
        $roots = $this->normalize_media_replacement_theme_css_roots($state['roots'] ?? array());
        $roots_hash = isset($state['roots_hash']) && preg_match('/^[a-f0-9]{32}$/', (string) $state['roots_hash']) ? (string) $state['roots_hash'] : '';

        return array(
            'state_version'       => 2,
            'status'              => $status,
            'phase'               => $phase,
            'roots'               => $roots,
            'roots_hash'          => $roots_hash,
            'root_index'          => isset($state['root_index']) ? max(0, absint($state['root_index'])) : 0,
            'traversal_stack'     => $this->normalize_media_replacement_theme_css_traversal_stack($state['traversal_stack'] ?? array()),
            'cursor_file_id'      => isset($state['cursor_file_id']) ? max(0, absint($state['cursor_file_id'])) : 0,
            'cursor_file_index'   => isset($state['scanned_files']) ? max(0, absint($state['scanned_files'])) : 0,
            'total_files'         => isset($state['total_files']) ? max(0, absint($state['total_files'])) : 0,
            'discovered_files'    => isset($state['discovered_files']) ? max(0, absint($state['discovered_files'])) : 0,
            'scanned_files'       => isset($state['scanned_files']) ? max(0, absint($state['scanned_files'])) : 0,
            'validated_files'     => isset($state['validated_files']) ? max(0, absint($state['validated_files'])) : 0,
            'matched_refs'        => isset($state['matched_refs']) ? max(0, absint($state['matched_refs'])) : 0,
            'matched_files'       => isset($state['matched_files']) ? max(0, absint($state['matched_files'])) : 0,
            'created_at'          => isset($state['created_at']) ? sanitize_text_field((string) $state['created_at']) : '',
            'updated_at'          => isset($state['updated_at']) ? sanitize_text_field((string) $state['updated_at']) : '',
            'completed_at'        => isset($state['completed_at']) ? sanitize_text_field((string) $state['completed_at']) : '',
        );
    }

    private function get_media_replacement_theme_css_scan_state()
    {
        return $this->normalize_media_replacement_theme_css_scan_state($this->get_media_replacement_workflow_section('theme_css_scan'));
    }

    private function update_media_replacement_theme_css_scan_state(array $state)
    {
        $state['updated_at'] = current_time('mysql', true);
        $state = $this->normalize_media_replacement_theme_css_scan_state($state);
        $this->update_media_replacement_workflow_section('theme_css_scan', $state);
        return $state;
    }

    private function get_media_replacement_theme_css_roots()
    {
        $paths = array();
        if (function_exists('get_stylesheet_directory')) {
            $paths[] = get_stylesheet_directory();
        }
        if (function_exists('get_template_directory')) {
            $paths[] = get_template_directory();
        }

        $roots = array();
        foreach ($paths as $path) {
            $path = untrailingslashit(wp_normalize_path((string) $path));
            if ('' === $path || !is_dir($path) || isset($roots[$path])) {
                continue;
            }
            $roots[$path] = array(
                'path'  => $path,
                'label' => sanitize_file_name(basename($path)),
            );
        }
        return array_values($roots);
    }

    private function get_media_replacement_theme_css_roots_hash(array $roots)
    {
        $facts = array();
        foreach ($this->normalize_media_replacement_theme_css_roots($roots) as $root) {
            $facts[] = $root['path'] . '|' . $root['label'];
        }
        return md5((string) wp_json_encode($facts));
    }

    private function reset_media_replacement_theme_css_inventory()
    {
        global $wpdb;
        $table = $this->get_media_replacement_theme_css_files_table_name();
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return false;
        }
        return false !== $wpdb->query($wpdb->prepare('DELETE FROM %i', $table));
    }

    private function reset_media_replacement_theme_css_refs()
    {
        global $wpdb;
        $table = $this->get_media_replacement_file_refs_table_name();
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return false;
        }
        if (false === $wpdb->query($wpdb->prepare('DELETE FROM %i', $table))) {
            return false;
        }
        return $this->reset_media_replacement_theme_css_inventory();
    }

    private function initialize_media_replacement_theme_css_traversal(array $state, array $roots)
    {
        $roots = $this->normalize_media_replacement_theme_css_roots($roots);
        $state['roots'] = $roots;
        $state['roots_hash'] = $this->get_media_replacement_theme_css_roots_hash($roots);
        $state['root_index'] = 0;
        $state['traversal_stack'] = array();
        return $state;
    }

    private function get_next_media_replacement_theme_css_traversal_entry(array &$state, &$error_message)
    {
        $error_message = '';
        $excluded_directories = array('.git', 'node_modules', 'vendor', '.cache', 'cache');

        while (true) {
            if (empty($state['traversal_stack'])) {
                if ($state['root_index'] >= count($state['roots'])) {
                    return array('done' => true, 'worked' => false, 'file' => array());
                }
                $root = $state['roots'][$state['root_index']];
                $state['root_index']++;
                $state['traversal_stack'][] = array(
                    'path'     => $root['path'],
                    'root'     => $root['path'],
                    'label'    => $root['label'],
                    'position' => 0,
                );
            }

            $frame_index = count($state['traversal_stack']) - 1;
            $frame = $state['traversal_stack'][$frame_index];
            try {
                $iterator = new FilesystemIterator($frame['path'], FilesystemIterator::SKIP_DOTS);
                try {
                    $iterator->seek((int) $frame['position']);
                } catch (OutOfBoundsException $exception) {
                    array_pop($state['traversal_stack']);
                    continue;
                }
                if (!$iterator->valid()) {
                    array_pop($state['traversal_stack']);
                    continue;
                }
                $file_info = $iterator->current();
                $state['traversal_stack'][$frame_index]['position'] = (int) $frame['position'] + 1;
            } catch (UnexpectedValueException $exception) {
                $error_message = sprintf(
                    /* translators: %s: unreadable theme directory path. */
                    __('Theme CSS discovery could not read directory: %s', 'ultracache'),
                    $frame['path']
                );
                return array('done' => false, 'worked' => false, 'file' => array());
            }

            if (!($file_info instanceof SplFileInfo)) {
                return array('done' => false, 'worked' => true, 'file' => array());
            }

            $name = $file_info->getFilename();
            if ($file_info->isDir()) {
                if ($file_info->isLink() || in_array($name, $excluded_directories, true)) {
                    return array('done' => false, 'worked' => true, 'file' => array());
                }
                $child_path = untrailingslashit(wp_normalize_path($file_info->getPathname()));
                $state['traversal_stack'][] = array(
                    'path'     => $child_path,
                    'root'     => $frame['root'],
                    'label'    => $frame['label'],
                    'position' => 0,
                );
                return array('done' => false, 'worked' => true, 'file' => array());
            }

            if (!$file_info->isFile()) {
                return array('done' => false, 'worked' => true, 'file' => array());
            }

            $path = wp_normalize_path($file_info->getPathname());
            if (!preg_match('/\.css$/i', $path)) {
                return array('done' => false, 'worked' => true, 'file' => array());
            }

            $size = (int) $file_info->getSize();
            if ($size < 0) {
                return array('done' => false, 'worked' => true, 'file' => array());
            }

            return array(
                'done'   => false,
                'worked' => true,
                'file'   => array(
                    'path'     => $path,
                    'relative' => ltrim(str_replace($frame['root'], $frame['label'], $path), '/'),
                    'size'     => $size,
                    'mtime'    => (int) $file_info->getMTime(),
                ),
            );
        }
    }

    private function insert_media_replacement_theme_css_inventory_file(array $file)
    {
        global $wpdb;
        $table = $this->get_media_replacement_theme_css_files_table_name();
        $path = isset($file['path']) ? wp_normalize_path((string) $file['path']) : '';
        $relative = isset($file['relative']) ? ltrim(str_replace('\\', '/', (string) $file['relative']), '/') : '';
        $size = isset($file['size']) ? (int) $file['size'] : -1;
        $mtime = isset($file['mtime']) ? (int) $file['mtime'] : -1;
        if ('' === $table || '' === $path || '' === $relative || $size < 0 || $mtime < 0 || !($wpdb instanceof wpdb)) {
            return false;
        }

        $now = current_time('mysql', true);
        $result = $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO %i (job_id, path_hash, file_path, relative_file_path, file_size, file_mtime, scan_status, validation_status, created_at, updated_at) VALUES (%s, %s, %s, %s, %d, %d, %s, %s, %s, %s)',
            $table,
            '',
            md5($path),
            $path,
            $relative,
            $size,
            $mtime,
            'pending',
            'pending',
            $now,
            $now
        ));
        if (false === $result) {
            return false;
        }
        return 1 === (int) $result ? 'inserted' : 'existing';
    }

    private function get_media_replacement_theme_css_inventory_count()
    {
        global $wpdb;
        $table = $this->get_media_replacement_theme_css_files_table_name();
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return 0;
        }
        return max(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table)));
    }

    private function get_media_replacement_theme_css_inventory_rows($after_id, $limit)
    {
        global $wpdb;
        $table = $this->get_media_replacement_theme_css_files_table_name();
        $after_id = max(0, absint($after_id));
        $limit = max(1, min(100, absint($limit)));
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, path_hash, file_path, relative_file_path, file_size, file_mtime, checksum_before, scan_status, validation_status FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
            $table,
            $after_id,
            $limit
        ), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    private function reset_media_replacement_theme_css_inventory_validation()
    {
        global $wpdb;
        $table = $this->get_media_replacement_theme_css_files_table_name();
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return false;
        }
        return false !== $wpdb->query($wpdb->prepare(
            'UPDATE %i SET validation_status = %s, updated_at = %s',
            $table,
            'pending',
            current_time('mysql', true)
        ));
    }

    private function get_media_replacement_theme_css_unseen_inventory_count()
    {
        global $wpdb;
        $table = $this->get_media_replacement_theme_css_files_table_name();
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return 0;
        }
        return max(0, (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE validation_status <> %s',
            $table,
            'validated'
        )));
    }

    private function find_media_replacement_item_for_reference_match($match)
    {
        global $wpdb;
        $items_table = $this->get_media_replacement_items_table_name();
        $match = ltrim(str_replace('\\', '/', (string) $match), '/');
        if ('' === $items_table || '' === $match || !($wpdb instanceof wpdb)) {
            return array();
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id AS item_id, attachment_id, old_relative_path, old_url, new_relative_path, new_url FROM %i WHERE old_path_hash = %s AND status IN (%s, %s, %s, %s, %s) ORDER BY id ASC LIMIT 1',
                $items_table,
                md5($match),
                'metadata_ready',
                'metadata_updated',
                'refs_scanned',
                'cleanup_deleted',
                'cleanup_failed'
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : array();
    }

    private function insert_media_replacement_theme_css_ref_row(array $item_row, $file_path, $relative_file_path, $old_fragment, $new_fragment, $checksum_before)
    {
        global $wpdb;
        $table = $this->get_media_replacement_file_refs_table_name();
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return false;
        }

        $item_id = absint($item_row['item_id'] ?? 0);
        $file_path = wp_normalize_path((string) $file_path);
        $relative_file_path = ltrim(str_replace('\\', '/', (string) $relative_file_path), '/');
        $old_fragment = (string) $old_fragment;
        $new_fragment = (string) $new_fragment;
        $checksum_before = preg_match('/^[a-f0-9]{32}$/', (string) $checksum_before) ? (string) $checksum_before : '';
        if ($item_id <= 0 || '' === $file_path || '' === $relative_file_path || '' === $old_fragment || '' === $new_fragment || '' === $checksum_before) {
            return false;
        }

        $ref_hash = md5($file_path . '|' . md5($old_fragment) . '|' . md5($new_fragment));
        $now = current_time('mysql', true);
        $result = $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO %i (job_id, item_id, ref_hash, file_path, relative_file_path, old_fragment, new_fragment, backup_file_path, checksum_before, checksum_after, status, error_message, created_at, updated_at) VALUES (%s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, NULL, %s, %s)',
            $table,
            '',
            $item_id,
            $ref_hash,
            $file_path,
            $relative_file_path,
            $old_fragment,
            $new_fragment,
            '',
            $checksum_before,
            '',
            'pending',
            $now,
            $now
        ));
        if (false === $result) {
            return false;
        }
        return 1 === (int) $result ? 'inserted' : 'existing';
    }

    private function get_media_replacement_theme_css_summary()
    {
        global $wpdb;
        $table = $this->get_media_replacement_file_refs_table_name();
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return array();
        }
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS total_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS pending_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS applied_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS verified_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS failed_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS verify_failed_refs, COUNT(DISTINCT file_path) AS file_count FROM %i',
                'pending',
                'applied',
                'verified',
                'failed',
                'verify_failed',
                $table
            ),
            ARRAY_A
        );
        $row = is_array($row) ? $row : array();
        return array(
            'total'        => max(0, (int) ($row['total_refs'] ?? 0)),
            'pending'      => max(0, (int) ($row['pending_refs'] ?? 0)),
            'applied'      => max(0, (int) ($row['applied_refs'] ?? 0)),
            'verified'     => max(0, (int) ($row['verified_refs'] ?? 0)),
            'failed'       => max(0, (int) ($row['failed_refs'] ?? 0)),
            'verifyFailed' => max(0, (int) ($row['verify_failed_refs'] ?? 0)),
            'files'        => max(0, (int) ($row['file_count'] ?? 0)),
        );
    }

    private function retry_media_replacement_failed_theme_css_references()
    {
        global $wpdb;
        $table = $this->get_media_replacement_file_refs_table_name();
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return 0;
        }
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET status = %s, error_message = NULL, updated_at = %s WHERE status = %s',
                $table,
                'pending',
                current_time('mysql', true),
                'failed'
            )
        );
        return false === $updated ? 0 : max(0, (int) $updated);
    }

    private function fail_media_replacement_theme_css_scan(array $state, $status, $message)
    {
        $state['status'] = 'failed';
        $state['updated_at'] = current_time('mysql', true);
        $state = $this->update_media_replacement_theme_css_scan_state($state);
        return array(
            'success'    => false,
            'blocked'    => true,
            'status'     => sanitize_key((string) $status),
            'message'    => wp_strip_all_tags((string) $message),
            'phase'      => $state['phase'],
            'hasMore'    => false,
            'themeCssFilesDiscovered' => (int) $state['discovered_files'],
            'themeCssFilesScanned'    => (int) $state['scanned_files'],
            'themeCssFilesValidated'  => (int) $state['validated_files'],
            'themeCssFilesTotal'      => (int) $state['total_files'],
        );
    }

    private function build_media_replacement_theme_css_scan_response(array $state, array $summary, array $batch, $has_more, $message, $next_step)
    {
        $phase = (string) $state['phase'];
        $response = array(
            'success' => true,
            'message' => (string) $message,
            'status' => $has_more ? 'theme_css_scanning' : 'theme_css_scanned',
            'phase' => $phase,
            'hasMore' => (bool) $has_more,
            'batchDiscoveredFiles' => max(0, (int) ($batch['discovered'] ?? 0)),
            'batchScannedFiles' => max(0, (int) ($batch['scanned'] ?? 0)),
            'batchValidatedFiles' => max(0, (int) ($batch['validated'] ?? 0)),
            'batchMatchedThemeCssRefs' => max(0, (int) ($batch['refs'] ?? 0)),
            'themeCssFilesDiscovered' => (int) $state['discovered_files'],
            'themeCssFilesScanned' => (int) $state['scanned_files'],
            'themeCssFilesValidated' => (int) $state['validated_files'],
            'themeCssFilesTotal' => (int) $state['total_files'],
            'themeCssRefs' => (int) ($summary['total'] ?? 0),
            'themeCssPendingRefs' => (int) ($summary['pending'] ?? 0),
            'themeCssAppliedRefs' => (int) ($summary['applied'] ?? 0),
            'themeCssVerifiedRefs' => (int) ($summary['verified'] ?? 0),
            'themeCssFailedRefs' => (int) ($summary['failed'] ?? 0),
            'themeCssFilesWithRefs' => (int) ($summary['files'] ?? 0),
            'nextStep' => (string) $next_step,
        );

        if ('scan' === $phase && $state['total_files'] > 0) {
            $response['progressPercent'] = min(100, round(($state['scanned_files'] / $state['total_files']) * 100, 1));
        } elseif ('validate_file_set' === $phase && $state['total_files'] > 0) {
            $response['progressPercent'] = min(100, round(($state['validated_files'] / $state['total_files']) * 100, 1));
        } elseif ('complete' === $phase) {
            $response['progressPercent'] = 100;
        }

        return $response;
    }

    private function get_media_replacement_theme_css_preview_rows($limit = 50, $offset = 0)
    {
        global $wpdb;
        $table = $this->get_media_replacement_file_refs_table_name();
        $limit = max(1, min(250, absint($limit)));
        $offset = max(0, absint($offset));
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return array();
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT id, item_id, relative_file_path, old_fragment, new_fragment, status, error_message FROM %i ORDER BY id ASC LIMIT %d OFFSET %d', $table, $limit, $offset),
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }

    public function get_media_library_replacement_theme_css_replacement_preview($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array('success' => false, 'message' => __('Media Library replacement registry tables are not available.', 'ultracache'));
        }

        $args = is_array($args) ? $args : array();
        $issue_confirmation_token = !array_key_exists('issue_confirmation_token', $args) || !empty($args['issue_confirmation_token']);
        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        $offset = isset($args['offset']) ? absint($args['offset']) : 0;

        $summary = $this->get_media_replacement_theme_css_summary();
        $total = (int) $summary['total'];
        $items = $total > 0 ? $this->get_media_replacement_theme_css_preview_rows($limit, $offset) : array();

        $response = array(
            'success' => true,
            'message' => $total > 0
                /* translators: %1$d: planned replacement count; %2$d: affected file count. */
                ? sprintf(__('Theme CSS replacement preview is ready with %1$d planned replacements across %2$d files. No theme files were changed by this preview.', 'ultracache'), $total, (int) $summary['files'])
                : __('Theme CSS replacement preview found no JPG/PNG references matched to the current Media Library replacement workflow.', 'ultracache'),
            'status' => 'theme_css_preview',
            'hasMore' => false,
            'themeCssRefs' => $total,
            'themeCssPendingRefs' => (int) $summary['pending'],
            'themeCssAppliedRefs' => (int) $summary['applied'],
            'themeCssVerifiedRefs' => (int) $summary['verified'],
            'themeCssFailedRefs' => (int) $summary['failed'],
            'themeCssVerifyFailedRefs' => (int) $summary['verifyFailed'],
            'themeCssFilesWithRefs' => (int) $summary['files'],
            'items' => $items,
            'nextStep' => $total > 0 && (int) $summary['pending'] > 0
                ? __('Next step: Apply Theme CSS Replacements. Backups are written before CSS files are changed.', 'ultracache')
                : __('No theme CSS replacements are pending.', 'ultracache'),
        );

        return $this->add_media_replacement_confirmation_token_to_response($response, 'theme_css_apply', $issue_confirmation_token && $total > 0 && (int) $summary['pending'] > 0);
    }

    private function build_media_replacement_theme_css_backup_path($file_path)
    {
        $uploads = function_exists('ultracache_uploads_base_info') ? ultracache_uploads_base_info() : wp_upload_dir(null, false);
        $file_path = wp_normalize_path((string) $file_path);
        $relative = md5($file_path) . '-' . sanitize_file_name(basename($file_path)) . '.bak';
        if (empty($uploads['basedir']) || '' === $file_path || '' === $relative) {
            return '';
        }
        return ultracache_storage_join_path((string) $uploads['basedir'], 'ultracache/theme-css-backups/current/' . $relative);
    }

}
