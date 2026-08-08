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

    private function clear_media_replacement_theme_css_manifest()
    {
        $this->clear_media_replacement_workflow_section('theme_css_manifest');
    }

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

    private function update_media_replacement_theme_css_inventory_scan_result($row_id, $status, $checksum, $error_message = '')
    {
        global $wpdb;
        $table = $this->get_media_replacement_theme_css_files_table_name();
        $row_id = absint($row_id);
        $status = sanitize_key((string) $status);
        $checksum = preg_match('/^[a-f0-9]{32}$/', (string) $checksum) ? (string) $checksum : '';
        if ('' === $table || $row_id <= 0 || !($wpdb instanceof wpdb)) {
            return false;
        }
        return false !== $wpdb->update(
            $table,
            array(
                'scan_status'    => $status,
                'checksum_before'=> $checksum,
                'error_message'  => wp_strip_all_tags((string) $error_message),
                'updated_at'     => current_time('mysql', true),
            ),
            array('id' => $row_id),
            array('%s', '%s', '%s', '%s'),
            array('%d')
        );
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

    private function validate_media_replacement_theme_css_inventory_file(array $file)
    {
        global $wpdb;
        $table = $this->get_media_replacement_theme_css_files_table_name();
        $path = isset($file['path']) ? wp_normalize_path((string) $file['path']) : '';
        if ('' === $table || '' === $path || !($wpdb instanceof wpdb)) {
            return array('valid' => false, 'counted' => false, 'message' => __('Theme CSS validation input is invalid.', 'ultracache'));
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, file_size, file_mtime, checksum_before, validation_status FROM %i WHERE path_hash = %s LIMIT 1',
            $table,
            md5($path)
        ), ARRAY_A);
        if (!is_array($row)) {
            return array('valid' => false, 'counted' => false, 'message' => __('A new Theme CSS file appeared during Prepare.', 'ultracache'));
        }
        if ('validated' === (string) ($row['validation_status'] ?? '')) {
            return array('valid' => true, 'counted' => false, 'message' => '');
        }

        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : false;
        $mtime = is_file($path) ? filemtime($path) : false;
        $content = is_file($path) && is_readable($path) && function_exists('ultracache_safe_file_get_contents')
            ? ultracache_safe_file_get_contents($path, 'media_library_replacement_theme_css_validate')
            : false;
        if (!is_int($size) || !is_int($mtime) || !is_string($content)
            || (int) $row['file_size'] !== $size
            || (int) $row['file_mtime'] !== $mtime
            || !hash_equals((string) $row['checksum_before'], md5($content))
        ) {
            return array('valid' => false, 'counted' => false, 'message' => __('A Theme CSS file changed during Prepare.', 'ultracache'));
        }

        $updated = $wpdb->update(
            $table,
            array('validation_status' => 'validated', 'updated_at' => current_time('mysql', true)),
            array('id' => absint($row['id'])),
            array('%s', '%s'),
            array('%d')
        );
        return array('valid' => false !== $updated, 'counted' => false !== $updated, 'message' => false === $updated ? __('Theme CSS validation progress could not be persisted.', 'ultracache') : '');
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

    public function scan_media_library_replacement_theme_css_references($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array('success' => false, 'message' => __('Media Library replacement registry tables are not available.', 'ultracache'));
        }

        $args = is_array($args) ? $args : array();
        if (!$this->media_replacement_has_registry_rows()) {
            return $this->build_media_replacement_empty_registry_response(__('No Media Library replacement registry rows are available for theme CSS scanning.', 'ultracache'));
        }

        $limit = isset($args['limit']) ? absint($args['limit']) : 20;
        $limit = max(1, min(100, $limit));
        $entry_limit = max(25, min(2500, $limit * 25));
        $time_budget = isset($args['time_budget']) && (float) $args['time_budget'] > 0 ? (float) $args['time_budget'] : 15.0;
        $time_budget = max(1.0, min(30.0, $time_budget));
        $deadline = microtime(true) + $time_budget;
        $state = $this->get_media_replacement_theme_css_scan_state();
        $start_new = 'idle' === $state['phase'] || !empty($args['reset']) || !empty($args['start']);

        if ($start_new) {
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
            return $this->build_media_replacement_theme_css_scan_response(
                $state,
                $summary,
                array(),
                false,
                sprintf(
                    /* translators: %1$d: planned replacement count; %2$d: affected file count. */
                    __('Theme CSS reference scan is already complete with %1$d planned replacements across %2$d files.', 'ultracache'),
                    (int) $summary['total'],
                    (int) $summary['files']
                ),
                __('Continue Prepare with the Theme CSS replacement preview.', 'ultracache')
            );
        }
        if ('failed' === $state['status']) {
            return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_scan_failed', __('The Theme CSS scan is in a failed state. Restart Prepare to rebuild it.', 'ultracache'));
        }

        $batch = array('discovered' => 0, 'scanned' => 0, 'validated' => 0, 'refs' => 0);

        if ('discover' === $state['phase']) {
            $work_units = 0;
            $discovery_complete = false;
            while ($work_units < $entry_limit && ($work_units === 0 || microtime(true) < $deadline)) {
                $error_message = '';
                $entry = $this->get_next_media_replacement_theme_css_traversal_entry($state, $error_message);
                if ('' !== $error_message) {
                    return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_discovery_failed', $error_message);
                }
                if (!empty($entry['done'])) {
                    $discovery_complete = true;
                    break;
                }
                if (!empty($entry['worked'])) {
                    $work_units++;
                }
                if (!empty($entry['file'])) {
                    $insert_result = $this->insert_media_replacement_theme_css_inventory_file($entry['file']);
                    if (false === $insert_result) {
                        return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_inventory_write_failed', __('Theme CSS discovery could not persist its file inventory.', 'ultracache'));
                    }
                    if ('inserted' === $insert_result) {
                        $state['discovered_files']++;
                        $batch['discovered']++;
                    }
                }
            }

            if ($discovery_complete) {
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
            $summary = array('total' => 0, 'pending' => 0, 'applied' => 0, 'verified' => 0, 'failed' => 0, 'files' => 0);
            return $this->build_media_replacement_theme_css_scan_response(
                $state,
                $summary,
                $batch,
                true,
                $discovery_complete
                    ? sprintf(
                        /* translators: %d: discovered CSS file count. */
                        __('Theme CSS discovery completed with %d files. The resumable reference scan is ready to continue.', 'ultracache'),
                        (int) $state['total_files']
                    )
                    : sprintf(
                        /* translators: %d: CSS file count discovered so far. */
                        __('Theme CSS discovery is continuing. %d CSS files have been found so far.', 'ultracache'),
                        (int) $state['discovered_files']
                    ),
                $discovery_complete
                    ? __('Continue with the persisted Theme CSS inventory scan. No files have been changed.', 'ultracache')
                    : __('Continue discovering Theme CSS files from the saved directory cursor.', 'ultracache')
            );
        }

        if ('scan' === $state['phase']) {
            $rows = $this->get_media_replacement_theme_css_inventory_rows($state['cursor_file_id'], $limit);
            foreach ($rows as $row) {
                if ($batch['scanned'] > 0 && microtime(true) >= $deadline) {
                    break;
                }
                $row_id = absint($row['id'] ?? 0);
                $path = isset($row['file_path']) ? wp_normalize_path((string) $row['file_path']) : '';
                $relative = isset($row['relative_file_path']) ? (string) $row['relative_file_path'] : '';
                clearstatcache(true, $path);
                $current_size = is_file($path) ? filesize($path) : false;
                $current_mtime = is_file($path) ? filemtime($path) : false;
                if ($row_id <= 0 || !is_int($current_size) || !is_int($current_mtime) || (int) $row['file_size'] !== $current_size || (int) $row['file_mtime'] !== $current_mtime) {
                    $this->update_media_replacement_theme_css_inventory_scan_result($row_id, 'failed', '', __('The Theme CSS file changed before it could be scanned.', 'ultracache'));
                    return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_file_set_changed', __('A Theme CSS file changed during Prepare. Restart Prepare to rebuild a consistent plan.', 'ultracache'));
                }

                $content = function_exists('ultracache_safe_file_get_contents') ? ultracache_safe_file_get_contents($path, 'media_library_replacement_theme_css_scan') : false;
                if (!is_string($content)) {
                    $this->update_media_replacement_theme_css_inventory_scan_result($row_id, 'failed', '', __('The Theme CSS file could not be read.', 'ultracache'));
                    return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_file_read_failed', __('A Theme CSS file could not be read. Restart Prepare after correcting the file permissions.', 'ultracache'));
                }
                clearstatcache(true, $path);
                $after_size = is_file($path) ? filesize($path) : false;
                $after_mtime = is_file($path) ? filemtime($path) : false;
                if (!is_int($after_size) || !is_int($after_mtime) || $current_size !== $after_size || $current_mtime !== $after_mtime) {
                    $this->update_media_replacement_theme_css_inventory_scan_result($row_id, 'failed', '', __('The Theme CSS file changed while it was being scanned.', 'ultracache'));
                    return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_file_set_changed', __('A Theme CSS file changed during Prepare. Restart Prepare to rebuild a consistent plan.', 'ultracache'));
                }

                $checksum = md5($content);
                $file_refs = 0;
                if ('' !== $content) {
                    foreach ($this->extract_media_replacement_image_references_from_value($content) as $reference) {
                        $match = isset($reference['match']) ? (string) $reference['match'] : '';
                        $item = $this->find_media_replacement_item_for_reference_match($match);
                        if (empty($item)) {
                            continue;
                        }
                        $index_row = array('raw_fragment' => isset($reference['raw']) ? (string) $reference['raw'] : '');
                        $new_fragment = $this->build_media_replacement_new_fragment_for_index($index_row, $item);
                        if ('' !== $new_fragment && 'inserted' === $this->insert_media_replacement_theme_css_ref_row($item, $path, $relative, (string) $index_row['raw_fragment'], $new_fragment, $checksum)) {
                            $batch['refs']++;
                            $file_refs++;
                        }
                    }
                }

                if (!$this->update_media_replacement_theme_css_inventory_scan_result($row_id, 'scanned', $checksum)) {
                    return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_inventory_write_failed', __('Theme CSS scan progress could not be persisted.', 'ultracache'));
                }
                if ($file_refs > 0) {
                    $state['matched_files']++;
                }
                $state['matched_refs'] += $file_refs;
                $state['cursor_file_id'] = $row_id;
                $state['scanned_files']++;
                $batch['scanned']++;
            }

            $has_scan_rows = !empty($this->get_media_replacement_theme_css_inventory_rows($state['cursor_file_id'], 1));
            if (!$has_scan_rows) {
                $current_roots = $this->get_media_replacement_theme_css_roots();
                $current_roots_hash = $this->get_media_replacement_theme_css_roots_hash($current_roots);
                if ('' === $state['roots_hash'] || !hash_equals($state['roots_hash'], $current_roots_hash)) {
                    return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_file_set_changed', __('The active Theme CSS roots changed during Prepare. Restart Prepare to rebuild a consistent plan.', 'ultracache'));
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
            $summary = array(
                'total' => (int) $state['matched_refs'],
                'pending' => (int) $state['matched_refs'],
                'applied' => 0,
                'verified' => 0,
                'failed' => 0,
                'files' => (int) $state['matched_files'],
            );
            return $this->build_media_replacement_theme_css_scan_response(
                $state,
                $summary,
                $batch,
                true,
                $has_scan_rows
                    ? sprintf(
                        /* translators: %1$d: scanned file count; %2$d: total file count; %3$d: planned replacement count. */
                        __('Theme CSS scan: %1$d of %2$d files scanned, %3$d planned replacements found.', 'ultracache'),
                        (int) $state['scanned_files'],
                        (int) $state['total_files'],
                        (int) $state['matched_refs']
                    )
                    : __('Theme CSS content scan is complete. Prepare is validating the same file set through a second resumable traversal.', 'ultracache'),
                $has_scan_rows
                    ? __('Continue scanning the persisted Theme CSS inventory. No files have been changed.', 'ultracache')
                    : __('Continue validating the Theme CSS file set from the saved directory cursor.', 'ultracache')
            );
        }

        if ('validate_file_set' === $state['phase']) {
            $work_units = 0;
            $validation_complete = false;
            while ($work_units < $entry_limit && ($work_units === 0 || microtime(true) < $deadline)) {
                $error_message = '';
                $entry = $this->get_next_media_replacement_theme_css_traversal_entry($state, $error_message);
                if ('' !== $error_message) {
                    return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_validation_failed', $error_message);
                }
                if (!empty($entry['done'])) {
                    $validation_complete = true;
                    break;
                }
                if (!empty($entry['worked'])) {
                    $work_units++;
                }
                if (!empty($entry['file'])) {
                    $validation = $this->validate_media_replacement_theme_css_inventory_file($entry['file']);
                    if (empty($validation['valid'])) {
                        return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_file_set_changed', (string) ($validation['message'] ?? __('The Theme CSS file set changed during Prepare.', 'ultracache')));
                    }
                    if (!empty($validation['counted'])) {
                        $state['validated_files']++;
                        $batch['validated']++;
                    }
                }
            }

            if ($validation_complete) {
                $unseen = $this->get_media_replacement_theme_css_unseen_inventory_count();
                if ($unseen > 0) {
                    return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_file_set_changed', __('One or more Theme CSS files disappeared during Prepare. Restart Prepare to rebuild a consistent plan.', 'ultracache'));
                }
                $state['status'] = 'completed';
                $state['phase'] = 'complete';
                $state['validated_files'] = $state['total_files'];
                $state['completed_at'] = current_time('mysql', true);
                $state['root_index'] = count($state['roots']);
                $state['traversal_stack'] = array();
            } else {
                $state['status'] = 'scanning';
            }
            $state['updated_at'] = current_time('mysql', true);
            $state = $this->update_media_replacement_theme_css_scan_state($state);
            $summary = $validation_complete
                ? $this->get_media_replacement_theme_css_summary()
                : array(
                    'total' => (int) $state['matched_refs'],
                    'pending' => (int) $state['matched_refs'],
                    'applied' => 0,
                    'verified' => 0,
                    'failed' => 0,
                    'files' => (int) $state['matched_files'],
                );
            return $this->build_media_replacement_theme_css_scan_response(
                $state,
                $summary,
                $batch,
                !$validation_complete,
                $validation_complete
                    ? sprintf(
                        /* translators: %1$d: planned replacement count; %2$d: affected file count. */
                        __('Theme CSS scan and file-set validation completed with %1$d planned replacements across %2$d files.', 'ultracache'),
                        (int) $summary['total'],
                        (int) $summary['files']
                    )
                    : sprintf(
                        /* translators: %1$d: validated file count; %2$d: total file count. */
                        __('Theme CSS file-set validation: %1$d of %2$d files confirmed unchanged.', 'ultracache'),
                        (int) $state['validated_files'],
                        (int) $state['total_files']
                    ),
                $validation_complete
                    ? __('Continue Prepare with the Theme CSS replacement preview.', 'ultracache')
                    : __('Continue validating the Theme CSS file set from the saved directory cursor.', 'ultracache')
            );
        }

        return $this->fail_media_replacement_theme_css_scan($state, 'theme_css_scan_failed', __('The Theme CSS scan reached an unsupported phase. Restart Prepare.', 'ultracache'));
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

    private function get_media_replacement_theme_css_pending_rows($limit = 25, $statuses = array('pending'))
    {
        global $wpdb;
        $table = $this->get_media_replacement_file_refs_table_name();
        $limit = max(1, min(100, absint($limit)));
        $statuses = array_values(array_intersect(array_map('sanitize_key', (array) $statuses), array('pending', 'applied', 'verified', 'failed', 'verify_failed')));
        if ('' === $table || !($wpdb instanceof wpdb) || empty($statuses)) {
            return array();
        }

        if (1 === count($statuses)) {
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM %i WHERE status = %s ORDER BY id ASC LIMIT %d',
                $table,
                $statuses[0],
                $limit
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM %i WHERE (status = %s OR status = %s) ORDER BY id ASC LIMIT %d',
                $table,
                $statuses[0],
                $statuses[1],
                $limit
            ), ARRAY_A);
        }
        return is_array($rows) ? $rows : array();
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

    private function update_media_replacement_theme_css_ref_status($row_id, $status, $message = '', $extra = array())
    {
        global $wpdb;

        $table = $this->get_media_replacement_file_refs_table_name();
        $row_id = absint($row_id);
        if ('' === $table || $row_id <= 0 || !($wpdb instanceof wpdb)) {
            return false;
        }

        $data = array(
            'status' => sanitize_key((string) $status),
            'error_message' => '' !== (string) $message ? wp_strip_all_tags((string) $message) : null,
            'updated_at' => current_time('mysql', true),
        );
        $formats = array('%s', '%s', '%s');
        foreach (array('backup_file_path', 'checksum_before', 'checksum_after') as $field) {
            if (array_key_exists($field, $extra)) {
                $data[$field] = (string) $extra[$field];
                $formats[] = '%s';
            }
        }

        return false !== $wpdb->update($table, $data, array('id' => $row_id), $formats, array('%d'));
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
        $limit = isset($args['limit']) ? absint($args['limit']) : 25;
        $limit = max(1, min(100, $limit));
        $time_budget = isset($args['time_budget']) && (float) $args['time_budget'] > 0 ? (float) $args['time_budget'] : 15.0;
        $time_budget = max(1.0, min(30.0, $time_budget));
        $deadline = microtime(true) + $time_budget;
        $rows = $this->get_media_replacement_theme_css_pending_rows($limit, array('pending', 'failed'));

        $processed = 0;
        $applied = 0;
        $failed = 0;
        $registry_sync_failed = 0;
        foreach ((array) $rows as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $processed++;
            $row_id = isset($row['id']) ? absint($row['id']) : 0;
            $path = isset($row['file_path']) ? wp_normalize_path((string) $row['file_path']) : '';
            $old = isset($row['old_fragment']) ? (string) $row['old_fragment'] : '';
            $new = isset($row['new_fragment']) ? (string) $row['new_fragment'] : '';
            if ($row_id <= 0 || '' === $path || '' === $old || '' === $new || $old === $new) {
                $this->update_media_replacement_theme_css_ref_status($row_id, 'failed', __('Invalid theme CSS replacement row.', 'ultracache'));
                $failed++;
                continue;
            }

            $content = function_exists('ultracache_safe_file_get_contents') ? ultracache_safe_file_get_contents($path, 'media_library_replacement_theme_css_apply') : false;
            if (!is_string($content)) {
                $this->update_media_replacement_theme_css_ref_status($row_id, 'failed', __('Theme CSS file could not be read.', 'ultracache'));
                $failed++;
                continue;
            }

            $planned_checksum = isset($row['checksum_before']) && preg_match('/^[a-f0-9]{32}$/', (string) $row['checksum_before']) ? (string) $row['checksum_before'] : '';
            $current_checksum = md5($content);
            $backup = isset($row['backup_file_path']) ? wp_normalize_path((string) $row['backup_file_path']) : '';
            if ('' === $backup) {
                $backup = $this->build_media_replacement_theme_css_backup_path($path);
            }
            $backup_exists = false;
            $backup_matches_baseline = false;
            if ('' !== $backup && function_exists('ultracache_get_wp_filesystem')) {
                $filesystem = ultracache_get_wp_filesystem();
                $backup_exists = is_object($filesystem) && method_exists($filesystem, 'exists') && $filesystem->exists($backup);
                if ($backup_exists && function_exists('ultracache_safe_file_get_contents')) {
                    $backup_content = ultracache_safe_file_get_contents($backup, 'media_library_replacement_theme_css_backup_validate');
                    $backup_matches_baseline = is_string($backup_content) && '' !== $planned_checksum && hash_equals($planned_checksum, md5($backup_content));
                }
            }

            if ('' === $planned_checksum) {
                $this->update_media_replacement_theme_css_ref_status($row_id, 'failed', __('Theme CSS plan does not contain a baseline checksum. Restart Prepare.', 'ultracache'));
                $failed++;
                continue;
            }
            if ($backup_exists && !$backup_matches_baseline) {
                $this->update_media_replacement_theme_css_ref_status($row_id, 'failed', __('Theme CSS backup no longer matches the prepared baseline.', 'ultracache'));
                $failed++;
                continue;
            }

            if (false === strpos($content, $old)) {
                if (false !== strpos($content, $new) && $backup_matches_baseline) {
                    if ($this->update_media_replacement_theme_css_ref_status($row_id, 'applied', __('Theme CSS replacement was already present.', 'ultracache'), array('backup_file_path' => $backup, 'checksum_before' => $planned_checksum, 'checksum_after' => $current_checksum))) {
                        $applied++;
                    } else {
                        $registry_sync_failed++;
                    }
                } else {
                    $this->update_media_replacement_theme_css_ref_status($row_id, 'failed', __('Theme CSS no longer matches the prepared replacement plan.', 'ultracache'));
                    $failed++;
                }
                continue;
            }

            if (!hash_equals($planned_checksum, $current_checksum) && !$backup_matches_baseline) {
                $this->update_media_replacement_theme_css_ref_status($row_id, 'failed', __('Theme CSS changed after Prepare and was not overwritten.', 'ultracache'));
                $failed++;
                continue;
            }

            if ('' === $backup || !function_exists('ultracache_safe_file_put_contents') || (!$backup_exists && false === ultracache_safe_file_put_contents($backup, $content, 0, 'media_library_replacement_theme_css_backup'))) {
                $this->update_media_replacement_theme_css_ref_status($row_id, 'failed', __('Theme CSS backup could not be written.', 'ultracache'));
                $failed++;
                continue;
            }

            $count = 0;
            $new_content = str_replace($old, $new, $content, $count);
            if ($count <= 0 || $new_content === $content) {
                $this->update_media_replacement_theme_css_ref_status($row_id, 'failed', __('Theme CSS replacement did not change the file.', 'ultracache'));
                $failed++;
                continue;
            }

            if (false === ultracache_safe_file_put_contents($path, $new_content, 0, 'media_library_replacement_theme_css_apply')) {
                $this->update_media_replacement_theme_css_ref_status($row_id, 'failed', __('Theme CSS file could not be written.', 'ultracache'), array('backup_file_path' => $backup, 'checksum_before' => $planned_checksum));
                $failed++;
                continue;
            }

            if ($this->update_media_replacement_theme_css_ref_status($row_id, 'applied', '', array('backup_file_path' => $backup, 'checksum_before' => $planned_checksum, 'checksum_after' => md5($new_content)))) {
                $applied++;
            } else {
                $registry_sync_failed++;
            }
        }

        $summary = $this->get_media_replacement_theme_css_summary();
        $remaining = (int) $summary['pending'];
        $has_more = $remaining > 0;
        $total = (int) $summary['total'];
        $done = max(0, $total - $remaining);

        if ($registry_sync_failed > 0) {
            return array(
                'success'            => false,
                'blocked'            => true,
                'retryRequired'      => true,
                'message'            => __('Theme CSS files were processed, but one or more replacement registry rows could not be persisted. Resume Do to reconcile the current file contents.', 'ultracache'),
                'status'             => 'theme_css_reconcile_required',
                'hasMore'            => true,
                'batchProcessedThemeCssRefs' => $processed,
                'batchAppliedThemeCssRefs'   => $applied,
                'batchFailedThemeCssRefs'    => $failed,
                'registrySyncFailed'         => $registry_sync_failed,
            );
        }

        if (!$has_more) {
            $this->clear_media_replacement_destructive_authorization('theme_css_apply');
        }

        return array(
            'success' => true,
            'message' => $has_more
                /* translators: %1$d: processed replacement count; %2$d: total replacement count. */
                ? sprintf(__('Theme CSS replacement apply is in progress: %1$d of %2$d replacements processed.', 'ultracache'), $done, $total)
                /* translators: %1$d: processed replacement count; %2$d: failed replacement count. */
                : sprintf(__('Theme CSS replacement apply processed %1$d replacements. Failed: %2$d.', 'ultracache'), $total, (int) $summary['failed']),
            'status' => $has_more ? 'theme_css_applying' : 'theme_css_applied',
            'hasMore' => $has_more,
            'batchProcessedThemeCssRefs' => $processed,
            'batchAppliedThemeCssRefs' => $applied,
            'batchFailedThemeCssRefs' => $failed,
            'themeCssRefs' => $total,
            'themeCssPendingRefs' => (int) $summary['pending'],
            'themeCssAppliedRefs' => (int) $summary['applied'],
            'themeCssVerifiedRefs' => (int) $summary['verified'],
            'themeCssFailedRefs' => (int) $summary['failed'],
            'themeCssFilesWithRefs' => (int) $summary['files'],
            'progressPercent' => $total > 0 ? min(100, round(($done / $total) * 100, 1)) : 100,
            'nextStep' => $has_more
                ? __('Continue applying Theme CSS replacements in chunks.', 'ultracache')
                : __('Next step: Verify Theme CSS Replacements before Cleanup Preview.', 'ultracache'),
        );
    }

    public function verify_media_library_replacement_theme_css_replacements($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array('success' => false, 'message' => __('Media Library replacement registry tables are not available.', 'ultracache'));
        }

        $args = is_array($args) ? $args : array();
        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        $rows = $this->get_media_replacement_theme_css_pending_rows($limit, array('applied', 'verify_failed'));
        $processed = 0;
        $verified = 0;
        $failed = 0;
        foreach ((array) $rows as $row) {
            $processed++;
            $row_id = isset($row['id']) ? absint($row['id']) : 0;
            $path = isset($row['file_path']) ? wp_normalize_path((string) $row['file_path']) : '';
            $old = isset($row['old_fragment']) ? (string) $row['old_fragment'] : '';
            $new = isset($row['new_fragment']) ? (string) $row['new_fragment'] : '';
            $content = '' !== $path && function_exists('ultracache_safe_file_get_contents') ? ultracache_safe_file_get_contents($path, 'media_library_replacement_theme_css_verify') : false;
            if (!is_string($content)) {
                $this->update_media_replacement_theme_css_ref_status($row_id, 'verify_failed', __('Theme CSS file could not be read during verification.', 'ultracache'));
                $failed++;
                continue;
            }
            if (false === strpos($content, $old) && false !== strpos($content, $new)) {
                $this->update_media_replacement_theme_css_ref_status($row_id, 'verified', '', array('checksum_after' => md5($content)));
                $verified++;
            } else {
                $this->update_media_replacement_theme_css_ref_status($row_id, 'verify_failed', __('Theme CSS verification found the old fragment or could not find the new fragment.', 'ultracache'));
                $failed++;
            }
        }

        $summary = $this->get_media_replacement_theme_css_summary();
        $remaining = (int) $summary['applied'] + (int) $summary['verifyFailed'];
        $has_more = $remaining > 0;
        $total = (int) $summary['total'];

        return array(
            'success' => true,
            'message' => $has_more
                /* translators: %1$d: verified reference count; %2$d: pending reference count. */
                ? sprintf(__('Theme CSS replacement verification is in progress: %1$d verified, %2$d still pending.', 'ultracache'), (int) $summary['verified'], $remaining)
                /* translators: %1$d: verified reference count; %2$d: failed verification count. */
                : sprintf(__('Theme CSS replacement verification complete. Verified: %1$d. Failed: %2$d.', 'ultracache'), (int) $summary['verified'], (int) $summary['verifyFailed']),
            'status' => $has_more ? 'theme_css_verifying' : 'theme_css_verified',
            'hasMore' => $has_more,
            'batchProcessedThemeCssRefs' => $processed,
            'batchVerifiedThemeCssRefs' => $verified,
            'batchVerifyFailedThemeCssRefs' => $failed,
            'themeCssRefs' => $total,
            'themeCssPendingRefs' => (int) $summary['pending'],
            'themeCssAppliedRefs' => (int) $summary['applied'],
            'themeCssVerifiedRefs' => (int) $summary['verified'],
            'themeCssFailedRefs' => (int) $summary['failed'],
            'themeCssVerifyFailedRefs' => (int) $summary['verifyFailed'],
            'themeCssFilesWithRefs' => (int) $summary['files'],
            'progressPercent' => $total > 0 ? min(100, round(((int) $summary['verified'] / $total) * 100, 1)) : 100,
            'nextStep' => $has_more
                ? __('Continue verifying Theme CSS replacements.', 'ultracache')
                : __('Theme CSS replacements are verified. Next step: run Cleanup Preview, then use Delete Originals only after reviewing candidates.', 'ultracache'),
        );
    }

}
