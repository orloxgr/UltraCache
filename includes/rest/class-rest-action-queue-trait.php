<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!trait_exists('Ultra_Cache_Rest_Action_Queue_Trait')) {
    trait Ultra_Cache_Rest_Action_Queue_Trait
    {
        private function get_action_queue_option_key()
        {
            return defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY . '_action_jobs' : 'ultracache_settings_action_jobs';
        }

        private function get_allowed_action_queue_actions()
        {
            return array(
                'purge_all',
                'object_cache_flush',
                'object_cache_full_count',
                'warm_frontpage_html',
                'warm_frontpage_html_css',
                'varnish_test',
                'varnish_flush_all',
                'opcache_flush',
                'apcu_flush',
                'litespeed_flush',
                'nginx_flush',
                'external_caches_redetect',
                'redis_test',
                'google_fonts_rebuild_cache',
                'performance_profile',
            );
        }

        private function get_heavy_action_queue_actions()
        {
            return array(
                'purge_all',
                'object_cache_flush',
                'object_cache_full_count',
                'warm_frontpage_html',
                'warm_frontpage_html_css',
                'varnish_flush_all',
                'google_fonts_rebuild_cache',
                'performance_profile',
            );
        }

        private function is_heavy_action_queue_action($action)
        {
            return in_array((string) $action, $this->get_heavy_action_queue_actions(), true);
        }

        private function get_action_queue_stale_seconds()
        {
            return 180;
        }

        private function get_action_queue_heavy_lock_option_name()
        {
            return 'ultracache_action_queue_heavy_lock_v1';
        }


        private function get_action_jobs_db_version()
        {
            return '1';
        }

        private function get_action_jobs_db_version_option_key()
        {
            return 'ultracache_action_jobs_db_version';
        }

        private function get_action_jobs_table_name()
        {
            global $wpdb;
            return $wpdb->prefix . 'ultracache_action_jobs';
        }

        private function action_jobs_table_exists()
        {
            global $wpdb;
            if (!($wpdb instanceof wpdb)) {
                return false;
            }

            $table = $this->get_action_jobs_table_name();
            $cache_key = 'action_jobs_table_exists_' . md5((string) $table);
            $cache_found = false;
            $cached = wp_cache_get($cache_key, 'ultracache', false, $cache_found);
            if ($cache_found && is_bool($cached)) {
                return $cached;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema existence check for an UltraCache-owned custom table; result is cached below.
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            $exists = ((string) $found === (string) $table);
            wp_cache_set($cache_key, $exists, 'ultracache', HOUR_IN_SECONDS);
            return $exists;
        }

        public function ensure_action_jobs_table()
        {
            global $wpdb;

            if (!($wpdb instanceof wpdb)) {
                return false;
            }

            $table = $this->get_action_jobs_table_name();
            $version = (string) get_option($this->get_action_jobs_db_version_option_key(), '');
            if ($this->get_action_jobs_db_version() === $version && $this->action_jobs_table_exists()) {
                return true;
            }

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$table} (
                job_id varchar(64) NOT NULL,
                action varchar(80) NOT NULL DEFAULT '',
                status varchar(20) NOT NULL DEFAULT 'queued',
                is_heavy tinyint(1) unsigned NOT NULL DEFAULT 0,
                is_direct tinyint(1) unsigned NOT NULL DEFAULT 0,
                message text NULL,
                params longtext NULL,
                result longtext NULL,
                blocked_by varchar(64) NOT NULL DEFAULT '',
                created_at bigint(20) unsigned NOT NULL DEFAULT 0,
                started_at bigint(20) unsigned NOT NULL DEFAULT 0,
                updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
                finished_at bigint(20) unsigned NOT NULL DEFAULT 0,
                orphaned_runtime tinyint(1) unsigned NOT NULL DEFAULT 0,
                extra_data longtext NULL,
                PRIMARY KEY  (job_id),
                KEY status_updated (status, updated_at),
                KEY action_status (action, status),
                KEY finished_at (finished_at),
                KEY created_at (created_at)
            ) {$charset_collate};";

            dbDelta($sql);
            wp_cache_delete('action_jobs_table_exists_' . md5((string) $table), 'ultracache');
            if ($this->action_jobs_table_exists()) {
                update_option($this->get_action_jobs_db_version_option_key(), $this->get_action_jobs_db_version(), false);
                wp_cache_delete('action_jobs_table_exists_' . md5((string) $table), 'ultracache');
                return true;
            }

            return false;
        }

        private function action_queue_job_from_row(array $row)
        {
            $job = array(
                'id'        => sanitize_text_field((string) ($row['job_id'] ?? '')),
                'action'    => sanitize_key((string) ($row['action'] ?? '')),
                'status'    => sanitize_key((string) ($row['status'] ?? 'queued')),
                'createdAt' => (int) ($row['created_at'] ?? 0),
                'startedAt' => (int) ($row['started_at'] ?? 0),
                'updatedAt' => (int) ($row['updated_at'] ?? 0),
                'finishedAt'=> (int) ($row['finished_at'] ?? 0),
            );

            $message = isset($row['message']) ? (string) $row['message'] : '';
            if ('' !== $message) {
                $job['message'] = $message;
            }

            if (!empty($row['params'])) {
                $params = maybe_unserialize($row['params']);
                $job['params'] = is_array($params) ? $params : array();
            } else {
                $job['params'] = array();
            }

            if (!empty($row['result'])) {
                $result = maybe_unserialize($row['result']);
                if (is_array($result)) {
                    $job['result'] = $result;
                }
            }

            if (!empty($row['blocked_by'])) {
                $job['blockedBy'] = sanitize_text_field((string) $row['blocked_by']);
            }
            if (!empty($row['is_direct'])) {
                $job['direct'] = true;
            }
            if (!empty($row['orphaned_runtime'])) {
                $job['orphanedRuntime'] = true;
            }

            if (!empty($row['extra_data'])) {
                $extra = maybe_unserialize($row['extra_data']);
                if (is_array($extra)) {
                    foreach ($extra as $extra_key => $extra_value) {
                        if (!array_key_exists($extra_key, $job)) {
                            $job[$extra_key] = $extra_value;
                        }
                    }
                }
            }

            return $job;
        }

        private function action_queue_job_to_row($id, array $job)
        {
            $id = sanitize_text_field((string) $id);
            if ('' === $id) {
                $id = sanitize_text_field((string) ($job['id'] ?? ''));
            }

            $core_keys = array(
                'id' => true,
                'action' => true,
                'status' => true,
                'message' => true,
                'params' => true,
                'result' => true,
                'createdAt' => true,
                'startedAt' => true,
                'updatedAt' => true,
                'finishedAt' => true,
                'blockedBy' => true,
                'direct' => true,
                'orphanedRuntime' => true,
            );
            $extra = array();
            foreach ($job as $key => $value) {
                if (!isset($core_keys[$key])) {
                    $extra[$key] = $value;
                }
            }

            $action = sanitize_key((string) ($job['action'] ?? ''));
            $status = sanitize_key((string) ($job['status'] ?? 'queued'));
            if (!in_array($status, array('queued', 'running', 'done', 'failed'), true)) {
                $status = 'queued';
            }

            return array(
                'job_id'           => $id,
                'action'           => $action,
                'status'           => $status,
                'is_heavy'         => $this->is_heavy_action_queue_action($action) ? 1 : 0,
                'is_direct'        => !empty($job['direct']) ? 1 : 0,
                'message'          => isset($job['message']) ? sanitize_textarea_field((string) $job['message']) : '',
                'params'           => maybe_serialize(is_array($job['params'] ?? null) ? $job['params'] : array()),
                'result'           => isset($job['result']) ? maybe_serialize($job['result']) : '',
                'blocked_by'       => sanitize_text_field((string) ($job['blockedBy'] ?? '')),
                'created_at'       => max(0, (int) ($job['createdAt'] ?? time())),
                'started_at'       => max(0, (int) ($job['startedAt'] ?? 0)),
                'updated_at'       => max(0, (int) ($job['updatedAt'] ?? 0)),
                'finished_at'      => max(0, (int) ($job['finishedAt'] ?? 0)),
                'orphaned_runtime' => !empty($job['orphanedRuntime']) ? 1 : 0,
                'extra_data'       => !empty($extra) ? maybe_serialize($extra) : '',
            );
        }

        private function normalize_action_jobs(array $jobs)
        {
            $now = time();
            $stale_after = $this->get_action_queue_stale_seconds();
            foreach ($jobs as $id => $job) {
                if (!is_array($job)) {
                    unset($jobs[$id]);
                    continue;
                }

                $status = (string) ($job['status'] ?? 'queued');
                $created = isset($job['createdAt']) ? (int) $job['createdAt'] : $now;
                $started = isset($job['startedAt']) ? (int) $job['startedAt'] : 0;
                $updated = isset($job['updatedAt']) ? (int) $job['updatedAt'] : 0;
                $finished = isset($job['finishedAt']) ? (int) $job['finishedAt'] : 0;
                $age_base = max($started, $updated, $created);

                if (in_array($status, array('queued', 'running'), true) && $age_base > 0 && ($now - $age_base) > $stale_after) {
                    $job['status'] = 'failed';
                    $job['message'] = 'Dashboard processing action was marked stale and stopped from blocking new work.';
                    $job['finishedAt'] = $now;
                    $job['updatedAt'] = $now;
                    $jobs[$id] = $job;
                    $status = 'failed';
                    $finished = $now;
                }

                $terminal = in_array($status, array('done', 'failed'), true);
                if (($terminal && $finished > 0 && ($now - $finished) > HOUR_IN_SECONDS) || (!$terminal && ($now - $created) > 6 * HOUR_IN_SECONDS)) {
                    unset($jobs[$id]);
                }
            }

            if (count($jobs) > 20) {
                uasort($jobs, static function ($a, $b) {
                    $a_time = is_array($a) ? (int) ($a['updatedAt'] ?? $a['createdAt'] ?? 0) : 0;
                    $b_time = is_array($b) ? (int) ($b['updatedAt'] ?? $b['createdAt'] ?? 0) : 0;
                    return $b_time <=> $a_time;
                });
                $jobs = array_slice($jobs, 0, 20, true);
            }

            return $jobs;
        }

        private function get_action_jobs_rows_cache_key()
        {
            return 'action_jobs_rows_v2_' . md5((string) $this->get_action_jobs_table_name());
        }

        private function flush_action_jobs_rows_cache()
        {
            wp_cache_delete($this->get_action_jobs_rows_cache_key(), 'ultracache');
            wp_cache_delete('action_jobs_rows_v1', 'ultracache');
        }

        private function load_action_jobs()
        {
            if (!$this->ensure_action_jobs_table()) {
                return array();
            }

            global $wpdb;
            $table = $this->get_action_jobs_table_name();
            $cache_key = $this->get_action_jobs_rows_cache_key();

            /*
             * Action jobs are wp-admin/dashboard state, not a frontend hot path.
             * Always refresh from the UltraCache-owned custom table so queued
             * actions cannot disappear behind a stale persistent object-cache row.
             * The refreshed rows are still written to object cache for diagnostics
             * and for WordPressCS' explicit caching expectation around custom DB reads.
             */
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache-owned custom action queue table; refreshed intentionally to avoid stale dashboard job state and then cached below.
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY updated_at DESC, created_at DESC LIMIT %d', $table, 50), ARRAY_A);
            $rows = is_array($rows) ? $rows : array();
            wp_cache_set($cache_key, $rows, 'ultracache', 30);

            $jobs = array();
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $job = is_array($row) ? $this->action_queue_job_from_row($row) : array();
                    $id = (string) ($job['id'] ?? '');
                    if ('' !== $id) {
                        $jobs[$id] = $job;
                    }
                }
            }

            $normalized = $this->normalize_action_jobs($jobs);
            if ($normalized !== $jobs) {
                $this->save_action_jobs($normalized);
                $jobs = $normalized;
            }

            return $this->reconcile_action_queue_state($jobs);
        }

        private function save_action_jobs(array $jobs)
        {
            if (!$this->ensure_action_jobs_table()) {
                return;
            }

            global $wpdb;
            $table = $this->get_action_jobs_table_name();
            $normalized = $this->normalize_action_jobs($jobs);
            $scrubbed = $this->scrub_action_jobs_for_storage($normalized);

            $this->flush_action_jobs_rows_cache();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache-owned custom action queue table; cache is invalidated before writes.
            $wpdb->query($wpdb->prepare('DELETE FROM %i', $table));
            foreach ($scrubbed as $id => $job) {
                if (!is_array($job)) {
                    continue;
                }
                $row = $this->action_queue_job_to_row($id, $job);
                if ('' === (string) $row['job_id']) {
                    continue;
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache-owned custom action queue table; cache is invalidated before writes.
                $wpdb->replace($table, $row, array(
                    '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s',
                    '%d', '%d', '%d', '%d', '%d', '%s',
                ));
            }
            $this->flush_action_jobs_rows_cache();
        }

        private function find_active_heavy_action_job(array $jobs, $exclude_id = '')
        {
            foreach ($jobs as $id => $job) {
                if ((string) $id === (string) $exclude_id || !is_array($job)) {
                    continue;
                }
                $status = (string) ($job['status'] ?? '');
                $action = (string) ($job['action'] ?? '');
                if ($this->is_heavy_action_queue_action($action) && in_array($status, array('queued', 'running'), true)) {
                    return $job;
                }
            }

            return array();
        }

        private function get_action_queue_lock_payload()
        {
            $lock = get_option($this->get_action_queue_heavy_lock_option_name(), array());
            return is_array($lock) ? $lock : array();
        }

        private function is_action_queue_job_locked(array $job, $job_id = '')
        {
            $lock = $this->get_action_queue_lock_payload();
            if (empty($lock)) {
                return false;
            }

            $lock_job_id = (string) ($lock['jobId'] ?? '');
            if ('' !== (string) $job_id && '' !== $lock_job_id && $lock_job_id !== (string) $job_id) {
                return false;
            }

            $lock_action = sanitize_key((string) ($lock['action'] ?? ''));
            $job_action = sanitize_key((string) ($job['action'] ?? ''));

            return '' !== $lock_action && '' !== $job_action && $lock_action === $job_action;
        }

        private function acquire_action_queue_heavy_lock($action, $job_id)
        {
            $action = sanitize_key((string) $action);
            $job_id = sanitize_text_field((string) $job_id);
            $now = time();
            $option_name = $this->get_action_queue_heavy_lock_option_name();
            $payload = array(
                'action' => $action,
                'jobId'  => $job_id,
                'time'   => $now,
            );

            if (add_option($option_name, $payload, '', false)) {
                return true;
            }

            $existing = get_option($option_name, array());
            $existing_time = is_array($existing) ? (int) ($existing['time'] ?? 0) : 0;
            if ($existing_time > 0 && ($now - $existing_time) > $this->get_action_queue_stale_seconds()) {
                delete_option($option_name);
                return add_option($option_name, $payload, '', false);
            }

            return false;
        }

        private function release_action_queue_heavy_lock($job_id)
        {
            $option_name = $this->get_action_queue_heavy_lock_option_name();
            $existing = get_option($option_name, array());
            if (is_array($existing) && (string) ($existing['jobId'] ?? '') === (string) $job_id) {
                delete_option($option_name);
            }
        }

        private function get_action_queue_job_age(array $job)
        {
            $started = isset($job['startedAt']) ? (int) $job['startedAt'] : 0;
            $updated = isset($job['updatedAt']) ? (int) $job['updatedAt'] : 0;
            $created = isset($job['createdAt']) ? (int) $job['createdAt'] : 0;
            $base = max($started, $updated, $created);

            return $base > 0 ? max(0, time() - $base) : 0;
        }

        private function get_action_queue_lock_age()
        {
            $existing = get_option($this->get_action_queue_heavy_lock_option_name(), array());
            if (!is_array($existing)) {
                return 0;
            }

            $time = isset($existing['time']) ? (int) $existing['time'] : 0;
            return $time > 0 ? max(0, time() - $time) : 0;
        }

        private function reconcile_action_queue_heavy_lock(array $jobs)
        {
            $option_name = $this->get_action_queue_heavy_lock_option_name();
            $lock = get_option($option_name, array());
            if (!is_array($lock) || empty($lock)) {
                return $jobs;
            }

            $now = time();
            $lock_job_id = (string) ($lock['jobId'] ?? '');
            $lock_action = sanitize_key((string) ($lock['action'] ?? ''));
            $lock_time = isset($lock['time']) ? (int) $lock['time'] : 0;
            $lock_age = $lock_time > 0 ? max(0, $now - $lock_time) : PHP_INT_MAX;
            $stale_after = $this->get_action_queue_stale_seconds();
            $matching_job = ($lock_job_id !== '' && isset($jobs[$lock_job_id]) && is_array($jobs[$lock_job_id])) ? $jobs[$lock_job_id] : array();
            $matching_status = (string) ($matching_job['status'] ?? '');
            $matching_action = sanitize_key((string) ($matching_job['action'] ?? $lock_action));
            $matching_age = !empty($matching_job) ? $this->get_action_queue_job_age($matching_job) : PHP_INT_MAX;
            $delete_lock = false;

            if (empty($matching_job)) {
                $delete_lock = true;
            } elseif (!in_array($matching_status, array('queued', 'running'), true)) {
                $delete_lock = true;
            } elseif (!$this->is_heavy_action_queue_action($matching_action)) {
                $delete_lock = true;
            } elseif ($lock_age > $stale_after || $matching_age > $stale_after) {
                $jobs[$lock_job_id]['status'] = 'failed';
                $jobs[$lock_job_id]['message'] = 'Dashboard processing action was marked stale and stopped from blocking new work.';
                $jobs[$lock_job_id]['finishedAt'] = $now;
                $jobs[$lock_job_id]['updatedAt'] = $now;
                $delete_lock = true;
            }

            if ($delete_lock) {
                delete_option($option_name);
            }

            return $jobs;
        }

        private function reconcile_action_queue_orphaned_running_jobs(array $jobs)
        {
            $now = time();
            $lock = $this->get_action_queue_lock_payload();
            $lock_job_id = is_array($lock) ? (string) ($lock['jobId'] ?? '') : '';

            foreach ($jobs as $id => $job) {
                if (!is_array($job)) {
                    continue;
                }

                $status = (string) ($job['status'] ?? '');
                $action = (string) ($job['action'] ?? '');
                if ('running' !== $status || !$this->is_heavy_action_queue_action($action)) {
                    continue;
                }

                if ('' !== $lock_job_id && (string) $id === $lock_job_id) {
                    continue;
                }

                $age = $this->get_action_queue_job_age($job);
                if ($age < 10) {
                    continue;
                }

                $job['status'] = 'failed';
                $job['message'] = 'Dashboard processing action was released because its worker lock was no longer active.';
                $job['finishedAt'] = $now;
                $job['updatedAt'] = $now;
                $job['orphanedRuntime'] = true;
                $jobs[$id] = $job;
            }

            return $jobs;
        }

        private function reconcile_action_queue_state(array $jobs)
        {
            $before = $jobs;
            $jobs = $this->reconcile_action_queue_heavy_lock($jobs);
            $jobs = $this->reconcile_action_queue_orphaned_running_jobs($jobs);

            if ($jobs !== $before) {
                $this->save_action_jobs($jobs);
            }

            return $jobs;
        }

        private function is_sensitive_action_queue_key($key)
        {
            if (function_exists('ucwp_is_sensitive_debug_key')) {
                return (bool) ucwp_is_sensitive_debug_key($key);
            }

            return 1 === preg_match('/(?:^key$|password|passwd|pwd|secret|token|authorization|cookie|nonce|auth|credential|security|redis[_-]?password|varnish.*key|varnish.*secret|api[_-]?key|access[_-]?key|private[_-]?key|order[_-]?key|client[_-]?secret)/i', (string) $key);
        }

        private function scrub_action_queue_payload($value, $key = '', $depth = 0)
        {
            if (function_exists('ucwp_redact_sensitive_debug_value')) {
                return ucwp_redact_sensitive_debug_value($key, $value, $depth);
            }

            if ($this->is_sensitive_action_queue_key($key)) {
                return '[redacted]';
            }

            if ($depth > 8) {
                return is_scalar($value) || null === $value ? $value : '[truncated]';
            }

            if (is_array($value)) {
                $lower_key = strtolower((string) $key);
                if (in_array($lower_key, array('sourcedetails', 'source_details'), true)) {
                    return array(
                        '_truncated' => true,
                        'count' => count($value),
                        'items' => array_slice($value, 0, 10),
                    );
                }
                if (in_array($lower_key, array('sourceurls', 'source_urls'), true) && count($value) > 25) {
                    return array_merge(array_slice($value, 0, 25), array('... ' . (count($value) - 25) . ' more'));
                }
                if ($depth >= 4 && count($value) > 50) {
                    return array(
                        '_truncated' => true,
                        'count' => count($value),
                        'items' => array_slice($value, 0, 20),
                    );
                }

                $scrubbed = array();
                foreach ($value as $child_key => $child_value) {
                    $scrubbed[$child_key] = $this->scrub_action_queue_payload($child_value, (string) $child_key, $depth + 1);
                }
                return $scrubbed;
            }

            if (is_string($value)) {
                if (function_exists('ucwp_redact_sensitive_string')) {
                    return ucwp_redact_sensitive_string($value);
                }
                if (preg_match('/(?:password|passwd|pwd|secret|token|nonce|auth|credential|security|key)=([^&\s]+)/i', $value)) {
                    return preg_replace('/((?:password|passwd|pwd|secret|token|nonce|auth|credential|security|key)=)([^&\s]+)/i', '$1[redacted]', $value);
                }
                if (preg_match('/(?:bearer\s+|basic\s+)[a-z0-9._~+\/=:-]+/i', $value)) {
                    return '[redacted]';
                }
            }

            return $value;
        }

        private function scrub_action_jobs_for_storage(array $jobs)
        {
            $scrubbed = array();
            foreach ($jobs as $id => $job) {
                $scrubbed[$id] = is_array($job) ? $this->scrub_action_queue_payload($job, 'job', 0) : $job;
            }
            return $scrubbed;
        }

        private function normalize_action_params($params)
        {
            if (!is_array($params)) {
                return array();
            }

            $normalized = array();
            foreach ($params as $key => $value) {
                $key = sanitize_key((string) $key);
                if ('' === $key) {
                    continue;
                }
                if (is_scalar($value) || null === $value) {
                    $normalized[$key] = is_string($value) ? sanitize_text_field($value) : $value;
                }
            }

            return $normalized;
        }

        public function enqueue_action_job(WP_REST_Request $request)
        {
            $action = sanitize_key((string) $request->get_param('action'));
            if (!in_array($action, $this->get_allowed_action_queue_actions(), true)) {
                return new WP_REST_Response(array('success' => false, 'message' => __('Unsupported dashboard processing action.', 'ultracache')), 400);
            }

            $params = $this->normalize_action_params($request->get_param('params'));
            $stored_params = $this->scrub_action_queue_payload($params, 'params', 0);
            $id = 'ucwp_' . wp_generate_password(18, false, false);
            $now = time();
            $job = array(
                'id'        => $id,
                'action'    => $action,
                'params'    => is_array($stored_params) ? $stored_params : array(),
                'status'    => 'running',
                'message'   => __('Processing dashboard action.', 'ultracache'),
                'createdAt' => $now,
                'startedAt' => $now,
                'updatedAt' => $now,
                'direct'    => true,
            );

            /*
             * Dashboard work actions are intentionally synchronous from 2.56.250.
             *
             * Older builds attempted to enqueue these actions and then run them from
             * a separate worker/polling request protected by a global "heavy" lock.
             * That made simple dashboard buttons such as Flush All Cache appear done
             * while stale purge_all locks/jobs were still blocking later actions.
             *
             * The dashboard UX now treats these as blocking work buttons: click, wait,
             * receive success/failure. The settings debounce queue remains separate
             * and is flushed before the work action is called from the admin UI.
             */
            try {
                $result = $this->run_action_queue_job($action, $params);
                $ok = !empty($result['success']) || !empty($result['skipped']);
                $job['status'] = $ok ? 'done' : 'failed';
                $job['message'] = !empty($result['message']) ? (string) $result['message'] : ($ok ? 'Completed.' : 'Failed.');
                $job['result'] = $this->scrub_action_queue_payload($result, 'result', 0);
            } catch (Throwable $error) {
                $job['status'] = 'failed';
                $job['message'] = $error->getMessage();
            } catch (Exception $error) {
                $job['status'] = 'failed';
                $job['message'] = $error->getMessage();
            }

            $job['finishedAt'] = time();
            $job['updatedAt'] = time();

            $jobs = $this->load_action_jobs();
            $jobs[$id] = $job;
            $this->save_action_jobs($jobs);

            return new WP_REST_Response(array('success' => true, 'job' => $this->scrub_action_queue_payload($job, 'job', 0)), 200);
        }

        public function get_action_job(WP_REST_Request $request)
        {
            $id = sanitize_text_field((string) $request->get_param('id'));
            $jobs = $this->load_action_jobs();
            if (empty($jobs[$id]) || !is_array($jobs[$id])) {
                return new WP_REST_Response(array('success' => false, 'message' => __('Dashboard processing action not found.', 'ultracache')), 404);
            }

            $job = $jobs[$id];
            $status = (string) ($job['status'] ?? 'queued');
            $action = (string) ($job['action'] ?? '');

            if ('queued' === $status) {
                $job['message'] = !empty($job['message']) ? (string) $job['message'] : 'Waiting for dashboard runner.';
                $job['updatedAt'] = time();
                $jobs[$id] = $job;
                $this->save_action_jobs($jobs);
            } elseif ('running' === $status && !empty($job['startedAt']) && (time() - (int) $job['startedAt']) > 300) {
                $job['status'] = 'failed';
                $job['message'] = 'Dashboard processing action timed out.';
                $job['finishedAt'] = time();
                $job['updatedAt'] = time();
                $jobs[$id] = $job;
                $this->save_action_jobs($jobs);
                if ($this->is_heavy_action_queue_action($action)) {
                    $this->release_action_queue_heavy_lock($id);
                }
            }

            return new WP_REST_Response(array('success' => true, 'job' => $this->scrub_action_queue_payload($job, 'job', 0)), 200);
        }

        public function run_action_job_request(WP_REST_Request $request)
        {
            $id = sanitize_text_field((string) $request->get_param('id'));
            $jobs = $this->load_action_jobs();
            if (empty($jobs[$id]) || !is_array($jobs[$id])) {
                return new WP_REST_Response(array('success' => false, 'message' => __('Dashboard processing action not found.', 'ultracache')), 404);
            }

            $job = $jobs[$id];
            $status = (string) ($job['status'] ?? 'queued');
            $action = (string) ($job['action'] ?? '');
            if (!in_array($action, $this->get_allowed_action_queue_actions(), true)) {
                $job['status'] = 'failed';
                $job['message'] = 'Unsupported dashboard processing action.';
                $job['finishedAt'] = time();
                $job['updatedAt'] = time();
                $jobs[$id] = $job;
                $this->save_action_jobs($jobs);
                return new WP_REST_Response(array('success' => true, 'job' => $this->scrub_action_queue_payload($job, 'job', 0)), 200);
            }

            if (in_array($status, array('done', 'failed'), true)) {
                return new WP_REST_Response(array('success' => true, 'job' => $this->scrub_action_queue_payload($job, 'job', 0)), 200);
            }

            if ('running' === $status) {
                return new WP_REST_Response(array('success' => true, 'job' => $this->scrub_action_queue_payload($job, 'job', 0)), 202);
            }

            $lock_acquired = false;
            if ($this->is_heavy_action_queue_action($action)) {
                $active = $this->find_active_heavy_action_job($jobs, $id);
                if (!empty($active)) {
                    $job['status'] = 'failed';
                    $job['message'] = 'Dashboard action already running: ' . (string) ($active['action'] ?? 'unknown') . '.';
                    $job['blockedBy'] = (string) ($active['id'] ?? '');
                    $job['finishedAt'] = time();
                    $job['updatedAt'] = time();
                    $jobs[$id] = $job;
                    $this->save_action_jobs($jobs);
                    return new WP_REST_Response(array('success' => true, 'alreadyRunning' => true, 'job' => $this->scrub_action_queue_payload($active, 'job', 0)), 202);
                }

                if (!$this->acquire_action_queue_heavy_lock($action, $id)) {
                    $job['message'] = 'Waiting for another heavy dashboard action to finish.';
                    $job['updatedAt'] = time();
                    $jobs[$id] = $job;
                    $this->save_action_jobs($jobs);
                    return new WP_REST_Response(array('success' => true, 'job' => $this->scrub_action_queue_payload($job, 'job', 0)), 202);
                }
                $lock_acquired = true;
            }

            $job['status'] = 'running';
            $job['message'] = 'Processing via dashboard runner.';
            $job['startedAt'] = time();
            $job['updatedAt'] = time();
            $jobs[$id] = $job;
            $this->save_action_jobs($jobs);

            try {
                $result = $this->run_action_queue_job($action, is_array($job['params'] ?? null) ? $job['params'] : array());
                $ok = !empty($result['success']) || !empty($result['skipped']);
                $job['status'] = $ok ? 'done' : 'failed';
                $job['message'] = !empty($result['message']) ? (string) $result['message'] : ($ok ? 'Completed.' : 'Failed.');
                $job['result'] = $this->scrub_action_queue_payload($result, 'result', 0);
            } catch (Throwable $error) {
                $job['status'] = 'failed';
                $job['message'] = $error->getMessage();
            } catch (Exception $error) {
                $job['status'] = 'failed';
                $job['message'] = $error->getMessage();
            }

            $job['finishedAt'] = time();
            $job['updatedAt'] = time();
            $jobs = $this->load_action_jobs();
            $jobs[$id] = $job;
            $this->save_action_jobs($jobs);

            if ($lock_acquired) {
                $this->release_action_queue_heavy_lock($id);
            }

            return new WP_REST_Response(array('success' => true, 'job' => $this->scrub_action_queue_payload($job, 'job', 0)), 200);
        }

        private function run_action_queue_job($action, array $params)
        {
            try {
                switch ($action) {
                    case 'purge_all':
                        return $this->unwrap_rest_payload($this->purge_all());
                    case 'object_cache_flush':
                        $flush_request = new WP_REST_Request('POST', '/');
                        foreach ($params as $key => $value) {
                            $flush_request->set_param($key, $value);
                        }
                        return $this->unwrap_rest_payload($this->object_cache_flush($flush_request));
                    case 'object_cache_full_count':
                        return $this->unwrap_rest_payload($this->object_cache_full_count());
                    case 'warm_frontpage_html':
                        return $this->unwrap_rest_payload($this->warm_frontpage_html());
                    case 'warm_frontpage_html_css':
                        return $this->unwrap_rest_payload($this->warm_frontpage_html_css());
                    case 'varnish_test':
                        return $this->unwrap_rest_payload($this->varnish_test());
                    case 'varnish_flush_all':
                        return $this->unwrap_rest_payload($this->varnish_flush_all());
                    case 'opcache_flush':
                        return $this->unwrap_rest_payload($this->opcache_flush());
                    case 'apcu_flush':
                        return $this->unwrap_rest_payload($this->apcu_flush());
                    case 'litespeed_flush':
                        return $this->unwrap_rest_payload($this->litespeed_flush());
                    case 'nginx_flush':
                        return $this->unwrap_rest_payload($this->nginx_flush());
                    case 'external_caches_redetect':
                        return $this->unwrap_rest_payload($this->external_caches_redetect());
                    case 'redis_test':
                        $redis_request = new WP_REST_Request('POST', '/');
                        foreach ($params as $key => $value) {
                            $redis_request->set_param($key, $value);
                        }
                        return $this->unwrap_rest_payload($this->redis_test($redis_request));
                    case 'google_fonts_rebuild_cache':
                        $engine = $this->get_engine();
                        if (!$engine || !method_exists($engine, 'rebuild_google_fonts_cache_from_scan_urls')) {
                            return array('success' => false, 'message' => __('Google Fonts rebuild helper is not available.', 'ultracache'));
                        }
                        $result = $engine->rebuild_google_fonts_cache_from_scan_urls(array(), !empty($params['clear']), 'dashboard');
                        if (is_array($result) && class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics')) {
                            $result['diagnostics'] = Ultra_Cache_WP::get_dashboard_diagnostics();
                        }
                        return $result;
                    case 'performance_profile':
                        return $this->run_performance_profile_job($params);
                }
            } catch (Throwable $error) {
                return array('success' => false, 'message' => $error->getMessage());
            } catch (Exception $error) {
                return array('success' => false, 'message' => $error->getMessage());
            }

            return array('success' => false, 'message' => __('Unsupported dashboard processing action.', 'ultracache'));
        }

    }
}
