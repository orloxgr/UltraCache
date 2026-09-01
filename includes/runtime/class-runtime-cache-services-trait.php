<?php
/**
 * Runtime cache status, detection, probing, and flush services.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Runtime_Cache_Services_Trait
{
    private static function get_engine_instance()
    {
        $candidates = array('Ultra_Cache_Engine');
        foreach ($candidates as $class) {
            if (class_exists($class) && method_exists($class, 'get_instance')) {
                return call_user_func(array($class, 'get_instance'));
            }
        }

        return null;
    }

    private static function get_media_instance()
    {
        $candidates = array('Ultra_Cache_Media_Converter');
        foreach ($candidates as $class) {
            if (class_exists($class) && method_exists($class, 'get_instance')) {
                return call_user_func(array($class, 'get_instance'));
            }
        }

        return null;
    }


    public static function get_opcache_status_summary()
    {
        if (!function_exists('opcache_get_status')) {
            return array(
                'available' => false,
                'enabled'   => false,
                'message'   => __('OPcache functions are unavailable on this server.', 'ultracache'),
            );
        }

        $status = @opcache_get_status(false);
        if (!is_array($status)) {
            return array(
                'available' => true,
                'enabled'   => false,
                'message'   => __('OPcache is not enabled for the current PHP SAPI.', 'ultracache'),
            );
        }

        $memory = isset($status['memory_usage']) && is_array($status['memory_usage']) ? $status['memory_usage'] : array();
        $interned = isset($status['interned_strings_usage']) && is_array($status['interned_strings_usage']) ? $status['interned_strings_usage'] : array();
        $statistics = isset($status['opcache_statistics']) && is_array($status['opcache_statistics']) ? $status['opcache_statistics'] : array();

        $used = (int) ($memory['used_memory'] ?? 0);
        $free = (int) ($memory['free_memory'] ?? 0);
        $wasted = (int) ($memory['wasted_memory'] ?? 0);
        $hits = (int) ($statistics['hits'] ?? 0);
        $misses = (int) ($statistics['misses'] ?? 0);
        $requests = $hits + $misses;
        $hit_rate = $requests > 0 ? round(($hits / $requests) * 100, 2) : 0.0;
        $last_restart = (int) ($status['last_restart_time'] ?? 0);
        $last_flush = (int) get_option('ultracache_opcache_last_flush_at', 0);

        return array(
            'available'                 => true,
            'enabled'                   => true,
            'message'                   => '',
            'memoryUsedBytes'           => $used,
            'memoryFreeBytes'           => $free,
            'memoryWastedBytes'         => $wasted,
            'memoryUsedHuman'           => function_exists('size_format') ? size_format($used, 2) : (string) $used,
            'memoryFreeHuman'           => function_exists('size_format') ? size_format($free, 2) : (string) $free,
            'memoryWastedHuman'         => function_exists('size_format') ? size_format($wasted, 2) : (string) $wasted,
            'internedUsedBytes'         => (int) ($interned['used_memory'] ?? 0),
            'internedFreeBytes'         => (int) ($interned['free_memory'] ?? 0),
            'internedUsedHuman'         => function_exists('size_format') ? size_format((int) ($interned['used_memory'] ?? 0), 2) : (string) ((int) ($interned['used_memory'] ?? 0)),
            'internedFreeHuman'         => function_exists('size_format') ? size_format((int) ($interned['free_memory'] ?? 0), 2) : (string) ((int) ($interned['free_memory'] ?? 0)),
            'cachedScripts'             => (int) ($statistics['num_cached_scripts'] ?? 0),
            'cachedKeys'                => (int) ($statistics['num_cached_keys'] ?? 0),
            'maxCachedKeys'             => (int) ($statistics['max_cached_keys'] ?? 0),
            'hits'                      => $hits,
            'misses'                    => $misses,
            'hitRate'                   => $hit_rate,
            'oomRestarts'               => (int) ($statistics['oom_restarts'] ?? 0),
            'hashRestarts'              => (int) ($statistics['hash_restarts'] ?? 0),
            'manualRestarts'            => (int) ($statistics['manual_restarts'] ?? 0),
            'lastRestartTime'           => $last_restart,
            'lastRestartTimeHuman'      => $last_restart > 0 ? gmdate('Y-m-d H:i:s', $last_restart) . ' UTC' : 'Never',
            'lastFlushTime'             => $last_flush,
            'lastFlushTimeHuman'        => $last_flush > 0 ? gmdate('Y-m-d H:i:s', $last_flush) . ' UTC' : 'Never',
            'restartPending'            => !empty($status['restart_pending']),
        );
    }

    public static function flush_opcache()
    {
        if (!function_exists('opcache_reset')) {
            return array(
                'success' => false,
                'message' => __('OPcache reset is unavailable on this server.', 'ultracache'),
            );
        }

        $success = (bool) @opcache_reset();
        if ($success) {
            update_option('ultracache_opcache_last_flush_at', time(), false);
        }
        $response = array(
            'success' => $success,
            'message' => $success ? __('OPcache flushed successfully.', 'ultracache') : __('OPcache flush failed.', 'ultracache'),
            'opcache' => self::get_opcache_status_summary(),
        );

        if (method_exists(__CLASS__, 'get_engine_stats')) {
            $response['stats'] = self::get_engine_stats();
        }

        return $response;
    }

    public static function get_apcu_status_summary()
    {
        if (!function_exists('apcu_fetch') || !function_exists('apcu_store') || !function_exists('apcu_cache_info') || !function_exists('apcu_sma_info')) {
            return array(
                'available' => false,
                'enabled'   => false,
                'message'   => __('APCu functions are unavailable on this server.', 'ultracache'),
            );
        }

        if (function_exists('apcu_enabled') && !apcu_enabled()) {
            return array(
                'available' => true,
                'enabled'   => false,
                'message'   => __('APCu is loaded but disabled for the current PHP SAPI.', 'ultracache'),
            );
        }

        $cache_info = @apcu_cache_info(true);
        $sma_info   = @apcu_sma_info(true);
        if (!is_array($cache_info) || !is_array($sma_info)) {
            return array(
                'available' => true,
                'enabled'   => false,
                'message'   => __('APCu status could not be read for the current PHP SAPI.', 'ultracache'),
            );
        }

        $num_segments = max(1, (int) ($sma_info['num_seg'] ?? 1));
        $segment_size = (int) ($sma_info['seg_size'] ?? 0);
        $total_memory = $segment_size > 0 ? ($num_segments * $segment_size) : 0;
        $free_memory  = (int) ($sma_info['avail_mem'] ?? 0);
        $used_memory  = $total_memory > 0 ? max(0, $total_memory - $free_memory) : (int) ($cache_info['mem_size'] ?? 0);
        $hits         = (int) ($cache_info['num_hits'] ?? 0);
        $misses       = (int) ($cache_info['num_misses'] ?? 0);
        $requests     = $hits + $misses;
        $hit_rate     = $requests > 0 ? round(($hits / $requests) * 100, 2) : 0.0;
        $usage_rate   = $total_memory > 0 ? round(($used_memory / $total_memory) * 100, 2) : 0.0;
        $entries      = (int) ($cache_info['num_entries'] ?? 0);
        if (!$entries && isset($cache_info['cache_list']) && is_array($cache_info['cache_list'])) {
            $entries = count($cache_info['cache_list']);
        }

        return array(
            'available'            => true,
            'enabled'              => true,
            'message'              => '',
            'memoryTotalBytes'     => $total_memory,
            'memoryUsedBytes'      => $used_memory,
            'memoryFreeBytes'      => $free_memory,
            'memoryTotalHuman'     => function_exists('size_format') ? size_format($total_memory, 2) : (string) $total_memory,
            'memoryUsedHuman'      => function_exists('size_format') ? size_format($used_memory, 2) : (string) $used_memory,
            'memoryFreeHuman'      => function_exists('size_format') ? size_format($free_memory, 2) : (string) $free_memory,
            'memoryUsagePercent'   => $usage_rate,
            'cachedEntries'        => $entries,
            'hits'                 => $hits,
            'misses'               => $misses,
            'hitRate'              => $hit_rate,
            'inserts'              => (int) ($cache_info['num_inserts'] ?? 0),
            'expunges'             => (int) ($cache_info['expunges'] ?? 0),
            'startTime'            => (int) ($cache_info['start_time'] ?? 0),
            'startTimeHuman'       => !empty($cache_info['start_time']) ? gmdate('Y-m-d H:i:s', (int) $cache_info['start_time']) . ' UTC' : '—',
        );
    }

    private static function clear_apcu_user_cache($include_stats = true)
    {
        if (!function_exists('apcu_clear_cache')) {
            return array(
                'success' => false,
                'message' => __('APCu clear cache is unavailable on this server.', 'ultracache'),
            );
        }

        if (function_exists('apcu_enabled') && !apcu_enabled()) {
            return array(
                'success' => false,
                'message' => __('APCu is loaded but disabled for the current PHP SAPI.', 'ultracache'),
            );
        }

        $success = (bool) @apcu_clear_cache();
        $response = array(
            'success' => $success,
            'message' => $success ? __('APCu user cache flushed successfully.', 'ultracache') : __('APCu user cache flush failed.', 'ultracache'),
        );

        if ($include_stats && method_exists(__CLASS__, 'get_engine_stats')) {
            $response['stats'] = self::get_engine_stats();
        }

        return $response;
    }

    public static function flush_apcu()
    {
        return self::clear_apcu_user_cache(true);
    }

    private static function get_external_cache_detection_option_key()
    {
        return 'ultracache_external_cache_detection';
    }

    public static function get_external_cache_detection($force = false)
    {
        if (!$force) {
            $saved = get_option(self::get_external_cache_detection_option_key(), array());
            if (is_array($saved) && !empty($saved['layers']) && !empty($saved['detectedAt']) && !empty($saved['schemaVersion']) && (int) $saved['schemaVersion'] >= 9) {
                if (isset($saved['layers']['litespeed']) && is_array($saved['layers']['litespeed'])) {
                    if (method_exists(__CLASS__, 'get_litespeed_metrics_status')) {
                        $saved['layers']['litespeed']['metrics'] = self::get_litespeed_metrics_status();
                    }
                    $litespeed_settings = self::get_dashboard_settings();
                    $saved['layers']['litespeed']['stalePurgeEnabled'] = self::is_litespeed_stale_invalidation_enabled($litespeed_settings);
                }
                return $saved;
            }
        }

        return self::redetect_external_caches();
    }

    public static function redetect_external_caches()
    {
        $opcache = self::get_opcache_status_summary();
        $apcu = self::get_apcu_status_summary();
        $server_software = '';
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $server_software = sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']));
        }
        $reverse_proxy = method_exists(__CLASS__, 'get_reverse_proxy_status') ? self::get_reverse_proxy_status() : array();
        $litespeed_origin_probe = method_exists(__CLASS__, 'probe_litespeed_origin')
            ? self::probe_litespeed_origin()
            : array();
        $litespeed = method_exists(__CLASS__, 'get_litespeed_diagnostics_status')
            ? self::get_litespeed_diagnostics_status(false)
            : self::get_litespeed_transport_status($server_software, $reverse_proxy);
        if (!empty($litespeed_origin_probe)) {
            $litespeed['originProbe'] = $litespeed_origin_probe;
        }

        $nginx_action = function_exists('has_action') && has_action('rt_nginx_helper_purge_all');
        $nginx_class = class_exists('Nginx_Helper') || class_exists('Nginx_Helper_Admin');
        $nginx_server = false !== stripos($server_software, 'nginx');
        $nginx_cache_integration = $nginx_action || $nginx_class;
        $nginx_detected = (bool) $nginx_cache_integration;
        $nginx_flushable = (bool) $nginx_action;
        $nginx_method = $nginx_action ? 'do_action(rt_nginx_helper_purge_all)' : ($nginx_server ? 'nginx_server_detected_no_cache_purge_hook' : 'not_detected');

        $varnish_settings = method_exists(__CLASS__, 'get_varnish_cli_settings') ? self::get_varnish_cli_settings() : array();
        $varnish_detected = !empty($varnish_settings['enabled']) && !empty($varnish_settings['endpointCount']);
        $varnish_flushable = $varnish_detected;
        $varnish_method = !empty($varnish_settings['effectiveMethod']) ? (string) $varnish_settings['effectiveMethod'] : 'configured_ultracache_varnish';
        $elementor = method_exists(__CLASS__, 'get_elementor_cache_status')
            ? self::get_elementor_cache_status()
            : array(
                'label' => __('Elementor Cache', 'ultracache'),
                'detected' => false,
                'flushable' => false,
                'enabled' => false,
                'method' => 'unavailable',
                'message' => __('Elementor was not detected.', 'ultracache'),
            );

        $detection = array(
            'success'    => true,
            'schemaVersion' => 9,
            'detectedAt' => time(),
            'detectedAtHuman' => gmdate('Y-m-d H:i:s') . ' UTC',
            'serverSoftware' => $server_software,
            'layers' => array(
                'opcache' => array(
                    'label' => __('OPcache', 'ultracache'),
                    'detected' => !empty($opcache['available']) && !empty($opcache['enabled']),
                    'flushable' => function_exists('opcache_reset') && !empty($opcache['available']) && !empty($opcache['enabled']),
                    'enabled' => !empty($opcache['enabled']),
                    'method' => function_exists('opcache_reset') ? 'opcache_reset' : 'unavailable',
                    'message' => isset($opcache['message']) ? (string) $opcache['message'] : '',
                ),
                'apcu' => array(
                    'label' => __('APCu', 'ultracache'),
                    'detected' => !empty($apcu['available']) && !empty($apcu['enabled']),
                    'flushable' => function_exists('apcu_clear_cache') && !empty($apcu['available']) && !empty($apcu['enabled']),
                    'enabled' => !empty($apcu['enabled']),
                    'method' => function_exists('apcu_clear_cache') ? 'apcu_clear_cache' : 'unavailable',
                    'message' => isset($apcu['message']) ? (string) $apcu['message'] : '',
                ),
                'litespeed' => array_merge(
                    array('label' => __('LiteSpeed Cache', 'ultracache')),
                    $litespeed
                ),
                'nginx' => array(
                    'label' => __('Nginx Cache', 'ultracache'),
                    'detected' => (bool) $nginx_detected,
                    'flushable' => (bool) $nginx_flushable,
                    'enabled' => (bool) $nginx_detected,
                    'method' => $nginx_method,
                    'message' => $nginx_flushable ? __('Nginx Helper purge hook detected.', 'ultracache') : ($nginx_detected ? __('Nginx was detected, but no safe purge hook/endpoint is configured.', 'ultracache') : __('Nginx Cache was not detected.', 'ultracache')),
                ),
                'varnish' => array(
                    'label' => __('Varnish Cache', 'ultracache'),
                    'detected' => (bool) $varnish_detected,
                    'flushable' => (bool) $varnish_flushable,
                    'enabled' => !empty($varnish_settings['enabled']),
                    'method' => $varnish_method,
                    'message' => $varnish_flushable ? __('UltraCache Varnish endpoint is configured.', 'ultracache') : ($varnish_detected ? __('Varnish settings exist, but flushing is not enabled/configured.', 'ultracache') : __('Varnish Cache was not detected.', 'ultracache')),
                ),
                'elementor' => $elementor,
            ),
        );

        update_option(self::get_external_cache_detection_option_key(), $detection, false);
        return $detection;
    }

    public static function flush_litespeed_cache()
    {
        $server_software = '';
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $server_software = sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']));
        }
        $reverse_proxy = method_exists(__CLASS__, 'get_reverse_proxy_status') ? self::get_reverse_proxy_status() : array();
        $status = self::get_litespeed_transport_status($server_software, $reverse_proxy);

        $native_enabled = method_exists(__CLASS__, 'is_native_litespeed_html_cache_enabled')
            && self::is_native_litespeed_html_cache_enabled();
        if (!$native_enabled && empty($status['flushable'])) {
            return array(
                'success' => false,
                'message' => __('LiteSpeed Cache purge is not available.', 'ultracache'),
                'externalCaches' => self::get_external_cache_detection(true),
            );
        }

        $result = $native_enabled && method_exists(__CLASS__, 'dispatch_litespeed_site_purge')
            ? self::dispatch_litespeed_site_purge($status)
            : self::dispatch_litespeed_purge_all($status);
        if (method_exists(static::class, 'record_litespeed_purge_result')) {
            self::record_litespeed_purge_result('site', $result, 1, $native_enabled ? 'manual-native-flush' : 'manual-integration-flush');
        }
        $result['externalCaches'] = self::get_external_cache_detection(true);

        return $result;
    }

    public static function flush_nginx_cache()
    {
        $detection = self::get_external_cache_detection(false);
        $layer = isset($detection['layers']['nginx']) && is_array($detection['layers']['nginx']) ? $detection['layers']['nginx'] : array();
        if (empty($layer['flushable'])) {
            return array('success' => false, 'message' => __('Nginx Cache purge is not available. Configure Nginx Helper or a safe purge integration first.', 'ultracache'), 'externalCaches' => $detection);
        }

        $success = false;
        $method = 'unknown';
        if (function_exists('has_action') && has_action('rt_nginx_helper_purge_all')) {
            $method = 'do_action(rt_nginx_helper_purge_all)';
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- External Nginx Helper hook intentionally invoked for interoperability.
            do_action('rt_nginx_helper_purge_all');
            $success = true;
        }

        return array(
            'success' => (bool) $success,
            'message' => $success ? __('Nginx Cache flush triggered.', 'ultracache') : __('Nginx Cache flush failed.', 'ultracache'),
            'method' => $method,
            'externalCaches' => self::get_external_cache_detection(true),
        );
    }

    public static function maybe_flush_external_caches_after_purge()
    {
        $settings = self::get_dashboard_settings();
        $detection = self::get_external_cache_detection(false);
        $layers = isset($detection['layers']) && is_array($detection['layers']) ? $detection['layers'] : array();
        $results = array();

        if (!empty($settings['flushAllIncludeOpcache']) && !empty($layers['opcache']['flushable'])) {
            $results['opcache'] = self::flush_opcache();
        } else {
            $results['opcache'] = array('success' => true, 'skipped' => true, 'message' => empty($settings['flushAllIncludeOpcache']) ? __('Skipped by setting.', 'ultracache') : __('Not detected/flushable.', 'ultracache'));
        }

        if (!empty($settings['flushAllIncludeApcu']) && !empty($layers['apcu']['flushable'])) {
            $results['apcu'] = self::flush_apcu();
        } else {
            $results['apcu'] = array('success' => true, 'skipped' => true, 'message' => empty($settings['flushAllIncludeApcu']) ? __('Skipped by setting.', 'ultracache') : __('Not detected/flushable.', 'ultracache'));
        }

        $native_litespeed_enabled = method_exists(__CLASS__, 'is_native_litespeed_html_cache_enabled')
            && self::is_native_litespeed_html_cache_enabled();
        if ($native_litespeed_enabled) {
            $results['litespeed'] = array(
                'success' => true,
                'handled' => true,
                'message' => __('Handled by the native LiteSpeed public site-cache purge hook.', 'ultracache'),
            );
        } elseif (!empty($settings['flushAllIncludeLiteSpeed']) && !empty($layers['litespeed']['flushable'])) {
            $results['litespeed'] = self::flush_litespeed_cache();
        } else {
            $results['litespeed'] = array('success' => true, 'skipped' => true, 'message' => empty($settings['flushAllIncludeLiteSpeed']) ? __('Skipped by setting.', 'ultracache') : __('Not detected/flushable.', 'ultracache'));
        }

        if (!empty($settings['flushAllIncludeNginx']) && !empty($layers['nginx']['flushable'])) {
            $results['nginx'] = self::flush_nginx_cache();
        } else {
            $results['nginx'] = array('success' => true, 'skipped' => true, 'message' => empty($settings['flushAllIncludeNginx']) ? __('Skipped by setting.', 'ultracache') : __('Not detected/flushable.', 'ultracache'));
        }

        if (!empty($settings['flushAllIncludeVarnish']) && !empty($layers['varnish']['flushable'])) {
            $results['varnish'] = array('success' => true, 'handled' => true, 'message' => __('Handled by the Flush All Cache purge hook.', 'ultracache'));
        } else {
            $results['varnish'] = array('success' => true, 'skipped' => true, 'message' => empty($settings['flushAllIncludeVarnish']) ? __('Skipped by setting.', 'ultracache') : __('Not detected/flushable.', 'ultracache'));
        }

        $elementor_result = method_exists(__CLASS__, 'get_elementor_flush_all_result')
            ? self::get_elementor_flush_all_result()
            : null;
        if (is_array($elementor_result)) {
            $results['elementor'] = $elementor_result;
        } else {
            $results['elementor'] = array(
                'success' => true,
                'skipped' => true,
                'message' => empty($settings['flushAllIncludeElementor']) ? __('Skipped by setting.', 'ultracache') : __('Not detected/flushable.', 'ultracache'),
            );
        }

        return array(
            'success' => true,
            'results' => $results,
            'externalCaches' => self::get_external_cache_detection(true),
        );
    }

    public static function get_engine_stats($full_object_count = false, $force = false, $include_diagnostics = false)
    {
        if (!$force && method_exists(__CLASS__, 'are_cache_stats_enabled') && !self::are_cache_stats_enabled()) {
            return self::get_cache_stats_disabled_payload('engine_stats_disabled');
        }

        $stats = array();
        $candidates = array('Ultra_Cache_Engine');
        foreach ($candidates as $class) {
            if (class_exists($class) && method_exists($class, 'get_stats')) {
                $stats = call_user_func(array($class, 'get_stats'));
                $stats = is_array($stats) ? $stats : array();
                break;
            }
        }

        if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'get_stats')) {
            $object_stats = Ultra_Cache_Object_Cache_Manager::get_stats((bool) $full_object_count);
            if (is_array($object_stats)) {
                $stats = array_merge($stats, $object_stats);
                $stats['cacheSizeBytes'] = (int) ($stats['cacheSizeBytes'] ?? 0) + (int) ($object_stats['objectCacheSizeBytes'] ?? 0);
                if (function_exists('size_format')) {
                    $stats['cacheSizeHuman'] = size_format((int) $stats['cacheSizeBytes'], 2);
                }
            }
        }

        $stats['cronWarm'] = self::get_cron_warm_status();
        $stats['opcache'] = self::get_opcache_status_summary();
        $stats['apcu'] = self::get_apcu_status_summary();
        $stats['externalCaches'] = self::get_external_cache_detection(false);
        $stats['dashboardStatsLightweight'] = true;
        $stats['dashboardDiagnosticsIncluded'] = false;

        if ($include_diagnostics) {
            $stats['diagnostics'] = self::get_dashboard_diagnostics();
            $stats['dashboardDiagnosticsIncluded'] = true;
        }

        return $stats;
    }

    private static function get_compression_support_status()
    {
        $brotli    = function_exists('brotli_compress');
        $gzip      = function_exists('gzencode');
        $preferred = $brotli ? 'brotli' : ($gzip ? 'gzip' : 'none');
        $message   = self::maybe_translate('No PHP compression support detected on this server.');

        if ($brotli && $gzip) {
            $message = self::maybe_translate('Brotli and gzip are available. UltraCache will prefer Brotli and fall back to gzip when needed.');
        } elseif ($brotli) {
            $message = self::maybe_translate('Brotli is available on this server. UltraCache will prefer Brotli compression.');
        } elseif ($gzip) {
            $message = self::maybe_translate('Brotli is not available on this server. UltraCache will use gzip compression instead.');
        }

        return array(
            'brotli'    => $brotli,
            'gzip'      => $gzip,
            'preferred' => $preferred,
            'message'   => $message,
        );
    }

    private static function get_frontend_compression_probe_state_name()
    {
        return 'ultracache_state:runtime.frontend_compression_probe';
    }

    private static function get_frontend_compression_probe_fingerprint()
    {
        $payload = array(
            'schema' => 1,
            'siteOrigin' => function_exists('ultracache_get_configured_site_origin') ? ultracache_get_configured_site_origin() : '',
            'phpVersion' => PHP_VERSION,
            'brotliExtension' => function_exists('brotli_compress') ? (string) phpversion('brotli') : '',
            'zlibExtension' => function_exists('gzencode') ? (string) phpversion('zlib') : '',
            'contractVersion' => 1,
        );

        return substr(hash('sha256', (string) wp_json_encode($payload)), 0, 24);
    }

    private static function get_default_frontend_compression_probe_status()
    {
        return array(
            'detected'      => false,
            'gzip'          => false,
            'brotli'        => false,
            'brokenGzip'    => false,
            'brokenBrotli'  => false,
            'message'       => '',
            'cachedOnly'    => false,
            'liveProbe'     => false,
            'gzipProbeCompleted' => false,
            'brotliProbeCompleted' => false,
            'probeComplete' => false,
            'requestedUrl'  => '',
            'resolvedUrl'   => '',
            'requestedLanguage' => '',
            'resolvedLanguage' => '',
            'redirected'    => false,
            'redirectCount' => 0,
            'resolutionError' => '',
            'probeResults'  => array(),
            'testedAt'      => 0,
            'fingerprint'   => self::get_frontend_compression_probe_fingerprint(),
            'diagnosticStatus' => 'not-tested',
            'configurationChanged' => false,
            'stale' => false,
            'ageSeconds' => 0,
        );
    }

    private static function read_frontend_compression_probe_status()
    {
        $default = self::get_default_frontend_compression_probe_status();
        if (!function_exists('ultracache_get_state_record_read_only')) {
            return $default;
        }

        $record = ultracache_get_state_record_read_only(self::get_frontend_compression_probe_state_name());
        $payload = is_array($record['payload'] ?? null) ? $record['payload'] : array();
        $stored = is_array($payload['status'] ?? null) ? $payload['status'] : array();
        if (empty($stored)) {
            return $default;
        }

        $status = array_merge($default, $stored);
        $status['testedAt'] = max(0, (int) ($status['testedAt'] ?? ($payload['recordedAt'] ?? 0)));
        $status['fingerprint'] = sanitize_text_field((string) ($status['fingerprint'] ?? ($payload['fingerprint'] ?? '')));
        $status['requestedLanguage'] = function_exists('ultracache_multilingual_normalize_language_code')
            ? ultracache_multilingual_normalize_language_code($status['requestedLanguage'] ?? '')
            : sanitize_key((string) ($status['requestedLanguage'] ?? ''));
        $status['resolvedLanguage'] = function_exists('ultracache_multilingual_normalize_language_code')
            ? ultracache_multilingual_normalize_language_code($status['resolvedLanguage'] ?? '')
            : sanitize_key((string) ($status['resolvedLanguage'] ?? ''));
        $status['ageSeconds'] = $status['testedAt'] > 0 ? max(0, time() - $status['testedAt']) : 0;
        $status['configurationChanged'] = !hash_equals(
            (string) self::get_frontend_compression_probe_fingerprint(),
            (string) $status['fingerprint']
        );
        $status['stale'] = $status['testedAt'] > 0 && $status['ageSeconds'] > DAY_IN_SECONDS;
        $status['diagnosticStatus'] = $status['configurationChanged']
            ? 'configuration-changed'
            : ($status['stale'] ? 'stale' : 'current');
        $status['cachedOnly'] = true;
        $status['liveProbe'] = false;

        return $status;
    }

    private static function persist_frontend_compression_probe_status(array $status)
    {
        if (!function_exists('ultracache_mutate_state_record')) {
            return false;
        }

        $status = array_merge(self::get_default_frontend_compression_probe_status(), $status);
        $status['testedAt'] = max(0, (int) ($status['testedAt'] ?? time()));
        $status['fingerprint'] = self::get_frontend_compression_probe_fingerprint();
        $status['requestedLanguage'] = function_exists('ultracache_multilingual_normalize_language_code')
            ? ultracache_multilingual_normalize_language_code($status['requestedLanguage'] ?? '')
            : sanitize_key((string) ($status['requestedLanguage'] ?? ''));
        $status['resolvedLanguage'] = function_exists('ultracache_multilingual_normalize_language_code')
            ? ultracache_multilingual_normalize_language_code($status['resolvedLanguage'] ?? '')
            : sanitize_key((string) ($status['resolvedLanguage'] ?? ''));
        $status['diagnosticStatus'] = 'current';
        $status['configurationChanged'] = false;
        $status['stale'] = false;
        $status['ageSeconds'] = 0;
        $status['cachedOnly'] = false;
        $status['liveProbe'] = true;

        $mutation = ultracache_mutate_state_record(
            self::get_frontend_compression_probe_state_name(),
            static function () use ($status) {
                return array(
                    'schemaVersion' => 1,
                    'recordedAt' => (int) $status['testedAt'],
                    'fingerprint' => (string) $status['fingerprint'],
                    'status' => $status,
                );
            },
            5,
            array()
        );

        return !empty($mutation['success']);
    }

    private static function create_compression_probe_token()
    {
        try {
            $nonce = bin2hex(random_bytes(16));
        } catch (Throwable $error) {
            unset($error);
            $nonce = substr(hash('sha256', wp_generate_uuid4() . '|' . microtime(true)), 0, 32);
        }

        $payload = time() . '.' . $nonce;
        return $payload . '.' . ultracache_internal_sign('compression-probe', $payload);
    }

    private static function claim_compression_probe_token($token)
    {
        $token = trim((string) $token);
        if (1 !== preg_match('/\A([0-9]{10})\.([a-f0-9]{32})\.([a-f0-9]{64})\z/', $token, $matches)) {
            return false;
        }

        $issued_at = (int) $matches[1];
        $now = time();
        if ($issued_at > ($now + 5) || ($now - $issued_at) > 120) {
            return false;
        }

        $payload = $matches[1] . '.' . $matches[2];
        if (!ultracache_internal_verify('compression-probe', $payload, (string) $matches[3]) || !function_exists('ultracache_acquire_lock')) {
            return false;
        }

        $token_hash = hash('sha256', $token);
        return ultracache_acquire_lock(
            'ultracache_compression_probe_replay_' . substr($token_hash, 0, 40),
            $token_hash,
            180,
            array(
                'issuedAt' => $issued_at,
                'claimedAt' => $now,
                'purpose' => 'frontend-compression-probe',
            )
        );
    }

    private static function get_frontend_compression_probe_status($allow_live_probe = false, $force_refresh = false)
    {
        $stored = self::read_frontend_compression_probe_status();
        $current_reusable = 'current' === (string) ($stored['diagnosticStatus'] ?? '')
            && max(0, (int) ($stored['ageSeconds'] ?? 0)) <= 5 * MINUTE_IN_SECONDS;

        if (!$force_refresh && $current_reusable) {
            return $stored;
        }

        if (!$allow_live_probe) {
            if ('not-tested' === (string) ($stored['diagnosticStatus'] ?? 'not-tested')) {
                $stored['message'] = 'Frontend compression probe has not run yet. Live loopback probing is skipped during normal settings sanitization.';
            }
            $stored['cachedOnly'] = true;
            return $stored;
        }

        $status = self::get_default_frontend_compression_probe_status();
        $status['liveProbe'] = true;
        $status['testedAt'] = time();

        $probe_requested_url = esc_url_raw(home_url('/'));
        $status['requestedUrl'] = $probe_requested_url;
        $status['requestedLanguage'] = function_exists('ultracache_multilingual_get_public_url_language')
            ? ultracache_multilingual_get_public_url_language($probe_requested_url)
            : '';
        $resolution = function_exists('ultracache_resolve_anonymous_frontend_url')
            ? ultracache_resolve_anonymous_frontend_url($probe_requested_url, 'compression')
            : array(
                'success' => '' !== $probe_requested_url,
                'requestedUrl' => $probe_requested_url,
                'resolvedUrl' => $probe_requested_url,
                'httpCode' => 200,
                'redirected' => false,
                'redirectCount' => 0,
            );

        if (is_wp_error($resolution)) {
            $status['resolutionError'] = sanitize_key((string) $resolution->get_error_code());
            $status['message'] = 'The live HTML compression probe could not resolve the anonymous frontend URL: ' . sanitize_text_field((string) $resolution->get_error_message());
            self::persist_frontend_compression_probe_status($status);
            return $status;
        }

        $probe_base = esc_url_raw((string) ($resolution['resolvedUrl'] ?? ''));
        $status['resolvedUrl'] = $probe_base;
        $status['resolvedLanguage'] = function_exists('ultracache_multilingual_get_public_url_language')
            ? ultracache_multilingual_get_public_url_language($probe_base)
            : '';
        $status['redirected'] = !empty($resolution['redirected']);
        $status['redirectCount'] = max(0, (int) ($resolution['redirectCount'] ?? 0));
        if (empty($resolution['success']) || '' === $probe_base) {
            $http_code = max(0, (int) ($resolution['httpCode'] ?? 0));
            $status['resolutionError'] = 'frontend_target_http_status';
            $status['message'] = $http_code > 0
                ? 'The live HTML compression probe resolved the anonymous frontend URL, but that target returned HTTP ' . $http_code . '.'
                : 'The live HTML compression probe could not resolve a usable anonymous frontend URL.';
            self::persist_frontend_compression_probe_status($status);
            return $status;
        }

        $encodings = array(
            'brotli' => 'br',
            'gzip'   => 'gzip',
        );
        $request_timeout = function_exists('ultracache_get_php_max_execution_time_seconds')
            ? ultracache_get_php_max_execution_time_seconds()
            : max(0, (int) ini_get('max_execution_time'));
        foreach ($encodings as $bucket => $accept_encoding) {
            $probe_token = self::create_compression_probe_token();
            $probe_url = add_query_arg('ultracache_probe_compression', $probe_token, $probe_base);
            $status['probeResults'][$bucket] = array(
                'completed' => false,
                'httpCode' => 0,
                'errorCode' => '',
                'message' => '',
                'probeHeader' => '',
                'contentEncoding' => '',
            );

            $response = ultracache_safe_loopback_remote_request($probe_url, array(
                'method'              => 'GET',
                'timeout'             => $request_timeout,
                'redirection'         => 0,
                'decompress'          => false,
                'limit_response_size' => 128,
                'headers'             => array(
                    'Cache-Control'   => 'no-cache',
                    'Pragma'          => 'no-cache',
                    'Accept-Encoding' => $accept_encoding,
                ),
            ), 'frontend_compression_probe');

            if (is_wp_error($response)) {
                $status['probeResults'][$bucket]['errorCode'] = sanitize_key((string) $response->get_error_code());
                $status['probeResults'][$bucket]['message'] = sanitize_text_field((string) $response->get_error_message());
                continue;
            }

            $probe_http_code = (int) wp_remote_retrieve_response_code($response);
            $status['probeResults'][$bucket]['httpCode'] = $probe_http_code;
            if (200 !== $probe_http_code) {
                $status['probeResults'][$bucket]['message'] = 'Probe request returned HTTP ' . $probe_http_code . '.';
                continue;
            }

            $headers = wp_remote_retrieve_headers($response);
            $probe_header = trim((string) ($headers['x-ultracache-compression-probe'] ?? ''));
            $status['probeResults'][$bucket]['probeHeader'] = sanitize_text_field($probe_header);
            if ('1' !== $probe_header) {
                $status['probeResults'][$bucket]['message'] = 'Compression probe marker header was not observed.';
                continue;
            }

            if ('brotli' === $bucket) {
                $status['brotliProbeCompleted'] = true;
            } elseif ('gzip' === $bucket) {
                $status['gzipProbeCompleted'] = true;
            }

            $status['probeResults'][$bucket]['completed'] = true;
            $content_encoding = strtolower(trim((string) ($headers['content-encoding'] ?? '')));
            $status['probeResults'][$bucket]['contentEncoding'] = sanitize_text_field($content_encoding);
            $body = (string) wp_remote_retrieve_body($response);
            $gzip_magic = (strlen($body) >= 2 && 0x1f === ord($body[0]) && 0x8b === ord($body[1]));

            if ('brotli' === $bucket && false !== strpos($content_encoding, 'br')) {
                $status['brotli'] = true;
                $status['detected'] = true;
                continue;
            }

            if ('gzip' === $bucket && false !== strpos($content_encoding, 'gzip')) {
                $status['gzip'] = true;
                $status['detected'] = true;
                continue;
            }

            if ('gzip' === $bucket && $gzip_magic) {
                $status['brokenGzip'] = true;
                $status['detected'] = true;
            }
        }

        $status['probeComplete'] = !empty($status['gzipProbeCompleted']) && !empty($status['brotliProbeCompleted']);

        if ($status['brokenGzip']) {
            $status['message'] = 'UltraCache detected gzip-compressed output without a matching Content-Encoding header. Gzip has been disabled as a safety measure.';
        } elseif ($status['brotli'] && $status['gzip']) {
            $status['message'] = 'Your server is already using Brotli and gzip compression by default.';
        } elseif ($status['brotli']) {
            $status['message'] = 'Your server is already using Brotli compression by default.';
        } elseif ($status['gzip']) {
            $status['message'] = 'Your server is already using gzip compression by default.';
        } elseif (empty($status['probeComplete'])) {
            $status['message'] = 'The live HTML compression probe did not complete for both gzip and Brotli requests, so UltraCache cannot safely choose a compression mode automatically.';
        }

        self::persist_frontend_compression_probe_status($status);
        return $status;
    }

    public static function get_html_compression_capability_probe($force_refresh = false)
    {
        $support = self::get_compression_support_status();
        $frontend = self::get_frontend_compression_probe_status(true, (bool) $force_refresh);
        $server_managed = !empty($frontend['gzip']) || !empty($frontend['brotli']);
        $probe_complete = !empty($frontend['probeComplete'])
            || (!empty($frontend['gzipProbeCompleted']) && !empty($frontend['brotliProbeCompleted']));
        $indeterminate = !$server_managed
            && empty($frontend['brokenGzip'])
            && empty($frontend['brokenBrotli'])
            && !$probe_complete;
        $blocked = $server_managed || !empty($frontend['brokenGzip']) || !empty($frontend['brokenBrotli']) || $indeterminate;

        if ($server_managed) {
            $message = isset($frontend['message']) && '' !== (string) $frontend['message']
                ? (string) $frontend['message']
                : self::maybe_translate('Server-managed HTML compression is active.');
        } elseif ($blocked && !empty($frontend['message'])) {
            $message = (string) $frontend['message'];
        } elseif ($indeterminate) {
            $message = self::maybe_translate('The live HTML compression probe did not complete, so UltraCache did not change the compression mode.');
        } elseif (!empty($support['brotli']) && !empty($support['gzip'])) {
            $message = self::maybe_translate('Gzip and Brotli compression are available.');
        } elseif (!empty($support['brotli'])) {
            $message = self::maybe_translate('Brotli compression is available.');
        } elseif (!empty($support['gzip'])) {
            $message = self::maybe_translate('Gzip compression is available.');
        } else {
            $message = self::maybe_translate('No supported HTML compression encoder is available.');
        }

        return array(
            'serverManaged'   => $server_managed,
            'serverGzip'      => !empty($frontend['gzip']),
            'serverBrotli'    => !empty($frontend['brotli']),
            'gzipAvailable'   => !empty($support['gzip']),
            'brotliAvailable' => !empty($support['brotli']),
            'blocked'         => $blocked,
            'probeComplete'   => $probe_complete,
            'indeterminate'   => $indeterminate,
            'gzipProbeCompleted' => !empty($frontend['gzipProbeCompleted']),
            'brotliProbeCompleted' => !empty($frontend['brotliProbeCompleted']),
            'brokenGzip'      => !empty($frontend['brokenGzip']),
            'brokenBrotli'    => !empty($frontend['brokenBrotli']),
            'requestedUrl'    => esc_url_raw((string) ($frontend['requestedUrl'] ?? '')),
            'resolvedUrl'     => esc_url_raw((string) ($frontend['resolvedUrl'] ?? '')),
            'redirected'      => !empty($frontend['redirected']),
            'redirectCount'   => max(0, (int) ($frontend['redirectCount'] ?? 0)),
            'resolutionError' => sanitize_key((string) ($frontend['resolutionError'] ?? '')),
            'probeResults'    => is_array($frontend['probeResults'] ?? null) ? $frontend['probeResults'] : array(),
            'message'         => $message,
        );
    }

    private static function get_wp_cache_define_status()
    {
        $config = self::get_wp_config_path();
        $filesystem = ultracache_get_wp_filesystem();
        if (!$config || !$filesystem || !method_exists($filesystem, 'get_contents')) {
            return array(
                'status'  => 'missing-config',
                'message' => self::maybe_translate('wp-config.php could not be located.'),
            );
        }

        $contents = $filesystem->get_contents($config);
        if (!is_string($contents) || '' === $contents) {
            return array(
                'status'  => 'read-failed',
                'message' => self::maybe_translate('wp-config.php could not be read.'),
            );
        }

        $inventory = self::get_wp_config_constant_inventory($contents);
        if (!empty($inventory['managed_matches']['WP_CACHE'])) {
            return array(
                'status'  => 'managed',
                'message' => self::maybe_translate('WP_CACHE is managed by UltraCache.'),
            );
        }

        $external_status = isset($inventory['external_wp_cache_status'])
            ? (string) $inventory['external_wp_cache_status']
            : 'missing';

        if ('true' === $external_status) {
            return array(
                'status'  => 'true',
                'message' => self::maybe_translate('WP_CACHE is already defined as true outside the UltraCache managed block.'),
            );
        }

        if ('false' === $external_status) {
            return array(
                'status'  => 'false',
                'message' => self::maybe_translate('WP_CACHE is defined as false outside the UltraCache managed block and must be changed manually before page cache can be enabled.'),
            );
        }

        if ('other' === $external_status) {
            return array(
                'status'  => 'nonstandard',
                'message' => self::maybe_translate('WP_CACHE is defined outside the UltraCache managed block in a non-standard way and must be changed manually before page cache can be enabled.'),
            );
        }

        return array(
            'status'  => 'missing',
            'message' => self::maybe_translate('WP_CACHE is not currently defined in wp-config.php. UltraCache can add it to its managed block.'),
        );
    }


    public static function test_redis_connection(array $settings_override = array())
    {
        if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'test_redis_connection')) {
            return Ultra_Cache_Object_Cache_Manager::test_redis_connection($settings_override);
        }

        return array(
            'success' => false,
            'connected' => false,
            'message' => self::maybe_translate('Redis helper not available.'),
        );
    }

    public static function test_redis_read_write(array $settings_override = array())
    {
        if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'test_redis_read_write')) {
            return Ultra_Cache_Object_Cache_Manager::test_redis_read_write($settings_override);
        }

        return array(
            'success'   => false,
            'connected' => false,
            'readWrite' => false,
            'message'   => self::maybe_translate('Redis read/write validation helper not available.'),
        );
    }

    public static function test_object_cache_backend($backend = 'selected', array $settings_override = array())
    {
        if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'test_object_cache_backend')) {
            return Ultra_Cache_Object_Cache_Manager::test_object_cache_backend($backend, $settings_override);
        }

        return array(
            'success' => false,
            'message' => self::maybe_translate('Object cache backend test helper not available.'),
        );
    }
    public static function flush_object_cache($backend = 'active')
    {
        if (!class_exists('Ultra_Cache_Object_Cache_Manager') || (!method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache') && !method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache_with_report'))) {
            return array(
                'success' => false,
                'message' => self::maybe_translate('Object cache helper not available.'),
            );
        }

        $report = array(
            'success' => false,
            'message' => __('Object cache flush failed.', 'ultracache'),
        );

        try {
            if (function_exists('wp_suspend_cache_addition')) {
                wp_suspend_cache_addition(true);
            }

            if (method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache_with_report')) {
                $report = Ultra_Cache_Object_Cache_Manager::flush_cache_with_report(true, false, $backend);
            } else {
                $flushed = (bool) Ultra_Cache_Object_Cache_Manager::flush_cache(true, false);
                $report = array(
                    'success' => $flushed,
                    'message' => $flushed ? __('Object cache flushed.', 'ultracache') : __('Object cache flush failed.', 'ultracache'),
                );
            }

            if (method_exists('Ultra_Cache_Object_Cache_Manager', 'reset_metrics')) {
                Ultra_Cache_Object_Cache_Manager::reset_metrics();
            }
        } finally {
            if (function_exists('wp_suspend_cache_addition')) {
                wp_suspend_cache_addition(false);
            }
        }

        return is_array($report) ? $report : array(
            'success' => false,
            'message' => __('Object cache flush failed.', 'ultracache'),
        );
    }



    private static function get_media_support_status()
    {
        $media = self::get_media_instance();
        if ($media && method_exists($media, 'get_support_status')) {
            $status = $media->get_support_status();
            return is_array($status) ? $status : array('supported' => false);
        }

        return array('supported' => false);
    }


    public function maybe_serve_html_compression_probe()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signed, short-lived, one-time capability challenge validated before output.
        $token = isset($_GET['ultracache_probe_compression']) ? sanitize_text_field(wp_unslash($_GET['ultracache_probe_compression'])) : '';
        if ('' === $token || !self::claim_compression_probe_token($token)) {
            return;
        }

        if (!headers_sent()) {
            status_header(200);
            nocache_headers();
            header('Content-Type: text/html; charset=UTF-8');
            header('X-UltraCache-Compression-Probe: 1');
            header('Vary: Accept-Encoding', false);
        }

        echo esc_html(str_repeat('UltraCache compression capability probe. ', 512));
        exit;
    }

}
