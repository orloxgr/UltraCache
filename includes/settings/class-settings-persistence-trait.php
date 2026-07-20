<?php
/**
 * Dashboard settings persistence and transaction methods.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Settings_Persistence_Trait
{
    private static function apply_lcp_frontend_discovery_timer_state(array $current_settings, array $previous_settings)
    {
        $discovery_enabled_now = !empty($current_settings['lcpFrontendDiscoveryEnabled']);
        $discovery_was_enabled = !empty($previous_settings['lcpFrontendDiscoveryEnabled']);
        $discovery_duration = self::sanitize_lcp_frontend_discovery_duration($current_settings['lcpFrontendDiscoveryDuration'] ?? 'indefinitely');
        $previous_discovery_duration = self::sanitize_lcp_frontend_discovery_duration($previous_settings['lcpFrontendDiscoveryDuration'] ?? 'indefinitely');

        if (!$discovery_enabled_now) {
            $current_settings['lcpFrontendDiscoveryStartedAt'] = 0;
            $current_settings['lcpFrontendDiscoveryExpiresAt'] = 0;
            return $current_settings;
        }

        if (!$discovery_was_enabled || $discovery_duration !== $previous_discovery_duration) {
            $started_at = time();
            $duration_seconds = array(
                '1_hour' => HOUR_IN_SECONDS,
                '4_hours' => 4 * HOUR_IN_SECONDS,
                '8_hours' => 8 * HOUR_IN_SECONDS,
                '1_day' => DAY_IN_SECONDS,
                '3_days' => 3 * DAY_IN_SECONDS,
                '1_week' => WEEK_IN_SECONDS,
            );
            $current_settings['lcpFrontendDiscoveryStartedAt'] = $started_at;
            $current_settings['lcpFrontendDiscoveryExpiresAt'] = isset($duration_seconds[$discovery_duration])
                ? $started_at + $duration_seconds[$discovery_duration]
                : 0;
            return $current_settings;
        }

        $current_settings['lcpFrontendDiscoveryStartedAt'] = absint($previous_settings['lcpFrontendDiscoveryStartedAt'] ?? 0);
        $current_settings['lcpFrontendDiscoveryExpiresAt'] = absint($previous_settings['lcpFrontendDiscoveryExpiresAt'] ?? 0);
        return $current_settings;
    }

    private static function rollback_dashboard_settings_after_failed_critical_save(array $previous_settings, $sync_wp_config = true)
    {
        $restore = self::sanitize_dashboard_settings($previous_settings, false);
        $restore['redisPassword'] = '';
        $restore['varnishCliKey'] = '';
        update_option(ULTRACACHE_SETTINGS_KEY, $restore, false);
        self::reset_settings_cache();
        self::sync_page_cache_bootstrap(!empty($restore['pageCacheEnabled']), (bool) $sync_wp_config);

        if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'reset_plugin_settings_cache')) {
            Ultra_Cache_Object_Cache_Manager::reset_plugin_settings_cache();
        }
        if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'sync_dropin')) {
            Ultra_Cache_Object_Cache_Manager::sync_dropin();
        }
        if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'reset_plugin_settings_cache')) {
            Ultra_Cache_Object_Cache_Manager::reset_plugin_settings_cache();
        }
    }

    private static function rollback_failed_settings_transaction(array $previous_settings, array $wp_config_transaction)
    {
        $wp_config_restored = self::rollback_wp_config_transaction($wp_config_transaction);
        self::rollback_dashboard_settings_after_failed_critical_save($previous_settings, false);

        if (!$wp_config_restored) {
            return new WP_Error(
                'ultracache_wp_config_transaction_rollback_failed',
                self::maybe_translate('The settings save failed, and UltraCache could not verify restoration of the original wp-config.php content.')
            );
        }

        return true;
    }

    private static function current_user_can_manage_infrastructure()
    {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        return current_user_can('manage_options')
            && (current_user_can('activate_plugins') || current_user_can('manage_network_plugins'));
    }

    private static function infrastructure_settings_change_requested(array $current_settings, array $previous_settings, $force_redis_validation, $secret_change_requested)
    {
        if ($force_redis_validation || $secret_change_requested) {
            return true;
        }

        $infrastructure_keys = array(
            'redisHost',
            'redisPort',
            'redisUsername',
            'redisDatabase',
            'redisPrefix',
            'redisUseTls',
            'redisPersistent',
            'redisConnectTimeoutMs',
            'redisReadTimeoutMs',
            'varnishCliEnabled',
            'varnishCliMode',
            'varnishCliServers',
            'varnishCliTimeoutSeconds',
            'varnishCliMethod',
            'varnishInvalidationStrategy',
            'varnishFlushScope',
            'varnishHtmlTtlMinutes',
            'varnishStaleWhileRevalidateSeconds',
            'varnishRefillAfterTargetedInvalidation',
            'varnishWarmDuringManualWarmup',
            'varnishVerifyRefillHit',
            'varnishRefreshAheadEnabled',
            'varnishRefreshAheadThresholdPercent',
            'varnishRefreshAheadMaxPages',
            'flushAllIncludeVarnish',
        );
        foreach ($infrastructure_keys as $key) {
            if (($current_settings[$key] ?? null) !== ($previous_settings[$key] ?? null)) {
                return true;
            }
        }

        $current_backend = strtolower((string) ($current_settings['objectCacheBackend'] ?? 'redis'));
        $previous_backend = strtolower((string) ($previous_settings['objectCacheBackend'] ?? 'redis'));
        $object_cache_changed =
            !empty($current_settings['objectCacheEnabled']) !== !empty($previous_settings['objectCacheEnabled'])
            || $current_backend !== $previous_backend
            || (string) ($current_settings['objectCacheFallbackBackend'] ?? 'apcu') !== (string) ($previous_settings['objectCacheFallbackBackend'] ?? 'apcu');

        return $object_cache_changed && ('redis' === $current_backend || 'redis' === $previous_backend);
    }

    public static function persist_dashboard_settings(array $settings)
    {
        $force_redis_validation = !empty($settings['validateRedisSettings']);
        unset($settings['validateRedisSettings']);

        $previous_settings = self::get_dashboard_settings();
        $secret_patch = self::normalize_secret_constant_patch($settings);
        $secret_change_requested = self::secret_constant_patch_has_changes($secret_patch);

        $current_settings = self::sanitize_dashboard_settings(self::merge_protected_dashboard_settings($settings, $previous_settings));
        $current_settings = self::apply_lcp_frontend_discovery_timer_state($current_settings, $previous_settings);
        if (self::infrastructure_settings_change_requested($current_settings, $previous_settings, $force_redis_validation, $secret_change_requested)
            && !self::current_user_can_manage_infrastructure()) {
            return new WP_Error(
                'ultracache_infrastructure_permission_denied',
                self::maybe_translate('Configuring or validating Redis or Varnish requires manage_options plus plugin activation or network plugin management permission.')
            );
        }
        $lcp_discovery_enabled_now = !empty($current_settings['lcpFrontendDiscoveryEnabled']);
        $lcp_discovery_was_enabled = !empty($previous_settings['lcpFrontendDiscoveryEnabled']);
        $lcp_discovery_enabled_changed = $lcp_discovery_enabled_now !== $lcp_discovery_was_enabled;
        $lcp_discovery_delivery_changed = $lcp_discovery_enabled_changed || (
            ($lcp_discovery_enabled_now || $lcp_discovery_was_enabled)
            && (
                !empty($current_settings['lcpFrontendDiscoveryAdminsOnly']) !== !empty($previous_settings['lcpFrontendDiscoveryAdminsOnly'])
                || (string) ($current_settings['lcpFrontendDiscoveryDuration'] ?? 'indefinitely') !== (string) ($previous_settings['lcpFrontendDiscoveryDuration'] ?? 'indefinitely')
            )
        );
        $critical_validation = self::validate_critical_settings_support_before_persist($current_settings, $previous_settings);
        if (is_wp_error($critical_validation)) {
            return $critical_validation;
        }
        $varnish_validation = self::validate_varnish_settings($current_settings);
        if (is_wp_error($varnish_validation)) {
            return $varnish_validation;
        }

        $redis_validation = null;
        if ($force_redis_validation && 'redis' === strtolower((string) ($current_settings['objectCacheBackend'] ?? 'redis'))) {
            $redis_credentials = function_exists('ultracache_get_redis_credentials')
                ? ultracache_get_redis_credentials()
                : array('username' => '', 'password' => '');
            $redis_password = isset($redis_credentials['password']) ? (string) $redis_credentials['password'] : '';
            $redis_username = (string) ($current_settings['redisUsername'] ?? '');

            if (!empty($secret_patch['redis']['clear'])) {
                $redis_password = '';
            } elseif (!empty($secret_patch['redis']['provided'])) {
                $redis_password = (string) $secret_patch['redis']['value'];
            } elseif (!empty($redis_credentials['username'])) {
                // Preserve an existing Redis ACL username embedded in
                // WP_REDIS_PASSWORD when the password itself is unchanged.
                $redis_username = (string) $redis_credentials['username'];
            }

            if (defined('WP_REDIS_USERNAME')) {
                $external_redis_username = constant('WP_REDIS_USERNAME');
                if (is_scalar($external_redis_username) && '' !== trim((string) $external_redis_username)) {
                    $redis_username = trim((string) $external_redis_username);
                }
            }

            $redis_validation = self::test_redis_read_write(array(
                'redisHost'             => (string) ($current_settings['redisHost'] ?? '127.0.0.1'),
                'redisPort'             => (int) ($current_settings['redisPort'] ?? 6379),
                'redisUsername'         => $redis_username,
                'redisPassword'         => $redis_password,
                'redisDatabase'         => (int) ($current_settings['redisDatabase'] ?? 0),
                'redisPrefix'           => (string) ($current_settings['redisPrefix'] ?? ''),
                'redisUseTls'           => !empty($current_settings['redisUseTls']),
                'redisPersistent'       => !empty($current_settings['redisPersistent']),
                'redisConnectTimeoutMs' => (int) ($current_settings['redisConnectTimeoutMs'] ?? 200),
                'redisReadTimeoutMs'    => (int) ($current_settings['redisReadTimeoutMs'] ?? 200),
            ));

            if (empty($redis_validation['success']) || empty($redis_validation['readWrite'])) {
                $redis_message = !empty($redis_validation['message'])
                    ? trim((string) $redis_validation['message'])
                    : self::maybe_translate('Redis connection or read/write validation failed.');

                return new WP_Error(
                    'ultracache_redis_settings_validation_failed',
                    sprintf(
                        /* translators: %s: Redis connection validation error. */
                        self::maybe_translate('Redis settings were not saved. %s'),
                        $redis_message
                    )
                );
            }
        }

        $html_delivery_changed =
            !empty($current_settings['gzipEnabled']) !== !empty($previous_settings['gzipEnabled'])
            || !empty($current_settings['brotliEnabled']) !== !empty($previous_settings['brotliEnabled'])
            || !empty($current_settings['apacheStaticHtmlDeliveryEnabled']) !== !empty($previous_settings['apacheStaticHtmlDeliveryEnabled']);

        if ($html_delivery_changed) {
            $engine = self::get_engine_instance();
            if ($engine && method_exists($engine, 'purge_html_cache_for_delivery_change')) {
                $purged = $engine->purge_html_cache_for_delivery_change();
                if (!$purged) {
                    return new WP_Error(
                        'ultracache_html_delivery_cache_purge_failed',
                        self::maybe_translate('The existing HTML page cache could not be cleared before changing the delivery mode. Please retry the setting change.')
                    );
                }
            }
        }

        if ($lcp_discovery_delivery_changed) {
            $engine = self::get_engine_instance();
            if ($engine && method_exists($engine, 'purge_frontend_cache_for_lcp_discovery_change')) {
                $purged = $engine->purge_frontend_cache_for_lcp_discovery_change();
                if (!$purged) {
                    return new WP_Error(
                        'ultracache_lcp_discovery_cache_purge_failed',
                        self::maybe_translate('The existing frontend page cache could not be cleared before changing LCP Frontend Discovery. Please retry the setting change.')
                    );
                }
            }
        }

        $wp_config_transaction = self::update_wp_config_managed_constants(
            !empty($current_settings['pageCacheEnabled']),
            $secret_patch
        );
        if (is_wp_error($wp_config_transaction)) {
            return $wp_config_transaction;
        }

        $current_settings['redisPassword'] = '';
        $current_settings['varnishCliKey'] = '';
        update_option(ULTRACACHE_SETTINGS_KEY, $current_settings);
        self::reset_settings_cache();
        self::ensure_directories();
        if (class_exists('Ultra_Cache_Engine') && method_exists('Ultra_Cache_Engine', 'ensure_cache_directories')) {
            Ultra_Cache_Engine::ensure_cache_directories();
        }

        $page_cache_sync = self::sync_page_cache_bootstrap(!empty($current_settings['pageCacheEnabled']), false);
        if (is_wp_error($page_cache_sync)) {
            $rollback = self::rollback_failed_settings_transaction($previous_settings, $wp_config_transaction);
            return is_wp_error($rollback) ? $rollback : $page_cache_sync;
        }

        if (empty($current_settings['cronWarmEnabled'])) {
            self::stop_cron_warmup_queue('disabled');
        } else {
            $state = self::get_cron_warm_state();
            if (!empty($state['active'])) {
                $state['pagesPerMinute'] = max(0, (int) $current_settings['cronWarmPagesPerMinute']);
                $state['updatedAt'] = time();
                $state['lastMessage'] = $state['pagesPerMinute'] > 0 ? 'Cron warm up settings updated.' : 'Cron warm up paused because pages per minute is 0.';
                self::save_cron_warm_state($state);
                if ($state['pagesPerMinute'] > 0) {
                    self::ensure_cron_warm_events_scheduled();
                } else {
                    self::unschedule_cron_warm_events();
                }
            }
        }
        self::sync_scheduled_events();
        $browser_cache_sync = self::sync_browser_cache_rules();
        if (false === $browser_cache_sync) {
            $rollback = self::rollback_failed_settings_transaction($previous_settings, $wp_config_transaction);
            if (is_wp_error($rollback)) {
                return $rollback;
            }
            return new WP_Error('ultracache_browser_cache_rules_not_writable', self::maybe_translate('Browser Cache Headers could not be written to .htaccess. Check file permissions or disable Browser Cache Headers.'));
        }
        $apache_static_sync = self::sync_apache_static_html_delivery_rules();
        if (false === $apache_static_sync) {
            $rollback = self::rollback_failed_settings_transaction($previous_settings, $wp_config_transaction);
            if (is_wp_error($rollback)) {
                return $rollback;
            }
            return new WP_Error('ultracache_apache_static_html_rules_not_writable', self::maybe_translate('Apache Static HTML Delivery rules could not be written to .htaccess. Check file permissions or disable Apache Static HTML Delivery.'));
        }

        $object_cache_sync = null;
        if (class_exists('Ultra_Cache_Object_Cache_Manager')) {
            if (method_exists('Ultra_Cache_Object_Cache_Manager', 'reset_plugin_settings_cache')) {
                Ultra_Cache_Object_Cache_Manager::reset_plugin_settings_cache();
            }
            if (method_exists('Ultra_Cache_Object_Cache_Manager', 'sync_dropin')) {
                $object_cache_sync = Ultra_Cache_Object_Cache_Manager::sync_dropin();
            }
            if (method_exists('Ultra_Cache_Object_Cache_Manager', 'reset_plugin_settings_cache')) {
                Ultra_Cache_Object_Cache_Manager::reset_plugin_settings_cache();
            }
        }

        if (!empty($current_settings['objectCacheEnabled']) && true !== $object_cache_sync) {
            $rollback = self::rollback_failed_settings_transaction($previous_settings, $wp_config_transaction);
            if (is_wp_error($rollback)) {
                return $rollback;
            }
            return new WP_Error('ultracache_object_cache_dropin_sync_failed', self::maybe_translate('Object Cache could not be enabled because the UltraCache object-cache drop-in could not be installed or verified. Check the WordPress content-directory object-cache.php permissions and conflicting object-cache drop-ins.'));
        }

        $google_fonts_job = null;
        $google_fonts_enabled_now = !empty($current_settings['googleFontsLocalOptimizationEnabled']);
        $google_fonts_was_enabled = !empty($previous_settings['googleFontsLocalOptimizationEnabled']);
        $google_fonts_urls_changed = (string) ($current_settings['googleFontsAdditionalScanUrls'] ?? '') !== (string) ($previous_settings['googleFontsAdditionalScanUrls'] ?? '');
        if ($google_fonts_enabled_now && (!$google_fonts_was_enabled || $google_fonts_urls_changed)) {
            $google_fonts_job = array(
                'success' => true,
                'queued'  => false,
                'message' => sprintf(
                    /* translators: %s: WP-CLI command used to rebuild the local Google Fonts cache. */
                    __('Google Fonts settings saved. Use the Rebuild Google Fonts Cache button or %s to rebuild the local font cache.', 'ultracache'),
                    'wp ultracache google_fonts_rebuild --clear'
                ),
            );
        }

        $manual_lcp_selector_split = self::split_manual_lcp_selector_setting($current_settings['manualLcpHeroSelector'] ?? '');
        $engine = self::get_engine_instance();
        if ($engine && method_exists($engine, 'sync_lcp_observation_selectors')) {
            $engine->sync_lcp_observation_selectors((array) ($manual_lcp_selector_split['selectors'] ?? array()));
        }

        $crawl_scope_summary = self::get_crawl_scope_summary($current_settings);
        $selected_warm_sources_for_summary = array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) ($current_settings['warmFullSiteSources'] ?? ''))));
        $crawl_scope_source_counts = isset($crawl_scope_summary['sourceCounts']) && is_array($crawl_scope_summary['sourceCounts']) ? $crawl_scope_summary['sourceCounts'] : array();
        $crawl_scope_source_breakdown = isset($crawl_scope_summary['sourceBreakdown']) && is_array($crawl_scope_summary['sourceBreakdown']) ? $crawl_scope_summary['sourceBreakdown'] : array();
        $crawl_scope_selected_sources = isset($crawl_scope_summary['selectedFullSiteSources']) && is_array($crawl_scope_summary['selectedFullSiteSources']) ? $crawl_scope_summary['selectedFullSiteSources'] : array();
        $should_store_crawl_scope_summary = is_array($crawl_scope_summary);
        if (!empty($selected_warm_sources_for_summary) && empty($crawl_scope_source_counts) && empty($crawl_scope_source_breakdown) && empty($crawl_scope_selected_sources)) {
            $should_store_crawl_scope_summary = false;
        }
        if (defined('ULTRACACHE_CRAWL_SCOPE_SUMMARY_KEY') && $should_store_crawl_scope_summary) {
            $crawl_scope_summary['storedAt'] = time();
            update_option(
                ULTRACACHE_CRAWL_SCOPE_SUMMARY_KEY,
                array(
                    'updatedAt' => time(),
                    'summary'   => $crawl_scope_summary,
                ),
                false
            );
        }

        $payload = array(
            'success'     => true,
            'settings'    => self::get_dashboard_settings_for_client(),
            'crawlScopeSummary' => $crawl_scope_summary,
            'stats'       => self::get_engine_stats(),
            'diagnostics' => self::get_dashboard_diagnostics(),
        );
        if (is_array($google_fonts_job)) {
            $payload['googleFonts'] = $google_fonts_job;
        }
        if (is_array($redis_validation)) {
            unset($redis_validation['probeKey']);
            $payload['redisValidation'] = $redis_validation;
            $payload['message'] = self::maybe_translate('Redis settings verified and saved. Reloading to confirm the active runtime backend.');
        }

        return $payload;
    }
}
