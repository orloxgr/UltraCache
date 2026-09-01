<?php
/**
 * UltraCache Media Library replacement database reference indexing, matching, apply, verification, rollback, and preview.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Replacement_Database_Trait
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache uses private custom Media Library replacement registry tables with validated table identifiers.

    private function sanitize_media_replacement_db_identifier($identifier, $max_length = 191)
    {
        $identifier = preg_replace('/[^A-Za-z0-9_]/', '', (string) $identifier);
        $max_length = max(1, absint($max_length));
        return substr($identifier, 0, $max_length);
    }

    private function is_media_replacement_safe_identifier($identifier)
    {
        $identifier = (string) $identifier;
        return '' !== $identifier
            && $identifier === $this->sanitize_media_replacement_db_identifier($identifier, 191);
    }

    private function get_media_replacement_own_table_names()
    {
        return array_filter(array(
            $this->get_media_replacement_items_table_name(),
            $this->get_media_replacement_attachment_plans_table_name(),
            $this->get_media_replacement_refs_table_name(),
            $this->get_media_replacement_ref_index_table_name(),
            $this->get_media_replacement_file_refs_table_name(),
        ));
    }

    private function is_media_replacement_own_table_name($table_name)
    {
        $table_name = (string) $table_name;
        if ('' === $table_name) {
            return false;
        }

        return in_array($table_name, $this->get_media_replacement_own_table_names(), true);
    }

    private function is_media_replacement_ultracache_owned_database_table_name($table_name)
    {
        global $wpdb;

        $table_name = $this->sanitize_media_replacement_db_identifier((string) $table_name, 191);
        if ('' === $table_name || !($wpdb instanceof wpdb)) {
            return false;
        }

        if (function_exists('ultracache_is_allowed_custom_table_name') && ultracache_is_allowed_custom_table_name($table_name)) {
            return true;
        }

        $prefix = (string) $wpdb->prefix . 'ultracache_';
        return '' !== $prefix && 0 === strpos($table_name, $prefix);
    }

    private function is_media_replacement_ultracache_owned_option_name($option_name)
    {
        $option_name = (string) $option_name;
        return '' !== $option_name
            && (
                0 === strpos($option_name, 'ultracache_')
                || 0 === strpos($option_name, '_transient_ultracache_')
                || 0 === strpos($option_name, '_site_transient_ultracache_')
            );
    }

    private function get_media_replacement_database_ref_option_name(array $ref)
    {
        global $wpdb;

        if (!$this->is_media_replacement_options_database_ref($ref)) {
            return '';
        }

        $planned_option_name = substr((string) ($ref['row_identity'] ?? ''), 0, 191);
        if ('' !== $planned_option_name) {
            return $planned_option_name;
        }

        $table_name = isset($ref['table_name']) ? (string) $ref['table_name'] : '';
        $primary_value = isset($ref['primary_key_value']) ? absint($ref['primary_key_value']) : 0;
        if (!($wpdb instanceof wpdb) || (string) $wpdb->options !== $table_name || $primary_value <= 0) {
            return '';
        }

        return (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT option_name FROM %i WHERE option_id = %d LIMIT 1',
                $wpdb->options,
                $primary_value
            )
        );
    }

    private function is_media_replacement_options_database_ref(array $ref)
    {
        global $wpdb;

        $table_name = (string) ($ref['table_name'] ?? '');
        $row_identity = substr((string) ($ref['row_identity'] ?? ''), 0, 191);
        $has_option_shape = 'option_id' === (string) ($ref['primary_key_column'] ?? '')
            && 'option_value' === (string) ($ref['column_name'] ?? '')
            && absint($ref['primary_key_value'] ?? 0) > 0;

        if (!$has_option_shape) {
            return false;
        }

        /*
         * Persisted row_identity is written only for WordPress option rows during
         * reference discovery. Keep recognizing that plan even if switch_to_blog()
         * changes $wpdb->options before Apply/Verify/Rollback, so it can fail closed
         * instead of falling through to the generic database-row writer.
         */
        return '' !== $row_identity
            || ($wpdb instanceof wpdb && (string) $wpdb->options === $table_name);
    }

    private function get_media_replacement_database_ref_row_identity(array $ref)
    {
        if (!$this->is_media_replacement_options_database_ref($ref)) {
            return '';
        }

        return substr((string) ($ref['row_identity'] ?? ''), 0, 191);
    }

    private function get_media_replacement_option_row_context(array $ref)
    {
        global $wpdb;

        if (!$this->is_media_replacement_options_database_ref($ref) || !($wpdb instanceof wpdb)) {
            return null;
        }

        $table_name = isset($ref['table_name']) ? $this->sanitize_media_replacement_db_identifier((string) $ref['table_name'], 191) : '';
        $option_id = absint($ref['primary_key_value'] ?? 0);
        $planned_option_name = $this->get_media_replacement_database_ref_row_identity($ref);
        if ($option_id <= 0 || '' === $planned_option_name) {
            return array(
                'valid'   => false,
                'message' => __('This planned option replacement has no persisted option-name identity. Restart Prepare Replacement before applying it.', 'ultracache'),
            );
        }

        if ('' === $table_name || (string) $wpdb->options !== $table_name) {
            return array(
                'valid'   => false,
                'message' => __('The WordPress blog context changed after Prepare Replacement. The option plan was preserved and no generic database write was attempted.', 'ultracache'),
            );
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT option_id, option_name, option_value, autoload FROM %i WHERE option_id = %d LIMIT 1',
                $table_name,
                $option_id
            ),
            ARRAY_A
        );

        if (!is_array($row) || empty($row['option_name'])) {
            return array(
                'valid'   => false,
                'message' => __('The planned WordPress option row no longer exists.', 'ultracache'),
            );
        }

        $current_option_name = (string) $row['option_name'];
        if (!hash_equals($planned_option_name, $current_option_name)) {
            return array(
                'valid'   => false,
                'message' => __('The option ID now belongs to a different option name. The newer row was preserved; restart Prepare Replacement.', 'ultracache'),
            );
        }

        return array(
            'valid'        => true,
            'option_id'    => $option_id,
            'option_name'  => $current_option_name,
            'option_value' => isset($row['option_value']) ? (string) $row['option_value'] : '',
            'autoload'     => isset($row['autoload']) ? (string) $row['autoload'] : '',
        );
    }

    private function validate_media_replacement_database_ref_identity(array $ref)
    {
        if (!$this->is_media_replacement_options_database_ref($ref)) {
            return array('valid' => true, 'message' => '');
        }

        $context = $this->get_media_replacement_option_row_context($ref);
        if (!is_array($context) || empty($context['valid'])) {
            return array(
                'valid'   => false,
                'message' => is_array($context) && isset($context['message'])
                    ? (string) $context['message']
                    : __('The planned WordPress option identity could not be verified.', 'ultracache'),
            );
        }

        return array('valid' => true, 'message' => '', 'context' => $context);
    }

    private function is_media_replacement_option_autoloaded($autoload)
    {
        $autoload = (string) $autoload;
        if (function_exists('wp_autoload_values_to_autoload')) {
            return in_array($autoload, (array) wp_autoload_values_to_autoload(), true);
        }

        return in_array($autoload, array('yes', 'on', 'auto-on', 'auto'), true);
    }

    private function invalidate_media_replacement_option_runtime_cache($option_name)
    {
        $option_name = (string) $option_name;
        if ('' === $option_name) {
            return;
        }

        wp_cache_delete($option_name, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    private function prepare_media_replacement_option_lifecycle_value($option_name, $old_raw_value, $new_raw_value)
    {
        $option_name = (string) $option_name;
        $old_value = maybe_unserialize((string) $old_raw_value);
        $new_value = maybe_unserialize((string) $new_raw_value);

        if (is_object($old_value)) {
            $old_value = clone $old_value;
        }
        if (is_object($new_value)) {
            $new_value = clone $new_value;
        }

        $new_value = sanitize_option($option_name, $new_value);
        $new_value = apply_filters("pre_update_option_{$option_name}", $new_value, $old_value, $option_name);
        $new_value = apply_filters('pre_update_option', $new_value, $option_name, $old_value);
        $filtered_raw_value = maybe_serialize($new_value);

        if (!is_string($filtered_raw_value) || !hash_equals((string) $new_raw_value, $filtered_raw_value)) {
            return array(
                'valid'   => false,
                'message' => __('A WordPress option sanitization or pre-update filter changed the planned replacement value. No database value was written.', 'ultracache'),
            );
        }

        return array(
            'valid'     => true,
            'old_value' => $old_value,
            'new_value' => $new_value,
        );
    }

    private function compare_and_swap_media_replacement_option_value(array $ref, $expected_value, $new_value)
    {
        global $wpdb;

        $context = $this->get_media_replacement_option_row_context($ref);
        if (!is_array($context) || empty($context['valid'])) {
            return array(
                'updated'  => false,
                'conflict' => false,
                'error'    => true,
                'message'  => is_array($context) && isset($context['message'])
                    ? (string) $context['message']
                    : __('The WordPress option row could not be resolved.', 'ultracache'),
            );
        }

        if (!hash_equals((string) $expected_value, (string) $context['option_value'])) {
            return array(
                'updated'  => false,
                'conflict' => true,
                'error'    => false,
                'message'  => __('The WordPress option value changed after it was read. The newer value was preserved.', 'ultracache'),
            );
        }

        $lifecycle = $this->prepare_media_replacement_option_lifecycle_value(
            (string) $context['option_name'],
            (string) $expected_value,
            (string) $new_value
        );
        if (empty($lifecycle['valid'])) {
            return array(
                'updated'  => false,
                'conflict' => false,
                'error'    => true,
                'message'  => isset($lifecycle['message']) ? (string) $lifecycle['message'] : __('The WordPress option lifecycle rejected the planned value.', 'ultracache'),
            );
        }

        /*
         * Match the native update_option() lifecycle: the generic pre-update
         * action runs immediately before the database mutation, while the
         * option-specific and generic updated actions are published only after
         * the atomic write and runtime cache verification succeed.
         */
        do_action(
            'update_option',
            (string) $context['option_name'],
            $lifecycle['old_value'],
            $lifecycle['new_value']
        );

        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET option_value = %s WHERE option_id = %d AND option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)',
                $wpdb->options,
                (string) $new_value,
                (int) $context['option_id'],
                (string) $context['option_name'],
                (string) $expected_value
            )
        );

        if (false === $updated) {
            return array('updated' => false, 'conflict' => false, 'error' => true, 'message' => __('The WordPress option row could not be updated.', 'ultracache'));
        }
        if (0 === (int) $updated) {
            return array('updated' => false, 'conflict' => true, 'error' => false, 'message' => __('The WordPress option row changed before the atomic update. The newer value was preserved.', 'ultracache'));
        }

        $this->invalidate_media_replacement_option_runtime_cache((string) $context['option_name']);

        return array(
            'updated'        => true,
            'conflict'       => false,
            'error'          => false,
            'option_write'   => true,
            'option_name'    => (string) $context['option_name'],
            'autoload'       => (string) $context['autoload'],
            'old_value'      => $lifecycle['old_value'],
            'new_value'      => $lifecycle['new_value'],
            'expected_value' => (string) $expected_value,
            'written_value'  => (string) $new_value,
            'message'        => '',
        );
    }

    private function verify_media_replacement_option_runtime_state(array $ref, $expected_raw_value)
    {
        if (!$this->is_media_replacement_options_database_ref($ref)) {
            return array('verified' => true, 'message' => '');
        }

        $context = $this->get_media_replacement_option_row_context($ref);
        if (!is_array($context) || empty($context['valid'])) {
            return array(
                'verified' => false,
                'message'  => is_array($context) && isset($context['message'])
                    ? (string) $context['message']
                    : __('The WordPress option identity could not be verified.', 'ultracache'),
            );
        }

        $expected_raw_value = (string) $expected_raw_value;
        if (!hash_equals($expected_raw_value, (string) $context['option_value'])) {
            return array('verified' => false, 'message' => __('The WordPress option database value does not match the expected replacement value.', 'ultracache'));
        }

        $option_name = (string) $context['option_name'];

        if ($this->is_media_replacement_option_autoloaded((string) $context['autoload'])) {
            $alloptions = wp_load_alloptions();
            if (!is_array($alloptions) || !array_key_exists($option_name, $alloptions) || !hash_equals($expected_raw_value, (string) $alloptions[$option_name])) {
                return array('verified' => false, 'message' => __('The autoloaded WordPress option cache did not contain the exact replacement value.', 'ultracache'));
            }
        } else {
            get_option($option_name, null);
            $found = false;
            $cached_value = wp_cache_get($option_name, 'options', false, $found);
            if (!$found || !hash_equals($expected_raw_value, (string) $cached_value)) {
                return array('verified' => false, 'message' => __('The WordPress option cache did not contain the exact replacement value.', 'ultracache'));
            }
        }

        $notoptions = wp_cache_get('notoptions', 'options');
        if (is_array($notoptions) && isset($notoptions[$option_name])) {
            return array('verified' => false, 'message' => __('The WordPress notoptions cache still marked the replaced option as missing.', 'ultracache'));
        }

        return array('verified' => true, 'message' => '');
    }

    private function finalize_media_replacement_option_write(array $write_result)
    {
        if (empty($write_result['option_write']) || empty($write_result['option_name'])) {
            return;
        }

        $option_name = (string) $write_result['option_name'];
        $old_value = $write_result['old_value'] ?? null;
        $new_value = $write_result['new_value'] ?? null;

        do_action("update_option_{$option_name}", $old_value, $new_value, $option_name);
        do_action('updated_option', $option_name, $old_value, $new_value);
    }

    private function is_media_replacement_internal_database_ref(array $ref)
    {
        $table_name = isset($ref['table_name']) ? (string) $ref['table_name'] : '';
        if ($this->is_media_replacement_ultracache_owned_database_table_name($table_name)) {
            return true;
        }

        return $this->is_media_replacement_ultracache_owned_option_name(
            $this->get_media_replacement_database_ref_option_name($ref)
        );
    }

    private function is_media_replacement_allowed_database_table_name($table_name)
    {
        global $wpdb;

        $table_name = $this->sanitize_media_replacement_db_identifier((string) $table_name, 191);
        if (
            '' === $table_name
            || !($wpdb instanceof wpdb)
            || $this->is_media_replacement_own_table_name($table_name)
            || $this->is_media_replacement_ultracache_owned_database_table_name($table_name)
        ) {
            return false;
        }

        $prefix = (string) $wpdb->prefix;
        if ('' !== $prefix && 0 === strpos($table_name, $prefix)) {
            return true;
        }

        /**
         * Allows developers to explicitly include additional tables in Media Library replacement DB scanning.
         *
         * The default scope remains the current WordPress install tables only ($wpdb->prefix).
         * Returning true here should be reserved for intentionally owned application tables in the same schema.
         *
         * @param bool   $allowed    Whether this non-prefixed table is allowed.
         * @param string $table_name Sanitized table name.
         * @param string $prefix     Current WordPress table prefix.
         */
        return (bool) apply_filters('ultracache_media_replacement_database_table_allowed', false, $table_name, $prefix);
    }

    private function is_media_replacement_reference_indexable_column_type($column_type)
    {
        $column_type = strtolower((string) $column_type);
        if ('' === $column_type) {
            return false;
        }

        return (bool) preg_match('/\b(char|varchar|text|tinytext|mediumtext|longtext|json)\b/', $column_type);
    }

    private function get_media_replacement_upload_path_fragment($relative_path)
    {
        $uploads = function_exists('ultracache_uploads_base_info') ? ultracache_uploads_base_info() : wp_upload_dir(null, false);
        if (empty($uploads['baseurl'])) {
            return '';
        }

        $base_path = wp_parse_url((string) $uploads['baseurl'], PHP_URL_PATH);
        $base_path = is_string($base_path) ? trim(str_replace('\\', '/', $base_path), '/') : '';
        $relative_path = ltrim(str_replace('\\', '/', (string) $relative_path), '/');

        if ('' === $base_path || '' === $relative_path) {
            return '';
        }

        return '/' . trim($base_path . '/' . $relative_path, '/');
    }

    private function get_media_replacement_reference_fragments(array $row)
    {
        $old_url      = isset($row['old_url']) ? esc_url_raw((string) $row['old_url']) : '';
        $new_url      = isset($row['new_url']) ? esc_url_raw((string) $row['new_url']) : '';
        $old_relative = isset($row['old_relative_path']) ? ltrim(str_replace('\\', '/', (string) $row['old_relative_path']), '/') : '';
        $new_relative = isset($row['new_relative_path']) ? ltrim(str_replace('\\', '/', (string) $row['new_relative_path']), '/') : '';

        $fragments = array();
        if ('' !== $old_url && '' !== $new_url) {
            $fragments[] = array('old' => $old_url, 'new' => $new_url);
            $fragments[] = array('old' => str_replace('/', '\\/', $old_url), 'new' => str_replace('/', '\\/', $new_url));
        }

        if ('' !== $old_relative && '' !== $new_relative) {
            $fragments[] = array('old' => $old_relative, 'new' => $new_relative);
            $old_upload_path = $this->get_media_replacement_upload_path_fragment($old_relative);
            $new_upload_path = $this->get_media_replacement_upload_path_fragment($new_relative);
            if ('' !== $old_upload_path && '' !== $new_upload_path) {
                $fragments[] = array('old' => $old_upload_path, 'new' => $new_upload_path);
                $fragments[] = array('old' => str_replace('/', '\\/', $old_upload_path), 'new' => str_replace('/', '\\/', $new_upload_path));
            }
        }

        $unique = array();
        foreach ($fragments as $fragment) {
            $old = isset($fragment['old']) ? (string) $fragment['old'] : '';
            $new = isset($fragment['new']) ? (string) $fragment['new'] : '';
            if ('' === $old || '' === $new || $old === $new) {
                continue;
            }
            $unique[md5($old)] = array('old' => $old, 'new' => $new);
        }

        return array_values($unique);
    }

    private function is_media_replacement_json_like_value($value)
    {
        $value = trim((string) $value);
        if ('' === $value || !in_array(substr($value, 0, 1), array('{', '['), true)) {
            return false;
        }

        json_decode($value, true);
        return JSON_ERROR_NONE === json_last_error();
    }


    private function get_media_replacement_ref_index_default_state()
    {
        return array(
            'status'                => 'idle',
            'cursor_spec_index'     => 0,
            'cursor_offset'         => 0,
            'cursor_primary_value'  => '',
            'specs_hash'            => '',
            'total_specs'           => 0,
            'scanned_columns'       => 0,
            'scanned_rows'          => 0,
            'indexed_refs'          => 0,
            'serialized_refs'       => 0,
            'json_refs'             => 0,
            'current_table'         => '',
            'current_column'        => '',
            'current_pagination'    => '',
            'last_query_ms'         => 0,
            'last_batch_rows'       => 0,
            'last_batch_refs'       => 0,
            'created_at'            => '',
            'updated_at'            => '',
            'completed_at'          => '',
        );
    }

    private function normalize_media_replacement_ref_index_state($state)
    {
        $state = is_array($state) ? $state : array();
        $state = array_merge($this->get_media_replacement_ref_index_default_state(), $state);
        $state['status']               = in_array((string) $state['status'], array('idle', 'indexing', 'completed', 'failed'), true) ? (string) $state['status'] : 'idle';
        $state['cursor_spec_index']    = max(0, absint($state['cursor_spec_index']));
        $state['cursor_offset']        = max(0, absint($state['cursor_offset']));
        $state['cursor_primary_value'] = substr(sanitize_text_field((string) $state['cursor_primary_value']), 0, 191);
        $state['specs_hash']           = preg_match('/^[a-f0-9]{32}$/', (string) $state['specs_hash']) ? (string) $state['specs_hash'] : '';
        $state['total_specs']          = max(0, absint($state['total_specs']));
        $state['scanned_columns']      = max(0, (int) $state['scanned_columns']);
        $state['scanned_rows']         = max(0, (int) $state['scanned_rows']);
        $state['indexed_refs']         = max(0, (int) $state['indexed_refs']);
        $state['serialized_refs']      = max(0, (int) $state['serialized_refs']);
        $state['json_refs']            = max(0, (int) $state['json_refs']);
        $state['current_table']        = $this->sanitize_media_replacement_db_identifier((string) $state['current_table'], 191);
        $state['current_column']       = $this->sanitize_media_replacement_db_identifier((string) $state['current_column'], 64);
        $state['current_pagination']   = in_array((string) $state['current_pagination'], array('keyset', 'offset'), true) ? (string) $state['current_pagination'] : '';
        $state['last_query_ms']        = max(0, (int) $state['last_query_ms']);
        $state['last_batch_rows']      = max(0, (int) $state['last_batch_rows']);
        $state['last_batch_refs']      = max(0, (int) $state['last_batch_refs']);
        return $state;
    }

    private function get_media_replacement_ref_index_state()
    {
        return $this->normalize_media_replacement_ref_index_state($this->get_media_replacement_workflow_section('database_index_scan'));
    }

    private function update_media_replacement_ref_index_state(array $state)
    {
        $state = $this->normalize_media_replacement_ref_index_state($state);
        $this->update_media_replacement_workflow_section('database_index_scan', $state);
        return $state;
    }

    private function reset_media_replacement_ref_index_registry($unused_context = '')
    {
        global $wpdb;
        $refs_table      = $this->get_media_replacement_refs_table_name();
        $ref_index_table = $this->get_media_replacement_ref_index_table_name();
        if ('' === $refs_table || '' === $ref_index_table || !($wpdb instanceof wpdb)) {
            return false;
        }
        $refs_deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE status IN (%s, %s, %s)', $refs_table, 'pending', 'failed', 'verify_failed'));
        $index_deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i', $ref_index_table));
        return false !== $refs_deleted && false !== $index_deleted;
    }


    private function get_media_replacement_database_specs_hash(array $specs)
    {
        return md5((string) wp_json_encode(array_values($specs)));
    }

    private function save_media_replacement_database_reference_specs(array $specs)
    {
        $manifest = array(
            'initialized' => true,
            'specs_hash'  => $this->get_media_replacement_database_specs_hash($specs),
            'specs'       => array_values($specs),
            'created_at'  => current_time('mysql', true),
        );
        $this->update_media_replacement_workflow_section('database_reference_specs', $manifest);
        return $manifest;
    }

    private function get_saved_media_replacement_database_reference_specs()
    {
        $saved = $this->get_media_replacement_workflow_section('database_reference_specs');
        if (empty($saved['initialized'])) {
            return array();
        }
        $specs = isset($saved['specs']) && is_array($saved['specs']) ? array_values($saved['specs']) : array();
        $hash = isset($saved['specs_hash']) && preg_match('/^[a-f0-9]{32}$/', (string) $saved['specs_hash']) ? (string) $saved['specs_hash'] : '';
        if ('' === $hash || !hash_equals($hash, $this->get_media_replacement_database_specs_hash($specs))) {
            return array();
        }
        return array(
            'initialized' => true,
            'specs_hash'  => $hash,
            'specs'       => $specs,
        );
    }

    private function clear_media_replacement_database_reference_specs()
    {
        $this->clear_media_replacement_workflow_section('database_reference_specs');
    }

    private function get_media_replacement_database_reference_specs()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return array();
        }

        $table_rows = $wpdb->get_results('SHOW FULL TABLES', ARRAY_N);
        if (!is_array($table_rows)) {
            return array();
        }

        $specs = array();
        foreach ($table_rows as $table_row) {
            $table_name = isset($table_row[0]) ? (string) $table_row[0] : '';
            $table_type = isset($table_row[1]) ? strtoupper((string) $table_row[1]) : 'BASE TABLE';
            if ('' === $table_name || 'BASE TABLE' !== $table_type || !$this->is_media_replacement_allowed_database_table_name($table_name)) {
                continue;
            }

            $columns = $wpdb->get_results($wpdb->prepare('SHOW FULL COLUMNS FROM %i', $table_name), ARRAY_A);
            if (!is_array($columns) || empty($columns)) {
                continue;
            }

            $primary_columns = array();
            foreach ($columns as $column) {
                if (!empty($column['Key']) && 'PRI' === strtoupper((string) $column['Key'])) {
                    $primary_columns[] = array(
                        'field' => isset($column['Field']) ? (string) $column['Field'] : '',
                        'type'  => isset($column['Type']) ? (string) $column['Type'] : '',
                    );
                }
            }

            $primary_column = '';
            $primary_type = '';
            if (1 === count($primary_columns)) {
                $primary_column = (string) ($primary_columns[0]['field'] ?? '');
                $primary_type = (string) ($primary_columns[0]['type'] ?? '');
            }
            $pagination = '' !== $primary_column && preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)\b/i', $primary_type) ? 'keyset' : 'offset';

            foreach ($columns as $column) {
                $field = isset($column['Field']) ? (string) $column['Field'] : '';
                $type  = isset($column['Type']) ? (string) $column['Type'] : '';
                if ('' === $field || !$this->is_media_replacement_reference_indexable_column_type($type)) {
                    continue;
                }

                $specs[] = array(
                    'table'        => $table_name,
                    'primary'      => $primary_column,
                    'primary_type' => $primary_type,
                    'pagination'   => $pagination,
                    'column'       => $field,
                    'type'         => $type,
                );
            }
        }

        usort($specs, static function ($left, $right) {
            $left_key = (string) ($left['table'] ?? '') . "\0" . (string) ($left['column'] ?? '');
            $right_key = (string) ($right['table'] ?? '') . "\0" . (string) ($right['column'] ?? '');
            return strcmp($left_key, $right_key);
        });

        return $specs;
    }

    private function clean_media_replacement_reference_fragment($fragment)
    {
        $fragment = html_entity_decode((string) $fragment, ENT_QUOTES, get_bloginfo('charset') ? get_bloginfo('charset') : 'UTF-8');
        $fragment = trim($fragment);
        $fragment = preg_replace('/^url\((.*)$/i', '$1', $fragment);
        $fragment = trim((string) $fragment, " \t\n\r\0\x0B\"'()[]{}<>,;");
        $fragment = preg_replace('/[),.;]+$/', '', (string) $fragment);
        return trim((string) $fragment);
    }


    private function normalize_media_replacement_reference_fragment_for_index($fragment)
    {
        $raw = $this->clean_media_replacement_reference_fragment($fragment);
        if ('' === $raw) {
            return array('raw' => '', 'normalized' => '', 'match' => '', 'type' => '');
        }

        $normalized = str_replace(array(chr(92) . chr(92) . '/', chr(92) . '/'), '/', $raw);
        $normalized = html_entity_decode($normalized, ENT_QUOTES, get_bloginfo('charset') ? get_bloginfo('charset') : 'UTF-8');
        $normalized = trim($normalized);
        $normalized = preg_replace('/[?#].*$/', '', $normalized);
        $normalized = str_replace(chr(92), '/', (string) $normalized);
        $normalized = preg_replace('#(?<!:)//+#', '/', (string) $normalized);

        $type = 'relative_path';
        $path_for_match = $normalized;
        if (preg_match('#^(?:https?:)?//#i', $normalized)) {
            $type = 'url';
            $parsed_path = wp_parse_url($normalized, PHP_URL_PATH);
            $path_for_match = is_string($parsed_path) ? $parsed_path : $normalized;
        } elseif (0 === strpos($normalized, '/')) {
            $type = 'absolute_path';
            $path_for_match = $normalized;
        }

        $uploads = function_exists('ultracache_uploads_base_info') ? ultracache_uploads_base_info() : wp_upload_dir(null, false);
        $upload_base_path = '';
        if (!empty($uploads['baseurl'])) {
            $upload_base_path = wp_parse_url((string) $uploads['baseurl'], PHP_URL_PATH);
            $upload_base_path = is_string($upload_base_path) ? trim(str_replace(chr(92), '/', $upload_base_path), '/') : '';
        }

        $match = ltrim(str_replace(chr(92), '/', (string) $path_for_match), '/');
        if ('' !== $upload_base_path) {
            $needle = trim($upload_base_path, '/') . '/';
            $pos = strpos($match, $needle);
            if (false !== $pos) {
                $match = substr($match, $pos + strlen($needle));
                $type = 'uploads_relative_path';
            }
        }

        if ('' === $match) {
            $match = ltrim(str_replace(chr(92), '/', (string) $normalized), '/');
        }

        return array(
            'raw'        => $raw,
            'normalized' => $normalized,
            'match'      => $match,
            'type'       => $type,
        );
    }


    private function is_media_replacement_reference_token_delimiter($character)
    {
        if ('' === $character) {
            return true;
        }

        $ord = ord($character);
        return $ord <= 32
            || '"' === $character
            || "'" === $character
            || '<' === $character
            || '>' === $character;
    }

    private function extract_media_replacement_image_references_from_value($value)
    {
        $value = (string) $value;
        $value_length = strlen($value);
        if ($value_length < 4) {
            return array();
        }

        $references = array();
        $position = 0;
        $max_fragment_length = 2000;

        while ($position < $value_length) {
            $dot_offset = strpos($value, '.', $position);
            if (false === $dot_offset || $dot_offset + 3 >= $value_length) {
                break;
            }

            $extension_length = 0;
            $extension_four = strtolower(substr($value, $dot_offset, 4));
            if ('.jpg' === $extension_four || '.png' === $extension_four) {
                $extension_length = 4;
            } elseif ($dot_offset + 4 < $value_length && '.jpeg' === strtolower(substr($value, $dot_offset, 5))) {
                $extension_length = 5;
            }

            if (0 === $extension_length) {
                $position = $dot_offset + 1;
                continue;
            }

            $fragment_end = $dot_offset + $extension_length;
            $forward_limit = min($value_length, $dot_offset + $max_fragment_length + 1);
            $cursor = $fragment_end;

            if ($cursor < $value_length && '?' === $value[$cursor]) {
                $cursor++;
                while ($cursor < $forward_limit
                    && '#' !== $value[$cursor]
                    && !$this->is_media_replacement_reference_token_delimiter($value[$cursor])) {
                    $cursor++;
                }
            }

            if ($cursor < $value_length && '#' === $value[$cursor]) {
                $cursor++;
                while ($cursor < $forward_limit
                    && !$this->is_media_replacement_reference_token_delimiter($value[$cursor])) {
                    $cursor++;
                }
            }

            if ($cursor >= $forward_limit && $cursor < $value_length
                && !$this->is_media_replacement_reference_token_delimiter($value[$cursor])) {
                $position = $dot_offset + $extension_length;
                continue;
            }
            $fragment_end = $cursor;

            $fragment_start = $dot_offset;
            $backward_limit = max(0, $fragment_end - $max_fragment_length);
            while ($fragment_start > $backward_limit
                && !$this->is_media_replacement_reference_token_delimiter($value[$fragment_start - 1])) {
                $fragment_start--;
            }

            if ($fragment_start === $backward_limit && $backward_limit > 0
                && !$this->is_media_replacement_reference_token_delimiter($value[$backward_limit - 1])) {
                $position = $dot_offset + $extension_length;
                continue;
            }

            $fragment_length = $fragment_end - $fragment_start;
            if ($fragment_length <= 0 || $fragment_length > $max_fragment_length) {
                $position = $dot_offset + $extension_length;
                continue;
            }

            $fragment = substr($value, $fragment_start, $fragment_length);
            $normalized = $this->normalize_media_replacement_reference_fragment_for_index($fragment);
            if (empty($normalized['raw']) || empty($normalized['match'])) {
                $position = $dot_offset + $extension_length;
                continue;
            }

            $raw = (string) $normalized['raw'];
            $data_offset = stripos($raw, 'data:');
            if (0 === $data_offset
                || (false !== $data_offset
                    && $data_offset > 0
                    && in_array($raw[$data_offset - 1], array('=', ':', '('), true))) {
                $position = $dot_offset + $extension_length;
                continue;
            }

            $match = (string) $normalized['match'];
            $match_length = strlen($match);
            if ($match_length < 4) {
                $position = $dot_offset + $extension_length;
                continue;
            }

            $match_suffix_four = strtolower(substr($match, -4));
            $match_has_image_extension = '.jpg' === $match_suffix_four || '.png' === $match_suffix_four;
            if (!$match_has_image_extension && $match_length >= 5) {
                $match_has_image_extension = '.jpeg' === strtolower(substr($match, -5));
            }
            if (!$match_has_image_extension) {
                $position = $dot_offset + $extension_length;
                continue;
            }

            if (strlen($raw) > $max_fragment_length
                || strlen((string) $normalized['normalized']) > $max_fragment_length
                || $match_length > $max_fragment_length) {
                $position = $dot_offset + $extension_length;
                continue;
            }

            $key = md5($match . '|' . $raw);
            $references[$key] = $normalized;
            $position = $dot_offset + $extension_length;
        }

        return array_values($references);
    }

    private function insert_media_replacement_ref_index_row(array $spec, $primary_key_value, array $reference, $stored_value, $row_identity = '')
    {
        global $wpdb;

        $ref_index_table = $this->get_media_replacement_ref_index_table_name();
        if ('' === $ref_index_table || !($wpdb instanceof wpdb)) {
            return false;
        }

        $table_name = isset($spec['table']) ? (string) $spec['table'] : '';
        $primary    = isset($spec['primary']) ? (string) $spec['primary'] : '';
        $column     = isset($spec['column']) ? (string) $spec['column'] : '';

        $table_name = $this->sanitize_media_replacement_db_identifier($table_name, 191);
        $primary    = $this->sanitize_media_replacement_db_identifier($primary, 64);
        $column     = $this->sanitize_media_replacement_db_identifier($column, 64);
        $primary_key_value = substr(sanitize_text_field((string) $primary_key_value), 0, 191);
        $row_identity = substr((string) $row_identity, 0, 191);

        $raw        = isset($reference['raw']) ? (string) $reference['raw'] : '';
        $normalized = isset($reference['normalized']) ? (string) $reference['normalized'] : '';
        $match      = isset($reference['match']) ? (string) $reference['match'] : '';
        $type       = isset($reference['type']) ? sanitize_key((string) $reference['type']) : 'relative_path';
        $type       = in_array($type, array('url', 'absolute_path', 'relative_path', 'uploads_relative_path'), true) ? $type : 'relative_path';

        if ('' === $table_name || '' === $column || '' === $raw || '' === $normalized || '' === $match || !$this->is_media_replacement_allowed_database_table_name($table_name)) {
            return false;
        }

        if ('' === $primary_key_value) {
            $primary_key_value = substr(md5((string) $stored_value), 0, 32);
        }

        $now = current_time('mysql', true);
        $url_path_hash = md5($match);
        $ref_hash = md5($table_name . '|' . $primary . '|' . $primary_key_value . '|' . $row_identity . '|' . $column . '|' . $url_path_hash . '|' . md5($raw));
        $serialized = function_exists('is_serialized') && is_serialized((string) $stored_value) ? 1 : 0;
        $json_detected = $this->is_media_replacement_json_like_value($stored_value) ? 1 : 0;

        /*
         * The scan cursor may be replayed after a terminated HTTP request. INSERT IGNORE
         * preserves any already matched row and reports 1 only for a genuinely new index row.
         */
        $inserted = $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO %i (job_id, ref_hash, table_name, primary_key_column, primary_key_value, row_identity, column_name, reference_type, raw_fragment, normalized_fragment, url_path_hash, serialized, json_detected, matched_item_id, status, error_message, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %d, %d, %s, NULL, %s, %s)',
                $ref_index_table,
                '',
                $ref_hash,
                $table_name,
                $primary,
                $primary_key_value,
                $row_identity,
                $column,
                $type,
                $raw,
                $normalized,
                $url_path_hash,
                $serialized,
                $json_detected,
                0,
                'indexed',
                $now,
                $now
            )
        );

        return false === $inserted ? false : (int) $inserted;
    }

    private function get_media_replacement_database_reference_window_limit(array $spec, $remaining)
    {
        $type = strtolower((string) ($spec['type'] ?? ''));
        $remaining = max(1, min(1000, absint($remaining)));

        if (false !== strpos($type, 'longtext')) {
            return min(100, $remaining);
        }
        if (false !== strpos($type, 'mediumtext') || false !== strpos($type, 'json')) {
            return min(200, $remaining);
        }
        if (false !== strpos($type, 'text')) {
            return min(500, $remaining);
        }

        return min(1000, $remaining);
    }


    private function get_media_replacement_database_reference_candidate_rows(array $spec, $limit, $offset, $cursor_primary_value = '')
    {
        global $wpdb;

        $table = isset($spec['table']) ? (string) $spec['table'] : '';
        $column = isset($spec['column']) ? (string) $spec['column'] : '';
        $primary = isset($spec['primary']) ? (string) $spec['primary'] : '';
        $pagination = isset($spec['pagination']) ? sanitize_key((string) $spec['pagination']) : 'offset';
        $limit = max(1, min(1000, absint($limit)));
        $offset = max(0, absint($offset));
        $cursor_primary_value = substr(sanitize_text_field((string) $cursor_primary_value), 0, 191);

        if (
            !($wpdb instanceof wpdb)
            || !$this->is_media_replacement_allowed_database_table_name($table)
            || !$this->is_media_replacement_safe_identifier($column)
            || ('' !== $primary && !$this->is_media_replacement_safe_identifier($primary))
        ) {
            return array(
                'success' => false,
                'rows' => array(),
                'scanPrimaryValues' => array(),
                'scannedRows' => 0,
                'nextPrimary' => $cursor_primary_value,
                'nextOffset' => $offset,
                'exhausted' => true,
                'queryMs' => 0,
                'pagination' => $pagination,
            );
        }

        $is_attachment_meta_value = (string) $wpdb->postmeta === $table && 'meta_value' === $column;
        $is_options_value = (string) $wpdb->options === $table && 'option_value' === $column && 'option_id' === $primary;
        $use_keyset = 'keyset' === $pagination && '' !== $primary;
        $ultracache_option_like = $wpdb->esc_like('ultracache_') . '%';
        $ultracache_transient_like = $wpdb->esc_like('_transient_ultracache_') . '%';
        $ultracache_site_transient_like = $wpdb->esc_like('_site_transient_ultracache_') . '%';
        $jpg_like = '%' . $wpdb->esc_like('.jpg') . '%';
        $jpeg_like = '%' . $wpdb->esc_like('.jpeg') . '%';
        $png_like = '%' . $wpdb->esc_like('.png') . '%';
        $query_started = microtime(true);

        if ($use_keyset) {
            /*
             * Resolve one bounded physical primary-key window first. The image
             * predicate is then evaluated only inside that window, so a sparse
             * column can never make MariaDB scan an unbounded tail of the table.
             */
            if ($is_options_value) {
                if ('' !== $cursor_primary_value) {
                    $primary_rows = $wpdb->get_col(
                        $wpdb->prepare(
                            'SELECT %i FROM %i WHERE %i > %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY %i ASC LIMIT %d',
                            $primary,
                            $table,
                            $primary,
                            $cursor_primary_value,
                            $ultracache_option_like,
                            $ultracache_transient_like,
                            $ultracache_site_transient_like,
                            $primary,
                            $limit
                        )
                    );
                } else {
                    $primary_rows = $wpdb->get_col(
                        $wpdb->prepare(
                            'SELECT %i FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY %i ASC LIMIT %d',
                            $primary,
                            $table,
                            $ultracache_option_like,
                            $ultracache_transient_like,
                            $ultracache_site_transient_like,
                            $primary,
                            $limit
                        )
                    );
                }
            } elseif ($is_attachment_meta_value) {
                if ('' !== $cursor_primary_value) {
                    $primary_rows = $wpdb->get_col(
                        $wpdb->prepare(
                            'SELECT %i FROM %i WHERE %i > %s AND meta_key NOT IN (%s, %s, %s) ORDER BY %i ASC LIMIT %d',
                            $primary,
                            $table,
                            $primary,
                            $cursor_primary_value,
                            '_wp_attached_file',
                            '_wp_attachment_metadata',
                            '_wp_attachment_backup_sizes',
                            $primary,
                            $limit
                        )
                    );
                } else {
                    $primary_rows = $wpdb->get_col(
                        $wpdb->prepare(
                            'SELECT %i FROM %i WHERE meta_key NOT IN (%s, %s, %s) ORDER BY %i ASC LIMIT %d',
                            $primary,
                            $table,
                            '_wp_attached_file',
                            '_wp_attachment_metadata',
                            '_wp_attachment_backup_sizes',
                            $primary,
                            $limit
                        )
                    );
                }
            } else {
                if ('' !== $cursor_primary_value) {
                    $primary_rows = $wpdb->get_col(
                        $wpdb->prepare(
                            'SELECT %i FROM %i WHERE %i > %s ORDER BY %i ASC LIMIT %d',
                            $primary,
                            $table,
                            $primary,
                            $cursor_primary_value,
                            $primary,
                            $limit
                        )
                    );
                } else {
                    $primary_rows = $wpdb->get_col(
                        $wpdb->prepare(
                            'SELECT %i FROM %i ORDER BY %i ASC LIMIT %d',
                            $primary,
                            $table,
                            $primary,
                            $limit
                        )
                    );
                }
            }

            if (!is_array($primary_rows)) {
                return array(
                    'success' => false,
                    'rows' => array(),
                    'scanPrimaryValues' => array(),
                    'scannedRows' => 0,
                    'nextPrimary' => $cursor_primary_value,
                    'nextOffset' => $offset,
                    'exhausted' => false,
                    'queryMs' => max(0, (int) round((microtime(true) - $query_started) * 1000)),
                    'pagination' => 'keyset',
                );
            }

            $primary_rows = array_values(array_map('strval', $primary_rows));
            $scanned_rows = count($primary_rows);
            if (0 === $scanned_rows) {
                return array(
                    'success' => true,
                    'rows' => array(),
                    'scanPrimaryValues' => array(),
                    'scannedRows' => 0,
                    'nextPrimary' => $cursor_primary_value,
                    'nextOffset' => 0,
                    'exhausted' => true,
                    'queryMs' => max(0, (int) round((microtime(true) - $query_started) * 1000)),
                    'pagination' => 'keyset',
                );
            }

            $window_end = (string) end($primary_rows);
            if ($is_options_value) {
                if ('' !== $cursor_primary_value) {
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            'SELECT %i AS primary_value, %i AS scanned_value, option_name AS row_identity FROM %i WHERE %i > %s AND %i <= %s AND (%i LIKE %s OR %i LIKE %s OR %i LIKE %s) AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY %i ASC',
                            $primary,
                            $column,
                            $table,
                            $primary,
                            $cursor_primary_value,
                            $primary,
                            $window_end,
                            $column,
                            $jpg_like,
                            $column,
                            $jpeg_like,
                            $column,
                            $png_like,
                            $ultracache_option_like,
                            $ultracache_transient_like,
                            $ultracache_site_transient_like,
                            $primary
                        ),
                        ARRAY_A
                    );
                } else {
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            'SELECT %i AS primary_value, %i AS scanned_value, option_name AS row_identity FROM %i WHERE %i <= %s AND (%i LIKE %s OR %i LIKE %s OR %i LIKE %s) AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY %i ASC',
                            $primary,
                            $column,
                            $table,
                            $primary,
                            $window_end,
                            $column,
                            $jpg_like,
                            $column,
                            $jpeg_like,
                            $column,
                            $png_like,
                            $ultracache_option_like,
                            $ultracache_transient_like,
                            $ultracache_site_transient_like,
                            $primary
                        ),
                        ARRAY_A
                    );
                }
            } elseif ($is_attachment_meta_value) {
                if ('' !== $cursor_primary_value) {
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            'SELECT %i AS primary_value, %i AS scanned_value FROM %i WHERE %i > %s AND %i <= %s AND (%i LIKE %s OR %i LIKE %s OR %i LIKE %s) AND meta_key NOT IN (%s, %s, %s) ORDER BY %i ASC',
                            $primary,
                            $column,
                            $table,
                            $primary,
                            $cursor_primary_value,
                            $primary,
                            $window_end,
                            $column,
                            $jpg_like,
                            $column,
                            $jpeg_like,
                            $column,
                            $png_like,
                            '_wp_attached_file',
                            '_wp_attachment_metadata',
                            '_wp_attachment_backup_sizes',
                            $primary
                        ),
                        ARRAY_A
                    );
                } else {
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            'SELECT %i AS primary_value, %i AS scanned_value FROM %i WHERE %i <= %s AND (%i LIKE %s OR %i LIKE %s OR %i LIKE %s) AND meta_key NOT IN (%s, %s, %s) ORDER BY %i ASC',
                            $primary,
                            $column,
                            $table,
                            $primary,
                            $window_end,
                            $column,
                            $jpg_like,
                            $column,
                            $jpeg_like,
                            $column,
                            $png_like,
                            '_wp_attached_file',
                            '_wp_attachment_metadata',
                            '_wp_attachment_backup_sizes',
                            $primary
                        ),
                        ARRAY_A
                    );
                }
            } else {
                if ('' !== $cursor_primary_value) {
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            'SELECT %i AS primary_value, %i AS scanned_value FROM %i WHERE %i > %s AND %i <= %s AND (%i LIKE %s OR %i LIKE %s OR %i LIKE %s) ORDER BY %i ASC',
                            $primary,
                            $column,
                            $table,
                            $primary,
                            $cursor_primary_value,
                            $primary,
                            $window_end,
                            $column,
                            $jpg_like,
                            $column,
                            $jpeg_like,
                            $column,
                            $png_like,
                            $primary
                        ),
                        ARRAY_A
                    );
                } else {
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            'SELECT %i AS primary_value, %i AS scanned_value FROM %i WHERE %i <= %s AND (%i LIKE %s OR %i LIKE %s OR %i LIKE %s) ORDER BY %i ASC',
                            $primary,
                            $column,
                            $table,
                            $primary,
                            $window_end,
                            $column,
                            $jpg_like,
                            $column,
                            $jpeg_like,
                            $column,
                            $png_like,
                            $primary
                        ),
                        ARRAY_A
                    );
                }
            }

            return array(
                'success' => is_array($rows),
                'rows' => is_array($rows) ? $rows : array(),
                'scanPrimaryValues' => $primary_rows,
                'scannedRows' => $scanned_rows,
                'nextPrimary' => $window_end,
                'nextOffset' => 0,
                'exhausted' => $scanned_rows < $limit,
                'queryMs' => max(0, (int) round((microtime(true) - $query_started) * 1000)),
                'pagination' => 'keyset',
            );
        }

        /*
         * Unsupported table shapes retain bounded OFFSET traversal. These rows
         * are read directly because there is no stable single integer key from
         * which to construct a bounded range predicate.
         */
        if ('' !== $primary && $is_options_value) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT %i AS primary_value, %i AS scanned_value, option_name AS row_identity FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY %i ASC LIMIT %d OFFSET %d',
                    $primary,
                    $column,
                    $table,
                    $ultracache_option_like,
                    $ultracache_transient_like,
                    $ultracache_site_transient_like,
                    $primary,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } elseif ('' !== $primary && $is_attachment_meta_value) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT %i AS primary_value, %i AS scanned_value FROM %i WHERE meta_key NOT IN (%s, %s, %s) ORDER BY %i ASC LIMIT %d OFFSET %d',
                    $primary,
                    $column,
                    $table,
                    '_wp_attached_file',
                    '_wp_attachment_metadata',
                    '_wp_attachment_backup_sizes',
                    $primary,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } elseif ('' !== $primary) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT %i AS primary_value, %i AS scanned_value FROM %i ORDER BY %i ASC LIMIT %d OFFSET %d',
                    $primary,
                    $column,
                    $table,
                    $primary,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT %i AS scanned_value FROM %i LIMIT %d OFFSET %d',
                    $column,
                    $table,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        }

        $query_success = is_array($rows);
        $rows = $query_success ? $rows : array();
        return array(
            'success' => $query_success,
            'rows' => $rows,
            'scanPrimaryValues' => array(),
            'scannedRows' => count($rows),
            'nextPrimary' => $cursor_primary_value,
            'nextOffset' => $offset + count($rows),
            'exhausted' => count($rows) < $limit,
            'queryMs' => max(0, (int) round((microtime(true) - $query_started) * 1000)),
            'pagination' => 'offset',
        );
    }


    private function get_media_replacement_ref_index_summary()
    {
        global $wpdb;

        $ref_index_table = $this->get_media_replacement_ref_index_table_name();
        if ('' === $ref_index_table || !($wpdb instanceof wpdb)) {
            return array('total' => 0, 'serialized' => 0, 'json' => 0, 'matched' => 0, 'tables' => 0);
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS total_refs, SUM(serialized) AS serialized_refs, SUM(json_detected) AS json_refs, SUM(CASE WHEN matched_item_id > 0 THEN 1 ELSE 0 END) AS matched_refs FROM %i WHERE status <> %s',
                $ref_index_table,
                'excluded'
            ),
            ARRAY_A
        );

        $table_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT table_name) FROM %i WHERE status <> %s',
                $ref_index_table,
                'excluded'
            )
        );

        return array(
            'total'      => is_array($row) && isset($row['total_refs']) ? max(0, (int) $row['total_refs']) : 0,
            'serialized' => is_array($row) && isset($row['serialized_refs']) ? max(0, (int) $row['serialized_refs']) : 0,
            'json'       => is_array($row) && isset($row['json_refs']) ? max(0, (int) $row['json_refs']) : 0,
            'matched'    => is_array($row) && isset($row['matched_refs']) ? max(0, (int) $row['matched_refs']) : 0,
            'tables'     => max(0, $table_count),
        );
    }


    public function scan_media_library_replacement_database_references($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array(
                'success' => false,
                'message' => __('Media Library replacement registry tables are not available.', 'ultracache'),
            );
        }

        $args = is_array($args) ? $args : array();
        if (!$this->media_replacement_has_registry_rows()) {
            return $this->build_media_replacement_empty_registry_response(__('No Media Library replacement registry rows are available for database reference scanning.', 'ultracache'));
        }

        $limit = isset($args['limit']) ? absint($args['limit']) : 250;
        $limit = max(25, min(1000, $limit));

        $state = $this->get_media_replacement_ref_index_state();
        $start_new = 'idle' === $state['status'] || !empty($args['reset']) || !empty($args['start']);
        if ($start_new) {
            $specs = $this->get_media_replacement_database_reference_specs();
            $manifest = $this->save_media_replacement_database_reference_specs($specs);
            $specs_hash = isset($manifest['specs_hash']) ? (string) $manifest['specs_hash'] : '';
            $this->reset_media_replacement_ref_index_registry();
            $state = $this->update_media_replacement_ref_index_state(array(
                'status'               => 'indexing',
                'cursor_spec_index'    => 0,
                'cursor_offset'        => 0,
                'cursor_primary_value' => '',
                'specs_hash'           => $specs_hash,
                'total_specs'          => count($specs),
                'scanned_columns'      => 0,
                'scanned_rows'         => 0,
                'indexed_refs'         => 0,
                'serialized_refs'      => 0,
                'json_refs'            => 0,
                'created_at'           => current_time('mysql', true),
                'updated_at'           => current_time('mysql', true),
                'completed_at'         => '',
            ));
        } else {
            $manifest = $this->get_saved_media_replacement_database_reference_specs();
            if (empty($manifest['initialized'])) {
                return array(
                    'success' => false,
                    'blocked' => true,
                    'status'  => 'database_scan_manifest_missing',
                    'message' => __('The persisted database scan manifest is missing or invalid. Restart Prepare to rebuild it.', 'ultracache'),
                    'hasMore' => false,
                );
            }
            $specs = isset($manifest['specs']) && is_array($manifest['specs']) ? $manifest['specs'] : array();
            $specs_hash = isset($manifest['specs_hash']) ? (string) $manifest['specs_hash'] : '';
        }

        $total_specs = count($specs);
        if ((int) $state['total_specs'] !== $total_specs || '' === $state['specs_hash'] || !hash_equals((string) $state['specs_hash'], (string) $specs_hash)) {
            return array(
                'success' => false,
                'blocked' => true,
                'status'  => 'database_scan_spec_changed',
                'message' => __('The persisted database scan specification changed. Restart Prepare to create a consistent plan.', 'ultracache'),
                'hasMore' => false,
            );
        }
        if ('completed' === $state['status']) {
            $summary = $this->get_media_replacement_ref_index_summary();
            return array(
                'success' => true,
                /* translators: %d: indexed database reference count. */
                'message' => sprintf(__('Database reference index is already complete with %d indexed JPG/PNG references.', 'ultracache'), (int) $summary['total']),
                'status' => 'ref_indexed',
                'hasMore' => false,
                'batchScannedRows' => 0,
                'batchIndexedRefs' => 0,
                'refsScanned' => $total_specs,
                'remainingRefsScan' => 0,
                'totalReferenceColumns' => $total_specs,
                'referencesFound' => (int) $summary['total'],
                'serializedRefs' => (int) $summary['serialized'],
                'jsonRefs' => (int) $summary['json'],
                'databaseReferenceIndexReady' => true,
            );
        }

        if (empty($specs)) {
            $state['status'] = 'completed';
            $state['completed_at'] = current_time('mysql', true);
            $state['updated_at'] = current_time('mysql', true);
            $this->update_media_replacement_ref_index_state($state);
            return array(
                'success' => true,
                'message' => __('Database reference index found no text-like WordPress table columns to scan.', 'ultracache'),
                'status' => 'ref_indexed',
                'hasMore' => false,
                'referencesFound' => 0,
                'serializedRefs' => 0,
                'jsonRefs' => 0,
                'refsScanned' => 0,
                'remainingRefsScan' => 0,
                'totalReferenceColumns' => 0,
                'databaseReferenceIndexReady' => true,
            );
        }

        $remaining = $limit;
        $batch_rows = 0;
        $batch_refs = 0;
        $batch_serialized = 0;
        $batch_json = 0;
        $batch_columns_completed = 0;
        $batch_query_ms = 0;
        $iterations = 0;
        $last_pagination = 'offset';
        $current_table = '';
        $current_column = '';

        while ($remaining > 0 && $state['cursor_spec_index'] < $total_specs && $iterations < 25) {
            $spec = isset($specs[$state['cursor_spec_index']]) ? $specs[$state['cursor_spec_index']] : null;
            if (!is_array($spec)) {
                $state['cursor_spec_index']++;
                $state['cursor_offset'] = 0;
                $state['cursor_primary_value'] = '';
                $iterations++;
                continue;
            }

            $pagination = isset($spec['pagination']) && 'keyset' === (string) $spec['pagination'] ? 'keyset' : 'offset';
            $last_pagination = $pagination;
            $current_table = $this->sanitize_media_replacement_db_identifier((string) ($spec['table'] ?? ''), 191);
            $current_column = $this->sanitize_media_replacement_db_identifier((string) ($spec['column'] ?? ''), 64);
            $query_limit = $this->get_media_replacement_database_reference_window_limit($spec, $remaining);
            $page = $this->get_media_replacement_database_reference_candidate_rows(
                $spec,
                $query_limit,
                $state['cursor_offset'],
                $state['cursor_primary_value']
            );

            if (empty($page['success'])) {
                return array(
                    'success' => false,
                    'blocked' => true,
                    'status' => 'database_scan_query_failed',
                    'message' => sprintf(
                        /* translators: 1: database table, 2: database column. */
                        __('The bounded database reference query failed for %1$s.%2$s. Restart Prepare after correcting the database error.', 'ultracache'),
                        $current_table,
                        $current_column
                    ),
                        'hasMore' => false,
                );
            }

            $batch_query_ms += max(0, (int) ($page['queryMs'] ?? 0));
            $rows = isset($page['rows']) && is_array($page['rows']) ? $page['rows'] : array();
            $processed_candidates = 0;
            $last_processed_primary = '';

            foreach ($rows as $db_row) {
                $stored_value = isset($db_row['scanned_value']) ? (string) $db_row['scanned_value'] : '';
                $primary_value = isset($db_row['primary_value']) ? (string) $db_row['primary_value'] : '';
                if ('' === $primary_value) {
                    $primary_value = 'offset-' . (string) ($state['cursor_offset'] + $processed_candidates);
                } elseif ('keyset' === $pagination) {
                    $last_processed_primary = $primary_value;
                }

                if ('' !== $stored_value && preg_match('/\.(?:jpe?g|png)/i', $stored_value)) {
                    $references = $this->extract_media_replacement_image_references_from_value($stored_value);
                    $row_serialized = function_exists('is_serialized') && is_serialized($stored_value);
                    $row_json = $this->is_media_replacement_json_like_value($stored_value);
                    foreach ($references as $reference) {
                        $inserted = $this->insert_media_replacement_ref_index_row(
                                        $spec,
                            $primary_value,
                            $reference,
                            $stored_value,
                            isset($db_row['row_identity']) ? (string) $db_row['row_identity'] : ''
                        );
                        if (false === $inserted) {
                            return array(
                                'success' => false,
                                'blocked' => true,
                                'status' => 'database_reference_index_write_failed',
                                'message' => __('A database reference could not be persisted in the replacement index. Restart Prepare after correcting the database error.', 'ultracache'),
                                                'hasMore' => false,
                            );
                        }
                        if ($inserted > 0) {
                            $batch_refs++;
                            if ($row_serialized) {
                                $batch_serialized++;
                            }
                            if ($row_json) {
                                $batch_json++;
                            }
                        }
                    }
                }

                $processed_candidates++;
            }

            $candidate_count = count($rows);
            $all_candidates_processed = $processed_candidates >= $candidate_count;
            $advanced_rows = 0;

            if ('keyset' === $pagination) {
                $scan_primary_values = isset($page['scanPrimaryValues']) && is_array($page['scanPrimaryValues'])
                    ? array_values(array_map('strval', $page['scanPrimaryValues']))
                    : array();

                if ($all_candidates_processed) {
                    $state['cursor_primary_value'] = (string) ($page['nextPrimary'] ?? $state['cursor_primary_value']);
                    $advanced_rows = max(0, (int) ($page['scannedRows'] ?? count($scan_primary_values)));
                } elseif ('' !== $last_processed_primary) {
                    $state['cursor_primary_value'] = $last_processed_primary;
                    $position = array_search($last_processed_primary, $scan_primary_values, true);
                    $advanced_rows = false === $position ? 1 : ((int) $position + 1);
                }
            } else {
                $advanced_rows = $processed_candidates;
                $state['cursor_offset'] += $advanced_rows;
            }

            $advanced_rows = min($remaining, max(0, $advanced_rows));
            $batch_rows += $advanced_rows;
            $state['scanned_rows'] += $advanced_rows;
            $remaining -= $advanced_rows;

            if (!$all_candidates_processed) {
                break;
            }

            if (!empty($page['exhausted'])) {
                $state['cursor_spec_index']++;
                $state['cursor_offset'] = 0;
                $state['cursor_primary_value'] = '';
                $state['scanned_columns']++;
                $batch_columns_completed++;
            } elseif (0 === $advanced_rows) {
                return array(
                    'success' => false,
                    'blocked' => true,
                    'status' => 'database_scan_no_progress',
                    'message' => sprintf(
                        /* translators: 1: database table, 2: database column. */
                        __('The bounded database reference cursor made no progress for %1$s.%2$s. Restart Prepare after checking the table key.', 'ultracache'),
                        $current_table,
                        $current_column
                    ),
                        'hasMore' => false,
                );
            }

            $iterations++;
        }

        $state['indexed_refs'] += $batch_refs;
        $state['serialized_refs'] += $batch_serialized;
        $state['json_refs'] += $batch_json;
        $state['current_table'] = $current_table;
        $state['current_column'] = $current_column;
        $state['current_pagination'] = $last_pagination;
        $state['last_query_ms'] = $batch_query_ms;
        $state['last_batch_rows'] = $batch_rows;
        $state['last_batch_refs'] = $batch_refs;
        $has_more = $state['cursor_spec_index'] < $total_specs;
        if (!$has_more) {
            $live_specs = $this->get_media_replacement_database_reference_specs();
            $live_hash = $this->get_media_replacement_database_specs_hash($live_specs);
            if (!hash_equals((string) $state['specs_hash'], $live_hash)) {
                $state['status'] = 'failed';
                $state['updated_at'] = current_time('mysql', true);
                $this->update_media_replacement_ref_index_state($state);
                return array(
                    'success' => false,
                    'blocked' => true,
                    'status' => 'database_scan_schema_changed',
                    'message' => __('The database schema changed while the reference index was running. Restart Prepare to rebuild a consistent plan.', 'ultracache'),
                        'hasMore' => false,
                );
            }
            $state['status'] = 'completed';
            $state['completed_at'] = current_time('mysql', true);
            $state['cursor_spec_index'] = $total_specs;
            $state['cursor_offset'] = 0;
            $state['cursor_primary_value'] = '';
            $state['current_table'] = '';
            $state['current_column'] = '';
            $state['current_pagination'] = '';
        } else {
            $state['status'] = 'indexing';
        }
        $state['updated_at'] = current_time('mysql', true);
        $state = $this->update_media_replacement_ref_index_state($state);

        if ($has_more) {
            $completed_tables = array();
            foreach (array_slice($specs, 0, min($state['cursor_spec_index'], $total_specs)) as $completed_spec) {
                if (!empty($completed_spec['table'])) {
                    $completed_tables[(string) $completed_spec['table']] = true;
                }
            }
            $summary = array(
                'total' => (int) $state['indexed_refs'],
                'serialized' => (int) $state['serialized_refs'],
                'json' => (int) $state['json_refs'],
                'matched' => 0,
                'tables' => count($completed_tables),
            );
        } else {
            $summary = $this->get_media_replacement_ref_index_summary();
        }

        $progress = $total_specs > 0 ? min(100, round((min($state['cursor_spec_index'], $total_specs) / $total_specs) * 100, 1)) : 100;
        return array(
            'success'             => true,
            'message'             => $has_more
                /* translators: %1$d: completed column count; %2$d: total column count; %3$d: indexed reference count. */
                ? sprintf(
                    /* translators: 1: completed columns, 2: total columns, 3: rows scanned in this request, 4: total rows scanned, 5: indexed references, 6: current table, 7: current column, 8: current keyset/offset cursor. */
                    __('Database reference index: %1$d of %2$d text-like columns complete; %3$d rows scanned in this request, %4$d total; %5$d references indexed. Current: %6$s.%7$s · cursor %8$s.', 'ultracache'),
                    (int) min($state['cursor_spec_index'], $total_specs),
                    $total_specs,
                    $batch_rows,
                    (int) $state['scanned_rows'],
                    (int) $summary['total'],
                    '' !== $current_table ? $current_table : '—',
                    '' !== $current_column ? $current_column : '—',
                    'keyset' === $last_pagination ? (string) $state['cursor_primary_value'] : (string) $state['cursor_offset']
                )
                /* translators: %d: indexed database reference count. */
                : sprintf(__('Database reference index completed with %d JPG/PNG references.', 'ultracache'), (int) $summary['total']),
            'status'              => $has_more ? 'ref_indexing' : 'ref_indexed',
            'hasMore'             => $has_more,
            'batchSize'           => $limit,
            'batchScannedRows'    => $batch_rows,
            'batchIndexedRefs'    => $batch_refs,
            'batchColumnsDone'    => $batch_columns_completed,
            'refsScanned'         => (int) min($state['cursor_spec_index'], $total_specs),
            'remainingRefsScan'   => max(0, $total_specs - min($state['cursor_spec_index'], $total_specs)),
            'totalReferenceColumns' => $total_specs,
            'scannedRows'         => (int) $state['scanned_rows'],
            'referencesFound'     => (int) $summary['total'],
            'serializedRefs'      => (int) $summary['serialized'],
            'jsonRefs'            => (int) $summary['json'],
            'matchedRefs'         => (int) $summary['matched'],
            'indexedTables'       => (int) $summary['tables'],
            'databaseScanPagination' => $last_pagination,
            'databaseScanTable'  => $current_table,
            'databaseScanColumn' => $current_column,
            'databaseScanCursorPrimary' => (string) $state['cursor_primary_value'],
            'databaseScanCursorOffset' => (int) $state['cursor_offset'],
            'databaseScanQueryMs' => $batch_query_ms,
            'progressPercent'     => $progress,
            'databaseReferenceIndexReady' => !$has_more,
            'databaseReferencesScanned' => !$has_more,
            'databaseReplaced'    => false,
            'nextStep'            => $has_more
                ? __('Continue the resumable database reference index. Site content has not been changed.', 'ultracache')
                : __('Continue Prepare by matching indexed references to replacement registry rows.', 'ultracache'),
        );
    }

    private function get_media_replacement_ref_match_summary()
    {
        global $wpdb;

        $ref_index_table = $this->get_media_replacement_ref_index_table_name();
        $refs_table      = $this->get_media_replacement_refs_table_name();
        if ('' === $ref_index_table || '' === $refs_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $index_row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS total_indexed, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS indexed_pending, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS matched_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS unmatched_refs, SUM(CASE WHEN status = %s AND matched_item_id = 0 THEN 1 ELSE 0 END) AS unmatched_ignored, SUM(CASE WHEN status = %s AND matched_item_id > 0 THEN 1 ELSE 0 END) AS unmatched_relevant, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS failed_refs, SUM(serialized) AS serialized_refs, SUM(json_detected) AS json_refs FROM %i WHERE status <> %s',
                'indexed',
                'matched',
                'unmatched',
                'unmatched',
                'unmatched',
                'failed',
                $ref_index_table,
                'excluded'
            ),
            ARRAY_A
        );

        $refs_row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS planned_refs, SUM(serialized) AS planned_serialized, SUM(json_detected) AS planned_json FROM %i WHERE status <> %s',
                $refs_table,
                'excluded'
            ),
            ARRAY_A
        );

        return array(
            'indexedTotal'      => is_array($index_row) && isset($index_row['total_indexed']) ? max(0, (int) $index_row['total_indexed']) : 0,
            'indexedPending'    => is_array($index_row) && isset($index_row['indexed_pending']) ? max(0, (int) $index_row['indexed_pending']) : 0,
            'matchedIndexed'    => is_array($index_row) && isset($index_row['matched_refs']) ? max(0, (int) $index_row['matched_refs']) : 0,
            'unmatchedIndexed'  => is_array($index_row) && isset($index_row['unmatched_refs']) ? max(0, (int) $index_row['unmatched_refs']) : 0,
            'unmatchedIgnored'  => is_array($index_row) && isset($index_row['unmatched_ignored']) ? max(0, (int) $index_row['unmatched_ignored']) : 0,
            'unmatchedRelevant' => is_array($index_row) && isset($index_row['unmatched_relevant']) ? max(0, (int) $index_row['unmatched_relevant']) : 0,
            'failedIndexed'     => is_array($index_row) && isset($index_row['failed_refs']) ? max(0, (int) $index_row['failed_refs']) : 0,
            'indexedSerialized' => is_array($index_row) && isset($index_row['serialized_refs']) ? max(0, (int) $index_row['serialized_refs']) : 0,
            'indexedJson'       => is_array($index_row) && isset($index_row['json_refs']) ? max(0, (int) $index_row['json_refs']) : 0,
            'plannedRefs'       => is_array($refs_row) && isset($refs_row['planned_refs']) ? max(0, (int) $refs_row['planned_refs']) : 0,
            'plannedSerialized' => is_array($refs_row) && isset($refs_row['planned_serialized']) ? max(0, (int) $refs_row['planned_serialized']) : 0,
            'plannedJson'       => is_array($refs_row) && isset($refs_row['planned_json']) ? max(0, (int) $refs_row['planned_json']) : 0,
        );
    }

    private function get_media_replacement_ref_index_match_rows($limit = 250)
    {
        global $wpdb;

        $items_table     = $this->get_media_replacement_items_table_name();
        $ref_index_table = $this->get_media_replacement_ref_index_table_name();
        $limit           = max(1, min(1000, absint($limit)));
        if ('' === $items_table || '' === $ref_index_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT x.id AS index_id, x.ref_hash AS index_ref_hash, x.table_name, x.primary_key_column, x.primary_key_value, x.row_identity, x.column_name, x.reference_type, x.raw_fragment, x.normalized_fragment, x.url_path_hash, x.serialized, x.json_detected, i.id AS item_id, i.attachment_id, i.old_relative_path, i.old_url, i.new_relative_path, i.new_url FROM %i x LEFT JOIN %i i ON i.old_path_hash = x.url_path_hash AND i.status IN (%s, %s, %s, %s) WHERE x.status = %s ORDER BY x.id ASC LIMIT %d',
                $ref_index_table,
                $items_table,
                'metadata_ready',
                'metadata_updated',
                'refs_scanned',
                'copied',
                'indexed',
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private function build_media_replacement_new_fragment_for_index(array $index_row, array $item_row)
    {
        $raw = isset($index_row['raw_fragment']) ? (string) $index_row['raw_fragment'] : '';
        if ('' === $raw) {
            return '';
        }

        $fragments = $this->get_media_replacement_reference_fragments($item_row);
        $old_relative = isset($item_row['old_relative_path']) ? ltrim(str_replace('\\', '/', (string) $item_row['old_relative_path']), '/') : '';
        $new_relative = isset($item_row['new_relative_path']) ? ltrim(str_replace('\\', '/', (string) $item_row['new_relative_path']), '/') : '';
        if ('' !== $old_relative && '' !== $new_relative) {
            $fragments[] = array('old' => str_replace('/', '\\/', $old_relative), 'new' => str_replace('/', '\\/', $new_relative));
        }

        usort($fragments, static function ($a, $b) {
            $left  = isset($a['old']) ? strlen((string) $a['old']) : 0;
            $right = isset($b['old']) ? strlen((string) $b['old']) : 0;
            return $right <=> $left;
        });

        foreach ($fragments as $fragment) {
            $old = isset($fragment['old']) ? (string) $fragment['old'] : '';
            $new = isset($fragment['new']) ? (string) $fragment['new'] : '';
            if ('' === $old || '' === $new || $old === $new) {
                continue;
            }

            if (false !== strpos($raw, $old)) {
                return str_replace($old, $new, $raw);
            }
        }

        if ('' !== $old_relative && '' !== $new_relative && $raw === $old_relative) {
            return $new_relative;
        }

        return '';
    }

    private function insert_media_replacement_reference_row_from_index(array $row, $new_fragment)
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        if ('' === $refs_table || !($wpdb instanceof wpdb)) {
            return false;
        }

        $item_id           = isset($row['item_id']) ? absint($row['item_id']) : 0;
        $table_name        = isset($row['table_name']) ? $this->sanitize_media_replacement_db_identifier((string) $row['table_name'], 191) : '';
        $primary_column    = isset($row['primary_key_column']) ? $this->sanitize_media_replacement_db_identifier((string) $row['primary_key_column'], 64) : '';
        $primary_value     = isset($row['primary_key_value']) ? substr(sanitize_text_field((string) $row['primary_key_value']), 0, 191) : '';
        $row_identity      = isset($row['row_identity']) ? substr((string) $row['row_identity'], 0, 191) : '';
        $column_name       = isset($row['column_name']) ? $this->sanitize_media_replacement_db_identifier((string) $row['column_name'], 64) : '';
        $old_fragment      = isset($row['raw_fragment']) ? (string) $row['raw_fragment'] : '';
        $new_fragment      = (string) $new_fragment;

        if ($item_id <= 0 || '' === $table_name || '' === $primary_column || '' === $primary_value || '' === $column_name || '' === $old_fragment || '' === $new_fragment || $old_fragment === $new_fragment || !$this->is_media_replacement_allowed_database_table_name($table_name)) {
            return false;
        }

        $old_value_hash = md5($old_fragment);
        $new_value_hash = md5($new_fragment);
        $now            = current_time('mysql', true);
        $ref_hash       = md5($table_name . '|' . $primary_column . '|' . $primary_value . '|' . $row_identity . '|' . $column_name . '|' . $old_value_hash . '|' . $new_value_hash);

        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE table_name = %s AND primary_key_column = %s AND primary_key_value = %s AND row_identity = %s AND column_name = %s AND old_value_hash = %s AND new_value_hash = %s LIMIT 1',
                $refs_table,
                $table_name,
                $primary_column,
                $primary_value,
                $row_identity,
                $column_name,
                $old_value_hash,
                $new_value_hash
            )
        );

        if (!empty($existing_id)) {
            return false !== $wpdb->update(
                $refs_table,
                array(
                    'row_identity'  => $row_identity,
                    'serialized'    => !empty($row['serialized']) ? 1 : 0,
                    'json_detected' => !empty($row['json_detected']) ? 1 : 0,
                    'updated_at'    => $now,
                ),
                array('id' => absint($existing_id)),
                array('%s', '%d', '%d', '%s'),
                array('%d')
            );
        }

        $row_data = array(
            'job_id'             => '',
            'item_id'            => $item_id,
            'ref_hash'           => $ref_hash,
            'table_name'         => $table_name,
            'primary_key_column' => $primary_column,
            'primary_key_value'  => $primary_value,
            'row_identity'       => $row_identity,
            'column_name'        => $column_name,
            'old_value_hash'     => $old_value_hash,
            'new_value_hash'     => $new_value_hash,
            'old_fragment'       => $old_fragment,
            'new_fragment'       => $new_fragment,
            'serialized'         => !empty($row['serialized']) ? 1 : 0,
            'json_detected'      => !empty($row['json_detected']) ? 1 : 0,
            'status'             => 'pending',
            'error_message'      => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        );

        return false !== $wpdb->replace(
            $refs_table,
            $row_data,
            array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s')
        );
    }

    private function deduplicate_media_replacement_database_reference_rows()
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        if ('' === $refs_table || !($wpdb instanceof wpdb)) {
            return 0;
        }

        $groups = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT MIN(id) AS keep_id, COUNT(*) AS duplicate_count, table_name, primary_key_column, primary_key_value, row_identity, column_name, old_value_hash, new_value_hash FROM %i GROUP BY table_name, primary_key_column, primary_key_value, row_identity, column_name, old_value_hash, new_value_hash HAVING COUNT(*) > 1',
                $refs_table
            ),
            ARRAY_A
        );

        $deleted = 0;
        foreach ((array) $groups as $group) {
            $keep_id = isset($group['keep_id']) ? absint($group['keep_id']) : 0;
            if ($keep_id <= 0) {
                continue;
            }

            $table_name     = isset($group['table_name']) ? $this->sanitize_media_replacement_db_identifier((string) $group['table_name'], 191) : '';
            $primary_column = isset($group['primary_key_column']) ? $this->sanitize_media_replacement_db_identifier((string) $group['primary_key_column'], 64) : '';
            $primary_value  = isset($group['primary_key_value']) ? substr(sanitize_text_field((string) $group['primary_key_value']), 0, 191) : '';
            $row_identity   = isset($group['row_identity']) ? substr((string) $group['row_identity'], 0, 191) : '';
            $column_name    = isset($group['column_name']) ? $this->sanitize_media_replacement_db_identifier((string) $group['column_name'], 64) : '';
            $old_hash       = isset($group['old_value_hash']) ? preg_replace('/[^a-f0-9]/', '', strtolower((string) $group['old_value_hash'])) : '';
            $new_hash       = isset($group['new_value_hash']) ? preg_replace('/[^a-f0-9]/', '', strtolower((string) $group['new_value_hash'])) : '';

            if ('' === $table_name || '' === $primary_column || '' === $primary_value || '' === $column_name || 32 !== strlen($old_hash) || 32 !== strlen($new_hash)) {
                continue;
            }

            $result = $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE id <> %d AND table_name = %s AND primary_key_column = %s AND primary_key_value = %s AND row_identity = %s AND column_name = %s AND old_value_hash = %s AND new_value_hash = %s',
                    $refs_table,
                        $keep_id,
                    $table_name,
                    $primary_column,
                    $primary_value,
                    $row_identity,
                    $column_name,
                    $old_hash,
                    $new_hash
                )
            );

            if (false !== $result) {
                $deleted += max(0, (int) $result);
            }
        }

        return $deleted;
    }

    private function update_media_replacement_ref_index_match_result($index_id, $matched_item_id, $status, $message = '')
    {
        global $wpdb;

        $ref_index_table = $this->get_media_replacement_ref_index_table_name();
        $index_id        = absint($index_id);
        $matched_item_id = absint($matched_item_id);
        $status          = in_array((string) $status, array('matched', 'unmatched', 'failed'), true) ? (string) $status : 'failed';
        if ('' === $ref_index_table || $index_id <= 0 || !($wpdb instanceof wpdb)) {
            return false;
        }

        return false !== $wpdb->update(
            $ref_index_table,
            array(
                'matched_item_id' => $matched_item_id,
                'status'          => $status,
                'error_message'   => '' !== (string) $message ? wp_strip_all_tags((string) $message) : null,
                'updated_at'      => current_time('mysql', true),
            ),
            array('id' => $index_id),
            array('%d', '%s', '%s', '%s'),
            array('%d')
        );
    }

    public function match_media_library_replacement_database_references($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array(
                'success' => false,
                'message' => __('Media Library replacement registry tables are not available.', 'ultracache'),
            );
        }

        $args = is_array($args) ? $args : array();
        if (!$this->media_replacement_has_registry_rows()) {
            return $this->build_media_replacement_empty_registry_response(__('No Media Library replacement registry rows are available for database reference matching.', 'ultracache'));
        }

        $limit = isset($args['limit']) ? absint($args['limit']) : 250;
        $limit = max(25, min(1000, $limit));
        $rows = $this->get_media_replacement_ref_index_match_rows($limit);

        $batch_processed = 0;
        $batch_matched = 0;
        $batch_unmatched = 0;
        $batch_failed = 0;
        foreach ($rows as $row) {
            $batch_processed++;
            $index_id = isset($row['index_id']) ? absint($row['index_id']) : 0;
            $item_id = isset($row['item_id']) ? absint($row['item_id']) : 0;
            $table_name = isset($row['table_name']) ? (string) $row['table_name'] : '';
            $primary_column = isset($row['primary_key_column']) ? (string) $row['primary_key_column'] : '';
            if ('' !== $table_name && !$this->is_media_replacement_allowed_database_table_name($table_name)) {
                $this->update_media_replacement_ref_index_match_result($index_id, 0, 'failed', __('Indexed reference table is outside the current WordPress table prefix scope.', 'ultracache'));
                $batch_failed++;
                continue;
            }
            if ('' === $primary_column) {
                $this->update_media_replacement_ref_index_match_result($index_id, 0, 'failed', __('Indexed reference belongs to a table without a primary key and cannot be replaced deterministically.', 'ultracache'));
                $batch_failed++;
                continue;
            }
            if ($index_id <= 0 || $item_id <= 0) {
                $this->update_media_replacement_ref_index_match_result($index_id, 0, 'unmatched', __('Indexed reference does not match a prepared Media Library replacement item.', 'ultracache'));
                $batch_unmatched++;
                continue;
            }

                $new_fragment = $this->build_media_replacement_new_fragment_for_index($row, $row);
            if ('' === $new_fragment) {
                $this->update_media_replacement_ref_index_match_result($index_id, $item_id, 'failed', __('Could not build a same-style replacement fragment for the indexed reference.', 'ultracache'));
                $batch_failed++;
                continue;
            }

            if ($this->insert_media_replacement_reference_row_from_index($row, $new_fragment)) {
                $this->update_media_replacement_ref_index_match_result($index_id, $item_id, 'matched', '');
                $batch_matched++;
            } else {
                $this->update_media_replacement_ref_index_match_result($index_id, $item_id, 'failed', __('Could not write the planned database replacement reference.', 'ultracache'));
                $batch_failed++;
            }
        }

        $duplicates_removed = $this->deduplicate_media_replacement_database_reference_rows();
        $summary = $this->get_media_replacement_ref_match_summary();
        $remaining = max(0, (int) ($summary['indexedPending'] ?? 0));
        $total = max(0, (int) ($summary['indexedTotal'] ?? 0));
        $failed_total = max(0, (int) ($summary['failedIndexed'] ?? 0));
        $processed = max(0, $total - $remaining);
        $progress = $total > 0 ? min(100, round(($processed / $total) * 100, 1)) : 100;
        $has_more = $remaining > 0 && 0 === $failed_total;

        return array(
            'success'              => 0 === $failed_total,
            'message'              => $failed_total > 0
                /* translators: %d: failed indexed row count. */
                ? sprintf(__('Database reference matching stopped with %d failed indexed rows.', 'ultracache'), $failed_total)
                : ($has_more
                    /* translators: %1$d: planned replacement count; %2$d: indexed reference count. */
                    ? sprintf(__('Database reference matching: %1$d planned replacements from %2$d indexed references.', 'ultracache'), (int) $summary['plannedRefs'], $total)
                    /* translators: %1$d: planned replacement count; %2$d: indexed reference count. */
                    : sprintf(__('Database reference matching completed with %1$d planned replacements from %2$d indexed references.', 'ultracache'), (int) $summary['plannedRefs'], $total)),
            'status'               => $failed_total > 0 ? 'refs_match_failed' : ($has_more ? 'refs_matching' : 'refs_matched'),
            'hasMore'              => $has_more,
            'batchSize'            => $limit,
            'batchProcessedRefs'   => $batch_processed,
            'batchMatchedRefs'     => $batch_matched,
            'batchUnmatchedRefs'   => $batch_unmatched,
            'batchFailedRefs'      => $batch_failed,
            'duplicateRefsSkipped' => (int) $duplicates_removed,
            'referencesFound'      => $total,
            'matchedRefs'          => (int) $summary['plannedRefs'],
            'unmatchedRefs'        => (int) $summary['unmatchedIndexed'],
            'failedIndexedRefs'    => $failed_total,
            'remainingRefsMatch'   => $remaining,
            'serializedRefs'       => (int) $summary['plannedSerialized'],
            'jsonRefs'             => (int) $summary['plannedJson'],
            'refsScanned'          => $processed,
            'remainingRefsScan'    => 0,
            'progressPercent'      => $progress,
            'databaseReferencesMatched' => !$has_more && 0 === $failed_total,
            'databaseReplaced'     => false,
            'nextStep'             => $has_more
                ? __('Continue matching indexed references. Site content has not been changed.', 'ultracache')
                : __('Continue Prepare with the database replacement preview. Site content has not been changed.', 'ultracache'),
        );
    }


    private function get_media_replacement_database_apply_summary()
    {
        return $this->get_media_replacement_database_preview_summary();
    }

    private function get_media_replacement_database_physical_row_key(array $ref)
    {
        return md5(
            (string) ($ref['table_name'] ?? '') . "\n"
            . (string) ($ref['primary_key_column'] ?? '') . "\n"
            . (string) ($ref['primary_key_value'] ?? '') . "\n"
            . (string) ($ref['row_identity'] ?? '') . "\n"
            . (string) ($ref['column_name'] ?? '')
        );
    }

    private function get_media_replacement_database_apply_groups($limit = 50)
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        $limit      = max(1, min(250, absint($limit)));

        if ('' === $refs_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        /*
         * Keep the existing reference limit as the batch seed, but expand every
         * seeded physical row to include all of its pending prepared references.
         * This prevents one database value from being read and written repeatedly
         * merely because it contains several image references.
         */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT r.id, r.item_id, r.table_name, r.primary_key_column, r.primary_key_value, r.row_identity, r.column_name, r.old_fragment, r.new_fragment, r.serialized, r.json_detected, r.status
                 FROM %i r
                 INNER JOIN (
                     SELECT seed.table_name, seed.primary_key_column, seed.primary_key_value, seed.row_identity, seed.column_name, MIN(seed.id) AS first_id
                     FROM (
                         SELECT id, table_name, primary_key_column, primary_key_value, row_identity, column_name
                         FROM %i
                         WHERE status = %s
                         ORDER BY id ASC
                         LIMIT %d
                     ) seed
                     GROUP BY seed.table_name, seed.primary_key_column, seed.primary_key_value, seed.row_identity, seed.column_name
                 ) grouped
                   ON grouped.table_name = r.table_name
                  AND grouped.primary_key_column = r.primary_key_column
                  AND grouped.primary_key_value = r.primary_key_value
                  AND grouped.row_identity = r.row_identity
                  AND grouped.column_name = r.column_name
                 WHERE r.status = %s
                 ORDER BY grouped.first_id ASC, r.id ASC',
                $refs_table,
                $refs_table,
                'pending',
                $limit,
                'pending'
            ),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            return array();
        }

        $groups = array();
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : array();
            $key = $this->get_media_replacement_database_physical_row_key($row);
            if (!isset($groups[$key])) {
                $groups[$key] = array();
            }
            $groups[$key][] = $row;
        }

        return array_values($groups);
    }

    private function update_media_replacement_database_apply_ref_result($ref_id, $status, $message = '')
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        $ref_id     = absint($ref_id);
        $status     = in_array((string) $status, array('replaced', 'failed', 'excluded'), true) ? (string) $status : 'failed';

        if ('' === $refs_table || $ref_id <= 0 || !($wpdb instanceof wpdb)) {
            return false;
        }

        return false !== $wpdb->update(
            $refs_table,
            array(
                'status'        => $status,
                'error_message' => '' !== (string) $message ? wp_strip_all_tags((string) $message) : null,
                'updated_at'    => current_time('mysql', true),
            ),
            array('id' => $ref_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }


    private function exclude_media_replacement_internal_database_references()
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        $ref_index_table = $this->get_media_replacement_ref_index_table_name();
        if ('' === $refs_table || '' === $ref_index_table || !($wpdb instanceof wpdb)) {
            return 0;
        }

        $excluded = 0;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, table_name, primary_key_column, primary_key_value, row_identity FROM %i WHERE status <> %s',
                $refs_table,
                'excluded'
            ),
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            if (!$this->is_media_replacement_internal_database_ref((array) $row)) {
                continue;
            }
            if (false !== $wpdb->update(
                $refs_table,
                array(
                    'status'        => 'excluded',
                    'error_message' => __('UltraCache operational state was excluded from the Media Library replacement content plan.', 'ultracache'),
                    'updated_at'    => current_time('mysql', true),
                ),
                array('id' => absint($row['id'] ?? 0)),
                array('%s', '%s', '%s'),
                array('%d')
            )) {
                $excluded++;
            }
        }

        $index_rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, table_name, primary_key_column, primary_key_value, row_identity FROM %i WHERE status <> %s',
                $ref_index_table,
                'excluded'
            ),
            ARRAY_A
        );
        foreach ((array) $index_rows as $row) {
            if (!$this->is_media_replacement_internal_database_ref((array) $row)) {
                continue;
            }
            $wpdb->update(
                $ref_index_table,
                array(
                    'status'          => 'excluded',
                    'matched_item_id' => 0,
                    'error_message'   => __('UltraCache operational state was excluded from the Media Library replacement content index.', 'ultracache'),
                    'updated_at'      => current_time('mysql', true),
                ),
                array('id' => absint($row['id'] ?? 0)),
                array('%s', '%d', '%s', '%s'),
                array('%d')
            );
        }

        return $excluded;
    }

    private function retry_media_replacement_failed_database_references()
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        if ('' === $refs_table || !($wpdb instanceof wpdb)) {
            return 0;
        }

        $retried = 0;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, table_name, primary_key_column, primary_key_value, row_identity FROM %i WHERE status IN (%s, %s)',
                $refs_table,
                'failed',
                'verify_failed'
            ),
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            if ($this->is_media_replacement_internal_database_ref((array) $row)) {
                continue;
            }
            if (false !== $wpdb->update(
                $refs_table,
                array(
                    'status'        => 'pending',
                    'error_message' => null,
                    'updated_at'    => current_time('mysql', true),
                ),
                array('id' => absint($row['id'] ?? 0)),
                array('%s', '%s', '%s'),
                array('%d')
            )) {
                $retried++;
            }
        }

        return $retried;
    }

    private function reset_media_replacement_database_plan_for_restart()
    {
        if (!$this->reset_media_replacement_ref_index_registry()) {
            return false;
        }

        $this->clear_media_replacement_workflow_section('database_index_scan');
        $this->clear_media_replacement_database_reference_specs();
        $this->clear_media_replacement_destructive_authorization('database_apply');
        return true;
    }

    private function media_replacement_value_contains_object($value)
    {
        if (is_object($value)) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if ($this->media_replacement_value_contains_object($item)) {
                return true;
            }
        }

        return false;
    }

    private function safe_unserialize_media_replacement_database_value($serialized_value)
    {
        $serialized_value = (string) $serialized_value;

        if (!function_exists('is_serialized') || !is_serialized($serialized_value)) {
            return array(
                'success' => false,
                'data'    => null,
                'message' => __('The serialized database value is not valid serialized data.', 'ultracache'),
            );
        }

        if ('b:0;' === $serialized_value) {
            return array(
                'success' => true,
                'data'    => false,
                'message' => '',
            );
        }

        $data = @unserialize($serialized_value, array('allowed_classes' => false));
        if (false === $data) {
            return array(
                'success' => false,
                'data'    => null,
                'message' => __('The serialized database value could not be decoded.', 'ultracache'),
            );
        }

        if ($this->media_replacement_value_contains_object($data)) {
            return array(
                'success' => false,
                'data'    => null,
                'message' => __('The serialized database value contains object data and was skipped.', 'ultracache'),
                'object'  => true,
            );
        }

        return array(
            'success' => true,
            'data'    => $data,
            'message' => '',
        );
    }

    private function replace_media_replacement_fragment_recursive($value, $old_fragment, $new_fragment, &$count)
    {
        if (is_string($value)) {
            $local_count = 0;
            $new_value   = str_replace((string) $old_fragment, (string) $new_fragment, $value, $local_count);
            $count      += absint($local_count);
            return $new_value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->replace_media_replacement_fragment_recursive($item, $old_fragment, $new_fragment, $count);
            }
            return $value;
        }

        return $value;
    }

    private function resolve_media_replacement_database_value_encoding($stored_value, $planned_serialized = false, $planned_json_detected = false)
    {
        $stored_value          = (string) $stored_value;
        $planned_serialized    = !empty($planned_serialized);
        $planned_json_detected = !empty($planned_json_detected);

        if (function_exists('is_serialized') && is_serialized($stored_value)) {
            return array(
                'mode'          => 'serialized',
                'serialized'    => true,
                'json_detected' => false,
            );
        }

        if ($this->is_media_replacement_json_like_value($stored_value)) {
            return array(
                'mode'          => 'json',
                'serialized'    => false,
                'json_detected' => true,
            );
        }

        // Preserve the stricter planned mode when a previously structured value is now malformed.
        if ($planned_serialized) {
            return array(
                'mode'          => 'serialized',
                'serialized'    => true,
                'json_detected' => false,
            );
        }

        if ($planned_json_detected) {
            return array(
                'mode'          => 'json',
                'serialized'    => false,
                'json_detected' => true,
            );
        }

        return array(
            'mode'          => 'raw',
            'serialized'    => false,
            'json_detected' => false,
        );
    }

    private function validate_media_replacement_database_prepared_encoding($stored_value, $planned_serialized = false, $planned_json_detected = false)
    {
        $stored_value          = (string) $stored_value;
        $planned_serialized    = !empty($planned_serialized);
        $planned_json_detected = !empty($planned_json_detected);

        if ($planned_serialized && $planned_json_detected) {
            return array(
                'valid'         => false,
                'serialized'    => false,
                'json_detected' => false,
                'message'       => __('The prepared database row has inconsistent structured-value flags.', 'ultracache'),
            );
        }

        $current_serialized = function_exists('is_serialized') && is_serialized($stored_value);
        $current_json       = !$current_serialized && $this->is_media_replacement_json_like_value($stored_value);

        if ($planned_serialized) {
            if (!function_exists('is_serialized')) {
                return array(
                    'valid'         => false,
                    'serialized'    => true,
                    'json_detected' => false,
                    'message'       => __('Serialized database value support is not available.', 'ultracache'),
                );
            }

            if (!$current_serialized) {
                return array(
                    'valid'         => false,
                    'serialized'    => true,
                    'json_detected' => false,
                    'message'       => __('The database row no longer matches the serialized representation recorded by Prepare. The prepared plan is stale and the current value was preserved.', 'ultracache'),
                );
            }

            return array('valid' => true, 'serialized' => true, 'json_detected' => false, 'message' => '');
        }

        if ($planned_json_detected) {
            if (!$current_json) {
                return array(
                    'valid'         => false,
                    'serialized'    => false,
                    'json_detected' => true,
                    'message'       => __('The database row no longer matches the JSON representation recorded by Prepare. The prepared plan is stale and the current value was preserved.', 'ultracache'),
                );
            }

            return array('valid' => true, 'serialized' => false, 'json_detected' => true, 'message' => '');
        }

        if ($current_serialized || $current_json) {
            return array(
                'valid'         => false,
                'serialized'    => false,
                'json_detected' => false,
                'message'       => __('The database row no longer matches the raw representation recorded by Prepare. The prepared plan is stale and the current value was preserved.', 'ultracache'),
            );
        }

        return array('valid' => true, 'serialized' => false, 'json_detected' => false, 'message' => '');
    }

    private function validate_media_replacement_database_built_value($replacement_value, $old_fragment, $new_fragment, $serialized, $json_detected)
    {
        $replacement_value = (string) $replacement_value;
        $old_fragment      = (string) $old_fragment;
        $new_fragment      = (string) $new_fragment;

        if ('' === $replacement_value || '' === $old_fragment || '' === $new_fragment || $old_fragment === $new_fragment) {
            return array('valid' => false, 'message' => __('Invalid database replacement value validation request.', 'ultracache'));
        }

        if (false !== strpos($replacement_value, $old_fragment)) {
            return array('valid' => false, 'message' => __('The old image reference is still present in the built database replacement value.', 'ultracache'));
        }

        if (false === strpos($replacement_value, $new_fragment)) {
            return array('valid' => false, 'message' => __('The new image reference is missing from the built database replacement value.', 'ultracache'));
        }

        if (!empty($serialized)) {
            $serialized_check = $this->verify_media_replacement_serialized_database_value($replacement_value);
            if (empty($serialized_check['verified'])) {
                return array('valid' => false, 'message' => __('The built serialized database replacement value could not be decoded before writing.', 'ultracache'));
            }

            if (!empty($serialized_check['repaired'])) {
                return array('valid' => false, 'message' => __('The built serialized database replacement value required string-length repair before writing.', 'ultracache'));
            }
        }

        if (!empty($json_detected)) {
            json_decode($replacement_value, true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                return array('valid' => false, 'message' => __('The built JSON-like database replacement value could not be decoded before writing.', 'ultracache'));
            }
        }

        return array('valid' => true, 'message' => '');
    }

    private function build_media_replacement_database_value($stored_value, $old_fragment, $new_fragment, $serialized, $json_detected)
    {
        $stored_value  = (string) $stored_value;
        $old_fragment  = (string) $old_fragment;
        $new_fragment  = (string) $new_fragment;
        $serialized    = !empty($serialized);
        $json_detected = !empty($json_detected);

        if ('' === $stored_value || '' === $old_fragment || '' === $new_fragment || $old_fragment === $new_fragment) {
            return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('Invalid database replacement fragment.', 'ultracache'));
        }

        if ($serialized) {
            if (!function_exists('is_serialized')) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('Serialized database value support is not available.', 'ultracache'));
            }

            $serialized_value = $stored_value;
            if (!is_serialized($serialized_value)) {
                $repaired_serialized_value = $this->repair_media_replacement_serialized_string_lengths($serialized_value);
                if ('' !== $repaired_serialized_value) {
                    $serialized_value = $repaired_serialized_value;
                }
            }

            if (!is_serialized($serialized_value)) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The planned serialized database value could not be decoded before replacement.', 'ultracache'));
            }

            $decoded_serialized = $this->safe_unserialize_media_replacement_database_value($serialized_value);
            if (empty($decoded_serialized['success'])) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => isset($decoded_serialized['message']) ? (string) $decoded_serialized['message'] : __('The planned serialized database value could not be decoded before replacement.', 'ultracache'));
            }

            $count = 0;
            $data  = $this->replace_media_replacement_fragment_recursive($decoded_serialized['data'], $old_fragment, $new_fragment, $count);
            if ($count <= 0) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The old image reference was not found inside the decoded serialized value.', 'ultracache'));
            }

            $new_value = serialize($data);
            if (!is_string($new_value) || '' === $new_value || !is_serialized($new_value)) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The serialized database value could not be re-encoded after replacement.', 'ultracache'));
            }

            $verify_data = $this->safe_unserialize_media_replacement_database_value($new_value);
            if (empty($verify_data['success'])) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The re-encoded serialized database value could not be decoded after replacement.', 'ultracache'));
            }

            if (false !== strpos($new_value, $old_fragment)) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The old image reference is still present in the serialized replacement value.', 'ultracache'));
            }

            if (false === strpos($new_value, $new_fragment)) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The new image reference is missing from the serialized replacement value.', 'ultracache'));
            }

            return array('changed' => true, 'value' => $new_value, 'count' => $count, 'message' => '');
        }

        if ($json_detected) {
            if (!$this->is_media_replacement_json_like_value($stored_value)) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The planned JSON-like database value is no longer JSON-like.', 'ultracache'));
            }

            if (false === strpos($stored_value, $old_fragment)) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The prepared image reference is no longer present in the JSON-like database value.', 'ultracache'));
            }

            $count     = 0;
            $new_value = str_replace($old_fragment, $new_fragment, $stored_value, $count);
            if ($count <= 0 || $new_value === $stored_value) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The prepared image reference could not be replaced in the JSON-like database value.', 'ultracache'));
            }

            json_decode($new_value, true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The JSON-like database value became invalid after applying the prepared replacement.', 'ultracache'));
            }

            if (false !== strpos($new_value, $old_fragment)) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The old image reference is still present in the JSON-like replacement value.', 'ultracache'));
            }

            if (false === strpos($new_value, $new_fragment)) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The new image reference is missing from the JSON-like replacement value.', 'ultracache'));
            }

            return array('changed' => true, 'value' => $new_value, 'count' => $count, 'message' => '');
        }

        if (false === strpos($stored_value, $old_fragment)) {
            return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The old image reference is no longer present in the database row.', 'ultracache'));
        }

        $count = 0;
        $new_value = str_replace($old_fragment, $new_fragment, $stored_value, $count);
        return array(
            'changed' => $count > 0 && $new_value !== $stored_value,
            'value'   => $new_value,
            'count'   => $count,
            'message' => $count > 0 ? '' : __('The old image reference could not be replaced.', 'ultracache'),
        );
    }

    private function get_media_replacement_database_row_value(array $ref)
    {
        global $wpdb;

        $table_name     = isset($ref['table_name']) ? $this->sanitize_media_replacement_db_identifier((string) $ref['table_name'], 191) : '';
        $primary_column = isset($ref['primary_key_column']) ? $this->sanitize_media_replacement_db_identifier((string) $ref['primary_key_column'], 64) : '';
        $primary_value  = isset($ref['primary_key_value']) ? (string) $ref['primary_key_value'] : '';
        $column_name    = isset($ref['column_name']) ? $this->sanitize_media_replacement_db_identifier((string) $ref['column_name'], 64) : '';

        if ('' === $table_name || '' === $primary_column || '' === $primary_value || '' === $column_name || !($wpdb instanceof wpdb)) {
            return null;
        }

        if (!$this->is_media_replacement_allowed_database_table_name($table_name)) {
            return null;
        }

        if ($this->is_media_replacement_options_database_ref($ref)) {
            $context = $this->get_media_replacement_option_row_context($ref);
            return is_array($context) && !empty($context['valid'])
                ? (string) $context['option_value']
                : null;
        }

        return $wpdb->get_var(
            $wpdb->prepare(
                'SELECT %i FROM %i WHERE %i = %s LIMIT 1',
                $column_name,
                $table_name,
                $primary_column,
                $primary_value
            )
        );
    }

    private function compare_and_swap_media_replacement_database_row_value(array $ref, $expected_value, $new_value)
    {
        global $wpdb;

        $table_name     = isset($ref['table_name']) ? $this->sanitize_media_replacement_db_identifier((string) $ref['table_name'], 191) : '';
        $primary_column = isset($ref['primary_key_column']) ? $this->sanitize_media_replacement_db_identifier((string) $ref['primary_key_column'], 64) : '';
        $primary_value  = isset($ref['primary_key_value']) ? (string) $ref['primary_key_value'] : '';
        $column_name    = isset($ref['column_name']) ? $this->sanitize_media_replacement_db_identifier((string) $ref['column_name'], 64) : '';

        if ('' === $table_name || '' === $primary_column || '' === $primary_value || '' === $column_name || !($wpdb instanceof wpdb)) {
            return array('updated' => false, 'conflict' => false, 'error' => true);
        }

        if (!$this->is_media_replacement_allowed_database_table_name($table_name)) {
            return array('updated' => false, 'conflict' => false, 'error' => true);
        }

        if ($this->is_media_replacement_options_database_ref($ref)) {
            return $this->compare_and_swap_media_replacement_option_value($ref, $expected_value, $new_value);
        }

        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET %i = %s WHERE %i = %s AND CAST(%i AS BINARY) = CAST(%s AS BINARY)',
                $table_name,
                $column_name,
                (string) $new_value,
                $primary_column,
                $primary_value,
                $column_name,
                (string) $expected_value
            )
        );

        if (false === $updated) {
            return array('updated' => false, 'conflict' => false, 'error' => true);
        }

        if (0 === (int) $updated) {
            return array('updated' => false, 'conflict' => true, 'error' => false);
        }

        return array('updated' => true, 'conflict' => false, 'error' => false);
    }

    private function apply_media_replacement_database_row_group(array $refs)
    {
        $refs = array_values(array_filter($refs, 'is_array'));
        if (empty($refs)) {
            return array('results' => array());
        }

        $first_ref = $refs[0];
        $group_key = $this->get_media_replacement_database_physical_row_key($first_ref);
        $results   = array();
        $work_refs = array();

        foreach ($refs as $ref) {
            $ref_id       = isset($ref['id']) ? absint($ref['id']) : 0;
            $old_fragment = isset($ref['old_fragment']) ? (string) $ref['old_fragment'] : '';
            $new_fragment = isset($ref['new_fragment']) ? (string) $ref['new_fragment'] : '';

            if ($ref_id <= 0 || '' === $old_fragment || '' === $new_fragment || $old_fragment === $new_fragment) {
                if ($ref_id > 0) {
                    $results[$ref_id] = array('status' => 'failed', 'message' => __('Invalid planned database replacement row.', 'ultracache'));
                }
                continue;
            }

            if (!hash_equals($group_key, $this->get_media_replacement_database_physical_row_key($ref))) {
                $results[$ref_id] = array('status' => 'failed', 'message' => __('The prepared database replacement batch mixed different physical rows.', 'ultracache'));
                continue;
            }

            if ($this->is_media_replacement_internal_database_ref($ref)) {
                $results[$ref_id] = array(
                    'status'  => 'excluded',
                    'message' => __('UltraCache operational state is outside the Media Library replacement content scope.', 'ultracache'),
                );
                continue;
            }

            $identity = $this->validate_media_replacement_database_ref_identity($ref);
            if (empty($identity['valid'])) {
                $results[$ref_id] = array(
                    'status'  => 'failed',
                    'message' => isset($identity['message']) ? (string) $identity['message'] : __('The planned database-row identity could not be verified.', 'ultracache'),
                );
                continue;
            }

            $work_refs[] = $ref;
        }

        if (empty($work_refs)) {
            return array('results' => $results);
        }

        $base_ref     = $work_refs[0];
        $stored_value = $this->get_media_replacement_database_row_value($base_ref);
        if (null === $stored_value) {
            foreach ($work_refs as $ref) {
                $results[absint($ref['id'])] = array('status' => 'failed', 'message' => __('The database row for this replacement could not be read.', 'ultracache'));
            }
            return array('results' => $results);
        }

        $stored_value       = (string) $stored_value;
        $planned_serialized = !empty($base_ref['serialized']);
        $planned_json       = !empty($base_ref['json_detected']);

        foreach ($work_refs as $ref) {
            if ((!empty($ref['serialized'])) !== $planned_serialized || (!empty($ref['json_detected'])) !== $planned_json) {
                foreach ($work_refs as $row_ref) {
                    $results[absint($row_ref['id'])] = array('status' => 'failed', 'message' => __('The prepared references for this database row disagree about its stored representation. The current value was preserved.', 'ultracache'));
                }
                return array('results' => $results);
            }
        }

        $encoding = $this->validate_media_replacement_database_prepared_encoding(
            $stored_value,
            $planned_serialized,
            $planned_json
        );
        if (empty($encoding['valid'])) {
            $message = isset($encoding['message']) ? (string) $encoding['message'] : __('The database row no longer matches the representation recorded by Prepare. The current value was preserved.', 'ultracache');
            foreach ($work_refs as $ref) {
                $results[absint($ref['id'])] = array('status' => 'failed', 'message' => $message, 'conflict' => true);
            }
            return array('results' => $results);
        }

        $working_value   = $stored_value;
        $changed_ref_ids = array();
        $already_ref_ids = array();

        foreach ($work_refs as $ref) {
            $ref_id       = absint($ref['id']);
            $old_fragment = (string) $ref['old_fragment'];
            $new_fragment = (string) $ref['new_fragment'];

            if (false === strpos($working_value, $old_fragment)) {
                if (false !== strpos($working_value, $new_fragment)) {
                    $already_validation = $this->validate_media_replacement_database_built_value(
                        $working_value,
                        $old_fragment,
                        $new_fragment,
                        $encoding['serialized'],
                        $encoding['json_detected']
                    );
                    if (!empty($already_validation['valid'])) {
                        $already_ref_ids[$ref_id] = true;
                    } else {
                        $results[$ref_id] = array(
                            'status'  => 'failed',
                            'message' => isset($already_validation['message']) ? (string) $already_validation['message'] : __('The already-replaced database value failed validation.', 'ultracache'),
                        );
                    }
                } else {
                    $results[$ref_id] = array('status' => 'failed', 'message' => __('Neither the old nor the new image reference is present in the current database value.', 'ultracache'));
                }
                continue;
            }

            $replacement = $this->build_media_replacement_database_value(
                $working_value,
                $old_fragment,
                $new_fragment,
                $encoding['serialized'],
                $encoding['json_detected']
            );

            if (empty($replacement['changed'])) {
                $results[$ref_id] = array(
                    'status'  => 'failed',
                    'message' => isset($replacement['message']) ? (string) $replacement['message'] : __('No database value change was produced.', 'ultracache'),
                );
                continue;
            }

            $candidate = isset($replacement['value']) ? (string) $replacement['value'] : '';
            $prewrite_validation = $this->validate_media_replacement_database_built_value(
                $candidate,
                $old_fragment,
                $new_fragment,
                $encoding['serialized'],
                $encoding['json_detected']
            );
            if (empty($prewrite_validation['valid'])) {
                $results[$ref_id] = array(
                    'status'  => 'failed',
                    'message' => isset($prewrite_validation['message']) ? (string) $prewrite_validation['message'] : __('The built database replacement value failed pre-write validation.', 'ultracache'),
                );
                continue;
            }

            $working_value = $candidate;
            $changed_ref_ids[$ref_id] = true;
        }

        if ($working_value === $stored_value) {
            if (!empty($already_ref_ids)) {
                $runtime_verification = $this->verify_media_replacement_option_runtime_state($base_ref, $stored_value);
                if (empty($runtime_verification['verified'])) {
                    $message = isset($runtime_verification['message']) ? (string) $runtime_verification['message'] : __('The WordPress option runtime cache could not be verified for the already-applied value.', 'ultracache');
                    foreach (array_keys($already_ref_ids) as $ref_id) {
                        $results[$ref_id] = array('status' => 'failed', 'message' => $message);
                    }
                } else {
                    foreach (array_keys($already_ref_ids) as $ref_id) {
                        $results[$ref_id] = array('status' => 'replaced', 'message' => '', 'already_applied' => true);
                    }
                }
            }
            return array('results' => $results);
        }

        $write_result = $this->compare_and_swap_media_replacement_database_row_value($base_ref, $stored_value, $working_value);
        if (empty($write_result['updated'])) {
            $message = !empty($write_result['conflict'])
                ? __('The database row changed after it was read. No grouped replacement was written; run the database replacement step again with a fresh plan.', 'ultracache')
                : (!empty($write_result['message']) ? (string) $write_result['message'] : __('The database row could not be updated.', 'ultracache'));
            foreach (array_unique(array_merge(array_keys($changed_ref_ids), array_keys($already_ref_ids))) as $ref_id) {
                $results[$ref_id] = array('status' => 'failed', 'message' => $message);
            }
            return array('results' => $results);
        }

        $restore_and_fail = function ($message) use ($base_ref, $stored_value, $working_value, &$results, $changed_ref_ids, $already_ref_ids) {
            $restore_result = $this->compare_and_swap_media_replacement_database_row_value($base_ref, $working_value, $stored_value);
            $final_message = !empty($restore_result['updated'])
                ? (string) $message
                : sprintf(
                    /* translators: %s: failure reason. */
                    __('%s The original database value was not restored because the row changed again or the restore query failed.', 'ultracache'),
                    (string) $message
                );

            foreach (array_keys($changed_ref_ids) as $ref_id) {
                $results[$ref_id] = array('status' => 'failed', 'message' => $final_message);
            }
            foreach (array_keys($already_ref_ids) as $ref_id) {
                $results[$ref_id] = array('status' => 'replaced', 'message' => '', 'already_applied' => true);
            }

            return array('results' => $results);
        };

        $verified_value = $this->get_media_replacement_database_row_value($base_ref);
        if (null === $verified_value) {
            return $restore_and_fail(__('The grouped database row update could not be read back for verification.', 'ultracache'));
        }
        $verified_value = (string) $verified_value;

        foreach ($work_refs as $ref) {
            $ref_id = absint($ref['id']);
            if (!isset($changed_ref_ids[$ref_id]) && !isset($already_ref_ids[$ref_id])) {
                continue;
            }
            if (false === strpos($verified_value, (string) $ref['new_fragment']) || false !== strpos($verified_value, (string) $ref['old_fragment'])) {
                return $restore_and_fail(__('One or more prepared image references could not be verified after the grouped database row update.', 'ultracache'));
            }
        }

        if (!empty($encoding['serialized'])) {
            $serialized_check = $this->verify_media_replacement_serialized_database_value($verified_value);
            if (empty($serialized_check['verified'])) {
                return $restore_and_fail(__('The serialized database value could not be decoded immediately after the grouped replacement.', 'ultracache'));
            }
            if (!empty($serialized_check['repaired'])) {
                return $restore_and_fail(__('The serialized database value required repair immediately after the grouped replacement and was not marked as applied.', 'ultracache'));
            }
        }

        if (!empty($encoding['json_detected'])) {
            json_decode($verified_value, true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                return $restore_and_fail(__('The JSON-like database value could not be decoded immediately after the grouped replacement.', 'ultracache'));
            }
        }

        $runtime_verification = $this->verify_media_replacement_option_runtime_state($base_ref, $verified_value);
        if (empty($runtime_verification['verified'])) {
            return $restore_and_fail(isset($runtime_verification['message']) ? (string) $runtime_verification['message'] : __('The WordPress option runtime cache could not be verified after replacement.', 'ultracache'));
        }

        $this->finalize_media_replacement_option_write($write_result);

        foreach (array_keys($changed_ref_ids) as $ref_id) {
            $results[$ref_id] = array('status' => 'replaced', 'message' => '');
        }
        foreach (array_keys($already_ref_ids) as $ref_id) {
            $results[$ref_id] = array('status' => 'replaced', 'message' => '', 'already_applied' => true);
        }

        return array('results' => $results);
    }

    public function apply_media_library_replacement_database_replacements($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array(
                'success' => false,
                'message' => __('Media Library replacement registry tables are not available.', 'ultracache'),
            );
        }

        $args = is_array($args) ? $args : array();
        if (!$this->media_replacement_has_registry_rows()) {
            return $this->build_media_replacement_empty_registry_response(__('No Media Library replacement registry rows are available for database replacement apply. Restore the database backup or roll back attachment metadata, then run Restart Replacement Plan again.', 'ultracache'));
        }

        $authorization = $this->authorize_media_replacement_destructive_action('database_apply', $args);
        if (empty($authorization['success'])) {
            return $authorization;
        }

        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        $limit = max(10, min(250, $limit));
        $duplicates_removed = $this->deduplicate_media_replacement_database_reference_rows();
        $groups = $this->get_media_replacement_database_apply_groups($limit);

        $batch_processed = 0;
        $batch_replaced  = 0;
        $batch_failed    = 0;
        $batch_excluded  = 0;
        $registry_sync_failed = 0;

        foreach ($groups as $group) {
            $group_result = $this->apply_media_replacement_database_row_group((array) $group);
            $ref_results  = isset($group_result['results']) && is_array($group_result['results']) ? $group_result['results'] : array();

            foreach ((array) $group as $row) {
                $ref_id = isset($row['id']) ? absint($row['id']) : 0;
                if ($ref_id <= 0) {
                    continue;
                }

                $batch_processed++;
                $result = isset($ref_results[$ref_id]) && is_array($ref_results[$ref_id])
                    ? $ref_results[$ref_id]
                    : array('status' => 'failed', 'message' => __('Database replacement did not return a result for this prepared reference.', 'ultracache'));
                $status  = isset($result['status']) ? (string) $result['status'] : 'failed';
                $message = isset($result['message']) ? (string) $result['message'] : '';

                if ('replaced' === $status) {
                    if ($this->update_media_replacement_database_apply_ref_result($ref_id, 'replaced', '')) {
                        $batch_replaced++;
                    } else {
                        $registry_sync_failed++;
                    }
                } elseif ('excluded' === $status) {
                    if ($this->update_media_replacement_database_apply_ref_result($ref_id, 'excluded', $message)) {
                        $batch_excluded++;
                    } else {
                        $registry_sync_failed++;
                    }
                } else {
                    if ($this->update_media_replacement_database_apply_ref_result($ref_id, 'failed', '' !== $message ? $message : __('Database replacement failed.', 'ultracache'))) {
                        $batch_failed++;
                    } else {
                        $registry_sync_failed++;
                    }
                }
            }
        }

        $summary = $this->get_media_replacement_database_apply_summary();
        $summary['duplicateRefsSkipped'] = (int) $duplicates_removed;
        $total = isset($summary['totalRefs']) ? max(0, (int) $summary['totalRefs']) : 0;
        $pending = isset($summary['pendingRefs']) ? max(0, (int) $summary['pendingRefs']) : 0;
        $replaced = isset($summary['replacedRefs']) ? max(0, (int) $summary['replacedRefs']) : 0;
        $failed = isset($summary['failedRefs']) ? max(0, (int) $summary['failedRefs']) : 0;
        $processed = max(0, $total - $pending);
        $has_more = $pending > 0;
        $progress = $total > 0 ? min(100, round(($processed / $total) * 100, 1)) : 100;

        if ($registry_sync_failed > 0) {
            return array(
                'success'            => false,
                'blocked'            => true,
                'retryRequired'      => true,
                'message'            => __('Database values were processed, but one or more replacement registry rows could not be persisted. Resume Do to reconcile the current database values.', 'ultracache'),
                'status'             => 'database_reconcile_required',
                'hasMore'            => true,
                'batchProcessedRefs' => $batch_processed,
                'batchReplacedRefs'  => $batch_replaced,
                'batchFailedRefs'    => $batch_failed,
                'batchExcludedRefs'  => $batch_excluded,
                'registrySyncFailed' => $registry_sync_failed,
            );
        }

        if (!$has_more) {
            $this->clear_media_replacement_destructive_authorization('database_apply');
        }

        return array(
            'success'              => true,
            'message'              => $has_more
                ? sprintf(
                    /* translators: 1: replaced references, 2: total planned references. */
                    __('Media Library replacement database replacement is in progress: %1$d of %2$d planned changes applied.', 'ultracache'),
                    (int) $replaced,
                    (int) $total
                )
                : sprintf(
                    /* translators: 1: replaced references, 2: failed references. */
                    __('Media Library replacement applied %1$d database replacements. Failed: %2$d.', 'ultracache'),
                    (int) $replaced,
                    (int) $failed
                ),
            'status'               => $has_more ? 'db_replacing' : 'db_replaced',
            'hasMore'              => $has_more,
            'batchSize'            => $limit,
            'batchProcessedRefs'   => $batch_processed,
            'batchReplacedRefs'    => $batch_replaced,
            'batchFailedRefs'      => $batch_failed,
            'duplicateRefsSkipped' => (int) $duplicates_removed,
            'referencesFound'      => $total,
            'matchedRefs'          => $total,
            'pendingRefs'          => $pending,
            'replacedRefs'         => $replaced,
            'failedRefs'           => $failed,
            'excludedRefs'         => isset($summary['excludedRefs']) ? (int) $summary['excludedRefs'] : 0,
            'serializedRefs'       => isset($summary['serializedRefs']) ? (int) $summary['serializedRefs'] : 0,
            'jsonRefs'             => isset($summary['jsonRefs']) ? (int) $summary['jsonRefs'] : 0,
            'refsScanned'          => $processed,
            'remainingRefsScan'    => 0,
            'progressPercent'      => $progress,
            'databaseReferencesMatched' => true,
            'databaseReplaced'     => !$has_more,
            'nextStep'             => $has_more
                ? __('Continue applying database replacements in chunks. Do not interrupt this step on large sites.', 'ultracache')
                : __('Next step: verify replaced database references before cleanup preview.', 'ultracache'),
        );
    }


    private function get_media_replacement_database_verify_rows($limit = 50)
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        $limit      = max(1, min(250, absint($limit)));

        if ('' === $refs_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, item_id, table_name, primary_key_column, primary_key_value, row_identity, column_name, old_fragment, new_fragment, serialized, json_detected, status FROM %i WHERE status IN (%s, %s) ORDER BY id ASC LIMIT %d',
                $refs_table,
                'replaced',
                'verify_failed',
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private function update_media_replacement_database_verify_ref_result($ref_id, $status, $message = '')
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        $ref_id     = absint($ref_id);
        $status     = in_array((string) $status, array('verified', 'verify_failed'), true) ? (string) $status : 'verify_failed';

        if ('' === $refs_table || $ref_id <= 0 || !($wpdb instanceof wpdb)) {
            return false;
        }

        return false !== $wpdb->update(
            $refs_table,
            array(
                'status'        => $status,
                'error_message' => '' !== (string) $message ? wp_strip_all_tags((string) $message) : null,
                'updated_at'    => current_time('mysql', true),
            ),
            array('id' => $ref_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }

    private function get_media_replacement_database_verify_summary()
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        if ('' === $refs_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS total_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS pending_verify, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS verified_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS verify_failed_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS pending_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS failed_refs, SUM(serialized) AS serialized_refs, SUM(json_detected) AS json_refs FROM %i WHERE status <> %s',
                'replaced',
                'verified',
                'verify_failed',
                'pending',
                'failed',
                $refs_table,
                'excluded'
            ),
            ARRAY_A
        );

        return array(
            'totalRefs'        => is_array($row) && isset($row['total_refs']) ? max(0, (int) $row['total_refs']) : 0,
            'pendingVerify'    => is_array($row) && isset($row['pending_verify']) ? max(0, (int) $row['pending_verify']) : 0,
            'verifiedRefs'     => is_array($row) && isset($row['verified_refs']) ? max(0, (int) $row['verified_refs']) : 0,
            'verifyFailedRefs' => is_array($row) && isset($row['verify_failed_refs']) ? max(0, (int) $row['verify_failed_refs']) : 0,
            'pendingRefs'      => is_array($row) && isset($row['pending_refs']) ? max(0, (int) $row['pending_refs']) : 0,
            'failedRefs'       => is_array($row) && isset($row['failed_refs']) ? max(0, (int) $row['failed_refs']) : 0,
            'serializedRefs'   => is_array($row) && isset($row['serialized_refs']) ? max(0, (int) $row['serialized_refs']) : 0,
            'jsonRefs'         => is_array($row) && isset($row['json_refs']) ? max(0, (int) $row['json_refs']) : 0,
        );
    }

    private function repair_media_replacement_serialized_string_lengths($stored_value)
    {
        $stored_value = (string) $stored_value;
        $length       = strlen($stored_value);

        if ('' === $stored_value || false === strpos($stored_value, 's:')) {
            return '';
        }

        $offset  = 0;
        $output  = '';
        $changed = false;

        while (preg_match('/s:(\d+):"/', $stored_value, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $match_start = (int) $matches[0][1];
            $header      = (string) $matches[0][0];
            $declared    = absint($matches[1][0]);
            $value_start = $match_start + strlen($header);
            $expected_end = $value_start + $declared;
            $value_end    = -1;

            if ($expected_end + 1 < $length && '";' === substr($stored_value, $expected_end, 2)) {
                $value_end = $expected_end;
            } else {
                $search_start = max($value_start, $expected_end - 16);
                $search_end   = min($length - 2, $expected_end + 128);
                for ($candidate = $search_start; $candidate <= $search_end; $candidate++) {
                    if ('";' === substr($stored_value, $candidate, 2)) {
                        $value_end = $candidate;
                        break;
                    }
                }
            }

            if ($value_end < $value_start) {
                return '';
            }

            $value  = substr($stored_value, $value_start, $value_end - $value_start);
            $actual = strlen($value);

            $output .= substr($stored_value, $offset, $match_start - $offset);
            $output .= 's:' . (string) $actual . ':"' . $value . '";';

            if ($actual !== $declared) {
                $changed = true;
            }

            $offset = $value_end + 2;
        }

        if (!$changed) {
            return '';
        }

        $output .= substr($stored_value, $offset);

        if (!function_exists('is_serialized') || !is_serialized($output)) {
            return '';
        }

        if ('b:0;' === $output) {
            return $output;
        }

        $decoded = $this->safe_unserialize_media_replacement_database_value($output);
        return !empty($decoded['success']) ? $output : '';
    }

    private function verify_media_replacement_serialized_database_value($stored_value)
    {
        $stored_value = (string) $stored_value;
        if (!function_exists('is_serialized') || !is_serialized($stored_value)) {
            $repaired = $this->repair_media_replacement_serialized_string_lengths($stored_value);
            return '' !== $repaired
                ? array('verified' => true, 'repaired' => true, 'value' => $repaired)
                : array('verified' => false, 'repaired' => false, 'value' => $stored_value);
        }

        if ('b:0;' === $stored_value) {
            return array('verified' => true, 'repaired' => false, 'value' => $stored_value);
        }

        $decoded = $this->safe_unserialize_media_replacement_database_value($stored_value);
        if (!empty($decoded['success'])) {
            return array('verified' => true, 'repaired' => false, 'value' => $stored_value);
        }

        if (!empty($decoded['object'])) {
            return array('verified' => false, 'repaired' => false, 'value' => $stored_value);
        }

        $repaired = $this->repair_media_replacement_serialized_string_lengths($stored_value);
        return '' !== $repaired
            ? array('verified' => true, 'repaired' => true, 'value' => $repaired)
            : array('verified' => false, 'repaired' => false, 'value' => $stored_value);
    }

    private function verify_media_replacement_database_ref(array $ref)
    {
        $ref_id       = isset($ref['id']) ? absint($ref['id']) : 0;
        $old_fragment = isset($ref['old_fragment']) ? (string) $ref['old_fragment'] : '';
        $new_fragment = isset($ref['new_fragment']) ? (string) $ref['new_fragment'] : '';

        if ($ref_id <= 0 || '' === $old_fragment || '' === $new_fragment || $old_fragment === $new_fragment) {
            return array('verified' => false, 'message' => __('Invalid planned database replacement row.', 'ultracache'));
        }

        $identity = $this->validate_media_replacement_database_ref_identity($ref);
        if (empty($identity['valid'])) {
            return array('verified' => false, 'message' => isset($identity['message']) ? (string) $identity['message'] : __('The planned database-row identity could not be verified.', 'ultracache'));
        }

        $stored_value = $this->get_media_replacement_database_row_value($ref);
        if (null === $stored_value) {
            return array('verified' => false, 'message' => __('The database row for this replacement could not be read.', 'ultracache'));
        }

        $stored_value = (string) $stored_value;
        $encoding     = $this->resolve_media_replacement_database_value_encoding(
            $stored_value,
            !empty($ref['serialized']),
            !empty($ref['json_detected'])
        );
        if (false !== strpos($stored_value, $old_fragment)) {
            return array('verified' => false, 'message' => __('The old image reference is still present in the database row.', 'ultracache'));
        }

        if (false === strpos($stored_value, $new_fragment)) {
            return array('verified' => false, 'message' => __('The new image reference is missing from the database row.', 'ultracache'));
        }

        if (!empty($encoding['serialized'])) {
            if (!function_exists('is_serialized') || !is_serialized($stored_value)) {
                return array('verified' => false, 'message' => __('The serialized database value is invalid after replacement. Verify does not modify site content.', 'ultracache'));
            }

            if ('b:0;' !== $stored_value) {
                $decoded_serialized = $this->safe_unserialize_media_replacement_database_value($stored_value);
                if (empty($decoded_serialized['success'])) {
                    return array('verified' => false, 'message' => __('The serialized database value could not be decoded after replacement. Verify does not modify site content.', 'ultracache'));
                }
            }
        }

        if (!empty($encoding['json_detected'])) {
            json_decode($stored_value, true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                return array('verified' => false, 'message' => __('The JSON database value could not be decoded after replacement.', 'ultracache'));
            }
        }

        $runtime_verification = $this->verify_media_replacement_option_runtime_state($ref, $stored_value);
        if (empty($runtime_verification['verified'])) {
            return array('verified' => false, 'message' => isset($runtime_verification['message']) ? (string) $runtime_verification['message'] : __('The WordPress option runtime cache could not be verified.', 'ultracache'));
        }

        return array('verified' => true, 'message' => '');
    }

    public function verify_media_library_replacement_database_replacements($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array(
                'success' => false,
                'message' => __('Media Library replacement registry tables are not available.', 'ultracache'),
            );
        }

        $args = is_array($args) ? $args : array();
        if (!$this->media_replacement_has_registry_rows()) {
            return $this->build_media_replacement_empty_registry_response(__('No Media Library replacement registry rows are available for database replacement verification. Restore the database backup or roll back attachment metadata, then run Restart Replacement Plan again.', 'ultracache'));
        }

        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        $limit = max(10, min(250, $limit));
        $rows  = $this->get_media_replacement_database_verify_rows($limit);

        $batch_processed = 0;
        $batch_verified  = 0;
        $batch_failed    = 0;

        foreach ($rows as $row) {
            $batch_processed++;
            $ref_id = isset($row['id']) ? absint($row['id']) : 0;
            $result = $this->verify_media_replacement_database_ref($row);
            if (!empty($result['verified'])) {
                $this->update_media_replacement_database_verify_ref_result($ref_id, 'verified', '');
                $batch_verified++;
            } else {
                $this->update_media_replacement_database_verify_ref_result($ref_id, 'verify_failed', isset($result['message']) ? (string) $result['message'] : __('Database replacement verification failed.', 'ultracache'));
                $batch_failed++;
            }
        }

        $summary = $this->get_media_replacement_database_verify_summary();
        $total = isset($summary['totalRefs']) ? max(0, (int) $summary['totalRefs']) : 0;
        $pending_verify = isset($summary['pendingVerify']) ? max(0, (int) $summary['pendingVerify']) : 0;
        $verified = isset($summary['verifiedRefs']) ? max(0, (int) $summary['verifiedRefs']) : 0;
        $verify_failed = isset($summary['verifyFailedRefs']) ? max(0, (int) $summary['verifyFailedRefs']) : 0;
        $processed = max(0, $total - $pending_verify);
        $has_more = $pending_verify > 0;
        $progress = $total > 0 ? min(100, round(($processed / $total) * 100, 1)) : 100;

        return array(
            'success'              => true,
            'message'              => $has_more
                ? sprintf(
                    /* translators: 1: verified references, 2: total references. */
                    __('Media Library replacement database verification is in progress: %1$d of %2$d replacements verified.', 'ultracache'),
                    (int) $verified,
                    (int) $total
                )
                : sprintf(
                    /* translators: 1: verified references, 2: failed references. */
                    __('Media Library replacement verified %1$d database replacements. Failed: %2$d.', 'ultracache'),
                    (int) $verified,
                    (int) $verify_failed
                ),
            'status'               => $has_more ? 'db_verifying' : 'db_verified',
            'hasMore'              => $has_more,
            'batchSize'            => $limit,
            'batchProcessedRefs'   => $batch_processed,
            'batchVerifiedRefs'    => $batch_verified,
            'batchVerifyFailedRefs'=> $batch_failed,
            'referencesFound'      => $total,
            'matchedRefs'          => $total,
            'pendingRefs'          => isset($summary['pendingRefs']) ? (int) $summary['pendingRefs'] : 0,
            'replacedRefs'         => $total,
            'failedRefs'           => isset($summary['failedRefs']) ? (int) $summary['failedRefs'] : 0,
            'verifiedRefs'         => $verified,
            'pendingVerifyRefs'    => $pending_verify,
            'verifyFailedRefs'     => $verify_failed,
            'serializedRefs'       => isset($summary['serializedRefs']) ? (int) $summary['serializedRefs'] : 0,
            'jsonRefs'             => isset($summary['jsonRefs']) ? (int) $summary['jsonRefs'] : 0,
            'refsScanned'          => $processed,
            'remainingRefsScan'    => 0,
            'progressPercent'      => $progress,
            'databaseReferencesMatched' => true,
            'databaseReplaced'     => true,
            'databaseVerified'     => !$has_more,
            'nextStep'             => $has_more
                ? __('Continue verifying database replacements in chunks. Do not interrupt this step on large sites.', 'ultracache')
                : __('Next step: run Cleanup Preview, then use Delete Originals only after reviewing the candidates.', 'ultracache'),
        );
    }


    private function update_media_replacement_database_rollback_ref_result($ref_id, $status, $message = '')
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        $ref_id     = absint($ref_id);
        $status     = in_array((string) $status, array('restored', 'rollback_failed'), true) ? (string) $status : 'rollback_failed';

        if ('' === $refs_table || $ref_id <= 0 || !($wpdb instanceof wpdb)) {
            return false;
        }

        return false !== $wpdb->update(
            $refs_table,
            array(
                'status'        => $status,
                'error_message' => '' !== (string) $message ? wp_strip_all_tags((string) $message) : null,
                'updated_at'    => current_time('mysql', true),
            ),
            array('id' => $ref_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }

    private function get_media_replacement_database_rollback_summary()
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        if ('' === $refs_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS total_refs, SUM(CASE WHEN status IN (%s, %s, %s) THEN 1 ELSE 0 END) AS pending_rollback, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS restored_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS rollback_failed_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS pending_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS failed_refs, SUM(serialized) AS serialized_refs, SUM(json_detected) AS json_refs FROM %i WHERE status <> %s',
                'verified',
                'replaced',
                'verify_failed',
                'restored',
                'rollback_failed',
                'pending',
                'failed',
                $refs_table,
                'excluded'
            ),
            ARRAY_A
        );

        return array(
            'totalRefs'          => is_array($row) && isset($row['total_refs']) ? max(0, (int) $row['total_refs']) : 0,
            'pendingRollback'    => is_array($row) && isset($row['pending_rollback']) ? max(0, (int) $row['pending_rollback']) : 0,
            'restoredRefs'       => is_array($row) && isset($row['restored_refs']) ? max(0, (int) $row['restored_refs']) : 0,
            'rollbackFailedRefs' => is_array($row) && isset($row['rollback_failed_refs']) ? max(0, (int) $row['rollback_failed_refs']) : 0,
            'pendingRefs'        => is_array($row) && isset($row['pending_refs']) ? max(0, (int) $row['pending_refs']) : 0,
            'failedRefs'         => is_array($row) && isset($row['failed_refs']) ? max(0, (int) $row['failed_refs']) : 0,
            'serializedRefs'     => is_array($row) && isset($row['serialized_refs']) ? max(0, (int) $row['serialized_refs']) : 0,
            'jsonRefs'           => is_array($row) && isset($row['json_refs']) ? max(0, (int) $row['json_refs']) : 0,
        );
    }

    private function get_media_replacement_database_rollback_groups($limit = 50)
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        $limit = max(1, min(250, absint($limit)));
        if ('' === $refs_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT r.id, r.item_id, r.table_name, r.primary_key_column, r.primary_key_value, r.row_identity, r.column_name, r.old_fragment, r.new_fragment, r.serialized, r.json_detected, r.status
                 FROM %i r
                 INNER JOIN (
                     SELECT seed.table_name, seed.primary_key_column, seed.primary_key_value, seed.row_identity, seed.column_name, MIN(seed.id) AS first_id
                     FROM (
                         SELECT id, table_name, primary_key_column, primary_key_value, row_identity, column_name
                         FROM %i
                         WHERE status IN (%s, %s, %s)
                         ORDER BY id DESC
                         LIMIT %d
                     ) seed
                     GROUP BY seed.table_name, seed.primary_key_column, seed.primary_key_value, seed.row_identity, seed.column_name
                 ) grouped
                   ON grouped.table_name = r.table_name
                  AND grouped.primary_key_column = r.primary_key_column
                  AND grouped.primary_key_value = r.primary_key_value
                  AND grouped.row_identity = r.row_identity
                  AND grouped.column_name = r.column_name
                 WHERE r.status IN (%s, %s, %s, %s)
                 ORDER BY grouped.first_id DESC, r.id DESC',
                $refs_table,
                $refs_table,
                'verified',
                'replaced',
                'verify_failed',
                $limit,
                'verified',
                'replaced',
                'verify_failed',
                'restored'
            ),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            return array();
        }

        $groups = array();
        foreach ($rows as $row) {
            $key = $this->get_media_replacement_database_physical_row_key((array) $row);
            if (!isset($groups[$key])) {
                $groups[$key] = array();
            }
            $groups[$key][] = (array) $row;
        }

        return array_values($groups);
    }

    private function rollback_media_replacement_database_row_group(array $refs)
    {
        $refs = array_values(array_filter($refs, 'is_array'));
        if (empty($refs)) {
            return array('results' => array());
        }

        $first_ref = $refs[0];
        $group_key = $this->get_media_replacement_database_physical_row_key($first_ref);
        $results = array();
        $work_refs = array();

        foreach ($refs as $ref) {
            $ref_id = absint($ref['id'] ?? 0);
            if ('restored' === (string) ($ref['status'] ?? '')) {
                if ($ref_id > 0) {
                    $results[$ref_id] = array('status' => 'restored', 'message' => '', 'already_restored' => true);
                }
                continue;
            }

            $old_fragment = (string) ($ref['old_fragment'] ?? '');
            $new_fragment = (string) ($ref['new_fragment'] ?? '');
            if ($ref_id <= 0 || '' === $old_fragment || '' === $new_fragment || $old_fragment === $new_fragment) {
                if ($ref_id > 0) {
                    $results[$ref_id] = array('status' => 'rollback_failed', 'message' => __('Invalid planned database rollback row.', 'ultracache'));
                }
                continue;
            }
            if (!hash_equals($group_key, $this->get_media_replacement_database_physical_row_key($ref))) {
                $results[$ref_id] = array('status' => 'rollback_failed', 'message' => __('The database rollback batch mixed different physical rows.', 'ultracache'));
                continue;
            }
            $identity = $this->validate_media_replacement_database_ref_identity($ref);
            if (empty($identity['valid'])) {
                $results[$ref_id] = array('status' => 'rollback_failed', 'message' => (string) ($identity['message'] ?? __('The planned database-row identity could not be verified.', 'ultracache')));
                continue;
            }
            $work_refs[] = $ref;
        }

        if (empty($work_refs)) {
            return array('results' => $results);
        }

        $base_ref = $work_refs[0];
        $stored_value = $this->get_media_replacement_database_row_value($base_ref);
        if (null === $stored_value) {
            foreach ($work_refs as $ref) {
                $results[absint($ref['id'])] = array('status' => 'rollback_failed', 'message' => __('The database row for this rollback could not be read.', 'ultracache'));
            }
            return array('results' => $results);
        }
        $stored_value = (string) $stored_value;

        $planned_serialized = !empty($base_ref['serialized']);
        $planned_json = !empty($base_ref['json_detected']);
        foreach ($work_refs as $ref) {
            if ((!empty($ref['serialized'])) !== $planned_serialized || (!empty($ref['json_detected'])) !== $planned_json) {
                $message = __('The prepared references for this database row disagree about its stored representation. The current value was preserved.', 'ultracache');
                foreach ($work_refs as $row_ref) {
                    $results[absint($row_ref['id'])] = array('status' => 'rollback_failed', 'message' => $message);
                }
                return array('results' => $results);
            }
        }

        $encoding = $this->validate_media_replacement_database_prepared_encoding($stored_value, $planned_serialized, $planned_json);
        if (empty($encoding['valid'])) {
            $message = (string) ($encoding['message'] ?? __('The database row no longer matches the representation recorded by Prepare. The current value was preserved.', 'ultracache'));
            foreach ($work_refs as $ref) {
                $results[absint($ref['id'])] = array('status' => 'rollback_failed', 'message' => $message, 'conflict' => true);
            }
            return array('results' => $results);
        }

        $working_value = $stored_value;
        $changed_ref_ids = array();
        $already_ref_ids = array();

        /* Validate the whole physical row before producing any rollback write. */
        foreach ($work_refs as $ref) {
            $ref_id = absint($ref['id']);
            $old_fragment = (string) $ref['old_fragment'];
            $new_fragment = (string) $ref['new_fragment'];
            $has_old = false !== strpos($working_value, $old_fragment);
            $has_new = false !== strpos($working_value, $new_fragment);

            if (!$has_new && $has_old) {
                $already_ref_ids[$ref_id] = true;
                continue;
            }
            if (!$has_new) {
                $message = __('A prepared replacement reference is no longer present in this database row. The newer row was preserved and no grouped rollback was written.', 'ultracache');
                foreach ($work_refs as $row_ref) {
                    $row_id = absint($row_ref['id']);
                    if ('restored' !== (string) ($row_ref['status'] ?? '')) {
                        $results[$row_id] = array('status' => 'rollback_failed', 'message' => $message, 'conflict' => true);
                    }
                }
                return array('results' => $results);
            }

            $replacement = $this->build_media_replacement_database_value(
                $working_value,
                $new_fragment,
                $old_fragment,
                $encoding['serialized'],
                $encoding['json_detected']
            );
            if (empty($replacement['changed'])) {
                $message = (string) ($replacement['message'] ?? __('No grouped database rollback value was produced.', 'ultracache'));
                foreach ($work_refs as $row_ref) {
                    $row_id = absint($row_ref['id']);
                    if ('restored' !== (string) ($row_ref['status'] ?? '')) {
                        $results[$row_id] = array('status' => 'rollback_failed', 'message' => $message);
                    }
                }
                return array('results' => $results);
            }

            $candidate = (string) ($replacement['value'] ?? '');
            $validation = $this->validate_media_replacement_database_built_value(
                $candidate,
                $new_fragment,
                $old_fragment,
                $encoding['serialized'],
                $encoding['json_detected']
            );
            if (empty($validation['valid'])) {
                $message = (string) ($validation['message'] ?? __('The grouped database rollback value failed pre-write validation.', 'ultracache'));
                foreach ($work_refs as $row_ref) {
                    $row_id = absint($row_ref['id']);
                    if ('restored' !== (string) ($row_ref['status'] ?? '')) {
                        $results[$row_id] = array('status' => 'rollback_failed', 'message' => $message);
                    }
                }
                return array('results' => $results);
            }

            $working_value = $candidate;
            $changed_ref_ids[$ref_id] = true;
        }

        if ($working_value !== $stored_value) {
            $write_result = $this->compare_and_swap_media_replacement_database_row_value($base_ref, $stored_value, $working_value);
            if (empty($write_result['updated'])) {
                $message = !empty($write_result['conflict'])
                    ? __('The database row changed after it was read. The newer value was preserved and no grouped rollback was written.', 'ultracache')
                    : (string) ($write_result['message'] ?? __('The database row could not be restored.', 'ultracache'));
                foreach ($work_refs as $ref) {
                    $results[absint($ref['id'])] = array('status' => 'rollback_failed', 'message' => $message, 'conflict' => !empty($write_result['conflict']));
                }
                return array('results' => $results);
            }

            $verified_value = $this->get_media_replacement_database_row_value($base_ref);
            if (null === $verified_value) {
                $this->compare_and_swap_media_replacement_database_row_value($base_ref, $working_value, $stored_value);
                foreach ($work_refs as $ref) {
                    $results[absint($ref['id'])] = array('status' => 'rollback_failed', 'message' => __('The grouped database rollback could not be read back for verification.', 'ultracache'));
                }
                return array('results' => $results);
            }
            $verified_value = (string) $verified_value;
            foreach ($work_refs as $ref) {
                if (false === strpos($verified_value, (string) $ref['old_fragment']) || false !== strpos($verified_value, (string) $ref['new_fragment'])) {
                    $this->compare_and_swap_media_replacement_database_row_value($base_ref, $working_value, $stored_value);
                    foreach ($work_refs as $row_ref) {
                        $results[absint($row_ref['id'])] = array('status' => 'rollback_failed', 'message' => __('One or more prepared references could not be verified after the grouped database rollback.', 'ultracache'));
                    }
                    return array('results' => $results);
                }
            }

            if (!empty($encoding['serialized'])) {
                $serialized_check = $this->verify_media_replacement_serialized_database_value($verified_value);
                if (empty($serialized_check['verified']) || !empty($serialized_check['repaired'])) {
                    $this->compare_and_swap_media_replacement_database_row_value($base_ref, $working_value, $stored_value);
                    foreach ($work_refs as $ref) {
                        $results[absint($ref['id'])] = array('status' => 'rollback_failed', 'message' => __('The serialized database value did not verify cleanly after grouped rollback.', 'ultracache'));
                    }
                    return array('results' => $results);
                }
            }
            if (!empty($encoding['json_detected'])) {
                json_decode($verified_value, true);
                if (JSON_ERROR_NONE !== json_last_error()) {
                    $this->compare_and_swap_media_replacement_database_row_value($base_ref, $working_value, $stored_value);
                    foreach ($work_refs as $ref) {
                        $results[absint($ref['id'])] = array('status' => 'rollback_failed', 'message' => __('The JSON-like database value did not verify after grouped rollback.', 'ultracache'));
                    }
                    return array('results' => $results);
                }
            }

            $runtime = $this->verify_media_replacement_option_runtime_state($base_ref, $verified_value);
            if (empty($runtime['verified'])) {
                $this->compare_and_swap_media_replacement_database_row_value($base_ref, $working_value, $stored_value);
                foreach ($work_refs as $ref) {
                    $results[absint($ref['id'])] = array('status' => 'rollback_failed', 'message' => (string) ($runtime['message'] ?? __('The WordPress option runtime cache did not verify after grouped rollback.', 'ultracache')));
                }
                return array('results' => $results);
            }
            $this->finalize_media_replacement_option_write($write_result);
        } else {
            $runtime = $this->verify_media_replacement_option_runtime_state($base_ref, $stored_value);
            if (empty($runtime['verified'])) {
                foreach ($work_refs as $ref) {
                    $results[absint($ref['id'])] = array('status' => 'rollback_failed', 'message' => (string) ($runtime['message'] ?? __('The WordPress option runtime cache did not verify for the already-restored database row.', 'ultracache')));
                }
                return array('results' => $results);
            }
        }

        foreach ($work_refs as $ref) {
            $results[absint($ref['id'])] = array('status' => 'restored', 'message' => '', 'already_restored' => isset($already_ref_ids[absint($ref['id'])]));
        }
        return array('results' => $results);
    }

    public function rollback_media_library_replacement_database_replacements($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array('success' => false, 'message' => __('Media Library replacement registry tables are not available.', 'ultracache'));
        }

        $args = is_array($args) ? $args : array();
        if (!$this->media_replacement_has_registry_rows()) {
            return $this->build_media_replacement_empty_registry_response(__('No Media Library replacement registry rows are available for database rollback. Restore the database backup, then run Restart Replacement Plan again.', 'ultracache'));
        }

        $limit = max(10, min(250, absint($args['limit'] ?? 50)));
        $groups = $this->get_media_replacement_database_rollback_groups($limit);
        $batch_processed = 0;
        $batch_restored = 0;
        $batch_failed = 0;
        $registry_sync_failed = 0;

        foreach ($groups as $group) {
            $group_result = $this->rollback_media_replacement_database_row_group((array) $group);
            $ref_results = isset($group_result['results']) && is_array($group_result['results']) ? $group_result['results'] : array();
            foreach ((array) $group as $ref) {
                $ref_id = absint($ref['id'] ?? 0);
                if ($ref_id <= 0 || 'restored' === (string) ($ref['status'] ?? '')) {
                    continue;
                }
                $batch_processed++;
                $result = isset($ref_results[$ref_id]) ? (array) $ref_results[$ref_id] : array('status' => 'rollback_failed', 'message' => __('Database rollback did not return a result for this prepared reference.', 'ultracache'));
                $status = (string) ($result['status'] ?? 'rollback_failed');
                $message = (string) ($result['message'] ?? '');
                if ('restored' === $status) {
                    if ($this->update_media_replacement_database_rollback_ref_result($ref_id, 'restored', '')) {
                        $batch_restored++;
                    } else {
                        $registry_sync_failed++;
                    }
                } else {
                    if ($this->update_media_replacement_database_rollback_ref_result($ref_id, 'rollback_failed', '' !== $message ? $message : __('Database rollback failed.', 'ultracache'))) {
                        $batch_failed++;
                    } else {
                        $registry_sync_failed++;
                    }
                }
            }
        }

        $summary = $this->get_media_replacement_database_rollback_summary();
        $total = max(0, (int) ($summary['totalRefs'] ?? 0));
        $pending = max(0, (int) ($summary['pendingRollback'] ?? 0));
        $restored = max(0, (int) ($summary['restoredRefs'] ?? 0));
        $rollback_failed = max(0, (int) ($summary['rollbackFailedRefs'] ?? 0));
        $has_more = $pending > 0;

        if ($registry_sync_failed > 0) {
            return array(
                'success' => false,
                'blocked' => true,
                'retryRequired' => true,
                'status' => 'database_rollback_reconcile_required',
                'hasMore' => true,
                'message' => __('Database rows were restored, but one or more rollback registry statuses could not be persisted. Resume Rollback Replacement to reconcile the current values.', 'ultracache'),
                'registrySyncFailed' => $registry_sync_failed,
                'rollbackFailedRefs' => $rollback_failed,
            );
        }

        return array(
            'success' => true,
            'message' => $has_more
                ? sprintf(
                    /* translators: 1: number of restored references, 2: number of references remaining. */
                    __('Media Library replacement database rollback is in progress: %1$d references restored; %2$d remain.', 'ultracache'),
                    $restored,
                    $pending
                )
                : sprintf(
                    /* translators: 1: number of restored references, 2: number of references that failed to roll back. */
                    __('Media Library replacement database rollback restored %1$d references. Failed: %2$d.', 'ultracache'),
                    $restored,
                    $rollback_failed
                ),
            'status' => $has_more ? 'db_rollback_running' : 'db_rollback_complete',
            'hasMore' => $has_more,
            'batchSize' => $limit,
            'batchProcessedRefs' => $batch_processed,
            'batchRestoredRefs' => $batch_restored,
            'batchRollbackFailedRefs' => $batch_failed,
            'referencesFound' => $total,
            'restoredRefs' => $restored,
            'pendingRollbackRefs' => $pending,
            'rollbackFailedRefs' => $rollback_failed,
            'databaseRolledBack' => !$has_more && 0 === $rollback_failed,
            'progressPercent' => ($restored + $rollback_failed + $pending) > 0 ? min(100, round((($restored + $rollback_failed) / ($restored + $rollback_failed + $pending)) * 100, 1)) : 100,
        );
    }


    private function get_media_replacement_database_preview_summary()
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        if ('' === $refs_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $summary = array(
            'totalRefs'        => 0,
            'pendingRefs'      => 0,
            'replacedRefs'     => 0,
            'verifiedRefs'     => 0,
            'failedRefs'       => 0,
            'verifyFailedRefs' => 0,
            'restoredRefs'     => 0,
            'rollbackFailedRefs' => 0,
            'excludedRefs'     => 0,
            'serializedRefs'   => 0,
            'jsonRefs'         => 0,
            'plainRefs'        => 0,
            'tables'           => array(),
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT status, COUNT(*) AS ref_count, SUM(serialized) AS serialized_refs, SUM(json_detected) AS json_refs FROM %i GROUP BY status',
                $refs_table
            ),
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $status = isset($row['status']) ? sanitize_key((string) $row['status']) : '';
            $count  = isset($row['ref_count']) ? max(0, (int) $row['ref_count']) : 0;
            if ('excluded' === $status) {
                $summary['excludedRefs'] += $count;
                continue;
            }

            $summary['totalRefs'] += $count;
            if ('pending' === $status) {
                $summary['pendingRefs'] += $count;
            } elseif (in_array($status, array('replaced', 'applied', 'verified'), true)) {
                $summary['replacedRefs'] += $count;
                if ('verified' === $status) {
                    $summary['verifiedRefs'] += $count;
                }
            } elseif ('restored' === $status) {
                $summary['restoredRefs'] += $count;
            } elseif (in_array($status, array('failed', 'verify_failed', 'rollback_failed'), true)) {
                $summary['failedRefs'] += $count;
                if ('verify_failed' === $status) {
                    $summary['verifyFailedRefs'] += $count;
                }
                if ('rollback_failed' === $status) {
                    $summary['rollbackFailedRefs'] += $count;
                }
            }
            $summary['serializedRefs'] += isset($row['serialized_refs']) ? max(0, (int) $row['serialized_refs']) : 0;
            $summary['jsonRefs']       += isset($row['json_refs']) ? max(0, (int) $row['json_refs']) : 0;
        }

        $summary['plainRefs'] = max(0, (int) $summary['totalRefs'] - (int) $summary['serializedRefs'] - (int) $summary['jsonRefs']);

        $table_rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT table_name, COUNT(*) AS ref_count FROM %i WHERE status <> %s GROUP BY table_name ORDER BY ref_count DESC, table_name ASC',
                $refs_table,
                'excluded'
            ),
            ARRAY_A
        );

        foreach ((array) $table_rows as $row) {
            $table_name = isset($row['table_name']) ? $this->sanitize_media_replacement_db_identifier((string) $row['table_name'], 191) : '';
            $count      = isset($row['ref_count']) ? max(0, (int) $row['ref_count']) : 0;
            if ('' === $table_name || $count <= 0) {
                continue;
            }
            $summary['tables'][] = array(
                'table' => $table_name,
                'count' => $count,
            );
        }

        return $summary;
    }

    private function get_media_replacement_database_preview_rows($limit = 200, $offset = 0)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $refs_table  = $this->get_media_replacement_refs_table_name();
        $limit       = max(1, min(500, absint($limit)));
        $offset      = max(0, absint($offset));

        if ('' === $items_table || '' === $refs_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT r.id, r.item_id, r.table_name, r.primary_key_column, r.primary_key_value, r.column_name, r.old_fragment, r.new_fragment, r.serialized, r.json_detected, r.status, r.error_message, i.attachment_id, i.old_relative_path, i.new_relative_path FROM %i r LEFT JOIN %i i ON r.item_id = i.id WHERE r.status <> %s ORDER BY r.id ASC LIMIT %d OFFSET %d',
                $refs_table,
                $items_table,
                'excluded',
                $limit,
                $offset
            ),
            ARRAY_A
        );

        $items = array();
        foreach ((array) $rows as $row) {
            $attachment_id = isset($row['attachment_id']) ? absint($row['attachment_id']) : 0;
            $title = $attachment_id > 0 ? get_the_title($attachment_id) : '';
            $title = is_string($title) && '' !== $title ? $title : sprintf(
                /* translators: %d: attachment ID. */
                __('Attachment #%d', 'ultracache'),
                $attachment_id
            );

            $items[] = array(
                'id'               => isset($row['id']) ? absint($row['id']) : 0,
                'itemId'           => isset($row['item_id']) ? absint($row['item_id']) : 0,
                'attachmentId'     => $attachment_id,
                'title'            => $title,
                'oldRelativePath'  => isset($row['old_relative_path']) ? ltrim(str_replace('\\', '/', (string) $row['old_relative_path']), '/') : '',
                'newRelativePath'  => isset($row['new_relative_path']) ? ltrim(str_replace('\\', '/', (string) $row['new_relative_path']), '/') : '',
                'tableName'        => isset($row['table_name']) ? $this->sanitize_media_replacement_db_identifier((string) $row['table_name'], 191) : '',
                'primaryKeyColumn' => isset($row['primary_key_column']) ? $this->sanitize_media_replacement_db_identifier((string) $row['primary_key_column'], 64) : '',
                'primaryKeyValue'  => isset($row['primary_key_value']) ? substr(sanitize_text_field((string) $row['primary_key_value']), 0, 191) : '',
                'rowIdentity'      => isset($row['row_identity']) ? substr((string) $row['row_identity'], 0, 191) : '',
                'columnName'       => isset($row['column_name']) ? $this->sanitize_media_replacement_db_identifier((string) $row['column_name'], 64) : '',
                'oldFragment'      => isset($row['old_fragment']) ? wp_strip_all_tags((string) $row['old_fragment']) : '',
                'newFragment'      => isset($row['new_fragment']) ? wp_strip_all_tags((string) $row['new_fragment']) : '',
                'serialized'       => !empty($row['serialized']),
                'jsonDetected'     => !empty($row['json_detected']),
                'status'           => isset($row['status']) ? sanitize_key((string) $row['status']) : 'pending',
                'errorMessage'     => isset($row['error_message']) ? wp_strip_all_tags((string) $row['error_message']) : '',
            );
        }

        return $items;
    }

    public function get_media_library_replacement_database_replacement_preview($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array(
                'success' => false,
                'message' => __('Media Library replacement registry tables are not available.', 'ultracache'),
            );
        }

        $args = is_array($args) ? $args : array();
        $issue_confirmation_token = !array_key_exists('issue_confirmation_token', $args) || !empty($args['issue_confirmation_token']);
        if (!$this->media_replacement_has_registry_rows()) {
            $empty = $this->build_media_replacement_empty_registry_response(__('No Media Library replacement registry rows are available for database replacement preview. Restore the database backup or roll back attachment metadata, then run Restart Replacement Plan again.', 'ultracache'));
            $empty['hasReplacementPreview'] = false;
            return $empty;
        }

        $limit  = isset($args['limit']) ? absint($args['limit']) : 200;
        $offset = isset($args['offset']) ? absint($args['offset']) : 0;
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $duplicates_removed = $this->deduplicate_media_replacement_database_reference_rows();
        $summary = $this->get_media_replacement_database_preview_summary();
        if (empty($summary)) {
            return array(
                'success' => false,
                'message' => __('Database replacement preview is not available for the current Media Library replacement workflow.', 'ultracache'),
                'hasReplacementPreview' => false,
            );
        }

        $summary['duplicateRefsSkipped'] = (int) $duplicates_removed;
        $total = isset($summary['totalRefs']) ? max(0, (int) $summary['totalRefs']) : 0;
        $pending_refs = isset($summary['pendingRefs']) ? max(0, (int) $summary['pendingRefs']) : 0;
        $verified_refs = isset($summary['verifiedRefs']) ? max(0, (int) $summary['verifiedRefs']) : 0;
        $verify_failed_refs = isset($summary['verifyFailedRefs']) ? max(0, (int) $summary['verifyFailedRefs']) : 0;
        $restored_refs = isset($summary['restoredRefs']) ? max(0, (int) $summary['restoredRefs']) : 0;
        $rollback_failed_refs = isset($summary['rollbackFailedRefs']) ? max(0, (int) $summary['rollbackFailedRefs']) : 0;
        $replaced_refs = isset($summary['replacedRefs']) ? max(0, (int) $summary['replacedRefs']) : 0;
        $pending_verify_refs = max(0, $replaced_refs - $verified_refs);
        $items = $total > 0 ? $this->get_media_replacement_database_preview_rows($limit, $offset) : array();
        $has_more = ($offset + count($items)) < $total;
        $database_replaced = $total > 0 && 0 === $pending_refs;

        if ($pending_refs > 0) {
            $next_step = __('Next step: apply database replacements in chunks with serialized-aware handling. Site content has not been changed yet.', 'ultracache');
            $message = sprintf(
                /* translators: %d: database reference count. */
                __('Media Library replacement database replacement preview is ready with %d planned changes. No database content was changed.', 'ultracache'),
                $total
            );
        } elseif ($pending_verify_refs > 0) {
            $next_step = __('Next step: verify replaced database references before rollback/cleanup planning.', 'ultracache');
            $message = sprintf(
                /* translators: %d: database reference count. */
                __('Media Library replacement has applied %d database replacements. Verification is still pending for some rows.', 'ultracache'),
                $total
            );
        } elseif ($verify_failed_refs > 0) {
            $next_step = __('Review failed database verification rows before cleanup. Verify does not repair or modify site content.', 'ultracache');
            $message = sprintf(
                /* translators: %d: failed verification count. */
                __('Media Library replacement verification found %d database row that still needs attention.', 'ultracache'),
                $verify_failed_refs
            );
        } elseif ($rollback_failed_refs > 0) {
            $next_step = __('Review rollback failures before cleanup. Original files should remain in place.', 'ultracache');
            $message = sprintf(
                /* translators: %d: failed rollback count. */
                __('Media Library replacement database rollback found %d row that still needs attention.', 'ultracache'),
                $rollback_failed_refs
            );
        } elseif ($total > 0 && $restored_refs === $total) {
            $next_step = __('Database reference rollback is complete. Original files and attachment metadata still remain; cleanup should not run yet.', 'ultracache');
            $message = sprintf(
                /* translators: %d: restored database reference count. */
                __('Media Library replacement has restored %d database references.', 'ultracache'),
                $restored_refs
            );
        } elseif ($total > 0) {
            $next_step = __('Next step: run Cleanup Preview, then use Delete Originals only after reviewing the candidates.', 'ultracache');
            $message = sprintf(
                /* translators: %d: verified database reference count. */
                __('Media Library replacement has verified %d database replacements.', 'ultracache'),
                $verified_refs
            );
        } else {
            $next_step = __('No database replacements are pending for this workflow. The next step is verification/cleanup planning, not DB replacement.', 'ultracache');
            $message = __('Media Library replacement database replacement preview found no old image references to apply. No database content changes are needed for this workflow.', 'ultracache');
        }

        $response = array(
            'success'               => true,
            'message'               => $message,
            'hasReplacementPreview' => true,
            'summary'               => $summary,
            'items'                 => $items,
            'limit'                 => $limit,
            'offset'                => $offset,
            'returned'              => count($items),
            'hasMore'               => $has_more,
            'nextOffset'            => $has_more ? $offset + count($items) : $offset,
            'previousOffset'        => max(0, $offset - $limit),
            'databasePreviewOnly'   => !$database_replaced,
            'databaseReplaced'      => $database_replaced,
            'databaseVerified'      => $total > 0 && $verified_refs === $total,
            'databaseRolledBack'    => $total > 0 && $restored_refs === $total,
            'nextStep'              => $next_step,
        );

        return $this->add_media_replacement_confirmation_token_to_response($response, 'database_apply', $issue_confirmation_token && $pending_refs > 0);
    }

}
