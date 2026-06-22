<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Profiling_Metrics_Trait
{

    /** @var bool|null */
    private $store_profile_enabled = null;

    /** @var array<string, mixed> */
    private $store_profile = array();

    /** @var float */
    private $store_profile_started_at = 0.0;

    /** @var array<int, array<string, mixed>> */
    private $store_profile_request_checkpoints = array();

    /** @var float */
    private $store_profile_last_checkpoint_at = 0.0;

    /**  bool */
    private $store_profile_shutdown_written = false;

    /** @var array<int, array<string, mixed>> */
    private $deferred_store_post_response_actions = array();

    public function profile_init_checkpoint()
    {
        $this->profile_request_checkpoint('init');
    }

    public function profile_wp_loaded_checkpoint()
    {
        $this->profile_request_checkpoint('wp_loaded');
    }

    public function profile_template_redirect_checkpoint()
    {
        $this->profile_request_checkpoint('template_redirect_start');
    }

    public function profile_wp_enqueue_scripts_start_checkpoint()
    {
        $this->profile_request_checkpoint('wp_enqueue_scripts_start');
    }

    public function profile_wp_enqueue_scripts_end_checkpoint()
    {
        $this->profile_request_checkpoint('wp_enqueue_scripts_end');
    }

    private function get_store_profile_request_started_at()
    {
        if (isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])) {
            return (float) $_SERVER['REQUEST_TIME_FLOAT'];
        }

        if (isset($_SERVER['REQUEST_TIME']) && is_numeric($_SERVER['REQUEST_TIME'])) {
            return (float) $_SERVER['REQUEST_TIME'];
        }

        return $this->store_profile_started_at > 0 ? (float) $this->store_profile_started_at : microtime(true);
    }

    private function profile_request_checkpoint($stage, array $extra = array())
    {
        if (!$this->is_store_profiler_enabled()) {
            return;
        }

        $stage_key = sanitize_key((string) $stage);
        if (function_exists('ultracache_request_profile_should_record_checkpoint') && !ultracache_request_profile_should_record_checkpoint($stage_key)) {
            return;
        }

        $now = microtime(true);
        $request_start = $this->get_store_profile_request_started_at();
        $previous = $this->store_profile_last_checkpoint_at > 0 ? $this->store_profile_last_checkpoint_at : $request_start;
        $checkpoint = array_merge(array(
            'stage' => $stage_key,
            'at_ms' => (int) round(max(0, $now - $request_start) * 1000),
            'since_previous_ms' => (int) round(max(0, $now - $previous) * 1000),
            'memory_bytes' => function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0,
            'peak_memory_bytes' => function_exists('memory_get_peak_usage') ? (int) memory_get_peak_usage(true) : 0,
        ), $extra);

        $this->store_profile_request_checkpoints[] = $checkpoint;
        $this->store_profile_last_checkpoint_at = $now;
    }

    private function profile_settings_request_checkpoint($stage, array $extra = array())
    {
        if (function_exists('ultracache_request_profile_verbose_settings_enabled') && !ultracache_request_profile_verbose_settings_enabled()) {
            return;
        }

        $this->profile_request_checkpoint($stage, $extra);
    }

    private function get_store_profile_request_checkpoints()
    {
        $external = function_exists('ultracache_get_request_profile_checkpoints') ? ultracache_get_request_profile_checkpoints() : array();
        $checkpoints = array_merge(is_array($external) ? $external : array(), $this->store_profile_request_checkpoints);

        if (empty($checkpoints)) {
            return array();
        }

        $indexed = array();
        foreach ($checkpoints as $index => $checkpoint) {
            if (!is_array($checkpoint)) {
                continue;
            }
            $checkpoint['_ultracache_order'] = $index;
            $indexed[] = $checkpoint;
        }

        usort($indexed, function ($a, $b) {
            $a_ms = isset($a['at_ms']) ? (int) $a['at_ms'] : 0;
            $b_ms = isset($b['at_ms']) ? (int) $b['at_ms'] : 0;
            if ($a_ms === $b_ms) {
                return (int) ($a['_ultracache_order'] ?? 0) <=> (int) ($b['_ultracache_order'] ?? 0);
            }
            return $a_ms <=> $b_ms;
        });

        $previous_at = 0;
        foreach ($indexed as $i => $checkpoint) {
            $at = isset($checkpoint['at_ms']) ? (int) $checkpoint['at_ms'] : 0;
            $checkpoint['since_previous_ms'] = 0 === $i ? $at : max(0, $at - $previous_at);
            unset($checkpoint['_ultracache_order']);
            $indexed[$i] = $checkpoint;
            $previous_at = $at;
        }

        return $indexed;
    }

    private function get_store_profile_request_phase_summary(array $checkpoints)
    {
        $wanted = array(
            'plugin_file_loaded',
            'ultracache_wp_construct_start',
            'ultracache_dependencies_loaded',
            'ultracache_hooks_registered',
            'plugins_loaded_p-1000',
            'plugins_loaded_p0',
            'plugins_loaded_p5_components',
            'engine_construct',
            'plugins_loaded_p18_before_reconcile',
            'plugins_loaded_p19_before_page_cache_reconcile',
            'page_cache_reconcile_skipped',
            'page_cache_reconcile_light_start',
            'page_cache_reconcile_light_end',
            'page_cache_reconcile_full_start',
            'advanced_cache_setup_template_read_start',
            'advanced_cache_setup_template_read_end',
            'advanced_cache_setup_existing_read_start',
            'advanced_cache_setup_existing_read_end',
            'advanced_cache_setup_unchanged',
            'advanced_cache_setup_write_temp_start',
            'advanced_cache_setup_write_temp_end',
            'advanced_cache_setup_rename_start',
            'advanced_cache_setup_rename_end',
            'page_cache_reconcile_full_end',
            'plugins_loaded_p20_before_object_cache_reconcile',
            'plugins_loaded_p22_after_reconcile',
            'plugins_loaded_end',
            'setup_theme_start',
            'setup_theme_end',
            'after_setup_theme_start',
            'after_setup_theme_end',
            'init_start',
            'init',
            'init_end',
            'wp_loaded_start',
            'wp_loaded',
            'wp_loaded_end',
            'template_redirect_global_start',
            'template_redirect_start',
            'maybe_start_buffering_start',
            'maybe_start_buffering_after_reentry_check',
            'maybe_start_buffering_before_should_bypass',
            'maybe_start_buffering_after_should_bypass',
            'early_hit_check_start',
            'early_hit_check_end',
            'page_generation_lock_before',
            'page_generation_lock_checked',
            'record_analytics_miss_start',
            'record_analytics_miss_end',
            'send_debug_headers_start',
            'send_debug_headers_end',
            'buffer_start',
            'diagnostic_fallback_output_buffer_started',
            'diagnostic_fallback_output_buffer_flush_start',
            'diagnostic_fallback_output_buffer_callback',
            'diagnostic_fallback_output_buffer_store_start',
            'cache_output_callback_start',
            'cache_output_callback_end',
            'output_buffer_callback_missing',
            'shutdown_start',
            'shutdown_end',
        );

        $by_stage = array();
        foreach ($checkpoints as $checkpoint) {
            if (!is_array($checkpoint) || empty($checkpoint['stage'])) {
                continue;
            }
            $stage = (string) $checkpoint['stage'];
            if (!isset($by_stage[$stage])) {
                $by_stage[$stage] = isset($checkpoint['at_ms']) ? (int) $checkpoint['at_ms'] : 0;
            }
        }

        $summary = array();
        $previous_stage = '';
        $previous_ms = null;
        foreach ($wanted as $stage) {
            if (!isset($by_stage[$stage])) {
                continue;
            }
            $at = (int) $by_stage[$stage];
            $summary[] = array(
                'stage' => $stage,
                'at_ms' => $at,
                'since_previous_wanted_ms' => null === $previous_ms ? $at : max(0, $at - (int) $previous_ms),
                'previous_stage' => $previous_stage,
            );
            $previous_stage = $stage;
            $previous_ms = $at;
        }

        return $summary;
    }

    private function get_store_profile_callback_timings()
    {
        if (function_exists('ultracache_get_request_profile_callback_timings')) {
            $timings = ultracache_get_request_profile_callback_timings(120);
            return is_array($timings) ? $timings : array();
        }

        return array();
    }

    private function get_store_profile_callback_timing_summary()
    {
        if (function_exists('ultracache_get_request_profile_callback_timing_summary')) {
            $summary = ultracache_get_request_profile_callback_timing_summary(80);
            return is_array($summary) ? $summary : array();
        }

        return array();
    }

    private function get_store_profile_settings_snapshot()
    {
        $settings = $this->get_settings();
        return array(
            'homepage_css_bundle' => !empty($settings['homepage_css_bundle']),
            'homepage_css_bundle_inline' => !empty($settings['homepage_css_bundle_inline']),
            'homepage_css_bundle_mode' => isset($settings['homepage_css_bundle_mode']) ? (string) $settings['homepage_css_bundle_mode'] : '',
            'css_bundle_scope' => isset($settings['css_bundle_scope']) ? (string) $settings['css_bundle_scope'] : '',
            'page_css_bundle_on_entry' => !empty($settings['page_css_bundle_on_entry']),
            'page_css_bundle_async_on_entry' => !empty($settings['page_css_bundle_async_on_entry']),
            'async_css' => !empty($settings['async_css']),
            'aggressive_async_css' => !empty($settings['aggressive_async_css']),
            'defer_js' => !empty($settings['defer_js']),
            'defer_all_js' => !empty($settings['defer_all_js']),
            'delay_safe_third_party_js' => !empty($settings['delay_safe_third_party_js']),
            'delay_non_critical_js' => !empty($settings['delay_non_critical_js']),
            'lcp_image_priority' => !empty($settings['lcp_image_priority']),
            'lcp_boundary_defer' => !empty($settings['lcp_boundary_defer']),
            'slider_safe_mode' => !empty($settings['slider_safe_mode']),
        );
    }

    private function get_store_profile_css_bundle_context()
    {
        $context = array(
            'entry_url' => '',
            'bundle_url' => '',
            'bundle_file' => '',
            'bundle_file_exists' => false,
            'bundle_file_bytes' => 0,
            'source_url_count' => 0,
            'source_bytes_total' => 0,
            'largest_source_bytes' => 0,
            'largest_source_url' => '',
            'source_top' => array(),
            'mode' => '',
            'large_bundle_warning' => false,
            'very_large_bundle_warning' => false,
            'source_control_ready' => false,
        );

        $settings = $this->get_settings();
        if (empty($settings['homepage_css_bundle'])) {
            return $context;
        }

        $scope = $this->get_css_bundle_scope($settings);
        $current_url = $this->get_current_request_url();
        $entry_url = $current_url;
        if ('homepage' === $scope || 'shared' === $scope) {
            $entry_url = home_url('/');
        }
        $context['entry_url'] = (string) $entry_url;

        $entry = $this->get_frontpage_css_manifest_entry($entry_url);
        if (empty($entry)) {
            return $context;
        }

        $bundle_file = isset($entry['bundleFile']) ? (string) $entry['bundleFile'] : (isset($entry['file']) ? (string) $entry['file'] : '');
        $context['bundle_url'] = isset($entry['bundleUrl']) ? (string) $entry['bundleUrl'] : '';
        $context['bundle_file'] = $bundle_file;
        $context['bundle_file_exists'] = ('' !== $bundle_file && is_readable($bundle_file));
        $context['bundle_file_bytes'] = $context['bundle_file_exists'] ? (int) filesize($bundle_file) : 0;
        $context['source_url_count'] = isset($entry['sourceUrls']) && is_array($entry['sourceUrls']) ? count($entry['sourceUrls']) : 0;
        $context['mode'] = isset($entry['mode']) ? (string) $entry['mode'] : '';

        $source_details = array();
        if (isset($entry['sourceDetails']) && is_array($entry['sourceDetails'])) {
            $source_details = $entry['sourceDetails'];
        } elseif (!empty($entry['sourceUrls']) && is_array($entry['sourceUrls'])) {
            $source_details = $this->build_css_bundle_source_details_from_urls((array) $entry['sourceUrls']);
        }

        $source_details = $this->normalize_css_bundle_source_details($source_details);
        $context['source_top'] = array_slice($source_details, 0, 12);
        $context['source_control_ready'] = !empty($context['source_top']);
        $context['source_bytes_total'] = isset($entry['sourceBytesTotal']) ? max(0, (int) $entry['sourceBytesTotal']) : $this->sum_css_bundle_source_detail_bytes($source_details);
        if (!empty($source_details[0])) {
            $context['largest_source_bytes'] = isset($source_details[0]['bytes']) ? (int) $source_details[0]['bytes'] : 0;
            $context['largest_source_url'] = isset($source_details[0]['url']) ? (string) $source_details[0]['url'] : '';
        }

        $context['large_bundle_warning'] = ($context['bundle_file_bytes'] > 153600);
        $context['very_large_bundle_warning'] = ($context['bundle_file_bytes'] > 204800);

        return $context;
    }

    private function is_ultracache_generated_css_output_url($url)
    {
        $path = strtolower((string) wp_parse_url((string) $url, PHP_URL_PATH));
        if ('' === $path) {
            return false;
        }

        return ultracache_generated_asset_reference_matches($path, array('css-bundles', 'font-css', 'optimized-css'));
    }

    private function normalize_css_rewrite_map_lookup_url($url)
    {
        $url = trim((string) $url);
        if ('' === $url) {
            return '';
        }
        if (0 === strpos($url, '//')) {
            $url = (is_ssl() ? 'https:' : 'http:') . $url;
        } elseif (0 === strpos($url, '/')) {
            $url = home_url($url);
        }
        if (method_exists($this, 'normalize_public_resource_url')) {
            $normalized = $this->normalize_public_resource_url($url);
            if ('' !== $normalized) {
                return esc_url_raw((string) $normalized);
            }
        }
        return esc_url_raw($url);
    }

    private function get_css_rewrite_source_url_from_transient_map($generated_url)
    {
        $generated_url = $this->normalize_css_rewrite_map_lookup_url($generated_url);
        if ('' === $generated_url) {
            return '';
        }

        $map = get_transient('ultracache_runtime_font_css_url_map_v3');
        if (!is_array($map)) {
            return '';
        }

        foreach ($map as $source_url => $mapped_url) {
            $source_url = $this->normalize_css_rewrite_map_lookup_url((string) $source_url);
            $mapped_url = $this->normalize_css_rewrite_map_lookup_url((string) $mapped_url);
            if ('' !== $source_url && '' !== $mapped_url && $mapped_url === $generated_url) {
                return $source_url;
            }
        }

        return '';
    }

    private function map_generated_css_detail_to_original_source(array $detail)
    {
        $url = isset($detail['url']) ? $this->normalize_css_rewrite_map_lookup_url((string) $detail['url']) : '';
        if ('' === $url || !$this->is_ultracache_generated_css_output_url($url)) {
            return $detail;
        }

        $source_url = '';
        $source_path = '';
        $generated_url = $url;
        $generated_path = $this->resolve_local_path_from_public_url($generated_url);
        $generated_bytes = ('' !== $generated_path && is_readable($generated_path)) ? (int) filesize($generated_path) : max(0, (int) ($detail['bytes'] ?? 0));
        $optimization_type = 'ultracache-generated-css';

        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_css_rewrite_map_by_generated_url')) {
            $row = Ultra_Cache_WP::get_css_rewrite_map_by_generated_url($generated_url);
            if (is_array($row) && !empty($row['source_url'])) {
                $source_url = $this->normalize_css_rewrite_map_lookup_url((string) $row['source_url']);
                $source_path = isset($row['source_path']) ? wp_normalize_path((string) $row['source_path']) : '';
                $optimization_type = isset($row['optimization_type']) ? sanitize_key((string) $row['optimization_type']) : $optimization_type;
            }
        }

        if ('' === $source_url) {
            $source_url = $this->get_css_rewrite_source_url_from_transient_map($generated_url);
            if ('' !== $source_url) {
                $source_path = $this->resolve_local_path_from_public_url($source_url);
                $optimization_type = 'css-font-mix';
            }
        }

        if ('' === $source_url) {
            $detail['generatedUrl'] = $generated_url;
            $detail['generatedBytes'] = $generated_bytes;
            $detail['generatedOutputOnly'] = true;
            $detail['suggestedExclusion'] = '';
            return $detail;
        }

        $source_bytes = ('' !== $source_path && is_readable($source_path)) ? (int) filesize($source_path) : max(0, (int) ($detail['sourceBytes'] ?? $detail['bytes'] ?? 0));
        $detail['url'] = $source_url;
        $detail['bytes'] = $source_bytes;
        $detail['generatedUrl'] = $generated_url;
        $detail['generatedBytes'] = $generated_bytes;
        $detail['generatedOptimizationType'] = $optimization_type;
        $detail['rewrittenByUltraCache'] = true;
        return $detail;
    }

    private function get_css_bundle_source_type($url)
    {
        $path = strtolower((string) wp_parse_url((string) $url, PHP_URL_PATH));
        if (function_exists('ultracache_public_path_contains') && ultracache_public_path_contains($path, ultracache_plugins_public_path())) {
            return 'plugin';
        }
        if (function_exists('ultracache_public_path_contains_any') && ultracache_public_path_contains_any($path, ultracache_themes_public_paths())) {
            return 'theme';
        }
        if (function_exists('ultracache_public_path_contains') && ultracache_public_path_contains($path, ultracache_generated_asset_public_path())) {
            return 'ultracache-generated';
        }
        if (function_exists('ultracache_public_path_contains') && ultracache_public_path_contains($path, ultracache_uploads_public_path())) {
            return 'uploads';
        }
        if (function_exists('ultracache_public_path_contains') && function_exists('ultracache_content_cache_public_path') && ultracache_public_path_contains($path, ultracache_content_cache_public_path())) {
            return 'ultracache-cache';
        }
        return 'local';
    }

    private function get_css_bundle_source_exclusion_suggestion($url)
    {
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        $path = trim($path);
        if ('' === $path) {
            $path = trim((string) $url);
        }
        if ('' === $path) {
            return '';
        }
        $path = rawurldecode($path);
        $path = preg_replace('/[\r\n\t]+/', '', $path);
        return trim((string) $path);
    }

    private function build_css_bundle_source_details_from_urls(array $source_urls)
    {
        $details = array();
        foreach ($source_urls as $source_url) {
            $url = trim((string) $source_url);
            if ('' === $url) {
                continue;
            }
            $path = $this->resolve_local_path_from_public_url($url);
            $bytes = ('' !== $path && is_readable($path)) ? (int) filesize($path) : 0;
            $details[] = array(
                'url' => $url,
                'bytes' => $bytes,
                'preparedBytes' => 0,
                'type' => $this->get_css_bundle_source_type($url),
            );
        }
        return $details;
    }

    private function normalize_css_bundle_source_details(array $details)
    {
        $normalized = array();
        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }
            $detail = $this->map_generated_css_detail_to_original_source($detail);
            $url = isset($detail['url']) ? trim((string) $detail['url']) : '';
            if ('' === $url) {
                continue;
            }
            $bytes = isset($detail['bytes']) ? max(0, (int) $detail['bytes']) : 0;
            $prepared_bytes = isset($detail['preparedBytes']) ? max(0, (int) $detail['preparedBytes']) : 0;
            $css_image_urls_scanned = isset($detail['cssImageUrlsScanned']) ? max(0, (int) $detail['cssImageUrlsScanned']) : 0;
            $css_image_urls_rewritten = isset($detail['cssImageUrlsRewritten']) ? max(0, (int) $detail['cssImageUrlsRewritten']) : 0;
            $css_image_urls_image_set = isset($detail['cssImageUrlsImageSet']) ? max(0, (int) $detail['cssImageUrlsImageSet']) : 0;
            $css_image_urls_skipped = isset($detail['cssImageUrlsSkipped']) ? max(0, (int) $detail['cssImageUrlsSkipped']) : 0;
            $key = $url;
            if (isset($normalized[$key])) {
                $normalized[$key]['bytes'] = max((int) $normalized[$key]['bytes'], $bytes);
                $normalized[$key]['preparedBytes'] = max((int) $normalized[$key]['preparedBytes'], $prepared_bytes);
                $normalized[$key]['cssImageUrlsScanned'] = max(0, (int) ($normalized[$key]['cssImageUrlsScanned'] ?? 0)) + $css_image_urls_scanned;
                $normalized[$key]['cssImageUrlsRewritten'] = max(0, (int) ($normalized[$key]['cssImageUrlsRewritten'] ?? 0)) + $css_image_urls_rewritten;
                $normalized[$key]['cssImageUrlsImageSet'] = max(0, (int) ($normalized[$key]['cssImageUrlsImageSet'] ?? 0)) + $css_image_urls_image_set;
                $normalized[$key]['cssImageUrlsSkipped'] = max(0, (int) ($normalized[$key]['cssImageUrlsSkipped'] ?? 0)) + $css_image_urls_skipped;
                continue;
            }
            $normalized[$key] = array(
                'url' => $url,
                'bytes' => $bytes,
                'preparedBytes' => $prepared_bytes,
                'type' => isset($detail['type']) ? sanitize_key((string) $detail['type']) : $this->get_css_bundle_source_type($url),
                'suggestedExclusion' => !empty($detail['generatedOutputOnly']) ? '' : $this->get_css_bundle_source_exclusion_suggestion($url),
                'largeSourceWarning' => ($bytes > 51200),
                'generatedUrl' => isset($detail['generatedUrl']) ? (string) $detail['generatedUrl'] : '',
                'generatedBytes' => isset($detail['generatedBytes']) ? max(0, (int) $detail['generatedBytes']) : 0,
                'generatedOptimizationType' => isset($detail['generatedOptimizationType']) ? sanitize_key((string) $detail['generatedOptimizationType']) : '',
                'rewrittenByUltraCache' => !empty($detail['rewrittenByUltraCache']),
                'generatedOutputOnly' => !empty($detail['generatedOutputOnly']),
                'cssImageUrlsScanned' => $css_image_urls_scanned,
                'cssImageUrlsRewritten' => $css_image_urls_rewritten,
                'cssImageUrlsImageSet' => $css_image_urls_image_set,
                'cssImageUrlsSkipped' => $css_image_urls_skipped,
            );
        }

        $normalized = array_values($normalized);
        usort($normalized, function ($a, $b) {
            $a_bytes = isset($a['bytes']) ? (int) $a['bytes'] : 0;
            $b_bytes = isset($b['bytes']) ? (int) $b['bytes'] : 0;
            if ($a_bytes === $b_bytes) {
                return strcmp((string) ($a['url'] ?? ''), (string) ($b['url'] ?? ''));
            }
            return ($a_bytes < $b_bytes) ? 1 : -1;
        });

        return $normalized;
    }

    private function sum_css_bundle_source_detail_bytes(array $details)
    {
        $total = 0;
        foreach ($details as $detail) {
            if (is_array($detail) && isset($detail['bytes'])) {
                $total += max(0, (int) $detail['bytes']);
            }
        }
        return (int) $total;
    }

    private function sum_store_profile_regex_group_bytes($pattern, $html, $group = 1)
    {
        if (!is_string($html) || '' === $html) {
            return 0;
        }

        $count = preg_match_all($pattern, $html, $matches);
        if (!is_int($count) || $count <= 0 || empty($matches[$group]) || !is_array($matches[$group])) {
            return 0;
        }

        $bytes = 0;
        foreach ($matches[$group] as $match) {
            $bytes += strlen((string) $match);
        }

        return (int) $bytes;
    }

    private function is_store_profiler_enabled()
    {
        if (null !== $this->store_profile_enabled) {
            return (bool) $this->store_profile_enabled;
        }

        $this->store_profile_enabled = function_exists('ultracache_request_profiler_enabled') ? ultracache_request_profiler_enabled() : false;
        return (bool) $this->store_profile_enabled;
    }

    private function get_store_profile_dir()
    {
        return trailingslashit(ULTRACACHE_CACHE_DIR) . 'diagnostics/';
    }

    private function get_store_profile_last_file()
    {
        return $this->get_store_profile_dir() . 'store-profile-last.json';
    }

    private function get_store_profile_run_id()
    {
        $run_id = sanitize_key((string) ultracache_query_value('ultracache_profile_run'));
        if ('' === $run_id) {
            $run_id = sanitize_key((string) ultracache_server_value('HTTP_X_ULTRACACHE_PROFILE_RUN'));
        }

        return '' !== $run_id ? substr($run_id, 0, 64) : '';
    }

    private function get_store_profile_run_file($run_id)
    {
        $run_id = sanitize_key((string) $run_id);
        if ('' === $run_id) {
            return '';
        }

        return $this->get_store_profile_dir() . 'store-profile-' . substr($run_id, 0, 64) . '.json';
    }

    private function cleanup_old_store_profile_run_files($context = 'store_profile_cleanup')
    {
        $dir = $this->get_store_profile_dir();
        if (!is_dir($dir)) {
            return;
        }

        $entries = ultracache_safe_scandir($dir, (string) $context);
        if (!is_array($entries)) {
            return;
        }

        $retention_seconds = 7 * DAY_IN_SECONDS;
        $cutoff = time() - $retention_seconds;

        foreach ($entries as $entry) {
            $entry = (string) $entry;
            if ('.' === $entry || '..' === $entry || 'store-profile-last.json' === $entry) {
                continue;
            }

            if (1 !== preg_match('/^store-profile-[A-Za-z0-9_-]+\.json$/', $entry)) {
                continue;
            }

            $path = trailingslashit($dir) . $entry;
            if (!is_file($path)) {
                continue;
            }

            $mtime = ultracache_safe_filemtime($path, (string) $context);
            if (!is_int($mtime) || $mtime >= $cutoff) {
                continue;
            }

            ultracache_safe_unlink($path, (string) $context);
        }
    }

    private function write_store_profile_json($context = 'store_profile_write')
    {
        if (empty($this->store_profile) || !is_array($this->store_profile)) {
            return false;
        }

        $dir = $this->get_store_profile_dir();
        if (!is_dir($dir)) {
            ultracache_safe_mkdir($dir, 0755, true, (string) $context . '_mkdir');
        }

        if (!is_dir($dir) || !ultracache_path_is_writable($dir)) {
            ultracache_debug_log('store profile write failed', array('context' => (string) $context, 'reason' => 'diagnostics-dir-not-writable', 'dir' => $dir));
            return false;
        }

        $json = wp_json_encode($this->store_profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || '' === $json) {
            ultracache_debug_log('store profile write failed', array('context' => (string) $context, 'reason' => 'json-encode-failed'));
            return false;
        }

        $last_ok = false !== ultracache_safe_file_put_contents($this->get_store_profile_last_file(), $json, LOCK_EX, (string) $context . '_last');
        $run_ok = true;
        $run_id = isset($this->store_profile['profile_run_id']) ? sanitize_key((string) $this->store_profile['profile_run_id']) : '';
        if ('' !== $run_id) {
            $run_file = $this->get_store_profile_run_file($run_id);
            if ('' !== $run_file) {
                $run_ok = false !== ultracache_safe_file_put_contents($run_file, $json, LOCK_EX, (string) $context . '_run');
            }
        }

        if ($last_ok && $run_ok) {
            $this->cleanup_old_store_profile_run_files((string) $context . '_retention');
        }

        if (!$last_ok || !$run_ok) {
            ultracache_debug_log('store profile write failed', array('context' => (string) $context, 'last_ok' => $last_ok ? 'yes' : 'no', 'run_ok' => $run_ok ? 'yes' : 'no'));
        }

        return $last_ok && $run_ok;
    }

    public function get_store_profile_by_run_id($run_id)
    {
        $file = $this->get_store_profile_run_file($run_id);
        if ('' === $file || !is_readable($file)) {
            return array();
        }

        $json = ultracache_safe_file_get_contents($file);
        $data = json_decode((string) $json, true);
        return is_array($data) ? $data : array();
    }

    public function get_last_store_profile()
    {
        $file = $this->get_store_profile_last_file();
        if (!is_readable($file)) {
            return array();
        }

        $json = ultracache_safe_file_get_contents($file);
        $data = json_decode((string) $json, true);
        return is_array($data) ? $data : array();
    }

    public function clear_last_store_profile()
    {
        $file = $this->get_store_profile_last_file();
        if (file_exists($file)) {
            ultracache_safe_unlink($file);
        }
        return !file_exists($file);
    }

    private function initialize_store_profile($html, $initial_stage = 'original_wordpress_html')
    {
        if (!$this->is_store_profiler_enabled()) {
            return;
        }

        if (!empty($this->store_profile)) {
            return;
        }

        $html = is_string($html) ? $html : (string) $html;
        $initial_stage = sanitize_key((string) $initial_stage);
        if ('' === $initial_stage) {
            $initial_stage = 'original_wordpress_html';
        }

        $this->store_profile_started_at = microtime(true);
        $request_id = gmdate('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 10);
        $this->store_profile = array(
            'label' => __('ULTRACACHE STORE PROFILE', 'ultracache'),
            'version' => defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : '',
            'request_id' => $request_id,
            'profile_run_id' => $this->get_store_profile_run_id(),
            'url' => $this->get_current_request_url(),
            'bucket' => $this->get_request_image_bucket(),
            'started_at_utc' => gmdate('c'),
            'started_at_site' => function_exists('current_time') ? current_time('mysql') : '',
            'request_profile' => array(
                'request_started_at_ms' => 0,
                'checkpoints' => $this->get_store_profile_request_checkpoints(),
            ),
            'settings_snapshot' => $this->get_store_profile_settings_snapshot(),
            'css_bundle_context' => $this->get_store_profile_css_bundle_context(),
            'stages' => array(),
        );

        $counts = $this->collect_store_profile_html_counts($html);
        $this->store_profile['stages'][] = array_merge(array(
            'stage' => $initial_stage,
            'bytes_in' => (int) strlen($html),
            'bytes_out' => (int) strlen($html),
            'delta_bytes' => 0,
            'duration_ms' => 0,
        ), $counts);
    }

    private function start_store_profile($html)
    {
        if (!$this->is_store_profiler_enabled()) {
            return;
        }

        $this->profile_request_checkpoint('store_profile_start', array('html_bytes' => is_string($html) ? strlen($html) : 0));
        $this->initialize_store_profile($html, 'original_wordpress_html');
    }

    private function start_store_profile_diagnostic_skip($reason)
    {
        if (!$this->is_store_profiler_enabled()) {
            return;
        }

        $reason = sanitize_key((string) $reason);
        $this->profile_request_checkpoint('store_profile_diagnostic_skip_start', array('reason' => $reason));
        $this->initialize_store_profile('', 'diagnostic_skip_' . ('' !== $reason ? $reason : 'unknown'));
    }

    private function profile_store_stage($stage, $html, callable $callback)
    {
        if (!$this->is_store_profiler_enabled()) {
            return $callback($html);
        }

        $before = is_string($html) ? $html : (string) $html;
        $before_bytes = strlen($before);
        $start = microtime(true);
        $result = $callback($html);
        $duration_ms = (int) round((microtime(true) - $start) * 1000);

        if (!is_string($result)) {
            $result = $html;
        }

        $after = (string) $result;
        $after_bytes = strlen($after);
        $this->store_profile['stages'][] = array_merge(array(
            'stage' => sanitize_key((string) $stage),
            'bytes_in' => (int) $before_bytes,
            'bytes_out' => (int) $after_bytes,
            'delta_bytes' => (int) ($after_bytes - $before_bytes),
            'duration_ms' => $duration_ms,
        ), $this->collect_store_profile_html_counts($after));

        return $result;
    }

    private function profile_store_event($stage, $html, callable $callback)
    {
        if (!$this->is_store_profiler_enabled()) {
            return $callback($html);
        }

        $before = is_string($html) ? $html : (string) $html;
        $before_bytes = strlen($before);
        $start = microtime(true);
        $result = $callback($html);
        $duration_ms = (int) round((microtime(true) - $start) * 1000);
        $after = is_string($html) ? $html : (string) $html;
        $after_bytes = strlen($after);

        $this->store_profile['stages'][] = array_merge(array(
            'stage' => sanitize_key((string) $stage),
            'bytes_in' => (int) $before_bytes,
            'bytes_out' => (int) $after_bytes,
            'delta_bytes' => (int) ($after_bytes - $before_bytes),
            'duration_ms' => $duration_ms,
            'result' => is_bool($result) ? ($result ? 'true' : 'false') : (is_scalar($result) ? (string) $result : gettype($result)),
        ), $this->collect_store_profile_html_counts($after));

        return $result;
    }

    private function sum_store_profile_page_css_inline_bytes($html)
    {
        if (!is_string($html) || '' === $html) {
            return 0;
        }

        $bytes = 0;
        $offset = 0;
        $needle = 'data-ultracache-page-css-bundle';
        while (false !== ($style_start = stripos($html, '<style', $offset))) {
            $tag_end = strpos($html, '>', $style_start);
            if (false === $tag_end) {
                break;
            }

            $open_tag = substr($html, $style_start, $tag_end - $style_start + 1);
            $close_tag = stripos($html, '</style>', $tag_end + 1);
            if (false === $close_tag) {
                break;
            }

            if (false !== stripos($open_tag, $needle)) {
                $bytes += max(0, $close_tag - $tag_end - 1);
            }

            $offset = $close_tag + 8;
        }

        return (int) $bytes;
    }

    private function count_store_profile_regex($pattern, $html)
    {
        if (!is_string($html) || '' === $html) {
            return 0;
        }

        $count = preg_match_all($pattern, $html, $matches);
        return is_int($count) ? (int) $count : 0;
    }

    private function get_style_critical_request_candidate($tag, $offset, $head_end, array $settings = array())
    {
        $href = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag, 'href'), ENT_QUOTES | ENT_HTML5);
        if ('' === $href) {
            return array();
        }

        $media = strtolower(trim((string) $this->extract_attribute_from_html_tag($tag, 'media')));
        $is_print = (false !== strpos($media, 'print') || false !== strpos($media, 'speech'));
        $async_marker = (bool) preg_match('/\b(?:disabled|onload|data-ultracache-async-css|data-ultracache-page-css-bundle-fallback)\b/i', (string) $tag);
        $render_blocking = (!$is_print && !$async_marker);
        $origin = $this->get_public_resource_origin_type($href);
        $path = $this->get_public_resource_path_fragment($href);
        $is_bundle = ultracache_generated_asset_reference_matches($href, array('css-bundles'));
        $slider_fragment = !empty($settings['slider_safe_mode']) ? $this->get_matching_fragment('', $href, $tag, $this->get_slider_hero_protected_fragments()) : '';
        $bytes = 0;
        $local_path = $this->resolve_local_path_from_public_url($href);
        if ('' !== $local_path && is_readable($local_path)) {
            $bytes = (int) filesize($local_path);
        }

        $status = $render_blocking ? 'render-blocking' : 'non-blocking';
        $reason = $render_blocking ? 'stylesheet link without async/print/deferred marker' : 'async/deferred/print stylesheet';
        $suggested = 'Review before changing.';
        $protected = false;
        $protected_reason = '';
        if ($is_bundle) {
            $reason = 'external UltraCache CSS bundle is render-blocking';
            $suggested = 'Candidate for critical CSS split or deferred non-critical bundle mode.';
        } elseif ('' !== $slider_fragment) {
            $protected = true;
            $protected_reason = 'slider/hero stylesheet fragment: ' . $slider_fragment;
            $reason = $protected_reason;
            $suggested = 'Keep blocking if this slider/hero CSS is needed above the fold.';
        } elseif ($render_blocking && $bytes > 0 && $bytes <= 8192) {
            $suggested = 'Small stylesheet: candidate to fold into a critical/vendor CSS group.';
        } elseif ($render_blocking) {
            $suggested = 'Candidate for async CSS or bundle review after visual testing.';
        }

        return array(
            'type' => 'style',
            'url' => $href,
            'path' => $path,
            'origin' => $origin,
            'location' => $this->get_html_offset_location($offset, $head_end),
            'renderBlocking' => (bool) $render_blocking,
            'delayed' => false,
            'protected' => (bool) $protected,
            'protectedReason' => $protected_reason,
            'status' => $status,
            'reason' => $reason,
            'suggestedAction' => $suggested,
            'bytes' => $bytes,
            'isBundle' => (bool) $is_bundle,
        );
    }

    private function collect_store_profile_critical_request_chain($html)
    {
        $html = is_string($html) ? $html : (string) $html;
        $settings = $this->get_settings();
        $head_end = stripos($html, '</head>');
        $head_end = false === $head_end ? -1 : (int) $head_end;
        $noscript_ranges = $this->get_html_tag_ranges_by_name($html, 'noscript');

        $styles = array();
        $scripts = array();
        $render_blocking_styles = 0;
        $render_blocking_scripts = 0;
        $delayed_scripts = 0;
        $protected_scripts = 0;
        $protected_styles = 0;

        if ('' !== $html && preg_match_all('/<link\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ((array) $matches[0] as $match) {
                $tag = isset($match[0]) ? (string) $match[0] : '';
                $offset = isset($match[1]) ? (int) $match[1] : 0;
                if ($this->is_html_offset_inside_ranges($offset, $noscript_ranges)) {
                    continue;
                }
                if ('' === $tag || !$this->html_tag_rel_contains_stylesheet($tag)) {
                    continue;
                }
                $item = $this->get_style_critical_request_candidate($tag, $offset, $head_end, $settings);
                if (empty($item)) {
                    continue;
                }
                if (!empty($item['renderBlocking'])) {
                    $render_blocking_styles++;
                }
                if (!empty($item['protected'])) {
                    $protected_styles++;
                }
                if (!empty($item['renderBlocking']) || !empty($item['protected']) || count($styles) < 20) {
                    $styles[] = $item;
                }
                if (count($styles) >= 40) {
                    break;
                }
            }
        }

        if ('' !== $html && preg_match_all('/<script\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ((array) $matches[0] as $match) {
                $tag = isset($match[0]) ? (string) $match[0] : '';
                $offset = isset($match[1]) ? (int) $match[1] : 0;
                if ('' === $tag) {
                    continue;
                }
                $item = $this->get_script_critical_request_candidate($tag, $offset, $head_end, $settings);
                if (empty($item)) {
                    continue;
                }
                if (!empty($item['renderBlocking'])) {
                    $render_blocking_scripts++;
                }
                if (!empty($item['delayed'])) {
                    $delayed_scripts++;
                }
                if (!empty($item['protected'])) {
                    $protected_scripts++;
                }
                if (!empty($item['renderBlocking']) || !empty($item['protected']) || !empty($item['delayed']) || count($scripts) < 20) {
                    $scripts[] = $item;
                }
                if (count($scripts) >= 60) {
                    break;
                }
            }
        }

        return array(
            'available' => true,
            'render_blocking_style_count' => (int) $render_blocking_styles,
            'render_blocking_script_count' => (int) $render_blocking_scripts,
            'delayed_script_count' => (int) $delayed_scripts,
            'protected_script_count' => (int) $protected_scripts,
            'protected_style_count' => (int) $protected_styles,
            'style_candidates' => array_slice($styles, 0, 40),
            'script_candidates' => array_slice($scripts, 0, 60),
        );
    }

    private function collect_store_profile_render_blocking_css_counts($html)
    {
        $html = is_string($html) ? $html : (string) $html;
        $result = array(
            'render_blocking_stylesheet_links' => 0,
            'render_blocking_css_bundle_links' => 0,
            'render_blocking_non_bundle_stylesheet_links' => 0,
            'render_blocking_stylesheet_hrefs' => array(),
            'render_blocking_non_bundle_stylesheet_hrefs' => array(),
        );

        $noscript_ranges = $this->get_html_tag_ranges_by_name($html, 'noscript');
        if ('' === $html || !preg_match_all('/<link\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return $result;
        }

        foreach ((array) $matches[0] as $match) {
            $tag_html = isset($match[0]) ? (string) $match[0] : '';
            $offset = isset($match[1]) ? (int) $match[1] : 0;
            if ($this->is_html_offset_inside_ranges($offset, $noscript_ranges)) {
                continue;
            }
            if (!$this->html_tag_rel_contains_stylesheet($tag_html)) {
                continue;
            }
            if (preg_match('/\b(?:disabled|onload|data-ultracache-async-css|data-ultracache-page-css-bundle-fallback)\b/i', $tag_html)) {
                continue;
            }

            $media = strtolower(trim((string) $this->extract_attribute_from_html_tag($tag_html, 'media')));
            if (false !== strpos($media, 'print') || false !== strpos($media, 'speech')) {
                continue;
            }

            $href = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag_html, 'href'), ENT_QUOTES | ENT_HTML5);
            $result['render_blocking_stylesheet_links']++;
            if ('' !== $href && count($result['render_blocking_stylesheet_hrefs']) < 20) {
                $result['render_blocking_stylesheet_hrefs'][] = $href;
            }

            if (ultracache_generated_asset_reference_matches($href, array('css-bundles'))) {
                $result['render_blocking_css_bundle_links']++;
            } else {
                $result['render_blocking_non_bundle_stylesheet_links']++;
                if ('' !== $href && count($result['render_blocking_non_bundle_stylesheet_hrefs']) < 20) {
                    $result['render_blocking_non_bundle_stylesheet_hrefs'][] = $href;
                }
            }
        }

        return $result;
    }

    private function collect_store_profile_html_counts($html)
    {
        $html = is_string($html) ? $html : (string) $html;
        $render_blocking_css = $this->collect_store_profile_render_blocking_css_counts($html);

        return array_merge($render_blocking_css, array(
            'link_tags' => $this->count_store_profile_regex('/<link\b/i', $html),
            'stylesheet_links' => $this->count_store_profile_regex('/<link\b(?=[^>]*\brel\s*=)[^>]*stylesheet[^>]*>/i', $html),
            'script_tags' => $this->count_store_profile_regex('/<script\b/i', $html),
            'noscript_tags' => $this->count_store_profile_regex('/<noscript\b/i', $html),
            'fonts_googleapis_refs' => $this->count_store_profile_regex('/fonts\.googleapis\.com/i', $html),
            'local_google_fonts_refs' => $this->count_store_profile_regex('#uploads/ultracache/google-fonts#i', $html),
            'css_bundle_refs' => $this->count_store_profile_regex('#uploads/ultracache/css-bundles#i', $html),
            'page_css_bundle_markers' => $this->count_store_profile_regex('/\bdata-ultracache-page-css-bundle\s*=/i', $html),
            'page_css_bundle_external_links' => $this->count_store_profile_regex('/<link\b(?=[^>]*\bdata-ultracache-page-css-bundle\s*=)[^>]*>/i', $html),
            'page_css_bundle_inline_style_tags' => $this->count_store_profile_regex('/<style\b(?=[^>]*\bdata-ultracache-page-css-bundle\s*=)[^>]*>/i', $html),
            'page_css_bundle_inline_style_bytes' => $this->sum_store_profile_page_css_inline_bytes($html),
            'page_css_bundle_fallback_markers' => $this->count_store_profile_regex('/\bdata-ultracache-page-css-bundle-fallback\s*=/i', $html),
            'page_css_bundle_fallback_blocks' => $this->count_store_profile_regex('/\bdata-ultracache-page-css-bundle-fallback-block\s*=/i', $html),
            'page_css_bundle_fallback_links' => $this->count_store_profile_regex('/<link\b(?=[^>]*\bdata-ultracache-page-css-bundle-fallback\s*=)[^>]*>/i', $html),
            'leftover_css_bundle_refs' => $this->count_store_profile_regex('#uploads/ultracache/css-bundles#i', $html),
            'leftover_css_bundle_markers' => $this->count_store_profile_regex('/\bdata-ultracache-leftover-css-bundle\s*=/i', $html),
            'frontpage_css_bundle_markers' => $this->count_store_profile_regex('/\bdata-ultracache-frontpage-css\s*=/i', $html),
            'async_css_markers' => $this->count_store_profile_regex('/\bdata-ultracache-async-css\s*=/i', $html),
            'async_css_fallback_markers' => $this->count_store_profile_regex('/\bdata-ultracache-async-css-fallback\s*=/i', $html),
            'delayed_js_markers' => $this->count_store_profile_regex('/text\/ultracache-delayed-js/i', $html),
            'data_ultracache_src_markers' => $this->count_store_profile_regex('/\bdata-ultracache-src\s*=/i', $html),
            'lcp_priority_markers' => $this->count_store_profile_regex('/\bfetchpriority\s*=\s*["\']high/i', $html),
        ));
    }

    private function defer_store_post_response_action($type, array $payload = array())
    {
        $this->deferred_store_post_response_actions[] = array(
            'type' => sanitize_key((string) $type),
            'payload' => $payload,
        );
    }

    public function run_deferred_store_post_response_actions()
    {
        if (empty($this->deferred_store_post_response_actions)) {
            return;
        }

        $actions = $this->deferred_store_post_response_actions;
        $this->deferred_store_post_response_actions = array();

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $type = isset($action['type']) ? sanitize_key((string) $action['type']) : '';
            $payload = isset($action['payload']) && is_array($action['payload']) ? $action['payload'] : array();

            if ('store_success' === $type) {
                $url = isset($payload['url']) ? (string) $payload['url'] : '';
                $file_path = isset($payload['file_path']) ? (string) $payload['file_path'] : '';

                $this->record_analytics_store();
                $this->record_cache_event('store', array('url' => $url, 'file' => $file_path));
                $this->finalize_store_profile('STORE', '', $file_path);
            }
        }
    }

    public function finalize_missing_output_buffer_store_profile()
    {
        if (!$this->is_store_profiler_enabled()) {
            return;
        }

        if (!$this->buffering || !$this->template_enhancement_buffer_required) {
            return;
        }

        if (!empty($this->store_profile)) {
            return;
        }

        $reason = 'output-buffer-callback-not-run';
        $this->profile_request_checkpoint('output_buffer_callback_missing', array(
            'buffering' => $this->buffering ? 'yes' : 'no',
            'template_buffer_required' => $this->template_enhancement_buffer_required ? 'yes' : 'no',
            'template_buffer_started' => $this->template_enhancement_buffer_started ? 'yes' : 'no',
        ));
        $this->start_store_profile_diagnostic_skip($reason);
        $this->finalize_store_profile('SKIP', $reason, '');
        $this->buffering = false;
        $this->template_enhancement_buffer_required = false;
    }

    public function update_store_profile_after_shutdown()
    {
        if (!$this->is_store_profiler_enabled() || empty($this->store_profile) || $this->store_profile_shutdown_written) {
            return;
        }

        $this->profile_request_checkpoint('engine_shutdown_profile_update');
        $now_for_profile = microtime(true);
        $request_start_for_profile = $this->get_store_profile_request_started_at();
        $total_request_ms = (int) round(max(0, $now_for_profile - $request_start_for_profile) * 1000);
        $total_ms = $this->store_profile_started_at > 0 ? (int) round(($now_for_profile - $this->store_profile_started_at) * 1000) : 0;
        $merged_checkpoints = $this->get_store_profile_request_checkpoints();

        $this->store_profile['total_request_duration_ms'] = $total_request_ms;
        $this->store_profile['shutdown_updated_at_utc'] = gmdate('c');
        $this->store_profile['shutdown_total_duration_ms'] = $total_ms;
        $this->store_profile['peak_memory_bytes'] = function_exists('memory_get_peak_usage') ? (int) memory_get_peak_usage(true) : (int) ($this->store_profile['peak_memory_bytes'] ?? 0);
        $this->store_profile['request_profile'] = array(
            'request_started_at_ms' => 0,
            'mode' => (function_exists('ultracache_request_profile_verbose_enabled') && ultracache_request_profile_verbose_enabled()) ? 'verbose' : 'compact',
            'total_request_duration_ms' => $total_request_ms,
            'unmeasured_before_store_profile_ms' => max(0, $total_request_ms - $total_ms),
            'checkpoints' => $merged_checkpoints,
            'phase_summary' => $this->get_store_profile_request_phase_summary($merged_checkpoints),
            'callback_timings' => $this->get_store_profile_callback_timings(),
            'callback_timing_summary' => $this->get_store_profile_callback_timing_summary(),
        );

        if ($this->write_store_profile_json('store_profile_shutdown_write')) {
            $this->store_profile_shutdown_written = true;
        }
    }

    private function finalize_store_profile($status, $reason = '', $file_path = '')
    {
        if (!$this->is_store_profiler_enabled() || empty($this->store_profile)) {
            return;
        }

        $this->profile_request_checkpoint('store_profile_finalize_start', array('status' => strtoupper((string) $status)));
        $now_for_profile = microtime(true);
        $request_start_for_profile = $this->get_store_profile_request_started_at();
        $total_request_ms = (int) round(max(0, $now_for_profile - $request_start_for_profile) * 1000);
        $total_ms = $this->store_profile_started_at > 0 ? (int) round(($now_for_profile - $this->store_profile_started_at) * 1000) : 0;
        $this->store_profile['status'] = strtoupper((string) $status);
        $this->store_profile['reason'] = (string) $reason;
        $this->store_profile['cache_file'] = (string) $file_path;
        $this->store_profile['total_duration_ms'] = $total_ms;
        $this->store_profile['peak_memory_bytes'] = function_exists('memory_get_peak_usage') ? (int) memory_get_peak_usage(true) : 0;
        $this->store_profile['finished_at_utc'] = gmdate('c');
        $this->store_profile['total_request_duration_ms'] = $total_request_ms;
        $merged_checkpoints = $this->get_store_profile_request_checkpoints();
        $this->store_profile['request_profile'] = array(
            'request_started_at_ms' => 0,
            'mode' => (function_exists('ultracache_request_profile_verbose_enabled') && ultracache_request_profile_verbose_enabled()) ? 'verbose' : 'compact',
            'total_request_duration_ms' => $total_request_ms,
            'unmeasured_before_store_profile_ms' => max(0, $total_request_ms - $total_ms),
            'checkpoints' => $merged_checkpoints,
            'phase_summary' => $this->get_store_profile_request_phase_summary($merged_checkpoints),
            'callback_timings' => $this->get_store_profile_callback_timings(),
            'callback_timing_summary' => $this->get_store_profile_callback_timing_summary(),
        );
        $this->store_profile['css_bundle_context_after'] = $this->get_store_profile_css_bundle_context();
        $critical_request_html = '';
        if ('' !== (string) $file_path && is_readable((string) $file_path)) {
            $critical_request_html = ultracache_safe_file_get_contents((string) $file_path);
        }
        $this->store_profile['critical_request_chain'] = $this->collect_store_profile_critical_request_chain(is_string($critical_request_html) ? $critical_request_html : '');
        $this->store_profile['js_delay_safety_scan'] = $this->collect_store_profile_js_delay_safety_scan(is_string($critical_request_html) ? $critical_request_html : '');

        $largest_delta = array('stage' => '', 'delta_bytes' => 0);
        $slowest = array('stage' => '', 'duration_ms' => 0);
        foreach ((array) ($this->store_profile['stages'] ?? array()) as $stage) {
            $delta = isset($stage['delta_bytes']) ? (int) $stage['delta_bytes'] : 0;
            $duration = isset($stage['duration_ms']) ? (int) $stage['duration_ms'] : 0;
            if ($delta > (int) $largest_delta['delta_bytes']) {
                $largest_delta = array('stage' => (string) ($stage['stage'] ?? ''), 'delta_bytes' => $delta);
            }
            if ($duration > (int) $slowest['duration_ms']) {
                $slowest = array('stage' => (string) ($stage['stage'] ?? ''), 'duration_ms' => $duration);
            }
        }
        $this->store_profile['largest_positive_delta'] = $largest_delta;
        $this->store_profile['slowest_stage'] = $slowest;

        $write_ok = $this->write_store_profile_json('store_profile_write');

        if (!headers_sent()) {
            $status = strtoupper((string) $status);
            $reason_header = sanitize_key((string) $reason);
            header('X-Ultra-Cache-Store-Profile: ' . ($write_ok ? 'saved' : 'skipped'));
            header('X-Ultra-Cache-Store-Profile-Status: ' . $status);
            if ('' !== $reason_header || !$write_ok) {
                header('X-Ultra-Cache-Store-Profile-Reason: ' . ('' !== $reason_header ? $reason_header : 'profile-write-failed'));
            }
            header('X-Ultra-Cache-Store-Profile-Id: ' . substr((string) ($this->store_profile['request_id'] ?? ''), 0, 40));
            if (!empty($this->store_profile['profile_run_id'])) {
                header('X-Ultra-Cache-Store-Profile-Run: ' . substr((string) $this->store_profile['profile_run_id'], 0, 64));
            }
            header('X-Ultra-Cache-Store-Profile-Slowest: ' . substr((string) $slowest['stage'] . ':' . (string) $slowest['duration_ms'] . 'ms', 0, 120));
            header('X-Ultra-Cache-Store-Profile-Largest-Delta: ' . substr((string) $largest_delta['stage'] . ':' . (string) $largest_delta['delta_bytes'] . 'b', 0, 120));
        }
    }

    private function record_store_profile_async_css_diagnostics(array $stats)
    {
        if (!$this->is_store_profiler_enabled() || empty($this->store_profile)) {
            return;
        }

        $settings = $this->get_settings();
        $items = isset($stats['items']) && is_array($stats['items']) ? array_slice($stats['items'], 0, 80) : array();
        $reasons = isset($stats['reasons']) && is_array($stats['reasons']) ? $stats['reasons'] : array();
        arsort($reasons);

        $this->store_profile['async_css_diagnostics'] = array(
            'available' => true,
            'enabled' => !empty($settings['async_css']),
            'aggressive_enabled' => !empty($settings['aggressive_async_css']),
            'safe' => !isset($stats['safe']) || !empty($stats['safe']),
            'scanned' => isset($stats['scanned']) ? (int) $stats['scanned'] : 0,
            'rewritten' => isset($stats['rewritten']) ? (int) $stats['rewritten'] : 0,
            'skipped' => isset($stats['skipped']) ? (int) $stats['skipped'] : 0,
            'unresolved' => isset($stats['unresolved']) ? (int) $stats['unresolved'] : 0,
            'reason_counts' => $reasons,
            'items' => $items,
        );
    }

}
