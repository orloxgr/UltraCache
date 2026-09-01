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
        return '17';
    }

    private static function get_cron_warm_queue_db_version_option_key()
    {
        return 'ultracache_cron_warm_queue_db_version';
    }

    private static function get_cron_warm_full_site_context_marker()
    {
        return 'scheduled-full-site';
    }

    private static function get_cron_warm_full_site_completed_context_marker()
    {
        return 'scheduled-full-site-complete';
    }

    /**
     * Return all URL hashes already selected by the active full-site plan.
     *
     * Full-site discovery is cursor-based and can encounter the same URL again
     * through homepage, menu, post, and taxonomy sources. Persisted membership
     * in the canonical queue provides exact cross-batch deduplication without
     * carrying thousands of hashes inside the cursor option.
     *
     * @return array<string,string>
     */
    private static function get_cron_warm_full_site_member_hash_lookup()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return array();
        }

        $table = self::get_cron_warm_queue_table_name();
        $marker = self::get_cron_warm_full_site_context_marker();
        $completed_marker = self::get_cron_warm_full_site_completed_context_marker();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads exact membership from the UltraCache-owned canonical queue.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT url_hash, source_contexts FROM %i WHERE job_type = %s AND (FIND_IN_SET(%s, source_contexts) > 0 OR FIND_IN_SET(%s, source_contexts) > 0)',
                $table,
                'page_warm',
                $marker,
                $completed_marker
            ),
            ARRAY_A
        );

        $lookup = array();
        foreach ((array) $rows as $row) {
            $hash = strtolower(trim((string) ($row['url_hash'] ?? '')));
            if (40 !== strlen($hash)) {
                continue;
            }
            $contexts = self::normalize_cron_warm_queue_csv((string) ($row['source_contexts'] ?? ''));
            $context_list = '' === $contexts ? array() : explode(',', $contexts);
            $lookup[$hash] = in_array($completed_marker, $context_list, true)
                ? $completed_marker
                : $marker;
        }

        return $lookup;
    }

    /**
     * Return exact selected/processed outcome counts for the active full-site plan.
     *
     * Canonical rows can reach a terminal state through the cron worker or a
     * real frontend visit. Persisted membership markers are therefore the
     * authoritative source for full-site progress, not only the worker option.
     *
     * @param bool $ensure_schema Whether schema creation/upgrades may run.
     * @return array{ready:bool,selected:int,processed:int,success:int,skipped:int,error:int}
     */
    private static function get_cron_warm_full_site_membership_counts($ensure_schema = true)
    {
        global $wpdb;

        $queue_ready = $ensure_schema ? self::ensure_cron_warm_queue_table() : self::cron_warm_queue_table_read_ready();
        if (!($wpdb instanceof wpdb) || !$queue_ready) {
            return array(
                'ready' => false,
                'selected' => 0,
                'processed' => 0,
                'success' => 0,
                'skipped' => 0,
                'error' => 0,
            );
        }

        $table = self::get_cron_warm_queue_table_name();
        $marker = self::get_cron_warm_full_site_context_marker();
        $completed_marker = self::get_cron_warm_full_site_completed_context_marker();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads exact active-plan membership and terminal outcomes from the UltraCache-owned canonical queue.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS selected, SUM(CASE WHEN FIND_IN_SET(%s, source_contexts) > 0 THEN 1 ELSE 0 END) AS processed, SUM(CASE WHEN FIND_IN_SET(%s, source_contexts) > 0 AND full_site_outcome = %s THEN 1 ELSE 0 END) AS success, SUM(CASE WHEN FIND_IN_SET(%s, source_contexts) > 0 AND full_site_outcome = %s THEN 1 ELSE 0 END) AS skipped, SUM(CASE WHEN FIND_IN_SET(%s, source_contexts) > 0 AND full_site_outcome = %s THEN 1 ELSE 0 END) AS error FROM %i WHERE job_type = %s AND (FIND_IN_SET(%s, source_contexts) > 0 OR FIND_IN_SET(%s, source_contexts) > 0)",
                $completed_marker,
                $completed_marker,
                'success',
                $completed_marker,
                'skipped',
                $completed_marker,
                'error',
                $table,
                'page_warm',
                $marker,
                $completed_marker
            ),
            ARRAY_A
        );

        return array(
            'ready' => is_array($row),
            'selected' => max(0, (int) ($row['selected'] ?? 0)),
            'processed' => max(0, (int) ($row['processed'] ?? 0)),
            'success' => max(0, (int) ($row['success'] ?? 0)),
            'skipped' => max(0, (int) ($row['skipped'] ?? 0)),
            'error' => max(0, (int) ($row['error'] ?? 0)),
        );
    }

    /**
     * Count exact unique URL membership for the active full-site plan.
     *
     * @return int
     */
    private static function count_cron_warm_full_site_members()
    {
        $counts = self::get_cron_warm_full_site_membership_counts();
        return max(0, (int) ($counts['selected'] ?? 0));
    }

    /**
     * Remove membership from a finished or replaced full-site plan while
     * preserving canonical targeted work that shares the same URL row.
     *
     * @return bool
     */
    private static function release_cron_warm_full_site_membership()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return false;
        }

        $table = self::get_cron_warm_queue_table_name();
        $marker = self::get_cron_warm_full_site_context_marker();
        $completed_marker = self::get_cron_warm_full_site_completed_context_marker();

        // Full-site-only rows are lifecycle records for the finished plan and
        // can be removed. Active processing rows are never touched. Capture the
        // affected URLs first so their persistent pending hints can be reconciled
        // immediately after the database cleanup.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads only non-processing full-site-only rows immediately before deleting them from the UltraCache-owned queue.
        $deleted_urls = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT url FROM %i WHERE job_type = %s AND status <> %s AND source_context = %s AND (FIND_IN_SET(%s, source_contexts) > 0 OR FIND_IN_SET(%s, source_contexts) > 0)',
                $table,
                'page_warm',
                'processing',
                '',
                $marker,
                $completed_marker
            )
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only non-processing full-site-only rows from the UltraCache-owned queue.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE job_type = %s AND status <> %s AND source_context = %s AND (FIND_IN_SET(%s, source_contexts) > 0 OR FIND_IN_SET(%s, source_contexts) > 0)',
                $table,
                'page_warm',
                'processing',
                '',
                $marker,
                $completed_marker
            )
        );
        if (false === $deleted) {
            return false;
        }
        self::synchronize_cron_warm_queue_url_hints((array) $deleted_urls);

        // Every row that remains after the full-site-only delete must keep its
        // canonical targeted lifecycle but release membership from the finished
        // plan. This includes an actively processing targeted reuse of a URL that
        // had already completed its full-site work; excluding processing rows here
        // would leave a stale marker that suppresses the URL in the next plan.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases only finished-plan metadata while preserving canonical claims, stages, retries, and targeted contexts.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET source_contexts = TRIM(BOTH ',' FROM REPLACE(REPLACE(CONCAT(',', COALESCE(source_contexts, ''), ','), CONCAT(',', %s, ','), ','), CONCAT(',', %s, ','), ',')), full_site_outcome = %s, updated_at = %d WHERE job_type = %s AND (FIND_IN_SET(%s, source_contexts) > 0 OR FIND_IN_SET(%s, source_contexts) > 0)",
                $table,
                $marker,
                $completed_marker,
                '',
                time(),
                'page_warm',
                $marker,
                $completed_marker
            )
        );

        return false !== $updated;
    }

    /**
     * Mark one selected full-site URL as accounted for without losing its
     * membership when later targeted work reuses the same canonical row.
     *
     * @param int $row_id Canonical queue row ID.
     * @return bool
     */
    private static function mark_cron_warm_full_site_member_processed($row_id)
    {
        global $wpdb;

        $row_id = absint($row_id);
        if ($row_id < 1 || !($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return false;
        }

        $table = self::get_cron_warm_queue_table_name();
        $marker = self::get_cron_warm_full_site_context_marker();
        $completed_marker = self::get_cron_warm_full_site_completed_context_marker();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Updates only the terminal canonical row selected by the active full-site plan.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET source_contexts = CASE WHEN FIND_IN_SET(%s, source_contexts) > 0 THEN TRIM(BOTH ',' FROM REPLACE(CONCAT(',', source_contexts, ','), CONCAT(',', %s, ','), ',')) ELSE TRIM(BOTH ',' FROM REPLACE(CONCAT(',', source_contexts, ','), CONCAT(',', %s, ','), CONCAT(',', %s, ','))) END, full_site_outcome = CASE WHEN status = %s THEN %s WHEN status = %s THEN %s WHEN status = %s THEN %s ELSE full_site_outcome END, updated_at = %d WHERE id = %d AND status IN (%s, %s, %s) AND FIND_IN_SET(%s, source_contexts) > 0",
                $table,
                $completed_marker,
                $marker,
                $marker,
                $completed_marker,
                'done',
                'success',
                'skipped',
                'skipped',
                'error',
                'error',
                time(),
                $row_id,
                'done',
                'skipped',
                'error',
                $marker
            )
        );

        return 1 === (int) $updated;
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
        static $ready = null;

        if (is_bool($ready)) {
            return $ready;
        }

        global $wpdb;
        if (!($wpdb instanceof wpdb)) {
            $ready = false;
            return false;
        }

        if (self::get_cron_warm_queue_db_version() !== (string) get_option(self::get_cron_warm_queue_db_version_option_key(), '')) {
            $ready = false;
            return false;
        }

        // The stored schema version is authoritative for normal runtime/status reads.
        // Structural verification is lifecycle-owned and must not add SHOW TABLES
        // to repeated frontend/status hot paths.
        $ready = true;
        return true;
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

        $required = array('claim_token', 'claimed_at', 'lease_expires_at', 'rerun_requested', 'pending_targets', 'result_level', 'required_stages', 'completed_stages', 'rerun_completed_stages', 'source_contexts', 'full_site_outcome');
        $ready = empty(array_diff($required, array_map('strval', $columns)));
        wp_cache_set($cache_key, $ready, 'ultracache', HOUR_IN_SECONDS);
        return $ready;
    }

    /**
     * Normalize a bounded comma-separated queue metadata set.
     *
     * @param string|array $values Raw values.
     * @param array        $allowed Optional allow-list.
     * @return string
     */
    private static function normalize_cron_warm_queue_csv($values, array $allowed = array())
    {
        if (is_string($values)) {
            $values = preg_split('/[\\s,]+/', $values, -1, PREG_SPLIT_NO_EMPTY);
        }
        $values = is_array($values) ? $values : array();
        $normalized = array();
        foreach ($values as $value) {
            $value = substr(sanitize_key((string) $value), 0, 48);
            if ('' === $value || (!empty($allowed) && !in_array($value, $allowed, true))) {
                continue;
            }
            $normalized[$value] = $value;
        }
        ksort($normalized, SORT_STRING);
        return implode(',', array_values($normalized));
    }

    /**
     * Return the short-lived object-cache hint key for one canonical warm URL.
     *
     * The hint lets a PHP-served frontend cache hit avoid a database lookup
     * unless the URL was recently added to the canonical warm queue.
     *
     * @param string $url Local public URL.
     * @return string
     */
    private static function get_cron_warm_queue_url_hint_cache_key($url)
    {
        $url = function_exists('esc_url_raw') ? esc_url_raw(trim((string) $url)) : trim((string) $url);
        return '' === $url ? '' : 'pending-' . sha1($url);
    }

    /**
     * Mark one URL as recently present in the canonical warm queue.
     *
     * @param string $url Local public URL.
     * @return void
     */
    private static function set_cron_warm_queue_url_hint($url)
    {
        $key = self::get_cron_warm_queue_url_hint_cache_key($url);
        if ('' !== $key) {
            wp_cache_set($key, 1, 'ultracache-warm-queue', WEEK_IN_SECONDS);
        }
    }

    /**
     * Remove the object-cache hint after one canonical URL reaches a terminal state.
     *
     * @param string $url Local public URL.
     * @return void
     */
    private static function delete_cron_warm_queue_url_hint($url)
    {
        $key = self::get_cron_warm_queue_url_hint_cache_key($url);
        if ('' !== $key) {
            wp_cache_delete($key, 'ultracache-warm-queue');
        }
    }

    /**
     * Return active canonical URL hashes for a bounded candidate set.
     *
     * Hint reconciliation must not read every active queue URL when cleanup
     * removes duplicate legacy rows or races with a status transition. Query
     * only the affected hashes in fixed-size batches so memory and database
     * work remain bounded on large WooCommerce/import queues.
     *
     * @param array $hashes Candidate SHA-1 URL hashes.
     * @return array<string,bool>|null Active hash lookup, or null when reconciliation could not be read reliably.
     */
    private static function get_active_cron_warm_queue_hint_hashes(array $hashes)
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb) || !self::cron_warm_queue_table_exists()) {
            return array();
        }

        $normalized = array();
        foreach ($hashes as $hash) {
            $hash = strtolower(trim((string) $hash));
            if (1 === preg_match('/^[a-f0-9]{40}$/', $hash)) {
                $normalized[$hash] = $hash;
            }
        }
        if (empty($normalized)) {
            return array();
        }

        $table = self::get_cron_warm_queue_table_name();
        $active = array();
        foreach (array_chunk(array_values($normalized), 50) as $chunk) {
            $chunk = array_pad($chunk, 50, '');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reconciles only bounded candidate hashes after queue cleanup.
            $active_hashes = $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT url_hash FROM %i WHERE job_type = %s AND status IN (%s, %s) AND url_hash IN (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
                    $table,
                    'page_warm',
                    'pending',
                    'processing',
                    $chunk[0],
                    $chunk[1],
                    $chunk[2],
                    $chunk[3],
                    $chunk[4],
                    $chunk[5],
                    $chunk[6],
                    $chunk[7],
                    $chunk[8],
                    $chunk[9],
                    $chunk[10],
                    $chunk[11],
                    $chunk[12],
                    $chunk[13],
                    $chunk[14],
                    $chunk[15],
                    $chunk[16],
                    $chunk[17],
                    $chunk[18],
                    $chunk[19],
                    $chunk[20],
                    $chunk[21],
                    $chunk[22],
                    $chunk[23],
                    $chunk[24],
                    $chunk[25],
                    $chunk[26],
                    $chunk[27],
                    $chunk[28],
                    $chunk[29],
                    $chunk[30],
                    $chunk[31],
                    $chunk[32],
                    $chunk[33],
                    $chunk[34],
                    $chunk[35],
                    $chunk[36],
                    $chunk[37],
                    $chunk[38],
                    $chunk[39],
                    $chunk[40],
                    $chunk[41],
                    $chunk[42],
                    $chunk[43],
                    $chunk[44],
                    $chunk[45],
                    $chunk[46],
                    $chunk[47],
                    $chunk[48],
                    $chunk[49]
                )
            );
            if (null === $active_hashes) {
                return null;
            }
            foreach ($active_hashes as $hash) {
                $hash = strtolower(trim((string) $hash));
                if (isset($normalized[$hash])) {
                    $active[$hash] = true;
                }
            }
        }

        return $active;
    }

    /**
     * Synchronize short-lived pending URL hints after queue-row cleanup.
     *
     * Queue cleanup can delete hundreds of canonical rows at once. Their
     * persistent object-cache hints must be removed as well, otherwise the
     * next PHP-served cache hit performs an unnecessary queue-table lookup.
     * Always reconcile the bounded candidate set after the delete: deleted-row
     * counts cannot prove that every unique URL disappeared when duplicate
     * legacy rows and concurrent status transitions offset each other.
     *
     * @param array $candidate_urls URLs whose queue rows may have been removed.
     * @return void
     */
    private static function synchronize_cron_warm_queue_url_hints(array $candidate_urls)
    {
        $candidates = array();
        foreach ($candidate_urls as $url) {
            $url = function_exists('esc_url_raw') ? esc_url_raw(trim((string) $url)) : trim((string) $url);
            if ('' !== $url) {
                $candidates[$url] = $url;
            }
        }
        if (empty($candidates)) {
            return;
        }

        $candidate_hashes = array();
        foreach ($candidates as $url) {
            $candidate_hashes[] = sha1($url);
        }
        $active_hashes = self::get_active_cron_warm_queue_hint_hashes($candidate_hashes);
        if (null === $active_hashes) {
            // A stale positive hint costs one bounded queue lookup; deleting an
            // active hint after a failed reconciliation read would hide useful
            // visit/worker coalescing. Preserve the existing hints and retry on
            // the next cleanup instead.
            return;
        }

        foreach ($candidates as $url) {
            if (isset($active_hashes[sha1($url)])) {
                self::set_cron_warm_queue_url_hint($url);
            } else {
                self::delete_cron_warm_queue_url_hint($url);
            }
        }
    }

    /**
     * Check the low-cost pending hint used by PHP-served frontend cache hits.
     *
     * Without a persistent object cache, a cache hit remains completely
     * read-only instead of adding a database query to every frontend request.
     * Cache misses still report successful storage directly after the response.
     *
     * @param string $url Local public URL.
     * @return bool
     */
    public static function has_pending_canonical_warm_url_hint($url)
    {
        if (!function_exists('wp_using_ext_object_cache') || !wp_using_ext_object_cache()) {
            return false;
        }
        $key = self::get_cron_warm_queue_url_hint_cache_key($url);
        return '' !== $key && false !== wp_cache_get($key, 'ultracache-warm-queue');
    }

    /**
     * Merge cache stages satisfied by a real frontend visit into one canonical row.
     *
     * A pending row is completed immediately only when every required stage has
     * already been satisfied. A processing row retains its current claim while
     * the visit result is merged for the owning worker to preserve.
     *
     * @param string $url    Local public URL.
     * @param array  $stages Satisfied canonical stages.
     * @param string $source Visit source label.
     * @return array
     */
    public static function record_frontend_visit_cache_satisfaction($url, array $stages = array('html'), $source = 'visit-store')
    {
        global $wpdb;

        $url = function_exists('esc_url_raw') ? esc_url_raw(trim((string) $url)) : trim((string) $url);
        $stages_csv = self::normalize_cron_warm_queue_csv(
            $stages,
            array('html', 'css_bundle', 'lcp_refresh', 'varnish', 'litespeed')
        );
        $schema_ready = self::get_cron_warm_queue_db_version() === (string) get_option(self::get_cron_warm_queue_db_version_option_key(), '')
            && self::cron_warm_queue_table_exists();
        if ('' === $url || '' === $stages_csv || !($wpdb instanceof wpdb) || !$schema_ready) {
            return array('matched' => false, 'updated' => false, 'completed' => false);
        }

        $table = self::get_cron_warm_queue_table_name();
        $hash = sha1($url);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads one matching UltraCache-owned canonical queue row after a successful frontend cache response.
        $row_id = absint($wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE job_type = %s AND url_hash = %s AND status IN (%s, %s) LIMIT 1',
                $table,
                'page_warm',
                $hash,
                'pending',
                'processing'
            )
        ));
        if ($row_id < 1) {
            self::delete_cron_warm_queue_url_hint($url);
            return array('matched' => false, 'updated' => false, 'completed' => false);
        }

        $source = substr(sanitize_key((string) $source), 0, 32);
        $message = sanitize_text_field(sprintf(
            'Frontend %s satisfied canonical stage(s): %s.',
            '' !== $source ? $source : 'visit',
            implode(', ', explode(',', $stages_csv))
        ));
        $now = time();

        // Merge against the current database value instead of writing a CSV
        // assembled from an earlier SELECT. A visit and the active worker may
        // satisfy different stages concurrently; this fixed-set union preserves
        // both writers regardless of update order.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomically merges real frontend cache stages into one active UltraCache-owned canonical row.
        $merged = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET completed_stages = CONCAT_WS(',', IF(FIND_IN_SET('css_bundle', completed_stages) > 0 OR FIND_IN_SET('css_bundle', %s) > 0, 'css_bundle', NULL), IF(FIND_IN_SET('html', completed_stages) > 0 OR FIND_IN_SET('html', %s) > 0, 'html', NULL), IF(FIND_IN_SET('lcp_refresh', completed_stages) > 0 OR FIND_IN_SET('lcp_refresh', %s) > 0, 'lcp_refresh', NULL), IF(FIND_IN_SET('litespeed', completed_stages) > 0 OR FIND_IN_SET('litespeed', %s) > 0, 'litespeed', NULL), IF(FIND_IN_SET('varnish', completed_stages) > 0 OR FIND_IN_SET('varnish', %s) > 0, 'varnish', NULL)), rerun_completed_stages = CASE WHEN status = %s AND rerun_requested = %d THEN CONCAT_WS(',', IF(FIND_IN_SET('css_bundle', rerun_completed_stages) > 0 OR FIND_IN_SET('css_bundle', %s) > 0, 'css_bundle', NULL), IF(FIND_IN_SET('html', rerun_completed_stages) > 0 OR FIND_IN_SET('html', %s) > 0, 'html', NULL), IF(FIND_IN_SET('lcp_refresh', rerun_completed_stages) > 0 OR FIND_IN_SET('lcp_refresh', %s) > 0, 'lcp_refresh', NULL), IF(FIND_IN_SET('litespeed', rerun_completed_stages) > 0 OR FIND_IN_SET('litespeed', %s) > 0, 'litespeed', NULL), IF(FIND_IN_SET('varnish', rerun_completed_stages) > 0 OR FIND_IN_SET('varnish', %s) > 0, 'varnish', NULL)) ELSE rerun_completed_stages END, result_message = %s, updated_at = %d WHERE id = %d AND status IN (%s, %s)",
                $table,
                $stages_csv,
                $stages_csv,
                $stages_csv,
                $stages_csv,
                $stages_csv,
                'processing',
                1,
                $stages_csv,
                $stages_csv,
                $stages_csv,
                $stages_csv,
                $stages_csv,
                $message,
                $now,
                $row_id,
                'pending',
                'processing'
            )
        );
        if (false === $merged) {
            return array('matched' => true, 'updated' => false, 'completed' => false);
        }

        // Complete only a still-pending row and evaluate requirements from the
        // current database state. A concurrent enqueue that adds CSS/LCP/Varnish
        // work before this statement therefore prevents premature completion;
        // a concurrent worker claim also keeps the row processing for its owner.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditionally completes one canonical pending row after an atomic stage merge.
        $completed_update = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET status = %s, result_level = %s, result_message = %s, updated_at = %d, processed_at = %d, next_attempt_at = %d WHERE id = %d AND status = %s AND (FIND_IN_SET('css_bundle', required_stages) = 0 OR FIND_IN_SET('css_bundle', completed_stages) > 0) AND (FIND_IN_SET('html', required_stages) = 0 OR FIND_IN_SET('html', completed_stages) > 0) AND (FIND_IN_SET('lcp_refresh', required_stages) = 0 OR FIND_IN_SET('lcp_refresh', completed_stages) > 0) AND (FIND_IN_SET('litespeed', required_stages) = 0 OR FIND_IN_SET('litespeed', completed_stages) > 0) AND (FIND_IN_SET('varnish', required_stages) = 0 OR FIND_IN_SET('varnish', completed_stages) > 0)",
                $table,
                'done',
                'success',
                $message,
                $now,
                $now,
                0,
                $row_id,
                'pending'
            )
        );
        if (false === $completed_update) {
            return array('matched' => true, 'updated' => (int) $merged > 0, 'completed' => false);
        }

        $completed_row = 1 === (int) $completed_update;
        if ($completed_row) {
            self::mark_cron_warm_full_site_member_processed($row_id);
        }

        // Reconcile against the authoritative active-row state. This also
        // covers a new enqueue that reopens the URL immediately after the visit
        // completed the previous lifecycle.
        self::synchronize_cron_warm_queue_url_hints(array($url));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads one canonical row to report the current post-merge stage state.
        $current = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT required_stages, completed_stages, status FROM %i WHERE id = %d LIMIT 1',
                $table,
                $row_id
            ),
            ARRAY_A
        );
        $required = is_array($current)
            ? self::get_cron_warm_queue_row_required_stages(array(
                'job_type' => 'page_warm',
                'source_context' => '',
                'url' => (string) $url,
                'required_stages' => (string) ($current['required_stages'] ?? ''),
            ))
            : array();
        $completed_csv = is_array($current)
            ? self::normalize_cron_warm_queue_csv(
                (string) ($current['completed_stages'] ?? ''),
                array('html', 'css_bundle', 'lcp_refresh', 'varnish', 'litespeed')
            )
            : '';
        $completed = '' === $completed_csv ? array() : explode(',', $completed_csv);

        return array(
            'matched' => true,
            'updated' => (int) $merged > 0 || $completed_row,
            'completed' => $completed_row,
            'remainingStages' => array_values(array_diff($required, $completed)),
        );
    }

    /**
     * Return whether one public URL is eligible for the multilingual CSS warm stage.
     *
     * Non-multilingual sites preserve the existing global CSS warm behavior. On
     * multilingual sites the URL must resolve to an active provider language and
     * that language must be eligible for the css_bundle warm operation.
     *
     * @param string $url Public URL represented by the queue row.
     * @return bool
     */
    private static function cron_warm_url_allows_css_bundle($url)
    {
        return !function_exists('ultracache_multilingual_public_url_allows_warm_operation')
            || ultracache_multilingual_public_url_allows_warm_operation($url, 'css_bundle');
    }

    /**
     * Resolve the canonical stages represented by one legacy/new enqueue call.
     *
     * @param string $job_type       Requested legacy job type.
     * @param string $source_context Requested source context.
     * @return array
     */
    private static function get_cron_warm_queue_required_stages($job_type, $source_context = '', $url = '')
    {
        $job_type = sanitize_key((string) $job_type);
        $source_context = sanitize_key((string) $source_context);
        $stages = array('html');

        // The persistent queue row is the canonical stage contract. If the
        // user enabled Also Warm up CSS and CSS bundling is active, ordinary
        // page_warm work must declare CSS up front instead of relying on the
        // runner's legacy auto-CSS behavior and later reporting work that was
        // never represented in required_stages/completed_stages.
        if ('page_warm' === $job_type) {
            $settings = self::get_settings();
            if (
                !empty($settings['warm_css_bundles_enabled'])
                && !empty($settings['homepage_css_bundle'])
                && self::cron_warm_url_allows_css_bundle($url)
            ) {
                $stages[] = 'css_bundle';
            }
        }
        if (('css_bundle' === $job_type || 'css-bundle' === $source_context)
            && self::cron_warm_url_allows_css_bundle($url)
        ) {
            $stages[] = 'css_bundle';
        }
        if ('lcp_refresh' === $job_type || 'lcp-refresh' === $source_context) {
            $stages[] = 'lcp_refresh';
        }
        if (
            'varnish_invalidate' === $job_type
            || in_array($source_context, array('varnish-invalidate', 'refresh-ahead'), true)
        ) {
            $stages[] = 'varnish';
        }
        return explode(',', self::normalize_cron_warm_queue_csv(
            $stages,
            array('html', 'css_bundle', 'lcp_refresh', 'varnish', 'litespeed')
        ));
    }

    /**
     * Resolve external-cache stages that belong to every newly discovered
     * full-site URL. The canonical queue row remains the only stage contract.
     *
     * @return array
     */
    private static function get_cron_warm_site_required_stages()
    {
        $stages = array();
        if (
            method_exists(static::class, 'should_include_varnish_in_site_warmup')
            && self::should_include_varnish_in_site_warmup()
        ) {
            $stages[] = 'varnish';
        }
        if (
            method_exists(static::class, 'should_include_litespeed_in_site_warmup')
            && self::should_include_litespeed_in_site_warmup()
        ) {
            $stages[] = 'litespeed';
        }

        return $stages;
    }

    /**
     * Read canonical stage requirements from a queue row, including legacy rows.
     *
     * @param array $row Queue row.
     * @return array
     */
    private static function get_cron_warm_queue_row_required_stages(array $row)
    {
        $stored = (string) ($row['required_stages'] ?? '');
        $inferred = self::get_cron_warm_queue_required_stages(
            (string) ($row['job_type'] ?? 'page_warm'),
            (string) ($row['source_context'] ?? ''),
            (string) ($row['url'] ?? '')
        );
        $stages = self::normalize_cron_warm_queue_csv(
            trim($stored . ',' . implode(',', $inferred), ','),
            array('html', 'css_bundle', 'lcp_refresh', 'varnish', 'litespeed')
        );
        return '' === $stages ? array('html') : explode(',', $stages);
    }

    /**
     * Return the dedicated queue-schema coordination lock name.
     *
     * This deliberately does not use the ultracache_cron_warm_ prefix because
     * the public runtime reset clears that lock namespace while rebuilding the
     * disposable queue.
     *
     * @return string
     */
    private static function get_cron_warm_queue_schema_lock_name()
    {
        return 'ultracache_schema_cron_warm_queue';
    }

    /**
     * Check whether the canonical queue schema is fully current.
     *
     * @return bool
     */
    private static function cron_warm_queue_schema_is_current($force_schema_verify = false)
    {
        if (self::get_cron_warm_queue_db_version() !== (string) get_option(self::get_cron_warm_queue_db_version_option_key(), '')) {
            return false;
        }

        if (!$force_schema_verify) {
            return true;
        }

        return self::cron_warm_queue_table_exists()
            && self::cron_warm_queue_claim_schema_ready();
    }

    /**
     * Return the canonical CREATE TABLE statement for an empty warm queue.
     *
     * @return string
     */
    private static function get_cron_warm_queue_create_sql()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return '';
        }

        $table = self::get_cron_warm_queue_table_name();
        $charset_collate = $wpdb->get_charset_collate();
        return "CREATE TABLE {$table} (
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
            required_stages varchar(255) NOT NULL DEFAULT 'html',
            completed_stages varchar(255) NOT NULL DEFAULT '',
            rerun_completed_stages varchar(255) NOT NULL DEFAULT '',
            source_contexts text NULL,
            full_site_outcome varchar(20) NOT NULL DEFAULT '',
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
    }

    /**
     * Invalidate cached queue-schema probes after a create/drop operation.
     *
     * @return void
     */
    private static function invalidate_cron_warm_queue_schema_cache()
    {
        $table = self::get_cron_warm_queue_table_name();
        wp_cache_delete('ultracache_cron_warm_queue_table_exists_' . md5((string) $table), 'ultracache');
        wp_cache_delete('ultracache_cron_warm_queue_claim_schema_' . md5((string) $table), 'ultracache');
    }

    /**
     * Recreate an empty canonical queue under one cross-request schema lock.
     *
     * Warm queue rows are disposable runtime. Any outdated or incomplete
     * schema is dropped rather than migrated through private-development
     * layouts.
     *
     * @param bool $force_recreate Drop even an already-current table.
     * @return bool
     */
    private static function recreate_empty_cron_warm_queue_schema($force_recreate = false, $force_schema_verify = false)
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb) || !ultracache_ensure_locks_table()) {
            return false;
        }

        if (!$force_recreate && self::cron_warm_queue_schema_is_current($force_schema_verify)) {
            return true;
        }

        $lock_token = 'queue-schema-' . gmdate('YmdHis') . '-' . wp_generate_password(20, false, false);
        if (!ultracache_acquire_lock(
            self::get_cron_warm_queue_schema_lock_name(),
            $lock_token,
            120,
            array('forceRecreate' => (bool) $force_recreate, 'targetVersion' => self::get_cron_warm_queue_db_version())
        )) {
            if ($force_recreate) {
                return false;
            }

            for ($attempt = 0; $attempt < 30; $attempt++) {
                usleep(100000);
                self::invalidate_cron_warm_queue_schema_cache();
                if (self::cron_warm_queue_schema_is_current($force_schema_verify)) {
                    return true;
                }
            }
            return false;
        }

        try {
            if (!$force_recreate && self::cron_warm_queue_schema_is_current($force_schema_verify)) {
                return true;
            }

            if (!ultracache_require_wordpress_admin_include('upgrade.php', 'dbDelta')) {
                return false;
            }

            $table = self::get_cron_warm_queue_table_name();
            if ('' === $table) {
                return false;
            }

            if (self::cron_warm_queue_table_exists()) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Outdated UltraCache warm rows are disposable runtime and are intentionally discarded before the current schema is created.
                $dropped = $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
                if (false === $dropped) {
                    return false;
                }
            }

            delete_option(self::get_cron_warm_queue_db_version_option_key());
            self::invalidate_cron_warm_queue_schema_cache();
            wp_cache_flush_group('ultracache-warm-queue');

            $sql = self::get_cron_warm_queue_create_sql();
            if ('' === $sql) {
                return false;
            }
            dbDelta($sql);
            self::invalidate_cron_warm_queue_schema_cache();

            if (!self::cron_warm_queue_table_exists() || !self::cron_warm_queue_claim_schema_ready()) {
                return false;
            }

            update_option(self::get_cron_warm_queue_db_version_option_key(), self::get_cron_warm_queue_db_version(), false);
            return true;
        } finally {
            ultracache_release_lock(self::get_cron_warm_queue_schema_lock_name(), $lock_token);
        }
    }

    /**
     * Ensure the current disposable warm queue exists.
     *
     * Requests that do not own an incomplete public upgrade reset must not
     * recreate or enqueue warm runtime while another request is resetting it.
     *
     * @return bool
     */
    public static function ensure_cron_warm_queue_table($force_schema_verify = false)
    {
        if (
            !self::$public_warm_runtime_reset_active
            && !self::is_public_warm_runtime_reset_complete()
            && self::has_existing_ultracache_installation_evidence()
        ) {
            return false;
        }

        return self::recreate_empty_cron_warm_queue_schema(false, (bool) $force_schema_verify);
    }

    /**
     * Recreate the canonical warm queue for the public upgrade reset.
     *
     * @return bool
     */
    private static function recreate_cron_warm_queue_table_for_upgrade()
    {
        return self::recreate_empty_cron_warm_queue_schema(true);
    }


    /**
     * Recreate an empty warm/Varnish queue at a Flush All generation boundary.
     *
     * Flush All invalidates every queued operation from the previous cache
     * generation, including pending, retrying, processing, and terminal rows.
     * The warm-after-flush hook may enqueue only fresh rows after this reset.
     *
     * @return bool
     */
    private static function reset_cron_warm_queue_table_for_cache_flush()
    {
        global $wpdb;

        if (!self::ensure_cron_warm_queue_table() || !($wpdb instanceof wpdb)) {
            return false;
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Captures disposable UltraCache queue URL hints immediately before the Flush All generation reset.
        $previous_urls = (array) $wpdb->get_col($wpdb->prepare('SELECT url FROM %i', $table));

        // A cache-generation boundary supersedes every old queue lifecycle,
        // including processing claims and terminal failures. The generation and
        // execution-fence reset happens before this delete, so an old worker
        // cannot commit its result back into the new empty queue.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Flush All intentionally deletes all disposable UltraCache warm/Varnish runtime rows.
        $deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i', $table));
        if (false === $deleted) {
            return false;
        }

        wp_cache_flush_group('ultracache-warm-queue');
        self::synchronize_cron_warm_queue_url_hints($previous_urls);
        return true;
    }

    private static function clear_cron_warm_queue_table($preserve_lcp_refresh = false)
    {
        global $wpdb;

        if (!self::ensure_cron_warm_queue_table()) {
            return false;
        }

        $table = self::get_cron_warm_queue_table_name();
        if ($preserve_lcp_refresh) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads ordinary UltraCache work immediately before manual-priority cleanup so persistent URL hints can be reconciled.
            $deleted_urls = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT url FROM %i WHERE status <> %s AND job_type NOT IN (%s, %s, %s) AND NOT (job_type = %s AND source_context <> %s)",
                    $table,
                    'processing',
                    'lcp_refresh',
                    'varnish_invalidate',
                    'litespeed_invalidate',
                    'page_warm',
                    ''
                )
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Manual warm priority clears ordinary UltraCache work while retaining deferred LCP and persistent Varnish jobs.
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM %i WHERE status <> %s AND job_type NOT IN (%s, %s, %s) AND NOT (job_type = %s AND source_context <> %s)",
                    $table,
                    'processing',
                    'lcp_refresh',
                    'varnish_invalidate',
                    'litespeed_invalidate',
                    'page_warm',
                    ''
                )
            );
            if (false === $deleted) {
                return false;
            }
            self::synchronize_cron_warm_queue_url_hints((array) $deleted_urls);
            return true;
        }

        // Persistent Varnish invalidation, active targeted page-warm rows, and every
        // processing claim survive ordinary batch transitions and manual-priority cleanup.
        // Flush All uses reset_cron_warm_queue_table_for_cache_flush() instead.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads ordinary UltraCache rows immediately before cleanup so persistent URL hints can be reconciled.
        $deleted_urls = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT url FROM %i WHERE status <> %s AND job_type NOT IN (%s, %s) AND NOT (job_type = %s AND source_context <> %s)",
                $table,
                'processing',
                'varnish_invalidate',
                'litespeed_invalidate',
                'page_warm',
                ''
            )
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes only ordinary UltraCache warm queue rows.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE status <> %s AND job_type NOT IN (%s, %s) AND NOT (job_type = %s AND source_context <> %s)",
                $table,
                'processing',
                'varnish_invalidate',
                'litespeed_invalidate',
                'page_warm',
                ''
            )
        );
        if (false === $deleted) {
            return false;
        }
        self::synchronize_cron_warm_queue_url_hints((array) $deleted_urls);
        return true;
    }

    private static function insert_cron_warm_queue_urls(array $urls, $base_position = 0, $job_type = 'page_warm', $source_context = '', $requires_verified_origin = false, &$accepted_urls = null, &$enqueue_summary = null, array $additional_required_stages = array())
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
        $requested_job_type = in_array((string) $job_type, array('page_warm', 'css_bundle', 'lcp_refresh', 'varnish_invalidate'), true)
            ? (string) $job_type
            : 'page_warm';
        $source_context = substr(sanitize_key((string) $source_context), 0, 32);
        $is_full_site_request = 'page_warm' === $requested_job_type && '' === $source_context;
        if ('' === $source_context) {
            if ('css_bundle' === $requested_job_type) {
                $source_context = 'css-bundle';
            } elseif ('lcp_refresh' === $requested_job_type) {
                $source_context = 'lcp-refresh';
            } elseif ('varnish_invalidate' === $requested_job_type) {
                $source_context = 'varnish-invalidate';
            }
        }
        $additional_required_stages = array_values(array_unique(array_intersect(
            array('html', 'css_bundle', 'lcp_refresh', 'varnish', 'litespeed'),
            array_map('sanitize_key', $additional_required_stages)
        )));
        $source_contexts = self::normalize_cron_warm_queue_csv(
            $is_full_site_request ? self::get_cron_warm_full_site_context_marker() : $source_context
        );
        $job_type = 'page_warm';
        $requires_verified_origin = (bool) $requires_verified_origin;
        $seen_urls = array();
        $full_site_member_hashes = $is_full_site_request
            ? self::get_cron_warm_full_site_member_hash_lookup()
            : array();

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

            $row_additional_stages = $additional_required_stages;
            if (!self::cron_warm_url_allows_css_bundle($url)) {
                $row_additional_stages = array_values(array_diff($row_additional_stages, array('css_bundle')));
            }
            $required_stages = self::normalize_cron_warm_queue_csv(
                array_merge(
                    self::get_cron_warm_queue_required_stages($requested_job_type, $source_context, $url),
                    $row_additional_stages
                ),
                array('html', 'css_bundle', 'lcp_refresh', 'varnish', 'litespeed')
            );

            $hash = sha1($url);
            if ($is_full_site_request && isset($full_site_member_hashes[$hash])) {
                ++$enqueue_summary['duplicates'];
                continue;
            }
            $row_source_contexts = $source_contexts;
            $position = '' !== $source_context ? 0 : $base_position + count($accepted_urls) + 1;

            // Preserve full-site membership only from the authoritative current
            // database row. A targeted enqueue must not resurrect a marker captured
            // before full-site plan cleanup, while a concurrent visit/worker transition
            // from selected to completed membership must remain intact.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical queue upsert merges all requested stages into one UltraCache-owned URL row.
            $result = $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO %i (url_hash, url, job_type, source_context, requires_verified_origin, position, status, result_level, claim_token, claimed_at, lease_expires_at, rerun_requested, pending_targets, required_stages, completed_stages, source_contexts, full_site_outcome, result_message, created_at, updated_at, processed_at, attempt_count, next_attempt_at) VALUES (%s, %s, %s, %s, %d, %d, %s, %s, %s, %d, %d, %d, %s, %s, %s, %s, %s, %s, %d, %d, %d, %d, %d) ON DUPLICATE KEY UPDATE url = VALUES(url), source_context = CASE WHEN status IN (%s, %s) THEN CASE WHEN VALUES(source_context) <> %s THEN VALUES(source_context) ELSE source_context END ELSE VALUES(source_context) END, source_contexts = CASE WHEN status IN (%s, %s) THEN TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(COALESCE(source_contexts, ''), ''), IF(%s <> '' AND FIND_IN_SET(%s, COALESCE(source_contexts, '')) = 0, %s, NULL))) WHEN FIND_IN_SET(%s, COALESCE(source_contexts, '')) > 0 THEN TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(TRIM(BOTH ',' FROM REPLACE(CONCAT(',', COALESCE(VALUES(source_contexts), ''), ','), CONCAT(',', %s, ','), ',')), ''), IF(FIND_IN_SET(%s, VALUES(source_contexts)) = 0, %s, NULL))) WHEN FIND_IN_SET(%s, COALESCE(source_contexts, '')) > 0 THEN TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(VALUES(source_contexts), ''), IF(FIND_IN_SET(%s, VALUES(source_contexts)) = 0 AND FIND_IN_SET(%s, VALUES(source_contexts)) = 0, %s, NULL))) ELSE VALUES(source_contexts) END, full_site_outcome = CASE WHEN COALESCE(full_site_outcome, '') <> '' THEN full_site_outcome ELSE VALUES(full_site_outcome) END, required_stages = CASE WHEN status IN (%s, %s) THEN CONCAT_WS(',', IF(FIND_IN_SET('css_bundle', required_stages) > 0 OR FIND_IN_SET('css_bundle', VALUES(required_stages)) > 0, 'css_bundle', NULL), IF(FIND_IN_SET('html', required_stages) > 0 OR FIND_IN_SET('html', VALUES(required_stages)) > 0, 'html', NULL), IF(FIND_IN_SET('lcp_refresh', required_stages) > 0 OR FIND_IN_SET('lcp_refresh', VALUES(required_stages)) > 0, 'lcp_refresh', NULL), IF(FIND_IN_SET('litespeed', required_stages) > 0 OR FIND_IN_SET('litespeed', VALUES(required_stages)) > 0, 'litespeed', NULL), IF(FIND_IN_SET('varnish', required_stages) > 0 OR FIND_IN_SET('varnish', VALUES(required_stages)) > 0, 'varnish', NULL)) ELSE VALUES(required_stages) END, completed_stages = CASE WHEN status IN (%s, %s) THEN completed_stages ELSE %s END, requires_verified_origin = CASE WHEN status IN (%s, %s) THEN GREATEST(requires_verified_origin, VALUES(requires_verified_origin)) ELSE VALUES(requires_verified_origin) END, position = CASE WHEN status = %s THEN LEAST(position, VALUES(position)) WHEN status = %s THEN position ELSE VALUES(position) END, result_level = CASE WHEN status = %s THEN result_level ELSE %s END, pending_targets = CASE WHEN status = %s THEN pending_targets ELSE %s END, result_message = CASE WHEN status = %s THEN result_message ELSE %s END, created_at = CASE WHEN status IN (%s, %s, %s) THEN VALUES(created_at) ELSE created_at END, updated_at = VALUES(updated_at), processed_at = CASE WHEN status = %s THEN processed_at ELSE %d END, attempt_count = CASE WHEN status IN (%s, %s) THEN attempt_count ELSE %d END, next_attempt_at = CASE WHEN status = %s THEN next_attempt_at ELSE %d END, claim_token = CASE WHEN status = %s THEN claim_token ELSE %s END, claimed_at = CASE WHEN status = %s THEN claimed_at ELSE %d END, lease_expires_at = CASE WHEN status = %s THEN lease_expires_at ELSE %d END, rerun_completed_stages = CASE WHEN status = %s AND rerun_requested = %d THEN %s WHEN status = %s THEN rerun_completed_stages ELSE %s END, rerun_requested = CASE WHEN status = %s THEN %d ELSE %d END, status = CASE WHEN status = %s THEN status ELSE %s END",
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
                    $required_stages,
                    '',
                    $row_source_contexts,
                    $is_full_site_request ? 'pending' : '',
                    '',
                    $now,
                    $now,
                    0,
                    0,
                    0,
                    'pending',
                    'processing',
                    '',
                    'pending',
                    'processing',
                    $source_contexts,
                    $source_contexts,
                    $source_contexts,
                    self::get_cron_warm_full_site_completed_context_marker(),
                    self::get_cron_warm_full_site_context_marker(),
                    self::get_cron_warm_full_site_completed_context_marker(),
                    self::get_cron_warm_full_site_completed_context_marker(),
                    self::get_cron_warm_full_site_context_marker(),
                    self::get_cron_warm_full_site_context_marker(),
                    self::get_cron_warm_full_site_completed_context_marker(),
                    self::get_cron_warm_full_site_context_marker(),
                    'pending',
                    'processing',
                    'pending',
                    'processing',
                    '',
                    'pending',
                    'processing',
                    'pending',
                    'processing',
                    'processing',
                    '',
                    'processing',
                    '',
                    'processing',
                    '',
                    'done',
                    'skipped',
                    'error',
                    'processing',
                    0,
                    'pending',
                    'processing',
                    0,
                    'processing',
                    0,
                    'processing',
                    '',
                    'processing',
                    0,
                    'processing',
                    0,
                    'processing',
                    0,
                    '',
                    'processing',
                    '',
                    'processing',
                    1,
                    0,
                    'processing',
                    'pending'
                )
            );
            if (false === $result) {
                ++$enqueue_summary['failed'];
                continue;
            }

            $accepted_urls[$url] = $url;
            if ($is_full_site_request) {
                $full_site_member_hashes[$hash] = self::get_cron_warm_full_site_context_marker();
            }
            self::set_cron_warm_queue_url_hint($url);
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
        $pages_per_minute = self::get_shared_automation_pages_per_minute();
        $paused_by_work_limit = $pages_per_minute < 1;

        // Foreground ownership pauses execution, not work discovery. Keep the
        // canonical CSS stage queued so the shared background worker can resume
        // it after UI/WP-CLI releases ownership. Do not replace an interrupted
        // full-site state or schedule a competing cron worker while foreground
        // work is active.
        if (self::is_manual_warmup_blocking_cron()) {
            return true;
        }

        if (empty($state['active'])) {
            $state = self::save_cron_warm_state(array(
                'active'       => !$paused_by_work_limit,
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
                'pagesPerMinute' => $pages_per_minute,
                'totalLimit'   => 0,
                'workloadType' => 'targeted',
                'fullSiteDiscoveryComplete' => true,
                'currentBatch' => array(),
                'batchIndex'   => 0,
                'batchHasMore' => false,
                'nextCursorPending' => '',
                'lastError'    => '',
                'lastMessage'  => $paused_by_work_limit
                    ? self::maybe_translate('Async CSS bundle work is queued and paused because Background warm pages per minute is 0.')
                    : self::maybe_translate('Async CSS bundle build queued.'),
                'lastUrl'      => $url,
                'completed'    => false,
                'stopped'      => $paused_by_work_limit,
                'stopReason'   => $paused_by_work_limit ? 'paused' : '',
                'invokedBy'    => 'frontend-css-bundle',
            ));
        } else {
            $state['active'] = !$paused_by_work_limit;
            $state['completed'] = false;
            $state['stopped'] = $paused_by_work_limit;
            $state['stopReason'] = $paused_by_work_limit ? 'paused' : '';
            $state['pagesPerMinute'] = $pages_per_minute;
            $state['updatedAt'] = $now;
            if ('full_site' !== (string) ($state['workloadType'] ?? '')) {
                $state['total'] = max((int) ($state['total'] ?? 0), (int) ($state['processed'] ?? 0) + $pending_before + $inserted);
            }
            $state['lastMessage'] = $paused_by_work_limit
                ? self::maybe_translate('Async CSS bundle work is queued and paused because Background warm pages per minute is 0.')
                : self::maybe_translate('Async CSS bundle build queued.');
            $state['lastUrl'] = $url;
            self::save_cron_warm_state($state);
        }

        if ($paused_by_work_limit) {
            self::unschedule_cron_warm_events();
        } else {
            self::ensure_cron_warm_events_scheduled(5);
        }
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
        $configured_rate = self::get_shared_automation_pages_per_minute($settings);
        // Foreground ownership pauses execution, not LCP work discovery.
        // The canonical stage has already been queued above, so leave the
        // interrupted full-site/targeted worker state untouched and let the
        // normal foreground-release recovery schedule it afterwards.
        if (self::is_manual_warmup_blocking_cron()) {
            return true;
        }

        if ($configured_rate < 1) {
            $full_site_plan = 'full_site' === (string) ($state['workloadType'] ?? '');
            $state['active'] = false;
            $state['completed'] = false;
            $state['stopped'] = true;
            $state['stopReason'] = 'paused';
            $state['updatedAt'] = $now;
            $state['pagesPerMinute'] = 0;
            if (!$full_site_plan) {
                $state['reason'] = 'lcp_refresh_async';
                $state['workloadType'] = 'targeted';
                $state['total'] = max(1, self::count_pending_lcp_refresh_queue_rows());
                $state['invokedBy'] = 'browser-lcp-paused';
            }
            $state['lastMessage'] = self::maybe_translate('Page-specific LCP refresh is queued and paused because Background warm pages per minute is 0.');
            $state['lastUrl'] = $url;
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
                'workloadType'      => 'targeted',
                'fullSiteDiscoveryComplete' => true,
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
            $state['pagesPerMinute'] = $configured_rate;
            $full_site_plan = 'full_site' === (string) ($state['workloadType'] ?? '');
            if (!$full_site_plan) {
                $state['total'] = max(
                    (int) ($state['total'] ?? 0),
                    (int) ($state['processed'] ?? 0) + $pending_before + $inserted
                );
                $state['invokedBy'] = 'browser-lcp';
            }
            // Targeted work shares the worker but never expands or consumes the
            // scheduled full-site URL-selection limit or progress counters.
            $state['lastMessage'] = self::maybe_translate('Page-specific LCP refresh queued.');
            $state['lastUrl'] = $url;
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
        $seconds = max(60, (int) apply_filters('ultracache_cron_warm_queue_lease_seconds', 300));
        $max_execution = function_exists('ultracache_get_php_max_execution_time_seconds')
            ? ultracache_get_php_max_execution_time_seconds()
            : max(0, (int) ini_get('max_execution_time'));
        return $max_execution > 0 ? max($seconds, $max_execution) : $seconds;
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
        $stale_before = $now - self::get_cron_warm_queue_lease_seconds();
        $message = self::maybe_translate('A previous queue worker lease expired; the row was returned to pending state without losing completed stage progress.');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovers only abandoned processing leases in the UltraCache-owned queue.
        $recovered = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET status = %s, result_level = CASE WHEN claim_token LIKE %s THEN result_level ELSE %s END, claim_token = %s, claimed_at = %d, lease_expires_at = %d, completed_stages = CASE WHEN rerun_requested = %d THEN rerun_completed_stages ELSE completed_stages END, rerun_completed_stages = %s, pending_targets = CASE WHEN rerun_requested = %d THEN %s ELSE pending_targets END, result_message = %s, attempt_count = CASE WHEN rerun_requested = %d THEN %d WHEN claim_token LIKE %s THEN attempt_count ELSE GREATEST(attempt_count - 1, 0) END, next_attempt_at = %d, updated_at = %d, processed_at = %d, rerun_requested = %d WHERE status = %s AND ((lease_expires_at > %d AND lease_expires_at <= %d) OR (lease_expires_at = %d AND claimed_at > %d AND claimed_at <= %d) OR (lease_expires_at = %d AND claimed_at = %d AND updated_at <= %d))",
                $table,
                'pending',
                'varnish-%',
                'retrying',
                '',
                0,
                0,
                1,
                '',
                1,
                '',
                $message,
                1,
                0,
                'varnish-%',
                $now + 30,
                $now,
                0,
                0,
                'processing',
                0,
                $now,
                0,
                0,
                $stale_before,
                0,
                0,
                $stale_before
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
                "SELECT id, url, job_type, source_context, source_contexts, required_stages, completed_stages, requires_verified_origin, status, claim_token, claimed_at, lease_expires_at, rerun_requested, pending_targets, attempt_count, next_attempt_at FROM %i WHERE id = %d AND status = %s AND claim_token = %s",
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
                "UPDATE %i SET status = %s, claim_token = %s, claimed_at = %d, lease_expires_at = %d, completed_stages = CASE WHEN rerun_requested = %d THEN rerun_completed_stages ELSE completed_stages END, rerun_completed_stages = %s, rerun_requested = %d, result_message = %s, attempt_count = GREATEST(attempt_count - 1, 0), next_attempt_at = %d, updated_at = %d, processed_at = %d WHERE id = %d AND status = %s AND claim_token = %s",
                $table,
                'pending',
                '',
                0,
                0,
                1,
                '',
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

    private static function load_cron_warm_pending_queue_rows($limit, $prefer_full_site, $single_slot_preference, array &$selection_meta)
    {
        global $wpdb;

        $selection_meta = array(
            'mixed' => false,
            'selectedClass' => '',
        );
        $limit = max(0, min(600, absint($limit)));
        $single_slot_preference = 'targeted' === sanitize_key((string) $single_slot_preference)
            ? 'targeted'
            : 'full_site';
        if ($limit < 1 || !self::ensure_cron_warm_queue_table()) {
            return array();
        }

        $table = self::get_cron_warm_queue_table_name();
        if (!$prefer_full_site) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron warm queue reads only UltraCache-owned rows.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, url, job_type, source_context, source_contexts, required_stages, completed_stages, requires_verified_origin, attempt_count, next_attempt_at FROM %i WHERE status = %s AND next_attempt_at <= %d AND job_type IN (%s, %s, %s) ORDER BY CASE WHEN job_type = 'page_warm' AND source_context <> '' THEN 0 WHEN job_type = 'lcp_refresh' THEN 1 WHEN job_type = 'css_bundle' THEN 2 ELSE 3 END ASC, position ASC, id ASC LIMIT %d",
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

        $marker = self::get_cron_warm_full_site_context_marker();
        $now = time();
        // Fetch bounded candidate sets for both workload classes, then reserve
        // approximately half of each tick for the active full-site plan. This
        // keeps homepage/source-order progress moving without starving targeted
        // update/import work that already shares the canonical queue.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads only active full-site members from the UltraCache-owned canonical queue.
        $full_site_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, url, job_type, source_context, source_contexts, required_stages, completed_stages, requires_verified_origin, attempt_count, next_attempt_at FROM %i WHERE status = %s AND next_attempt_at <= %d AND job_type IN (%s, %s, %s) AND FIND_IN_SET(%s, source_contexts) > 0 ORDER BY position ASC, id ASC LIMIT %d",
                $table,
                'pending',
                $now,
                'page_warm',
                'css_bundle',
                'lcp_refresh',
                $marker,
                $limit
            ),
            ARRAY_A
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads only active non-full-site work from the UltraCache-owned canonical queue.
        $targeted_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, url, job_type, source_context, source_contexts, required_stages, completed_stages, requires_verified_origin, attempt_count, next_attempt_at FROM %i WHERE status = %s AND next_attempt_at <= %d AND job_type IN (%s, %s, %s) AND FIND_IN_SET(%s, source_contexts) = 0 ORDER BY CASE WHEN job_type = 'page_warm' AND source_context <> '' THEN 0 WHEN job_type = 'lcp_refresh' THEN 1 WHEN job_type = 'css_bundle' THEN 2 ELSE 3 END ASC, position ASC, id ASC LIMIT %d",
                $table,
                'pending',
                $now,
                'page_warm',
                'css_bundle',
                'lcp_refresh',
                $marker,
                $limit
            ),
            ARRAY_A
        );

        $full_site_rows = is_array($full_site_rows) ? array_values($full_site_rows) : array();
        $targeted_rows = is_array($targeted_rows) ? array_values($targeted_rows) : array();
        $selection_meta['mixed'] = !empty($full_site_rows) && !empty($targeted_rows);

        if (1 === $limit) {
            if ($selection_meta['mixed']) {
                $selection_meta['selectedClass'] = $single_slot_preference;
                return 'targeted' === $single_slot_preference
                    ? array($targeted_rows[0])
                    : array($full_site_rows[0]);
            }
            if (!empty($full_site_rows)) {
                $selection_meta['selectedClass'] = 'full_site';
                return array($full_site_rows[0]);
            }
            if (!empty($targeted_rows)) {
                $selection_meta['selectedClass'] = 'targeted';
                return array($targeted_rows[0]);
            }
            return array();
        }

        $full_site_quota = min(count($full_site_rows), max(1, (int) ceil($limit / 2)));
        $rows = array_slice($full_site_rows, 0, $full_site_quota);
        $targeted_quota = min(count($targeted_rows), max(0, $limit - count($rows)));
        if ($targeted_quota > 0) {
            $rows = array_merge($rows, array_slice($targeted_rows, 0, $targeted_quota));
        }
        if (count($rows) < $limit && count($full_site_rows) > $full_site_quota) {
            $rows = array_merge($rows, array_slice($full_site_rows, $full_site_quota, $limit - count($rows)));
        }
        if (count($rows) < $limit && count($targeted_rows) > $targeted_quota) {
            $rows = array_merge($rows, array_slice($targeted_rows, $targeted_quota, $limit - count($rows)));
        }

        return array_slice($rows, 0, $limit);
    }

    private static function count_cron_warm_unprocessed_full_site_queue_rows($ensure_schema = true)
    {
        global $wpdb;

        $queue_ready = $ensure_schema ? self::ensure_cron_warm_queue_table() : self::cron_warm_queue_table_read_ready();
        if (!$queue_ready) {
            return 0;
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Counts only active selected members of the current full-site plan.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE job_type = %s AND status IN (%s, %s) AND FIND_IN_SET(%s, source_contexts) > 0',
                $table,
                'page_warm',
                'pending',
                'processing',
                self::get_cron_warm_full_site_context_marker()
            )
        );
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
                "SELECT COUNT(*) FROM %i WHERE status = %s AND (job_type = %s OR (job_type = %s AND FIND_IN_SET(%s, required_stages) > %d))",
                $table,
                'pending',
                'lcp_refresh',
                'page_warm',
                'lcp_refresh',
                0
            )
        );
    }

    private static function resume_deferred_lcp_refresh_queue()
    {
        if (self::is_manual_warmup_blocking_cron()) {
            return false;
        }

        $pending = self::count_pending_lcp_refresh_queue_rows();
        $settings = self::get_settings();
        $pages_per_minute = self::get_shared_automation_pages_per_minute($settings);
        if ($pending < 1 || $pages_per_minute < 1) {
            return false;
        }

        $now = time();
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
            'pagesPerMinute'    => $pages_per_minute,
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

    /**
     * Resolve completed canonical stages from one unified pipeline result.
     *
     * @param array $required_stages Required canonical stage names.
     * @param array $result          Unified pipeline result.
     * @return string
     */
    private static function get_completed_cron_warm_queue_stages(array $required_stages, array $result)
    {
        $pipeline_stages = isset($result['pipeline']['stages']) && is_array($result['pipeline']['stages'])
            ? $result['pipeline']['stages']
            : array();
        $completed = array();
        foreach ($required_stages as $stage) {
            $pipeline_key = $stage;
            if ('css_bundle' === $stage) {
                $pipeline_key = 'css';
            } elseif ('lcp_refresh' === $stage) {
                $pipeline_key = 'lcp';
            }
            $stage_result = isset($pipeline_stages[$pipeline_key]) && is_array($pipeline_stages[$pipeline_key])
                ? $pipeline_stages[$pipeline_key]
                : array();
            $stage_status = sanitize_key((string) ($stage_result['status'] ?? ''));
            $skip_reason = sanitize_key((string) ($stage_result['details']['reason'] ?? ''));
            if (
                'completed' === $stage_status
                || ('skipped' === $stage_status && 'dependency' !== $skip_reason)
                || ('disabled' === $stage_status && !empty($result['success']))
            ) {
                $completed[] = $stage;
            }
        }
        return self::normalize_cron_warm_queue_csv(
            $completed,
            array('html', 'css_bundle', 'lcp_refresh', 'varnish', 'litespeed')
        );
    }

    /**
     * Resolve canonical stages that actually failed during one pipeline attempt.
     *
     * @param array $required_stages Required canonical stages.
     * @param array $result          Unified pipeline result.
     * @param array $completed       Canonical stages already completed after this attempt.
     * @return string
     */
    private static function get_failed_cron_warm_queue_stages(array $required_stages, array $result, array $completed = array())
    {
        $pipeline_stages = isset($result['pipeline']['stages']) && is_array($result['pipeline']['stages'])
            ? $result['pipeline']['stages']
            : array();
        $failed = array();
        foreach ($required_stages as $stage) {
            if (in_array($stage, $completed, true)) {
                continue;
            }
            $pipeline_key = 'css_bundle' === $stage ? 'css' : ('lcp_refresh' === $stage ? 'lcp' : $stage);
            if ('failed' === sanitize_key((string) ($pipeline_stages[$pipeline_key]['status'] ?? ''))) {
                $failed[] = $stage;
            }
        }

        if (empty($failed) && empty($result['success']) && empty($result['skipped'])) {
            $failure_class = sanitize_key((string) ($result['failureClass'] ?? ''));
            $preferred = '';
            if (false !== strpos($failure_class, 'litespeed')) {
                $preferred = 'litespeed';
            } elseif (false !== strpos($failure_class, 'varnish') || false !== strpos($failure_class, 'refresh-ahead')) {
                $preferred = 'varnish';
            } elseif (false !== strpos($failure_class, 'css')) {
                $preferred = 'css_bundle';
            } elseif (false !== strpos($failure_class, 'lcp')) {
                $preferred = 'lcp_refresh';
            }
            if ('' !== $preferred && in_array($preferred, $required_stages, true) && !in_array($preferred, $completed, true)) {
                $failed[] = $preferred;
            } else {
                foreach ($required_stages as $stage) {
                    if (!in_array($stage, $completed, true)) {
                        $failed[] = $stage;
                        break;
                    }
                }
            }
        }

        return self::normalize_cron_warm_queue_csv(
            $failed,
            array('html', 'css_bundle', 'lcp_refresh', 'varnish', 'litespeed')
        );
    }

    /**
     * Return the retry policy for the stage that failed, preserving the legacy
     * global filter as the HTML/default baseline.
     *
     * @param string $stage Canonical stage name.
     * @return array{maxAttempts:int,delays:array}
     */
    private static function get_cron_warm_stage_retry_policy($stage)
    {
        $stage = sanitize_key((string) $stage);
        $legacy_max = max(1, min(10, (int) apply_filters('ultracache_warm_pipeline_max_attempts', 3)));
        $policies = array(
            'html' => array('maxAttempts' => $legacy_max, 'delays' => array(30, 120, 300, 600)),
            'css_bundle' => array('maxAttempts' => 3, 'delays' => array(60, 180, 600)),
            'lcp_refresh' => array('maxAttempts' => 3, 'delays' => array(60, 300, 900)),
            'varnish' => array('maxAttempts' => 5, 'delays' => array(30, 120, 300, 600, 900)),
            'litespeed' => array('maxAttempts' => 5, 'delays' => array(30, 120, 300, 600, 900)),
        );
        $policy = $policies[$stage] ?? $policies['html'];
        $policy = apply_filters('ultracache_warm_pipeline_stage_retry_policy', $policy, $stage);
        $max_attempts = max(1, min(10, (int) ($policy['maxAttempts'] ?? $legacy_max)));
        $delays = isset($policy['delays']) && is_array($policy['delays']) ? $policy['delays'] : array(30, 120, 300, 600);
        $delays = array_values(array_map(static function ($delay) {
            return max(1, min(3600, absint($delay)));
        }, $delays));
        if (empty($delays)) {
            $delays = array(30);
        }
        return array('maxAttempts' => $max_attempts, 'delays' => $delays);
    }

    private static function mark_cron_warm_queue_row_processed(array $row, $status, $message = '', $retryable = false, $result_level = '', $completed_stages = '', $failed_stages = '', $failure_class = '')
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
        $allowed_stages = array('html', 'css_bundle', 'lcp_refresh', 'varnish', 'litespeed');
        $completed_stages = self::normalize_cron_warm_queue_csv($completed_stages, $allowed_stages);
        $failed_stages = self::normalize_cron_warm_queue_csv($failed_stages, $allowed_stages);
        $failed_stage_list = '' === $failed_stages ? array() : explode(',', $failed_stages);
        $primary_failed_stage = 'html';
        foreach (array('html', 'css_bundle', 'lcp_refresh', 'varnish', 'litespeed') as $candidate_stage) {
            if (in_array($candidate_stage, $failed_stage_list, true)) {
                $primary_failed_stage = $candidate_stage;
                break;
            }
        }
        $failure_class = sanitize_key((string) $failure_class);

        $previous_completed_csv = self::normalize_cron_warm_queue_csv((string) ($row['completed_stages'] ?? ''), $allowed_stages);
        $previous_completed = '' === $previous_completed_csv ? array() : explode(',', $previous_completed_csv);
        $current_completed = '' === $completed_stages ? array() : explode(',', $completed_stages);
        $made_progress = !empty(array_diff($current_completed, $previous_completed));

        $attempt_count = max(1, (int) ($row['attempt_count'] ?? 1));
        $stage_attempt_count = $made_progress ? 1 : $attempt_count;
        $persist_attempt_count = $attempt_count;
        $next_attempt_at = 0;
        $processed_at = time();
        $retrying = false;
        $retryable = (bool) $retryable;
        if ('error' === $status && $retryable) {
            $policy = self::get_cron_warm_stage_retry_policy($primary_failed_stage);
            if ($stage_attempt_count < (int) $policy['maxAttempts']) {
                $status = 'pending';
                $retrying = true;
                $processed_at = 0;
                $persist_attempt_count = $stage_attempt_count;
                $delay_index = max(0, min(count($policy['delays']) - 1, $stage_attempt_count - 1));
                $next_attempt_at = time() + (int) $policy['delays'][$delay_index];
            }
        }

        $has_partial_progress = !empty($current_completed) && !empty($failed_stage_list);
        if ('pending' === $status) {
            $result_level = 'retrying';
        } elseif ('error' === $status) {
            $result_level = $has_partial_progress ? 'partial' : 'error';
        } elseif ('skipped' === $status) {
            $result_level = 'skipped';
        } elseif (!in_array($result_level, array('success', 'warning'), true)) {
            $result_level = 'success';
        }

        if ('' !== $primary_failed_stage && ('error' === $status || 'pending' === $status)) {
            $stage_label = str_replace('_', ' ', $primary_failed_stage);
            $class_suffix = '' !== $failure_class ? ' [' . $failure_class . ']' : '';
            $message = trim($message . ' Stage: ' . $stage_label . $class_suffix . '.');
            if (strlen($message) > 2000) {
                $message = substr($message, 0, 2000);
            }
        }

        $rerun_message = self::maybe_translate('A newer warm request arrived while this row was processing; the shared queue will run it again.');
        $table = self::get_cron_warm_queue_table_name();
        $now = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional claim token prevents a delayed worker from overwriting newer queue ownership or a requested rerun.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET status = CASE WHEN rerun_requested = %d THEN %s ELSE %s END, result_level = CASE WHEN rerun_requested = %d THEN %s ELSE %s END, claim_token = %s, claimed_at = %d, lease_expires_at = %d, pending_targets = CASE WHEN rerun_requested = %d THEN %s WHEN %s = %s THEN pending_targets ELSE %s END, completed_stages = CASE WHEN rerun_requested = %d THEN rerun_completed_stages ELSE CONCAT_WS(',', IF(FIND_IN_SET('css_bundle', completed_stages) > 0 OR FIND_IN_SET('css_bundle', %s) > 0, 'css_bundle', NULL), IF(FIND_IN_SET('html', completed_stages) > 0 OR FIND_IN_SET('html', %s) > 0, 'html', NULL), IF(FIND_IN_SET('lcp_refresh', completed_stages) > 0 OR FIND_IN_SET('lcp_refresh', %s) > 0, 'lcp_refresh', NULL), IF(FIND_IN_SET('litespeed', completed_stages) > 0 OR FIND_IN_SET('litespeed', %s) > 0, 'litespeed', NULL), IF(FIND_IN_SET('varnish', completed_stages) > 0 OR FIND_IN_SET('varnish', %s) > 0, 'varnish', NULL)) END, rerun_completed_stages = %s, result_message = CASE WHEN rerun_requested = %d THEN %s ELSE %s END, updated_at = %d, processed_at = CASE WHEN rerun_requested = %d THEN %d ELSE %d END, attempt_count = CASE WHEN rerun_requested = %d THEN %d ELSE %d END, next_attempt_at = CASE WHEN rerun_requested = %d THEN %d ELSE %d END, rerun_requested = %d WHERE id = %d AND status = %s AND claim_token = %s",
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
                $completed_stages,
                $completed_stages,
                $completed_stages,
                $completed_stages,
                $completed_stages,
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
                $persist_attempt_count,
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
                'failedStage' => $primary_failed_stage,
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
        $saved_attempt_count = is_array($saved) ? max(0, (int) ($saved['attempt_count'] ?? $persist_attempt_count)) : $persist_attempt_count;
        $saved_next_attempt_at = is_array($saved) ? max(0, (int) ($saved['next_attempt_at'] ?? $next_attempt_at)) : $next_attempt_at;
        $requeued = 'pending' === $saved_status && 0 === $saved_attempt_count;
        if (in_array($saved_status, array('done', 'skipped', 'error'), true)) {
            self::delete_cron_warm_queue_url_hint((string) ($row['url'] ?? ''));
        } else {
            self::set_cron_warm_queue_url_hint((string) ($row['url'] ?? ''));
        }

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
            'failedStage' => $primary_failed_stage,
            'partial' => 'partial' === $saved_result_level,
        );
    }

    /**
     * Aggregate lifecycle states for the shared warm queue.
     *
     * @return array
     */
    private static function get_cron_warm_queue_lifecycle_status($ensure_schema = true)
    {
        $queue_status = self::get_cron_warm_queue_stage_status($ensure_schema);
        return isset($queue_status['lifecycle']) && is_array($queue_status['lifecycle'])
            ? $queue_status['lifecycle']
            : array(
                'planned' => 0,
                'processing' => 0,
                'retrying' => 0,
                'warnings' => 0,
                'partial' => 0,
                'completed' => 0,
                'skipped' => 0,
                'terminalErrors' => 0,
                'failed' => 0,
            );
    }

    /**
     * Read unique URL and canonical stage counters for the shared automation queue.
     *
     * @return array
     */
    private static function get_cron_warm_queue_stage_status($ensure_schema = true)
    {
        global $wpdb;

        $status = array(
            'pendingUrls' => 0,
            'processingUrls' => 0,
            'executablePendingUrls' => 0,
            'retryingUrls' => 0,
            'nextRetryAt' => 0,
            'pendingStages' => 0,
            'processingStages' => 0,
            'lifecycle' => array(
                'planned' => 0,
                'processing' => 0,
                'retrying' => 0,
                'warnings' => 0,
                'partial' => 0,
                'completed' => 0,
                'skipped' => 0,
                'terminalErrors' => 0,
                'failed' => 0,
            ),
            'stages' => array(
                'html' => array('pending' => 0, 'processing' => 0),
                'cssBundle' => array('pending' => 0, 'processing' => 0),
                'lcpRefresh' => array('pending' => 0, 'processing' => 0),
                'varnish' => array('pending' => 0, 'processing' => 0),
                'liteSpeed' => array('pending' => 0, 'processing' => 0),
            ),
        );
        $queue_ready = $ensure_schema ? self::ensure_cron_warm_queue_table() : self::cron_warm_queue_table_read_ready();
        if (!($wpdb instanceof wpdb) || !$queue_ready) {
            return $status;
        }

        $table = self::get_cron_warm_queue_table_name();
        $now = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads one bounded aggregate snapshot from the UltraCache-owned canonical queue.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN status = %s AND attempt_count = %d THEN 1 ELSE 0 END) AS planned_rows,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS processing_rows,
                    SUM(CASE WHEN status = %s AND attempt_count > %d THEN 1 ELSE 0 END) AS retrying_rows,
                    SUM(CASE WHEN status = %s AND result_level = %s THEN 1 ELSE 0 END) AS warning_rows,
                    SUM(CASE WHEN status = %s AND result_level = %s THEN 1 ELSE 0 END) AS partial_rows,
                    SUM(CASE WHEN status = %s AND result_level <> %s THEN 1 ELSE 0 END) AS completed_rows,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS skipped_rows,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS terminal_error_rows,
                    COUNT(DISTINCT CASE WHEN status = %s THEN url_hash ELSE NULL END) AS pending_urls,
                    COUNT(DISTINCT CASE WHEN status = %s THEN url_hash ELSE NULL END) AS processing_urls,
                    COUNT(DISTINCT CASE WHEN status = %s AND next_attempt_at <= %d THEN url_hash ELSE NULL END) AS executable_pending_urls,
                    COUNT(DISTINCT CASE WHEN status = %s AND attempt_count > %d THEN url_hash ELSE NULL END) AS retrying_urls,
                    MIN(CASE WHEN status = %s AND attempt_count > %d AND next_attempt_at > %d THEN next_attempt_at ELSE NULL END) AS next_retry_at,
                    COUNT(DISTINCT CASE WHEN status = %s AND job_type <> 'varnish_invalidate' AND FIND_IN_SET(%s, required_stages) > %d AND FIND_IN_SET(%s, completed_stages) = %d THEN CONCAT(url_hash, %s) ELSE NULL END) AS pending_html,
                    COUNT(DISTINCT CASE WHEN status = %s AND job_type <> 'varnish_invalidate' AND FIND_IN_SET(%s, required_stages) > %d AND FIND_IN_SET(%s, completed_stages) = %d THEN CONCAT(url_hash, %s) ELSE NULL END) AS processing_html,
                    COUNT(DISTINCT CASE WHEN status = %s AND FIND_IN_SET(%s, required_stages) > %d AND FIND_IN_SET(%s, completed_stages) = %d THEN CONCAT(url_hash, %s) ELSE NULL END) AS pending_css,
                    COUNT(DISTINCT CASE WHEN status = %s AND FIND_IN_SET(%s, required_stages) > %d AND FIND_IN_SET(%s, completed_stages) = %d THEN CONCAT(url_hash, %s) ELSE NULL END) AS processing_css,
                    COUNT(DISTINCT CASE WHEN status = %s AND FIND_IN_SET(%s, required_stages) > %d AND FIND_IN_SET(%s, completed_stages) = %d THEN CONCAT(url_hash, %s) ELSE NULL END) AS pending_lcp,
                    COUNT(DISTINCT CASE WHEN status = %s AND FIND_IN_SET(%s, required_stages) > %d AND FIND_IN_SET(%s, completed_stages) = %d THEN CONCAT(url_hash, %s) ELSE NULL END) AS processing_lcp,
                    COUNT(DISTINCT CASE WHEN status = %s AND (job_type = %s OR (FIND_IN_SET(%s, required_stages) > %d AND FIND_IN_SET(%s, completed_stages) = %d)) THEN CONCAT(url_hash, %s) ELSE NULL END) AS pending_varnish,
                    COUNT(DISTINCT CASE WHEN status = %s AND (job_type = %s OR (FIND_IN_SET(%s, required_stages) > %d AND FIND_IN_SET(%s, completed_stages) = %d)) THEN CONCAT(url_hash, %s) ELSE NULL END) AS processing_varnish,
                    COUNT(DISTINCT CASE WHEN status = %s AND FIND_IN_SET(%s, required_stages) > %d AND FIND_IN_SET(%s, completed_stages) = %d THEN CONCAT(url_hash, %s) ELSE NULL END) AS pending_litespeed,
                    COUNT(DISTINCT CASE WHEN status = %s AND FIND_IN_SET(%s, required_stages) > %d AND FIND_IN_SET(%s, completed_stages) = %d THEN CONCAT(url_hash, %s) ELSE NULL END) AS processing_litespeed
                FROM %i
                WHERE job_type IN (%s, %s, %s, %s)",
                'pending', 0,
                'processing',
                'pending', 0,
                'done', 'warning',
                'error', 'partial',
                'done', 'warning',
                'skipped',
                'error',
                'pending',
                'processing',
                'pending', $now,
                'pending', 0,
                'pending', 0, $now,
                'pending', 'html', 0, 'html', 0, ':html',
                'processing', 'html', 0, 'html', 0, ':html',
                'pending', 'css_bundle', 0, 'css_bundle', 0, ':css_bundle',
                'processing', 'css_bundle', 0, 'css_bundle', 0, ':css_bundle',
                'pending', 'lcp_refresh', 0, 'lcp_refresh', 0, ':lcp_refresh',
                'processing', 'lcp_refresh', 0, 'lcp_refresh', 0, ':lcp_refresh',
                'pending', 'varnish_invalidate', 'varnish', 0, 'varnish', 0, ':varnish',
                'processing', 'varnish_invalidate', 'varnish', 0, 'varnish', 0, ':varnish',
                'pending', 'litespeed', 0, 'litespeed', 0, ':litespeed',
                'processing', 'litespeed', 0, 'litespeed', 0, ':litespeed',
                $table,
                'page_warm',
                'css_bundle',
                'lcp_refresh',
                'varnish_invalidate'
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return $status;
        }

        $status['lifecycle']['planned'] = max(0, (int) ($row['planned_rows'] ?? 0));
        $status['lifecycle']['processing'] = max(0, (int) ($row['processing_rows'] ?? 0));
        $status['lifecycle']['retrying'] = max(0, (int) ($row['retrying_rows'] ?? 0));
        $status['lifecycle']['warnings'] = max(0, (int) ($row['warning_rows'] ?? 0));
        $status['lifecycle']['partial'] = max(0, (int) ($row['partial_rows'] ?? 0));
        $status['lifecycle']['completed'] = max(0, (int) ($row['completed_rows'] ?? 0));
        $status['lifecycle']['skipped'] = max(0, (int) ($row['skipped_rows'] ?? 0));
        $status['lifecycle']['terminalErrors'] = max(0, (int) ($row['terminal_error_rows'] ?? 0));
        $status['lifecycle']['failed'] = max(0, $status['lifecycle']['terminalErrors'] - $status['lifecycle']['partial']);
        $status['pendingUrls'] = max(0, (int) ($row['pending_urls'] ?? 0));
        $status['processingUrls'] = max(0, (int) ($row['processing_urls'] ?? 0));
        $status['executablePendingUrls'] = max(0, (int) ($row['executable_pending_urls'] ?? 0));
        $status['retryingUrls'] = max(0, (int) ($row['retrying_urls'] ?? 0));
        $status['nextRetryAt'] = max(0, (int) ($row['next_retry_at'] ?? 0));
        $status['stages']['html']['pending'] = max(0, (int) ($row['pending_html'] ?? 0));
        $status['stages']['html']['processing'] = max(0, (int) ($row['processing_html'] ?? 0));
        $status['stages']['cssBundle']['pending'] = max(0, (int) ($row['pending_css'] ?? 0));
        $status['stages']['cssBundle']['processing'] = max(0, (int) ($row['processing_css'] ?? 0));
        $status['stages']['lcpRefresh']['pending'] = max(0, (int) ($row['pending_lcp'] ?? 0));
        $status['stages']['lcpRefresh']['processing'] = max(0, (int) ($row['processing_lcp'] ?? 0));
        $status['stages']['varnish']['pending'] = max(0, (int) ($row['pending_varnish'] ?? 0));
        $status['stages']['varnish']['processing'] = max(0, (int) ($row['processing_varnish'] ?? 0));
        $status['stages']['liteSpeed']['pending'] = max(0, (int) ($row['pending_litespeed'] ?? 0));
        $status['stages']['liteSpeed']['processing'] = max(0, (int) ($row['processing_litespeed'] ?? 0));

        foreach ($status['stages'] as $stage) {
            $status['pendingStages'] += max(0, (int) ($stage['pending'] ?? 0));
            $status['processingStages'] += max(0, (int) ($stage['processing'] ?? 0));
        }

        return $status;
    }

    /**
     * Read the oldest active canonical queue claim for worker status output.
     *
     * @return array
     */
    private static function get_cron_warm_queue_current_activity($ensure_schema = true)
    {
        global $wpdb;

        $queue_ready = $ensure_schema ? self::ensure_cron_warm_queue_table() : self::cron_warm_queue_table_read_ready();
        if (!($wpdb instanceof wpdb) || !$queue_ready) {
            return array();
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads one currently claimed UltraCache queue row for status output.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT url, job_type, source_context, required_stages, completed_stages FROM %i WHERE status = %s AND job_type IN (%s, %s, %s, %s, %s) ORDER BY claimed_at ASC, id ASC LIMIT %d',
                $table,
                'processing',
                'page_warm',
                'css_bundle',
                'lcp_refresh',
                'varnish_invalidate',
                'litespeed_invalidate',
                1
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return array();
        }

        $job_type = sanitize_key((string) ($row['job_type'] ?? 'page_warm'));
        $required = self::get_cron_warm_queue_row_required_stages($row);
        $completed_csv = self::normalize_cron_warm_queue_csv((string) ($row['completed_stages'] ?? ''));
        $completed = '' === $completed_csv ? array() : explode(',', $completed_csv);
        $legacy_stage_map = array(
            'css_bundle' => 'css_bundle',
            'lcp_refresh' => 'lcp_refresh',
            'varnish_invalidate' => 'varnish',
            'litespeed_invalidate' => 'litespeed',
        );
        $current_stage = (string) ($legacy_stage_map[$job_type] ?? '');
        if ('' === $current_stage) {
            foreach (array('html', 'css_bundle', 'lcp_refresh', 'varnish', 'litespeed') as $stage) {
                if (in_array($stage, $required, true) && !in_array($stage, $completed, true)) {
                    $current_stage = $stage;
                    break;
                }
            }
        }

        return array(
            'url' => esc_url_raw((string) ($row['url'] ?? '')),
            'stage' => sanitize_key($current_stage),
            'sourceContext' => sanitize_key((string) ($row['source_context'] ?? '')),
        );
    }








    private static function get_shared_automation_pages_per_minute(array $settings = array())
    {
        return self::get_configured_warm_rate_limit($settings);
    }

    private static function get_cron_warm_recovery_lock_name()
    {
        return 'ultracache_cron_warm_recovery';
    }

    private static function get_cron_warm_recovery_throttle_lock_name()
    {
        return 'ultracache_cron_warm_recovery_throttle';
    }

    private static function get_cron_warm_recovery_throttle_lock_token()
    {
        return 'cron-warm-recovery-throttle';
    }

    private static function is_cron_warm_recovery_throttled()
    {
        if (!function_exists('ultracache_get_lock_read_only')) {
            return false;
        }

        $lock = ultracache_get_lock_read_only(self::get_cron_warm_recovery_throttle_lock_name());
        return !empty($lock['token']) && empty($lock['expired']);
    }

    private static function refresh_cron_warm_recovery_throttle()
    {
        if (!function_exists('ultracache_acquire_lock') || !function_exists('ultracache_renew_lock')) {
            return false;
        }

        $lock_name = self::get_cron_warm_recovery_throttle_lock_name();
        $token = self::get_cron_warm_recovery_throttle_lock_token();
        $payload = array('checkedAt' => time());
        if (ultracache_acquire_lock($lock_name, $token, MINUTE_IN_SECONDS, $payload)) {
            return true;
        }

        return ultracache_renew_lock($lock_name, $token, MINUTE_IN_SECONDS, $payload);
    }

    private static function clear_expired_cron_warm_execution_lock()
    {
        $decision_recovery = self::recover_expired_warm_decision_leases();
        $decision_recovered = !empty($decision_recovery['cronExpired']);

        if (!function_exists('ultracache_get_lock') || !function_exists('ultracache_release_lock')) {
            return $decision_recovered;
        }

        $lock = ultracache_get_lock(self::get_cron_warm_lock_name());
        if (empty($lock['token']) || empty($lock['expired'])) {
            return $decision_recovered;
        }

        $released = ultracache_release_lock(self::get_cron_warm_lock_name(), (string) $lock['token']);
        return $decision_recovered || $released;
    }

    private static function resume_orphaned_cron_warm_queue_state()
    {
        if (self::is_manual_warmup_blocking_cron()) {
            return false;
        }

        if (self::resume_full_site_warm_after_foreground()) {
            return true;
        }
        if (self::resume_active_full_site_warm_plan()) {
            return true;
        }

        $state = self::get_cron_warm_state();
        if (!empty($state['active'])) {
            return false;
        }

        $decision = self::get_warm_decision_status(false);
        if (!empty($decision['cron']['active'])) {
            return false;
        }

        $pending = self::count_cron_warm_pending_queue_rows();
        if ($pending < 1) {
            return false;
        }

        $settings = self::get_settings();
        $pages_per_minute = self::get_shared_automation_pages_per_minute($settings);
        if ($pages_per_minute < 1) {
            return false;
        }

        $total = $pending;
        $now = time();
        self::save_cron_warm_state(array(
            'active' => true,
            'reason' => 'queue_recovery',
            'cursor' => '',
            'processed' => 0,
            'total' => $total,
            'successCount' => 0,
            'skippedCount' => 0,
            'errorCount' => 0,
            'startedAt' => $now,
            'updatedAt' => $now,
            'lastRunAt' => 0,
            'finishedAt' => 0,
            'pagesPerMinute' => $pages_per_minute,
            'totalLimit' => 0,
            'workloadType' => 'targeted',
            'fullSiteDiscoveryComplete' => true,
            'currentBatch' => array(),
            'batchIndex' => 0,
            'batchHasMore' => false,
            'nextCursorPending' => '',
            'lastError' => '',
            'lastMessage' => self::maybe_translate('An orphaned automation queue was reattached to the shared worker.'),
            'lastUrl' => '',
            'completed' => false,
            'stopped' => false,
            'stopReason' => '',
            'invokedBy' => 'automatic-worker-recovery',
            'warmupGeneration' => self::get_warmup_generation(),
            'workerRecovery' => array(
                'lastRecoveredAt' => $now,
                'recoveredQueueRows' => 0,
                'recoveredExecutionLock' => false,
                'resumedQueueState' => true,
                'restoredSchedule' => false,
                'message' => self::maybe_translate('Orphaned queue state resumed.'),
            ),
        ));

        return true;
    }

    public function handle_cron_warm_worker_recovery()
    {
        self::maybe_recover_cron_warm_worker();
    }

    private static function maybe_recover_cron_warm_worker($force = false)
    {
        if (!$force && self::is_cron_warm_recovery_throttled()) {
            return array('recovered' => false, 'throttled' => true);
        }

        $token = 'cron-warm-recovery-' . wp_generate_password(20, false, false);
        if (
            !function_exists('ultracache_acquire_lock')
            || !ultracache_acquire_lock(
                self::get_cron_warm_recovery_lock_name(),
                $token,
                30,
                array('startedAt' => time())
            )
        ) {
            return array('recovered' => false, 'locked' => true);
        }

        try {
            self::refresh_cron_warm_recovery_throttle();
            if (method_exists(static::class, 'ensure_cache_asset_refs_gc_event_scheduled')) {
                self::ensure_cache_asset_refs_gc_event_scheduled();
            }

            $recovered_rows = self::recover_expired_cron_warm_queue_leases();
            $recovered_lock = self::clear_expired_cron_warm_execution_lock();
            $blocked = self::is_manual_warmup_blocking_cron();
            $resumed_state = false;
            $restored_schedule = false;

            if (!$blocked) {
                $resumed_state = self::resume_orphaned_cron_warm_queue_state();

                $state = self::get_cron_warm_state();
                $queued_pending = self::count_cron_warm_pending_queue_rows(false);
                $queued_processing = self::count_cron_warm_processing_queue_rows(false);
                $varnish_queue = method_exists(static::class, 'get_varnish_queue_stats')
                    ? self::get_varnish_queue_stats()
                    : array();
                $has_varnish_work = max(0, (int) ($varnish_queue['pendingInvalidations'] ?? 0)) > 0
                    || max(0, (int) ($varnish_queue['processingInvalidations'] ?? 0)) > 0;
                $litespeed_queue = method_exists(static::class, 'get_litespeed_invalidation_queue_stats')
                    ? self::get_litespeed_invalidation_queue_stats()
                    : array();
                $has_litespeed_invalidation_work = max(0, (int) ($litespeed_queue['pending'] ?? 0)) > 0
                    || max(0, (int) ($litespeed_queue['processing'] ?? 0)) > 0;
                $keep_varnish_refresh_ahead = method_exists(static::class, 'should_keep_varnish_refresh_ahead_cron')
                    && self::should_keep_varnish_refresh_ahead_cron();
                $settings = self::get_settings();
                $generic_worker_enabled = self::get_shared_automation_pages_per_minute($settings) > 0;
                $needs_worker = !empty($state['active'])
                    || ($generic_worker_enabled && ($queued_pending > 0 || $queued_processing > 0))
                    || $has_varnish_work
                    || $has_litespeed_invalidation_work
                    || $keep_varnish_refresh_ahead;

                if ($needs_worker && self::get_next_cron_warm_scheduled_at() < 1) {
                    self::ensure_cron_warm_events_scheduled(1, true);
                    $restored_schedule = self::get_next_cron_warm_scheduled_at() > 0;
                }
            }

            $recovered = $recovered_rows > 0 || $recovered_lock || $resumed_state || $restored_schedule;
            if ($recovered) {
                $messages = array();
                if ($recovered_rows > 0) {
                    $messages[] = self::maybe_translate_sprintf('%d expired queue lease(s) recovered', $recovered_rows);
                }
                if ($recovered_lock) {
                    $messages[] = self::maybe_translate('Expired worker lock released');
                }
                if ($resumed_state) {
                    $messages[] = self::maybe_translate('Orphaned queue state resumed');
                }
                if ($restored_schedule) {
                    $messages[] = self::maybe_translate('Missing worker schedule restored');
                }

                $state = self::get_cron_warm_state();
                $state['workerRecovery'] = array(
                    'lastRecoveredAt' => time(),
                    'recoveredQueueRows' => $recovered_rows,
                    'recoveredExecutionLock' => $recovered_lock,
                    'resumedQueueState' => $resumed_state,
                    'restoredSchedule' => $restored_schedule,
                    'message' => implode('; ', $messages) . '.',
                );
                self::save_cron_warm_state($state);
            }

            return array(
                'recovered' => $recovered,
                'recoveredQueueRows' => $recovered_rows,
                'recoveredExecutionLock' => $recovered_lock,
                'resumedQueueState' => $resumed_state,
                'restoredSchedule' => $restored_schedule,
                'blockedByManualWarm' => $blocked,
            );
        } finally {
            if (function_exists('ultracache_release_lock')) {
                ultracache_release_lock(self::get_cron_warm_recovery_lock_name(), $token);
            }
        }
    }

    private static function normalize_warm_worker_display_stage($stage)
    {
        $stage = sanitize_key((string) $stage);
        if (false !== strpos($stage, 'varnish')) {
            return 'varnish';
        }
        if (false !== strpos($stage, 'litespeed')) {
            return 'litespeed';
        }
        if (false !== strpos($stage, 'lcp')) {
            return 'lcp_refresh';
        }
        if (false !== strpos($stage, 'css')) {
            return 'css_bundle';
        }
        if (
            false !== strpos($stage, 'html')
            || false !== strpos($stage, 'pipeline')
            || false !== strpos($stage, 'preflight')
        ) {
            return 'html';
        }
        return $stage;
    }

    private static function get_cron_warm_worker_health_status(array $state, array $queue_status, array $manual_warm, $next_scheduled, array $decision = array(), array $execution_mutex = array(), array $settings = array(), array $varnish_queue = array())
    {
        $next_scheduled = max(0, (int) $next_scheduled);
        $pending_urls = max(0, (int) ($queue_status['pendingUrls'] ?? 0));
        $processing_urls = max(0, (int) ($queue_status['processingUrls'] ?? 0));
        $executable_pending_urls = max(0, (int) ($queue_status['executablePendingUrls'] ?? 0));
        $pending_stages = max(0, (int) ($queue_status['pendingStages'] ?? 0));
        $processing_stages = max(0, (int) ($queue_status['processingStages'] ?? 0));
        $next_retry_at = max(0, (int) ($queue_status['nextRetryAt'] ?? 0));
        $recovery = self::normalize_cron_warm_worker_recovery_state($state['workerRecovery'] ?? array());
        if (empty($decision)) {
            $decision = self::get_warm_decision_status(false);
        }
        $foreground = isset($decision['foreground']) && is_array($decision['foreground']) ? $decision['foreground'] : array();
        $cron_owner = isset($decision['cron']) && is_array($decision['cron']) ? $decision['cron'] : array();
        $manual_running = !empty($foreground['active']);
        $manual_paused = 'paused' === (string) ($foreground['status'] ?? '');
        $cron_running = !empty($cron_owner['active']);
        $cron_expired = 'expired' === (string) ($cron_owner['status'] ?? '');
        if (empty($settings)) {
            $settings = self::get_settings();
        }
        $pending_page_urls = self::count_cron_warm_pending_queue_rows(false);
        $processing_page_urls = self::count_cron_warm_processing_queue_rows(false);
        $pending_varnish_invalidations = max(0, (int) ($varnish_queue['pendingInvalidations'] ?? 0));
        $processing_varnish_invalidations = max(0, (int) ($varnish_queue['processingInvalidations'] ?? 0));
        $has_varnish_invalidation_work = $pending_varnish_invalidations > 0 || $processing_varnish_invalidations > 0;
        $generic_worker_paused = ($pending_page_urls > 0 || $processing_page_urls > 0 || !empty($state['active']))
            && self::get_shared_automation_pages_per_minute($settings) < 1;

        if (empty($execution_mutex)) {
            if (function_exists('ultracache_get_lock_read_only')) {
                $execution_mutex = ultracache_get_lock_read_only(self::get_cron_warm_lock_name());
            } elseif (function_exists('ultracache_get_lock')) {
                $execution_mutex = ultracache_get_lock(self::get_cron_warm_lock_name());
            }
        }
        $execution_mutex_active = !empty($execution_mutex['token']) && empty($execution_mutex['expired']);
        $execution_mutex_expired = !empty($execution_mutex['token']) && !empty($execution_mutex['expired']);
        $has_work = !empty($state['active']) || $pending_urls > 0 || $processing_urls > 0;
        $recently_recovered = $recovery['lastRecoveredAt'] > time() - 5 * MINUTE_IN_SECONDS;
        $workload_type = self::normalize_cron_warm_workload_type(
            $state['workloadType'] ?? '',
            $state['reason'] ?? ''
        );
        $owner_source = (string) ($decision['ownerSource'] ?? '');
        $current_url = '';
        $current_stage = '';

        if ($manual_running) {
            $owner_source = 'cli' === (string) ($foreground['source'] ?? '') ? 'cli' : 'ui';
            $status = 'cli' === $owner_source ? 'running-cli' : 'running-ui';
            $message = 'cli' === $owner_source
                ? self::maybe_translate('WP-CLI warm-up owns execution. Background automation is yielding until it finishes.')
                : self::maybe_translate('Dashboard warm-up owns execution. Background automation is yielding until it finishes.');
            $current_url = esc_url_raw((string) ($foreground['currentUrl'] ?? ''));
            $current_stage = self::normalize_warm_worker_display_stage($foreground['currentStage'] ?? '');
        } elseif ($cron_running) {
            $status = 'full_site' === $workload_type ? 'running-scheduled' : 'running-targeted';
            $message = 'full_site' === $workload_type
                ? self::maybe_translate('The background worker is processing the current full-site warm-up plan.')
                : self::maybe_translate('The background worker is processing targeted automation work.');
            $current_url = esc_url_raw((string) ($cron_owner['currentUrl'] ?? ''));
            $current_stage = sanitize_key((string) ($cron_owner['currentStage'] ?? ''));
        } elseif ($has_varnish_invalidation_work && $next_scheduled > 0) {
            $status = 'scheduled-varnish-invalidation';
            $message = $generic_worker_paused
                ? self::maybe_translate('Varnish invalidation is scheduled independently; page warming remains paused because Background warm pages per minute is 0.')
                : self::maybe_translate('Varnish invalidation is waiting for the next background worker run.');
        } elseif ($generic_worker_paused) {
            $status = 'paused-configuration';
            $message = self::maybe_translate('Queued page automation is paused because Background warm pages per minute is 0.');
        } elseif ($pending_urls > 0 && $executable_pending_urls < 1 && $next_retry_at > time()) {
            $status = 'waiting-retry';
            $message = self::maybe_translate('Queued work is waiting for its next retry window.');
        } elseif ($has_work && $next_scheduled > 0) {
            if ($recently_recovered) {
                $status = 'recovered';
                $message = '' !== $recovery['message']
                    ? $recovery['message']
                    : self::maybe_translate('Automation work recovered and has an executable schedule.');
            } else {
                $status = 'full_site' === $workload_type ? 'scheduled-full-site' : 'scheduled-targeted';
                $message = 'full_site' === $workload_type
                    ? self::maybe_translate('The full-site warm-up plan is waiting for the next background worker run.')
                    : self::maybe_translate('Targeted automation work is waiting for the next background worker run.');
            }
        } elseif ($has_work || $cron_expired) {
            $status = 'attention';
            $message = $cron_expired
                ? self::maybe_translate('An expired background ownership lease is waiting for automatic recovery.')
                : self::maybe_translate('Automation work is pending without an executable worker schedule.');
        } else {
            $status = 'ready';
            $message = self::maybe_translate('No automation work is waiting.');
        }

        return array(
            'status' => $status,
            'message' => $message,
            'ownerSource' => $owner_source,
            'workloadType' => $workload_type,
            'pendingUrls' => $pending_urls,
            'processingUrls' => $processing_urls,
            'pending' => $pending_urls,
            'processing' => $processing_urls,
            'pendingPageUrls' => max(0, (int) $pending_page_urls),
            'processingPageUrls' => max(0, (int) $processing_page_urls),
            'pendingVarnishInvalidations' => $pending_varnish_invalidations,
            'processingVarnishInvalidations' => $processing_varnish_invalidations,
            'pendingStages' => $pending_stages,
            'processingStages' => $processing_stages,
            'retryingUrls' => max(0, (int) ($queue_status['retryingUrls'] ?? 0)),
            'nextRetryAt' => $next_retry_at,
            'currentUrl' => $current_url,
            'currentStage' => $current_stage,
            'active' => !empty($state['active']) || $manual_running || $cron_running,
            'scheduled' => $next_scheduled > 0,
            'nextScheduledAt' => $next_scheduled,
            'lockActive' => $cron_running,
            'lockExpired' => $cron_expired,
            'lockExpiresAt' => max(0, (int) ($cron_owner['leaseExpiresAt'] ?? 0)),
            'executionMutexActive' => $execution_mutex_active,
            'executionMutexExpired' => $execution_mutex_expired,
            'executionMutexExpiresAt' => max(0, (int) ($execution_mutex['expiresAt'] ?? 0)),
            'blockedByManualWarm' => $manual_running && $has_work,
            'foregroundOwner' => $manual_running ? $manual_warm : array(),
            'pausedForegroundSession' => $manual_paused ? $manual_warm : array(),
            'pausedByWorkLimit' => $generic_worker_paused,
            'lastRecoveredAt' => $recovery['lastRecoveredAt'],
            'recentlyRecovered' => $recently_recovered,
            'decisionGeneration' => max(0, (int) ($decision['decisionGeneration'] ?? 0)),
            'lastDecisionReason' => (string) ($decision['lastDecisionReason'] ?? ''),
        );
    }


    private static function yield_cron_warmup_to_foreground($source)
    {
        self::yield_cron_warm_decision($source);
        $state = self::get_cron_warm_state();
        $state['active'] = false;
        $state['stopped'] = true;
        $state['completed'] = false;
        $state['stopReason'] = 'foreground_warm_priority';
        $state['updatedAt'] = time();
        $state['finishedAt'] = time();
        $state['lastMessage'] = 'cli' === $source
            ? self::maybe_translate('Background automation yielded to WP-CLI warm-up.')
            : self::maybe_translate('Background automation yielded to dashboard warm-up.');
        self::save_cron_warm_state($state);
        self::unschedule_cron_warm_events();
    }

    /**
     * Reattach an active atomic full-site plan to the legacy worker lifecycle.
     *
     * The plan remains authoritative while the background rate is 0 or a
     * foreground owner temporarily pauses cron. Once execution is allowed
     * again, resume the worker without creating a second plan or resetting its
     * committed cursor, batch, limit, or fairness pointer.
     *
     * @param string $message Resume message.
     * @return bool
     */
    private static function resume_active_full_site_warm_plan($message = '')
    {
        if (self::is_manual_warmup_blocking_cron()) {
            return false;
        }

        $plan = self::get_warm_plan_state();
        if (!self::is_warm_plan_active($plan)) {
            return false;
        }

        $state = self::get_cron_warm_state();
        if (!empty($state['active'])) {
            return false;
        }

        $pages_per_minute = self::get_shared_automation_pages_per_minute(self::get_settings());
        if ($pages_per_minute < 1) {
            return false;
        }

        $now = time();
        $state['active'] = true;
        $state['stopped'] = false;
        $state['completed'] = false;
        $state['stopReason'] = '';
        $state['updatedAt'] = $now;
        $state['finishedAt'] = 0;
        $state['pagesPerMinute'] = $pages_per_minute;
        $state['lastMessage'] = '' !== (string) $message
            ? sanitize_text_field((string) $message)
            : self::maybe_translate('Full-site background warm-up resumed from its committed atomic plan.');
        self::save_cron_warm_state($state);
        self::ensure_cron_warm_events_scheduled(1, true);
        return true;
    }

    /**
     * Resume an interrupted full-site plan without converting it into a
     * targeted recovery workload or losing its discovery cursor/limit state.
     *
     * @return bool
     */
    private static function resume_full_site_warm_after_foreground()
    {
        if (self::is_manual_warmup_blocking_cron()) {
            return false;
        }

        $state = self::get_cron_warm_state();
        $stop_reason = sanitize_key((string) ($state['stopReason'] ?? ''));
        if (
            'full_site' !== (string) ($state['workloadType'] ?? '')
            || !in_array($stop_reason, array('foreground_warm_priority', 'manual_warm_priority'), true)
            || !empty($state['completed'])
        ) {
            return false;
        }

        $has_pending_queue = self::count_cron_warm_pending_queue_rows() > 0
            || self::count_cron_warm_processing_queue_rows() > 0;
        $has_pending_discovery = empty($state['fullSiteDiscoveryComplete'])
            || !empty($state['batchHasMore'])
            || '' !== (string) ($state['nextCursorPending'] ?? '');
        if (!$has_pending_queue && !$has_pending_discovery) {
            return false;
        }

        $pages_per_minute = self::get_shared_automation_pages_per_minute(self::get_settings());
        $now = time();
        if ($pages_per_minute < 1) {
            $state['active'] = false;
            $state['stopped'] = true;
            $state['completed'] = false;
            $state['stopReason'] = 'paused';
            $state['updatedAt'] = $now;
            $state['finishedAt'] = $now;
            $state['pagesPerMinute'] = 0;
            $state['lastMessage'] = self::maybe_translate('Full-site background warm-up is paused because Background warm pages per minute is 0.');
            self::save_cron_warm_state($state);
            self::unschedule_cron_warm_events();
            return false;
        }

        $state['active'] = true;
        $state['stopped'] = false;
        $state['completed'] = false;
        $state['stopReason'] = '';
        $state['updatedAt'] = $now;
        $state['finishedAt'] = 0;
        $state['pagesPerMinute'] = $pages_per_minute;
        $state['lastMessage'] = self::maybe_translate('Full-site background warm-up resumed after foreground warm-up.');
        self::save_cron_warm_state($state);
        self::ensure_cron_warm_events_scheduled(1, true);
        return true;
    }

    private static function resume_background_automation_after_foreground_warm()
    {
        if (self::resume_full_site_warm_after_foreground()) {
            return;
        }

        self::resume_deferred_lcp_refresh_queue();
        self::resume_deferred_targeted_page_warm_queue();
        self::resume_orphaned_cron_warm_queue_state();
        if (self::count_cron_warm_pending_queue_rows() > 0 || (method_exists(static::class, 'has_pending_varnish_invalidation_rows') && self::has_pending_varnish_invalidation_rows())) {
            self::ensure_cron_warm_events_scheduled(1, true);
        }
    }

    /**
     * Wait for the current queue owner to release its authoritative worker lock.
     *
     * The decision fence is cleared before this method is called, so the old
     * owner cannot renew the lease and must stop at its next heartbeat. Waiting
     * through the authoritative remaining lease prevents a stale in-flight page
     * write from racing the cache-directory purge that follows.
     *
     * @return bool
     */
    private static function wait_for_cron_warm_execution_quiescence_after_cache_flush()
    {
        if (!function_exists('ultracache_get_lock') || !function_exists('ultracache_release_lock')) {
            return true;
        }

        while (true) {
            $lock = ultracache_get_lock(self::get_cron_warm_lock_name());
            if (empty($lock['token'])) {
                return true;
            }

            if (!empty($lock['expired'])) {
                ultracache_release_lock(self::get_cron_warm_lock_name(), (string) $lock['token']);
                continue;
            }

            usleep(100000);
        }
    }

    public static function reset_cron_warmup_queue_after_cache_flush($reason = 'cache_flush', $preserve_foreground_token = '')
    {
        $preserve_foreground_token = sanitize_text_field((string) $preserve_foreground_token);
        $foreground_preserved = false;
        if ('' !== $preserve_foreground_token) {
            $renewed = self::renew_foreground_warmup_session(
                $preserve_foreground_token,
                'ui',
                'cache-flush-reset'
            );
            $foreground_preserved = !empty($renewed['success']);
        }

        if (!$foreground_preserved) {
            self::reset_manual_warmup_session($reason);
        }
        self::clear_cron_warm_decision('cache_flush_reset');
        $generation = self::bump_warmup_generation($reason);
        self::unschedule_cron_warm_events();
        $worker_quiesced = self::wait_for_cron_warm_execution_quiescence_after_cache_flush();
        $state = self::get_default_cron_warm_state();
        // warm_rate is intentionally untouched. A flush resets queue/plan state
        // but cannot grant a second real-minute background allowance.
        $state['active'] = false;
        $state['stopped'] = true;
        $state['completed'] = false;
        $state['stopReason'] = sanitize_key((string) $reason);
        $state['finishedAt'] = time();
        $state['updatedAt'] = time();
        $state['lastMessage'] = self::maybe_translate('Cron warm up queue reset after cache flush.');
        $state['warmupGeneration'] = $generation;
        $queue_reset = $worker_quiesced && self::reset_cron_warm_queue_table_for_cache_flush();
        self::reset_warm_plan_state();
        self::release_cron_warm_full_site_membership();
        if (!$queue_reset) {
            $state['lastError'] = self::maybe_translate('Flush All could not recreate the warm queue cleanly.');
            $state['lastMessage'] = (string) $state['lastError'];
        }
        self::save_cron_warm_state($state);
        self::unschedule_cron_warm_events();

        $status = self::get_cron_warm_status();
        $status['queueResetSuccess'] = (bool) $queue_reset;
        $status['foregroundPreserved'] = (bool) $foreground_preserved;
        return $status;
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
        return self::get_consolidated_warm_status();
    }

    /**
     * Determine whether a full-site background warm-up is still the active plan.
     *
     * Start requests perform a low-cost pre-lock check and repeat the same check
     * after acquiring the shared warm lock. The second check is authoritative:
     * two concurrent after-flush/cleanup triggers may both read the old state
     * before either request creates the new plan.
     *
     * @param array $state Shared warm worker state.
     * @param int   $now   Current timestamp.
     * @return bool
     */
    private static function is_cron_warm_full_site_plan_fresh(array $state, $now = 0)
    {
        unset($state, $now);
        return self::is_warm_plan_active(self::get_warm_plan_state());
    }

    /** Map one full-site background plan reason to its per-language warm policy operation. */
    private static function get_cron_warm_multilingual_operation($reason)
    {
        $reason = sanitize_key((string) $reason);
        if ('scheduled_cleanup' === $reason) {
            return 'after_cleanup';
        }
        if (in_array($reason, array('manual_purge', 'plugin_update'), true)) {
            return 'after_flush';
        }

        return 'scheduled';
    }

    /** Return whether a multilingual plan has at least one eligible language target. */
    private static function cron_warm_multilingual_operation_has_targets($operation)
    {
        if (!function_exists('ultracache_multilingual_is_active') || !ultracache_multilingual_is_active()) {
            return true;
        }
        if (!function_exists('ultracache_multilingual_get_warm_languages')) {
            return true;
        }

        return !empty(ultracache_multilingual_get_warm_languages($operation));
    }

    public static function start_cron_warmup_queue($reason = 'manual', $run_immediately = false)
    {
        if (self::is_manual_warmup_blocking_cron()) {
            self::yield_cron_warmup_to_foreground((string) (self::get_manual_warm_status()['source'] ?? 'ui'));
            return array(
                'success' => false,
                'message' => self::maybe_translate('Background automation is yielding to an active foreground warm-up.'),
                'state' => self::get_cron_warm_status(),
            );
        }

        $settings = self::get_settings();
        $engine = self::get_engine_instance();
        if (!$engine || !method_exists($engine, 'get_crawl_urls_cursor_batch') || !method_exists($engine, 'warm_page_pipeline')) {
            return array('success' => false, 'message' => self::maybe_translate('Cron warm up is not available.'));
        }

        $multilingual_operation = self::get_cron_warm_multilingual_operation($reason);
        if (!self::cron_warm_multilingual_operation_has_targets($multilingual_operation)) {
            return array(
                'success' => true,
                'queued' => false,
                'message' => self::maybe_translate('No multilingual language is enabled for this automatic warm operation.'),
                'state' => self::get_cron_warm_status(),
            );
        }

        $existing_state = self::get_cron_warm_state();
        if (self::is_cron_warm_full_site_plan_fresh($existing_state) && !empty($existing_state['active'])) {
            return array(
                'success' => true,
                'message' => self::maybe_translate('Full-site background warm-up is already queued or running.'),
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
            // The pre-lock reads above are advisory only. Re-evaluate foreground
            // ownership and full-site state under the shared lock before releasing
            // membership or resetting discovery. This prevents a second concurrent
            // trigger from replacing the plan that the first request just created.
            if (self::is_manual_warmup_blocking_cron()) {
                self::yield_cron_warmup_to_foreground((string) (self::get_manual_warm_status()['source'] ?? 'ui'));
                return array(
                    'success' => false,
                    'message' => self::maybe_translate('Background automation is yielding to an active foreground warm-up.'),
                    'state' => self::get_cron_warm_status(),
                );
            }

            $locked_state = self::get_cron_warm_state();
            if (self::is_cron_warm_full_site_plan_fresh($locked_state)) {
                $resumed = empty($locked_state['active'])
                    ? self::resume_active_full_site_warm_plan(
                        self::maybe_translate('Full-site background warm-up resumed from its committed atomic plan.')
                    )
                    : false;
                return array(
                    'success' => true,
                    'message' => $resumed
                        ? self::maybe_translate('Full-site background warm-up resumed.')
                        : self::maybe_translate('Full-site background warm-up is already queued or running.'),
                    'state' => self::get_cron_warm_status(),
                );
            }

            $pages_per_minute = max(0, (int) $settings['cron_warm_pages_per_minute']);
            $total_limit = max(0, (int) $settings['scheduled_warm_limit']);
            $plan_invoked_by = 'cli' === sanitize_key((string) $reason)
                ? 'wp-cli'
                : ($run_immediately ? 'immediate' : 'background');
            $plan_start = self::start_warm_plan($reason, $plan_invoked_by, $total_limit);
            if (empty($plan_start['success'])) {
                return array(
                    'success' => false,
                    'message' => self::maybe_translate('Full-site warm plan could not be committed.'),
                    'state' => self::get_cron_warm_status(),
                );
            }
            if (!empty($plan_start['alreadyActive'])) {
                return array(
                    'success' => true,
                    'message' => self::maybe_translate('Full-site background warm-up is already queued or running.'),
                    'state' => self::get_cron_warm_status(),
                );
            }

            // A new plan owns a fresh membership set while canonical targeted
            // rows and their stage state remain intact.
            self::release_cron_warm_full_site_membership();
            $state = self::save_cron_warm_state(array(
                'active'         => true,
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
                'lastError'      => '',
                'lastMessage'    => self::maybe_translate('Cron warm up queued.'),
                'lastUrl'        => '',
                'completed'      => false,
                'stopped'        => false,
                'stopReason'     => '',
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
            ? self::maybe_translate('Background automation yielded to an active foreground warm-up.')
            : self::maybe_translate('Cron warm up stopped.');
        $yield_only = in_array($state['stopReason'], array('manual_warm_priority', 'foreground_warm_priority'), true);
        self::clear_cron_warm_queue_table($yield_only);
        if (!$yield_only) {
            self::stop_warm_plan($state['stopReason']);
            self::release_cron_warm_full_site_membership();
        }
        self::save_cron_warm_state($state);
        self::clear_cron_warm_decision('cron_stopped');
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

    /**
     * Acquire the physical cron mutex and its exact authoritative execution fence.
     *
     * @param string                   $lock_token      Lock token.
     * @param int                      $lock_ttl        Lease duration in seconds.
     * @param array<string,mixed>|null $execution_fence Exact source/token/generation fence.
     * @return bool
     */
    private static function acquire_cron_warm_lock($lock_token, $lock_ttl, &$execution_fence = null)
    {
        $lock_ttl = max(10, (int) $lock_ttl);
        $lock_token = sanitize_text_field((string) $lock_token);
        if ('' === $lock_token || !function_exists('ultracache_acquire_lock')) {
            return false;
        }

        $now = time();
        $lock = array(
            'token' => $lock_token,
            'startedAt' => $now,
            'expiresAt' => $now + $lock_ttl,
        );

        if (!ultracache_acquire_lock(self::get_cron_warm_lock_name(), $lock_token, $lock_ttl, $lock)) {
            return false;
        }

        $cron_generation = 0;
        if (!self::acquire_cron_warm_decision($lock_token, $lock_ttl, $cron_generation)) {
            ultracache_release_lock(self::get_cron_warm_lock_name(), $lock_token);
            return false;
        }

        $execution_fence = array(
            'source' => 'cron',
            'token' => $lock_token,
            'generation' => max(0, (int) $cron_generation),
        );
        if (!self::is_warm_execution_fence_current($execution_fence)) {
            self::release_cron_warm_decision($lock_token, 'execution_fence_initialization_failed');
            ultracache_release_lock(self::get_cron_warm_lock_name(), $lock_token);
            $execution_fence = array();
            return false;
        }

        return true;
    }

    /**
     * Renew the physical cron mutex only while the exact decision fence remains current.
     *
     * @param string $lock_token          Lock token.
     * @param int    $lock_ttl            Lease duration in seconds.
     * @param int    $expected_generation Exact cron generation.
     * @param string $stage               Current stage.
     * @param string $url                 Current URL.
     * @return bool
     */
    private static function renew_cron_warm_lock($lock_token, $lock_ttl, $expected_generation = 0, $stage = '', $url = '')
    {
        $lock_ttl = max(10, (int) $lock_ttl);
        $lock_token = sanitize_text_field((string) $lock_token);
        if ('' === $lock_token || !function_exists('ultracache_get_lock') || !function_exists('ultracache_renew_lock')) {
            return false;
        }

        if (!self::renew_cron_warm_decision($lock_token, $lock_ttl, $expected_generation, $stage, $url)) {
            return false;
        }

        $existing = ultracache_get_lock(self::get_cron_warm_lock_name());
        if (empty($existing['token']) || !hash_equals((string) $existing['token'], $lock_token)) {
            self::release_cron_warm_decision($lock_token, 'execution_mutex_missing');
            return false;
        }

        $now = time();
        $existing_payload = isset($existing['payload']) && is_array($existing['payload']) ? $existing['payload'] : array();
        $lock = array(
            'token' => $lock_token,
            'startedAt' => !empty($existing_payload['startedAt']) ? (int) $existing_payload['startedAt'] : max(0, (int) ($existing['acquiredAt'] ?? $now)),
            'expiresAt' => $now + $lock_ttl,
        );

        if (!ultracache_renew_lock(self::get_cron_warm_lock_name(), $lock_token, $lock_ttl, $lock)) {
            self::release_cron_warm_decision($lock_token, 'execution_mutex_renew_failed');
            return false;
        }

        return true;
    }

    private static function release_cron_warm_lock($lock_token)
    {
        $lock_token = sanitize_text_field((string) $lock_token);
        if ('' !== $lock_token) {
            self::release_cron_warm_decision($lock_token, 'cron_released');
        }
        if ('' !== $lock_token && function_exists('ultracache_release_lock')) {
            ultracache_release_lock(self::get_cron_warm_lock_name(), $lock_token);
        }
    }

    /**
     * Encode per-language full-site discovery cursors into one opaque plan cursor.
     *
     * @param array<string,mixed> $state Composite multilingual discovery state.
     * @return string
     */
    private static function encode_cron_warm_multilingual_cursor(array $state)
    {
        $encoded = wp_json_encode($state);
        if (!$encoded) {
            return '';
        }

        return 'ucml2:' . base64_encode($encoded);
    }

    /**
     * Decode one composite multilingual discovery cursor.
     *
     * Legacy/single-language cursors intentionally restart discovery from the
     * canonical queue boundary. Queue membership deduplication prevents duplicate
     * execution while allowing an in-progress plan to adopt fair language slices.
     *
     * @param string            $cursor    Opaque plan cursor.
     * @param array<int,string> $languages Current eligible languages.
     * @return array<string,mixed>
     */
    private static function decode_cron_warm_multilingual_cursor($cursor, array $languages)
    {
        $languages = array_values(array_unique(array_filter(array_map('strval', $languages))));
        $default = array(
            'version'   => 2,
            'languages' => $languages,
            'cursors'   => array(),
            'done'      => array(),
            'totals'    => array(),
            'processed' => array(),
            'nextIndex' => 0,
        );
        foreach ($languages as $language_code) {
            $default['cursors'][$language_code] = '';
            $default['done'][$language_code] = false;
            $default['totals'][$language_code] = 0;
            $default['processed'][$language_code] = 0;
        }

        $cursor = trim((string) $cursor);
        if (0 !== strpos($cursor, 'ucml2:')) {
            return $default;
        }

        $decoded = base64_decode(substr($cursor, 6), true);
        $decoded = false !== $decoded ? json_decode($decoded, true) : null;
        if (!is_array($decoded) || 2 !== (int) ($decoded['version'] ?? 0)) {
            return $default;
        }

        $stored_languages = array_values(array_unique(array_filter(array_map('strval', (array) ($decoded['languages'] ?? array())))));
        if (empty($stored_languages)) {
            return $default;
        }

        // Keep the language set committed by the first discovery batch. If a
        // language becomes ineligible mid-plan, its requested-language crawler
        // will fail closed and that lane will be marked complete.
        $state = array(
            'version'   => 2,
            'languages' => $stored_languages,
            'cursors'   => array(),
            'done'      => array(),
            'totals'    => array(),
            'processed' => array(),
            'nextIndex' => max(0, (int) ($decoded['nextIndex'] ?? 0)),
        );
        foreach ($stored_languages as $language_code) {
            $state['cursors'][$language_code] = trim((string) (($decoded['cursors'] ?? array())[$language_code] ?? ''));
            $state['done'][$language_code] = !empty(($decoded['done'] ?? array())[$language_code]);
            $state['totals'][$language_code] = max(0, (int) (($decoded['totals'] ?? array())[$language_code] ?? 0));
            $state['processed'][$language_code] = max(0, (int) (($decoded['processed'] ?? array())[$language_code] ?? 0));
        }
        if (!empty($stored_languages)) {
            $state['nextIndex'] %= count($stored_languages);
        }

        return $state;
    }

    /**
     * Discover one fair background batch across all eligible multilingual lanes.
     *
     * Each language owns its own native crawler cursor and per-language 5k cap.
     * The global Scheduled/Cron selection limit is applied later by the warm plan,
     * so a small limit can no longer be consumed entirely by the default language.
     *
     * @param object $engine    Warm engine.
     * @param string $cursor    Opaque plan cursor.
     * @param int    $limit     Maximum URLs requested for this batch.
     * @param string $operation Multilingual warm-policy operation.
     * @return array<string,mixed>
     */
    private static function get_cron_warm_multilingual_discovery_batch($engine, $cursor, $limit, $operation)
    {
        $limit = max(1, min(500, (int) $limit));
        $operation = sanitize_key((string) $operation);
        $languages = function_exists('ultracache_multilingual_get_warm_languages')
            ? array_values(ultracache_multilingual_get_warm_languages($operation))
            : array();

        if (empty($languages)) {
            return $engine->get_crawl_urls_cursor_batch((string) $cursor, $limit, 'full', $operation);
        }

        if (1 === count($languages)) {
            return $engine->get_crawl_urls_cursor_batch(
                (string) $cursor,
                $limit,
                'full',
                $operation,
                (string) $languages[0]
            );
        }

        $state = self::decode_cron_warm_multilingual_cursor($cursor, $languages);
        $languages = array_values((array) $state['languages']);
        $language_count = count($languages);
        if ($language_count < 1) {
            return array(
                'items' => array(),
                'total' => 0,
                'offset' => 0,
                'limit' => $limit,
                'cursor' => (string) $cursor,
                'nextCursor' => '',
                'nextOffset' => 0,
                'processed' => 0,
                'hasMore' => false,
            );
        }

        $start_index = max(0, (int) $state['nextIndex']) % $language_count;
        $ordered = array();
        for ($i = 0; $i < $language_count; $i++) {
            $ordered[] = $languages[($start_index + $i) % $language_count];
        }

        $items = array();
        $seen = array();
        $eligible_this_round = array_values(array_filter(
            $ordered,
            static function ($language_code) use ($state) {
                return empty($state['done'][$language_code]);
            }
        ));

        foreach ($eligible_this_round as $position => $language_code) {
            $remaining = $limit - count($items);
            if ($remaining < 1) {
                break;
            }

            $slots_left = max(1, count($eligible_this_round) - $position);
            $quota = max(1, (int) ceil($remaining / $slots_left));
            $language_batch = $engine->get_crawl_urls_cursor_batch(
                (string) ($state['cursors'][$language_code] ?? ''),
                min(500, $quota),
                'full',
                $operation,
                $language_code
            );

            foreach ((array) ($language_batch['items'] ?? array()) as $url) {
                $url = trim((string) $url);
                if ('' === $url || isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $items[] = $url;
                if (count($items) >= $limit) {
                    break;
                }
            }

            $state['cursors'][$language_code] = !empty($language_batch['nextCursor'])
                ? (string) $language_batch['nextCursor']
                : '';
            $state['done'][$language_code] = empty($language_batch['hasMore']);
            $state['totals'][$language_code] = max(
                (int) ($state['totals'][$language_code] ?? 0),
                max(0, (int) ($language_batch['total'] ?? 0))
            );
            $state['processed'][$language_code] = max(
                (int) ($state['processed'][$language_code] ?? 0),
                max(0, (int) ($language_batch['processed'] ?? 0))
            );
        }

        $state['nextIndex'] = ($start_index + 1) % $language_count;
        $has_more = false;
        foreach ($languages as $language_code) {
            if (empty($state['done'][$language_code])) {
                $has_more = true;
                break;
            }
        }

        $total = array_sum(array_map('intval', (array) $state['totals']));
        $processed = array_sum(array_map('intval', (array) $state['processed']));

        return array(
            'items'      => array_values($items),
            'total'      => max($total, $processed),
            'offset'     => max(0, $processed - count($items)),
            'limit'      => $limit,
            'cursor'     => (string) $cursor,
            'nextCursor' => $has_more ? self::encode_cron_warm_multilingual_cursor($state) : '',
            'nextOffset' => $processed,
            'processed'  => $processed,
            'hasMore'    => $has_more,
        );
    }

    private static function prepare_next_cron_warm_full_site_batch(array &$state, $engine, $pages_per_minute, $total_limit, $now, array $args = array(), $configured_pages_per_minute = null)
    {
        $plan_record = self::get_warm_plan_record();
        $plan = isset($plan_record['payload']) && is_array($plan_record['payload'])
            ? $plan_record['payload']
            : self::get_default_warm_plan_state();
        if (
            !self::is_warm_plan_active($plan)
            || !empty($plan['discoveryComplete'])
            || self::count_cron_warm_unprocessed_full_site_queue_rows() > 0
        ) {
            return 0;
        }

        if (!empty($plan['batchHasMore']) && !empty($plan['nextCursorPending'])) {
            $advanced = self::advance_warm_plan_cursor($plan_record);
            if (empty($advanced['success'])) {
                $state = self::get_cron_warm_state();
                return 0;
            }
            $plan_record = self::get_warm_plan_record();
            $plan = isset($plan_record['payload']) && is_array($plan_record['payload'])
                ? $plan_record['payload']
                : self::get_default_warm_plan_state();
        }

        $planned = self::count_cron_warm_full_site_members();
        $total_limit = max(0, (int) ($plan['selectionLimit'] ?? $total_limit));
        $remaining_budget = $total_limit > 0 ? max(0, $total_limit - $planned) : $pages_per_minute;
        if ($total_limit > 0 && $remaining_budget < 1) {
            self::mark_warm_plan_discovery_complete(
                (string) $plan['planId'],
                (int) $plan['planGeneration'],
                self::maybe_translate('Full-site warm plan reached the Scheduled / Cron warm limit.')
            );
            $state = self::get_cron_warm_state();
            return 0;
        }

        $batch_limit = $total_limit > 0
            ? min($pages_per_minute, $remaining_budget)
            : $pages_per_minute;
        $batch_limit = max(1, $batch_limit);
        $multilingual_operation = self::get_cron_warm_multilingual_operation((string) ($plan['reason'] ?? ''));
        $batch = self::get_cron_warm_multilingual_discovery_batch(
            $engine,
            (string) ($plan['cursor'] ?? ''),
            $batch_limit,
            $multilingual_operation
        );
        $items = isset($batch['items']) && is_array($batch['items']) ? array_values($batch['items']) : array();
        $accepted_urls = array();
        $enqueue_summary = array();
        $site_required_stages = self::get_cron_warm_site_required_stages();
        $inserted = self::insert_cron_warm_queue_urls(
            $items,
            $planned,
            'page_warm',
            '',
            false,
            $accepted_urls,
            $enqueue_summary,
            $site_required_stages
        );

        $selected_after = self::count_cron_warm_full_site_members();
        $discovery_complete = empty($batch['hasMore'])
            || ($total_limit > 0 && $selected_after >= $total_limit);
        $message = $inserted < 1
            ? self::maybe_translate('No new unique full-site URLs were found in this discovery batch.')
            : self::maybe_translate('Full-site discovery added URLs alongside existing targeted automation.');
        $commit = self::commit_warm_plan_discovery_batch(
            $plan_record,
            $batch,
            $accepted_urls,
            $discovery_complete,
            !empty($args['invokedBy']) ? sanitize_key((string) $args['invokedBy']) : (string) ($plan['invokedBy'] ?? ''),
            $message
        );

        $state['pagesPerMinute'] = null === $configured_pages_per_minute
            ? $pages_per_minute
            : max(0, min(600, absint($configured_pages_per_minute)));
        $state['total'] = max((int) ($state['total'] ?? 0), (int) ($batch['total'] ?? 0));
        $state['lastRunAt'] = $now;
        $state['updatedAt'] = $now;
        $state['lastMessage'] = empty($commit['success'])
            ? self::maybe_translate('A newer full-site discovery revision was committed first; this batch will be reconciled from the canonical queue.')
            : $message;
        self::save_cron_warm_state($state);
        $state = self::get_cron_warm_state();

        return $inserted;
    }

    /**
     * Resolve which background work classes may run during this cron tick.
     *
     * Varnish invalidation is correctness work with its own operation budget.
     * Page warming, refill, and refresh-ahead remain controlled by the shared
     * background page rate.
     *
     * @param bool $manual_warm_active            Whether UI/WP-CLI owns execution.
     * @param bool $site_warm_active              Whether a page-warm plan is active.
     * @param bool $background_execution_enabled  Whether page work is enabled.
     * @param bool $pending_varnish_invalidations Whether invalidation work exists.
     * @return array<string,bool>
     */
    private static function get_cron_warm_tick_execution_policy(
        $manual_warm_active,
        $site_warm_active,
        $background_execution_enabled,
        $pending_varnish_invalidations,
        $pending_litespeed_invalidations = false
    ) {
        $manual_warm_active = (bool) $manual_warm_active;
        $site_warm_active = (bool) $site_warm_active;
        $background_execution_enabled = (bool) $background_execution_enabled;
        $pending_varnish_invalidations = (bool) $pending_varnish_invalidations;
        $pending_litespeed_invalidations = (bool) $pending_litespeed_invalidations;

        return array(
            'runAuxiliaryCacheWork' => !$manual_warm_active
                && !$site_warm_active
                && $background_execution_enabled,
            'runVarnishInvalidations' => !$manual_warm_active
                && $pending_varnish_invalidations,
            'runLiteSpeedInvalidations' => !$manual_warm_active
                && $pending_litespeed_invalidations,
        );
    }

    /**
     * Return a bounded kickoff delay for pending Varnish invalidation work.
     *
     * @param array $queue_run Latest invalidation worker result.
     * @return int|null
     */
    private static function get_varnish_invalidation_reschedule_delay(array $queue_run)
    {
        $now = time();
        $next_times = array();
        $rate_claim = isset($queue_run['rateClaim']) && is_array($queue_run['rateClaim'])
            ? $queue_run['rateClaim']
            : array();
        $queue = isset($queue_run['queue']) && is_array($queue_run['queue'])
            ? $queue_run['queue']
            : array();

        $rate_next_at = max(0, (int) ($rate_claim['nextAt'] ?? 0));
        if ($rate_next_at > $now) {
            $next_times[] = $rate_next_at;
        }
        $retry_next_at = max(0, (int) ($queue['nextAttemptAt'] ?? 0));
        if ($retry_next_at > $now) {
            $next_times[] = $retry_next_at;
        }
        if (empty($next_times)) {
            return null;
        }

        return max(1, min(300, min($next_times) - $now));
    }

    public static function run_cron_warm_tick(array $args = array())
    {
        self::ensure_cron_warm_queue_table();
        self::recover_expired_cron_warm_queue_leases();
        $state = self::get_cron_warm_state();
        $manual_warm_active = self::is_manual_warmup_blocking_cron();
        $site_warm_active = !empty($state['active']);
        $settings_snapshot = self::get_settings();
        $configured_background_limit = self::get_shared_automation_pages_per_minute($settings_snapshot);
        $requested_background_limit = isset($args['pagesPerMinute']) && null !== $args['pagesPerMinute']
            ? max(0, min(600, absint($args['pagesPerMinute'])))
            : null;
        $background_pages_per_minute = self::resolve_effective_warm_rate_limit(
            $configured_background_limit,
            $requested_background_limit
        );
        $background_execution_enabled = $background_pages_per_minute > 0;
        $pending_varnish_invalidations = method_exists(static::class, 'has_pending_varnish_invalidation_rows')
            && self::has_pending_varnish_invalidation_rows();
        $pending_litespeed_invalidations = method_exists(static::class, 'has_pending_litespeed_invalidation_rows')
            && self::has_pending_litespeed_invalidation_rows();
        $execution_policy = self::get_cron_warm_tick_execution_policy(
            $manual_warm_active,
            $site_warm_active,
            $background_execution_enabled,
            $pending_varnish_invalidations,
            $pending_litespeed_invalidations
        );
        $run_auxiliary_cache_work = !empty($execution_policy['runAuxiliaryCacheWork']);
        $run_varnish_invalidation_queue = !empty($execution_policy['runVarnishInvalidations']);
        $run_litespeed_invalidation_queue = !empty($execution_policy['runLiteSpeedInvalidations']);
        $varnish_queue_limit = 100;
        $litespeed_queue_limit = 100;
        $auxiliary_skip_reason = $manual_warm_active
            ? 'manual-warm-priority'
            : ($site_warm_active
                ? 'site-warm-priority'
                : ($background_execution_enabled ? 'unavailable' : 'background-rate-paused'));

        // Durable targeted invalidation has correctness priority over discovery
        // and page warming. It is independent from the configured background
        // pages-per-minute rate and can therefore drain while warming is paused.
        $litespeed_queue_run = $run_litespeed_invalidation_queue && method_exists(static::class, 'process_ready_litespeed_invalidation_rows')
            ? self::process_ready_litespeed_invalidation_rows($litespeed_queue_limit)
            : array(
                'processed' => 0,
                'reason' => $manual_warm_active
                    ? 'manual-warm-priority'
                    : 'no-pending-invalidation',
            );
        $state = self::get_cron_warm_state();
        if (!empty($state['active'])) {
            $run_auxiliary_cache_work = false;
            $auxiliary_skip_reason = 'site-warm-priority';
        }

        $varnish_refresh_ahead_run = $run_auxiliary_cache_work && method_exists(static::class, 'maybe_run_varnish_refresh_ahead')
            ? self::maybe_run_varnish_refresh_ahead()
            : array('ran' => false, 'reason' => $auxiliary_skip_reason);
        $varnish_queue_run = $run_varnish_invalidation_queue
            ? self::process_ready_varnish_queue_rows($varnish_queue_limit)
            : array(
                'processed' => 0,
                'reason' => $manual_warm_active
                    ? 'manual-warm-priority'
                    : 'no-pending-invalidation',
            );
        // A queued invalidation batch can activate the shared targeted warm
        // pipeline while the auxiliary worker is running. Reload both worker
        // and foreground state: UI/WP-CLI may have acquired ownership after the
        // initial tick check and must preempt every remaining background stage.
        $state = self::get_cron_warm_state();
        $manual_warm_active = self::is_manual_warmup_blocking_cron();
        if ($manual_warm_active) {
            self::yield_cron_warmup_to_foreground((string) (self::get_manual_warm_status()['source'] ?? 'ui'));
            return array(
                'success' => true,
                'message' => self::maybe_translate('Background automation skipped this tick because a foreground warm-up has priority.'),
                'warmedThisRun' => 0,
                'liteSpeedQueue' => $litespeed_queue_run,
                'varnishQueue' => $varnish_queue_run,
                'varnishRefreshAhead' => $varnish_refresh_ahead_run,
                'state' => self::get_cron_warm_status(),
            );
        }
        if (empty($state['active']) && self::is_warm_plan_active(self::get_warm_plan_state())) {
            self::resume_active_full_site_warm_plan();
            $state = self::get_cron_warm_state();
        }
        if (empty($state['active']) && method_exists(static::class, 'count_pending_targeted_page_warm_queue_rows') && self::count_pending_targeted_page_warm_queue_rows() > 0) {
            self::resume_deferred_targeted_page_warm_queue();
            $state = self::get_cron_warm_state();
        }
        if (
            method_exists(static::class, 'has_pending_litespeed_invalidation_rows')
            && self::has_pending_litespeed_invalidation_rows()
        ) {
            $litespeed_queue = isset($litespeed_queue_run['queue']) && is_array($litespeed_queue_run['queue'])
                ? $litespeed_queue_run['queue']
                : (method_exists(static::class, 'get_litespeed_invalidation_queue_stats')
                    ? self::get_litespeed_invalidation_queue_stats()
                    : array());
            $litespeed_delay = 1;
            if (!empty($litespeed_queue['nextAttemptAt']) && (int) $litespeed_queue['nextAttemptAt'] > time()) {
                $litespeed_delay = max(1, min(300, (int) $litespeed_queue['nextAttemptAt'] - time()));
            }
            self::ensure_cron_warm_events_scheduled($litespeed_delay, true);
            $queue_reason = sanitize_key((string) ($litespeed_queue_run['reason'] ?? ''));
            $message = in_array($queue_reason, array('foreground-warm-priority', 'manual-warm-priority'), true)
                ? self::maybe_translate('Queued LiteSpeed invalidation is yielding to an active foreground warm-up.')
                : self::maybe_translate('Persistent LiteSpeed invalidation is waiting for its next operation or retry window.');
            if (!empty($state['active'])) {
                $message .= ' ' . self::maybe_translate('Page warming will continue after LiteSpeed invalidation finishes.');
            }
            return array(
                'success' => true,
                'message' => $message,
                'warmedThisRun' => 0,
                'liteSpeedQueue' => $litespeed_queue_run,
                'varnishQueue' => $varnish_queue_run,
                'varnishRefreshAhead' => $varnish_refresh_ahead_run,
                'state' => self::get_cron_warm_status(),
            );
        }

        if (
            method_exists(static::class, 'has_pending_varnish_invalidation_rows')
            && self::has_pending_varnish_invalidation_rows()
        ) {
            $varnish_delay = self::get_varnish_invalidation_reschedule_delay($varnish_queue_run);
            self::ensure_cron_warm_events_scheduled($varnish_delay, true);
            $queue_reason = sanitize_key((string) ($varnish_queue_run['reason'] ?? ''));
            if ('minute-budget-exhausted' === $queue_reason) {
                $message = self::maybe_translate('The Varnish invalidation operation budget is exhausted for this minute; invalidation will continue in the next minute.');
            } elseif ('foreground-warm-priority' === $queue_reason || 'manual-warm-priority' === $queue_reason) {
                $message = self::maybe_translate('Queued Varnish invalidation is yielding to an active foreground warm-up.');
            } else {
                $message = self::maybe_translate('Persistent Varnish invalidation is waiting for its next operation or retry window.');
            }
            $page_work_waiting = !empty($state['active'])
                || self::count_cron_warm_pending_queue_rows() > 0
                || self::count_cron_warm_processing_queue_rows() > 0
                || self::is_warm_plan_active(self::get_warm_plan_state());
            if (!$background_execution_enabled && $page_work_waiting) {
                $state['active'] = false;
                $state['completed'] = false;
                $state['stopped'] = true;
                $state['stopReason'] = 'paused';
                $state['updatedAt'] = time();
                $state['finishedAt'] = time();
                $state['pagesPerMinute'] = 0;
                $state['lastMessage'] = self::maybe_translate('Page warming remains paused because Background warm pages per minute is 0.');
                self::save_cron_warm_state($state);
                $message .= ' ' . $state['lastMessage'];
            } elseif (!empty($state['active'])) {
                $message .= ' ' . self::maybe_translate('Page warming will continue after invalidation finishes.');
            }
            return array(
                'success' => true,
                'message' => $message,
                'warmedThisRun' => 0,
                'varnishQueue' => $varnish_queue_run,
                'varnishRefreshAhead' => $varnish_refresh_ahead_run,
                'state' => self::get_cron_warm_status(),
            );
        }

        if (!$background_execution_enabled) {
            self::sync_warm_rate_limit($configured_background_limit, time());
            $known_page_work = !empty($state['active'])
                || self::count_cron_warm_pending_queue_rows() > 0
                || self::count_cron_warm_processing_queue_rows() > 0
                || self::is_warm_plan_active(self::get_warm_plan_state());
            if ($known_page_work) {
                $state['active'] = false;
                $state['completed'] = false;
                $state['stopped'] = true;
                $state['stopReason'] = 'paused';
                $state['updatedAt'] = time();
                $state['finishedAt'] = time();
                $state['pagesPerMinute'] = 0;
                $state['lastMessage'] = self::maybe_translate('Page warming is queued and paused because Background warm pages per minute is 0.');
                self::save_cron_warm_state($state);
                self::unschedule_cron_warm_events();
                return array(
                    'success' => true,
                    'message' => $state['lastMessage'],
                    'warmedThisRun' => 0,
                    'varnishQueue' => $varnish_queue_run,
                    'varnishRefreshAhead' => $varnish_refresh_ahead_run,
                        'state' => self::get_cron_warm_status(),
                );
            }

            self::unschedule_cron_warm_events();
            return array(
                'success' => true,
                'message' => self::maybe_translate('No background page work is enabled or waiting.'),
                'warmedThisRun' => 0,
                'varnishQueue' => $varnish_queue_run,
                'varnishRefreshAhead' => $varnish_refresh_ahead_run,
                'state' => self::get_cron_warm_status(),
            );
        }

        if (empty($state['active'])) {
            $settings = self::get_settings();
            $pending_total = self::count_cron_warm_pending_queue_rows();
            if ($pending_total > 0) {
                if (self::get_shared_automation_pages_per_minute($settings) < 1) {
                    $state['active'] = false;
                    $state['completed'] = false;
                    $state['stopped'] = true;
                    $state['stopReason'] = 'paused';
                    $state['updatedAt'] = time();
                    $state['finishedAt'] = time();
                    $state['pagesPerMinute'] = 0;
                    $state['lastMessage'] = self::maybe_translate('Queued page automation is paused because Background warm pages per minute is 0.');
                    self::save_cron_warm_state($state);
                    self::unschedule_cron_warm_events();
                    return array(
                        'success' => true,
                        'message' => $state['lastMessage'],
                        'warmedThisRun' => 0,
                        'varnishQueue' => $varnish_queue_run,
                        'varnishRefreshAhead' => $varnish_refresh_ahead_run,
                                'state' => self::get_cron_warm_status(),
                    );
                }

                if (self::resume_orphaned_cron_warm_queue_state()) {
                    $state = self::get_cron_warm_state();
                } else {
                    self::ensure_cron_warm_events_scheduled(1, true);
                    return array(
                        'success' => true,
                        'message' => self::maybe_translate('Persistent page automation is waiting for its current owner or automatic recovery.'),
                        'warmedThisRun' => 0,
                        'varnishQueue' => $varnish_queue_run,
                        'varnishRefreshAhead' => $varnish_refresh_ahead_run,
                                'state' => self::get_cron_warm_status(),
                    );
                }
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
                self::release_cron_warm_full_site_membership();
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
        }

        $lock_ttl = 90;
        $now = time();
        $lock_token = wp_generate_password(20, false, false);
        $cron_execution_fence = array();
        if (!self::acquire_cron_warm_lock($lock_token, $lock_ttl, $cron_execution_fence)) {
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
                self::stop_warm_plan('engine_unavailable');
                self::release_cron_warm_full_site_membership();
                self::save_cron_warm_state($state);
                self::unschedule_cron_warm_events();
                return array('success' => false, 'message' => $state['lastError'], 'state' => self::get_cron_warm_status());
            }

            $state_reason = sanitize_key((string) ($state['reason'] ?? ''));
            $pages_per_minute = $background_pages_per_minute;
            $workload_type = self::normalize_cron_warm_workload_type(
                $state['workloadType'] ?? '',
                $state_reason
            );
            $total_limit = 'full_site' === $workload_type
                ? (isset($args['totalLimit']) && null !== $args['totalLimit']
                    ? max(0, min(5000, absint($args['totalLimit'])))
                    : max(0, (int) ($state['totalLimit'] ?? $settings['scheduled_warm_limit'])))
                : 0;
            $state['workloadType'] = $workload_type;
            if ('full_site' === $workload_type && empty($state['completed'])) {
                $full_site_counts = self::get_cron_warm_full_site_membership_counts();
                if (!empty($full_site_counts['ready'])) {
                    $state['fullSitePlanned'] = max(0, (int) ($full_site_counts['selected'] ?? 0));
                    $state['fullSiteProcessed'] = min(
                        $state['fullSitePlanned'],
                        max(0, (int) ($full_site_counts['processed'] ?? 0))
                    );
                    $state['fullSiteSuccessCount'] = max(0, (int) ($full_site_counts['success'] ?? 0));
                    $state['fullSiteSkippedCount'] = max(0, (int) ($full_site_counts['skipped'] ?? 0));
                    $state['fullSiteErrorCount'] = max(0, (int) ($full_site_counts['error'] ?? 0));
                }
            }

            if ($pages_per_minute < 1) {
                $state['active'] = false;
                $state['completed'] = false;
                $state['stopped'] = true;
                $state['stopReason'] = 'paused';
                $state['updatedAt'] = time();
                $state['finishedAt'] = time();
                $state['totalLimit'] = $total_limit;
                $state['batchIndex'] = 0;
                $state['lastMessage'] = 'Background page automation is paused because pages per minute is 0.';
                self::save_cron_warm_state($state);
                self::unschedule_cron_warm_events();
                return array('success' => true, 'message' => $state['lastMessage'], 'warmedThisRun' => 0, 'state' => self::get_cron_warm_status());
            }

            // Full-site discovery only produces canonical queue work; it does
            // not consume the URL execution allowance. Discover first, then
            // claim exactly the number of executable URL rows selected below.
            if ('full_site' === $workload_type) {
                self::prepare_next_cron_warm_full_site_batch(
                    $state,
                    $engine,
                    $pages_per_minute,
                    $total_limit,
                    $now,
                    $args,
                    $pages_per_minute
                );
            }

            $selection_meta = array();
            $pending_rows = self::load_cron_warm_pending_queue_rows(
                $pages_per_minute,
                'full_site' === $workload_type,
                (string) ($state['mixedWorkloadNextClass'] ?? 'full_site'),
                $selection_meta
            );
            $requested_page_slots = count($pending_rows);
            $page_rate_claim = self::claim_warm_rate_slots(
                $requested_page_slots,
                $configured_background_limit,
                $pages_per_minute,
                time(),
                'page_pipeline'
            );
            $page_work_budget = max(0, (int) ($page_rate_claim['granted'] ?? 0));

            if ($requested_page_slots > 0 && $page_work_budget < 1) {
                $claim_reason = sanitize_key((string) ($page_rate_claim['reason'] ?? ''));
                $next_at = max(time() + 1, (int) ($page_rate_claim['nextAt'] ?? (time() + MINUTE_IN_SECONDS)));
                $delay = max(1, $next_at - time());
                $state['active'] = true;
                $state['completed'] = false;
                $state['stopped'] = false;
                $state['stopReason'] = '';
                $state['updatedAt'] = time();
                $state['lastRunAt'] = time();
                $state['lastMessage'] = 'minute-budget-exhausted' === $claim_reason
                    ? self::maybe_translate('The shared background URL budget is exhausted for this minute; page automation will continue in the next minute.')
                    : self::maybe_translate('The atomic background URL budget could not be claimed; page automation will retry in the next minute.');
                self::save_cron_warm_state($state);
                self::ensure_cron_warm_events_scheduled($delay, true);
                return array(
                    'success' => 'minute-budget-exhausted' === $claim_reason,
                    'message' => $state['lastMessage'],
                    'warmedThisRun' => 0,
                    'varnishQueue' => $varnish_queue_run,
                    'varnishRefreshAhead' => $varnish_refresh_ahead_run,
                        'state' => self::get_cron_warm_status(),
                );
            }

            if ($page_work_budget > 0 && $page_work_budget < count($pending_rows)) {
                $selection_meta = array();
                $pending_rows = self::load_cron_warm_pending_queue_rows(
                    $page_work_budget,
                    'full_site' === $workload_type,
                    (string) ($state['mixedWorkloadNextClass'] ?? 'full_site'),
                    $selection_meta
                );
            }
            if (
                1 === $page_work_budget
                && !empty($selection_meta['mixed'])
                && in_array((string) ($selection_meta['selectedClass'] ?? ''), array('full_site', 'targeted'), true)
            ) {
                $next_class = 'full_site' === (string) $selection_meta['selectedClass']
                    ? 'targeted'
                    : 'full_site';
                $plan_snapshot = isset($state['warmPlan']) && is_array($state['warmPlan'])
                    ? $state['warmPlan']
                    : array();
                self::update_warm_plan_fairness_pointer(
                    (string) ($plan_snapshot['planId'] ?? ''),
                    (int) ($plan_snapshot['planGeneration'] ?? 0),
                    $next_class
                );
                $state['mixedWorkloadNextClass'] = $next_class;
            }
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
            if (empty($pending_rows) && in_array($state_reason, array('css_bundle_async', 'lcp_refresh_async', 'targeted_purge_async', 'queue_recovery'), true)) {
                $state['active'] = false;
                $state['completed'] = true;
                $state['stopped'] = false;
                $state['stopReason'] = '';
                $state['finishedAt'] = time();
                $state['updatedAt'] = time();
                if ('lcp_refresh_async' === $state_reason) {
                    $state['lastMessage'] = self::maybe_translate('Page-specific LCP refresh queue complete.');
                } elseif ('targeted_purge_async' === $state_reason) {
                    $state['lastMessage'] = self::maybe_translate('Targeted purge warm queue complete.');
                } elseif ('queue_recovery' === $state_reason) {
                    $state['lastMessage'] = self::maybe_translate('Recovered automation queue complete.');
                } else {
                    $state['lastMessage'] = self::maybe_translate('Async CSS bundle queue complete.');
                }
                self::clear_cron_warm_queue_table();
                self::save_cron_warm_state($state);
                self::unschedule_cron_warm_events();
                return array('success' => true, 'message' => $state['lastMessage'], 'warmedThisRun' => 0, 'state' => self::get_cron_warm_status());
            }

            if (empty($pending_rows)) {
                $full_site_plan = 'full_site' === $workload_type;
                $planned = max(0, (int) ($state['fullSitePlanned'] ?? 0));
                $remaining_budget = $full_site_plan && $total_limit > 0 ? max(0, $total_limit - $planned) : 0;
                $plan_snapshot = isset($state['warmPlan']) && is_array($state['warmPlan'])
                    ? $state['warmPlan']
                    : array();

                if ($full_site_plan && $total_limit > 0 && $remaining_budget < 1 && empty($state['fullSiteDiscoveryComplete'])) {
                    self::mark_warm_plan_discovery_complete(
                        (string) ($plan_snapshot['planId'] ?? ''),
                        (int) ($plan_snapshot['planGeneration'] ?? 0),
                        self::maybe_translate('Full-site warm plan reached the Scheduled / Cron warm limit.')
                    );
                    $state = self::get_cron_warm_state();
                    $plan_snapshot = isset($state['warmPlan']) && is_array($state['warmPlan'])
                        ? $state['warmPlan']
                        : $plan_snapshot;
                }

                if (
                    !$full_site_plan
                    || (
                        !empty($state['fullSiteDiscoveryComplete'])
                        && self::count_cron_warm_unprocessed_full_site_queue_rows() < 1
                    )
                ) {
                    if ($full_site_plan) {
                        self::complete_warm_plan(
                            (string) ($plan_snapshot['planId'] ?? ''),
                            (int) ($plan_snapshot['planGeneration'] ?? 0),
                            self::maybe_translate('Full-site background warm-up complete.')
                        );
                        self::release_cron_warm_full_site_membership();
                        self::clear_cron_warm_queue_table();
                    }
                    $state['active'] = false;
                    $state['completed'] = true;
                    $state['stopped'] = false;
                    $state['stopReason'] = '';
                    $state['finishedAt'] = time();
                    $state['pagesPerMinute'] = $pages_per_minute;
                    $state['lastMessage'] = $full_site_plan
                        ? self::maybe_translate('Full-site background warm-up complete.')
                        : self::maybe_translate('Background page automation complete.');
                    self::save_cron_warm_state($state);
                    self::unschedule_cron_warm_events();
                    return array('success' => true, 'message' => $state['lastMessage'], 'warmedThisRun' => 0, 'state' => self::get_cron_warm_status());
                }

                // Discovery is prepared before queue loading so an existing
                // targeted backlog cannot suppress the full-site plan. If the
                // current source batch contained only duplicates, advance on the
                // next bounded tick instead of rescanning the same cursor here.
                $state['active'] = true;
                $state['completed'] = false;
                $state['stopped'] = false;
                $state['stopReason'] = '';
                $state['updatedAt'] = time();
                $state['lastMessage'] = self::maybe_translate('Full-site discovery will continue on the next background tick.');
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

            $operation_budget = function_exists('ultracache_get_safe_operation_budget') ? ultracache_get_safe_operation_budget('cron_warm') : array();
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
                if (!self::is_warm_execution_fence_current($cron_execution_fence)) {
                    $foreground_status = self::get_manual_warm_status();
                    if (!empty($foreground_status['active'])) {
                        self::yield_cron_warmup_to_foreground((string) ($foreground_status['source'] ?? 'ui'));
                    }
                    return array(
                        'success' => true,
                        'message' => self::maybe_translate('Background automation lost its execution fence before claiming the next URL.'),
                        'warmedThisRun' => $warmed,
                        'state' => self::get_cron_warm_status(),
                    );
                }
                if (self::is_manual_warmup_blocking_cron()) {
                    self::yield_cron_warmup_to_foreground((string) (self::get_manual_warm_status()['source'] ?? 'ui'));
                    return array(
                        'success' => true,
                        'message' => self::maybe_translate('Background automation yielded to an active foreground warm-up.'),
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
                $row_source_contexts_csv = self::normalize_cron_warm_queue_csv(
                    trim((string) ($row['source_contexts'] ?? '') . ',' . $row_source_context, ',')
                );
                $row_source_contexts = '' === $row_source_contexts_csv ? array() : explode(',', $row_source_contexts_csv);
                $full_site_marker = self::get_cron_warm_full_site_context_marker();
                $full_site_completed_marker = self::get_cron_warm_full_site_completed_context_marker();
                $is_unprocessed_full_site_row = in_array($full_site_marker, $row_source_contexts, true)
                    && !in_array($full_site_completed_marker, $row_source_contexts, true);
                $is_full_site_row = $is_unprocessed_full_site_row
                    || in_array($full_site_completed_marker, $row_source_contexts, true);
                $required_stages = self::get_cron_warm_queue_row_required_stages($row);
                $completed_stages_csv = self::normalize_cron_warm_queue_csv(
                    (string) ($row['completed_stages'] ?? ''),
                    array('html', 'css_bundle', 'lcp_refresh', 'varnish', 'litespeed')
                );
                $completed_stage_list = '' === $completed_stages_csv ? array() : explode(',', $completed_stages_csv);
                $has_css_stage = in_array('css_bundle', $required_stages, true) && !in_array('css_bundle', $completed_stage_list, true);
                $has_lcp_stage = in_array('lcp_refresh', $required_stages, true) && !in_array('lcp_refresh', $completed_stage_list, true);
                $has_varnish_stage = in_array('varnish', $required_stages, true) && !in_array('varnish', $completed_stage_list, true);
                $has_litespeed_stage = in_array('litespeed', $required_stages, true) && !in_array('litespeed', $completed_stage_list, true);
                $is_targeted_warm = false;
                $is_affected_rebuild = false;
                foreach ($row_source_contexts as $context_item) {
                    if (0 === strpos($context_item, 'affected-')) {
                        $is_affected_rebuild = true;
                        $is_targeted_warm = true;
                    } elseif (!in_array($context_item, array('lcp-refresh', 'css-bundle', $full_site_marker, $full_site_completed_marker), true)) {
                        $is_targeted_warm = true;
                    }
                }
                $is_varnish_refresh_ahead = in_array('refresh-ahead', $row_source_contexts, true);
                if ($is_affected_rebuild) {
                    $warm_context = 'affected-save';
                } elseif (in_array('litespeed-queued-invalidation', $row_source_contexts, true)) {
                    $warm_context = 'queued-invalidation';
                } elseif ($is_targeted_warm) {
                    $warm_context = $is_varnish_refresh_ahead ? 'refresh-ahead' : 'targeted-purge';
                } elseif ($has_lcp_stage) {
                    $warm_context = 'lcp-refresh';
                } elseif ($has_css_stage) {
                    $warm_context = 'css-bundle';
                } else {
                    $warm_context = in_array($state_reason, array('manual_purge', 'scheduled_cleanup'), true)
                        ? ('scheduled_cleanup' === $state_reason ? 'scheduled-cleanup' : 'warm-after-flush')
                        : 'cron';
                }
                $include_varnish = !in_array('varnish', $completed_stage_list, true)
                    && (
                        $has_varnish_stage
                        || ($is_full_site_row
                            && !$has_lcp_stage
                            && method_exists(static::class, 'should_include_varnish_in_site_warmup')
                            && self::should_include_varnish_in_site_warmup())
                    );
                if (in_array('litespeed', $completed_stage_list, true)) {
                    $include_litespeed = false;
                } elseif ($has_litespeed_stage) {
                    $include_litespeed = true;
                } else {
                    $include_litespeed = $is_full_site_row
                        && !$has_lcp_stage
                        && method_exists(static::class, 'should_include_litespeed_in_site_warmup')
                        && self::should_include_litespeed_in_site_warmup();
                }
                $queue_lease_renewed_at = time();
                $queue_lease_renew_interval = max(15, min(60, (int) floor(self::get_cron_warm_queue_lease_seconds() / 3)));
                $cron_generation = max(0, (int) ($cron_execution_fence['generation'] ?? 0));
                $warm_args = array(
                    'ignore_runtime_bypass' => true,
                    'include_varnish' => $include_varnish,
                    'include_litespeed' => $include_litespeed,
                    'warm_context' => $warm_context,
                    'required_stages' => $required_stages,
                    'completed_stages' => $completed_stage_list,
                    '_queue_lease_heartbeat' => static function ($stage = '') use ($row, $url, $lock_token, $lock_ttl, $cron_generation, &$queue_lease_renewed_at, $queue_lease_renew_interval) {
                        if (!self::renew_cron_warm_lock($lock_token, $lock_ttl, $cron_generation, $stage, $url)) {
                            return false;
                        }
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
                if ($has_css_stage) {
                    $warm_args['build_css_bundle'] = true;
                }
                if ($has_lcp_stage) {
                    $warm_args['force_refresh'] = true;
                    if (!$has_css_stage) {
                        $warm_args['skip_css_bundle'] = true;
                    }
                }
                if ($is_affected_rebuild) {
                    $warm_args['force_refresh'] = true;
                    $warm_args['build_css_bundle'] = $has_css_stage || !empty(self::get_settings()['homepage_css_bundle']);
                    $warm_args['requires_verified_origin'] = !empty($row['requires_verified_origin']);
                } elseif ($is_targeted_warm) {
                    $warm_args['force_refresh'] = true;
                    if (!$has_css_stage) {
                        $warm_args['skip_css_bundle'] = true;
                    }
                    $warm_args['requires_verified_origin'] = !empty($row['requires_verified_origin']);
                }

                $refresh_ahead_preparation = null;
                if ($is_varnish_refresh_ahead && method_exists(static::class, 'prepare_varnish_refresh_ahead_page_warm')) {
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

                if (!self::is_warm_execution_fence_current($cron_execution_fence)) {
                    self::release_cron_warm_queue_claim(
                        $row,
                        self::maybe_translate('The queue worker was preempted before its page result could be committed.'),
                        1
                    );
                    $foreground_status = self::get_manual_warm_status();
                    if (!empty($foreground_status['active'])) {
                        self::yield_cron_warmup_to_foreground((string) ($foreground_status['source'] ?? 'ui'));
                    }
                    return array(
                        'success' => true,
                        'message' => self::maybe_translate('Background automation yielded before committing a stale page result.'),
                        'warmedThisRun' => $warmed,
                        'state' => self::get_cron_warm_status(),
                    );
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
                                $redirect_enqueue_summary,
                                $required_stages
                            );
                            if ($redirect_accepted > 0 && !empty($redirect_urls)) {
                                $redirect_inserted = max(0, (int) ($redirect_enqueue_summary['inserted'] ?? 0));
                                if ($redirect_inserted > 0) {
                                    $state['total'] = max(0, (int) ($state['total'] ?? 0)) + $redirect_inserted;
                                    // Canonical redirects replace a selected URL; they do not expand
                                    // the Scheduled / Cron warm limit.
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
                $failed_stage_name = sanitize_key((string) ($result['failedStage'] ?? ''));
                $failed_stage_class = sanitize_key((string) ($result['failureClass'] ?? ''));
                $failed_stage_message = sanitize_text_field((string) ($result['failureMessage'] ?? ''));
                if ('' !== $failed_stage_name) {
                    $result_message .= ' Stage: ' . $failed_stage_name;
                    if ('' !== $failed_stage_class) {
                        $result_message .= ' [' . $failed_stage_class . ']';
                    }
                    if ('' !== $failed_stage_message) {
                        $result_message .= ': ' . $failed_stage_message;
                    }
                    $result_message .= '.';
                }
                $failure_details = is_array($result['failureDetails'] ?? null) ? $result['failureDetails'] : array();
                $refill_details = is_array($failure_details['refillDetails'] ?? null) ? $failure_details['refillDetails'] : array();
                foreach ($refill_details as $refill_detail) {
                    if (!is_array($refill_detail) || !empty($refill_detail['success'])) {
                        continue;
                    }
                    $bucket = sanitize_key((string) ($refill_detail['bucket'] ?? ''));
                    $detail = sanitize_text_field((string) ($refill_detail['detail'] ?? ''));
                    $http_code = max(0, (int) ($refill_detail['httpCode'] ?? 0));
                    $result_message .= ' Refill ' . ('' !== $bucket ? $bucket : 'variant') . ' failed';
                    if ($http_code > 0) {
                        $result_message .= ' with HTTP ' . $http_code;
                    }
                    if ('' !== $detail) {
                        $result_message .= ': ' . $detail;
                    }
                    $result_message .= '.';
                    break;
                }

                $pause_reason = sanitize_key((string) ($result['pauseReason'] ?? ''));
                if (!empty($result['deferred']) && in_array($pause_reason, array('time_budget', 'memory_budget'), true)) {
                    $state['lastRunAt'] = time();
                    $state['updatedAt'] = time();
                    $state['lastError'] = '';
                    $state['lastUrl'] = $last_url;
                    $state['lastMessage'] = self::maybe_translate_sprintf(
                        'Warm pipeline yielded to the %s and will continue on the next tick.',
                        str_replace('_', ' ', $pause_reason)
                    );
                    self::release_cron_warm_queue_claim($row, $state['lastMessage'], 15);
                    self::save_cron_warm_state($state);
                    self::ensure_cron_warm_events_scheduled(1);
                    break;
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

                $completed_stages = self::normalize_cron_warm_queue_csv(
                    trim($completed_stages_csv . ',' . self::get_completed_cron_warm_queue_stages($required_stages, is_array($result) ? $result : array()), ','),
                    array('html', 'css_bundle', 'lcp_refresh', 'varnish', 'litespeed')
                );
                $completed_stage_result_list = '' === $completed_stages ? array() : explode(',', $completed_stages);
                $failed_stages = self::get_failed_cron_warm_queue_stages(
                    $required_stages,
                    is_array($result) ? $result : array(),
                    $completed_stage_result_list
                );
                $failure_class = sanitize_key((string) ($result['failureClass'] ?? ''));
                if ('' === $failure_class && !empty($result['pipeline']['stages']) && is_array($result['pipeline']['stages'])) {
                    foreach ($result['pipeline']['stages'] as $stage_result) {
                        if ('failed' !== sanitize_key((string) ($stage_result['status'] ?? ''))) {
                            continue;
                        }
                        $failure_class = sanitize_key((string) ($stage_result['details']['failureClass'] ?? ''));
                        if ('' !== $failure_class) {
                            break;
                        }
                    }
                }
                if (!self::is_warm_execution_fence_current($cron_execution_fence)) {
                    self::release_cron_warm_queue_claim(
                        $row,
                        self::maybe_translate('The queue worker lost ownership immediately before its stage result commit.'),
                        1
                    );
                    $foreground_status = self::get_manual_warm_status();
                    if (!empty($foreground_status['active'])) {
                        self::yield_cron_warmup_to_foreground((string) ($foreground_status['source'] ?? 'ui'));
                    }
                    return array(
                        'success' => true,
                        'message' => self::maybe_translate('Background automation discarded a stale stage result before commit.'),
                        'warmedThisRun' => $warmed,
                        'state' => self::get_cron_warm_status(),
                    );
                }

                $terminal = true;
                $result_was_success = !empty($result['success']);
                $result_was_skipped = !$result_was_success && !empty($result['skipped']);
                if ($result_was_success) {
                    $attempt_result = self::mark_cron_warm_queue_row_processed($row, 'done', $result_message, false, !empty($result['warning']) ? 'warning' : 'success', $completed_stages, $failed_stages, $failure_class);
                } elseif ($result_was_skipped) {
                    $attempt_result = self::mark_cron_warm_queue_row_processed($row, 'skipped', $result_message, false, '', $completed_stages, $failed_stages, $failure_class);
                } else {
                    $last_error = $result_message;
                    $attempt_result = self::mark_cron_warm_queue_row_processed($row, 'error', $last_error, !empty($result['retryable']), '', $completed_stages, $failed_stages, $failure_class);
                }

                if (!self::renew_cron_warm_lock($lock_token, $lock_ttl, $cron_generation, 'queue-row-result-committed', $url)) {
                    $foreground_status = self::get_manual_warm_status();
                    if (!empty($foreground_status['active'])) {
                        self::yield_cron_warmup_to_foreground((string) ($foreground_status['source'] ?? 'ui'));
                    }
                    return array(
                        'success' => true,
                        'message' => self::maybe_translate('Background automation stopped before reporting progress for a worker that no longer owns execution.'),
                        'warmedThisRun' => $warmed,
                        'state' => self::get_cron_warm_status(),
                    );
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
                    if ($is_unprocessed_full_site_row) {
                        $state['fullSiteSuccessCount'] = (int) ($state['fullSiteSuccessCount'] ?? 0) + 1;
                    }
                    if ($has_lcp_stage) {
                        update_option('ultracache_lcp_last_refresh', array(
                            'url'       => esc_url_raw($url),
                            'timestamp' => time(),
                            'message'   => sanitize_text_field($result_message),
                        ), false);
                    }
                } elseif ($result_was_skipped) {
                    $state['skippedCount'] = (int) $state['skippedCount'] + 1;
                    if ($is_unprocessed_full_site_row) {
                        $state['fullSiteSkippedCount'] = (int) ($state['fullSiteSkippedCount'] ?? 0) + 1;
                    }
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
                    if ($is_unprocessed_full_site_row) {
                        $state['fullSiteErrorCount'] = (int) ($state['fullSiteErrorCount'] ?? 0) + 1;
                    }
                }

                if ($terminal) {
                    $handled_this_run++;
                    $state['processed'] = max(0, (int) $state['processed']) + 1;
                    if ($is_unprocessed_full_site_row) {
                        self::mark_cron_warm_full_site_member_processed((int) ($row['id'] ?? 0));
                        $state['fullSiteProcessed'] = min(
                            max(0, (int) ($state['fullSitePlanned'] ?? 0)),
                            max(0, (int) ($state['fullSiteProcessed'] ?? 0)) + 1
                        );
                    }
                }
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

            }

            $completed = false;
            $pending_after = self::count_cron_warm_pending_queue_rows();
            $processing_after = self::count_cron_warm_processing_queue_rows();
            if (
                'full_site' === (string) ($state['workloadType'] ?? '')
                && !empty($state['fullSiteDiscoveryComplete'])
                && self::count_cron_warm_unprocessed_full_site_queue_rows() < 1
            ) {
                $plan_snapshot = isset($state['warmPlan']) && is_array($state['warmPlan'])
                    ? $state['warmPlan']
                    : array();
                self::complete_warm_plan(
                    (string) ($plan_snapshot['planId'] ?? ''),
                    (int) ($plan_snapshot['planGeneration'] ?? 0),
                    self::maybe_translate('Full-site background warm-up complete.')
                );
                self::release_cron_warm_full_site_membership();
                $pending_after = self::count_cron_warm_pending_queue_rows();
                $processing_after = self::count_cron_warm_processing_queue_rows();
                if ($pending_after > 0 || $processing_after > 0) {
                    $state['active'] = true;
                    $state['completed'] = false;
                    $state['stopped'] = false;
                    $state['stopReason'] = '';
                    $state['reason'] = 'queue_recovery';
                    $state['workloadType'] = 'targeted';
                    $state['processed'] = 0;
                    $state['total'] = $pending_after + $processing_after;
                    $state['successCount'] = 0;
                    $state['skippedCount'] = 0;
                    $state['errorCount'] = 0;
                    $state['finishedAt'] = 0;
                    $state['updatedAt'] = time();
                    $state['lastMessage'] = self::maybe_translate('Full-site background warm-up complete. Remaining targeted automation continues.');
                    self::save_cron_warm_state($state);
                    self::ensure_cron_warm_events_scheduled();
                    return array(
                        'success' => true,
                        'message' => $state['lastMessage'],
                        'warmedThisRun' => $warmed,
                        'errorsThisRun' => $errors,
                        'varnishQueue' => $varnish_queue_run,
                        'varnishRefreshAhead' => $varnish_refresh_ahead_run,
                                'state' => self::get_cron_warm_status(),
                    );
                }
            }
            if ($pending_after < 1 && $processing_after < 1) {
                if (
                    empty($state['fullSiteDiscoveryComplete'])
                    && !empty($state['batchHasMore'])
                    && !empty($state['nextCursorPending'])
                ) {
                    // Retain completed membership until final plan completion.
                    // Cursor advancement is an exact CAS against the committed
                    // plan revision, so a stale worker cannot move it backward.
                    self::advance_warm_plan_cursor(self::get_warm_plan_record());
                    $state = self::get_cron_warm_state();
                    $state['active'] = true;
                    $state['completed'] = false;
                    $state['stopped'] = false;
                    $state['stopReason'] = '';
                    $state['updatedAt'] = time();
                    $remaining_after = max(0, (int) ($state['fullSitePlanned'] ?? 0) - (int) ($state['fullSiteProcessed'] ?? 0));
                    $state['lastMessage'] = $handled_this_run > 0
                        ? sprintf('Warmed %d URL(s) this tick. %d remaining.', $warmed, $remaining_after)
                        : self::maybe_translate('Advanced full-site discovery to the next source batch.');
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
                self::release_cron_warm_full_site_membership();
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
                    $state['lastMessage'] = $warmed > 0 || $state['processed'] > 0
                        ? ('full_site' === (string) ($state['workloadType'] ?? '') ? 'Full-site background warm-up complete.' : 'Background page automation complete.')
                        : ('full_site' === (string) ($state['workloadType'] ?? '') ? 'Full-site background warm-up completed with no eligible URLs.' : 'Background page automation completed with no eligible URLs.');
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
