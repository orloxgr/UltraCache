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
        return '4';
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

    public static function ensure_cron_warm_queue_table()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return false;
        }

        $table = self::get_cron_warm_queue_table_name();
        $version = (string) get_option(self::get_cron_warm_queue_db_version_option_key(), '');
        if (self::get_cron_warm_queue_db_version() === $version && self::cron_warm_queue_table_exists()) {
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
            job_type varchar(32) NOT NULL DEFAULT 'warm',
            position bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
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
            KEY updated_at (updated_at),
            KEY processed_at (processed_at)
        ) {$charset_collate};";

        dbDelta($sql);
        wp_cache_delete('ultracache_cron_warm_queue_table_exists_' . md5((string) $table), 'ultracache');
        if (self::cron_warm_queue_table_exists()) {
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
                    'DELETE FROM %i WHERE job_type NOT IN (%s, %s, %s)',
                    $table,
                    'lcp_refresh',
                    'varnish_invalidate',
                    'varnish_refill'
                )
            );
            return true;
        }

        // Persistent Varnish invalidation/refill rows survive ordinary warm resets,
        // batch transitions, manual warm priority, and page-cache flushes.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes only ordinary UltraCache warm queue rows.
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE job_type NOT IN (%s, %s)',
                $table,
                'varnish_invalidate',
                'varnish_refill'
            )
        );
        return true;
    }

    private static function insert_cron_warm_queue_urls(array $urls, $base_position = 0, $job_type = 'warm')
    {
        global $wpdb;

        if (empty($urls) || !self::ensure_cron_warm_queue_table()) {
            return 0;
        }

        $table = self::get_cron_warm_queue_table_name();
        $now = time();
        $base_position = max(0, (int) $base_position);
        $job_type = in_array((string) $job_type, array('warm', 'css_bundle', 'lcp_refresh', 'varnish_invalidate', 'varnish_refill'), true) ? (string) $job_type : 'warm';
        $inserted = 0;

        foreach ($urls as $url) {
            $url = is_string($url) ? trim($url) : '';
            if ('' === $url) {
                continue;
            }

            $url = function_exists('esc_url_raw') ? esc_url_raw($url) : $url;
            if ('' === $url) {
                continue;
            }

            $hash = sha1($url);
            $position = in_array($job_type, array('varnish_invalidate', 'varnish_refill', 'lcp_refresh'), true) ? 0 : $base_position + $inserted + 1;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron warm queue writes only UltraCache-owned rows.
            $result = $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO %i (url_hash, url, job_type, position, status, result_message, created_at, updated_at, processed_at, attempt_count, next_attempt_at) VALUES (%s, %s, %s, %d, %s, %s, %d, %d, %d, %d, %d) ON DUPLICATE KEY UPDATE url = VALUES(url), job_type = VALUES(job_type), position = VALUES(position), status = VALUES(status), result_message = VALUES(result_message), updated_at = VALUES(updated_at), processed_at = VALUES(processed_at), attempt_count = VALUES(attempt_count), next_attempt_at = VALUES(next_attempt_at)',
                    $table,
                    $hash,
                    $url,
                    $job_type,
                    $position,
                    'pending',
                    '',
                    $now,
                    $now,
                    0,
                    0,
                    0
                )
            );
            if (false !== $result && $result > 0) {
                $inserted++;
            }
        }

        return $inserted;
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
                "SELECT id, url, job_type FROM %i WHERE status = %s AND job_type IN (%s, %s, %s) ORDER BY CASE job_type WHEN 'lcp_refresh' THEN 0 WHEN 'css_bundle' THEN 1 ELSE 2 END ASC, position ASC, id ASC LIMIT %d",
                $table,
                'pending',
                'warm',
                'css_bundle',
                'lcp_refresh',
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private static function count_cron_warm_pending_queue_rows()
    {
        global $wpdb;

        if (!self::ensure_cron_warm_queue_table()) {
            return 0;
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron warm queue count reads only UltraCache-owned rows.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE status = %s AND job_type IN (%s, %s, %s)',
                $table,
                'pending',
                'warm',
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

    private static function mark_cron_warm_queue_row_processed($row_id, $status, $message = '')
    {
        global $wpdb;

        $row_id = absint($row_id);
        if ($row_id < 1 || !self::ensure_cron_warm_queue_table()) {
            return false;
        }

        $status = in_array((string) $status, array('done', 'error'), true) ? (string) $status : 'done';
        $message = sanitize_textarea_field((string) $message);
        if (strlen($message) > 2000) {
            $message = substr($message, 0, 2000);
        }

        $table = self::get_cron_warm_queue_table_name();
        $now = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron warm queue updates only UltraCache-owned rows.
        return false !== $wpdb->update(
            $table,
            array(
                'status' => $status,
                'result_message' => $message,
                'updated_at' => $now,
                'processed_at' => $now,
            ),
            array('id' => $row_id),
            array('%s', '%s', '%d', '%d'),
            array('%d')
        );
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

    private static function get_default_manual_warm_state()
    {
        return array(
            'active'      => false,
            'paused'      => false,
            'jobType'     => '',
            'token'       => '',
            'ownerUserId' => 0,
            'startedAt'   => 0,
            'updatedAt'   => 0,
            'pausedAt'    => 0,
        );
    }

    private static function get_manual_warm_state()
    {
        $state = get_option(ULTRACACHE_MANUAL_WARM_STATE_KEY, array());
        if (!is_array($state)) {
            $state = array();
        }

        return array_merge(self::get_default_manual_warm_state(), $state);
    }

    private static function save_manual_warm_state(array $state)
    {
        $state = array_merge(self::get_default_manual_warm_state(), $state);
        update_option(ULTRACACHE_MANUAL_WARM_STATE_KEY, $state, false);
        return $state;
    }

    public static function get_manual_warm_status()
    {
        $state = self::get_manual_warm_state();
        return array(
            'active'    => !empty($state['active']),
            'paused'    => !empty($state['paused']),
            'jobType'   => (string) $state['jobType'],
            'startedAt' => max(0, (int) $state['startedAt']),
            'updatedAt' => max(0, (int) $state['updatedAt']),
            'pausedAt'  => max(0, (int) $state['pausedAt']),
        );
    }

    public static function is_manual_warmup_blocking_cron()
    {
        $state = self::get_manual_warm_state();
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
            'active'      => true,
            'paused'      => false,
            'jobType'     => $job_type,
            'token'       => $token,
            'ownerUserId' => $current_user_id,
            'startedAt'   => !empty($state['startedAt']) && ($same_token || $same_owner) ? (int) $state['startedAt'] : $now,
            'updatedAt'   => $now,
            'pausedAt'    => 0,
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
        $state['updatedAt'] = $now;
        $state['pausedAt'] = $now;
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
        $varnish_resumed = self::resume_pending_varnish_queue();
        $resume_message = self::maybe_translate('Manual warm-up ownership released.');
        if ($lcp_resumed && $varnish_resumed) {
            $resume_message = self::maybe_translate('Manual warm-up ownership released. Deferred LCP refreshes and Varnish jobs resumed.');
        } elseif ($lcp_resumed) {
            $resume_message = self::maybe_translate('Manual warm-up ownership released. Deferred LCP refreshes resumed.');
        } elseif ($varnish_resumed) {
            $resume_message = self::maybe_translate('Manual warm-up ownership released. Pending Varnish jobs resumed.');
        }
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
        self::resume_pending_varnish_queue();
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
            'queuedPending' => self::count_cron_warm_pending_queue_rows(),
            'varnishQueue' => self::get_varnish_queue_stats(),
            'queueStorage' => 'db',
            'successCount' => max(0, (int) $state['successCount']),
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
            'blockedByManualWarm' => self::is_manual_warmup_blocking_cron(),
            'manualWarm' => self::get_manual_warm_status(),
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
        if (!$engine || !method_exists($engine, 'get_crawl_urls_cursor_batch') || !method_exists($engine, 'warm_url')) {
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
        $varnish_refresh_ahead_run = method_exists(static::class, 'maybe_run_varnish_refresh_ahead')
            ? self::maybe_run_varnish_refresh_ahead()
            : array('ran' => false, 'reason' => 'unavailable');
        $varnish_queue_run = self::process_ready_varnish_queue_rows(100);
        if (self::is_manual_warmup_blocking_cron()) {
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

        $state = self::get_cron_warm_state();
        if (empty($state['active'])) {
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
            if (!$engine || !method_exists($engine, 'get_crawl_urls_cursor_batch') || !method_exists($engine, 'warm_url')) {
                $state['active'] = false;
                $state['lastError'] = 'Cron warm up engine is not available.';
                $state['lastMessage'] = $state['lastError'];
                $state['updatedAt'] = time();
                self::clear_cron_warm_queue_table();
                self::save_cron_warm_state($state);
                self::unschedule_cron_warm_events();
                return array('success' => false, 'message' => $state['lastError'], 'state' => self::get_cron_warm_status());
            }

            $pages_per_minute = isset($args['pagesPerMinute']) && null !== $args['pagesPerMinute']
                ? max(0, min(600, absint($args['pagesPerMinute'])))
                : max(0, (int) ($state['pagesPerMinute'] ?: $settings['cron_warm_pages_per_minute']));
            $total_limit = isset($args['totalLimit']) && null !== $args['totalLimit']
                ? max(0, min(5000, absint($args['totalLimit'])))
                : max(0, (int) ($state['totalLimit'] ?: $settings['scheduled_warm_limit']));

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
            $state_reason = sanitize_key((string) ($state['reason'] ?? ''));
            if (empty($pending_rows) && in_array($state_reason, array('css_bundle_async', 'lcp_refresh_async'), true)) {
                $state['active'] = false;
                $state['completed'] = true;
                $state['stopped'] = false;
                $state['stopReason'] = '';
                $state['finishedAt'] = time();
                $state['updatedAt'] = time();
                $state['lastMessage'] = self::maybe_translate('lcp_refresh_async' === $state_reason ? 'Page-specific LCP refresh queue complete.' : 'Async CSS bundle queue complete.');
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
                $row_id = isset($row['id']) ? absint($row['id']) : 0;
                $url = isset($row['url']) ? (string) $row['url'] : '';
                $job_type = isset($row['job_type']) && in_array((string) $row['job_type'], array('warm', 'css_bundle', 'lcp_refresh'), true) ? (string) $row['job_type'] : 'warm';
                if ($row_id < 1 || '' === $url) {
                    continue;
                }

                $last_url = $url;
                $warm_args = array('ignore_runtime_bypass' => true);
                if ('css_bundle' === $job_type) {
                    $warm_args['build_css_bundle'] = true;
                } elseif ('lcp_refresh' === $job_type) {
                    $warm_args['force_refresh'] = true;
                    $warm_args['skip_css_bundle'] = true;
                }
                $warm_args['time_budget'] = 20;
                $result = $engine->warm_url($url, $warm_args);
                if (!empty($result['success'])) {
                    $warmed++;
                    $state['successCount'] = (int) $state['successCount'] + 1;
                    self::mark_cron_warm_queue_row_processed($row_id, 'done', !empty($result['message']) ? (string) $result['message'] : 'OK');
                    if ('lcp_refresh' === $job_type) {
                        update_option('ultracache_lcp_last_refresh', array(
                            'url'       => esc_url_raw($url),
                            'timestamp' => time(),
                            'message'   => sanitize_text_field(!empty($result['message']) ? (string) $result['message'] : 'OK'),
                        ), false);
                    }
                } else {
                    $errors++;
                    $state['errorCount'] = (int) $state['errorCount'] + 1;
                    if (!empty($result['message'])) {
                        $last_error = (string) $result['message'];
                    }
                    self::mark_cron_warm_queue_row_processed($row_id, 'error', $last_error);
                }

                $handled_this_run++;
                $state['batchIndex'] = $handled_this_run;
                $state['processed'] = max(0, (int) $state['processed']) + 1;
                $state['lastRunAt'] = time();
                $state['updatedAt'] = time();
                $state['lastError'] = (string) $last_error;
                $state['lastUrl'] = $last_url;
                $state['currentBatch'] = array();
                $state['lastMessage'] = sprintf('Processed %d/%d URL(s) in the current cron warm DB batch.', $handled_this_run, $pending_total_this_run);
                if (0 === ($handled_this_run % $state_save_every) || microtime(true) - $last_state_save_at >= $state_save_seconds) {
                    self::save_cron_warm_state($state);
                    $last_state_save_at = microtime(true);
                }

                self::renew_cron_warm_lock($lock_token, $lock_ttl);
            }

            $completed = false;
            $pending_after = self::count_cron_warm_pending_queue_rows();
            if ($pending_after < 1) {
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
