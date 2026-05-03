<?php
/**
 * Core procedural helpers for UltraCache.
 *
 * These functions are loaded by ultracache.php before the main plugin class
 * and before the engine/REST/WP-CLI components. They intentionally keep the
 * existing ucwp_* function names for compatibility with the rest of the
 * codebase and generated drop-in helpers.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('ucwp_is_sensitive_debug_key')) {
    function ucwp_is_sensitive_debug_key($key)
    {
        $key = strtolower((string) $key);
        if ('' === $key) {
            return false;
        }

        return 1 === preg_match('/(?:^key$|password|passwd|pwd|secret|token|authorization|cookie|nonce|auth|credential|security|redis[_-]?password|varnish.*key|varnish.*secret|api[_-]?key|access[_-]?key|private[_-]?key|order[_-]?key|client[_-]?secret)/i', $key);
    }
}

if (!function_exists('ucwp_redact_sensitive_string')) {
    function ucwp_redact_sensitive_string($value)
    {
        $value = (string) $value;

        $json_key_pattern = '(?:redis_password|redisPassword|varnish_admin_secret|varnishCliKey|password|passwd|pwd|secret|token|authorization|cookie|nonce|auth|credential|security|api[_-]?key|access[_-]?key|private[_-]?key|order[_-]?key|client[_-]?secret|key)';
        $value = preg_replace('/((?:"|\')' . $json_key_pattern . '(?:"|\')\s*:\s*(?:"|\'))[^"\']*((?:"|\'))/i', '$1[redacted]$2', $value);
        $value = preg_replace('/(' . $json_key_pattern . '\s*[=:]\s*)[^,\s&}\]]+/i', '$1[redacted]', $value);
        $value = preg_replace('/((?:password|passwd|pwd|secret|token|nonce|auth|credential|security|key)=)([^&\s]+)/i', '$1[redacted]', $value);

        if (preg_match('/(?:bearer\s+|basic\s+)[a-z0-9._~+\/=:-]+/i', $value)) {
            $value = preg_replace('/(?:bearer\s+|basic\s+)[a-z0-9._~+\/=:-]+/i', '[redacted]', $value);
        }

        return $value;
    }
}

if (!function_exists('ucwp_redact_sensitive_debug_value')) {
    function ucwp_redact_sensitive_debug_value($key, $value, $depth = 0)
    {
        if (ucwp_is_sensitive_debug_key($key)) {
            return '[redacted]';
        }

        if ($depth > 8) {
            return is_scalar($value) || null === $value ? ucwp_redact_sensitive_string((string) $value) : '[truncated]';
        }

        if (is_array($value)) {
            $redacted = array();
            foreach ($value as $child_key => $child_value) {
                $redacted[$child_key] = ucwp_redact_sensitive_debug_value($child_key, $child_value, $depth + 1);
            }
            return $redacted;
        }

        if (is_string($value)) {
            return ucwp_redact_sensitive_string($value);
        }

        return $value;
    }
}

if (!function_exists('ucwp_redact_sensitive_debug_context')) {
    function ucwp_redact_sensitive_debug_context(array $context)
    {
        $redacted = array();
        foreach ($context as $key => $value) {
            $redacted[$key] = ucwp_redact_sensitive_debug_value($key, $value, 0);
        }
        return $redacted;
    }
}

if (!function_exists('ucwp_debug_log')) {
    function ucwp_debug_log($message, array $context = array())
    {
        /**
         * Fires when UltraCache emits a debug event. Sensitive values are redacted before hooks receive the context.
         *
         * @param string $message Debug message.
         * @param array  $context Context data.
         */
        if (function_exists('ucwp_redact_sensitive_debug_context')) {
            $context = ucwp_redact_sensitive_debug_context($context);
        }
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
            'maybe_start_buffering_after_reentry_check' => true,
            'maybe_start_buffering_before_should_bypass' => true,
            'maybe_start_buffering_after_should_bypass' => true,
            'bypass_selected' => true,
            'should_bypass_return' => true,
            'early_hit_check_start' => true,
            'early_hit_check_end' => true,
            'early_hit_no_file_return' => true,
            'early_hit_served' => true,
            'page_generation_lock_before' => true,
            'page_generation_lock_before_current_url' => true,
            'page_generation_lock_after_current_url' => true,
            'page_generation_lock_before_cache_path' => true,
            'page_generation_lock_after_cache_path' => true,
            'page_generation_lock_acquire_start' => true,
            'page_generation_lock_acquired' => true,
            'page_generation_lock_checked' => true,
            'record_analytics_miss_start' => true,
            'record_analytics_miss_end' => true,
            'send_debug_headers_start' => true,
            'send_debug_headers_end' => true,
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

        if (!ucwp_is_allowed_writable_path($path, $context)) {
            ucwp_debug_log('file_put_contents blocked: path outside allowed write roots', array('path' => $path, 'context' => $context));
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

if (!function_exists('ucwp_normalize_filesystem_path_for_guard')) {
    function ucwp_normalize_filesystem_path_for_guard($path)
    {
        $path = is_string($path) ? trim($path) : '';
        if ('' === $path) {
            return '';
        }

        $real = realpath($path);
        if (false !== $real) {
            return str_replace('\\', '/', $real);
        }

        $dir = dirname($path);
        $base = basename($path);
        $dir_real = realpath($dir);
        if (false !== $dir_real) {
            return rtrim(str_replace('\\', '/', $dir_real), '/') . '/' . $base;
        }

        return str_replace('\\', '/', $path);
    }
}

if (!function_exists('ucwp_path_has_dir_prefix')) {
    function ucwp_path_has_dir_prefix($path, $dir)
    {
        $path = ucwp_normalize_filesystem_path_for_guard($path);
        $dir = ucwp_normalize_filesystem_path_for_guard($dir);
        if ('' === $path || '' === $dir) {
            return false;
        }

        $dir = rtrim($dir, '/') . '/';
        return 0 === strpos($path, $dir) || rtrim($path, '/') === rtrim($dir, '/');
    }
}

if (!function_exists('ucwp_is_allowed_destructive_path')) {
    function ucwp_is_allowed_destructive_path($path, $context = '')
    {
        $normalized = ucwp_normalize_filesystem_path_for_guard($path);
        if ('' === $normalized) {
            return false;
        }

        $allowed_dirs = array();
        foreach (array('UCWP_CACHE_DIR', 'UCWP_AVIF_DIR', 'UCWP_WEBP_DIR', 'UCWP_OBJECT_CACHE_DIR') as $constant) {
            if (defined($constant)) {
                $allowed_dirs[] = constant($constant);
            }
        }

        foreach ($allowed_dirs as $dir) {
            if (ucwp_path_has_dir_prefix($normalized, $dir)) {
                return true;
            }
        }

        $allowed_files = array();
        if (defined('WP_CONTENT_DIR')) {
            $allowed_files[] = trailingslashit(WP_CONTENT_DIR) . 'advanced-cache.php';
            $allowed_files[] = trailingslashit(WP_CONTENT_DIR) . 'object-cache.php';
            $allowed_files[] = trailingslashit(WP_CONTENT_DIR) . 'ultracache-runtime-secrets.php';
            $allowed_files[] = trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache-runtime-secrets.php';
        }

        foreach ($allowed_files as $file) {
            if ($normalized === ucwp_normalize_filesystem_path_for_guard($file)) {
                return true;
            }
        }

        $base = basename($normalized);
        $dir = dirname($normalized);

        if (preg_match('/^wp-config-backup-\\d{8}-\\d{6}-[A-Za-z0-9]+\\.php$/', $base) && file_exists($dir . '/wp-config.php')) {
            return true;
        }

        if (preg_match('/^wp-config\\.php\\.tmp-[A-Za-z0-9_.]+$/', $base) && file_exists($dir . '/wp-config.php')) {
            return true;
        }

        if (false !== strpos((string) $context, 'runtime') && preg_match('/^(?:\\.[A-Za-z0-9_.-]+-)?ultracache-runtime-secrets\\.php(?:\\.tmp-[A-Za-z0-9_.-]+)?$/', $base)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('ucwp_is_allowed_writable_path')) {
    function ucwp_is_allowed_writable_path($path, $context = '')
    {
        $normalized = ucwp_normalize_filesystem_path_for_guard($path);
        $context = (string) $context;
        if ('' === $normalized) {
            return false;
        }

        $allowed_dirs = array();
        foreach (array('UCWP_CACHE_DIR', 'UCWP_OPTIMIZED_IMAGES_DIR', 'UCWP_AVIF_DIR', 'UCWP_WEBP_DIR', 'UCWP_OBJECT_CACHE_DIR') as $constant) {
            if (defined($constant)) {
                $allowed_dirs[] = constant($constant);
            }
        }

        foreach ($allowed_dirs as $dir) {
            if (ucwp_path_has_dir_prefix($normalized, $dir)) {
                return true;
            }
        }

        $base = basename($normalized);
        $dir = dirname($normalized);

        if (defined('WP_CONTENT_DIR')) {
            $content_dir = wp_normalize_path(WP_CONTENT_DIR);
            foreach (array('advanced-cache.php', 'object-cache.php', 'ultracache-runtime-secrets.php') as $managed_file) {
                $target = trailingslashit($content_dir) . $managed_file;
                if ($normalized === ucwp_normalize_filesystem_path_for_guard($target)) {
                    return true;
                }
                if (0 === strpos($base, $managed_file . '.tmp-') && ucwp_path_has_dir_prefix($normalized, $content_dir)) {
                    return true;
                }
            }
        }

        if (false !== strpos($context, 'runtime') && preg_match('/^(?:\.[A-Za-z0-9_.-]+-)?ultracache-runtime-secrets\.php(?:\.tmp-[A-Za-z0-9_.-]+)?$/', $base)) {
            return true;
        }

        if (false !== strpos($context, 'set_wp_cache_flag')) {
            if ('wp-config.php' === $base && file_exists($dir . '/wp-config.php')) {
                return true;
            }
            if (preg_match('/^wp-config\.php\.tmp-[A-Za-z0-9_.]+$/', $base) && file_exists($dir . '/wp-config.php')) {
                return true;
            }
            if (preg_match('/^wp-config-backup-\d{8}-\d{6}-[A-Za-z0-9]+\.php$/', $base) && file_exists($dir . '/wp-config.php')) {
                return true;
            }
        }

        if (false !== strpos($context, 'sync_browser_cache_rules') && defined('ABSPATH')) {
            $root = wp_normalize_path(ABSPATH);
            if (ucwp_path_has_dir_prefix($normalized, $root) && ('.htaccess' === $base || preg_match('/^\.htaccess\.tmp-[A-Za-z0-9_.]+$/', $base))) {
                return true;
            }
        }

        return (bool) apply_filters('ucwp_is_allowed_writable_path', false, $normalized, $context);
    }
}

if (!function_exists('ucwp_safe_unlink')) {
    function ucwp_safe_unlink($path, $context = '')
    {
        if (!file_exists($path)) {
            return true;
        }

        if (!ucwp_is_allowed_destructive_path($path, $context)) {
            ucwp_debug_log('unlink blocked: path outside allowed roots', array('path' => $path, 'context' => (string) $context));
            return false;
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

        if (!ucwp_is_allowed_writable_path($from, $context) || !ucwp_is_allowed_writable_path($to, $context)) {
            ucwp_debug_log('rename blocked: path outside allowed write roots', array('from' => $from, 'to' => $to, 'context' => (string) $context));
            return false;
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
        $from = is_string($from) ? $from : '';
        $to = is_string($to) ? $to : '';
        if ('' === $from || '' === $to || !file_exists($from)) {
            ucwp_debug_log('copy failed: invalid path', array('from' => $from, 'to' => $to, 'context' => (string) $context));
            return false;
        }

        if (!ucwp_is_allowed_writable_path($to, $context)) {
            ucwp_debug_log('copy blocked: destination outside allowed write roots', array('from' => $from, 'to' => $to, 'context' => (string) $context));
            return false;
        }

        $dir = dirname($to);
        if ('' !== $dir && '.' !== $dir && !is_dir($dir) && !ucwp_safe_mkdir($dir, 0755, true, $context . ' parent mkdir')) {
            return false;
        }

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
        $dir = is_string($dir) ? $dir : '';
        if ('' === $dir || !ucwp_is_allowed_writable_path($dir, $context)) {
            ucwp_debug_log('mkdir blocked: directory outside allowed write roots', array('dir' => $dir, 'mode' => $mode, 'recursive' => (bool) $recursive, 'context' => (string) $context));
            return false;
        }

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

        if (!ucwp_is_allowed_destructive_path($dir, $context)) {
            ucwp_debug_log('rmdir blocked: path outside allowed roots', array('dir' => $dir, 'context' => (string) $context));
            return false;
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
        if ('' === $dir || !ucwp_is_allowed_writable_path($dir, $context) || !is_dir($dir) || !ucwp_path_is_writable($dir)) {
            ucwp_debug_log('tempnam directory unavailable or blocked', array('dir' => $dir, 'context' => (string) $context));
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
        $url = is_string($url) ? trim($url) : '';
        if ('' === $url) {
            return new WP_Error('ucwp_empty_remote_url', 'Remote request URL is empty.');
        }

        $defaults = array(
            'timeout' => 10,
            'redirection' => 3,
            'reject_unsafe_urls' => true,
            'user-agent' => 'UltraCache/' . (defined('UCWP_VERSION') ? UCWP_VERSION : 'unknown') . '; ' . home_url('/'),
        );
        $args = array_merge($defaults, $args);
        $args['redirection'] = max(0, min(5, (int) $args['redirection']));
        $args['timeout'] = max(1, min(60, (int) $args['timeout']));
        $args['reject_unsafe_urls'] = true;

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

if (!function_exists('ucwp_is_trusted_loopback_url')) {
    function ucwp_is_trusted_loopback_url($url)
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
        $host = isset($parts['host']) ? ucwp_normalize_host((string) $parts['host']) : '';
        if (!in_array($scheme, array('http', 'https'), true) || '' === $host) {
            return false;
        }

        $trusted_hosts = ucwp_get_trusted_hosts();
        if (in_array($host, $trusted_hosts, true)) {
            return true;
        }

        if (in_array($host, array('localhost', '127.0.0.1', '::1', '[::1]'), true)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 0 === strpos($host, '10.') || 0 === strpos($host, '192.168.') || 1 === preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $host);
        }

        return (bool) apply_filters('ucwp_is_trusted_loopback_url', false, $url, $host);
    }
}

if (!function_exists('ucwp_safe_loopback_remote_request')) {
    function ucwp_safe_loopback_remote_request($url, array $args = array(), $context = '')
    {
        if (!ucwp_is_trusted_loopback_url($url)) {
            ucwp_debug_log('loopback remote request blocked: untrusted URL', array('url' => (string) $url, 'context' => (string) $context));
            return new WP_Error('ucwp_untrusted_loopback_url', 'Loopback request URL is not local/trusted for this site.');
        }

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
