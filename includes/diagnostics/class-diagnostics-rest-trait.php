<?php
/**
 * Dashboard diagnostics and cache-stat response surfaces used by REST and admin consumers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Diagnostics_REST_Trait
{
private static function get_dashboard_stats_state_name()
    {
        return 'ultracache_state:dashboard.stats_snapshot';
    }

private static function get_last_cache_event_state_name()
    {
        return 'ultracache_state:dashboard.last_cache_event';
    }

private static function get_persistent_last_cache_event()
    {
        if (!function_exists('ultracache_get_state_record_read_only')) {
            return array();
        }

        $record = ultracache_get_state_record_read_only(self::get_last_cache_event_state_name());
        return isset($record['payload']['event']) && is_array($record['payload']['event'])
            ? $record['payload']['event']
            : array();
    }

public static function persist_dashboard_stats_snapshot(array $stats, $timestamp)
    {
        $payload = array(
            'time' => max(0, (int) $timestamp),
            'stats' => $stats,
            'contractVersion' => 1,
        );

        if (!function_exists('ultracache_mutate_state_record')) {
            return array('success' => false, 'reason' => 'state_storage_unavailable');
        }

        return ultracache_mutate_state_record(
            self::get_dashboard_stats_state_name(),
            static function () use ($payload) {
                return $payload;
            },
            3,
            $payload
        );
    }

private static function build_dashboard_stats_from_persistent_sources(array $previous_stats = array())
    {
        $stats = $previous_stats;

        if (class_exists('Ultra_Cache_Engine') && method_exists('Ultra_Cache_Engine', 'get_analytics_stats')) {
            $analytics = Ultra_Cache_Engine::get_analytics_stats();
            if (is_array($analytics)) {
                $stats = array_merge($stats, $analytics);
            }
        }

        $activity_record = function_exists('ultracache_get_state_record_read_only')
            ? ultracache_get_state_record_read_only(self::get_page_cache_activity_state_name())
            : array();
        $activity = isset($activity_record['payload']) && is_array($activity_record['payload']) ? $activity_record['payload'] : array();
        $storage_record = function_exists('ultracache_get_state_record_read_only')
            ? ultracache_get_state_record_read_only(self::get_cache_storage_diagnostics_state_name())
            : array();
        $storage = isset($storage_record['payload']) && is_array($storage_record['payload']) ? $storage_record['payload'] : array();

        $activity_time = max(0, (int) ($activity['computedAt'] ?? 0), (int) ($activity['dirtyAt'] ?? 0), (int) ($activity_record['updatedAt'] ?? 0));
        $storage_time = max(0, (int) ($storage['scannedAt'] ?? ($storage_record['updatedAt'] ?? 0)));
        if (isset($activity['pageFiles']) && $activity_time >= $storage_time) {
            $stats['pageCacheFiles'] = max(0, (int) $activity['pageFiles']);
            $stats['pageCacheStatsState'] = !empty($activity['dirty']) ? 'dirty' : (!empty($activity['partial']) ? 'partial' : 'current');
            $stats['pageCacheStatsComputedAt'] = $activity_time;
            $stats['pageCacheStatsPartial'] = !empty($activity['partial']);
            $stats['pageCacheStatsPartialReason'] = (string) ($activity['partialReason'] ?? '');
        } elseif (isset($storage['pageCache']['files'])) {
            $stats['pageCacheFiles'] = max(0, (int) $storage['pageCache']['files']);
            $stats['pageCacheStatsState'] = !empty($storage['fingerprint']) && !hash_equals((string) $storage['fingerprint'], self::get_cache_storage_diagnostics_fingerprint())
                ? 'configuration-changed'
                : (($storage_time > 0 && (time() - $storage_time) > DAY_IN_SECONDS) ? 'stale' : (!empty($storage['pageCache']['truncated']) ? 'partial' : 'current'));
            $stats['pageCacheStatsComputedAt'] = $storage_time;
            $stats['pageCacheStatsPartial'] = !empty($storage['pageCache']['truncated']);
            $stats['pageCacheStatsPartialReason'] = !empty($storage['pageCache']['truncated']) ? 'limit' : '';
        } elseif (!isset($stats['pageCacheFiles'])) {
            $stats['pageCacheFiles'] = 0;
            $stats['pageCacheStatsState'] = 'not-measured';
            $stats['pageCacheStatsComputedAt'] = 0;
            $stats['pageCacheStatsPartial'] = false;
            $stats['pageCacheStatsPartialReason'] = '';
        }

        $previous_backend = strtolower((string) ($stats['objectCacheActiveBackend'] ?? $stats['objectCacheBackend'] ?? ''));
        $light_object_stats = array();
        if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'get_lightweight_stats')) {
            $light_object_stats = Ultra_Cache_Object_Cache_Manager::get_lightweight_stats();
        }
        if (is_array($light_object_stats) && !empty($light_object_stats)) {
            $current_backend = strtolower((string) ($light_object_stats['objectCacheActiveBackend'] ?? $light_object_stats['objectCacheBackend'] ?? ''));
            $backend_changed = '' !== $previous_backend && '' !== $current_backend && $previous_backend !== $current_backend;
            $stats = array_merge($stats, $light_object_stats);
            if ($backend_changed) {
                foreach (array(
                    'objectCacheEntries', 'objectCacheSizeBytes',
                    'objectCacheRedisEntries', 'objectCacheRedisSizeBytes',
                    'objectCacheApcuEntries', 'objectCacheApcuSizeBytes',
                    'objectCacheSqliteEntries', 'objectCacheSqliteSizeBytes',
                    'objectCacheDiskEntries', 'objectCacheDiskSizeBytes'
                ) as $key) {
                    $stats[$key] = 0;
                }
                $stats['objectCacheStatsState'] = 'configuration-changed';
                $stats['objectCacheStatsMeasured'] = false;
            }
        }

        if (!isset($stats['objectCacheEntries'])) {
            $stats['objectCacheEntries'] = 0;
        }
        if (!isset($stats['objectCacheSizeBytes'])) {
            $stats['objectCacheSizeBytes'] = 0;
        }
        if (!isset($stats['objectCacheStatsMeasured'])) {
            $stats['objectCacheStatsMeasured'] = isset($previous_stats['objectCacheEntries']);
        }
        if (!isset($stats['objectCacheStatsState'])) {
            $stats['objectCacheStatsState'] = !empty($stats['objectCacheStatsMeasured']) ? 'persisted-measurement' : 'not-measured';
        }
        $stats['objectCacheSizeHuman'] = function_exists('size_format')
            ? size_format((int) $stats['objectCacheSizeBytes'], 2)
            : (string) (int) $stats['objectCacheSizeBytes'];

        $cache_root_bytes = null;
        if (isset($storage['cacheRoot']['bytes'])) {
            $cache_root_bytes = max(0, (int) $storage['cacheRoot']['bytes']);
        } elseif (isset($storage['pageCache']['bytes'])) {
            $cache_root_bytes = max(0, (int) $storage['pageCache']['bytes']) + max(0, (int) ($storage['cssBundles']['bytes'] ?? 0));
        }
        if (null !== $cache_root_bytes) {
            $stats['cacheSizeBytes'] = $cache_root_bytes + max(0, (int) $stats['objectCacheSizeBytes']);
        } elseif (!isset($stats['cacheSizeBytes'])) {
            $stats['cacheSizeBytes'] = max(0, (int) $stats['objectCacheSizeBytes']);
        }
        $stats['cacheSizeHuman'] = function_exists('size_format')
            ? size_format((int) $stats['cacheSizeBytes'], 2)
            : (string) (int) $stats['cacheSizeBytes'];

        $stats['cronWarm'] = self::get_cron_warm_status();
        $stats['opcache'] = self::get_opcache_status_summary();
        $stats['apcu'] = self::get_apcu_status_summary();
        $stats['externalCaches'] = self::get_external_cache_detection(false);
        $stats['dashboardStatsLightweight'] = true;
        $stats['dashboardStatsSource'] = 'persistent-state-and-authoritative-counters';
        $stats['dashboardDiagnosticsIncluded'] = false;

        return $stats;
    }

public static function get_dashboard_diagnostics($force_storage_refresh = false)
        {
            $settings             = self::get_dashboard_settings();
            $support              = self::get_media_support_status();
            $compression          = self::get_compression_support_status();
            $last                 = self::get_persistent_last_cache_event();
            $advanced_cache_path  = function_exists('ultracache_dropin_path') ? ultracache_dropin_path('advanced-cache.php') : '';
            $object_cache_path    = function_exists('ultracache_dropin_path') ? ultracache_dropin_path('object-cache.php') : '';
            $browser_cache_path   = self::get_browser_cache_htaccess_path();
            $object_cache_support  = self::get_object_cache_support_status(false);
            $object_backend_status = array();
            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'get_backend_status')) {
                $object_backend_status = Ultra_Cache_Object_Cache_Manager::get_backend_status();
            }
            if (!is_array($object_backend_status)) {
                $object_backend_status = array();
            }
            $selected_object_backend = isset($object_backend_status['selected']) ? self::sanitize_object_cache_backend($object_backend_status['selected']) : self::sanitize_object_cache_backend($settings['objectCacheBackend']);
            $active_object_backend = isset($object_backend_status['active']) ? strtolower(trim((string) $object_backend_status['active'])) : $selected_object_backend;
            if (!in_array($active_object_backend, array('redis', 'apcu', 'sqlite', 'disk', 'runtime'), true)) {
                $active_object_backend = $selected_object_backend;
            }
            $configured_object_fallback = self::sanitize_object_cache_fallback_backend($settings['objectCacheFallbackBackend'] ?? 'apcu');
            $fallback_object_backend = isset($object_backend_status['fallback']) ? strtolower(trim((string) $object_backend_status['fallback'])) : ('none' === $configured_object_fallback ? 'runtime' : $configured_object_fallback);
            if (!in_array($fallback_object_backend, array('apcu', 'sqlite', 'disk', 'runtime'), true)) {
                $fallback_object_backend = 'none' === $configured_object_fallback ? 'runtime' : $configured_object_fallback;
            }
            $selected_object_backend_supported = true;
            if ('redis' === $selected_object_backend) {
                $selected_object_backend_supported = !empty(self::get_redis_support_status()['available']);
            } elseif ('apcu' === $selected_object_backend) {
                $selected_object_backend_supported = !empty($object_cache_support['apcu']['available']);
            } elseif ('sqlite' === $selected_object_backend) {
                $selected_object_backend_supported = !empty($object_cache_support['sqlite']['available']);
            }
            $object_fallback_active = isset($object_backend_status['fallbackActive'])
                ? (bool) $object_backend_status['fallbackActive']
                : ($selected_object_backend !== $active_object_backend);
            $object_active_runtime_only = 'runtime' === $active_object_backend;
            $object_active_persistent = in_array($active_object_backend, array('redis', 'apcu', 'sqlite', 'disk'), true);

            $css_bundle_summary_diagnostics = self::get_css_bundle_summary_diagnostics($settings);
            $cache_storage_diagnostics = self::get_cache_storage_diagnostics($settings, $css_bundle_summary_diagnostics, (bool) $force_storage_refresh);

            $varnish_mode = self::sanitize_varnish_mode($settings['varnishCliMode']);
            $varnish_servers = self::sanitize_varnish_servers_string($settings['varnishCliServers'], $varnish_mode);
            $varnish_cli_settings = self::get_varnish_cli_settings();
            $varnish_strategy_status = self::get_varnish_invalidation_strategy_status($varnish_cli_settings);
            $varnish_endpoint_diagnostics = is_array($varnish_cli_settings['endpointDiagnostics'] ?? null)
                ? $varnish_cli_settings['endpointDiagnostics']
                : array();

            $diagnostics = array(
                'pageCache' => array(
                    'enabled' => !empty($settings['pageCacheEnabled']),
                    'active'  => (bool) (defined('WP_CACHE') && WP_CACHE && ultracache_dropin_exists('advanced-cache.php')),
                ),
                'objectCache' => array_merge(
                    $object_cache_support,
                    array(
                        'enabled'         => !empty($settings['objectCacheEnabled']),
                        'active'          => (bool) (
                            class_exists('Ultra_Cache_Object_Cache_Manager')
                            && method_exists('Ultra_Cache_Object_Cache_Manager', 'is_dropin_active')
                            ? Ultra_Cache_Object_Cache_Manager::is_dropin_active()
                            : (function_exists('wp_using_ext_object_cache')
                                && wp_using_ext_object_cache()
                                && ultracache_dropin_exists('object-cache.php'))
                        ),
                        'selectedBackend' => $selected_object_backend,
                        'fallbackBackend' => $object_fallback_active ? $active_object_backend : $fallback_object_backend,
                        'configuredFallbackBackend' => $configured_object_fallback,
                        'fallbackActive'  => (bool) $object_fallback_active,
                        'activeFallbackBackend' => $object_fallback_active ? $active_object_backend : '',
                        'activeFallbackKind' => $object_fallback_active ? ($object_active_runtime_only ? 'runtime-only' : 'persistent') : '',
                        'fallbackPersistent' => $object_fallback_active && $object_active_persistent,
                        'fallbackReason'  => (string) ($object_backend_status['fallbackReason'] ?? ''),
                        'fallbackMessage' => (string) ($object_backend_status['fallbackMessage'] ?? ''),
                        'activeBackend'   => $active_object_backend,
                        'selectedBackendSupported' => (bool) $selected_object_backend_supported,
                        'activeBackendPersistent' => (bool) $object_active_persistent,
                        'activeBackendRuntimeOnly' => (bool) $object_active_runtime_only,
                        'passiveStatusOnly' => true,
                        'manualTestsOnly' => true,
                        'backendStatus'   => $object_backend_status,
                        'redis'           => array_merge(
                            self::get_redis_support_status(),
                            array(
                                'host'             => self::sanitize_redis_host($settings['redisHost']),
                                'port'             => self::sanitize_bounded_integer_setting($settings['redisPort'], 6379, 1, 65535),
                                'database'         => self::sanitize_redis_database($settings['redisDatabase']),
                                'prefix'           => self::sanitize_redis_prefix($settings['redisPrefix']),
                                'useTls'           => !empty($settings['redisUseTls']),
                                'persistent'       => !empty($settings['redisPersistent']),
                                'connectTimeoutMs' => self::sanitize_bounded_integer_setting($settings['redisConnectTimeoutMs'], 200, 50, 15000),
                                'readTimeoutMs'    => self::sanitize_bounded_integer_setting($settings['redisReadTimeoutMs'], 200, 50, 15000),
                            ),
                            isset($object_backend_status['redis']) && is_array($object_backend_status['redis'])
                                ? array(
                                    'dropinEnabled' => !empty($object_backend_status['redis']['enabled']),
                                    'dropinError' => (string) ($object_backend_status['redis']['error'] ?? ''),
                                    'payloadSkipReason' => (string) ($object_backend_status['redis']['payloadSkipReason'] ?? ''),
                                )
                                : array(),
                            array(
                                'passiveStatusOnly' => true,
                                'manualTestsOnly' => true,
                            ),
                            (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'get_last_flush_report'))
                                ? array('lastFlush' => Ultra_Cache_Object_Cache_Manager::get_last_flush_report())
                                : array()
                        ),
                        'sqlite'          => array_merge(
                            isset($object_cache_support['sqlite']) && is_array($object_cache_support['sqlite']) ? $object_cache_support['sqlite'] : array(),
                            isset($object_backend_status['sqlite']) && is_array($object_backend_status['sqlite'])
                                ? array(
                                    'dropinEnabled' => !empty($object_backend_status['sqlite']['enabled']),
                                    'dropinAvailable' => !empty($object_backend_status['sqlite']['available']),
                                    'journalMode' => (string) ($object_backend_status['sqlite']['journalMode'] ?? ''),
                                    'path' => (string) ($object_backend_status['sqlite']['path'] ?? ''),
                                    'error' => (string) ($object_backend_status['sqlite']['error'] ?? ''),
                                )
                                : array()
                        ),
                    )
                ),
                'formats' => array(
                    'avif' => !empty($support['imagick_avif']) || !empty($support['gd_avif']),
                    'webp' => !empty($support['imagick_webp']) || !empty($support['gd_webp']),
                ),
                'compression' => array(
                    'brotli' => array(
                        'available' => !empty($compression['brotli']),
                        'enabled'   => !empty($settings['brotliEnabled']),
                    ),
                    'gzip' => array(
                        'available' => !empty($compression['gzip']),
                        'enabled'   => !empty($settings['gzipEnabled']),
                    ),
                    'preferred' => (string) $compression['preferred'],
                    'message'   => (string) $compression['message'],
                    'serverDefault' => self::get_frontend_compression_probe_status(false),
                ),
                'wpCache' => self::get_wp_cache_define_status(),
                'googleFonts' => self::get_google_fonts_cache_diagnostics(),
                'fontPipeline' => self::get_font_pipeline_diagnostics($settings),
                'settingsTransparency' => self::get_settings_transparency_diagnostics($settings),
                'cssBundleSummary' => $css_bundle_summary_diagnostics,
                'cacheStorage' => $cache_storage_diagnostics,
                'securityCorrectness' => self::get_security_cache_correctness_diagnostics($settings),
                'browserCache' => array(
                    'enabled' => !empty($settings['browserCacheRulesEnabled']),
                    'path'    => $browser_cache_path,
                    'active'  => file_exists($browser_cache_path) && false !== strpos((string) ultracache_safe_file_get_contents($browser_cache_path, 'dashboard diagnostics'), '# BEGIN UltraCache Browser Cache'),
                ),
                'liteSpeed' => method_exists(__CLASS__, 'get_litespeed_diagnostics_status')
                    ? self::get_litespeed_diagnostics_status()
                    : array(),
                'varnish' => array_merge(
                    self::get_varnish_support_status(),
                    array(
                        'enabled' => self::is_varnish_enabled($settings),
                        'connectionConfigured' => self::is_varnish_connection_configured($settings),
                        'sharedCacheDelivery' => self::get_shared_cache_delivery_status($settings),
                        'mode'    => $varnish_mode,
                        'configuredMode' => $varnish_mode,
                        'servers' => $varnish_servers,
                        'endpointCount' => count(array_values(array_filter(array_map('trim', preg_split('/\s+/', $varnish_servers))))),
                        'method'  => ('PURGE' === strtoupper(trim((string) $settings['varnishCliMethod']))) ? 'PURGE' : 'BAN',
                        'effectiveMethod' => (string) ($varnish_strategy_status['effectiveLabel'] ?? 'BAN'),
                        'invalidationStrategy' => $varnish_strategy_status,
                        'adminModeUsed' => ('admin' === $varnish_mode),
                        'httpEndpointModeUsed' => ('http' === $varnish_mode),
                        'secretConfigured' => '' !== (function_exists('ultracache_get_varnish_password') ? ultracache_get_varnish_password() : ''),
                        'timeout' => max(1, min(15, absint($settings['varnishCliTimeoutSeconds']))),
                        'last'    => self::get_varnish_last_result(),
                        'basicTest' => self::get_varnish_basic_test_result(),
                        'endpointCapabilities' => self::get_varnish_endpoint_capability_registry_status(),
                        'runtimePlanner' => self::get_varnish_runtime_planner_status(),
                        'esiCapability' => self::get_varnish_esi_capability_status(),
                        'flushScope' => self::get_varnish_flush_scope_status(),
                        'staleWhileRevalidate' => self::get_varnish_stale_while_revalidate_status($settings),
                        'refillAfterTargetedInvalidation' => !empty($varnish_cli_settings['refillAfterTargetedInvalidation']),
                        'warmDuringManualWarmup' => !empty($varnish_cli_settings['warmWithSiteWarmup']),
                        'warmWithSiteWarmup' => !empty($varnish_cli_settings['warmWithSiteWarmup']),
                        'automationPolicy' => is_array($varnish_cli_settings['automationPolicy'] ?? null) ? $varnish_cli_settings['automationPolicy'] : array(),
                        'refreshAhead' => self::get_varnish_refresh_ahead_status($settings),
                        'endpointDiagnostics' => $varnish_endpoint_diagnostics,
                        'queue' => self::get_varnish_queue_stats(),
                        'metrics' => self::get_varnish_metrics_status(),
                        'performanceSnapshot' => self::get_varnish_performance_snapshot_status(),
                        'hasUnsafeEndpoints' => !empty($varnish_endpoint_diagnostics['unsafe']),
                        'unsafeEndpointMessage' => !empty($varnish_endpoint_diagnostics['messages'][0]) ? (string) $varnish_endpoint_diagnostics['messages'][0] : '',
                    )
                ),
                'reverseProxy' => self::get_reverse_proxy_status(),
                'loopbackSsl' => ultracache_get_loopback_ssl_status(),
                'legacyCacheConflicts' => self::get_legacy_cache_conflict_status(),
                'analytics' => self::get_analytics_hit_backend_diagnostic($settings),
                'environment' => self::get_advanced_environment_diagnostic(),
                'mediaRuntime' => self::get_media_runtime_diagnostic(),
                'cronWarm' => self::get_cron_warm_status(),
                'paths' => array(
                    'cacheDir'          => self::get_path_diagnostic(ULTRACACHE_CACHE_DIR, 'dir'),
                    'objectCacheDir'    => self::get_path_diagnostic(ULTRACACHE_OBJECT_CACHE_DIR, 'dir'),
                    'optimizedImagesDir' => defined('ULTRACACHE_OPTIMIZED_IMAGES_DIR') ? self::get_path_diagnostic(ULTRACACHE_OPTIMIZED_IMAGES_DIR, 'dir') : array(),
                    'avifDir'           => self::get_path_diagnostic(ULTRACACHE_AVIF_DIR, 'dir'),
                    'webpDir'           => self::get_path_diagnostic(ULTRACACHE_WEBP_DIR, 'dir'),
                    'advancedCache'     => self::get_path_diagnostic($advanced_cache_path, 'file', 'UltraCache advanced-cache drop-in'),
                    'objectCache'       => self::get_path_diagnostic($object_cache_path, 'file', 'UltraCache generated object-cache drop-in'),
                    'runtimeConfig'     => self::get_embedded_runtime_config_diagnostic(),
                    'analytics'         => self::get_analytics_diagnostic(),
                    'browserCacheRules' => self::get_path_diagnostic($browser_cache_path, 'file', '# BEGIN UltraCache Browser Cache'),
                ),
                'lastCacheWrite' => self::get_page_cache_activity_snapshot((bool) $force_storage_refresh),
                'lastEvent' => self::normalize_last_cache_event($last),
            );

            return self::redact_diagnostics_for_output($diagnostics, 'diagnostics', 0);
        }

public static function are_cache_stats_enabled()
    {
        $settings = defined('ULTRACACHE_SETTINGS_KEY') ? get_option(ULTRACACHE_SETTINGS_KEY, array()) : array();
        if (!is_array($settings)) {
            return false;
        }

        return !empty($settings['cacheStatsEnabled']) || !empty($settings['cache_stats_enabled']);
    }

public static function get_cache_stats_disabled_payload($source = 'stats_disabled')
    {
        $opcache = method_exists(__CLASS__, 'get_opcache_status_summary')
            ? self::get_opcache_status_summary()
            : array();
        $apcu = method_exists(__CLASS__, 'get_apcu_status_summary')
            ? self::get_apcu_status_summary()
            : array();

        $payload = array(
            'success' => true,
            'enabled' => false,
            'disabled' => true,
            'cacheStatsEnabled' => false,
            'message' => __('Cache stats are disabled.', 'ultracache'),
            'impact' => 'off',
            'timestamp' => time(),
            'source' => (string) $source,
            'dashboardStatsDisabled' => true,
            'dashboardStatsDisabledReason' => 'Cache stats are disabled.',
            'dashboardStatsSnapshotCached' => false,
            'dashboardStatsSnapshotAge' => 0,
            'dashboardStatsRefreshInterval' => 0,
            'dashboardStatsPollingDisabled' => true,
            // Cache Statistics OFF must hard-stop counters/scans/polling, but it must
            // not hide unrelated runtime tools. OPcache/APCu status is lightweight
            // admin runtime visibility and keeps the manual flush buttons usable.
            'opcache' => $opcache,
            'apcu' => $apcu,
            'externalCaches' => method_exists(__CLASS__, 'get_external_cache_detection') ? self::get_external_cache_detection(false) : array(),
            'diagnostics' => array(
                'cacheStats' => array(
                    'enabled' => false,
                    'disabled' => true,
                    'message' => __('When disabled, UltraCache does not collect, refresh, scan, or poll cache statistics. OPcache/APCu runtime status and manual flush controls remain available.', 'ultracache'),
                ),
                'objectCache' => method_exists(__CLASS__, 'get_object_cache_status_diagnostic_lite')
                    ? self::get_object_cache_status_diagnostic_lite()
                    : array(),
            ),
        );

        return $payload;
    }

public static function get_dashboard_stats_snapshot($max_age = 60, $allow_refresh = true)
    {
        $now = time();
        $max_age = max(3, (int) $max_age);

        // Count cache stats OFF is a hard stop for dashboard/stat snapshots.
        // Do not read persisted snapshots, refresh engine stats, scan storage,
        // count Redis/APCu keys, scan manifests, or touch analytics here.
        if (!self::are_cache_stats_enabled()) {
            return self::get_cache_stats_disabled_payload('snapshot_disabled');
        }

        $record = function_exists('ultracache_get_state_record_read_only')
            ? ultracache_get_state_record_read_only(self::get_dashboard_stats_state_name())
            : array();
        $stored = isset($record['payload']) && is_array($record['payload']) ? $record['payload'] : array();

        if (isset($stored['time'], $stored['stats']) && is_array($stored['stats'])) {
            $age = max(0, $now - (int) $stored['time']);
            if ($age <= $max_age || !$allow_refresh) {
                $stats = $stored['stats'];
                $stats['dashboardStatsSnapshotCached'] = true;
                $stats['dashboardStatsSnapshotPersistent'] = true;
                $stats['dashboardStatsSnapshotAge'] = $age;
                $stats['dashboardStatsRefreshInterval'] = $max_age;
                $stats['dashboardStatsSnapshotState'] = $age <= $max_age ? 'current' : 'stale';
                return $stats;
            }
        }

        if (!$allow_refresh) {
            $passive = array(
                'success' => true,
                'dashboardStatsSnapshotCached' => false,
                'dashboardStatsSnapshotPersistent' => true,
                'dashboardStatsRefreshInterval' => $max_age,
                'dashboardStatsSnapshotState' => empty($stored) ? 'not-measured' : 'stale',
                'message' => __('Dashboard stats are passive; no refresh was requested.', 'ultracache'),
            );

            // Initial dashboard bootstrap must not run heavy engine/storage stats,
            // but lightweight runtime-cache cards should still render before the
            // user presses Redetect Caches. Keep OPcache/APCu/Varnish visibility
            // independent from cache counter snapshots.
            if (method_exists(__CLASS__, 'get_opcache_status_summary')) {
                $passive['opcache'] = self::get_opcache_status_summary();
            }
            if (method_exists(__CLASS__, 'get_apcu_status_summary')) {
                $passive['apcu'] = self::get_apcu_status_summary();
            }
            if (method_exists(__CLASS__, 'get_external_cache_detection')) {
                $passive['externalCaches'] = self::get_external_cache_detection(false);
            }
            if (method_exists(__CLASS__, 'get_dashboard_diagnostics')) {
                $passive['diagnostics'] = self::get_dashboard_diagnostics();
            }

            return $passive;
        }

        $previous_stats = isset($stored['stats']) && is_array($stored['stats']) ? $stored['stats'] : array();
        $stats = self::build_dashboard_stats_from_persistent_sources($previous_stats);
        $stats['dashboardStatsSnapshotCached'] = false;
        $stats['dashboardStatsSnapshotPersistent'] = true;
        $stats['dashboardStatsSnapshotAge'] = 0;
        $stats['dashboardStatsRefreshInterval'] = $max_age;
        $stats['dashboardStatsSnapshotState'] = 'current';
        $mutation = self::persist_dashboard_stats_snapshot($stats, $now);
        if (empty($mutation['success'])) {
            $stats['dashboardStatsSnapshotPersistent'] = false;
            $stats['dashboardStatsSnapshotPersistenceError'] = (string) ($mutation['reason'] ?? 'write_failed');
        }
        return $stats;
    }

private static function should_use_live_settings_support_checks()
    {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return true;
        }

        if (function_exists('is_admin') && is_admin()) {
            return true;
        }

        return false;
    }

}
