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

    private function get_media_replacement_reference_scan_tables()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return array();
        }

        return array(
            array(
                'table'   => (string) $wpdb->posts,
                'primary' => 'ID',
                'columns' => array('post_content', 'post_excerpt'),
            ),
            array(
                'table'   => (string) $wpdb->postmeta,
                'primary' => 'meta_id',
                'columns' => array('meta_value'),
            ),
            array(
                'table'   => (string) $wpdb->options,
                'primary' => 'option_id',
                'columns' => array('option_value'),
            ),
            array(
                'table'   => (string) $wpdb->termmeta,
                'primary' => 'meta_id',
                'columns' => array('meta_value'),
            ),
        );
    }

    private function sanitize_media_replacement_db_identifier($identifier, $max_length = 191)
    {
        $identifier = preg_replace('/[^A-Za-z0-9_]/', '', (string) $identifier);
        $max_length = max(1, absint($max_length));
        return substr($identifier, 0, $max_length);
    }

    private function get_media_replacement_own_table_names()
    {
        return array_filter(array(
            $this->get_media_replacement_items_table_name(),
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

    private function is_media_replacement_allowed_database_table_name($table_name)
    {
        global $wpdb;

        $table_name = $this->sanitize_media_replacement_db_identifier((string) $table_name, 191);
        if ('' === $table_name || !($wpdb instanceof wpdb) || $this->is_media_replacement_own_table_name($table_name)) {
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

    private function get_media_replacement_reference_rows($job_id, $limit = 10)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $job_id      = sanitize_key((string) $job_id);
        $limit       = max(1, min(100, absint($limit)));

        if ('' === $items_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, attachment_id, item_scope, size_name, old_relative_path, old_url, new_relative_path, new_url, status FROM %i WHERE job_id = %s AND status = %s ORDER BY id ASC LIMIT %d',
                $items_table,
                $job_id,
                'metadata_updated',
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
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

    private function insert_media_replacement_reference_row($job_id, $item_id, $table_name, $primary_key_column, $primary_key_value, $column_name, $old_fragment, $new_fragment, $stored_value)
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        $job_id     = sanitize_key((string) $job_id);
        $item_id    = absint($item_id);

        if ('' === $refs_table || '' === $job_id || $item_id <= 0 || !($wpdb instanceof wpdb)) {
            return false;
        }

        $table_name         = $this->sanitize_media_replacement_db_identifier($table_name, 191);
        $primary_key_column = $this->sanitize_media_replacement_db_identifier($primary_key_column, 64);
        $column_name        = $this->sanitize_media_replacement_db_identifier($column_name, 64);
        $primary_key_value  = substr(sanitize_text_field((string) $primary_key_value), 0, 191);
        $old_fragment       = (string) $old_fragment;
        $new_fragment       = (string) $new_fragment;
        $stored_value       = (string) $stored_value;

        if ('' === $table_name || '' === $primary_key_column || '' === $primary_key_value || '' === $column_name || '' === $old_fragment || '' === $new_fragment) {
            return false;
        }

        $now      = current_time('mysql', true);
        $ref_hash = md5($job_id . '|' . $item_id . '|' . $table_name . '|' . $primary_key_column . '|' . $primary_key_value . '|' . $column_name . '|' . md5($old_fragment));

        $row = array(
            'job_id'             => $job_id,
            'item_id'            => $item_id,
            'ref_hash'           => $ref_hash,
            'table_name'         => $table_name,
            'primary_key_column' => $primary_key_column,
            'primary_key_value'  => $primary_key_value,
            'column_name'        => $column_name,
            'old_value_hash'     => md5($stored_value),
            'new_value_hash'     => md5($new_fragment),
            'old_fragment'       => $old_fragment,
            'new_fragment'       => $new_fragment,
            'serialized'         => function_exists('is_serialized') && is_serialized($stored_value) ? 1 : 0,
            'json_detected'      => $this->is_media_replacement_json_like_value($stored_value) ? 1 : 0,
            'status'             => 'pending',
            'error_message'      => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        );

        return false !== $wpdb->replace(
            $refs_table,
            $row,
            array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s')
        );
    }

    private function scan_media_replacement_item_database_references(array $row)
    {
        global $wpdb;

        $item_id = isset($row['id']) ? absint($row['id']) : 0;
        if ($item_id <= 0 || !($wpdb instanceof wpdb)) {
            return array('scanned' => false, 'refs' => 0, 'message' => __('Invalid Media Library replacement reference row.', 'ultracache'));
        }

        $fragments = $this->get_media_replacement_reference_fragments($row);
        if (empty($fragments)) {
            return array('scanned' => false, 'refs' => 0, 'message' => __('Old and new image URL/path fragments are missing for reference scan.', 'ultracache'));
        }

        $found = 0;
        foreach ($this->get_media_replacement_reference_scan_tables() as $table_spec) {
            $table_name = isset($table_spec['table']) ? (string) $table_spec['table'] : '';
            $primary    = isset($table_spec['primary']) ? (string) $table_spec['primary'] : '';
            $columns    = isset($table_spec['columns']) && is_array($table_spec['columns']) ? $table_spec['columns'] : array();

            if ('' === $table_name || '' === $primary || empty($columns)) {
                continue;
            }

            foreach ($columns as $column) {
                $column = (string) $column;
                if ('' === $column) {
                    continue;
                }

                foreach ($fragments as $fragment) {
                    $old_fragment = isset($fragment['old']) ? (string) $fragment['old'] : '';
                    $new_fragment = isset($fragment['new']) ? (string) $fragment['new'] : '';
                    if ('' === $old_fragment || '' === $new_fragment) {
                        continue;
                    }

                    $like = '%' . $wpdb->esc_like($old_fragment) . '%';
                    $matches = $wpdb->get_results(
                        $wpdb->prepare(
                            'SELECT %i AS primary_value, %i AS scanned_value FROM %i WHERE %i LIKE %s LIMIT 2000',
                            $primary,
                            $column,
                            $table_name,
                            $column,
                            $like
                        ),
                        ARRAY_A
                    );

                    foreach ((array) $matches as $match) {
                        $primary_value = isset($match['primary_value']) ? (string) $match['primary_value'] : '';
                        $stored_value  = isset($match['scanned_value']) ? (string) $match['scanned_value'] : '';
                        if ('' === $primary_value || '' === $stored_value || false === strpos($stored_value, $old_fragment)) {
                            continue;
                        }

                        if ($this->insert_media_replacement_reference_row(
                            isset($row['job_id']) ? (string) $row['job_id'] : '',
                            $item_id,
                            $table_name,
                            $primary,
                            $primary_value,
                            $column,
                            $old_fragment,
                            $new_fragment,
                            $stored_value
                        )) {
                            $found++;
                        }
                    }
                }
            }
        }

        return array('scanned' => true, 'refs' => $found, 'message' => '');
    }

    private function update_media_replacement_item_reference_scan_result($item_id, $status, $message = '')
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $item_id     = absint($item_id);
        $status      = in_array((string) $status, array('metadata_updated', 'refs_scanned', 'failed'), true) ? (string) $status : 'failed';

        if ('' === $items_table || $item_id <= 0 || !($wpdb instanceof wpdb)) {
            return false;
        }

        return false !== $wpdb->update(
            $items_table,
            array(
                'status'        => $status,
                'error_message' => '' !== (string) $message ? wp_strip_all_tags((string) $message) : null,
                'updated_at'    => current_time('mysql', true),
            ),
            array('id' => $item_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }

    private function get_media_replacement_reference_scan_summary($job_id)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $refs_table  = $this->get_media_replacement_refs_table_name();
        $job_id      = sanitize_key((string) $job_id);
        if ('' === $items_table || '' === $refs_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array();
        }

        $item_rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT status, COUNT(*) AS item_count FROM %i WHERE job_id = %s GROUP BY status',
                $items_table,
                $job_id
            ),
            ARRAY_A
        );

        $summary = array(
            'metadataUpdated'    => 0,
            'refsScanned'        => 0,
            'referenceScanFailed'=> 0,
            'remainingRefsScan'  => 0,
            'refsFound'          => 0,
            'serializedRefs'     => 0,
            'jsonRefs'           => 0,
            'referenceScanItems' => 0,
            'referenceScanTotal' => 0,
        );

        foreach ((array) $item_rows as $row) {
            $status = isset($row['status']) ? sanitize_key((string) $row['status']) : '';
            $count  = isset($row['item_count']) ? max(0, (int) $row['item_count']) : 0;
            if ('metadata_updated' === $status) {
                $summary['metadataUpdated'] += $count;
            } elseif ('refs_scanned' === $status) {
                $summary['refsScanned'] += $count;
            } elseif ('failed' === $status) {
                $summary['referenceScanFailed'] += $count;
            }
        }

        $ref_row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS refs_found, SUM(serialized) AS serialized_refs, SUM(json_detected) AS json_refs FROM %i WHERE job_id = %s',
                $refs_table,
                $job_id
            ),
            ARRAY_A
        );

        if (is_array($ref_row)) {
            $summary['refsFound']      = isset($ref_row['refs_found']) ? max(0, (int) $ref_row['refs_found']) : 0;
            $summary['serializedRefs'] = isset($ref_row['serialized_refs']) ? max(0, (int) $ref_row['serialized_refs']) : 0;
            $summary['jsonRefs']       = isset($ref_row['json_refs']) ? max(0, (int) $ref_row['json_refs']) : 0;
        }

        $summary['remainingRefsScan']  = max(0, (int) $summary['metadataUpdated']);
        $summary['referenceScanItems'] = max(0, (int) $summary['refsScanned']);
        $summary['referenceScanTotal'] = max(0, (int) $summary['metadataUpdated'] + (int) $summary['refsScanned']);

        return $summary;
    }


    private function get_media_replacement_ref_index_scan_option_name()
    {
        return 'ultracache_media_replacement_ref_index_scan_v1';
    }

    private function get_media_replacement_ref_index_default_state()
    {
        return array(
            'job_id'                => '',
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
            'created_at'            => '',
            'updated_at'            => '',
            'completed_at'          => '',
        );
    }

    private function normalize_media_replacement_ref_index_state($state)
    {
        $state = is_array($state) ? $state : array();
        $state = array_merge($this->get_media_replacement_ref_index_default_state(), $state);
        $state['job_id']               = sanitize_key((string) $state['job_id']);
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
        return $state;
    }

    private function get_media_replacement_ref_index_state()
    {
        return $this->normalize_media_replacement_ref_index_state(get_option($this->get_media_replacement_ref_index_scan_option_name(), array()));
    }

    private function update_media_replacement_ref_index_state(array $state)
    {
        $state = $this->normalize_media_replacement_ref_index_state($state);
        update_option($this->get_media_replacement_ref_index_scan_option_name(), $state, false);
        return $state;
    }

    private function reset_media_replacement_ref_index_registry($job_id)
    {
        global $wpdb;

        $job_id          = sanitize_key((string) $job_id);
        $refs_table      = $this->get_media_replacement_refs_table_name();
        $ref_index_table = $this->get_media_replacement_ref_index_table_name();
        if ('' === $job_id || '' === $refs_table || '' === $ref_index_table || !($wpdb instanceof wpdb)) {
            return false;
        }

        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE job_id = %s AND status IN (%s, %s)', $refs_table, $job_id, 'pending', 'failed'));
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE job_id = %s', $ref_index_table, $job_id));
        return true;
    }


    private function get_media_replacement_ref_index_specs_option_name()
    {
        return 'ultracache_media_replacement_ref_index_specs_v1';
    }

    private function get_media_replacement_database_specs_hash(array $specs)
    {
        return md5((string) wp_json_encode(array_values($specs)));
    }

    private function save_media_replacement_database_reference_specs($job_id, array $specs)
    {
        $job_id = sanitize_key((string) $job_id);
        if ('' === $job_id) {
            return array();
        }

        $manifest = array(
            'job_id'       => $job_id,
            'initialized'  => true,
            'specs_hash'   => $this->get_media_replacement_database_specs_hash($specs),
            'specs'        => array_values($specs),
            'created_at'   => current_time('mysql', true),
        );
        update_option($this->get_media_replacement_ref_index_specs_option_name(), $manifest, false);
        return $manifest;
    }

    private function get_saved_media_replacement_database_reference_specs($job_id)
    {
        $job_id = sanitize_key((string) $job_id);
        $saved = get_option($this->get_media_replacement_ref_index_specs_option_name(), array());
        if ('' === $job_id || !is_array($saved) || empty($saved['initialized']) || $job_id !== sanitize_key((string) ($saved['job_id'] ?? ''))) {
            return array();
        }

        $specs = isset($saved['specs']) && is_array($saved['specs']) ? array_values($saved['specs']) : array();
        $hash = isset($saved['specs_hash']) && preg_match('/^[a-f0-9]{32}$/', (string) $saved['specs_hash']) ? (string) $saved['specs_hash'] : '';
        if ('' === $hash || !hash_equals($hash, $this->get_media_replacement_database_specs_hash($specs))) {
            return array();
        }

        return array(
            'job_id'      => $job_id,
            'initialized' => true,
            'specs_hash'  => $hash,
            'specs'       => $specs,
        );
    }

    private function clear_media_replacement_database_reference_specs()
    {
        delete_option($this->get_media_replacement_ref_index_specs_option_name());
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


    private function extract_media_replacement_image_references_from_value($value)
    {
        $value = (string) $value;
        if ('' === $value || !preg_match('/\.(?:jpe?g|png)/i', $value)) {
            return array();
        }

        $scan_values = array($value);
        $unescaped_slashes = str_replace(array('\\/', '\/'), '/', $value);
        if ($unescaped_slashes !== $value) {
            $scan_values[] = $unescaped_slashes;
        }

        $references = array();
        foreach ($scan_values as $scan_value) {
            if (!preg_match_all("~[^\\s\"'<>]+?\\.(?:jpe?g|png)(?:\\?[^\\s\"'<>#]*)?(?:#[^\\s\"'<>]*)?~i", (string) $scan_value, $matches)) {
                continue;
            }

            foreach ((array) $matches[0] as $fragment) {
                $normalized = $this->normalize_media_replacement_reference_fragment_for_index($fragment);
                if (empty($normalized['raw']) || empty($normalized['match'])) {
                    continue;
                }

                $match = (string) $normalized['match'];
                if (!preg_match('/\.(?:jpe?g|png)$/i', $match)) {
                    continue;
                }

                if (strlen($normalized['raw']) > 2000 || strlen($normalized['normalized']) > 2000 || strlen($match) > 2000) {
                    continue;
                }

                $key = md5($match . '|' . $normalized['raw']);
                $references[$key] = $normalized;
            }
        }

        return array_values($references);
    }

    private function insert_media_replacement_ref_index_row($job_id, array $spec, $primary_key_value, array $reference, $stored_value)
    {
        global $wpdb;

        $ref_index_table = $this->get_media_replacement_ref_index_table_name();
        $job_id          = sanitize_key((string) $job_id);
        if ('' === $ref_index_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return false;
        }

        $table_name = isset($spec['table']) ? (string) $spec['table'] : '';
        $primary    = isset($spec['primary']) ? (string) $spec['primary'] : '';
        $column     = isset($spec['column']) ? (string) $spec['column'] : '';

        $table_name = $this->sanitize_media_replacement_db_identifier($table_name, 191);
        $primary    = $this->sanitize_media_replacement_db_identifier($primary, 64);
        $column     = $this->sanitize_media_replacement_db_identifier($column, 64);
        $primary_key_value = substr(sanitize_text_field((string) $primary_key_value), 0, 191);

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
        $ref_hash = md5($job_id . '|' . $table_name . '|' . $primary . '|' . $primary_key_value . '|' . $column . '|' . $url_path_hash . '|' . md5($raw));

        $row = array(
            'job_id'             => $job_id,
            'ref_hash'           => $ref_hash,
            'table_name'         => $table_name,
            'primary_key_column' => $primary,
            'primary_key_value'  => $primary_key_value,
            'column_name'        => $column,
            'reference_type'     => $type,
            'raw_fragment'       => $raw,
            'normalized_fragment'=> $normalized,
            'url_path_hash'      => $url_path_hash,
            'serialized'         => function_exists('is_serialized') && is_serialized((string) $stored_value) ? 1 : 0,
            'json_detected'      => $this->is_media_replacement_json_like_value($stored_value) ? 1 : 0,
            'matched_item_id'    => 0,
            'status'             => 'indexed',
            'error_message'      => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        );

        return false !== $wpdb->replace(
            $ref_index_table,
            $row,
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s')
        );
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

        if ('' === $table || '' === $column || !($wpdb instanceof wpdb) || !$this->is_media_replacement_allowed_database_table_name($table)) {
            return array();
        }

        $jpg_like = '%' . $wpdb->esc_like('.jpg') . '%';
        $jpeg_like = '%' . $wpdb->esc_like('.jpeg') . '%';
        $png_like = '%' . $wpdb->esc_like('.png') . '%';
        $is_attachment_meta_value = (string) $wpdb->postmeta === $table && 'meta_value' === $column;
        $use_keyset = 'keyset' === $pagination && '' !== $primary && '' !== $cursor_primary_value;

        if ($use_keyset && $is_attachment_meta_value) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT %i AS primary_value, %i AS scanned_value FROM %i WHERE %i > %s AND (%i LIKE %s OR %i LIKE %s OR %i LIKE %s) AND meta_key NOT IN (%s, %s, %s) ORDER BY %i ASC LIMIT %d',
                    $primary,
                    $column,
                    $table,
                    $primary,
                    '' !== $cursor_primary_value ? $cursor_primary_value : '0',
                    $column,
                    $jpg_like,
                    $column,
                    $jpeg_like,
                    $column,
                    $png_like,
                    '_wp_attached_file',
                    '_wp_attachment_metadata',
                    '_wp_attachment_backup_sizes',
                    $primary,
                    $limit
                ),
                ARRAY_A
            );
        } elseif ($use_keyset) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT %i AS primary_value, %i AS scanned_value FROM %i WHERE %i > %s AND (%i LIKE %s OR %i LIKE %s OR %i LIKE %s) ORDER BY %i ASC LIMIT %d',
                    $primary,
                    $column,
                    $table,
                    $primary,
                    '' !== $cursor_primary_value ? $cursor_primary_value : '0',
                    $column,
                    $jpg_like,
                    $column,
                    $jpeg_like,
                    $column,
                    $png_like,
                    $primary,
                    $limit
                ),
                ARRAY_A
            );
        } elseif ('' !== $primary && $is_attachment_meta_value) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT %i AS primary_value, %i AS scanned_value FROM %i WHERE (%i LIKE %s OR %i LIKE %s OR %i LIKE %s) AND meta_key NOT IN (%s, %s, %s) ORDER BY %i ASC LIMIT %d OFFSET %d',
                    $primary,
                    $column,
                    $table,
                    $column,
                    $jpg_like,
                    $column,
                    $jpeg_like,
                    $column,
                    $png_like,
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
                    'SELECT %i AS primary_value, %i AS scanned_value FROM %i WHERE (%i LIKE %s OR %i LIKE %s OR %i LIKE %s) ORDER BY %i ASC LIMIT %d OFFSET %d',
                    $primary,
                    $column,
                    $table,
                    $column,
                    $jpg_like,
                    $column,
                    $jpeg_like,
                    $column,
                    $png_like,
                    $primary,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT %i AS scanned_value FROM %i WHERE (%i LIKE %s OR %i LIKE %s OR %i LIKE %s) LIMIT %d OFFSET %d',
                    $column,
                    $table,
                    $column,
                    $jpg_like,
                    $column,
                    $jpeg_like,
                    $column,
                    $png_like,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        }

        return is_array($rows) ? $rows : array();
    }

    private function get_media_replacement_ref_index_summary($job_id)
    {
        global $wpdb;

        $ref_index_table = $this->get_media_replacement_ref_index_table_name();
        $job_id = sanitize_key((string) $job_id);
        if ('' === $ref_index_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array('total' => 0, 'serialized' => 0, 'json' => 0, 'matched' => 0, 'tables' => 0);
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS total_refs, SUM(serialized) AS serialized_refs, SUM(json_detected) AS json_refs, SUM(CASE WHEN matched_item_id > 0 THEN 1 ELSE 0 END) AS matched_refs FROM %i WHERE job_id = %s',
                $ref_index_table,
                $job_id
            ),
            ARRAY_A
        );

        $table_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT table_name) FROM %i WHERE job_id = %s',
                $ref_index_table,
                $job_id
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
        $job_id = $this->get_media_replacement_preview_job_id(isset($args['job_id']) ? (string) $args['job_id'] : '');
        if ('' === $job_id) {
            return array(
                'success' => false,
                'message' => __('Complete replacement metadata planning before scanning database references.', 'ultracache'),
            );
        }
        if (!$this->media_replacement_job_has_registry_rows($job_id)) {
            return $this->build_media_replacement_empty_registry_response($job_id, __('No Media Library replacement registry rows are available for database reference scanning.', 'ultracache'));
        }

        $limit = isset($args['limit']) ? absint($args['limit']) : 250;
        $limit = max(25, min(1000, $limit));
        $time_budget = isset($args['time_budget']) && (float) $args['time_budget'] > 0 ? (float) $args['time_budget'] : 15.0;
        $time_budget = max(1.0, min(30.0, $time_budget));
        $deadline = microtime(true) + $time_budget;

        $state = $this->get_media_replacement_ref_index_state();
        $start_new = '' === $state['job_id'] || $state['job_id'] !== $job_id || !empty($args['reset']) || !empty($args['start']);
        if ($start_new) {
            $specs = $this->get_media_replacement_database_reference_specs();
            $manifest = $this->save_media_replacement_database_reference_specs($job_id, $specs);
            $specs_hash = isset($manifest['specs_hash']) ? (string) $manifest['specs_hash'] : '';
            $this->reset_media_replacement_ref_index_registry($job_id);
            $state = $this->update_media_replacement_ref_index_state(array(
                'job_id'               => $job_id,
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
            $manifest = $this->get_saved_media_replacement_database_reference_specs($job_id);
            if (empty($manifest['initialized'])) {
                return array(
                    'success' => false,
                    'blocked' => true,
                    'status'  => 'database_scan_manifest_missing',
                    'message' => __('The persisted database scan manifest is missing or invalid. Restart Prepare to rebuild it.', 'ultracache'),
                    'jobId'   => $job_id,
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
                'jobId'   => $job_id,
                'hasMore' => false,
            );
        }
        if ('completed' === $state['status']) {
            $summary = $this->get_media_replacement_ref_index_summary($job_id);
            return array(
                'success' => true,
                /* translators: %d: indexed database reference count. */
                'message' => sprintf(__('Database reference index is already complete with %d indexed JPG/PNG references.', 'ultracache'), (int) $summary['total']),
                'jobId' => $job_id,
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
                'jobId' => $job_id,
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
        $iterations = 0;
        $last_pagination = 'offset';

        while ($remaining > 0 && $state['cursor_spec_index'] < $total_specs && $iterations < 25 && ($batch_rows === 0 || microtime(true) < $deadline)) {
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
            $query_limit = $remaining;
            $rows = $this->get_media_replacement_database_reference_candidate_rows(
                $spec,
                $query_limit,
                $state['cursor_offset'],
                $state['cursor_primary_value']
            );
            $fetched_count = count($rows);
            $processed_in_spec = 0;
            $last_primary_value = '';

            foreach ($rows as $db_row) {
                if ($batch_rows > 0 && microtime(true) >= $deadline) {
                    break;
                }
                $stored_value = isset($db_row['scanned_value']) ? (string) $db_row['scanned_value'] : '';
                $primary_value = isset($db_row['primary_value']) ? (string) $db_row['primary_value'] : '';
                if ('' === $primary_value) {
                    $primary_value = 'offset-' . (string) ($state['cursor_offset'] + $processed_in_spec);
                } elseif ('keyset' === $pagination) {
                    $last_primary_value = $primary_value;
                }

                if ('' !== $stored_value) {
                    $references = $this->extract_media_replacement_image_references_from_value($stored_value);
                    $row_serialized = function_exists('is_serialized') && is_serialized($stored_value);
                    $row_json = $this->is_media_replacement_json_like_value($stored_value);
                    foreach ($references as $reference) {
                        if ($this->insert_media_replacement_ref_index_row($job_id, $spec, $primary_value, $reference, $stored_value)) {
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
                $processed_in_spec++;
                $batch_rows++;
                $state['scanned_rows']++;
                $remaining--;
            }

            if ('keyset' === $pagination && '' !== $last_primary_value) {
                $state['cursor_primary_value'] = $last_primary_value;
            } elseif ('offset' === $pagination) {
                $state['cursor_offset'] += $processed_in_spec;
            }

            if ($processed_in_spec < $fetched_count) {
                break;
            }

            if ($fetched_count < $query_limit) {
                $state['cursor_spec_index']++;
                $state['cursor_offset'] = 0;
                $state['cursor_primary_value'] = '';
                $state['scanned_columns']++;
                $batch_columns_completed++;
            }
            $iterations++;
        }

        $state['indexed_refs'] += $batch_refs;
        $state['serialized_refs'] += $batch_serialized;
        $state['json_refs'] += $batch_json;
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
                    'jobId' => $job_id,
                    'hasMore' => false,
                );
            }
            $state['status'] = 'completed';
            $state['completed_at'] = current_time('mysql', true);
            $state['cursor_spec_index'] = $total_specs;
            $state['cursor_offset'] = 0;
            $state['cursor_primary_value'] = '';
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
            $summary = $this->get_media_replacement_ref_index_summary($job_id);
        }

        $progress = $total_specs > 0 ? min(100, round((min($state['cursor_spec_index'], $total_specs) / $total_specs) * 100, 1)) : 100;
        return array(
            'success'             => true,
            'message'             => $has_more
                /* translators: %1$d: completed column count; %2$d: total column count; %3$d: indexed reference count. */
                ? sprintf(__('Database reference index: %1$d of %2$d text-like columns complete, %3$d references indexed.', 'ultracache'), (int) min($state['cursor_spec_index'], $total_specs), $total_specs, (int) $summary['total'])
                /* translators: %d: indexed database reference count. */
                : sprintf(__('Database reference index completed with %d JPG/PNG references.', 'ultracache'), (int) $summary['total']),
            'jobId'               => $job_id,
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
            'progressPercent'     => $progress,
            'databaseReferenceIndexReady' => !$has_more,
            'databaseReferencesScanned' => !$has_more,
            'databaseReplaced'    => false,
            'nextStep'            => $has_more
                ? __('Continue the resumable database reference index. Site content has not been changed.', 'ultracache')
                : __('Continue Prepare by matching indexed references to replacement registry rows.', 'ultracache'),
        );
    }

    private function get_media_replacement_ref_match_summary($job_id)
    {
        global $wpdb;

        $ref_index_table = $this->get_media_replacement_ref_index_table_name();
        $refs_table      = $this->get_media_replacement_refs_table_name();
        $job_id          = sanitize_key((string) $job_id);
        if ('' === $ref_index_table || '' === $refs_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array();
        }

        $index_row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS total_indexed, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS indexed_pending, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS matched_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS unmatched_refs, SUM(CASE WHEN status = %s AND matched_item_id = 0 THEN 1 ELSE 0 END) AS unmatched_ignored, SUM(CASE WHEN status = %s AND matched_item_id > 0 THEN 1 ELSE 0 END) AS unmatched_relevant, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS failed_refs, SUM(serialized) AS serialized_refs, SUM(json_detected) AS json_refs FROM %i WHERE job_id = %s',
                'indexed',
                'matched',
                'unmatched',
                'unmatched',
                'unmatched',
                'failed',
                $ref_index_table,
                $job_id
            ),
            ARRAY_A
        );

        $refs_row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS planned_refs, SUM(serialized) AS planned_serialized, SUM(json_detected) AS planned_json FROM %i WHERE job_id = %s',
                $refs_table,
                $job_id
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

    private function get_media_replacement_ref_index_match_rows($job_id, $limit = 250)
    {
        global $wpdb;

        $items_table     = $this->get_media_replacement_items_table_name();
        $ref_index_table = $this->get_media_replacement_ref_index_table_name();
        $job_id          = sanitize_key((string) $job_id);
        $limit           = max(1, min(1000, absint($limit)));
        if ('' === $items_table || '' === $ref_index_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT x.id AS index_id, x.ref_hash AS index_ref_hash, x.table_name, x.primary_key_column, x.primary_key_value, x.column_name, x.reference_type, x.raw_fragment, x.normalized_fragment, x.url_path_hash, x.serialized, x.json_detected, i.id AS item_id, i.attachment_id, i.old_relative_path, i.old_url, i.new_relative_path, i.new_url FROM %i x LEFT JOIN %i i ON i.job_id = x.job_id AND i.old_path_hash = x.url_path_hash AND i.status IN (%s, %s, %s, %s) WHERE x.job_id = %s AND x.status = %s ORDER BY x.id ASC LIMIT %d',
                $ref_index_table,
                $items_table,
                'metadata_ready',
                'metadata_updated',
                'refs_scanned',
                'copied',
                $job_id,
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

        $job_id            = isset($row['job_id']) ? sanitize_key((string) $row['job_id']) : '';
        $item_id           = isset($row['item_id']) ? absint($row['item_id']) : 0;
        $table_name        = isset($row['table_name']) ? $this->sanitize_media_replacement_db_identifier((string) $row['table_name'], 191) : '';
        $primary_column    = isset($row['primary_key_column']) ? $this->sanitize_media_replacement_db_identifier((string) $row['primary_key_column'], 64) : '';
        $primary_value     = isset($row['primary_key_value']) ? substr(sanitize_text_field((string) $row['primary_key_value']), 0, 191) : '';
        $column_name       = isset($row['column_name']) ? $this->sanitize_media_replacement_db_identifier((string) $row['column_name'], 64) : '';
        $old_fragment      = isset($row['raw_fragment']) ? (string) $row['raw_fragment'] : '';
        $new_fragment      = (string) $new_fragment;

        if ('' === $job_id || $item_id <= 0 || '' === $table_name || '' === $primary_column || '' === $primary_value || '' === $column_name || '' === $old_fragment || '' === $new_fragment || $old_fragment === $new_fragment || !$this->is_media_replacement_allowed_database_table_name($table_name)) {
            return false;
        }

        $old_value_hash = md5($old_fragment);
        $new_value_hash = md5($new_fragment);
        $now            = current_time('mysql', true);
        $ref_hash       = md5($job_id . '|' . $table_name . '|' . $primary_column . '|' . $primary_value . '|' . $column_name . '|' . $old_value_hash . '|' . $new_value_hash);

        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE job_id = %s AND table_name = %s AND primary_key_column = %s AND primary_key_value = %s AND column_name = %s AND old_value_hash = %s AND new_value_hash = %s LIMIT 1',
                $refs_table,
                $job_id,
                $table_name,
                $primary_column,
                $primary_value,
                $column_name,
                $old_value_hash,
                $new_value_hash
            )
        );

        if (!empty($existing_id)) {
            return false !== $wpdb->update(
                $refs_table,
                array(
                    'serialized'    => !empty($row['serialized']) ? 1 : 0,
                    'json_detected' => !empty($row['json_detected']) ? 1 : 0,
                    'updated_at'    => $now,
                ),
                array('id' => absint($existing_id)),
                array('%d', '%d', '%s'),
                array('%d')
            );
        }

        $row_data = array(
            'job_id'             => $job_id,
            'item_id'            => $item_id,
            'ref_hash'           => $ref_hash,
            'table_name'         => $table_name,
            'primary_key_column' => $primary_column,
            'primary_key_value'  => $primary_value,
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
            array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s')
        );
    }

    private function deduplicate_media_replacement_database_reference_rows($job_id)
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        $job_id     = sanitize_key((string) $job_id);
        if ('' === $refs_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return 0;
        }

        $groups = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT MIN(id) AS keep_id, COUNT(*) AS duplicate_count, table_name, primary_key_column, primary_key_value, column_name, old_value_hash, new_value_hash FROM %i WHERE job_id = %s GROUP BY table_name, primary_key_column, primary_key_value, column_name, old_value_hash, new_value_hash HAVING COUNT(*) > 1',
                $refs_table,
                $job_id
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
            $column_name    = isset($group['column_name']) ? $this->sanitize_media_replacement_db_identifier((string) $group['column_name'], 64) : '';
            $old_hash       = isset($group['old_value_hash']) ? preg_replace('/[^a-f0-9]/', '', strtolower((string) $group['old_value_hash'])) : '';
            $new_hash       = isset($group['new_value_hash']) ? preg_replace('/[^a-f0-9]/', '', strtolower((string) $group['new_value_hash'])) : '';

            if ('' === $table_name || '' === $primary_column || '' === $primary_value || '' === $column_name || 32 !== strlen($old_hash) || 32 !== strlen($new_hash)) {
                continue;
            }

            $result = $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE job_id = %s AND id <> %d AND table_name = %s AND primary_key_column = %s AND primary_key_value = %s AND column_name = %s AND old_value_hash = %s AND new_value_hash = %s',
                    $refs_table,
                    $job_id,
                    $keep_id,
                    $table_name,
                    $primary_column,
                    $primary_value,
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
        $job_id = $this->get_media_replacement_preview_job_id(isset($args['job_id']) ? (string) $args['job_id'] : '');
        if ('' === $job_id) {
            return array(
                'success' => false,
                'message' => __('Complete the database reference index before matching references.', 'ultracache'),
            );
        }
        if (!$this->media_replacement_job_has_registry_rows($job_id)) {
            return $this->build_media_replacement_empty_registry_response($job_id, __('No Media Library replacement registry rows are available for database reference matching.', 'ultracache'));
        }

        $limit = isset($args['limit']) ? absint($args['limit']) : 250;
        $limit = max(25, min(1000, $limit));
        $time_budget = isset($args['time_budget']) && (float) $args['time_budget'] > 0 ? (float) $args['time_budget'] : 15.0;
        $time_budget = max(1.0, min(30.0, $time_budget));
        $deadline = microtime(true) + $time_budget;
        $rows = $this->get_media_replacement_ref_index_match_rows($job_id, $limit);

        $batch_processed = 0;
        $batch_matched = 0;
        $batch_unmatched = 0;
        $batch_failed = 0;
        foreach ($rows as $row) {
            if ($batch_processed > 0 && microtime(true) >= $deadline) {
                break;
            }
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

            $row['job_id'] = $job_id;
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

        $duplicates_removed = $this->deduplicate_media_replacement_database_reference_rows($job_id);
        $summary = $this->get_media_replacement_ref_match_summary($job_id);
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
            'jobId'                => $job_id,
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


    private function get_media_replacement_database_apply_summary($job_id)
    {
        return $this->get_media_replacement_database_preview_summary($job_id);
    }

    private function get_media_replacement_database_apply_rows($job_id, $limit = 50)
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        $job_id     = sanitize_key((string) $job_id);
        $limit      = max(1, min(250, absint($limit)));

        if ('' === $refs_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, item_id, table_name, primary_key_column, primary_key_value, column_name, old_fragment, new_fragment, serialized, json_detected, status FROM %i WHERE job_id = %s AND status = %s ORDER BY id ASC LIMIT %d',
                $refs_table,
                $job_id,
                'pending',
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private function update_media_replacement_database_apply_ref_result($ref_id, $status, $message = '')
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        $ref_id     = absint($ref_id);
        $status     = in_array((string) $status, array('replaced', 'failed'), true) ? (string) $status : 'failed';

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

            $decoded = json_decode($stored_value, true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The planned JSON-like database value could not be decoded before replacement.', 'ultracache'));
            }

            $count   = 0;
            $decoded = $this->replace_media_replacement_fragment_recursive($decoded, $old_fragment, $new_fragment, $count);
            if ($count <= 0) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The old image reference was not found inside the decoded JSON-like value.', 'ultracache'));
            }

            $encoded = wp_json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($encoded) || '' === $encoded) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The JSON-like database value could not be re-encoded after replacement.', 'ultracache'));
            }

            json_decode($encoded, true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The re-encoded JSON-like database value could not be decoded after replacement.', 'ultracache'));
            }

            if (false !== strpos($encoded, $old_fragment)) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The old image reference is still present in the JSON-like replacement value.', 'ultracache'));
            }

            if (false === strpos($encoded, $new_fragment)) {
                return array('changed' => false, 'value' => $stored_value, 'count' => 0, 'message' => __('The new image reference is missing from the JSON-like replacement value.', 'ultracache'));
            }

            return array('changed' => true, 'value' => $encoded, 'count' => $count, 'message' => '');
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

    private function update_media_replacement_database_row_value(array $ref, $new_value)
    {
        global $wpdb;

        $table_name     = isset($ref['table_name']) ? $this->sanitize_media_replacement_db_identifier((string) $ref['table_name'], 191) : '';
        $primary_column = isset($ref['primary_key_column']) ? $this->sanitize_media_replacement_db_identifier((string) $ref['primary_key_column'], 64) : '';
        $primary_value  = isset($ref['primary_key_value']) ? (string) $ref['primary_key_value'] : '';
        $column_name    = isset($ref['column_name']) ? $this->sanitize_media_replacement_db_identifier((string) $ref['column_name'], 64) : '';

        if ('' === $table_name || '' === $primary_column || '' === $primary_value || '' === $column_name || !($wpdb instanceof wpdb)) {
            return false;
        }

        if (!$this->is_media_replacement_allowed_database_table_name($table_name)) {
            return false;
        }

        return false !== $wpdb->update(
            $table_name,
            array($column_name => (string) $new_value),
            array($primary_column => $primary_value),
            array('%s'),
            array('%s')
        );
    }

    private function apply_media_replacement_database_ref(array $ref)
    {
        $ref_id       = isset($ref['id']) ? absint($ref['id']) : 0;
        $old_fragment = isset($ref['old_fragment']) ? (string) $ref['old_fragment'] : '';
        $new_fragment = isset($ref['new_fragment']) ? (string) $ref['new_fragment'] : '';

        if ($ref_id <= 0 || '' === $old_fragment || '' === $new_fragment || $old_fragment === $new_fragment) {
            return array('applied' => false, 'message' => __('Invalid planned database replacement row.', 'ultracache'));
        }

        $stored_value = $this->get_media_replacement_database_row_value($ref);
        if (null === $stored_value) {
            return array('applied' => false, 'message' => __('The database row for this replacement could not be read.', 'ultracache'));
        }

        $stored_value = (string) $stored_value;
        if (false === strpos($stored_value, $old_fragment)) {
            if (false !== strpos($stored_value, $new_fragment)) {
                $already_applied = $this->validate_media_replacement_database_built_value(
                    $stored_value,
                    $old_fragment,
                    $new_fragment,
                    !empty($ref['serialized']),
                    !empty($ref['json_detected'])
                );
                if (!empty($already_applied['valid'])) {
                    return array('applied' => true, 'already_applied' => true, 'message' => '', 'count' => 0);
                }
                return array('applied' => false, 'message' => isset($already_applied['message']) ? (string) $already_applied['message'] : __('The already-replaced database value failed validation.', 'ultracache'));
            }
            return array('applied' => false, 'message' => __('Neither the old nor the new image reference is present in the current database value.', 'ultracache'));
        }

        $replacement  = $this->build_media_replacement_database_value(
            $stored_value,
            $old_fragment,
            $new_fragment,
            !empty($ref['serialized']),
            !empty($ref['json_detected'])
        );

        if (empty($replacement['changed'])) {
            return array('applied' => false, 'message' => isset($replacement['message']) ? (string) $replacement['message'] : __('No database value change was produced.', 'ultracache'));
        }

        $prewrite_validation = $this->validate_media_replacement_database_built_value(
            isset($replacement['value']) ? (string) $replacement['value'] : '',
            $old_fragment,
            $new_fragment,
            !empty($ref['serialized']),
            !empty($ref['json_detected'])
        );
        if (empty($prewrite_validation['valid'])) {
            return array('applied' => false, 'message' => isset($prewrite_validation['message']) ? (string) $prewrite_validation['message'] : __('The built database replacement value failed pre-write validation.', 'ultracache'));
        }

        if (!$this->update_media_replacement_database_row_value($ref, (string) $replacement['value'])) {
            return array('applied' => false, 'message' => __('The database row could not be updated.', 'ultracache'));
        }

        $restore_and_fail = function ($message) use ($ref, $stored_value) {
            $restored = $this->update_media_replacement_database_row_value($ref, $stored_value);
            return array(
                'applied' => false,
                'message' => $restored
                    ? (string) $message
                    : sprintf(
                        /* translators: %s: failure reason. */
                        __('%s The original database value could not be restored automatically.', 'ultracache'),
                        (string) $message
                    ),
            );
        };

        $verified_value = $this->get_media_replacement_database_row_value($ref);
        if (null === $verified_value || false === strpos((string) $verified_value, $new_fragment)) {
            return $restore_and_fail(__('The database row update could not be verified.', 'ultracache'));
        }

        $verified_value = (string) $verified_value;
        if (false !== strpos($verified_value, $old_fragment)) {
            return $restore_and_fail(__('The old image reference is still present after the database row update.', 'ultracache'));
        }

        if (!empty($ref['serialized'])) {
            $serialized_check = $this->verify_media_replacement_serialized_database_value($verified_value);
            if (empty($serialized_check['verified'])) {
                return $restore_and_fail(__('The serialized database value could not be decoded immediately after replacement.', 'ultracache'));
            }

            if (!empty($serialized_check['repaired'])) {
                return $restore_and_fail(__('The serialized database value required repair immediately after replacement and was not marked as applied.', 'ultracache'));
            }
        }

        if (!empty($ref['json_detected'])) {
            json_decode($verified_value, true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                return $restore_and_fail(__('The JSON-like database value could not be decoded immediately after replacement.', 'ultracache'));
            }
        }

        return array('applied' => true, 'message' => '', 'count' => isset($replacement['count']) ? absint($replacement['count']) : 1);
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
        $job_id = $this->get_media_replacement_preview_job_id(isset($args['job_id']) ? (string) $args['job_id'] : '');
        if ('' === $job_id) {
            return array(
                'success' => false,
                'message' => __('Run Preview DB Replacements before applying database replacements.', 'ultracache'),
            );
        }
        if (!$this->media_replacement_job_has_registry_rows($job_id)) {
            return $this->build_media_replacement_empty_registry_response($job_id, __('No Media Library replacement registry rows are available for database replacement apply. Restore the database backup or roll back attachment metadata, then run Restart Replacement Plan again.', 'ultracache'));
        }

        $confirmation = $this->validate_media_replacement_confirmation_token($job_id, 'database_apply', $args);
        if (empty($confirmation['success'])) {
            return $confirmation;
        }

        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        $limit = max(10, min(250, $limit));
        $time_budget = isset($args['time_budget']) && (float) $args['time_budget'] > 0 ? (float) $args['time_budget'] : 15.0;
        $time_budget = max(1.0, min(30.0, $time_budget));
        $deadline = microtime(true) + $time_budget;
        $duplicates_removed = $this->deduplicate_media_replacement_database_reference_rows($job_id);
        $rows = $this->get_media_replacement_database_apply_rows($job_id, $limit);

        $batch_processed = 0;
        $batch_replaced  = 0;
        $batch_failed    = 0;
        $registry_sync_failed = 0;

        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $batch_processed++;
            $ref_id = isset($row['id']) ? absint($row['id']) : 0;
            $result = $this->apply_media_replacement_database_ref($row);
            if (!empty($result['applied'])) {
                if ($this->update_media_replacement_database_apply_ref_result($ref_id, 'replaced', '')) {
                    $batch_replaced++;
                } else {
                    $registry_sync_failed++;
                }
            } else {
                if ($this->update_media_replacement_database_apply_ref_result($ref_id, 'failed', isset($result['message']) ? (string) $result['message'] : __('Database replacement failed.', 'ultracache'))) {
                    $batch_failed++;
                } else {
                    $registry_sync_failed++;
                }
            }
        }

        $summary = $this->get_media_replacement_database_apply_summary($job_id);
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
                'jobId'              => $job_id,
                'status'             => 'database_reconcile_required',
                'hasMore'            => true,
                'batchProcessedRefs' => $batch_processed,
                'batchReplacedRefs'  => $batch_replaced,
                'batchFailedRefs'    => $batch_failed,
                'registrySyncFailed' => $registry_sync_failed,
            );
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
            'jobId'                => $job_id,
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


    private function get_media_replacement_database_verify_rows($job_id, $limit = 50)
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        $job_id     = sanitize_key((string) $job_id);
        $limit      = max(1, min(250, absint($limit)));

        if ('' === $refs_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, item_id, table_name, primary_key_column, primary_key_value, column_name, old_fragment, new_fragment, serialized, json_detected, status FROM %i WHERE job_id = %s AND status IN (%s, %s) ORDER BY id ASC LIMIT %d',
                $refs_table,
                $job_id,
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

    private function get_media_replacement_database_verify_summary($job_id)
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        $job_id     = sanitize_key((string) $job_id);
        if ('' === $refs_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array();
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS total_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS pending_verify, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS verified_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS verify_failed_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS pending_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS failed_refs, SUM(serialized) AS serialized_refs, SUM(json_detected) AS json_refs FROM %i WHERE job_id = %s',
                'replaced',
                'verified',
                'verify_failed',
                'pending',
                'failed',
                $refs_table,
                $job_id
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

        $stored_value = $this->get_media_replacement_database_row_value($ref);
        if (null === $stored_value) {
            return array('verified' => false, 'message' => __('The database row for this replacement could not be read.', 'ultracache'));
        }

        $stored_value = (string) $stored_value;
        if (false !== strpos($stored_value, $old_fragment)) {
            return array('verified' => false, 'message' => __('The old image reference is still present in the database row.', 'ultracache'));
        }

        if (false === strpos($stored_value, $new_fragment)) {
            return array('verified' => false, 'message' => __('The new image reference is missing from the database row.', 'ultracache'));
        }

        if (!empty($ref['serialized'])) {
            $serialized_check = $this->verify_media_replacement_serialized_database_value($stored_value);
            if (empty($serialized_check['verified'])) {
                return array('verified' => false, 'message' => __('The serialized database value could not be decoded after replacement.', 'ultracache'));
            }

            if (!empty($serialized_check['repaired']) && isset($serialized_check['value']) && is_string($serialized_check['value'])) {
                $repaired_value = (string) $serialized_check['value'];
                if ($repaired_value !== $stored_value) {
                    if (!$this->update_media_replacement_database_row_value($ref, $repaired_value)) {
                        return array('verified' => false, 'message' => __('The serialized database value was repaired in memory but could not be saved.', 'ultracache'));
                    }

                    $stored_value = $repaired_value;
                    if (false !== strpos($stored_value, $old_fragment)) {
                        return array('verified' => false, 'message' => __('The old image reference is still present in the repaired serialized database row.', 'ultracache'));
                    }
                    if (false === strpos($stored_value, $new_fragment)) {
                        return array('verified' => false, 'message' => __('The new image reference is missing from the repaired serialized database row.', 'ultracache'));
                    }
                }
            }
        }

        if (!empty($ref['json_detected']) && $this->is_media_replacement_json_like_value($stored_value)) {
            json_decode($stored_value, true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                return array('verified' => false, 'message' => __('The JSON database value could not be decoded after replacement.', 'ultracache'));
            }
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
        $job_id = $this->get_media_replacement_preview_job_id(isset($args['job_id']) ? (string) $args['job_id'] : '');
        if ('' === $job_id) {
            return array(
                'success' => false,
                'message' => __('Run Apply DB Replacements before verifying database replacements.', 'ultracache'),
            );
        }
        if (!$this->media_replacement_job_has_registry_rows($job_id)) {
            return $this->build_media_replacement_empty_registry_response($job_id, __('No Media Library replacement registry rows are available for database replacement verification. Restore the database backup or roll back attachment metadata, then run Restart Replacement Plan again.', 'ultracache'));
        }

        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        $limit = max(10, min(250, $limit));
        $rows  = $this->get_media_replacement_database_verify_rows($job_id, $limit);

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

        $summary = $this->get_media_replacement_database_verify_summary($job_id);
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
            'jobId'                => $job_id,
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
                : __('Next step: run Cleanup Preview, then Cleanup Apply only after reviewing the candidates.', 'ultracache'),
        );
    }


    private function get_media_replacement_database_rollback_rows($job_id, $limit = 50)
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        $job_id     = sanitize_key((string) $job_id);
        $limit      = max(1, min(250, absint($limit)));

        if ('' === $refs_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, item_id, table_name, primary_key_column, primary_key_value, column_name, old_fragment, new_fragment, serialized, json_detected, status FROM %i WHERE job_id = %s AND status IN (%s, %s, %s) ORDER BY id DESC LIMIT %d',
                $refs_table,
                $job_id,
                'verified',
                'replaced',
                'verify_failed',
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
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

    private function get_media_replacement_database_rollback_summary($job_id)
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        $job_id     = sanitize_key((string) $job_id);
        if ('' === $refs_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array();
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS total_refs, SUM(CASE WHEN status IN (%s, %s, %s) THEN 1 ELSE 0 END) AS pending_rollback, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS restored_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS rollback_failed_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS pending_refs, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS failed_refs, SUM(serialized) AS serialized_refs, SUM(json_detected) AS json_refs FROM %i WHERE job_id = %s',
                'verified',
                'replaced',
                'verify_failed',
                'restored',
                'rollback_failed',
                'pending',
                'failed',
                $refs_table,
                $job_id
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

    private function rollback_media_replacement_database_ref(array $ref)
    {
        $ref_id       = isset($ref['id']) ? absint($ref['id']) : 0;
        $old_fragment = isset($ref['old_fragment']) ? (string) $ref['old_fragment'] : '';
        $new_fragment = isset($ref['new_fragment']) ? (string) $ref['new_fragment'] : '';

        if ($ref_id <= 0 || '' === $old_fragment || '' === $new_fragment || $old_fragment === $new_fragment) {
            return array('restored' => false, 'message' => __('Invalid planned database rollback row.', 'ultracache'));
        }

        $stored_value = $this->get_media_replacement_database_row_value($ref);
        if (null === $stored_value) {
            return array('restored' => false, 'message' => __('The database row for this rollback could not be read.', 'ultracache'));
        }

        $stored_value = (string) $stored_value;
        if (false !== strpos($stored_value, $old_fragment) && false === strpos($stored_value, $new_fragment)) {
            return array('restored' => true, 'message' => '', 'count' => 0, 'alreadyRestored' => true);
        }

        if (false === strpos($stored_value, $new_fragment)) {
            return array('restored' => false, 'message' => __('The replacement image reference is no longer present in this database row, so rollback could not identify a value to restore.', 'ultracache'));
        }

        $replacement = $this->build_media_replacement_database_value(
            $stored_value,
            $new_fragment,
            $old_fragment,
            !empty($ref['serialized']),
            !empty($ref['json_detected'])
        );

        if (empty($replacement['changed'])) {
            return array('restored' => false, 'message' => isset($replacement['message']) ? (string) $replacement['message'] : __('No database rollback value was produced.', 'ultracache'));
        }

        $prewrite_validation = $this->validate_media_replacement_database_built_value(
            isset($replacement['value']) ? (string) $replacement['value'] : '',
            $new_fragment,
            $old_fragment,
            !empty($ref['serialized']),
            !empty($ref['json_detected'])
        );
        if (empty($prewrite_validation['valid'])) {
            return array('restored' => false, 'message' => isset($prewrite_validation['message']) ? (string) $prewrite_validation['message'] : __('The built database rollback value failed pre-write validation.', 'ultracache'));
        }

        if (!$this->update_media_replacement_database_row_value($ref, (string) $replacement['value'])) {
            return array('restored' => false, 'message' => __('The database row could not be restored.', 'ultracache'));
        }

        $restore_and_fail = function ($message) use ($ref, $stored_value) {
            $restored = $this->update_media_replacement_database_row_value($ref, $stored_value);
            return array(
                'restored' => false,
                'message'  => $restored
                    ? (string) $message
                    : sprintf(
                        /* translators: %s: failure reason. */
                        __('%s The replaced database value could not be restored automatically after rollback validation failed.', 'ultracache'),
                        (string) $message
                    ),
            );
        };

        $verified_value = $this->get_media_replacement_database_row_value($ref);
        if (null === $verified_value || false === strpos((string) $verified_value, $old_fragment)) {
            return $restore_and_fail(__('The database rollback update could not be verified.', 'ultracache'));
        }

        $verified_value = (string) $verified_value;
        if (false !== strpos($verified_value, $new_fragment)) {
            return $restore_and_fail(__('The replacement image reference is still present after database rollback.', 'ultracache'));
        }

        if (!empty($ref['serialized'])) {
            $serialized_check = $this->verify_media_replacement_serialized_database_value($verified_value);
            if (empty($serialized_check['verified'])) {
                return $restore_and_fail(__('The serialized database value could not be decoded immediately after rollback.', 'ultracache'));
            }
            if (!empty($serialized_check['repaired'])) {
                return $restore_and_fail(__('The serialized database value required repair immediately after rollback and was not marked as restored.', 'ultracache'));
            }
        }

        if (!empty($ref['json_detected'])) {
            json_decode($verified_value, true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                return $restore_and_fail(__('The JSON-like database value could not be decoded immediately after rollback.', 'ultracache'));
            }
        }

        return array('restored' => true, 'message' => '', 'count' => isset($replacement['count']) ? absint($replacement['count']) : 1);
    }

    public function rollback_media_library_replacement_database_replacements($args = array())
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
                'message' => __('Run Verify DB Replacements before rolling back database replacements.', 'ultracache'),
            );
        }
        if (!$this->media_replacement_job_has_registry_rows($job_id)) {
            return $this->build_media_replacement_empty_registry_response($job_id, __('No Media Library replacement registry rows are available for database rollback. Restore the database backup, then run Restart Replacement Plan again.', 'ultracache'));
        }

        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        $limit = max(10, min(250, $limit));
        $rows  = $this->get_media_replacement_database_rollback_rows($job_id, $limit);

        $batch_processed = 0;
        $batch_restored  = 0;
        $batch_failed    = 0;

        foreach ($rows as $row) {
            $batch_processed++;
            $ref_id = isset($row['id']) ? absint($row['id']) : 0;
            $result = $this->rollback_media_replacement_database_ref($row);
            if (!empty($result['restored'])) {
                $this->update_media_replacement_database_rollback_ref_result($ref_id, 'restored', '');
                $batch_restored++;
            } else {
                $this->update_media_replacement_database_rollback_ref_result($ref_id, 'rollback_failed', isset($result['message']) ? (string) $result['message'] : __('Database rollback failed.', 'ultracache'));
                $batch_failed++;
            }
        }

        $summary = $this->get_media_replacement_database_rollback_summary($job_id);
        $total = isset($summary['totalRefs']) ? max(0, (int) $summary['totalRefs']) : 0;
        $pending_rollback = isset($summary['pendingRollback']) ? max(0, (int) $summary['pendingRollback']) : 0;
        $restored = isset($summary['restoredRefs']) ? max(0, (int) $summary['restoredRefs']) : 0;
        $rollback_failed = isset($summary['rollbackFailedRefs']) ? max(0, (int) $summary['rollbackFailedRefs']) : 0;
        $processed = max(0, $total - $pending_rollback);
        $has_more = $pending_rollback > 0;
        $progress = $total > 0 ? min(100, round(($processed / $total) * 100, 1)) : 100;

        return array(
            'success'              => true,
            'message'              => $has_more
                ? sprintf(
                    /* translators: 1: restored references, 2: total references. */
                    __('Media Library replacement database rollback is in progress: %1$d of %2$d references restored.', 'ultracache'),
                    (int) $restored,
                    (int) $total
                )
                : sprintf(
                    /* translators: 1: restored references, 2: failed references. */
                    __('Media Library replacement restored %1$d database references. Failed: %2$d.', 'ultracache'),
                    (int) $restored,
                    (int) $rollback_failed
                ),
            'jobId'                => $job_id,
            'status'               => $has_more ? 'db_rollback_running' : 'db_rollback_complete',
            'hasMore'              => $has_more,
            'batchSize'            => $limit,
            'batchProcessedRefs'   => $batch_processed,
            'batchRestoredRefs'    => $batch_restored,
            'batchRollbackFailedRefs' => $batch_failed,
            'referencesFound'      => $total,
            'matchedRefs'          => $total,
            'pendingRefs'          => isset($summary['pendingRefs']) ? (int) $summary['pendingRefs'] : 0,
            'replacedRefs'         => max(0, $total - $restored - $rollback_failed),
            'failedRefs'           => isset($summary['failedRefs']) ? (int) $summary['failedRefs'] : 0,
            'restoredRefs'         => $restored,
            'pendingRollbackRefs'  => $pending_rollback,
            'rollbackFailedRefs'   => $rollback_failed,
            'serializedRefs'       => isset($summary['serializedRefs']) ? (int) $summary['serializedRefs'] : 0,
            'jsonRefs'             => isset($summary['jsonRefs']) ? (int) $summary['jsonRefs'] : 0,
            'refsScanned'          => $processed,
            'remainingRefsScan'    => 0,
            'progressPercent'      => $progress,
            'databaseReferencesMatched' => true,
            'databaseReplaced'     => false,
            'databaseRolledBack'   => !$has_more && $restored === $total && 0 === $rollback_failed,
            'nextStep'             => $has_more
                ? __('Continue rolling back database references in chunks. Do not delete original files.', 'ultracache')
                : __('Database reference rollback is complete. Original files and attachment metadata still remain; cleanup should not run until the full rollback/cleanup tools are in place.', 'ultracache'),
        );
    }


    private function get_media_replacement_database_preview_summary($job_id)
    {
        global $wpdb;

        $refs_table = $this->get_media_replacement_refs_table_name();
        $job_id     = sanitize_key((string) $job_id);
        if ('' === $refs_table || '' === $job_id || !($wpdb instanceof wpdb)) {
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
            'serializedRefs'   => 0,
            'jsonRefs'         => 0,
            'plainRefs'        => 0,
            'tables'           => array(),
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT status, COUNT(*) AS ref_count, SUM(serialized) AS serialized_refs, SUM(json_detected) AS json_refs FROM %i WHERE job_id = %s GROUP BY status',
                $refs_table,
                $job_id
            ),
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $status = isset($row['status']) ? sanitize_key((string) $row['status']) : '';
            $count  = isset($row['ref_count']) ? max(0, (int) $row['ref_count']) : 0;
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
                'SELECT table_name, COUNT(*) AS ref_count FROM %i WHERE job_id = %s GROUP BY table_name ORDER BY ref_count DESC, table_name ASC',
                $refs_table,
                $job_id
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

    private function get_media_replacement_database_preview_rows($job_id, $limit = 200, $offset = 0)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $refs_table  = $this->get_media_replacement_refs_table_name();
        $job_id      = sanitize_key((string) $job_id);
        $limit       = max(1, min(500, absint($limit)));
        $offset      = max(0, absint($offset));

        if ('' === $items_table || '' === $refs_table || '' === $job_id || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT r.id, r.item_id, r.table_name, r.primary_key_column, r.primary_key_value, r.column_name, r.old_fragment, r.new_fragment, r.serialized, r.json_detected, r.status, r.error_message, i.attachment_id, i.old_relative_path, i.new_relative_path FROM %i r LEFT JOIN %i i ON r.item_id = i.id WHERE r.job_id = %s ORDER BY r.id ASC LIMIT %d OFFSET %d',
                $refs_table,
                $items_table,
                $job_id,
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
        $job_id = $this->get_media_replacement_preview_job_id(isset($args['job_id']) ? (string) $args['job_id'] : '');
        if ('' === $job_id) {
            return array(
                'success' => false,
                'message' => __('Run Scan Database References before previewing database replacements.', 'ultracache'),
                'hasReplacementPreview' => false,
            );
        }
        if (!$this->media_replacement_job_has_registry_rows($job_id)) {
            $empty = $this->build_media_replacement_empty_registry_response($job_id, __('No Media Library replacement registry rows are available for database replacement preview. Restore the database backup or roll back attachment metadata, then run Restart Replacement Plan again.', 'ultracache'));
            $empty['hasReplacementPreview'] = false;
            return $empty;
        }

        $limit  = isset($args['limit']) ? absint($args['limit']) : 200;
        $offset = isset($args['offset']) ? absint($args['offset']) : 0;
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $duplicates_removed = $this->deduplicate_media_replacement_database_reference_rows($job_id);
        $summary = $this->get_media_replacement_database_preview_summary($job_id);
        if (empty($summary)) {
            return array(
                'success' => false,
                'message' => __('Database replacement preview is not available for the active Media Library replacement job.', 'ultracache'),
                'jobId' => $job_id,
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
        $items = $total > 0 ? $this->get_media_replacement_database_preview_rows($job_id, $limit, $offset) : array();
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
            $next_step = __('Retry Verify DB Replacements to repair and verify failed serialized rows before cleanup.', 'ultracache');
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
            $next_step = __('Next step: run Cleanup Preview, then Cleanup Apply only after reviewing the candidates.', 'ultracache');
            $message = sprintf(
                /* translators: %d: verified database reference count. */
                __('Media Library replacement has verified %d database replacements.', 'ultracache'),
                $verified_refs
            );
        } else {
            $next_step = __('No database replacements are pending for this job. The next step is verification/cleanup planning, not DB replacement.', 'ultracache');
            $message = __('Media Library replacement database replacement preview found no old image references to apply. No database content changes are needed for this job.', 'ultracache');
        }

        $response = array(
            'success'               => true,
            'message'               => $message,
            'jobId'                 => $job_id,
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

        return $this->add_media_replacement_confirmation_token_to_response($response, $job_id, 'database_apply', $pending_refs > 0);
    }

}
