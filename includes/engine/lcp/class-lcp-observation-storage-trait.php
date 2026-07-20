<?php
/**
 * Persistent browser-observed LCP storage.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_LCP_Observation_Storage_Trait
{
    public static function get_lcp_observations_db_version()
    {
        return '3';
    }

    public static function get_lcp_observations_db_version_option_key()
    {
        return 'ultracache_lcp_observations_db_version';
    }

    public static function get_lcp_observations_table_name()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return '';
        }

        $table = $wpdb->prefix . 'ultracache_lcp_observations';
        return function_exists('ultracache_validate_custom_table_name')
            ? ultracache_validate_custom_table_name($table, 'lcp_observations')
            : $table;
    }

    public static function lcp_observations_table_exists()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return false;
        }

        $table = self::get_lcp_observations_table_name();
        if ('' === $table) {
            return false;
        }

        $cache_key = 'ultracache_lcp_observations_table_exists_' . md5($table);
        $found = false;
        $cached = wp_cache_get($cache_key, 'ultracache', false, $found);
        if ($found && is_bool($cached)) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Schema existence check for an UltraCache-owned custom table; cached below.
        $found_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $exists = (string) $found_table === (string) $table;
        wp_cache_set($cache_key, $exists, 'ultracache', HOUR_IN_SECONDS);

        return $exists;
    }

    private static function invalidate_lcp_observations_table_exists_cache()
    {
        $table = self::get_lcp_observations_table_name();
        if ('' !== $table) {
            wp_cache_delete('ultracache_lcp_observations_table_exists_' . md5($table), 'ultracache');
        }
    }

    public static function ensure_lcp_observations_table($force_check = false)
    {
        global $wpdb;
        static $ensured = null;

        if ($force_check) {
            $ensured = null;
            self::invalidate_lcp_observations_table_exists_cache();
        }

        if (true === $ensured) {
            return true;
        }

        if ((defined('ULTRACACHE_UNINSTALL_IN_PROGRESS') && ULTRACACHE_UNINSTALL_IN_PROGRESS) || !($wpdb instanceof wpdb)) {
            return false;
        }

        $table = self::get_lcp_observations_table_name();
        if ('' === $table) {
            return false;
        }

        $version = (string) get_option(self::get_lcp_observations_db_version_option_key(), '');
        if (self::get_lcp_observations_db_version() === $version && self::lcp_observations_table_exists()) {
            $ensured = true;
            return true;
        }

        if (!ultracache_require_wordpress_admin_include('upgrade.php', 'dbDelta')) {
            return false;
        }

        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            page_url_hash char(64) NOT NULL DEFAULT '',
            page_url text NOT NULL,
            selector_hash char(64) NOT NULL DEFAULT '',
            selector varchar(512) NOT NULL DEFAULT '',
            viewport varchar(12) NOT NULL DEFAULT '',
            element_tag varchar(80) NOT NULL DEFAULT '',
            resource_type varchar(20) NOT NULL DEFAULT 'unknown',
            resource_url_hash char(64) NOT NULL DEFAULT '',
            resource_url text NULL,
            observation_source varchar(32) NOT NULL DEFAULT 'browser',
            observation_count bigint(20) unsigned NOT NULL DEFAULT 1,
            status varchar(20) NOT NULL DEFAULT 'confirmed',
            learning_state varchar(20) NOT NULL DEFAULT 'locked',
            confirmation_count bigint(20) unsigned NOT NULL DEFAULT 2,
            candidate_window longtext NULL,
            locked_at bigint(20) unsigned NOT NULL DEFAULT 0,
            last_refresh_at bigint(20) unsigned NOT NULL DEFAULT 0,
            first_seen bigint(20) unsigned NOT NULL DEFAULT 0,
            last_seen bigint(20) unsigned NOT NULL DEFAULT 0,
            confirmed_at bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY page_selector_viewport (page_url_hash, selector_hash, viewport),
            KEY page_viewport (page_url_hash, viewport),
            KEY status_last_seen (status, last_seen),
            KEY updated_id (updated_at, id),
            KEY status_updated (status, updated_at, id),
            KEY learning_state_updated (learning_state, updated_at, id),
            KEY source_updated (observation_source, updated_at, id),
            KEY viewport_updated (viewport, updated_at, id),
            KEY resource_url_hash (resource_url_hash)
        ) {$charset_collate};";

        dbDelta($sql);
        self::invalidate_lcp_observations_table_exists_cache();

        if (self::lcp_observations_table_exists()) {
            if ('3' !== $version) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Additive migration preserves existing confirmed mappings as locked winners.
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE %i SET learning_state = %s, confirmation_count = GREATEST(confirmation_count, 2), locked_at = CASE WHEN locked_at > 0 THEN locked_at WHEN confirmed_at > 0 THEN confirmed_at ELSE updated_at END WHERE status = %s",
                        $table,
                        'locked',
                        'confirmed'
                    )
                );
                self::cleanup_legacy_lcp_learning_transients();
            }
            update_option(self::get_lcp_observations_db_version_option_key(), self::get_lcp_observations_db_version(), false);
            $ensured = true;
            return true;
        }

        return false;
    }

    private static function cleanup_legacy_lcp_learning_transients()
    {
        global $wpdb;
        if (!($wpdb instanceof wpdb)) {
            return;
        }

        $prefixes = array(
            'ultracache_lcp_learning_session_',
            'ultracache_lcp_learning_authority_',
            'ultracache_lcp_learning_scope_',
            'ultracache_lcp_pending_candidate_',
            'ultracache_lcp_refresh_cooldown_',
            'ultracache_lcp_obs_rate_',
        );
        foreach ($prefixes as $prefix) {
            foreach (array('_transient_', '_transient_timeout_') as $transient_prefix) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema migration removes only known legacy UltraCache LCP transient prefixes.
                $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $transient_prefix . $wpdb->esc_like($prefix) . '%'));
            }
        }
    }

    private function get_stale_lcp_observation_retention_seconds()
    {
        return (int) apply_filters('ultracache_stale_lcp_observation_retention_seconds', 30 * DAY_IN_SECONDS);
    }

    private function get_pending_lcp_observation_retention_seconds()
    {
        return (int) apply_filters('ultracache_pending_lcp_observation_retention_seconds', 30 * DAY_IN_SECONDS);
    }

    private function get_lcp_observation_page_hash($page_url)
    {
        $page_url = $this->normalize_lcp_observation_page_url($page_url);
        return '' === $page_url ? '' : hash('sha256', $page_url);
    }

    /**
     * Read one exact LCP observation row with the prepared statement at the database sink.
     *
     * @param string $table          Validated UltraCache observations table.
     * @param string $page_url_hash  Normalized page URL hash.
     * @param string $page_url       Normalized page URL.
     * @param string $selector_hash  Validated selector hash.
     * @param string $viewport       Validated viewport.
     * @return array<string,mixed>|null
     */
    private function select_lcp_observation_row_from_db($table, $page_url_hash, $page_url, $selector_hash, $viewport)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads one UltraCache-owned LCP mapping row for comparison before an atomic upsert.
        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT page_url, selector_hash, selector, viewport, element_tag, resource_type, resource_url, observation_source, observation_count, status, learning_state, confirmation_count, candidate_window, locked_at, last_refresh_at, first_seen, last_seen, confirmed_at, updated_at FROM %i WHERE page_url_hash = %s AND page_url = %s AND selector_hash = %s AND viewport = %s LIMIT 1',
                $table,
                $page_url_hash,
                $page_url,
                $selector_hash,
                $viewport
            ),
            ARRAY_A
        );
    }

    private function get_lcp_observation_row($page_url, $selector_hash, $viewport)
    {
        $page_url = $this->normalize_lcp_observation_page_url($page_url);
        $page_url_hash = $this->get_lcp_observation_page_hash($page_url);
        $selector_hash = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $selector_hash));
        $viewport = sanitize_key((string) $viewport);

        if (
            '' === $page_url
            || !$this->is_lcp_observation_page_cacheable_url($page_url)
            || 64 !== strlen($page_url_hash)
            || 64 !== strlen($selector_hash)
            || !in_array($viewport, array('mobile', 'tablet', 'desktop'), true)
            || !self::ensure_lcp_observations_table()
        ) {
            return array();
        }

        $table = self::get_lcp_observations_table_name();
        $row = $this->select_lcp_observation_row_from_db($table, $page_url_hash, $page_url, $selector_hash, $viewport);
        if (!is_array($row) && self::has_lcp_observation_database_error() && self::ensure_lcp_observations_table(true)) {
            $row = $this->select_lcp_observation_row_from_db($table, $page_url_hash, $page_url, $selector_hash, $viewport);
        }

        return is_array($row) ? $row : array();
    }

    /**
     * Read confirmed mappings for one page and retain only configured manual selectors.
     *
     * The database query uses a fixed prepared template. Selector hashes are already
     * validated by the caller and are matched in PHP so Plugin Check can verify the
     * complete prepared statement without a generated IN clause.
     *
     * @param string            $table           Validated observations table.
     * @param string            $page_url_hash   Normalized page URL hash.
     * @param string            $page_url        Normalized page URL.
     * @param array<int,string> $selector_hashes Validated selector hashes.
     * @return array<int,array<string,mixed>>|null
     */
    private function select_lcp_observation_records_for_page_from_db($table, $page_url_hash, $page_url, array $selector_hashes)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the bounded set of confirmed UltraCache-owned LCP mappings for one exact page before matching configured manual selector hashes in PHP.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, page_url, selector_hash, selector, viewport, element_tag, resource_type, resource_url, observation_source, observation_count, status, learning_state, confirmation_count, candidate_window, locked_at, last_refresh_at, first_seen, last_seen, confirmed_at, updated_at FROM %i WHERE page_url_hash = %s AND page_url = %s AND status = %s ORDER BY confirmed_at DESC, updated_at DESC, observation_count DESC, id DESC',
                $table,
                $page_url_hash,
                $page_url,
                'confirmed'
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return null;
        }

        $allowed_selector_hashes = array_fill_keys($selector_hashes, true);

        return array_values(array_filter($rows, static function ($row) use ($allowed_selector_hashes) {
            $selector_hash = is_array($row) ? strtolower((string) ($row['selector_hash'] ?? '')) : '';
            return isset($allowed_selector_hashes[$selector_hash]);
        }));
    }

    private function get_lcp_observation_records_for_page_from_db($page_url, array $selector_hashes)
    {
        $page_url = $this->normalize_lcp_observation_page_url($page_url);
        $page_url_hash = $this->get_lcp_observation_page_hash($page_url);
        $selector_hashes = array_values(array_unique(array_filter(array_map(static function ($hash) {
            $hash = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $hash));
            return 64 === strlen($hash) ? $hash : '';
        }, $selector_hashes))));

        if ('' === $page_url || !$this->is_lcp_observation_page_cacheable_url($page_url) || 64 !== strlen($page_url_hash) || empty($selector_hashes) || !self::ensure_lcp_observations_table()) {
            return array();
        }

        $table = self::get_lcp_observations_table_name();
        $rows = $this->select_lcp_observation_records_for_page_from_db($table, $page_url_hash, $page_url, $selector_hashes);
        if (!is_array($rows) && self::has_lcp_observation_database_error() && self::ensure_lcp_observations_table(true)) {
            $rows = $this->select_lcp_observation_records_for_page_from_db($table, $page_url_hash, $page_url, $selector_hashes);
        }

        return is_array($rows) ? $rows : array();
    }

    /**
     * Read confirmed automatic mappings for one page.
     *
     * @param string $table         Validated observations table.
     * @param string $page_url_hash Normalized page URL hash.
     * @param string $page_url      Normalized page URL.
     * @return array<int,array<string,mixed>>|null
     */
    private function select_automatic_lcp_observation_records_from_db($table, $page_url_hash, $page_url)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads confirmed automatic UltraCache LCP mappings for one page.
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, page_url, selector_hash, selector, viewport, element_tag, resource_type, resource_url, observation_source, observation_count, status, learning_state, confirmation_count, candidate_window, locked_at, last_refresh_at, first_seen, last_seen, confirmed_at, updated_at FROM %i WHERE page_url_hash = %s AND page_url = %s AND observation_source = %s AND status = %s ORDER BY confirmed_at DESC, updated_at DESC, observation_count DESC, id DESC',
                $table,
                $page_url_hash,
                $page_url,
                'automatic',
                'confirmed'
            ),
            ARRAY_A
        );
    }

    private function get_automatic_lcp_observation_records_for_page_from_db($page_url)
    {
        $page_url = $this->normalize_lcp_observation_page_url($page_url);
        $page_url_hash = $this->get_lcp_observation_page_hash($page_url);
        if ('' === $page_url || !$this->is_lcp_observation_page_cacheable_url($page_url) || 64 !== strlen($page_url_hash) || !self::ensure_lcp_observations_table()) {
            return array();
        }

        $table = self::get_lcp_observations_table_name();
        $rows = $this->select_automatic_lcp_observation_records_from_db($table, $page_url_hash, $page_url);
        if (!is_array($rows) && self::has_lcp_observation_database_error() && self::ensure_lcp_observations_table(true)) {
            $rows = $this->select_automatic_lcp_observation_records_from_db($table, $page_url_hash, $page_url);
        }

        return is_array($rows) ? $rows : array();
    }

    /**
     * Read the active winner for one page and viewport.
     *
     * @param string $table         Validated observations table.
     * @param string $page_url_hash Normalized page URL hash.
     * @param string $page_url      Normalized page URL.
     * @param string $viewport      Validated viewport.
     * @return array<string,mixed>|null
     */
    private function select_confirmed_lcp_observation_winner_from_db($table, $page_url_hash, $page_url, $viewport)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the active UltraCache LCP winner for one page and viewport.
        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT page_url, selector_hash, selector, viewport, element_tag, resource_type, resource_url, observation_source, observation_count, status, learning_state, confirmation_count, candidate_window, locked_at, last_refresh_at, first_seen, last_seen, confirmed_at, updated_at FROM %i WHERE page_url_hash = %s AND page_url = %s AND viewport = %s AND status = %s ORDER BY confirmed_at DESC, updated_at DESC, observation_count DESC, id DESC LIMIT 1',
                $table,
                $page_url_hash,
                $page_url,
                $viewport,
                'confirmed'
            ),
            ARRAY_A
        );
    }

    private function get_confirmed_lcp_observation_winner_for_page_viewport($page_url, $viewport)
    {
        $page_url = $this->normalize_lcp_observation_page_url($page_url);
        $page_url_hash = $this->get_lcp_observation_page_hash($page_url);
        $viewport = sanitize_key((string) $viewport);
        if (
            '' === $page_url
            || !$this->is_lcp_observation_page_cacheable_url($page_url)
            || 64 !== strlen($page_url_hash)
            || !in_array($viewport, array('mobile', 'tablet', 'desktop'), true)
            || !self::ensure_lcp_observations_table()
        ) {
            return array();
        }

        $table = self::get_lcp_observations_table_name();
        $row = $this->select_confirmed_lcp_observation_winner_from_db($table, $page_url_hash, $page_url, $viewport);
        if (!is_array($row) && self::has_lcp_observation_database_error() && self::ensure_lcp_observations_table(true)) {
            $row = $this->select_confirmed_lcp_observation_winner_from_db($table, $page_url_hash, $page_url, $viewport);
        }

        return is_array($row) ? $row : array();
    }

    /**
     * Report whether the previous LCP database operation failed.
     *
     * @return bool
     */
    private static function has_lcp_observation_database_error()
    {
        global $wpdb;

        return $wpdb instanceof wpdb && '' !== (string) $wpdb->last_error;
    }

    /**
     * Execute the atomic LCP observation upsert.
     */
    private function execute_lcp_observation_upsert($table, array $record)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic upsert into the UltraCache-owned persistent LCP mapping table.
        return $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO %i (
                    page_url_hash, page_url, selector_hash, selector, viewport, element_tag,
                    resource_type, resource_url_hash, resource_url, observation_source,
                    observation_count, status, learning_state, confirmation_count, candidate_window,
                    locked_at, last_refresh_at, first_seen, last_seen, confirmed_at, updated_at
                ) VALUES (
                    %s, %s, %s, %s, %s, %s,
                    %s, %s, %s, %s,
                    %d, %s, %s, %d, %s,
                    %d, %d, %d, %d, %d, %d
                ) ON DUPLICATE KEY UPDATE
                    first_seen = IF(status = %s, VALUES(first_seen), first_seen),
                    page_url = VALUES(page_url),
                    selector = VALUES(selector),
                    element_tag = VALUES(element_tag),
                    resource_type = VALUES(resource_type),
                    resource_url_hash = VALUES(resource_url_hash),
                    resource_url = VALUES(resource_url),
                    observation_source = VALUES(observation_source),
                    observation_count = VALUES(observation_count),
                    status = VALUES(status),
                    learning_state = VALUES(learning_state),
                    confirmation_count = VALUES(confirmation_count),
                    candidate_window = VALUES(candidate_window),
                    locked_at = VALUES(locked_at),
                    last_refresh_at = VALUES(last_refresh_at),
                    last_seen = VALUES(last_seen),
                    confirmed_at = VALUES(confirmed_at),
                    updated_at = VALUES(updated_at)",
                $table,
                $record['page_url_hash'],
                $record['page_url'],
                $record['selector_hash'],
                $record['selector'],
                $record['viewport'],
                $record['element_tag'],
                $record['resource_type'],
                $record['resource_url_hash'],
                $record['resource_url'],
                $record['observation_source'],
                $record['observation_count'],
                $record['status'],
                $record['learning_state'],
                $record['confirmation_count'],
                $record['candidate_window'],
                $record['locked_at'],
                $record['last_refresh_at'],
                $record['now'],
                $record['now'],
                $record['confirmed_at'],
                $record['now'],
                'stale'
            )
        );
    }

    private function stale_competing_lcp_observations($table, $now, $page_url_hash, $page_url, $viewport, $selector_hash)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Updates only competing UltraCache-owned mappings for the same page and viewport.
        return $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET status = %s, updated_at = %d WHERE page_url_hash = %s AND page_url = %s AND viewport = %s AND selector_hash <> %s AND status IN (%s, %s)',
                $table,
                'stale',
                $now,
                $page_url_hash,
                $page_url,
                $viewport,
                $selector_hash,
                'confirmed',
                'pending'
            )
        );
    }

    private function upsert_lcp_observation_row(array $record)
    {
        if (!self::ensure_lcp_observations_table()) {
            return false;
        }

        $page_url = $this->normalize_lcp_observation_page_url($record['page_url'] ?? '');
        $page_url_hash = $this->get_lcp_observation_page_hash($page_url);
        $selector_hash = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) ($record['selector_hash'] ?? '')));
        $selector = substr((string) ($record['selector'] ?? ''), 0, 512);
        $viewport = sanitize_key((string) ($record['viewport'] ?? ''));
        $element_tag = substr(sanitize_key((string) ($record['element_tag'] ?? '')), 0, 80);
        $resource_type = sanitize_key((string) ($record['resource_type'] ?? 'unknown'));
        $resource_url = esc_url_raw((string) ($record['resource_url'] ?? ''));
        $resource_url_hash = '' === $resource_url ? '' : hash('sha256', $resource_url);
        $observation_source = sanitize_key((string) ($record['observation_source'] ?? 'manual'));
        $observation_count = max(1, absint($record['observation_count'] ?? 1));
        $status = sanitize_key((string) ($record['status'] ?? 'confirmed'));
        $learning_state = sanitize_key((string) ($record['learning_state'] ?? 'locked'));
        $confirmation_count = max(0, absint($record['confirmation_count'] ?? 0));
        $candidate_window = (string) ($record['candidate_window'] ?? '[]');
        $decoded_window = json_decode($candidate_window, true);
        $candidate_window = wp_json_encode(is_array($decoded_window) ? array_slice($decoded_window, -3) : array());
        $locked_at = 'locked' === $learning_state ? max(1, absint($record['locked_at'] ?? time())) : 0;
        $last_refresh_at = absint($record['last_refresh_at'] ?? 0);
        $now = max(1, absint($record['observed_at'] ?? time()));
        $confirmed_at = 'confirmed' === $status ? max(1, absint($record['confirmed_at'] ?? $now)) : 0;

        if (
            '' === $page_url
            || !$this->is_lcp_observation_page_cacheable_url($page_url)
            || 64 !== strlen($page_url_hash)
            || 64 !== strlen($selector_hash)
            || '' === $selector
            || !in_array($viewport, array('mobile', 'tablet', 'desktop'), true)
            || !in_array($resource_type, array('text', 'image', 'background', 'poster', 'unknown'), true)
            || !in_array($observation_source, array('manual', 'automatic', 'browser'), true)
            || !in_array($status, array('pending', 'confirmed'), true)
            || !in_array($learning_state, array('learning', 'locked'), true)
        ) {
            return false;
        }

        $prepared = array(
            'page_url_hash'      => $page_url_hash,
            'page_url'           => $page_url,
            'selector_hash'      => $selector_hash,
            'selector'           => $selector,
            'viewport'           => $viewport,
            'element_tag'        => $element_tag,
            'resource_type'      => $resource_type,
            'resource_url_hash'  => $resource_url_hash,
            'resource_url'       => $resource_url,
            'observation_source' => $observation_source,
            'observation_count'  => $observation_count,
            'status'             => $status,
            'learning_state'     => $learning_state,
            'confirmation_count' => $confirmation_count,
            'candidate_window'   => $candidate_window,
            'locked_at'          => $locked_at,
            'last_refresh_at'    => $last_refresh_at,
            'now'                => $now,
            'confirmed_at'       => $confirmed_at,
        );

        $table = self::get_lcp_observations_table_name();
        $result = $this->execute_lcp_observation_upsert($table, $prepared);
        if (false === $result && self::ensure_lcp_observations_table(true)) {
            $result = $this->execute_lcp_observation_upsert($table, $prepared);
        }
        if (false === $result) {
            return false;
        }

        if ('confirmed' === $status) {
            $this->stale_competing_lcp_observations($table, $now, $page_url_hash, $page_url, $viewport, $selector_hash);
        }

        $this->maybe_cleanup_lcp_observations_table();
        return true;
    }

    private function update_lcp_observation_selector_statuses($table, $now, array $selector_hashes)
    {
        global $wpdb;

        if (empty($selector_hashes)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Synchronizes only UltraCache-owned persistent LCP mappings after an explicit settings save.
            return $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, updated_at = %d WHERE status = %s AND observation_source IN (%s, %s)',
                    $table,
                    'stale',
                    $now,
                    'confirmed',
                    'manual',
                    'browser'
                )
            );
        }

        $affected_rows = 0;

        // Automatic mappings cannot match configured manual-selector hashes. Stale all
        // confirmed automatic winners in one fixed prepared update when manual mode is active.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Synchronizes only UltraCache-owned persistent LCP mappings after an explicit settings save.
        $automatic_result = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET status = %s, updated_at = %d WHERE status = %s AND observation_source = %s',
                $table,
                'stale',
                $now,
                'confirmed',
                'automatic'
            )
        );
        if (false === $automatic_result) {
            return false;
        }
        $affected_rows += (int) $automatic_result;

        // Manual and legacy-browser selector hashes are few and stable. Read their distinct
        // hashes with a fixed query, then stale only removed hashes through fixed updates.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads UltraCache-owned selector hashes during an explicit settings synchronization.
        $stored_selector_hashes = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT DISTINCT selector_hash FROM %i WHERE status = %s AND observation_source IN (%s, %s)',
                $table,
                'confirmed',
                'manual',
                'browser'
            )
        );
        if (!is_array($stored_selector_hashes) || self::has_lcp_observation_database_error()) {
            return false;
        }

        $configured_selector_hashes = array_fill_keys($selector_hashes, true);
        foreach ($stored_selector_hashes as $stored_selector_hash) {
            $stored_selector_hash = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $stored_selector_hash));
            if (64 !== strlen($stored_selector_hash) || isset($configured_selector_hashes[$stored_selector_hash])) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Stales one removed UltraCache-owned manual selector mapping through a fixed prepared statement.
            $selector_result = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, updated_at = %d WHERE status = %s AND selector_hash = %s AND observation_source IN (%s, %s)',
                    $table,
                    'stale',
                    $now,
                    'confirmed',
                    $stored_selector_hash,
                    'manual',
                    'browser'
                )
            );
            if (false === $selector_result) {
                return false;
            }
            $affected_rows += (int) $selector_result;
        }

        return $affected_rows;
    }

    /**
     * Mark confirmed mappings for selectors removed from the current settings
     * as stale without touching still-configured selectors.
     *
     * @param array<int,string> $selectors Current manual LCP selectors.
     * @return int|false Number of affected rows, or false on failure.
     */
    public function sync_lcp_observation_selectors(array $selectors)
    {
        if (!self::ensure_lcp_observations_table()) {
            return false;
        }

        $selector_hashes = array();
        foreach ($selectors as $selector) {
            $selector_hash = $this->get_lcp_observation_selector_hash($selector);
            if (64 === strlen($selector_hash)) {
                $selector_hashes[$selector_hash] = true;
            }
        }
        $selector_hashes = array_keys($selector_hashes);
        $table = self::get_lcp_observations_table_name();
        $now = time();

        $result = $this->update_lcp_observation_selector_statuses($table, $now, $selector_hashes);
        if (false === $result && self::ensure_lcp_observations_table(true)) {
            $result = $this->update_lcp_observation_selector_statuses($table, $now, $selector_hashes);
        }

        return false === $result ? false : (int) $result;
    }

    /**
     * Normalize server-side LCP diagnostics list arguments.
     *
     * @param array<string,mixed> $query Raw list arguments.
     * @return array<string,mixed>
     */
    private function normalize_lcp_observation_diagnostics_query(array $query)
    {
        $tab = sanitize_key((string) ($query['tab'] ?? 'attention'));
        if (!in_array($tab, array('attention', 'confirmed', 'pending', 'stale', 'all'), true)) {
            $tab = 'attention';
        }

        $source = sanitize_key((string) ($query['source'] ?? 'all'));
        if (!in_array($source, array('all', 'automatic', 'manual'), true)) {
            $source = 'all';
        }

        $viewport = sanitize_key((string) ($query['viewport'] ?? 'all'));
        if (!in_array($viewport, array('all', 'mobile', 'tablet', 'desktop'), true)) {
            $viewport = 'all';
        }

        $resource_type = sanitize_key((string) ($query['resourceType'] ?? $query['resource_type'] ?? 'all'));
        if (!in_array($resource_type, array('all', 'text', 'image', 'background', 'poster', 'unknown'), true)) {
            $resource_type = 'all';
        }

        $refresh_status = sanitize_key((string) ($query['refreshStatus'] ?? $query['refresh_status'] ?? 'all'));
        if (!in_array($refresh_status, array('all', 'pending', 'done', 'error', 'none'), true)) {
            $refresh_status = 'all';
        }

        $per_page = absint($query['perPage'] ?? $query['per_page'] ?? 20);
        if (!in_array($per_page, array(20, 50, 100), true)) {
            $per_page = 20;
        }

        $search = sanitize_text_field((string) ($query['search'] ?? ''));
        if ('*' === trim($search)) {
            $search = '';
        }
        if (function_exists('mb_substr')) {
            $search = mb_substr($search, 0, 200);
        } else {
            $search = substr($search, 0, 200);
        }

        $cursor = sanitize_text_field((string) ($query['cursor'] ?? ''));
        $cursor_values = $this->decode_lcp_observation_diagnostics_cursor($cursor);

        return array(
            'tab'           => $tab,
            'source'        => $source,
            'viewport'      => $viewport,
            'resourceType'  => $resource_type,
            'refreshStatus' => $refresh_status,
            'search'        => $search,
            'perPage'       => $per_page,
            'cursor'        => $cursor_values ? $cursor : '',
            'cursorValues'  => $cursor_values,
        );
    }

    /**
     * Decode a stable updated_at/id cursor used by the LCP diagnostics list.
     *
     * @param string $cursor Encoded cursor.
     * @return array<string,int>
     */
    private function decode_lcp_observation_diagnostics_cursor($cursor)
    {
        $cursor = trim((string) $cursor);
        if ('' === $cursor || strlen($cursor) > 180) {
            return array();
        }

        $padding = strlen($cursor) % 4;
        if ($padding) {
            $cursor .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!is_string($decoded) || '' === $decoded) {
            return array();
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            return array();
        }

        $updated_at = absint($payload['updatedAt'] ?? 0);
        $id = absint($payload['id'] ?? 0);
        if ($updated_at < 1 || $id < 1) {
            return array();
        }

        return array('updatedAt' => $updated_at, 'id' => $id);
    }

    /**
     * Encode an updated_at/id cursor for the next LCP diagnostics page.
     *
     * @param array<string,mixed> $row Last returned database row.
     * @return string
     */
    private function encode_lcp_observation_diagnostics_cursor(array $row)
    {
        $updated_at = absint($row['updated_at'] ?? 0);
        $id = absint($row['id'] ?? 0);
        if ($updated_at < 1 || $id < 1) {
            return '';
        }

        $encoded = base64_encode(wp_json_encode(array('updatedAt' => $updated_at, 'id' => $id)));
        return rtrim(strtr($encoded, '+/', '-_'), '=');
    }

    /**
     * Return the validated cron-warm queue table when it exists.
     *
     * @return string
     */
    private function get_lcp_diagnostics_queue_table()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return '';
        }

        $queue_table = function_exists('ultracache_validate_custom_table_name')
            ? ultracache_validate_custom_table_name($wpdb->prefix . 'ultracache_cron_warm_queue', 'cron_warm_queue')
            : $wpdb->prefix . 'ultracache_cron_warm_queue';
        if ('' === $queue_table) {
            return '';
        }

        $cache_key = 'ultracache_cron_warm_queue_table_exists_' . md5((string) $queue_table);
        $found = false;
        $cached = wp_cache_get($cache_key, 'ultracache', false, $found);
        if ($found && is_bool($cached)) {
            return $cached ? $queue_table : '';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Schema existence check for an UltraCache-owned custom table; cached below.
        $found_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $queue_table));
        $exists = (string) $found_table === (string) $queue_table;
        wp_cache_set($cache_key, $exists, 'ultracache', HOUR_IN_SECONDS);

        return $exists ? $queue_table : '';
    }

    /**
     * Normalize the fixed SQL gate values used by the LCP diagnostics queries.
     *
     * Query structure remains static at every database sink. Only allowlisted
     * values and escaped LIKE terms are bound through wpdb::prepare().
     *
     * @param array<string,mixed> $query       Normalized diagnostics query.
     * @param bool                $with_cursor Whether to apply the cursor boundary.
     * @return array<string,mixed>
     */
    private function get_lcp_observation_diagnostics_filter_state(array $query, $with_cursor)
    {
        global $wpdb;

        $tab = (string) $query['tab'];
        $tab_status = in_array($tab, array('confirmed', 'pending', 'stale'), true) ? $tab : '';
        $source = (string) $query['source'];
        $viewport = (string) $query['viewport'];
        $resource_type = (string) $query['resourceType'];
        $refresh_status = (string) $query['refreshStatus'];
        $refresh_match = in_array($refresh_status, array('pending', 'done', 'error'), true) ? $refresh_status : '';
        $search = (string) $query['search'];
        $like = '' === $search ? '' : '%' . $wpdb->esc_like($search) . '%';
        $cursor_values = $with_cursor && !empty($query['cursorValues'])
            ? (array) $query['cursorValues']
            : array();
        $cursor_updated_at = absint($cursor_values['updatedAt'] ?? 0);
        $cursor_id = absint($cursor_values['id'] ?? 0);
        $cursor_enabled = $cursor_updated_at > 0 && $cursor_id > 0;

        return array(
            'tabAll'                  => 'all' === $tab ? 1 : 0,
            'tabAttention'            => 'attention' === $tab ? 1 : 0,
            'tabStatus'               => $tab_status,
            'sourceAll'               => 'all' === $source ? 1 : 0,
            'source'                  => $source,
            'viewportAll'             => 'all' === $viewport ? 1 : 0,
            'viewport'                => $viewport,
            'resourceTypeAll'         => 'all' === $resource_type ? 1 : 0,
            'resourceType'            => $resource_type,
            'refreshAll'              => 'all' === $refresh_status ? 1 : 0,
            'refreshNone'             => 'none' === $refresh_status ? 1 : 0,
            'refreshMatch'            => $refresh_match,
            'refreshWithoutQueue'     => in_array($refresh_status, array('all', 'none'), true) ? 1 : 0,
            'searchEmpty'             => '' === $search ? 1 : 0,
            'searchLike'              => $like,
            'cursorDisabled'          => $cursor_enabled ? 0 : 1,
            'cursorUpdatedAt'         => $cursor_updated_at,
            'cursorId'                => $cursor_id,
        );
    }


    /**
     * Normalize the compact URL-grouped LCP diagnostics query.
     *
     * @param array<string,mixed> $query Raw query.
     * @return array<string,mixed>
     */
    private function normalize_lcp_observation_url_list_query(array $query)
    {
        $search = sanitize_text_field((string) ($query['search'] ?? ''));
        if ('*' === trim($search)) {
            $search = '';
        }
        if (function_exists('mb_substr')) {
            $search = mb_substr($search, 0, 200);
        } else {
            $search = substr($search, 0, 200);
        }

        $cursor = sanitize_text_field((string) ($query['cursor'] ?? ''));
        $cursor_values = $this->decode_lcp_observation_url_cursor($cursor);

        return array(
            'search'         => $search,
            'cursor'         => $cursor_values ? $cursor : '',
            'cursorValues'   => $cursor_values,
            'includeSummary' => !array_key_exists('includeSummary', $query) || !empty($query['includeSummary']),
            'perPage'        => 10,
        );
    }

    /**
     * Decode a compact URL-list cursor.
     *
     * @param string $cursor Encoded cursor.
     * @return array<string,mixed>
     */
    private function decode_lcp_observation_url_cursor($cursor)
    {
        $cursor = trim((string) $cursor);
        if ('' === $cursor || strlen($cursor) > 180) {
            return array();
        }

        $padding = strlen($cursor) % 4;
        if ($padding) {
            $cursor .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!is_string($decoded) || '' === $decoded) {
            return array();
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            return array();
        }

        $updated_at = absint($payload['updatedAt'] ?? 0);
        $page_hash = strtolower(trim((string) ($payload['pageHash'] ?? '')));
        if ($updated_at < 1 || !preg_match('/^[a-f0-9]{64}$/', $page_hash)) {
            return array();
        }

        return array(
            'updatedAt' => $updated_at,
            'pageHash'  => $page_hash,
        );
    }

    /**
     * Encode a compact URL-list cursor.
     *
     * @param array<string,mixed> $row Last URL-group row.
     * @return string
     */
    private function encode_lcp_observation_url_cursor(array $row)
    {
        $updated_at = absint($row['updated_at'] ?? 0);
        $page_hash = strtolower(trim((string) ($row['page_url_hash'] ?? '')));
        if ($updated_at < 1 || !preg_match('/^[a-f0-9]{64}$/', $page_hash)) {
            return '';
        }

        $encoded = base64_encode(wp_json_encode(array(
            'updatedAt' => $updated_at,
            'pageHash'  => $page_hash,
        )));
        return rtrim(strtr($encoded, '+/', '-_'), '=');
    }

    /**
     * Return the lightweight LCP diagnostics summary.
     *
     * @param string $table       LCP observations table.
     * @param string $queue_table Warm queue table, when available.
     * @return array<string,mixed>
     */
    private function get_lcp_observation_compact_summary($table, $queue_table)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One aggregate over the UltraCache-owned diagnostics table, loaded only after the accordion opens.
        $summary_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(DISTINCT CASE WHEN status = %s THEN page_url_hash ELSE NULL END) AS learned_pages,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS confirmed_mappings,
                    SUM(CASE WHEN status = %s AND learning_state = %s THEN 1 ELSE 0 END) AS learning_mappings,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS stale_mappings,
                    MAX(last_seen) AS last_observation_at
                FROM %i",
                'confirmed',
                'confirmed',
                'confirmed',
                'learning',
                'stale',
                $table
            ),
            ARRAY_A
        );
        $summary_row = is_array($summary_row) ? $summary_row : array();

        $pending_refreshes = 0;
        $failed_refreshes = 0;
        if ('' !== $queue_table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One aggregate over UltraCache-owned LCP refresh jobs.
            $queue_summary = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS pending_refreshes, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS failed_refreshes FROM %i WHERE job_type = %s',
                    'pending',
                    'error',
                    $queue_table,
                    'lcp_refresh'
                ),
                ARRAY_A
            );
            $queue_summary = is_array($queue_summary) ? $queue_summary : array();
            $pending_refreshes = absint($queue_summary['pending_refreshes'] ?? 0);
            $failed_refreshes = absint($queue_summary['failed_refreshes'] ?? 0);
        }

        $last_successful_refresh = get_option('ultracache_lcp_last_refresh', array());
        $last_successful_refresh = is_array($last_successful_refresh) ? $last_successful_refresh : array();

        return array(
            'learnedPages'          => absint($summary_row['learned_pages'] ?? 0),
            'confirmedMappings'     => absint($summary_row['confirmed_mappings'] ?? 0),
            'learningMappings'      => absint($summary_row['learning_mappings'] ?? 0),
            'pendingRefreshes'      => max(0, $pending_refreshes),
            'failedRefreshes'       => max(0, $failed_refreshes),
            'staleMappings'         => absint($summary_row['stale_mappings'] ?? 0),
            'lastObservationAt'     => absint($summary_row['last_observation_at'] ?? 0),
            'lastSuccessfulRefresh' => array(
                'url'       => esc_url_raw((string) ($last_successful_refresh['url'] ?? '')),
                'timestamp' => absint($last_successful_refresh['timestamp'] ?? 0),
                'message'   => sanitize_text_field((string) ($last_successful_refresh['message'] ?? '')),
            ),
        );
    }

    /**
     * Return ten compact URL groups without loading mapping details.
     *
     * @param array<string,mixed> $query Query.
     * @return array<string,mixed>
     */
    public function get_lcp_observation_diagnostics_list_snapshot($query = array())
    {
        global $wpdb;

        $query = $this->normalize_lcp_observation_url_list_query(is_array($query) ? $query : array());
        $fallback = array(
            'available' => false,
            'message'   => __('The LCP observations table is not available.', 'ultracache'),
            'summary'   => array(
                'learnedPages'          => 0,
                'confirmedMappings'     => 0,
                'learningMappings'      => 0,
                'pendingRefreshes'      => 0,
                'failedRefreshes'       => 0,
                'staleMappings'         => 0,
                'lastObservationAt'     => 0,
                'lastSuccessfulRefresh' => array(),
            ),
            'urls'       => array(),
            'query'      => array(
                'search'         => (string) $query['search'],
                'cursor'         => (string) $query['cursor'],
                'includeSummary' => !empty($query['includeSummary']),
            ),
            'pagination' => array(
                'perPage'    => 10,
                'returned'   => 0,
                'hasMore'    => false,
                'nextCursor' => '',
            ),
        );

        if (!($wpdb instanceof wpdb) || !self::ensure_lcp_observations_table()) {
            return $fallback;
        }

        $table = self::get_lcp_observations_table_name();
        if ('' === $table) {
            return $fallback;
        }

        $queue_table = $this->get_lcp_diagnostics_queue_table();
        $summary = !empty($query['includeSummary'])
            ? $this->get_lcp_observation_compact_summary($table, $queue_table)
            : null;
        $search = (string) $query['search'];
        $search_empty = '' === $search ? 1 : 0;
        $search_like = '' === $search ? '' : '%' . $wpdb->esc_like($search) . '%';
        $cursor_values = (array) ($query['cursorValues'] ?? array());
        $cursor_updated_at = absint($cursor_values['updatedAt'] ?? 0);
        $cursor_page_hash = strtolower((string) ($cursor_values['pageHash'] ?? ''));
        $cursor_disabled = ($cursor_updated_at > 0 && preg_match('/^[a-f0-9]{64}$/', $cursor_page_hash)) ? 0 : 1;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Returns at most eleven compact URL groups for cursor pagination.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    page_url_hash,
                    MAX(page_url) AS page_url,
                    MAX(updated_at) AS updated_at,
                    MAX(last_seen) AS last_seen,
                    COUNT(*) AS mapping_count,
                    SUM(CASE WHEN status = 'confirmed' AND learning_state = 'locked' THEN 1 ELSE 0 END) AS locked_count,
                    SUM(CASE WHEN status = 'confirmed' AND learning_state = 'learning' THEN 1 ELSE 0 END) AS learning_count,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status = 'stale' THEN 1 ELSE 0 END) AS stale_count
                FROM %i
                WHERE (%d = 1 OR page_url LIKE %s)
                GROUP BY page_url_hash
                HAVING (%d = 1 OR updated_at < %d OR (updated_at = %d AND page_url_hash < %s))
                ORDER BY updated_at DESC, page_url_hash DESC
                LIMIT %d",
                $table,
                $search_empty,
                $search_like,
                $cursor_disabled,
                $cursor_updated_at,
                $cursor_updated_at,
                $cursor_page_hash,
                11
            ),
            ARRAY_A
        );
        $rows = is_array($rows) ? $rows : array();

        $has_more = count($rows) > 10;
        if ($has_more) {
            array_pop($rows);
        }

        $viewport_rows = array();
        $hashes = array_values(array_filter(array_map(
            static function ($row) {
                $hash = strtolower(trim((string) ($row['page_url_hash'] ?? '')));
                return preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : '';
            },
            $rows
        )));
        if ($hashes) {
            $hashes = array_slice($hashes, 0, 10);
            $hashes = array_pad($hashes, 10, str_repeat('0', 64));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Loads compact states only for the ten visible URL groups.
            $viewport_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT page_url_hash, viewport, status, learning_state, confirmation_count
                    FROM %i
                    WHERE page_url_hash IN (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    ORDER BY page_url_hash ASC, FIELD(viewport, 'mobile', 'tablet', 'desktop') ASC, id ASC",
                    $table,
                    $hashes[0],
                    $hashes[1],
                    $hashes[2],
                    $hashes[3],
                    $hashes[4],
                    $hashes[5],
                    $hashes[6],
                    $hashes[7],
                    $hashes[8],
                    $hashes[9]
                ),
                ARRAY_A
            );
            $viewport_rows = is_array($viewport_rows) ? $viewport_rows : array();
        }

        $viewport_states = array();
        foreach ($viewport_rows as $viewport_row) {
            $hash = strtolower(trim((string) ($viewport_row['page_url_hash'] ?? '')));
            $viewport = sanitize_key((string) ($viewport_row['viewport'] ?? ''));
            if (!preg_match('/^[a-f0-9]{64}$/', $hash) || !in_array($viewport, array('mobile', 'tablet', 'desktop'), true)) {
                continue;
            }

            $status = sanitize_key((string) ($viewport_row['status'] ?? ''));
            $learning_state = sanitize_key((string) ($viewport_row['learning_state'] ?? 'locked'));
            $state = 'attention';
            if ('stale' === $status) {
                $state = 'stale';
            } elseif ('confirmed' === $status && 'locked' === $learning_state) {
                $state = 'locked';
            } elseif ('confirmed' === $status && 'learning' === $learning_state) {
                $state = 'learning';
            } elseif ('pending' === $status) {
                $state = 'learning';
            }

            if (!isset($viewport_states[$hash][$viewport]) || in_array($state, array('stale', 'learning'), true)) {
                $viewport_states[$hash][$viewport] = array(
                    'state'         => $state,
                    'confirmations' => absint($viewport_row['confirmation_count'] ?? 0),
                );
            }
        }

        $urls = array();
        foreach ($rows as $row) {
            $page_hash = strtolower(trim((string) ($row['page_url_hash'] ?? '')));
            if (!preg_match('/^[a-f0-9]{64}$/', $page_hash)) {
                continue;
            }

            $mapping_count = max(0, absint($row['mapping_count'] ?? 0));
            $locked_count = max(0, absint($row['locked_count'] ?? 0));
            $learning_count = max(0, absint($row['learning_count'] ?? 0));
            $pending_count = max(0, absint($row['pending_count'] ?? 0));
            $stale_count = max(0, absint($row['stale_count'] ?? 0));
            $overall_state = 'attention';
            if ($stale_count > 0) {
                $overall_state = 'stale';
            } elseif ($learning_count > 0 || $pending_count > 0) {
                $overall_state = 'learning';
            } elseif ($mapping_count > 0 && $locked_count === $mapping_count) {
                $overall_state = 'locked';
            }

            $urls[] = array(
                'pageHash'     => $page_hash,
                'pageUrl'      => esc_url_raw((string) ($row['page_url'] ?? '')),
                'state'        => $overall_state,
                'mappingCount' => $mapping_count,
                'lastSeen'     => absint($row['last_seen'] ?? 0),
                'updatedAt'    => absint($row['updated_at'] ?? 0),
                'viewports'    => isset($viewport_states[$page_hash]) ? $viewport_states[$page_hash] : array(),
            );
        }

        $next_cursor = $has_more && !empty($rows)
            ? $this->encode_lcp_observation_url_cursor($rows[count($rows) - 1])
            : '';

        return array(
            'available' => true,
            'message'   => empty($urls)
                ? __('No LCP-discovered URLs match the current search.', 'ultracache')
                : __('Only the visible URL list is loaded. Mapping details are requested after you select a URL.', 'ultracache'),
            'summary'   => $summary,
            'urls'      => $urls,
            'query'     => array(
                'search'         => (string) $query['search'],
                'cursor'         => (string) $query['cursor'],
                'includeSummary' => !empty($query['includeSummary']),
            ),
            'pagination' => array(
                'perPage'    => 10,
                'returned'   => count($urls),
                'hasMore'    => $has_more,
                'nextCursor' => $next_cursor,
            ),
        );
    }

    /**
     * Return complete mapping details for one URL hash.
     *
     * @param string $page_hash SHA-256 page URL hash.
     * @return array<string,mixed>
     */
    public function get_lcp_observation_diagnostics_detail_snapshot($page_hash)
    {
        global $wpdb;

        $page_hash = strtolower(trim((string) $page_hash));
        $fallback = array(
            'available' => false,
            'message'   => __('The selected LCP URL is unavailable.', 'ultracache'),
            'pageHash'  => '',
            'pageUrl'   => '',
            'mappings'  => array(),
        );
        if (!preg_match('/^[a-f0-9]{64}$/', $page_hash)) {
            return $fallback;
        }
        if (!($wpdb instanceof wpdb) || !self::ensure_lcp_observations_table()) {
            return $fallback;
        }

        $table = self::get_lcp_observations_table_name();
        if ('' === $table) {
            return $fallback;
        }
        $queue_table = $this->get_lcp_diagnostics_queue_table();

        if ('' !== $queue_table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Loads details only for one administrator-selected page hash.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                        o.id,
                        o.page_url,
                        o.selector_hash,
                        o.selector,
                        o.viewport,
                        o.element_tag,
                        o.resource_type,
                        o.resource_url,
                        o.observation_source,
                        o.observation_count,
                        o.status,
                        o.learning_state,
                        o.confirmation_count,
                        o.candidate_window,
                        o.locked_at,
                        o.last_refresh_at,
                        o.first_seen,
                        o.last_seen,
                        o.confirmed_at,
                        o.updated_at,
                        COALESCE(q.status, '') AS warm_refresh_status,
                        COALESCE(q.result_message, '') AS warm_refresh_message,
                        COALESCE(q.updated_at, 0) AS warm_refresh_updated_at,
                        COALESCE(q.processed_at, 0) AS warm_refresh_processed_at
                    FROM %i AS o
                    LEFT JOIN %i AS q
                        ON q.job_type = %s
                        AND q.url_hash = SHA1(o.page_url)
                    WHERE o.page_url_hash = %s
                    ORDER BY FIELD(o.viewport, 'mobile', 'tablet', 'desktop') ASC, o.id ASC",
                    $table,
                    $queue_table,
                    'lcp_refresh',
                    $page_hash
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Loads details only for one administrator-selected page hash.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                        id,
                        page_url,
                        selector_hash,
                        selector,
                        viewport,
                        element_tag,
                        resource_type,
                        resource_url,
                        observation_source,
                        observation_count,
                        status,
                        learning_state,
                        confirmation_count,
                        candidate_window,
                        locked_at,
                        last_refresh_at,
                        first_seen,
                        last_seen,
                        confirmed_at,
                        updated_at,
                        '' AS warm_refresh_status,
                        '' AS warm_refresh_message,
                        0 AS warm_refresh_updated_at,
                        0 AS warm_refresh_processed_at
                    FROM %i
                    WHERE page_url_hash = %s
                    ORDER BY FIELD(viewport, 'mobile', 'tablet', 'desktop') ASC, id ASC",
                    $table,
                    $page_hash
                ),
                ARRAY_A
            );
        }
        $rows = is_array($rows) ? $rows : array();
        if (!$rows) {
            return $fallback;
        }

        $last_successful_refresh = get_option('ultracache_lcp_last_refresh', array());
        $last_successful_refresh = is_array($last_successful_refresh) ? $last_successful_refresh : array();
        $page_url = esc_url_raw((string) ($rows[0]['page_url'] ?? ''));
        $mappings = array();

        foreach ($rows as $row) {
            $queue_status = sanitize_key((string) ($row['warm_refresh_status'] ?? ''));
            $queue_message = sanitize_text_field((string) ($row['warm_refresh_message'] ?? ''));
            $queue_updated_at = absint($row['warm_refresh_updated_at'] ?? 0);
            $queue_processed_at = absint($row['warm_refresh_processed_at'] ?? 0);
            if (
                '' === $queue_status
                && '' !== $page_url
                && $page_url === esc_url_raw((string) ($last_successful_refresh['url'] ?? ''))
                && absint($last_successful_refresh['timestamp'] ?? 0) > 0
            ) {
                $queue_status = 'done';
                $queue_message = sanitize_text_field((string) ($last_successful_refresh['message'] ?? ''));
                $queue_updated_at = absint($last_successful_refresh['timestamp'] ?? 0);
                $queue_processed_at = absint($last_successful_refresh['timestamp'] ?? 0);
            }

            $candidate_window = json_decode((string) ($row['candidate_window'] ?? '[]'), true);
            $candidate_window = is_array($candidate_window) ? array_slice($candidate_window, -3) : array();
            $candidate_fingerprints = array();
            foreach ($candidate_window as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $fingerprint = sanitize_text_field((string) ($candidate['fingerprint'] ?? ''));
                if ('' !== $fingerprint) {
                    $candidate_fingerprints[] = $fingerprint;
                }
            }

            $mappings[] = array(
                'id'                   => absint($row['id'] ?? 0),
                'selectorHash'         => sanitize_text_field((string) ($row['selector_hash'] ?? '')),
                'selector'             => (string) ($row['selector'] ?? ''),
                'viewport'             => sanitize_key((string) ($row['viewport'] ?? '')),
                'elementTag'           => sanitize_key((string) ($row['element_tag'] ?? '')),
                'resourceType'         => sanitize_key((string) ($row['resource_type'] ?? 'unknown')),
                'resourceUrl'          => esc_url_raw((string) ($row['resource_url'] ?? '')),
                'observationSource'    => sanitize_key((string) ($row['observation_source'] ?? 'browser')),
                'observationCount'     => absint($row['observation_count'] ?? 0),
                'status'               => sanitize_key((string) ($row['status'] ?? '')),
                'learningState'        => sanitize_key((string) ($row['learning_state'] ?? 'locked')),
                'confirmationCount'    => absint($row['confirmation_count'] ?? 0),
                'candidateFingerprints'=> $candidate_fingerprints,
                'lockedAt'             => absint($row['locked_at'] ?? 0),
                'lastRefreshAt'        => absint($row['last_refresh_at'] ?? 0),
                'firstSeen'            => absint($row['first_seen'] ?? 0),
                'lastSeen'             => absint($row['last_seen'] ?? 0),
                'confirmedAt'          => absint($row['confirmed_at'] ?? 0),
                'updatedAt'            => absint($row['updated_at'] ?? 0),
                'warmRefreshStatus'    => '' === $queue_status ? 'none' : $queue_status,
                'warmRefreshMessage'   => $queue_message,
                'warmRefreshUpdatedAt' => $queue_updated_at,
                'warmRefreshDoneAt'    => $queue_processed_at,
            );
        }

        return array(
            'available' => true,
            'message'   => __('Full mapping details were loaded only for the selected URL.', 'ultracache'),
            'pageHash'  => $page_hash,
            'pageUrl'   => $page_url,
            'mappings'  => $mappings,
        );
    }

    /**
     * Build a paginated dashboard snapshot for browser-observed LCP mappings.
     *
     * @param array<string,mixed>|int $query Query filters, or a legacy numeric row limit.
     * @return array<string,mixed>
     */
    public function get_lcp_observation_diagnostics_snapshot($query = array())
    {
        global $wpdb;

        if (is_numeric($query)) {
            $query = array('perPage' => absint($query), 'tab' => 'all');
        }
        $query = is_array($query) ? $query : array();
        $query = $this->normalize_lcp_observation_diagnostics_query($query);

        $fallback = array(
            'available' => false,
            'message'   => __('The LCP observations table is not available.', 'ultracache'),
            'summary'   => array(
                'learnedPages'          => 0,
                'allMappings'           => 0,
                'attentionMappings'     => 0,
                'confirmedMappings'     => 0,
                'pendingObservations'   => 0,
                'pendingRefreshes'      => 0,
                'failedRefreshes'       => 0,
                'staleMappings'         => 0,
                'lastObservationAt'     => 0,
                'lastSuccessfulRefresh' => array(),
            ),
            'records'    => array(),
            'query'      => $query,
            'pagination' => array(
                'perPage'       => (int) $query['perPage'],
                'returned'      => 0,
                'totalFiltered' => 0,
                'hasMore'       => false,
                'nextCursor'    => '',
            ),
        );

        if (!($wpdb instanceof wpdb) || !self::ensure_lcp_observations_table()) {
            return $fallback;
        }

        $table = self::get_lcp_observations_table_name();
        if ('' === $table) {
            return $fallback;
        }
        $queue_table = $this->get_lcp_diagnostics_queue_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only aggregate over the UltraCache-owned LCP diagnostics table.
        $summary_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(DISTINCT CASE WHEN status = %s THEN page_url_hash ELSE NULL END) AS learned_pages,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS confirmed_mappings,
                    SUM(CASE WHEN status = %s AND learning_state = %s THEN 1 ELSE 0 END) AS learning_mappings,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS pending_observations,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS stale_mappings,
                    MAX(last_seen) AS last_observation_at
                FROM %i",
                'confirmed',
                'confirmed',
                'confirmed',
                'learning',
                'pending',
                'stale',
                $table
            ),
            ARRAY_A
        );
        $summary_row = is_array($summary_row) ? $summary_row : array();

        $pending_refreshes = 0;
        $failed_refreshes = 0;
        if ('' !== $queue_table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only aggregate over UltraCache LCP refresh jobs.
            $queue_summary = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS pending_refreshes, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS failed_refreshes FROM %i WHERE job_type = %s',
                    'pending',
                    'error',
                    $queue_table,
                    'lcp_refresh'
                ),
                ARRAY_A
            );
            $queue_summary = is_array($queue_summary) ? $queue_summary : array();
            $pending_refreshes = absint($queue_summary['pending_refreshes'] ?? 0);
            $failed_refreshes = absint($queue_summary['failed_refreshes'] ?? 0);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact Attention-tab count without loading matching rows.
            $attention_mappings = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                    FROM %i AS o
                    LEFT JOIN %i AS q
                        ON q.job_type = %s
                        AND q.url_hash = SHA1(o.page_url)
                    WHERE o.status IN ('pending', 'stale')
                        OR q.status IN ('pending', 'error')",
                    $table,
                    $queue_table,
                    'lcp_refresh'
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact Attention-tab count without loading matching rows.
            $attention_mappings = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE status IN ('pending', 'stale')",
                    $table
                )
            );
        }

        $count_filter = $this->get_lcp_observation_diagnostics_filter_state($query, false);
        $row_filter = $this->get_lcp_observation_diagnostics_filter_state($query, true);
        $row_limit = (int) $query['perPage'] + 1;

        if ('' !== $queue_table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Server-side filtered count avoids loading all mappings in the browser.
            $total_filtered = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                    FROM %i AS o
                    LEFT JOIN %i AS q
                        ON q.job_type = %s
                        AND q.url_hash = SHA1(o.page_url)
                    WHERE (
                            %d = 1
                            OR (%d = 1 AND (o.status IN ('pending', 'stale') OR q.status IN ('pending', 'error')))
                            OR (%s <> '' AND o.status = %s)
                        )
                        AND (%d = 1 OR o.observation_source = %s)
                        AND (%d = 1 OR o.viewport = %s)
                        AND (%d = 1 OR o.resource_type = %s)
                        AND (
                            %d = 1
                            OR (%d = 1 AND q.id IS NULL)
                            OR (%s <> '' AND q.status = %s)
                        )
                        AND (%d = 1 OR o.page_url LIKE %s OR o.selector LIKE %s OR o.resource_url LIKE %s)
                        AND (%d = 1 OR o.updated_at < %d OR (o.updated_at = %d AND o.id < %d))",
                    $table,
                    $queue_table,
                    'lcp_refresh',
                    $count_filter['tabAll'],
                    $count_filter['tabAttention'],
                    $count_filter['tabStatus'],
                    $count_filter['tabStatus'],
                    $count_filter['sourceAll'],
                    $count_filter['source'],
                    $count_filter['viewportAll'],
                    $count_filter['viewport'],
                    $count_filter['resourceTypeAll'],
                    $count_filter['resourceType'],
                    $count_filter['refreshAll'],
                    $count_filter['refreshNone'],
                    $count_filter['refreshMatch'],
                    $count_filter['refreshMatch'],
                    $count_filter['searchEmpty'],
                    $count_filter['searchLike'],
                    $count_filter['searchLike'],
                    $count_filter['searchLike'],
                    $count_filter['cursorDisabled'],
                    $count_filter['cursorUpdatedAt'],
                    $count_filter['cursorUpdatedAt'],
                    $count_filter['cursorId']
                )
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Server-side cursor pagination returns only the requested LCP diagnostics page.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                        o.id,
                        o.page_url,
                        o.selector_hash,
                        o.selector,
                        o.viewport,
                        o.element_tag,
                        o.resource_type,
                        o.resource_url,
                        o.observation_source,
                        o.observation_count,
                        o.status,
                        o.learning_state,
                        o.confirmation_count,
                        o.locked_at,
                        o.first_seen,
                        o.last_seen,
                        o.confirmed_at,
                        o.updated_at,
                        COALESCE(q.status, '') AS warm_refresh_status,
                        COALESCE(q.result_message, '') AS warm_refresh_message,
                        COALESCE(q.updated_at, 0) AS warm_refresh_updated_at,
                        COALESCE(q.processed_at, 0) AS warm_refresh_processed_at
                    FROM %i AS o
                    LEFT JOIN %i AS q
                        ON q.job_type = %s
                        AND q.url_hash = SHA1(o.page_url)
                    WHERE (
                            %d = 1
                            OR (%d = 1 AND (o.status IN ('pending', 'stale') OR q.status IN ('pending', 'error')))
                            OR (%s <> '' AND o.status = %s)
                        )
                        AND (%d = 1 OR o.observation_source = %s)
                        AND (%d = 1 OR o.viewport = %s)
                        AND (%d = 1 OR o.resource_type = %s)
                        AND (
                            %d = 1
                            OR (%d = 1 AND q.id IS NULL)
                            OR (%s <> '' AND q.status = %s)
                        )
                        AND (%d = 1 OR o.page_url LIKE %s OR o.selector LIKE %s OR o.resource_url LIKE %s)
                        AND (%d = 1 OR o.updated_at < %d OR (o.updated_at = %d AND o.id < %d))
                    ORDER BY o.updated_at DESC, o.id DESC
                    LIMIT %d",
                    $table,
                    $queue_table,
                    'lcp_refresh',
                    $row_filter['tabAll'],
                    $row_filter['tabAttention'],
                    $row_filter['tabStatus'],
                    $row_filter['tabStatus'],
                    $row_filter['sourceAll'],
                    $row_filter['source'],
                    $row_filter['viewportAll'],
                    $row_filter['viewport'],
                    $row_filter['resourceTypeAll'],
                    $row_filter['resourceType'],
                    $row_filter['refreshAll'],
                    $row_filter['refreshNone'],
                    $row_filter['refreshMatch'],
                    $row_filter['refreshMatch'],
                    $row_filter['searchEmpty'],
                    $row_filter['searchLike'],
                    $row_filter['searchLike'],
                    $row_filter['searchLike'],
                    $row_filter['cursorDisabled'],
                    $row_filter['cursorUpdatedAt'],
                    $row_filter['cursorUpdatedAt'],
                    $row_filter['cursorId'],
                    $row_limit
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Server-side filtered count avoids loading all mappings in the browser.
            $total_filtered = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                    FROM %i AS o
                    WHERE (
                            %d = 1
                            OR (%d = 1 AND o.status IN ('pending', 'stale'))
                            OR (%s <> '' AND o.status = %s)
                        )
                        AND (%d = 1 OR o.observation_source = %s)
                        AND (%d = 1 OR o.viewport = %s)
                        AND (%d = 1 OR o.resource_type = %s)
                        AND %d = 1
                        AND (%d = 1 OR o.page_url LIKE %s OR o.selector LIKE %s OR o.resource_url LIKE %s)
                        AND (%d = 1 OR o.updated_at < %d OR (o.updated_at = %d AND o.id < %d))",
                    $table,
                    $count_filter['tabAll'],
                    $count_filter['tabAttention'],
                    $count_filter['tabStatus'],
                    $count_filter['tabStatus'],
                    $count_filter['sourceAll'],
                    $count_filter['source'],
                    $count_filter['viewportAll'],
                    $count_filter['viewport'],
                    $count_filter['resourceTypeAll'],
                    $count_filter['resourceType'],
                    $count_filter['refreshWithoutQueue'],
                    $count_filter['searchEmpty'],
                    $count_filter['searchLike'],
                    $count_filter['searchLike'],
                    $count_filter['searchLike'],
                    $count_filter['cursorDisabled'],
                    $count_filter['cursorUpdatedAt'],
                    $count_filter['cursorUpdatedAt'],
                    $count_filter['cursorId']
                )
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Server-side cursor pagination returns only the requested LCP diagnostics page.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                        o.id,
                        o.page_url,
                        o.selector_hash,
                        o.selector,
                        o.viewport,
                        o.element_tag,
                        o.resource_type,
                        o.resource_url,
                        o.observation_source,
                        o.observation_count,
                        o.status,
                        o.learning_state,
                        o.confirmation_count,
                        o.locked_at,
                        o.first_seen,
                        o.last_seen,
                        o.confirmed_at,
                        o.updated_at,
                        '' AS warm_refresh_status,
                        '' AS warm_refresh_message,
                        0 AS warm_refresh_updated_at,
                        0 AS warm_refresh_processed_at
                    FROM %i AS o
                    WHERE (
                            %d = 1
                            OR (%d = 1 AND o.status IN ('pending', 'stale'))
                            OR (%s <> '' AND o.status = %s)
                        )
                        AND (%d = 1 OR o.observation_source = %s)
                        AND (%d = 1 OR o.viewport = %s)
                        AND (%d = 1 OR o.resource_type = %s)
                        AND %d = 1
                        AND (%d = 1 OR o.page_url LIKE %s OR o.selector LIKE %s OR o.resource_url LIKE %s)
                        AND (%d = 1 OR o.updated_at < %d OR (o.updated_at = %d AND o.id < %d))
                    ORDER BY o.updated_at DESC, o.id DESC
                    LIMIT %d",
                    $table,
                    $row_filter['tabAll'],
                    $row_filter['tabAttention'],
                    $row_filter['tabStatus'],
                    $row_filter['tabStatus'],
                    $row_filter['sourceAll'],
                    $row_filter['source'],
                    $row_filter['viewportAll'],
                    $row_filter['viewport'],
                    $row_filter['resourceTypeAll'],
                    $row_filter['resourceType'],
                    $row_filter['refreshWithoutQueue'],
                    $row_filter['searchEmpty'],
                    $row_filter['searchLike'],
                    $row_filter['searchLike'],
                    $row_filter['searchLike'],
                    $row_filter['cursorDisabled'],
                    $row_filter['cursorUpdatedAt'],
                    $row_filter['cursorUpdatedAt'],
                    $row_filter['cursorId'],
                    $row_limit
                ),
                ARRAY_A
            );
        }

        $rows = is_array($rows) ? $rows : array();
        $has_more = count($rows) > (int) $query['perPage'];
        if ($has_more) {
            array_pop($rows);
        }
        $next_cursor = $has_more && !empty($rows)
            ? $this->encode_lcp_observation_diagnostics_cursor($rows[count($rows) - 1])
            : '';

        $last_successful_refresh = get_option('ultracache_lcp_last_refresh', array());
        $last_successful_refresh = is_array($last_successful_refresh) ? $last_successful_refresh : array();
        $records = array();
        foreach ($rows as $row) {
            $page_url = esc_url_raw((string) ($row['page_url'] ?? ''));
            $queue = array(
                'status'         => sanitize_key((string) ($row['warm_refresh_status'] ?? '')),
                'result_message' => (string) ($row['warm_refresh_message'] ?? ''),
                'updated_at'     => absint($row['warm_refresh_updated_at'] ?? 0),
                'processed_at'   => absint($row['warm_refresh_processed_at'] ?? 0),
            );
            if (
                '' === $queue['status']
                && '' !== $page_url
                && $page_url === esc_url_raw((string) ($last_successful_refresh['url'] ?? ''))
                && absint($last_successful_refresh['timestamp'] ?? 0) > 0
            ) {
                $queue = array(
                    'status'         => 'done',
                    'result_message' => (string) ($last_successful_refresh['message'] ?? ''),
                    'updated_at'     => absint($last_successful_refresh['timestamp'] ?? 0),
                    'processed_at'   => absint($last_successful_refresh['timestamp'] ?? 0),
                );
            }
            $records[] = array(
                'id'                   => absint($row['id'] ?? 0),
                'pageUrl'              => $page_url,
                'selectorHash'         => sanitize_text_field((string) ($row['selector_hash'] ?? '')),
                'selector'             => (string) ($row['selector'] ?? ''),
                'viewport'             => sanitize_key((string) ($row['viewport'] ?? '')),
                'elementTag'           => sanitize_key((string) ($row['element_tag'] ?? '')),
                'resourceType'         => sanitize_key((string) ($row['resource_type'] ?? 'unknown')),
                'resourceUrl'          => esc_url_raw((string) ($row['resource_url'] ?? '')),
                'observationSource'    => sanitize_key((string) ($row['observation_source'] ?? 'browser')),
                'observationCount'     => absint($row['observation_count'] ?? 0),
                'status'               => sanitize_key((string) ($row['status'] ?? '')),
                'learningState'        => sanitize_key((string) ($row['learning_state'] ?? 'locked')),
                'confirmationCount'    => absint($row['confirmation_count'] ?? 0),
                'lockedAt'             => absint($row['locked_at'] ?? 0),
                'firstSeen'            => absint($row['first_seen'] ?? 0),
                'lastSeen'             => absint($row['last_seen'] ?? 0),
                'confirmedAt'          => absint($row['confirmed_at'] ?? 0),
                'updatedAt'            => absint($row['updated_at'] ?? 0),
                'warmRefreshStatus'    => '' === $queue['status'] ? 'none' : $queue['status'],
                'warmRefreshMessage'   => sanitize_text_field((string) ($queue['result_message'] ?? '')),
                'warmRefreshUpdatedAt' => absint($queue['updated_at'] ?? 0),
                'warmRefreshDoneAt'    => absint($queue['processed_at'] ?? 0),
            );
        }

        $all_rows = absint($summary_row['confirmed_mappings'] ?? 0)
            + absint($summary_row['pending_observations'] ?? 0)
            + absint($summary_row['stale_mappings'] ?? 0);

        return array(
            'available' => true,
            'homeUrl'   => esc_url_raw(home_url('/')),
            'message'   => $all_rows < 1
                ? __('No browser-observed LCP mappings have been recorded yet. Enable LCP Frontend Discovery and visit cacheable public pages without query parameters.', 'ultracache')
                : (empty($records)
                    ? __('No LCP observations match the current server-side filters.', 'ultracache')
                    : __('Browser-observed LCP mappings are stored persistently; each page and viewport stops discovery after a two-of-three confirmation.', 'ultracache')),
            'summary'   => array(
                'learnedPages'          => absint($summary_row['learned_pages'] ?? 0),
                'allMappings'           => max(0, $all_rows),
                'attentionMappings'     => max(0, $attention_mappings),
                'confirmedMappings'     => absint($summary_row['confirmed_mappings'] ?? 0),
                'learningMappings'      => absint($summary_row['learning_mappings'] ?? 0),
                'pendingObservations'   => absint($summary_row['pending_observations'] ?? 0),
                'pendingRefreshes'      => max(0, $pending_refreshes),
                'failedRefreshes'       => max(0, $failed_refreshes),
                'staleMappings'         => absint($summary_row['stale_mappings'] ?? 0),
                'lastObservationAt'     => absint($summary_row['last_observation_at'] ?? 0),
                'lastSuccessfulRefresh' => array(
                    'url'       => esc_url_raw((string) ($last_successful_refresh['url'] ?? '')),
                    'timestamp' => absint($last_successful_refresh['timestamp'] ?? 0),
                    'message'   => sanitize_text_field((string) ($last_successful_refresh['message'] ?? '')),
                ),
            ),
            'records'    => $records,
            'query'      => array(
                'tab'           => (string) $query['tab'],
                'source'        => (string) $query['source'],
                'viewport'      => (string) $query['viewport'],
                'resourceType'  => (string) $query['resourceType'],
                'refreshStatus' => (string) $query['refreshStatus'],
                'search'        => (string) $query['search'],
                'perPage'       => (int) $query['perPage'],
                'cursor'        => (string) $query['cursor'],
            ),
            'pagination' => array(
                'perPage'       => (int) $query['perPage'],
                'returned'      => count($records),
                'totalFiltered' => max(0, $total_filtered),
                'hasMore'       => $has_more,
                'nextCursor'    => $next_cursor,
            ),
        );
    }

    /**
     * Execute an administrator-requested LCP mapping action.
     *
     * @param int    $record_id Observation row ID.
     * @param string $action    forget, relearn, or refresh.
     * @return array<string,mixed>|WP_Error
     */
    public function perform_lcp_observation_admin_action($record_id, $action)
    {
        global $wpdb;

        $record_id = absint($record_id);
        $action = sanitize_key((string) $action);
        if ($record_id < 1 || !in_array($action, array('forget', 'relearn', 'refresh'), true)) {
            return new WP_Error('ultracache_invalid_lcp_action', __('Invalid LCP observation action.', 'ultracache'), array('status' => 400));
        }
        if (!($wpdb instanceof wpdb) || !self::ensure_lcp_observations_table()) {
            return new WP_Error('ultracache_lcp_table_unavailable', __('The LCP observations table is unavailable.', 'ultracache'), array('status' => 500));
        }

        $table = self::get_lcp_observations_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads one administrator-selected UltraCache-owned diagnostics row.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, page_url, selector_hash, viewport, status, observation_source FROM %i WHERE id = %d LIMIT 1',
                $table,
                $record_id
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return new WP_Error('ultracache_lcp_record_missing', __('The selected LCP mapping no longer exists.', 'ultracache'), array('status' => 404));
        }

        $page_url = $this->normalize_lcp_observation_page_url((string) ($row['page_url'] ?? ''));
        $viewport = sanitize_key((string) ($row['viewport'] ?? ''));
        if ('' === $page_url || !$this->is_lcp_observation_page_cacheable_url($page_url) || !in_array($viewport, array('mobile', 'tablet', 'desktop'), true)) {
            return new WP_Error('ultracache_invalid_lcp_learning_scope', __('The selected LCP mapping has an invalid discovery scope.', 'ultracache'), array('status' => 400));
        }

        $now = time();
        if ('forget' === $action) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit administrator deletion of one UltraCache-owned diagnostics row.
            $changed = $wpdb->delete($table, array('id' => $record_id), array('%d'));
        } elseif ('relearn' === $action) {
            // Keep the current mapping active as a fallback while clearing only its learning evidence.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit administrator reset of one UltraCache-owned discovery row.
            $changed = $wpdb->update(
                $table,
                array(
                    'status'             => 'confirmed',
                    'learning_state'     => 'learning',
                    'confirmation_count' => 0,
                    'candidate_window'   => wp_json_encode(array()),
                    'locked_at'          => 0,
                    'updated_at'         => $now,
                ),
                array('id' => $record_id),
                array('%s', '%s', '%d', '%s', '%d', '%d'),
                array('%d')
            );
        } else {
            $changed = 0;
        }
        if (false === $changed) {
            return new WP_Error('ultracache_lcp_action_failed', __('The LCP mapping could not be updated.', 'ultracache'), array('status' => 500));
        }

        $purged = false;
        if (method_exists($this, 'purge_page_cache_url_only')) {
            $purged = (bool) $this->purge_page_cache_url_only($page_url);
        } elseif (method_exists($this, 'purge_url')) {
            $purged = (bool) $this->purge_url($page_url);
        }

        $refresh_queued = null;
        if ('relearn' !== $action && class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'enqueue_lcp_refresh_url')) {
            $refresh_queued = (bool) Ultra_Cache_WP::enqueue_lcp_refresh_url($page_url);
        }

        $message = 'relearn' === $action
            ? __('LCP discovery was reset for this viewport. The current mapping remains active until new visits confirm a replacement.', 'ultracache')
            : ('forget' === $action ? __('LCP mapping forgotten.', 'ultracache') : __('LCP page-cache refresh requested.', 'ultracache'));
        if ('relearn' !== $action && false === $refresh_queued) {
            $message .= ' ' . __('The page refresh could not be queued automatically.', 'ultracache');
        }

        return array(
            'success'       => true,
            'action'        => $action,
            'recordId'      => $record_id,
            'pageUrl'       => $page_url,
            'purged'        => $purged,
            'refreshQueued' => $refresh_queued,
            'message'       => $message,
        );
    }

    private function maybe_cleanup_lcp_observations_table()
    {
        global $wpdb;

        $cleanup_key = 'ultracache_lcp_observations_cleanup_v2';
        if (get_transient($cleanup_key) || !self::ensure_lcp_observations_table()) {
            return;
        }

        set_transient($cleanup_key, 1, MINUTE_IN_SECONDS);
        $now = time();
        $stale_retention = max(DAY_IN_SECONDS, $this->get_stale_lcp_observation_retention_seconds());
        $pending_retention = max(DAY_IN_SECONDS, $this->get_pending_lcp_observation_retention_seconds());
        $stale_cutoff = max(0, $now - $stale_retention);
        $pending_cutoff = max(0, $now - $pending_retention);
        $table = self::get_lcp_observations_table_name();

        // Confirmed mappings persist until they are replaced or explicitly
        // forgotten. Old stale competitors and unconfirmed legacy pending rows
        // are pruned in bounded batches without limiting total stored pages.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded cleanup for stale UltraCache-owned LCP mapping rows.
        $stale_deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE status = %s AND last_seen > 0 AND last_seen < %d LIMIT 500', $table, 'stale', $stale_cutoff));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded cleanup for old unconfirmed UltraCache-owned LCP mapping rows.
        $pending_deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE status = %s AND last_seen > 0 AND last_seen < %d LIMIT 500', $table, 'pending', $pending_cutoff));

        $more_batches_may_exist = 500 === absint($stale_deleted) || 500 === absint($pending_deleted);
        set_transient($cleanup_key, 1, $more_batches_may_exist ? 5 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS);
    }
}
