<?php
/**
 * Durable LiteSpeed exact/stale invalidation queue helpers.
 *
 * Targeted invalidation is persisted in the shared UltraCache queue table so
 * content-save requests do not block on signed LiteSpeed loopbacks. The queue
 * owns transport retries; successful invalidations may then join the shared
 * page-warm pipeline for refill.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_LiteSpeed_Queue_Trait
{
    private static function get_litespeed_invalidation_queue_job_type()
    {
        return 'litespeed_invalidate';
    }

    private static function get_litespeed_invalidation_queue_max_attempts()
    {
        return 5;
    }

    private static function get_litespeed_invalidation_queue_retry_delay($attempt_number)
    {
        $attempt_number = max(1, (int) $attempt_number);
        $delays = array(1 => 30, 2 => 120, 3 => 300, 4 => 600, 5 => 900);
        return isset($delays[$attempt_number]) ? $delays[$attempt_number] : 900;
    }

    private static function get_litespeed_invalidation_queue_mode($stale, $refill)
    {
        if ($stale) {
            return $refill ? 'ls-stale-refill' : 'ls-stale';
        }
        return $refill ? 'ls-hard-refill' : 'ls-hard';
    }

    private static function litespeed_invalidation_queue_mode_is_stale($mode)
    {
        return 0 === strpos(sanitize_key((string) $mode), 'ls-stale');
    }

    private static function litespeed_invalidation_queue_mode_requires_refill($mode)
    {
        return false !== strpos(sanitize_key((string) $mode), '-refill');
    }

    private static function merge_litespeed_invalidation_queue_modes($current, $incoming)
    {
        $current = sanitize_key((string) $current);
        $incoming = sanitize_key((string) $incoming);
        $hard = in_array($current, array('ls-hard', 'ls-hard-refill'), true)
            || in_array($incoming, array('ls-hard', 'ls-hard-refill'), true);
        $refill = self::litespeed_invalidation_queue_mode_requires_refill($current)
            || self::litespeed_invalidation_queue_mode_requires_refill($incoming);

        return self::get_litespeed_invalidation_queue_mode(!$hard, $refill);
    }

    private static function normalize_litespeed_invalidation_queue_context($context)
    {
        $context = substr(sanitize_key((string) $context), 0, 32);
        return '' !== $context ? $context : 'exact-invalidation';
    }

    private static function get_litespeed_tag_queue_target($tag)
    {
        $tag = is_scalar($tag) ? trim((string) $tag) : '';
        return '' !== $tag ? 'tag:' . $tag : '';
    }

    private static function litespeed_queue_target_is_tag($target)
    {
        return 0 === strpos((string) $target, 'tag:');
    }

    private static function get_litespeed_tag_from_queue_target($target)
    {
        if (!self::litespeed_queue_target_is_tag($target)) {
            return '';
        }
        $tag = substr((string) $target, 4);
        $prepared = method_exists(static::class, 'prepare_litespeed_purge_tags')
            ? self::prepare_litespeed_purge_tags(array($tag), 1)
            : array();
        return !empty($prepared[0]) ? (string) $prepared[0] : '';
    }

    /**
     * Persist exact/stale LiteSpeed invalidation work.
     *
     * One row exists per normalized URL. Repeated requests coalesce atomically:
     * hard purge dominates stale purge and refill intent is sticky. If new work
     * arrives while a row is processing, rerun_requested preserves it for a
     * second pass after the current claim commits.
     *
     * @param array  $urls    URL candidates.
     * @param bool   $stale   Whether stale invalidation is requested.
     * @param string $context Invalidation source.
     * @param bool   $refill  Whether successful invalidation should queue refill.
     * @return array<string,mixed>
     */
    private static function enqueue_litespeed_invalidation_urls(array $urls, $stale, $context, $refill)
    {
        global $wpdb;

        $partition = self::partition_litespeed_purge_urls($urls);
        $eligible_urls = array_values((array) ($partition['urls'] ?? array()));
        $result = array_merge($partition, array(
            'success' => true,
            'queued' => false,
            'queuedUrlCount' => 0,
            'insertedUrlCount' => 0,
            'coalescedUrlCount' => 0,
            'upgradedUrlCount' => 0,
            'failedUrlCount' => 0,
            'failedUrls' => array(),
            'operation' => $stale ? 'stale-urls' : 'urls',
            'stale' => (bool) $stale,
            'refillRequested' => (bool) $refill,
        ));

        if (empty($eligible_urls)) {
            $result['message'] = !empty($partition['skippedQueryUrlCount'])
                ? self::maybe_translate('LiteSpeed query-string requests are bypassed, so no exact invalidation work was queued.')
                : self::maybe_translate('No eligible LiteSpeed URLs required queued invalidation.');
            return $result;
        }

        if (!($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            $result['success'] = false;
            $result['failedUrlCount'] = count($eligible_urls);
            $result['failedUrls'] = $eligible_urls;
            $result['message'] = self::maybe_translate('Persistent LiteSpeed invalidation storage is unavailable.');
            return $result;
        }

        $table = self::get_cron_warm_queue_table_name();
        $job_type = self::get_litespeed_invalidation_queue_job_type();
        $context = self::normalize_litespeed_invalidation_queue_context($context);
        $incoming_mode = self::get_litespeed_invalidation_queue_mode((bool) $stale, (bool) $refill);
        $incoming_hard = self::litespeed_invalidation_queue_mode_is_stale($incoming_mode) ? 0 : 1;
        $incoming_refill = self::litespeed_invalidation_queue_mode_requires_refill($incoming_mode) ? 1 : 0;
        $now = time();

        foreach ($eligible_urls as $url) {
            $url_hash = sha1((string) $url);
            // Read-only classification improves diagnostics; the SQL upsert below
            // remains authoritative and merges mode flags atomically.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads one UltraCache-owned queue row before an atomic upsert.
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT source_context, status FROM %i WHERE job_type = %s AND url_hash = %s LIMIT 1',
                    $table,
                    $job_type,
                    $url_hash
                ),
                ARRAY_A
            );
            $existing_status = is_array($existing) ? sanitize_key((string) ($existing['status'] ?? '')) : '';
            $existing_mode = is_array($existing) && in_array($existing_status, array('pending', 'processing'), true)
                ? sanitize_key((string) ($existing['source_context'] ?? ''))
                : '';
            $merged_mode = self::merge_litespeed_invalidation_queue_modes($existing_mode, $incoming_mode);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic durable LiteSpeed queue upsert in an UltraCache-owned table.
            $stored = $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO %i (url_hash, url, job_type, source_context, requires_verified_origin, position, status, result_level, claim_token, claimed_at, lease_expires_at, rerun_requested, pending_targets, required_stages, completed_stages, rerun_completed_stages, source_contexts, full_site_outcome, result_message, created_at, updated_at, processed_at, attempt_count, next_attempt_at) VALUES (%s, %s, %s, %s, %d, %d, %s, %s, %s, %d, %d, %d, %s, %s, %s, %s, %s, %s, %s, %d, %d, %d, %d, %d) ON DUPLICATE KEY UPDATE url = VALUES(url), source_context = CASE WHEN status IN ('pending','processing') THEN CASE WHEN (source_context IN ('ls-hard','ls-hard-refill') OR %d = 1) THEN CASE WHEN (source_context IN ('ls-stale-refill','ls-hard-refill') OR %d = 1) THEN 'ls-hard-refill' ELSE 'ls-hard' END ELSE CASE WHEN (source_context IN ('ls-stale-refill','ls-hard-refill') OR %d = 1) THEN 'ls-stale-refill' ELSE 'ls-stale' END END ELSE VALUES(source_context) END, source_contexts = CASE WHEN status IN ('done','skipped','error') THEN VALUES(source_contexts) WHEN VALUES(source_contexts) = '' THEN source_contexts WHEN FIND_IN_SET(VALUES(source_contexts), COALESCE(source_contexts, '')) > 0 THEN source_contexts ELSE TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(COALESCE(source_contexts, ''), ''), VALUES(source_contexts))) END, rerun_requested = CASE WHEN status = 'processing' THEN 1 ELSE 0 END, result_level = CASE WHEN status = 'processing' THEN result_level ELSE '' END, result_message = CASE WHEN status = 'processing' THEN result_message ELSE '' END, created_at = CASE WHEN status IN ('done','skipped','error') THEN VALUES(created_at) ELSE created_at END, updated_at = VALUES(updated_at), processed_at = CASE WHEN status = 'processing' THEN processed_at ELSE 0 END, attempt_count = CASE WHEN status = 'processing' THEN attempt_count ELSE 0 END, next_attempt_at = CASE WHEN status = 'processing' THEN next_attempt_at ELSE 0 END, claim_token = CASE WHEN status = 'processing' THEN claim_token ELSE '' END, claimed_at = CASE WHEN status = 'processing' THEN claimed_at ELSE 0 END, lease_expires_at = CASE WHEN status = 'processing' THEN lease_expires_at ELSE 0 END, status = CASE WHEN status = 'processing' THEN status ELSE 'pending' END",
                    $table,
                    $url_hash,
                    $url,
                    $job_type,
                    $incoming_mode,
                    0,
                    0,
                    'pending',
                    '',
                    '',
                    0,
                    0,
                    0,
                    '',
                    '',
                    '',
                    '',
                    $context,
                    '',
                    '',
                    $now,
                    $now,
                    0,
                    0,
                    0,
                    $incoming_hard,
                    $incoming_refill,
                    $incoming_refill
                )
            );

            if (false === $stored) {
                ++$result['failedUrlCount'];
                $result['failedUrls'][] = $url;
                continue;
            }

            ++$result['queuedUrlCount'];
            if ('' === $existing_mode) {
                ++$result['insertedUrlCount'];
            } else {
                ++$result['coalescedUrlCount'];
                if (!hash_equals($existing_mode, $merged_mode)) {
                    ++$result['upgradedUrlCount'];
                }
            }
        }

        $result['queued'] = $result['queuedUrlCount'] > 0;
        $result['success'] = $result['failedUrlCount'] < 1;
        $result['partial'] = $result['queuedUrlCount'] > 0 && $result['failedUrlCount'] > 0;
        $result['message'] = self::maybe_translate_sprintf(
            'Accepted %1$d LiteSpeed invalidation URL(s): %2$d inserted, %3$d coalesced, %4$d upgraded, %5$d failed to persist.',
            (int) $result['queuedUrlCount'],
            (int) $result['insertedUrlCount'],
            (int) $result['coalescedUrlCount'],
            (int) $result['upgradedUrlCount'],
            (int) $result['failedUrlCount']
        );

        if ($result['queued']) {
            self::ensure_cron_warm_events_scheduled(1, true);
        }

        return $result;
    }

    /**
     * Persist semantic LiteSpeed tag invalidation in the same durable queue used
     * for exact URL targets. Tags use a synthetic `tag:` queue target so the
     * existing unique job_type/url_hash key provides atomic deduplication
     * without a second table or transient.
     *
     * @param array  $tags    Semantic cache tags.
     * @param bool   $stale   Whether stale invalidation is requested.
     * @param string $context Invalidation source.
     * @return array<string,mixed>
     */
    public static function enqueue_litespeed_invalidation_tags(array $tags, $stale = false, $context = 'semantic-invalidation')
    {
        global $wpdb;

        if (method_exists(static::class, 'is_native_litespeed_html_cache_enabled') && !self::is_native_litespeed_html_cache_enabled()) {
            return array(
                'success' => true,
                'queued' => false,
                'skipped' => true,
                'queuedTagCount' => 0,
                'message' => self::maybe_translate('Native LiteSpeed HTML Cache is disabled; semantic invalidation was not queued.'),
            );
        }

        $tags = method_exists(static::class, 'prepare_litespeed_purge_tags')
            ? self::prepare_litespeed_purge_tags($tags, 100)
            : array();
        $result = array(
            'success' => true,
            'queued' => false,
            'queuedTagCount' => 0,
            'insertedTagCount' => 0,
            'coalescedTagCount' => 0,
            'upgradedTagCount' => 0,
            'failedTagCount' => 0,
            'failedTags' => array(),
            'operation' => $stale ? 'stale-tags' : 'tags',
            'stale' => (bool) $stale,
        );
        if (empty($tags)) {
            $result['message'] = self::maybe_translate('No LiteSpeed semantic tags required queued invalidation.');
            return $result;
        }
        if (!($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            $result['success'] = false;
            $result['failedTagCount'] = count($tags);
            $result['failedTags'] = $tags;
            $result['message'] = self::maybe_translate('Persistent LiteSpeed semantic invalidation storage is unavailable.');
            return $result;
        }

        $table = self::get_cron_warm_queue_table_name();
        $job_type = self::get_litespeed_invalidation_queue_job_type();
        $context = self::normalize_litespeed_invalidation_queue_context($context);
        $incoming_mode = self::get_litespeed_invalidation_queue_mode((bool) $stale, false);
        $incoming_hard = self::litespeed_invalidation_queue_mode_is_stale($incoming_mode) ? 0 : 1;
        $now = time();

        foreach ($tags as $tag) {
            $target = self::get_litespeed_tag_queue_target($tag);
            if ('' === $target) {
                ++$result['failedTagCount'];
                $result['failedTags'][] = $tag;
                continue;
            }
            $target_hash = sha1($target);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads one UltraCache-owned queue row before the atomic semantic-target upsert.
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT source_context, status FROM %i WHERE job_type = %s AND url_hash = %s LIMIT 1',
                    $table,
                    $job_type,
                    $target_hash
                ),
                ARRAY_A
            );
            $existing_status = is_array($existing) ? sanitize_key((string) ($existing['status'] ?? '')) : '';
            $existing_mode = is_array($existing) && in_array($existing_status, array('pending', 'processing'), true)
                ? sanitize_key((string) ($existing['source_context'] ?? ''))
                : '';
            $merged_mode = self::merge_litespeed_invalidation_queue_modes($existing_mode, $incoming_mode);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic durable semantic-tag upsert in the existing UltraCache queue table.
            $stored = $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO %i (url_hash, url, job_type, source_context, requires_verified_origin, position, status, result_level, claim_token, claimed_at, lease_expires_at, rerun_requested, pending_targets, required_stages, completed_stages, rerun_completed_stages, source_contexts, full_site_outcome, result_message, created_at, updated_at, processed_at, attempt_count, next_attempt_at) VALUES (%s, %s, %s, %s, %d, %d, %s, %s, %s, %d, %d, %d, %s, %s, %s, %s, %s, %s, %s, %d, %d, %d, %d, %d) ON DUPLICATE KEY UPDATE url = VALUES(url), source_context = CASE WHEN status IN ('pending','processing') THEN CASE WHEN (source_context IN ('ls-hard','ls-hard-refill') OR %d = 1) THEN 'ls-hard' ELSE 'ls-stale' END ELSE VALUES(source_context) END, source_contexts = CASE WHEN status IN ('done','skipped','error') THEN VALUES(source_contexts) WHEN VALUES(source_contexts) = '' THEN source_contexts WHEN FIND_IN_SET(VALUES(source_contexts), COALESCE(source_contexts, '')) > 0 THEN source_contexts ELSE TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(COALESCE(source_contexts, ''), ''), VALUES(source_contexts))) END, rerun_requested = CASE WHEN status = 'processing' THEN 1 ELSE 0 END, result_level = CASE WHEN status = 'processing' THEN result_level ELSE '' END, result_message = CASE WHEN status = 'processing' THEN result_message ELSE '' END, created_at = CASE WHEN status IN ('done','skipped','error') THEN VALUES(created_at) ELSE created_at END, updated_at = VALUES(updated_at), processed_at = CASE WHEN status = 'processing' THEN processed_at ELSE 0 END, attempt_count = CASE WHEN status = 'processing' THEN attempt_count ELSE 0 END, next_attempt_at = CASE WHEN status = 'processing' THEN next_attempt_at ELSE 0 END, claim_token = CASE WHEN status = 'processing' THEN claim_token ELSE '' END, claimed_at = CASE WHEN status = 'processing' THEN claimed_at ELSE 0 END, lease_expires_at = CASE WHEN status = 'processing' THEN lease_expires_at ELSE 0 END, status = CASE WHEN status = 'processing' THEN status ELSE 'pending' END",
                    $table,
                    $target_hash,
                    $target,
                    $job_type,
                    $incoming_mode,
                    0,
                    0,
                    'pending',
                    '',
                    '',
                    0,
                    0,
                    0,
                    '',
                    '',
                    '',
                    '',
                    $context,
                    '',
                    '',
                    $now,
                    $now,
                    0,
                    0,
                    0,
                    $incoming_hard
                )
            );
            if (false === $stored) {
                ++$result['failedTagCount'];
                $result['failedTags'][] = $tag;
                continue;
            }

            ++$result['queuedTagCount'];
            if ('' === $existing_mode) {
                ++$result['insertedTagCount'];
            } else {
                ++$result['coalescedTagCount'];
                if (!hash_equals($existing_mode, $merged_mode)) {
                    ++$result['upgradedTagCount'];
                }
            }
        }

        $result['queued'] = $result['queuedTagCount'] > 0;
        $result['success'] = $result['failedTagCount'] < 1;
        $result['partial'] = $result['queuedTagCount'] > 0 && $result['failedTagCount'] > 0;
        $result['message'] = self::maybe_translate_sprintf(
            'Accepted %1$d LiteSpeed semantic tag(s): %2$d inserted, %3$d coalesced, %4$d upgraded, %5$d failed to persist.',
            (int) $result['queuedTagCount'],
            (int) $result['insertedTagCount'],
            (int) $result['coalescedTagCount'],
            (int) $result['upgradedTagCount'],
            (int) $result['failedTagCount']
        );
        if ($result['queued']) {
            self::ensure_cron_warm_events_scheduled(1, true);
        }
        return $result;
    }

    private static function get_litespeed_invalidation_queue_stats()
    {
        global $wpdb;

        $stats = array(
            'pending' => 0,
            'processing' => 0,
            'retrying' => 0,
            'completed' => 0,
            'terminalErrors' => 0,
            'nextAttemptAt' => 0,
            'nextAttemptAtHuman' => '',
            'pendingUrlTargets' => 0,
            'pendingTagTargets' => 0,
        );
        if (!($wpdb instanceof wpdb) || !self::cron_warm_queue_table_read_ready()) {
            return $stats;
        }

        $table = self::get_cron_warm_queue_table_name();
        $job_type = self::get_litespeed_invalidation_queue_job_type();
        $tag_like = $wpdb->esc_like('tag:') . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate read from the UltraCache-owned durable queue.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS pending_count, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS processing_count, SUM(CASE WHEN status = %s AND attempt_count > %d THEN 1 ELSE 0 END) AS retrying_count, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS completed_count, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS error_count, MIN(CASE WHEN status = %s AND next_attempt_at > %d THEN next_attempt_at ELSE NULL END) AS next_attempt_at, SUM(CASE WHEN status IN ('pending','processing') AND url NOT LIKE %s THEN 1 ELSE 0 END) AS pending_url_targets, SUM(CASE WHEN status IN ('pending','processing') AND url LIKE %s THEN 1 ELSE 0 END) AS pending_tag_targets FROM %i WHERE job_type = %s",
                'pending',
                'processing',
                'pending',
                0,
                'done',
                'error',
                'pending',
                time(),
                $tag_like,
                $tag_like,
                $table,
                $job_type
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return $stats;
        }

        $stats['pending'] = max(0, (int) ($row['pending_count'] ?? 0));
        $stats['processing'] = max(0, (int) ($row['processing_count'] ?? 0));
        $stats['retrying'] = max(0, (int) ($row['retrying_count'] ?? 0));
        $stats['completed'] = max(0, (int) ($row['completed_count'] ?? 0));
        $stats['terminalErrors'] = max(0, (int) ($row['error_count'] ?? 0));
        $stats['nextAttemptAt'] = max(0, (int) ($row['next_attempt_at'] ?? 0));
        $stats['nextAttemptAtHuman'] = $stats['nextAttemptAt'] > 0
            ? gmdate('Y-m-d H:i:s', $stats['nextAttemptAt']) . ' UTC'
            : '';
        $stats['pendingUrlTargets'] = max(0, (int) ($row['pending_url_targets'] ?? 0));
        $stats['pendingTagTargets'] = max(0, (int) ($row['pending_tag_targets'] ?? 0));
        return $stats;
    }

    private static function has_pending_litespeed_invalidation_rows()
    {
        $stats = self::get_litespeed_invalidation_queue_stats();
        return !empty($stats['pending']) || !empty($stats['processing']);
    }

    private static function load_ready_litespeed_invalidation_rows($limit = 100)
    {
        global $wpdb;
        if (!($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return array();
        }

        $limit = max(1, min(200, absint($limit)));
        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded durable LiteSpeed queue read.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, url, source_context, source_contexts, attempt_count, next_attempt_at FROM %i WHERE job_type = %s AND status = %s AND next_attempt_at <= %d ORDER BY next_attempt_at ASC, id ASC LIMIT %d',
                $table,
                self::get_litespeed_invalidation_queue_job_type(),
                'pending',
                time(),
                $limit
            ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }

    private static function claim_litespeed_invalidation_row(array $candidate)
    {
        global $wpdb;
        $row_id = absint($candidate['id'] ?? 0);
        if ($row_id < 1 || !($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return array();
        }

        $table = self::get_cron_warm_queue_table_name();
        $now = time();
        $claim_token = 'litespeed-' . wp_generate_password(32, false, false);
        $lease_expires_at = $now + self::get_cron_warm_queue_lease_seconds();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic claim of one UltraCache-owned LiteSpeed queue row.
        $claimed = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET status = %s, claim_token = %s, claimed_at = %d, lease_expires_at = %d, rerun_requested = %d, attempt_count = attempt_count + 1, updated_at = %d WHERE id = %d AND job_type = %s AND status = %s AND next_attempt_at <= %d',
                $table,
                'processing',
                $claim_token,
                $now,
                $lease_expires_at,
                0,
                $now,
                $row_id,
                self::get_litespeed_invalidation_queue_job_type(),
                'pending',
                $now
            )
        );
        if (1 !== (int) $claimed) {
            return array();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads back only the newly claimed LiteSpeed queue row.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, url, source_context, source_contexts, status, claim_token, claimed_at, lease_expires_at, rerun_requested, attempt_count FROM %i WHERE id = %d AND job_type = %s AND status = %s AND claim_token = %s LIMIT 1',
                $table,
                $row_id,
                self::get_litespeed_invalidation_queue_job_type(),
                'processing',
                $claim_token
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : array();
    }

    private static function commit_litespeed_invalidation_row(array $row, $success, $retryable, $message = '')
    {
        global $wpdb;
        $row_id = absint($row['id'] ?? 0);
        $claim_token = sanitize_text_field((string) ($row['claim_token'] ?? ''));
        if ($row_id < 1 || '' === $claim_token || !($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return array('success' => false, 'leaseLost' => true);
        }

        $table = self::get_cron_warm_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads claim state immediately before guarded commit.
        $current = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT source_context, rerun_requested, attempt_count FROM %i WHERE id = %d AND job_type = %s AND status = %s AND claim_token = %s LIMIT 1',
                $table,
                $row_id,
                self::get_litespeed_invalidation_queue_job_type(),
                'processing',
                $claim_token
            ),
            ARRAY_A
        );
        if (!is_array($current)) {
            return array('success' => false, 'leaseLost' => true);
        }

        $message = sanitize_textarea_field((string) $message);
        if (strlen($message) > 2000) {
            $message = substr($message, 0, 2000);
        }
        $now = time();
        $rerun = !empty($current['rerun_requested']);
        $attempt_count = max(1, (int) ($current['attempt_count'] ?? 1));
        $mode = sanitize_key((string) ($current['source_context'] ?? $row['source_context'] ?? 'ls-hard'));

        if ($rerun) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic guarded commit to the UltraCache-owned durable queue.
            $saved = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, result_level = %s, result_message = %s, claim_token = %s, claimed_at = %d, lease_expires_at = %d, rerun_requested = %d, attempt_count = %d, next_attempt_at = %d, updated_at = %d, processed_at = %d WHERE id = %d AND status = %s AND claim_token = %s',
                    $table,
                    'pending',
                    '',
                    self::maybe_translate('A newer LiteSpeed invalidation was merged while this row was processing; the row was requeued.'),
                    '',
                    0,
                    0,
                    0,
                    0,
                    0,
                    $now,
                    0,
                    $row_id,
                    'processing',
                    $claim_token
                )
            );
            return array('success' => 1 === (int) $saved, 'requeued' => true, 'mode' => $mode);
        }

        if ($success) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic guarded commit to the UltraCache-owned durable queue.
            $saved = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, result_level = %s, result_message = %s, claim_token = %s, claimed_at = %d, lease_expires_at = %d, rerun_requested = %d, next_attempt_at = %d, updated_at = %d, processed_at = %d WHERE id = %d AND status = %s AND claim_token = %s',
                    $table,
                    'done',
                    'success',
                    $message,
                    '',
                    0,
                    0,
                    0,
                    0,
                    $now,
                    $now,
                    $row_id,
                    'processing',
                    $claim_token
                )
            );
            return array('success' => 1 === (int) $saved, 'completed' => true, 'mode' => $mode);
        }

        $can_retry = (bool) $retryable && $attempt_count < self::get_litespeed_invalidation_queue_max_attempts();
        $next_attempt_at = $can_retry ? $now + self::get_litespeed_invalidation_queue_retry_delay($attempt_count) : 0;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic guarded commit to the UltraCache-owned durable queue.
        $saved = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET status = %s, result_level = %s, result_message = %s, claim_token = %s, claimed_at = %d, lease_expires_at = %d, rerun_requested = %d, next_attempt_at = %d, updated_at = %d, processed_at = %d WHERE id = %d AND status = %s AND claim_token = %s',
                $table,
                $can_retry ? 'pending' : 'error',
                $can_retry ? 'retrying' : 'error',
                $message,
                '',
                0,
                0,
                0,
                $next_attempt_at,
                $now,
                $can_retry ? 0 : $now,
                $row_id,
                'processing',
                $claim_token
            )
        );
        return array(
            'success' => 1 === (int) $saved,
            'retrying' => $can_retry,
            'terminal' => !$can_retry,
            'mode' => $mode,
            'nextAttemptAt' => $next_attempt_at,
        );
    }

    private static function prune_completed_litespeed_invalidation_rows()
    {
        global $wpdb;
        if (!($wpdb instanceof wpdb) || !self::ensure_cron_warm_queue_table()) {
            return 0;
        }

        $table = self::get_cron_warm_queue_table_name();
        $now = time();
        $done_before = $now - DAY_IN_SECONDS;
        $error_before = $now - (14 * DAY_IN_SECONDS);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded-retention cleanup for terminal UltraCache LiteSpeed queue rows.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE job_type = %s AND ((status IN (%s, %s) AND processed_at > %d AND processed_at < %d) OR (status = %s AND processed_at > %d AND processed_at < %d))',
                $table,
                self::get_litespeed_invalidation_queue_job_type(),
                'done',
                'skipped',
                0,
                $done_before,
                'error',
                0,
                $error_before
            )
        );
        return false === $deleted ? 0 : max(0, (int) $deleted);
    }

    /**
     * Process ready durable LiteSpeed invalidations.
     *
     * The worker is bounded by row count and the native signed-control URL batch
     * size, never by an UltraCache wall-clock deadline.
     *
     * @param int $limit Maximum rows considered this tick.
     * @return array<string,mixed>
     */
    private static function process_ready_litespeed_invalidation_rows($limit = 100)
    {
        if (method_exists(static::class, 'is_manual_warmup_blocking_cron') && self::is_manual_warmup_blocking_cron()) {
            return array(
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
                'reason' => 'foreground-warm-priority',
                'queue' => self::get_litespeed_invalidation_queue_stats(),
            );
        }

        self::recover_expired_cron_warm_queue_leases();
        $candidates = self::load_ready_litespeed_invalidation_rows($limit);
        if (empty($candidates)) {
            self::prune_completed_litespeed_invalidation_rows();
            return array('processed' => 0, 'success' => 0, 'failed' => 0, 'queue' => self::get_litespeed_invalidation_queue_stats());
        }

        $lock_token = 'litespeed-queue-' . wp_generate_password(20, false, false);
        $execution_fence = array();
        if (!self::acquire_cron_warm_lock($lock_token, self::get_cron_warm_queue_lease_seconds(), $execution_fence)) {
            return array('processed' => 0, 'success' => 0, 'failed' => 0, 'locked' => true, 'queue' => self::get_litespeed_invalidation_queue_stats());
        }

        $claimed = array();
        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $retried = 0;
        $refill_urls = array();

        try {
            foreach ($candidates as $candidate) {
                if (method_exists(static::class, 'is_manual_warmup_blocking_cron') && self::is_manual_warmup_blocking_cron()) {
                    break;
                }
                $row = self::claim_litespeed_invalidation_row($candidate);
                if (!empty($row)) {
                    $claimed[] = $row;
                }
            }

            $groups = array();
            foreach ($claimed as $row) {
                $mode = sanitize_key((string) ($row['source_context'] ?? 'ls-hard'));
                $groups[$mode][] = $row;
            }

            foreach ($groups as $mode => $rows) {
                $stale = self::litespeed_invalidation_queue_mode_is_stale($mode);
                $chunk_limit = method_exists(static::class, 'get_litespeed_control_url_limit')
                    ? self::get_litespeed_control_url_limit()
                    : 20;
                $target_groups = array('url' => array(), 'tag' => array());
                foreach ($rows as $row) {
                    $target = (string) ($row['url'] ?? '');
                    $target_groups[self::litespeed_queue_target_is_tag($target) ? 'tag' : 'url'][] = $row;
                }

                foreach ($target_groups as $target_type => $target_rows) {
                    foreach (array_chunk($target_rows, max(1, (int) $chunk_limit)) as $chunk) {
                        if ('tag' === $target_type) {
                            $targets = array_values(array_filter(array_map(static function ($row) {
                                return self::get_litespeed_tag_from_queue_target((string) ($row['url'] ?? ''));
                            }, $chunk)));
                            $purge = empty($targets)
                                ? array('success' => false, 'message' => self::maybe_translate('Queued LiteSpeed semantic tag target was invalid.'))
                                : self::purge_litespeed_tags(
                                    $targets,
                                    $stale,
                                    $stale ? 'queued-stale-semantic-invalidation' : 'queued-hard-semantic-invalidation'
                                );
                        } else {
                            $targets = array_values(array_filter(array_map(static function ($row) {
                                return esc_url_raw((string) ($row['url'] ?? ''));
                            }, $chunk)));
                            $purge = empty($targets)
                                ? array('success' => false, 'message' => self::maybe_translate('Queued LiteSpeed URL target was invalid.'))
                                : self::purge_litespeed_urls(
                                    $targets,
                                    $stale,
                                    $stale ? 'queued-stale-invalidation' : 'queued-hard-invalidation'
                                );
                        }

                        $purge_success = !empty($purge['success']);
                        $retryable = !$purge_success
                            && method_exists(static::class, 'is_litespeed_purge_failure_retryable')
                            && self::is_litespeed_purge_failure_retryable($purge);
                        $message = (string) ($purge['message'] ?? self::maybe_translate('Queued LiteSpeed invalidation completed.'));

                        foreach ($chunk as $row) {
                            ++$processed;
                            $commit = self::commit_litespeed_invalidation_row($row, $purge_success, $retryable, $message);
                            if (!empty($commit['requeued'])) {
                                ++$retried;
                                continue;
                            }
                            if ($purge_success && !empty($commit['completed'])) {
                                ++$succeeded;
                                if ('url' === $target_type && self::litespeed_invalidation_queue_mode_requires_refill($commit['mode'] ?? $mode)) {
                                    $url = esc_url_raw((string) ($row['url'] ?? ''));
                                    if ('' !== $url) {
                                        $refill_urls[$url] = $url;
                                    }
                                }
                            } else {
                                ++$failed;
                                if (!empty($commit['retrying'])) {
                                    ++$retried;
                                }
                            }
                        }
                    }
                }
            }

            if (!empty($refill_urls) && method_exists(static::class, 'enqueue_targeted_warm_pipeline_urls')) {
                self::enqueue_targeted_warm_pipeline_urls(
                    array_values($refill_urls),
                    false,
                    'litespeed-queued-invalidation'
                );
            }
        } finally {
            self::release_cron_warm_lock($lock_token);
        }

        self::prune_completed_litespeed_invalidation_rows();
        $queue = self::get_litespeed_invalidation_queue_stats();
        if (!empty($queue['pending']) || !empty($queue['processing'])) {
            $delay = 1;
            if (!empty($queue['nextAttemptAt']) && (int) $queue['nextAttemptAt'] > time()) {
                $delay = max(1, min(300, (int) $queue['nextAttemptAt'] - time()));
            }
            self::ensure_cron_warm_events_scheduled($delay, true);
        }

        return array(
            'processed' => $processed,
            'success' => $succeeded,
            'failed' => $failed,
            'retrying' => $retried,
            'refillQueuedUrlCount' => count($refill_urls),
            'queue' => $queue,
        );
    }
}
