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

    private function get_media_replacement_copy_rows($limit = 50)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $limit       = max(1, min(250, absint($limit)));

        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, attachment_id, item_scope, size_name, source_format, target_format, fallback_format, old_relative_path, generated_file_path, new_relative_path, new_url, new_file_path, old_size, new_size, destination_existed, destination_overwritten, destination_previous_size, destination_previous_hash, destination_backup_path, destination_backup_size, destination_backup_hash, destination_published_size, destination_published_hash, decision, status FROM %i WHERE status = %s ORDER BY id ASC LIMIT %d',
                $items_table,
                'matched',
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private function get_media_replacement_copy_summary()
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT status, COUNT(*) AS item_count, SUM(new_size) AS new_total, SUM(destination_overwritten) AS overwritten_total FROM %i GROUP BY status',
                $items_table
            ),
            ARRAY_A
        );

        $summary = array(
            'total'             => 0,
            'matched'           => 0,
            'copied'            => 0,
            'metadata_ready'    => 0,
            'metadata_updated'  => 0,
            'refs_scanned'      => 0,
            'metadata_restored' => 0,
            'metadata_rollback_failed' => 0,
            'metadata_failed'   => 0,
            'skipped'           => 0,
            'failed'            => 0,
            'pending'           => 0,
            'blocked'           => 0,
            'blocked_dependency'=> 0,
            'excluded'          => 0,
            'copiedBytes'       => 0,
            'overwritten'       => 0,
            'remainingToCopy'   => 0,
            'copyProgressItems' => 0,
            'copyProgressTotal' => 0,
        );

        foreach ((array) $rows as $row) {
            $status = isset($row['status']) ? sanitize_key((string) $row['status']) : '';
            $count  = isset($row['item_count']) ? max(0, (int) $row['item_count']) : 0;
            if ('excluded' !== $status) {
                $summary['total'] += $count;
            }
            if (array_key_exists($status, $summary)) {
                $summary[$status] += $count;
            }
            if (in_array($status, array('copied', 'metadata_ready', 'metadata_updated', 'refs_scanned', 'metadata_restored'), true)) {
                $summary['copiedBytes'] += isset($row['new_total']) ? max(0, (int) $row['new_total']) : 0;
                $summary['overwritten'] += isset($row['overwritten_total']) ? max(0, (int) $row['overwritten_total']) : 0;
            }
        }

        $summary['remainingToCopy']   = max(0, (int) $summary['matched']);
        $summary['copyProgressItems'] = max(0, (int) $summary['copied'] + (int) $summary['metadata_ready'] + (int) $summary['metadata_updated'] + (int) $summary['refs_scanned'] + (int) $summary['metadata_restored']);
        $summary['copyProgressTotal'] = max(0, (int) $summary['copyProgressItems'] + (int) $summary['matched']);

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
        $string_fields = array(
            'new_relative_path',
            'new_url',
            'new_file_path',
            'destination_previous_hash',
            'destination_backup_path',
            'destination_backup_hash',
            'destination_published_hash',
            'new_metadata_json',
            'status',
            'error_message',
        );
        $integer_fields = array(
            'destination_existed',
            'destination_overwritten',
            'destination_previous_size',
            'destination_backup_size',
            'destination_published_size',
        );

        foreach ($string_fields as $key) {
            if (array_key_exists($key, $data)) {
                $row[$key] = $data[$key];
                $formats[] = '%s';
            }
        }
        foreach ($integer_fields as $key) {
            if (array_key_exists($key, $data)) {
                $row[$key] = max(0, (int) $data[$key]);
                $formats[] = '%d';
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

    private function get_media_replacement_other_destination_owner($item_id, $relative_path)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $item_id = absint($item_id);
        $relative_path = ltrim(str_replace('\\', '/', (string) $relative_path), '/');
        if ('' === $items_table || $item_id <= 0 || '' === $relative_path || !($wpdb instanceof wpdb)) {
            return array();
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, generated_file_path, status FROM %i WHERE id <> %d AND new_relative_path = %s AND status NOT IN (%s, %s) ORDER BY id ASC LIMIT 1",
                $items_table,
                $item_id,
                $relative_path,
                'skipped',
                'failed'
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : array();
    }

    private function get_media_replacement_file_fingerprint($path)
    {
        $path = wp_normalize_path((string) $path);
        if ('' === $path || !$this->optimized_storage_path_exists($path, true) || !is_readable($path) || !function_exists('hash_file')) {
            return array('size' => 0, 'hash' => '');
        }

        $size = function_exists('ultracache_safe_filesize')
            ? (int) ultracache_safe_filesize($path, 'media_replacement_file_fingerprint')
            : (int) filesize($path);
        $hash = hash_file('sha256', $path);

        return array(
            'size' => max(0, $size),
            'hash' => is_string($hash) && preg_match('/^[a-f0-9]{64}$/', strtolower($hash)) ? strtolower($hash) : '',
        );
    }

    private function media_replacement_files_are_identical($source_file, $destination_file)
    {
        $source = $this->get_media_replacement_file_fingerprint($source_file);
        $destination = $this->get_media_replacement_file_fingerprint($destination_file);

        return $source['size'] > 0
            && $source['size'] === $destination['size']
            && '' !== $source['hash']
            && '' !== $destination['hash']
            && hash_equals($source['hash'], $destination['hash']);
    }

    private function build_media_replacement_atomic_temp_path($target_file)
    {
        $target_file = wp_normalize_path((string) $target_file);
        $target_format = strtolower((string) pathinfo($target_file, PATHINFO_EXTENSION));
        if ('' === $target_file || !in_array($target_format, array('avif', 'webp'), true)) {
            return '';
        }

        $token = strtolower((string) wp_generate_password(16, false, false));
        $token = preg_replace('/[^a-z0-9_-]/', '', $token);
        if (!is_string($token) || '' === $token) {
            $token = substr(md5(wp_generate_uuid4() . microtime(true)), 0, 16);
        }

        $stem = sanitize_file_name((string) pathinfo(basename($target_file), PATHINFO_FILENAME));
        if ('' === $stem) {
            $stem = 'ultracache-replacement';
        }

        return trailingslashit(dirname($target_file)) . '.' . $stem . '.uc-tmp-' . $token . '.' . $target_format;
    }

    private function get_media_replacement_backup_root()
    {
        $uploads = function_exists('ultracache_uploads_base_info') ? ultracache_uploads_base_info() : wp_upload_dir(null, false);
        if (empty($uploads['basedir'])) {
            return '';
        }

        return trailingslashit(wp_normalize_path((string) $uploads['basedir']))
            . 'ultracache/media-replacement-backups/current';
    }

    private function build_media_replacement_backup_path($item_id, $target_file, $previous_hash)
    {
        $root = $this->get_media_replacement_backup_root();
        $item_id = absint($item_id);
        $target_file = wp_normalize_path((string) $target_file);
        $previous_hash = strtolower((string) $previous_hash);
        if ('' === $root || $item_id <= 0 || '' === $target_file || !preg_match('/^[a-f0-9]{64}$/', $previous_hash)) {
            return '';
        }

        $filename = sanitize_file_name($item_id . '-' . substr($previous_hash, 0, 16) . '-' . basename($target_file));
        return '' !== $filename ? trailingslashit($root) . $filename : '';
    }

    private function media_replacement_backup_matches_row(array $row)
    {
        $backup = wp_normalize_path((string) ($row['destination_backup_path'] ?? ''));
        $expected_size = max(0, (int) ($row['destination_backup_size'] ?? 0));
        $expected_hash = strtolower((string) ($row['destination_backup_hash'] ?? ''));
        if ('' === $backup || $expected_size <= 0 || !preg_match('/^[a-f0-9]{64}$/', $expected_hash)) {
            return false;
        }

        $fingerprint = $this->get_media_replacement_file_fingerprint($backup);
        return $fingerprint['size'] === $expected_size
            && '' !== $fingerprint['hash']
            && hash_equals($expected_hash, $fingerprint['hash']);
    }

    private function media_replacement_published_destination_matches_row(array $row, $path = '')
    {
        $path = '' !== (string) $path
            ? wp_normalize_path((string) $path)
            : wp_normalize_path((string) ($row['new_file_path'] ?? ''));
        $expected_size = max(0, (int) ($row['destination_published_size'] ?? 0));
        $expected_hash = strtolower((string) ($row['destination_published_hash'] ?? ''));
        if ('' === $path || $expected_size <= 0 || !preg_match('/^[a-f0-9]{64}$/', $expected_hash)) {
            return false;
        }

        $fingerprint = $this->get_media_replacement_file_fingerprint($path);
        return $fingerprint['size'] === $expected_size
            && '' !== $fingerprint['hash']
            && hash_equals($expected_hash, $fingerprint['hash']);
    }

    private function media_replacement_previous_destination_matches_row(array $row, $path = '')
    {
        $path = '' !== (string) $path
            ? wp_normalize_path((string) $path)
            : wp_normalize_path((string) ($row['new_file_path'] ?? ''));
        $expected_size = max(0, (int) ($row['destination_previous_size'] ?? 0));
        $expected_hash = strtolower((string) ($row['destination_previous_hash'] ?? ''));
        if ('' === $path || $expected_size <= 0 || !preg_match('/^[a-f0-9]{64}$/', $expected_hash)) {
            return false;
        }

        $fingerprint = $this->get_media_replacement_file_fingerprint($path);
        return $fingerprint['size'] === $expected_size
            && '' !== $fingerprint['hash']
            && hash_equals($expected_hash, $fingerprint['hash']);
    }

    private function cleanup_media_replacement_atomic_temp($temp_file)
    {
        $temp_file = wp_normalize_path((string) $temp_file);
        return '' === $temp_file
            || !function_exists('ultracache_safe_unlink')
            || ultracache_safe_unlink($temp_file, 'media_replacement_atomic_temp_cleanup');
    }

    private function restore_media_replacement_destination_backup(array $row, $authoritative = false)
    {
        $authoritative = !empty($authoritative);
        $item_id = absint($row['id'] ?? 0);
        $target_format = sanitize_key((string) ($row['target_format'] ?? ''));
        $target_file = wp_normalize_path((string) ($row['new_file_path'] ?? ''));
        $backup_file = wp_normalize_path((string) ($row['destination_backup_path'] ?? ''));
        $overwritten = !empty($row['destination_overwritten']);
        if (!$overwritten && '' === $backup_file) {
            return array('restored' => true, 'message' => '');
        }
        if ($item_id <= 0 || !in_array($target_format, array('avif', 'webp'), true) || '' === $target_file) {
            return array('restored' => false, 'message' => __('The overwritten destination registry row is invalid.', 'ultracache'));
        }

        if ($this->optimized_storage_path_exists($target_file, true)
            && $this->media_replacement_previous_destination_matches_row($row, $target_file)
        ) {
            if ('' !== $backup_file && function_exists('ultracache_safe_unlink')
                && !ultracache_safe_unlink($backup_file, 'media_replacement_backup_cleanup')
            ) {
                return array('restored' => false, 'message' => __('The original destination is already restored, but its UltraCache backup could not be removed.', 'ultracache'));
            }

            if (!$this->update_media_replacement_item_copy_result($item_id, array(
                'destination_overwritten'   => 0,
                'destination_previous_size' => 0,
                'destination_previous_hash' => '',
                'destination_backup_path'   => '',
                'destination_backup_size'   => 0,
                'destination_backup_hash'   => '',
                'destination_published_size'=> 0,
                'destination_published_hash'=> '',
            ))) {
                return array('restored' => false, 'message' => __('The original destination is restored, but the replacement registry could not be updated.', 'ultracache'));
            }
            return array('restored' => true, 'message' => '');
        }

        if (!$this->media_replacement_backup_matches_row($row)) {
            return array('restored' => false, 'message' => __('The overwritten destination backup is missing or no longer matches the replacement registry.', 'ultracache'));
        }

        if (!$authoritative && $this->optimized_storage_path_exists($target_file, true)) {
            $published_owned = $this->media_replacement_published_destination_matches_row($row, $target_file);
            if (!$published_owned) {
                $generated = wp_normalize_path((string) ($row['generated_file_path'] ?? ''));
                $published_owned = '' !== $generated && $this->media_replacement_files_are_identical($generated, $target_file);
            }

            if (!$published_owned) {
                return array('restored' => false, 'message' => __('The overwritten destination changed after UltraCache publication, so its backup was not restored over the newer file.', 'ultracache'));
            }
        }

        $temp_file = $this->build_media_replacement_atomic_temp_path($target_file);
        $filesystem = $this->optimized_storage_filesystem();
        if ('' === $temp_file || !$filesystem || !method_exists($filesystem, 'copy')) {
            return array('restored' => false, 'message' => __('The overwritten destination backup could not be staged for restoration.', 'ultracache'));
        }
        if (!$filesystem->copy($backup_file, $temp_file, true, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644)) {
            $this->cleanup_media_replacement_atomic_temp($temp_file);
            return array('restored' => false, 'message' => __('The overwritten destination backup could not be staged for restoration.', 'ultracache'));
        }

        $this->optimized_storage_forget_path($temp_file);
        if (!$this->optimized_storage_path_exists($temp_file, true)
            || !$this->media_replacement_files_are_identical($backup_file, $temp_file)
        ) {
            $this->cleanup_media_replacement_atomic_temp($temp_file);
            return array('restored' => false, 'message' => __('The staged overwritten destination backup failed validation.', 'ultracache'));
        }

        if (!function_exists('ultracache_safe_rename')
            || !ultracache_safe_rename($temp_file, $target_file, 'media_replacement_atomic_commit')
        ) {
            $this->cleanup_media_replacement_atomic_temp($temp_file);
            return array('restored' => false, 'message' => __('The overwritten destination backup could not be atomically restored.', 'ultracache'));
        }

        $this->optimized_storage_forget_path($target_file);
        if (!$this->media_replacement_files_are_identical($backup_file, $target_file)) {
            return array('restored' => false, 'message' => __('The restored destination does not match its persisted backup.', 'ultracache'));
        }

        if (function_exists('ultracache_safe_unlink')
            && !ultracache_safe_unlink($backup_file, 'media_replacement_backup_cleanup')
        ) {
            return array('restored' => false, 'message' => __('The destination was restored, but its UltraCache backup could not be removed.', 'ultracache'));
        }

        if (!$this->update_media_replacement_item_copy_result($item_id, array(
            'destination_overwritten'   => 0,
            'destination_previous_size' => 0,
            'destination_previous_hash' => '',
            'destination_backup_path'   => '',
            'destination_backup_size'   => 0,
            'destination_backup_hash'   => '',
            'destination_published_size'=> 0,
            'destination_published_hash'=> '',
        ))) {
            return array('restored' => false, 'message' => __('The destination was restored, but the replacement registry could not be updated.', 'ultracache'));
        }

        return array('restored' => true, 'message' => '');
    }

    private function finalize_media_replacement_destination_backup_cleanup(array $row)
    {
        $item_id = absint($row['id'] ?? 0);
        $backup_file = wp_normalize_path((string) ($row['destination_backup_path'] ?? ''));
        if ('' === $backup_file) {
            return array('cleaned' => true, 'message' => '');
        }

        if (function_exists('ultracache_safe_unlink')
            && !ultracache_safe_unlink($backup_file, 'media_replacement_backup_cleanup')
        ) {
            return array('cleaned' => false, 'message' => __('The overwritten destination backup could not be removed.', 'ultracache'));
        }

        if ($item_id > 0 && !$this->update_media_replacement_item_copy_result($item_id, array(
            'destination_previous_size' => 0,
            'destination_previous_hash' => '',
            'destination_backup_path'   => '',
            'destination_backup_size'   => 0,
            'destination_backup_hash'   => '',
        ))) {
            return array('cleaned' => false, 'message' => __('The overwritten destination backup was removed, but the replacement registry could not be updated.', 'ultracache'));
        }

        return array('cleaned' => true, 'message' => '');
    }

    private function copy_media_replacement_item_to_library(array $row, $collision_policy = 'block')
    {
        $item_id       = absint($row['id'] ?? 0);
        $target_format = sanitize_key((string) ($row['target_format'] ?? ''));
        $old_relative  = ltrim(str_replace('\\', '/', (string) ($row['old_relative_path'] ?? '')), '/');
        $generated     = wp_normalize_path((string) ($row['generated_file_path'] ?? ''));
        $collision_policy = in_array((string) $collision_policy, array('block', 'overwrite'), true) ? (string) $collision_policy : 'block';
        if ('overwrite_with_backup' === sanitize_key((string) ($row['decision'] ?? ''))) {
            $collision_policy = 'overwrite';
        }

        if ($item_id <= 0 || !in_array($target_format, array('avif', 'webp'), true) || '' === $old_relative || '' === $generated) {
            return array('copied' => false, 'bytes' => 0, 'message' => __('Invalid Media Library replacement registry row.', 'ultracache'));
        }

        if (!$this->optimized_storage_path_exists($generated, true) || !$this->is_valid_generated_media_file($generated, $target_format, 'media_replacement_copy_source_validate')) {
            return array('copied' => false, 'bytes' => 0, 'message' => __('Generated UltraCache source file is missing or invalid.', 'ultracache'));
        }

        $stored_relative = ltrim(str_replace('\\', '/', (string) ($row['new_relative_path'] ?? '')), '/');
        $stored_target = wp_normalize_path((string) ($row['new_file_path'] ?? ''));
        $destination = $this->build_media_replacement_planned_destination($old_relative, $target_format);
        $relative = '' !== $stored_relative ? $stored_relative : ltrim(str_replace('\\', '/', (string) ($destination['relativePath'] ?? '')), '/');
        $url = '' !== (string) ($row['new_url'] ?? '') ? esc_url_raw((string) $row['new_url']) : esc_url_raw((string) ($destination['url'] ?? ''));
        $target_file = '' !== $stored_target ? $stored_target : $this->build_media_replacement_destination_file_path($relative);
        $expected_target = $this->build_media_replacement_destination_file_path($relative);

        if ('' === $relative || '' === $target_file || '' === $expected_target || $target_file !== wp_normalize_path($expected_target) || '' === $url) {
            return array('copied' => false, 'bytes' => 0, 'message' => __('WordPress upload destination could not be resolved consistently.', 'ultracache'));
        }

        if ('' === $stored_relative || '' === $stored_target) {
            if (!$this->update_media_replacement_item_copy_result($item_id, array(
                'new_relative_path' => $relative,
                'new_url'           => $url,
                'new_file_path'     => $target_file,
                'status'            => 'matched',
                'error_message'     => null,
            ))) {
                return array('copied' => false, 'bytes' => 0, 'message' => __('Replacement destination plan could not be persisted.', 'ultracache'));
            }
            $row['new_relative_path'] = $relative;
            $row['new_url'] = $url;
            $row['new_file_path'] = $target_file;
        }

        $other_destination_owner = $this->get_media_replacement_other_destination_owner($item_id, $relative);
        if (!empty($other_destination_owner)) {
            $other_generated = wp_normalize_path((string) ($other_destination_owner['generated_file_path'] ?? ''));
            if ('' === $other_generated || !$this->media_replacement_files_are_identical($generated, $other_generated)) {
                return array(
                    'copied' => false,
                    'blocked' => true,
                    'collision' => true,
                    'bytes' => 0,
                    'message' => __('Two replacement registry rows resolve to the same Media Library destination but require different image bytes. Overwrite cannot resolve an internal replacement-plan collision.', 'ultracache'),
                );
            }
        }

        $target_dir = dirname($target_file);
        if (!$this->optimized_storage_ensure_directory($target_dir)) {
            return array('copied' => false, 'bytes' => 0, 'message' => __('WordPress upload destination directory could not be created.', 'ultracache'));
        }
        $this->optimized_storage_harden_upload_permissions($target_dir, 'directory');

        $filesystem = $this->optimized_storage_filesystem();
        if (!$filesystem || !method_exists($filesystem, 'copy') || !method_exists($filesystem, 'exists')) {
            return array('copied' => false, 'bytes' => 0, 'message' => __('WordPress filesystem is not available for Media Library replacement publication.', 'ultracache'));
        }

        $target_exists = $filesystem->exists($target_file);
        $has_persisted_backup = '' !== wp_normalize_path((string) ($row['destination_backup_path'] ?? ''))
            || !empty($row['destination_overwritten']);
        if ($has_persisted_backup && !$this->media_replacement_backup_matches_row($row)) {
            return array('copied' => false, 'bytes' => 0, 'message' => __('The persisted overwrite backup is missing or invalid. Prepare stopped without changing the destination.', 'ultracache'));
        }

        if ($target_exists) {
            $this->optimized_storage_forget_path($target_file);
            if ($this->optimized_storage_path_exists($target_file, true)
                && $this->is_valid_generated_media_file($target_file, $target_format, 'media_replacement_reuse_existing_destination_validate')
                && $this->media_replacement_files_are_identical($generated, $target_file)
            ) {
                $reused_existing = empty($row['destination_overwritten']);
                return $this->complete_media_replacement_copy_registry(
                    $row,
                    $relative,
                    $url,
                    $target_file,
                    $generated,
                    $reused_existing,
                    !empty($row['destination_overwritten'])
                );
            }

            if ('overwrite' !== $collision_policy) {
                return array(
                    'copied'   => false,
                    'blocked'  => true,
                    'collision'=> true,
                    'bytes'    => 0,
                    'message'  => __('A different AVIF/WebP file already exists at the planned Media Library destination. Select overwrite with backup and restart Prepare, or resolve the collision manually.', 'ultracache'),
                );
            }
        }

        if ($target_exists && 'overwrite' === $collision_policy) {
            $current = $this->get_media_replacement_file_fingerprint($target_file);
            $previous_hash = strtolower((string) ($row['destination_previous_hash'] ?? ''));
            $previous_size = max(0, (int) ($row['destination_previous_size'] ?? 0));
            if ($has_persisted_backup) {
                if ($current['size'] !== $previous_size || '' === $current['hash'] || !hash_equals($previous_hash, $current['hash'])) {
                    return array('copied' => false, 'blocked' => true, 'collision' => true, 'bytes' => 0, 'message' => __('The existing destination changed after UltraCache created its overwrite backup. Prepare stopped without replacing the newer file.', 'ultracache'));
                }
            } else {
                if ($current['size'] <= 0 || '' === $current['hash']) {
                    return array('copied' => false, 'bytes' => 0, 'message' => __('The existing destination could not be fingerprinted before overwrite.', 'ultracache'));
                }

                $backup_file = $this->build_media_replacement_backup_path($item_id, $target_file, $current['hash']);
                if ('' === $backup_file || !$this->optimized_storage_ensure_directory(dirname($backup_file))) {
                    return array('copied' => false, 'bytes' => 0, 'message' => __('The existing destination could not be backed up before overwrite.', 'ultracache'));
                }
                if (!$filesystem->copy($target_file, $backup_file, true, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644)) {
                    if (function_exists('ultracache_safe_unlink')) {
                        ultracache_safe_unlink($backup_file, 'media_replacement_backup_cleanup');
                    }
                    return array('copied' => false, 'bytes' => 0, 'message' => __('The existing destination could not be backed up before overwrite.', 'ultracache'));
                }

                $backup = $this->get_media_replacement_file_fingerprint($backup_file);
                if ($backup['size'] !== $current['size'] || '' === $backup['hash'] || !hash_equals($current['hash'], $backup['hash'])) {
                    if (function_exists('ultracache_safe_unlink')) {
                        ultracache_safe_unlink($backup_file, 'media_replacement_backup_cleanup');
                    }
                    return array('copied' => false, 'bytes' => 0, 'message' => __('The existing destination backup failed verification.', 'ultracache'));
                }

                if (!$this->update_media_replacement_item_copy_result($item_id, array(
                    'destination_existed'       => 1,
                    'destination_overwritten'   => 1,
                    'destination_previous_size' => $current['size'],
                    'destination_previous_hash' => $current['hash'],
                    'destination_backup_path'   => $backup_file,
                    'destination_backup_size'   => $backup['size'],
                    'destination_backup_hash'   => $backup['hash'],
                    'status'                    => 'matched',
                    'error_message'             => null,
                ))) {
                    if (function_exists('ultracache_safe_unlink')) {
                        ultracache_safe_unlink($backup_file, 'media_replacement_backup_cleanup');
                    }
                    return array('copied' => false, 'bytes' => 0, 'message' => __('The overwrite backup was created, but its registry state could not be persisted.', 'ultracache'));
                }

                $row['destination_existed'] = 1;
                $row['destination_overwritten'] = 1;
                $row['destination_previous_size'] = $current['size'];
                $row['destination_previous_hash'] = $current['hash'];
                $row['destination_backup_path'] = $backup_file;
                $row['destination_backup_size'] = $backup['size'];
                $row['destination_backup_hash'] = $backup['hash'];
            }
        } elseif (!$has_persisted_backup) {
            $row['destination_existed'] = 0;
            $row['destination_overwritten'] = 0;
        }

        $temp_file = $this->build_media_replacement_atomic_temp_path($target_file);
        if ('' === $temp_file) {
            return array('copied' => false, 'bytes' => 0, 'message' => __('Generated image could not be staged beside the planned Media Library destination.', 'ultracache'));
        }
        if (!$filesystem->copy($generated, $temp_file, true, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644)) {
            $this->cleanup_media_replacement_atomic_temp($temp_file);
            return array('copied' => false, 'bytes' => 0, 'message' => __('Generated image could not be staged beside the planned Media Library destination.', 'ultracache'));
        }

        $this->optimized_storage_forget_path($temp_file);
        if (!$this->optimized_storage_path_exists($temp_file, true)
            || !$this->is_valid_generated_media_file($temp_file, $target_format, 'media_replacement_atomic_temp_validate')
            || !$this->media_replacement_files_are_identical($generated, $temp_file)
        ) {
            $this->cleanup_media_replacement_atomic_temp($temp_file);
            return array('copied' => false, 'bytes' => 0, 'message' => __('The staged replacement file failed validation.', 'ultracache'));
        }

        $target_exists_now = $filesystem->exists($target_file);
        if ($target_exists_now) {
            if ($this->media_replacement_files_are_identical($generated, $target_file)) {
                $this->cleanup_media_replacement_atomic_temp($temp_file);
                return $this->complete_media_replacement_copy_registry($row, $relative, $url, $target_file, $generated, empty($row['destination_overwritten']), !empty($row['destination_overwritten']));
            }

            if ('overwrite' !== $collision_policy) {
                $this->cleanup_media_replacement_atomic_temp($temp_file);
                return array('copied' => false, 'blocked' => true, 'collision' => true, 'bytes' => 0, 'message' => __('A destination collision appeared before atomic publication. No existing file was changed.', 'ultracache'));
            }

            $current = $this->get_media_replacement_file_fingerprint($target_file);
            $expected_size = max(0, (int) ($row['destination_previous_size'] ?? 0));
            $expected_hash = strtolower((string) ($row['destination_previous_hash'] ?? ''));
            if ($current['size'] !== $expected_size || '' === $current['hash'] || !preg_match('/^[a-f0-9]{64}$/', $expected_hash) || !hash_equals($expected_hash, $current['hash'])) {
                $this->cleanup_media_replacement_atomic_temp($temp_file);
                return array('copied' => false, 'blocked' => true, 'collision' => true, 'bytes' => 0, 'message' => __('The destination changed after collision validation. No overwrite was performed.', 'ultracache'));
            }
        }

        if (!function_exists('ultracache_safe_rename')
            || !ultracache_safe_rename($temp_file, $target_file, 'media_replacement_atomic_commit')
        ) {
            $this->cleanup_media_replacement_atomic_temp($temp_file);
            return array('copied' => false, 'bytes' => 0, 'message' => __('The staged replacement file could not be atomically published.', 'ultracache'));
        }

        $this->optimized_storage_harden_upload_permissions($target_file, 'file');
        $this->optimized_storage_forget_path($target_file);
        if (!$this->optimized_storage_path_exists($target_file, true)
            || !$this->is_valid_generated_media_file($target_file, $target_format, 'media_replacement_atomic_destination_validate')
            || !$this->media_replacement_files_are_identical($generated, $target_file)
        ) {
            $rollback = $this->rollback_media_replacement_failed_publication($row, $target_file);
            return array(
                'copied' => false,
                'bytes' => 0,
                'message' => !empty($rollback['rolledBack'])
                    ? __('The atomically published replacement file failed validation and the previous destination state was restored.', 'ultracache')
                    : (string) ($rollback['message'] ?? __('The atomically published replacement file failed validation and could not be rolled back.', 'ultracache')),
            );
        }

        $completed = $this->complete_media_replacement_copy_registry($row, $relative, $url, $target_file, $generated, false, !empty($row['destination_overwritten']));
        if (empty($completed['copied'])) {
            $rollback = $this->rollback_media_replacement_failed_publication($row, $target_file);
            if (empty($rollback['rolledBack'])) {
                $completed['message'] = (string) ($rollback['message'] ?? __('Published file registry update failed and the destination could not be rolled back.', 'ultracache'));
            }
        }

        return $completed;
    }

    private function complete_media_replacement_copy_registry(array $row, $relative, $url, $target_file, $generated, $reused_existing, $overwritten)
    {
        $item_id = absint($row['id'] ?? 0);
        $published = $this->get_media_replacement_file_fingerprint($target_file);
        $bytes = max(0, (int) $published['size']);
        if ($bytes <= 0 || '' === $published['hash']) {
            return array('copied' => false, 'bytes' => 0, 'message' => __('Published file fingerprint could not be persisted.', 'ultracache'));
        }
        $metadata_plan = array(
            'copied_from'       => $generated,
            'copied_to'         => $target_file,
            'new_relative_path' => $relative,
            'new_url'           => $url,
            'target_format'     => sanitize_key((string) ($row['target_format'] ?? '')),
            'copy_skipped'      => (bool) $reused_existing,
            'reused_existing'   => (bool) $reused_existing,
            'overwrote_existing_destination' => (bool) $overwritten,
            'destination_backup_path' => $overwritten ? wp_normalize_path((string) ($row['destination_backup_path'] ?? '')) : '',
            'copied_at'         => current_time('mysql', true),
            'metadata_updated'  => false,
            'db_replaced'       => false,
        );
        $metadata_json = function_exists('wp_json_encode') ? wp_json_encode($metadata_plan) : '{}';

        $updated = $this->update_media_replacement_item_copy_result($item_id, array(
            'new_relative_path' => $relative,
            'new_url'           => $url,
            'new_file_path'     => $target_file,
            'destination_existed' => !empty($row['destination_existed']) || $reused_existing || $overwritten ? 1 : 0,
            'destination_overwritten' => $overwritten ? 1 : 0,
            'destination_published_size' => $published['size'],
            'destination_published_hash' => $published['hash'],
            'new_metadata_json' => is_string($metadata_json) ? $metadata_json : '{}',
            'status'            => 'copied',
            'error_message'     => $reused_existing ? __('Existing WordPress upload replacement file reused; copy skipped.', 'ultracache') : null,
        ));

        if (!$updated) {
            return array('copied' => false, 'bytes' => 0, 'message' => __('Published file registry update failed.', 'ultracache'));
        }

        return array('copied' => true, 'bytes' => $bytes, 'message' => '', 'overwritten' => (bool) $overwritten, 'reused' => (bool) $reused_existing);
    }

    private function rollback_media_replacement_failed_publication(array $row, $target_file)
    {
        $target_file = wp_normalize_path((string) $target_file);
        if (!empty($row['destination_overwritten']) && $this->media_replacement_backup_matches_row($row)) {
            $restored = $this->restore_media_replacement_destination_backup($row);
            return array(
                'rolledBack' => !empty($restored['restored']),
                'message' => (string) ($restored['message'] ?? ''),
            );
        }

        if ('' === $target_file || !$this->optimized_storage_path_exists($target_file, true)) {
            return array('rolledBack' => true, 'message' => '');
        }

        if (!empty($row['destination_existed'])) {
            return array('rolledBack' => false, 'message' => __('A pre-existing replacement destination was preserved after the failed publication.', 'ultracache'));
        }

        $generated = wp_normalize_path((string) ($row['generated_file_path'] ?? ''));
        if ('' === $generated || !$this->media_replacement_files_are_identical($generated, $target_file)) {
            return array('rolledBack' => false, 'message' => __('The replacement destination changed after publication, so the newer file was not removed.', 'ultracache'));
        }

        if (function_exists('ultracache_safe_unlink')
            && ultracache_safe_unlink($target_file, 'media_replacement_invalid_atomic_final_cleanup')
        ) {
            return array('rolledBack' => true, 'message' => '');
        }

        return array('rolledBack' => false, 'message' => __('The replacement destination created by the failed publication could not be removed.', 'ultracache'));
    }

    private function get_media_replacement_restart_publication_rows()
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, target_format, generated_file_path, new_file_path, destination_existed, destination_overwritten, destination_previous_size, destination_previous_hash, destination_backup_path, destination_backup_size, destination_backup_hash, destination_published_size, destination_published_hash, status FROM %i WHERE (new_file_path <> '' OR destination_backup_path <> '') ORDER BY id DESC",
                $items_table
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private function reset_media_replacement_prepare_publications_for_restart()
    {
        foreach ($this->get_media_replacement_restart_publication_rows() as $row) {
            $target_file = wp_normalize_path((string) ($row['new_file_path'] ?? ''));
            $has_backup = '' !== wp_normalize_path((string) ($row['destination_backup_path'] ?? '')) || !empty($row['destination_overwritten']);

            if ($has_backup) {
                $restored = $this->restore_media_replacement_destination_backup($row, true);
                if (empty($restored['restored'])) {
                    return array(
                        'success' => false,
                        'message' => (string) ($restored['message'] ?? __('An overwritten destination could not be restored before restarting Prepare.', 'ultracache')),
                    );
                }
                continue;
            }

            if ('' === $target_file || !$this->optimized_storage_path_exists($target_file, true) || !empty($row['destination_existed'])) {
                continue;
            }

            if (function_exists('ultracache_safe_unlink')) {
                ultracache_safe_unlink($target_file, 'media_replacement_restart_current_destination_cleanup');
            }
        }

        return array('success' => true, 'message' => '');
    }

    public function copy_media_library_replacement_files($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array(
                'success' => false,
                'message' => __('Media Library replacement registry tables are not available.', 'ultracache'),
            );
        }

        if (!$this->media_replacement_has_registry_rows()) {
            return $this->build_media_replacement_empty_registry_response(__('No replacement registry rows are available to copy.', 'ultracache'));
        }

        $args = is_array($args) ? $args : array();
        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        $limit = max(1, min(250, $limit));
        $time_budget = isset($args['time_budget']) && (float) $args['time_budget'] > 0 ? (float) $args['time_budget'] : 15.0;
        $time_budget = max(1.0, min(30.0, $time_budget));
        $deadline = microtime(true) + $time_budget;
        $rows = $this->get_media_replacement_copy_rows($limit);
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        $collision_policy = $state['collision_policy'];

        $copied = 0;
        $failed = 0;
        $blocked_collision = false;
        $blocked_message = '';
        $bytes = 0;
        foreach ($rows as $row) {
            if (($copied + $failed) > 0 && microtime(true) >= $deadline) {
                break;
            }

            $item_id = isset($row['id']) ? absint($row['id']) : 0;
            $result = $this->copy_media_replacement_item_to_library($row, $collision_policy);
            if (!empty($result['copied'])) {
                $copied++;
                $bytes += isset($result['bytes']) ? max(0, (int) $result['bytes']) : 0;
            } elseif (!empty($result['blocked']) && !empty($result['collision'])) {
                $blocked_collision = true;
                $blocked_message = isset($result['message']) ? wp_strip_all_tags((string) $result['message']) : __('A destination filename collision blocked Prepare.', 'ultracache');
                $this->update_media_replacement_item_copy_result($item_id, array(
                    'status'        => 'matched',
                    'error_message' => $blocked_message,
                ));
                break;
            } else {
                $failed++;
                $this->update_media_replacement_item_copy_result($item_id, array(
                    'status'        => 'failed',
                    'error_message' => isset($result['message']) ? wp_strip_all_tags((string) $result['message']) : __('Copy failed.', 'ultracache'),
                ));
            }
        }

        $summary = $this->get_media_replacement_copy_summary();
        $has_more = !empty($summary['remainingToCopy']);
        $state['heartbeat_at'] = current_time('mysql', true);
        $state['updated_at'] = current_time('mysql', true);

        if ($blocked_collision) {
            $state['status'] = 'failed';
            $state['run_status'] = 'failed';
            $state['active_step'] = 'prepare_failed';
            $state['last_error'] = $blocked_message;
        } elseif ((int) $summary['failed'] > 0) {
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
        $state = $this->update_media_replacement_workflow_state($state);

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
            'status'          => $state['status'],
            'activeStep'      => $state['active_step'],
            'hasMore'         => $has_more,
            'batchSize'       => $limit,
            'batchCopied'     => $copied,
            'batchFailed'     => $failed,
            'collisionBlocked'=> $blocked_collision,
            'collisionPolicy' => $collision_policy,
            'overwritten'     => (int) $summary['overwritten'],
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

    private function get_media_replacement_prepare_validation_rows($after_item_id = 0, $limit = 50)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $after_item_id = max(0, absint($after_item_id));
        $limit = max(1, min(250, absint($limit)));
        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, target_format, generated_file_path, new_relative_path, new_file_path FROM %i WHERE status = %s AND id > %d ORDER BY id ASC LIMIT %d',
            $items_table,
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
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if (!$this->media_replacement_has_registry_rows()) {
            return array('success' => false, 'message' => __('The active Prepare workflow has no registry rows.', 'ultracache'));
        }

        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        $limit = max(1, min(250, $limit));
        $time_budget = isset($args['time_budget']) && (float) $args['time_budget'] > 0 ? (float) $args['time_budget'] : 15.0;
        $time_budget = max(1.0, min(30.0, $time_budget));
        $deadline = microtime(true) + $time_budget;
        $rows = $this->get_media_replacement_prepare_validation_rows($state['validation_cursor_item_id'], $limit);
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
        $has_more = !empty($this->get_media_replacement_prepare_validation_rows($last_id, 1));
        $summary = $this->get_media_replacement_copy_summary();

        $final_guard = !$has_more ? $this->get_media_library_replacement_start_guard() : array('allowed' => true);
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
        $state = $this->update_media_replacement_workflow_state($state);

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
