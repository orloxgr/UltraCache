<?php
/**
 * Cron warm-up queue and orchestration methods.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Cron_Warm_Orchestrator_Trait
{
    private static function get_cron_warm_queue_db_version()
    {
        return '9';
    }

    private static function get_cron_warm_queue_db_version_option_key()
    {
        return 'ultracache_cron_warm_queue_db_version';
    }

    private static function get_cron_warm_queue_table_name()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ultracache_cron_warm_queue';
        return function_exists('ultracache_validate_custom_table_name') ? ultracache_validate_custom_table_name($table, 'cron_warm_queue') : $table;
    }

    private static function cron_warm_queue_table_exists()
    {
        global $wpdb;
        if (!($wpdb instanceof wpdb)) {
            return false;
        }

        $table = self::get_cron_warm_queue_table_name();
        $cache_key = 'ultracache_cron_warm_queue_table_exists_' . md5((string) $table);
        $found = false;
        $cached = wp_cache_get($cache_key, 'ultracache', false, $found);
        if ($found && is_bool($cached)) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Schema existence check for an UltraCache-owned custom table; cached below.
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $exists = ((string) $found === (string) $table);
        wp_cache_set($cache_key, $exists, 'ultracache', HOUR_IN_SECONDS);
        return $exists;
    }

    /**
     * Check whether the permanent warm queue can be read without creating or
     * upgrading schema and without writing an object-cache existence result.
     *
     * Status surfaces use this path so dashboard reads cannot invoke dbDelta,
     * update schema-version options, or otherwise mutate queue storage.
     *
     * @return bool
     */
    private static function cron_warm_queue_table_read_ready()
    {
        global $wpdb;
        if (!($wpdb instanceof wpdb)) {
            return false;
        }

        if (self::get_cron_warm_queue_db_version() !== (string) get_option(self::get_cron_warm_queue_db_version_option_key(), '')) {
            return false;
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only schema existence check for the status path; deliberately avoids persistent/object-cache writes.
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return (string) $found === (string) $table;
    }

    /**
     * Verify the permanent claim/lease columns required by the queue runtime.
     *
     * @return bool
     */
    private static function cron_warm_queue_claim_schema_ready()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb) || !self::cron_warm_queue_table_exists()) {
            return false;
        }

        $table = self::get_cron_warm_queue_table_name();
        $cache_key = 'ultracache_cron_warm_queue_claim_schema_' . md5((string) $table);
        $found = false;
        $cached = wp_cache_get($cache_key, 'ultracache', false, $found);
        if ($found && is_bool($cached)) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Verifies the permanent schema of an UltraCache-owned custom table after dbDelta; cached below.
        $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table), 0);
        if (!is_array($columns)) {
            wp_cache_set($cache_key, false, 'ultracache', MINUTE_IN_SECONDS);
            return false;
        }

        $required = array('claim_token', 'claimed_at', 'lease_expires_at', 'rerun_requested', 'pending_targets', 'result_level');
        $ready = empty(array_diff($required, array_map('strval', $columns)));
        wp_cache_set($cache_key, $ready, 'ultracache', HOUR_IN_SECONDS);
        return $ready;
    }

    public static function ensure_cron_warm_queue_table()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return false;
        }

        $table = self::get_cron_warm_queue_table_name();
        $version = (string) get_option(self::get_cron_warm_queue_db_version_option_key(), '');
        if (
            self::get_cron_warm_queue_db_version() === $version
            && self::cron_warm_queue_table_exists()
            && self::cron_warm_queue_claim_schema_ready()
        ) {
            return true;
        }

        if (!ultracache_require_wordpress_admin_include('upgrade.php', 'dbDelta')) {
            return false;
        }
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            url_hash varchar(40) NOT NULL DEFAULT '',
            url text NOT NULL,
            job_type varchar(32) NOT NULL DEFAULT 'page_warm',
            source_context varchar(32) NOT NULL DEFAULT '',
            requires_verified_origin tinyint(1) unsigned NOT NULL DEFAULT 0,
            position bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            result_level varchar(20) NOT NULL DEFAULT '',
            claim_token varchar(64) NOT NULL DEFAULT '',
            claimed_at bigint(20) unsigned NOT NULL DEFAULT 0,
            lease_expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
            rerun_requested tinyint(1) unsigned NOT NULL DEFAULT 0,
            pending_targets text NULL,
            result_message text NULL,
            created_at bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
            processed_at bigint(20) unsigned NOT NULL DEFAULT 0,
            attempt_count int(10) unsigned NOT NULL DEFAULT 0,
            next_attempt_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY job_type_url_hash (job_type, url_hash),
            KEY url_hash (url_hash),
            KEY job_status_position (job_type, status, position),
            KEY job_status_retry_position (job_type, status, next_attempt_at, position),
            KEY status_position (status, position),
            KEY status_lease_expires (status, lease_expires_at),
            KEY claim_token (claim_token),
            KEY updated_at (updated_at),
            KEY processed_at (processed_at)
        ) {$charset_collate};";

        dbDelta($sql);
        wp_cache_delete('ultracache_cron_warm_queue_table_exists_' . md5((string) $table), 'ultracache');
        wp_cache_delete('ultracache_cron_warm_queue_claim_schema_' . md5((string) $table), 'ultracache');
        if (self::cron_warm_queue_table_exists() && self::cron_warm_queue_claim_schema_ready()) {
            update_option(self::get_cron_warm_queue_db_version_option_key(), self::get_cron_warm_queue_db_version(), false);
            return true;
        }

        return false;
    }

    private static function clear_cron_warm_queue_table($preserve_lcp_refresh = false)
    {
        global $wpdb;

        if (!self::ensure_cron_warm_queue_table()) {
            return false;
        }

        $table = self::get_cron_warm_queue_table_name();
        if ($preserve_lcp_refresh) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Manual warm priority clears ordinary UltraCache work while retaining deferred LCP and persistent Varnish jobs.
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM %i WHERE status <> %s AND job_type NOT IN (%s, %s) AND NOT (job_type = %s AND source_context <> %s)",
                    $table,
                    'processing',
                    'lcp_refresh',
                    'varnish_invalidate',
                    'page_warm',
                    ''
                )
            );
            return true;
        }

        // Persistent Varnish invalidation, active targeted page-warm rows, and every
        // processing claim survive batch transitions, manual priority, and cache flushes.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes only ordinary UltraCache warm queue rows.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE status <> %s AND job_type <> %s AND NOT (job_type = %s AND source_context <> %s)",
                $table,
                'processing',
                'varnish_invalidate',
                'page_warm',
                ''
            )
        );
        return true;
    }

    private static function insert_cron_warm_queue_urls(array $urls, $base_position = 0, $job_type = 'page_warm', $source_context = '', $requires_verified_origin = false, &$accepted_urls = null, &$enqueue_summary = null)
    {
        global $wpdb;

        $enqueue_summary = array(
            'received' => count($urls),
            'accepted' => 0,
            'inserted' => 0,
            'coalesced' => 0,
            'upgraded' => 0,
            'duplicates' => 0,
            'rejected' => 0,
            'failed' => 0,
        );
        $accepted_urls = array();
        if (empty($urls) || !self::ensure_cron_warm_queue_table()) {
            $enqueue_summary['failed'] = count($urls);
            return 0;
        }

        $table = self::get_cron_warm_queue_table_name();
        $now = time();
        $base_position = max(0, (int) $base_position);
        $job_type = in_array((string) $job_type, array('page_warm', 'css_bundle', 'lcp_refresh', 'varnish_invalidate'), true) ? (string) $job_type : 'page_warm';
        $source_context = substr(sanitize_key((string) $source_context), 0, 32);
        $requires_verified_origin = 'page_warm' === $job_type && (bool) $requires_verified_origin;
        $seen_urls = array();

        foreach ($urls as $url) {
            $url = is_string($url) ? trim($url) : '';
            if ('' === $url) {
                ++$enqueue_summary['rejected'];
                continue;
            }

            $url = function_exists('esc_url_raw') ? esc_url_raw($url) : $url;
            if ('' === $url) {
                ++$enqueue_summary['rejected'];
                continue;
            }
            if (isset($seen_urls[$url])) {
                ++$enqueue_summary['duplicates'];
                continue;
            }
            $seen_urls[$url] = true;

            $hash = sha1($url);
            $position = in_array($job_type, array('varnish_invalidate', 'lcp_refresh'), true)
                || ('page_warm' === $job_type && '' !== $source_context)
                ? 0
                : $base_position + count($accepted_urls) + 1;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue writes and coalesces only UltraCache-owned rows.
            $result = $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO %i (url_hash, url, job_type, source_context, requires_verified_origin, position, status, result_level, claim_token, claimed_at, lease_expires_at, rerun_requested, pending_targets, result_message, created_at, updated_at, processed_at, attempt_count, next_attempt_at) VALUES (%s, %s, %s, %s, %d, %d, %s, %s, %s, %d, %d, %d, %s, %s, %d, %d, %d, %d, %d) ON DUPLICATE KEY UPDATE url = VALUES(url), source_context = CASE WHEN status IN ('pending', 'error', 'processing') THEN CASE WHEN VALUES(source_context) <> '' THEN VALUES(source_context) ELSE source_context END ELSE VALUES(source_context) END, requires_verified_origin = CASE WHEN status IN ('pending', 'error', 'processing') THEN GREATEST(requires_verified_origin, VALUES(requires_verified_origin)) ELSE VALUES(requires_verified_origin) END, position = CASE WHEN status IN ('pending', 'error') THEN LEAST(position, VALUES(position)) WHEN status = 'processing' THEN position ELSE VALUES(position) END, result_level = CASE WHEN status = 'processing' THEN result_level ELSE VALUES(result_level) END, pending_targets = CASE WHEN status = 'processing' THEN pending_targets ELSE VALUES(pending_targets) END, result_message = CASE WHEN status = 'processing' THEN result_message ELSE VALUES(result_message) END, created_at = CASE WHEN status IN ('done', 'skipped', 'error') THEN VALUES(created_at) ELSE created_at END, updated_at = CASE WHEN status = 'pending' AND (VALUES(source_context) = '' OR source_context = VALUES(source_context)) AND requires_verified_origin >= VALUES(requires_verified_origin) AND position <= VALUES(position) AND result_level = '' AND COALESCE(pending_targets, '') = '' AND COALESCE(result_message, '') = '' AND processed_at = 0 AND next_attempt_at = 0 AND claim_token = '' AND claimed_at = 0 AND lease_expires_at = 0 AND rerun_requested = 0 THEN updated_at WHEN status = 'processing' AND (VALUES(source_context) = '' OR source_context = VALUES(source_context)) AND requires_verified_origin >= VALUES(requires_verified_origin) AND rerun_requested = 1 THEN updated_at ELSE VALUES(updated_at) END, processed_at = CASE WHEN status = 'processing' THEN processed_at ELSE VALUES(processed_at) END, attempt_count = CASE WHEN status IN ('pending', 'processing') THEN attempt_count ELSE VALUES(attempt_count) END, next_attempt_at = CASE WHEN status = 'processing' THEN next_attempt_at ELSE VALUES(next_attempt_at) END, claim_token = CASE WHEN status = 'processing' THEN claim_token ELSE VALUES(claim_token) END, claimed_at = CASE WHEN status = 'processing' THEN claimed_at ELSE VALUES(claimed_at) END, lease_expires_at = CASE WHEN status = 'processing' THEN lease_expires_at ELSE VALUES(lease_expires_at) END, rerun_requested = CASE WHEN status = 'processing' THEN 1 ELSE VALUES(rerun_requested) END, status = CASE WHEN status = 'processing' THEN status ELSE VALUES(status) END",
                    $table,
                    $hash,
                    $url,
                    $job_type,
                    $source_context,
                    $requires_verified_origin ? 1 : 0,
                    $position,
                    'pending',
                    '',
                    '',
                    0,
                    0,
                    0,
                    '',
                    '',
                    $now,
                    $now,
                    0,
                    0,
                    0
                )
            );
            if (false === $result) {
                ++$enqueue_summary['failed'];
                continue;
            }

            $accepted_urls[$url] = $url;
            if (0 === (int) $result) {
                ++$enqueue_summary['coalesced'];
            } elseif (1 === (int) $result) {
                ++$enqueue_summary['inserted'];
            } else {
                ++$enqueue_summary['upgraded'];
            }
        }

        $accepted_urls = array_values($accepted_urls);
        $enqueue_summary['accepted'] = count($accepted_urls);
        return $enqueue_summary['accepted'];
    }

    public static function enqueue_async_css_bundle_url($url)
    {
        global $wpdb;

        if (self::is_manual_warmup_blocking_cron()) {
            return false;
        }

        $url = is_string($url) ? trim($url) : '';
        if ('' === $url || !self::ensure_cron_warm_queue_table()) {
            return false;
        }

        $engine = self::get_engine_instance();
        if (!$engine || !method_exists($engine, 'is_cacheable_local_url') || !$engine->is_cacheable_local_url($url)) {
            return false;
        }

        $state = self::get_cron_warm_state();
        $pending_before = self::count_cron_warm_pending_queue_rows();
        $inserted = self::insert_cron_warm_queue_urls(array($url), $pending_before, 'css_bundle');
        if ($inserted < 1) {
            return false;
        }

        $now = time();
        if (empty($state['active'])) {
            $state = self::save_cron_warm_state(array(
                'active'       => true,
                'reason' => 'css_bundle_async',
                'cursor'       => '',
                'processed'    => 0,
                'total'        => max(1, $pending_before + $inserted),
                'successCount' => 0,
                'errorCount'   => 0,
                'startedAt'    => $now,
                'updatedAt'    => $now,
                'lastRunAt'    => 0,
                'finishedAt'   => 0,
                'pagesPerMinute' => max(1, (int) (self::get_settings()['cron_warm_pages_per_minute'] ?? 2)),
                'totalLimit'   => 0,
                'currentBatch' => array(),
                'batchIndex'   => 0,
                'batchHasMore' => false,
                'nextCursorPending' => '',
                'lastError'    => '',
                'lastMessage'  => self::maybe_translate('Async CSS bundle build queued.'),
                'lastUrl'      => $url,
                'completed'    => false,
                'stopped'      => false,
                'stopReason'   => '',
                'invokedBy'    => 'frontend-css-bundle',
            ));
        } else {
            $state['active'] = true;
            $state['completed'] = false;
            $state['stopped'] = false;
            $state['updatedAt'] = $now;
            $state['total'] = max((int) ($state['total'] ?? 0), (int) ($state['processed'] ?? 0) + $pending_before + $inserted);
            $state['lastMessage'] = self::maybe_translate('Async CSS bundle build queued.');
            $state['lastUrl'] = $url;
            self::save_cron_warm_state($state);
        }

        self::ensure_cron_warm_events_scheduled(5);
        return true;
    }

    /**
     * Queue one page-specific LCP refresh through the existing cron warm runner.
     *
     * The browser observation has already supplied the verified page/resource
     * mapping. This queue item only rebuilds the affected page-cache variants;
     * it does not start a CSS bundle scan or a full-site crawl.
     *
     * @param string $url Local page URL.
     * @return bool
     */
    public static function enqueue_lcp_refresh_url($url)
    {
        $url = is_string($url) ? trim($url) : '';
        if ('' === $url || !self::ensure_cron_warm_queue_table()) {
            return false;
        }

        $engine = self::get_engine_instance();
        if (
            !$engine
            || !method_exists($engine, 'is_lcp_observation_page_cacheable_url')
            || !$engine->is_lcp_observation_page_cacheable_url($url)
        ) {
            return false;
        }

        $state = self::get_cron_warm_state();
        $pending_before = self::count_cron_warm_pending_queue_rows();
        // Position page-specific refreshes at the front of the existing queue.
        // The unique (job_type, url_hash) key keeps one pending refresh per URL.
        $inserted = self::insert_cron_warm_queue_urls(array($url), 0, 'lcp_refresh');
        if ($inserted < 1) {
            return false;
        }

        $now = time();
        $settings = self::get_settings();
        $configured_rate = max(1, (int) ($settings['cron_warm_pages_per_minute'] ?? 2));
        if (self::is_manual_warmup_blocking_cron()) {
            $state['active'] = false;
            $state['reason'] = 'lcp_refresh_async';
            $state['completed'] = false;
            $state['stopped'] = true;
            $state['stopReason'] = 'manual_warm_priority';
            $state['updatedAt'] = $now;
            $state['pagesPerMinute'] = $configured_rate;
            $state['total'] = max(1, self::count_pending_lcp_refresh_queue_rows());
            $state['lastMessage'] = self::maybe_translate('Page-specific LCP refresh deferred until the manual warm-up finishes.');
            $state['lastUrl'] = $url;
            $state['invokedBy'] = 'browser-lcp-deferred';
            self::save_cron_warm_state($state);
            self::unschedule_cron_warm_events();
            return true;
        }

        if (empty($state['active'])) {
            $state = self::save_cron_warm_state(array(
                'active'            => true,
                'reason'            => 'lcp_refresh_async',
                'cursor'            => '',
                'processed'         => 0,
                'total'             => max(1, $pending_before + $inserted),
                'successCount'      => 0,
                'errorCount'        => 0,
                'startedAt'         => $now,
                'updatedAt'         => $now,
                'lastRunAt'         => 0,
                'finishedAt'        => 0,
                'pagesPerMinute'    => $configured_rate,
                'totalLimit'        => 0,
                'currentBatch'      => array(),
                'batchIndex'        => 0,
                'batchHasMore'      => false,
                'nextCursorPending' => '',
                'lastError'         => '',
                'lastMessage'       => self::maybe_translate('Page-specific LCP refresh queued.'),
                'lastUrl'           => $url,
                'completed'         => false,
                'stopped'           => false,
                'stopReason'        => '',
                'invokedBy'         => 'browser-lcp',
            ));
        } else {
            $state['active'] = true;
            $state['completed'] = false;
            $state['stopped'] = false;
            $state['updatedAt'] = $now;
            $state['pagesPerMinute'] = max(1, (int) ($state['pagesPerMinute'] ?? $configured_rate));
            $state['total'] = max(
                (int) ($state['total'] ?? 0),
                (int) ($state['processed'] ?? 0) + $pending_before + $inserted
            );
            // A page-specific refresh must not be discarded merely because a
            // bounded full-site run has already reached its configured limit.
            if (!empty($state['totalLimit'])) {
                $state['totalLimit'] = max(
                    (int) $state['totalLimit'],
                    (int) ($state['processed'] ?? 0) + $inserted
                );
            }
            $state['lastMessage'] = self::maybe_translate('Page-specific LCP refresh queued.');
            $state['lastUrl'] = $url;
            $state['invokedBy'] = 'browser-lcp';
            self::save_cron_warm_state($state);
        }

        self::ensure_cron_warm_events_scheduled(5);
        return true;
    }

    /**
     * Queue-row lease duration. The warm pipeline itself has a much smaller
     * execution budget; this lease only guards against abandoned workers.
     *
     * @return int
     */
    private static function get_cron_warm_queue_lease_seconds()
    {
        $seconds = (int) apply_filters('ultracache_cron_warm_queue_lease_seconds', 300);
        return max(60, min(1800, $seconds));
    }

    /**
     * Return abandoned processing rows to the pending state.
     *
     * This is normal runtime recovery for interrupted queue workers.
     *
     * @return int
     */
    private static function recover_expired_cron_warm_queue_leases()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return 0;
        }

        $table = self::get_cron_warm_queue_table_name();
        $now = time();
        $message = self::maybe_translate('A previous queue worker lease expired; the row was returned to pending state.');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovers only expired processing leases in the UltraCache-owned queue.
        $recovered = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET status = %s, claim_token = %s, claimed_at = %d, lease_expires_at = %d, rerun_requested = %d, result_message = %s, attempt_count = GREATEST(attempt_count - 1, 0), next_attempt_at = %d, updated_at = %d, processed_at = %d WHERE status = %s AND lease_expires_at > %d AND lease_expires_at <= %d",
                $table,
                'pending',
                '',
                0,
                0,
                0,
                $message,
                $now + 30,
                $now,
                0,
                'processing',
                0,
                $now
            )
        );

        return false === $recovered ? 0 : max(0, (int) $recovered);
    }

    /**
     * Atomically claim one queue row immediately before executing it.
     *
     * @param array $candidate Candidate row loaded from the pending queue.
     * @return array
     */
    private static function claim_cron_warm_queue_row(array $candidate)
    {
        global $wpdb;

        $row_id = absint($candidate['id'] ?? 0);
        if ($row_id < 1 || !($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return array();
        }

        $table = self::get_cron_warm_queue_table_name();
        $now = time();
        $claim_token = 'warm-' . wp_generate_password(32, false, false);
        $lease_expires_at = $now + self::get_cron_warm_queue_lease_seconds();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional UPDATE is the atomic claim primitive for one UltraCache-owned queue row.
        $claimed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET status = %s, claim_token = %s, claimed_at = %d, lease_expires_at = %d, rerun_requested = %d, attempt_count = attempt_count + 1, updated_at = %d WHERE id = %d AND status = %s AND next_attempt_at <= %d",
                $table,
                'processing',
                $claim_token,
                $now,
                $lease_expires_at,
                0,
                $now,
                $row_id,
                'pending',
                $now
            )
        );
        if (1 !== (int) $claimed) {
            return array();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads back only the row owned by the newly issued claim token.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, url, job_type, source_context, requires_verified_origin, status, claim_token, claimed_at, lease_expires_at, rerun_requested, pending_targets, attempt_count, next_attempt_at FROM %i WHERE id = %d AND status = %s AND claim_token = %s",
                $table,
                $row_id,
                'processing',
                $claim_token
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : array();
    }

    /**
     * Extend the processing lease only while the supplied token still owns the row.
     *
     * @param array $row Claimed queue row.
     * @return bool
     */
    private static function renew_cron_warm_queue_claim(array $row)
    {
        global $wpdb;

        $row_id = absint($row['id'] ?? 0);
        $claim_token = sanitize_text_field((string) ($row['claim_token'] ?? ''));
        if ($row_id < 1 || '' === $claim_token || !($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return false;
        }

        $table = self::get_cron_warm_queue_table_name();
        $now = time();
        $lease_expires_at = $now + self::get_cron_warm_queue_lease_seconds();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Token-guarded renewal extends only the caller-owned UltraCache queue row.
        $renewed = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET lease_expires_at = %d, updated_at = %d WHERE id = %d AND status = %s AND claim_token = %s',
                $table,
                $lease_expires_at,
                $now,
                $row_id,
                'processing',
                $claim_token
            )
        );
        if (1 === (int) $renewed) {
            return true;
        }

        // A second renewal inside the same second can legitimately leave the value unchanged.
        // Read the authoritative row before treating that as lost ownership.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Ownership verification must read the authoritative UltraCache queue row.
        $current = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT status, claim_token, lease_expires_at FROM %i WHERE id = %d LIMIT 1',
                $table,
                $row_id
            ),
            ARRAY_A
        );

        return is_array($current)
            && 'processing' === (string) ($current['status'] ?? '')
            && hash_equals((string) ($current['claim_token'] ?? ''), $claim_token)
            && (int) ($current['lease_expires_at'] ?? 0) >= $lease_expires_at;
    }

    /**
     * Persist bounded endpoint/phase state while one worker still owns a queue row.
     *
     * @param array  $row             Claimed queue row.
     * @param string $pending_targets Encoded bounded state.
     * @return bool
     */
    private static function update_cron_warm_queue_claim_pending_targets(array $row, $pending_targets)
    {
        global $wpdb;
        $row_id = absint($row['id'] ?? 0);
        $claim_token = (string) ($row['claim_token'] ?? '');
        $pending_targets = is_string($pending_targets) && strlen($pending_targets) <= 8192 ? $pending_targets : '';
        if ($row_id < 1 || '' === $claim_token || !($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return false;
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Claim token keeps refresh-ahead phase state bound to the authoritative UltraCache queue owner.
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET pending_targets = %s, updated_at = %d WHERE id = %d AND status = %s AND claim_token = %s',
                $table,
                $pending_targets,
                time(),
                $row_id,
                'processing',
                $claim_token
            )
        );
        if (1 === (int) $updated) {
            return true;
        }

        // An identical bounded state can produce zero affected rows. Verify the
        // authoritative claim before treating that as lost ownership.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads only the caller-owned UltraCache queue row.
        $current = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT status, claim_token, pending_targets FROM %i WHERE id = %d LIMIT 1',
                $table,
                $row_id
            ),
            ARRAY_A
        );
        return is_array($current)
            && 'processing' === (string) ($current['status'] ?? '')
            && hash_equals((string) ($current['claim_token'] ?? ''), $claim_token)
            && (string) ($current['pending_targets'] ?? '') === $pending_targets;
    }


    /**
     * Return an owned row to pending without recording an application failure.
     *
     * @param array  $row     Claimed queue row.
     * @param string $message Result detail.
     * @param int    $delay   Delay before the next attempt.
     * @return bool
     */
    private static function release_cron_warm_queue_claim(array $row, $message = '', $delay = 15)
    {
        global $wpdb;

        $row_id = absint($row['id'] ?? 0);
        $claim_token = sanitize_text_field((string) ($row['claim_token'] ?? ''));
        if ($row_id < 1 || '' === $claim_token || !($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return false;
        }

        $message = sanitize_textarea_field((string) $message);
        if (strlen($message) > 2000) {
            $message = substr($message, 0, 2000);
        }
        $now = time();
        $delay = max(0, min(600, absint($delay)));
        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases only the UltraCache queue row still owned by this claim token.
        $released = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET status = %s, claim_token = %s, claimed_at = %d, lease_expires_at = %d, rerun_requested = %d, result_message = %s, attempt_count = GREATEST(attempt_count - 1, 0), next_attempt_at = %d, updated_at = %d, processed_at = %d WHERE id = %d AND status = %s AND claim_token = %s",
                $table,
                'pending',
                '',
                0,
                0,
                0,
                $message,
                $now + $delay,
                $now,
                0,
                $row_id,
                'processing',
                $claim_token
            )
        );

        return 1 === (int) $released;
    }

    private static function load_cron_warm_pending_queue_rows($limit)
    {
        global $wpdb;

        $limit = max(0, min(600, absint($limit)));
        if ($limit < 1 || !self::ensure_cron_warm_queue_table()) {
            return array();
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron warm queue reads only UltraCache-owned rows.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, url, job_type, source_context, requires_verified_origin, attempt_count, next_attempt_at FROM %i WHERE status = %s AND next_attempt_at <= %d AND job_type IN (%s, %s, %s) ORDER BY CASE WHEN job_type = 'page_warm' AND source_context <> '' THEN 0 WHEN job_type = 'lcp_refresh' THEN 1 WHEN job_type = 'css_bundle' THEN 2 ELSE 3 END ASC, position ASC, id ASC LIMIT %d",
                $table,
                'pending',
                time(),
                'page_warm',
                'css_bundle',
                'lcp_refresh',
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private static function count_cron_warm_pending_queue_rows($ensure_schema = true)
    {
        global $wpdb;

        $queue_ready = $ensure_schema ? self::ensure_cron_warm_queue_table() : self::cron_warm_queue_table_read_ready();
        if (!$queue_ready) {
            return 0;
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron warm queue count reads only UltraCache-owned rows.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE status = %s AND job_type IN (%s, %s, %s)',
                $table,
                'pending',
                'page_warm',
                'css_bundle',
                'lcp_refresh'
            )
        );
    }

    private static function count_cron_warm_processing_queue_rows($ensure_schema = true)
    {
        global $wpdb;

        $queue_ready = $ensure_schema ? self::ensure_cron_warm_queue_table() : self::cron_warm_queue_table_read_ready();
        if (!$queue_ready) {
            return 0;
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Counts only actively claimed UltraCache warm queue rows.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE status = %s AND job_type IN (%s, %s, %s)',
                $table,
                'processing',
                'page_warm',
                'css_bundle',
                'lcp_refresh'
            )
        );
    }

    private static function count_pending_lcp_refresh_queue_rows()
    {
        global $wpdb;

        if (!self::ensure_cron_warm_queue_table()) {
            return 0;
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Counts only deferred UltraCache LCP refresh queue rows.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE status = %s AND job_type = %s',
                $table,
                'pending',
                'lcp_refresh'
            )
        );
    }

    private static function resume_deferred_lcp_refresh_queue()
    {
        if (self::is_manual_warmup_blocking_cron()) {
            return false;
        }

        $pending = self::count_pending_lcp_refresh_queue_rows();
        if ($pending < 1) {
            return false;
        }

        $now = time();
        $settings = self::get_settings();
        self::save_cron_warm_state(array(
            'active'            => true,
            'reason'            => 'lcp_refresh_async',
            'cursor'            => '',
            'processed'         => 0,
            'total'             => $pending,
            'successCount'      => 0,
            'errorCount'        => 0,
            'startedAt'         => $now,
            'updatedAt'         => $now,
            'lastRunAt'         => 0,
            'finishedAt'        => 0,
            'pagesPerMinute'    => max(1, (int) ($settings['cron_warm_pages_per_minute'] ?? 2)),
            'totalLimit'        => 0,
            'currentBatch'      => array(),
            'batchIndex'        => 0,
            'batchHasMore'      => false,
            'nextCursorPending' => '',
            'lastError'         => '',
            'lastMessage'       => self::maybe_translate('Deferred page-specific LCP refresh queue resumed.'),
            'lastUrl'           => '',
            'completed'         => false,
            'stopped'           => false,
            'stopReason'        => '',
            'invokedBy'         => 'browser-lcp-deferred',
        ));
        self::ensure_cron_warm_events_scheduled(1);
        return true;
    }

    private static function mark_cron_warm_queue_row_processed(array $row, $status, $message = '', $retryable = false, $result_level = '')
    {
        global $wpdb;

        $row_id = absint($row['id'] ?? 0);
        $claim_token = sanitize_text_field((string) ($row['claim_token'] ?? ''));
        if ($row_id < 1 || '' === $claim_token || !($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return array('success' => false, 'status' => 'error', 'resultLevel' => 'error', 'retrying' => false, 'leaseLost' => true);
        }

        $status = in_array((string) $status, array('done', 'skipped', 'error'), true) ? (string) $status : 'done';
        $result_level = sanitize_key((string) $result_level);
        $message = sanitize_textarea_field((string) $message);
        if (strlen($message) > 2000) {
            $message = substr($message, 0, 2000);
        }

        $attempt_count = max(1, (int) ($row['attempt_count'] ?? 1));
        $next_attempt_at = 0;
        $processed_at = time();
        $retrying = false;
        $retryable = (bool) $retryable;
        if ('error' === $status && $retryable) {
            $max_attempts = max(1, min(5, (int) apply_filters('ultracache_warm_pipeline_max_attempts', 3)));
            if ($attempt_count < $max_attempts) {
                $status = 'pending';
                $retrying = true;
                $processed_at = 0;
                $delays = array(1 => 30, 2 => 120, 3 => 300, 4 => 600);
                $next_attempt_at = time() + (int) ($delays[$attempt_count] ?? 600);
            }
        }

        if ('pending' === $status) {
            $result_level = 'retrying';
        } elseif ('error' === $status) {
            $result_level = 'error';
        } elseif ('skipped' === $status) {
            $result_level = 'skipped';
        } elseif (!in_array($result_level, array('success', 'warning'), true)) {
            $result_level = 'success';
        }

        $rerun_message = self::maybe_translate('A newer warm request arrived while this row was processing; the shared queue will run it again.');
        $table = self::get_cron_warm_queue_table_name();
        $now = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional claim token prevents a delayed worker from overwriting newer queue ownership or a requested rerun.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET status = CASE WHEN rerun_requested = %d THEN %s ELSE %s END, result_level = CASE WHEN rerun_requested = %d THEN %s ELSE %s END, claim_token = %s, claimed_at = %d, lease_expires_at = %d, pending_targets = CASE WHEN rerun_requested = %d THEN %s WHEN %s = %s THEN pending_targets ELSE %s END, result_message = CASE WHEN rerun_requested = %d THEN %s ELSE %s END, updated_at = %d, processed_at = CASE WHEN rerun_requested = %d THEN %d ELSE %d END, attempt_count = CASE WHEN rerun_requested = %d THEN %d ELSE attempt_count END, next_attempt_at = CASE WHEN rerun_requested = %d THEN %d ELSE %d END, rerun_requested = %d WHERE id = %d AND status = %s AND claim_token = %s",
                $table,
                1,
                'pending',
                $status,
                1,
                '',
                $result_level,
                '',
                0,
                0,
                1,
                '',
                $status,
                'pending',
                '',
                1,
                $rerun_message,
                $message,
                $now,
                1,
                0,
                $processed_at,
                1,
                0,
                1,
                0,
                $next_attempt_at,
                0,
                $row_id,
                'processing',
                $claim_token
            )
        );
        if (1 !== (int) $updated) {
            return array(
                'success' => false,
                'status' => 'processing',
                'resultLevel' => '',
                'retrying' => false,
                'leaseLost' => true,
                'attemptCount' => $attempt_count,
                'nextAttemptAt' => 0,
                'retryable' => $retryable,
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the authoritative state immediately after the owned claim is released.
        $saved = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT status, result_level, attempt_count, next_attempt_at, result_message FROM %i WHERE id = %d',
                $table,
                $row_id
            ),
            ARRAY_A
        );
        $saved_status = is_array($saved) ? sanitize_key((string) ($saved['status'] ?? $status)) : $status;
        $saved_result_level = is_array($saved) ? sanitize_key((string) ($saved['result_level'] ?? $result_level)) : $result_level;
        $saved_attempt_count = is_array($saved) ? max(0, (int) ($saved['attempt_count'] ?? $attempt_count)) : $attempt_count;
        $saved_next_attempt_at = is_array($saved) ? max(0, (int) ($saved['next_attempt_at'] ?? $next_attempt_at)) : $next_attempt_at;
        $requeued = 'pending' === $saved_status && 0 === $saved_attempt_count;

        return array(
            'success' => true,
            'status' => $saved_status,
            'resultLevel' => $saved_result_level,
            'retrying' => !$requeued && $retrying,
            'requeued' => $requeued,
            'leaseLost' => false,
            'attemptCount' => $saved_attempt_count,
            'nextAttemptAt' => $saved_next_attempt_at,
            'retryable' => $retryable,
        );
    }

    /**
     * Aggregate lifecycle states for the shared warm queue.
     *
     * @return array
     */
    private static function get_cron_warm_queue_lifecycle_status($ensure_schema = true)
    {
        global $wpdb;
        $status = array(
            'planned' => 0,
            'processing' => 0,
            'retrying' => 0,
            'warnings' => 0,
            'completed' => 0,
            'skipped' => 0,
            'terminalErrors' => 0,
            'failed' => 0,
        );
        $queue_ready = $ensure_schema ? self::ensure_cron_warm_queue_table() : self::cron_warm_queue_table_read_ready();
        if (!($wpdb instanceof wpdb) || !$queue_ready) {
            return $status;
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads aggregate lifecycle counters from the UltraCache-owned queue.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN status = %s AND attempt_count = %d THEN 1 ELSE 0 END) AS planned_rows,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS processing_rows,
                    SUM(CASE WHEN status = %s AND attempt_count > %d THEN 1 ELSE 0 END) AS retrying_rows,
                    SUM(CASE WHEN status = %s AND result_level = %s THEN 1 ELSE 0 END) AS warning_rows,
                    SUM(CASE WHEN status = %s AND result_level <> %s THEN 1 ELSE 0 END) AS completed_rows,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS skipped_rows,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS terminal_error_rows
                FROM %i
                WHERE job_type IN (%s, %s, %s)",
                'pending',
                0,
                'processing',
                'pending',
                0,
                'done',
                'warning',
                'done',
                'warning',
                'skipped',
                'error',
                $table,
                'page_warm',
                'css_bundle',
                'lcp_refresh'
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return $status;
        }

        $status['planned'] = max(0, (int) ($row['planned_rows'] ?? 0));
        $status['processing'] = max(0, (int) ($row['processing_rows'] ?? 0));
        $status['retrying'] = max(0, (int) ($row['retrying_rows'] ?? 0));
        $status['warnings'] = max(0, (int) ($row['warning_rows'] ?? 0));
        $status['completed'] = max(0, (int) ($row['completed_rows'] ?? 0));
        $status['skipped'] = max(0, (int) ($row['skipped_rows'] ?? 0));
        $status['terminalErrors'] = max(0, (int) ($row['terminal_error_rows'] ?? 0));
        $status['failed'] = $status['terminalErrors'];
        return $status;
    }

    private static function get_default_cron_warm_state()
    {
        return array(
            'active'       => false,
            'reason'       => '',
            'cursor'       => '',
            'processed'    => 0,
            'total'        => 0,
            'successCount' => 0,
            'skippedCount' => 0,
            'errorCount'   => 0,
            'startedAt'    => 0,
            'updatedAt'    => 0,
            'lastRunAt'    => 0,
            'finishedAt'   => 0,
            'pagesPerMinute' => 15,
            'totalLimit'   => 0,
            'currentBatch' => array(),
            'batchIndex'   => 0,
            'batchHasMore' => false,
            'nextCursorPending' => '',
            'lastError'    => '',
            'lastMessage'  => '',
            'lastUrl'      => '',
            'completed'    => false,
            'stopped'      => false,
            'stopReason'   => '',
            'invokedBy'    => '',
        );
    }

    public static function get_cron_warm_state()
    {
        $state = get_option(ULTRACACHE_CRON_WARM_STATE_KEY, array());
        if (!is_array($state)) {
            $state = array();
        }

        return array_merge(self::get_default_cron_warm_state(), $state);
    }

    private static function save_cron_warm_state(array $state)
    {
        $state = array_merge(self::get_default_cron_warm_state(), $state);
        update_option(ULTRACACHE_CRON_WARM_STATE_KEY, $state, false);
        return $state;
    }

    private static function get_manual_warm_session_lease_seconds()
    {
        $seconds = (int) apply_filters('ultracache_manual_warm_session_lease_seconds', 600);
        return max(120, min(3600, $seconds));
    }

    private static function get_default_manual_warm_state()
    {
        return array(
            'active'         => false,
            'paused'         => false,
            'interrupted'    => false,
            'jobType'        => '',
            'token'          => '',
            'ownerUserId'    => 0,
            'startedAt'      => 0,
            'updatedAt'      => 0,
            'pausedAt'       => 0,
            'leaseExpiresAt' => 0,
        );
    }

    private static function get_manual_warm_state($recover_expired = true)
    {
        $state = get_option(ULTRACACHE_MANUAL_WARM_STATE_KEY, array());
        if (!is_array($state)) {
            $state = array();
        }

        $state = array_merge(self::get_default_manual_warm_state(), $state);
        $now = time();
        $lease_expires_at = max(0, (int) ($state['leaseExpiresAt'] ?? 0));
        if (!empty($state['active']) && $lease_expires_at <= 0) {
            $last_activity_at = max(0, (int) ($state['updatedAt'] ?? 0), (int) ($state['startedAt'] ?? 0));
            if ($last_activity_at > 0) {
                $lease_expires_at = $last_activity_at + self::get_manual_warm_session_lease_seconds();
                $state['leaseExpiresAt'] = $lease_expires_at;
            }
        }
        if (!empty($state['active']) && $lease_expires_at > 0 && $lease_expires_at <= $now) {
            // Present expired ownership as interrupted even on passive status reads.
            // Persisting the release and resuming deferred queues belongs only to
            // an explicit runtime/lifecycle caller.
            $state['active'] = false;
            $state['paused'] = false;
            $state['interrupted'] = true;
            $state['updatedAt'] = $now;
            $state['leaseExpiresAt'] = 0;
            if ($recover_expired) {
                update_option(ULTRACACHE_MANUAL_WARM_STATE_KEY, $state, false);
                self::resume_deferred_lcp_refresh_queue();
                self::resume_deferred_targeted_page_warm_queue();
            }
        }

        return $state;
    }

    private static function save_manual_warm_state(array $state)
    {
        $state = array_merge(self::get_default_manual_warm_state(), $state);
        update_option(ULTRACACHE_MANUAL_WARM_STATE_KEY, $state, false);
        return $state;
    }

    public static function get_manual_warm_status()
    {
        $state = self::get_manual_warm_state(false);
        return array(
            'active'         => !empty($state['active']),
            'paused'         => !empty($state['paused']),
            'interrupted'    => !empty($state['interrupted']),
            'jobType'        => (string) $state['jobType'],
            'startedAt'      => max(0, (int) $state['startedAt']),
            'updatedAt'      => max(0, (int) $state['updatedAt']),
            'pausedAt'       => max(0, (int) $state['pausedAt']),
            'leaseExpiresAt' => max(0, (int) $state['leaseExpiresAt']),
        );
    }

    public static function is_manual_warmup_blocking_cron($recover_expired = true)
    {
        $state = self::get_manual_warm_state($recover_expired);
        return !empty($state['active']) || !empty($state['paused']);
    }

    private static function sanitize_manual_warm_job_type($job_type)
    {
        $job_type = sanitize_key((string) $job_type);
        $allowed = array(
            'warm',
            'warm_menu',
            'warm_css',
            'warm_menu_css',
            'warm_css_homepage',
            'warm_menu_css_homepage',
            'warm_css_shared',
            'warm_menu_css_shared',
            'warm_css_per_page',
            'warm_menu_css_per_page',
        );

        return in_array($job_type, $allowed, true) ? $job_type : '';
    }

    public static function begin_manual_warmup_session($job_type, $preferred_token = '')
    {
        $job_type = self::sanitize_manual_warm_job_type($job_type);
        if ('' === $job_type) {
            return array('success' => false, 'message' => self::maybe_translate('Invalid manual warm-up job type.'), 'state' => self::get_manual_warm_status());
        }

        $preferred_token = sanitize_text_field((string) $preferred_token);
        $current_user_id = get_current_user_id();
        $state = self::get_manual_warm_state();
        $state_token = (string) $state['token'];
        $same_token = '' !== $preferred_token && '' !== $state_token && hash_equals($state_token, $preferred_token);
        $same_owner = $current_user_id > 0 && $current_user_id === (int) $state['ownerUserId'];

        if (self::is_manual_warmup_blocking_cron() && !$same_token && !$same_owner) {
            return array('success' => false, 'message' => self::maybe_translate('Another administrator has an active or paused manual warm-up.'), 'state' => self::get_manual_warm_status());
        }

        $token = ($same_token || $same_owner) && '' !== $state_token
            ? $state_token
            : wp_generate_password(32, false, false);
        $now = time();

        self::save_manual_warm_state(array(
            'active'         => true,
            'paused'         => false,
            'interrupted'    => false,
            'jobType'        => $job_type,
            'token'          => $token,
            'ownerUserId'    => $current_user_id,
            'startedAt'      => !empty($state['startedAt']) && ($same_token || $same_owner) ? (int) $state['startedAt'] : $now,
            'updatedAt'      => $now,
            'pausedAt'       => 0,
            'leaseExpiresAt' => $now + self::get_manual_warm_session_lease_seconds(),
        ));
        self::stop_cron_warmup_queue('manual_warm_priority');

        return array(
            'success' => true,
            'message' => self::maybe_translate('Manual warm-up started with priority over cron warm-up.'),
            'token'   => $token,
            'state'   => self::get_manual_warm_status(),
            'cronWarm' => self::get_cron_warm_status(),
        );
    }

    public static function renew_manual_warmup_session($token)
    {
        $token = sanitize_text_field((string) $token);
        $state = self::get_manual_warm_state();
        if (
            '' === $token
            || empty($state['token'])
            || !hash_equals((string) $state['token'], $token)
            || empty($state['active'])
            || !empty($state['paused'])
        ) {
            return array('success' => false, 'message' => self::maybe_translate('Manual warm-up ownership could not be verified.'), 'state' => self::get_manual_warm_status());
        }

        $now = time();
        $state['updatedAt'] = $now;
        $state['leaseExpiresAt'] = $now + self::get_manual_warm_session_lease_seconds();
        $state['interrupted'] = false;
        self::save_manual_warm_state($state);

        return array('success' => true, 'token' => $token, 'state' => self::get_manual_warm_status());
    }

    public static function pause_manual_warmup_session($token)
    {
        $token = sanitize_text_field((string) $token);
        $state = self::get_manual_warm_state();
        if ('' === $token || empty($state['token']) || !hash_equals((string) $state['token'], $token)) {
            return array('success' => false, 'message' => self::maybe_translate('Manual warm-up ownership could not be verified.'), 'state' => self::get_manual_warm_status());
        }

        $now = time();
        $state['active'] = false;
        $state['paused'] = true;
        $state['interrupted'] = false;
        $state['updatedAt'] = $now;
        $state['pausedAt'] = $now;
        $state['leaseExpiresAt'] = 0;
        self::save_manual_warm_state($state);
        self::stop_cron_warmup_queue('manual_warm_priority');

        return array('success' => true, 'message' => self::maybe_translate('Manual warm-up paused. Cron warm-up remains blocked.'), 'token' => $token, 'state' => self::get_manual_warm_status());
    }

    public static function end_manual_warmup_session($token)
    {
        $token = sanitize_text_field((string) $token);
        $state = self::get_manual_warm_state();
        if ('' === $token || empty($state['token']) || !hash_equals((string) $state['token'], $token)) {
            return array('success' => false, 'message' => self::maybe_translate('Manual warm-up ownership could not be verified.'), 'state' => self::get_manual_warm_status());
        }

        delete_option(ULTRACACHE_MANUAL_WARM_STATE_KEY);
        $lcp_resumed = self::resume_deferred_lcp_refresh_queue();
        $targeted_resumed = self::resume_deferred_targeted_page_warm_queue();
        $resumed_labels = array();
        if ($lcp_resumed) {
            $resumed_labels[] = self::maybe_translate('deferred LCP refreshes');
        }
        if ($targeted_resumed) {
            $resumed_labels[] = self::maybe_translate('targeted purge warm pages');
        }
        $resume_message = empty($resumed_labels)
            ? self::maybe_translate('Manual warm-up ownership released.')
            : self::maybe_translate_sprintf('Manual warm-up ownership released. Resumed: %s.', implode(', ', $resumed_labels));
        return array(
            'success' => true,
            'message' => $resume_message,
            'state' => self::get_manual_warm_status(),
            'cronWarm' => self::get_cron_warm_status(),
        );
    }

    public static function reset_manual_warmup_session($reason = 'reset')
    {
        delete_option(ULTRACACHE_MANUAL_WARM_STATE_KEY);
        self::resume_deferred_targeted_page_warm_queue();
        return self::get_manual_warm_status();
    }

    public static function get_warmup_generation()
    {
        return max(0, (int) get_option('ultracache_warmup_generation', 0));
    }

    public static function bump_warmup_generation($reason = 'cache_flush')
    {
        $generation = max(0, (int) get_option('ultracache_warmup_generation', 0)) + 1;
        update_option('ultracache_warmup_generation', $generation, false);
        return $generation;
    }

    public static function reset_cron_warmup_queue_after_cache_flush($reason = 'cache_flush')
    {
        self::reset_manual_warmup_session($reason);
        $generation = self::bump_warmup_generation($reason);
        $state = self::get_default_cron_warm_state();
        $state['active'] = false;
        $state['stopped'] = true;
        $state['completed'] = false;
        $state['stopReason'] = sanitize_key((string) $reason);
        $state['finishedAt'] = time();
        $state['updatedAt'] = time();
        $state['lastMessage'] = self::maybe_translate('Cron warm up queue reset after cache flush.');
        $state['warmupGeneration'] = $generation;
        self::clear_cron_warm_queue_table();
        self::save_cron_warm_state($state);
        self::unschedule_cron_warm_events();
        return self::get_cron_warm_status();
    }

    private static function schedule_next_cron_warm_tick($delay_seconds = 5)
    {
        self::ensure_cron_warm_events_scheduled($delay_seconds);
    }

    private static function get_cron_warm_server_cron_command()
    {
        $path = untrailingslashit(ultracache_wordpress_core_root_dir());
        if ('' === $path) {
            $path = '.';
        }

        return '* * * * * cd ' . escapeshellarg($path) . ' && wp ultracache cron_warm tick --path=' . escapeshellarg($path) . ' >/dev/null 2>&1';
    }

    public static function get_cron_warm_status()
    {
        $settings = self::get_settings();
        $varnish_queue = self::get_varnish_queue_stats();
        $targeted_worker = isset($varnish_queue['refillWorker']) && is_array($varnish_queue['refillWorker'])
            ? $varnish_queue['refillWorker']
            : array('status' => 'unavailable', 'pending' => 0, 'active' => false, 'nextScheduledAt' => 0);
        $state = self::get_cron_warm_state();
        $next = self::get_next_cron_warm_scheduled_at();
        $remaining = max(0, (int) $state['total'] - (int) $state['processed']);

        return array(
            'enabled' => !empty($settings['cron_warm_enabled']),
            'startAfterCleanup' => !empty($settings['cron_warm_start_after_cleanup']),
            'startAfterManualPurge' => !empty($settings['cron_warm_start_after_manual_purge']),
            'pagesPerMinute' => max(0, (int) $settings['cron_warm_pages_per_minute']),
            'totalLimit' => max(0, (int) ($state['totalLimit'] ?: $settings['scheduled_warm_limit'])),
            'active' => !empty($state['active']),
            'processed' => max(0, (int) $state['processed']),
            'total' => max(0, (int) $state['total']),
            'remaining' => $remaining,
            'queuedPending' => self::count_cron_warm_pending_queue_rows(false),
            'queuedProcessing' => self::count_cron_warm_processing_queue_rows(false),
            'queueStatus' => self::get_cron_warm_queue_lifecycle_status(false),
            'targetedWorker' => $targeted_worker,
            'varnishQueue' => $varnish_queue,
            'queueStorage' => 'db',
            'successCount' => max(0, (int) $state['successCount']),
            'skippedCount' => max(0, (int) $state['skippedCount']),
            'errorCount' => max(0, (int) $state['errorCount']),
            'startedAt' => max(0, (int) $state['startedAt']),
            'updatedAt' => max(0, (int) $state['updatedAt']),
            'lastRunAt' => max(0, (int) $state['lastRunAt']),
            'finishedAt' => max(0, (int) $state['finishedAt']),
            'lastError' => (string) $state['lastError'],
            'lastMessage' => (string) $state['lastMessage'],
            'lastUrl' => (string) $state['lastUrl'],
            'reason' => (string) $state['reason'],
            'completed' => !empty($state['completed']),
            'stopped' => !empty($state['stopped']),
            'stopReason' => (string) $state['stopReason'],
            'invokedBy' => (string) $state['invokedBy'],
            'nextScheduledAt' => (int) $next,
            'serverCronCommand' => self::get_cron_warm_server_cron_command(),
            'warmupGeneration' => self::get_warmup_generation(),
            'blockedByManualWarm' => self::is_manual_warmup_blocking_cron(false),
            'manualWarm' => self::get_manual_warm_status(),
            'varnishWithSiteWarmup' => method_exists(static::class, 'should_include_varnish_in_site_warmup')
                ? self::should_include_varnish_in_site_warmup()
                : false,
            'varnishWarmPlan' => method_exists(static::class, 'get_site_warm_varnish_plan')
                ? self::get_site_warm_varnish_plan()
                : array('enabled' => false, 'buckets' => array()),
        );
    }

    public static function start_cron_warmup_queue($reason = 'manual', $run_immediately = false)
    {
        if (self::is_manual_warmup_blocking_cron()) {
            self::stop_cron_warmup_queue('manual_warm_priority');
            return array(
                'success' => false,
                'message' => self::maybe_translate('Cron warm up is blocked while a manual warm-up is active or paused.'),
                'state' => self::get_cron_warm_status(),
            );
        }

        $settings = self::get_settings();
        $engine = self::get_engine_instance();
        if (!$engine || !method_exists($engine, 'get_crawl_urls_cursor_batch') || !method_exists($engine, 'warm_page_pipeline')) {
            return array('success' => false, 'message' => self::maybe_translate('Cron warm up is not available.'));
        }

        $existing_state = self::get_cron_warm_state();
        $existing_updated_at = !empty($existing_state['updatedAt']) ? (int) $existing_state['updatedAt'] : 0;
        $existing_is_fresh = !empty($existing_state['active']) && empty($existing_state['completed']) && empty($existing_state['stopped']) && $existing_updated_at > (time() - 15 * MINUTE_IN_SECONDS);
        if ($existing_is_fresh) {
            return array(
                'success' => true,
                'message' => self::maybe_translate('Cron warm up is already queued or running.'),
                'state' => self::get_cron_warm_status(),
            );
        }

        $lock_token = 'start-' . gmdate('YmdHis') . '-' . wp_generate_password(12, false, false);
        if (!self::acquire_cron_warm_lock($lock_token, 60)) {
            return array(
                'success' => false,
                'message' => self::maybe_translate('Cron warm up start skipped because another warm-up operation is active.'),
                'state' => self::get_cron_warm_status(),
            );
        }

        try {
            $pages_per_minute = max(0, (int) $settings['cron_warm_pages_per_minute']);
            $total_limit = max(0, (int) $settings['scheduled_warm_limit']);
            self::clear_cron_warm_queue_table();
            $state = self::save_cron_warm_state(array(
                'active'         => true,
                'reason'         => sanitize_key((string) $reason),
                'cursor'         => '',
                'processed'      => 0,
                'total'          => 0,
                'successCount'   => 0,
                'skippedCount'   => 0,
                'errorCount'     => 0,
                'startedAt'      => time(),
                'updatedAt'      => time(),
                'lastRunAt'      => 0,
                'finishedAt'     => 0,
                'pagesPerMinute' => $pages_per_minute,
                'totalLimit'     => $total_limit,
                'currentBatch'   => array(),
                'batchIndex'     => 0,
                'batchHasMore'   => false,
                'nextCursorPending' => '',
                'lastError'      => '',
                'lastMessage'    => self::maybe_translate('Cron warm up queued.'),
                'lastUrl'        => '',
                'completed'      => false,
                'stopped'        => false,
                'stopReason'     => '',
                'invokedBy'      => '',
                'warmupGeneration' => self::get_warmup_generation(),
            ));

            self::unschedule_cron_warm_events();
            self::ensure_cron_warm_events_scheduled(1);

            return array(
                'success' => true,
                'message' => self::maybe_translate('Cron warm up queued.'),
                'state'   => self::get_cron_warm_status(),
            );
        } finally {
            self::release_cron_warm_lock($lock_token);
        }
    }

    public static function stop_cron_warmup_queue($reason = 'manual')
    {
        $state = self::get_cron_warm_state();
        $state['active'] = false;
        $state['stopped'] = true;
        $state['completed'] = false;
        $state['stopReason'] = sanitize_key((string) $reason);
        $state['finishedAt'] = time();
        $state['updatedAt'] = time();
        $state['lastMessage'] = 'manual_warm_priority' === $state['stopReason']
            ? self::maybe_translate('Cron warm up stopped because a manual warm-up has priority.')
            : self::maybe_translate('Cron warm up stopped.');
        self::clear_cron_warm_queue_table('manual_warm_priority' === $state['stopReason']);
        self::save_cron_warm_state($state);
        self::unschedule_cron_warm_events();

        return array(
            'success' => true,
            'message' => (string) $state['lastMessage'],
            'state'   => self::get_cron_warm_status(),
        );
    }

    private static function get_cron_warm_lock_name()
    {
        return ULTRACACHE_CRON_WARM_LOCK_KEY . '_atomic';
    }

    private static function acquire_cron_warm_lock($lock_token, $lock_ttl)
    {
        $lock_ttl = max(10, (int) $lock_ttl);
        $lock_token = (string) $lock_token;
        if ('' === $lock_token || !function_exists('ultracache_acquire_lock')) {
            return false;
        }

        $now = time();
        $lock = array(
            'token'     => $lock_token,
            'startedAt' => $now,
            'expiresAt' => $now + $lock_ttl,
        );

        if (!ultracache_acquire_lock(self::get_cron_warm_lock_name(), $lock_token, $lock_ttl, $lock)) {
            return false;
        }

        set_transient(ULTRACACHE_CRON_WARM_LOCK_KEY, $lock, $lock_ttl);
        return true;
    }

    private static function renew_cron_warm_lock($lock_token, $lock_ttl)
    {
        $lock_ttl = max(10, (int) $lock_ttl);
        $lock_token = (string) $lock_token;
        if ('' === $lock_token || !function_exists('ultracache_get_lock') || !function_exists('ultracache_renew_lock')) {
            return false;
        }

        $existing = ultracache_get_lock(self::get_cron_warm_lock_name());
        if (empty($existing['token']) || !hash_equals((string) $existing['token'], $lock_token)) {
            return false;
        }

        $now = time();
        $existing_payload = isset($existing['payload']) && is_array($existing['payload']) ? $existing['payload'] : array();
        $lock = array(
            'token'     => $lock_token,
            'startedAt' => !empty($existing_payload['startedAt']) ? (int) $existing_payload['startedAt'] : max(0, (int) ($existing['acquiredAt'] ?? $now)),
            'expiresAt' => $now + $lock_ttl,
        );

        if (!ultracache_renew_lock(self::get_cron_warm_lock_name(), $lock_token, $lock_ttl, $lock)) {
            return false;
        }

        set_transient(ULTRACACHE_CRON_WARM_LOCK_KEY, $lock, $lock_ttl);
        return true;
    }

    private static function release_cron_warm_lock($lock_token)
    {
        $lock_token = (string) $lock_token;
        if ('' !== $lock_token && function_exists('ultracache_release_lock')) {
            ultracache_release_lock(self::get_cron_warm_lock_name(), $lock_token);
        }

        $latest_lock = get_transient(ULTRACACHE_CRON_WARM_LOCK_KEY);
        if (is_array($latest_lock) && isset($latest_lock['token']) && hash_equals((string) $latest_lock['token'], $lock_token)) {
            delete_transient(ULTRACACHE_CRON_WARM_LOCK_KEY);
        }
    }

    public static function run_cron_warm_tick(array $args = array())
    {
        self::ensure_cron_warm_queue_table();
        self::recover_expired_cron_warm_queue_leases();
        $state = self::get_cron_warm_state();
        $manual_warm_active = self::is_manual_warmup_blocking_cron();
        $site_warm_active = !empty($state['active']);
        $run_auxiliary_varnish_work = !$manual_warm_active && !$site_warm_active;
        $varnish_refresh_ahead_run = $run_auxiliary_varnish_work && method_exists(static::class, 'maybe_run_varnish_refresh_ahead')
            ? self::maybe_run_varnish_refresh_ahead()
            : array('ran' => false, 'reason' => $run_auxiliary_varnish_work ? 'unavailable' : 'site-warm-priority');
        $varnish_queue_run = $run_auxiliary_varnish_work
            ? self::process_ready_varnish_queue_rows(100)
            : array('processed' => 0, 'reason' => 'site-warm-priority');
        // A queued invalidation batch can activate the shared targeted warm
        // pipeline while the auxiliary worker is running. Reload the state so
        // this same tick does not treat that newly activated pipeline as idle.
        $state = self::get_cron_warm_state();
        if (empty($state['active']) && method_exists(static::class, 'count_pending_targeted_page_warm_queue_rows') && self::count_pending_targeted_page_warm_queue_rows() > 0) {
            self::resume_deferred_targeted_page_warm_queue();
            $state = self::get_cron_warm_state();
        }
        if ($manual_warm_active) {
            self::stop_cron_warmup_queue('manual_warm_priority');
            return array(
                'success' => true,
                'message' => self::maybe_translate('Cron warm up skipped because a manual warm-up has priority.'),
                'warmedThisRun' => 0,
                'varnishQueue' => $varnish_queue_run,
                'varnishRefreshAhead' => $varnish_refresh_ahead_run,
                'state' => self::get_cron_warm_status(),
            );
        }

        if (empty($state['active'])) {
            $processing_total = self::count_cron_warm_processing_queue_rows();
            if ($processing_total > 0 || self::has_pending_varnish_queue_rows()) {
                self::ensure_cron_warm_events_scheduled();
                return array(
                    'success' => true,
                    'message' => self::maybe_translate('Persistent queue work is still processing or waiting for retry.'),
                    'warmedThisRun' => 0,
                    'varnishQueue' => $varnish_queue_run,
                    'varnishRefreshAhead' => $varnish_refresh_ahead_run,
                    'state' => self::get_cron_warm_status(),
                );
            }
            self::clear_cron_warm_queue_table();
            self::unschedule_cron_warm_events();
            return array(
                'success' => true,
                'message' => self::maybe_translate('Cron warm up queue is idle.'),
                'warmedThisRun' => 0,
                'varnishQueue' => $varnish_queue_run,
                'varnishRefreshAhead' => $varnish_refresh_ahead_run,
                'state' => self::get_cron_warm_status(),
            );
        }

        $lock_ttl = 90;
        $now = time();
        $lock_token = wp_generate_password(20, false, false);
        if (!self::acquire_cron_warm_lock($lock_token, $lock_ttl)) {
            return array(
                'success' => true,
                'message' => self::maybe_translate('Cron warm up tick skipped because another run is active.'),
                'warmedThisRun' => 0,
                'state' => self::get_cron_warm_status(),
            );
        }

        try {
            $settings = self::get_settings();
            $engine = self::get_engine_instance();
            if (!$engine || !method_exists($engine, 'get_crawl_urls_cursor_batch') || !method_exists($engine, 'warm_page_pipeline')) {
                $state['active'] = false;
                $state['lastError'] = 'Cron warm up engine is not available.';
                $state['lastMessage'] = $state['lastError'];
                $state['updatedAt'] = time();
                self::clear_cron_warm_queue_table();
                self::save_cron_warm_state($state);
                self::unschedule_cron_warm_events();
                return array('success' => false, 'message' => $state['lastError'], 'state' => self::get_cron_warm_status());
            }

            $state_reason = sanitize_key((string) ($state['reason'] ?? ''));
            $pages_per_minute = isset($args['pagesPerMinute']) && null !== $args['pagesPerMinute']
                ? max(0, min(600, absint($args['pagesPerMinute'])))
                : max(0, (int) ($state['pagesPerMinute'] ?: $settings['cron_warm_pages_per_minute']));
            $total_limit = isset($args['totalLimit']) && null !== $args['totalLimit']
                ? max(0, min(5000, absint($args['totalLimit'])))
                : max(0, (int) ($state['totalLimit'] ?: $settings['scheduled_warm_limit']));
            if ('targeted_purge_async' === $state_reason) {
                $pages_per_minute = max(1, min(100, (int) apply_filters('ultracache_targeted_warm_pages_per_tick', 5)));
                $total_limit = 0;
            }

            if ($pages_per_minute < 1) {
                $state['active'] = false;
                $state['completed'] = false;
                $state['stopped'] = true;
                $state['stopReason'] = 'paused';
                $state['updatedAt'] = time();
                $state['finishedAt'] = time();
                $state['pagesPerMinute'] = 0;
                $state['totalLimit'] = $total_limit;
                $state['currentBatch'] = array();
                $state['batchIndex'] = 0;
                $state['lastMessage'] = 'Cron warm up paused because pages per minute is 0.';
                self::clear_cron_warm_queue_table();
                self::save_cron_warm_state($state);
                self::unschedule_cron_warm_events();
                return array('success' => false, 'message' => $state['lastMessage'], 'warmedThisRun' => 0, 'state' => self::get_cron_warm_status());
            }

            if ($total_limit > 0 && max(0, (int) $state['processed']) >= $total_limit) {
                $state['active'] = false;
                $state['completed'] = true;
                $state['stopped'] = false;
                $state['stopReason'] = '';
                $state['finishedAt'] = time();
                $state['pagesPerMinute'] = $pages_per_minute;
                $state['totalLimit'] = $total_limit;
                $state['total'] = max(0, min((int) $state['total'], $total_limit));
                $state['currentBatch'] = array();
                $state['batchIndex'] = 0;
                $state['lastMessage'] = 'Cron warm up reached the scheduled warm limit.';
                self::clear_cron_warm_queue_table();
                self::save_cron_warm_state($state);
                self::unschedule_cron_warm_events();
                return array('success' => true, 'message' => $state['lastMessage'], 'warmedThisRun' => 0, 'state' => self::get_cron_warm_status());
            }

            $pending_rows = self::load_cron_warm_pending_queue_rows($pages_per_minute);
            $pending_total = self::count_cron_warm_pending_queue_rows();
            $processing_total = self::count_cron_warm_processing_queue_rows();
            if (empty($pending_rows) && $processing_total > 0) {
                $state['active'] = true;
                $state['completed'] = false;
                $state['stopped'] = false;
                $state['updatedAt'] = time();
                $state['lastMessage'] = self::maybe_translate_sprintf(
                    '%d warm pipeline URL(s) are currently owned by active workers.',
                    $processing_total
                );
                self::save_cron_warm_state($state);
                self::ensure_cron_warm_events_scheduled();
                return array(
                    'success' => true,
                    'message' => $state['lastMessage'],
                    'warmedThisRun' => 0,
                    'varnishQueue' => $varnish_queue_run,
                    'varnishRefreshAhead' => $varnish_refresh_ahead_run,
                    'state' => self::get_cron_warm_status(),
                );
            }
            if (empty($pending_rows) && $pending_total > 0) {
                $state['active'] = true;
                $state['completed'] = false;
                $state['stopped'] = false;
                $state['updatedAt'] = time();
                $state['lastMessage'] = self::maybe_translate_sprintf(
                    '%d warm pipeline URL(s) are waiting for their bounded retry delay.',
                    $pending_total
                );
                self::save_cron_warm_state($state);
                self::ensure_cron_warm_events_scheduled();
                return array(
                    'success' => true,
                    'message' => $state['lastMessage'],
                    'warmedThisRun' => 0,
                    'varnishQueue' => $varnish_queue_run,
                    'varnishRefreshAhead' => $varnish_refresh_ahead_run,
                    'state' => self::get_cron_warm_status(),
                );
            }
            if (empty($pending_rows) && in_array($state_reason, array('css_bundle_async', 'lcp_refresh_async', 'targeted_purge_async'), true)) {
                $state['active'] = false;
                $state['completed'] = true;
                $state['stopped'] = false;
                $state['stopReason'] = '';
                $state['finishedAt'] = time();
                $state['updatedAt'] = time();
                $state['lastMessage'] = self::maybe_translate('lcp_refresh_async' === $state_reason ? 'Page-specific LCP refresh queue complete.' : ('targeted_purge_async' === $state_reason ? 'Targeted purge warm queue complete.' : 'Async CSS bundle queue complete.'));
                self::clear_cron_warm_queue_table();
                self::save_cron_warm_state($state);
                self::unschedule_cron_warm_events();
                return array('success' => true, 'message' => $state['lastMessage'], 'warmedThisRun' => 0, 'state' => self::get_cron_warm_status());
            }

            if (empty($pending_rows)) {
                $remaining_budget = $total_limit > 0 ? max(0, $total_limit - max(0, (int) $state['processed'])) : 0;
                if ($total_limit > 0 && $remaining_budget < 1) {
                    $state['active'] = false;
                    $state['completed'] = true;
                    $state['stopped'] = false;
                    $state['stopReason'] = '';
                    $state['finishedAt'] = time();
                    $state['pagesPerMinute'] = $pages_per_minute;
                    $state['totalLimit'] = $total_limit;
                    $state['total'] = max(0, min((int) $state['total'], $total_limit));
                    $state['currentBatch'] = array();
                    $state['batchIndex'] = 0;
                    $state['lastMessage'] = 'Cron warm up reached the scheduled warm limit.';
                    self::clear_cron_warm_queue_table();
                    self::save_cron_warm_state($state);
                    self::unschedule_cron_warm_events();
                    return array('success' => true, 'message' => $state['lastMessage'], 'warmedThisRun' => 0, 'state' => self::get_cron_warm_status());
                }

                self::clear_cron_warm_queue_table();
                $batch_limit = $total_limit > 0 ? min($pages_per_minute, $remaining_budget) : $pages_per_minute;
                $batch = $engine->get_crawl_urls_cursor_batch((string) $state['cursor'], $batch_limit);
                $items = isset($batch['items']) && is_array($batch['items']) ? array_values($batch['items']) : array();
                $inserted = self::insert_cron_warm_queue_urls($items, max(0, (int) $state['processed']));
                $state['currentBatch'] = array();
                $state['batchIndex'] = 0;
                $state['batchHasMore'] = !empty($batch['hasMore']);
                $state['nextCursorPending'] = !empty($batch['nextCursor']) ? (string) $batch['nextCursor'] : '';
                $state['total'] = max((int) $state['total'], (int) ($batch['total'] ?? 0));
                if ($total_limit > 0) {
                    $state['total'] = max(0, min((int) $state['total'], $total_limit));
                }
                $state['pagesPerMinute'] = $pages_per_minute;
                $state['totalLimit'] = $total_limit;
                $state['lastRunAt'] = $now;
                $state['updatedAt'] = $now;
                $state['invokedBy'] = !empty($args['invokedBy']) ? sanitize_key((string) $args['invokedBy']) : '';
                $state['lastMessage'] = $inserted < 1 ? 'No eligible URLs found for this cron warm tick.' : 'Cron warm up running.';
                self::save_cron_warm_state($state);
                $pending_rows = self::load_cron_warm_pending_queue_rows($pages_per_minute);
            } else {
                $state['currentBatch'] = array();
                $state['batchIndex'] = 0;
                $state['pagesPerMinute'] = $pages_per_minute;
                $state['totalLimit'] = $total_limit;
                $state['lastRunAt'] = $now;
                $state['updatedAt'] = $now;
                $state['invokedBy'] = !empty($args['invokedBy']) ? sanitize_key((string) $args['invokedBy']) : '';
                self::save_cron_warm_state($state);
            }

            $operation_budget = function_exists('ultracache_get_safe_operation_budget') ? ultracache_get_safe_operation_budget('cron_warm', null, 45) : array();
            $warmed = 0;
            $errors = 0;
            $last_error = (string) $state['lastError'];
            $last_url = (string) $state['lastUrl'];
            $state_save_every = (int) apply_filters('ultracache_cron_warm_state_save_interval_urls', 10);
            $state_save_every = max(1, min(100, $state_save_every));
            $state_save_seconds = (float) apply_filters('ultracache_cron_warm_state_save_interval_seconds', 3);
            $state_save_seconds = max(0.5, min(15, $state_save_seconds));
            $last_state_save_at = microtime(true);
            $handled_this_run = 0;
            $pending_total_this_run = count($pending_rows);

            foreach ($pending_rows as $row) {
                if (self::is_manual_warmup_blocking_cron()) {
                    self::stop_cron_warmup_queue('manual_warm_priority');
                    return array(
                        'success' => true,
                        'message' => self::maybe_translate('Cron warm up stopped because a manual warm-up has priority.'),
                        'warmedThisRun' => $warmed,
                        'state' => self::get_cron_warm_status(),
                    );
                }

                $budget_pause_reason = function_exists('ultracache_operation_pause_reason') ? ultracache_operation_pause_reason($operation_budget) : '';
                if ('' !== $budget_pause_reason) {
                    $state['lastMessage'] = 'Cron warm paused by ' . $budget_pause_reason . '; it will resume on the next tick.';
                    break;
                }
                $row = self::claim_cron_warm_queue_row($row);
                if (empty($row)) {
                    continue;
                }
                $row_id = isset($row['id']) ? absint($row['id']) : 0;
                $url = isset($row['url']) ? (string) $row['url'] : '';
                $job_type = isset($row['job_type']) && in_array((string) $row['job_type'], array('page_warm', 'css_bundle', 'lcp_refresh'), true) ? (string) $row['job_type'] : 'page_warm';
                if ($row_id < 1 || '' === $url) {
                    self::release_cron_warm_queue_claim($row, self::maybe_translate('The claimed queue row did not contain a valid URL.'), 30);
                    continue;
                }

                $last_url = $url;
                $state_reason = sanitize_key((string) ($state['reason'] ?? ''));
                $row_source_context = sanitize_key((string) ($row['source_context'] ?? ''));
                $is_targeted_warm = 'page_warm' === $job_type && '' !== $row_source_context;
                if ($is_targeted_warm) {
                    $warm_context = 'refresh-ahead' === $row_source_context ? 'refresh-ahead' : 'targeted-purge';
                } else {
                    $warm_context = in_array($state_reason, array('manual_purge', 'scheduled_cleanup'), true)
                        ? ('scheduled_cleanup' === $state_reason ? 'scheduled-cleanup' : 'warm-after-flush')
                        : ('cli' === $state_reason ? 'cli' : 'cron');
                }
                $include_varnish = $is_targeted_warm
                    || ('lcp_refresh' !== $job_type
                        && method_exists(static::class, 'should_include_varnish_in_site_warmup')
                        && self::should_include_varnish_in_site_warmup());
                $queue_lease_renewed_at = time();
                $queue_lease_renew_interval = max(15, min(60, (int) floor(self::get_cron_warm_queue_lease_seconds() / 3)));
                $warm_args = array(
                    'ignore_runtime_bypass' => true,
                    'include_varnish' => $include_varnish,
                    'warm_context' => $warm_context,
                    'time_budget' => 20,
                    '_queue_lease_heartbeat' => static function ($stage = '') use ($row, &$queue_lease_renewed_at, $queue_lease_renew_interval) {
                        unset($stage);
                        $now = time();
                        if (($now - $queue_lease_renewed_at) < $queue_lease_renew_interval) {
                            return true;
                        }
                        $renewed = self::renew_cron_warm_queue_claim($row);
                        if ($renewed) {
                            $queue_lease_renewed_at = $now;
                        }
                        return $renewed;
                    },
                );
                if ('css_bundle' === $job_type) {
                    $warm_args['build_css_bundle'] = true;
                } elseif ('lcp_refresh' === $job_type) {
                    $warm_args['force_refresh'] = true;
                    $warm_args['skip_css_bundle'] = true;
                } elseif ($is_targeted_warm) {
                    $warm_args['force_refresh'] = true;
                    $warm_args['skip_css_bundle'] = true;
                    $warm_args['requires_verified_origin'] = !empty($row['requires_verified_origin']);
                }

                $refresh_ahead_preparation = null;
                if ('refresh-ahead' === $row_source_context && method_exists(static::class, 'prepare_varnish_refresh_ahead_page_warm')) {
                    $refresh_ahead_preparation = self::prepare_varnish_refresh_ahead_page_warm(
                        $url,
                        $row,
                        $warm_args['_queue_lease_heartbeat']
                    );
                }

                if (is_array($refresh_ahead_preparation) && empty($refresh_ahead_preparation['proceed'])) {
                    $preparation_message = (string) ($refresh_ahead_preparation['message'] ?? self::maybe_translate('Refresh-ahead preparation did not allow the page pipeline to continue.'));
                    $result = array(
                        'success' => false,
                        'skipped' => !empty($refresh_ahead_preparation['skipped']),
                        'retryable' => !empty($refresh_ahead_preparation['retryable']),
                        'terminal' => empty($refresh_ahead_preparation['retryable']),
                        'ownershipLost' => !empty($refresh_ahead_preparation['ownershipLost']),
                        'failureClass' => !empty($refresh_ahead_preparation['ownershipLost'])
                            ? 'ownership-lost'
                            : (!empty($refresh_ahead_preparation['skipped']) ? 'refresh-ahead-no-longer-eligible' : 'refresh-ahead-soft-purge'),
                        'message' => $preparation_message,
                        'pipeline' => array(
                            'status' => !empty($refresh_ahead_preparation['skipped']) ? 'skipped' : 'failed',
                            'message' => $preparation_message,
                        ),
                        'refreshAheadPreparation' => $refresh_ahead_preparation,
                    );
                } else {
                    $result = $engine->warm_page_pipeline($url, $warm_args);
                    if (is_array($refresh_ahead_preparation)) {
                        $result['refreshAheadPreparation'] = $refresh_ahead_preparation;
                    }
                }
                if (
                    'page_warm' === $job_type
                    && '' !== $row_source_context
                    && 'canonical-redirect' === sanitize_key((string) ($result['failureClass'] ?? ''))
                    && !empty($result['redirectUrl'])
                    && method_exists(static::class, 'normalize_varnish_invalidation_url')
                ) {
                    $normalized_redirect = self::normalize_varnish_invalidation_url((string) $result['redirectUrl']);
                    $canonical_redirect_url = !empty($normalized_redirect['valid']) && !empty($normalized_redirect['url'])
                        ? esc_url_raw((string) $normalized_redirect['url'])
                        : '';
                    if ('' !== $canonical_redirect_url && !hash_equals($url, $canonical_redirect_url)) {
                        $canonical_eligibility = $engine->get_warm_pipeline_eligibility($canonical_redirect_url);
                        if (!empty($canonical_eligibility['eligible'])) {
                            $redirect_urls = array();
                            $redirect_enqueue_summary = array();
                            $redirect_source = $row_source_context;
                            $redirect_accepted = self::insert_cron_warm_queue_urls(
                                array($canonical_redirect_url),
                                0,
                                'page_warm',
                                $redirect_source,
                                !empty($row['requires_verified_origin']),
                                $redirect_urls,
                                $redirect_enqueue_summary
                            );
                            if ($redirect_accepted > 0 && !empty($redirect_urls)) {
                                $redirect_inserted = max(0, (int) ($redirect_enqueue_summary['inserted'] ?? 0));
                                if ($redirect_inserted > 0) {
                                    $state['total'] = max(0, (int) ($state['total'] ?? 0)) + $redirect_inserted;
                                    if (!empty($state['totalLimit'])) {
                                        $state['totalLimit'] = max(0, (int) $state['totalLimit']) + $redirect_inserted;
                                    }
                                }
                                self::ensure_cron_warm_events_scheduled(1);
                                $result = array(
                                    'success' => false,
                                    'skipped' => true,
                                    'retryable' => false,
                                    'terminal' => true,
                                    'warning' => true,
                                    'failureClass' => 'canonical-redirect-requeued',
                                    'redirectUrl' => $canonical_redirect_url,
                                    'redirectQueued' => true,
                                    'message' => self::maybe_translate_sprintf(
                                        'The redirected URL was replaced with its verified local canonical target: %s',
                                        $canonical_redirect_url
                                    ),
                                    'pipeline' => array(
                                        'status' => 'skipped',
                                        'message' => self::maybe_translate('The non-canonical queue row was replaced by a canonical page-warm row.'),
                                    ),
                                );
                            }
                        }
                    }
                }

                $result_message = !empty($result['message']) ? (string) $result['message'] : 'OK';
                if (!empty($result['pipeline']['status'])) {
                    $result_message = strtoupper(sanitize_key((string) $result['pipeline']['status'])) . ': ' . $result_message;
                }

                if (!empty($result['coalesced'])) {
                    $state['lastRunAt'] = time();
                    $state['updatedAt'] = time();
                    $state['lastUrl'] = $last_url;
                    $state['lastMessage'] = self::maybe_translate('The current URL is already owned by another warm-up source; the shared queue will retry it on the next tick.');
                    self::release_cron_warm_queue_claim($row, $state['lastMessage'], 15);
                    self::save_cron_warm_state($state);
                    break;
                }

                $terminal = true;
                $result_was_success = !empty($result['success']);
                $result_was_skipped = !$result_was_success && !empty($result['skipped']);
                if ($result_was_success) {
                    $attempt_result = self::mark_cron_warm_queue_row_processed($row, 'done', $result_message, false, !empty($result['warning']) ? 'warning' : 'success');
                } elseif ($result_was_skipped) {
                    $attempt_result = self::mark_cron_warm_queue_row_processed($row, 'skipped', $result_message);
                } else {
                    $last_error = $result_message;
                    $attempt_result = self::mark_cron_warm_queue_row_processed($row, 'error', $last_error, !empty($result['retryable']));
                }

                if (!empty($attempt_result['leaseLost'])) {
                    $terminal = false;
                    $state['lastMessage'] = self::maybe_translate('The queue worker lost ownership before its result could be saved; the authoritative row was left unchanged.');
                } elseif (!empty($attempt_result['requeued'])) {
                    $terminal = false;
                    $state['lastMessage'] = self::maybe_translate('A newer request arrived while this URL was processing; the shared queue will run it again.');
                } elseif ($result_was_success) {
                    $warmed++;
                    $state['successCount'] = (int) $state['successCount'] + 1;
                    if ('lcp_refresh' === $job_type) {
                        update_option('ultracache_lcp_last_refresh', array(
                            'url'       => esc_url_raw($url),
                            'timestamp' => time(),
                            'message'   => sanitize_text_field($result_message),
                        ), false);
                    }
                } elseif ($result_was_skipped) {
                    $state['skippedCount'] = (int) $state['skippedCount'] + 1;
                } elseif (!empty($attempt_result['retrying'])) {
                    $terminal = false;
                    $state['lastMessage'] = self::maybe_translate_sprintf(
                        'Warm pipeline retry %1$d scheduled for %2$s.',
                        max(1, (int) ($attempt_result['attemptCount'] ?? 1)),
                        esc_url_raw($url)
                    );
                } else {
                    $errors++;
                    $state['errorCount'] = (int) $state['errorCount'] + 1;
                }

                if ($terminal) {
                    $handled_this_run++;
                    $state['processed'] = max(0, (int) $state['processed']) + 1;
                }
                $state['batchIndex'] = $handled_this_run;
                $state['lastRunAt'] = time();
                $state['updatedAt'] = time();
                $state['lastError'] = (string) $last_error;
                $state['lastUrl'] = $last_url;
                $state['currentBatch'] = array();
                if ($terminal) {
                    $state['lastMessage'] = sprintf('Processed %d/%d URL(s) in the current cron warm DB batch.', $handled_this_run, $pending_total_this_run);
                }
                if (0 === ($handled_this_run % $state_save_every) || microtime(true) - $last_state_save_at >= $state_save_seconds) {
                    self::save_cron_warm_state($state);
                    $last_state_save_at = microtime(true);
                }

                self::renew_cron_warm_lock($lock_token, $lock_ttl);
            }

            $completed = false;
            $pending_after = self::count_cron_warm_pending_queue_rows();
            $processing_after = self::count_cron_warm_processing_queue_rows();
            if ($pending_after < 1 && $processing_after < 1) {
                if (!empty($state['batchHasMore']) && !empty($state['nextCursorPending'])) {
                    self::clear_cron_warm_queue_table();
                    $state['cursor'] = (string) $state['nextCursorPending'];
                    $state['currentBatch'] = array();
                    $state['batchIndex'] = 0;
                    $state['batchHasMore'] = false;
                    $state['nextCursorPending'] = '';
                    $state['active'] = true;
                    $state['completed'] = false;
                    $state['stopped'] = false;
                    $state['stopReason'] = '';
                    $state['updatedAt'] = time();
                    $remaining_after = max(0, (int) $state['total'] - (int) $state['processed']);
                    $state['lastMessage'] = $handled_this_run > 0 ? sprintf('Warmed %d URL(s) this tick. %d remaining.', $warmed, $remaining_after) : 'Advanced cron warm queue to the next batch.';
                    self::save_cron_warm_state($state);
                    self::ensure_cron_warm_events_scheduled();
                } else {
                    $completed = true;
                }
            } else {
                self::save_cron_warm_state($state);
                self::ensure_cron_warm_events_scheduled();
            }

            if ($completed) {
                self::clear_cron_warm_queue_table();
                $state['active'] = false;
                $state['completed'] = true;
                $state['stopped'] = false;
                $state['stopReason'] = '';
                $state['finishedAt'] = time();
                $state['currentBatch'] = array();
                $state['batchIndex'] = 0;
                $state['batchHasMore'] = false;
                $state['nextCursorPending'] = '';
                $completion_reason = sanitize_key((string) ($state['reason'] ?? ''));
                if ('lcp_refresh_async' === $completion_reason) {
                    $state['lastMessage'] = 'Page-specific LCP refresh warm complete.';
                } elseif ('css_bundle_async' === $completion_reason) {
                    $state['lastMessage'] = 'Async CSS bundle queue complete.';
                } elseif ('targeted_purge_async' === $completion_reason) {
                    $state['lastMessage'] = 'Targeted purge warm queue complete.';
                } else {
                    $state['lastMessage'] = $warmed > 0 || $state['processed'] > 0 ? 'Cron warm up complete.' : 'Cron warm up queue completed with no eligible URLs.';
                }
                self::save_cron_warm_state($state);
                self::unschedule_cron_warm_events();
            }

            return array(
                'success' => true,
                'message' => $state['lastMessage'],
                'warmedThisRun' => $warmed,
                'errorsThisRun' => $errors,
                'varnishQueue' => $varnish_queue_run,
                'varnishRefreshAhead' => $varnish_refresh_ahead_run,
                'state' => self::get_cron_warm_status(),
            );
        } finally {
            self::release_cron_warm_lock($lock_token);
        }
    }


}
