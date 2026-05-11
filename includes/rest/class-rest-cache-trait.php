<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!trait_exists('Ultra_Cache_Rest_Cache_Trait')) {
    trait Ultra_Cache_Rest_Cache_Trait
    {
        public function get_stats()
        {
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'are_cache_stats_enabled') && !Ultra_Cache_WP::are_cache_stats_enabled()) {
                $payload = method_exists('Ultra_Cache_WP', 'get_cache_stats_disabled_payload')
                    ? Ultra_Cache_WP::get_cache_stats_disabled_payload('rest_stats_disabled')
                    : array(
                        'success' => true,
                        'enabled' => false,
                        'disabled' => true,
                        'message' => 'Cache stats are disabled.',
                        'impact' => 'off',
                        'timestamp' => time(),
                    );
                return new WP_REST_Response($payload, 200);
            }

            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_stats_snapshot')) {
                // Stats are ON, so the REST refresh must return a real dashboard snapshot.
                // The hard zero-impact path is handled above when Count cache stats is OFF.
                return new WP_REST_Response(Ultra_Cache_WP::get_dashboard_stats_snapshot(20, true), 200);
            }

            return new WP_REST_Response(array('success' => true), 200);
        }

        public function purge_all()
        {
            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'purge_all')) {
                return new WP_REST_Response(
                    array(
                        'success' => false,
                        'message' => 'Cache engine not available.',
                    ),
                    500
                );
            }

            $success = (bool) $engine->purge_all();
            $response = array('success' => $success);
            if (!$success) {
                $response['message'] = 'Flush All Cache is already running or the purge lock could not be acquired.';
            }

            if ($success && class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'maybe_start_cron_warmup_after_purge')) {
                $response['cronWarm'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_cron_warm_status') ? Ultra_Cache_WP::get_cron_warm_status() : array();
            }

            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_engine_stats')) {
                $response['stats'] = Ultra_Cache_WP::get_engine_stats();
            }
            if ($success && class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'maybe_flush_external_caches_after_purge')) {
                $response['externalFlush'] = Ultra_Cache_WP::maybe_flush_external_caches_after_purge();
            }
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics')) {
                $response['diagnostics'] = Ultra_Cache_WP::get_dashboard_diagnostics();
            }
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_external_cache_detection')) {
                $response['externalCaches'] = Ultra_Cache_WP::get_external_cache_detection(false);
            }

            return new WP_REST_Response($response, $success ? 200 : 500);
        }

        public function get_settings()
        {
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_settings')) {
                return new WP_REST_Response(Ultra_Cache_WP::get_dashboard_settings_for_client(), 200);
            }

            $settings = get_option(UCWP_SETTINGS_KEY, array());
            return new WP_REST_Response(is_array($settings) ? array_diff_key($settings, array_flip(array('redisPassword', 'varnishCliKey'))) : array(), 200);
        }

        private function request_may_mutate_files(WP_REST_Request $request, array $current)
        {
            $file_mutating_keys = array(
                'pageCacheEnabled',
                'objectCacheEnabled',
                'objectCacheBackend',
                'objectCacheFallbackBackend',
                'browserCacheRulesEnabled',
            );

            foreach ($file_mutating_keys as $key) {
                if (null !== $request->get_param($key)) {
                    return true;
                }

                if (!empty($current[$key]) && in_array($key, array('pageCacheEnabled', 'objectCacheEnabled', 'browserCacheRulesEnabled'), true)) {
                    return true;
                }
            }

            foreach (array('redisPassword', 'varnishCliKey') as $secret_key) {
                if (null !== $request->get_param($secret_key) && '' !== trim((string) $request->get_param($secret_key))) {
                    return true;
                }
            }

            return false;
        }

        private function file_mutation_forbidden_response()
        {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'ucwp_file_mutation_forbidden',
                    'message' => 'This UltraCache action changes plugin drop-ins, wp-config.php, .htaccess, runtime secrets, or plugin activation state. It requires a full administrator with plugin activation permissions.',
                ),
                403
            );
        }

        public function update_settings(WP_REST_Request $request)
        {
            $allowed_keys = array_keys($this->get_settings_update_args());

            $current = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_settings')
                ? Ultra_Cache_WP::get_dashboard_settings()
                : (array) get_option(UCWP_SETTINGS_KEY, array());

            if ($this->request_may_mutate_files($request, is_array($current) ? $current : array()) && !$this->check_file_mutation_permission($request)) {
                return $this->file_mutation_forbidden_response();
            }

            foreach ($allowed_keys as $key) {
                if (null !== $request->get_param($key)) {
                    $current[$key] = $request->get_param($key);
                }
            }
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'persist_dashboard_settings')) {
                $response = Ultra_Cache_WP::persist_dashboard_settings($current);
                if (is_wp_error($response)) {
                    return new WP_REST_Response(array('success' => false, 'message' => $response->get_error_message()), 500);
                }

                if (!empty($response['settings']) && class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_settings_for_client')) {
                    $response['settings'] = Ultra_Cache_WP::get_dashboard_settings_for_client();
                }

                return new WP_REST_Response($response, 200);
            }

            update_option(UCWP_SETTINGS_KEY, $current);
            $client_settings = is_array($current) ? array_diff_key($current, array_flip(array('redisPassword', 'varnishCliKey'))) : array();
            return new WP_REST_Response(array('success' => true, 'settings' => $client_settings), 200);
        }


        public function delete_all_plugin_data(WP_REST_Request $request)
        {
            $confirmation = strtoupper(trim((string) $request->get_param('confirmation')));
            if ('DELETE' !== $confirmation) {
                return new WP_REST_Response(
                    array(
                        'success' => false,
                        'message' => 'Confirmation failed. Type DELETE to remove UltraCache data and deactivate the plugin.',
                    ),
                    400
                );
            }

            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'delete_all_plugin_data_and_deactivate')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cleanup helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::delete_all_plugin_data_and_deactivate();
            if (is_wp_error($result)) {
                return new WP_REST_Response(array('success' => false, 'message' => $result->get_error_message()), 500);
            }

            return new WP_REST_Response($result, 200);
        }

        public function cron_warm_start()
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'start_cron_warmup_queue')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cron warm helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::start_cron_warmup_queue('manual', false);
            if (!empty($result['state'])) {
                $result['diagnostics'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics') ? Ultra_Cache_WP::get_dashboard_diagnostics() : array();
                $result['stats'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_engine_stats') ? Ultra_Cache_WP::get_engine_stats() : array();
            }
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function cron_warm_stop()
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'stop_cron_warmup_queue')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cron warm helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::stop_cron_warmup_queue('manual');
            $result['diagnostics'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics') ? Ultra_Cache_WP::get_dashboard_diagnostics() : array();
            $result['stats'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_engine_stats') ? Ultra_Cache_WP::get_engine_stats() : array();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function cron_warm_tick(WP_REST_Request $request)
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'run_cron_warm_tick')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cron warm helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::run_cron_warm_tick(array(
                'invokedBy' => 'rest',
                'pagesPerMinute' => null !== $request->get_param('pagesPerMinute') ? absint($request->get_param('pagesPerMinute')) : null,
            ));
            $result['diagnostics'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics') ? Ultra_Cache_WP::get_dashboard_diagnostics() : array();
            $result['stats'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_engine_stats') ? Ultra_Cache_WP::get_engine_stats() : array();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }


        public function varnish_test()
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'varnish_test_connection')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Varnish helper not available.'), 500);
            }

            if (method_exists('Ultra_Cache_WP', 'reset_settings_cache')) {
                Ultra_Cache_WP::reset_settings_cache();
            }
            $result = Ultra_Cache_WP::varnish_test_connection();
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics')) {
                $result['diagnostics'] = Ultra_Cache_WP::get_dashboard_diagnostics();
            }
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_settings_for_client')) {
                $result['settings'] = Ultra_Cache_WP::get_dashboard_settings_for_client();
            }
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_engine_stats')) {
                $result['stats'] = Ultra_Cache_WP::get_engine_stats();
            }

            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function varnish_flush_all()
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'varnish_flush_all_current_host')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Varnish helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::varnish_flush_all_current_host();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function opcache_flush()
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'flush_opcache')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'OPcache helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::flush_opcache();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function apcu_flush()
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'flush_apcu')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'APCu helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::flush_apcu();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function external_caches_redetect()
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'redetect_external_caches')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'External cache detection helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::redetect_external_caches();
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_engine_stats')) {
                $result['stats'] = Ultra_Cache_WP::get_engine_stats();
            }
            return new WP_REST_Response($result, 200);
        }

        public function litespeed_flush()
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'flush_litespeed_cache')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'LiteSpeed Cache helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::flush_litespeed_cache();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function nginx_flush()
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'flush_nginx_cache')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Nginx Cache helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::flush_nginx_cache();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function redis_test(WP_REST_Request $request)
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'test_redis_connection')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Redis helper not available.'), 500);
            }

            $settings = array();
            foreach (array('redisHost', 'redisPort', 'redisUsername', 'redisPassword', 'redisDatabase', 'redisPrefix', 'redisUseTls', 'redisPersistent', 'redisConnectTimeoutMs', 'redisReadTimeoutMs') as $key) {
                if (null !== $request->get_param($key)) {
                    $settings[$key] = $request->get_param($key);
                }
            }

            if (array_key_exists('redisPassword', $settings) && '' === trim((string) $settings['redisPassword']) && !empty($request->get_param('redisPasswordConfigured'))) {
                unset($settings['redisPassword']);
            }

            $result = Ultra_Cache_WP::test_redis_connection($settings);
            $status = !empty($result['blocked']) ? 400 : (!empty($result['success']) ? 200 : 500);
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics')) {
                $result['diagnostics'] = Ultra_Cache_WP::get_dashboard_diagnostics();
            }
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_settings_for_client')) {
                $result['settings'] = Ultra_Cache_WP::get_dashboard_settings_for_client();
            }
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_engine_stats')) {
                $result['stats'] = Ultra_Cache_WP::get_engine_stats();
            }

            return new WP_REST_Response($result, $status);
        }

        public function object_cache_backend_test(WP_REST_Request $request)
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'test_object_cache_backend')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Object cache backend test helper not available.'), 500);
            }

            $backend = sanitize_key((string) $request->get_param('backend'));
            if (!in_array($backend, array('redis', 'apcu', 'disk', 'active', 'selected'), true)) {
                $backend = 'selected';
            }

            $settings = array();
            foreach (array('redisHost', 'redisPort', 'redisUsername', 'redisPassword', 'redisDatabase', 'redisPrefix', 'redisUseTls', 'redisPersistent', 'redisConnectTimeoutMs', 'redisReadTimeoutMs') as $key) {
                if (null !== $request->get_param($key)) {
                    $settings[$key] = $request->get_param($key);
                }
            }

            if (array_key_exists('redisPassword', $settings) && '' === trim((string) $settings['redisPassword']) && !empty($request->get_param('redisPasswordConfigured'))) {
                unset($settings['redisPassword']);
            }

            $profile_probe = filter_var($request->get_param('profileProbe'), FILTER_VALIDATE_BOOLEAN)
                || filter_var($request->get_param('skipPayloadProbe'), FILTER_VALIDATE_BOOLEAN);

            $result = Ultra_Cache_WP::test_object_cache_backend($backend, $settings);
            $result['profileProbe'] = (bool) $profile_probe;

            // Profile auto-selection needs backend availability only. Do not mix the
            // runtime object payload probe into that decision, because the active
            // runtime backend may still be the previous setting until the profile is
            // saved and WordPress bootstraps again. Manual backend tests keep the
            // payload probe so users can explicitly verify object payload storage.
            if (!$profile_probe && !empty($result['success']) && class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'test_runtime_object_cache_payloads')) {
                $payload_probe = Ultra_Cache_Object_Cache_Manager::test_runtime_object_cache_payloads();
                $result['payloadProbe'] = is_array($payload_probe) ? $payload_probe : array();
                if (empty($payload_probe['success'])) {
                    $result['success'] = false;
                    $result['message'] = !empty($payload_probe['message']) ? (string) $payload_probe['message'] : 'Object cache payload probe failed.';
                } elseif (!empty($payload_probe['message'])) {
                    $result['message'] = trim((string) ($result['message'] ?? '') . ' ' . (string) $payload_probe['message']);
                }
            }
            $status = !empty($result['blocked']) ? 400 : (!empty($result['success']) ? 200 : 500);
            if ($profile_probe && empty($result['blocked'])) {
                // Profile auto-setup treats a determinate unavailable backend as a
                // normal probe result, not as a browser-level server failure.
                // Manual backend tests keep strict HTTP status semantics.
                $status = 200;
            }
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics')) {
                $result['diagnostics'] = Ultra_Cache_WP::get_dashboard_diagnostics();
            }
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_settings_for_client')) {
                $result['settings'] = Ultra_Cache_WP::get_dashboard_settings_for_client();
            }
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_engine_stats')) {
                $result['stats'] = Ultra_Cache_WP::get_engine_stats();
            }

            return new WP_REST_Response($result, $status);
        }

        public function object_cache_flush(WP_REST_Request $request = null)
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'flush_object_cache')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Object cache helper not available.'), 500);
            }

            $backend = $request instanceof WP_REST_Request ? sanitize_key((string) $request->get_param('backend')) : 'active';
            if (!in_array($backend, array('redis', 'apcu', 'disk', 'active', 'selected'), true)) {
                $backend = 'active';
            }

            $result = Ultra_Cache_WP::flush_object_cache($backend);
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }


        public function remove_conflicting_cache_dropins()
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'remove_conflicting_cache_dropins')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cache helper cleanup is not available.'), 500);
            }

            $result = Ultra_Cache_WP::remove_conflicting_cache_dropins();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }


        public function object_cache_full_count()
        {
            $stats = $this->resolve_engine_stats(true);

            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Full object-cache count completed.',
                'stats' => $stats,
            ), 200);
        }


        private function is_dashboard_setting_enabled($key)
        {
            $settings = defined('UCWP_SETTINGS_KEY') ? get_option(UCWP_SETTINGS_KEY, array()) : array();
            return is_array($settings) && !empty($settings[$key]);
        }

        private function guard_page_cache_enabled()
        {
            if (!$this->is_dashboard_setting_enabled('pageCacheEnabled')) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Please enable Page Caching first or select a profile before warming cache.',
                ), 400);
            }

            return null;
        }

        private function guard_css_bundle_enabled()
        {
            if (!$this->is_dashboard_setting_enabled('homepageCssBundleEnabled')) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Please enable CSS Bundling before using CSS bundle actions.',
                ), 400);
            }

            return null;
        }

        public function get_urls(WP_REST_Request $request)
        {
            $engine = $this->get_engine();
            $offset = max(0, absint($request->get_param('offset')));
            $cursor = (string) $request->get_param('cursor');
            $limit = max(1, min(500, absint($request->get_param('limit')) ?: 100));
            $scope = sanitize_text_field((string) $request->get_param('scope'));

            if (!$engine || !method_exists($engine, 'get_crawl_urls')) {
                return new WP_REST_Response($this->format_batch_response(array(), 0, $offset, $limit), 200);
            }

            if (method_exists($engine, 'get_crawl_urls_cursor_batch')) {
                return new WP_REST_Response($engine->get_crawl_urls_cursor_batch($cursor, $limit, $scope), 200);
            }

            if (method_exists($engine, 'get_crawl_urls_batch')) {
                return new WP_REST_Response($engine->get_crawl_urls_batch($offset, $limit, $scope), 200);
            }

            $all_urls = (array) $engine->get_crawl_urls($scope);
            return new WP_REST_Response($this->format_batch_response($all_urls, count($all_urls), $offset, $limit), 200);
        }

        public function crawl_page(WP_REST_Request $request)
        {
            $url = esc_url_raw((string) $request->get_param('url'));
            if ('' === $url) {
                return new WP_REST_Response(array('success' => false, 'message' => 'No URL provided.'), 400);
            }

            $guard = $this->guard_page_cache_enabled();
            if ($guard instanceof WP_REST_Response) {
                return $guard;
            }

            if ((bool) $request->get_param('buildCssBundle')) {
                $guard = $this->guard_css_bundle_enabled();
                if ($guard instanceof WP_REST_Response) {
                    return $guard;
                }
            }

            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'warm_url') || !method_exists($engine, 'is_cacheable_local_url')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cache engine not available.'), 500);
            }

            if (!$engine->is_cacheable_local_url($url)) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Only local site URLs can be crawled.'), 400);
            }

            $result = $engine->warm_url($url, array('build_css_bundle' => (bool) $request->get_param('buildCssBundle')));
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function inspect_url(WP_REST_Request $request)
        {
            $url = (string) $request->get_param('url');
            if ('' === trim($url)) {
                return new WP_REST_Response(array('success' => false, 'message' => 'No URL provided.'), 400);
            }

            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'inspect_url')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cache engine not available.'), 500);
            }

            if (!method_exists($engine, 'is_cacheable_local_url') || !$engine->is_cacheable_local_url($url)) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Only local site URLs can be inspected.'), 400);
            }

            return new WP_REST_Response($engine->inspect_url($url), 200);
        }

        public function build_frontpage_css()
        {
            $guard = $this->guard_css_bundle_enabled();
            if ($guard instanceof WP_REST_Response) {
                return $guard;
            }

            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'build_frontpage_css_bundle')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cache engine not available.'), 500);
            }

            $result = $engine->build_frontpage_css_bundle();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : (!empty($result['skipped']) ? 200 : 500));
        }

        public function warm_frontpage_html()
        {
            $guard = $this->guard_page_cache_enabled();
            if ($guard instanceof WP_REST_Response) {
                return $guard;
            }

            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'warm_frontpage_html')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cache engine not available.'), 500);
            }

            $result = $engine->warm_frontpage_html();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function warm_frontpage_html_css()
        {
            $guard = $this->guard_page_cache_enabled();
            if ($guard instanceof WP_REST_Response) {
                return $guard;
            }

            $guard = $this->guard_css_bundle_enabled();
            if ($guard instanceof WP_REST_Response) {
                return $guard;
            }

            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'warm_frontpage_html_with_css')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cache engine not available.'), 500);
            }

            $result = $engine->warm_frontpage_html_with_css();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : (!empty($result['skipped']) ? 200 : 500));
        }

    }
}
