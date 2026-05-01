<?php
/**
 * Plugin Name: UltraCache
 * Plugin URI: https://github.com/orloxgr/ultracache
 * Description: High-performance WordPress caching with static HTML pre-rendering, Redis object caching, Varnish integration, compression, and AVIF/WebP media optimization.
 * Version: 2.56.122
 * Author: Byron Iniotakis
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: ultracache
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('UCWP_VERSION')) {
    define('UCWP_VERSION', '2.56.122');
}
if (!defined('UCWP_FILE')) {
    define('UCWP_FILE', __FILE__);
}
if (!defined('UCWP_BASENAME')) {
    define('UCWP_BASENAME', plugin_basename(__FILE__));
}
if (!defined('UCWP_PATH')) {
    define('UCWP_PATH', plugin_dir_path(__FILE__));
}
if (!defined('UCWP_URL')) {
    define('UCWP_URL', plugin_dir_url(__FILE__));
}
if (!defined('UCWP_SETTINGS_KEY')) {
    define('UCWP_SETTINGS_KEY', 'ucwp_settings');
}
if (!defined('UCWP_CRON_WARM_STATE_KEY')) {
    define('UCWP_CRON_WARM_STATE_KEY', 'ucwp_cron_warm_state');
}
if (!defined('UCWP_CRON_WARM_LOCK_KEY')) {
    define('UCWP_CRON_WARM_LOCK_KEY', 'ucwp_cron_warm_lock');
}
if (!defined('UCWP_WP_CACHE_MANAGED_KEY')) {
    define('UCWP_WP_CACHE_MANAGED_KEY', 'ucwp_wp_cache_managed');
}
if (!defined('UCWP_CACHE_DIR')) {
    define('UCWP_CACHE_DIR', trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache/');
}
if (!defined('UCWP_AVIF_DIR')) {
    define('UCWP_AVIF_DIR', trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache-avif/');
}
if (!defined('UCWP_AVIF_URL')) {
    define('UCWP_AVIF_URL', trailingslashit(content_url('cache/ultracache-avif')));
}
if (!defined('UCWP_WEBP_DIR')) {
    define('UCWP_WEBP_DIR', trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache-webp/');
}
if (!defined('UCWP_WEBP_URL')) {
    define('UCWP_WEBP_URL', trailingslashit(content_url('cache/ultracache-webp')));
}
if (!defined('UCWP_OBJECT_CACHE_DIR')) {
    define('UCWP_OBJECT_CACHE_DIR', trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache-objects/');
}

if (!function_exists('ucwp_debug_log')) {
    function ucwp_debug_log($message, array $context = array())
    {
        /**
         * Fires when UltraCache emits a debug event.
         *
         * @param string $message Debug message.
         * @param array  $context Context data.
         */
        do_action('ucwp_debug_log', (string) $message, $context);
    }
}

if (!function_exists('ucwp_get_wp_filesystem')) {
    function ucwp_get_wp_filesystem()
    {
        static $initialized = null;
        global $wp_filesystem;

        if (true === $initialized && is_object($wp_filesystem)) {
            return $wp_filesystem;
        }

        if (false === $initialized) {
            return false;
        }

        $initialized = false;

        if (!defined('ABSPATH')) {
            return false;
        }

        if (!function_exists('WP_Filesystem')) {
            $file_api = ABSPATH . 'wp-admin/includes/file.php';
            if (!file_exists($file_api)) {
                return false;
            }
            require_once $file_api;
        }

        if (!function_exists('WP_Filesystem')) {
            return false;
        }

        if (!WP_Filesystem()) {
            return false;
        }

        if (!is_object($wp_filesystem)) {
            return false;
        }

        $initialized = true;
        return $wp_filesystem;
    }
}


if (!function_exists('ucwp_path_is_writable')) {
    function ucwp_path_is_writable($path)
    {
        $filesystem = ucwp_get_wp_filesystem();
        if ($filesystem && method_exists($filesystem, 'is_writable')) {
            return (bool) $filesystem->is_writable($path);
        }

        if (function_exists('wp_is_writable')) {
            return wp_is_writable($path);
        }

        return false;
    }
}

if (!function_exists('ucwp_server_value')) {
    function ucwp_server_value($key)
    {
        if (!is_string($key) || '' === $key) {
            return '';
        }

        $value = null;
        if (function_exists('filter_input')) {
            $value = filter_input(INPUT_SERVER, $key, FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR);
        }

        if (null === $value || false === $value) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Fallback only when filter_input() does not expose server values.
            $value = isset($_SERVER[$key]) ? wp_unslash($_SERVER[$key]) : '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}

if (!function_exists('ucwp_server_flag_enabled')) {
    function ucwp_server_flag_enabled($key)
    {
        $value = strtolower(ucwp_server_value($key));
        return '' !== $value && 'off' !== $value && '0' !== $value;
    }
}

if (!function_exists('ucwp_query_value')) {
    function ucwp_query_value($key)
    {
        if (!is_string($key) || '' === $key) {
            return '';
        }

        $value = null;
        if (function_exists('filter_input')) {
            $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR);
        }

        if (null === $value || false === $value) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended -- Fallback only when filter_input() does not expose query values.
            $value = isset($_GET[$key]) ? wp_unslash($_GET[$key]) : '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}

if (!function_exists('ucwp_request_profiler_enabled')) {
    function ucwp_request_profiler_enabled()
    {
        $query_flag = strtolower(trim((string) ucwp_query_value('ucwp_store_profile')));
        $header_flag = strtolower(trim((string) ucwp_server_value('HTTP_X_ULTRACACHE_STORE_PROFILE')));
        $constant_flag = defined('UCWP_STORE_PROFILE') && UCWP_STORE_PROFILE;

        return ('1' === $query_flag || 'true' === $query_flag || '1' === $header_flag || 'true' === $header_flag || $constant_flag);
    }
}

if (!function_exists('ucwp_request_callback_profiler_enabled')) {
    function ucwp_request_callback_profiler_enabled()
    {
        if (!ucwp_request_profiler_enabled()) {
            return false;
        }

        $query_flag = strtolower(trim((string) ucwp_query_value('ucwp_callback_profile')));
        $header_flag = strtolower(trim((string) ucwp_server_value('HTTP_X_ULTRACACHE_CALLBACK_PROFILE')));
        $constant_flag = defined('UCWP_CALLBACK_PROFILE') && UCWP_CALLBACK_PROFILE;

        return ('1' === $query_flag || 'true' === $query_flag || '1' === $header_flag || 'true' === $header_flag || $constant_flag);
    }
}

if (!function_exists('ucwp_request_profile_request_started_at')) {
    function ucwp_request_profile_request_started_at()
    {
        if (isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])) {
            return (float) $_SERVER['REQUEST_TIME_FLOAT'];
        }

        if (isset($_SERVER['REQUEST_TIME']) && is_numeric($_SERVER['REQUEST_TIME'])) {
            return (float) $_SERVER['REQUEST_TIME'];
        }

        return microtime(true);
    }
}

if (!function_exists('ucwp_request_profile_sanitize_stage')) {
    function ucwp_request_profile_sanitize_stage($stage)
    {
        $stage = strtolower((string) $stage);
        $stage = preg_replace('/[^a-z0-9_\-.]+/', '_', $stage);
        $stage = trim((string) $stage, '_-.');

        return '' !== $stage ? substr($stage, 0, 96) : 'checkpoint';
    }
}

if (!function_exists('ucwp_request_profile_current_hook_summary')) {
    function ucwp_request_profile_current_hook_summary($hook_name)
    {
        global $wp_filter;

        $hook_name = (string) $hook_name;
        if ('' === $hook_name || empty($wp_filter[$hook_name]) || !is_object($wp_filter[$hook_name])) {
            return array();
        }

        $callbacks = isset($wp_filter[$hook_name]->callbacks) && is_array($wp_filter[$hook_name]->callbacks) ? $wp_filter[$hook_name]->callbacks : array();
        if (empty($callbacks)) {
            return array('hook' => $hook_name, 'callback_count' => 0, 'priorities' => array());
        }

        $count = 0;
        $priorities = array();
        foreach ($callbacks as $priority => $items) {
            $item_count = is_array($items) ? count($items) : 0;
            $count += $item_count;
            $priorities[] = (string) $priority . ':' . (string) $item_count;
        }

        return array(
            'hook' => $hook_name,
            'callback_count' => $count,
            'priorities' => $priorities,
        );
    }
}

if (!function_exists('ucwp_request_profile_callback_label')) {
    function ucwp_request_profile_callback_label($callback)
    {
        if (is_string($callback)) {
            return $callback;
        }

        if (is_array($callback) && count($callback) >= 2) {
            $target = $callback[0];
            $method = is_scalar($callback[1]) ? (string) $callback[1] : 'unknown';
            if (is_object($target)) {
                return get_class($target) . '->' . $method;
            }
            if (is_string($target)) {
                return $target . '::' . $method;
            }
        }

        if ($callback instanceof Closure) {
            return 'Closure';
        }

        if (is_object($callback) && method_exists($callback, '__invoke')) {
            return get_class($callback) . '::__invoke';
        }

        return 'unknown_callback';
    }
}

if (!function_exists('ucwp_request_profile_hook_priority_details')) {
    function ucwp_request_profile_hook_priority_details($hook_name, $priority, $limit = 30)
    {
        global $wp_filter;

        $hook_name = (string) $hook_name;
        $priority_key = (string) $priority;
        if ('' === $hook_name || empty($wp_filter[$hook_name]) || !is_object($wp_filter[$hook_name])) {
            return array(
                'hook' => $hook_name,
                'priority' => $priority_key,
                'callback_count' => 0,
                'callbacks' => array(),
            );
        }

        $callbacks = isset($wp_filter[$hook_name]->callbacks) && is_array($wp_filter[$hook_name]->callbacks) ? $wp_filter[$hook_name]->callbacks : array();
        $items = array();
        if (array_key_exists($priority, $callbacks) && is_array($callbacks[$priority])) {
            $items = $callbacks[$priority];
        } elseif (array_key_exists($priority_key, $callbacks) && is_array($callbacks[$priority_key])) {
            $items = $callbacks[$priority_key];
        }

        $labels = array();
        $count = 0;
        foreach ($items as $item) {
            $count++;
            if (count($labels) >= (int) $limit) {
                continue;
            }
            $cb = is_array($item) && array_key_exists('function', $item) ? $item['function'] : $item;
            $labels[] = ucwp_request_profile_callback_label($cb);
        }

        return array(
            'hook' => $hook_name,
            'priority' => $priority_key,
            'callback_count' => $count,
            'callbacks' => $labels,
            'truncated' => $count > count($labels),
        );
    }
}

if (!function_exists('ucwp_request_profile_verbose_settings_enabled')) {
    function ucwp_request_profile_verbose_settings_enabled()
    {
        $query_flag = strtolower(trim((string) ucwp_query_value('ucwp_store_profile_verbose_settings')));
        $header_flag = strtolower(trim((string) ucwp_server_value('HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE_SETTINGS')));
        $constant_flag = defined('UCWP_STORE_PROFILE_VERBOSE_SETTINGS') && UCWP_STORE_PROFILE_VERBOSE_SETTINGS;
        $query_verbose_flag = strtolower(trim((string) ucwp_query_value('ucwp_store_profile_verbose')));
        $header_verbose_flag = strtolower(trim((string) ucwp_server_value('HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE')));
        $constant_verbose_flag = defined('UCWP_STORE_PROFILE_VERBOSE') && UCWP_STORE_PROFILE_VERBOSE;

        return (
            '1' === $query_flag
            || 'true' === $query_flag
            || '1' === $header_flag
            || 'true' === $header_flag
            || $constant_flag
            || '1' === $query_verbose_flag
            || 'true' === $query_verbose_flag
            || '1' === $header_verbose_flag
            || 'true' === $header_verbose_flag
            || $constant_verbose_flag
        );
    }
}


if (!function_exists('ucwp_request_profile_verbose_enabled')) {
    function ucwp_request_profile_verbose_enabled()
    {
        $query_flag = strtolower(trim((string) ucwp_query_value('ucwp_store_profile_verbose')));
        $header_flag = strtolower(trim((string) ucwp_server_value('HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE')));
        $constant_flag = defined('UCWP_STORE_PROFILE_VERBOSE') && UCWP_STORE_PROFILE_VERBOSE;

        return ('1' === $query_flag || 'true' === $query_flag || '1' === $header_flag || 'true' === $header_flag || $constant_flag || ucwp_request_profile_verbose_settings_enabled());
    }
}

if (!function_exists('ucwp_request_profile_compact_stages')) {
    function ucwp_request_profile_compact_stages()
    {
        return array(
            'plugin_file_loaded' => true,
            'ultracache_wp_construct_start' => true,
            'ultracache_dependencies_loaded' => true,
            'ultracache_hooks_registered' => true,
            'dependency_load_start' => true,
            'dependency_load_end' => true,
            'engine_construct' => true,
            'plugins_loaded_p-1000' => true,
            'plugins_loaded_p0' => true,
            'plugins_loaded_p4_before_components' => true,
            'plugins_loaded_p5_before_components' => true,
            'plugins_loaded_p5_components' => true,
            'plugins_loaded_p6_after_components' => true,
            'plugins_loaded_p18_before_reconcile' => true,
            'plugins_loaded_p19_before_page_cache_reconcile' => true,
            'page_cache_reconcile_skipped' => true,
            'page_cache_reconcile_light_start' => true,
            'page_cache_reconcile_light_end' => true,
            'page_cache_reconcile_full_start' => true,
            'page_cache_reconcile_full_end' => true,
            'plugins_loaded_p20_before_object_cache_reconcile' => true,
            'object_cache_reconcile_skipped' => true,
            'object_cache_reconcile_light_start' => true,
            'object_cache_reconcile_light_end' => true,
            'object_cache_reconcile_full_start' => true,
            'object_cache_reconcile_full_end' => true,
            'plugins_loaded_p21_before_runtime_config_reconcile' => true,
            'runtime_config_reconcile_skipped' => true,
            'runtime_config_reconcile_light_start' => true,
            'runtime_config_reconcile_light_end' => true,
            'runtime_config_reconcile_full_before_sync' => true,
            'runtime_config_reconcile_full_after_sync' => true,
            'plugins_loaded_p22_after_reconcile' => true,
            'plugins_loaded_end' => true,
            'setup_theme_start' => true,
            'setup_theme_end' => true,
            'after_setup_theme_start' => true,
            'after_setup_theme_end' => true,
            'init_start' => true,
            'init' => true,
            'init_end' => true,
            'wp_loaded_start' => true,
            'wp_loaded' => true,
            'wp_loaded_end' => true,
            'template_redirect_global_start' => true,
            'template_redirect_start' => true,
            'maybe_start_buffering_start' => true,
            'maybe_start_buffering_reentry_return' => true,
            'maybe_start_buffering_after_should_bypass' => true,
            'bypass_selected' => true,
            'should_bypass_return' => true,
            'early_hit_check_start' => true,
            'early_hit_served' => true,
            'page_generation_lock_checked' => true,
            'buffer_start' => true,
            'template_redirect_global_end' => true,
            'wp_head_start' => true,
            'wp_enqueue_scripts_start' => true,
            'wp_enqueue_scripts_end' => true,
            'wp_head_end' => true,
            'shutdown_start' => true,
            'cache_output_callback_start' => true,
            'store_profile_start' => true,
            'cache_output_callback_end' => true,
            'store_profile_finalize_start' => true,
            'shutdown_end' => true,
            'engine_shutdown_profile_update' => true,
            'callback_slow' => true,
        );
    }
}

if (!function_exists('ucwp_request_profile_should_record_checkpoint')) {
    function ucwp_request_profile_should_record_checkpoint($stage)
    {
        $stage = ucwp_request_profile_sanitize_stage($stage);
        if (ucwp_request_profile_verbose_enabled()) {
            return true;
        }

        $compact = ucwp_request_profile_compact_stages();
        if (isset($compact[$stage])) {
            return true;
        }

        if (0 === strpos($stage, 'advanced_cache_setup_')) {
            return true;
        }

        if (0 === strpos($stage, 'callback_') && ucwp_request_callback_profiler_enabled()) {
            return true;
        }

        return false;
    }
}

if (!function_exists('ucwp_request_profile_settings_checkpoint')) {
    function ucwp_request_profile_settings_checkpoint($stage, array $extra = array())
    {
        if (!ucwp_request_profile_verbose_settings_enabled()) {
            return;
        }

        ucwp_request_profile_checkpoint($stage, $extra);
    }
}

if (!function_exists('ucwp_request_profile_normalize_path')) {
    function ucwp_request_profile_normalize_path($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        return preg_replace('#/+#', '/', $path);
    }
}

if (!function_exists('ucwp_request_profile_relative_path')) {
    function ucwp_request_profile_relative_path($file, $base)
    {
        $file = ucwp_request_profile_normalize_path($file);
        $base = rtrim(ucwp_request_profile_normalize_path($base), '/') . '/';
        if ('' !== $base && 0 === strpos($file, $base)) {
            return ltrim(substr($file, strlen($base)), '/');
        }

        return '';
    }
}

if (!function_exists('ucwp_request_profile_callback_origin')) {
    function ucwp_request_profile_callback_origin($callback)
    {
        $file = '';
        $line = 0;
        try {
            if (is_string($callback) && function_exists($callback)) {
                $ref = new ReflectionFunction($callback);
                $file = (string) $ref->getFileName();
                $line = (int) $ref->getStartLine();
            } elseif ($callback instanceof Closure) {
                $ref = new ReflectionFunction($callback);
                $file = (string) $ref->getFileName();
                $line = (int) $ref->getStartLine();
            } elseif (is_array($callback) && count($callback) >= 2) {
                $target = $callback[0];
                $method = is_scalar($callback[1]) ? (string) $callback[1] : '';
                if ('' !== $method && (is_object($target) || is_string($target)) && method_exists($target, $method)) {
                    $ref = new ReflectionMethod($target, $method);
                    $file = (string) $ref->getFileName();
                    $line = (int) $ref->getStartLine();
                }
            } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
                $ref = new ReflectionMethod($callback, '__invoke');
                $file = (string) $ref->getFileName();
                $line = (int) $ref->getStartLine();
            }
        } catch (Throwable $e) {
            $file = '';
            $line = 0;
        }

        $file = ucwp_request_profile_normalize_path($file);
        $type = 'unknown';
        $name = '';
        $relative = '';

        if ('' !== $file) {
            $plugin_dir = defined('WP_PLUGIN_DIR') ? ucwp_request_profile_normalize_path(WP_PLUGIN_DIR) : '';
            $mu_plugin_dir = defined('WPMU_PLUGIN_DIR') ? ucwp_request_profile_normalize_path(WPMU_PLUGIN_DIR) : '';
            $content_dir = defined('WP_CONTENT_DIR') ? ucwp_request_profile_normalize_path(WP_CONTENT_DIR) : '';
            $abs_path = defined('ABSPATH') ? ucwp_request_profile_normalize_path(ABSPATH) : '';

            if ('' !== $plugin_dir && '' !== ($relative = ucwp_request_profile_relative_path($file, $plugin_dir))) {
                $type = 'plugin';
                $parts = explode('/', $relative);
                $name = (string) reset($parts);
            } elseif ('' !== $mu_plugin_dir && '' !== ($relative = ucwp_request_profile_relative_path($file, $mu_plugin_dir))) {
                $type = 'mu-plugin';
                $parts = explode('/', $relative);
                $name = (string) reset($parts);
            } elseif (function_exists('get_stylesheet_directory') && '' !== ($relative = ucwp_request_profile_relative_path($file, get_stylesheet_directory()))) {
                $type = 'theme';
                $name = function_exists('get_stylesheet') ? (string) get_stylesheet() : 'stylesheet';
            } elseif (function_exists('get_template_directory') && '' !== ($relative = ucwp_request_profile_relative_path($file, get_template_directory()))) {
                $type = 'theme';
                $name = function_exists('get_template') ? (string) get_template() : 'template';
            } elseif ('' !== $content_dir && '' !== ($relative = ucwp_request_profile_relative_path($file, $content_dir))) {
                $type = 'wp-content';
                $parts = explode('/', $relative);
                $name = (string) reset($parts);
            } elseif ('' !== $abs_path && '' !== ($relative = ucwp_request_profile_relative_path($file, $abs_path))) {
                $type = 'core';
                $parts = explode('/', $relative);
                $name = (string) reset($parts);
            }
        }

        return array(
            'type' => $type,
            'name' => $name,
            'file' => $file,
            'relative_file' => $relative,
            'line' => $line,
        );
    }
}

if (!function_exists('ucwp_request_profile_record_callback_timing')) {
    function ucwp_request_profile_record_callback_timing($hook_name, $priority, $index, $callback_id, $label, array $origin, $start_ms, $duration_ms)
    {
        if (!ucwp_request_callback_profiler_enabled()) {
            return;
        }

        if (!isset($GLOBALS['ucwp_request_profile_callback_timings']) || !is_array($GLOBALS['ucwp_request_profile_callback_timings'])) {
            $GLOBALS['ucwp_request_profile_callback_timings'] = array();
        }

        if (!isset($GLOBALS['ucwp_request_profile_callback_timing_summary']) || !is_array($GLOBALS['ucwp_request_profile_callback_timing_summary'])) {
            $GLOBALS['ucwp_request_profile_callback_timing_summary'] = array();
        }

        $duration_ms = (int) max(0, $duration_ms);
        $entry = array(
            'hook' => (string) $hook_name,
            'priority' => (string) $priority,
            'callback_index' => (string) $index,
            'callback_id' => (string) $callback_id,
            'callback_label' => (string) $label,
            'duration_ms' => $duration_ms,
            'start_ms' => (int) max(0, $start_ms),
            'origin_type' => (string) ($origin['type'] ?? ''),
            'origin_name' => (string) ($origin['name'] ?? ''),
            'origin_file' => (string) ($origin['relative_file'] ?? ''),
            'origin_line' => (int) ($origin['line'] ?? 0),
        );

        $GLOBALS['ucwp_request_profile_callback_timings'][] = $entry;

        $key = implode('|', array($entry['hook'], $entry['priority'], $entry['callback_label'], $entry['origin_type'], $entry['origin_name'], $entry['origin_file']));
        if (!isset($GLOBALS['ucwp_request_profile_callback_timing_summary'][$key])) {
            $GLOBALS['ucwp_request_profile_callback_timing_summary'][$key] = array(
                'hook' => $entry['hook'],
                'priority' => $entry['priority'],
                'callback_label' => $entry['callback_label'],
                'origin_type' => $entry['origin_type'],
                'origin_name' => $entry['origin_name'],
                'origin_file' => $entry['origin_file'],
                'count' => 0,
                'total_ms' => 0,
                'max_ms' => 0,
            );
        }

        $GLOBALS['ucwp_request_profile_callback_timing_summary'][$key]['count']++;
        $GLOBALS['ucwp_request_profile_callback_timing_summary'][$key]['total_ms'] += $duration_ms;
        if ($duration_ms > (int) $GLOBALS['ucwp_request_profile_callback_timing_summary'][$key]['max_ms']) {
            $GLOBALS['ucwp_request_profile_callback_timing_summary'][$key]['max_ms'] = $duration_ms;
        }

        if ($duration_ms >= 200) {
            ucwp_request_profile_checkpoint('callback_slow', array(
                'target_hook' => $entry['hook'],
                'target_priority' => $entry['priority'],
                'target_callback' => $entry['callback_label'],
                'callback' => $entry['callback_label'],
                'callback_label' => $entry['callback_label'],
                'duration_ms' => $duration_ms,
                'origin_type' => $entry['origin_type'],
                'origin_name' => $entry['origin_name'],
                'origin_file' => $entry['origin_file'],
                'plugin' => ('plugin' === $entry['origin_type']) ? $entry['origin_name'] : '',
                'file' => $entry['origin_file'],
            ));
        }
    }
}

if (!function_exists('ucwp_get_request_profile_callback_timings')) {
    function ucwp_get_request_profile_callback_timings($limit = 120)
    {
        $timings = isset($GLOBALS['ucwp_request_profile_callback_timings']) && is_array($GLOBALS['ucwp_request_profile_callback_timings']) ? $GLOBALS['ucwp_request_profile_callback_timings'] : array();
        usort($timings, function ($a, $b) {
            return (int) ($b['duration_ms'] ?? 0) <=> (int) ($a['duration_ms'] ?? 0);
        });

        return array_slice($timings, 0, max(1, (int) $limit));
    }
}

if (!function_exists('ucwp_get_request_profile_callback_timing_summary')) {
    function ucwp_get_request_profile_callback_timing_summary($limit = 80)
    {
        $summary = isset($GLOBALS['ucwp_request_profile_callback_timing_summary']) && is_array($GLOBALS['ucwp_request_profile_callback_timing_summary']) ? array_values($GLOBALS['ucwp_request_profile_callback_timing_summary']) : array();
        foreach ($summary as $i => $row) {
            $count = max(1, (int) ($row['count'] ?? 1));
            $summary[$i]['avg_ms'] = (int) round(((int) ($row['total_ms'] ?? 0)) / $count);
        }

        usort($summary, function ($a, $b) {
            $a_total = (int) ($a['total_ms'] ?? 0);
            $b_total = (int) ($b['total_ms'] ?? 0);
            if ($a_total === $b_total) {
                return (int) ($b['max_ms'] ?? 0) <=> (int) ($a['max_ms'] ?? 0);
            }
            return $b_total <=> $a_total;
        });

        return array_slice($summary, 0, max(1, (int) $limit));
    }
}

if (!function_exists('ucwp_request_profile_wrap_hook_callbacks')) {
    function ucwp_request_profile_wrap_hook_callbacks($hook_name, $priorities = null)
    {
        if (!ucwp_request_callback_profiler_enabled()) {
            return;
        }

        global $wp_filter;
        $hook_name = (string) $hook_name;
        if ('' === $hook_name || empty($wp_filter[$hook_name]) || !is_object($wp_filter[$hook_name]) || empty($wp_filter[$hook_name]->callbacks) || !is_array($wp_filter[$hook_name]->callbacks)) {
            ucwp_request_profile_checkpoint('callback_wrap_skipped', array('target_hook' => $hook_name, 'reason' => 'no_callbacks'));
            return;
        }

        if (!isset($GLOBALS['ucwp_request_profile_wrapped_callbacks']) || !is_array($GLOBALS['ucwp_request_profile_wrapped_callbacks'])) {
            $GLOBALS['ucwp_request_profile_wrapped_callbacks'] = array();
        }
        if (!isset($GLOBALS['ucwp_request_profile_wrapped_callbacks'][$hook_name])) {
            $GLOBALS['ucwp_request_profile_wrapped_callbacks'][$hook_name] = array();
        }

        $available_priorities = array_keys($wp_filter[$hook_name]->callbacks);
        $target_priorities = null === $priorities ? $available_priorities : array_map('intval', (array) $priorities);
        $wrapped = 0;
        $priority_counts = array();

        foreach ($target_priorities as $priority) {
            $priority_key = (string) $priority;
            $actual_priority = null;
            if (array_key_exists($priority, $wp_filter[$hook_name]->callbacks)) {
                $actual_priority = $priority;
            } elseif (array_key_exists($priority_key, $wp_filter[$hook_name]->callbacks)) {
                $actual_priority = $priority_key;
            }

            if (null === $actual_priority || empty($wp_filter[$hook_name]->callbacks[$actual_priority]) || !is_array($wp_filter[$hook_name]->callbacks[$actual_priority])) {
                $priority_counts[$priority_key] = 0;
                continue;
            }

            $index = 0;
            foreach ($wp_filter[$hook_name]->callbacks[$actual_priority] as $callback_id => $item) {
                if (!is_array($item) || !array_key_exists('function', $item)) {
                    $index++;
                    continue;
                }

                $wrapped_key = (string) $actual_priority . ':' . (is_scalar($callback_id) ? (string) $callback_id : (string) $index);
                if (!empty($GLOBALS['ucwp_request_profile_wrapped_callbacks'][$hook_name][$wrapped_key])) {
                    $index++;
                    continue;
                }

                $original_callback = $item['function'];
                if (!is_callable($original_callback)) {
                    $index++;
                    continue;
                }

                $accepted_args = isset($item['accepted_args']) ? (int) $item['accepted_args'] : 1;
                if ($accepted_args < 0) {
                    $accepted_args = 0;
                }

                $label = ucwp_request_profile_callback_label($original_callback);
                $origin = ucwp_request_profile_callback_origin($original_callback);
                $current_index = $index;
                $current_id = is_scalar($callback_id) ? (string) $callback_id : 'callback_' . (string) $current_index;
                $current_priority = (string) $actual_priority;

                $wp_filter[$hook_name]->callbacks[$actual_priority][$callback_id]['function'] = function () use ($hook_name, $original_callback, $accepted_args, $current_priority, $current_index, $current_id, $label, $origin) {
                    $args = func_get_args();
                    if ($accepted_args >= 0) {
                        $args = array_slice($args, 0, $accepted_args);
                    }

                    $request_start = ucwp_request_profile_request_started_at();
                    $start = microtime(true);
                    $result = null;
                    try {
                        $result = call_user_func_array($original_callback, $args);
                    } finally {
                        $end = microtime(true);
                        ucwp_request_profile_record_callback_timing(
                            $hook_name,
                            $current_priority,
                            $current_index,
                            $current_id,
                            $label,
                            $origin,
                            (int) round(max(0, $start - $request_start) * 1000),
                            (int) round(max(0, $end - $start) * 1000)
                        );
                    }

                    return $result;
                };

                $GLOBALS['ucwp_request_profile_wrapped_callbacks'][$hook_name][$wrapped_key] = true;
                $wrapped++;
                $index++;
            }

            $priority_counts[$priority_key] = $index;
        }

        ucwp_request_profile_checkpoint('callback_wrap_done', array(
            'target_hook' => $hook_name,
            'wrapped_count' => (string) $wrapped,
            'priority_counts' => $priority_counts,
        ));
    }
}

if (!function_exists('ucwp_request_profile_checkpoint')) {
    function ucwp_request_profile_checkpoint($stage, array $extra = array())
    {
        if (!ucwp_request_profiler_enabled()) {
            return;
        }

        $stage = ucwp_request_profile_sanitize_stage($stage);
        if (!ucwp_request_profile_should_record_checkpoint($stage)) {
            return;
        }

        if (!isset($GLOBALS['ucwp_request_profile_checkpoints']) || !is_array($GLOBALS['ucwp_request_profile_checkpoints'])) {
            $GLOBALS['ucwp_request_profile_checkpoints'] = array();
        }

        $now = microtime(true);
        $request_start = ucwp_request_profile_request_started_at();
        $last = isset($GLOBALS['ucwp_request_profile_last_checkpoint_at']) && is_numeric($GLOBALS['ucwp_request_profile_last_checkpoint_at'])
            ? (float) $GLOBALS['ucwp_request_profile_last_checkpoint_at']
            : $request_start;

        $current_hook = function_exists('current_filter') ? (string) current_filter() : '';
        $checkpoint = array_merge(array(
            'stage' => $stage,
            'source' => 'plugin',
            'hook' => $current_hook,
            'at_ms' => (int) round(max(0, $now - $request_start) * 1000),
            'since_previous_ms' => (int) round(max(0, $now - $last) * 1000),
            'memory_bytes' => function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0,
            'peak_memory_bytes' => function_exists('memory_get_peak_usage') ? (int) memory_get_peak_usage(true) : 0,
        ), $extra);

        if (ucwp_request_profile_verbose_enabled() && '' !== $current_hook && !isset($checkpoint['hook_summary'])) {
            $summary = ucwp_request_profile_current_hook_summary($current_hook);
            if (!empty($summary)) {
                $checkpoint['hook_summary'] = $summary;
            }
        }

        $GLOBALS['ucwp_request_profile_checkpoints'][] = $checkpoint;
        $GLOBALS['ucwp_request_profile_last_checkpoint_at'] = $now;
    }
}

if (!function_exists('ucwp_get_request_profile_checkpoints')) {
    function ucwp_get_request_profile_checkpoints()
    {
        return isset($GLOBALS['ucwp_request_profile_checkpoints']) && is_array($GLOBALS['ucwp_request_profile_checkpoints'])
            ? $GLOBALS['ucwp_request_profile_checkpoints']
            : array();
    }
}

ucwp_request_profile_checkpoint('plugin_file_loaded');

if (!function_exists('ucwp_php_string_literal')) {
    function ucwp_php_string_literal($value)
    {
        return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $value) . "'";
    }
}

if (!function_exists('ucwp_php_float_literal')) {
    function ucwp_php_float_literal($value)
    {
        return rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.');
    }
}

if (!function_exists('ucwp_is_allowed_socket_target')) {
    function ucwp_is_allowed_socket_target($host, $port)
    {
        $host = strtolower(trim((string) $host));
        $port = (int) $port;

        if ('' === $host || $port <= 0 || $port > 65535) {
            return false;
        }

        $allowed_ports = apply_filters('ucwp_allowed_socket_ports', array(80, 82, 443, 6081, 6082), $host);
        if (is_array($allowed_ports) && !in_array($port, array_map('intval', $allowed_ports), true)) {
            return false;
        }

        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $home_host = strtolower((string) $home_host);
        $local_hosts = array_filter(array_unique(array('localhost', '127.0.0.1', '::1', $home_host)));

        if (in_array($host, $local_hosts, true)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                if (0 === strpos($host, '10.') || 0 === strpos($host, '192.168.') || preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $host)) {
                    return true;
                }
            }
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && ('fc' === substr($host, 0, 2) || 'fd' === substr($host, 0, 2))) {
                return true;
            }
            return false;
        }

        if ('' !== $home_host && ($host === $home_host || preg_match('/(?:^|\.)' . preg_quote($home_host, '/') . '$/i', $host))) {
            return true;
        }

        return (bool) apply_filters('ucwp_is_allowed_socket_target', false, $host, $port);
    }
}

if (!function_exists('ucwp_safe_file_get_contents')) {

    function ucwp_safe_file_get_contents($path, $context = '', $suppress_warnings = false)
    {
        $path = (string) $path;
        $context = (string) $context;
        $suppress_warnings = (bool) $suppress_warnings;
        $data = false;

        $filesystem = ucwp_get_wp_filesystem();
        if ($filesystem && $filesystem->exists($path) && $filesystem->is_file($path)) {
            $data = $filesystem->get_contents($path);
        } else {
            $exists = file_exists($path);
            $is_file = $exists && is_file($path);
            $readable = $is_file && is_readable($path);

            if (!$suppress_warnings || $readable) {
                $data = file_get_contents($path);
            }
        }

        if (false === $data) {
            $log_context = array('path' => $path, 'context' => $context);
            ucwp_debug_log('file_get_contents failed', $log_context);
        }

        return $data;
    }
}

if (!function_exists('ucwp_safe_fsockopen')) {
    function ucwp_safe_fsockopen($host, $port, &$errno, &$errstr, $timeout = 0, $context = '')
    {
        $host = (string) $host;
        $port = (int) $port;
        $timeout = (float) $timeout;
        $context = (string) $context;
        $errno = 0;
        $errstr = '';

        $remote_socket = 'tcp://' . $host . ':' . $port;
        $stream = @stream_socket_client($remote_socket, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);

        if (false === $stream) {
            $log_context = array(
                'host' => $host,
                'port' => $port,
                'timeout' => $timeout,
                'context' => $context,
                'errno' => (int) $errno,
            );
            if ('' !== (string) $errstr) {
                $log_context['error'] = (string) $errstr;
            }
            ucwp_debug_log('stream_socket_client failed', $log_context);
        }

        return $stream;
    }
}

if (!function_exists('ucwp_safe_file_put_contents')) {
    function ucwp_safe_file_put_contents($path, $contents, $flags = 0, $context = '')
    {
        $path = (string) $path;
        $context = (string) $context;
        if ('' === $path) {
            ucwp_debug_log('file_put_contents failed', array('path' => $path, 'context' => $context, 'reason' => 'empty-path'));
            return false;
        }

        $dir = dirname($path);
        if ('' !== $dir && '.' !== $dir && !is_dir($dir)) {
            if (function_exists('ucwp_safe_mkdir')) {
                ucwp_safe_mkdir($dir, 0755, true, $context . ' parent mkdir');
            } elseif (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($dir);
            } else {
                @mkdir($dir, 0755, true);
            }
        }

        if ('' !== $dir && '.' !== $dir && (!is_dir($dir) || !is_writable($dir))) {
            if (is_dir($dir)) {
                @chmod($dir, 0755);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                ucwp_debug_log('file_put_contents failed', array('path' => $path, 'context' => $context, 'reason' => 'parent-not-writable'));
                return false;
            }
        }

        $filesystem = ucwp_get_wp_filesystem();
        if ($filesystem) {
            $existing = '';
            if (FILE_APPEND === ($flags & FILE_APPEND) && $filesystem->exists($path)) {
                $existing = (string) $filesystem->get_contents($path);
            }
            $data = $existing . (string) $contents;
            $result = $filesystem->put_contents($path, $data, FS_CHMOD_FILE);
            if (false !== $result) {
                return strlen($data);
            }
        }

        $result = @file_put_contents($path, $contents, $flags);
        if (false === $result) {
            ucwp_debug_log('file_put_contents failed', array('path' => $path, 'context' => $context));
        }

        return $result;
    }
}

if (!function_exists('ucwp_safe_unlink')) {
    function ucwp_safe_unlink($path, $context = '')
    {
        if (!file_exists($path)) {
            return true;
        }

        $filesystem = ucwp_get_wp_filesystem();
        if ($filesystem) {
            $result = $filesystem->delete($path, false, 'f');
            if ($result || !file_exists($path)) {
                return true;
            }
        }

        if (function_exists('wp_delete_file')) {
            wp_delete_file($path);
        }

        $result = !file_exists($path);
        if (!$result) {
            ucwp_debug_log('unlink failed', array('path' => $path, 'context' => (string) $context));
        }

        return $result;
    }
}

if (!function_exists('ucwp_safe_rename')) {
    function ucwp_safe_rename($from, $to, $context = '')
    {
        $from = is_string($from) ? $from : '';
        $to = is_string($to) ? $to : '';
        if ('' === $from || '' === $to) {
            ucwp_debug_log('rename failed: empty path', array('from' => $from, 'to' => $to, 'context' => (string) $context));
            return false;
        }

        if ($from === $to) {
            return file_exists($to);
        }

        clearstatcache(true, $from);
        clearstatcache(true, $to);
        if (!file_exists($from)) {
            $already_moved = file_exists($to);
            if (!$already_moved) {
                ucwp_debug_log('rename failed: source missing', array('from' => $from, 'to' => $to, 'context' => (string) $context));
            }
            return $already_moved;
        }

        if (@rename($from, $to)) {
            clearstatcache(true, $from);
            clearstatcache(true, $to);
            return file_exists($to) && !file_exists($from);
        }

        $filesystem = ucwp_get_wp_filesystem();
        if ($filesystem) {
            $result = $filesystem->move($from, $to, true);
            clearstatcache(true, $from);
            clearstatcache(true, $to);
            if ($result || (file_exists($to) && !file_exists($from))) {
                return true;
            }
        }

        ucwp_debug_log('rename failed', array('from' => $from, 'to' => $to, 'context' => (string) $context));
        return false;
    }
}


if (!function_exists('ucwp_safe_copy')) {
    function ucwp_safe_copy($from, $to, $context = '')
    {
        $filesystem = ucwp_get_wp_filesystem();
        if ($filesystem) {
            $result = $filesystem->copy($from, $to, true, FS_CHMOD_FILE);
            if ($result) {
                return true;
            }
        }

        $result = copy($from, $to);
        if (!$result) {
            ucwp_debug_log('copy failed', array('from' => $from, 'to' => $to, 'context' => (string) $context));
        }

        return $result;
    }
}

if (!function_exists('ucwp_safe_mkdir')) {
    function ucwp_safe_mkdir($dir, $mode = 0755, $recursive = true, $context = '')
    {
        if (is_dir($dir)) {
            return true;
        }

        $filesystem = ucwp_get_wp_filesystem();
        if ($recursive && function_exists('wp_mkdir_p') && wp_mkdir_p($dir)) {
            return true;
        }

        if ($filesystem) {
            $result = $filesystem->mkdir($dir, $mode);
            if ($result || is_dir($dir)) {
                return true;
            }
        }

        ucwp_debug_log('mkdir failed', array('dir' => $dir, 'mode' => $mode, 'recursive' => (bool) $recursive, 'context' => (string) $context));
        return is_dir($dir);
    }
}

if (!function_exists('ucwp_native_delete_directory')) {
    function ucwp_native_delete_directory($dir)
    {
        $dir = is_string($dir) ? $dir : '';
        if ('' === $dir || !file_exists($dir)) {
            return true;
        }
        if (!is_dir($dir) || is_link($dir)) {
            return false;
        }

        $items = @scandir($dir);
        if (!is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                if (!ucwp_native_delete_directory($path)) {
                    return false;
                }
            } elseif (file_exists($path) && !@unlink($path)) {
                return false;
            }
        }

        clearstatcache(true, $dir);
        return @rmdir($dir) || !file_exists($dir);
    }
}

if (!function_exists('ucwp_safe_rmdir')) {
    function ucwp_safe_rmdir($dir, $context = '')
    {
        $dir = is_string($dir) ? $dir : '';
        if ('' === $dir || !file_exists($dir)) {
            return true;
        }

        $filesystem = ucwp_get_wp_filesystem();
        if ($filesystem) {
            $result = $filesystem->delete($dir, true, 'd');
            clearstatcache(true, $dir);
            if ($result || !file_exists($dir)) {
                return true;
            }
        }

        if (ucwp_native_delete_directory($dir)) {
            clearstatcache(true, $dir);
            return true;
        }

        ucwp_debug_log('rmdir failed', array('dir' => $dir, 'context' => (string) $context));
        return !file_exists($dir);
    }
}


if (!function_exists('ucwp_safe_filemtime')) {
    function ucwp_safe_filemtime($path, $context = '')
    {
        $result = filemtime($path);
        if (false === $result && file_exists($path)) {
            ucwp_debug_log('filemtime failed', array('path' => $path, 'context' => (string) $context));
        }

        return $result;
    }
}

if (!function_exists('ucwp_safe_filesize')) {
    function ucwp_safe_filesize($path, $context = '')
    {
        $path = (string) $path;
        if ('' === $path || !file_exists($path) || !is_file($path) || !is_readable($path)) {
            return false;
        }

        $result = filesize($path);
        if (false === $result && file_exists($path)) {
            ucwp_debug_log('filesize failed', array('path' => $path, 'context' => (string) $context));
        }

        return $result;
    }
}

if (!function_exists('ucwp_safe_tempnam')) {
    function ucwp_safe_tempnam($dir, $prefix = 'ucwp', $context = '')
    {
        $dir = (string) $dir;
        $prefix = (string) $prefix;
        if ('' === $dir || !is_dir($dir) || !ucwp_path_is_writable($dir)) {
            ucwp_debug_log('tempnam directory unavailable', array('dir' => $dir, 'context' => (string) $context));
            return false;
        }

        $sanitized_prefix = preg_replace('/[^A-Za-z0-9._-]/', '', $prefix);
        if (!is_string($sanitized_prefix) || '' === $sanitized_prefix) {
            $sanitized_prefix = 'ucwp';
        }

        $result = tempnam($dir, substr($sanitized_prefix, 0, 32));
        if (false === $result) {
            ucwp_debug_log('tempnam failed', array('dir' => $dir, 'prefix' => $sanitized_prefix, 'context' => (string) $context));
        }

        return $result;
    }
}

if (!function_exists('ucwp_safe_fread')) {
    function ucwp_safe_fread($stream, $length, $context = '')
    {
        $length = max(0, (int) $length);
        if ($length <= 0) {
            return '';
        }

        if (!is_resource($stream)) {
            ucwp_debug_log('fread failed: invalid stream', array('context' => (string) $context, 'length' => $length));
            return false;
        }

        $result = stream_get_contents($stream, $length);
        if (false === $result) {
            ucwp_debug_log('stream_get_contents failed', array('context' => (string) $context, 'length' => $length));
        }

        return $result;
    }
}

if (!function_exists('ucwp_safe_scandir')) {
    function ucwp_safe_scandir($dir, $context = '')
    {
        $dir = (string) $dir;
        if ('' === $dir || !is_dir($dir)) {
            return false;
        }

        if (!is_readable($dir)) {
            ucwp_debug_log('scandir failed: directory not readable', array('dir' => $dir, 'context' => (string) $context));
            return false;
        }

        $result = scandir($dir);
        if (false === $result) {
            ucwp_debug_log('scandir failed', array('dir' => $dir, 'context' => (string) $context));
        }

        return $result;
    }
}

if (!function_exists('ucwp_safe_stream_set_blocking')) {
    function ucwp_safe_stream_set_blocking($stream, $enable, $context = '')
    {
        $result = stream_set_blocking($stream, $enable);
        if (false === $result) {
            ucwp_debug_log('stream_set_blocking failed', array('context' => (string) $context, 'enable' => (bool) $enable));
        }

        return $result;
    }
}


if (!function_exists('ucwp_safe_remote_request')) {
    function ucwp_safe_remote_request($url, array $args = array(), $context = '')
    {
        $response = wp_safe_remote_request($url, $args);
        if (is_wp_error($response)) {
            ucwp_debug_log('wp_safe_remote_request failed', array(
                'url' => (string) $url,
                'context' => (string) $context,
                'error' => $response->get_error_message(),
            ));
        }

        return $response;
    }
}


if (!function_exists('ucwp_get_loopback_ssl_status')) {
    function ucwp_get_loopback_ssl_status()
    {
        $status = get_transient('ucwp_loopback_ssl_status_v1');
        if (!is_array($status)) {
            $status = array();
        }

        return wp_parse_args($status, array(
            'strictByDefault' => true,
            'fallbackUsed'    => false,
            'lastUrl'         => '',
            'lastError'       => '',
            'context'         => '',
            'message'         => '',
            'updatedAt'       => 0,
        ));
    }
}

if (!function_exists('ucwp_set_loopback_ssl_status')) {
    function ucwp_set_loopback_ssl_status(array $status)
    {
        set_transient('ucwp_loopback_ssl_status_v1', $status, DAY_IN_SECONDS);
    }
}

if (!function_exists('ucwp_reset_loopback_ssl_status')) {
    function ucwp_reset_loopback_ssl_status()
    {
        delete_transient('ucwp_loopback_ssl_status_v1');
    }
}

if (!function_exists('ucwp_is_local_https_url')) {
    function ucwp_is_local_https_url($url)
    {
        $url = is_string($url) ? trim($url) : '';
        if ('' === $url) {
            return false;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host   = isset($parts['host']) ? ucwp_normalize_host((string) $parts['host']) : '';
        if ('https' !== $scheme || '' === $host) {
            return false;
        }

        $trusted_hosts = ucwp_get_trusted_hosts();
        return in_array($host, $trusted_hosts, true);
    }
}

if (!function_exists('ucwp_is_ssl_verification_wp_error')) {
    function ucwp_is_ssl_verification_wp_error($error)
    {
        if (!($error instanceof WP_Error)) {
            return false;
        }

        $message = strtolower(trim((string) $error->get_error_message()));
        if ('' === $message) {
            return false;
        }

        $needles = array(
            'ssl certificate',
            'certificate verify failed',
            'peer certificate',
            'self signed certificate',
            'unable to get local issuer certificate',
            'unable to verify the first certificate',
            'tlsv1 alert',
            'certificate has expired',
            'hostname mismatch',
            'curl error 60',
            'curl error 51',
        );

        foreach ($needles as $needle) {
            if (false !== strpos($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('ucwp_safe_loopback_remote_request')) {
    function ucwp_safe_loopback_remote_request($url, array $args = array(), $context = '')
    {
        $is_local_https = ucwp_is_local_https_url($url);
        if (!$is_local_https) {
            return ucwp_safe_remote_request($url, $args, $context);
        }

        $strict_args = $args;
        $strict_args['sslverify'] = true;
        $response = ucwp_safe_remote_request($url, $strict_args, $context . ':strict');
        if (!is_wp_error($response)) {
            return $response;
        }

        if (!ucwp_is_ssl_verification_wp_error($response)) {
            return $response;
        }

        $fallback_args = $args;
        $fallback_args['sslverify'] = false;
        $fallback = ucwp_safe_remote_request($url, $fallback_args, $context . ':fallback');
        if (!is_wp_error($fallback)) {
            ucwp_set_loopback_ssl_status(array(
                'strictByDefault' => true,
                'fallbackUsed'    => true,
                'lastUrl'         => (string) $url,
                'lastError'       => (string) $response->get_error_message(),
                'context'         => (string) $context,
                'message'         => UltraCache_WP::maybe_translate('Strict local SSL verification failed and UltraCache temporarily retried the same-host HTTPS loopback request without certificate verification.'),
                'updatedAt'       => time(),
            ));
            return $fallback;
        }

        return $response;
    }
}

if (!function_exists('ucwp_safe_wp_parse_url')) {
    function ucwp_safe_wp_parse_url($url, $component = -1, $context = '')
    {
        if (-1 === $component) {
            $result = wp_parse_url((string) $url);
        } else {
            $result = wp_parse_url((string) $url, $component);
        }

        if (false === $result) {
            ucwp_debug_log('wp_parse_url failed', array('url' => (string) $url, 'component' => $component, 'context' => (string) $context));
        }

        return $result;
    }
}

if (!function_exists('ucwp_normalize_host')) {
    function ucwp_normalize_host($host)
    {
        $host = trim((string) $host);
        if ('' === $host) {
            return '';
        }

        if (false !== strpos($host, ',')) {
            $parts = explode(',', $host);
            $host = (string) reset($parts);
        }

        $host = preg_replace('/\s+/', '', $host);
        $parsed = wp_parse_url('http://' . ltrim($host, '/'));
        if (is_array($parsed) && !empty($parsed['host'])) {
            $host = (string) $parsed['host'];
        }

        $host = strtolower(rtrim(trim($host), '.'));
        if ('' === $host) {
            return '';
        }

        if (!preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:.]+\])$/i', $host)) {
            return '';
        }

        return $host;
    }
}

if (!function_exists('ucwp_get_trusted_hosts')) {
    function ucwp_get_trusted_hosts()
    {
        $hosts = array();
        foreach (array(home_url('/'), site_url('/')) as $url) {
            $host = ucwp_normalize_host(wp_parse_url((string) $url, PHP_URL_HOST));
            if ('' !== $host) {
                $hosts[$host] = true;
            }
        }

        return array_values(array_keys($hosts));
    }
}

if (!function_exists('ucwp_get_validated_http_host')) {
    function ucwp_get_validated_http_host($host, $context = '')
    {
        $normalized = ucwp_normalize_host($host);
        if ('' === $normalized) {
            ucwp_debug_log('invalid host header', array('host' => (string) $host, 'context' => (string) $context));
            return '';
        }

        $trusted = array_fill_keys(ucwp_get_trusted_hosts(), true);
        if (empty($trusted) || !isset($trusted[$normalized])) {
            ucwp_debug_log('untrusted host header rejected', array('host' => $normalized, 'context' => (string) $context, 'trusted_hosts' => array_keys($trusted)));
            return '';
        }

        return $normalized;
    }
}

if (!class_exists('Ultra_Cache_WP')) {
    class Ultra_Cache_WP
    {
        /** @var Ultra_Cache_WP|null */
        private static $instance = null;

        /** @var array|null */
        private static $dashboard_settings_cache = null;

        /** @var array|null */
        private static $settings_cache = null;

        public static function instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        private function __construct()
        {
            ucwp_request_profile_checkpoint('ultracache_wp_construct_start');
            $this->load_dependencies();
            ucwp_request_profile_checkpoint('ultracache_dependencies_loaded');
            $this->register_hooks();
            ucwp_request_profile_checkpoint('ultracache_hooks_registered');
        }

        private function load_dependencies()
        {
            $files = array(
                UCWP_PATH . 'includes/class-ultra-cache-engine.php',
                UCWP_PATH . 'includes/class-media-converter.php',
                UCWP_PATH . 'includes/class-object-cache-manager.php',
                UCWP_PATH . 'includes/class-rest-api.php',
                UCWP_PATH . 'includes/class-wp-cli.php',
            );

            foreach ($files as $file) {
                if (file_exists($file)) {
                    ucwp_request_profile_checkpoint('dependency_load_start', array('file' => basename((string) $file)));
                    require_once $file;
                    ucwp_request_profile_checkpoint('dependency_load_end', array('file' => basename((string) $file)));
                }
            }
        }

        private function register_request_profile_hooks()
        {
            if (!ucwp_request_profiler_enabled()) {
                return;
            }

            $hooks = array(
                array('plugins_loaded', -1000, 'plugins_loaded_p-1000'),
                array('plugins_loaded', 0, 'plugins_loaded_p0'),
                array('plugins_loaded', 5, 'plugins_loaded_p5_components'),
                array('plugins_loaded', 18, 'plugins_loaded_p18_before_reconcile'),
                array('plugins_loaded', 19, 'plugins_loaded_p19_before_page_cache_reconcile'),
                array('plugins_loaded', 20, 'plugins_loaded_p20_before_object_cache_reconcile'),
                array('plugins_loaded', 21, 'plugins_loaded_p21_before_runtime_config_reconcile'),
                array('plugins_loaded', 22, 'plugins_loaded_p22_after_reconcile'),
                array('plugins_loaded', PHP_INT_MAX, 'plugins_loaded_end'),
                array('setup_theme', -1000, 'setup_theme_start'),
                array('setup_theme', PHP_INT_MAX, 'setup_theme_end'),
                array('after_setup_theme', -1000, 'after_setup_theme_start'),
                array('after_setup_theme', PHP_INT_MAX, 'after_setup_theme_end'),
                array('init', -1000, 'init_start'),
                array('init', PHP_INT_MAX, 'init_end'),
                array('wp_loaded', -1000, 'wp_loaded_start'),
                array('wp_loaded', PHP_INT_MAX, 'wp_loaded_end'),
                array('template_redirect', -1000, 'template_redirect_global_start'),
                array('template_redirect', PHP_INT_MAX, 'template_redirect_global_end'),
                array('wp_head', -1000, 'wp_head_start'),
                array('wp_head', PHP_INT_MAX, 'wp_head_end'),
                array('shutdown', -1000, 'shutdown_start'),
                array('shutdown', PHP_INT_MAX, 'shutdown_end'),
            );


            foreach ($hooks as $hook) {
                add_action($hook[0], function () use ($hook) {
                    ucwp_request_profile_checkpoint($hook[2]);
                }, $hook[1]);
            }


            if (ucwp_request_callback_profiler_enabled()) {
                add_action('init', function () {
                    ucwp_request_profile_checkpoint('callback_profiler_wrap_init', array('target_priorities' => array('1', '2', '5', '10', '20')));
                    ucwp_request_profile_wrap_hook_callbacks('init', array(1, 2, 5, 10, 20));
                }, 0);


                add_action('wp_loaded', function () {
                    ucwp_request_profile_checkpoint('callback_profiler_wrap_pre_template');
                    ucwp_request_profile_wrap_hook_callbacks('template_redirect');
                    ucwp_request_profile_wrap_hook_callbacks('wp_enqueue_scripts');
                    ucwp_request_profile_wrap_hook_callbacks('wp_calculate_image_srcset');
                    ucwp_request_profile_wrap_hook_callbacks('shutdown');
                }, 1000);

                add_action('wp_head', function () {
                    ucwp_request_profile_checkpoint('callback_profiler_wrap_pre_head_output');
                    ucwp_request_profile_wrap_hook_callbacks('wp_enqueue_scripts');
                    ucwp_request_profile_wrap_hook_callbacks('style_loader_src');
                    ucwp_request_profile_wrap_hook_callbacks('style_loader_tag');
                    ucwp_request_profile_wrap_hook_callbacks('script_loader_src');
                    ucwp_request_profile_wrap_hook_callbacks('script_loader_tag');
                    ucwp_request_profile_wrap_hook_callbacks('wp_calculate_image_srcset');
                }, -999);
            }
        }

        private function register_hooks()
        {
            $this->register_request_profile_hooks();
            add_action('plugins_loaded', array($this, 'bootstrap_components'), 5);
            add_action('plugins_loaded', array($this, 'reconcile_page_cache_dropin'), 19);
            add_action('plugins_loaded', array($this, 'reconcile_object_cache_dropin'), 20);
            add_action('plugins_loaded', array($this, 'reconcile_runtime_config'), 21);
            add_action('admin_menu', array($this, 'register_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            add_action('admin_enqueue_scripts', array($this, 'suppress_conflicting_admin_assets'), 999);
            add_action('admin_print_scripts-toplevel_page_ultracache', array($this, 'suppress_conflicting_admin_assets'), 1);
            add_action('admin_print_footer_scripts-toplevel_page_ultracache', array($this, 'suppress_conflicting_admin_assets'), 1);
            add_action('admin_notices', array($this, 'render_admin_notice'));
            add_action('admin_bar_menu', array($this, 'register_admin_bar_menu'), 100);
            add_action('init', array($this, 'handle_admin_bar_actions'));
            add_filter('cron_schedules', array($this, 'register_cron_schedules'));
            add_action('ucwp_scheduled_cache_cleanup', array($this, 'handle_scheduled_cache_cleanup'));
            add_action('ucwp_cron_warm_tick', array($this, 'handle_cron_warm_tick'));
            add_action('ucwp_cron_warm_tick_kickoff', array($this, 'handle_cron_warm_tick_kickoff'));
            add_action('ucwp_after_purge_all', array($this, 'handle_varnish_after_purge_all'), 10, 1);
            add_action('ucwp_after_purge_all', array($this, 'handle_cron_warm_after_purge_all'), 20, 1);
            add_action('ucwp_after_purge_urls', array($this, 'handle_varnish_after_purge_urls'), 10, 3);
            add_action('wp_loaded', array($this, 'maybe_fix_revslider_footer_conflict'), 1);
        }


        private static function maybe_translate($text)
        {
            $text = (string) $text;

            if ('' === $text || !did_action('init')) {
                return $text;
            }

            switch ($text) {
                case 'PHP Redis extension is not loaded on this server.':
                    return __('PHP Redis extension is not loaded on this server.', 'ultracache');

                case 'Every minute for UltraCache':
                    return __('Every minute for UltraCache', 'ultracache');

                case 'No PHP compression support detected on this server.':
                    return __('No PHP compression support detected on this server.', 'ultracache');

                case 'Brotli and gzip are available. UltraCache will prefer Brotli and fall back to gzip when needed.':
                    return __('Brotli and gzip are available. UltraCache will prefer Brotli and fall back to gzip when needed.', 'ultracache');

                case 'Brotli is available on this server. UltraCache will prefer Brotli compression.':
                    return __('Brotli is available on this server. UltraCache will prefer Brotli compression.', 'ultracache');

                case 'Brotli is not available on this server. UltraCache will use gzip compression instead.':
                    return __('Brotli is not available on this server. UltraCache will use gzip compression instead.', 'ultracache');

                case 'wp-config.php could not be located.':
                    return __('wp-config.php could not be located.', 'ultracache');

                case 'wp-config.php could not be read.':
                    return __('wp-config.php could not be read.', 'ultracache');

                case 'WP_CACHE is managed by UltraCache.':
                    return __('WP_CACHE is managed by UltraCache.', 'ultracache');

                case 'WP_CACHE is already defined as true in wp-config.php.':
                    return __('WP_CACHE is already defined as true in wp-config.php.', 'ultracache');

                case 'WP_CACHE is currently defined as false in wp-config.php and UltraCache will disable that line safely before enabling page cache.':
                    return __('WP_CACHE is currently defined as false in wp-config.php and UltraCache will disable that line safely before enabling page cache.', 'ultracache');

                case 'WP_CACHE is not currently defined in wp-config.php. UltraCache can add it automatically.':
                    return __('WP_CACHE is not currently defined in wp-config.php. UltraCache can add it automatically.', 'ultracache');

                case 'WP_CACHE is defined in a non-standard way in wp-config.php and UltraCache can replace it safely when enabling page cache.':
                    return __('WP_CACHE is defined in a non-standard way in wp-config.php and UltraCache can replace it safely when enabling page cache.', 'ultracache');

                case 'Reverse Proxy Cache':
                    return __('Reverse Proxy Cache', 'ultracache');

                case 'Read failed':
                    return __('Read failed', 'ultracache');

                case 'UltraCache':
                    return __('UltraCache', 'ultracache');

                case 'Strict local SSL verification failed and UltraCache temporarily retried the same-host HTTPS loopback request without certificate verification.':
                    return __('Strict local SSL verification failed and UltraCache temporarily retried the same-host HTTPS loopback request without certificate verification.', 'ultracache');

                case 'Cron warm start suppressed for this purge.':
                    return __('Cron warm start suppressed for this purge.', 'ultracache');

                case 'Cron warm up is disabled.':
                    return __('Cron warm up is disabled.', 'ultracache');

                case 'Cron warm up after scheduled cleanup is disabled.':
                    return __('Cron warm up after scheduled cleanup is disabled.', 'ultracache');

                case 'Cron warm up is paused because pages per minute is 0.':
                    return __('Cron warm up is paused because pages per minute is 0.', 'ultracache');

                case 'Cron warm up is not available.':
                    return __('Cron warm up is not available.', 'ultracache');

                case 'Cron warm up queued.':
                    return __('Cron warm up queued.', 'ultracache');

                case 'Cron warm up stopped.':
                    return __('Cron warm up stopped.', 'ultracache');

                case 'Cron warm up queue is idle.':
                    return __('Cron warm up queue is idle.', 'ultracache');

                case 'Cron warm up tick skipped because another run is active.':
                    return __('Cron warm up tick skipped because another run is active.', 'ultracache');

                case 'Varnish integration is disabled.':
                    return __('Varnish integration is disabled.', 'ultracache');

                case 'No Varnish endpoints are configured.':
                    return __('No Varnish endpoints are configured.', 'ultracache');

                case 'Could not determine site host for Varnish.':
                    return __('Could not determine site host for Varnish.', 'ultracache');

                case 'Invalid URL for Varnish purge.':
                    return __('Invalid URL for Varnish purge.', 'ultracache');

                case 'Could not determine site host for Varnish test.':
                    return __('Could not determine site host for Varnish test.', 'ultracache');

                case 'Redis helper not available.':
                    return __('Redis helper not available.', 'ultracache');

                case 'Object cache helper not available.':
                    return __('Object cache helper not available.', 'ultracache');
            }

            return $text;
        }

        private static function maybe_translate_sprintf($text)
        {
            $args = func_get_args();
            $text = (string) array_shift($args);
            $translated = $text;

            if (did_action('init')) {
                switch ($text) {
                    case 'Every %d hour(s) for UltraCache':
                        /* translators: %d: Number of hours between UltraCache cleanup runs. */
                        $translated = __('Every %d hour(s) for UltraCache', 'ultracache');
                        break;

                    case '%s detected. UltraCache hit counters reflect only requests that reach PHP/advanced-cache and may under-report public hits served before WordPress.':
                        /* translators: %s: Reverse proxy or server cache provider name. */
                        $translated = __('%s detected. UltraCache hit counters reflect only requests that reach PHP/advanced-cache and may under-report public hits served before WordPress.', 'ultracache');
                        break;

                    case 'UltraCache %1$s · Bundle %2$s':
                        /* translators: 1: UltraCache plugin version, 2: hotfix bundle version. */
                        $translated = __('UltraCache %1$s · Bundle %2$s', 'ultracache');
                        break;

                    case 'Varnish %1$s succeeded on %2$d endpoint(s).':
                        /* translators: 1: Varnish action label, 2: number of endpoints. */
                        $translated = __('Varnish %1$s succeeded on %2$d endpoint(s).', 'ultracache');
                        break;

                    case 'Varnish %s failed on one or more endpoints.':
                        /* translators: %s: Varnish action label. */
                        $translated = __('Varnish %s failed on one or more endpoints.', 'ultracache');
                        break;
                }
            }

            if (empty($args)) {
                return $translated;
            }

            return vsprintf($translated, $args);
        }

        public function reconcile_object_cache_dropin()
        {
            ucwp_request_profile_checkpoint('object_cache_reconcile_entry');

            $is_full_reconcile = $this->should_run_full_object_cache_reconcile();
            ucwp_request_profile_checkpoint('object_cache_reconcile_context_checked', array(
                'full_reconcile' => $is_full_reconcile ? 'true' : 'false',
            ));

            if (!class_exists('Ultra_Cache_Object_Cache_Manager')) {
                ucwp_request_profile_checkpoint('object_cache_reconcile_skipped', array('reason' => 'manager_class_missing'));
                return;
            }

            // Frontend traffic performs only a read-only drop-in health check.
            // File writes/removals remain in admin, activation, settings-save, and WP-CLI contexts
            // so WordPress Filesystem API can be used where mutation is required.
            if (!$is_full_reconcile) {
                ucwp_request_profile_checkpoint('object_cache_reconcile_frontend_before_raw_setting');
                $object_cache_enabled = self::is_object_cache_enabled_raw_fast();
                ucwp_request_profile_checkpoint('object_cache_reconcile_frontend_after_raw_setting', array(
                    'object_cache_enabled' => $object_cache_enabled ? 'true' : 'false',
                ));

                if (!$object_cache_enabled) {
                    ucwp_request_profile_checkpoint('object_cache_reconcile_skipped', array('reason' => 'disabled_frontend_fast'));
                    return;
                }

                ucwp_request_profile_checkpoint('object_cache_reconcile_light_start');
                $status = method_exists('Ultra_Cache_Object_Cache_Manager', 'get_dropin_status_fast')
                    ? Ultra_Cache_Object_Cache_Manager::get_dropin_status_fast()
                    : array('healthy' => false, 'reason' => 'status_method_missing');

                if (!empty($status['healthy']) && function_exists('wp_using_ext_object_cache')) {
                    wp_using_ext_object_cache(true);
                }

                ucwp_request_profile_checkpoint('object_cache_reconcile_light_end', array(
                    'mode' => 'read_only_frontend',
                    'healthy' => !empty($status['healthy']) ? 'true' : 'false',
                    'reason' => isset($status['reason']) ? (string) $status['reason'] : '',
                    'exists' => !empty($status['exists']) ? 'true' : 'false',
                    'readable' => !empty($status['readable']) ? 'true' : 'false',
                    'has_marker' => !empty($status['has_marker']) ? 'true' : 'false',
                    'build' => isset($status['build']) ? (string) $status['build'] : '',
                    'expected_build' => isset($status['expected_build']) ? (string) $status['expected_build'] : '',
                ));
                return;
            }

            ucwp_request_profile_checkpoint('object_cache_reconcile_full_start', array('mode' => 'admin_cli_repair'));
            if (method_exists('Ultra_Cache_Object_Cache_Manager', 'sync_dropin')) {
                $result = Ultra_Cache_Object_Cache_Manager::sync_dropin();
                ucwp_request_profile_checkpoint('object_cache_reconcile_full_end', array(
                    'mode' => 'admin_cli_repair',
                    'result' => $result ? 'true' : 'false',
                ));
                return;
            }

            ucwp_request_profile_checkpoint('object_cache_reconcile_full_end', array(
                'mode' => 'admin_cli_repair',
                'result' => 'method_missing',
            ));
        }

        private function should_run_full_object_cache_reconcile()
        {
            if (defined('WP_CLI') && WP_CLI) {
                return true;
            }

            if (function_exists('is_admin') && is_admin()) {
                return true;
            }

            return false;
        }

        private function should_run_full_page_cache_reconcile()
        {
            if (defined('WP_CLI') && WP_CLI) {
                return true;
            }

            // File mutations are intentionally kept in privileged/admin contexts.
            // Public frontend requests only perform a read-only health check so they
            // do not initialize WP_Filesystem or attempt drop-in repair on traffic.
            if (function_exists('is_admin') && is_admin()) {
                return true;
            }

            return false;
        }

        private static function is_page_cache_enabled_raw_fast()
        {
            static $cached = null;

            if (null !== $cached) {
                return $cached;
            }

            $saved = get_option(UCWP_SETTINGS_KEY, array());
            $cached = is_array($saved) && !empty($saved['pageCacheEnabled']);

            return $cached;
        }

        private static function is_object_cache_enabled_raw_fast()
        {
            static $cached = null;

            if (null !== $cached) {
                return $cached;
            }

            $saved = get_option(UCWP_SETTINGS_KEY, array());
            $cached = is_array($saved) && !empty($saved['objectCacheEnabled']);

            return $cached;
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

        public function reconcile_page_cache_dropin()
        {
            ucwp_request_profile_checkpoint('page_cache_reconcile_entry');

            $is_full_reconcile = $this->should_run_full_page_cache_reconcile();
            ucwp_request_profile_checkpoint('page_cache_reconcile_context_checked', array(
                'full_reconcile' => $is_full_reconcile ? 'true' : 'false',
            ));

            // Frontend traffic must stay cheap and read-only. Do not call the full
            // dashboard settings sanitizer here because it may canonicalize/update the
            // settings option. File writes and repairs remain in admin/CLI contexts.
            if (!$is_full_reconcile) {
                ucwp_request_profile_checkpoint('page_cache_reconcile_frontend_before_raw_setting');
                $page_cache_enabled = self::is_page_cache_enabled_raw_fast();
                ucwp_request_profile_checkpoint('page_cache_reconcile_frontend_after_raw_setting', array(
                    'page_cache_enabled' => $page_cache_enabled ? 'true' : 'false',
                ));

                if (!$page_cache_enabled || !defined('WP_CACHE') || !WP_CACHE) {
                    ucwp_request_profile_checkpoint('page_cache_reconcile_skipped', array('reason' => 'disabled_or_wp_cache_false_frontend_fast'));
                    return;
                }

                ucwp_request_profile_checkpoint('page_cache_reconcile_frontend_before_engine_class');
                $engine_class = self::get_engine_class();
                ucwp_request_profile_checkpoint('page_cache_reconcile_frontend_after_engine_class', array(
                    'engine_class' => $engine_class ? 'true' : 'false',
                ));
                if (!$engine_class) {
                    ucwp_request_profile_checkpoint('page_cache_reconcile_skipped', array('reason' => 'engine_class_missing'));
                    return;
                }

                ucwp_request_profile_checkpoint('page_cache_reconcile_light_start');
                $status = method_exists($engine_class, 'get_advanced_cache_dropin_status')
                    ? $engine_class::get_advanced_cache_dropin_status()
                    : array('healthy' => false, 'reason' => 'status_method_missing');

                ucwp_request_profile_checkpoint('page_cache_reconcile_light_end', array(
                    'mode' => 'read_only_frontend',
                    'healthy' => !empty($status['healthy']) ? 'true' : 'false',
                    'reason' => isset($status['reason']) ? (string) $status['reason'] : '',
                    'exists' => !empty($status['exists']) ? 'true' : 'false',
                    'readable' => !empty($status['readable']) ? 'true' : 'false',
                    'has_marker' => !empty($status['has_marker']) ? 'true' : 'false',
                    'build' => isset($status['build']) ? (string) $status['build'] : '',
                    'expected_build' => isset($status['expected_build']) ? (string) $status['expected_build'] : '',
                ));
                return;
            }

            ucwp_request_profile_checkpoint('page_cache_reconcile_full_before_settings');
            $settings = self::get_dashboard_settings();
            ucwp_request_profile_checkpoint('page_cache_reconcile_full_after_settings', array(
                'page_cache_enabled' => !empty($settings['pageCacheEnabled']) ? 'true' : 'false',
            ));

            if (empty($settings['pageCacheEnabled']) || !defined('WP_CACHE') || !WP_CACHE) {
                ucwp_request_profile_checkpoint('page_cache_reconcile_skipped', array('reason' => 'disabled_or_wp_cache_false_admin_cli'));
                return;
            }

            $engine_class = self::get_engine_class();
            if (!$engine_class) {
                ucwp_request_profile_checkpoint('page_cache_reconcile_skipped', array('reason' => 'engine_class_missing'));
                return;
            }

            ucwp_request_profile_checkpoint('page_cache_reconcile_full_start', array('mode' => 'admin_cli_repair'));
            if (method_exists($engine_class, 'setup_advanced_cache')) {
                $engine_class::setup_advanced_cache(true);
            }
            ucwp_request_profile_checkpoint('page_cache_reconcile_full_end', array('mode' => 'admin_cli_repair'));
        }

        public function reconcile_runtime_config()
        {
            ucwp_request_profile_checkpoint('runtime_config_reconcile_entry');

            $is_full_reconcile = $this->should_run_full_runtime_config_reconcile();
            ucwp_request_profile_checkpoint('runtime_config_reconcile_context_checked', array(
                'full_reconcile' => $is_full_reconcile ? 'true' : 'false',
            ));

            if (!$is_full_reconcile) {
                ucwp_request_profile_checkpoint('runtime_config_reconcile_frontend_before_raw_setting');
                $page_cache_enabled = self::is_page_cache_enabled_raw_fast();
                ucwp_request_profile_checkpoint('runtime_config_reconcile_frontend_after_raw_setting', array(
                    'page_cache_enabled' => $page_cache_enabled ? 'true' : 'false',
                ));

                if (!$page_cache_enabled || !defined('WP_CACHE') || !WP_CACHE) {
                    ucwp_request_profile_checkpoint('runtime_config_reconcile_skipped', array('reason' => 'disabled_or_wp_cache_false_frontend_fast'));
                    return;
                }

                ucwp_request_profile_checkpoint('runtime_config_reconcile_light_start');
                $status = self::get_runtime_config_status_fast();
                ucwp_request_profile_checkpoint('runtime_config_reconcile_light_end', array(
                    'mode' => 'read_only_frontend',
                    'healthy' => !empty($status['healthy']) ? 'true' : 'false',
                    'reason' => isset($status['reason']) ? (string) $status['reason'] : '',
                    'exists' => !empty($status['exists']) ? 'true' : 'false',
                    'readable' => !empty($status['readable']) ? 'true' : 'false',
                    'valid_json' => !empty($status['valid_json']) ? 'true' : 'false',
                ));
                return;
            }

            ucwp_request_profile_checkpoint('runtime_config_reconcile_full_before_settings');
            $settings = self::get_dashboard_settings();
            ucwp_request_profile_checkpoint('runtime_config_reconcile_full_after_settings', array(
                'page_cache_enabled' => !empty($settings['pageCacheEnabled']) ? 'true' : 'false',
            ));

            if (empty($settings['pageCacheEnabled']) || !defined('WP_CACHE') || !WP_CACHE) {
                ucwp_request_profile_checkpoint('runtime_config_reconcile_skipped', array('reason' => 'disabled_or_wp_cache_false_admin_cli'));
                return;
            }

            ucwp_request_profile_checkpoint('runtime_config_reconcile_full_before_needs_sync');
            $needs_sync = self::runtime_config_needs_sync();
            ucwp_request_profile_checkpoint('runtime_config_reconcile_full_after_needs_sync', array(
                'needs_sync' => $needs_sync ? 'true' : 'false',
            ));

            if ($needs_sync) {
                ucwp_request_profile_checkpoint('runtime_config_reconcile_full_before_sync');
                self::sync_runtime_config();
                ucwp_request_profile_checkpoint('runtime_config_reconcile_full_after_sync');
            }
        }

        private function should_run_full_runtime_config_reconcile()
        {
            if (defined('WP_CLI') && WP_CLI) {
                return true;
            }

            if (function_exists('is_admin') && is_admin()) {
                return true;
            }

            return false;
        }

        public function bootstrap_components()
        {
            $component_classes = array(
                array('Ultra_Cache_Engine', 'get_instance'),
                array('Ultra_Cache_Media_Converter', 'get_instance'),
                array('Ultra_Cache_Rest_API', 'get_instance'),
                array('Ultra_Cache_WP_CLI', 'register'),
            );

            foreach ($component_classes as $component) {
                list($class, $method) = $component;
                if (class_exists($class) && method_exists($class, $method)) {
                    call_user_func(array($class, $method));
                }
            }
        }

        public static function activate()
        {
            self::ensure_directories();

            // Do not create a full settings row on first install. Missing boolean
            // switches are treated as off at runtime; non-boolean settings use safe
            // runtime fallbacks until the user explicitly saves settings or applies a profile.
            self::reset_settings_cache();

            self::sync_page_cache_bootstrap();
            self::sync_runtime_config();
            self::sync_scheduled_events();
            $browser_cache_sync = self::sync_browser_cache_rules();
            if (false === $browser_cache_sync) {
                set_transient(
                    'ucwp_admin_notice',
                    array(
                        'type'    => 'warning',
                        'message' => self::maybe_translate('UltraCache: Browser Cache Headers are enabled, but .htaccess could not be updated during activation. Check file permissions or disable Browser Cache Headers.'),
                    ),
                    90
                );
            }

            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'sync_dropin')) {
                Ultra_Cache_Object_Cache_Manager::sync_dropin();
                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache')) {
                    Ultra_Cache_Object_Cache_Manager::flush_cache(true, true);
                }
            }
        }

        public static function deactivate()
        {
            self::sync_page_cache_bootstrap(false);
            self::unschedule_scheduled_events();
            self::unschedule_cron_warm_events();
            self::sync_browser_cache_rules(false);

            if (class_exists('Ultra_Cache_Object_Cache_Manager')) {
                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache')) {
                    Ultra_Cache_Object_Cache_Manager::flush_cache(true, true);
                }
                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'maybe_remove_dropin')) {
                    Ultra_Cache_Object_Cache_Manager::maybe_remove_dropin();
                }
            }
        }

        private static function ensure_directories()
        {
            $dirs = array(
                UCWP_CACHE_DIR,
                UCWP_AVIF_DIR,
                UCWP_WEBP_DIR,
                UCWP_OBJECT_CACHE_DIR,
                trailingslashit(UCWP_CACHE_DIR) . 'google-fonts/',
            );

            foreach ($dirs as $dir) {
                if (!file_exists($dir)) {
                    wp_mkdir_p($dir);
                }

                $index = trailingslashit($dir) . 'index.php';
                if (!file_exists($index)) {
                    ucwp_safe_file_put_contents($index, "<?php\n// Silence is golden.\n", 0, 'ensure_directories index');
                }
            }

            self::ensure_runtime_config_protection_files();
        }

        public static function get_dashboard_defaults()
        {
            ucwp_request_profile_checkpoint('sanitize_settings_before_compression_support');
            $compression_support = self::get_compression_support_status();
            ucwp_request_profile_checkpoint('sanitize_settings_after_compression_support', array('brotli' => !empty($compression_support['brotli']) ? 'true' : 'false', 'gzip' => !empty($compression_support['gzip']) ? 'true' : 'false'));
            ucwp_request_profile_checkpoint('sanitize_settings_before_frontend_compression_cached');
            $frontend_compression = self::get_frontend_compression_probe_status(false);
            ucwp_request_profile_checkpoint('sanitize_settings_after_frontend_compression_cached', array('brotli' => !empty($frontend_compression['brotli']) ? 'true' : 'false', 'gzip' => !empty($frontend_compression['gzip']) ? 'true' : 'false', 'broken_brotli' => !empty($frontend_compression['brokenBrotli']) ? 'true' : 'false', 'broken_gzip' => !empty($frontend_compression['brokenGzip']) ? 'true' : 'false'));
            ucwp_request_profile_checkpoint('sanitize_settings_before_media_support');
            $media_support = self::get_media_support_status();
            ucwp_request_profile_checkpoint('sanitize_settings_after_media_support', array('supported' => !empty($media_support['supported']) ? 'true' : 'false'));
            $crawl_scope_summary = self::get_crawl_scope_summary();
            $default_scheduled_warm_limit = max(1, (int) ($crawl_scope_summary['defaultScheduledWarmLimit'] ?? 0));

            return array(
                'pageCacheEnabled'           => false,
                'objectCacheEnabled'         => false,
                'objectCacheBackend'         => 'redis',
                'redisHost'                  => '127.0.0.1',
                'redisPort'                  => 6379,
                'redisPassword'              => '',
                'redisDatabase'              => 0,
                'redisPrefix'                => '',
                'redisUseTls'                => false,
                'redisPersistent'            => false,
                'redisConnectTimeoutMs'      => 200,
                'redisReadTimeoutMs'         => 200,
                'brotliEnabled'              => false,
                'gzipEnabled'                => false,
                'cacheStatsEnabled'          => false,
                'mediaOptimizationEnabled'     => false,
                'mediaGenerateOnUploadEnabled' => false,
                'mediaGenerateOnDemandEnabled' => false,
                'mediaOutputMode'            => 'auto',
                'deferJsEnabled'             => false,
                'deferAllJsEnabled'          => false,
                'deferJsForceList'           => '',
                'deferJsExcludeList'         => '',
                'delaySafeThirdPartyJsEnabled'   => false,
                'lazyMailerliteNonceEnabled' => true,
                'delaySafeThirdPartyJsPatterns' => implode("\n", self::get_default_safe_third_party_delay_patterns()),
                'delayFunctionalThirdPartyJsEnabled' => false,
                'delayFunctionalThirdPartyJsPatterns' => implode("\n", self::get_default_functional_third_party_delay_patterns()),
                'delayThirdPartyJsExcludeList' => '',
                'asyncExternalScriptsEnabled'=> false,
                'homepageCssBundleEnabled'   => false,
                'homepageCssBundleInlineEnabled' => false,
                'leftoverCssBundleEnabled'   => false,
                'homepageCssBundleExcludeList' => '',
                'homepageCssBundleMode'      => 'safe',
                'delayIconFontsEnabled'      => false,
                'delayIconFontsAutoDetectEnabled' => true,
                'delayIconFontsList'         => implode("\n", self::get_default_delay_icon_font_patterns()),
                'delayIconFontsExcludeList'  => implode("\n", self::get_default_delay_icon_font_exclude_patterns()),
                'cssBundleScope'            => 'homepage',
                'pageCssBundleOnEntryEnabled' => false,
                'frontendSafeModeEnabled'    => false,
                'sliderSafeModeEnabled'       => false,
                'clsDimensionsEnabled'       => false,
                'asyncCssEnabled'            => false,
                'asyncCssExcludeList'        => '',
                'aggressiveAsyncCssEnabled'  => false,
                'aggressiveAsyncCssExcludeList' => '',
                'delayNonCriticalJsEnabled'  => false,
                'delayNonCriticalJsExcludeList' => '',
                'lcpImagePriorityEnabled'    => false,
                'lcpBoundaryDeferEnabled'    => false,
                'lcpImagePriorityOverride'   => '',
                'manualLcpHeroSelector'      => '',
                'mainThreadReliefEnabled'    => false,
                'criticalRequestChainReliefEnabled' => false,
                'criticalResourcePreloadList' => '',
                'criticalFetchPreloadList'    => '',
                'criticalRequestChainDelayList' => '',
                'assetChainCleanupEnabled'    => false,
                'assetCleanupWooProductAssetsEnabled' => false,
                'assetCleanupProductFilterAssetsEnabled' => false,
                'assetCleanupWooBlocksCssEnabled' => false,
                'assetCleanupExcludeList'     => "elementor\nbricks\noxygen\nwpbakery\nvc_\nrevslider\nsr7\najaxsearch\nfibosearch\n.dgwt-wcas\naws-container\ncart\ncheckout\naccount",
                'googleFontsSwapEnabled'     => false,
                'googleFontsLocalOptimizationEnabled' => false,
                'googleFontsAdditionalScanUrls' => '',
                'selfHostedFontCssOptimizationEnabled' => false,
                'selfHostedFontRuntimeRewriteEnabled' => false,
                'speculationRulesEnabled'    => false,
                'browserCacheRulesEnabled'   => false,
                'varnishCliEnabled'          => false,
                'varnishCliMode'             => 'http',
                'varnishCliServers'          => self::get_default_varnish_http_endpoint(),
                'varnishCliKey'              => '',
                'varnishCliTimeoutSeconds'   => 2,
                'varnishCliMethod'           => 'BAN',
                'varnishCliDebug'            => false,
                'preRenderOnSave'            => false,
                'woocommerceSafeModeEnabled' => false,
                'cacheCleanupEnabled'        => false,
                'apcuFlushOnScheduledCleanup'=> false,
                'cacheCleanupIntervalHours'  => 24,
                'cronWarmEnabled'            => false,
                'cronWarmStartAfterCleanup'  => false,
                'cronWarmStartAfterManualPurge' => false,
                'cronWarmPagesPerMinute'     => 2,
                'scheduledWarmLimit'         => $default_scheduled_warm_limit,
                'staleWhileRevalidateEnabled'=> false,
                'cacheFreshTtlMinutes'       => 15,
                'cacheMaxStaleMinutes'       => 720,
                'cacheExceptionPaths'        => implode("\n", self::get_default_excluded_paths()),
                'cacheExceptionQueryArgs'    => implode("\n", self::get_default_excluded_query_args()),
                'cacheQueryStringsEnabled'   => false,
                'cacheQueryStringAllowlist'  => '',
            );
        }

        private static function get_default_excluded_paths()
        {
            return array(
                '/cart/',
                '/checkout/',
                '/my-account/',
                '/wp-admin/',
                '/wp-login.php',
                '/wc-api/',
                '/wp-json/',
            );
        }

        private static function get_default_excluded_query_args()
        {
            return array(
                'preview',
                'customize_changeset_uuid',
                'customize_autosaved',
                'elementor-preview',
                'vc_editable',
                'et_fb',
                'add-to-cart',
                'wc-ajax',
                'remove_item',
                'undo_item',
                'apply_coupon',
                'remove_coupon',
                'order_again',
            );
        }

        private static function get_default_delay_icon_font_patterns()
        {
            return array(
                'fa-solid-900',
                'fa-regular',
                'fa-brands',
                'fontawesome',
                'font awesome',
                'eicons',
                'dashicons',
                'bokifa-icon',
                'icomoon',
                'flaticon',
                'themify',
                'simple-line-icons',
                'linearicons',
                'material-icons',
                'materialicons',
                'ionicons',
            );
        }

        private static function get_default_delay_icon_font_exclude_patterns()
        {
            return array(
                'manrope',
                'fraunces',
                'roboto',
                'open-sans',
                'inter',
                'lato',
                'montserrat',
                'poppins',
                'source-sans',
                'noto',
                '2920108',
                'google-fonts',
                'body',
                'heading',
            );
        }

        private static function get_default_js_delay_defer_exclusion_patterns()
        {
            return array(
                'revslider',
                'sr7',
                'tptools',
                'elementor-frontend',
                'swiper',
                'slick',
                'splide',
                'owl.carousel',
                'smartslider',
                'n2-ss',
                'html_types/image',
                'html_types/color',
                'html_types/label',
                'official-mailerlite-sign-up-forms/assets/js/localization/validation-messages.js',
                'validation-messages.js',
            );
        }

        private static function get_defer_all_js_legacy_conservative_exclusion_patterns()
        {
            return array(
                'official-mailerlite-sign-up-forms/assets/js/localization/validation-messages.js',
                'revslider',
                'sliderrevolution',
                'slider-revolution',
                'revolution',
                'sr7',
                'rs6',
                'rs7',
                'tptools',
                'tp-tools',
                'rs-module',
                'wp-block-themepunch-revslider',
                'swiper',
                'swiper-bundle',
                'slick',
                'splide',
                'owl.carousel',
                'smartslider',
                'smart-slider',
                'n2-ss',
                'elementor',
                'elementor-frontend',
                'frontend-modules',
                'webpack.runtime',
                'webpack-pro.runtime',
                'pro-elements-handlers',
                'smartmenus',
                'html_types/image',
                'html_types/color',
                'html_types/label',
                'html_types/slide',
                'html_types/slider',
                'product-ajax-search',
                'search-popup',
                'nav-mobile',
                'megamenu',
                'header-cart',
                'cart-canvas',
                'off-canvas',
                'woocommerce-products-filter',
                'woof_',
                'contact-form-7',
                'author-arc',
                'typewriting-author-arc/assets/js/form-handler.js',
                'mailerlite',
                'mailchimp',
                'mc4wp',
                'complianz',
                'cmplz',
                'cky-',
            );
        }

        private static function strip_defer_all_js_legacy_conservative_exclusions($value)
        {
            $normalized = self::normalize_textarea_setting($value);
            if ('' === $normalized) {
                return '';
            }

            $legacy = array();
            foreach (self::get_defer_all_js_legacy_conservative_exclusion_patterns() as $pattern) {
                $pattern = strtolower(trim((string) $pattern));
                if ('' !== $pattern) {
                    $legacy[$pattern] = true;
                }
            }

            $kept = array();
            foreach (preg_split('/\r\n|\r|\n/', $normalized) as $line) {
                $line = trim((string) $line);
                if ('' === $line) {
                    continue;
                }
                if (isset($legacy[strtolower($line)])) {
                    continue;
                }
                $kept[] = $line;
            }

            return implode("\n", array_values(array_unique($kept)));
        }

        private static function get_default_safe_third_party_delay_patterns()
        {
            return array(
                'googletagmanager.com',
                'google-analytics.com',
                'gtag/js',
                'gtm.js',
                'googlesitekit-events-provider',
                'google-site-kit/dist/assets/js',
                'connect.facebook.net',
                'fbevents.js',
                'fbq',
                'analytics.tiktok.com',
                'snap.licdn.com',
                'insight.min.js',
                'bat.bing.com',
                'clarity.ms',
                'static.hotjar.com',
                'script.hotjar.com',
                's.pinimg.com',
                'pintrk',
                'doubleclick.net',
                'googleadservices.com',
                'taboola',
                'outbrain',
                'yahoo',
                'yimg.com',
            );
        }

        private static function get_default_functional_third_party_delay_patterns()
        {
            return array(
                'recaptcha',
                'hcaptcha',
                'google.com/recaptcha',
                'gstatic.com/recaptcha',
                'maps.googleapis.com',
                'maps.gstatic.com',
                'complianz',
                'cmplz',

                'cookieyes',
                'cky-',
                'intercom',
                'crisp.chat',
                'tawk.to',
                'zendesk',
                'calendly',
                'typeform',
                'jotform',
            );
        }

        public static function get_crawl_scope_summary()
        {
            $fallback = array(
                'baseUrlCount' => 1,
                'menuUrlCount' => 0,
                'seedUrlCount' => 1,
                'postUrlCount' => 0,
                'termUrlCount' => 0,
                'contentUrlCount' => 0,
                'estimatedTotal' => 1,
                'maxUrls' => 5000,
                'defaultScheduledWarmLimit' => 1,
            );

            if (!function_exists('home_url')) {
                return $fallback;
            }

            $engine = self::get_engine_instance();
            if ($engine && method_exists($engine, 'get_crawl_scope_summary')) {
                $summary = $engine->get_crawl_scope_summary();
                if (is_array($summary)) {
                    return array_merge($fallback, $summary);
                }
            }

            return $fallback;
        }

        private static function normalize_textarea_setting($value)
        {
            if (is_array($value)) {
                $value = implode("\n", array_map('strval', $value));
            }

            $value = str_replace(array("\r\n", "\r"), "\n", (string) $value);
            $lines = array_filter(array_map('trim', explode("\n", $value)), static function ($line) {
                return '' !== $line;
            });

            return implode("\n", array_values(array_unique($lines)));
        }

        private static function merge_textarea_settings($first, $second)
        {
            $lines = array_merge(self::parse_textarea_setting($first), self::parse_textarea_setting($second));
            return self::normalize_textarea_setting($lines);
        }

        private static function normalize_multiline_setting_with_callback($value, callable $callback, $limit = 200)
        {
            $lines = self::parse_textarea_setting($value);
            $normalized = array();

            foreach ($lines as $line) {
                $sanitized = call_user_func($callback, $line);
                if (!is_string($sanitized) || '' === $sanitized) {
                    continue;
                }

                $normalized[$sanitized] = $sanitized;
                if (count($normalized) >= max(1, absint($limit))) {
                    break;
                }
            }

            return implode("\n", array_values($normalized));
        }

        private static function get_reserved_setting_keys()
        {
            return array(
                '__proto__',
                'constructor',
                'prototype',
            );
        }

        private static function sanitize_setting_key_line($value)
        {
            $value = strtolower(trim((string) $value));
            if ('' === $value) {
                return '';
            }

            if (!preg_match('/^[a-z0-9_-]{1,64}$/', $value)) {
                return '';
            }

            if (in_array($value, self::get_reserved_setting_keys(), true)) {
                return '';
            }

            return $value;
        }

        private static function sanitize_setting_key_list($value, $limit = 200)
        {
            return self::normalize_multiline_setting_with_callback($value, array(__CLASS__, 'sanitize_setting_key_line'), $limit);
        }

        private static function sanitize_excluded_path_line($value)
        {
            $rule = html_entity_decode(trim((string) $value), ENT_QUOTES, 'UTF-8');
            if ('' === $rule) {
                return '';
            }

            $rule = str_replace('\\', '/', $rule);
            if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $rule)) {
                return '';
            }

            if (false !== strpos($rule, '?') || false !== strpos($rule, '#')) {
                return '';
            }

            if (preg_match('/[[:cntrl:]\s]/u', $rule)) {
                return '';
            }

            if ('/' !== substr($rule, 0, 1)) {
                return '';
            }

            if ('/' === $rule) {
                return '';
            }

            if (false !== strpos($rule, '//')) {
                return '';
            }

            $wildcard = false;
            if (substr($rule, -2) === '/*') {
                $wildcard = true;
                $rule = substr($rule, 0, -2);
            } elseif (false !== strpos($rule, '*')) {
                return '';
            }

            if ('' === $rule || '/' === $rule) {
                return '';
            }

            foreach (explode('/', trim($rule, '/')) as $segment) {
                if ('' === $segment || '.' === $segment || '..' === $segment) {
                    return '';
                }
            }

            if ($wildcard) {
                $rule = rtrim($rule, '/') . '/*';
            }

            return $rule;
        }

        private static function sanitize_excluded_paths_setting($value, $limit = 200)
        {
            return self::normalize_multiline_setting_with_callback($value, array(__CLASS__, 'sanitize_excluded_path_line'), $limit);
        }

        private static function sanitize_bounded_integer_setting($value, $default, $min, $max)
        {
            $default = (int) $default;
            $min = (int) $min;
            $max = (int) $max;

            if ($min > $max) {
                $swap = $min;
                $min = $max;
                $max = $swap;
            }

            if ($default < $min || $default > $max) {
                $default = max($min, min($max, $default));
            }

            if (is_string($value)) {
                $value = trim($value);
                if ('' === $value || !preg_match('/^\d+$/', $value)) {
                    return $default;
                }
                $value = (int) $value;
            } elseif (is_int($value)) {
                $value = (int) $value;
            } else {
                return $default;
            }

            if ($value < $min || $value > $max) {
                return $default;
            }

            return $value;
        }

        private static function parse_textarea_setting($value)
        {
            $normalized = self::normalize_textarea_setting($value);
            if ('' === $normalized) {
                return array();
            }

            return array_values(array_unique(array_filter(array_map('trim', explode("\n", $normalized)))));
        }


        private static function sanitize_object_cache_backend($value)
        {
            $value = strtolower(trim((string) $value));
            return in_array($value, array('redis', 'apcu', 'disk'), true) ? $value : 'redis';
        }

        private static function sanitize_homepage_css_bundle_mode($value)
        {
            $value = strtolower(trim((string) $value));
            return in_array($value, array('safe', 'aggressive', 'full'), true) ? $value : 'safe';
        }

        private static function sanitize_css_bundle_scope($value)
        {
            $value = strtolower(trim((string) $value));
            return in_array($value, array('homepage', 'shared', 'per-page'), true) ? $value : 'homepage';
        }

        private static function sanitize_redis_host($value)
        {
            $value = trim((string) $value);
            if ('' === $value) {
                return '127.0.0.1';
            }

            $value = preg_replace('/[\r\n\t\0\x0B]+/', '', $value);
            $value = trim((string) $value);
            if ('' === $value) {
                return '127.0.0.1';
            }

            if (strlen($value) > 255) {
                $value = substr($value, 0, 255);
            }

            return $value;
        }

        private static function sanitize_redis_database($value)
        {
            return self::sanitize_bounded_integer_setting($value, 0, 0, 15);
        }

        private static function sanitize_redis_prefix($value)
        {
            $value = trim((string) $value);
            if ('' === $value) {
                return '';
            }

            $value = preg_replace('/[^A-Za-z0-9:_\-]/', '', $value);
            $value = trim((string) $value, ':');

            return '' === $value ? '' : $value . ':';
        }

        private static function get_redis_support_status()
        {
            $available = class_exists('Redis') || extension_loaded('redis');
            $message = '';

            if (!$available) {
                $message = self::maybe_translate('PHP Redis extension is not loaded on this server.');
            }

            return array(
                'available' => $available,
                'message'   => $message,
            );
        }

        private static function sanitize_varnish_mode($value)
        {
            return ('admin' === strtolower(trim((string) $value))) ? 'admin' : 'http';
        }

        private static function get_default_varnish_http_endpoint()
        {
            return '127.0.0.1:82';
        }

        private static function get_default_varnish_admin_endpoint()
        {
            return '127.0.0.1:6082';
        }

        private static function get_varnish_http_allowed_ports()
        {
            $ports = apply_filters('ucwp_varnish_http_endpoint_ports', array(82, 6081));
            if (!is_array($ports)) {
                $ports = array(82, 6081);
            }

            $ports = array_values(array_unique(array_filter(array_map('intval', $ports))));
            return !empty($ports) ? $ports : array(82, 6081);
        }

        private static function normalize_varnish_compare_host($host)
        {
            $host = strtolower(trim((string) $host));
            $host = trim($host, "[] \t\n\r\0\x0B.");
            if (0 === strpos($host, 'www.')) {
                $host = substr($host, 4);
            }
            return $host;
        }

        private static function get_varnish_site_host_candidates()
        {
            $hosts = array();
            $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
            if ($home_host) {
                $hosts[] = self::normalize_varnish_compare_host($home_host);
            }

            foreach (array('HTTP_HOST', 'SERVER_NAME', 'SERVER_ADDR', 'LOCAL_ADDR') as $key) {
                if (!empty($_SERVER[$key]) && is_scalar($_SERVER[$key])) {
                    $hosts[] = self::normalize_varnish_compare_host((string) wp_unslash($_SERVER[$key]));
                }
            }

            $expanded = array();
            foreach ($hosts as $host) {
                if ('' === $host) {
                    continue;
                }
                $expanded[] = $host;
                $expanded[] = 'www.' . $host;
            }

            return array_values(array_unique(array_filter($expanded)));
        }

        private static function get_varnish_http_endpoint_block_message($host, $port)
        {
            $host = self::normalize_varnish_compare_host($host);
            $port = (int) $port;

            if ('' === $host || $port <= 0) {
                return self::maybe_translate('Invalid Varnish HTTP endpoint. Use host:port, for example 127.0.0.1:82.');
            }

            $allowed_ports = self::get_varnish_http_allowed_ports();
            if (!in_array($port, $allowed_ports, true)) {
                return self::maybe_translate_sprintf('Blocked unsafe Varnish HTTP endpoint %1$s:%2$d. HTTP mode may only target a Varnish listener on port %3$s. Do not use the public WordPress frontend on ports 80/443; use 127.0.0.1:82 for HTTP mode or Admin mode 127.0.0.1:6082.', $host, $port, implode('/', $allowed_ports));
            }

            if (in_array($host, self::get_varnish_site_host_candidates(), true) && in_array($port, array(80, 443, 8443), true)) {
                return self::maybe_translate_sprintf('Blocked unsafe Varnish endpoint %1$s:%2$d because it points to the public WordPress frontend. Use 127.0.0.1:82 for HTTP mode or Admin mode 127.0.0.1:6082.', $host, $port);
            }

            return '';
        }

        private static function validate_varnish_http_endpoint($terminal)
        {
            $terminal = trim((string) $terminal);
            if ('' === $terminal) {
                return array('valid' => false, 'message' => self::maybe_translate('Empty Varnish HTTP endpoint. Use 127.0.0.1:82.'));
            }

            list($host, $port) = self::parse_varnish_terminal($terminal);
            $message = self::get_varnish_http_endpoint_block_message($host, $port);
            if ('' !== $message) {
                return array('valid' => false, 'message' => $message, 'host' => $host, 'port' => $port);
            }

            if (!ucwp_is_allowed_socket_target($host, $port)) {
                return array('valid' => false, 'message' => self::maybe_translate_sprintf('Varnish HTTP endpoint %1$s:%2$d is not allowed by the socket target allowlist.', $host, $port), 'host' => $host, 'port' => $port);
            }

            return array('valid' => true, 'message' => '', 'host' => $host, 'port' => $port);
        }

        private static function get_varnish_endpoint_diagnostics($servers, $mode = 'http')
        {
            $mode = self::sanitize_varnish_mode($mode);
            $servers = self::sanitize_varnish_servers_string($servers, $mode);
            $items = array_values(array_filter(array_map('trim', preg_split('/\s+/', (string) $servers))));
            $messages = array();
            $unsafe = false;

            if ('http' === $mode) {
                foreach ($items as $server) {
                    $check = self::validate_varnish_http_endpoint($server);
                    if (empty($check['valid'])) {
                        $unsafe = true;
                        $messages[] = (string) ($check['message'] ?? 'Invalid or unsafe Varnish HTTP endpoint.');
                    }
                }
            }

            $messages = array_values(array_unique(array_filter($messages)));

            return array(
                'unsafe'       => $unsafe,
                'messages'     => $messages,
                'allowedPorts' => self::get_varnish_http_allowed_ports(),
            );
        }

        private static function validate_varnish_settings(array $settings)
        {
            if (empty($settings['varnishCliEnabled'])) {
                return true;
            }

            $mode = self::sanitize_varnish_mode($settings['varnishCliMode'] ?? 'http');
            if ('http' !== $mode) {
                return true;
            }

            $diagnostics = self::get_varnish_endpoint_diagnostics($settings['varnishCliServers'] ?? '', $mode);
            if (!empty($diagnostics['unsafe'])) {
                $message = !empty($diagnostics['messages'][0]) ? (string) $diagnostics['messages'][0] : self::maybe_translate('Unsafe Varnish HTTP endpoint blocked.');
                return new WP_Error('ucwp_unsafe_varnish_http_endpoint', $message);
            }

            return true;
        }

        private static function get_legacy_cache_conflict_status()
        {
            $option_names = array(
                'purge_varnish_action',
                'purge_varnish_expire',
                'varnish_bantype',
                'varnish_control_key',
                'varnish_control_terminal',
                'varnish_socket_timeout',
                'varnish_version',
                'vhp_varnish_debug',
                'w3x_varnish_cli_secret',
                'w3x_varnish_cli_timeout_ms',
                'w3x_varnish_http_servers',
                'w3tc_state',
            );

            $found_options = array();
            foreach ($option_names as $option_name) {
                if (false !== get_option($option_name, false)) {
                    $found_options[] = $option_name;
                }
            }

            $found_plugins = array();
            if (defined('WP_PLUGIN_DIR')) {
                foreach (array('w3-total-cache', 'w3tc-varnish-cli-helper') as $plugin_dir) {
                    if (file_exists(trailingslashit(WP_PLUGIN_DIR) . $plugin_dir)) {
                        $found_plugins[] = $plugin_dir;
                    }
                }
            }

            return array(
                'detected' => !empty($found_options) || !empty($found_plugins),
                'options'  => $found_options,
                'plugins'  => $found_plugins,
                'message'  => (!empty($found_options) || !empty($found_plugins)) ? self::maybe_translate('Old W3 Total Cache / Varnish helper leftovers detected. Remove them before enabling Varnish or Object Cache to avoid purge loops or Redis auth conflicts.') : '',
            );
        }

        private static function sanitize_varnish_servers_string($value, $mode = 'http')
        {
            $mode = self::sanitize_varnish_mode($mode);

            if (is_array($value)) {
                $value = implode("
", array_map('strval', $value));
            }

            $value = str_replace(array("
", "
", ",", ";", "	"), array("
", "
", "
", "
", "
"), (string) $value);
            $servers = preg_split('/\s+/', $value);
            if (!is_array($servers)) {
                return '';
            }

            $normalized = array();
            foreach ($servers as $server) {
                $server = trim((string) $server);
                if ('' === $server) {
                    continue;
                }

                $server = preg_replace('#^[a-z]+://#i', '', $server);
                $server = preg_replace('#/.*$#', '', $server);
                $server = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $server);
                if ('' === $server) {
                    continue;
                }

                $normalized[] = $server;
            }

            if (empty($normalized)) {
                $normalized[] = ('admin' === $mode) ? self::get_default_varnish_admin_endpoint() : self::get_default_varnish_http_endpoint();
            }

            return implode("
", array_values(array_unique($normalized)));
        }


        private static function sanitize_media_output_mode($value)
        {
            $value = strtolower(trim((string) $value));
            return in_array($value, array('auto', 'avif', 'webp'), true) ? $value : 'auto';
        }

        private static function normalize_boolean_setting_value($value, $default = false)
        {
            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return 0 !== (int) $value;
            }

            if (is_string($value)) {
                $normalized = strtolower(trim($value));
                if ('' === $normalized) {
                    return (bool) $default;
                }

                if (in_array($normalized, array('1', 'true', 'yes', 'on', 'enabled'), true)) {
                    return true;
                }

                if (in_array($normalized, array('0', 'false', 'no', 'off', 'disabled'), true)) {
                    return false;
                }
            }

            if (null === $value) {
                return (bool) $default;
            }

            return !empty($value);
        }

        public static function sanitize_dashboard_settings(array $settings)
        {
            $raw_settings = $settings;
            $defaults = self::get_dashboard_defaults();
            $settings = wp_parse_args($settings, $defaults);

            if (array_key_exists('cronWarmPagesPerMinute', $raw_settings)) {
                $settings['cronWarmPagesPerMinute'] = $raw_settings['cronWarmPagesPerMinute'];
            }

            if (array_key_exists('scheduledWarmLimit', $raw_settings)) {
                $settings['scheduledWarmLimit'] = $raw_settings['scheduledWarmLimit'];
            }


            $boolean_keys = array(
                'pageCacheEnabled',
                'objectCacheEnabled',
                'brotliEnabled',
                'gzipEnabled',
                'cacheStatsEnabled',
                'mediaOptimizationEnabled',
                'mediaGenerateOnUploadEnabled',
                'mediaGenerateOnDemandEnabled',
                'deferJsEnabled',
                'deferAllJsEnabled',
                'delaySafeThirdPartyJsEnabled',
                'lazyMailerliteNonceEnabled',
                'delayFunctionalThirdPartyJsEnabled',
                'asyncExternalScriptsEnabled',
                'homepageCssBundleEnabled',
                'homepageCssBundleInlineEnabled',
                'leftoverCssBundleEnabled',
                'delayIconFontsEnabled',
                'delayIconFontsAutoDetectEnabled',
                'pageCssBundleOnEntryEnabled',
                'frontendSafeModeEnabled',
                'sliderSafeModeEnabled',
                'clsDimensionsEnabled',
                'asyncCssEnabled',
                'aggressiveAsyncCssEnabled',
                'delayNonCriticalJsEnabled',
                'lcpImagePriorityEnabled',
                'lcpBoundaryDeferEnabled',
                'mainThreadReliefEnabled',
                'criticalRequestChainReliefEnabled',
                'assetChainCleanupEnabled',
                'assetCleanupWooProductAssetsEnabled',
                'assetCleanupProductFilterAssetsEnabled',
                'assetCleanupWooBlocksCssEnabled',
                'googleFontsSwapEnabled',
                'googleFontsLocalOptimizationEnabled',
                'selfHostedFontCssOptimizationEnabled',
                'selfHostedFontRuntimeRewriteEnabled',
                'speculationRulesEnabled',
                'browserCacheRulesEnabled',
                'varnishCliEnabled',
                'varnishCliDebug',
                'preRenderOnSave',
                'woocommerceSafeModeEnabled',
                'cacheCleanupEnabled',
                'apcuFlushOnScheduledCleanup',
                'cronWarmEnabled',
                'cronWarmStartAfterCleanup',
                'cronWarmStartAfterManualPurge',
                'staleWhileRevalidateEnabled',
                'cacheQueryStringsEnabled',
                'redisUseTls',
                'redisPersistent',
            );

            foreach ($boolean_keys as $key) {
                $default = array_key_exists($key, $defaults) ? $defaults[$key] : false;
                $settings[$key] = self::normalize_boolean_setting_value($settings[$key], $default);
            }

            // Defer and Delay controls are intentionally independent. Delay third-party and
            // delay non-critical/local scripts must not silently enable each other or enable
            // native defer, because that makes frontend regressions hard to isolate.

            $settings['cacheCleanupIntervalHours'] = max(1, min(720, absint($settings['cacheCleanupIntervalHours'])));
            $settings['cronWarmPagesPerMinute']    = max(0, min(600, absint($settings['cronWarmPagesPerMinute'])));
            $settings['scheduledWarmLimit']        = max(0, min(5000, absint($settings['scheduledWarmLimit'])));
            $settings['varnishCliTimeoutSeconds']  = max(1, min(30, absint($settings['varnishCliTimeoutSeconds'])));
            $settings['cacheFreshTtlMinutes']      = self::sanitize_bounded_integer_setting($settings['cacheFreshTtlMinutes'], $defaults['cacheFreshTtlMinutes'], 1, 1440);
            $settings['cacheMaxStaleMinutes']      = self::sanitize_bounded_integer_setting($settings['cacheMaxStaleMinutes'], $defaults['cacheMaxStaleMinutes'], (int) $settings['cacheFreshTtlMinutes'], 10080);
            $settings['cacheExceptionPaths']       = self::sanitize_excluded_paths_setting($settings['cacheExceptionPaths']);
            $settings['cacheExceptionQueryArgs']   = self::sanitize_setting_key_list($settings['cacheExceptionQueryArgs']);
            $settings['cacheQueryStringAllowlist'] = self::sanitize_setting_key_list($settings['cacheQueryStringAllowlist']);
            $settings['deferJsForceList']         = self::normalize_textarea_setting($settings['deferJsForceList']);
            $settings['deferJsExcludeList']       = self::merge_textarea_settings($settings['deferJsExcludeList'], $settings['delayNonCriticalJsExcludeList']);
            $old_default_js_exclusions = self::normalize_textarea_setting(self::get_default_js_delay_defer_exclusion_patterns());
            if ($old_default_js_exclusions === $settings['deferJsExcludeList']) {
                $settings['deferJsExcludeList'] = '';
            }
            // Manual JS Delay / Defer Exclusions must be durable. Do not strip
            // lines while Defer all JS is enabled: this field is the user's final
            // escape hatch for aggressive defer regressions such as inline globals.
            $settings['deferJsExcludeList'] = self::normalize_textarea_setting($settings['deferJsExcludeList']);
            $settings['delayNonCriticalJsExcludeList'] = '';
            $settings['delaySafeThirdPartyJsPatterns'] = self::normalize_textarea_setting($settings['delaySafeThirdPartyJsPatterns']);
            $settings['delayFunctionalThirdPartyJsPatterns'] = self::normalize_textarea_setting($settings['delayFunctionalThirdPartyJsPatterns']);
            $settings['delayThirdPartyJsExcludeList'] = self::normalize_textarea_setting($settings['delayThirdPartyJsExcludeList']);
            $settings['homepageCssBundleExcludeList'] = self::normalize_textarea_setting($settings['homepageCssBundleExcludeList']);
            $settings['delayIconFontsList'] = self::normalize_textarea_setting($settings['delayIconFontsList']);
            $settings['delayIconFontsExcludeList'] = self::normalize_textarea_setting($settings['delayIconFontsExcludeList']);
            $settings['homepageCssBundleMode'] = self::sanitize_homepage_css_bundle_mode($settings['homepageCssBundleMode']);
            $settings['cssBundleScope'] = self::sanitize_css_bundle_scope($settings['cssBundleScope'] ?? 'homepage');
            $settings['asyncCssExcludeList']       = self::normalize_textarea_setting($settings['asyncCssExcludeList']);
            $settings['aggressiveAsyncCssExcludeList'] = self::normalize_textarea_setting($settings['aggressiveAsyncCssExcludeList']);
            $settings['delayNonCriticalJsExcludeList'] = self::normalize_textarea_setting($settings['delayNonCriticalJsExcludeList']);
            $settings['assetCleanupExcludeList'] = self::normalize_textarea_setting($settings['assetCleanupExcludeList']);
            $settings['googleFontsAdditionalScanUrls'] = self::normalize_textarea_setting($settings['googleFontsAdditionalScanUrls']);
            $settings['lcpImagePriorityOverride'] = self::normalize_textarea_setting($settings['lcpImagePriorityOverride']);
            $settings['manualLcpHeroSelector'] = self::normalize_textarea_setting($settings['manualLcpHeroSelector']);
            $settings['criticalResourcePreloadList'] = self::normalize_textarea_setting($settings['criticalResourcePreloadList']);
            $settings['criticalFetchPreloadList'] = self::normalize_textarea_setting($settings['criticalFetchPreloadList']);
            $settings['criticalRequestChainDelayList'] = self::normalize_textarea_setting($settings['criticalRequestChainDelayList']);
            $settings['mediaOutputMode']           = self::sanitize_media_output_mode($settings['mediaOutputMode']);
            $settings['objectCacheBackend']        = self::sanitize_object_cache_backend($settings['objectCacheBackend']);
            $settings['redisHost']                 = self::sanitize_redis_host($settings['redisHost']);
            $settings['redisPort']                 = self::sanitize_bounded_integer_setting($settings['redisPort'], $defaults['redisPort'], 1, 65535);
            $settings['redisPassword']             = trim((string) $settings['redisPassword']);
            $settings['redisDatabase']             = self::sanitize_redis_database($settings['redisDatabase']);
            $settings['redisPrefix']               = self::sanitize_redis_prefix($settings['redisPrefix']);
            $settings['redisUseTls']               = !empty($settings['redisUseTls']);
            $settings['redisPersistent']           = !empty($settings['redisPersistent']);
            $settings['redisConnectTimeoutMs']     = self::sanitize_bounded_integer_setting($settings['redisConnectTimeoutMs'], $defaults['redisConnectTimeoutMs'], 50, 5000);
            $settings['redisReadTimeoutMs']        = self::sanitize_bounded_integer_setting($settings['redisReadTimeoutMs'], $defaults['redisReadTimeoutMs'], 50, 5000);
            $settings['varnishCliMode']            = self::sanitize_varnish_mode($settings['varnishCliMode']);
            $settings['varnishCliServers']         = self::sanitize_varnish_servers_string($settings['varnishCliServers'], $settings['varnishCliMode']);
            $settings['varnishCliKey']             = trim((string) $settings['varnishCliKey']);
            $settings['varnishCliMethod']          = ('PURGE' === strtoupper(trim((string) $settings['varnishCliMethod']))) ? 'PURGE' : 'BAN';

            // LCP Boundary Defer stays unavailable in broad Frontend Safe Mode, but it remains
            // user-controllable with Slider/Hero Safe Mode. Slider/Hero mode now uses the same
            // conservative boundary deferral path while keeping slider/runtime assets protected.
            if (!empty($settings['frontendSafeModeEnabled'])) {
                $settings['lcpBoundaryDeferEnabled'] = false;
            }

            $compression_support = self::get_compression_support_status();
            if (empty($compression_support['brotli'])) {
                $settings['brotliEnabled'] = false;
            }
            if (empty($compression_support['gzip'])) {
                $settings['gzipEnabled'] = false;
            }

            $frontend_compression = self::get_frontend_compression_probe_status(false);
            if (!empty($frontend_compression['brotli']) || !empty($frontend_compression['brokenBrotli'])) {
                $settings['brotliEnabled'] = false;
            }
            if (!empty($frontend_compression['gzip']) || !empty($frontend_compression['brokenGzip'])) {
                $settings['gzipEnabled'] = false;
            }

            $live_support_checks = self::should_use_live_settings_support_checks();
            ucwp_request_profile_checkpoint(
                $live_support_checks ? 'sanitize_settings_before_object_cache_support_live' : 'sanitize_settings_before_object_cache_support_cached'
            );
            $object_cache_support = self::get_object_cache_support_status($live_support_checks);
            ucwp_request_profile_checkpoint(
                $live_support_checks ? 'sanitize_settings_after_object_cache_support_live' : 'sanitize_settings_after_object_cache_support_cached',
                array(
                    'available' => !empty($object_cache_support['available']) ? 'true' : 'false',
                    'source' => isset($object_cache_support['source']) ? (string) $object_cache_support['source'] : '',
                )
            );
            if (empty($object_cache_support['available'])) {
                $settings['objectCacheEnabled'] = false;
            }

            $media_support = self::get_media_support_status();
            if (empty($media_support['supported'])) {
                $settings['mediaOptimizationEnabled'] = false;
                $settings['mediaGenerateOnUploadEnabled'] = false;
                $settings['mediaGenerateOnDemandEnabled'] = false;
            }

            ucwp_request_profile_checkpoint('sanitize_settings_before_varnish_support');
            $varnish_support = self::get_varnish_support_status();
            ucwp_request_profile_checkpoint('sanitize_settings_after_varnish_support', array('available' => !empty($varnish_support['available']) ? 'true' : 'false'));
            if (empty($varnish_support['available'])) {
                $settings['varnishCliEnabled'] = false;
            }

            $settings['cronWarmStartAfterCleanup'] = !empty($settings['cronWarmStartAfterCleanup']);
            $settings['cronWarmStartAfterManualPurge'] = !empty($settings['cronWarmStartAfterManualPurge']);

            // Keep the public settings payload canonical. Stored options may still contain
            // old keys from previous local builds, but they must not leak back to CLI, REST,
            // exports, or runtime settings after sanitization.
            $settings = array_intersect_key($settings, $defaults);

            return $settings;
        }

        public static function reset_settings_cache()
        {
            self::$dashboard_settings_cache = null;
            self::$settings_cache = null;
            delete_transient('ucwp_frontend_compression_probe_v1');
            delete_transient('ucwp_object_cache_support_status_v1');
            ucwp_reset_loopback_ssl_status();

            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'reset_settings_cache')) {
                Ultra_Cache_Object_Cache_Manager::reset_settings_cache();
            }
        }

        public static function get_dashboard_settings()
        {
            ucwp_request_profile_checkpoint('dashboard_settings_start');
            if (null !== self::$dashboard_settings_cache) {
                ucwp_request_profile_checkpoint('dashboard_settings_cache_hit');
                return self::$dashboard_settings_cache;
            }

            ucwp_request_profile_checkpoint('dashboard_settings_before_get_option');
            $saved = get_option(UCWP_SETTINGS_KEY, array());
            ucwp_request_profile_checkpoint('dashboard_settings_after_get_option', array(
                'raw_is_array' => is_array($saved) ? 'true' : 'false',
                'raw_count' => is_array($saved) ? count($saved) : 0,
            ));
            $raw_saved = is_array($saved) ? $saved : array();
            ucwp_request_profile_checkpoint('dashboard_settings_before_sanitize');
            $sanitized = self::sanitize_dashboard_settings($raw_saved);
            ucwp_request_profile_checkpoint('dashboard_settings_after_sanitize', array(
                'sanitized_count' => is_array($sanitized) ? count($sanitized) : 0,
            ));

            // This private build does not need a backward-compatibility layer. Keep the
            // stored option canonical so invalid combinations from previous local builds
            // do not remain visible in direct option reads, CLI checks, or diagnostics.
            ucwp_request_profile_checkpoint('dashboard_settings_before_canonical_compare');
            if (!is_array($saved) || wp_json_encode($raw_saved) !== wp_json_encode($sanitized)) {
                ucwp_request_profile_checkpoint('dashboard_settings_before_update_option');
                update_option(UCWP_SETTINGS_KEY, $sanitized, false);
                ucwp_request_profile_checkpoint('dashboard_settings_after_update_option');
            }
            ucwp_request_profile_checkpoint('dashboard_settings_after_canonical_compare');

            self::$dashboard_settings_cache = $sanitized;

            ucwp_request_profile_checkpoint('dashboard_settings_end');
            return self::$dashboard_settings_cache;
        }

        private static function get_secret_setting_keys()
        {
            return array(
                'redisPassword',
                'varnishCliKey',
            );
        }

        private static function get_secret_configuration_flag_map()
        {
            return array(
                'redisPassword' => 'redisPasswordConfigured',
                'varnishCliKey' => 'varnishCliKeyConfigured',
            );
        }

        private static function merge_protected_dashboard_settings(array $incoming, array $existing)
        {
            $merged = $incoming;
            $flag_map = self::get_secret_configuration_flag_map();

            foreach (self::get_secret_setting_keys() as $key) {
                $clear_flag = 'clear' . ucfirst($key);
                $should_clear = !empty($incoming[$clear_flag]);

                if ($should_clear) {
                    $merged[$key] = '';
                    continue;
                }

                if (!array_key_exists($key, $incoming)) {
                    if (array_key_exists($key, $existing)) {
                        $merged[$key] = $existing[$key];
                    }
                    continue;
                }

                if ('' === trim((string) $incoming[$key]) && array_key_exists($key, $existing) && '' !== trim((string) $existing[$key])) {
                    $merged[$key] = $existing[$key];
                }
            }

            return $merged;
        }

        public static function get_dashboard_settings_for_client()
        {
            $settings = self::get_dashboard_settings();
            $flag_map = self::get_secret_configuration_flag_map();

            foreach (self::get_secret_setting_keys() as $key) {
                $flag = isset($flag_map[$key]) ? $flag_map[$key] : '';
                if ('' !== $flag) {
                    $settings[$flag] = ('' !== trim((string) ($settings[$key] ?? '')));
                }
                $settings[$key] = '';
            }

            return $settings;
        }

        public static function get_dashboard_defaults_for_client()
        {
            $settings = self::sanitize_dashboard_settings(self::get_dashboard_defaults());
            $flag_map = self::get_secret_configuration_flag_map();

            foreach (self::get_secret_setting_keys() as $key) {
                $flag = isset($flag_map[$key]) ? $flag_map[$key] : '';
                if ('' !== $flag) {
                    $settings[$flag] = false;
                }
                $settings[$key] = '';
            }

            return $settings;
        }

        public static function get_settings()
        {
            ucwp_request_profile_settings_checkpoint('wp_get_settings_start');
            if (null !== self::$settings_cache) {
                ucwp_request_profile_settings_checkpoint('wp_get_settings_cache_hit');
                return self::$settings_cache;
            }

            ucwp_request_profile_settings_checkpoint('wp_get_settings_before_dashboard_settings');
            $ui = self::get_dashboard_settings();
            ucwp_request_profile_settings_checkpoint('wp_get_settings_after_dashboard_settings', array(
                'dashboard_settings_count' => is_array($ui) ? count($ui) : 0,
            ));

            ucwp_request_profile_settings_checkpoint('wp_get_settings_before_runtime_textareas');
            $excluded_paths = self::parse_textarea_setting($ui['cacheExceptionPaths']);
            if (empty($excluded_paths)) {
                $excluded_paths = self::get_default_excluded_paths();
            }

            $excluded_query_args = self::parse_textarea_setting($ui['cacheExceptionQueryArgs']);
            if (empty($excluded_query_args)) {
                $excluded_query_args = self::get_default_excluded_query_args();
            }

            $query_allowlist = self::parse_textarea_setting(self::sanitize_setting_key_list($ui['cacheQueryStringAllowlist']));
            ucwp_request_profile_settings_checkpoint('wp_get_settings_after_runtime_textareas', array(
                'excluded_paths_count' => count($excluded_paths),
                'excluded_query_args_count' => count($excluded_query_args),
                'query_allowlist_count' => count($query_allowlist),
            ));
            $defer_js_enabled = !empty($ui['deferJsEnabled']);
            $defer_all_js_enabled = $defer_js_enabled && !empty($ui['deferAllJsEnabled']);
            $delay_safe_third_party_js_enabled = !empty($ui['delaySafeThirdPartyJsEnabled']);
            $delay_functional_third_party_js_enabled = !empty($ui['delayFunctionalThirdPartyJsEnabled']);
            $delay_non_critical_js_enabled = !empty($ui['delayNonCriticalJsEnabled']);
            $defer_stage_aggressive = $delay_non_critical_js_enabled;
            $defer_stage_balanced = $delay_safe_third_party_js_enabled || $delay_functional_third_party_js_enabled || $defer_stage_aggressive;
            $defer_stage_safe = $defer_js_enabled || $defer_stage_balanced;

            self::$settings_cache = array(
                'enabled'                      => !empty($ui['pageCacheEnabled']),
                'object_cache_enabled'         => !empty($ui['objectCacheEnabled']),
                'object_cache_backend'         => self::sanitize_object_cache_backend($ui['objectCacheBackend']),
                'object_cache_fallback_backend'=> 'apcu',
                'redis_host'                   => self::sanitize_redis_host($ui['redisHost']),
                'redis_port'                   => self::sanitize_bounded_integer_setting($ui['redisPort'], 6379, 1, 65535),
                'redis_password'               => trim((string) $ui['redisPassword']),
                'redis_database'               => self::sanitize_redis_database($ui['redisDatabase']),
                'redis_prefix'                 => self::sanitize_redis_prefix($ui['redisPrefix']),
                'redis_use_tls'                => !empty($ui['redisUseTls']),
                'redis_persistent'             => !empty($ui['redisPersistent']),
                'redis_connect_timeout_ms'     => self::sanitize_bounded_integer_setting($ui['redisConnectTimeoutMs'], 200, 50, 5000),
                'redis_read_timeout_ms'        => self::sanitize_bounded_integer_setting($ui['redisReadTimeoutMs'], 200, 50, 5000),
                'cache_logged_in_users'        => false,
                'cache_query_strings'          => !empty($ui['cacheQueryStringsEnabled']),
                'cache_query_allowlist'        => $query_allowlist,
                'gzip_enabled'                 => !empty($ui['gzipEnabled']),
                'brotli_enabled'               => !empty($ui['brotliEnabled']),
                'cache_stats_enabled'          => !empty($ui['cacheStatsEnabled']),
                'preload_on_save'              => !empty($ui['preRenderOnSave']),
                'defer_js'                     => $defer_js_enabled,
                'defer_all_js'                 => $defer_all_js_enabled,
                'defer_stage_safe'             => $defer_stage_safe,
                'defer_stage_balanced'         => $defer_stage_balanced,
                'defer_stage_aggressive'       => $defer_stage_aggressive,
                'defer_js_force_list'          => self::parse_textarea_setting(self::normalize_textarea_setting($ui['deferJsForceList'])),
                'defer_js_exclude_list'        => self::parse_textarea_setting(self::normalize_textarea_setting($ui['deferJsExcludeList'])),
                'delay_safe_third_party_js'         => $delay_safe_third_party_js_enabled,
                'lazy_mailerlite_nonce'        => !empty($ui['lazyMailerliteNonceEnabled']),
                'delay_safe_third_party_js_patterns' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['delaySafeThirdPartyJsPatterns'])),
                'delay_functional_third_party_js' => $delay_functional_third_party_js_enabled,
                'delay_functional_third_party_js_patterns' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['delayFunctionalThirdPartyJsPatterns'])),
                'delay_third_party_js_exclude_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['delayThirdPartyJsExcludeList'])),
                'async_external_scripts'       => !empty($ui['asyncExternalScriptsEnabled']),
                'homepage_css_bundle'         => !empty($ui['homepageCssBundleEnabled']),
                'homepage_css_bundle_inline'  => !empty($ui['homepageCssBundleInlineEnabled']),
                'leftover_css_bundle'       => !empty($ui['leftoverCssBundleEnabled']),
                'homepage_css_bundle_exclude_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['homepageCssBundleExcludeList'])),
                'delay_icon_fonts'            => !empty($ui['delayIconFontsEnabled']),
                'delay_icon_fonts_auto_detect' => !empty($ui['delayIconFontsAutoDetectEnabled']),
                'delay_icon_fonts_list'       => self::parse_textarea_setting(self::normalize_textarea_setting($ui['delayIconFontsList'])),
                'delay_icon_fonts_exclude_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['delayIconFontsExcludeList'])),
                'homepage_css_bundle_mode'    => self::sanitize_homepage_css_bundle_mode($ui['homepageCssBundleMode']),
                'css_bundle_scope'            => self::sanitize_css_bundle_scope($ui['cssBundleScope'] ?? 'homepage'),
                'page_css_bundle_on_entry'    => !empty($ui['pageCssBundleOnEntryEnabled']),
                'frontend_safe_mode'          => !empty($ui['frontendSafeModeEnabled']),
                'slider_safe_mode'            => !empty($ui['sliderSafeModeEnabled']),
                'cls_dimensions'               => !empty($ui['clsDimensionsEnabled']),
                'async_css'                    => !empty($ui['asyncCssEnabled']),
                'async_css_exclude_list'       => self::parse_textarea_setting(self::normalize_textarea_setting($ui['asyncCssExcludeList'])),
                'aggressive_async_css'         => !empty($ui['aggressiveAsyncCssEnabled']),
                'aggressive_async_css_exclude_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['aggressiveAsyncCssExcludeList'])),
                'delay_non_critical_js'        => $delay_non_critical_js_enabled,
                'delay_non_critical_js_aggressive' => $defer_stage_aggressive,
                'delay_non_critical_js_exclude_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['deferJsExcludeList'])),
                'lcp_image_priority'           => !empty($ui['lcpImagePriorityEnabled']),
                'lcp_boundary_defer'           => !empty($ui['lcpBoundaryDeferEnabled']),
                'lcp_image_priority_override_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['lcpImagePriorityOverride'])),
                'manual_lcp_hero_selector_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['manualLcpHeroSelector'] ?? '')),
                'main_thread_relief'          => !empty($ui['mainThreadReliefEnabled']),
                'critical_request_chain_relief' => !empty($ui['criticalRequestChainReliefEnabled']),
                'critical_resource_preload_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['criticalResourcePreloadList'])),
                'critical_fetch_preload_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['criticalFetchPreloadList'])),
                'critical_request_chain_delay_list' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['criticalRequestChainDelayList'])),
                'asset_chain_cleanup'          => !empty($ui['assetChainCleanupEnabled']),
                'asset_cleanup_woo_product_assets' => !empty($ui['assetCleanupWooProductAssetsEnabled']),
                'asset_cleanup_product_filter_assets' => !empty($ui['assetCleanupProductFilterAssetsEnabled']),
                'asset_cleanup_woo_blocks_css' => !empty($ui['assetCleanupWooBlocksCssEnabled']),
                'asset_cleanup_exclude_list'   => self::parse_textarea_setting(self::normalize_textarea_setting($ui['assetCleanupExcludeList'])),
                'google_fonts_swap'            => !empty($ui['googleFontsSwapEnabled']),
                'google_fonts_local_optimization' => !empty($ui['googleFontsLocalOptimizationEnabled']),
                'google_fonts_additional_scan_urls' => self::parse_textarea_setting(self::normalize_textarea_setting($ui['googleFontsAdditionalScanUrls'] ?? '')),
                'self_hosted_font_css_optimization' => !empty($ui['selfHostedFontCssOptimizationEnabled']),
                'self_hosted_font_runtime_rewrite' => !empty($ui['selfHostedFontRuntimeRewriteEnabled']),
                'speculation_rules_enabled'    => !empty($ui['speculationRulesEnabled']),
                'browser_cache_rules'          => !empty($ui['browserCacheRulesEnabled']),
                'varnish_cli_enabled'          => !empty($ui['varnishCliEnabled']),
                'varnish_cli_mode'             => self::sanitize_varnish_mode($ui['varnishCliMode']),
                'varnish_cli_servers'          => self::sanitize_varnish_servers_string($ui['varnishCliServers'], self::sanitize_varnish_mode($ui['varnishCliMode'])),
                'varnish_cli_key'              => trim((string) $ui['varnishCliKey']),
                'varnish_cli_timeout_seconds'  => max(1, min(30, absint($ui['varnishCliTimeoutSeconds']))),
                'varnish_cli_method'           => ('PURGE' === strtoupper(trim((string) $ui['varnishCliMethod']))) ? 'PURGE' : 'BAN',
                'varnish_cli_debug'            => !empty($ui['varnishCliDebug']),
                'media_optimization_enabled'   => !empty($ui['mediaOptimizationEnabled']),
                'media_generate_on_upload'     => !empty($ui['mediaGenerateOnUploadEnabled']),
                'media_generate_on_demand'     => !empty($ui['mediaGenerateOnDemandEnabled']),
                'media_output_mode'            => self::sanitize_media_output_mode($ui['mediaOutputMode']),
                'woo_safe_mode'                => !empty($ui['woocommerceSafeModeEnabled']),
                'cache_cleanup_enabled'        => !empty($ui['cacheCleanupEnabled']),
                'apcu_flush_on_scheduled_cleanup' => !empty($ui['apcuFlushOnScheduledCleanup']),
                'cache_cleanup_interval_hours' => max(1, absint($ui['cacheCleanupIntervalHours'])),
                'cron_warm_enabled'            => !empty($ui['cronWarmEnabled']),
                'cron_warm_start_after_cleanup'=> !empty($ui['cronWarmStartAfterCleanup']),
                'cron_warm_start_after_manual_purge'=> !empty($ui['cronWarmStartAfterManualPurge']),
                'cron_warm_pages_per_minute'   => max(0, absint($ui['cronWarmPagesPerMinute'])),
                'scheduled_warm_limit'         => max(0, absint($ui['scheduledWarmLimit'])),
                'stale_while_revalidate_enabled' => !empty($ui['staleWhileRevalidateEnabled']),
                'cache_fresh_ttl_minutes'      => max(1, absint($ui['cacheFreshTtlMinutes'])),
                'cache_max_stale_minutes'      => max(absint($ui['cacheFreshTtlMinutes']), absint($ui['cacheMaxStaleMinutes'])),
                'excluded_paths'               => $excluded_paths,
                'excluded_query_args'          => $excluded_query_args,
            );

            ucwp_request_profile_settings_checkpoint('wp_get_settings_after_runtime_map', array(
                'settings_count' => count(self::$settings_cache),
            ));
            return self::$settings_cache;
        }


        private static function get_runtime_config_path()
        {
            return trailingslashit(UCWP_CACHE_DIR) . 'runtime-config.json';
        }

        private static function get_runtime_secret_site_token()
        {
            $site_root = wp_normalize_path(untrailingslashit(ABSPATH));
            $token = wp_basename($site_root);
            $token = is_string($token) ? strtolower($token) : '';
            $token = preg_replace('/[^a-z0-9._-]+/', '-', $token);
            $token = trim((string) $token, '.-_');

            if ('' === $token) {
                $token = 'site';
            }

            return $token;
        }

        private static function get_runtime_secret_path()
        {
            $base = dirname(untrailingslashit(ABSPATH));
            if (!is_string($base) || '' === trim($base) || '.' === $base || '/' === $base) {
                $base = dirname(untrailingslashit(WP_CONTENT_DIR));
            }

            return rtrim($base, '/\\') . '/.' . self::get_runtime_secret_site_token() . '-ultracache-runtime-secrets.php';
        }

        private static function ensure_runtime_config_protection_files()
        {
            $runtime_dir = dirname(self::get_runtime_config_path());
            if (!file_exists($runtime_dir) && !ucwp_safe_mkdir($runtime_dir, 0755, true, 'ensure_runtime_config_protection_files mkdir') && !file_exists($runtime_dir)) {
                return;
            }

            $htaccess = trailingslashit($runtime_dir) . '.htaccess';
            $htaccess_rules = "<Files \"runtime-config.json\">\nRequire all denied\n</Files>\n<IfModule !mod_authz_core.c>\n<Files \"runtime-config.json\">\nDeny from all\n</Files>\n</IfModule>\n";
            if (!file_exists($htaccess)) {
                ucwp_safe_file_put_contents($htaccess, $htaccess_rules, 0, 'runtime_config htaccess');
            } else {
                $existing_htaccess = ucwp_safe_file_get_contents($htaccess, 'runtime_config htaccess read');
                if (is_string($existing_htaccess) && false === strpos($existing_htaccess, 'runtime-config.json')) {
                    ucwp_safe_file_put_contents($htaccess, rtrim($existing_htaccess) . "\n\n" . $htaccess_rules, 0, 'runtime_config htaccess append');
                }
            }

            $web_config = trailingslashit($runtime_dir) . 'web.config';
            $web_config_rules = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <location path=\"runtime-config.json\">\n    <system.webServer>\n      <security>\n        <authorization>\n          <clear />\n          <add accessType=\"Deny\" users=\"*\" />\n        </authorization>\n      </security>\n    </system.webServer>\n  </location>\n</configuration>\n";
            if (!file_exists($web_config)) {
                ucwp_safe_file_put_contents($web_config, $web_config_rules, 0, 'runtime_config web_config');
            }
        }

        private static function render_runtime_secret_php(array $runtime)
        {
            $secret = isset($runtime['revalidate_secret']) ? (string) $runtime['revalidate_secret'] : '';
            $redis_password = isset($runtime['redis_password']) ? (string) $runtime['redis_password'] : '';
            return "<?php\n/** UltraCache managed runtime secrets. */\nif (!defined('ABSPATH')) {\n    exit;\n}\nreturn array(\n    'revalidate_secret' => " . ucwp_php_string_literal($secret) . ",\n    'redis_password' => " . ucwp_php_string_literal($redis_password) . ",\n);\n";
        }

        private static function load_runtime_secret_file($path = null)
        {
            $candidates = array();
            if (is_string($path) && '' !== trim($path)) {
                $candidates[] = $path;
            } else {
                $candidates[] = self::get_runtime_secret_path();
            }

            foreach ($candidates as $candidate) {
                if (!is_string($candidate) || '' === trim($candidate) || !file_exists($candidate) || !is_readable($candidate)) {
                    continue;
                }

                $loaded = require $candidate;
                if (!is_array($loaded)) {
                    continue;
                }

                return array(
                    'revalidate_secret' => isset($loaded['revalidate_secret']) ? (string) $loaded['revalidate_secret'] : '',
                    'redis_password' => isset($loaded['redis_password']) ? (string) $loaded['redis_password'] : '',
                );
            }

            return array();
        }

        private static function build_runtime_config()
        {
            $settings = self::get_settings();

            return self::normalize_runtime_config(array(
                'excluded_paths'                  => $settings['excluded_paths'],
                'excluded_query_args'             => $settings['excluded_query_args'],
                'cache_query_strings'             => !empty($settings['cache_query_strings']),
                'cache_query_allowlist'           => !empty($settings['cache_query_allowlist']) ? self::parse_textarea_setting(self::sanitize_setting_key_list((array) $settings['cache_query_allowlist'])) : array(),
                'woo_safe_mode'                   => !empty($settings['woo_safe_mode']),
                'cache_stats_enabled'             => !empty($settings['cache_stats_enabled']),
                'object_cache_enabled'            => !empty($settings['object_cache_enabled']),
                'object_cache_backend'            => self::sanitize_object_cache_backend($settings['object_cache_backend'] ?? 'redis'),
                'redis_host'                      => (string) ($settings['redis_host'] ?? '127.0.0.1'),
                'redis_port'                      => max(1, absint($settings['redis_port'] ?? 6379)),
                'redis_password'                  => (string) ($settings['redis_password'] ?? ''),
                'redis_database'                  => max(0, absint($settings['redis_database'] ?? 0)),
                'redis_prefix'                    => preg_replace('/[^A-Za-z0-9:_\\-]/', '', (string) ($settings['redis_prefix'] ?? '')),
                'redis_use_tls'                   => !empty($settings['redis_use_tls']),
                'redis_persistent'                => !empty($settings['redis_persistent']),
                'redis_connect_timeout_ms'        => max(50, absint($settings['redis_connect_timeout_ms'] ?? 200)),
                'redis_read_timeout_ms'           => max(50, absint($settings['redis_read_timeout_ms'] ?? 200)),
                'stale_while_revalidate_enabled'  => !empty($settings['stale_while_revalidate_enabled']),
                'cache_fresh_ttl_minutes'         => max(1, absint($settings['cache_fresh_ttl_minutes'])),
                'cache_max_stale_minutes'         => max(absint($settings['cache_fresh_ttl_minutes']), absint($settings['cache_max_stale_minutes'])),
                'revalidate_secret'               => wp_hash('ucwp-revalidate-v1'),
                'trusted_hosts'                   => ucwp_get_trusted_hosts(),
            ));
        }

        private static function load_runtime_config_file($path)
        {
            if (!file_exists($path) || !is_readable($path)) {
                return new WP_Error('ucwp_runtime_config_missing', 'runtime-config.json is missing or not readable.');
            }

            $raw = ucwp_safe_file_get_contents($path, 'load_runtime_config_file');
            if (false === $raw || '' === $raw) {
                return new WP_Error('ucwp_runtime_config_load_failed', 'Failed to read runtime-config.json.');
            }

            $loaded = json_decode($raw, true);
            if (!is_array($loaded)) {
                return new WP_Error('ucwp_runtime_config_invalid', 'runtime-config.json did not contain a valid JSON object.');
            }

            $loaded = array_merge($loaded, self::load_runtime_secret_file());

            return self::normalize_runtime_config($loaded);
        }

        private static function render_runtime_config_json(array $runtime)
        {
            $public_runtime = self::normalize_runtime_config($runtime);
            unset($public_runtime['revalidate_secret'], $public_runtime['redis_password']);

            $encoded = wp_json_encode($public_runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded) || '' === $encoded) {
                return "{}\n";
            }

            return $encoded . "\n";
        }

        private static function write_file_atomically($target, $contents, $context)
        {
            $dir = dirname($target);
            if (!file_exists($dir) && !ucwp_safe_mkdir($dir, 0755, true, $context . ' mkdir') && !file_exists($dir)) {
                return false;
            }

            $tmp = trailingslashit($dir) . '.' . wp_basename($target) . '.tmp-' . wp_generate_password(8, false, false);
            if (false === ucwp_safe_file_put_contents($tmp, $contents, LOCK_EX, $context . ' tmp')) {
                ucwp_safe_unlink($tmp, $context . ' tmp cleanup');
                return false;
            }

            if (!ucwp_safe_rename($tmp, $target, $context . ' rename')) {
                ucwp_safe_unlink($tmp, $context . ' rename cleanup');
                return false;
            }

            return true;
        }

        private static function get_runtime_config_status_fast()
        {
            $path = self::get_runtime_config_path();
            $status = array(
                'exists' => file_exists($path),
                'readable' => is_readable($path),
                'valid_json' => false,
                'healthy' => false,
                'reason' => '',
            );

            if (!$status['exists']) {
                $status['reason'] = 'missing';
                return $status;
            }

            if (!$status['readable']) {
                $status['reason'] = 'not_readable';
                return $status;
            }

            $raw = file_get_contents($path);
            if (!is_string($raw) || '' === $raw) {
                $status['reason'] = 'empty_or_read_failed';
                return $status;
            }

            $decoded = json_decode($raw, true);
            $status['valid_json'] = is_array($decoded);
            $status['healthy'] = $status['valid_json'];
            $status['reason'] = $status['healthy'] ? 'present_valid_json' : 'invalid_json';

            return $status;
        }

        private static function runtime_config_needs_sync()
        {
            $loaded = self::load_runtime_config_file(self::get_runtime_config_path());
            if (is_wp_error($loaded)) {
                return true;
            }

            return $loaded !== self::build_runtime_config();
        }

        private static function normalize_runtime_config(array $runtime)
        {
            $defaults = self::get_dashboard_defaults();
            $fresh_minutes = self::sanitize_bounded_integer_setting($runtime['cache_fresh_ttl_minutes'] ?? $defaults['cacheFreshTtlMinutes'], $defaults['cacheFreshTtlMinutes'], 1, 1440);
            $max_stale_minutes = self::sanitize_bounded_integer_setting($runtime['cache_max_stale_minutes'] ?? $defaults['cacheMaxStaleMinutes'], $defaults['cacheMaxStaleMinutes'], $fresh_minutes, 10080);

            $normalized = array(
                'excluded_paths'                 => self::parse_textarea_setting(self::sanitize_excluded_paths_setting((array) ($runtime['excluded_paths'] ?? array()))),
                'excluded_query_args'            => self::parse_textarea_setting(self::sanitize_setting_key_list((array) ($runtime['excluded_query_args'] ?? array()))),
                'cache_query_strings'            => !empty($runtime['cache_query_strings']),
                'cache_query_allowlist'          => self::parse_textarea_setting(self::sanitize_setting_key_list((array) ($runtime['cache_query_allowlist'] ?? array()))),
                'woo_safe_mode'                  => !empty($runtime['woo_safe_mode']),
                'cache_stats_enabled'            => !empty($runtime['cache_stats_enabled']),
                'object_cache_enabled'           => !empty($runtime['object_cache_enabled']),
                'object_cache_backend'           => self::sanitize_object_cache_backend($runtime['object_cache_backend'] ?? 'redis'),
                'redis_host'                     => trim((string) ($runtime['redis_host'] ?? '127.0.0.1')) ?: '127.0.0.1',
                'redis_port'                     => max(1, min(65535, absint($runtime['redis_port'] ?? 6379))),
                'redis_password'                 => (string) ($runtime['redis_password'] ?? ''),
                'redis_database'                 => max(0, absint($runtime['redis_database'] ?? 0)),
                'redis_prefix'                   => preg_replace('/[^A-Za-z0-9:_\\-]/', '', (string) ($runtime['redis_prefix'] ?? '')),
                'redis_use_tls'                  => !empty($runtime['redis_use_tls']),
                'redis_persistent'               => !empty($runtime['redis_persistent']),
                'redis_connect_timeout_ms'       => max(50, min(10000, absint($runtime['redis_connect_timeout_ms'] ?? 200))),
                'redis_read_timeout_ms'          => max(50, min(10000, absint($runtime['redis_read_timeout_ms'] ?? 200))),
                'stale_while_revalidate_enabled' => !empty($runtime['stale_while_revalidate_enabled']),
                'cache_fresh_ttl_minutes'        => $fresh_minutes,
                'cache_max_stale_minutes'        => $max_stale_minutes,
                'revalidate_secret'              => (string) ($runtime['revalidate_secret'] ?? ''),
                'trusted_hosts'                  => array_values(array_filter(array_map('ucwp_normalize_host', (array) ($runtime['trusted_hosts'] ?? ucwp_get_trusted_hosts())))),
            );

            sort($normalized['excluded_paths']);
            sort($normalized['excluded_query_args']);
            sort($normalized['cache_query_allowlist']);

            return $normalized;
        }

        public static function sync_runtime_config()
        {
            self::ensure_directories();

            $runtime         = self::build_runtime_config();
            $config_target   = self::get_runtime_config_path();
            $config_contents = self::render_runtime_config_json($runtime);
            $secret_target   = self::get_runtime_secret_path();
            $secret_contents = self::render_runtime_secret_php($runtime);

            $secret_written = self::write_file_atomically($secret_target, $secret_contents, 'sync_runtime_config secret');
            $config_written = self::write_file_atomically($config_target, $config_contents, 'sync_runtime_config');

            return $secret_written && $config_written;
        }

        private static function get_cache_cleanup_schedule_name($hours)
        {
            return 'ucwp_every_' . max(1, absint($hours)) . '_hours';
        }

        public function register_cron_schedules($schedules)
        {
            $settings = self::get_settings();
            $hours    = max(1, absint($settings['cache_cleanup_interval_hours']));
            $key      = self::get_cache_cleanup_schedule_name($hours);

            if (empty($schedules[$key])) {
                $schedules[$key] = array(
                    'interval' => $hours * HOUR_IN_SECONDS,
                    'display'  => self::maybe_translate_sprintf('Every %d hour(s) for UltraCache', $hours),
                );
            }

            if (empty($schedules['ucwp_every_minute'])) {
                $schedules['ucwp_every_minute'] = array(
                    'interval' => MINUTE_IN_SECONDS,
                    'display'  => self::maybe_translate('Every minute for UltraCache'),
                );
            }

            return $schedules;
        }

        public static function unschedule_scheduled_events()
        {
            $timestamp = wp_next_scheduled('ucwp_scheduled_cache_cleanup');
            while ($timestamp) {
                wp_unschedule_event($timestamp, 'ucwp_scheduled_cache_cleanup');
                $timestamp = wp_next_scheduled('ucwp_scheduled_cache_cleanup');
            }
        }
        /**
         * Backward-compatible cleanup scheduler alias.
         *
         * The destructive cleanup action used this method name, while the
         * actual scheduled cleanup helper is unschedule_scheduled_events().
         * Keep this wrapper so the REST cleanup action cannot fatal.
         */
        public static function unschedule_cache_cleanup()
        {
            self::unschedule_scheduled_events();
        }



        private static function unschedule_cron_warm_events()
        {
            $timestamp = wp_next_scheduled('ucwp_cron_warm_tick');
            while ($timestamp) {
                wp_unschedule_event($timestamp, 'ucwp_cron_warm_tick');
                $timestamp = wp_next_scheduled('ucwp_cron_warm_tick');
            }

            $kickoff_timestamp = wp_next_scheduled('ucwp_cron_warm_tick_kickoff');
            while ($kickoff_timestamp) {
                wp_unschedule_event($kickoff_timestamp, 'ucwp_cron_warm_tick_kickoff');
                $kickoff_timestamp = wp_next_scheduled('ucwp_cron_warm_tick_kickoff');
            }
        }

        public static function sync_scheduled_events()
        {
            self::unschedule_scheduled_events();

            $settings = self::get_settings();
            if (!empty($settings['cache_cleanup_enabled'])) {
                $hours    = max(1, absint($settings['cache_cleanup_interval_hours']));
                $schedule = self::get_cache_cleanup_schedule_name($hours);
                wp_schedule_event(time() + MINUTE_IN_SECONDS, $schedule, 'ucwp_scheduled_cache_cleanup');
            }
        }

        private static function has_cron_warm_recurring_event_scheduled()
        {
            return false !== wp_next_scheduled('ucwp_cron_warm_tick');
        }

        private static function get_next_cron_warm_scheduled_at()
        {
            $times = array();
            $main = wp_next_scheduled('ucwp_cron_warm_tick');
            if ($main) {
                $times[] = (int) $main;
            }

            $kickoff = wp_next_scheduled('ucwp_cron_warm_tick_kickoff');
            if ($kickoff) {
                $times[] = (int) $kickoff;
            }

            return empty($times) ? 0 : min($times);
        }

        private static function ensure_cron_warm_events_scheduled($kickoff_delay = null)
        {
            if (!self::has_cron_warm_recurring_event_scheduled()) {
                wp_schedule_event(time() + MINUTE_IN_SECONDS, 'ucwp_every_minute', 'ucwp_cron_warm_tick');
            }

            if (null !== $kickoff_delay && !wp_next_scheduled('ucwp_cron_warm_tick_kickoff')) {
                $kickoff_delay = max(1, min(300, (int) $kickoff_delay));
                wp_schedule_single_event(time() + $kickoff_delay, 'ucwp_cron_warm_tick_kickoff');
            }
        }

        public function handle_scheduled_cache_cleanup()
        {
            self::run_scheduled_cache_cleanup();
        }

        public function handle_cron_warm_tick()
        {
            self::run_cron_warm_tick(array('invokedBy' => 'wp-cron'));
        }

        public function handle_cron_warm_tick_kickoff()
        {
            self::run_cron_warm_tick(array('invokedBy' => 'wp-cron-kickoff'));
        }

        public function handle_cron_warm_after_purge_all($payload = array())
        {
            self::maybe_start_cron_warmup_after_purge('manual_purge', false);
        }

        public static function maybe_start_cron_warmup_after_purge($reason = 'manual_purge', $run_immediately = false)
        {
            if (!empty(self::$suppress_after_purge_warm)) {
                return array('success' => false, 'message' => self::maybe_translate('Cron warm start suppressed for this purge.'), 'state' => self::get_cron_warm_status());
            }

            $settings = self::get_settings();
            if (empty($settings['cron_warm_enabled'])) {
                return array('success' => false, 'message' => self::maybe_translate('Cron warm up is disabled.'), 'state' => self::get_cron_warm_status());
            }

            if (!in_array((string) $reason, array('scheduled_cleanup', 'manual_purge', 'manual', 'cli'), true)) {
                $reason = 'manual_purge';
            }

            if ('scheduled_cleanup' === $reason && empty($settings['cron_warm_start_after_cleanup'])) {
                return array('success' => false, 'message' => self::maybe_translate('Cron warm up after scheduled cleanup is disabled.'), 'state' => self::get_cron_warm_status());
            }

            if (in_array((string) $reason, array('manual_purge', 'manual', 'cli'), true) && empty($settings['cron_warm_start_after_manual_purge'])) {
                return array('success' => false, 'message' => self::maybe_translate('Cron warm up after manual purge is disabled.'), 'state' => self::get_cron_warm_status());
            }

            $pages_per_minute = max(0, (int) $settings['cron_warm_pages_per_minute']);
            if ($pages_per_minute < 1) {
                return array('success' => false, 'message' => self::maybe_translate('Cron warm up is paused because pages per minute is 0.'), 'state' => self::get_cron_warm_status());
            }

            return self::start_cron_warmup_queue((string) $reason, (bool) $run_immediately);
        }

        public static function run_scheduled_cache_cleanup()
        {
            $engine = self::get_engine_instance();
            $settings = self::get_settings();
            $purged   = false;
            $warmed   = 0;
            $queue_started = false;
            $object_cache_removed = 0;
            $apcu_flushed = false;
            $apcu_flush_message = '';

            if ($engine && method_exists($engine, 'purge_all')) {
                self::$suppress_after_purge_warm = true;
                try {
                    $purged = (bool) $engine->purge_all();
                } finally {
                    self::$suppress_after_purge_warm = false;
                }
            }

            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'cleanup_expired_entries')) {
                $object_cache_removed = (int) Ultra_Cache_Object_Cache_Manager::cleanup_expired_entries();
            }

            if (!empty($settings['apcu_flush_on_scheduled_cleanup'])) {
                $apcu_flush_result = self::clear_apcu_user_cache(false);
                $apcu_flushed = !empty($apcu_flush_result['success']);
                $apcu_flush_message = isset($apcu_flush_result['message']) ? (string) $apcu_flush_result['message'] : '';
            }

            if ($purged) {
                $start_result = self::maybe_start_cron_warmup_after_purge('scheduled_cleanup', false);
                $queue_started = !empty($start_result['success']) && !empty(($start_result['state']['active'] ?? false));
                $warmed = (int) ($start_result['warmedThisRun'] ?? 0);
            }

            return array(
                'success' => ($purged || $object_cache_removed > 0 || $apcu_flushed),
                'warmed'  => $warmed,
                'queueStarted' => $queue_started,
                'objectCacheRemoved' => $object_cache_removed,
                'apcuFlushed' => $apcu_flushed,
                'apcuFlushMessage' => $apcu_flush_message,
            );
        }


        private static function get_default_cron_warm_state()
        {
            return array(
                'active'       => false,
                'reason'       => '',
                'cursor'       => '',
                'processed'    => 0,
                'total'        => 0,
                'successCount' => 0,
                'errorCount'   => 0,
                'startedAt'    => 0,
                'updatedAt'    => 0,
                'lastRunAt'    => 0,
                'finishedAt'   => 0,
                'pagesPerMinute' => 15,
                'totalLimit'   => 0,
                'currentBatch' => array(),
                'batchIndex'   => 0,
                'batchHasMore' => false,
                'nextCursorPending' => '',
                'lastError'    => '',
                'lastMessage'  => '',
                'lastUrl'      => '',
                'completed'    => false,
                'stopped'      => false,
                'stopReason'   => '',
                'invokedBy'    => '',
            );
        }

        public static function get_cron_warm_state()
        {
            $state = get_option(UCWP_CRON_WARM_STATE_KEY, array());
            if (!is_array($state)) {
                $state = array();
            }

            return array_merge(self::get_default_cron_warm_state(), $state);
        }

        private static function save_cron_warm_state(array $state)
        {
            $state = array_merge(self::get_default_cron_warm_state(), $state);
            if (false === get_option(UCWP_CRON_WARM_STATE_KEY, false)) {
                add_option(UCWP_CRON_WARM_STATE_KEY, $state, '', 'no');
            } else {
                update_option(UCWP_CRON_WARM_STATE_KEY, $state);
            }
            return $state;
        }

        public static function reset_cron_warmup_queue_after_cache_flush($reason = 'cache_flush')
        {
            $state = self::get_default_cron_warm_state();
            $state['active'] = false;
            $state['stopped'] = true;
            $state['completed'] = false;
            $state['stopReason'] = sanitize_key((string) $reason);
            $state['finishedAt'] = time();
            $state['updatedAt'] = time();
            $state['lastMessage'] = self::maybe_translate('Cron warm up queue reset after cache flush.');
            self::save_cron_warm_state($state);
            self::unschedule_cron_warm_events();
            return self::get_cron_warm_status();
        }

        private static function schedule_next_cron_warm_tick($delay_seconds = 5)
        {
            self::ensure_cron_warm_events_scheduled($delay_seconds);
        }

        private static function get_cron_warm_server_cron_command()
        {
            $path = rtrim(ABSPATH, '/\\');
            if ('' === $path) {
                $path = '.';
            }

            return '* * * * * cd ' . escapeshellarg($path) . ' && wp ultracache cron_warm tick --path=' . escapeshellarg($path) . ' >/dev/null 2>&1';
        }

        public static function get_cron_warm_status()
        {
            $settings = self::get_settings();
            $state = self::get_cron_warm_state();
            $next = self::get_next_cron_warm_scheduled_at();
            $remaining = max(0, (int) $state['total'] - (int) $state['processed']);

            return array(
                'enabled' => !empty($settings['cron_warm_enabled']),
                'startAfterCleanup' => !empty($settings['cron_warm_start_after_cleanup']),
                'startAfterManualPurge' => !empty($settings['cron_warm_start_after_manual_purge']),
                'pagesPerMinute' => max(0, (int) $settings['cron_warm_pages_per_minute']),
                'totalLimit' => max(0, (int) ($state['totalLimit'] ?: $settings['scheduled_warm_limit'])),
                'active' => !empty($state['active']),
                'processed' => max(0, (int) $state['processed']),
                'total' => max(0, (int) $state['total']),
                'remaining' => $remaining,
                'successCount' => max(0, (int) $state['successCount']),
                'errorCount' => max(0, (int) $state['errorCount']),
                'startedAt' => max(0, (int) $state['startedAt']),
                'updatedAt' => max(0, (int) $state['updatedAt']),
                'lastRunAt' => max(0, (int) $state['lastRunAt']),
                'finishedAt' => max(0, (int) $state['finishedAt']),
                'lastError' => (string) $state['lastError'],
                'lastMessage' => (string) $state['lastMessage'],
                'lastUrl' => (string) $state['lastUrl'],
                'reason' => (string) $state['reason'],
                'completed' => !empty($state['completed']),
                'stopped' => !empty($state['stopped']),
                'stopReason' => (string) $state['stopReason'],
                'invokedBy' => (string) $state['invokedBy'],
                'nextScheduledAt' => (int) $next,
                'serverCronCommand' => self::get_cron_warm_server_cron_command(),
            );
        }

        public static function start_cron_warmup_queue($reason = 'manual', $run_immediately = false)
        {
            $settings = self::get_settings();
            $engine = self::get_engine_instance();
            if (!$engine || !method_exists($engine, 'get_crawl_urls_cursor_batch') || !method_exists($engine, 'warm_url')) {
                return array('success' => false, 'message' => self::maybe_translate('Cron warm up is not available.'));
            }

            $pages_per_minute = max(0, (int) $settings['cron_warm_pages_per_minute']);
            $total_limit = max(0, (int) $settings['scheduled_warm_limit']);
            $state = self::save_cron_warm_state(array(
                'active'         => true,
                'reason'         => sanitize_key((string) $reason),
                'cursor'         => '',
                'processed'      => 0,
                'total'          => 0,
                'successCount'   => 0,
                'errorCount'     => 0,
                'startedAt'      => time(),
                'updatedAt'      => time(),
                'lastRunAt'      => 0,
                'finishedAt'     => 0,
                'pagesPerMinute' => $pages_per_minute,
                'totalLimit'     => $total_limit,
                'currentBatch'   => array(),
                'batchIndex'     => 0,
                'batchHasMore'   => false,
                'nextCursorPending' => '',
                'lastError'      => '',
                'lastMessage'    => self::maybe_translate('Cron warm up queued.'),
                'lastUrl'        => '',
                'completed'      => false,
                'stopped'        => false,
                'stopReason'     => '',
                'invokedBy'      => '',
            ));

            self::unschedule_cron_warm_events();
            self::ensure_cron_warm_events_scheduled(1);

            return array(
                'success' => true,
                'message' => self::maybe_translate('Cron warm up queued.'),
                'state'   => self::get_cron_warm_status(),
            );
        }

        public static function stop_cron_warmup_queue($reason = 'manual')
        {
            $state = self::get_cron_warm_state();
            $state['active'] = false;
            $state['stopped'] = true;
            $state['completed'] = false;
            $state['stopReason'] = sanitize_key((string) $reason);
            $state['finishedAt'] = time();
            $state['updatedAt'] = time();
            $state['lastMessage'] = self::maybe_translate('Cron warm up stopped.');
            self::save_cron_warm_state($state);
            self::unschedule_cron_warm_events();

            return array(
                'success' => true,
                'message' => self::maybe_translate('Cron warm up stopped.'),
                'state'   => self::get_cron_warm_status(),
            );
        }

        private static function get_cron_warm_lock_option_name()
        {
            return UCWP_CRON_WARM_LOCK_KEY . '_atomic';
        }

        private static function decode_cron_warm_lock($raw_lock)
        {
            if (is_array($raw_lock)) {
                return $raw_lock;
            }

            if (!is_string($raw_lock) || '' === $raw_lock) {
                return array();
            }

            $decoded = json_decode($raw_lock, true);
            return is_array($decoded) ? $decoded : array();
        }

        private static function acquire_cron_warm_lock($lock_token, $lock_ttl)
        {
            $now = time();
            $lock_ttl = max(10, (int) $lock_ttl);
            $option_name = self::get_cron_warm_lock_option_name();
            $existing = self::decode_cron_warm_lock(get_option($option_name, ''));

            if (!empty($existing['token']) && !empty($existing['expiresAt']) && (int) $existing['expiresAt'] > $now) {
                return false;
            }

            if (!empty($existing)) {
                delete_option($option_name);
            }

            $lock = array(
                'token' => (string) $lock_token,
                'startedAt' => $now,
                'expiresAt' => $now + $lock_ttl,
            );

            $payload = function_exists('wp_json_encode') ? wp_json_encode($lock) : json_encode($lock);
            if (!add_option($option_name, $payload, '', 'no')) {
                return false;
            }

            set_transient(UCWP_CRON_WARM_LOCK_KEY, $lock, $lock_ttl);
            return true;
        }

        private static function renew_cron_warm_lock($lock_token, $lock_ttl)
        {
            $lock_ttl = max(10, (int) $lock_ttl);
            $option_name = self::get_cron_warm_lock_option_name();
            $existing = self::decode_cron_warm_lock(get_option($option_name, ''));

            if (empty($existing['token']) || !hash_equals((string) $existing['token'], (string) $lock_token)) {
                return false;
            }

            $lock = array(
                'token' => (string) $lock_token,
                'startedAt' => !empty($existing['startedAt']) ? (int) $existing['startedAt'] : time(),
                'expiresAt' => time() + $lock_ttl,
            );

            $payload = function_exists('wp_json_encode') ? wp_json_encode($lock) : json_encode($lock);
            update_option($option_name, $payload, false);
            set_transient(UCWP_CRON_WARM_LOCK_KEY, $lock, $lock_ttl);
            return true;
        }

        private static function release_cron_warm_lock($lock_token)
        {
            $option_name = self::get_cron_warm_lock_option_name();
            $existing = self::decode_cron_warm_lock(get_option($option_name, ''));

            if (!empty($existing['token']) && hash_equals((string) $existing['token'], (string) $lock_token)) {
                delete_option($option_name);
            }

            $latest_lock = get_transient(UCWP_CRON_WARM_LOCK_KEY);
            if (is_array($latest_lock) && isset($latest_lock['token']) && hash_equals((string) $latest_lock['token'], (string) $lock_token)) {
                delete_transient(UCWP_CRON_WARM_LOCK_KEY);
            }
        }

        public static function run_cron_warm_tick(array $args = array())
        {
            $state = self::get_cron_warm_state();
            if (empty($state['active'])) {
                self::unschedule_cron_warm_events();
                return array(
                    'success' => true,
                    'message' => self::maybe_translate('Cron warm up queue is idle.'),
                    'warmedThisRun' => 0,
                    'state' => self::get_cron_warm_status(),
                );
            }

            $lock_ttl = 90;
            $now = time();
            $lock_token = wp_generate_password(20, false, false);
            if (!self::acquire_cron_warm_lock($lock_token, $lock_ttl)) {
                return array(
                    'success' => true,
                    'message' => self::maybe_translate('Cron warm up tick skipped because another run is active.'),
                    'warmedThisRun' => 0,
                    'state' => self::get_cron_warm_status(),
                );
            }

            try {
                $settings = self::get_settings();
                $engine = self::get_engine_instance();
                if (!$engine || !method_exists($engine, 'get_crawl_urls_cursor_batch') || !method_exists($engine, 'warm_url')) {
                    $state['active'] = false;
                    $state['lastError'] = 'Cron warm up engine is not available.';
                    $state['lastMessage'] = $state['lastError'];
                    $state['updatedAt'] = time();
                    self::save_cron_warm_state($state);
                    self::unschedule_cron_warm_events();
                    return array('success' => false, 'message' => $state['lastError'], 'state' => self::get_cron_warm_status());
                }

                $pages_per_minute = isset($args['pagesPerMinute']) && null !== $args['pagesPerMinute']
                    ? max(0, min(600, absint($args['pagesPerMinute'])))
                    : max(0, (int) ($state['pagesPerMinute'] ?: $settings['cron_warm_pages_per_minute']));
                $total_limit = isset($args['totalLimit']) && null !== $args['totalLimit']
                    ? max(0, min(5000, absint($args['totalLimit'])))
                    : max(0, (int) ($state['totalLimit'] ?: $settings['scheduled_warm_limit']));

                if ($pages_per_minute < 1) {
                    $state['active'] = false;
                    $state['completed'] = false;
                    $state['stopped'] = true;
                    $state['stopReason'] = 'paused';
                    $state['updatedAt'] = time();
                    $state['finishedAt'] = time();
                    $state['pagesPerMinute'] = 0;
                    $state['totalLimit'] = $total_limit;
                    $state['lastMessage'] = 'Cron warm up paused because pages per minute is 0.';
                    self::save_cron_warm_state($state);
                    self::unschedule_cron_warm_events();
                    return array('success' => false, 'message' => $state['lastMessage'], 'warmedThisRun' => 0, 'state' => self::get_cron_warm_status());
                }

                if ($total_limit > 0 && max(0, (int) $state['processed']) >= $total_limit) {
                    $state['active'] = false;
                    $state['completed'] = true;
                    $state['stopped'] = false;
                    $state['stopReason'] = '';
                    $state['finishedAt'] = time();
                    $state['pagesPerMinute'] = $pages_per_minute;
                    $state['totalLimit'] = $total_limit;
                    $state['total'] = max(0, min((int) $state['total'], $total_limit));
                    $state['lastMessage'] = 'Cron warm up reached the scheduled warm limit.';
                    self::save_cron_warm_state($state);
                    self::unschedule_cron_warm_events();
                    return array('success' => true, 'message' => $state['lastMessage'], 'warmedThisRun' => 0, 'state' => self::get_cron_warm_status());
                }

                $current_batch = isset($state['currentBatch']) && is_array($state['currentBatch']) ? array_values($state['currentBatch']) : array();
                $batch_index = max(0, (int) $state['batchIndex']);
                if ($batch_index >= count($current_batch)) {
                    $current_batch = array();
                    $batch_index = 0;
                }

                if (empty($current_batch)) {
                    $remaining_budget = $total_limit > 0 ? max(0, $total_limit - max(0, (int) $state['processed'])) : 0;
                    if ($total_limit > 0 && $remaining_budget < 1) {
                        $state['active'] = false;
                        $state['completed'] = true;
                        $state['stopped'] = false;
                        $state['stopReason'] = '';
                        $state['finishedAt'] = time();
                        $state['pagesPerMinute'] = $pages_per_minute;
                        $state['totalLimit'] = $total_limit;
                        $state['total'] = max(0, min((int) $state['total'], $total_limit));
                        $state['lastMessage'] = 'Cron warm up reached the scheduled warm limit.';
                        self::save_cron_warm_state($state);
                        self::unschedule_cron_warm_events();
                        return array('success' => true, 'message' => $state['lastMessage'], 'warmedThisRun' => 0, 'state' => self::get_cron_warm_status());
                    }

                    $batch_limit = $total_limit > 0 ? min($pages_per_minute, $remaining_budget) : $pages_per_minute;
                    $batch = $engine->get_crawl_urls_cursor_batch((string) $state['cursor'], $batch_limit);
                    $current_batch = isset($batch['items']) && is_array($batch['items']) ? array_values($batch['items']) : array();
                    $state['currentBatch'] = $current_batch;
                    $state['batchIndex'] = 0;
                    $state['batchHasMore'] = !empty($batch['hasMore']);
                    $state['nextCursorPending'] = !empty($batch['nextCursor']) ? (string) $batch['nextCursor'] : '';
                    $state['total'] = max((int) $state['total'], (int) ($batch['total'] ?? 0));
                    if ($total_limit > 0) {
                        $state['total'] = max(0, min((int) $state['total'], $total_limit));
                    }
                    $state['pagesPerMinute'] = $pages_per_minute;
                    $state['totalLimit'] = $total_limit;
                    $state['lastRunAt'] = $now;
                    $state['updatedAt'] = $now;
                    $state['invokedBy'] = !empty($args['invokedBy']) ? sanitize_key((string) $args['invokedBy']) : '';
                    $state['lastMessage'] = empty($current_batch) ? 'No eligible URLs found for this cron warm tick.' : 'Cron warm up running.';
                    self::save_cron_warm_state($state);
                } else {
                    $state['pagesPerMinute'] = $pages_per_minute;
                    $state['totalLimit'] = $total_limit;
                    $state['lastRunAt'] = $now;
                    $state['updatedAt'] = $now;
                    $state['invokedBy'] = !empty($args['invokedBy']) ? sanitize_key((string) $args['invokedBy']) : '';
                    self::save_cron_warm_state($state);
                }

                $warmed = 0;
                $errors = 0;
                $last_error = (string) $state['lastError'];
                $last_url = (string) $state['lastUrl'];

                foreach ($current_batch as $index => $url) {
                    if ($index < $batch_index) {
                        continue;
                    }

                    $last_url = (string) $url;
                    $result = $engine->warm_url($url, array('ignore_runtime_bypass' => true));
                    if (!empty($result['success'])) {
                        $warmed++;
                        $state['successCount'] = (int) $state['successCount'] + 1;
                    } else {
                        $errors++;
                        $state['errorCount'] = (int) $state['errorCount'] + 1;
                        if (!empty($result['message'])) {
                            $last_error = (string) $result['message'];
                        }
                    }

                    $state['batchIndex'] = $index + 1;
                    $state['processed'] = max(0, (int) $state['processed']) + 1;
                    $state['lastRunAt'] = time();
                    $state['updatedAt'] = time();
                    $state['lastError'] = (string) $last_error;
                    $state['lastUrl'] = $last_url;
                    $state['lastMessage'] = sprintf('Processed %d/%d URL(s) in the current cron warm batch.', $state['batchIndex'], count($current_batch));
                    self::save_cron_warm_state($state);

                    self::renew_cron_warm_lock($lock_token, $lock_ttl);
                }

                $completed = false;
                if (empty($current_batch)) {
                    if (!empty($state['batchHasMore']) && !empty($state['nextCursorPending'])) {
                        $state['cursor'] = (string) $state['nextCursorPending'];
                        $state['currentBatch'] = array();
                        $state['batchIndex'] = 0;
                        $state['batchHasMore'] = false;
                        $state['nextCursorPending'] = '';
                        $state['active'] = true;
                        $state['completed'] = false;
                        $state['stopped'] = false;
                        $state['stopReason'] = '';
                        $state['updatedAt'] = time();
                        $state['lastMessage'] = 'Advanced cron warm queue to the next batch.';
                        self::save_cron_warm_state($state);
                        self::ensure_cron_warm_events_scheduled();
                    } else {
                        $completed = true;
                    }
                } elseif ((int) $state['batchIndex'] >= count($current_batch)) {
                    if (!empty($state['batchHasMore'])) {
                        $state['cursor'] = (string) $state['nextCursorPending'];
                        $state['currentBatch'] = array();
                        $state['batchIndex'] = 0;
                        $state['batchHasMore'] = false;
                        $state['nextCursorPending'] = '';
                        $state['active'] = true;
                        $state['completed'] = false;
                        $state['stopped'] = false;
                        $state['stopReason'] = '';
                        $state['updatedAt'] = time();
                        $remaining_after = max(0, (int) $state['total'] - (int) $state['processed']);
                        $state['lastMessage'] = sprintf('Warmed %d URL(s) this tick. %d remaining.', $warmed, $remaining_after);
                        self::save_cron_warm_state($state);
                        self::ensure_cron_warm_events_scheduled();
                    } else {
                        $completed = true;
                    }
                }

                if ($completed) {
                    $state['active'] = false;
                    $state['completed'] = true;
                    $state['stopped'] = false;
                    $state['stopReason'] = '';
                    $state['finishedAt'] = time();
                    $state['currentBatch'] = array();
                    $state['batchIndex'] = 0;
                    $state['batchHasMore'] = false;
                    $state['nextCursorPending'] = '';
                    $state['lastMessage'] = $warmed > 0 || $state['processed'] > 0 ? 'Cron warm up complete.' : 'Cron warm up queue completed with no eligible URLs.';
                    self::save_cron_warm_state($state);
                    self::unschedule_cron_warm_events();
                }

                return array(
                    'success' => true,
                    'message' => $state['lastMessage'],
                    'warmedThisRun' => $warmed,
                    'errorsThisRun' => $errors,
                    'state' => self::get_cron_warm_status(),
                );
            } finally {
                self::release_cron_warm_lock($lock_token);
            }
        }

private static function delete_plugin_options_and_transients()
{
    global $wpdb;

    $option_names = array(
        UCWP_SETTINGS_KEY,
        UCWP_CRON_WARM_STATE_KEY,
        UCWP_CRON_WARM_LOCK_KEY . '_atomic',
        UCWP_WP_CACHE_MANAGED_KEY,
        UCWP_SETTINGS_KEY . '_action_jobs',
        'ucwp_media_conversion_queue',
        'ucwp_media_diagnostics_v1',
        'ucwp_object_cache_last_flush_report',
    );

    foreach ($option_names as $option_name) {
        delete_option($option_name);
        delete_site_option($option_name);
    }

    delete_transient(UCWP_CRON_WARM_LOCK_KEY);
    delete_transient('ucwp_loopback_ssl_status_v1');
    delete_transient('ucwp_frontend_compression_probe_v1');
    delete_transient('ucwp_media_conversion_queue_lock');
    delete_transient('ucwp_media_work_summary_v1');

    if ($wpdb instanceof wpdb) {
        $patterns = array(
            'ucwp_%',
            '_transient_ucwp_%',
            '_transient_timeout_ucwp_%',
            '_site_transient_ucwp_%',
            '_site_transient_timeout_ucwp_%',
        );

        foreach ($patterns as $pattern) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $pattern
                )
            );
        }

        if (is_multisite()) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
                    'ucwp_%'
                )
            );
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
                    '_site_transient_ucwp_%'
                )
            );
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
                    '_site_transient_timeout_ucwp_%'
                )
            );
        }
    }
}

private static function remove_runtime_secret_files()
{
    $candidates = array();

    if (defined('WP_CONTENT_DIR')) {
        $candidates[] = trailingslashit(WP_CONTENT_DIR) . 'ultracache-runtime-secrets.php';
        $candidates[] = trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache-runtime-secrets.php';
    }

    if (defined('UCWP_CACHE_DIR')) {
        $candidates[] = trailingslashit(UCWP_CACHE_DIR) . 'runtime-config.json';
        $candidates[] = trailingslashit(UCWP_CACHE_DIR) . 'runtime-secrets.php';
        $candidates[] = trailingslashit(UCWP_CACHE_DIR) . '.ultracache-runtime-secrets.php';
    }

    foreach (array_unique($candidates) as $path) {
        if (is_string($path) && '' !== $path && file_exists($path)) {
            ucwp_safe_unlink($path, 'delete_all_plugin_data runtime cleanup');
        }
    }
}

public static function delete_all_plugin_data_and_deactivate()
{
    if (!current_user_can('activate_plugins') && !current_user_can('manage_options')) {
        return new WP_Error('ucwp_forbidden', 'You do not have permission to deactivate this plugin.');
    }

    self::stop_cron_warmup_queue('delete-all-data');
    self::unschedule_cache_cleanup();
    self::unschedule_cron_warm_events();
    wp_clear_scheduled_hook('ucwp_scheduled_cache_cleanup');
    wp_clear_scheduled_hook('ucwp_cron_warm_tick');
    wp_clear_scheduled_hook('ucwp_cron_warm_tick_kickoff');
    wp_clear_scheduled_hook('ucwp_process_media_conversion_queue');

    if (class_exists('Ultra_Cache_Engine') && method_exists('Ultra_Cache_Engine', 'maybe_remove_advanced_cache')) {
        Ultra_Cache_Engine::maybe_remove_advanced_cache();
    }

    if (class_exists('Ultra_Cache_Object_Cache_Manager')) {
        if (method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache')) {
            Ultra_Cache_Object_Cache_Manager::flush_cache(true, true);
        }
        if (method_exists('Ultra_Cache_Object_Cache_Manager', 'maybe_remove_dropin')) {
            Ultra_Cache_Object_Cache_Manager::maybe_remove_dropin();
        }
    }

    self::sync_browser_cache_rules(false);
    self::set_wp_cache_flag(false);
    self::remove_runtime_secret_files();

    if (defined('UCWP_CACHE_DIR') && is_dir(UCWP_CACHE_DIR)) {
        ucwp_safe_rmdir(UCWP_CACHE_DIR, 'delete_all_plugin_data cache dir');
    }
    if (defined('UCWP_OBJECT_CACHE_DIR') && is_dir(UCWP_OBJECT_CACHE_DIR)) {
        ucwp_safe_rmdir(UCWP_OBJECT_CACHE_DIR, 'delete_all_plugin_data object cache dir');
    }

    // Keep converted media files by design. UCWP_AVIF_DIR and UCWP_WEBP_DIR
    // are intentionally not removed here.
    self::delete_plugin_options_and_transients();
    self::reset_settings_cache();

    if (!function_exists('deactivate_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    if (function_exists('deactivate_plugins')) {
        deactivate_plugins(UCWP_BASENAME, false, is_multisite());
    }

    return array(
        'success' => true,
        'message' => 'UltraCache data was deleted and the plugin was deactivated. Converted media folders were not deleted.',
        'mediaFoldersKept' => array(
            'avif' => defined('UCWP_AVIF_DIR') ? UCWP_AVIF_DIR : '',
            'webp' => defined('UCWP_WEBP_DIR') ? UCWP_WEBP_DIR : '',
        ),
    );
}

        public static function persist_dashboard_settings(array $settings)
        {
            $previous_settings = self::get_dashboard_settings();
            $current_settings = self::sanitize_dashboard_settings(self::merge_protected_dashboard_settings($settings, $previous_settings));
            $varnish_validation = self::validate_varnish_settings($current_settings);
            if (is_wp_error($varnish_validation)) {
                return $varnish_validation;
            }
            update_option(UCWP_SETTINGS_KEY, $current_settings);
            self::reset_settings_cache();
            self::ensure_directories();
            if (class_exists('Ultra_Cache_Engine') && method_exists('Ultra_Cache_Engine', 'ensure_cache_directories')) {
                Ultra_Cache_Engine::ensure_cache_directories();
            }

            $page_cache_sync = self::sync_page_cache_bootstrap(!empty($current_settings['pageCacheEnabled']));
            if (is_wp_error($page_cache_sync)) {
                return $page_cache_sync;
            }

            self::sync_runtime_config();
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
                return new WP_Error('ucwp_browser_cache_rules_not_writable', self::maybe_translate('Browser Cache Headers could not be written to .htaccess. Check file permissions or disable Browser Cache Headers.'));
            }

            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'sync_dropin')) {
                Ultra_Cache_Object_Cache_Manager::sync_dropin();
            }

            $google_fonts_job = null;
            $google_fonts_enabled_now = !empty($current_settings['googleFontsLocalOptimizationEnabled']);
            $google_fonts_was_enabled = !empty($previous_settings['googleFontsLocalOptimizationEnabled']);
            $google_fonts_urls_changed = (string) ($current_settings['googleFontsAdditionalScanUrls'] ?? '') !== (string) ($previous_settings['googleFontsAdditionalScanUrls'] ?? '');
            if ($google_fonts_enabled_now && (!$google_fonts_was_enabled || $google_fonts_urls_changed)) {
                $google_fonts_job = array(
                    'success' => true,
                    'queued'  => false,
                    'message' => 'Google Fonts settings saved. Use the Rebuild Google Fonts Cache button or wp ultracache google_fonts_rebuild --clear to rebuild the local font cache.',
                );
            }

            $payload = array(
                'success'     => true,
                'settings'    => self::get_dashboard_settings_for_client(),
                'stats'       => self::get_engine_stats(),
                'diagnostics' => self::get_dashboard_diagnostics(),
            );
            if (is_array($google_fonts_job)) {
                $payload['googleFonts'] = $google_fonts_job;
            }

            return $payload;
        }
        private static function get_browser_cache_htaccess_path()
        {
            return trailingslashit(ABSPATH) . '.htaccess';
        }

        private static function get_browser_cache_htaccess_block()
        {
            return implode("\n", array(
                '<IfModule mod_expires.c>',
                'ExpiresActive On',
                'ExpiresByType text/css "access plus 1 year"',
                'ExpiresByType text/javascript "access plus 1 year"',
                'ExpiresByType application/javascript "access plus 1 year"',
                'ExpiresByType application/x-javascript "access plus 1 year"',
                'ExpiresByType image/jpeg "access plus 1 year"',
                'ExpiresByType image/png "access plus 1 year"',
                'ExpiresByType image/gif "access plus 1 year"',
                'ExpiresByType image/webp "access plus 1 year"',
                'ExpiresByType image/avif "access plus 1 year"',
                'ExpiresByType image/svg+xml "access plus 1 year"',
                'ExpiresByType image/x-icon "access plus 1 year"',
                'ExpiresByType font/ttf "access plus 1 year"',
                'ExpiresByType font/otf "access plus 1 year"',
                'ExpiresByType font/woff "access plus 1 year"',
                'ExpiresByType font/woff2 "access plus 1 year"',
                'ExpiresByType application/font-woff "access plus 1 year"',
                'ExpiresByType application/font-woff2 "access plus 1 year"',
                '</IfModule>',
                '<IfModule mod_headers.c>',
                '<FilesMatch "\\.(css|js|mjs|gif|png|jpe?g|webp|avif|svg|ico|woff2?|ttf|otf|eot)$">',
                'Header set Cache-Control "public, max-age=31536000, immutable"',
                '</FilesMatch>',
                '</IfModule>',
            ));
        }

        public static function sync_browser_cache_rules($enabled = null)
        {
            $begin = '# BEGIN UltraCache Browser Cache';
            $end   = '# END UltraCache Browser Cache';

            if (null === $enabled) {
                $settings = self::get_settings();
                $enabled = !empty($settings['browser_cache_rules']);
            }

            $path = self::get_browser_cache_htaccess_path();
            $contents = file_exists($path) ? (string) ucwp_safe_file_get_contents($path, 'sync_browser_cache_rules') : '';
            $has_block = (false !== strpos($contents, $begin) && false !== strpos($contents, $end));

            if (file_exists($path) && !ucwp_path_is_writable($path)) {
                return !$enabled && !$has_block;
            }
            $pattern  = '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\R*/s';
            $updated  = (string) preg_replace($pattern, '', $contents);
            $updated  = rtrim($updated);

            if ($enabled) {
                $block = $begin . "\n" . self::get_browser_cache_htaccess_block() . "\n" . $end;
                $updated = '' === $updated ? $block : ($updated . "\n\n" . $block);
            }

            $updated = '' === trim($updated) ? '' : (rtrim($updated) . "\n");

            if ($updated === $contents) {
                return true;
            }

            $dir = dirname($path);
            if (!file_exists($dir) && !ucwp_safe_mkdir($dir, 0755, true, 'sync_browser_cache_rules') && !file_exists($dir)) {
                return false;
            }

            $tmp = $path . '.tmp-' . uniqid('', true);
            if (false === ucwp_safe_file_put_contents($tmp, $updated, LOCK_EX, 'sync_browser_cache_rules tmp')) {
                ucwp_safe_unlink($tmp, 'sync_browser_cache_rules tmp cleanup');
                return false;
            }

            if (!ucwp_safe_rename($tmp, $path, 'sync_browser_cache_rules rename')) {
                ucwp_safe_unlink($tmp, 'sync_browser_cache_rules rename cleanup');
                return false;
            }

            return true;
        }

        private static function get_engine_class()
        {
            $candidates = array('Ultra_Cache_Engine');
            foreach ($candidates as $class) {
                if (class_exists($class)) {
                    return $class;
                }
            }

            return null;
        }

        public static function sync_page_cache_bootstrap($enabled = null)
        {
            if (null === $enabled) {
                $settings = self::get_dashboard_settings();
                $enabled = !empty($settings['pageCacheEnabled']);
            }

            $enabled = (bool) $enabled;

            $result = self::set_wp_cache_flag($enabled);
            if (is_wp_error($result)) {
                return $result;
            }

            $engine_class = self::get_engine_class();
            if (!$engine_class) {
                return true;
            }

            if ($enabled) {
                if (method_exists($engine_class, 'setup_advanced_cache')) {
                    $engine_class::setup_advanced_cache();
                }
            } elseif (method_exists($engine_class, 'maybe_remove_advanced_cache')) {
                $engine_class::maybe_remove_advanced_cache();
            }

            return true;
        }

        private static function get_wp_config_path()
        {
            $paths = array(
                ABSPATH . 'wp-config.php',
                dirname(ABSPATH) . '/wp-config.php',
            );

            foreach ($paths as $path) {
                if (file_exists($path) && is_readable($path)) {
                    return $path;
                }
            }

            return false;
        }

        private static function get_managed_wp_cache_block($enabled)
        {
            return "// Added by UltraCache\n"
                . "if ( ! defined('WP_CACHE') ) {\n"
                . "\tdefine('WP_CACHE', " . ($enabled ? 'true' : 'false') . ");\n"
                . "}\n"
                . "// End UltraCache\n";
        }

        private static function strip_managed_wp_cache_block($contents)
        {
            $pattern = '/\n?\/\/ Added by UltraCache\R.*?\/\/ End UltraCache\R?/s';
            return (string) preg_replace($pattern, '', (string) $contents);
        }

        private static function get_wp_cache_insertion_offset($contents)
        {
            $contents = (string) $contents;
            if ('' === $contents) {
                return false;
            }

            $tokens = token_get_all($contents);
            if (!is_array($tokens) || empty($tokens)) {
                return false;
            }

            $offset   = 0;
            $saw_open = false;
            $total    = count($tokens);

            for ($i = 0; $i < $total; $i++) {
                $token = $tokens[$i];
                $text  = is_array($token) ? (string) $token[1] : (string) $token;

                if (!$saw_open) {
                    $offset += strlen($text);
                    if (is_array($token) && T_OPEN_TAG === $token[0]) {
                        $saw_open = true;
                    }
                    continue;
                }

                if (is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                    $offset += strlen($text);
                    continue;
                }

                if (is_array($token) && T_DECLARE === $token[0]) {
                    $offset += strlen($text);
                    $depth = 0;
                    for ($j = $i + 1; $j < $total; $j++) {
                        $next      = $tokens[$j];
                        $next_text = is_array($next) ? (string) $next[1] : (string) $next;
                        $offset += strlen($next_text);
                        if ('(' === $next_text) {
                            $depth++;
                        } elseif (')' === $next_text && $depth > 0) {
                            $depth--;
                        } elseif (';' === $next_text && 0 === $depth) {
                            $i = $j;
                            break;
                        }
                    }
                    continue;
                }

                break;
            }

            return $saw_open ? $offset : false;
        }

        private static function normalize_wp_cache_define_name($raw)
        {
            $raw = trim((string) $raw);
            if ('' === $raw) {
                return '';
            }

            $quote = substr($raw, 0, 1);
            if (("'" === $quote || '"' === $quote) && $quote === substr($raw, -1)) {
                $raw = substr($raw, 1, -1);
            }

            return stripslashes($raw);
        }

        private static function classify_wp_cache_define_value($raw)
        {
            $raw = trim((string) $raw);
            if ('' === $raw) {
                return 'unknown';
            }

            $normalized = strtolower($raw);
            if ('true' === $normalized) {
                return 'true';
            }

            if ('false' === $normalized) {
                return 'false';
            }

            $quote = substr($raw, 0, 1);
            if (("'" === $quote || '"' === $quote) && $quote === substr($raw, -1)) {
                $string_value = strtolower(stripslashes(substr($raw, 1, -1)));
                if ('true' === $string_value) {
                    return 'string-true';
                }
                if ('false' === $string_value) {
                    return 'string-false';
                }
            }

            return 'other';
        }

        private static function find_wp_cache_define_statements($contents)
        {
            $contents = (string) $contents;
            if ('' === $contents) {
                return array();
            }

            $tokens = token_get_all($contents);
            if (!is_array($tokens) || empty($tokens)) {
                return array();
            }

            $matches = array();
            $offset  = 0;
            $total   = count($tokens);

            for ($i = 0; $i < $total; $i++) {
                $token = $tokens[$i];
                $text  = is_array($token) ? (string) $token[1] : (string) $token;
                $len   = strlen($text);

                if (!is_array($token) || T_STRING !== $token[0] || 'define' !== strtolower($text)) {
                    $offset += $len;
                    continue;
                }

                $start_offset = $offset;
                $cursor       = $offset + $len;
                $j            = $i + 1;

                while ($j < $total) {
                    $next      = $tokens[$j];
                    $next_text = is_array($next) ? (string) $next[1] : (string) $next;
                    if (is_array($next) && in_array($next[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                        $cursor += strlen($next_text);
                        $j++;
                        continue;
                    }
                    break;
                }

                if ($j >= $total || '(' !== (is_array($tokens[$j]) ? (string) $tokens[$j][1] : (string) $tokens[$j])) {
                    $offset += $len;
                    continue;
                }

                $cursor += 1;
                $j++;
                $depth       = 0;
                $current_arg = '';
                $args        = array();
                $closed      = false;

                for (; $j < $total; $j++) {
                    $part      = $tokens[$j];
                    $part_text = is_array($part) ? (string) $part[1] : (string) $part;
                    $cursor   += strlen($part_text);

                    if ('(' === $part_text) {
                        $depth++;
                        $current_arg .= $part_text;
                        continue;
                    }

                    if (')' === $part_text) {
                        if ($depth > 0) {
                            $depth--;
                            $current_arg .= $part_text;
                            continue;
                        }

                        $args[] = $current_arg;
                        $current_arg = '';
                        $closed = true;
                        $j++;
                        break;
                    }

                    if (',' === $part_text && 0 === $depth) {
                        $args[] = $current_arg;
                        $current_arg = '';
                        continue;
                    }

                    $current_arg .= $part_text;
                }

                if (!$closed) {
                    $offset += $len;
                    continue;
                }

                while ($j < $total) {
                    $tail      = $tokens[$j];
                    $tail_text = is_array($tail) ? (string) $tail[1] : (string) $tail;
                    $cursor   += strlen($tail_text);
                    if (';' === $tail_text) {
                        break;
                    }
                    $j++;
                }

                if ($j >= $total) {
                    $offset += $len;
                    continue;
                }

                $name = isset($args[0]) ? self::normalize_wp_cache_define_name($args[0]) : '';
                if ('WP_CACHE' !== $name) {
                    $offset += $len;
                    continue;
                }

                $matches[] = array(
                    'start'      => $start_offset,
                    'end'        => $cursor,
                    'statement'  => substr($contents, $start_offset, $cursor - $start_offset),
                    'value_type' => self::classify_wp_cache_define_value(isset($args[1]) ? $args[1] : ''),
                );

                $offset = $cursor;
                $i      = $j;
            }

            return $matches;
        }

        private static function get_wp_cache_define_summary($contents)
        {
            $matches = self::find_wp_cache_define_statements($contents);
            if (empty($matches)) {
                return array(
                    'status'  => 'missing',
                    'matches' => array(),
                );
            }

            $status = 'other';
            foreach ($matches as $match) {
                $value_type = isset($match['value_type']) ? (string) $match['value_type'] : 'other';
                if ('true' === $value_type) {
                    return array(
                        'status'  => 'true',
                        'matches' => $matches,
                    );
                }

                if ('false' === $value_type) {
                    $status = 'false';
                    continue;
                }

                if ('false' !== $status) {
                    $status = 'nonstandard';
                }
            }

            return array(
                'status'  => $status,
                'matches' => $matches,
            );
        }

        private static function remove_wp_cache_define_statements($contents, $matches)
        {
            $contents = (string) $contents;
            if (empty($matches) || !is_array($matches)) {
                return $contents;
            }

            usort($matches, static function ($a, $b) {
                return (int) $b['start'] <=> (int) $a['start'];
            });

            foreach ($matches as $match) {
                $start = isset($match['start']) ? (int) $match['start'] : 0;
                $end   = isset($match['end']) ? (int) $match['end'] : $start;
                if ($end <= $start) {
                    continue;
                }

                $contents = substr($contents, 0, $start) . substr($contents, $end);
            }

            return $contents;
        }

        private static function insert_managed_wp_cache_block($contents, $block)
        {
            $offset = self::get_wp_cache_insertion_offset($contents);
            if (false === $offset) {
                return new WP_Error('ucwp_wp_config_anchor_not_found', 'Could not locate a safe insertion point for WP_CACHE in wp-config.php.');
            }

            $before = substr((string) $contents, 0, $offset);
            $after  = substr((string) $contents, $offset);
            $prefix = '';

            if ('' !== $before && !preg_match('/\R\z/', $before)) {
                $prefix = "\n";
            }

            return $before . $prefix . $block . "\n" . ltrim($after, "\r\n");
        }

        private static function get_wp_config_backup_path($config)
        {
            return dirname($config) . '/wp-config-backup-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false) . '.php';
        }

        private static function cleanup_wp_config_backups($config, $keep = 5)
        {
            $pattern = dirname($config) . '/wp-config-backup-*.php';
            $matches = glob($pattern);
            if (!is_array($matches) || count($matches) <= $keep) {
                return;
            }

            usort($matches, static function ($a, $b) {
                return (int) ucwp_safe_filemtime($b, 'cleanup_wp_config_backups') <=> (int) ucwp_safe_filemtime($a, 'cleanup_wp_config_backups');
            });

            foreach (array_slice($matches, max(0, (int) $keep)) as $old_backup) {
                ucwp_safe_unlink($old_backup, 'cleanup_wp_config_backups');
            }
        }

        private static function write_wp_config_atomically($config, $contents)
        {
            $backup = self::get_wp_config_backup_path($config);
            if (!ucwp_safe_copy($config, $backup, 'set_wp_cache_flag backup')) {
                return new WP_Error('ucwp_wp_config_backup_failed', 'Failed to create a wp-config backup before updating wp-config.php.');
            }

            self::cleanup_wp_config_backups($config);

            $tmp = $config . '.tmp-' . uniqid('', true);
            if (false === ucwp_safe_file_put_contents($tmp, $contents, LOCK_EX, 'set_wp_cache_flag tmp')) {
                ucwp_safe_unlink($tmp, 'set_wp_cache_flag tmp cleanup');
                return new WP_Error('ucwp_wp_config_write_failed', 'Failed to write temporary wp-config.php file.');
            }

            if (!ucwp_safe_rename($tmp, $config, 'set_wp_cache_flag rename')) {
                ucwp_safe_unlink($tmp, 'set_wp_cache_flag rename cleanup');
                return new WP_Error('ucwp_wp_config_write_failed', 'Failed to replace wp-config.php atomically.');
            }

            return true;
        }

        private static function set_wp_cache_flag($enabled = true)
        {
            $config = self::get_wp_config_path();
            if (!$config || !ucwp_path_is_writable($config)) {
                return new WP_Error('ucwp_wp_config_not_writable', 'wp-config.php was not found or is not writable.');
            }

            $raw_contents = ucwp_safe_file_get_contents($config, 'set_wp_cache_flag');
            if (false === $raw_contents) {
                return new WP_Error('ucwp_wp_config_read_failed', 'Failed to read wp-config.php.');
            }

            $enabled           = (bool) $enabled;
            $original_contents = (string) $raw_contents;
            $contents          = self::strip_managed_wp_cache_block($original_contents);

            if ($enabled) {
                $wp_cache_define = self::get_wp_cache_define_summary($contents);
                if ('true' === $wp_cache_define['status']) {
                    delete_option(UCWP_WP_CACHE_MANAGED_KEY);
                } else {
                    if (!empty($wp_cache_define['matches'])) {
                        $contents = self::remove_wp_cache_define_statements($contents, $wp_cache_define['matches']);
                    }

                    $block    = self::get_managed_wp_cache_block(true);
                    $contents = self::insert_managed_wp_cache_block($contents, $block);
                    if (is_wp_error($contents)) {
                        return $contents;
                    }

                    update_option(UCWP_WP_CACHE_MANAGED_KEY, !empty($wp_cache_define['matches']) ? 'replaced-existing' : 'block', false);
                }
            } else {
                delete_option(UCWP_WP_CACHE_MANAGED_KEY);
            }

            if ($contents === $original_contents) {
                return true;
            }

            return self::write_wp_config_atomically($config, $contents);
        }

        public function register_admin_menu()
        {
            add_menu_page(
                __('UltraCache', 'ultracache'),
                __('UltraCache', 'ultracache'),
                'manage_options',
                'ultracache',
                array($this, 'render_dashboard'),
                'dashicons-performance',
                100
            );
        }

        public function render_dashboard()
        {
            $version_label = self::maybe_translate_sprintf(
                'UltraCache %s',
                (string) UCWP_VERSION
            );

            echo '<div id="uc-dashboard"></div>';
            echo '<div class="ucwp-version-badge" aria-label="' . esc_attr($version_label) . '">' . esc_html($version_label) . '</div>';
            echo '<div id="ucwp-root" style="display:none"></div>';
            echo '<div id="ucwp-admin-root" style="display:none"></div>';
            echo '<div id="ultracache-root" style="display:none"></div>';
        }

        public function enqueue_admin_assets($hook)
        {
            if ('toplevel_page_ultracache' !== $hook) {
                return;
            }

            wp_enqueue_style('ucwp-admin-css', UCWP_URL . 'includes/admin-dashboard.css', array(), UCWP_VERSION);
            wp_enqueue_script('ucwp-admin-js', UCWP_URL . 'includes/admin-dashboard.js', array('wp-element'), UCWP_VERSION, true);
            wp_script_add_data('ucwp-admin-js', 'type', 'module');

            wp_localize_script(
                'ucwp-admin-js',
                'ucwpData',
                array(
                    'restBase'     => esc_url_raw(rest_url('ultracache/v1/')),
                    'restNonce'    => wp_create_nonce('wp_rest'),
                    'frontendProbeUrl' => esc_url_raw(home_url('/')),
                    'version'      => UCWP_VERSION,
                    'stats'        => self::get_engine_stats(),
                    'settings'     => self::get_dashboard_settings_for_client(),
                    'defaults'     => self::get_dashboard_defaults_for_client(),
                    'jsDelayDeferRecommendedExclusions' => implode("\n", self::get_default_js_delay_defer_exclusion_patterns()),
                    'avifSupport'  => self::get_media_support_status(),
                    'diagnostics'  => self::get_dashboard_diagnostics(),
                    'crawlScopeSummary' => self::get_crawl_scope_summary(),
                )
            );
        }

        public function suppress_conflicting_admin_assets($hook = '')
        {
            $is_ultracache_screen = ('toplevel_page_ultracache' === (string) $hook);

            if (!$is_ultracache_screen && function_exists('get_current_screen')) {
                $screen = get_current_screen();
                $is_ultracache_screen = $screen && 'toplevel_page_ultracache' === (string) $screen->id;
            }

            if (!$is_ultracache_screen) {
                return;
            }

            // Elementor Notes can be enqueued on unrelated admin pages and may
            // throw "React is not defined" when its dependency chain is not
            // present. UltraCache does not use it, so keep the dashboard clean.
            $blocked_handles = array(
                'elementor-notes',
                'elementor-notes-app',
                'elementor-notes-app-initiator',
                'elementor-pro-notes',
                'elementor-pro-notes-app',
                'elementor-pro-notes-app-initiator',
            );

            foreach ($blocked_handles as $handle) {
                wp_dequeue_script($handle);
                wp_deregister_script($handle);
                wp_dequeue_style($handle);
                wp_deregister_style($handle);
            }

            global $wp_scripts, $wp_styles;

            if ($wp_scripts instanceof WP_Scripts) {
                foreach ((array) $wp_scripts->registered as $handle => $script) {
                    $src = isset($script->src) ? (string) $script->src : '';
                    $handle_lc = strtolower((string) $handle);
                    $src_lc = strtolower($src);

                    if (false !== strpos($handle_lc, 'elementor-notes') || false !== strpos($src_lc, 'notes.min.js') || false !== strpos($src_lc, 'notes-app-initiator.min.js')) {
                        wp_dequeue_script($handle);
                        wp_deregister_script($handle);
                    }
                }
            }

            if ($wp_styles instanceof WP_Styles) {
                foreach ((array) $wp_styles->registered as $handle => $style) {
                    $src = isset($style->src) ? strtolower((string) $style->src) : '';
                    $handle_lc = strtolower((string) $handle);

                    if (false !== strpos($handle_lc, 'elementor-notes') || false !== strpos($src, 'notes.min.css')) {
                        wp_dequeue_style($handle);
                        wp_deregister_style($handle);
                    }
                }
            }
        }

        public function maybe_fix_revslider_footer_conflict()
        {
            if (!is_admin()) {
                return;
            }

            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if (!$screen || 'toplevel_page_ultracache' !== (string) $screen->id) {
                return;
            }

            if (class_exists('RevSliderAdmin')) {
                remove_action('admin_footer', array('RevSliderAdmin', 'add_ajax_footer_functionality'));
            }
        }

        public function register_admin_bar_menu($admin_bar)
        {
            if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
                return;
            }

            $admin_bar->add_node(
                array(
                    'id'    => 'ultracache',
                    'title' => __('UltraCache', 'ultracache'),
                    'href'  => admin_url('admin.php?page=ultracache'),
                    'meta'  => array('title' => __('UltraCache', 'ultracache')),
                )
            );

            $admin_bar->add_node(
                array(
                    'id'     => 'ultracache-purge-all',
                    'parent' => 'ultracache',
                    'title'  => __('Clear All Cache', 'ultracache'),
                    'href'   => wp_nonce_url(add_query_arg('ucwp_action', 'purge_all'), 'ucwp_purge_nonce'),
                )
            );

            if (!is_admin()) {
                $admin_bar->add_node(
                    array(
                        'id'     => 'ultracache-purge-page',
                        'parent' => 'ultracache',
                        'title'  => __('Clear This Page', 'ultracache'),
                        'href'   => wp_nonce_url(add_query_arg('ucwp_action', 'purge_page'), 'ucwp_purge_nonce'),
                    )
                );
            }
        }

        public function handle_admin_bar_actions()
        {
            if (empty($_GET['ucwp_action']) || !current_user_can('manage_options')) {
                return;
            }

            if (empty($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'ucwp_purge_nonce')) {
                return;
            }

            $engine = self::get_engine_instance();
            if (!$engine) {
                return;
            }

            $action = sanitize_key(wp_unslash($_GET['ucwp_action']));
            if ('purge_all' === $action && method_exists($engine, 'purge_all')) {
                $engine->purge_all();
                set_transient('ucwp_admin_notice', __('UltraCache: all cache cleared.', 'ultracache'), 30);
            } elseif ('purge_page' === $action) {
                $url = self::get_current_url_without_plugin_args();
                if ($url) {
                    if (method_exists($engine, 'purge_url')) {
                        $engine->purge_url($url);
                    } elseif (method_exists($engine, 'purge_page_by_url')) {
                        $engine->purge_page_by_url($url);
                    }

                    set_transient('ucwp_admin_notice', __('UltraCache: current page cache cleared.', 'ultracache'), 30);
                }
            }

            wp_safe_redirect(remove_query_arg(array('ucwp_action', '_wpnonce')));
            exit;
        }

        public function render_admin_notice()
        {
            $notice = get_transient('ucwp_admin_notice');
            if (!$notice) {
                return;
            }

            delete_transient('ucwp_admin_notice');

            $type = 'success';
            $message = $notice;
            if (is_array($notice)) {
                $message = isset($notice['message']) ? (string) $notice['message'] : '';
                $type = isset($notice['type']) ? sanitize_key((string) $notice['type']) : 'success';
            }

            if ('' === trim((string) $message)) {
                return;
            }

            if (!in_array($type, array('success', 'warning', 'error', 'info'), true)) {
                $type = 'success';
            }

            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }

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
                    'message'   => 'OPcache functions are unavailable on this server.',
                );
            }

            $status = @opcache_get_status(false);
            if (!is_array($status)) {
                return array(
                    'available' => true,
                    'enabled'   => false,
                    'message'   => 'OPcache is not enabled for the current PHP SAPI.',
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
                'restartPending'            => !empty($status['restart_pending']),
            );
        }

        public static function flush_opcache()
        {
            if (!function_exists('opcache_reset')) {
                return array(
                    'success' => false,
                    'message' => 'OPcache reset is unavailable on this server.',
                );
            }

            $success = (bool) @opcache_reset();
            $response = array(
                'success' => $success,
                'message' => $success ? 'OPcache flushed successfully.' : 'OPcache flush failed.',
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
                    'message'   => 'APCu functions are unavailable on this server.',
                );
            }

            if (function_exists('apcu_enabled') && !apcu_enabled()) {
                return array(
                    'available' => true,
                    'enabled'   => false,
                    'message'   => 'APCu is loaded but disabled for the current PHP SAPI.',
                );
            }

            $cache_info = @apcu_cache_info(true);
            $sma_info   = @apcu_sma_info(true);
            if (!is_array($cache_info) || !is_array($sma_info)) {
                return array(
                    'available' => true,
                    'enabled'   => false,
                    'message'   => 'APCu status could not be read for the current PHP SAPI.',
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
                    'message' => 'APCu clear cache is unavailable on this server.',
                );
            }

            if (function_exists('apcu_enabled') && !apcu_enabled()) {
                return array(
                    'success' => false,
                    'message' => 'APCu is loaded but disabled for the current PHP SAPI.',
                );
            }

            $success = (bool) @apcu_clear_cache();
            $response = array(
                'success' => $success,
                'message' => $success ? 'APCu user cache flushed successfully.' : 'APCu user cache flush failed.',
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

        public static function get_engine_stats($full_object_count = false)
        {
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

            $media = self::get_media_instance();
            if ($media && method_exists($media, 'get_stats')) {
                $media_stats = $media->get_stats();
                if (is_array($media_stats)) {
                    $stats = array_merge($stats, $media_stats);
                }
            }

            $stats['cronWarm'] = self::get_cron_warm_status();
            $stats['opcache'] = self::get_opcache_status_summary();
            $stats['apcu'] = self::get_apcu_status_summary();
            $stats['diagnostics'] = self::get_dashboard_diagnostics();
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

            $cached = get_transient('ucwp_frontend_compression_probe_v1');
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
                set_transient('ucwp_frontend_compression_probe_v1', $status, 5 * MINUTE_IN_SECONDS);
                return $status;
            }

            $probe_url = add_query_arg('ucwp_probe_compression', (string) time(), $probe_base);
            $encodings = array(
                'brotli' => 'br',
                'gzip'   => 'gzip',
            );

            foreach ($encodings as $bucket => $accept_encoding) {
                $response = ucwp_safe_loopback_remote_request($probe_url, array(
                    'method'              => 'GET',
                    'timeout'             => 5,
                    'redirection'         => 2,
                    'decompress'          => false,
                    'limit_response_size' => 1,
                    'headers'             => array(
                        'Cache-Control'           => 'no-cache',
                        'Pragma'                  => 'no-cache',
                        'Accept-Encoding'         => $accept_encoding,
                        'X-UltraCache-Compression-Probe' => '1',
                    ),
                ), 'frontend_compression_probe');

                if (is_wp_error($response)) {
                    continue;
                }

                $headers = wp_remote_retrieve_headers($response);
                $content_encoding = strtolower(trim((string) ($headers['content-encoding'] ?? '')));
                $ultracache_encoding = strtolower(trim((string) ($headers['x-ultracache-encoding'] ?? '')));
                $body = (string) wp_remote_retrieve_body($response);
                $gzip_magic = (strlen($body) >= 2 && 0x1f === ord($body[0]) && 0x8b === ord($body[1]));

                if ('' !== $ultracache_encoding) {
                    if ('gzip' === $ultracache_encoding && false === strpos($content_encoding, 'gzip') && $gzip_magic) {
                        $status['brokenGzip'] = true;
                        $status['detected'] = true;
                    }
                    if ('brotli' === $ultracache_encoding && false === strpos($content_encoding, 'br') && '' !== $body) {
                        $status['brokenBrotli'] = true;
                        $status['detected'] = true;
                    }
                    continue;
                }

                if ('brotli' === $bucket && false !== strpos($content_encoding, 'br')) {
                    $status['brotli'] = true;
                    $status['detected'] = true;
                }
                if ('gzip' === $bucket && false !== strpos($content_encoding, 'gzip')) {
                    $status['gzip'] = true;
                    $status['detected'] = true;
                }
            }

            if ($status['brokenBrotli'] && $status['brokenGzip']) {
                $status['message'] = 'UltraCache detected Brotli and gzip compressed output without matching Content-Encoding headers. Plugin compression has been disabled as a safety measure.';
            } elseif ($status['brokenBrotli']) {
                $status['message'] = 'UltraCache detected Brotli compressed output without a matching Content-Encoding header. Brotli has been disabled as a safety measure.';
            } elseif ($status['brokenGzip']) {
                $status['message'] = 'UltraCache detected gzip-compressed output without a matching Content-Encoding header. Gzip has been disabled as a safety measure.';
            } elseif ($status['brotli'] && $status['gzip']) {
                $status['message'] = 'Your server is already using Brotli and gzip compression by default.';
            } elseif ($status['brotli']) {
                $status['message'] = 'Your server is already using Brotli compression by default.';
            } elseif ($status['gzip']) {
                $status['message'] = 'Your server is already using gzip compression by default.';
            }

            set_transient('ucwp_frontend_compression_probe_v1', $status, 5 * MINUTE_IN_SECONDS);
            return $status;
        }

        private static function get_wp_cache_define_status()
        {
            $config = self::get_wp_config_path();
            if (!$config) {
                return array(
                    'status'  => 'missing-config',
                    'message' => self::maybe_translate('wp-config.php could not be located.'),
                );
            }

            $raw_contents = ucwp_safe_file_get_contents($config, 'get_wp_cache_define_status');
            if (false === $raw_contents) {
                return array(
                    'status'  => 'read-failed',
                    'message' => self::maybe_translate('wp-config.php could not be read.'),
                );
            }

            $contents = (string) $raw_contents;
            if (false !== strpos($contents, '// Added by UltraCache')) {
                return array(
                    'status'  => 'managed',
                    'message' => self::maybe_translate('WP_CACHE is managed by UltraCache.'),
                );
            }

            $wp_cache_define = self::get_wp_cache_define_summary($contents);
            if ('true' === $wp_cache_define['status']) {
                return array(
                    'status'  => 'true',
                    'message' => self::maybe_translate('WP_CACHE is already defined as true in wp-config.php.'),
                );
            }

            if ('false' === $wp_cache_define['status']) {
                return array(
                    'status'  => 'false',
                    'message' => self::maybe_translate('WP_CACHE is currently defined as false in wp-config.php and UltraCache will disable that line safely before enabling page cache.'),
                );
            }

            if ('nonstandard' === $wp_cache_define['status']) {
                return array(
                    'status'  => 'nonstandard',
                    'message' => self::maybe_translate('WP_CACHE is defined in a non-standard way in wp-config.php and UltraCache can replace it safely when enabling page cache.'),
                );
            }

            return array(
                'status'  => 'missing',
                'message' => self::maybe_translate('WP_CACHE is not currently defined in wp-config.php. UltraCache can add it automatically.'),
            );
        }


        public function handle_varnish_after_purge_all($payload = array())
        {
            self::varnish_flush_all_current_host();
        }

        public function handle_varnish_after_purge_urls($urls, $scope = 'batch', $payload = array())
        {
            if (!is_array($urls)) {
                return;
            }

            foreach ($urls as $url) {
                self::varnish_flush_url($url);
            }
        }

        private static function get_varnish_log_directory()
        {
            return trailingslashit(UCWP_CACHE_DIR) . 'logs';
        }

        private static function ensure_varnish_log_directory()
        {
            $dir = self::get_varnish_log_directory();
            if ('' === $dir) {
                return '';
            }

            if (!file_exists($dir) && !ucwp_safe_mkdir($dir, 0700, true, 'ensure_varnish_log_directory') && !file_exists($dir)) {
                return '';
            }

            $index = trailingslashit($dir) . 'index.php';
            if (!file_exists($index)) {
                ucwp_safe_file_put_contents($index, "<?php\n// Silence is golden.\n", 0, 'varnish_log index');
            }

            $htaccess = trailingslashit($dir) . '.htaccess';
            if (!file_exists($htaccess)) {
                ucwp_safe_file_put_contents($htaccess, "Deny from all\n", 0, 'varnish_log htaccess');
            }

            return $dir;
        }

        private static function get_varnish_log_path()
        {
            $dir = self::ensure_varnish_log_directory();
            if ('' === $dir) {
                return '';
            }

            return trailingslashit($dir) . 'varnish-cli.log';
        }

        private static function varnish_log($line)
        {
            $settings = self::get_dashboard_settings();
            if (empty($settings['varnishCliDebug'])) {
                return;
            }

            $file = self::get_varnish_log_path();
            if ('' === $file) {
                return;
            }

            if (file_exists($file) && (int) ucwp_safe_filesize($file, 'varnish_log_rotate') > 1048576) {
                ucwp_safe_unlink($file . '.1', 'varnish_log_rotate');
                ucwp_safe_rename($file, $file . '.1', 'varnish_log_rotate');
            }

            $entry = '[' . gmdate('Y-m-d H:i:s') . "] " . (string) $line . "\n";
            ucwp_safe_file_put_contents($file, $entry, FILE_APPEND | LOCK_EX, 'varnish_log_append');
            if (file_exists($file)) {
                $filesystem = ucwp_get_wp_filesystem();
                if ($filesystem && method_exists($filesystem, 'chmod')) {
                    $filesystem->chmod($file, 0600);
                }
            }
        }

        private static function set_varnish_last_result(array $result)
        {
            set_transient('ucwp_varnish_last_result', $result, DAY_IN_SECONDS);
        }

        private static function get_varnish_last_result()
        {
            $value = get_transient('ucwp_varnish_last_result');
            return is_array($value) ? $value : array();
        }

        private static function get_varnish_support_status()
        {
            $http_available = function_exists('wp_safe_remote_request');
            $admin_available = function_exists('fsockopen');
            $available = $http_available || $admin_available;
            if ($http_available && $admin_available) {
                $message = 'Varnish integration supports both HTTP host:port purge endpoints and admin-secret mode.';
            } elseif ($http_available) {
                $message = 'Varnish integration supports HTTP host:port purge endpoints on this server.';
            } elseif ($admin_available) {
                $message = 'Varnish integration supports admin-secret mode on this server.';
            } else {
                $message = 'Neither the WordPress HTTP API nor socket support is available, so Varnish integration is unavailable.';
            }

            return array(
                'available' => $available,
                'message'   => $message,
            );
        }

        public static function get_varnish_cli_settings()
        {
            $settings = self::get_dashboard_settings();

            return array(
                'enabled'      => !empty($settings['varnishCliEnabled']),
                'mode'         => self::sanitize_varnish_mode($settings['varnishCliMode']),
                'servers_raw'  => self::sanitize_varnish_servers_string($settings['varnishCliServers'], self::sanitize_varnish_mode($settings['varnishCliMode'])),
                'servers'      => array_values(array_filter(array_map('trim', preg_split('/\s+/', self::sanitize_varnish_servers_string($settings['varnishCliServers'], self::sanitize_varnish_mode($settings['varnishCliMode'])))))),
                'key'          => trim((string) $settings['varnishCliKey']),
                'timeout'      => max(1, min(30, absint($settings['varnishCliTimeoutSeconds']))),
                'method'       => ('PURGE' === strtoupper(trim((string) $settings['varnishCliMethod']))) ? 'PURGE' : 'BAN',
                'debug'        => !empty($settings['varnishCliDebug']),
                'support'      => self::get_varnish_support_status(),
                'last'         => self::get_varnish_last_result(),
                'endpointDiagnostics' => self::get_varnish_endpoint_diagnostics($settings['varnishCliServers'], self::sanitize_varnish_mode($settings['varnishCliMode'])),
            );
        }

        private static function normalize_varnish_endpoint($terminal)
        {
            $terminal = trim((string) $terminal);
            if ('' === $terminal) {
                return array();
            }

            $check = self::validate_varnish_http_endpoint($terminal);
            if (empty($check['valid'])) {
                return array();
            }

            $host = (string) ($check['host'] ?? '');
            $port = (int) ($check['port'] ?? 0);
            if ('' === $host || $port <= 0) {
                return array();
            }

            return array(
                'scheme' => 'http',
                'host'   => $host,
                'port'   => $port,
            );
        }

        private static function build_varnish_target_url(array $endpoint, $path = '/')
        {
            $path = '/' . ltrim((string) $path, '/');
            return $endpoint['scheme'] . '://' . $endpoint['host'] . ':' . $endpoint['port'] . $path;
        }

        private static function summarize_varnish_http_body($body, $max_length = 180)
        {
            $body = trim(wp_strip_all_tags((string) $body));
            if ('' === $body) {
                return '';
            }

            $body = preg_replace('/\s+/', ' ', $body);
            if (!is_string($body) || '' === $body) {
                return '';
            }

            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($body) > $max_length) {
                    $body = mb_substr($body, 0, $max_length - 1) . '…';
                }
            } elseif (strlen($body) > $max_length) {
                $body = substr($body, 0, $max_length - 1) . '…';
            }

            return $body;
        }

        private static function send_varnish_http_request(array $endpoint, $target_url, $host_header, $timeout_s, $expr, $method)
        {
            $headers = array(
                'Host'               => (string) $host_header,
                'X-Ban-Expression'   => (string) $expr,
                'X-UltraCache-Purge' => '1',
            );

            $settings = self::get_varnish_cli_settings();
            if (!empty($settings['key'])) {
                $headers['X-UltraCache-Token'] = (string) $settings['key'];
            }

            $response = ucwp_safe_loopback_remote_request($target_url, array(
                'method'      => (string) $method,
                'timeout'     => max(1, (int) $timeout_s),
                'redirection' => 0,
                'headers'     => $headers,
                'body'        => '',
            ), 'varnish_http_request');

            if (is_wp_error($response)) {
                return array('ok' => false, 'detail' => $response->get_error_message());
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $message = trim((string) wp_remote_retrieve_response_message($response));
            $body = trim((string) wp_remote_retrieve_body($response));
            $content_type = strtolower(trim((string) wp_remote_retrieve_header($response, 'content-type')));
            $summary = self::summarize_varnish_http_body($body);
            $looks_like_html = (false !== strpos($content_type, 'text/html')) || ('' !== $body && preg_match('/<(?:!doctype|html|head|body)/i', $body));

            if ($code < 200 || $code >= 300) {
                $detail = 'HTTP ' . $code . ($message !== '' ? ' ' . $message : '');
                if ($summary !== '') {
                    $detail .= ' · ' . $summary;
                }
                return array('ok' => false, 'detail' => $detail, 'code' => $code);
            }

            if ($looks_like_html) {
                return array(
                    'ok' => false,
                    'detail' => 'HTTP ' . $code . ' returned an HTML page instead of a Varnish purge response. Check that this endpoint points to a Varnish frontend/listener that accepts ' . strtoupper((string) $method) . '.',
                    'code' => $code,
                );
            }

            $detail = 'HTTP ' . $code . ($message !== '' ? ' ' . $message : '');
            if ($summary !== '') {
                $detail .= ' · ' . $summary;
            } elseif ($message === '') {
                $detail .= ' ' . strtoupper((string) $method) . ' OK';
            }

            return array('ok' => true, 'detail' => $detail, 'code' => $code);
        }

        private static function parse_varnish_terminal($terminal)
        {
            $terminal = trim((string) $terminal);
            if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $terminal, $matches)) {
                return array($matches[1], (int) $matches[2]);
            }

            $pos = strrpos($terminal, ':');
            if (false === $pos) {
                return array('', 0);
            }

            return array(substr($terminal, 0, $pos), (int) substr($terminal, $pos + 1));
        }

        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        private static function read_varnish_admin_response($fp)
        {
            $header = ucwp_safe_fread($fp, 13, 'read_varnish_admin_response header');
            if (false === $header || strlen($header) < 13) {
                return array('ok' => false, 'code' => 0, 'body' => 'Failed to read Varnish admin response header.');
            }

            $code = (int) substr($header, 0, 3);
            $length = (int) substr($header, 4, 6) + 1;
            $body = '';
            while (strlen($body) < $length && !feof($fp)) {
                $chunk = ucwp_safe_fread($fp, $length - strlen($body), 'read_varnish_admin_response body');
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                $body .= $chunk;
            }

            return array('ok' => true, 'code' => $code, 'body' => trim((string) $body));
        }

        private static function extract_varnish_admin_challenge($body)
        {
            $body = (string) $body;
            if (preg_match('/^([A-Za-z0-9]{32,64})/m', $body, $matches)) {
                return (string) $matches[1];
            }

            return '';
        }

        private static function build_varnish_admin_auth_token($challenge, $secret)
        {
            $challenge = trim((string) $challenge);
            $secret    = trim((string) $secret);
            if ('' === $challenge || '' === $secret || !function_exists('hash')) {
                return '';
            }

            return hash('sha256', $challenge . "
" . $secret . "
" . $challenge . "
");
        }

        private static function send_varnish_admin_ban($host, $port, $secret, $timeout_s, $expr)
        {
            $host = trim((string) $host);
            $port = (int) $port;
            $secret = (string) $secret;

            if ('' === $host || $port <= 0 || !ucwp_is_allowed_socket_target($host, $port)) {
                return array('ok' => false, 'detail' => 'Invalid or blocked Varnish admin endpoint.');
            }

            if ('' === trim($secret)) {
                return array('ok' => false, 'detail' => 'Varnish admin secret is required for admin mode.');
            }

            $connect = static function () use ($host, $port, $timeout_s) {
                $errno  = 0;
                $errstr = '';
                $fp = ucwp_safe_fsockopen($host, $port, $errno, $errstr, max(1, (int) $timeout_s), 'send_varnish_admin_ban');
                if (!is_resource($fp)) {
                    return array(false, 'Connection failed: ' . trim($errstr !== '' ? $errstr : ('Error ' . $errno)));
                }
                stream_set_timeout($fp, max(1, (int) $timeout_s));
                return array($fp, '');
            };

            list($fp, $connect_error) = $connect();
            if (!is_resource($fp)) {
                return array('ok' => false, 'detail' => $connect_error);
            }

            $hello = self::read_varnish_admin_response($fp);
            if (empty($hello['ok'])) {
                fclose($fp);
                return array('ok' => false, 'detail' => (string) ($hello['body'] ?? 'Invalid admin banner.'));
            }

            if (107 === (int) $hello['code']) {
                $challenge = self::extract_varnish_admin_challenge((string) ($hello['body'] ?? ''));
                if ('' === $challenge) {
                    fclose($fp);
                    return array('ok' => false, 'detail' => 'Admin auth failed · Missing challenge from Varnish banner.');
                }

                $tokens = array();
                $tokens[] = self::build_varnish_admin_auth_token($challenge, $secret);
                $tokens = array_values(array_unique(array_filter($tokens)));
                if (empty($tokens)) {
                    fclose($fp);
                    return array('ok' => false, 'detail' => 'Admin auth failed · Could not build auth token.');
                }

                $auth = array('ok' => false, 'code' => 0, 'body' => '');
                foreach ($tokens as $index => $token) {
                    fwrite($fp, 'auth ' . $token . "
");
                    $auth = self::read_varnish_admin_response($fp);
                    if (!empty($auth['ok']) && 200 === (int) ($auth['code'] ?? 0)) {
                        break;
                    }
                    if ($index < count($tokens) - 1) {
                        fclose($fp);
                        list($fp, $connect_error) = $connect();
                        if (!is_resource($fp)) {
                            return array('ok' => false, 'detail' => $connect_error);
                        }
                        $hello = self::read_varnish_admin_response($fp);
                        if (empty($hello['ok']) || 107 !== (int) ($hello['code'] ?? 0)) {
                            fclose($fp);
                            return array('ok' => false, 'detail' => 'Admin auth failed · Could not re-open authenticated session.');
                        }
                    }
                }

                if (empty($auth['ok']) || 200 !== (int) ($auth['code'] ?? 0)) {
                    fclose($fp);
                    $detail = 'Admin auth failed';
                    if (!empty($auth['body'])) {
                        $detail .= ' · ' . self::summarize_varnish_http_body($auth['body']);
                    }
                    return array('ok' => false, 'detail' => $detail);
                }
            } elseif (200 !== (int) $hello['code']) {
                fclose($fp);
                return array('ok' => false, 'detail' => 'Unexpected admin banner · ' . self::summarize_varnish_http_body((string) ($hello['body'] ?? '')));
            }

            fwrite($fp, 'ban ' . $expr . "
");
            $resp = self::read_varnish_admin_response($fp);
            fclose($fp);

            if (empty($resp['ok'])) {
                return array('ok' => false, 'detail' => (string) ($resp['body'] ?? 'No admin response.'));
            }

            $detail = 'Admin ' . (int) $resp['code'];
            if (!empty($resp['body'])) {
                $detail .= ' · ' . self::summarize_varnish_http_body($resp['body']);
            }

            return array('ok' => (200 === (int) $resp['code']), 'detail' => $detail, 'code' => (int) $resp['code']);
        }

        private static function varnish_command_for_expr($terminal, $secret, $timeout_s, $expr, $method)
        {
            $settings = self::get_varnish_cli_settings();
            if ('admin' === ($settings['mode'] ?? 'http')) {
                list($host, $port) = self::parse_varnish_terminal($terminal);
                $response = self::send_varnish_admin_ban($host, $port, $secret, $timeout_s, $expr);
                self::varnish_log('ADMIN BAN @ ' . $host . ':' . $port . ' :: ' . trim((string) ($response['detail'] ?? '')));
                return $response;
            }

            $endpoint_check = self::validate_varnish_http_endpoint($terminal);
            if (empty($endpoint_check['valid'])) {
                return array('ok' => false, 'detail' => (string) ($endpoint_check['message'] ?? 'Invalid or blocked Varnish HTTP endpoint.'));
            }

            $endpoint = self::normalize_varnish_endpoint($terminal);
            if (empty($endpoint)) {
                return array('ok' => false, 'detail' => 'Invalid or blocked Varnish HTTP endpoint.');
            }

            $home = wp_parse_url(home_url('/'));
            $site_host = !empty($home['host']) ? (string) $home['host'] : $endpoint['host'];
            $target_url = self::build_varnish_target_url($endpoint, '/');

            $response = self::send_varnish_http_request($endpoint, $target_url, $site_host, $timeout_s, $expr, $method);
            self::varnish_log('HTTP ' . $method . ' @ ' . $target_url . ' :: ' . trim((string) ($response['detail'] ?? '')));

            return $response;
        }

        private static function varnish_send_expr_to_all($expr, $label = '')
        {
            $settings = self::get_varnish_cli_settings();
            $support = $settings['support'];

            if (empty($support['available'])) {
                $result = array(
                    'success' => false,
                    'message' => (string) $support['message'],
                    'time'    => time(),
                    'label'   => $label,
                );
                self::set_varnish_last_result($result);
                return $result;
            }

            if (empty($settings['enabled'])) {
                $result = array(
                    'success' => false,
                    'message' => self::maybe_translate('Varnish integration is disabled.'),
                    'time'    => time(),
                    'label'   => $label,
                );
                self::set_varnish_last_result($result);
                return $result;
            }

            if (empty($settings['servers'])) {
                $result = array(
                    'success' => false,
                    'message' => self::maybe_translate('No Varnish endpoints are configured.'),
                    'time'    => time(),
                    'label'   => $label,
                );
                self::set_varnish_last_result($result);
                return $result;
            }

            $details = array();
            $all_ok = true;
            foreach ($settings['servers'] as $server) {
                $res = self::varnish_command_for_expr($server, $settings['key'], $settings['timeout'], $expr, $settings['method']);
                $all_ok = $all_ok && !empty($res['ok']);
                $details[] = array(
                    'server'  => $server,
                    'success' => !empty($res['ok']),
                    'detail'  => (string) ($res['detail'] ?? ''),
                );
            }

            $action_label = ('admin' === ($settings['mode'] ?? 'http')) ? 'admin BAN' : $settings['method'];
            $message = $all_ok
                ? self::maybe_translate_sprintf('Varnish %1$s succeeded on %2$d endpoint(s).', $action_label, count($details))
                : self::maybe_translate_sprintf('Varnish %s failed on one or more endpoints.', $action_label);

            $result = array(
                'success' => $all_ok,
                'message' => $message,
                'time'    => time(),
                'method'  => $settings['method'],
                'label'   => $label,
                'details' => $details,
            );

            self::set_varnish_last_result($result);
            return $result;
        }

        public static function varnish_flush_all_current_host()
        {
            $home = home_url('/');
            $parsed = wp_parse_url($home);
            $host = $parsed && !empty($parsed['host']) ? $parsed['host'] : '';
            if ('' === $host) {
                $result = array('success' => false, 'message' => self::maybe_translate('Could not determine site host for Varnish.'), 'time' => time());
                self::set_varnish_last_result($result);
                return $result;
            }

            $expr = 'req.http.host == "' . $host . '" && req.url ~ ".*"';
            return self::varnish_send_expr_to_all($expr, 'all');
        }

        public static function varnish_flush_url($url)
        {
            $parsed = ucwp_safe_wp_parse_url((string) $url, -1, 'varnish_flush_url');
            if (!$parsed || empty($parsed['host'])) {
                $result = array('success' => false, 'message' => self::maybe_translate('Invalid URL for Varnish purge.'), 'time' => time(), 'url' => (string) $url);
                self::set_varnish_last_result($result);
                return $result;
            }

            $host = (string) $parsed['host'];
            $path = ((string) ($parsed['path'] ?? '/')) . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
            $settings = self::get_varnish_cli_settings();

            if (empty($settings['enabled'])) {
                $result = array('success' => false, 'message' => self::maybe_translate('Varnish integration is disabled.'), 'time' => time(), 'url' => (string) $url);
                self::set_varnish_last_result($result);
                return $result;
            }

            if (empty($settings['servers'])) {
                $result = array('success' => false, 'message' => self::maybe_translate('No Varnish endpoints are configured.'), 'time' => time(), 'url' => (string) $url);
                self::set_varnish_last_result($result);
                return $result;
            }

            $details = array();
            $all_ok = true;
            foreach ($settings['servers'] as $terminal) {
                $expr = 'req.http.host == "' . $host . '" && req.url ~ "^' . str_replace('"', '\"', $path) . '$"';
                if ('admin' === ($settings['mode'] ?? 'http')) {
                    list($admin_host, $admin_port) = self::parse_varnish_terminal($terminal);
                    $res = self::send_varnish_admin_ban($admin_host, $admin_port, $settings['key'], $settings['timeout'], $expr);
                    self::varnish_log('ADMIN BAN @ ' . $admin_host . ':' . $admin_port . ' :: ' . trim((string) ($res['detail'] ?? '')));
                    $details[] = array('server' => $terminal, 'success' => !empty($res['ok']), 'detail' => (string) ($res['detail'] ?? ''));
                    if (empty($res['ok'])) {
                        $all_ok = false;
                    }
                    continue;
                }

                $endpoint_check = self::validate_varnish_http_endpoint($terminal);
                if (empty($endpoint_check['valid'])) {
                    $details[] = array('server' => $terminal, 'success' => false, 'detail' => (string) ($endpoint_check['message'] ?? 'Invalid or blocked Varnish HTTP endpoint.'));
                    $all_ok = false;
                    continue;
                }

                $endpoint = self::normalize_varnish_endpoint($terminal);
                if (empty($endpoint)) {
                    $details[] = array('server' => $terminal, 'success' => false, 'detail' => 'Invalid or blocked Varnish HTTP endpoint.');
                    $all_ok = false;
                    continue;
                }

                $target_url = self::build_varnish_target_url($endpoint, $path);
                $res = self::send_varnish_http_request($endpoint, $target_url, $host, $settings['timeout'], $expr, $settings['method']);
                self::varnish_log('HTTP ' . $settings['method'] . ' @ ' . $target_url . ' :: ' . trim((string) ($res['detail'] ?? '')));
                $details[] = array('server' => $terminal, 'success' => !empty($res['ok']), 'detail' => (string) ($res['detail'] ?? ''));
                if (empty($res['ok'])) {
                    $all_ok = false;
                }
            }

            $result = array(
                'success' => $all_ok,
                'message' => $all_ok ? 'Varnish ' . (('admin' === ($settings['mode'] ?? 'http')) ? 'admin BAN' : $settings['method']) . ' succeeded on ' . count($details) . ' endpoint(s).' : 'Varnish ' . (('admin' === ($settings['mode'] ?? 'http')) ? 'admin BAN' : $settings['method']) . ' failed on one or more endpoints.',
                'time'    => time(),
                'method'  => $settings['method'],
                'label'   => $path,
                'details' => $details,
            );
            self::set_varnish_last_result($result);
            return $result;
        }

        public static function varnish_test_connection()
        {
            $home = home_url('/');
            $parsed = wp_parse_url($home);
            $host = $parsed && !empty($parsed['host']) ? $parsed['host'] : '';
            if ('' === $host) {
                $result = array('success' => false, 'message' => self::maybe_translate('Could not determine site host for Varnish test.'), 'time' => time());
                self::set_varnish_last_result($result);
                return $result;
            }

            $expr = 'req.http.host == "' . $host . '" && req.url ~ "^/$"';
            return self::varnish_send_expr_to_all($expr, '/');
        }

        private static function get_reverse_proxy_status()
        {
            $cached = get_transient('ucwp_reverse_proxy_status_v2');
            if (is_array($cached)) {
                return $cached;
            }

            $status = array(
                'detected'           => false,
                'varnish'            => false,
                'nginx_cache'        => false,
                'litespeed_cache'    => false,
                'server_cache'       => false,
                'provider'           => '',
                'providers'          => array(),
                'via'                => '',
                'x_varnish'          => '',
                'x_cache'            => '',
                'x_cache_status'     => '',
                'x_proxy_cache'      => '',
                'x_fastcgi_cache'    => '',
                'x_litespeed_cache'  => '',
                'x_qc_cache'         => '',
                'cf_cache_status'    => '',
                'age'                => '',
                'server'             => '',
                'message'            => '',
            );

            $response = ucwp_safe_loopback_remote_request(home_url('/'), array(
                'method'      => 'HEAD',
                'timeout'     => 5,
                'redirection' => 2,
                'headers'     => array(
                    'Cache-Control' => 'no-cache',
                    'Pragma'        => 'no-cache',
                ),
            ), 'reverse_proxy_status');

            if (!is_wp_error($response)) {
                $headers = wp_remote_retrieve_headers($response);
                $status['via']               = trim((string) ($headers['via'] ?? ''));
                $status['x_varnish']         = trim((string) ($headers['x-varnish'] ?? ''));
                $status['x_cache']           = trim((string) ($headers['x-cache'] ?? ''));
                $status['x_cache_status']    = trim((string) ($headers['x-cache-status'] ?? ''));
                $status['x_proxy_cache']     = trim((string) ($headers['x-proxy-cache'] ?? ''));
                $status['x_fastcgi_cache']   = trim((string) ($headers['x-fastcgi-cache'] ?? ''));
                $status['x_litespeed_cache'] = trim((string) ($headers['x-litespeed-cache'] ?? ''));
                $status['x_qc_cache']        = trim((string) ($headers['x-qc-cache'] ?? ''));
                $status['cf_cache_status']   = trim((string) ($headers['cf-cache-status'] ?? ''));
                $status['age']               = trim((string) ($headers['age'] ?? ''));
                $status['server']            = trim((string) ($headers['server'] ?? ''));

                $via_lower             = strtolower($status['via']);
                $x_cache_lower         = strtolower($status['x_cache']);
                $x_cache_status_lower  = strtolower($status['x_cache_status']);
                $x_proxy_cache_lower   = strtolower($status['x_proxy_cache']);
                $x_fastcgi_cache_lower = strtolower($status['x_fastcgi_cache']);
                $x_litespeed_lower     = strtolower($status['x_litespeed_cache']);
                $x_qc_cache_lower      = strtolower($status['x_qc_cache']);
                $cf_cache_lower        = strtolower($status['cf_cache_status']);
                $server_lower          = strtolower($status['server']);

                $status['varnish'] = ('' !== $status['x_varnish']) || (false !== strpos($via_lower, 'varnish'));
                $status['nginx_cache'] = ('' !== $status['x_fastcgi_cache'])
                    || ('' !== $status['x_proxy_cache'])
                    || ('' !== $status['x_cache_status'])
                    || ((false !== strpos($server_lower, 'nginx')) && (preg_match('/(hit|miss|bypass|expired|stale|updating|revalidated)/i', $status['x_cache']) || preg_match('/(hit|miss|bypass|expired|stale|updating|revalidated)/i', $status['x_cache_status'])));
                $status['litespeed_cache'] = ('' !== $status['x_litespeed_cache'])
                    || ('' !== $status['x_qc_cache'])
                    || (false !== strpos($server_lower, 'litespeed'));

                $providers = array();
                if ($status['varnish']) {
                    $providers[] = 'Varnish';
                }
                if ($status['nginx_cache']) {
                    $providers[] = 'Nginx Cache';
                }
                if ($status['litespeed_cache']) {
                    $providers[] = 'LiteSpeed Cache';
                }
                if ('' !== $status['cf_cache_status']) {
                    $providers[] = 'Cloudflare Cache';
                }
                if (!$providers && ('' !== $status['via'] || '' !== $status['x_cache'] || '' !== $status['age'])) {
                    $providers[] = 'Reverse Proxy Cache';
                }

                $status['providers'] = array_values(array_unique($providers));
                $status['provider'] = !empty($status['providers']) ? implode(' + ', $status['providers']) : '';
                $status['server_cache'] = !empty($status['providers']);
                $status['detected'] = $status['server_cache'];

                if ($status['detected']) {
                    $provider_label = $status['provider'] ? $status['provider'] : self::maybe_translate('Reverse Proxy Cache');
                    $status['message'] = self::maybe_translate_sprintf(
                        '%s detected. UltraCache hit counters reflect only requests that reach PHP/advanced-cache and may under-report public hits served before WordPress.',
                        $provider_label
                    );
                }
            }

            set_transient('ucwp_reverse_proxy_status_v2', $status, MINUTE_IN_SECONDS);
            return $status;
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
        public static function flush_object_cache()
        {
            if (!class_exists('Ultra_Cache_Object_Cache_Manager') || (!method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache') && !method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache_with_report'))) {
                return array(
                    'success' => false,
                    'message' => self::maybe_translate('Object cache helper not available.'),
                );
            }

            $report = array(
                'success' => false,
                'message' => 'Object cache flush failed.',
            );

            try {
                if (function_exists('wp_suspend_cache_addition')) {
                    wp_suspend_cache_addition(true);
                }

                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'flush_cache_with_report')) {
                    $report = Ultra_Cache_Object_Cache_Manager::flush_cache_with_report(true, false);
                } else {
                    $flushed = (bool) Ultra_Cache_Object_Cache_Manager::flush_cache(true, false);
                    $report = array(
                        'success' => $flushed,
                        'message' => $flushed ? 'Object cache flushed.' : 'Object cache flush failed.',
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
                'message' => 'Object cache flush failed.',
            );
        }


        private static function get_google_fonts_cache_diagnostics()
        {
            $fallback = array(
                'enabled' => false,
                'built' => false,
                'cssFiles' => 0,
                'fontFiles' => 0,
                'totalFiles' => 0,
                'bytes' => 0,
                'lastBuilt' => 0,
                'message' => 'Google Fonts cache status unavailable.',
            );

            if (!class_exists('Ultra_Cache_Engine')) {
                return $fallback;
            }

            try {
                $engine = new Ultra_Cache_Engine();
                if (method_exists($engine, 'get_google_fonts_cache_summary')) {
                    $summary = $engine->get_google_fonts_cache_summary();
                    return is_array($summary) ? array_merge($fallback, $summary) : $fallback;
                }
            } catch (Throwable $e) {
                $fallback['message'] = $e->getMessage();
            } catch (Exception $e) {
                $fallback['message'] = $e->getMessage();
            }

            return $fallback;
        }

        public static function get_dashboard_diagnostics()
        {
            $settings             = self::get_dashboard_settings();
            $support              = self::get_media_support_status();
            $compression          = self::get_compression_support_status();
            $last                 = get_transient('ucwp_last_cache_event');
            $advanced_cache_path  = trailingslashit(WP_CONTENT_DIR) . 'advanced-cache.php';
            $object_cache_path    = trailingslashit(WP_CONTENT_DIR) . 'object-cache.php';
            $runtime_config_path  = self::get_runtime_config_path();
            $analytics_path       = trailingslashit(UCWP_CACHE_DIR) . 'analytics.json';
            $browser_cache_path   = self::get_browser_cache_htaccess_path();
            $object_cache_support  = self::get_object_cache_support_status();
            $object_backend_status = array();
            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'get_backend_status')) {
                $object_backend_status = Ultra_Cache_Object_Cache_Manager::get_backend_status();
            }
            if (!is_array($object_backend_status)) {
                $object_backend_status = array();
            }
            $selected_object_backend = isset($object_backend_status['selected']) ? self::sanitize_object_cache_backend($object_backend_status['selected']) : self::sanitize_object_cache_backend($settings['objectCacheBackend']);
            $active_object_backend = isset($object_backend_status['active']) ? strtolower(trim((string) $object_backend_status['active'])) : $selected_object_backend;
            if (!in_array($active_object_backend, array('redis', 'apcu', 'disk', 'runtime'), true)) {
                $active_object_backend = $selected_object_backend;
            }
            $fallback_object_backend = isset($object_backend_status['fallback']) ? strtolower(trim((string) $object_backend_status['fallback'])) : (!empty($object_cache_support['apcu']['available']) ? 'apcu' : 'runtime');
            if (!in_array($fallback_object_backend, array('apcu', 'runtime'), true)) {
                $fallback_object_backend = !empty($object_cache_support['apcu']['available']) ? 'apcu' : 'runtime';
            }
            $selected_object_backend_supported = true;
            if ('redis' === $selected_object_backend) {
                $selected_object_backend_supported = !empty(self::get_redis_support_status()['available']);
            } elseif ('apcu' === $selected_object_backend) {
                $selected_object_backend_supported = !empty($object_cache_support['apcu']['available']);
            }

            return array(
                'pageCache' => array(
                    'enabled' => !empty($settings['pageCacheEnabled']),
                    'active'  => (bool) (defined('WP_CACHE') && WP_CACHE && file_exists($advanced_cache_path)),
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
                                && file_exists($object_cache_path))
                        ),
                        'selectedBackend' => $selected_object_backend,
                        'fallbackBackend' => $fallback_object_backend,
                        'fallbackActive'  => !empty($object_backend_status['fallbackActive']),
                        'fallbackReason'  => (string) ($object_backend_status['fallbackReason'] ?? ''),
                        'fallbackMessage' => (string) ($object_backend_status['fallbackMessage'] ?? ''),
                        'activeBackend'   => $active_object_backend,
                        'selectedBackendSupported' => (bool) $selected_object_backend_supported,
                        'activeBackendPersistent' => 'redis' === $active_object_backend,
                        'activeBackendRuntimeOnly' => 'runtime' === $active_object_backend,
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
                                'connectTimeoutMs' => self::sanitize_bounded_integer_setting($settings['redisConnectTimeoutMs'], 200, 50, 5000),
                                'readTimeoutMs'    => self::sanitize_bounded_integer_setting($settings['redisReadTimeoutMs'], 200, 50, 5000),
                            ),
                            isset($object_backend_status['redis']) && is_array($object_backend_status['redis'])
                                ? array(
                                    'dropinEnabled' => !empty($object_backend_status['redis']['enabled']),
                                    'dropinError' => (string) ($object_backend_status['redis']['error'] ?? ''),
                                )
                                : array(),
                            (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'test_redis_connection'))
                                ? Ultra_Cache_Object_Cache_Manager::test_redis_connection()
                                : array(),
                            (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'test_runtime_object_cache_payloads'))
                                ? array('payloadProbe' => Ultra_Cache_Object_Cache_Manager::test_runtime_object_cache_payloads())
                                : array(),
                            (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'get_last_flush_report'))
                                ? array('lastFlush' => Ultra_Cache_Object_Cache_Manager::get_last_flush_report())
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
                'browserCache' => array(
                    'enabled' => !empty($settings['browserCacheRulesEnabled']),
                    'path'    => $browser_cache_path,
                    'active'  => file_exists($browser_cache_path) && false !== strpos((string) ucwp_safe_file_get_contents($browser_cache_path, 'dashboard diagnostics'), '# BEGIN UltraCache Browser Cache'),
                ),
                'varnish' => array_merge(
                    self::get_varnish_support_status(),
                    array(
                        'enabled' => !empty($settings['varnishCliEnabled']),
                        'mode'    => self::sanitize_varnish_mode($settings['varnishCliMode']),
                        'servers' => self::sanitize_varnish_servers_string($settings['varnishCliServers'], self::sanitize_varnish_mode($settings['varnishCliMode'])),
                        'method'  => ('PURGE' === strtoupper(trim((string) $settings['varnishCliMethod']))) ? 'PURGE' : 'BAN',
                        'timeout' => max(1, min(30, absint($settings['varnishCliTimeoutSeconds']))),
                        'last'    => self::get_varnish_last_result(),
                        'endpointDiagnostics' => self::get_varnish_endpoint_diagnostics($settings['varnishCliServers'], self::sanitize_varnish_mode($settings['varnishCliMode'])),
                        'hasUnsafeEndpoints' => !empty(self::get_varnish_endpoint_diagnostics($settings['varnishCliServers'], self::sanitize_varnish_mode($settings['varnishCliMode']))['unsafe']),
                        'unsafeEndpointMessage' => !empty(self::get_varnish_endpoint_diagnostics($settings['varnishCliServers'], self::sanitize_varnish_mode($settings['varnishCliMode']))['messages'][0]) ? (string) self::get_varnish_endpoint_diagnostics($settings['varnishCliServers'], self::sanitize_varnish_mode($settings['varnishCliMode']))['messages'][0] : '',
                    )
                ),
                'reverseProxy' => self::get_reverse_proxy_status(),
                'loopbackSsl' => ucwp_get_loopback_ssl_status(),
                'legacyCacheConflicts' => self::get_legacy_cache_conflict_status(),
                'analytics' => self::get_analytics_hit_backend_diagnostic($settings),
                'environment' => self::get_advanced_environment_diagnostic(),
                'mediaRuntime' => self::get_media_runtime_diagnostic(),
                'cronWarm' => self::get_cron_warm_status(),
                'paths' => array(
                    'cacheDir'          => self::get_path_diagnostic(UCWP_CACHE_DIR, 'dir'),
                    'objectCacheDir'    => self::get_path_diagnostic(UCWP_OBJECT_CACHE_DIR, 'dir'),
                    'avifDir'           => self::get_path_diagnostic(UCWP_AVIF_DIR, 'dir'),
                    'webpDir'           => self::get_path_diagnostic(UCWP_WEBP_DIR, 'dir'),
                    'advancedCache'     => self::get_path_diagnostic($advanced_cache_path, 'file', 'UltraCache advanced-cache drop-in'),
                    'objectCache'       => self::get_path_diagnostic($object_cache_path, 'file', 'UltraCache generated object-cache drop-in'),
                    'runtimeConfig'     => self::get_runtime_config_diagnostic($runtime_config_path),
                    'analytics'         => self::get_analytics_diagnostic($analytics_path),
                    'browserCacheRules' => self::get_path_diagnostic($browser_cache_path, 'file', '# BEGIN UltraCache Browser Cache'),
                ),
                'lastCacheWrite' => self::get_page_cache_activity_snapshot(),
                'lastEvent' => self::normalize_last_cache_event($last),
            );
        }

        private static function get_path_diagnostic($path, $type = 'file', $managed_marker = '')
        {
            $exists          = file_exists($path);
            $is_dir          = ('dir' === $type);
            $parent          = dirname($path);
            $modified        = 0;
            $size            = 0;
            $managed         = false;
            $drop_in_build   = '';
            $storage_format  = '';
            $read_error      = '';
            $readable        = $exists ? is_readable($path) : false;
            $writable        = $exists ? ucwp_path_is_writable($path) : ($parent && file_exists($parent) ? ucwp_path_is_writable($parent) : false);
            $parent_writable = ($parent && file_exists($parent)) ? ucwp_path_is_writable($parent) : false;

            if ($exists) {
                $modified = ucwp_safe_filemtime($path, 'path_diagnostic');
                if (!$is_dir) {
                    $size = (int) ucwp_safe_filesize($path, 'path_diagnostic');
                }

                if (!$is_dir && $managed_marker && $readable) {
                    $contents = ucwp_safe_file_get_contents($path, 'dashboard path diagnostic');
                    if (false === $contents) {
                        $read_error = self::maybe_translate('Read failed');
                    } else {
                        $contents_string = (string) $contents;
                        $managed = false !== strpos($contents_string, $managed_marker);

                        if (preg_match('/Drop-in Build:\s*([^\r\n]+)/i', $contents_string, $matches)) {
                            $drop_in_build = trim((string) $matches[1]);
                        }

                        if (preg_match('/Storage format:\s*([^\r\n]+)/i', $contents_string, $matches)) {
                            $storage_format = trim((string) $matches[1]);
                        }
                    }
                }
            }

            return array(
                'path'           => (string) $path,
                'type'           => $is_dir ? 'dir' : 'file',
                'exists'         => (bool) $exists,
                'readable'       => (bool) $readable,
                'writable'       => (bool) $writable,
                'parentWritable' => (bool) $parent_writable,
                'size'           => (int) max(0, (int) $size),
                'modified'       => (int) max(0, (int) $modified),
                'managed'        => (bool) $managed,
                'dropInBuild'    => (string) $drop_in_build,
                'storageFormat'  => (string) $storage_format,
                'readError'      => (string) $read_error,
            );
        }

        private static function redact_runtime_config_for_diagnostics(array $runtime)
        {
            if (isset($runtime['revalidate_secret']) && '' !== (string) $runtime['revalidate_secret']) {
                $runtime['revalidate_secret'] = '[redacted]';
            }

            return $runtime;
        }

        private static function get_runtime_config_diagnostic($path)
        {
            $diag = self::get_path_diagnostic($path, 'file');
            $diag['valid'] = false;
            $diag['keys'] = array();
            $diag['inSync'] = false;
            $diag['loaded'] = array();
            $diag['secretPath'] = self::get_runtime_secret_path();
            $diag['secretStorage'] = 'file_outside_webroot_per_site';
            $diag['secretPresent'] = false;

            $expected_runtime = self::build_runtime_config();
            $expected_public_runtime = $expected_runtime;
            unset($expected_public_runtime['revalidate_secret']);
            $diag['expected'] = $expected_public_runtime;

            if (!empty($diag['exists']) && !empty($diag['readable'])) {
                $raw = ucwp_safe_file_get_contents($path, 'dashboard runtime config raw diagnostic');
                if (false === $raw || '' === $raw) {
                    $diag['readError'] = self::maybe_translate('Read failed');
                } else {
                    $loaded = json_decode($raw, true);
                    if (!is_array($loaded)) {
                        $diag['readError'] = self::maybe_translate('Invalid JSON');
                    } else {
                        $normalized_public = self::normalize_runtime_config(array_merge($expected_public_runtime, $loaded));
                        unset($normalized_public['revalidate_secret']);
                        $diag['valid'] = true;
                        $diag['keys'] = array_values(array_keys($loaded));
                        $diag['loaded'] = $normalized_public;
                        $diag['inSync'] = ($normalized_public === $expected_public_runtime);
                    }
                }
            }

            $secret_runtime = self::load_runtime_secret_file();
            $diag['secretPresent'] = !empty($secret_runtime['revalidate_secret']);

            return $diag;
        }

        private static function get_analytics_diagnostic($path)
        {
            $diag = self::get_path_diagnostic($path, 'file');
            $diag['validJson'] = false;
            $diag['keys']      = array();

            if (!empty($diag['exists']) && !empty($diag['readable'])) {
                $contents = ucwp_safe_file_get_contents($path, 'dashboard analytics diagnostic');
                if (false !== $contents && '' !== $contents) {
                    $decoded = json_decode((string) $contents, true);
                    if (is_array($decoded)) {
                        $diag['validJson'] = true;
                        $diag['keys']      = array_values(array_slice(array_keys($decoded), 0, 12));
                    }
                }
            }

            return $diag;
        }

        private static function get_analytics_hit_backend_diagnostic(array $settings = array())
        {
            if (empty($settings)) {
                $settings = self::get_dashboard_settings();
            }

            $apcu_available = function_exists('apcu_fetch')
                && function_exists('apcu_inc')
                && function_exists('apcu_dec')
                && function_exists('apcu_delete')
                && (!function_exists('apcu_enabled') || apcu_enabled());

            if ($apcu_available) {
                $probe_key = 'ucwp_analytics_probe_' . md5(uniqid('ucwp', true));
                $probe_value = 'ucwp:' . md5($probe_key . '|' . microtime(true));
                $probe_ok = false;
                $probe_message = self::maybe_translate('APCu read/write probe failed.');

                try {
                    $stored = @apcu_store($probe_key, $probe_value, 30);
                    $fetch_success = false;
                    $fetched = @apcu_fetch($probe_key, $fetch_success);
                    @apcu_delete($probe_key);
                    $probe_ok = (bool) $stored && (bool) $fetch_success && ((string) $fetched === (string) $probe_value);
                    if ($probe_ok) {
                        $probe_message = self::maybe_translate('APCu read/write probe passed.');
                    }
                } catch (Throwable $e) {
                    $probe_message = $e->getMessage();
                }

                return array(
                    'enabled' => true,
                    'activeBackend' => 'apcu',
                    'apcuAvailable' => true,
                    'redisAvailable' => class_exists('Redis') || extension_loaded('redis'),
                    'readWrite' => $probe_ok,
                    'probeStatus' => $probe_ok ? 'passed' : 'failed',
                    'message' => $probe_ok ? self::maybe_translate('Realtime cache-hit analytics are stored in APCu. Read/write probe passed.') : $probe_message,
                );
            }

            $redis_selected = !empty($settings['objectCacheEnabled']) && 'redis' === self::sanitize_object_cache_backend($settings['objectCacheBackend']);
            $redis_support = self::get_redis_support_status();
            $redis_available = !empty($redis_support['available']);
            $redis_connected = false;
            $redis_message = !empty($redis_support['message']) ? (string) $redis_support['message'] : '';

            $redis_read_write = false;
            if ($redis_selected && $redis_available && class_exists('Ultra_Cache_Object_Cache_Manager')) {
                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'test_redis_read_write')) {
                    $redis_test = Ultra_Cache_Object_Cache_Manager::test_redis_read_write();
                    $redis_connected = !empty($redis_test['connected']);
                    $redis_read_write = !empty($redis_test['readWrite']);
                    if (empty($redis_test['success']) && !empty($redis_test['message'])) {
                        $redis_message = (string) $redis_test['message'];
                    }
                } elseif (method_exists('Ultra_Cache_Object_Cache_Manager', 'test_redis_connection')) {
                    $redis_test = Ultra_Cache_Object_Cache_Manager::test_redis_connection();
                    $redis_connected = !empty($redis_test['success']);
                    $redis_read_write = $redis_connected;
                    if (!$redis_connected && !empty($redis_test['message'])) {
                        $redis_message = (string) $redis_test['message'];
                    }
                }
            }

            if ($redis_selected && $redis_available && $redis_connected && $redis_read_write) {
                return array(
                    'enabled' => true,
                    'activeBackend' => 'redis',
                    'apcuAvailable' => false,
                    'redisAvailable' => true,
                    'redisSelected' => true,
                    'redisConnected' => true,
                    'readWrite' => true,
                    'probeStatus' => 'passed',
                    'message' => self::maybe_translate('Realtime cache-hit analytics are stored in Redis because APCu is unavailable. Read/write probe passed.'),
                );
            }

            $message = self::maybe_translate('Realtime cache-hit analytics are disabled because APCu is unavailable and Redis is not connected as the active backend.');
            if ($redis_selected && $redis_available && '' !== $redis_message) {
                $message = self::maybe_translate('Realtime cache-hit analytics are disabled because APCu is unavailable and Redis is not connected.') . ' ' . $redis_message;
            } elseif (!$redis_selected && $redis_available) {
                $message = self::maybe_translate('Realtime cache-hit analytics are disabled because APCu is unavailable and Redis is not selected as the object cache backend.');
            }

            return array(
                'enabled' => false,
                'activeBackend' => 'disabled',
                'apcuAvailable' => false,
                'redisAvailable' => $redis_available,
                'redisSelected' => $redis_selected,
                'redisConnected' => $redis_connected,
                'readWrite' => $redis_read_write,
                'probeStatus' => $redis_read_write ? 'passed' : 'failed',
                'message' => $message,
            );
        }

        private static function get_page_cache_activity_snapshot()
        {
            $cache_key = 'ucwp_dashboard_cache_activity_v1';
            $cached    = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }

            $snapshot = array(
                'path'      => '',
                'modified'  => 0,
                'size'      => 0,
                'pageFiles' => 0,
            );

            if (!is_dir(UCWP_CACHE_DIR)) {
                set_transient($cache_key, $snapshot, MINUTE_IN_SECONDS);
                return $snapshot;
            }

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(UCWP_CACHE_DIR, FilesystemIterator::SKIP_DOTS)
                );

                foreach ($iterator as $file_info) {
                    if (!$file_info->isFile()) {
                        continue;
                    }

                    $path = str_replace('\\', '/', (string) $file_info->getPathname());
                    $name = strtolower((string) $file_info->getFilename());

                    if (false !== strpos($path, '/font-css/')) {
                        continue;
                    }

                    if (in_array($name, array('index.php', 'runtime-config.json', 'analytics.json'), true)) {
                        continue;
                    }

                    if (!preg_match('/\.html(?:\.(?:gz|br))?$/', $name)) {
                        continue;
                    }

                    $snapshot['pageFiles']++;
                    $mtime = (int) $file_info->getMTime();
                    if ($mtime > $snapshot['modified']) {
                        $snapshot['modified'] = $mtime;
                        $snapshot['path']     = $path;
                        $snapshot['size']     = (int) $file_info->getSize();
                    }
                }
            } catch (Exception $e) {
                $snapshot['error'] = (string) $e->getMessage();
            }

            set_transient($cache_key, $snapshot, MINUTE_IN_SECONDS);
            return $snapshot;
        }

        private static function get_object_cache_support_status($allow_live_check = true)
        {
            $cache_key = 'ucwp_object_cache_support_status_v1';
            $allow_live_check = (bool) $allow_live_check;

            if (!$allow_live_check) {
                $cached = get_transient($cache_key);
                if (is_array($cached)) {
                    $cached['source'] = 'cached';
                    return $cached;
                }

                $light = self::get_object_cache_support_status_light();
                $light['source'] = 'light_frontend_default';
                return $light;
            }

            $dropin_installable = true;
            $message   = '';

            if (class_exists('Ultra_Cache_Object_Cache_Manager')) {
                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'supports_dropin')) {
                    $dropin_installable = (bool) Ultra_Cache_Object_Cache_Manager::supports_dropin();
                }

                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'get_unavailable_reason')) {
                    $message = (string) Ultra_Cache_Object_Cache_Manager::get_unavailable_reason();
                }
            }

            $status = self::get_object_cache_support_status_light();
            $status['available'] = $dropin_installable;
            $status['dropinInstallable'] = $dropin_installable;
            $status['message'] = $message;
            $status['source'] = 'live';

            set_transient($cache_key, $status, 5 * MINUTE_IN_SECONDS);

            return $status;
        }

        private static function get_object_cache_support_status_light()
        {
            $apcu_available  = function_exists('apcu_fetch') && function_exists('apcu_store') && (!function_exists('apcu_enabled') || apcu_enabled());
            $redis_available = (bool) (class_exists('Redis') || extension_loaded('redis'));
            $dropin_installable = class_exists('Ultra_Cache_Object_Cache_Manager');

            return array(
                // Kept for compatibility with the dashboard JS. This means the drop-in can be installed, not that Redis is connected.
                'available'                  => $dropin_installable,
                'dropinInstallable'          => $dropin_installable,
                'persistentBackendAvailable' => $redis_available,
                'localBackendAvailable'      => $apcu_available,
                'message'                    => $dropin_installable ? '' : self::maybe_translate('Object cache helper not available.'),
                'apcu'      => array(
                    'available' => $apcu_available,
                    'message'   => $apcu_available ? '' : self::maybe_translate('APCu extension is not loaded or not enabled.'),
                ),
                'source' => 'light',
            );
        }


        private static function normalize_last_cache_event($last)
        {
            if (!is_array($last)) {
                return array();
            }

            $time = 0;
            if (isset($last['time']) && is_numeric($last['time'])) {
                $time = (int) $last['time'];
            } elseif (!empty($last['time'])) {
                $time = (int) strtotime((string) $last['time']);
            } elseif (!empty($last['time_mysql'])) {
                $time = (int) strtotime((string) $last['time_mysql']);
            }

            if (empty($last['time_mysql']) && !empty($last['time']) && !is_numeric($last['time'])) {
                $last['time_mysql'] = (string) $last['time'];
            }

            $bucket = '';
            if (!empty($last['bucket'])) {
                $bucket = (string) $last['bucket'];
            } elseif (!empty($last['payload']['bucket'])) {
                $bucket = (string) $last['payload']['bucket'];
            } else {
                $paths = array();
                if (!empty($last['file'])) {
                    $paths[] = (string) $last['file'];
                }
                if (!empty($last['files']) && is_array($last['files'])) {
                    $paths = array_merge($paths, array_map('strval', $last['files']));
                }

                foreach ($paths as $path) {
                    if (false !== strpos($path, 'index-avif-')) {
                        $bucket = 'avif';
                        break;
                    }
                    if (false !== strpos($path, 'index-webp-')) {
                        $bucket = 'webp';
                        break;
                    }
                    if (false !== strpos($path, 'index-orig-')) {
                        $bucket = 'orig';
                        break;
                    }
                }
            }

            $last['status'] = !empty($last['status']) ? (string) $last['status'] : (!empty($last['type']) ? (string) $last['type'] : '');
            $last['bucket'] = $bucket;
            $last['time']   = $time > 0 ? $time : 0;

            return $last;
        }

        private static function get_mysql_query_cache_size()
        {
            global $wpdb;

            if (!($wpdb instanceof wpdb)) {
                return '';
            }

            $value = '';
            $result = $wpdb->get_var("SHOW VARIABLES LIKE 'query_cache_size'", 1);
            if (null === $result || false === $result || '' === $result) {
                $result = $wpdb->get_var("SELECT @@query_cache_size");
            }
            if (null === $result || false === $result) {
                return '';
            }
            return (string) $result;
        }

        private static function get_mysql_max_allowed_packet_size()
        {
            global $wpdb;
            if (!($wpdb instanceof wpdb)) {
                return '';
            }

            $result = $wpdb->get_var("SHOW VARIABLES LIKE 'max_allowed_packet'", 1);
            if (null === $result || false === $result || '' === $result) {
                $result = $wpdb->get_var("SELECT @@max_allowed_packet");
            }
            if (null === $result || false === $result) {
                return '';
            }
            return (string) $result;
        }

        private static function get_advanced_environment_diagnostic()
        {
            $host = function_exists('gethostname') ? (string) @gethostname() : '';

            $site_url = function_exists('home_url') ? (string) home_url('/') : '';
            $site_parts = $site_url ? wp_parse_url($site_url) : array();
            $default_port = (!empty($site_parts['scheme']) && 'http' === strtolower((string) $site_parts['scheme'])) ? '80' : '443';
            $server_addr = (string) ucwp_server_value('SERVER_ADDR');
            $server_port = (string) ucwp_server_value('SERVER_PORT');

            if ('' === $server_addr && !empty($site_parts['host'])) {
                $resolved = @gethostbyname((string) $site_parts['host']);
                if (is_string($resolved) && '' !== $resolved) {
                    $server_addr = $resolved;
                }
            }

            if ('' === $server_port) {
                if (!empty($site_parts['port'])) {
                    $server_port = (string) $site_parts['port'];
                } else {
                    $server_port = $default_port;
                }
            }

            $ip_port = $server_addr;
            if ('' !== $server_addr && '' !== $server_port) {
                $ip_port .= ':' . $server_port;
            }

            $document_root = (string) ucwp_server_value('DOCUMENT_ROOT');
            if ('' === $document_root && defined('ABSPATH')) {
                $document_root = untrailingslashit((string) ABSPATH);
            }

            $query_cache_raw = self::get_mysql_query_cache_size();
            $query_cache_size = '';
            if ('' !== $query_cache_raw && is_numeric($query_cache_raw)) {
                $query_cache_size = size_format((int) $query_cache_raw);
            } elseif ('' !== $query_cache_raw) {
                $query_cache_size = (string) $query_cache_raw;
            }

            $max_packet_raw = self::get_mysql_max_allowed_packet_size();
            $max_packet_size = '';
            if ('' !== $max_packet_raw && is_numeric($max_packet_raw)) {
                $max_packet_size = size_format((int) $max_packet_raw);
            } elseif ('' !== $max_packet_raw) {
                $max_packet_size = (string) $max_packet_raw;
            }

            return array(
                'serverHostname' => $host,
                'originIpPort' => $ip_port,
                'serverDocumentRoot' => $document_root,
                'phpVersion' => (string) PHP_VERSION,
                'phpSapi' => function_exists('php_sapi_name') ? (string) php_sapi_name() : '',
                'phpMaxExecutionTime' => (string) ini_get('max_execution_time'),
                'phpMemoryLimit' => (string) ini_get('memory_limit'),
                'phpMaxUploadSize' => (string) ini_get('upload_max_filesize'),
                'phpMaxPostSize' => (string) ini_get('post_max_size'),
                'phpMaxInputVars' => (string) ini_get('max_input_vars'),
                'wpMemoryLimit' => defined('WP_MEMORY_LIMIT') ? (string) WP_MEMORY_LIMIT : '',
                'mysqlQueryCacheSize' => $query_cache_size,
                'mysqlMaxAllowedPacket' => $max_packet_size,
            );
        }

        private static function get_media_runtime_diagnostic()

        {
            $support = self::get_media_support_status();
            return array(
                'preferredEditor' => (string) ($support['preferred_editor'] ?? ''),
                'lastImageEditorClass' => (string) ($support['last_image_editor_class'] ?? ''),
                'lastAvifEncodeEngine' => (string) ($support['last_avif_encode_engine'] ?? ''),
                'lastAvifEncodeError' => (string) ($support['last_avif_encode_error'] ?? ''),
                'lastAvifEncodeFile' => (string) ($support['last_avif_encode_file'] ?? ''),
                'lastAvifEncodeAt' => (int) ($support['last_avif_encode_at'] ?? 0),
                'gdAvif' => !empty($support['gd_avif']),
                'gdWebp' => !empty($support['gd_webp']),
                'imagickAvif' => !empty($support['imagick_avif']),
                'imagickWebp' => !empty($support['imagick_webp']),
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

        private static function get_current_url_without_plugin_args()
        {
            if (empty($_SERVER['HTTP_HOST']) || empty($_SERVER['REQUEST_URI'])) {
                return '';
            }

            $is_ssl = ucwp_server_flag_enabled('HTTPS')
                || ('443' === ucwp_server_value('SERVER_PORT'));
            $scheme = $is_ssl ? 'https://' : 'http://';
            $host = ucwp_get_validated_http_host(ucwp_server_value('HTTP_HOST'), 'plugin_current_url');
            if ('' === $host) {
                return '';
            }

            $url = $scheme . $host . ucwp_server_value('REQUEST_URI');

            return esc_url_raw(remove_query_arg(array('ucwp_action', '_wpnonce'), $url));
        }
    }
}

if (!function_exists('ucwp_ultracache')) {
    function ucwp_ultracache()
    {
        return Ultra_Cache_WP::instance();
    }
}

register_activation_hook(__FILE__, array('Ultra_Cache_WP', 'activate'));
register_deactivation_hook(__FILE__, array('Ultra_Cache_WP', 'deactivate'));
ucwp_ultracache();
