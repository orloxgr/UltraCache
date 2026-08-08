<?php
/**
 * Persistent revisioned coordination-state helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalize a persistent UltraCache state-record name.
 *
 * @param string $state_name State name.
 * @return string
 */
function ultracache_normalize_state_name($state_name)
{
    $state_name = trim((string) $state_name);
    if (
        '' === $state_name
        || strlen($state_name) > 191
        || 1 !== preg_match('/^ultracache_state:[A-Za-z0-9_.:-]+$/', $state_name)
    ) {
        return '';
    }

    return $state_name;
}

/**
 * Decode a stored coordination payload.
 *
 * @param string $payload Encoded payload.
 * @return array
 */
function ultracache_decode_state_payload($payload)
{
    $decoded = json_decode((string) $payload, true);
    return is_array($decoded) ? $decoded : array();
}

/**
 * Read one persistent state record.
 *
 * @param string $state_name State name.
 * @return array
 */
function ultracache_get_state_record($state_name)
{
    global $wpdb;

    $state_name = ultracache_normalize_state_name($state_name);
    if ('' === $state_name || !($wpdb instanceof wpdb) || !ultracache_ensure_locks_table()) {
        return array();
    }

    $table = ultracache_get_locks_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Coordination state must be read from the authoritative UltraCache-owned state row.
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT payload, revision, updated_at FROM %i WHERE lock_name = %s AND record_type = 'state' LIMIT 1",
            $table,
            $state_name
        ),
        ARRAY_A
    );

    if (!is_array($row)) {
        return array();
    }

    return array(
        'name'      => $state_name,
        'payload'   => ultracache_decode_state_payload((string) ($row['payload'] ?? '')),
        'revision'  => max(0, (int) ($row['revision'] ?? 0)),
        'updatedAt' => max(0, (int) ($row['updated_at'] ?? 0)),
    );
}

/**
 * Read one persistent state record without creating or upgrading storage.
 *
 * @param string $state_name State name.
 * @return array
 */
function ultracache_get_state_record_read_only($state_name)
{
    global $wpdb;

    $state_name = ultracache_normalize_state_name($state_name);
    if ('' === $state_name || !($wpdb instanceof wpdb) || !function_exists('ultracache_locks_table_read_ready') || !ultracache_locks_table_read_ready()) {
        return array();
    }

    $table = ultracache_get_locks_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only status lookup from the authoritative UltraCache state table.
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT payload, revision, updated_at FROM %i WHERE lock_name = %s AND record_type = 'state' LIMIT 1",
            $table,
            $state_name
        ),
        ARRAY_A
    );

    if (!is_array($row)) {
        return array();
    }

    return array(
        'name'      => $state_name,
        'payload'   => ultracache_decode_state_payload((string) ($row['payload'] ?? '')),
        'revision'  => max(0, (int) ($row['revision'] ?? 0)),
        'updatedAt' => max(0, (int) ($row['updated_at'] ?? 0)),
    );
}

/**
 * Create one persistent state record when it does not already exist.
 *
 * @param string $state_name State name.
 * @param array  $payload    Initial state payload.
 * @return array
 */
function ultracache_create_state_record($state_name, array $payload)
{
    global $wpdb;

    $state_name = ultracache_normalize_state_name($state_name);
    if ('' === $state_name || !($wpdb instanceof wpdb) || !ultracache_ensure_locks_table()) {
        return array(
            'success'  => false,
            'created'  => false,
            'conflict' => false,
            'reason'   => 'storage_unavailable',
            'state'    => array(),
        );
    }

    $table = ultracache_get_locks_table_name();
    $now = time();
    $encoded_payload = ultracache_encode_lock_payload($payload);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT IGNORE atomically creates a single named UltraCache state row.
    $inserted = $wpdb->query(
        $wpdb->prepare(
            "INSERT IGNORE INTO %i (lock_name, record_type, token, payload, revision, acquired_at, updated_at, expires_at) VALUES (%s, 'state', '', %s, 1, %d, %d, 0)",
            $table,
            $state_name,
            $encoded_payload,
            $now,
            $now
        )
    );

    if (1 === (int) $inserted) {
        return array(
            'success'  => true,
            'created'  => true,
            'conflict' => false,
            'reason'   => 'created',
            'state'    => array(
                'name'      => $state_name,
                'payload'   => $payload,
                'revision'  => 1,
                'updatedAt' => $now,
            ),
        );
    }

    $current = ultracache_get_state_record($state_name);
    if (!empty($current)) {
        return array(
            'success'  => false,
            'created'  => false,
            'conflict' => true,
            'reason'   => 'already_exists',
            'state'    => $current,
        );
    }

    return array(
        'success'  => false,
        'created'  => false,
        'conflict' => false,
        'reason'   => 'write_failed',
        'state'    => array(),
    );
}

/**
 * Replace a state payload only when its revision is still current.
 *
 * @param string $state_name       State name.
 * @param int    $expected_revision Expected current revision.
 * @param array  $payload          Replacement payload.
 * @return array
 */
function ultracache_compare_and_swap_state_record($state_name, $expected_revision, array $payload)
{
    global $wpdb;

    $state_name = ultracache_normalize_state_name($state_name);
    $expected_revision = max(0, (int) $expected_revision);
    if ('' === $state_name || !($wpdb instanceof wpdb) || !ultracache_ensure_locks_table()) {
        return array(
            'success'  => false,
            'conflict' => false,
            'reason'   => 'storage_unavailable',
            'state'    => array(),
        );
    }

    $table = ultracache_get_locks_table_name();
    $now = time();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Revision-guarded UPDATE is the atomic CAS primitive for UltraCache state.
    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE %i SET payload = %s, revision = revision + 1, updated_at = %d WHERE lock_name = %s AND record_type = 'state' AND revision = %d",
            $table,
            ultracache_encode_lock_payload($payload),
            $now,
            $state_name,
            $expected_revision
        )
    );

    if (1 === (int) $updated) {
        return array(
            'success'  => true,
            'conflict' => false,
            'reason'   => 'committed',
            'state'    => array(
                'name'      => $state_name,
                'payload'   => $payload,
                'revision'  => $expected_revision + 1,
                'updatedAt' => $now,
            ),
        );
    }

    $current = ultracache_get_state_record($state_name);
    return array(
        'success'  => false,
        'conflict' => !empty($current),
        'reason'   => empty($current) ? 'missing' : 'stale_revision',
        'state'    => $current,
    );
}

/**
 * Apply a bounded read/mutate/CAS loop to one state record.
 *
 * The mutator receives the current payload, the complete current record, and
 * the one-based attempt number. It must return the complete replacement
 * payload array. Returning a non-array aborts the mutation explicitly.
 *
 * @param string   $state_name      State name.
 * @param callable $mutator         Payload mutator.
 * @param int      $max_attempts     Maximum create/CAS attempts.
 * @param array    $initial_payload Payload used when the row does not exist.
 * @return array
 */
function ultracache_mutate_state_record($state_name, callable $mutator, $max_attempts = 3, array $initial_payload = array())
{
    $state_name = ultracache_normalize_state_name($state_name);
    $max_attempts = max(1, min(10, (int) $max_attempts));
    if ('' === $state_name) {
        return array(
            'success'  => false,
            'conflict' => false,
            'attempts' => 0,
            'reason'   => 'invalid_name',
            'state'    => array(),
        );
    }

    for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
        $current = ultracache_get_state_record($state_name);
        $payload = !empty($current) ? (array) ($current['payload'] ?? array()) : $initial_payload;

        try {
            $replacement = call_user_func($mutator, $payload, $current, $attempt);
        } catch (Throwable $error) {
            ultracache_debug_log(
                'UltraCache state mutator failed',
                array(
                    'state' => $state_name,
                    'error' => $error->getMessage(),
                )
            );
            return array(
                'success'  => false,
                'conflict' => false,
                'attempts' => $attempt,
                'reason'   => 'mutator_exception',
                'state'    => $current,
            );
        }

        if (!is_array($replacement)) {
            return array(
                'success'  => false,
                'conflict' => false,
                'attempts' => $attempt,
                'reason'   => 'mutator_rejected',
                'state'    => $current,
            );
        }

        $result = empty($current)
            ? ultracache_create_state_record($state_name, $replacement)
            : ultracache_compare_and_swap_state_record(
                $state_name,
                (int) ($current['revision'] ?? 0),
                $replacement
            );

        if (!empty($result['success'])) {
            $result['attempts'] = $attempt;
            return $result;
        }

        if (empty($result['conflict'])) {
            $result['attempts'] = $attempt;
            return $result;
        }
    }

    return array(
        'success'  => false,
        'conflict' => true,
        'attempts' => $max_attempts,
        'reason'   => 'conflict_exhausted',
        'state'    => ultracache_get_state_record($state_name),
    );
}

/**
 * Delete one specifically named persistent state record.
 *
 * @param string $state_name State name.
 * @return bool
 */
function ultracache_delete_state_record($state_name)
{
    global $wpdb;

    $state_name = ultracache_normalize_state_name($state_name);
    if ('' === $state_name || !($wpdb instanceof wpdb) || !ultracache_ensure_locks_table()) {
        return false;
    }

    $table = ultracache_get_locks_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Named deletion is restricted to one UltraCache state row.
    $deleted = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM %i WHERE lock_name = %s AND record_type = 'state'",
            $table,
            $state_name
        )
    );

    return false !== $deleted;
}

/**
 * Read only the explicitly requested state records.
 *
 * @param array $state_names State names.
 * @return array
 */
function ultracache_get_state_records(array $state_names)
{
    $records = array();
    $normalized = array();

    foreach (array_slice($state_names, 0, 50) as $state_name) {
        $state_name = ultracache_normalize_state_name($state_name);
        if ('' !== $state_name) {
            $normalized[$state_name] = true;
        }
    }

    foreach (array_keys($normalized) as $state_name) {
        $record = ultracache_get_state_record($state_name);
        if (!empty($record)) {
            $records[$state_name] = $record;
        }
    }

    return $records;
}


/**
 * Clear persisted per-file JavaScript analysis evidence.
 *
 * The cache stores only extracted listener/emitter metadata in the shared
 * UltraCache coordination state table. Source JavaScript bodies are never
 * persisted.
 *
 * @return int|false Number of deleted rows or false when storage is unavailable.
 */
function ultracache_clear_js_analysis_cache()
{
    if (!function_exists('ultracache_delete_coordination_records_by_prefix')) {
        return false;
    }

    return ultracache_delete_coordination_records_by_prefix(
        'state',
        'ultracache_state:js-analysis.'
    );
}
