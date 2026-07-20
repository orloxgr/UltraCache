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
            if (is_array($saved) && !empty($saved['layers']) && !empty($saved['detectedAt']) && !empty($saved['schemaVersion']) && (int) $saved['schemaVersion'] >= 3) {
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
        $reverse_server = isset($reverse_proxy['server']) ? (string) $reverse_proxy['server'] : '';
        $reverse_x_litespeed_cache = isset($reverse_proxy['x_litespeed_cache']) ? (string) $reverse_proxy['x_litespeed_cache'] : '';
        $reverse_x_qc_cache = isset($reverse_proxy['x_qc_cache']) ? (string) $reverse_proxy['x_qc_cache'] : '';

        $litespeed_class = class_exists('LiteSpeed_Cache_API');
        $litespeed_class_purge = $litespeed_class && method_exists('LiteSpeed_Cache_API', 'purge_all');
        $litespeed_namespaced_purge = class_exists('\LiteSpeed\Purge') && method_exists('\LiteSpeed\Purge', 'purge_all');
        $litespeed_action = function_exists('has_action') && has_action('litespeed_purge_all');
        $litespeed_function = function_exists('litespeed_purge_all');
        $litespeed_defined = defined('LSCWP_V') || defined('LITESPEED_STATIC_DIR');
        $litespeed_server = false !== stripos($server_software, 'LiteSpeed')
            || false !== stripos($server_software, 'OpenLiteSpeed')
            || false !== stripos($reverse_server, 'LiteSpeed')
            || false !== stripos($reverse_server, 'OpenLiteSpeed');
        $litespeed_cache_header = '' !== trim($reverse_x_litespeed_cache) || '' !== trim($reverse_x_qc_cache) || (!empty($reverse_proxy['litespeed_cache']));
        $litespeed_server_purge = $litespeed_server || $litespeed_cache_header;
        $litespeed_detected = $litespeed_class || $litespeed_namespaced_purge || $litespeed_action || $litespeed_function || $litespeed_defined || $litespeed_server_purge;
        $litespeed_flushable = $litespeed_class_purge || $litespeed_namespaced_purge || $litespeed_action || $litespeed_function || $litespeed_server_purge;
        if ($litespeed_class_purge) {
            $litespeed_method = 'LiteSpeed_Cache_API::purge_all';
        } elseif ($litespeed_namespaced_purge) {
            $litespeed_method = '\LiteSpeed\Purge::purge_all';
        } elseif ($litespeed_action) {
            $litespeed_method = 'do_action(litespeed_purge_all)';
        } elseif ($litespeed_function) {
            $litespeed_method = 'litespeed_purge_all';
        } elseif ($litespeed_server_purge) {
            $litespeed_method = 'X-LiteSpeed-Purge response header';
        } else {
            $litespeed_method = 'not_detected';
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

        $detection = array(
            'success'    => true,
            'schemaVersion' => 3,
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
                'litespeed' => array(
                    'label' => __('LiteSpeed Cache', 'ultracache'),
                    'detected' => (bool) $litespeed_detected,
                    'flushable' => (bool) $litespeed_flushable,
                    'enabled' => (bool) $litespeed_detected,
                    'method' => $litespeed_method,
                    'serverDetected' => (bool) $litespeed_server,
                    'cacheHeaderDetected' => (bool) $litespeed_cache_header,
                    'pluginDetected' => (bool) ($litespeed_class || $litespeed_namespaced_purge || $litespeed_action || $litespeed_function || $litespeed_defined),
                    'message' => $litespeed_flushable
                        ? ($litespeed_server_purge && !$litespeed_class_purge && !$litespeed_namespaced_purge && !$litespeed_action && !$litespeed_function
                            ? 'LiteSpeed/OpenLiteSpeed detected. UltraCache can request a server-level purge with the X-LiteSpeed-Purge response header; effect depends on LSCache being enabled for this vhost.'
                            : 'LiteSpeed WordPress purge integration detected.')
                        : ($litespeed_detected ? 'LiteSpeed was detected, but no safe purge method is available.' : 'LiteSpeed Cache was not detected.'),
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
            ),
        );

        update_option(self::get_external_cache_detection_option_key(), $detection, false);
        return $detection;
    }

    private static function send_litespeed_purge_header($value = '*')
    {
        $value = is_string($value) ? trim($value) : '*';
        if ('' === $value) {
            $value = '*';
        }

        // Keep the public helper intentionally narrow. UltraCache currently issues full public-cache purge only.
        if ('*' !== $value && !preg_match('/^(?:url|tag|private|public)=[A-Za-z0-9_:\/.,?&=%+~#@!$;*()\[\]\-]+$/', $value)) {
            return array(
                'success' => false,
                'message' => __('Invalid LiteSpeed purge header value.', 'ultracache'),
                'method' => 'X-LiteSpeed-Purge response header',
            );
        }

        if (PHP_SAPI === 'cli') {
            return array(
                'success' => false,
                'message' => __('LiteSpeed server-level purge needs an HTTP response; it cannot be sent from WP-CLI.', 'ultracache'),
                'method' => 'X-LiteSpeed-Purge response header',
            );
        }

        if (headers_sent($file, $line)) {
            return array(
                'success' => false,
                'message' => sprintf(
                    /* translators: 1: PHP file path where headers were sent, 2: line number. */
                    __('LiteSpeed purge header could not be sent because headers were already sent at %1$s:%2$s.', 'ultracache'),
                    (string) $file,
                    (string) $line
                ),
                'method' => 'X-LiteSpeed-Purge response header',
            );
        }

        header('X-LiteSpeed-Purge: ' . $value, false);
        header('X-UltraCache-LiteSpeed-Purge: requested', false);

        return array(
            'success' => true,
            'message' => __('LiteSpeed server-level purge header queued on this HTTP response.', 'ultracache'),
            'method' => 'X-LiteSpeed-Purge response header',
        );
    }

    public static function flush_litespeed_cache()
    {
        $detection = self::get_external_cache_detection(false);
        $layer = isset($detection['layers']['litespeed']) && is_array($detection['layers']['litespeed']) ? $detection['layers']['litespeed'] : array();
        if (empty($layer['flushable'])) {
            return array('success' => false, 'message' => __('LiteSpeed Cache purge is not available.', 'ultracache'), 'externalCaches' => $detection);
        }

        $success = false;
        $method = 'unknown';
        $message = 'LiteSpeed Cache flush failed.';
        if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) {
            $method = 'LiteSpeed_Cache_API::purge_all';
            $result = @LiteSpeed_Cache_API::purge_all();
            $success = (false !== $result);
            $message = $success ? 'LiteSpeed Cache purge triggered through LiteSpeed_Cache_API.' : 'LiteSpeed_Cache_API purge failed.';
        } elseif (class_exists('\LiteSpeed\Purge') && method_exists('\LiteSpeed\Purge', 'purge_all')) {
            $method = '\LiteSpeed\Purge::purge_all';
            $result = @call_user_func(array('\LiteSpeed\Purge', 'purge_all'));
            $success = (false !== $result);
            $message = $success ? 'LiteSpeed Cache purge triggered through \LiteSpeed\Purge.' : '\LiteSpeed\Purge purge failed.';
        } elseif (function_exists('has_action') && has_action('litespeed_purge_all')) {
            $method = 'do_action(litespeed_purge_all)';
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- External LiteSpeed Cache hook intentionally invoked for interoperability.
            do_action('litespeed_purge_all');
            $success = true;
            $message = 'LiteSpeed Cache purge hook triggered.';
        } elseif (function_exists('litespeed_purge_all')) {
            $method = 'litespeed_purge_all';
            $result = @litespeed_purge_all();
            $success = (false !== $result);
            $message = $success ? 'LiteSpeed Cache purge function triggered.' : 'LiteSpeed purge function failed.';
        } elseif (!empty($layer['method']) && 'X-LiteSpeed-Purge response header' === (string) $layer['method']) {
            $header_result = self::send_litespeed_purge_header('*');
            $success = !empty($header_result['success']);
            $method = isset($header_result['method']) ? (string) $header_result['method'] : 'X-LiteSpeed-Purge response header';
            $message = isset($header_result['message']) ? (string) $header_result['message'] : ($success ? 'LiteSpeed server-level purge header queued.' : 'LiteSpeed server-level purge header failed.');
        }

        return array(
            'success' => (bool) $success,
            'message' => $message,
            'method' => $method,
            'externalCaches' => self::get_external_cache_detection(true),
        );
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

        if (!empty($settings['flushAllIncludeLiteSpeed']) && !empty($layers['litespeed']['flushable'])) {
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

    private static function get_frontend_compression_probe_status($allow_live_probe = false)
    {
        $status = array(
            'detected'      => false,
            'gzip'          => false,
            'brotli'        => false,
            'brokenGzip'    => false,
            'brokenBrotli'  => false,
            'message'       => '',
            'cachedOnly'    => false,
            'liveProbe'     => false,
        );

        $cached = get_transient('ultracache_frontend_compression_probe_v1');
        if (is_array($cached)) {
            return array_merge($status, $cached, array('cachedOnly' => true));
        }

        if (!$allow_live_probe) {
            $status['cachedOnly'] = true;
            $status['message'] = 'Frontend compression probe has not run yet. Live loopback probing is skipped during normal settings sanitization.';
            return $status;
        }

        $status['liveProbe'] = true;

        $probe_base = home_url('/');
        if ('' === (string) $probe_base) {
            set_transient('ultracache_frontend_compression_probe_v1', $status, 5 * MINUTE_IN_SECONDS);
            return $status;
        }

        $encodings = array(
            'brotli' => 'br',
            'gzip'   => 'gzip',
        );
        foreach ($encodings as $bucket => $accept_encoding) {
            $probe_token = wp_generate_password(48, false, false);
            $probe_transient = 'ultracache_compression_probe_' . hash('sha256', $probe_token);
            set_transient($probe_transient, $probe_token, 2 * MINUTE_IN_SECONDS);
            $probe_url = add_query_arg('ultracache_probe_compression', $probe_token, $probe_base);

            $response = ultracache_safe_loopback_remote_request($probe_url, array(
                'method'              => 'GET',
                'timeout'             => 5,
                'redirection'         => 0,
                'decompress'          => false,
                'limit_response_size' => 128,
                'headers'             => array(
                    'Cache-Control'   => 'no-cache',
                    'Pragma'          => 'no-cache',
                    'Accept-Encoding' => $accept_encoding,
                ),
            ), 'frontend_compression_probe');

            delete_transient($probe_transient);

            if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
                continue;
            }

            $headers = wp_remote_retrieve_headers($response);
            $probe_header = trim((string) ($headers['x-ultracache-compression-probe'] ?? ''));
            if ('1' !== $probe_header) {
                continue;
            }

            $content_encoding = strtolower(trim((string) ($headers['content-encoding'] ?? '')));
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

        if ($status['brokenGzip']) {
            $status['message'] = 'UltraCache detected gzip-compressed output without a matching Content-Encoding header. Gzip has been disabled as a safety measure.';
        } elseif ($status['brotli'] && $status['gzip']) {
            $status['message'] = 'Your server is already using Brotli and gzip compression by default.';
        } elseif ($status['brotli']) {
            $status['message'] = 'Your server is already using Brotli compression by default.';
        } elseif ($status['gzip']) {
            $status['message'] = 'Your server is already using gzip compression by default.';
        }

        set_transient('ultracache_frontend_compression_probe_v1', $status, 5 * MINUTE_IN_SECONDS);
        return $status;
    }

    public static function get_html_compression_capability_probe($force_refresh = false)
    {
        if ($force_refresh) {
            delete_transient('ultracache_frontend_compression_probe_v1');
        }

        $support = self::get_compression_support_status();
        $frontend = self::get_frontend_compression_probe_status(true);
        $server_managed = !empty($frontend['gzip']) || !empty($frontend['brotli']);
        $blocked = $server_managed || !empty($frontend['brokenGzip']);

        if ($server_managed) {
            $message = isset($frontend['message']) && '' !== (string) $frontend['message']
                ? (string) $frontend['message']
                : self::maybe_translate('Server-managed HTML compression is active.');
        } elseif ($blocked && !empty($frontend['message'])) {
            $message = (string) $frontend['message'];
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
            'brokenGzip'      => !empty($frontend['brokenGzip']),
            'brokenBrotli'    => !empty($frontend['brokenBrotli']),
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- One-time read-only capability token; no state is changed from user input.
        $token = isset($_GET['ultracache_probe_compression']) ? sanitize_text_field(wp_unslash($_GET['ultracache_probe_compression'])) : '';
        if ('' === $token || 1 !== preg_match('/\A[A-Za-z0-9]{48}\z/', $token)) {
            return;
        }

        $transient_key = 'ultracache_compression_probe_' . hash('sha256', $token);
        $expected = get_transient($transient_key);
        if (!is_string($expected) || !hash_equals($expected, $token)) {
            return;
        }

        delete_transient($transient_key);

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
