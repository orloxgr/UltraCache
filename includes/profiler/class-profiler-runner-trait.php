<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Profiler_Runner_Trait
{
    private function index_profile_checkpoints_by_stage(array $checkpoints)
    {
        $indexed = array();
        foreach ($checkpoints as $checkpoint) {
            if (!is_array($checkpoint) || empty($checkpoint['stage'])) {
                continue;
            }
            $stage = sanitize_key((string) $checkpoint['stage']);
            if ('' === $stage || isset($indexed[$stage])) {
                continue;
            }
            $indexed[$stage] = isset($checkpoint['at_ms']) ? (int) $checkpoint['at_ms'] : 0;
        }

        return $indexed;
    }

    private function profile_checkpoint_interval(array $by_stage, $label, $start_stage, $end_stage, $description = '')
    {
        $start_stage = sanitize_key((string) $start_stage);
        $end_stage = sanitize_key((string) $end_stage);
        if (!isset($by_stage[$start_stage]) || !isset($by_stage[$end_stage])) {
            return array();
        }

        return array(
            'label' => (string) $label,
            'startStage' => $start_stage,
            'endStage' => $end_stage,
            'durationMs' => max(0, (int) $by_stage[$end_stage] - (int) $by_stage[$start_stage]),
            'description' => (string) $description,
        );
    }

    private function build_ultracache_overhead_probe(array $checkpoints)
    {
        $by_stage = $this->index_profile_checkpoints_by_stage($checkpoints);
        $items = array();
        $add = function ($label, $start, $end, $description = '') use (&$items, $by_stage) {
            $item = $this->profile_checkpoint_interval($by_stage, $label, $start, $end, $description);
            if (!empty($item)) {
                $items[] = $item;
            }
        };

        $maybe_end = '';
        foreach (array('buffer_start', 'bypass_selected', 'early_hit_served', 'maybe_start_buffering_reentry_return') as $candidate) {
            if (isset($by_stage[$candidate])) {
                $maybe_end = $candidate;
                break;
            }
        }
        if ('' !== $maybe_end) {
            $add('maybe_start_buffering total', 'maybe_start_buffering_start', $maybe_end, 'Total UltraCache template_redirect entry work before buffering, bypass, or early HIT.');
        $add('Reentry guard', 'maybe_start_buffering_before_reentry_check', 'maybe_start_buffering_after_reentry_check', 'Checks whether UltraCache buffering already started.');
        $add('Pre-bypass setup', 'maybe_start_buffering_after_reentry_check', 'maybe_start_buffering_before_should_bypass', 'Template_redirect setup before cacheability checks.');
        $add('Early HIT lookup total', 'early_hit_check_start', 'early_hit_check_end', 'Complete WordPress-level early HIT lookup when no HIT is served.');
        $add('Early HIT miss path', 'early_hit_check_start', 'early_hit_no_file_return', 'Early HIT lookup until no readable cache file is found.');
        $add('Page generation lock total', 'page_generation_lock_before', 'page_generation_lock_checked', 'Page generation stampede lock path.');
        $add('Page lock URL build', 'page_generation_lock_before_current_url', 'page_generation_lock_after_current_url', 'Builds current URL for page generation lock.');
        $add('Page lock cache path', 'page_generation_lock_before_cache_path', 'page_generation_lock_after_cache_path', 'Maps current URL to cache file for the lock.');
        $add('Analytics MISS record', 'record_analytics_miss_start', 'record_analytics_miss_end', 'Records WordPress-level MISS analytics before starting output buffering.');
        $add('MISS debug headers', 'send_debug_headers_start', 'send_debug_headers_end', 'Sends UltraCache MISS/debug headers before output buffering.');
        $add('Buffer start tail', 'send_debug_headers_end', 'buffer_start', 'Final tail before template enhancement output buffering.');
        }

        $add('should_bypass_cache total', 'should_bypass_start', 'should_bypass_return', 'Cacheability decision time. If high, inspect the sub-steps below.');
        $add('Settings read', 'should_bypass_before_get_settings', 'should_bypass_after_get_settings', 'Reads UltraCache settings.');
        $add('Basic request checks', 'should_bypass_before_basic_checks', 'should_bypass_after_basic_checks', 'DONOTCACHEPAGE, admin, feed, preview, method checks.');
        $add('Internal revalidate check', 'should_bypass_before_internal_revalidate', 'should_bypass_after_internal_revalidate', 'Checks internal refresh request markers.');
        $add('WooCommerce dynamic check', 'should_bypass_before_woocommerce_dynamic', 'should_bypass_after_woocommerce_dynamic', 'Cart/checkout/account and dynamic WooCommerce request checks.');
        $add('Logged-in user check', 'should_bypass_before_user_check', 'should_bypass_after_user_check', 'Logged-in bypass check.');
        $add('Cookie bypass scan', 'should_bypass_before_cookie_checks', 'should_bypass_after_cookie_checks', 'Scans cookies that force bypass.');
        $add('Current URL build', 'should_bypass_before_current_url', 'should_bypass_after_current_url', 'Builds the current normalized request URL.');
        $add('Local URL validation', 'should_bypass_before_local_url_check', 'should_bypass_after_local_url_check', 'Confirms the request belongs to the current site.');
        $add('URL parse/query strip', 'should_bypass_before_url_parse', 'should_bypass_after_url_parse', 'Parses path/query and strips diagnostic query args.');
        $add('Excluded path rules', 'should_bypass_before_excluded_path_rules', 'should_bypass_after_excluded_path_rules', 'Matches request path against visible cache exclusions.');
        $add('Excluded query args', 'should_bypass_before_excluded_query_args', 'should_bypass_after_excluded_query_args', 'Matches query args against excluded query-string args.');
        $add('Query allowlist build', 'should_bypass_before_query_allowlist', 'should_bypass_after_query_allowlist', 'Builds the query-string args allowlist.');
        $add('Query variant check', 'should_bypass_before_query_variant', 'should_bypass_after_query_variant', 'Checks whether the query string is cacheable.');
        $add('Early HIT URL build', 'early_hit_before_current_url', 'early_hit_after_current_url', 'Builds URL for WordPress-level early HIT lookup.');
        $add('Early HIT cache path', 'early_hit_before_cache_path', 'early_hit_after_cache_path', 'Maps URL to page-cache file path.');
        $add('Early HIT file stat', 'early_hit_before_file_stat', 'early_hit_after_file_stat', 'Checks whether the cached HTML file exists/readable.');
        $add('Early HIT file read', 'early_hit_before_file_read', 'early_hit_after_file_read', 'Reads cached HTML before serving HIT.');
        $add('Early HIT CSS ref validation', 'early_hit_before_css_ref_validation', 'early_hit_after_css_ref_validation', 'Validates cached HTML does not reference missing CSS bundle files.');
        $add('CSS bundle ref scan', 'css_bundle_ref_validation_before_scan', 'css_bundle_ref_validation_after_scan', 'Scans cached HTML for missing css-bundles references.');
        $add('Page generation lock acquire', 'page_generation_lock_acquire_start', 'page_generation_lock_acquired', 'Acquires page generation/stampede lock.');
        $add('Page generation lock wait', 'page_generation_lock_wait_start', 'page_generation_lock_wait_timeout', 'Waits for another worker to finish generating the cache file.');
        $add('Cache output callback total', 'cache_output_callback_start', 'cache_output_callback_end', 'HTML rewrite and cache store work inside the output buffer callback.');
        $add('Diagnostic fallback output buffer', 'diagnostic_fallback_output_buffer_started', 'diagnostic_fallback_output_buffer_callback', 'Captures final HTML for profiled diagnostic requests when the WordPress template output-buffer filter does not finish.');
        $add('Output buffer callback missing', 'buffer_start', 'output_buffer_callback_missing', 'UltraCache requested template output buffering, but the final STORE callback did not run before shutdown.');

        usort($items, function ($a, $b) {
            return (int) ($b['durationMs'] ?? 0) <=> (int) ($a['durationMs'] ?? 0);
        });

        $top_deltas = array();
        foreach ($checkpoints as $checkpoint) {
            if (!is_array($checkpoint) || empty($checkpoint['stage'])) {
                continue;
            }
            $stage = (string) $checkpoint['stage'];
            if (!preg_match('/^(maybe_start_buffering|should_bypass|early_hit|page_generation|record_analytics_miss|send_debug_headers|buffer_start|diagnostic_fallback_output_buffer|cache_output_callback|output_buffer_callback_missing|css_bundle_ref_validation)/', $stage)) {
                continue;
            }
            $delta = isset($checkpoint['since_previous_ms']) ? (int) $checkpoint['since_previous_ms'] : 0;
            if ($delta < 2) {
                continue;
            }
            $top_deltas[] = array(
                'stage' => $stage,
                'deltaMs' => $delta,
                'atMs' => isset($checkpoint['at_ms']) ? (int) $checkpoint['at_ms'] : 0,
                'reason' => isset($checkpoint['reason']) ? (string) $checkpoint['reason'] : '',
            );
        }
        usort($top_deltas, function ($a, $b) {
            return (int) ($b['deltaMs'] ?? 0) <=> (int) ($a['deltaMs'] ?? 0);
        });

        $maybe_total = 0;
        $should_bypass_total = 0;
        $cache_output_total = 0;
        foreach ($items as $item) {
            if ('maybe_start_buffering total' === (string) ($item['label'] ?? '')) {
                $maybe_total = (int) ($item['durationMs'] ?? 0);
            }
            if ('should_bypass_cache total' === (string) ($item['label'] ?? '')) {
                $should_bypass_total = (int) ($item['durationMs'] ?? 0);
            }
            if ('Cache output callback total' === (string) ($item['label'] ?? '')) {
                $cache_output_total = (int) ($item['durationMs'] ?? 0);
            }
        }

        return array(
            'available' => !empty($items) || !empty($top_deltas),
            'maybeStartBufferingMs' => $maybe_total,
            'shouldBypassMs' => $should_bypass_total,
            'cacheOutputCallbackMs' => $cache_output_total,
            'slowItems' => array_slice($items, 0, 16),
            'topCheckpointDeltas' => array_slice($top_deltas, 0, 16),
        );
    }

    private function build_css_link_duplication_diagnostics(array $style_candidates)
    {
        $groups = array();
        foreach ($style_candidates as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = isset($item['url']) ? (string) $item['url'] : (isset($item['path']) ? (string) $item['path'] : '');
            if ('' === $url) {
                continue;
            }
            $key = preg_replace('/[?#].*$/', '', strtolower($url));
            if ('' === $key) {
                $key = strtolower($url);
            }
            if (!isset($groups[$key])) {
                $groups[$key] = array(
                    'url' => $url,
                    'count' => 0,
                    'renderBlockingCount' => 0,
                    'nonBlockingCount' => 0,
                    'protectedCount' => 0,
                    'statuses' => array(),
                );
            }
            $groups[$key]['count']++;
            if (!empty($item['renderBlocking'])) {
                $groups[$key]['renderBlockingCount']++;
            } else {
                $groups[$key]['nonBlockingCount']++;
            }
            if (!empty($item['protected'])) {
                $groups[$key]['protectedCount']++;
            }
            $status = isset($item['status']) ? (string) $item['status'] : (!empty($item['renderBlocking']) ? 'render-blocking' : 'non-blocking');
            if ('' !== $status) {
                $groups[$key]['statuses'][$status] = true;
            }
        }

        $items = array();
        foreach ($groups as $group) {
            $count = (int) $group['count'];
            $mixed = (int) $group['renderBlockingCount'] > 0 && (int) $group['nonBlockingCount'] > 0;
            $interesting = $count > 1 || $mixed;
            if (!$interesting) {
                continue;
            }
            $statuses = array_keys((array) $group['statuses']);
            $items[] = array(
                'url' => (string) $group['url'],
                'count' => $count,
                'renderBlockingCount' => (int) $group['renderBlockingCount'],
                'nonBlockingCount' => (int) $group['nonBlockingCount'],
                'protectedCount' => (int) $group['protectedCount'],
                'mixedBlockingStatus' => $mixed,
                'statuses' => $statuses,
                'suggestedAction' => $mixed ? 'Verify whether the same stylesheet is emitted once as non-blocking and once as blocking.' : 'Review whether duplicate stylesheet links are intentional.',
            );
        }
        usort($items, function ($a, $b) {
            if (!empty($a['mixedBlockingStatus']) !== !empty($b['mixedBlockingStatus'])) {
                return !empty($a['mixedBlockingStatus']) ? -1 : 1;
            }
            return (int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0);
        });

        return array(
            'available' => true,
            'duplicateCount' => count(array_filter($items, function ($item) { return isset($item['count']) && (int) $item['count'] > 1; })),
            'mixedStatusCount' => count(array_filter($items, function ($item) { return !empty($item['mixedBlockingStatus']); })),
            'items' => array_slice($items, 0, 20),
        );
    }
    private function build_frontend_rewrite_stage_breakdown(array $stages)
    {
        $items = array();
        $total_ms = 0;
        foreach ($stages as $stage) {
            if (!is_array($stage)) {
                continue;
            }
            $name = isset($stage['stage']) ? sanitize_key((string) $stage['stage']) : '';
            if ('' === $name) {
                continue;
            }
            $duration = isset($stage['duration_ms']) ? (int) $stage['duration_ms'] : 0;
            if ('frontend_performance_optimizations_total' === $name) {
                $total_ms = max($total_ms, $duration);
                continue;
            }
            if (in_array($name, array(
                'original_wordpress_html',
                'final_cache_write',
                'final_google_fonts_rewrite_before_skip_check',
                'final_google_fonts_rewrite_inside_write',
                'store_success_deferred_actions',
            ), true)) {
                continue;
            }
            if ($duration <= 0) {
                continue;
            }
            $bytes_in = isset($stage['bytes_in']) ? (int) $stage['bytes_in'] : 0;
            $bytes_out = isset($stage['bytes_out']) ? (int) $stage['bytes_out'] : 0;
            $items[] = array(
                'stage' => $name,
                'label' => $this->humanize_profiler_stage_label($name),
                'durationMs' => $duration,
                'bytesIn' => $bytes_in,
                'bytesOut' => $bytes_out,
                'deltaBytes' => isset($stage['delta_bytes']) ? (int) $stage['delta_bytes'] : ($bytes_out - $bytes_in),
            );
        }

        usort($items, function ($a, $b) {
            return (int) ($b['durationMs'] ?? 0) <=> (int) ($a['durationMs'] ?? 0);
        });

        $visible_total = 0;
        foreach ($items as $item) {
            $visible_total += (int) ($item['durationMs'] ?? 0);
        }

        return array(
            'available' => !empty($items) || $total_ms > 0,
            'frontendTotalMs' => $total_ms,
            'visibleStageTotalMs' => $visible_total,
            'note' => 'Sub-stage timings are diagnostic. Some stages are nested/safe wrappers, so totals may not add up exactly to the parent rewrite time.',
            'items' => array_slice($items, 0, 24),
        );
    }

    private function humanize_profiler_stage_label($stage)
    {
        $stage = sanitize_key((string) $stage);
        $labels = array(
            'normalize-script-loading-attrs' => 'Normalize protected script attributes',
            'manual-critical-preloads' => 'Manual critical preloads',
            'slider-fetch-preloads' => 'Slider fetch preloads',
            'critical-request-chain-relief' => 'Critical request chain relief',
            'asset-chain-cleanup' => 'Asset chain cleanup',
            'strip-authoring-assets' => 'Strip authoring assets',
            'replace-homepage-css-bundle' => 'Replace homepage CSS bundle links',
            'replace-shared-css-bundle' => 'Replace shared CSS bundle links',
            'replace-page-css-bundle' => 'Replace page CSS bundle links',
            'build_page_css_bundle_on_entry' => 'Build CSS bundle on entry',
            'queue_page_css_bundle_async_on_entry' => 'Queue CSS bundle async on entry',
            'consolidate-leftover-css-bundle' => 'Consolidate remaining CSS',
            'cls-dimensions' => 'CLS image dimensions',
            'google-fonts-local-links' => 'Google Fonts local rewrite',
            'google-fonts-display-swap' => 'Google Fonts display swap',
            'self-hosted-font-css-links' => 'Self-hosted font CSS rewrite',
            'runtime-font-css-map' => 'Runtime font CSS map',
            'lazy-mailerlite-nonce-refresh' => 'Lazy MailerLite nonce refresh',
            'delay-third-party-pattern-scripts' => 'Delay third-party scripts',
            'speculation-rules-prefetch' => 'Speculation Rules prefetch',
            'sr7-first-slide-lcp-priority' => 'SR7 first-slide LCP priority',
            'safe-lcp-priority-preloads' => 'Safe LCP preload injection',
            'lcp-image-markup' => 'LCP image markup optimization',
            'lcp-boundary-defer' => 'LCP Boundary Delay',
            'delay-all-js-final-pass' => 'Delay all JS final pass',
        );
        if (isset($labels[$stage])) {
            return $labels[$stage];
        }
        return ucwords(str_replace(array('-', '_'), ' ', $stage));
    }

    private function run_performance_profile_job(array $params)
    {
        $mode = $this->normalize_performance_profile_mode($params['mode'] ?? 'compact');
        $engine = $this->get_engine();
        if (!$engine || !method_exists($engine, 'get_last_store_profile')) {
            return array('success' => false, 'message' => __('Speed diagnostics helper is not available.', 'ultracache'));
        }

        if (method_exists($engine, 'clear_last_store_profile')) {
            $engine->clear_last_store_profile();
        }

        $run_id = substr(md5((string) microtime(true) . wp_rand()), 0, 12);
        $headers = array(
            'X-UltraCache-Store-Profile'  => '1',
            'X-UltraCache-Debug'          => '1',
            'X-UltraCache-Revalidate'      => '1',
            'X-UltraCache-Internal-Request'=> '1',
            'X-UltraCache-Force-Refresh'  => '1',
            'X-UltraCache-Profile-Run'    => $run_id,
            'X-UltraCache-Token'          => (function_exists('ultracache_create_runtime_control_token') ? ultracache_create_runtime_control_token() : ''),
            'X-UltraCache-VCL-Signature'      => (function_exists('ultracache_get_varnish_revalidation_vcl_signature') ? ultracache_get_varnish_revalidation_vcl_signature() : ''),
            'Cache-Control'               => 'no-cache, no-store, max-age=0',
            'Pragma'                      => 'no-cache',
        );
        if ('verbose' === $mode) {
            $headers['X-UltraCache-Store-Profile-Verbose'] = '1';
        }
        if ('callback' === $mode) {
            $headers['X-UltraCache-Callback-Profile'] = '1';
        }

        $url = $this->normalize_performance_profile_url($params['url'] ?? home_url('/'));
        if (is_wp_error($url)) {
            return array(
                'success' => false,
                'message' => $url->get_error_message(),
                'performanceProfile' => array('available' => false, 'mode' => $mode),
            );
        }

        // Use query flags as well as headers so reverse proxies or web servers
        // that do not vary/pass custom diagnostic headers cannot serve an old
        // cached page to the dashboard profiler. These query args are stripped
        // from UltraCache cache keys/cacheability checks by the engine.
        $profile_query_args = array(
            'ultracache_store_profile' => '1',
            'ultracache_revalidate'    => '1',
            'ultracache_profile_run'   => $run_id,
            'ultracache_rt'            => (function_exists('ultracache_create_runtime_control_token') ? ultracache_create_runtime_control_token() : ''),
        );
        if ('verbose' === $mode) {
            $profile_query_args['ultracache_store_profile_verbose'] = '1';
        }
        if ('callback' === $mode) {
            $profile_query_args['ultracache_callback_profile'] = '1';
        }
        $profile_url = add_query_arg($profile_query_args, $url);

        $started = microtime(true);
        $response = ultracache_safe_loopback_remote_request($profile_url, array(
            'timeout'     => 90,
            'redirection' => 3,
            'headers'     => $headers,
            'user-agent'  => 'UltraCache Dashboard Profiler/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : 'unknown') . '; ' . home_url('/'),
        ));
        $elapsed_ms = (int) round((microtime(true) - $started) * 1000);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: WordPress HTTP API error message. */
                    __('Profiler request failed: %s', 'ultracache'),
                    $response->get_error_message()
                ),
                'performanceProfile' => array('available' => false, 'mode' => $mode),
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $cache_status = (string) wp_remote_retrieve_header($response, 'x-ultra-cache');
        $cache_source = (string) wp_remote_retrieve_header($response, 'x-ultra-cache-source');
        $profile_header = (string) wp_remote_retrieve_header($response, 'x-ultra-cache-store-profile');
        $profile_status_header = (string) wp_remote_retrieve_header($response, 'x-ultra-cache-store-profile-status');
        $profile_reason_header = (string) wp_remote_retrieve_header($response, 'x-ultra-cache-store-profile-reason');
        $body = wp_remote_retrieve_body($response);

        $profile = $this->wait_for_performance_profile_for_run($engine, $run_id, 8, 250000);

        if (!is_array($profile) || empty($profile)) {
            return array(
                'success' => false,
                'message' => sprintf(
                    /* translators: 1: cache status returned by the diagnostic request, 2: profile response header, 3: profile reason header. */
                    __('The diagnostic request reached cache status %1$s, but no STORE profile JSON was saved. Profile header: %2$s. Reason: %3$s.', 'ultracache'),
                    ($cache_status ?: 'unknown'),
                    ($profile_header ?: 'missing'),
                    ($profile_reason_header ?: 'missing-profile-json')
                ),
                'performanceProfile' => array(
                    'available' => false,
                    'mode' => $mode,
                    'responseCode' => $code,
                    'requestMs' => $elapsed_ms,
                    'cacheStatus' => $cache_status,
                    'cacheSource' => $cache_source,
                    'profileHeader' => $profile_header,
                    'profileStatusHeader' => $profile_status_header,
                    'profileReasonHeader' => $profile_reason_header,
                    'profileRunId' => $run_id,
                    'bodyBytes' => is_string($body) ? strlen($body) : 0,
                ),
            );
        }

        $summary = $this->summarize_performance_profile($profile);
        $summary['mode'] = $mode;
        $summary['profileUrl'] = $url;
        $summary['scannedAt'] = function_exists('current_time') ? current_time('mysql') : gmdate('c');
        $summary['responseCode'] = $code;
        $summary['requestMs'] = $elapsed_ms;
        $summary['cacheStatus'] = $cache_status;
        $summary['cacheSource'] = $cache_source;
        $summary['profileHeader'] = $profile_header;
        $summary['profileStatusHeader'] = $profile_status_header;
        $summary['profileReasonHeader'] = $profile_reason_header;
        $summary['profileRunId'] = $run_id;
        $summary['bodyBytes'] = is_string($body) ? strlen($body) : 0;
        $summary['cacheBypassedForDiagnostic'] = true;

        $profile_status = strtoupper((string) ($summary['status'] ?? ''));
        $profile_reason = sanitize_key((string) ($summary['reason'] ?? ''));
        if ('SKIP' === $profile_status && 'output-buffer-callback-not-run' === $profile_reason) {
            return array(
                'success' => false,
                'message' => __('The diagnostic request reached MISS, but UltraCache did not receive the final STORE output-buffer callback. A diagnostic profile was saved with reason: output-buffer-callback-not-run.', 'ultracache'),
                'performanceProfile' => $summary,
            );
        }

        return array(
            'success' => true,
            'message' => sprintf(
                /* translators: %s: speed diagnostic profile mode, for example COMPACT or VERBOSE. */
                __('%s performance profile completed.', 'ultracache'),
                strtoupper($mode)
            ),
            'performanceProfile' => $summary,
        );
    }

}
