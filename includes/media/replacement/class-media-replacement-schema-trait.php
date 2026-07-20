<?php
/**
 * UltraCache Media Library replacement schema and registry-table foundation.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Replacement_Schema_Trait
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache uses private custom Media Library replacement registry tables with validated table identifiers.

    private function get_media_replacement_items_table_name()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return '';
        }

        $table = (string) $wpdb->prefix . 'ultracache_media_replacement_items';
        return function_exists('ultracache_validate_custom_table_name') ? ultracache_validate_custom_table_name($table, 'media_replacement_items') : $table;
    }

    private function get_media_replacement_refs_table_name()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return '';
        }

        $table = (string) $wpdb->prefix . 'ultracache_media_replacement_refs';
        return function_exists('ultracache_validate_custom_table_name') ? ultracache_validate_custom_table_name($table, 'media_replacement_refs') : $table;
    }

    private function get_media_replacement_ref_index_table_name()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return '';
        }

        $table = (string) $wpdb->prefix . 'ultracache_media_replacement_ref_index';
        return function_exists('ultracache_validate_custom_table_name') ? ultracache_validate_custom_table_name($table, 'media_replacement_ref_index') : $table;
    }

    private function get_media_replacement_file_refs_table_name()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return '';
        }

        $table = (string) $wpdb->prefix . 'ultracache_media_replacement_file_refs';
        return function_exists('ultracache_validate_custom_table_name') ? ultracache_validate_custom_table_name($table, 'media_replacement_file_refs') : $table;
    }

    private function get_media_replacement_theme_css_files_table_name()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return '';
        }

        $table = (string) $wpdb->prefix . 'ultracache_media_replacement_theme_css_files';
        return function_exists('ultracache_validate_custom_table_name') ? ultracache_validate_custom_table_name($table, 'media_replacement_theme_css_files') : $table;
    }

    private function media_replacement_table_exists($table)
    {
        global $wpdb;

        $table = (string) $table;
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return false;
        }

        $table_like = method_exists($wpdb, 'esc_like') ? $wpdb->esc_like($table) : addcslashes($table, '_%\\');
        $found      = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_like));
        return (string) $found === $table;
    }


    private function media_replacement_table_has_columns($table, array $columns)
    {
        global $wpdb;

        $table = (string) $table;
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return false;
        }
        foreach ($columns as $column) {
            $column = sanitize_key((string) $column);
            if ('' === $column) {
                return false;
            }
            $found = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $table, $column));
            if ((string) $found !== $column) {
                return false;
            }
        }
        return true;
    }

    private function media_replacement_items_table_exists()
    {
        return $this->media_replacement_table_exists($this->get_media_replacement_items_table_name());
    }

    private function media_replacement_refs_table_exists()
    {
        return $this->media_replacement_table_exists($this->get_media_replacement_refs_table_name());
    }

    private function media_replacement_ref_index_table_exists()
    {
        return $this->media_replacement_table_exists($this->get_media_replacement_ref_index_table_name());
    }

    private function media_replacement_file_refs_table_exists()
    {
        return $this->media_replacement_table_exists($this->get_media_replacement_file_refs_table_name());
    }

    private function media_replacement_theme_css_files_table_exists()
    {
        return $this->media_replacement_table_exists($this->get_media_replacement_theme_css_files_table_name());
    }

    private function media_replacement_schema_ready_for_current_version()
    {
        return self::MEDIA_REPLACEMENT_DB_VERSION === (string) get_option(self::MEDIA_REPLACEMENT_DB_VERSION_OPTION, '')
            && $this->media_replacement_items_table_exists()
            && $this->media_replacement_refs_table_exists()
            && $this->media_replacement_ref_index_table_exists()
            && $this->media_replacement_file_refs_table_exists()
            && $this->media_replacement_theme_css_files_table_exists()
            && $this->media_replacement_table_has_columns($this->get_media_replacement_file_refs_table_name(), array('apply_old_found', 'apply_new_found', 'verify_old_found', 'verify_new_found'))
            && $this->media_replacement_table_has_columns($this->get_media_replacement_theme_css_files_table_name(), array('checksum_after', 'checksum_scheme'));
    }

    private function acquire_media_replacement_schema_lock()
    {
        if (!function_exists('ultracache_acquire_lock')) {
            return '';
        }

        $now = time();
        $legacy_lock = get_option(self::MEDIA_REPLACEMENT_SCHEMA_LOCK, false);
        if (is_array($legacy_lock) && (int) ($legacy_lock['expires_at'] ?? 0) > $now) {
            return '';
        }
        if (false !== $legacy_lock) {
            delete_option(self::MEDIA_REPLACEMENT_SCHEMA_LOCK);
        }

        $token = wp_generate_uuid4();
        if ('' === $token) {
            return '';
        }

        $payload = array(
            'operation'  => 'media_replacement_schema_upgrade',
            'db_version' => self::MEDIA_REPLACEMENT_DB_VERSION,
        );

        return ultracache_acquire_lock(
            self::MEDIA_REPLACEMENT_SCHEMA_LOCK,
            $token,
            self::MEDIA_REPLACEMENT_SCHEMA_LOCK_TTL,
            $payload
        ) ? $token : '';
    }

    private function release_media_replacement_schema_lock($token)
    {
        $token = (string) $token;
        if ('' === $token || !function_exists('ultracache_release_lock')) {
            return;
        }

        ultracache_release_lock(self::MEDIA_REPLACEMENT_SCHEMA_LOCK, $token);
    }

    private function wait_for_media_replacement_schema_upgrade($attempts = 20)
    {
        $attempts = max(1, min(50, (int) $attempts));
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($this->media_replacement_schema_ready_for_current_version()) {
                return true;
            }
            usleep(100000);
        }

        return $this->media_replacement_schema_ready_for_current_version();
    }

    private function reset_legacy_media_replacement_storage()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return false;
        }

        $tables = array(
            $this->get_media_replacement_theme_css_files_table_name(),
            $this->get_media_replacement_file_refs_table_name(),
            $this->get_media_replacement_ref_index_table_name(),
            $this->get_media_replacement_refs_table_name(),
            $this->get_media_replacement_items_table_name(),
        );

        foreach ($tables as $table) {
            if ('' === $table) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional reset of obsolete test-only replacement tables.
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
        }

        foreach (array(
            'ultracache_media_replacement_active_job_v1',
            'ultracache_media_replacement_active_job_v2',
            'ultracache_media_replacement_ref_index_scan_v1',
            'ultracache_media_replacement_ref_index_specs_v1',
            'ultracache_media_replacement_intermediate_expand_v1',
            'ultracache_media_replacement_theme_css_scan_state',
            'ultracache_media_replacement_theme_css_scan_manifest_v1',
            'ultracache_media_replacement_theme_css_stream_state_v1',
            'ultracache_media_replacement_readiness_v1',
            'ultracache_media_replacement_cli_pause_request_v1',
            self::MEDIA_REPLACEMENT_DB_VERSION_OPTION,
        ) as $option_name) {
            delete_option($option_name);
        }

        return !$this->media_replacement_items_table_exists()
            && !$this->media_replacement_refs_table_exists()
            && !$this->media_replacement_ref_index_table_exists()
            && !$this->media_replacement_file_refs_table_exists()
            && !$this->media_replacement_theme_css_files_table_exists();
    }

    public function ensure_media_replacement_tables()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return false;
        }

        $items_table     = $this->get_media_replacement_items_table_name();
        $refs_table      = $this->get_media_replacement_refs_table_name();
        $ref_index_table = $this->get_media_replacement_ref_index_table_name();
        $file_refs_table = $this->get_media_replacement_file_refs_table_name();
        $theme_css_files_table = $this->get_media_replacement_theme_css_files_table_name();
        if ('' === $items_table || '' === $refs_table || '' === $ref_index_table || '' === $file_refs_table || '' === $theme_css_files_table) {
            return false;
        }

        if ($this->media_replacement_schema_ready_for_current_version()) {
            return true;
        }

        $schema_lock_token = $this->acquire_media_replacement_schema_lock();
        if ('' === $schema_lock_token) {
            if ($this->wait_for_media_replacement_schema_upgrade()) {
                return true;
            }
            $schema_lock_token = $this->acquire_media_replacement_schema_lock();
            if ('' === $schema_lock_token) {
                return false;
            }
        }

        try {
            if ($this->media_replacement_schema_ready_for_current_version()) {
                return true;
            }

            $version = (string) get_option(self::MEDIA_REPLACEMENT_DB_VERSION_OPTION, '');
            $legacy_storage_exists = '' !== $version
                || $this->media_replacement_items_table_exists()
                || $this->media_replacement_refs_table_exists()
                || $this->media_replacement_ref_index_table_exists()
                || $this->media_replacement_file_refs_table_exists()
                || $this->media_replacement_theme_css_files_table_exists()
                || false !== get_option('ultracache_media_replacement_active_job_v1', false)
                || false !== get_option('ultracache_media_replacement_active_job_v2', false)
                || false !== get_option('ultracache_media_replacement_ref_index_scan_v1', false)
                || false !== get_option('ultracache_media_replacement_ref_index_specs_v1', false)
                || false !== get_option('ultracache_media_replacement_intermediate_expand_v1', false)
                || false !== get_option('ultracache_media_replacement_theme_css_scan_state', false)
                || false !== get_option('ultracache_media_replacement_theme_css_scan_manifest_v1', false)
                || false !== get_option('ultracache_media_replacement_theme_css_stream_state_v1', false)
                || false !== get_option('ultracache_media_replacement_readiness_v1', false);

            $additive_schema_upgrade = in_array($version, array('8', '9'), true)
                && $this->media_replacement_items_table_exists()
                && $this->media_replacement_refs_table_exists()
                && $this->media_replacement_ref_index_table_exists()
                && $this->media_replacement_file_refs_table_exists();

            if ($legacy_storage_exists && self::MEDIA_REPLACEMENT_DB_VERSION !== $version && !$additive_schema_upgrade) {
                if (!$this->reset_legacy_media_replacement_storage()) {
                    return false;
                }
            }

            if (!function_exists('ultracache_require_wordpress_admin_include') || !ultracache_require_wordpress_admin_include('upgrade.php', 'dbDelta')) {
                return false;
            }

            $charset_collate = $wpdb->get_charset_collate();

            $items_sql = "CREATE TABLE {$items_table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                job_id varchar(64) NOT NULL DEFAULT '',
                attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
                item_scope varchar(24) NOT NULL DEFAULT 'main',
                size_name varchar(64) NOT NULL DEFAULT '',
                old_path_hash char(32) NOT NULL DEFAULT '',
                source_format varchar(12) NOT NULL DEFAULT '',
                target_format varchar(12) NOT NULL DEFAULT '',
                fallback_format varchar(12) NOT NULL DEFAULT '',
                old_relative_path text NOT NULL,
                old_url text NOT NULL,
                old_file_path text NOT NULL,
                generated_file_path text NOT NULL,
                new_relative_path text NOT NULL,
                new_url text NOT NULL,
                new_file_path text NOT NULL,
                old_mime varchar(100) NOT NULL DEFAULT '',
                new_mime varchar(100) NOT NULL DEFAULT '',
                old_size bigint(20) unsigned NOT NULL DEFAULT 0,
                new_size bigint(20) unsigned NOT NULL DEFAULT 0,
                old_metadata_json longtext NOT NULL,
                new_metadata_json longtext NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'pending',
                error_message text NULL,
                created_at datetime NULL DEFAULT NULL,
                updated_at datetime NULL DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY job_attachment_variant (job_id, attachment_id, item_scope, size_name),
                KEY old_path_hash (old_path_hash),
                KEY attachment_id (attachment_id),
                KEY job_status (job_id, status),
                KEY job_cursor (job_id, id),
                KEY target_format (target_format),
                KEY updated_at (updated_at)
            ) {$charset_collate};";

            $refs_sql = "CREATE TABLE {$refs_table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                job_id varchar(64) NOT NULL DEFAULT '',
                item_id bigint(20) unsigned NOT NULL DEFAULT 0,
                ref_hash char(32) NOT NULL DEFAULT '',
                table_name varchar(191) NOT NULL DEFAULT '',
                primary_key_column varchar(64) NOT NULL DEFAULT '',
                primary_key_value varchar(191) NOT NULL DEFAULT '',
                column_name varchar(64) NOT NULL DEFAULT '',
                old_value_hash char(32) NOT NULL DEFAULT '',
                new_value_hash char(32) NOT NULL DEFAULT '',
                old_fragment longtext NOT NULL,
                new_fragment longtext NOT NULL,
                serialized tinyint(1) unsigned NOT NULL DEFAULT 0,
                json_detected tinyint(1) unsigned NOT NULL DEFAULT 0,
                status varchar(24) NOT NULL DEFAULT 'pending',
                error_message text NULL,
                created_at datetime NULL DEFAULT NULL,
                updated_at datetime NULL DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY job_ref (job_id, ref_hash),
                KEY item_status (item_id, status),
                KEY job_status (job_id, status),
                KEY job_cursor (job_id, id),
                KEY job_table_column (job_id, table_name, column_name),
                KEY table_name (table_name),
                KEY column_name (column_name),
                KEY updated_at (updated_at)
            ) {$charset_collate};";

            $ref_index_sql = "CREATE TABLE {$ref_index_table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                job_id varchar(64) NOT NULL DEFAULT '',
                ref_hash char(32) NOT NULL DEFAULT '',
                table_name varchar(191) NOT NULL DEFAULT '',
                primary_key_column varchar(64) NOT NULL DEFAULT '',
                primary_key_value varchar(191) NOT NULL DEFAULT '',
                column_name varchar(64) NOT NULL DEFAULT '',
                reference_type varchar(24) NOT NULL DEFAULT '',
                raw_fragment longtext NOT NULL,
                normalized_fragment longtext NOT NULL,
                url_path_hash char(32) NOT NULL DEFAULT '',
                serialized tinyint(1) unsigned NOT NULL DEFAULT 0,
                json_detected tinyint(1) unsigned NOT NULL DEFAULT 0,
                matched_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
                status varchar(24) NOT NULL DEFAULT 'pending',
                error_message text NULL,
                created_at datetime NULL DEFAULT NULL,
                updated_at datetime NULL DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY job_ref (job_id, ref_hash),
                KEY job_hash (job_id, url_path_hash),
                KEY matched_item (matched_item_id),
                KEY job_status (job_id, status),
                KEY job_cursor (job_id, id),
                KEY job_table_column (job_id, table_name, column_name),
                KEY table_name (table_name),
                KEY column_name (column_name),
                KEY updated_at (updated_at)
            ) {$charset_collate};";

            $file_refs_sql = "CREATE TABLE {$file_refs_table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                job_id varchar(64) NOT NULL DEFAULT '',
                item_id bigint(20) unsigned NOT NULL DEFAULT 0,
                ref_hash char(32) NOT NULL DEFAULT '',
                file_path text NOT NULL,
                relative_file_path text NOT NULL,
                old_fragment longtext NOT NULL,
                new_fragment longtext NOT NULL,
                backup_file_path text NOT NULL,
                checksum_before char(32) NOT NULL DEFAULT '',
                checksum_after char(32) NOT NULL DEFAULT '',
                apply_old_found tinyint(1) unsigned NOT NULL DEFAULT 0,
                apply_new_found tinyint(1) unsigned NOT NULL DEFAULT 0,
                verify_old_found tinyint(1) unsigned NOT NULL DEFAULT 0,
                verify_new_found tinyint(1) unsigned NOT NULL DEFAULT 0,
                status varchar(24) NOT NULL DEFAULT 'pending',
                error_message text NULL,
                created_at datetime NULL DEFAULT NULL,
                updated_at datetime NULL DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY job_ref (job_id, ref_hash),
                KEY item_status (item_id, status),
                KEY job_status (job_id, status),
                KEY job_cursor (job_id, id),
                KEY updated_at (updated_at)
            ) {$charset_collate};";

            $theme_css_files_sql = "CREATE TABLE {$theme_css_files_table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                job_id varchar(64) NOT NULL DEFAULT '',
                path_hash char(32) NOT NULL DEFAULT '',
                file_path text NOT NULL,
                relative_file_path text NOT NULL,
                file_size bigint(20) unsigned NOT NULL DEFAULT 0,
                file_mtime bigint(20) unsigned NOT NULL DEFAULT 0,
                checksum_before char(32) NOT NULL DEFAULT '',
                checksum_after char(32) NOT NULL DEFAULT '',
                checksum_scheme varchar(24) NOT NULL DEFAULT '',
                scan_status varchar(24) NOT NULL DEFAULT 'pending',
                validation_status varchar(24) NOT NULL DEFAULT 'pending',
                error_message text NULL,
                created_at datetime NULL DEFAULT NULL,
                updated_at datetime NULL DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY job_path (job_id, path_hash),
                KEY job_scan_cursor (job_id, scan_status, id),
                KEY job_validation (job_id, validation_status, id),
                KEY job_cursor (job_id, id),
                KEY updated_at (updated_at)
            ) {$charset_collate};";

            dbDelta($items_sql);
            dbDelta($refs_sql);
            dbDelta($ref_index_sql);
            dbDelta($file_refs_sql);
            dbDelta($theme_css_files_sql);

            if ($this->media_replacement_items_table_exists()) {
                $legacy_index = $wpdb->get_var($wpdb->prepare('SHOW INDEX FROM %i WHERE Key_name = %s', $items_table, 'job_attachment'));
                if (!empty($legacy_index)) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removes an obsolete replacement registry index during the schema upgrade.
                    $wpdb->query($wpdb->prepare('ALTER TABLE %i DROP INDEX %i', $items_table, 'job_attachment'));
                }

                $wpdb->query($wpdb->prepare("UPDATE %i SET item_scope = %s WHERE item_scope = '' OR item_scope IS NULL", $items_table, 'main'));
                $wpdb->query($wpdb->prepare("UPDATE %i SET old_path_hash = MD5(old_relative_path) WHERE old_path_hash = '' AND old_relative_path <> ''", $items_table));
            }

            if ($this->media_replacement_items_table_exists()
                && $this->media_replacement_refs_table_exists()
                && $this->media_replacement_ref_index_table_exists()
                && $this->media_replacement_file_refs_table_exists()
                && $this->media_replacement_theme_css_files_table_exists()
                && $this->media_replacement_table_has_columns($file_refs_table, array('apply_old_found', 'apply_new_found', 'verify_old_found', 'verify_new_found'))
                && $this->media_replacement_table_has_columns($theme_css_files_table, array('checksum_after', 'checksum_scheme'))
            ) {
                update_option(self::MEDIA_REPLACEMENT_DB_VERSION_OPTION, self::MEDIA_REPLACEMENT_DB_VERSION, false);
                return true;
            }

            return false;
        } finally {
            $this->release_media_replacement_schema_lock($schema_lock_token);
        }
    }

    private function get_media_replacement_table_status()
    {
        $items_table     = $this->get_media_replacement_items_table_name();
        $refs_table      = $this->get_media_replacement_refs_table_name();
        $ref_index_table = $this->get_media_replacement_ref_index_table_name();
        $file_refs_table = $this->get_media_replacement_file_refs_table_name();
        $theme_css_files_table = $this->get_media_replacement_theme_css_files_table_name();

        return array(
            'version'        => self::MEDIA_REPLACEMENT_DB_VERSION,
            'itemsTable'     => $items_table,
            'refsTable'      => $refs_table,
            'refIndexTable'  => $ref_index_table,
            'fileRefsTable'  => $file_refs_table,
            'themeCssFilesTable' => $theme_css_files_table,
            'itemsReady'     => '' !== $items_table && $this->media_replacement_items_table_exists(),
            'refsReady'      => '' !== $refs_table && $this->media_replacement_refs_table_exists(),
            'refIndexReady'  => '' !== $ref_index_table && $this->media_replacement_ref_index_table_exists(),
            'fileRefsReady'  => '' !== $file_refs_table && $this->media_replacement_file_refs_table_exists(),
            'themeCssFilesReady' => '' !== $theme_css_files_table && $this->media_replacement_theme_css_files_table_exists(),
        );
    }



}
