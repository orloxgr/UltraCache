<?php
if (!defined('ABSPATH')) {
    exit;
}

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
                    'message' => __('Cache stats are disabled.', 'ultracache'),
                    'impact' => 'off',
                    'timestamp' => time(),
                );
            return new WP_REST_Response($payload, 200);
        }

        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_stats_snapshot')) {
            // Stats are ON, so the REST refresh must return a real dashboard snapshot.
            // The hard zero-impact path is handled above when Count cache stats is OFF.
            return new WP_REST_Response(Ultra_Cache_WP::get_dashboard_stats_snapshot(60, true), 200);
        }

        return new WP_REST_Response(array('success' => true), 200);
    }

    public function refresh_storage_diagnostics()
    {
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Storage diagnostics helper is not available.', 'ultracache')), 500);
        }

        $diagnostics = Ultra_Cache_WP::get_dashboard_diagnostics(true);
        return new WP_REST_Response(array(
            'success' => true,
            'message' => __('Storage diagnostics refreshed.', 'ultracache'),
            'diagnostics' => $diagnostics,
        ), 200);
    }

    public function compression_capabilities()
    {
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'get_html_compression_capability_probe')) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => __('HTML compression capability probe is not available.', 'ultracache'),
                ),
                500
            );
        }

        $capabilities = Ultra_Cache_WP::get_html_compression_capability_probe(true);

        return new WP_REST_Response(
            array(
                'success'      => true,
                'capabilities' => $capabilities,
            ),
            200
        );
    }

    public function purge_all()
    {
        if (!$this->check_purge_all_permission()) {
            return $this->infrastructure_forbidden_response();
        }

        $engine = $this->get_engine();
        if (!$engine || !method_exists($engine, 'purge_all')) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => __('Cache engine not available.', 'ultracache'),
                ),
                500
            );
        }

        $success = (bool) $engine->purge_all();
        $response = array('success' => $success);
        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_warmup_generation')) {
            $response['warmupGeneration'] = Ultra_Cache_WP::get_warmup_generation();
        }
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

    public function update_admin_theme(WP_REST_Request $request)
    {
        $theme = sanitize_key((string) $request->get_param('theme'));
        if (!in_array($theme, array('native', 'ultracache'), true)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('Invalid UltraCache admin theme.', 'ultracache'),
            ), 400);
        }

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('The current administrator could not be resolved.', 'ultracache'),
            ), 400);
        }

        $updated = update_user_meta($user_id, 'ultracache_admin_theme', $theme);
        if (false === $updated && $theme !== (string) get_user_meta($user_id, 'ultracache_admin_theme', true)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('The UltraCache admin theme could not be saved.', 'ultracache'),
            ), 500);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'theme'   => $theme,
        ), 200);
    }

    public function get_settings()
    {
        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_settings')) {
            return new WP_REST_Response(Ultra_Cache_WP::get_dashboard_settings_for_client(), 200);
        }

        $settings = get_option(ULTRACACHE_SETTINGS_KEY, array());
        return new WP_REST_Response(is_array($settings) ? array_diff_key($settings, array_flip(array('redisPassword', 'varnishCliKey'))) : array(), 200);
    }

    private function get_explicit_settings_patch(WP_REST_Request $request, array $allowed_keys)
    {
        $patch = array();
        $json_params = $request->get_json_params();
        $json_params = is_array($json_params) ? $json_params : array();
        foreach ($allowed_keys as $key) {
            if (array_key_exists($key, $json_params)) {
                $patch[$key] = $json_params[$key];
            }
        }

        return $patch;
    }

    private function request_may_mutate_files(WP_REST_Request $request, array $current, array $patch = array())
    {
        $file_mutating_keys = array(
            'pageCacheEnabled',
            'objectCacheEnabled',
            'objectCacheBackend',
            'objectCacheFallbackBackend',
            'sqliteDatabaseSizeMb',
            'browserCacheRulesEnabled',
            'apacheStaticHtmlDeliveryEnabled',
            'gzipEnabled',
            'brotliEnabled',
        );

        $infrastructure_change = $this->request_may_manage_infrastructure($current, $patch);
        foreach ($file_mutating_keys as $key) {
            if (!array_key_exists($key, $patch)) {
                continue;
            }
            if ($infrastructure_change && in_array($key, array('objectCacheEnabled', 'objectCacheBackend', 'objectCacheFallbackBackend'), true)) {
                continue;
            }
            return true;
        }

        return false;
    }

    private function file_mutation_forbidden_response()
    {
        return new WP_REST_Response(
            array(
                'success' => false,
                'code'    => 'ultracache_file_mutation_forbidden',
                'message' => __('This UltraCache action changes plugin drop-ins, wp-config.php, .htaccess, or plugin activation state. It requires a full administrator with plugin activation permissions.', 'ultracache'),
            ),
            403
        );
    }

    private function infrastructure_forbidden_response()
    {
        return new WP_REST_Response(
            array(
                'success' => false,
                'code'    => 'ultracache_infrastructure_permission_denied',
                'message' => __('Configuring, testing, or flushing Redis or Varnish requires manage_options plus plugin activation or network plugin management permission.', 'ultracache'),
            ),
            403
        );
    }

    private function request_may_manage_infrastructure(array $current, array $patch)
    {
        $redis_keys = array(
            'redisHost',
            'redisPort',
            'redisUsername',
            'redisPassword',
            'clearRedisPassword',
            'validateRedisSettings',
            'redisDatabase',
            'redisPrefix',
            'redisUseTls',
            'redisPersistent',
            'redisConnectTimeoutMs',
            'redisReadTimeoutMs',
        );
        $varnish_keys = array(
            'varnishCliEnabled',
            'varnishCliMode',
            'varnishCliServers',
            'varnishCliKey',
            'clearVarnishCliKey',
            'varnishCliTimeoutSeconds',
            'varnishCliMethod',
            'varnishInvalidationStrategy',
            'varnishFlushScope',
            'varnishHtmlTtlMinutes',
            'varnishStaleWhileRevalidateSeconds',
            'varnishRefillAfterTargetedInvalidation',
            'varnishWarmDuringManualWarmup',
            'varnishRefreshAheadEnabled',
            'varnishRefreshAheadThresholdPercent',
            'varnishRefreshAheadMaxPages',
            'varnishRefreshAheadPinnedUrls',
            'flushAllIncludeVarnish',
        );

        foreach (array_merge($redis_keys, $varnish_keys) as $key) {
            if (array_key_exists($key, $patch)) {
                return true;
            }
        }

        $generic_object_cache_keys = array('objectCacheEnabled', 'objectCacheBackend', 'objectCacheFallbackBackend');
        $generic_change_requested = false;
        foreach ($generic_object_cache_keys as $key) {
            if (array_key_exists($key, $patch)) {
                $generic_change_requested = true;
                break;
            }
        }
        if (!$generic_change_requested) {
            return false;
        }

        $next = array_merge($current, $patch);
        $current_backend = strtolower((string) ($current['objectCacheBackend'] ?? 'redis'));
        $next_backend = strtolower((string) ($next['objectCacheBackend'] ?? 'redis'));

        return 'redis' === $current_backend || 'redis' === $next_backend;
    }

    public function update_settings(WP_REST_Request $request)
    {
        $allowed_keys = array_keys($this->get_settings_update_args());
        $patch = $this->get_explicit_settings_patch($request, $allowed_keys);

        $stored = get_option(ULTRACACHE_SETTINGS_KEY, array());
        $stored = is_array($stored) ? $stored : array();
        $current = $stored;

        if ($this->request_may_manage_infrastructure($stored, $patch) && !$this->check_infrastructure_permission($request)) {
            return $this->infrastructure_forbidden_response();
        }

        if ($this->request_may_mutate_files($request, $stored, $patch) && !$this->check_file_mutation_permission($request)) {
            return $this->file_mutation_forbidden_response();
        }

        if (!empty($patch)) {
            $current = array_merge($stored, $patch);
        }

        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'persist_dashboard_settings')) {
            $response = Ultra_Cache_WP::persist_dashboard_settings($current);
            if (is_wp_error($response)) {
                $error_code = $response->get_error_code();
                $status = 'ultracache_redis_settings_validation_failed' === $error_code
                    ? 400
                    : ('ultracache_infrastructure_permission_denied' === $error_code ? 403 : 500);
                return new WP_REST_Response(
                    array(
                        'success' => false,
                        'code'    => $response->get_error_code(),
                        'message' => $response->get_error_message(),
                    ),
                    $status
                );
            }

            $response['patchKeys'] = array_keys($patch);

            if (!empty($response['settings']) && class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_settings_for_client')) {
                $response['settings'] = Ultra_Cache_WP::get_dashboard_settings_for_client();
            }

            return new WP_REST_Response($response, 200);
        }

        update_option(ULTRACACHE_SETTINGS_KEY, $current);
        $client_settings = is_array($current) ? array_diff_key($current, array_flip(array('redisPassword', 'varnishCliKey'))) : array();
        return new WP_REST_Response(array('success' => true, 'settings' => $client_settings, 'patchKeys' => array_keys($patch)), 200);
    }


    public function delete_all_plugin_data(WP_REST_Request $request)
    {
        $confirmation = strtoupper(trim((string) $request->get_param('confirmation')));
        if ('DELETE' !== $confirmation) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => __('Confirmation failed. Type DELETE to remove UltraCache data and deactivate the plugin.', 'ultracache'),
                ),
                400
            );
        }

        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'delete_all_plugin_data_and_deactivate')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Cleanup helper not available.', 'ultracache')), 500);
        }

        $cleanup_policy = $request->get_param('cleanupPolicy');
        $result = Ultra_Cache_WP::delete_all_plugin_data_and_deactivate($cleanup_policy);
        if (is_wp_error($result)) {
            return new WP_REST_Response(array('success' => false, 'message' => $result->get_error_message()), 500);
        }

        return new WP_REST_Response($result, 200);
    }

    public function manual_warm_session(WP_REST_Request $request)
    {
        if (!class_exists('Ultra_Cache_WP')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Manual warm-up control is not available.', 'ultracache')), 500);
        }

        $action = sanitize_key((string) $request->get_param('action'));
        $token = sanitize_text_field((string) $request->get_param('token'));
        $job_type = sanitize_key((string) $request->get_param('jobType'));

        if ('status' === $action && method_exists('Ultra_Cache_WP', 'get_manual_warm_status')) {
            return new WP_REST_Response(array('success' => true, 'state' => Ultra_Cache_WP::get_manual_warm_status()), 200);
        }
        if ('begin' === $action && method_exists('Ultra_Cache_WP', 'begin_manual_warmup_session')) {
            $result = Ultra_Cache_WP::begin_manual_warmup_session($job_type, $token);
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 409);
        }
        if ('pause' === $action && method_exists('Ultra_Cache_WP', 'pause_manual_warmup_session')) {
            $result = Ultra_Cache_WP::pause_manual_warmup_session($token);
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 409);
        }
        if ('end' === $action && method_exists('Ultra_Cache_WP', 'end_manual_warmup_session')) {
            $result = Ultra_Cache_WP::end_manual_warmup_session($token);
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 409);
        }

        return new WP_REST_Response(array('success' => false, 'message' => __('Invalid manual warm-up action.', 'ultracache')), 400);
    }

    public function cron_warm_start()
    {
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'start_cron_warmup_queue')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Cron warm helper not available.', 'ultracache')), 500);
        }

        $result = Ultra_Cache_WP::start_cron_warmup_queue('manual', false);
        if (!empty($result['state'])) {
            $result['diagnostics'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics') ? Ultra_Cache_WP::get_dashboard_diagnostics() : array();
            $result['stats'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_engine_stats') ? Ultra_Cache_WP::get_engine_stats() : array();
        }
        return new WP_REST_Response($result, 200);
    }

    public function cron_warm_stop()
    {
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'stop_cron_warmup_queue')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Cron warm helper not available.', 'ultracache')), 500);
        }

        $result = Ultra_Cache_WP::stop_cron_warmup_queue('manual');
        $result['diagnostics'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics') ? Ultra_Cache_WP::get_dashboard_diagnostics() : array();
        $result['stats'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_engine_stats') ? Ultra_Cache_WP::get_engine_stats() : array();
        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }

    public function cron_warm_tick(WP_REST_Request $request)
    {
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'run_cron_warm_tick')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Cron warm helper not available.', 'ultracache')), 500);
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
        if (!$this->check_infrastructure_permission()) {
            return $this->infrastructure_forbidden_response();
        }

        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'run_varnish_basic_test')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Varnish helper not available.', 'ultracache')), 500);
        }

        if (method_exists('Ultra_Cache_WP', 'reset_settings_cache')) {
            Ultra_Cache_WP::reset_settings_cache();
        }
        $result = Ultra_Cache_WP::run_varnish_basic_test();
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

    public function varnish_test_behavior()
    {
        if (!$this->check_infrastructure_permission()) {
            return $this->infrastructure_forbidden_response();
        }

        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'run_varnish_basic_test')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Varnish behavior test helper not available.', 'ultracache')), 500);
        }

        if (method_exists('Ultra_Cache_WP', 'reset_settings_cache')) {
            Ultra_Cache_WP::reset_settings_cache();
        }
        $result = Ultra_Cache_WP::run_varnish_basic_test();
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

    public function varnish_flush_all($request = null)
    {
        if (!$this->check_infrastructure_permission()) {
            return $this->infrastructure_forbidden_response();
        }

        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'varnish_flush_all_current_host')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Varnish helper not available.', 'ultracache')), 500);
        }

        $scope = 'configured';
        if ($request instanceof WP_REST_Request) {
            $scope = $this->sanitize_varnish_flush_action_scope_param($request->get_param('scope'));
        }

        if ('entire-host' === $scope) {
            if (!method_exists('Ultra_Cache_WP', 'varnish_flush_entire_current_host')) {
                return new WP_REST_Response(array('success' => false, 'message' => __('Varnish entire-host flush helper not available.', 'ultracache')), 500);
            }
            $result = Ultra_Cache_WP::varnish_flush_entire_current_host();
        } else {
            $result = Ultra_Cache_WP::varnish_flush_all_current_host();
        }
        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }

    public function opcache_flush()
    {
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'flush_opcache')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('OPcache helper not available.', 'ultracache')), 500);
        }

        $result = Ultra_Cache_WP::flush_opcache();
        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }

    public function apcu_flush()
    {
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'flush_apcu')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('APCu helper not available.', 'ultracache')), 500);
        }

        $result = Ultra_Cache_WP::flush_apcu();
        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }

    public function external_caches_redetect()
    {
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'redetect_external_caches')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('External cache detection helper not available.', 'ultracache')), 500);
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
            return new WP_REST_Response(array('success' => false, 'message' => __('LiteSpeed Cache helper not available.', 'ultracache')), 500);
        }

        $result = Ultra_Cache_WP::flush_litespeed_cache();
        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }

    public function nginx_flush()
    {
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'flush_nginx_cache')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Nginx Cache helper not available.', 'ultracache')), 500);
        }

        $result = Ultra_Cache_WP::flush_nginx_cache();
        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }

    public function object_cache_backend_test(WP_REST_Request $request)
    {
        if (!$this->check_object_cache_infrastructure_permission($request)) {
            return $this->infrastructure_forbidden_response();
        }

        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'test_object_cache_backend')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Object cache backend test helper not available.', 'ultracache')), 500);
        }

        $backend = sanitize_key((string) $request->get_param('backend'));
        if (!in_array($backend, array('redis', 'apcu', 'sqlite', 'disk', 'active', 'selected'), true)) {
            $backend = 'selected';
        }

        $settings = array();
        foreach (array('redisHost', 'redisPort', 'redisUsername', 'redisPassword', 'clearRedisPassword', 'redisDatabase', 'redisPrefix', 'redisUseTls', 'redisPersistent', 'redisConnectTimeoutMs', 'redisReadTimeoutMs') as $key) {
            if (null !== $request->get_param($key)) {
                $settings[$key] = $request->get_param($key);
            }
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

    public function object_cache_flush(?WP_REST_Request $request = null)
    {
        if (!$this->check_object_cache_infrastructure_permission($request)) {
            return $this->infrastructure_forbidden_response();
        }

        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'flush_object_cache')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Object cache helper not available.', 'ultracache')), 500);
        }

        $backend = $request instanceof WP_REST_Request ? sanitize_key((string) $request->get_param('backend')) : 'active';
        if (!in_array($backend, array('redis', 'apcu', 'sqlite', 'disk', 'active', 'selected'), true)) {
            $backend = 'active';
        }

        $result = Ultra_Cache_WP::flush_object_cache($backend);
        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }


    public function remove_conflicting_cache_dropins()
    {
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'remove_conflicting_cache_dropins')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Cache helper cleanup is not available.', 'ultracache')), 500);
        }

        $result = Ultra_Cache_WP::remove_conflicting_cache_dropins();
        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }


    public function object_cache_full_count()
    {
        $stats = $this->resolve_engine_stats(true);

        return new WP_REST_Response(array(
            'success' => true,
            'message' => __('Full object-cache count completed.', 'ultracache'),
            'stats' => $stats,
        ), 200);
    }


    private function is_dashboard_setting_enabled($key)
    {
        $settings = defined('ULTRACACHE_SETTINGS_KEY') ? get_option(ULTRACACHE_SETTINGS_KEY, array()) : array();
        return is_array($settings) && !empty($settings[$key]);
    }

    private function guard_page_cache_enabled()
    {
        if (!$this->is_dashboard_setting_enabled('pageCacheEnabled')) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('Please enable Page Caching first or select a profile before warming cache.', 'ultracache'),
            ), 400);
        }

        return null;
    }

    private function guard_css_bundle_enabled()
    {
        if (!$this->is_dashboard_setting_enabled('homepageCssBundleEnabled')) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('Please enable CSS Bundling before using CSS bundle actions.', 'ultracache'),
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

    /**
     * Attach a Varnish public refill result to a successful dashboard warm action.
     *
     * @param array  $result Warm result.
     * @param string $url    Public local URL.
     * @return array
     */
    private function maybe_refill_varnish_after_dashboard_warm(array $result, $url)
    {
        if (
            !empty($result['success'])
            && class_exists('Ultra_Cache_WP')
            && method_exists('Ultra_Cache_WP', 'refill_varnish_after_manual_warm')
        ) {
            $result['varnishRefill'] = Ultra_Cache_WP::refill_varnish_after_manual_warm($url, $result);
            unset($result['forceRefreshDetails']);
        }

        return $result;
    }

    public function crawl_page(WP_REST_Request $request)
    {
        $url = esc_url_raw((string) $request->get_param('url'));
        if ('' === $url) {
            return new WP_REST_Response(array('success' => false, 'message' => __('No URL provided.', 'ultracache')), 400);
        }

        $manual_token = sanitize_text_field((string) $request->get_param('manualToken'));
        if ('' !== $manual_token && class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'renew_manual_warmup_session')) {
            $manual_state = Ultra_Cache_WP::renew_manual_warmup_session($manual_token);
            if (empty($manual_state['success'])) {
                return new WP_REST_Response($manual_state, 409);
            }
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
            return new WP_REST_Response(array('success' => false, 'message' => __('Cache engine not available.', 'ultracache')), 500);
        }

        if (!$engine->is_cacheable_local_url($url)) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Only local site URLs can be crawled.', 'ultracache')), 400);
        }

        $build_css_bundle = (bool) $request->get_param('buildCssBundle');
        $warm_args = array(
            'build_css_bundle'      => $build_css_bundle,
            'force_refresh'         => '' !== $manual_token,
            'ignore_runtime_bypass' => '' !== $manual_token,
        );
        if ('' !== $manual_token && !$build_css_bundle) {
            // Manual HTML-only warm buttons must not trigger the automatic CSS bundle
            // preparation path. HTML+CSS buttons opt in through buildCssBundle.
            $warm_args['skip_css_bundle'] = true;
        }

        if ('' !== $manual_token && method_exists($engine, 'warm_page_pipeline')) {
            $warm_args['include_varnish'] = true;
            $result = $engine->warm_page_pipeline($url, $warm_args);
        } else {
            $result = $engine->warm_url($url, $warm_args);
        }
        $status = !empty($result['success']) || !empty($result['skipped']) ? 200 : 500;
        return new WP_REST_Response($result, $status);
    }

    public function manual_warm_page_stage(WP_REST_Request $request)
    {
        $url = esc_url_raw((string) $request->get_param('url'));
        $stage = sanitize_key((string) $request->get_param('stage'));
        $manual_token = sanitize_text_field((string) $request->get_param('manualToken'));

        if ('' !== $manual_token && class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'renew_manual_warmup_session')) {
            $manual_state = Ultra_Cache_WP::renew_manual_warmup_session($manual_token);
            if (empty($manual_state['success'])) {
                return new WP_REST_Response($manual_state, 409);
            }
        }

        $guard = $this->guard_page_cache_enabled();
        if ($guard instanceof WP_REST_Response) {
            return $guard;
        }

        $engine = $this->get_engine();
        if (!$engine || !method_exists($engine, 'is_cacheable_local_url') || !$engine->is_cacheable_local_url($url)) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Only local site URLs can be warmed.', 'ultracache')), 400);
        }

        if (in_array($stage, array('html', 'css'), true)) {
            if ('css' === $stage && (bool) $request->get_param('buildCssBundle')) {
                $guard = $this->guard_css_bundle_enabled();
                if ($guard instanceof WP_REST_Response) {
                    return $guard;
                }
            }
            if (!method_exists($engine, 'warm_page_pipeline_stage')) {
                return new WP_REST_Response(array('success' => false, 'message' => __('Manual warm-up stage engine is unavailable.', 'ultracache')), 500);
            }

            $result = $engine->warm_page_pipeline_stage($url, $stage, array(
                'build_css_bundle' => (bool) $request->get_param('buildCssBundle'),
                'force_refresh' => true,
                'ignore_runtime_bypass' => true,
            ));
            if ('html' === $stage && class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_manual_varnish_warm_plan')) {
                $result['varnishPlan'] = Ultra_Cache_WP::get_manual_varnish_warm_plan();
            }
            return new WP_REST_Response($result, !empty($result['success']) || !empty($result['skipped']) ? 200 : 500);
        }

        if (!class_exists('Ultra_Cache_WP')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Varnish integration is unavailable.', 'ultracache')), 500);
        }

        if ('varnish' === $stage && method_exists('Ultra_Cache_WP', 'refill_varnish_manual_bucket')) {
            $result = Ultra_Cache_WP::refill_varnish_manual_bucket($url, sanitize_key((string) $request->get_param('bucket')));
            return new WP_REST_Response($result, !empty($result['success']) || !empty($result['skipped']) ? 200 : 500);
        }

        return new WP_REST_Response(array('success' => false, 'message' => __('Unknown manual warm-up stage.', 'ultracache')), 400);
    }

    public function inspect_url(WP_REST_Request $request)
    {
        $url = (string) $request->get_param('url');
        if ('' === trim($url)) {
            return new WP_REST_Response(array('success' => false, 'message' => __('No URL provided.', 'ultracache')), 400);
        }

        $engine = $this->get_engine();
        if (!$engine || !method_exists($engine, 'inspect_url')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Cache engine not available.', 'ultracache')), 500);
        }

        if (!method_exists($engine, 'is_cacheable_local_url') || !$engine->is_cacheable_local_url($url)) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Only local site URLs can be inspected.', 'ultracache')), 400);
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
            return new WP_REST_Response(array('success' => false, 'message' => __('Cache engine not available.', 'ultracache')), 500);
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
            return new WP_REST_Response(array('success' => false, 'message' => __('Cache engine not available.', 'ultracache')), 500);
        }

        if (method_exists($engine, 'warm_page_pipeline')) {
            $result = $engine->warm_page_pipeline(home_url('/'), array(
                'force_refresh'         => true,
                'ignore_runtime_bypass' => true,
                'skip_css_bundle'       => true,
                'include_varnish'       => true,
            ));
        } else {
            $result = $engine->warm_frontpage_html(array(
                'force_refresh'         => true,
                'ignore_runtime_bypass' => true,
                'skip_css_bundle'       => true,
            ));
            $result = $this->maybe_refill_varnish_after_dashboard_warm($result, home_url('/'));
        }
        return new WP_REST_Response($result, !empty($result['success']) || !empty($result['skipped']) ? 200 : 500);
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
            return new WP_REST_Response(array('success' => false, 'message' => __('Cache engine not available.', 'ultracache')), 500);
        }

        if (method_exists($engine, 'warm_page_pipeline')) {
            $result = $engine->warm_page_pipeline(home_url('/'), array(
                'build_css_bundle'      => true,
                'force_refresh'         => true,
                'ignore_runtime_bypass' => true,
                'include_varnish'       => true,
            ));
        } else {
            $result = $engine->warm_frontpage_html_with_css(array(
                'ignore_runtime_bypass' => true,
            ));
            $result = $this->maybe_refill_varnish_after_dashboard_warm($result, home_url('/'));
        }
        return new WP_REST_Response($result, !empty($result['success']) ? 200 : (!empty($result['skipped']) ? 200 : 500));
    }

}
