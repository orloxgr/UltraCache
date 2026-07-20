<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Profiler_Queue_Trait
{
    private function runtime_js_diagnostic_queue_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'ultracache_js_diagnostic_jobs';
    }

    private function runtime_js_diagnostic_queue_db_version()
    {
        return '1';
    }

    private function runtime_js_diagnostic_queue_db_version_option_key()
    {
        return 'ultracache_js_diagnostic_queue_db_version';
    }

    private function ensure_runtime_js_diagnostic_queue_table()
    {
        global $wpdb;
        if (!($wpdb instanceof wpdb)) {
            return false;
        }

        $table = $this->runtime_js_diagnostic_queue_table_name();
        $version = (string) get_option($this->runtime_js_diagnostic_queue_db_version_option_key(), '');
        if ($version === $this->runtime_js_diagnostic_queue_db_version()) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache-owned diagnostic queue schema check.
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ((string) $found === (string) $table) {
                return true;
            }
        }

        if (!ultracache_require_wordpress_admin_include('upgrade.php', 'dbDelta')) {
            return false;
        }
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            job_id varchar(64) NOT NULL,
            scan_type varchar(30) NOT NULL DEFAULT 'runtime',
            status varchar(20) NOT NULL DEFAULT 'queued',
            target_url text NULL,
            scan_context varchar(30) NOT NULL DEFAULT 'anonymous',
            message text NULL,
            console_text longtext NULL,
            payload longtext NULL,
            result longtext NULL,
            progress_current int(10) unsigned NOT NULL DEFAULT 0,
            progress_total int(10) unsigned NOT NULL DEFAULT 100,
            created_at bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
            started_at bigint(20) unsigned NOT NULL DEFAULT 0,
            finished_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (job_id),
            KEY status_updated (status, updated_at),
            KEY scan_type_status (scan_type, status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta($sql);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache-owned diagnostic queue schema check immediately after dbDelta.
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ((string) $found === (string) $table) {
            update_option($this->runtime_js_diagnostic_queue_db_version_option_key(), $this->runtime_js_diagnostic_queue_db_version(), false);
            return true;
        }

        return false;
    }

    private function runtime_js_diagnostic_queue_new_job_id()
    {
        $random = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('jsdq_', true);
        return 'jsdq_' . substr(str_replace('-', '', sanitize_key((string) $random)), 0, 40);
    }

    private function runtime_js_diagnostic_queue_buckets_from_scan(array $scan)
    {
        $suggestions = isset($scan['suggestions']) && is_array($scan['suggestions']) ? $scan['suggestions'] : array();
        $buckets = array(
            'confirmedErrorFixes' => array(),
            'suggestions'         => array(),
            'reviewOnly'          => array(),
            'alreadyListed'       => array(),
            'ignored'             => array(),
        );

        foreach ($suggestions as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!empty($item['ignored']) || (isset($item['confidence']) && 'ignored' === strtolower((string) $item['confidence']))) {
                $buckets['ignored'][] = $item;
                continue;
            }
            if (!empty($item['alreadyExcluded'])) {
                $buckets['alreadyListed'][] = $item;
                continue;
            }
            if (empty($item['appendable'])) {
                $buckets['reviewOnly'][] = $item;
                continue;
            }
            if (isset($scan['source']) && 'browser-runtime' === (string) $scan['source']) {
                $buckets['confirmedErrorFixes'][] = $item;
            } elseif (isset($item['category']) && in_array((string) $item['category'], array('browser-runtime-error', 'appendable-fix'), true)) {
                $buckets['confirmedErrorFixes'][] = $item;
            } else {
                $buckets['suggestions'][] = $item;
            }
        }

        return $buckets;
    }

    private function runtime_js_diagnostic_queue_result_from_scan(array $scan, array $report = array())
    {
        $buckets = $this->runtime_js_diagnostic_queue_buckets_from_scan($scan);
        return array(
            'available'            => true,
            'dashboardScan'        => $scan,
            'report'               => $report,
            'buckets'              => $buckets,
            'bucketCounts'         => array(
                'confirmedErrorFixes' => count($buckets['confirmedErrorFixes']),
                'suggestions'         => count($buckets['suggestions']),
                'reviewOnly'          => count($buckets['reviewOnly']),
                'alreadyListed'       => count($buckets['alreadyListed']),
                'ignored'             => count($buckets['ignored']),
            ),
            'runtimeErrorCount'    => isset($scan['runtimeErrorCount']) ? (int) $scan['runtimeErrorCount'] : (isset($report['errorCount']) ? (int) $report['errorCount'] : 0),
            'resourceErrorCount'   => isset($scan['resourceErrorCount']) ? (int) $scan['resourceErrorCount'] : 0,
            'suggestionCount'      => isset($scan['suggestionCount']) ? (int) $scan['suggestionCount'] : (isset($scan['suggestion_count']) ? (int) $scan['suggestion_count'] : 0),
            'missingCount'         => isset($scan['missingCount']) ? (int) $scan['missingCount'] : (isset($scan['missing_count']) ? (int) $scan['missing_count'] : 0),
            'alreadyExcludedCount' => isset($scan['alreadyExcludedCount']) ? (int) $scan['alreadyExcludedCount'] : (isset($scan['already_excluded_count']) ? (int) $scan['already_excluded_count'] : 0),
        );
    }

    private function runtime_js_diagnostic_queue_cache_group()
    {
        return 'ultracache_js_diagnostic_queue';
    }

    private function runtime_js_diagnostic_queue_job_cache_key($job_id)
    {
        return 'ultracache_runtime_js_diagnostic_job_' . md5(sanitize_text_field((string) $job_id));
    }

    private function runtime_js_diagnostic_queue_latest_cache_key()
    {
        return 'ultracache_runtime_js_diagnostic_latest_job';
    }

    private function runtime_js_diagnostic_queue_delete_cache($job_id = '')
    {
        $group = $this->runtime_js_diagnostic_queue_cache_group();
        wp_cache_delete($this->runtime_js_diagnostic_queue_latest_cache_key(), $group);
        $job_id = sanitize_text_field((string) $job_id);
        if ('' !== $job_id) {
            wp_cache_delete($this->runtime_js_diagnostic_queue_job_cache_key($job_id), $group);
        }
    }

    private function runtime_js_diagnostic_queue_row_to_job(array $row)
    {
        $result = array();
        if (!empty($row['result'])) {
            $decoded = maybe_unserialize($row['result']);
            if (is_array($decoded)) {
                $result = $decoded;
            }
        }
        $payload = array();
        if (!empty($row['payload'])) {
            $decoded_payload = maybe_unserialize($row['payload']);
            if (is_array($decoded_payload)) {
                $payload = $decoded_payload;
            }
        }

        return array(
            'id'              => sanitize_text_field((string) ($row['job_id'] ?? '')),
            'scanType'        => sanitize_key((string) ($row['scan_type'] ?? 'runtime')),
            'status'          => sanitize_key((string) ($row['status'] ?? 'queued')),
            'targetUrl'       => isset($row['target_url']) ? esc_url_raw((string) $row['target_url']) : '',
            'scanContext'     => isset($row['scan_context']) && 'logged-in' === (string) $row['scan_context'] ? 'logged-in' : 'anonymous',
            'message'         => isset($row['message']) ? sanitize_text_field((string) $row['message']) : '',
            'progressCurrent' => (int) ($row['progress_current'] ?? 0),
            'progressTotal'   => max(1, (int) ($row['progress_total'] ?? 100)),
            'createdAt'       => (int) ($row['created_at'] ?? 0),
            'updatedAt'       => (int) ($row['updated_at'] ?? 0),
            'startedAt'       => (int) ($row['started_at'] ?? 0),
            'finishedAt'      => (int) ($row['finished_at'] ?? 0),
            'payload'         => $payload,
            'result'          => $result,
        );
    }

    private function runtime_js_diagnostic_queue_get_job($job_id)
    {
        global $wpdb;
        if (!$this->ensure_runtime_js_diagnostic_queue_table()) {
            return null;
        }
        $job_id = sanitize_text_field((string) $job_id);
        if ('' === $job_id) {
            return null;
        }
        $cache_group = $this->runtime_js_diagnostic_queue_cache_group();
        $cache_key = $this->runtime_js_diagnostic_queue_job_cache_key($job_id);
        $cache_found = false;
        $cached_job = wp_cache_get($cache_key, $cache_group, false, $cache_found);
        if ($cache_found) {
            return is_array($cached_job) ? $cached_job : null;
        }

        $table = $this->runtime_js_diagnostic_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache-owned diagnostic queue row read cached by job id below.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE job_id = %s', $table, $job_id), ARRAY_A);
        $job = is_array($row) ? $this->runtime_js_diagnostic_queue_row_to_job($row) : null;
        wp_cache_set($cache_key, $job, $cache_group, MINUTE_IN_SECONDS);
        return $job;
    }

    private function runtime_js_diagnostic_queue_latest_job()
    {
        global $wpdb;
        if (!$this->ensure_runtime_js_diagnostic_queue_table()) {
            return null;
        }
        $cache_group = $this->runtime_js_diagnostic_queue_cache_group();
        $cache_key = $this->runtime_js_diagnostic_queue_latest_cache_key();
        $cache_found = false;
        $cached_job = wp_cache_get($cache_key, $cache_group, false, $cache_found);
        if ($cache_found) {
            return is_array($cached_job) ? $cached_job : null;
        }

        $table = $this->runtime_js_diagnostic_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache-owned diagnostic queue latest row read cached below.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i ORDER BY updated_at DESC, created_at DESC LIMIT 1', $table), ARRAY_A);
        $job = is_array($row) ? $this->runtime_js_diagnostic_queue_row_to_job($row) : null;
        wp_cache_set($cache_key, $job, $cache_group, MINUTE_IN_SECONDS);
        return $job;
    }

    private function runtime_js_diagnostic_queue_insert_job(array $data)
    {
        global $wpdb;
        if (!$this->ensure_runtime_js_diagnostic_queue_table()) {
            return null;
        }
        $now = time();
        $job_id = $this->runtime_js_diagnostic_queue_new_job_id();
        $table = $this->runtime_js_diagnostic_queue_table_name();
        $row = array(
            'job_id'           => $job_id,
            'scan_type'        => sanitize_key((string) ($data['scan_type'] ?? 'runtime')),
            'status'           => sanitize_key((string) ($data['status'] ?? 'running')),
            'target_url'       => esc_url_raw((string) ($data['target_url'] ?? '')),
            'scan_context'     => isset($data['scan_context']) && 'logged-in' === (string) $data['scan_context'] ? 'logged-in' : 'anonymous',
            'message'          => sanitize_text_field((string) ($data['message'] ?? 'JS diagnostic queue started.')),
            'console_text'     => isset($data['console_text']) ? sanitize_textarea_field((string) $data['console_text']) : '',
            'payload'          => maybe_serialize(isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array()),
            'result'           => maybe_serialize(isset($data['result']) && is_array($data['result']) ? $data['result'] : array()),
            'progress_current' => isset($data['progress_current']) ? absint($data['progress_current']) : 5,
            'progress_total'   => 100,
            'created_at'       => $now,
            'updated_at'       => $now,
            'started_at'       => $now,
            'finished_at'      => isset($data['finished_at']) ? absint($data['finished_at']) : 0,
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache-owned diagnostic queue insert; related queue object-cache entries are invalidated immediately after write.
        $ok = $wpdb->insert($table, $row, array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%d','%d','%d'));
        if (!$ok) {
            return null;
        }
        $this->runtime_js_diagnostic_queue_delete_cache($job_id);
        return $this->runtime_js_diagnostic_queue_get_job($job_id);
    }

    private function runtime_js_diagnostic_queue_update_job($job_id, array $changes)
    {
        global $wpdb;
        if (!$this->ensure_runtime_js_diagnostic_queue_table()) {
            return null;
        }
        $job_id = sanitize_text_field((string) $job_id);
        if ('' === $job_id) {
            return null;
        }
        $row = array('updated_at' => time());
        $formats = array('%d');
        if (isset($changes['status'])) {
            $row['status'] = sanitize_key((string) $changes['status']);
            $formats[] = '%s';
        }
        if (isset($changes['message'])) {
            $row['message'] = sanitize_text_field((string) $changes['message']);
            $formats[] = '%s';
        }
        if (isset($changes['progress_current'])) {
            $row['progress_current'] = absint($changes['progress_current']);
            $formats[] = '%d';
        }
        if (isset($changes['result']) && is_array($changes['result'])) {
            $row['result'] = maybe_serialize($changes['result']);
            $formats[] = '%s';
        }
        if (isset($changes['payload']) && is_array($changes['payload'])) {
            $row['payload'] = maybe_serialize($changes['payload']);
            $formats[] = '%s';
        }
        if (isset($changes['finished_at'])) {
            $row['finished_at'] = absint($changes['finished_at']);
            $formats[] = '%d';
        }
        $table = $this->runtime_js_diagnostic_queue_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache-owned diagnostic queue update; related queue object-cache entries are invalidated immediately after write.
        $wpdb->update($table, $row, array('job_id' => $job_id), $formats, array('%s'));
        $this->runtime_js_diagnostic_queue_delete_cache($job_id);
        return $this->runtime_js_diagnostic_queue_get_job($job_id);
    }

    private function runtime_js_diagnostic_queue_response($job)
    {
        if (!is_array($job)) {
            return array('success' => false, 'message' => __('JS diagnostic queue job not found.', 'ultracache'));
        }
        return array('success' => true, 'jsDiagnosticQueue' => $job);
    }

    public function runtime_js_diagnostic_queue_start(WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();
        $scan_type = sanitize_key((string) ($params['scanType'] ?? $params['type'] ?? 'runtime'));
        if (!in_array($scan_type, array('runtime', 'console'), true)) {
            $scan_type = 'runtime';
        }
        $target_url = isset($params['url']) ? esc_url_raw((string) $params['url']) : home_url('/');
        $scan_context = isset($params['scanContext']) && 'logged-in' === sanitize_key((string) $params['scanContext']) ? 'logged-in' : 'anonymous';
        $console_text = isset($params['text']) ? (string) $params['text'] : '';

        $job = $this->runtime_js_diagnostic_queue_insert_job(array(
            'scan_type'        => $scan_type,
            'status'           => 'running',
            'target_url'       => $target_url,
            'scan_context'     => $scan_context,
            'console_text'     => $console_text,
            'message'          => 'runtime' === $scan_type ? __('Browser runtime JS diagnostic queue started.', 'ultracache') : __('Console JS diagnostic queue started.', 'ultracache'),
            'progress_current' => 10,
            'payload'          => array('url' => $target_url, 'scanContext' => $scan_context),
        ));
        if (!is_array($job)) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Could not create JS diagnostic queue job.', 'ultracache')), 500);
        }

        if ('console' === $scan_type) {
            if ('' === trim($console_text)) {
                $job = $this->runtime_js_diagnostic_queue_update_job($job['id'], array(
                    'status' => 'failed',
                    'message' => __('Console diagnostic queue needs pasted console text.', 'ultracache'),
                    'progress_current' => 100,
                    'finished_at' => time(),
                ));
                return new WP_REST_Response($this->runtime_js_diagnostic_queue_response($job), 400);
            }
            $scripts = $this->runtime_js_scan_fetch_script_inventory_for_url($target_url);
            $errors = $this->runtime_js_scan_console_text_to_errors($console_text);
            $scan = $this->build_runtime_js_scan_suggestions($errors, $scripts);
            $dashboard_scan = array(
                'available'              => true,
                'source'                 => 'console-paste-runtime-engine',
                'runtimeErrorCount'      => count($errors),
                'resourceErrorCount'     => 0,
                'suggestionCount'        => isset($scan['suggestion_count']) ? (int) $scan['suggestion_count'] : 0,
                'missingCount'           => isset($scan['missing_count']) ? (int) $scan['missing_count'] : 0,
                'alreadyExcludedCount'   => isset($scan['already_excluded_count']) ? (int) $scan['already_excluded_count'] : 0,
                'suggestions'            => isset($scan['suggestions']) && is_array($scan['suggestions']) ? array_slice($scan['suggestions'], 0, 80) : array(),
                'errors'                 => array_slice($errors, 0, 40),
                'resourceErrors'         => array(),
                'scannedUrl'             => '' !== $target_url ? $this->runtime_js_scan_sanitize_display_url($target_url) : home_url('/'),
                'scriptInventoryCount'   => count($scripts),
                'scriptInventorySummary' => $this->runtime_js_scan_inventory_summary($scripts),
                'scanContext'            => 'console-paste',
                'completed'              => true,
            );
            $result = $this->runtime_js_diagnostic_queue_result_from_scan($dashboard_scan, array('errors' => $errors, 'scripts' => $scripts));
            $job = $this->runtime_js_diagnostic_queue_update_job($job['id'], array(
                'status' => 'done',
                'message' => __('Console JS diagnostic queue completed.', 'ultracache'),
                'progress_current' => 100,
                'result' => $result,
                'finished_at' => time(),
            ));
        }

        return new WP_REST_Response($this->runtime_js_diagnostic_queue_response($job), 200);
    }

    public function runtime_js_diagnostic_queue_status(WP_REST_Request $request)
    {
        $job_id = sanitize_text_field((string) $request->get_param('jobId'));
        $job = '' !== $job_id ? $this->runtime_js_diagnostic_queue_get_job($job_id) : $this->runtime_js_diagnostic_queue_latest_job();
        if (!is_array($job)) {
            return new WP_REST_Response(array(
                'success' => true,
                'message' => __('No JS diagnostic queue job is stored yet.', 'ultracache'),
                'jsDiagnosticQueue' => null,
            ), 200);
        }
        return new WP_REST_Response($this->runtime_js_diagnostic_queue_response($job), 200);
    }

    private function runtime_js_diagnostic_queue_transition(WP_REST_Request $request, $status, $message)
    {
        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();
        $job_id = sanitize_text_field((string) ($params['jobId'] ?? $request->get_param('jobId')));
        if ('' === $job_id) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Missing JS diagnostic queue job id.', 'ultracache')), 400);
        }
        $changes = array('status' => $status, 'message' => $message);
        if (in_array($status, array('cancelled', 'done', 'failed'), true)) {
            $changes['progress_current'] = 100;
            $changes['finished_at'] = time();
        }
        $job = $this->runtime_js_diagnostic_queue_update_job($job_id, $changes);
        return new WP_REST_Response($this->runtime_js_diagnostic_queue_response($job), is_array($job) ? 200 : 404);
    }

    public function runtime_js_diagnostic_queue_pause(WP_REST_Request $request)
    {
        return $this->runtime_js_diagnostic_queue_transition($request, 'paused', __('JS diagnostic queue paused.', 'ultracache'));
    }

    public function runtime_js_diagnostic_queue_resume(WP_REST_Request $request)
    {
        return $this->runtime_js_diagnostic_queue_transition($request, 'running', __('JS diagnostic queue resumed.', 'ultracache'));
    }

    public function runtime_js_diagnostic_queue_cancel(WP_REST_Request $request)
    {
        return $this->runtime_js_diagnostic_queue_transition($request, 'cancelled', __('JS diagnostic queue cancelled.', 'ultracache'));
    }
}
