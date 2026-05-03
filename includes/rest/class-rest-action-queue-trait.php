<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!trait_exists('Ultra_Cache_Rest_Action_Queue_Trait')) {
    trait Ultra_Cache_Rest_Action_Queue_Trait
    {
        private function get_action_queue_option_key()
        {
            return defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY . '_action_jobs' : 'ucwp_settings_action_jobs';
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

        private function get_action_queue_lock_option_key()
        {
            return defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY . '_action_queue_heavy_lock' : 'ucwp_settings_action_queue_heavy_lock';
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

        private function load_action_jobs()
        {
            $jobs = get_option($this->get_action_queue_option_key(), array());
            $jobs = is_array($jobs) ? $jobs : array();
            $normalized = $this->normalize_action_jobs($jobs);
            if ($normalized !== $jobs) {
                update_option($this->get_action_queue_option_key(), $normalized, false);
            }
            return $normalized;
        }

        private function save_action_jobs(array $jobs)
        {
            $normalized = $this->normalize_action_jobs($jobs);
            update_option($this->get_action_queue_option_key(), $this->scrub_action_jobs_for_storage($normalized), false);
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

        private function acquire_action_queue_heavy_lock($action, $job_id)
        {
            $action = sanitize_key((string) $action);
            $job_id = sanitize_text_field((string) $job_id);
            $now = time();
            $key = $this->get_action_queue_lock_option_key();
            $payload = array(
                'action' => $action,
                'jobId'  => $job_id,
                'time'   => $now,
            );

            if (add_option($key, $payload, '', false)) {
                return true;
            }

            $existing = get_option($key, array());
            $existing_time = is_array($existing) ? (int) ($existing['time'] ?? 0) : 0;
            if ($existing_time > 0 && ($now - $existing_time) > $this->get_action_queue_stale_seconds()) {
                delete_option($key);
                return add_option($key, $payload, '', false);
            }

            return false;
        }

        private function release_action_queue_heavy_lock($job_id)
        {
            $key = $this->get_action_queue_lock_option_key();
            $existing = get_option($key, array());
            if (is_array($existing) && (string) ($existing['jobId'] ?? '') === (string) $job_id) {
                delete_option($key);
            }
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
                return new WP_REST_Response(array('success' => false, 'message' => 'Unsupported dashboard processing action.'), 400);
            }

            $params = $this->normalize_action_params($request->get_param('params'));
            $stored_params = $this->scrub_action_queue_payload($params, 'params', 0);
            $id = 'ucwp_' . wp_generate_password(18, false, false);
            $now = time();
            $job = array(
                'id'        => $id,
                'action'    => $action,
                'params'    => is_array($stored_params) ? $stored_params : array(),
                'status'    => 'queued',
                'message'   => 'Waiting for dashboard processing.',
                'createdAt' => $now,
                'updatedAt' => $now,
            );

            $jobs = $this->load_action_jobs();

            if ('redis_test' === $action) {
                $job['status'] = 'running';
                $job['message'] = 'Testing Redis connection.';
                $job['startedAt'] = $now;
                $job['updatedAt'] = $now;

                $result = $this->run_action_queue_job($action, $params);
                $ok = !empty($result['success']) || !empty($result['skipped']);
                $job['status'] = $ok ? 'done' : 'failed';
                $job['message'] = !empty($result['message']) ? (string) $result['message'] : ($ok ? 'Completed.' : 'Failed.');
                $job['result'] = $this->scrub_action_queue_payload($result, 'result', 0);
                $job['finishedAt'] = time();
                $job['updatedAt'] = time();
                $jobs[$id] = $job;
                $this->save_action_jobs($jobs);

                return new WP_REST_Response(array('success' => true, 'job' => $this->scrub_action_queue_payload($job, 'job', 0)), 202);
            }

            if ($this->is_heavy_action_queue_action($action)) {
                $active = $this->find_active_heavy_action_job($jobs);
                if (!empty($active)) {
                    $job['status'] = 'failed';
                    $job['message'] = 'Another heavy dashboard action is already running: ' . (string) ($active['action'] ?? 'unknown') . '.';
                    $job['finishedAt'] = $now;
                    $job['updatedAt'] = $now;
                    $jobs[$id] = $job;
                    $this->save_action_jobs($jobs);

                    return new WP_REST_Response(array('success' => true, 'job' => $this->scrub_action_queue_payload($job, 'job', 0)), 202);
                }
            }
            $jobs[$id] = $job;
            $this->save_action_jobs($jobs);

            return new WP_REST_Response(array('success' => true, 'job' => $this->scrub_action_queue_payload($job, 'job', 0)), 202);
        }

        public function get_action_job(WP_REST_Request $request)
        {
            $id = sanitize_text_field((string) $request->get_param('id'));
            $jobs = $this->load_action_jobs();
            if (empty($jobs[$id]) || !is_array($jobs[$id])) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Dashboard processing action not found.'), 404);
            }

            $job = $jobs[$id];
            $status = (string) ($job['status'] ?? 'queued');
            if ('queued' === $status) {
                $action = (string) ($job['action'] ?? '');
                if ($this->is_heavy_action_queue_action($action)) {
                    $active = $this->find_active_heavy_action_job($jobs, $id);
                    if (!empty($active)) {
                        $job['status'] = 'failed';
                        $job['message'] = 'Another heavy dashboard action is already running: ' . (string) ($active['action'] ?? 'unknown') . '.';
                        $job['finishedAt'] = time();
                        $job['updatedAt'] = time();
                        $jobs[$id] = $job;
                        $this->save_action_jobs($jobs);
                        return new WP_REST_Response(array('success' => true, 'job' => $this->scrub_action_queue_payload($job, 'job', 0)), 200);
                    }
                    if (!$this->acquire_action_queue_heavy_lock($action, $id)) {
                        $job['status'] = 'failed';
                        $job['message'] = 'Another heavy dashboard action lock is active. Try again shortly.';
                        $job['finishedAt'] = time();
                        $job['updatedAt'] = time();
                        $jobs[$id] = $job;
                        $this->save_action_jobs($jobs);
                        return new WP_REST_Response(array('success' => true, 'job' => $this->scrub_action_queue_payload($job, 'job', 0)), 200);
                    }
                }

                $job['status'] = 'running';
                $job['message'] = 'Processing via dashboard.';
                $job['startedAt'] = time();
                $job['updatedAt'] = time();
                $jobs[$id] = $job;
                $this->save_action_jobs($jobs);

                try {
                    $result = $this->run_action_queue_job($action, is_array($job['params'] ?? null) ? $job['params'] : array());
                } finally {
                    if ($this->is_heavy_action_queue_action($action)) {
                        $this->release_action_queue_heavy_lock($id);
                    }
                }
                $ok = !empty($result['success']) || !empty($result['skipped']);
                $job['status'] = $ok ? 'done' : 'failed';
                $job['message'] = !empty($result['message']) ? (string) $result['message'] : ($ok ? 'Completed.' : 'Failed.');
                $job['result'] = $this->scrub_action_queue_payload($result, 'result', 0);
                $job['finishedAt'] = time();
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
                        return $this->unwrap_rest_payload($this->object_cache_flush());
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
                    case 'redis_test':
                        $redis_request = new WP_REST_Request('POST', '/');
                        foreach ($params as $key => $value) {
                            $redis_request->set_param($key, $value);
                        }
                        return $this->unwrap_rest_payload($this->redis_test($redis_request));
                    case 'google_fonts_rebuild_cache':
                        $engine = $this->get_engine();
                        if (!$engine || !method_exists($engine, 'rebuild_google_fonts_cache_from_scan_urls')) {
                            return array('success' => false, 'message' => 'Google Fonts rebuild helper is not available.');
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

            return array('success' => false, 'message' => 'Unsupported dashboard processing action.');
        }

    }
}
