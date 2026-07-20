<?php
/**
 * Request-profiler checkpoint and callback-timing helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

function ultracache_request_profile_token_valid()
{
    $token = trim((string) ultracache_query_value('ultracache_rt'));
    if ('' === $token) {
        $token = trim((string) ultracache_server_value('HTTP_X_ULTRACACHE_TOKEN'));
    }

    return ultracache_validate_runtime_control_token($token);
}

function ultracache_request_profiler_enabled()
{
    $query_flag = strtolower(trim((string) ultracache_query_value('ultracache_store_profile')));
    $header_flag = strtolower(trim((string) ultracache_server_value('HTTP_X_ULTRACACHE_STORE_PROFILE')));
    $constant_flag = defined('ULTRACACHE_STORE_PROFILE') && ULTRACACHE_STORE_PROFILE;

    $requested = ('1' === $query_flag || 'true' === $query_flag || '1' === $header_flag || 'true' === $header_flag || $constant_flag);
    if (!$requested) {
        return false;
    }

    return ultracache_request_profile_token_valid();
}

function ultracache_request_callback_profiler_enabled()
{
    if (!ultracache_request_profiler_enabled()) {
        return false;
    }

    $query_flag = strtolower(trim((string) ultracache_query_value('ultracache_callback_profile')));
    $header_flag = strtolower(trim((string) ultracache_server_value('HTTP_X_ULTRACACHE_CALLBACK_PROFILE')));
    $constant_flag = defined('ULTRACACHE_CALLBACK_PROFILE') && ULTRACACHE_CALLBACK_PROFILE;

    return ('1' === $query_flag || 'true' === $query_flag || '1' === $header_flag || 'true' === $header_flag || $constant_flag);
}

function ultracache_request_profile_request_started_at()
{
    if (isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])) {
        return (float) $_SERVER['REQUEST_TIME_FLOAT'];
    }

    if (isset($_SERVER['REQUEST_TIME']) && is_numeric($_SERVER['REQUEST_TIME'])) {
        return (float) $_SERVER['REQUEST_TIME'];
    }

    return microtime(true);
}

function ultracache_request_profile_sanitize_stage($stage)
{
    $stage = strtolower((string) $stage);
    $stage = preg_replace('/[^a-z0-9_\-.]+/', '_', $stage);
    $stage = trim((string) $stage, '_-.');

    return '' !== $stage ? substr($stage, 0, 96) : 'checkpoint';
}

function ultracache_request_profile_current_hook_summary($hook_name)
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

function ultracache_request_profile_callback_label($callback)
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



function ultracache_request_profile_verbose_settings_enabled()
{
    $query_flag = strtolower(trim((string) ultracache_query_value('ultracache_store_profile_verbose_settings')));
    $header_flag = strtolower(trim((string) ultracache_server_value('HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE_SETTINGS')));
    $constant_flag = defined('ULTRACACHE_STORE_PROFILE_VERBOSE_SETTINGS') && ULTRACACHE_STORE_PROFILE_VERBOSE_SETTINGS;
    $query_verbose_flag = strtolower(trim((string) ultracache_query_value('ultracache_store_profile_verbose')));
    $header_verbose_flag = strtolower(trim((string) ultracache_server_value('HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE')));
    $constant_verbose_flag = defined('ULTRACACHE_STORE_PROFILE_VERBOSE') && ULTRACACHE_STORE_PROFILE_VERBOSE;

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


function ultracache_request_profile_verbose_enabled()
{
    $query_flag = strtolower(trim((string) ultracache_query_value('ultracache_store_profile_verbose')));
    $header_flag = strtolower(trim((string) ultracache_server_value('HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE')));
    $constant_flag = defined('ULTRACACHE_STORE_PROFILE_VERBOSE') && ULTRACACHE_STORE_PROFILE_VERBOSE;

    return ('1' === $query_flag || 'true' === $query_flag || '1' === $header_flag || 'true' === $header_flag || $constant_flag || ultracache_request_profile_verbose_settings_enabled());
}

function ultracache_request_profile_compact_stages()
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
        'diagnostic_fallback_output_buffer_started' => true,
        'diagnostic_fallback_output_buffer_flush_start' => true,
        'diagnostic_fallback_output_buffer_flush_step' => true,
        'diagnostic_fallback_output_buffer_missing_on_shutdown' => true,
        'diagnostic_fallback_output_buffer_callback' => true,
        'diagnostic_fallback_output_buffer_store_start' => true,
        'template_redirect_global_end' => true,
        'wp_head_start' => true,
        'wp_enqueue_scripts_start' => true,
        'wp_enqueue_scripts_end' => true,
        'wp_head_end' => true,
        'shutdown_start' => true,
        'cache_output_callback_start' => true,
        'store_profile_start' => true,
        'store_profile_diagnostic_skip_start' => true,
        'cache_output_callback_end' => true,
        'store_profile_finalize_start' => true,
        'output_buffer_callback_missing' => true,
        'shutdown_end' => true,
        'engine_shutdown_profile_update' => true,
        'callback_slow' => true,
    );
}

function ultracache_request_profile_should_record_checkpoint($stage)
{
    $stage = ultracache_request_profile_sanitize_stage($stage);
    if (ultracache_request_profile_verbose_enabled()) {
        return true;
    }

    $compact = ultracache_request_profile_compact_stages();
    if (isset($compact[$stage])) {
        return true;
    }

    if (0 === strpos($stage, 'advanced_cache_setup_')) {
        return true;
    }

    if (0 === strpos($stage, 'callback_') && ultracache_request_callback_profiler_enabled()) {
        return true;
    }

    return false;
}

function ultracache_request_profile_settings_checkpoint($stage, array $extra = array())
{
    if (!ultracache_request_profile_verbose_settings_enabled()) {
        return;
    }

    ultracache_request_profile_checkpoint($stage, $extra);
}

function ultracache_request_profile_normalize_path($path)
{
    $path = str_replace('\\', '/', (string) $path);
    return preg_replace('#/+#', '/', $path);
}

function ultracache_request_profile_relative_path($file, $base)
{
    $file = ultracache_request_profile_normalize_path($file);
    $base = rtrim(ultracache_request_profile_normalize_path($base), '/') . '/';
    if ('' !== $base && 0 === strpos($file, $base)) {
        return ltrim(substr($file, strlen($base)), '/');
    }

    return '';
}

function ultracache_request_profile_callback_origin($callback)
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

    $file = ultracache_request_profile_normalize_path($file);
    $type = 'unknown';
    $name = '';
    $relative = '';

    if ('' !== $file) {
        $plugin_dir = ultracache_request_profile_normalize_path(ultracache_plugins_root_dir());
        $mu_plugin_dir = ultracache_request_profile_normalize_path(ultracache_mu_plugins_root_dir());
        $uploads = ultracache_uploads_base_info();
        $uploads_dir = !empty($uploads['basedir']) ? ultracache_request_profile_normalize_path($uploads['basedir']) : '';
        $core_dir = ultracache_request_profile_normalize_path(ultracache_wordpress_core_root_dir());

        if ('' !== $plugin_dir && '' !== ($relative = ultracache_request_profile_relative_path($file, $plugin_dir))) {
            $type = 'plugin';
            $parts = explode('/', $relative);
            $name = (string) reset($parts);
        } elseif ('' !== $mu_plugin_dir && '' !== ($relative = ultracache_request_profile_relative_path($file, $mu_plugin_dir))) {
            $type = 'mu-plugin';
            $parts = explode('/', $relative);
            $name = (string) reset($parts);
        } elseif (function_exists('get_stylesheet_directory') && '' !== ($relative = ultracache_request_profile_relative_path($file, get_stylesheet_directory()))) {
            $type = 'theme';
            $name = function_exists('get_stylesheet') ? (string) get_stylesheet() : 'stylesheet';
        } elseif (function_exists('get_template_directory') && '' !== ($relative = ultracache_request_profile_relative_path($file, get_template_directory()))) {
            $type = 'theme';
            $name = function_exists('get_template') ? (string) get_template() : 'template';
        } elseif ('' !== $uploads_dir && '' !== ($relative = ultracache_request_profile_relative_path($file, $uploads_dir))) {
            $type = 'uploads';
            $parts = explode('/', $relative);
            $name = (string) reset($parts);
        } elseif ('' !== $core_dir && '' !== ($relative = ultracache_request_profile_relative_path($file, $core_dir))) {
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

function ultracache_request_profile_record_callback_timing($hook_name, $priority, $index, $callback_id, $label, array $origin, $start_ms, $duration_ms)
{
    if (!ultracache_request_callback_profiler_enabled()) {
        return;
    }

    if (!isset($GLOBALS['ultracache_request_profile_callback_timings']) || !is_array($GLOBALS['ultracache_request_profile_callback_timings'])) {
        $GLOBALS['ultracache_request_profile_callback_timings'] = array();
    }

    if (!isset($GLOBALS['ultracache_request_profile_callback_timing_summary']) || !is_array($GLOBALS['ultracache_request_profile_callback_timing_summary'])) {
        $GLOBALS['ultracache_request_profile_callback_timing_summary'] = array();
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

    $GLOBALS['ultracache_request_profile_callback_timings'][] = $entry;

    $key = implode('|', array($entry['hook'], $entry['priority'], $entry['callback_label'], $entry['origin_type'], $entry['origin_name'], $entry['origin_file']));
    if (!isset($GLOBALS['ultracache_request_profile_callback_timing_summary'][$key])) {
        $GLOBALS['ultracache_request_profile_callback_timing_summary'][$key] = array(
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

    $GLOBALS['ultracache_request_profile_callback_timing_summary'][$key]['count']++;
    $GLOBALS['ultracache_request_profile_callback_timing_summary'][$key]['total_ms'] += $duration_ms;
    if ($duration_ms > (int) $GLOBALS['ultracache_request_profile_callback_timing_summary'][$key]['max_ms']) {
        $GLOBALS['ultracache_request_profile_callback_timing_summary'][$key]['max_ms'] = $duration_ms;
    }

    if ($duration_ms >= 200) {
        ultracache_request_profile_checkpoint('callback_slow', array(
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

function ultracache_get_request_profile_callback_timings($limit = 120)
{
    $timings = isset($GLOBALS['ultracache_request_profile_callback_timings']) && is_array($GLOBALS['ultracache_request_profile_callback_timings']) ? $GLOBALS['ultracache_request_profile_callback_timings'] : array();
    usort($timings, function ($a, $b) {
        return (int) ($b['duration_ms'] ?? 0) <=> (int) ($a['duration_ms'] ?? 0);
    });

    return array_slice($timings, 0, max(1, (int) $limit));
}

function ultracache_get_request_profile_callback_timing_summary($limit = 80)
{
    $summary = isset($GLOBALS['ultracache_request_profile_callback_timing_summary']) && is_array($GLOBALS['ultracache_request_profile_callback_timing_summary']) ? array_values($GLOBALS['ultracache_request_profile_callback_timing_summary']) : array();
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

function ultracache_request_profile_wrap_hook_callbacks($hook_name, $priorities = null)
{
    if (!ultracache_request_callback_profiler_enabled()) {
        return;
    }

    global $wp_filter;
    $hook_name = (string) $hook_name;
    if ('' === $hook_name || empty($wp_filter[$hook_name]) || !is_object($wp_filter[$hook_name]) || empty($wp_filter[$hook_name]->callbacks) || !is_array($wp_filter[$hook_name]->callbacks)) {
        ultracache_request_profile_checkpoint('callback_wrap_skipped', array('target_hook' => $hook_name, 'reason' => 'no_callbacks'));
        return;
    }

    if (!isset($GLOBALS['ultracache_request_profile_wrapped_callbacks']) || !is_array($GLOBALS['ultracache_request_profile_wrapped_callbacks'])) {
        $GLOBALS['ultracache_request_profile_wrapped_callbacks'] = array();
    }
    if (!isset($GLOBALS['ultracache_request_profile_wrapped_callbacks'][$hook_name])) {
        $GLOBALS['ultracache_request_profile_wrapped_callbacks'][$hook_name] = array();
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
            if (!empty($GLOBALS['ultracache_request_profile_wrapped_callbacks'][$hook_name][$wrapped_key])) {
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

            $label = ultracache_request_profile_callback_label($original_callback);
            $origin = ultracache_request_profile_callback_origin($original_callback);
            $current_index = $index;
            $current_id = is_scalar($callback_id) ? (string) $callback_id : 'callback_' . (string) $current_index;
            $current_priority = (string) $actual_priority;

            $wp_filter[$hook_name]->callbacks[$actual_priority][$callback_id]['function'] = function () use ($hook_name, $original_callback, $accepted_args, $current_priority, $current_index, $current_id, $label, $origin) {
                $args = func_get_args();
                if ($accepted_args >= 0) {
                    $args = array_slice($args, 0, $accepted_args);
                }

                $request_start = ultracache_request_profile_request_started_at();
                $start = microtime(true);
                $result = null;
                try {
                    $result = call_user_func_array($original_callback, $args);
                } finally {
                    $end = microtime(true);
                    ultracache_request_profile_record_callback_timing(
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

            $GLOBALS['ultracache_request_profile_wrapped_callbacks'][$hook_name][$wrapped_key] = true;
            $wrapped++;
            $index++;
        }

        $priority_counts[$priority_key] = $index;
    }

    ultracache_request_profile_checkpoint('callback_wrap_done', array(
        'target_hook' => $hook_name,
        'wrapped_count' => (string) $wrapped,
        'priority_counts' => $priority_counts,
    ));
}

function ultracache_request_profile_checkpoint($stage, array $extra = array())
{
    if (!ultracache_request_profiler_enabled()) {
        return;
    }

    $stage = ultracache_request_profile_sanitize_stage($stage);
    if (!ultracache_request_profile_should_record_checkpoint($stage)) {
        return;
    }

    if (!isset($GLOBALS['ultracache_request_profile_checkpoints']) || !is_array($GLOBALS['ultracache_request_profile_checkpoints'])) {
        $GLOBALS['ultracache_request_profile_checkpoints'] = array();
    }

    $now = microtime(true);
    $request_start = ultracache_request_profile_request_started_at();
    $last = isset($GLOBALS['ultracache_request_profile_last_checkpoint_at']) && is_numeric($GLOBALS['ultracache_request_profile_last_checkpoint_at'])
        ? (float) $GLOBALS['ultracache_request_profile_last_checkpoint_at']
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

    if (ultracache_request_profile_verbose_enabled() && '' !== $current_hook && !isset($checkpoint['hook_summary'])) {
        $summary = ultracache_request_profile_current_hook_summary($current_hook);
        if (!empty($summary)) {
            $checkpoint['hook_summary'] = $summary;
        }
    }

    $GLOBALS['ultracache_request_profile_checkpoints'][] = $checkpoint;
    $GLOBALS['ultracache_request_profile_last_checkpoint_at'] = $now;
}

function ultracache_get_request_profile_checkpoints()
{
    return isset($GLOBALS['ultracache_request_profile_checkpoints']) && is_array($GLOBALS['ultracache_request_profile_checkpoints'])
        ? $GLOBALS['ultracache_request_profile_checkpoints']
        : array();
}

ultracache_request_profile_checkpoint('plugin_file_loaded');
