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
        'ultracache_media_page_refs',
        'ultracache_media_replacement_items',
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
    return '1';
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
 * Ensure the shared UltraCache lock table exists.
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

    if ($expected_version === $stored_version) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One schema-existence check for the validated UltraCache-owned lock table.
        $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ((string) $existing === $table) {
            $ready = true;
            return true;
        }
    }

    if (!ultracache_require_wordpress_admin_include('upgrade.php', 'dbDelta')) {
        return false;
    }

    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        lock_name varchar(191) NOT NULL,
        token varchar(191) NOT NULL,
        payload longtext NOT NULL,
        acquired_at bigint(20) unsigned NOT NULL DEFAULT 0,
        expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (lock_name),
        KEY expires_at (expires_at)
    ) {$charset_collate};";

    dbDelta($sql);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Immediate schema verification after dbDelta for the validated UltraCache-owned lock table.
    $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    if ((string) $existing !== $table) {
        return false;
    }

    update_option(ultracache_get_locks_db_version_option_key(), $expected_version, false);
    $ready = true;
    return true;
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
            'INSERT IGNORE INTO %i (lock_name, token, payload, acquired_at, expires_at) VALUES (%s, %s, %s, %d, %d)',
            $table,
            $lock_name,
            $token,
            $encoded_payload,
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
            'UPDATE %i SET token = %s, payload = %s, acquired_at = %d, expires_at = %d WHERE lock_name = %s AND expires_at <= %d',
            $table,
            $token,
            $encoded_payload,
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
        $wpdb->prepare('SELECT token, payload, acquired_at, expires_at FROM %i WHERE lock_name = %s LIMIT 1', $table, $lock_name),
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
                'UPDATE %i SET payload = %s, expires_at = %d WHERE lock_name = %s AND token = %s',
                $table,
                ultracache_encode_lock_payload($payload),
                $expires_at,
                $lock_name,
                $token
            )
        );
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Token-guarded renewal updates only the caller-owned UltraCache lock.
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET expires_at = %d WHERE lock_name = %s AND token = %s',
                $table,
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
        $wpdb->prepare('DELETE FROM %i WHERE expires_at <= %d LIMIT %d', $table, time(), $limit)
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
        $wpdb->prepare('DELETE FROM %i WHERE lock_name = %s AND token = %s', $table, $lock_name, $token)
    );

    return 1 === (int) $deleted;
}

