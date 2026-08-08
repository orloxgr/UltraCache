<?php
/**
 * Custom-table validation and database-backed lock helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

function ultracache_get_allowed_custom_table_basenames()
{
    return array(
        'ultracache_media_queue',
        'ultracache_media_queue_units',
        'ultracache_media_page_refs',
        'ultracache_media_replacement_items',
        'ultracache_media_replacement_attachment_plans',
        'ultracache_media_replacement_refs',
        'ultracache_media_replacement_ref_index',
        'ultracache_media_replacement_file_refs',
        'ultracache_media_replacement_theme_css_files',
        'ultracache_action_jobs',
        'ultracache_js_diagnostic_jobs',
        'ultracache_cron_warm_queue',
        'ultracache_analytics',
        'ultracache_cache_asset_refs',
        'ultracache_css_rewrite_map',
        'ultracache_lcp_observations',
        'ultracache_locks',
    );
}

function ultracache_is_allowed_custom_table_name($table)
{
    global $wpdb;

    $table = (string) $table;
    if ('' === $table || !($wpdb instanceof wpdb)) {
        return false;
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }

    $allowed = array();
    foreach (ultracache_get_allowed_custom_table_basenames() as $basename) {
        $allowed[(string) $wpdb->prefix . $basename] = true;
    }

    return isset($allowed[$table]);
}

function ultracache_validate_custom_table_name($table, $context = '')
{
    $table = (string) $table;
    if (ultracache_is_allowed_custom_table_name($table)) {
        return $table;
    }

    ultracache_debug_log('blocked invalid UltraCache custom table identifier', array('table' => $table, 'context' => (string) $context));
    return '';
}

/**
 * Return the validated UltraCache lock table name.
 *
 * @return string
 */
function ultracache_get_locks_table_name()
{
    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return '';
    }

    return ultracache_validate_custom_table_name(
        (string) $wpdb->prefix . 'ultracache_locks',
        'locks'
    );
}

/**
 * Return the schema version used by the UltraCache lock table.
 *
 * @return string
 */
function ultracache_get_locks_db_version()
{
    return '2';
}

/**
 * Return the option key storing the UltraCache lock-table schema version.
 *
 * @return string
 */
function ultracache_get_locks_db_version_option_key()
{
    return 'ultracache_locks_db_version';
}

/**
 * Return the bootstrap option used to serialize lock-table schema changes.
 *
 * The lock table cannot coordinate its own upgrade until the current schema is
 * ready, so this one narrow option lock protects dbDelta across concurrent
 * activation/frontend requests.
 *
 * @return string
 */
function ultracache_get_locks_schema_install_lock_option_key()
{
    return 'ultracache_locks_schema_install_lock';
}

/**
 * Verify the current lock/state table columns and indexes without mutating it.
 *
 * @param string $table Validated table name.
 * @return bool
 */
function ultracache_locks_table_schema_ready($table)
{
    global $wpdb;

    $table = (string) $table;
    if ('' === $table || !($wpdb instanceof wpdb)) {
        return false;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only verification of one validated UltraCache-owned schema.
    $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table), 0);
    if (!is_array($columns)) {
        return false;
    }

    $required_columns = array(
        'lock_name',
        'record_type',
        'token',
        'payload',
        'revision',
        'acquired_at',
        'updated_at',
        'expires_at',
    );
    if (!empty(array_diff($required_columns, array_map('strval', $columns)))) {
        return false;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only verification of required indexes on one validated UltraCache-owned schema.
    $index_rows = $wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i', $table), ARRAY_A);
    if (!is_array($index_rows)) {
        return false;
    }

    $indexes = array();
    foreach ($index_rows as $row) {
        $key_name = (string) ($row['Key_name'] ?? '');
        $column_name = (string) ($row['Column_name'] ?? '');
        $sequence = max(1, (int) ($row['Seq_in_index'] ?? 1));
        if ('' === $key_name || '' === $column_name) {
            continue;
        }
        $indexes[$key_name][$sequence] = $column_name;
    }
    foreach ($indexes as $key_name => $columns_by_sequence) {
        ksort($columns_by_sequence, SORT_NUMERIC);
        $indexes[$key_name] = array_values($columns_by_sequence);
    }

    return isset($indexes['PRIMARY'], $indexes['expires_at'], $indexes['type_expires'])
        && array('lock_name') === $indexes['PRIMARY']
        && array('expires_at') === $indexes['expires_at']
        && array('record_type', 'expires_at') === $indexes['type_expires'];
}

/**
 * Acquire the narrow pre-schema installation lock.
 *
 * @param string $token Lock owner token.
 * @param int    $ttl   Lock lifetime in seconds.
 * @return bool
 */
function ultracache_acquire_locks_schema_install_lock($token, $ttl = 30)
{
    $token = sanitize_text_field((string) $token);
    $ttl = max(5, min(120, absint($ttl)));
    if ('' === $token) {
        return false;
    }

    $option_key = ultracache_get_locks_schema_install_lock_option_key();
    $now = time();
    $payload = array(
        'token' => $token,
        'expiresAt' => $now + $ttl,
    );
    if (add_option('ultracache_locks_schema_install_lock', $payload, '', false)) {
        return true;
    }

    $existing = get_option($option_key, array());
    $existing = is_array($existing) ? $existing : array();
    if (max(0, (int) ($existing['expiresAt'] ?? 0)) > $now) {
        return false;
    }

    delete_option($option_key);
    return add_option('ultracache_locks_schema_install_lock', $payload, '', false);
}

/**
 * Release the narrow pre-schema installation lock when still owned by token.
 *
 * @param string $token Lock owner token.
 * @return void
 */
function ultracache_release_locks_schema_install_lock($token)
{
    $token = sanitize_text_field((string) $token);
    if ('' === $token) {
        return;
    }

    $option_key = ultracache_get_locks_schema_install_lock_option_key();
    $existing = get_option($option_key, array());
    if (is_array($existing) && hash_equals((string) ($existing['token'] ?? ''), $token)) {
        delete_option($option_key);
    }
}

/**
 * Check whether the shared lock/state table is readable without creating or
 * upgrading schema. Status surfaces use this path so reads cannot invoke
 * dbDelta or update the stored schema version.
 *
 * @return bool
 */
function ultracache_locks_table_read_ready()
{
    static $ready = null;

    if (is_bool($ready)) {
        return $ready;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        $ready = false;
        return false;
    }

    if (ultracache_get_locks_db_version() !== (string) get_option(ultracache_get_locks_db_version_option_key(), '')) {
        $ready = false;
        return false;
    }

    $table = ultracache_get_locks_table_name();
    if ('' === $table) {
        $ready = false;
        return false;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only schema existence check; deliberately avoids dbDelta and cache writes.
    $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    $ready = (string) $existing === $table && ultracache_locks_table_schema_ready($table);
    return $ready;
}

/**
 * Ensure the shared UltraCache lock table exists.
 *
 * Schema installation is serialized before dbDelta because this table is the
 * prerequisite for all later UltraCache coordination locks. Concurrent plugin
 * activation/frontend requests must never race separate ALTER statements.
 *
 * @return bool
 */
function ultracache_ensure_locks_table()
{
    static $ready = false;

    if ($ready) {
        return true;
    }

    if (defined('ULTRACACHE_UNINSTALL_IN_PROGRESS') && ULTRACACHE_UNINSTALL_IN_PROGRESS) {
        return false;
    }

    global $wpdb;
    if (!($wpdb instanceof wpdb)) {
        return false;
    }

    $table = ultracache_get_locks_table_name();
    if ('' === $table) {
        return false;
    }

    $expected_version = ultracache_get_locks_db_version();
    $stored_version = (string) get_option(ultracache_get_locks_db_version_option_key(), '');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One schema-existence check for the validated UltraCache-owned lock table.
    $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    if ((string) $existing === $table && ultracache_locks_table_schema_ready($table)) {
        if ($expected_version !== $stored_version) {
            update_option(ultracache_get_locks_db_version_option_key(), $expected_version, false);
        }
        $ready = true;
        return true;
    }

    $schema_token = 'locks-schema-' . gmdate('YmdHis') . '-' . wp_generate_password(20, false, false);
    if (!ultracache_acquire_locks_schema_install_lock($schema_token, 30)) {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            usleep(100000);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Waits for the single concurrent schema owner and verifies its result without issuing DDL.
            $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ((string) $existing === $table && ultracache_locks_table_schema_ready($table)) {
                update_option(ultracache_get_locks_db_version_option_key(), $expected_version, false);
                $ready = true;
                return true;
            }
        }
        return false;
    }

    try {
        $stored_version = (string) get_option(ultracache_get_locks_db_version_option_key(), '');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rechecks schema after acquiring the sole bootstrap installer lock.
        $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ((string) $existing === $table && ultracache_locks_table_schema_ready($table)) {
            if ($expected_version !== $stored_version) {
                update_option(ultracache_get_locks_db_version_option_key(), $expected_version, false);
            }
            $ready = true;
            return true;
        }

        if (!ultracache_require_wordpress_admin_include('upgrade.php', 'dbDelta')) {
            return false;
        }

        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            lock_name varchar(191) NOT NULL,
            record_type varchar(16) NOT NULL DEFAULT 'lock',
            token varchar(191) NOT NULL,
            payload longtext NOT NULL,
            revision bigint(20) unsigned NOT NULL DEFAULT 0,
            acquired_at bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
            expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (lock_name),
            KEY expires_at (expires_at),
            KEY type_expires (record_type, expires_at)
        ) {$charset_collate};";

        dbDelta($sql);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Immediate schema verification after the serialized dbDelta operation.
        $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ((string) $existing !== $table || !ultracache_locks_table_schema_ready($table)) {
            return false;
        }

        update_option(ultracache_get_locks_db_version_option_key(), $expected_version, false);
        $ready = true;
        return true;
    } finally {
        ultracache_release_locks_schema_install_lock($schema_token);
    }
}

/**
 * Delete UltraCache coordination rows by a validated logical prefix.
 *
 * This helper is reserved for explicit lifecycle resets. It never accepts an
 * arbitrary record type and cannot remove records outside the UltraCache name
 * namespace.
 *
 * @param string $record_type Lock or state.
 * @param string $prefix      Valid UltraCache logical-name prefix.
 * @return int|false
 */
function ultracache_delete_coordination_records_by_prefix($record_type, $prefix)
{
    global $wpdb;

    $record_type = sanitize_key((string) $record_type);
    $prefix = trim((string) $prefix);
    if (
        !in_array($record_type, array('lock', 'state'), true)
        || '' === $prefix
        || strlen($prefix) > 191
        || 1 !== preg_match('/^ultracache_[A-Za-z0-9_.:-]+$/', $prefix)
        || !($wpdb instanceof wpdb)
        || !ultracache_ensure_locks_table()
    ) {
        return false;
    }

    $table = ultracache_get_locks_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit lifecycle reset deletes only UltraCache-owned coordination rows under one validated prefix.
    $deleted = $wpdb->query(
        $wpdb->prepare(
            'DELETE FROM %i WHERE record_type = %s AND lock_name LIKE %s',
            $table,
            $record_type,
            $wpdb->esc_like($prefix) . '%'
        )
    );

    return false === $deleted ? false : max(0, (int) $deleted);
}

/**
 * Normalize and validate a logical UltraCache lock name.
 *
 * @param string $lock_name Lock name.
 * @return string
 */
function ultracache_normalize_lock_name($lock_name)
{
    $lock_name = trim((string) $lock_name);
    if (
        '' === $lock_name
        || strlen($lock_name) > 191
        || 1 !== preg_match('/^ultracache_[A-Za-z0-9_.:-]+$/', $lock_name)
    ) {
        return '';
    }

    return $lock_name;
}

/**
 * Normalize a lock-owner token for storage.
 *
 * @param string $token Lock token.
 * @return string
 */
function ultracache_normalize_lock_token($token)
{
    $token = trim((string) $token);
    if ('' === $token) {
        return '';
    }

    return strlen($token) > 191 ? hash('sha256', $token) : $token;
}

/**
 * Encode lock metadata without allowing serialization failures to break locking.
 *
 * @param array $payload Lock metadata.
 * @return string
 */
function ultracache_encode_lock_payload(array $payload)
{
    $encoded = wp_json_encode($payload);
    return is_string($encoded) ? $encoded : '{}';
}

/**
 * Acquire a shared database lock or atomically take over an expired lock.
 *
 * @param string $lock_name Lock name.
 * @param string $token     Unique owner token.
 * @param int    $ttl       Lock lifetime in seconds.
 * @param array  $payload   Optional lock metadata.
 * @return bool
 */
function ultracache_acquire_lock($lock_name, $token, $ttl, array $payload = array())
{
    global $wpdb;

    $lock_name = ultracache_normalize_lock_name($lock_name);
    $token = ultracache_normalize_lock_token($token);
    $ttl = max(1, (int) $ttl);

    if ('' === $lock_name || '' === $token || !($wpdb instanceof wpdb) || !ultracache_ensure_locks_table()) {
        return false;
    }

    $table = ultracache_get_locks_table_name();
    $now = time();
    $expires_at = $now + $ttl;
    $encoded_payload = ultracache_encode_lock_payload($payload);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT IGNORE is the atomic acquisition primitive for the UltraCache-owned lock table.
    $inserted = $wpdb->query(
        $wpdb->prepare(
            "INSERT IGNORE INTO %i (lock_name, record_type, token, payload, revision, acquired_at, updated_at, expires_at) VALUES (%s, 'lock', %s, %s, 0, %d, %d, %d)",
            $table,
            $lock_name,
            $token,
            $encoded_payload,
            $now,
            $now,
            $expires_at
        )
    );

    if (1 === (int) $inserted) {
        return true;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional UPDATE atomically transfers only an expired UltraCache lock.
    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE %i SET token = %s, payload = %s, acquired_at = %d, updated_at = %d, expires_at = %d WHERE lock_name = %s AND record_type = 'lock' AND expires_at <= %d",
            $table,
            $token,
            $encoded_payload,
            $now,
            $now,
            $expires_at,
            $lock_name,
            $now
        )
    );

    return 1 === (int) $updated;
}

/**
 * Read the current database lock record.
 *
 * @param string $lock_name Lock name.
 * @return array
 */
function ultracache_get_lock($lock_name)
{
    global $wpdb;

    $lock_name = ultracache_normalize_lock_name($lock_name);
    if ('' === $lock_name || !($wpdb instanceof wpdb) || !ultracache_ensure_locks_table()) {
        return array();
    }

    $table = ultracache_get_locks_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock ownership must be read directly from the authoritative UltraCache lock table.
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT token, payload, acquired_at, updated_at, expires_at FROM %i WHERE lock_name = %s AND record_type = 'lock' LIMIT 1", $table, $lock_name),
        ARRAY_A
    );

    if (!is_array($row) || empty($row['token'])) {
        return array();
    }

    $payload = json_decode((string) ($row['payload'] ?? ''), true);
    return array(
        'name'       => $lock_name,
        'token'      => (string) $row['token'],
        'payload'    => is_array($payload) ? $payload : array(),
        'acquiredAt' => max(0, (int) ($row['acquired_at'] ?? 0)),
        'updatedAt'  => max(0, (int) ($row['updated_at'] ?? 0)),
        'expiresAt'  => max(0, (int) ($row['expires_at'] ?? 0)),
        'expired'    => (int) ($row['expires_at'] ?? 0) <= time(),
    );
}

/**
 * Read one lock record without creating or upgrading lock storage.
 *
 * @param string $lock_name Lock name.
 * @return array
 */
function ultracache_get_lock_read_only($lock_name)
{
    global $wpdb;

    $lock_name = ultracache_normalize_lock_name($lock_name);
    if ('' === $lock_name || !($wpdb instanceof wpdb) || !ultracache_locks_table_read_ready()) {
        return array();
    }

    $table = ultracache_get_locks_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only status lookup from the authoritative UltraCache lock table.
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT token, payload, acquired_at, updated_at, expires_at FROM %i WHERE lock_name = %s AND record_type = 'lock' LIMIT 1", $table, $lock_name),
        ARRAY_A
    );

    if (!is_array($row) || empty($row['token'])) {
        return array();
    }

    $payload = json_decode((string) ($row['payload'] ?? ''), true);
    return array(
        'name'       => $lock_name,
        'token'      => (string) $row['token'],
        'payload'    => is_array($payload) ? $payload : array(),
        'acquiredAt' => max(0, (int) ($row['acquired_at'] ?? 0)),
        'updatedAt'  => max(0, (int) ($row['updated_at'] ?? 0)),
        'expiresAt'  => max(0, (int) ($row['expires_at'] ?? 0)),
        'expired'    => (int) ($row['expires_at'] ?? 0) <= time(),
    );
}

/**
 * Renew a lock only when the supplied token still owns it.
 *
 * @param string     $lock_name Lock name.
 * @param string     $token     Owner token.
 * @param int        $ttl       New lifetime in seconds.
 * @param array|null $payload   Optional replacement metadata.
 * @return bool
 */
function ultracache_renew_lock($lock_name, $token, $ttl, $payload = null)
{
    global $wpdb;

    $lock_name = ultracache_normalize_lock_name($lock_name);
    $token = ultracache_normalize_lock_token($token);
    $ttl = max(1, (int) $ttl);

    if ('' === $lock_name || '' === $token || !($wpdb instanceof wpdb) || !ultracache_ensure_locks_table()) {
        return false;
    }

    $table = ultracache_get_locks_table_name();
    $expires_at = time() + $ttl;

    if (is_array($payload)) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Token-guarded renewal updates only the caller-owned UltraCache lock.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET payload = %s, updated_at = %d, expires_at = %d WHERE lock_name = %s AND record_type = 'lock' AND token = %s",
                $table,
                ultracache_encode_lock_payload($payload),
                time(),
                $expires_at,
                $lock_name,
                $token
            )
        );
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Token-guarded renewal updates only the caller-owned UltraCache lock.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET updated_at = %d, expires_at = %d WHERE lock_name = %s AND record_type = 'lock' AND token = %s",
                $table,
                time(),
                $expires_at,
                $lock_name,
                $token
            )
        );
    }

    if (1 === (int) $updated) {
        return true;
    }

    $current = ultracache_get_lock($lock_name);
    return !empty($current['token'])
        && hash_equals((string) $current['token'], $token)
        && (int) ($current['expiresAt'] ?? 0) >= $expires_at;
}

/**
 * Delete a bounded number of expired lock rows.
 *
 * @param int $limit Maximum rows to delete.
 * @return int
 */
function ultracache_prune_expired_locks($limit = 500)
{
    global $wpdb;

    $limit = max(1, min(5000, (int) $limit));
    if (!($wpdb instanceof wpdb) || !ultracache_ensure_locks_table()) {
        return 0;
    }

    $table = ultracache_get_locks_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded cleanup removes only expired rows from the UltraCache-owned lock table.
    $deleted = $wpdb->query(
        $wpdb->prepare("DELETE FROM %i WHERE record_type = 'lock' AND expires_at <= %d LIMIT %d", $table, time(), $limit)
    );

    return is_numeric($deleted) ? max(0, (int) $deleted) : 0;
}

/**
 * Release a lock only when the supplied token still owns it.
 *
 * @param string $lock_name Lock name.
 * @param string $token     Owner token.
 * @return bool
 */
function ultracache_release_lock($lock_name, $token)
{
    global $wpdb;

    $lock_name = ultracache_normalize_lock_name($lock_name);
    $token = ultracache_normalize_lock_token($token);
    if ('' === $lock_name || '' === $token || !($wpdb instanceof wpdb) || !ultracache_ensure_locks_table()) {
        return false;
    }

    $table = ultracache_get_locks_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Token-guarded DELETE prevents one worker from releasing another worker's UltraCache lock.
    $deleted = $wpdb->query(
        $wpdb->prepare("DELETE FROM %i WHERE lock_name = %s AND record_type = 'lock' AND token = %s", $table, $lock_name, $token)
    );

    return 1 === (int) $deleted;
}

